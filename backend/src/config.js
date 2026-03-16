/**
 * Config loader — reads all BUSINESS_N_* env vars and builds a businesses map.
 * Adding a new business only requires adding env vars, no code changes needed.
 */
require('dotenv').config();

function loadBusinesses() {
  const businesses = {};
  let i = 1;

  while (process.env[`BUSINESS_${i}_ID`]) {
    const prefix = `BUSINESS_${i}_`;
    const id = process.env[`${prefix}ID`];

    businesses[id] = {
      id,
      name: process.env[`${prefix}NAME`] || id,
      wordpress: {
        url: process.env[`${prefix}WP_URL`],
        username: process.env[`${prefix}WP_USERNAME`],
        appPassword: process.env[`${prefix}WP_APP_PASSWORD`],
      },
    };
    i++;
  }

  return businesses;
}

const config = {
  port: parseInt(process.env.PORT || '3001', 10),
  nodeEnv: process.env.NODE_ENV || 'development',
  anthropicApiKey: process.env.ANTHROPIC_API_KEY,
  pexelsApiKey: process.env.PEXELS_API_KEY,
  businesses: loadBusinesses(),
};

// Validate required config on startup
function validate() {
  const errors = [];
  if (!config.anthropicApiKey) errors.push('ANTHROPIC_API_KEY is required');
  if (Object.keys(config.businesses).length === 0) {
    errors.push('At least one BUSINESS_N_* configuration block is required');
  }
  for (const [id, biz] of Object.entries(config.businesses)) {
    if (!biz.wordpress.url) errors.push(`BUSINESS: ${id} missing WP_URL`);
    if (!biz.wordpress.username) errors.push(`BUSINESS: ${id} missing WP_USERNAME`);
    if (!biz.wordpress.appPassword) errors.push(`BUSINESS: ${id} missing WP_APP_PASSWORD`);
  }
  if (errors.length > 0) {
    console.error('\n[Config] Missing required environment variables:');
    errors.forEach(e => console.error(`  ✗ ${e}`));
    console.error('\nCopy backend/.env.example to backend/.env and fill in the values.\n');
    process.exit(1);
  }
}

module.exports = { config, validate };
