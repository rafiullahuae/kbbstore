#!/usr/bin/env node
/*
 * Lane APV2 — drives the two app previews, docs/app-preview/en/index.html and
 * docs/app-preview/ar/index.html, in Chromium and holds them to the owner's bar.
 *
 *   node tools/apv-shots.cjs                         everything: every screen × device × language,
 *                                                    the checkout walk, the navigation smoke tests,
 *                                                    contact sheets, report.json and the key pictures
 *   node tools/apv-shots.cjs --lang=ar --dev=ip16,duo --only=Bag,Product --no-shots
 *   node tools/apv-shots.cjs --flows-only            only the clicked-through flows
 *   node tools/apv-shots.cjs --no-flows              only the screen grid
 *   node tools/apv-shots.cjs --mutate='<css>'        inject a defect to prove the checks can fail
 *
 * Every screen is reached by its deep link (index.html#/route?device=…), the same
 * link screens.html uses. Per render it asserts:
 *   overflow   no screen root is wider than its box (scrollWidth == clientWidth)
 *   clipped    no text line is cut by a box that does not scroll (designed ellipses and clamps excepted)
 *   overlap    no two text runs, or text and a picture, intersect within one stacking layer
 *   icon-size  no unsized inline icon
 *   contrast   text against its solid background is at least 3:1
 *   tap        every interactive element is at least 44 × 44 (a field's box is its target)
 *   safe       no fixed text or control in the status-bar band or the home-indicator band
 *   hinge      nothing crosses the Duo hinge or the Flip tabletop fold
 *   label      floating labels: the label stays inside its box; resting, it is centred; lifted, it
 *              sits at the top-start corner (left in English, right in Arabic) and never overlaps
 *              the value
 *   english    Arabic app only: no Latin word in any text, aria-label, placeholder or title, outside
 *              an element marked data-x="<why>" (brand names, codes, card networks…); the
 *              exceptions are counted by reason in report.json
 *   console    no console error (a missing Arabic string is one), page error or failed request
 *   site       Lane APV3: the website's header is the site's, measured: at this frame's width its
 *              height, search box (height, corners), logo, language switch, ☰ and menu bar equal the
 *              live site's (docs/app-preview/site-measure.json, from tools/apv-site.cjs) within 1px;
 *              the Arabic app shows the site's Arabic wordmark
 *   rail       no navigation rail anywhere (the site's menus replaced it)
 *   The site's own header and menus (.kh) are held to the site's sizes and colours by the parity
 *   check, not to the app's 44px tap rule or 3:1 contrast: the owner asked for exactly the site.
 *   Where the site itself falls short (the 34px search field, white on #E991AE in the delivery
 *   strip) the report says so in site_findings instead of failing the app for copying it.
 *
 * Lane APV3 flows: the site's phone menu (every top-level item and a sub-level of each, the chips,
 * close by × and by the scrim), the menu bar and every mega panel on iPad landscape and with
 * "Tablet menu: menu bar", the header's icons, logo, search box and language switch, and every
 * bottom-bar variant A–E on the phones (each tab, each "More" tile, the badges).
 *
 * The flows click and type like a shopper: the whole checkout, step by step, for every payment
 * method, the coupon, a declined payment and its retry with the bag kept; and the navigation smoke
 * tests (product → back → tab switch → state kept, wishlist kept across a reload).
 *
 * Writes docs/app-preview/shots/<lang>/<screen>-<device>.png, contact sheets
 * shots/sheet-<device>-<lang>.png, the checkout strips, report.json, and docs/app-preview/key/.
 */
'use strict';
const pw = require('/opt/node22/lib/node_modules/playwright');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const APP = path.join(ROOT, 'docs/app-preview');
const PAGE = (lang) => 'file://' + path.join(APP, lang, 'index.html');
const OUT = path.join(APP, 'shots');
const KEY = path.join(APP, 'key');
const arg = (k) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.slice(k.length + 3).split(',') : null; };
const NOSHOTS = process.argv.includes('--no-shots');
const FLOWS_ONLY = process.argv.includes('--flows-only');
const NO_FLOWS = process.argv.includes('--no-flows');
const MUTATE = (process.argv.find((x) => x.startsWith('--mutate=')) || '').slice(9);
const WORKERS = Number((arg('workers') || ['3'])[0]);
/* Lane APV3: the live site's header at the app's widths (tools/apv-site.cjs) */
const SITEM = (() => {
  const f = path.join(APP, 'site-measure.json');
  if (!fs.existsSync(f)) return {en:null, ar:null};
  const J = JSON.parse(fs.readFileSync(f, 'utf8'));
  const out = {en:{}, ar:{}};
  for (const [k, m] of Object.entries(J.measured)) {
    const [lang, w] = k.split('-');
    out[lang][w] = {h:m.header.h, si:{h:m.search.h, w:m.search.w, r:m.search.borderRadius, ph:m.search.placeholder}, icon:{w:m.logoIcon.w, h:m.logoIcon.h}, word:m.wordText.trim(),
      lang:m.lang && m.lang.box ? {w:m.lang.box.w, h:m.lang.box.h} : null, menu:m.menuIcon ? {w:m.menuIcon.w, h:m.menuIcon.h} : null, bar:m.menuBar ? m.menuBar.h : 0,
      iconSvg:((m.icons.filter((i) => i.svg).pop()) || {svg:{w:21}}).svg.w}; // the cart's: the language capsule is drawn text, not an icon
  }
  const C = (J.co || {}).measured || {};
  for (const lang of ['en', 'ar']) out[lang].co = {cart:C[lang + '-cart'], checkout:C[lang + '-checkout']};
  return out;
})();
const DEVICES = ['ip16', 'ipse', 'ipad', 'ipad-land', 'px9', 'flip', 'flip-tabletop', 'fold-cover', 'fold-inner', 'duo'].filter((d) => !arg('dev') || arg('dev').includes(d));
const LANGS = arg('lang') || ['en', 'ar'];
const FULL = !arg('dev') && !arg('lang') && !arg('only');

