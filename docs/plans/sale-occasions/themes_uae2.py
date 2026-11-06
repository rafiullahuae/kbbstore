# Loaded by build.py after themes_extra.py; replaces u1-u3.
# The owner, 6 Nov: "i don't like the national day at all. i want to include
# layered flag. and building skyline, the actual buildings."
# Flat silhouettes of the real UAE landmarks (Burj Khalifa, Burj Al Arab,
# Museum of the Future, Dubai Frame, Ain Dubai, Emirates Towers, Sheikh Zayed
# Grand Mosque) and paper-cut layers of the flag. Pure SVG + CSS, no image file.
import math


def skyline(back, front, accent=None):
    """1000 x 240 viewBox, ground at y=240. Back layer: generic towers. Front: landmarks."""
    accent = accent or front
    b = ''
    # back layer: a city of plain towers
    xs = [(20, 70, 26), (50, 110, 22), (95, 85, 30), (140, 130, 20), (175, 95, 28), (300, 120, 24), (330, 90, 30), (420, 140, 22),
          (455, 100, 26), (560, 125, 24), (595, 95, 30), (700, 150, 22), (735, 110, 26), (850, 130, 24), (880, 100, 30), (940, 120, 26), (975, 80, 25)]
    for x, h, w in xs:
        b += '<rect x="%d" y="%d" width="%d" height="%d"/>' % (x, 240 - h, w, h)
    back_g = '<g fill="%s">%s</g>' % (back, b)

    f = ''
    # Sheikh Zayed Grand Mosque: domes + 4 minarets (left)
    f += '<rect x="30" y="200" width="150" height="40"/>'
    f += '<path d="M70 200 a35 35 0 0 1 70 0z"/><path d="M103 166 v-10 l2 -4 l2 4 v10z"/>'
    f += '<path d="M44 205 a14 14 0 0 1 28 0z"/><path d="M138 205 a14 14 0 0 1 28 0z"/>'
    for mx in (34, 172):
        f += '<rect x="%d" y="140" width="7" height="100"/><path d="M%d 140 l3.5 -12 l3.5 12z"/>' % (mx, mx)
    # Dubai Frame
    f += '<path d="M205 240 V110 H265 V240 H253 V122 H217 V240z"/>'
    # Emirates Towers (two, sloped tops)
    f += '<path d="M290 240 V95 L306 70 V240z"/><path d="M312 240 V115 L326 94 V240z"/>'
    # Burj Khalifa (stepped spire, tallest) centre
    f += ('<path d="M470 240 V200 H478 V160 H484 V120 H489 V82 H493 V48 H496 V10 L498 0 L500 10 V48 H503 V82 H507 V120 H512 V160 '
          'H518 V200 H526 V240z"/>')
    # Museum of the Future: torus on a green mound
    f += '<path d="M370 240 q35 -18 70 0z"/><ellipse cx="405" cy="190" rx="34" ry="30"/>'
    hole = '<ellipse cx="408" cy="188" rx="17" ry="14" fill="%s"/>' % ('#ffffff00',)
    # Ain Dubai (wheel)
    wheel = '<g fill="none" stroke="%s" stroke-width="3"><circle cx="640" cy="150" r="52"/>' % front
    for i in range(12):
        a = 2 * math.pi * i / 12
        wheel += '<line x1="640" y1="150" x2="%.1f" y2="%.1f" stroke-width="1.4"/>' % (640 + 52 * math.cos(a), 150 + 52 * math.sin(a))
    wheel += '</g><path d="M612 240 L640 150 L668 240 H660 L640 170 L620 240z"/>'
    # Burj Al Arab (sail + mast) right
    f += '<path d="M760 240 V70 L766 60 Q842 120 836 240z"/><rect x="756" y="50" width="4" height="190"/>'
    # Etihad-ish curved towers
    f += '<path d="M880 240 V120 Q892 100 904 112 V240z"/><path d="M910 240 V140 Q920 124 930 134 V240z"/>'
    front_g = ('<g fill="%s">' % front) + f + '</g>' + wheel
    # a waving UAE flag on its pole, beside the Burj
    pole = '<rect x="560" y="70" width="3" height="170" fill="%s"/>' % front
    def fw(y0, y1, col):
        top = ' '.join('L%.1f %.1f' % (563 + i * 4, y0 + 4 * math.sin(i / 15 * 2 * math.pi)) for i in range(16))
        bot = ' '.join('L%.1f %.1f' % (563 + i * 4, y1 + 4 * math.sin(i / 15 * 2 * math.pi)) for i in reversed(range(16)))
        return '<path fill="%s" d="M563 %.1f %s %s Z"/>' % (col, y0, top, bot)
    flag = ('<path fill="#E31B23" d="M563 72 L578 72 L578 112 L563 112Z"/>'
            + ''.join(fw(a_, b_, c_).replace('M563', 'M578').replace('L563', 'L578', 1) for a_, b_, c_ in ()) )
    bands = ''
    for y0, y1, col in ((72, 85.3, '#00732F'), (85.3, 98.6, '#FFFFFF'), (98.6, 112, '#000000')):
        top = ' '.join('L%.1f %.1f' % (578 + i * 3.5, y0 + 3 * math.sin(i / 15 * 2 * math.pi)) for i in range(16))
        bot = ' '.join('L%.1f %.1f' % (578 + i * 3.5, y1 + 3 * math.sin(i / 15 * 2 * math.pi)) for i in reversed(range(16)))
        bands += '<path fill="%s" stroke="%s" stroke-width=".4" d="M578 %.1f %s %s Z"/>' % (col, '#00000022', y0, top, bot)
    front_g += pole + flag + bands
    # museum hole cut: draw it in the sky colour afterwards (caller passes sky)
    return back_g, front_g


