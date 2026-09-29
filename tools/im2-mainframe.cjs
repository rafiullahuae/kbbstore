/*
 * Lane IM2, one question only: the MAIN frame's box against the photograph
 * inside it, with a PORTRAIT shot selected.
 *
 * The coordinator, relaying the owner's screenshot: "the main image is a tall
 * portrait and the frame around it is taller still." That is a different
 * question from the strip and Lane PP owns the product page's layout, so this
 * measures it and stops. The portrait shot is selected the way a shopper
 * selects it -- by tapping its thumbnail -- because the main frame's own file
 * is square and the complaint is about what happens when it is not.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.IM2_BASE || 'http://127.0.0.1:8979';
const OUT = process.env.IM2_OUT || `${require('path').resolve(__dirname, '..')}/docs/lane-im2-shots`;

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const lines = ['# Lane IM2 — the main frame, measured and not redesigned', ''];

  for (const [w, h] of [[390, 844], [1280, 900]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/product/im2-gallery`, { waitUntil: 'networkidle' });

    for (const [label, index] of [['square shot 1 (as loaded)', 0], ['PORTRAIT shot 2 (9:16)', 1], ['LANDSCAPE shot 3 (16:9)', 2]]) {
      if (index > 0) {
        await page.evaluate((i) => document.querySelectorAll('.gthumb')[i]?.click(), index);
        await page.waitForTimeout(700);
      }

      const m = await page.evaluate(() => {
        const frame = document.getElementById('gmain');
        const img = document.getElementById('gmainImg');
        const fr = frame.getBoundingClientRect();
        const ir = img.getBoundingClientRect();
        const fit = getComputedStyle(img).objectFit;
        const s = fit === 'cover'
          ? Math.max(ir.width / img.naturalWidth, ir.height / img.naturalHeight)
          : Math.min(ir.width / img.naturalWidth, ir.height / img.naturalHeight);

        return {
          frame: [Math.round(fr.width), Math.round(fr.height)],
          imgBox: [Math.round(ir.width), Math.round(ir.height)],
          natural: [img.naturalWidth, img.naturalHeight],
          painted: [Math.round(img.naturalWidth * s), Math.round(img.naturalHeight * s)],
          fit,
        };
      });

      const bandX = Math.round((m.frame[0] - m.painted[0]) / 2);
      const bandY = Math.round((m.frame[1] - m.painted[1]) / 2);

      lines.push(`@${w}px  ${label}`);
      lines.push(`  .gmain frame ${m.frame.join('×')}   file ${m.natural.join('×')}   painted ${m.painted.join('×')}   fit=${m.fit}`);
      lines.push(`  empty band: ${bandX}px each side, ${bandY}px top and bottom`);
      lines.push('');
    }

    await page.evaluate(() => document.querySelectorAll('.gthumb')[1]?.click());
    await page.waitForTimeout(600);
    await page.evaluate(() => document.getElementById('gmain')?.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/mainframe-portrait-${w}.png` });
    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(`${OUT}/mainframe.md`, lines.join('\n'));
  console.log(lines.join('\n'));
})();
