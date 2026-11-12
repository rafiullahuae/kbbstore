# Black Friday social ads -- the owner's reference layout (dark card, glossy gift
# on top, date pill, BLACK / FRIDAY, line, button, glass heart, sparkles) in ten
# colour schemes, three with products rising out of the gift; post 1080x1350,
# square 1080x1080, story 1080x1920. Owner, 12 Nov 2026: "almost same, don't
# change anything major, just give me different options with different color
# scheme" ... "i need live previews. not images!" ... "in few options, put some
# products too."
#
# Every size is in cqw (the ad box is a size container), so one markup is the
# live preview at any width AND the 1080px file render.cjs photographs.
#   python3 build.py  ->  gallery.html (the live page) + out/<id>-<fmt>.html
import base64, os
D = os.path.dirname(os.path.abspath(__file__)); OUT = os.path.join(D, 'out')
ASSETS = os.path.join(D, '..', 'free-gifts-ad', 'assets')

# key, label, card, BLACK, accent (FRIDAY + button), button text, line, pill bg, pill text,
# gift light/mid/dark, heart, sparkle, backdrop (centre, edge), products?
S = [
  ('classic-red',   'Classic Red',      '#232123', '#FFFFFF', '#F0091E', '#FFFFFF', '#FFFFFF', 'rgba(255,255,255,.28)', '#FFFFFF', '#FFC2C8', '#FF6B78', '#D92B3D', '#7E1520', '#F0293A', ('#FFFFFF', '#EBEBEB'), False),
  ('shop-pink',     'Shop Pink',        '#211A1E', '#FFFFFF', '#E8306F', '#FFFFFF', '#FFFFFF', 'rgba(255,255,255,.26)', '#FFFFFF', '#FFD0E0', '#FF7FA8', '#D9356F', '#8A2147', '#FF4F93', ('#FFF6F9', '#F7DCE7'), False),
  ('gold-luxe',     'Gold Luxe',        '#141210', '#FFFFFF', '#E9B949', '#1A1408', '#F6E7C1', 'rgba(233,185,73,.22)', '#F6E7C1', '#FFF0C2', '#F3CF72', '#B8862E', '#6E5019', '#F1CF7A', ('#FBF6EA', '#E8DCC0'), False),
  ('rose-gold',     'Rose Gold',        '#2A1F22', '#FFFFFF', '#E7A08B', '#2A1F22', '#FBE3DA', 'rgba(231,160,139,.24)', '#FBE3DA', '#FFE1D6', '#F2B8A6', '#C9826E', '#7E493D', '#F2B8A6', ('#FFF7F3', '#F1DAD0'), False),
  ('neon-purple',   'Neon Purple',      '#17111F', '#FFFFFF', '#B44CFF', '#FFFFFF', '#FFFFFF', 'rgba(255,255,255,.22)', '#FFFFFF', '#E9CCFF', '#C77DFF', '#8B2FD6', '#461B72', '#C77DFF', ('#FAF5FF', '#E6D8F7'), False),
  ('electric-blue', 'Electric Blue',    '#0F1420', '#FFFFFF', '#2F7BFF', '#FFFFFF', '#FFFFFF', 'rgba(255,255,255,.22)', '#FFFFFF', '#CFE1FF', '#6EA8FF', '#2563EB', '#16357A', '#4E95FF', ('#F4F8FF', '#D9E3F5'), False),
  ('emerald',       'Emerald',          '#0F1A16', '#FFFFFF', '#12B76A', '#FFFFFF', '#FFFFFF', 'rgba(255,255,255,.22)', '#FFFFFF', '#C9F5DF', '#4ED39A', '#0E9A57', '#0A5634', '#3DDC97', ('#F3FBF7', '#D6EBE0'), False),
  ('sunset-orange', 'Sunset Orange',    '#1E1714', '#FFFFFF', '#FF6A1A', '#FFFFFF', '#FFFFFF', 'rgba(255,255,255,.24)', '#FFFFFF', '#FFD9BF', '#FF9A55', '#E45A12', '#80340B', '#FF8A3D', ('#FFF8F3', '#F3DECF'), False),
  ('white-red',     'White Card',       '#FFFFFF', '#111111', '#F0091E', '#FFFFFF', '#2B2B2B', 'rgba(0,0,0,.08)', '#3A3A3A', '#FFC2C8', '#FF6B78', '#D92B3D', '#FFC9CF', '#F0293A', ('#2A2628', '#0E0D0E'), False),
  ('blush-cream',   'Blush Cream',      '#FFF1F4', '#2A2228', '#C6395F', '#FFFFFF', '#4A3A44', 'rgba(198,57,95,.12)', '#8A2A47', '#FFE0EA', '#F7A6BE', '#D9567F', '#F4BCCC', '#E2557F', ('#6A2842', '#2E1320'), False),
  ('red-products',  'Classic Red · products',  '#232123', '#FFFFFF', '#F0091E', '#FFFFFF', '#FFFFFF', 'rgba(255,255,255,.28)', '#FFFFFF', '#FFC2C8', '#FF6B78', '#D92B3D', '#7E1520', '#F0293A', ('#FFFFFF', '#EBEBEB'), True),
  ('pink-products', 'Shop Pink · products',    '#211A1E', '#FFFFFF', '#E8306F', '#FFFFFF', '#FFFFFF', 'rgba(255,255,255,.26)', '#FFFFFF', '#FFD0E0', '#FF7FA8', '#D9356F', '#8A2147', '#FF4F93', ('#FFF6F9', '#F7DCE7'), True),
  ('gold-products', 'Gold Luxe · products',    '#141210', '#FFFFFF', '#E9B949', '#1A1408', '#F6E7C1', 'rgba(233,185,73,.22)', '#F6E7C1', '#FFF0C2', '#F3CF72', '#B8862E', '#6E5019', '#F1CF7A', ('#FBF6EA', '#E8DCC0'), True),
]
F = {'post': (1080, 1350), 'square': (1080, 1080), 'story': (1080, 1920)}

