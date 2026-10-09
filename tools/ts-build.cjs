// Lane TS: builds docs/trust-strip-options/index.html, one self-contained page
// (no network requests: the shop's own Outfit font is inlined once) showing
// every trust-strip option at a phone width and a laptop width side by side,
// plus docs/trust-strip-options/costs.json.
//   node tools/ts-build.cjs [--artifact <out.html>]
// --artifact also writes a copy without the document skeleton and with the
// in-place screenshot gallery (shots/…), for publishing as a private page.
'use strict';
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { variants } = require('./ts-variants.cjs');

const ROOT = path.join(__dirname, '..');
const OUT = path.join(ROOT, 'docs', 'trust-strip-options');
const fontFile = fs.readdirSync(path.join(ROOT, 'public', 'build', 'assets')).find((f) => /^outfit-latin-[^e].*\.woff2$/.test(f));
const FONT = fs.readFileSync(path.join(ROOT, 'public', 'build', 'assets', fontFile)).toString('base64');

const min = (css) => css.replace(/\n/g, '');
const gz = (s) => zlib.gzipSync(Buffer.from(s), { level: 9 }).length;
const costs = variants.map((v) => ({
  id: v.id, title: v.title,
  html: Buffer.byteLength(v.markup), htmlGz: gz(v.markup),
  css: Buffer.byteLength(min(v.css)), cssGz: gz(min(v.css)),
  bothGz: gz(v.markup + min(v.css)),
}));
fs.writeFileSync(path.join(OUT, 'costs.json'), JSON.stringify(costs, null, 1) + '\n');

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const cost = (id) => costs.find((c) => c.id === id);

// The frame each variant is drawn in: a quiet stand-in for the page around it,
// so the strip is judged in context. Home family: banner above, a section
// heading below. Footer family: page content above, the footer's own pink help
// strip below (its real colours, from kbb.css .kft-help).
const FRAME_CSS = `*{box-sizing:border-box}html,body{margin:0;background:#fff;font-family:Outfit,system-ui,sans-serif;color:#2A2228;-webkit-font-smoothing:antialiased}
.bn{margin:10px 12px 0;height:var(--bh,150px);border-radius:16px;background:linear-gradient(120deg,#F3A9BE,#DE5A80 55%,#B83563);color:#fff;display:flex;flex-direction:column;justify-content:center;padding:0 22px;font-weight:700;font-size:var(--bf,20px);line-height:1.15}
.bn small{font-size:11px;letter-spacing:.14em;font-weight:600;opacity:.85;margin-bottom:6px}
.nx{text-align:center;padding:14px 12px 18px;font-weight:700;font-size:var(--nf,20px)}
.nx span{display:block;font-weight:400;font-size:13px;color:#756C74;margin-top:4px}
.pg{padding:16px 22px 22px;display:grid;grid-template-columns:repeat(var(--pc,2),1fr);gap:12px}
.pg i{display:block;height:var(--ph,70px);border-radius:12px;background:#FBF4F6;border:1px solid rgba(42,34,40,.06)}
.hp{color:#fff;background:linear-gradient(110deg,#E0567B,#C13E63,#E23A4E);padding:16px 22px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.hp b{font-size:var(--hf,17px)}.hp span{font-size:12px;opacity:.9;flex-basis:100%}
.hp em{font-style:normal;margin-inline-start:auto;background:#fff;color:#C13E63;border-radius:999px;padding:8px 16px;font-weight:600;font-size:13px}
.ft{padding:16px 22px;height:60px;background:#fff;color:#756C74;font-size:12px}`;

