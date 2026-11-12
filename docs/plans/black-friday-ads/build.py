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
PRODUCTS = ['isntree', 'guasha', 'marymay', 'axisy']

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
        for (p, dx, h, r) in [('isntree', -66, 150, -12), ('guasha', -24, 178, -4), ('marymay', 24, 188, 5), ('axisy', 64, 152, 12)]:
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
        b = base64.b64encode(open(os.path.join(ASSETS, p + '.webp'), 'rb').read()).decode()
        out += f'.p-{p}{{background-image:url(data:image/webp;base64,{b})}}'
    return out

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

# ── the live page ──
cards = {fmt: '' for fmt in F}
for i, s in enumerate(S, 1):
    for fmt in F:
        cards[fmt] += f'<figure class="opt"><figcaption><b>{i:02d}</b> {s[1]}</figcaption>{ad(i, s, fmt)}</figure>'
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
{AD_CSS}{prod_css()}
</style>
<div class="wrap">
<header><h1>Black Friday ads</h1><p class="lead">Your reference ad in ten colour schemes, plus three with products in the gift (11–13). Live previews; pick the numbers you want and they are exported at full size: post 1080×1350, square 1080×1080, story 1080×1920.</p></header>
<div class="tabs" role="group" aria-label="Size">
<button type="button" data-f="post" aria-pressed="true">Post · 4:5</button><button type="button" data-f="square" aria-pressed="false">Square · 1:1</button><button type="button" data-f="story" aria-pressed="false">Story · 9:16</button></div>
<section class="grid post" data-g="post">{cards['post']}</section>
<section class="grid square" data-g="square" hidden>{cards['square']}</section>
<section class="grid story" data-g="story" hidden>{cards['story']}</section>
</div>
<script>
document.querySelector('.tabs').addEventListener('click',function(e){{var b=e.target.closest('button');if(!b)return;
document.querySelectorAll('.tabs button').forEach(function(x){{x.setAttribute('aria-pressed',x===b?'true':'false')}});
document.querySelectorAll('[data-g]').forEach(function(g){{g.hidden=g.dataset.g!==b.dataset.f}});}});
</script>'''
open(os.path.join(D, 'gallery.html'), 'w').write(page)
print('ok', len(S), 'options;', os.path.getsize(os.path.join(D, 'gallery.html')) // 1024, 'KB')
