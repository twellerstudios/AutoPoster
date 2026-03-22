/**
 * Photo Automation Pipeline
 *
 * Orchestrates the full automated workflow:
 *   1. Detect new photos (via watcher or manual trigger)
 *   2. Auto-cull (blur/duplicate/exposure analysis)
 *   3. AI Edit (Imagen AI or built-in fallback)
 *   4. Stage advancement (callback to WordPress)
 *   5. Gallery preparation
 *
 * Each step auto-advances the WordPress session stage and logs progress.
 */
const path = require('path');
const fs = require('fs').promises;
const axios = require('axios');
const { cullPhotos, organizeCulledPhotos } = require('./cullingService');
const { editPhotos } = require('./imagenService');
const { PhotoWatcher } = require('./photoWatcherService');

class AutomationPipeline {
  constructor(config = {}) {
    this.wpUrl = config.wpUrl || process.env.WP_AUTOMATION_URL || '';
    this.wpApiKey = config.wpApiKey || process.env.WP_AUTOMATION_API_KEY || '';
    this.watchDir = config.watchDir || process.env.PHOTO_WATCH_DIR || '';
    this.galleryBaseUrl = config.galleryBaseUrl || process.env.GALLERY_BASE_URL || '';

    // Culling settings
    this.cullSettings = {
      keepPercent: parseInt(process.env.CULL_KEEP_PERCENT || '40', 10),
      duplicateThreshold: parseInt(process.env.CULL_DUPLICATE_THRESHOLD || '10', 10),
      minSharpness: parseInt(process.env.CULL_MIN_SHARPNESS || '50', 10),
    };

    // Edit settings
    this.editSettings = {
      outputFormat: process.env.EDIT_OUTPUT_FORMAT || 'jpeg',
      outputQuality: parseInt(process.env.EDIT_OUTPUT_QUALITY || '92', 10),
    };

    // Pipeline state tracking
    this.activePipelines = new Map();

    // File watcher
    this.watcher = new PhotoWatcher({ watchDir: this.watchDir });
  }

  /**
   * Initialize the pipeline — start watching and wire up events.
   */
  async start() {
    // Wire up watcher events
    this.watcher.on('photos-detected', (event) => {
      this._handleNewPhotos(event).catch(err => {
        console.error(`[Pipeline] Error processing ${event.sessionCode}:`, err.message);
        this._updatePipelineState(event.sessionCode, 'error', err.message);
      });
    });

    await this.watcher.start();
    console.log('[Pipeline] Automation pipeline started');
  }

  /**
   * Stop the pipeline.
   */
  async stop() {
    await this.watcher.stop();
    console.log('[Pipeline] Automation pipeline stopped');
  }

  /**
   * Handle new photos detected by the watcher.
   * Determines which pipeline step to run based on which folder received files.
   */
  async _handleNewPhotos(event) {
    const { sessionCode, sessionDir, subfolders, fileCount } = event;

    console.log(`[Pipeline] Processing session ${sessionCode}: ${fileCount} files in ${subfolders.join(', ')}`);

    // If files landed in 'raw' → start full pipeline from culling
    if (subfolders.includes('raw')) {
      await this.runFullPipeline(sessionCode, sessionDir);
    }
    // If files landed in 'final' → skip to gallery prep
    else if (subfolders.includes('final')) {
      await this.prepareGallery(sessionCode, sessionDir);
    }
  }