const frameBody = (v, laptop) => {
  const vars = laptop ? '--bh:260px;--bf:38px;--nf:30px;--pc:5;--ph:120px;--hf:22px' : '';
  if (v.family === 'home') {
    return `<div style="${vars}"><div class="bn"><small>HOMEPAGE BANNER</small>Your banner<br>sits here</div>${v.markup}<div class="nx">Big savings bundles<span>The next homepage section</span></div></div>`;
  }
  return `<div style="${vars}"><div class="pg"><i></i><i></i>${laptop ? '<i></i><i></i><i></i>' : ''}</div>${v.markup}<div class="hp"><b>Find your perfect K-beauty match</b><em>Chat on WhatsApp</em><span>The footer's own pink help strip, then the footer.</span></div><div class="ft">Footer…</div></div>`;
};

const DATA = variants.map((v) => ({ id: v.id, css: min(v.css), phone: frameBody(v, false), laptop: frameBody(v, true) }));

const card = (v) => {
  const c = cost(v.id);
  return `<article class="v" id="${v.id}">
  <header class="vh"><span class="tag">${v.id}</span><div><h3>${esc(v.title)}</h3><p>${esc(v.line)}</p></div>
  <dl class="cost"><div><dt>HTML</dt><dd>${c.htmlGz} B</dd></div><div><dt>CSS</dt><dd>${c.cssGz} B</dd></div><div><dt>JS</dt><dd>0</dd></div></dl></header>
  <div class="frames">
    <figure class="ph"><figcaption>Phone · 390 px</figcaption><div class="scr"><iframe title="${v.id} at 390 pixels" data-v="${v.id}" data-w="390" loading="eager"></iframe></div></figure>
    <figure class="lp"><figcaption>Laptop · 1280 px, scaled to fit</figcaption><div class="scr"><iframe title="${v.id} at 1280 pixels" data-v="${v.id}" data-w="1280" loading="eager"></iframe></div></figure>
  </div>
</article>`;
};

const SHOTS = [
  ['Homepage, under the banner', 'H', 'home'],
  ['Super Sale, under the page header', 'H', 'sale'],
  ['Above the footer · homepage', 'F', 'foot-home'],
  ['Above the footer · category', 'F', 'foot-category'],
  ['Above the footer · product page', 'F', 'foot-product'],
];
const gallery = () => `<section class="fam" id="in-place"><div class="famh"><h2>On the real pages</h2><p>Each option injected into the running shop preview, nothing in the shop changed. Phone shots are at 390 px, laptop at 1280 px.</p></div>
${SHOTS.map(([t, fam, place]) => `<h3 class="gt">${t}</h3><div class="gal">${variants.filter((v) => v.id[0] === fam).map((v) => `<figure><figcaption>${v.id} · ${esc(v.title)}</figcaption><div class="pair"><img src="shots/${v.id}-${place}-390.png" alt="${v.id} at 390 pixels, ${t}" loading="lazy"><img src="shots/${v.id}-${place}-1280.png" alt="${v.id} at 1280 pixels, ${t}" loading="lazy"></div></figure>`).join('')}</div>`).join('\n')}
</section>`;

