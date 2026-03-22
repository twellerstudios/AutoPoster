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

app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

// Rate limiting — prevent runaway Claude API calls
const apiLimiter = rateLimit({
  windowMs: 60 * 1000, // 1 minute
  max: 20,
  message: { error: 'Too many requests, please wait a moment' },
});
app.use('/api/', apiLimiter);

// ── Routes ────────────────────────────────────────────────────────────────────

app.use('/api/businesses', require('./routes/businesses'));
app.use('/api/posts', require('./routes/posts'));
app.use('/api/lightroom', require('./routes/lightroom'));

// Health check
app.get('/api/health', (req, res) => {
  res.json({
    status: 'ok',
    ok: true,
    version: '1.1.0',
    businesses: Object.keys(config.businesses),
    geminiConfigured: !!process.env.GEMINI_API_KEY,
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
