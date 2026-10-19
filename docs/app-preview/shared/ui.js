'use strict';
/* ── building blocks ───────────────────────────────────────────────────── */
const isA = () => S.os === 'android';
/* the site's breadcrumb words ("Shop" is the tab's "تسوّقي" but the crumb's "التسوق") */
const tc = k => t(k + ' (crumb)').replace(/ \(crumb\)$/, '');
const backIco = () => isA() ? ico('arrowL', 'flip') : ico('chevL', 'flip');
const logo = (tag = true) => `<span class="lgx"${AR ? ' data-x="logo"' : ''}>${LOTUS}<span class="w"><b>K-BEAUTY <em>BLISS</em></b>${tag ? `<small>${t('Authentic Korean skincare')}</small>` : ''}</span></span>`;
const brandWord = () => AR ? 'كي بيوتي بليس' : 'K-Beauty Bliss';
const go$ = path => `data-go="${esc(path)}"`;

function pcard(p, o = {}){
  if (!p) return '';
  const w = wasOf(p), off = pct(p), fav = S.wish.has(p.slug), sel = o.sel === p.slug;
  return `<div class="pc${sel ? ' sel' : ''}" data-card="${p.slug}"><div class="imw">
    <button class="open" ${go$('/product/' + p.slug)} tabindex="-1" aria-hidden="true"><div class="im"><img src="${p.img}" alt="" loading="${o.eager ? 'eager' : 'lazy'}" decoding="async">${off ? `<span class="badge off">−${num(off)}%</span>` : ''}</div></button>
    <button class="fav${fav ? ' on' : ''}" data-act="wish" data-p="${p.slug}" aria-pressed="${fav}" aria-label="${t(fav ? 'Remove from wishlist' : 'Save to wishlist')}"><i>${ico('heart')}</i></button>
    <button class="add" data-act="add" data-p="${p.slug}" aria-label="${t('Add to bag')}"><i>${ico('plus')}</i></button></div>
    <button class="open meta" ${go$('/product/' + p.slug)}><div class="br ell">${brandTxt(bn(p))}</div><div class="nm clamp2">${nameTxt(p)}</div>
      <div class="rt">${p.reviews ? `${stars(p.rating)}<span>(${num(p.reviews)})</span>` : `<span class="tiny">${t('New')}</span>`}</div>
      <div class="pr"><span class="price">${money(cur(p))}</span>${w ? `<span class="was">${money(w)}</span>` : ''}</div></button>
  </div>`;
}
function lcard(p){
  if (!p) return '';
  const w = wasOf(p);
  return `<button class="lc" ${go$('/product/' + p.slug)}><img src="${p.img200}" alt="" loading="lazy"><div style="min-width:0"><div class="br ell">${brandTxt(bn(p))}</div><div class="nm clamp2">${nameTxt(p)}</div><div class="pr"><span class="price">${money(cur(p))}</span>${w ? `<span class="was">${money(w)}</span>` : ''}</div></div></button>`;
}
const sec = (title, inner, o = {}) => `<section class="sec${o.cls ? ' ' + o.cls : ''}"${o.id ? ` id="${o.id}"` : ''}><div class="sh"><h2>${title}</h2>${o.more ? `<button class="more" ${go$(o.more)}>${t('View all')}${ico('chevR', 'flip')}</button>` : ''}</div>${inner}</section>`;
const rail = list => `<div class="hs">${list.map(p => pcard(p)).join('')}</div>`;
const skel = (h, w = '100%', r = 10) => `<div class="sk" style="height:${h}px;width:${w};border-radius:${r}px"></div>`;
const gridSkel = n => `<div class="grid">${Array.from({length:n}, () => `<div><div class="sk" style="aspect-ratio:1;border-radius:14px"></div><div style="height:8px"></div>${skel(10, '50%')}<div style="height:6px"></div>${skel(14, '90%')}<div style="height:6px"></div>${skel(14, '40%')}</div>`).join('')}</div>`;
const empty = (icon, h, p, btn, path) => `<div class="state"><div class="ic">${ico(icon)}</div><h2>${h}</h2><p>${p}</p>${btn ? `<button class="btn" ${go$(path)} data-root="1">${btn}</button>` : ''}</div>`;
const offbar = () => `<div class="offbar" role="status">${ico('wifioff')}<span class="grow">${t('You’re offline. Showing what was saved on this phone.')}</span><button data-act="retry">${t('Retry')}</button></div>`;
const errState = () => `<div class="state"><div class="ic">${ico('alert')}</div><h2>${t('Something went wrong')}</h2><p>${t('We couldn’t load this page. Your bag and wishlist are safe.')}</p><button class="btn" data-act="retry">${ico('refresh')}${t('Try again')}</button><p class="tiny muted">${t('Error code {c}', {c:X('code', 'APP-503')})}</p></div>`;
const bagBtn = () => `<button class="ib bagb" data-tab="bag" aria-label="${t('Bag')}">${ico('bag')}${bagCount() ? `<span class="bdg">${num(bagCount())}</span>` : ''}</button>`;
const priceBlock = (p, big) => { const w = wasOf(p); return `<div class="pp"><span class="price">${money(cur(p))}</span>${w ? `<span class="was">${money(w)}</span><span class="off">−${num(pct(p))}%</span>` : ''}</div>${w ? `<div class="save">${t('You save {v}', {v:money(w - cur(p))})}</div>` : ''}`; };
const qtyStep = (act, p, n, label) => `<div class="qty" role="group" aria-label="${label || t('Quantity')}"><button data-act="${act}" ${p ? `data-p="${p}"` : ''} data-v="-1" aria-label="${t('Less')}"${n <= 1 && act !== 'bq' ? ' disabled' : ''}>${ico(n <= 1 && act === 'bq' ? 'trash' : 'minus')}</button><b aria-live="polite">${num(n)}</b><button data-act="${act}" ${p ? `data-p="${p}"` : ''} data-v="1" aria-label="${t('More')}">${ico('plus')}</button></div>`;
const toggleRow = (act, v, on, label, sub, icon) => `<button class="li" data-act="${act}" data-v="${v}" role="switch" aria-checked="${!!on}">${icon ? ico(icon) : ''}<span class="grow"><b class="lt">${label}</b>${sub ? `<span class="small muted">${sub}</span>` : ''}</span><span class="tgb"><span class="tgl${on ? ' on' : ''}"></span></span></button>`;
const cbxRow = (act, v, on, label) => `<button class="li cbr" data-act="${act}" data-v="${v}" role="checkbox" aria-checked="${!!on}"><span class="cbx${on ? ' on' : ''}">${ico('check')}</span><span class="grow">${label}</span></button>`;
const radio = (on) => `<span class="rad${on ? ' on' : ''}" aria-hidden="true"></span>`;
const rowLink = (path, icon, label, val) => `<button class="li" ${go$(path)}>${icon ? ico(icon) : ''}<span class="grow">${label}</span>${val != null ? `<span class="v">${val}</span>` : ''}${ico('chevR', 'chev flip')}</button>`;
const bInit = b => initials(AR ? b.name : (b.name_en || b.name));
const initials = s => esc(String(s || '?').replace(/[^A-Za-z0-9؀-ۿ ]/g, '').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase());

