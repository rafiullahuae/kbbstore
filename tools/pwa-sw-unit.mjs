/*
 * Lane PW: run the shop's service worker (the bytes /sw.js serves) in a Node
 * sandbox and prove what its fetch handler does with every kind of request.
 *
 *   node tools/pwa-sw-unit.mjs /path/to/served-sw.js
 *
 * tests/Feature/SiteAppWorkerRulesTest.php writes the served worker to a file
 * and runs this. Exit 0 and "ok N" when every rule holds; otherwise exit 1
 * and one line per broken rule. Nothing here is a mock of the rules: the
 * handler under test is the real file, and the stubs are the browser.
 */
import fs from 'node:fs';
import vm from 'node:vm';

const src = fs.readFileSync(process.argv[2], 'utf8');
const ORIGIN = 'https://extrabeauty.ae';
const fails = [];
let checks = 0;
const ok = (cond, msg) => { checks++; if (!cond) fails.push(msg); };

// ---- a tiny browser: caches, fetch, Response, the worker global ----------
const store = new Map();            // cacheName -> Map(url -> response)
const fetched = [];
let network = 'up';

class Resp {
  constructor(body, init = {}) { this.body = body; this.status = init.status ?? 200; this.type = init.type ?? 'basic'; this.headers = new Map(Object.entries(init.headers || {})); this.headers.get = (k) => Map.prototype.get.call(this.headers, k.toLowerCase()) ?? null; }
  clone() { return this; }
  static error() { return new Resp(null, { status: 0, type: 'error' }); }
}
const keyOf = (r) => (typeof r === 'string' ? new URL(r, ORIGIN).href : r.url);
const cacheApi = (name) => {
  if (!store.has(name)) store.set(name, new Map());
  const m = store.get(name);
  return {
    put: async (r, res) => { m.set(keyOf(r), res); },
    match: async (r) => m.get(keyOf(r)),
    keys: async () => [...m.keys()].map((url) => ({ url })),
    delete: async (r) => m.delete(keyOf(r)),
    addAll: async (list) => { for (const u of list) m.set(keyOf(u), new Resp('precached ' + u, { headers: { 'content-type': 'text/html' } })); },
  };
};
const caches = {
  open: async (n) => cacheApi(n),
  keys: async () => [...store.keys()],
  delete: async (n) => store.delete(n),
  match: async (r, o = {}) => (o.cacheName ? (store.get(o.cacheName) || new Map()).get(keyOf(r)) : undefined),
};
const listeners = {};
const self = {
  location: new URL(ORIGIN + '/sw.js'),
  addEventListener: (t, fn) => { listeners[t] = fn; },
  skipWaiting: async () => {},
  clients: { claim: async () => {} },
  registration: { navigationPreload: { enable: async () => {} }, unregister: async () => true },
};
const fetchStub = async (req) => {
  fetched.push(req.url || req);
  if (network === 'down') throw new TypeError('Failed to fetch');
  const url = req.url || req;
  const html = req.mode === 'navigate';
  return new Resp('body of ' + url, { headers: { 'content-type': html ? 'text/html' : 'image/png' } });
};
const ctx = vm.createContext({ self, caches, fetch: fetchStub, Response: Resp, URL, console, setTimeout, Object, Array, Promise });
vm.runInContext(src, ctx, { filename: 'sw.js' });

const headers = (h = {}) => ({ has: (k) => Object.keys(h).map((x) => x.toLowerCase()).includes(k.toLowerCase()) });
async function dispatch(url, { method = 'GET', mode = 'no-cors', destination = '', h = {} } = {}) {
  let responded = null;
  const waits = [];
  const event = {
    request: { url: new URL(url, ORIGIN).href, method, mode, destination, headers: headers(h) },
    preloadResponse: Promise.resolve(undefined),
    respondWith: (p) => { responded = p; },
    waitUntil: (p) => waits.push(p),
  };
  listeners.fetch(event);
  const res = responded ? await responded : null;
  await Promise.all(waits);
  return { handled: !!responded, res };
}

// ---- install: precaches the offline page(s) and nothing personal ---------
{
  const waits = [];
  listeners.install({ waitUntil: (p) => waits.push(p) });
  await Promise.all(waits);
  const shells = [...store.keys()].filter((k) => k.startsWith('kbb-shell-'));
  ok(shells.length === 1, 'install should create exactly one kbb-shell-<version> cache, got ' + shells.join(','));
  const pre = [...store.get(shells[0]).keys()].map((u) => new URL(u).pathname);
  ok(pre.includes('/offline'), 'install should precache /offline, got ' + pre.join(','));
  ok(pre.every((p) => p === '/offline' || /^\/[a-z]{2}\/offline$/.test(p) || p.startsWith('/site-app/icons/')), 'install precached something other than the offline page(s) and the icon: ' + pre.join(','));
}

