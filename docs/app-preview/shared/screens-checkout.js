'use strict';
/* =========================== BAG & CHECKOUT (Lane APV3) ==================
   The owner: "we need the same one step as in site mobile version. or max two
   steps." and, for the bag, "same for cart page."

   So the bag is the site's cart page and the checkout is the site's ONE-PAGE
   checkout (the "13 October" layout the shop runs): the same sections in the
   same order, the same labels, icons and logos, drawn by the site's own CSS
   (en|ar/site-co.css) from the site's own markup (en|ar/site-co.js), both
   captured by tools/apv-site-co.cjs. This file clones that markup and binds it
   to the app: the bag's lines, the form's values and errors, the delivery and
   payment choices, the coupon and the totals.

   Bag → Checkout is the whole road to Place order: two screens. What follows
   the tap is not a step: 3-D Secure, the Apple Pay / Google Pay sheet, Tabby
   or Tamara approving in the app, the order placed, a payment failed (the bag
   kept). The emirate picker opens over the checkout.
   ========================================================================= */
const VISA = X('pay', 'Visa');
const METHOD_ROUTE = {apple:'/checkout/wallet', gpay:'/checkout/wallet', card:'/checkout/3ds', tabby:'/checkout/tabby', tamara:'/checkout/tamara', cod:null};
const methodName = k => ({apple:t('Apple Pay'), gpay:t('Google Pay'), card:t('Credit or debit card'), tabby:t('Tabby · 4 payments'), tamara:t('Tamara · 4 payments'), cod:t('Cash on delivery')}[k]);
const methodTxt = k => methodName(k);
const CO_CHAIN = ['/checkout'];
const GIFT_FEE = 15;

/* ── the site's markup, parsed once ─────────────────────────────────────── */
const SCO = window.APV_SITE_CO || {cart:'', checkout:'', words:{pay:'Pay now', cod:'Complete Order'}};
let COT = null;
function coTpl(){
  if (COT) return COT;
  const P0 = new DOMParser();
  const strip = h => h.replace(/<style[\s\S]*?<\/style>/g, '');
  const cart = P0.parseFromString(`<body>${strip(SCO.cart)}</body>`, 'text/html').body.firstElementChild;
  const co = P0.parseFromString(`<body>${strip(SCO.checkout)}</body>`, 'text/html').body.firstElementChild;
  /* the site's CSS names ids as classes (#x -> .id-x, see tools/apv-site.cjs) */
  for (const r of [cart, co]) [r, ...r.querySelectorAll('[id]')].forEach(e => { if (e.id) e.classList.add('id-' + e.id); });
  COT = {cart, co};
  return COT;
}
const SYM = AR ? 'د.إ' : 'AED';
const amt = v => `<span class="woocommerce-Price-amount amount" dir="ltr"><span class="woocommerce-Price-currencySymbol" dir="auto">${SYM}</span> ${num(r2(v))}</span>`;
const $1 = (root, s) => root.querySelector(s);
const each = (root, s, f) => root.querySelectorAll(s).forEach(f);
/* the site's own links become app routes; anything else is inert */
function retarget(root){
  each(root, 'a[href]', a => {
    const h = a.getAttribute('href').replace(/^\/ar(?=\/)/, '');
    a.removeAttribute('href'); a.setAttribute('role', 'button'); a.setAttribute('tabindex', '0');
    if (/^\/(shop\/)?$/.test(h)) { a.dataset.go = '/home'; a.dataset.tabroot = 'home'; }
    else if (/^\/cart\/?$/.test(h)) a.dataset.act = 'back';
    else if (/^\/checkout\/?$/.test(h)) a.dataset.go = '/checkout';
    else if (/^\/my-account/.test(h)) a.dataset.go = '/account/signin?next=checkout';
    else if (/wa\.me/.test(h)) a.dataset.act = 'wa';
    else if (/^mailto:/.test(h)) { a.dataset.act = 'toast'; a.dataset.v = 'Opens your email app.'; }
    else if (/delivery/.test(h)) a.dataset.go = '/policy/shipping';
    else if (/privacy/.test(h)) a.dataset.go = '/policy/privacy';
    else a.dataset.go = '/policies';
  });
  /* the Arabic site keeps a few Latin words of its own: payment names */
  if (AR) each(root, 'label, span, b', e => { if (e.children.length === 0 || e.matches('label')) { const tx = [...e.childNodes].filter(n => n.nodeType === 3).map(n => n.nodeValue).join(''); if (/[A-Za-z]{2,}/.test(tx) && !e.closest('[data-x]')) e.dataset.x = 'pay'; } });
}

