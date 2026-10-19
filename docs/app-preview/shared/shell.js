'use strict';
/* =========================== SHELL, NAVIGATION, EVENTS =================== */
S.formFor = {}; S.animW = {}; S.qFrom = null; S.areaQ = '';
const sizeClass = w => w < 600 ? 'compact' : w < 840 ? 'medium' : 'expanded';
function dev(){
  const d = {...DEV[S.dev]};
  if (S.dev === 'fold' && !S.folded) Object.assign(d, {w:673, h:841, r:22, crease:'v', inner:true});
  if (S.dev === 'ipad' && S.land) Object.assign(d, {w:1133, h:744});
  if (S.dev === 'flip') d.crease = 'h';
  return d;
}
const devKey = () => S.dev === 'fold' ? (S.folded ? 'fold-cover' : 'fold-inner') : S.dev === 'flip' && S.posture === 'tabletop' ? 'flip-tabletop' : S.dev === 'ipad' && S.land ? 'ipad-land' : S.dev;
const reduced = () => window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ── stacks ────────────────────────────────────────────────────────────── */
function resetStacks(){ for (const k of TABS) S.stacks[k] = [{path:ROOT[k]}]; S.tab = 'home'; }
function stackFor(path){
  const r = resolve(path); if (!r) return null;
  const tab = r.s.tab || 'home';
  const chain = (r.s.chain ? r.s.chain(r) : []).filter(p => p !== ROOT[tab] && p !== r.path);
  const st = [{path:ROOT[tab]}].concat(chain.map(p => ({path:p})));
  if (r.path !== ROOT[tab]) st.push({path:r.path});
  return {tab, st};
}
function titleOf(path){ const r = resolve(path); if (!r) return ''; try { return r.s.title ? r.s.title(r) : ''; } catch { return ''; } }

/* ── which panes: one, a list | detail pair, two halves, or a companion ─ */
function layoutFor(r, d){
  const cls = sizeClass(d.w), dual = !!d.dual;
  if (!dual && cls !== 'expanded') return {mode:'single'};
  const mode = dual ? 'dual' : 'expanded';
  const dv = r.s.dual ? r.s.dual(r) : null;
  if (dv === 'halves') return dual ? {mode:'halves'} : {mode:'single'};
  if (dv && dv.detail && dv.modes.includes(mode)) return {mode:'pair', start:r.path, end:S.detail[r.path] || dv.def(), host:r.path, match:dv.match};
  if (r.s.list) {
    const lp = r.s.list(r), lr = resolve(lp), dv2 = lr && lr.s.dual ? lr.s.dual(lr) : null;
    if (dv2 && dv2.detail && dv2.modes.includes(mode)) return {mode:'pair', start:lp, end:r.path, host:lp, match:dv2.match, topIsDetail:true};
  }
  return dual ? {mode:'companion'} : {mode:'single'};
}

