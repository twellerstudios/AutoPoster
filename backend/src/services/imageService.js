/**
 * Image service — fetches a relevant free stock photo from Pexels.
 * Falls back gracefully if no API key is configured.
 */
const axios = require('axios');
const { config } = require('../config');

/**
 * Search for a relevant image and return its URL + photographer credit.
 * @param {string} query
 * @returns {Promise<{url: string, photographer: string, photographerUrl: string} | null>}
 */
async function findImage(query) {
  if (!config.pexelsApiKey) {
    console.log('[Image] No PEXELS_API_KEY configured — skipping image fetch');
    return null;
  }

  try {
    const response = await axios.get('https://api.pexels.com/v1/search', {
      params: { query, per_page: 5, orientation: 'landscape' },
      headers: { Authorization: config.pexelsApiKey },
      timeout: 8000,
    });

    const photos = response.data?.photos;
    if (!photos || photos.length === 0) return null;

    // Pick the best-sized image (large2x for quality, large for fallback)
    const photo = photos[0];
    return {
      url: photo.src.large2x || photo.src.large,
      photographer: photo.photographer,
      photographerUrl: photo.photographer_url,
      pexelsId: photo.id,
    };
  } catch (err) {
    console.warn('[Image] Pexels fetch failed:', err.message);
    return null;
  }
}

/**
 * Download image buffer from URL for uploading to WordPress media library.
 * @param {string} url
 * @returns {Promise<Buffer>}
 */
async function downloadImage(url) {
  const response = await axios.get(url, {
    responseType: 'arraybuffer',
    timeout: 15000,
  });
  return Buffer.from(response.data);
}

module.exports = { findImage, downloadImage };
