"""Live previews: a "Free gifts with every order. Limited time offer!" strip on the product page,
above the tabby/tamara cards, with a little more space above and below (owner, 13 Nov 2026:
"urgent: on product page, i need a beautiful strip ... give me multiple options with shiny continue
effects etc style. show me live previews, keep this strip above tabby tamara section with little
more spacing up n down").

The strips are live HTML/CSS (the motion is the point); the page around them is a photograph of
the real product page from tools/pdp-preview.sh (phone 390px, computer right column), so each
option is seen exactly where it would sit. Nothing ships until he picks a letter.

  python3 preview.py <dir with fg2-*.png>   -> preview.html
"""
import base64, io, os, sys
from PIL import Image

D = os.path.dirname(os.path.abspath(__file__))
SRC = sys.argv[1]


IMGCSS = []


def img(name, w):
    """Each photo once, as a CSS class; every option reuses it."""
    im = Image.open(os.path.join(SRC, name)).convert('RGB')
    buf = io.BytesIO()
    im.save(buf, 'JPEG', quality=84, optimize=True)
    cls = 'ph-' + name.split('.')[0]
    IMGCSS.append(f'.{cls}{{background:url(data:image/jpeg;base64,{base64.b64encode(buf.getvalue()).decode()}) 0 0/100% 100% no-repeat;aspect-ratio:{im.width}/{im.height}}}')
    return f'<div class="ph {cls}" role="img" aria-label="Product page"></div>'


GIFT = ('<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" '
        'stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/>'
        '<path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>')
EN = '<b>Free gifts with every order.</b> <span class="kfg-l">Limited time offer!</span>'
AR = '<b>هدايا مجانية مع كل طلب.</b> <span class="kfg-l">عرض لفترة محدودة!</span>'

# (letter, name, note)
OPTS = [
    ('A', 'Rose shimmer', 'Soft blush strip in the shop\'s pink; a band of light sweeps across it every few seconds.'),
    ('B', 'Berry shine', 'The Add-to-cart pink, white text; a bright shine glides across and the gift gently bobs.'),
    ('C', 'Black & gold', 'Luxe dark strip; the words are gold with a slow shine moving through them.'),
    ('D', 'Running ticker', 'The line scrolls continuously, like a news ticker, with ✦ sparkles between repeats.'),
    ('E', 'Glowing border', 'White strip whose pink-to-gold border light runs round and round the edge.'),
    ('F', 'Candy stripes', 'Pink candy stripes drift slowly behind a white pill holding the words.'),
    ('G', 'Aurora', 'Pink, peach and lavender colours flow gently behind the words, always moving.'),
    ('H', 'Twinkle', 'Blush strip with little ✦ sparkles twinkling around the words; the gift shines.'),
]


def strip(L, lang):
    txt = AR if lang == 'ar' else EN
    d = ' dir="rtl" lang="ar"' if lang == 'ar' else ''
    if L == 'D':
        one = f'<span class="kfg-i">{GIFT}</span><span class="kfg-t">{txt}</span><span class="kfg-sep">✦</span>'
        return f'<div class="kfg kfg-{L}"{d}><div class="kfg-run">{one * 4}</div></div>'
    extra = '<i class="tw t1">✦</i><i class="tw t2">✦</i><i class="tw t3">✦</i><i class="tw t4">✦</i>' if L == 'H' else ''
    inner = f'<span class="kfg-i">{GIFT}</span><span class="kfg-t">{txt}</span>'
    if L == 'F':
        inner = f'<span class="kfg-pill">{inner}</span>'
    return f'<div class="kfg kfg-{L}"{d}>{extra}{inner}</div>'


