/**
 * Config loader — reads from settings.json (UI-managed) with .env fallback.
 * Settings can be managed entirely from the web UI — no terminal needed.
 */
require('dotenv').config();
const fs = require('fs');
const path = require('path');
const os = require('os');

const SETTINGS_PATH = path.join(__dirname, '..', 'data', 'settings.json');
const DATA_DIR = path.join(__dirname, '..', 'data');

// Ensure data directory exists
if (!fs.existsSync(DATA_DIR)) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
}

/**
 * Load settings from JSON file (or create default).
 */
function loadSettings() {
  if (fs.existsSync(SETTINGS_PATH)) {
    try {
      return JSON.parse(fs.readFileSync(SETTINGS_PATH, 'utf8'));
    } catch (err) {
      console.warn('[Config] Could not parse settings.json, using defaults:', err.message);
    }
  }
  return null;
}

/**
 * Save settings to JSON file.
 */
function saveSettings(settings) {
  fs.writeFileSync(SETTINGS_PATH, JSON.stringify(settings, null, 2), 'utf8');
}

/**
 * Load businesses from env vars (legacy/fallback).
 */
function loadBusinessesFromEnv() {
  const businesses = {};
  let i = 1;
  while (process.env[`BUSINESS_${i}_ID`]) {
    const prefix = `BUSINESS_${i}_`;
    const id = process.env[`${prefix}ID`];
    businesses[id] = {
      id,
      name: process.env[`${prefix}NAME`] || id,
      wordpress: {
        url: process.env[`${prefix}WP_URL`] || '',
        username: process.env[`${prefix}WP_USERNAME`] || '',
        appPassword: process.env[`${prefix}WP_APP_PASSWORD`] || '',
      },
    };
    i++;
  }
  return businesses;
}

/**
 * Build the full config, merging settings.json over .env defaults.
 */
function buildConfig() {
  const saved = loadSettings();

  let businesses;
  let anthropicApiKey;
  let pexelsApiKey;

  if (saved) {
    // Use saved settings
    businesses = {};
    (saved.businesses || []).forEach(b => {
      businesses[b.id] = {
        id: b.id,
        name: b.name,
        wordpress: {
          url: b.wordpressUrl || '',
          username: b.wordpressUsername || '',
          appPassword: b.wordpressAppPassword || '',
        },
      };
    });
    anthropicApiKey = saved.anthropicApiKey || process.env.ANTHROPIC_API_KEY || '';
    pexelsApiKey = saved.pexelsApiKey || process.env.PEXELS_API_KEY || '';
  } else {
    // First run — load from .env and create settings.json
    businesses = loadBusinessesFromEnv();
    anthropicApiKey = process.env.ANTHROPIC_API_KEY || '';
    pexelsApiKey = process.env.PEXELS_API_KEY || '';

    // Persist to settings.json so UI can manage from here
    const initial = {
      anthropicApiKey,
      pexelsApiKey,
      businesses: Object.values(businesses).map(b => ({
        id: b.id,
        name: b.name,
        wordpressUrl: b.wordpress.url,
        wordpressUsername: b.wordpress.username,
        wordpressAppPassword: b.wordpress.appPassword,
      })),
    };
    saveSettings(initial);
    console.log('[Config] Created data/settings.json from environment variables');
  }

  return {
    port: parseInt(process.env.PORT || '3001', 10),
    nodeEnv: process.env.NODE_ENV || 'development',
    anthropicApiKey,
    pexelsApiKey,
    businesses,
  };
}

// Build initial config
const config = buildConfig();

/**
 * Reload config from settings.json (called after UI saves changes).
 */
function reloadConfig() {
  const fresh = buildConfig();
  Object.assign(config, fresh);
  return config;
}

/**
 * Get the machine's local network IP addresses for multi-device access.
 */
function getNetworkAddresses() {
  const interfaces = os.networkInterfaces();
  const addresses = [];
  for (const [name, nets] of Object.entries(interfaces)) {
    for (const net of nets) {
      if (net.family === 'IPv4' && !net.internal) {
        addresses.push({ name, address: net.address });
      }
    }
  }
  return addresses;
}

/**
 * Validate config — soft validation (warnings, not fatal).
 * The app should start even without full config so the user can set things up via UI.
 */
function validate() {
  const warnings = [];

  if (!config.anthropicApiKey) {
    warnings.push('No Anthropic API key configured — you can use Manual AI mode (free) or add a key in Settings');
  }
  if (!config.pexelsApiKey) {
    warnings.push('No Pexels API key configured — stock images will not be available. Add one in Settings (free at pexels.com/api)');
  }
  if (Object.keys(config.businesses).length === 0) {
    warnings.push('No businesses configured yet — add one in Settings to get started');
  }

  for (const [id, biz] of Object.entries(config.businesses)) {
    if (!biz.wordpress.url) warnings.push(`Business "${biz.name}": WordPress URL is missing`);
    if (!biz.wordpress.username) warnings.push(`Business "${biz.name}": WordPress username is missing`);
    if (!biz.wordpress.appPassword) warnings.push(`Business "${biz.name}": WordPress app password is missing`);
  }

  if (warnings.length > 0) {
    console.log('\n[Config] Setup notes:');
    warnings.forEach(w => console.log(`  - ${w}`));
    console.log('');
  }

  return warnings;
}

module.exports = { config, validate, saveSettings, loadSettings, reloadConfig, getNetworkAddresses, SETTINGS_PATH };
