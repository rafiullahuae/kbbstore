# Builds ../all.html: designs 1-3 x post (1080x1350) / story (1080x1920) x EN / AR.
# Each frame is a .frame with id <design>-<format>-<lang>; tools/shoot.mjs screenshots them.
import os

D = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

T = {
    'en': dict(dir='ltr', me='Me:', save='Trying to save money', also='Also me:', eye='Eye cream', cream='Cream', serum='Serum',
               mist='Face mist', booster='Booster shot', booster2='Booster<br>shot', deo='Deodorant??', meow='meooow~',
               adds='*Adds to cart*', meow_big='*Meooooow~*', meow_adds='meooow~ (adds to cart)', btn='Add to cart', sale='Super Sale',
               wallet='My wallet 💸', online='online · judging you', today='Today 9:41 PM', read='Read 9:42 PM',
               m1="I'm saving money this month.<br>No more skincare 🙅‍♀️", m2='so proud of you 🥹', m3='the Super Sale is live though…',
               m4='meooow~ 😾', pov='POV: the Super Sale is live 👀', logo='logo-en.png', me_line='Me: <span class="lt">trying to save money</span>'),
    'ar': dict(dir='rtl', me='أنا:', save='أحاول توفير المال', also='وأنا أيضاً:', eye='كريم العين', cream='كريم', serum='سيروم',
               mist='رذاذ الوجه', booster='بوستر شوت', booster2='بوستر<br>شوت', deo='مزيل عرق؟؟', meow='مياااو~',
               adds='*أضيف إلى السلة*', meow_big='*مياااااو~*', meow_adds='مياااو~ (أضيف إلى السلة)', btn='أضف إلى السلة', sale='السوبر سيل',
               wallet='محفظتي 💸', online='متصلة الآن · تراقبك', today='اليوم 9:41 م', read='تمت القراءة 9:42 م',
               m1='سأوفّر المال هذا الشهر.<br>لا مزيد من العناية بالبشرة 🙅‍♀️', m2='فخورة بك 🥹', m3='لكن السوبر سيل بدأ…',
               m4='مياااو~ 😾', pov='عندما يبدأ السوبر سيل 👀', logo='logo-ar.png', me_line='أنا: <span class="lt">أحاول توفير المال</span>'),
}


def side(ar: bool, x: int, w: int = 0) -> str:
    """A block placed `x` px from the reading edge (left in EN, right in AR)."""
    return f'right:{x}px' if ar else f'left:{x}px'


def d1(t, ar):
    s = lambda x: side(ar, x)
    ta = 'text-align:right;' if ar else ''
    return f'''
 <svg class="grain" width="1080" height="1350"><rect width="1080" height="1350" filter="url(#paper)"/></svg>
 <p class="a fr" style="{s(86)};top:92px;font-size:66px">{t["me"]}</p>
 <p class="a" style="{s(86)};top:174px;font-size:50px">{t["save"]}</p>
 <p class="a fr" style="{s(86)};top:292px;font-size:66px">{t["also"]}</p>
 <img class="p" src="cut-eyecream.png" style="left:71px;top:815px;width:230px;transform:rotate(-5deg)">
 <img class="p" src="cut-cream.png"    style="left:329px;top:600px;height:400px;transform:rotate(-6deg)">
 <img class="p" src="cut-serum.png"    style="left:467px;top:584px;height:420px;transform:rotate(3deg)">
 <img class="p" src="cut-mist.png"     style="left:625px;top:592px;height:410px;transform:rotate(-3deg)">
 <img class="p" src="cut-booster.png"  style="left:760px;top:562px;height:440px;transform:rotate(7deg)">
 <img class="p" src="cut-deo.png"      style="left:897px;top:684px;height:320px;transform:rotate(-5deg)">
 <p class="a lb" style="left:92px;top:1046px;transform:rotate(3deg)">{t["eye"]}</p>
 <p class="a lb" style="left:250px;top:500px;transform:rotate(-6deg)">{t["cream"]}</p>
 <svg class="ar" style="left:300px;top:540px" width="80" height="70"><path d="M8 8 C 30 22, 46 40, 56 62 M42 56 L 57 64 L 62 48"/></svg>
 <p class="a lb" style="left:476px;top:1036px;transform:rotate(-2deg)">{t["serum"]}</p>
 <p class="a lb" style="left:560px;top:500px;transform:rotate(-3deg)">{t["mist"]}</p>
 <svg class="ar" style="left:640px;top:546px" width="40" height="50"><path d="M12 6 C 18 18, 22 30, 22 44 M12 34 L 22 45 L 31 34"/></svg>
 <p class="a lb" style="left:770px;top:470px;transform:rotate(4deg)">{t["booster"]}</p>
 <p class="a lb" style="left:830px;top:1036px;transform:rotate(-3deg);font-size:40px">{t["deo"]}</p>
 <img class="catph" src="cat.jpg" style="{s(86)};top:1112px;width:180px;height:180px;border-radius:50%;border:6px solid #fff;box-shadow:0 10px 24px rgba(42,34,40,.18)">
 <p class="a fr" style="{s(286)};top:1178px;font-size:{50 if ar else 56}px;color:#C13E63">{t["meow_big"]}</p>
 <svg class="ar" style="{'left:420px;transform:scaleX(-1)' if ar else 'left:640px'};top:1196px" width="64" height="40"><path d="M6 22 C 22 14, 38 14, 54 20 M44 10 L 56 20 L 44 30"/></svg>
 <span class="btn b1" style="{'left:40px;font-size:36px' if ar else 'left:712px'};top:1182px"><svg><use href="#cartic"/></svg>{t["btn"]}</span>
 <p class="a sale" style="{side(not ar, 70)};top:110px;width:200px;{'text-align:left' if ar else 'text-align:right'}">{t["sale"]}</p>'''