/* ── fields: the website's floating label (kbb-checkout.css `.fld.kbb-fl`) ─
   The label sits inside the box as the placeholder; on focus or once filled
   it lifts to a small pink heading at the top of the box (top-start: left in
   English, right in Arabic) and the value sits below it. Pure CSS, as on the
   site: :focus / :not(:placeholder-shown) do the lifting; the checker reads
   the boxes. */
const FV = {
  req: v => v.trim() ? '' : 'required',
  name: v => !v.trim() ? 'required' : v.trim().length < 2 ? 'Enter your full name.' : '',
  email: v => !v.trim() ? 'required' : /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()) ? '' : 'Enter an email address like name@example.com.',
  phone: v => { const d = v.replace(/\D/g, '').replace(/^(00971|971|0)/, ''); return !d ? 'required' : /^5\d{8}$/.test(d) ? '' : 'Enter a 9-digit UAE mobile number, like 50 123 4567.'; },
  pw: v => !v ? 'required' : v.length < 8 ? 'Use at least 8 characters.' : '',
  pwIn: v => v ? '' : 'required',
  card: v => { const d = v.replace(/\D/g, ''); if (!d) return 'required'; if (d.length < 13) return 'Your card number is incomplete.'; let s = 0; d.split('').reverse().forEach((c, i) => { let n = +c; if (i % 2) { n *= 2; if (n > 9) n -= 9; } s += n; }); return s % 10 ? 'Your card number is invalid.' : ''; },
  exp: v => { const m = v.replace(/\s/g, '').match(/^(\d{2})\/?(\d{2})$/); if (!v.trim()) return 'required'; if (!m || +m[1] < 1 || +m[1] > 12) return 'Enter the expiry as MM / YY.'; const y = 2000 + +m[2], mo = +m[1]; return (y < 2026 || (y === 2026 && mo < 10)) ? 'This card has expired.' : ''; },
  cvc: v => !v.trim() ? 'required' : /^\d{3,4}$/.test(v.trim()) ? '' : 'Enter the 3 digits on the back of the card.',
  opt: () => '',
  msg: v => !v.trim() ? 'required' : v.trim().length < 10 ? 'Tell us a little more (10 characters or more).' : '',
};
const fval = (fid, k) => ((S.form[fid] || {})[k] ?? '');
function ferrMsg(fid, k){ const e = (S.ferr[fid] || {})[k]; return e ? (e === 'required' ? t('This field is required.') : t(e)) : ''; }
/* o: {fid, k, label, type, ac, im, ekh, icon, pre, opt, hint, ltr, end, max} */
function fld(o){
  const v = fval(o.fid, o.k), id = `f-${o.fid}-${o.k}`, err = ferrMsg(o.fid, o.k);
  const cls = ['fl', o.icon ? 'ico' : '', o.pre ? 'pre' : '', o.ltr ? 'ltrv' : '', o.end ? 'endi' : '', err ? 'err' : '', o.area ? 'area' : ''].filter(Boolean).join(' ');
  const attrs = `id="${id}" name="${o.k}" data-form="${o.fid}" data-k="${o.k}" placeholder="${esc(o.hint || ' ')}"${o.ac ? ` autocomplete="${o.ac}"` : ''}${o.im ? ` inputmode="${o.im}"` : ''}${o.ekh ? ` enterkeyhint="${o.ekh}"` : ''}${o.max ? ` maxlength="${o.max}"` : ''}${o.ltr ? ' dir="ltr"' : ''}${o.cap ? ` autocapitalize="${o.cap}"` : ''} aria-describedby="${id}-m"${err ? ' aria-invalid="true"' : ''}${o.opt ? '' : ' aria-required="true"'}`;
  const input = o.area ? `<textarea ${attrs} rows="4">${esc(v)}</textarea>` : `<input type="${o.type || 'text'}" value="${esc(v)}" ${attrs}>`;
  return `<div class="fw"><div class="${cls}" data-fld="${o.fid}.${o.k}">${o.icon ? `<span class="lead">${ico(o.icon)}</span>` : ''}${input}<label for="${id}">${o.label}${o.opt && !o.quiet ? ` <span class="optl">${t('(optional)')}</span>` : ''}</label>${o.pre ? `<span class="pfx" aria-hidden="true">${o.pre}</span>` : ''}${o.end || ''}</div><p class="fl-m" id="${id}-m" aria-live="polite">${err ? ico('alert') + `<span>${err}</span>` : ''}</p></div>`;
}
/* A picker that looks like a field: opens a sheet, shows the chosen value floated. */
function pickFld(o){
  const err = ferrMsg(o.fid, o.k), id = `f-${o.fid}-${o.k}`;
  return `<div class="fw"><button type="button" class="fl pick${o.value ? ' has' : ''}${err ? ' err' : ''}${o.icon ? ' ico' : ''}" data-fld="${o.fid}.${o.k}" data-sheet="${o.sheet}" id="${id}" aria-haspopup="listbox" aria-describedby="${id}-m"${err ? ' aria-invalid="true"' : ''}>${o.icon ? `<span class="lead">${ico(o.icon)}</span>` : ''}<span class="val">${o.value || ''}</span><span class="lbl">${o.label}</span>${ico('chevD', 'chv')}</button><p class="fl-m" id="${id}-m" aria-live="polite">${err ? ico('alert') + `<span>${err}</span>` : ''}</p></div>`;
}
/* Validate one field or a whole form; returns the number of errors. */
const FORMS = {};
function validate(fid, only){
  const F = FORMS[fid]; if (!F) return 0;
  const E = S.ferr[fid] = S.ferr[fid] || {};
  let n = 0;
  for (const f of F()) {
    if (only && f.k !== only) continue;
    if (f.when && !f.when()) { delete E[f.k]; continue; }
    const v = f.pick ? (fval(fid, f.k) ? 'x' : '') : fval(fid, f.k);
    const m = f.v ? FV[f.v](String(v)) : (f.opt ? '' : FV.req(String(v)));
    if (m) { E[f.k] = m; n++; } else delete E[f.k];
  }
  return n;
}
/* Repaint one field's error state in place (no re-render: keeps the keyboard up). */
function paintField(fid, k){
  const w = document.querySelector(`[data-fld="${fid}.${k}"]`); if (!w) return;
  const m = ferrMsg(fid, k);
  w.classList.toggle('err', !!m);
  const inp = w.querySelector('input,textarea'); if (inp) { if (m) inp.setAttribute('aria-invalid', 'true'); else inp.removeAttribute('aria-invalid'); }
  const msg = document.getElementById(`f-${fid}-${k}-m`); if (msg) msg.innerHTML = m ? ico('alert') + `<span>${m}</span>` : '';
}

