"""One-line versions, with a little colour and a gentle movement, of the product-page free-gifts strip (owner, 14 Nov 2026: "it's very prominent
and colored, look like a temu fake / scam website. can u make it super real and minimals.").

Built to sit with the shop's own quiet parts -- the white Tabby & Tamara cards right under it, the
plum text, the one brand pink -- no gradient, no confetti, no exclamation marks. Shown in place on a
photo of the real product page (phone + laptop), EN and AR. Nothing ships until he picks.

  python3 soft.py <dir with fg2-*.png>   -> soft.html  (owner, 14 Nov: "no, i mean little colors should be there and minimal animated effect also" ... "one line is fine, give versions further")
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
    ('1', 'Pink hairlines + gift sway', 'The one-line note between two hairlines that fade pink at the centre; the gift gives a small sway every 6 s.'),
    ('2', 'Blush pill + slow glint', 'The line on a slim blush-to-peach pill; one soft glint crosses it every 9 s.'),
    ('3', 'Flowing underline', 'No box: the line with a thin pink-peach-lilac underline that slowly flows.'),
    ('4', 'Rose shimmer words', '"Free gift with every order" in a soft rose tone with a slow shimmer through the words; the rest stays plain.'),
    ('5', 'Hairlines + twinkles', 'Option 1\'s hairlines with two tiny sparkles beside the gift, twinkling slowly in turn.'),
    ('6', 'Soft band + breathing gift', 'A slim soft-pink band across the column; the gift icon breathes gently (grows a touch and back).'),
]


def strip(o, lang):
    a, b = T[lang]
    d = ' dir="rtl" lang="ar"' if lang == 'ar' else ''
    icon = GIFT.format(s=17)
    tw = '<i class="tw tw1" aria-hidden="true">✦</i><i class="tw tw2" aria-hidden="true">✦</i>' if o == '5' else ''
    words = f'<b class="w">{a}</b><span class="dot"> · </span><span class="m">{b}</span>'
    return f'<div class="sl sl-{o}"{d}><span class="ic">{tw}{icon}</span><span class="tx">{words}</span></div>'


CSS = """
.sl{--pk:#C8365E;--ink:#2A2228;--mu:#6B6268;position:relative;display:flex;align-items:center;justify-content:center;gap:8px;padding:11px 6px;font:500 13.5px/1.35 Outfit,system-ui,sans-serif;color:var(--ink);text-align:center}
.sl[dir=rtl]{font-family:Cairo,Outfit,sans-serif}
.sl b{font-weight:600}.sl .m,.sl .dot{color:var(--mu)}
.sl .ic{position:relative;display:inline-grid;place-items:center;flex:none;color:var(--pk)}
/* 1 pink hairlines + sway */
.sl-1,.sl-5{border:0;background:linear-gradient(90deg,transparent,#E9A3B8 50%,transparent) top/100% 1px no-repeat,linear-gradient(90deg,transparent,#E9A3B8 50%,transparent) bottom/100% 1px no-repeat}
.sl-1 .ic svg{transform-origin:50% 90%;animation:slSway 6s ease-in-out infinite}
@keyframes slSway{0%,82%,100%{transform:rotate(0)}86%{transform:rotate(-12deg)}90%{transform:rotate(10deg)}94%{transform:rotate(-5deg)}}
/* 2 blush pill + glint */
.sl-2{overflow:hidden;border-radius:99px;background:linear-gradient(90deg,#FDE7EE,#FFEFE4);padding:10px 16px}
.sl-2::after{content:"";position:absolute;inset-block:0;left:0;width:30%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.8),transparent);transform:translateX(-120%);animation:slGlint 9s ease-in-out infinite;pointer-events:none}
@keyframes slGlint{0%,72%{transform:translateX(-120%)}100%{transform:translateX(380%)}}
/* 3 flowing underline */
.sl-3{padding-bottom:13px}
.sl-3::after{content:"";position:absolute;left:18%;right:18%;bottom:4px;height:2px;border-radius:2px;background:linear-gradient(90deg,#F2A7BC,#FFC9A8,#D8C4F2,#F2A7BC,#FFC9A8) 0 0/300% 100%;animation:slFlow 8s linear infinite}
@keyframes slFlow{to{background-position:-300% 0}}
/* 4 rose shimmer words */
.sl-4 .w{background:linear-gradient(100deg,#B4304F 0%,#B4304F 40%,#F08FAB 50%,#B4304F 60%,#B4304F 100%) 0 0/250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:slShim 7s linear infinite}
@keyframes slShim{from{background-position:120% 0}to{background-position:-130% 0}}
/* 5 twinkles */
.sl-5 .tw{position:absolute;font-style:normal;font-size:8px;color:#E3A93B;opacity:0;animation:slTw 4s ease-in-out infinite}
.sl-5 .tw1{top:-7px;inset-inline-start:-7px}.sl-5 .tw2{bottom:-6px;inset-inline-end:-7px;color:var(--pk);animation-delay:2s}
@keyframes slTw{0%,100%{opacity:0;transform:scale(.5)}50%{opacity:1;transform:scale(1)}}
/* 6 soft band + breathing gift */
.sl-6{background:#FBE6EC;border-radius:10px;padding:10px 12px}
.sl-6 .ic svg{animation:slBreath 3.2s ease-in-out infinite}
@keyframes slBreath{0%,100%{transform:scale(1)}50%{transform:scale(1.12)}}
@media (prefers-reduced-motion:reduce){.sl *,.sl::after{animation:none!important}}
"""


def page():
    ma, mb, da, db = img('fg2-m-above.png'), img('fg2-m-below.png'), img('fg2-d-above.png'), img('fg2-d-below.png')
    cards = ''
    for o, name, note in OPTS:
        cards += f'''<section class="opt"><h2><b>{o}</b> {name}</h2><p>{note}</p>
<div class="phone">{ma}<div class="slot"><div class="l-en">{strip(o, 'en')}</div><div class="l-ar">{strip(o, 'ar')}</div></div>{mb}</div>
<details><summary>On a computer</summary><div class="desk">{da}<div class="slot d"><div class="l-en">{strip(o, 'en')}</div><div class="l-ar">{strip(o, 'ar')}</div></div>{db}</div></details></section>'''
    return f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>One-Line Gift Strip</title>
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
<h1>Free gifts strip, one line</h1>
<p class="lead">Six versions of the one-line note, each with a little colour and one gentle movement. Wording: "Free gift with every order · For a limited time". Each is shown in its place on the real product page. Pick a number.</p>
<div class="bar" role="group" aria-label="Language"><span>Language</span><button class="chip" type="button" data-l="en" aria-pressed="true">English</button><button class="chip" type="button" data-l="ar" aria-pressed="false">العربية</button></div>
<div class="grid">{cards}</div></div>
<script>
document.querySelectorAll('[data-l]').forEach(function(b){{ b.onclick = function(){{
  document.querySelectorAll('[data-l]').forEach(function(x){{ x.setAttribute('aria-pressed', String(x === b)); }});
  document.body.classList.toggle('is-ar', b.dataset.l === 'ar'); }}; }});
</script></body></html>'''


open(os.path.join(D, 'soft.html'), 'w').write(page())
print('ok', os.path.getsize(os.path.join(D, 'soft.html')) // 1024, 'KB')
