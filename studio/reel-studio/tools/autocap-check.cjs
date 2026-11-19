#!/usr/bin/env node
// KBB Studio — Auto captions per clip, headless Chromium against the Linux engine, REAL Whisper, through the panel.
//
// The owner's report: three iPhone clips back to back on the main track, Auto captions, and only the first clip got
// captions (the caption layer ended at 4.3 s). Reproduced: captions made while the reel held one clip; clips added
// afterwards got none and nothing said so, and the caption layer (last word + 1 s, short of the reel's end) never
// grew with the reel. This check drives exactly that and the per-clip behaviour he asked for:
//   owner's flow   clip 1 captioned, clips 2 + 3 added -> the panel names them ("Caption the new clips"); one click
//                  captions them, clip 1 untouched, the caption layer spans to clip 3's last word
//   whole video    no selection -> "Whole video", every clip's words at its own times, layer spans all three
//   selected       clip 2 only: only its range is re-captioned; clip 1 and 3 words, line ids and a line's position stay
//   multi-select   clips 2 + 3 together
//   4th clip       added later, selected, re-run on it
//   no sound       a clip with no audio track and a clip with a silent track: each named, with the reason
//   undo           every run is ONE undo step; save -> reload keeps the captions and the caption layer
// Clips are made like iPhone files: H.264, AAC 44.1 kHz stereo, .mov, a rotation tag, a different sentence each (flite).
//
// usage: bash tools/r2-fetch-test-models.sh && NODE_PATH=/home/user/kbbstore/node_modules node tools/autocap-check.cjs [--model small]
// (the Whisper files are hard-linked into a throwaway studio; the check marks them installed — the manifest also lists
// the WebGPU files the test fetch skips.)
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'autocap-check'), SHOTS = path.join(ROOT, 'shots', 'autocap');
const MODEL = process.argv.includes('--model') ? process.argv[process.argv.indexOf('--model') + 1] : 'base';
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (c, msg) => { console.log((c ? 'PASS ' : 'FAIL ') + msg); if (!c) failures++; };
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a]);

