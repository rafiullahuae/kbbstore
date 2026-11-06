# Loaded by build.py (exec) after the 11.11 themes.
#
# UAE National Day, redrawn -- the owner, 6 Nov: "change the national day
# design, more to uae colors and flag etc".
# New Year Sale -- the owner, 6 Nov: "i also need new year sale design too".
import math
import urllib.parse


def svg_url(svg):
    return 'url("data:image/svg+xml,' + urllib.parse.quote(svg, safe=' =:/,;') + '")'


G, WH, K, R = '#00732F', '#FFFFFF', '#000000', '#E31B23'


def _wave(y, a):
    pts = []
    for i in range(0, 61):
        x = 150 + i * 7.5
        pts.append((x, y + a * math.sin(i / 60 * 2 * math.pi * 1.5)))
    return pts


def _band(y1, y2, color):
    top = _wave(y1, 14)
    bot = _wave(y2, 14)
    d = 'M' + ' L'.join('%.1f %.1f' % p for p in top) + ' L' + ' L'.join('%.1f %.1f' % p for p in reversed(bot)) + 'Z'
    return '<path fill="%s" d="%s"/>' % (color, d)


FLAG_WAVE = svg_url(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 300" preserveAspectRatio="none">'
    + '<path fill="%s" d="M0 -10 L150 -10 L150 310 L0 310Z"/>' % R
    + _band(-10, 100, G) + _band(100, 200, WH) + _band(200, 310, K)
    + '<path fill="#fff" fill-opacity=".16" d="M150 -10 C260 60 380 -20 600 40 L600 120 C380 60 260 140 150 70Z"/></svg>')

RIBBON = svg_url(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 300" preserveAspectRatio="none">'
    + '<path fill="%s" d="M0 300 C200 240 400 90 800 10 L800 60 C420 120 220 250 0 300Z"/>' % R
    + '<path fill="%s" d="M40 300 C220 250 420 120 800 60 L800 110 C430 165 240 290 110 300Z"/>' % G
    + '<path fill="#fff" stroke="#dcdcdc" stroke-width="1.5" d="M110 300 C240 290 430 165 800 110 L800 160 C450 210 280 300 190 300Z"/>'
    + '<path fill="%s" d="M190 300 C280 300 450 210 800 160 L800 210 C480 250 360 300 290 300Z"/></svg>' % K)

FLAG = 'linear-gradient(180deg,#00732F 0 33.4%,#fff 33.4% 66.7%,#000 66.7% 100%)'
STRIPES_TOP = 'inset 0 calc(var(--u)*.6) 0 #00732F,inset 0 calc(var(--u)*1.2) 0 #fff,inset 0 calc(var(--u)*1.8) 0 #000'