/* ── one pane ──────────────────────────────────────────────────────────── */
function bar(o){
  const st = S.stacks[S.tab];
  const prev = st.length > 1 ? titleOf(st[st.length - 2].path) : '';
  const lbl = prev && prev.length <= 14 ? prev : t('Back');
  const left = o.back ? `<button class="back" data-act="back" aria-label="${t('Back')}">${backIco()}${!isA() ? `<span>${esc(lbl)}</span>` : ''}</button>` : '';
  const titleH = o.titleH || esc(o.title || '');
  return `<header class="bar${o.line ? ' hl' : ''}${o.large && !isA() ? ' lt' : ''}" data-bar="1"><div class="row"><div class="s">${left}</div><div class="t">${titleH}</div><div class="e">${o.right || ''}</div></div>${o.extra || ''}</header>`;
}
function renderScreen(path, ctx){
  const r = resolve(path) || resolve('/home');
  let R;
  try { R = r.s.render(r, ctx) || {}; } catch (e) { console.error(e); R = {title:'', body:errState()}; }
  return {r, R};
}
function paneHTML(path, L, ctx, o = {}){
  const {r, R} = renderScreen(path, ctx);
  const sk = (R.sk || r.path) + '|' + (ctx.half || o.slot || '');
  if (R.raw) return `<section class="pane nobar" data-layer="${L}" data-slot="${o.slot || 'main'}" data-screen="${esc(r.s.pattern)}">${R.raw}</section>`;
  const stack = S.stacks[S.tab];
  const canBack = !o.noBack && R.back !== false && stack.length > 1 && (o.slot !== 'end');
  const showLarge = R.large && !isA();
  const head = R.head || (R.nobar ? '' : bar({title:R.title, titleH:R.titleH, large:showLarge, back:canBack && (R.back || stack.length > 1), right:R.right, extra:R.extra, line:R.line}));
  const large = showLarge ? `<h1 class="large">${R.titleH || esc(R.title)}</h1>` : '';
  const foot = R.foot ? `<footer class="foot" data-layer="${L}-foot">${R.foot}</footer>` : '';
  const pairAttr = o.match ? ` data-match="${esc(o.match.source)}" data-host="${esc(o.host)}"` : '';
  if (ctx.tabletop) return {R, head, large, foot, sk};
  return `<section class="pane${head ? '' : ' nobar'}${R.dark ? ' dark' : ''}" data-layer="${L}" data-slot="${o.slot || 'main'}" data-screen="${esc(r.s.pattern)}"${pairAttr}>${head ? `<div data-layer="${L}-bar" class="pbar0">${head}</div>` : ''}${R.head2 || ''}<div class="body" data-layer="${L}-body" data-sk="${esc(sk)}"${R.ptr ? ' data-ptr="1"' : ''}>${R.ptr ? `<div class="ptr" aria-hidden="true"><i></i></div>` : ''}${large}${R.body || ''}</div>${foot}${R.overlay || ''}</section>`;
}

/* ── navigation bar / rail ─────────────────────────────────────────────── */
/* The bottom bar of the chosen variant (shared/header.js, BBDEF). Phones only:
   from 600 wide the website's header and menus do the navigating, as on the site. */
const TABDEF = () => BBDEF[S.bb] || BBDEF.A;
function tabbar(){
  const n = bagCount(), w = S.wish.size;
  return `<nav class="tabbar bb-${S.bb}" data-layer="nav" aria-label="${t('Main')}">${TABDEF().map(([k, i, l, x]) => {
    const on = k === 'more' ? S.sheet === 'more' : S.tab === k;
    const c = k === 'bag' ? n : k === 'wish' ? w : 0;
    const act = k === 'more' ? `data-act="more" aria-expanded="${S.sheet === 'more'}"` : `data-tab="${k}" aria-current="${on ? 'page' : 'false'}"`;
    return `<button class="tab${on ? ' on' : ''}${x ? ' ' + x : ''}" ${act}>${isA() || x ? `<span class="pill">${ico(i)}</span>` : ico(i)}<span class="tbl">${tl(l)}</span>${c ? `<span class="bdg">${num(c)}</span>` : ''}</button>`;
  }).join('')}</nav>`;
}

