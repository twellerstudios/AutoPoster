const BASE = process.env.REACT_APP_API_URL || '';

async function request(method, path, body) {
  const res = await fetch(`${BASE}/api${path}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await res.json();
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

/**
 * Generate a blog post preview with optional image uploads.
 * Uses multipart/form-data to support file uploads.
 */
async function generatePostWithImages({ businessId, topic, tone, wordCount, keywords, images, imageCaptions }) {
  const formData = new FormData();
  formData.append('businessId', businessId);
  formData.append('topic', topic);
  formData.append('tone', tone);
  formData.append('wordCount', String(wordCount));
  if (keywords) formData.append('keywords', keywords);

  // Append image files and their captions
  if (images && images.length > 0) {
    images.forEach((file, index) => {
      formData.append('images', file);
      if (imageCaptions && imageCaptions[index]) {
        formData.append(`imageCaption_${index}`, imageCaptions[index]);
      }
    });
  }

  const res = await fetch(`${BASE}/api/posts/generate`, {
    method: 'POST',
    body: formData,
    // No Content-Type header — browser sets it with boundary for multipart
  });

  const data = await res.json();
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

/**
 * Publish a previously generated preview to WordPress.
 */
async function publishPost({ previewId, title, htmlContent }) {
  return request('POST', '/posts/publish', { previewId, title, htmlContent });
}

export const api = {
  getBusinesses: () => request('GET', '/businesses'),
  testBusiness: (id) => request('GET', `/businesses/${id}/test`),
  health: () => request('GET', '/health'),
  generatePost: generatePostWithImages,
  publishPost,
};