def gift(n, gl, gm, gd):
    return f'''<svg viewBox="0 0 200 230" width="100%" height="100%" aria-hidden="true"><defs>
<linearGradient id="bd{n}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{gm}" stop-opacity=".95"/><stop offset=".55" stop-color="{gd}" stop-opacity=".55"/><stop offset="1" stop-color="{gd}" stop-opacity="0"/></linearGradient>
<linearGradient id="bs{n}" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity="0"/><stop offset=".18" stop-color="#fff" stop-opacity=".28"/><stop offset=".4" stop-color="#fff" stop-opacity="0"/><stop offset=".85" stop-color="#000" stop-opacity=".12"/></linearGradient>
<linearGradient id="ld{n}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{gl}"/><stop offset=".5" stop-color="{gm}"/><stop offset="1" stop-color="{gd}"/></linearGradient>
<linearGradient id="rb{n}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{gl}" stop-opacity=".95"/><stop offset=".7" stop-color="{gm}" stop-opacity=".45"/><stop offset="1" stop-color="{gm}" stop-opacity="0"/></linearGradient>
<radialGradient id="bw{n}" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="{gl}"/><stop offset=".55" stop-color="{gm}"/><stop offset="1" stop-color="{gd}"/></radialGradient></defs>
<rect x="22" y="112" width="156" height="118" rx="10" fill="url(#bd{n})"/><rect x="22" y="112" width="156" height="118" rx="10" fill="url(#bs{n})"/>
<rect x="84" y="112" width="32" height="118" fill="url(#rb{n})"/>
<rect x="12" y="82" width="176" height="38" rx="12" fill="url(#ld{n})"/><rect x="12" y="82" width="176" height="14" rx="7" fill="#fff" opacity=".22"/>
<rect x="84" y="82" width="32" height="38" fill="{gl}" opacity=".75"/>
<path d="M100 86C82 58 52 34 40 52c-12 18 18 36 60 34z" fill="url(#bw{n})"/><path d="M100 86c18-28 48-52 60-34 12 18-18 36-60 34z" fill="url(#bw{n})"/>
<path d="M100 84C84 64 62 48 54 58c-6 9 14 22 46 26z" fill="{gd}" opacity=".35"/><path d="M100 84c16-20 38-36 46-26 6 9-14 22-46 26z" fill="{gd}" opacity=".35"/>
<path d="M48 50c4-6 14-4 22 2" stroke="#fff" stroke-opacity=".7" stroke-width="4" fill="none" stroke-linecap="round"/><path d="M134 50c8-6 18-6 22 0" stroke="#fff" stroke-opacity=".6" stroke-width="4" fill="none" stroke-linecap="round"/>
<ellipse cx="100" cy="84" rx="15" ry="11" fill="url(#bw{n})"/><ellipse cx="96" cy="80" rx="6" ry="3.5" fill="#fff" opacity=".55"/></svg>'''

def heart(n, h):
    return f'''<svg viewBox="0 0 100 92" width="100%" height="100%" aria-hidden="true"><defs>
<radialGradient id="hg{n}" cx=".32" cy=".28" r=".85"><stop offset="0" stop-color="{h}" stop-opacity=".62"/><stop offset=".45" stop-color="{h}" stop-opacity=".72"/><stop offset="1" stop-color="{h}" stop-opacity=".42"/></radialGradient></defs>
<path d="M50 90C20 68 2 50 2 28 2 13 13 2 27 2c9 0 17 5 23 13C56 7 64 2 73 2c14 0 25 11 25 26 0 22-18 40-48 62z" fill="url(#hg{n})"/>
<path d="M20 16c5-5 13-6 18-2" stroke="#fff" stroke-opacity=".3" stroke-width="3" fill="none" stroke-linecap="round"/></svg>'''

STAR = '<svg viewBox="0 0 24 24" width="100%" height="100%" aria-hidden="true"><path d="M12 0c.9 6.6 4.8 10.6 12 12-7.2 1.4-11.1 5.4-12 12-.9-6.6-4.8-10.6-12-12C7.2 10.6 11.1 6.6 12 0z" fill="{c}"/></svg>'

def ad(i, s, fmt):
    key, label, card, tc, acc, btc, sub, pill, pillt, gl, gm, gd, hc, spk, (b1, b2), prods = s
    W, H = F[fmt]
    top_extra = 185 if prods else 112           # products rise above the gift
    total = 561 + top_extra
    u = min(W * (0.84 if fmt == 'story' else 0.8) / 460, H * (0.74 if fmt == 'story' else 0.86) / total)
    c = lambda v: f'{v / W * 100:.3f}cqw'          # canvas px -> container width units
    U = lambda v: c(v * u)                          # reference units -> container units
    cw, ch = 460 * u, 561 * u
    left = (W - cw) / 2
    top = (H - total * u) / 2 + top_extra * u
    n = f'{i}{fmt[0]}'
    gx, gy = left + cw / 2 - 95 * u, top - 112 * u
    pr = ''
    if prods:
        # Four of the shop's own products standing in the open gift, fanned.
        for (p, dx, h, r) in [('balmpink', -66, 150, -12), ('guasha', -24, 178, -4), ('marymay', 24, 188, 5), ('anuacream', 64, 152, 12)]:
            pr += f'<i class="bp p-{p}" style="left:{c(left + cw / 2 + dx * u - h * u * .26)};top:{c(gy + 104 * u - h * u)};width:{c(h * u * .52)};height:{c(h * u)};transform:rotate({r}deg)"></i>'
    return f'''<div class="bf" style="aspect-ratio:{W}/{H};background:radial-gradient(70% 55% at 50% 40%,{b1},{b2})">
<div class="bc" style="left:{c(left)};top:{c(top)};width:{c(cw)};height:{c(ch)};border-radius:{U(26)};background:{card};box-shadow:0 {U(30)} {U(60)} rgba(0,0,0,.28),0 {U(6)} {U(16)} rgba(0,0,0,.18)">
<div class="bh" style="left:{U(250)};top:{U(300)};width:{U(250)};height:{U(230)}">{heart(n, hc)}</div>
<div class="bs" style="left:{U(42)};top:{U(118)};width:{U(30)};height:{U(30)}">{STAR.format(c=spk)}</div>
<div class="bs" style="left:{U(374)};top:{U(366)};width:{U(13)};height:{U(13)}">{STAR.format(c=spk)}</div>
<div class="bs" style="left:{U(376)};top:{U(384)};width:{U(30)};height:{U(30)}">{STAR.format(c=spk)}</div>
<div class="bt" style="top:{U(122)}"><span style="background:{pill};color:{pillt};font-size:{U(21)};padding:{U(9)} {U(22)}">Nov 10 - Nov 30</span></div>
<div class="bt bx" style="top:{U(186)};color:{tc};font-size:{U(70)}">BLACK</div>
<div class="bt bx" style="top:{U(252)};color:{acc};font-size:{U(70)}">FRIDAY</div>
<div class="bt bl" style="top:{U(338)};color:{sub};font-size:{U(23)}">Black Friday Perks To<br>Enjoy Now!</div>
<div class="bb" style="left:{U(36)};right:{U(36)};top:{U(448)};height:{U(77)};border-radius:{U(14)};background:{acc};color:{btc};font-size:{U(23)};box-shadow:0 {U(10)} {U(22)} {acc}55">See All Perks</div>
</div>{pr}<div class="bg" style="left:{c(gx)};top:{c(gy)};width:{U(190)};height:{U(218)};filter:drop-shadow(0 {U(10)} {U(14)} rgba(0,0,0,.25))">{gift(n, gl, gm, gd)}</div></div>'''

AD_CSS = '''.bf{position:relative;width:100%;container-type:inline-size;overflow:hidden;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
.bf .bc{position:absolute;overflow:hidden}.bf .bh{position:absolute;transform:rotate(-14deg)}.bf .bs{position:absolute}
.bf .bt{position:absolute;left:0;right:0;text-align:center}.bf .bt span{display:inline-block;font-weight:500;border-radius:99px}
.bf .bx{font-weight:900;line-height:1;letter-spacing:-.005em}.bf .bl{font-weight:500;line-height:1.45}
.bf .bb{position:absolute;display:flex;align-items:center;justify-content:center;font-weight:600}
.bf .bg{position:absolute}.bf .bp{position:absolute;display:block;background-position:center bottom;background-size:contain;background-repeat:no-repeat;filter:drop-shadow(0 .6cqw .8cqw rgba(0,0,0,.3))}'''