/* ── the in-page checker ─────────────────────────────────────────────────── */
function audit(arg) {
  const lang = typeof arg === 'string' ? arg : arg.lang;
  const SITE = (arg && arg.site) || null;
  const SCO = (arg && arg.co) || null;
  const issues = [];
  const add = (kind, el, msg) => {
    const path = [];
    for (let e = el; e && e.nodeType === 1 && path.length < 4 && !e.classList.contains('glass'); e = e.parentElement) path.unshift(e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : ''));
    issues.push({kind, at:path.join(' > '), msg});
  };
  const glasses = [...document.querySelectorAll('.device .glass')];
  const cs = (e) => getComputedStyle(e);
  const scrollsX = (s) => /auto|scroll/.test(s.overflowX);
  const scrollsY = (s) => /auto|scroll/.test(s.overflowY);
  const clips = (s) => s.overflowX !== 'visible' || s.overflowY !== 'visible';
  const designedCut = (e) => { const s = cs(e); return s.textOverflow === 'ellipsis' || (s.webkitLineClamp && s.webkitLineClamp !== 'none'); };
  const inter = (a, b) => ({left:Math.max(a.left, b.left), top:Math.max(a.top, b.top), right:Math.min(a.right, b.right), bottom:Math.min(a.bottom, b.bottom)});
  const area = (r) => Math.max(0, r.right - r.left) * Math.max(0, r.bottom - r.top);
  const stackOf = (e) => e.closest('.mmenu') ? 'menu' : e.closest('.kh .drop') ? 'drop' : e.closest('.mscrim') ? 'scrim' : e.closest('.ovl') ? 'ovl' : e.closest('.viewer') ? 'viewer' : e.closest('.toast') ? 'toast' : e.closest('[data-layer="pb"]') ? 'peekbg' : 'base';
  const hidden = (e) => { for (let x = e; x && !x.classList.contains('glass'); x = x.parentElement) { const s = cs(x); if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0) return true; } return false; };

  /* visible part of a rect: intersect every clipping ancestor up to the glass.
     ext=true stops at the nearest scroller, so content below the fold or off the
     side of a rail is audited as if scrolled into view. */
  function visible(el, r, ext, self) {
    let v = {left:r.left, top:r.top, right:r.right, bottom:r.bottom};
    for (let a = self ? el : el.parentElement; a; a = a.parentElement) {
      const s = cs(a);
      if (ext && (scrollsX(s) || scrollsY(s))) break;
      if (clips(s) || a.classList.contains('glass')) v = inter(v, a.getBoundingClientRect());
      if (a.classList.contains('glass')) break;
    }
    return v;
  }
  const scOf = (el) => { for (let a = el.parentElement; a && !a.classList.contains('glass'); a = a.parentElement) { const s = cs(a); if (scrollsX(s) || scrollsY(s)) return a; } return null; };

  for (const g of glasses) {
    const G = g.getBoundingClientRect();
    const sat = parseFloat(cs(g).getPropertyValue('--sat')) || 0;
    const sab = parseFloat(cs(g).getPropertyValue('--sab')) || 0;

    /* 1. overflow of screen roots */
    g.querySelectorAll('.app,.pane,.body,.bar,.foot,.sheet,.sb2,.tabbar,.rail,.split,.tt-ctl').forEach((e) => {
      if (hidden(e)) return;
      if (e.scrollWidth > e.clientWidth + 1) {
        const E = e.getBoundingClientRect();
        let worst = null, by = 0;
        e.querySelectorAll('*').forEach((c) => { const sc = scOf(c); if (sc && sc !== e && e.contains(sc)) return; const r = c.getBoundingClientRect(); const o = Math.max(r.right - E.right, E.left - r.left); if (o > by + .5 && r.width) { by = o; worst = c; } });
        add('overflow', e, `scrollWidth ${e.scrollWidth} > clientWidth ${e.clientWidth}${worst ? ` — widest: ${worst.tagName.toLowerCase()}.${String(worst.className).split(' ')[0]} (+${Math.round(by)}px)` : ''}`);
      }
    });

    /* 2–3. text: clipping and overlap */
    const runs = [];
    const tw = document.createTreeWalker(g, NodeFilter.SHOW_TEXT, {acceptNode:(n) => n.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT});
    const rg = document.createRange();
    for (let n = tw.nextNode(); n; n = tw.nextNode()) {
      const el = n.parentElement;
      if (!el || el.closest('.sb') || hidden(el)) continue;
      rg.selectNodeContents(n);
      const fs = parseFloat(cs(el).fontSize) || 14;
      for (const r of rg.getClientRects()) {
        if (r.width < 1 || r.height < 1) continue;
        // clipping: walk ancestors; a scrolling one forgives, a hidden one is a cut
        let cut = null;
        for (let a = el; a; a = a.parentElement) {
          const s = cs(a);
          if (a !== el || clips(s)) {
            if (clips(s) || a.classList.contains('glass')) {
              const A = a.getBoundingClientRect();
              const outX = r.left < A.left - .5 || r.right > A.right + .5;
              const outY = r.top < A.top - .5 || r.bottom > A.bottom + .5;
              const fullyOut = r.right <= A.left || r.left >= A.right || r.bottom <= A.top || r.top >= A.bottom;
              if ((outX || outY) && !fullyOut) {
                if ((outX && scrollsX(s)) || (outY && scrollsY(s))) break;
                if (designedCut(a) || a.closest('.clamp2,.clamp3,.ell')) break;
                // a glyph box taller than its line (Arabic ascenders) is not a cut
                const coreTop = (r.top + r.bottom) / 2 - fs * .45, coreBot = (r.top + r.bottom) / 2 + fs * .45;
                if (!outX && coreTop >= A.top - .5 && coreBot <= A.bottom + .5) break;
                cut = a; break;
              }
              if (fullyOut) break; // wholly hidden, e.g. the spare lines of a clamp: nothing is cut
            }
          }
          if (a.classList.contains('glass')) break;
        }
        if (cut) add('clipped', el, `“${n.nodeValue.trim().slice(0, 40)}” cut by ${cut.tagName.toLowerCase()}.${String(cut.className).split(' ')[0]}`);
        const v = visible(el, r, false, true), ve = visible(el, r, true, true);
        if (area(ve) <= 0) continue;
        const cy = (r.top + r.bottom) / 2;
        const core = (w) => ({left:Math.max(w.left, r.left + .5), right:Math.min(w.right, r.right - .5), top:Math.max(w.top, cy - fs * .35), bottom:Math.min(w.bottom, cy + fs * .35)});
        runs.push({n, el, rr:r, sc:scOf(el), stack:stackOf(el), core:core(v), ext:core(ve), real:area(v) > 0});
      }
    }
    for (let i = 0; i < runs.length; i++) for (let j = i + 1; j < runs.length; j++) {
      const a = runs[i], b = runs[j];
      if (a.n === b.n || a.stack !== b.stack) continue;
      const same = a.sc === b.sc;
      if (!same && !(a.real && b.real)) continue;
      const x = same ? inter(a.ext, b.ext) : inter(a.core, b.core);
      if (x.right - x.left > 1.5 && x.bottom - x.top > 1.5) add('overlap', a.el, `“${a.n.nodeValue.trim().slice(0, 30)}” × “${b.n.nodeValue.trim().slice(0, 30)}”`);
    }

    /* 3b. text over a picture or an icon (designed overlays on photos excepted) */
    const media = [...g.querySelectorAll('img,svg')].filter((m) => !hidden(m) && !m.closest('.sb,.island,.punch')).map((m) => { const R = m.getBoundingClientRect(); return {m, sc:scOf(m), stack:stackOf(m), v:visible(m, R), ve:visible(m, R, true)}; }).filter((x) => area(x.ve) > 0);
    for (const r of runs) {
      if (r.el.closest('.zh,.cnt,.badge,.bdg,.pbk,.tt-fold,.blogo,.cth,.kh,.kc')) continue; // .kh: the site's own art behind its own text
      for (const x of media) {
        if (x.stack !== r.stack || x.m.contains(r.el) || r.el.contains(x.m)) continue;
        const same = x.sc === r.sc;
        if (!same && !(r.real && area(x.v) > 0)) continue;
        const o = same ? inter(r.ext, x.ve) : inter(r.core, x.v);
        if (o.right - o.left > 1.5 && o.bottom - o.top > 1.5) { add('overlap', r.el, `“${r.n.nodeValue.trim().slice(0, 30)}” over ${x.m.tagName.toLowerCase()}`); break; }
      }
    }

    /* 3c. icons keep their size: an unsized inline <svg> grows to its box */
    g.querySelectorAll('svg').forEach((m) => {
      if (hidden(m) || m.closest('.sb,.splash,.compan,.bio,.done-mark,.state .ic,.scanv,.lgx,.kh,.kc')) return; // .kh/.kc: the site's own art, at the site's sizes
      const r = m.getBoundingClientRect();
      if (r.width > 48 || r.height > 48) add('icon-size', m, `${Math.round(r.width)}×${Math.round(r.height)}`);
    });
    /* 3d. a field whose value does not fit its box */
    g.querySelectorAll('input,textarea').forEach((e) => {
      if (hidden(e) || e.type === 'checkbox' || e.type === 'radio') return;
      if (e.scrollWidth > e.clientWidth + 1 && e.tagName === 'INPUT') add('clipped', e, `field value “${String(e.value).slice(0, 24)}” wider than its box (${e.scrollWidth} > ${e.clientWidth})`);
    });
    /* 3e. contrast: text against the first solid background behind it (≥ 3:1) */
    const rgba = (c) => { const m = c.match(/[\d.]+/g); return m ? {c:m.slice(0, 3).map(Number), a:m[3] === undefined ? 1 : Number(m[3])} : null; };
    const L = (rgb) => { const [r, g2, b] = rgb.map((v) => { v = v / 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }); return .2126 * r + .7152 * g2 + .0722 * b; };
    const lum = (c) => { const x = rgba(c); return x ? {L:L(x.c), a:x.a} : null; };
    const checkedEl = new Set();
    for (const r of runs) {
      if (checkedEl.has(r.el)) continue; checkedEl.add(r.el);
      if (r.el.closest('.kh,.kc')) continue; // the website's own colours: held to the site, see site_findings
      const fg = lum(cs(r.el).color); if (!fg || fg.a < .5) continue;
      // composite translucent backgrounds down to the first solid one
      let bg = null; const layers = [];
      for (let a = r.el; a; a = a.parentElement) {
        const s2 = cs(a);
        if (s2.backgroundImage !== 'none' || a.tagName === 'IMG') break;
        const b = rgba(s2.backgroundColor);
        if (b && b.a > .05) { layers.push(b); if (b.a > .97) break; }
        if (a.classList.contains('glass')) break;
      }
      if (!layers.length || layers[layers.length - 1].a <= .97) continue;
      let c = layers.pop().c;
      while (layers.length) { const t = layers.pop(); c = c.map((v, k) => t.c[k] * t.a + v * (1 - t.a)); }
      bg = {L:L(c)};
      const ratio = (Math.max(fg.L, bg.L) + .05) / (Math.min(fg.L, bg.L) + .05);
      if (ratio < 3) add('contrast', r.el, `“${r.n.nodeValue.trim().slice(0, 30)}” ${ratio.toFixed(2)}:1`);
    }

    /* 4. tap targets */
    const T = g.querySelectorAll('button,a,input,select,textarea,[role=button],[data-go],[data-act],[data-tab],[data-sheet]');
    T.forEach((e) => {
      if (hidden(e) || e.matches('.scrim') || e.closest('.peek')) return; // .peek is a scaled transition frame
      if (e.closest('.kh,.kc')) return; // the website's own header, menus, cart and checkout: held to the site's sizes (site, cart, checkout checks)
      if (e.closest('.fl,.srch.field') && e.matches('input,textarea')) return; // the field box is the target
      const r = e.getBoundingClientRect();
      if (area(visible(e, r, true)) <= 0) return;
      if (r.width < 43.5 || r.height < 43.5) add('tap', e, `${Math.round(r.width)}×${Math.round(r.height)}`);
    });

    /* 5. safe areas: fixed text / controls out of the status band and home-indicator band */
    const fixedEls = [...T].filter((e) => !e.matches('.scrim,.mscrim')).map((e) => ({e, r:e.getBoundingClientRect()})).concat(runs.map((x) => ({e:x.el, r:x.n && x.rr})));
    const inScroll = (e) => { for (let a = e.parentElement; a && !a.classList.contains('glass'); a = a.parentElement) { const s = cs(a); if (scrollsY(s)) return a; } return null; };
    const seen = new Set();
    for (const {e, r:R0} of fixedEls) {
      if (!R0 || hidden(e)) continue; seen.add({e, R0});
      const r = visible(e, R0);
      if (area(r) <= 0) continue;
      const sc = inScroll(e);
      if (r.top < G.top + sat - .5 && (!sc || sc.scrollTop === 0)) add('safe-top', e, `top ${Math.round(r.top - G.top)} < inset ${sat}`);
      if (sab && !sc && r.bottom > G.bottom - sab + .5 && !e.closest('.homeind')) add('safe-bottom', e, `bottom ${Math.round(G.bottom - r.bottom)} from edge < inset ${sab}`);
    }

    /* 6. fold (Flip tabletop) */
    const fold = g.querySelector('[data-fold]');
    if (fold) {
      const F = fold.getBoundingClientRect();
      for (const {e, R0} of seen) {
        const r = visible(e, R0);
        if (area(r) > 0 && r.bottom > F.top + .5 && r.top < F.bottom - .5) add('hinge', e, 'crosses the tabletop fold');
      }
    }
  }
  /* 9. Lane APV3: the website's header, measured against the live site at this frame's width */
  for (const g of glasses) {
    if (g.querySelector('.rail')) add('rail', g.querySelector('.rail'), 'a navigation rail is drawn');
    const hd = g.querySelector('.kh .kh-hd');
    if (!hd || !SITE) continue;
    const W = Math.round(g.offsetWidth), M = SITE[W];
    if (!M) { add('site', hd, `no site measurement at ${W}px`); continue; }
    const near = (a, b) => Math.abs(a - b) <= 1;
    const box = (e) => e.getBoundingClientRect();
    const mb0 = hd.closest('.kh.tm-bar') && hd.querySelector('.mbar');
    const h = box(hd).height - parseFloat(cs(hd).paddingTop) - (mb0 ? box(mb0).height : 0); // "menu bar" option: the site's header + its menu bar
    if (!near(h, M.h)) add('site', hd, `header ${h.toFixed(1)}px high, the site's is ${M.h}px at ${W}px`);
    const si = hd.querySelector('.search-in');
    if (!si || hidden(si)) add('site', hd, 'no search box');
    else {
      const S2 = box(si);
      if (!near(S2.height, M.si.h)) add('site', si, `search box ${S2.height.toFixed(1)}px high, the site's is ${M.si.h}px`);
      if (Math.abs(S2.width - M.si.w) > 2) add('site', si, `search box ${S2.width.toFixed(1)}px wide, the site's is ${M.si.w}px`);
      if (cs(si).borderTopLeftRadius !== M.si.r) add('site', si, `search box corners ${cs(si).borderTopLeftRadius}, the site's are ${M.si.r}`);
      const inp = si.querySelector('input');
      if (inp && inp.placeholder !== M.si.ph) add('site', inp, `placeholder “${inp.placeholder}”, the site's is “${M.si.ph}”`);
    }
    const li = hd.querySelector('.logo .lgx-i');
    if (!li || !near(box(li).width, M.icon.w) || !near(box(li).height, M.icon.h)) add('site', hd, `logo ${li ? box(li).width.toFixed(1) + '×' + box(li).height.toFixed(1) : 'missing'}, the site's is ${M.icon.w}×${M.icon.h}`);
    const lw = hd.querySelector('.lgx-w');
    if (!lw || lw.textContent.trim() !== M.word) add('site', hd, `wordmark “${lw ? lw.textContent.trim() : ''}”, the site's is “${M.word}”`);
    if (lang === 'ar' && lw && !/[\u0600-\u06FF]/.test(lw.textContent)) add('site', lw, 'the Arabic app does not show the Arabic wordmark');
    const ls0 = [...hd.querySelectorAll('[data-lang]')].find((x) => !hidden(x) && box(x).width);
    const ls = ls0 && (ls0.closest('.lsw-d') || ls0); // the computer's pill is the whole EN | ع switch
    if (!ls) add('site', hd, 'no language switch');
    else if (M.lang && (!near(box(ls).width, M.lang.w) || !near(box(ls).height, M.lang.h))) add('site', ls, `language switch ${box(ls).width.toFixed(1)}×${box(ls).height.toFixed(1)}, the site's is ${M.lang.w}×${M.lang.h}`);
    const bu = hd.querySelector('[data-act="menu"]');
    const buOn = bu && !hidden(bu) && box(bu).width > 0;
    if (!!M.menu !== !!buOn) add('site', hd, `menu button ${buOn ? 'shown' : 'hidden'}, the site's is ${M.menu ? 'shown' : 'hidden'} at ${W}px`);
    else if (buOn && (!near(box(bu).width, M.menu.w) || !near(box(bu).height, M.menu.h))) add('site', bu, `menu button ${box(bu).width}×${box(bu).height}, the site's is ${M.menu.w}×${M.menu.h}`);
    const mb = hd.querySelector('.mbar');
    const mbOn = mb && !hidden(mb) && box(mb).height > 0;
    const tmBar = !!hd.closest('.kh.tm-bar');
    if (!tmBar && !!M.bar !== !!mbOn) add('site', hd, `menu bar ${mbOn ? 'shown' : 'hidden'}, the site's is ${M.bar ? 'shown' : 'hidden'} at ${W}px`);
    else if (mbOn && M.bar && !near(box(mb).height, M.bar)) add('site', mb, `menu bar ${box(mb).height}px high, the site's is ${M.bar}px`);
    if (tmBar && !mbOn) add('site', hd, '"Tablet menu: menu bar" shows no menu bar');
    const ib = [...hd.querySelectorAll('.hact .ib')].filter((x) => !x.matches('[data-lang]') && !hidden(x) && box(x).width);
    for (const x of ib) { const sv = x.querySelector('svg'); if (sv && !near(box(sv).width, M.iconSvg)) add('site', x, `header icon ${box(sv).width.toFixed(1)}px, the site's are ${M.iconSvg}px`); }
  }

  /* 10. Lane APV3: the cart page and the checkout are the site's, measured against the site at 390 */
  if (SCO) for (const g of glasses) {
    if (g.offsetWidth >= 600) continue; // measured on the site at 390: the phone layout
    const near = (a, b) => Math.abs(a - b) <= 1;
    const H = (e) => e.getBoundingClientRect().height;
    const cart = g.querySelector('.kc .kbb-cartpage');
    if (cart && SCO.cart) {
      const M = SCO.cart, btn = cart.querySelector('a.cobtn'), h1 = cart.querySelector('h1'), q = cart.querySelector('.qty');
      if (!btn || !near(H(btn), M.btn.h) || cs(btn).borderTopLeftRadius !== M.btn.rad) add('cart', btn || cart, `checkout button ${btn ? H(btn).toFixed(1) + 'px, corners ' + cs(btn).borderTopLeftRadius : 'missing'}; the site's is ${M.btn.h}px, ${M.btn.rad}`);
      if (!h1 || cs(h1).fontSize !== M.title.fs) add('cart', h1 || cart, `title ${h1 ? cs(h1).fontSize : 'missing'}, the site's is ${M.title.fs}`);
      if (q && !near(H(q), M.qty.h)) add('cart', q, `quantity stepper ${H(q)}px, the site's is ${M.qty.h}px`);
      const order = [...cart.querySelectorAll('.wrap > *, .grid > *, aside.sum > *')].filter((e) => e.getBoundingClientRect().height).map((e) => (e.tagName + '.' + (String(e.className).split(' ').filter((c) => c && !/^id-/.test(c))[0] || '')).toLowerCase());
      if (order.join(' ') !== M.order.join(' ')) add('cart', cart, `sections ${order.join(' ')} ≠ the site's ${M.order.join(' ')}`);
    }
    const hd = g.querySelector('.kc-head .co-head'), co = g.querySelector('.kc section.kbb-checkout:not(.kc-head *)');
    if (hd && co && SCO.checkout) {
      const M = SCO.checkout;
      const h = H(hd) - parseFloat(cs(hd).paddingTop);
      if (!near(h, M.head.h)) add('checkout', hd, `checkout header ${h.toFixed(1)}px, the site's is ${M.head.h}px`);
      const row = co.querySelector('.cosr'); if (!row || !near(H(row), M.sumRow.h)) add('checkout', row || co, `summary bar ${row ? H(row) : 'missing'}, the site's is ${M.sumRow.h}px`);
      const pl = co.querySelector('.kbb-mobile-order .place'); if (!pl || !near(H(pl), M.place.h) || cs(pl).borderTopLeftRadius !== M.place.rad) add('checkout', pl || co, `Pay now ${pl ? H(pl) + 'px, ' + cs(pl).borderTopLeftRadius : 'missing'}; the site's is ${M.place.h}px, ${M.place.rad}`);
      const ids = [...co.querySelectorAll('.form-row')].map((e) => (String(e.className).match(/id-(billing_\w+)/) || [])[1]).filter(Boolean).map((x) => x + '');
      const want = M.fields.filter((x) => /^billing_/.test(x));
      if (ids.join(',') !== want.join(',')) add('checkout', co, `fields ${ids.join(',')} ≠ the site's ${want.join(',')}`);
      const pm = [...co.querySelectorAll('.wc_payment_method > input.input-radio')].map((i) => i.value).filter((v) => v !== 'apple' && v !== 'gpay').map((v) => v === 'card' ? 'stripe' : v);
      const pmWant = M.methods.filter((v) => /^[a-z]+$/.test(v)); // the site's own list (its save-card tick aside); the app adds Apple Pay / Google Pay on top, as the site's Apple Pay row does on Safari
      if (pm.join(',') !== pmWant.join(',')) add('checkout', co, `payment methods ${pm.join(',')} ≠ the site's ${pmWant.join(',')}`);
      const secs = co.querySelectorAll('.sec > h2 .n');
      if (secs.length !== M.sections.length) add('checkout', co, `${secs.length} sections, the site has ${M.sections.length}`);
    }
  }

  /* 6b. Duo hinge: nothing of either screen reaches into it */
  const h = document.querySelector('[data-hinge]');
  if (h) {
    const H = h.getBoundingClientRect();
    document.querySelectorAll('.device .glass *').forEach((e) => {
      const r = e.getBoundingClientRect();
      if (r.width && r.height && area(inter(r, H)) > 0 && area(visible(e, r)) > 0 && area(inter(visible(e, r), H)) > 0) add('hinge', e, 'under the hinge');
    });
  }
  /* 7. floating labels (the website's .kbb-fl): inside the box; resting = centred;
        lifted = top-start corner, small, clear of the value */
  document.querySelectorAll('.device .fl').forEach((f) => {
    if (hidden(f)) return;
    const F = f.getBoundingClientRect();
    if (area(visible(f, F, true)) <= 0) return;
    const lab = f.querySelector(':scope > label, :scope > .lbl'); if (!lab) return;
    const inp = f.querySelector(':scope > input, :scope > textarea');
    const L = lab.getBoundingClientRect();
    const rtl = cs(f).direction === 'rtl';
    if (L.left < F.left - .5 || L.right > F.right + .5 || L.top < F.top - .5 || L.bottom > F.bottom + .5) add('label', lab, `label outside its field (top ${Math.round(L.top - F.top)}, start ${Math.round(rtl ? F.right - L.right : L.left - F.left)})`);
    const startGap = rtl ? F.right - L.right : L.left - F.left, endGap = rtl ? L.left - F.left : F.right - L.right;
    if (startGap > 50) add('label', lab, `label sits on the end side, not the start (${rtl ? 'right' : 'left'})`);
    const floated = inp ? (inp.value !== '' || document.activeElement === inp) : f.classList.contains('has');
    if (floated) {
      let vTop;
      if (inp) { const I = inp.getBoundingClientRect(); const s = cs(inp); vTop = I.top + parseFloat(s.borderTopWidth) + parseFloat(s.paddingTop); }
      else { const v = f.querySelector('.val'); const V = v.getBoundingClientRect(); vTop = V.top + parseFloat(cs(v).paddingTop); }
      if (L.bottom > vTop + .5) add('label', lab, `lifted label overlaps the value by ${(L.bottom - vTop).toFixed(1)}px`);
      if (L.top - F.top > 8) add('label', lab, `lifted label ${Math.round(L.top - F.top)}px below the top of the box`);
      if (parseFloat(cs(lab).fontSize) > 12.5) add('label', lab, `lifted label is ${cs(lab).fontSize}, not the small heading`);
    } else if (inp && inp.tagName === 'INPUT') {
      const c = (L.top + L.bottom) / 2, fc = (F.top + F.bottom) / 2;
      if (Math.abs(c - fc) > 1.5) add('label', lab, `resting label ${(c - fc).toFixed(1)}px off the centre of the box`);
    }
  });

  /* 8. the Arabic app has no English, except what is marked data-x="<why>" */
  const exceptions = {};
  if (lang === 'ar') {
    const LAT = /[A-Za-z]{2,}/;
    const hiddenAny = (e) => { for (let x = e; x && x.nodeType === 1; x = x.parentElement) { const s = cs(x); if (s.display === 'none' || s.visibility === 'hidden') return true; } return false; };
    const xOf = (e) => e && e.closest && e.closest('[data-x]');
    const tw2 = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = tw2.nextNode(); n; n = tw2.nextNode()) {
      const el = n.parentElement; if (!el || el.closest('script,style,.navghost')) continue;
      const v = n.nodeValue; if (!LAT.test(v)) continue;
      const x = xOf(el);
      if (x) { const k = x.dataset.x; const E = exceptions[k] = exceptions[k] || {n:0, eg:[]}; E.n++; const s = v.trim().slice(0, 28); if (E.eg.length < 5 && !E.eg.includes(s)) E.eg.push(s); continue; }
      if (el.tagName === 'OPTION' ? false : hiddenAny(el)) continue;
      add('english', el, `“${v.trim().slice(0, 60)}”`);
    }
    document.querySelectorAll('[aria-label],[placeholder],[title],img[alt]').forEach((e) => {
      if (e.closest('.navghost')) return;
      for (const a of ['aria-label', 'placeholder', 'title', 'alt']) { const v = e.getAttribute(a); if (v && LAT.test(v) && !xOf(e)) add('english', e, `${a}=“${v.slice(0, 60)}”`); }
    });
    if (LAT.test(document.title)) add('english', document.body, `document title “${document.title}”`);
  }

  // de-duplicate
  const key = new Set();
  return {issues:issues.filter((x) => { const k = x.kind + x.at + x.msg; if (key.has(k)) return false; key.add(k); return true; }), exceptions};
}

