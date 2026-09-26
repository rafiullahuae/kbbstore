/* The nav bar's own arithmetic, at --nav-scale 1 with wrapping suspended:
   how much room the row needs and what it is spending it on. */
const { chromium } = require('playwright');
(async () => {
  const base = process.argv[2];
  const widths = (process.argv[3] || '1024').split(',').map(Number);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of widths) {
    const page = await browser.newPage({ viewport: { width: w, height: 900 } });
    await page.goto(base + '/shop/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    const out = await page.evaluate(() => {
      const wrap = document.querySelector('.mbar .wrap');
      const mbar = document.querySelector('.mbar');
      if (!wrap) return null;
      mbar.style.setProperty('--nav-scale', '1');
      wrap.style.flexWrap = 'nowrap';
      const st = getComputedStyle(wrap);
      const kids = [...wrap.children];
      const gap = parseFloat(st.columnGap) || 0;
      const widths = kids.map(k => k.getBoundingClientRect().width);
      const needed = widths.reduce((a, b) => a + b, 0) + gap * (kids.length - 1);
      const available = wrap.clientWidth - (parseFloat(st.paddingLeft) || 0) - (parseFloat(st.paddingRight) || 0);
      // what the row spends on whitespace at scale 1
      const link = wrap.querySelector('.navlink');
      const lcs = getComputedStyle(link);
      const padEach = parseFloat(lcs.paddingLeft) + parseFloat(lcs.paddingRight);
      const innerGaps = kids.map(k => {
        const l = k.querySelector('.navlink');
        const g = parseFloat(getComputedStyle(l).gap) || 0;
        return g * Math.max(0, l.children.length); // gap per inline child boundary
      }).reduce((a, b) => a + b, 0);
      wrap.style.removeProperty('flex-wrap');
      mbar.style.removeProperty('--nav-scale');
      return {
        items: kids.length,
        available: Math.round(available * 10) / 10,
        needed: Math.round(needed * 10) / 10,
        deficit: Math.round((needed - available) * 10) / 10,
        barGapTotal: gap * (kids.length - 1),
        padTotal: Math.round(padEach * kids.length * 10) / 10,
        innerGapTotal: Math.round(innerGaps * 10) / 10,
        textTotal: Math.round((needed - padEach * kids.length - gap * (kids.length - 1) - innerGaps) * 10) / 10,
        itemWidths: widths.map(x => Math.round(x * 10) / 10),
      };
    });
    console.log(w, JSON.stringify(out));
    await page.close();
  }
  await browser.close();
})();
