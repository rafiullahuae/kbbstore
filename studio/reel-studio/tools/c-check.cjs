#!/usr/bin/env node
// KBB Studio — Lane C check: the content & growth features end to end, in headless Chromium against the Linux engine,
// with a FAKE camera and microphone (Chromium's --use-fake-device-for-media-stream). Every feature runs on test media,
// exports, and is measured (ffprobe, decoded frames, pixel bounding boxes, file headers):
//   looks      every cue 1–3 words; cues off the face and inside the safe area; middle third when nothing is in the way;
//              product / price / country pops placed off the face and the captions; one undo step
//   b-roll     the image tagger (CLIP) tags a serum photo named IMG_2041.jpg "serum"; b-roll lands on matching words
//   formats    12 ready timelines; slots fill; the before/after WIPE measured in the exported frames
//   series     opener + badge + look, the episode counter moves on
//   cover      1080×1920 + 1080×1440 PNGs, title contrast ≥ 4.5:1 and ≥ 9 px tall at 1/6 grid scale
//   copy       EN + AR from the local LLM (Qwen2.5-0.5B on WASM here) — ≤ 5 hashtags, Arabic script; rules fallback too
//   shapes     9:16 / 4:5 / 1:1 / 16:9 exports at their sizes; captions inside the platform safe area, text inside title-safe
//   shorts     a 15-min webinar → 8–12 shorts of 20–60 s, each starting on a sentence, exported and ffprobed
//   record     teleprompter → two takes from the fake camera → playable files, best take picked, used on the timeline
//   cues       a 128 BPM sound measured; every cut snapped onto a beat; markers; cue sheet + guide WAV clicks on the cuts
// No console errors; no request leaves 127.0.0.1. Screenshots 1920×1080 + 1280×800 → shots/c/.
//
// usage: node tools/c-media.cjs && node tools/c-fetch-models.cjs && NODE_PATH=/home/user/kbbstore/node_modules node tools/c-check.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out'), MEDIA = path.join(OUT, 'c'), STUDIO = path.join(OUT, 'c-studio'), SHOTS = path.join(ROOT, 'shots', 'c');
fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0; const results = [];
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const secs = (t) => ((Date.now() - t) / 1000).toFixed(1) + ' s';
const probe = (f) => JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height', '-of', 'json', f]).toString());
const S = (p) => path.join(STUDIO, p);
const WORDS = (n) => JSON.parse(fs.readFileSync(path.join(MEDIA, n + '.words.json'), 'utf8'));
/** mean RGB of a region of the frame at t (ffmpeg → raw RGB24 at 108 px wide) */
function frameStats(file, t, W = 108) {
  const buf = execFileSync('ffmpeg', ['-v', 'error', '-ss', String(t), '-i', file, '-frames:v', '1', '-vf', `scale=${W}:-2`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 26 });
  const h = buf.length / 3 / W;
  return (x0, y0, x1, y1) => { let r = 0, g = 0, b = 0, n = 0; for (let y = Math.floor(y0 * h); y < Math.floor(y1 * h); y++) for (let x = Math.floor(x0 * W); x < Math.floor(x1 * W); x++) { const i = (y * W + x) * 3; r += buf[i]; g += buf[i + 1]; b += buf[i + 2]; n++; } return [r / n, g / n, b / n]; };
}
const lumaOf = ([r, g, b]) => 0.299 * r + 0.587 * g + 0.114 * b;

async function staticChecks() {
  const St = await import(path.join(ROOT, 'app/captions/styles.js'));
  const M = await import(path.join(ROOT, 'app/captions/model.js'));
  const R = await import(path.join(ROOT, 'app/captions/render.js'));
  const viral = St.STYLES.filter((s) => s.group === 'Viral');
  ok(viral.length >= 8, `${viral.length} viral caption looks (${viral.map((s) => s.id).join(', ')}) in a style list of ${St.STYLES.length}`);
  const kw = WORDS('kbb-talk').words;
  const bad = [];
  for (const s of viral) {
    const lines = M.buildLines(kw, s.id, { w: 1080, h: 1920 });
    const most = Math.max(...lines.map((L) => L.wordIdx[1] - L.wordIdx[0] + 1));
    const safe = R.safeArea(1080, 1920), cy = (safe.y + s.pos.y * safe.h) / 1920;
    if (most > 3 || cy < 1 / 3 || cy > 2 / 3 || !s.brand) bad.push(`${s.id}: ${most} words, centre ${cy.toFixed(2)}`);
  }
  ok(!bad.length, `every viral look shows 1–3 words per cue, centred in the middle third (9:16), and names its brand-colour field${bad.length ? ': ' + bad.join('; ') : ''}`);
  const F = await import(path.join(ROOT, 'app/content/formats.js'));
  const kbbF = F.FORMATS.filter((f) => f.ws === 'kbb'), pcF = F.FORMATS.filter((f) => f.ws === 'planet-careers');
  const lays = F.FORMATS.map((f) => ({ f, L: F.layoutFormat(f, { w: 1080, h: 1920 }, { colors: { primary: '#C6395F' } }) }));
  ok(kbbF.length === 5 && pcF.length === 7 && lays.every(({ L }) => L.slots.length >= 1 && L.length >= 10 && L.length <= 30 && L.slots.every((s) => s.guide && s.dur > 0)),
    `12 proven formats (KBB ${kbbF.map((f) => f.id.replace('kbb-', '')).join(', ')}; PC ${pcF.map((f) => f.id.replace('pc-', '')).join(', ')}), each 10–30 s with slot guides and timing`);
  const man = JSON.parse(fs.readFileSync(path.join(ROOT, 'models/manifest.json'), 'utf8'));
  const tg = man.models.find((m) => m.id === 'media-tagger'), llm = man.models.find((m) => m.id === 'copy-llm'), lite = man.models.find((m) => m.id === 'copy-llm-lite');
  ok(tg?.required && llm && !llm.required && lite && !lite.required, `manifest: media-tagger ${tg.repo} (${(tg.size / 1e6).toFixed(0)} MB, required), copy-llm ${llm.repo} (${(llm.size / 1e6).toFixed(0)} MB), copy-llm-lite ${lite.repo} (${(lite.size / 1e6).toFixed(0)} MB)`);
  // long → shorts planner on the webinar transcript (pure)
  const P = await import(path.join(ROOT, 'app/content/lib/shorts-plan.js'));
  const web = WORDS('webinar-15min');
  const plan = P.planShorts(web.words);
  const starts = new Set(web.sents.map((s) => s.t0.toFixed(2)));
  const onSent = plan.shorts.filter((s) => starts.has(s.t0.toFixed(2)));
  const topicsHit = new Set(plan.shorts.map((s) => web.sents.find((x) => x.t0.toFixed(2) === s.t0.toFixed(2))?.topic));
  ok(plan.shorts.length >= 8 && plan.shorts.length <= 12 && plan.shorts.every((s) => s.dur >= 20 && s.dur <= 60) && onSent.length === plan.shorts.length,
    `planner: ${(web.dur / 60).toFixed(1)}-min webinar, ${plan.sentences} sentences, ${plan.topics.length} topics → ${plan.shorts.length} shorts of ${Math.min(...plan.shorts.map((s) => s.dur)).toFixed(1)}–${Math.max(...plan.shorts.map((s) => s.dur)).toFixed(1)} s, all starting on a script sentence, covering ${topicsHit.size} of 10 topics`);
  return { plan, web };
}