async function settle(page) {
  await page.evaluate(async () => {
    await document.fonts.ready;
    const t0 = performance.now();
    for (;;) {
      const pending = [...document.querySelectorAll('.device img')].filter((im) => {
        if (im.complete) return false;
        const r = im.getBoundingClientRect(), g = im.closest('.glass'); if (!g) return false;
        const G = g.getBoundingClientRect();
        return r.bottom > G.top && r.top < G.bottom && r.right > G.left && r.left < G.right;
      });
      if (!pending.length || performance.now() - t0 > 9000) break;
      await new Promise((r) => setTimeout(r, 60));
    }
    await new Promise((r) => setTimeout(r, 200)); // label transitions are .16s
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
  });
}

/* ── one browser, pages that remember their console ──────────────────────── */
let browser, ctx;
async function newPage(lang) {
  const page = await ctx.newPage();
  page.errs = [];
  page.on('console', (m) => { if (m.type() === 'error') page.errs.push('console: ' + m.text().slice(0, 200)); });
  page.on('pageerror', (e) => page.errs.push('pageerror: ' + e.message));
  page.on('requestfailed', (r) => (r.failure() || {}).errorText === 'net::ERR_ABORTED' || page.errs.push('requestfailed: ' + r.url() + ' ' + (r.failure() || {}).errorText));
  page.on('response', (r) => { if (r.status() >= 400) page.errs.push(`http ${r.status()}: ${r.url()}`); });
  await page.goto(PAGE(lang) + '#/home?z=100');
  await page.waitForFunction(() => window.APV);
  page.lang = lang;
  return page;
}
async function check(page, o = {}) {
  if (MUTATE) await page.addStyleTag({content:MUTATE});
  await settle(page);
  const {issues, exceptions} = await page.evaluate(audit, {lang:page.lang, site:SITEM[page.lang], co:SITEM[page.lang] && SITEM[page.lang].co});
  for (const e of page.errs.splice(0)) issues.push({kind:'console', at:'', msg:e});
  if (o.shot && !NOSHOTS) await page.locator('.device').screenshot({path:o.shot, animations:'disabled', type:'jpeg', quality:82});
  if (o.key && !NOSHOTS) await page.locator('.device').screenshot({path:o.key, animations:'disabled'});
  return {issues, exceptions};
}
const mergeEx = (into, ex) => { for (const [k, v] of Object.entries(ex)) { const E = into[k] = into[k] || {n:0, eg:[]}; E.n += v.n; for (const s of v.eg) if (E.eg.length < 8 && !E.eg.includes(s)) E.eg.push(s); } };

