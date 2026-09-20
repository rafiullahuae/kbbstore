/**
 * Store → Import, driven with a group ZIP — Lane GM.
 *
 * The suite proves the unpacking. This proves the thing the owner actually
 * does: sign in, drop one zip on the upload box, and press Import. Nothing in
 * this file touches the API directly — every step goes through the screen, so
 * a change that works in a test and not in the browser fails here.
 *
 * ── RUNNING IT ──────────────────────────────────────────────────────────────
 *
 * The shop's real web root is a different directory on the shared host
 * (bootstrap/app.php's usePublicPath), so a preview needs KBB_PUBLIC_PATH and a
 * front controller of its own:
 *
 *   cd public && KBB_PUBLIC_PATH=$PWD php -S 127.0.0.1:8979
 *
 *   KBB_GM_URL=http://127.0.0.1:8979 \
 *   KBB_GM_OUT=docs/gm-zip-shots \
 *   KBB_GM_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/gm-import-accepts-zip.mjs
 *
 * It signs in as gm@example.test / secret-secret and IMPORTS INTO the shop it
 * points at. Point it at a preview database, never at production.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.KBB_GM_URL || 'http://127.0.0.1:8979';
const OUT = process.env.KBB_GM_OUT || 'docs/gm-zip-shots';
const CHROME = process.env.KBB_GM_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

/* ── the group zips, built the way Lane GL will ship them ─────────────────── */

const work = mkdtempSync(join(tmpdir(), 'kbb-gm-shots-'));

const rows = (body) => body.trim().split('\n').length - 1;

function groupZip(name, files, group, skipped) {
  const dir = mkdtempSync(join(tmpdir(), 'kbb-gm-zip-'));
  const manifestFiles = {};

  for (const f of files) {
    const body = readFileSync(`tests/Fixtures/woo/${f}`, 'utf8');
    writeFileSync(join(dir, f), body);
    manifestFiles[f] = {
      rows: rows(body),
      bytes: Buffer.byteLength(body),
      sha256: execFileSync('sha256sum', [join(dir, f)]).toString().split(' ')[0],
    };
  }

  writeFileSync(join(dir, 'manifest.json'), JSON.stringify({
    format: 'kbb-export/1',
    // ONE export id across every group zip. This is the whole reason the
    // manifests have to merge rather than replace one another.
    export_id: '8f14e45f-ceea-467a-9c31-1a2b3c4d5e6f',
    generated_at: '2026-09-18T09:30:00+04:00',
    source: {
      site_url: 'https://kbeautybliss.com',
      wp_version: '6.5.2',
      woo_version: '8.7.0',
      plugin_version: '1.0.0',
    },
    files: manifestFiles,
    groups: {
      selected: [group],
      skipped,
      files: Object.keys(manifestFiles),
      assumed_already_imported: group === 'sales' ? [{
        group: 'sales',
        needs: 'customers',
        severity: 'loses',
        claim: 'Customers was already imported into the new shop when this export was taken. '
          + 'The operator stated this; the plugin cannot see the other shop and did not check it.',
      }] : [],
    },
    notes: [],
  }, null, 2));

  const zip = join(work, name);
  execFileSync('zip', ['-q', '-j', zip, ...files.map(f => join(dir, f)), join(dir, 'manifest.json')]);

  return zip;
}

const catalogueZip = groupZip('kbb-export-catalogue.zip',
  ['categories.csv', 'brands.csv', 'products.csv'], 'catalogue',
  ['sales', 'customers', 'reviews', 'coupons', 'seo', 'content', 'addresses']);

const salesZip = groupZip('kbb-export-orders.zip',
  ['orders.csv', 'order_items.csv'], 'sales',
  ['catalogue', 'customers', 'reviews', 'coupons', 'seo', 'content', 'addresses']);

// The one that must be refused, in the browser and not only in the suite.
const hostile = join(work, 'hostile.zip');
execFileSync('python3', ['-c', `
import zipfile, sys
z = zipfile.ZipFile(${JSON.stringify(hostile)}, 'w')
z.writestr('../../../../products.csv', 'id,name\\n1,escaped\\n')
z.close()
`]);

/* ── the browser ──────────────────────────────────────────────────────────── */

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 1440, height: 1100 } });
const p = await ctx.newPage();
p.on('console', m => { if (m.type() === 'error') console.log('  console error:', m.text()); });
p.on('pageerror', e => console.log('  page error:', e.message));

const shot = async (name, opts = {}) => {
  await p.screenshot({ path: `${OUT}/${name}.png`, ...opts });
  console.log('  shot:', name);
};

