// Lane PF2: the first screen of the homepage, before and after, at the widths
// the banner tier is for. The banner's pixels are EXPECTED to differ at DPR 1
// (a 1280w copy resampled by GD instead of the 1920 original resampled by the
// browser); this records by how much, and that nothing else on the screen
// moved.   node tools/pf2-banner-shots.cjs <before> <after> <outdir>
const { chromium } = require('playwright');
(async () => {
  const [B, A, out] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [w, dpr] of [[1280, 1], [1440, 1], [1280, 2], [390, 1]]) {
    for (const [tag, base] of [['before', B], ['after', A]]) {
      const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: dpr, reducedMotion: 'reduce' });
      const p = await ctx.newPage();
      await p.goto(base + '/', { waitUntil: 'load' });
      await p.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
      await p.evaluate(() => Promise.race([new Promise(r => setTimeout(r, 5000)), Promise.all([...document.images].filter(i => i.complete).map(i => i.decode().catch(() => 0)))]));
      await p.waitForTimeout(600);
      const r = await p.evaluate(() => { const v = document.querySelector('.kbbs-vp').getBoundingClientRect(); const i = document.querySelector('.kbbs-vp img'); return { x: v.x, y: v.y, w: v.width, h: v.height, cur: i.currentSrc.replace(location.origin, '') }; });
      await p.screenshot({ path: `${out}/banner-${tag}-${w}@${dpr}x.png`, animations: 'disabled', caret: 'hide' });
      console.log(`${tag} ${w}@${dpr}x frame=${r.w}x${r.h}+${r.x}+${r.y} chose=${r.cur}`);
      await ctx.close();
    }
  }
  await b.close();
})();
