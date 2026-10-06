/*
 * Lane FA: build docs/fa-preview/index.html from tools/fa-options.cjs and the
 * numbers tools/fa-shots.cjs measured (docs/fa-preview/measure.json).
 *
 *   node tools/fa-index.cjs docs/fa-preview [body-only-out.html]
 *
 * The second argument writes the same page without the document wrapper, for
 * publishing as an Artifact (the publisher adds its own <head>).
 */
const fs = require('fs');
const O = require('./fa-options.cjs');
const [out, bodyOut] = process.argv.slice(2);
const M = JSON.parse(fs.readFileSync(out + '/measure.json', 'utf8'));

const MOOD = {
  A: 'Quiet. One soft-pink pill on the footer\'s own white, solid pink Install button. The least change to the footer.',
  B: 'A blush-to-peach band across the full width, the three glyphs in white circles, a glossy pink button. Warm and obvious without shouting.',
  C: 'A small white card on cream with a tiny phone showing the app icon. Reads as "this is an app", the most product-like.',
  D: 'A frosted glass pill laid over the bottom of the big K-Beauty Bliss wordmark, the letters blurred through it. The most "beauty brand". It sits just above the copyright row by design.',
  E: 'A dark plum strip with champagne button and a small spaced caption. Elegant and premium; the strongest contrast with the rest of the footer.',
  F: 'Pastel glyph tiles, a pink button with a shine that runs three times, three tiny sparkles that twinkle and stop. Fun and young.',
};

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const h = (k) => (M[k] ? M[k].row : '–');
const sw = (k) => (M[k] ? `${M[k].scrollWidth}/${M[k].clientWidth}` : '–');
const addsNote = (k) => (M[k] && M[k].adds !== M[k].row ? ` <span class="dim">(adds ${M[k].adds})</span>` : '');
const ok = (k, cap) => (M[k] && M[k].row <= cap ? 'ok' : 'over');

// Tallest of the three lines, so the number holds whichever line he picks.
const most = (L, ar) => Math.max(...['', '-l2', '-l3'].map((x) => (M[`${L}-390${ar ? '-ar' : ''}${x}`] || { row: 0 }).row));
const rows = O.LETTERS.map((L) => `<tr><th scope="row"><a href="#opt-${L}">${L}</a></th><td>${O.NAMES[L]}</td><td class="num ${most(L, false) <= 64 ? 'ok' : 'over'}">${h(L + '-390')} <span class="dim">(max ${most(L, false)})</span>${addsNote(L + '-390')}</td><td class="num ${most(L, true) <= 64 ? 'ok' : 'over'}">${h(L + '-390-ar')} <span class="dim">(max ${most(L, true)})</span></td><td class="num ${ok(L + '-1280', 72)}">${h(L + '-1280')}${addsNote(L + '-1280')}</td><td class="num">${sw(L + '-390')} · ${sw(L + '-1280')}</td></tr>`).join('');

// Live rows: every option x line x language, pre-rendered, swapped by a tiny script.
const live = {};
for (const L of O.LETTERS) for (const lang of ['en', 'ar']) for (const i of [0, 1, 2]) live[`${L}-${lang}-${i}`] = (L === 'D' ? '<p class="live-name" aria-hidden="true">K-Beauty Bliss</p>' : '') + O.html(L, lang, i);

const shot = (f, alt, cls = '') => fs.existsSync(`${out}/shots/${f}.png`) ? `<figure class="${cls}"><img src="shots/${f}.png" alt="${esc(alt)}" loading="lazy"><figcaption>${esc(alt)}</figcaption></figure>` : '';

