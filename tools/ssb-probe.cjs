// Lane SSB: which stylesheet rules touch one element -- node tools/ssb-probe.cjs URL SELECTOR [width]
const { chromium } = require('playwright');
(async () => {
  const [url, sel, w] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await browser.newPage({ viewport: { width: Number(w || 390), height: 800 } });
  await page.goto(url, { waitUntil: 'networkidle' });
  const out = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return 'none';
    const cs = getComputedStyle(el);
    const hits = [];
    for (const sh of document.styleSheets) {
      let rules; try { rules = sh.cssRules; } catch (e) { continue; }
      const walk = (list, ctx) => { for (const r of list) { if (r.cssRules && !r.selectorText) { walk(r.cssRules, ctx + (r.conditionText || r.media?.mediaText || '') + ' '); continue; } if (r.selectorText) { try { if (el.matches(r.selectorText)) hits.push(ctx + r.selectorText + ' {' + r.style.cssText.slice(0, 160) + '}'); } catch (e) {} } } };
      walk(rules, (sh.href || 'inline').split('/').pop() + ': ');
    }
    return { display: cs.display, fontSize: cs.fontSize, visibility: cs.visibility, height: cs.height, hits };
  }, sel);
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