/* card brand from the number (Stripe's own rules, trimmed) */
function cardBrand(v){
  const d = String(v || '').replace(/\D/g, '');
  if (/^4/.test(d)) return 'visa';
  if (/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/.test(d)) return 'mc';
  if (/^3[47]/.test(d)) return 'amex';
  return '';
}
const BRANDS = {visa:['VISA', '#1A1F71'], mc:['MC', 'linear-gradient(90deg,#EB001B 50%,#F79E1B 50%)'], amex:['AMEX', '#2E77BC']};
const brandChip = b => b ? `<i class="cbrand" style="background:${BRANDS[b][1]}" data-x="pay">${BRANDS[b][0]}</i>` : '';
function fmtCard(v){ const d = v.replace(/\D/g, '').slice(0, 19); return cardBrand(d) === 'amex' ? d.replace(/^(\d{0,4})(\d{0,6})(\d{0,5}).*/, (m, a, b, c) => [a, b, c].filter(Boolean).join(' ')) : d.replace(/(\d{4})(?=\d)/g, '$1 '); }
function fmtExp(v){ const d = v.replace(/\D/g, '').slice(0, 4); return d.length > 2 ? d.slice(0, 2) + ' / ' + d.slice(2) : d; }
function fmtPhone(v){ const d = v.replace(/\D/g, '').replace(/^(00971|971|0)/, '').slice(0, 9); return [d.slice(0, 2), d.slice(2, 5), d.slice(5)].filter(Boolean).join(' '); }

/* checkout step indicator: Bag · Contact · Address · Delivery · Payment · Review */
const COSTEPS = ['Bag', 'Contact', 'Address', 'Delivery', 'Payment', 'Review'];
function steps(n, sub){
  return `<div class="costeps" role="list" aria-label="${t('Checkout steps')}">${COSTEPS.map((l, i) => `<div role="listitem" class="${i < n ? 'done' : i === n ? 'on' : ''}"${i === n ? ' aria-current="step"' : ''}><i></i><span>${t(l)}</span></div>`).join('')}</div>${sub ? `<div class="costep-sub">${sub}</div>` : ''}`;
}
