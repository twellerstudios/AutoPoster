const BASE = process.env.REACT_APP_API_URL || '';

async function request(method, path, body) {
  let res;
  try {
    res = await fetch(`${BASE}/api${path}`, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (err) {
    throw new Error(
      'Cannot reach the server. Make sure the backend is running (npm run dev) ' +
      'and check your network connection.'
    );
  }

  let data;
  try {
    data = await res.json();
  } catch {
    throw new Error(`Server returned an invalid response (HTTP ${res.status}). Try again.`);
  }

  if (!res.ok) {
    const msg = data.error || `HTTP ${res.status}`;
    const err = new Error(msg);
    err.hint = data.hint || '';
    err.details = data.details || [];
    throw err;
  }
  return data;
}

/**
 * Generate a blog post preview with optional image uploads (API mode).
 */
async function generatePostWithImages({ businessId, topic, tone, wordCount, keywords, images, imageCaptions }) {
  const formData = new FormData();
  formData.append('businessId', businessId);
  formData.append('topic', topic);
  formData.append('tone', tone);
  formData.append('wordCount', String(wordCount));
  if (keywords) formData.append('keywords', keywords);

  if (images && images.length > 0) {
    images.forEach((file, index) => {
      formData.append('images', file);
      if (imageCaptions && imageCaptions[index]) {
        formData.append(`imageCaption_${index}`, imageCaptions[index]);
      }
    });
  }

  let res;
  try {
    res = await fetch(`${BASE}/api/posts/generate`, {
      method: 'POST',
      body: formData,
    });
  } catch {
    throw new Error('Cannot reach the server. Check your connection and make sure the backend is running.');
  }

  const data = await res.json();
  if (!res.ok) {
    const err = new Error(data.error || `HTTP ${res.status}`);
    err.hint = data.hint || '';
    throw err;
  }
  return data;
}

/**
 * Get the manual AI prompt (free mode — no API key needed).
 */
async function getManualPrompt({ businessId, topic, tone, wordCount, keywords, images, imageCaptions }) {
  const formData = new FormData();
  formData.append('businessId', businessId);
  formData.append('topic', topic);
  formData.append('tone', tone);
  formData.append('wordCount', String(wordCount));
  if (keywords) formData.append('keywords', keywords);

  if (images && images.length > 0) {
    images.forEach((file, index) => {
      formData.append('images', file);
      if (imageCaptions && imageCaptions[index]) {
        formData.append(`imageCaption_${index}`, imageCaptions[index]);
      }
    });
  }

  let res;
  try {
    res = await fetch(`${BASE}/api/posts/manual-prompt`, {
      method: 'POST',
      body: formData,
    });
  } catch {
    throw new Error('Cannot reach the server. Check your connection.');
  }

  const data = await res.json();
  if (!res.ok) {
    const err = new Error(data.error || `HTTP ${res.status}`);
    err.hint = data.hint || '';
    throw err;
  }
  return data;
}

/**
 * Parse content pasted back from Claude (manual mode).
 */
async function parseManualContent({ sessionId, content }) {
  return request('POST', '/posts/manual-parse', { sessionId, content });
}

/**
 * Add more images to an existing preview.
 */
async function addPreviewImages({ previewId, images, imageCaptions }) {
  const formData = new FormData();
  if (images && images.length > 0) {
    images.forEach((file, index) => {
      formData.append('images', file);
      if (imageCaptions && imageCaptions[index]) {
        formData.append(`imageCaption_${index}`, imageCaptions[index]);
      }
    });
  }

  let res;
  try {
    res = await fetch(`${BASE}/api/posts/preview/${previewId}/images`, {
      method: 'POST',
      body: formData,
    });
  } catch {
    throw new Error('Cannot reach the server.');
  }

  const data = await res.json();
  if (!res.ok) {
    const err = new Error(data.error || `HTTP ${res.status}`);
    err.hint = data.hint || '';
    throw err;
  }
  return data;
}

/**
 * Publish a previously generated preview to selected platforms.
 * @param {string[]} platforms - e.g. ['wordpress', 'facebook']
 */
async function publishPost({ previewId, title, htmlContent, platforms }) {
  return request('POST', '/posts/publish', { previewId, title, htmlContent, platforms });
}

// ── Settings API ──────────────────────────────────────────────────────────────

async function getSettings() {
  return request('GET', '/settings');
}

async function updateSettings(body) {
  return request('PUT', '/settings', body);
}

async function addBusiness(body) {
  return request('POST', '/settings/businesses', body);
}

async function updateBusiness(id, body) {
  return request('PUT', `/settings/businesses/${id}`, body);
}

async function deleteBusiness(id) {
  return request('DELETE', `/settings/businesses/${id}`);
}

async function getNetworkInfo() {
  return request('GET', '/settings/network');
}

export const api = {
  getBusinesses: () => request('GET', '/businesses'),
  testBusiness: (id) => request('GET', `/businesses/${id}/test`),
  testBusinessFacebook: (id) => request('GET', `/businesses/${id}/test-facebook`),
  testBuffer: () => request('GET', '/businesses/buffer/test'),
  getBufferChannels: () => request('GET', '/businesses/buffer/channels'),
  health: () => request('GET', '/health'),
  generatePost: generatePostWithImages,
  getManualPrompt,
  parseManualContent,
  addPreviewImages,
  publishPost,
  // Settings
  getSettings,
  updateSettings,
  addBusiness,
  updateBusiness,
  deleteBusiness,
  getNetworkInfo,
};
