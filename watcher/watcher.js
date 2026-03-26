/**
 * Tweller Flow — Local Folder Watcher
 *
 * Watches a configured directory for new photo session folders.
 * When photos appear, it notifies WordPress to update the session pipeline.
 *
 * Usage:
 *   1. Edit config.json with your paths and WordPress URL
 *   2. npm install
 *   3. npm start
 */

const fs = require('fs');
const path = require('path');
const chokidar = require('chokidar');
const axios = require('axios');

// ── Config ─────────────────────────────────────────────

const CONFIG_PATH = path.join(__dirname, 'config.json');

function loadConfig() {
    if (!fs.existsSync(CONFIG_PATH)) {
        console.error('[ERROR] config.json not found. Create it first.');
        process.exit(1);
    }
    return JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf-8'));
}

const config = loadConfig();

const WATCH_DIR = config.watchDir;
const CULLED_DIR = config.culledDir || '';
const EXPORTS_DIR = config.exportsDir;
const LR_AUTO_IMPORT_DIR = config.lrAutoImportDir || '';
const WP_URL = config.wordpressUrl.replace(/\/$/, '');
const API_KEY = config.apiKey;
const PHOTO_EXT = new Set(config.photoExtensions.map(e => e.toLowerCase()));
const POLL_INTERVAL = (config.pollIntervalSeconds || 30) * 1000;
const AUTO_UPLOAD = config.autoUploadToGallery !== false; // default: true

// Track known folders and their state
const folderState = new Map(); // folderName -> { photoCount, lastNotified, stage }
const exportState = new Map(); // folderName -> { exportCount, lastChanged, exported, uploaded, uploadedFiles }
const cullState = new Map();   // folderName -> { greenCount, totalPhotos, lastNotified }
const editState = new Map();   // folderName -> { editedCount, greenCount, lastChanged, completed }
const culledCopyState = new Map(); // folderName -> { copied: true, greenCount } — tracks green photos copied to FOR-IMAGEN
const imagenImportState = new Map(); // FOR-IMAGEN folderName -> { editedCount, lastChanged, importedToLR }
const createdSessions = new Set(); // tracking codes we already created folders for
const cullingState = new Map(); // sessionCode -> { proofsUploaded, selectionsDownloaded }

// ── Persistent state file ────────────────────────────
const STATE_FILE = path.join(__dirname, '.watcher-state.json');

function loadPersistedState() {
    try {
        if (!fs.existsSync(STATE_FILE)) return;
        const data = JSON.parse(fs.readFileSync(STATE_FILE, 'utf-8'));
        if (data.exportState) {
            for (const [k, v] of Object.entries(data.exportState)) {
                // Restore uploadedFiles as a Set
                if (v.uploadedFiles && Array.isArray(v.uploadedFiles)) {
                    v.uploadedFiles = new Set(v.uploadedFiles);
                } else {
                    v.uploadedFiles = new Set();
                }
                exportState.set(k, v);
            }
        }
        log(`Restored state: ${exportState.size} export folder(s) tracked`);
    } catch (err) {
        log(`Could not load state file: ${err.message}`, 'error');
    }
}

function savePersistedState() {
    try {
        const data = { exportState: {} };
        for (const [k, v] of exportState.entries()) {
            data.exportState[k] = {
                ...v,
                uploadedFiles: v.uploadedFiles ? Array.from(v.uploadedFiles) : [],
            };
        }
        fs.writeFileSync(STATE_FILE, JSON.stringify(data, null, 2));
    } catch (err) {
        log(`Could not save state file: ${err.message}`, 'error');
    }
}

// Load persisted state on startup
loadPersistedState();

// ── WordPress API ──────────────────────────────────────

async function wpAdvanceStage(sessionCode, targetStage, notes = '', extras = {}) {
    // Use GET with query params — host redirects POST→GET and strips the body
    const params = new URLSearchParams({
        session_code: sessionCode,
        target_stage: targetStage,
        notes: notes,
        api_key: API_KEY || '',
    });
    if (extras.photo_count) params.append('photo_count', extras.photo_count);

    const url = `${WP_URL}/wp-json/tweller-flow/v1/automation/advance?${params.toString()}`;
    try {
        const res = await axios.get(url, {
            headers: API_KEY ? { 'Authorization': `Bearer ${API_KEY}` } : {},
            timeout: 10000,
        });
        log(`Stage updated: ${sessionCode} → ${targetStage}`);
        return res.data;
    } catch (err) {
        const status = err.response ? err.response.status : 'network';
        const detail = err.response ? JSON.stringify(err.response.data || {}).substring(0, 200) : err.message;
        log(`Failed to update stage (${status}): ${detail}`, 'error');
        return null;
    }
}

async function wpGetSessions() {
    const url = `${WP_URL}/wp-json/tweller-flow/v1/automation/sessions`;
    try {
        const res = await axios.get(url, {
            headers: API_KEY ? { 'Authorization': `Bearer ${API_KEY}` } : {},
            timeout: 10000,
        });
        return res.data || [];
    } catch (err) {
        log(`Failed to fetch sessions: ${err.message}`, 'error');
        return [];
    }
}

/**
 * Check which photos already exist on the server for a session.
 * Returns a Set of filenames.
 */
async function wpGetExistingPhotos(sessionCode) {
    const url = `${WP_URL}/wp-json/tweller-flow/v1/gallery/${sessionCode}/filenames`;
    try {
        const res = await axios.get(url, {
            headers: API_KEY ? { 'Authorization': `Bearer ${API_KEY}` } : {},
            params: API_KEY ? { api_key: API_KEY } : {},
            timeout: 10000,
        });
        if (res.data && res.data.ok && Array.isArray(res.data.filenames)) {
            return new Set(res.data.filenames);
        }
        return new Set();
    } catch (err) {
        log(`Could not check existing photos for ${sessionCode}: ${err.message}`, 'error');
        return new Set();
    }
}

// ── Folder Analysis ────────────────────────────────────

function isPhotoFile(filePath) {
    const ext = path.extname(filePath).toLowerCase();
    return PHOTO_EXT.has(ext);
}

function countPhotosInFolder(folderPath) {
    try {
        const files = fs.readdirSync(folderPath, { recursive: true });
        return files.filter(f => {
            const fullPath = path.join(folderPath, f);
            return fs.statSync(fullPath).isFile() && isPhotoFile(f);
        }).length;
    } catch {
        return 0;
    }
}

/**
 * Read an XMP sidecar file and check if it has a green color label.
 * Lightroom Classic writes xmp:Label="Green" in sidecar .xmp files.
 *
 * Uses a short delay + retry to avoid conflicting with Lightroom's file writes.
 */
function hasGreenLabel(xmpPath) {
    const MAX_RETRIES = 3;
    for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
        try {
            // Check file size first — a 0-byte or tiny file means LR is mid-write
            const stat = fs.statSync(xmpPath);
            if (stat.size < 50) return false; // XMP files are always > 50 bytes

            const content = fs.readFileSync(xmpPath, 'utf-8');

            // Sanity check: valid XMP should contain the closing tag
            if (!content.includes('</x:xmpmeta>') && !content.includes('xpacket end')) {
                // File is truncated / mid-write — skip it
                return false;
            }

            // Match both attribute form and element form
            return /xmp:Label\s*=\s*"Green"/i.test(content) ||
                   /<xmp:Label>\s*Green\s*<\/xmp:Label>/i.test(content);
        } catch (err) {
            // EBUSY / EACCES / EPERM = file locked by Lightroom, retry after brief pause
            if (attempt < MAX_RETRIES - 1 && (err.code === 'EBUSY' || err.code === 'EACCES' || err.code === 'EPERM')) {
                const waitMs = 500 * (attempt + 1); // 500ms, 1000ms
                const waitUntil = Date.now() + waitMs;
                while (Date.now() < waitUntil) { /* busy-wait */ }
                continue;
            }
            return false;
        }
    }
    return false;
}

/**
 * Find XMP sidecar for a given photo file.
 * Lightroom writes sidecars as: photo.cr3 -> photo.cr3.xmp or photo.xmp
 */
/**
 * Check if an XMP sidecar contains Lightroom develop/edit settings.
 * Imagen returns XMP files with Camera Raw Settings (crs: namespace)
 * containing adjustments like Exposure, Contrast, etc.
 * We check for non-default values to confirm actual edits were applied.
 */