/* ── the app inside one screen ─────────────────────────────────────────── */
function shellHTML(d, o = {}){
  const r = route();
  const lay = o.lay || layoutFor(r, d);
  const cls = sizeClass(d.w);
  const base = {cls, dual:!!d.dual};
  /* the website's header on top of the start screen (shared/header.js) */
  const R00 = renderScreen(o.only || r.path, {...base, half:o.half || null}).R;
  const site = o.slot !== 'end' && siteOn(resolve(o.only || r.path) || r, R00);
  base.site = site;
  let main, R0;
  if (o.only) {
    const ctx = {...base, half:o.half || null, inPair:!!o.pair, selected:o.selected};
    main = paneHTML(o.only, 'p0', ctx, {slot:o.slot, noBack:o.noBack, match:o.match, host:o.host});
    R0 = renderScreen(o.only, ctx).R;
  } else if (lay.mode === 'pair') {
    const sel = lay.end;
    main = `<div class="split">${paneHTML(lay.start, 'p0', {...base, inPair:true, selected:sel}, {slot:'start', match:lay.match, host:lay.host})}${paneHTML(sel, 'p1', {...base, inPair:true}, {slot:'end', noBack:true})}</div>`;
    R0 = renderScreen(r.path, {...base}).R;
  } else {
    main = paneHTML(r.path, 'p0', {...base}, {});
    R0 = renderScreen(r.path, {...base}).R;
  }
  const bare = R0.bare;
  const showTabs = !R0.noTabs && !bare && !o.noTabs;
  return `<div class="shell${showTabs ? '' : ' notabs'}${bare ? ' bare' : ''}${site ? ' hassite' : ''}">${site ? siteHTML(d) : ''}<main class="main">${main}</main>${showTabs ? tabbar() : ''}</div>`;
}
function overlayHTML(){
  let h = '';
  if (S.sheet) h += `<div class="ovl${S.sheet === 'delconfirm' ? ' center' : ''}${S.dev === 'flip' && S.posture === 'tabletop' ? ' tt' : ''}" data-layer="sheet"><div class="scrim" data-act="close"></div>${sheetHTML(S.sheet)}</div>`;
  if (S.viewer) h += viewerHTML();
  if (S.toast) h += `<div class="toast" role="status" data-layer="toast">${ico('check')}<span class="grow">${S.toast.msg}</span>${S.toast.tab ? `<button data-tab="${S.toast.tab}">${S.toast.label}</button>` : ''}</div>`;
  return h;
}
function viewerHTML(){
  const v = S.viewer;
  const G = v.photos || gal(P[v.slug] || P[firstBest]).map(g => g.src);
  const i = Math.min(v.i || 0, G.length - 1);
  return `<div class="viewer" data-layer="viewer" role="dialog" aria-label="${t('Photos')}"><div class="vt"><button class="ib" data-act="closeviewer" aria-label="${t('Close')}">${ico('close')}</button><span class="small">${LTR(num(i + 1) + ' / ' + num(G.length))}</span><button class="ib" data-act="share" aria-label="${t('Share')}">${ico('share')}</button></div><div class="vs" data-vs="1">${G.map(u => `<div><img src="${u}" alt=""></div>`).join('')}</div><div class="vb">${G.length > 1 ? `<div class="thumbs">${G.map((u, j) => `<button class="${j === i ? 'on' : ''}" data-act="vi" data-v="${j}" aria-label="${t('Photo {n}', {n:num(j + 1)})}"><img src="${u}" alt=""></button>`).join('')}</div>` : ''}${t('Pinch or double-tap to zoom · swipe down to close')}</div></div>`;
}
function appHTML(inner, overlays, d, o = {}){
  return `<div class="app ${isA() ? 'android' : 'ios'}" dir="${AR ? 'rtl' : 'ltr'}" lang="${LANG}" data-cls="${sizeClass(d.w)}" style="--toastb:${o.toastb || 'calc(var(--sab) + 72px)'}">${inner}${overlays || ''}</div>`;
}
function statusBar(d){
  const sig = '<svg viewBox="0 0 18 12"><rect x="0" y="8" width="3" height="4" rx="1"/><rect x="5" y="5" width="3" height="7" rx="1"/><rect x="10" y="2.5" width="3" height="9.5" rx="1"/><rect x="15" y="0" width="3" height="12" rx="1"/></svg>';
  const wifi = '<svg viewBox="0 0 16 12"><path d="M8 11.5 5.6 9a3.4 3.4 0 0 1 4.8 0zM3.2 6.6a6.8 6.8 0 0 1 9.6 0l-1.2 1.2a5.1 5.1 0 0 0-7.2 0zM.8 4.2a10.2 10.2 0 0 1 14.4 0L14 5.4a8.5 8.5 0 0 0-12 0z"/></svg>';
  const bat = '<svg viewBox="0 0 27 12"><rect x=".5" y=".5" width="22" height="11" rx="3" fill="none" stroke="currentColor" opacity=".4"/><rect x="2" y="2" width="17" height="8" rx="1.6"/><rect x="24" y="4" width="2" height="4" rx="1" opacity=".4"/></svg>';
  return d.os === 'android' ? `<div class="sb android"><span>9:41</span><span class="sbi">${wifi}${sig}${bat}</span></div>` : `<div class="sb ${d.cls}"><span>9:41</span><span class="sbi">${sig}${wifi}${bat}</span></div>`;
}
const companion = () => `<section class="pane nobar" data-layer="p1"><div class="compan">${logo()}<p>${t('Authentic Korean skincare, delivered across the UAE.')}</p><p class="tiny">${t('Second screen: kept clear on purpose. Nothing sits under the hinge.')}</p></div></section>`;
function frameHTML(){
  const d = dev(), r = route();
  const {R} = renderScreen(r.path, {cls:sizeClass(d.w), dual:!!d.dual});
  const dark = R.dark || S.viewer;
  const vars = `--w:${d.w}px;--h:${d.h}px;--r:${d.r}px;--sat:${d.sat}px;--sab:${d.sab}px${dark ? ';--sbc:#fff;--hic:#fff' : ''}`;
  const furniture = () => `${statusBar(d)}${d.island ? '<div class="island"></div>' : ''}${d.punch ? '<div class="punch"></div>' : ''}${d.sab ? `<div class="homeind${d.os === 'android' ? ' android' : ''}"></div>` : ''}`;
  const label = `<div class="lbl">${X('dev', esc(d.name))}${S.dev === 'fold' ? ' · ' + t(S.folded ? 'cover screen' : 'inner screen') : ''}${S.dev === 'flip' && S.posture === 'tabletop' ? ' · ' + t('Tabletop') : ''}${S.dev === 'ipad' && S.land ? ' · ' + t('Landscape') : ''} — ${X('dev', `${d.w} × ${d.h} ${d.unit}`)} · ${t(sizeClass(d.w))}</div>`;
  if (d.dual) {
    const lay = layoutFor(r, d);
    let L, Rh;
    const ovL = S.sheetSide !== 'r';
    if (lay.mode === 'pair') {
      L = appHTML(shellHTML(d, {only:lay.start, slot:'start', pair:true, selected:lay.end, match:lay.match, host:lay.host}), ovL ? overlayHTML() : '', d);
      Rh = appHTML(shellHTML(d, {only:lay.end, slot:'end', pair:true, noBack:true, noTabs:true}), ovL ? '' : overlayHTML(), d, {toastb:'calc(var(--sab) + 84px)'});
    } else if (lay.mode === 'halves') {
      L = appHTML(shellHTML(d, {only:r.path, half:'start', slot:'start'}), ovL ? overlayHTML() : '', d);
      Rh = appHTML(shellHTML(d, {only:r.path, half:'end', slot:'end', noBack:true, noTabs:true}), ovL ? '' : overlayHTML(), d, {toastb:'calc(var(--sab) + 84px)'});
    } else {
      L = appHTML(shellHTML(d, {lay:{mode:'single'}}), overlayHTML(), d);
      Rh = appHTML(`<div class="shell notabs bare"><main class="main">${companion()}</main></div>`, '', d);
    }
    return `<div class="device duo" style="${vars};--hg:${d.hinge}px" data-dev="duo">${label}<div class="glass" data-pane="start">${furniture()}${L}</div><div class="hinge" data-hinge="1"></div><div class="glass" data-pane="end">${furniture()}${Rh}</div></div>`;
  }
  if (S.dev === 'flip' && S.posture === 'tabletop') {
    let inner;
    if (R.raw) inner = `<div class="shell notabs bare"><main class="main">${paneHTML(r.path, 'p0', {cls:'compact'})}</main></div>`;
    else {
      const ttSite = siteOn(r, R);
      const T = paneHTML(r.path, 'p0', {cls:'compact', tabletop:true, site:ttSite});
      const showTabs = !R.noTabs && !R.bare;
      const ctl = T.R.tt || `<div class="pad ttgen"><b class="ell" style="font-size:17px">${T.R.titleH || esc(T.R.title || brandWord())}</b><p class="small muted" style="margin:2px 0 10px">${t('Half-folded: the page is above, the controls are here.')}</p><div class="row2"><button class="btn soft grow" data-act="page" data-v="-1">${ico('chevU')}${t('Page up')}</button><button class="btn soft grow" data-act="page" data-v="1">${ico('chevD')}${t('Page down')}</button></div></div>`;
      const sk = T.sk;
      inner = `<div class="shell tts"><div class="pane tt-top${ttSite ? ' hassite' : ''}" data-layer="p0top">${ttSite ? siteHTML(d) : ''}${T.head ? `<div data-layer="p0-bar" class="pbar0">${T.head}</div>` : ''}${T.R.head2 || ''}<div class="body" data-layer="p0-body" data-sk="${esc(sk)}"${T.head || ttSite ? '' : ' style="padding-top:var(--sat)"'}>${T.large}${T.R.body || ''}</div></div><div class="tt-gap"></div><div class="pane tt-bottom" data-layer="p0bot"><div class="tt-ctl" data-layer="p0-ctl">${ctl}</div>${T.foot}${showTabs ? tabbar() : `<div style="height:var(--sab);flex:0 0 auto"></div>`}</div></div>`;
    }
    return `<div class="device tabletop ${d.cls}" style="${vars}" data-dev="flip">${label}<div class="glass">${furniture()}${appHTML(inner, overlayHTML(), d, {toastb:'calc(50% + 16px)'})}<div class="tt-fold" data-fold="1"></div></div></div>`;
  }
  const crease = d.crease === 'v' ? ' crease-v' : d.crease === 'h' ? ' crease-h' : '';
  const kind = S.dev === 'ipse' ? 'ipse' : S.dev === 'ipad' ? 'ipad' : '';
  return `<div class="device ${kind} ${d.os}" style="${vars}" data-dev="${S.dev}">${label}<div class="glass${crease}">${furniture()}${appHTML(shellHTML(d), overlayHTML(), d)}</div></div>`;
}

