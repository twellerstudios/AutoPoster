/**
 * AutoPoster Backend — Entry point
 * Binds to 0.0.0.0 for multi-device access across networks.
 */
const express = require('express');
const cors = require('cors');
const path = require('path');
const rateLimit = require('express-rate-limit');
const { config, validate, getNetworkAddresses } = require('./config');

// Soft-validate config (warnings, not fatal — user can fix via UI)
validate();

const app = express();
app.set('trust proxy', 1);

// ── Middleware ────────────────────────────────────────────────────────────────

app.use(cors({
  origin: (origin, cb) => cb(null, true),
  credentials: true,
}));

app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

// Serve uploaded images for preview
app.use('/uploads', express.static(path.join(__dirname, '..', 'uploads')));

// Rate limiting
const apiLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: 30,
  message: { error: 'Too many requests. Please wait a moment and try again.' },
});
app.use('/api/', apiLimiter);

// ── Routes ────────────────────────────────────────────────────────────────────

app.use('/api/businesses', require('./routes/businesses'));
app.use('/api/posts', require('./routes/posts'));
app.use('/api/settings', require('./routes/settings'));

// Health check
app.get('/api/health', (req, res) => {
  res.json({
    ok: true,
    version: '2.1.0',
    businesses: Object.keys(config.businesses),
    hasApiKey: Boolean(config.anthropicApiKey),
    hasPexelsKey: Boolean(config.pexelsApiKey),
    timestamp: new Date().toISOString(),
  });
});

// 404
app.use('/api/*', (req, res) => {
  res.status(404).json({
    error: `API endpoint not found: ${req.method} ${req.originalUrl}`,
    hint: 'Check the URL and method. Available endpoints: /api/health, /api/businesses, /api/posts, /api/settings',
  });
});

// Global error handler
app.use((err, req, res, _next) => {
  console.error('[Server] Unhandled error:', err);
  res.status(500).json({
    error: 'Something went wrong on the server.',
    hint: 'Please try again. If this keeps happening, check the server logs for details.',
  });
});

// ── Start on 0.0.0.0 for multi-device access ─────────────────────────────────

const HOST = '0.0.0.0';
app.listen(config.port, HOST, () => {
  const businesses = Object.values(config.businesses);
  const addresses = getNetworkAddresses();

  console.log(`\n✓ AutoPoster backend running on http://localhost:${config.port}`);

  if (addresses.length > 0) {
    console.log('\n  Access from other devices:');
    addresses.forEach(a => {
      console.log(`    http://${a.address}:${config.port}  (${a.name})`);
    });
  }

  if (businesses.length > 0) {
    console.log(`\n  Businesses: ${businesses.map(b => b.name).join(', ')}`);
  } else {
    console.log('\n  No businesses configured — add one in the Settings page');
  }

  if (!config.anthropicApiKey) {
    console.log('  AI Mode: Manual (free — paste from claude.ai)');
  } else {
    console.log('  AI Mode: API (automatic)');
  }

  console.log(`  Environment: ${config.nodeEnv}\n`);
});
