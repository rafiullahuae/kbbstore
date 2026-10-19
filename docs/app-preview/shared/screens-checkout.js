'use strict';
/* =========================== BAG & CHECKOUT ============================== */
const VISA = X('pay', 'Visa');
const METHOD_ROUTE = {apple:'/checkout/wallet', gpay:'/checkout/wallet', card:'/checkout/card', tabby:'/checkout/tabby', tamara:'/checkout/tamara', cod:'/checkout/cod'};
const methodName = k => ({apple:t('Apple Pay'), gpay:t('Google Pay'), card:t('Credit or debit card'), tabby:t('Tabby · 4 payments'), tamara:t('Tamara · 4 payments'), cod:t('Cash on delivery')}[k]);
const methodTxt = k => methodName(k);
const CO_CHAIN = ['/checkout/bag', '/checkout/contact', '/checkout/address', '/checkout/delivery', '/checkout/payment'];
const chainTo = p => CO_CHAIN.slice(0, CO_CHAIN.indexOf(p));
const coBar = (n, title, sub) => ({title, back:true, noTabs:true, extra:steps(n, sub), line:true});
const FREE_LINE = T => { const left = Math.max(0, r2(T.free - (T.sub - T.disc))); return left ? t('Add {v} more for free delivery', {v:`<b>${money(left)}</b>`}) : `<b class="okc">${t('You’ve got free delivery')}</b>`; };

function fdb(T){
  const p = Math.min(100, Math.round((T.sub - T.disc) / T.free * 100));
  return `<div class="fdb"><div class="fdt">${ico('truck')}<span class="grow">${FREE_LINE(T)}</span></div><div class="bar2" role="progressbar" aria-label="${t('Free delivery progress')}" aria-valuenow="${p}" aria-valuemin="0" aria-valuemax="100"><i data-anim-w="fdb" style="width:${p}%"></i></div></div>`;
}
function bagLine(b, o = {}){
  const p = P[b.slug]; if (!p) return '';
  return `<div class="line"><button ${go$('/product/' + p.slug)} aria-label="${esc(pn(p))}" class="lim"><img src="${p.img200}" alt=""></button><div style="min-width:0"><div class="nr"><div style="min-width:0"><div class="br ell">${brandTxt(bn(p))}</div><div class="nm clamp2">${nameTxt(p)}</div></div>${o.noRemove ? '' : `<button class="ib" data-act="rm" data-p="${p.slug}" aria-label="${t('Remove')}">${ico('trash')}</button>`}</div>
    <div class="pq"><span><span class="price">${money(cur(p) * b.qty)}</span>${wasOf(p) ? ` <span class="was">${money(wasOf(p) * b.qty)}</span>` : ''}</span>${qtyStep('bq', p.slug, b.qty)}</div></div></div>`;
}
function couponBox(){
  if (S.coupon) return `<div class="note ok cpn-on">${ico('tag')}<span class="grow">${t('{c} applied · 10% off', {c:X('code', `<b>${S.coupon}</b>`)})}</span><button class="lnk sm" data-act="uncoupon">${t('Remove')}</button></div>`;
  return `<div class="cpn">${fld({fid:'coupon', k:'code', label:t('Discount code'), icon:'tag', cap:'characters', ekh:'go', ac:'off', opt:true, quiet:true})}<button class="btn dark" data-act="coupon">${t('Apply')}</button></div>`;
}
function totals(T, o = {}){
  return `<div class="tot"><div><span>${t('Subtotal')} · ${t('{n} items', {n:num(bagCount())})}</span><span>${money(T.sub)}</span></div>${T.disc ? `<div class="okc"><span>${t('Discount')} ${X('code', 'WELCOME10')}</span><span>−${money(T.disc)}</span></div>` : ''}<div><span>${o.ship === false ? t('Delivery') : T.gcc ? t('Delivery (GCC)') : t('Delivery (UAE)')}</span><span>${T.ship ? money(T.ship) : t('Free')}</span></div>${T.cod ? `<div><span>${t('Cash on delivery fee')}</span><span>${money(T.cod)}</span></div>` : ''}<div class="grand"><span>${t('Total')}</span><span>${money(T.total)}</span></div><div class="tiny muted vatl">${t('Includes VAT {v}', {v:money(T.vat)})}</div></div>`;
}
function bagSummary(T){
  return `<div class="bsum">${couponBox()}${S.coupon ? '' : `<p class="tiny muted promo">${ico('gift')}${t('First order? Use {c} for 10% off.', {c:X('code', '<b>WELCOME10</b>')})}</p>`}${totals(T)}
    <button class="bnpl" data-sheet="bnpl"><span class="lg"><span class="tabby" data-x="pay">tabby</span></span><span class="grow">${t('Or 4 interest-free payments of {v}', {v:`<b>${money(inst(T.total))}</b>`})}</span>${ico('chevR', 'flip')}</button></div>`;
}
scr('/bag', {tab:'bag', group:'Bag & Checkout', label:'Bag', title:() => t('Bag'),
  render(r, ctx){
    const st = r.query.state;
    if (st === 'loading') return {title:t('Bag'), large:true, body:`<div class="pad">${skel(64, '100%', 14)}<div style="height:16px"></div>${[1, 2].map(() => `<div style="display:flex;gap:12px;margin-bottom:16px">${skel(76, '76px', 12)}<div style="flex:1">${skel(12, '40%')}<div style="height:8px"></div>${skel(16, '90%')}<div style="height:8px"></div>${skel(30, '60%')}</div></div>`).join('')}</div>`};
    if (!S.bag.length || st === 'empty') return {title:t('Bag'), large:true, body:empty('bag', t('Your bag is empty'), t('Korean skincare, delivered across the UAE. Free delivery over {v}.', {v:money(SET.free_over_uae)}), t('Start shopping'), '/categories')};
    const T = bagTotals({noCod:true});
    const ups = PL.filter(p => p.tags.includes('under54') && !S.bag.some(b => b.slug === p.slug)).slice(0, 8);
    const lines = `${fdb(T)}<div class="lines">${S.bag.map(b => bagLine(b)).join('')}</div>`;
    const foot = `<button class="btn block" ${go$('/checkout/bag')}>${ico('lock')}${t('Checkout')} · ${money(T.total)}</button>`;
    if (ctx.half === 'start') return {title:t('Bag'), large:true, body:`<div class="pad" style="padding-top:4px">${lines}</div>${sec(t('Complete your routine'), rail(ups))}`, sk:'bag-s'};
    if (ctx.half === 'end') return {title:t('Order summary'), body:`<div class="pad">${bagSummary(T)}</div>`, foot, sk:'bag-e'};
    return {title:t('Bag'), large:true, body:`${st === 'offline' ? offbar() : ''}<div class="bagwrap"><div class="pad bagl" style="padding-top:4px">${lines}</div><aside class="pad bags">${bagSummary(T)}</aside></div>${sec(t('Complete your routine'), rail(ups))}`, foot, ptr:true};
  },
  dual:() => S.bag.length ? 'halves' : null});

