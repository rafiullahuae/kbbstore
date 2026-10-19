'use strict';
/* =========================== ACCOUNT, CONTENT, STATES ==================== */
const accChain = () => ['/account'];
const ST_LABEL = {ship:'On the way', del:'Delivered', proc:'Processing', can:'Cancelled'};

screen('/account', {tab:'account', group:'Account', label:'Account', title:() => t('Account'),
  render(r, ctx){
    const u = S.user;
    const head = u
      ? `<div class="acchead"><span class="av">${initials(ut(u.name))}</span><span class="grow"><b class="nm2">${utH(u.name)}</b><span class="small muted" style="display:block">${X('email', u.email)}</span></span><button class="ib" ${go$('/account/profile')} aria-label="${t('Edit profile')}">${ico('edit')}</button></div>`
      : `<div class="pad"><div class="cardbox signbox"><b>${t('Sign in for faster checkout')}</b><p class="small muted">${t('Track orders, save addresses and keep your wishlist on every device.')}</p><div class="row2"><button class="btn grow" ${go$('/account/signin')}>${t('Sign in')}</button><button class="btn ghost grow" ${go$('/account/register')}>${t('Create account')}</button></div></div></div>`;
    const body = `${head}
      ${u ? `<div class="grp">${t('Shopping')}</div><div class="list mx">${rowLink('/account/orders', 'box', t('Orders'), num(S.orders.length))}${rowLink('/wishlist', 'heart', t('Wishlist'), num(S.wish.size))}${rowLink('/account/addresses', 'pin', t('Addresses'), num(S.co.addresses.length))}</div>` : `<div class="grp">${t('Shopping')}</div><div class="list mx">${rowLink('/wishlist', 'heart', t('Wishlist'), num(S.wish.size))}</div>`}
      <div class="grp">${t('Settings')}</div><div class="list mx">${rowLink('/account/notifications', 'bell', t('Notifications'))}${rowLink('/account/language', 'globe', t('Language & region'), AR ? 'العربية' : 'English')}${u ? toggleRow('faceid', '', S.faceid, isA() ? t('Fingerprint unlock') : X('pay', 'Face ID') + ' · ' + t('unlock'), null, isA() ? 'finger' : 'faceid') : ''}</div>
      <div class="grp">${t('Help')}</div><div class="list mx">${rowLink('/contact', 'chat', t('Contact us'))}${rowLink('/faq', 'help', t('FAQ'))}${rowLink('/policies', 'doc', t('Policies'))}${rowLink('/about', 'info', t('About K-Beauty Bliss'))}${rowLink('/blog', 'note', t('Blog'))}</div>
      ${u ? `<div style="height:16px"></div><div class="list mx"><button class="li" data-act="signout">${ico('logout')}<span class="grow">${t('Sign out')}</span></button><button class="li danger" ${go$('/account/delete')}>${ico('trash')}<span class="grow">${t('Delete account')}</span></button></div>` : ''}
      <p class="tiny muted ver">${t('Version {v}', {v:LTR('1.0.0 (100)')})}</p>`;
    return {title:t('Account'), large:true, body};
  },
  dual:() => S.user ? {detail:true, def:() => '/account/orders', match:/^\/(account\/(orders|profile|addresses|notifications|language)|wishlist|contact|faq|policies|about|blog)/, modes:['dual', 'expanded']} : null});

/* sign in / register / forgot */
FORMS.signin = () => [{k:'email', v:'email'}, {k:'pw', v:'pwIn'}];
FORMS.register = () => [{k:'name', v:'name'}, {k:'email', v:'email'}, {k:'phone', v:'phone'}, {k:'pw', v:'pw'}];
FORMS.forgot = () => [{k:'email', v:'email'}];
const eye = (k) => `<button type="button" class="ib eye" data-act="pw" aria-label="${t(S.pw ? 'Hide password' : 'Show password')}" aria-pressed="${S.pw}">${ico('eye')}</button>`;
screen('/account/signin', {tab:'account', group:'Account', label:'Sign in', chain:accChain, title:() => t('Sign in'),
  render(r){
    if (S.formFor.signin !== r.path) { S.formFor.signin = r.path; S.form.signin = r.query.filled ? {email:SARA.email, pw:'kbeauty2026'} : {}; S.ferr.signin = {}; }
    return {title:t('Sign in'), back:true, noTabs:true, bare:true, body:`<div class="pad narrow"><div class="lgc">${logo()}</div><p class="muted center">${t('Welcome back. Sign in to see your orders and saved addresses.')}</p>
      ${fld({fid:'signin', k:'email', label:t('Email'), type:'email', ac:'email', im:'email', ekh:'next', icon:'mail', ltr:true})}
      ${fld({fid:'signin', k:'pw', label:t('Password'), type:S.pw ? 'text' : 'password', ac:'current-password', ekh:'go', icon:'lock', end:eye()})}
      <div class="rowend"><button class="lnk" ${go$('/account/forgot')}>${t('Forgot password?')}</button></div>
      <button class="btn block" data-act="signin" data-v="${esc(r.query.next || '')}">${t('Sign in')}</button>
      ${cbxRow('faceid', '', S.faceid, isA() ? t('Unlock with fingerprint next time') : t('Use {f} next time', {f:X('pay', 'Face ID')}))}
      <div class="hr"></div><p class="center muted" style="margin:0">${t('New to K-Beauty Bliss?')}</p><button class="btn block ghost" style="margin-top:10px" ${go$('/account/register')}>${t('Create an account')}</button></div>`};
  }});
