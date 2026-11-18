// KBB Studio · UI AUDIT — measured, not eyeballed (the owner, 17 Nov: "no any issues in alignments or styling issues,
// also check overall everything"). Headless Chrome against the Linux engine opens every screen and tab for BOTH
// companies (KBB, Planet Careers) at 1280×800, 1440×900 and 1920×1080, and fails on:
//   clip      text clipped in its box (scrollWidth > clientWidth on buttons, labels, chips, tab labels, headings) unless
//             it ellipsizes on purpose AND carries a title tooltip
//   overlap   two controls whose visible boxes intersect
//   hscroll   horizontal scroll on the page or on a screen's own scrolling column
//   outside   a control past the viewport / cut by a box that cannot scroll it into view
//   console   console errors and page errors
//   height    buttons/fields side by side in one row with different heights
//   contrast  text below 4.5:1 against its real background (opacity and alpha composited)
// Not judged for contrast: artwork that shows a style or a brand colour as itself ([data-sample] text-style buttons,
// palettes, swatches, company badges) and media (preview, timeline clips, canvases).
// Arabic: a brief in Arabic on the home card and the Designs screen, and an Arabic reel name, are laid out and audited.
//
//   node tools/ui-audit.cjs [--label before|after] [--shots before|after] [--only kbb|planet-careers] [--views 1280x800,…]
// Needs engine/kbbstudio-linux-amd64 (cd engine && GOOS=linux GOARCH=amd64 go build -o kbbstudio-linux-amd64 .) + ffmpeg.
// Report: tools/out/ui/<label>.json (every finding) and shots/ui/audit-<label>.txt (counts + the first findings).
'use strict';
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d; };
const LABEL = arg('label', 'run'), SHOT = arg('shots', ''), ONLY = arg('only', '');
const OUT = path.join(H.ROOT, 'tools/out/ui'), SHOTS = path.join(H.ROOT, 'shots/ui');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const VIEWS = (arg('views', '') ? arg('views').split(',').map((v) => v.split('x').map(Number)) : [[1280, 800], [1440, 900], [1920, 1080]]);
const WORKSPACES = ['kbb', 'planet-careers'].filter((w) => !ONLY || w === ONLY);
const BENIGN = /No available adapters|AudioContext was not allowed|net::ERR_ABORTED/;