function hasEditSettings(xmpPath) {
    const MAX_RETRIES = 3;
    for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
        try {
            const stat = fs.statSync(xmpPath);
            if (stat.size < 50) return false;

            const content = fs.readFileSync(xmpPath, 'utf-8');

            if (!content.includes('</x:xmpmeta>') && !content.includes('xpacket end')) {
                return false;
            }

            // Check for Camera Raw Settings namespace (Imagen/LR develop settings)
            // Look for any non-zero/non-default develop adjustments
            const editIndicators = [
                /crs:ToneCurvePV2012/i,              // tone curve (strong indicator of AI edit)
                /crs:ProcessVersion/i,                // processing version set
                /crs:Exposure2012\s*=\s*"(?!0\.00|0")/i,  // non-zero exposure
                /crs:Contrast2012\s*=\s*"(?!0")/i,         // non-zero contrast
                /crs:Highlights2012\s*=\s*"(?!0")/i,       // non-zero highlights
                /crs:Shadows2012\s*=\s*"(?!0")/i,          // non-zero shadows
                /crs:Whites2012\s*=\s*"(?!0")/i,           // non-zero whites
                /crs:Blacks2012\s*=\s*"(?!0")/i,           // non-zero blacks
                /crs:Clarity2012\s*=\s*"(?!0")/i,          // non-zero clarity
                /crs:Vibrance\s*=\s*"(?!0")/i,             // non-zero vibrance
                /crs:Saturation\s*=\s*"(?!\+?0")/i,        // non-zero saturation
                /crs:ColorGradeHighlightHue/i,             // color grading
                /crs:LookName/i,                           // LR preset/look applied
            ];

            // If at least 2 indicators are present, edits have been applied
            let matchCount = 0;
            for (const pattern of editIndicators) {
                if (pattern.test(content)) {
                    matchCount++;
                    if (matchCount >= 2) return true;
                }
            }

            return false;
        } catch (err) {
            if (attempt < MAX_RETRIES - 1 && (err.code === 'EBUSY' || err.code === 'EACCES' || err.code === 'EPERM')) {
                const waitMs = 500 * (attempt + 1);
                const waitUntil = Date.now() + waitMs;
                while (Date.now() < waitUntil) { /* busy-wait */ }
                continue;
            }
            return false;
        }
    }
    return false;
}

/**
 * Count how many green-labeled RAW photos have Imagen/LR develop settings applied.
 * Returns { editedCount, greenCount }
 */
function countEditedPhotos(folderPath) {
    const RAW_EXT = new Set(['.cr2', '.cr3', '.nef', '.arw', '.dng', '.orf', '.rw2', '.raf']);
    let editedCount = 0;
    let greenCount = 0;

    try {
        const files = fs.readdirSync(folderPath, { recursive: true });
        for (const f of files) {
            const fullPath = path.join(folderPath, f);
            if (!fs.statSync(fullPath).isFile()) continue;

            const ext = path.extname(f).toLowerCase();
            if (!RAW_EXT.has(ext)) continue;

            const xmpPath = findXmpSidecar(fullPath);
            if (!xmpPath) continue;

            if (hasGreenLabel(xmpPath)) {
                greenCount++;
                if (hasEditSettings(xmpPath)) {
                    editedCount++;
                }
            }
        }
    } catch {
        // folder may not exist yet
    }

    return { editedCount, greenCount };
}

function findXmpSidecar(photoPath) {
    // Try photo.cr3.xmp first (Lightroom default for RAW files)
    const xmpWithExt = photoPath + '.xmp';
    if (fs.existsSync(xmpWithExt)) return xmpWithExt;

    // Try photo.xmp (alternative naming)
    const parsed = path.parse(photoPath);
    const xmpAlt = path.join(parsed.dir, parsed.name + '.xmp');
    if (fs.existsSync(xmpAlt)) return xmpAlt;

    return null;
}

/**
 * Scan a folder for RAW photos that have green labels in their XMP sidecars.
 * Returns { greenCount, totalRawCount }
 */
function countGreenLabeled(folderPath) {
    const RAW_EXT = new Set(['.cr2', '.cr3', '.nef', '.arw', '.dng', '.orf', '.rw2', '.raf']);
    let greenCount = 0;
    let totalRawCount = 0;

    try {
        const files = fs.readdirSync(folderPath, { recursive: true });
        for (const f of files) {
            const fullPath = path.join(folderPath, f);
            if (!fs.statSync(fullPath).isFile()) continue;

            const ext = path.extname(f).toLowerCase();
            if (!RAW_EXT.has(ext)) continue;

            totalRawCount++;

            const xmpPath = findXmpSidecar(fullPath);
            if (xmpPath && hasGreenLabel(xmpPath)) {
                greenCount++;
            }
        }
    } catch {
        // folder may not exist yet
    }

    return { greenCount, totalRawCount };
}

/**
 * Copy green-labeled RAW photos + their XMP sidecars to a -FOR-IMAGEN folder.
 * This folder contains only the keeper photos ready for Imagen AI editing.
 * Returns the number of photos copied.
 */
function copyGreenToForImagen(sourceFolderPath, sessionFolder) {
    if (!CULLED_DIR) return 0;

    const RAW_EXT = new Set(['.cr2', '.cr3', '.nef', '.arw', '.dng', '.orf', '.rw2', '.raf']);
    const imagenFolderName = `${sessionFolder}-FOR-IMAGEN`;
    const imagenFolderPath = path.join(CULLED_DIR, imagenFolderName);

    // Also create the base CULLED session folder for reference
    const culledSessionPath = path.join(CULLED_DIR, sessionFolder);

    let copiedCount = 0;

    try {
        const files = fs.readdirSync(sourceFolderPath, { recursive: true });

        // Collect green-labeled RAW files
        const greenFiles = [];
        for (const f of files) {
            const fullPath = path.join(sourceFolderPath, f);
            if (!fs.statSync(fullPath).isFile()) continue;

            const ext = path.extname(f).toLowerCase();
            if (!RAW_EXT.has(ext)) continue;

            const xmpPath = findXmpSidecar(fullPath);
            if (xmpPath && hasGreenLabel(xmpPath)) {
                greenFiles.push({ rawPath: fullPath, rawName: f, xmpPath, xmpName: path.basename(xmpPath) });
            }
        }

        if (greenFiles.length === 0) return 0;

        // Create FOR-IMAGEN folder
        if (!fs.existsSync(imagenFolderPath)) {
            fs.mkdirSync(imagenFolderPath, { recursive: true });
        }

        // Copy green-labeled RAW files + their XMP sidecars
        for (const { rawPath, rawName, xmpPath, xmpName } of greenFiles) {
            const destRaw = path.join(imagenFolderPath, path.basename(rawName));
            const destXmp = path.join(imagenFolderPath, xmpName);

            // Only copy if not already there or source is newer
            if (!fs.existsSync(destRaw) || fs.statSync(rawPath).mtimeMs > fs.statSync(destRaw).mtimeMs) {
                fs.copyFileSync(rawPath, destRaw);
            }
            if (!fs.existsSync(destXmp) || fs.statSync(xmpPath).mtimeMs > fs.statSync(destXmp).mtimeMs) {
                fs.copyFileSync(xmpPath, destXmp);
            }
            copiedCount++;
        }

        if (copiedCount > 0) {
            log(`Copied ${copiedCount} green-labeled photos to ${imagenFolderName}/`);
        }
    } catch (err) {
        log(`Error copying green photos to FOR-IMAGEN: ${err.message}`, 'error');
    }

    return copiedCount;
}

/**
 * Copy Imagen-edited RAW photos + XMP sidecars to LR Auto Import staging folder.
 * Only copies files with develop settings (edited by Imagen).
 */
