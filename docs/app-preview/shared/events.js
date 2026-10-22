'use strict';
/* =========================== JUMP LIST, EVENTS, START ==================== */
/* Every screen the preview can show, by deep link. The switcher's "Jump to"
   menu, screens.html and tools/apv-shots.cjs all read this one list. */
const PSALE = (PL.find(p => pct(p) >= 20 && gal(p).length >= 2) || PL[3] || {}).slug;
const BRAND1 = (D.brands.find(b => b.banner && b.about && b.count >= 8) || D.brands[0] || {}).slug;
const BRAND2 = (D.brands.find(b => b.slug !== BRAND1 && b.count >= 6) || D.brands[1] || {}).slug;
const QWORD = AR ? 'سيروم' : 'serum';
const QNONE = AR ? 'مظلة' : 'umbrella';
const JUMP = [
  {g:'Home', label:'Home', path:'/home', designs:true},
  {g:'Home', label:'Home · loading', path:'/home?state=loading'},
  {g:'Home', label:'Home · offline', path:'/home?state=offline'},
  {g:'Home', label:'Splash', path:'/start/splash'},
  {g:'Home', label:'First run · notifications', path:'/start/notifications'},
  {g:'Shop', label:'All categories', path:'/categories'},
  {g:'Shop', label:'All brands', path:'/brands'},
  {g:'Shop', label:'Shop all products', path:'/products'},
  {g:'Shop', label:'Best Sellers', path:'/products?tag=best'},
  {g:'Shop', label:'Under 54', path:'/products?tag=under54'},
  {g:'Product', label:'Product', path:'/product/' + firstBest, designs:true},
  {g:'Product', label:'Product on sale', path:'/product/' + PSALE},
  {g:'Product', label:'Product · photo viewer', path:'/product/' + firstBest, ctl:{viewer:'0'}},
  {g:'Product', label:'Product · pay in 4', path:'/product/' + firstBest, ctl:{sheet:'bnpl'}},
  {g:'Product', label:'Product · loading', path:'/product/' + firstBest + '?state=loading'},
  {g:'Category', label:'Category · Skincare', path:'/category/skincare'},
  {g:'Category', label:'Category · Serums', path:'/category/serums'},
  {g:'Category', label:'Concern · Acne', path:'/category/acne'},
  {g:'Category', label:'Category · filter', path:'/category/serums', ctl:{sheet:'filter'}},
  {g:'Category', label:'Category · sort', path:'/category/serums', ctl:{sheet:'sort'}},
  {g:'Category', label:'Category · no matches', path:'/category/serums?state=nomatch'},
  {g:'Category', label:'Category · loading', path:'/category/serums?state=loading'},
  {g:'Brand', label:'Brand page', path:'/brand/' + BRAND1},
  {g:'Brand', label:'Brand page (second)', path:'/brand/' + BRAND2},
  {g:'Brand', label:'Brand · filter', path:'/brand/' + BRAND1, ctl:{sheet:'filter'}},
  {g:'Search', label:'Search', path:'/search'},
  {g:'Search', label:'Search results', path:'/search?q=' + encodeURIComponent(QWORD)},
  {g:'Search', label:'Search · no results', path:'/search?q=' + encodeURIComponent(QNONE)},
  {g:'Search', label:'Barcode scanner', path:'/scan'},
  {g:'Bag & Checkout', label:'Bag', path:'/bag'},
  {g:'Bag & Checkout', label:'Bag · empty', path:'/bag?state=empty'},
  {g:'Bag & Checkout', label:'Checkout (one page)', path:'/checkout', step:1},
  {g:'Bag & Checkout', label:'Checkout · guest', path:'/checkout?guest=1', ctl:{user:'0'}, step:1},
  {g:'Bag & Checkout', label:'Checkout · filled', path:'/checkout?filled=1', step:1},
  {g:'Bag & Checkout', label:'Checkout · errors', path:'/checkout?errors=1&guest=1', ctl:{user:'0'}, step:1},
  {g:'Bag & Checkout', label:'Checkout · summary open', path:'/checkout?filled=1&sum=1', step:1},
  {g:'Bag & Checkout', label:'Checkout · emirate picker', path:'/checkout?guest=1', ctl:{user:'0', sheet:'emirate'}, step:1},
  {g:'Bag & Checkout', label:'Checkout · Tabby chosen', path:'/checkout?filled=1&pay=tabby', step:1},
  {g:'Bag & Checkout', label:'Checkout · cash on delivery', path:'/checkout?filled=1&pay=cod', step:1},
  {g:'Bag & Checkout', label:'After Pay now · card 3-D Secure', path:'/checkout/3ds', step:2},
  {g:'Bag & Checkout', label:'After Pay now · Apple Pay / Google Pay', path:'/checkout/wallet?filled=1', step:2},
  {g:'Bag & Checkout', label:'After Pay now · Tabby approval', path:'/checkout/tabby', step:2},
  {g:'Bag & Checkout', label:'After Pay now · Tamara approval', path:'/checkout/tamara', step:2},
  {g:'Bag & Checkout', label:'After Pay now · order placed', path:'/checkout/placed', step:2},
  {g:'Bag & Checkout', label:'After Pay now · payment failed (card)', path:'/checkout/failed?m=card', step:2},
  {g:'Bag & Checkout', label:'After Pay now · payment failed (Tabby)', path:'/checkout/failed?m=tabby', step:2},
  {g:'Account', label:'Account', path:'/account'},
  {g:'Account', label:'Account · signed out', path:'/account', ctl:{user:'0'}},
  {g:'Account', label:'Sign in', path:'/account/signin'},
  {g:'Account', label:'Sign in (filled)', path:'/account/signin?filled=1'},
  {g:'Account', label:'Create account', path:'/account/register'},
  {g:'Account', label:'Forgot password', path:'/account/forgot'},
  {g:'Account', label:'Face ID / fingerprint unlock', path:'/account/biometric'},
  {g:'Account', label:'Profile', path:'/account/profile'},
  {g:'Account', label:'Addresses', path:'/account/addresses'},
  {g:'Account', label:'Add address', path:'/account/addresses/new'},
  {g:'Account', label:'Orders', path:'/account/orders'},
  {g:'Account', label:'Order on the way', path:'/account/orders/KBB-56214'},
  {g:'Account', label:'Order delivered', path:'/account/orders/KBB-55870'},
  {g:'Account', label:'Wishlist', path:'/wishlist'},
  {g:'Account', label:'Notification settings', path:'/account/notifications'},
  {g:'Account', label:'Language & region', path:'/account/language'},
  {g:'Account', label:'Delete account', path:'/account/delete'},
  {g:'Account', label:'Delete account · confirm', path:'/account/delete', ctl:{sheet:'delconfirm'}},
  {g:'Content', label:'Contact us', path:'/contact'},
  {g:'Content', label:'Contact us (filled)', path:'/contact?filled=1'},
  {g:'Content', label:'FAQ', path:'/faq'},
  {g:'Content', label:'Policies', path:'/policies'},
  {g:'Content', label:'Policy · Returns', path:'/policy/returns'},
  {g:'Content', label:'About', path:'/about'},
  {g:'Content', label:'Blog', path:'/blog'},
  {g:'Content', label:'Blog article', path:'/blog/' + ((D.posts || [])[0] || {}).slug},
  {g:'States', label:'Offline', path:'/state/offline'},
  {g:'States', label:'Error', path:'/home?state=error'},
  {g:'States', label:'Product error', path:'/product/' + firstBest + '?state=error'},
  {g:'States', label:'Update required', path:'/state/update'},
  {g:'States', label:'Wishlist · empty', path:'/wishlist?state=empty'},
  {g:'States', label:'Orders · empty', path:'/account/orders?state=empty'},
  {g:'States', label:'Orders · loading', path:'/account/orders?state=loading'},
  {g:'States', label:'Bag · loading', path:'/bag?state=loading'},
].map((j, i) => ({...j, id:i, label:j.label === 'Under 54' ? 'Under {v}' : j.label}));
const jumpHref = (j, o = {}) => `#${j.path}${j.path.includes('?') ? '&' : '?'}device=${o.device || devKey()}${o.design && o.design !== 'A' ? '&d=' + o.design : ''}${(o.bb || S.bb) !== 'A' ? '&bb=' + (o.bb || S.bb) : ''}${(o.tm || S.tm) === 'bar' ? '&tm=bar' : ''}${Object.entries(j.ctl || {}).map(([k, v]) => `&${k}=${v}`).join('')}${o.embed ? '&embed=1' : ''}`;

