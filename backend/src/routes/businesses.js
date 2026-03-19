/**
 * GET  /api/businesses                — List all configured businesses
 * GET  /api/businesses/:id/test       — Test WordPress connection for a business
 * GET  /api/businesses/:id/test-facebook — Test Facebook connection
 * GET  /api/businesses/buffer/test    — Test Buffer API connection
 * GET  /api/businesses/buffer/channels — List available Buffer channels
 * GET  /api/businesses/buffer/schema   — Introspect Buffer GraphQL schema
 */
const express = require('express');
const router = express.Router();
const { config } = require('../config');
const { testConnection } = require('../services/wordpressService');
const { testFacebookConnection } = require('../services/facebookService');
const { testBufferConnection, getChannels, introspectSchema } = require('../services/bufferService');

// List all businesses (safe — no credentials exposed)
router.get('/', (req, res) => {
  const list = Object.values(config.businesses).map(b => ({
    id: b.id,
    name: b.name,
    wordpressUrl: b.wordpress.url,
    facebookConfigured: Boolean(b.facebook.pageId && b.facebook.pageAccessToken),
    bufferConfigured: Boolean(config.bufferApiToken && b.buffer.channelIds && b.buffer.channelIds.length > 0),
    bufferChannelCount: (b.buffer.channelIds || []).length,
  }));
  res.json(list);
});

// Test WordPress connection
router.get('/:id/test', async (req, res) => {
  const business = config.businesses[req.params.id];
  if (!business) {
    return res.status(404).json({
      error: `Business "${req.params.id}" not found.`,
      hint: 'This business may have been deleted. Check Settings to see your current businesses.',
    });
  }

  if (!business.wordpress.url || !business.wordpress.username || !business.wordpress.appPassword) {
    return res.status(422).json({
      error: `"${business.name}" has incomplete WordPress settings.`,
      hint: 'Go to Settings and fill in the WordPress URL, username, and application password for this business.',
    });
  }

  try {
    const result = await testConnection(business.wordpress);
    res.json({
      ok: true,
      ...result,
      message: `Connected successfully as "${result.user}". Your WordPress credentials are working.`,
    });
  } catch (err) {
    const status = err.response?.status;
    let hint = '';

    if (status === 401 || status === 403) {
      hint = 'Your username or application password is incorrect. To fix: go to WordPress Admin > Users > Profile > Application Passwords, create a new one, and update it in Settings.';
    } else if (status === 404) {
      hint = `Could not find the WordPress REST API at "${business.wordpress.url}". Make sure the URL is correct and doesn't have a typo.`;
    } else if (err.code === 'ENOTFOUND') {
      hint = `The domain "${business.wordpress.url}" could not be found. Check the URL for typos.`;
    } else if (err.code === 'ECONNREFUSED') {
      hint = 'Connection refused. The WordPress site may be down or the URL may be wrong.';
    } else if (err.code === 'ETIMEDOUT' || err.code === 'ECONNABORTED') {
      hint = 'The connection timed out. The WordPress site may be slow or unreachable. Try again in a moment.';
    } else {
      hint = `Connection failed: ${err.message}. Double-check your settings.`;
    }

    res.status(502).json({ ok: false, error: 'Connection failed', hint });
  }
});

// Test Facebook connection
router.get('/:id/test-facebook', async (req, res) => {
  const business = config.businesses[req.params.id];
  if (!business) {
    return res.status(404).json({
      error: `Business "${req.params.id}" not found.`,
      hint: 'This business may have been deleted. Check Settings.',
    });
  }

  if (!business.facebook.pageId || !business.facebook.pageAccessToken) {
    return res.status(422).json({
      error: `"${business.name}" has no Facebook Page configured.`,
      hint: 'Go to Settings and add a Facebook Page ID and Page Access Token for this business.',
    });
  }

  try {
    const result = await testFacebookConnection(business.facebook);
    res.json({
      ok: true,
      ...result,
      message: `Connected to Facebook Page "${result.pageName}".`,
    });
  } catch (err) {
    const status = err.response?.status;
    let hint = '';

    if (status === 190 || (err.response?.data?.error?.code === 190)) {
      hint = 'Your Page Access Token is invalid or expired. Generate a new long-lived Page Access Token from the Facebook Graph API Explorer.';
    } else if (status === 400) {
      hint = `Facebook API error: ${err.response?.data?.error?.message || err.message}. Check your Page ID and Access Token.`;
    } else {
      hint = `Facebook connection failed: ${err.response?.data?.error?.message || err.message}`;
    }

    res.status(502).json({ ok: false, error: 'Facebook connection failed', hint });
  }
});

// Test Buffer API connection
router.get('/buffer/test', async (req, res) => {
  if (!config.bufferApiToken) {
    return res.status(422).json({
      error: 'Buffer API token is not configured.',
      hint: 'Go to Settings and add your Buffer API token. Get one from your Buffer account settings.',
    });
  }

  try {
    const result = await testBufferConnection(config.bufferApiToken);
    res.json({
      ok: true,
      ...result,
      message: `Connected to Buffer! Found ${result.channelCount} channel(s).`,
    });
  } catch (err) {
    let hint = '';
    const status = err.response?.status;

    if (status === 401 || status === 403) {
      hint = 'Your Buffer API token is invalid or expired. Generate a new one in your Buffer account settings.';
    } else {
      hint = `Buffer connection failed: ${err.message}`;
    }

    res.status(502).json({ ok: false, error: 'Buffer connection failed', hint });
  }
});

// List available Buffer channels (connected social accounts)
router.get('/buffer/channels', async (req, res) => {
  if (!config.bufferApiToken) {
    return res.status(422).json({
      error: 'Buffer API token is not configured.',
      hint: 'Add your Buffer API token in Settings first.',
    });
  }

  try {
    const channels = await getChannels(config.bufferApiToken);
    res.json({
      ok: true,
      channels,
    });
  } catch (err) {
    const status = err.response?.status;
    let hint = '';

    if (status === 401 || status === 403) {
      hint = 'Your Buffer API token is invalid or expired.';
    } else {
      hint = `Failed to fetch channels: ${err.message}`;
    }

    res.status(502).json({ ok: false, error: 'Failed to fetch Buffer channels', hint });
  }
});

// Introspect Buffer GraphQL schema (diagnostic endpoint)
router.get('/buffer/schema', async (req, res) => {
  if (!config.bufferApiToken) {
    return res.status(422).json({
      error: 'Buffer API token is not configured.',
      hint: 'Add your Buffer API token in Settings first.',
    });
  }

  try {
    const schema = await introspectSchema(config.bufferApiToken);
    res.json({ ok: true, schema });
  } catch (err) {
    const status = err.response?.status;
    let hint = '';

    if (status === 401 || status === 403) {
      hint = 'Your Buffer API token is invalid or expired.';
    } else {
      hint = `Schema introspection failed: ${err.message}`;
    }

    res.status(502).json({ ok: false, error: 'Schema introspection failed', hint });
  }
});

module.exports = router;
