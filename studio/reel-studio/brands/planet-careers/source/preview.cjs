// Planet Careers brand sheet -> brands/planet-careers/preview.png: every logo file on light and dark, the palette with
// contrast ratios, the type. node source/preview.cjs (from brands/planet-careers; needs playwright + chromium)
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const D = path.join(__dirname, '..'), kit = JSON.parse(fs.readFileSync(path.join(D, 'kit.json'), 'utf8'));
const u = (f) => 'file://' + path.join(D, f);
const relL = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((c) => (c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4)).reduce((s, c, i) => s + c * [.2126, .7152, .0722][i], 0);
const cr = (a, b) => { const x = relL(a), y = relL(b); return ((Math.max(x, y) + .05) / (Math.min(x, y) + .05)).toFixed(1); };
const C = kit.colors, sw = [['Primary · navy', C.primary], ['Secondary / accent · red', C.secondary], ['White', '#FFFFFF'], ['Soft', C.soft], ['Slate · small print', C.slate], ['Black', '#000000']];
const tile = (bg, f, h, lab) => `<figure style="background:${bg}"><img src="${u(f)}" style="height:${h}px"><figcaption>${lab}</figcaption></figure>`;
const html = `<!doctype html><meta charset="utf-8"><style>
@font-face{font-family:Afacad;src:url(${u('fonts/afacad-latin.woff2')}) format('woff2');font-weight:400 700}
body{margin:0;padding:36px;width:1528px;background:#fff;font:400 18px Afacad,sans-serif;color:${C.primary}}
h1{font-size:44px;font-weight:700;margin:0}h2{font-size:22px;font-weight:600;margin:30px 0 12px;color:${C.slate};text-transform:uppercase;letter-spacing:.12em}
p{margin:4px 0 0;color:${C.slate}}.g{display:grid;gap:16px}.g2{grid-template-columns:1fr 1fr}.g4{grid-template-columns:repeat(4,1fr)}.g6{grid-template-columns:repeat(6,1fr)}
figure{margin:0;border-radius:14px;padding:22px;display:flex;flex-direction:column;align-items:center;gap:12px;border:1px solid #DDE4EE}
figcaption{font-size:15px;color:${C.slate};background:#fff;border-radius:6px;padding:2px 8px}
.sw{border-radius:12px;border:1px solid #DDE4EE;overflow:hidden}.sw div{height:90px}.sw b{display:block;padding:8px 10px 0;font-weight:700}.sw span{display:block;padding:0 10px 10px;font-size:14px;color:${C.slate}}
.ty{display:flex;gap:28px;align-items:baseline}.btn{background:${C.secondary};color:#fff;border-radius:999px;padding:10px 26px;font-weight:700}
</style>
<h1>Planet Careers · brand kit</h1><p>From theplanetcareers.com (the owner, 16 Nov 2026). The logo is the site's own, unmixed from its white ground and traced (source/make-logos.py) — never redrawn.</p>
<h2>Logo</h2><div class="g g2">${tile('#FFFFFF', 'logo.svg', 150, 'logo.svg / logo.png · light grounds')}${tile(C.primary, 'logo-white.svg', 150, 'logo-white.svg / .png · navy')}
${tile(C.soft, 'logo.png', 150, 'logo.png on soft')}${tile('#000000', 'logo-white.png', 150, 'logo-white.png on black')}</div>
<h2>Mark &amp; icons</h2><div class="g g4">${tile('#FFFFFF', 'logo-mark.svg', 150, 'logo-mark.svg')}${tile(C.primary, 'logo-mark-white.svg', 150, 'logo-mark-white.svg')}
${tile('#EEF1F5', 'logo-app-icon.svg', 150, 'logo-app-icon.svg / .png')}${tile('#EEF1F5', 'logo-app-icon-light.svg', 150, 'logo-app-icon-light.svg / .png')}</div>
<div class="g g4" style="margin-top:16px">${tile('#FFFFFF', 'favicon.png', 64, 'favicon.png · the site’s, as served (32 px)')}</div>
<h2>Colours</h2><div class="g g6">${sw.map(([n, h]) => `<div class="sw"><div style="background:${h}"></div><b>${h}</b><span>${n}<br>on white ${cr(h, '#FFFFFF')}:1 · on navy ${cr(h, C.primary)}:1</span></div>`).join('')}</div>
<h2>Type · Afacad (headings 700, secondary 600, body 400) · Cairo for Arabic</h2>
<div class="ty"><span style="font-size:64px;font-weight:700">Study in Canada</span><span style="font-size:34px;font-weight:600">Visa file preparation</span><span style="font-size:22px">Rules change — we confirm the official requirements.</span><span class="btn">Book your free consultation</span></div>`;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1600, height: 900 } });
  await p.goto('file://' + path.join(D, 'source')); await p.setContent(html, { waitUntil: 'load' }); await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: path.join(D, 'preview.png'), fullPage: true }); await b.close(); console.log('preview.png');
})();