/* ── state from the address bar ────────────────────────────────────────── */
const CTL = ['device', 'os', 'd', 'dg', 'embed', 'z', 'sheet', 'viewer', 'user', 'k', 'bb', 'tm', 'menu'];
function readHash(){
  const raw = decodeURIComponent(location.hash.slice(1) || '/home');
  const [p0, qs] = raw.split('?');
  const q = new URLSearchParams(qs || '');
  const dk = DEVKEYS[q.get('device')] ? q.get('device') : null;
  if (dk) { const x = DEVKEYS[dk]; S.dev = x.dev; S.os = DEV[x.dev].os; S.land = !!x.land; S.posture = x.posture || 'flat'; if (x.dev === 'fold') S.folded = x.folded; }
  else if (q.get('os') === 'android' || q.get('os') === 'ios') { S.os = q.get('os'); if (DEV[S.dev].os !== S.os) S.dev = DEVORDER.find(k => DEV[k].os === S.os); }
  if ('ABC'.includes(q.get('d') || 'x')) S.design = q.get('d'); else S.design = 'A';
  if (AR) S.digits = q.get('dg') === 'arab' ? 'arab' : 'latn';
  S.embed = q.get('embed') === '1';
  S.bb = BB.includes(q.get('bb') || '') ? q.get('bb') : S.embed ? 'D' : pref.get('bb', 'D', v => BB.includes(v));
  S.tm = q.get('tm') === 'bar' || q.get('tm') === 'site' ? q.get('tm') : S.embed ? 'site' : pref.get('tm', 'site', v => v === 'site' || v === 'bar');
  S.menu = q.get('menu') === '1'; S.mega = q.get('menu') && /^m\d+$/.test(q.get('menu')) ? +q.get('menu').slice(1) : null; S.mmNode = null; S.mmQ = '';
  S.zoom = q.get('z') === '100' ? '100' : 'fit';
  if (q.get('user') === '0') { S.user = null; persist(); }
  const rq = new URLSearchParams();
  for (const [k, v] of q) if (!CTL.includes(k)) rq.append(k, v);
  const path = (p0.startsWith('/') ? p0 : '/' + p0) + (rq.toString() ? '?' + rq.toString().replace(/\+/g, '%20') : '');
  const r = resolve(path) ? path : '/home';
  resetStacks();
  const sf = stackFor(r);
  S.tab = sf.tab; S.stacks[sf.tab] = sf.st; enter(r);
  S.sheet = q.get('sheet') || null; S.sheetKey = q.get('k') || listKey(resolve(r)); S.sheetSide = 'l';
  S.viewer = q.get('viewer') != null ? {slug:(resolve(r).params || {}).slug, i:+q.get('viewer') || 0} : null;
  document.body.classList.toggle('embed', S.embed);
}