/* ── the grid: every screen × device × language, by deep link ─────────────── */
async function grid(results, exceptions) {
  for (const lang of LANGS) {
    const probe = await newPage(lang);
    let J = await probe.evaluate(() => APV.jump());
    await probe.close();
    if (arg('only')) J = J.filter((j) => arg('only').some((o) => j.label.includes(o) || j.g === o || String(j.id) === o));
    fs.mkdirSync(path.join(OUT, lang), {recursive:true});
    const jobs = [];
    for (const d of DEVICES) for (const j of J) for (const design of j.designs ? ['A', 'B', 'C'] : ['A']) jobs.push({d, j, design, name:`${String(j.id).padStart(2, '0')}-${slug(j.label)}${design !== 'A' ? '-' + design : ''}-${d}`});
    /* Lane APV3: every bottom-bar variant on the phones, and "Tablet menu: menu bar" on the tablets */
    const VAR_SCREENS = ['Home', 'Category · Serums', 'Product', 'Bag', 'Account', 'Search results', 'Brand page', 'Wishlist'];
    for (const d of DEVICES.filter((x) => ['ip16', 'px9', 'ipse', 'fold-cover'].includes(x))) for (const bb of ['B', 'C', 'D', 'E']) for (const j of J.filter((x) => VAR_SCREENS.includes(x.label))) jobs.push({d, j, design:'A', bb, name:`${String(j.id).padStart(2, '0')}-${slug(j.label)}-bb${bb}-${d}`});
    for (const d of DEVICES.filter((x) => ['fold-inner', 'ipad'].includes(x))) for (const j of J.filter((x) => ['Home', 'Category · Serums', 'Product', 'Account'].includes(x.label))) jobs.push({d, j, design:'A', tm:'bar', name:`${String(j.id).padStart(2, '0')}-${slug(j.label)}-tmbar-${d}`});
    let done = 0; const t0 = Date.now(); const total = jobs.length;
    async function worker() {
      const page = await newPage(lang);
      for (;;) {
        const job = jobs.shift(); if (!job) break;
        const href = await page.evaluate(([id, o]) => APV.href(id, o), [job.j.id, {device:job.d, design:job.design, bb:job.bb || 'A', tm:job.tm || 'site'}]);
        await page.evaluate((h) => APV.open(h + '&z=100'), href);
        const shot = path.join(OUT, lang, job.name + '.jpg');
        const kp = !job.bb && !job.tm && KEYPICKS.find((k) => k.lang === lang && k.dev === job.d && k.label === job.j.label && job.design === 'A');
        const r = await check(page, {shot, key:kp && FULL ? path.join(KEY, kp.file) : null});
        mergeEx(exceptions, r.exceptions);
        results.push({name:job.name, lang, device:job.d, id:job.j.id, group:job.j.g, label:job.j.label, design:job.design, bb:job.bb || 'A', tm:job.tm || 'site', link:href, shot, issues:r.issues});
        if (++done % 100 === 0) process.stdout.write(`${lang} ${done}/${total} ${Math.round((Date.now() - t0) / 1000)}s\n`);
      }
      await page.close();
    }
    await Promise.all(Array.from({length:WORKERS}, worker));
  }
}
/* key pictures, taken as PNG while the grid passes them */
const KEYPICKS = ['en', 'ar'].flatMap((lang) => [
  {lang, dev:'ip16', label:'Home', file:`home-${lang}.png`},
  {lang, dev:'fold-inner', label:'Category · Serums', file:`category-fold-inner-${lang}.png`},
  {lang, dev:'fold-inner', label:'Brand page', file:`brand-fold-inner-${lang}.png`},
  {lang, dev:'duo', label:'Product', file:`product-duo-${lang}.png`},
  {lang, dev:'ipad-land', label:'Category · Skincare', file:`ipad-landscape-${lang}.png`},
]);
const slug = (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/* ── the flows: clicked and typed, like a shopper ────────────────────────── */
async function flows(lang, dev, log) {
  const page = await newPage(lang);
  const shots = {};
  const fails = [];
  const st = () => page.evaluate(() => APV.state());
  const open = async (p) => { await page.evaluate((h) => APV.open(h), `#${p}${p.includes('?') ? '&' : '?'}device=${dev}&z=100`); await settle(page); };
  const vis = (sel) => page.locator(`#stage ${sel}`).filter({visible:true}).first();
  const click = async (sel) => { try { await vis(sel).click({timeout:6000}); } catch (e) { throw new Error(`could not click ${sel} on ${(await st()).path}: ${String(e.message).split('\n')[0]}`); } await page.waitForTimeout(60); };
  const type = async (sel, v, o = {}) => { const l = vis(sel); try { if (o.fill) await l.fill(v, {timeout:6000}); else { await l.click({timeout:6000}); await l.pressSequentially(v, {delay:5}); } } catch (e) { throw new Error(`could not type in ${sel} on ${(await st()).path}: ${String(e.message).split('\n')[0]}`); } };
  const expect = (ok, msg) => { if (!ok) fails.push(msg); };
  const step = async (name, o = {}) => {
    const file = path.join(OUT, lang, `flow-${dev}-${name}.jpg`);
    const r = await check(page, {shot:file});
    log.push({flow:`${lang}/${dev}`, step:name, issues:r.issues, shot:file});
    shots[name] = file;
    return r;
  };
  try {
    /* 1 · bag → checkout (one page) → Pay now by card, as a guest, every field typed (Lane APV3) */
    await open('/home?user=0');
    const b0 = (await st()).bag;
    await click('.pc .add');
    expect((await st()).bag === b0 + 1, 'Add to bag on a home card did not raise the bag count');
    await click('[data-tab="bag"]');
    expect((await st()).path === '/bag', 'the bag icon did not open the bag');
    expect(await page.locator('#stage .kc .kbb-cartpage .ci').count() === (await st()).bagLines, 'the cart page does not list every line of the bag');
    await step('bag');
    await click('.kc a.cobtn');
    let s1 = await st();
    expect(s1.path === '/checkout', 'Proceed to checkout did not open the checkout');
    /* the rule: never more than two screens before Place order */
    expect(s1.stacks.bag.length <= 2 && (await page.evaluate(() => APV.beforePlace())) <= 2, `there are ${s1.stacks.bag.length} screens before Pay now (${s1.stacks.bag.join(' → ')}); the most is 2 (bag → checkout)`);
    await step('checkout');
    /* the coupon lives in the summary at the top, as on the site */
    await click('.kc .cosr');
    expect((await st()).sumOpen, 'the order summary did not open');
    await type('#f-coupon-code', 'SUMMER');
    await click('.kc .coupon .apply');
    expect(await page.locator('#stage [data-fld="coupon.code"].err').count() === 1, 'a wrong coupon did not show the error state');
    await step('coupon-error');
    await type('#f-coupon-code', 'welcome10', {fill:true});
    await page.dispatchEvent('#stage #f-coupon-code', 'input');
    await click('.kc .coupon .apply');
    expect(await page.locator('#stage .kc .cpn-on').count() >= 1, 'WELCOME10 was not applied');
    await step('coupon-applied');
    /* Pay now with nothing filled: every error inline, on the one page */
    await click('.kc .kbb-mobile-order [data-act="place"]');
    const nErr = await page.locator('#stage .kc .fld.err').count();
    expect((await st()).path === '/checkout' && nErr >= 5, `an empty form did not show its errors on the page (${nErr})`);
    await step('checkout-errors');
    await type('#f-co-email', 'sara@example.com');
    await type('#f-co-name', lang === 'ar' ? 'سارة المنصوري' : 'Sara Al Mansoori');
    await type('#f-co-phone', '0501234567');
    expect(await vis('#f-co-phone').inputValue() === '50 123 4567', 'the phone was not formatted as 50 123 4567');
    await type('#f-co-building', lang === 'ar' ? 'مارينا غيت 2' : 'Marina Gate 2');
    await type('#f-co-street', lang === 'ar' ? 'دبي مارينا' : 'Dubai Marina');
    await click('#f-co-emirate');
    expect((await st()).sheet === 'emirate', 'the emirate field did not open the picker');
    await step('emirate-sheet');
    await click('[data-act="pick"][data-v="dubai"]');
    const s2 = await st();
    expect(!s2.sheet && s2.co.emirate === 'dubai', 'choosing an emirate did not fill the field');
    expect(await page.locator('#stage .kc .fld.err').count() === 0 || (await st()).co.pay !== 'card', 'errors stayed after the fields were filled');
    /* the card, inline in the payment section as on the site */
    await click('.kc label[for="payment_method_card"]');
    await click('.kc .kbb-mobile-order [data-act="place"]');
    expect(await page.locator('#stage .kc .kbb-card-el.err').count() >= 2, 'an empty card did not show its errors');
    await type('#f-card-number', '4242424242424242');
    expect(await vis('#f-card-number').inputValue() === '4242 4242 4242 4242', 'the card number was not grouped in fours');
    expect(await page.locator('#stage #cbrand .cbrand').count() === 1, 'the card brand was not detected');
    await type('#f-card-exp', '1229');
    expect(await vis('#f-card-exp').inputValue() === '12 / 29', 'the expiry was not formatted MM / YY');
    await type('#f-card-cvc', '123');
    await step('checkout-filled');
    await click('.kc .kbb-mobile-order [data-act="place"]');
    expect((await st()).path === '/checkout/3ds', 'Pay now by card did not reach 3-D Secure');
    await step('after-3ds');
    await type('#f-otp-code', '481205');
    const o0 = (await st()).orders;
    await click('[data-act="otp-submit"]');
    const s8 = await st();
    expect(s8.path === '/checkout/placed' && s8.bag === 0 && s8.orders === o0 + 1, 'the bank code did not place the order and empty the bag');
    await step('after-placed');

    /* 2 · the other methods, each from the filled checkout: what follows Pay now, then the order */
    const wallet = dev === 'px9' || dev.startsWith('fold') || dev.startsWith('flip') || dev === 'duo' ? 'gpay' : 'apple';
    for (const m of [wallet, 'tabby', 'tamara', 'cod']) {
      await open('/checkout?filled=1');
      await click(`.kc label[for="payment_method_${m}"]`);
      expect((await st()).co.pay === m, `${m}: the payment choice did not take`);
      if (m === 'cod') await step('checkout-cod');
      const o1 = (await st()).orders;
      await click('.kc .kbb-mobile-order [data-act="place"]');
      if (m !== 'cod') {
        await step('after-' + (m === 'apple' || m === 'gpay' ? 'wallet' : m));
        if (m === 'tabby' || m === 'tamara') await click('[data-act="bnpl-ok"]'); else await click('[data-act="authorise"]');
      }
      const s = await st();
      expect(s.path === '/checkout/placed' && s.orders === o1 + 1 && s.bag === 0, `${m}: Pay now did not end in the order placed`);
    }
    /* 3 · a declined card keeps the bag; Try again returns to the checkout with the card chosen */
    await open('/checkout/3ds');
    const bagBefore = (await st()).bag;
    await click('[data-act="decline"]');
    expect((await st()).path.startsWith('/checkout/failed'), 'a declined card did not show Payment failed');
    await step('after-failed');
    expect((await st()).bag === bagBefore, 'the bag was not kept after a declined payment');
    await click('[data-act="retry-pay"]');
    const sr = await st();
    expect(sr.path === '/checkout' && sr.co.pay === 'card', 'Try again did not return to the checkout with the card');

    /* 4 · navigation smoke: product → back → tab switch → state kept */
    await open('/home');
    await click('.pc .open.meta');
    let s = await st();
    expect(s.path.startsWith('/product/'), 'a product card did not open the product');
    const pslug = s.path.split('/')[2];
    await step('nav-product');
    await click('[data-act="back"]');
    expect((await st()).path === '/home', 'Back did not return to Home');
    /* tab switch keeps each tab's stack (phones: the bottom bar; tablets: the header's account and logo) */
    await open('/brands');
    await click('[data-go^="/brand/"]');
    const before = await st();
    if (await page.locator('#stage .tabbar').filter({visible:true}).count()) {
      await click('.tabbar [data-tab="home"]');
      expect((await st()).tab === 'home', 'the Home tab did not open');
      await click('.tabbar [data-tab="shop"]');
      const after = await st();
      expect(after.tab === 'shop' && after.path === before.path && after.depth === before.depth, `the Shop tab lost its place (${before.path} → ${after.path})`);
    } else {
      await click('.kh-hd .ib-acct [data-tab="account"]');
      expect((await st()).tab === 'account', "the header's account icon did not open Account");
      await click('.kh-hd .logo');
      const after = await st();
      expect(after.tab === 'home' && after.path === '/home', "the header's logo did not open Home");
    }
    await open('/product/' + pslug);
    await click('.pbar .fv');
    expect((await st()).wish.includes(pslug), 'the wishlist heart did not save the product');
    await page.reload(); await page.waitForFunction(() => window.APV); await settle(page);
    expect((await st()).wish.includes(pslug), 'the wishlist was not kept for the session');
    /* edge swipe back (iOS from the start edge; Android predictive back from either edge) */
    await open('/home');
    await click('.pc .open.meta');
    const g = await page.locator('#stage .glass').first().boundingBox();
    const x0 = lang === 'ar' ? g.x + g.width - 6 : g.x + 6, dir = lang === 'ar' ? -1 : 1;
    await page.mouse.move(x0, g.y + g.height / 2); await page.mouse.down();
    for (let i = 1; i <= 8; i++) await page.mouse.move(x0 + dir * i * 20, g.y + g.height / 2);
    await page.mouse.up(); await page.waitForTimeout(250);
    expect((await st()).path === '/home', 'the edge swipe did not go back');
    /* live search keeps its query across product → back */
    await click('[data-tab="search"]');
    await type('#q', lang === 'ar' ? 'سيروم' : 'serum');
    const hits = await page.locator('#stage #sres [data-go^="/product/"]').count();
    expect(hits > 0, 'live search found nothing');
    await step('nav-search');
    await click('#sres [data-go^="/product/"]');
    if (dev !== 'duo') await click('[data-act="back"]'); // on the Duo the result opens in the other screen: nothing to go back from
    expect(await vis('#q').inputValue() === (lang === 'ar' ? 'سيروم' : 'serum'), 'the search query was lost after product → back');
    /* sort and filter really sort and filter */
    await open('/category/serums');
    await click('[data-sheet="sort"]');
    await click('[data-act="sort"][data-v="low"]');
    const prices = await page.$$eval('#stage .grid .pc .pr .price', (els) => els.slice(0, 8).map((e) => Number(e.textContent.replace(/[^\d.٠-٩]/g, '').replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)))));
    expect(prices.length > 2 && prices.every((p, i) => !i || p >= prices[i - 1]), 'Price: low to high did not sort: ' + prices.join(','));
    const filterBtn = await page.locator('#stage [data-sheet="filter"]').filter({visible:true}).count();
    if (filterBtn) { await click('[data-sheet="filter"]'); await click('[data-act="fmax"][data-v="54"]'); await click('.sheet .sf [data-act="close"]'); }
    else await click('.fpanel [data-act="fmax"][data-v="54"]');
    const p2 = await page.$$eval('#stage .grid .pc .pr .price', (els) => els.map((e) => Number(e.textContent.replace(/[^\d.]/g, ''))));
    expect(p2.length > 0 && p2.every((p) => p <= 54), 'Up to AED 54 did not filter: ' + p2.slice(0, 6).join(','));
    await step('nav-filtered');
    /* endless scroll */
    await open('/products');
    const n0 = await page.locator('#stage .grid .pc').count();
    await page.evaluate(() => { const b = document.querySelector('#stage .body'); b.scrollTop = b.scrollHeight; });
    await page.waitForTimeout(900);
    const n1 = await page.locator('#stage .grid .pc').count();
    expect(n1 > n0, `endless scroll did not load more (${n0} → ${n1})`);
    /* pull to refresh */
    await open('/home');
    const hb = await page.locator('#stage .body[data-ptr]').first().boundingBox();
    await page.mouse.move(hb.x + hb.width / 2, hb.y + 40); await page.mouse.down();
    for (let i = 1; i <= 10; i++) await page.mouse.move(hb.x + hb.width / 2, hb.y + 40 + i * 18);
    await page.mouse.up(); await page.waitForTimeout(900);
    expect(await page.locator('#stage .toast').count() === 1, 'pull to refresh did not refresh');
    /* list → detail on two panes: a tap updates the detail, nothing is pushed */
    if (dev === 'duo') {
      await open('/categories');
      const depth = (await st()).depth;
      await click('[data-slot="start"] [data-go^="/category/"]');
      expect((await st()).depth === depth, 'on two panes a category tap pushed a screen instead of filling the detail pane');
    }
  } catch (e) {
    fails.push('flow stopped: ' + String(e.message || e).split('\n')[0]);
  }
  for (const e of page.errs.splice(0)) fails.push('console: ' + e);
  await page.close();
  return {shots, fails};
}

