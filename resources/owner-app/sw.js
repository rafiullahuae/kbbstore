/*
 * K-Beauty Bliss Owner — service worker (Lane MAC).
 *
 * Served by AppController::worker() from inside the app's secret path, so its
 * scope is the app and nothing else on the site.
 *
 * WHAT IT CACHES: the app shell and nothing else — the shell page, its one
 * script, its one stylesheet and two icons (SHELL below, written in by the
 * server from the build manifest). None of them carries a name, an order or a
 * token.
 *
 * WHAT IT NEVER CACHES: anything under /api/. Orders, customers, products and
 * the session are fetched from the network every time and never written to
 * Cache Storage — a lost phone gives up no customer data from its caches. The
 * fetch handler returns before touching the cache for any request that is not
 * a GET of a SHELL address, and /api/ is never in SHELL.
 */
const BASE = __OA_BASE__;
const SHELL = __OA_SHELL__;
const VERSION = __OA_VERSION__;
const ICON = __OA_ICON__;
const BADGE = __OA_BADGE__;
const CACHE = 'oa-shell-' + VERSION;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.indexOf('oa-shell-') === 0 && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.indexOf(BASE + '/api/') === 0) return;   // never cached, never served from cache

  // The shell page: network first, so a deploy is picked up at once; the
  // cached copy only when there is no network at all.
  if (req.mode === 'navigate' && (url.pathname === BASE || url.pathname === BASE + '/')) {
    event.respondWith(fetch(req).catch(() => caches.match(BASE + '/')));
    return;
  }

  // The hashed script, stylesheet and icons: cache first; their names change
  // when their bytes do.
  if (SHELL.indexOf(url.pathname) !== -1) {
    event.respondWith(caches.match(url.pathname).then((hit) => hit || fetch(req)));
  }
});

/* ------------------------------------------------------------------ push */

self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = {}; }
  const title = typeof d.t === 'string' && d.t ? d.t : 'K-Beauty Bliss';
  event.waitUntil(self.registration.showNotification(title, {
    body: typeof d.b === 'string' ? d.b : '',
    icon: ICON,
    badge: BADGE,
    tag: typeof d.g === 'string' ? d.g : 'oa',
    renotify: true,
    data: { url: typeof d.u === 'string' && d.u.charAt(0) === '#' ? d.u : '#/' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const hash = (event.notification.data && event.notification.data.url) || '#/';
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) {
      if (new URL(c.url).pathname.indexOf(BASE) === 0) {
        c.postMessage({ type: 'oa:open', url: hash });
        return c.focus();
      }
    }
    return self.clients.openWindow(BASE + '/' + hash);
  }));
});

/* The browser rotated the subscription: the app re-registers it on its next open. */
self.addEventListener('pushsubscriptionchange', () => {});
