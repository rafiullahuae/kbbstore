#!/usr/bin/env node
// KBB Studio · MUSIC CHECK (20 Nov 2026) — the background music's controls, the Music Library window and the new
// "viral reel" tracks, measured (the owner: "we don't have controls on the background music when we add, we can't
// reduce the volume etc, and upon double click on the background music, it should give proper library, upon click on
// anyone, it should preview us before apply. and i want more music like viral reels use").
//
//   tracks    every library track (Node + ffmpeg): decodes, length = library.json, ebur128 integrated loudness (new
//             tracks −14 ±1 LUFS), true peak ≤ −1 dBTP, no clipped samples, no DC, no silence gap (250 ms windows),
//             the loop seam click-free (the jump across end→start no bigger than the track's own 99.9th-percentile
//             sample step), the spectrum balanced (sub / low / mid / high shares inside sane bounds)
//   decode    every library track loads and decodes in Chromium (decodeAudioData), length as listed
//   inspector a music clip gets the 🎵 Music section FIRST in the Inspector (Volume, Mute, Fade in/out, Start the song
//             at, Loop, Auto-duck, Replace song…); Speed is folded below it
//   volume    Volume 50 % → the export's music RMS −6.02 dB vs 100 % (ffmpeg astats on the exported mix); a slow slider
//             drag (inputs 0.9 s apart) is ONE undo step; on Auto music's keyframed ducking the slider is heard too
//   fades     Fade in / out 2 s: the export is −6 dB at the ramp's middle and silent at the very edges
//   duck      Auto-duck under voice on a clip with speech (flite) at 1–4 s and silence after: the music is −10 dB under
//             the speech and untouched in the silence; the amount slider moves it (−18 dB)
//   start     Start the song at 12 s: the export's music = the song from 12 s (correlation), the clip keeps its length
//   loop      Loop on a song shorter than the clip: no silence after the song's end, the repeat = the song again
//   live      Volume 150 % plays live through a Web Audio gain of 1.5 (above 100 % is heard, not clamped to 1)
//   library   a double-click on the music clip opens the Music Library beside the video (frame fully visible at
//             1440×900 and 1280×800); every category has songs; a click plays THAT song alone and the project is
//             byte-identical; switching songs = one preview at a time; "Preview with my video" plays the reel with the
//             timeline's music hushed and the song from the playhead; Esc stops everything and changes nothing; Cancel
//             is byte-identical; ✓ Use this song = ONE undo step that keeps volume 50 %, fades and ducking; undo =
//             byte-identical; ★ favourites saved per company; Your music imports a file into library/music/user
//   hygiene   no console errors, nothing leaves 127.0.0.1
//   shots     shots/music/*.png at 1440×900 and 1280×800
//
// Mutation notes (each line is red without the code it names):
//   volume  · core/export.js: `levelAt(item, t)` back to `evalProp(item, 'volume', t)` → the keyframed-music volume case
//             is red (the slider moved, the sound did not — the owner's bug)
//   duck    · core/audio-gain.js duckGain(): `return 1` → duck −10 dB red;  `hasGainCurve` without duck → export ignores it
//   start   · audio/music.js setStart(): drop `in:` → the correlation is red
//   loop    · core/export.js: remove `s.loop = true` → silence after the song's end, red;  core/retime.js wrap → preview
//   live    · editor/preview.js setLevel(): `x.volume = Math.min(1, v)` only → gain 1.5 red
//   library · audio/music-library.js closeMusicLibrary(): replaceSong with two history.apply calls → "one step" red
//   inspector · editor/inspector.js: drop the MUSIC_PANEL branch → the section check is red
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/music-check.cjs   (MC_ONLY=tracks,editor)
'use strict';
const fs = require('fs'), path = require('path'), { execFileSync, spawnSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'music'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'music');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const ONLY = process.env.MC_ONLY ? new Set(process.env.MC_ONLY.split(',')) : null, want = (k) => !ONLY || ONLY.has(k);
let failures = 0; const results = [];
const ok = (c, msg) => { const l = (c ? 'PASS ' : 'FAIL ') + msg; console.log(l); results.push(l); if (!c) failures++; };
const info = (msg) => { console.log('     ' + msg); results.push('     ' + msg); };
const LIB = JSON.parse(fs.readFileSync(path.join(ROOT, 'library/music/library.json'), 'utf8')).tracks;
const NEW = new Set(LIB.filter((t) => /r4-music/.test(t.licence || '')).map((t) => t.id));

function pcm(file, sr = 44100) { const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '2', '-ar', String(sr), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(b.buffer, b.byteOffset, b.length / 4); }
function ebur(file) {
  const r = spawnSync('ffmpeg', ['-hide_banner', '-nostats', '-i', file, '-af', 'ebur128=peak=true', '-f', 'null', '-'], { encoding: 'utf8' }).stderr;
  const I = +(/I:\s+(-?[\d.]+) LUFS\s*\n\s*Threshold[\s\S]*?Loudness range/.exec(r) || [])[1], tp = +(/True peak:\s*\n\s*Peak:\s+(-?[\d.inf]+) dBFS/.exec(r) || [])[1];
  return { I, tp };
}
const db = (x) => 20 * Math.log10(Math.max(1e-12, x));
/** RMS (dB) of an interleaved stereo pcm over [a,b) seconds */
function rmsDb(x, sr, a, b) { const i0 = Math.max(0, Math.floor(a * sr)), i1 = Math.min(x.length / 2, Math.floor(b * sr)); let s = 0; for (let i = i0; i < i1; i++) s += x[2 * i] ** 2 + x[2 * i + 1] ** 2; return 10 * Math.log10(s / Math.max(1, 2 * (i1 - i0)) + 1e-20); }
/** band shares of a mono signal (FFT-free: one-pole splits) */
function bands(x, sr) {
  const n = x.length / 2, out = { sub: 0, low: 0, mid: 0, high: 0 }; let a1 = 0, a2 = 0, a3 = 0;
  const k = (f) => Math.exp(-2 * Math.PI * f / sr), k1 = k(60), k2 = k(250), k3 = k(4000);
  for (let i = 0; i < n; i++) { const v = (x[2 * i] + x[2 * i + 1]) / 2; a1 = (1 - k1) * v + k1 * a1; a2 = (1 - k2) * v + k2 * a2; a3 = (1 - k3) * v + k3 * a3; out.sub += a1 * a1; out.low += (a2 - a1) ** 2; out.mid += (a3 - a2) ** 2; out.high += (v - a3) ** 2; }
  const T = out.sub + out.low + out.mid + out.high; for (const q in out) out[q] = +(100 * out[q] / T).toFixed(1); return out;
}