def d2(t, ar):
    s = lambda x: side(ar, x)
    flip = 'transform:scaleX(-1);transform-origin:540px 0' if ar else ''
    return f'''
 <div class="dots"></div>
 <p class="a fr" style="left:0;right:0;top:86px;text-align:center;font-size:62px">{t["me_line"]}</p>
 <p class="a fr" style="left:0;right:0;top:176px;text-align:center;font-size:62px">{t["also"]}</p>
 <img class="p" src="cut-booster.png"  style="left:252px;top:468px;height:470px;transform:rotate(-9deg)">
 <img class="p" src="cut-cream.png"    style="left:372px;top:500px;height:440px;transform:rotate(-3deg)">
 <img class="p" src="cut-serum.png"    style="left:492px;top:480px;height:460px;transform:rotate(2deg)">
 <img class="p" src="cut-mist.png"     style="left:640px;top:496px;height:450px;transform:rotate(6deg)">
 <img class="p" src="cut-deo.png"      style="left:760px;top:590px;height:350px;transform:rotate(10deg)">
 <svg class="a" style="left:0;top:0;{flip}" width="1080" height="1350">
  <path d="M206 760 L 874 760 L 830 1010 L 262 1010 Z" fill="rgba(255,255,255,.18)" stroke="#C13E63" stroke-width="10" stroke-linejoin="round"/>
  <path d="M 362 760 L 380 1010 M 500 760 L 506 1010 M 638 760 L 632 1010 M 760 760 L 744 1010" stroke="#E7A3B8" stroke-width="5" fill="none"/>
  <path d="M206 760 L 182 700 L 96 700" stroke="#C13E63" stroke-width="10" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M262 1010 L 238 1072 L 860 1072" stroke="#C13E63" stroke-width="10" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <circle cx="320" cy="1110" r="30" fill="#fff" stroke="#C13E63" stroke-width="10"/><circle cx="790" cy="1110" r="30" fill="#fff" stroke="#C13E63" stroke-width="10"/>
 </svg>
 <img class="p" src="cut-eyecream.png" style="left:410px;top:800px;width:270px;transform:rotate(-3deg)">
 <p class="a lb" style="left:690px;top:950px;transform:rotate(-4deg);font-size:40px">{t["eye"]}</p>
 <p class="a lb" style="left:150px;top:470px;transform:rotate(-8deg)">{t["booster2"]}</p>
 <p class="a lb" style="left:470px;top:404px;transform:rotate(-2deg)">{t["serum"]}</p>
 <p class="a lb" style="left:846px;top:520px;transform:rotate(6deg)">{t["deo"]}</p>
 <svg class="ar" style="left:830px;top:560px" width="60" height="60"><path d="M40 8 C 30 24, 24 36, 20 52 M10 42 L 20 53 L 30 42"/></svg>
 <img class="catph" src="cat.jpg" style="{s(60)};top:1160px;width:200px;height:170px;border-radius:24px;border:7px solid #fff;transform:rotate({5 if ar else -5}deg);box-shadow:0 12px 26px rgba(42,34,40,.18)">
 <p class="a lb" style="{s(286)};top:1170px;font-size:48px;color:#C13E63;transform:rotate({4 if ar else -4}deg)">{t["meow_adds"]}</p>
 <span class="btn b2" style="{s(560 if not ar else 520)};top:1244px"><svg><use href="#cartic"/></svg>{t["btn"]}</span>'''


