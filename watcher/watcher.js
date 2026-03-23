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
const EXPORTS_DIR = config.exportsDir;
const WP_URL = config.wordpressUrl.replace(/\/$/, '');
const API_KEY = config.apiKey;
const PHOTO_EXT = new Set(config.photoExtensions.map(e => e.toLowerCase()));
const POLL_INTERVAL = (config.pollIntervalSeconds || 30) * 1000;

// Track known folders and their state
const folderState = new Map(); // folderName -> { photoCount, lastNotified, stage }
const exportState = new Map(); // folderName -> { exportCount, lastNotified }
const cullState = new Map();   // folderName -> { greenCount, totalPhotos, lastNotified }
const createdSessions = new Set(); // tracking codes we already created folders for

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
 */
function hasGreenLabel(xmpPath) {
    try {
        const content = fs.readFileSync(xmpPath, 'utf-8');
        // Match both attribute form and element form
        return /xmp:Label\s*=\s*"Green"/i.test(content) ||
               /<xmp:Label>\s*Green\s*<\/xmp:Label>/i.test(content);
    } catch {
        return false;
    }
}

/**
 * Find XMP sidecar for a given photo file.
 * Lightroom writes sidecars as: photo.cr3 -> photo.cr3.xmp or photo.xmp
 */
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

    createdSessions.add(session.tracking_code);
    log(`Created folder: ${folderName}/ — set as Lightroom import destination`);
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
            }
        }
    }

    // Scan exports directory
    if (EXPORTS_DIR && fs.existsSync(EXPORTS_DIR)) {
        const exportFolders = getTopLevelFolders(EXPORTS_DIR);

        for (const folder of exportFolders) {
            const folderPath = path.join(EXPORTS_DIR, folder);
            const exportCount = countPhotosInFolder(folderPath);

            if (exportCount === 0) continue;

            const session = matchFolderToSession(folder, sessions);
            if (!session) continue;

            const prev = exportState.get(folder);

            if (!prev || prev.exportCount !== exportCount) {
                log(`Exports "${folder}" → ${session.client_name} [${session.tracking_code}]: ${exportCount} exports`);

                if (session.current_stage === 'imported') {
                    await wpAdvanceStage(
                        session.tracking_code,
                        'edited',
                        `${exportCount} edited photos exported`,
                        { photo_count: exportCount }
                    );
                }

                exportState.set(folder, {
                    exportCount,
                    lastNotified: Date.now(),
                });
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

    log(`Watching RAWs:   ${WATCH_DIR}`);
    log(`Watching Exports: ${EXPORTS_DIR || '(not set)'}`);
    log(`WordPress: ${WP_URL}`);
    log(`Poll interval: ${POLL_INTERVAL / 1000}s`);
    log('─'.repeat(50));

    // Initial scan
    scan();

    // Watch for new files (debounced via polling)
    const watchPaths = [WATCH_DIR];
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

    function isXmpFile(filePath) {
        return path.extname(filePath).toLowerCase() === '.xmp';
    }

    watcher.on('add', (filePath) => {
        if (!isPhotoFile(filePath) && !isXmpFile(filePath)) return;

        // Debounce: wait 10s after last file add before scanning
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            log('New files detected, scanning...');
            scan();
        }, 10000);
    });

    // Also trigger on XMP changes (Lightroom writes labels to existing sidecars)
    watcher.on('change', (filePath) => {
        if (!isXmpFile(filePath)) return;

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            log('XMP sidecar updated, scanning for label changes...');
            scan();
        }, 5000);
    });

    // Also poll periodically
    setInterval(scan, POLL_INTERVAL);

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
console.log('  Tweller Flow — Folder Watcher v2.0');
console.log('  ─────────────────────────────────────');
console.log('');

startWatcher();
