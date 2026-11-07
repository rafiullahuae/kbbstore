// Lane CUT evidence: the free-gifts strip with the automatic cut-outs (before)
// and the hand-made ones (after) at 390 and 1280, the admin rows with
// "Upload cut-out" / "Use automatic", and the numbers.
//   tools/gst-preview.sh 12340 && BASE=http://127.0.0.1:12340 node tools/cut-shots.cjs
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:12340';
const APP = path.join(__dirname, '..');
const OUT = path.join(APP, 'docs', 'lane-cut-shots');
const UPLOAD = process.env.UPLOAD || '';           // a transparent PNG to upload in the demo
fs.mkdirSync(OUT, { recursive: true });
const numbers = {};

async function call(page, method, url, body) {
  return page.evaluate(async ([method, url, body]) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': x }, body: body ? JSON.stringify(body) : undefined });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, [method, BASE + '/admin-api' + url, body]);
}

async function shop(browser, width, label) {
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.addStyleTag({ content: '.kbw{display:none!important}' });
  await page.evaluate(async () => {
    const imgs = [...document.querySelectorAll('.kgs-sec img')];
    imgs.forEach((i) => { i.loading = 'eager'; });
    await Promise.all(imgs.map((i) => i.complete ? 0 : new Promise((r) => { i.onload = i.onerror = r; })));
    document.querySelector('.kgs-sec').scrollIntoView({ block: 'center' });
  });
  await page.waitForTimeout(400);
  const m = await page.evaluate(() => {
    const sec = document.querySelector('.kgs-sec');
    const r = sec.getBoundingClientRect();
    return {
      y: r.top, h: r.height,
      imgs: [...sec.querySelectorAll('img')].map((i) => ({ src: i.currentSrc.replace(/^.*\/img-cache\/cut\//, ''), w: Math.round(i.getBoundingClientRect().width), h: Math.round(i.getBoundingClientRect().height) })),
      scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth,
    };
  });
  await page.screenshot({ path: path.join(OUT, `strip-${label}-${width}.png`), clip: { x: 0, y: Math.max(0, m.y - 10), width, height: Math.round(m.h + 20) } });
  numbers[`strip-${label}-${width}`] = { scrollWidth: m.scrollWidth, innerWidth: m.innerWidth, console_errors: errors.length, imgs: m.imgs };
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--disable-dev-shm-usage', '--disable-gpu'] });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });

  const s = await call(page, 'POST', '/gift-strip', { settings: { on: true, style: 'S2' } });
  numbers.save = s.status;
  const ids = (await call(page, 'GET', '/gift-strip')).json.live.map((p) => p.id);

  // BEFORE: what 2.60.649 did -- "Use automatic" on every gift
  for (const id of ids) numbers['auto-' + id] = (await call(page, 'POST', '/gift-strip/cutout/automatic', { id, white: false })).status;
  for (const w of [390, 1280]) await shop(browser, w, 'before-auto');

  // AFTER: the owner's choice put back (as if he never pressed Use automatic), then "Prepare cut-outs"
  for (const f of fs.readdirSync(path.join(APP, 'storage/app/cutouts/auto'))) fs.unlinkSync(path.join(APP, 'storage/app/cutouts/auto', f));
  for (const id of ids) {
    const r = await call(page, 'POST', '/gift-strip/cutout', { id });
    numbers['prepare-' + id] = [r.status, r.json && r.json.own];
  }
  for (const w of [390, 1280]) await shop(browser, w, 'after-handmade');

  // The admin rows: Appearance -> Free gifts strip
  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: 900 });
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.evaluate(() => window.go('giftstrip'));
    await page.waitForSelector('[data-gsa-upl]', { timeout: 20000 });
    await page.waitForTimeout(600);
    const row = page.locator('.gsa-row').first();
    await row.scrollIntoViewIfNeeded();
    const box = await page.locator('.gsa-row').nth(0).boundingBox();
    const last = await page.locator('.gsa-row').nth(2).boundingBox();
    await page.screenshot({ path: path.join(OUT, `admin-rows-${w}.png`), clip: { x: 0, y: Math.max(0, box.y - 8), width: w, height: Math.round(last.y + last.height - box.y + 16) } });
    numbers['admin-' + w] = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      upload_buttons: document.querySelectorAll('[data-gsa-upl]').length,
      auto_buttons: document.querySelectorAll('[data-gsa-auto]').length,
      handmade_labels: [...document.querySelectorAll('.gsa-ok')].filter((e) => e.textContent === 'Hand-made cut-out').length,
    }));
  }

  // The controls, clicked: Use automatic on the first row, then Upload cut-out on it
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.click('[data-gsa-auto]');
  await page.waitForFunction(() => !document.querySelector('.gsa-row [disabled]'), null, { timeout: 30000 });
  await page.waitForTimeout(300);
  numbers.after_use_automatic = await page.evaluate(() => document.querySelector('.gsa-row .gsa-ok').textContent);
  let b = await page.locator('.gsa-row').first().boundingBox();
  await page.screenshot({ path: path.join(OUT, 'admin-row-1-after-use-automatic.png'), clip: { x: 0, y: b.y - 60, width: 1280, height: b.height + 68 } });
  if (UPLOAD) {
    const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('[data-gsa-upl]')]);
    await chooser.setFiles(UPLOAD);
    await page.waitForFunction(() => document.querySelector('.gsa-row .gsa-ok') && document.querySelector('.gsa-row .gsa-ok').textContent === 'Hand-made cut-out', null, { timeout: 30000 });
    await page.waitForTimeout(500);
    numbers.after_upload = await page.evaluate(() => document.querySelector('.gsa-row .gsa-ok').textContent);
    b = await page.locator('.gsa-row').first().boundingBox();
    await page.screenshot({ path: path.join(OUT, 'admin-row-1-after-upload.png'), clip: { x: 0, y: b.y - 60, width: 1280, height: b.height + 68 } });
  }

  fs.writeFileSync(path.join(OUT, 'numbers.json'), JSON.stringify(numbers, null, 1) + '\n');
  console.log(JSON.stringify(numbers));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