def prod_css():
    out = ''
    for p in PRODUCTS:
        f = os.path.join(D, 'assets', p + '.webp')
        b = base64.b64encode(open(f if os.path.exists(f) else os.path.join(ASSETS, p + '.webp'), 'rb').read()).decode()
        out += f'.p-{p}{{background-image:url(data:image/webp;base64,{b})}}'
    return out


# ════════════════════════ SET 2 (owner, 12 Nov: "designs are good, need to
# improve more, overall ... keep these existing to compare ... products in the
# square, story etc, in all versions, but only in few from each. spread the
# products images ... focus more on products ... little bit more large size") ═══
ASPECT = {'anua': 529 / 669, 'isntree': 409 / 819, 'guasha': 424 / 797, 'marymay': 234 / 1100, 'axisy': 251 / 823, 'eyemask': 900 / 874, 'patch': 671 / 383}
PRODUCTS = ['isntree', 'guasha', 'marymay', 'axisy', 'eyemask', 'patch', 'anua']
# Four places around the card, in the reference's units (card = 460 x 561):
# [x, y, height, rotation] -- left middle, right top, bottom-left corner, right lower.
SLOTS = [(-86, 190, 240, -13), (404, -62, 225, 11), (-104, 495, 185, -8), (386, 232, 192, 9)]
# key, label, scheme (index into S), products per slot or None
S2 = [
  ('red-spread',     'Classic Red · products',   0, ['guasha', 'marymay', 'eyemask', 'isntree']),
  ('red-clean',      'Classic Red',              0, None),
  ('pink-spread',    'Shop Pink · products',     1, ['isntree', 'axisy', 'eyemask', 'guasha']),
  ('gold-spread',    'Gold Luxe · products',     2, ['marymay', 'guasha', 'patch', 'axisy']),
  ('rose-clean',     'Rose Gold',                3, None),
  ('purple-spread',  'Neon Purple · products',   4, ['axisy', 'isntree', 'eyemask', 'marymay']),
  ('blue-clean',     'Electric Blue',            5, None),
  ('emerald-spread', 'Emerald · products',       6, ['guasha', 'axisy', 'patch', 'isntree']),
  ('white-spread',   'White Card · products',    8, ['isntree', 'marymay', 'eyemask', 'guasha']),
  ('blush-clean',    'Blush Cream',              9, None),
]

GIFT_ICON = '<svg viewBox="0 0 24 24" width="1.05em" height="1.05em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>'
FREE = 'Free Gifts with Every Order'

def freeline(i, acc, pill, pillt, tc, light, U):
    """The owner, 12 Nov: "remove the date and replace this line with Free Gifts
    with Every Order, in some stylish designs" -- four treatments, in turn."""
    style = (i - 1) % 4
    if style == 0:   # a ribbon with notched ends
        return (f'<span class="fl fl-rb" style="font-size:{U(14.5)};padding:{U(10)} {U(26)};background:linear-gradient(180deg,color-mix(in srgb,{acc} 80%,#fff),{acc});'
                f'color:#fff;box-shadow:0 {U(6)} {U(14)} {acc}55">{GIFT_ICON}{FREE}</span>')
    if style == 1:   # a dashed ticket
        return (f'<span class="fl fl-tk" style="font-size:{U(18)};padding:{U(9)} {U(20)};border:{U(2)} dashed {acc};border-radius:{U(12)};'
                f'color:color-mix(in srgb,{acc} {"100" if light else "62"}%,{"#000" if light else "#fff"});background:{acc}14">{GIFT_ICON}{FREE}</span>')
    if style == 2:   # a glass pill with a coloured gift icon
        return (f'<span class="fl fl-gl" style="font-size:{U(18)};padding:{U(10)} {U(22)};border-radius:99px;background:{pill};color:{pillt};'
                f'box-shadow:inset 0 0 0 {U(1)} rgba(255,255,255,.18)"><b style="color:{acc}">{GIFT_ICON}</b>{FREE}</span>')
    return (f'<span class="fl fl-sc" style="font-size:{U(21)};color:{tc}"><i style="background:{acc}"></i>'  # italic, ruled
            f'<b style="color:{acc}">{GIFT_ICON}</b><em>{FREE}</em><i style="background:{acc}"></i></span>')

# ═══ SET 3 (owner, 12 Nov: "add the products in different styles in the light
# colour versions and keep the background also light colours, don't reduce the
# size of the box. just add the products in little large sizes surroundings") ═══
def light(key, label, card, tc, acc, sub, gift, heart_c, bg):
    gl, gm, gd = gift
    return (key, label, card, tc, acc, '#FFFFFF', sub, 'rgba(0,0,0,.055)', tc, gl, gm, gd, heart_c, acc, bg, True)
LIGHT = {
    'white':    light('white', 'White & Red', '#FFFFFF', '#141214', '#F0091E', '#3A3638', ('#FFC2C8', '#FF6B78', '#D92B3D'), '#FFD3D8', ('#FFF7F8', '#F8E3E6')),
    'blush':    light('blush', 'Blush Pink', '#FFF5F8', '#2A2228', '#E8306F', '#4A3A44', ('#FFD0E0', '#FF7FA8', '#D9356F'), '#FFCADB', ('#FFFAFC', '#F8DFE9')),
    'ivory':    light('ivory', 'Ivory Gold', '#FFFDF7', '#2A2116', '#C9962F', '#5A4A32', ('#FFF0C2', '#F3CF72', '#B8862E'), '#F4E2B8', ('#FFFCF5', '#F1E7CF')),
    'lavender': light('lavender', 'Lavender', '#FCF9FF', '#251E33', '#8B3FD9', '#4B4060', ('#E9CCFF', '#C77DFF', '#8B2FD6'), '#E7D6FA', ('#FCFAFF', '#EAE0F8')),
    'mint':     light('mint', 'Mint', '#F7FFFB', '#12261D', '#0E9A57', '#33493F', ('#C9F5DF', '#4ED39A', '#0E9A57'), '#CDEFDC', ('#F8FFFB', '#DCF1E6')),
    'peach':    light('peach', 'Peach', '#FFF9F5', '#2B1C14', '#F2662A', '#5A4236', ('#FFD9BF', '#FF9A55', '#E45A12'), '#FFDCC7', ('#FFFBF8', '#FAE4D6')),
}
# Product placements around a FULL-SIZE card [x, y, height, rotation], card = 460 x 561.
# Each reaches only a card's edge strip (never the words or the button) and may bleed
# off the frame, which reads as a bold crop rather than a mistake.
EDGES   = [(-118, 150, 290, -10), (400, 112, 285, 9), (-128, 470, 205, -8), (372, -96, 215, 13)]
CORNERS = [(-150, -60, 300, -16), (402, 370, 300, 12), (-120, 455, 210, -6), (395, -70, 230, 15)]
SIDES   = [(-150, 120, 340, -7), (410, 110, 335, 7), (-125, 490, 185, -9), (385, 455, 175, 10)]
S3 = [
  ('l-white-edges',    'White & Red · products around',  LIGHT['white'],    ['guasha', 'marymay', 'eyemask', 'anua'], EDGES),
  ('l-blush-corners',  'Blush Pink · products in corners', LIGHT['blush'],  ['anua', 'guasha', 'patch', 'marymay'], CORNERS),
  ('l-ivory-sides',    'Ivory Gold · tall pair',          LIGHT['ivory'],    ['guasha', 'isntree', 'eyemask', 'anua'], SIDES),
  ('l-lavender-edges', 'Lavender · products around',      LIGHT['lavender'], ['anua', 'axisy', 'eyemask', 'guasha'], EDGES),
  ('l-mint-corners',   'Mint · products in corners',      LIGHT['mint'],     ['axisy', 'anua', 'eyemask', 'guasha'], CORNERS),
  ('l-peach-sides',    'Peach · tall pair',               LIGHT['peach'],    ['anua', 'guasha', 'patch', 'axisy'], SIDES),
  ('l-white-corners',  'White & Red · products in corners', LIGHT['white'],  ['marymay', 'guasha', 'eyemask', 'anua'], CORNERS),
  ('l-blush-sides',    'Blush Pink · tall pair',          LIGHT['blush'],    ['guasha', 'anua', 'eyemask', 'axisy'], SIDES),
]