screen('/account/register', {tab:'account', group:'Account', label:'Create account', chain:accChain, title:() => t('Create account'),
  render(r){
    if (S.formFor.register !== r.path) { S.formFor.register = r.path; S.form.register = r.query.filled ? {name:ut(SARA.name), email:SARA.email, phone:fmtPhone(SARA.phone), pw:'kbeauty2026'} : {}; S.ferr.register = {}; }
    const pw = fval('register', 'pw'), sc = Math.min(4, (pw.length >= 8) + /[A-Z]/.test(pw) + /\d/.test(pw) + (pw.length >= 12 || /[^A-Za-z0-9]/.test(pw)));
    return {title:t('Create account'), back:true, noTabs:true, bare:true, body:`<div class="pad narrow">
      ${fld({fid:'register', k:'name', label:t('Full name'), ac:'name', ekh:'next', icon:'user', cap:'words'})}
      ${fld({fid:'register', k:'email', label:t('Email'), type:'email', ac:'email', im:'email', ekh:'next', icon:'mail', ltr:true})}
      ${fld({fid:'register', k:'phone', label:t('Mobile number'), type:'tel', ac:'tel-national', im:'tel', ekh:'next', icon:'phone', pre:'+971', ltr:true, hint:'50 123 4567', max:12})}
      ${fld({fid:'register', k:'pw', label:t('Password'), type:S.pw ? 'text' : 'password', ac:'new-password', ekh:'done', icon:'lock', end:eye()})}
      <div class="pwm" id="pwm" aria-live="polite">${[0, 1, 2, 3].map(i => `<i class="${i < sc ? 's' + sc : ''}"></i>`).join('')}<span>${pw ? t(['Too short', 'Weak', 'Fair', 'Good', 'Strong'][sc]) : t('At least 8 characters')}</span></div>
      ${cbxRow('news', '', S.co.news, t('Send me offers and new arrivals (optional)'))}
      <div style="height:8px"></div><button class="btn block" data-act="register">${t('Create account')}</button><p class="tiny muted center">${t('By continuing you agree to the Terms and the Privacy policy.')}</p></div>`};
  }});
screen('/account/forgot', {tab:'account', group:'Account', label:'Forgot password', chain:() => ['/account', '/account/signin'], title:() => t('Reset password'),
  render(r){
    if (S.formFor.forgot !== r.path) { S.formFor.forgot = r.path; S.form.forgot = {email:fval('signin', 'email')}; S.ferr.forgot = {}; S.sent.forgot = false; }
    return {title:t('Reset password'), back:true, noTabs:true, bare:true, body:`<div class="pad narrow"><p class="muted" style="margin-top:0">${t('Enter your email. We’ll send a link that opens this app to set a new password.')}</p>
      ${fld({fid:'forgot', k:'email', label:t('Email'), type:'email', ac:'email', im:'email', ekh:'send', icon:'mail', ltr:true})}<button class="btn block" data-act="forgot">${t('Send reset link')}</button>
      ${S.sent.forgot ? `<div class="note ok" role="status" style="margin-top:16px">${ico('check')}<span>${t('Sent. Check {e}. The link works for 60 minutes.', {e:X('email', esc(fval('forgot', 'email')))})}</span></div>` : ''}</div>`};
  }});
screen('/account/biometric', {tab:'account', group:'Account', label:'Face ID / fingerprint unlock', chain:() => [], title:() => t('Unlock'),
  render(){
    return {nobar:true, noTabs:true, bare:true, body:`<div class="state">${logo()}<div style="height:24px"></div><div class="bio">${isA() ? ico('finger', 'fi') : ico('faceid', 'fi')}<h2>${isA() ? t('Touch the fingerprint sensor') : t('Unlock with {f}', {f:X('pay', 'Face ID')})}</h2><p>${t('Your orders, addresses and saved details stay locked until it’s you.')}</p></div><button class="btn" ${go$('/account')} data-tabroot="account">${isA() ? t('Use fingerprint') : t('Use {f}', {f:X('pay', 'Face ID')})}</button><button class="lnk" ${go$('/account/signin')}>${t('Use password instead')}</button></div>`};
  }});

/* profile, addresses */
FORMS.profile = () => [{k:'name', v:'name'}, {k:'email', v:'email'}, {k:'phone', v:'phone'}];
screen('/account/profile', {tab:'account', group:'Account', label:'Profile', chain:accChain, title:() => t('Profile'),
  render(r){
    if (S.formFor.profile !== 'x') { S.formFor.profile = 'x'; S.form.profile = {name:ut(SARA.name), email:SARA.email, phone:fmtPhone(SARA.phone), skin:'combo'}; S.ferr.profile = {}; }
    const skin = fval('profile', 'skin');
    return {title:t('Profile'), back:true, body:`<div class="pad narrow">${fld({fid:'profile', k:'name', label:t('Full name'), ac:'name', icon:'user', cap:'words'})}${fld({fid:'profile', k:'email', label:t('Email'), type:'email', ac:'email', im:'email', icon:'mail', ltr:true})}${fld({fid:'profile', k:'phone', label:t('Mobile number'), type:'tel', ac:'tel-national', im:'tel', icon:'phone', pre:'+971', ltr:true, max:12})}
      ${pickFld({fid:'profile', k:'bday', label:t('Birthday (for a birthday treat)'), sheet:'bday', value:date('1996-03-14', {day:'numeric', month:'long'}), icon:'cal'})}
      <span class="lab">${t('Skin type')}</span><div class="chips wrap" role="radiogroup">${[['dry', 'Dry'], ['oily', 'Oily'], ['combo', 'Combination'], ['sens', 'Sensitive']].map(([k, l]) => `<button class="chip${skin === k ? ' on' : ''}" role="radio" aria-checked="${skin === k}" data-act="skin" data-v="${k}">${t(l)}</button>`).join('')}</div>
      <div style="height:12px"></div><button class="lnk" ${go$('/account/forgot')}>${t('Change password')}</button></div>`, foot:`<button class="btn block" data-act="profile-save">${t('Save changes')}</button>`};
  }});
