/* Lane LGO (2.60.492): the lotus logo on a browser without CSS round().
   Emulates such a browser by renaming round( so it is unknown, exactly as an
   older Android WebView sees it. BASE=... OLD_CSS=... node tools/lgo-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OLD = process.env.OLD_CSS, OUT = path.resolve(__dirname, '../docs/lane-lgo-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Linux; Android 12; SM-A125F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36 Instagram 300.0';
const cases = [ ['before-old-browser', true, true], ['after-old-browser', false, true], ['after-modern', false, false] ];
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const [name, before, old] of cases) for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 } });
    const page = await ctx.newPage(); const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.route(/\/build\/assets\/kbb-[^/]+\.css$/, async (route) => {
      const r = await route.fetch(); let css = before ? fs.readFileSync(OLD, 'utf8') : await r.text();
      if (old) css = css.replace(/\bround\(/g, 'nosuchfn(');
      route.fulfill({ response: r, body: css });
    });
    if (before) await page.route(BASE + '/', async (route) => { const r = await route.fetch(); route.fulfill({ response: r, body: (await r.text()).replace(/ width="35" height="26"/g, '') }); });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
    const m = await page.evaluate(() => {
      const i = document.querySelector('header .logo.lgx>.lgx-i'), r = i.getBoundingClientRect(), h = document.querySelector('header .hin').getBoundingClientRect();
      return { iconW: Math.round(r.width), iconH: Math.round(r.height), headerRowH: Math.round(h.height), scrollW: document.documentElement.scrollWidth };
    });
    console.log(name, w, JSON.stringify({ ...m, errors }));
    await ctx.close();
  }
  await browser.close();
})();
