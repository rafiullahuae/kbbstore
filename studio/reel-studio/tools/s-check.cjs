#!/usr/bin/env node
// KBB Studio — Lane S check (word-perfect caption sync + cutaways), headless Chromium against the Linux engine.
//
//   sync accuracy   word-onset error vs clips with KNOWN word times (tools/s-sync.cjs): EN (two flite voices, one with
//                   words run together), AR and UR (MMS voices, test-only), Whisper-only vs aligned; then IN THE EDITOR
//                   on an MP4 (AAC) made from the same audio — the whole path (container, decode, timeline clock)
//   sync follows    trim, speed change, split + re-order, silence removal, ripple delete, caption edits; undo/redo
//   cutaways        I/O marks -> edges within one frame; exported audio identical with and without (PCM hash); captions
//                   unchanged; preview frame = export frame; follows trim / re-order, goes with a ripple delete of its
//                   range; slide; auto suggestions from keywords; undo/redo
//   hygiene         no console errors, no request leaves 127.0.0.1; screenshots 1920×1080 + 1280×800 -> shots/s/
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/s-check.cjs [--skip-accuracy] [--reuse-accuracy]
// Needs: engine/kbbstudio-linux-amd64 (GOOS=linux go build in engine/), bash tools/s-fetch-models.sh, and the Whisper /
// MMS test models (tools/r2-fetch-test-models.sh + Xenova/mms-tts-hin) for the accuracy part.
'use strict';
const fs = require('fs'), path = require('path'), crypto = require('crypto');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 's'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 's');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const args = process.argv.slice(2);
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
let failures = 0; const results = [], numbers = {};
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const pcmHash = (file) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-vn', '-ac', '2', '-ar', '48000', '-f', 's16le', '-'], { maxBuffer: 1 << 30 }); return { hash: crypto.createHash('sha256').update(b).digest('hex').slice(0, 16), bytes: b.length }; };
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
const meanDiff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return s / a.length; };
const pct = (xs, p) => { const s = xs.slice().sort((a, b) => a - b); return s[Math.min(s.length - 1, Math.floor(p * (s.length - 1) + 0.5))]; };

