#!/usr/bin/env python3
"""Builds docs/logo-options/index.html and snippets/<X>.html (Lane LG, preview only).

Nothing here is wired into the shop. Each snippet is the exact SVG + CSS one
option would add to a page; index.html shows all six large, in a 390px phone
header mock and in a 1280px desktop header mock.  Run:  python3 build.py
"""
import base64, gzip, os, pathlib

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent.parent

# Icon, fitted to storage/logo-brief/icon.jpg (1063px, scaled to a 1050 frame)
# by coordinate descent on a per-pixel class diff; 4.9% of ink pixels differ,
# all of it 1-2px edge antialiasing and stroke end caps. Translated by
# (-130,-219) so the viewBox starts at the origin.
F = [[284, 322, 529, 341, 569, 643, 324, 562], [651, 221, 537, 355, 601, 608, 770, 404],
     [678, 606, 772, 458, 908, 468, 868, 612], [132, 700, 320, 532, 564, 694, 319, 814],
     [664, 675, 837, 680, 867, 763, 743, 808]]
O = [[575, 657, 279, 548, 261, 375, 483, 384, 524, 596], [624, 548, 728, 392, 587, 236, 492, 404, 613, 627],
     [682, 605, 695, 462, 851, 434, 860, 550, 649, 653], [527, 692, 327, 570, 157, 751, 326, 848, 590, 698],
     [617, 685, 773, 764, 850, 697, 742, 616, 662, 692]]
DX, DY = 130, 219
VB = '0 0 780 582'


def _t(v):
    return [v[i] - (DX if i % 2 == 0 else DY) for i in range(len(v))]


def petal(f):
    f = _t(f)
    return 'M%d %dQ%d %d %d %dQ%d %d %d %dZ' % (*f, f[0], f[1])


def outline(o):
    return 'M%d %dQ%d %d %d %dQ%d %d %d %d' % tuple(_t(o))


FILLS = ''.join(petal(f) for f in F)
LINES = ''.join(outline(o) for o in O)
# a four-point glint, for option E (centred on the top petal and the right petal)
STAR1 = 'M512 50q6 46 42 52q-36 6-42 52q-6-46-42-52q36-6 42-52Z'
STAR2 = 'M712 270q4 30 28 34q-24 4-28 34q-4-30-28-34q24-4 28-34Z'


def svg(inner):
    return f'<svg viewBox="{VB}" aria-hidden="true">{inner}</svg>'


ICON_PLAIN = svg(f'<path class="f" d="{FILLS}"/><path class="o" d="{LINES}"/>')
ICON_SWAY = svg(f'<path class="f" d="{FILLS}"/><path class="f2" d="{FILLS}"/><path class="o" d="{LINES}"/>')
ICON_SWAY_O = svg(f'<path class="f" d="{FILLS}"/><path class="o" d="{LINES}"/><path class="o2" d="{LINES}"/>')
ICON_BLOOM = svg(''.join(f'<path class="f p{i}" d="{petal(f)}"/>' for i, f in enumerate(F))
                 + f'<path class="o" d="{LINES}"/>')
ICON_STAR = svg(f'<path class="f" d="{FILLS}"/><path class="f2" d="{FILLS}"/><path class="o" d="{LINES}"/>'
                f'<path class="g g1" d="{STAR1}"/><path class="g g2" d="{STAR2}"/>')

# One markup for every option, so the shop needs one template and a class.
# Words stay mixed case in the source (they are the Header settings' text);
# capitals come from text-transform.
def lockup(letter, icon):
    return (f'<a class="lg lg{letter}" href="#" aria-label="K-Beauty Bliss, Korean Skincare &amp; Makeup">{icon}'
            '<span class="t"><b class="w"><span>K-Beauty</span><i>Bliss</i></b>'
            '<small class="tg">Korean Skincare &amp; Makeup</small></span></a>')