/* order summary used beside every checkout step (expanded aside, Duo end pane, tabletop top) */
function coSummary(){
  const T = bagTotals();
  return `<div class="cosum"><h2 class="h3">${t('Order summary')}</h2><div class="cothumbs">${S.bag.map(b => P[b.slug] ? `<span class="cth"><img src="${P[b.slug].img200}" alt="">${b.qty > 1 ? `<b>${num(b.qty)}</b>` : ''}</span>` : '').join('')}</div>${totals(T)}<p class="tiny muted lockl">${ico('lock')}${t('Prices and stock are checked again when you place the order.')}</p></div>`;
}
function coPage(n, title, inner, foot, ctx, o = {}){
  if (ctx.half === 'end') return {title:t('Order summary'), body:`<div class="pad">${coSummary()}</div>`, noTabs:true, sk:'cos'};
  const body = `<div class="cowrap"><div class="comain">${inner}</div>${ctx.half ? '' : `<aside class="coaside">${coSummary()}</aside>`}</div>`;
  return {...coBar(n, title, o.sub), body, foot, sk:o.sk, tt:o.tt};
}
const coEmptyGuard = ctx => !S.bag.length ? {title:t('Checkout'), back:true, noTabs:true, body:empty('bag', t('Your bag is empty'), t('Add something you love, then check out.'), t('Start shopping'), '/categories')} : null;

/* 1 · Bag review */
scr('/checkout/bag', {tab:'bag', group:'Bag & Checkout', label:'1 · Bag review', chain:() => [], title:() => t('Review your bag'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    const T = bagTotals({noCod:true});
    const inner = `<div class="pad">${fdb(T)}<div class="lines">${S.bag.map(b => bagLine(b)).join('')}</div>${couponBox()}<div style="height:12px"></div>${totals(T, {ship:false})}</div>`;
    return coPage(0, t('Review your bag'), inner, `<button class="btn block" data-act="co-next" data-v="/checkout/contact">${t('Continue')}</button>`, ctx);
  }, dual:() => 'halves'});

