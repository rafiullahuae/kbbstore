// 2.60.507: live product pages before, and with the new description pipeline +
// stylesheet applied in the browser (transform.php, local kbb-product css), at 1280 and 390.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const CSS_OLD = process.env.CSS_OLD, CSS_NEW = process.env.CSS_NEW;
const pages = ['anua-peach-77-niacin-enriched-cream-50ml', 'anua-niacinamide-5-txa-brightening-pad', 'anua-bha-2-gentle-exfoliating-toner-150ml'];
const out = 'docs/lane-co13-shots/desc-spacing-507/';
const tx = b => execFileSync('php', ['storage/cpb-logs/ds507/transform.php'], { input: b }).toString();
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const slug of pages) {
    const url = `https://kbeautybliss.com/product/${slug}/`;
    for (const w of [1280, 390]) for (const mode of ['before', 'after']) {
      const p = await b.newPage({ viewport: { width: w, height: 900 } });
      if (mode === 'after') {
        await p.route(url, async r => {
          const res = await r.fetch(); let h = await res.text();
          h = h.replace(/(<div class="dcontent (?:clamp|open)">)([\s\S]*?)(<\/div>\s*(?:<button class="readmore"|<\/div>\s*<\/div>))/g, (m, a, body, c) => a + tx(body) + c);
          await r.fulfill({ response: res, body: h });
        });
        await p.route('**/build/assets/' + CSS_OLD, r => r.fulfill({ contentType: 'text/css', body: fs.readFileSync('public/build/assets/' + CSS_NEW) }));
      }
      await p.goto(url, { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(1500);
      const box = await p.evaluateHandle(() => {
        const el = [...document.querySelectorAll('.details .dcontent')].find(e => e.offsetParent);
        el.classList.remove('clamp'); el.style.maxHeight = 'none'; el.scrollIntoView(); return el;
      });
      const hgt = await p.evaluate(el => Math.round(el.getBoundingClientRect().height), box);
      await p.evaluate(() => document.querySelectorAll('header,.kbb-wa,.wa-float,[class*="whatsapp"]').forEach(e => e.style.visibility = 'hidden'));
      await box.asElement().screenshot({ path: `${out}${slug}-${w}-${mode}.png` });
      console.log(slug, w, mode, 'height', hgt, 'scrollWidth', await p.evaluate(() => document.documentElement.scrollWidth));
      await p.close();
    }
  }
  await b.close();
})();