// ───────────────────────── the in-page measurement (pure DOM reads; the app itself never does this) ─────────────────────────
function measure() {
  const vw = innerWidth, vh = innerHeight, out = [];
  const CTRL = 'button, .btn, input:not([type=hidden]):not([type=file]), select, textarea, [role=tab], a[href], .chip';
  const TEXTY = 'button, .btn, label, .chip, [role=tab], summary, .lbl, h1, h2, h3, h4, .mtag, .r-aspect, .doit-lbl, .ws-name, b, small, span';
  const cs = (e) => getComputedStyle(e);
  // what is on top: a full-window layer (a dialog, ⚙ Configure, the design editor) hides everything under it, so only
  // that layer is measured; the company menu is not full-window, so it is measured together with the page
  const layers = [...document.querySelectorAll('.modal-back, .cfg-back, .de')].filter((x) => cs(x).display !== 'none');
  const ROOT = layers.sort((a, b) => (+cs(b).zIndex || 0) - (+cs(a).zIndex || 0))[0] || document.body;
  const all = (sel) => [...ROOT.querySelectorAll(sel), ...(ROOT === document.body ? [] : [])];
  const desc = (e) => {
    let s = e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + [...e.classList].slice(0, 3).map((c) => '.' + c).join('');
    const t = (e.getAttribute('aria-label') || e.innerText || e.value || '').trim().replace(/\s+/g, ' ').slice(0, 40);
    let p = e.parentElement, trail = [];
    for (let i = 0; p && i < 3; i++, p = p.parentElement) trail.unshift(p.tagName.toLowerCase() + (p.id ? '#' + p.id : '') + (p.classList[0] ? '.' + p.classList[0] : ''));
    return `${trail.join(' > ')} > ${s}${t ? ` "${t}"` : ''}`;
  };
  // checkVisibility() also sees what a closed <details> hides (Chrome lays that content out under content-visibility)
  const hiddenUp = (e) => { if (e.checkVisibility && !e.checkVisibility({ opacityProperty: true, visibilityProperty: true, contentVisibilityAuto: true })) return true;
    for (let p = e.parentElement; p; p = p.parentElement) if (p.tagName === 'DETAILS' && !p.open) { const sum = [...p.children].find((c) => c.tagName === 'SUMMARY'); if (!sum || !sum.contains(e)) return true; }
    for (let p = e; p && p !== document.documentElement; p = p.parentElement) { const s = cs(p); if (s.display === 'none' || s.visibility === 'hidden' || +s.opacity === 0) return true; } return false; };
  // the part of e you can actually see: its box cut by every ancestor that clips (overflow != visible) and the viewport
  const visRect = (e) => {
    const r = e.getBoundingClientRect();
    let L = r.left, T = r.top, R = r.right, B = r.bottom;
    for (let p = e.parentElement; p && p !== document.body; p = p.parentElement) {
      const s = cs(p);
      if (s.position === 'fixed') { /* still clips its own content */ }
      if (s.overflowX !== 'visible' || s.overflowY !== 'visible') {
        const q = p.getBoundingClientRect();
        if (s.overflowX !== 'visible') { L = Math.max(L, q.left); R = Math.min(R, q.right); }
        if (s.overflowY !== 'visible') { T = Math.max(T, q.top); B = Math.min(B, q.bottom); }
      }
    }
    L = Math.max(L, 0); T = Math.max(T, 0); R = Math.min(R, vw); B = Math.min(B, vh);
    return { r, v: { L, T, R, B, w: Math.max(0, R - L), h: Math.max(0, B - T) } };
  };
  const scroller = (e, axis) => {
    for (let p = e.parentElement; p; p = p.parentElement) {
      const s = cs(p), o = axis === 'x' ? s.overflowX : s.overflowY;
      if (o === 'auto' || o === 'scroll') return p;
      if (o === 'hidden' || o === 'clip') {
        // a clipping box only matters if e sticks out of it on this axis; otherwise keep looking for the scroller
        const a = e.getBoundingClientRect(), q = p.getBoundingClientRect();
        const inside = axis === 'x' ? a.left >= q.left - 1 && a.right <= q.right + 1 : a.top >= q.top - 1 && a.bottom <= q.bottom + 1;
        if (!inside) return null;
      }
    }
    return null;
  };
  const SKIP = '.pv-wrap, .tl-lane, .tl-item, .de-stage, .dz-card canvas, .thumbs, .hdl, .snap, canvas, .toasts, .rs-cap-ov-preview';
  const skip = (e) => !!e.closest(SKIP);

  // overflow (page + named screen scrollers)
  const de = document.documentElement;
  if (de.scrollWidth > vw + 1 || document.body.scrollWidth > vw + 1) out.push({ k: 'hscroll', d: `page scrollWidth ${Math.max(de.scrollWidth, document.body.scrollWidth)} > ${vw}` });
  for (const sel of ['.home', '.home-main', '.cfg-main', '.cfg-nav', '.dz-col', '.setup-screen', '.setup-card', '.bin-body', '.side-body', '.modal-body', '.de-insp', '.de-layers', '.doit-row', '.topbar', '.insp-body', '.mod-body'])
    for (const el of all(sel)) if (!hiddenUp(el) && el.scrollWidth > el.clientWidth + 1) out.push({ k: 'hscroll', d: `${desc(el)} scrollWidth ${el.scrollWidth} > ${el.clientWidth}` });

  // controls: visible ones, with what can be seen of them
  const ctrls = [];
  for (const e of all(CTRL)) {
    if (skip(e) || hiddenUp(e)) continue;
    const { r, v } = visRect(e);
    if (r.width < 2 || r.height < 2) continue;
    if (v.w <= 0 || v.h <= 0) {
      // nothing of it shows: fine if a scroller can bring it in, a defect if a box that cannot scroll swallows it
      const sx = r.right > vw || r.left < 0 ? scroller(e, 'x') : true, sy = r.bottom > vh || r.top < 0 ? scroller(e, 'y') : true;
      if ((r.left < 0 || r.right > vw) && !sx) out.push({ k: 'outside', d: `${desc(e)} at x ${Math.round(r.left)}–${Math.round(r.right)} (viewport ${vw})` });
      else if ((r.top < 0 || r.bottom > vh) && !sy) out.push({ k: 'outside', d: `${desc(e)} at y ${Math.round(r.top)}–${Math.round(r.bottom)} (viewport ${vh})` });
      continue;
    }
    ctrls.push({ e, r, v });
    // partly cut: by the viewport with no scroller, or by a box that clips and cannot scroll
    const cutX = v.w < r.width - 2, cutY = v.h < r.height - 2;
    if ((cutX && !scroller(e, 'x')) || (cutY && !scroller(e, 'y'))) out.push({ k: 'outside', d: `${desc(e)} shows ${Math.round(v.w)}×${Math.round(v.h)} of ${Math.round(r.width)}×${Math.round(r.height)}` });
  }

  // overlap: two controls (neither inside the other) whose visible boxes intersect
  for (let i = 0; i < ctrls.length; i++) for (let j = i + 1; j < ctrls.length; j++) {
    const a = ctrls[i], b = ctrls[j];
    if (a.e.contains(b.e) || b.e.contains(a.e)) continue;
    const pa = cs(a.e).position, pb = cs(b.e).position;
    if (a.e.closest('.ws-menu, .modal-back, .cfg-back, .de') !== b.e.closest('.ws-menu, .modal-back, .cfg-back, .de')) continue; // a layer above another
    const w = Math.min(a.v.R, b.v.R) - Math.max(a.v.L, b.v.L), hh = Math.min(a.v.B, b.v.B) - Math.max(a.v.T, b.v.T);
    if (w > 1 && hh > 1 && w * hh > 6 && !(pa === 'absolute' && a.e.classList.contains('add')) && !(pb === 'absolute' && b.e.classList.contains('add')))
      out.push({ k: 'overlap', d: `${desc(a.e)}  ×  ${desc(b.e)} (${Math.round(w)}×${Math.round(hh)})` });
  }

  // heights within one row: buttons and single-line fields that share a parent and a line
  const FIELD = (e) => e.matches('button, .btn, select, input[type=text], input[type=search], input[type=number], input[type=password], input[type=url], input:not([type])');
  const byParent = new Map();
  for (const c of ctrls) if (FIELD(c.e) && !c.e.closest('.seg, .bin-tabs, .side-tabs, .dz-tabs, .rs-cap-tabs, .cfg-nav, .emoji-grid, .sticker-grid, .t-btns, .de-layers, .ws-menu, .chips, .dz-ex')) {
    const p = c.e.parentElement; if (!byParent.has(p)) byParent.set(p, []); byParent.get(p).push(c);
  }
  for (const [, list] of byParent) {
    if (list.length < 2) continue;
    const used = new Set();
    for (const a of list) {
      if (used.has(a)) continue;
      const row = list.filter((b) => !used.has(b) && Math.abs((b.r.top + b.r.bottom) / 2 - (a.r.top + a.r.bottom) / 2) < 4);
      row.forEach((b) => used.add(b));
      if (row.length < 2) continue;
      // a button meant to be small (.small/.icon-btn/.chip) is compared only with its own kind
      const kinds = new Map();
      for (const b of row) { const k = b.e.matches('.small, .chip, .icon-btn, .tb, .tool') ? 'small' : 'main'; if (!kinds.has(k)) kinds.set(k, []); kinds.get(k).push(b); }
      for (const [, ks] of kinds) {
        if (ks.length < 2) continue;
        const hs = ks.map((b) => Math.round(b.r.height));
        if (Math.max(...hs) - Math.min(...hs) > 2) out.push({ k: 'height', d: ks.map((b) => `${desc(b.e)}=${Math.round(b.r.height)}`).join('  |  ') });
      }
    }
  }

  // clipped text
  for (const e of all(TEXTY)) {
    if (skip(e) || hiddenUp(e)) continue;
    if (e.matches('b, small, span') && !e.closest('button, .btn, .chip, [role=tab], label, .ws-switch, .doit-btn, .recent, .mtag')) continue;
    if (!e.textContent.trim() || e.tagName === 'SELECT') continue;
    const s = cs(e);
    if (s.display === 'inline') continue;
    const over = e.scrollWidth > e.clientWidth + 1 && e.clientWidth > 0;
    if (!over) continue;
    const ell = s.textOverflow === 'ellipsis' && s.overflowX !== 'visible';
    const titled = !!(e.title || e.closest('[title]'));
    if (ell && titled) continue;
    out.push({ k: 'clip', d: `${desc(e)} text ${e.scrollWidth} in ${e.clientWidth}${ell ? ' (ellipsis, no title)' : ''}` });
  }

  // contrast: leaf text against the background it really sits on
  const parse = (c) => { const m = /rgba?\(([^)]+)\)/.exec(c); if (!m) return null; const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1]; };
  const over = (f, b) => [f[0] * f[3] + b[0] * (1 - f[3]), f[1] * f[3] + b[1] * (1 - f[3]), f[2] * f[3] + b[2] * (1 - f[3]), 1];
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  // the stack of backgrounds under e, bottom first; a gradient counts as every colour it names (the worst one is judged)
  const bgStack = (e) => {
    const layers = [];
    for (let p = e; p; p = p.parentElement) {
      const s = cs(p);
      if (s.backgroundImage && s.backgroundImage !== 'none') {
        if (/url\(/.test(s.backgroundImage)) return null;
        const cols = (s.backgroundImage.match(/rgba?\([^)]+\)/g) || []).map(parse);
        if (cols.length) { layers.push({ multi: cols }); if (cols.every((c) => c[3] >= 1)) break; }
      }
      const c = parse(s.backgroundColor);
      if (c && c[3] > 0) { layers.push({ c }); if (c[3] >= 1) break; }
    }
    return layers.reverse();
  };
  const seen = new Set();
  for (const e of all('*')) {
    if (skip(e) || e.closest('svg, select, option, .palette, .swatches, .dz-sw, .ws-badge, .logo-dot, [data-sample]')) continue;
    const direct = [...e.childNodes].some((n) => n.nodeType === 3 && /[\p{L}\p{N}]/u.test(n.textContent));
    const isField = e.matches('input[type=text], input:not([type]), input[type=number], input[type=search], textarea') && e.value && /[\p{L}\p{N}]/u.test(e.value);
    if (!direct && !isField) continue;
    if (e.closest('[disabled], .soon, [aria-disabled=true]') || e.matches(':disabled')) continue;
    if (hiddenUp(e)) continue;
    // the hover-help card (#kbh-card) fades in over ~120 ms; one caught mid-fade is not a contrast fault of the card
    if (e.closest('#kbh-card') && +cs(e.closest('#kbh-card')).opacity < 1) continue;
    const { v } = visRect(e); if (v.w <= 0 || v.h <= 0) continue;
    const s = cs(e);
    let fg = parse(s.color); if (!fg) continue;
    let op = 1; for (let p = e; p; p = p.parentElement) op *= +cs(p).opacity;
    const layers = bgStack(e); if (!layers) continue;
    // every combination of gradient stops (worst case)
    let bases = [[15, 16, 19, 1]];
    for (const L of layers) {
      if (L.c) bases = bases.map((b) => over(L.c, b));
      else bases = bases.flatMap((b) => L.multi.map((m) => over(m, b)));
    }
    let worst = 99;
    for (const b of bases) { const f = over([fg[0], fg[1], fg[2], fg[3] * op], b); worst = Math.min(worst, ratio(f, b)); }
    if (worst < 4.5) {
      const key = desc(e); if (seen.has(key)) continue; seen.add(key);
      out.push({ k: 'contrast', d: `${key} ${worst.toFixed(2)}:1 (${s.color} @${op.toFixed(2)})` });
    }
  }
  return out;
}

