/* Lane LG2: the shine, shown moving -- the header logo at 2x with the shine
   animation held at three points of its crossing (of the 7 s default period
   the crossing is the last 3.15 s), plus the reduced-motion state.
     NODE_PATH=/opt/node22/lib/node_modules node tools/lg2-shine.cjs <base> */
const { chromium } = require('playwright');
const BASE = process.argv[2];
const OUT = __dirname + '/../docs/lane-lg2-shots/';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    for (const rm of [false, true]) {
      const ctx = await b.newContext({ viewport: { width: w, height: 700 }, deviceScaleFactor: 2, reducedMotion: rm ? 'reduce' : 'no-preference', isMobile: w < 600, hasTouch: w < 600 });
      const p = await ctx.newPage();
      await p.goto(BASE + '/collections/cbbanner/', { waitUntil: 'load' });
      await p.evaluate(() => document.fonts.ready);
      const lg = p.locator('header a.lgx');
      if (rm) {
        const st = await p.evaluate(() => ({ anims: document.getAnimations().filter((a) => a.effect && a.effect.target && (a.effect.target.closest && a.effect.target.closest('.lgx'))).length, shine: getComputedStyle(document.querySelector('.lgx-lt')).display }));
        console.log(w, 'reduced motion:', JSON.stringify(st));
        await lg.screenshot({ path: `${OUT}shine-reduced-motion-${w}.png` });
      } else {
        for (const t of [4400, 5200, 6000]) {
          await p.evaluate((t) => document.getAnimations().filter((a) => a.animationName === 'lgx-sh').forEach((a) => { a.pause(); a.currentTime = t; }), t);
          await p.waitForTimeout(150);
          await lg.screenshot({ path: `${OUT}shine-${w}-t${t}.png` });
        }
      }
      await ctx.close();
    }
  }
  await b.close();
})();