THEMES['u1'] = dict(occ='uae', name='National Day · A  Waving Flag', tag='EID AL ETIHAD · 2 DECEMBER', title='NATIONAL DAY SALE', num='', badge='55', css=(
    '.kbb-ssb.occ-u1 .ssb-in{background:linear-gradient(118deg,#ffffff 0%,#f5f6f5 100%);'
    '--ssb-tag-cm:#E31B23;--ssb-tag-cd:#E31B23;--ssb-title-cm:#00732F;--ssb-title-cd:#00732F;--ssb-clabel-cm:#000;--ssb-clabel-cd:#000;--ssb-cvalue-cm:#E31B23;--ssb-cvalue-cd:#E31B23}'
    '.kbb-ssb.occ-u1 .ssb-hero{position:relative}'
    '.kbb-ssb.occ-u1 .ssb-hero::before{content:"";position:absolute;inset-inline-end:0;top:0;bottom:0;width:50%;background:' + FLAG_WAVE + ' center/100% 100% no-repeat;'
    '-webkit-mask:linear-gradient(90deg,transparent 0,#000 4%);mask:linear-gradient(90deg,transparent 0,#000 4%)}'
    '.kbb-ssb.occ-u1 .ssb-copy,.kbb-ssb.occ-u1 .ssb-model{position:relative;z-index:1}'
    '.kbb-ssb.occ-u1 .ssb-super{text-shadow:none}'
    '.kbb-ssb.occ-u1 .ssb-coupon b{border-color:#00732F}'
    '.kbb-ssb.occ-u1 .ssb-model::before{content:"";position:absolute;left:2%;right:2%;bottom:0;height:86%;border-radius:50% 50% 0 0;'
    'background:radial-gradient(58% 58% at 50% 42%,rgba(255,255,255,.95),rgba(255,255,255,.55) 55%,transparent 74%)}'
    '.kbb-ssb.occ-u1 .ssb-model picture{position:relative}'
    '.kbb-ssb.occ-u1 .occ-badge{position:absolute;z-index:2;inset-inline-end:calc(var(--u)*31);top:calc(var(--u)*4);width:calc(var(--u)*9);height:calc(var(--u)*9);border-radius:50%;'
    'display:grid;place-items:center;background:#E31B23;color:#fff;font-weight:900;font-size:calc(var(--u)*3.6);box-shadow:0 0 0 calc(var(--u)*.5) #fff,0 calc(var(--u)*.6) calc(var(--u)*1.6) rgba(0,0,0,.25)}'
    '.kbb-ssb.occ-u1 .ssb-gifts{background:#fff;box-shadow:' + STRIPES_TOP + '}'
    '@media (max-width:900px){.kbb-ssb.occ-u1 .ssb-hero::before{width:44%}.kbb-ssb.occ-u1 .occ-badge{inset-inline-end:calc(var(--u)*27);top:calc(var(--u)*3)}}'
))

THEMES['u2'] = dict(occ='uae', name='National Day · B  Full Flag', tag='EID AL ETIHAD · 55 YEARS', title='NATIONAL DAY SALE', num='', badge='', css=(
    '.kbb-ssb.occ-u2 .ssb-in{background:linear-gradient(90deg,#E31B23 0 calc(var(--u)*6),transparent calc(var(--u)*6)),' + FLAG + ';'
    '--ssb-tag-cm:#E31B23;--ssb-tag-cd:#E31B23;--ssb-title-cm:#00732F;--ssb-title-cd:#00732F;--ssb-clabel-cm:#000;--ssb-clabel-cd:#000;--ssb-cvalue-cm:#E31B23;--ssb-cvalue-cd:#E31B23}'
    '.kbb-ssb.occ-u2 .ssb-hero{padding-inline-start:calc(var(--u)*9)}'
    '.kbb-ssb.occ-u2 .ssb-copy{flex:0 1 auto;align-self:center;background:rgba(255,255,255,.97);border-radius:calc(var(--u)*1.6);padding:calc(var(--u)*1.6) calc(var(--u)*3);'
    'box-shadow:0 calc(var(--u)*.8) calc(var(--u)*2.6) rgba(0,0,0,.25)}'
    '.kbb-ssb.occ-u2 .ssb-super{text-shadow:none}'
    '.kbb-ssb.occ-u2 .ssb-coupon b{border-color:#00732F}'
    '.kbb-ssb.occ-u2 .ssb-model{margin-inline-start:auto}'
    '.kbb-ssb.occ-u2 .ssb-model::before{content:"";position:absolute;left:4%;right:4%;bottom:0;height:82%;border-radius:50% 50% 0 0;'
    'background:radial-gradient(58% 58% at 50% 42%,rgba(255,255,255,.8),transparent 72%)}'
    '.kbb-ssb.occ-u2 .ssb-model picture{position:relative}'
    '.kbb-ssb.occ-u2 .ssb-gifts{background:#fff;border-top:0;box-shadow:inset 0 calc(var(--u)*.6) 0 #E31B23}'
    '@media (max-width:900px){.kbb-ssb.occ-u2 .ssb-in{background:linear-gradient(90deg,#E31B23 0 calc(var(--u)*3.5),transparent calc(var(--u)*3.5)),' + FLAG + '}'
    '.kbb-ssb.occ-u2 .ssb-hero{padding-inline-start:calc(var(--u)*5.5);padding-top:calc(var(--u)*2.4)}'
    '.kbb-ssb.occ-u2 .ssb-copy{padding:calc(var(--u)*1.6) calc(var(--u)*2.2);gap:calc(var(--u)*.5);margin-bottom:calc(var(--u)*2.4)}}'
))

