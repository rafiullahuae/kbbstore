# Homepage occasion hero, round 2 (the owner, 6 Nov 2026: "don't follow the
# super sale style design etc. neither use small products grid or thumbnails
# etc. i want everything big").
# Sizes as measured on kbeautybliss.com: computer full width x 600 (548 at
# 1024, 411 at 768), phone full width x 500.
# Pure CSS movement (transform / opacity only), stopped for reduced motion.
import json, os, sys

D = os.path.dirname(os.path.abspath(__file__))
SITE = 'https://kbeautybliss.com'
PHOTO = SITE + '/img-cache/1920/uploads/banners/anua-pdrn-glow-skincare.webp'
PHOTO_M = SITE + '/img-cache/800/uploads/banners/anua-pdrn-collagen-glow-facial-serum-spray-100ml-066287-2.webp'
PRODUCT = SITE + '/img-cache/800/uploads/products/anua-pdrn-hyaluron-ultimate-glass-skin-set-2.webp'

OCC = {
    '1111': dict(label='11.11', num='11.11', title='MEGA SALE', offer='UP TO 50% OFF', line='Korean skincare bestsellers · Free gifts with every order',
                 cta='Shop the 11.11 sale', c1='#ff2e88', c2='#7c3aed', c3='#ff8a3d', bg='#14061f', ink='#fff', acc='#FFD36E', btn='#ff2e88', btnInk='#fff'),
    'uae': dict(label='UAE National Day', num='55', title='NATIONAL DAY SALE', offer='UP TO 55% OFF', line='Celebrating Eid Al Etihad · Free gifts with every order',
                cta='Shop the National Day sale', c1='#00a14b', c2='#E31B23', c3='#0c7a3a', bg='#04261a', ink='#fff', acc='#ffffff', btn='#E31B23', btnInk='#fff'),
    'ny': dict(label='New Year', num='2027', title='NEW YEAR SALE', offer='UP TO 50% OFF', line='Start the year glowing · Free gifts with every order',
               cta='Shop the New Year sale', c1='#F5C969', c2='#5b4bff', c3='#ff6fb1', bg='#070a1c', ink='#fff', acc='#F5C969', btn='#F5C969', btnInk='#1a1230'),
    'super': dict(label='Super Sale', num='', title='SUPER SALE', offer='UP TO 50% OFF', line='Korean skincare bestsellers · Free gifts with every order',
                  cta='Shop the Super Sale', c1='#ff4f93', c2='#ff9a6b', c3='#c86bff', bg='#2b0b1e', ink='#fff', acc='#ffd1e3', btn='#ffffff', btnInk='#d0246a'),
}

BASE = '''
*{box-sizing:border-box}html,body{margin:0}body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,sans-serif}
.hx{position:relative;overflow:hidden;width:100%;height:500px;isolation:isolate;color:var(--ink);background:var(--bg)}
@media (min-width:768px){.hx{height:411px}}@media (min-width:1024px){.hx{height:548px}}@media (min-width:1280px){.hx{height:600px}}
.hx-fx,.hx-fx2{position:absolute;inset:-40%;z-index:-1;pointer-events:none;will-change:transform}
.hx-fx{background:radial-gradient(28% 34% at 22% 30%,var(--c1),transparent 70%),radial-gradient(30% 34% at 78% 70%,var(--c2),transparent 70%),radial-gradient(22% 24% at 62% 18%,var(--c3),transparent 70%);opacity:.85;animation:hxSpin 20s linear infinite}
.hx-fx2{background:radial-gradient(30% 30% at 70% 30%,var(--c3),transparent 70%),radial-gradient(32% 32% at 28% 76%,var(--c1),transparent 70%);opacity:0;animation:hxFade 7s ease-in-out infinite alternate,hxSpin 28s linear infinite reverse}
@keyframes hxSpin{to{transform:rotate(360deg)}}@keyframes hxFade{to{opacity:.8}}
@media (prefers-reduced-motion:reduce){.hx-fx,.hx-fx2,.hx-shine{animation:none}}
.hx h2{margin:0;font:inherit}
.hx-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;background:var(--btn);color:var(--btnInk);text-decoration:none;font-weight:900;border-radius:999px;font-size:clamp(17px,1.7vw,24px);padding:clamp(14px,1.5vw,22px) clamp(26px,3vw,48px);box-shadow:0 16px 34px -14px var(--btn)}
.hx-num{font-weight:900;line-height:.82;letter-spacing:-.045em;color:var(--acc)}
'''

LAYOUTS = {}

