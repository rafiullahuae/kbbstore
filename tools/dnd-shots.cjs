// Lane DND: Appearance -> Occasion banner -> Arrange products, driven in Chromium.
// At 1280 with a mouse and at 390 with REAL touch (CDP touch events -> pointer
// events of type "touch"): drag two products and resize one, on the computer
// frame and on the phone frame; nudge with the keyboard and undo it; Save.
// Then the shop homepage at 1280 and 390, before and after.
//   node tools/dnd-shots.cjs http://127.0.0.1:11930
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const BASE = process.argv[2];
const OUT = path.join(__dirname, '..', 'docs', 'lane-dnd-shots');
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const report = {};

async function shopShot(browser, tag) {
  const out = {};
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: width < 700 ? 844 : 800 }, isMobile: width < 700, hasTouch: width < 700, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    const res = await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const html = await res.text();
    await page.addStyleTag({ content: '.ohb-p{animation:none!important;transform:rotate(var(--r,0deg))!important}' });
    await page.waitForTimeout(500);
    const m = await page.evaluate(() => {
      const st = document.querySelector('.ohb') && document.querySelector('.ohb').previousElementSibling;
      const b = document.querySelector('.ohb').getBoundingClientRect();
      return {
        styleBytes: st ? st.textContent.length : 0,
        scrollWidth: document.documentElement.scrollWidth,
        scripts: document.querySelectorAll('script').length,
        banner: { w: Math.round(b.width), h: Math.round(b.height) },
        products: [...document.querySelectorAll('.ohb-p')].map((p) => {
          const r = p.querySelector('img').getBoundingClientRect();
          return { cls: p.className, cx: +(((r.left + r.right) / 2 - b.left) / b.width * 100).toFixed(1), bottom: +((r.bottom - b.top) / b.height * 100).toFixed(1), h: +(r.height / b.height * 100).toFixed(1) };
        }),
      };
    });
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(OUT, `shop-${width}-${tag}.jpg`), type: 'jpeg', quality: 80 });
    out[width] = { htmlBytes: html.length, ...m, errors };
    await ctx.close();
  }
  return out;
}

async function login(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.evaluate(() => window.go('occbanner'));
  await page.waitForSelector('[data-screen="occbanner"] .obx-card', { timeout: 15000 });
  await page.waitForTimeout(1000);
}

// A point on the element that a real click would hit (handles can overlap: the
// one on top wins, exactly as for the owner), scanning from the centre out.
const center = (page, sel) => page.evaluate((q) => {
  const el = document.querySelector(q), r = el.getBoundingClientRect();
  const pts = [[0.5, 0.5], [0.5, 0.3], [0.5, 0.7], [0.3, 0.5], [0.7, 0.5], [0.3, 0.3], [0.7, 0.3], [0.3, 0.7], [0.7, 0.7], [0.5, 0.15], [0.5, 0.85], [0.15, 0.5], [0.85, 0.5]];
  for (const [fx, fy] of pts) {
    const x = r.left + r.width * fx, y = r.top + r.height * fy, hit = document.elementFromPoint(x, y);
    if (hit && (hit === el || el.contains(hit))) return { x, y, ok: true };
  }
  return { x: r.left + r.width / 2, y: r.top + r.height / 2, ok: false };
}, sel);
const label = (page, dev, n) => page.evaluate(([d, k]) => document.querySelector(`.obx-hd[data-dev="${d}"][data-n="${k}"]`).getAttribute('aria-label'), [dev, n]);

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  report.shopBefore = await shopShot(browser, 'before');

  for (const width of [1280, 390]) {
    const touch = width < 700;
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, isMobile: touch, hasTouch: touch });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    let api = 0;
    page.on('request', (r) => { if (r.url().includes('/admin-api/')) api++; });
    const cdp = touch ? await ctx.newCDPSession(page) : null;
    const types = [];
    await login(page);
    await page.evaluate(() => { window.__pt = []; document.addEventListener('pointerdown', (e) => window.__pt.push(e.pointerType), true); });

    async function drag(sel, dx, dy) {
      const a = await center(page, sel);
      if (touch) {
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: a.x, y: a.y }] });
        for (let i = 1; i <= 12; i++) {
          await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: a.x + dx * i / 12, y: a.y + dy * i / 12 }] });
          await page.waitForTimeout(16);
        }
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      } else {
        await page.mouse.move(a.x, a.y);
        await page.mouse.down();
        await page.mouse.move(a.x + dx, a.y + dy, { steps: 12 });
        await page.mouse.up();
      }
      await page.waitForTimeout(120);
    }
    async function tap(sel) {
      const a = await center(page, sel);
      if (touch) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: a.x, y: a.y }] }); await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); }
      else await page.mouse.click(a.x, a.y);
      await page.waitForTimeout(120);
    }

    await page.click('[data-a="arrange"]');
    await page.waitForTimeout(700);
    await page.evaluate(() => document.querySelector('.obx-pv').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(OUT, `admin-${width}-before.jpg`), type: 'jpeg', quality: 80 });

    const k = touch ? 0.5 : 1; // the frames are half the size under 760px
    const moves = [];
    // Computer frame: move place 3 left and down, place 5 right; resize place 2.
    const steps = [
      ['d', 3, 'move', -150 * k, 30 * k], ['d', 5, 'move', 70 * k, -40 * k], ['d', 2, 'size', 8 * k, -12 * k],
      ['m', 1, 'move', 40 * k, -40 * k], ['m', 4, 'move', -60 * k, -20 * k], ['m', 3, 'size', 18 * k, -18 * k],
    ];
    for (const [dev, n, kind, dx, dy] of steps) {
      const hd = `.obx-hd[data-dev="${dev}"][data-n="${n}"]`;
      const before = await label(page, dev, n);
      if (kind === 'size') { await tap(hd); await drag(`.obx-hk.r[data-dev="${dev}"][data-n="${n}"]`, dx, dy); } else await drag(hd, dx, dy);
      moves.push({ dev, n, kind, before, after: await label(page, dev, n) });
    }
    // Keyboard: Shift+ArrowUp on the phone's place 4, then Ctrl+Z puts it back.
    await page.focus('.obx-hd[data-dev="m"][data-n="4"]');
    const k0 = await label(page, 'm', 4);
    await page.keyboard.press('Shift+ArrowUp');
    const k1 = await label(page, 'm', 4);
    await page.keyboard.press('Control+z');
    const k2 = await label(page, 'm', 4);
    const panel = await page.evaluate(() => document.querySelector('[data-dnd-panel]').innerText.slice(0, 400));
    await page.evaluate(() => document.querySelector('.obx-pv').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(OUT, `admin-${width}-after.jpg`), type: 'jpeg', quality: 80 });
    const apiBeforeSave = api;
    await page.click('[data-a="save"]');
    await page.waitForTimeout(1200);
    const saved = await page.evaluate(() => fetch(location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/occasion-banner', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then((r) => r.json()).then((j) => j.config.pos));
    report['admin' + width] = {
      pointerTypes: [...new Set(await page.evaluate(() => window.__pt))],
      requestsWhileArranging: apiBeforeSave - 2, // the screen's own GET + the login page's
      moves, keyboard: { before: k0, shiftUp: k1, afterCtrlZ: k2 }, panel, saved,
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors,
    };
    await ctx.close();
  }

  report.shopAfter = await shopShot(browser, 'after');
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