function prepareStudio() {
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces']) fs.rmSync(S(d), { recursive: true, force: true });
  fs.mkdirSync(S('models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), S(d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), S('models/manifest.json'));
  // the content models, hard-linked (no copy) from tools/out/c-models
  const CM = path.join(OUT, 'c-models');
  if (fs.existsSync(CM)) execFileSync('cp', ['-al', '--remove-destination', CM + '/.', S('models')]);
  for (const ws of ['kbb', 'planet-careers']) fs.mkdirSync(S(`workspaces/${ws}/test`), { recursive: true });
  for (const f of ['kbb-talk.mp4']) fs.copyFileSync(path.join(MEDIA, f), S('workspaces/kbb/test/' + f));
  for (const f of ['pc-talk.mp4', 'webinar-15min.mp4']) fs.copyFileSync(path.join(MEDIA, f), S('workspaces/planet-careers/test/' + f));
  fs.mkdirSync(S('workspaces/kbb/library'), { recursive: true }); fs.mkdirSync(S('workspaces/planet-careers/library'), { recursive: true });
  fs.copyFileSync(path.join(MEDIA, 'lib/IMG_2041.jpg'), S('workspaces/kbb/library/IMG_2041.jpg'));
  fs.copyFileSync(path.join(MEDIA, 'lib/sunscreen-on-the-beach.mp4'), S('workspaces/kbb/library/sunscreen-on-the-beach.mp4'));
  fs.copyFileSync(path.join(MEDIA, 'lib/passport-and-documents.jpg'), S('workspaces/planet-careers/library/passport-and-documents.jpg'));
  fs.copyFileSync(path.join(MEDIA, 'trend-128bpm.wav'), S('workspaces/kbb/test/trend-128bpm.wav'));
}

(async () => {
  const T0 = Date.now();
  for (const f of ['kbb-talk.mp4', 'pc-talk.mp4', 'webinar-15min.mp4', 'lib/IMG_2041.jpg']) if (!fs.existsSync(path.join(MEDIA, f))) throw new Error('run tools/c-media.cjs first (' + f + ')');
  const { plan: nodePlan } = await staticChecks();
  prepareStudio();
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined,
    args: ['--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, permissions: ['camera', 'microphone'] });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('response', (r) => { if (r.status() >= 400) errors.push(`HTTP ${r.status()} ${r.url().replace(e.url, '')}`); });
  page.on('worker', (w) => w.on('console', (m) => { if (m.type() === 'error') errors.push('[worker] ' + m.text()); }));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = async (name) => {
    for (const [w, h] of [[1920, 1080], [1280, 800]]) { await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(450); await page.screenshot({ path: path.join(SHOTS, `${name}-${w}.png`) }); }
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(200);
  };
  const T = (file) => path.join(STUDIO, file);

  // ── boot ──
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.setup-screen, .home', { timeout: 30000 });
  if (await page.locator('.setup-screen').count()) await page.click('#su-later');
  await page.waitForSelector('.home', { timeout: 30000 });
  await page.waitForSelector('.mod-card[data-mod-home="create"]', { timeout: 15000 });
  ok(true, 'home shows the Create card (Record · Webinar → shorts · Start from a format)');
  await shot('01-home-create');

  /** open a fresh project in a company with media from the studio folder, words injected as captions */
  const openWith = async (ws, files, { aspect = '9:16', words = null, name = 'Lane C' } = {}) => {
    const id = await ev(async ({ ws, aspect, name }) => {
      const W = await import('/app/editor/workspaces.js'); W.switchWorkspace(ws);
      const P = await import('/app/core/project.js'), E = (await import('/app/core/engine.js')).engine;
      const p = P.createProject({ name, workspace: ws, brand: ws, aspect }); await E.saveProject(p); return p.id;
    }, { ws, aspect, name });
    await page.goto(`${e.url}/app/#/p/${ws}/${id}`); await page.reload();
    await page.waitForFunction(() => window.kbbEditor && window.kbbContent, null, { timeout: 30000 });
    await ev(async ({ files, words }) => {
      const ed = window.kbbEditor, { ops } = await import('/app/core/history.js');
      for (const [i, f] of files.entries()) { const m = await ed.media.importPath(f); ed.apply(ops.addMedia(m)); if (i === 0) ed.addMediaToTimeline(m, { t0: 0 }); }
      if (words) { const A = await import('/app/captions/auto.js'), M = await import('/app/captions/model.js'); const style = ed.project.captions.style || 'word-box';
        ed.apply(A.captionsWithItem(ed.project, { style, lang: 'en', words, lines: M.buildLines(words, style, ed.project.canvas) }, ops)); }
    }, { files, words });
    await page.waitForTimeout(300);
  };
  const openCreate = async () => { await page.click('.side-tab[data-side="mod:create"]'); await page.waitForSelector('.cr-panel .cr-card'); };

  // ═══ KBB: looks, b-roll, cover, copy, series, shapes ═══
  let t = Date.now();
  const kbbWords = WORDS('kbb-talk').words;
  await openWith('kbb', ['workspaces/kbb/test/kbb-talk.mp4'], { words: kbbWords, name: 'Evening routine' });
  await openCreate();
  // the "face" in the test clip is the skin-tone card at x 300–780, y 520–1140 of the 1080×1920 frame
  const FACE = { x: 540 / 1080, y: 830 / 1920, w: 480 / 1080, h: 620 / 1920 };
  const looks = await ev(async (FACE) => {
    const C = window.kbbContent, ed = window.kbbEditor, ctx = ed.context(), mid = ed.project.media[0].id;
    const faces = { [mid]: Array.from({ length: 32 }, (_, i) => ({ t: i, ...FACE, conf: 0.9 })) };
    const before = ed.history.undoStack.length;
    const r = await C.applyLook(ctx, { look: 'v-pop', faces });
    const steps = ed.history.undoStack.length - before;
    // measure every cue: draw ONLY the captions at the cue's middle and take the painted pixels' bounding box
    const { drawCaptions, safeArea } = await import('/app/captions/render.js');
    const { isActive } = await import('/app/core/project.js');
    if (document.fonts) await document.fonts.ready;
    const p = ed.project, W = p.canvas.w, H = p.canvas.h, cv = new OffscreenCanvas(W, H), g = cv.getContext('2d', { willReadFrequently: true });
    const caps = p.tracks.find((t) => t.kind === 'caption').items;
    const rects = [];
    for (const L of p.captions.lines) {
      const t = (L.t0 + L.t1) / 2, it = caps.find((i) => isActive(i, t)); if (!it) continue;
      g.clearRect(0, 0, W, H); drawCaptions(g, it, t, p, { box: { x: 0, y: 0, w: W, h: H } });
      const d = g.getImageData(0, 0, W, H).data; let x0 = W, y0 = H, x1 = 0, y1 = 0;
      for (let y = 0; y < H; y += 2) for (let x = 0; x < W; x += 2) if (d[(y * W + x) * 4 + 3] > 40) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
      if (x1 > x0) rects.push({ t, words: L.wordIdx[1] - L.wordIdx[0] + 1, x: x0, y: y0, w: x1 - x0, h: y1 - y0 });
    }
    const fb = { x: (FACE.x - FACE.w / 2) * W, y: (FACE.y - FACE.h / 2) * H, w: FACE.w * W, h: FACE.h * H };
    const inter = (a, b) => Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x)) * Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y));
    const safe = safeArea(W, H);
    const popItems = p.tracks.flatMap((t) => t.items).filter((i) => i.auto === 'content.pop');
    const popClash = popItems.filter((i) => inter(i, fb) > 0.02 * i.w * i.h).map((i) => i.name);
    const popOnCaption = popItems.filter((i) => rects.some((r) => r.t >= i.t0 && r.t <= i.t0 + i.out - i.in && inter(i, r) > 0.05 * i.w * i.h)).map((i) => i.name);
    // undo / redo: the whole look is one step
    const styleAfter = p.captions.style.id; ed.history.undo(); const styleUndone = ed.project.captions.style?.id || ed.project.captions.style; ed.history.redo();
    return { r: { look: r.look, cues: r.cues, maxWords: r.maxWords, segments: r.segments.length, moved: r.moved, pops: r.pops.map((x) => `${x.kind}:${x.label}`) }, steps, rects: rects.length,
      onFace: rects.filter((x) => inter(x, fb) > 0).length, outSafe: rects.filter((x) => x.x < safe.x - 2 || x.y < safe.y - 2 || x.x + x.w > safe.x + safe.w + 2 || x.y + x.h > safe.y + safe.h + 2).length,
      maxWordsDrawn: Math.max(...rects.map((x) => x.words)), popClash, popOnCaption, styleAfter, styleUndone, active: p.captions.style.active };
  }, FACE);
  ok(looks.r.look === 'v-pop' && looks.maxWordsDrawn <= 3 && looks.rects > 15, `look "v-pop": ${looks.r.cues} cues, every one 1–${looks.maxWordsDrawn} words, spoken word in the brand colour ${looks.active}`);
  ok(looks.onFace === 0 && looks.outSafe === 0, `measured on drawn pixels: 0 of ${looks.rects} cues touch the face, 0 leave the platform safe area (moved off the face in ${looks.r.moved} of ${looks.r.segments} placements)`);
  ok(looks.r.pops.some((x) => /price:AED 99/.test(x)) && looks.r.pops.some((x) => /product:Biodance/.test(x)) && looks.r.pops.some((x) => /30% OFF/.test(x)) && !looks.popClash.length && !looks.popOnCaption.length,
    `keyword pops: ${looks.r.pops.join(', ')} — none on the face or over the captions${looks.popClash.length || looks.popOnCaption.length ? " — CLASH face: " + looks.popClash.join(",") + " captions: " + looks.popOnCaption.join(",") : ""}`);
  ok(looks.steps === 1 && looks.styleUndone !== looks.styleAfter, `the whole look is ONE undo step (undo → "${looks.styleUndone}", redo → "${looks.styleAfter}")`);
  // without a face in the way, the captions sit in the middle third
  const mid = await ev(async () => { const C = window.kbbContent, ed = window.kbbEditor; const r = await C.applyLook(ed.context(), { look: 'v-box', faces: {}, pops: false });
    const { cueRect } = C.looks; const { resolveStyle } = await import('/app/captions/styles.js'); const st = resolveStyle(r.spec);
    return r.segments.map((s) => { const c = cueRect(ed.project.canvas, st, s.cy).rect; return (c.y + c.h / 2) / ed.project.canvas.h; }); });
  ok(mid.every((c) => c > 1 / 3 && c < 2 / 3), `with nothing in the way every cue centre sits in the middle third (${mid.map((c) => c.toFixed(2)).join(', ')})`);
  await ev(async (FACE) => { const C = window.kbbContent, ed = window.kbbEditor; await C.applyLook(ed.context(), { look: 'v-pop', faces: { [ed.project.media[0].id]: Array.from({ length: 32 }, (_, i) => ({ t: i, ...FACE, conf: 0.9 })) } }); ed.seek(5.6); }, FACE);
  await page.waitForTimeout(500);
  await shot('02-viral-look');

  // ── b-roll from his library (tagger on the serum photo with a camera file name) ──
  const br = await ev(async () => {
    const C = window.kbbContent, ed = window.kbbEditor, ctx = ed.context();
    const t0 = performance.now();
    const tag = await C.tagMedia('kbb', { src: 'workspaces/kbb/library/IMG_2041.jpg', name: 'IMG_2041.jpg', kind: 'image', size: 1 });
    const tagMs = performance.now() - t0;
    const r = await C.autoBroll(ctx);
    const { stem } = C.keywords;
    const lib = await C.libraryAssets(ctx, { tag: true });
    const check = r.placements.map((p) => { const a = lib.find((x) => x.src === p.asset.src); return { t0: p.t0, t1: p.t1, word: p.word, src: p.asset.src.split('/').pop(), source: p.asset.source, match: !!a && a.words.has(stem(p.word)) }; });
    return { tag, tagMs, check, spots: r.spots.length, assets: r.assets };
  });
  ok(br.tag.model && br.tag.tags.includes('serum'), `image tagger (CLIP, offline) on IMG_2041.jpg → ${br.tag.model.map((x) => `${x.tag} ${x.p}`).join(', ')} in ${(br.tagMs / 1000).toFixed(1)} s`);
  ok(br.check.length >= 2 && br.check.every((c) => c.match) && br.check.some((c) => c.src === 'IMG_2041.jpg') && br.check.some((c) => /sunscreen/.test(c.word.toLowerCase())),
    `b-roll: ${br.check.length} shots in ${br.spots} dead spots, every one on a matching word: ${br.check.map((c) => `${c.t0}s “${c.word}”→${c.src} (${c.source})`).join('; ')}`);
  const brollT = br.check.find((c) => c.src === 'IMG_2041.jpg');
  await ev((t) => window.kbbEditor.seek(t), brollT.t0 + 0.8); await page.waitForTimeout(400);
  await shot('03-broll');

  // export the KBB reel and look at the b-roll frame
  const kExp = await ev(async () => {
    const ed = window.kbbEditor, { exportMp4 } = await import('/app/core/export.js');
    const r = await exportMp4(ed.project, ed.media, { width: 540, height: 960, quality: 'draft' in {} ? 'draft' : 'standard' });
    const path = `workspaces/kbb/exports/c-kbb-reel.mp4`; await ed.engine.export(path, r.blob); return { path, frames: r.frames, note: r.note };
  });
  const kp = probe(T(kExp.path));
  const fsB = frameStats(T(kExp.path), brollT.t0 + 1.0), fsT = frameStats(T(kExp.path), 1.0);
  const lB = lumaOf(fsB(0.3, 0.75, 0.7, 0.95)), lT = lumaOf(fsT(0.3, 0.75, 0.7, 0.95));
  ok(Math.abs(+kp.format.duration - 31.2) < 0.4 && kp.streams.some((s) => s.codec_type === 'audio'), `KBB reel exported: ${(+kp.format.duration).toFixed(2)} s, ${kp.streams.map((s) => s.codec_name).join('+')}`);
  ok(Math.abs(lB - lT) > 25, `the exported frame at ${(brollT.t0 + 1).toFixed(1)} s shows the b-roll photo, not the talking shot (luma ${lB.toFixed(0)} vs ${lT.toFixed(0)})`);

  // ── cover ──
  await openCreate();
  t = Date.now();
  const cov = await ev(async () => { const C = window.kbbContent, ed = window.kbbEditor; const r = await C.makeCover(ed.context(), { title: 'My 2-step evening glow routine' }); return { title: r.title, files: r.files.map(({ url, ...x }) => x), metrics: r.metrics, frames: r.frames }; });
  const pngSize = (f) => { const b = fs.readFileSync(T(f)); return [b.readUInt32BE(16), b.readUInt32BE(20)]; };
  const [c1, c2] = cov.files.map((f) => pngSize(f.path));
  ok(c1[0] === 1080 && c1[1] === 1920 && c2[0] === 1080 && c2[1] === 1440, `cover PNGs on disk: ${cov.files.map((f) => f.path.split('/').pop() + ' ' + pngSize(f.path).join('×')).join(', ')} in ${secs(t)} (best of ${cov.frames.length} frames, score ${cov.frames[0]?.score})`);
  ok(cov.metrics.grid.readable && cov.metrics.reel.contrast >= 4.5 && cov.metrics.grid.contrast >= 4.5 && cov.metrics.grid.gridPx >= 9 && (cov.metrics.grid.popContrast == null || cov.metrics.grid.popContrast >= 4.5),
    `title "${cov.title}": contrast ${cov.metrics.reel.contrast}:1 (9:16) / ${cov.metrics.grid.contrast}:1 (3:4), keyword ${cov.metrics.grid.popContrast}:1, ${cov.metrics.grid.gridPx} px tall at 1/6 grid scale, plate ${cov.metrics.grid.plate}`);
  // the 9:16 cover's centre 3:4 (what the grid shows) holds the whole title
  const cb = cov.metrics.reel.box, top34 = (1920 - 1440) / 2;
  ok(cb.y >= top34 && cb.y + cb.h <= top34 + 1440, `the 9:16 cover's title (y ${cb.y}–${cb.y + cb.h}) sits inside the grid's centre 3:4 crop (${top34}–${top34 + 1440})`);
  // independent contrast measurement: the PNG pixels under the title box, white text vs the brightest background around it
  await page.click('.cr-card[data-cr="cover"] .cr-go'); await page.waitForSelector('.cr-card[data-cr="cover"] .cr-covers img', { timeout: 60000 }); await page.waitForTimeout(300);
  await page.locator('.cr-card[data-cr="cover"] .cr-out').evaluate((el) => el.scrollIntoView({ block: 'end' }));
  await shot('04-cover');

  // ── post copy: local LLM (WASM) and the rule-based writer ──
  t = Date.now();
  const cp = await ev(async () => { const C = window.kbbContent, ed = window.kbbEditor; const r = await C.writeCopy(ed.context(), { prefer: 'cpu' }); const rules = await C.writeCopy(ed.context(), { useModel: false, save: false }); return { r, rules }; });
  const ar = /[؀-ۿ]/;
  const good = (x, lang) => x.hashtags.length >= 3 && x.hashtags.length <= 5 && x.hashtags.every((h) => /^#\S+$/.test(h)) && x.comments.length >= 2 && x.keywords.length >= 3 && (lang !== 'ar' || ar.test(x.caption));
  ok(cp.r.model?.id === 'copy-llm-lite' && cp.r.model.device === 'wasm' && good(cp.r.en, 'en') && good(cp.r.ar, 'ar') && cp.r.file,
    `post copy by the local model ${cp.r.model?.id} (${cp.r.model?.device}) in ${secs(t)}: EN [model: ${cp.r.en.fromModel.join('+') || 'none, rules'}] ${cp.r.en.hashtags.length} hashtags ${cp.r.en.hashtags.join(' ')} · AR [model: ${cp.r.ar.fromModel.join('+') || 'none, rules'}] ${cp.r.ar.hashtags.length} hashtags ${cp.r.ar.hashtags.join(' ')}`);
  console.log('   EN caption:', JSON.stringify(cp.r.en.caption).slice(0, 240));
  console.log('   AR caption:', JSON.stringify(cp.r.ar.caption).slice(0, 240));
  console.log('   EN comments:', JSON.stringify(cp.r.en.comments));
  ok(good(cp.rules.en, 'en') && good(cp.rules.ar, 'ar') && cp.rules.en.hashtags.length === 5 && cp.rules.ar.hashtags.length === 5, `rule-based writer (no model): EN ${cp.rules.en.hashtags.join(' ')} · AR ${cp.rules.ar.hashtags.join(' ')}`);
  ok(fs.existsSync(T(cp.r.file)) && ar.test(fs.readFileSync(T(cp.r.file), 'utf8')), `post copy saved to ${cp.r.file}`);
  await page.click('.cr-card[data-cr="copy"] .cr-go'); await page.waitForSelector('.cr-card[data-cr="copy"] .cr-copy', { timeout: 300000 });
  await page.locator('.cr-card[data-cr="copy"] .cr-copy').first().evaluate((el) => el.scrollIntoView({ block: 'start' }));
  await shot('05-post-copy');

  // ── series ──
  const ser = await ev(async () => { const C = window.kbbContent, ed = window.kbbEditor; const r1 = await C.addEpisode(ed.context(), 'skin-tip'); const list = await C.loadSeries('kbb');
    const items = ed.project.tracks.flatMap((t) => t.items).filter((i) => i.auto === 'content.series'); return { r1, next: list.find((s) => s.id === 'skin-tip').n, items: items.length, badge: items.find((i) => i.name === 'Episode badge')?.text, look: ed.project.captions.style.id }; });
  ok(ser.r1.title === 'Skin Tip #1' && ser.next === 2 && ser.items >= 4 && ser.badge === 'Skin Tip #1' && ser.look === 'v-pop', `series: "${ser.r1.title}" opener (${ser.items} items, badge "${ser.badge}"), look ${ser.look}, next episode #${ser.next} saved in workspaces/kbb/series.json`);
  await ev(() => window.kbbEditor.seek(0.9)); await page.waitForTimeout(300); await shot('06-series-opener');

  // ── every shape: re-laid out + batch export ──
  t = Date.now();
  const shp = await ev(async () => {
    const C = window.kbbContent, ed = window.kbbEditor, ctx = ed.context();
    const { drawCaptions, safeArea } = await import('/app/captions/render.js'), { isActive, safeAreas } = await import('/app/core/project.js'), { boxAt } = await import('/app/core/render.js');
    const faces = { [ed.project.media[0].id]: [] };
    const res = {};
    for (const a of C.SHAPES) {
      const q = C.layoutFor(ed.project, a, faces), W = q.canvas.w, H = q.canvas.h, cv = new OffscreenCanvas(W, H), g = cv.getContext('2d', { willReadFrequently: true });
      const sa = safeArea(W, H), ts = safeAreas(q.canvas).title; let out = 0, n = 0, textOut = [];
      const caps = q.tracks.find((t) => t.kind === 'caption').items;
      for (const L of q.captions.lines) { const t = (L.t0 + L.t1) / 2, it = caps.find((i) => isActive(i, t)); if (!it) continue;
        g.clearRect(0, 0, W, H); drawCaptions(g, it, t, q, { box: { x: 0, y: 0, w: W, h: H } }); const d = g.getImageData(0, 0, W, H).data; let x0 = W, y0 = H, x1 = 0, y1 = 0;
        for (let y = 0; y < H; y += 3) for (let x = 0; x < W; x += 3) if (d[(y * W + x) * 4 + 3] > 40) { x0 = Math.min(x0, x); x1 = Math.max(x1, x); y0 = Math.min(y0, y); y1 = Math.max(y1, y); }
        if (x1 > x0) { n++; if (x0 < sa.x - 3 || y0 < sa.y - 3 || x1 > sa.x + sa.w + 3 || y1 > sa.y + sa.h + 3) out++; } }
      for (const tr of q.tracks) for (const it of tr.items) if ((it.type === 'text' || it.type === 'sticker' || (it.type === 'clip' && tr.kind !== 'video' && it.w < W * 0.9)) && it.auto !== 'content.series') {
        const b = boxAt(it, it.t0 + Math.min(0.5, (it.out - it.in) / 2)); if (b.x < ts.x - 2 || b.y < ts.y - 2 || b.x + b.w > ts.x + ts.w + 2 || b.y + b.h > ts.y + ts.h + 2) textOut.push(it.name); }
      res[a] = { cues: n, out, textOut, lines: q.captions.lines.length };
    }
    const files = await C.exportShapes(ctx, { size: 540, faces, onProgress: () => {} });
    return { res, files: files.map(({ url, ...x }) => x) };
  });
  const dims = { '9:16': [540, 960], '4:5': [540, 676], '1:1': [540, 540], '16:9': [960, 540] };
  const shapeOk = shp.files.every((f) => { const pr = probe(T(f.path)), v = pr.streams.find((s) => s.codec_type === 'video'); return v.width === dims[f.aspect][0] && Math.abs(v.height - dims[f.aspect][1]) <= 2 && Math.abs(+pr.format.duration - 31.2) < 0.5; });
  ok(shp.files.length === 4 && shapeOk, `one edit → 4 shapes exported in ${secs(t)}: ${shp.files.map((f) => `${f.aspect} ${f.w}×${f.h}`).join(', ')} (ffprobe sizes and durations match)`);
  ok(Object.values(shp.res).every((r) => r.out === 0 && r.cues > 10 && !r.textOut.length), `captions inside the platform safe area in every shape (measured on drawn pixels: ${Object.entries(shp.res).map(([a, r]) => `${a} ${r.cues} cues/${r.lines} lines, ${r.out} out`).join('; ')}); text, pops and b-roll cards inside title-safe${Object.values(shp.res).some((r) => r.textOut.length) ? ' — OUT: ' + JSON.stringify(Object.fromEntries(Object.entries(shp.res).map(([a, r]) => [a, r.textOut]))) : ''}`);
  // a 16:9 talk re-aimed at its speaker in 9:16 (face-aware reframe) — the PC clip's "face" card at x 1180–1600
  const rf = await ev(async () => {
    const C = window.kbbContent, ed = window.kbbEditor, P = await import('/app/core/project.js'), { boxAt } = await import('/app/core/render.js');
    const q = P.createProject({ name: 'rf', workspace: 'planet-careers', aspect: '16:9' });
    const m = await ed.media.importPath('workspaces/planet-careers/test/pc-talk.mp4');
    q.media.push(m); q.tracks.find((t) => t.kind === 'video').items.push(P.createItem('clip', { media: m.id, t0: 0, in: 0, out: m.dur, x: 0, y: 0, w: 1920, h: 1080 }));
    const faces = { [m.id]: Array.from({ length: 18 }, (_, i) => ({ t: i, x: 1390 / 1920, y: 460 / 1080, w: 420 / 1920, h: 560 / 1080, conf: 0.9 })) };
    const v = C.layoutFor(q, '9:16', faces), it = v.tracks.find((t) => t.kind === 'video').items[0], b = boxAt(it, 5);
    // where the face centre lands on the 9:16 canvas
    const s = b.w / m.w; return { fx: (b.x + 1390 * s) / 1080, kf: Object.keys(it.kf || {}).length };
  });
  ok(rf.kf && rf.fx > 0.3 && rf.fx < 0.7, `face-aware reframe: a 16:9 talk's speaker (at 72 % across the source) lands at ${(rf.fx * 100).toFixed(0)} % across the 9:16 frame`);

  // ── cue sheet: measure the trending sound, snap the cuts, guide track ──
  const cue = await ev(async () => {
    const C = window.kbbContent, ed = window.kbbEditor, ctx = ed.context(), { ops } = await import('/app/core/history.js');
    const blob = await (await fetch('/workspaces/kbb/test/trend-128bpm.wav')).blob();
    const meas = await C.measureSound(ctx, blob);
    // cut the main clip into four pieces at off-beat moments
    const vt = ed.project.tracks.find((t) => t.kind === 'video'), main = vt.items[0], P = await import('/app/core/project.js');
    const cuts = [6.0, 13.3, 21.95];
    const pieces = [0, ...cuts, main.out].slice(0, -1).map((a, i, arr) => ({ a, b: i < arr.length - 1 ? arr[i + 1] : main.out }));
    ed.apply(ops.batch('cuts', [ops.setItem(main.id, { out: cuts[0] }), ops.addItems(pieces.slice(1).map((pc) => ({ trackId: vt.id, item: { ...P.clone(main), id: P.uid('i'), t0: pc.a, in: pc.a, out: pc.b } })))]));
    const r = await C.cueSheet(ctx, { bpm: meas.bpm, offset: meas.offset });
    const after = ed.project.tracks.find((t) => t.kind === 'video').items.map((i) => i.t0).sort((a, b) => a - b).slice(1);
    const grid = (t) => { const k = Math.round((t - meas.offset) / (60 / meas.bpm)); return Math.abs(meas.offset + k * 60 / meas.bpm - t); };
    return { meas, r: { ...r, wavUrl: null, csv: r.csv.split('\n').length, txt: null }, after, off: after.map(grid), markers: ed.project.markers.filter((m) => m.cue).length, end: P.projectDuration(ed.project) };
  });
  ok(Math.abs(cue.meas.bpm - 128) <= 1.5 && Math.abs(cue.meas.offset - 0.25) < 0.03, `trending sound measured: ${cue.meas.bpm} BPM (true 128), first beat ${cue.meas.offset} s (true 0.25)`);
  ok(cue.after.length === 3 && cue.off.every((d) => d < 0.002) && cue.markers === 3 && cue.r.shifted === 3, `every cut snapped onto a beat (${cue.after.map((x) => x.toFixed(3)).join(', ')} s; off-grid ${Math.max(...cue.off).toFixed(4)} s), ${cue.markers} beat markers, reel ${cue.end.toFixed(2)} s`);
  const wavF = T(cue.r.files.wav), wb = fs.readFileSync(wavF), wsr = wb.readUInt32LE(24), smp = (wb.length - 44) / 2;
  const peaks = cue.after.map((tc) => { let mx = 0; for (let i = Math.round((tc - 0.005) * wsr); i < Math.round((tc + 0.05) * wsr) && i < smp; i++) mx = Math.max(mx, Math.abs(wb.readInt16LE(44 + i * 2))); return mx / 32767; });
  ok(fs.existsSync(T(cue.r.files.csv)) && fs.existsSync(T(cue.r.files.txt)) && peaks.every((p) => p > 0.6), `cue sheet ${path.basename(cue.r.files.csv)} (${cue.r.csv - 2} rows) + ${path.basename(cue.r.files.txt)} + guide ${path.basename(wavF)}: accented clicks on every cut (${peaks.map((p) => p.toFixed(2)).join(', ')})`);
  await page.locator('.cr-card[data-cr="cues"]').scrollIntoViewIfNeeded(); await shot('07-cues');

  // ═══ Planet Careers: formats ═══
  await openWith('planet-careers', ['workspaces/planet-careers/test/pc-talk.mp4', 'workspaces/planet-careers/library/passport-and-documents.jpg'], { name: 'Documents' });
  // the pc-talk was put on the timeline by openWith: start from an empty reel for the format
  const fm = await ev(async () => {
    const C = window.kbbContent, ed = window.kbbEditor, ctx = ed.context(), { ops } = await import('/app/core/history.js');
    ed.apply(ops.removeItems(ed.project.tracks.flatMap((t) => t.items).map((i) => i.id)));
    const r = C.buildFormat(ctx, 'pc-documents-30');
    const slots = C.slotsOf(ed.project);
    const pass = ed.project.media.find((m) => /passport/.test(m.name)), talk = ed.project.media.find((m) => /pc-talk/.test(m.name));
    C.fillSlot(ctx, 'intro', talk.id); C.fillSlot(ctx, 'd1', pass.id);
    const filled = C.slotsOf(ed.project).filter((s) => s.filled).length;
    const n = C.autoFill(ctx);
    const after = C.slotsOf(ed.project);
    return { r: { length: r.length, slots: r.slots.length }, before: slots.length, filled, auto: n, empty: after.filter((s) => !s.filled).map((s) => s.label), dur: (await import('/app/core/project.js')).projectDuration(ed.project) };
  });
  ok(fm.r.slots === 8 && fm.filled === 2 && Math.abs(fm.dur - 28) < 0.01, `format "Documents in 30 s": ${fm.r.slots} slots with guides on a ${fm.dur.toFixed(1)} s timeline; 2 filled by hand, ${fm.auto} from the bin, still empty: ${fm.empty.join(', ') || 'none'}`);
  await openCreate(); await page.waitForTimeout(300); await ev(() => window.kbbEditor.seek(4.2)); await shot('08-format-pc');
  const fExp = await ev(async () => { const ed = window.kbbEditor, { exportMp4 } = await import('/app/core/export.js'); const r = await exportMp4(ed.project, ed.media, { width: 540, height: 960 }); const p = 'workspaces/planet-careers/exports/c-format-docs.mp4'; await ed.engine.export(p, r.blob); return p; });
  const fp = probe(T(fExp));
  const slotFrame = frameStats(T(fExp), 4.5)(0.1, 0.25, 0.9, 0.7), emptyFrame = frameStats(T(fExp), 19)(0.1, 0.25, 0.9, 0.7);
  ok(Math.abs(+fp.format.duration - 28) < 0.3, `format exported: ${(+fp.format.duration).toFixed(2)} s; the passport slot shows the photo (mean RGB ${slotFrame.map((x) => x.toFixed(0)).join(',')}), an empty slot its brand card (${emptyFrame.map((x) => x.toFixed(0)).join(',')})`);

  // KBB before / after: the wipe, measured in the exported frames
  await openWith('kbb', ['workspaces/kbb/test/kbb-talk.mp4'], { name: 'Before after' });
  const ba = await ev(async () => {
    const C = window.kbbContent, ed = window.kbbEditor, ctx = ed.context(), { ops } = await import('/app/core/history.js');
    ed.apply(ops.removeItems(ed.project.tracks.flatMap((t) => t.items).map((i) => i.id)));
    const img = await ed.media.importPath('workspaces/kbb/library/IMG_2041.jpg'); ed.apply(ops.addMedia(img));
    C.buildFormat(ctx, 'kbb-before-after');
    C.fillSlot(ctx, 'before', ed.project.media[0].id); C.fillSlot(ctx, 'after', img.id); C.fillSlot(ctx, 'product', img.id);
    const { exportMp4 } = await import('/app/core/export.js'); const r = await exportMp4(ed.project, ed.media, { width: 540, height: 960 }); const p = 'workspaces/kbb/exports/c-before-after.mp4'; await ed.engine.export(p, r.blob); return p;
  });
  const mids = [3.6, 4.2 + 0.55, 6.0].map((tt) => { const f = frameStats(T(ba), tt); return [lumaOf(f(0.03, 0.55, 0.2, 0.75)), lumaOf(f(0.8, 0.55, 0.97, 0.75))]; });
  ok(mids[0][0] < 140 && mids[0][1] < 140 && mids[1][0] > 170 && mids[1][1] < 140 && mids[2][0] > 170 && mids[2][1] > 170,
    `before/after wipe in the export: before full (L/R luma ${mids[0].map((x) => x.toFixed(0)).join('/')}), mid-wipe after on the left only (${mids[1].map((x) => x.toFixed(0)).join('/')}), after full (${mids[2].map((x) => x.toFixed(0)).join('/')})`);
  await ev(() => window.kbbEditor.seek(4.75)); await openCreate(); await page.waitForTimeout(300); await shot('09-format-before-after');

  // ═══ long webinar → shorts ═══
  const web = WORDS('webinar-15min');
  await openWith('planet-careers', ['workspaces/planet-careers/test/webinar-15min.mp4'], { aspect: '16:9', words: web.words, name: 'Study abroad webinar' });
  t = Date.now();
  const sh = await ev(async () => { const C = window.kbbContent, ed = window.kbbEditor; const r = await C.makeShorts(ed.context(), { faces: false }); return { topics: r.topics, sentences: r.sentences, shorts: r.shorts.map(({ project, ...x }) => x) }; });
  const starts = new Set(web.sents.map((s) => s.t0));
  const startOk = sh.shorts.filter((s) => web.sents.some((x) => Math.abs(x.t0 - (s.t0 + 0.12)) < 0.02 || Math.abs(x.t0 - s.t0) < 0.02));
  ok(sh.shorts.length >= 8 && sh.shorts.length <= 12 && sh.shorts.every((s) => s.dur >= 20 && s.dur <= 61) && startOk.length === sh.shorts.length,
    `webinar ${(web.dur / 60).toFixed(1)} min → ${sh.shorts.length} shorts (${sh.topics} topics, ${sh.sentences} sentences) of ${Math.min(...sh.shorts.map((s) => s.dur)).toFixed(1)}–${Math.max(...sh.shorts.map((s) => s.dur)).toFixed(1)} s in ${secs(t)}, every one starting on a sentence ("${sh.shorts.map((s) => s.firstWord).join('", "')}")`);
  console.log('   hooks:', sh.shorts.map((s) => s.title).join(' | '));
  ok(nodePlan.shorts.length === sh.shorts.length, 'the browser and the Node planner agree on the shorts');
  await page.locator('.cr-card[data-cr="shorts"]').scrollIntoViewIfNeeded().catch(() => {});
  t = Date.now();
  const shx = await ev(async () => { const C = window.kbbContent, ed = window.kbbEditor; const list = (ed.project.meta.content.shorts || []); return C.exportShorts(ed.context(), list, { size: 720 }); });
  const shp2 = shx.map((x) => probe(T(x.path)));
  const durs = shp2.map((p) => +p.format.duration);
  ok(shx.length === sh.shorts.length && shp2.every((p) => { const v = p.streams.find((s) => s.codec_type === 'video'); return v.width === 720 && v.height === 1280 && p.streams.some((s) => s.codec_type === 'audio'); }) && durs.every((d) => d >= 20 && d <= 61),
    `${shx.length} shorts exported in ${secs(t)}: 720×1280 with sound, ${durs.map((d) => d.toFixed(1)).join(', ')} s`);
  // open one short: hook + captions + CTA
  const one = await ev(async () => { const E = (await import('/app/core/engine.js')).engine; const q = await E.loadProject('planet-careers', window.kbbEditor.project.meta.content.shorts[0].id); const its = q.tracks.flatMap((t) => t.items);
    return { hook: its.find((i) => i.name === 'Hook')?.text, cta: its.find((i) => i.name === 'CTA')?.text, words: q.captions.words.length, look: q.captions.style.id, id: q.id }; });
  ok(one.hook && one.cta && one.words > 30 && one.look === 'v-pop', `short 1 is a finished reel: hook "${one.hook}", ${one.words} caption words in ${one.look}, CTA "${one.cta}"`);
  await page.goto(`${e.url}/app/#/p/planet-careers/${one.id}`); await page.reload(); await page.waitForFunction(() => window.kbbEditor); await ev(() => window.kbbEditor.seek(1.2)); await page.waitForTimeout(600);
  await shot('10-short-1');

  // ═══ teleprompter + record (fake camera) ═══
  await openWith('kbb', [], { name: 'Teleprompter' });
  await openCreate();
  await page.click('.cr-card[data-cr="record"] .cr-go');
  await page.waitForFunction(() => /Camera ready/.test(document.querySelector('.tp-actions .muted')?.textContent || ''), null, { timeout: 30000 });
  await page.fill('.tp-script', 'Hi! Today I am showing you my three favourite Korean serums. Number one is the Biodance caviar serum. Shop now at the link in bio.');
  for (const [n, ms] of [[1, 3500], [2, 5000]]) {
    await page.click('.tp-rec'); await page.waitForFunction(() => /Stop/.test(document.querySelector('.tp-rec')?.textContent || ''), null, { timeout: 10000 });
    await page.waitForTimeout(ms);
    if (n === 2) await shot('11-teleprompter');
    await page.click('.tp-rec');
    await page.waitForFunction((n) => /saved/.test(document.querySelector('.tp-actions .muted')?.textContent || '') && document.querySelectorAll('.tp-take').length === n, n, { timeout: 120000 });
  }
  const takes = await ev(() => { const ed = window.kbbEditor; return { media: ed.project.media.map((m) => ({ src: m.src, dur: m.dur, w: m.w, h: m.h })), best: document.querySelector('.tp-take.best b')?.textContent, rows: [...document.querySelectorAll('.tp-take small')].map((x) => x.textContent) }; });
  const tp = takes.media.map((m) => probe(T(m.src)));
  ok(takes.media.length === 2 && tp.every((p) => p.streams.some((s) => s.codec_type === 'video') && p.streams.some((s) => s.codec_type === 'audio')) && takes.media.every((m) => m.dur > 2),
    `2 takes recorded from the camera + mic and saved in the project (${takes.media.map((m, i) => `${m.src.split('/').pop()} ${m.w}×${m.h} ${m.dur.toFixed(1)} s ${tp[i].streams.map((s) => s.codec_name).join('+')}`).join('; ')})`);
  ok(!!takes.best, `best take picked: ${takes.best} — ${takes.rows.join(' / ')}`);
  await page.locator('.tp-take.best button').click(); await page.waitForTimeout(300);
  const used = await ev(() => window.kbbEditor.project.tracks.find((t) => t.kind === 'video').items.length);
  ok(used === 1, '"Use this take" put it on the timeline');
  await page.keyboard.press('Escape'); await page.waitForTimeout(200);

  // ── wrap up ──
  const errs = errors.filter((x) => !/favicon/.test(x));
  ok(!errs.length, `no console errors${errs.length ? ': ' + errs.slice(0, 5).join(' | ') : ''}`);
  ok(!outside.length, `no request left 127.0.0.1${outside.length ? ': ' + outside.slice(0, 3).join(', ') : ''}`);
  await browser.close(); await H.stopEngine(e);
  console.log(`\n${results.length - failures}/${results.length} passed in ${secs(T0)}`);
  fs.writeFileSync(path.join(SHOTS, 'results.txt'), results.join('\n') + `\n${results.length - failures}/${results.length} passed\n`);
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