/* ── the bag: the site's cart page ─────────────────────────────────────── */
function cartLine(tplLine, b){
  const p = P[b.slug]; if (!p) return null;
  const ci = tplLine.cloneNode(true);
  const th = $1(ci, '.cth'); th.style.backgroundImage = `url('${p.img}')`; th.setAttribute('role', 'button'); th.dataset.go = '/product/' + p.slug; th.setAttribute('aria-label', pn(p));
  const a = $1(ci, '.cn a'); a.innerHTML = nameTxt(p); a.dataset.go = '/product/' + p.slug; a.removeAttribute('href'); a.setAttribute('role', 'button'); a.setAttribute('tabindex', '0');
  const qb = ci.querySelectorAll('.qty button');
  qb.forEach(x => { x.dataset.act = 'bq'; x.dataset.p = p.slug; x.dataset.v = x.dataset.d; x.removeAttribute('data-kcpq'); });
  $1(ci, '.qty span').textContent = num(b.qty);
  const w = wasOf(p);
  $1(ci, '.cpr').innerHTML = amt(cur(p) * b.qty) + (w ? `<span class="cwas">${amt(w * b.qty)}</span>` : '');
  const rm = $1(ci, '.crm'); rm.dataset.act = 'rm'; rm.dataset.p = p.slug; rm.removeAttribute('data-kcprm');
  return ci;
}
scr('/bag', {tab:'bag', group:'Bag & Checkout', label:'Bag', title:() => t('Bag'),
  render(r, ctx){
    const st = r.query.state;
    if (st === 'loading') return {title:t('Bag'), large:true, body:`<div class="pad">${skel(64, '100%', 14)}<div style="height:16px"></div>${[1, 2].map(() => `<div style="display:flex;gap:12px;margin-bottom:16px">${skel(76, '76px', 12)}<div style="flex:1">${skel(12, '40%')}<div style="height:8px"></div>${skel(16, '90%')}<div style="height:8px"></div>${skel(30, '60%')}</div></div>`).join('')}</div>`};
    if (!S.bag.length || st === 'empty') return {title:t('Bag'), large:true, body:empty('bag', t('Your bag is empty'), t('Korean skincare, delivered across the UAE. Free delivery over {v}.', {v:money(SET.free_over_uae)}), t('Start shopping'), '/categories')};
    const T = bagTotals({noCod:true, noGift:true});
    const root = coTpl().cart.cloneNode(true);
    retarget(root);
    const n = bagCount();
    const lead = $1(root, '.cart-count'); if (lead) lead.textContent = AR ? `(${num(n)} ${n === 1 ? 'منتج' : n === 2 ? 'منتجان' : n <= 10 ? 'منتجات' : 'منتجًا'})` : `(${num(n)} ${n === 1 ? 'item' : 'items'})`;
    const items = $1(root, '.items'), tpl = $1(items, '.ci');
    items.replaceChildren(...S.bag.map(b => cartLine(tpl, b)).filter(Boolean));
    const rows = root.querySelectorAll('aside.sum .srow');
    if (rows[0]) rows[0].lastElementChild.innerHTML = amt(T.sub);
    if (rows[1]) rows[1].lastElementChild.innerHTML = amt(T.sub - T.disc);
    each(root, '.paylogos span', s => { if (/[A-Za-z]/.test(s.textContent)) s.dataset.x = 'pay'; });
    root.removeAttribute('id'); each(root, '[id]', e => e.removeAttribute('id'));
    return {nobar:true, body:`${st === 'offline' ? offbar() : ''}<div class="kc" dir="${AR ? 'rtl' : 'ltr'}" lang="${LANG}">${root.outerHTML}</div>`, ptr:true, sk:'bag'};
  }});