def svg(inner, vb='0 0 1000 240', par='xMidYMax meet'):
    import urllib.parse
    s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s" preserveAspectRatio="%s">%s</svg>' % (vb, par, inner)
    return 'url("data:image/svg+xml,' + urllib.parse.quote(s, safe=' =:/,;') + '")'


def flag_layers(shadow='rgba(0,0,0,.18)'):
    """Paper-cut flag layers: 1000 x 120, stretched across the bottom of the hero."""
    def wave(y, amp, ph, k=2.2):
        pts = ['M0 %.1f' % y]
        for i in range(0, 101):
            x = i * 10
            pts.append('L%.1f %.1f' % (x, y + 46 * (1 - i / 100) ** 1.6 + amp * math.sin(i / 100 * 2 * math.pi * k + ph)))
        return ' '.join(pts) + ' L1000 120 L0 120Z'
    out = ''
    for y, amp, ph, col in ((10, 7, 0.0, '#00732F'), (40, 6, 1.3, '#FFFFFF'), (72, 5, 2.6, '#000000')):
        out += '<path fill="%s" transform="translate(0 4)" d="%s"/>' % (shadow, wave(y, amp, ph))
        out += '<path fill="%s" d="%s"/>' % (col, wave(y, amp, ph))
    return out


SKY_A_BACK, SKY_A_FRONT = skyline('#c9d6dc', '#5d6f78')
SKY_B_BACK, SKY_B_FRONT = skyline('#e9a77c', '#3a2633')
SKY_C_BACK, SKY_C_FRONT = skyline('rgba(255,255,255,.35)', '#ffffff')

CITY_A = svg(SKY_A_BACK + SKY_A_FRONT + '<ellipse cx="408" cy="188" rx="17" ry="14" fill="#eef5f7"/>')
CITY_B = svg(SKY_B_BACK + SKY_B_FRONT + '<ellipse cx="408" cy="188" rx="17" ry="14" fill="#f6c49a"/>')
CITY_C = svg(SKY_C_BACK + SKY_C_FRONT + '<ellipse cx="408" cy="188" rx="17" ry="14" fill="#00732F"/>')
LAYERS = svg(flag_layers(), '0 0 1000 120', 'none')
RED = '#E31B23'

U_COMMON = (
    '.kbb-ssb[class*=" occ-u"] .ssb-hero{position:relative}'
    '.kbb-ssb[class*=" occ-u"] .ssb-copy,.kbb-ssb[class*=" occ-u"] .ssb-model{position:relative;z-index:1}'
    '.kbb-ssb[class*=" occ-u"] .ssb-super{text-shadow:none}'
    '.kbb-ssb[class*=" occ-u"] .ssb-model picture{position:relative}'
    '.kbb-ssb[class*=" occ-u"] .ssb-gifts{background:#fff;border-top:0;box-shadow:none}'
)


