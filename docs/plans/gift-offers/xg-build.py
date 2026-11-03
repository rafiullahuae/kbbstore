import json
base=open('xg-base2.html').read().replace('http://localhost','https://extrabeauty.ae')
i=base.find('class="line"'); p1=base.rfind('<tr><td class="px"',0,i)
g=base.find('1.5px solid #2e8f62'); p3=base.rfind('<tr><td class="px"',0,g)
p2=base.find('<tr><td class="px" style="padding:26px 32px 0;')
for tok,pos in sorted([('@@P1@@',p1),('@@P3@@',p3),('@@P2@@',p2)],key=lambda x:-x[1]):
    base=base[:pos]+tok+base[pos:]
page=r'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Abandoned Email Gift</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#FBF6F3;--ink:#2A2228;--soft:#7A6E76;--pink:#C13E63;--line:#EBDDE3;--card:#fff}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#1d1a1c;--ink:#f3eef1;--soft:#b9adb4;--line:#3a3237;--card:#262125}}
:root[data-theme="dark"]{--bg:#1d1a1c;--ink:#f3eef1;--soft:#b9adb4;--line:#3a3237;--card:#262125}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Outfit,system-ui,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:20px 16px 40px}
h1{font-size:22px;margin:0 0 4px}.lead{color:var(--soft);font-size:14px;margin:0 0 16px;line-height:1.5}
.grid{display:grid;grid-template-columns:330px 1fr;gap:20px;align-items:start}
@media (max-width:860px){.grid{grid-template-columns:1fr}}
.panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px}
.grp{margin-bottom:14px}.grp b{display:block;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--soft);margin-bottom:7px}
.opts{display:flex;flex-wrap:wrap;gap:6px}
.opts button{font:inherit;font-size:13px;border:1px solid var(--line);background:transparent;color:var(--ink);border-radius:99px;padding:7px 12px;cursor:pointer}
.opts button.on{background:var(--pink);border-color:var(--pink);color:#fff}
.opts.col{flex-direction:column}.opts.col button{border-radius:10px;text-align:left}
.opts.col button small{display:block;opacity:.75;font-size:11.5px;margin-top:2px}
.note{font-size:12.5px;color:var(--soft);line-height:1.5;margin:6px 0 0}
.stage{display:flex;justify-content:center;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;overflow:hidden}
iframe{border:0;width:680px;max-width:100%;height:1500px;background:#FFF8F5;border-radius:10px;transition:width .25s}
.stage.ph iframe{width:390px}
table.rng{width:100%;border-collapse:collapse;font-size:12.5px}table.rng td{padding:5px 4px;border-bottom:1px solid var(--line)}
</style></head><body><div class="wrap">
<h1>Abandoned-cart email: everything together</h1>
<p class="lead">This is your real abandoned-cart email, rendered from the shop's code: the bag, the <b>free gifts box</b> she already reached (green), and the <b>new extra gift</b> with its timer. Pick a <b>design</b>, <b>words</b> and <b>where it sits</b>. Change the <b>cart value</b> to see the gift change by range. The gift is held for <b>8 hours</b> from when the email is sent.</p>
<div class="grid"><div class="panel">
<div class="grp"><b>Design</b><div class="opts col" id="oD"></div></div>
<div class="grp"><b>Words</b><div class="opts col" id="oW"></div></div>
<div class="grp"><b>Where it sits</b><div class="opts" id="oP"></div></div>
<div class="grp"><b>Timer in the email</b><div class="opts col" id="oT"></div>
<p class="note">An email cannot run a clock by itself. "Live" is a small moving picture our server draws when the email is opened, so it shows the real time left. It works in Gmail, Apple Mail and Yahoo; Outlook on a computer shows it without moving. "Fixed" prints the end time as text and needs nothing extra.</p></div>
<div class="grp"><b>This bag: AED 404</b><div class="opts" id="oC"></div>
<table class="rng" id="rng"></table><p class="note">The green free-gifts box is your real Free gifts module for this bag (3 masks + 1 mini). The extra gift comes from its own ranges, which you set in the admin; example products shown.</p></div>
<div class="grp"><b>Preview width</b><div class="opts" id="oV"></div></div>
</div>
<div class="stage" id="stage"><iframe id="fr" title="Email preview"></iframe></div></div></div>
<script>
const BASE=__BASE__, P1=__P1__, P2=__P2__, P3=__P3__;
const F="font-family:'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
function bottle(c1,c2,cap){return 'data:image/svg+xml;utf8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'+c1+'"/><stop offset="1" stop-color="'+c2+'"/></linearGradient></defs><rect width="120" height="120" rx="18" fill="#FFF4EC"/><rect x="47" y="14" width="26" height="16" rx="4" fill="'+cap+'"/><rect x="36" y="28" width="48" height="78" rx="12" fill="url(#g)"/><rect x="44" y="52" width="32" height="30" rx="5" fill="#fff" opacity=".85"/><rect x="49" y="60" width="22" height="3" rx="1.5" fill="'+cap+'"/><rect x="49" y="67" width="16" height="3" rx="1.5" fill="'+cap+'" opacity=".6"/></svg>');}
const RANGES=[{min:0,max:149,p:null},{min:150,max:299,p:{name:'Round Lab 1025 Dokdo Toner',brand:'ROUND LAB',size:'200 ml',worth:'AED 89',img:bottle('#CFE6F5','#9CC6E6','#2F6E9C')}},
{min:300,max:499,p:{name:'Beauty of Joseon Relief Sun : Rice + Probiotics SPF50+',brand:'BEAUTY OF JOSEON',size:'50 ml',worth:'AED 65',img:bottle('#FFF1C9','#F5D98B','#B88A2E')}},
{min:500,max:1e9,p:{name:'COSRX Advanced Snail 96 Mucin Power Essence',brand:'COSRX',size:'100 ml',worth:'AED 89',img:bottle('#F6E3EC','#E7BBD0','#A2456E')}}];
const BAGS=[404];
const DESIGNS=[['A','Golden ticket','Cream and gold card, like the cart row you chose (R2)'],['B','Countdown tiles','Big hour, minute and second boxes'],['C','Gift box','Large product picture, pink band, the most visual'],['D','App row','The same thin row as the cart, plus one line'],['E','Coupon ticket','A tear-off ticket: photo stub, dashed cut line, clock'],['F','Midnight luxe','Deep plum card with gold, like a beauty-counter gift']];
const WORDS=[['1','Wait… this is for you',"Your bag is still here, and we've added a full-size gift to go with it. Free, just for you.",'Claim my free gift'],
['2','Your gift is on hold, beautiful ✨','Finish your order and this full-size treat is yours at AED 0. It will not wait forever.','Yes, add my gift'],
['3','Don\'t leave your glow behind','Complete your order in time and we\'ll slip a full-size favourite into your box, on us.','Complete my order + gift']];
const TIMERS=[['live','Live clock','A moving countdown picture'],['fixed','Fixed end time','"Held until 4:15 AM tomorrow"']];
let st={d:'A',w:'1',p:'P1',t:'live',bag:404,v:'ph'};
const end=Date.now()+8*3600e3;
function left(){let s=Math.max(0,Math.round((end-Date.now())/1000));return [Math.floor(s/3600),Math.floor(s%3600/60),s%60].map(n=>String(n).padStart(2,'0'));}
function until(){const d=new Date(end),t=d.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});return t+(d.getDate()!==new Date().getDate()?' tomorrow':' tonight');}
function esc(s){return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
function chip(t,bg,fg){return '<span style="display:inline-block;padding:3px 9px;margin:0 4px 4px 0;border-radius:99px;background:'+bg+';color:'+fg+';font-size:11px;font-weight:700;letter-spacing:.04em;white-space:nowrap;">'+t+'</span>';}
function clock(size,col){const l=left();return st.t==='live'?'<span data-clk style="font-size:'+size+'px;font-weight:800;letter-spacing:.04em;color:'+col+';font-variant-numeric:tabular-nums;">'+l.join(':')+'</span>':'<span style="font-size:'+Math.round(size*.62)+'px;font-weight:700;color:'+col+';">Held until '+until()+'</span>';}
function btn(label,bg){return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:16px auto 0;"><tr><td align="center" bgcolor="'+bg+'" style="background:'+bg+';border-radius:99px;"><a href="#" style="display:inline-block;padding:14px 30px;'+F+'font-size:15px;font-weight:700;color:#FFFFFF;text-decoration:none;">'+esc(label)+' &rarr;</a></td></tr></table>';}
function row(inner){return '<tr><td class="px" style="padding:22px 32px 4px;'+F+'">'+inner+'</td></tr>';}
function section(){
  const r=RANGES.find(r=>st.bag>=r.min&&st.bag<=r.max); if(!r.p) return '';
  const p=r.p,w=WORDS.find(x=>x[0]===st.w);
  const chips=chip('FREE','#FBE3EA','#C13E63')+chip('FULL SIZE','#F6EBD7','#8A6420')+chip('×1','#EFEAE6','#5E545A');
  if(st.d==='A') return row('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF7EC;border:1.5px dashed #E2BE7E;border-radius:16px;"><tr><td style="padding:20px 20px 22px;'+F+'" align="center">'+
   '<div style="font-size:11px;font-weight:700;letter-spacing:.14em;color:#B08433;">✦ A GIFT FOR YOU ✦</div>'+
   '<div class="h1" style="margin-top:6px;font-size:26px;line-height:1.2;font-weight:800;color:#2A2228;">'+esc(w[1])+'</div>'+
   '<div style="margin-top:6px;font-size:14px;line-height:1.55;color:#6A5E64;">'+esc(w[2])+'</div>'+
   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;background:#FFFFFF;border-radius:14px;border:1px solid #F1E2C8;"><tr>'+
   '<td width="96" style="width:96px;padding:12px;"><img src="'+p.img+'" width="84" height="84" alt="" style="display:block;border-radius:12px;"></td>'+
   '<td valign="middle" style="padding:12px 12px 12px 0;text-align:left;'+F+'"><div style="font-size:11px;font-weight:700;letter-spacing:.08em;color:#8C828A;">'+esc(p.brand)+'</div><div style="margin:3px 0 7px;font-size:14.5px;line-height:1.35;font-weight:600;color:#2A2228;">'+esc(p.name)+'</div>'+chips+'</td></tr></table>'+
   '<div style="margin-top:14px;font-size:12.5px;color:#8C828A;">Complete your order in</div><div style="margin-top:2px;">'+clock(34,'#C13E63')+'</div>'+
   btn(w[3],'#C13E63')+'<div style="margin-top:10px;font-size:12px;color:#A0959B;">Worth '+p.worth+' · '+p.size+' · one per order</div></td></tr></table>');
  if(st.d==='B'){const l=left();const tile=(n,lab)=>'<td align="center" style="padding:0 4px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="width:64px;height:64px;background:#C13E63;border-radius:12px;'+F+'font-size:30px;font-weight:800;color:#fff;"><span data-t="'+lab+'">'+n+'</span></td></tr><tr><td align="center" style="padding-top:5px;'+F+'font-size:10.5px;font-weight:700;letter-spacing:.1em;color:#8C828A;">'+lab+'</td></tr></table></td>';
   const timer=st.t==='live'?'<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:12px auto 0;"><tr>'+tile(l[0],'HOURS')+tile(l[1],'MINS')+tile(l[2],'SECS')+'</tr></table>':'<div style="margin-top:10px;">'+clock(34,'#C13E63')+'</div>';
   return row('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFFFFF;border:2px solid #F3C6D4;border-radius:18px;"><tr><td style="padding:22px 18px;'+F+'" align="center">'+
   '<div class="h1" style="font-size:25px;line-height:1.2;font-weight:800;color:#2A2228;">'+esc(w[1])+'</div><div style="margin-top:6px;font-size:13.5px;color:#6A5E64;line-height:1.55;">Your free full-size gift is held for</div>'+timer+
   '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:18px auto 0;"><tr><td style="padding-right:12px;"><img src="'+p.img+'" width="70" height="70" alt="" style="display:block;border-radius:12px;"></td><td style="text-align:left;'+F+'"><div style="font-size:14px;font-weight:600;color:#2A2228;max-width:240px;line-height:1.35;">'+esc(p.name)+'</div><div style="margin-top:5px;">'+chips+'</div></td></tr></table>'+
   '<div style="margin-top:12px;font-size:13.5px;color:#6A5E64;line-height:1.55;">'+esc(w[2])+'</div>'+btn(w[3],'#C13E63')+'</td></tr></table>');}
  if(st.d==='C') return row('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:18px;overflow:hidden;background:#FFFFFF;border:1px solid #F3D3DE;">'+
   '<tr><td align="center" style="background:#C13E63;background-image:linear-gradient(135deg,#D9577C,#B23258);padding:16px 18px;'+F+'"><div style="font-size:12px;font-weight:700;letter-spacing:.16em;color:#FFE3EA;">🎀 FREE FULL-SIZE GIFT 🎀</div><div class="h1" style="margin-top:4px;font-size:25px;line-height:1.2;font-weight:800;color:#FFFFFF;">'+esc(w[1])+'</div></td></tr>'+
   '<tr><td align="center" style="padding:20px 20px 22px;'+F+'"><img src="'+p.img+'" width="150" height="150" alt="" style="display:block;border-radius:16px;"><div style="margin-top:10px;font-size:11px;font-weight:700;letter-spacing:.08em;color:#8C828A;">'+esc(p.brand)+'</div><div style="margin-top:3px;font-size:16px;line-height:1.35;font-weight:700;color:#2A2228;">'+esc(p.name)+'</div><div style="margin-top:8px;">'+chips+'</div>'+
   '<div style="margin-top:8px;font-size:14px;line-height:1.55;color:#6A5E64;">'+esc(w[2])+'</div>'+
   '<div style="display:inline-block;margin-top:12px;padding:8px 16px;border-radius:99px;background:#FFF3F6;">'+clock(22,'#C13E63')+'</div>'+btn(w[3],'#C13E63')+'</td></tr></table>');
  if(st.d==='E') return row('<div style="'+F+'text-align:center;font-size:22px;font-weight:800;color:#2A2228;line-height:1.25;">'+esc(w[1])+'</div><div style="'+F+'text-align:center;margin:6px 0 14px;font-size:13.5px;line-height:1.55;color:#6A5E64;">'+esc(w[2])+'</div>'+
   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:16px;background:#FFFFFF;border:1.5px solid #F0C5D3;"><tr>'+
   '<td width="120" align="center" valign="middle" style="width:120px;background:#FDEBF1;border-radius:14px 0 0 14px;padding:14px 8px;border-right:2px dashed #E7A9BE;"><img src="'+p.img+'" width="92" height="92" alt="" style="display:block;border-radius:12px;"><div style="margin-top:6px;'+F+'font-size:11px;font-weight:800;letter-spacing:.12em;color:#C13E63;">FREE</div></td>'+
   '<td valign="middle" style="padding:14px 14px;'+F+'"><div style="font-size:10.5px;font-weight:700;letter-spacing:.12em;color:#B08433;">FULL-SIZE GIFT · ×1</div><div style="margin-top:4px;font-size:14.5px;line-height:1.35;font-weight:700;color:#2A2228;">'+esc(p.name)+'</div><div style="margin-top:8px;font-size:11.5px;color:#8C828A;">Complete your order in</div>'+clock(24,'#C13E63')+'</td></tr></table>'+btn(w[3],'#C13E63'));
  if(st.d==='F') return row('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#3B1F2E;border-radius:18px;"><tr><td align="center" style="padding:24px 20px 24px;'+F+'">'+
   '<div style="font-size:11px;font-weight:700;letter-spacing:.2em;color:#E4C38A;">— EXCLUSIVELY FOR YOU —</div><div class="h1" style="margin-top:8px;font-size:25px;line-height:1.2;font-weight:800;color:#FFFFFF;">'+esc(w[1])+'</div><div style="margin-top:6px;font-size:13.5px;line-height:1.55;color:#E9D9E1;">'+esc(w[2])+'</div>'+
   '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:16px auto 0;background:#FFF8F1;border-radius:14px;"><tr><td style="padding:12px;"><img src="'+p.img+'" width="78" height="78" alt="" style="display:block;border-radius:10px;"></td><td style="padding:12px 14px 12px 0;text-align:left;'+F+'"><div style="font-size:10.5px;font-weight:700;letter-spacing:.1em;color:#B08433;">'+esc(p.brand)+'</div><div style="margin-top:3px;font-size:14px;line-height:1.35;font-weight:700;color:#2A2228;max-width:220px;">'+esc(p.name)+'</div><div style="margin-top:6px;">'+chip('FREE','#FBE3EA','#C13E63')+chip('FULL SIZE','#F6EBD7','#8A6420')+'</div></td></tr></table>'+
   '<div style="margin-top:16px;font-size:12px;letter-spacing:.06em;color:#CDB9C4;">COMPLETE YOUR ORDER IN</div><div style="margin-top:2px;">'+clock(32,'#E4C38A')+'</div>'+btn(w[3],'#C9A15C')+'</td></tr></table>');
  return row('<div style="'+F+'font-size:15px;font-weight:700;color:#2A2228;">'+esc(w[1])+'</div><div style="margin:4px 0 10px;'+F+'font-size:13.5px;line-height:1.5;color:#6A5E64;">'+esc(w[2])+'</div>'+
   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF7EC;border:1px solid #E7E1DC;border-radius:14px;"><tr>'+
   '<td width="78" style="width:78px;padding:10px;"><img src="'+p.img+'" width="66" height="66" alt="" style="display:block;border-radius:10px;"></td>'+
   '<td valign="middle" style="padding:10px 12px 10px 0;'+F+'"><div style="font-size:13.5px;line-height:1.3;font-weight:600;color:#2A2228;">'+esc(p.name)+'</div><div style="margin-top:6px;white-space:nowrap;">'+chips+'</div></td></tr>'+
   '<tr><td colspan="2" style="border-top:1px solid #EFE3D2;padding:9px 14px;'+F+'"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="font-size:12.5px;color:#8C828A;'+F+'">Complete your order in</td><td align="right">'+clock(22,'#C13E63')+'</td></tr></table></td></tr></table>'+
   '<div style="margin-top:10px;'+F+'"><a href="#" style="font-size:14px;font-weight:700;color:#C13E63;text-decoration:none;">'+esc(w[3])+' &rarr;</a></div>');
}
function render(){
  const sec=section();
  document.getElementById('fr').srcdoc=['P1','P2','P3'].reduce((h,k)=>h.replace('@@'+k+'@@',st.p===k?sec:''),BASE);
  document.getElementById('stage').className='stage'+(st.v==='ph'?' ph':'');
}
function opts(id,list,key,col){const el=document.getElementById(id);el.innerHTML=list.map(o=>'<button data-v="'+o[0]+'"'+(String(st[key])===String(o[0])?' class="on"':'')+'>'+o[1]+(o[2]&&col?'<small>'+o[2]+'</small>':'')+'</button>').join('');
  el.onclick=e=>{const b=e.target.closest('button');if(!b)return;st[key]=key==='bag'?+b.dataset.v:b.dataset.v;draw();};}
function draw(){opts('oD',DESIGNS.map(d=>[d[0],d[0]+' · '+d[1],d[2]]),'d',1);opts('oW',WORDS.map(w=>[w[0],w[0]+' · '+w[1],w[3]]),'w',1);
 opts('oP',[['P1','Top, before the bag'],['P3','Between bag and free gifts'],['P2','Under the free gifts']],'p');opts('oT',TIMERS,'t',1);
 opts('oC',BAGS.map(b=>[b,'AED '+b]),'bag');opts('oV',[['ph','Phone'],['dt','Computer']],'v');
 document.getElementById('rng').innerHTML=RANGES.map(r=>'<tr'+(st.bag>=r.min&&st.bag<=r.max?' style="font-weight:700"':'')+'><td>AED '+r.min+(r.max>1e8?'+':'–'+r.max)+'</td><td>'+(r.p?esc(r.p.name):'No gift')+'</td></tr>').join('');render();}
draw();
try{const fr=document.getElementById('fr');setInterval(()=>{const d=fr.contentDocument;if(!d)return;const l=left();d.querySelectorAll('[data-clk]').forEach(e=>e.textContent=l.join(':'));
 const m={HOURS:0,MINS:1,SECS:2};d.querySelectorAll('[data-t]').forEach(e=>e.textContent=l[m[e.dataset.t]]);},1000);}catch(e){}
</script></body></html>'''
page=page.replace('__BASE__',json.dumps(base).replace('</','<\\/').replace('<!--','<\\u0021--')).replace('__P1__',str(p1)).replace('__P3__',str(p3)).replace('__P2__',str(p2))
open('abandoned-gift-preview.html','w').write(page)
print(len(page))
