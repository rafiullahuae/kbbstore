/*
 * Lane AR round 2 -- LIVE STANDALONE PREVIEWS, Arabic beside English.
 *
 *   node tools/arr-standalone.cjs <port> [outDir=docs/ar-preview]
 *
 * Reads the SERVER-RENDERED HTML tools/arr-shots.cjs saved for every page
 * (storage/ar-logs/html/<lang>-<key>.html -- the page exactly as the shop sent
 * it, signed in, with the seven-line basket) and makes each one a single file
 * that works when opened from disk:
 *
 *   - every stylesheet inlined, with the Outfit and Cairo faces as data: URIs;
 *   - the shop's own scripts inlined as <script type="module"> (the bundles
 *     carry no static imports -- checked: the one dynamic import is the admin
 *     editor, which a shopper never reaches);
 *   - every picture the page names embedded (the product photos are 800px
 *     WebP, ~16 KB each), srcset dropped so nothing reaches for a network copy;
 *   - a shim that answers every /api call, beacon and analytics request with
 *     an empty success, so a click that would talk to the server does nothing
 *     instead of throwing;
 *   - links between previewed pages rewritten to the sibling files;
 *   - a slim fixed banner: "PREVIEW — Arabic RTL draft (not live)".
 *
 * The technique is tools/tabs508-standalone.cjs's, pointed at the local
 * preview, where every same-origin asset is one request away.
 */
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '9466';
const OUT = process.argv[3] || 'docs/ar-preview';
const BASE = `http://127.0.0.1:${PORT}`;
const HTML_DIR = 'storage/ar-logs/html';
const IDS = JSON.parse(fs.readFileSync('storage/ar-logs/preview-ids.json', 'utf8'));

/* key => [path, opens-on-load] -- the same keys tools/arr-shots.cjs saves. */
const PAGES = {
  home: '/', shop: '/shop/', category: '/product-category/toners/', brands: '/brands/', brand: '/brands/anua/',
  product: `/product/${IDS.hero}/`, 'product-cream': `/product/${IDS.cream}/`, 'product-sun': `/product/${IDS.sun}/`,
  set: `/product/${IDS.set}/`, search: '/shop/?s=toner', sale: '/super-sale/', cart: '/cart/', 'cart-panel': null,
  checkout: '/checkout/', 'order-received': null, login: null, register: null, forgot: '/my-account/forgot/',
  account: '/my-account/', orders: '/my-account/orders/', 'order-detail': `/my-account/orders/${IDS.orders[0]}/`,
  addresses: '/my-account/edit-address/', 'address-edit': null, wishlist: '/my-wishlist/', track: '/track-my-order/',
  about: '/about/', faqs: '/faqs/', delivery: '/delivery/', returns: '/refund_returns/', privacy: '/privacy-policy/',
  terms: '/terms-and-conditions/', contact: '/contact-us/', blog: '/blog/', post: `/blog/${IDS.post}/`, 'not-found': null,
};
const SKIP_FILES = new Set(['mobile-menu', 'mega-menu', 'checkout-signin']);

