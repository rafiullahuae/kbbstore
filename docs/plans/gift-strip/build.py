# Homepage free-gifts strip -- previews (the owner, 6 Nov 2026, URGENT):
# "a section, similar to the Gift row we have on super sale, but keep the
# heading in center, on left side 3 floating products, 1 can go outside the box
# etc, and same on right side further products, the live products which we
# actually offer as gifts. show me multiple designs" ... "want a thin bar type
# box, under the main banner on homepage for this".
# The six live gifts (kbeautybliss.com/super-sale/ gift row, 6 Nov), cut out
# with ../home-hero/products/cutout.py.
import base64, io, json, os
from PIL import Image

D = os.path.dirname(os.path.abspath(__file__))


def cut(name, h=260):
    im = Image.open(os.path.join(D, 'products', name)).convert('RGBA')
    w = max(1, round(im.width * h / im.height))
    b = io.BytesIO()
    im.resize((w, h), Image.LANCZOS).save(b, 'WEBP', quality=84, method=6)
    return 'data:image/webp;base64,' + base64.b64encode(b.getvalue()).decode(), w, h


L = [('arencia.png', 'Arencia Vitamin C Booster Shot'), ('axisy.png', 'AXIS-Y Dark Spot Correcting Glow Serum'), ('deo.png', 'Medicube Vanilla & Pistachio Deodorant')]
R = [('guasha.png', 'Medicube PDRN Gua Sha Neck Cream'), ('eye-mask.png', 'Medicube PDRN Pink Collagen Jelly Eye Mask'), ('peel-pad.png', 'Medicube Red Succinic Acid Peeling Pad')]
REAL = {'arencia.png': 14, 'axisy.png': 14.5, 'deo.png': 11.5, 'guasha.png': 12, 'eye-mask.png': 12, 'peel-pad.png': 9}
IMG = {n: cut(n) for n, _ in L + R}

BASE = '''
*{box-sizing:border-box}html,body{margin:0;background:#fff}body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,sans-serif;padding:70px 0 40px}
.gs-wrap{max-width:1200px;margin:0 auto;padding:0 16px}
.gs{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;height:130px;border-radius:22px;padding:0 24px;isolation:isolate}
.gs-bg{position:absolute;inset:0;border-radius:inherit;overflow:hidden;z-index:-1}
.gs-mid{text-align:center;padding:0 18px;display:flex;flex-direction:column;align-items:center;gap:4px}
.gs-eye{font-weight:800;font-size:12px;letter-spacing:.2em}
.gs-h{margin:0;font-weight:800;font-size:clamp(28px,3.4vw,44px);line-height:1;letter-spacing:.02em;white-space:nowrap}
.gs-sub{font-weight:600;font-size:clamp(14px,1.4vw,18px);opacity:.9}
.gs-link{font-weight:800;font-size:13px;text-decoration:none;margin-top:2px}
.gs-side{position:relative;height:100%}
.gs-p{position:absolute;bottom:12px;filter:drop-shadow(0 12px 12px rgba(40,20,40,.22));animation:gsBob 6s ease-in-out infinite}
.gs-p img{display:block;height:100%;width:auto}
@keyframes gsBob{0%,100%{transform:translateY(0) rotate(var(--r,0deg))}50%{transform:translateY(-6px) rotate(calc(var(--r,0deg) + 2deg))}}
@media (prefers-reduced-motion:reduce){.gs-p,.gs-fx,.gs-shine{animation:none}}
/* TRUE SIZES (owner, 6 Nov: "the size of the products are very important in real life").
   Every gift is drawn at one scale, --k pixels per real centimetre, times its real height --h (cm):
   Arencia Vitamin C Booster Shot 30 ml tube 14 · AXIS-Y Glow Serum 50 ml airless tube 14.5 ·
   Medicube deodorant 83 g stick 11.5 · Medicube Gua Sha neck cream 50 ml 12 ·
   Medicube eye mask 6-pair pack 12 · Medicube peeling pad 70-pad jar 9 (9.1 x 9 x 8.9 cm). */
.gs{--k:10px}
.gs-p{height:calc(var(--k) * var(--h))}
.gs-p img{height:100%;width:auto}
.gs-l .gs-p:nth-child(1){left:8%;bottom:10px;--r:-8deg}
.gs-l .gs-p:nth-child(2){left:30%;bottom:6px;--r:4deg;animation-delay:-2s;z-index:1}
.gs-l .gs-p:nth-child(3){left:56%;bottom:12px;--r:9deg;animation-delay:-4s}
.gs-r .gs-p:nth-child(1){right:60%;bottom:10px;--r:-8deg;animation-delay:-1s}
.gs-r .gs-p:nth-child(2){right:26%;bottom:22px;--r:-3deg;animation-delay:-3s;z-index:1}
.gs-r .gs-p:nth-child(3){right:3%;bottom:12px;--r:6deg;animation-delay:-5s}
@media (max-width:767px){body{padding-top:50px}.gs{--k:6px;height:84px;padding:0 4px;border-radius:18px;grid-template-columns:31% minmax(0,1fr) 31%}
 .gs-mid{padding:0 2px;gap:2px}.gs-h{font-size:18px}.gs-sub{font-size:11.5px}.gs-link{font-size:11.5px}
 .gs-l .gs-p:nth-child(1){left:3%;bottom:6px}.gs-l .gs-p:nth-child(2){left:31%;bottom:3px}.gs-l .gs-p:nth-child(3){left:64%;bottom:8px}
 .gs-r .gs-p:nth-child(1){right:63%;bottom:12px;z-index:2}.gs-r .gs-p:nth-child(2){right:25%;bottom:6px;z-index:1}.gs-r .gs-p:nth-child(3){right:-3%;bottom:4px;z-index:2}}
'''

