/*
 * K-Beauty Bliss Owner — shared pieces (Lane MAC, Petal).
 *
 * SECURITY. Every string from the server reaches the DOM through esc(). The
 * CSRF value comes only from POST enrol / unlock and lives in this module's
 * memory plus sessionStorage 'oa.k' (so a reload of the same tab keeps it;
 * never localStorage), and setKey(null) drops both on lock, forget and
 * sign-out. It rides on EVERY /api request except state, enrol and unlock.
 * The device and session tokens are HttpOnly cookies this code cannot read.
 * The PIN is never stored anywhere. localStorage holds three conveniences and
 * nothing else (see `store` below): whether the install sheet was offered,
 * the full-screen preference, and the last notification seen. Shop data
 * (orders, customers, products) is held in MEMORY only, never in any browser
 * storage, and resetData() empties it whenever the app locks or signs out.
 *
 * NO LAYOUT MEASURING. Nothing here asks an element for its size; sizing is
 * CSS (CLAUDE.md rule 4). Animations are transform and opacity only.
 */

export const S = {
  base: document.body.getAttribute('data-base') || '',
  me: null, csrf: null, vapid: null, groups: {}, idle: 12, tz: null,
  cursor: 0, seen: 0, unread: 0, stage: 'boot',
  staleMs: 30 * 60000, spinning: false, spinAt: 0, gen: 0,
};

/* ----------------------------------------------------------- the CSRF key */

export function setKey(v) {
  S.csrf = v || null;
  try {
    if (S.csrf) window.sessionStorage.setItem('oa.k', S.csrf); else window.sessionStorage.removeItem('oa.k');
  } catch (e) { /* private mode: memory only */ }
}
try { S.csrf = window.sessionStorage.getItem('oa.k') || null; } catch (e) { S.csrf = null; }

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ESC[c]);
export const $ = (s, r) => (r || document).querySelector(s);
export const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
export const ic = (n, c) => '<svg class="i ' + (c || '') + '" aria-hidden="true"><use href="#i-' + n + '"/></svg>';

/* The three conveniences, and only these keys, ever. */
const KEYS = ['oa.a2', 'oa.fs', 'oa.seen'];
export const store = {
  get(k) { if (KEYS.indexOf(k) === -1) return null; try { return window.localStorage.getItem(k); } catch (e) { return null; } },
  set(k, v) { if (KEYS.indexOf(k) === -1) return; try { window.localStorage.setItem(k, String(v)); } catch (e) { /* private mode */ } },
};

/* ------------------------------------------------------------- the API */

export class AuthError extends Error { constructor(code) { super(code); this.code = code; } }
let onAuth = () => {};
export function onAuthLost(fn) { onAuth = fn; }

const NO_KEY = ['state', 'enrol', 'unlock'];

/**
 * `passive` marks a GET nobody asked for — the 25 s changes poll and the
 * silent background refresh — so the server does not count it as use and the
 * idle lock still runs. Navigation and "Sync now" are use, and send no flag.
 */
