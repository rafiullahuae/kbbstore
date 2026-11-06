# Homepage occasion hero -- live previews (the owner, 6 Nov 2026).
# "I want also the main banner for the home page ... first banner should come
# these occasionally upon ON or selection, then the banner slider ... exact
# sizes we have currently ... more content as per the seo etc and offers ...
# super lightening ... editable ... background changing colors continues ...
# multiple designs as live previews ... desktop and mobile both."
#
# Measured on kbeautybliss.com (6 Nov): the slider is full width x 600px on a
# computer (548 at 1024, 411 at 768), and full width x 500px on a phone.
# Pure CSS: the moving colour is two composited layers (transform + opacity
# only, no repaint of the box per frame), stopped for prefers-reduced-motion.
import json, os

D = os.path.dirname(os.path.abspath(__file__))
MODEL = 'https://kbeautybliss.com/uploads/appearance/kbb-super-sale-model-mobile.webp'
P = 'https://kbeautybliss.com/img-cache/400/'
PRODUCTS = [P + 'wp-content/uploads/2026/04/Arencia-Vitamin-C-Booster-Shot.webp',
            P + 'wp-content/uploads/2024/12/Medicube-Red-Succinic-Acid-Peeling-Pad.webp',
            P + 'wp-content/uploads/2024/10/AXIS-Y-Dark-Spot-Correcting-Glow-Serum.webp']
TABBY = '<span class="hh-pm hh-tabby">tabby</span>'
TAMARA = '<span class="hh-pm hh-tamara">tamara</span>'

# Occasion palettes: every layout reads the same variables.
OCC = {
    '1111': dict(label='11.11 Mega Sale', num='11.11', title='MEGA SALE', h2='11.11 Mega Sale on Korean skincare in the UAE',
                 offer='UP TO 50% OFF', sub='Anua, COSRX, Medicube, Beauty of Joseon and more. Free gifts with every order and fast UAE delivery.',
                 cta='Shop the 11.11 sale',
                 c1='#ff3d8b', c2='#7c3aed', c3='#ff8a5c', bg='#1a0a2e', ink='#ffffff', acc='#FFD36E', btn='#ff3d8b', btnInk='#fff', dark=1),
    'uae': dict(label='UAE National Day', num='55', title='NATIONAL DAY SALE', h2='UAE National Day sale on Korean skincare',
                offer='UP TO 55% OFF', sub='Celebrating Eid Al Etihad with K-beauty favourites, free gifts with every order and fast UAE delivery.',
                cta='Shop the National Day sale',
                c1='#00a14b', c2='#E31B23', c3='#ffffff', bg='#063d22', ink='#ffffff', acc='#ffffff', btn='#E31B23', btnInk='#fff', dark=1),
    'ny': dict(label='New Year Sale', num='2027', title='NEW YEAR SALE', h2='New Year sale on Korean skincare in the UAE',
               offer='UP TO 50% OFF', sub='Start the year glowing: K-beauty bestsellers, free gifts with every order and fast UAE delivery.',
               cta='Shop the New Year sale',
               c1='#F5C969', c2='#6a5cff', c3='#ff8fc4', bg='#0b1026', ink='#ffffff', acc='#F5C969', btn='#F5C969', btnInk='#1a1230', dark=1),
    'super': dict(label='Super Sale', num='', title='SUPER SALE', h2='Super Sale on Korean skincare in the UAE',
                  offer='UP TO 50% OFF', sub='Perfect skincare to glow: free gifts with every order, fast UAE delivery, pay later with Tabby or Tamara.',
                  cta='Shop the Super Sale',
                  c1='#ffb3cf', c2='#ffd9c6', c3='#f7a6ff', bg='#fde8ef', ink='#2a2228', acc='#EC3A80', btn='#EC3A80', btnInk='#fff', dark=0),
}

