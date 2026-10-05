/*
 * Lane IC — "extend the background colour to the top end": both installed
 * apps at 390, before and after, plus the Customise control at 390 and 1280.
 *
 *   sh tools/mac-preview.sh 10640
 *   IC_BASE=http://127.0.0.1:10640 IC_APP=/<owner app path> node tools/ic-statusbar-shots.cjs
 *
 * ▲ THE STATUS BAR IN THESE PICTURES IS A MOCK, LABELLED ON EVERY SHOT.
 *   Chromium cannot draw a phone's clock, camera cutout or safe-area inset.
 *   So: the inset is emulated by overriding the app's own --sat variable
 *   (what env(safe-area-inset-top) gives on a phone, 47 px on an iPhone with
 *   a notch / Dynamic Island), and the bar is a drawn overlay. What is REAL
 *   is everything under it: the app's colours, the header's position (read
 *   with getBoundingClientRect by this harness, never by the app), and the
 *   colour the bar is given (read from the served manifest / theme-color).
 *
 * Writes docs/ic-shots/statusbar-*.png and docs/ic-shots/STATUSBAR.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.IC_BASE || 'http://127.0.0.1:10640';
const APP = process.env.IC_APP;
const OUT = path.join(__dirname, '..', 'docs', 'ic-shots');
const CHROME = process.env.PW_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const INSET = 47;
const M = {};
fs.mkdirSync(OUT, { recursive: true });

/* The mock bar: a clock, signal and battery, and the camera, over `bg`. */
function mockBar(page, { bg, ink, label, mode }) {
  return page.evaluate(({ bg, ink, label, mode, INSET }) => {
    const bar = document.createElement('div');
    bar.setAttribute('data-mock', '');
    bar.style.cssText = `position:fixed;z-index:2147483647;left:0;right:0;top:0;height:${INSET}px;background:${bg};color:${ink};font:600 15px/1 -apple-system,system-ui,sans-serif;display:flex;align-items:center;justify-content:space-between;padding:0 26px;pointer-events:none`;
    bar.innerHTML = (mode === 'blank' ? '<span></span><span></span>' : '<span>9:41</span><span>▂▄▆ ▭</span>')
      + `<i style="position:absolute;left:50%;top:11px;width:${mode === 'blank' ? 14 : 120}px;height:${mode === 'blank' ? 14 : 34}px;margin-left:-${mode === 'blank' ? 7 : 60}px;border-radius:20px;background:#000"></i>`;
    const tag = document.createElement('div');
    tag.setAttribute('data-mock', '');
    tag.style.cssText = 'position:fixed;z-index:2147483647;right:6px;bottom:6px;background:#111;color:#fff;font:600 11px/1.3 system-ui;padding:5px 8px;border-radius:6px;max-width:250px;opacity:.9';
    tag.textContent = 'MOCK status bar (Chromium cannot draw one). ' + label;
    document.body.append(bar, tag);
  }, { bg, ink, label, mode, INSET });
}

async function phone(browser) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, bypassCSP: true });
  await ctx.addInitScript(() => { try { localStorage.setItem('oa.a2', '1'); } catch (e) {} });
  return { ctx, page: await ctx.newPage() };
}

async function signIn(page) {
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'ic-statusbar');
  await page.click('[data-enrol] button[type=submit]');
  try { await page.waitForFunction(() => !!document.querySelector('.app .hero'), null, { timeout: 20000 }); } catch (e) { await page.screenshot({ path: path.join(OUT, 'debug-signin.png') }); throw e; }
  await page.waitForTimeout(1500);
}