# ----------------------------------------------- D: Mega Type -- words are the picture
LAYOUTS['D'] = dict(name='D · Mega Type', css='''
.hD{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:clamp(10px,1.6vw,22px);padding:20px}
.hD .hx-num{font-size:clamp(150px,22vw,300px);position:relative}
.hD .hx-title{display:block;font-weight:900;font-size:clamp(46px,7.6vw,112px);line-height:.9;letter-spacing:-.02em;margin-top:-.08em}
.hD .hx-offer{display:inline-block;font-weight:900;font-size:clamp(22px,3vw,44px);letter-spacing:.04em;padding:.18em .7em;border-radius:999px;background:var(--acc);color:var(--bg)}
.hD .hx-num.nonum{display:none}
@media (max-width:767px){.hD{gap:14px}.hD .hx-num{font-size:min(31vw,150px)}.hD .hx-title{font-size:min(11.5vw,52px)}.hD .hx-offer{font-size:22px}.hD .hx-btn{width:100%;max-width:340px}}
''', html=lambda o: (
    '<section class="hx hD"><div class="hx-fx"></div><div class="hx-fx2"></div>'
    + ('<div class="hx-num" aria-hidden="true">{num}</div>' if o['num'] else '')
    + '<h2><span class="hx-title">{title}</span></h2><div class="hx-offer">{offer}</div>'
    '<a class="hx-btn" href="#">{cta} &rarr;</a></section>'))

# ----------------------------------------------- E: Hero Product -- one huge product
LAYOUTS['E'] = dict(name='E · Hero Product', css='''
.hE{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:center;padding:0 clamp(16px,5vw,96px);gap:2vw}
.hE .hx-copy{display:flex;flex-direction:column;gap:clamp(12px,1.4vw,20px);align-items:flex-start}
.hE .hx-num{font-size:clamp(96px,13vw,190px)}
.hE .hx-title{display:block;font-weight:900;font-size:clamp(40px,5.6vw,84px);line-height:.92;letter-spacing:-.02em}
.hE .hx-offer{font-weight:900;font-size:clamp(24px,2.8vw,40px);color:var(--acc)}
.hE .hx-line{font-size:clamp(15px,1.3vw,19px);opacity:.9;margin:0}
.hE .hx-art{position:relative;height:100%;display:grid;place-items:center}
.hE .hx-art::before{content:"";position:absolute;width:min(92%,560px);aspect-ratio:1;border-radius:50%;background:radial-gradient(circle,#fff 0,rgba(255,255,255,.85) 45%,rgba(255,255,255,0) 70%)}
.hE .hx-art img{position:relative;width:min(88%,540px);height:auto;aspect-ratio:1;object-fit:contain;mix-blend-mode:multiply}
.hE .hx-tag{position:absolute;top:12%;inset-inline-end:8%;width:clamp(110px,11vw,170px);aspect-ratio:1;border-radius:50%;display:grid;place-items:center;text-align:center;font-weight:900;line-height:1;background:var(--btn);color:var(--btnInk);font-size:clamp(18px,1.8vw,28px);transform:rotate(-10deg);box-shadow:0 18px 30px -16px rgba(0,0,0,.5)}
.hE .hx-tag b{display:block;font-size:2em}
@media (max-width:1023px){.hE .hx-line{display:none}}
@media (max-width:767px){.hE{grid-template-columns:1fr;grid-template-rows:auto 1fr;padding:20px 16px 18px;gap:0;align-items:start}
 .hE .hx-copy{gap:6px}.hE .hx-num{font-size:min(24vw,110px)}.hE .hx-title{font-size:min(9.6vw,44px)}.hE .hx-offer{font-size:22px}
 .hE .hx-art{position:absolute;inset:auto -8% -6% 30%;height:62%}.hE .hx-art img{width:100%}.hE .hx-tag{width:96px;font-size:15px;top:-4%;inset-inline-end:14%}
 .hE .hx-btn{position:absolute;left:16px;bottom:18px;z-index:2}}
''', html=lambda o: (
    '<section class="hx hE"><div class="hx-fx"></div><div class="hx-fx2"></div><div class="hx-copy">'
    + ('<div class="hx-num" aria-hidden="true">{num}</div>' if o['num'] else '')
    + '<h2><span class="hx-title">{title}</span></h2><div class="hx-offer">{offer}</div><p class="hx-line">{line}</p>'
    '<a class="hx-btn" href="#">{cta} &rarr;</a></div>'
    '<div class="hx-art"><img src="' + PRODUCT + '" alt="Anua PDRN Hyaluron set" width="800" height="800" fetchpriority="high" decoding="async">'
    '<div class="hx-tag"><span><b>50%</b>OFF</span></div></div></section>'))