export async function api(method, path, body, passive) {
  const headers = { Accept: 'application/json', 'X-OA': '1' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (S.csrf && NO_KEY.indexOf(path.split('?')[0]) === -1) headers['X-OA-CSRF'] = S.csrf;
  if (passive && method === 'GET') headers['X-OA-Passive'] = '1';
  let r;
  try {
    r = await fetch(S.base + '/api/' + path, { method, headers, credentials: 'same-origin', cache: 'no-store', body: body === undefined ? undefined : JSON.stringify(body) });
  } catch (e) {
    return { ok: false, status: 0, data: { message: 'No connection. Check the network and try again.' } };
  }
  let data = {};
  try { data = await r.json(); } catch (e) { data = {}; }
  if ([401, 403, 419].indexOf(r.status) !== -1 && ['pin', 'locked', 'no_device', 'disabled', 'csrf'].indexOf(data.code) !== -1 && path !== 'unlock' && path !== 'enrol') {
    onAuth(data.code);
    throw new AuthError(data.code);
  }
  if (r.status === 404 && !data.code && !data.message) data.message = 'Not found. If the app was just updated, clear the route cache (Platform → Cache).';
  return { ok: r.ok && data.ok !== false, status: r.status, data };
}

/* ------------------------------------------------------------ formatting */

/* Dates in the SHOP's time zone (Asia/Dubai), whatever the phone is set to,
   so "Today" and "10:19 AM" mean the same as they do in the admin. */
const F = {};
const fmt = (kind, opts) => (F[kind + S.tz] ||= new Intl.DateTimeFormat(kind === 't' ? 'en-US' : 'en-GB', Object.assign({ timeZone: S.tz || undefined }, opts)));
export const day = (iso) => (iso ? fmt('d', { day: 'numeric', month: 'short' }).format(new Date(iso)) : '');
export const time = (iso) => (iso ? fmt('t', { hour: 'numeric', minute: '2-digit' }).format(new Date(iso)) : '');
export const year = (iso) => (iso ? fmt('y', { year: 'numeric' }).format(new Date(iso)) : '');
export const dateKey = (d) => fmt('k', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
export const when = (iso) => (iso ? day(iso) + ' ' + year(iso) + ', ' + time(iso) : '');
export function ago(iso) {
  if (!iso) return '';
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (s < 60) return 'now';
  if (s < 3600) return Math.floor(s / 60) + 'm';
  if (s < 86400) return Math.floor(s / 3600) + 'h';
  return day(iso);
}

/* Server status -> the preview's pill codes and words. */
export const ST = {
  pending: ['pend', 'Pending payment'], processing: ['proc', 'Processing'], onhold: ['hold', 'On hold'], shipped: ['ship', 'Shipped'],
  completed: ['done', 'Completed'], cancelled: ['canc', 'Cancelled'], refunded: ['ref', 'Refunded'], failed: ['fail', 'Failed'], draft: ['pend', 'Draft'],
};
export const stWord = (s) => (ST[s] ? ST[s][1] : String(s || ''));
export const pill = (s, extra) => '<span class="pill" data-s="' + esc(ST[s] ? ST[s][0] : 'pend') + '"' + (extra || '') + '>' + esc(stWord(s)) + '</span>';

export const hue = (s) => Array.from(String(s || '')).reduce((h, c) => (h * 31 + c.charCodeAt(0)) % 360, 7);
export function ini(n) {
  const w = String(n || '').trim().split(/\s+/).filter(Boolean);
  return ((w[0] || '?').charAt(0) + (w.length > 1 ? w[w.length - 1].charAt(0) : '')).toUpperCase();
}
/* The avatar's hue travels as data-h and is applied by paint() through the
   CSSOM: the CSP allows no inline style attribute, and needs none. */
export const av = (n, c) => '<i class="av ' + (c || '') + '" data-h="' + hue(n) + '" aria-hidden="true">' + esc(ini(n)) + '</i>';

/**
 * innerHTML, then the three per-element values markup cannot carry under the
 * CSP: an avatar hue (--h), a bar's length (scaleX) and a column's height
 * (scaleY). Writes only — nothing is read back from layout.
 */
export function paint(el, html) {
  if (!el) return el;
  if (html !== undefined) el.innerHTML = html;
  vars(el);
  return el;
}
export function vars(el) {
  $$('[data-sync]', el).forEach(spinMark);
  $$('[data-h]', el).forEach((x) => x.style.setProperty('--h', x.getAttribute('data-h')));
  $$('[data-sx]', el).forEach((x) => { x.style.transform = 'scaleX(' + x.getAttribute('data-sx') + ')'; });
  $$('[data-sy]', el).forEach((x) => { x.style.transform = 'scaleY(' + x.getAttribute('data-sy') + ')'; });
}
export const th = (src, c) => (src
  ? '<img class="th ' + (c || '') + '" src="' + esc(src) + '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">'
  : '<i class="th blank ' + (c || '') + '" aria-hidden="true"></i>');
export const logo = (c) => '<i class="logo ' + (c || '') + '" aria-hidden="true">KB</i>';
export const tgl = (on, label, attrs) => '<button type="button" class="tgl" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" aria-label="' + esc(label) + '" ' + (attrs || '') + '></button>';
/* A screen header. Every one carries "Sync now" unless `cls` says nosync
   (the tablet's right-hand order pane: its list pane already has one). */
export const top = (t, sub, left, right, cls) => '<header class="top ' + (cls || '') + '">' + (left || '') + '<div class="tt"><h3>' + t + '</h3>' + (sub ? '<p>' + sub + '</p>' : '') + '</div>' + (right || '') + (/\bnosync\b/.test(cls || '') ? '' : syncBtn('plain')) + '</header>';
export const back = (href, label) => '<a class="ib plain" href="' + href + '" aria-label="' + esc(label || 'Back') + '">' + ic('back') + '</a>';
export const errorBox = (msg) => '<div class="card empty" role="alert">' + esc(msg || 'Something went wrong.') + '</div>';

/* ------------------------------------------------------------- sync now */

/* The header's refresh icon. Its state is a class, never part of the markup,
   so a header repainted mid-sync picks it up from spinMark() — and the spin
   carries on from the same angle rather than jumping back to 0. */
export const syncBtn = (cls) => '<button type="button" class="ib sync ' + (cls || '') + '" data-sync aria-label="Sync now" title="Sync now">' + ic('refresh') + '</button>';

function spinMark(b) {
  b.classList.toggle('on', S.spinning);
  b.setAttribute('aria-busy', S.spinning ? 'true' : 'false');
  const i = b.firstElementChild;
  if (i) i.style.animationDelay = S.spinning ? -((Date.now() - S.spinAt) % 900) + 'ms' : '';
}
export function setSpin(on) {
  S.spinning = !!on;
  if (on) S.spinAt = Date.now();
  $$('[data-sync]').forEach(spinMark);
}

/* --------------------------------------------- freshness, in memory only */

/*
 * When each section last landed (dash, orders, products, customers, notes,
 * order:ID, product:ID, customer:ID). A screen whose section landed within
 * S.staleMs is drawn at once from memory and refreshed silently; older, or
 * never, and it shows grey bars until its request lands.
 */
const AT = {};
export const landed = (k) => { AT[k] = Date.now(); };
export const isFresh = (k) => !!AT[k] && Date.now() - AT[k] < S.staleMs;

/* One request per key at a time: a second trigger joins the first. */
const FLIGHT = {};
export function once(k, fn, force) {
  if (FLIGHT[k] && !force) return FLIGHT[k];
  const p = Promise.resolve().then(fn).finally(() => { if (FLIGHT[k] === p) delete FLIGHT[k]; });
  FLIGHT[k] = p;
  return p;
}

/* Every module's held data, dropped on lock / forget / sign-out. */
const RESETS = [];
export const onReset = (fn) => RESETS.push(fn);
export function resetData() {
  S.gen++;                                    // a request still in the air lands nowhere
  Object.keys(AT).forEach((k) => { delete AT[k]; });
  Object.keys(FLIGHT).forEach((k) => { delete FLIGHT[k]; });
  RESETS.forEach((fn) => fn());
}

/** A newer first page over a list he has paged further: keep his extra pages. */
export function merge(fresh, old, paged) {
  if (!paged) return fresh;
  const ids = new Set(fresh.map((x) => x.id));
  return fresh.concat(old.filter((x) => !ids.has(x.id)));
}

/* ------------------------------------------------------------- screens */

/** The element now showing screen `k` (record `id` when given), if any. */
export const onScreen = (k, id) => document.querySelector('[data-scr="' + k + '"]' + (id ? '[data-id="' + (+id) + '"]' : ''));

export function mark(host, k, id, loading) {
  host.setAttribute('data-scr', k);
  host.setAttribute('data-id', String(+id || 0));
  host.setAttribute('aria-busy', loading ? 'true' : 'false');
  if (loading) host.setAttribute('data-sk', ''); else host.removeAttribute('data-sk');
  return host;
}

/**
 * Draw a [header][.body] screen. When the same screen is already up, the
 * header is swapped only if it changed and the body's CONTENT is replaced in
 * place — the scroller survives, so a silent refresh keeps his scroll
 * position and nothing jumps.
 */
export function screen(host, k, id, head, body, loading) {
  const same = host.getAttribute('data-scr') === k && host.getAttribute('data-id') === String(+id || 0);
  const b = same ? $(':scope > .body', host) : null;
  if (b) {
    if (host.oaHead !== head) {
      const h = b.previousElementSibling;
      if (h) h.remove();
      b.insertAdjacentHTML('beforebegin', head);
    }
    b.innerHTML = body;
  } else host.innerHTML = head + '<div class="body">' + body + '</div>';
  host.oaHead = head;
  mark(host, k, id, loading);
  vars(host);
  return host;
}

/* ------------------------------------------------- grey loading bars */

/* Bars and blocks shaped like the real thing; CSS (.sk) gives them their
   size and the shimmer, which prefers-reduced-motion stills. */
export const ln = (w, c) => '<i class="sk sl' + (w ? ' w' + w : '') + (c ? ' ' + c : '') + '"></i>';
export const blk = (c) => '<i class="sk ' + c + '"></i>';
export const times = (n, fn) => Array.from({ length: n }, (_, i) => fn(i)).join('');
export const skChips = (n) => times(n, () => blk('k-chip'));
export const skNote = '<span class="sr" role="status">Loading</span>';

/* ----------------------------------------------------------------- toast */

let toastEl = null, toastT = 0;
export function toast(msg, bad) {
  if (toastEl) toastEl.remove();
  toastEl = document.createElement('div');
  toastEl.className = 'toast' + (bad ? ' bad' : '');
  toastEl.setAttribute('role', 'status');
  toastEl.innerHTML = ic(bad ? 'alert' : 'check') + '<span>' + esc(msg) + '</span>';
  document.body.appendChild(toastEl);
  clearTimeout(toastT);
  toastT = setTimeout(() => { if (toastEl) { toastEl.remove(); toastEl = null; } }, 2600);
}

/* ---------------------------------------------------------------- sheets */

let closeCurrent = null;
/** Open a bottom sheet. Returns close(); `mount(panel, close)` wires it. */
export function sheet(title, html, mount, cls) {
  if (closeCurrent) closeCurrent(true);
  const scrim = document.createElement('div');
  scrim.className = 'scrim';
  const s = document.createElement('section');
  s.className = 'sheet ' + (cls || '');
  s.setAttribute('role', 'dialog');
  s.setAttribute('aria-modal', 'true');
  s.setAttribute('aria-label', title);
  s.innerHTML = '<i class="grab" aria-hidden="true"></i>' + (title ? '<div class="sh-h"><b>' + esc(title) + '</b><button class="ib plain" type="button" data-close aria-label="Close">' + ic('x') + '</button></div>' : '') + html;
  vars(s);
  document.body.appendChild(scrim);
  document.body.appendChild(s);
  requestAnimationFrame(() => { scrim.classList.add('show'); s.classList.add('open'); });
  let done = false;
  const close = (instant) => {
    if (done) return;
    done = true;
    closeCurrent = null;
    scrim.classList.remove('show');
    s.classList.remove('open');
    setTimeout(() => { scrim.remove(); s.remove(); }, instant ? 0 : 330);
  };
  scrim.addEventListener('click', () => close());
  s.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) close(); });
  closeCurrent = close;
  if (mount) mount(s, close);
  return close;
}
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && closeCurrent) closeCurrent(); });

export async function busy(el, fn) {
  if (el) el.classList.add('busy');
  try { return await fn(); } finally { if (el) el.classList.remove('busy'); }
}

/** One call after typing pauses: never a request per key. */
export function debounce(fn, ms) {
  let t = 0;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

export const isTablet = () => window.matchMedia('(min-width: 768px)').matches;
export const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.matchMedia('(display-mode: fullscreen)').matches || navigator.standalone === true;
export const isIOS = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