/* The real numbers: where the header is, and the app's colour at the top edge. */
async function readTop(page) {
  return page.evaluate(() => {
    // The header's first line (the date over the store name), found by its text.
    const app = document.querySelector('.app');
    const top = [...app.querySelectorAll('*')].find((e) => e.children.length === 0 && /^(Mon|Tues|Wednes|Thurs|Fri|Satur|Sun)day, /.test(e.textContent.trim())).parentElement;
    return {
      sat: getComputedStyle(document.documentElement).getPropertyValue('--sat').trim(),
      header: top.tagName + '.' + String(top.className).slice(0, 30), headerTop: Math.round(top.getBoundingClientRect().top), headerHeight: Math.round(top.getBoundingClientRect().height),
      appPaddingTop: getComputedStyle(app).paddingTop, appBackground: getComputedStyle(app).backgroundImage.slice(0, 80),
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

async function setSat(page, px) {
  await page.evaluate((px) => {
    let s = document.getElementById('ic-sat');
    if (!s) { s = document.createElement('style'); s.id = 'ic-sat'; document.head.append(s); }
    s.textContent = `:root{--sat:${px}px !important}`;
  }, px);
  await page.waitForTimeout(200);
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });

  /* ---------------------------------------------------------- owner app */
  const { ctx, page } = await phone(browser);
  await signIn(page);
  const manifest = await (await page.request.get(BASE + APP + '/manifest.webmanifest')).json();
  const theme = await page.evaluate(() => ({
    themeColor: document.querySelector('meta[name=theme-color]').content,
    iosStyle: document.querySelector('meta[name=apple-mobile-web-app-status-bar-style]').content,
  }));
  M.ownerApp = { manifest: { display: manifest.display, display_override: manifest.display_override || null, theme_color: manifest.theme_color }, shell: theme };

  // BEFORE (Android, full screen): the cutout letterboxed in black, no clock;
  // the page starts below the band. Emulated by moving .app down by the band.
  await setSat(page, 0);
  await page.addStyleTag({ content: '.app{top:' + INSET + 'px !important}' });
  await mockBar(page, { bg: '#000', ink: '#000', mode: 'blank', label: 'BEFORE, Android full screen: the camera band letterboxed black, no clock (his screenshot).' });
  M.ownerBefore = await readTop(page);
  await page.screenshot({ path: path.join(OUT, 'statusbar-owner-before-android-390.png') });

  // AFTER (Android, standalone): the bar is theme_color, the page below it, inset 0.
  await page.reload({ waitUntil: 'networkidle' }); await page.waitForFunction(() => !!document.querySelector('.app .hero')); await page.waitForTimeout(1200);
  await setSat(page, 0);
  await page.addStyleTag({ content: '.app{top:' + INSET + 'px !important}' });
  await mockBar(page, { bg: manifest.theme_color, ink: '#1d1d1f', label: 'AFTER, Android: the bar is the manifest theme_color ' + manifest.theme_color + ', clock shown.' });
  M.ownerAfterAndroid = await readTop(page);
  await page.screenshot({ path: path.join(OUT, 'statusbar-owner-after-android-390.png') });

  // AFTER (iPhone, black-translucent): the page runs under the bar; the inset
  // (47 px) pads the app, so the header sits exactly below it.
  await page.reload({ waitUntil: 'networkidle' }); await page.waitForFunction(() => !!document.querySelector('.app .hero')); await page.waitForTimeout(1200);
  M.ownerIphoneNoInset = await readTop(page);
  await setSat(page, INSET);
  await mockBar(page, { bg: 'transparent', ink: '#fff', label: 'AFTER, iPhone black-translucent: the app\'s own background under the clock (iOS draws the clock white).' });
  M.ownerAfterIphone = await readTop(page);
  await page.screenshot({ path: path.join(OUT, 'statusbar-owner-after-iphone-390.png') });
  await ctx.close();

  /* ---------------------------------------------------------- the shop */
  const shop = await phone(browser);
  await shop.page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const sm = await (await shop.page.request.get(BASE + '/manifest.webmanifest')).json();
  const meta = await shop.page.evaluate(() => { const m = document.querySelector('meta[name=theme-color]'); return m ? m.outerHTML : null; });
  const headerTop = await shop.page.evaluate(() => { const e = document.elementFromPoint(195, 2); const h = e.closest('header, .kfb, [class*=flag]') || e; return { tag: h.tagName, cls: String(h.className).slice(0, 40), bg: getComputedStyle(h).backgroundColor, top: Math.round(h.getBoundingClientRect().top) }; });
  const TAG = process.env.IC_SHOP_TAG || 'default';
  const S = M['shop-' + TAG] = { manifestTheme: sm.theme_color, meta, topElement: headerTop, label: process.env.IC_SHOP_LABEL || 'default header' };
  await shop.page.addStyleTag({ content: 'html{margin-top:' + INSET + 'px !important}' });
  await mockBar(shop.page, { bg: sm.theme_color, ink: '#1d1d1f', label: 'Shop app, Android standalone: the bar is theme_color ' + sm.theme_color + ' (the colour at the top of the page).' });
  await shop.page.screenshot({ path: path.join(OUT, 'statusbar-shop-' + TAG + '-390.png') });
  S.scrollWidth = await shop.page.evaluate(() => document.documentElement.scrollWidth);
  await shop.ctx.close();

  /* ---------------------------------------------------------- the control */
  for (const w of [1280, 390]) {
    const c = await browser.newContext({ viewport: { width: w, height: w < 600 ? 2400 : 1400 } });
    const p = await c.newPage();
    await p.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await p.fill('input[name=email]', 'owner@example.com');
    await p.fill('input[name=password]', 'preview-password');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
    await p.goto(BASE + '/admin?go=ownerapp', { waitUntil: 'networkidle' });
    await p.click('[data-oa-screen] [data-oac-tab="ui"]');
    await p.waitForSelector('select[data-k=statusbar]', { timeout: 15000 });
    const card = p.locator('.rl-card', { has: p.locator('select[data-k=statusbar]') });
    await card.scrollIntoViewIfNeeded();
    await card.screenshot({ path: path.join(OUT, 'statusbar-control-' + w + '.png') });
    M['control-' + w] = await p.evaluate(() => ({
      options: [...document.querySelectorAll('select[data-k=statusbar] option')].map((o) => o.textContent + (o.selected ? ' [selected]' : '')),
      scrollWidth: document.documentElement.scrollWidth,
    }));
    await c.close();
  }

  const file = path.join(OUT, 'STATUSBAR.json');
  const prev = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
  fs.writeFileSync(file, JSON.stringify(Object.assign(prev, M), null, 2));
  console.log(JSON.stringify(M, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
