#!/usr/bin/env node
// KBB Studio — captions clip by clip: headless Chromium against the Linux engine, REAL Whisper, through the panel.
//
// The owner, 19 Nov: "i have three videos, two has okay, but the third video captions are not generating, it says
// nothing to heard, and that's the normal video with original voice. and in total script when i write the script
// myself and extend to whole video, it's not showing the captions on the third clip. i want each captions bar /
// element in the timeline must be separate, not one!"
//
// What was wrong, and what this drives:
//   heard      every clip was mixed into ONE soundtrack and listened to at once: the loudest clips set the quiet/loud
//              floor and the language for all of them, and a stereo phone recording whose two sides are out of phase
//              averaged to near silence. Clip 3 here is both (a normal voice, recorded quiet, sides out of phase):
//              before the fix it came back "no speech heard". Now each clip is listened to on its own, at a speech
//              level, from the louder side when the average cancels.
//   bars       one caption bar per clip, spanning exactly that clip (named after it); they follow their clip through
//              a move, a split (the new piece gets its own bar), a delete (the bar goes with it), and undo.
//   script     the Script tab lists the script clip by clip; a clip with no captions has a box: what is typed there
//              becomes that clip's captions (and its bar), synced to its voice; the other clips' words stay.
// usage: bash tools/r2-fetch-test-models.sh && bash tools/s-fetch-models.sh &&
//        NODE_PATH=/home/user/kbbstore/node_modules node tools/clipcap-check.cjs [--model small]
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'clipcap-check'), SHOTS = path.join(ROOT, 'shots', 'clipcap');
const MODEL = process.argv.includes('--model') ? process.argv[process.argv.indexOf('--model') + 1] : 'base';
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (c, msg) => { console.log((c ? 'PASS ' : 'FAIL ') + msg); if (!c) failures++; };
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a]);

// name, sentence, voice, seconds, colour, how it was recorded
const CLIPS = [
  ['CLIP_A', 'Welcome to Planet Careers we help you find work abroad', 'slt', 5.0, 'teal', 'normal'],
  ['CLIP_B', 'Book a free consultation with our team today', 'kal', 4.6, 'navy', 'normal'],
  // a normal voice, recorded about 30 dB quieter, the right side out of phase with the left (some lapel / phone mics)
  ['CLIP_C', 'Nursing jobs in Germany are open now apply this week', 'awb', 5.2, 'purple', 'quiet-inverted'],
];
function makeMedia() {
  for (const [n, text, voice, d, col, how] of CLIPS) {
    const mov = path.join(OUT, n + '.mov'); if (fs.existsSync(mov)) continue;
    const wav = path.join(OUT, n + '.wav');
    const pan = how === 'normal' ? 'pan=stereo|c0=c0|c1=c0' : 'pan=stereo|c0=0.03*c0|c1=-0.03*c0';
    ff('-f', 'lavfi', '-i', `flite=text='${text}':voice=${voice}`, '-af', `adelay=400,apad,${pan}`, '-t', String(d), '-ar', '44100', wav);
    ff('-f', 'lavfi', '-i', `color=c=${col}:s=640x360:r=30:d=${d}`, '-i', wav, '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-ar', '44100', '-ac', '2', '-t', String(d), '-f', 'mov', mov);
  }
}