screen('/account/addresses', {tab:'account', group:'Account', label:'Addresses', chain:accChain, title:() => t('Addresses'),
  render(){
    const L = S.co.addresses;
    return {title:t('Addresses'), back:true, body:`<div class="pad">${L.length ? L.map(a => `<div class="addr static"><span class="ai">${ico(a.label === 'Work' ? 'building' : 'pin')}</span><span class="grow"><b>${t(a.label)}${a.def ? ` <span class="tagp">${t('Default')}</span>` : ''}</b><small>${utH(a.name)} · ${LTR('+971 ' + fmtPhone(a.phone))}</small><small>${addrLine(a)}</small></span><span class="acts"><button class="ib" ${go$('/account/addresses/new?edit=' + a.id)} aria-label="${t('Edit')}">${ico('edit')}</button><button class="ib" data-act="addr-del" data-v="${a.id}" aria-label="${t('Delete')}">${ico('trash')}</button></span></div>`).join('') : empty('pin', t('No saved addresses'), t('Add one to check out faster.'), null)}
      <button class="btn block ghost" ${go$('/account/addresses/new')}>${ico('plus')}${t('Add address')}</button></div>`};
  },
  dual:() => null});
screen('/account/addresses/new', {tab:'account', group:'Account', label:'Add / edit address', chain:() => ['/account', '/account/addresses'], title:() => t('Address'),
  render(r){
    if (S.formFor.addr !== r.path) { S.formFor.addr = r.path; S.ferr.addr = {}; const ed = r.query.edit && S.co.addresses.find(a => a.id === r.query.edit); if (ed) prefillAddr('addr', ed); else S.form.addr = r.query.filled ? SAMPLE_NEW() : {label:'Home', def:false}; }
    return {title:r.query.edit ? t('Edit address') : t('New address'), back:true, noTabs:true, body:`<div class="pad">${addrForm('addr')}</div>`, foot:`<button class="btn block" data-act="addr-save" data-v="account">${t('Save address')}</button>`};
  }});