BASE = '''
*{box-sizing:border-box}html,body{margin:0}body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,sans-serif}
.hh{position:relative;overflow:hidden;width:100%;height:500px;isolation:isolate;color:var(--ink);background:var(--bg);container-type:inline-size}
@media (min-width:768px){.hh{height:411px}}@media (min-width:1024px){.hh{height:548px}}@media (min-width:1280px){.hh{height:600px}}
/* the moving colour: two oversized layers, transform and opacity only */
.hh-fx,.hh-fx2{position:absolute;inset:-35%;z-index:-1;pointer-events:none;will-change:transform}
.hh-fx{background:radial-gradient(30% 30% at 25% 30%,var(--c1),transparent 70%),radial-gradient(28% 32% at 75% 65%,var(--c2),transparent 70%),radial-gradient(22% 22% at 60% 20%,var(--c3),transparent 70%);opacity:.75;animation:hhSpin 22s linear infinite}
.hh-fx2{background:radial-gradient(26% 26% at 70% 30%,var(--c3),transparent 70%),radial-gradient(30% 30% at 30% 75%,var(--c1),transparent 70%);opacity:0;animation:hhFade 9s ease-in-out infinite alternate,hhSpin 30s linear infinite reverse}
@keyframes hhSpin{to{transform:rotate(360deg)}}@keyframes hhFade{to{opacity:.7}}
@media (prefers-reduced-motion:reduce){.hh-fx,.hh-fx2{animation:none}}
.hh h2{margin:0;font:inherit}
.hh-pm{display:inline-block;font:800 13px/1 system-ui,sans-serif;padding:6px 10px;border-radius:7px}
.hh-tabby{background:#3BFFC1;color:#292929}.hh-tamara{background:linear-gradient(90deg,#f6d1a8,#e8c1f0,#b5d9f5);color:#000}
.hh-btn{display:inline-flex;align-items:center;gap:8px;background:var(--btn);color:var(--btnInk);text-decoration:none;font-weight:800;border-radius:999px;padding:14px 26px;font-size:16px;box-shadow:0 10px 24px -10px var(--btn)}
.hh-code{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:14px}.hh-code b{border:2px dashed var(--acc);border-radius:8px;padding:5px 12px;letter-spacing:.08em;background:rgba(255,255,255,.12)}
.hh-sub{opacity:.88;font-size:15px;line-height:1.5;max-width:46ch;margin:0}
.hh-num{font-weight:900;line-height:.85;letter-spacing:-.03em;color:var(--acc)}
.hh-dark .hh-num{text-shadow:0 0 18px color-mix(in srgb,var(--acc) 60%,transparent)}
'''

LAYOUTS = {}

# ------------------------------------------------ A: Spotlight (model + big number)
LAYOUTS['A'] = dict(name='A · Spotlight', css='''
.hA{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);align-items:center;padding:0 clamp(16px,5vw,90px)}
.hA .hh-copy{display:flex;flex-direction:column;gap:14px;z-index:1}
.hA .hh-num{font-size:clamp(64px,10.5vw,150px)}
.hA .hh-title{font-weight:900;font-size:clamp(30px,4.6vw,66px);letter-spacing:-.01em;line-height:1}
.hA .hh-offer{font-weight:800;font-size:clamp(18px,2vw,26px);color:var(--acc);letter-spacing:.04em}
.hA .hh-row{display:flex;flex-wrap:wrap;align-items:center;gap:14px}
.hA .hh-art{position:relative;height:100%;display:flex;align-items:flex-end;justify-content:center}
.hA .hh-art::before{content:"";position:absolute;bottom:0;width:78%;aspect-ratio:1;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.45),transparent 65%)}
.hA .hh-art img{position:relative;height:96%;width:auto;object-fit:contain}
.hA .hh-code-m{display:none}@media (max-width:767px){.hA .hh-code-m{display:inline-flex}}
.hA .hh-offer .hh-code{margin-inline-start:10px;font-size:13px;color:var(--ink)}
@media (max-width:1023px){.hA .hh-sub{display:none}.hA .hh-num{font-size:clamp(56px,9vw,96px)}.hA .hh-copy{gap:10px}}
@media (max-width:767px){.hA{grid-template-columns:1fr;align-items:start;padding:22px 16px 0}.hA .hh-copy{gap:8px}
 .hA .hh-art{position:absolute;right:-8%;bottom:0;height:56%;width:58%}.hA .hh-art img{height:100%}
 .hA .hh-btn{padding:11px 18px;font-size:14px}.hA .hh-row:last-child{position:absolute;left:16px;bottom:18px}.hA .hh-row:last-child .hh-code{display:none}
 .hA .hh-offer{display:flex;flex-wrap:wrap;align-items:center;gap:6px 0}}
''', html=lambda o: (
    '<section class="hh hA {dark}" style="{vars}"><div class="hh-fx"></div><div class="hh-fx2"></div>'
    '<div class="hh-copy"><div class="hh-row">' + TABBY + TAMARA + '</div>'
    + ('<div class="hh-num" aria-hidden="true">{num}</div>' if o['num'] else '')
    + '<h2><span class="hh-title">{title}</span></h2><div class="hh-offer">{offer}<span class="hh-code hh-code-m">Code <b>GLOW</b></span></div><p class="hh-sub">{sub}</p>'
    '<div class="hh-row"><a class="hh-btn" href="#">{cta} &rarr;</a><span class="hh-code">Code <b>GLOW</b></span></div></div>'
    '<div class="hh-art"><img src="' + MODEL + '" alt="" width="381" height="500" fetchpriority="high" decoding="async"></div></section>'))