CSS = '''
.kfg{--p:#C8365E;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:10px 14px;border-radius:12px;font:600 14.5px/1.3 Outfit,system-ui,sans-serif;text-align:center;isolation:isolate}
.kfg[dir=rtl]{font-family:Cairo,Outfit,sans-serif}
.kfg b{font-weight:700;white-space:nowrap}.kfg-l{white-space:nowrap}.kfg-i{display:inline-grid;place-items:center;flex:none}.kfg-t{min-width:0}
/* A rose shimmer */
.kfg-A{background:linear-gradient(90deg,#FBE3EA,#F7CFDA 50%,#FBE3EA);color:#9E2347;box-shadow:inset 0 0 0 1px #F2B8C8}
.kfg-A::after,.kfg-B::after{content:"";position:absolute;inset:-20% auto -20% -40%;width:35%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.75),transparent);transform:translateX(-120%) skewX(-18deg);animation:kfgSweep 3.2s ease-in-out infinite;z-index:-1}
@keyframes kfgSweep{0%{transform:translateX(-120%) skewX(-18deg)}60%,100%{transform:translateX(520%) skewX(-18deg)}}
/* B berry shine */
.kfg-B{background:linear-gradient(135deg,#B42A52,var(--p) 45%,#E0607E);color:#fff;box-shadow:0 6px 16px rgba(200,54,94,.28)}
.kfg-B::after{background:linear-gradient(100deg,transparent,rgba(255,255,255,.45),transparent);z-index:0}.kfg-B>*{position:relative;z-index:1}
.kfg-B .kfg-i,.kfg-H .kfg-i{animation:kfgBob 1.8s ease-in-out infinite}
@keyframes kfgBob{0%,100%{transform:translateY(0) rotate(0)}50%{transform:translateY(-2px) rotate(-8deg)}}
/* C black & gold */
.kfg-C{background:linear-gradient(135deg,#1F1B1E,#2E2629);color:#E9C46A;box-shadow:inset 0 0 0 1px rgba(233,196,106,.35)}
.kfg-C .kfg-t,.kfg-C .kfg-i{background:linear-gradient(100deg,#C99A3B 0%,#F6E3A1 22%,#C99A3B 44%,#C99A3B 100%) 0 0/250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:kfgGold 3.4s linear infinite}
.kfg-C .kfg-i{color:#E9C46A;background:none;animation:none}
@keyframes kfgGold{from{background-position:120% 0}to{background-position:-130% 0}}
/* D ticker */
.kfg-D{background:linear-gradient(90deg,#F9D6E0,#FBE7ED);color:#9E2347;justify-content:flex-start;padding:10px 0;box-shadow:inset 0 0 0 1px #F2B8C8}
.kfg-run{display:flex;align-items:center;gap:8px;white-space:nowrap;width:max-content;animation:kfgRun 16s linear infinite}
.kfg-run>*{flex:none}.kfg-sep{color:var(--p);margin:0 10px}
@keyframes kfgRun{to{transform:translateX(-50%)}}
.kfg-D[dir=rtl] .kfg-run{animation-name:kfgRunR}@keyframes kfgRunR{to{transform:translateX(50%)}}
/* E glowing border */
.kfg-E{background:#fff;color:var(--p);padding:11px 15px}
.kfg-E::before{content:"";position:absolute;inset:-150%;background:conic-gradient(from 0deg,#F7CFDA,var(--p),#E9B44C,#F7CFDA 40%,#F7CFDA);animation:kfgSpin 3s linear infinite;z-index:-2}
.kfg-E::after{content:"";position:absolute;inset:2px;border-radius:10px;background:#fff;z-index:-1}
@keyframes kfgSpin{to{transform:rotate(1turn)}}
/* F candy stripes */
.kfg-F{background:repeating-linear-gradient(-45deg,#F4B7C8 0 12px,#FBE3EA 12px 24px);background-size:34px 34px;animation:kfgStripe 1.6s linear infinite;padding:8px 10px}
@keyframes kfgStripe{to{background-position:34px 0}}
.kfg-F .kfg-pill{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#9E2347;border-radius:99px;padding:6px 14px;box-shadow:0 2px 8px rgba(158,35,71,.15)}
/* G aurora */
.kfg-G{background:linear-gradient(110deg,#F9C5D5,#FDE1C8,#E5D4F7,#F9C5D5,#FDE1C8) 0 0/300% 100%;color:#5A1F35;animation:kfgAur 7s ease-in-out infinite alternate}
@keyframes kfgAur{to{background-position:100% 0}}
/* H twinkle */
.kfg-H{background:linear-gradient(90deg,#FFF1F5,#FBE3EA);color:#9E2347;box-shadow:inset 0 0 0 1px #F5C6D3}
.kfg-H .kfg-i{color:var(--p)}
.kfg-H .tw{position:absolute;font-style:normal;color:#E9B44C;font-size:11px;animation:kfgTw 2.4s ease-in-out infinite;opacity:0}
.kfg-H .t1{left:5%;top:18%}.kfg-H .t2{left:14%;bottom:12%;animation-delay:.6s;color:var(--p)}.kfg-H .t3{right:6%;top:16%;animation-delay:1.2s}.kfg-H .t4{right:15%;bottom:14%;animation-delay:1.8s;color:var(--p)}
@keyframes kfgTw{0%,100%{opacity:0;transform:scale(.4) rotate(0)}50%{opacity:1;transform:scale(1.15) rotate(45deg)}}
@media (prefers-reduced-motion:reduce){.kfg,.kfg *,.kfg::before,.kfg::after{animation:none!important}}
'''


