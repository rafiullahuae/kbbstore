// Lane OHB instrument: which stylesheet rules colour an element.  node tools/ohb-probe.cjs <url> <selector>
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 1280, height: 800 } });
  await p.goto(process.argv[2], { waitUntil: 'networkidle' });
  const r = await p.evaluate((sel) => {
    const a = document.querySelector(sel);
    const cs = getComputedStyle(a);
    const hits = [];
    for (const sh of document.styleSheets) {
      let rules; try { rules = sh.cssRules; } catch (e) { continue; }
      for (const r of rules) { if (r.selectorText && a.matches(r.selectorText) && /color/.test(r.cssText)) hits.push(r.cssText.slice(0, 180)); }
    }
    return { color: cs.color, btnInk: cs.getPropertyValue('--btnInk'), hits };
  }, process.argv[3]);
  console.log(JSON.stringify(r, null, 1));
  await b.close();
})();