/* ── helpers used by the actions ───────────────────────────────────────── */
function goChain(path, anim = 'push'){
  const sf = stackFor(path); if (!sf) return;
  S.sheet = null; S.viewer = null; enter(path);
  S.tab = sf.tab; S.stacks[sf.tab] = sf.st; render({anim});
}
function focusFirstError(fid){
  const k = Object.keys(S.ferr[fid] || {})[0]; if (!k) return;
  const el = document.querySelector(`[data-fld="${fid}.${k}"]`);
  if (!el) return;
  const body = el.closest('.body');
  if (body) body.scrollTop += el.getBoundingClientRect().top - body.getBoundingClientRect().top - 80;
  const inp = el.querySelector('input,textarea'); if (inp) inp.focus({preventScroll:true});
}
function submitForm(fid){
  const n = validate(fid);
  if (n) { haptic('error'); render(); focusFirstError(fid); return false; }
  return true;
}
function addToBag(slug, q = 1){
  if (!P[slug]) return;
  const x = S.bag.find(b => b.slug === slug);
  if (x) x.qty = Math.min(99, x.qty + q); else S.bag.push({slug, qty:q});
  persist();
}
const ORDER_SEQ = {n:56215};
function placeOrder(){
  const T = bagTotals();
  const id = 'KBB-' + ORDER_SEQ.n++;
  S.orders.unshift({id, d:'2026-10-19', st:'proc', items:S.bag.map(b => ({...b})), total:T.total, pay:S.co.pay, addr:S.co.addr, ship:{...(S.form.co || {})}});
  S.lastOrder = id;
  S.bag = []; S.coupon = null; S.co.authorised = null; S.co.gift = false; S.formFor.co = null;
  persist();
  S.sheet = null; S.tab = 'bag'; S.stacks.bag = [{path:'/bag'}, {path:'/checkout/placed'}];
  render({anim:'fade'});
}
function pickDone(f, k, v){
  S.form[f] = S.form[f] || {};
  S.form[f][k] = v;
  if (S.ferr[f]) delete S.ferr[f][k];
  if (k === 'emirate') {
    if (!areasOf(v).some(a => a[0] === S.form[f].area)) S.form[f].area = '';
    S.areaQ = ''; S.sheet = f === 'co' || S.form[f].area ? null : 'area'; render(); return;
  }
  S.sheet = null; render();
}