(async () => {
  makeMedia();
  const STUDIO = H.makeStudio();
  const link = (src, dst) => { if (!fs.existsSync(src)) return; if (fs.statSync(src).isDirectory()) { fs.mkdirSync(dst, { recursive: true }); for (const f of fs.readdirSync(src)) link(path.join(src, f), path.join(dst, f)); } else if (!/\.part$/.test(src)) fs.linkSync(fs.realpathSync(src), dst); };
  link(path.join(ROOT, 'models', 'onnx-community'), path.join(STUDIO, 'models', 'onnx-community'));
  link(path.join(ROOT, 'models', 'NewComer00'), path.join(STUDIO, 'models', 'NewComer00'));
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(0);
  const errors = [], offsite = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/^\[[\d:.]+\] \[BoxParser\]/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(r.url())) offsite.push(r.url()); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  const ev = (fn, a) => page.evaluate(fn, a);
  const shot = async (name) => { await page.mouse.move(5, 895); await page.waitForTimeout(500); await page.screenshot({ path: path.join(SHOTS, `${name}-1440.png`) }); };

  try {
    await page.goto(e.url + '/app/');
    await page.waitForSelector('.home', { timeout: 30000 });
    await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'CLIP_A.mov'));
    await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    await ev(async (model) => {
      const ed = window.kbbEditor, E = ed.engine;
      const orig = E.modelsStatus.bind(E);
      E.modelsStatus = async () => { const st = await orig(); for (const m of st.models) if (m.id === 'whisper-' + model) m.installed = true; return st; };
      window.ED = ed; window.EO = await import('/app/editor/edit-ops.js');
      window.clips = () => ed.project.tracks.find((t) => t.kind === 'video').items.slice().sort((a, b) => a.t0 - b.t0).map((c) => ({ id: c.id, t0: c.t0, t1: c.t0 + (c.out - c.in) / (c.speed || 1), name: ed.project.media.find((m) => m.id === c.media)?.name }));
      window.bars = () => ed.project.tracks.find((t) => t.kind === 'caption').items.filter((i) => i.type === 'caption').map((i) => ({ id: i.id, t0: +i.t0.toFixed(3), t1: +(i.t0 + (i.out - i.in) / (i.speed || 1)).toFixed(3), clip: i.clip, name: i.name })).sort((a, b) => a.t0 - b.t0);
      window.wordsIn = (a, b) => (ed.project.captions?.words || []).filter((w) => (w.t0 + w.t1) / 2 >= a && (w.t0 + w.t1) / 2 < b).map((w) => w.w);
      window.toasts = [];
      new MutationObserver(() => { for (const t of document.querySelectorAll('.toast')) if (!t.__seen) { t.__seen = 1; window.toasts.push(t.className.replace('toast ', '') + ': ' + t.textContent); } }).observe(document.body, { childList: true, subtree: true });
      ed.showSide('captions');
      const s = document.querySelector('.rs-cap-in[title="Speech model"]'); s.value = model; s.dispatchEvent(new Event('change'));
    }, MODEL);
    await ev(async (files) => {
      for (const [name, b64] of files) { const b = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)); await ED.importFiles([new File([b], name, { type: 'video/quicktime' })]); }
    }, ['CLIP_B', 'CLIP_C'].map((n) => [n + '.mov', fs.readFileSync(path.join(OUT, n + '.mov')).toString('base64')]));
    await page.waitForFunction(() => window.clips().length === 3);
    const cl = await ev(() => window.clips());

    // ── 1. whole video: every clip heard, the quiet out-of-phase one too ──
    await ev(() => { ED.select([]); window.toasts = []; });
    await page.click('.rs-cap-btn.primary');
    await page.waitForFunction(() => !document.querySelector('.rs-cap-btn.primary').disabled);
    await page.waitForFunction(() => ED.history.undoLabel === 'Sync words to the voice', null, { timeout: 180000 }).catch(() => {});
    await page.waitForTimeout(500);
    const per = [];
    for (const c of cl) per.push(await ev(({ a, b }) => window.wordsIn(a, b), { a: c.t0, b: c.t1 }));
    const toasts = await ev(() => window.toasts.slice());
    ok(per[0].length >= 4 && per[1].length >= 4, `clips 1 and 2 captioned: "${per[0].join(' ')}" / "${per[1].join(' ')}"`);
    ok(per[2].length >= 4 && per[2].some((w) => /nurs|germany|jobs|apply|week/i.test(w)),
      `clip 3 (a normal voice recorded ~30 dB quieter, its two sides out of phase) is heard: "${per[2].join(' ')}"`);
    ok(!toasts.some((t) => /no speech heard/i.test(t)), `no "no speech heard" (toasts: ${toasts.join(' | ')})`);

    // ── 2. one caption bar per clip ──
    let bars = await ev(() => window.bars());
    const fits = bars.length === 3 && cl.every((c, k) => bars[k].clip === c.id && Math.abs(bars[k].t0 - c.t0) < 2e-3 && Math.abs(bars[k].t1 - c.t1) < 2e-3);
    ok(fits, `three caption bars, each exactly its clip: ${bars.map((b) => `${b.name} ${b.t0}–${b.t1}`).join(' · ')}`);
    await ev(() => ED.seek(window.clips()[2].t0 + 2.4)); await page.waitForTimeout(500);
    await shot('clipcap-01-three-bars');
    // the line on screen during clip 3 is drawn by clip 3's bar
    const onC = await ev(() => { const t = ED.t, p = ED.project; const line = p.captions.lines.find((L) => t >= L.t0 && t < L.t1); const bar = window.bars().find((b) => t >= b.t0 && t < b.t1); return { line: line ? p.captions.words.slice(line.wordIdx[0], line.wordIdx[1] + 1).map((w) => w.w).join(' ') : null, bar: bar?.name }; });
    ok(!!onC.line && /CLIP_C/.test(onC.bar || ''), `during clip 3 the line "${onC.line}" is on screen, on bar "${onC.bar}"`);

    // ── 3. the bars follow their clips ──
    // split clip 2 in the middle: the new piece gets its own bar
    const mid = (cl[1].t0 + cl[1].t1) / 2;
    await ev((t) => { const r = EO.splitCmd(ED.project, [], t); ED.apply(r.cmd); }, mid);
    bars = await ev(() => window.bars());
    let cs = await ev(() => window.clips());
    ok(bars.length === 4 && cs.every((c) => bars.some((b) => b.clip === c.id && Math.abs(b.t0 - c.t0) < 2e-3 && Math.abs(b.t1 - c.t1) < 2e-3)),
      `split clip 2: ${cs.length} clips, ${bars.length} bars, each on its own clip (${bars.map((b) => `${b.t0}–${b.t1}`).join(', ')})`);
    ok(await ev(() => ED.history.undoLabel) === 'Split', 'the split and its bars are ONE undo step');
    await ev(() => ED.history.undo());
    bars = await ev(() => window.bars());
    ok(bars.length === 3, `undo: back to 3 bars`);
    // ripple-delete the first 1.5 s of clip 1: every bar slides with its clip
    await ev(() => ED.apply(EO.rangeCmd(ED.project, 0.2, 1.7, { ripple: true })));
    bars = await ev(() => window.bars()); cs = await ev(() => window.clips());
    ok(cs.every((c) => bars.some((b) => b.clip === c.id && Math.abs(b.t0 - c.t0) < 2e-3 && Math.abs(b.t1 - c.t1) < 2e-3)) && bars.length === cs.length,
      `ripple delete in clip 1: the bars follow (${bars.map((b) => `${b.t0}–${b.t1}`).join(', ')})`);
    await ev(() => ED.history.undo());
    // delete clip 3: its bar goes with it
    await ev(() => { const c = window.clips()[2]; ED.apply(ED.ops.removeItems([c.id])); });
    bars = await ev(() => window.bars());
    ok(bars.length === 2 && !bars.some((b) => /CLIP_C/.test(b.name)), `delete clip 3: its bar goes with it (${bars.map((b) => b.name).join(', ')})`);
    await ev(() => ED.history.undo());
    ok((await ev(() => window.bars())).length === 3, 'undo: clip 3 and its bar are back');
    // the owner deletes one bar himself: it stays deleted through a later edit
    const gone = await ev(() => { const b = window.bars()[1]; ED.apply(ED.ops.removeItems([b.id])); return b.clip; });
    await ev(() => ED.apply(EO.rangeCmd(ED.project, 0.2, 0.6, { ripple: true })));   // (splits clip 1 around the hole: its new piece gets a bar)
    bars = await ev(() => window.bars()); cs = await ev(() => window.clips());
    const bClip = cs.find((c) => c.id === gone);
    ok(!!bClip && !bars.some((b) => b.t0 < bClip.t1 - 1e-3 && b.t1 > bClip.t0 + 1e-3) && bars.length === cs.length - 1,
      `a bar the owner deleted (clip 2's) stays deleted through a later edit; the other ${bars.length} clips keep theirs`);
    await ev(() => { ED.history.undo(); ED.history.undo(); });

    // ── 4. a typed script on a clip with no captions ──
    // clip 3's captions removed (as the owner had it): the Script tab shows a box under clip 3
    await ev(async () => {
      const C = await import('/app/captions/auto.js'), p = ED.project, c = window.clips()[2];
      const cap = { ...p.captions, words: p.captions.words.filter((w) => (w.t0 + w.t1) / 2 < c.t0) };
      cap.lines = (await import('/app/captions/model.js')).buildLines(cap.words, cap.style, p.canvas);
      ED.history.apply(C.captionsWithItem(p, cap, ED.ops, { label: 'Edit script' }));
    });
    ok((await ev(() => window.bars())).length === 2, 'clip 3 without captions has no bar');
    await page.click(".rs-cap-tabs [data-tab=script]");
    await page.waitForSelector('.rs-cap-typed');
    const ui = await ev(() => ({ heads: [...document.querySelectorAll('.rs-cap-clip')].map((x) => x.textContent), box: document.querySelector('.rs-cap-typed')?.closest('.rs-cap-pwrap')?.previousElementSibling?.textContent, ph: document.querySelector('.rs-cap-typed')?.dataset.ph }));
    ok(ui.heads.length === 3 && /CLIP_C/.test(ui.box || ''), `the Script tab lists the script clip by clip (${ui.heads.join(' | ')}), with a box under clip 3: "${ui.ph}"`);
    await shot('clipcap-02-script-box');
    const typed = 'Nursing jobs in Germany are open now, apply this week.';
    await ev(() => { window.toasts = []; });
    await page.click('.rs-cap-typed');
    await page.keyboard.type(typed);
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => /^(Auto captions · typed script|Sync words to the voice)$/.test(ED.history.undoLabel || ''), null, { timeout: 60000 });
    await page.waitForFunction(() => ED.history.undoLabel === 'Sync words to the voice', null, { timeout: 180000 }).catch(() => {});
    await page.waitForTimeout(500);
    const c3 = (await ev(() => window.clips()))[2];
    const w3 = await ev(({ a, b }) => (ED.project.captions.words || []).filter((w) => (w.t0 + w.t1) / 2 >= a && (w.t0 + w.t1) / 2 < b).map((w) => ({ w: w.w, t0: w.t0, t1: w.t1 })), { a: c3.t0, b: c3.t1 });
    bars = await ev(() => window.bars());
    ok(w3.map((w) => w.w).join(' ') === typed, `the typed script is clip 3's captions, word for word: "${w3.map((w) => w.w).join(' ')}"`);
    ok(w3.length && w3[0].t0 >= c3.t0 && w3[w3.length - 1].t1 <= c3.t1 + 1e-3, `…all inside clip 3 (${w3[0]?.t0}–${w3[w3.length - 1]?.t1} s, clip ${c3.t0.toFixed(2)}–${c3.t1.toFixed(2)})`);
    // the voice starts 0.4 s into the clip: synced words do not start at the clip's first frame
    ok(w3[0].t0 - c3.t0 > 0.2 && w3[0].t0 - c3.t0 < 1.2, `…and on the voice: the first word at +${(w3[0].t0 - c3.t0).toFixed(2)} s (the voice starts at +0.40 s)`);
    ok(bars.length === 3 && bars.some((b) => b.clip === c3.id && Math.abs(b.t0 - c3.t0) < 2e-3), `clip 3 got its own bar back (${bars.map((b) => b.name).join(', ')})`);
    const kept = await ev(({ a }) => window.wordsIn(0, a), { a: c3.t0 });
    ok(kept.join(' ') === [...per[0], ...per[1]].join(' '), `clips 1 and 2 kept their ${kept.length} words`);
    await shot('clipcap-03-typed-script');
    // undo: the typed script and its bar go in one step (after the sync's own step)
    const und = await ev(() => { const back = []; while (/^(Sync words|Auto captions)/.test(ED.history.undoLabel || '')) { back.push(ED.history.undoLabel); ED.history.undo(); if (/^Auto captions/.test(back[back.length - 1])) break; } return { back, bars: window.bars().length }; });
    ok(und.back[und.back.length - 1] === 'Auto captions · typed script' && und.bars === 2, `undo (${und.back.join(' → ')}): clip 3's script and its bar are gone again`);

    // ── 5. save → reload keeps the bars ──
    await ev(() => { ED.history.redo(); ED.history.redo(); });
    const saved = await ev(async () => { await ED.save(); return JSON.stringify(window.bars()); });
    await page.reload(); await page.waitForFunction(() => window.kbbEditor?.project?.captions?.words?.length > 0, null, { timeout: 60000 });
    const back = await ev(() => JSON.stringify(window.kbbEditor.project.tracks.find((t) => t.kind === 'caption').items.filter((i) => i.type === 'caption').map((i) => ({ id: i.id, t0: +i.t0.toFixed(3), t1: +(i.t0 + (i.out - i.in) / (i.speed || 1)).toFixed(3), clip: i.clip, name: i.name })).sort((a, b) => a.t0 - b.t0)));
    ok(back === saved, `save → reload: the ${JSON.parse(back).length} bars come back identical`);

    ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}`);
    ok(!offsite.length, `no request leaves 127.0.0.1${offsite.length ? ': ' + offsite.slice(0, 3).join(' ') : ''}`);
  } catch (err) { console.error(err); failures++; }
  finally { await browser.close(); await H.stopEngine(e); }
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})();
