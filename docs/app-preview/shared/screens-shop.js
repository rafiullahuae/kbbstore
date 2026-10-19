'use strict';
/* =========================== SHOP SCREENS ================================ */
const CONCERN_CAT = {acne:'acne', hydration:'hydration', spots:'pigmentation', ageing:'anti-aging', sensitive:'sensitive', pores:'pores', dull:'hydration', sun:'sunscreens', oily:'oily'};
const concernName = c => c.short || c.name;
const isPhone = ctx => ctx.cls === 'compact';

/* ── Home ─────────────────────────────────────────────────────────────── */
function sbcBlock(){
  const C = D.concerns || [];
  const c = C[S.conc] || C[0];
  if (!c) return '';
  const items = c.items.map(s => P[s]).filter(Boolean);
  const shot = items[0];
  const target = CAT[CONCERN_CAT[c.k]] ? '/category/' + CONCERN_CAT[c.k] : '/products?concern=' + c.k;
  return `<section class="sec sbc" data-sec="sbc"><div class="sh"><h2>${t('Shop by Concern')}</h2></div>
  <div class="tabs" role="tablist">${C.map((x, i) => `<button class="tab2${i === S.conc ? ' on' : ''}" role="tab" aria-selected="${i === S.conc}" data-act="conc" data-v="${i}"><i>${cIco(x.k)}</i>${dt(concernName(x))}</button>`).join('')}</div>
  <div class="body2"><div class="panel" role="tabpanel"><div class="txt"><h3>${dt(c.name)}</h3><div class="sub">${dt(c.sub)}</div>
  <span class="tipl">${ico('sparkle')}${t('TIP')}</span><p>${dt(c.tip)}</p></div>
  ${shot ? `<span class="shot"><img src="${shot.img200}" alt="" loading="lazy"></span>` : ''}
  <button class="btn" ${go$(target)}>${t('View all')} · ${dt(concernName(c))}</button></div>
  <div class="rail2">${items.map(lcard).join('')}</div></div></section>`;
}
function bannerTarget(b){ const br = D.brands.find(x => (b.mobile + ' ' + b.desktop).toLowerCase().includes(x.slug.replace(/-/g, ''))) || D.brands.find(x => (b.mobile + ' ' + b.desktop).toLowerCase().includes(x.slug)); return br ? '/brand/' + br.slug : '/products?tag=trending'; }
function catTile(c){
  const img = c.banner || (P[c.items[0]] || {}).img;
  return `<button class="ctile" ${go$('/category/' + c.k)}><span class="ci"><img src="${img}" alt="" loading="lazy"${c.banner ? ' class="cover"' : ''}></span><span class="cn"><b class="clamp2">${dt(c.name)}</b><small>${t('{n} products', {n:num(c.items.length)})}</small></span></button>`;
}
function homeSections(){
  const B = D.banners || [], d = S.design;
  const bnr = `<div class="banner"><div class="bnr" data-dots="bd">${B.map((b, i) => `<a role="button" tabindex="0" ${go$(bannerTarget(b))} aria-label="${esc(b.alt) || t('Offer')}">${d === 'C' ? '' : `<img class="bm" src="${b.mobile}" alt="" ${i ? 'loading="lazy"' : ''}>`}<img class="bd" src="${b.desktop}" alt="" loading="lazy"></a>`).join('')}</div>
    <div class="dots" id="bd" aria-hidden="true">${B.map((_, i) => `<span class="${i ? '' : 'on'}"><i></i></span>`).join('')}</div></div>`;
  const TI = ['shield', 'truck', 'card', 'chat'];
  const trust = `<div class="trust">${(D.trust || []).map((x, i) => `<div><i>${ico(TI[i])}</i><span>${dt((x.t || []).join(' '))}</span></div>`).join('')}</div>`;
  const brands = `<div class="brands">${D.brands.slice(0, 16).map(b => `<button class="brand" ${go$('/brand/' + b.slug)}><i style="background:${brandColour(b.slug)}">${initials(b.name_en || b.name)}</i><span><b>${brandTxt(b.name)}</b><small>${t('{n} products', {n:num(b.count)})}</small></span></button>`).join('')}</div>`;
  const cats = `<div class="hs ctiles" style="--cw:132px">${D.categories.filter(c => c.parent === 'skincare' || !c.parent).slice(0, 10).map(catTile).join('')}</div>`;
  const blog = `<div class="hs" style="--cw:78%">${(D.posts || []).filter(x => bt(x, 'title')).slice(0, 5).map(x => `<button class="blog" ${go$('/blog/' + x.slug)}>${x.cover ? `<img src="${x.cover}" alt="" loading="lazy">` : ''}<span class="bt"><b class="clamp2">${esc(bt(x, 'title'))}</b><small>${date(x.date)}</small></span></button>`).join('')}</div>`;
  const search = `<button class="srch" data-tab="search"><span>${ico('search')}</span><span class="grow">${t('Search {n}+ Korean skincare products', {n:num(Math.floor((D.counts || {}).catalogue / 10) * 10 || 760)})}</span><span class="scan" ${go$('/scan')} aria-label="${t('Scan barcode')}">${ico('scan')}</span></button>`;
  let topH;
  if (d === 'C') topH = `<div class="hero">${search}<h1>${t('Glass skin starts here.')}</h1><div class="chips">${(D.concerns || []).slice(0, 6).map((c, i) => `<button class="chip" data-act="conc" data-v="${i}" data-scrollto="sbc">${dt(concernName(c))}</button>`).join('')}</div></div><div style="height:16px"></div>${bnr}`;
  else topH = `<div class="hsearch" style="background:${d === 'B' ? 'var(--blush)' : 'var(--cream)'}">${search}</div>${bnr}`;
  return [
    topH + trust,
    sbcBlock(),
    sec(t('Best Sellers'), rail(tagged('best', 10)), {more:'/products?tag=best'}),
    sec(t('Shop by category'), cats, {more:'/categories'}),
    sec(t('Brands'), brands, {cls:'cream2', more:'/brands'}),
    sec(t('Trending'), rail(tagged('trending', 10)), {more:'/products?tag=trending'}),
    sec(t('Under {v}', {v:money(54)}), rail(tagged('under54', 10)), {cls:'tint', more:'/products?tag=under54'}),
    sec(t('From the blog'), blog, {more:'/blog'}),
  ];
}
function brandColour(slug){ let h = 0; for (const c of slug) h = (h * 31 + c.charCodeAt(0)) % 360; return `hsl(${h} 60% 90%)`; }
const blogTitle = x => x.title;
function homeSkel(){
  return `<div style="padding:0 16px">${skel(46, '100%', 23)}</div><div style="height:12px"></div><div class="sk" style="aspect-ratio:4/5;border-radius:0"></div><div class="trust">${Array.from({length:4}, () => `<div>${skel(40, '40px', 20)}${skel(10, '80%')}</div>`).join('')}</div><div class="sec">${skel(22, '50%')}<div style="height:12px"></div><div style="display:flex;gap:8px;overflow:hidden">${Array.from({length:4}, () => skel(44, '110px', 22)).join('')}</div><div style="height:12px"></div>${skel(220, '100%', 20)}</div><div class="sec">${skel(22, '40%')}<div style="height:12px"></div><div style="display:flex;gap:12px;overflow:hidden">${Array.from({length:3}, () => `<div style="flex:0 0 148px">${skel(148, '148px', 14)}<div style="height:8px"></div>${skel(12, '60%')}<div style="height:6px"></div>${skel(14, '90%')}</div>`).join('')}</div></div>`;
}
scr('/home', {tab:'home', group:'Home', label:'Home', title:() => t('Home'),
  render(r, ctx){
    const st = r.query.state;
    const head = `<div class="hhead d${S.design}">${logo()}<button class="ib" ${go$('/wishlist')} aria-label="${t('Wishlist')}">${ico('heart')}${S.wish.size ? `<span class="dotb"></span>` : ''}</button><button class="ib" ${go$('/account/notifications')} aria-label="${t('Notifications')}">${ico('bell')}</button></div>`;
    let body;
    if (st === 'loading') body = homeSkel();
    else if (st === 'error') body = errState();
    else {
      const parts = homeSections();
      const cut = 3;
      const use = ctx.half === 'start' ? parts.slice(0, cut) : ctx.half === 'end' ? parts.slice(cut) : parts;
      body = `<div class="d${S.design}">${st === 'offline' ? offbar() : ''}${use.join('')}</div>`;
    }
    return {head: ctx.half === 'end' ? `<div class="hhead d${S.design} sub"><b>${t('More to explore')}</b></div>` : head, body, ptr:true,
      tt:`<div class="pad"><button class="srch" data-tab="search"><span>${ico('search')}</span><span class="grow">${t('Search products')}</span></button><div style="height:12px"></div><div class="chips">${(D.concerns || []).map((c, i) => `<button class="chip${i === S.conc ? ' on' : ''}" data-act="conc" data-v="${i}">${dt(concernName(c))}</button>`).join('')}</div></div>`};
  },
  dual:() => 'halves'});

