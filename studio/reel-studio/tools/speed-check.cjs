#!/usr/bin/env node
// KBB Studio — speed check (constant speed, ramps, smooth slow motion, reverse, freeze), headless Chrome against the
// Linux engine. Makes its own clips into tools/out/speed/ (never committed): 1080p30, 4K30 and a 1080p 120 fps "iPhone
// slo-mo" clip, each with a binary frame-number bar along the top (10 cells, cell k = bit k of the frame index) and a
// 440 Hz tone, so the frame shown and the pitch heard can be READ back, in the page and from the exported MP4.
//
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/speed-check.cjs            everything
//   ... tools/speed-check.cjs --perf                                                 preview frame times only
//
// Checks: constant speeds and durations · ramp presets and the time mapping (frame-exact) · export = preview at
// sampled frames · audio duration and pitch kept (dominant frequency) · reverse · freeze · captions remapping · undo ·
// save/reload (and an old project unchanged) · preview frame time and dropped frames at 0.25×, 0.5×, 2×, 3× and on a
// ramp (1080p and 4K). Logs and numbers go to tools/out/speed/.
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'speed'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'speed');
fs.mkdirSync(OUT, { recursive: true });
const ARGS = new Set(process.argv.slice(2));
let failures = 0; const results = [], numbers = {};
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
const SR = 48000;

// ── clips ──
function clip(name, w, h, fps, dur, gop) {
  const f = path.join(OUT, name + '.mp4');
  if (fs.existsSync(f)) return f;
  const sh = Math.round(h / 12);
  ff('-f', 'lavfi', '-i', `testsrc2=size=${w}x${h}:rate=${fps}:duration=${dur}`, '-f', 'lavfi', '-i', `nullsrc=size=${w}x${sh}:rate=${fps}:duration=${dur}`,
    '-f', 'lavfi', '-i', `sine=frequency=440:duration=${dur}:sample_rate=48000`,
    '-filter_complex', "[1:v]geq=lum='255*mod(floor(N/pow(2,floor(X*10/W))),2)':cb=128:cr=128[bar];[0:v][bar]overlay=0:0:shortest=1,format=yuv420p[v]",
    '-map', '[v]', '-map', '2:a', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '30', '-g', String(gop), '-bf', '2', '-c:a', 'aac', '-b:a', '96k', '-shortest', '-movflags', '+faststart', f);
  return f;
}
const ffprobe = (f) => JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-count_frames', '-show_entries', 'format=duration:stream=codec_type,nb_read_frames,r_frame_rate,duration', '-of', 'json', f]).toString());
const pcm = (file, sr = SR) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '1', '-ar', String(sr), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(b.buffer.slice(b.byteOffset, b.byteOffset + b.length)); };
/** dominant frequency of x[a..b) by a Goertzel sweep 100..2000 Hz (1 Hz steps around the coarse peak) */
function dominant(x, a = 0, b = x.length, sr = SR) {
  const n = Math.min(b - a, sr), seg = x.subarray(a, a + n);
  const power = (f) => { const k = 2 * Math.cos(2 * Math.PI * f / sr); let s1 = 0, s2 = 0; for (let i = 0; i < seg.length; i++) { const s = seg[i] + k * s1 - s2; s2 = s1; s1 = s; } return s1 * s1 + s2 * s2 - k * s1 * s2; };
  let best = 100, bp = -1;
  for (let f = 100; f <= 2000; f += 10) { const p = power(f); if (p > bp) { bp = p; best = f; } }
  for (let f = best - 10; f <= best + 10; f += 0.5) { const p = power(f); if (p > bp) { bp = p; best = f; } }
  return best;
}
const rms = (x, a, b) => { let s = 0; for (let i = a; i < b; i++) s += x[i] * x[i]; return Math.sqrt(s / Math.max(1, b - a)); };
/** frame index from the bar in an RGB frame (w×h, bar on the top 1/12) */
function readBar(rgb, w, h) {
  const y = Math.floor(h / 24); let n = 0;
  for (let k = 0; k < 10; k++) { const x = Math.floor((k + 0.5) * w / 10), i = (y * w + x) * 3; if ((rgb[i] + rgb[i + 1] + rgb[i + 2]) / 3 > 128) n |= 1 << k; }
  return n;
}
function frameRGB(file, idx, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-vf', `select=eq(n\\,${idx}),scale=${w}:${h}:flags=area`, '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
const meanDiff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return s / a.length; };
const stats = (a) => { if (!a.length) return { mean: 0, p95: 0, max: 0 }; const s = [...a].sort((x, y) => x - y); return { mean: +(a.reduce((x, y) => x + y, 0) / a.length).toFixed(2), p95: +s[Math.floor(s.length * 0.95)].toFixed(2), max: +s[s.length - 1].toFixed(2) }; };

