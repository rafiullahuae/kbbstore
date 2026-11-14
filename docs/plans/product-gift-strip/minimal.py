"""Minimal versions of the product-page free-gifts strip (owner, 14 Nov 2026: "it's very prominent
and colored, look like a temu fake / scam website. can u make it super real and minimals.").

Built to sit with the shop's own quiet parts -- the white Tabby & Tamara cards right under it, the
plum text, the one brand pink -- no gradient, no confetti, no exclamation marks. Shown in place on a
photo of the real product page (phone + laptop), EN and AR. Nothing ships until he picks.

  python3 minimal.py <dir with fg2-*.png>   -> minimal.html
"""
import base64, io, os, sys
from PIL import Image

D = os.path.dirname(os.path.abspath(__file__))
SRC = sys.argv[1]
IMGCSS = []


def img(name):
    im = Image.open(os.path.join(SRC, name)).convert('RGB')
    buf = io.BytesIO()
    im.save(buf, 'JPEG', quality=84, optimize=True)
    cls = 'ph-' + name.split('.')[0]
    IMGCSS.append(f'.{cls}{{background:url(data:image/jpeg;base64,{base64.b64encode(buf.getvalue()).decode()}) 0 0/100% 100% no-repeat;aspect-ratio:{im.width}/{im.height}}}')
    return f'<div class="ph {cls}" role="img" aria-label="Product page"></div>'


GIFT = ('<svg viewBox="0 0 24 24" width="{s}" height="{s}" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" '
        'stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/>'
        '<path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>')
T = {'en': ('Free gift with every order', 'For a limited time'), 'ar': ('هدية مجانية مع كل طلب', 'لفترة محدودة')}

OPTS = [
    ('1', 'Quiet card', 'A white card exactly like the Tabby & Tamara cards under it: thin border, pink gift outline, plum text. No motion.'),
    ('2', 'Blush tint', 'The faintest blush panel, no border; the gift sits in a small pink circle. No motion.'),
    ('3', 'One line', 'Just a line of text between two hairlines, like a note on the page. The most understated.'),
    ('4', 'Blush + soft glint', 'Option 2 with one very faint glint passing every 10 seconds, the only movement.'),
    ('5', 'Pink outline', 'White card with a thin brand-pink outline and pink words on line one. A touch more noticeable, still calm.'),
]


def strip(o, lang):
    a, b = T[lang]
    d = ' dir="rtl" lang="ar"' if lang == 'ar' else ''
    if o == '3':
        return f'<div class="mg mg-3"{d}><span class="mg-i">{GIFT.format(s=17)}</span><span><b>{a}</b><span class="mg-dot"> · </span>{b}</span></div>'
    icon = GIFT.format(s=20)
    return f'<div class="mg mg-{o}"{d}><span class="mg-i">{icon}</span><span class="mg-t"><b>{a}</b><span>{b}</span></span></div>'


CSS = '''
.mg{--pk:#C8365E;--ink:#2A2228;--mu:#6B6268;position:relative;overflow:hidden;display:flex;align-items:center;gap:12px;font:500 14px/1.35 Outfit,system-ui,sans-serif;color:var(--ink);text-align:start}
.mg[dir=rtl]{font-family:Cairo,Outfit,sans-serif}
.mg b{font-weight:600;font-size:14.5px}.mg-t{display:flex;flex-direction:column;gap:1px}.mg-t>span{color:var(--mu);font-size:13px}
.mg-i{display:inline-grid;place-items:center;flex:none;color:var(--pk)}
.mg-1{background:#fff;border:1px solid #EFE3E7;border-radius:14px;padding:12px 16px}
.mg-2,.mg-4{background:#FFF7F9;border-radius:14px;padding:11px 14px}
.mg-2 .mg-i,.mg-4 .mg-i{width:36px;height:36px;border-radius:50%;background:#FBE6EC}
.mg-3{justify-content:center;gap:8px;padding:11px 4px;border-block:1px solid #EBDCE1;font-size:13.5px}.mg-3 b{font-size:13.5px}.mg-3 .mg-dot{color:var(--mu)}.mg-3>span:last-child{color:var(--mu)}.mg-3 b{color:var(--ink)}
.mg-4::after{content:"";position:absolute;inset-block:0;left:0;width:40%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.7),transparent);transform:translateX(-110%);animation:mgG 10s ease-in-out infinite;pointer-events:none}
@keyframes mgG{0%,70%{transform:translateX(-110%)}100%{transform:translateX(260%)}}
.mg-5{background:#fff;border:1px solid #E7A9BB;border-radius:14px;padding:12px 16px}.mg-5 b{color:var(--pk)}
@media (prefers-reduced-motion:reduce){.mg-4::after{animation:none}}
'''


def page():
    ma, mb, da, db = img('fg2-m-above.png'), img('fg2-m-below.png'), img('fg2-d-above.png'), img('fg2-d-below.png')
    cards = ''
    for o, name, note in OPTS:
        cards += f'''<section class="opt"><h2><b>{o}</b> {name}</h2><p>{note}</p>
<div class="phone">{ma}<div class="slot"><div class="l-en">{strip(o, 'en')}</div><div class="l-ar">{strip(o, 'ar')}</div></div>{mb}</div>
<details><summary>On a computer</summary><div class="desk">{da}<div class="slot d"><div class="l-en">{strip(o, 'en')}</div><div class="l-ar">{strip(o, 'ar')}</div></div>{db}</div></details></section>'''
    return f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Minimal Gift Strip</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600&family=Cairo:wght@500;600&display=swap">
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
.phone{{max-width:390px;margin:0 auto;border-radius:18px;overflow:hidden;background:#FDEFF3;box-shadow:0 0 0 1px var(--line)}}
.ph{{display:block;width:100%}}.slot{{background:#FDEFF3;padding:20px 22px}}.slot.d{{padding:20px 24px}}
.l-ar{{display:none}}body.is-ar .l-en{{display:none}}body.is-ar .l-ar{{display:block}}
details{{margin-top:10px}}summary{{cursor:pointer;font-size:13px;font-weight:600;color:var(--muted)}}
.desk{{margin-top:8px;border-radius:12px;overflow:hidden;box-shadow:0 0 0 1px var(--line);background:#FDEFF3}}
{CSS}
{"".join(IMGCSS)}
</style></head><body><div class="wrap">
<h1>Free gifts strip, minimal</h1>
<p class="lead">Five calm versions that sit with the shop's own Tabby &amp; Tamara cards: white or the faintest blush, the one brand pink, plum text, no confetti, no exclamation marks. Wording: "Free gift with every order / For a limited time". Each is shown in its place on the real product page. Pick a number.</p>
<div class="bar" role="group" aria-label="Language"><span>Language</span><button class="chip" type="button" data-l="en" aria-pressed="true">English</button><button class="chip" type="button" data-l="ar" aria-pressed="false">العربية</button></div>
<div class="grid">{cards}</div></div>
<script>
document.querySelectorAll('[data-l]').forEach(function(b){{ b.onclick = function(){{
  document.querySelectorAll('[data-l]').forEach(function(x){{ x.setAttribute('aria-pressed', String(x === b)); }});
  document.body.classList.toggle('is-ar', b.dataset.l === 'ar'); }}; }});
</script></body></html>'''


open(os.path.join(D, 'minimal.html'), 'w').write(page())
print('ok', os.path.getsize(os.path.join(D, 'minimal.html')) // 1024, 'KB')