/* ── preview chrome (outside the phone) ────────────────────────────────── */
const OTHER = AR ? {href:'../en/index.html', label:'Open the English app'} : {href:'../ar/index.html', label:'Open the Arabic app'};
function swHTML(){
  const B = (k, v, l, on) => `<button data-sw="${k}" data-v="${v}" class="${on ? 'on' : ''}" aria-pressed="${!!on}">${l}</button>`;
  const r = route();
  const devs = DEVORDER.filter(k => DEV[k].os === S.os).map(k => B('dev', k, X('dev', DEV[k].name), S.dev === k)).join('');
  const extra = S.dev === 'fold' ? B('fold', S.folded ? 'unfold' : 'fold', S.folded ? t('Unfold') + ' ↔' : t('Fold') + ' ↔', false) : S.dev === 'flip' ? B('posture', S.posture === 'flat' ? 'tabletop' : 'flat', S.posture === 'flat' ? t('Tabletop') : t('Flat'), S.posture === 'tabletop') : S.dev === 'ipad' ? B('land', S.land ? '0' : '1', S.land ? t('Portrait') : t('Landscape'), false) : '';
  const groups = [...new Set(JUMP.map(j => j.g))];
  const curPath = topE().path;
  return `<span class="ttl">${LOTUS.replace('<svg', '<svg class="lgx" style="width:30px;height:23px"').replace('class="lgx-f"', 'fill="#D94A76" class="lgx-f"').replace('class="lgx-o"', 'fill="none" stroke="#E5567E" stroke-width="20" stroke-linecap="round" class="lgx-o"')}${t('App preview')}</span>
  <div class="sg"><span>${t('System')}</span>${B('os', 'ios', X('dev', 'iOS'), S.os === 'ios')}${B('os', 'android', X('dev', 'Android'), S.os === 'android')}</div>
  <div class="sg"><span>${t('Device')}</span>${devs}${extra}</div>
  <div class="sg"><span>${t('Design')}</span>${['A', 'B', 'C'].map(x => B('design', x, X('dev', x), S.design === x)).join('')}</div>
  <div class="sg"><span>${t('Jump to')}</span><select data-sw="jump" aria-label="${t('Jump to')}"><option value="">${esc(titleOf(curPath) || t('Screen'))}</option>${groups.map(g => `<optgroup label="${esc(t(g))}">${JUMP.filter(j => j.g === g).map(j => `<option value="${j.id}">${esc(t(j.label, {v:money(54)}))}</option>`).join('')}</optgroup>`).join('')}</select></div>
  <div class="sg"><span>${t('View')}</span>${B('zoom', 'fit', t('Fit'), S.zoom === 'fit')}${B('zoom', '100', '100%', S.zoom === '100')}</div>
  <div class="sg links"><a href="screens.html#device=${devKey()}">${ico('grid')}${t('All screens')}</a><a href="${OTHER.href}#${esc(curPath)}${curPath.includes('?') ? '&' : '?'}device=${devKey()}" class="other">${ico('globe')}${t(OTHER.label)}</a></div>`;
}
const SIZE_RULES = {compact:'Compact (under 600): the website’s phone header and menu, bottom tab bar, one pane, 2-column grid.', medium:'Medium (600 to 839): the website’s header and menu as the site shows them at this width, one pane, 3-column grid, gallery beside the details.', expanded:'Expanded (840 and over): the website’s computer header with its menu bar and mega panels, two panes (list and detail) where the screen has a pair, filter panel beside the grid.'};
function notesHTML(){
  const d = dev(), cls = sizeClass(d.w), r = route();
  const extra = d.dual ? t('Surface Duo spanned: list on one screen, detail on the other; nothing sits under the hinge.') : S.dev === 'flip' && S.posture === 'tabletop' ? t('Tabletop: content in the top half, controls in the bottom half.') : S.dev === 'fold' ? t('Fold or unfold: the same screen, bag and scroll position carry over.') : '';
  return `<div class="meta"><b>${esc(titleOf(r.path) || '')}</b><b>${X('dev', esc(d.name))} · ${X('dev', `${d.w}×${d.h} ${d.unit}`)}</b><b>${t(cls)}</b><b>${S.os === 'ios' ? X('dev', 'iOS') : X('dev', 'Android · Material 3')}</b><span class="hap" id="hap"></span></div>
  <p>${t(SIZE_RULES[cls])} ${extra}</p>
  <p class="small dim">${S.os === 'ios' ? t('Swipe from the {e} edge to go back. Pull down at the top of a list to refresh.', {e:t(AR ? 'right' : 'left')}) : t('Swipe from either edge for the predictive back preview. Pull down at the top of a list to refresh.')} ${t('Real data: {n} products from kbeautybliss.com. Payments, push, camera and biometrics are mocked.', {n:num(PL.length)})}</p>`;
}
function fit(){
  const sc = $('#scaler'), st = $('#stage'), d = dev();
  if (!sc || !st) return;
  const fw = d.dual ? d.w * 2 + d.hinge + 32 : d.w + (S.dev === 'ipse' ? 28 : S.dev === 'ipad' ? 44 : 24);
  const fh = d.h + (S.dev === 'ipse' ? 150 : S.dev === 'ipad' ? 44 : d.dual ? 28 : 24) + 24;
  if (S.zoom !== 'fit' && !S.embed) { sc.style.width = fw + 'px'; sc.style.height = fh + 'px'; st.style.transform = ''; st.style.width = ''; return; }
  const avW = window.innerWidth - (S.embed ? 8 : 32), avH = window.innerHeight - (S.embed ? 8 : (($('#sw') || {}).offsetHeight || 0) + 40);
  const k = Math.max(.15, Math.min(1, avW / fw, avH / fh));
  st.style.width = fw + 'px'; st.style.transformOrigin = 'top left'; st.style.transform = `scale(${k})`;
  sc.style.width = Math.floor(fw * k) + 'px'; sc.style.height = Math.ceil(fh * k) + 'px';
}