/* ── the checkout: the site's one page ─────────────────────────────────── */
/* field name on the site → the app's form key */
const COF = {billing_email:'email', billing_first_name:'name', billing_phone:'phone', billing_address_1:'building', billing_address_2:'street', billing_state:'emirate', billing_country:'country', account_password:'pw', gift_note:'giftmsg'};
FORMS.co = () => [{k:'email', v:'email'}, {k:'name', v:'name'}, {k:'phone', v:'phone'}, {k:'building', v:'req'}, {k:'street', v:'req'}, {k:'emirate', v:'req'}, {k:'pw', v:'pw', when:() => !!S.co.acct}];
FORMS.card = () => [{k:'number', v:'card'}, {k:'exp', v:'exp'}, {k:'cvc', v:'cvc'}];
const SAMPLE_CO = () => ({email:'sara@example.com', name:ut('Sara Al Mansoori'), phone:'50 123 4567', building:ut('Marina Gate 2'), street:areaName('dubai', 'dubai-marina'), emirate:'dubai', country:'AE'});
function coPrefill(r){
  const key = r.path;
  if (S.formFor.co === key) return;
  S.formFor.co = key; S.ferr.co = {}; S.ferr.card = {};
  if (r.query.filled || (S.user && !r.query.guest)) {
    const a = coAddress() || {};
    S.form.co = r.query.filled ? SAMPLE_CO() : {email:S.user.email, name:ut(S.user.name), phone:fmtPhone(S.user.phone), building:ut(a.building), street:areaName(a.emirate, a.area), emirate:a.emirate || '', country:'AE'};
  } else S.form.co = {country:'AE'};
  if (r.query.filled) S.form.card = {number:'4242 4242 4242 4242', exp:'12 / 29', cvc:'123'};
  else if (!S.form.card) S.form.card = {};
  if (r.query.pay && METHOD_ROUTE.hasOwnProperty(r.query.pay)) S.co.pay = r.query.pay;
  if (r.query.errors) { S.form.co = {country:'AE', email:'sara@', phone:'50 123'}; validate('co'); S.co.sumOpen = false; }
  if (r.query.sum) S.co.sumOpen = true;
}
/* one field of the site's form, bound to the app's form state */
function bindField(root, fid, k, input){
  const id = `f-${fid}-${k}`;
  input.id = id; input.dataset.form = fid; input.dataset.k = k;
  const lab = input.parentElement.querySelector('label[for]'); if (lab) lab.setAttribute('for', id);
  const v = fval(fid, k);
  if (input.tagName === 'SELECT') {
    if (k === 'emirate') { const e = emirate(v); [...input.options].forEach((o, i) => { const on = e ? o.value.toLowerCase() === e[1].toLowerCase() : i === 0; if (on) o.setAttribute('selected', ''); else o.removeAttribute('selected'); }); input.dataset.sheet = 'emirate'; input.dataset.k = k; }
  } else input.setAttribute('value', v);
  const err = ferrMsg(fid, k);
  const wrap = input.closest('.fld') || input.parentElement;
  wrap.dataset.fld = `${fid}.${k}`;
  const row = input.closest('.form-row') || wrap;
  row.classList.toggle('woocommerce-invalid', !!err); wrap.classList.toggle('err', !!err);
  input.setAttribute('aria-describedby', id + '-m'); if (err) input.setAttribute('aria-invalid', 'true');
  const m = document.createElement('span'); m.className = 'fl-m'; m.id = id + '-m'; m.setAttribute('aria-live', 'polite');
  if (err) m.innerHTML = ico('alert') + `<span>${err}</span>`;
  wrap.after(m);
  if (/phone|email/.test(k) || input.type === 'tel') { input.setAttribute('dir', 'ltr'); if (AR && /[A-Za-z]{2,}/.test(input.getAttribute('placeholder') || '')) input.dataset.x = 'code'; }
}
function coSummaryItems(root){
  const box = $1(root, '.co-items'); if (!box) return;
  const tpl = $1(box, '.ci');
  box.replaceChildren(...S.bag.map(b => {
    const p = P[b.slug]; if (!p) return null;
    const ci = tpl.cloneNode(true); ci.dataset.key = p.slug;
    $1(ci, '.cth').style.backgroundImage = `url('${p.img}')`; $1(ci, '.qb').textContent = num(b.qty);
    $1(ci, '.n').innerHTML = nameTxt(p);
    ci.querySelectorAll('.co-q').forEach(x => { x.dataset.act = 'bq'; x.dataset.p = p.slug; x.dataset.v = x.dataset.d; });
    $1(ci, '.qty span').textContent = num(b.qty);
    const rm = $1(ci, '.co-rm'); rm.dataset.act = 'rm'; rm.dataset.p = p.slug; rm.setAttribute('aria-label', `${t('Remove')} · ${pn(p)}`);
    $1(ci, '.cprice').innerHTML = amt(cur(p) * b.qty);
    return ci;
  }).filter(Boolean));
}
function coTotals(root, T){
  each(root, '.js-subtotal', e => e.innerHTML = amt(T.sub));
  each(root, '.js-shipping', e => e.innerHTML = T.ship ? amt(T.ship) : `<span style="color:var(--green);font-weight:700">${t('Free')}</span>`);
  each(root, '.js-gift-row', e => { e.hidden = !T.gift; }); each(root, '.js-gift', e => e.innerHTML = amt(T.gift));
  each(root, '.js-total', e => e.innerHTML = amt(T.total));
  each(root, '.js-total-row-fee', e => e.remove());
  each(root, '.js-coupons', e => { e.innerHTML = T.disc ? `<div class="sumrow cpn-on"><span>${t('Discount')} ${X('code', `<b>${S.coupon}</b>`)} <button type="button" class="co-cpx" data-act="uncoupon" aria-label="${t('Remove')}">✕</button></span><span>−${amt(T.disc)}</span></div>` : ''; });
  each(root, '.js-vat-short', e => { if (T.cod) e.textContent += ` · ${t('Cash on delivery fee')} ${money(T.cod)}`; });
}
function walletRow(tplLi){
  const k = isA() ? 'gpay' : 'apple';
  const li = tplLi.cloneNode(true);
  li.className = `wc_payment_method payment_method_${k} pay-brand`;
  li.querySelectorAll('.payment_box').forEach(b => b.innerHTML = `<p>${isA() ? t('Fastest. Confirm with your fingerprint or screen lock.') : t('Fastest. Confirm with Face ID.')}</p>`);
  const lab = $1(li, 'label');
  lab.innerHTML = ` ${isA() ? t('Google Pay') : t('Apple Pay')} <span class="pay-logo" aria-hidden="true">${isA() ? `<span class="paybtn gpay mini" data-x="pay"><span class="g">G</span>&nbsp;Pay</span>` : `<span class="paybtn mini" data-x="pay">${APPLE}Pay</span>`}</span>`;
  if (AR) lab.dataset.x = 'pay';
  return li;
}
function checkoutHTML(r, ctx){
  const T = bagTotals();
  const root = coTpl().co.cloneNode(true);
  const head = $1(root, 'header.co-head'); head.remove();
  retarget(root);
  each(root, '[id]', e => { if (!/^(billing_|payment_method_|shipping_method)/.test(e.id)) e.removeAttribute('id'); });
  /* 1 · Delivery information */
  each(root, 'input[name], select[name], textarea[name]', inp => { const k = COF[inp.name]; if (k) bindField(root, 'co', k, inp); });
  const signed = S.user && !r.query.guest;
  const si = $1(root, '.co-si-stack'); if (si && signed) si.innerHTML = `<span class="co-signin-q">${t('Signed in')}</span><span class="co-signin is-b" data-x="email">${esc(S.user.email)}</span>`;
  const acct = $1(root, '#create_account_field'); if (acct && signed) acct.remove();
  const acb = $1(root, '#create_account'); if (acb) { acb.dataset.act = 'cotog'; acb.dataset.v = 'acct'; if (S.co.acct) acb.setAttribute('checked', ''); }
  const pw = root.querySelector('.kbb-acct-pw'); if (pw) { pw.hidden = !S.co.acct; const i = $1(pw, 'input'); if (i) { if (S.co.acct) i.removeAttribute('disabled'); else i.setAttribute('disabled', ''); } }
  const wa = $1(root, '#billing_kbb_whatsapp'); if (wa) { wa.dataset.act = 'cotog'; wa.dataset.v = 'wa'; if (S.co.wa) wa.setAttribute('checked', ''); }
  /* 2 · Delivery: the one method the site offers for this bag (free over 199) */
  const note = $1(root, '.co-dnote'); if (note) note.hidden = !T.ship;
  const dl = $1(root, '.woocommerce-shipping-methods li');
  if (dl) { $1(dl, 'input').setAttribute('checked', ''); const lt = $1(dl, '.co-dl-t'); if (lt) lt.textContent = T.ship ? t('Express Delivery') : lt.textContent; const lp = $1(dl, '.co-dl-p'); if (lp) lp.innerHTML = T.ship ? amt(T.ship) : lp.innerHTML; }
  const gift = $1(root, '#is_gift'); if (gift) { gift.dataset.act = 'gift'; if (S.co.gift) gift.setAttribute('checked', ''); }
  const gw = $1(root, '.kbb-gift-msg'); if (gw) gw.hidden = !S.co.gift;
  /* 3 · Payment: Apple Pay / Google Pay first (the site's Apple Pay row), then card, Tabby, Tamara, cash */
  const ul = $1(root, '.wc_payment_methods');
  if (ul) {
    const lis = [...ul.children];
    ul.prepend(walletRow(lis.find(l => l.classList.contains('payment_method_tabby')) || lis[0]));
    const key = v => v === 'stripe' ? 'card' : v;
    [...ul.children].forEach(li => {
      const inp = $1(li, 'input.input-radio'); const k = key((li.className.match(/payment_method_(\w+)/) || [])[1]);
      inp.value = k; inp.id = 'payment_method_' + k; inp.dataset.act = 'pay'; inp.dataset.v = k; $1(li, 'label').setAttribute('for', inp.id);
      if (S.co.pay === k) inp.setAttribute('checked', ''); else inp.removeAttribute('checked');
      li.classList.toggle('on', S.co.pay === k);
    });
    /* the card's three boxes (Stripe's, on the site): the app's own fields in them */
    const cardMap = {number:['number', 'cc-number', '1234 1234 1234 1234', 23], expiry:['exp', 'cc-exp', t('MM / YY'), 7], cvc:['cvc', 'cc-csc', 'CVC', 4]};
    each(root, '.kbb-card-el', el => {
      const [k, ac, ph, max] = cardMap[el.dataset.kbbCardEl] || [];
      if (!k) return;
      const err = ferrMsg('card', k), id = `f-card-${k}`;
      el.dataset.fld = `card.${k}`; el.classList.toggle('err', !!err);
      el.innerHTML = `<input class="kbb-card-in" id="${id}" type="text" dir="ltr" inputmode="numeric" autocomplete="${ac}" placeholder="${esc(ph)}" maxlength="${max}" value="${esc(fval('card', k))}" data-form="card" data-k="${k}" aria-describedby="${id}-m"${err ? ' aria-invalid="true"' : ''}${AR ? ' data-x="code"' : ''}>${k === 'number' ? `<span class="cbrw" id="cbrand">${brandChip(cardBrand(fval('card', 'number')))}</span>` : ''}`;
      const lb = el.parentElement.querySelector('.kbb-card-lb'); if (lb) { lb.setAttribute('id', id + '-l'); }
      const m = document.createElement('span'); m.className = 'fl-m'; m.id = id + '-m'; m.setAttribute('aria-live', 'polite'); if (err) m.innerHTML = ico('alert') + `<span>${err}</span>`; el.after(m);
    });
    each(root, '.kbb-card-bail, .kbb-card-err', e => e.remove());
  }
  /* the summary bar on top, and the order box at the foot */
  const aside = $1(root, 'aside.summary'); if (aside) aside.classList.toggle('cosr-open', !!S.co.sumOpen);
  const row = $1(root, '#kbbSumRow, .cosr'); if (row) { row.dataset.act = 'cosum'; row.setAttribute('aria-expanded', String(!!S.co.sumOpen)); }
  coSummaryItems(root);
  const cin = $1(root, '#kbb_coupon_code, input[name="coupon_code"]');
  if (cin) {
    const err = ferrMsg('coupon', 'code'), id = 'f-coupon-code';
    cin.id = id; cin.dataset.form = 'coupon'; cin.dataset.k = 'code'; cin.setAttribute('value', fval('coupon', 'code')); cin.setAttribute('autocapitalize', 'characters'); cin.setAttribute('enterkeyhint', 'go');
    const lab = cin.parentElement.querySelector('label'); if (lab) lab.setAttribute('for', id);
    const w = cin.closest('.fld'); w.dataset.fld = 'coupon.code'; w.classList.toggle('err', !!err);
    const m = document.createElement('span'); m.className = 'fl-m'; m.id = id + '-m'; m.setAttribute('aria-live', 'polite'); if (err) m.innerHTML = ico('alert') + `<span>${err}</span>`;
    (cin.closest('.crow') || w).after(m);
    const ap = cin.closest('.coupon').querySelector('.apply'); if (ap) { ap.dataset.act = 'coupon'; ap.removeAttribute('id'); }
    if (S.coupon) cin.closest('.coupon').hidden = true;
  }
  const box = $1(root, '.kbb-mobile-order');
  if (box) {
    box.classList.toggle('co-open', !!S.co.footOpen);
    each(box, '.co-ta, .co-osh', b => { b.dataset.act = 'cofoot'; b.setAttribute('aria-expanded', String(!!S.co.footOpen)); });
    if (S.co.footOpen) { const host = $1(box, '.co-ositems'), src = $1(root, '.co-items'); if (host && src) { const c = src.cloneNode(true); host.replaceChildren(c); } }
  }
  each(root, '.co-ta', b => { b.dataset.act = b.closest('.kbb-mobile-order') ? 'cofoot' : 'cosum'; });
  coTotals(root, T);
  each(root, '[data-place]', b => { b.dataset.act = 'place'; b.textContent = S.co.pay === 'cod' ? SCO.words.cod : SCO.words.pay; b.removeAttribute('data-place'); });
  each(root, '.viewfull', b => { b.dataset.act = 'cosum'; });
  each(root, '.sf-top', b => { b.dataset.act = 'totop'; });
  each(root, 'form', f => { f.setAttribute('novalidate', ''); f.removeAttribute('action'); f.removeAttribute('method'); f.removeAttribute('id'); });
  const headH = head.outerHTML.replace(/^<header/, '<div').replace(/<\/header>$/, '</div>').replace('class="co-head"', 'class="kh-hd co-head"');
  const hdRoot = document.createElement('div'); hdRoot.innerHTML = headH; retarget(hdRoot);
  const cart = $1(hdRoot, '.co-cart'); if (cart) { cart.dataset.act = 'back'; delete cart.dataset.go; }
  return {head:`<div class="kc kc-head" dir="${AR ? 'rtl' : 'ltr'}" lang="${LANG}"><section class="${root.className}">${hdRoot.innerHTML}</section></div>`, body:`<div class="kc" dir="${AR ? 'rtl' : 'ltr'}" lang="${LANG}">${root.outerHTML}</div>`};
}
const coEmptyGuard = ctx => !S.bag.length ? {title:t('Checkout'), back:true, noTabs:true, body:empty('bag', t('Your bag is empty'), t('Add something you love, then check out.'), t('Start shopping'), '/categories')} : null;
scr('/checkout', {tab:'bag', group:'Bag & Checkout', label:'Checkout (one page)', chain:() => ['/bag'], title:() => t('Checkout'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    coPrefill(r);
    const H = checkoutHTML(r, ctx);
    return {head:H.head, body:H.body, noTabs:true, sk:'co'};
  }});