STYLES = {}
STYLES['S1'] = ('S1 · Mint Fresh', '''
.gs-bg{background:radial-gradient(60% 140% at 50% 0%,#ffffff,transparent 70%),linear-gradient(90deg,#e3f7ec,#f4fbf7 50%,#e3f7ec);border:1.5px solid #bfe6cf}
.gs{color:#1d4e35}.gs-eye{color:#2e8f62}.gs-link{color:#2e8f62}
''')
STYLES['S2'] = ('S2 · Blush Ribbon', '''
.gs-bg{background:linear-gradient(90deg,#ffe3ec,#fff4f7 50%,#ffe3ec)}
.gs-bg::before{content:"";position:absolute;left:0;right:0;top:50%;height:22px;transform:translateY(-50%);background:linear-gradient(180deg,#f7a7c1,#ec7fa5);opacity:.35}
.gs-mid{background:#fff;border-radius:999px;padding:10px 28px;box-shadow:0 10px 22px -12px rgba(236,58,128,.5)}
.gs{color:#3b1f2b}.gs-eye{color:#e3246e}.gs-link{color:#e3246e}
@media (max-width:767px){.gs-mid{padding:8px 12px}}
''')
STYLES['S3'] = ('S3 · Gold Ticket', '''
.gs-bg{background:linear-gradient(90deg,#fff3dc,#fffaf0 50%,#fff3dc)}
.gs-bg::before{content:"";position:absolute;inset:8px;border:2px dashed #d9b46a;border-radius:16px}
.gs::before,.gs::after{content:"";position:absolute;top:50%;width:26px;height:26px;border-radius:50%;background:#fff;transform:translateY(-50%);z-index:0}
.gs::before{left:-13px}.gs::after{right:-13px}
.gs{color:#3d2a08}.gs-eye{color:#a87a22}.gs-link{color:#a87a22}
''')
STYLES['S4'] = ('S4 · Plum Night', '''
.gs-bg{background:radial-gradient(40% 120% at 50% 50%,rgba(255,79,154,.35),transparent 70%),linear-gradient(90deg,#2a0f3d,#3d1452 50%,#2a0f3d)}
.gs-bg::before{content:"";position:absolute;inset:0;background:radial-gradient(circle,#FFD36E 0 1.2px,transparent 2.2px) 0 0/34px 34px;opacity:.35}
.gs{color:#fff}.gs-eye{color:#FFD36E}.gs-sub{opacity:.8}.gs-link{color:#FFD36E}
.gs-p{filter:drop-shadow(0 0 14px rgba(255,211,110,.35)) drop-shadow(0 12px 12px rgba(0,0,0,.35))}
''')
STYLES['S5'] = ('S5 · Pastel Shimmer', '''
.gs-bg{background:linear-gradient(90deg,#ffe0ef,#efe4ff,#ffe9da)}
.gs-fx{position:absolute;inset:-60% -10%;background:radial-gradient(25% 60% at 20% 50%,#ffc2dc,transparent 70%),radial-gradient(25% 60% at 80% 50%,#d6c6ff,transparent 70%),radial-gradient(20% 60% at 50% 50%,#ffd9bf,transparent 70%);animation:gsSpin 18s linear infinite;opacity:.9}
.gs-shine{position:absolute;inset:0;background:linear-gradient(100deg,transparent 30%,rgba(255,255,255,.6) 45%,transparent 60%);transform:translateX(-100%);animation:gsShine 6s ease-in-out infinite}
@keyframes gsSpin{to{transform:rotate(360deg)}}@keyframes gsShine{60%,100%{transform:translateX(100%)}}
.gs{color:#2a1033}.gs-eye{color:#c0246b}.gs-link{color:#c0246b}
''')


