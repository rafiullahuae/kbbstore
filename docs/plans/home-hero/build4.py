# Homepage occasion hero, round 3 -- the owner, 6 Nov 2026, with his own slider
# as the reference: "i want multiple products in the banner like floating
# products with little animations, and background color scheme for the whole
# banner must be colors changing etc beautiful, not dull or rushly. and on the
# left side the content. also make the same for mobile screen size as our
# current banner size." Then: "do not make the content box as shown in
# screenshot need big content" -- so no card: big words straight on the colour.
#
# Sizes as measured on kbeautybliss.com: computer full width x 600 (548 at
# 1024, 411 at 768), phone full width x 500.
# Movement is transform / opacity only (composited): the colour cycles by
# cross-fading three full-size gradient layers; each product bobs and tilts on
# its own slow loop. Everything stops for prefers-reduced-motion.
import base64, io, json, os, sys
from PIL import Image

D = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(D, 'products')
# The owner, 6 Nov: "i want the products from different brands. total 4-5 products, 1 from
# each brand, keep the Anua mist mini, and medicube stick, choose rest any most selling in our
# website." Best sellers (kbeautybliss.com/best-sellers/, 6 Nov): Dr.Althea 345 Relief Cream (3rd),
# Beauty of Joseon Relief Sun (4th), COSRX Advanced Snail 96 Essence (8th). Cut out with ../gift-strip/tools/cutout2.py from the shop's full-size photos (6 Nov: no white rims).


def cut(name, h=420):
    im = Image.open(os.path.join(SRC, name)).convert('RGBA')
    w = round(im.width * h / im.height)
    im = im.resize((w, h), Image.LANCZOS)
    b = io.BytesIO()
    im.save(b, 'WEBP', quality=82, method=6)
    return 'data:image/webp;base64,' + base64.b64encode(b.getvalue()).decode(), w, h


P = [cut('anua-pdrn-hyaluronic-acid-hydrating-capsule-mist-mini.png'),
     cut('dr-althea-345-relief-cream.png'),
     cut('cosrx-snail-96-essence.png'),
     cut('beauty-of-joseon-relief-sun.png'),
     cut('medicube-vanilla-pistachio-deodorant.png')]
ALTS = ['Anua PDRN Hyaluronic Capsule Mist', 'Dr.Althea 345 Relief Cream', 'COSRX Advanced Snail 96 Mucin Essence',
        'Beauty of Joseon Relief Sun Rice + Probiotics', 'Medicube Vanilla Pistachio Deodorant']

# Three colour states per occasion (the background cross-fades between them),
# all light enough for the products to read, deep enough not to look dull.
OCC = {
    '1111': dict(label='11.11 MEGA SALE', num='11.11', title='MEGA SALE', offer='Up to 50% off', line='Korean skincare bestsellers, free gifts with every order and fast UAE delivery.',
                 cta='Shop the 11.11 sale', ink='#2a1033', acc='#e3246e', btn='#e3246e', btnInk='#fff',
                 a=('#ffd6e8', '#e9d5ff', '#ffe3cf'), b=('#ffb4d2', '#c9b5ff', '#ffc9a8'), c=('#fff0f6', '#f3ecff', '#fff4ea')),
    'uae': dict(label='UAE NATIONAL DAY', num='55', title='NATIONAL DAY SALE', offer='Up to 55% off', line='Celebrating Eid Al Etihad with K-beauty favourites, free gifts and fast UAE delivery.',
                cta='Shop the National Day sale', ink='#0d2a1b', acc='#00732F', btn='#E31B23', btnInk='#fff',
                a=('#dff5e7', '#ffffff', '#ffe4e4'), b=('#b9ebcb', '#f4f4f4', '#ffc9cb'), c=('#f1fbf5', '#ffffff', '#fff3f3')),
    'ny': dict(label='NEW YEAR SALE', num='2027', title='NEW YEAR SALE', offer='Up to 50% off', line='Start the year glowing: K-beauty bestsellers, free gifts and fast UAE delivery.',
               cta='Shop the New Year sale', ink='#231a33', acc='#b07a12', btn='#2b1d4a', btnInk='#ffe7a8',
               a=('#fff1cf', '#e7ddff', '#ffe0ef'), b=('#ffe19a', '#cdbcff', '#ffc4df'), c=('#fffaf0', '#f5f1ff', '#fff3f8')),
    'super': dict(label='SUPER SALE', num='', title='SUPER SALE', offer='Up to 50% off', line='Perfect skincare to glow: free gifts with every order, fast UAE delivery, pay later with Tabby or Tamara.',
                  cta='Shop the Super Sale', ink='#2a2228', acc='#e3246e', btn='#e3246e', btnInk='#fff',
                  a=('#ffe0ea', '#ffe9dc', '#f6e1ff'), b=('#ffc2d6', '#ffd2b8', '#ecc6ff'), c=('#fff4f8', '#fff7f1', '#fbf4ff')),
}

