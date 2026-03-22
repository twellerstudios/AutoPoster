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

export const api = {
  getBusinesses: () => request('GET', '/businesses'),
  testBusiness: (id) => request('GET', `/businesses/${id}/test`),
  health: () => request('GET', '/health'),
  generatePost: (payload) => request('POST', '/posts/generate', payload),
  lightroomStatus: () => request('GET', '/lightroom/status'),
  // Ingest / Auto-import
  ingestStatus: () => request('GET', '/ingest/status'),
  ingestStart: () => request('POST', '/ingest/start'),
  ingestStop: () => request('POST', '/ingest/stop'),
  ingestImport: (payload) => request('POST', '/ingest/import', payload),
  ingestBookings: (daysBack = 7, daysForward = 7) =>
    request('GET', `/ingest/bookings?daysBack=${daysBack}&daysForward=${daysForward}`),

  lightroomUpload: async (formData) => {
    const res = await fetch(`${BASE}/api/lightroom/upload`, {
      method: 'POST',
      body: formData, // multipart — no Content-Type header (browser sets boundary)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  },
};