const cache = new Map();
async function get(url, binary) {
  const k = (binary ? 'b:' : 't:') + url;
  if (cache.has(k)) return cache.get(k);
  let v = null;
  try {
    const r = await fetch(url.startsWith('http') ? url : BASE + url);
    if (r.ok) v = binary ? Buffer.from(await r.arrayBuffer()) : await r.text();
  } catch (e) { v = null; }
  cache.set(k, v);
  return v;
}
const mime = (p) => ({ '.webp': 'image/webp', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.svg': 'image/svg+xml', '.gif': 'image/gif', '.woff2': 'font/woff2' })[path.extname(p.split('?')[0]).toLowerCase()] || 'application/octet-stream';
async function dataUri(p) {
  const b = await get(p, true);
  return b ? `data:${mime(p)};base64,${b.toString('base64')}` : null;
}

const KEEP_FONTS = /\/build\/assets\/(outfit-latin(-ext)?|cairo-(arabic|latin|latin-ext))-[A-Za-z0-9_-]+\.woff2/;

async function inlineCss(css) {
  const urls = [...new Set([...css.matchAll(/url\((['"]?)(\/[^)'"]+)\1\)/g)].map((m) => m[2]))];
  for (const u of urls) {
    if (/\.woff2/.test(u) && !KEEP_FONTS.test(u)) continue;
    const d = await dataUri(u);
    if (d) css = css.split(u).join(d);
  }
  return css;
}

const SHIM = `<script>(function(){var R=function(){return Promise.resolve(new Response('{}',{status:200,headers:{'Content-Type':'application/json'}}))};var f=window.fetch;window.fetch=function(u){var s=String(u&&u.url||u);if(/^file:/.test(s)||/^(https?:)?\\/\\//.test(s)&&!/^data:/.test(s)||/^\\//.test(s)||/api|google|facebook|tiktok|clarity|hotjar|snap|pinterest/i.test(s))return R();return f.apply(this,arguments)};if(navigator.sendBeacon)navigator.sendBeacon=function(){return true};var O=XMLHttpRequest.prototype.open,S=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(){this.__kbbOff=true;return O.apply(this,arguments)};XMLHttpRequest.prototype.send=function(){if(this.__kbbOff)return;return S.apply(this,arguments)};try{Object.defineProperty(navigator,'serviceWorker',{value:{register:function(){return Promise.resolve({})},getRegistrations:function(){return Promise.resolve([])},ready:new Promise(function(){}),addEventListener:function(){}}})}catch(e){}})();</script>`;

function banner(lang) {
  const ar = lang === 'ar';
  const text = ar ? 'معاينة — مسودة العربية من اليمين إلى اليسار (ليست الموقع المباشر)' : 'PREVIEW — English twin of the Arabic RTL draft (not live)';
  return `<div style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;background:#1a1a1a;color:#fff;font:600 11.5px/1.35 system-ui,sans-serif;padding:6px 12px;text-align:center;pointer-events:none;opacity:.92" dir="${ar ? 'rtl' : 'ltr'}">PREVIEW — Arabic RTL draft (not live) · ${text} · <a href="index.html" style="color:#ffd1dc;pointer-events:auto">index</a></div>`;
}

/* The Arabic lockup (the owner, 15 October: "yes translate the logo also"),
   in the SAVED FILE ONLY -- the real change waits for the header lock's sweep. */
function arabicLogo(html) {
  return html.replace(/(<bdi class="lgx-w"[^>]*>)[\s\S]*?(<\/bdi>)/g, '$1كي بيوتي <em>بليس</em>$2')
    .replace(/(<small class="lgx-g"[^>]*>)[^<]*(<\/small>)/g, '$1العناية الكورية والمكياج$2');
}

async function build(lang, key) {
  const src = path.join(HTML_DIR, `${lang}-${key}.html`);
  if (!fs.existsSync(src)) return null;
  let html = fs.readFileSync(src, 'utf8');
  const pre = lang === 'ar' ? '/ar' : '';

  // Network-only head entries out: preloads, speculation rules, the Google Fonts link, pixels.
  html = html.replace(/<link rel="(?:modulepreload|preload|prefetch|dns-prefetch|preconnect)"[^>]*>/g, '');
  html = html.replace(/<script type="speculationrules">[\s\S]*?<\/script>/g, '');
  html = html.replace(/<link rel="stylesheet" href="https:\/\/fonts\.googleapis[^>]*>/g, '');
  html = html.replace(/<link rel="manifest"[^>]*>/g, '');

  // Stylesheets, inlined.
  for (const m of [...html.matchAll(/<link rel="stylesheet" href="([^"]+)"[^>]*>/g)]) {
    const css = await get(m[1]);
    const blk = css ? `<style>${(await inlineCss(css)).replace(/<\/style/gi, '<\\/style')}</style>` : '';
    // A function, never a string: a replacement STRING expands $& and $` inside minified code.
    html = html.replace(m[0], () => blk);
  }
  // url(/...) inside inline <style> blocks too (backgrounds, fonts).
  for (const m of [...html.matchAll(/<style([^>]*)>([\s\S]*?)<\/style>/g)]) {
    if (!/url\(['"]?\//.test(m[2])) continue;
    const blk = `<style${m[1]}>${await inlineCss(m[2])}</style>`;
    html = html.replace(m[0], () => blk);
  }
  // Scripts, inlined.
  for (const m of [...html.matchAll(/<script([^>]*) src="(\/[^"]+)"([^>]*)><\/script>/g)]) {
    const js = await get(m[2]);
    const attrs = (m[1] + m[3]).replace(/\s(defer|async)\b/g, '');
    const blk = js ? `<script${attrs}>${js.replace(/<\/script/gi, '<\\/script')}</script>` : '';
    html = html.replace(m[0], () => blk);
  }
  // Pictures: srcset out, src embedded.
  html = html.replace(/\s(srcset|imagesrcset|sizes|data-srcset|data-sizes)="[^"]*"/g, '');
  for (const m of [...new Set([...html.matchAll(/\b(?:src|poster|data-src|href)="(\/(?:uploads|img-cache|build|images|storage)[^"]+\.(?:webp|jpe?g|png|svg|gif))"/g)].map((x) => x[1]))]) {
    const d = await dataUri(m);
    if (d) html = html.split(`"${m}"`).join(`"${d}"`);
  }
  for (const m of [...new Set([...html.matchAll(/url\((?:&quot;|&#039;|')?(\/(?:uploads|img-cache)[^)'&]+)(?:&quot;|&#039;|')?\)/g)].map((x) => x[1]))]) {
    const d = await dataUri(m);
    if (d) html = html.split(m).join(d);
  }
  html = html.replace(/<video\b[^>]*>[\s\S]*?<\/video>/g, (v) => v.replace(/\ssrc="\/[^"]*"/g, ''));

  // Links between previewed pages, to the sibling files.
  for (const [k, p] of Object.entries(PAGES)) {
    if (!p || SKIP_FILES.has(k)) continue;
    const href = (pre + p).replace(/&/g, '&amp;');
    html = html.split(`href="${href}"`).join(`href="${lang}-${k}.html"`);
  }

  if (lang === 'ar') html = arabicLogo(html);

  // Cart panel: open on load, the way the bag icon opens it.
  if (key === 'cart-panel') {
    // The panel's own open state (overlay.js: `.drawer.on`, `#ov.on`), set
    // directly: a click would also ask the server for a fresh fragment, which
    // a saved file cannot answer, and the skeleton would replace the basket.
    html = html.replace(/<\/body>/, `<script>addEventListener('load',function(){setTimeout(function(){var d=document.querySelector('.drawer');if(d)d.classList.add('on');var o=document.getElementById('ov');if(o)o.classList.add('on');document.body.classList.add('kbb-locked');},250)})</script></body>`);
  }

  html = html.replace(/<head>/, `<head>\n${SHIM}`);
  html = html.replace(/<title>([^<]*)<\/title>/, '<title>PREVIEW · $1</title>');
  html = html.replace(/<\/body>/, banner(lang) + '</body>');
  return html;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const rows = [];
  for (const key of Object.keys(PAGES)) {
    for (const lang of ['ar', 'en']) {
      const html = await build(lang, key);
      if (!html) { console.log('MISSING', lang, key); continue; }
      const file = path.join(OUT, `${lang}-${key}.html`);
      fs.writeFileSync(file, html);
      const kb = Math.round(fs.statSync(file).size / 1024);
      rows.push({ lang, key, kb });
      console.log(`${lang}-${key}.html ${kb} KB`);
    }
  }
  fs.writeFileSync(path.join('storage/ar-logs', 'standalone-sizes.json'), JSON.stringify(rows, null, 1));
})();
