/**
 * GET  /api/businesses        — List all configured businesses
 * GET  /api/businesses/:id/test — Test WordPress connection for a business
 */
const express = require('express');
const router = express.Router();
const { config } = require('../config');
const { testConnection } = require('../services/wordpressService');

// List all businesses (safe — no credentials exposed)
router.get('/', (req, res) => {
  const list = Object.values(config.businesses).map(b => ({
    id: b.id,
    name: b.name,
    wordpressUrl: b.wordpress.url,
  }));
  res.json(list);
});

// Test WordPress connection
router.get('/:id/test', async (req, res) => {
  const business = config.businesses[req.params.id];
  if (!business) {
    return res.status(404).json({ error: 'Business not found' });
  }

  try {
    const result = await testConnection(business.wordpress);
    res.json({ ok: true, ...result });
  } catch (err) {
    const detail = err.response?.data || err.message;
    res.status(502).json({ ok: false, error: 'Connection failed', detail });
  }
});

module.exports = router;