  /**
   * Run the full automated pipeline for a session.
   */
  async runFullPipeline(sessionCode, sessionDir = null) {
    if (!sessionDir) {
      sessionDir = path.join(this.watchDir, sessionCode);
    }

    const rawDir = path.join(sessionDir, 'raw');
    const editedDir = path.join(sessionDir, 'edited');
    const galleryDir = path.join(sessionDir, 'gallery');

    const pipelineId = `${sessionCode}-${Date.now()}`;
    this._updatePipelineState(sessionCode, 'running', 'Starting pipeline');

    try {
      // ── Step 1: Advance to "importing" ──
      await this._advanceWpStage(sessionCode, 'importing', 'Photos detected — starting import');
      this._updatePipelineState(sessionCode, 'importing', 'Importing photos');

      // Verify photos exist in raw folder
      const rawFiles = await this._listImageFiles(rawDir);
      if (rawFiles.length === 0) {
        throw new Error('No photos found in raw/ folder');
      }

      console.log(`[Pipeline] ${sessionCode}: Found ${rawFiles.length} photos in raw/`);

      // ── Step 2: Auto-cull ──
      await this._advanceWpStage(sessionCode, 'culling', `Culling ${rawFiles.length} photos`);
      this._updatePipelineState(sessionCode, 'culling', `Analyzing ${rawFiles.length} photos`);

      const cullResult = await cullPhotos(rawDir, this.cullSettings);
      await organizeCulledPhotos(rawDir, cullResult);

      console.log(`[Pipeline] ${sessionCode}: Culled → ${cullResult.stats.kept} keeps, ${cullResult.stats.rejected} rejects`);

      // ── Step 3: AI Edit ──
      const keepsDir = path.join(rawDir, '_keeps');
      await this._advanceWpStage(sessionCode, 'ai_editing', `Editing ${cullResult.stats.kept} photos`);
      this._updatePipelineState(sessionCode, 'editing', `Editing ${cullResult.stats.kept} photos`);

      const editResult = await editPhotos(keepsDir, editedDir, this.editSettings);

      console.log(`[Pipeline] ${sessionCode}: Edited → ${editResult.count} photos (method: ${editResult.method || 'imagen'})`);

      // ── Step 4: Edit Review ──
      await this._advanceWpStage(sessionCode, 'edit_review', `${editResult.count} photos ready for review`);
      this._updatePipelineState(sessionCode, 'review', 'Waiting for review');

      // Auto-advance past review if configured for full automation
      if (process.env.AUTO_SKIP_REVIEW === 'true') {
        await this._advanceWpStage(sessionCode, 'final_edits', 'Auto-approved — no manual review');

        // Copy edited to gallery (no final tweaks needed)
        await this._copyToGallery(editedDir, galleryDir);

        // ── Step 5: Gallery ──
        await this._prepareAndNotify(sessionCode, sessionDir, galleryDir);
      } else {
        console.log(`[Pipeline] ${sessionCode}: Paused at edit_review — waiting for manual approval`);
      }

      this._updatePipelineState(sessionCode, 'complete', 'Pipeline finished');

    } catch (err) {
      console.error(`[Pipeline] ${sessionCode}: Pipeline error —`, err.message);
      this._updatePipelineState(sessionCode, 'error', err.message);
      throw err;
    }
  }

  /**
   * Prepare gallery from final/edited photos and notify client.
   */
  async prepareGallery(sessionCode, sessionDir = null) {
    if (!sessionDir) {
      sessionDir = path.join(this.watchDir, sessionCode);
    }

    const galleryDir = path.join(sessionDir, 'gallery');
    const finalDir = path.join(sessionDir, 'final');
    const editedDir = path.join(sessionDir, 'edited');

    // Prefer final/ over edited/
    const sourceDir = await this._dirHasFiles(finalDir) ? finalDir : editedDir;
    await this._copyToGallery(sourceDir, galleryDir);
    await this._prepareAndNotify(sessionCode, sessionDir, galleryDir);
  }

  /**
   * Continue pipeline after manual review approval.
   * Called from the REST API when the photographer approves edits.
   */
  async approveReview(sessionCode) {
    const sessionDir = path.join(this.watchDir, sessionCode);
    const editedDir = path.join(sessionDir, 'edited');
    const galleryDir = path.join(sessionDir, 'gallery');

    await this._advanceWpStage(sessionCode, 'final_edits', 'Review approved');

    // Check if there are final edits, otherwise use edited
    const finalDir = path.join(sessionDir, 'final');
    const sourceDir = await this._dirHasFiles(finalDir) ? finalDir : editedDir;

    await this._copyToGallery(sourceDir, galleryDir);
    await this._prepareAndNotify(sessionCode, sessionDir, galleryDir);
  }

