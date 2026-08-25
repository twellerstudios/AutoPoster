/**
 * AutoPoster Backend — Entry point
 */
const express = require('express');
const cors = require('cors');
const rateLimit = require('express-rate-limit');
const { config, validate } = require('./config');

// Validate config before starting
validate();

const app = express();
app.set('trust proxy', 1); // Trust the React dev proxy / reverse proxies

// ── Middleware ────────────────────────────────────────────────────────────────

app.use(cors({
  origin: (origin, cb) => cb(null, true), // Allow all in dev; tighten in prod
  credentials: true,
}));

app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

// Rate limiting — prevent runaway Claude API calls
const apiLimiter = rateLimit({
  windowMs: 60 * 1000, // 1 minute
  max: 20,
  message: { error: 'Too many requests, please wait a moment' },
});
app.use('/api/', apiLimiter);

// ── Photo Automation Pipeline ─────────────────────────────────────────────────

const { AutomationPipeline } = require('./services/automationPipeline');
const pipeline = new AutomationPipeline();
app.locals.pipeline = pipeline;

// Start the folder watcher (non-blocking)
pipeline.start().catch(err => {
  console.warn('[Automation] Pipeline start warning:', err.message);
});

// ── Auto-Import (SD card / dump folder → session matching) ───────────────────

const { AutoImporter } = require('./services/autoImportService');
const importer = new AutoImporter();
app.locals.importer = importer;

// Wire import completion to pipeline: when photos are sorted, trigger culling
importer.on('import-complete', (result) => {
  for (const group of result.groups) {
    if (group.matched && group.sessionCode) {
      console.log(`[Import→Pipeline] Auto-triggering pipeline for ${group.sessionCode}`);
      pipeline.runFullPipeline(group.sessionCode).catch(err => {
        console.error(`[Import→Pipeline] Error for ${group.sessionCode}:`, err.message);
      });
    }
  }
});

// Start the import watcher (non-blocking)
importer.start().catch(err => {
  console.warn('[Import] Auto-import start warning:', err.message);
});

// ── Routes ────────────────────────────────────────────────────────────────────

app.use('/api/businesses', require('./routes/businesses'));
app.use('/api/posts', require('./routes/posts'));
app.use('/api/automation', require('./routes/automation'));
app.use('/api/import', require('./routes/import'));

// Health check
app.get('/api/health', (req, res) => {
  res.json({
    ok: true,
    version: '1.2.0',
    businesses: Object.keys(config.businesses),
    automation: {
      watching: pipeline.watcher.isWatching,
      watchDir: pipeline.watchDir || null,
      imagenEnabled: !!(process.env.IMAGEN_AI_API_KEY && process.env.IMAGEN_AI_PROFILE_ID),
    },
    import: {
      enabled: importer.enabled,
      watching: importer.isWatching,
      importDir: importer.importDir || null,
    },
    timestamp: new Date().toISOString(),
  });
});

// 404
app.use('/api/*', (req, res) => {
  res.status(404).json({ error: 'Not found' });
});

// Global error handler
app.use((err, req, res, _next) => {
  console.error('[Server] Unhandled error:', err);
  res.status(500).json({ error: 'Internal server error' });
});

// ── Start ─────────────────────────────────────────────────────────────────────

app.listen(config.port, () => {
  console.log(`\n✓ AutoPoster backend running on http://localhost:${config.port}`);
  console.log(`  Businesses: ${Object.values(config.businesses).map(b => b.name).join(', ')}`);
  console.log(`  Environment: ${config.nodeEnv}\n`);
});