// ════════ tracks (Node + ffmpeg) ════════
function checkTracks() {
  console.log('\n── tracks: every library song decoded and measured (ffmpeg) ──');
  const rows = [], bad = [];
  let newBytes = 0;
  for (const t of LIB) {
    const f = path.join(ROOT, 'library/music', t.file), isNew = NEW.has(t.id);
    if (!fs.existsSync(f)) { bad.push(`${t.id}: file missing`); continue; }
    if (isNew) newBytes += fs.statSync(f).size;
    const x = pcm(f), sr = 44100, n = x.length / 2, dur = n / sr;
    let clip = 0, dcL = 0, dcR = 0, steps = [];
    for (let i = 0; i < n; i++) { if (Math.abs(x[2 * i]) >= 0.999 || Math.abs(x[2 * i + 1]) >= 0.999) clip++; dcL += x[2 * i]; dcR += x[2 * i + 1]; }
    dcL /= n; dcR /= n;
    // gaps: up to the end of the music (a written ending's last tail may fade to nothing, as it should)
    let minWin = 0; { let m = Infinity; const end = t.endingStart != null ? t.endingStart + 0.5 * t.barSec : dur - 0.05; for (let a = 0; a + 0.25 <= end; a += 0.25) m = Math.min(m, rmsDb(x, sr, a, a + 0.25)); minWin = m; }
    const L = ebur(f);
    const row = { id: t.id, genre: t.genre || t.mood, bpm: t.bpm, dur: +dur.toFixed(2), listed: t.duration, lufs: L.I, tp: L.tp, clip, dc: +Math.max(Math.abs(dcL), Math.abs(dcR)).toExponential(1), minWin: +minWin.toFixed(1), isNew };
    if (isNew) {
      // seam: the jump from the last sample to the first vs the track's own sample steps
      for (let i = 1; i < n; i += 7) steps.push(Math.abs(x[2 * i] - x[2 * i - 2]));
      steps.sort((a, b) => a - b); const p999 = steps[Math.floor(steps.length * 0.999)];
      // compare a short window across the seam (last 5 ms + first 5 ms) with the same window in the middle of a bar
      // the loop: its region a..b (library.json), b → a
      const la = Math.round((t.loop?.a || 0) * sr), lb = Math.min(n, Math.round((t.loop?.b ?? dur) * sr)), ln = lb - la;
      const seam = Math.max(Math.abs(x[2 * la] - x[2 * (lb - 1)]), Math.abs(x[2 * la + 1] - x[2 * (lb - 1) + 1]));
      row.seam = +(seam / p999).toFixed(2);
      // a click is a burst of HIGH-frequency energy: the first-difference energy in 3 ms around the seam (wrapped)
      // against the 99th percentile of the same 3 ms measure everywhere in the track
      const W = 64, at = (c) => la + (((c - la) % ln) + ln) % ln, hfAt = (c) => { let e = 0; for (let k = -W; k < W; k++) { const i = at(c + k), j = at(c + k - 1); e += (x[2 * i] - x[2 * j]) ** 2 + (x[2 * i + 1] - x[2 * j + 1]) ** 2; } return e; };
      const hs = []; for (let c = la + W; c < lb - W; c += 997) hs.push(hfAt(c)); hs.sort((a, b) => a - b);
      row.seamHf = +(hfAt(la) / hs[Math.floor(hs.length * 0.99)]).toFixed(2);
      const e = rmsDb(x, sr, lb / sr - 0.05, lb / sr), s = rmsDb(x, sr, la / sr, la / sr + 0.05); row.seamLvl = +(s - e).toFixed(1);
      row.ending = t.endingStart != null && t.musicEnd > t.endingStart && Math.abs(t.loop?.b - t.endingStart) < 1e-3;
      row.bands = bands(x, sr);
    }
    rows.push(row);
    const okLen = Math.abs(dur - t.duration) < 0.15;
    if (!okLen) bad.push(`${t.id}: decoded ${dur.toFixed(2)} s, listed ${t.duration}`);
    if (clip) bad.push(`${t.id}: ${clip} clipped samples`);
    if (Math.abs(dcL) > 2e-3 || Math.abs(dcR) > 2e-3) bad.push(`${t.id}: DC ${row.dc}`);
    if (isNew) {
      if (!(Math.abs(L.I + 14) <= 1)) bad.push(`${t.id}: ${L.I} LUFS`);
      if (!(L.tp <= -1)) bad.push(`${t.id}: true peak ${L.tp}`);
      if (!(minWin > -50)) bad.push(`${t.id}: a silence gap (${minWin.toFixed(1)} dB in a 250 ms window)`);
      if (!row.ending) bad.push(`${t.id}: no written ending after its loop`);
      if (!(row.seam <= 2)) bad.push(`${t.id}: loop seam jump ${row.seam}× the 99.9th-percentile step`);
      if (!(row.seamHf <= 1)) bad.push(`${t.id}: a click at the loop seam (HF ${row.seamHf}× the track's 99th percentile)`);
      // bounds from the 27 starter tracks (same splitter): sub+low 30–76 %, high 1.2–4.7 %
      const b = row.bands; if (!(b.sub + b.low >= 15 && b.sub + b.low <= 80 && b.high >= 0.8 && b.mid >= 15)) bad.push(`${t.id}: spectrum ${JSON.stringify(b)}`);
    }
  }
  const nw = rows.filter((r) => r.isNew);
  ok(LIB.length === rows.length && !bad.length, `${rows.length} library tracks decode, lengths as listed, no clipped samples, no DC; ${nw.length} new tracks: −14 ±1 LUFS, true peak ≤ −1 dBTP, no silence gap, a seamless loop (click-free seam) then a written ending, spectrum balanced${bad.length ? ' — ' + bad.join('; ') : ''}`);
  ok(nw.length >= 25 && nw.length <= 35, `${nw.length} new original tracks (25–35 asked), ${(newBytes / 1048576).toFixed(1)} MB added`);
  info('id                   genre        bpm   len   LUFS   TP    step  click  level  sub/low/mid/high %   (step/click: × the track\'s own; level: the loop back to the intro, dB)');
  for (const r of nw) info(`${r.id.padEnd(20)} ${String(r.genre).padEnd(12)} ${String(r.bpm).padEnd(5)} ${r.dur.toFixed(1).padStart(5)} ${String(r.lufs).padStart(6)} ${String(r.tp).padStart(5)} ${String(r.seam).padStart(5)} ${String(r.seamHf).padStart(6)} ${String(r.seamLvl).padStart(6)}  ${r.bands.sub}/${r.bands.low}/${r.bands.mid}/${r.bands.high}`);
  fs.writeFileSync(path.join(OUT, 'tracks.json'), JSON.stringify(rows, null, 1));
  return { rows, newBytes };
}

