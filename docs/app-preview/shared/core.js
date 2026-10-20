'use strict';
/* =========================================================================
   K-Beauty Bliss — native app preview (Lane APV2). Vanilla JS, no framework.
   One engine, two apps: en/index.html sets APV_LANG='en' and loads only
   English data; ar/index.html sets APV_LANG='ar' and loads the Arabic data
   and the Arabic string table. Strings in this file are English keys; the
   Arabic app looks each one up in window.APV_I18N, and a missing key is a
   console error the checker fails on.
   ========================================================================= */
const LANG = window.APV_LANG === 'ar' ? 'ar' : 'en';
const AR = LANG === 'ar';
const D = window.APV_DATA || {products:[], brands:[], categories:[], concerns:[], posts:[], articles:{}, banners:[], trust:[], settings:{}};
const DICT = window.APV_I18N || {};
/* tools/apv-data.cjs --split may ship photo paths only (im / g / n2): rebuild
   the site's img-cache URLs, so either form of data.js works. */
(function hydrate(){
  const IC = (w, p) => p ? (/^https?:/.test(p) ? p : `https://kbeautybliss.com/img-cache/${w}${p}`) : null;
  for (const p of D.products) {
    if (p.img || p.im == null) continue;
    p.img = IC(400, p.im); p.img200 = p.n2 ? p.img : IC(200, p.im);
    p.gallery = [p.im].concat(p.g || []).map(x => ({src:IC(800, x), thumb:IC(200, x)}));
  }
})();
const PL = D.products;
const P = Object.fromEntries(PL.map(p => [p.slug, p]));
const BR = Object.fromEntries(D.brands.map(b => [b.slug, b]));
const CAT = Object.fromEntries(D.categories.map(c => [c.k, c]));
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const SET = Object.assign({currency:'AED', vat:5, cod_fee:10, ship_uae:20, free_over_uae:199, ship_gcc:150, free_over_gcc:1600}, D.settings || {});

/* ── i18n ──────────────────────────────────────────────────────────────── */
const missing = new Set();
function t(key, vars){
  let s = AR ? DICT[key] : key;
  if (s == null) { if (AR && !missing.has(key)) { missing.add(key); console.error('i18n: no Arabic for “' + key + '”'); } s = key; }
  if (vars) s = s.replace(/\{(\w+)\}/g, (m, k) => vars[k] != null ? vars[k] : m);
  return s;
}
function num(n, dec){
  const o = {minimumFractionDigits: dec ?? (n % 1 ? 2 : 0), maximumFractionDigits: dec ?? 2};
  return new Intl.NumberFormat(AR && S.digits === 'arab' ? 'ar-AE-u-nu-arab' : 'en-US', o).format(n);
}
const r2 = v => Math.round(v * 100) / 100;
const money = v => AR ? `${num(r2(v))} د.إ` : `AED ${num(r2(v))}`;
const date = (iso, o) => new Intl.DateTimeFormat(AR ? (S.digits === 'arab' ? 'ar-AE-u-nu-arab' : 'ar-AE-u-nu-latn') : 'en-GB', o || {day:'numeric', month:'short', year:'numeric'}).format(new Date(iso + 'T12:00:00'));
/* Latin text that is allowed in the Arabic app carries data-x="<why>" so the
   checker can list each exception instead of guessing. */
const X = (why, s, cls = '') => AR ? `<bdi class="lat${cls ? ' ' + cls : ''}" dir="ltr" data-x="${why}">${s}</bdi>` : (cls ? `<span class="${cls}">${s}</span>` : s);
const LTR = s => `<bdi dir="ltr" class="ltr">${s}</bdi>`;
/* Text that comes from the site's data. In the Arabic app the site's own Arabic
   copy keeps Latin product terms (PDRN, SPF50+, BHA); each run is isolated and
   marked data-x="term" so it reads in the right order and the checker can list it. */
const LATIN_RUN = /[A-Za-z][A-Za-z0-9.+%&'’\-\/]*(?:[ \-]+[A-Za-z0-9][A-Za-z0-9.+%&'’\-\/]*)*/g;
function dt(s){
  s = String(s == null ? '' : s);
  if (!AR) return esc(s);
  let out = '', i = 0;
  s.replace(LATIN_RUN, (m, at) => { out += esc(s.slice(i, at)) + X('term', esc(m)); i = at + m.length; return m; });
  return out + esc(s.slice(i));
}