/* orders */
const statusPill = st => `<span class="stt ${st}">${t(ST_LABEL[st])}</span>`;
screen('/account/orders', {tab:'account', group:'Account', label:'Orders', chain:accChain, title:() => t('Orders'),
  render(r, ctx){
    const st = r.query.state;
    const sel = ctx.selected;
    const body = st === 'empty' ? empty('box', t('No orders yet'), t('When you place an order, you can follow it here, live.'), t('Start shopping'), '/categories')
      : st === 'loading' ? `<div class="pad">${[1, 2, 3].map(() => `${skel(132, '100%', 16)}<div style="height:12px"></div>`).join('')}</div>`
      : `<div class="pad">${S.orders.map(o => `<button class="ord${sel === '/account/orders/' + o.id ? ' sel' : ''}" ${go$('/account/orders/' + o.id)}><div class="oh"><b>${X('code', '#' + o.id)}</b>${statusPill(o.st)}</div><div class="ims">${o.items.slice(0, 4).map(b => P[b.slug] ? `<img src="${P[b.slug].img200}" alt="" loading="lazy">` : '').join('')}</div><div class="oh small muted"><span>${date(o.d)} · ${t('{n} items', {n:num(o.items.reduce((a, b) => a + b.qty, 0))})}</span><b class="ink">${money(o.total)}</b></div></button>`).join('')}</div>`;
    return {title:t('Orders'), back:true, body, ptr:true};
  },
  dual:() => ({detail:true, def:() => '/account/orders/' + (S.orders[0] || {}).id, match:/^\/account\/orders\//, modes:['dual', 'expanded']})});
screen('/account/orders/:id', {tab:'account', group:'Account', label:'Order detail', chain:() => ['/account', '/account/orders'], list:() => '/account/orders', title:r => '#' + r.params.id,
  render(r, ctx){
    const o = S.orders.find(x => x.id === r.params.id) || S.orders[0];
    const a = S.co.addresses.find(x => x.id === o.addr) || S.co.addresses[0] || savedAddresses()[0];
    const tm = date(o.d, {day:'numeric', month:'short'});
    const steps2 = o.st === 'proc' ? [['Order placed', tm + ' · 10:42', 'done'], ['Confirmed & paid', o.pay === 'cod' ? t('Pay on delivery') : methodTxt(o.pay), 'now'], ['Packed in Dubai', '', ''], ['Out for delivery', '', ''], ['Delivered', '', '']]
      : o.st === 'ship' ? [['Order placed', tm + ' · 10:42', 'done'], ['Confirmed & paid', methodTxt(o.pay), 'done'], ['Packed in Dubai', tm + ' · 15:10', 'done'], ['Out for delivery', t('Arrives today, {a}–{b} pm', {a:num(2), b:num(6)}), 'now'], ['Delivered', '', '']]
      : [['Order placed', tm, 'done'], ['Confirmed & paid', methodTxt(o.pay), 'done'], ['Packed in Dubai', '', 'done'], ['Out for delivery', '', 'done'], ['Delivered', date(o.d, {day:'numeric', month:'short'}), 'done']];
    return {title:'#' + o.id, titleH:X('code', '#' + o.id), back:true, body:`<div class="pad"><div class="cardbox" style="padding:16px"><div class="row2" style="margin-bottom:12px"><span class="grow small muted">${date(o.d)}</span>${statusPill(o.st)}</div><div class="tl">${steps2.map(([b, s, c]) => `<div class="st ${c}"><span class="dot">${c === 'done' ? ico('check') : ''}</span><b>${t(b)}</b>${s ? `<small>${s}</small>` : ''}</div>`).join('')}</div>
      ${o.st !== 'proc' ? `<div class="row2" style="margin-top:2px"><span class="small muted grow">${t('Courier')}: ${X('code', 'Aramex 4485 9921 03')}</span><button class="lnk" data-act="toast" data-v="Opens the courier’s live map.">${t('Track')}</button></div>` : ''}</div>
      <div class="grp px0">${t('Items')}</div><div class="list">${o.items.map(b => { const p = P[b.slug]; return p ? `<button class="li rvi" ${go$('/product/' + p.slug)}><img src="${p.img200}" alt=""><span class="grow"><span class="clamp2 nm">${nameTxt(p)}</span><span class="tiny muted">${t('Qty {n}', {n:num(b.qty)})}</span></span><span class="small nowrap">${money(cur(p) * b.qty)}</span></button>` : ''; }).join('')}</div>
      <div class="grp px0">${t('Delivery')}</div><div class="cardbox" style="padding:14px 16px;font-size:14px"><b>${utH(a.name)}</b><br><span class="muted">${addrLine(a)}</span></div>
      <div class="tot" style="margin-top:12px"><div class="grand" style="border:0;padding-top:0"><span>${t('Total')}</span><span>${money(o.total)}</span></div></div>
      <button class="btn block ghost" ${go$('/contact')}>${ico('chat')}${t('Help with this order')}</button></div>`, foot:`<button class="btn block" data-act="reorder" data-v="${o.id}">${ico('refresh')}${t('Reorder')}</button>`};
  }});

/* settings */
screen('/account/notifications', {tab:'account', group:'Account', label:'Notification settings', chain:accChain, title:() => t('Notifications'),
  render(){
    const N = [['orders', 'Order updates', 'Confirmed, shipped, delivered'], ['stock', 'Back in stock', 'Items you asked about'], ['drop', 'Price drops on your wishlist', 'Only for saved items'], ['bag', 'Bag reminders', 'At most once a day'], ['offers', 'Offers and campaigns', 'New arrivals and sales'], ['blog', 'Skincare tips', 'New blog posts']];
    return {title:t('Notifications'), back:true, body:`<div class="pad" style="padding-bottom:0"><div class="note ok">${ico('check')}<span>${t('Notifications are allowed for K-Beauty Bliss in the phone’s Settings.')}</span></div></div><div class="grp">${t('Push notifications')}</div><div class="list mx">${N.map(([k, l, s]) => toggleRow('ntog', k, S.notif[k], t(l), t(s))).join('')}</div><p class="tiny muted pnote">${t('Order updates also arrive by email, whatever you choose here.')}</p>`};
  }});
screen('/account/language', {tab:'account', group:'Account', label:'Language & region', chain:accChain, title:() => t('Language & region'),
  render(){
    const other = AR ? '../en/index.html#/account/language' : '../ar/index.html#/account/language';
    const langs = AR
      ? `<button class="langc on" aria-current="true"><span class="fl">ع</span><span class="grow"><b>العربية</b><small>${t('This app')}</small></span>${radio(true)}</button><a class="langc" href="${other}" target="_top"><span class="fl">${X('lang', 'EN')}</span><span class="grow"><b>${X('lang', 'English')}</b><small>${t('Opens the English app')}</small></span>${ico('ext', 'chev')}</a>`
      : `<button class="langc on" aria-current="true"><span class="fl">EN</span><span class="grow"><b>English</b><small>${t('This app')}</small></span>${radio(true)}</button><a class="langc" href="${other}" target="_top"><span class="fl">AR</span><span class="grow"><b>Arabic</b><small>Opens the Arabic app, right to left</small></span>${ico('ext', 'chev')}</a>`;
    return {title:t('Language & region'), back:true, body:`<div class="pad">${langs}
      ${AR ? `<div class="grp px0">${t('Numbers')}</div><div class="list" role="radiogroup"><button class="li" data-act="digits" data-v="latn" role="radio" aria-checked="${S.digits === 'latn'}"><span class="grow">${t('Latin digits')} ${LTR('123')}</span>${radio(S.digits === 'latn')}</button><button class="li" data-act="digits" data-v="arab" role="radio" aria-checked="${S.digits === 'arab'}"><span class="grow">${t('Arabic digits')} ١٢٣</span>${radio(S.digits === 'arab')}</button></div><p class="tiny muted">${t('Default: Latin digits, as on the website and UAE receipts.')}</p>` : ''}
      <div class="grp px0">${t('Region')}</div><div class="list"><div class="li"><span class="grow">${t('Country')}</span><span class="v">${t('United Arab Emirates')}</span></div><div class="li"><span class="grow">${t('Currency')}</span><span class="v">${AR ? 'درهم إماراتي (د.إ)' : 'UAE dirham (AED)'}</span></div></div></div>`};
  }});
screen('/account/delete', {tab:'account', group:'Account', label:'Delete account', chain:accChain, title:() => t('Delete account'),
  render(){
    return {title:t('Delete account'), back:true, body:`<div class="pad"><div class="state" style="min-height:0;padding:12px 0 18px"><div class="ic errc">${ico('trash')}</div><h2>${t('Delete your account?')}</h2></div><p>${t('This permanently deletes:')}</p><ul class="dl"><li>${t('your profile, addresses and wishlist')}</li><li>${t('your saved sign-in on every device')}</li><li>${t('your marketing preferences')}</li></ul><div class="note warn">${ico('info')}<span>${t('Invoices for past orders are kept for 5 years, as UAE VAT law requires.')}</span></div></div>`, foot:`<button class="btn block danger" data-sheet="delconfirm">${t('Delete account')}</button><button class="btn block ghost noborder" style="margin-top:8px" data-act="back">${t('Keep my account')}</button>`};
  }});

/* ── content ───────────────────────────────────────────────────────────── */
FORMS.msg = () => [{k:'name', v:'name'}, {k:'email', v:'email'}, {k:'topic', pick:true}, {k:'order', opt:true}, {k:'body', v:'msg'}];
const TOPICS = [['order', 'An order'], ['product', 'A product question'], ['returns', 'Returns'], ['other', 'Something else']];
screen('/contact', {tab:'account', group:'Content', label:'Contact (WhatsApp here only)', chain:accChain, list:() => '/faq', title:() => t('Contact us'),
  render(r){
    if (S.formFor.msg !== 'x') { S.formFor.msg = 'x'; S.form.msg = S.user ? {name:ut(SARA.name), email:SARA.email, topic:'order', order:'KBB-56214'} : {}; S.ferr.msg = {}; }
    if (r.query.filled && !fval('msg', 'body')) S.form.msg.body = t('Hi, can I change the delivery time to the evening?');
    const tp = TOPICS.find(x => x[0] === fval('msg', 'topic'));
    const body = S.sent.msg ? `<div class="state" style="min-height:0;padding-top:28px"><div class="ic">${ico('check')}</div><h2>${t('Message sent')}</h2><p>${t('Thanks. We usually reply within a few hours, by email.')}</p><button class="btn ghost" data-act="msg-again">${t('Send another message')}</button></div>`
      : `<div class="pad narrow"><p class="muted" style="margin-top:0">${t('We reply within a few hours, 7 days a week.')}</p>
      <button class="btn block wa" data-act="wa">${ico('wa')}${t('Chat on WhatsApp')}</button><p class="tiny muted center" style="margin:6px 0 16px">${t('Opens WhatsApp. This is the only place the app hands off.')}</p>
      <div class="hr" style="margin-top:0"></div><b class="db" style="margin-bottom:12px">${t('Or send us a message')}</b>
      ${fld({fid:'msg', k:'name', label:t('Name'), ac:'name', icon:'user', cap:'words', ekh:'next'})}
      ${fld({fid:'msg', k:'email', label:t('Email'), type:'email', ac:'email', im:'email', icon:'mail', ltr:true, ekh:'next'})}
      ${pickFld({fid:'msg', k:'topic', label:t('Topic'), sheet:'topic', value:tp ? t(tp[1]) : '', icon:'help'})}
      ${fld({fid:'msg', k:'order', label:t('Order number'), opt:true, icon:'hash', ltr:true, hint:'KBB-12345', cap:'characters'})}
      ${fld({fid:'msg', k:'body', label:t('Message'), area:true, max:1000})}
      <button class="btn block" data-act="msg-send">${t('Send message')}</button></div>`;
    return {title:t('Contact us'), back:true, body};
  }});
const FAQS = [['How long does delivery take?', '1–3 working days across the UAE. {a}, free over {b}.'], ['Are the products authentic?', 'Yes. Every product comes from the brand or its authorised distributor.'], ['Can I pay in instalments?', 'Yes, with Tabby or Tamara: 4 interest-free payments.'], ['Do you offer cash on delivery?', 'In the UAE, for a {c} fee.'], ['How do I return an item?', 'Orders → the order → Help. Unopened items only, within 7 days.'], ['Do you deliver outside the UAE?', 'Yes, to the GCC: {d}, free over {e}.']];
screen('/faq', {tab:'account', group:'Content', label:'FAQ', chain:accChain, title:() => t('FAQ'),
  render(){
    const v = {a:money(SET.ship_uae), b:money(SET.free_over_uae), c:money(SET.cod_fee), d:money(SET.ship_gcc), e:money(SET.free_over_gcc)};
    return {title:t('FAQ'), back:true, body:`<div class="pad" style="padding-top:4px">${FAQS.map(([q, a], i) => { const open = S.open['f' + i] ?? i === 0; return `<div class="faq${open ? ' open' : ''}"><button data-act="acc" data-v="f${i}" data-def="${i === 0 ? 1 : 0}" aria-expanded="${!!open}">${t(q)}${ico('chevD')}</button>${open ? `<p>${t(a, v)}</p>` : ''}</div>`; }).join('')}<div style="height:16px"></div><button class="btn block soft" ${go$('/contact')}>${t('Still need help? Contact us')}</button></div>`};
  },
  dual:() => ({detail:true, def:() => '/contact', match:/^\/contact/, modes:['dual', 'expanded']})});
const POLICIES = [['shipping', 'Shipping', 'truck'], ['returns', 'Returns & refunds', 'refresh'], ['privacy', 'Privacy policy', 'shield'], ['terms', 'Terms of sale', 'doc']];
screen('/policies', {tab:'account', group:'Content', label:'Policies', chain:accChain, title:() => t('Policies'),
  render(r, ctx){
    return {title:t('Policies'), back:true, body:`<div class="pad"><div class="list">${POLICIES.map(([k, l, i]) => `<button class="li${ctx.selected === '/policy/' + k ? ' sel' : ''}" ${go$('/policy/' + k)}>${ico(i)}<span class="grow">${t(l)}</span>${ico('chevR', 'chev flip')}</button>`).join('')}</div></div>`};
  },
  dual:() => ({detail:true, def:() => '/policy/shipping', match:/^\/policy\//, modes:['dual', 'expanded']})});
function policyText(k){
  const v = {a:money(SET.ship_uae), b:money(SET.free_over_uae), c:money(SET.cod_fee), d:money(SET.ship_gcc), e:money(SET.free_over_gcc)};
  const T = {
    shipping:[['Delivery in the UAE', 'All seven emirates, 1–3 working days. {a} per order, free over {b}. Express next-day delivery in Dubai, Sharjah and Ajman.'], ['Gulf countries', 'Saudi Arabia, Kuwait, Bahrain, Qatar and Oman: {d} per order, free over {e}.'], ['Cash on delivery', 'UAE only, {c} fee, paid to the courier.']],
    returns:[['What can be returned', 'Unopened, unused items in their original packaging, within 7 days of delivery.'], ['How to return', 'Start from Orders → the order → Help. We arrange the pickup.'], ['Refunds', 'Back to the original payment method within 5–10 working days of the item reaching us.']],
    privacy:[['What we collect', 'Your name, contact details, addresses and orders, to deliver what you buy.'], ['What we never do', 'We never sell your data. Card details go to Stripe and are never stored by us.'], ['Your choices', 'Change notifications any time, or delete your account from Account.']],
    terms:[['Prices', 'Prices are in UAE dirhams and include VAT.'], ['Orders', 'An order is confirmed when you receive the confirmation email.'], ['Authenticity', 'Every product is sourced from the brand or its authorised distributor.']],
  }[k] || [];
  return T.map(([h, p]) => `<h2>${t(h)}</h2><p>${t(p, v)}</p>`).join('');
}
screen('/policy/:k', {tab:'account', group:'Content', label:'Policy page', chain:() => ['/account', '/policies'], list:() => '/policies', title:r => t((POLICIES.find(x => x[0] === r.params.k) || POLICIES[0])[1]),
  render(r){
    const p = POLICIES.find(x => x[0] === r.params.k) || POLICIES[0];
    return {title:t(p[1]), back:true, body:`<div class="prose"><h1 class="h1">${t(p[1])}</h1><p class="small muted">${t('Last updated {d}', {d:date('2026-10-01')})}</p>${policyText(p[0])}</div>`};
  }});
screen('/about', {tab:'account', group:'Content', label:'About', chain:accChain, title:() => t('About'),
  render(){
    return {title:t('About'), back:true, body:`<div class="pad center">${logo()}</div><div class="prose"><p>${t('K-Beauty Bliss brings authentic Korean skincare to the UAE: {n}+ products from {b} brands, delivered fast across the Emirates.', {n:num(Math.floor(((D.counts || {}).catalogue || 760) / 10) * 10), b:num((D.counts || {}).brands_on_site || 96)})}</p><p>${t('Every product is sourced from the brand or its authorised distributor.')}</p></div><div class="trust">${(D.trust || []).map((x, i) => `<div><i>${ico(['shield', 'truck', 'card', 'chat'][i])}</i><span>${esc((x.t || []).join(' '))}</span></div>`).join('')}</div>`};
  }});
/* blog: the site has no Arabic posts yet; the Arabic app reads its own
   translation from the string table (blog:<slug>:…) and says so */
const bt = (x, f) => AR ? (DICT['blog:' + x.slug + ':' + f] || null) : x[f];
screen('/blog', {tab:'account', group:'Content', label:'Blog list', chain:accChain, title:() => t('Blog'),
  render(r, ctx){
    const L = (D.posts || []).filter(x => bt(x, 'title'));
    return {title:t('Blog'), large:true, back:true, body:`<div class="pad bloglist">${L.map(x => `<button class="blog${ctx.selected === '/blog/' + x.slug ? ' sel' : ''}" ${go$('/blog/' + x.slug)}>${x.cover ? `<img src="${x.cover}" alt="" loading="lazy">` : ''}<span class="bt"><b class="clamp2">${esc(bt(x, 'title'))}</b><small class="clamp2">${esc(bt(x, 'excerpt') || '')}</small><small>${date(x.date)}</small></span></button>`).join('')}</div>`, ptr:true};
  },
  dual:() => ({detail:true, def:() => '/blog/' + ((D.posts || [])[0] || {}).slug, match:/^\/blog\//, modes:['dual', 'expanded']})});
screen('/blog/:slug', {tab:'account', group:'Content', label:'Blog article', chain:() => ['/account', '/blog'], list:() => '/blog', title:r => { const x = (D.posts || []).find(p => p.slug === r.params.slug) || (D.posts || [])[0] || {}; return bt(x, 'title') || ''; },
  render(r){
    const x = (D.posts || []).find(p => p.slug === r.params.slug) || (D.posts || [])[0] || {};
    const paras = AR ? (DICT['blog:' + x.slug + ':body'] || []) : ((D.articles || {})[x.slug] || []);
    const kw = (x.title || '').toLowerCase().match(/pdrn|spf|sun|mask|spot|pigment|axis|morning/);
    const rel = PL.filter(p => kw ? (p.name_en || p.name).toLowerCase().includes(kw[0] === 'pigment' || kw[0] === 'spot' ? 'spot' : kw[0] === 'morning' ? 'toner' : kw[0]) : false).slice(0, 8);
    return {title:'', back:true, right:`<button class="ib" data-act="share" aria-label="${t('Share')}">${ico('share')}</button>`, body:`${x.cover ? `<img src="${x.cover}" alt="" class="acover">` : ''}<div class="prose"><p class="tiny muted" style="margin-top:14px">${date(x.date || '2026-10-15')} · ${brandWord()}</p><h1 class="h1">${esc(bt(x, 'title') || '')}</h1>${AR ? `<p class="note small">${t('Translated for the app preview. The website has this article in English only.')}</p>` : ''}${paras.map(s => `<p>${esc(s)}</p>`).join('')}</div>${rel.length ? sec(t('Shop the article'), rail(rel)) : ''}`};
  }});

/* ── start & system states ─────────────────────────────────────────────── */
screen('/start/splash', {tab:'home', group:'States', label:'Splash', chain:() => [], title:() => t('Splash'),
  render(){ return {nobar:true, bare:true, noTabs:true, raw:`<div class="splash">${logo()}<div class="ld"><i></i></div></div>`}; }});
screen('/start/notifications', {tab:'home', group:'States', label:'First run · notifications', chain:() => [], title:() => t('Notifications'),
  render(){
    return {nobar:true, bare:true, noTabs:true, body:`<div class="onb"><div class="top"><div class="state" style="min-height:0;padding:0"><div class="ic">${ico('bell')}</div></div><h1>${t('Know the moment it ships')}</h1><p>${t('Order updates, back-in-stock and price drops on your wishlist. You choose which ones, any time.')}</p>
      <div class="notifmock"><span class="ai">${LOTUS}</span><span class="grow"><b>${t('Your order is on its way')}</b><small>${t('{id} left our Dubai warehouse. Arrives today.', {id:X('code', '#KBB-56214')})}</small></span></div>
      <div class="notifmock"><span class="ai">${LOTUS}</span><span class="grow"><b>${t('Back in stock')}</b><small class="clamp2">${nameTxt(PL[2] || PL[0])}</small></span></div></div>
      <button class="btn block" data-act="perm">${t('Turn on notifications')}</button><button class="btn block ghost noborder" style="margin-top:10px" ${go$('/home')} data-tabroot="home">${t('Not now')}</button>
      <p class="tiny muted center" style="margin:8px 0 0">${t('Next, the phone asks once. If you say no, Account → Notifications explains how to turn them on.')}</p></div>`};
  }});
screen('/state/offline', {tab:'home', group:'States', label:'Offline screen', chain:() => [], title:() => t('Offline'),
  render(){ return {nobar:true, body:`<div class="state"><div class="ic">${ico('wifioff')}</div><h2>${t('You’re offline')}</h2><p>${t('Check Wi-Fi or mobile data. Your bag and wishlist are saved on this phone.')}</p><button class="btn" data-act="retry">${ico('refresh')}${t('Try again')}</button><button class="lnk" data-tab="bag">${t('Open my bag')}</button></div>`}; }});
screen('/state/update', {tab:'home', group:'States', label:'Update required', chain:() => [], title:() => t('Update'),
  render(){ return {nobar:true, noTabs:true, bare:true, body:`<div class="state">${logo()}<div style="height:12px"></div><div class="ic">${ico('down')}</div><h2>${t('Time for an update')}</h2><p>${t('This version can no longer place orders safely. The update takes a minute; your bag stays as it is.')}</p><button class="btn" data-act="toast" data-v="Opens the store page.">${isA() ? t('Update on {s}', {s:X('pay', 'Google Play')}) : t('Update on the {s}', {s:X('pay', 'App Store')})}</button><p class="tiny muted">${t('Set in the admin: App → Mobile app → Minimum version.')}</p></div>`}; }});

/* ── sheets (bottom sheets / centred dialogs from Medium up) ──────────── */
function sheetHTML(k){
  const close = `<button class="ib" data-act="close" aria-label="${t('Close')}">${ico('close')}</button>`;
  const head = (title) => `<div class="grab"></div><div class="sh2"><div></div><b>${title}</b><div class="e">${close}</div></div>`;
  const key = S.sheetKey;
  if (k === 'filter') {
    const r = route(), base = r.path.startsWith('/category/') ? (CAT[r.params.k] || {items:[]}).items.map(s => P[s]).filter(Boolean) : r.path.startsWith('/brand/') ? (BR[r.params.b] || {items:[]}).items.map(s => P[s]).filter(Boolean) : productsBase(r);
    const n = applyList(key, base).length;
    return `<div class="sheet full" role="dialog" aria-label="${t('Filter')}">${head(t('Filter'))}<div class="sb2">${filterPanel(key, base, {noBrand:r.path.startsWith('/brand/')})}</div><div class="sf"><button class="btn ghost" data-act="clearf" data-k="${key}">${t('Clear')}</button><button class="btn" data-act="close">${t('Show {n} results', {n:num(n)})}</button></div></div>`;
  }
  if (k === 'sort') return `<div class="sheet" role="dialog" aria-label="${t('Sort by')}">${head(t('Sort by'))}<div class="sb2"><div class="list" role="radiogroup">${SORTS.map(([v, l]) => `<button class="li" data-act="sort" data-k="${key}" data-v="${v}" role="radio" aria-checked="${(S.sort[key] || 'pop') === v}"><span class="grow">${t(l)}</span>${radio((S.sort[key] || 'pop') === v)}</button>`).join('')}</div></div></div>`;
  if (k === 'emirate') { const cur2 = fval('addr', 'emirate'); return `<div class="sheet" role="dialog" aria-label="${t('Emirate')}">${head(t('Emirate'))}<div class="sb2" role="listbox">${EMIRATES.map(e => `<button class="pickrow" role="option" aria-selected="${cur2 === e[0]}" data-act="pick" data-k="emirate" data-v="${e[0]}"><span>${t(e[1])}</span>${cur2 === e[0] ? ico('check') : ''}</button>`).join('')}</div></div>`; }
  if (k === 'emirate-first') return `<div class="sheet" role="dialog" aria-label="${t('Area')}">${head(t('Area'))}<div class="sb2"><div class="note">${ico('info')}<span>${t('Choose your emirate first. The areas are listed by emirate.')}</span></div><div style="height:12px"></div><button class="btn block" data-sheet="emirate">${t('Choose emirate')}</button></div></div>`;
  if (k === 'area') {
    const em = fval('addr', 'emirate'), cur2 = fval('addr', 'area');
    const q = (S.areaQ || '').trim().toLowerCase();
    const L = areasOf(em).filter(a => !q || t(a[1]).toLowerCase().includes(q) || a[1].toLowerCase().includes(q));
    return `<div class="sheet full" role="dialog" aria-label="${t('Area')}">${head(t('Area in {e}', {e:emirateName(em)}))}<div class="sb2"><label class="srch field" for="aq" style="margin-bottom:8px"><span>${ico('search')}</span><input id="aq" type="search" value="${esc(S.areaQ || '')}" placeholder="${t('Search areas')}" autocomplete="off" aria-label="${t('Search areas')}"></label><div id="alist" role="listbox">${areaRows(L, cur2)}</div></div></div>`;
  }
  if (k === 'topic') { const c2 = fval('msg', 'topic'); return `<div class="sheet" role="dialog" aria-label="${t('Topic')}">${head(t('Topic'))}<div class="sb2" role="listbox">${TOPICS.map(([v, l]) => `<button class="pickrow" role="option" aria-selected="${c2 === v}" data-act="pick" data-k="topic" data-f="msg" data-v="${v}"><span>${t(l)}</span>${c2 === v ? ico('check') : ''}</button>`).join('')}</div></div>`; }
  if (k === 'bday') return `<div class="sheet" role="dialog" aria-label="${t('Birthday')}">${head(t('Birthday'))}<div class="sb2"><div class="wheel" aria-hidden="true"><span>${date('1996-03-13', {day:'numeric', month:'long'})}</span><b>${date('1996-03-14', {day:'numeric', month:'long'})}</b><span>${date('1996-03-15', {day:'numeric', month:'long'})}</span></div><button class="btn block" data-act="close">${t('Done')}</button></div></div>`;
  if (k === 'bnpl') { const p = P[(route().params || {}).slug]; const v = p ? cur(p) : bagTotals().total; return `<div class="sheet" role="dialog" aria-label="${t('Pay later')}">${head(t('Pay in 4, no interest'))}<div class="sb2"><div class="sched">${[t('Today'), t('In 1 month'), t('In 2 months'), t('In 3 months')].map((w, i) => `<div class="${i ? '' : 'now'}"><i></i><b>${money(inst(v))}</b><span>${w}</span></div>`).join('')}</div><div class="list"><div class="li"><span class="tabby" data-x="pay">tabby</span><span class="grow small">${t('No interest, no fees. Pay the first today.')}</span></div><div class="li"><span class="tamara" data-x="pay">tamara</span><span class="grow small">${t('Sharia-compliant, no late fees.')}</span></div></div><p class="tiny muted">${t('Choose Tabby or Tamara at checkout.')}</p></div></div>`; }
  if (k === 'delconfirm') return `<div class="dlg" role="alertdialog" aria-label="${t('Delete account?')}"><div class="dt"><b>${t('Delete account?')}</b><p>${t('This can’t be undone. You’ll be signed out on every device.')}</p></div><div class="da"><button data-act="close">${t('Cancel')}</button><button class="red" data-act="delete-account" data-hap="warning">${t('Delete')}</button></div></div>`;
  return '';
}
const areaRows = (L, cur2) => L.length ? L.map(a => `<button class="pickrow" role="option" aria-selected="${cur2 === a[0]}" data-act="pick" data-k="area" data-v="${a[0]}"><span>${t(a[1])}</span>${cur2 === a[0] ? ico('check') : ''}</button>`).join('') : `<p class="muted small" style="padding:12px 4px">${t('No area matches. Check the spelling.')}</p>`;
