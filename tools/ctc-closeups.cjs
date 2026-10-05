/*
 * Lane CT (contrast) — close-ups for docs/ct-shots/contrast/: one product card
 * row and the phone footer's link columns (tap areas outlined), each before and
 * after. node tools/ctc-closeups.cjs <before-base> <after-base> <outDir>
 */
const { chromium } = require('playwright');
const [B, A, OUT] = process.argv.slice(2);
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140 Safari/537.36';
const FREEZE = '*,*::before,*::after{animation:none!important;transition:none!important}';
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--disable-lcd-text'] });
  for (const [side, base] of [['before', B], ['after', A]]) {
    for (const width of [390, 1280]) {
      const ctx = await br.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 2, userAgent: UA, reducedMotion: 'reduce' });
      const p = await ctx.newPage();
      await p.goto(base + '/shop/', { waitUntil: 'networkidle' });
      await p.addStyleTag({ content: FREEZE });
      const grid = p.locator('.kbb-pgrid').first();
      const box = await grid.boundingBox();
      await p.screenshot({ path: `${OUT}/card-row-${width}-${side}.png`, clip: { x: box.x, y: box.y, width: box.width, height: Math.min(box.height, width === 390 ? 420 : 470) }, fullPage: true });
      if (width === 390) {
        await p.addStyleTag({ content: 'nav.kft-col > ul > li > a{outline:1px dashed #2A62D6;outline-offset:0}' });
        const f = p.locator('.kft-grid').first();
        await f.scrollIntoViewIfNeeded();
        await f.screenshot({ path: `${OUT}/footer-links-390-${side}.png` });
      }
      await ctx.close();
    }
  }
  await br.close();
})();
