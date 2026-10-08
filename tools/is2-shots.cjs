/*
 * Lane IS2: drive Catalog -> Image SEO in Chromium against tools/is2-preview.sh
 * and photograph the owner's four states at 1280 and 390.
 *
 *   node tools/is2-shots.cjs http://127.0.0.1:10880 <outdir>
 *
 * 1. The owner's 7 products / 27 pictures: Start with no preview previews
 *    first, then asks; cancelled here, so the shop is untouched.
 * 2. Bulk Beauty: "Select all 1,234 results", two unticked on page 2, pages
 *    visited back and forth: the boxes and the bar must not move.
 * 3. "Rename files + ALT text": the combined preview (cancelled once to
 *    photograph it), then Start: the live progress bar mid-run, the step
 *    requests timed and weighed, then the finished summary.
 * Writes <outdir>/report.json; exits 1 on any failure.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const ORIGIN = process.argv[2] || 'http://127.0.0.1:10880';
const OUT = process.argv[3] || path.join(__dirname, '..', 'storage', 'is2-logs', 'shots');
fs.mkdirSync(OUT, { recursive: true });
const report = { steps: [], failures: [], measures: {} };
const fail = (m) => { report.failures.push(m); console.log('FAIL ' + m); };
const note = (m) => { report.steps.push(m); console.log(m); };

async function shot(page, name) {
  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
    await page.waitForTimeout(150);
    const m = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
      contentOverflow: (() => { const c = document.getElementById('content'); return c ? c.scrollWidth - c.clientWidth : 0; })() }));
    report.measures[name + '@' + w] = m;
    if (m.contentOverflow > 1 || m.scrollWidth > m.clientWidth + 1) fail(`${name}@${w}: horizontal overflow ${JSON.stringify(m)}`);
    const tall = await page.evaluate(() => { const c = document.getElementById('content'); return c ? c.scrollHeight + (c.getBoundingClientRect().top || 0) + 40 : 0; });
    const h = w === 390 ? 844 : 900;
    if (tall > h) await page.setViewportSize({ width: w, height: Math.ceil(Math.min(tall, 5000)) });
    await page.screenshot({ path: path.join(OUT, `${w}-${name}.png`) });
  }
  await page.setViewportSize({ width: 1280, height: 900 });
}

async function search(page, fields) {
  if ('q' in fields) await page.fill('#isx-q', fields.q);
  if ('brand' in fields) await page.selectOption('#isx-brand', String(fields.brand));
  await Promise.all([page.waitForResponse((r) => r.url().includes('/image-seo/find')), page.click('[data-isx-form=find] button[type=submit]')]);
  await page.waitForFunction(() => { const b = document.querySelector('[data-isx-form=find] button[type=submit]'); return b && b.textContent === 'Search'; });
}

/* Wait until the page the screen DRAWS is the one asked for, not merely until its answer arrived. */
async function toPage(page, button, n) {
  await page.click(`[data-isx="${button}"]`);
  await page.waitForFunction((k) => { const m = document.querySelector('.isx-pager span'); return m && m.textContent.startsWith('Page ' + k + ' of'); }, n);
}

