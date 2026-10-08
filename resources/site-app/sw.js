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
 *      are never stored and never answered from a cache: a request steps
 *      aside entirely, and a page is the network's own answer -- the one
 *      navigation preload already fetched, so it is asked for ONCE (Lane SW).
 *   4. It stores three kinds of public file and nothing else: hashed build
 *      assets (cache first, they never change), the app's own versioned
 *      files under /site-app/ (cache first), and product/content images
 *      (shown from cache, refreshed in the background), each with a cap.
 *   5. It names no secret. The admin and owner-app addresses are not in this
 *      file and never will be; it does not need them, because of rule 2.
 *   6. A push message (Lane NT) becomes one notification built from three
 *      strings in it, title / body / url, and tapping it opens that url ONLY
 *      if it is on this shop; anything else opens the shop's home page.
 *      A tap posts the message's signed click token to this shop's
 *      /api/site-app/push/click (Lane PN), nowhere else.
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

/*
 * "Update App" (Lane UA, App -> Site App -> App update). This worker already
 * activates itself on install, so a waiting one is rare; the page still asks,
 * so a worker that does wait is never the reason the button did nothing. The
 * message carries no data the worker uses: one fixed word, one fixed action.
 */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
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
    /* (Lane SW) RULE 3 FOR A PAGE: STRAIGHT FROM THE NETWORK, ASKED FOR ONCE.
       Navigation preload is on for the whole registration (activate, below),
       so Chrome has ALREADY sent this page's request by the time this handler
       runs. Stepping aside here (a bare `return`) threw that answer away and
       made the browser ask a SECOND time, about 8 ms later -- measured in
       Chromium: two GETs of /checkout/success, /cart/ and /my-account/ per
       navigation, one carrying `Service-Worker-Navigation-Preload: true`,
       each doing the server's work twice on the shop's most sensitive pages.
       So the answer already on its way is the one handed over: unchanged,
       never stored, never the offline page (a failure is the browser's own
       error, as with no worker), and asked for again only when there is no
       preload at all (Safari, Firefox, a worker that has just woken). */
    if (bypassed(p)) {                                   // rule 3
      event.respondWith(Promise.resolve(event.preloadResponse).then((pre) => pre || fetch(req)));
      return;
    }
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
      const hit = await caches.match(req, { cacheName: ASSETS }).catch(() => undefined);   // (PG2) a refused lookup is a miss, never a broken stylesheet
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
    /* (Lane PG2) THE PICTURE GOES TO THE PAGE FIRST; THE COPY IS KEPT AFTER.
       This used to hand the page its response only once the copy had been
       written to Cache Storage and the cache trimmed. So a cold photograph
       could not paint a single row until it had fully downloaded AND been
       stored, and a write the browser refused (QuotaExceededError: incognito,
       a phone short of space) rejected the response itself -- the <img>
       broke and Chrome painted its alt text, the product title, in place of
       the main photo and every thumbnail. Reproduced in Chromium with a small
       storage quota (docs/PG2-STALLS.md). The store is now waited on by the
       worker (waitUntil), never by the page, and a failed lookup or store is
       simply the picture from the network, as with no worker at all. */
    event.respondWith((async () => {
      const hit = await caches.match(req, { cacheName: IMAGES }).catch(() => undefined);
      const fresh = fetch(req).then((res) => {
        if (storable(res)) {
          const copy = res.clone();
          event.waitUntil(caches.open(IMAGES).then((c) => c.put(req, copy)).then(() => trim(IMAGES)).catch(() => {}));
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

/* ------------------------------------------------------- push (rule 6) */

/** An address on this shop, or the home page. Never another origin, never javascript:. */
function shopUrl(u) {
  const home = self.location.origin + BASE + '/';
  try {
    const url = new URL(typeof u === 'string' && u ? u : home, self.location.origin);
    return url.origin === self.location.origin ? url.href : home;
  } catch (e) {
    return home;
  }
}

self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = {}; }
  if (!d || typeof d !== 'object') d = {};
  const title = typeof d.t === 'string' && d.t ? d.t.slice(0, 120) : 'K-Beauty Bliss';
  event.waitUntil(self.registration.showNotification(title, {
    body: typeof d.b === 'string' ? d.b.slice(0, 300) : '',
    icon: ICON,
    tag: typeof d.g === 'string' && d.g ? d.g.slice(0, 40) : 'kbb',
    data: { url: shopUrl(d.u), c: typeof d.c === 'string' && /^[0-9]{1,18}\.[0-9a-f]{24}$/.test(d.c) ? d.c : '' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const data = event.notification.data || {};
  const url = shopUrl(data.url);
  // The tap, counted (Lane PN): the signed token the payload carried, posted to
  // this shop only, no cookies, fire-and-forget. A failure changes nothing.
  if (data.c) {
    event.waitUntil(fetch(self.location.origin + BASE + '/api/site-app/push/click', {
      method: 'POST', credentials: 'omit', keepalive: true,
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ c: data.c }),
    }).catch(() => {}));
  }
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) {
      if (new URL(c.url).origin === self.location.origin && typeof c.navigate === 'function') {
        return c.focus().then(() => c.navigate(url)).catch(() => self.clients.openWindow(url));
      }
    }
    return self.clients.openWindow(url);
  }));
});
