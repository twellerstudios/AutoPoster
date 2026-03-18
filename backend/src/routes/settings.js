/**
 * Settings API — manage businesses, API keys, and app config from the UI.
 * No terminal access needed.
 *
 * GET  /api/settings             — Get all settings (API keys masked)
 * PUT  /api/settings             — Update settings
 * POST /api/settings/businesses  — Add a new business
 * PUT  /api/settings/businesses/:id — Update a business
 * DELETE /api/settings/businesses/:id — Delete a business
 * GET  /api/settings/network     — Get network addresses for multi-device access
 */
const express = require('express');
const router = express.Router();
const { config, saveSettings, loadSettings, reloadConfig, getNetworkAddresses } = require('../config');
const { testConnection } = require('../services/wordpressService');

/**
 * Mask a secret string for safe display (show first 4 and last 4 chars).
 */
function maskSecret(str) {
  if (!str) return '';
  if (str.length <= 12) return str.slice(0, 3) + '***';
  return str.slice(0, 4) + '***' + str.slice(-4);
}

/**
 * GET /api/settings — Return current settings with secrets masked.
 */
router.get('/', (req, res) => {
  const settings = loadSettings() || { businesses: [], anthropicApiKey: '', pexelsApiKey: '' };

  res.json({
    aiMode: settings.aiMode || (settings.anthropicApiKey ? 'auto' : 'manual'),
    anthropicApiKey: maskSecret(settings.anthropicApiKey),
    anthropicApiKeySet: Boolean(settings.anthropicApiKey),
    pexelsApiKey: maskSecret(settings.pexelsApiKey),
    pexelsApiKeySet: Boolean(settings.pexelsApiKey),
    businesses: (settings.businesses || []).map(b => ({
      id: b.id,
      name: b.name,
      wordpressUrl: b.wordpressUrl,
      wordpressUsername: b.wordpressUsername,
      wordpressAppPasswordSet: Boolean(b.wordpressAppPassword),
      wordpressAppPassword: maskSecret(b.wordpressAppPassword),
    })),
  });
});

/**
 * PUT /api/settings — Update API keys.
 * Body: { anthropicApiKey?, pexelsApiKey? }
 * Only updates provided fields. Empty string clears the key.
 */
router.put('/', (req, res) => {
  const settings = loadSettings() || { businesses: [], anthropicApiKey: '', pexelsApiKey: '' };
  const { anthropicApiKey, pexelsApiKey, aiMode } = req.body;

  if (aiMode !== undefined) {
    settings.aiMode = aiMode;
  }
  if (anthropicApiKey !== undefined) {
    settings.anthropicApiKey = anthropicApiKey;
  }
  if (pexelsApiKey !== undefined) {
    settings.pexelsApiKey = pexelsApiKey;
  }

  saveSettings(settings);
  reloadConfig();

  res.json({
    success: true,
    message: 'Settings saved. Changes take effect immediately.',
    anthropicApiKeySet: Boolean(settings.anthropicApiKey),
    pexelsApiKeySet: Boolean(settings.pexelsApiKey),
  });
});

/**
 * POST /api/settings/businesses — Add a new business.
 * Body: { name, wordpressUrl, wordpressUsername, wordpressAppPassword }
 */