// ---- rule 1: POST, other methods, other origins: never touched -----------
for (const m of ['POST', 'PUT', 'DELETE', 'PATCH']) {
  const r = await dispatch('/cart/add', { method: m });
  ok(!r.handled, m + ' /cart/add was handled by the worker');
  const n = await dispatch('/checkout', { method: m, mode: 'navigate' });
  ok(!n.handled, m + ' navigation to /checkout was handled by the worker');
  // And on paths the worker WOULD handle for a GET, so the method check is
  // what refuses them, not the bypass list.
  for (const [u, o] of [['/', { mode: 'navigate' }], ['/shop/', { mode: 'navigate' }], ['/newsletter', {}],
    ['/build/assets/app-Cfk1PW2Y.js', {}], ['/wp-content/uploads/a.jpg', { destination: 'image' }], ['/site-app/icons/icon-192.png?v=1', {}]]) {
    ok(!(await dispatch(u, { method: m, ...o })).handled, m + ' ' + u + ' was handled by the worker');
  }
}
for (const u of ['https://checkout.tabby.ai/x', 'https://api.tamara.co/checkout', 'https://js.stripe.com/v3', 'https://www.google-analytics.com/g/collect', 'https://connect.facebook.net/en_US/fbevents.js', 'https://wa.me/971585052611', 'https://cdn.example.com/a.png']) {
  ok(!(await dispatch(u)).handled, 'cross-origin ' + u + ' was handled');
  ok(!(await dispatch(u, { mode: 'navigate' })).handled, 'cross-origin navigation ' + u + ' was handled');
  ok(!(await dispatch(u, { destination: 'image' })).handled, 'cross-origin image ' + u + ' was handled');
}
ok(!(await dispatch('/video.mp4', { h: { Range: 'bytes=0-' } })).handled, 'a Range request was handled');

// ---- rule 3: the shopper's own pages, payment returns, APIs: stepped aside
for (const p of ['/cart', '/cart/', '/cart-panel', '/checkout', '/checkout/', '/checkout/success?order=1', '/checkout/pending', '/checkout/order-pay?id=2',
  '/my-account', '/my-account/orders/7', '/account-panel', '/orders/7', '/track-my-order', '/wishlist', '/my-wishlist', '/api/products', '/admin-api/settings',
  '/payments/tamara', '/.well-known/apple-developer-merchantid-domain-association', '/ar/cart', '/ar/checkout/success', '/ar/my-account']) {
  ok(!(await dispatch(p, { mode: 'navigate' })).handled, 'navigation to ' + p + ' was handled; it must be bypassed');
  ok(!(await dispatch(p)).handled, 'request to ' + p + ' was handled; it must be bypassed');
}
// A path that merely STARTS with a bypassed word is a normal page.
ok((await dispatch('/cartier-serum', { mode: 'navigate' })).handled, '/cartier-serum is a product page, not /cart; it should be passed through');

// ---- rule 2: every other page: from the network, never stored ------------
store.forEach((m, k) => { if (!k.startsWith('kbb-shell-')) store.delete(k); });
const shellName = [...store.keys()].find((k) => k.startsWith('kbb-shell-'));
const precached = [...store.get(shellName).keys()].sort().join('|');
for (const p of ['/', '/shop/', '/product/snail-essence/', '/ar/', '/blog/', '/secret-admin-door/dashboard', '/v0jdryu2fl_ve1s2evszcr4/']) {
  const before = fetched.length;
  const r = await dispatch(p, { mode: 'navigate' });
  ok(r.handled && fetched.length === before + 1 && r.res && String(r.res.body).startsWith('body of'), 'navigation ' + p + ' should come from the network');
}
const htmlStored = [];
for (const [name, m] of store) for (const [u, res] of m) {
  if (!name.startsWith('kbb-shell-') && /text\/html/.test(res.headers.get('content-type') || '')) htmlStored.push(name + ' ' + u);
}
ok(htmlStored.length === 0, 'a page was STORED: ' + htmlStored.join(', '));
ok([...store.get(shellName).keys()].sort().join('|') === precached, 'a page was STORED in the shell cache: ' + [...store.get(shellName).keys()].join(', '));

// ---- offline: the offline page, by locale --------------------------------
network = 'down';
{
  const r = await dispatch('/shop/', { mode: 'navigate' });
  ok(r.handled && String(r.res.body).includes('/offline'), 'offline navigation should answer the precached offline page, got ' + (r.res && r.res.body));
  const ar = await dispatch('/ar/shop/', { mode: 'navigate' });
  const arWanted = Object.keys(JSON.parse(src.match(/const OFFLINE = (\{.*?\});/)[1])).includes('ar') ? '/ar/offline' : '/offline';
  ok(ar.handled && String(ar.res.body).includes(arWanted), 'offline Arabic navigation should answer ' + arWanted + ', got ' + (ar.res && ar.res.body));
  const cart = await dispatch('/cart', { mode: 'navigate' });
  ok(!cart.handled, 'offline /cart must still be the browser\'s own (bypassed)');
}
network = 'up';

