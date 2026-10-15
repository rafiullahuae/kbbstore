/*
 * Lane AR round 2 -- docs/ar-preview/index.html: every previewed page, Arabic
 * beside English, with one line of what to look at. Standalone (no network),
 * light and dark, phone-friendly.
 *
 *   node tools/arr-index.cjs [outDir=docs/ar-preview]
 */
const fs = require('fs');
const path = require('path');
const OUT = process.argv[2] || 'docs/ar-preview';

const GROUPS = [
  ['Shop', [
    ['home', 'Homepage', 'Hero slider (dots and arrows mirrored), trust strip, every section heading in Arabic, Shop by skin concern carousel (next on the left, pointing left), bundles carousel, footer with the Arabic wordmark.'],
    ['shop', 'Shop all', 'Product grid mirrored, sort select caret on the left, Arabic product names and prices in د.إ.'],
    ['category', 'Category (Toners)', 'Category header and description in Arabic; grid and filters mirrored.'],
    ['brands', 'All brands', 'Brand names in the owner\'s Arabic spellings (kbeautyarabia list).'],
    ['brand', 'Brand (Anua → أنوا)', 'Brand header, Read more, grid.'],
    ['search', 'Search results', 'Searched in Arabic (أنوا); the query and results right-to-left.'],
    ['sale', 'Super sale', 'Struck prices and sale badges (-20% reads correctly).'],
  ]],
  ['Product', [
    ['product', 'Product (full Arabic copy)', 'Gallery thumbnails from the right, price/was-price/-20%, Tabby/Tamara blocks, bundle tiers in Arabic, qty + / −, Add to cart, delivery countdown with an Arabic date, tabs (Description bullets indented from the right), reviews (Arabic dates, rating bars filling from the right), you-may-also-like arrows.'],
    ['product-cream', 'Product (moisturiser)', 'Same checks on a second fully written product; Benefits / Made in Korea / Size / About the brand.'],
    ['product-sun', 'Product (sunscreen)', 'Units and SPF written in Arabic (عامل حماية 50+ وحماية ++++، 50 مل).'],
    ['set', 'Set (Glass Skin Starter Set)', 'Set contents rows and You save line.'],
  ]],
  ['Basket & checkout', [
    ['cart-panel', 'Cart panel (open)', 'Seven lines: picture on the right, × on the left, steppers mirrored, free-delivery bar filling from the right, coupon hint, Subtotal and the two buttons; the panel slides in from the left.'],
    ['cart', 'Cart page', 'Same seven lines, the recommended rail with arrows (next on the left; it scrolls the right way), summary and Secure checkout.'],
    ['checkout', 'Checkout', 'Signed-in customer: fields mirrored, the phone number kept left-to-right, emirate list in Arabic, delivery and payment list, summary.'],
    ['order-received', 'Order received', 'Order lines with Arabic names and brands, address with the phone kept left-to-right, What next links with left-pointing chevrons.'],
  ]],
  ['Account', [
    ['login', 'Sign in', 'Floating labels on the right, eye icon on the left.'],
    ['register', 'Create account', 'Form fields and the human check.'],
    ['forgot', 'Forgot password', ''],
    ['account', 'Dashboard', 'Recent orders with Arabic dates and statuses.'],
    ['orders', 'Orders', 'Arabic dates ("14 أكتوبر 2026 · منتجان") and status pills.'],
    ['order-detail', 'Order detail', 'Lines, totals, delivery and payment.'],
    ['addresses', 'Addresses', 'Address cards (type and country in Arabic, phone LTR) and the add form.'],
    ['wishlist', 'Wishlist', 'Three saved products.'],
    ['track', 'Track my order', ''],
  ]],
  ['Pages', [
    ['about', 'About us', ''], ['faqs', 'FAQs', 'Questions and answers, email links.'], ['delivery', 'Shipping & delivery', 'Lists indented from the right.'],
    ['returns', 'Returns', ''], ['privacy', 'Privacy policy', ''], ['terms', 'Terms', 'Numbered list from the right.'], ['contact', 'Contact us', 'Contact cards, form, social links.'],
    ['blog', 'Journal', 'Article cards with Arabic tags and dates.'], ['post', 'Journal article', 'Headings, list, in-article link to /ar.'], ['not-found', '404', ''],
  ]],
];

