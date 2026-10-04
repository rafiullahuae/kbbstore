/*
 * Lane FS: what each homepage section draws TODAY, measured in Chromium, for
 * App\Support\SectionType::TODAY (the number a Fonts & size slider opens at).
 * Run against tools/fs-preview.sh with every section given `font => outfit`
 * (a no-op that adds the kbb-ty-<key> hook): node tools/fs-measure.cjs PORT
 */
const { chromium } = require('playwright');
const EB = '.hs-eyebrow,h2>.cnt,.kick,.q-k';
const SUB = '.hs-ht>p,.sh p,.gs-head p,.lede,.ugcr-sub';
const BODY = '.kbb-card-nm,.hs-pex,.hs-post h3,.hs-abtext p,.hs-fp>p,.q-left>p,.rb b,.rb span,.i b,.i span,.ct b,.ct .n,.hs-blb,.rtext,.igp-cap,.ugcr-cap';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const [w, dev] of [[1280, 'd'], [390, 'm']]) {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    await p.goto(`http://127.0.0.1:${process.argv[2] || 10090}/`, { waitUntil: 'networkidle' });
    const r = await p.evaluate(([EB, SUB, BODY]) => {
      const px = (el, prop) => el ? Math.round(parseFloat(getComputedStyle(el)[prop])) : null;
      const res = {};
      document.querySelectorAll('[class*="kbb-ty-"]').forEach((s) => {
        const key = [...s.classList].find((c) => c.startsWith('kbb-ty-')).slice(7);
        res[key] = { h: px(s.querySelector('h2'), 'fontSize'), eb: px(s.querySelector(EB), 'fontSize'), sub: px(s.querySelector(SUB), 'fontSize'),
          body: px(s.querySelector(BODY), 'fontSize'), pt: px(s, 'paddingTop'), pb: px(s, 'paddingBottom'),
          gap: px(s.querySelector('.sh,.hs-head,.gs-head,.ugcr-head'), 'marginBottom') };
      });
      return res;
    }, [EB, SUB, BODY]);
    for (const [k, v] of Object.entries(r)) {
      out[k] = out[k] || {};
      for (const [f, n] of Object.entries(v)) if (n !== null) out[k][(f === 'h' ? 'h' : f) + '_' + dev] = n;
    }
    await p.close();
  }
  await b.close();
  console.log(JSON.stringify(out));
})();