def ad2(i, s2, fmt):
    key, label, si, prods = s2[:4]
    slots = s2[4] if len(s2) > 4 else SLOTS
    full = len(s2) > 4            # set 3: the card keeps its full size; products overlap its edges
    _, _, card, tc, acc, btc, sub, pill, pillt, gl, gm, gd, hc, spk, (b1, b2), _ = S[si] if isinstance(si, int) else si
    W, H = F[fmt]
    top_extra, bot_extra, side = (112 + 46, 175, 100) if prods and not full else (112, 0, 0)
    total = top_extra + 561 + bot_extra
    wf = {'post': .70, 'square': .66, 'story': .74}[fmt] if prods and not full else (0.84 if fmt == 'story' else 0.8)
    u = min(W * wf / 460, H * (0.74 if fmt == 'story' else 0.88) / total)
    c = lambda v: f'{v / W * 100:.3f}cqw'
    U = lambda v: c(v * u)
    cw, ch = 460 * u, 561 * u
    left = (W - cw) / 2
    top = (H - total * u) / 2 + top_extra * u
    n = f'v{i}{fmt[0]}'
    gx, gy = left + cw / 2 - 95 * u, top - 112 * u
    light = card.upper() in ('#FFFFFF', '#FFF1F4')
    pr = ''
    if prods:
        for p, (x, y, h, r) in zip(prods, slots):
            hh = h * {'patch': .55, 'eyemask': .86, 'anua': .78}.get(p, 1) * (1.08 if fmt == 'story' else 1)   # wide packs: lower, so not larger than the tubes
            w = hh * ASPECT[p]
            px, py, pw, ph = left + x * u, top + y * u, w * u, hh * u
            if full:
                # Set 3 (owner, 12 Nov: "the products must not cut. so only enlarge it
                # until the products fit in"): inside the frame with a margin that
                # covers the rotation, and only as large as reaches the card's edge
                # strip -- never the words or the button.
                m = W * .035
                left_side = px + pw / 2 < W / 2
                clear = py + ph < top + 112 * u or py > top + 528 * u      # above the words / below the button
                on_button = py < top + 530 * u and py + ph > top + 444 * u   # beside the button's rows
                reach = (150 if clear else (32 if on_button else 74)) * u   # how far into the card it may come
                if left_side:
                    px = max(px, m)
                    limit = left + reach - px                               # its right edge stops here
                else:
                    px = min(px, W - m - pw)
                    limit = px + pw - (left + cw - reach)                   # widest it may be from its left edge
                    limit = (px + pw) - max(px, left + cw - reach)
                if left_side and pw > limit > 0:
                    k = limit / pw
                    pw, ph = pw * k, ph * k
                if not left_side:
                    over = (left + cw - reach) - px                         # >0: starts outside the strip; fine
                    if px < left + cw - reach:
                        pass
                    else:
                        pass
                    px = max(px, left + cw - reach)                         # never further in than the strip
                    pw2 = min(pw, W - m - px)
                    k = pw2 / pw
                    pw, ph = pw * k, ph * k
                py = min(max(py, m), H - m - ph)
            pr += f'<i class="bp p-{p}" style="left:{c(px)};top:{c(py)};width:{c(pw)};height:{c(ph)};transform:rotate({r}deg)"></i>'
    glow_card = 'rgba(255,255,255,.07)' if not light else 'rgba(0,0,0,.05)'
    return f'''<div class="bf v2" style="aspect-ratio:{W}/{H};background:radial-gradient(38% 26% at 16% 18%,{acc}2e,transparent 70%),radial-gradient(44% 30% at 86% 86%,{acc}26,transparent 70%),radial-gradient(70% 55% at 50% 42%,{b1},{b2})">
<i class="bsp" style="left:{c(W * .1)};top:{c(H * .1)};width:{c(26)};height:{c(26)}">{STAR.format(c=spk)}</i><i class="bsp" style="left:{c(W * .86)};top:{c(H * .16)};width:{c(18)};height:{c(18)}">{STAR.format(c=spk)}</i><i class="bsp" style="left:{c(W * .08)};top:{c(H * .86)};width:{c(16)};height:{c(16)}">{STAR.format(c=spk)}</i>
<div class="bglow" style="left:{c(left + cw / 2 - 160 * u)};top:{c(gy - 10 * u)};width:{U(320)};height:{U(240)};background:radial-gradient(closest-side,{acc}55,transparent)"></div>
<div class="bc" style="left:{c(left)};top:{c(top)};width:{c(cw)};height:{c(ch)};border-radius:{U(28)};background:linear-gradient(180deg,color-mix(in srgb,{card} 90%,{'#000' if light else '#fff'}) 0%,{card} 38%);box-shadow:inset 0 0 0 {U(1.2)} {glow_card},0 {U(34)} {U(70)} rgba(0,0,0,.32),0 {U(8)} {U(18)} rgba(0,0,0,.18)">
<div class="bh" style="left:{U(250)};top:{U(300)};width:{U(250)};height:{U(230)}">{heart(n, hc)}</div>
<div class="bs" style="left:{U(22)};top:{U(176)};width:{U(26)};height:{U(26)}">{STAR.format(c=spk)}</div>
<div class="bs" style="left:{U(374)};top:{U(366)};width:{U(13)};height:{U(13)}">{STAR.format(c=spk)}</div>
<div class="bs" style="left:{U(376)};top:{U(384)};width:{U(30)};height:{U(30)}">{STAR.format(c=spk)}</div>
<div class="bt" style="top:{U(124)}">{freeline(i, acc, pill, pillt, tc, light, U)}</div>
<div class="bt bx" style="top:{U(186)};color:{tc};font-size:{U(72)}">BLACK</div>
<div class="bt bx bgr" style="top:{U(254)};font-size:{U(72)};background-image:linear-gradient(180deg,color-mix(in srgb,{acc} 72%,#fff) 0%,{acc} 55%,color-mix(in srgb,{acc} 88%,#000) 100%)">FRIDAY</div>
<div class="bt bl" style="top:{U(340)};color:{sub};font-size:{U(23)}">Black Friday Perks To<br>Enjoy Now!</div>
<div class="bb" style="left:{U(36)};right:{U(36)};top:{U(448)};height:{U(77)};border-radius:{U(16)};background:linear-gradient(180deg,color-mix(in srgb,{acc} 82%,#fff),{acc} 52%,color-mix(in srgb,{acc} 86%,#000));color:{btc};font-size:{U(23)};box-shadow:inset 0 {U(1.5)} 0 rgba(255,255,255,.35),0 {U(12)} {U(26)} {acc}66">See All Perks</div>
</div><div class="bg" style="left:{c(gx)};top:{c(gy)};width:{U(190)};height:{U(218)};filter:drop-shadow(0 {U(12)} {U(16)} rgba(0,0,0,.28))">{gift(n, gl, gm, gd)}</div>{pr}</div>'''

