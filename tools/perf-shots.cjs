/*
 * Lane PERF — the pictures, and the geometry behind the claim that nothing
 * moved.
 *
 * Both trees are served at once (tools/perf-preview.sh takes a git ref), so
 * every pair below is the SAME page at the SAME width in the SAME browser,
 * differing only in the commit that rendered it.
 *
 * NOTHING HERE SHIPS, and the PAGE measures nothing — every rectangle is read
 * by this harness in a browser, never by a script the shop serves. CLAUDE.md
 * rule 4 is about the storefront's own JavaScript and two tests in this repo
 * forbid the element-measuring APIs by name inside it.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BEFORE = process.env.PERF_BEFORE || 'http://127.0.0.1:8992';
const AFTER = process.env.PERF_AFTER || 'http://127.0.0.1:8991';
const OUT = process.env.PERF_OUT || '/home/user/lane-perf/docs/perf-shots';
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

/* Read in the page, not by the page: the geometry that would show a
   regression. Every value here is one a delivery change must NOT move. */
const M = () => {
  const box = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    return {
      w: +r.width.toFixed(2), h: +r.height.toFixed(2),
      font: cs.fontSize, weight: cs.fontWeight, color: cs.color,
      family: cs.fontFamily.split(',')[0].replace(/"/g, ''),
    };
  };

  const imgs = [...document.querySelectorAll('.kbbn-im img')].slice(0, 3).map((i) => ({
    css: [+i.getBoundingClientRect().width.toFixed(1), +i.getBoundingClientRect().height.toFixed(1)],
    natural: [i.naturalWidth, i.naturalHeight],
    current: (i.currentSrc || i.src).replace(/^https?:\/\/[^/]+/, ''),
  }));

  return {
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    bodyHeight: Math.round(document.body.getBoundingClientRect().height),
    headings: [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((h) => h.tagName),
    footerHeading: box('.fcol h2, .fcol h5'),
    cardName: box('.kbb-card .cn'),
    price: box('.kbb-card-price'),
    bannerBand: box('.kbbn-bd'),
    bannerHeading: box('.kbbn-h'),
    bannerCard: box('.kbbn-c'),
    banner: imgs,
    fontsLoaded: [...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family + ' ' + f.weight).sort(),
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE, args: ['--ignore-certificate-errors'] });
  const out = {};

  for (const [label, base] of [['before', BEFORE], ['after', AFTER]]) {
    for (const width of [390, 1280]) {
      const ctx = await browser.newContext({
        viewport: { width, height: width === 390 ? 844 : 900 },
        deviceScaleFactor: 2,
      });
      const page = await ctx.newPage();
      await page.goto(base + '/', { waitUntil: 'networkidle' });
      /* EVERY animation off, not just the carousel's.
         Two reasons, and the second one cost a run: the marquee, the promo
         ticker and the burger's three bars all loop forever, so two shots
         taken a second apart differ for a reason that is not the change --
         and Playwright's screenshot WAITS for animations to settle, so a page
         with an infinite one never yields a picture at all. */
      await page.addStyleTag({ content: '*,*::before,*::after{animation:none !important;transition:none !important}' });
      await page.waitForTimeout(500);

      out[`${label}-${width}`] = await page.evaluate(M);
      await page.screenshot({ path: `${OUT}/${label}-${width}.png`, fullPage: false, animations: 'disabled', timeout: 60000 });
      await page.screenshot({ path: `${OUT}/${label}-${width}-full.png`, fullPage: true, animations: 'disabled', timeout: 120000 });
      await ctx.close();
    }
  }

  await browser.close();
  fs.writeFileSync(`${OUT}/MEASUREMENTS.json`, JSON.stringify(out, null, 1));
  console.log(JSON.stringify(out, null, 1));
})();