/* ── Lane APV3: the website's menus, header and the bottom-bar variants, clicked ── */
async function siteFlows(lang, dev, log) {
  const page = await newPage(lang);
  const shots = {}, fails = [];
  const st = () => page.evaluate(() => APV.state());
  const open = async (p, extra = '') => { await page.evaluate((h) => APV.open(h), `#${p}${p.includes('?') ? '&' : '?'}device=${dev}&z=100${extra}`); await settle(page); };
  const vis = (sel) => page.locator(`#stage ${sel}`).filter({visible:true});
  const click = async (sel, n = 0) => { try { await vis(sel).nth(n).click({timeout:6000}); } catch (e) { throw new Error(`could not click ${sel} #${n} on ${(await st()).path}: ${String(e.message).split('\n')[0]}`); } await page.waitForTimeout(60); };
  const expect = (ok, msg) => { if (!ok) fails.push(msg); };
  const step = async (name) => { const file = path.join(OUT, lang, `site-${dev}-${name}.jpg`); const r = await check(page, {shot:file}); log.push({flow:`${lang}/${dev}`, step:name, issues:r.issues, shot:file}); shots[name] = file; return r; };
  /* the header region of the frame, for the site-vs-app pictures */
  const headerShot = async (name) => {
    if (NOSHOTS) return;
    const g = await page.locator('#stage .glass').first().boundingBox();
    const h = await page.evaluate(() => { const e = document.querySelector('#stage .kh-hd'); return e ? e.getBoundingClientRect().height : 120; });
    const file = path.join(OUT, lang, `site-${dev}-${name}.png`);
    await page.screenshot({path:file, clip:{x:g.x, y:g.y, width:g.width, height:Math.ceil(h) + 40}});
    shots[name] = file;
  };
  const go = async (sel, n, what) => {
    const want = await vis(sel).nth(n).evaluate((e) => ({go:e.dataset.go || null, tab:e.dataset.tab || null, txt:e.textContent.replace(/\s+/g, ' ').trim().slice(0, 40)}));
    await click(sel, n);
    const s = await st();
    if (want.go) expect(s.path === want.go.split('#')[0], `${what} “${want.txt}” went to ${s.path}, not ${want.go}`);
    else if (want.tab) expect(s.tab === want.tab, `${what} “${want.txt}” opened the ${s.tab} tab, not ${want.tab}`);
    else fails.push(`${what} “${want.txt}” has no destination`);
    expect(!s.menu && s.mega == null, `${what} “${want.txt}” left the menu open`);
    return want;
  };
  try {
    const W = await (async () => { await open('/home'); return page.evaluate(() => document.querySelector('#stage .glass').offsetWidth); })();
    const drawer = W <= 900;
    await headerShot('header');
    await step('header-home');
    /* the header: icons, logo, search box, badges, language switch */
    await open('/category/serums');
    const s0 = await st();
    const badge = await vis('.kh-hd .id-cartCt').first().textContent().catch(() => '');
    expect(badge.replace(/[^\d٠-٩]/g, '') !== '' && Number(badge.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))) === s0.bag, `the header's bag badge reads “${badge}”, the bag holds ${s0.bag}`);
    const lhref = await vis('.kh-hd [data-lang]').first().getAttribute('href');
    expect(lhref && lhref.startsWith(lang === 'ar' ? '../en/index.html#/category/serums' : '../ar/index.html#/category/serums'), `the language switch points at ${lhref}`);
    await click('.kh-hd .ib-acct [data-tab="account"]');
    expect((await st()).tab === 'account', "the header's account icon did not open Account");
    await open('/category/serums');
    await click('.kh-hd [data-tab="bag"]');
    expect((await st()).path === '/bag', "the header's cart did not open the bag");
    await open('/category/serums');
    await click('.kh-hd .logo');
    expect((await st()).path === '/home', "the header's logo did not go Home");
    await open('/category/serums');
    await click('.kh-hd .search-in');
    expect((await st()).tab === 'search' && await page.evaluate(() => document.activeElement && document.activeElement.id === 'q' && !!document.activeElement.closest('.kh-hd')), "the header's search box did not become the search field");
    await page.keyboard.type(lang === 'ar' ? 'سيروم' : 'serum', {delay:5});
    expect(await page.locator('#stage #sres [data-go^="/product/"]').count() > 0, "typing in the header's search box found nothing");
    await step('header-search');
    if (drawer) {
      /* the site's phone menu: open, every top-level item, a sub-level of each, chips, close */
      await open('/home');
      await click('[data-act="menu"]');
      expect((await st()).menu && await vis('.mmenu.on').count() === 1, 'the ☰ did not open the menu');
      await step('menu-open');
      const nTop = await page.locator('#stage .mmenu .mm-body > .mm-node, #stage .mmenu .mm-body > a.mm-it').count();
      const nPar = await page.locator('#stage .mmenu .mm-node').count();
      expect(nTop >= 8, `the menu has ${nTop} top-level items`);
      for (let i = 0; i < nPar; i++) {
        await open('/home'); await click('[data-act="menu"]');
        await click('.mmenu .mm-node .mm-par', i);
        const on = await page.evaluate((i) => { const n = document.querySelectorAll('#stage .mmenu .mm-node')[i]; return n.classList.contains('on') && [...n.querySelectorAll('.mm-si')].filter((a) => a.getBoundingClientRect().height > 0).length; }, i);
        expect(on > 0, `menu level ${i + 1} did not open its sub-items`);
        if (i === 2) await step('menu-level');
        await go('.mmenu .mm-node.on .mm-si', 0, 'menu sub-item');
      }
      const nIt = await page.locator('#stage .mmenu .mm-body > a.mm-it').count();
      for (let i = 0; i < Math.max(nIt, 1); i++) { await open('/home'); await click('[data-act="menu"]'); if (nIt) await go('.mmenu .mm-body > a.mm-it', i, 'menu item'); }
      await open('/home'); await click('[data-act="menu"]');
      const nChip = await page.locator('#stage .mmenu .mm-chip').count();
      expect(nChip >= 3, `the menu has ${nChip} chips`);
      for (let i = 0; i < nChip; i++) { await open('/home'); await click('[data-act="menu"]'); await page.locator('#stage .mmenu .mm-chip').nth(i).scrollIntoViewIfNeeded(); await go('.mmenu .mm-chip', i, 'menu chip'); }
      await open('/home'); await click('[data-act="menu"]'); await go('.mmenu .mm-acs a', 0, 'menu account');
      await open('/home'); await click('[data-act="menu"]');
      const mh = await vis('.mmenu [data-lang]').first().getAttribute('href').catch(() => null);
      expect(mh && mh.includes(lang === 'ar' ? '../en/index.html#/home' : '../ar/index.html#/home'), `the menu's language switch points at ${mh}`);
      await click('.mmenu .id-mmx');
      expect(!(await st()).menu, 'the × did not close the menu');
      await click('[data-act="menu"]');
      const gb = await page.locator('#stage .glass').first().boundingBox();
      await page.mouse.click(lang === 'ar' ? gb.x + 20 : gb.x + gb.width - 20, gb.y + gb.height * 0.3);
      await page.waitForTimeout(80);
      expect(!(await st()).menu, 'a tap on the scrim did not close the menu');
      /* the site's "Search the menu…" filter */
      if (await page.locator('#stage .kh-hd').count()) {
        await click('[data-act="menu"]');
        if (await vis('.mmenu .id-mmFilter').count()) {
          await vis('.mmenu .id-mmFilter').first().fill(lang === 'ar' ? 'سيروم' : 'serum');
          await page.dispatchEvent('#stage .mmenu .id-mmFilter', 'input');
          const shown = await page.evaluate(() => [...document.querySelectorAll('#stage .mmenu .mm-si,#stage .mmenu .mm-body>a.mm-it')].filter((a) => a.getBoundingClientRect().height > 0).map((a) => a.textContent.trim()));
          expect(shown.length > 0 && shown.length < 20, `the menu filter shows ${shown.length} items`);
          await step('menu-filter');
        }
      }
    }
    /* the site's menu bar and its mega panels: iPad landscape as the site; tablets with "menu bar" */
    for (const extra of W > 1000 ? [''] : W >= 600 ? ['&tm=bar'] : []) {
      await open('/home', extra);
      const n = await page.locator('#stage .kh-hd .mbar .navitem').count();
      expect(n >= 8, `the menu bar has ${n} items`);
      for (let i = 0; i < n; i++) {
        await open('/home', extra);
        const mega = await page.locator('#stage .kh-hd .mbar .navitem').nth(i).locator('[data-act="mega"]').count();
        if (mega) {
          await page.locator('#stage .kh-hd .mbar .navitem').nth(i).locator('[data-act="mega"]').click();
          await page.waitForTimeout(260); // the site's panel fades in over .16s
          const open1 = await page.evaluate((i) => { const it = document.querySelectorAll('#stage .kh-hd .mbar .navitem')[i]; const d = it.querySelector('.drop'); return it.classList.contains('kopen') && d && getComputedStyle(d).visibility === 'visible'; }, i);
          expect(open1, `menu bar item ${i + 1}: the panel did not open`);
          if (i === 2 || (i === 1 && extra)) { await step('mega-open' + (extra ? '-bar' : '')); }
          await go('.kh-hd .mbar .navitem.kopen .drop a[data-go]', 0, 'mega panel link');
        } else await go('.kh-hd .mbar .navitem .navlink', i, 'menu bar item');
      }
      const direct = await page.locator('#stage .kh-hd .mbar .wrap > a[data-go]').count();
      for (let i = 0; i < direct; i++) { await open('/home', extra); await go('.kh-hd .mbar .wrap > a[data-go]', i, 'menu bar link'); }
    }
    /* every bottom-bar variant on the phones: each tab, each More tile, the badges */
    if (['ip16', 'px9'].includes(dev)) {
      for (const bb of ['A', 'B', 'C', 'D', 'E']) {
        await open('/home', '&bb=' + bb);
        const s = await st();
        expect(s.bb === bb, `bottom bar ${bb} was not applied`);
        await step('bb-' + bb);
        const bagB = await page.evaluate(() => { const b = document.querySelector('#stage .tabbar [data-tab="bag"] .bdg'); return b ? b.textContent : null; });
        if (['A', 'C', 'D', 'E'].includes(bb)) expect(bagB !== null && Number(bagB.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))) === s.bag, `bar ${bb}: bag badge ${bagB}, bag ${s.bag}`);
        if (['C', 'D'].includes(bb)) { const wB = await page.evaluate(() => { const b = document.querySelector('#stage .tabbar [data-tab="wish"] .bdg'); return b ? b.textContent : null; }); expect(wB !== null && Number(wB.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))) === s.wish.length, `bar ${bb}: wishlist badge ${wB}, wishlist ${s.wish.length}`); }
        const tabs = await page.$$eval('#stage .tabbar .tab', (L) => L.map((b) => b.dataset.tab || b.dataset.act));
        expect(tabs.length === 5, `bar ${bb} has ${tabs.length} items`);
        for (let i = 0; i < tabs.length; i++) {
          await open('/home', '&bb=' + bb);
          if (tabs[i] === 'home') await open('/category/serums', '&bb=' + bb);
          await click('.tabbar .tab', i);
          const s2 = await st();
          if (tabs[i] === 'more') {
            expect(s2.sheet === 'more', `bar ${bb}: More did not open its sheet`);
            await step('bb-E-more');
            const tiles = await page.locator('#stage .sheet .mtile').count();
            expect(tiles === 8, `the More sheet has ${tiles} tiles`);
            for (let k = 0; k < tiles; k++) { await open('/home', '&bb=' + bb); await click('.tabbar .tab', i); await go('.sheet .mtile', k, 'More tile'); }
          } else {
            expect(s2.tab === tabs[i] && s2.depth === 1, `bar ${bb}: tab ${tabs[i]} opened ${s2.tab} at depth ${s2.depth}`);
            const on = await page.evaluate((i) => document.querySelectorAll('#stage .tabbar .tab')[i].classList.contains('on'), i);
            expect(on, `bar ${bb}: tab ${tabs[i]} is not shown as active`);
          }
        }
      }
    }
  } catch (e) {
    fails.push('flow stopped: ' + String(e.message || e).split('\n')[0]);
  }
  for (const e of page.errs.splice(0)) fails.push('console: ' + e);
  await page.close();
  return {shots, fails};
}

