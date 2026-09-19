/**
 * The "Addresses and pictures" group, dropped on Store → Import — Lane GP.
 *
 * The suite proves the acceptance. This proves the thing the owner actually
 * does: sign in, drop the group's zip on the same box he drops the catalogue
 * on, and watch the redirect map change because of it. Nothing here posts to
 * the API to make something happen — the upload goes through the screen.
 *
 * ── RUNNING IT ──────────────────────────────────────────────────────────────
 *
 * The shop's real web root is a different directory on the shared host
 * (bootstrap/app.php's usePublicPath), so a preview needs KBB_PUBLIC_PATH and a
 * front controller of its own — see docs/GP-ADDRESSES-LAND.md §9.
 *
 *   KBB_GP_URL=http://127.0.0.1:8971 \
 *   KBB_GP_ZIP=/tmp/addresses.zip \
 *   KBB_GP_OUT=docs/gp-urls-shots \
 *   KBB_GP_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/gp-addresses-land.mjs
 *
 * It signs in as gp@example.test / secret-secret and WRITES REDIRECT ROWS into
 * the shop it points at. Point it at a preview database, never at production.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_GP_URL || 'http://127.0.0.1:8971';
const ZIP = process.env.KBB_GP_ZIP;
const OUT = process.env.KBB_GP_OUT || 'docs/gp-urls-shots';
const CHROME = process.env.KBB_GP_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 1200 } });
const p = await ctx.newPage();
p.on('console', m => { if (m.type() === 'error') console.log('  console error:', m.text()); });
p.on('pageerror', e => console.log('  page error:', e.message));

const shot = async (name, opts = {}) => {
  await p.screenshot({ path: `${OUT}/${name}.png`, ...opts });
  console.log('  shot:', name);
};

await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
await p.fill('input[type="email"]', 'gp@example.test');
await p.fill('input[type="password"]', 'secret-secret');
await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);
console.log('signed in at:', p.url());

// Reached the way the owner reaches it, not by URL.
await p.locator('#nav .nav-group[data-sec="Store"] .nav-gh').click();
await p.waitForTimeout(300);
await p.locator('#nav [data-go="import"]').click();
await p.waitForSelector('#impDrop', { timeout: 15000 });
await p.waitForTimeout(800);
await shot('01-import-screen-no-addresses');

/* ── 1 · the map before the file, which is the map of what we can DERIVE ─── */

await p.locator('#gbUMLoad').scrollIntoViewIfNeeded();
await p.locator('#gbUMLoad').click();
await p.waitForSelector('#gbUMWrite', { timeout: 20000 });
await p.waitForTimeout(600);
await shot('02-map-without-permalinks');

const before = await p.evaluate(async () => (await fetch('/admin-api/urls-media/status')).json());
console.log('before — permalinks present:', before.sources.permalinks.present,
  '| buckets migrate/ask/discard:',
  before.urls.buckets.migrate.count, before.urls.buckets.ask.count, before.urls.buckets.discard.count,
  '| gone at source:', before.media.gone_at_source.count);

/* ── 2 · the group's zip, chosen exactly as the owner chooses it ─────────── */

await p.locator('#impDrop').scrollIntoViewIfNeeded();
await p.setInputFiles('#impFileInput', ZIP);
await p.waitForTimeout(2500);

// Scrolled to the two cards themselves: the point of this shot is that the
// files are NAMED on the screen, not that a toast said "Uploaded".
await p.locator('.impfile', { hasText: 'Old addresses' }).scrollIntoViewIfNeeded();
await p.waitForTimeout(400);
await shot('03-addresses-group-accepted');

const status = await p.evaluate(async () => (await fetch('/admin-api/import/status')).json());
console.log('companions on the screen:',
  status.companions.map(c => `${c.file}=${c.present ? c.rows + ' rows' : 'absent'}`).join(' '));

/* ── 3 · the map after, built from the addresses the old site published ──── */

await p.locator('#gbUMLoad, #gbUMWrite').first().scrollIntoViewIfNeeded();
const reload = await p.locator('#gbUMLoad');
if (await reload.count()) { await reload.click(); } else { await p.locator('#gbUMUndo').click({ trial: true }).catch(() => {}); }
await p.evaluate(async () => { await window.gbUMLoad(); });
await p.waitForTimeout(1200);
await p.locator('#gbUMWrite').scrollIntoViewIfNeeded();
await shot('04-map-with-permalinks');

const after = await p.evaluate(async () => (await fetch('/admin-api/urls-media/status')).json());
console.log('after  — permalinks present:', after.sources.permalinks.present,
  '| rows:', after.sources.permalinks.rows,
  '| buckets migrate/ask/discard:',
  after.urls.buckets.migrate.count, after.urls.buckets.ask.count, after.urls.buckets.discard.count,
  '| gone at source:', after.media.gone_at_source.count);

/* ── 4 · press the button, then follow one of the addresses ──────────────── */

await p.locator('#gbUMWrite').click();
await p.waitForTimeout(2500);
await shot('05-redirects-written');

const written = await p.evaluate(async () => (await fetch('/admin-api/urls-media/status')).json());
console.log('written — create/unchanged:', written.urls.diff.create, written.urls.diff.unchanged);

// The proof that matters: an old address, followed in the browser, landing on
// a real page at the canonical spelling.
const landed = await p.goto(`${BASE}/toners/`, { waitUntil: 'networkidle' });
console.log('GET /toners/ →', landed.status(), 'final url:', p.url());
console.log('  canonical on the page:',
  await p.locator('link[rel="canonical"]').getAttribute('href'));
await shot('06-old-address-landed');

await b.close();
