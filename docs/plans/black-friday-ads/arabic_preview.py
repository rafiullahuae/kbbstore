"""Previews of an Arabic line under the free-gifts lines (owner, 12 Nov: "add one line below gifts
line, with some style in arabic 'Black Friday Mega Offers are live' ... first show me previews
seperately in the first version only"). R01 post, one card per style; nothing ships until he picks.

  python3 build.py && python3 arabic_preview.py   -> arabic-line-preview.html
"""
import os, re
D = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(D, 'out', 'v4-01-white-post-f0.html')).read()
style = re.search(r'<style>(.*?)</style>', src, re.S).group(1)
style = style.replace('html,body{width:1080px;height:1350px;overflow:hidden}', '')
ad = re.search(r'<body>(.*)</body>', src, re.S).group(1)
U = 9.330 / 58                      # one design unit of this ad, in cqw (the gift icon is 58 units)
cq = lambda v: f'{v * U:.3f}cqw'
ACC, DARK = '#E11D2E', '#1F1B1E'
# owner, 12 Nov: "make it short, Black Friday Deals are live"
# and "or Exclusive Deals are live"
W = {'a': 'عروض بلاك فرايدي بدأت الآن', 'b': 'عروض الجمعة البيضاء بدأت الآن', 'c': 'عروض حصرية بدأت الآن'}
# (letter, name, note, extra css for .arl, inner html with {t})
S = [
 ('A', 'Clean bold', 'Cairo Bold in the dark text colour. Quiet, reads instantly.',
  f'font:700 {cq(19)} Cairo,sans-serif;color:{DARK}', '{t}'),
 ('B', 'Soft red pill', 'Cairo Bold in red, on a pale red pill with a thin red outline.',
  f'font:700 {cq(17.5)} Cairo,sans-serif;color:{ACC}', f'<span class="pill" style="padding:{cq(4)} {cq(14)};border-radius:99px;background:{ACC}14;box-shadow:inset 0 0 0 {cq(1.4)} {ACC}66">{{t}}</span>'),
 ('C', 'Red tag', 'White Cairo Black on a solid red tag with sparkles, like a sale sticker.',
  f'font:800 {cq(17)} Cairo,sans-serif;color:#fff', f'<span class="pill" style="padding:{cq(4)} {cq(14)};border-radius:{cq(9)};background:linear-gradient(180deg,#F04A57,{ACC} 60%,#B5121F);box-shadow:0 {cq(6)} {cq(14)} {ACC}55">✦ {{t}} ✦</span>'),
 ('D', 'Live dot', 'A pulsing red dot says "live"; Cairo Bold beside it. The dot pulses in the video.',
  f'font:700 {cq(18)} Cairo,sans-serif;color:{DARK}', f'<span class="live" style="width:{cq(10)};height:{cq(10)};margin-inline-start:{cq(8)}"></span>{{t}}'),
 ('E', 'Gold shine', 'Cairo Black with the same red-to-gold shine as the title, gently sweeping.',
  f'font:900 {cq(19.5)} Cairo,sans-serif', '<span class="gold">{t}</span>'),
 ('F', 'Lalezar display', 'Lalezar, a bold Arabic display face, in red. Loud and festive.',
  f'font:400 {cq(22)} Lalezar,sans-serif;color:{ACC}', '{t}'),
 ('G', 'Ruqaa calligraphy', 'Aref Ruqaa calligraphy between two fine lines. Elegant, a little luxury.',
  f'font:700 {cq(17)} "Aref Ruqaa",serif;color:{DARK}', f'<span class="ln" style="width:{cq(16)};margin-inline-end:{cq(6)}"></span>{{t}}<span class="ln" style="width:{cq(16)};margin-inline-start:{cq(6)}"></span>'),
 ('H', 'Reem Kufi underline', 'Reem Kufi (geometric Kufi) with a short red underline. Modern and tidy.',
  f'font:700 {cq(16)} "Reem Kufi",sans-serif;color:{DARK}', f'<span style="display:inline-block;padding-bottom:{cq(9)};background:linear-gradient({ACC},{ACC}) 50% 100%/36% {cq(2.4)} no-repeat">{{t}}</span>'),
]
TOP = 374                           # design units: under "with Every Order" (ends ~358), above the button (434)
cards = ''
for L, name, note, css, inner in S:
    line = f'<div class="bt arl" dir="rtl" lang="ar" style="top:{cq(TOP)};height:{cq(40)};{css}">{inner.format(t="<span class=wt>" + W["a"] + "</span>")}</div>'
    a = ad.replace('<div class="bb" data-k="btn"', line + '<div class="bb" data-k="btn"', 1)
    cards += f'<figure class="opt"><figcaption><b>{L}</b> {name}<span>{note}</span></figcaption>{a}</figure>'
