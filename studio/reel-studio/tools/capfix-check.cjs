#!/usr/bin/env node
// KBB Studio — "Auto captions gives nothing": headless Chromium against the Linux engine, REAL Whisper, through the panel.
//
// The owner, 20 Nov (v1.4.1, his Mac): "I run multiple times auto captions, but nothing generating. why it's not
// working, it's giving nothing" — then "the process finished multiple times, but nothing i can see in the script",
// for Selected clips AND Whole video. His reel: Planet Careers, "IMG_7710 (copy) (copy)" (duplicated twice), two iPhone
// .mov clips of Urdu/Hindi speech, two "Text behind the host" elements and a logo on top; "Heard: Hindi 94%".
//
// What this drives (one engine, one Chromium):
//   reel       his reel as he has it: two Hindi/Urdu .mov clips (portrait rotation tag, AAC 44.1 kHz stereo), two text-
//              behind elements + a logo, saved, duplicated twice from Home (⧉), the copy of the copy opened after a
//              reload. Selected clips (IMG_7814) and Whole video both write Devanagari words into the transcript: the
//              duplicate, the text-behind elements, the .mov sound and the Selected-clips ranges are NOT the defect.
//   gpu        his Mac picks WebGPU for Whisper (Apple GPU: fp16 encoder + q4 decoder) — a path no check could run
//              (this Chromium has no shader-f16) and that, run here on WebGPU, wrote NO words over speech while
//              the CPU path wrote them. Simulated at the worker boundary: the load says 'webgpu' and every decode on it
//              comes back empty, exactly what reached the panel on his Mac. Before the fix: "No captions … no speech
//              heard", the transcript stays "No captions yet". Now: listened again on the CPU, words in the
//              transcript, the diagnostics say so, and the next run goes straight to the CPU.
//   stall      a decode that never answers: the watchdog ends it with a message, "Try again" and "Copy diagnostics"
//              in the panel — never "Writing captions…" forever. Try again then captions the clip.
//   empty      a silent clip: the panel says WHY in place ("its sound is silent"), not a vanished toast.
//   diag       "Copy diagnostics" puts a short report on the clipboard: stage, model, device, per clip the decoded
//              seconds, RMS, words heard / dropped / kept, the error.
// Mutation notes: drop the "WebGPU wrote no words" block in Transcriber.transcribe (stt.js) and `gpu` is red (0
// words); drop the watchdog timer in Transcriber._call and `stall` never ends (the check's own 120 s guard fails it).
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/capfix-check.cjs [--model base]
// needs: models/onnx-community/whisper-{base,small}_timestamped (+ Xenova/mms-tts-hin for the speech), the Linux engine.
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'capfix-check'), SHOTS = path.join(ROOT, 'shots', 'captions-fix');
const MODEL = process.argv.includes('--model') ? process.argv[process.argv.indexOf('--model') + 1] : 'base';
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (c, msg) => { console.log((c ? 'PASS ' : 'FAIL ') + msg); if (!c) failures++; };
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a]);
const DEVA = /[ऀ-ॿ]/;   // Devanagari (Hinglish shows in Roman by default)