function copyToLRAutoImport(imagenFolderPath, imagenFolderName) {
    if (!LR_AUTO_IMPORT_DIR) return 0;

    const RAW_EXT = new Set(['.cr2', '.cr3', '.nef', '.arw', '.dng', '.orf', '.rw2', '.raf']);
    let copiedCount = 0;

    try {
        const files = fs.readdirSync(imagenFolderPath);

        for (const f of files) {
            const fullPath = path.join(imagenFolderPath, f);
            if (!fs.statSync(fullPath).isFile()) continue;

            const ext = path.extname(f).toLowerCase();
            if (!RAW_EXT.has(ext)) continue;

            const xmpPath = findXmpSidecar(fullPath);
            if (!xmpPath || !hasEditSettings(xmpPath)) continue;

            const destRaw = path.join(LR_AUTO_IMPORT_DIR, f);
            const destXmp = path.join(LR_AUTO_IMPORT_DIR, path.basename(xmpPath));

            // Only copy if not already there or source is newer
            if (!fs.existsSync(destRaw) || fs.statSync(fullPath).mtimeMs > fs.statSync(destRaw).mtimeMs) {
                fs.copyFileSync(fullPath, destRaw);
            }
            if (!fs.existsSync(destXmp) || fs.statSync(xmpPath).mtimeMs > fs.statSync(destXmp).mtimeMs) {
                fs.copyFileSync(xmpPath, destXmp);
            }
            copiedCount++;
        }

        // Track which session these files belong to, so the post-import
        // organizer can sort them into the correct subfolder.
        if (copiedCount > 0) {
            trackSessionFiles(imagenFolderName, files.filter(f => {
                const ext = path.extname(f).toLowerCase();
                return RAW_EXT.has(ext);
            }));
        }
    } catch (err) {
        log(`Error copying to LR Auto Import: ${err.message}`, 'error');
    }

    return copiedCount;
}

/**
 * Track which files belong to which session for post-import organization.
 * Stores a mapping file so the post-import organizer knows where to sort files.
 */
function trackSessionFiles(sessionName, fileNames) {
    const trackingFile = path.join(LR_AUTO_IMPORT_DIR, '.session-tracking.json');
    let tracking = {};

    try {
        if (fs.existsSync(trackingFile)) {
            tracking = JSON.parse(fs.readFileSync(trackingFile, 'utf8'));
        }
    } catch (err) {
        log(`Warning: Could not read session tracking file, starting fresh`, 'warn');
    }

    if (!tracking[sessionName]) {
        tracking[sessionName] = [];
    }

    for (const f of fileNames) {
        if (!tracking[sessionName].includes(f)) {
            tracking[sessionName].push(f);
        }
    }

    try {
        fs.writeFileSync(trackingFile, JSON.stringify(tracking, null, 2));
    } catch (err) {
        log(`Error writing session tracking file: ${err.message}`, 'error');
    }
}

function getTopLevelFolders(dir) {
    dir = dir || WATCH_DIR;
    try {
        return fs.readdirSync(dir)
            .filter(f => {
                const fullPath = path.join(dir, f);
                return fs.statSync(fullPath).isDirectory() && !f.startsWith('.');
            });
    } catch {
        return [];
    }
}

/**
 * Try to match a folder name to a session.
 * Folder naming convention: anything containing the tracking code or client name.
 */
function matchFolderToSession(folderName, sessions) {
    const folderLower = folderName.toLowerCase().replace(/[_\-\.]/g, ' ');

    // 1. Exact tracking code match
    for (const s of sessions) {
        if (folderLower.includes(s.tracking_code.toLowerCase())) {
            return s;
        }
    }

    // 2. Folder name already assigned to a session
    for (const s of sessions) {
        if (s.folder_name && s.folder_name.toLowerCase() === folderLower) {
            return s;
        }
    }

    // 3. Client name match (fuzzy)
    for (const s of sessions) {
        const nameParts = s.client_name.toLowerCase().split(/\s+/);
        const matchCount = nameParts.filter(part => part.length > 2 && folderLower.includes(part)).length;
        if (matchCount >= 2 || (nameParts.length === 1 && matchCount === 1)) {
            return s;
        }
    }

    return null;
}

// ── Auto-Create Folders ───────────────────────────────

