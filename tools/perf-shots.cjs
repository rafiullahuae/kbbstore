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
      /* deviceScaleFactor 1, not 2. The full-page shot of this homepage is
         ~18,000px tall; at ratio 2 that is a 5 MB PNG per shot and 20 MB of
         binaries in the repository to show that nothing moved. One CSS pixel
         per image pixel is the same picture and a twentieth of the weight. */
      const ctx = await browser.newContext({
        viewport: { width, height: width === 390 ? 844 : 900 },
        deviceScaleFactor: 1,
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
      await page.screenshot({ path: `${OUT}/${label}-${width}.jpg`, quality: 82, fullPage: false, animations: 'disabled', timeout: 60000 });
      await page.screenshot({ path: `${OUT}/${label}-${width}-full.jpg`, quality: 76, fullPage: true, animations: 'disabled', timeout: 120000 });
      await ctx.close();
    }
  }

  await browser.close();
  fs.writeFileSync(`${OUT}/MEASUREMENTS.json`, JSON.stringify(out, null, 1));

  /* A contact sheet, because eight files in a folder is not a picture anybody
     looks at. Side by side at each width, with the numbers under them. */
  const row = (w) => {
    const b = out[`before-${w}`];
    const a = out[`after-${w}`];
    const cell = (label, m, file) => `
      <figure>
        <figcaption><b>${label}</b> · ${w}px<br>
          scrollWidth <b>${m.scrollWidth}</b> · body ${m.bodyHeight}px<br>
          headings ${m.headings.join(' ')}<br>
          footer heading ${m.footerHeading ? m.footerHeading.font + ' / ' + m.footerHeading.weight : '—'}<br>
          faces loaded ${m.fontsLoaded.join(', ') || 'none'}<br>
          first banner picture <code>${(m.banner[0] || {}).current || '—'}</code>
        </figcaption>
        <img src="${file}" alt="${label} at ${w}px">
      </figure>`;
    return `<section><h2>${w}px</h2><div class="pair">
      ${cell('before', b, `before-${w}.jpg`)}
      ${cell('after', a, `after-${w}.jpg`)}
    </div></section>`;
  };

  fs.writeFileSync(`${OUT}/index.html`, `<!doctype html>
<meta charset="utf-8"><title>Lane PERF — the homepage, before and after</title>
<style>
 body{font:14px/1.5 system-ui,sans-serif;margin:24px;background:#faf7f8;color:#2A2228}
 h1{font-size:20px} h2{font-size:15px;margin:28px 0 8px}
 .pair{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}
 figure{margin:0} figcaption{font-size:12px;margin-bottom:6px;color:#5E545A}
 img{width:100%;border:1px solid rgba(42,34,40,.15);border-radius:8px;background:#fff}
 code{font-size:11px;word-break:break-all}
</style>
<h1>Lane PERF — the homepage, before and after</h1>
<p>Left: the commit this lane branched from. Right: its tip. Same browser, same
fixture, animations disabled so the carousel is at the same offset in both.</p>
${row(390)}
${row(1280)}
<h2>Full pages</h2>
<div class="pair">
 <figure><figcaption><b>before</b> · 390px, full page</figcaption><img src="before-390-full.jpg"></figure>
 <figure><figcaption><b>after</b> · 390px, full page</figcaption><img src="after-390-full.jpg"></figure>
</div>
<div class="pair">
 <figure><figcaption><b>before</b> · 1280px, full page</figcaption><img src="before-1280-full.jpg"></figure>
 <figure><figcaption><b>after</b> · 1280px, full page</figcaption><img src="after-1280-full.jpg"></figure>
</div>
`);

  console.log(JSON.stringify(out, null, 1));
})();