page = f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Arabic Line Previews</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;800;900&family=Cairo:wght@700;800;900&family=Lalezar&family=Aref+Ruqaa:wght@700&family=Reem+Kufi:wght@700&display=swap">
<style>
:root{{--bg:#F1EEF0;--fg:#1F1B1E;--muted:#6B6268;--line:#DCD6DA;--panel:#fff;--on:#1F1B1E;--on-fg:#fff}}
@media (prefers-color-scheme:dark){{:root:not([data-theme="light"]){{--bg:#151315;--fg:#F3EFF2;--muted:#A79DA4;--line:#2E2A2D;--panel:#1F1C1F;--on:#F3EFF2;--on-fg:#151315}}}}
:root[data-theme="dark"]{{--bg:#151315;--fg:#F3EFF2;--muted:#A79DA4;--line:#2E2A2D;--panel:#1F1C1F;--on:#F3EFF2;--on-fg:#151315}}
*{{box-sizing:border-box}}body{{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 Inter,system-ui,sans-serif}}
.wrap{{max-width:1240px;margin:0 auto;padding:24px 16px 48px}}h1{{font-size:24px;margin:0 0 6px}}.lead{{color:var(--muted);margin:0 0 14px;max-width:760px}}
.bar{{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 18px}}.bar .lbl{{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-inline-end:4px}}
.chip{{font:600 14px Inter,sans-serif;padding:8px 14px;border-radius:99px;border:1px solid var(--line);background:var(--panel);color:var(--fg);cursor:pointer}}
.chip[aria-pressed="true"]{{background:var(--on);color:var(--on-fg);border-color:var(--on)}}.chip .ar{{font-family:Cairo,sans-serif;font-weight:700}}
.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr));gap:18px}}
.opt{{margin:0;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:12px}}
figcaption{{font-size:14px;margin:0 0 10px}}figcaption b{{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;background:var(--on);color:var(--on-fg);margin-inline-end:6px;font-size:13px}}
figcaption span{{display:block;color:var(--muted);font-size:13px;margin-top:4px}}
.opt .bf{{border-radius:8px}}
{style}
.bf .arl{{display:flex;align-items:center;justify-content:center;line-height:1.2;white-space:nowrap}}
.bf .arl span{{font-weight:inherit;border-radius:0}}.bf .arl .pill{{display:inline-block;border-radius:99px}}
.bf .arl .live{{display:inline-block;border-radius:50%;background:{ACC};box-shadow:0 0 0 0 {ACC}88;animation:arlive 1.6s ease-out infinite}}
@keyframes arlive{{0%{{box-shadow:0 0 0 0 {ACC}88}}70%{{box-shadow:0 0 0 .9cqw {ACC}00}}100%{{box-shadow:0 0 0 0 {ACC}00}}}}
.bf .arl .gold{{background:linear-gradient(100deg,{ACC} 0%,#C8102E 30%,#E9B44C 50%,#C8102E 70%,{ACC} 100%) 0 0/250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:arshine 3.2s linear infinite}}
@keyframes arshine{{to{{background-position:-250% 0}}}}
.bf .arl .ln{{display:inline-block;height:1px;background:linear-gradient(90deg,transparent,{DARK}99,transparent)}}
@media (prefers-reduced-motion:reduce){{.bf .arl .live,.bf .arl .gold{{animation:none}}}}
</style></head><body><div class="wrap">
<h1>Arabic line under the free-gifts lines</h1>
<p class="lead">"Black Friday Deals are live" (or "Exclusive Deals are live") in Arabic, styled eight ways on the first version (R01, post). Products and everything else are as they are now. Pick a letter, and a wording, and it goes into the editor with controls of its own for every size and colour.</p>
<div class="bar" role="group" aria-label="Wording"><span class="lbl">Wording</span>
<button class="chip" type="button" data-w="a" aria-pressed="true"><span class="ar" lang="ar">{W['a']}</span> · "Black Friday Deals are live"</button>
<button class="chip" type="button" data-w="b" aria-pressed="false"><span class="ar" lang="ar">{W['b']}</span> · "White Friday", as Gulf shops say it</button>
<button class="chip" type="button" data-w="c" aria-pressed="false"><span class="ar" lang="ar">{W['c']}</span> · "Exclusive Deals are live"</button></div>
<div class="grid">{cards}</div></div>
<script>
var W = {{a: {W['a']!r}, b: {W['b']!r}, c: {W['c']!r}}};
document.querySelectorAll('[data-w]').forEach(function(b){{ b.onclick = function(){{
  document.querySelectorAll('[data-w]').forEach(function(x){{ x.setAttribute('aria-pressed', String(x === b)); }});
  document.querySelectorAll('.wt').forEach(function(s){{ s.textContent = W[b.dataset.w]; }}); }}; }});
</script></body></html>'''
open(os.path.join(D, 'arabic-line-preview.html'), 'w').write(page)
print('ok', len(page) // 1024, 'KB')
