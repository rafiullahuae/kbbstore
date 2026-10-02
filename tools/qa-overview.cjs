/*
 * Lane QA — compose docs/qa-shots/overview.png from the shots in that folder.
 *   node tools/qa-overview.cjs      (after qa-shots.cjs before|after|arabic and qa-admin-shots.cjs)
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const DIR = path.join(__dirname, '..', 'docs', 'qa-shots');
const CHROME = process.env.QA_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const after = JSON.parse(fs.readFileSync(path.join(DIR, 'MEASUREMENTS-after.json'), 'utf8'));
const admin = JSON.parse(fs.readFileSync(path.join(DIR, 'MEASUREMENTS-admin.json'), 'utf8'));

const img = (f) => 'data:image/png;base64,' + fs.readFileSync(path.join(DIR, f)).toString('base64');
const tile = (f, cap, w, h, pos = 'top') => `<figure style="width:${w}px"><div class="f" style="height:${h}px"><img src="${img(f)}" style="object-position:center ${pos}"></div><figcaption>${cap}</figcaption></figure>`;

const gaps = after['toner-390'].gaps.join(' · ');
const html = `<!doctype html><meta charset="utf-8"><style>
body{margin:0;padding:28px;font:13px/1.45 system-ui,sans-serif;background:#f4f1f3;color:#2a2228;width:2160px}
h1{font-size:22px;margin:0 0 4px}h2{font-size:15px;margin:22px 0 10px}
p{margin:0 0 6px;color:#5b5258}.row{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap}
figure{margin:0}.f{overflow:hidden;border:1px solid #ddd;border-radius:8px;background:#fff}
.f img{width:100%;height:100%;object-fit:cover}figcaption{font-size:12px;color:#5b5258;margin-top:5px}
code{background:#fff;padding:1px 5px;border-radius:4px;font-size:12px}
</style>
<h1>Lane QA — product page as ordered, switchable sections (phone), price row + share (laptop), Tabby &amp; Tamara</h1>
<p>Phone breakpoint: the product page's own <code>max-width:880px</code>. Measured at 390: section order by on-screen top = <code>${after['toner-390'].order.join(' → ')}</code>; scrollWidth ${after['toner-390'].scrollWidth} = viewport.</p>
<p>Gaps between consecutive sections at 390 (default 18px; named exceptions blurb 10px, Add to cart 12px): <code>${gaps}</code></p>
<h2>Phone, 390 — before / after (toner), after (set, variable, Arabic)</h2>
<div class="row">
${tile('before-toner-390.png', 'BEFORE · toner · 390', 300, 1500)}
${tile('after-toner-390.png', 'AFTER · toner · 390 — title, blurb, price row + rating (no count), Tabby &amp; Tamara, bundles, ready, cart, delivery, authenticity; trust rows and THE DETAILS gone', 300, 1500)}
${tile('after-set-390.png', 'AFTER · set · 390 — blurb stays under “What is in this set”', 300, 1500)}
${tile('after-variable-390.png', 'AFTER · variable · 390', 300, 1500)}
${tile('arabic-toner-390.png', 'AFTER · Arabic (RTL) · 390 — share on the left, rating on the left', 300, 1500)}
${tile('admin-after-product-390.png', 'AFTER A SAVE ON THE TAB · delivery box dragged above “Ready to ship”, trust rows switched on', 300, 1500)}
</div>
<h2>Laptop, 1280 — before / after (only the two changes: price row under the title with the rating beside it, share icon in the title row)</h2>
<div class="row">
${tile('before-toner-1280.png', 'BEFORE · 1280', 1000, 620)}
${tile('after-toner-1280.png', 'AFTER · 1280 — everything below the price row measured identical to before (offsets relative to the blurb, 4 products)', 1000, 620)}
</div>
<h2>Admin — Appearance → Product page → Mobile sections</h2>
<div class="row">
${tile('admin-tab-1280.png', 'The tab at 1280, with the live phone + laptop preview', 760, 470)}
${tile('admin-drag-in-progress-1280.png', 'Mouse drag in progress (HTML5 DnD): “Delivery box” lifted, drop marker shown', 760, 470)}
${tile('admin-tab-390.png', 'The tab at 390', 280, 470)}
${tile('admin-drag-in-progress-390.png', 'Finger drag in progress at 390 (pointer events): “Payment chips” over “Authenticity row”', 280, 470)}
</div>
<p style="margin-top:14px">After the mouse drop: <code>${admin['after-drop'].join(' → ')}</code></p>
<p>Product page after Save: <code>${admin['product-after-save'].order.join(' → ')}</code> (scrollWidth ${admin['product-after-save'].scrollWidth})</p>`;

(async () => {
  const b = await chromium.launch({ executablePath: CHROME });
  const p = await b.newPage({ viewport: { width: 2216, height: 1000 } });
  await p.setContent(html, { waitUntil: 'load' });
  await p.screenshot({ path: path.join(DIR, 'overview.png'), fullPage: true });
  await b.close();
  console.log('overview.png written');
})();
