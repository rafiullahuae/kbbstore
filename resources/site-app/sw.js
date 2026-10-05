/*
 * K-Beauty Bliss: the shop's service worker (Lane PW, App -> Site App).
 * Served by SiteAppController at /sw.js with the placeholders filled in.
 *
 * SAFE BY CONSTRUCTION. It is easier to say what it does than what it
 * refuses, so here is all of it:
 *
 *   1. It answers ONLY same-origin GET requests. A POST, a PUT, anything to
 *      another origin (Tabby, Tamara, card / 3-D Secure, Google, Meta,
 *      WhatsApp) is never touched: no respondWith, so the browser does
 *      exactly what it does with no worker at all.
 *   2. It NEVER STORES A PAGE. Every page carries the shopper's bag count,
 *      their name and a CSRF token; a stored copy would show the wrong bag
 *      and fail its next form with 419. Pages come from the network, through
 *      navigation preload so the worker adds no wait, and only when the
 *      network itself fails does the shopper see the offline page.
 *   3. The shopper's own pages, every payment return and the APIs (BYPASS)
 *      are not even passed through: the worker steps aside entirely.
 *   4. It stores three kinds of public file and nothing else: hashed build
 *      assets (cache first, they never change), the app's own versioned
 *      files under /site-app/ (cache first), and product/content images
 *      (shown from cache, refreshed in the background), each with a cap.
 *   5. It names no secret. The admin and owner-app addresses are not in this
 *      file and never will be; it does not need them, because of rule 2.
 */
'use strict';

const BASE = __SA_BASE__;            // '' on the live shop, '/kbb-upgrade' on staging
const VERSION = __SA_VERSION__;
const OFFLINE = __SA_OFFLINE__;      // { '': '/offline', 'ar': '/ar/offline' }
const BYPASS = __SA_BYPASS__;
const ICON = __SA_ICON__;

const SHELL = 'kbb-shell-' + VERSION;
const ASSETS = 'kbb-assets-v1';
const IMAGES = 'kbb-img-v1';
const CAP = { [ASSETS]: 150, [IMAGES]: 80 };
const IMAGE_EXT = /\.(?:jpe?g|png|webp|avif|gif|svg)$/i;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL)
      .then((c) => c.addAll(Object.values(OFFLINE).concat([ICON])))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // Only this worker's own caches, and only older shells: the asset and
    // image caches hold immutable or public files and are trimmed by cap.
    for (const k of await caches.keys()) {
      if (k.startsWith('kbb-shell-') && k !== SHELL) await caches.delete(k);
    }
    if (self.registration.navigationPreload) {
      try { await self.registration.navigationPreload.enable(); } catch (e) { /* not supported */ }
    }
    await self.clients.claim();
  })());
});

/** The path after the base and the locale segment, plus that segment. */
function split(pathname) {
  let p = pathname.startsWith(BASE + '/') ? pathname.slice(BASE.length) : pathname;
  let seg = '';
  for (const s of Object.keys(OFFLINE)) {
    if (s !== '' && (p === '/' + s || p.startsWith('/' + s + '/'))) { seg = s; p = p.slice(s.length + 1) || '/'; break; }
  }
  return { p, seg };
}

function bypassed(p) {
  return BYPASS.some((b) => p === b || p.startsWith(b + '/'));
}

async function trim(name) {
  const cache = await caches.open(name);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - CAP[name]; i++) await cache.delete(keys[i]);
}

/** Store a response only if it is a plain, complete, same-origin 200. */
function storable(res) {
  return res && res.status === 200 && res.type === 'basic' && !/no-store/i.test(res.headers.get('Cache-Control') || '');
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                      // rule 1
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;       // rule 1
  if (req.headers.has('range')) return;                  // media streams: the browser's job

  const { p, seg } = split(url.pathname);

  if (req.mode === 'navigate') {
    if (bypassed(p)) return;                             // rule 3
    event.respondWith((async () => {                     // rule 2: never stored
      try {
        const pre = await event.preloadResponse;
        return pre || await fetch(req);
      } catch (e) {
        const off = await caches.match(OFFLINE[seg] || OFFLINE[''], { cacheName: SHELL });
        return off || Response.error();
      }
    })());
    return;
  }

  if (bypassed(p)) return;

  const asset = url.pathname.startsWith(BASE + '/build/assets/')
    || (url.pathname.startsWith(BASE + '/site-app/') && url.searchParams.has('v'))
    || (url.pathname === BASE + '/site-app.js' && url.searchParams.has('v'));

  if (asset) {                                           // rule 4: immutable, cache first
    event.respondWith((async () => {
      const hit = await caches.match(req, { cacheName: ASSETS });
      if (hit) return hit;
      const res = await fetch(req);
      if (storable(res)) {
        const copy = res.clone();
        event.waitUntil(caches.open(ASSETS).then((c) => c.put(req, copy)).then(() => trim(ASSETS)));
      }
      return res;
    })());
    return;
  }

  if (req.destination === 'image' && IMAGE_EXT.test(url.pathname)) {   // rule 4: public images
    event.respondWith((async () => {
      const hit = await caches.match(req, { cacheName: IMAGES });
      const fresh = fetch(req).then((res) => {
        if (storable(res)) {
          const copy = res.clone();
          return caches.open(IMAGES).then((c) => c.put(req, copy)).then(() => trim(IMAGES)).then(() => res);
        }
        return res;
      });
      if (hit) {
        event.waitUntil(fresh.catch(() => {}));
        return hit;
      }
      return fresh;
    })());
  }
  // Anything else: not ours. The browser handles it.
});