/* 2 · Contact & sign-in choice */
FORMS.contact = () => [{k:'email', v:'email'}];
scr('/checkout/contact', {tab:'bag', group:'Bag & Checkout', label:'2 · Contact & sign-in', chain:() => chainTo('/checkout/contact'), title:() => t('Contact'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    if (r.query.guest === '1' && !S.co._g) { S.co.guest = true; S.co._g = true; }
    const signed = S.user && !S.co.guest;
    const inner = signed
      ? `<div class="pad"><div class="cardbox who"><span class="av sm">${initials(ut(S.user.name))}</span><span class="grow"><b>${utH(S.user.name)}</b><span class="small muted" style="display:block">${X('email', S.user.email)}</span></span><span class="tagp">${t('Signed in')}</span></div>
        <p class="small muted">${t('We’ll send your receipt and delivery updates to this email.')}</p>
        <button class="lnk" data-act="co-guest" data-v="1">${t('Check out as a guest instead')}</button></div>`
      : `<div class="pad"><div class="seg" role="tablist"><button role="tab" class="on" aria-selected="true">${t('Guest checkout')}</button><button role="tab" aria-selected="false" ${go$('/account/signin?next=checkout')}>${t('Sign in')}</button></div>
        <p class="small muted" style="margin:14px 0">${t('No account needed. We’ll email your receipt and delivery updates.')}</p>
        ${fld({fid:'contact', k:'email', label:t('Email'), type:'email', ac:'email', im:'email', ekh:'next', icon:'mail', ltr:true})}
        ${cbxRow('news', '', S.co.news, t('Email me offers and new arrivals (optional)'))}
        <div class="hr"></div><p class="small muted" style="margin:0 0 8px">${t('Have an account? Sign in to use your saved addresses.')}</p><button class="btn block ghost" ${go$('/account/signin?next=checkout')}>${ico('user')}${t('Sign in')}</button></div>`;
    return coPage(1, t('Contact'), inner, `<button class="btn block" data-act="co-contact">${t('Continue')}</button>`, ctx);
  }, dual:() => 'halves'});

/* 3 · Delivery address: saved list → new address form */
scr('/checkout/address', {tab:'bag', group:'Bag & Checkout', label:'3 · Delivery address (saved)', chain:() => chainTo('/checkout/address'), title:() => t('Delivery address'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    const L = S.co.addresses;
    const inner = `<div class="pad">${L.length ? L.map(a => `<div class="addr${S.co.addr === a.id ? ' on' : ''}"><button class="addr-pick" data-act="addr" data-v="${a.id}" role="radio" aria-checked="${S.co.addr === a.id}">${radio(S.co.addr === a.id)}<span class="grow"><b>${utH(a.name)} <span class="tagp">${alabel(a.label)}</span>${a.def ? ` <span class="tagp soft">${t('Default')}</span>` : ''}</b><small>${addrLine(a)}</small><small>${LTR('+971 ' + fmtPhone(a.phone))}</small></span></button><button class="ib" ${go$('/checkout/address/new?edit=' + a.id)} aria-label="${t('Edit')}">${ico('edit')}</button></div>`).join('') : `<div class="note">${ico('pin')}<span>${t('No saved addresses yet. Add one below.')}</span></div><div style="height:12px"></div>`}
      <button class="btn block ghost" ${go$('/checkout/address/new')}>${ico('plus')}${t('Add a new address')}</button>
      ${coAddress() && coAddress().country !== 'AE' ? `<div class="note warn" style="margin-top:14px">${ico('info')}<span>${t('Delivering outside the UAE: {v}, free over {f}. Cash on delivery is UAE only.', {v:money(SET.ship_gcc), f:money(SET.free_over_gcc)})}</span></div>` : ''}</div>`;
    return coPage(2, t('Delivery address'), inner, `<button class="btn block" data-act="co-next" data-v="/checkout/delivery"${L.length ? '' : ' disabled'}>${t('Deliver here')}</button>`, ctx);
  }, dual:() => 'halves'});

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
const SAMPLE_NEW = () => ({name:ut('Sara Al Mansoori'), phone:'55 987 6543', emirate:'sharjah', area:'al-majaz', building:ut('Al Majaz Pearl Tower'), street:ut('Jamal Abdul Nasser Street'), flat:ut('Flat 905, 9th floor'), landmark:'', label:'Home', def:false});
scr('/checkout/address/new', {tab:'bag', group:'Bag & Checkout', label:'3b · New address form', chain:() => chainTo('/checkout/address').concat(['/checkout/address']), title:() => t('New address'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    const key = r.path;
    if (S.formFor.addr !== key) {
      S.formFor.addr = key; S.ferr.addr = {};
      const ed = r.query.edit && S.co.addresses.find(a => a.id === r.query.edit);
      if (ed) prefillAddr('addr', ed); else S.form.addr = r.query.filled ? SAMPLE_NEW() : {label:'Home', def:!S.co.addresses.length};
      if (r.query.errors) { S.form.addr = {label:'Home', name:ut('Sara'), phone:'50 123 45'}; validate('addr'); }
    }
    const edit = r.query.edit;
    return coPage(2, edit ? t('Edit address') : t('New address'), `<div class="pad">${addrForm('addr')}</div>`, `<button class="btn block" data-act="addr-save" data-v="checkout">${t('Save and continue')}</button>`, ctx, {sk:'addr-' + (edit || 'new')});
  }, dual:() => 'halves'});

