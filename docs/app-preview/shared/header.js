'use strict';
/* =========================================================================
   THE WEBSITE'S HEADER AND MENUS, AND THE BOTTOM-BAR VARIANTS (Lane APV3)

   The owner: "the header style and height etc i need exactly the same as the
   site. and also with language switch same. need the same search box etc."
   So the header, the phone menu (the drawer behind the ☰) and the menu bar
   with its mega panels are not redrawn here: they are the website's own
   markup (en|ar/site.js) and its own rules (en|ar/site.css), captured from
   kbeautybliss.com by tools/apv-site.cjs. This file only wires them to the
   app: links become app routes, the cart and wishlist badges count the bag,
   the language switch opens the other app, the search box is the app's
   search field, and the menus open and close.

   Each device frame is its own viewport (site.css turns the site's @media
   widths into @container app queries), so a phone shows the site's phone
   header and its drawer, the Fold inner and iPad portrait (673 / 744) show
   what the site shows at that width (the same header and drawer: the site
   switches to its menu bar above 1000), and iPad landscape (1133) shows the
   site's computer header with the menu bar and mega panels. The navigation
   rail is gone. "Tablet menu: menu bar" in the preview chooser shows the
   site's menu bar on the tablets as well, for the owner to compare.
   ========================================================================= */
