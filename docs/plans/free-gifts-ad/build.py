# "Get Free Gifts" -- Instagram story + posts and the website banners
# (the owner, 10 Nov 2026: "get me the initial free gifts stories + posts
# sizes ... and convert those into website banner size for desktop and
# mobile. the reference version is attached").
#
# One design, five canvases. Writes out/<name>.html; render.cjs photographs
# each at its exact pixel size into out/<name>.png and out/<name>.jpg.
#
#   story      1080 x 1920   Instagram / Facebook story
#   post       1080 x 1350   Instagram feed post (4:5)
#   square     1080 x 1080   Instagram / Facebook square post
#   web-desk   3840 x 1200   homepage slider, computer (a 1920 x 600 box at 2x; centre-cropped)
#   web-phone  1200 x 1540   homepage slider, phone   (shown 390 x 500, ~3x)
#
# Products are the shop's own photographs, cut out (assets/): Isntree Onion
# Newpair Sunscreen, Medicube PDRN Gua Sha Neck Cream, Mary & May Spicule
# Collagen PDRN Cream, AXIS-Y Dark Spot Glow Serum, Medicube PDRN eye mask.
import os

D = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(D, 'out')
os.makedirs(OUT, exist_ok=True)
LOTUS = open(os.path.join(D, 'assets', 'lotus.svg')).read().replace('class="lgx-i" ', '').replace('width="35" height="26" ', '')

# The reference's own type: Cormorant Upright (Get / Gifts, the descending f), Cormorant
# Garamond italic (Free), Pinyon Script (the line under it). The button and the small
# line are the WEBSITE's: Outfit and the shop's pink pill (kbb.css .btn, --pink #C6395F).
FONTS = '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Upright:wght@700&family=Cormorant+Garamond:ital,wght@1,700&family=Pinyon+Script&family=Outfit:wght@500;600;700&display=block" rel="stylesheet">'

# The scene (gift box + products) is drawn on a 1000 x 800 board and scaled.
SCENE = '''
<div class="scene" style="--sc:{sc};left:{x}px;top:{y}px">
  <svg class="spark s1" style="left:70px;top:150px;width:34px" viewBox="0 0 24 24"><path d="M12 0l2.6 9.4L24 12l-9.4 2.6L12 24l-2.6-9.4L0 12l9.4-2.6z"/></svg>
  <svg class="spark" style="left:905px;top:250px;width:26px" viewBox="0 0 24 24"><path d="M12 0l2.6 9.4L24 12l-9.4 2.6L12 24l-2.6-9.4L0 12l9.4-2.6z"/></svg>
  <svg class="spark" style="left:860px;top:90px;width:18px" viewBox="0 0 24 24"><path d="M12 0l2.6 9.4L24 12l-9.4 2.6L12 24l-2.6-9.4L0 12l9.4-2.6z"/></svg>
  <svg class="spark" style="left:150px;top:300px;width:16px" viewBox="0 0 24 24"><path d="M12 0l2.6 9.4L24 12l-9.4 2.6L12 24l-2.6-9.4L0 12l9.4-2.6z"/></svg>
  <div class="inside"></div>
  <img class="p" src="../assets/isntree.webp" style="left:178px;bottom:236px;height:356px;--r:-9deg">
  <img class="p" src="../assets/guasha.webp" style="left:322px;bottom:226px;height:452px;--r:-2deg">
  <img class="p" src="../assets/marymay.webp" style="left:540px;bottom:232px;height:500px;--r:4deg">
  <img class="p" src="../assets/axisy.webp" style="left:690px;bottom:226px;height:400px;--r:9deg">
  <svg class="tissue" viewBox="0 0 760 190" style="left:120px;top:388px;width:760px">
    <defs><linearGradient id="ts" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#ffe6ee"/></linearGradient></defs>
    <path d="M10 190 C 20 120, 40 70, 70 92 C 90 40, 130 40, 140 96 C 170 60, 190 120, 205 150 L 205 190Z" fill="url(#ts)" stroke="#f6c9d8" stroke-width="2"/>
    <path d="M150 190 C 160 140, 190 120, 210 140 C 230 110, 250 120, 262 150 L262 190Z" fill="#fde2eb" stroke="#f6c9d8" stroke-width="2"/>
    <path d="M330 190 C 340 150, 360 128, 380 146 C 395 120, 420 126, 428 156 L 428 190Z" fill="url(#ts)" stroke="#f6c9d8" stroke-width="2"/>
    <path d="M480 190 C 486 136, 520 100, 548 126 C 566 96, 600 104, 606 150 L606 190Z" fill="url(#ts)" stroke="#f6c9d8" stroke-width="2"/>
    <path d="M560 190 C 570 150, 590 140, 606 152 L606 190Z" fill="#fde2eb"/>
    <path d="M640 190 C 650 110, 690 70, 712 104 C 730 70, 760 90, 752 150 L752 190Z" fill="url(#ts)" stroke="#f6c9d8" stroke-width="2"/>
  </svg>
  <div class="lid"></div>
  <div class="front"><i class="rib"></i></div>
  <svg class="bow" viewBox="0 0 260 150" style="left:370px;top:478px;width:260px">
    <defs><linearGradient id="bw" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#ffe3ec"/><stop offset="1" stop-color="#f6b3c9"/></linearGradient></defs>
    <path d="M130 70 C 70 0, 0 10, 18 60 C 30 100, 90 96, 130 76Z" fill="url(#bw)" stroke="#eda0bb" stroke-width="3"/>
    <path d="M130 70 C 190 0, 260 10, 242 60 C 230 100, 170 96, 130 76Z" fill="url(#bw)" stroke="#eda0bb" stroke-width="3"/>
    <path d="M120 80 L70 150 L98 142 L112 150 L128 86Z" fill="#f7bcd0" stroke="#eda0bb" stroke-width="2"/>
    <path d="M140 80 L190 150 L162 142 L148 150 L132 86Z" fill="#f7bcd0" stroke="#eda0bb" stroke-width="2"/>
    <ellipse cx="130" cy="74" rx="20" ry="17" fill="#ffd4e2" stroke="#eda0bb" stroke-width="3"/>
  </svg>
  <img class="p em" src="../assets/eyemask.webp" style="left:-6px;bottom:-14px;width:280px;--r:-7deg">
  <img class="p" src="../assets/patch.webp" style="left:850px;bottom:110px;width:150px;--r:18deg">
  <img class="p" src="../assets/patch.webp" style="left:830px;bottom:30px;width:140px;--r:-160deg">
</div>'''