// ───────────────────────── driving the app ─────────────────────────
(async () => {
  const clip = path.join(OUT, 'ui-clip.mp4');
  if (!fs.existsSync(clip)) execFileSync('ffmpeg', ['-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=s=720x1280:r=30:d=8', '-f', 'lavfi', '-i', 'sine=f=330:d=8',
    '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'veryfast', '-c:a', 'aac', clip]);
  const STUDIO = H.makeStudio();
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const report = { label: LABEL, when: new Date().toISOString(), screens: 0, findings: [] };
  const errors = [];

  for (const wsId of WORKSPACES) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: SHOT ? 2 : 1 });
    await context.addInitScript((id) => { try { localStorage.setItem('kbbstudio.workspace', id); } catch {} }, wsId);
    const page = await context.newPage();
    page.setDefaultTimeout(45000);
    page.on('console', (m) => { if (m.type() === 'error' && !BENIGN.test(m.text())) errors.push({ ws: wsId, t: m.text() }); });
    page.on('pageerror', (err) => errors.push({ ws: wsId, t: 'pageerror: ' + err.message }));
    let where = 'boot';
    const pageErrorsAt = () => errors.filter((x) => x.ws === wsId).length;
    const audit = async (screen, shotName) => {
      const before = pageErrorsAt();
      for (const [w, hgt] of VIEWS) {
        await page.setViewportSize({ width: w, height: hgt }); await page.waitForTimeout(220);
        const f = await page.evaluate(measure);
        for (const x of f) report.findings.push({ ws: wsId, screen, view: `${w}x${hgt}`, ...x });
        report.screens++;
      }
      await page.setViewportSize({ width: 1440, height: 900 }); await page.waitForTimeout(200);
      if (SHOT && shotName) await page.screenshot({ path: path.join(SHOTS, `${SHOT}-${shotName}.png`) });
      for (const x of errors.slice(before).filter((x) => x.ws === wsId)) x.screen = screen;
      where = screen;
    };
    const tryStep = async (name, fn) => { try { await fn(); } catch (err) { report.findings.push({ ws: wsId, screen: name, view: '-', k: 'console', d: 'audit could not reach this screen: ' + String(err.message).split('\n')[0] }); } };
    const closeModal = async () => { for (let i = 0; i < 3 && await page.$('.modal-back'); i++) { await page.keyboard.press('Escape'); await page.waitForTimeout(150); } };
    const short = wsId === 'kbb' ? 'kbb' : 'pc';

    await page.goto(e.url + '/app/');
    // 1 · the first-run Setup screen
    await page.waitForSelector('#su-later, .home', { timeout: 30000 });
    if (await page.$('#su-later')) { await audit('setup-screen', wsId === 'kbb' ? 'setup' : null); await page.click('#su-later'); }
    await page.waitForSelector('.home');
    await page.waitForTimeout(500);
    // 2 · home
    await audit('home', 'home-' + short);
    await tryStep('home-arabic', async () => {
      const ar = wsId === 'kbb' ? 'عرض اليوم الوطني: خصم ٣٠٪ على منتجات أنوا وبيوتي أوف جوسون، ستوري وبوست، طابع فاخر للسيدات' : 'الدراسة في ألمانيا - التسجيل مفتوح لدفعة سبتمبر للطلاب، احجز استشارة مجانية';
      if (await page.$('#home-brief')) await page.fill('#home-brief', ar);
      await audit('home-arabic');
      if (await page.$('#home-brief')) await page.fill('#home-brief', '');
    });
    // 3 · company menu, company editor, models dialog
    await tryStep('company-menu', async () => { await page.click('.home .ws-switch'); await page.waitForSelector('.ws-menu'); await audit('company-menu'); await page.keyboard.press('Escape'); await page.mouse.click(700, 600); await page.evaluate(() => document.querySelector('.ws-menu')?.remove()); });
    await tryStep('company-editor', async () => {
      await page.evaluate(() => [...document.querySelectorAll('.home button')].find((b) => /Edit company/.test(b.textContent))?.click());
      await page.waitForSelector('.modal .company-form'); await audit('company-editor', wsId === 'kbb' ? 'company' : null); await closeModal();
    });
    await tryStep('models-dialog', async () => {
      await page.evaluate(() => [...document.querySelectorAll('.home button')].find((b) => /Models/.test(b.textContent))?.click());
      await page.waitForSelector('.modal .setup-form'); await audit('models-dialog'); await closeModal();
    });
    // 4 · Configure, every section
    await tryStep('configure', async () => {
      await page.click('#configure-btn'); await page.waitForSelector('.cfg-back [data-sec]');
      const secs = await page.$$eval('.cfg-nav [data-sec]', (b) => b.map((x) => x.dataset.sec));
      for (const s of secs) {
        await page.click(`.cfg-nav [data-sec="${s}"]`); await page.waitForTimeout(400); await audit('configure:' + s, wsId === 'kbb' && s === 'companies' ? 'configure' : null);
        // the "How to get this key →" steps (from GUIDE - keys and what to send.html), opened
        if (await page.$('.cfg-how')) { await page.$$eval('.cfg-how', (d) => d.forEach((x) => { x.open = true; })); await page.waitForTimeout(150); await audit('configure:' + s + ':how-to', wsId === 'kbb' && s === 'photos' ? 'configure-howto' : null); }
      }
      await page.keyboard.press('Escape'); await page.waitForSelector('.cfg-back', { state: 'detached' });
    });
    // 5 · Designs (a brief through the offline reader), every left tab, then the design editor + its export
    await tryStep('designs', async () => {
      const brief = wsId === 'kbb' ? 'National Day: 30% off Anua and BoJ, story and post, luxury' : 'Study in Germany, September intake open — story and post';
      await page.fill('#home-brief', brief); await page.click('#home-designs');
      await page.waitForSelector('.dz-card', { timeout: 60000 }); await page.waitForTimeout(600);
      await audit('designs', wsId === 'kbb' ? 'designs' : 'designs-pc');
      const tabs = await page.$$eval('.dz-left .dz-tabs [data-t], .dz-col .dz-tabs [data-t]', (b) => [...new Set(b.map((x) => x.dataset.t))]);
      for (const t of tabs) { await page.click(`.dz-tabs [data-t="${t}"]`); await page.waitForTimeout(700); await audit('designs:' + t); }
      await page.click('.dz-card'); await page.waitForSelector('#de-canvas'); await page.waitForTimeout(500);
      await audit('design-editor', wsId === 'kbb' ? 'design-editor' : null);
      await page.click('[data-el="headline"]').catch(() => {}); await page.waitForTimeout(300);
      await audit('design-editor:headline');
      await page.click('#de-export'); await page.waitForSelector('#de-x-go'); await audit('design-export'); await closeModal();
      await page.click('#de-back'); await page.waitForTimeout(400);
    });
    await tryStep('designs-arabic', async () => {
      const ar = wsId === 'kbb' ? 'عرض اليوم الوطني: خصم ٣٠٪ على منتجات أنوا وبيوتي أوف جوسون، ستوري وبوست' : 'مطلوب لحامين للعمل في بولندا، قدّم عبر واتساب';
      const box = await page.$('.dz-brief');
      if (box) { await page.fill('.dz-brief', ar); const go = await page.$('.dz-go'); if (go) { await go.click(); await page.waitForTimeout(2500); } }
      await audit('designs-arabic', wsId === 'planet-careers' ? 'designs-arabic-pc' : null);
    });
    // 6 · the editor with a clip, captions, every left and right tab, export, shortcuts
    await page.goto(e.url + '/app/'); await page.waitForSelector('.home');
    await page.setInputFiles('.home-drop input[type=file]', clip);
    await page.waitForSelector('.tl-item');
    await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    await page.evaluate(async (ar) => {
      const C = await import('/app/captions/commands.js'); const P = await import('/app/core/project.js'); const ed = kbbEditor, p = ed.project;
      const tr = p.tracks.find((t) => t.kind === 'caption');
      const text = ar ? 'مرحبا بكم في الدراسة في ألمانيا التسجيل مفتوح الآن احجز استشارة مجانية اليوم' : 'Welcome to your glow routine this serum is the secret for soft and bright skin every single day';
      const words = text.split(' ').map((w, i) => ({ w, t0: 0.3 + i * 0.45, t1: 0.3 + i * 0.45 + 0.4, conf: 1 }));
      ed.history.apply(ed.ops.batch('Captions', [C.setCaptions({ style: 'word-box', lang: ar ? 'ar' : 'en', words, soundTags: 'hide' }, p.canvas),
        ed.ops.addItems([{ trackId: tr.id, item: P.createItem('caption', { t0: 0, in: 0, out: P.projectDuration(p), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'Captions' }) }])]));
      ed.seek(1.2);
    }, wsId === 'planet-careers');
    await page.waitForTimeout(600);
    await audit('editor', 'editor-' + short);
    if (wsId === 'planet-careers') await tryStep('editor-arabic-name', async () => {
      await page.fill('.topbar .proj-name', 'ريل الدراسة في ألمانيا — دفعة سبتمبر'); await page.keyboard.press('Enter'); await audit('editor-arabic-name');
    });
    for (const t of await page.$$eval('.bin-tab', (b) => b.map((x, i) => i))) await tryStep('bin:' + t, async () => { await page.click(`.bin-tabs .bin-tab:nth-child(${t + 1})`); await page.waitForTimeout(450); await audit('bin:' + (await page.$eval('.bin-tab.on', (x) => x.textContent.trim()))); });
    await page.click('.bin-tabs .bin-tab:nth-child(1)');
    await page.click('.tl-item.t-clip').catch(() => {}); await page.waitForTimeout(200);
    const sides = await page.$$eval('.side-tab', (b) => b.map((x) => x.dataset.side));
    for (const s of sides) await tryStep('side:' + s, async () => {
      await page.click(`.side-tab[data-side="${s}"]`); await page.waitForTimeout(s.startsWith('mod:') ? 900 : 500);
      await audit('side:' + s, s === 'captions' ? 'editor-captions-' + short : null);
      if (s === 'captions') for (const t of await page.$$eval('.rs-cap-tabs button[data-tab]', (b) => b.map((x) => x.dataset.tab))) {
        await page.click(`.rs-cap-tabs button[data-tab="${t}"]`); await page.waitForTimeout(500); await audit('captions:' + t);
      }
    });
    // the capsules carousel's inspector (themes/capsules-panel.js) and its two pickers; the carousel is taken out again
    await tryStep('inspector-capsules', async () => {
      await page.evaluate(async () => { const TH = await import('/app/themes/index.js'); const it = TH.placeElement(kbbEditor.context(), 'capsules', { t0: 0 }); kbbEditor.select([it.id]); kbbEditor.showSide('inspector'); window.__capsUndo = kbbEditor.history.undoStack.length; });
      await page.waitForSelector('[data-caps-insp] .cps-preset'); await page.waitForTimeout(600);
      await audit('inspector-capsules');
      await page.click(`.cps-item .cps-grip`); await page.waitForSelector('[data-caps-sel-box]'); await page.waitForTimeout(200);
      await audit('inspector-capsules-selected');
      await page.click('[data-caps-add-countries]'); await page.waitForSelector('.cps-countries label[data-caps-country]'); await page.waitForTimeout(800); await audit('capsules-country-picker'); await closeModal();
      await page.click('[data-caps-add-jobs]'); await page.waitForSelector('.cps-jobs label'); await page.waitForTimeout(400); await audit('capsules-jobs-picker'); await closeModal();
      await page.click('[data-caps-add-custom="jobs"]'); await page.waitForSelector('.cps-icons button'); await audit('capsules-custom'); await closeModal();
      await page.evaluate(() => { kbbEditor.history.undo(); });
    });
    await page.click('.side-tab[data-side="captions"]');
    await page.click('.rs-cap-tabs button[data-tab="transcript"]').catch(() => {});
    await tryStep('export', async () => { await page.click('.export-btn'); await page.waitForSelector('#x-go'); await page.waitForTimeout(400); await audit('export', wsId === 'kbb' ? 'export' : null); await closeModal(); });
    await tryStep('shortcuts', async () => { await page.click('.topbar button[title^="Keyboard"]'); await page.waitForSelector('.modal'); await audit('shortcuts'); await closeModal(); });
    await tryStep('editor-configure', async () => { await page.click('#configure-btn'); await page.waitForSelector('.cfg-back'); await audit('editor-configure'); await page.keyboard.press('Escape'); });
    // 7 · home again, now with reels in Recent (the empty state was measured first)
    await tryStep('home-recent', async () => { await page.goto(e.url + '/app/'); await page.waitForSelector('.recent'); await page.waitForTimeout(300); await audit('home-recent'); });
    // 8 · the stop screens main.js draws before anything else: an engine that does not answer, and an older engine
    //     (api < 3, the integrator's handshake) — provoked by answering /api/info for the page
    for (const [screen, fake] of [['stopped-no-engine', null], ['stopped-older-engine', 2]]) await tryStep(screen, async () => {
      await page.route('**/api/info*', async (route) => {
        if (fake == null) return route.fulfill({ status: 200, contentType: 'text/plain', body: 'not the engine' });
        const res = await route.fetch(); const j = await res.json(); j.api = fake; return route.fulfill({ response: res, json: j });
      });
      await page.goto(e.url + '/app/'); await page.waitForSelector('.stopped h1'); await page.waitForTimeout(200);
      await audit(screen, wsId === 'kbb' && fake ? 'stopped-older-engine' : null);
      await page.unroute('**/api/info*');
    });
    void where;
    await context.close();
  }
  for (const x of errors) report.findings.push({ ws: x.ws, screen: x.screen || '?', view: '-', k: 'console', d: x.t });
  await browser.close(); await H.stopEngine(e);
  fs.rmSync(STUDIO, { recursive: true, force: true });

  // dedupe: the same finding at three sizes is one line with its sizes
  const map = new Map();
  for (const f of report.findings) { const k = `${f.k}|${f.ws}|${f.screen}|${f.d}`; if (!map.has(k)) map.set(k, { ...f, views: [] }); map.get(k).views.push(f.view); }
  const uniq = [...map.values()];
  const counts = {}; for (const f of report.findings) counts[f.k] = (counts[f.k] || 0) + 1;
  report.counts = counts; report.total = report.findings.length; report.unique = uniq.length;
  fs.writeFileSync(path.join(OUT, LABEL + '.json'), JSON.stringify({ ...report, findings: uniq }, null, 1));
  const lines = [`KBB Studio UI audit · ${LABEL} · ${report.when}`, `${report.screens} screen×size states, ${WORKSPACES.join(' + ')}, ${VIEWS.map((v) => v.join('×')).join(' / ')}`,
    `findings: ${report.total} (${uniq.length} unique) — ` + (Object.entries(counts).map(([k, n]) => `${k} ${n}`).join(', ') || 'none'), ''];
  for (const k of ['clip', 'overlap', 'hscroll', 'outside', 'height', 'contrast', 'console']) {
    const fs_ = uniq.filter((f) => f.k === k); if (!fs_.length) continue;
    lines.push(`── ${k} (${fs_.length} unique)`);
    for (const f of fs_.slice(0, 60)) lines.push(`  [${f.ws} · ${f.screen} · ${f.views.join(',')}] ${f.d}`);
    if (fs_.length > 60) lines.push(`  … ${fs_.length - 60} more in tools/out/ui/${LABEL}.json`);
  }
  fs.writeFileSync(path.join(SHOTS, `audit-${LABEL}.txt`), lines.join('\n') + '\n');
  console.log(lines.slice(0, 3).join('\n'));
  console.log(report.total ? 'AUDIT FAILED' : 'AUDIT CLEAN');
  process.exit(report.total ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