AD2_CSS = '''.bf.v2 .fl{display:inline-flex;align-items:center;gap:.45em;font-weight:700;white-space:nowrap;line-height:1}
.bf.v2 .fl b{display:inline-flex}
.bf.v2 .fl-rb{text-transform:uppercase;letter-spacing:.04em;clip-path:polygon(0 0,100% 0,95% 50%,100% 100%,0 100%,5% 50%)}
.bf.v2 .fl-tk{letter-spacing:.01em}
.bf.v2 .fl-gl{font-weight:600}
.bf.v2 .fl-sc{font-weight:600;gap:.5em}.bf.v2 .fl-sc em{font-style:italic;letter-spacing:.01em}.bf.v2 .fl-sc i{display:block;width:1.6em;height:.09em;border-radius:1em;opacity:.8}
.bf.v2 .bsp{position:absolute;opacity:.7}.bf.v2 .bglow{position:absolute;filter:blur(1.2cqw)}
.bf.v2 .bgr{-webkit-background-clip:text;background-clip:text;color:transparent}
.bf.v2 .bp{filter:drop-shadow(0 1.1cqw 1.3cqw rgba(0,0,0,.35)) drop-shadow(0 .2cqw .3cqw rgba(0,0,0,.2))}'''


# ═══ SET 4 · ROTATING (owner, 12 Nov: "don't overlap the product images on one
# another. also keep different products in different versions ... randomly 1-2
# products change. get from our best sellers ... the product sizes has the
# issues ... keep little bit medicube gua sha [bigger] bcz that's the main
# product. also remove the bottom line of black friday, and bring free gift
# line, make it big in two lines, increase the button font size" ... "the
# motive is to run all those versions in one ad, so user can see different
# products in the same ad time to time.") ═══
from PIL import Image as _Im
for _p in ['guasha', 'isntree', 'marymay', 'axisy', 'eyemask', 'patch', 'reliefsun', 'cosrx', 'dralthea', 'deodorant', 'anua',
           'balmred', 'balmpink', 'celimax', 'dalba', 'glowvanilla', 'anuacream', 'hairoil']:   # the cleaned cut-outs (no white fringe)
    _f = os.path.join(D, 'assets', _p + '.webp')
    _w, _h = _Im.open(_f).size
    ASPECT[_p] = _w / _h
PRODUCTS += ['reliefsun', 'cosrx', 'dralthea', 'deodorant', 'balmred', 'balmpink', 'celimax', 'dalba', 'glowvanilla', 'anuacream', 'hairoil']
# One real size per product, in card units of height: the same in every ad.
SIZE = {'guasha': 290, 'isntree': 212, 'marymay': 228, 'axisy': 196, 'anua': 190, 'eyemask': 165,
        'patch': 95, 'reliefsun': 205, 'cosrx': 208, 'dralthea': 196, 'deodorant': 205,
        'balmred': 205, 'balmpink': 205, 'celimax': 222, 'dalba': 232, 'glowvanilla': 232, 'anuacream': 228, 'hairoil': 232}
# The owner's list, 12 Nov: keep the gua sha, Anua mist mini, Mary&May, the deodorant and the eye
# patches; add the products he sent (balms, celimax, d'Alba, Glow Vanilla, Anua cream, hair oil).
TALL = ['celimax', 'dalba', 'anuacream', 'hairoil', 'glowvanilla', 'marymay', 'balmred', 'deodorant']   # above the title, right
NARROW = ['balmpink', 'marymay', 'balmred', 'deodorant', 'anuacream', 'celimax', 'balmpink', 'marymay']  # beside the button, left
SHORT = ['anua', 'eyemask', 'glowvanilla', 'anua', 'hairoil', 'eyemask', 'anua', 'dalba']             # beside the free-gifts line, right
FRAME_S = 3.2   # seconds each product set shows

def frames_for(i):
    """Three product sets for ad i -- mix and match (owner, 12 Nov): the gua sha always, the other
    three drawn from the whole list so every ad and every set is a different combination."""
    out = []
    for f in range(3):
        n = (i * 3 + f)
        pick = ['guasha']
        for lst, step in ((TALL, 5), (SHORT, 3), (NARROW, 7)):
            j = n * step
            while lst[j % len(lst)] in pick:
                j += 1
            pick.append(lst[j % len(lst)])
        out.append(pick)
    return out

# Where text sits on the card (card units, 460 x 561): how far a product may reach in.
def reach_at(y0, y1):
    rows = [(-999, 0, 999), (0, 126, 120), (126, 292, 76), (292, 410, 96), (410, 446, 86), (446, 532, 26), (532, 999, 999)]
    return min(r for a, b, r in rows if y0 < b and y1 > a)

def place(p, side, yc, k, W, H, left, top, cw, u, taken, rot):
    """A product at its own size x k, inside the frame, clear of the words and of every product placed before."""
    m = W * .03
    h = SIZE[p] * k
    w = h * ASPECT[p]
    for _ in range(40):
        y = (122 - h) if yc is None else yc - h / 2         # None: stands above the title
        ymin, ymax = (m - top) / u, (H - m - top) / u
        y = min(max(y, ymin), ymax - h)
        r = reach_at(y, y + h)
        if side == 'L':
            x = max((m - left) / u, r - w if r < 900 else -w * .35)
            fits = x + w <= r
        else:
            x = min((W - m - left) / u - w, 460 - r if r < 900 else 460 - w * .65)
            fits = x >= 460 - r
        clash = any(not (x + w + 8 <= a or a2 + 8 <= x or y + h + 8 <= b or b2 + 8 <= y) for a, b, a2, b2 in taken)
        if fits and not clash:
            taken.append((x, y, x + w, y + h))
            return x, y, w, h
        if h < SIZE[p] * k * .85:          # "standard sizes ... don't put too small": stop here
            break
        h *= .95; w *= .95
    taken.append((x, y, x + w, y + h))
    return x, y, w, h