THEMES['u3'] = dict(occ='uae', name='National Day · C  Flag Ribbon', tag='CELEBRATING 55 YEARS · EID AL ETIHAD', title='NATIONAL DAY SALE', num='', badge='', css=(
    '.kbb-ssb.occ-u3 .ssb-in{background:radial-gradient(70% 90% at 80% 20%,#ffffff,transparent 60%),linear-gradient(118deg,#f1f9f4 0%,#ffffff 50%,#fdf1f1 100%);'
    '--ssb-tag-cm:#000;--ssb-tag-cd:#000;--ssb-title-cm:#E31B23;--ssb-title-cd:#E31B23;--ssb-clabel-cm:#000;--ssb-clabel-cd:#000;--ssb-cvalue-cm:#00732F;--ssb-cvalue-cd:#00732F}'
    '.kbb-ssb.occ-u3 .ssb-hero{position:relative}'
    '.kbb-ssb.occ-u3 .ssb-hero::before{content:"";position:absolute;inset-inline-start:20%;inset-inline-end:0;top:0;bottom:0;background:' + RIBBON + ' center/100% 100% no-repeat;pointer-events:none}'
    '.kbb-ssb.occ-u3 .ssb-copy,.kbb-ssb.occ-u3 .ssb-model{position:relative;z-index:1}'
    '.kbb-ssb.occ-u3 .ssb-super{text-shadow:0 calc(var(--u)*.3) 0 #fff}'
    '.kbb-ssb.occ-u3 .ssb-coupon b{border-color:#E31B23}'
    '.kbb-ssb.occ-u3 .ssb-model::before{content:"";position:absolute;left:2%;right:2%;bottom:0;height:86%;border-radius:50% 50% 0 0;'
    'background:radial-gradient(58% 58% at 50% 42%,rgba(255,255,255,.97),rgba(255,255,255,.55) 55%,transparent 74%)}'
    '.kbb-ssb.occ-u3 .ssb-model picture{position:relative}'
    '.kbb-ssb.occ-u3 .ssb-gifts{background:#fff;box-shadow:' + STRIPES_TOP + '}'
    '@media (max-width:900px){.kbb-ssb.occ-u3 .ssb-hero::before{inset-inline-start:52%}}'
))

# ------------------------------------------------------------- New Year 2027
def _burst(cx, cy, r, color, n=14):
    out = ''
    for i in range(n):
        a = 2 * math.pi * i / n
        x1, y1 = cx + math.cos(a) * r * .35, cy + math.sin(a) * r * .35
        x2, y2 = cx + math.cos(a) * r, cy + math.sin(a) * r
        out += '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="2.4" stroke-linecap="round"/>' % (x1, y1, x2, y2, color)
        out += '<circle cx="%.1f" cy="%.1f" r="2.6" fill="%s"/>' % (x2, y2, color)
    return out


FIREWORKS = svg_url(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 300">'
    + _burst(120, 80, 60, '#F5C969') + _burst(330, 60, 44, '#ff8fc4') + _burst(470, 150, 70, '#F5C969', 18) + _burst(240, 210, 36, '#9fd8ff')
    + '</svg>')