BASE_CSS = '''
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:%(w)dpx;height:%(h)dpx;overflow:hidden}
body{position:relative;font-family:Outfit,system-ui,sans-serif;color:#2b1d24;
  background:radial-gradient(70%% 55%% at 50%% 38%%,#fff1f5 0%%,#fde0ea 55%%,#f9cddd 100%%)}
.glow{position:absolute;border-radius:50%%;filter:blur(40px);opacity:.7}
.logo{position:absolute;display:flex;align-items:center;gap:calc(var(--lg)*.28)}
.logo svg{width:calc(var(--lg)*1.45);height:auto}
.logo svg .lgx-f{fill:#e0457f}.logo svg .lgx-o{fill:none;stroke:#fff;stroke-width:14;opacity:.55}
.logo b{display:block;font-weight:700;font-size:var(--lg);letter-spacing:.06em;line-height:1}
.logo b em{font-style:normal;color:#d63a74}
.logo small{display:block;margin-top:calc(var(--lg)*.18);font-size:calc(var(--lg)*.36);letter-spacing:.14em;font-weight:600;color:#d63a74;text-align:center}
.h{position:absolute;font-family:'Cormorant Upright',serif;font-weight:700;line-height:1;white-space:nowrap;color:#231a1f}
.h em{font-family:'Cormorant Garamond',serif;font-style:italic;font-weight:700;color:#e3317a}
.sc{position:absolute;font-family:'Pinyon Script',cursive;color:#c8336c;line-height:1.1;white-space:nowrap}
.cta{position:absolute;display:inline-flex;align-items:center;gap:.64em;background:#C6395F;color:#fff;border-radius:99px;font-weight:700;
  padding:1em 1.86em;white-space:nowrap;line-height:1;box-shadow:0 .9em 1.6em -.9em rgba(198,57,95,.75)}
.cta svg{width:1.1em;height:1.1em}
.pay{position:absolute;font-weight:600;letter-spacing:.16em;color:#4a3a40;white-space:nowrap}
.scene{position:absolute;width:1000px;height:800px;transform:scale(var(--sc));transform-origin:0 0}
.scene .p{position:absolute;transform:rotate(var(--r,0deg));filter:drop-shadow(0 18px 18px rgba(120,30,60,.22))}
.scene .em{filter:drop-shadow(0 14px 16px rgba(120,30,60,.25))}
.spark{position:absolute;fill:#e8457f;opacity:.8}
.inside{position:absolute;left:150px;top:470px;width:700px;height:70px;border-radius:14px 14px 0 0;background:linear-gradient(#b82763,#d23a72)}
.tissue{position:absolute}
.lid{position:absolute;left:128px;top:532px;width:744px;height:34px;border-radius:12px;background:linear-gradient(#f2649a,#d93b76);box-shadow:0 6px 10px -4px rgba(120,20,60,.35)}
.front{position:absolute;left:150px;top:560px;width:700px;height:220px;border-radius:0 0 18px 18px;
  background:linear-gradient(100deg,#e84d86 0%%,#f0679a 45%%,#d63673 100%%);box-shadow:0 30px 40px -24px rgba(140,25,70,.55)}
.rib{position:absolute;left:315px;top:0;width:70px;height:100%%;background:linear-gradient(90deg,#fcd0de,#ffe9f0,#f8c2d5)}
.bow{position:absolute}
'''