# ----------------------------------------------- F: Poster Split -- colour block + full photo
LAYOUTS['F'] = dict(name='F · Poster Split', css='''
.hF{display:grid;grid-template-columns:minmax(0,46fr) minmax(0,54fr)}
.hF .hx-panel{position:relative;overflow:hidden;isolation:isolate;display:flex;flex-direction:column;justify-content:center;gap:clamp(10px,1.4vw,20px);padding:0 clamp(18px,4.4vw,84px)}
.hF .hx-panel .hx-fx,.hF .hx-panel .hx-fx2{inset:-60%}
.hF .hx-num{font-size:clamp(100px,12.5vw,200px)}
.hF .hx-title{display:block;font-weight:900;font-size:clamp(40px,5.4vw,86px);line-height:.9;letter-spacing:-.02em}
.hF .hx-offer{font-weight:900;font-size:clamp(26px,3.4vw,54px);line-height:1;color:var(--acc)}
.hF .hx-photo{position:relative;overflow:hidden}
.hF .hx-photo img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:60% 50%}
.hF .hx-photo::before{content:"";position:absolute;inset:0 auto 0 0;width:18%;z-index:1;background:linear-gradient(90deg,var(--bg),transparent)}
@media (max-width:767px){.hF{grid-template-columns:1fr;grid-template-rows:46% 54%}.hF .hx-photo{grid-row:1}.hF .hx-panel{grid-row:2;padding:14px 16px;gap:6px;justify-content:flex-start}
 .hF .hx-photo::before{inset:auto 0 0 0;width:auto;height:30%;background:linear-gradient(0deg,var(--bg),transparent)}
 .hF .hx-num{font-size:min(19vw,84px)}.hF .hx-title{font-size:min(8.6vw,38px)}.hF .hx-offer{font-size:24px}.hF .hx-btn{margin-top:4px;align-self:stretch}}
''', html=lambda o: (
    '<section class="hx hF"><div class="hx-panel"><div class="hx-fx"></div><div class="hx-fx2"></div>'
    + ('<div class="hx-num" aria-hidden="true">{num}</div>' if o['num'] else '')
    + '<h2><span class="hx-title">{title}</span></h2><div class="hx-offer">{offer}</div>'
    '<div><a class="hx-btn" href="#">{cta} &rarr;</a></div></div>'
    '<div class="hx-photo"><picture><source media="(max-width:767px)" srcset="' + PHOTO_M + '"><img src="' + PHOTO + '" alt="Anua PDRN skincare" width="1920" height="1028" fetchpriority="high" decoding="async"></picture></div></section>'))


def doc(layout, occ):
    o = dict(OCC[occ])
    vars_ = ';'.join('--%s:%s' % (k, o[k]) for k in ('c1', 'c2', 'c3', 'bg', 'ink', 'acc', 'btn')) + ';--btnInk:' + o['btnInk']
    html = LAYOUTS[layout]['html'](o)
    for k in ('num', 'title', 'offer', 'line', 'cta', 'label'):
        html = html.replace('{' + k + '}', o[k])
    html = html.replace('class="hx ', 'style="' + vars_ + '" class="hx ', 1)
    return ('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;800&display=swap" rel="stylesheet">'
            '<style>' + BASE + LAYOUTS[layout]['css'] + '</style></head><body>' + html + '</body></html>')


ORDER = [(l, o) for l in 'DEF' for o in ('1111', 'uae', 'ny', 'super')]
docs = {l + '-' + o: doc(l, o) for l, o in ORDER}
names = {l + '-' + o: LAYOUTS[l]['name'] + '  ·  ' + OCC[o]['label'] for l, o in ORDER}
if len(sys.argv) > 1 and sys.argv[1] == 'shots':
    os.makedirs(os.path.join(D, 'v'), exist_ok=True)
    for k, v in docs.items():
        open(os.path.join(D, 'v', k + '.html'), 'w').write(v)
    sys.exit()

page = open(os.path.join(D, 'home-hero-preview.html')).read()
i = page.index('var DOCS=')
j = page.index(',list=document.getElementById')
esc = lambda x: json.dumps(x).replace('</', '<\\/').replace('<!--', '<\\!--')
page = page[:i] + 'var DOCS=' + esc(docs) + ',NAMES=' + esc(names) + page[j:]
page = page.replace('<button data-f="A">Layout A</button><button data-f="B">Layout B</button><button data-f="C">Layout C</button>',
                    '<button data-f="D">Layout D</button><button data-f="E">Layout E</button><button data-f="F">Layout F</button>')
page = page.replace('Pick a layout letter.', 'Everything big: giant words, one big product or a full photo, a big button. Pick a layout letter.')
open(os.path.join(D, 'home-hero-preview.html'), 'w').write(page)
print('ok', len(page))
