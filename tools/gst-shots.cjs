// Lane GST evidence: the free-gifts strip in Chromium, every style at 390 and
// 1280, the admin screen at 1280 and 390, and the numbers CLAUDE.md asks for.
//   BASE=http://127.0.0.1:12200 node tools/gst-shots.cjs
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:12200';
const OUT = path.join(__dirname, '..', 'docs', 'lane-gst-shots');
fs.mkdirSync(OUT, { recursive: true });
const numbers = { styles: {} };

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
}

async function openScreen(page) {
  await page.evaluate(() => window.go('giftstrip'));
  await page.waitForSelector('[data-gsa] [data-gsa-save]', { timeout: 20000 });
  await page.waitForTimeout(900);
}

async function shop(browser, width, url) {
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, deviceScaleFactor: width < 600 ? 2 : 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  const res = await page.goto(BASE + url, { waitUntil: 'networkidle' });
  const html = await res.text();
  await page.evaluate(async () => {
    const imgs = [...document.querySelectorAll('.kgs-sec img')];
    imgs.forEach((i) => { i.loading = 'eager'; });
    await Promise.all(imgs.map((i) => i.complete ? 0 : new Promise((r) => { i.onload = i.onerror = r; })));
  });
  await page.waitForTimeout(400);
  return { ctx, page, errors, html };
}

async function measure(page) {
  return page.evaluate(() => {
    const sec = document.querySelector('.kgs-sec');
    const box = sec && sec.querySelector('.kgs');
    const link = sec && sec.querySelector('.kgs-link');
    let hit = null;
    if (link) {
      link.scrollIntoView({ block: 'center' });
      const r = link.getBoundingClientRect();
      const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      hit = !!el && (el === link || link.contains(el));
    }
    const imgs = sec ? [...sec.querySelectorAll('.kgs-p')].map((p) => Math.round(p.getBoundingClientRect().height)) : [];
    return {
      shown: !!sec && getComputedStyle(sec).display !== 'none',
      section_h: sec ? Math.round(sec.getBoundingClientRect().height) : 0,
      strip_h: box ? Math.round(box.getBoundingClientRect().height) : 0,
      product_heights_px: imgs,
      icons: sec ? sec.querySelectorAll('.kgs-gi').length : 0,
      h_font: sec ? getComputedStyle(sec.querySelector('.kgs-h')).fontSize : null,
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      link_hit_first_try: hit,
    };
  });
}

