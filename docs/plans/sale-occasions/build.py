import json, re, sys, os

D = os.path.dirname(os.path.abspath(__file__))
css = open(os.path.join(D, 'ssb.css')).read()
html = open(os.path.join(D, 'ssb-1280.html')).read()
html = html.replace('src="/img-cache', 'src="https://kbeautybliss.com/img-cache').replace(', /img-cache', ', https://kbeautybliss.com/img-cache').replace('srcset="/img-cache', 'srcset="https://kbeautybliss.com/img-cache')

THEMES = {}

# ------------------------------------------------------------------ 11.11
THEMES['m1'] = dict(occ='1111', name='11.11 · A  Midnight Neon', tag='ONE DAY ONLY · 11 NOVEMBER', title='MEGA SALE', num='11.11', css='''
.kbb-ssb.occ-m1 .ssb-in{background:radial-gradient(60% 90% at 86% 20%,rgba(255,61,139,.55),transparent 60%),radial-gradient(50% 80% at 8% 110%,rgba(124,58,237,.55),transparent 60%),linear-gradient(120deg,#1a0a2e 0%,#2d0b45 55%,#4a0d4f 100%);
 --ssb-tag-cm:#F8D7E6;--ssb-tag-cd:#F8D7E6;--ssb-pay-cm:#fff;--ssb-pay-cd:#fff;--ssb-title-cm:#FFD36E;--ssb-title-cd:#FFD36E;--ssb-clabel-cm:#fff;--ssb-clabel-cd:#fff;--ssb-cvalue-cm:#D61F69;--ssb-cvalue-cd:#D61F69}
.kbb-ssb.occ-m1 .ssb-hero{position:relative}
.kbb-ssb.occ-m1 .ssb-hero::before{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle,#FFD36E 0 1.5px,transparent 2.5px) 0 0/46px 46px,radial-gradient(circle,#ff6fae 0 1.5px,transparent 2.5px) 23px 23px/46px 46px;opacity:.45;-webkit-mask:linear-gradient(90deg,transparent 0,#000 40%,#000 100%);mask:linear-gradient(90deg,transparent 0,#000 40%,#000 100%)}
.kbb-ssb.occ-m1 .ssb-copy{position:relative}
.kbb-ssb.occ-m1 .ssb-super{text-shadow:0 0 calc(var(--u)*1.6) rgba(255,211,110,.55)}
.kbb-ssb.occ-m1 .occ-num{display:block;font-weight:900;line-height:.9;letter-spacing:-.02em;color:#ff4f9a;text-shadow:0 0 calc(var(--u)*.6) #ff4f9a,0 0 calc(var(--u)*2.4) rgba(255,79,154,.8),0 0 calc(var(--u)*5) rgba(255,79,154,.5)}
.kbb-ssb.occ-m1 .ssb-model::before{content:"";position:absolute;left:8%;right:8%;bottom:0;height:78%;border-radius:50% 50% 0 0;background:radial-gradient(60% 60% at 50% 45%,rgba(255,79,154,.6),rgba(124,58,237,.25) 60%,transparent 72%)}
.kbb-ssb.occ-m1 .ssb-model picture{position:relative}
.kbb-ssb.occ-m1 .ssb-coupon b{border-color:#ff4f9a}
.kbb-ssb.occ-m1 .ssb-gifts{background:#fff}
''')
THEMES['m2'] = dict(occ='1111', name='11.11 · B  Pink Burst', tag='BIGGEST SALE OF THE YEAR', title='MEGA SALE', num='11.11', css='''
.kbb-ssb.occ-m2 .ssb-in{background:repeating-conic-gradient(from 0deg at 82% 60%,rgba(255,255,255,.13) 0 6deg,transparent 6deg 18deg),radial-gradient(70% 90% at 82% 55%,#ffb38a,transparent 60%),linear-gradient(115deg,#e8306f 0%,#ff4f93 45%,#ff8a5c 100%);
 --ssb-tag-cm:#fff;--ssb-tag-cd:#fff;--ssb-pay-cm:#fff;--ssb-pay-cd:#fff;--ssb-title-cm:#fff;--ssb-title-cd:#fff;--ssb-clabel-cm:#fff;--ssb-clabel-cd:#fff;--ssb-cvalue-cm:#D61F69;--ssb-cvalue-cd:#D61F69}
.kbb-ssb.occ-m2 .ssb-super{text-shadow:0 calc(var(--u)*.45) 0 #b3124f,0 calc(var(--u)*1.2) calc(var(--u)*2) rgba(120,0,40,.35)}
.kbb-ssb.occ-m2 .occ-num{display:block;font-weight:900;line-height:.9;letter-spacing:-.02em;color:#fff;-webkit-text-stroke:0;text-shadow:calc(var(--u)*.5) calc(var(--u)*.5) 0 #b3124f,calc(var(--u)*1) calc(var(--u)*1) 0 rgba(120,0,40,.25)}
.kbb-ssb.occ-m2 .ssb-coupon b{border-color:#fff;box-shadow:0 0 0 calc(var(--u)*.3) rgba(255,255,255,.35)}
.kbb-ssb.occ-m2 .ssb-gifts{background:rgba(255,255,255,.94)}
''')
THEMES['m3'] = dict(occ='1111', name='11.11 · C  Blush & Gold Ticket', tag='DOUBLE ELEVEN · 11 NOV', title='MEGA SALE', num='11.11', css='''
.kbb-ssb.occ-m3 .ssb-in{background:radial-gradient(90% 120% at 85% 10%,#fff,transparent 55%),linear-gradient(118deg,#fff1e6 0%,#fff9f3 50%,#fde9ef 100%);
 --ssb-tag-cm:#8A6420;--ssb-tag-cd:#8A6420;--ssb-title-cm:#E2336F;--ssb-title-cd:#E2336F;--ssb-cvalue-cm:#8A6420;--ssb-cvalue-cd:#8A6420}
.kbb-ssb.occ-m3 .ssb-hero{position:relative}
@media (max-width:900px){.kbb-ssb.occ-m3 .occ-num{position:relative}}
.kbb-ssb.occ-m3 .occ-num::after{content:"\\2726";position:absolute;margin-inline-start:.12em;top:-.05em;font-size:.38em;color:#d4a24c;text-shadow:none}
.kbb-ssb.occ-m3 .ssb-hero::after{content:"\\2727  \\2726";position:absolute;right:36%;top:16%;color:#d4a24c;letter-spacing:.6em;font-size:calc(var(--u)*2.4);opacity:.8}
.kbb-ssb.occ-m3 .occ-num{display:block;font-weight:900;line-height:.9;letter-spacing:-.02em;color:#C9952F;text-shadow:0 calc(var(--u)*.35) 0 #8A6420,0 calc(var(--u)*1) calc(var(--u)*1.6) rgba(138,100,32,.3)}
.kbb-ssb.occ-m3 .ssb-coupon b{border-color:#C9952F;background:#fffaf0}
.kbb-ssb.occ-m3 .ssb-model::before{content:"";position:absolute;left:4%;right:4%;bottom:0;height:86%;border-radius:50% 50% 0 0;border:calc(var(--u)*.35) dashed #d4a24c;border-bottom:0;background:radial-gradient(60% 60% at 50% 40%,#fff4dc,transparent 75%)}
.kbb-ssb.occ-m3 .ssb-model picture{position:relative}
''')