const sections = O.LETTERS.map((L) => {
  const lines = O.LINES[L].map(([en, ar], i) => `<li><span class="ln">${i + 1}</span><span class="en">${esc(en)}</span><span class="ar" dir="rtl" lang="ar">${esc(ar)}</span></li>`).join('');
  const arWide = fs.existsSync(`${out}/shots/${L}-1280-ar.png`) ? shot(`${L}-1280-ar`, `${L} · 1280 px · Arabic`, 'wide') : '';
  const extra = ['above', 'below'].map((w) => shot(`${L}-1280-${w}`, `${L} · 1280 px · ${w === 'above' ? 'above the copyright row' : 'below the copyright row'}`, 'wide') + shot(`${L}-390-${w}`, `${L} · 390 px · ${w === 'above' ? 'above the copyright row' : 'below the copyright row'}`, 'phone')).join('');
  return `
<section class="opt" id="opt-${L}">
  <header class="opt-hd"><span class="letter">${L}</span><div><h2>${O.NAMES[L]}</h2><p>${MOOD[L]}</p></div>
  <dl class="meas"><div><dt>Phone</dt><dd>${h(L + '-390')} px</dd></div><div><dt>Phone · AR</dt><dd>${h(L + '-390-ar')} px</dd></div><div><dt>Laptop</dt><dd>${h(L + '-1280')} px</dd></div></dl></header>
  <div class="live" data-opt="${L}">
    <div class="live-ctl" role="group" aria-label="Line and language for option ${L}">
      <button type="button" data-line="0" aria-pressed="true">Line 1</button><button type="button" data-line="1" aria-pressed="false">Line 2</button><button type="button" data-line="2" aria-pressed="false">Line 3</button>
      <span class="sep"></span><button type="button" data-lang="en" aria-pressed="true">EN</button><button type="button" data-lang="ar" aria-pressed="false">AR</button>
    </div>
    <div class="live-foot" dir="ltr">${live[`${L}-en-0`]}</div>
    <p class="dim small">Live row, drawn from the same code as the shots. Resize the window under 768 px to see the phone layout.</p>
  </div>
  <div class="shots">
    ${shot(`${L}-390`, `${L} · 390 px · in the real footer`, 'phone')}
    ${shot(`${L}-390-ar`, `${L} · 390 px · Arabic, right to left`, 'phone')}
    ${shot(`${L}-1280`, `${L} · 1280 px · in the real footer`, 'wide')}
    ${arWide}
    ${extra}
  </div>
  <h3>Three lines to choose from</h3>
  <ol class="lines">${lines}</ol>
</section>`;
}).join('');