/* 4 · Delivery method */
function arrival(days){ const d = new Date('2026-10-19T12:00:00'); let n = 0; while (n < days) { d.setDate(d.getDate() + 1); if (d.getDay() !== 5) n++; } return date(d.toISOString().slice(0, 10), {weekday:'short', day:'numeric', month:'short'}); }
scr('/checkout/delivery', {tab:'bag', group:'Bag & Checkout', label:'4 · Delivery method', chain:() => chainTo('/checkout/delivery'), title:() => t('Delivery'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    const T = bagTotals(), a = coAddress(), gcc = a && a.country !== 'AE';
    const opts = gcc ? [['gcc', t('GCC delivery'), t('3–6 working days · arrives by {d}', {d:arrival(6)}), T.sub - T.disc >= SET.free_over_gcc ? t('Free') : money(SET.ship_gcc)]]
      : [['std', t('Standard delivery'), t('1–3 working days · arrives by {d}', {d:arrival(3)}), T.sub - T.disc >= SET.free_over_uae ? t('Free') : money(SET.ship_uae)], ['exp', t('Express'), t('Next working day in Dubai, Sharjah and Ajman · {d}', {d:arrival(1)}), money(30)]];
    if (gcc) S.co.del = 'gcc'; else if (S.co.del === 'gcc') S.co.del = 'std';
    const inner = `<div class="pad"><div class="cardbox dto">${ico('pin')}<span class="grow"><b>${t('Delivering to {l}', {l:alabel(a.label)})}</b><span class="small muted" style="display:block">${addrLine(a, {short:true})}</span></span><button class="lnk sm" data-act="back">${t('Change')}</button></div><div style="height:14px"></div>
      <div role="radiogroup" aria-label="${t('Delivery method')}">${opts.map(([k, l, s, pr]) => `<button class="opt${S.co.del === k ? ' on' : ''}" data-act="del" data-v="${k}" role="radio" aria-checked="${S.co.del === k}">${radio(S.co.del === k)}<span class="grow"><b>${l}</b><small>${s}</small></span><b class="nowrap">${pr}</b></button>`).join('')}</div>
      <div style="height:6px"></div>${toggleRow('gift', '', S.co.gift, t('Add a gift message'), t('Printed on a card, no prices on the slip'), 'gift')}
      ${S.co.gift ? `<div style="height:12px"></div>${fld({fid:'gift', k:'msg', label:t('Gift message'), area:true, opt:true, max:200})}` : ''}</div>`;
    return coPage(3, t('Delivery'), inner, `<button class="btn block" data-act="co-next" data-v="/checkout/payment">${t('Continue to payment')}</button>`, ctx);
  }, dual:() => 'halves'});

/* 5 · Payment method list */
function payList(){
  const T = bagTotals({noCod:true}), a = coAddress(), uae = !a || a.country === 'AE';
  const wallet = isA() ? 'gpay' : 'apple';
  const M = [
    [wallet, isA() ? `<span class="paybtn gpay mini" data-x="pay"><span class="g">G</span>&nbsp;Pay</span>` : `<span class="paybtn mini" data-x="pay">${APPLE}Pay</span>`, isA() ? t('Google Pay') : t('Apple Pay'), isA() ? t('Fastest. Confirm with your fingerprint or screen lock.') : t('Fastest. Confirm with Face ID.')],
    ['card', `<span class="cardlogos">${brandChip('visa')}${brandChip('mc')}${brandChip('amex')}</span>`, t('Credit or debit card'), t('Visa, Mastercard, Amex. Verified by your bank in the app.')],
    ['tabby', '<span class="tabby" data-x="pay">tabby</span>', t('Pay in 4 with Tabby'), t('4 × {v} · No interest, no fees. Pay the first today.', {v:money(inst(T.total))})],
    ['tamara', '<span class="tamara" data-x="pay">tamara</span>', t('Split in 4 with Tamara'), t('4 × {v} · Sharia-compliant, no late fees.', {v:money(inst(T.total))})],
    ['cod', `<span class="codi">${ico('cash')}</span>`, t('Cash on delivery'), uae ? t('UAE only · +{v} fee, paid to the courier.', {v:money(SET.cod_fee)}) : t('Not available outside the UAE.')],
  ];
  if (!uae && S.co.pay === 'cod') S.co.pay = wallet;
  if ((S.co.pay === 'apple' || S.co.pay === 'gpay') && S.co.pay !== wallet) S.co.pay = wallet;
  return `<div role="radiogroup" aria-label="${t('Payment method')}">${M.map(([k, lg, l, s]) => { const off = k === 'cod' && !uae; return `<button class="opt pm${S.co.pay === k ? ' on' : ''}${off ? ' off' : ''}" data-act="pay" data-v="${k}" role="radio" aria-checked="${S.co.pay === k}"${off ? ' aria-disabled="true"' : ''}>${radio(S.co.pay === k)}<span class="grow"><b>${l}</b><small>${s}</small></span><span class="pmlogo">${lg}</span></button>`; }).join('')}</div>`;
}
const payFoot = () => `<button class="btn block" data-act="co-pay">${t('Continue')}</button>`;
scr('/checkout/payment', {tab:'bag', group:'Bag & Checkout', label:'5 · Payment method', chain:() => chainTo('/checkout/payment'), title:() => t('Payment'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    const inner = `<div class="pad">${payList()}<div class="note">${ico('shield')}<span>${t('Payments are processed by Stripe, Tabby and Tamara. We never see or store your card number.')}</span></div></div>`;
    if (ctx.tabletop) return {...coBar(4, t('Payment')), body:`<div class="pad">${coSummary()}</div>`, tt:`<div class="pad">${payList()}</div>`, foot:payFoot()};
    return coPage(4, t('Payment'), inner, payFoot(), ctx);
  }, dual:() => 'halves'});