# ------------------------------------------------------------- UAE 55
FLAG = 'linear-gradient(180deg,#00732F 0 33.4%,#fff 33.4% 66.7%,#000 66.7% 100%)'
THEMES['u1'] = dict(occ='uae', name='National Day · A  Flag Ribbon', tag='EID AL ETIHAD · 2 DECEMBER', title='NATIONAL DAY SALE', num='', badge='55', css='''
.kbb-ssb.occ-u1 .ssb-in{background:radial-gradient(80% 100% at 85% 10%,#fff,transparent 60%),linear-gradient(118deg,#eef8f1 0%,#ffffff 55%,#f3f7f4 100%);
 --ssb-tag-cm:#1f2a22;--ssb-tag-cd:#1f2a22;--ssb-title-cm:#00732F;--ssb-title-cd:#00732F;--ssb-cvalue-cm:#C8102E;--ssb-cvalue-cd:#C8102E}
.kbb-ssb.occ-u1 .ssb-hero{position:relative;padding-inline-start:calc(var(--u)*7.5)}
.kbb-ssb.occ-u1 .ssb-hero::before{content:"";position:absolute;inset-inline-start:0;top:0;bottom:0;width:calc(var(--u)*2.2);background:#C8102E}
.kbb-ssb.occ-u1 .ssb-hero::after{content:"";position:absolute;inset-inline-start:calc(var(--u)*2.2);inset-inline-end:0;bottom:0;height:calc(var(--u)*1.8);background:''' + FLAG + '''}
.kbb-ssb.occ-u1 .ssb-super{text-shadow:none}
.kbb-ssb.occ-u1 .ssb-coupon b{border-color:#00732F}
.kbb-ssb.occ-u1 .occ-badge{position:absolute;z-index:2;inset-inline-end:calc(var(--u)*30);top:calc(var(--u)*5);width:calc(var(--u)*9);height:calc(var(--u)*9);border-radius:50%;display:grid;place-items:center;background:#00732F;color:#fff;font-weight:900;font-size:calc(var(--u)*3.6);box-shadow:0 0 0 calc(var(--u)*.5) #fff,0 0 0 calc(var(--u)*.9) #C8102E}
.kbb-ssb.occ-u1 .ssb-model::before{content:"";position:absolute;left:6%;right:6%;bottom:0;height:80%;border-radius:50% 50% 0 0;background:radial-gradient(60% 60% at 50% 40%,rgba(0,115,47,.18),transparent 75%)}
.kbb-ssb.occ-u1 .ssb-model picture{position:relative}

''')
SKY = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 600 120' preserveAspectRatio='none'%3E%3Cpath fill='%23d9b77c' fill-opacity='.55' d='M0 120V96h30V84h18v12h22V70h12v26h26V60h8V42h6v18h8v36h24V78l14-10 14 10v18h30V88h20V54h4V20l4-14 4 14v34h4v42h22V74h26v22h18V64h14v32h26V84a22 22 0 0 1 44 0v12h20V72h16v24h28V58h10v38h24V82h16v14h30V76h14v20h20v24z'/%3E%3C/svg%3E\")"
THEMES['u2'] = dict(occ='uae', name='National Day · B  Desert Gold', tag='CELEBRATING 55 YEARS · EID AL ETIHAD', title='NATIONAL DAY SALE', num='', badge='', css='''
.kbb-ssb.occ-u2 .ssb-in{background:linear-gradient(118deg,#fbf2e2 0%,#f6e6c8 55%,#efd7a8 100%);
 --ssb-tag-cm:#6b4a14;--ssb-tag-cd:#6b4a14;--ssb-title-cm:#00732F;--ssb-title-cd:#00732F;--ssb-cvalue-cm:#00732F;--ssb-cvalue-cd:#00732F}
.kbb-ssb.occ-u2 .ssb-hero{position:relative}
.kbb-ssb.occ-u2 .ssb-hero::before{content:"";position:absolute;left:0;right:0;bottom:0;height:42%;background:''' + SKY + ''' bottom/100% 100% no-repeat;pointer-events:none}
.kbb-ssb.occ-u2 .ssb-hero::after{content:"";position:absolute;right:30%;top:12%;width:calc(var(--u)*9);height:calc(var(--u)*9);border-radius:50%;background:radial-gradient(circle,#fff6dd,#f1c46a 60%,transparent 62%);opacity:.9}
.kbb-ssb.occ-u2 .ssb-copy,.kbb-ssb.occ-u2 .ssb-model{position:relative;z-index:1}
.kbb-ssb.occ-u2 .ssb-super{text-shadow:0 calc(var(--u)*.3) 0 #fff}
.kbb-ssb.occ-u2 .ssb-coupon b{border-color:#C8102E;background:#fffaf0}
.kbb-ssb.occ-u2 .ssb-gifts{box-shadow:inset 0 calc(var(--u)*-.6) 0 #000,inset 0 calc(var(--u)*-1.2) 0 #fff,inset 0 calc(var(--u)*-1.8) 0 #00732F}
''')
THEMES['u3'] = dict(occ='uae', name='National Day · C  Green & Gold Night', tag='EID AL ETIHAD · 55 YEARS', title='NATIONAL DAY SALE', num='', badge='', css='''
.kbb-ssb.occ-u3 .ssb-in{background:radial-gradient(60% 90% at 84% 25%,rgba(241,196,106,.35),transparent 60%),linear-gradient(118deg,#062e1b 0%,#00532a 55%,#00732F 100%);
 --ssb-tag-cm:#F1D79A;--ssb-tag-cd:#F1D79A;--ssb-pay-cm:#fff;--ssb-pay-cd:#fff;--ssb-title-cm:#fff;--ssb-title-cd:#fff;--ssb-clabel-cm:#fff;--ssb-clabel-cd:#fff;--ssb-cvalue-cm:#00732F;--ssb-cvalue-cd:#00732F}
.kbb-ssb.occ-u3 .ssb-hero{position:relative}
.kbb-ssb.occ-u3 .ssb-hero::before{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle,#F1C46A 0 1.4px,transparent 2.4px) 0 0/52px 52px,radial-gradient(circle,#fff 0 1px,transparent 2px) 26px 26px/52px 52px;opacity:.55;-webkit-mask:linear-gradient(90deg,transparent 0,#000 45%);mask:linear-gradient(90deg,transparent 0,#000 45%)}
.kbb-ssb.occ-u3 .ssb-hero::after{content:"";position:absolute;inset-inline-end:0;top:0;width:calc(var(--u)*2.4);height:100%;background:#C8102E}
.kbb-ssb.occ-u3 .ssb-copy{position:relative}
.kbb-ssb.occ-u3 .ssb-super{text-shadow:0 calc(var(--u)*.4) 0 rgba(0,0,0,.35),0 0 calc(var(--u)*2) rgba(241,196,106,.45)}
.kbb-ssb.occ-u3 .ssb-coupon b{border-color:#F1C46A}
.kbb-ssb.occ-u3 .ssb-model::before{content:"";position:absolute;left:6%;right:6%;bottom:0;height:80%;border-radius:50% 50% 0 0;background:radial-gradient(60% 60% at 50% 42%,rgba(241,196,106,.55),transparent 72%)}
.kbb-ssb.occ-u3 .ssb-model picture{position:relative}
.kbb-ssb.occ-u3 .ssb-gifts{background:#fff;box-shadow:inset 0 calc(var(--u)*.6) 0 #00732F,inset 0 calc(var(--u)*1.2) 0 #fff,inset 0 calc(var(--u)*1.8) 0 #000}
''')
THEMES['s0'] = dict(occ='super', name='Super Sale  (current, kept as it is)', css='')