/* order summary for the after-tap screens (3-D Secure, approvals) */
function coSummary(){
  const T = bagTotals();
  return `<div class="cosum"><h2 class="h3">${t('Order summary')}</h2><div class="cothumbs">${S.bag.map(b => P[b.slug] ? `<span class="cth"><img src="${P[b.slug].img200}" alt="">${b.qty > 1 ? `<b>${num(b.qty)}</b>` : ''}</span>` : '').join('')}</div><div class="tot"><div class="grand"><span>${t('Total')}</span><span>${money(T.total)}</span></div></div></div>`;
}
const afterTap = (title, inner, foot) => ({title, back:true, noTabs:true, body:inner, foot, line:true});

/* ── after the tap: 3-D Secure (card) ──────────────────────────────────── */
FORMS.otp = () => [{k:'code', v:'req'}];
scr('/checkout/3ds', {tab:'bag', group:'Bag & Checkout', label:'After Pay now · card 3-D Secure', chain:() => ['/bag', '/checkout'], title:() => t('Verify your payment'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    const T = bagTotals();
    S.co.pay = 'card';
    if (S.formFor.otp !== r.path) { S.formFor.otp = r.path; S.form.otp = {code:r.query.filled ? '481 205' : ''}; S.ferr.otp = {}; }
    const inner = `<div class="tds"><div class="tdsh">${X('bank', '<b>Emirates NBD</b>')}${X('pay', '<span class="vsec">VISA <i>Secure</i></span>')}</div>
      <div class="pad"><h2 class="h2">${t('Confirm it’s you')}</h2><p>${t('Enter the 6-digit code we sent to {p} to pay {v} to K-Beauty Bliss.', {p:LTR('+971 50 ••• 4567'), v:`<b>${money(T.total)}</b>`})}</p>
      <div class="tdsrow"><span>${t('Merchant')}</span><b>${brandWord()}</b></div><div class="tdsrow"><span>${t('Amount')}</span><b>${money(T.total)}</b></div><div class="tdsrow"><span>${t('Card')}</span><b>${LTR(VISA + ' •••• ' + (fval('card', 'number').replace(/\D/g, '').slice(-4) || '4242'))}</b></div>
      <div style="height:14px"></div>${fld({fid:'otp', k:'code', label:t('One-time code'), ac:'one-time-code', im:'numeric', ekh:'done', ltr:true, hint:'123 456', max:7})}
      <button class="lnk sm" data-act="toast" data-v="A new code is on its way.">${t('Resend code')}</button>
      <p class="tiny muted">${t('Your bank’s page, shown inside the app by Stripe. You never leave K-Beauty Bliss.')}</p></div></div>`;
    return afterTap(t('Verify your payment'), inner, `<button class="btn block tdsb" data-act="otp-submit">${t('Submit')}</button><button class="btn block ghost noborder" style="margin-top:6px" data-act="decline" data-v="card">${t('Simulate a declined card')}</button>`);
  }});