BASE = '''
*{box-sizing:border-box}html,body{margin:0}body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,sans-serif}
.hz{position:relative;overflow:hidden;width:100%;height:500px;isolation:isolate;color:var(--ink);background:var(--a1)}
@media (min-width:768px){.hz{height:411px}}@media (min-width:1024px){.hz{height:548px}}@media (min-width:1280px){.hz{height:600px}}
/* the colour: three full layers, each fading in and out on a 18s loop, a third apart */
.hz-bg{position:absolute;inset:0;z-index:-2;pointer-events:none}
.hz-bg i{position:absolute;inset:0;opacity:0;animation:hzCycle 18s ease-in-out infinite}
.hz-bg i:nth-child(1){background:radial-gradient(70% 90% at 80% 30%,var(--b1),transparent 70%),linear-gradient(115deg,var(--c1),var(--a1) 55%,var(--a2));animation-delay:0s}
.hz-bg i:nth-child(2){background:radial-gradient(70% 90% at 70% 70%,var(--b2),transparent 70%),linear-gradient(115deg,var(--c2),var(--a2) 55%,var(--a3));animation-delay:-6s}
.hz-bg i:nth-child(3){background:radial-gradient(70% 90% at 85% 50%,var(--b3),transparent 70%),linear-gradient(115deg,var(--c3),var(--a3) 55%,var(--a1));animation-delay:-12s}
@keyframes hzCycle{0%,100%{opacity:0}16%,50%{opacity:1}66%{opacity:0}}
/* soft light drifting across */
.hz-glow{position:absolute;z-index:-1;inset:-30%;pointer-events:none;background:radial-gradient(20% 26% at 30% 40%,rgba(255,255,255,.75),transparent 70%),radial-gradient(18% 22% at 70% 65%,rgba(255,255,255,.55),transparent 70%);animation:hzDrift 24s ease-in-out infinite alternate}
@keyframes hzDrift{to{transform:translate(8%,-6%) rotate(8deg)}}
/* floating products: each its own slow bob and tilt */
.hz-p{position:absolute;filter:drop-shadow(0 26px 22px rgba(40,20,40,.22));will-change:transform;animation:hzBob 7s ease-in-out infinite}
.hz-p img{display:block;height:100%;width:auto}
@keyframes hzBob{0%,100%{transform:translateY(0) rotate(var(--r,0deg))}50%{transform:translateY(-14px) rotate(calc(var(--r,0deg) + 3deg))}}
.hz-dot{position:absolute;border-radius:50%;background:radial-gradient(circle at 35% 30%,#fff,rgba(255,255,255,.55) 40%,rgba(255,255,255,0) 70%);animation:hzBob 9s ease-in-out infinite}
@media (prefers-reduced-motion:reduce){.hz-bg i,.hz-glow,.hz-p,.hz-dot{animation:none}.hz-bg i:nth-child(1){opacity:1}}
.hz h2{margin:0;font:inherit}
.hz-copy{position:absolute;z-index:2;display:flex;flex-direction:column;align-items:flex-start}
.hz-label{font-weight:800;letter-spacing:.18em;color:var(--acc)}
.hz-num{font-weight:900;line-height:.82;letter-spacing:-.045em;color:var(--acc)}
.hz-title{display:block;font-weight:900;line-height:.92;letter-spacing:-.02em}
.hz-offer{font-weight:900;color:var(--acc)}
.hz-line{margin:0;opacity:.82}
.hz-btn{display:inline-flex;align-items:center;gap:10px;background:var(--btn);color:var(--btnInk);text-decoration:none;font-weight:900;border-radius:999px;box-shadow:0 18px 30px -16px var(--btn)}
'''

