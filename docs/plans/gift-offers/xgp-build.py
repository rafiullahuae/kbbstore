import urllib.parse
s=open('xg-pending.html').read().replace('http://localhost','https://extrabeauty.ae')
i=s.find('COSRX Advanced'); j=s.rfind('<tr><td class="px"',0,i)
F="font-family:'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;"
img="data:image/svg+xml;utf8,"+urllib.parse.quote('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFF1C9"/><stop offset="1" stop-color="#F5D98B"/></linearGradient></defs><rect width="120" height="120" rx="18" fill="#FFF4EC"/><rect x="47" y="14" width="26" height="16" rx="4" fill="#B88A2E"/><rect x="36" y="28" width="48" height="78" rx="12" fill="url(#g)"/><rect x="44" y="52" width="32" height="30" rx="5" fill="#fff" opacity=".85"/></svg>')
chip=lambda t,bg,fg:f'<span style="display:inline-block;padding:3px 9px;margin:0 4px 4px 0;border-radius:99px;background:{bg};color:{fg};font-size:11px;font-weight:700;letter-spacing:.04em;white-space:nowrap;">{t}</span>'
chips=chip('FREE','#FBE3EA','#C13E63')+chip('FULL SIZE','#F6EBD7','#8A6420')+chip('×1','#EFEAE6','#5E545A')
sec=f'''<tr><td class="px" style="padding:22px 32px 4px;{F}"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF7EC;border:1.5px dashed #E2BE7E;border-radius:16px;"><tr><td style="padding:20px 20px 22px;{F}" align="center">
<div style="font-size:11px;font-weight:700;letter-spacing:.14em;color:#B08433;">✦ A GIFT FOR YOU ✦</div>
<div class="h1" style="margin-top:6px;font-size:26px;line-height:1.2;font-weight:800;color:#2A2228;">Wait… this is for you</div>
<div style="margin-top:6px;font-size:14px;line-height:1.55;color:#6A5E64;">Complete your order and we'll add a full-size gift to it. Free, just for you.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;background:#FFFFFF;border-radius:14px;border:1px solid #F1E2C8;"><tr>
<td width="96" style="width:96px;padding:12px;"><img src="{img}" width="84" height="84" alt="" style="display:block;border-radius:12px;"></td>
<td valign="middle" style="padding:12px 12px 12px 0;text-align:left;{F}"><div style="font-size:11px;font-weight:700;letter-spacing:.08em;color:#8C828A;">BEAUTY OF JOSEON</div><div style="margin:3px 0 7px;font-size:14.5px;line-height:1.35;font-weight:600;color:#2A2228;">Beauty of Joseon Relief Sun : Rice + Probiotics SPF50+</div>{chips}</td></tr></table>
<div style="margin-top:14px;font-size:12.5px;color:#8C828A;">Complete your order in</div><div style="margin-top:2px;"><span data-clk style="font-size:34px;font-weight:800;letter-spacing:.04em;color:#C13E63;">07:59:59</span></div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:16px auto 0;"><tr><td align="center" bgcolor="#C13E63" style="background:#C13E63;border-radius:99px;"><a href="#" style="display:inline-block;padding:14px 30px;{F}font-size:15px;font-weight:700;color:#FFFFFF;text-decoration:none;">Pay now &amp; claim my gift &rarr;</a></td></tr></table>
<div style="margin-top:10px;font-size:12px;color:#A0959B;">Worth AED 65 · 50 ml · added to order #57240 when it is paid</div></td></tr></table></td></tr>'''
out=s[:j]+sec+s[j:]
out=out.replace('</body>','<script>var E=Date.now()+8*3600e3;setInterval(function(){var l=Math.max(0,Math.round((E-Date.now())/1000));document.querySelectorAll("[data-clk]").forEach(function(e){e.textContent=[Math.floor(l/3600),Math.floor(l%3600/60),l%60].map(function(n){return String(n).padStart(2,"0")}).join(":")})},1000)</script></body>')
open('pending-order-gift-preview.html','w').write(out)