ZONES = {  # side, vertical centre (card units; None = above the title), rotation -- one per product of a set
    'post':   [('L', 300, -7), ('R', None, 9), ('R', 330, 8), ('L', 560, -4)],
    'square': [('L', 300, -7), ('R', None, 9), ('R', 318, 8), ('L', 560, -4)],
    'story':  [('L', 650, -4), ('R', None, 9), ('R', 650, 5), ('L', 260, -6)],
}
KSCALE = {'post': 1.0, 'square': 1.25, 'story': .78}   # one size scale per format: every product keeps its own size

def ad4(i, sch, fmt, frame=None, bare=False, data=None):
    key, label, card, tc, acc, btc, sub, pill, pillt, gl, gm, gd, hc, spk, (b1, b2), _ = sch
    W, H = F[fmt]
    total = 112 + 561 + (215 if fmt == 'story' else 0)
    # post (owner, 12 Nov: "in square and instagram ads, keep the product images more big"): the card a
    # little narrower so the products beside it can be clearly larger; square already has the room.
    u = min(W * {'story': 0.84, 'post': 0.74, 'square': 0.8}[fmt] / 460, H * (0.80 if fmt == 'story' else 0.88) / total)
    c = lambda v: f'{v / W * 100:.3f}cqw'
    U = lambda v: c(v * u)
    cw, ch = 460 * u, 561 * u
    left = (W - cw) / 2
    top = (H - total * u) / 2 + 112 * u
    n = f'r{i}{fmt[0]}'
    gx, gy = left + cw / 2 - 95 * u, top - 112 * u
    sets = frames_for(i)
    prods = ''
    for f, ps in enumerate(sets):
        if frame is not None and f != frame:
            continue
        taken = [(135, -112, 325, 106)]            # the gift
        cls = '' if frame is not None else f' rf rf{f}'
        for p, (side, yc, rot) in zip(ps, ZONES[fmt]):
            x, y, w, h = place(p, side, yc, KSCALE[fmt], W, H, left, top, cw, u, taken, rot)
            if data is not None:
                data.setdefault(f, []).append({'p': p, 'x': round((left + x * u) / W * 100, 3), 'y': round((top + y * u) / W * 100, 3),
                                               'w': round(w * u / W * 100, 3), 'r': rot})
            if bare:
                continue
            prods += f'<i class="bp p-{p}{cls}" style="left:{c(left + x * u)};top:{c(top + y * u)};width:{U(w)};height:{U(h)};transform:rotate({rot}deg)"></i>'
    return f'''<div class="bf v2 v4" style="aspect-ratio:{W}/{H};background:radial-gradient(38% 26% at 16% 18%,{acc}22,transparent 70%),radial-gradient(44% 30% at 86% 86%,{acc}1c,transparent 70%),radial-gradient(70% 55% at 50% 42%,{b1},{b2})">
<i class="bsp" style="left:{c(W * .1)};top:{c(H * .08)};width:{c(24)};height:{c(24)}">{STAR.format(c=spk)}</i><i class="bsp" style="left:{c(W * .88)};top:{c(H * .07)};width:{c(18)};height:{c(18)}">{STAR.format(c=spk)}</i>
<div class="bglow" style="left:{c(left + cw / 2 - 160 * u)};top:{c(gy - 10 * u)};width:{U(320)};height:{U(240)};background:radial-gradient(closest-side,{acc}44,transparent)"></div>
<div class="bc" style="left:{c(left)};top:{c(top)};width:{c(cw)};height:{c(ch)};border-radius:{U(28)};background:linear-gradient(180deg,color-mix(in srgb,{card} 94%,#000) 0%,{card} 38%);box-shadow:inset 0 0 0 {U(1.2)} rgba(0,0,0,.05),0 {U(34)} {U(70)} rgba(0,0,0,.16),0 {U(8)} {U(18)} rgba(0,0,0,.1)">
<div class="bh" style="left:{U(250)};top:{U(300)};width:{U(250)};height:{U(230)}">{heart(n, hc)}</div>
<div class="bs" style="left:{U(26)};top:{U(196)};width:{U(22)};height:{U(22)}">{STAR.format(c=spk)}</div>
<div class="bs" style="left:{U(398)};top:{U(395)};width:{U(22)};height:{U(22)}">{STAR.format(c=spk)}</div>
<div class="bt bx" style="top:{U(136)};color:{tc};font-size:{U(76)}">BLACK</div>
<div class="bt bx bgr" style="top:{U(208)};font-size:{U(76)};background-image:linear-gradient(180deg,color-mix(in srgb,{acc} 72%,#fff) 0%,{acc} 55%,color-mix(in srgb,{acc} 88%,#000) 100%)">FRIDAY</div>
<div class="bt fg2" style="top:{U(300)};gap:{U(13)}"><span class="fg2i" style="width:{U(58)};height:{U(58)};background:radial-gradient(circle at 35% 30%,color-mix(in srgb,{acc} 70%,#fff),{acc} 70%);box-shadow:0 0 0 {U(6)} {acc}22,0 0 {U(24)} {acc}99">{GIFT_ICON}</span><span class="fg2t"><span class="fg2a" style="font-size:{U(32)};color:{acc}">FREE GIFTS</span><span class="fg2b" style="font-size:{U(24)};color:{tc}">with Every Order</span></span></div>
<div class="bb" style="left:{U(36)};right:{U(36)};top:{U(448)};height:{U(80)};border-radius:{U(16)};background:linear-gradient(180deg,color-mix(in srgb,{acc} 82%,#fff),{acc} 52%,color-mix(in srgb,{acc} 86%,#000));color:{btc};font-size:{U(29)};font-weight:700;box-shadow:inset 0 {U(1.5)} 0 rgba(255,255,255,.35),0 {U(12)} {U(26)} {acc}55">See All Perks</div>
</div><div class="bg" style="left:{c(gx)};top:{c(gy)};width:{U(190)};height:{U(218)};filter:drop-shadow(0 {U(12)} {U(16)} rgba(0,0,0,.2))">{gift(n, gl, gm, gd)}</div>{prods}
<div class="brib" style="left:{c(RIB_D - RIB_W / 2)};top:{c(RIB_D - RIB_H / 2)};width:{c(RIB_W)};height:{c(RIB_H)};font-size:{c(RIB_F)};background:linear-gradient(180deg,color-mix(in srgb,{acc} 80%,#fff),{acc} 55%,color-mix(in srgb,{acc} 85%,#000));box-shadow:0 {c(5)} {c(14)} rgba(0,0,0,.22)">K-Beauty Bliss Specials{HEART_ICON}</div></div>'''

