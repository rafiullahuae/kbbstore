/* Lane SPD: which event wakes the deferred marketing pixels on a page nobody
 * touched.  node tools/spd-pixel-wake.cjs <url> [width=412]
 *
 * The pixel loaders in the <head> (fbq / gtag / ttq, AnalyticsDefer) wait for
 * the load event AND a first "human" event -- pointerdown, touchstart,
 * keydown, scroll, wheel, mousemove, listened for on window in the CAPTURE
 * phase -- or five seconds. This logs every one of those events that reaches
 * window before load, with its target, so a programmatic scroll can be told
 * from a person. Nothing is clicked or scrolled by this script. */
const { chromium } = require('playwright');

(async () => {
  const [url, width = '412'] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: +width, height: 823 }, deviceScaleFactor: 1.75, isMobile: true, hasTouch: true });
  await page.addInitScript(() => {
    window.__spdWake = [];
    const t0 = performance.now();
    ['pointerdown', 'touchstart', 'keydown', 'scroll', 'wheel', 'mousemove'].forEach((type) => {
      addEventListener(type, (e) => {
        const el = e.target;
        const name = el === document ? 'document' : el && el.nodeType === 1
          ? el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : '')
          : String(el);
        window.__spdWake.push({ type, target: name, ms: Math.round(performance.now() - t0), beforeLoad: document.readyState !== 'complete' });
      }, { capture: true, passive: true });
    });
  });
  const scripts = [];
  page.on('request', (r) => { if (/fbevents|gtag\/js|analytics\.tiktok/.test(r.url())) scripts.push({ url: r.url().slice(0, 60), ms: Date.now() }); });
  const t = Date.now();
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(1500);
  const wake = await page.evaluate(() => window.__spdWake);
  console.log(JSON.stringify({ wake: wake.slice(0, 20), pixels: scripts.map((s) => ({ url: s.url, ms: s.ms - t })) }, null, 1));
  await browser.close();
})();
