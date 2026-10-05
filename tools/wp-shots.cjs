/*
 * Lane WP — the evidence. Content -> Media Library -> WebP images.
 *
 *   sh tools/wp-preview.sh 10140
 *   BASE=http://127.0.0.1:10140 node tools/wp-shots.cjs
 *
 * At 390 and 1280: the settings, the dry-run result, a converted upload in the
 * Media Library, and the panel after a real bulk run. Numbers are printed as
 * well as drawn (bytes before/after, scrollWidth), because rule 2 wants them.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10140';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/wp-shots';
const SAMPLE = process.env.SAMPLE || 'storage/wp-logs/sample-upload.jpg';
const numbers = {};

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);
}

const measure = (page) => page.evaluate(() => ({
  scrollWidth: document.documentElement.scrollWidth,
  clientWidth: document.documentElement.clientWidth,
  contentScrollWidth: (document.getElementById('content') || {}).scrollWidth,
  contentClientWidth: (document.getElementById('content') || {}).clientWidth,
}));

async function openWebp(page) {
  await page.evaluate(() => window.go('media'));
  await page.waitForSelector('#mlib-webp', { timeout: 15000 });
  await page.click('#mlib-webp');
  await page.waitForSelector('#wpx-save', { timeout: 15000 });
  await page.waitForTimeout(400);
}

async function shot(page, name, width) {
  const el = await page.$('#content');
  await el.screenshot({ path: `${OUT}/${name}-${width}.png` });
  numbers[`${name}-${width}`] = Object.assign(numbers[`${name}-${width}`] || {}, await measure(page));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const pages = {};

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: 3000 } });
    const page = await ctx.newPage();
    await signIn(page);
    pages[width] = page;

    // 1. The settings, as shipped.
    await openWebp(page);
    await shot(page, 'settings', width);

    // 2. The dry run.
    await page.click('#wpx-plan');
    await page.waitForFunction(() => /Dry run/.test(document.getElementById('wpx-root').innerText), null, { timeout: 60000 });
    await page.waitForTimeout(300);
    numbers[`dryrun-${width}`] = await page.evaluate(() => {
      const stats = {};
      document.querySelectorAll('#wpx-root .wpx-stat').forEach((s) => { stats[s.querySelector('span').innerText] = s.querySelector('b').innerText; });
      return stats;
    });
    await shot(page, 'dryrun', width);
  }

  // 3. A real upload through the one upload endpoint, then the library.
  const page = pages[1280];
  const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
  const res = await page.request.post(BASE + '/admin-api/media/upload', {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'application/json' },
    multipart: { folder: 'products', file: { name: 'Snail Serum 2400x3200.jpg', mimeType: 'image/jpeg', buffer: fs.readFileSync(SAMPLE) } },
  });
  const up = await res.json();
  numbers.upload = { status: res.status(), filename: up.filename, webp: up.webp, sized: up.sized };

  for (const width of [390, 1280]) {
    const p = pages[width];
    await p.evaluate(() => window.go('media'));
    await p.waitForSelector('#mlib-webp', { timeout: 15000 });
    await p.waitForTimeout(1500);
    await shot(p, 'library-upload', width);
    const tile = await p.$(`#content [data-mlib-id] img[src*="${up.filename}"], #content img[src*="${up.filename}"]`);
    if (tile) {
      await tile.click();
      await p.waitForTimeout(800);
      await p.screenshot({ path: `${OUT}/library-upload-detail-${width}.png` });
      numbers[`library-upload-detail-${width}`] = Object.assign(await measure(p), {
        detail: await p.evaluate(() => (document.querySelector('.mlib-modal, [class*=mlib-detail], [role=dialog]') || {}).innerText || ''),
      });
      await p.keyboard.press('Escape');
    }
  }

  // 4. A real bulk run, then the panel at both widths.
  await openWebp(page);
  await page.click('#wpx-run');
  await page.waitForFunction(() => !document.querySelector('#wpx-run') || !/Converting/.test(document.getElementById('wpx-run').innerText), null, { timeout: 120000 });
  await page.waitForFunction(() => /WebP/.test((document.querySelector('#wpx-root .wpx-table') || {}).innerText || ''), null, { timeout: 60000 });
  for (const width of [390, 1280]) {
    const p = pages[width];
    await openWebp(p);
    await p.waitForTimeout(600);
    numbers[`after-run-${width}`] = await p.evaluate(() => {
      const stats = {};
      document.querySelectorAll('#wpx-root .wpx-stat').forEach((s) => { stats[s.querySelector('span').innerText] = s.querySelector('b').innerText; });
      return stats;
    });
    await shot(p, 'after-run', width);
  }

  fs.writeFileSync(`${OUT}/numbers.json`, JSON.stringify(numbers, null, 2));
  console.log(JSON.stringify(numbers, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