CONFETTI = svg_url(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 200">'
    '<rect x="20" y="30" width="10" height="4" rx="2" fill="#E2B04A" transform="rotate(30 25 32)"/>'
    '<rect x="110" y="70" width="10" height="4" rx="2" fill="#ec4f86" transform="rotate(-25 115 72)"/>'
    '<rect x="200" y="20" width="10" height="4" rx="2" fill="#E2B04A" transform="rotate(60 205 22)"/>'
    '<rect x="260" y="120" width="10" height="4" rx="2" fill="#f7a6c4" transform="rotate(15 265 122)"/>'
    '<rect x="60" y="150" width="10" height="4" rx="2" fill="#E2B04A" transform="rotate(-50 65 152)"/>'
    '<circle cx="160" cy="140" r="3" fill="#E2B04A"/><circle cx="40" cy="95" r="2.5" fill="#ec4f86"/><circle cx="235" cy="75" r="2.5" fill="#E2B04A"/>'
    '</svg>')

NY_COMMON = (
    '@media (min-width:901px){.kbb-ssb[class*=" occ-n"] .ssb-copy{position:relative}.kbb-ssb[class*=" occ-n"] .ssb-super{font-size:calc(var(--u)*4.4)}.kbb-ssb[class*=" occ-n"] .occ-num{inset-inline-start:72%;font-size:calc(var(--u)*11.5)}}'
    '.kbb-ssb[class*=" occ-n"] .ssb-super{--ssb-title-sm:6.2;white-space:nowrap}'
)

THEMES['n1'] = dict(occ='ny', name='New Year · A  Midnight Gold', tag='NEW YEAR, NEW GLOW', title='NEW YEAR SALE', num='2027', css=NY_COMMON + (
    '.kbb-ssb.occ-n1 .ssb-in{background:radial-gradient(55% 90% at 55% 30%,rgba(245,201,105,.22),transparent 65%),linear-gradient(120deg,#0b1026 0%,#141a3a 55%,#231a3d 100%);'
    '--ssb-tag-cm:#F5D78E;--ssb-tag-cd:#F5D78E;--ssb-pay-cm:#fff;--ssb-pay-cd:#fff;--ssb-title-cm:#fff;--ssb-title-cd:#fff;--ssb-clabel-cm:#fff;--ssb-clabel-cd:#fff;--ssb-cvalue-cm:#9a6b12;--ssb-cvalue-cd:#9a6b12}'
    '.kbb-ssb.occ-n1 .ssb-hero{position:relative}'
    '.kbb-ssb.occ-n1 .ssb-hero::before{content:"";position:absolute;inset:0;pointer-events:none;'
    'background:radial-gradient(circle,#F5D78E 0 1.4px,transparent 2.4px) 0 0/44px 44px,radial-gradient(circle,#fff 0 1px,transparent 2px) 22px 22px/44px 44px;opacity:.5;'
    '-webkit-mask:linear-gradient(90deg,transparent 0,#000 40%);mask:linear-gradient(90deg,transparent 0,#000 40%)}'
    '.kbb-ssb.occ-n1 .ssb-super{text-shadow:0 0 calc(var(--u)*1.4) rgba(245,215,142,.5)}'
    '.kbb-ssb.occ-n1 .occ-num{display:block;font-weight:900;line-height:.9;letter-spacing:-.01em;color:#F5C969;'
    'text-shadow:0 calc(var(--u)*.35) 0 #9a6b12,0 0 calc(var(--u)*3) rgba(245,201,105,.55)}'
    '.kbb-ssb.occ-n1 .ssb-coupon b{border-color:#E2B04A;background:#fffaf0}'
    '.kbb-ssb.occ-n1 .ssb-model::before{content:"";position:absolute;left:6%;right:6%;bottom:0;height:80%;border-radius:50% 50% 0 0;'
    'background:radial-gradient(60% 60% at 50% 42%,rgba(245,201,105,.5),transparent 72%)}'
    '.kbb-ssb.occ-n1 .ssb-model picture{position:relative}'
    '.kbb-ssb.occ-n1 .ssb-gifts{background:#fff;border-top-color:#E2B04A}'
))