/* 6 · Per-method steps */
FORMS.card = () => [{k:'number', v:'card'}, {k:'exp', v:'exp'}, {k:'cvc', v:'cvc'}, {k:'cname', v:'name'}];
scr('/checkout/card', {tab:'bag', group:'Bag & Checkout', label:'6a · Card details', chain:() => CO_CHAIN, title:() => t('Card details'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    S.co.pay = 'card';
    if (S.formFor.card !== r.path) { S.formFor.card = r.path; S.ferr.card = {}; if (r.query.filled) S.form.card = {number:'4000 0027 6000 3184', exp:'08 / 29', cvc:'123', cname:'SARA AL MANSOORI'}; else if (!S.form.card) S.form.card = {}; if (r.query.errors) { S.form.card = {number:'4000 0027 6000 318', exp:'01 / 24', cvc:'', cname:''}; validate('card'); } }
    const b = cardBrand(fval('card', 'number'));
    const inner = `<div class="pad"><div class="cardhd">${ico('card')}<b>${t('Pay {v} by card', {v:money(bagTotals().total)})}</b></div>
      ${fld({fid:'card', k:'number', label:t('Card number'), ac:'cc-number', im:'numeric', ekh:'next', ltr:true, hint:'1234 1234 1234 1234', max:23, end:`<span class="cbrw" id="cbrand">${brandChip(b)}</span>`})}
      <div class="row2f">${fld({fid:'card', k:'exp', label:t('Expiry (MM / YY)'), ac:'cc-exp', im:'numeric', ekh:'next', ltr:true, hint:t('MM / YY'), max:7})}${fld({fid:'card', k:'cvc', label:t('Security code'), ac:'cc-csc', im:'numeric', ekh:'next', ltr:true, hint:t('CVC'), max:4, end:`<span class="cvi" aria-hidden="true">${ico('lock')}</span>`})}</div>
      ${fld({fid:'card', k:'cname', label:t('Name on card'), ac:'cc-name', ekh:'done', cap:'characters'})}
      ${cbxRow('savecard', '', S.co.saveCard !== false, t('Save this card for next time'))}
      <p class="tiny muted lockl">${ico('lock')}${t('Card details go straight to Stripe over an encrypted connection. K-Beauty Bliss never sees or stores them.')}</p></div>`;
    return coPage(4, t('Card details'), inner, `<button class="btn block" data-act="card-next">${t('Continue')}</button>`, ctx, {sub:t('Step 6 of 9 · card')});
  }, dual:() => 'halves'});
FORMS.otp = () => [{k:'code', v:'req'}];
scr('/checkout/3ds', {tab:'bag', group:'Bag & Checkout', label:'6b · Card 3-D Secure', chain:() => CO_CHAIN.concat(['/checkout/card']), title:() => t('Verify your payment'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    const T = bagTotals();
    if (S.formFor.otp !== r.path) { S.formFor.otp = r.path; S.form.otp = {code:r.query.filled ? '481 205' : ''}; S.ferr.otp = {}; }
    const inner = `<div class="tds"><div class="tdsh">${X('bank', '<b>Emirates NBD</b>')}${X('pay', '<span class="vsec">VISA <i>Secure</i></span>')}</div>
      <div class="pad"><h2 class="h2">${t('Confirm it’s you')}</h2><p>${t('Enter the 6-digit code we sent to {p} to pay {v} to K-Beauty Bliss.', {p:LTR('+971 50 ••• 4567'), v:`<b>${money(T.total)}</b>`})}</p>
      <div class="tdsrow"><span>${t('Merchant')}</span><b>${brandWord()}</b></div><div class="tdsrow"><span>${t('Amount')}</span><b>${money(T.total)}</b></div><div class="tdsrow"><span>${t('Card')}</span><b>${LTR(VISA + ' •••• ' + (fval('card', 'number').replace(/\D/g, '').slice(-4) || '3184'))}</b></div>
      <div style="height:14px"></div>${fld({fid:'otp', k:'code', label:t('One-time code'), ac:'one-time-code', im:'numeric', ekh:'done', ltr:true, hint:'123 456', max:7})}
      <button class="lnk sm" data-act="toast" data-v="A new code is on its way.">${t('Resend code')}</button>
      <p class="tiny muted">${t('Your bank’s page, shown inside the app by Stripe. You never leave K-Beauty Bliss.')}</p></div></div>`;
    return coPage(4, t('Verify your payment'), inner, `<button class="btn block tdsb" data-act="otp-submit">${t('Submit')}</button><button class="btn block ghost noborder" style="margin-top:6px" data-act="decline" data-v="card">${t('Simulate a declined card')}</button>`, ctx, {sub:t('Step 6 of 9 · bank check')});
  }, dual:() => 'halves'});