COMMON = '''
.kbb-ssb .occ-num{font-size:calc(var(--u)*11)}
.kbb-ssb[class*=" occ-"] .ssb-copy{gap:calc(var(--u)*.9)}
.kbb-ssb[class*=" occ-m"] .ssb-super{--ssb-title-sm:6.4}
.kbb-ssb[class*=" occ-u"] .ssb-super{--ssb-title-sm:6.6}
@media (max-width:900px){.kbb-ssb.occ-u1 .occ-badge{inset-inline-end:calc(var(--u)*27);top:calc(var(--u)*3)}.kbb-ssb.occ-u1 .ssb-super,.kbb-ssb.occ-u2 .ssb-super,.kbb-ssb.occ-u3 .ssb-super{white-space:normal;max-width:12ch}.kbb-ssb[class*=" occ-"] .ssb-tag{--ssb-tag-sm:2.7}}
@media (min-width:901px){.kbb-ssb[class*=" occ-m"] .ssb-copy{position:relative}.kbb-ssb .occ-num{position:absolute;inset-inline-start:52%;top:50%;transform:translate(-50%,-50%);font-size:calc(var(--u)*13);z-index:1}.kbb-ssb[class*=" occ-"] .ssb-copy{gap:calc(var(--u)*.55)}.kbb-ssb[class*=" occ-m"] .ssb-super{font-size:calc(var(--u)*5)}.kbb-ssb[class*=" occ-u"] .ssb-super{font-size:calc(var(--u)*4.2)}}
'''