/* ── clicks ────────────────────────────────────────────────────────────── */
const ACT = {
  back:() => back(),
  close:() => { S.sheet = null; render(); },
  closeviewer:() => { S.viewer = null; render(); },
  share:() => { haptic('impact light'); toast(t('The share sheet opens here.')); },
  toast:(el) => toast(t(el.dataset.v)),
  retry:() => { const p = topE().path; const clean = p.replace(/([?&])state=[^&]*&?/, '$1').replace(/[?&]$/, ''); haptic('impact light'); if (clean !== p) go(clean, {replace:true}); else toast(t('Up to date')); },
  torch:() => { haptic('impact light'); toast(t('Torch on')); },
  wish:(el) => { const p = el.dataset.p; const on = !S.wish.has(p); on ? S.wish.add(p) : S.wish.delete(p); persist(); haptic('impact light'); toast(t(on ? 'Saved to your wishlist' : 'Removed from your wishlist'), on ? {tab:'account', label:t('View'), go:'/wishlist'} : {}); },
  add:(el) => { addToBag(el.dataset.p, el.dataset.q ? S.qty : 1); haptic('success'); toast(t('Added to bag'), {tab:'bag', label:t('View bag')}); },
  addall:(el) => { const p = P[el.dataset.p]; const L = [p, ...PL.filter(x => x.slug !== p.slug && !(x.cats || []).some(c => (p.cats || []).includes(c)) && x.bslug !== p.bslug).slice(0, 2)].filter(x => S.bt[x.slug] !== false); L.forEach(x => addToBag(x.slug)); haptic('success'); toast(t('{n} items added to bag', {n:num(L.length)}), {tab:'bag', label:t('View bag')}); },
  bt:(el) => { S.bt[el.dataset.p] = S.bt[el.dataset.p] === false; render(); },
  pq:(el) => { S.qty = Math.max(1, Math.min(9, S.qty + +el.dataset.v)); haptic('selection'); render(); },
  bq:(el) => { const b = S.bag.find(x => x.slug === el.dataset.p); if (!b) return; b.qty += +el.dataset.v; if (b.qty < 1) S.bag = S.bag.filter(x => x !== b); persist(); haptic('selection'); render(); },
  rm:(el) => { S.bag = S.bag.filter(b => b.slug !== el.dataset.p); persist(); haptic('impact medium'); toast(t('Removed from bag')); },
  conc:(el) => { S.conc = +el.dataset.v; render(); if (el.dataset.scrollto) { const s = $('#stage [data-sec="sbc"]'); const b = s && s.closest('.body'); if (b) b.scrollTo({top:s.offsetTop - 8, behavior:reduced() ? 'auto' : 'smooth'}); } },
  gi:(el) => { const box = el.closest('.pane,.tts') || document; const g = $('[data-dots^="gthumbs"]', box); if (g) g.scrollTo({left:(AR ? -1 : 1) * +el.dataset.v * g.clientWidth, behavior:reduced() ? 'auto' : 'smooth'}); },
  viewer:(el) => { S.viewer = {slug:(route().params || {}).slug, i:+el.dataset.v || 0}; render(); },
  vi:(el) => { S.viewer.i = +el.dataset.v; const b = $('#stage [data-vs]'); if (b) b.scrollTo({left:(AR ? -1 : 1) * S.viewer.i * b.clientWidth, behavior:'smooth'}); $$('#stage .viewer .thumbs button').forEach((x, j) => x.classList.toggle('on', j === S.viewer.i)); },
  rphoto:(el) => { S.viewer = {photos:[el.dataset.v], i:0}; render(); },
  ptab:(el) => { S.ptab = el.dataset.v; render(); },
  acc:(el) => { const v = el.dataset.v; S.open[v] = !(S.open[v] ?? el.dataset.def === '1'); render(); },
  toreviews:(el) => { const b = el.closest('.body'); const r = b && b.querySelector('#reviews'); if (r) b.scrollTo({top:r.offsetTop - 60, behavior:reduced() ? 'auto' : 'smooth'}); },
  express:(el) => { const p = (route().params || {}).slug; if (p && !S.bag.some(b => b.slug === p)) addToBag(p, S.qty); goChain('/checkout/wallet'); },
  page:(el) => { const b = $('#stage [data-layer="p0-body"]'); if (b) b.scrollBy({top:+el.dataset.v * b.clientHeight * .8, behavior:reduced() ? 'auto' : 'smooth'}); },
  /* listings */
  cols:(el) => { S.cols = +el.dataset.v === 1 ? 1 : 2; try { localStorage.setItem('apv-cols', String(S.cols)); } catch {} haptic('selection'); render(); },
  sort:(el) => { S.sort[el.dataset.k] = el.dataset.v; S.more[el.dataset.k] = 12; S.sheet = null; haptic('selection'); render(); },
  fconc:(el) => { const f = filt(el.dataset.k); f.conc = f.conc === el.dataset.v ? '' : el.dataset.v; S.more[el.dataset.k] = 12; render(); },
  fmax:(el) => { const f = filt(el.dataset.k); f.max = f.max === +el.dataset.v ? 0 : +el.dataset.v; S.more[el.dataset.k] = 12; render(); },
  fsale:(el) => { const f = filt(el.dataset.v); f.sale = !f.sale; S.more[el.dataset.v] = 12; render(); },
  fbrand:(el) => { const f = filt(el.dataset.k), v = el.dataset.v; f.brands = f.brands.includes(v) ? f.brands.filter(x => x !== v) : f.brands.concat([v]); S.more[el.dataset.k] = 12; render(); },
  clearf:(el) => { const k = el.dataset.k; S.filt[k] = {brands:[], max:0, conc:'', sale:false}; S.more[k] = 12; if (S.sheet !== 'filter') S.sheet = null; const p = topE().path; if (/state=nomatch/.test(p)) { go(p.replace(/[?&]state=nomatch/, ''), {replace:true}); return; } render(); },
  q:(el) => { S.q = el.dataset.v; if (S.q) S.recent = [S.q].concat(S.recent.filter(x => x !== S.q)).slice(0, 6); render(); },
  clearrecent:() => { S.recent = []; render(); },
  /* bag & checkout */
  coupon:() => {
    const v = String(fval('coupon', 'code')).trim().toUpperCase();
    S.ferr.coupon = {};
    if (!v) S.ferr.coupon.code = 'Enter a discount code.';
    else if (v === 'WELCOME10') { S.coupon = v; S.form.coupon = {}; haptic('success'); toast(t('{c} applied · 10% off', {c:X('code', v)})); return; }
    else S.ferr.coupon.code = 'This code isn’t valid or has expired.';
    haptic('error'); render(); const i = $('#stage #f-coupon-code'); if (i) i.focus();
  },
  uncoupon:() => { S.coupon = null; haptic('impact light'); render(); },
  news:() => { S.co.news = !S.co.news; render(); },
  flabel:(el) => { S.form[el.dataset.k].label = el.dataset.v; render(); },
  fdef:(el) => { const f = S.form[el.dataset.v]; f.def = f.def === false; render(); },
  'addr-save':(el) => {
    if (!submitForm('addr')) return;
    const f = S.form.addr;
    const a = {id:f.edit || 'a' + Date.now().toString(36), label:f.label || 'Home', name:f.name.trim(), phone:f.phone.replace(/\D/g, '').replace(/^(00971|971|0)/, ''), emirate:f.emirate, area:f.area, building:f.building.trim(), street:f.street.trim(), flat:(f.flat || '').trim(), landmark:(f.landmark || '').trim(), country:'AE', def:f.def !== false};
    if (a.def) S.co.addresses.forEach(x => x.def = false);
    const i = S.co.addresses.findIndex(x => x.id === a.id);
    if (i >= 0) S.co.addresses[i] = a; else S.co.addresses.push(a);
    S.formFor.addr = null; haptic('success');
    const st = S.stacks[S.tab];
    st.pop(); render({anim:'pop'}); toast(t('Address saved'));
  },
  pick:(el) => { haptic('selection'); pickDone(el.dataset.f || (route().path.startsWith('/contact') ? 'msg' : route().s.pattern === '/checkout' ? 'co' : 'addr'), el.dataset.k, el.dataset.v); },
  gift:() => { S.co.gift = !S.co.gift; haptic('selection'); render(); },
  pay:(el) => { if (el.getAttribute('aria-disabled') === 'true') { haptic('error'); toast(t('Cash on delivery is available in the UAE only.')); return; } S.co.pay = el.dataset.v; S.co.authorised = null; S.ferr.card = {}; haptic('selection'); render(); },
  savecard:() => { S.co.saveCard = S.co.saveCard === false; render(); },
  'otp-submit':() => {
    const d = fval('otp', 'code').replace(/\D/g, '');
    S.ferr.otp = {};
    if (!d) S.ferr.otp.code = 'required'; else if (d.length !== 6) S.ferr.otp.code = 'Enter the 6-digit code.';
    if (S.ferr.otp.code) { haptic('error'); render(); focusFirstError('otp'); return; }
    S.co.authorised = 'card'; haptic('success'); placeOrder();
  },
  decline:(el) => { S.co.failed = el.dataset.v; S.co.authorised = null; haptic('error'); goChain('/checkout/failed?m=' + el.dataset.v, 'fade'); },
  authorise:(el) => { S.co.pay = el.dataset.v; S.co.authorised = el.dataset.v; haptic('success'); S.sheet = null; placeOrder(); },
  'bnpl-ok':(el) => { if (!submitForm('bnpl')) return; S.co.pay = el.dataset.v; S.co.authorised = el.dataset.v; haptic('success'); placeOrder(); },
  /* the one page's Pay now: every field judged on the page; then what follows the tap */
  place:() => {
    const n = validate('co') + (S.co.pay === 'card' ? validate('card') : 0);
    if (n) { haptic('error'); render(); focusFirstError(Object.keys(S.ferr.co || {}).length ? 'co' : 'card'); return; }
    haptic('success');
    const next = METHOD_ROUTE[S.co.pay];
    if (next) { go(next); return; }
    placeOrder();
  },
  cotog:(el) => { const k = el.dataset.v; S.co[k] = !S.co[k]; render(); },
  cosum:() => { S.co.sumOpen = !S.co.sumOpen; render(); },
  cofoot:() => { S.co.footOpen = !S.co.footOpen; render(); },
  totop:(el) => { const b = el.closest('.body'); if (b) b.scrollTo({top:0, behavior:reduced() ? 'auto' : 'smooth'}); },
  'retry-pay':(el) => { const m = el.dataset.v; if (m !== 'other') S.co.pay = m === 'apple' || m === 'gpay' ? (isA() ? 'gpay' : 'apple') : m; S.stacks.bag = [{path:'/bag'}, {path:'/checkout'}]; S.tab = 'bag'; render({anim:'pop'}); },
  track:(el) => goChain('/account/orders/' + el.dataset.v, 'tab'),
  /* account */
  pw:() => { S.pw = !S.pw; render(); },
  signin:(el) => { if (!submitForm('signin')) return; S.user = SARA; S.co.guest = false; persist(); haptic('success'); if (el.dataset.v === 'checkout') { S.formFor.co = null; goChain('/checkout', 'fade'); } else { S.stacks.account = [{path:'/account'}]; S.tab = 'account'; render({anim:'pop'}); } toast(t('Signed in as {n}', {n:esc(ut(SARA.first))})); },
  register:() => { if (!submitForm('register')) return; S.user = SARA; persist(); haptic('success'); S.stacks.account = [{path:'/account'}]; S.tab = 'account'; render({anim:'pop'}); toast(t('Welcome to K-Beauty Bliss')); },
  forgot:() => { if (!submitForm('forgot')) return; S.sent.forgot = true; haptic('success'); render(); },
  signout:() => { S.user = null; persist(); haptic('impact medium'); render(); toast(t('Signed out')); },
  faceid:() => { S.faceid = !S.faceid; haptic('selection'); render(); },
  skin:(el) => { S.form.profile.skin = el.dataset.v; render(); },
  'profile-save':() => { if (!submitForm('profile')) return; haptic('success'); toast(t('Changes saved')); },
  'addr-del':(el) => { S.co.addresses = S.co.addresses.filter(a => a.id !== el.dataset.v); if (!S.co.addresses.some(a => a.id === S.co.addr)) S.co.addr = (S.co.addresses[0] || {}).id; haptic('impact medium'); render(); toast(t('Address deleted')); },
  'delete-account':() => { S.user = null; persist(); S.sheet = null; S.stacks.account = [{path:'/account'}]; render({anim:'pop'}); toast(t('Your account was deleted')); },
  digits:(el) => { S.digits = el.dataset.v; render(); },
  ntog:(el) => { S.notif[el.dataset.v] = !S.notif[el.dataset.v]; haptic('selection'); render(); },
  'msg-send':() => { if (!submitForm('msg')) return; S.sent.msg = true; haptic('success'); render(); },
  'msg-again':() => { S.sent.msg = false; S.form.msg.body = ''; S.ferr.msg = {}; render(); },
  wa:() => { haptic('impact light'); toast(t('Opens WhatsApp with K-Beauty Bliss')); },
  perm:() => { S.notif.orders = true; haptic('success'); go('/home', {tabroot:'home'}); toast(t('Notifications are on')); },
  wish2bag:() => { [...S.wish].forEach(s => { if (!S.bag.some(b => b.slug === s)) addToBag(s); }); haptic('success'); toast(t('Moved to bag'), {tab:'bag', label:t('View bag')}); },
  ...ACT_EXTRA,
  reorder:(el) => { const o = S.orders.find(x => x.id === el.dataset.v) || S.orders[0]; o.items.forEach(b => addToBag(b.slug, b.qty)); haptic('success'); toast(t('Added again to your bag'), {tab:'bag', label:t('View bag')}); },
};
let swallowClick = false;
/* the checkout's Emirate is the site's <select>: in the app it opens the picker sheet instead of the browser's list */
document.addEventListener('mousedown', e => { const s = e.target.closest && e.target.closest('#stage select[data-sheet]'); if (s) e.preventDefault(); }, true);
document.addEventListener('click', e => {
  if (swallowClick) { swallowClick = false; e.preventDefault(); e.stopPropagation(); return; }
  const sw = e.target.closest('[data-sw]');
  if (sw && sw.tagName === 'BUTTON') {
    const k = sw.dataset.sw, v = sw.dataset.v;
    if (k === 'design') S.design = v;
    if (k === 'os') { S.os = v; S.dev = DEVORDER.find(x => DEV[x].os === v); }
    if (k === 'dev') S.dev = v;
    if (k === 'fold') S.folded = v === 'fold';
    if (k === 'posture') S.posture = v;
    if (k === 'land') S.land = v === '1';
    if (k === 'zoom') S.zoom = v;
    S.sheetSide = 'l';
    render();
    return;
  }
  if (S.mega != null && e.target.closest('#stage') && !e.target.closest('.navitem')) { S.mega = null; $$('#stage .navitem.kopen').forEach(x => x.classList.remove('kopen')); }
  const el = e.target.closest('[data-act],[data-go],[data-tab],[data-sheet]');
  if (!el || !el.closest('#stage')) return;
  if (el.tagName === 'A' && el.getAttribute('href') && !el.dataset.go) return;
  e.preventDefault();
  if (el.disabled || el.getAttribute('aria-disabled') === 'true' && !el.dataset.act) return;
  if (el.dataset.hap) haptic(el.dataset.hap);
  if (el.dataset.tab) {
    const tgo = S.toast && el.closest('.toast') && S.toast.go;
    clearTimeout(toastTimer); S.toast = null;
    if (tgo) { goChain(tgo, 'tab'); return; }
    tab(el.dataset.tab);
    if (el.classList.contains('search-in')) { const q = $('#stage #q'); if (q) q.focus(); }
    return;
  }
  if (el.dataset.sheet) {
    S.sheet = el.dataset.sheet; S.sheetKey = el.dataset.k || S.sheetKey; S.areaQ = '';
    const gl = el.closest('[data-pane]'); S.sheetSide = gl && gl.dataset.pane === 'end' ? 'r' : 'l';
    haptic('impact light'); render(); return;
  }
  if (el.dataset.go) {
    const path = el.dataset.go;
    const pane = el.closest('[data-match]');
    if (pane && pane.dataset.slot === 'start' && new RegExp(pane.dataset.match).test(path)) { S.detail[pane.dataset.host] = path; enter(path); S.sheet = null; render({anim:'fade'}); return; }
    const endPane = el.closest('[data-slot="end"]');
    const host = $('#stage [data-match][data-slot="start"]');
    if (endPane && host && new RegExp(host.dataset.match).test(path)) { S.detail[host.dataset.host] = path; enter(path); render({anim:'fade'}); return; }
    if (el.dataset.tabroot) { go(path, {tabroot:el.dataset.tabroot}); return; }
    if (el.dataset.root) { go(path, {root:true}); return; }
    go(path, {replace:!!el.dataset.replace}); return;
  }
  const f = ACT[el.dataset.act];
  if (f) f(el, e);
});