# ------------------------------------------------ B: Offer Stack (three offer tiles)
LAYOUTS['B'] = dict(name='B · Offer Stack', css='''
.hB{display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;gap:16px;padding:24px clamp(16px,5vw,80px)}
.hB .hh-head{display:flex;align-items:baseline;justify-content:center;flex-wrap:wrap;gap:0 18px}
.hB .hh-num{font-size:clamp(56px,9vw,128px)}
.hB .hh-title{font-weight:900;font-size:clamp(28px,4.4vw,64px);line-height:1}
.hB .hh-sub{margin:0 auto}
.hB .hh-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;width:min(100%,880px)}
.hB .hh-tile{border-radius:18px;padding:16px 14px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}
.hB:not(.hh-dark) .hh-tile{background:rgba(255,255,255,.7);border-color:rgba(236,58,128,.25)}
.hB .hh-tile b{display:block;font-size:clamp(17px,1.8vw,24px);font-weight:900;color:var(--acc)}.hB .hh-tile span{font-size:13px;opacity:.9}
.hB .hh-tile img{width:52px;height:52px;border-radius:50%;object-fit:cover;background:#fff;margin:0 2px -4px}
@media (max-width:767px){.hB{gap:12px;padding:20px 14px}.hB .hh-sub{font-size:13px}.hB .hh-tiles{grid-template-columns:1fr;gap:8px}.hB .hh-tile{padding:10px 12px;display:flex;align-items:center;gap:10px;text-align:start}
 .hB .hh-tile b{font-size:17px}.hB .hh-tile:nth-child(3){display:none}.hB .hh-btn{padding:12px 20px;font-size:15px}}
''', html=lambda o: (
    '<section class="hh hB {dark}" style="{vars}"><div class="hh-fx"></div><div class="hh-fx2"></div>'
    '<div class="hh-head">' + ('<span class="hh-num" aria-hidden="true">{num}</span>' if o['num'] else '') + '<h2><span class="hh-title">{title}</span></h2></div>'
    '<p class="hh-sub">{sub}</p>'
    '<div class="hh-tiles"><div class="hh-tile"><b>{offer}</b><span>K-beauty bestsellers</span></div>'
    '<div class="hh-tile"><b>FREE GIFTS</b><span>' + ''.join('<img src="%s" alt="" width="52" height="52" loading="lazy" decoding="async">' % u for u in PRODUCTS) + '</span></div>'
    '<div class="hh-tile"><b>PAY LATER</b><span>' + TABBY + ' ' + TAMARA + '</span></div></div>'
    '<div><a class="hh-btn" href="#">{cta} &rarr;</a> <span class="hh-code" style="margin-inline-start:12px">Code <b>GLOW</b></span></div></section>'))