function createSessionFolder(session) {
    const safeName = session.client_name.replace(/[<>:"\/\\|?*]/g, '_').trim();
    const folderName = `${session.tracking_code} - ${safeName}`;
    const folderPath = path.join(WATCH_DIR, folderName);

    if (fs.existsSync(folderPath)) {
        createdSessions.add(session.tracking_code);
        return;
    }

    fs.mkdirSync(folderPath, { recursive: true });

    // Also create the matching CULLED folder (ready for FOR-IMAGEN separation)
    if (CULLED_DIR) {
        const culledFolderPath = path.join(CULLED_DIR, folderName);
        if (!fs.existsSync(culledFolderPath)) {
            fs.mkdirSync(culledFolderPath, { recursive: true });
            log(`Created culled folder: ${folderName}/`);
        }
    }

    // Also create the matching exports folder
    if (EXPORTS_DIR) {
        const exportFolderName = `${session.tracking_code} - ${safeName}-Exports`;
        const exportPath = path.join(EXPORTS_DIR, exportFolderName);
        if (!fs.existsSync(exportPath)) {
            fs.mkdirSync(exportPath, { recursive: true });
            log(`Created exports folder: ${exportFolderName}/`);
        }
    }

    createdSessions.add(session.tracking_code);
    log(`Created folder: ${folderName}/ — set as Lightroom import destination`);
}

// ── Gallery Upload ─────────────────────────────────────

const JPEG_EXT = new Set(['.jpg', '.jpeg', '.png', '.webp', '.tif', '.tiff']);

/**
 * Get list of exportable photo files in a folder.
 */
function getExportPhotos(folderPath) {
    try {
        const files = fs.readdirSync(folderPath, { recursive: true });
        return files.filter(f => {
            const fullPath = path.join(folderPath, f);
            if (!fs.statSync(fullPath).isFile()) return false;
            const ext = path.extname(f).toLowerCase();
            return JPEG_EXT.has(ext);
        }).map(f => ({
            name: path.basename(f),
            path: path.join(folderPath, f),
        }));
    } catch {
        return [];
    }
}

/**
 * Upload a single photo to the WordPress gallery API.
 * Uses multipart/form-data via raw HTTP.
 */
const UPLOAD_DELAY_MS = 2000;   // 2s pause between uploads to avoid overwhelming the server
const MAX_RETRIES = 3;          // retry failed uploads up to 3 times
const MAX_CONSECUTIVE_FAILS = 5; // abort batch after 5 consecutive failures

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function wpUploadPhoto(sessionCode, filePath, fileName, galleryPassword) {
    const FormData = (await import('form-data')).default;
    const url = `${WP_URL}/wp-json/tweller-flow/v1/photo-upload`;

    for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
        const form = new FormData();
        form.append('session_code', sessionCode);
        form.append('photo', fs.createReadStream(filePath), fileName);
        if (galleryPassword) {
            form.append('gallery_password', galleryPassword);
        }
        if (API_KEY) form.append('api_key', API_KEY);

        try {
            const res = await axios.post(url, form, {
                headers: {
                    ...form.getHeaders(),
                    'Authorization': API_KEY ? `Bearer ${API_KEY}` : '',
                },
                timeout: 60000,
                maxContentLength: Infinity,
                maxBodyLength: Infinity,
            });
            return res.data;
        } catch (err) {
            const status = err.response?.status;
            const detail = err.response ? JSON.stringify(err.response.data || {}).substring(0, 200) : err.message;

            if (attempt < MAX_RETRIES && (status === 503 || status === 429 || status === 502)) {
                const backoff = attempt * 5000; // 5s, 10s, 15s
                log(`Upload ${fileName} got ${status}, retrying in ${backoff / 1000}s (attempt ${attempt}/${MAX_RETRIES})...`, 'warn');
                await sleep(backoff);
                continue;
            }

            log(`Upload failed for ${fileName}: ${detail}`, 'error');
            return null;
        }
    }
    return null;
}

/**
 * Upload all new photos from an export folder to the WordPress gallery.
 * Checks both local state AND server-side to avoid duplicates.
 * Returns { uploaded, failed, total }
 */
const activeUploads = new Set(); // prevent concurrent uploads for the same session

async function uploadExportFolder(sessionCode, folderPath, galleryPassword) {
    // Prevent concurrent uploads for the same session
    if (activeUploads.has(sessionCode)) {
        log(`Upload already in progress for ${sessionCode} — skipping`);
        return { uploaded: 0, failed: 0, total: 0, skipped: true };
    }

    activeUploads.add(sessionCode);
    try {
        return await _doUpload(sessionCode, folderPath, galleryPassword);
    } finally {
        activeUploads.delete(sessionCode);
    }
}

async function _doUpload(sessionCode, folderPath, galleryPassword) {
    const photos = getExportPhotos(folderPath);
    if (photos.length === 0) return { uploaded: 0, failed: 0, total: 0 };

    const prev = exportState.get(path.basename(folderPath));
    const localUploaded = prev?.uploadedFiles || new Set();

    // Also check server for already-uploaded photos (handles restart / stage-back scenarios)
    const serverFiles = await wpGetExistingPhotos(sessionCode);
    const alreadyUploaded = new Set([...localUploaded, ...serverFiles]);

    if (serverFiles.size > 0 && localUploaded.size === 0) {
        log(`Server already has ${serverFiles.size} photos for ${sessionCode} — syncing local state`);
    }

    let uploaded = 0;
    let failed = 0;
    let consecutiveFails = 0;

    const toUpload = photos.filter(p => !alreadyUploaded.has(p.name));
    if (toUpload.length === 0) {
        log(`All ${photos.length} photos already uploaded for ${sessionCode} — skipping`);
        return { uploaded: 0, failed: 0, total: photos.length, uploadedFiles: alreadyUploaded };
    }

    const totalNew = toUpload.length;
    let current = 0;

    for (const photo of toUpload) {
        current++;
        log(`Uploading ${photo.name} (${current}/${totalNew}) for ${sessionCode}...`);
        const result = await wpUploadPhoto(sessionCode, photo.path, photo.name, galleryPassword);
        if (result && result.ok) {
            uploaded++;
            consecutiveFails = 0;
            alreadyUploaded.add(photo.name);
            // Only send password on first upload
            galleryPassword = null;
        } else {
            failed++;
            consecutiveFails++;
            if (consecutiveFails >= MAX_CONSECUTIVE_FAILS) {
                log(`Aborting upload for ${sessionCode}: ${MAX_CONSECUTIVE_FAILS} consecutive failures — server may be overloaded`, 'error');
                break;
            }
        }

        // Throttle: pause between uploads to avoid overwhelming the server
        if (current < totalNew) {
            await sleep(UPLOAD_DELAY_MS);
        }
    }

    return { uploaded, failed, total: photos.length, uploadedFiles: alreadyUploaded };
}

// ── Culling Portal Integration ─────────────────────────

async function wpGetCullingFilenames(sessionCode) {
    const url = `${WP_URL}/wp-json/tweller-flow/v1/culling/${sessionCode}/filenames`;
    try {
        const res = await axios.get(url, {
            headers: API_KEY ? { 'Authorization': `Bearer ${API_KEY}` } : {},
            params: API_KEY ? { api_key: API_KEY } : {},
            timeout: 10000,
        });
        return new Set(res.data.filenames || []);
    } catch (err) {
        return new Set();
    }
}

async function wpUploadProof(sessionCode, filePath, fileName, originalFilename) {
    const FormData = (await import('form-data')).default;
    const url = `${WP_URL}/wp-json/tweller-flow/v1/culling/${sessionCode}/upload`;

    const form = new FormData();
    form.append('photo', fs.createReadStream(filePath), fileName);
    form.append('original_filename', originalFilename);
    if (API_KEY) form.append('api_key', API_KEY);

    try {
        const res = await axios.post(url, form, {
            headers: {
                ...form.getHeaders(),
                'Authorization': API_KEY ? `Bearer ${API_KEY}` : '',
            },
            timeout: 60000,
            maxContentLength: Infinity,
            maxBodyLength: Infinity,
        });
        return res.data;
    } catch (err) {
        const detail = err.response ? JSON.stringify(err.response.data || {}).substring(0, 200) : err.message;
        log(`Proof upload failed for ${fileName}: ${detail}`, 'error');
        return null;
    }
}

async function wpMarkCullingReady(sessionCode, password) {
    const params = new URLSearchParams({ api_key: API_KEY || '' });
    if (password) params.append('password', password);
    const url = `${WP_URL}/wp-json/tweller-flow/v1/culling/${sessionCode}/ready`;

    try {
        const FormData = (await import('form-data')).default;
        const form = new FormData();
        if (API_KEY) form.append('api_key', API_KEY);
        if (password) form.append('password', password);

        const res = await axios.post(url, form, {
            headers: {
                ...form.getHeaders(),
                'Authorization': API_KEY ? `Bearer ${API_KEY}` : '',
            },
            timeout: 10000,
        });
        return res.data;
    } catch (err) {
        const detail = err.response ? JSON.stringify(err.response.data || {}).substring(0, 200) : err.message;
        log(`Failed to mark culling ready: ${detail}`, 'error');
        return null;
    }
}

async function wpGetCullingSelections(sessionCode) {
    const url = `${WP_URL}/wp-json/tweller-flow/v1/culling/${sessionCode}/selections`;
    try {
        const res = await axios.get(url, {
            headers: API_KEY ? { 'Authorization': `Bearer ${API_KEY}` } : {},
            params: API_KEY ? { api_key: API_KEY } : {},
            timeout: 10000,
        });
        return res.data;
    } catch (err) {
        return null;
    }
}

/**
 * Generate preview JPGs from RAW files using the Lightroom preset.
 * Uses dcraw/exiftool to extract embedded preview from RAW + sips/convert for resize.
 * Falls back to embedded JPEG preview in the RAW file.
 */
function extractRawPreview(rawPath, destPath) {
    const { execSync } = require('child_process');

    // Try exiftool to extract embedded preview (fastest, best quality)
    try {
        execSync(`exiftool -b -PreviewImage "${rawPath}" > "${destPath}"`, {
            stdio: 'pipe',
            timeout: 30000,
        });
        if (fs.existsSync(destPath) && fs.statSync(destPath).size > 1000) {
            return true;
        }
    } catch (e) { /* fall through */ }

    // Try extracting JpgFromRaw
    try {
        execSync(`exiftool -b -JpgFromRaw "${rawPath}" > "${destPath}"`, {
            stdio: 'pipe',
            timeout: 30000,
        });
        if (fs.existsSync(destPath) && fs.statSync(destPath).size > 1000) {
            return true;
        }
    } catch (e) { /* fall through */ }

    // Try dcraw (converts RAW to PPM then to JPEG)
    try {
        const ppmPath = destPath.replace(/\.[^.]+$/, '.ppm');
        execSync(`dcraw -c -e "${rawPath}" > "${destPath}"`, {
            stdio: 'pipe',
            timeout: 60000,
        });
        if (fs.existsSync(destPath) && fs.statSync(destPath).size > 1000) {
            return true;
        }
    } catch (e) { /* fall through */ }

    return false;
}

async function uploadCullingProofs(session, folderPath) {
    const code = session.tracking_code;
    const state = cullingState.get(code) || {};

    if (state.proofsUploaded) return;

    // Get green-labeled RAW files
    const RAW_EXT = new Set(['.cr2', '.cr3', '.nef', '.arw', '.dng', '.orf', '.rw2', '.raf']);
    const greens = [];

    function scanDir(dir) {
        if (!fs.existsSync(dir)) return;
        const entries = fs.readdirSync(dir, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                scanDir(fullPath);
            } else if (RAW_EXT.has(path.extname(entry.name).toLowerCase())) {
                // Check for green label
                const xmpPath1 = fullPath + '.xmp';
                const xmpPath2 = fullPath.replace(/\.[^.]+$/, '.xmp');
                const xmpPath = fs.existsSync(xmpPath1) ? xmpPath1 : (fs.existsSync(xmpPath2) ? xmpPath2 : null);
                if (xmpPath) {
                    try {
                        const content = fs.readFileSync(xmpPath, 'utf-8');
                        if (/xmp:Label="Green"|<xmp:Label>Green<\/xmp:Label>/i.test(content)) {
                            greens.push({ name: entry.name, path: fullPath });
                        }
                    } catch (e) { /* skip */ }
                }
            }
        }
    }

    scanDir(folderPath);

    if (greens.length === 0) return;

    // Check which proofs already uploaded
    const existingProofs = await wpGetCullingFilenames(code);
    const toUpload = greens.filter(g => {
        const previewName = g.name.replace(/\.[^.]+$/, '.jpg');
        return !existingProofs.has(previewName);
    });

    if (toUpload.length === 0 && existingProofs.size >= greens.length) {
        // All proofs already uploaded, mark ready
        log(`All ${existingProofs.size} culling proofs already uploaded for ${session.client_name}`);
        const password = config.defaultGalleryPassword || '';
        await wpMarkCullingReady(code, password);
        cullingState.set(code, { ...state, proofsUploaded: true });
        return;
    }

    if (toUpload.length === 0) return;

    // Create temp dir for preview JPGs
    const tmpDir = path.join(__dirname, '.culling-previews', code);
    fs.mkdirSync(tmpDir, { recursive: true });

    log(`Generating ${toUpload.length} culling preview(s) for ${session.client_name}...`);

    let uploaded = 0;
    for (const raw of toUpload) {
        const previewName = raw.name.replace(/\.[^.]+$/, '.jpg');
        const previewPath = path.join(tmpDir, previewName);

        // Extract preview from RAW
        const ok = extractRawPreview(raw.path, previewPath);
        if (!ok) {
            log(`Could not extract preview from ${raw.name} — skipping`, 'error');
            continue;
        }

        // Upload to culling portal
        const result = await wpUploadProof(code, previewPath, previewName, raw.name);
        if (result && result.ok) {
            uploaded++;
        }

        // Throttle
        await sleep(1000);
    }

    // Clean up temp previews
    try {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    } catch (e) { /* ok */ }

    if (uploaded > 0) {
        log(`Uploaded ${uploaded} culling proof(s) for ${session.client_name}`);
    }

    // Check if all done
    const finalCount = await wpGetCullingFilenames(code);
    if (finalCount.size >= greens.length) {
        const password = config.defaultGalleryPassword || '';
        await wpMarkCullingReady(code, password);
        log(`Culling proofs ready for ${session.client_name} — email sent to client`);
        cullingState.set(code, { ...state, proofsUploaded: true });
    }
}