function walletSheet(){
  const T = bagTotals(), a = coAddress();
  if (isA()) return `<div class="sheet gp" role="dialog" aria-label="${t('Google Pay')}"><div class="grab"></div><div class="sh2"><div></div><b>${t('Google Pay')}</b><div class="e"><button class="ib" data-act="back" aria-label="${t('Close')}">${ico('close')}</button></div></div><div class="sb2 ap"><div class="apr"><span>${t('Pay to')}</span><span class="grow"><b>${brandWord()}</b></span></div><div class="apr"><span>${t('Card')}</span><span class="grow"><b>${LTR(VISA + ' •••• 4242')}</b></span>${ico('chevR', 'chev flip')}</div><div class="apr"><span>${t('Ship to')}</span><span class="grow"><b>${utH(a.name)}</b><span class="small">${addrLine(a, {short:true})}</span></span>${ico('chevR', 'chev flip')}</div><div class="total"><span>${t('Total')}</span><b>${money(T.total)}</b></div><button class="btn block dark gpb" data-act="authorise" data-v="gpay" data-hap="success">${t('Continue')}</button><p class="tiny" style="text-align:center;color:#5f6368">${t('Then confirm with your fingerprint or screen lock.')}</p><button class="lnk sm dcl" data-act="decline" data-v="gpay">${t('Simulate a declined card')}</button></div></div>`;
  return `<div class="sheet sheetpay" role="dialog" aria-label="${t('Apple Pay')}"><div class="grab"></div><div class="sh2"><div><span class="paybtn mini" data-x="pay">${APPLE}Pay</span></div><b></b><div class="e"><button class="ib" data-act="back" aria-label="${t('Cancel')}">${ico('close')}</button></div></div><div class="sb2 ap"><div class="apr"><span>${t('Card')}</span><span class="grow"><b>${LTR(VISA + ' •••• 4242')}</b><span class="small">${X('bank', 'Emirates NBD')}</span></span>${ico('chevR', 'chev flip')}</div><div class="apr"><span>${t('Ship to')}</span><span class="grow"><b>${utH(a.name)}</b><span class="small">${addrLine(a, {short:true})}</span></span>${ico('chevR', 'chev flip')}</div><div class="apr"><span>${t('Contact')}</span><span class="grow"><b>${X('email', SARA.email)}</b></span>${ico('chevR', 'chev flip')}</div><div class="total"><span>${t('Pay K-Beauty Bliss')}</span><b>${money(T.total)}</b></div><button class="side" data-act="authorise" data-v="apple" data-hap="success">${ico('faceid')}<span>${t('Confirm with Side Button')}</span></button><button class="lnk sm dcl" data-act="decline" data-v="apple">${t('Simulate a declined card')}</button></div></div>`;
}
scr('/checkout/wallet', {tab:'bag', group:'Bag & Checkout', label:'6c · Apple Pay / Google Pay sheet', chain:() => CO_CHAIN, title:() => isA() ? t('Google Pay') : t('Apple Pay'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    S.co.pay = isA() ? 'gpay' : 'apple';
    const base = coPage(4, t('Payment'), `<div class="pad">${payList()}</div>`, payFoot(), ctx, {sub:t('Step 6 of 9 · wallet')});
    if (ctx.half === 'end') return base;
    return {...base, overlay:`<div class="ovl inpane" data-layer="sheet"><div class="scrim" data-act="back"></div>${walletSheet()}</div>`};
  }, dual:() => 'halves'});
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
for (const k of ['tabby', 'tamara']) scr('/checkout/' + k, {tab:'bag', group:'Bag & Checkout', label:k === 'tabby' ? '6d · Tabby approval' : '6e · Tamara approval', chain:() => CO_CHAIN, title:() => k === 'tabby' ? t('Tabby') : t('Tamara'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    S.co.pay = k;
    if (S.formFor.bnpl !== r.path) { S.formFor.bnpl = r.path; S.form.bnpl = {phone:fmtPhone(SARA.phone)}; S.ferr.bnpl = {}; }
    return coPage(4, k === 'tabby' ? t('Tabby') : t('Tamara'), bnplPage(k), `<button class="btn block ${k}b" data-act="bnpl-ok" data-v="${k}">${t('Confirm and continue')}</button><button class="btn block ghost noborder" style="margin-top:6px" data-act="decline" data-v="${k}">${t('Simulate a declined application')}</button>`, ctx, {sub:t('Step 6 of 9 · approval')});
  }, dual:() => 'halves'});