(async () => {
  const T0 = Date.now();
  // ── 1. accuracy on known word times (dev harness page, no engine needed) ──
  if (!args.includes('--skip-accuracy')) {
    let acc;
    if (args.includes('--reuse-accuracy') && fs.existsSync(path.join(OUT, 'sync.json'))) acc = JSON.parse(fs.readFileSync(path.join(OUT, 'sync.json'), 'utf8'));
    else {
      const server = require('./r2-serve.cjs'), { chromium } = require('playwright');
      await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
      const b = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined });
      const pg = await b.newPage(); pg.setDefaultTimeout(0);
      await pg.goto(`http://127.0.0.1:${server.address().port}/app/sync/dev/index.html`); await pg.waitForFunction(() => window.S);
      acc = await require('./s-sync.cjs').measure(pg, {});
      await b.close(); server.close();
    }
    numbers.accuracy = acc;
    for (const [k, o] of Object.entries(acc)) {
      const tt = o.trueTranscript, readable = o.before.matched >= o.words * 0.6;
      const meets = (x) => x.startMedian <= 30 && x.startP95 <= 80 ? 'meets ≤30/≤80' : 'target ≤30/≤80 NOT met';
      if (readable) ok(o.after.startMedian < o.before.startMedian, `${k}: word starts on Whisper's words (${o.after.matched}/${o.words} matched) — Whisper only median ${o.before.startMedian} / p95 ${o.before.startP95} ms → synced median ${o.after.startMedian} / p95 ${o.after.startP95} ms (${meets(o.after)}); on the true transcript ${tt.startMedian}/${tt.startP95} ms; model alone (no snapping) ${o.ctcOnly?.startMedian}/${o.ctcOnly?.startP95} ms; ${o.alignMs} ms for ${o.dur} s`);
      // a synthetic voice Whisper cannot read (per-word MMS): only the aligner on the true transcript is measurable
      else ok(tt.startMedian <= 30, `${k}: Whisper could not read this synthetic voice (${o.before.matched}/${o.words} words), so before/after on Whisper's words is not measurable; synced on the true transcript median ${tt.startMedian} ms / p95 ${tt.startP95} ms (${meets(tt)}); ${o.alignMs} ms for ${o.dur} s`);
    }
  }

  // ── 2. the editor ──
  const gtSet = JSON.parse(fs.readFileSync(path.join(OUT, 'sync-en-flite.json'), 'utf8'));
  const talk = path.join(OUT, 'talk.mp4'), pic = path.join(OUT, 'pic.jpg'), broll = path.join(OUT, 'broll.mp4');
  const dur = execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path.join(OUT, 'sync-en-flite.wav')]).toString().trim();
  if (!fs.existsSync(talk)) ff('-f', 'lavfi', '-i', `testsrc2=size=1080x1920:rate=30:duration=${dur}`, '-i', path.join(OUT, 'sync-en-flite.wav'), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26', '-pix_fmt', 'yuv420p', '-g', '30', '-c:a', 'aac', '-b:a', '160k', '-shortest', '-movflags', '+faststart', talk);
  if (!fs.existsSync(pic)) ff('-f', 'lavfi', '-i', 'mandelbrot=size=1080x1350:rate=1', '-frames:v', '1', pic);
  if (!fs.existsSync(broll)) ff('-f', 'lavfi', '-i', 'smptehdbars=size=1080x1920:rate=30:duration=6', '-f', 'lavfi', '-i', 'sine=frequency=550:duration=6:sample_rate=48000', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '24', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', broll);
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings', 'adstudio']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  const AM = path.join(ROOT, 'models', 'NewComer00');
  if (fs.existsSync(AM) && !fs.existsSync(path.join(STUDIO, 'models', 'NewComer00'))) execFileSync('cp', ['-al', AM, path.join(STUDIO, 'models', 'NewComer00')]);
  fs.mkdirSync(path.join(STUDIO, 'library', 's-test'), { recursive: true });
  for (const f of [pic, broll]) fs.copyFileSync(f, path.join(STUDIO, 'library', 's-test', path.basename(f)));
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  page.setDefaultTimeout(180000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('worker', (w) => w.on('console', (m) => { if (m.type() === 'error') errors.push('[worker] ' + m.text()); }));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = async (name) => {
    for (const [w, h] of [[1920, 1080], [1280, 800]]) { await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(600); await page.screenshot({ path: path.join(SHOTS, `${name}-${w}.png`) }); }
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(300);
  };
  const settle = async () => { await page.waitForTimeout(300); await ev(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))); };
  const seekDraw = async (t) => { await ev((t) => window.kbbEditor.seek(t), t); await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }); await settle(); };
  const exportTo = (name) => ev(async (name) => {
    const E = await import('/app/core/export.js'), ed = window.kbbEditor, p = ed.project;
    const r = await E.exportMp4(p, ed.media, { quality: 'high' });
    const file = `workspaces/${p.workspace}/exports/${name}`; await ed.engine.writeFile(file, r.blob);
    return { file, note: r.note, frames: r.frames };
  }, name);

  await page.setInputFiles('.home-drop input[type=file]', talk);
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await ev(async () => {
    window.SY = await import('/app/sync/index.js'); window.CW = await import('/app/cutaways/index.js');
    window.CA = await import('/app/captions/auto.js'); window.CC = await import('/app/captions/commands.js'); window.CM = await import('/app/captions/model.js');
    window.AC = await import('/app/auto/commands.js'); window.CTX = window.kbbEditor.context();
    // independent of app/sync/follow.js: where source time s of the talk media is on the timeline now
    window.tl = (s) => { for (const tr of window.kbbEditor.project.tracks) if (tr.kind === 'video') for (const c of tr.items) if (c.type === 'clip' && s >= c.in - 1e-6 && s < c.out - 1e-6) return c.t0 + (s - c.in) / (c.speed || 1); return null; };
    window.words = () => window.kbbEditor.project.captions.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1 }));
  });
  const tabs = await ev(() => [...document.querySelectorAll('.side-tab')].map((b) => b.textContent));
  ok(tabs.includes('⏱ Sync') && tabs.includes('🎞 Cutaways'), `right column has the Sync and Cutaways tabs (${tabs.join(' · ')})`);

  // captions as Whisper would give them: the known words with Whisper's own timing errors from the accuracy run
  const whisperLike = gtSet.gt.map((g, i) => { const wh = gtSet.whisper.find((w) => w.w.replace(/\W/g, '').toLowerCase() === g.w.replace(/\W/g, '').toLowerCase() && Math.abs(w.t0 - g.t0) < 1); return { w: g.w, t0: +(wh ? wh.t0 : g.t0 + 0.2).toFixed(3), t1: +(wh ? wh.t1 : g.t1 + 0.2).toFixed(3), conf: 1 }; });
  for (let i = 1; i < whisperLike.length; i++) if (whisperLike[i].t0 < whisperLike[i - 1].t0) whisperLike[i].t0 = whisperLike[i - 1].t0;
  // ── sync in the editor (MP4/AAC decode → timeline clock → aligner → snapping) ──
  let t = Date.now();
  const inEd = await ev(async ({ ws, gt }) => {
    const ed = window.kbbEditor, p = ed.project, style = 'word-box';
    const cap = { style, lang: 'en', words: ws, lines: CM.buildLines(ws, style, p.canvas) };
    ed.history.apply(CA.captionsWithItem(p, cap, ed.ops));
    // the "Auto captions" step triggers the automatic sync: wait for its undo step
    const t0 = performance.now();
    while (ed.history.undoLabel !== 'Sync words to the voice' && performance.now() - t0 < 240000) await new Promise((r) => setTimeout(r, 250));
    const got = window.words(), err = gt.map((g, i) => Math.abs(got[i].t0 - g.t0) * 1000), signed = gt.map((g, i) => (got[i].t0 - g.t0) * 1000);
    const before = gt.map((g, i) => Math.abs(ws[i].t0 - g.t0) * 1000);
    return { err, before, signed, label: ed.history.undoLabel, last: p.meta.sync?.last, anchored: p.captions.words.filter((w) => w.src).length, n: p.captions.words.length };
  }, { ws: whisperLike, gt: gtSet.gt });
  numbers.inEditor = { startMedian: +pct(inEd.err, 0.5).toFixed(1), startP95: +pct(inEd.err, 0.95).toFixed(1), bias: +pct(inEd.signed, 0.5).toFixed(1), whisperMedian: +pct(inEd.before, 0.5).toFixed(1), whisperP95: +pct(inEd.before, 0.95).toFixed(1), stats: inEd.last, secs: (Date.now() - t) / 1000 };
  ok(inEd.label === 'Sync words to the voice' && inEd.anchored === inEd.n, `Auto captions → sync runs by itself as one undo step "${inEd.label}" (${inEd.last?.aligned} placed by the model, ${inEd.last?.snapped} snapped), every word anchored to its media time (${inEd.anchored}/${inEd.n}) in ${numbers.inEditor.secs.toFixed(1)} s`);
  ok(numbers.inEditor.startMedian <= 30 && numbers.inEditor.startP95 <= 80 && Math.abs(numbers.inEditor.bias) <= 10, `in the editor, from an MP4 with AAC audio (container offset + encoder priming in the path): word starts median ${numbers.inEditor.startMedian} ms, p95 ${numbers.inEditor.startP95} ms, median signed ${numbers.inEditor.bias} ms (Whisper-like input ${numbers.inEditor.whisperMedian}/${numbers.inEditor.whisperP95} ms)`);
  await ev(() => window.kbbEditor.showSide('mod:sync')); await seekDraw(4.2); await page.waitForTimeout(800);
  await shot('s-01-sync-tab');
  await ev(() => document.querySelector('.sy-wave')?.scrollIntoView({ block: 'center' })); await page.waitForTimeout(400);
  await shot('s-01b-sync-word-timing');

  // ── sync follows every edit ──
  const follow = await ev(() => {
    const ed = window.kbbEditor, p = ed.project, H = ed.history, out = {};
    const clip = () => p.tracks.find((t) => t.kind === 'video').items[0];
    const synced = window.words(), src = synced.map((w) => w.t0);   // clip at t0 0, in 0, speed 1: timeline = source
    const check = (name) => {
      const ws = window.words(), errs = [];
      let shown = 0;
      // every word still on the timeline is where its source time maps to now (±1 ms), in order, lines valid
      const exp = synced.map((w, i) => ({ w: w.w, t: window.tl(src[i]) })).filter((x) => x.t != null).sort((a, b) => a.t - b.t);
      for (let i = 0; i < Math.min(exp.length, ws.length); i++) errs.push(Math.abs(ws[i].t0 - exp[i].t) * 1000), shown += ws[i].w === exp[i].w;
      const v = CM.validate(p.captions);
      out[name] = { words: ws.length, expected: exp.length, maxErr: +Math.max(0, ...errs).toFixed(2), sameWords: shown, valid: v.length === 0, problems: v.slice(0, 3), offcut: p.captions.offcut?.length || 0 };
    };
    const base = JSON.stringify(p.captions);
    // trim the start by 2 s and close the gap (in 2, t0 0)
    let c = clip(); ed.apply(ed.ops.setItem(c.id, { in: 2 }, 'Trim'));  check('trimStart');
    ed.apply(ed.ops.setItem(c.id, { t0: 0.5 }, 'Move')); check('move');
    ed.apply(ed.ops.setItem(c.id, { speed: 1.25 }, 'Speed')); check('speed');
    H.undo(); H.undo(); H.undo();
    out.undoTrim = JSON.stringify(p.captions) === base;
    // split at 15 s and swap the two halves (re-order)
    ed.seek(15); ed.select([]); ed.split();
    const [a, b] = p.tracks.find((t) => t.kind === 'video').items.slice().sort((x, y) => x.t0 - y.t0);
    const da = (a.out - a.in), db = (b.out - b.in);
    ed.apply(ed.ops.moveItems([{ id: b.id, t0: 0 }, { id: a.id, t0: db }], 'Re-order')); check('reorder');
    H.undo(); H.undo(); out.undoReorder = JSON.stringify(p.captions) === base;
    // ripple delete the middle: split at 10 and 12, delete 10..12 with ripple
    ed.seek(10); ed.select([]); ed.split(); ed.seek(12); ed.select([]); ed.split();
    const mid = p.tracks.find((t) => t.kind === 'video').items.find((x) => Math.abs(x.t0 - 10) < 1e-3);
    ed.select([mid.id]); ed.deleteSelection(true); check('rippleDelete');
    out.rippleOffcut = p.captions.offcut?.length || 0;
    for (let i = 0; i < 3; i++) H.undo(); out.undoRipple = JSON.stringify(p.captions) === base;
    // caption edits: retime one word, edit another, then trim: both follow their (new) anchors
    const i = 20, w = p.captions.words[i];
    ed.apply(CC.retimeWord(i, w.t0 + 0.05, w.t1 + 0.05)); const moved = p.captions.words[i].t0;
    ed.apply(CC.editWord(i + 2, 'EDITED'));
    ed.apply(ed.ops.setItem(clip().id, { in: 1, t0: 0 }, 'Trim'));
    out.editFollow = { expected: +(moved - 1).toFixed(3), got: p.captions.words.find((x) => Math.abs(x.t0 - (moved - 1)) < 0.002)?.w, edited: p.captions.words.some((x) => x.w === 'EDITED') };
    H.undo(); H.undo(); H.undo(); out.undoEdits = JSON.stringify(p.captions) === base;
    for (let k = 0; k < 3; k++) H.redo();
    out.redoEdits = p.captions.words.some((x) => x.w === 'EDITED') && clip().in === 1;
    H.undo(); H.undo(); H.undo();
    return out;
  });
  numbers.follow = follow;
  for (const k of ['trimStart', 'move', 'speed', 'reorder', 'rippleDelete']) {
    const f = follow[k];
    ok(f.maxErr <= 1 && f.valid && f.sameWords === Math.min(f.words, f.expected) && f.words === f.expected, `captions follow ${k}: ${f.words} words on the timeline (expected ${f.expected}), max error vs the clip's media time ${f.maxErr} ms, lines valid ${f.valid}${f.offcut ? `, ${f.offcut} waiting off the timeline` : ''}`);
  }
  ok(follow.undoTrim && follow.undoReorder && follow.undoRipple && follow.undoEdits && follow.redoEdits, `undo restores the captions exactly after trim/move/speed, re-order, ripple delete and caption edits; redo re-applies (${JSON.stringify({ trim: follow.undoTrim, reorder: follow.undoReorder, ripple: follow.undoRipple, edits: follow.undoEdits, redo: follow.redoEdits })})`);
  ok(follow.editFollow.got && follow.editFollow.edited, `a retimed word keeps its new timing through a trim (at ${follow.editFollow.expected} s: "${follow.editFollow.got}"), an edited word keeps its text`);

  // silence removal (app/auto's own command): captions keep their media time
  const sil = await ev(() => {
    const ed = window.kbbEditor, p = ed.project, m = p.media[0];
    const synced = window.words(), ws = synced;
    const cuts = []; for (let i = 1; i < ws.length; i++) if (ws[i].t0 - ws[i - 1].t1 > 0.3) cuts.push({ t0: ws[i - 1].t1 + 0.08, t1: ws[i].t0 - 0.08 });
    const cmd = AC.removeCutsCommand(p, m.id, cuts); ed.apply(cmd);
    const now = window.words(), errs = synced.map((w, i) => Math.abs(now[i].t0 - window.tl(w.t0)) * 1000);
    const r = { cuts: cuts.length, removed: +cmd.removed.toFixed(2), maxErr: +Math.max(...errs).toFixed(2), n: now.length };
    ed.history.undo(); r.undo = JSON.stringify(window.words()) === JSON.stringify(synced);
    return r;
  });
  ok(sil.maxErr <= 1 && sil.n === gtSet.gt.length && sil.undo, `remove silences (${sil.cuts} pauses, −${sil.removed} s): every word still on its own media time (max ${sil.maxErr} ms); undo restores`);

  // nudge + lead apply in the caption layer (preview and export share it)
  const nudge = await ev(async () => {
    const ed = window.kbbEditor, p = ed.project, R = await import('/app/core/render.js');
    const c = document.createElement('canvas'); c.width = 270; c.height = 480; const x = c.getContext('2d', { willReadFrequently: true });
    const it = p.tracks.find((t) => t.kind === 'caption').items[0], w = p.captions.words[10];
    const at = (t) => { x.setTransform(1, 0, 0, 1, 0, 0); x.clearRect(0, 0, 270, 480); x.setTransform(0.25, 0, 0, 0.25, 0, 0); R.drawItem(x, it, t, p, { w: 1080, h: 1920, scale: 0.25, preview: false, source: () => null, project: p, track: p.tracks.find((t) => t.kind === 'caption') }); return x.getImageData(0, 0, 270, 480).data.join(','); };
    const before = at(w.t0 - 0.06);
    ed.apply(ed.ops.setProject({ meta: { ...p.meta, sync: { ...p.meta.sync, leadMs: 100 } } }, 'Caption lead'));
    const early = at(w.t0 - 0.06), onWord = (() => { const m = p.meta.sync; p.meta.sync = { ...m, leadMs: 0 }; const v = at(w.t0 + 0.04); p.meta.sync = m; return v; })();
    ed.history.undo();
    const sum = (q) => q.split(',').reduce((a, v) => a + +v, 0);
    return { changed: before !== early, matches: early === onWord, sums: [sum(before), sum(early), sum(onWord)], word: w.w, t0: w.t0, lead: p.meta.sync };
  });
  console.log('lead probe', JSON.stringify(nudge));
  ok(nudge.changed && nudge.matches, `"Show words early" 100 ms: 60 ms before a word the frame already shows it (identical to the frame 40 ms into the word without the lead)`);

  // ── cutaways ──
  await ev(() => window.kbbEditor.showSide('mod:cutaways'));
  const fps = 30, IN = 3.0, OUT_ = 5.5;
  await seekDraw(IN + 0.013); await page.keyboard.press('i');
  await seekDraw(OUT_ - 0.011); await page.keyboard.press('o');
  const mk = await ev(() => window.CW.marks());
  ok(Math.abs(mk.in - IN) < 1 / fps / 2 && Math.abs(mk.out - OUT_) < 1 / fps / 2, `I / O keys mark ${mk.in.toFixed(4)} → ${mk.out.toFixed(4)} s (snapped to the 30 fps frame grid)`);
  await shot('s-02-cutaways-marked');
  const capBefore = await ev(() => JSON.stringify(window.kbbEditor.project.captions));
  // the export WITHOUT any cutaway (reference sound)
  const ex0 = await exportTo('s-none.mp4');
  // image cutaway over the marks (no transitions for the edge test)
  await ev(async () => { const m = await window.kbbEditor.media.importPath('library/s-test/pic.jpg', 'pic.jpg'); window.kbbEditor.apply(window.kbbEditor.ops.addMedia(m)); });
  const cw1 = await ev(async () => { const m = window.kbbEditor.project.media.find((x) => x.name === 'pic.jpg'); const it = await CW.addCutaway(CTX, { media: m, style: { tin: 'none', tout: 'none', fit: 'fill', kb: 0 } }); return { id: it.id, t0: it.t0, end: it.t0 + (it.out - it.in) / (it.speed || 1), link: it.link, muted: it.muted }; });
  ok(Math.abs(cw1.t0 - IN) <= 1 / fps && Math.abs(cw1.end - OUT_) <= 1 / fps && cw1.link, `image cutaway from the marks: ${cw1.t0.toFixed(4)} → ${cw1.end.toFixed(4)} s (within one frame of ${IN} / ${OUT_}), linked to the talking clip's media time ${cw1.link.s0}–${cw1.link.s1} s`);
  // a video cutaway (its own sound muted by default) and a slide
  const cw2 = await ev(async () => {
    const m = await window.kbbEditor.media.importPath('library/s-test/broll.mp4', 'broll.mp4'); window.kbbEditor.apply(window.kbbEditor.ops.addMedia(m));
    const v = await CW.addCutaway(CTX, { t0: 8, t1: 10.5, media: m, style: { fit: 'blur' } });
    const s = await CW.addCutaway(CTX, { t0: 13, t1: 15.5, slide: { title: 'Vitamin C serum', bullets: ['Brighter in one week', 'Light, no white cast'], price: 'AED 49', offer: 'Free delivery today', icon: '✨', theme: 'offer', anim: 'rise' }, style: { fit: 'fill' } });
    const pip = await CW.addCutaway(CTX, { t0: 18, t1: 20, media: window.kbbEditor.project.media.find((x) => x.name === 'pic.jpg'), style: { fit: 'pip', tin: 'slide', tout: 'zoom' } });
    return { v: { muted: v.muted, volume: v.volume }, s: !!s.style.slide, pip: pip.id };
  });
  ok(cw2.v.muted && cw2.v.volume === 0 && cw2.s, `video cutaway added with its own sound muted (muted ${cw2.v.muted}, volume ${cw2.v.volume}); slide cutaway added`);
  const capAfter = await ev(() => JSON.stringify(window.kbbEditor.project.captions));
  ok(capAfter === capBefore, 'captions are byte-identical after adding four cutaways (times, words, lines)');
  // edges in the preview path: one frame before the mark shows the speaker, at the mark the picture
  const edges = await ev(async ({ IN, OUT_ }) => {
    const ed = window.kbbEditor, p = ed.project, R = await import('/app/core/render.js');
    const c = document.createElement('canvas'); c.width = 108; c.height = 192; const x = c.getContext('2d', { willReadFrequently: true });
    const pic = ed.media.element(p.media.find((m) => m.name === 'pic.jpg').id); await pic.decode();
    const src = (it) => (it.type === 'cutaway' ? pic : null);   // the talking clip draws nothing: black
    const skip = new Set(p.tracks.filter((t) => t.kind === 'caption').flatMap((t) => t.items.map((i) => i.id)));   // captions off for the luminance test
    const lum = (t) => { R.renderFrame(x, p, t, { scale: 0.1, source: src, skip }); const d = x.getImageData(0, 0, 108, 192).data; let s = 0; for (let i = 0; i < d.length; i += 4) s += d[i] + d[i + 1] + d[i + 2]; return s / (d.length / 4); };
    const f = 1 / 30;
    return { before: lum(IN - f + 1e-4), at: lum(IN + 1e-4), last: lum(OUT_ - f + 1e-4), after: lum(OUT_ + 1e-4) };
  }, { IN, OUT_ });
  ok(edges.at > 10 * edges.before && edges.last > 10 * edges.after && Math.abs(edges.at - edges.last) < 5, `cutaway edges on the frame grid: frame before the in-mark ${edges.before.toFixed(1)} (speaker), first frame ${edges.at.toFixed(1)} (picture), last frame ${edges.last.toFixed(1)}, frame at the out-mark ${edges.after.toFixed(1)} (speaker)`);
  await seekDraw(14.4);
  await ev(() => window.kbbEditor.select([window.kbbEditor.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'cutaway' && i.style.slide).id]));
  await page.waitForTimeout(400);
  await shot('s-03-slide-designer');
  await seekDraw(19); await ev((id) => window.kbbEditor.select([id]), cw2.pip); await page.waitForTimeout(300);
  await shot('s-04-pip-bubble');

  // export WITH the cutaways: same sound, the picture where it should be
  t = Date.now();
  const ex1 = await exportTo('s-cutaways.mp4');
  const f0 = path.join(STUDIO, ex0.file), f1 = path.join(STUDIO, ex1.file);
  const h0 = pcmHash(f0), h1 = pcmHash(f1);
  const mix = await ev(async () => {
    const E = await import('/app/core/export.js'), ed = window.kbbEditor, p = ed.project, P = await import('/app/core/project.js');
    const T = P.projectDuration(p), a = await E.mixAudio(p, ed.media, T);
    const q = structuredClone(p); for (const tr of q.tracks) tr.items = tr.items.filter((i) => i.type !== 'cutaway');
    const b = await E.mixAudio(q, ed.media, T);
    let d = 0; for (let c = 0; c < 2; c++) { const x = a.getChannelData(c), y = b.getChannelData(c); for (let i = 0; i < x.length; i++) d = Math.max(d, Math.abs(x[i] - y[i])); }
    return { maxDiff: d, len: a.length };
  });
  numbers.audio = { without: h0, with: h1, mixMaxDiff: mix.maxDiff };
  ok(h0.hash === h1.hash && mix.maxDiff === 0, `exported sound identical with and without cutaways: decoded PCM sha256 ${h0.hash} = ${h1.hash} (${h0.bytes} bytes); in-page mix max |difference| ${mix.maxDiff} over ${mix.len} samples`);
  // export frames at the edges and in the middle
  const W = 108, Hh = 192;
  const exPic = frameRGB(f1, IN + 0.5, W, Hh), exSp = frameRGB(f0, IN + 0.5, W, Hh);
  const exB = frameRGB(f1, IN - 1 / fps + 0.005, W, Hh), exA = frameRGB(f1, IN + 0.005, W, Hh), exL = frameRGB(f1, OUT_ - 1 / fps + 0.005, W, Hh), exO = frameRGB(f1, OUT_ + 0.005, W, Hh);
  const sp0 = frameRGB(f0, IN - 1 / fps + 0.005, W, Hh), sp1 = frameRGB(f0, OUT_ + 0.005, W, Hh);
  const dd = { before: meanDiff(exB, sp0), first: meanDiff(exA, exPic), last: meanDiff(exL, exPic), after: meanDiff(exO, sp1), picVsSpeaker: meanDiff(exPic, exSp) };
  numbers.exportEdges = dd;
  ok(dd.before < 3 && dd.after < 3 && dd.first < 6 && dd.last < 6 && dd.picVsSpeaker > 30, `exported edges within one frame: frame before in-mark = speaker (Δ ${dd.before.toFixed(1)}), first/last cutaway frames = picture (Δ ${dd.first.toFixed(1)} / ${dd.last.toFixed(1)}), frame at out-mark = speaker (Δ ${dd.after.toFixed(1)}); picture vs speaker Δ ${dd.picVsSpeaker.toFixed(1)}`);
  // preview = export (slide at 14.4 s and the bubble at 19 s)
  for (const [tt, name] of [[14.4, 'slide'], [19, 'bubble'], [9.2, 'video, fit + blur']]) {
    await seekDraw(tt); await page.waitForTimeout(500); await seekDraw(tt);
    const pv = await ev(() => { const c = window.kbbEditor.preview.canvas; const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; const rgb = new Uint8Array(c.width * c.height * 3); for (let i = 0, j = 0; i < d.length; i += 4) { rgb[j++] = d[i]; rgb[j++] = d[i + 1]; rgb[j++] = d[i + 2]; } return { w: c.width, h: c.height, data: Array.from(rgb) }; });
    const exF = frameRGB(f1, tt, pv.w, pv.h), pd = meanDiff(Uint8Array.from(pv.data), exF);
    (numbers.previewVsExport ||= {})[name] = +pd.toFixed(2);
    ok(pd < 6, `preview frame = export frame at ${tt} s (${name}, ${pv.w}×${pv.h}): mean difference ${pd.toFixed(2)}/255 (< 6)`);
  }
  ok(true, `export with 4 cutaways: ${ex1.note} (${((Date.now() - t) / 1000).toFixed(1)} s)`);

  // cutaways follow the clip: trim the start by 1 s, re-order, ripple delete the range; undo/redo
  const cf = await ev(({ id }) => {
    const ed = window.kbbEditor, p = ed.project, H = ed.history, find = () => p.tracks.flatMap((t) => t.items).find((i) => i.id === id);
    const clip = () => p.tracks.find((t) => t.kind === 'video').items[0];
    const base = JSON.stringify(p.tracks), out = {};
    const t0 = find().t0;
    ed.apply(ed.ops.setItem(clip().id, { in: 1 }, 'Trim')); out.trim = +(find().t0 - t0).toFixed(4);
    H.undo();
    // split at 2 and 6, swap the outer pieces so the cutaway's range moves to the start
    ed.seek(2); ed.select([]); ed.split(); ed.seek(6); ed.select([]); ed.split();
    const v = p.tracks.find((t) => t.kind === 'video').items, a = v.find((x) => Math.abs(x.t0) < 1e-3), b = v.find((x) => Math.abs(x.t0 - 2) < 1e-3);
    ed.apply(ed.ops.moveItems([{ id: b.id, t0: 0 }, { id: a.id, t0: 4 }], 'Re-order'));
    out.reorder = { t0: find().t0, expected: 1 };   // source 3.0 sits 1 s into piece b, now at 0
    H.undo(); H.undo(); H.undo();
    out.undoBack = JSON.stringify(p.tracks) === base;
    // ripple delete exactly the marked range
    ed.seek(3); ed.select([]); ed.split(); ed.seek(5.5); ed.select([]); ed.split();
    const mid = p.tracks.find((t) => t.kind === 'video').items.find((x) => Math.abs(x.t0 - 3) < 1e-3);
    ed.select([mid.id]); ed.deleteSelection(true);
    out.rippleGone = !find();
    out.laterFollow = p.tracks.flatMap((t) => t.items).filter((i) => i.type === 'cutaway').map((i) => +i.t0.toFixed(3));
    H.undo(); out.rippleUndo = !!find() && Math.abs(find().t0 - 3) < 1e-3;
    H.redo(); out.rippleRedo = !find();
    H.undo(); H.undo(); H.undo();
    out.allBack = JSON.stringify(p.tracks) === base;
    return out;
  }, { id: cw1.id });
  numbers.cutawayFollow = cf;
  ok(Math.abs(cf.trim + 1) < 1e-3, `cutaway follows a 1 s trim of the clip under it (moved ${cf.trim} s)`);
  ok(Math.abs(cf.reorder.t0 - cf.reorder.expected) < 1e-3, `cutaway follows a re-order of the clip pieces (now at ${cf.reorder.t0} s, expected ${cf.reorder.expected})`);
  ok(cf.rippleGone && cf.rippleUndo && cf.rippleRedo && cf.allBack && cf.undoBack, `ripple-deleting the marked range removes its cutaway too (later cutaways ripple with their clips: ${cf.laterFollow.join(', ')} s); undo brings both back, redo removes again; everything restores exactly`);

  // undo / redo of adding a cutaway
  const ur = await ev(() => { const ed = window.kbbEditor, n = () => ed.project.tracks.flatMap((t) => t.items).filter((i) => i.type === 'cutaway').length; const a = n(); ed.history.undo(); const b = n(); ed.history.redo(); return [a, b, n()]; });
  ok(ur[0] === ur[1] + 1 && ur[2] === ur[0], `adding a cutaway is one undo step (${ur.join(' → ')})`);

  // auto cutaways from keywords
  const auto = await ev(async () => {
    const ed = window.kbbEditor, p = ed.project;
    const words = 'I love the Anua heartleaf mist mini it costs 49 AED and every nurse and plumber in Germany can use it'.split(' ');
    const ws = words.map((w, i) => ({ w, t0: 22 + i * 0.35, t1: 22 + i * 0.35 + 0.3, conf: 1 }));
    const kbb = await CW.suggestCutaways({ ...CTX, project: { ...p, captions: { ...p.captions, words: ws } } }, { tag: false, assets: [] });
    const pc = await CW.suggestCutaways({ ...CTX, workspace: { id: 'planet-careers', industry: 'Immigration & study-visa consultancy', colors: {} }, project: { ...p, captions: { ...p.captions, words: ws } } }, { tag: false, assets: [] });
    const before = p.tracks.flatMap((t) => t.items).filter((i) => i.type === 'cutaway').length;
    const n = await CW.acceptSuggestions(CTX, kbb);
    const after = p.tracks.flatMap((t) => t.items).filter((i) => i.type === 'cutaway').length;
    ed.history.undo(); const undone = p.tracks.flatMap((t) => t.items).filter((i) => i.type === 'cutaway').length;
    ed.history.redo();
    return { kbb: kbb.map((s) => `${s.kind}:${s.label}@${s.t0}`), pc: pc.map((s) => `${s.kind}:${s.label}@${s.t0}`), n, before, after, undone };
  });
  numbers.auto = auto;
  const kinds = new Set([...auto.kbb, ...auto.pc].map((s) => s.split(':')[0]));
  ok(['product', 'price', 'occupation', 'country'].every((k) => kinds.has(k)) && auto.after === auto.before + auto.n && auto.undone === auto.before, `Auto cutaways from spoken keywords: KBB → ${auto.kbb.join(', ')}; Planet Careers → ${auto.pc.join(', ')}; ${auto.n} accepted as one undo step`);
  // the proposals UI
  await seekDraw(22.5);
  await page.click('button:has-text("Suggest from what I say")'); await page.waitForTimeout(1500);
  await shot('s-05-auto-cutaways');
  const ex2 = await exportTo('s-final.mp4');
  ok(fs.existsSync(path.join(STUDIO, ex2.file)), `final export with slides and auto cutaways: ${ex2.note}`);
  ff('-ss', '14.4', '-i', path.join(STUDIO, ex2.file), '-frames:v', '1', '-vf', 'scale=540:960', path.join(SHOTS, 's-06-export-slide-frame.png'));
  ff('-ss', '19', '-i', path.join(STUDIO, ex2.file), '-frames:v', '1', '-vf', 'scale=540:960', path.join(SHOTS, 's-07-export-bubble-frame.png'));

  ok(errors.length === 0, `no console errors (${errors.length}${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''})`);
  ok(outside.length === 0, `no request left 127.0.0.1 (${outside.length})`);
  await browser.close(); await H.stopEngine(e);
  numbers.secs = (Date.now() - T0) / 1000;
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify({ results, numbers }, null, 1));
  console.log(`\n${failures ? failures + ' FAILED' : 'ALL PASS'} · ${results.length} checks · ${numbers.secs.toFixed(0)} s · tools/out/s/results.json`);
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