async function checkCullingSelections(session, folderPath) {
    const code = session.tracking_code;
    const state = cullingState.get(code) || {};

    if (state.selectionsDownloaded) return;
    if (!state.proofsUploaded) return;

    const data = await wpGetCullingSelections(code);
    if (!data || !data.submitted) return;

    const filenames = data.filenames || [];
    if (filenames.length === 0) return;

    log(`Client ${session.client_name} selected ${filenames.length} photos — copying to FOR-IMAGEN...`);

    // Copy selected RAW files + XMP to FOR-IMAGEN folder
    const baseName = path.basename(folderPath);
    const imagenFolder = path.join(CULLED_DIR, baseName + '-FOR-IMAGEN');
    fs.mkdirSync(imagenFolder, { recursive: true });

    const filenameSet = new Set(filenames.map(f => f.toLowerCase()));
    let copied = 0;

    function copySelected(dir) {
        if (!fs.existsSync(dir)) return;
        const entries = fs.readdirSync(dir, { withFileTypes: true });
        for (const entry of entries) {
            const fullPath = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                copySelected(fullPath);
            } else {
                // Check if this file matches a selected filename (RAW or original_filename)
                const lowerName = entry.name.toLowerCase();
                if (filenameSet.has(lowerName)) {
                    const dest = path.join(imagenFolder, entry.name);
                    if (!fs.existsSync(dest)) {
                        fs.copyFileSync(fullPath, dest);
                        copied++;
                    }
                    // Also copy XMP sidecar
                    const xmpPath1 = fullPath + '.xmp';
                    const xmpPath2 = fullPath.replace(/\.[^.]+$/, '.xmp');
                    const xmpSrc = fs.existsSync(xmpPath1) ? xmpPath1 : (fs.existsSync(xmpPath2) ? xmpPath2 : null);
                    if (xmpSrc) {
                        const xmpDest = path.join(imagenFolder, path.basename(xmpSrc));
                        if (!fs.existsSync(xmpDest)) {
                            fs.copyFileSync(xmpSrc, xmpDest);
                        }
                    }
                }
            }
        }
    }

    copySelected(folderPath);

    if (copied > 0) {
        log(`Copied ${copied} selected RAW(s) to CULLED folder -> "${baseName}-FOR-IMAGEN/"`);
    }

    if (data.upsell) {
        log(`Upsell: +${data.upsell.tier || 'All'} photos ($${data.upsell.price}) for ${session.client_name}`);
    }

    cullingState.set(code, { ...state, selectionsDownloaded: true });

    // Advance to culled stage if needed
    if (['imported', 'culling'].includes(session.current_stage)) {
        await wpAdvanceStage(code, 'culled', `Client selected ${filenames.length} photos via culling portal`, { photo_count: filenames.length });
    }
}

// ── Main Loop ──────────────────────────────────────────