const barText = (page) => page.evaluate(() => (document.querySelector('[data-isx-selbar] span') || {}).textContent || '');
const settled = (page) => page.waitForFunction(() => { const b = document.querySelector('[data-isx-selbar] span'); return b && !/counting/.test(b.textContent); }, null, { timeout: 15000 }).catch(async (e) => { console.log('UNSETTLED bar=', await page.evaluate(() => (document.querySelector('[data-isx-screen]') || {}).textContent.slice(0, 300))); throw e; });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  const api = [];
  page.on('response', async (r) => {
    if (!r.url().includes('/admin-api/image-seo')) return;
    let size = 0; try { size = (await r.body()).length; } catch (e) {}
    api.push({ t: Date.now(), path: new URL(r.url()).pathname.replace(/.*admin-api/, ''), status: r.status(), size });
    if (r.status() >= 300 || process.env.IS2_TRACE) console.log('API', r.status(), r.url().slice(-50), size);
  });
  let dialogs = [];
  let dialogAnswer = false;
  page.on('dialog', async (d) => { dialogs.push(d.message()); await (dialogAnswer ? d.accept() : d.dismiss()); });

  await page.goto(ORIGIN + '/admin/login');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(ORIGIN + '/admin?go=imageseo', { waitUntil: 'networkidle' });
  await page.evaluate(() => window.go('imageseo'));
  await page.waitForSelector('[data-isx-screen] .isx-tabs');
  await page.waitForFunction(() => !/Scoring the library/.test(document.querySelector('[data-isx-screen]').textContent), null, { timeout: 120000 });

  /* 1 ---------------------------------------------------------------- */
  for (const sku of ['MDC-PDRN-60', 'ANU-HL77', 'ANU-HL-CO', 'CRX-SN96', 'BOJ-RS50', 'IS2-GLOW']) {
    await search(page, { q: sku });
    const n = await page.$$eval('[data-isx="pick-product"]', (b) => b.length);
    await page.click('[data-isx="page-all"]');
    note(`ticked ${n} for ${sku}`);
  }
  await settled(page);
  const bar7 = await barText(page);
  note('bar: ' + bar7);
  if (!/7 products selected · 27 pictures/.test(bar7)) fail('owner selection bar: ' + bar7);
  await page.click('[data-isx-selbar] [data-isx="tab-rename"]');
  const start0 = await page.$eval('[data-isx="start"]', (b) => ({ text: b.textContent, disabled: b.disabled }));
  const why0 = await page.$eval('[data-isx-why]', (e) => e.textContent).catch(() => '');
  note('Start before any preview: ' + JSON.stringify(start0) + ' why=' + why0);
  if (start0.disabled) fail('Start is still greyed out before a preview');
  await shot(page, '01-rename-tab-7-products');
  dialogs = []; dialogAnswer = false;
  await page.click('[data-isx="start"]');
  await page.waitForFunction(() => /Start renaming|Nothing to rename/.test(document.querySelector('[data-isx="start"]').textContent), null, { timeout: 30000 });
  await page.waitForTimeout(300);
  note('Start pressed with no preview -> dialog: ' + JSON.stringify(dialogs));
  if (!dialogs.length || !/Rename 25 files in 7 products/.test(dialogs[0])) fail('Start did not preview then ask: ' + JSON.stringify(dialogs));
  await shot(page, '02-start-previewed-first');

  /* zero-rename reason: the two Anua products share a picture; select only the oil, its own picture
     already... use COSRX alone with shared off: still renames. Instead: the shared picture only. */
  await page.click('[data-isx-selbar] [data-isx="clear-sel"]');
  await page.click('[data-isx="tab-find"]');
  await search(page, { q: 'ANU-HL-CO' });
  const sharedRel = 'uploads/products/20261006-090001-shared.jpg';
  await page.check(`[data-isx="pick-image"][data-isx-rel="${sharedRel}"]`);
  await settled(page);
  note('bar (one shared picture): ' + await barText(page));
  await page.click('[data-isx-selbar] [data-isx="tab-rename"]');
  await page.click('[data-isx="preview"]');
  await page.waitForSelector('[data-isx-preview]');
  await page.waitForFunction(() => document.querySelector('[data-isx-why]') && /Nothing to rename/.test(document.querySelector('[data-isx-why]').textContent), null, { timeout: 20000 });
  const zero = await page.$eval('[data-isx-why]', (e) => e.textContent);
  const zeroBtn = await page.$eval('[data-isx="start"]', (b) => ({ text: b.textContent, disabled: b.disabled }));
  note('zero rename: ' + zero + ' button=' + JSON.stringify(zeroBtn));
  if (!/shared with other products/.test(zero)) fail('zero reason does not say why: ' + zero);
  await shot(page, '03-nothing-to-rename-says-why');

  /* 2 ---------------------------------------------------------------- */
  await page.click('[data-isx-selbar] [data-isx="clear-sel"]');
  await page.click('[data-isx="tab-find"]');
  const bulk = await page.$eval('#isx-brand', (s) => [...s.options].find((o) => o.textContent === 'Bulk Beauty').value);
  await search(page, { q: '', brand: bulk });
  await page.click('[data-isx="all-matches"]');
  await settled(page);
  note('bar after select all: ' + await barText(page));
  await toPage(page, 'next', 2);
  const p2 = await page.$$eval('[data-isx="pick-product"]', (b) => b.slice(0, 2).map((x) => x.getAttribute('data-isx-p')));
  for (const id of p2) await page.uncheck(`[data-isx="pick-product"][data-isx-p="${id}"]`);
  await settled(page);
  await toPage(page, 'prev', 1);
  const p1checked = await page.$$eval('[data-isx="pick-product"]', (b) => b.every((x) => x.checked));
  await toPage(page, 'next', 2);
  const p2state = await page.$$eval('[data-isx="pick-product"]', (b) => b.map((x) => x.checked));
  note(`page 1 all ticked=${p1checked}; page 2 after the round trip: ${p2state.slice(0, 4)}`);
  if (!p1checked || p2state[0] || p2state[1] || !p2state[2]) fail('paging changed the selection');
  const bar = await barText(page);
  note('bar: ' + bar);
  if (!/All 1,234 products selected \(2 excluded\) · 1,232 to work on · 2,464 pictures/.test(bar)) fail('bar: ' + bar);
  await shot(page, '04-all-1234-selected-2-excluded');

  /* 3 ---------------------------------------------------------------- */
  await page.click('[data-isx-selbar] [data-isx="tab-rename"]');
  dialogs = []; dialogAnswer = false;
  const t0 = Date.now();
  await page.click('[data-isx="combo"]');
  await page.waitForTimeout(400);
  await shot(page, '05-combined-preview-checking');
  await page.waitForFunction(() => /^Start: rename/.test(document.querySelector('[data-isx="combo"]').textContent), null, { timeout: 180000 });
  await page.waitForTimeout(300);
  note(`combined preview of 1,232 products took ${Date.now() - t0} ms; dialog: ${JSON.stringify(dialogs)}`);
  if (!dialogs.length || !/Rename 2,464 files and write 2,464 ALT texts in 1,232 products/.test(dialogs[0])) fail('combined dialog: ' + JSON.stringify(dialogs));
  await shot(page, '06-combined-preview');

  dialogAnswer = true; dialogs = [];
  const runStart = Date.now();
  await page.click('[data-isx="combo"]');
  await page.waitForSelector('[data-isx-run]');
  await page.waitForFunction(() => { const c = document.querySelector('[data-isx-counts]'); return c && /^[1-9][\d,]* \//.test(c.textContent) && !/^1,232 \//.test(c.textContent); }, null, { timeout: 60000 });
  await page.waitForTimeout(2500);
  note('mid-run: ' + await page.$eval('[data-isx-counts]', (e) => e.textContent) + ' | ' + await page.$eval('[data-isx-time]', (e) => e.textContent));
  await shot(page, '07-live-progress-mid-run');

  // Reload mid-run: the screen picks the run up and carries on by itself.
  const pos = async () => +((await page.$eval('[data-isx-counts]', (e) => e.textContent)).split(' /')[0].replace(/,/g, ''));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.evaluate(() => window.go('imageseo'));
  await page.waitForSelector('[data-isx-run]', { timeout: 20000 });
  const r1 = await pos();
  await page.waitForTimeout(4000);
  const r2 = await pos();
  note(`after a reload the run carried on by itself: ${r1} -> ${r2}`);
  if (!(r2 > r1)) fail('the run did not carry on after a reload');

  // Stop, then Resume.
  await page.click('[data-isx="stop"]');
  await page.waitForFunction(() => { const r = document.querySelector('[data-isx-run] .isx-meta'); return r && r.textContent === 'paused'; }, null, { timeout: 20000 });
  const s1 = await pos();
  await page.waitForTimeout(3000);
  const s2 = await pos();
  note(`stopped at ${s1}, still ${s2} three seconds later`);
  if (s1 !== s2) fail('a stopped run kept going');
  await shot(page, '07b-stopped');
  await page.click('[data-isx-run] [data-isx="resume"]');
  await page.waitForFunction(() => { const r = document.querySelector('[data-isx-run] .isx-meta'); return r && r.textContent === 'running'; }, null, { timeout: 20000 });
  note('resumed');

  await page.waitForFunction(() => { const r = document.querySelector('[data-isx-run] .isx-meta'); return r && r.textContent === 'finished'; }, null, { timeout: 900000, polling: 1000 });
  await page.waitForLoadState('networkidle');
  const runMs = Date.now() - runStart;
  const fin = await page.$eval('[data-isx-counts]', (e) => e.textContent);
  note(`finished in ${runMs} ms: ${fin} | ${await page.$eval('[data-isx-time]', (e) => e.textContent)}`);
  if (!/1,232 \/ 1,232 products · 2,464 files renamed · 2,464 ALT texts written · 0 skipped · 0 failed/.test(fin)) fail('finished counts: ' + fin);
  await shot(page, '08-finished-summary');

  const steps = api.filter((a) => a.path === '/image-seo/step');
  const gaps = steps.slice(1).map((s, i) => s.t - steps[i].t);
  report.measures.steps = { count: steps.length, maxBytes: Math.max(...steps.map((s) => s.size)), medianGapMs: gaps.sort((a, b) => a - b)[Math.floor(gaps.length / 2)], maxGapMs: Math.max(...gaps) };
  note('step requests: ' + JSON.stringify(report.measures.steps));

  // Idle: no request for 6 s once the run is over.
  const before = api.length;
  await page.waitForTimeout(6000);
  report.measures.idleRequests = api.length - before;
  note('requests while idle for 6 s: ' + report.measures.idleRequests);
  if (report.measures.idleRequests) fail('requests while idle');

  // Undo the combined run from its card: files and ALT text both go back.
  dialogAnswer = true;
  await page.click('[data-isx-run] [data-isx="undo"]');
  await page.waitForFunction(() => { const r = document.querySelector('[data-isx-run] b'); const m = document.querySelector('[data-isx-run] .isx-meta'); return r && /Undo of run/.test(r.textContent) && m && m.textContent === 'finished'; }, null, { timeout: 900000, polling: 1000 });
  const undone = await page.$eval('[data-isx-counts]', (e) => e.textContent);
  note('undo: ' + undone);
  if (!/1,232 \/ 1,232 products · 2,464 files restored · 2,464 ALT texts restored · 0 skipped · 0 failed/.test(undone)) fail('undo counts: ' + undone);
  await page.click('[data-isx="tab-history"]');
  await shot(page, '09-history-after-undo');

  report.errors = errors;
  if (report.errors.length) fail('console errors: ' + report.errors.join(' | '));
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  await browser.close();
  process.exit(report.failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
