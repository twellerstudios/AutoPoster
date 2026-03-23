/**
 * Photo Import REST Routes
 *
 * Endpoints for the auto-import system:
 *   - GET  /api/import/status           — Import watcher status and recent history
 *   - POST /api/import/trigger           — Manually trigger import scan
 *   - GET  /api/import/unmatched         — List unmatched photo folders
 *   - POST /api/import/assign            — Manually assign unmatched photos to a session
 */
const express = require('express');
const router = express.Router();

function getImporter(req) {
  if (!req.app.locals.importer) {
    throw new Error('Auto-importer not initialized');
  }
  return req.app.locals.importer;
}

// ── Status ────────────────────────────────────────────────────────────────────

router.get('/status', async (req, res) => {
  try {
    const importer = getImporter(req);
    res.json({ ok: true, ...importer.getStatus() });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Manual trigger ────────────────────────────────────────────────────────────

router.post('/trigger', async (req, res) => {
  try {
    const importer = getImporter(req);

    // Run import asynchronously
    const resultPromise = importer.manualImport();

    // If caller wants to wait for result
    if (req.query.wait === 'true') {
      const result = await resultPromise;
      return res.json({ ok: true, ...result });
    }

    // Otherwise return immediately
    resultPromise.catch(err => {
      console.error('[Import API] Manual import error:', err.message);
    });

    res.json({
      ok: true,
      message: 'Import scan triggered — check /api/import/status for progress',
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Unmatched photos ──────────────────────────────────────────────────────────

router.get('/unmatched', async (req, res) => {
  try {
    const importer = getImporter(req);
    const folders = await importer.getUnmatched();
    res.json({ ok: true, folders });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Manual assignment ─────────────────────────────────────────────────────────

router.post('/assign', async (req, res) => {
  try {
    const importer = getImporter(req);
    const { date, sessionCode } = req.body;

    if (!date || !sessionCode) {
      return res.status(400).json({ error: 'date and sessionCode are required' });
    }

    const result = await importer.assignUnmatched(date, sessionCode);
    res.json(result);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;
