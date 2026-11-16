'use strict';
// KBB Studio · R3 · automation check.  NODE_PATH=/home/user/kbbstore/node_modules node tools/r3-check.cjs [--no-browser]
// Builds test media with ffmpeg (talking-tone sentences + known silences, 4 scenes with a moving skin-tone subject),
// asserts the analysis/planning numbers in Node, then drives app/auto in headless Chromium through R1's core
// (worker, layers, one-step undo, MediaPipe offline) and writes pictures to shots/r3/.
const fs = require('fs'), path = require('path'), os = require('os'), http = require('http'), { execFileSync } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), SH = path.join(ROOT, 'shots/r3');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'r3-check-'));
const results = []; let fails = 0;
const ok = (name, cond, detail = '') => { results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); if (!cond) fails++; };
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });

// ── test speech: words as voiced bursts, sentences separated by known pauses ──
function rng(s) { return () => { s ^= s << 13; s >>>= 0; s ^= s >>> 17; s ^= s << 5; s >>>= 0; return s / 4294967296; }; }
const SENTENCES = [
  'Have you ever wondered why your skin looks dull?', 'Today I am unboxing my new order from K-Beauty Bliss.', 'The box arrived in two days.',
  'First, the snail mucin essence, my absolute favourite.', 'The texture is so lightweight and it absorbs fast.', 'Um, then this gentle cleanser.',
  'It smells like green tea and leaves my skin soft.', 'Next is the sunscreen with SPF fifty.', 'No white cast at all, which is amazing.',
  'I have been using this routine for three weeks.', 'My pores look smaller and my skin is glowing.', 'Honestly, like, I did not expect results so fast.',
  'The serum is the real star of the haul.', 'You only need two drops for your whole face.', 'It layers well under makeup too.',
  'Everything was packed with so much care.', 'They even added a free sample as a gift.', 'This is the best skincare order I made this year.',
  'If you want the same glow, here is what to do.', 'Shop now at the link in bio and order today.',
];
const PAUSES = [0.5, 0.9, 0.35, 1.6, 0.6, 1.1, 0.4, 2.0, 0.7, 0.5, 1.3, 0.8, 0.45, 1.0, 0.6, 1.8, 0.5, 0.9, 0.7, 0.6];
function makeTalk(sr = 16000) {
  const r = rng(7), words = [], truth = []; let t = 0.6;
  for (let s = 0; s < SENTENCES.length; s++) {
    const ws = SENTENCES[s].split(' '), a = t;
    for (const w of ws) { const syl = Math.max(1, (w.match(/[aeiouy]+/gi) || []).length), d = 0.13 * syl + 0.08 + r() * 0.05; words.push({ t0: +t.toFixed(3), t1: +(t + d).toFixed(3), w, conf: 0.95 }); t += d + 0.05 + r() * 0.05; }
    truth.push([a, t - 0.05]); t += PAUSES[s];
  }
  const dur = t + 0.8, n = Math.ceil(dur * sr), x = new Float32Array(n);
  for (let i = 0; i < n; i++) x[i] = (r() * 2 - 1) * 0.0008; // room tone ~ -62 dBFS
  for (const w of words) {
    const a = Math.floor(w.t0 * sr), b = Math.floor(w.t1 * sr), f0 = 120 + r() * 50, amp = 0.15 + r() * 0.12;
    for (let i = a; i < b; i++) { const tt = (i - a) / sr, d = (b - a) / sr, env = Math.sin(Math.PI * tt / d) ** 0.6 * (0.6 + 0.4 * Math.abs(Math.sin(Math.PI * 4.5 * tt))); let v = 0; for (let k = 1; k <= 12; k++) v += Math.sin(2 * Math.PI * f0 * k * tt * (1 + 0.01 * Math.sin(2 * Math.PI * 5 * tt))) / k * (k > 2 && k < 6 ? 1.6 : 1); x[i] += v * env * amp * 0.5; }
  }
  return { x, sr, words, truth, dur };
}
function wavMono(x, sr) { const b = Buffer.alloc(44 + x.length * 2); b.write('RIFF', 0); b.writeUInt32LE(36 + x.length * 2, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22); b.writeUInt32LE(sr, 24); b.writeUInt32LE(sr * 2, 28); b.writeUInt16LE(2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(x.length * 2, 40); for (let i = 0; i < x.length; i++) b.writeInt16LE(Math.round(Math.max(-1, Math.min(1, x[i])) * 32767), 44 + i * 2); return b; }
const f32 = (file, sr) => { const buf = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '1', '-ar', String(sr), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(buf.buffer, buf.byteOffset, buf.length / 4); };

async function node() {
  const A = await import(path.join(ROOT, 'app/auto/lib/audio.js'));
  const V = await import(path.join(ROOT, 'app/auto/lib/video.js'));
  const P = await import(path.join(ROOT, 'app/auto/lib/plan.js'));
  const M = await import(path.join(ROOT, 'app/auto/lib/music.js'));
  const E = await import(path.join(ROOT, 'app/auto/recipes/engine.js'));
  const RC = await import(path.join(ROOT, 'app/auto/recipes/recipes.js'));
  const FX = await import(path.join(ROOT, 'app/auto/lib/effects.js'));
  const talk = makeTalk();
  fs.writeFileSync(path.join(TMP, 'talk.wav'), wavMono(talk.x, talk.sr));
  // 1. VAD + silence removal timing
  const v = A.vad(talk.x, talk.sr);
  let worst = 0; for (const [a, b] of talk.truth) { const s = v.speech.find(x => x[1] > a && x[0] < b); worst = Math.max(worst, s ? Math.max(Math.abs(s[0] - a), Math.abs(s[1] - b)) : 9); }
  ok('VAD finds every sentence, edges within ±80 ms', worst <= 0.08 && v.speech.length === talk.truth.length, `${v.speech.length}/${talk.truth.length} segments, worst edge ${(worst * 1000).toFixed(0)} ms`);
  const fl = A.fillers(talk.words);
  ok('fillers: "Um," hard, "like," soft', fl.some(f => f.kind === 'hard' && /um/i.test(f.text)) && fl.some(f => f.kind === 'soft' && /like/i.test(f.text)), fl.map(f => `${f.text}(${f.kind})`).join(' '));
  ok('Arabic fillers recognised', A.fillers([{ t0: 0, t1: .3, w: 'اممم' }, { t0: .4, t1: .7, w: 'يعني' }, { t0: .8, t1: 1, w: 'الدراسة' }]).map(f => f.kind).join() === 'hard,soft');
  const cuts = P.planCuts({ duration: talk.dur, silences: v.silences, fillers: fl }, { keepPad: 0.12, minSilence: 0.45 });
  const sil = cuts.filter(c => c.kind === 'silence');
  const gaps = [[0, talk.truth[0][0]], ...talk.truth.slice(1).map((t, i) => [talk.truth[i][1], t[0]]), [talk.truth.at(-1)[1], talk.dur]];
  const expectSil = gaps.filter(([a, b]) => b - a >= 0.45);
  const expRemoved = expectSil.reduce((s, [a, b]) => s + (b - a) - (a === 0 ? 0.12 : b >= talk.dur - 1e-3 ? 0.12 : 0.24), 0);
  const edgeErr = sil.length === expectSil.length ? Math.max(...sil.map((c, i) => { const [a, b] = expectSil[i]; return Math.max(Math.abs(c.t0 - (a === 0 ? 0 : a + 0.12)), Math.abs(c.t1 - (b >= talk.dur - 1e-3 ? talk.dur : b - 0.12))); })) : 9;
  const keep = P.keepRanges(talk.dur, sil), removed = talk.dur - keep.reduce((a, [x, y]) => a + y - x, 0);
  ok('silence cuts: one per pause ≥ 0.45 s (short pauses kept)', sil.length === expectSil.length, `${sil.length} cuts, expected ${expectSil.length}`);
  ok('silence removal: every cut edge within ±80 ms of the true pause (minus the 0.12 s kept), total within ±0.6 s', edgeErr <= 0.08 && Math.abs(removed - expRemoved) <= 0.6, `removed ${removed.toFixed(2)} s, expected ${expRemoved.toFixed(2)} s of ${talk.dur.toFixed(1)} s; worst edge ${(edgeErr * 1000).toFixed(0)} ms`);
  ok('no word is cut by silence removal', talk.words.every(w => keep.some(([a, b]) => w.t0 >= a - 1e-3 && w.t1 <= b + 1e-3)));
  // 2. scene cuts on a 4-scene test video
  const vid = path.join(TMP, 'scenes.webm');
  ff('-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=30:d=6', '-f', 'lavfi', '-i', 'smptebars=size=640x360:rate=30:d=6', '-f', 'lavfi', '-i', 'mandelbrot=size=640x360:rate=30', '-f', 'lavfi', '-i', 'color=c=0x2a9d8f:size=640x360:rate=30:d=6', '-f', 'lavfi', '-i', 'color=c=0xe0ac69:size=110x150:rate=30:d=6',
    '-i', path.join(TMP, 'talk.wav'),
    '-filter_complex', "[2]trim=duration=6,setpts=PTS-STARTPTS[m];[4]drawbox=x=30:y=40:w=12:h=12:color=black@1:t=fill,drawbox=x=68:y=40:w=12:h=12:color=black@1:t=fill,drawbox=x=40:y=100:w=30:h=8:color=0x8a3b2a@1:t=fill[blob];[3][blob]overlay=x='40+t*70':y=110:shortest=1[f];[0][1][m][f]concat=n=4:v=1:a=0,format=yuv420p[v]",
    '-map', '[v]', '-map', '5:a', '-t', '24', '-c:v', 'libvpx-vp9', '-b:v', '1M', '-deadline', 'realtime', '-cpu-used', '8', '-c:a', 'libopus', '-b:a', '64k', vid);
  const raw = execFileSync('ffmpeg', ['-v', 'error', '-i', vid, '-vf', 'fps=5,scale=96:54', '-f', 'rawvideo', '-pix_fmt', 'rgba', '-'], { maxBuffer: 1 << 30 });
  const fsz = 96 * 54 * 4, frames = []; for (let i = 0; i * fsz < raw.length; i++) frames.push({ t: i / 5, w: 96, h: 54, data: new Uint8ClampedArray(raw.buffer, raw.byteOffset + i * fsz, fsz) });
  const va = V.analyseFrames(frames, { duration: 24 });
  const cutT = va.cuts.map(c => c.t);
  ok('scene cuts at 6 / 12 / 18 s (±0.25 s), none inside moving shots', cutT.length === 3 && [6, 12, 18].every((t, i) => Math.abs(cutT[i] - t) <= 0.25), JSON.stringify(cutT));
  const subj = frames.map(f => ({ t: f.t, ...(V.subjectCentroid(f) || { x: null }) }));
  const crop = V.reframe(subj.filter(s => s.t >= 18), { w: 640, h: 360 }, { w: 1080, h: 1920 }, { duration: 24 });
  const xs = crop.filter(c => c.t >= 19).map(c => c.x);
  ok('auto-reframe 16:9→9:16 follows the moving subject smoothly', xs.length > 1 && xs[xs.length - 1] > xs[0] + 40 && xs.every((x, i) => !i || x >= xs[i - 1] - 1), `crop x ${xs[0]?.toFixed(0)} → ${xs[xs.length - 1]?.toFixed(0)} px, ${crop.length} keyframes, crop ${crop[0].w}×${crop[0].h}`);
  // 3. beat grid on library tracks
  const lib = JSON.parse(fs.readFileSync(path.join(ROOT, 'library/music/library.json'), 'utf8')).tracks;
  for (const id of ['glow-up', 'night-routine', 'desert-rose', 'clear-path']) {
    const tr = lib.find(t => t.id === id), x = f32(path.join(ROOT, 'library/music', tr.file), 22050);
    const g = A.beatGrid(x, 22050);
    const ratio = g.bpm / tr.bpm, truth = tr.beats.filter(b => b < tr.musicEnd - 0.1 && b > tr.sections[0].t1);
    let hit = 0; for (const b of truth) if (g.beats.some(d => Math.abs(d - b) <= 0.07)) hit++;
    ok(`beat grid ${id}: tempo ±2% and beats on the grid`, Math.abs(ratio - 1) <= 0.02 && hit / truth.length >= 0.8, `detected ${g.bpm} bpm vs ${tr.bpm}, ${(100 * hit / truth.length).toFixed(0)}% of beats within 70 ms`);
  }
  // 4. auto reel lengths (whole sentences, hook first, CTA last, ±0.5 s)
  const energy = A.energyCurve(talk.x, talk.sr);
  const sents = P.sentences(talk.words);
  for (const target of [15, 30, 60]) for (const withBeats of [false, true]) {
    const tr = lib.find(t => t.id === 'glow-up'), fit = M.fitTrack(tr, target);
    const plan = P.planAutoReel({ duration: talk.dur, words: talk.words, energy, frames: null, keywords: RC.recipeById('kbb-unboxing').keywords.en }, { target, beats: withBeats ? fit.beats : null });
    const whole = plan.segments.every(s => sents.some(x => s.in <= x.t0 + 1e-3 && s.out >= x.t1 - 1e-3));
    const onBeat = plan.segments.filter(s => s.onBeat).length;
    ok(`auto reel ${target} s${withBeats ? ' (cuts on beats)' : ''}: length ±0.5 s, whole sentences, hook first, CTA last`, Math.abs(plan.total - target) <= 0.5 && whole && plan.segments[0].role === 'hook' && plan.segments.at(-1).role === 'cta',
      `${plan.total.toFixed(2)} s = ${plan.segments.length} sentences + end card ${plan.endCard?.dur.toFixed(2)} s; hook “${plan.segments[0].text.slice(0, 40)}”${withBeats ? `; ${onBeat}/${plan.segments.length} cuts on beats` : ''}`);
  }
  const plan0 = P.planAutoReel({ duration: talk.dur, words: talk.words, energy }, { target: 30, endCard: null });
  ok('auto reel without end card still lands within ±0.5 s', Math.abs(plan0.total - 30) <= 0.5, plan0.total.toFixed(2) + ' s');
  // 5. auto music fit + ducking
  let fitOk = true, fitInfo = [];
  for (const tr of lib) for (const L of [15, 30, 60]) { const f = M.fitTrack(tr, L); const bar = tr.barSec; if (!(f.total <= L + 1e-3 && f.total >= L - bar - 0.05 && Math.abs(f.items.at(-1).in - tr.endingStart) < 1e-3)) { fitOk = false; fitInfo.push(`${tr.id}@${L}: ${f.total}`); } }
  ok('music fit: every track × 15/30/60 s ends on its written ending, within one bar of the length', fitOk, fitInfo.slice(0, 3).join(' '));
  const dk = M.duckKeys([[1, 3], [3.2, 5], [8, 9]], { t0: 0, in: 0, out: 12 });
  ok('ducking keys dip under speech and recover, times increasing', dk.every((k, i) => !i || k[0] > dk[i - 1][0]) && dk.some(k => k[1] < 0.5) && dk.at(-1)[1] === 1, JSON.stringify(dk));
  const pick = M.rankTracks(lib, { moods: ['arabic-oud'], energy: 'mid', duration: 30 })[0];
  ok('music pick follows the recipe mood', pick.track.mood === 'arabic-oud', pick.track.id + ' — ' + pick.reasons.join(', '));
  // 6. recipe suggest
  const S = (text, ws) => E.suggestRecipes({ text, workspace: ws });
  const cases = [
    ['unboxing', 'Hi guys, my parcel finally arrived! Let me open the box and unbox this haul from K-Beauty Bliss. Look at this packaging, I received everything I ordered.', { id: 'kbb', industry: 'beauty' }, ['kbb-unboxing']],
    ['cooking', 'Today we cook chicken with rice. Add two tablespoons of oil to the pan, then the onion and garlic, stir for five minutes, add salt and pepper. So delicious.', null, ['cooking-recipe']],
    ['study visa Germany', 'Want to study in Germany? Here are the steps for your German student visa: get your university admission letter, open a blocked account, book the embassy appointment in Berlin.', { id: 'planet-careers', industry: 'immigration' }, ['pc-study-visa-steps', 'pc-spotlight-de']],
    ['Arabic study visa Canada', 'تبي تدرس في كندا؟ خطوات تأشيرة الدراسة: القبول من الجامعة، المستندات، موعد السفارة في تورنتو.', { id: 'planet-careers', industry: 'immigration' }, ['pc-study-visa-steps', 'pc-spotlight-ca']],
    ['Arabic flash sale', 'عرض اليوم فقط! خصم ٥٠ بالمئة على كل منتجات العناية بالبشرة، استخدمي الكود عند الدفع', { id: 'kbb', industry: 'beauty' }, ['kbb-flash-sale']],
  ];
  for (const [name, text, ws, want] of cases) { const s = S(text, ws); ok(`suggest "${name}" → ${want.join(' / ')} first`, want.includes(s[0].recipe.id) && (want.length < 2 || s.some(x => x.recipe.id === want[1])), s.map(x => `${x.recipe.id} (${x.score}: ${x.reasons.slice(0, 2).join('; ')})`).join(' | ')); }
  ok('recipes ≥ 30, every one has structure / caption style / music / end card', RC.RECIPES.length >= 30 && RC.RECIPES.every(r => r.structure?.length && r.captionStyle && r.music?.moods?.length && r.endCard), `${RC.BASE_RECIPES.length} base + ${RC.RECIPES.length - RC.BASE_RECIPES.length} country spotlights`);
  const ev = FX.planEffects({ duration: 30, cuts: [3, 7.5, 12], words: talk.words.slice(0, 40), beats: [3.02, 7.5], recipe: RC.recipeById('kbb-flash-sale'), canvas: { w: 1080, h: 1920 } });
  ok('auto effects: transitions on every cut, SFX on them, punch-ins, price tag', ev.transitions.length === 3 && ev.sfx.filter(s => s.role === 'transition').length === 3 && ev.punches.length > 0 && ev.priceTag, `${ev.transitions.map(t => t.kind).join(',')}; ${ev.punches.length} punch-ins; tag ${ev.priceTag?.price}`);
  return { lib, talk, vid };
}

function loudnormAll(lib) {
  const rows = [];
  for (const tr of lib) {
    const r = require('child_process').spawnSync('ffmpeg', ['-hide_banner', '-nostats', '-i', path.join(ROOT, 'library/music', tr.file), '-af', 'loudnorm=I=-14:TP=-1:print_format=json', '-f', 'null', '-'], { encoding: 'utf8' });
    const j = JSON.parse(r.stderr.slice(r.stderr.lastIndexOf('{')));
    rows.push({ id: tr.id, mood: tr.mood, bpm: tr.bpm, dur: tr.duration, I: +j.input_i, TP: +j.input_tp, LRA: +j.input_lra });
  }
  return rows;
}

// ── browser part ──
function serve() {
  const types = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript', '.json': 'application/json', '.wasm': 'application/wasm', '.svg': 'image/svg+xml', '.m4a': 'audio/mp4', '.webm': 'video/webm', '.woff2': 'font/woff2', '.tflite': 'application/octet-stream', '.png': 'image/png' };
  const srv = http.createServer((req, res) => {
    const u = decodeURIComponent(req.url.split('?')[0]);
    const file = u.startsWith('/_r3/') ? path.join(TMP, u.slice(5)) : path.join(ROOT, u);
    if (!file.startsWith(ROOT) && !file.startsWith(TMP)) { res.writeHead(403); return res.end(); }
    fs.stat(file, (e, st) => {
      if (e || !st.isFile()) { res.writeHead(404); return res.end(); }
      const h = { 'Content-Type': types[path.extname(file)] || 'application/octet-stream', 'Cross-Origin-Opener-Policy': 'same-origin', 'Cross-Origin-Embedder-Policy': 'require-corp', 'Accept-Ranges': 'bytes' };
      const range = /bytes=(\d+)-(\d*)/.exec(req.headers.range || '');
      if (range) { const a = +range[1], b = range[2] ? +range[2] : st.size - 1; res.writeHead(206, { ...h, 'Content-Range': `bytes ${a}-${b}/${st.size}`, 'Content-Length': b - a + 1 }); fs.createReadStream(file, { start: a, end: b }).pipe(res); }
      else { res.writeHead(200, { ...h, 'Content-Length': st.size }); fs.createReadStream(file).pipe(res); }
    });
  });
  return new Promise(r => srv.listen(0, '127.0.0.1', () => r(srv)));
}

async function browser(talk) {
  const { chromium } = require('playwright');
  const srv = await serve(), port = srv.address().port, base = `http://127.0.0.1:${port}`;
  const b = await chromium.launch();
  const out = {};
  for (const ws of ['kbb', 'planet-careers']) {
    const pg = await b.newPage({ viewport: { width: 1280, height: 1000 } });
    const errors = [], foreign = [];
    pg.on('console', m => { if (m.type() === 'error' || /unavailable/.test(m.text())) errors.push(m.text()); });
    pg.on('pageerror', e => errors.push(String(e)));
    pg.on('response', r => { if (r.status() >= 400) errors.push('HTTP ' + r.status() + ' ' + r.url()); });
    pg.on('request', r => { if (!r.url().startsWith(base) && !r.url().startsWith('data:') && !r.url().startsWith('blob:')) foreign.push(r.url()); });
    await pg.goto(`${base}/app/auto/dev/harness.html?ws=${ws}`);
    await pg.waitForFunction(() => window.H && window.H.ready);
    const words = talk.words.filter(w => w.t1 < 24);
    const r = await pg.evaluate(async ({ words, ws }) => {
      const { ctx, auto, history } = window.H, res = {};
      res.long = []; new PerformanceObserver(l => { for (const e of l.getEntries()) res.long.push(Math.round(e.duration) + 'ms@' + (window.__phase || 'start')); }).observe({ type: 'longtask', buffered: false });
      const id = await window.H.load('_r3/scenes.webm', { dur: 24, w: 640, h: 360 }, words);
      let t = performance.now(); let longest = 0, last = performance.now();
      const tick = () => { const n = performance.now(); longest = Math.max(longest, n - last); last = n; if (!res.done) requestAnimationFrame(tick); }; requestAnimationFrame(tick);
      const a = await auto.analyse(ctx, id);
      res.analyseMs = Math.round(performance.now() - t);
      res.cuts = a.video.cuts.map(c => c.t); res.speech = a.audio.speech.length; res.faceShare = a.video.faceShare ?? null;
      window.__phase = 'review'; const list = await auto.silenceReview(ctx, id);
      res.reviewItems = list.length;
      const before = JSON.stringify(window.H.project.tracks);
      const removed = auto.applyCuts(ctx, id, list); res.removed = +removed.toFixed(2);
      res.clipsAfterCut = window.H.project.tracks.find(x => x.kind === 'video').items.length;
      history.undo(); res.undoCutRestores = JSON.stringify(window.H.project.tracks) === before;
      window.__phase = 'suggest'; const sug = await auto.suggest(ctx, id); res.suggest = sug.map(s => s.id);
      window.__phase = 'autoReel'; const rr = await auto.autoReel(ctx, { mediaId: id, recipeId: ws === 'kbb' ? 'kbb-flash-sale' : 'pc-spotlight-de', length: 15 });
      res.reelTotal = rr.plan.total; res.reelSegs = rr.plan.segments.length; res.music = rr.music; res.reframed = rr.reframed;
      const p = window.H.project;
      res.items = Object.fromEntries(p.tracks.map(x => [x.kind, x.items.length]));
      res.types = [...new Set(p.tracks.flatMap(x => x.items.map(i => i.type)))];
      res.undoLabel = history.undoLabel;
      res.longestFrameMs = Math.round(longest); res.done = true;
      // MediaPipe offline: load + run once on a drawn canvas
      try { const { detectFace } = await import('/app/auto/faces.js'); const c = new OffscreenCanvas(256, 256); const g = c.getContext('2d'); g.fillStyle = '#ccc'; g.fillRect(0, 0, 256, 256); const bm = c.transferToImageBitmap(); const f = await detectFace(bm); res.mediapipe = 'ran (' + (f ? 'face' : 'no face') + ')'; } catch (e) { res.mediapipe = 'ERROR ' + e.message; }
      return res;
    }, { words, ws });
    // pictures: draw frames at key moments
    const p = await pg.evaluate(() => { const pr = window.H.project; const ov = pr.tracks.find(t => t.kind === 'overlay').items; const pick = ty => ov.find(i => i.type === ty); return { logo: pick('auto.logo')?.t0, tag: pick('auto.pricetag')?.t0, tr: pick('auto.transition'), end: pick('auto.endcard')?.t0, sticker: pick('sticker')?.t0 }; });
    const shots = [['intro', (p.logo ?? 0) + 0.8], ['pricetag', (p.tag ?? 1) + 0.6], ['transition', p.tr ? p.tr.t0 + (p.tr.out - p.tr.in) / 2 : 2], ['endcard', (p.end ?? 13) + 1.4]];
    for (const [name, t] of shots) { await pg.evaluate(t => window.H.draw(t), t); await pg.locator('#cv').screenshot({ path: path.join(SH, `fx-${ws}-${name}.png`) }); }
    await pg.screenshot({ path: path.join(SH, `harness-${ws}.png`) });
    r.consoleErrors = errors; r.foreignRequests = foreign; out[ws] = r;
    await pg.close();
  }
  await b.close(); srv.close();
  return out;
}

(async () => {
  fs.mkdirSync(SH, { recursive: true });
  const { lib, talk } = await node();
  // loudness table + pictures of 6 tracks
  const rows = loudnormAll(lib);
  ok('every library track at -14 LUFS ±0.5 and true peak ≤ -1 dBTP', rows.every(r => Math.abs(r.I + 14) <= 0.5 && r.TP <= -1.0), `I ${Math.min(...rows.map(r => r.I))}…${Math.max(...rows.map(r => r.I))} LUFS, TP max ${Math.max(...rows.map(r => r.TP))} dBTP`);
  for (const id of ['velvet-hour', 'glow-up', 'morning-ritual', 'night-routine', 'desert-rose', 'the-arrival']) {
    const f = path.join(ROOT, 'library/music', id + '.m4a');
    ff('-i', f, '-filter_complex', `[0:a]showwavespic=s=1200x200:split_channels=1:colors=0xC6395F|0x13a89e[w];[0:a]showspectrumpic=s=1200x360:legend=0:fscale=log:color=intensity[s];[w][s]vstack,drawtext=text='${id}':x=12:y=8:fontcolor=white:fontsize=22:box=1:boxcolor=black@0.5`, '-frames:v', '1', path.join(SH, `music-${id}.png`));
  }
  fs.writeFileSync(path.join(SH, 'music-loudness.txt'), 'id\tmood\tbpm\tdur\tLUFS\tTP\tLRA\n' + rows.map(r => [r.id, r.mood, r.bpm, r.dur, r.I, r.TP, r.LRA].join('\t')).join('\n') + '\n');
  if (!process.argv.includes('--no-browser')) {
    let br; try { br = await browser(talk); } catch (e) { ok('browser run', false, e.message); }
    if (br) for (const [ws, r] of Object.entries(br)) {
      ok(`[${ws}] worker analysis in the page: scene cuts + speech`, r.cuts.length === 3 && r.speech > 5, `cuts ${JSON.stringify(r.cuts)}, ${r.speech} speech segments, ${r.analyseMs} ms`);
      ok(`[${ws}] remove silences = one undo step`, r.removed > 1 && r.clipsAfterCut > 3 && r.undoCutRestores, `review ${r.reviewItems} items, removed ${r.removed} s → ${r.clipsAfterCut} clips; undo restores: ${r.undoCutRestores}`);
      ok(`[${ws}] auto reel 15 s in the editor model (±0.5 s) with music, effects, end card`, Math.abs(r.reelTotal - 15) <= 0.5 && r.items.music > 0 && r.items.sfx > 0 && r.types.includes('auto.endcard') && r.undoLabel === 'Auto reel', `${r.reelTotal} s, ${r.reelSegs} segments, music ${r.music}, reframed ${r.reframed}, items ${JSON.stringify(r.items)}`);
      ok(`[${ws}] UI never blocked > 50 ms (workers; PLAN quality bar)`, r.longestFrameMs < 50 && !r.long.length, `longest frame gap ${r.longestFrameMs} ms; long tasks: ${r.long.join(' ')}`);
      ok(`[${ws}] MediaPipe face detector loads and runs offline`, /^ran/.test(r.mediapipe), r.mediapipe);
      ok(`[${ws}] no console errors, no request leaves 127.0.0.1`, !r.consoleErrors.length && !r.foreignRequests.length, [...r.consoleErrors, ...r.foreignRequests].slice(0, 3).join(' | '));
      ok(`[${ws}] suggest returns 3 recipes from the clip (same unboxing talk in both companies)`, r.suggest.length === 3, r.suggest.join(', '));
    }
  }
  fs.rmSync(TMP, { recursive: true, force: true });
  console.log(results.join('\n'));
  console.log(`\n${results.length - fails}/${results.length} passed`);
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); fs.rmSync(TMP, { recursive: true, force: true }); process.exit(1); });