def theme(key, name, tag, sky_bg, city, layers_h, colours, extra=''):
    return dict(occ='uae', name=name, tag=tag, title='NATIONAL DAY SALE', num='', badge='', css=U_COMMON + (
        '.kbb-ssb.occ-%s .ssb-in{background:%s;%s}' % (key, sky_bg, colours)
        # the skyline, then the flag layers and the red hoist (the hoist stays inside the banner)
        + '.kbb-ssb.occ-%s .ssb-hero::before{content:"";position:absolute;inset:0;pointer-events:none;'
          'background:%s right calc(var(--u)*3) bottom calc(var(--u)*3)/auto 76%% no-repeat}' % (key, city)
        + '.kbb-ssb.occ-%s .ssb-hero::after{content:"";position:absolute;inset:0;pointer-events:none;'
          'background:linear-gradient(%s,%s) 0 0/calc(var(--u)*2) 100%% no-repeat,%s bottom/100%% %s no-repeat}' % (key, RED, RED, LAYERS, layers_h)
        + '@media (max-width:900px){.kbb-ssb.occ-%s .ssb-hero::before{background:%s right -26%% bottom calc(var(--u)*2)/auto 60%% no-repeat;'
          '-webkit-mask:linear-gradient(90deg,transparent 45%%,#000 72%%);mask:linear-gradient(90deg,transparent 45%%,#000 72%%)}'
          '.kbb-ssb.occ-%s .ssb-hero::after{background:linear-gradient(%s,%s) 0 0/calc(var(--u)*2) 100%% no-repeat,%s bottom/100%% 20%% no-repeat}}' % (key, city, key, RED, RED, LAYERS)
        + extra))


THEMES['u1'] = theme('u1', 'National Day · A  Skyline by Day', 'EID AL ETIHAD · 2 DECEMBER',
    'linear-gradient(180deg,#eef5f7 0%,#ffffff 70%)', CITY_A, '28%',
    '--ssb-tag-cm:#E31B23;--ssb-tag-cd:#E31B23;--ssb-title-cm:#00732F;--ssb-title-cd:#00732F;--ssb-clabel-cm:#000;--ssb-clabel-cd:#000;--ssb-cvalue-cm:#E31B23;--ssb-cvalue-cd:#E31B23',
    '.kbb-ssb.occ-u1 .ssb-coupon b{border-color:#00732F}'
    '')

THEMES['u2'] = theme('u2', 'National Day · B  Golden Dusk Skyline', 'CELEBRATING 55 YEARS · EID AL ETIHAD',
    'radial-gradient(40% 60% at 62% 70%,#fff3c4,transparent 70%),linear-gradient(180deg,#f7d9a8 0%,#f6c49a 55%,#f2b08f 100%)', CITY_B, '28%',
    '--ssb-tag-cm:#7a3b12;--ssb-tag-cd:#7a3b12;--ssb-title-cm:#00732F;--ssb-title-cd:#00732F;--ssb-clabel-cm:#000;--ssb-clabel-cd:#000;--ssb-cvalue-cm:#E31B23;--ssb-cvalue-cd:#E31B23',
    '.kbb-ssb.occ-u2 .ssb-super{text-shadow:0 calc(var(--u)*.3) 0 rgba(255,255,255,.8)}'
    '.kbb-ssb.occ-u2 .ssb-coupon b{border-color:#E31B23}')

THEMES['u3'] = theme('u3', 'National Day · C  Flag Sky', 'EID AL ETIHAD · 55 YEARS',
    'linear-gradient(180deg,#00732F 0%,#0a8a3c 100%)', CITY_C, '30%',
    '--ssb-tag-cm:#ffffff;--ssb-tag-cd:#ffffff;--ssb-pay-cm:#fff;--ssb-pay-cd:#fff;--ssb-title-cm:#ffffff;--ssb-title-cd:#ffffff;--ssb-clabel-cm:#fff;--ssb-clabel-cd:#fff;--ssb-cvalue-cm:#E31B23;--ssb-cvalue-cd:#E31B23',
    '.kbb-ssb.occ-u3 .ssb-super{text-shadow:0 calc(var(--u)*.35) 0 rgba(0,0,0,.3)}'
    '.kbb-ssb.occ-u3 .ssb-coupon b{border-color:#fff}'
    '.kbb-ssb.occ-u3 .ssb-model::before{content:"";position:absolute;left:4%;right:4%;bottom:0;height:84%;border-radius:50% 50% 0 0;'
    'background:radial-gradient(58% 58% at 50% 42%,rgba(255,255,255,.35),transparent 72%)}')
