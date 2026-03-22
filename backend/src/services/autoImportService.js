/**
 * Auto-Import Service
 *
 * Watches a generic import directory (e.g., SD card mount or dump folder)
 * for new photos. When photos arrive:
 *
 *   1. Wait for file transfers to finish (debounce)
 *   2. Read EXIF timestamps from all new photos
 *   3. Group photos by capture time window
 *   4. Match each group to a WordPress session by date/time
 *   5. Move photos into the correct session folder ({watchDir}/{CODE}/raw/)
 *   6. The existing PhotoWatcher picks up the files and triggers the pipeline
 *
 * Also handles:
 *   - Unmatched photos → moved to {watchDir}/_unmatched/{date}/ for manual sorting
 *   - Duplicate import prevention via tracking imported file hashes
 *   - Manual import trigger via REST API
 */
const chokidar = require('chokidar');
const path = require('path');
const fs = require('fs').promises;
const crypto = require('crypto');
const EventEmitter = require('events');
const { readCaptureTime, readAllTimestamps, groupBySession, SUPPORTED_EXTS } = require('./exifService');
const { SessionMatcher } = require('./sessionMatcherService');

class AutoImporter extends EventEmitter {
  constructor(options = {}) {
    super();

    this.importDir = options.importDir || process.env.PHOTO_IMPORT_DIR || '';
    this.watchDir = options.watchDir || process.env.PHOTO_WATCH_DIR || '';
    this.debounceMs = options.debounceMs || parseInt(process.env.IMPORT_DEBOUNCE_MS || '10000', 10);
    this.gapMinutes = options.gapMinutes || parseInt(process.env.SESSION_GAP_MINUTES || '120', 10);
    this.autoTriggerPipeline = options.autoTriggerPipeline !== false;

    this.enabled = !!(this.importDir && this.watchDir);
    this.matcher = new SessionMatcher(options);

    // Track imported files to prevent re-processing
    this.importedHashes = new Set();
    this.watcher = null;
    this.isWatching = false;

    // Debounce: accumulate files, then process batch
    this.pendingFiles = [];
    this.debounceTimer = null;

    // Import history for status/debugging
    this.importHistory = [];
  }

  /**
   * Start watching the import directory for new photos.
   */
  async start() {
    if (!this.enabled) {
      console.log('[Import] PHOTO_IMPORT_DIR not set — auto-import disabled');
      return;
    }

    // Ensure directories exist
    await fs.mkdir(this.importDir, { recursive: true });
    await fs.mkdir(this.watchDir, { recursive: true });
    await fs.mkdir(path.join(this.watchDir, '_unmatched'), { recursive: true });

    console.log(`[Import] Watching import directory: ${this.importDir}`);
    console.log(`[Import] Session folders: ${this.watchDir}`);

    // Build glob for image files
    const extList = [...SUPPORTED_EXTS].map((e) => e.slice(1)).join(',');
    const imagePattern = path.join(this.importDir, `**/*.{${extList}}`);

    this.watcher = chokidar.watch(imagePattern, {
      ignored: /(^|[\/\\])\../, // Ignore dotfiles
      persistent: true,
      ignoreInitial: true,
      awaitWriteFinish: {
        stabilityThreshold: 3000, // Wait 3s after write stops (SD cards can be slow)
        pollInterval: 1000,
      },
      depth: 5, // Support nested folder structures from cameras
    });

    this.watcher
      .on('add', (filePath) => this._onFileDetected(filePath))
      .on('error', (err) => console.error('[Import] Watcher error:', err.message));

    this.isWatching = true;
    console.log('[Import] Watching for new photos (e.g., SD card, import folder)...');
  }