# The corner ribbon (owner, 12 Nov: "a diagonal left upper corner strip with text. K-Beauty Bliss
# Specials + heart icon"), across the card's top-left corner, clear of the title and the gift.
# On the AD's own top-left corner (owner: "in the overall corner strip, not on the inner box").
RIB_D, RIB_W, RIB_H, RIB_F = 184, 800, 62, 27   # canvas px at 1080 wide: centre on the corner's diagonal, length, band, type
HEART_ICON = '<svg viewBox="0 0 24 24" width="1.05em" height="1.05em" aria-hidden="true"><path fill="currentColor" d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 4.5 6.8 4.5c2.2 0 3.6 1.2 4.2 2.4h2c.6-1.2 2-2.4 4.2-2.4 3.8 0 5.9 3.9 4.4 7.3C19.5 16.4 12 21 12 21z"/></svg>'
CYC = 3 * FRAME_S
AD4_CSS = (f'''.bf.v4 .brib{{position:absolute;transform:rotate(-45deg);z-index:5;display:flex;align-items:center;justify-content:center;gap:.35em;color:#fff;font-weight:800;letter-spacing:.02em;white-space:nowrap;z-index:2;border-top:1px solid rgba(255,255,255,.35)}}
.bf.v4 .fg2{{display:flex;flex-direction:row;align-items:center;justify-content:center;line-height:1.1}}
.bf.v4 .fg2i{{display:inline-grid;place-items:center;border-radius:50%;color:#fff;flex:none}}.bf.v4 .fg2i svg{{width:52%;height:52%}}
.bf.v4 .fg2t{{display:flex;flex-direction:column;align-items:flex-start;gap:.08em;text-align:left}}
.bf.v4 .fg2a{{display:inline-flex;align-items:center;gap:.3em;font-weight:900;letter-spacing:.03em}}.bf.v4 .fg2b{{font-weight:600;letter-spacing:.005em}}
.bf.v4 .rf{{opacity:0;animation:bfcyc {CYC}s linear infinite both}}
.bf.v4 .rf0{{animation-delay:-0.6s}}.bf.v4 .rf1{{animation-delay:{FRAME_S - 0.6:.1f}s}}.bf.v4 .rf2{{animation-delay:{2 * FRAME_S - 0.6:.1f}s}}
@keyframes bfcyc{{0%{{opacity:0}}6%{{opacity:1}}33.3%{{opacity:1}}39%{{opacity:0}}100%{{opacity:0}}}}
@media (prefers-reduced-motion:reduce){{.bf.v4 .rf{{animation:none}}.bf.v4 .rf0{{opacity:1}}}}''')
ROT = [('R01', LIGHT['white']), ('R02', LIGHT['blush']), ('R03', LIGHT['ivory']), ('R04', LIGHT['lavender']), ('R05', LIGHT['mint']), ('R06', LIGHT['peach'])]

# The owner removed these (12 Nov); every older set shows a kept product in their place.
REMOVED = {'cosrx': 'celimax', 'reliefsun': 'dalba', 'axisy': 'anuacream', 'dralthea': 'hairoil', 'isntree': 'balmpink', 'patch': 'glowvanilla'}
S2 = [(a, b, c, [REMOVED.get(p, p) for p in d] if d else d) for a, b, c, d in S2]
S3 = [(a, b, c, [REMOVED.get(p, p) for p in d], e) for a, b, c, d, e in S3]

FONT = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;800;900&display=swap">'

# ── the 1080px files ──
os.makedirs(OUT, exist_ok=True)
for f in os.listdir(OUT):
    if f.endswith('.html'):
        os.remove(os.path.join(OUT, f))
for i, s in enumerate(S, 1):
    for fmt, (W, H) in F.items():
        open(os.path.join(OUT, f'{i:02d}-{s[0]}-{fmt}.html'), 'w').write(
            f'<!doctype html><html><head><meta charset="utf-8">{FONT}<style>*{{margin:0;padding:0;box-sizing:border-box}}html,body{{width:{W}px;height:{H}px;overflow:hidden}}{AD_CSS}{prod_css() if s[15] else ""}</style></head><body>{ad(i, s, fmt)}</body></html>')

for i, s2 in enumerate(S2, 1):
    for fmt, (W, H) in F.items():
        open(os.path.join(OUT, f'v2-{i:02d}-{s2[0]}-{fmt}.html'), 'w').write(
            f'<!doctype html><html><head><meta charset="utf-8">{FONT}<style>*{{margin:0;padding:0;box-sizing:border-box}}html,body{{width:{W}px;height:{H}px;overflow:hidden}}{AD_CSS}{AD2_CSS}{prod_css() if s2[3] else ""}</style></head><body>{ad2(i, s2, fmt)}</body></html>')

for i, s3 in enumerate(S3, 1):
    for fmt, (W, H) in F.items():
        open(os.path.join(OUT, f'v3-{i:02d}-{s3[0]}-{fmt}.html'), 'w').write(
            f'<!doctype html><html><head><meta charset="utf-8">{FONT}<style>*{{margin:0;padding:0;box-sizing:border-box}}html,body{{width:{W}px;height:{H}px;overflow:hidden}}{AD_CSS}{AD2_CSS}{prod_css()}</style></head><body>{ad2(i + 20, s3, fmt)}</body></html>')

for i, (rid, sch) in enumerate(ROT, 1):
    for fmt, (W, H) in F.items():
        for fr in range(3):
            open(os.path.join(OUT, f'v4-{i:02d}-{sch[0]}-{fmt}-f{fr}.html'), 'w').write(
                f'<!doctype html><html><head><meta charset="utf-8">{FONT}<style>*{{margin:0;padding:0;box-sizing:border-box}}html,body{{width:{W}px;height:{H}px;overflow:hidden}}{AD_CSS}{AD2_CSS}{AD4_CSS}{prod_css()}</style></head><body>{ad4(i, sch, fmt, fr)}</body></html>')

# ── the live page ──
cards = {fmt: '' for fmt in F}
for i, s in enumerate(S, 1):
    for fmt in F:
        cards[fmt] += f'<figure class="opt"><figcaption><b>{i:02d}</b> {s[1]}</figcaption>{ad(i, s, fmt)}</figure>'
cards4 = {fmt: '' for fmt in F}
for i, (rid, sch) in enumerate(ROT, 1):
    for fmt in F:
        cards4[fmt] += f'<figure class="opt"><figcaption><b>{rid}</b> {sch[1]} · products change</figcaption>{ad4(i, sch, fmt)}</figure>'
cards3 = {fmt: '' for fmt in F}
for i, s3 in enumerate(S3, 1):
    for fmt in F:
        cards3[fmt] += f'<figure class="opt"><figcaption><b>L{i:02d}</b> {s3[1]}</figcaption>{ad2(i + 20, s3, fmt)}</figure>'
cards2 = {fmt: '' for fmt in F}
for i, s2 in enumerate(S2, 1):
    for fmt in F:
        cards2[fmt] += f'<figure class="opt"><figcaption><b>N{i:02d}</b> {s2[1]}</figcaption>{ad2(i, s2, fmt)}</figure>'