BASE = """.lg{--s:22px;display:inline-flex;align-items:center;gap:calc(var(--s)*.36);position:relative;overflow:hidden;font:700 var(--s)/1 Outfit,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#2A2228;text-decoration:none;white-space:nowrap;vertical-align:middle}
.lg svg{display:block;flex:none;width:auto;height:calc(var(--s)*1.72)}
.lg .f,.lg .f2{fill:#D94A76}.lg .o,.lg .o2{fill:none;stroke:#E5567E;stroke-width:20;stroke-linecap:round;stroke-linejoin:round}
.lg .t{display:flex;flex-direction:column;gap:calc(var(--s)*.22)}
.lg .w{display:block;font-weight:inherit;text-transform:uppercase;letter-spacing:.035em}
.lg .w i{font-style:normal;color:#C6395F;margin-inline-start:.24em}
.lg .tg{display:block;font-size:max(9px,calc(var(--s)*.45));line-height:1.15;font-weight:500;letter-spacing:calc((var(--s) - 13px)*.17);text-transform:uppercase;color:#756C74}
@supports (-webkit-background-clip:text) or (background-clip:text){.lg .w i{background:linear-gradient(90deg,#A82F53,#C6395F 25%,#DF5280 50%,#C6395F 75%,#A82F53) 0 0/200% 100%;-webkit-background-clip:text;background-clip:text;color:transparent}}
@keyframes lg-drift{to{background-position:200% 0}}
@media (prefers-reduced-motion:reduce){.lg *,.lg::after{animation:none!important}.lg::after{content:none!important}}"""

# A diagonal sweep that lightens only what is not already white: on a white
# header, screen(white, x) is white, so the band shows on the letters and petals
# and nowhere else. Moved by transform only.
def sweep(letter, secs, alpha, width='34%', tint='255,255,255', rest='64%'):
    return (f""".lg{letter}::after{{content:"";position:absolute;top:-30%;bottom:-30%;left:0;width:{width};background:linear-gradient(90deg,rgba({tint},0),rgba({tint},{alpha}),rgba({tint},0));mix-blend-mode:screen;pointer-events:none;transform:translateX(-170%) skewX(-18deg);animation:lg{letter}-s {secs}s cubic-bezier(.45,0,.25,1) infinite}}
@keyframes lg{letter}-s{{0%,{rest}{{transform:translateX(-170%) skewX(-18deg)}}100%{{transform:translateX(330%) skewX(-18deg)}}}}""")