LAYOUTS = {}
PLACE = '''
/* true-to-life proportions (the owner, 6 Nov: "the mini is small in size. but u make it
   larger then the medicube stick"): COSRX 100ml bottle and Dr.Althea tube tallest, Beauty of
   Joseon tube a little shorter, Medicube stick about two thirds, Anua mist mini about half */
.hz-p4{right:43%;top:42%;height:40%;z-index:2}
.hz-p0{right:32.5%;top:46%;height:30%;z-index:3}
.hz-p1{right:22%;top:12%;height:60%}
.hz-p2{right:12.5%;top:11%;height:62%;z-index:1}
.hz-p3{right:4%;top:20%;height:54%}
@media (max-width:1023px){.hz-p4{right:42%;top:46%;height:36%}.hz-p0{top:52%;height:27%}}
@media (max-width:767px){.hz-p4{right:auto;left:3%;top:auto;bottom:3%;height:24%}.hz-p0{right:auto;left:21%;top:auto;bottom:3%;height:18%;z-index:3}.hz-p1{right:auto;left:38%;top:auto;bottom:4%;height:35%}
 .hz-p2{right:auto;left:57%;top:auto;bottom:3%;height:36%}.hz-p3{right:4%;top:auto;bottom:4%;height:31%}}
'''

# Computer: words left (big), products right. Phone: words top, products below.
COPY_DESKTOP = '''
.hz-copy{left:clamp(18px,5vw,96px);top:50%;transform:translateY(-50%);width:min(46%,620px);gap:clamp(10px,1.2vw,18px)}
.hz-label{font-size:clamp(13px,1.1vw,16px)}
.hz-num{font-size:clamp(90px,11vw,170px)}
.hz-title{font-size:clamp(42px,5.4vw,84px)}
.hz-offer{font-size:clamp(28px,3.2vw,50px);line-height:1}
.hz-line{font-size:clamp(15px,1.3vw,19px);max-width:34ch}
.hz-btn{font-size:clamp(16px,1.5vw,21px);padding:clamp(14px,1.3vw,20px) clamp(26px,2.6vw,42px)}
@media (max-width:1023px){.hz-line{display:none}.hz-num{font-size:clamp(76px,10vw,110px)}}
@media (max-width:767px){.hz-copy{left:16px;right:16px;top:20px;transform:none;width:auto;gap:6px}
 .hz-label{font-size:12px}.hz-num{font-size:min(21vw,96px)}.hz-title{font-size:min(10vw,42px)}.hz-offer{font-size:26px}
 .hz-btn{position:absolute;top:auto;font-size:15px;padding:13px 22px}}
'''


def products(spec):
    out = ''
    for i, (x, y, h, r, d) in enumerate(spec):
        src, w, hh = P[i]
        out += ('<div class="hz-p hz-p%d" style="--r:%sdeg;animation-delay:%ss"><img src="%s" alt="%s" width="%d" height="%d" decoding="async"></div>' % (i, r, d, src, ALTS[i], w, hh))
    return out


# ------------------------------------------------ G: Floating Cluster
LAYOUTS['G'] = dict(name='G · Floating Cluster', css=COPY_DESKTOP + PLACE + '''
.hz-d1{width:70px;height:70px;right:44%;top:12%}.hz-d2{width:44px;height:44px;right:6%;bottom:12%;animation-delay:-3s}.hz-d3{width:30px;height:30px;right:36%;bottom:16%;animation-delay:-5s}
@media (max-width:767px){.hz-btn{left:16px;top:216px}}
''', html=lambda o: (
    '<section class="hz hG"><div class="hz-bg"><i></i><i></i><i></i></div><div class="hz-glow"></div>'
    '<span class="hz-dot hz-d1"></span><span class="hz-dot hz-d2"></span><span class="hz-dot hz-d3"></span>'
    + products([(0, 0, 0, -8, 0), (0, 0, 0, 6, -2), (0, 0, 0, -4, -4), (0, 0, 0, 9, -1), (0, 0, 0, -10, -3)])
    + COPY(o) + '</section>'))

