const { app, BrowserWindow, ipcMain, shell } = require('electron');
const { spawn } = require('child_process');
const path = require('path');
const fs   = require('fs');

// ── Paths ────────────────────────────────────────────────────────────────────
// When packaged the launcher is installed separately; the repo root is one
// level up from the launcher folder in dev, or pointed to by REPO_ROOT env.
const REPO_ROOT   = process.env.REPO_ROOT || path.resolve(__dirname, '..');
const WATCHER_DIR = path.join(REPO_ROOT, 'watcher');
const CONFIG_PATH = path.join(WATCHER_DIR, 'config.json');

// ── State ────────────────────────────────────────────────────────────────────
let mainWindow     = null;
let watcherProcess = null;

// ── Window ───────────────────────────────────────────────────────────────────
function createWindow() {
    mainWindow = new BrowserWindow({
        width:  900,
        height: 660,
        minWidth:  760,
        minHeight: 520,
        backgroundColor: '#0f172a',
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
        },
        title: 'Tweller Launcher',
        autoHideMenuBar: true,
    });
    mainWindow.loadFile(path.join(__dirname, 'index.html'));
}

app.whenReady().then(createWindow);

app.on('window-all-closed', () => {
    if (watcherProcess) watcherProcess.kill();
    app.quit();
});

// ── Helpers ──────────────────────────────────────────────────────────────────
function getConfig() {
    try { return JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8')); }
    catch { return {}; }
}

function emit(msg, type = 'info') {
    if (mainWindow && !mainWindow.isDestroyed()) {
        mainWindow.webContents.send('log', {
            msg,
            type,
            time: new Date().toLocaleTimeString(),
        });
    }
}

function setWatcherStatus(running) {
    if (mainWindow && !mainWindow.isDestroyed()) {
        mainWindow.webContents.send('watcher-status', running);
    }
}

function pipeProcess(proc, label) {
    proc.stdout && proc.stdout.on('data', d => {
        d.toString().split('\n').filter(Boolean).forEach(l => emit(l, 'info'));
    });
    proc.stderr && proc.stderr.on('data', d => {
        d.toString().split('\n').filter(Boolean).forEach(l => emit(l, 'warn'));
    });
    return proc;
}

// ── IPC: get-status ──────────────────────────────────────────────────────────
ipcMain.handle('get-status', () => ({
    watcherRunning: !!watcherProcess,
    repoRoot: REPO_ROOT,
    config: getConfig(),
}));

// ── IPC: start-watcher ───────────────────────────────────────────────────────
ipcMain.handle('start-watcher', () => {
    if (watcherProcess) return { ok: false, error: 'Already running' };

    // Install dependencies first if node_modules is missing
    if (!fs.existsSync(path.join(WATCHER_DIR, 'node_modules'))) {
        emit('node_modules not found — running npm install…', 'warn');
        const install = spawn('npm', ['install'], { cwd: WATCHER_DIR, shell: true });
        pipeProcess(install, 'npm install');
        install.on('close', code => {
            if (code !== 0) { emit('npm install failed', 'error'); return; }
            emit('npm install complete — starting watcher…', 'success');
            launchWatcher();
        });
    } else {
        launchWatcher();
    }
    return { ok: true };
});

function launchWatcher() {
    watcherProcess = spawn('node', ['watcher.js'], {
        cwd: WATCHER_DIR,
        shell: true,
    });
    pipeProcess(watcherProcess);
    watcherProcess.on('close', code => {
        emit(`Watcher stopped (exit ${code ?? '—'})`, code === 0 ? 'info' : 'error');
        watcherProcess = null;
        setWatcherStatus(false);
    });
    emit('Watcher started', 'success');
    setWatcherStatus(true);
}

// ── IPC: stop-watcher ────────────────────────────────────────────────────────
ipcMain.handle('stop-watcher', () => {
    if (!watcherProcess) return { ok: false, error: 'Not running' };
    watcherProcess.kill();
    watcherProcess = null;
    emit('Watcher stopped', 'warn');
    setWatcherStatus(false);
    return { ok: true };
});

// ── IPC: git-pull ────────────────────────────────────────────────────────────
ipcMain.handle('git-pull', () => new Promise(resolve => {
    emit('git pull…', 'info');
    const proc = spawn('git', ['pull'], { cwd: REPO_ROOT, shell: true });
    pipeProcess(proc);
    proc.on('close', code => {
        if (code === 0) { emit('Git pull complete ✓', 'success'); resolve({ ok: true }); }
        else            { emit(`Git pull failed (exit ${code})`, 'error');  resolve({ ok: false }); }
    });
}));

// ── IPC: build-zip ───────────────────────────────────────────────────────────
ipcMain.handle('build-zip', () => new Promise(resolve => {
    emit('Building tweller-flow.zip…', 'info');
    const zipPath = path.join(REPO_ROOT, 'tweller-flow.zip');
    const srcPath = path.join(REPO_ROOT, 'tweller-flow');
    const cmd = [
        `Remove-Item -Force "${zipPath}" -ErrorAction SilentlyContinue;`,
        `Compress-Archive -Path "${srcPath}" -DestinationPath "${zipPath}"`,
    ].join(' ');
    const proc = spawn('powershell', ['-Command', cmd], { shell: true });
    pipeProcess(proc);
    proc.on('close', code => {
        if (code === 0) { emit(`ZIP ready: ${zipPath} ✓`, 'success'); resolve({ ok: true }); }
        else            { emit(`ZIP build failed (exit ${code})`, 'error');  resolve({ ok: false }); }
    });
}));

// ── IPC: open-wp-admin ───────────────────────────────────────────────────────
ipcMain.handle('open-wp-admin', () => {
    const url = (getConfig().wordpressUrl || 'https://twellerstudios.com') + '/wp-admin/admin.php?page=tweller-flow-galleries';
    shell.openExternal(url);
    return { ok: true };
});

// ── IPC: open-folder ─────────────────────────────────────────────────────────
ipcMain.handle('open-folder', (_, folder) => {
    const target = folder === 'repo'    ? REPO_ROOT
                 : folder === 'watcher' ? WATCHER_DIR
                 : folder === 'exports' ? (getConfig().exportsDir || REPO_ROOT)
                 : REPO_ROOT;
    shell.openPath(target);
    return { ok: true };
});