# ------------------------------------------------ C: Split Showcase (products fan + words)
LAYOUTS['C'] = dict(name='C · Product Showcase', css='''
.hC{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:center;gap:20px;padding:0 clamp(16px,5vw,90px)}
.hC .hh-copy{display:flex;flex-direction:column;gap:14px;z-index:1}
.hC .hh-badge{align-self:flex-start;font-weight:800;letter-spacing:.12em;font-size:12px;padding:7px 12px;border-radius:999px;background:var(--acc);color:var(--bg)}
.hC .hh-title{font-weight:900;font-size:clamp(30px,4.8vw,70px);line-height:1}
.hC .hh-num{font-size:clamp(40px,5vw,72px);margin-inline-end:10px}
.hC .hh-offer{font-weight:800;font-size:clamp(18px,2vw,26px)}
.hC .hh-fan{position:relative;height:78%;display:flex;align-items:center;justify-content:center}
.hC .hh-fan img{position:absolute;height:auto;width:30%;max-width:240px;aspect-ratio:1;object-fit:cover;border-radius:24px;background:#fff;box-shadow:0 24px 40px -18px rgba(0,0,0,.45)}
.hC .hh-fan img:nth-child(1){transform:translateX(-62%) rotate(-10deg)}.hC .hh-fan img:nth-child(2){transform:translateY(-6%) scale(1.15);z-index:1}.hC .hh-fan img:nth-child(3){transform:translateX(62%) rotate(10deg)}
.hC .hh-pay{display:flex;gap:8px;align-items:center;font-size:13px;font-weight:700}
@media (max-width:767px){.hC{grid-template-columns:1fr;grid-template-rows:auto 1fr;align-items:start;padding:22px 16px 18px;gap:8px}.hC .hh-sub{display:none}.hC .hh-copy{gap:9px}
 .hC .hh-fan{height:100%;min-height:0;align-items:flex-end;padding-bottom:6px}.hC .hh-fan img{width:28%;max-width:120px;bottom:10px}.hC .hh-btn{padding:11px 18px;font-size:14px}
 .hC .hh-pay{display:none}.hC .hh-offer{font-size:16px}}
''', html=lambda o: (
    '<section class="hh hC {dark}" style="{vars}"><div class="hh-fx"></div><div class="hh-fx2"></div>'
    '<div class="hh-copy"><span class="hh-badge">{label}</span>'
    '<h2><span class="hh-title">' + ('<span class="hh-num" aria-hidden="true">{num}</span>' if o['num'] else '') + '{title}</span></h2>'
    '<div class="hh-offer">{offer} · FREE GIFTS</div><p class="hh-sub">{sub}</p>'
    '<div class="hh-pay">' + TABBY + TAMARA + '<span>Pay later</span></div>'
    '<div><a class="hh-btn" href="#">{cta} &rarr;</a></div></div>'
    '<div class="hh-fan">' + ''.join('<img src="%s" alt="" width="400" height="400" decoding="async">' % u for u in PRODUCTS) + '</div></section>'))


def doc(layout, occ):
    o = dict(OCC[occ])
    vars_ = ';'.join('--%s:%s' % (k, o[k]) for k in ('c1', 'c2', 'c3', 'bg', 'ink', 'acc', 'btn')) + ';--btnInk:' + o['btnInk']
    html = LAYOUTS[layout]['html'](o)
    for k in ('num', 'title', 'h2', 'offer', 'sub', 'cta', 'label'):
        html = html.replace('{' + k + '}', o[k])
    html = html.replace('{vars}', vars_).replace('{dark}', 'hh-dark' if o['dark'] else '')
    return ('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&display=swap" rel="stylesheet">'
            '<style>' + BASE + LAYOUTS[layout]['css'] + '</style></head><body>' + html + '</body></html>')


ORDER = [(l, o) for l in 'ABC' for o in ('1111', 'uae', 'ny', 'super')]
docs = {l + '-' + o: doc(l, o) for l, o in ORDER}
names = {l + '-' + o: LAYOUTS[l]['name'] + '  ·  ' + OCC[o]['label'] for l, o in ORDER}
import sys
if len(sys.argv) > 1 and sys.argv[1] == 'shots':
    os.makedirs(os.path.join(D, 'v'), exist_ok=True)
    for k, v in docs.items():
        open(os.path.join(D, 'v', k + '.html'), 'w').write(v)
    sys.exit()

