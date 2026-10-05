/*
 * Lane PW, phase 1: photograph the shop's homepage as it ships today, at an
 * iPhone and an iPad size, so the install-UI options in docs/pw-preview/ are
 * drawn over the real shop rather than over a mock of it.
 *
 *   sh tools/pwa-shop-preview.sh            # prints the URL
 *   PW_BASE=http://127.0.0.1:8731 node tools/pwa-backdrops.cjs
 *
 * Writes storage/pw-logs/bd-*.jpg; the preview embeds them as data URIs.
 */
const { chromium } = require('playwright');
const BASE = process.env.PW_BASE || 'http://127.0.0.1:8731';
const OUT = __dirname + '/../storage/pw-logs';
const IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const IPAD = 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [name, w, h, ua] of [['phone', 390, 844, IOS], ['tablet', 820, 1180, IPAD]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, userAgent: ua, isMobile: true, hasTouch: true });
    const page = await ctx.newPage();
    // Twice: the WhatsApp welcome bubble shows once per visitor, and the
    // backdrop should be the page a returning shopper sees.
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
      tabbar: !!document.querySelector('.tabbar'),
      wa: (() => { const a = document.querySelector('#kbbWa .kbw-a'); if (!a) return null; const r = a.getBoundingClientRect(); return [r.left, r.top, r.width, r.height].map(Math.round); })(),
      header: (() => { const e = document.querySelector('header'); if (!e) return null; const r = e.getBoundingClientRect(); return [r.top, r.height, getComputedStyle(e).position]; })() }));
    console.log(name, JSON.stringify(m));
    await page.screenshot({ path: `${OUT}/bd-${name}.jpg`, type: 'jpeg', quality: 72 });
    // And once without the WhatsApp float: the preview draws that button
    // itself, at the position measured above, so option A can show it lifting.
    await page.addStyleTag({ content: '#kbbWa{display:none!important}' });
    await page.screenshot({ path: `${OUT}/bd-${name}-nowa.jpg`, type: 'jpeg', quality: 70 });
    await ctx.close();
  }
  await browser.close();
})();
