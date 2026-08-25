/**
 * Photo Automation REST Routes
 *
 * Endpoints for managing the automated photo pipeline:
 *   - GET  /api/automation/status           — Overall pipeline status
 *   - GET  /api/automation/session/:code     — Session pipeline status + folder info
 *   - POST /api/automation/session/:code/run — Manually trigger full pipeline
 *   - POST /api/automation/session/:code/approve — Approve edit review, continue pipeline
 *   - POST /api/automation/session/:code/gallery — Prepare gallery from final/edited
 *   - POST /api/automation/session/:code/folders — Create session folder structure
 *   - POST /api/automation/cull             — Run culling on an arbitrary folder
 *   - POST /api/automation/edit             — Run editing on an arbitrary folder
 */
const express = require('express');
const router = express.Router();

// Pipeline instance is injected via middleware (set in index.js)
function getPipeline(req) {
  if (!req.app.locals.pipeline) {
    throw new Error('Automation pipeline not initialized');
  }
  return req.app.locals.pipeline;
}

// ── Status endpoints ─────────────────────────────────────────────────────────

router.get('/status', async (req, res) => {
  try {
    const pipeline = getPipeline(req);
    res.json({
      ok: true,
      watching: pipeline.watcher.isWatching,
      watchDir: pipeline.watchDir,
      activePipelines: pipeline.getAllStatuses(),
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.get('/session/:code', async (req, res) => {
  try {
    const pipeline = getPipeline(req);
    const code = req.params.code;

    const [pipelineStatus, folderStatus] = await Promise.all([
      pipeline.getPipelineStatus(code),
      pipeline.getFolderStatus(code),
    ]);

    if (!pipelineStatus && !folderStatus) {
      return res.status(404).json({ error: 'Session not found in automation system' });
    }

    res.json({
      ok: true,
      sessionCode: code,
      pipeline: pipelineStatus,
      folders: folderStatus,
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Action endpoints ─────────────────────────────────────────────────────────

router.post('/session/:code/run', async (req, res) => {
  try {
    const pipeline = getPipeline(req);
    const code = req.params.code;

    // Start pipeline asynchronously
    pipeline.runFullPipeline(code).catch(err => {
      console.error(`[Automation API] Pipeline error for ${code}:`, err.message);
    });

    res.json({
      ok: true,
      message: `Pipeline started for session ${code}`,
      sessionCode: code,
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/session/:code/approve', async (req, res) => {
  try {
    const pipeline = getPipeline(req);
    const code = req.params.code;

    // Run approval asynchronously
    pipeline.approveReview(code).catch(err => {
      console.error(`[Automation API] Approve error for ${code}:`, err.message);
    });

    res.json({
      ok: true,
      message: `Review approved for session ${code} — continuing pipeline`,
      sessionCode: code,
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/session/:code/gallery', async (req, res) => {
  try {
    const pipeline = getPipeline(req);
    const code = req.params.code;

    pipeline.prepareGallery(code).catch(err => {
      console.error(`[Automation API] Gallery error for ${code}:`, err.message);
    });

    res.json({
      ok: true,
      message: `Gallery preparation started for session ${code}`,
      sessionCode: code,
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/session/:code/folders', async (req, res) => {
  try {
    const pipeline = getPipeline(req);
    const code = req.params.code;
    const sessionDir = await pipeline.createSessionFolders(code);

    res.json({
      ok: true,
      message: `Folder structure created for session ${code}`,
      sessionDir,
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Standalone tool endpoints ────────────────────────────────────────────────

router.post('/cull', async (req, res) => {
  try {
    const { inputDir, keepPercent, duplicateThreshold, minSharpness } = req.body;
    if (!inputDir) {
      return res.status(400).json({ error: 'inputDir is required' });
    }

    const { cullPhotos, organizeCulledPhotos } = require('../services/cullingService');
    const result = await cullPhotos(inputDir, {
      keepPercent: keepPercent || 40,
      duplicateThreshold: duplicateThreshold || 10,
      minSharpness: minSharpness || 50,
    });

    if (req.body.organize !== false) {
      await organizeCulledPhotos(inputDir, result);
    }

    res.json({ ok: true, ...result });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/edit', async (req, res) => {
  try {
    const { inputDir, outputDir } = req.body;
    if (!inputDir || !outputDir) {
      return res.status(400).json({ error: 'inputDir and outputDir are required' });
    }

    const { editPhotos } = require('../services/imagenService');
    const result = await editPhotos(inputDir, outputDir, {
      outputFormat: req.body.outputFormat || 'jpeg',
      outputQuality: req.body.outputQuality || 92,
    });

    res.json({ ok: true, ...result });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;