page = '''<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Homepage occasion banner</title>
<style>
:root{--bg:#f4f1f3;--ink:#2a2228;--soft:#6b5e66;--card:#fff;--line:#e6dde2;--acc:#e4457c}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#17131a;--ink:#f2ecef;--soft:#b3a7ad;--card:#221c25;--line:#3a3040}}
:root[data-theme="dark"]{--bg:#17131a;--ink:#f2ecef;--soft:#b3a7ad;--card:#221c25;--line:#3a3040}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 system-ui,-apple-system,sans-serif}
.wrap{max-width:1320px;margin:0 auto;padding:20px 16px 60px}h1{font-size:20px;margin:0 0 4px}.sub{color:var(--soft);margin:0 0 12px}
.bar{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;position:sticky;top:0;background:var(--bg);padding:10px 0;z-index:3}
.bar button{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:99px;padding:7px 14px;font:inherit;font-weight:600;cursor:pointer}
.bar button[aria-pressed="true"]{background:var(--acc);border-color:var(--acc);color:#fff}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin:0 0 22px}.card h2{font-size:16px;margin:0 0 10px}
.lab{font-size:12px;font-weight:700;letter-spacing:.06em;color:var(--soft);text-transform:uppercase;margin:6px 0}
.dt{width:100%;overflow:hidden;border-radius:10px;border:1px solid var(--line)}.dt iframe{width:1280px;height:600px;border:0;display:block;transform-origin:0 0}
.ph{width:390px;max-width:100%;border-radius:22px;border:8px solid #1d1820;overflow:hidden}.ph iframe{width:390px;height:500px;border:0;display:block}
</style></head><body><div class="wrap">
<h1>Homepage occasion banner</h1>
<p class="sub">The first banner on the homepage, before your picture slider. Exact current sizes: computer full width x 600px, phone full width x 500px. The colours keep moving slowly (pure CSS, no video, no script). Every word is editable; the occasion switches the colours and words. Pick a layout letter.</p>
<div class="bar" id="bar"><button data-f="all" aria-pressed="true">All</button><button data-f="A">Layout A</button><button data-f="B">Layout B</button><button data-f="C">Layout C</button><button data-f="1111">11.11</button><button data-f="uae">National Day</button><button data-f="ny">New Year</button><button data-f="super">Super Sale</button></div>
<div id="list"></div></div>
<script>
var DOCS=__DOCS__,NAMES=__NAMES__,list=document.getElementById('list');
Object.keys(DOCS).forEach(function(k){var c=document.createElement('section');c.className='card';c.dataset.k=k;
c.innerHTML='<h2></h2><div class="lab">Computer (1280 x 600)</div><div class="dt"><iframe loading="lazy" title="computer"></iframe></div><div class="lab" style="margin-top:12px">Phone (390 x 500)</div><div class="ph"><iframe loading="lazy" title="phone"></iframe></div>';
c.querySelector('h2').textContent=NAMES[k];var f=c.querySelectorAll('iframe');f[0].srcdoc=DOCS[k];f[1].srcdoc=DOCS[k];list.appendChild(c);});
function fit(){document.querySelectorAll('.dt').forEach(function(d){var s=Math.min(1,d.clientWidth/1280);d.querySelector('iframe').style.transform='scale('+s+')';d.style.height=(600*s)+'px';});}
window.addEventListener('resize',fit);fit();
document.getElementById('bar').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;document.querySelectorAll('#bar button').forEach(function(x){x.setAttribute('aria-pressed',x===b)});var f=b.dataset.f;
document.querySelectorAll('#list .card').forEach(function(c){var p=c.dataset.k.split('-');c.style.display=(f==='all'||p[0]===f||p[1]===f)?'':'none'});});
</script></body></html>'''
esc = lambda x: json.dumps(x).replace('</', '<\\/').replace('<!--', '<\\!--')
page = page.replace('__DOCS__', esc(docs)).replace('__NAMES__', esc(names))
open(os.path.join(D, 'home-hero-preview.html'), 'w').write(page)
print('ok', len(page))