OPTIONS = {
    'A': dict(
        name='Signature',
        line='Icon spans both lines · BLISS drifts slowly through the pinks · letter-spaced grey tagline · a white sweep crosses icon and name every 6 s.',
        icon=ICON_PLAIN, phone='16px',
        css=""".lgA .w i{animation:lg-drift 10s linear infinite}
""" + sweep('A', 6, .75)),
    'B': dict(
        name='Hairline',
        line='Icon spans both lines · the petals breathe between deep and soft rose · tagline in sentence case under a thin pink rule · sweep every 7 s.',
        icon=ICON_SWAY, phone='16px',
        css=""".lgB .t{gap:calc(var(--s)*.1)}
.lgB .w i{animation:lg-drift 12s linear infinite}
.lgB .tg{font-size:max(9px,calc(var(--s)*.46));line-height:1.05;font-weight:400;letter-spacing:.07em;text-transform:none;color:#5E545A;border-top:1px solid #F3C3D1;padding-top:calc(var(--s)*.1)}
.lgB .f2{fill:#F08AA8;opacity:0;animation:lgB-b 7s ease-in-out infinite alternate}
@keyframes lgB-b{to{opacity:.85}}
""" + sweep('B', 7, .7)),
    'C': dict(
        name='Bloom',
        line='Small icon on the name line, tagline in pink running the full width underneath · the petals fill in one by one, then let go · BLISS drifts · sweep every 8 s.',
        icon=ICON_BLOOM, phone='17px',
        css=""".lgC{display:inline-grid;grid-template-columns:auto auto;column-gap:calc(var(--s)*.3);row-gap:calc(var(--s)*.1);align-items:end}
.lgC .t{display:contents}
.lgC svg{height:calc(var(--s)*1.06)}
.lgC .tg{grid-column:1/-1;text-align:justify;text-align-last:justify;letter-spacing:calc(var(--s)*.14 - .8px);color:#C6395F;font-weight:600}
.lgC .w i{animation:lg-drift 10s linear infinite}
.lgC .f{animation:lgC-p 7s ease-in-out infinite}
.lgC .p1{animation-delay:.35s}.lgC .p2{animation-delay:.7s}.lgC .p3{animation-delay:1.05s}.lgC .p4{animation-delay:1.4s}
@keyframes lgC-p{0%,100%{opacity:.22}20%,78%{opacity:1}}
""" + sweep('C', 8, .7)),
    'D': dict(
        name='Pearl',
        line='Icon spans both lines · lighter 600 weight, wide tracking · pink tagline between two hairlines · outlines shimmer lighter and back · a broad pearl sheen every 7 s.',
        icon=ICON_SWAY_O, phone='15px',
        css=""".lgD .w{font-weight:600;letter-spacing:.09em}
.lgD .w i{animation:lg-drift 11s linear infinite}
.lgD .tg{display:flex;align-items:center;gap:.5em;color:#C6395F;letter-spacing:calc(var(--s)*.1 - 1.2px);font-weight:500}
.lgD .tg::before,.lgD .tg::after{content:"";flex:1;min-width:0;height:1px;background:currentColor;opacity:.4}
.lgD .o2{stroke:#F7B3C7;opacity:0;animation:lgD-o 5s ease-in-out infinite alternate}
@keyframes lgD-o{to{opacity:1}}
""" + sweep('D', 7, .6, width='55%', tint='255,244,248', rest='55%')),
    'E': dict(
        name='Sparkle',
        line='Icon spans both lines · heavier 800 name · petals and BLISS drift together · two four-point glints twinkle on the petals · grey spaced tagline.',
        icon=ICON_STAR, phone='16px',
        css=""".lgE .w{font-weight:800;letter-spacing:.02em}
.lgE .w i{animation:lg-drift 8s linear infinite}
.lgE .tg{font-weight:400;letter-spacing:calc((var(--s) - 13px)*.19)}
.lgE .f2{fill:#B9365E;opacity:0;animation:lgE-b 4s ease-in-out infinite alternate}
@keyframes lgE-b{to{opacity:.7}}
.lgE .g{fill:#fff;transform-box:fill-box;transform-origin:center;transform:scale(0);animation:lgE-g 4.5s ease-in-out infinite}
.lgE .g2{animation-delay:1.6s}
@keyframes lgE-g{0%,55%,100%{transform:scale(0) rotate(0);opacity:0}70%{transform:scale(1) rotate(45deg);opacity:1}85%{transform:scale(.3) rotate(90deg);opacity:0}}
@media (prefers-reduced-motion:reduce){.lgE .g{transform:scale(.85);opacity:1}}"""),
    'F': dict(
        name='Rose glow',
        line='Icon spans both lines · a rose light runs through the ink letters, then a white glint over BLISS, every 6 s · BLISS drifts · petals breathe · pink tagline.',
        icon=ICON_SWAY, phone='16px',
        css=""".lgF .t{gap:calc(var(--s)*.16)}
.lgF .tg{text-transform:none;font-size:max(9px,calc(var(--s)*.48));letter-spacing:.08em;color:#C6395F;font-weight:500}
.lgF .f2{fill:#F08AA8;opacity:0;animation:lgF-p 6s ease-in-out infinite alternate}
@keyframes lgF-p{to{opacity:.8}}
@supports (-webkit-background-clip:text) or (background-clip:text){
.lgF .w span{background:linear-gradient(100deg,transparent 44%,#E5567E 50%,transparent 56%) 100% 0/300% 100% no-repeat,linear-gradient(#2A2228,#2A2228);-webkit-background-clip:text;background-clip:text;color:transparent;animation:lgF-k 6s linear infinite}
.lgF .w i{background:linear-gradient(100deg,transparent 44%,rgba(255,255,255,.85) 50%,transparent 56%) 100% 0/300% 100% no-repeat,linear-gradient(90deg,#A82F53,#C6395F 25%,#DF5280 50%,#C6395F 75%,#A82F53) 0 0/200% 100%;-webkit-background-clip:text;background-clip:text;animation:lgF-b 6s linear infinite}}
@keyframes lgF-k{0%{background-position:100% 0,0 0}20%,100%{background-position:0 0,0 0}}
@keyframes lgF-b{0%{background-position:100% 0,0 0}7%{background-position:100% 0,14% 0}27%{background-position:0 0,54% 0}100%{background-position:0 0,200% 0}}"""),
}


def snippet(letter):
    o = OPTIONS[letter]
    css = BASE + '\n' + o['css']
    return css, lockup(letter, o['icon'])


def gz(s):
    return len(gzip.compress(s.encode(), 9))