def d3(t, ar):
    me, them = ('left', 'right') if ar else ('right', 'left')
    s = lambda x: side(ar, x)
    return f'''
 <div class="bar"></div>
 <svg class="a" style="{s(46)};top:58px;{'transform:scaleX(-1)' if ar else ''}" width="34" height="34" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7" fill="none" stroke="#E0567B" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
 <img class="catph" src="cat.jpg" style="{s(104)};top:36px;width:80px;height:80px;border-radius:50%">
 <p class="a" style="{s(204)};top:44px;font-size:34px;font-weight:700">{t["wallet"]}</p>
 <p class="a" style="{s(204)};top:88px;font-size:22px;color:#9A8F94">{t["online"]}</p>
 <img class="a" src="{t["logo"]}" style="{side(not ar, 46)};top:{56 if ar else 52}px;height:{40 if ar else 34}px">
 <p class="tm" style="left:0;right:0;top:186px;text-align:center">{t["today"]}</p>
 <p class="msg me a" style="{me}:46px;top:232px">{t["m1"]}</p>
 <p class="msg them a" style="{them}:46px;top:380px">{t["m2"]}</p>
 <p class="msg me a" style="{me}:46px;top:478px">{t["m3"]}</p>
 <div class="a pbox" style="{me}:46px;top:590px">
  <img class="p" src="cut-eyecream.png" style="left:24px;top:214px;width:170px;transform:rotate(-4deg)">
  <img class="p" src="cut-cream.png"    style="left:200px;top:40px;height:330px;transform:rotate(-6deg)">
  <img class="p" src="cut-serum.png"    style="left:300px;top:28px;height:350px;transform:rotate(-2deg)">
  <img class="p" src="cut-mist.png"     style="left:420px;top:36px;height:340px;transform:rotate(3deg)">
  <img class="p" src="cut-booster.png"  style="left:525px;top:22px;height:360px;transform:rotate(7deg)">
  <img class="p" src="cut-deo.png"      style="left:640px;top:120px;height:250px;transform:rotate(-4deg)">
 </div>
 <p class="tm" style="{me}:56px;top:1010px">{t["read"]}</p>
 <img class="catph" src="cat.jpg" style="{them}:46px;top:1046px;width:240px;height:200px;border-radius:30px;border-bottom-{them}-radius:10px">
 <p class="msg them a" style="{them}:302px;top:1110px">{t["m4"]}</p>
 <span class="btn b3" style="{me}:{120 if ar else 122}px;top:1218px"><svg><use href="#cartic"/></svg>{t["btn"]}</span>'''


BG = {'d1': '#FFFDFB', 'd2': '#FCEFF2', 'd3': '#F7F1EE'}
frames = []
for key, fn in (('d1', d1), ('d2', d2), ('d3', d3)):
    for lang in ('en', 'ar'):
        t = T[lang]
        ar = lang == 'ar'
        inner = fn(t, ar)
        body = f'<div class="post {key}" dir="{t["dir"]}" lang="{lang}">{inner}</div>'
        frames.append(f'<div class="frame" id="{key}-post-{lang}" style="width:1080px;height:1350px">{body}</div>')
        # Story: the same artwork, centred in the 1920 frame (IG's top and bottom bars stay clear).
        if key == 'd3':
            story = (f'<div class="frame story" id="{key}-story-{lang}" style="width:1080px;height:1920px;background:#F3DFE6" dir="{t["dir"]}">'
                     f'<p class="a fr" style="left:0;right:0;top:226px;text-align:center;font-size:58px">{t["pov"]}</p>'
                     f'<div style="position:absolute;left:43px;top:380px;width:1080px;height:1350px;transform:scale(.92);transform-origin:0 0;border-radius:44px;overflow:hidden;box-shadow:0 30px 60px rgba(120,50,80,.18)">{body}</div></div>')
        else:
            story = (f'<div class="frame story" id="{key}-story-{lang}" style="width:1080px;height:1920px;background:{BG[key]}">'
                     + (f'<svg class="grain" width="1080" height="1920" style="position:absolute;inset:0;opacity:.35;mix-blend-mode:multiply"><rect width="1080" height="1920" filter="url(#paper)"/></svg>' if key == 'd1' else '')
                     + ('<div class="dots"></div>' if key == 'd2' else '')
                     + f'<div style="position:absolute;left:0;top:285px;width:1080px;height:1350px">{body.replace(chr(60) + "svg class=" + chr(34) + "grain", chr(60) + "svg style=" + chr(34) + "display:none" + chr(34) + " class=" + chr(34) + "grain").replace(chr(60) + "div class=" + chr(34) + "dots" + chr(34) + "></div>", "")}</div></div>')
        frames.append(story)

css = open(os.path.join(D, 'tools', 'style.css')).read()
open(os.path.join(D, 'all.html'), 'w').write(f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Adds To Cart</title><style>{css}</style></head><body>
<svg width="0" height="0" style="position:absolute">
 <filter id="paper"><feTurbulence type="fractalNoise" baseFrequency=".85" numOctaves="2" seed="7"/><feColorMatrix values="0 0 0 0 .55  0 0 0 0 .45  0 0 0 0 .5  0 0 0 .18 0"/></filter>
 <symbol id="cartic" viewBox="0 0 24 24"><path d="M3 4h2.2l2.3 10.4a1.6 1.6 0 0 0 1.6 1.2h7.7a1.6 1.6 0 0 0 1.5-1.1L20.5 8H6.4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="19.5" r="1.6" fill="currentColor"/><circle cx="17" cy="19.5" r="1.6" fill="currentColor"/></symbol>
</svg>
{"".join(frames)}
</body></html>''')
print(len(frames), 'frames')
