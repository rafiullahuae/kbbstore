/* The exit popup's new wording (owner, 16 Nov), from render.php's files, at 390 and 1280, en and ar.
   NODE_PATH=/home/user/kbbstore/node_modules node docs/lane-gow-shots/shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch();
  const out = [];
  for (const lang of ['en', 'ar']) for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    const errors = []; p.on('pageerror', (e) => errors.push(String(e)));
    await p.goto('file://' + path.join(__dirname, `popup-${lang}.html`));
    await p.waitForTimeout(300);
    const r = await p.evaluate(() => ({ text: (document.querySelector('.kgo-pp p, .kgo-p, [class*=kgo] p') || {}).textContent || '', chips: [...document.querySelectorAll('.kgo-chips span')].map((s) => s.textContent), sw: document.documentElement.scrollWidth }));
    await p.screenshot({ path: path.join(__dirname, `popup-${lang}-${w}.png`) });
    out.push({ lang, w, ...r, errors });
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