def side(items, cls):
    out = '<div class="gs-side %s">' % cls
    for i, (n, alt) in enumerate(items):
        src, w, h = IMG[n]
        out += '<div class="gs-p" style="--h:%s"><img src="%s" alt="%s" width="%d" height="%d" loading="lazy" decoding="async"></div>' % (REAL[n], src, alt, w, h)
    return out + '</div>'


def doc(key):
    name, css = STYLES[key]
    fx = '<div class="gs-fx"></div><div class="gs-shine"></div>' if key == 'S5' else ''
    html = ('<div class="gs-wrap"><section class="gs" aria-label="Free gifts"><div class="gs-bg">' + fx + '</div>'
            + side(L, 'gs-l')
            + '<div class="gs-mid"><h2 class="gs-h">FREE GIFTS</h2>'
              '<span class="gs-sub">with every order</span><a class="gs-link" href="#">See the gifts &rarr;</a></div>'
            + side(R, 'gs-r') + '</section></div>')
    return ('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;800&display=swap" rel="stylesheet">'
            '<style>' + BASE + css + '</style></head><body>' + html + '</body></html>')


docs = {k: doc(k) for k in STYLES}
names = {k: STYLES[k][0] for k in STYLES}
import sys
if len(sys.argv) > 1 and sys.argv[1] == 'shots':
    os.makedirs(os.path.join(D, 'v'), exist_ok=True)
    for k, v in docs.items():
        open(os.path.join(D, 'v', k + '.html'), 'w').write(v)
    sys.exit()
esc = lambda x: json.dumps(x).replace('</', '<\\/').replace('<!--', '<\\!--')
page = '''<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Free gifts strip</title>
<style>
:root{--bg:#f4f1f3;--ink:#2a2228;--soft:#6b5e66;--card:#fff;--line:#e6dde2}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#17131a;--ink:#f2ecef;--soft:#b3a7ad;--card:#221c25;--line:#3a3040}}
:root[data-theme="dark"]{--bg:#17131a;--ink:#f2ecef;--soft:#b3a7ad;--card:#221c25;--line:#3a3040}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 system-ui,-apple-system,sans-serif}
.wrap{max-width:1320px;margin:0 auto;padding:20px 16px 60px}h1{font-size:20px;margin:0 0 4px}.sub{color:var(--soft);margin:0 0 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin:0 0 22px}.card h2{font-size:16px;margin:0 0 10px}
.lab{font-size:12px;font-weight:700;letter-spacing:.06em;color:var(--soft);text-transform:uppercase;margin:6px 0}
.dt{width:100%;overflow:hidden;border-radius:10px;border:1px solid var(--line)}.dt iframe{width:1280px;height:250px;border:0;display:block;transform-origin:0 0}
.ph{width:390px;max-width:100%;border-radius:22px;border:8px solid #1d1820;overflow:hidden}.ph iframe{width:390px;height:210px;border:0;display:block}
</style></head><body><div class="wrap"><h1>Homepage free-gifts strip</h1>
<p class="sub">A thin box under the main banner: the heading in the centre, three of your live gifts floating on each side, the middle one on each side popping out above the box. The products are the six on your Super Sale gift row today; on the shop they come from Free gifts and change with it. Pick a style.</p>
<div id="list"></div></div><script>
var DOCS=__DOCS__,NAMES=__NAMES__,list=document.getElementById('list');
Object.keys(DOCS).forEach(function(k){var c=document.createElement('section');c.className='card';
c.innerHTML='<h2></h2><div class="lab">Computer</div><div class="dt"><iframe loading="lazy" title="computer"></iframe></div><div class="lab" style="margin-top:12px">Phone</div><div class="ph"><iframe loading="lazy" title="phone"></iframe></div>';
c.querySelector('h2').textContent=NAMES[k];var f=c.querySelectorAll('iframe');f[0].srcdoc=DOCS[k];f[1].srcdoc=DOCS[k];list.appendChild(c);});
function fit(){document.querySelectorAll('.dt').forEach(function(d){var s=Math.min(1,d.clientWidth/1280);d.querySelector('iframe').style.transform='scale('+s+')';d.style.height=(250*s)+'px';});}
window.addEventListener('resize',fit);fit();
</script></body></html>'''
page = page.replace('__DOCS__', esc(docs)).replace('__NAMES__', esc(names))
open(os.path.join(D, 'gift-strip-preview.html'), 'w').write(page)
print('ok', len(page))