// ════════ the editor ════════
async function editor() {
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings', 'adstudio']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  // the test clip: 8 s of picture; speech (flite) at 1.0 s for ~3 s, then silence to the end
  const clip = path.join(OUT, 'speech-clip.mp4');
  if (!fs.existsSync(clip)) {
    const sp = path.join(OUT, 'speech.wav');
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', "flite=text='Hello and welcome. Today I am showing you the new serum, it feels so light.':voice=slt", '-ar', '48000', '-ac', '1', sp]);
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=540x960:rate=30:duration=8', '-i', sp, '-filter_complex', '[1:a]adelay=1000|1000,apad=whole_dur=8,atrim=0:8,volume=0.9[a]', '-map', '0:v', '-map', '[a]', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'veryfast', '-c:a', 'aac', '-b:a', '160k', '-t', '8', clip]);
  }
  const speechEnd = (() => { const x = pcm(clip, 16000); let last = 0; for (let a = 0; a < 8; a += 0.05) if (rmsDb(x, 16000, a, a + 0.05) > -45) last = a + 0.05; return +last.toFixed(2); })();
  info(`test clip: speech 1.0–${speechEnd} s, silence to 8 s`);
  fs.mkdirSync(path.join(STUDIO, 'library', 'mc-test'), { recursive: true });
  fs.copyFileSync(clip, path.join(STUDIO, 'library', 'mc-test', 'speech-clip.mp4'));
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/No available adapters|AudioContext was not allowed|net::ERR_ABORTED/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); localStorage.removeItem('kbbstudio.musicLib.rect'); } catch {} });
  await page.goto(e.url + '/app/'); await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, a) => page.evaluate(fn, a);
  const shot = async (name, vp) => { if (vp) await page.setViewportSize(vp); await page.waitForTimeout(450); await page.screenshot({ path: path.join(SHOTS, name + '.png') }); };
  await page.setInputFiles('.home-drop input[type=file]', path.join(STUDIO, 'library', 'mc-test', 'speech-clip.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await ev(async () => {
    const ed = window.kbbEditor; window.ED = ed;
    window.E = await import('/app/core/export.js'); window.P = await import('/app/core/project.js'); window.M = await import('/app/audio/music.js'); window.L = await import('/app/audio/music-library.js'); window.G = await import('/app/core/audio-gain.js');
    window.J = () => JSON.stringify(ed.project, (k, v) => (k === 'modified' ? undefined : v));
    window.steps = () => ed.history.undoStack.length;
    window.mus = () => ed.project.tracks.find((t) => t.kind === 'music').items[0];
    window.vid = () => ed.project.tracks.find((t) => t.kind === 'video').items[0];
    /** the export's own mix (mixAudio + the export's mix passes), 48 kHz stereo, as a WAV the engine writes */
    window.MIX = async (name) => {
      const p = ed.project, T = window.P.projectDuration(p);
      let buf = await window.E.mixAudio(p, ed.media, Math.min(T, 8)); buf = buf ? await window.E.postMix(buf, p) : null;
      if (!buf) return null;
      const n = buf.length, L0 = buf.getChannelData(0), R0 = buf.getChannelData(1), dv = new DataView(new ArrayBuffer(44 + n * 8));
      const w = (o, s) => { for (let i = 0; i < s.length; i++) dv.setUint8(o + i, s.charCodeAt(i)); };
      w(0, 'RIFF'); dv.setUint32(4, 36 + n * 8, true); w(8, 'WAVE'); w(12, 'fmt '); dv.setUint32(16, 16, true); dv.setUint16(20, 3, true); dv.setUint16(22, 2, true); dv.setUint32(24, 48000, true); dv.setUint32(28, 48000 * 8, true); dv.setUint16(32, 8, true); dv.setUint16(34, 32, true); w(36, 'data'); dv.setUint32(40, n * 8, true);
      for (let i = 0; i < n; i++) { dv.setFloat32(44 + i * 8, L0[i], true); dv.setFloat32(48 + i * 8, R0[i], true); }
      const file = `workspaces/${p.workspace}/exports/${name}.wav`; await ed.engine.writeFile(file, new Blob([dv.buffer]));
      return file;
    };
  });
  const wavOf = (rel) => pcm(path.join(STUDIO, rel), 48000);
  const SONG = 'sunlit-swipe', songT = LIB.find((t) => t.id === SONG);

  // ── a music clip from the library (Left panel → Music → +), selected ──
  await ev(async (s) => { await window.ED.bin.addLibrary('library/music/' + s + '.m4a', { t0: 0 }); }, SONG);
  await page.waitForFunction(() => window.mus?.() && window.ED.selectedItems()[0]?.item.type === 'audio');
  await page.waitForTimeout(300);
  // fit the music to the 8 s clip (as Add music would) — a trim, so the mix covers the reel exactly
  await ev(() => { const it = window.mus(); window.ED.history.apply(window.ED.ops.setItems([{ id: it.id, props: { out: it.in + 8 } }], 'Trim')); window.ED.history.seal(); window.ED.inspector.render(true); });

  // ════════ inspector ════════
  const insp = await ev(() => {
    const body = window.ED.inspEl || document.querySelector('.inspector.side'), secs = [...document.querySelectorAll('.side-body details.sec, details.sec')].filter((d) => d.offsetParent);
    const first = secs[0], ms = document.querySelector('[data-music="section"]'), sp = secs.find((d) => /speed/i.test(d.querySelector('summary')?.textContent || ''));
    const r = ms?.getBoundingClientRect();
    return { first: first?.querySelector('summary')?.textContent.trim(), has: ['volume', 'mute', 'fadein', 'fadeout', 'start', 'loop', 'duck', 'replace'].filter((k) => document.querySelector(`[data-music="${k}"]`)), speedOpen: sp?.open, volBottom: document.querySelector('[data-music="volume"]')?.getBoundingClientRect().bottom, vh: innerHeight, top: r?.top };
  });
  ok(/Music/.test(insp.first || '') && insp.has.length === 8 && insp.speedOpen === false && insp.volBottom < insp.vh, `the music clip's Inspector opens on 🎵 Music (first section “${insp.first}”) with ${insp.has.join(', ')}; Volume is on screen at 1440×900 (bottom ${Math.round(insp.volBottom)} px of ${insp.vh}); Speed is folded below (it used to sit first, the Sound section under the fold)`);
  const top = () => ev(() => { const sec = document.querySelector('[data-music="section"]'); let p = sec?.parentElement; while (p && p.scrollHeight <= p.clientHeight + 2) p = p.parentElement; if (p) p.scrollTop = 0; });
  const reveal = (sel) => ev((sel) => { const el = document.querySelector(sel); el?.scrollIntoView({ block: 'center' }); }, sel);
  await top(); await shot('inspector-music-1440x900');
  // the hover card on the music's Volume describes the MUSIC volume (search catalogue: “Background music volume”)
  await page.hover('[data-music="volume"] input'); await page.waitForTimeout(900);
  const card = await ev(() => document.querySelector('.kbh.on')?.textContent || '');
  ok(/background music/i.test(card), `hovering the music's Volume shows its own card: “${card.slice(0, 90)}”`);
  await page.mouse.move(5, 5); await page.waitForTimeout(200);
  // search → “take me there”: with nothing selected, each music entry selects the music clip and lands on its control
  const nav = await ev(async () => {
    const C = (await import('/app/core/search-index.js')).CATALOGUE, N = await import('/app/editor/search-nav.js'), out = [];
    for (const title of ['Background music volume', 'Auto-duck under voice', 'Start the song at', 'Loop music to fill the video', 'Replace song…', 'Music Library window']) {
      window.ED.select([]); await new Promise((r) => setTimeout(r, 100));
      const e = C.find((x) => x.title === title); const r = await N.goTo(e);
      const el = r?.el || document.activeElement, box = el?.getBoundingClientRect?.();
      out.push({ title, ok: !!r?.ok && !!box && box.width > 0 && box.bottom <= innerHeight && box.top >= 0 && (title === 'Music Library window' || window.ED.selectedItems()[0]?.item.type === 'audio'), at: el?.closest?.('[data-music]')?.dataset.music || el?.dataset?.musicOpen || el?.tagName });
    }
    window.ED.select([window.mus().id]); window.ED.showSide('inspector'); window.ED.inspector.render(true);
    return out;
  });
  ok(nav.every((x) => x.ok), `search → Enter lands on the control, the music clip selected first: ${nav.map((x) => `“${x.title}” → ${x.at}${x.ok ? '' : ' ✗'}`).join(', ')}`);
  await page.setViewportSize({ width: 1280, height: 800 }); await top(); await shot('inspector-music-1280x800');
  await page.setViewportSize({ width: 1440, height: 900 });

  // ════════ volume (UI slider; export mix measured with ffmpeg) ════════
  // mute the video's own sound for the music measurements (its speech would be in the mix)
  await ev(() => { const v = window.vid(); window.ED.history.apply(window.ED.ops.setItems([{ id: v.id, props: { muted: true } }], 'Mute')); window.ED.history.seal(); });
  const f100 = await ev(() => window.MIX('mc-vol100'));
  /** drag a slider in the Inspector: pointerdown, inputs `gap` ms apart (slower than the 700 ms coalescing), change */
  const dragRange = (k, vals, gap = 120) => ev(async ({ k, vals, gap }) => {
    const inp = document.querySelector(`[data-music="${k}"] input[type=range]`);
    inp.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
    for (const v of vals) { inp.value = String(v); inp.dispatchEvent(new Event('input', { bubbles: true })); await new Promise((r) => setTimeout(r, gap)); }
    inp.dispatchEvent(new Event('change', { bubbles: true })); inp.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
  }, { k, vals, gap });
  const s0 = await ev(() => window.steps());
  await dragRange('volume', [0.9, 0.8, 0.7, 0.6, 0.5], 900);
  const s1 = await ev(() => window.steps()), v50 = await ev(() => ({ v: window.mus().volume, label: window.ED.history.undoLabel, shown: document.querySelector('[data-music="volume"] .val')?.textContent }));
  const f50 = await ev(() => window.MIX('mc-vol50'));
  const a100 = wavOf(f100), a50 = wavOf(f50), r100 = rmsDb(a100, 48000, 0.5, 7.5), r50 = rmsDb(a50, 48000, 0.5, 7.5);
  ok(Math.abs((r50 - r100) + 6.02) < 0.15, `Volume 50 % → the exported music is ${(r50 - r100).toFixed(2)} dB vs 100 % (−6.02 expected; ffmpeg-decoded mix, RMS 0.5–7.5 s: ${r100.toFixed(2)} → ${r50.toFixed(2)} dBFS); the slider reads “${v50.shown}”`);
  ok(s1 - s0 === 1 && v50.v === 0.5, `a slow drag of the Volume slider (5 steps, 0.9 s apart) is ONE undo step (“${v50.label}”, ${s1 - s0} step)`);
  // Auto music's ducking = absolute volume keyframes: the slider must still be heard (the owner's bug)
  const kfCase = await ev(async () => {
    const ed = window.ED, it = window.mus();
    ed.history.apply(ed.ops.setItems([{ id: it.id, props: { volume: 0.8, kf: { volume: [[0, 0.8, 'linear'], [8, 0.8, 'linear']] } } }], 'kf')); ed.history.seal();
    const a = await window.E.mixAudio(ed.project, ed.media, 8);
    window.M.setVolume(ed, it.id, 0.4); ed.history.seal();
    const b = await window.E.mixAudio(ed.project, ed.media, 8);
    const rms = (x) => { const c = x.getChannelData(0); let s = 0; for (let i = 24000; i < c.length - 24000; i++) s += c[i] * c[i]; return 10 * Math.log10(s / (c.length - 48000)); };
    const kf = window.mus().kf.volume.map((k) => k[1]);
    ed.history.undo(); ed.history.undo();
    return { d: rms(b) - rms(a), kf };
  });
  ok(Math.abs(kfCase.d + 6.02) < 0.2, `music added by “Add music” (ducking stored as volume keyframes, which used to override the slider): Volume 80 % → 40 % is ${kfCase.d.toFixed(2)} dB in the export (keyframes scaled to ${kfCase.kf.join(', ')})`);
  // back to 100 %
  await ev(() => { window.M.setVolume(window.ED, window.mus().id, 1); window.ED.history.seal(); });

  // ════════ fades ════════
  await ev(() => { const ed = window.ED, it = window.mus(); ed.history.apply(ed.ops.setItems([{ id: it.id, props: { fadeIn: 2, fadeOut: 2 } }], 'Fades')); ed.history.seal(); });
  const fF = await ev(() => window.MIX('mc-fades')), aF = wavOf(fF);
  const fd = (t) => rmsDb(aF, 48000, t - 0.1, t + 0.1) - rmsDb(a100, 48000, t - 0.1, t + 0.1);
  const fin = fd(1.0), fout = fd(7.0), edge0 = rmsDb(aF, 48000, 0, 0.01) - rmsDb(a100, 48000, 0, 0.01), mid = fd(4.0);
  ok(Math.abs(fin + 6.02) < 0.8 && Math.abs(fout + 6.02) < 0.8 && edge0 < -30 && Math.abs(mid) < 0.1, `Fade in / out 2 s in the export: ${fin.toFixed(2)} dB at 1.0 s, ${fout.toFixed(2)} dB at 7.0 s (−6 at the ramp's middle), ${edge0.toFixed(1)} dB in the first 10 ms, ${mid.toFixed(2)} dB at 4 s`);
  await ev(() => { const ed = window.ED, it = window.mus(); ed.history.apply(ed.ops.setItems([{ id: it.id, props: { fadeIn: 0, fadeOut: 0 } }], 'Fades')); ed.history.seal(); });

  // ════════ duck (Auto-duck under voice: the checkbox; the video's sound is the voice) ════════
  await ev(() => { const v = window.vid(); window.ED.history.apply(window.ED.ops.setItems([{ id: v.id, props: { muted: false } }], 'Unmute')); window.ED.history.seal(); window.ED.select([window.mus().id]); window.ED.inspector.render(true); });
  const sd0 = await ev(() => window.steps());
  await page.click('[data-music="duck"] input');
  await page.waitForFunction(() => window.mus().duck?.on && window.mus().duck.speech);
  const duck = await ev(() => ({ sp: window.mus().duck.speech, db: window.mus().duck.db, steps: window.steps() }));
  const spIn = duck.sp.length && duck.sp[0][0] >= 0.6 && duck.sp[0][0] <= 1.5 && duck.sp[duck.sp.length - 1][1] <= speechEnd + 0.6 && duck.sp[duck.sp.length - 1][1] >= speechEnd - 0.8;
  ok(spIn && duck.steps - sd0 === 1, `Auto-duck found the speech: ${duck.sp.map(([a, b]) => a.toFixed(2) + '–' + b.toFixed(2)).join(', ')} s (clip: 1.0–${speechEnd} s), one undo step`);
  await ev(() => { const v = window.vid(); window.ED.history.apply(window.ED.ops.setItems([{ id: v.id, props: { muted: true } }], 'Mute')); window.ED.history.seal(); });
  const fD = await ev(() => window.MIX('mc-duck')), aD = wavOf(fD);
  const under = rmsDb(aD, 48000, 1.6, speechEnd - 0.6) - rmsDb(a100, 48000, 1.6, speechEnd - 0.6), after = rmsDb(aD, 48000, speechEnd + 1.4, 7.8) - rmsDb(a100, 48000, speechEnd + 1.4, 7.8);
  ok(Math.abs(under + duck.db) < 0.6 && Math.abs(after) < 0.2, `the music under the speech is ${under.toFixed(2)} dB (−${duck.db} dB set), after it ${after.toFixed(2)} dB (untouched) — export mix`);
  await ev(() => { window.ED.select([window.mus().id]); window.ED.inspector.render(true); });
  await dragRange('duck-db', [14, 18]);
  const fD2 = await ev(() => window.MIX('mc-duck18')), aD2 = wavOf(fD2), under2 = rmsDb(aD2, 48000, 1.6, speechEnd - 0.6) - rmsDb(a100, 48000, 1.6, speechEnd - 0.6);
  ok(Math.abs(under2 + 18) < 0.8, `Duck by −18 dB (the slider) → ${under2.toFixed(2)} dB under the speech`);
  await reveal('[data-music="duck"]'); await shot('inspector-duck-1440x900');
  // duck off again for the rest
  await page.click('[data-music="duck"] input'); await page.waitForFunction(() => !window.mus().duck?.on);

  // ════════ start the song at 12 s ════════
  const len0 = await ev(() => window.P.itemDur(window.mus()));
  await dragRange('start', [6, 12]);
  const st = await ev(() => ({ in: window.mus().in, len: window.P.itemDur(window.mus()) }));
  const fS = await ev(() => window.MIX('mc-start12')), aS = wavOf(fS), song = pcm(path.join(ROOT, 'library/music', SONG + '.m4a'), 48000);
  const corr0 = (x, xo, y, yo, n) => { let a = 0, b = 0, c = 0; for (let i = 0; i < n; i++) { const p = x[2 * (xo + i)], q = y[2 * (yo + i)]; a += p * q; b += p * p; c += q * q; } return a / Math.sqrt(b * c + 1e-20); };
  /** the best match within ±5 ms (the song's own decode vs the browser's may sit a few samples apart) */
  const corr = (x, xo, y, yo, n) => { let best = -2; for (let d = -240; d <= 240; d += 4) best = Math.max(best, corr0(x, xo, y, yo + d, n)); return best; };
  const cS = corr(aS, 48000, song, 48000 * 13, 24000);
  ok(st.in === 12 && Math.abs(st.len - len0) < 1e-6 && cS > 0.95, `Start the song at 0:12 → the clip plays the song from 12 s (export vs the song at 13 s: correlation ${cS.toFixed(3)}), its length stays ${st.len.toFixed(2)} s`);

  // ════════ loop to fill ════════
  // start 6.5 s before the file's end: played once, it runs through its written ending and stops short of the 8 s video
  const durSong = songT.duration, lb = songT.loop?.b ?? durSong, st0s = Math.floor(durSong - 6.5);
  await ev((s) => { window.M.setStart(window.ED, window.mus().id, s); window.ED.history.seal(); window.ED.inspector.render(true); }, st0s);
  const once = await ev(() => ({ len: window.P.itemDur(window.mus()) }));
  await page.click('[data-music="loop"] input');
  await page.waitForFunction(() => !!window.mus().loop);
  const lp = await ev(() => ({ loop: window.mus().loop, len: window.P.itemDur(window.mus()) }));
  const fL = await ev(() => window.MIX('mc-loop')), aL = wavOf(fL);
  const wrapAt = lp.loop.b - st0s, tail = rmsDb(aL, 48000, 5.5, 7.8);   // timeline time where the song wraps
  const cL = corr(aL, Math.round((wrapAt + 0.3) * 48000), song, Math.round((lp.loop.a + 0.3) * 48000), 24000);
  ok(once.len < 7.9 && Math.abs(lp.len - 8) < 0.05 && tail > -40 && cL > 0.9, `Loop to fill the video: a song with ${once.len.toFixed(2)} s left (played once) fills the 8 s video, wrapping at ${wrapAt.toFixed(2)} s, (${lp.len.toFixed(2)} s), no silence after the song's end (${tail.toFixed(1)} dBFS at 5.5–7.8 s), the repeat = the song from ${lp.loop.a} s (correlation ${cL.toFixed(3)})`);
  await page.click('[data-music="loop"] input'); await page.waitForFunction(() => !window.mus().loop);
  await ev((s) => { window.M.setStart(window.ED, window.mus().id, 0); const it = window.mus(); window.ED.history.apply(window.ED.ops.setItems([{ id: it.id, props: { out: 8 } }], 'Trim')); window.ED.history.seal(); });

  // ════════ live: 150 % is heard above 100 % in the preview ════════
  await ev(() => { window.M.setVolume(window.ED, window.mus().id, 1.5); window.ED.history.seal(); window.ED.seek(1); window.ED.play(); });
  await page.waitForTimeout(900);
  const live = await ev(() => { const ed = window.ED, m = window.P.findMedia(ed.project, window.mus().media), el = ed.media.element(m.id), g = ed.preview.boosted?.get(el); const r = { gain: g ? +g.gain.value.toFixed(3) : null, vol: el.volume, playing: !el.paused }; ed.pause(); return r; });
  ok(live.gain !== null && Math.abs(live.gain - 1.5) < 0.02 && live.vol === 1, `Volume 150 % plays live through a Web Audio gain of ${live.gain} (element at ${live.vol}; it used to be clamped to 100 %)`);
  await ev(() => { window.M.setVolume(window.ED, window.mus().id, 0.5); window.ED.history.seal(); const v = window.vid(); window.ED.history.apply(window.ED.ops.setItems([{ id: v.id, props: { muted: false } }], 'Unmute')); window.ED.history.seal(); });
  // fades and ducking kept through Replace: give the clip some
  await ev(async () => { const ed = window.ED, it = window.mus(); ed.history.apply(ed.ops.setItems([{ id: it.id, props: { fadeIn: 0.5, fadeOut: 1.5 } }], 'Fades')); ed.history.seal(); await window.M.setDuck(ed.context(), it.id, { on: true, db: 12 }); ed.history.seal(); });

  // ════════ the Music Library window ════════
  const decodeAll = await ev(async () => {
    const A = await import('/app/auto/index.js'), lib = (await A.library(window.ED.context())).music, ac = new OfflineAudioContext(1, 1, 44100), bad = [];
    for (const t of lib) { try { const b = await ac.decodeAudioData(await (await fetch('/' + (t.src || 'library/music/' + t.file))).arrayBuffer()); if (Math.abs(b.duration - t.duration) > 0.15) bad.push(`${t.id} ${b.duration.toFixed(2)}≠${t.duration}`); } catch (err) { bad.push(t.id + ' ' + err.message); } }
    return { n: lib.length, bad };
  });
  ok(decodeAll.n === LIB.length && !decodeAll.bad.length, `every library track loads and decodes in Chromium (${decodeAll.n}${decodeAll.bad.length ? ': ' + decodeAll.bad.join(', ') : ''})`);
  const J0 = await ev(() => window.J()), st0 = await ev(() => window.steps());
  const openByDbl = async () => {
    const id = await ev(() => window.mus().id), loc = page.locator(`.tl-item[data-id="${id}"]`);
    await loc.scrollIntoViewIfNeeded(); const box = await loc.boundingBox();
    await loc.dblclick({ position: { x: Math.min(60, box.width / 2), y: box.height / 2 } });
    await page.waitForSelector('.mlib-row', { timeout: 15000 });
  };
  const frameFree = () => ev(() => { const a = document.querySelector('.mlib').getBoundingClientRect(), b = window.ED.preview.stage.getBoundingClientRect(); return { free: a.right <= b.left || a.left >= b.right || a.bottom <= b.top || a.top >= b.bottom, a: [a.left, a.top, a.width, a.height].map(Math.round), b: [b.left, b.top, b.width, b.height].map(Math.round), inWin: a.left >= 0 && a.right <= innerWidth && a.bottom <= innerHeight }; });
  await openByDbl();
  const ff1 = await frameFree();
  ok(ff1.free && ff1.inWin, `a double-click on the music clip opens the Music Library beside the video: window ${ff1.a.join(',')} vs frame ${ff1.b.join(',')} — the frame stays fully visible (1440×900)`);
  // layout: nothing clipped (unless it ellipsizes on purpose), no sideways scroll, the footer's buttons one height
  const layout = () => ev(() => {
    const bad = [], scan = (root) => { for (const x of root.querySelectorAll('button, .nm, .meta, label, b, small, span.lbl, span.val')) { if (!x.offsetParent) continue; const cs = getComputedStyle(x); if (x.scrollWidth > x.clientWidth + 1 && cs.textOverflow !== 'ellipsis' && cs.overflow !== 'visible') bad.push((x.className || x.tagName) + ': ' + x.textContent.trim().slice(0, 30)); } };
    const el = document.querySelector('.mlib'); scan(el); const sec = document.querySelector('[data-music="section"]'); if (sec) scan(sec);
    const list = el.querySelector('.mlib-list'), hs = list.scrollWidth > list.clientWidth + 1, hb = [...el.querySelectorAll('.mlib-foot .acts .btn')].map((b) => Math.round(b.getBoundingClientRect().height));
    return { bad, hs, hb };
  });
  const lay1 = await layout();
  ok(!lay1.bad.length && !lay1.hs && new Set(lay1.hb).size === 1, `the window's layout: nothing clipped${lay1.bad.length ? ' — ' + lay1.bad.join(' | ') : ''}, no sideways scroll, Cancel / ✓ the same height (${lay1.hb.join('/')} px)`);
  await shot('library-all-1440x900');
  // every category has songs
  const cats = await ev(async () => { const out = {}; for (const b of [...document.querySelectorAll('[data-mlib-cat]')]) { b.click(); await new Promise((r) => setTimeout(r, 30)); out[b.dataset.mlibCat] = document.querySelectorAll('.mlib-row').length; } document.querySelector('[data-mlib-cat="all"]').click(); return out; });
  const need = ['viral', 'upbeat', 'beauty', 'luxury', 'chill', 'arabic', 'corporate', 'emotional', 'hype', 'lofi'];
  ok(need.every((k) => cats[k] >= 4) && cats.all === LIB.length && 'user' in cats, `every category has songs: ${Object.entries(cats).map(([k, v]) => k + ' ' + v).join(' · ')}`);
  for (const [k, name] of [['viral', 'library-viral-1440x900'], ['arabic', 'library-arabic-1440x900']]) { await page.click(`[data-mlib-cat="${k}"]`); await shot(name); }
  await page.click('[data-mlib-cat="viral"]');
  // click a song: it plays alone, nothing in the project changes
  const pick = await ev(() => document.querySelectorAll('.mlib-row')[1].dataset.mlibTrack);
  await page.click(`.mlib-row[data-mlib-track="${pick}"] .nm`);
  await page.waitForTimeout(1200);
  const p1 = await ev(() => { const s = window.L.musicLibraryState(); return { playing: s.playing, paused: s.paused, time: s.time, src: s.src, edPlaying: window.ED.playing, J: window.J(), steps: window.steps() }; });
  ok(p1.playing === pick && !p1.paused && p1.time > 0.5 && p1.src === `library/music/${pick}.m4a` && !p1.edPlaying && p1.J === J0 && p1.steps === st0, `a click on “${pick}” plays THAT song alone (${p1.time.toFixed(2)} s in, the reel paused); the project is byte-identical and the undo stack untouched`);
  await shot('library-preview-1440x900');
  // switch: one preview at a time
  const pick2 = await ev(() => document.querySelectorAll('.mlib-row')[3].dataset.mlibTrack);
  await page.click(`.mlib-row[data-mlib-track="${pick2}"] .nm`); await page.waitForTimeout(700);
  const p2 = await ev(() => ({ s: window.L.musicLibraryState(), playingRows: document.querySelectorAll('.mlib-row.playing').length }));
  ok(p2.s.playing === pick2 && p2.s.src === `library/music/${pick2}.m4a` && p2.playingRows === 1 && !p2.s.paused, `another click switches the preview to “${pick2}” — one preview at a time (${p2.playingRows} row playing)`);
  // preview with my video
  await ev(() => window.ED.seek(2));
  await page.click('[data-mlib-with]'); await page.waitForTimeout(1200);
  const pv = await ev(() => { const ed = window.ED, s = window.L.musicLibraryState(), m = window.P.findMedia(ed.project, window.mus().media), el = ed.media.element(m.id); return { edPlaying: ed.playing, hush: ed.preview.hushMusic, musicMuted: el.muted, t: ed.t, song: s.time, vol: s.volume, it0: window.mus().t0, J: window.J() }; });
  ok(pv.edPlaying && pv.hush && pv.musicMuted && Math.abs((pv.t - pv.it0) - pv.song) < 0.35 && Math.abs(pv.vol - 0.5) < 1e-6 && pv.J === J0, `Preview with my video: the reel plays (${pv.t.toFixed(2)} s) with the timeline's music hushed and the song under it at ${pv.song.toFixed(2)} s (in step with the playhead) at the clip's volume ${Math.round(pv.vol * 100)} %; project unchanged`);
  await shot('library-with-video-1440x900');
  // Esc: everything stops, nothing changes
  await page.keyboard.press('Escape'); await page.waitForTimeout(300);
  const esc = await ev(() => ({ open: window.L.musicLibraryState().open, edPlaying: window.ED.playing, hush: window.ED.preview.hushMusic, J: window.J(), steps: window.steps(), el: !!document.querySelector('.mlib') }));
  ok(!esc.open && !esc.el && !esc.edPlaying && !esc.hush && esc.J === J0 && esc.steps === st0, 'Esc closes it: the song and the reel stop, the music is no longer hushed, the project is byte-identical');
  // Cancel after picking: byte-identical
  await openByDbl(); await page.click('.mlib-row >> nth=5'); await page.waitForTimeout(400); await page.click('[data-mlib-cancel]'); await page.waitForTimeout(200);
  ok((await ev(() => window.J())) === J0 && (await ev(() => window.steps())) === st0 && !(await ev(() => window.L.musicLibraryState().open)), 'Cancel (with a song picked and playing) changes nothing: byte-identical, no undo step');
  // 1280×800: still beside the frame
  await page.setViewportSize({ width: 1280, height: 800 }); await page.waitForTimeout(300);
  await openByDbl();
  const ff2 = await frameFree();
  ok(ff2.free && ff2.inWin, `at 1280×800 the window ${ff2.a.join(',')} leaves the frame ${ff2.b.join(',')} fully visible`);
  await page.click('[data-mlib-cat="beauty"]'); await shot('library-beauty-1280x800');
  await page.keyboard.press('Escape');
  await page.setViewportSize({ width: 1440, height: 900 }); await page.waitForTimeout(300);
  // ✓ Use this song: ONE step, keeps volume, fades, ducking; undo = byte-identical
  const before = await ev(() => { const it = window.mus(); return { v: it.volume, fi: it.fadeIn, fo: it.fadeOut, duck: JSON.stringify(it.duck), t0: it.t0, len: window.P.itemDur(it) }; });
  await openByDbl();
  const use = await ev(() => document.querySelector('[data-mlib-cat="hype"]') && (document.querySelector('[data-mlib-cat="hype"]').click(), document.querySelectorAll('.mlib-row')[0].dataset.mlibTrack));
  await page.click(`.mlib-row[data-mlib-track="${use}"] .nm`); await page.waitForTimeout(300);
  await page.click('[data-mlib-use]');
  await page.waitForFunction((u) => window.P.findMedia(window.ED.project, window.mus().media)?.src === `library/music/${u}.m4a`, use);
  const after2 = await ev(() => { const it = window.mus(); return { v: it.volume, fi: it.fadeIn, fo: it.fadeOut, duck: JSON.stringify(it.duck), t0: it.t0, len: window.P.itemDur(it), steps: window.steps(), label: window.ED.history.undoLabel, open: window.L.musicLibraryState().open }; });
  ok(after2.steps === st0 + 1 && after2.v === before.v && after2.fi === before.fi && after2.fo === before.fo && after2.duck === before.duck && after2.t0 === before.t0 && Math.abs(after2.len - before.len) < 1e-3 && !after2.open, `✓ Use this song → “${use}” replaces the song as ONE undo step (“${after2.label}”), keeping volume ${Math.round(after2.v * 100)} %, fades ${after2.fi}/${after2.fo} s, Auto-duck, position and length`);
  await top(); await shot('inspector-after-replace-1440x900');
  await ev(() => window.ED.history.undo());
  ok((await ev(() => window.J())) === J0, '⌘Z after ✓ Use this song: the project is byte-identical to before');
  // favourites, per company
  await openByDbl();
  const favId = await ev(() => document.querySelectorAll('.mlib-row')[2].dataset.mlibTrack);
  await page.click(`[data-mlib-star="${favId}"]`); await page.waitForTimeout(500);
  await page.keyboard.press('Escape');
  const ws = await ev(() => window.ED.project.workspace), favFile = path.join(STUDIO, 'workspaces', ws, 'music-favourites.json');
  await openByDbl(); await page.waitForSelector('[data-mlib-cat="fav"]'); await page.click('[data-mlib-cat="fav"]');
  const favRows = await ev(() => [...document.querySelectorAll('.mlib-row')].map((r) => r.dataset.mlibTrack));
  ok(fs.existsSync(favFile) && JSON.parse(fs.readFileSync(favFile, 'utf8')).ids.includes(favId) && favRows.length === 1 && favRows[0] === favId, `★ favourite “${favId}” is kept for this company (workspaces/${ws}/music-favourites.json) and listed under ★ Favourites after reopening`);
  // Your music: import a file
  await page.click('[data-mlib-cat="user"]');
  const imp = path.join(OUT, 'My Song.wav');
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=6', '-ar', '44100', imp]);
  await page.setInputFiles('.mlib input[type=file]', imp);
  await page.waitForFunction(() => document.querySelectorAll('.mlib-row').length > 0, null, { timeout: 30000 });
  const userRows = await ev(() => [...document.querySelectorAll('.mlib-row .nm')].map((x) => x.textContent));
  ok(userRows.includes('My Song') && fs.existsSync(path.join(STUDIO, 'library/music/user/My Song.wav')), `Your music → ＋ Import a song… copies the file into library/music/user and lists it (${userRows.join(', ')})`);
  await shot('library-your-music-1440x900');
  await page.keyboard.press('Escape');
  ok((await ev(() => window.J())) === J0, 'favourites and imports never touch the project (byte-identical)');

  // ════════ hygiene ════════
  ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 4).join(' | ') : ''}`);
  ok(!outside.length, `nothing leaves 127.0.0.1${outside.length ? ': ' + outside.slice(0, 3).join(', ') : ''}`);
  await browser.close(); await H.stopEngine(e);
}

(async () => {
  const T0 = Date.now();
  if (want('tracks')) checkTracks();
  if (want('editor')) await editor();
  const line = `\n${failures ? 'FAILED ' + failures : 'ALL PASS'} · ${results.filter((r) => /^PASS/.test(r)).length} passed · ${((Date.now() - T0) / 1000).toFixed(0)} s`;
  console.log(line);
  fs.writeFileSync(path.join(SHOTS, 'check.txt'), results.join('\n') + line + '\n');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