/* ── typing: fields keep focus; only their own message and the totals repaint ─ */
const FMT = {phone:fmtPhone, number:fmtCard, exp:fmtExp, code:(v, fid) => fid === 'otp' ? v.replace(/\D/g, '').slice(0, 6).replace(/^(\d{3})(\d)/, '$1 $2') : fid === 'coupon' ? v.toUpperCase() : v};
document.addEventListener('input', e => {
  const el = e.target;
  if (el.dataset && el.dataset.form) {
    const fid = el.dataset.form, k = el.dataset.k;
    let v = el.value;
    const fm = FMT[k];
    if (fm && (k !== 'phone' || el.type === 'tel')) {
      const nv = fm(v, fid);
      if (nv !== v) { const atEnd = el.selectionStart === v.length; el.value = nv; if (atEnd) el.setSelectionRange(nv.length, nv.length); v = nv; }
    }
    S.form[fid] = S.form[fid] || {};
    S.form[fid][k] = v;
    if ((S.ferr[fid] || {})[k]) { validate(fid, k); paintField(fid, k); }
    if (fid === 'card' && k === 'number') { const c = $('#cbrand'); if (c) c.innerHTML = brandChip(cardBrand(v)); }
    if (fid === 'register' && k === 'pw') {
      const sc = Math.min(4, (v.length >= 8) + /[A-Z]/.test(v) + /\d/.test(v) + (v.length >= 12 || /[^A-Za-z0-9]/.test(v)));
      const m = $('#pwm'); if (m) m.innerHTML = [0, 1, 2, 3].map(i => `<i class="${i < sc ? 's' + sc : ''}"></i>`).join('') + `<span>${v ? t(['Too short', 'Weak', 'Fair', 'Good', 'Strong'][sc]) : t('At least 8 characters')}</span>`;
    }
    return;
  }
  if (el.id === 'q') { S.q = el.value; const box = $('#sres'); if (box) box.innerHTML = searchResults({cls:sizeClass(dev().w)}); const c = el.parentElement.querySelector('.clr'); if (c) c.outerHTML = S.q ? `<button class="ib clr" data-act="q" data-v="" aria-label="${t('Clear search')}">${ico('close')}</button>` : `<button class="ib clr" ${go$('/scan')} aria-label="${t('Scan barcode')}">${ico('scan')}</button>`; return; }
  if (el.classList && el.classList.contains('id-mmFilter')) { S.mmQ = el.value; mmFilter(el.closest('.kh')); return; }
  if (el.id === 'bq') { S.brandQ = el.value; const b = $('#blist'); if (b) b.innerHTML = brandList(); return; }
  if (el.id === 'cq') { S.catQ = el.value; const b = $('#clist'); if (b) b.innerHTML = catList(); return; }
  if (el.id === 'aq') { S.areaQ = el.value; const b = $('#alist'); if (b) { const q = el.value.trim().toLowerCase(); b.innerHTML = areaRows(areasOf(fval('addr', 'emirate')).filter(a => !q || t(a[1]).toLowerCase().includes(q) || a[1].toLowerCase().includes(q)), fval('addr', 'area')); } }
});
/* leaving a field judges it (only once something was typed: an empty field is
   judged on Continue, as on the website) */