/* ── after the tap: the Apple Pay / Google Pay sheet, over the checkout ── */
function walletSheet(){
  const T = bagTotals(), f = S.form.co || {};
  const shipTo = `<b>${utH(f.name || SARA.name)}</b><span class="small">${[f.building, f.street, emirateName(f.emirate)].filter(Boolean).map(utH).join(AR ? '، ' : ', ')}</span>`;
  if (isA()) return `<div class="sheet gp" role="dialog" aria-label="${t('Google Pay')}"><div class="grab"></div><div class="sh2"><div></div><b>${t('Google Pay')}</b><div class="e"><button class="ib" data-act="back" aria-label="${t('Close')}">${ico('close')}</button></div></div><div class="sb2 ap"><div class="apr"><span>${t('Pay to')}</span><span class="grow"><b>${brandWord()}</b></span></div><div class="apr"><span>${t('Card')}</span><span class="grow"><b>${LTR(VISA + ' •••• 4242')}</b></span>${ico('chevR', 'chev flip')}</div><div class="apr"><span>${t('Ship to')}</span><span class="grow">${shipTo}</span>${ico('chevR', 'chev flip')}</div><div class="total"><span>${t('Total')}</span><b>${money(T.total)}</b></div><button class="btn block dark gpb" data-act="authorise" data-v="gpay" data-hap="success">${t('Continue')}</button><p class="tiny" style="text-align:center;color:#5f6368">${t('Then confirm with your fingerprint or screen lock.')}</p><button class="lnk sm dcl" data-act="decline" data-v="gpay">${t('Simulate a declined card')}</button></div></div>`;
  return `<div class="sheet sheetpay" role="dialog" aria-label="${t('Apple Pay')}"><div class="grab"></div><div class="sh2"><div><span class="paybtn mini" data-x="pay">${APPLE}Pay</span></div><b></b><div class="e"><button class="ib" data-act="back" aria-label="${t('Cancel')}">${ico('close')}</button></div></div><div class="sb2 ap"><div class="apr"><span>${t('Card')}</span><span class="grow"><b>${LTR(VISA + ' •••• 4242')}</b><span class="small">${X('bank', 'Emirates NBD')}</span></span>${ico('chevR', 'chev flip')}</div><div class="apr"><span>${t('Ship to')}</span><span class="grow">${shipTo}</span>${ico('chevR', 'chev flip')}</div><div class="apr"><span>${t('Contact')}</span><span class="grow"><b>${X('email', esc(f.email || SARA.email))}</b></span>${ico('chevR', 'chev flip')}</div><div class="total"><span>${t('Pay K-Beauty Bliss')}</span><b>${money(T.total)}</b></div><button class="side" data-act="authorise" data-v="apple" data-hap="success">${ico('faceid')}<span>${t('Confirm with Side Button')}</span></button><button class="lnk sm dcl" data-act="decline" data-v="apple">${t('Simulate a declined card')}</button></div></div>`;
}
scr('/checkout/wallet', {tab:'bag', group:'Bag & Checkout', label:'After Pay now · Apple Pay / Google Pay sheet', chain:() => ['/bag', '/checkout'], title:() => isA() ? t('Google Pay') : t('Apple Pay'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    S.co.pay = isA() ? 'gpay' : 'apple';
    if (!S.form.co || !S.form.co.email) S.form.co = SAMPLE_CO();
    const H = checkoutHTML(r, ctx);
    return {head:H.head, body:H.body, noTabs:true, back:false, overlay:`<div class="ovl inpane" data-layer="sheet"><div class="scrim" data-act="back"></div>${walletSheet()}</div>`};
  }});
