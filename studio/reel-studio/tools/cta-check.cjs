#!/usr/bin/env node
// KBB Studio — SLIM CONTACT CARDS check (20 Nov 2026; the owner: "I didn't like the CTA, i want something nice, animated
// continues and with less height stuff. please make almost 10 different CTAS and with full controls of hide-unhide
// anything or change the colors etc"). Headless Chromium against the Linux engine, in the real editor.
//
//   catalogue  ≥ 10 slim designs, listed FIRST; a newly placed Contact card is a slim one; the old 15 are all still there
//   height     every slim card's box and its drawn ink are ≤ 60 % of the old default card's (Phone + WhatsApp), 9:16
//   motion     the card keeps moving for its whole duration: frames 0.5 s apart differ early, in the middle and in the
//              last second of a 12 s card; "Animation off" holds still (two late frames identical)
//   parts      every show / hide toggle changes the pixels on every design, and hiding a part removes its ink
//              (the number hidden: the number is no longer drawn; the background hidden: the bar's middle is see-through)
//   colours    every colour role reaches the pixels on every design; a picked background colour IS the bar's colour
//   controls   speed (slow / fast change the motion), intensity, corners; words are still text slots (inspector field +
//              the label double-clicked on the video)
//   ui         Inspector → Contact card → Show / Colours / Motion: a checkbox, a colour dragged (many input events) and a
//              slider dragged are ONE undo step each, and ⌘Z puts the style back byte for byte
//   old        the 15 old designs draw pixel-identical to the code before this change (HEAD's cta.js, loaded beside it)
//   same       preview frame = export frame with a slim card on
//   speed      draw time per frame for each slim card at 1080×1920 (and the old card for reference)
//   hygiene    no console errors, nothing leaves 127.0.0.1
//   shots      shots/cta2/: a gallery of all ten on a 9:16 frame, one frame per design, a 4-frame motion strip per design,
//              the controls at 1440×900 and 1280×800
//
//   --mutate show   (proof the parts test asserts something) the drawing ignores the show / hide settings -> parts FAIL
//   --mutate anim   the loop clock frozen (an entrance-only card, the old kind) -> motion FAIL
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/cta-check.cjs [--mutate show|anim] [--no-shots]
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'cta2'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'cta2');
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d; };
const MUTATE = arg('mutate', ''), SHOTS_ON = !process.argv.includes('--no-shots') && !MUTATE;
fs.mkdirSync(OUT, { recursive: true }); if (SHOTS_ON) fs.mkdirSync(SHOTS, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
let failures = 0; const results = [];
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
const WORLD_JS = /const WORLD_JS = `([\s\S]*?)`;\n/.exec(fs.readFileSync(path.join(__dirname, 'bg-check.cjs'), 'utf8'))[1];
const FPS = 30;

(async () => {
  const T0 = Date.now();
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings', 'adstudio']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  // the code before this change, beside the new one: the old designs must draw exactly as they did
  const old = execFileSync('git', ['show', (process.env.CTA_BASE || '44fa2555f') + ':studio/reel-studio/app/themes/cta.js'], { cwd: ROOT, maxBuffer: 1 << 24 }).toString();
  fs.writeFileSync(path.join(STUDIO, 'app', 'themes', 'cta-before.js'), old);
  if (MUTATE) {
    const f = path.join(STUDIO, 'app', 'themes', 'cta2.js'); let s = fs.readFileSync(f, 'utf8');
    if (MUTATE === 'show') s = s.replace('const on = (k) => (sh[k] != null ? !!sh[k] :', 'const on = (k) => (false ? !!sh[k] :');
    if (MUTATE === 'anim') s = s.replace('T = L.anim ? S.tl * L.spd : des.still * 4', 'T = des.still * 4');
    fs.writeFileSync(f, s); console.log('MUTATION ' + MUTATE + ' applied to the test copy of cta2.js');
  }
  const LIB = path.join(STUDIO, 'library', 'cta-test'); fs.mkdirSync(LIB, { recursive: true });
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(180000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/No available adapters|AudioContext was not allowed|net::ERR_ABORTED/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, a) => page.evaluate(fn, a);
  const save = (name, dataUrl) => { if (SHOTS_ON) fs.writeFileSync(path.join(SHOTS, name + '.png'), Buffer.from(dataUrl.split(',')[1], 'base64')); };

  // ── test media: the drawn presenter (a person, 2 s), three times: a 6 s reel ──
  await ev(WORLD_JS);
  const f = path.join(OUT, 'presenter.mp4');
  if (!fs.existsSync(f)) {
    const dir = path.join(OUT, 'frames'); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir, { recursive: true });
    for (let i = 0; i < 2 * FPS; i++) {
      const jpg = await ev(([t]) => { const c = document.createElement('canvas'); c.width = 540; c.height = 960; window.W.draw(c, 'presenter', t, 'rgb'); return c.toDataURL('image/jpeg', 0.93); }, [i / FPS]);
      fs.writeFileSync(path.join(dir, String(i).padStart(4, '0') + '.jpg'), Buffer.from(jpg.split(',')[1], 'base64'));
    }
    ff('-framerate', String(FPS), '-i', path.join(dir, '%04d.jpg'), '-f', 'lavfi', '-i', 'sine=frequency=220:duration=2:sample_rate=48000', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '16', '-pix_fmt', 'yuv420p', '-g', '15', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', f);
    fs.rmSync(dir, { recursive: true, force: true });
  }
  fs.copyFileSync(f, path.join(LIB, 'presenter.mp4'));
  await page.setInputFiles('.home-drop input[type=file]', path.join(LIB, 'presenter.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await ev(async () => {
    const ed = window.kbbEditor; window.ED = ed; window.CTX = ed.context();
    window.TH = await import('/app/themes/index.js'); window.EL = await import('/app/themes/elements.js');
    window.CTA = await import('/app/themes/cta.js'); window.CTA2 = await import('/app/themes/cta2.js'); window.OLD = await import('/app/themes/cta-before.js');
    window.TX = await import('/app/themes/text.js'); window.TP = await import('/app/themes/text-panel.js'); window.KIT = await import('/app/themes/kit.js');
    window.R = await import('/app/core/render.js'); window.E = await import('/app/core/export.js'); window.P = await import('/app/core/project.js');
    const m = ed.project.media[0]; ed.addMediaToTimeline(m); ed.addMediaToTimeline(m);
    ed.workspace.contactInfo = { phone: '+971 50 123 4567', whatsapp: '+971 55 765 4321', email: 'hello@kbeautybliss.com', website: 'kbeautybliss.com' };
    window.items = () => ed.project.tracks.flatMap((t) => t.items);
    window.find = (id) => window.items().find((i) => i.id === id);
    window.steps = () => ed.history.undoStack.length;
    /** one element alone, drawn at local time t into a w×h canvas (scale k): its RGBA */
    window.DRAW = (style, t, { d = 12, k = 0.5, box = null } = {}) => {
      const p = ed.project, b = box || window.EL.boxFor('cta', p.canvas.w, p.canvas.h, style.variant), pad = 40;
      const c = document.createElement('canvas'); c.width = Math.round((b.w + pad * 2) * k); c.height = Math.round((b.h + pad * 2) * k);
      const g = c.getContext('2d', { willReadFrequently: true }); g.setTransform(k, 0, 0, k, pad * k, pad * k);
      const it = window.P.createItem('theme.el', { t0: 0, in: 0, out: d, ...b, style });
      window.EL.drawElement(g, 'cta', it, p, { box: { x: 0, y: 0, w: b.w, h: b.h }, local: t, preview: false, w: p.canvas.w, h: p.canvas.h });
      return { c, w: c.width, h: c.height, d: g.getImageData(0, 0, c.width, c.height).data, box: b, k, pad };
    };
    /** pixels that differ (any channel by > 24) between two draws */
    window.DIFF = (a, b) => { let n = 0; for (let i = 0; i < a.d.length; i += 4) if (Math.abs(a.d[i] - b.d[i]) > 24 || Math.abs(a.d[i + 1] - b.d[i + 1]) > 24 || Math.abs(a.d[i + 2] - b.d[i + 2]) > 24 || Math.abs(a.d[i + 3] - b.d[i + 3]) > 24) n++; return n; };
    window.STYLE = (v, look) => ({ ...window.EL.elementStyle('europe', 'cta', { variant: v, ws: ed.workspace }), ...(look ? { ctaLook: look } : {}) });
  });

  // ════════ catalogue ════════
  const cat = await ev(() => {
    const ids = window.EL.elementById('cta').variants, slim = window.CTA2.CTA2_DESIGNS.map((d) => d.id);
    const it = window.TH.placeElement(window.CTX, 'cta', { theme: 'europe', t0: 0.3, dur: 5.5 });
    const placed = window.find(it.id).style.variant; window.ED.history.undo();
    return { ids, slim, placed, firstSlim: ids.slice(0, slim.length).every((v, i) => v === slim[i]), oldAll: ['call', 'whatsapp', 'phonewa', 'stack', 'email', 'website', 'instagram', 'tiktok', 'snapchat', 'youtube', 'socials', 'qr', 'consult', 'strip', 'tap'].every((v) => ids.includes(v)) };
  });
  ok(cat.slim.length >= 10 && cat.firstSlim, `${cat.slim.length} slim designs listed first (${cat.slim.join(', ')}), ${cat.ids.length} in all`);
  ok(cat.oldAll, 'the 15 old designs are all still offered');
  ok(cat.slim.includes(cat.placed), `a newly placed Contact card (no design picked) is a slim one: ${cat.placed}`);
  const SLIM = cat.slim;

  // ════════ height ════════
  const hgt = await ev((SLIM) => {
    const p = window.ED.project, W = p.canvas.w, Hh = p.canvas.h, ink = (r) => { let y0 = 1e9, y1 = -1; for (let y = 0; y < r.h; y++) for (let x = 0; x < r.w; x += 2) if (r.d[(y * r.w + x) * 4 + 3] > 40) { if (y < y0) y0 = y; y1 = y; break; } return y1 < 0 ? 0 : (y1 - y0 + 1) / r.k; };
    const oldBox = window.EL.boxFor('cta', W, Hh, 'phonewa'), oldInk = ink(window.DRAW(window.STYLE('phonewa'), 2, { k: 1 }));
    const per = {};
    for (const v of SLIM) { const b = window.EL.boxFor('cta', W, Hh, v); per[v] = { box: b.h, w: b.w, ink: Math.round(ink(window.DRAW(window.STYLE(v), 2, { k: 1 }))) }; }
    return { oldBox: oldBox.h, oldW: oldBox.w, oldInk: Math.round(oldInk), per };
  }, SLIM);
  const tall = Object.entries(hgt.per).filter(([, x]) => x.box > hgt.oldBox * 0.6 || x.ink > hgt.oldInk * 0.6);
  ok(!tall.length, `every slim card ≤ 60 % of the old one's height on 9:16 (old Phone + WhatsApp: box ${hgt.oldW}×${hgt.oldBox}, ink ${hgt.oldInk} px): ${Object.entries(hgt.per).map(([v, x]) => `${v} ${x.w}×${x.box} (ink ${x.ink}, ${Math.round(x.box / hgt.oldBox * 100)} %)`).join(', ')}${tall.length ? ' TOO TALL ' + tall.map((x) => x[0]).join(',') : ''}`);

  // ════════ motion: whole duration ════════
  const mot = await ev((SLIM) => {
    const out = {};
    for (const v of SLIM) {
      const st = window.STYLE(v), at = (t) => window.DRAW(st, t, { d: 12, k: 0.4 });
      const pairs = [[1.0, 1.5], [5.6, 6.1], [10.9, 11.4]].map(([a, b]) => window.DIFF(at(a), at(b)));
      const still = { ...st, ctaLook: { show: { anim: false } } }, s1 = window.DRAW(still, 6, { d: 12, k: 0.4 }), s2 = window.DRAW(still, 10.5, { d: 12, k: 0.4 });
      out[v] = { pairs, frozen: window.DIFF(s1, s2) };
    }
    return out;
  }, SLIM);
  const still = Object.entries(mot).filter(([, x]) => x.pairs.some((n) => n < 40));
  ok(!still.length, `every slim card keeps moving for the whole 12 s (pixels changed between frames 0.5 s apart at 1 s · 5.6 s · 10.9 s): ${Object.entries(mot).map(([v, x]) => `${v} ${x.pairs.join('/')}`).join(', ')}${still.length ? ' STILL ' + still.map((x) => x[0]).join(',') : ''}`);
  ok(Object.values(mot).every((x) => x.frozen === 0), `Animation off holds still (6 s vs 10.5 s: ${Object.entries(mot).map(([v, x]) => v + ' ' + x.frozen).join(', ')})`);

  // ════════ parts: every toggle, every design ════════
  const parts = await ev((SLIM) => {
    const out = {}, PARTS = window.CTA2.CTA2_PARTS.map((x) => x[0]), T = 2.1;
    for (const v of SLIM) {
      const des = window.CTA.ctaById(v), base = window.STYLE(v), d0 = window.DRAW(base, T, { k: 0.4 }), row = {};
      for (const k of PARTS) {
        const def = k === 'sparkles' ? !!des.on?.includes(k) : !des.off.includes(k);
        row[k] = window.DIFF(d0, window.DRAW({ ...base, ctaLook: { show: { [k]: !def } } }, T, { k: 0.4 }));
      }
      out[v] = row;
    }
    // hiding removes the ink: the number hidden -> it is not drawn; the background hidden -> the bar's centre-right is see-through
    const words = {}, holes = {};
    for (const v of SLIM) {
      const dr = [], p = window.ED.project, b = window.EL.boxFor('cta', p.canvas.w, p.canvas.h, v), it = window.P.createItem('theme.el', { t0: 0, in: 0, out: 6, ...b, style: { ...window.STYLE(v), ctaLook: { show: { number: false } } } });
      const c = document.createElement('canvas'); c.width = 600; c.height = 200; window.EL.drawElement(c.getContext('2d'), 'cta', it, p, { box: { x: 0, y: 0, w: b.w, h: b.h }, local: 2.1, preview: false, w: 1080, h: 1920 }, null, false, { draws: dr });
      words[v] = dr.some((x) => x.key === 'phone');
      const r = window.DRAW({ ...window.STYLE(v), ctaLook: { show: { bg: false, sparkles: false, glow: false, border: false } } }, 2.1, { k: 0.5 }), cx = Math.round(r.w * 0.93) - Math.round(r.pad * r.k), cy = Math.round(r.h * 0.18);
      // a few pixels near the right end, high up: no words there; the bar alone would be opaque
      let a = 0; for (let y = cy; y < cy + 4; y++) for (let x = cx - 4; x < cx; x++) a = Math.max(a, r.d[(y * r.w + x) * 4 + 3]);
      holes[v] = a;
    }
    return { out, words, holes };
  }, SLIM);
  const dead = [];
  for (const [v, row] of Object.entries(parts.out)) for (const [k, n] of Object.entries(row)) if (n < 30) dead.push(`${v}:${k}(${n})`);
  ok(!dead.length, `all ${SLIM.length * 10} show / hide toggles change the card (min ${Math.min(...Object.values(parts.out).flatMap((r) => Object.values(r)))} px changed)${dead.length ? ' DEAD ' + dead.join(' ') : ''}`);
  ok(Object.values(parts.words).every((x) => !x), `"Number" off: no design draws the number any more (${Object.entries(parts.words).filter(([, x]) => x).map(([v]) => v).join(',') || 'none drawn'})`);
  ok(Object.values(parts.holes).every((a) => a < 20), `"Background" off (+ border, glow, sparkles off): the bar is see-through (alpha at its right end: ${Object.entries(parts.holes).map(([v, a]) => v + ' ' + a).join(', ')})`);

  // ════════ colours ════════
  const cols = await ev((SLIM) => {
    const roles = window.CTA2.CTA2_COLORS.map((x) => x[0]), out = {}, bgHit = {}, T = 2.1;
    for (const v of SLIM) {
      const base = window.STYLE(v), d0 = window.DRAW(base, T, { k: 0.4 }), row = {};
      // a colour of a part the design hides by default is tested with that part shown (it cannot show otherwise)
      for (const k of roles) { const show = k === 'bg' ? { bg: true } : k === 'chip' ? { chips: true } : {}, a = window.DRAW({ ...base, ctaLook: { show } }, T, { k: 0.4 }); row[k] = window.DIFF(a, window.DRAW({ ...base, ctaLook: { show, col: { [k]: '#00c853' } } }, T, { k: 0.4 })); }
      out[v] = row;
      // a picked background colour is the bar's colour (sampled where only the bar is: its right end, below the frosted top half)
      const r = window.DRAW({ ...base, ctaLook: { col: { bg: '#2040e0' }, show: { glow: false, sparkles: false, border: false, bg: true } } }, T, { k: 0.5 });
      let best = 1e9; const y = Math.round(r.pad * r.k + r.box.h * r.k * 0.7);
      for (let x = Math.round(r.w * 0.8); x < Math.round(r.w - r.pad * r.k - r.box.h * r.k * 0.5); x++) { const i = (y * r.w + x) * 4; best = Math.min(best, Math.abs(r.d[i] - 0x20) + Math.abs(r.d[i + 1] - 0x40) + Math.abs(r.d[i + 2] - 0xe0)); }
      bgHit[v] = best;
    }
    return { out, bgHit };
  }, SLIM);
  const deaf = [];
  for (const [v, row] of Object.entries(cols.out)) for (const [k, n] of Object.entries(row)) if (n < 30) deaf.push(`${v}:${k}(${n})`);
  ok(!deaf.length, `all ${SLIM.length * 7} colour pickers reach the pixels (min ${Math.min(...Object.values(cols.out).flatMap((r) => Object.values(r)))} px changed)${deaf.length ? ' DEAF ' + deaf.join(' ') : ''}`);
  ok(Object.values(cols.bgHit).every((d) => d < 110), `a picked background (#2040e0) is the bar's colour, give or take the design's own shading (RGB distance < 110 at the bar's right end: ${Object.entries(cols.bgHit).map(([v, d]) => v + ' ' + d).join(', ')})`);

  // ════════ speed, intensity, corners ════════
  const ctl = await ev((SLIM) => {
    const out = {};
    for (const v of SLIM) {
      const at = (look, t) => window.DRAW({ ...window.STYLE(v), ctaLook: look }, t, { k: 0.4 });
      const n = at({}, 3), slow = at({ speed: 'slow' }, 3), fast = at({ speed: 'fast' }, 3);
      out[v] = { slow: window.DIFF(n, slow), fast: window.DIFF(n, fast), amt: window.DIFF(at({ amt: 0 }, 3), at({ amt: 1 }, 3)), round: window.DIFF(at({ round: 0, show: { bg: true } }, 3), at({ round: 1, show: { bg: true } }, 3)) };
    }
    return out;
  }, SLIM);
  const weak = Object.entries(ctl).filter(([, x]) => x.slow < 30 || x.fast < 30 || x.amt < 30 || x.round < 30);
  ok(!weak.length, `speed (slow / fast), intensity (0 vs 100 %) and corners (square vs pill) each change every design: ${Object.entries(ctl).map(([v, x]) => `${v} ${x.slow}/${x.fast}/${x.amt}/${x.round}`).join(', ')}${weak.length ? ' WEAK ' + weak.map((x) => x[0]).join(',') : ''}`);

  // ════════ the old designs: pixel-identical to the code before ════════
  const oldSame = await ev(() => {
    const ids = window.OLD.CTA_DESIGNS.map((d) => d.id), K = window.KIT.kitFor('europe'), bad = [];
    let n = 0;
    for (const v of ids) for (const lang of ['en', 'ar']) for (const t of [0.2, 0.9, 2.5, 3.85]) {
      const st = { ...window.STYLE(v), lang }, des = window.OLD.ctaById(v), w = 560, hh = w / des.ar;
      const S = (style) => ({ w, h: hh, tl: t, d: 4, t, inn: (s) => 1 - Math.pow(1 - Math.min(1, t / s), 3), out: (s) => 1 - Math.pow(Math.max(0, Math.min(1, (t - (4 - s)) / s)), 3), txt: {}, style });
      const a = document.createElement('canvas'), b = document.createElement('canvas'); a.width = b.width = 600; a.height = b.height = Math.ceil(hh) + 40;
      const ga = a.getContext('2d', { willReadFrequently: true }), gb = b.getContext('2d', { willReadFrequently: true }); ga.translate(20, 20); gb.translate(20, 20);
      window.OLD.drawCta(ga, K, S(st)); window.CTA.drawCta(gb, K, S(st));
      const da = ga.getImageData(0, 0, a.width, a.height).data, db = gb.getImageData(0, 0, b.width, b.height).data;
      let diff = 0; for (let i = 0; i < da.length; i++) if (da[i] !== db[i]) diff++;
      n++; if (diff) bad.push(`${v}/${lang}@${t}:${diff}`);
    }
    // and an old project (no ctaLook, an old variant) as the editor draws it: the same through drawElement
    return { n, bad, ids: ids.length };
  });
  ok(oldSame.ids === 15 && oldSame.bad.length === 0, `the ${oldSame.ids} old designs draw byte-identical to before (${oldSame.n} frames, English + Arabic, in / hold / out)${oldSame.bad.length ? ' DIFFER ' + oldSame.bad.slice(0, 6).join(' ') : ''}`);

  // ════════ words: still text slots ════════
  const wd = await ev(() => {
    const ed = window.ED, it = window.TH.placeElement(window.CTX, 'cta', { theme: 'europe', variant: 'shimmer', t0: 0.3, dur: 5.5 });
    const item = window.find(it.id), fields = window.TP.fieldsFor(item, ed.project);
    window.CTA_ID = it.id;
    return { keys: fields.map((f) => f.key + '=' + f.value) };
  });
  ok(wd.keys.some((k) => k === 'phone=+971 50 123 4567') && wd.keys.some((k) => /^bothLbl=/.test(k)), `the label and the number are inspector text fields (${wd.keys.join(' · ')})`);
  await ev(() => { window.ED.select([window.CTA_ID]); window.ED.seek(2.2); window.ED.showSide?.('inspector'); }); await page.waitForTimeout(700);
  const ov = await page.locator('.pv-overlay').boundingBox();
  await page.waitForFunction(() => { window.ED.preview.draw?.(); return !!window.TX.lastBoxes(window.CTA_ID)?.boxes.some((x) => x.key === 'bothLbl'); }, null, { timeout: 15000, polling: 300 }).catch(() => {});
  const at = await ev(() => { const id = window.CTA_ID, it = window.find(id), b = window.TX.lastBoxes(id)?.boxes.find((x) => x.key === 'bothLbl'), k = window.ED.preview.k; return b && { x: (it.x + b.x + b.w / 2) * k, y: (it.y + b.y + b.h / 2) * k }; });
  let dbl = null;
  if (at) {
    await page.mouse.dblclick(ov.x + at.x, ov.y + at.y);
    await page.waitForSelector('[data-th-canvas-text]', { timeout: 5000 }).catch(() => {});
    dbl = await ev(() => { const i = document.querySelector('[data-th-canvas-text]'); return i ? { key: i.dataset.thCanvasText, value: i.value } : null; });
    if (dbl) { await page.keyboard.press('Meta+A').catch(() => {}); await page.keyboard.press('Control+A'); await page.keyboard.type('BOOK YOUR GLOW-UP'); await page.keyboard.press('Enter'); await page.waitForTimeout(300); }
  }
  const lbl = await ev(() => window.find(window.CTA_ID).style.text?.bothLbl);
  ok(dbl?.key === 'bothLbl' && /BOOK YOUR GLOW-UP/.test(lbl || ''), `double-click the slim card's label on the video: an input opens (${dbl?.key} “${dbl?.value}”), typing replaces it → “${lbl}”`);
  if (lbl) { await ev(() => { window.ED.history.undo(); }); await page.waitForTimeout(200); }
  else console.log('     (no label box: ' + JSON.stringify(await ev(() => window.TX.lastBoxes(window.CTA_ID))) + ')');

  // ════════ ui: inspector controls, one undo step each ════════
  await ev(() => { window.ED.select([window.CTA_ID]); window.ED.showSide?.('inspector'); window.ED.inspector?.render?.(true); }); await page.waitForTimeout(500);
  const ui = {};
  const snap = () => ev(() => JSON.stringify(window.find(window.CTA_ID).style));
  const s0 = await snap(), n0 = await ev(() => window.steps());
  ui.found = await ev(() => ['[data-cta-show="wa"]', '[data-cta-col="bg"]', '[data-cta-amt]', '[data-cta-round]', '[data-cta-speed] [data-v="fast"]'].map((s) => !!document.querySelector(s)));
  await page.click('[data-cta-show="wa"]'); await page.waitForTimeout(250);
  const a1 = await ev(() => ({ steps: window.steps(), wa: window.find(window.CTA_ID).style.ctaLook?.show?.wa, checked: document.querySelector('[data-cta-show="wa"]')?.checked }));
  await ev(() => window.ED.history.undo()); await page.waitForTimeout(250);
  const back1 = (await snap()) === s0;
  // a colour dragged in the native picker: many input events, then change
  await ev(async () => { const inp = document.querySelector('[data-cta-col="bg"]'); for (const c of ['#aa2266', '#bb3377', '#cc4488', '#7a1f4f']) { inp.value = c; inp.dispatchEvent(new Event('input', { bubbles: true })); await new Promise((r) => setTimeout(r, 120)); } inp.dispatchEvent(new Event('change', { bubbles: true })); });
  await page.waitForTimeout(250);
  const a2 = await ev(() => ({ steps: window.steps(), bg: window.find(window.CTA_ID).style.ctaLook?.col?.bg }));
  // a slider dragged
  await ev(async () => { const inp = document.querySelector('[data-cta-amt]'); for (const v of [0.7, 0.8, 0.9, 1]) { inp.value = v; inp.dispatchEvent(new Event('input', { bubbles: true })); await new Promise((r) => setTimeout(r, 100)); } inp.dispatchEvent(new Event('change', { bubbles: true })); });
  await page.waitForTimeout(250);
  const a3 = await ev(() => ({ steps: window.steps(), amt: window.find(window.CTA_ID).style.ctaLook?.amt }));
  await page.click('[data-cta-speed] [data-v="fast"]'); await page.waitForTimeout(250);
  const a4 = await ev(() => ({ steps: window.steps(), speed: window.find(window.CTA_ID).style.ctaLook?.speed }));
  ok(ui.found.every(Boolean), `Inspector → Contact card shows Show / Colours / Motion controls for a slim card (${ui.found.join(',')})`);
  ok(a1.steps === n0 + 1 && a1.wa === false && a1.checked === false && back1, `"💬 WhatsApp icon" unticked: ONE undo step (${a1.steps - n0}), ⌘Z puts the style back byte for byte (${back1})`);
  ok(a2.steps === n0 + 1 && a2.bg === '#7a1f4f', `a background colour dragged through 4 values: ONE undo step (${a2.steps - n0}), the last value kept (${a2.bg})`);
  ok(a3.steps === n0 + 2 && a3.amt === 1, `the intensity slider dragged: ONE undo step (${a3.steps - a2.steps}) → ${a3.amt}`);
  ok(a4.steps === n0 + 3 && a4.speed === 'fast', `speed Fast: ONE undo step (${a4.steps - a3.steps})`);
  await ev(() => { window.ED.history.undo(); window.ED.history.undo(); window.ED.history.undo(); }); await page.waitForTimeout(250);
  ok((await snap()) === s0, 'three ⌘Z: the card is exactly as placed');

  // ════════ draw time per frame ════════
  const perf = await ev((SLIM) => {
    const p = window.ED.project, c = document.createElement('canvas'); c.width = 1080; c.height = 1920; const g = c.getContext('2d');
    const time = (v, look) => {
      const b = window.EL.boxFor('cta', 1080, 1920, v), it = window.P.createItem('theme.el', { t0: 0, in: 0, out: 12, ...b, style: { ...window.STYLE(v), ...(look ? { ctaLook: look } : {}) } });
      for (let i = 0; i < 10; i++) window.EL.drawElement(g, 'cta', it, p, { box: b, local: 1 + i * 0.1, preview: false, w: 1080, h: 1920 });
      const n = 120, t0 = performance.now();
      for (let i = 0; i < n; i++) { g.save(); window.EL.drawElement(g, 'cta', it, p, { box: b, local: 0.5 + i / 30, preview: false, w: 1080, h: 1920 }); g.restore(); }
      g.getImageData(0, 0, 1, 1);
      return +((performance.now() - t0) / n).toFixed(3);
    };
    for (const v of SLIM) time(v); time('phonewa');   // a first pass warms fonts, gradients and the JIT up
    const out = {}; for (const v of SLIM) out[v] = time(v); out['phonewa (old)'] = time('phonewa'); out['sparkle + all on'] = time('sparkle', { amt: 1, show: { border: true } });
    return out;
  }, SLIM);
  const worst = Math.max(...SLIM.map((v) => perf[v]));
  ok(worst < 4, `draw time per frame at 1080×1920 (ms, CPU 2D canvas, swiftshader): ${Object.entries(perf).map(([v, ms]) => v + ' ' + ms).join(', ')} — worst slim ${worst} ms (< 4)`);

  // ════════ preview = export ════════
  const same = await ev(async () => {
    const ed = window.ED;
    window.TH.placeElement(window.CTX, 'cta', { theme: 'europe', variant: 'runner', t0: 0, dur: 6, box: undefined });
    await window.R.prepareRender(ed.project);
    const r = await window.E.exportMp4(ed.project, ed.media, { quality: 'high', width: 540, height: 960 });
    const file = `workspaces/${ed.project.workspace}/exports/cta-same.mp4`; await ed.engine.writeFile(file, r.blob);
    return file;
  });
  const sm = [];
  for (const t of [1.0, 2.6, 4.3, 5.6]) {
    await ev((t) => window.ED.seek(t), t);
    await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(800);
    const pv = await ev(() => { const c = document.createElement('canvas'); c.width = 135; c.height = 240; const g = c.getContext('2d', { willReadFrequently: true }); g.drawImage(window.ED.preview.canvas, 0, 0, 135, 240); return Array.from(g.getImageData(0, 0, 135, 240).data).filter((_, i) => i % 4 !== 3); });
    const ex = frameRGB(path.join(STUDIO, same), t, 135, 240);
    let s = 0; for (let i = 0; i < ex.length; i++) s += Math.abs(ex[i] - pv[i]);
    sm.push([t, +(s / ex.length).toFixed(2)]);
  }
  ok(sm.every(([, d]) => d < 6), `preview = export with two slim cards on (Glass shimmer + Running light): mean difference ${sm.map(([t, d]) => `${t}s ${d}`).join(', ')} (/255, < 6)`);

  // ════════ search: the new controls are found and opened ════════
  const srch = [];
  for (const [q, want, sel] of [['hide whatsapp icon', /show or hide parts/, '[data-cta-show]'], ['cta colour', /colours of each part/, '[data-cta-col]'], ['cta speed', /speed, intensity, corners/, '[data-cta-speed]']]) {
    await ev(() => { window.ED.select([]); window.ED.showSide?.('captions'); }); await page.waitForTimeout(200);
    const r = await ev(async ([q, sel]) => {
      const S = await import('/app/core/search-store.js'), N = await import('/app/editor/search-nav.js'), st = await S.getStore(), top = st.index.search(q)[0]?.e;
      if (!top) return { title: '(nothing)' };
      await N.goTo(top); await new Promise((res) => setTimeout(res, 500));
      const ring = document.querySelector('.kbs-pulse'), r2 = ring?.getBoundingClientRect();
      return { title: top.title, ringed: !!ring && !!ring.closest(sel) || !!ring?.querySelector?.(sel) || !!ring?.matches?.(sel), visible: !!r2 && r2.bottom > 0 && r2.top < innerHeight };
    }, [q, sel]);
    srch.push(`“${q}” → ${r.title}${r.ringed ? ' (ringed' + (r.visible ? ', visible)' : ', HIDDEN)') : ' (NOT RINGED)'}`);
    if (!(want.test(r.title || '') && r.ringed && r.visible)) srch.push('FAIL');
  }
  ok(!srch.includes('FAIL'), `search finds and opens the new controls: ${srch.filter((x) => x !== 'FAIL').join(' · ')}`);

  // ════════ shots ════════
  if (SHOTS_ON) {
    const pics = await ev(async (SLIM) => {
      const H2 = await import('/app/themes/hover.js'), EL = window.EL, P = window.P, ws = window.ED.workspace, out = {};
      const mk = (items) => { const p = H2.miniProject('9:16'); p.tracks[0].items.push(P.createItem('clip', { id: 'hp-clip-0-0', t0: 0, in: 0, out: 12, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, variant: 0 })); for (const it of items) p.tracks[2].items.push(it); return p; };
      const el = (v, box, look) => { const p0 = H2.miniProject('9:16'); return P.createItem('theme.el', { t0: 0, in: 0, out: 12, ...(box || EL.boxFor('cta', p0.canvas.w, p0.canvas.h, v)), style: { ...EL.elementStyle('europe', 'cta', { variant: v, ws }), ...(look ? { ctaLook: look } : {}) } }); };
      const p0 = H2.miniProject('9:16'), W = p0.canvas.w;
      // the gallery: all ten stacked on one 9:16 frame
      const items = SLIM.map((v, i) => { const b = EL.boxFor('cta', W, p0.canvas.h, v), w = Math.round(W * 0.8), h = Math.round(w / window.CTA.ctaById(v).ar); return el(v, { x: Math.round((W - w) / 2), y: 150 + i * 168, w, h: Math.min(h, b.h) }); });
      const c = document.createElement('canvas'); c.width = 720; c.height = 1280; H2.drawMini(c.getContext('2d'), 720, 1280, mk(items), 2.3); out['00-gallery-all-ten-9x16'] = c.toDataURL('image/png');
      SLIM.forEach((v, i) => { const c2 = document.createElement('canvas'); c2.width = 432; c2.height = 768; H2.drawMini(c2.getContext('2d'), 432, 768, mk([el(v)]), 2.3); out[`${String(i + 1).padStart(2, '0')}-${v}`] = c2.toDataURL('image/png'); });
      // a motion strip per design: the bar's region at four moments (1 s, 2.4 s, 5 s, 10.6 s of a 12 s card)
      SLIM.forEach((v, i) => {
        const it = el(v), p = mk([it]), sc = 0.5, pad = 24, bw = Math.round((it.w + pad * 2) * sc), bh = Math.round((it.h + pad * 2) * sc), times = [1, 2.4, 5, 10.6];
        const full = document.createElement('canvas'); full.width = p.canvas.w * sc; full.height = p.canvas.h * sc;
        const strip = document.createElement('canvas'); strip.width = bw; strip.height = bh * times.length + 6 * (times.length - 1); const sg = strip.getContext('2d'); sg.fillStyle = '#111'; sg.fillRect(0, 0, strip.width, strip.height);
        times.forEach((t, k) => { H2.drawMini(full.getContext('2d'), full.width, full.height, p, t); sg.drawImage(full, (it.x - pad) * sc, (it.y - pad) * sc, bw, bh, 0, k * (bh + 6), bw, bh); sg.fillStyle = 'rgba(0,0,0,.6)'; sg.fillRect(4, k * (bh + 6) + 4, 64, 22); sg.fillStyle = '#fff'; sg.font = '600 14px sans-serif'; sg.fillText(t + ' s', 10, k * (bh + 6) + 20); });
        out[`strip-${String(i + 1).padStart(2, '0')}-${v}`] = strip.toDataURL('image/png');
      });
      // a few of his controls at work: own colours, parts hidden, Arabic
      const custom = [
        el('shimmer', null, { col: { bg: '#f4c6d7', text: '#5a1534', label: '#a3456e', chip: '#ffffff', icon: '#c2185b' } }),
        el('split', null, { show: { wa: false, label: false }, col: { chip: '#111111', icon: '#ffd166' } }),
        el('neon', null, { col: { accent: '#7cf9ff', glow: '#00e5ff', label: '#bdf7ff' } }),
        el('underline', null, { show: { phone: false } }),
      ];
      custom.forEach((it, i) => { it.y = 260 + i * 330; });
      const c3 = document.createElement('canvas'); c3.width = 540; c3.height = 960; H2.drawMini(c3.getContext('2d'), 540, 960, mk(custom), 2.6); out['11-own-colours-and-hidden-parts'] = c3.toDataURL('image/png');
      const ar = el('ringing'); ar.style.lang = 'ar';
      const c4 = document.createElement('canvas'); c4.width = 432; c4.height = 768; H2.drawMini(c4.getContext('2d'), 432, 768, mk([ar]), 2.3); out['12-arabic-ringing'] = c4.toDataURL('image/png');
      return out;
    }, SLIM);
    for (const [n, d] of Object.entries(pics)) save(n, d);
    // the controls in the real editor, 1440×900 and 1280×800
    await ev(() => { const it = window.items().find((i) => i.style?.el === 'cta' && i.style.variant === 'shimmer') || window.items().find((i) => i.style?.el === 'cta'); window.CTA_ID = it.id; window.ED.select([it.id]); window.ED.seek(2.3); window.ED.showSide?.('inspector'); window.ED.inspector?.render?.(true); });
    await page.waitForTimeout(800);
    const reveal = (sel) => ev((sel) => { const el = document.querySelector(sel); if (!el) return false; el.scrollIntoView({ block: 'start' }); let p = el.parentElement; while (p && p.scrollHeight <= p.clientHeight + 2) p = p.parentElement; if (p) p.scrollTop -= 60; return true; }, sel);
    for (const [w, hh] of [[1440, 900], [1280, 800]]) {
      await page.setViewportSize({ width: w, height: hh }); await page.waitForTimeout(600);
      await reveal('.e2-sub'); await page.waitForTimeout(300); await page.screenshot({ path: path.join(SHOTS, `controls-designs-${w}x${hh}.png`) });
      await reveal('[data-cta-show]'); await ev(() => { const el = document.querySelector('[data-cta-show]'); let p = el.parentElement; while (p && p.scrollHeight <= p.clientHeight + 2) p = p.parentElement; if (p) p.scrollTop -= 60; }); await page.waitForTimeout(300); await page.screenshot({ path: path.join(SHOTS, `controls-show-colours-${w}x${hh}.png`) });
      await reveal('[data-cta-col="bg"]'); await page.waitForTimeout(300); await page.screenshot({ path: path.join(SHOTS, `controls-colours-motion-${w}x${hh}.png`) });
    }
    const sw = await ev(() => ({ sw: document.documentElement.scrollWidth, w: innerWidth }));
    ok(sw.sw <= sw.w, `no sideways scroll at 1280×800 (scrollWidth ${sw.sw})`);
    ok(true, `shots in shots/cta2/: ${fs.readdirSync(SHOTS).filter((x) => x.endsWith('.png')).length} images`);
  }

  ok(errors.length === 0, `no console errors (${errors.length}${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''})`);
  ok(outside.length === 0, `no network outside the engine (${outside.length})`);
  const sum = `\n${failures ? failures + ' FAILED' : 'ALL PASS'}${MUTATE ? ' (mutation: ' + MUTATE + ')' : ''} · ${((Date.now() - T0) / 1000).toFixed(0)} s`;
  if (SHOTS_ON) fs.writeFileSync(path.join(SHOTS, 'check.txt'), results.join('\n') + '\n' + sum + '\n');
  console.log(sum);
  await browser.close(); await H.stopEngine(e);
  process.exit(failures ? 1 : 0);
})().catch(async (err) => { console.error(err); process.exit(2); });