def variant(key):
    t = THEMES[key]
    h = html
    if t['occ'] != 'super':
        h = h.replace('<div class="kbb-ssb" ', '<div class="kbb-ssb occ-' + key + '" ', 1)
        h = h.replace('<span class="ssb-tag">PERFECT SKINCARE TO GLOW</span>', '<span class="ssb-tag">' + t['tag'] + '</span>')
        title = '<div class="ssb-super">' + t['title'] + '</div>'
        if t.get('num'):
            title = '<span class="occ-num" aria-hidden="true">' + t['num'] + '</span>' + title
        h = h.replace('<div class="ssb-super">SUPER SALE</div>', title)
        if t.get('badge'):
            h = h.replace('<div class="ssb-hero">', '<div class="ssb-hero"><span class="occ-badge" aria-hidden="true">' + t['badge'] + '</span>', 1)
    style = css + COMMON + t['css']
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"><style>html,body{margin:0;background:#fff;font-family:"Plus Jakarta Sans",system-ui,sans-serif}body{padding:0}' + style + '</style></head><body>' + h + '</body></html>'


order = ['m1', 'm2', 'm3', 'u1', 'u2', 'u3', 's0']
docs = {k: variant(k) for k in order}
names = {k: THEMES[k]['name'] for k in order}
if len(sys.argv) > 1 and sys.argv[1] == 'shots':
    for k in order:
        open(os.path.join(D, 'v-' + k + '.html'), 'w').write(docs[k])
    sys.exit()