def page():
    ma, mb = img('fg2-m-above.png', 390), img('fg2-m-below.png', 390)
    da, db = img('fg2-d-above.png', 630), img('fg2-d-below.png', 630)
    cards = ''
    for L, name, note in OPTS:
        cards += f'''<section class="opt"><h2><b>{L}</b> {name}</h2><p>{note}</p>
<div class="phone"><div class="ctx">{ma}</div><div class="slot m"><div class="l-en">{strip(L, 'en')}</div><div class="l-ar">{strip(L, 'ar')}</div></div><div class="ctx">{mb}</div></div>
<details><summary>On a computer</summary><div class="desk"><div class="ctx">{da}</div><div class="slot d"><div class="l-en">{strip(L, 'en')}</div><div class="l-ar">{strip(L, 'ar')}</div></div><div class="ctx">{db}</div></div></details></section>'''
    return f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Free Gifts Strip</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Cairo:wght@600;700&display=swap">
<style>
:root{{--bg:#F1EEF0;--fg:#1F1B1E;--muted:#6B6268;--line:#DCD6DA;--panel:#fff;--on:#1F1B1E;--on-fg:#fff}}
@media (prefers-color-scheme:dark){{:root:not([data-theme="light"]){{--bg:#151315;--fg:#F3EFF2;--muted:#A79DA4;--line:#2E2A2D;--panel:#1F1C1F;--on:#F3EFF2;--on-fg:#151315}}}}
:root[data-theme="dark"]{{--bg:#151315;--fg:#F3EFF2;--muted:#A79DA4;--line:#2E2A2D;--panel:#1F1C1F;--on:#F3EFF2;--on-fg:#151315}}
*{{box-sizing:border-box}}body{{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 Outfit,system-ui,sans-serif}}
.wrap{{max-width:1320px;margin:0 auto;padding:22px 16px 48px}}h1{{font-size:24px;margin:0 0 6px}}.lead{{color:var(--muted);margin:0 0 14px;max-width:780px}}
.bar{{display:flex;gap:8px;align-items:center;margin:0 0 18px;flex-wrap:wrap}}.bar span{{font-size:12px;font-weight:700;color:var(--muted);letter-spacing:.06em;text-transform:uppercase}}
.chip{{font:600 14px Outfit,sans-serif;padding:8px 14px;border-radius:99px;border:1px solid var(--line);background:var(--panel);color:var(--fg);cursor:pointer}}
.chip[aria-pressed="true"]{{background:var(--on);color:var(--on-fg);border-color:var(--on)}}
.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,400px),1fr));gap:18px}}
.opt{{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:12px}}
.opt h2{{font-size:16px;margin:0}}.opt h2 b{{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;background:var(--on);color:var(--on-fg);font-size:13px;margin-inline-end:6px}}
.opt p{{color:var(--muted);font-size:13px;margin:4px 0 10px}}
.phone{{width:100%;max-width:390px;margin:0 auto;border-radius:18px;overflow:hidden;background:#FDEFF3;box-shadow:0 0 0 1px var(--line)}}
.ph{{display:block;width:100%}}
.slot{{background:#FDEFF3;padding:18px 22px}}.slot.d{{padding:18px 24px}}
.l-ar{{display:none}}body.is-ar .l-en{{display:none}}body.is-ar .l-ar{{display:block}}
details{{margin-top:10px}}summary{{cursor:pointer;font-size:13px;font-weight:600;color:var(--muted)}}
.desk{{margin-top:8px;border-radius:12px;overflow:hidden;box-shadow:0 0 0 1px var(--line);background:#FDEFF3}}
{CSS}
{"".join(IMGCSS)}
</style></head><body><div class="wrap">
<h1>Free gifts strip: product page</h1>
<p class="lead">Eight live options for "Free gifts with every order. Limited time offer!", each shown in its place: above the tabby/tamara cards, with more space above and below. The page around each strip is a photo of the real product page; the strip itself is live, so the movement is exactly what shoppers would see. Pick a letter.</p>
<div class="bar" role="group" aria-label="Language"><span>Language</span><button class="chip" type="button" data-l="en" aria-pressed="true">English</button><button class="chip" type="button" data-l="ar" aria-pressed="false">العربية (/ar pages)</button></div>
<div class="grid">{cards}</div></div>
<script>
document.querySelectorAll('[data-l]').forEach(function(b){{ b.onclick = function(){{
  document.querySelectorAll('[data-l]').forEach(function(x){{ x.setAttribute('aria-pressed', String(x === b)); }});
  document.body.classList.toggle('is-ar', b.dataset.l === 'ar'); }}; }});
</script></body></html>'''


open(os.path.join(D, 'preview.html'), 'w').write(page())
print('ok', os.path.getsize(os.path.join(D, 'preview.html')) // 1024, 'KB')
