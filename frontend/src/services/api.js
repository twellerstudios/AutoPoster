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
};