def page(name, w, h, body):
    return ('<!doctype html><html><head><meta charset="utf-8">' + FONTS + '<style>' + (BASE_CSS % {'w': w, 'h': h}) + '</style></head><body>' + body
            + '</body></html>')


def logo(x, y, size, center_w=None):
    st = 'left:%dpx;top:%dpx;--lg:%dpx' % (x, y, size)
    if center_w:
        st = 'left:0;width:%dpx;justify-content:center;top:%dpx;--lg:%dpx' % (center_w, y, size)
    return ('<div class="logo" style="%s">%s<div><b>K-BEAUTY <em>BLISS</em></b><small>— KOREAN SKINCARE &amp; MAKEUP —</small></div></div>' % (st, LOTUS))


ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'


def centered(w, cls, y, size, html_, extra=''):
    return '<div class="%s" style="left:0;width:%dpx;text-align:center;top:%dpx;font-size:%dpx;%s">%s</div>' % (cls, w, y, size, extra, html_)


def vertical(w, h, L):
    """Story / posts / phone banner: everything stacked and centred."""
    b = '<div class="glow" style="left:%dpx;top:%dpx;width:%dpx;height:%dpx;background:#fff"></div>' % (w * .15, h * .42, w * .7, w * .5)
    b += centered(w, 'h', L['h'], L['hs'], 'Get <em>Free</em> Gifts')
    b += centered(w, 'sc', L['s'], L['ss'], 'Grab yours before they&rsquo;re gone')
    sw = 1000 * L['sc']
    b += SCENE.format(sc=L['sc'], x=round((w - sw) / 2), y=L['scene'])
    if L.get('cta'):
        b += '<div style="position:absolute;left:0;width:%dpx;top:%dpx;display:flex;justify-content:center"><div class="cta" style="position:static;font-size:%dpx">Shop Now%s</div></div>' % (w, L['cta'], L['cs'], ARROW)
    b += centered(w, 'pay', L['pay'], L['ps'], 'SHOP NOW, PAY LATER WITH TABBY OR TAMARA')
    return b


def wide(w, h):
    """Desktop slider picture, 3840 x 1200 = a 1920 x 600 box at 2x. The slider
    crops it from the CENTRE (object-fit: cover): every screen sees the full
    height and smaller screens lose the left and right edges -- at 1024 wide the
    visible middle is ~2240 of 3840. So the words and the box all sit in the
    centre 2200 (x 820..3020), inside the website frame on every screen."""
    b = '<div class="glow" style="left:1700px;top:200px;width:1400px;height:900px;background:#fff"></div>'
    x = 860
    b += '<div class="h" style="left:%dpx;top:250px;font-size:206px">Get <em>Free</em> Gifts</div>' % x
    b += '<div class="sc" style="left:%dpx;top:490px;font-size:96px">Grab yours before they&rsquo;re gone</div>' % (x + 10)
    b += '<div class="cta" style="left:%dpx;top:690px;font-size:40px">Shop Now%s</div>' % (x + 10, ARROW)
    b += '<div class="pay" style="left:%dpx;top:880px;font-size:26px">SHOP NOW, PAY LATER WITH TABBY OR TAMARA</div>' % (x + 14)
    b += SCENE.format(sc=1.1, x=1880, y=170)
    # the scene's left sparkle would sit on the headline here
    b += '<style>.s1{display:none}</style>'
    return b


FORMATS = {
    'story': (1080, 1920, dict(h=250, hs=150, s=420, ss=76, scene=560, sc=1.0, cta=1480, cs=36, pay=1640, ps=24)),
    'post': (1080, 1350, dict(h=96, hs=132, s=246, ss=64, scene=350, sc=.9, cta=None, pay=1236, ps=22)),
    'square': (1080, 1080, dict(h=70, hs=114, s=196, ss=56, scene=290, sc=.76, cta=None, pay=1000, ps=20)),
    # phone slider, 1200 x 1540 (390 x 500 at ~3x); kept 90px clear of every
    # edge a narrower or wider phone may crop (360 trims ~46px a side, 430 ~72px top/bottom)
    'web-phone': (1200, 1540, dict(h=150, hs=150, s=322, ss=74, scene=450, sc=.98, cta=1255, cs=34, pay=1400, ps=22)),
}

for name, (w, h, L) in FORMATS.items():
    open(os.path.join(OUT, name + '.html'), 'w').write(page(name, w, h, vertical(w, h, L)))
open(os.path.join(OUT, 'web-desk.html'), 'w').write(page('web-desk', 3840, 1200, wide(3840, 1200)))
print('ok')