/* ── Listings: one engine for category, brand, "all products", wishlist ─ */
const SORTS = [['pop', 'Most popular'], ['rating', 'Top rated'], ['low', 'Price: low to high'], ['high', 'Price: high to low']];
function listKey(r){ return r.path.split('&state=')[0]; }
function filt(key){ return S.filt[key] = S.filt[key] || {brands:[], max:0, conc:'', sale:false}; }
function applyList(key, base){
  const f = filt(key), so = S.sort[key] || 'pop';
  let L = base.slice();
  if (f.brands.length) L = L.filter(p => f.brands.includes(p.bslug));
  if (f.conc) { const c = (D.concerns || []).find(x => x.k === f.conc); const set = new Set(c ? c.items : []); const ck = CONCERN_CAT[f.conc]; L = L.filter(p => set.has(p.slug) || (p.cats || []).includes(ck)); }
  if (f.max) L = L.filter(p => cur(p) <= f.max);
  if (f.sale) L = L.filter(p => pct(p) > 0);
  if (so === 'low') L.sort((a, b) => cur(a) - cur(b));
  if (so === 'high') L.sort((a, b) => cur(b) - cur(a));
  if (so === 'rating') L.sort((a, b) => (b.rating * 1000 + b.reviews) - (a.rating * 1000 + a.reviews));
  return L;
}
const nFilters = key => { const f = filt(key); return f.brands.length + (f.max ? 1 : 0) + (f.conc ? 1 : 0) + (f.sale ? 1 : 0); };
function filterPanel(key, base, o = {}){
  const f = filt(key);
  const brands = [...new Set(base.map(p => p.bslug).filter(Boolean))].map(b => [b, base.filter(p => p.bslug === b).length]).sort((a, b) => b[1] - a[1]).slice(0, 10);
  return `${o.noConc ? '' : `<span class="lab" style="margin-top:4px">${t('Concern')}</span><div class="chips wrap">${(D.concerns || []).map(c => `<button class="chip${f.conc === c.k ? ' on' : ''}" data-act="fconc" data-k="${key}" data-v="${c.k}" aria-pressed="${f.conc === c.k}">${dt(concernName(c))}</button>`).join('')}</div>`}
    <span class="lab">${t('Price')}</span><div class="chips wrap">${[54, 100, 200].map(v => `<button class="chip${f.max === v ? ' on' : ''}" data-act="fmax" data-k="${key}" data-v="${v}" aria-pressed="${f.max === v}">${t('Up to {v}', {v:money(v)})}</button>`).join('')}</div>
    <div style="height:8px"></div>${cbxRow('fsale', key, f.sale, t('On sale only'))}
    ${o.noBrand || brands.length < 2 ? '' : `<span class="lab">${t('Brand')}</span><div class="list">${brands.map(([b, c]) => `<button class="li" data-act="fbrand" data-k="${key}" data-v="${b}" role="checkbox" aria-checked="${f.brands.includes(b)}"><span class="cbx${f.brands.includes(b) ? ' on' : ''}">${ico('check')}</span><span class="grow">${brandTxt((BR[b] || {}).name || b)}</span><span class="v">${num(c)}</span></button>`).join('')}</div>`}`;
}
function listing(r, ctx, base, o = {}){
  const key = listKey(r), st = r.query.state;
  const L = st === 'nomatch' ? [] : applyList(key, base);
  const so = S.sort[key] || 'pop', nf = nFilters(key);
  const wide = ctx.cls === 'expanded' && !ctx.dual && !ctx.half && !o.noPanel;
  const tools = `<div class="ltools" data-sticky="1"><span class="grow small muted" aria-live="polite">${t('{n} products', {n:num(L.length)})}</span><button class="chip sm" data-sheet="sort" data-k="${key}">${ico('sort')}${t(SORTS.find(x => x[0] === so)[1])}</button>${wide ? '' : `<button class="chip sm${nf ? ' on' : ''}" data-sheet="filter" data-k="${key}">${ico('filter')}${t('Filter')}${nf ? ` · ${num(nf)}` : ''}</button>`}</div>`;
  if (st === 'loading') return (o.head || '') + tools + `<div class="gpad">${gridSkel(6)}</div>`;
  if (st === 'error') return errState();
  const shown = Math.min(L.length, S.more[key] || 12);
  const sel = ctx.selected;
  const grid = !L.length
    ? `${empty('search', t('No products match these filters'), t('Try another brand or a higher price, or clear the filters.'), null)}<div style="display:flex;justify-content:center"><button class="btn ghost" data-act="clearf" data-k="${key}">${t('Clear filters')}</button></div>`
    : `<div class="grid">${L.slice(0, shown).map((p, i) => pcard(p, {eager:i < 4, sel})).join('')}</div>${shown < L.length
      ? `<div class="more-load" data-more="${esc(key)}" data-total="${L.length}" aria-live="polite">${gridSkel(2)}<p class="small muted" style="text-align:center;margin:12px 0 0">${t('Loading more…')} · ${num(shown)} / ${num(L.length)}</p></div>`
      : `<p class="small muted endnote">${t('You’ve seen all {n}', {n:num(L.length)})}</p>`}`;
  return `${st === 'offline' ? offbar() : ''}${o.head || ''}${tools}${wide ? `<div class="lwide"><aside class="fpanel" aria-label="${t('Filter')}"><div class="fph"><b>${t('Filter')}</b>${nf ? `<button class="lnk" data-act="clearf" data-k="${key}">${t('Clear')}</button>` : ''}</div>${filterPanel(key, base, o)}</aside><div class="gpad">${grid}</div></div>` : `<div class="gpad">${grid}</div>`}`;
}
const listDual = r => ({detail:true, def:() => { const L = listItems(r); return '/product/' + ((L[0] || {}).slug || firstBest); }, match:/^\/product\//, modes:['dual']});
function listItems(r){
  const m = r.path.match(/^\/(category|brand)\/([^?/]+)/);
  if (m && m[1] === 'category') return applyList(listKey(r), (CAT[m[2]] || {items:[]}).items.map(s => P[s]).filter(Boolean));
  if (m && m[1] === 'brand') return applyList(listKey(r), (BR[m[2]] || {items:[]}).items.map(s => P[s]).filter(Boolean));
  if (r.path.startsWith('/wishlist')) return [...S.wish].map(s => P[s]).filter(Boolean);
  return applyList(listKey(r), productsBase(r));
}

/* ── Category page ─────────────────────────────────────────────────────── */
function aboutBlock(id, text){
  if (!text) return '';
  const open = S.open[id];
  return `<div class="about${open ? ' open' : ''}"><p${open ? '' : ' class="clamp3"'}>${dt(text)}</p><button class="lnk sm" data-act="acc" data-v="${id}" aria-expanded="${!!open}">${t(open ? 'Read less' : 'Read more')}</button></div>`;
}
function subChips(c){
  let L = [], allK = null;
  if (c.k === 'skincare') L = D.categories.filter(x => x.parent === 'skincare');
  else if (c.parent === 'skincare') { L = D.categories.filter(x => x.parent === 'skincare'); allK = 'skincare'; }
  else if (c.parent === 'concerns') L = D.categories.filter(x => x.parent === 'concerns');
  else L = D.categories.filter(x => !x.parent && x.k !== c.k).concat([]);
  if (!L.length) return '';
  return `<div class="chips subc" role="list">${allK ? `<button class="chip" ${go$('/category/' + allK)} data-replace="1">${t('All {c}', {c:dt(catName(allK))})}</button>` : ''}${L.map(x => `<button class="chip${x.k === c.k ? ' on' : ''}" ${x.k === c.k ? 'aria-current="page"' : go$('/category/' + x.k) + ' data-replace="1"'}>${dt(x.name)}</button>`).join('')}</div>`;
}
scr('/category/:k', {tab:'shop', group:'Category', label:'Category page', chain:() => ['/categories'], title:r => catName(r.params.k),
  render(r, ctx){
    const c = CAT[r.params.k] || D.categories[0];
    const base = c.items.map(s => P[s]).filter(Boolean);
    const head = `<div class="cbanner">${c.banner ? `<picture><source media="(min-width:700px)" srcset="${c.banner_w || c.banner}"><img src="${c.banner}" alt="" class="cover"></picture>` : `<div class="cbg"></div>`}</div>
      <div class="chead"><h1 class="h1">${dt(c.name)}</h1>${aboutBlock('about-c-' + c.k, c.about)}</div>${subChips(c)}`;
    return {title:c.name, back:true, right:bagBtn(), body:listing(r, ctx, base, {head}), ptr:true, sk:listKey(r)};
  },
  dual:listDual});

/* ── Brand page ────────────────────────────────────────────────────────── */
scr('/brand/:b', {tab:'shop', group:'Brand', label:'Brand page', chain:() => ['/brands'], title:r => (BR[r.params.b] || {}).name || '',
  render(r, ctx){
    const b = BR[r.params.b] || D.brands[0];
    const base = b.items.map(s => P[s]).filter(Boolean);
    const head = `<div class="bbanner">${b.banner ? `<picture><source media="(min-width:700px)" srcset="${b.banner_w || b.banner}"><img src="${b.banner}" alt="" class="cover"></picture>` : `<div class="cbg" style="background:${brandColour(b.slug)}"></div>`}</div>
      <div class="bhead"><span class="blogo" style="background:${brandColour(b.slug)}" aria-hidden="true">${initials(b.name_en || b.name)}</span><div class="grow"><h1 class="h1">${brandTxt(b.name)}</h1><span class="small muted">${t('{n} products on K-Beauty Bliss', {n:num(b.count)})}</span></div><button class="ib" data-act="share" aria-label="${t('Share')}">${ico('share')}</button></div>
      <div class="chead" style="padding-top:0">${aboutBlock('about-b-' + b.slug, b.about)}</div>`;
    return {title:b.name, titleH:brandTxt(b.name), back:true, right:bagBtn(), body:listing(r, ctx, base, {head, noBrand:true}), ptr:true, sk:listKey(r)};
  },
  dual:listDual});

/* ── All products (View all from home) ─────────────────────────────────── */
function productsBase(r){
  const tag = r.query.tag, c = r.query.concern;
  if (tag) return PL.filter(p => p.tags.includes(tag));
  if (c) { const x = (D.concerns || []).find(y => y.k === c); return x ? x.items.map(s => P[s]).filter(Boolean) : PL; }
  return PL;
}
const PTITLE = {best:'Best Sellers', trending:'Trending', under54:'Under {v}'};
scr('/products', {tab:'shop', group:'Shop', label:'All products', chain:() => ['/categories'], title:r => r.query.tag ? t(PTITLE[r.query.tag] || 'Shop all', {v:money(54)}) : t('Shop all'),
  render(r, ctx){
    const title = r.query.tag ? t(PTITLE[r.query.tag] || 'Shop all', {v:money(54)}) : t('Shop all');
    return {title, large:true, back:true, right:bagBtn(), body:listing(r, ctx, productsBase(r)), ptr:true, sk:listKey(r)};
  },
  dual:listDual});

/* ── All categories / all brands (the Shop tab) ────────────────────────── */
const seg = on => `<div class="seg" role="tablist"><button role="tab" aria-selected="${on === 'c'}" class="${on === 'c' ? 'on' : ''}" ${go$('/categories')} data-replace="1">${t('Categories')}</button><button role="tab" aria-selected="${on === 'b'}" class="${on === 'b' ? 'on' : ''}" ${go$('/brands')} data-replace="1">${t('Brands')}</button></div>`;
scr('/categories', {tab:'shop', group:'Shop', label:'All categories', title:() => t('Shop'),
  render(r, ctx){
    const G = [[t('Skincare'), D.categories.filter(c => c.k === 'skincare' || c.parent === 'skincare')], [t('Shop by concern'), D.categories.filter(c => c.parent === 'concerns')], [t('More from K-Beauty Bliss'), D.categories.filter(c => !c.parent && c.k !== 'skincare')]];
    const body = `<div class="pad0">${seg('c')}</div><button class="srch inbody" data-tab="search"><span>${ico('search')}</span><span class="grow">${t('Search products')}</span></button>
      <div class="allp"><button class="li" ${go$('/products')}>${ico('grid')}<span class="grow"><b class="lt">${t('Shop all products')}</b><span class="small muted">${t('{n} products', {n:num(PL.length)})}</span></span>${ico('chevR', 'chev flip')}</button></div>
      ${G.filter(g => g[1].length).map(([h, L]) => `<div class="grp">${h}</div><div class="cgrid">${L.map(catTile).join('')}</div>`).join('')}`;
    return {title:t('Shop'), large:true, right:bagBtn(), body, ptr:true};
  },
  dual:() => ({detail:true, def:() => '/category/skincare', match:/^\/category\//, modes:['dual', 'expanded']})});
scr('/brands', {tab:'shop', group:'Shop', label:'All brands', title:() => t('Brands'),
  render(r, ctx){
    return {title:t('Brands'), large:true, right:bagBtn(), body:`<div class="pad0">${seg('b')}</div><label class="srch inbody field" for="bq"><span>${ico('search')}</span><input id="bq" type="search" value="${esc(S.brandQ)}" placeholder="${t('Search {n} brands', {n:num(D.brands.length)})}" autocomplete="off" enterkeyhint="search" aria-label="${t('Search brands')}"></label><div id="blist">${brandList()}</div>`};
  },
  dual:() => ({detail:true, def:() => '/brand/' + (D.brands[0] || {}).slug, match:/^\/brand\//, modes:['dual', 'expanded']})});
function brandList(){
  const q = S.brandQ.trim().toLowerCase();
  const L = D.brands.filter(b => !q || (b.name + ' ' + (b.name_en || '')).toLowerCase().includes(q)).slice().sort((a, b) => (a.name_en || a.name).localeCompare(b.name_en || b.name));
  if (!L.length) return `<div class="state" style="min-height:0;padding-top:24px"><div class="ic">${ico('search')}</div><h2>${t('No brand called “{q}”', {q:esc(S.brandQ)})}</h2><p>${t('Check the spelling, or browse the list.')}</p></div>`;
  const groups = {};
  L.forEach(b => { const k = (b.name_en || b.name)[0].toUpperCase(); (groups[k] = groups[k] || []).push(b); });
  return Object.entries(groups).map(([k, bs]) => `<div class="grp ltr-grp">${X('letter', k)}</div><div class="list" style="margin:0 16px">${bs.map(b => `<button class="li" ${go$('/brand/' + b.slug)}><span class="bav" style="background:${brandColour(b.slug)}">${initials(b.name_en || b.name)}</span><span class="grow"><b class="lt">${brandTxt(b.name)}</b></span><span class="v">${num(b.count)}</span>${ico('chevR', 'chev flip')}</button>`).join('')}</div>`).join('');
}

/* ── Product ───────────────────────────────────────────────────────────── */
const UNIT_AR = {ml:'مل', g:'غ', ea:'قطعة', pcs:'قطعة', sheet:'ورقة', sheets:'ورقة'};
function sizeOf(p){ const m = (p.name_en || p.name).match(/(\d+(?:\.\d+)?)\s?(ml|g|ea|pcs|sheets?)\b/i); if (!m) return t('Standard'); const u = m[2].toLowerCase(); return AR ? `${num(+m[1])} ${UNIT_AR[u]}` : `${m[1]} ${u}`; }
function secByHead(p, words){ return (p.sec || []).find(s => words.some(w => s.h.toLowerCase().includes(w))); }
function ptab(p, k){
  if (k === 'desc') {
    const parts = (p.sec || []).filter(s => !/how to|طريقة|about|عن العلامة/i.test(s.h));
    return `${p.desc ? `<p>${dt(p.desc)}</p>` : AR ? `<p class="muted">${t('The full description of this product is being translated. The key facts are below.')}</p>` : ''}${parts.map(s => `<h3 class="pth">${dt(s.h)}</h3><ul>${s.items.map(i => `<li>${dt(i)}</li>`).join('')}</ul>`).join('')}`;
  }
  if (k === 'ing') return p.inci ? `<p class="inci">${X('inci', esc(p.inci))}</p><p class="tiny muted">${t('Full ingredient list (INCI), as printed on the pack.')}</p>` : `<p class="muted">${t('The ingredient list for this product is being added.')}</p>`;
  if (k === 'how') { const s = secByHead(p, ['how to', 'طريقة']); return s ? `<ul>${s.items.map(i => `<li>${dt(i)}</li>`).join('')}</ul>` : `<ul><li>${t('After cleansing and toner, take a small amount.')}</li><li>${t('Pat gently into face and neck until absorbed.')}</li><li>${t('Morning and evening. Follow with sunscreen in the day.')}</li></ul>`; }
  if (k === 'ship') return `<ul><li>${t('UAE: {a}, free over {b}.', {a:money(SET.ship_uae), b:money(SET.free_over_uae)})}</li><li>${t('Gulf countries: {a}, free over {b}.', {a:money(SET.ship_gcc), b:money(SET.free_over_gcc)})}</li><li>${t('Cash on delivery in the UAE only (+{a}).', {a:money(SET.cod_fee)})}</li></ul>`;
  if (k === 'ret') return `<p>${t('Unopened items can be returned within 7 days. Start a return from Orders → the order → Help, and we arrange the pickup.')}</p>`;
  return '';
}
function reviewsBlock(p){
  const R = p.revs || [];
  if (!p.reviews && !R.length) return `<p class="muted small">${t('No reviews yet. Be the first.')}</p><button class="btn ghost" ${go$('/contact')}>${t('Write a review')}</button>`;
  const dist = [5, 4, 3, 2, 1].map(s => R.filter(r => r.rating === s).length);
  return `<div class="revsum"><div><div class="big">${num(p.rating || 5, 1)}</div>${stars(p.rating || 5)}<div class="tiny muted" style="text-align:center">${t('{n} reviews', {n:num(p.reviews || R.length)})}</div></div>
  <div class="bars">${dist.map((n, i) => `<div><span>${num(5 - i)}</span><i><b style="width:${R.length ? Math.round(n / R.length * 100) : 0}%"></b></i><span>${num(n)}</span></div>`).join('')}</div></div>
  ${R.slice(0, 4).map(r => `<div class="rev"><div class="rh"><b>${AR && isLatin(r.name) ? X('ugc', esc(r.name)) : esc(r.name)}</b>${stars(r.rating)}${r.verified ? `<span class="ver">${ico('check')}${t('Verified')}</span>` : ''}${r.date ? `<span class="tiny muted">${date(r.date)}</span>` : ''}</div><p${AR && isLatin(r.text) ? ' dir="ltr" class="ugc"' : ''}>${AR && isLatin(r.text) ? X('ugc', esc(r.text)) : esc(r.text)}</p>
  ${r.photos && r.photos.length ? `<div class="ph">${r.photos.map(u => `<button data-act="rphoto" data-v="${esc(u)}" aria-label="${t('Review photo')}"><img src="${u}" alt="" loading="lazy"></button>`).join('')}</div>` : ''}</div>`).join('')}
  ${AR && R.some(r => isLatin(r.text)) ? `<p class="tiny muted">${t('Reviews are shown in the language they were written in.')}</p>` : ''}`;
}
function boughtTogether(p){
  const others = PL.filter(x => x.slug !== p.slug && !(x.cats || []).some(c => (p.cats || []).includes(c)) && x.bslug !== p.bslug).slice(0, 2);
  const L = [p, ...others];
  const sel = L.filter(x => S.bt[x.slug] !== false);
  const sum = sel.reduce((a, x) => a + cur(x), 0);
  return `<div class="bt">${L.map(x => `<div class="it"><button data-act="bt" data-p="${x.slug}" role="checkbox" aria-checked="${S.bt[x.slug] !== false}" aria-label="${esc(pn(x))}"><span class="cbx${S.bt[x.slug] !== false ? ' on' : ''}">${ico('check')}</span></button><button class="bti" ${x === p ? 'disabled' : go$('/product/' + x.slug)} aria-label="${esc(pn(x))}"><img src="${x.img200}" alt="" loading="lazy"></button><div style="min-width:0"><div class="nm clamp2">${x === p ? `<b>${t('This item')}:</b> ` : ''}${nameTxt(x)}</div><span class="price small">${money(cur(x))}</span></div></div>`).join('')}</div>
  <div class="btsum"><span>${t('Total')} <b class="price">${money(sum)}</b></span><button class="btn soft" data-act="addall" data-p="${p.slug}"${sel.length ? '' : ' disabled'}>${t('Add {n} to bag', {n:num(sel.length)})}</button></div>`;
}
const PTABS = [['desc', 'Description'], ['ing', 'Ingredients'], ['how', 'How to use'], ['ship', 'Delivery'], ['ret', 'Returns']];
const expressPay = () => isA() ? `<button class="paybtn gpay" data-act="express"><span>${t('Buy with')}</span>&nbsp;${X('pay', '<span class="g">G</span>&nbsp;Pay')}</button>` : `<button class="paybtn" data-act="express">${t('Buy with')}&nbsp;${APPLE}${X('pay', 'Pay')}</button>`;
function productParts(p, r){
  const st = r.query.state;
  if (st === 'loading') return {gal:`<div class="sk" style="aspect-ratio:1;border-radius:0"></div>`, info:`<div class="pad">${skel(12, '30%')}<div style="height:10px"></div>${skel(22, '90%')}<div style="height:8px"></div>${skel(22, '60%')}<div style="height:16px"></div>${skel(30, '40%')}<div style="height:16px"></div>${skel(56, '100%', 14)}</div>`, rest:''};
  const G = gal(p), d = S.design;
  const galH = `<div class="gal"><div class="gs" data-dots="gthumbs-${p.slug}">${G.map((g, i) => `<button data-act="viewer" data-v="${i}" aria-label="${t('Open photo {n}', {n:num(i + 1)})}"><img src="${g.src}" alt="" ${i ? 'loading="lazy"' : ''}></button>`).join('')}</div><span class="zh">${ico('zoom')}${t('Pinch to zoom')}</span><span class="cnt">${LTR(num(1) + ' / ' + num(G.length))}</span></div>
    <div class="thumbs" id="gthumbs-${p.slug}">${G.map((g, i) => `<button class="${i ? '' : 'on'}" data-act="gi" data-v="${i}" aria-label="${t('Photo {n}', {n:num(i + 1)})}"><img src="${g.thumb}" alt="" loading="lazy"></button>`).join('')}</div>`;
  const priceH = `${priceBlock(p)}<button class="bnpl" data-sheet="bnpl"><span class="lg"><span class="tabby" data-x="pay">tabby</span><span class="tamara" data-x="pay">tamara</span></span><span class="grow">${t('4 interest-free payments of {v}. No fees. Sharia-compliant.', {v:`<b>${money(inst(cur(p)))}</b>`})}</span>${ico('chevR', 'flip')}</button>`;
  const cat = (p.cats || [])[0];
  const info = `<div class="pinfo"><button class="br" ${go$('/brand/' + p.bslug)}>${brandTxt(bn(p))}${ico('chevR', 'flip')}</button><h1>${nameTxt(p)}</h1>
    <div class="rt">${p.reviews ? `${stars(p.rating)}<b>${num(p.rating, 1)}</b><button data-act="toreviews">${t('{n} reviews', {n:num(p.reviews)})}</button>` : `<span>${t('No reviews yet')}</span>`}<span aria-hidden="true">·</span><span style="color:var(--ok)">${p.stock === 'instock' ? t('In stock') : t('Few left')}</span></div>
    ${d === 'C' ? `<div class="pcard">${priceH}${expressPay()}</div>` : priceH}
    <span class="lab">${t('Size')}</span><div class="vars"><button class="chip on" aria-pressed="true">${esc(sizeOf(p))}</button></div>
    <span class="lab">${t('Quantity')}</span>${qtyStep('pq', null, S.qty)}
    ${cat ? `<button class="crumb" ${go$('/category/' + cat)}>${ico('grid')}${t('More in {c}', {c:dt(catName(cat))})}${ico('chevR', 'flip')}</button>` : ''}</div>`;
  const tabsH = d === 'B'
    ? PTABS.map(([k, l]) => { const open = S.open['acc-' + k] ?? k === 'desc'; return `<div class="acc${open ? ' open' : ''}"><button data-act="acc" data-v="acc-${k}" data-def="${k === 'desc' ? 1 : 0}" aria-expanded="${!!open}">${t(l)}${ico('chevD')}</button>${open ? `<div class="pt">${ptab(p, k)}</div>` : ''}</div>`; }).join('')
    : `<div class="ptabs" role="tablist">${PTABS.map(([k, l]) => `<button role="tab" class="${S.ptab === k ? 'on' : ''}" aria-selected="${S.ptab === k}" data-act="ptab" data-v="${k}">${t(l)}</button>`).join('')}</div><div class="pt" role="tabpanel">${ptab(p, S.ptab)}</div>`;
  const also = PL.filter(x => x.slug !== p.slug && (x.bslug === p.bslug || (x.cats || []).some(c => (p.cats || []).includes(c)))).slice(0, 10);
  const rest = `${tabsH}<section class="sec" id="reviews"><div class="sh"><h2>${t('Reviews')}</h2></div>${reviewsBlock(p)}</section>
    ${sec(t('Bought together'), boughtTogether(p))}
    ${sec(t('You may also like'), rail(also.length ? also : PL.slice(10, 18)))}`;
  return {gal:galH, info, rest};
}
function productFoot(p, r){
  if (r.query.state === 'loading' || r.query.state === 'error') return '';
  const fav = S.wish.has(p.slug);
  return `<div class="pbar"><button class="fv${fav ? ' on' : ''}" data-act="wish" data-p="${p.slug}" aria-pressed="${fav}" aria-label="${t(fav ? 'Remove from wishlist' : 'Save to wishlist')}">${ico('heart')}</button><button class="btn" data-act="add" data-p="${p.slug}" data-q="1">${ico('bag')}${t('Add to bag')} · ${money(cur(p) * S.qty)}</button></div>`;
}
scr('/product/:slug', {tab:'shop', group:'Product', label:'Product', chain:r => { const p = P[r.params.slug]; return p && p.cats && p.cats[0] ? ['/categories', '/category/' + p.cats[0]] : ['/categories']; }, title:r => pn(P[r.params.slug] || P[firstBest]),
  render(r, ctx){
    const p = P[r.params.slug] || P[firstBest];
    if (r.query.state === 'error') return {title:'', back:true, body:errState(), noTabs:true};
    const X2 = productParts(p, r), d = S.design;
    const right = `<button class="ib" data-act="share" aria-label="${t('Share')}">${ico('share')}</button>${bagBtn()}`;
    let body;
    if (ctx.half === 'start') body = `<div class="d${d} pgal-only">${X2.gal}</div>`;
    else if (ctx.half === 'end') body = `<div class="d${d}">${X2.info}${X2.rest}</div>`;
    else if (ctx.tabletop) body = `<div class="d${d}">${X2.gal}</div>`;
    else body = `<div class="d${d}">${r.query.state === 'offline' ? offbar() : ''}<div class="pdp"><div class="pdp-g">${X2.gal}</div><div class="pdp-i">${X2.info}</div></div>${X2.rest}</div>`;
    const tt = `<div class="pad ttp"><div class="row2" style="align-items:flex-start"><div class="grow"><div class="small muted">${brandTxt(bn(p))}</div><b class="clamp2" style="font-size:16px">${nameTxt(p)}</b></div><span class="price" style="font-size:20px">${money(cur(p))}</span></div><div style="height:10px"></div><div class="row2">${qtyStep('pq', null, S.qty)}<span class="grow small muted">${t('4 payments of {v}', {v:money(inst(cur(p)))})}</span></div><div class="thumbs" style="padding:12px 0 0">${gal(p).map((g, i) => `<button class="${i ? '' : 'on'}" data-act="gi" data-v="${i}" aria-label="${t('Photo {n}', {n:num(i + 1)})}"><img src="${g.thumb}" alt="" loading="lazy"></button>`).join('')}</div></div>`;
    return {title:'', back:true, right: ctx.half === 'start' ? '' : right, body, foot: ctx.half === 'start' ? '' : productFoot(p, r), noTabs:true, tt, sk:'p-' + p.slug + (ctx.half || '')};
  },
  dual:() => 'halves'});

/* ── Search ────────────────────────────────────────────────────────────── */
const POP = ['Anua', 'PDRN', 'sunscreen', 'COSRX', 'Beauty of Joseon', 'toner pad'];
/* the Arabic app searches in Arabic: each popular search has its Arabic query */
const popQ = x => AR ? (DICT['q:' + x] || x) : x;
function searchHits(q){
  const words = q.toLowerCase().split(/\s+/).filter(Boolean);
  const hay = p => (p.name + ' ' + (p.name_en || '') + ' ' + p.brand + ' ' + (BR[p.bslug] || {}).name_en).toLowerCase();
  const prods = PL.filter(p => words.every(w => hay(p).includes(w)));
  const brands = D.brands.filter(b => words.every(w => (b.name + ' ' + (b.name_en || '')).toLowerCase().includes(w))).slice(0, 3);
  const cats = D.categories.filter(c => words.every(w => (c.name + ' ' + (c.name_en || '')).toLowerCase().includes(w))).slice(0, 3);
  return {prods, brands, cats};
}
function searchResults(ctx = {}){
  const q = S.q.trim();
  if (!q) return `<div class="pad" style="padding-top:4px">${S.recent.length ? `<div class="sh"><h2 class="h3">${t('Recent')}</h2><button class="more" data-act="clearrecent">${t('Clear')}</button></div><div class="chips wrap">${S.recent.map(x => `<button class="chip" data-act="q" data-v="${esc(x)}">${ico('clock')}${utH(x)}</button>`).join('')}</div><div style="height:16px"></div>` : ''}
    <div class="sh"><h2 class="h3">${t('Popular searches')}</h2></div><div class="list">${POP.map(x => `<button class="li" data-act="q" data-v="${esc(popQ(x))}">${ico('search')}<span class="grow">${dt(popQ(x))}</span>${ico('chevR', 'chev flip')}</button>`).join('')}</div>
    <div class="sh" style="margin-top:20px"><h2 class="h3">${t('Shop by category')}</h2></div><div class="chips wrap">${D.categories.filter(c => c.parent === 'skincare').map(c => `<button class="chip" ${go$('/category/' + c.k)}>${dt(c.name)}</button>`).join('')}</div>
    <div style="height:16px"></div><button class="btn block soft" ${go$('/scan')}>${ico('scan')}${t('Scan a barcode in the shop')}</button></div>`;
  const {prods, brands, cats} = searchHits(q);
  if (!prods.length && !brands.length && !cats.length) return `<div class="state" style="min-height:0;padding-top:28px"><div class="ic">${ico('search')}</div><h2>${t('No results for “{q}”', {q:utH(S.q)})}</h2><p>${t('Check the spelling, or try a brand or a concern.')}</p></div><div class="pad"><div class="sh"><h2 class="h3">${t('Popular searches')}</h2></div><div class="chips wrap">${POP.slice(0, 4).map(x => `<button class="chip" data-act="q" data-v="${esc(popQ(x))}">${dt(popQ(x))}</button>`).join('')}</div></div>`;
  return `<div class="spad">${brands.length || cats.length ? `<div class="chips wrap" style="margin-bottom:12px">${brands.map(b => `<button class="chip" ${go$('/brand/' + b.slug)}>${ico('tag')}${brandTxt(b.name)}</button>`).join('')}${cats.map(c => `<button class="chip" ${go$('/category/' + c.k)}>${ico('grid')}${dt(c.name)}</button>`).join('')}</div>` : ''}
    <div class="grp" style="padding:4px 2px 8px">${t('Products')} · ${num(prods.length)}</div>${ctx.cls !== 'compact' ? `<div class="grid">${prods.slice(0, 24).map(p => pcard(p, {sel:ctx.selected})).join('')}</div>` : `<div class="list">${prods.slice(0, 24).map(p => `<button class="li sr" ${go$('/product/' + p.slug)}><img src="${p.img200}" alt="" loading="lazy"><span class="grow"><span class="small muted ell" style="display:block">${brandTxt(bn(p))}</span><span class="clamp2 nm">${nameTxt(p)}</span></span><span class="price small">${money(cur(p))}</span></button>`).join('')}</div>`}</div>`;
}
scr('/search', {tab:'search', group:'Search', label:'Search', title:() => t('Search'),
  render(r, ctx){
    if (r.query.q != null && r.query.q !== S.qFrom) { S.q = r.query.q; S.qFrom = r.query.q; }
    const field = `<div class="sfield"><label class="srch field" for="q"><span>${ico('search')}</span><input id="q" type="search" value="${esc(S.q)}" placeholder="${t('Products, brands, concerns')}" autocomplete="off" enterkeyhint="search" aria-label="${t('Search')}" aria-controls="sres">${S.q ? `<button class="ib clr" data-act="q" data-v="" aria-label="${t('Clear search')}">${ico('close')}</button>` : `<button class="ib clr" ${go$('/scan')} aria-label="${t('Scan barcode')}">${ico('scan')}</button>`}</label></div>`;
    return {title:t('Search'), large:true, body:`${field}<div id="sres" aria-live="polite">${searchResults(ctx)}</div>`};
  },
  dual:r => ({detail:true, def:() => { const h = S.q.trim() ? searchHits(S.q).prods : []; return '/product/' + ((h[0] || {}).slug || firstBest); }, match:/^\/product\//, modes:['dual']})});
scr('/scan', {tab:'search', group:'Search', label:'Barcode scanner', chain:() => [], title:() => t('Scan barcode'),
  render(){
    return {nobar:true, noTabs:true, dark:true, raw:`<div class="scanv"><div class="vt"><button class="ib" data-act="back" aria-label="${t('Close')}">${ico('close')}</button><b style="font-size:17px">${t('Scan barcode')}</b><button class="ib" data-act="torch" aria-label="${t('Torch')}">${ico('flash')}</button></div><div style="display:flex;align-items:center;justify-content:center"><div class="vf"><i></i><i></i><i></i><i></i><b></b></div></div><div class="vb"><p>${t('Point at the barcode on the box')}</p><p class="tiny" style="color:#cbbcc3">${t('The camera is used only while this screen is open. Nothing is recorded.')}</p><div class="row2"><button class="btn" ${go$('/product/' + firstBest)} data-replace="1" style="min-width:0">${t('Simulate a match')}</button></div></div></div>`};
  }});

/* ── Wishlist ──────────────────────────────────────────────────────────── */
scr('/wishlist', {tab:'account', group:'Account', label:'Wishlist', chain:() => [], title:() => t('Wishlist'),
  render(r, ctx){
    const L = [...S.wish].map(s => P[s]).filter(Boolean);
    const st = r.query.state;
    const body = st === 'loading' ? `<div class="gpad">${gridSkel(4)}</div>` : !L.length || st === 'empty' ? empty('heart', t('Save what you love'), t('Tap the heart on any product. We’ll tell you when the price drops.'), t('Discover products'), '/categories')
      : `<div class="gpad" style="padding-top:4px"><div class="note" style="margin-bottom:14px">${ico('bell')}<span>${t('Price-drop alerts are on for your wishlist.')}</span></div><div class="grid">${L.map(p => pcard(p, {eager:true, sel:ctx.selected})).join('')}</div><div style="height:16px"></div><button class="btn block soft" data-act="wish2bag">${ico('bag')}${t('Move all to bag')}</button></div>`;
    return {title:t('Wishlist'), large:true, back:true, right:bagBtn(), body};
  },
  dual:r => [...S.wish].length ? listDual(r) : null});