/* ── after the tap: Tabby / Tamara approve inside the app ──────────────── */
function bnplPage(k){
  const T = bagTotals(), v = inst(T.total);
  const when = [t('Today'), t('In 1 month'), t('In 2 months'), t('In 3 months')];
  const nm = k === 'tabby' ? '<span class="tabby big" data-x="pay">tabby</span>' : '<span class="tamara big" data-x="pay">tamara</span>';
  return `<div class="bnplp bp-${k}"><div class="bnh">${nm}<span class="tiny">${ico('lock')}${X('pay', k === 'tabby' ? 'checkout.tabby.ai' : 'checkout.tamara.co')}</span></div><div class="pad">
    <h2 class="h2">${k === 'tabby' ? t('Pay in 4. No interest, no fees.') : t('Split in 4 payments')}</h2><p class="muted" style="margin:0">${brandWord()} · ${money(T.total)}</p>
    <div class="sched">${when.map((w, i) => `<div class="${i ? '' : 'now'}"><i></i><b>${money(v)}</b><span>${w}</span></div>`).join('')}</div>
    ${fld({fid:'bnpl', k:'phone', label:t('Mobile number'), type:'tel', ac:'tel-national', im:'tel', icon:'phone', pre:'+971', ltr:true, hint:'50 123 4567', max:12})}
    <p class="tiny muted">${k === 'tabby' ? t('Tabby checks your eligibility instantly. No impact on your credit score.') : t('Tamara is Sharia-compliant. No late fees, ever.')}</p>
    <p class="tiny muted">${t('{p}’s own approval screen, opened inside our app by their SDK.', {p:k === 'tabby' ? t('Tabby') : t('Tamara')})}</p></div></div>`;
}
FORMS.bnpl = () => [{k:'phone', v:'phone'}];
for (const k of ['tabby', 'tamara']) scr('/checkout/' + k, {tab:'bag', group:'Bag & Checkout', label:k === 'tabby' ? 'After Pay now · Tabby approval' : 'After Pay now · Tamara approval', chain:() => ['/bag', '/checkout'], title:() => k === 'tabby' ? t('Tabby') : t('Tamara'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    S.co.pay = k;
    if (S.formFor.bnpl !== r.path) { S.formFor.bnpl = r.path; S.form.bnpl = {phone:(S.form.co && S.form.co.phone) || fmtPhone(SARA.phone)}; S.ferr.bnpl = {}; }
    return afterTap(k === 'tabby' ? t('Tabby') : t('Tamara'), bnplPage(k), `<button class="btn block ${k}b" data-act="bnpl-ok" data-v="${k}">${t('Confirm and continue')}</button><button class="btn block ghost noborder" style="margin-top:6px" data-act="decline" data-v="${k}">${t('Simulate a declined application')}</button>`);
  }});

