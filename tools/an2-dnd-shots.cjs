/*
 * Lane AN2 — the Analytics board's blocks moved by drag and drop, photographed:
 * a drag in progress (1280), the reordered board after a reload (so the saved,
 * per-admin order is what is shown), and the board at 390.
 *
 *   BASE=http://127.0.0.1:10480 node tools/an2-dnd-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:10480';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-an2-shots';
const say = (s) => process.stdout.write(s + '\n');

async function board(page) {
  await page.goto(BASE + '/admin#site-analytics', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  await page.evaluate(() => window.go('site-analytics'));
  await page.waitForSelector('[data-an="b-funnel"] .card', { timeout: 15000 });
  await page.waitForTimeout(700);
}
const order = (page) => page.evaluate(() => [...document.querySelectorAll('[data-an-grid] > [data-blk]')].map((b) => b.getAttribute('data-blk')).join(' '));

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const puts = [];
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('request', (r) => { if (r.url().includes('/site-analytics/layout') && r.method() === 'PUT') puts.push(Date.now()); });
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await board(page);
  await page.evaluate(() => fetch('/admin-api/site-analytics/layout', { method: 'DELETE', headers: { 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''), Accept: 'application/json' } }));
  await board(page);
  say('before: ' + await order(page));

  // Drag "Checkout funnel" up onto "Happening now".
  const funnel = await page.$('[data-blk="funnel"]');
  await funnel.scrollIntoViewIfNeeded();
  await funnel.hover();
  const h = await (await page.$('[data-blk="funnel"] > [data-an-hdl]')).boundingBox();
  await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
  await page.mouse.down();
  const feed = await page.$('[data-blk="pagesnow"]');
  await feed.scrollIntoViewIfNeeded();
  const f = await feed.boundingBox();
  for (let i = 1; i <= 12; i++) {
    await page.mouse.move(f.x + f.width / 2, f.y + f.height / 2 + (12 - i) * 4, { steps: 2 });
  }
  await page.waitForTimeout(250);
  say('mid-drag: ' + await order(page) + '; PUTs while dragging: ' + puts.length);
  await page.screenshot({ path: `${OUT}/admin-dnd-dragging-1280.png` });
  await page.mouse.up();
  await page.waitForTimeout(1200);
  say('after drop: PUTs ' + puts.length);

  // Keyboard: move "Top pages" up once.
  await page.focus('[data-blk="pages"] > [data-an-hdl]');
  await page.keyboard.press('ArrowUp');
  await page.waitForTimeout(900);
  say('announced: ' + await page.evaluate(() => document.querySelector('[data-an-say]').textContent));

  await page.reload({ waitUntil: 'networkidle' });
  await board(page);
  say('after reload (saved): ' + await order(page));
  await page.screenshot({ path: `${OUT}/admin-dnd-reordered-1280.png` });
  say('errors: ' + (errors.join(' | ') || 'none'));
  await ctx.close();

  const m = await browser.newContext({ viewport: { width: 390, height: 900 }, isMobile: true, hasTouch: true });
  const p2 = await m.newPage();
  await p2.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await p2.fill('input[name=email]', 'owner@preview.test');
  await p2.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p2.waitForNavigation({ waitUntil: 'networkidle' }), p2.click('button[type=submit]')]);
  await board(p2);
  say('390: ' + await order(p2) + '; scrollWidth ' + await p2.evaluate(() => document.documentElement.scrollWidth));
  await p2.screenshot({ path: `${OUT}/admin-dnd-390.png` });
  await m.close();
  await browser.close();
})();