const page = (artifact) => `${artifact ? '' : '<!doctype html>\n<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">\n'}<title>Trust Strip Options</title>
<style>
/* Layout: one long sheet of option cards, phone frame beside a scaled laptop frame; stacks on a phone. */
:root{--bg:#FFF8F5;--card:#FFFFFF;--ink:#2A2228;--ink-2:#5E545A;--muted:#756C74;--pink:#C6395F;--blush:#FCE0E8;--line:rgba(42,34,40,.10);
--display:Outfit,system-ui,sans-serif;--body:Outfit,system-ui,sans-serif;--mono:ui-monospace,SFMono-Regular,Menlo,monospace;color-scheme:light}
@font-face{font-family:Outfit;src:url(data:font/woff2;base64,${FONT}) format("woff2");font-weight:100 900;font-display:swap}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--body);font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased}
.page{max-width:1320px;margin:0 auto;padding-inline:16px;padding-block:28px 64px}
.top h1{font-family:var(--display);font-size:clamp(28px,4.4vw,44px);line-height:1.05;letter-spacing:-.02em;margin:0 0 10px;text-wrap:balance}
.top p{max-width:66ch;color:var(--ink-2);margin:0 0 8px}
.top .ask{border-inline-start:3px solid var(--pink);padding-inline-start:12px;font-style:italic;color:var(--ink-2)}
.jump{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 6px;padding:0;list-style:none}
.jump a{display:inline-block;padding:6px 12px;border-radius:999px;border:1px solid var(--line);background:var(--card);color:var(--ink);text-decoration:none;font-weight:600;font-size:13px}
.jump a:hover,.jump a:focus-visible{border-color:var(--pink);color:var(--pink);outline:none}
.fam{margin-top:44px}
.famh h2{font-family:var(--display);font-size:24px;margin:0 0 4px;letter-spacing:-.01em}
.famh p{margin:0 0 18px;color:var(--ink-2);max-width:72ch}
.v{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;margin-bottom:22px}
.vh{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:14px;align-items:start;margin-bottom:14px}
.tag{font-family:var(--display);font-weight:800;font-size:20px;color:#fff;background:var(--pink);border-radius:10px;padding:4px 10px;line-height:1.2}
.vh h3{margin:0;font-size:18px}
.vh p{margin:2px 0 0;color:var(--ink-2);font-size:14px;max-width:80ch}
.cost{display:flex;gap:6px;margin:0}
.cost div{background:var(--bg);border-radius:10px;padding:4px 10px;text-align:center}
.cost dt{font-size:10.5px;letter-spacing:.12em;color:var(--muted);font-weight:600}
.cost dd{margin:0;font-family:var(--mono);font-size:13px;font-variant-numeric:tabular-nums}
.frames{display:grid;grid-template-columns:390px minmax(0,1fr);gap:18px;align-items:start}
figure{margin:0;min-width:0}
figcaption{font-size:12px;letter-spacing:.06em;color:var(--muted);font-weight:600;margin-bottom:6px;text-transform:uppercase}
.scr{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff;position:relative}
.ph .scr{width:390px;max-width:100%}
.scr iframe{display:block;border:0;width:100%;height:340px}
.lp iframe{width:1280px;transform-origin:0 0}
.note{font-size:13px;color:var(--muted);margin-top:30px;max-width:80ch}
.gt{font-size:16px;margin:26px 0 10px}
.gal{display:grid;gap:16px}
.gal figure{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px}
.pair{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,3.3fr);gap:12px;align-items:start}
.pair img{width:100%;height:auto;border-radius:8px;border:1px solid var(--line);display:block}
table{border-collapse:collapse;font-size:14px;min-width:520px}
.tw{overflow-x:auto}
th,td{text-align:start;padding:7px 12px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
th{font-size:11.5px;letter-spacing:.1em;color:var(--muted);text-transform:uppercase}
td.n{font-family:var(--mono);text-align:end}
@media(max-width:860px){.frames{grid-template-columns:minmax(0,1fr)}.vh{grid-template-columns:auto minmax(0,1fr)}.cost{grid-column:1/-1}.pair{grid-template-columns:minmax(0,1fr)}}
</style>
${artifact ? '' : '</head><body>\n'}<main class="page">
<section class="top">
<h1>Trust strip options</h1>
<p class="ask">“We need this trust strip with same content, but make it super nice, clear sharp. Keep this under homepage banner, and in laptop also. Give multiple designs to choose from, and keep a totally different design above footer on all pages except cart and checkout pages.”</p>
<p><b>Homepage family, H1 to H4.</b> Under the homepage banner on phone and laptop, and the same strip under the Super Sale page header. The four items and their wording are exactly as in your screenshot.</p>
<p><b>Above-footer family, F1 to F3.</b> A different look, shown on every page except cart and checkout, directly above the footer's pink help strip. The short sub-lines in F1 and F3 are suggestions for you to approve or rewrite.</p>
<p>Nothing is live yet. Pick one letter from each family and that is what ships. Every option is HTML and CSS only: no script, no picture file, no extra font, and it costs the same on every page.</p>
<ul class="jump">${variants.map((v) => `<li><a href="#${v.id}">${v.id} · ${esc(v.title)}</a></li>`).join('')}${artifact ? '<li><a href="#in-place">On the real pages</a></li>' : ''}<li><a href="#costs">Byte costs</a></li></ul>
</section>
<section class="fam"><div class="famh"><h2>Homepage strip · H1 to H4</h2><p>Under the homepage banner, and reused under the Super Sale page header.</p></div>
${variants.filter((v) => v.family === 'home').map(card).join('\n')}
</section>
<section class="fam"><div class="famh"><h2>Above the footer · F1 to F3</h2><p>Every page except cart and checkout, directly above the footer's pink help strip, which is drawn below each one so you can see them together.</p></div>
${variants.filter((v) => v.family === 'foot').map(card).join('\n')}
</section>
${artifact ? gallery() : ''}
<section class="fam" id="costs"><div class="famh"><h2>Byte costs</h2><p>What each option adds to a page, gzipped, as it would be sent. None needs JavaScript; H2's sideways scroll on the phone is CSS only.</p></div>
<div class="tw"><table><thead><tr><th>Option</th><th>HTML</th><th>HTML gz</th><th>CSS</th><th>CSS gz</th><th>Both gz</th></tr></thead><tbody>
${costs.map((c) => `<tr><td><b>${c.id}</b> ${esc(c.title)}</td><td class="n">${c.html}</td><td class="n">${c.htmlGz}</td><td class="n">${c.css}</td><td class="n">${c.cssGz}</td><td class="n">${c.bothGz}</td></tr>`).join('\n')}
</tbody></table></div>
<p class="note">Bytes. The CSS figure is for a stand-alone rule set; inside the shop's existing stylesheet it would compress further. Icons are drawn with a 1.75 px line at every size. The banner, page blocks and footer around each strip on this page are simple stand-ins; the in-place screenshots${artifact ? ' above' : ' in shots/'} show the real pages.</p>
</section>
</main>
<script>
(function () {
  var F = ${JSON.stringify(FONT)};
  var D = ${JSON.stringify(DATA).replace(/</g, '\\u003c')};
  var head = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>@font-face{font-family:Outfit;src:url(data:font/woff2;base64,' + F + ') format("woff2");font-weight:100 900}' + ${JSON.stringify(FRAME_CSS)} + '</style>';
  var byId = {}; D.forEach(function (d) { byId[d.id] = d; });
  var frames = [].slice.call(document.querySelectorAll('iframe[data-v]'));
  function fit(f) {
    var w = +f.dataset.w, box = f.parentNode, s = w > 400 ? box.clientWidth / w : 1, h = 0;
    try { h = f.contentDocument.body ? f.contentDocument.body.scrollHeight : 0; } catch (e) { h = 0; }
    if (!h) h = w > 400 ? 520 : 360;
    f.style.height = h + 'px';
    if (w > 400) { f.style.transform = 'scale(' + s + ')'; box.style.height = Math.ceil(h * s) + 'px'; }
  }
  frames.forEach(function (f) {
    var d = byId[f.dataset.v];
    f.addEventListener('load', function () { fit(f); try { f.contentDocument.fonts.ready.then(function () { fit(f); }); } catch (e) {} });
    f.srcdoc = head + '<style>' + d.css + '</style>' + (+f.dataset.w > 400 ? d.laptop : d.phone);
  });
  if ('ResizeObserver' in window) { var ro = new ResizeObserver(function () { frames.forEach(fit); }); frames.forEach(function (f) { ro.observe(f.parentNode); }); }
})();
</script>
${artifact ? '' : '</body></html>\n'}`;

fs.writeFileSync(path.join(OUT, 'index.html'), page(false));
const ai = process.argv.indexOf('--artifact');
if (ai > 0) fs.writeFileSync(process.argv[ai + 1], page(true));
console.table(costs);
