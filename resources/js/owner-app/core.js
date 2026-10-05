/*
 * K-Beauty Bliss Owner — shared pieces (Lane MAC, Petal).
 *
 * SECURITY. Every string from the server reaches the DOM through esc(). The
 * CSRF value lives in this module's memory only. The device and session
 * tokens are HttpOnly cookies this code cannot read. The PIN is never stored
 * anywhere. Browser storage holds three conveniences and nothing else (see
 * `store` below): whether the install sheet was offered, the full-screen
 * preference, and the last notification seen.
 *
 * NO LAYOUT MEASURING. Nothing here asks an element for its size; sizing is
 * CSS (CLAUDE.md rule 4). Animations are transform and opacity only.
 */

export const S = {
  base: document.body.getAttribute('data-base') || '',
  me: null, csrf: null, vapid: null, groups: {}, idle: 12, tz: null,
  cursor: 0, seen: 0, unread: 0, stage: 'boot', pulse: null,
};

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

export async function api(method, path, body) {
  const headers = { Accept: 'application/json', 'X-OA': '1' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && S.csrf) headers['X-OA-CSRF'] = S.csrf;
  let r;
  try {
    r = await fetch(S.base + '/api/' + path, { method, headers, credentials: 'same-origin', cache: 'no-store', body: body === undefined ? undefined : JSON.stringify(body) });
  } catch (e) {
    return { ok: false, status: 0, data: { message: 'No connection. Check the network and try again.' } };
  }
  let data = {};
  try { data = await r.json(); } catch (e) { data = {}; }
  if ([401, 403, 419].indexOf(r.status) !== -1 && ['locked', 'no_device', 'disabled', 'csrf'].indexOf(data.code) !== -1 && path !== 'unlock' && path !== 'enrol') {
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
  $$('[data-h]', el).forEach((x) => x.style.setProperty('--h', x.getAttribute('data-h')));
  $$('[data-sx]', el).forEach((x) => { x.style.transform = 'scaleX(' + x.getAttribute('data-sx') + ')'; });
  $$('[data-sy]', el).forEach((x) => { x.style.transform = 'scaleY(' + x.getAttribute('data-sy') + ')'; });
}
export const th = (src, c) => (src
  ? '<img class="th ' + (c || '') + '" src="' + esc(src) + '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">'
  : '<i class="th blank ' + (c || '') + '" aria-hidden="true"></i>');
export const logo = (c) => '<i class="logo ' + (c || '') + '" aria-hidden="true">KB</i>';
export const tgl = (on, label, attrs) => '<button type="button" class="tgl" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" aria-label="' + esc(label) + '" ' + (attrs || '') + '></button>';
export const top = (t, sub, left, right, cls) => '<header class="top ' + (cls || '') + '">' + (left || '') + '<div class="tt"><h3>' + t + '</h3>' + (sub ? '<p>' + sub + '</p>' : '') + '</div>' + (right || '') + '</header>';
export const back = (href, label) => '<a class="ib plain" href="' + href + '" aria-label="' + esc(label || 'Back') + '">' + ic('back') + '</a>';
export const errorBox = (msg) => '<div class="card empty" role="alert">' + esc(msg || 'Something went wrong.') + '</div>';
export const skel = (n, tall) => Array.from({ length: n || 3 }, () => '<div class="skel' + (tall ? ' tall' : '') + '"></div>').join('');

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