/* ── render ────────────────────────────────────────────────────────────── */
function render(o = {}){
  const ghosts = o.anim && !reduced() && !S.embedStill ? $$('#stage .app .main').map(m => ({el:m.cloneNode(true), tops:$$('.body', m).map(b => b.scrollTop)})) : null;
  $('#stage').innerHTML = frameHTML();
  if (!S.embed) { $('#sw').innerHTML = swHTML(); $('#notes').innerHTML = notesHTML(); }
  document.documentElement.lang = LANG; document.documentElement.dir = AR ? 'rtl' : 'ltr';
  $$('#stage [data-sk]').forEach(b => { const v = S.scroll[b.dataset.sk]; if (v) b.scrollTop = v; });
  wire();
  wireSite();
  paintChooser();
  if (ghosts) animate(ghosts, o.anim);
  fit();
  writeHash();
}
function animate(ghosts, kind){
  const mains = $$('#stage .app .main');
  mains.forEach((m, i) => {
    const g = ghosts[i]; if (!g) return;
    if (kind === 'tab' || kind === 'fade') { m.classList.add('an-fade'); setTimeout(() => m.classList.remove('an-fade'), 260); return; }
    if (g.el.innerHTML === m.innerHTML) return;
    const ghost = g.el; ghost.classList.add('navghost', kind === 'pop' ? 'gh-pop' : 'gh-push'); ghost.setAttribute('aria-hidden', 'true'); ghost.querySelectorAll('[data-layer]').forEach(x => x.removeAttribute('data-layer')); ghost.querySelectorAll('[id]').forEach(x => x.removeAttribute('id'));
    m.parentElement.insertBefore(ghost, kind === 'pop' ? m.nextSibling : m);
    ghost.style.gridRow = getComputedStyle(m).gridRow; ghost.style.gridColumn = getComputedStyle(m).gridColumn;
    $$('.body', ghost).forEach((b, j) => b.scrollTop = g.tops[j] || 0);
    m.classList.add(kind === 'pop' ? 'an-pop' : 'an-push');
    setTimeout(() => { ghost.remove(); m.classList.remove('an-pop', 'an-push'); }, 340);
  });
}
let wired = false;
function wire(){
  $$('#stage .body').forEach(b => b.addEventListener('scroll', onBodyScroll, {passive:true}));
  $$('#stage [data-dots]').forEach(el => el.addEventListener('scroll', () => {
    const i = Math.round(Math.abs(el.scrollLeft) / Math.max(1, el.clientWidth));
    const box = document.getElementById(el.dataset.dots);
    if (box) [...box.children].forEach((b, j) => { b.classList.toggle('on', j === i); });
    const cnt = el.parentElement.querySelector('.cnt'); if (cnt) cnt.innerHTML = LTR(num(i + 1) + ' / ' + num(el.children.length));
  }, {passive:true}));
  const vs = $('#stage [data-vs]'); if (vs && S.viewer && S.viewer.i) vs.scrollLeft = (AR ? -1 : 1) * S.viewer.i * vs.clientWidth;
  $$('#stage [data-anim-w]').forEach(el => {
    const k = el.dataset.animW, now = el.style.width, was = S.animW[k];
    S.animW[k] = now;
    if (was && was !== now && !reduced()) { el.style.transition = 'none'; el.style.width = was; void el.offsetWidth; el.style.transition = ''; el.style.width = now; }
  });
  $$('#stage .body').forEach(b => onBodyScroll({target:b}, true));
}
function onBodyScroll(e, init){
  const b = e.target;
  if (!init) S.scroll[b.dataset.sk] = b.scrollTop;
  const pane = b.closest('.pane'); const br = pane && pane.querySelector('[data-bar]');
  if (br) br.classList.toggle('sc', b.scrollTop > 34);
  const ml = b.querySelector('.more-load');
  if (ml && !S.loadingMore && b.scrollTop + b.clientHeight > b.scrollHeight - 320 && !init) {
    S.loadingMore = true;
    setTimeout(() => { S.more[ml.dataset.more] = (S.more[ml.dataset.more] || 12) + 12; S.loadingMore = false; render(); }, 550);
  }
}
function writeHash(){
  if (S.noHash) return;
  const p = topE().path;
  const h = `#${p}${p.includes('?') ? '&' : '?'}device=${devKey()}&os=${S.os}${S.design !== 'A' ? '&d=' + S.design : ''}${S.bb !== 'A' ? '&bb=' + S.bb : ''}${S.tm === 'bar' ? '&tm=bar' : ''}${AR && S.digits === 'arab' ? '&dg=arab' : ''}${S.embed ? '&embed=1' : ''}${S.zoom === '100' ? '&z=100' : ''}`;
  if (location.hash !== h) { S.selfHash = h; history.replaceState(null, '', h); }
}
function haptic(kind){
  const el = $('#hap'); if (!el) return;
  el.innerHTML = S.os === 'ios' ? t('Haptic · {k}', {k:t(kind)}) : t('Haptic · {k}', {k:X('code', kind === 'success' ? 'CONFIRM' : kind === 'error' ? 'REJECT' : 'CLOCK_TICK')});
  el.classList.add('on'); clearTimeout(haptic.t); haptic.t = setTimeout(() => el.classList.remove('on'), 1600);
}
let toastTimer;
function toast(msg, o = {}){ S.toast = {msg, ...o}; render(); clearTimeout(toastTimer); toastTimer = setTimeout(() => { S.toast = null; $$('#stage .toast').forEach(x => x.remove()); }, 2600); }

