// 2.60.508 PREVIEW as standalone files: the live product page, complete, with the
// detail tabs rebuilt (Description / Major Ingredients / How to Use / Shipping
// Policy / Return Policy), the 2.60.507 description pipeline + stylesheet, and the
// shop's own scripts inlined so tabs, accordion and Read more work from a file.
// Analytics, pixels and speculation rules are removed; a shim stops any request to
// the shop's /api (no views counted, no basket touched). Nothing on the shop changes.
const { execFileSync } = require('child_process');
const fs = require('fs');
const S = process.env.S, CSS_NEW = process.env.CSS_NEW;
const php = (f, input) => execFileSync('php', ['storage/cpb-logs/ds507/' + f], { input }).toString();
const curl = u => execFileSync('curl', ['-s', '-m', '30', u], { maxBuffer: 64 << 20 }).toString();
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
const SHIP = '<p><b>Shipping Policy</b></p><p><i>Sample text. The real policy is written once in Catalog → Product tabs → Shipping Policy and shows on every product.</i></p><ul><li>Free delivery across the UAE over AED 199.</li><li>Dubai &amp; Sharjah: next day. Other Emirates: 1–3 working days.</li></ul>';
const RET = '<p><b>Return Policy</b></p><p><i>Sample text. The real policy is written once in Catalog → Product tabs → Return Policy and shows on every product.</i></p><ul><li>Unopened items can be returned within 7 days of delivery.</li></ul>';
const JS = {};
function inlineJs(src) { if (!JS[src]) JS[src] = curl('https://kbeautybliss.com' + src).replace(/<\/script/gi, '<\\/script'); return JS[src]; }
function rebuild(html) {
  const a = html.indexOf('<div class="details" id="details">');
  const m = html.indexOf('<div class="macc">', a);
  const b = html.indexOf('\n</div>\n  </section>', m) + '\n</div>\n'.length;
  const block = html.slice(a, b);
  const titles = [...block.matchAll(/<button class="dtab[^"]*" type="button" data-i="\d+">([^<]*)<\/button>/g)].map(x => x[1]);
  const bodies = [...block.matchAll(/<div class="dtabpanel[^"]*" data-panel="\d+">\s*<div class="dcontent[^"]*">([\s\S]*?)<\/div>\s*<button class="readmore"/g)].map(x => x[1]);
  let desc = '', ingTitle = null, ing = '', how = '';
  titles.forEach((t, i) => { if (i === 0) desc = bodies[i] || ''; else if (/ingredient/i.test(t)) { ingTitle = t; ing = bodies[i] || ''; } else if (/how to use/i.test(t)) how = bodies[i] || ''; });
  desc = php('transform.php', desc);
  const split = JSON.parse(php('howto.php', desc));
  desc = split.description; if (!how) how = split.how;
  const tabs = [['Description', desc], [ingTitle || 'Ingredients', ing], ['How to Use', how], ['Shipping Policy', SHIP], ['Return Policy', RET]]
    .filter(([, body]) => body.replace(/<[^>]*>/g, '').trim() !== '');
  const bar = tabs.map(([t], i) => `<button class="dtab${i ? '' : ' on'}" type="button" data-i="${i}">${esc(t)}</button>`).join('');
  const panels = tabs.map(([, body], i) => `<div class="dtabpanel${i ? '' : ' on'}" data-panel="${i}"><div class="dcontent clamp">${body}</div><button class="readmore" type="button">Read more ↓</button></div>`).join('');
  const acc = tabs.map(([t, body], i) => `<div class="macc-i${i ? '' : ' open'}"><button class="macc-h" type="button" data-i="${i}">${esc(t)}<span class="pm">${i ? '+' : '−'}</span></button><div class="macc-b"><div class="dcontent open">${body}</div></div></div>`).join('');
  html = html.slice(0, a) + `<div class="details" id="details"><div class="dtabbar">${bar}</div><div class="dpanel" id="dpanel">${panels}</div><div class="macc">${acc}</div></div>\n` + html.slice(b);
  return { html, tabs: tabs.map(t => t[0]) };
}
const SHIM = `<script>(function(){var api=/\\/api\\/|google|facebook|tiktok|doubleclick|clarity|hotjar/i;var f=window.fetch;window.fetch=function(u,o){var s=String(u&&u.url||u);if(api.test(s)||/^\\//.test(s)&&!/\\.(css|js|png|jpe?g|webp|svg|woff2?)(\\?|$)/i.test(s))return Promise.resolve(new Response('{}',{status:200,headers:{'Content-Type':'application/json'}}));return f.apply(this,arguments)};if(navigator.sendBeacon)navigator.sendBeacon=function(){return true};var X=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u){this.__b=api.test(String(u));return X.apply(this,arguments)};var Sd=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.send=function(){if(this.__b)return;return Sd.apply(this,arguments)};})();</script>`;
const BANNER = `<div style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;background:#1a1a1a;color:#fff;font:600 12px/1.4 system-ui,sans-serif;padding:8px 14px;text-align:center">PREVIEW of patch 2.60.508 — tabs: Description · Major Ingredients · How to Use · Shipping Policy · Return Policy. Not the live shop; buttons that need the server (add to bag, reviews) do nothing here.</div>`;
for (const slug of process.env.SLUGS.split(',')) {
  let html = curl(`https://kbeautybliss.com/product/${slug}/?preview=${Date.now()}`);
  const r = rebuild(html); html = r.html;
  html = html.replace(/<script type="application\/ld\+json">[\s\S]*?<\/script>/g, '');
  html = html.replace(/<script data-r=[\s\S]*?<\/script>/g, '');
  html = html.replace(/<script>[^<]*?(fbq|gtag|dataLayer|ttq|clarity)[\s\S]*?<\/script>/g, '');
  html = html.replace(/<noscript>[\s\S]*?<\/noscript>/g, '');
  html = html.replace(/<link rel="(?:modulepreload|preload|prefetch|dns-prefetch|preconnect)"[^>]*>/g, '');
  html = html.replace(/<link rel="stylesheet" href="\/build\/assets\/kbb-product-[^"]+\.css"[^>]*>/, `<style>${fs.readFileSync('public/build/assets/' + CSS_NEW, 'utf8')}</style>`);
  html = html.replace(/<script type="module" src="([^"]+)"><\/script>/g, (x, src) => `<script type="module">${inlineJs(src)}</script>`);
  // The shop's font, inside the file: a font from another site needs permission a saved file does not have.
  html = html.replace(/url\(\/build\/assets\/(outfit-latin(?:-ext)?-[A-Za-z0-9_-]+\.woff2)\)/g, (x, f) => fs.existsSync('public/build/assets/' + f) ? `url(data:font/woff2;base64,${fs.readFileSync('public/build/assets/' + f).toString('base64')})` : x);
  html = html.replace(/<head>/, `<head>\n<base href="https://kbeautybliss.com/">\n${SHIM}`);
  html = html.replace(/<title>([^<]*)<\/title>/, '<title>PREVIEW · $1</title>');
  html = html.replace(/<\/body>/, BANNER + '</body>');
  const out = `docs/tabs-preview-508/${slug}.html`;
  fs.writeFileSync(out, html);
  console.log(out, Math.round(html.length / 1024) + ' KB', JSON.stringify(r.tabs), 'kbb-product link left:', /kbb-product-[^"]+\.css/.test(html.match(/<link[^>]*kbb-product[^>]*>/)?.[0] || ''));
}