const css = `
/* Layout: one reading column, a summary table, then one section per letter with its live row and in-place shots. */
:root{
  --paper:#FFFCFB; --card:#FFFFFF; --ink:#2A2228; --ink-2:#5E545A; --muted:#756C74; --rule:#F1E3E8; --pink:#C6395F; --pink-soft:#FFF0F4; --ok:#1F7A50; --warn:#B2452B; --chip:#FFF0F4;
  --sans:"Outfit",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; --ar:"Noto Sans Arabic","Geeza Pro","Segoe UI",Tahoma,sans-serif;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--paper:#1B1619;--card:#251E22;--ink:#F5ECEF;--ink-2:#D9CBD1;--muted:#B5A6AD;--rule:#3A2F35;--pink:#F07A9C;--pink-soft:#3A2430;--ok:#6FD3A1;--warn:#F2A08A;--chip:#3A2430;color-scheme:dark}}
:root[data-theme="dark"]{--paper:#1B1619;--card:#251E22;--ink:#F5ECEF;--ink-2:#D9CBD1;--muted:#B5A6AD;--rule:#3A2F35;--pink:#F07A9C;--pink-soft:#3A2430;--ok:#6FD3A1;--warn:#F2A08A;--chip:#3A2430;color-scheme:dark}
body{background:var(--paper);color:var(--ink);font:15px/1.55 var(--sans);margin:0}
.page{max-width:1100px;margin:0 auto;padding-inline:16px;padding-block:28px 64px;display:grid;gap:40px}
h1{font-size:clamp(28px,4vw,40px);line-height:1.1;margin:0;text-wrap:balance;letter-spacing:-.02em}
h2{font-size:22px;margin:0;line-height:1.2}
h3{font-size:15px;margin:18px 0 8px}
.eyebrow{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--pink);font-weight:600;margin:0 0 8px}
.lede{max-width:68ch;color:var(--ink-2);margin:10px 0 0}
.dim{color:var(--muted)} .small{font-size:12.5px;margin:6px 0 0}
a{color:var(--pink)} a:focus-visible,button:focus-visible{outline:2px solid var(--pink);outline-offset:2px}
.tbl{overflow-x:auto;border:1px solid var(--rule);border-radius:12px;background:var(--card)}
table{border-collapse:collapse;width:100%;min-width:620px;font-size:14px}
th,td{padding:10px 12px;text-align:start;border-bottom:1px solid var(--rule)}
thead th{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:600}
tbody tr:last-child>*{border-bottom:0}
.num{font-variant-numeric:tabular-nums}
td.ok{color:var(--ok)} td.over{color:var(--warn)}
.opt{display:grid;gap:14px;padding-top:28px;border-top:1px solid var(--rule)}
.opt-hd{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;align-items:start}
.opt-hd p{margin:4px 0 0;color:var(--ink-2);max-width:68ch}
.letter{width:48px;height:48px;border-radius:14px;background:var(--pink);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:24px}
.meas{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px;margin:4px 0 0}
.meas div{background:var(--chip);border-radius:99px;padding:4px 12px;display:flex;gap:6px;font-size:13px}
.meas dt{color:var(--muted)} .meas dd{margin:0;font-weight:600;font-variant-numeric:tabular-nums}
.live{background:var(--card);border:1px solid var(--rule);border-radius:14px;padding:12px;display:grid;gap:10px;min-width:0}
.live-ctl{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.live-ctl button{font:500 13px/1 var(--sans);border:1px solid var(--rule);background:var(--card);color:var(--ink);border-radius:99px;padding:7px 12px;cursor:pointer}
.live-ctl button[aria-pressed="true"]{background:var(--pink);border-color:var(--pink);color:#fff}
.live-ctl .sep{width:1px;height:20px;background:var(--rule);margin-inline:4px}
.live-foot{background:#FFFFFF;border-radius:10px;overflow:hidden;border:1px solid #F1E3E8;--sans:"Outfit",system-ui,sans-serif}
.live-foot .kft-wrap{padding-inline:16px}
.live-name{margin:0;padding-top:8px;text-align:center;font:800 clamp(40px,9vw,96px)/.86 "Outfit",system-ui,sans-serif;letter-spacing:-.045em;color:#F5A3B8;white-space:nowrap;overflow:hidden}
.live-foot[dir="rtl"] .kfa{font-family:var(--ar)}
.shots{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.shots figure{margin:0;display:grid;gap:4px;min-width:0}
.shots figure.wide{grid-column:1/-1}
.shots img{width:100%;height:auto;display:block;border:1px solid var(--rule);border-radius:10px;background:#fff}
.shots figure.phone img{max-width:390px}
figcaption{font-size:12.5px;color:var(--muted)}
.lines{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.lines li{display:grid;grid-template-columns:28px minmax(0,1fr) minmax(0,1fr);gap:12px;align-items:baseline;background:var(--card);border:1px solid var(--rule);border-radius:10px;padding:8px 12px}
.lines .ln{font-weight:700;color:var(--pink)}
.lines .ar{font-family:var(--ar);text-align:right}
@media (max-width:640px){.lines li{grid-template-columns:24px minmax(0,1fr)}.lines .ar{grid-column:2}.shots{grid-template-columns:minmax(0,1fr)}}
.prose{display:grid;gap:12px;max-width:76ch}
.prose h2{margin-top:6px}
.prose p,.prose li{color:var(--ink-2)} .prose b{color:var(--ink)}
.prose ul{margin:0;padding-inline-start:20px;display:grid;gap:6px}
.pair{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
@media (max-width:640px){.pair{grid-template-columns:minmax(0,1fr)}}
.pair figure{margin:0;display:grid;gap:4px} .pair img{width:100%;max-width:390px;border:1px solid var(--rule);border-radius:10px}
.wideshot img{width:100%;border:1px solid var(--rule);border-radius:10px}
.wideshot{margin:0;display:grid;gap:4px}
.note{background:var(--pink-soft);border-radius:12px;padding:12px 14px;color:var(--ink)}
`;

