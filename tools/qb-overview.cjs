/*
 * Lane QB: compose docs/qb-shots/overview.png from the shots tools/qb-shots.cjs
 * took — the phone sheet sliding, open, "Link copied", the laptop card, the
 * share picture itself and the admin Share tab, on one page.
 *
 *   node tools/qb-overview.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const DIR = path.resolve(__dirname, '..', 'docs/qb-shots');
const M = JSON.parse(fs.readFileSync(path.join(DIR, 'MEASUREMENTS.json'), 'utf8'));
const img = (f) => `data:image/${f.endsWith('.jpg') ? 'jpeg' : 'png'};base64,` + fs.readFileSync(path.join(DIR, f)).toString('base64');
const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const fig = (f, cap, w) => `<figure style="width:${w}px"><img src="${img(f)}"><figcaption>${esc(cap)}</figcaption></figure>`;

const html = `<!doctype html><meta charset="utf-8"><style>
body{margin:0;padding:28px;font:14px/1.45 system-ui,sans-serif;background:#F4F4F6;color:#1d1d1f;width:1844px}
h1{font-size:24px;margin:0 0 4px}p.lede{margin:0 0 20px;color:#555}
.row{display:flex;gap:22px;align-items:flex-start;margin-bottom:26px;flex-wrap:wrap}
figure{margin:0;background:#fff;border-radius:12px;padding:10px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
figure img{display:block;width:100%;border-radius:6px;border:1px solid #eee}
figcaption{font-size:12.5px;color:#444;margin-top:8px}
</style><h1>Lane QB — share icon → share sheet, and the picture a shared link carries</h1>
<p class="lede">Phone 390px and laptop 1280px, Chromium. The share icon beside the title is a stand-in (Lane QA draws the real one). og:image is now a 1200×630 JPEG of ${M.shareImage ? M.shareImage.bytes : '?'} bytes; the original was a 1600px WebP.</p>
<div class="row">
${fig('sheet-opening-390.png', 'Phone · sliding up (≈60 ms in)', 300)}
${fig('sheet-open-390.png', `Phone · open — panel ${M[390].open.panel.height}px tall, icons ${M[390].open.tiles.icon}px, ${M[390].open.tiles.perRow} per row, scrollWidth ${M[390].open.scrollWidth}`, 300)}
${fig('sheet-link-copied-390.png', 'Phone · Copy → “Link copied”', 300)}
${fig('sheet-open-with-more-390.png', 'Phone with a share menu · “More” shown; shares the JPEG as a file', 300)}
</div>
<div class="row">
${fig('sheet-open-1280.png', `Laptop · centred card ${M[1280].open.panel.width}px wide`, 860)}
${fig('share-image-1200x630.jpg', `og:image — 1200×630 JPEG, ${M.shareImage ? M.shareImage.bytes : '?'} bytes, letterboxed on white`, 600)}
</div>
<div class="row">
${fig('admin-share-tab-1280.png', 'Admin · Appearance → Product page → Share', 860)}
${fig('admin-share-order-moved-1280.png', 'Admin · tile order, Snapchat moved up one (before Save)', 420)}
${fig('buy-column-no-row-390.png', 'Phone · buy column: the share row is gone', 300)}
</div>`;

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const page = await browser.newPage({ viewport: { width: 1900, height: 1200 } });
  await page.setContent(html, { waitUntil: 'load' });
  await page.screenshot({ path: path.join(DIR, 'overview.png'), fullPage: true });
  await browser.close();
  console.log('wrote', path.join(DIR, 'overview.png'));
})();