// ---- rule 4: what it does store ------------------------------------------
{
  const a = '/build/assets/app-Cfk1PW2Y.js';
  const r1 = await dispatch(a);
  ok(r1.handled, 'hashed build asset should be handled');
  const before = fetched.length;
  await dispatch(a);
  ok(fetched.length === before, 'hashed build asset should be served from cache the second time');
  const v = await dispatch('/site-app/icons/icon-192.png?v=abc');
  ok(v.handled, 'a versioned /site-app/ file should be handled');
  ok(!(await dispatch('/site-app/icons/icon-192.png')).handled, 'an UNversioned /site-app/ file must not be cached');
  ok((await dispatch('/wp-content/uploads/2024/05/serum.jpg', { destination: 'image' })).handled, 'a same-origin product image should be handled');
  ok(!(await dispatch('/captcha', { destination: 'image' })).handled, 'an image with no image extension must not be cached');
  ok(!(await dispatch('/wp-content/uploads/a.jpg')).handled, 'a non-image request for an image path must not be cached as an image');
}
// Caps hold.
for (let i = 0; i < 200; i++) await dispatch('/wp-content/uploads/p' + i + '.webp', { destination: 'image' });
for (let i = 0; i < 300; i++) await dispatch('/build/assets/x' + i + '.js');
ok((store.get('kbb-img-v1') || new Map()).size <= 80, 'image cache exceeds its cap of 80: ' + (store.get('kbb-img-v1') || new Map()).size);
ok((store.get('kbb-assets-v1') || new Map()).size <= 150, 'asset cache exceeds its cap of 150: ' + (store.get('kbb-assets-v1') || new Map()).size);

// A 404, a redirect or a no-store response is never stored.
{
  const realFetch = ctx.fetch;
  ctx.fetch = async (req) => new Resp('nope', { status: 404 });
  vm.runInContext('fetch = globalThis.fetch', ctx);
  await dispatch('/build/assets/missing-AAAA.js');
  ok(!(store.get('kbb-assets-v1') || new Map()).has(ORIGIN + '/build/assets/missing-AAAA.js'), 'a 404 asset was stored');
  ctx.fetch = realFetch;
  vm.runInContext('fetch = globalThis.fetch', ctx);
}

// ---- (Lane PG2) a picture reaches the page whatever the cache does --------
// The owner's screenshot -- every product photo replaced by its alt text --
// is what Chrome paints for an <img> whose response FAILED, and this worker
// used to hand the page its picture only after the copy had been written to
// Cache Storage. A write the browser refuses (QuotaExceededError: incognito,
// a full phone) rejected the whole response, so the photo broke; a slow write
// held a picture that had already arrived. The page's answer must not wait
// on the cache at all. Mutation: put back `.then(() => res)` after the
// `caches.open(IMAGES)...put` chain and both checks below go red.
{
  const realOpen = caches.open;
  const pic = '/wp-content/uploads/2026/10/refused-by-the-cache.jpg';
  caches.open = async (n) => {
    const c = cacheApi(n);
    if (n === 'kbb-img-v1') c.put = async () => { throw new Error('QuotaExceededError'); };
    return c;
  };
  let res = null;
  try { res = (await dispatch(pic, { destination: 'image' })).res; } catch (e) { res = null; }
  ok(res && res.status === 200 && res.type !== 'error', 'a picture the cache refused to store did not reach the page (the alt text shows instead)');

  caches.open = async (n) => {
    const c = cacheApi(n);
    if (n === 'kbb-img-v1') c.put = () => new Promise(() => {});   // a write that never finishes
    return c;
  };
  const event = {
    request: { url: new URL('/wp-content/uploads/2026/10/slow-disk.jpg', ORIGIN).href, method: 'GET', mode: 'no-cors', destination: 'image', headers: headers() },
    preloadResponse: Promise.resolve(undefined),
    respondWith: (p) => { event.p = p; },
    waitUntil: () => {},
  };
  listeners.fetch(event);
  const got = await Promise.race([Promise.resolve(event.p).catch(() => null), new Promise((r) => setTimeout(() => r('waiting'), 200))]);
  ok(got && got !== 'waiting' && got.status === 200, 'a picture waited for the cache write before reaching the page');
  caches.open = realOpen;
}

// ---- activate: removes older shells only ---------------------------------
store.set('kbb-shell-old000000000', new Map());
store.set('someone-elses-cache', new Map());
{
  const waits = [];
  listeners.activate({ waitUntil: (p) => waits.push(p) });
  await Promise.all(waits);
  ok(!store.has('kbb-shell-old000000000'), 'activate should delete an older kbb-shell cache');
  ok(store.has('someone-elses-cache'), 'activate must not delete a cache that is not the shop worker\'s');
  ok([...store.keys()].filter((k) => k.startsWith('kbb-shell-')).length === 1, 'exactly one shell should remain after activate');
}

if (fails.length) {
  console.log(fails.map((f) => 'FAIL ' + f).join('\n'));
  process.exit(1);
}
console.log('ok ' + checks);