  /**
   * Stop watching.
   */
  async stop() {
    if (this.watcher) {
      await this.watcher.close();
      this.isWatching = false;
      console.log('[Import] Stopped');
    }
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
      this.debounceTimer = null;
    }
  }

  /**
   * Handle a new file being detected in the import directory.
   */
  _onFileDetected(filePath) {
    console.log(`[Import] New file: ${path.relative(this.importDir, filePath)}`);
    this.pendingFiles.push(filePath);

    // Reset debounce timer — wait for more files
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }

    this.debounceTimer = setTimeout(() => {
      const files = [...this.pendingFiles];
      this.pendingFiles = [];
      this.debounceTimer = null;

      this._processImportBatch(files).catch((err) => {
        console.error('[Import] Batch processing error:', err.message);
      });
    }, this.debounceMs);
  }

  /**
   * Process a batch of newly detected photos.
   * This is the main workflow: read EXIF → group → match → sort → trigger.
   */
  async _processImportBatch(files) {
    const batchId = Date.now();
    console.log(`[Import] Processing batch of ${files.length} files...`);

    // Filter to only new files (skip already imported)
    const newFiles = [];
    for (const file of files) {
      const hash = await this._quickHash(file);
      if (hash && !this.importedHashes.has(hash)) {
        newFiles.push(file);
        this.importedHashes.add(hash);
      } else {
        console.log(`[Import] Skipping duplicate: ${path.basename(file)}`);
      }
    }

    if (newFiles.length === 0) {
      console.log('[Import] All files already imported — skipping');
      return;
    }

    // Read timestamps from all new files
    const photos = [];
    for (const file of newFiles) {
      const captureTime = await readCaptureTime(file);
      photos.push({
        file,
        filename: path.basename(file),
        captureTime,
      });
    }

    // Group by session time windows
    const groups = groupBySession(photos, this.gapMinutes);
    console.log(`[Import] ${newFiles.length} photos grouped into ${groups.length} session(s)`);

    // Match and sort each group
    const importResult = {
      batchId,
      timestamp: new Date(),
      totalPhotos: newFiles.length,
      groups: [],
    };

    for (const group of groups) {
      const result = await this._matchAndSort(group);
      importResult.groups.push(result);
    }

    // Record in history
    this.importHistory.unshift(importResult);
    if (this.importHistory.length > 50) {
      this.importHistory = this.importHistory.slice(0, 50);
    }

    // Emit event for pipeline integration
    this.emit('import-complete', importResult);

    console.log('[Import] Batch complete:', this._summarizeResult(importResult));

    return importResult;
  }

  /**
   * Match a photo group to a session and move files to the session folder.
   */
  async _matchAndSort(group) {
    // Build sessions list from WordPress
    const dates = group.date ? [group.date] : [];

    // Fetch sessions for this date
    let sessions = [];
    for (const date of dates) {
      const dateSessions = await this.matcher.fetchSessionsByDate(date);
      sessions.push(...dateSessions);
    }

    // If no sessions on exact date, try recent
    if (sessions.length === 0) {
      sessions = await this.matcher.fetchRecentSessions();
    }

    // Match
    const match = this.matcher.matchGroupToSession(group, sessions);

    const result = {
      date: group.date,
      startTime: group.startTime,
      endTime: group.endTime,
      photoCount: group.photos.length,
      matched: !!match.session,
      confidence: match.confidence,
      reason: match.reason,
      sessionCode: match.session?.tracking_code || null,
      clientName: match.session?.client_name || null,
      movedTo: null,
    };

    if (match.session && match.confidence !== 'none') {
      // Move to session folder
      const sessionCode = match.session.tracking_code;
      const destDir = path.join(this.watchDir, sessionCode, 'raw');
      await fs.mkdir(destDir, { recursive: true });

      // Also ensure the full session folder structure exists
      for (const sub of ['edited', 'final', 'gallery']) {
        await fs.mkdir(path.join(this.watchDir, sessionCode, sub), { recursive: true });
      }

      let movedCount = 0;
      for (const photo of group.photos) {
        try {
          const dest = path.join(destDir, photo.filename);
          await this._moveFile(photo.file, dest);
          movedCount++;
        } catch (err) {
          console.warn(`[Import] Failed to move ${photo.filename}: ${err.message}`);
        }
      }

      result.movedTo = destDir;
      result.movedCount = movedCount;

      // Advance WP session to session_complete if it's still in a pre-photo stage
      if (match.session.current_stage && ['booked', 'deposit_received', 'session_scheduled'].includes(match.session.current_stage)) {
        await this._advanceToSessionComplete(sessionCode);
      }

      console.log(
        `[Import] ✓ ${movedCount} photos → ${sessionCode}/raw/ (${match.session.client_name})`
      );
    } else {
      // Move to unmatched folder organized by date
      const dateStr = group.date || 'unknown-date';
      const unmatchedDir = path.join(this.watchDir, '_unmatched', dateStr);
      await fs.mkdir(unmatchedDir, { recursive: true });

      let movedCount = 0;
      for (const photo of group.photos) {
        try {
          const dest = path.join(unmatchedDir, photo.filename);
          await this._moveFile(photo.file, dest);
          movedCount++;
        } catch (err) {
          console.warn(`[Import] Failed to move ${photo.filename}: ${err.message}`);
        }
      }

      result.movedTo = unmatchedDir;
      result.movedCount = movedCount;

      console.log(`[Import] ⚠ ${movedCount} photos → _unmatched/${dateStr}/ (${match.reason})`);
    }

    return result;
  }

  /**
   * Manually trigger import for photos already in the import directory.
   */
  async manualImport() {
    console.log('[Import] Manual import triggered...');

    const files = await this._listAllPhotos(this.importDir);
    if (files.length === 0) {
      return { ok: true, message: 'No photos found in import directory', groups: [] };
    }

    return await this._processImportBatch(files);
  }

  /**
   * Manually assign unmatched photos to a session.
   *
   * @param {string} dateOrDir - Date string (YYYY-MM-DD) or subdirectory name in _unmatched/
   * @param {string} sessionCode - Target session tracking code
   */
  async assignUnmatched(dateOrDir, sessionCode) {
    const unmatchedDir = path.join(this.watchDir, '_unmatched', dateOrDir);
    const destDir = path.join(this.watchDir, sessionCode, 'raw');

    try {
      await fs.access(unmatchedDir);
    } catch {
      throw new Error(`No unmatched photos found for ${dateOrDir}`);
    }

    await fs.mkdir(destDir, { recursive: true });

    // Ensure full folder structure
    for (const sub of ['edited', 'final', 'gallery']) {
      await fs.mkdir(path.join(this.watchDir, sessionCode, sub), { recursive: true });
    }

    const photos = await this._listAllPhotos(unmatchedDir);
    let movedCount = 0;

    for (const file of photos) {
      try {
        const dest = path.join(destDir, path.basename(file));
        await this._moveFile(file, dest);
        movedCount++;
      } catch (err) {
        console.warn(`[Import] Failed to move ${path.basename(file)}: ${err.message}`);
      }
    }

    // Clean up empty unmatched dir
    try {
      const remaining = await fs.readdir(unmatchedDir);
      if (remaining.length === 0) {
        await fs.rmdir(unmatchedDir);
      }
    } catch {
      // ignore
    }

    console.log(`[Import] Manually assigned ${movedCount} photos to session ${sessionCode}`);

    return {
      ok: true,
      movedCount,
      sessionCode,
      source: dateOrDir,
    };
  }

  /**
   * List unmatched photo folders.
   */
  async getUnmatched() {
    const unmatchedRoot = path.join(this.watchDir, '_unmatched');
    try {
      const entries = await fs.readdir(unmatchedRoot, { withFileTypes: true });
      const folders = [];

      for (const entry of entries) {
        if (entry.isDirectory()) {
          const dirPath = path.join(unmatchedRoot, entry.name);
          const photos = await this._listAllPhotos(dirPath);
          folders.push({
            name: entry.name,
            photoCount: photos.length,
            path: dirPath,
          });
        }
      }

      return folders;
    } catch {
      return [];
    }
  }

  // ── Private helpers ──────────────────────────────────────────────────────────

  /**
   * Move a file (try rename first for speed, fall back to copy+delete for cross-device).
   */
  async _moveFile(src, dest) {
    try {
      await fs.rename(src, dest);
    } catch (err) {
      if (err.code === 'EXDEV') {
        // Cross-device move — copy then delete
        await fs.copyFile(src, dest);
        await fs.unlink(src);
      } else {
        throw err;
      }
    }
  }

  /**
   * Quick hash of file (first 8KB + size) for duplicate detection.
   */
  async _quickHash(filePath) {
    try {
      const stat = await fs.stat(filePath);
      const handle = await fs.open(filePath, 'r');
      const buffer = Buffer.alloc(Math.min(8192, stat.size));
      await handle.read(buffer, 0, buffer.length, 0);
      await handle.close();

      const hash = crypto.createHash('md5');
      hash.update(buffer);
      hash.update(String(stat.size));
      return hash.digest('hex');
    } catch {
      return null;
    }
  }

  /**
   * List all photo files in a directory recursively.
   */
  async _listAllPhotos(dir) {
    const results = [];

    async function walk(currentDir) {
      const entries = await fs.readdir(currentDir, { withFileTypes: true });
      for (const entry of entries) {
        const fullPath = path.join(currentDir, entry.name);
        if (entry.isDirectory()) {
          await walk(fullPath);
        } else if (entry.isFile() && SUPPORTED_EXTS.has(path.extname(entry.name).toLowerCase())) {
          results.push(fullPath);
        }
      }
    }

    try {
      await walk(dir);
    } catch {
      // Directory doesn't exist or can't be read
    }

    return results;
  }

  /**
   * Advance session to session_complete via WP REST API.
   */
  async _advanceToSessionComplete(sessionCode) {
    if (!this.matcher.wpUrl) return;

    try {
      await axios.post(
        `${this.matcher.wpUrl}/wp-json/tweller-flow/v1/automation/advance`,
        {
          session_code: sessionCode,
          target_stage: 'session_complete',
          notes: 'Photos imported from SD card — session marked complete',
          api_key: this.matcher.wpApiKey,
        },
        { timeout: 15000 }
      );
      console.log(`[Import] Advanced ${sessionCode} to session_complete`);
    } catch (err) {
      console.warn(`[Import] Failed to advance ${sessionCode}:`, err.message);
    }
  }

  /**
   * Summarize an import result for logging.
   */
  _summarizeResult(result) {
    const matched = result.groups.filter((g) => g.matched).length;
    const unmatched = result.groups.filter((g) => !g.matched).length;
    return `${result.totalPhotos} photos, ${result.groups.length} group(s), ${matched} matched, ${unmatched} unmatched`;
  }

  /**
   * Get import status and history.
   */
  getStatus() {
    return {
      enabled: this.enabled,
      watching: this.isWatching,
      importDir: this.importDir,
      watchDir: this.watchDir,
      pendingFiles: this.pendingFiles.length,
      recentImports: this.importHistory.slice(0, 10),
    };
  }
}

module.exports = { AutoImporter };