FORMS.cod = () => [{k:'phone', v:'phone'}];
scr('/checkout/cod', {tab:'bag', group:'Bag & Checkout', label:'6f · Cash on delivery', chain:() => CO_CHAIN, title:() => t('Cash on delivery'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    S.co.pay = 'cod';
    if (S.formFor.cod !== r.path) { S.formFor.cod = r.path; S.form.cod = {phone:fmtPhone((coAddress() || SARA).phone)}; S.ferr.cod = {}; }
    const T = bagTotals();
    const inner = `<div class="pad"><div class="codbig"><span class="codi lg">${ico('cash')}</span><div><span class="small muted">${t('Pay the courier')}</span><b>${money(T.total)}</b><span class="tiny muted">${t('Includes the {v} cash on delivery fee', {v:money(SET.cod_fee)})}</span></div></div>
      <ul class="ticks"><li>${ico('check')}${t('Cash only. Please have the exact amount ready.')}</li><li>${ico('check')}${t('Available in the UAE only.')}</li><li>${ico('check')}${t('The courier calls this number before arriving.')}</li></ul>
      ${fld({fid:'cod', k:'phone', label:t('Mobile number for the courier'), type:'tel', ac:'tel-national', im:'tel', icon:'phone', pre:'+971', ltr:true, hint:'50 123 4567', max:12})}
      ${cbxRow('codok', '', S.co.codOk !== false, t('I’ll be available to receive and pay for the order'))}</div>`;
    return coPage(4, t('Cash on delivery'), inner, `<button class="btn block" data-act="cod-ok">${t('Confirm and continue')}</button>`, ctx, {sub:t('Step 6 of 9 · confirm')});
  }, dual:() => 'halves'});

/* 7 · Review & place order */
scr('/checkout/review', {tab:'bag', group:'Bag & Checkout', label:'7 · Review & place order', chain:() => CO_CHAIN.concat([METHOD_ROUTE[S.co.pay] || '/checkout/card']), title:() => t('Review'),
  render(r, ctx){
    const g = coEmptyGuard(ctx); if (g) return g;
    const T = bagTotals(), a = coAddress();
    if (r.query.ok) S.co.authorised = S.co.pay;
    const email = S.user && !S.co.guest ? S.user.email : fval('contact', 'email') || SARA.email;
    const authed = S.co.authorised === S.co.pay && S.co.pay !== 'cod';
    const termsErr = S.ferr.review && S.ferr.review.terms;
    const inner = `<div class="pad">
      <div class="list">${S.bag.map(b => { const p = P[b.slug]; return `<div class="li rvi"><img src="${p.img200}" alt=""><span class="grow"><span class="clamp2 nm">${nameTxt(p)}</span><span class="tiny muted">${t('Qty {n}', {n:num(b.qty)})}</span></span><span class="small nowrap">${money(cur(p) * b.qty)}</span></div>`; }).join('')}</div>
      <div style="height:12px"></div><div class="list">
      <button class="li" ${go$('/checkout/contact')}>${ico('mail')}<span class="grow"><span class="tiny muted">${t('Contact')}</span><b class="lt">${X('email', esc(email))}</b></span>${ico('chevR', 'chev flip')}</button>
      <button class="li" ${go$('/checkout/address')}>${ico('pin')}<span class="grow"><span class="tiny muted">${t('Deliver to')}</span><b class="lt">${utH(a.name)}</b><span class="small muted">${addrLine(a, {short:true})}</span></span>${ico('chevR', 'chev flip')}</button>
      <button class="li" ${go$('/checkout/delivery')}>${ico('truck')}<span class="grow"><span class="tiny muted">${t('Delivery')}</span><b class="lt">${S.co.del === 'exp' ? t('Express · next working day') : S.co.del === 'gcc' ? t('GCC delivery · 3–6 working days') : t('Standard · 1–3 working days')}</b></span>${ico('chevR', 'chev flip')}</button>
      <button class="li" ${go$('/checkout/payment')}>${ico('card')}<span class="grow"><span class="tiny muted">${t('Payment')}</span><b class="lt">${S.co.pay === 'card' ? LTR(VISA + ' •••• ' + (fval('card', 'number').replace(/\D/g, '').slice(-4) || '3184')) : methodTxt(S.co.pay)}</b>${authed ? `<span class="small okc">${ico('check')}${t('Approved, not charged yet')}</span>` : ''}</span>${ico('chevR', 'chev flip')}</button></div>
      <div style="height:14px"></div>${totals(T)}
      <div class="fw"><button class="li cbr${termsErr ? ' err' : ''}" data-act="terms" role="checkbox" aria-checked="${!!S.co.terms}" aria-describedby="terms-m"><span class="cbx${S.co.terms ? ' on' : ''}">${ico('check')}</span><span class="grow small">${t('I agree to the Terms of sale and the Returns policy.')}</span></button><p class="fl-m" id="terms-m" aria-live="polite">${termsErr ? ico('alert') + `<span>${t('Please accept the terms to place your order.')}</span>` : ''}</p></div></div>`;
    return coPage(5, t('Review'), inner, `<button class="btn block" data-act="place">${ico('lock')}${t('Place order')} · ${money(T.total)}</button>`, ctx);
  }, dual:() => 'halves'});