router.post('/businesses', async (req, res) => {
  const { name, wordpressUrl, wordpressUsername, wordpressAppPassword } = req.body;

  // Validation with helpful messages
  const errors = [];
  if (!name || !name.trim()) {
    errors.push('Business name is required. Example: "Journey To" or "My Blog"');
  }
  if (!wordpressUrl || !wordpressUrl.trim()) {
    errors.push('WordPress URL is required. Example: "https://www.letsjourneyto.com"');
  } else if (!/^https?:\/\/.+/i.test(wordpressUrl.trim())) {
    errors.push('WordPress URL must start with http:// or https://. Example: "https://www.letsjourneyto.com"');
  }
  if (!wordpressUsername || !wordpressUsername.trim()) {
    errors.push('WordPress username is required. This is the username you use to log into wp-admin.');
  }
  if (!wordpressAppPassword || !wordpressAppPassword.trim()) {
    errors.push('WordPress Application Password is required. To create one: go to WordPress Admin > Users > Profile > scroll to "Application Passwords" > add new.');
  }

  if (errors.length > 0) {
    return res.status(400).json({
      error: 'Please fix the following issues:',
      details: errors,
    });
  }

  const settings = loadSettings() || { businesses: [], anthropicApiKey: '', pexelsApiKey: '' };

  // Generate a URL-safe ID from the name
  const id = name.trim().toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-');

  // Check for duplicate ID
  if ((settings.businesses || []).some(b => b.id === id)) {
    return res.status(409).json({
      error: `A business with a similar name already exists (ID: "${id}"). Please use a different name.`,
    });
  }

  const newBusiness = {
    id,
    name: name.trim(),
    wordpressUrl: wordpressUrl.trim().replace(/\/+$/, ''), // Remove trailing slash
    wordpressUsername: wordpressUsername.trim(),
    wordpressAppPassword: wordpressAppPassword.trim(),
  };

  // Test the WordPress connection before saving
  try {
    await testConnection({
      url: newBusiness.wordpressUrl,
      username: newBusiness.wordpressUsername,
      appPassword: newBusiness.wordpressAppPassword,
    });
  } catch (err) {
    const status = err.response?.status;
    let hint = '';

    if (status === 401 || status === 403) {
      hint = 'Your username or application password is incorrect. Double-check them in WordPress Admin > Users > Profile > Application Passwords.';
    } else if (status === 404) {
      hint = `Could not reach the WordPress REST API at "${newBusiness.wordpressUrl}/wp-json/wp/v2". Make sure the URL is correct and the site has the REST API enabled.`;
    } else if (err.code === 'ENOTFOUND') {
      hint = `The domain "${newBusiness.wordpressUrl}" could not be found. Check the URL for typos.`;
    } else if (err.code === 'ECONNREFUSED') {
      hint = `Connection refused to "${newBusiness.wordpressUrl}". The site may be down or the URL may be wrong.`;
    } else {
      hint = `Connection failed: ${err.message}. Verify the WordPress URL and that the site is accessible.`;
    }

    return res.status(422).json({
      error: 'Could not connect to WordPress. The business was NOT saved.',
      details: [hint],
      hint: 'You can still save with an incorrect config by fixing the values later in Settings.',
    });
  }

  settings.businesses = settings.businesses || [];
  settings.businesses.push(newBusiness);
  saveSettings(settings);
  reloadConfig();

  res.json({
    success: true,
    message: `"${newBusiness.name}" added successfully and WordPress connection verified.`,
    business: {
      id: newBusiness.id,
      name: newBusiness.name,
      wordpressUrl: newBusiness.wordpressUrl,
    },
  });
});

/**
 * PUT /api/settings/businesses/:id — Update an existing business.
 */
router.put('/businesses/:id', (req, res) => {
  const settings = loadSettings() || { businesses: [] };
  const index = (settings.businesses || []).findIndex(b => b.id === req.params.id);

  if (index === -1) {
    return res.status(404).json({
      error: `Business "${req.params.id}" not found. It may have been deleted.`,
    });
  }

  const { name, wordpressUrl, wordpressUsername, wordpressAppPassword } = req.body;
  const biz = settings.businesses[index];

  if (name !== undefined) biz.name = name.trim();
  if (wordpressUrl !== undefined) biz.wordpressUrl = wordpressUrl.trim().replace(/\/+$/, '');
  if (wordpressUsername !== undefined) biz.wordpressUsername = wordpressUsername.trim();
  if (wordpressAppPassword !== undefined) biz.wordpressAppPassword = wordpressAppPassword.trim();

  settings.businesses[index] = biz;
  saveSettings(settings);
  reloadConfig();

  res.json({
    success: true,
    message: `"${biz.name}" updated successfully.`,
    business: {
      id: biz.id,
      name: biz.name,
      wordpressUrl: biz.wordpressUrl,
    },
  });
});

/**
 * DELETE /api/settings/businesses/:id — Remove a business.
 */
router.delete('/businesses/:id', (req, res) => {
  const settings = loadSettings() || { businesses: [] };
  const index = (settings.businesses || []).findIndex(b => b.id === req.params.id);

  if (index === -1) {
    return res.status(404).json({
      error: `Business "${req.params.id}" not found. It may have already been deleted.`,
    });
  }

  const removed = settings.businesses.splice(index, 1)[0];
  saveSettings(settings);
  reloadConfig();

  res.json({
    success: true,
    message: `"${removed.name}" has been removed.`,
  });
});

/**
 * GET /api/settings/network — Get network addresses for connecting from other devices.
 */
router.get('/network', (req, res) => {
  const addresses = getNetworkAddresses();
  const port = config.port;

  res.json({
    addresses: addresses.map(a => ({
      interface: a.name,
      ip: a.address,
      url: `http://${a.address}:${port}`,
    })),
    port,
    hint: addresses.length === 0
      ? 'No network interfaces found. Make sure you are connected to a network.'
      : 'Use any of these URLs to access AutoPoster from other devices on the same network.',
  });
});

module.exports = router;