async function scan() {
    const sessions = await wpGetSessions();
    if (sessions.length === 0) return;

    // Auto-create folders for new sessions
    for (const session of sessions) {
        if (!createdSessions.has(session.tracking_code)) {
            createSessionFolder(session);
        }
    }

    const folders = getTopLevelFolders();

    for (const folder of folders) {
        const folderPath = path.join(WATCH_DIR, folder);
        const photoCount = countPhotosInFolder(folderPath);

        if (photoCount === 0) continue;

        const session = matchFolderToSession(folder, sessions);
        if (!session) {
            // Only log unmatched once
            if (!folderState.has(folder)) {
                log(`Unmatched folder: "${folder}" (${photoCount} photos) — no session found`);
                folderState.set(folder, { photoCount, stage: 'unmatched' });
            }
            continue;
        }

        const prev = folderState.get(folder);

        // New folder detected or photo count changed
        if (!prev || prev.photoCount !== photoCount) {
            log(`Folder "${folder}" → ${session.client_name} [${session.tracking_code}]: ${photoCount} photos`);

            // Auto-advance to "imported" when photos appear
            if (session.current_stage === 'booked') {
                await wpAdvanceStage(
                    session.tracking_code,
                    'imported',
                    `${photoCount} photos detected in ${folder}`,
                    { photo_count: photoCount }
                );
            }

            folderState.set(folder, {
                photoCount,
                stage: session.current_stage,
                lastNotified: Date.now(),
            });
        }
    }

    // Scan for culling (green labels in XMP sidecars)
    for (const folder of folders) {
        const folderPath = path.join(WATCH_DIR, folder);
        const session = matchFolderToSession(folder, sessions);
        if (!session) continue;

        // Only check culling for sessions in 'imported' or 'culling' stage
        if (session.current_stage !== 'imported' && session.current_stage !== 'culling') continue;

        const prev = cullState.get(folder);
        if (prev && prev.completed) continue;

        const { greenCount, totalRawCount } = countGreenLabeled(folderPath);

        if (greenCount === 0) continue;

        if (!prev || prev.greenCount !== greenCount) {
            log(`Culling "${folder}" → ${session.client_name}: ${greenCount}/${totalRawCount} photos green-labeled`);

            // First green label detected → advance to 'culling'
            if (session.current_stage === 'imported') {
                await wpAdvanceStage(
                    session.tracking_code,
                    'culling',
                    `Culling started: ${greenCount}/${totalRawCount} green-labeled`,
                    { photo_count: greenCount }
                );
            }

            cullState.set(folder, {
                greenCount,
                totalRawCount,
                lastChanged: Date.now(),
            });
        } else if (prev && prev.greenCount === greenCount && session.current_stage === 'culling') {
            // Green count hasn't changed — check if stable long enough to mark culled
            const elapsed = Date.now() - prev.lastChanged;
            const CULL_STABLE_MS = (config.cullStableSeconds || 120) * 1000; // default 2 minutes

            if (elapsed >= CULL_STABLE_MS) {
                log(`Culling complete "${folder}" → ${session.client_name}: ${greenCount} keepers out of ${totalRawCount} (stable for ${Math.round(elapsed / 1000)}s)`);
                await wpAdvanceStage(
                    session.tracking_code,
                    'culled',
                    `Culling complete: ${greenCount}/${totalRawCount} keepers selected`,
                    { photo_count: greenCount }
                );
                // Mark as done so we don't re-trigger
                cullState.set(folder, {
                    greenCount,
                    totalRawCount,
                    lastChanged: Date.now(),
                    completed: true,
                });

                // If culling portal is enabled, upload proofs for client selection
                // Otherwise, copy green-labeled photos directly to FOR-IMAGEN
                if (session.culling_enabled) {
                    log(`Client culling enabled for "${folder}" → uploading proof previews to portal`);
                    try {
                        await uploadCullingProofs(session, folderPath);
                    } catch (err) {
                        log(`ERROR uploading culling proofs for "${folder}": ${err.message}`);
                    }
                } else if (CULLED_DIR && !culledCopyState.has(folder)) {
                    // Ensure CULLED_DIR exists
                    if (!fs.existsSync(CULLED_DIR)) {
                        fs.mkdirSync(CULLED_DIR, { recursive: true });
                        log(`Created CULLED directory: ${CULLED_DIR}`);
                    }
                    const copied = copyGreenToForImagen(folderPath, folder);
                    if (copied > 0) {
                        culledCopyState.set(folder, { copied: true, greenCount: copied });
                        log(`${copied} green-labeled photos ready for Imagen in ${folder}-FOR-IMAGEN/`);
                    }
                }
            }
        }
    }

    // ── Catch-up: copy green photos to FOR-IMAGEN for any session past culling ──
    // This runs on every scan so the watcher picks up where it left off,
    // even if the session was already culled before this code was deployed.
    if (CULLED_DIR) {
        for (const folder of folders) {
            const folderPath = path.join(WATCH_DIR, folder);
            const session = matchFolderToSession(folder, sessions);
            if (!session) continue;

            // Copy for any session that's at 'culled' or beyond (culling is done)
            const pastCulling = ['culled', 'editing', 'edited', 'exporting', 'exported', 'uploading', 'uploaded', 'delivered'];
            if (!pastCulling.includes(session.current_stage)) continue;

            // Skip catch-up copy for culling-enabled sessions — they wait for client selections
            if (session.culling_enabled && !session.culling_submitted) continue;

            const { greenCount } = countGreenLabeled(folderPath);
            if (greenCount === 0) continue;

            // Skip if already copied and green count hasn't changed
            const prevCopy = culledCopyState.get(folder);
            if (prevCopy && prevCopy.greenCount === greenCount) continue;

            // Ensure CULLED_DIR exists
            if (!fs.existsSync(CULLED_DIR)) {
                fs.mkdirSync(CULLED_DIR, { recursive: true });
            }

            const copied = copyGreenToForImagen(folderPath, folder);
            if (copied > 0) {
                const isUpdate = prevCopy ? ` (was ${prevCopy.greenCount})` : '';
                culledCopyState.set(folder, { copied: true, greenCount: copied });
                log(`${prevCopy ? 'Updated' : 'Catch-up'}: ${copied} green-labeled photos in ${folder}-FOR-IMAGEN/${isUpdate}`);
            }
        }
    }

    // ── Client culling: check for selections and copy selected RAWs ──
    // For sessions with culling_enabled, poll the portal for client selections.
    // When selections arrive, copy the selected RAW+XMP files to FOR-IMAGEN folder.
    for (const folder of folders) {
        const folderPath = path.join(WATCH_DIR, folder);
        const session = matchFolderToSession(folder, sessions);
        if (!session) continue;
        if (!session.culling_enabled) continue;
        if (session.current_stage !== 'culled') continue;

        const cState = cullingState.get(session.tracking_code) || {};

        // If proofs haven't been uploaded yet (e.g. catch-up after restart), upload them
        if (!cState.proofsUploaded) {
            try {
                await uploadCullingProofs(session, folderPath);
            } catch (err) {
                log(`ERROR uploading culling proofs for "${folder}": ${err.message}`);
            }
            continue; // Don't check selections on the same scan as upload
        }

        // If selections already downloaded, skip
        if (cState.selectionsDownloaded) continue;

        // Check if client has submitted selections
        if (session.culling_submitted) {
            log(`Client selections received for "${folder}" → ${session.client_name} — copying selected RAWs to FOR-IMAGEN`);
            try {
                await checkCullingSelections(session, folderPath);
            } catch (err) {
                log(`ERROR processing culling selections for "${folder}": ${err.message}`);
            }
        }
    }

    // ── Auto-advance: culled → editing ──────────────────
    // After culling completes, auto-advance to 'editing' after a short delay.
    // For culling-enabled sessions, only advance after client selections are received and processed.
    for (const folder of folders) {
        const folderPath = path.join(WATCH_DIR, folder);
        const session = matchFolderToSession(folder, sessions);
        if (!session) continue;
        if (session.current_stage !== 'culled') continue;

        const prev = editState.get(folder);
        if (prev && prev.completed) continue;

        // If culling is enabled, wait for selections to be downloaded before advancing
        if (session.culling_enabled) {
            const cState = cullingState.get(session.tracking_code) || {};
            if (!cState.selectionsDownloaded) continue; // Still waiting for client
        }

        // Auto-advance to 'editing' after 30s in 'culled' stage
        if (!prev) {
            editState.set(folder, {
                editedCount: 0,
                greenCount: 0,
                lastChanged: Date.now(),
            });
        } else {
            const elapsed = Date.now() - prev.lastChanged;
            const AUTO_EDIT_DELAY_MS = (config.editAutoAdvanceSeconds || 30) * 1000;

            if (elapsed >= AUTO_EDIT_DELAY_MS) {
                log(`Auto-advancing "${folder}" → ${session.client_name}: culled → editing (ready for Imagen)`);
                await wpAdvanceStage(
                    session.tracking_code,
                    'editing',
                    'Auto-advanced: photos ready for Imagen editing'
                );
                editState.set(folder, {
                    editedCount: 0,
                    greenCount: 0,
                    lastChanged: Date.now(),
                });
            }
        }
    }

    // ── Detect editing complete: editing → edited ───────
    // Watch for XMP develop settings (crs: namespace) appearing on green-labeled photos.
    // Imagen writes edits to the LR catalog; if "Auto write XMP" is enabled in LR,
    // the develop settings will appear in XMP sidecars.
    // Also supports a manual trigger: drop a file named ".editing-done" in the session folder.
    for (const folder of folders) {
        const folderPath = path.join(WATCH_DIR, folder);
        const session = matchFolderToSession(folder, sessions);
        if (!session) continue;
        if (session.current_stage !== 'editing') continue;

        const prev = editState.get(folder);
        if (prev && prev.completed) continue;

        // Manual trigger: photographer drops a .editing-done file in the folder
        const manualTrigger = path.join(folderPath, '.editing-done');
        if (fs.existsSync(manualTrigger)) {
            const cull = cullState.get(folder);
            const photoCount = cull ? cull.greenCount : 0;
            log(`Manual edit-complete trigger "${folder}" → ${session.client_name}: .editing-done file found`);
            await wpAdvanceStage(
                session.tracking_code,
                'edited',
                `Editing complete (manual trigger): ${photoCount} photos edited`,
                { photo_count: photoCount }
            );
            editState.set(folder, { editedCount: photoCount, greenCount: photoCount, lastChanged: Date.now(), completed: true });
            // Create exports folder so Lightroom has an export destination
            if (EXPORTS_DIR) {
                const exportFolder = path.join(EXPORTS_DIR, `${session.tracking_code} - ${session.client_name.replace(/[<>:"\/\\|?*]/g, '_').trim()}-Exports`);
                if (!fs.existsSync(exportFolder)) {
                    fs.mkdirSync(exportFolder, { recursive: true });
                    log(`Created exports folder: ${path.basename(exportFolder)}/`);
                }
            }
            // Remove trigger file so it doesn't re-fire
            try { fs.unlinkSync(manualTrigger); } catch { /* ignore */ }
            continue;
        }

        // XMP-based detection: check if green-labeled photos now have develop settings
        const { editedCount, greenCount } = countEditedPhotos(folderPath);

        if (editedCount === 0) continue;

        if (!prev || prev.editedCount !== editedCount) {
            log(`Editing "${folder}" → ${session.client_name}: ${editedCount}/${greenCount} photos have develop settings`);
            editState.set(folder, {
                editedCount,
                greenCount,
                lastChanged: Date.now(),
            });
        } else if (prev && prev.editedCount === editedCount && editedCount > 0) {
            // Edit count stable — check if enough photos are edited
            const elapsed = Date.now() - prev.lastChanged;
            const EDIT_STABLE_MS = (config.editStableSeconds || 120) * 1000;
            const editRatio = greenCount > 0 ? editedCount / greenCount : 0;

            // Advance when: stable for 2 min AND at least 80% of green photos have edits
            if (elapsed >= EDIT_STABLE_MS && editRatio >= 0.8) {
                log(`Editing complete "${folder}" → ${session.client_name}: ${editedCount}/${greenCount} photos edited (stable for ${Math.round(elapsed / 1000)}s)`);
                await wpAdvanceStage(
                    session.tracking_code,
                    'edited',
                    `Editing complete: ${editedCount}/${greenCount} photos edited by Imagen`,
                    { photo_count: editedCount }
                );
                editState.set(folder, {
                    editedCount,
                    greenCount,
                    lastChanged: Date.now(),
                    completed: true,
                });
                // Create exports folder so Lightroom has an export destination
                if (EXPORTS_DIR) {
                    const exportFolder = path.join(EXPORTS_DIR, `${session.tracking_code} - ${session.client_name.replace(/[<>:"\/\\|?*]/g, '_').trim()}-Exports`);
                    if (!fs.existsSync(exportFolder)) {
                        fs.mkdirSync(exportFolder, { recursive: true });
                        log(`Created exports folder: ${path.basename(exportFolder)}/`);
                    }
                }
            }
        }
    }

    // ── Auto-import Imagen edits into Lightroom ─────────
    // Monitor FOR-IMAGEN folders in CULLED_DIR for XMP files with develop settings.
    // When Imagen writes edits back, copy RAW+XMP to LR Auto Import staging folder.
    if (LR_AUTO_IMPORT_DIR && CULLED_DIR && fs.existsSync(CULLED_DIR)) {
        const culledFolders = getTopLevelFolders(CULLED_DIR);

        for (const folder of culledFolders) {
            if (!folder.endsWith('-FOR-IMAGEN')) continue;

            const prev = imagenImportState.get(folder);
            if (prev && prev.importedToLR) continue;

            const folderPath = path.join(CULLED_DIR, folder);
            const { editedCount, greenCount } = countEditedPhotos(folderPath);

            if (editedCount === 0) continue;

            if (!prev || prev.editedCount !== editedCount) {
                log(`Imagen edits detected in CULLED folder -> "${folder}": ${editedCount}/${greenCount} photos have develop settings`);
                imagenImportState.set(folder, {
                    editedCount,
                    greenCount,
                    lastChanged: Date.now(),
                    importedToLR: false,
                });
            } else if (prev && prev.editedCount === editedCount) {
                // Stable — check if ready to copy to LR
                const elapsed = Date.now() - prev.lastChanged;
                const IMAGEN_STABLE_MS = (config.imagenStableSeconds || 120) * 1000;
                const editRatio = greenCount > 0 ? editedCount / greenCount : 0;

                // Copy when: stable for 2 min AND at least 80% have edits
                if (elapsed >= IMAGEN_STABLE_MS && editRatio >= 0.8) {
                    // Ensure LR Auto Import dir exists
                    if (!fs.existsSync(LR_AUTO_IMPORT_DIR)) {
                        fs.mkdirSync(LR_AUTO_IMPORT_DIR, { recursive: true });
                        log(`Created LR Auto Import directory: ${LR_AUTO_IMPORT_DIR}`);
                    }

                    const copied = copyToLRAutoImport(folderPath, folder);
                    if (copied > 0) {
                        log(`LR Auto Import: ${copied} Imagen-edited photos from CULLED folder -> "${folder}" → ${LR_AUTO_IMPORT_DIR}`);
                        imagenImportState.set(folder, {
                            editedCount,
                            greenCount,
                            lastChanged: Date.now(),
                            importedToLR: true,
                        });

                        // Advance session to "edited" if still in editing stage
                        const baseName = folder.replace(/-FOR-IMAGEN$/, '');
                        const session = matchFolderToSession(baseName, sessions);
                        if (session && session.current_stage === 'editing') {
                            await wpAdvanceStage(
                                session.tracking_code,
                                'edited',
                                `Editing complete: ${editedCount}/${greenCount} photos edited by Imagen (copied to LR Auto Import)`,
                                { photo_count: editedCount }
                            );
                            editState.set(baseName, {
                                editedCount,
                                greenCount,
                                lastChanged: Date.now(),
                                completed: true,
                            });
                        }
                    }
                }
            }
        }
    }

    // Scan exports directory — detect new exports + auto-upload to gallery
    // Stage flow: exporting → exported → uploading → uploaded → delivered (auto, sends email)
    // Works for BOTH Lightroom exports and Imagen JPG exports — anything in EXPORTS_DIR.
    if (EXPORTS_DIR && fs.existsSync(EXPORTS_DIR)) {
        const exportFolders = getTopLevelFolders(EXPORTS_DIR);

        for (const folder of exportFolders) {
            // Skip FOR-IMAGEN folders — these are Imagen input, not gallery exports
            if (folder.endsWith('-FOR-IMAGEN')) continue;

            const folderPath = path.join(EXPORTS_DIR, folder);
            const exportCount = countPhotosInFolder(folderPath);

            if (exportCount === 0) continue;

            const session = matchFolderToSession(folder, sessions);
            if (!session) continue;

            const prev = exportState.get(folder);

            if (!prev || prev.exportCount !== exportCount) {
                // New exports detected or count changed — still exporting
                log(`Exports "${folder}" → ${session.client_name} [${session.tracking_code}]: ${exportCount} exports (stage: ${session.current_stage})`);

                // Advance to 'exporting' if in early stages
                if (['imported', 'culling', 'culled', 'editing', 'edited'].includes(session.current_stage)) {
                    const res = await wpAdvanceStage(
                        session.tracking_code,
                        'exporting',
                        `Exporting photos (${exportCount} so far)`,
                        { photo_count: exportCount }
                    );
                    if (res) session.current_stage = 'exporting';
                }

                exportState.set(folder, {
                    exportCount,
                    lastChanged: Date.now(),
                    exported: false,
                    uploaded: false,
                    uploadedFiles: prev?.uploadedFiles || new Set(),
                });
                savePersistedState();

            } else if (prev && !prev.exported && prev.exportCount === exportCount) {
                // Export count stable — check if exports are done
                const elapsed = Date.now() - prev.lastChanged;
                const EXPORT_STABLE_MS = (config.exportStableSeconds || 60) * 1000;

                if (elapsed >= EXPORT_STABLE_MS) {
                    log(`Export complete for ${session.client_name}: ${exportCount} photos (stable for ${Math.round(elapsed / 1000)}s)`);

                    // Advance to 'exported' — accept both 'exporting' and 'edited'
                    // (Imagen may export JPGs directly without going through 'exporting' first)
                    if (['edited', 'exporting'].includes(session.current_stage)) {
                        const res = await wpAdvanceStage(
                            session.tracking_code,
                            'exported',
                            `${exportCount} photos exported`,
                            { photo_count: exportCount }
                        );
                        if (res) session.current_stage = 'exported';
                    }

                    exportState.set(folder, {
                        ...prev,
                        exported: true,
                    });
                    savePersistedState();

                    // Start auto-upload immediately if enabled
                    if (AUTO_UPLOAD) {
                        const serverFiles = await wpGetExistingPhotos(session.tracking_code);
                        if (serverFiles.size >= exportCount) {
                            log(`All ${serverFiles.size} photos already on server for ${session.client_name} — skipping upload`);
                            exportState.set(folder, {
                                ...prev,
                                exported: true,
                                uploaded: true,
                                uploadedFiles: serverFiles,
                            });
                            savePersistedState();

                            // Advance to 'uploaded' if not already past it
                            if (['exported', 'uploading'].includes(session.current_stage)) {
                                const res = await wpAdvanceStage(
                                    session.tracking_code,
                                    'uploaded',
                                    `All ${serverFiles.size} photos already uploaded`,
                                    { photo_count: serverFiles.size }
                                );
                                if (res) session.current_stage = 'uploaded';
                            }

                            // Auto-advance to 'delivered' — triggers client email
                            if (session.current_stage === 'uploaded') {
                                const res = await wpAdvanceStage(
                                    session.tracking_code,
                                    'delivered',
                                    `Gallery delivered with ${serverFiles.size} photos`,
                                    { photo_count: serverFiles.size }
                                );
                                if (res) {
                                    session.current_stage = 'delivered';
                                    log(`Gallery delivered for ${session.client_name} — notification email triggered`);
                                }
                            }
                        } else {
                            // Advance to 'uploading' before starting
                            if (['exported'].includes(session.current_stage)) {
                                const res = await wpAdvanceStage(
                                    session.tracking_code,
                                    'uploading',
                                    `Uploading ${exportCount} photos to gallery`,
                                    { photo_count: exportCount }
                                );
                                if (res) session.current_stage = 'uploading';
                            }

                            const alreadyCount = Math.max(serverFiles.size, prev?.uploadedFiles?.size || 0);
                            log(`Auto-uploading photos to gallery for ${session.client_name} (${alreadyCount} already uploaded)...`);

                            const galleryPassword = config.defaultGalleryPassword || '';
                            const result = await uploadExportFolder(session.tracking_code, folderPath, galleryPassword);

                            if (result.skipped) {
                                // Upload lock prevented concurrent upload — retry next scan
                            } else if (result.uploaded > 0 || result.failed === 0) {
                                log(`Gallery upload complete: ${result.uploaded} new + ${alreadyCount} existing for ${session.client_name}`);

                                // Advance to 'uploaded'
                                if (['uploading'].includes(session.current_stage)) {
                                    const res = await wpAdvanceStage(
                                        session.tracking_code,
                                        'uploaded',
                                        `${result.uploaded} photos uploaded to gallery`,
                                        { photo_count: result.total }
                                    );
                                    if (res) session.current_stage = 'uploaded';
                                }

                                // Auto-advance to 'delivered' — triggers client email
                                if (session.current_stage === 'uploaded') {
                                    const res = await wpAdvanceStage(
                                        session.tracking_code,
                                        'delivered',
                                        `Gallery delivered with ${result.total} photos`,
                                        { photo_count: result.total }
                                    );
                                    if (res) {
                                        session.current_stage = 'delivered';
                                        log(`Gallery delivered for ${session.client_name} — notification email triggered`);
                                    }
                                }

                                exportState.set(folder, {
                                    ...prev,
                                    exported: true,
                                    uploaded: true,
                                    uploadedFiles: result.uploadedFiles,
                                });
                                savePersistedState();
                            } else {
                                log(`Gallery upload had failures for ${session.client_name} — will retry next scan`);
                            }
                        }
                    }
                }

            } else if (prev && prev.exported && !prev.uploaded && AUTO_UPLOAD) {
                // Exported but not yet uploaded — retry upload
                const serverFiles = await wpGetExistingPhotos(session.tracking_code);
                if (serverFiles.size >= exportCount) {
                    log(`All ${serverFiles.size} photos now on server for ${session.client_name}`);
                    exportState.set(folder, {
                        ...prev,
                        uploaded: true,
                        uploadedFiles: serverFiles,
                    });
                    savePersistedState();

                    if (['exported', 'uploading'].includes(session.current_stage)) {
                        const res = await wpAdvanceStage(
                            session.tracking_code,
                            'uploaded',
                            `All ${serverFiles.size} photos uploaded`,
                            { photo_count: serverFiles.size }
                        );
                        if (res) session.current_stage = 'uploaded';
                    }

                    // Auto-advance to 'delivered' — triggers client email
                    if (session.current_stage === 'uploaded') {
                        const res = await wpAdvanceStage(
                            session.tracking_code,
                            'delivered',
                            `Gallery delivered with ${serverFiles.size} photos`,
                            { photo_count: serverFiles.size }
                        );
                        if (res) {
                            session.current_stage = 'delivered';
                            log(`Gallery delivered for ${session.client_name} — notification email triggered`);
                        }
                    }
                } else {
                    // Advance to 'uploading' if not already there
                    if (['exported'].includes(session.current_stage)) {
                        const res = await wpAdvanceStage(
                            session.tracking_code,
                            'uploading',
                            `Uploading photos to gallery`,
                            { photo_count: exportCount }
                        );
                        if (res) session.current_stage = 'uploading';
                    }

                    log(`Retrying upload for ${session.client_name}...`);
                    const galleryPassword = config.defaultGalleryPassword || '';
                    const result = await uploadExportFolder(session.tracking_code, folderPath, galleryPassword);

                    if (result.skipped) {
                        // retry next scan
                    } else {
                        if (result.uploaded > 0 || result.failed === 0) {
                            log(`Gallery upload complete: ${result.uploaded}/${result.total} for ${session.client_name}`);

                            if (['uploading'].includes(session.current_stage)) {
                                const res = await wpAdvanceStage(
                                    session.tracking_code,
                                    'uploaded',
                                    `${result.uploaded} photos uploaded to gallery`,
                                    { photo_count: result.total }
                                );
                                if (res) session.current_stage = 'uploaded';
                            }

                            // Auto-advance to 'delivered' — triggers client email
                            if (session.current_stage === 'uploaded') {
                                const res = await wpAdvanceStage(
                                    session.tracking_code,
                                    'delivered',
                                    `Gallery delivered with ${result.total} photos`,
                                    { photo_count: result.total }
                                );
                                if (res) {
                                    session.current_stage = 'delivered';
                                    log(`Gallery delivered for ${session.client_name} — notification email triggered`);
                                }
                            }
                        }

                        exportState.set(folder, {
                            ...prev,
                            uploaded: (result.failed === 0),
                            uploadedFiles: result.uploadedFiles,
                        });
                        savePersistedState();
                    }
                }
            }
        }
    }
}