# ------------------------------------------------ H: Orbit Glow (products around a soft halo)
LAYOUTS['H'] = dict(name='H · Glow Halo', css=COPY_DESKTOP + PLACE + '''
.hz-halo{position:absolute;right:8%;top:50%;width:clamp(260px,34vw,520px);aspect-ratio:1;transform:translateY(-50%);border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.95) 0,rgba(255,255,255,.55) 45%,rgba(255,255,255,0) 70%)}
.hz-halo::after{content:"";position:absolute;inset:6%;border-radius:50%;border:2px dashed rgba(255,255,255,.9);animation:hzSpin 40s linear infinite}
@keyframes hzSpin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.hz-halo::after{animation:none}}
@media (max-width:767px){.hz-halo{right:-10%;top:auto;bottom:-14%;width:78%;transform:none}.hz-btn{left:16px;top:216px}}
''', html=lambda o: (
    '<section class="hz hH"><div class="hz-bg"><i></i><i></i><i></i></div><div class="hz-glow"></div><div class="hz-halo"></div>'
    + products([(0, 0, 0, -5, 0), (0, 0, 0, 7, -2.5), (0, 0, 0, -6, -4), (0, 0, 0, 10, -1.5), (0, 0, 0, -12, -3)])
    + COPY(o) + '</section>'))

# ------------------------------------------------ I: Wave Stage (products over moving soft waves)
WAVE = ("url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 200' preserveAspectRatio='none'%3E"
        "%3Cpath fill='%23fff' fill-opacity='.55' d='M0 80 C200 20 400 140 600 80 S1000 20 1200 80 V200 H0Z'/%3E%3C/svg%3E\")")
LAYOUTS['I'] = dict(name='I · Wave Stage', css=COPY_DESKTOP + PLACE + '''
.hz-wave{position:absolute;left:0;bottom:0;width:200%;height:34%;background:''' + WAVE + ''' 0 100%/50% 100% repeat-x;animation:hzWave 16s linear infinite;z-index:0}
.hz-wave.w2{height:24%;opacity:.7;animation-duration:24s;animation-direction:reverse}
@keyframes hzWave{to{transform:translateX(-50%)}}
@media (prefers-reduced-motion:reduce){.hz-wave{animation:none}}
@media (max-width:767px){.hz-wave{height:30%}.hz-btn{left:16px;top:216px}}
''', html=lambda o: (
    '<section class="hz hI"><div class="hz-bg"><i></i><i></i><i></i></div><div class="hz-glow"></div>'
    '<div class="hz-wave"></div><div class="hz-wave w2"></div>'
    + products([(0, 0, 0, -6, 0), (0, 0, 0, 5, -2), (0, 0, 0, -7, -3.5), (0, 0, 0, 11, -1), (0, 0, 0, -9, -2.5)])
    + COPY(o) + '</section>'))


def COPY(o):
    return ('<div class="hz-copy"><span class="hz-label">{label}</span>'
            + ('<span class="hz-num" aria-hidden="true">{num}</span>' if o['num'] else '')
            + '<h2><span class="hz-title">{title}</span></h2><div class="hz-offer">{offer}</div><p class="hz-line">{line}</p>'
            '<a class="hz-btn" href="#">{cta} &rarr;</a></div>')


def doc(layout, occ):
    o = dict(OCC[occ])
    v = {'ink': o['ink'], 'acc': o['acc'], 'btn': o['btn'], 'btnInk': o['btnInk']}
    for n, (x, y, z) in (('a', o['a']), ('b', o['b']), ('c', o['c'])):
        v[n + '1'], v[n + '2'], v[n + '3'] = x, y, z
    style = ';'.join('--%s:%s' % kv for kv in v.items())
    html = LAYOUTS[layout]['html'](o)
    for k in ('num', 'title', 'offer', 'line', 'cta', 'label'):
        html = html.replace('{' + k + '}', o[k])
    html = html.replace('class="hz ', 'style="' + style + '" class="hz ', 1)
    return ('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;800&display=swap" rel="stylesheet">'
            '<style>' + BASE + LAYOUTS[layout]['css'] + '</style></head><body>' + html + '</body></html>')


ORDER = [(l, o) for l in 'GHI' for o in ('1111', 'uae', 'ny', 'super')]
docs = {l + '-' + o: doc(l, o) for l, o in ORDER}
names = {l + '-' + o: LAYOUTS[l]['name'] + '  ·  ' + OCC[o]['label'].title() for l, o in ORDER}
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
page = page.replace('<button data-f="G">Layout G</button><button data-f="H">Layout H</button><button data-f="I">Layout I</button>',
                    '<button data-f="G">Layout G</button><button data-f="H">Layout H</button><button data-f="I">Layout I</button>')
page = page.replace('Everything big: giant words, one big product or a full photo, a big button. Pick a layout letter.',
                    'Five best sellers from five brands floating with gentle movement, the whole background softly changing colour, big words on the left (no box). Pick a layout letter.')
open(os.path.join(D, 'home-hero-preview.html'), 'w').write(page)
print('ok', len(page))