/* ── product helpers ───────────────────────────────────────────────────── */
const cur = p => p.sale ?? p.price;
const wasOf = p => p.was || (p.sale != null ? p.price : null);
const pct = p => { const w = wasOf(p); return w && w > cur(p) ? Math.round((w - cur(p)) / w * 100) : 0; };
const isLatin = s => /[A-Za-z]/.test(s) && !/[؀-ۿ]/.test(s);
/* The site names a product "Brand - Name"; the card shows the brand on its own line. */
function pn(p){
  const parts = p.name.split(' - ');
  return parts.length > 1 ? parts.slice(1).join(' - ') : p.name;
}
const bn = p => p.brand || '';
/* A brand name: Arabic when the site has one, else the Latin name marked as a brand. */
const brandTxt = s => (AR && isLatin(s)) ? X('brand', esc(s)) : dt(s);
const nameTxt = p => (AR && isLatin(pn(p))) ? X('name', esc(pn(p))) : dt(pn(p));
const gal = p => (p.gallery && p.gallery.length ? p.gallery : [{src:p.img, thumb:p.img200}]);
const firstBest = (PL.find(p => p.tags.includes('best') && gal(p).length >= 3 && p.revs && p.revs.length) || PL.find(p => gal(p).length >= 3) || PL[0] || {}).slug;
const tagged = (tag, n) => PL.filter(p => p.tags.includes(tag)).slice(0, n);
const catName = k => (CAT[k] || {}).name || k;

/* ── devices (logical size: pt on Apple, dp on Android) ────────────────── */
const DEV = {
  ip16: {name:'iPhone 16 Pro', os:'ios', w:402, h:874, sat:62, sab:34, r:55, unit:'pt', cls:'ip16', island:true},
  ipse: {name:'iPhone SE', os:'ios', w:375, h:667, sat:20, sab:0, r:0, unit:'pt', cls:'ipse'},
  ipad: {name:'iPad mini', os:'ios', w:744, h:1133, sat:24, sab:20, r:22, unit:'pt', cls:'ipad', rot:true},
  px9:  {name:'Pixel 9', os:'android', w:412, h:915, sat:40, sab:24, r:36, unit:'dp', cls:'px9', punch:true},
  flip: {name:'Galaxy Z Flip', os:'android', w:412, h:915, sat:40, sab:24, r:30, unit:'dp', cls:'flip', punch:true, posture:true},
  fold: {name:'Galaxy Z Fold', os:'android', w:344, h:882, sat:36, sab:24, r:26, unit:'dp', cls:'fold', punch:true, foldable:true},
  duo:  {name:'Surface Duo 2', os:'android', w:540, h:720, sat:28, sab:20, r:12, unit:'dp', cls:'duo', dual:true, hinge:28},
};
const DEVORDER = ['ip16', 'ipse', 'ipad', 'px9', 'flip', 'fold', 'duo'];
/* Named states of the switcher, used by the hash, the screens page and the checker. */
const DEVKEYS = {
  'ip16':{dev:'ip16'}, 'ipse':{dev:'ipse'}, 'ipad':{dev:'ipad'}, 'ipad-land':{dev:'ipad', land:true},
  'px9':{dev:'px9'}, 'flip':{dev:'flip'}, 'flip-tabletop':{dev:'flip', posture:'tabletop'},
  'fold-cover':{dev:'fold', folded:true}, 'fold-inner':{dev:'fold', folded:false}, 'duo':{dev:'duo'},
};