await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
await p.fill('input[type="email"]', 'gm@example.test');
await p.fill('input[type="password"]', 'secret-secret');
await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);
console.log('signed in at:', p.url());

// Reached the way the owner reaches it: the Store group in the sidebar, then
// the "Store Import / Export" row. Not by URL, so a row that stopped
// registering itself would fail here.
await p.locator('#nav .nav-group[data-sec="Store"] .nav-gh').click();
await p.waitForTimeout(300);
await p.locator('#nav [data-go="import"]').click();
await p.waitForSelector('#impDrop', { timeout: 15000 });
await p.waitForTimeout(800);
await shot('01-import-screen-empty');

/* ── 1 · the catalogue zip, chosen exactly as the owner chooses it ────────── */

await p.setInputFiles('#impFileInput', catalogueZip);
await p.waitForTimeout(2500);
await shot('02-catalogue-zip-unpacked');

const afterOne = await p.evaluate(async () => (await fetch('/admin-api/import/status')).json());
console.log('after catalogue.zip — files in manifest:', Object.keys(afterOne.manifest.files));
console.log('  groups selected:', afterOne.manifest.groups.selected);
console.log('  whole-export total:', afterOne.overall.total, 'over', afterOne.overall.files, 'files');

/* ── 2 · the second group, which must ADD to the first ───────────────────── */

await p.setInputFiles('#impFileInput', salesZip);
await p.waitForTimeout(2500);
await shot('03-second-group-zip-added');

const afterTwo = await p.evaluate(async () => (await fetch('/admin-api/import/status')).json());
console.log('after orders.zip — files in manifest:', Object.keys(afterTwo.manifest.files));
console.log('  groups selected:', afterTwo.manifest.groups.selected);
console.log('  groups skipped:', afterTwo.manifest.groups.skipped);
console.log('  the operator\'s unverified claim:', JSON.stringify(afterTwo.manifest.groups.assumed_already_imported));
console.log('  whole-export total:', afterTwo.overall.total, 'over', afterTwo.overall.files, 'files');
console.log('  denominators:', afterTwo.entities.filter(e => e.present)
  .map(e => `${e.entity}=${e.denominator}/${e.denominator_source}`).join(' '));

/* ── 3 · a hostile zip, refused on the same screen ───────────────────────── */

await p.setInputFiles('#impFileInput', hostile);
await p.waitForTimeout(2000);
await p.waitForSelector('.impbanner.bad', { timeout: 10000 });
await shot('04-hostile-zip-refused');
console.log('refusal shown on screen:', (await p.locator('.impbanner.bad').first().innerText()).replace(/\s+/g, ' ').slice(0, 220));

// And it was refused rather than half-applied: the five files from the two
// good zips are still exactly what is here.
const afterHostile = await p.evaluate(async () => (await fetch('/admin-api/import/status')).json());
console.log('files still present:', afterHostile.entities.filter(e => e.present).map(e => e.entity).join(' '));

/* ── 4 · and the import runs out of the zips ──────────────────────────────── */

await p.locator('button', { hasText: /Import/i }).last().scrollIntoViewIfNeeded();
await shot('05-ready-to-import');

const report = await p.evaluate(async () => {
  // The screen's own CSRF header. Without it every POST is a 419, which is
  // the guard doing its job and not something to route around.
  const token = decodeURIComponent(
    (document.cookie.split('; ').find(c => c.indexOf('XSRF-TOKEN=') === 0) || '').slice('XSRF-TOKEN='.length)
  );

  const post = async (u, b) => (await fetch(u, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': token },
    body: JSON.stringify(b),
  })).json();

  await post('/admin-api/import/start', { mode: 'live', adopt_by_slug: true });

  let last = null;

  for (let i = 0; i < 80; i++) {
    last = await post('/admin-api/import/step', { rows: 500 });

    if ((last.status?.run?.status ?? '') !== 'running') { break; }
  }

  return last.status;
});

console.log('run:', report.run.status);
console.log('per-entity:', report.entities.filter(e => e.present)
  .map(e => `${e.entity} ${e.percent}% (${e.denominator_source})`).join(' · '));

await p.reload({ waitUntil: 'networkidle' });
await p.locator('#nav .nav-group[data-sec="Store"] .nav-gh').click();
await p.waitForTimeout(300);
await p.locator('#nav [data-go="import"]').click();
await p.waitForSelector('#impDrop', { timeout: 15000 });
await p.waitForTimeout(1800);
await shot('06-imported-from-the-zips', { fullPage: true });

await b.close();
console.log('done');