page = f'''<title>Black Friday Ads</title>
<meta name="claude:viewport-fit-sides" content="cover">
{FONT}
<style>
/* Layout: a gallery of live ads, one size at a time; the ads keep their own colours. */
:root{{--bg:#F1EEF0;--fg:#1F1B1E;--muted:#6B6268;--line:#DCD6DA;--chip:#FFFFFF;--on:#1F1B1E;--on-fg:#FFFFFF;--sans:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}}
@media (prefers-color-scheme:dark){{:root:not([data-theme="light"]){{--bg:#151315;--fg:#F3EFF2;--muted:#A79DA4;--line:#2E2A2D;--chip:#211E21;--on:#F3EFF2;--on-fg:#151315;color-scheme:dark}}}}
:root[data-theme="dark"]{{--bg:#151315;--fg:#F3EFF2;--muted:#A79DA4;--line:#2E2A2D;--chip:#211E21;--on:#F3EFF2;--on-fg:#151315;color-scheme:dark}}
body{{background:var(--bg);color:var(--fg);font-family:var(--sans);padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px));padding-block:20px 40px}}
.wrap{{max-width:1320px;margin:0 auto;display:grid;gap:18px}}
h1{{margin:0;font-size:clamp(22px,3vw,30px);font-weight:800;letter-spacing:-.01em;text-wrap:balance}}
.lead{{margin:4px 0 0;color:var(--muted);font-size:15px;line-height:1.5;max-width:70ch}}
.tabs{{display:flex;gap:8px;flex-wrap:wrap}}
.tabs button{{font:600 14px var(--sans);padding:9px 16px;border-radius:99px;border:1px solid var(--line);background:var(--chip);color:var(--fg);cursor:pointer}}
.tabs button[aria-pressed="true"]{{background:var(--on);color:var(--on-fg);border-color:var(--on)}}
.tabs button:focus-visible{{outline:2px solid var(--fg);outline-offset:2px}}
.grid{{display:grid;gap:22px;grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}}
.grid.story{{grid-template-columns:repeat(auto-fill,minmax(210px,1fr))}}
.opt{{margin:0;display:grid;gap:8px;min-width:0}}
.opt figcaption{{font-size:14px;font-weight:600}}.opt figcaption b{{color:var(--muted);font-variant-numeric:tabular-nums;margin-right:4px}}
.opt .bf{{border-radius:12px;box-shadow:0 1px 0 var(--line)}}
{AD_CSS}{AD2_CSS}{AD4_CSS}{prod_css()}
.tabs+.tabs{{margin-top:-8px}}
</style>
<div class="wrap">
<header><h1>Black Friday ads</h1><p class="lead">Rotating products (R01–R06): one ad whose products change every few seconds, one or two at a time, the gua sha always there; every product at one size everywhere, none overlapping, none cut. They are exported as video. Light versions (L01–L08): light cards on light backgrounds, the card at full size, larger products around it in three arrangements. New designs (N01–N10): richer light and finish, and six with your products spread around the card, larger, in every size. The first set (01–13) is kept to compare. Live previews; pick the numbers and they are exported at full size: post 1080×1350, square 1080×1080, story 1080×1920.</p></header>
<div class="tabs" role="group" aria-label="Set" data-k="set">
<button type="button" data-v="v4" aria-pressed="true">Rotating products</button><button type="button" data-v="v3" aria-pressed="false">Light · products around</button><button type="button" data-v="v2" aria-pressed="false">New designs</button><button type="button" data-v="v1" aria-pressed="false">First set</button></div>
<div class="tabs" role="group" aria-label="Size" data-k="fmt">
<button type="button" data-v="post" aria-pressed="true">Post · 4:5</button><button type="button" data-v="square" aria-pressed="false">Square · 1:1</button><button type="button" data-v="story" aria-pressed="false">Story · 9:16</button></div>
<section class="grid post" data-g="v4-post">{cards4['post']}</section>
<section class="grid square" data-g="v4-square" hidden>{cards4['square']}</section>
<section class="grid story" data-g="v4-story" hidden>{cards4['story']}</section>
<section class="grid post" data-g="v3-post" hidden>{cards3['post']}</section>
<section class="grid square" data-g="v3-square" hidden>{cards3['square']}</section>
<section class="grid story" data-g="v3-story" hidden>{cards3['story']}</section>
<section class="grid post" data-g="v2-post" hidden>{cards2['post']}</section>
<section class="grid square" data-g="v2-square" hidden>{cards2['square']}</section>
<section class="grid story" data-g="v2-story" hidden>{cards2['story']}</section>
<section class="grid post" data-g="v1-post" hidden>{cards['post']}</section>
<section class="grid square" data-g="v1-square" hidden>{cards['square']}</section>
<section class="grid story" data-g="v1-story" hidden>{cards['story']}</section>
</div>
<script>
var pick={{set:'v4',fmt:'post'}};
document.querySelectorAll('.tabs').forEach(function(t){{t.addEventListener('click',function(e){{var b=e.target.closest('button');if(!b)return;
pick[t.dataset.k]=b.dataset.v;t.querySelectorAll('button').forEach(function(x){{x.setAttribute('aria-pressed',x===b?'true':'false')}});
document.querySelectorAll('[data-g]').forEach(function(g){{g.hidden=g.dataset.g!==pick.set+'-'+pick.fmt}});}});}});
</script>'''
open(os.path.join(D, 'gallery.html'), 'w').write(page)

# ── the live editor (owner, 12 Nov: "give me the live editor, i will drag to resize the images and also
#    positioning in all versions" ... "need minimum 4 products"). Layouts save to the page's db;
#    Claude reads them back to export the files. ──
import json
ed_ads = []
for i, (rid, sch) in enumerate(ROT, 1):
    fm = {}
    for fmt, (W, H) in F.items():
        dd = {}
        base = ad4(i, sch, fmt, bare=True, data=dd)
        base = base[:base.rfind('</div>')] + '<div class="pl"></div></div>'
        fm[fmt] = {'base': base, 'W': W, 'H': H, 'def': [dd[f] for f in range(3)]}
    ed_ads.append({'id': rid, 'label': sch[1], 'acc': sch[4], 'f': fm})
EDDATA = json.dumps({'ads': ed_ads, 'aspect': {k: round(v, 4) for k, v in ASPECT.items()},
                     'names': {'guasha': 'medicube Gua Sha cream', 'anua': 'Anua mist mini', 'marymay': 'Mary&May PDRN serum', 'deodorant': 'medicube deodorant',
                               'eyemask': 'medicube eye patches', 'balmred': 'medicube EGF balm (red)', 'balmpink': 'medicube PDRN balm (pink)',
                               'celimax': 'celimax Retinal Shot', 'dalba': "d'Alba First Spray Serum", 'glowvanilla': 'medicube Glow Vanilla mist',
                               'anuacream': 'Anua PDRN cream', 'hairoil': 'medicube Rosemary hair oil'}}, ensure_ascii=False).replace('</', '<\\/')
editor = open(os.path.join(D, 'editor-template.html')).read()
editor = editor.replace('/*AD_CSS*/', AD_CSS + AD2_CSS + AD4_CSS.replace('.bf.v4 .rf{', '.bf.v4 .rf-x{') + prod_css()).replace('/*DATA*/', EDDATA).replace('{FONT}', FONT)
open(os.path.join(D, 'editor.html'), 'w').write(editor)
print('editor', os.path.getsize(os.path.join(D, 'editor.html')) // 1024, 'KB')
print('ok', len(S), 'options;', os.path.getsize(os.path.join(D, 'gallery.html')) // 1024, 'KB')