// ── File Watcher (real-time) ───────────────────────────

function startWatcher() {
    if (!fs.existsSync(WATCH_DIR)) {
        log(`Watch directory does not exist: ${WATCH_DIR}`, 'error');
        log('Create it or update config.json, then restart.');
        process.exit(1);
    }

    log(`Watching RAWs:    ${WATCH_DIR}`);
    log(`Watching CULLED:  ${CULLED_DIR || '(not set)'}`);
    log(`Watching Exports: ${EXPORTS_DIR || '(not set)'}`);
    log(`LR Auto Import:   ${LR_AUTO_IMPORT_DIR || '(not set)'}`);
    log(`WordPress: ${WP_URL}`);
    log(`Auto-upload to gallery: ${AUTO_UPLOAD ? 'ON' : 'OFF'}`);
    log(`Poll interval: ${POLL_INTERVAL / 1000}s`);
    log('─'.repeat(50));

    // Initial scan
    scan();

    // Ensure CULLED_DIR exists on startup
    if (CULLED_DIR && !fs.existsSync(CULLED_DIR)) {
        fs.mkdirSync(CULLED_DIR, { recursive: true });
        log(`Created CULLED directory: ${CULLED_DIR}`);
    }

    // Watch for new files (debounced via polling)
    const watchPaths = [WATCH_DIR];
    if (CULLED_DIR && fs.existsSync(CULLED_DIR)) watchPaths.push(CULLED_DIR);
    if (EXPORTS_DIR && fs.existsSync(EXPORTS_DIR)) watchPaths.push(EXPORTS_DIR);

    const watcher = chokidar.watch(watchPaths, {
        depth: 3,
        ignoreInitial: true,
        ignored: /(^|[\/\\])\../, // ignore dotfiles
        persistent: true,
        awaitWriteFinish: {
            stabilityThreshold: 5000,
            pollInterval: 1000,
        },
    });

    let debounceTimer = null;
    let xmpDebounceTimer = null;
    let scanInProgress = false;

    function isXmpFile(filePath) {
        return path.extname(filePath).toLowerCase() === '.xmp';
    }

    // Wrap scan to prevent overlapping scans (which would hammer XMP files)
    async function safeScan(reason) {
        if (scanInProgress) {
            log(`Scan already in progress, skipping (${reason})`);
            return;
        }
        scanInProgress = true;
        try {
            log(reason);
            await scan();
        } finally {
            scanInProgress = false;
        }
    }

    watcher.on('add', (filePath) => {
        if (!isPhotoFile(filePath) && !isXmpFile(filePath)) return;

        // Debounce: wait 10s after last file add before scanning
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            safeScan('New files detected, scanning...');
        }, 10000);
    });

    // Also trigger on XMP changes (Lightroom writes labels to existing sidecars)
    // Use a LONGER debounce (15s) — Lightroom writes XMP files in batches
    // and we need to wait for the full batch to finish before reading them
    watcher.on('change', (filePath) => {
        if (!isXmpFile(filePath)) return;

        clearTimeout(xmpDebounceTimer);
        xmpDebounceTimer = setTimeout(() => {
            safeScan('XMP sidecars updated, scanning for label changes...');
        }, 15000); // 15s after the LAST xmp change — gives LR time to finish the batch
    });

    // Also poll periodically (uses safeScan to avoid overlapping with event-driven scans)
    setInterval(() => safeScan('Periodic scan...'), POLL_INTERVAL);


    // Graceful shutdown
    process.on('SIGINT', () => {
        log('Shutting down...');
        watcher.close();
        process.exit(0);
    });
}

// ── Logging ────────────────────────────────────────────

function log(message, level = 'info') {
    const time = new Date().toLocaleTimeString();
    const prefix = level === 'error' ? '[ERROR]' : '[INFO]';
    console.log(`${time} ${prefix} ${message}`);
}

// ── Start ──────────────────────────────────────────────

console.log('');
console.log('  Tweller Flow — Folder Watcher v2.1');
console.log('  ─────────────────────────────────────');
console.log('');

startWatcher();
