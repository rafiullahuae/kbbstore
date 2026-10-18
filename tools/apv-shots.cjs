#!/usr/bin/env node
/*
 * Lane APV — visits every screen × device × locale of docs/app-preview/index.html
 * in Chromium, asserts the owner's bar, and saves a PNG of each.
 *
 *   node tools/apv-shots.cjs                      everything
 *   node tools/apv-shots.cjs --dev=ip16,duo --lang=ar --only=home,product --no-shots
 *
 * Per render it asserts:
 *   overflow   no screen root (.app/.pane/.body/.bar/.foot/.sheet) is wider than its box
 *              (scrollWidth == clientWidth)
 *   clipped    no text line is cut by a box that does not scroll (designed ellipses and
 *              line clamps excepted)
 *   overlap    no two text runs intersect (glyph core band) within the same stacking layer
 *   tap        every interactive element is at least 44 × 44
 *   safe       no fixed text/control in the status-bar / Dynamic Island band or in the
 *              home-indicator band
 *   hinge      nothing crosses the Duo hinge or the Flip tabletop fold
 *   console    no console error, page error or failed request
 *
 * Writes docs/app-preview/shots/<screen>-<device>-<locale>.png, one contact sheet per
 * device and locale (sheet-<device>-<locale>.png) and report.json.
 */
'use strict';
const pw = require('/opt/node22/lib/node_modules/playwright');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PAGE = 'file://' + path.join(ROOT, 'docs/app-preview/index.html');
const OUT = path.join(ROOT, 'docs/app-preview/shots');
const arg = (k) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.split('=')[1].split(',') : null; };
const NOSHOTS = process.argv.includes('--no-shots');
// --mutate=<css> injects a defect to prove the checks can fail (see README).
const MUTATE = (process.argv.find((x) => x.startsWith('--mutate=')) || '').slice(9);
const WORKERS = Number((arg('workers') || ['3'])[0]);

const DEVICES = [
  {key:'ip16', os:'ios', dev:'ip16'},
  {key:'ipse', os:'ios', dev:'ipse'},
  {key:'ipad', os:'ios', dev:'ipad'},
  {key:'ipad-land', os:'ios', dev:'ipad', land:true},
  {key:'px9', os:'android', dev:'px9'},
  {key:'flip', os:'android', dev:'flip'},
  {key:'flip-tabletop', os:'android', dev:'flip', posture:'tabletop'},
  {key:'fold-cover', os:'android', dev:'fold', folded:true},
  {key:'fold-inner', os:'android', dev:'fold', folded:false},
  {key:'duo', os:'android', dev:'duo'},
].filter((d) => !arg('dev') || arg('dev').includes(d.key));
const LANGS = (arg('lang') || ['en', 'ar']);

/* ── the in-page checker ─────────────────────────────────────────────────── */
function audit() {
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
      if (r.el.closest('.zh,.cnt,.badge,.bdg,.pbk,.tt-fold')) continue;
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
      if (e.closest('label.inp,.inp,.card-el') && e.matches('input,textarea')) return; // the field box is the target
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
  // de-duplicate
  const key = new Set();
  return issues.filter((x) => { const k = x.kind + x.at + x.msg; if (key.has(k)) return false; key.add(k); return true; });
}

async function settle(page) {
  await page.evaluate(async () => {
    await document.fonts.ready;
    const t0 = performance.now();
    for (;;) {
      const pending = [...document.querySelectorAll('.device img')].filter((im) => {
        if (im.complete) return false;
        const r = im.getBoundingClientRect(), g = im.closest('.glass').getBoundingClientRect();
        return r.bottom > g.top && r.top < g.bottom && r.right > g.left && r.left < g.right;
      });
      if (!pending.length || performance.now() - t0 > 9000) break;
      await new Promise((r) => setTimeout(r, 60));
    }
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
  });
}

