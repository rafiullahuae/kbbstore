/*
 * LANE MAC — the phone full-screen toggle, verified in Chromium.
 *
 *   KBB_BASE=http://127.0.0.1:10221 KBB_APP=/<secret> node tools/mac-fs-shots.cjs
 *
 * What headless Chromium CAN show: the first tap entering full screen
 * (document.fullscreenElement), the icon's glyph and label swapping on
 * fullscreenchange, the icon exiting, the "off" preference holding on the next
 * tap, and — by wrapping every layout-reading API before the page loads — that
 * the click -> fullscreenchange -> glyph swap path reads layout ZERO times
 * (no forced layout from the app's code). What it cannot show: a real phone's
 * system bars disappearing, which needs a device.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10221';
const APP = process.env.KBB_APP;
const OUT = path.join(__dirname, '..', 'docs', 'mac-shots');
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

const COUNT_LAYOUT_READS = () => {
  window.__reads = 0;
  const bump = () => { window.__reads++; };
  const wrap = (proto, names) => names.forEach((n) => {
    const d = Object.getOwnPropertyDescriptor(proto, n);
    if (!d) return;
    if (d.get) Object.defineProperty(proto, n, { get() { bump(); return d.get.call(this); }, configurable: true });
    else if (typeof d.value === 'function') proto[n] = function (...a) { bump(); return d.value.apply(this, a); };
  });
  wrap(Element.prototype, ['getBoundingClientRect', 'getClientRects', 'clientWidth', 'clientHeight', 'scrollWidth', 'scrollHeight', 'scrollTop', 'scrollLeft']);
  wrap(HTMLElement.prototype, ['offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft', 'offsetParent', 'innerText']);
  const gcs = window.getComputedStyle;
  window.getComputedStyle = function (...a) { bump(); return gcs.apply(this, a); };
};

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  const out = {};

  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1.5, isMobile: true, hasTouch: true, userAgent: ANDROID });
  await ctx.addInitScript(COUNT_LAYOUT_READS);
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Performance.enable');
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  const state = () => page.evaluate(() => ({ fs: !!document.fullscreenElement, cls: document.documentElement.className, label: document.querySelector('[data-fs]').getAttribute('aria-label') }));

  out.before = await state();
  await page.screenshot({ path: path.join(OUT, 'android-390--fs-1-browser-view.jpg'), type: 'jpeg', quality: 82 });

  await page.tap('input[name=email]');            // the first tap anywhere
  await page.waitForFunction(() => !!document.fullscreenElement, null, { timeout: 4000 }).catch(() => {});
  await page.waitForTimeout(200);
  out.afterFirstTap = await state();
  await page.screenshot({ path: path.join(OUT, 'android-390--fs-2-full-screen.jpg'), type: 'jpeg', quality: 82 });

  // The icon, measured: layout reads by the app between the click and the swap.
  const m0 = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((x) => [x.name, x.value]));
  await page.evaluate(() => { window.__reads = 0; });
  const t0 = Date.now();
  await page.click('[data-fs]');
  await page.waitForFunction(() => !document.fullscreenElement && !document.documentElement.classList.contains('is-fs'), null, { timeout: 4000 }).catch(() => {});
  out.exitMs = Date.now() - t0;
  out.layoutReadsDuringToggle = await page.evaluate(() => window.__reads);
  const m1 = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((x) => [x.name, x.value]));
  out.layoutCountDelta = m1.LayoutCount - m0.LayoutCount;
  out.afterIconExit = await state();
  await page.screenshot({ path: path.join(OUT, 'android-390--fs-3-exited-by-icon.jpg'), type: 'jpeg', quality: 82 });

  await page.tap('input[name=pin]');               // "off" holds: no re-entry
  await page.waitForTimeout(500);
  out.afterNextTapWhileOff = await state();
  out.storedPreference = await page.evaluate(() => localStorage.getItem('oa.fs'));

  await page.click('[data-fs]');                   // the icon brings it back
  await page.waitForFunction(() => !!document.fullscreenElement, null, { timeout: 4000 }).catch(() => {});
  out.afterIconEnter = await state();
  await ctx.close();

  // iPhone Safari: no element full screen, so no icon.
  const ios = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1.5, isMobile: true, hasTouch: true, userAgent: IPHONE });
  await ios.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
  const p2 = await ios.newPage();
  await p2.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await p2.waitForSelector('[data-enrol]');
  out.iphone = await p2.evaluate(() => ({ cls: document.documentElement.className, iconVisible: getComputedStyle(document.querySelector('.fsbar')).display !== 'none' }));
  await p2.screenshot({ path: path.join(OUT, 'iphone-390--fs-icon-absent.jpg'), type: 'jpeg', quality: 82 });
  await ios.close();

  // A tablet keeps the browser view: no icon, no automatic full screen.
  const tab = await browser.newContext({ viewport: { width: 820, height: 1180 }, isMobile: true, hasTouch: true, userAgent: ANDROID.replace('Pixel 8', 'SM-X710').replace(' Mobile', '') });
  const p3 = await tab.newPage();
  await p3.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await p3.tap('input[name=email]');
  await p3.waitForTimeout(400);
  out.tablet = await p3.evaluate(() => ({ fs: !!document.fullscreenElement, cls: document.documentElement.className }));
  await tab.close();

  fs.writeFileSync(path.join(OUT, 'fullscreen-report.json'), JSON.stringify(out, null, 1));
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
