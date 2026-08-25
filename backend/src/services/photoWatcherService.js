/**
 * Photo Folder Watcher Service
 *
 * Monitors a root session photos directory for changes and triggers
 * the automation pipeline when new photos arrive.
 *
 * Expected folder structure:
 *   {watchDir}/
 *     {session_tracking_code}/
 *       raw/          ← Drop RAW/JPEG imports here → triggers culling
 *       _keeps/       ← Auto-populated by culling service
 *       _rejects/     ← Auto-populated by culling service
 *       edited/       ← Auto-populated by Imagen AI / auto-edit
 *       final/        ← Manual final tweaks (optional)
 *       gallery/      ← Final deliverables for client
 *
 * Uses chokidar for efficient filesystem watching with debouncing.
 */
const chokidar = require('chokidar');
const path = require('path');
const fs = require('fs').promises;
const EventEmitter = require('events');

class PhotoWatcher extends EventEmitter {
  constructor(options = {}) {
    super();

    this.watchDir = options.watchDir || process.env.PHOTO_WATCH_DIR || '';
    this.debounceMs = options.debounceMs || 5000; // Wait 5s after last file to trigger
    this.enabled = !!this.watchDir;

    // Track pending sessions (debounce timers per session folder)
    this.pendingTimers = new Map();
    this.pendingFiles = new Map();
    this.watcher = null;
    this.isWatching = false;
  }

  /**
   * Start watching the directory.
   */
  async start() {
    if (!this.enabled) {
      console.log('[Watcher] PHOTO_WATCH_DIR not set — folder watching disabled');
      return;
    }

    // Ensure watch directory exists
    try {
      await fs.mkdir(this.watchDir, { recursive: true });
    } catch (err) {
      console.error(`[Watcher] Cannot create watch dir ${this.watchDir}:`, err.message);
      return;
    }

    console.log(`[Watcher] Watching: ${this.watchDir}`);

    const imagePattern = path.join(this.watchDir, '**/*.{jpg,jpeg,png,tif,tiff,webp,cr2,cr3,nef,arw,dng,orf,rw2,raf}');

    this.watcher = chokidar.watch(imagePattern, {
      ignored: [
        /(^|[\/\\])\../, // Ignore dotfiles
        /\/_keeps\//,     // Ignore our output folders
        /\/_rejects\//,
        /\/edited\//,
        /\/gallery\//,
      ],
      persistent: true,
      ignoreInitial: true,  // Don't trigger on existing files
      awaitWriteFinish: {
        stabilityThreshold: 2000, // Wait 2s after write stops
        pollInterval: 500,
      },
      depth: 3, // {watchDir}/{session}/{subfolder}/{file}
    });

    this.watcher
      .on('add', (filePath) => this._onFileAdded(filePath))
      .on('error', (err) => console.error('[Watcher] Error:', err.message));

    this.isWatching = true;
    console.log('[Watcher] Started. Waiting for new photos...');
  }

  /**
   * Stop watching.
   */
  async stop() {
    if (this.watcher) {
      await this.watcher.close();
      this.isWatching = false;
      console.log('[Watcher] Stopped');
    }

    // Clear all pending timers
    for (const timer of this.pendingTimers.values()) {
      clearTimeout(timer);
    }
    this.pendingTimers.clear();
    this.pendingFiles.clear();
  }

  /**
   * Handle a new file being detected.
   * Groups files by session and debounces to batch process.
   */
  _onFileAdded(filePath) {
    // Determine which session this belongs to
    const relativePath = path.relative(this.watchDir, filePath);
    const parts = relativePath.split(path.sep);

    if (parts.length < 2) {
      // File directly in watch root — not a session folder
      return;
    }

    const sessionCode = parts[0]; // First folder = session tracking code
    const subfolder = parts.length >= 3 ? parts[1] : 'raw';

    console.log(`[Watcher] New file: ${relativePath} (session: ${sessionCode}, folder: ${subfolder})`);

    // Track pending files for this session
    if (!this.pendingFiles.has(sessionCode)) {
      this.pendingFiles.set(sessionCode, []);
    }
    this.pendingFiles.get(sessionCode).push({
      file: filePath,
      subfolder,
      addedAt: new Date(),
    });

    // Reset debounce timer for this session
    if (this.pendingTimers.has(sessionCode)) {
      clearTimeout(this.pendingTimers.get(sessionCode));
    }

    const timer = setTimeout(() => {
      this._triggerSession(sessionCode);
    }, this.debounceMs);

    this.pendingTimers.set(sessionCode, timer);
  }

  /**
   * Trigger processing for a session after debounce.
   */
  _triggerSession(sessionCode) {
    const files = this.pendingFiles.get(sessionCode) || [];
    this.pendingFiles.delete(sessionCode);
    this.pendingTimers.delete(sessionCode);

    if (files.length === 0) return;

    // Determine which subfolder got the files
    const subfolders = [...new Set(files.map(f => f.subfolder))];
    const sessionDir = path.join(this.watchDir, sessionCode);

    console.log(`[Watcher] Triggering session ${sessionCode}: ${files.length} new files in ${subfolders.join(', ')}`);

    // Emit event for the pipeline to handle
    this.emit('photos-detected', {
      sessionCode,
      sessionDir,
      files: files.map(f => f.file),
      subfolders,
      fileCount: files.length,
      timestamp: new Date(),
    });
  }

  /**
   * Create session folder structure for a new session.
   */
  async createSessionFolders(sessionCode) {
    if (!this.enabled) return null;

    const sessionDir = path.join(this.watchDir, sessionCode);
    const folders = ['raw', 'edited', 'final', 'gallery'];

    for (const folder of folders) {
      await fs.mkdir(path.join(sessionDir, folder), { recursive: true });
    }

    console.log(`[Watcher] Created session folders: ${sessionDir}`);
    return sessionDir;
  }

  /**
   * Get the status of a session's photo folder.
   */
  async getSessionFolderStatus(sessionCode) {
    if (!this.enabled) return null;

    const sessionDir = path.join(this.watchDir, sessionCode);

    try {
      await fs.access(sessionDir);
    } catch {
      return null;
    }

    const countFiles = async (dir) => {
      try {
        const entries = await fs.readdir(dir, { withFileTypes: true });
        return entries.filter(e => e.isFile()).length;
      } catch {
        return 0;
      }
    };

    return {
      sessionCode,
      sessionDir,
      raw: await countFiles(path.join(sessionDir, 'raw')),
      keeps: await countFiles(path.join(sessionDir, '_keeps')),
      rejects: await countFiles(path.join(sessionDir, '_rejects')),
      edited: await countFiles(path.join(sessionDir, 'edited')),
      final: await countFiles(path.join(sessionDir, 'final')),
      gallery: await countFiles(path.join(sessionDir, 'gallery')),
    };
  }
}

module.exports = { PhotoWatcher };