const sizes = (() => { try { return JSON.parse(fs.readFileSync('storage/ar-logs/standalone-sizes.json', 'utf8')); } catch (e) { return []; } })();
const kb = (lang, key) => (sizes.find((s) => s.lang === lang && s.key === key) || {}).kb;
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');

let rows = '';
for (const [g, items] of GROUPS) {
  rows += `<h2>${esc(g)}</h2><ul class="list">`;
  for (const [key, name, note] of items) {
    const a = fs.existsSync(path.join(OUT, `ar-${key}.html`)), e = fs.existsSync(path.join(OUT, `en-${key}.html`));
    rows += `<li><div class="nm">${esc(name)}</div><div class="go">${a ? `<a class="ar" href="ar-${key}.html" lang="ar">العربية<small>${kb('ar', key) || ''} KB</small></a>` : '<span class="miss">—</span>'}${e ? `<a class="en" href="en-${key}.html">English<small>${kb('en', key) || ''} KB</small></a>` : ''}</div>${note ? `<p>${esc(note)}</p>` : ''}</li>`;
  }
  rows += '</ul>';
}

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Arabic RTL Previews</title>
<meta name="robots" content="noindex">
<style>
:root{--bg:#fbf7f8;--card:#fff;--ink:#2a2228;--muted:#6f6469;--line:#eadfe3;--acc:#c13e63;--acc2:#1f7a4d}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#17131a;--card:#221c24;--ink:#f3edf0;--muted:#b3a8ad;--line:#3a2f3c;--acc:#ff8fb0;--acc2:#6fd3a0}}
:root[data-theme="dark"]{--bg:#17131a;--card:#221c24;--ink:#f3edf0;--muted:#b3a8ad;--line:#3a2f3c;--acc:#ff8fb0;--acc2:#6fd3a0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{max-width:980px;margin:0 auto;padding:24px 16px 64px}
h1{font-size:24px;margin:0 0 6px}h2{font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:28px 0 10px}
.lead{color:var(--muted);margin:0 0 14px;max-width:70ch}
.list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px}
.list li{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px}
.nm{font-weight:650;margin-bottom:8px}.go{display:flex;gap:8px;flex-wrap:wrap}
.go a{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-weight:600;border-radius:999px;padding:6px 12px;border:1px solid var(--line);color:var(--ink)}
.go a.ar{background:var(--acc);border-color:var(--acc);color:#fff;font-family:"Segoe UI",Tahoma,sans-serif}
.go small{font-weight:400;opacity:.75;font-size:11px}
.list p{margin:8px 0 0;color:var(--muted);font-size:13px}
.note{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:14px}
.note b{color:var(--acc2)}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
button{font:inherit;border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:999px;padding:6px 12px;cursor:pointer}
</style>
</head>
<body>
<div class="wrap">
<div class="top"><div><h1>Arabic RTL — first draft previews</h1>
<p class="lead">Every page of the shop in Arabic, right to left, next to its English twin. Each file is the page exactly as the shop renders it, saved with its styles, fonts, pictures and scripts inside, so it opens offline: tabs, accordions, carousels and arrows, the mobile menu, the cart panel, Read more. Nothing here talks to the shop — buttons that need the server (add to bag, place order) do nothing.</p></div>
<button type="button" id="t" aria-label="Switch light or dark">◐</button></div>
<p class="note"><b>Seen at 390px and 1280px.</b> Open any file and narrow the window to phone width. Round 3: the Arabic header is the real one now — the Arabic name with the lotus on its right, the search box, its suggestions and results in Arabic (hybrid search: Arabic or English words both find products). Screenshots and measurements: <code>docs/lane-ar-shots/round2/</code>.</p>
${rows}
</div>
<script>
(function(){var b=document.getElementById('t'),r=document.documentElement;try{var s=localStorage.getItem('arp-theme');if(s)r.setAttribute('data-theme',s);}catch(e){}
b.addEventListener('click',function(){var d=r.getAttribute('data-theme')==='dark'||(!r.getAttribute('data-theme')&&matchMedia('(prefers-color-scheme: dark)').matches);var n=d?'light':'dark';r.setAttribute('data-theme',n);try{localStorage.setItem('arp-theme',n);}catch(e){}});})();
</script>
</body>
</html>
`;
fs.mkdirSync(OUT, { recursive: true });
fs.writeFileSync(path.join(OUT, 'index.html'), html);
console.log('wrote', path.join(OUT, 'index.html'), Math.round(html.length / 1024) + ' KB');
