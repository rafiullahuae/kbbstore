// Engine API: per-launch token from /api/info, sent as X-KAA; the selected ad
// account goes as X-KAA-Account (SSE: ?t=&account=). 127.0.0.1 only.
let token = '';
let account = '';

export async function boot() {
  const r = await fetch('/api/info', { cache: 'no-store' });
  if (!r.ok) throw new Error('engine not answering');
  const info = await r.json();
  token = info.token;
  return info;
}

export function setAccount(id) { account = id || ''; }
export function getAccount() { return account; }

export async function api(path, opts = {}) {
  const headers = Object.assign({ 'X-KAA': token }, opts.headers || {});
  if (account && !opts.noAccount) headers['X-KAA-Account'] = account;
  let body = opts.body;
  if (body && !(body instanceof Blob) && !(body instanceof ArrayBuffer) && typeof body !== 'string') {
    body = JSON.stringify(body);
    headers['content-type'] = 'application/json';
  }
  const r = await fetch(path, { method: opts.method || 'GET', headers, body, cache: 'no-store' });
  const ct = r.headers.get('content-type') || '';
  const data = ct.includes('json') ? await r.json() : await r.text();
  if (!r.ok) {
    const e = new Error((data && data.error) || `HTTP ${r.status}`);
    e.status = r.status;
    throw e;
  }
  return data;
}

// Server-sent events with automatic reconnect (EventSource does it itself).
export function events(onEvent) {
  const es = new EventSource(`/api/events?t=${encodeURIComponent(token)}`);
  for (const t of ['hello', 'heartbeat', 'cycle.start', 'cycle.end', 'alert', 'metrics.updated', 'research.updated', 'accounts.updated', 'action.executed', 'action.proposed', 'sync.progress']) {
    es.addEventListener(t, (ev) => { let d = {}; try { d = JSON.parse(ev.data || '{}'); } catch {} onEvent(t, d); });
  }
  es.onerror = () => onEvent('disconnected', {});
  return es;
}

// Same-origin product photos (canvas stays untainted so it can export PNG).
export const img = (u) => `/api/img?u=${encodeURIComponent(u)}&t=${encodeURIComponent(token)}`;
export const authed = (u) => u + (u.includes('?') ? '&' : '?') + `t=${encodeURIComponent(token)}&account=${encodeURIComponent(account)}`;