const SITEMK = window.APV_SITE || {header:'', mmenu:'', annc:'', bodyClass:''};
const SITE_HEADER = SITEMK.header
  .replace(/<button class="id-burger /, '<button data-act="menu" class="id-burger ');
const SITE_MENU = SITEMK.mmenu
  .replace(/class="id-mmenu mmenu /, 'class="id-mmenu mmenu on ')
  .replace(/<button class="id-mmx /, '<button data-act="menuclose" class="id-mmx ')
  .replace(/data-mm-close=""/g, 'data-act="menuclose" aria-hidden="true"')
  .replace(/<button class="mm-it mm-par"/g, '<button class="mm-it mm-par" data-act="mmpar" aria-expanded="false"');
const BB = ['A', 'B', 'C', 'D', 'E'];
/* "More (tab)" is the tab's own key (Arabic: المزيد; "More" elsewhere means "more of") */
const tl = k => t(k).replace(/ \(tab\)$/, '');
const pref = {
  get(k, d, ok){ try { const v = localStorage.getItem('apv-' + k); return ok(v) ? v : d; } catch { return d; } },
  set(k, v){ try { localStorage.setItem('apv-' + k, v); } catch {} },
};
S.bb = pref.get('bb', 'A', v => BB.includes(v));
S.tm = pref.get('tm', 'site', v => v === 'site' || v === 'bar');
S.menu = false; S.mega = null; S.mmNode = null; S.mmQ = '';

/* ── where the site header shows ───────────────────────────────────────── */
/* On every shop, account and content screen, as on the website. Not on the
   checkout steps (the site's checkout has its own focused header too, and the
   app keeps its step bar there), not on full-screen moments (splash, first run,
   unlock, update, order placed, scanner, photo viewer). */
function siteOn(r, R){
  if (!SITEMK.header || R.raw || R.dark || (R.bare && R.nobar)) return false;
  return !/^\/(checkout|start)(\/|\?|$)/.test(r.path) && r.s.pattern !== '/scan';
}
function siteHTML(d){
  const cls = sizeClass(d.w);
  const drawer = S.menu && d.w <= 900;
  const k = `kh ${SITEMK.bodyClass}${drawer ? ' menu-open' : ''}${S.tm === 'bar' && cls === 'medium' ? ' tm-bar' : ''} hbb-${S.bb}`;
  return `<div class="${k}" dir="${AR ? 'rtl' : 'ltr'}" lang="${LANG}" data-layer="site">${SITE_HEADER}${drawer ? `<div class="mscrim on" data-act="menuclose" aria-hidden="true"></div>${SITE_MENU}` : ''}</div>`;
}
/* the site's delivery strip, at the top of Home (the site shows it under 900) */
const siteStrip = () => SITEMK.annc ? `<div class="kh" dir="${AR ? 'rtl' : 'ltr'}">${SITEMK.annc}</div>` : '';

/* after each render: counts, the other app's link, the search field, open panels */
function wireSite(){
  const n = shownBag(), w = S.wish.size;
  const r = route(), onSearch = r.s.pattern === '/search';
  const curPath = topE().path;
  $$('#stage .kh').forEach(kh => {
    $$('.id-cartCt', kh).forEach(b => { b.textContent = num(n); b.style.display = n ? 'grid' : 'none'; });
    $$('.id-kbbWishCt', kh).forEach(b => { b.textContent = num(w); b.style.display = w ? '' : 'none'; });
    $$('[data-lang]', kh).forEach(a => { a.href = `${OTHER.href}#${curPath}${curPath.includes('?') ? '&' : '?'}device=${devKey()}${S.bb !== 'A' ? '&bb=' + S.bb : ''}${S.tm === 'bar' ? '&tm=bar' : ''}`; });
    const bu = $('[data-act="menu"]', kh); if (bu) bu.setAttribute('aria-expanded', String(!!S.menu));
    const si = $('.search-in', kh), inp = si && $('input', si);
    if (inp) {
      inp.setAttribute('enterkeyhint', 'search');
      if (onSearch) { inp.id = 'q'; inp.value = S.q; inp.setAttribute('aria-controls', 'sres'); }
      else { si.dataset.tab = 'search'; si.setAttribute('role', 'button'); }
    }
    $$('.navitem', kh).forEach((it, i) => { it.classList.toggle('kopen', i === S.mega); const a = $('[data-act="mega"]', it); if (a) a.setAttribute('aria-expanded', String(i === S.mega)); });
    $$('.mm-node', kh).forEach((it, i) => { it.classList.toggle('on', i === S.mmNode); const b = $('.mm-par', it); if (b) b.setAttribute('aria-expanded', String(i === S.mmNode)); });
    const f = $('.id-mmFilter', kh); if (f) { f.value = S.mmQ; if (S.mmQ) mmFilter(kh); }
  });
}
/* the site's own "Search the menu…" field: hides what does not match */
function mmFilter(kh){
  const q = S.mmQ.trim().toLowerCase();
  let shown = 0;
  $$('.mm-body > .mm-it, .mm-node', kh).forEach(x => {
    if (x.classList.contains('mm-node')) {
      const kids = $$('.mm-si', x); let any = false;
      kids.forEach(k => { const ok = !q || k.textContent.toLowerCase().includes(q); k.hidden = !ok; any = any || ok; });
      const self = !q || $('.mm-par', x).textContent.toLowerCase().includes(q);
      if (self && q) kids.forEach(k => { k.hidden = false; });
      x.hidden = !(self || any); x.classList.toggle('on', !!q && any && !self || (!q && $$('.mm-node', kh).indexOf(x) === S.mmNode));
      if (!x.hidden) shown++;
    } else { const ok = !q || x.textContent.toLowerCase().includes(q); x.hidden = !ok; if (ok) shown++; }
  });
  const e = $('.id-mmEmpty', kh); if (e) e.hidden = shown > 0;
}

const ACT_EXTRA = ({
  menu:() => { S.menu = true; S.mmNode = null; S.mmQ = ''; S.mega = null; haptic('impact light'); render(); const m = $('#stage .mmenu'); if (m) m.focus({preventScroll:true}); },
  menuclose:() => { S.menu = false; render(); },
  mmpar:(el) => {
    const kh = el.closest('.kh'), nodes = $$('.mm-node', kh), node = el.closest('.mm-node'), i = nodes.indexOf(node);
    S.mmNode = S.mmNode === i ? null : i;
    nodes.forEach((x, j) => { x.classList.toggle('on', j === S.mmNode); const b = $('.mm-par', x); if (b) b.setAttribute('aria-expanded', String(j === S.mmNode)); });
    haptic('selection');
  },
  mega:(el) => {
    const kh = el.closest('.kh'), items = $$('.navitem', kh), i = items.indexOf(el.closest('.navitem'));
    S.mega = S.mega === i ? null : i;
    items.forEach((x, j) => { x.classList.toggle('kopen', j === S.mega); const a = $('[data-act="mega"]', x); if (a) a.setAttribute('aria-expanded', String(j === S.mega)); });
  },
  more:() => { S.sheet = S.sheet === 'more' ? null : 'more'; S.sheetSide = 'l'; haptic('impact light'); render(); },
});

/* ── the bottom bar: five variants for the owner to compare (phones only) ─ */
/* [tab, icon, label, extra]; a tab with its own root screen keeps its own stack */
const BBDEF = {
  A:[['home', 'home', 'Home'], ['shop', 'shop', 'Shop'], ['search', 'search', 'Search'], ['bag', 'bag', 'Bag'], ['account', 'user', 'Account']],
  B:[['home', 'home', 'Home'], ['shop', 'cats', 'Categories'], ['brands', 'award', 'Brands'], ['offers', 'pct', 'Offers'], ['account', 'user', 'Account']],
  C:[['home', 'home', 'Home'], ['shop', 'shop', 'Shop'], ['wish', 'heart', 'Wishlist'], ['bag', 'bag', 'Bag'], ['account', 'user', 'Account']],
  /* the owner's pick (20 October 2026): the happy bag raised in the middle, no label, and More */
  D:[['home', 'home', 'Home'], ['shop', 'cats', 'Categories'], ['bag', 'bag', 'Bag', 'raised happy'], ['account', 'user', 'Account'], ['more', 'dots', 'More (tab)']],
  E:[['home', 'home', 'Home'], ['shop', 'shop', 'Shop'], ['offers', 'pct', 'Offers'], ['bag', 'bag', 'Bag'], ['more', 'dots', 'More (tab)']],
};
const BBNOTE = {
  A:'Now: Home · Shop · Search · Bag · Account',
  B:'Home · Categories · Brands · Offers · Account (the bag is in the header)',
  C:'Home · Shop · Wishlist · Bag · Account (search is the header box)',
  D:'Home · Categories · happy Bag · Account · More (the wishlist is in the header)',
  E:'Home · Shop · Offers · Bag · More (brands, wishlist, orders, blog…)',
};
const MORE = [['/brands', 'award', 'Brands'], ['/wishlist', 'heart', 'Wishlist'], ['/account/orders', 'box', 'Orders'], ['/categories', 'sparkle', 'Skin concerns'], ['/blog', 'doc', 'Blog'], ['/contact', 'chat', 'Contact us'], ['/account/language', 'globe', 'Language & region'], ['/account/notifications', 'bell', 'Settings']];
function moreSheet(){
  return `<div class="sheet" role="dialog" aria-label="${tl('More (tab)')}"><div class="grab"></div><div class="sh2"><div></div><b>${tl('More (tab)')}</b><div class="e"><button class="ib" data-act="close" aria-label="${t('Close')}">${ico('close')}</button></div></div><div class="sb2"><div class="mgrid">${MORE.map(([p, i, l]) => `<button class="mtile" ${go$(p)}${p === '/wishlist' && S.wish.size ? ` data-n="${num(S.wish.size)}"` : ''}><span class="mi">${ico(i)}</span><span>${t(l)}</span></button>`).join('')}</div></div></div>`;
}

/* ── the preview chooser (outside the device frame) ────────────────────── */
function chooserHTML(){
  const B = (k, v, l, on, title) => `<button data-${k}="${v}" class="${on ? 'on' : ''}" aria-pressed="${!!on}"${title ? ` title="${esc(title)}"` : ''}>${l}</button>`;
  return `<div class="cr"><span>${t('Bottom bar (phones)')}</span>${BB.map(x => B('bb', x, X('dev', x), S.bb === x, t(BBNOTE[x]))).join('')}</div><p class="cn">${t(BBNOTE[S.bb])}</p>
    <div class="cr"><span>${t('Tablet menu')}</span>${B('tm', 'site', t('As the site'), S.tm === 'site')}${B('tm', 'bar', t('Menu bar'), S.tm === 'bar')}</div>`;
}
function paintChooser(){
  if (S.embed) return;
  let c = document.getElementById('bbsw');
  if (!c) { c = document.createElement('aside'); c.id = 'bbsw'; c.className = 'bbsw'; c.setAttribute('aria-label', t('Preview options')); document.body.appendChild(c); }
  c.innerHTML = chooserHTML();
}
document.addEventListener('click', e => {
  const b = e.target.closest('#bbsw [data-bb], #bbsw [data-tm]');
  if (!b) return;
  if (b.dataset.bb) { S.bb = b.dataset.bb; pref.set('bb', S.bb); if (S.sheet === 'more') S.sheet = null; }
  if (b.dataset.tm) { S.tm = b.dataset.tm; pref.set('tm', S.tm); S.mega = null; }
  if (window.APV_SCREENS) { paintChooser(); if (window.APV_SCREENS_DRAW) window.APV_SCREENS_DRAW(); return; }
  render();
});