/* the whole length of a screen: the frame's body scrolled a screenful at a time, stacked */
async function fullLength(lang, hash, file) {
  const page = await newPage(lang);
  await page.evaluate((h) => APV.open(h), hash + '&z=100');
  await settle(page);
  await page.addStyleTag({content:'#stage .homeind{visibility:hidden}'}); // stitched: no home indicator in the middle of the page
  const g = await page.locator('#stage .glass').first().boundingBox();
  const parts = [];
  const info = await page.evaluate(() => { const b = document.querySelector('#stage .pane .body'); const r = b.getBoundingClientRect(); return {top:r.top, h:b.clientHeight, sh:b.scrollHeight}; });
  for (let y = 0, i = 0; ; i++) {
    await page.evaluate((y) => { document.querySelector('#stage .pane .body').scrollTop = y; }, y);
    await page.waitForTimeout(250); await settle(page);
    const f = file.replace(/\.png$/, `.part${i}.png`);
    const first = i === 0;
    const at = await page.evaluate(() => document.querySelector('#stage .pane .body').scrollTop);
    const clipTop = first ? g.y : info.top;
    const clipH = first ? info.top - g.y + info.h : info.h;
    await page.screenshot({path:f, clip:{x:g.x, y:clipTop, width:g.width, height:clipH}});
    parts.push({f, skip:first ? 0 : (y - at) === 0 ? 0 : 0});
    if (at + info.h >= info.sh - 1) {
      const last = info.sh - info.h; // the final scroll stops short: keep only the new part of the last screenful
      if (i > 0) parts[i].crop = (y - at);
      break;
    }
    y += info.h;
  }
  await page.close();
  const pg = await ctx.newPage();
  await pg.setViewportSize({width:Math.ceil(g.width), height:800});
  const html = `<!doctype html><style>body{margin:0;background:#fff}div{width:${g.width}px}img{display:block;width:${g.width}px}</style><div>${parts.map((p, i) => `<div style="overflow:hidden;${p.crop ? `margin-top:0` : ''}"><img src="file://${p.f}" style="${p.crop ? `margin-top:-${p.crop}px` : ''}"></div>`).join('')}</div>`;
  const tmp = file + '.html'; fs.writeFileSync(tmp, html);
  await pg.goto('file://' + tmp, {waitUntil:'load'});
  await pg.screenshot({path:file, fullPage:true});
  await pg.close();
  fs.unlinkSync(tmp); parts.forEach((p) => fs.unlinkSync(p.f));
  return file;
}

