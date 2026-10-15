/*
 * Lane ARF -- the footer's big name and small logo, measured: does the big
 * name fit its line (scrollWidth <= clientWidth) on /ar and in English?
 *
 *   node tools/arf-kftname.cjs <port>
 */
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const lang of ['ar', 'en']) {
    for (const w of [320, 390, 600, 900, 1280, 1600]) {
      const c = await b.newContext({ viewport: { width: w, height: 800 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
      const p = await c.newPage();
      await p.goto(`http://127.0.0.1:${process.argv[2]}${lang === 'ar' ? '/ar' : ''}/about/`, { waitUntil: 'load' });
      await p.evaluate(() => document.fonts.ready);
      const m = await p.evaluate(() => {
        const n = document.querySelector('.kft-name');
        const cs = getComputedStyle(n);
        const r = document.createRange(); r.selectNodeContents(n);
        const ink = r.getBoundingClientRect();
        return { sw: n.scrollWidth, cw: n.clientWidth, ink: Math.round(ink.width), fs: cs.fontSize, ls: cs.letterSpacing, n: n.getAttribute('style'), t: n.textContent.trim(), logo: document.querySelector('.kft-logo').textContent, page: document.documentElement.scrollWidth };
      });
      console.log(lang, w, JSON.stringify(m));
      await c.close();
    }
  }
  await b.close();
})();