/* ── state ─────────────────────────────────────────────────────────────── */
const S = {
  design:'A', digits:'latn', os:'ios', dev:'ip16', folded:true, posture:'flat', land:false, zoom:'fit', embed:false,
  tab:'home', stacks:{}, scroll:{}, more:{}, detail:{}, sheet:null, toast:null, viewer:null, anim:null,
  bag:[], wish:new Set(), coupon:null, couponErr:null, couponDraft:'', recent:[], q:'',
  user:null, conc:0, ptab:'desc', qty:1, gi:0, bt:{}, open:{}, sort:{}, filt:{}, brandQ:'',
  notif:{orders:true, stock:true, drop:true, bag:false, offers:true, blog:false}, faceid:true, pw:false,
  form:{}, ferr:{}, co:null, orders:[], lastOrder:null, sent:{},
};
const store = {
  get(k, d){ try { const v = sessionStorage.getItem('apv-' + LANG + '-' + k); return v == null ? d : JSON.parse(v); } catch { return d; } },
  set(k, v){ try { sessionStorage.setItem('apv-' + LANG + '-' + k, JSON.stringify(v)); } catch {} },
};
const SARA = {name:'Sara Al Mansoori', first:'Sara', email:'sara@example.com', phone:'501234567'};
function savedAddresses(){
  return [
    {id:'home', label:'Home', name:SARA.name, phone:SARA.phone, emirate:'dubai', area:'dubai-marina', building:'Marina Gate 2', street:'Al Marsa Street', flat:'Apartment 1804, floor 18', landmark:'Next to Dubai Marina Mall', country:'AE', def:true},
    {id:'work', label:'Work', name:SARA.name, phone:SARA.phone, emirate:'abu-dhabi', area:'al-maryah', building:'Al Maryah Tower', street:'Al Falah Street', flat:'Floor 12, office 1204', landmark:'', country:'AE', def:false},
  ];
}
function defaultCheckout(){
  return {step:'bag', guest:false, email:'', addresses:savedAddresses(), addr:'home', del:'std', pay:'card', card:null, authorised:null, terms:true, gift:false};
}
function seed(){
  const pick = n => PL.filter(p => p.tags.includes('best') || p.tags.includes('trending'))[n];
  S.bag = store.get('bag', null) || [{slug:(pick(1) || PL[1] || {}).slug, qty:1}, {slug:(pick(4) || PL[4] || {}).slug, qty:2}].filter(x => x.slug && P[x.slug]);
  S.bag = S.bag.filter(x => P[x.slug]);
  S.wish = new Set((store.get('wish', null) || [pick(2), pick(5), pick(7), PL.find(p => p.tags.includes('under54'))].filter(Boolean).map(p => p.slug)).filter(s => P[s]));
  S.user = store.get('user', true) ? SARA : null;
  S.co = defaultCheckout();
  S.orders = baseOrders();
}
function persist(){ store.set('bag', S.bag); store.set('wish', [...S.wish]); store.set('user', !!S.user); }
function baseOrders(){
  const pickN = (i, n) => PL.slice(i, i + n).map(p => ({slug:p.slug, qty:1}));
  const tot = L => r2(L.reduce((a, b) => a + cur(P[b.slug]) * b.qty, 0) + 0);
  const o1 = pickN(3, 3), o2 = pickN(8, 2), o3 = pickN(20, 1);
  return [
    {id:'KBB-56214', d:'2026-10-18', st:'ship', items:o1, total:tot(o1), pay:'apple', addr:'home'},
    {id:'KBB-55870', d:'2026-09-27', st:'del', items:o2, total:tot(o2), pay:'card', addr:'home'},
    {id:'KBB-55102', d:'2026-08-30', st:'del', items:o3, total:r2(tot(o3) + SET.ship_uae), pay:'cod', addr:'work'},
  ];
}

/* ── bag maths ─────────────────────────────────────────────────────────── */
const bagCount = () => S.bag.reduce((a, b) => a + b.qty, 0);
/* the count the bag icons show: 0 on the "Bag · empty" preview, which draws an empty bag without
   emptying the demo bag (so the happy bag is sad there, as it will be in the app) */
const shownBag = () => { const r = route(); return r && /^\/bag(\?|$)/.test(r.path) && r.query.state === 'empty' ? 0 : bagCount(); };
function coAddress(){ const c = S.co; return c.addresses.find(a => a.id === c.addr) || c.addresses[0] || null; }
function bagTotals(o = {}){
  const sub = r2(S.bag.reduce((a, b) => a + cur(P[b.slug]) * b.qty, 0));
  const disc = S.coupon ? r2(sub * 0.10) : 0;
  const a = coAddress();
  const gcc = a && a.country && a.country !== 'AE';
  const free = gcc ? SET.free_over_gcc : SET.free_over_uae;
  let ship = sub - disc >= free ? 0 : (gcc ? SET.ship_gcc : SET.ship_uae);
  const cod = S.co && S.co.pay === 'cod' && !o.noCod ? SET.cod_fee : 0;
  const gift = S.co && S.co.gift && !o.noGift ? 15 : 0; // the site's "This order is a gift +AED 15"
  const total = r2(sub - disc + ship + cod + gift);
  const vat = r2(total / (1 + SET.vat / 100) * SET.vat / 100);
  return {sub, disc, ship, cod, gift, total, vat, free, gcc};
}
const inst = v => Math.ceil(v / 4 * 100) / 100;

