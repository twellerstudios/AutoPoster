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
const WP_URL = config.wordpressUrl.replace(/\/$/, '');
const API_KEY = config.apiKey;
const PHOTO_EXT = new Set(config.photoExtensions.map(e => e.toLowerCase()));
const POLL_INTERVAL = (config.pollIntervalSeconds || 30) * 1000;

// Track known folders and their state
const folderState = new Map(); // folderName -> { photoCount, lastNotified, stage }
const createdSessions = new Set(); // tracking codes we already created folders for

// ── WordPress API ──────────────────────────────────────

async function wpAdvanceStage(sessionCode, targetStage, notes = '', extras = {}) {
    const url = `${WP_URL}/wp-json/tweller-flow/v1/automation/advance`;
    try {
        const res = await axios.post(url, {
            session_code: sessionCode,
            target_stage: targetStage,
            notes: notes,
            ...extras,
        }, {
            headers: API_KEY ? { 'Authorization': `Bearer ${API_KEY}` } : {},
            timeout: 10000,
        });
        log(`Stage updated: ${sessionCode} → ${targetStage}`);
        return res.data;
    } catch (err) {
        log(`Failed to update stage: ${err.message}`, 'error');
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

function getTopLevelFolders() {
    try {
        return fs.readdirSync(WATCH_DIR)
            .filter(f => {
                const fullPath = path.join(WATCH_DIR, f);
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

    // Create folder with raw subfolder for importing
    const rawPath = path.join(folderPath, 'raw');
    fs.mkdirSync(rawPath, { recursive: true });

    createdSessions.add(session.tracking_code);
    log(`Created folder: ${folderName}/raw/ — ready for import`);
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
        const rawPath = path.join(folderPath, 'raw');
        const rawCount = fs.existsSync(rawPath) ? countPhotosInFolder(rawPath) : 0;
        const totalCount = countPhotosInFolder(folderPath);

        if (totalCount === 0) continue;

        const session = matchFolderToSession(folder, sessions);
        if (!session) {
            // Only log unmatched once
            if (!folderState.has(folder)) {
                log(`Unmatched folder: "${folder}" (${totalCount} photos) — no session found`);
                folderState.set(folder, { photoCount: totalCount, stage: 'unmatched' });
            }
            continue;
        }

        const prev = folderState.get(folder);
        const exportCount = totalCount - rawCount;

        // New folder detected or photo count changed
        if (!prev || prev.photoCount !== totalCount || prev.exportCount !== exportCount) {
            log(`Folder "${folder}" → ${session.client_name} [${session.tracking_code}]: ${rawCount} raws, ${exportCount} exports`);

            // Auto-advance to "imported" when raws appear
            if (session.current_stage === 'booked' && rawCount > 0) {
                await wpAdvanceStage(
                    session.tracking_code,
                    'imported',
                    `${rawCount} RAW files imported to ${folder}/raw/`,
                    { photo_count: rawCount }
                );
            }

            // Auto-advance to "edited" when exports appear outside raw/
            if (session.current_stage === 'imported' && exportCount > 0) {
                await wpAdvanceStage(
                    session.tracking_code,
                    'edited',
                    `${exportCount} edited photos exported`,
                    { photo_count: exportCount }
                );
            }

            folderState.set(folder, {
                photoCount: totalCount,
                exportCount,
                stage: session.current_stage,
                lastNotified: Date.now(),
            });
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

    log(`Watching: ${WATCH_DIR}`);
    log(`WordPress: ${WP_URL}`);
    log(`Poll interval: ${POLL_INTERVAL / 1000}s`);
    log('─'.repeat(50));

    // Initial scan
    scan();

    // Watch for new files (debounced via polling)
    const watcher = chokidar.watch(WATCH_DIR, {
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

    watcher.on('add', (filePath) => {
        if (!isPhotoFile(filePath)) return;

        // Debounce: wait 10s after last file add before scanning
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            log('New files detected, scanning...');
            scan();
        }, 10000);
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