/* ── pictures made of pictures ───────────────────────────────────────────── */
async function compose(file, title, items, o = {}) {
  const page = await ctx.newPage();
  const w = o.w || 220;
  const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:18px;font:13px system-ui;background:#f3eef0;width:${o.width || 1800}px;color:#2a2228}h1{font:600 19px system-ui;margin:0 0 14px}div{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start}figure{margin:0;width:${w}px}img{width:100%;display:block;border-radius:10px}figcaption{padding:5px 0 0;line-height:1.3}.bad{outline:3px solid #c0392b}</style><h1>${title}</h1><div>${items.map((x) => `<figure><img class="${x.bad ? 'bad' : ''}" src="file://${x.src}"><figcaption>${x.cap}</figcaption></figure>`).join('')}</div>`;
  const tmp = path.join(OUT, '.compose.html');
  fs.writeFileSync(tmp, html);
  await page.goto('file://' + tmp, {waitUntil:'load'});
  fs.unlinkSync(tmp);
  await page.screenshot({path:file, fullPage:true, type:file.endsWith('.jpg') ? 'jpeg' : 'png', quality:file.endsWith('.jpg') ? 72 : undefined});
  await page.close();
}

(async () => {
  fs.mkdirSync(OUT, {recursive:true});
  fs.mkdirSync(KEY, {recursive:true});
  browser = await pw.chromium.launch({executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome', proxy:process.env.HTTPS_PROXY ? {server:process.env.HTTPS_PROXY} : undefined});
  ctx = await browser.newContext({viewport:{width:1400, height:1100}, reducedMotion:'reduce'});
  const t0 = Date.now();
  const results = [], exceptions = {}, flowLog = [], flowFails = {};
  if (!FLOWS_ONLY) await grid(results, exceptions);
  const flowShots = {}, siteShots = {};
  if (!NO_FLOWS) {
    const FD = arg('dev') ? DEVICES : ['ip16', 'px9', 'fold-inner', 'duo'];
    for (const lang of LANGS) for (const d of FD) {
      const r = await flows(lang, d, flowLog);
      flowFails[`${lang}/${d}`] = r.fails;
      flowShots[`${lang}/${d}`] = r.shots;
      process.stdout.write(`flow ${lang}/${d}: ${r.fails.length} failures\n`);
    }
    const SD = arg('dev') ? DEVICES : ['ip16', 'px9', 'fold-cover', 'fold-inner', 'ipad', 'ipad-land', 'duo', 'flip-tabletop'];
    for (const lang of LANGS) for (const d of SD) {
      const r = await siteFlows(lang, d, flowLog);
      flowFails[`${lang}/${d}/site`] = r.fails;
      siteShots[`${lang}/${d}`] = r.shots;
      process.stdout.write(`site flow ${lang}/${d}: ${r.fails.length} failures\n`);
    }
  }

  /* screens.html: one live card per screen, every card a working deep link, no console error */
  const screensCheck = {};
  if (!FLOWS_ONLY) for (const lang of LANGS) {
    const page = await ctx.newPage(); const errs = [];
    page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text().slice(0, 160)); });
    page.on('pageerror', (e) => errs.push(e.message));
    await page.goto('file://' + path.join(APP, lang, 'screens.html') + '#device=ip16');
    await page.waitForTimeout(2500);
    const r = await page.evaluate(() => ({cards:document.querySelectorAll('.card').length, links:[...document.querySelectorAll('.card iframe')].filter((f) => /index\.html#\/.+device=ip16.*embed=1/.test(f.getAttribute('src'))).length, sw:document.documentElement.scrollWidth, w:innerWidth}));
    const want = arg('only') ? r.cards : new Set(results.filter((x) => x.lang === lang).map((x) => x.id + x.design)).size || r.cards;
    screensCheck[lang] = {...r, errors:[...new Set(errs)]};
    if (r.cards !== want || r.links !== r.cards || r.sw > r.w || errs.length) results.push({name:'screens.html', lang, device:'-', id:-1, label:'screens.html', design:'A', issues:[{kind:'screens', at:lang + '/screens.html', msg:JSON.stringify(screensCheck[lang])}]});
    await page.close();
  }
  /* contact sheets (JPEG, kept in git) */
  if (!NOSHOTS && FULL && !FLOWS_ONLY) {
    for (const d of DEVICES) for (const lang of LANGS) {
      const rows = results.filter((r) => r.device === d && r.lang === lang).sort((a, b) => a.id - b.id || a.design.localeCompare(b.design));
      if (!rows.length) continue;
      const w = d === 'duo' || d === 'ipad-land' ? 380 : d === 'ipad' || d === 'fold-inner' ? 250 : 170;
      await compose(path.join(OUT, `sheet-${d}-${lang}.jpg`), `${lang === 'ar' ? 'Arabic' : 'English'} · ${d} · ${rows.length} screens · ${rows.filter((r) => r.issues.length).length} with issues`, rows.map((r) => ({src:r.shot, cap:`${r.id} · ${r.label}${r.design !== 'A' ? ' · ' + r.design : ''}`, bad:r.issues.length})), {w});
    }
  }
  /* checkout strips and key pictures */
  if (!NOSHOTS && !NO_FLOWS) {
    fs.mkdirSync(KEY, {recursive:true});
    /* Lane APV3: bag → checkout is the whole road; the after-tap states follow */
    const ORDER = [['bag', 'Bag (the site\'s cart page)'], ['checkout', 'Checkout · the one page'], ['coupon-applied', 'Coupon in the summary'], ['checkout-errors', 'Pay now with gaps: inline errors'], ['emirate-sheet', 'Emirate picker over the page'], ['checkout-filled', 'Filled, card inline'], ['after-3ds', 'After Pay now · 3-D Secure'], ['after-wallet', 'After Pay now · wallet sheet'], ['after-tabby', 'After Pay now · Tabby'], ['after-tamara', 'After Pay now · Tamara'], ['after-placed', 'Order placed'], ['after-failed', 'Payment failed, bag kept']];
    for (const lang of LANGS) {
      const S = flowShots[`${lang}/ip16`]; if (!S) continue;
      await compose(path.join(KEY, `${lang}-checkout-flow.png`), `${lang === 'ar' ? 'Arabic' : 'English'} app · bag → checkout (two screens) → Pay now, clicked and typed by tools/apv-shots.cjs (iPhone 16 Pro)`, ORDER.filter(([k]) => S[k]).map(([k, c]) => ({src:S[k], cap:c})), {w:230, width:1800});
    }
  }
  if (!NOSHOTS && FULL) {
    const fl = (lang, k) => (flowShots[`${lang}/ip16`] || {})[k];
    await compose(path.join(KEY, 'floating-label-before.png'), 'Floating labels, before: the label is the placeholder, centred in the box (English, Arabic)', [{src:fl('en', 'checkout'), cap:'English · empty'}, {src:fl('ar', 'checkout'), cap:'Arabic · empty'}].filter((x) => x.src), {w:420, width:900});
    await compose(path.join(KEY, 'floating-label-after.png'), 'Floating labels, after: typed values; each label lifts to a small heading at the top-start corner', [{src:fl('en', 'checkout-filled'), cap:'English · filled'}, {src:fl('ar', 'checkout-filled'), cap:'Arabic · filled'}].filter((x) => x.src), {w:420, width:900});
  }
  /* Lane APV3 key pictures: site vs app headers, the menus, the bottom bars */
  if (!NOSHOTS && !NO_FLOWS && !arg('dev')) {
    const SITE_SHOTS = path.join(OUT, 'site');
    const ss = (lang, f) => { const p2 = path.join(SITE_SHOTS, `${lang}-${f}.png`); return fs.existsSync(p2) ? p2 : null; };
    const ap = (lang, d, k) => (siteShots[`${lang}/${d}`] || {})[k];
    const L = (lang) => lang === 'ar' ? 'Arabic' : 'English';
    for (const lang of LANGS) {
      const m = (w) => SITEM[lang][w];
      await compose(path.join(KEY, `header-phone-${lang}.png`), `${L(lang)} · the website's header (left, live kbeautybliss.com${lang === 'ar' ? '/ar/' : '/'}) and the app's (right) at 402 wide: header ${m(402).h}px, search box ${m(402).si.h}px, corners ${m(402).si.r}, logo ${m(402).icon.w}×${m(402).icon.h}. The app adds only the phone's status bar above it.`, [{src:ss(lang, 402), cap:'Website, 402 px'}, {src:ap(lang, 'ip16', 'header'), cap:'App, iPhone 16 Pro (402 pt)'}, {src:ss(lang, 412), cap:'Website, 412 px'}, {src:ap(lang, 'px9', 'header'), cap:'App, Pixel 9 (412 dp)'}].filter((x) => x.src), {w:402, width:1700});
      await compose(path.join(KEY, `header-tablet-${lang}.png`), `${L(lang)} · the website's header and the app's on the Galaxy Z Fold inner screen (673) and iPad mini (744 portrait; 1133 landscape with the site's menu bar)`, [{src:ss(lang, 673), cap:'Website, 673 px'}, {src:ap(lang, 'fold-inner', 'header'), cap:'App, Fold inner (673 dp)'}, {src:ss(lang, 744), cap:'Website, 744 px'}, {src:ap(lang, 'ipad', 'header'), cap:'App, iPad mini portrait (744 pt)'}, {src:ss(lang, 1133), cap:'Website, 1133 px'}, {src:ap(lang, 'ipad-land', 'header'), cap:'App, iPad mini landscape (1133 pt)'}].filter((x) => x.src), {w:760, width:1600});
      await compose(path.join(KEY, `menu-phone-${lang}.png`), `${L(lang)} · the site's phone menu (☰): website at 402 px, then the app on iPhone 16 Pro, closed levels and a level opened`, [{src:ss(lang, '402-menu'), cap:'Website · menu'}, {src:ap(lang, 'ip16', 'menu-open') && ap(lang, 'ip16', 'menu-open'), cap:'App · menu'}, {src:ss(lang, '402-menu-open'), cap:'Website · Skincare opened'}, {src:ap(lang, 'ip16', 'menu-level'), cap:'App · Skincare opened'}].filter((x) => x.src), {w:330, width:1450});
      await compose(path.join(KEY, `menu-tablet-${lang}.png`), `${L(lang)} · tablets: iPad landscape shows the site's menu bar and its mega panels (website left, app right); the Fold inner shows what the site shows at 673 (the ☰ menu), or the menu bar with “Tablet menu: menu bar”`, [{src:ss(lang, '1133-mega'), cap:'Website · 1133 px · Skincare panel'}, {src:ap(lang, 'ipad-land', 'mega-open'), cap:'App · iPad landscape · Skincare panel'}, {src:ss(lang, '673-menu'), cap:'Website · 673 px · menu'}, {src:ap(lang, 'fold-inner', 'menu-open'), cap:'App · Fold inner · menu'}, {src:ap(lang, 'fold-inner', 'mega-open-bar'), cap:'App · Fold inner · “menu bar” option, Skincare panel'}].filter((x) => x.src), {w:560, width:1800});
      await compose(path.join(KEY, `bottom-bars-${lang}.png`), `${L(lang)} · bottom bar options on iPhone 16 Pro — A (now) · B · C · D · E, and E's More sheet. Pick a letter: “Bottom bar” chooser beside the phone.`, ['A', 'B', 'C', 'D', 'E'].map((b) => ({src:ap(lang, 'ip16', 'bb-' + b), cap:{A:'A · Home · Shop · Search · Bag · Account (now)', B:'B · Home · Categories · Brands · Offers · Account', C:'C · Home · Shop · Wishlist · Bag · Account', D:'D · Home · Categories · raised Bag · Wishlist · Account', E:'E · Home · Shop · Offers · Bag · More'}[b]})).concat([{src:ap(lang, 'ip16', 'bb-E-more'), cap:'E · More sheet'}]).filter((x) => x.src), {w:280, width:1860});
    }
  }
  /* Lane APV3: the site's cart and one-page checkout beside the app's, full length, and what follows Pay now */
  if (!NOSHOTS && !arg('dev')) {
    const SITE_SHOTS = path.join(OUT, 'site');
    for (const lang of LANGS) {
      const L = lang === 'ar' ? 'Arabic' : 'English';
      const ss = (f) => { const p2 = path.join(SITE_SHOTS, `${lang}-${f}.png`); return fs.existsSync(p2) ? p2 : null; };
      const cartApp = await fullLength(lang, '#/bag?device=ip16', path.join(OUT, lang, 'full-cart.png'));
      const coApp = await fullLength(lang, '#/checkout?guest=1&user=0&device=ip16', path.join(OUT, lang, 'full-checkout.png'));
      await compose(path.join(KEY, `${lang}-cart.png`), `${L} · the bag: the site's cart page (left, at 390) and the app's (right, iPhone 16 Pro, under its header). Same sections, same order, same words.`, [{src:ss('cart'), cap:'Website · cart page'}, {src:cartApp, cap:'App · Bag'}].filter((x) => x.src), {w:402, width:900});
      const F = flowShots[`${lang}/ip16`] || {};
      await compose(path.join(KEY, `${lang}-checkout.png`), `${L} · the checkout is ONE page, as on the site: website (left, 390) and app (right), full length. Bag → checkout = two screens before Pay now. Then, after the tap (not steps): 3-D Secure, the wallet sheet, Tabby / Tamara approving in the app, the order placed, a declined payment with the bag kept.`, [{src:ss('checkout'), cap:'Website · one-page checkout'}, {src:coApp, cap:'App · one-page checkout'}].filter((x) => x.src).concat([['after-3ds', 'After Pay now · 3-D Secure'], ['after-wallet', 'After Pay now · Apple Pay sheet'], ['after-tabby', 'After Pay now · Tabby'], ['after-tamara', 'After Pay now · Tamara'], ['after-placed', 'Order placed'], ['after-failed', 'Payment failed · bag kept']].filter(([k]) => F[k]).map(([k, c]) => ({src:F[k], cap:c}))), {w:402, width:1950});
    }
  }
  await browser.close();

  const bad = results.filter((r) => r.issues.length);
  const flowBad = flowLog.filter((r) => r.issues.length);
  const byKind = {};
  for (const r of results.concat(flowLog)) for (const i of r.issues) byKind[i.kind] = (byKind[i.kind] || 0) + 1;
  const nFlowFails = Object.values(flowFails).reduce((a, b) => a + b.length, 0);
  const report = {
    generated:new Date().toISOString(), renders:results.length, screens:new Set(results.map((r) => r.lang + r.id + r.design)).size,
    devices:DEVICES, locales:LANGS, failed:bad.length, issues_by_kind:byKind,
    flows:{steps:flowLog.length, steps_with_issues:flowBad.length, assertions_failed:nFlowFails, by_run:flowFails},
    screens_html:screensCheck,
    variants:{bottom_bar:results.filter((r) => r.bb !== 'A').length, tablet_menu_bar:results.filter((r) => r.tm === 'bar').length},
    site_findings:['The site\'s own search field is 34px high on phones (under the 44px tap target): the app copies it, as asked.', 'The site\'s delivery strip is white on #E991AE, 2.3:1 contrast: the app copies it, as asked.'],
    arabic_exceptions:exceptions,
    failures:bad.map((r) => ({name:`${r.lang}/${r.name}`, link:r.link, issues:r.issues.slice(0, 12)})).concat(flowBad.map((r) => ({name:`flow ${r.flow} ${r.step}`, issues:r.issues.slice(0, 12)}))),
  };
  if (FULL) fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify({renders:report.renders, screens:report.screens, failed:report.failed, flow_steps:flowLog.length, flow_step_issues:flowBad.length, flow_assertions_failed:nFlowFails, issues_by_kind:byKind, secs:Math.round((Date.now() - t0) / 1000)}));
  for (const r of report.failures.slice(0, Number((arg('show') || ['40'])[0]))) console.log(r.name, JSON.stringify(r.issues.slice(0, 5)));
  for (const [k, v] of Object.entries(flowFails)) if (v.length) console.log('FLOW', k, JSON.stringify(v));
  process.exit(bad.length || flowBad.length || nFlowFails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