/* ── navigation ────────────────────────────────────────────────────────── */
function enter(path){
  const r = resolve(path); if (!r) return;
  if (r.s.pattern === '/product/:slug') { S.qty = 1; S.ptab = 'desc'; }
}
function go(path, o = {}){
  const r = resolve(path); if (!r) return;
  S.sheet = null; S.viewer = null; S.menu = false; S.mega = null;
  enter(path);
  if (o.tabroot) { S.tab = o.tabroot; S.stacks[S.tab] = [{path:ROOT[S.tab]}]; if (r.path !== ROOT[S.tab]) S.stacks[S.tab].push({path:r.path}); render({anim:'tab'}); return; }
  if (o.root) { const sf = stackFor(path); S.tab = sf.tab; S.stacks[S.tab] = [{path:ROOT[sf.tab]}]; if (r.path !== ROOT[sf.tab]) S.stacks[S.tab].push({path:r.path}); render({anim:'tab'}); return; }
  const st = S.stacks[S.tab];
  if (o.replace) { st[st.length - 1] = {path:r.path}; render({anim:'fade'}); return; }
  if (st[st.length - 1].path === r.path) { render(); return; }
  st.push({path:r.path});
  render({anim:'push'});
}
function back(o = {}){
  if (S.sheet) { S.sheet = null; render(); return; }
  if (S.viewer) { S.viewer = null; render(); return; }
  if (S.menu || S.mega != null) { S.menu = false; S.mega = null; render(); return; }
  const st = S.stacks[S.tab];
  if (st.length < 2) return;
  const done = () => { const gone = st.pop(); delete S.scroll[gone.path + '|']; render({anim:o.noAnim ? null : 'pop'}); };
  if (isA() && !o.noAnim && !reduced()) { const p = $('#stage .app .main'); if (p) { p.classList.add('pb-shrink'); setTimeout(done, 150); return; } }
  done();
}
function tab(k){
  S.sheet = null; S.viewer = null; S.menu = false; S.mega = null;
  if (S.tab === k) { const st = S.stacks[k]; if (st.length > 1) { S.stacks[k] = [st[0]]; render({anim:'pop'}); } else { const b = $('#stage .body'); if (b) b.scrollTo({top:0, behavior:reduced() ? 'auto' : 'smooth'}); } return; }
  S.tab = k; render({anim:'tab'});
}