/* ── UAE address data (emirate → areas), shared with the checkout ──────── */
const EMIRATES = [
  ['abu-dhabi', 'Abu Dhabi', ['al-maryah', 'Al Maryah Island'], ['al-reem', 'Al Reem Island'], ['khalifa-city', 'Khalifa City'], ['al-raha', 'Al Raha Beach'], ['saadiyat', 'Saadiyat Island'], ['mussafah', 'Mussafah'], ['al-ain', 'Al Ain']],
  ['dubai', 'Dubai', ['dubai-marina', 'Dubai Marina'], ['downtown', 'Downtown Dubai'], ['jvc', 'Jumeirah Village Circle'], ['business-bay', 'Business Bay'], ['al-barsha', 'Al Barsha'], ['deira', 'Deira'], ['mirdif', 'Mirdif'], ['jlt', 'Jumeirah Lake Towers'], ['palm', 'Palm Jumeirah'], ['silicon-oasis', 'Dubai Silicon Oasis']],
  ['sharjah', 'Sharjah', ['al-majaz', 'Al Majaz'], ['al-nahda-shj', 'Al Nahda'], ['al-khan', 'Al Khan'], ['muwaileh', 'Muwaileh'], ['aljada', 'Aljada']],
  ['ajman', 'Ajman', ['al-nuaimiya', 'Al Nuaimiya'], ['al-rashidiya', 'Al Rashidiya'], ['al-jurf', 'Al Jurf'], ['emirates-city', 'Emirates City']],
  ['uaq', 'Umm Al Quwain', ['al-salamah', 'Al Salamah'], ['al-raas', 'Al Raas'], ['al-ramlah', 'Al Ramlah']],
  ['rak', 'Ras Al Khaimah', ['al-nakheel', 'Al Nakheel'], ['al-hamra', 'Al Hamra Village'], ['mina-al-arab', 'Mina Al Arab'], ['khuzam', 'Khuzam']],
  ['fujairah', 'Fujairah', ['fujairah-city', 'Fujairah City'], ['dibba', 'Dibba Al Fujairah'], ['al-faseel', 'Al Faseel']],
];
const emirate = k => EMIRATES.find(e => e[0] === k);
const emirateName = k => { const e = emirate(k); return e ? t(e[1]) : ''; };
const areasOf = k => { const e = emirate(k); return e ? e.slice(2) : []; };
const areaName = (ek, ak) => { const a = areasOf(ek).find(x => x[0] === ak); return a ? t(a[1]) : ''; };
/* Text the customer typed (names, building, street). The demo customer's own
   words have an Arabic version in the string table; anything else she types in
   Latin letters is hers and is shown as typed (data-x="typed"). */
const alabel = l => AR ? t('addr:' + l) : l;
const ut = s => AR ? (DICT[s] || s) : s;
const utH = s => { const v = ut(s); return AR && isLatin(v) ? X('typed', esc(v)) : esc(v); };
const addrLine = (a, o = {}) => a ? [a.building, a.street, a.flat, o.short ? null : a.landmark, areaName(a.emirate, a.area), a.country && a.country !== 'AE' ? t(a.city || '') : emirateName(a.emirate)].filter(Boolean).map(utH).join(AR ? '، ' : ', ') : '';

/* ── router ────────────────────────────────────────────────────────────── */
/* A path names a screen; screens register a pattern. */
const SCREENS = [];
function scr(pattern, def){ const keys = []; const re = new RegExp('^' + pattern.replace(/:(\w+)/g, (m, k) => { keys.push(k); return '([^/]+)'; }) + '$'); SCREENS.push({pattern, re, keys, ...def}); }
function resolve(path){
  const [p, qs] = String(path || '/home').split('?');
  const clean = p.replace(/\/+$/, '') || '/home';
  for (const s of SCREENS) {
    const m = clean.match(s.re);
    if (m) { const params = {}; s.keys.forEach((k, i) => params[k] = decodeURIComponent(m[i + 1])); return {s, params, query:Object.fromEntries(new URLSearchParams(qs || '')), path:clean + (qs ? '?' + qs : '')}; }
  }
  return null;
}
/* Lane APV3: brands, offers and wish are tabs of the bottom-bar variants B to E (shared/header.js) */
const TABS = ['home', 'shop', 'search', 'bag', 'account', 'brands', 'offers', 'wish'];
const ROOT = {home:'/home', shop:'/categories', search:'/search', bag:'/bag', account:'/account', brands:'/brands', offers:'/products?tag=sale', wish:'/wishlist'};
const topE = () => { const st = S.stacks[S.tab]; return st[st.length - 1]; };
const route = () => resolve(topE().path);
