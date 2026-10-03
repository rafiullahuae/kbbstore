/*
 * Lane RG measuring harness. Reads rectangles IN THE HARNESS, never in a
 * script the shop serves.
 *   RG_BASE=http://127.0.0.1:9870 node tools/rg-measure.cjs <slug> [widths]
 * Prints JSON: per width, the details block (tab row bottom -> first text line
 * of the open panel, for every tab), and the buy column's block rects.
 */
const { chromium } = require('playwright');
const BASE = process.env.RG_BASE || 'http://127.0.0.1:9870';
const CHROME = process.env.RG_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const slug = process.argv[2] || 'pdp-heartleaf-toner';
const widths = (process.argv[3] || '390,1280').split(',').map(Number);

const BUY = {
  title: '.pm-title', price: '.pm-price', short: '.pm-short:not(.pm-short-set)', paylater: '.pm-paylater',
  bundles: '.pm-bundles', ready: '.pm-ready', delivery: '.kbb-cart-form > .pts-del', cart: '.pm-cart',
  auth: '.kbb-cart-form > .pts-stack', trust: '.pm-trust', paychips: '.pm-paychips',
};

async function measure(page) {
  return page.evaluate(async (BUY) => {
    const r = (el) => {
      if (!el) return null;
      const b = el.getBoundingClientRect();
      return b.height === 0 && b.width === 0 ? 'hidden' : { top: Math.round(b.top + scrollY), bottom: Math.round(b.bottom + scrollY), h: Math.round(b.height) };
    };
    const firstText = (root) => {
      const tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, { acceptNode: (n) => (n.nodeValue.replace(/[\s ]/g, '') ? 1 : 3) });
      const n = tw.nextNode();
      if (!n) return null;
      const rg = document.createRange();
      rg.selectNodeContents(n);
      const rs = rg.getClientRects();
      return rs.length ? { top: rs[0].top + scrollY, text: n.nodeValue.trim().slice(0, 24) } : null;
    };
    const res = { scrollWidth: document.documentElement.scrollWidth, tabs: [], buy: {} };
    const det = document.querySelector('.pm-details');
    if (det) {
      const eb = det.querySelector(':scope > .eyebrow');
      const h2 = det.querySelector(':scope > h2');
      res.details = { section: r(det), eyebrow: r(eb), heading: r(h2), sectionTopToTabbar: null };
      const bar = det.querySelector('.dtabbar');
      if (bar) {
        res.details.sectionTopToTabbar = Math.round(bar.getBoundingClientRect().top - det.getBoundingClientRect().top);
        const tabs = [...bar.querySelectorAll('.dtab')];
        for (const t of tabs) {
          t.click();
          await new Promise((ok) => setTimeout(ok, 80));
          const panel = det.querySelector('.dtabpanel.on .dcontent');
          const ft = firstText(panel);
          const bb = bar.getBoundingClientRect().bottom + scrollY;
          res.tabs.push({ tab: t.textContent.trim(), gap: ft ? Math.round(ft.top - bb) : null, boxGap: Math.round(panel.getBoundingClientRect().top + scrollY - bb), first: ft && ft.text });
        }
        if (tabs[0]) tabs[0].click();
      }
    }
    for (const [k, s] of Object.entries(BUY)) res.buy[k] = r(document.querySelector('.pdp-page ' + s));
    return res;
  }, BUY);
}

module.exports = { measure, BUY };

if (require.main === module) {
  (async () => {
    const browser = await chromium.launch({ executablePath: CHROME });
    const out = {};
    for (const w of widths) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 } });
      await page.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
      out[w] = await measure(page);
      await page.close();
    }
    await browser.close();
    console.log(JSON.stringify(out));
  })();
}