const body = `
<title>Footer App Row</title>
<style>${css}
/* the six options, exactly as the shots use them */
${O.CSS.base}${O.LETTERS.map((L) => O.CSS[L]).join('')}
</style>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap">
<main class="page">
<header>
  <p class="eyebrow">K-Beauty Bliss · footer · preview only</p>
  <h1>A thin "get the app" row at the end of the footer</h1>
  <p class="lede">Six looks, A to F. Each one is drawn into the real footer of the shop and photographed at 390 px (iPhone) and 1280 px (laptop), in English and Arabic. Nothing is on the shop yet. Pick a letter, a line (1, 2 or 3) and where it sits, and that is what gets built.</p>
</header>

<section aria-labelledby="sum-h" style="display:grid;gap:10px">
  <h2 id="sum-h">Measured</h2>
  <div class="tbl"><table>
    <thead><tr><th>Option</th><th>Look</th><th>Phone 390 (≤ 64)</th><th>Phone AR</th><th>Laptop 1280 (≤ 72)</th><th>scrollWidth / viewport</th></tr></thead>
    <tbody>${rows}</tbody>
  </table></div>
  <p class="dim small">Heights in CSS pixels, taken in Chromium from the row's own box with line 1; (max) is the tallest of the three lines at 390 px. scrollWidth equals the viewport on every shot, so no option makes the page scroll sideways. D overlaps the wordmark, so it adds fewer pixels to the footer than its own height.</p>
</section>

${sections}

<section class="prose" id="place" aria-labelledby="place-h">
  <h2 id="place-h">Where it sits: below or above the copyright row</h2>
  <p><b>Below</b> is literally the last thing in the footer, as asked. On a phone, though, the floating WhatsApp button lives in the bottom corner, and at the very end of the page it covers the Install button (left shot). <b>Above</b> keeps the button clear and leaves the copyright and payment chips as the last line (right shot). Either can be built; below would need about 70 px of empty space under the row on phones to keep the button visible.</p>
  <div class="pair">
    ${['wa-below-390', 'wa-above-390'].map((n) => `<figure><img src="shots/${n}.png" alt="${n}" loading="lazy"><figcaption>${n === 'wa-below-390' ? 'Below the copyright row: WhatsApp button covers Install' : 'Above the copyright row: Install stays clear'}</figcaption></figure>`).join('')}
  </div>
  <p class="dim small">The "Hi there" greeting bubble is the shop's own and closes on its own tap; it appears in both shots because they were taken after it opened.</p>
</section>

<section class="prose" id="behave" aria-labelledby="behave-h">
  <h2 id="behave-h">What the button does</h2>
  <ul>
    <li><b>Android, Chrome (also Edge and Samsung Internet).</b> The tap opens the browser's own "Install app" dialog. The shop holds on to the browser's install offer (<code>beforeinstallprompt</code>) when it arrives and shows it from the tap. Chrome only makes that offer once its checks pass (the shop's app manifest and its service worker, both already on) and after she has used the page a little. If the offer has not come yet, was already turned down, or she is on Firefox, the tap opens a short sheet instead: "Tap ⋮, then Install app or Add to Home screen".</li>
    <li><b>iPhone and iPad.</b> No web page can install itself on Apple devices; there is no install dialog to call. The tap opens a small two-step sheet with pictures: <b>1. Tap Share</b> (on iOS 26 Safari, Share is inside the ••• button) <b>2. Add to Home Screen</b>. On iPad, Share is at the top right. Since iOS 26 a site added this way opens as a full-screen web app by default. The sheet text is in the page already, so opening it costs no request.</li>
    <li><b>Inside Instagram, TikTok or Facebook.</b> Their built-in browsers offer neither install nor Add to Home Screen. The sheet says "Open this page in Safari (or Chrome) first" with where that menu is.</li>
    <li><b>Already installed.</b> When she opens the shop from her Home Screen, the row is hidden by CSS alone (<code>display-mode: standalone</code>), backed by the check the Site App script already does for older iPhones.</li>
    <li><b>Laptop and desktop. Two choices:</b> (1) <b>show it</b>, and the tap opens a small QR panel, "Scan with your phone camera", so she continues on her phone; or (2) <b>hide it</b> on screens 1024 px and wider, which costs nothing. Chrome and Edge on a laptop could also install the shop as a laptop app from the same tap, if you would rather keep that.</li>
    <li><b>App → Site App switched off.</b> The row is never printed: no markup, no CSS cost, nothing to hide.</li>
  </ul>
  <div class="pair">
    <figure><img src="shots/sheet-ios-390.png" alt="iPhone two-step sheet, English" loading="lazy"><figcaption>iPhone sheet, English</figcaption></figure>
    <figure><img src="shots/sheet-ios-390-ar.png" alt="iPhone two-step sheet, Arabic" loading="lazy"><figcaption>iPhone sheet, Arabic</figcaption></figure>
  </div>
  <figure class="wideshot"><img src="shots/qr-desktop-1280.png" alt="Desktop QR panel" loading="lazy"><figcaption>Desktop choice 1: QR panel. The code drawn here is a mock; the real one would be generated by the shop once and cached.</figcaption></figure>
  <p class="note">The iPhone wording follows Apple's iOS 26 Safari layout. It could not be tried on a real iPhone from here, so check it once on your own phone before it ships.</p>
</section>

<section class="prose" id="cost" aria-labelledby="cost-h">
  <h2 id="cost-h">What it costs the shop</h2>
  <ul>
    <li><b>No request until tapped</b>, and the Android dialog and iPhone sheet need none after the tap either.</li>
    <li><b>No layout-measuring script.</b> Heights come from CSS. The tap handler is a few lines added to the Site App script the shop already loads after the page.</li>
    <li><b>About 1.5 KB of CSS</b> for the chosen letter. No images: the Apple, Android and iPad glyphs are small drawings in the page.</li>
    <li><b>Motion stops by itself.</b> F's shine runs three times and its sparkles four, then rest. Both are off for visitors who ask for less motion. D uses a glass blur, the heaviest of the six, but fine on current phones.</li>
    <li><b>Desktop QR</b> (if chosen) needs a small QR generator written into the shop, with no new package, printed once and cached.</li>
  </ul>
</section>

<section class="prose" id="copy" aria-labelledby="copy-h">
  <h2 id="copy-h">About the lines</h2>
  <ul>
    <li>Every line promises only what the shop already does. The alert lines (B2, C1, E3) describe notifications the Site App sends today: order updates, back in stock, a price drop on a wished item. They are true only while those notifications stay switched on.</li>
    <li>No discounts and no "app-only deals", because the shop has none. A line like "Extra 10% on your first app order" would need a real code behind it. Say so if you want one.</li>
    <li>The Arabic speaks to a woman (حمّلي، ثبّتي، شاشتكِ), which matches who shops here.</li>
    <li>In the Arabic shots the footer's link columns are in English because the preview database has no published Arabic footer text. On the live shop they show in Arabic.</li>
  </ul>
</section>
</main>
<script>
(function(){
  var LIVE = ${JSON.stringify(live).replace(/</g, '\\u003c')};
  document.querySelectorAll('.live').forEach(function(box){
    var st = {line:'0', lang:'en'}, L = box.getAttribute('data-opt'), foot = box.querySelector('.live-foot');
    box.addEventListener('click', function(e){
      var b = e.target.closest('button'); if(!b || !b.parentNode.classList.contains('live-ctl')) return;
      var k = b.hasAttribute('data-line') ? 'line' : 'lang';
      st[k] = b.getAttribute('data-' + k);
      box.querySelectorAll('[data-' + k + ']').forEach(function(x){ x.setAttribute('aria-pressed', String(x === b)); });
      foot.setAttribute('dir', st.lang === 'ar' ? 'rtl' : 'ltr');
      foot.innerHTML = LIVE[L + '-' + st.lang + '-' + st.line];
    });
  });
})();
</script>
`;

fs.writeFileSync(out + '/index.html', `<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width,initial-scale=1">\n${body.replace('<main', '</head>\n<body>\n<main')}\n</body>\n</html>\n`);
if (bodyOut) fs.writeFileSync(bodyOut, body);
console.log('wrote', out + '/index.html', Object.keys(live).length, 'live rows');