document.addEventListener('focusout', e => {
  const el = e.target;
  if (!el.dataset || !el.dataset.form || !el.value) return;
  validate(el.dataset.form, el.dataset.k); paintField(el.dataset.form, el.dataset.k);
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && (S.sheet || S.viewer || S.menu || S.mega != null)) { back(); return; }
  if (e.key !== 'Enter') return;
  const el = e.target;
  if (el.closest && el.closest('.app [role=button]') && el.tagName !== 'BUTTON') { el.click(); return; }
  if (el.id === 'q') { const v = el.value.trim(); if (v) { S.recent = [v].concat(S.recent.filter(x => x !== v)).slice(0, 6); } el.blur(); return; }
  if (el.tagName === 'INPUT' && el.dataset.form) {
    e.preventDefault();
    const pane = el.closest('.pane') || document;
    const all = $$('input[data-form],textarea[data-form]', pane);
    const nx = all[all.indexOf(el) + 1];
    if (nx) nx.focus(); else { const b = el.dataset.form === 'coupon' ? $('[data-act="coupon"]', pane) : $('.foot .btn,.pad .btn.block', pane); el.blur(); if (b) b.click(); }
  }
});
document.addEventListener('change', e => {
  const s = e.target.closest('select[data-sw="jump"]');
  if (s && s.value) { location.hash = jumpHref(JUMP[+s.value]); }
});
window.addEventListener('hashchange', () => { if (window.APV_SCREENS || location.hash === S.selfHash) return; readHash(); render(); });
window.addEventListener('resize', fit);