def main():
    sn = HERE / 'snippets'
    sn.mkdir(exist_ok=True)
    rows = []
    for k in OPTIONS:
        css, html = snippet(k)
        (sn / f'{k}.html').write_text(f'<style>\n{css}\n</style>\n{html}\n')
        rows.append((k, len(html), len(css), gz(html), gz(css), gz(css + html)))
    font = base64.b64encode((ROOT / 'resources/fonts/outfit/outfit-latin.woff2').read_bytes()).decode()
    all_css = BASE + '\n' + '\n'.join(o['css'] for o in OPTIONS.values())
    ico = {
        'burger': '<svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
        'user': '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>',
        'heart': '<svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-8.6-9A4.6 4.6 0 0 1 12 6.6 4.6 4.6 0 0 1 20.6 11C19 15.6 12 20 12 20Z"/></svg>',
        'bag': '<svg viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>',
        'search': '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="m20 20-4-4"/></svg>',
    }
    acts = ''.join(f'<span class="ib">{ico[n]}</span>' for n in ('user', 'heart', 'bag'))
    current = '<a class="cur" href="#"><bdi>K-Beauty<span>Bliss</span></bdi></a>'
    cards = []
    for k, o in OPTIONS.items():
        html = snippet(k)[1]
        cards.append(f"""
<section class="opt" id="opt-{k}">
  <header class="oh"><span class="L">{k}</span><div><h2>{o['name']}</h2><p>{o['line']}</p></div></header>
  <div class="big">{html}</div>
  <div class="mocks">
    <figure><figcaption>Header · phone 390px · logo {o['phone']}</figcaption>
      <div class="ph" data-opt="{k}" style="--lg-m:{o['phone']}"><div class="hin"><span class="ib">{ico['burger']}</span><span class="slot">{html}</span><span class="hact">{acts}</span></div>
      <div class="sr">{ico['search']}<span>Search K-beauty…</span></div></div>
    </figure>
  </div>
  <figure class="dkfig"><figcaption>Header · computer 1280px · logo 22px</figcaption>
    <div class="dkscroll"><div class="dk" data-opt="{k}"><div class="hin">{html}<div class="sbox">{ico['search']}<span>Search skincare, makeup, brands…</span></div><span class="hact">{acts}</span></div>
    <nav><a>Skincare</a><a>Makeup</a><a>Brands</a><a>Sets &amp; Gifts</a><a>New in</a><a>Best sellers</a><a>Sale</a></nav></div></div>
  </figure>
</section>""")
    table = ''.join(f'<tr><td><b>{k}</b></td><td>{OPTIONS[k]["name"]}</td><td>{h:,}</td><td>{c:,}</td><td>{gh:,}</td><td>{gc:,}</td><td><b>{g:,}</b></td></tr>'
                    for k, h, c, gh, gc, g in rows)
    page = f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Logo Options</title>