THEMES['n2'] = dict(occ='ny', name='New Year · B  Champagne Blush', tag='CHEERS TO GLOWING SKIN', title='NEW YEAR SALE', num='2027', css=NY_COMMON + (
    '.kbb-ssb.occ-n2 .ssb-in{background:' + CONFETTI + ' 0 0/300px 200px,radial-gradient(80% 110% at 85% 10%,#fff,transparent 55%),linear-gradient(118deg,#fde4ec 0%,#fff6f1 50%,#fbe7d8 100%);'
    '--ssb-tag-cm:#9a6b12;--ssb-tag-cd:#9a6b12;--ssb-title-cm:#E2336F;--ssb-title-cd:#E2336F;--ssb-cvalue-cm:#9a6b12;--ssb-cvalue-cd:#9a6b12}'
    '.kbb-ssb.occ-n2 .occ-num{display:block;font-weight:900;line-height:.9;letter-spacing:-.01em;color:#D9A441;'
    'text-shadow:0 calc(var(--u)*.35) 0 #a87a22,0 calc(var(--u)*1) calc(var(--u)*1.6) rgba(168,122,34,.3)}'
    '.kbb-ssb.occ-n2 .ssb-coupon b{border-color:#D9A441;background:#fffaf0}'
    '.kbb-ssb.occ-n2 .ssb-model::before{content:"";position:absolute;left:4%;right:4%;bottom:0;height:86%;border-radius:50% 50% 0 0;'
    'border:calc(var(--u)*.35) solid #E8C77E;border-bottom:0;background:radial-gradient(60% 60% at 50% 40%,#fff3df,transparent 75%)}'
    '.kbb-ssb.occ-n2 .ssb-model picture{position:relative}'
))

THEMES['n3'] = dict(occ='ny', name='New Year · C  Fireworks', tag='START THE YEAR GLOWING', title='NEW YEAR SALE', num='2027', css=NY_COMMON + (
    '.kbb-ssb.occ-n3 .ssb-in{background:linear-gradient(120deg,#1d0b3a 0%,#3a1466 55%,#6a1f6e 100%);'
    '--ssb-tag-cm:#ffd1e6;--ssb-tag-cd:#ffd1e6;--ssb-pay-cm:#fff;--ssb-pay-cd:#fff;--ssb-title-cm:#F5C969;--ssb-title-cd:#F5C969;--ssb-clabel-cm:#fff;--ssb-clabel-cd:#fff;--ssb-cvalue-cm:#C3306A;--ssb-cvalue-cd:#C3306A}'
    '.kbb-ssb.occ-n3 .ssb-hero{position:relative}'
    '.kbb-ssb.occ-n3 .ssb-hero::before{content:"";position:absolute;inset-inline-start:30%;inset-inline-end:0;top:0;bottom:0;background:' + FIREWORKS + ' center/cover no-repeat;opacity:.9;pointer-events:none}'
    '.kbb-ssb.occ-n3 .ssb-copy,.kbb-ssb.occ-n3 .ssb-model{position:relative;z-index:1}'
    '.kbb-ssb.occ-n3 .ssb-super{text-shadow:0 0 calc(var(--u)*1.6) rgba(245,201,105,.55)}'
    '.kbb-ssb.occ-n3 .occ-num{display:block;font-weight:900;line-height:.9;letter-spacing:-.01em;color:#fff;'
    'text-shadow:0 0 calc(var(--u)*.6) #fff,0 0 calc(var(--u)*2.4) rgba(255,143,196,.9),0 0 calc(var(--u)*5) rgba(255,143,196,.5)}'
    '.kbb-ssb.occ-n3 .ssb-coupon b{border-color:#ff8fc4}'
    '.kbb-ssb.occ-n3 .ssb-gifts{background:#fff}'
    '@media (max-width:900px){.kbb-ssb.occ-n3 .ssb-hero::before{inset-inline-start:52%;opacity:.8}}'
))
