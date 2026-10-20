# Builds options.html (EN/AR email wording options for the UAE number request).
import os
D=os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EN=dict(l1="Number on your order",l2="UAE number needed")
AR=dict(l1="الرقم المسجّل في طلبك",l2="الرقم الإماراتي المطلوب")
O=[
('A','A friendly question','📱',
 dict(s="Quick question about your phone number 😊",h="Could you share your UAE number? 😊",
  m="Hi Moza, thank you for your order! We noticed the phone number on it isn't a UAE number. Our couriers can only call UAE local numbers, so could you share your UAE mobile number with us? Then we'll get your order to you without a hitch.",
  b="Share my UAE number",f="Or simply reply to this email with your number. Thank you so much! 💕"),
 dict(s="سؤال سريع عن رقم هاتفك 😊",h="هل يمكنك مشاركتنا رقمك الإماراتي؟ 😊",
  m="مرحباً موزة، شكراً لطلبك! لاحظنا أن رقم الهاتف في طلبك ليس رقماً إماراتياً. مندوبو التوصيل لدينا يتصلون بالأرقام المحلية الإماراتية فقط، فهل يمكنك مشاركتنا رقم جوالك الإماراتي؟ وبعدها يصلك طلبك بكل سهولة.",
  b="مشاركة رقمي الإماراتي",f="أو يمكنك الرد على هذه الرسالة برقمك. شكراً جزيلاً! 💕")),
('B','Just one small detail','🌸',
 dict(s="One small detail for your order 🌸",h="Just one small detail 🌸",
  m="Hi Moza, thank you for shopping with us! The number on your order doesn't look like a UAE local number, and our courier needs one to call you when they arrive. Could you send us your UAE mobile number? It only takes a few seconds.",
  b="Add my UAE number",f="You're also welcome to reply to this email with your number. We're always happy to help!"),
 dict(s="تفصيل صغير لطلبك 🌸",h="تفصيل صغير فقط 🌸",
  m="مرحباً موزة، شكراً لتسوّقك معنا! يبدو أن الرقم المسجّل في طلبك ليس رقماً محلياً إماراتياً، ويحتاج المندوب إلى رقم إماراتي للاتصال بك عند الوصول. هل يمكنك إرسال رقم جوالك الإماراتي؟ يستغرق ذلك ثوانيَ معدودة فقط.",
  b="إضافة رقمي الإماراتي",f="ويمكنك أيضاً الرد على هذه الرسالة برقمك. يسعدنا دائماً مساعدتك!")),
('C','Short and sweet','🙏',
 dict(s="Your UAE mobile number, please 🙏",h="May we have your UAE mobile number?",
  m="Hi Moza, thanks for your order! To deliver it, our courier needs a UAE local mobile number (one that starts with 05). Could you please share yours with us?",
  b="Send my UAE number",f="Or just reply with your number. Thank you! 🌷"),
 dict(s="رقم جوالك الإماراتي من فضلك 🙏",h="هل يمكننا الحصول على رقم جوالك الإماراتي؟",
  m="مرحباً موزة، شكراً لطلبك! لتوصيله يحتاج المندوب إلى رقم جوال إماراتي محلي (يبدأ بـ 05). هل يمكنك مشاركتنا رقمك من فضلك؟",
  b="إرسال رقمي الإماراتي",f="أو يمكنك الرد برقمك فقط. شكراً لك! 🌷")),
('D','So we can reach you','📞',
 dict(s="So our courier can reach you 📞",h="Let's make sure we can reach you 📞",
  m="Hi Moza, thank you for your order! Our delivery partner in the UAE can only call UAE local numbers, and the one on your order is from another country. Please share your UAE mobile number so the courier can call you when they're at your door.",
  b="Share my UAE number",f="Prefer to reply? Just send us your number in a reply to this email. 💕"),
 dict(s="ليتمكن المندوب من التواصل معك 📞",h="لنتأكد من أننا نستطيع التواصل معك 📞",
  m="مرحباً موزة، شكراً لطلبك! شريك التوصيل لدينا في الإمارات يتصل بالأرقام المحلية الإماراتية فقط، والرقم المسجّل في طلبك من دولة أخرى. يرجى مشاركة رقم جوالك الإماراتي ليتصل بك المندوب عند وصوله إلى بابك.",
  b="مشاركة رقمي الإماراتي",f="تفضّل الرد؟ أرسل لنا رقمك في رد على هذه الرسالة. 💕")),
('E','Thank you first','💐',
 dict(s="Thank you for your order, Moza 💐",h="Thank you! One little request 💐",
  m="We're so happy you chose us! Before we send your order, could you share a UAE local mobile number? The number on your order is from outside the UAE, and our courier can only call UAE numbers.",
  b="Share my UAE number",f="A quick reply with your number works too. Thank you! 💕"),
 dict(s="شكراً لطلبك يا موزة 💐",h="شكراً لك! طلب صغير واحد 💐",
  m="سعداء جداً باختيارك لنا! قبل إرسال طلبك، هل يمكنك مشاركتنا رقم جوال إماراتي محلي؟ الرقم المسجّل في طلبك من خارج الإمارات، ومندوبنا يتصل بالأرقام الإماراتية فقط.",
  b="مشاركة رقمي الإماراتي",f="ويكفي أيضاً الرد على هذه الرسالة برقمك. شكراً لك! 💕")),
('F','A tiny mix-up','🙈',
 dict(s="A tiny mix-up with your number 🙈",h="A tiny mix-up with your number 🙈",
  m="Hi Moza, it looks like the phone number on your order isn't a UAE local number. It happens! Our courier needs a UAE mobile number to call you, so could you share yours? We'll update your order right away.",
  b="Fix my number",f="Or simply reply with your UAE number and we'll do the rest. 😊"),
 dict(s="التباس بسيط في رقمك 🙈",h="التباس بسيط في رقم هاتفك 🙈",
  m="مرحباً موزة، يبدو أن رقم الهاتف في طلبك ليس رقماً محلياً إماراتياً، وهذا يحدث أحياناً! يحتاج المندوب إلى رقم جوال إماراتي للاتصال بك، فهل يمكنك مشاركتنا رقمك؟ سنحدّث طلبك فوراً.",
  b="تصحيح رقمي",f="أو يمكنك الرد برقمك الإماراتي وسنتولى الباقي. 😊")),
('G','A note from our team','💌',
 dict(s="A little note from the K-Beauty Bliss team 💌",h="A little note from our team 💌",
  m="Dear Moza, thank you for trusting us with your skincare! To make your delivery smooth, we kindly need a UAE local mobile number for our courier. Would you mind sharing yours with us?",
  b="Share my UAE number",f="With love, the K-Beauty Bliss team 🌸"),
 dict(s="رسالة صغيرة من فريق كي بيوتي بليس 💌",h="رسالة صغيرة من فريقنا 💌",
  m="مرحباً موزة، شكراً لثقتك بنا في العناية ببشرتك! ولتوصيل طلبك بسلاسة، نحتاج بكل لطف إلى رقم جوال إماراتي محلي للمندوب. هل يمكنك مشاركتنا رقمك؟",
  b="مشاركة رقمي الإماراتي",f="مع حبّنا، فريق كي بيوتي بليس 🌸")),
('H','One tap','✨',
 dict(s="Update your number in one tap ✨",h="Update your number in one tap ✨",
  m="Hi Moza, thank you for your order! Our courier calls UAE local numbers only, and the one on your order is from another country. Tap below to add your UAE mobile number. It takes just a moment.",
  b="Update my number",f="Or reply to this email with your number. Thank you! 💕"),
 dict(s="تحديث رقمك بضغطة واحدة ✨",h="تحديث رقمك بضغطة واحدة ✨",
  m="مرحباً موزة، شكراً لطلبك! مندوبنا يتصل بالأرقام المحلية الإماراتية فقط، والرقم المسجّل في طلبك من دولة أخرى. يكفي الضغط على الزر أدناه لإضافة رقم جوالك الإماراتي، فالأمر لا يستغرق سوى لحظة.",
  b="تحديث رقمي",f="أو يمكنك الرد على هذه الرسالة برقمك. شكراً لك! 💕")),
]
def mail(d,ic,ar):
  L=AR if ar else EN
  dr=' dir="rtl" lang="ar"' if ar else ''
  wm='كي بيوتي <span>بليس</span>' if ar else 'K-Beauty<span>Bliss</span>'
  return f'''<div class="sub"{dr}><b>{"الموضوع" if ar else "Subject"}:</b> {d["s"]}</div>
<div class="mail"{dr}><div class="card"><div class="mast"><div class="wm">{wm}</div></div><div class="bar"></div>
<div class="hero"><div class="ic">{ic}</div><div class="h1">{d["h"]}</div><div class="p">{d["m"]}</div></div>
<div class="box"><div class="r"><span>{L["l1"]}</span><span class="num cut">+20 507 272 773</span></div>
<div class="r"><span>{L["l2"]}</span><span class="num good">✓ 05X XXX XXXX</span></div></div>
<div class="cta"><a>{d["b"]}</a></div><div class="or">{d["f"]}</div>
<div class="sum"><span>{"الطلب" if ar else "Order"} <b class="num">#56272</b></span><span><b>{"160 درهم" if ar else "AED 160"}</b></span></div></div></div>'''
css=open(D+'/preview.html').read().split('<style>')[1].split('</style>')[0]
extra=('.sub{font-size:13.5px;color:#5E545A;margin:0 4px 8px}.sub b{color:#2A2228}.opt{margin-bottom:34px}'
 '.mail{padding:12px 10px 18px}.hero{padding:24px 26px 4px}.h1{font-size:24px}.sum{padding-bottom:20px}'
 '.box .num{font-size:15px}.cut{color:#9A9095!important;font-weight:500!important;text-decoration:line-through;text-decoration-color:#9A9095}'
 '.good{color:#2E7D4F!important;font-weight:700!important}')
body=''.join(f'<section class="opt" id="opt-{k}"><h2 class="sec">Option {k} · {t}</h2><div class="row"><div class="col">{mail(en,ic,0)}</div><div class="col">{mail(ar,ic,1)}</div></div></section>' for k,t,ic,en,ar in O)
open(D+'/options.html','w').write(f'<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UAE Number Wording</title><style>{css}{extra}</style></head><body><div class="wrap">{body}</div></body></html>')