/* ── gestures: back swipe from the edge, pull to refresh ───────────────── */
let G0 = null;
document.addEventListener('pointerdown', e => {
  const glass = e.target.closest('#stage .glass');
  if (!glass || e.button > 0) { G0 = null; return; }
  const R = glass.getBoundingClientRect();
  const k = R.width / glass.offsetWidth || 1;
  const x = (e.clientX - R.left) / k, w = glass.offsetWidth;
  const fromStart = AR ? w - x < 24 : x < 24, fromEnd = AR ? x < 24 : w - x < 24;
  const body = e.target.closest('.body[data-ptr]');
  G0 = {x:e.clientX, y:e.clientY, k, glass, edge:fromStart ? 'start' : (fromEnd && isA()) ? 'end' : null, body:body && body.scrollTop <= 0 ? body : null, moved:false};
});
document.addEventListener('pointermove', e => {
  if (!G0) return;
  const dx = (e.clientX - G0.x) / G0.k, dy = (e.clientY - G0.y) / G0.k;
  if (!G0.moved && Math.hypot(dx, dy) < 8) return;
  G0.moved = true;
  const main = G0.glass.querySelector('.app .main');
  if (G0.edge && S.stacks[S.tab].length > 1 && Math.abs(dx) > Math.abs(dy)) {
    const inward = G0.edge === 'start' ? (AR ? -dx : dx) : (AR ? dx : -dx);
    G0.back = inward;
    if (main) {
      if (isA()) { const s = Math.max(.86, 1 - Math.max(0, inward) / 900); main.style.transform = `scale(${s})`; main.style.borderRadius = '28px'; main.style.overflow = 'hidden'; }
      else main.style.transform = `translateX(${(AR ? -1 : 1) * Math.max(0, inward)}px)`;
      main.style.transition = 'none';
    }
    let h = G0.glass.querySelector('.edgehint');
    if (!h) { h = document.createElement('div'); h.className = 'edgehint' + (G0.edge === 'end' ? ' end' : ''); h.innerHTML = backIco(); G0.glass.appendChild(h); }
    h.classList.toggle('go', inward > 70);
    return;
  }
  if (G0.body && dy > 0 && Math.abs(dy) > Math.abs(dx)) {
    G0.pull = dy;
    const p = G0.body.querySelector('.ptr');
    if (p) { p.style.height = Math.min(80, dy * .5) + 'px'; p.classList.toggle('go', dy > 110); }
  }
});
document.addEventListener('pointerup', () => {
  if (!G0) return;
  const g = G0; G0 = null;
  if (g.moved) swallowClick = true, setTimeout(() => { swallowClick = false; }, 0);
  const main = g.glass.querySelector('.app .main');
  const h = g.glass.querySelector('.edgehint'); if (h) h.remove();
  if (g.back != null) {
    if (main) { main.style.transition = ''; main.style.transform = ''; main.style.borderRadius = ''; }
    if (g.back > 70) back({noAnim:true});
    return;
  }
  if (g.pull != null) {
    const p = g.body.querySelector('.ptr');
    if (g.pull > 110 && p) {
      p.classList.add('spin'); p.style.height = '56px'; haptic('impact light');
      setTimeout(() => { render(); toast(t('Up to date')); }, 750);
    } else if (p) p.style.height = '0px';
  }
});
document.addEventListener('pointercancel', () => { G0 = null; });