<style>
@font-face{{font-family:Outfit;font-weight:100 900;font-display:swap;src:url(data:font/woff2;base64,{font}) format("woff2")}}
:root{{--ink:#2A2228;--ink-2:#5E545A;--muted:#756C74;--pink:#C6395F;--line:rgba(42,34,40,.1);--bg:#FFF8F5;--card:#fff}}
@media (prefers-color-scheme:dark){{:root:not([data-theme="light"]){{--bg:#F3ECEA}}}}
:root[data-theme="dark"]{{--bg:#F3ECEA}}
*{{box-sizing:border-box}}
body{{overflow-x:clip;margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Outfit,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}}
.page{{max-width:1280px;margin:0 auto;padding:32px 16px 64px}}
h1{{font-size:28px;margin:0 0 6px;letter-spacing:-.01em}}
.lead{{color:var(--ink-2);margin:0 0 18px;max-width:72ch}}
.facts{{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin:0 0 28px;display:grid;gap:14px;grid-template-columns:minmax(0,1fr) auto;align-items:center}}
.facts ul{{margin:0;padding-left:18px;color:var(--ink-2);font-size:14px}}
.cur{{font:700 22px/1 Outfit,system-ui,sans-serif;letter-spacing:-.03em;color:#2A2228;text-decoration:none;white-space:nowrap}}.cur span{{color:#C6395F}}
.opt{{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px 22px 26px;margin:0 0 28px}}
.oh{{display:flex;gap:16px;align-items:center;margin-bottom:14px}}
.L{{flex:none;width:58px;height:58px;border-radius:14px;background:#2A2228;color:#fff;display:grid;place-items:center;font:700 34px/1 Outfit,system-ui,sans-serif}}
.oh h2{{margin:0;font-size:20px}}.oh p{{margin:2px 0 0;color:var(--ink-2);font-size:14px}}
.big{{display:flex;justify-content:center;align-items:center;padding:44px 12px;border-radius:12px;background:#fff;border:1px dashed var(--line);overflow:hidden}}
.big .lg{{--s:clamp(24px,6vw,58px)}}
figure{{margin:18px 0 0}}figcaption{{font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}}
svg{{flex:none}}
.ib{{width:36px;height:36px;display:grid;place-items:center;color:#2A2228;flex:none}}
.ib svg,.sr svg,.sbox svg{{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}}
.ph{{width:390px;max-width:100%;background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px 16px 10px;box-shadow:0 10px 30px -22px rgba(42,34,40,.5)}}
.ph .hin{{display:flex;align-items:center;gap:10px;min-height:44px}}
.ph .slot{{flex:1;min-width:0;display:flex;justify-content:center;align-items:center;min-height:44px}}
.ph .lg{{--s:var(--lg-m)}}
.hact{{display:flex;align-items:center}}
.sr{{margin-top:8px;height:34px;border-radius:999px;background:#F6F1F2;display:flex;align-items:center;gap:8px;padding:0 14px;color:var(--muted);font-size:13.5px}}
.sr svg{{width:17px;height:17px}}
.dkfig{{width:100vw;margin-inline:calc(50% - 50vw)}}.dkfig figcaption{{max-width:1236px;margin-inline:auto;padding-inline:16px}}
.dkscroll{{overflow-x:auto;border-block:1px solid var(--line);background:#fff}}
.dk{{width:1280px;max-width:none;margin-inline:auto;background:#fff}}
.dk .hin{{display:flex;align-items:center;gap:20px;min-height:40px;padding:12px 24px}}
.dk .lg{{--s:22px}}
.sbox{{flex:1;max-width:560px;margin-inline:auto;height:30px;border-radius:999px;background:#F6F1F2;display:flex;align-items:center;gap:8px;padding:0 14px;color:var(--muted);font-size:13.5px}}
.sbox svg{{width:16px;height:16px}}
.dk .hact{{gap:6px}}
.dk nav{{display:flex;gap:28px;padding:0 24px;height:38px;align-items:center;border-top:1px solid var(--line);font-size:14px;font-weight:500}}
table{{border-collapse:collapse;font-size:14px;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden;width:100%;max-width:760px}}
th,td{{text-align:right;padding:7px 12px;border-bottom:1px solid var(--line)}}th:nth-child(-n+2),td:nth-child(-n+2){{text-align:left}}
th{{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}}
.tw{{overflow-x:auto}}
.note{{color:var(--ink-2);font-size:13.5px;max-width:80ch}}
/* ===== the six options: exactly what snippets/*.html carry ===== */
{all_css}
</style></head><body><main class="page">
<h1>Logo options · K-Beauty Bliss</h1>
<p class="lead">Six ways to put the lotus beside the name in capitals, with “Korean Skincare &amp; Makeup” underneath. Same two colours as today (ink <b>#2A2228</b>, accent pink <b>#C6395F</b>), same typeface (Outfit). The colour movement never leaves the brand pinks, and every option stands still, fully formed, for anyone whose device asks for reduced motion. <b>Nothing on the shop has changed; pick a letter.</b></p>
<div class="facts"><ul>
<li>Today: <b>K-Beauty</b> in ink #2A2228 + <b>Bliss</b> in pink #C6395F, Outfit 700, 22px on computer and phone (Appearance → Header → Logo).</li>
<li>Header logo area: 40px-tall bar on computer; 44px tap row on phone, about 194px wide between the menu button and the three icons.</li>
<li>Motion is CSS only: background-position, transform and opacity. No script, no measuring, no extra request.</li></ul>
<div>{current}</div></div>
{''.join(cards)}
<h2>What each option adds to a page</h2>
<div class="tw"><table><tr><th>Option</th><th></th><th>SVG+markup bytes</th><th>CSS bytes</th><th>markup gz</th><th>CSS gz</th><th>Total gz</th></tr>{table}</table></div>
<p class="note">Bytes are measured from <code>snippets/&lt;letter&gt;.html</code>: the inline icon, the lockup markup and that option's whole stylesheet, gzip -9. Today's wordmark markup is about 80 bytes.</p>
</main></body></html>"""
    (HERE / 'index.html').write_text(page)
    for r in rows:
        print('%s markup %d css %d | gz markup %d css %d total %d' % r)


if __name__ == '__main__':
    main()