(async () => {
  const C = { s1080: clip('s1080', 1920, 1080, 30, 12, 30), s4k: clip('s4k', 3840, 2160, 30, 6, 30), s120: clip('s120', 1920, 1080, 120, 4, 120) };
  fs.rmSync(STUDIO, { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  fs.mkdirSync(path.join(STUDIO, 'library', 'speed'), { recursive: true });
  for (const f of Object.values(C)) fs.copyFileSync(f, path.join(STUDIO, 'library', 'speed', path.basename(f)));
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
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

  await page.setInputFiles('.home-drop input[type=file]', C.s1080);
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await ev(async () => {
    window.P = await import('/app/core/project.js'); window.RT = await import('/app/core/retime.js').catch(() => null); window.HI = await import('/app/core/history.js');
    window.kbbEditor.setAspect('16:9');
    const ed = window.kbbEditor;
    for (const name of ['s4k', 's120']) { const m = await ed.media.importPath(`library/speed/${name}.mp4`, name); ed.apply(window.HI.ops.addMedia(m)); }
  });
  await page.waitForTimeout(500);

  // ───────────────────────── functional checks (skipped with --perf) ─────────────────────────
  if (!ARGS.has('--perf')) {
    await ev(async () => { window.SP = await import('/app/editor/speed.js'); window.CM = await import('/app/captions/model.js'); });
    const FPS = 30;
    // one clip of `name` [in, out) on the video track at t0 = 0, plus props; returns its id
    const setup = (name, inn, out, props = {}) => ev(({ name, inn, out, props }) => {
      const ed = window.kbbEditor, p = ed.project, P = window.P, ops = window.HI.ops;
      const m = p.media.find((x) => x.name === name || x.src.endsWith(name + '.mp4'));
      const keep = new Set(['video', 'music', 'caption']);
      const rm = p.tracks.flatMap((t) => t.items.map((i) => i.id));
      const vt = p.tracks.find((t) => t.kind === 'video');
      const it = P.createItem('clip', Object.assign({ media: m.id, t0: 0, in: inn, out, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'test' }, props));
      ed.apply(ops.batch('setup', [ops.removeItems(rm), ops.setCaptions({ style: null, lang: 'auto', words: [], lines: [] }), ops.addItems([{ trackId: vt.id, item: it }])]));
      ed.history.seal(); ed.select([]);
      void keep;
      return it.id;
    }, { name, inn, out, props });
    const item = (id) => ev((id) => { const h = window.P.findItem(window.kbbEditor.project, id); return h ? JSON.parse(JSON.stringify(h.item)) : null; }, id);
    const settle = async () => { await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 30000 }); await ev(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))); };
    const seekDraw = async (t) => { await ev((t) => window.kbbEditor.seek(t), t); await page.waitForTimeout(60); await settle(); await page.waitForTimeout(60); await settle(); };
    // the frame index the monitor shows (the bar along the top) and a small RGB copy of it
    const previewBar = () => ev(() => {
      const c = window.kbbEditor.preview.canvas, x = c.getContext('2d'), w = c.width, h = c.height, y = Math.floor(h / 24);
      const row = x.getImageData(0, y, w, 1).data; let n = 0;
      for (let k = 0; k < 10; k++) { const i = Math.floor((k + 0.5) * w / 10) * 4; if ((row[i] + row[i + 1] + row[i + 2]) / 3 > 128) n |= 1 << k; }
      return n;
    });
    const previewRGB = (w, h) => ev(({ w, h }) => {
      const c = window.kbbEditor.preview.canvas, o = new OffscreenCanvas(w, h), x = o.getContext('2d');
      x.imageSmoothingQuality = 'high'; x.drawImage(c, 0, 0, w, h);
      const d = x.getImageData(0, 0, w, h).data, out = new Array(w * h * 3);
      for (let i = 0, j = 0; i < d.length; i += 4) { out[j++] = d[i]; out[j++] = d[i + 1]; out[j++] = d[i + 2]; }
      return out;
    }, { w, h });
    const exportTo = (name, opts = {}) => ev(async ({ name, opts }) => {
      const E = await import('/app/core/export.js'), ed = window.kbbEditor, p = ed.project;
      const r = await E.exportMp4(p, ed.media, Object.assign({ quality: 'high', width: 640, height: 360 }, opts));
      const file = `workspaces/${p.workspace}/exports/${name}`; await ed.engine.writeFile(file, r.blob);
      return { file, note: r.note, frames: r.frames };
    }, { name, opts });
    const want = (id, t) => ev(({ id, t }) => { const it = window.P.findItem(window.kbbEditor.project, id).item; return Math.floor((window.P.srcTime(it, t) + 0.0005) * 30); }, { id, t });
    const tlDur = () => ev(() => window.kbbEditor.duration());

    // ── 1. constant speeds and their durations (inspector buttons) ──
    let id = await setup('s1080', 0, 6);
    await ev((id) => window.kbbEditor.select([id]), id);
    await page.waitForSelector('.sp-seg button');
    for (const [lbl, v] of [['¼×', 0.25], ['½×', 0.5], ['2×', 2], ['3×', 3], ['1×', 1]]) {
      await page.click(`.sp-seg button:has-text("${lbl}")`);
      const it = await item(id), d = await tlDur();
      ok(it.speed === v && Math.abs(d - 6 / v) < 1e-6, `inspector ${lbl}: speed ${it.speed}, 6 s of footage → ${d.toFixed(4)} s on the timeline (want ${(6 / v).toFixed(4)})`);
    }
    // the custom slider is one undo step
    await ev((id) => window.SP.setSpeed(window.kbbEditor, id, 1.75), id); await ev(() => window.kbbEditor.history.seal());
    ok(Math.abs((await tlDur()) - 6 / 1.75) < 1e-6, `custom 1.75×: ${(await tlDur()).toFixed(4)} s`);

    // ── 2. ramp presets: duration = integral of the curve, timeline ↔ source mapping frame-exact ──
    const rampRows = [];
    for (const key of ['slowmo-hit', 'speed-up', 'ramp-in', 'ramp-out', 'bullet-time', 'montage']) {
      id = await setup('s1080', 1, 7);
      await ev(({ id, key }) => window.SP.setPreset(window.kbbEditor, id, key), { id, key });
      const r = await ev((id) => {
        const it = window.P.findItem(window.kbbEditor.project, id).item, RT = window.RT, D = RT.retimeDur(it);
        // ∫ v dt over the clip by the midpoint rule (100k steps) must give back the 6 s of footage
        let S = 0; const N = 100000; for (let i = 0; i < N; i++) S += RT.speedAt(it, it.t0 + (i + 0.5) / N * D) * D / N;
        // every output frame: timeline → source → timeline, and the source frame index monotonic
        let worst = 0, mono = true, prev = -1;
        for (let i = 0; i <= Math.round(D * 30); i++) { const t = Math.min(it.t0 + D, it.t0 + i / 30), s = RT.srcTime(it, t); worst = Math.max(worst, Math.abs(RT.tlTime(it, s) - t)); if (s < prev - 1e-9) mono = false; prev = s; }
        return { D, S, worst, mono, speed: it.speed, ends: [RT.srcTime(it, it.t0), RT.srcTime(it, it.t0 + D)] };
      }, id);
      rampRows.push({ key, ...r });
      ok(Math.abs(r.S - 6) < 1e-4 && r.worst < 1e-6 && r.mono && Math.abs(r.ends[0] - 1) < 1e-9 && Math.abs(r.ends[1] - 7) < 1e-9, `ramp ${key}: 6 s of footage → ${r.D.toFixed(4)} s; ∫v·dt = ${r.S.toFixed(6)} s; frame round-trip error ${r.worst.toExponential(1)} s; starts/ends at source 1 s / 7 s`);
    }
    numbers.ramps = rampRows;
    // the monitor shows the frame the mapping says, at sampled frames of a ramp (paused)
    id = await setup('s1080', 1, 7, {});
    await ev(({ id }) => window.SP.setPreset(window.kbbEditor, id, 'slowmo-hit'), { id });
    await ev((id) => window.kbbEditor.apply(window.HI.ops.setItem(id, { smooth: null }, 'x')), id);
    let D = await tlDur(), bad = [];
    for (const f of [0.05, 0.3, 0.45, 0.55, 0.7, 0.95]) { const t = Math.round(D * f * 30) / 30; await seekDraw(t); const got = await previewBar(), w = await want(id, t); if (got !== w) bad.push(`${t.toFixed(3)}s: ${got}≠${w}`); }
    ok(!bad.length, `monitor on a ramp (paused): shows exactly the mapped source frame at 6 sampled times${bad.length ? ' — ' + bad.join(', ') : ''}`);

    // ── 3. export = preview: frame numbers along a ramp, and pixels at sampled frames (blend on) ──
    const ex1 = await exportTo('sp-ramp.mp4');
    const f1 = path.join(STUDIO, ex1.file), pr1 = ffprobe(f1), vs = pr1.streams.find((s) => s.codec_type === 'video');
    ok(Math.abs(+vs.nb_read_frames - Math.round(D * 30)) <= 1, `ramp export: ${vs.nb_read_frames} frames for ${D.toFixed(4)} s (${Math.round(D * 30)} expected)`);
    bad = [];
    for (let i = 0; i < Math.round(D * 30); i += 7) { const got = readBar(frameRGB(f1, i, 640, 360), 640, 360), w = await want(id, i / 30); if (got !== w) bad.push(`#${i}: ${got}≠${w}`); }
    ok(!bad.length, `ramp export: every 7th frame shows exactly the mapped source frame${bad.length ? ' — ' + bad.slice(0, 6).join(', ') : ''}`);
    await ev((id) => window.kbbEditor.apply(window.HI.ops.setItem(id, { smooth: 'blend' }, 'x')), id);
    const ex2 = await exportTo('sp-ramp-blend.mp4');
    const f2 = path.join(STUDIO, ex2.file); const diffs = [];
    for (const f of [0.1, 0.4, 0.5, 0.6, 0.9]) {
      const i = Math.round(D * f * 30); await seekDraw(i / 30);
      const a = await previewRGB(160, 90), b = frameRGB(f2, i, 160, 90); diffs.push(+meanDiff(a, b).toFixed(2));
    }
    numbers.exportVsPreview = diffs;
    ok(Math.max(...diffs) < 6, `export = preview (ramp + frame blend) at 5 sampled frames: mean |Δ| ${diffs.join(' / ')} (of 255, < 6)`);
    // blending really blends in the slow part: a frame between two source frames differs from both neighbours
    const mid = Math.round(D * 0.5 * 30), ra = frameRGB(f2, mid, 160, 90), rp = frameRGB(f1, mid, 160, 90);
    ok(meanDiff(ra, rp) > 0.3, `frame blend changes the slow-motion frames (Δ ${meanDiff(ra, rp).toFixed(2)} vs the plain frame)`);

    // optical flow (export only): runs offline, keeps the length, and makes in-between frames that differ from a cross-fade
    {
      await ev((id) => window.kbbEditor.apply(window.HI.ops.setItem(id, { smooth: 'flow' }, 'x')), id);
      const t0 = Date.now(), ex = await exportTo('sp-ramp-flow.mp4'), secs = (Date.now() - t0) / 1000;
      const ff = path.join(STUDIO, ex.file), n = +ffprobe(ff).streams.find((x) => x.codec_type === 'video').nb_read_frames;
      const dflow = meanDiff(frameRGB(ff, mid, 160, 90), ra), dnb = meanDiff(frameRGB(f2, mid - 1, 160, 90), frameRGB(f2, mid + 1, 160, 90));
      numbers.flow = { secs, frames: n, vsBlend: dflow, neighbours: dnb };
      ok(n === +vs.nb_read_frames && dflow > 0.05, `optical flow export: ${n} frames in ${secs.toFixed(1)} s (640×360), in-between frames differ from the blend by ${dflow.toFixed(2)}/255`);
      await ev((id) => window.kbbEditor.apply(window.HI.ops.setItem(id, { smooth: 'blend' }, 'x')), id);
    }

    // ── 4. sound: duration and pitch (440 Hz tone in the clip) ──
    const audioCase = async (label, props, wantDur, wantHz) => {
      const id = await setup('s1080', 1, 4, props);
      const ex = await exportTo(`sp-a-${label.replace(/\W+/g, '')}.mp4`, { width: 320, height: 180 });
      const x = pcm(path.join(STUDIO, ex.file)), d = x.length / SR;
      const hz = dominant(x, Math.floor(x.length * 0.3), Math.floor(x.length * 0.7));
      (numbers.audio ||= []).push({ label, dur: +d.toFixed(3), hz: +hz.toFixed(1) });
      ok(Math.abs(d - wantDur) < 0.06 && Math.abs(hz - wantHz) < wantHz * 0.02, `${label}: sound ${d.toFixed(3)} s (want ${wantDur.toFixed(3)}), dominant ${hz.toFixed(1)} Hz (want ${wantHz})`);
      return { id, x };
    };
    await audioCase('2× pitch kept', { speed: 2 }, 1.5, 440);
    await audioCase('0.5× pitch kept', { speed: 0.5 }, 6, 440);
    await audioCase('3× pitch kept', { speed: 3 }, 1, 440);
    await audioCase('2× tape (keep pitch off)', { speed: 2, keepPitch: false }, 1.5, 880);
    await audioCase('reverse 1×', { reverse: true }, 3, 440);
    // mute while ramping: silent where the speed is away from 1×, the tone where it is 1×
    {
      const id2 = await setup('s1080', 1, 7);
      await ev(({ id2 }) => window.SP.setPreset(window.kbbEditor, id2, 'slowmo-hit'), { id2 });
      await ev((id2) => window.kbbEditor.apply(window.HI.ops.setItem(id2, { rampMute: true }, 'x')), id2);
      const ex = await exportTo('sp-a-rampmute.mp4', { width: 320, height: 180 });
      const x = pcm(path.join(STUDIO, ex.file)), n = x.length;
      const it = await item(id2), DD = 6 / it.speed;
      const slow = rms(x, Math.floor(n * 0.5) - 2400, Math.floor(n * 0.5) + 2400), fast = rms(x, Math.floor(n * 0.08), Math.floor(n * 0.08) + 4800);
      // clicks: the largest sample-to-sample jump stays like the tone's own
      let jump = 0; for (let i = 1; i < n; i++) jump = Math.max(jump, Math.abs(x[i] - x[i - 1]));
      numbers.rampMute = { slow, fast, jump, dur: n / SR, want: DD };
      ok(slow < fast * 0.02 && fast > 0.05 && Math.abs(n / SR - DD) < 0.06 && jump < 0.2, `mute while ramping: slow part rms ${slow.toFixed(4)} vs 1× part ${fast.toFixed(3)}, no clicks (largest step ${jump.toFixed(3)}), ${(n / SR).toFixed(3)} s`);
    }

    // the monitor plays the SAME rendered sound as the export (Web Audio), in step with the clock
    {
      const id3 = await setup('s1080', 1, 4, { speed: 2 });
      await ev(() => window.kbbEditor.seek(0));
      await page.waitForTimeout(1500);                         // the sound renders in the worker meanwhile
      const pa = await ev(async () => {
        const ed = window.kbbEditor; ed.play(); await new Promise((r) => setTimeout(r, 700));
        const n = [...ed.preview.audio.nodes.values()][0], ac = ed.preview.audio.ac;
        const pos = n ? ac.currentTime - n.at + n.off : null, clock = ed.t;
        const els = [...ed.media.els.values()].filter((x) => x.tagName === 'VIDEO' && !x.paused && !x.muted).length;
        ed.pause();
        return { nodes: ed.preview.audio.nodes.size, dur: n?.buf.duration, pos, clock, els };
      });
      ok(pa.dur && Math.abs(pa.dur - 1.5) < 0.002 && Math.abs(pa.pos - pa.clock) < 0.08 && pa.els === 0, `monitor sound at 2×: the rendered ${pa.dur?.toFixed(3)} s buffer plays (position ${pa.pos?.toFixed(3)} s vs clock ${pa.clock.toFixed(3)} s), no <video> sound doubles it`);
      void id3;
    }
    // high-frame-rate footage: a 120 fps clip at 0.25× shows its real frames
    {
      const fpsProbe = await ev(() => window.kbbEditor.project.media.find((m) => /s120/.test(m.src))?.fps);
      const id4 = await setup('s120', 0, 2, { speed: 0.25 });
      const seen = [], wantF = [];
      for (const t of [0.5, 0.5 + 1 / 30, 0.5 + 2 / 30, 0.5 + 3 / 30, 3, 6.9]) {
        await seekDraw(t); seen.push(await previewBar());
        wantF.push(await ev(({ id4, t }) => Math.floor((window.P.srcTime(window.P.findItem(window.kbbEditor.project, id4).item, t) + 0.0005) * 120), { id4, t }));
      }
      ok(fpsProbe === 120 && JSON.stringify(seen) === JSON.stringify(wantF) && new Set(seen.slice(0, 4)).size === 4, `120 fps clip (probed ${fpsProbe} fps) at 0.25×: consecutive 30 fps timeline frames show source frames ${seen.slice(0, 4).join(', ')} — every real frame, none repeated`);
    }
    // multi-format export keeps the ramp: the 9:16 version of the 16:9 ramp project has the same length
    {
      id = await setup('s1080', 1, 7);
      await ev((id) => window.SP.setPreset(window.kbbEditor, id, 'speed-up'), id);
      const r = await ev(async () => {
        const ed = window.kbbEditor, { reflowTo } = await import('/app/editor/items.js'), E = await import('/app/core/export.js');
        const q = JSON.parse(JSON.stringify(ed.project)); reflowTo('9:16')(q);
        const it = q.tracks.find((t) => t.kind === 'video').items[0];
        const x = await E.exportMp4(q, ed.media, { width: 180, height: 320 });
        return { frames: x.frames, curve: !!it.curve, d: window.P.projectDuration(q) };
      });
      ok(r.curve && r.frames === Math.round(r.d * 30), `multi-format: the 9:16 export of the ramped 16:9 edit keeps the ramp (${r.frames} frames = ${r.d.toFixed(3)} s)`);
    }

    // ── 5. reverse: monitor and export show the frames backwards ──
    id = await setup('s1080', 2, 5, { reverse: true });
    bad = [];
    for (const t of [0, 0.5, 1.5, 2.9]) { await seekDraw(t); const g = await previewBar(), w = await want(id, t); if (g !== w) bad.push(`${t}s ${g}≠${w}`); }
    const ex3 = await exportTo('sp-rev.mp4'); const f3 = path.join(STUDIO, ex3.file);
    const seq = []; for (let i = 0; i < 90; i += 15) seq.push(readBar(frameRGB(f3, i, 640, 360), 640, 360));
    const wantSeq = []; for (let i = 0; i < 90; i += 15) wantSeq.push(await want(id, i / 30));
    ok(!bad.length && JSON.stringify(seq) === JSON.stringify(wantSeq), `reverse: monitor ${bad.length ? 'WRONG ' + bad.join(', ') : 'exact'}; export frames ${seq.join(' → ')} (want ${wantSeq.join(' → ')})`);

    // ── 6. freeze frame: a 2 s hold of the frame under the playhead, the rest moves on ──
    id = await setup('s1080', 0, 4);
    await ev(() => window.kbbEditor.seek(1.5));
    await ev((id) => window.SP.freezeFrame(window.kbbEditor, id, 2), id);
    await page.waitForTimeout(200);
    const vt = await ev(() => window.kbbEditor.project.tracks.find((t) => t.kind === 'video').items.map((i) => ({ t0: i.t0, in: i.in, out: i.out, freeze: !!i.freeze, d: window.P.itemDur(i) })));
    ok(vt.length === 3 && vt[1].freeze && Math.abs(vt[1].d - 2) < 1e-6 && Math.abs(vt[2].t0 - 3.5) < 1e-6 && Math.abs((await tlDur()) - 6) < 1e-6, `freeze: split + 2 s hold + rest: ${JSON.stringify(vt.map((x) => [x.t0, x.freeze ? 'hold' : x.d.toFixed(2)]))}`);
    const ex4 = await exportTo('sp-freeze.mp4'); const f4 = path.join(STUDIO, ex4.file);
    const held = [45, 60, 80, 104].map((i) => readBar(frameRGB(f4, i, 640, 360), 640, 360));
    await seekDraw(2.6); const pvHeld = await previewBar();
    ok(held.every((x) => x === 45) && pvHeld === 45 && readBar(frameRGB(f4, 106, 640, 360), 640, 360) === 46, `freeze: export holds frame ${held.join(', ')} for 2 s, the monitor shows ${pvHeld}, then frame 46 follows`);
    await ev(() => window.kbbEditor.history.undo());
    ok((await ev(() => window.kbbEditor.project.tracks.find((t) => t.kind === 'video').items.length)) === 1 && Math.abs((await tlDur()) - 4) < 1e-6, 'freeze is one undo step');

    // ── 7. captions, overlays and transitions stay on the footage; undo / redo exact ──
    id = await setup('s1080', 0, 6);
    await ev(({ id }) => {
      const ed = window.kbbEditor, p = ed.project, P = window.P, ops = window.HI.ops;
      const words = [1, 2, 3, 4].map((t, i) => ({ t0: t, t1: t + 0.3, w: 'w' + i, conf: 1 }));
      ed.apply(ops.setCaptions({ style: null, lang: 'en', words, lines: window.CM.buildLines(words, null, p.canvas) }));
      const ov = p.tracks.find((t) => t.kind === 'overlay');
      ed.apply(ops.addItems([{ trackId: ov.id, item: P.createItem('sticker', { id: 'stk', t0: 2, in: 0, out: 1, text: '⭐', x: 10, y: 10, w: 100, h: 100 }) },
        { trackId: ov.id, item: P.createItem('sticker', { id: 'stk2', t0: 6.5, in: 0, out: 1, text: '⭐', x: 10, y: 10, w: 100, h: 100 }) }]));
      ed.history.seal();
    }, { id });
    const snap0 = await ev(() => JSON.stringify(window.kbbEditor.project.tracks) + JSON.stringify(window.kbbEditor.project.captions.words.map((w) => [w.t0, w.t1])));
    await ev((id) => window.SP.setSpeed(window.kbbEditor, id, 2), id); await ev(() => window.kbbEditor.history.seal());
    let st = await ev(() => ({ w: window.kbbEditor.project.captions.words.map((w) => w.t0), s: window.P.findItem(window.kbbEditor.project, 'stk').item.t0, s2: window.P.findItem(window.kbbEditor.project, 'stk2').item.t0 }));
    ok(JSON.stringify(st.w) === '[0.5,1,1.5,2]' && st.s === 1 && Math.abs(st.s2 - 3.5) < 1e-6, `2×: caption words ${JSON.stringify(st.w)}, sticker on the clip 2 → ${st.s} s, the one after the clip 6.5 → ${st.s2} s`);
    await ev(() => window.kbbEditor.history.undo());
    const snap1 = await ev(() => JSON.stringify(window.kbbEditor.project.tracks) + JSON.stringify(window.kbbEditor.project.captions.words.map((w) => [w.t0, w.t1])));
    ok(snap1 === snap0, 'undo puts the clip, the sticker and the caption words back exactly');
    await ev(() => window.kbbEditor.history.redo());
    ok((await ev(() => window.kbbEditor.project.captions.words[0].t0)) === 0.5, 'redo re-applies it');
    await ev(() => window.kbbEditor.history.undo());
    await ev((id) => window.SP.setPreset(window.kbbEditor, id, 'ramp-in'), id);
    st = await ev((id) => { const p = window.kbbEditor.project, it = window.P.findItem(p, id).item; return { w: p.captions.words.map((w) => w.t0), want: [1, 2, 3, 4].map((s) => Math.round(window.P.tlTime(it, s) * 1000) / 1000), s: window.P.findItem(p, 'stk').item.t0, ws: Math.round(window.P.tlTime(it, 2) * 1e5) / 1e5 }; }, id);
    ok(JSON.stringify(st.w) === JSON.stringify(st.want) && Math.abs(st.s - st.ws) < 1e-4, `ramp: caption words follow the curve ${JSON.stringify(st.w)}; the sticker stays on its moment of footage (${st.s.toFixed(3)} s)`);
    // split a ramp: both halves play exactly what the whole played
    st = await ev((id) => {
      const ed = window.kbbEditor, P = window.P, it = JSON.parse(JSON.stringify(P.findItem(ed.project, id).item)), D = P.itemDur(it);
      ed.seek(D * 0.4); ed.select([id]); ed.split();
      const vt = ed.project.tracks.find((t) => t.kind === 'video').items;
      let worst = 0; for (let i = 0; i <= 200; i++) { const t = D * i / 200, ref = P.srcTime(it, t), c = vt.find((x) => t >= x.t0 && t < P.itemEnd(x)) || vt[vt.length - 1]; worst = Math.max(worst, Math.abs(P.srcTime(c, t) - ref)); }
      return { n: vt.length, worst, d: P.itemDur(vt[0]) + P.itemDur(vt[1]) - D };
    }, id);
    ok(st.n === 2 && st.worst < 1e-6 && Math.abs(st.d) < 1e-6, `split a ramp at 40 %: the two pieces map within ${st.worst.toExponential(1)} s of the whole, same total length`);

    // ── 8. save / reload; an old project opens unchanged ──
    id = await setup('s1080', 0, 6);
    await ev((id) => window.SP.setPreset(window.kbbEditor, id, 'bullet-time'), id);
    await ev((id) => window.kbbEditor.apply(window.HI.ops.setItem(id, { reverse: true, keepPitch: false, rampMute: true }, 'x')), id);
    await ev(() => window.kbbEditor.save());
    const before = await ev(() => JSON.stringify(window.kbbEditor.project.tracks));
    await page.reload();
    await page.waitForSelector('.tl-item', { timeout: 30000 });
    await page.waitForFunction(() => window.kbbEditor?.project);
    await ev(async () => { window.P = await import('/app/core/project.js'); window.HI = await import('/app/core/history.js'); window.RT = await import('/app/core/retime.js'); window.SP = await import('/app/editor/speed.js'); window.CM = await import('/app/captions/model.js'); });
    ok((await ev(() => JSON.stringify(window.kbbEditor.project.tracks))) === before, 'a project with a ramp, reverse, tape sound and mute-while-ramping reopens identical after save + reload');
    const old = await ev(() => {
      const P = window.P;
      const legacy = { v: 1, name: 'old', canvas: { w: 1080, h: 1920, fps: 30 }, media: [], tracks: [{ id: 'tv', kind: 'video', items: [{ id: 'a', type: 'clip', media: 'm', t0: 0, in: 2, out: 8, speed: 0.5 }, { id: 'b', type: 'clip', media: 'm', t0: 12, in: 0, out: 3, speed: 3 }] }] };
      const p = P.normalizeProject(legacy), [a, b] = p.tracks[0].items;
      const oldSrc = (it, t) => Math.min(it.out, Math.max(it.in, it.in + (t - it.t0) * (it.speed || 1)));
      let worst = 0; for (let t = 0; t < 13; t += 0.01) for (const it of [a, b]) worst = Math.max(worst, Math.abs(P.srcTime(it, t) - oldSrc(it, t)));
      const RT = window.RT;
      return { d: P.projectDuration(p), worst, fields: Object.keys(a).filter((k) => ['curve', 'reverse', 'freeze', 'smooth', 'keepPitch', 'rampMute'].includes(k)), stream: RT.usesFrameStream(a) || RT.usesFrameStream(b) };
    });
    ok(old.d === 13 && old.worst === 0 && !old.fields.length && !old.stream, `an old project (0.5× and 3× clips) opens unchanged: same ${old.d} s, identical time mapping (max Δ ${old.worst}), no new fields added, export reads it as before`);

    // ── 9. timeline: badge, right-click → Speed, Shift-drag an edge to stretch ──
    id = await setup('s1080', 0, 6);
    await ev((id) => window.kbbEditor.select([id]), id);
    const clipSel = `.tl-item[data-id="${id}"]`;
    let box = await page.locator(clipSel).boundingBox();
    await page.mouse.click(box.x + box.width * 0.5, box.y + box.height * 0.5, { button: 'right' });
    await page.waitForSelector('.sp-menu');
    const menuItems = await ev(() => [...document.querySelectorAll('.sp-menu button')].map((b) => b.textContent));
    ok(menuItems.includes('2×') && menuItems.some((x) => /Slow-mo hit/.test(x)) && menuItems.some((x) => /Reverse/.test(x)) && menuItems.some((x) => /Freeze/.test(x)), `right-click a clip → Speed menu (${menuItems.length} entries: ${menuItems.slice(0, 8).join(' · ')} …)`);
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, 'timeline-menu-1440.png') });
    await page.click('.sp-menu button:has-text("2×")');
    ok((await item(id)).speed === 2 && (await page.locator(clipSel + ' .sp-badge').textContent()) === '2×', 'menu 2× sets the speed and the clip shows a "2×" badge');
    box = await page.locator(clipSel).boundingBox();
    const pps = await ev(() => window.kbbEditor.timeline.pps);
    await page.keyboard.down('Shift');
    await page.mouse.move(box.x + box.width - 3, box.y + box.height / 2); await page.mouse.down();
    await page.mouse.move(box.x + box.width - 3 + pps * 1.5, box.y + box.height / 2, { steps: 10 }); await page.mouse.up();
    await page.keyboard.up('Shift');
    const sv = await item(id), sd = await tlDur();
    ok(Math.abs(sd - 4.5) < 0.15 && Math.abs(sv.speed - 6 / sd) < 1e-6 && sv.in === 0 && sv.out === 6, `Shift-drag the right edge 1.5 s: the clip stretches to ${sd.toFixed(2)} s at ${sv.speed.toFixed(3)}× — same footage (in 0, out 6), not trimmed`);
    await ev(() => window.kbbEditor.history.undo());
    ok((await item(id)).speed === 2, 'the stretch is one undo step');

    // ── 10. inspector: presets + curve editor (drag a point) ──
    await ev((id) => { window.kbbEditor.select([]); window.kbbEditor.select([id]); }, id);
    await page.click('.sp-presets button[data-preset="slowmo-hit"]');
    ok((await item(id)).curve?.preset === 'slowmo-hit', 'inspector: the Slow-mo hit preset sets the ramp');
    await page.locator('.sp-curve').scrollIntoViewIfNeeded();
    const cv = await page.locator('.sp-curve').boundingBox();
    const it10 = await item(id), P0 = it10.curve.pts[2];   // the 0.3× point
    const px = cv.x + 1 + 10 + P0[0] * (264 - 20), py = cv.y + 1 + 10 + (1 - Math.log(P0[1] / 0.1) / Math.log(40)) * (112 - 20);
    await page.mouse.move(px, py); await page.mouse.down(); await page.mouse.move(px, py - 25, { steps: 6 }); await page.mouse.up();
    const it11 = await item(id);
    ok(it11.curve.pts[2][1] > P0[1] + 0.1 && Math.abs(it11.speed - (await ev((pts) => window.RT.curveMean(pts), it11.curve.pts))) < 1e-5, `curve editor: dragging the slow point up ${P0[1]}× → ${it11.curve.pts[2][1]}×; the clip's length follows (${(6 / it11.speed).toFixed(3)} s)`);
    // the panel follows the drag without reselecting the clip: the header's length and the Custom slider read the new
    // speed. Before 2026-11-18 both kept the values from before the drag (the inspector does not rebuild while focus is
    // inside it, and the curve canvas holds the focus) until the clip was selected again.
    const panel = await ev(() => ({ head: document.querySelector('.insp-head small')?.textContent || '', slider: +document.querySelector('input[aria-label="Custom speed"]')?.value }));
    const durTxt = await ev((d) => import('/app/editor/ui.js').then((U) => U.fmtDur(d)), 6 / it11.speed);
    ok(panel.head.includes(durTxt) && Math.abs(panel.slider - it11.speed) < 0.026, `after the curve drag the panel shows the new length in its header ("${panel.head}" has ${durTxt}) and the Custom slider the new average speed (${panel.slider} for ${it11.speed.toFixed(3)}×) without reselecting the clip`);
    await page.waitForTimeout(300);
    await page.locator('.sp-curve').screenshot({ path: path.join(SHOTS, 'curve-editor.png') });
    // the pictures: the panel (top: speeds, presets, curve; then the options), the timeline badges, at 1440×900
    await ev(() => { const ed = window.kbbEditor, p = ed.project, P = window.P, ops = window.HI.ops, vt = p.tracks.find((t) => t.kind === 'video'), a = vt.items[0], m = a.media, e = P.itemEnd(a);
      ed.apply(ops.addItems([{ trackId: vt.id, item: P.createItem('clip', { media: m, t0: e, in: 0, out: 3, speed: 2, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'fast' }) },
        { trackId: vt.id, item: P.createItem('clip', { media: m, t0: e + 1.5, in: 3, out: 6, reverse: true, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'backwards' }) },
        { trackId: vt.id, item: P.createItem('clip', { media: m, t0: e + 4.5, in: 6, out: 8, freeze: true, muted: true, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'hold' }) },
        { trackId: vt.id, item: P.createItem('clip', { media: m, t0: e + 6.5, in: 8, out: 9.5, speed: 0.5, smooth: 'blend', x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'slow' }) }]));
      ed.timeline.fit(); ed.seek(2.4); });
    await page.waitForTimeout(2500);
    await ev(() => document.querySelector('.sp-presets').closest('details').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(SHOTS, 'inspector-speed-1440.png') });
    await ev(() => document.querySelector('.sp-btns').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(SHOTS, 'inspector-speed-options-1440.png') });
    const tl = await page.locator('.k-video').boundingBox();
    await page.screenshot({ path: path.join(SHOTS, 'timeline-badges-1440.png'), clip: { x: 0, y: Math.max(0, tl.y - 70), width: 1440, height: Math.min(900 - Math.max(0, tl.y - 70), tl.height + 140) } });
    const badges = await ev(() => [...document.querySelectorAll('.k-video .sp-badge')].map((b) => b.textContent));
    ok(badges.length === 5, `timeline badges: ${badges.join(' | ')}`);
  }

  // ── preview playback: frame time and source-frame accuracy ──
  // Every rAF tick of the play loop records: the tick interval, the loop's own cost, the source time the clip SHOULD show
  // (srcTime of the playhead) and the source time of the picture it actually drew (the frame the <video> presented —
  // requestVideoFrameCallback mediaTime — or the frame a decoder path handed over, src.__srcT).
  async function perf(label, mediaName, props, secs = 3) {
    return ev(async ({ mediaName, props, secs }) => {
      const ed = window.kbbEditor, p = ed.project, P = window.P, ops = window.HI.ops;
      const m = p.media.find((x) => x.name === mediaName || x.src.endsWith(mediaName + '.mp4'));
      const vt = p.tracks.find((t) => t.kind === 'video');
      if (props.preset) { props = Object.assign({}, props, window.RT.curveProps({}, window.RT.PRESETS[props.preset].pts, props.preset)); delete props.preset; }
      const it0 = P.createItem('clip', { media: m.id, t0: 0, in: 0, out: m.dur, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'perf' });
      ed.apply(ops.batch('perf', [ops.removeItems(vt.items.map((i) => i.id)), ops.addItems([{ trackId: vt.id, item: Object.assign(it0, props) }])]));
      const item = vt.items[0];
      const fps = /s120/.test(m.src) ? 120 : 30;
      const el = ed.media.element(m.id);
      if (el && el.requestVideoFrameCallback && !el.__hooked) { el.__hooked = 1; const cb = (now, md) => { el.__mt = md.mediaTime; el.__pf = md.presentedFrames; el.requestVideoFrameCallback(cb); }; el.requestVideoFrameCallback(cb); }
      const pv = ed.preview, rec = [];
      const src0 = pv.source;
      let drawn = null;
      pv.source = (it, st) => { const s = src0.call(pv, it, st); if (s) drawn = s.__srcT != null ? s.__srcT : (s.tagName === 'VIDEO' ? (s.__mt ?? s.currentTime) : null); return s; };
      const L = pv.loop; let cost = 0;
      pv.loop = () => { const a = performance.now(); drawn = null; L(); cost = performance.now() - a; if (ed.playing) rec.push({ now: a, t: ed.t, cost, want: P.srcTime(item, ed.t), drawn }); };
      ed.seek(0); await new Promise((r) => setTimeout(r, 1200));
      const q0 = el?.getVideoPlaybackQuality?.();
      ed.play();
      await new Promise((r) => setTimeout(r, secs * 1000));
      ed.pause();
      const q1 = el?.getVideoPlaybackQuality?.();
      pv.loop = L; pv.source = src0;
      const st = pv.streams.get(m.id), proxy = st && !st.then ? st.proxy || null : null;
      const dts = []; for (let i = 1; i < rec.length; i++) dts.push(rec[i].now - rec[i - 1].now);
      // source-frame error per tick (frames of the SOURCE), skipping the first 300 ms (start-up)
      const settled = rec.filter((r) => r.now - rec[0].now > 300 && r.drawn != null);
      const errs = settled.map((r) => Math.abs(r.drawn - r.want) * fps);
      // expected distinct source frames in the played range vs the ones actually drawn
      const want = new Set(settled.map((r) => Math.floor(r.want * fps + 1e-3))), got = new Set(settled.map((r) => Math.round(r.drawn * fps)));
      let missed = 0; for (const f of want) if (!got.has(f)) missed++;
      return { ticks: rec.length, dts, costs: rec.map((r) => r.cost), errs, wantFrames: want.size, missedFrames: missed, nodrawn: rec.length - rec.filter((r) => r.drawn != null).length,
        elDropped: q1 && q0 ? q1.droppedVideoFrames - q0.droppedVideoFrames : null, elTotal: q1 && q0 ? q1.totalVideoFrames - q0.totalVideoFrames : null, fps, proxy };
    }, { mediaName, props, secs }).then((r) => {
      const d = stats(r.dts), c = stats(r.costs), er = stats(r.errs);
      const late = r.dts.filter((x) => x > 16.7 * 1.5).length;
      const out = { label, ticks: r.ticks, tick: d, loopCost: c, lateTicks: late, srcErrFrames: er, wantFrames: r.wantFrames, missedFrames: r.missedFrames, elDropped: r.elDropped, elTotal: r.elTotal, nodrawn: r.nodrawn, proxy: r.proxy };
      console.log(`PERF ${label}: tick ${d.mean}/${d.p95}/${d.max} ms (mean/p95/max), late ${late}/${r.ticks}, loop ${c.mean}/${c.p95}/${c.max} ms, source error ${er.mean}/${er.p95}/${er.max} frames, missed ${r.missedFrames}/${r.wantFrames} frames, element dropped ${r.elDropped}/${r.elTotal}${r.proxy ? `, proxy 1/${r.proxy.k} (full copies ${r.proxy.copyMs} ms for ${r.proxy.need} a second)` : ''}`);
      (numbers.perf ||= []).push(out);
      return out;
    });
  }
  const perfSet = [['1080p 1×', 's1080', { speed: 1 }], ['1080p 0.25×', 's1080', { speed: 0.25 }], ['1080p 0.5×', 's1080', { speed: 0.5 }], ['1080p 2×', 's1080', { speed: 2 }], ['1080p 3×', 's1080', { speed: 3 }],
    ['4K 0.5×', 's4k', { speed: 0.5 }], ['4K 2×', 's4k', { speed: 2 }], ['4K 3×', 's4k', { speed: 3 }], ['120fps 0.25×', 's120', { speed: 0.25 }]];
  if (!ARGS.has('--baseline')) perfSet.push(['1080p 0.25× blend', 's1080', { speed: 0.25, smooth: 'blend' }], ['1080p 0.5× blend', 's1080', { speed: 0.5, smooth: 'blend' }],
    ['1080p ramp slow-mo hit', 's1080', { preset: 'slowmo-hit', smooth: 'blend' }], ['1080p ramp speed-up', 's1080', { preset: 'speed-up' }], ['1080p ramp montage', 's1080', { preset: 'montage', smooth: 'blend' }],
    ['1080p reverse 1×', 's1080', { reverse: true }], ['1080p reverse 2×', 's1080', { reverse: true, speed: 2 }], ['120fps 0.25× blend', 's120', { speed: 0.25, smooth: 'blend' }],
    ['4K ramp slow-mo hit', 's4k', { preset: 'slowmo-hit', smooth: 'blend' }], ['4K reverse 1×', 's4k', { reverse: true }]);
  const ONLY = process.argv.includes('--only') ? process.argv[process.argv.indexOf('--only') + 1] : null;
  if (!ARGS.has('--func')) for (const [label, name, props] of perfSet) if (!ONLY || label.includes(ONLY)) await perf(label, name, props);
  // 4K without a hardware decoder: turning a decoded 4K frame into a picture costs more than a frame lasts, so before
  // 2026-11-18 a 4K ramp ran 7–9 frames behind (p95 24) and a 4K reverse 3 (p95 15). The preview now measures its copies
  // and drops to a 1/2 or 1/4 proxy copy when they miss the budget (core/frames-engine.js); the export never does.
  if (!ARGS.has('--func') && !ONLY) {
    for (const label of ['4K ramp slow-mo hit', '4K reverse 1×']) {
      const x = numbers.perf.find((q) => q.label === label);
      ok(!!x && x.srcErrFrames.p95 <= 2 && x.missedFrames <= Math.ceil(x.wantFrames * 0.05), `${label} keeps up in the preview: source error ${x?.srcErrFrames.mean}/${x?.srcErrFrames.p95} frames (mean/p95), missed ${x?.missedFrames}/${x?.wantFrames}${x?.proxy ? `; on its 1/${x.proxy.k} proxy copy (a full copy took ${x.proxy.copyMs} ms)` : '; full copies (a hardware decoder)'}`);
    }
    const full = await ev(async () => {
      const ed = window.kbbEditor, m = ed.project.media.find((x) => /s4k/.test(x.src));
      const fs = await ed.media.openStream(m.id, { maxW: m.w, budgetMB: 640 });   // what core/export.js opens
      const ws = [];
      for (let i = 0; i < 40; i++) { const r = await fs.frameAt(i / 30, false, 1, 1); ws.push(r?.a?.width || 0); }
      const proxy = fs.proxy || null; fs.close();
      return { ws: [...new Set(ws)], proxy, w: m.w };
    });
    ok(full.ws.length === 1 && full.ws[0] === full.w && !full.proxy, `the export reads 4K at full resolution: 40 frames in order, every one ${full.ws.join('/')} px wide (source ${full.w}), no proxy copy`);
  }

  fs.writeFileSync(path.join(OUT, ARGS.has('--perf') ? 'perf-numbers.json' : 'numbers.json'), JSON.stringify(numbers, null, 1));
  ok(errors.length === 0, `no console errors (${errors.length})${errors.length ? ': ' + errors.slice(0, 5).join(' | ') : ''}`);
  ok(outside.length === 0, `no request leaves 127.0.0.1 (${outside.length})`);
  await browser.close(); await H.stopEngine(e);
  console.log(`\n${failures ? failures + ' FAILED' : 'ALL PASSED'} (${results.length} checks)`);
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