  // ── Private helpers ────────────────────────────────────────────────────────

  async _prepareAndNotify(sessionCode, sessionDir, galleryDir) {
    const galleryFiles = await this._listImageFiles(galleryDir);

    // Build gallery URL
    const galleryUrl = this.galleryBaseUrl
      ? `${this.galleryBaseUrl}/${sessionCode}`
      : '';

    // Advance to gallery_created
    await this._advanceWpStage(sessionCode, 'gallery_created', `Gallery ready: ${galleryFiles.length} photos`, galleryUrl);

    // Auto-advance to client_notified (notification email is triggered by WP)
    await this._advanceWpStage(sessionCode, 'client_notified', 'Client notification sent');

    // Mark as delivered
    await this._advanceWpStage(sessionCode, 'delivered', 'Session delivered automatically');

    console.log(`[Pipeline] ${sessionCode}: Gallery delivered — ${galleryFiles.length} photos`);
  }

  /**
   * Advance the WordPress session to a specific stage.
   */
  async _advanceWpStage(sessionCode, targetStage, notes = '', galleryUrl = '') {
    if (!this.wpUrl) {
      console.log(`[Pipeline] WP not configured — would advance ${sessionCode} to ${targetStage}`);
      return;
    }

    try {
      const payload = {
        session_code: sessionCode,
        target_stage: targetStage,
        notes,
        api_key: this.wpApiKey,
      };

      if (galleryUrl) {
        payload.gallery_url = galleryUrl;
      }

      await axios.post(`${this.wpUrl}/wp-json/tweller-flow/v1/automation/advance`, payload, {
        timeout: 15000,
        headers: { 'Content-Type': 'application/json' },
      });

      console.log(`[Pipeline] ${sessionCode}: Advanced to "${targetStage}"`);
    } catch (err) {
      console.warn(`[Pipeline] WP stage advance failed for ${sessionCode} → ${targetStage}:`, err.message);
    }
  }

  /**
   * Copy files from source to gallery directory.
   */
  async _copyToGallery(sourceDir, galleryDir) {
    await fs.mkdir(galleryDir, { recursive: true });

    const files = await this._listImageFiles(sourceDir);
    for (const file of files) {
      await fs.copyFile(
        path.join(sourceDir, file),
        path.join(galleryDir, file)
      );
    }

    console.log(`[Pipeline] Copied ${files.length} photos to gallery`);
  }

  /**
   * List image files in a directory.
   */
  async _listImageFiles(dir) {
    try {
      const entries = await fs.readdir(dir, { withFileTypes: true });
      const imageExts = new Set(['.jpg', '.jpeg', '.png', '.tif', '.tiff', '.webp']);
      return entries
        .filter(e => e.isFile() && imageExts.has(path.extname(e.name).toLowerCase()))
        .map(e => e.name);
    } catch {
      return [];
    }
  }

  /**
   * Check if a directory has image files.
   */
  async _dirHasFiles(dir) {
    const files = await this._listImageFiles(dir);
    return files.length > 0;
  }

  /**
   * Update internal pipeline state tracking.
   */
  _updatePipelineState(sessionCode, status, message = '') {
    this.activePipelines.set(sessionCode, {
      status,
      message,
      updatedAt: new Date(),
    });
  }

  /**
   * Get pipeline status for a session.
   */
  getPipelineStatus(sessionCode) {
    return this.activePipelines.get(sessionCode) || null;
  }

  /**
   * Get all active pipeline statuses.
   */
  getAllStatuses() {
    const statuses = {};
    for (const [code, status] of this.activePipelines) {
      statuses[code] = status;
    }
    return statuses;
  }

  /**
   * Get folder status for a session.
   */
  async getFolderStatus(sessionCode) {
    return this.watcher.getSessionFolderStatus(sessionCode);
  }

  /**
   * Create session folders.
   */
  async createSessionFolders(sessionCode) {
    return this.watcher.createSessionFolders(sessionCode);
  }
}

module.exports = { AutomationPipeline };