async function shotStrip(page, file) {
  // For the picture only: the shop's WhatsApp welcome bubble floats over the
  // middle of a phone screen; it is hidden so the strip itself can be seen.
  await page.addStyleTag({ content: '.kbw{display:none!important}' });
  await page.evaluate(() => document.querySelector('.kgs-sec').scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(350);
  const b = await page.evaluate(() => { const r = document.querySelector('.kgs-sec').getBoundingClientRect(); return { y: r.top, h: r.height }; });
  const vp = page.viewportSize();
  await page.screenshot({ path: path.join(OUT, file), clip: { x: 0, y: Math.max(0, b.y - 12), width: vp.width, height: Math.round(b.h + 24) } });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--disable-dev-shm-usage', '--disable-gpu'] });

  // BEFORE: the strip is off, as the package ships it.
  for (const w of [390, 1280]) {
    const s = await shop(browser, w, '/');
    numbers['off_' + w] = { html_bytes: Buffer.byteLength(s.html), has_kgs: s.html.includes('kgs-'), scrollWidth: await s.page.evaluate(() => document.documentElement.scrollWidth), console_errors: s.errors };
    await s.ctx.close();
  }

  // THE ADMIN: open, prepare cut-outs, switch on, save.
  const actx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const admin = await actx.newPage();
  const adminErrors = [];
  admin.on('pageerror', (e) => adminErrors.push(String(e)));
  admin.on('console', (m) => { if (m.type() === 'error') adminErrors.push(m.text()); });
  await signIn(admin);
  await openScreen(admin);
  await admin.setViewportSize({ width: 1280, height: 2700 });
  await admin.waitForTimeout(700);
  await admin.screenshot({ path: path.join(OUT, 'admin-1280-before-cutouts.png') });
  await admin.setViewportSize({ width: 1280, height: 900 });
  const t0 = Date.now();
  await admin.click('[data-gsa-prep]');
  await admin.waitForFunction(() => /6 of 6 ready/.test((document.querySelector('[data-gsa-prog]') || {}).textContent || ''), null, { timeout: 120000 });
  numbers.cutouts_ms_for_six = Date.now() - t0;
  await admin.check('[data-gsa-b="on"]');
  await admin.click('[data-gsa-save]');
  await admin.waitForFunction(() => (document.querySelector('[data-gsa-state]') || {}).textContent === 'Saved', null, { timeout: 20000 });
  await admin.waitForTimeout(900);
  // The console scrolls inside its own panel, so a tall window shows the whole screen.
  await admin.setViewportSize({ width: 1280, height: 2700 });
  await admin.waitForTimeout(900);
  await admin.screenshot({ path: path.join(OUT, 'admin-1280.png') });
  await admin.setViewportSize({ width: 390, height: 4600 });
  await admin.waitForTimeout(900);
  await admin.screenshot({ path: path.join(OUT, 'admin-390.png') });
  await admin.setViewportSize({ width: 1280, height: 900 });

  // Typing redraws the preview with no request.
  let reqs = 0;
  admin.on('request', (r) => { if (r.url().includes('/admin-api/')) reqs++; });
  await admin.fill('[data-gsa-t="h_en"]', 'FREE GIFTS!');
  await admin.waitForTimeout(500);
  numbers.admin_requests_while_typing = reqs;
  numbers.admin_preview_redrawn = await admin.evaluate(() => [...document.querySelectorAll('iframe[data-gsa-frame="main"]')].every((f) => (f.srcdoc || '').includes('FREE GIFTS!')));
  await admin.fill('[data-gsa-t="h_en"]', 'FREE GIFTS');
  await admin.waitForTimeout(300);

  // EVERY STYLE, picked from the list and saved, then the shop at 390 and 1280.
  for (const st of ['S1', 'S2', 'S3', 'S4', 'S5']) {
    await admin.click('[data-gsa-style="' + st + '"]');
    await admin.click('[data-gsa-save]');
    await admin.waitForFunction(() => (document.querySelector('[data-gsa-state]') || {}).textContent === 'Saved', null, { timeout: 20000 });
    numbers.styles[st] = {};
    for (const w of [390, 1280]) {
      const s = await shop(browser, w, '/');
      const m = await measure(s.page);
      m.console_errors = s.errors;
      m.html_bytes = Buffer.byteLength(s.html);
      numbers.styles[st][w] = m;
      await shotStrip(s.page, `${st}-${w}.png`);
      if (st === 'S1') {
        await s.page.evaluate(() => window.scrollTo(0, 0));
        await s.page.waitForTimeout(200);
        await s.page.screenshot({ path: path.join(OUT, `S1-${w}-page-top.png`) });
        // The link answers a real click and navigates.
        await s.page.evaluate(() => document.querySelector('.kgs-link').scrollIntoView({ block: 'center' }));
        await Promise.all([s.page.waitForNavigation({ timeout: 15000 }).catch(() => null), s.page.click('.kgs-link')]);
        numbers['link_click_' + w] = s.page.url();
      }
      await s.ctx.close();
    }
  }

  // Arabic, right to left (S1).
  await admin.click('[data-gsa-style="S1"]');
  await admin.click('[data-gsa-save]');
  await admin.waitForFunction(() => (document.querySelector('[data-gsa-state]') || {}).textContent === 'Saved', null, { timeout: 20000 });
  for (const w of [390, 1280]) {
    const s = await shop(browser, w, '/ar/');
    numbers['ar_' + w] = await measure(s.page);
    numbers['ar_' + w].console_errors = s.errors;
    await shotStrip(s.page, `S1-ar-${w}.png`);
    await s.ctx.close();
  }

  numbers.admin_console_errors = adminErrors;
  fs.writeFileSync(path.join(OUT, 'numbers.json'), JSON.stringify(numbers, null, 1));
  console.log(JSON.stringify(numbers, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