/* ── test hook for tools/apv-shots.cjs and screens.html ────────────────── */
window.APV = {
  lang:LANG,
  jump:() => JUMP.map(j => ({id:j.id, g:j.g, label:j.label, title:t(j.label, {v:money(54)}), path:j.path, ctl:j.ctl || null, designs:!!j.designs, step:j.step || null})),
  devices:() => Object.keys(DEVKEYS),
  href:(id, o) => jumpHref(JUMP[id], o || {}),
  reset(){ try { sessionStorage.clear(); } catch {} seed(); Object.assign(S, {conc:0, qty:1, open:{}, bt:{}, sort:{}, filt:{}, more:{}, detail:{}, scroll:{}, q:'', qFrom:null, recent:[], brandQ:'', catQ:'', coupon:null, form:{}, ferr:{}, formFor:{}, sent:{}, pw:false, toast:null, lastOrder:null}); },
  beforePlace:() => (stackFor('/checkout') || {st:[]}).st.length,
  state:() => ({bb:S.bb, tm:S.tm, menu:S.menu, mega:S.mega, tab:S.tab, path:topE().path, depth:S.stacks[S.tab].length, stacks:Object.fromEntries(TABS.map(k => [k, S.stacks[k].map(x => x.path)])), bag:bagCount(), wish:[...S.wish], sheet:S.sheet, co:{pay:S.co.pay, authorised:S.co.authorised, emirate:(S.form.co || {}).emirate || ''}, sumOpen:!!S.co.sumOpen, bagLines:S.bag.length, orders:S.orders.length, lastOrder:S.lastOrder, scroll:{...S.scroll}, missing:[...missing]}),
  missing:() => [...missing],
  open(h){ APV.reset(); S.selfHash = h; history.replaceState(null, '', h); readHash(); render(); },
  render:() => render(),
};

/* ── start ─────────────────────────────────────────────────────────────── */
seed();
/* screens.html loads the same code for the jump list and the strings, and draws its own page */
if (!window.APV_SCREENS) {
  readHash();
  document.title = AR ? 'معاينة تطبيق كي بيوتي بليس' : 'K-Beauty Bliss app preview';
  render();
}
