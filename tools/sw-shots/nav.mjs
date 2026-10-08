/*
 * Lane SW: how many times does the SERVER see one navigation, with the shop's
 * real service worker registered and in control?
 *
 *   node tools/sw-shots/nav.mjs PORT LABEL [SHOTS_DIR]
 *
 * Reads the preview's request log (tools/ty-shots/preview-index.php writes one
 * line per request the shop answered, with its Service-Worker-Navigation-
 * Preload header), navigates to each page once, and counts the lines that
 * page produced. Also proves nothing is served stale: the bag count a page
 * shows after an add is the new one, and Cache Storage holds no HTML.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.argv[2] || '8881';
const LABEL = process.argv[3] || 'run';
const BASE = `http://127.0.0.1:${PORT}`;
const SHOTS = process.argv[4] || path.resolve(HERE, '../../docs/lane-sw-shots');
const STATE = path.resolve(HERE, `../../storage/sw-logs/preview-${PORT}/state`);
const LOG = `${STATE}/requests.log`;
const IDS = JSON.parse(fs.readFileSync(`${STATE}/ids.json`, 'utf8'));
fs.mkdirSync(SHOTS, { recursive: true });

const lines = () => (fs.existsSync(LOG) ? fs.readFileSync(LOG, 'utf8').trim().split('\n').filter(Boolean) : []);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const out = {};

for (const width of [390, 1280]) {
  // A real browser's user agent: BlockGate answers a headless one 403 on the
  // cart and checkout ("ask bots to leave"), which is not the page under test.
  const context = await browser.newContext({
    viewport: { width, height: width < 600 ? 844 : 900 },
    userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

  await page.goto(`${BASE}/product/ty-nida-cream`, { waitUntil: 'load' });
  await page.evaluate(() => navigator.serviceWorker.ready);
  await page.reload({ waitUntil: 'load' });
  const controlled = await page.evaluate(() => !!navigator.serviceWorker.controller);
  const preloadOn = await page.evaluate(async () => {
    const r = await navigator.serviceWorker.ready;
    return r.navigationPreload ? (await r.navigationPreload.getState()).enabled : null;
  });

  // Something in the bag, so /cart/ and /checkout/ are real pages.
  await page.evaluate(async (id) => {
    await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
  }, IDS.nida);

  const targets = ['/cart/', '/checkout/', '/checkout/success?order=10001', '/checkout/pending?order=10001', '/my-account/', '/product/ty-arencia-rice', '/shop/'];
  const rows = {};
  for (const t of targets) {
    await sleep(300);
    const before = lines().length;
    const res = await page.goto(BASE + t, { waitUntil: 'load' });
    await sleep(600);
    // Every request the server answered for THIS page's address (and, for a
    // redirect, the address it went on to), not the page's own fetches.
    const final = page.url().replace(BASE, '');
    const mine = lines().slice(before).filter((l) => {
      const [, , m, uri] = l.split(' ');
      return m === 'GET' && (uri === t || uri === final);
    });
    rows[t] = {
      finalUrl: page.url().replace(BASE, ''),
      status: res ? res.status() : null,
      fromServiceWorker: res ? res.fromServiceWorker() : null,
      serverRequests: mine.length,
      log: mine.map((l) => l.split(' ').slice(2, 6).join(' ')),
    };
  }

  // Prefetch on intent still fires and is used (InstantNav): hover a product
  // link on the shop page, then open it.
  await page.goto(BASE + '/shop/', { waitUntil: 'load' });
  await sleep(500);
  const link = page.locator('a[href*="/product/"]:visible').first();
  const href = (await link.getAttribute('href')).replace(BASE, '');
  let mark = lines().length;
  await link.hover();
  await sleep(1200);
  const prefetched = lines().slice(mark).filter((l) => l.includes(' GET ' + href + ' ') && /purpose=prefetch/.test(l)).length;
  mark = lines().length;
  await link.click();
  await page.waitForURL('**' + href);
  await sleep(500);
  const afterClick = lines().slice(mark).filter((l) => l.includes(' GET ' + href + ' ')).length;
  const prefetch = { href, prefetchRequests: prefetched, serverRequestsOnClick: afterClick };

  // Not stale: add a second product, then the bag page must show 2 lines.
  await page.evaluate(async (id) => {
    await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
  }, IDS.rohto);
  await page.goto(BASE + '/cart/', { waitUntil: 'load' });
  const fresh = await page.evaluate(() => ({
    hasRohto: document.body.innerText.includes('Rohto Mentholatum'),
    hasNida: document.body.innerText.includes('NIDA Cream'),
  }));
  const cached = await page.evaluate(async () => {
    const o = {};
    for (const k of await caches.keys()) {
      const c = await caches.open(k);
      const ks = await c.keys();
      const html = [];
      for (const r of ks) {
        const res = await c.match(r);
        if (/text\/html/.test(res.headers.get('content-type') || '') && !/\/offline$/.test(new URL(r.url).pathname)) html.push(new URL(r.url).pathname);
      }
      o[k] = { entries: ks.length, htmlOtherThanOffline: html };
    }
    return o;
  });
  await page.screenshot({ path: `${SHOTS}/${LABEL}-cart-${width}.png` });
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);

  out[width] = { controlled, preloadOn, rows, prefetch, fresh, cached, scrollWidth, errors };
  console.log(width, JSON.stringify(out[width], null, 1));
  await context.close();
}

fs.writeFileSync(`${SHOTS}/${LABEL}-nav.json`, JSON.stringify(out, null, 1));
await browser.close();
