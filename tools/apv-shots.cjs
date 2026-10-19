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
const DEVICES = ['ip16', 'ipse', 'ipad', 'ipad-land', 'px9', 'flip', 'flip-tabletop', 'fold-cover', 'fold-inner', 'duo'].filter((d) => !arg('dev') || arg('dev').includes(d));
const LANGS = arg('lang') || ['en', 'ar'];
const FULL = !arg('dev') && !arg('lang') && !arg('only');

/* ── the in-page checker ─────────────────────────────────────────────────── */
function audit(lang) {
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
  const stackOf = (e) => e.closest('.ovl') ? 'ovl' : e.closest('.viewer') ? 'viewer' : e.closest('.toast') ? 'toast' : e.closest('[data-layer="pb"]') ? 'peekbg' : 'base';
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
      if (r.el.closest('.zh,.cnt,.badge,.bdg,.pbk,.tt-fold,.blogo,.cth')) continue;
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
      if (hidden(m) || m.closest('.sb,.splash,.compan,.bio,.done-mark,.state .ic,.scanv,.lgx')) return;
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
      if (e.closest('.fl,.srch.field') && e.matches('input,textarea')) return; // the field box is the target
      const r = e.getBoundingClientRect();
      if (area(visible(e, r, true)) <= 0) return;
      if (r.width < 43.5 || r.height < 43.5) add('tap', e, `${Math.round(r.width)}×${Math.round(r.height)}`);
    });

    /* 5. safe areas: fixed text / controls out of the status band and home-indicator band */
    const fixedEls = [...T].filter((e) => !e.matches('.scrim')).map((e) => ({e, r:e.getBoundingClientRect()})).concat(runs.map((x) => ({e:x.el, r:x.n && x.rr})));
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
  const {issues, exceptions} = await page.evaluate(audit, page.lang);
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
    let done = 0; const t0 = Date.now(); const total = jobs.length;
    async function worker() {
      const page = await newPage(lang);
      for (;;) {
        const job = jobs.shift(); if (!job) break;
        const href = await page.evaluate(([id, o]) => APV.href(id, o), [job.j.id, {device:job.d, design:job.design}]);
        await page.evaluate((h) => APV.open(h + '&z=100'), href);
        const shot = path.join(OUT, lang, job.name + '.jpg');
        const kp = KEYPICKS.find((k) => k.lang === lang && k.dev === job.d && k.label === job.j.label && job.design === 'A');
        const r = await check(page, {shot, key:kp && FULL ? path.join(KEY, kp.file) : null});
        mergeEx(exceptions, r.exceptions);
        results.push({name:job.name, lang, device:job.d, id:job.j.id, group:job.j.g, label:job.j.label, design:job.design, link:href, shot, issues:r.issues});
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
    /* 1 · the whole checkout by card */
    await open('/home');
    const b0 = (await st()).bag;
    await click('.pc .add');
    expect((await st()).bag === b0 + 1, 'Add to bag on a home card did not raise the bag count');
    await click('[data-tab="bag"]');
    expect((await st()).path === '/bag', 'the Bag tab did not open the bag');
    await type('#f-coupon-code', 'SUMMER');
    await click('[data-act="coupon"]');
    expect(await page.locator('#stage .fl.err [id="f-coupon-code"]').count() === 1, 'a wrong coupon did not show the error state');
    await step('coupon-error');
    await type('#f-coupon-code', 'welcome10', {fill:true});
    await page.dispatchEvent('#stage #f-coupon-code', 'input');
    await click('[data-act="coupon"]');
    expect(await page.locator('#stage .cpn-on').count() === 1, 'WELCOME10 was not applied');
    await step('bag');
    await click('.foot [data-go="/checkout/bag"]');
    await step('1-bag-review');
    await click('[data-act="co-next"]');
    expect((await st()).path === '/checkout/contact', 'Continue did not reach Contact');
    await step('2-contact-signed-in');
    await click('[data-act="co-guest"]');
    await click('[data-act="co-contact"]');
    expect(await page.locator('#stage .fl.err #f-contact-email').count() === 1, 'an empty email did not show its error');
    await step('2-contact-error');
    await type('#f-contact-email', 'sara@example.com');
    await step('2-contact-guest');
    await click('[data-act="co-contact"]');
    expect((await st()).path === '/checkout/address', 'a valid email did not reach the address step');
    await step('3-address-saved');
    await click('[data-go="/checkout/address/new"]');
    await step('3-address-new');
    await click('[data-act="addr-save"]');
    expect(await page.locator('#stage .errsum').count() === 1, 'saving an empty address did not show the error summary');
    await step('3-address-errors');
    await type('#f-addr-name', lang === 'ar' ? 'سارة المنصوري' : 'Sara Al Mansoori');
    await type('#f-addr-phone', '0501234567');
    expect(await vis('#f-addr-phone').inputValue() === '50 123 4567', 'the phone was not formatted as 50 123 4567');
    await click('[data-sheet="emirate"]');
    await step('3-emirate-sheet');
    await click('[data-act="pick"][data-v="dubai"]');
    expect((await st()).sheet === 'area', 'choosing an emirate did not open its areas');
    await type('#aq', lang === 'ar' ? 'مار' : 'Mar');
    await step('3-area-sheet');
    await click('[data-act="pick"][data-v="dubai-marina"]');
    await type('#f-addr-building', lang === 'ar' ? 'مارينا غيت 2' : 'Marina Gate 2');
    await type('#f-addr-street', lang === 'ar' ? 'شارع المرسى' : 'Al Marsa Street');
    await step('3-address-filled');
    await click('[data-act="addr-save"]');
    expect((await st()).path === '/checkout/delivery', 'a complete address did not reach Delivery');
    await step('4-delivery');
    await click('[data-act="del"][data-v="exp"]');
    expect((await st()).co.del === 'exp', 'Express was not chosen');
    await click('[data-act="co-next"]');
    await step('5-payment');
    await click('[data-act="pay"][data-v="card"]');
    await click('[data-act="co-pay"]');
    expect((await st()).path === '/checkout/card', 'Card did not open the card form');
    await step('6-card-empty');
    await click('[data-act="card-next"]');
    expect(await page.locator('#stage .fl.err').count() >= 3, 'an empty card form did not show its errors');
    await step('6-card-errors');
    await type('#f-card-number', '4242424242424242');
    expect(await vis('#f-card-number').inputValue() === '4242 4242 4242 4242', 'the card number was not grouped in fours');
    expect(await page.locator('#stage #cbrand .cbrand').count() === 1, 'the card brand was not detected');
    await type('#f-card-exp', '1229');
    expect(await vis('#f-card-exp').inputValue() === '12 / 29', 'the expiry was not formatted MM / YY');
    await type('#f-card-cvc', '123');
    await type('#f-card-cname', 'SARA AL MANSOORI');
    await step('6-card-filled');
    await click('[data-act="card-next"]');
    expect((await st()).path === '/checkout/3ds', 'a valid card did not reach 3-D Secure');
    await step('6-card-3ds');
    await type('#f-otp-code', '481205');
    await click('[data-act="otp-submit"]');
    expect((await st()).path === '/checkout/review', 'the code did not reach Review');
    await step('7-review');
    const o0 = (await st()).orders;
    await click('[data-act="place"]');
    const s8 = await st();
    expect(s8.path === '/checkout/placed' && s8.bag === 0 && s8.orders === o0 + 1, 'Place order did not place the order and empty the bag');
    await step('8-placed');

    /* 2 · the other methods, each from the payment step with a bag */
    const wallet = dev === 'px9' || dev.startsWith('fold') || dev.startsWith('flip') || dev === 'duo' ? 'gpay' : 'apple';
    for (const m of [wallet, 'tabby', 'tamara', 'cod']) {
      await open('/checkout/payment');
      await click(`[data-act="pay"][data-v="${m}"]`);
      await click('[data-act="co-pay"]');
      await step('6-' + (m === 'apple' || m === 'gpay' ? 'wallet' : m));
      if (m === 'cod') await click('[data-act="cod-ok"]');
      else if (m === 'tabby' || m === 'tamara') await click(`[data-act="bnpl-ok"]`);
      else await click('[data-act="authorise"]');
      const s = await st();
      expect(s.path === '/checkout/review' && (m === 'cod' || s.co.authorised === m), `${m}: did not come back to Review approved`);
    }
    /* 3 · a declined card keeps the bag; retry goes back to the card */
    await open('/checkout/3ds');
    const bagBefore = (await st()).bag;
    await click('[data-act="decline"]');
    expect((await st()).path.startsWith('/checkout/failed'), 'a declined card did not show Payment failed');
    await step('9-failed');
    expect((await st()).bag === bagBefore, 'the bag was not kept after a declined payment');
    await click('[data-act="retry-pay"]');
    expect((await st()).path === '/checkout/card', 'Try again did not return to the card form');

    /* 4 · navigation smoke: product → back → tab switch → state kept */
    await open('/home');
    await click('.pc .open.meta');
    let s = await st();
    expect(s.path.startsWith('/product/'), 'a product card did not open the product');
    const pslug = s.path.split('/')[2];
    await step('nav-product');
    await click('[data-act="back"]');
    expect((await st()).path === '/home', 'Back did not return to Home');
    /* tab switch keeps each tab's stack */
    await open('/brands');
    await click('[data-go^="/brand/"]');
    const before = await st();
    await click('[data-tab="home"]');
    expect((await st()).tab === 'home', 'the Home tab did not open');
    await click('[data-tab="shop"]');
    const after = await st();
    expect(after.tab === 'shop' && after.path === before.path && after.depth === before.depth, `the Shop tab lost its place (${before.path} → ${after.path})`);
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
  const flowShots = {};
  if (!NO_FLOWS) {
    const FD = arg('dev') ? DEVICES : ['ip16', 'px9', 'fold-inner', 'duo'];
    for (const lang of LANGS) for (const d of FD) {
      const r = await flows(lang, d, flowLog);
      flowFails[`${lang}/${d}`] = r.fails;
      flowShots[`${lang}/${d}`] = r.shots;
      process.stdout.write(`flow ${lang}/${d}: ${r.fails.length} failures\n`);
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
    const ORDER = [['1-bag-review', '1 · Bag review'], ['2-contact-guest', '2 · Contact'], ['3-address-saved', '3 · Address: saved'], ['3-address-errors', '3 · New address: validation'], ['3-emirate-sheet', '3 · Emirate'], ['3-area-sheet', '3 · Area by emirate'], ['3-address-filled', '3 · New address: filled'], ['4-delivery', '4 · Delivery'], ['5-payment', '5 · Payment'], ['6-card-filled', '6 · Card'], ['6-card-3ds', '6 · 3-D Secure'], ['6-wallet', '6 · Wallet sheet'], ['6-tabby', '6 · Tabby'], ['6-tamara', '6 · Tamara'], ['6-cod', '6 · Cash on delivery'], ['7-review', '7 · Review'], ['8-placed', '8 · Order placed'], ['9-failed', '9 · Payment failed']];
    for (const lang of LANGS) {
      const S = flowShots[`${lang}/ip16`]; if (!S) continue;
      await compose(path.join(KEY, `${lang}-checkout-steps.png`), `${lang === 'ar' ? 'Arabic' : 'English'} app · checkout, every step, clicked and typed by tools/apv-shots.cjs (iPhone 16 Pro)`, ORDER.filter(([k]) => S[k]).map(([k, c]) => ({src:S[k], cap:c})), {w:200, width:2000});
    }
  }
  if (!NOSHOTS && FULL) {
    const fl = (lang, k) => (flowShots[`${lang}/ip16`] || {})[k];
    await compose(path.join(KEY, 'floating-label-before.png'), 'Floating labels, before: the label is the placeholder, centred in the box (English, Arabic)', [{src:fl('en', '3-address-new'), cap:'English · empty'}, {src:fl('ar', '3-address-new'), cap:'Arabic · empty'}].filter((x) => x.src), {w:420, width:900});
    await compose(path.join(KEY, 'floating-label-after.png'), 'Floating labels, after: typed values; each label lifts to a small heading at the top-start corner', [{src:fl('en', '3-address-filled'), cap:'English · filled'}, {src:fl('ar', '3-address-filled'), cap:'Arabic · filled'}].filter((x) => x.src), {w:420, width:900});
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
    arabic_exceptions:exceptions,
    failures:bad.map((r) => ({name:`${r.lang}/${r.name}`, link:r.link, issues:r.issues.slice(0, 12)})).concat(flowBad.map((r) => ({name:`flow ${r.flow} ${r.step}`, issues:r.issues.slice(0, 12)}))),
  };
  if (FULL) fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify({renders:report.renders, screens:report.screens, failed:report.failed, flow_steps:flowLog.length, flow_step_issues:flowBad.length, flow_assertions_failed:nFlowFails, issues_by_kind:byKind, secs:Math.round((Date.now() - t0) / 1000)}));
  for (const r of report.failures.slice(0, Number((arg('show') || ['40'])[0]))) console.log(r.name, JSON.stringify(r.issues.slice(0, 5)));
  for (const [k, v] of Object.entries(flowFails)) if (v.length) console.log('FLOW', k, JSON.stringify(v));
  process.exit(bad.length || flowBad.length || nFlowFails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
