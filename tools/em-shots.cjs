/*
 * Lane EM — screenshot every real email (tools/em-render.php's output) at 390
 * and 600 wide in Chromium, and put each beside the owner's approved preview
 * shot of the same email (docs/rj-email-previews/after/shots/NN-WIDTH.jpg) as
 * one side-by-side PNG in docs/em-emails/.
 *
 *   NODE_PATH=/home/user/kbbstore/node_modules node tools/em-shots.cjs [renderDir]
 *
 * The rendered emails ask for https://shots.test/<pic>.jpg (the fixture's
 * product pictures) and https://extrabeauty.ae/mail/font/outfit-latin.woff2;
 * both are answered locally from the previews' own stand-in pictures and the
 * shop's Outfit file, so the two columns are drawn with the same pixels.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
const RENDER = path.resolve(process.argv[2] || path.join(ROOT, 'storage/em-logs/render'));
const OUT = path.join(ROOT, 'docs/em-emails');
const PREV = path.join(ROOT, 'docs/rj-email-previews/after/shots');
const ASSETS = path.join(ROOT, 'docs/rj-email-previews/assets');
const FONT = path.join(ROOT, 'resources/fonts/outfit/outfit-latin.woff2');
const SUFFIX = process.env.EM_SUFFIX || '';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const metrics = [];
  const names = fs.readdirSync(RENDER).filter((f) => f.endsWith('.html')).map((f) => f.replace(/\.html$/, '')).sort();

  for (const name of names) {
    const html = fs.readFileSync(path.join(RENDER, `${name}.html`), 'utf8');
    for (const width of [390, 600]) {
      const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
      // Registered FIRST: Playwright tries the most recently added route first.
      await page.route(/^https?:\/\/(?!shots\.test)/, (route) => route.abort());
      await page.route('https://shots.test/**', (route) => {
        const pic = path.basename(new URL(route.request().url()).pathname, '.jpg');
        route.fulfill({ path: path.join(ASSETS, `${pic}-${width >= 600 ? 400 : 128}.jpg`), contentType: 'image/jpeg' });
      });
      await page.route('**/mail/font/outfit-latin.woff2', (route) => route.fulfill({ path: FONT, contentType: 'font/woff2' }));
      await page.setContent(html, { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      const m = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        height: document.documentElement.scrollHeight,
        font: getComputedStyle(document.querySelector('.h1') || document.body).fontFamily.split(',')[0],
        outfitLoaded: document.fonts.check("16px 'Outfit'"),
      }));
      const mine = path.join(OUT, `.tmp-${name}-${width}.png`);
      await page.screenshot({ path: mine, fullPage: true });
      await page.close();

      const preview = path.join(PREV, `${name}-${width}.jpg`);
      const out = path.join(OUT, `${name}-${width}${SUFFIX}.png`);
      const side = await browser.newPage({ viewport: { width: width * 2 + 60, height: 900 }, deviceScaleFactor: 1 });
      const img = (p) => `data:image/${p.endsWith('.png') ? 'png' : 'jpeg'};base64,${fs.readFileSync(p).toString('base64')}`;
      const col = (title, p) => `<div style="width:${width}px"><div style="font:600 13px system-ui;padding:8px 0;color:#333">${title}</div>${p && fs.existsSync(p) ? `<img src="${img(p)}" style="display:block;width:${width}px;border:1px solid #ddd">` : '<div style="font:13px system-ui;color:#999">(no preview shot at this width)</div>'}</div>`;
      await side.setContent(`<body style="margin:0;padding:12px 20px;background:#fff;display:flex;gap:20px;align-items:flex-start">${col(`APPROVED PREVIEW · ${name} · ${width}px`, preview)}${col(`REAL EMAIL (sent by the shop's mailer) · ${width}px`, mine)}</body>`);
      await side.screenshot({ path: out, fullPage: true });
      await side.close();
      fs.unlinkSync(mine);
      metrics.push({ name, width, ...m });
      console.log(name, width, JSON.stringify(m));
    }
  }
  fs.writeFileSync(path.join(OUT, `measurements${SUFFIX}.json`), JSON.stringify(metrics, null, 1) + '\n');
  await browser.close();
})();
