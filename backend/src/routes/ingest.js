/**
 * Ingest Routes — Photo auto-import pipeline.
 *
 * Endpoints:
 *   GET  /api/ingest/status       — Import status, history, watcher state
 *   POST /api/ingest/start        — Start the SD card watcher
 *   POST /api/ingest/stop         — Stop the SD card watcher
 *   POST /api/ingest/import       — Manually trigger an import from a path
 *   GET  /api/ingest/bookings     — Fetch recent bookings from Google Calendar
 *   GET  /api/ingest/import/:id   — Get details of a specific import
 */
const express = require('express');
const router = express.Router();
const cardWatcher = require('../services/cardWatcherService');
const importService = require('../services/importService');
const calendarService = require('../services/googleCalendarService');

// Wire up card watcher → auto-import pipeline
cardWatcher.on('card-inserted', async ({ drive, images }) => {
  console.log(`[Ingest] Auto-import triggered from ${drive} (${images.length} images)`);
  try {
    const result = await importService.importPhotos(images, drive);
    console.log(`[Ingest] Auto-import complete: ${result.destination}`);
  } catch (err) {
    console.error('[Ingest] Auto-import failed:', err.message);
  }
});

// ── GET /status ─────────────────────────────────────────────────────────────

router.get('/status', (req, res) => {
  res.json({
    watcher: cardWatcher.getStatus(),
    import: importService.getStatus(),
  });
});

// ── POST /start — Start watching for SD cards ───────────────────────────────

router.post('/start', (req, res) => {
  cardWatcher.start();
  res.json({
    message: 'Card watcher started',
    watcher: cardWatcher.getStatus(),
  });
});

// ── POST /stop — Stop watching ──────────────────────────────────────────────

router.post('/stop', (req, res) => {
  cardWatcher.stop();
  res.json({
    message: 'Card watcher stopped',
    watcher: cardWatcher.getStatus(),
  });
});

// ── POST /import — Manual import from a specific path ───────────────────────

router.post('/import', async (req, res) => {
  const { sourcePath, clientName, sessionDate } = req.body;

  if (!sourcePath) {
    return res.status(400).json({ error: 'sourcePath is required' });
  }

  try {
    // Scan the source path for images
    const images = cardWatcher.scanForImages(sourcePath);
    if (images.length === 0) {
      return res.status(400).json({ error: 'No image files found at the specified path' });
    }

    const result = await importService.importPhotos(images, sourcePath, {
      clientName,
      sessionDate,
    });

    res.json(result);
  } catch (err) {
    console.error('[Ingest] Manual import failed:', err.message);
    res.status(500).json({ error: err.message });
  }
});

// ── GET /import/:id — Get specific import details ───────────────────────────

router.get('/import/:id', (req, res) => {
  const result = importService.getImport(req.params.id);
  if (!result) {
    return res.status(404).json({ error: 'Import not found' });
  }
  res.json(result);
});

// ── GET /bookings — Fetch recent bookings from Google Calendar ──────────────

router.get('/bookings', async (req, res) => {
  const daysBack = parseInt(req.query.daysBack || '7', 10);
  const daysForward = parseInt(req.query.daysForward || '7', 10);

  try {
    const bookings = await calendarService.getRecentBookings(daysBack, daysForward);
    res.json({ bookings });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;