/* ── the order placed ──────────────────────────────────────────────────── */
function arrival(days){ const d = new Date('2026-10-19T12:00:00'); let n = 0; while (n < days) { d.setDate(d.getDate() + 1); if (d.getDay() !== 5) n++; } return date(d.toISOString().slice(0, 10), {weekday:'short', day:'numeric', month:'short'}); }
scr('/checkout/placed', {tab:'bag', group:'Bag & Checkout', label:'After Pay now · order placed', chain:() => [], title:() => t('Order placed'),
  render(r, ctx){
    let o = S.orders.find(x => x.id === S.lastOrder);
    if (!o) { o = {...S.orders[0], id:'KBB-56215', st:'proc', items:S.bag.length ? S.bag.slice() : S.orders[0].items, total:S.bag.length ? bagTotals().total : S.orders[0].total, pay:S.co.pay, addr:S.co.addr}; }
    const f = o.ship || S.form.co || {};
    const where = f.building ? [f.building, f.street, emirateName(f.emirate)].filter(Boolean).map(utH).join(AR ? '، ' : ', ') : addrLine(coAddress(), {short:true});
    const first = S.user ? ut(S.user.first) : (f.name || '').split(' ')[0];
    return {nobar:true, noTabs:true, bare:true, body:`<div class="state placed"><div class="done-mark">${LOTUS.replace('width="35" height="26"', 'width="56" height="42"').replace('<svg', '<svg class="lgx" style="width:56px;height:42px"')}</div><h2>${first ? t('Thank you, {n}', {n:utH(first)}) : t('Thank you')}</h2><p>${t('Order {id} is confirmed. We’ve emailed your receipt.', {id:X('code', `<b>#${o.id}</b>`)})}</p>
      <div class="cardbox plc"><div class="row2">${ico('truck')}<span class="grow"><b>${t('Arrives by {d}', {d:arrival(3)})}</b><span class="small muted" style="display:block">${where}</span></span></div><div class="hr"></div><div class="cothumbs">${o.items.map(b => P[b.slug] ? `<span class="cth"><img src="${P[b.slug].img200}" alt="">${b.qty > 1 ? `<b>${num(b.qty)}</b>` : ''}</span>` : '').join('')}</div><div class="tot"><div><span>${t('Payment')}</span><span>${o.pay === 'card' ? LTR(VISA + ' •••• 4242') : methodTxt(o.pay)}</span></div><div class="grand"><span>${o.pay === 'cod' ? t('To pay on delivery') : t('Paid')}</span><span>${money(o.total)}</span></div></div></div>
      <button class="btn plb" data-act="track" data-v="${o.id}">${ico('truck')}${t('Track order')}</button><button class="btn ghost plb" ${go$('/home')} data-tabroot="home">${t('Continue shopping')}</button></div>`};
  }});

/* ── payment failed → try again, the bag is kept ───────────────────────── */
const FAIL = {card:['Your bank declined the card. Nothing was charged.', 'card_declined · insufficient_funds'], apple:['The payment was cancelled in Apple Pay. Nothing was charged.', 'payment_intent_authentication_failure'], gpay:['Google Pay couldn’t complete the payment. Nothing was charged.', 'payment_intent_authentication_failure'], tabby:['Tabby couldn’t approve this order. Nothing was charged.', 'tabby: rejected · order_amount_too_high'], tamara:['Tamara couldn’t approve this order. Nothing was charged.', 'tamara: declined · risk_assessment']};
scr('/checkout/failed', {tab:'bag', group:'Bag & Checkout', label:'After Pay now · payment failed → retry', chain:() => ['/bag', '/checkout'], title:() => t('Payment'),
  render(r, ctx){
    const m = FAIL[r.query.m] ? r.query.m : FAIL[S.co.failed] ? S.co.failed : 'card';
    const T = bagTotals();
    const inner = `<div class="state" style="min-height:0;padding-top:24px"><div class="ic errc">${ico('alert')}</div><h2>${t('Payment didn’t go through')}</h2><p>${t(FAIL[m][0])}</p><p class="tiny">${X('code', FAIL[m][1])}</p></div>
      <div class="pad"><div class="note ok">${ico('bag')}<span>${t('Your bag is safe: {n} items, {v}. Try again or choose another way to pay.', {n:num(bagCount()), v:money(T.total)})}</span></div>
      <div class="cothumbs" style="margin-top:12px">${S.bag.map(b => P[b.slug] ? `<span class="cth"><img src="${P[b.slug].img200}" alt="">${b.qty > 1 ? `<b>${num(b.qty)}</b>` : ''}</span>` : '').join('')}</div></div>`;
    return afterTap(t('Payment'), inner, `<button class="btn block" data-act="retry-pay" data-v="${m}">${ico('refresh')}${t('Try again')}</button><button class="btn block ghost" style="margin-top:8px" data-act="retry-pay" data-v="other">${t('Choose another method')}</button>`);
  }});

/* ── the account's address book (Account → Addresses), unchanged ───────── */
FORMS.addr = () => [
  {k:'name', v:'name'}, {k:'phone', v:'phone'}, {k:'emirate', pick:true}, {k:'area', pick:true}, {k:'building'}, {k:'street'}, {k:'flat', opt:true}, {k:'landmark', opt:true},
];
function addrForm(fid){
  const f = S.form[fid] || {};
  const n = Object.keys(S.ferr[fid] || {}).length;
  const em = f.emirate ? emirateName(f.emirate) : '', ar2 = f.area ? areaName(f.emirate, f.area) : '';
  return `${n ? `<div class="note err errsum" role="alert">${ico('alert')}<span>${t(n === 1 ? 'Please check 1 field below.' : 'Please check {n} fields below.', {n:num(n)})}</span></div>` : ''}
    <div class="fgrid">
    ${fld({fid, k:'name', label:t('Full name'), ac:'name', ekh:'next', icon:'user', cap:'words'})}
    ${fld({fid, k:'phone', label:t('Mobile number'), type:'tel', ac:'tel-national', im:'tel', ekh:'next', icon:'phone', pre:'+971', ltr:true, hint:'50 123 4567', max:12})}
    ${pickFld({fid, k:'emirate', label:t('Emirate'), sheet:'emirate', value:esc(em), icon:'map'})}
    ${pickFld({fid, k:'area', label:t('Area'), sheet:f.emirate ? 'area' : 'emirate-first', value:esc(ar2), icon:'pin'})}
    ${fld({fid, k:'building', label:t('Building or villa'), ac:'address-line1', ekh:'next', icon:'building'})}
    ${fld({fid, k:'street', label:t('Street'), ac:'address-line2', ekh:'next'})}
    ${fld({fid, k:'flat', label:t('Apartment, floor'), ac:'address-line3', ekh:'next', opt:true})}
    ${fld({fid, k:'landmark', label:t('Nearest landmark'), ekh:'done', opt:true, hint:t('e.g. next to the mosque')})}
    </div>
    <span class="lab">${t('Address label')}</span><div class="chips wrap" role="radiogroup">${['Home', 'Work', 'Other'].map(l => `<button class="chip${(f.label || 'Home') === l ? ' on' : ''}" role="radio" aria-checked="${(f.label || 'Home') === l}" data-act="flabel" data-k="${fid}" data-v="${l}">${ico(l === 'Home' ? 'home' : l === 'Work' ? 'building' : 'pin')}${alabel(l)}</button>`).join('')}</div>
    <div style="height:8px"></div>${cbxRow('fdef', fid, f.def !== false, t('Set as my default address'))}`;
}
function prefillAddr(fid, a){
  S.form[fid] = {name:ut(a.name), phone:fmtPhone(a.phone), emirate:a.emirate, area:a.area, building:ut(a.building), street:ut(a.street), flat:ut(a.flat), landmark:ut(a.landmark), label:a.label, def:a.def, edit:a.id};
}