/* 8 · Order placed */
scr('/checkout/placed', {tab:'bag', group:'Bag & Checkout', label:'8 · Order placed', chain:() => [], title:() => t('Order placed'),
  render(r, ctx){
    let o = S.orders.find(x => x.id === S.lastOrder);
    if (!o) { o = {...S.orders[0], id:'KBB-56215', st:'proc', items:S.bag.length ? S.bag.slice() : S.orders[0].items, total:S.bag.length ? bagTotals().total : S.orders[0].total, pay:S.co.pay, addr:S.co.addr}; }
    const a = S.co.addresses.find(x => x.id === o.addr) || coAddress();
    const first = S.user ? ut(S.user.first) : '';
    return {nobar:true, noTabs:true, bare:true, body:`<div class="state placed"><div class="done-mark">${LOTUS.replace('width="35" height="26"', 'width="56" height="42"').replace('<svg', '<svg class="lgx" style="width:56px;height:42px"')}</div><h2>${first ? t('Thank you, {n}', {n:esc(first)}) : t('Thank you')}</h2><p>${t('Order {id} is confirmed. We’ve emailed your receipt.', {id:X('code', `<b>#${o.id}</b>`)})}</p>
      <div class="cardbox plc"><div class="row2">${ico('truck')}<span class="grow"><b>${t('Arrives by {d}', {d:arrival(S.co.del === 'exp' ? 1 : 3)})}</b><span class="small muted" style="display:block">${addrLine(a, {short:true})}</span></span></div><div class="hr"></div><div class="cothumbs">${o.items.map(b => P[b.slug] ? `<span class="cth"><img src="${P[b.slug].img200}" alt="">${b.qty > 1 ? `<b>${num(b.qty)}</b>` : ''}</span>` : '').join('')}</div><div class="tot"><div><span>${t('Payment')}</span><span>${o.pay === 'card' ? LTR(VISA + ' •••• 3184') : methodTxt(o.pay)}</span></div><div class="grand"><span>${o.pay === 'cod' ? t('To pay on delivery') : t('Paid')}</span><span>${money(o.total)}</span></div></div></div>
      <button class="btn plb" data-act="track" data-v="${o.id}">${ico('truck')}${t('Track order')}</button><button class="btn ghost plb" ${go$('/home')} data-tabroot="home">${t('Continue shopping')}</button></div>`};
  }});

/* 9 · Payment failed → retry, the bag is kept */
const FAIL = {card:['Your bank declined the card. Nothing was charged.', 'card_declined · insufficient_funds'], apple:['The payment was cancelled in Apple Pay. Nothing was charged.', 'payment_intent_authentication_failure'], gpay:['Google Pay couldn’t complete the payment. Nothing was charged.', 'payment_intent_authentication_failure'], tabby:['Tabby couldn’t approve this order. Nothing was charged.', 'tabby: rejected · order_amount_too_high'], tamara:['Tamara couldn’t approve this order. Nothing was charged.', 'tamara: declined · risk_assessment']};
scr('/checkout/failed', {tab:'bag', group:'Bag & Checkout', label:'9 · Payment failed → retry', chain:() => CO_CHAIN, title:() => t('Payment'),
  render(r, ctx){
    const m = r.query.m || S.co.failed || 'card';
    const T = bagTotals();
    const inner = `<div class="state" style="min-height:0;padding-top:24px"><div class="ic errc">${ico('alert')}</div><h2>${t('Payment didn’t go through')}</h2><p>${t(FAIL[m][0])}</p><p class="tiny">${X('code', FAIL[m][1])}</p></div>
      <div class="pad"><div class="note ok">${ico('bag')}<span>${t('Your bag is safe: {n} items, {v}. Try again or choose another way to pay.', {n:num(bagCount()), v:money(T.total)})}</span></div>
      <div class="cothumbs" style="margin-top:12px">${S.bag.map(b => P[b.slug] ? `<span class="cth"><img src="${P[b.slug].img200}" alt="">${b.qty > 1 ? `<b>${num(b.qty)}</b>` : ''}</span>` : '').join('')}</div></div>`;
    return coPage(4, t('Payment'), inner, `<button class="btn block" data-act="retry-pay" data-v="${m}">${ico('refresh')}${t('Try again')}</button><button class="btn block ghost" style="margin-top:8px" ${go$('/checkout/payment')}>${t('Choose another method')}</button>`, ctx);
  }, dual:() => 'halves'});