// name, sentence, voice, seconds, colour ('' = no audio track, '-' = a silent audio track)
const CLIPS = [
  ['IMG_7670', 'Hello and welcome to my channel today', 'slt', 4.5, 'pink'],
  ['IMG_7680', 'This vitamin serum makes my skin glow every single morning', 'kal', 6.1, 'teal'],
  ['IMG_7710', 'Thank you for watching and see you soon', 'awb', 4.6, 'navy'],
  ['IMG_7730', 'Remember to follow for more beauty tips', 'rms', 4.0, 'purple'],
  ['IMG_7740', '', '', 3.0, 'gray'],
  ['IMG_7750', '-', '', 3.0, 'olive'],
];
function makeMedia() {
  for (const [n, text, voice, d, col] of CLIPS) {
    const mov = path.join(OUT, n + '.mov'); if (fs.existsSync(mov)) continue;
    const v = ['-display_rotation', '90', '-f', 'lavfi', '-i', `color=c=${col}:s=640x360:r=30:d=${d}`];
    if (!text) { ff(...v, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-t', String(d), '-f', 'mov', mov); continue; }
    const wav = path.join(OUT, n + '.wav');
    if (text === '-') ff('-f', 'lavfi', '-i', `anullsrc=r=44100:cl=stereo`, '-t', String(d), wav);
    else ff('-f', 'lavfi', '-i', `flite=text='${text}':voice=${voice}`, '-af', 'adelay=400|400,apad', '-t', String(d), '-ar', '44100', '-ac', '2', wav);
    ff(...v, '-i', wav, '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-ar', '44100', '-ac', '2', '-t', String(d), '-f', 'mov', mov);
  }
}

(async () => {
  makeMedia();
  const STUDIO = H.makeStudio();
  const link = (src, dst) => { if (fs.statSync(src).isDirectory()) { fs.mkdirSync(dst, { recursive: true }); for (const f of fs.readdirSync(src)) link(path.join(src, f), path.join(dst, f)); } else if (!/\.part$/.test(src)) fs.linkSync(fs.realpathSync(src), dst); };
  link(path.join(ROOT, 'models', 'onnx-community'), path.join(STUDIO, 'models', 'onnx-community'));
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  page.setDefaultTimeout(0);
  const errors = [], offsite = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/^\[[\d:.]+\] \[BoxParser\]/.test(m.text())) errors.push(m.text()); });   // (BoxParser: mp4box's own note on ffmpeg-written .mov boxes, harmless)
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(r.url())) offsite.push(r.url()); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  const ev = (fn, a) => page.evaluate(fn, a);
  const shot = async (name) => { for (const [w, h] of [[1920, 1080], [1280, 800]]) { await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(400); await page.screenshot({ path: path.join(SHOTS, `${name}-${w}.png`) }); } await page.setViewportSize({ width: 1920, height: 1080 }); };
  const boot = () => ev(async (model) => {
    const ed = window.kbbEditor, E = ed.engine;
    if (!E.__autocap) { const orig = E.modelsStatus.bind(E); E.modelsStatus = async () => { const st = await orig(); for (const m of st.models) if (m.id === 'whisper-' + model) m.installed = true; return st; }; E.__autocap = true; }
    window.CC = await import('/app/captions/commands.js'); window.ED = ed;
    window.clips = () => ed.project.tracks.find((t) => t.kind === 'video').items.slice().sort((a, b) => a.t0 - b.t0).map((c) => ({ id: c.id, t0: c.t0, t1: c.t0 + (c.out - c.in) / (c.speed || 1), name: ed.project.media.find((m) => m.id === c.media)?.name }));
    // the caption layer = every caption bar (one per clip since clip-by-clip bars): from the first bar's start to the last bar's end
    window.capItem = () => { const its = ed.project.tracks.find((t) => t.kind === 'caption').items.filter((i) => i.type === 'caption'); return its.length ? [Math.min(...its.map((it) => it.t0)), Math.max(...its.map((it) => it.t0 + (it.out - it.in) / (it.speed || 1)))] : null; };
    window.wordsIn = (a, b) => (ed.project.captions?.words || []).filter((w) => (w.t0 + w.t1) / 2 >= a && (w.t0 + w.t1) / 2 < b);
    window.toasts = [];
    if (!window.__tw) { window.__tw = new MutationObserver(() => { for (const t of document.querySelectorAll('.toast')) if (!t.__seen) { t.__seen = 1; window.toasts.push(t.className.replace('toast ', '') + ': ' + t.textContent); } }); window.__tw.observe(document.body, { childList: true, subtree: true }); }
    ed.showSide('captions');
    const s = document.querySelector('.rs-cap-in[title="Speech model"]'); s.value = model; s.dispatchEvent(new Event('change'));
  }, MODEL);
  const importClips = (names) => ev(async ({ files }) => {
    for (const [name, b64] of files) { const b = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)); await ED.importFiles([new File([b], name, { type: 'video/quicktime' })]); }
  }, { files: names.map((n) => [n + '.mov', fs.readFileSync(path.join(OUT, n + '.mov')).toString('base64')]) });
  // run Auto captions from the panel (or the "Caption the new clips" button) and wait for its step and the sync after it
  const run = async (btn = '.rs-cap-btn.primary') => {
    const n0 = await ev(() => { window.toasts = []; return ED.history.undoStack.length; });
    const t = Date.now();
    await page.click(btn);
    await page.waitForFunction(() => !document.querySelector('.rs-cap-btn.primary').disabled);
    const label = await ev(() => ED.history.undoLabel);
    if (/^Auto captions/.test(label)) await page.waitForFunction(() => ED.history.undoLabel === 'Sync words to the voice', null, { timeout: 120000 }).catch(() => {});
    await page.waitForTimeout(300);
    return ev(({ n0, secs }) => ({ steps: ED.history.undoStack.slice(n0).map((c) => c.label), toasts: window.toasts.slice(), secs, words: ED.project.captions?.words?.length || 0 }), { n0, secs: (Date.now() - t) / 1000 });
  };
  const has = (ws, re) => ws.some((w) => re.test(w.w));
  const words = (a, b) => ev(({ a, b }) => window.wordsIn(a, b).map((w) => ({ w: w.w, t0: w.t0, t1: w.t1 })), { a, b });

  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  // ── 1. the owner's flow: clip 1 captioned, then clips 2 and 3 added ──
  await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'IMG_7670.mov'));
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await boot();
  await ev(() => ED.select([]));
  let r = await run();
  ok(r.steps.join(' | ') === 'Auto captions | Sync words to the voice' && r.words >= 5, `clip 1 alone: ${r.words} words in ONE step "${r.steps[0]}" (+ the automatic sync) in ${r.secs.toFixed(1)} s`);
  const c1Before = await words(0, 4.5);
  await importClips(['IMG_7680', 'IMG_7710']);
  await page.waitForFunction(() => window.clips().length === 3);
  await ev(() => ED.select([]));
  await page.waitForTimeout(400);
  const flow = await ev(() => ({ item: window.capItem(), row: document.querySelector('.rs-cap-new')?.hidden === false ? document.querySelector('.rs-cap-new').textContent : null }));
  ok(flow.item[1] < 4.6, `the owner's screen reproduced: after clips 2 and 3 are added the caption layer still ends at ${flow.item[1].toFixed(2)} s (clip 1 only)`);
  ok(!!flow.row && /IMG_7680\.mov/.test(flow.row) && /IMG_7710\.mov/.test(flow.row) && /Caption the new clips/.test(flow.row), `…and the panel now says so: "${flow.row}"`);
  await shot('autocap-01-new-clips');
  r = await run('[data-x="caption-new-clips"]');
  let cl = await ev(() => window.clips());
  let w2 = await words(cl[1].t0, cl[1].t1), w3 = await words(cl[2].t0, cl[2].t1), c1After = await words(0, 4.5);
  let item = await ev(() => window.capItem());
  ok(r.steps[0] === 'Auto captions · selected clips' && has(w2, /serum|vitamin|skin/i) && has(w3, /watching|thank/i), `"Caption the new clips": one step, clip 2 "${w2.map((w) => w.w).join(' ')}", clip 3 "${w3.map((w) => w.w).join(' ')}"`);
  ok(JSON.stringify(c1After.map((w) => w.w)) === JSON.stringify(c1Before.map((w) => w.w)), `clip 1's words kept (${c1After.length})`);
  ok(item[0] === 0 && item[1] >= w3[w3.length - 1].t1 - 1e-3, `the caption layer now spans 0–${item[1].toFixed(2)} s, past clip 3's last word (${w3[w3.length - 1].t1.toFixed(2)} s)`);
  ok(r.toasts.some((t) => /^ok: Captioned 2 clips \(IMG_7680\.mov, IMG_7710\.mov\)/.test(t)), `toast: ${r.toasts[0]}`);
  ok(await ev(() => document.querySelector('.rs-cap-new').hidden), 'the "new clips" row is gone once they have captions');

  // ── 2. whole video ──
  await ev(() => ED.select([]));
  const ui = await ev(() => ({ scope: document.querySelector('[data-k="capScope"]').value, title: document.querySelector('.rs-cap-btn.primary').title }));
  ok(ui.scope === 'all' && ui.title === 'Captions for the whole video (3 clips)', `nothing selected: the choice reads "Whole video", the button says "${ui.title}"`);
  r = await run();
  cl = await ev(() => window.clips());
  const per = []; for (const c of cl) per.push(await words(c.t0, c.t1));
  item = await ev(() => window.capItem());
  const exp = [/welcome|channel/i, /serum|vitamin|skin/i, /watching|thank/i];
  ok(per.every((ws, k) => has(ws, exp[k]) && ws[0].t0 >= cl[k].t0 - 0.5 && ws[ws.length - 1].t1 <= cl[k].t1 + 0.05), `whole video: each clip's own words at its own times — ${per.map((ws, k) => `${cl[k].name} ${ws.length} (${ws[0]?.t0.toFixed(2)}–${ws[ws.length - 1]?.t1.toFixed(2)} s)`).join(', ')}`);
  ok(item[0] === 0 && item[1] >= per[2][per[2].length - 1].t1 - 1e-3 && item[1] <= cl[2].t1 + 1e-3, `the caption layer spans all three clips: 0–${item[1].toFixed(2)} s`);
  ok(r.steps[0] === 'Auto captions' && r.toasts.some((t) => /^ok: Captions for the whole video \(3 clips\)/.test(t)), `one step "${r.steps[0]}"; toast: ${r.toasts[0]}`);
  await shot('autocap-02-whole-video');

  // ── 3. clip 2 selected: only its range re-captioned; a line position and line ids elsewhere kept ──
  const snap = () => ev(() => JSON.parse(JSON.stringify(ED.project.captions)));
  await ev(() => {
    const p = ED.project, c2 = window.clips()[1], i = p.captions.words.findIndex((w) => (w.t0 + w.t1) / 2 >= c2.t0 && (w.t0 + w.t1) / 2 < c2.t1);
    ED.history.apply(CC.editWord(i, 'ZZZ'));
    // the owner dragged clip 1's first line up: a line position override
    ED.history.apply(CC.captionsCommand('pos', 'Move line', (cap) => ({ ...cap, lines: cap.lines.map((L, k) => k === 0 ? { ...L, ov: { ...(L.ov || {}), pos: { x: 0.5, y: 0.3 } } } : L) })));
    ED.select([c2.id]);
  });
  await page.waitForTimeout(300);
  const s0 = await snap();
  const ui2 = await ev(() => ({ scope: document.querySelector('[data-k="capScope"]').value, title: document.querySelector('.rs-cap-btn.primary').title }));
  ok(ui2.scope === 'selected' && /1 selected clip \(IMG_7680\.mov\)/.test(ui2.title), `clip 2 selected: the choice switches to "Selected clips"; button: "${ui2.title}"`);
  await shot('autocap-03-selected');
  r = await run();
  const s1 = await snap();
  cl = await ev(() => window.clips());
  const outside = (cap, k) => cap.words.filter((w) => { const m = (w.t0 + w.t1) / 2; return k === 0 ? m < cl[1].t0 : m >= cl[1].t1; });
  const lineIds = (cap, a, b) => cap.lines.filter((L) => cap.words[L.wordIdx[0]].t0 >= a && cap.words[L.wordIdx[1]].t1 <= b).map((L) => L.id + JSON.stringify(L.ov || null));
  const same = (A, B) => A.length === B.length && A.every((w, i) => w.w === B[i].w && Math.abs(w.t0 - B[i].t0) < 0.06);
  w2 = s1.words.filter((w) => (w.t0 + w.t1) / 2 >= cl[1].t0 && (w.t0 + w.t1) / 2 < cl[1].t1);
  ok(!s1.words.some((w) => w.w === 'ZZZ') && has(w2, /serum|vitamin|skin/i), `clip 2 re-captioned (the edited "ZZZ" is replaced): "${w2.map((w) => w.w).join(' ')}"`);
  ok(same(outside(s1, 0), outside(s0, 0)) && same(outside(s1, 2), outside(s0, 2)), `clips 1 and 3 unchanged: ${outside(s1, 0).length} + ${outside(s1, 2).length} words, same text and times`);
  ok(JSON.stringify(lineIds(s1, 0, cl[1].t0)) === JSON.stringify(lineIds(s0, 0, cl[1].t0)) && s1.lines[0].ov?.pos?.y === 0.3 && JSON.stringify(s1.style) === JSON.stringify(s0.style),
    `clip 1's lines keep their ids and the moved line keeps its position (y ${s1.lines[0].ov?.pos?.y}); style kept`);
  ok(r.steps[0] === 'Auto captions · selected clips' && r.toasts.some((t) => /^ok: Captioned 1 clip \(IMG_7680\.mov\)/.test(t)), `one step; toast: ${r.toasts[0]}`);
  ok(validate(s1), 'lines cover every word, in order');

  // ── 4. clips 2 + 3 together ──
  await ev(() => {
    const p = ED.project, c = window.clips();
    for (const k of [1, 2]) { const i = p.captions.words.findIndex((w) => (w.t0 + w.t1) / 2 >= c[k].t0 && (w.t0 + w.t1) / 2 < c[k].t1); ED.history.apply(CC.editWord(i, k === 1 ? 'ZZZ' : 'QQQ')); }
    ED.select([c[1].id, c[2].id]);
  });
  const s2 = await snap();
  r = await run();
  const s3 = await snap();
  ok(!s3.words.some((w) => /^(ZZZ|QQQ)$/.test(w.w)) && same(outside(s3, 0), outside(s2, 0)) && r.toasts.some((t) => /^ok: Captioned 2 clips \(IMG_7680\.mov, IMG_7710\.mov\)/.test(t)),
    `clips 2 + 3 selected: both re-captioned, clip 1 unchanged; toast: ${r.toasts[0]}`);

  // ── 5. a 4th clip added later, selected, captioned ──
  await importClips(['IMG_7730']);
  await page.waitForFunction(() => window.clips().length === 4);
  cl = await ev(() => window.clips());
  const s4 = await snap();
  const row = await ev(() => document.querySelector('.rs-cap-new').hidden ? null : document.querySelector('.rs-cap-new').textContent);
  ok(/IMG_7730\.mov/.test(row || ''), `the 4th clip is named as having no captions: "${row}"`);
  await ev((id) => ED.select([id]), cl[3].id);
  r = await run();
  const s5 = await snap();
  const w4 = s5.words.filter((w) => (w.t0 + w.t1) / 2 >= cl[3].t0);
  item = await ev(() => window.capItem());
  ok(has(w4, /follow|beauty|tips/i) && item[1] >= w4[w4.length - 1].t1 - 1e-3 && same(s5.words.filter((w) => (w.t0 + w.t1) / 2 < cl[3].t0), s4.words), `the 4th clip: "${w4.map((w) => w.w).join(' ')}", earlier words unchanged, caption layer extended to ${item[1].toFixed(2)} s`);

  // ── 6. undo: one step back to exactly before ──
  const u = await ev((before) => { const back = []; while (ED.history.undoLabel && /^(Sync words|Auto captions)/.test(ED.history.undoLabel)) { back.push(ED.history.undoLabel); ED.history.undo(); if (/^Auto captions/.test(back[back.length - 1])) break; } return { back, same: JSON.stringify(ED.project.captions) === JSON.stringify(before), item: window.capItem() }; }, s4);
  ok(u.same && u.back[u.back.length - 1] === 'Auto captions · selected clips', `undo (${u.back.join(' → ')}): the captions are back exactly as before the run; the layer ends at ${u.item[1].toFixed(2)} s again`);
  await ev(() => { ED.history.redo(); ED.history.redo(); });

  // ── 7. clips with no sound are named ──
  await importClips(['IMG_7740', 'IMG_7750']);
  await page.waitForFunction(() => window.clips().length === 6);
  cl = await ev(() => window.clips());
  const s6 = await snap();
  await ev((ids) => ED.select(ids), [cl[4].id, cl[5].id]);
  r = await run();
  const s7 = await snap();
  const warn = r.toasts.find((t) => /^warn:/.test(t)) || '';
  ok(/IMG_7740\.mov: it has no sound/.test(warn) && /IMG_7750\.mov: no speech heard/.test(warn), `silent clips reported: "${warn}"`);
  ok(same(s7.words, s6.words), 'and no words were invented for them');
  await shot('autocap-04-silent');

  // ── 8. save -> reload ──
  const saved = await ev(async () => { await ED.save(); return JSON.stringify({ c: ED.project.captions, i: window.capItem() }); });
  await page.reload(); await page.waitForSelector('.tl-item'); await boot();
  const back = await ev(() => JSON.stringify({ c: ED.project.captions, i: window.capItem() }));
  ok(back === saved, `save → reload: captions (${JSON.parse(back).c.words.length} words, heard clips, lines) and the caption layer come back identical`);

  ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}`);
  ok(!offsite.length, `no request leaves 127.0.0.1${offsite.length ? ': ' + offsite.slice(0, 3).join(' ') : ''}`);
  await browser.close(); await H.stopEngine(e); fs.rmSync(STUDIO, { recursive: true, force: true });
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });

function validate(cap) {
  let prev = -1;
  for (const L of cap.lines) { if (L.wordIdx[0] !== prev + 1 || L.wordIdx[1] < L.wordIdx[0]) return false; prev = L.wordIdx[1]; }
  return prev === cap.words.length - 1 && cap.words.every((w, i) => !i || w.t0 >= cap.words[i - 1].t0 - 1e-6);
}