// his two clips: Urdu/Hindi speech (MMS Hindi voice), a few seconds each
const CLIPS = {
  IMG_7814: [['hin', 'दोस्तों आज मैं आपको बताऊँगा'], ['hin', 'पोलैंड में काम कैसे मिलेगा']],
  IMG_7801: [['hin', 'प्लैनेट करियर्स आपकी मदद करेगा'], ['hin', 'वीज़ा प्रोसेस बहुत आसान है'], ['hin', 'आज ही हमसे बात कीजिए'], ['hin', 'हमारी टीम आपका इंतज़ार कर रही है']],
};
async function makeMedia() {
  if (Object.keys(CLIPS).every((n) => fs.existsSync(path.join(OUT, n + '.mov'))) && fs.existsSync(path.join(OUT, 'SILENT.mov'))) return;
  const server = require('./r2-serve.cjs'), { chromium } = require('playwright');
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const b = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined });
  const pg = await b.newPage(); pg.setDefaultTimeout(0);
  await pg.goto(`http://127.0.0.1:${server.address().port}/app/captions/dev/hindi.html`); await pg.waitForFunction(() => window.HI);
  // an iPhone clip: landscape pixels + "show this turned 90°", H.264 + AAC 44.1 kHz stereo in a .mov
  const mov = (name, wav, d) => {
    const flat = path.join(OUT, name + '-flat.mov');
    ff('-f', 'lavfi', '-i', `testsrc2=s=640x360:r=30:d=${d}`, '-i', wav, '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-af', 'pan=stereo|c0=c0|c1=c0', '-c:a', 'aac', '-ar', '44100', '-ac', '2', '-t', d, '-f', 'mov', flat);
    ff('-display_rotation', '90', '-i', flat, '-c', 'copy', '-f', 'mov', path.join(OUT, name + '.mov'));
    fs.rmSync(flat);
  };
  for (const [name, parts] of Object.entries(CLIPS)) {
    const r = await pg.evaluate(async (parts) => { const s = await HI.speech(parts); return { wav: HI.wav64(s.audio), dur: s.dur }; }, parts);
    const wav = path.join(OUT, name + '.wav'); fs.writeFileSync(wav, Buffer.from(r.wav, 'base64'));
    mov(name, wav, r.dur.toFixed(2));
  }
  ff('-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '3', path.join(OUT, 'SILENT.wav'));
  mov('SILENT', path.join(OUT, 'SILENT.wav'), '3');
  await b.close(); server.close();
}