page = '''<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sale header occasions</title>
<style>
:root{--bg:#f4f1f3;--ink:#2a2228;--soft:#6b5e66;--card:#fff;--line:#e6dde2;--acc:#e4457c}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#17131a;--ink:#f2ecef;--soft:#b3a7ad;--card:#221c25;--line:#3a3040}}
:root[data-theme="dark"]{--bg:#17131a;--ink:#f2ecef;--soft:#b3a7ad;--card:#221c25;--line:#3a3040}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 system-ui,-apple-system,sans-serif}
.wrap{max-width:1320px;margin:0 auto;padding:20px 16px 60px}
h1{font-size:20px;margin:0 0 4px}.sub{color:var(--soft);margin:0 0 16px}
.bar{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px;position:sticky;top:0;background:var(--bg);padding:10px 0;z-index:3}
.bar button{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:99px;padding:7px 14px;font:inherit;font-weight:600;cursor:pointer}
.bar button[aria-pressed="true"]{background:var(--acc);border-color:var(--acc);color:#fff}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin:0 0 22px}
.card h2{font-size:16px;margin:0 0 10px}
.lab{font-size:12px;font-weight:700;letter-spacing:.06em;color:var(--soft);text-transform:uppercase;margin:6px 0}
.dt{width:100%;overflow:hidden;border-radius:10px;border:1px solid var(--line);background:#fff}
.dt iframe{width:1240px;height:310px;border:0;display:block;transform-origin:0 0}
.ph{width:390px;max-width:100%;border-radius:22px;border:8px solid #1d1820;overflow:hidden;background:#fff}
.ph iframe{width:390px;height:250px;border:0;display:block;transform-origin:0 0}
.row{display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start}
.note{color:var(--soft);font-size:13px}
</style></head><body><div class="wrap">
<h1>Sale page header · occasions</h1>
<p class="sub">Your live /super-sale/ banner with each occasion's design, live (same model photo, free gifts, Tabby/Tamara and coupon GLOW). Pick one letter for 11.11 and one for National Day; Super Sale stays as it is.</p>
<div class="bar" id="bar"><button data-f="all" aria-pressed="true">All</button><button data-f="1111">11.11 Mega Sale</button><button data-f="uae">UAE National Day</button><button data-f="super">Super Sale</button></div>
<div id="list"></div>
<p class="note">Desktop is drawn at 1240px wide (scaled to fit this window); the phone at 390px. Arabic pages get the same design right-to-left.</p>
</div>
<script>
var DOCS=__DOCS__, NAMES=__NAMES__, OCC=__OCC__, ORDER=__ORDER__;
var list=document.getElementById('list');
ORDER.forEach(function(k){
  var c=document.createElement('section');c.className='card';c.dataset.occ=OCC[k];
  c.innerHTML='<h2></h2><div class="lab">Desktop</div><div class="dt"><iframe loading="lazy" title="desktop"></iframe></div><div class="lab" style="margin-top:12px">Phone</div><div class="row"><div class="ph"><iframe loading="lazy" title="phone"></iframe></div></div>';
  c.querySelector('h2').textContent=NAMES[k];
  var f=c.querySelectorAll('iframe');f[0].srcdoc=DOCS[k];f[1].srcdoc=DOCS[k];
  list.appendChild(c);
});
function fit(){document.querySelectorAll('.dt').forEach(function(d){var w=d.clientWidth,s=Math.min(1,w/1240),f=d.querySelector('iframe');f.style.transform='scale('+s+')';d.style.height=(310*s)+'px';});}
window.addEventListener('resize',fit);fit();
document.getElementById('bar').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;document.querySelectorAll('#bar button').forEach(function(x){x.setAttribute('aria-pressed',x===b)});var f=b.dataset.f;document.querySelectorAll('#list .card').forEach(function(c){c.style.display=(f==='all'||c.dataset.occ===f)?'':'none'});});
</script></body></html>'''
esc = lambda o: json.dumps(o).replace('</', '<\\/').replace('<!--', '<\\!--')
page = page.replace('__DOCS__', esc(docs)).replace('__NAMES__', esc(names)).replace('__OCC__', esc({k: THEMES[k]['occ'] for k in order})).replace('__ORDER__', esc(order))
open(os.path.join(D, 'sale-occasions-preview.html'), 'w').write(page)
print('ok', len(page))
