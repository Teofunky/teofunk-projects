async function request(url, options = {}) {
  const method = options.method || 'GET';
  const headers = new Headers(options.headers || {});
  if (!['GET', 'HEAD'].includes(method)) headers.set('X-JJ-Request', '1');
  if (options.json !== undefined) {
    headers.set('Content-Type', 'application/json');
    options.body = JSON.stringify(options.json);
  }
  const response = await fetch(url, { ...options, method, headers, credentials: 'same-origin' });
  if (!response.ok) {
    let message = `Ошибка ${response.status}`;
    try { message = (await response.json()).error || message; } catch {}
    const error = new Error(message);
    error.status = response.status;
    throw error;
  }
  if (response.status === 204) return null;
  const type = response.headers.get('content-type') || '';
  return type.includes('application/json') ? response.json() : response;
}

export const api = {
  status: () => request('/api/status'),
  me: () => request('/api/me'),
  register: (json) => request('/api/register', { method: 'POST', json }),
  login: (json) => request('/api/login', { method: 'POST', json }),
  logout: () => request('/api/logout', { method: 'POST', json: {} }),
  messages: (before, limit = 500) => request(`/api/messages?limit=${limit}${before ? `&before=${before}` : ''}`),
  sendMessage: (json) => request('/api/messages', { method: 'POST', json }),
  readMessages: (ids) => request('/api/messages/read', { method: 'POST', json: { ids } }),
  initAttachment: () => request('/api/attachments/init', { method: 'POST', json: {} }),
  uploadChunk: (id, index, body) => request(`/api/attachments/${id}/chunks/${index}`, {
    method: 'PUT', headers: { 'Content-Type': 'application/octet-stream' }, body,
  }),
  completeAttachment: (id, chunkCount) => request(`/api/attachments/${id}/complete`, {
    method: 'POST', json: { chunkCount },
  }),
  getAttachment: async (id) => {
    const response = await request(`/api/attachments/${id}`);
    return response.arrayBuffer();
  },
  pushKey: () => request('/api/push/public-key'),
  subscribePush: (subscription) => request('/api/push/subscribe', { method: 'POST', json: subscription }),
  unsubscribePush: (endpoint) => request('/api/push/subscribe', { method: 'DELETE', json: { endpoint } }),
};