(async () => {
  fs.mkdirSync(OUT, {recursive:true});
  const browser = await pw.chromium.launch({executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome', proxy:process.env.HTTPS_PROXY ? {server:process.env.HTTPS_PROXY} : undefined});
  const ctx = await browser.newContext({viewport:{width:1300, height:1360}, deviceScaleFactor:1, ignoreHTTPSErrors:true, reducedMotion:'reduce'});
  const probe = await ctx.newPage();
  await probe.goto(PAGE + '#z=100');
  let screens = await probe.evaluate(() => window.APV.screens());
  await probe.close();
  if (arg('only')) screens = screens.filter((s) => arg('only').includes(s.id));

  const jobs = [];
  for (const d of DEVICES) for (const lang of LANGS) for (const s of screens) {
    if (s.androidOnly && d.os !== 'android') continue;
    const designs = (s.id === 'home' || s.id === 'product') ? ['A', 'B', 'C'] : ['A'];
    for (const design of designs) jobs.push({d, lang, s, design, name:`${s.id}${design !== 'A' ? '-' + design : ''}-${d.key}-${lang}`});
  }
  const results = [];
  let done = 0;
  const t0 = Date.now();
  async function worker() {
    const page = await ctx.newPage();
    let errs = [];
    page.on('console', (m) => { if (m.type() === 'error') errs.push('console: ' + m.text()); });
    page.on('pageerror', (e) => errs.push('pageerror: ' + e.message));
    page.on('requestfailed', (r) => errs.push('requestfailed: ' + r.url() + ' ' + (r.failure() || {}).errorText));
    page.on('response', (r) => { if (r.status() >= 400) errs.push(`http ${r.status()}: ${r.url()}`); });
    await page.goto(PAGE + '#z=100');
    for (;;) {
      const j = jobs.shift();
      if (!j) break;
      errs = [];
      await page.evaluate((o) => window.APV.set(o), {screen:j.s.id, os:j.d.os, dev:j.d.dev, lang:j.lang, design:j.design, folded:j.d.folded !== false, posture:j.d.posture, land:j.d.land});
      if (MUTATE) await page.addStyleTag({content:MUTATE});
      await settle(page);
      const issues = await page.evaluate(audit);
      for (const e of errs) issues.push({kind:'console', at:'', msg:e});
      if (!NOSHOTS) await page.locator('.device').screenshot({path:path.join(OUT, j.name + '.png'), animations:'disabled'});
      results.push({name:j.name, screen:j.s.id, design:j.design, device:j.d.key, lang:j.lang, issues});
      if (++done % 50 === 0) process.stdout.write(`${done}/${done + jobs.length} ${Math.round((Date.now() - t0) / 1000)}s\n`);
    }
    await page.close();
  }
  await Promise.all(Array.from({length:WORKERS}, worker));

  /* contact sheets: one per device and locale */
  if (!NOSHOTS && !arg('only')) {
    const sheet = await ctx.newPage();
    for (const d of DEVICES) for (const lang of LANGS) {
      const order = screens.map((x) => x.id);
      const rows = results.filter((r) => r.device === d.key && r.lang === lang).sort((a, b) => order.indexOf(a.screen) - order.indexOf(b.screen) || a.design.localeCompare(b.design));
      if (!rows.length) continue;
      const w = d.key === 'duo' || d.key === 'ipad-land' ? 380 : d.key === 'ipad' || d.key === 'fold-inner' ? 260 : 200;
      const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:16px;font:12px system-ui;background:#f3eef0;width:${Math.floor(1800 / (w + 12)) * (w + 12)}px}h1{font:600 18px system-ui;margin:0 0 12px}div{display:flex;flex-wrap:wrap;gap:12px}figure{margin:0;width:${w}px}img{width:100%;display:block;border-radius:8px}figcaption{padding:4px 0;color:#444}.bad{outline:3px solid #c0392b}</style><h1>${d.key} · ${lang} · ${rows.length} screens · ${rows.filter((r) => r.issues.length).length} with issues</h1><div>${rows.map((r) => `<figure><img class="${r.issues.length ? 'bad' : ''}" src="file://${path.join(OUT, r.name + '.png')}"><figcaption>${r.screen}${r.design !== 'A' ? ' · ' + r.design : ''}</figcaption></figure>`).join('')}</div>`;
      const tmp = path.join(OUT, `.sheet-${d.key}-${lang}.html`);
      fs.writeFileSync(tmp, html);
      await sheet.goto('file://' + tmp, {waitUntil:'load'});
      fs.unlinkSync(tmp);
      await sheet.screenshot({path:path.join(OUT, `sheet-${d.key}-${lang}.png`), fullPage:true});
    }
    await sheet.close();
  }
  await browser.close();

  const bad = results.filter((r) => r.issues.length);
  const byKind = {};
  for (const r of results) for (const i of r.issues) byKind[i.kind] = (byKind[i.kind] || 0) + 1;
  const report = {
    generated:new Date().toISOString(), renders:results.length,
    screens:new Set(results.map((r) => r.screen + (r.design !== 'A' ? '-' + r.design : ''))).size,
    devices:DEVICES.map((d) => d.key), locales:LANGS, failed:bad.length, issues_by_kind:byKind,
    failures:bad.map((r) => ({name:r.name, issues:r.issues.slice(0, 12)})),
  };
  if (!arg('only') && !arg('dev') && !arg('lang')) fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify({renders:report.renders, screens:report.screens, devices:report.devices.length, locales:LANGS.length, failed:report.failed, issues_by_kind:byKind, secs:Math.round((Date.now() - t0) / 1000)}));
  for (const r of bad.slice(0, Number((arg('show') || ['40'])[0]))) console.log(r.name, JSON.stringify(r.issues.slice(0, 6)));
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