(async () => {
  await makeMedia();
  const STUDIO = H.makeStudio();
  const link = (src, dst) => { if (!fs.existsSync(src)) return; if (fs.statSync(src).isDirectory()) { fs.mkdirSync(dst, { recursive: true }); for (const f of fs.readdirSync(src)) link(path.join(src, f), path.join(dst, f)); } else if (!/\.part$/.test(src)) fs.linkSync(fs.realpathSync(src), dst); };
  link(path.join(ROOT, 'models', 'onnx-community'), path.join(STUDIO, 'models', 'onnx-community'));
  link(path.join(ROOT, 'models', 'NewComer00'), path.join(STUDIO, 'models', 'NewComer00'));
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  const page = await context.newPage();
  page.setDefaultTimeout(240000);
  const errors = [], offsite = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/^\[[\d:.]+\] \[BoxParser\]/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(r.url())) offsite.push(r.url()); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  const ev = (fn, a) => page.evaluate(fn, a);
  const shot = async (name) => { await page.mouse.move(5, 895); await page.waitForTimeout(400); await page.screenshot({ path: path.join(SHOTS, `${name}-1440.png`) }); };

  const setup = () => ev(async (model) => {
    const ed = window.kbbEditor, E = ed.engine;
    const orig = E.modelsStatus.bind(E);
    E.modelsStatus = async () => { const st = await orig(); for (const m of st.models) if (m.id === 'whisper-' + model) m.installed = true; return st; };
    window.ED = ed; window.A = await import('/app/captions/auto.js'); window.STT = await import('/app/captions/stt.js');
    window.clips = () => ed.project.tracks.find((t) => t.kind === 'video').items.slice().sort((a, b) => a.t0 - b.t0).map((c) => ({ id: c.id, t0: c.t0, t1: c.t0 + (c.out - c.in) / (c.speed || 1), name: ed.project.media.find((m) => m.id === c.media)?.name }));
    window.wordsIn = (a, b) => (ed.project.captions?.words || []).filter((w) => (w.t0 + w.t1) / 2 >= a && (w.t0 + w.t1) / 2 < b).map((w) => w.w);
    window.toasts = [];
    new MutationObserver(() => { for (const t of document.querySelectorAll('.toast')) if (!t.__seen) { t.__seen = 1; window.toasts.push(t.className.replace('toast ', '') + ': ' + t.textContent); } }).observe(document.body, { childList: true, subtree: true });
    ed.showSide('captions');
    const s = document.querySelector('.rs-cap-in[title="Speech model"]'); s.value = model; s.dispatchEvent(new Event('change'));
  }, MODEL);
  /** press Auto captions with this scope; wait for the run (and its word sync) to end */
  const run = async (scope, sel = null, guard = 240000) => {
    const t = Date.now();
    await ev(({ scope, sel }) => {
      window.toasts = []; window.labels = [];
      if (sel) ED.select(sel === 'none' ? [] : [window.clips().find((c) => c.name.startsWith(sel)).id]);
      const s = document.querySelector('[data-k=capScope]'); s.value = scope; s.dispatchEvent(new Event('change'));
      window.lt = setInterval(() => { const l = document.querySelector('.rs-cap-barlabel')?.textContent; if (l && window.labels[window.labels.length - 1] !== l) window.labels.push(l); }, 100);
    }, { scope, sel });
    await page.click('.rs-cap-btn.primary');
    let ended = true;
    await page.waitForFunction(() => !document.querySelector('.rs-cap-btn.primary').disabled, null, { timeout: guard }).catch(() => { ended = false; });
    if (ended) await page.waitForFunction(() => /^Sync words/.test(ED.history.undoLabel || '') || !(ED.project.captions?.words?.length), null, { timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(600);
    const r = await ev(() => { clearInterval(window.lt); return {
      labels: window.labels, toasts: window.toasts.slice(), transcript: document.querySelector('.rs-cap-list')?.textContent || '',
      fail: document.querySelector('[data-cap-fail]')?.hidden === false ? document.querySelector('.rs-cap-failmsg').textContent : null,
      diag: window.A.captionDiagnostics(), words: (ED.project.captions?.words || []).length,
      spinning: !document.querySelector('.rs-cap-progress').hidden };
    });
    r.ended = ended; r.secs = (Date.now() - t) / 1000;
    return r;
  };

  try {
    // ── his reel: two clips, text behind ×2, a logo; duplicated twice from Home ──
    await page.goto(e.url + '/app/');
    await page.waitForSelector('.home', { timeout: 30000 });
    await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'IMG_7814.mov'));
    await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    await ev(async (b64) => { const b = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)); await window.kbbEditor.importFiles([new File([b], 'IMG_7801.mov', { type: 'video/quicktime' })]); }, fs.readFileSync(path.join(OUT, 'IMG_7801.mov')).toString('base64'));
    await page.waitForFunction(() => window.kbbEditor.project.tracks.find((t) => t.kind === 'video').items.length === 2);
    await ev(async () => {
      const ed = window.kbbEditor, TC = await import('/app/themes/textbehind-cmds.js'), EL = await import('/app/themes/el2-cmds.js');
      TC.addTextBehind(ed.context(), { t0: 0.5, dur: 4, code: 'poland' }); ed.history.seal();
      TC.addTextBehind(ed.context(), { t0: 7, dur: 5, code: 'germany' }); ed.history.seal();
      EL.addLogo(ed.context(), 'circle'); ed.history.seal();
      ed.project.name = 'IMG_7710'; await ed.save();
      await ed.goHome();
    });
    await page.waitForSelector('.recent .dup');
    await page.click('.recent .dup'); await page.waitForSelector('#dup-open'); await page.click('#dup-open');
    await page.waitForFunction(() => window.kbbEditor?.project?.name === 'IMG_7710 (copy)');
    await ev(() => window.kbbEditor.goHome());
    await page.waitForFunction(() => [...document.querySelectorAll('.recent b')].some((b) => b.textContent === 'IMG_7710 (copy)'));
    const idx = await ev(() => [...document.querySelectorAll('.recent b')].findIndex((b) => b.textContent === 'IMG_7710 (copy)'));
    await page.locator('.recent .dup').nth(idx).click(); await page.waitForTimeout(800); await page.locator('#dup-open').last().click();
    await page.waitForFunction(() => window.kbbEditor?.project?.name === 'IMG_7710 (copy) (copy)');
    await page.reload(); await page.waitForFunction(() => window.kbbEditor?.project?.name === 'IMG_7710 (copy) (copy)');
    await setup();
    const reel = await ev(() => ({ media: ED.project.media.map((m) => m.src), tb: ED.project.tracks.flatMap((t) => t.items).filter((i) => i.style?.el === 'tbehind').length }));
    ok(reel.media.every((s) => /projects\/[^/]+\/media\//.test(s)) && reel.tb === 2, `his reel, duplicated twice and reopened: "IMG_7710 (copy) (copy)", its own media (${reel.media.map((s) => s.split('/').pop()).join(', ')}), ${reel.tb} text-behind elements`);

    // ── reel: Selected clips, then Whole video, on the CPU path ──
    const sel = await run('selected', 'IMG_7814');
    const c = await ev(() => window.clips());
    const w1 = await ev(({ a, b }) => window.wordsIn(a, b), { a: c[0].t0, b: c[0].t1 });
    ok(w1.length >= 4 && w1.some((w) => DEVA.test(w) || /^[a-z]{3,}$/i.test(w)) && sel.transcript.includes(w1[0]), `Selected clips (IMG_7814) on the duplicated reel: ${w1.length} words in the transcript: "${w1.join(' ')}" (${sel.secs.toFixed(0)} s, ${sel.diag.device})`);
    const all = await run('all', 'none');
    const w2 = await ev(({ a, b }) => window.wordsIn(a, b), { a: c[1].t0, b: c[1].t1 });
    ok(all.words >= 12 && w2.length >= 8 && !all.fail, `Whole video: ${all.words} words, IMG_7801 "${w2.slice(0, 8).join(' ')}…" (${all.secs.toFixed(0)} s)`);
    await shot('01-duplicated-reel-captioned');

    // ── gpu: his Mac's WebGPU writes no words (simulated at the worker boundary) ──
    await ev(async () => {
      ED.history.apply(window.A.captionsWithItem(ED.project, { ...ED.project.captions, words: [], lines: [] }, ED.ops, { label: 'Clear' }));
      window.STT.trustGpuAgain();
      const T = window.STT.Transcriber.prototype;
      window.realLoad = T.load; window.realCall = T._call;
      // the load reports 'webgpu' (as on an Apple-silicon Mac); a decode on it comes back with no words
      T.load = async function (k, o = {}) { const r = await window.realLoad.call(this, k, { prefer: 'wasm' }); return (o.prefer || 'auto') === 'auto' && !window.STT.gpuDistrusted() ? { ...r, device: 'webgpu', sig: 'gpu' } : r; };
      T._call = function (msg, tr, to) { if (msg.type === 'run' && this.used === 'webgpu') { window.gpuRuns = (window.gpuRuns || 0) + 1; return Promise.resolve({ words: [], text: '', prompted: false }); } return window.realCall.call(this, msg, tr, to); };
      window.A.transcriber().dispose();
    });
    const gpu = await run('all', 'none');
    const gw = await ev(() => ({ n: ED.project.captions?.words?.length || 0, deva: (ED.project.captions?.words || []).some((w) => /[ऀ-ॿ]|^[a-z]{3,}$/i.test(w.w)), runs: window.gpuRuns || 0, note: window.STT.gpuDistrusted() }));
    ok(gw.runs > 0 && gw.n >= 12 && gw.deva && !gpu.fail, `a GPU that writes no words (as on his Mac): ${gw.runs} empty GPU decodes, then listened again on the CPU -> ${gw.n} words in the transcript (before: 0 and "no speech heard")`);
    ok(/CPU/.test(gpu.diag.fallback || '') && gpu.diag.device === 'wasm' && /WebGPU wrote no words/.test(gw.note || ''), `…the diagnostics say so ("${gpu.diag.fallback}") and this Mac is remembered: "${(gw.note || '').split(' · ')[0]}"`);
    await shot('02-gpu-wrote-nothing-cpu-captions');
    const before = await ev(() => window.gpuRuns);
    const again = await run('selected', 'IMG_7814');
    ok((await ev(() => window.gpuRuns)) === before && again.diag.device === 'wasm' && again.words > 0, `the next run goes straight to the CPU (no GPU decode, ${again.words} words)`);
    await ev(() => { const T = window.STT.Transcriber.prototype; T.load = window.realLoad; T._call = window.realCall; window.STT.trustGpuAgain(); window.A.transcriber().dispose(); });

    // ── stall: a decode that never answers ──
    await ev(() => {
      const T = window.STT.Transcriber.prototype; window.realSpawn = T._spawn;
      T._spawn = function () { window.realSpawn.call(this); const w = this.worker, post = w.postMessage.bind(w); w.postMessage = (m, t) => { if (m.type === 'run' && window.dropRuns) return; post(m, t); }; };
      window.dropRuns = true; window.A.transcriber().dispose(); window.A.transcriber().timeoutScale = 0.1;
    });
    const st = await run('selected', 'IMG_7814', 120000);
    ok(st.ended && !st.spinning && /stopped answering/.test(st.fail || '') && /writing/.test(st.diag.error || ''), `a decode that never answers ends by itself (${st.secs.toFixed(0)} s): "${st.fail}" — no "Writing captions…" forever`);
    const btns = await ev(() => [...document.querySelectorAll('[data-cap-fail] button')].map((b) => b.textContent));
    ok(btns.some((b) => /Try again/.test(b)) && btns.some((b) => /Copy diagnostics/.test(b)), `…with ${btns.join(' and ')} beside it`);
    await shot('03-stalled-try-again');
    await ev(() => { window.dropRuns = false; window.A.transcriber().timeoutScale = 1; window.toasts = []; });
    await page.click('[data-x=cap-retry]');
    await page.waitForFunction(() => !document.querySelector('.rs-cap-btn.primary').disabled);
    await page.waitForTimeout(600);
    const re = await ev(() => ({ n: (window.wordsIn(window.clips()[0].t0, window.clips()[0].t1)).length, fail: document.querySelector('[data-cap-fail]').hidden }));
    ok(re.n >= 4 && re.fail, `Try again captions the clip (${re.n} words) and the message goes`);
    await ev(() => { window.STT.Transcriber.prototype._spawn = window.realSpawn; });

    // ── empty: a silent clip says why, in place ──
    await ev(async (b64) => { const b = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)); await ED.importFiles([new File([b], 'SILENT.mov', { type: 'video/quicktime' })]); }, fs.readFileSync(path.join(OUT, 'SILENT.mov')).toString('base64'));
    await page.waitForFunction(() => window.clips().length === 3);
    const si = await run('selected', 'SILENT.mov');
    ok(/SILENT\.mov: (its sound is silent|it has no sound)/.test(si.fail || ''), `a clip with no voice: the panel says why in place: "${si.fail}"`);
    await shot('04-silent-clip-says-why');

    // ── diag: Copy diagnostics ──
    await page.click('[data-x=cap-diag]');
    await page.waitForTimeout(300);
    const clip = await ev(() => navigator.clipboard.readText());
    fs.writeFileSync(path.join(OUT, 'diagnostics.txt'), clip);
    ok(/Auto captions diagnostics/.test(clip) && /model base|model small/.test(clip) && /device wasm/.test(clip) && /SILENT\.mov: 3\.0 s decoded, RMS 0\.0000/.test(clip) && /stage reached/.test(clip),
      `Copy diagnostics puts a short report on the clipboard (${clip.split('\n').length} lines): ${clip.split('\n').slice(1, 4).join(' | ')}`);

    ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}`);
    ok(!offsite.length, `no request leaves 127.0.0.1${offsite.length ? ': ' + offsite.slice(0, 3).join(' ') : ''}`);
  } catch (err) { console.error(err); failures++; }
  finally { await browser.close(); await H.stopEngine(e); fs.rmSync(STUDIO, { recursive: true, force: true }); }
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})();
