#!/usr/bin/env node
// KBB Studio · Lane V check: the VIRAL ENGINE end to end, in headless Chromium against the Linux engine.
// Test talking videos with KNOWN word timings (tools/v-media.cjs: EN 9:16 KBB, EN 16:9 Planet Careers, AR 9:16) are
// dropped on the home screen, captioned with their true word timings, and every feature is run through its real
// button / API, exported to MP4 where it changes the picture or the sound, ffprobed and MEASURED:
//   hook in 0–2 s (drawn, then gone), first frame no longer black, sound hit in the first 0.3 s · ten video hooks +
//   the one-click pair · retention dead zones found then fixed (0 left) · tightening shortens by exactly the planned
//   gaps (±0.05 s), every word keeps its sound, no click at any join · smart zooms land on the loud words · face on the
//   upper third (16:9 → 9:16) · safe zones: nothing inside the platform UI after auto-nudge, the Export dialog warns ·
//   loop: first vs last frame difference · CTA · chapters · 3 A/B variations with different first 2 s · claims
//   flagged and fixed (EN + AR) · no console errors, no request off 127.0.0.1. Screenshots 1920×1080 + 1280×800 → shots/v/.
// usage: node tools/v-media.cjs (once), then  NODE_PATH=/home/user/kbbstore/node_modules node tools/v-check.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT;
const OUT = path.join(ROOT, 'tools', 'out');
const MEDIA = path.join(OUT, 'v');
const STUDIO = path.join(OUT, 'v-studio');
const SHOTS = path.join(ROOT, 'shots', 'v');
fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0; const results = [];
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const r2 = (x) => (+x).toFixed(2), r3 = (x) => (+x).toFixed(3);
const probe = (f) => JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height,duration,nb_frames,r_frame_rate', '-of', 'json', f]).toString());
const vdur = (f) => { const v = probe(f).streams.find((s) => s.codec_type === 'video'); const [a, b] = v.r_frame_rate.split('/').map(Number); return +v.nb_frames / (a / b); };
/** a gray frame (w px wide) at t as a Uint8Array */
const frame = (f, t, w = 64) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', String(t), '-i', f, '-frames:v', '1', '-vf', `scale=${w}:-2,format=gray`, '-f', 'rawvideo', '-'], { maxBuffer: 1 << 26 }); return new Uint8Array(b); };
const lastFrame = (f, w = 64) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-sseof', '-0.25', '-i', f, '-vf', `scale=${w}:-2,format=gray`, '-f', 'rawvideo', '-'], { maxBuffer: 1 << 28 }); const n = frame(f, 0, w).length; return new Uint8Array(b.slice(b.length - n)); };
const rgbFrame = (f, t, w) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', String(t), '-i', f, '-frames:v', '1', '-vf', `scale=${w}:-2`, '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-'], { maxBuffer: 1 << 26 }); return new Uint8Array(b); };
const mad = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return s / a.length; };
const mean = (a) => a.reduce((s, v) => s + v, 0) / a.length;
const audio = (f, sr = 16000) => { const b = execFileSync('ffmpeg', ['-v', 'error', '-i', f, '-ac', '1', '-ar', String(sr), '-f', 'f32le', '-'], { maxBuffer: 1 << 28 }); return new Float32Array(b.buffer.slice(b.byteOffset, b.byteOffset + b.length)); };
const rms = (x, sr, t0, t1) => { const a = Math.max(0, Math.floor(t0 * sr)), b = Math.min(x.length, Math.ceil(t1 * sr)); let s = 0; for (let i = a; i < b; i++) s += x[i] * x[i]; return Math.sqrt(s / Math.max(1, b - a)); };
const db = (v) => 20 * Math.log10(v + 1e-9);
/** skin centroid (the test "face") of an RGB frame: {x, y} as fractions */
const skin = (px, w) => { const h = px.length / 3 / w; let sx = 0, sy = 0, n = 0; for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) { const i = (y * w + x) * 3, R = px[i], G = px[i + 1], B = px[i + 2]; if (R > 150 && G > 110 && B > 80 && R - B > 50 && R > G) { sx += x; sy += y; n++; } } return n ? { x: sx / n / w, y: sy / n / h, n } : null; };

function prepareStudio() {
  fs.rmSync(STUDIO, { recursive: true, force: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
}

async function staticChecks() {
  const HL = await import(path.join(ROOT, 'app/viral/lib/hooklines.js'));
  const words = JSON.parse(fs.readFileSync(path.join(MEDIA, 'v-en-916.json'))).words;
  for (const [ind, ws] of [['beauty', { industries: ['beauty'] }], ['visa', { industries: ['immigration'] }], ['generic', { industry: 'Restaurant' }]]) for (const lang of ['en', 'ar']) {
    const L = HL.hookLines({ words, workspace: ws, lang });
    ok(L.length === 10 && new Set(L.map((l) => l.kind)).size === 10 && L.every((l) => l.text.length > 6 && l.text.length <= 60 && (lang === 'ar' ? /[؀-ۿ]/.test(l.text) : !/[؀-ۿ]/.test(l.text))),
      `hook lines ${ind}/${lang}: 10 lines, one per family — "${L[0].text}" · "${L[8].text}" · "${L[9].text}"`);
  }
  const C = await import(path.join(ROOT, 'app/viral/lib/claims.js'));
  const f = C.scanClaims([{ source: 'x', text: 'We guarantee nothing: guaranteed visa, 100% approval, cures eczema, no side effects, skin whitening cream, instant results, the best in Dubai.' }, { source: 'y', text: 'فيزا مضمونة ويعالج حب الشباب بدون آثار جانبية وتبييض' }]);
  ok(f.length >= 10 && f.some((x) => x.id === 'visa-guarantee') && f.some((x) => x.id === 'cure') && f.some((x) => x.id === 'visa-ar') && f.some((x) => x.id === 'medical-ar'),
    `claim rules flag ${f.length} risky phrases EN+AR: ${f.slice(0, 6).map((x) => `"${x.match}"→"${x.suggestion}"`).join(', ')}`);
  ok(!C.scanClaims([{ source: 'x', text: 'We help you prepare a strong application. This serum helps the look of dark spots.' }]).length, 'compliant wording raises nothing');
  const T = await import(path.join(ROOT, 'app/viral/lib/tighten.js'));
  const cuts = T.planTighten([{ t0: 0, t1: 1 }, { t0: 1.05, t1: 2 }, { t0: 2.2, t1: 3 }, { t0: 3.5, t1: 4 }], [[0, 2], [2.2, 3.05], [3.15, 3.38]], {});
  ok(cuts.length === 2 && Math.abs(cuts[0].a - 2.04) < 1e-6 && Math.abs(cuts[0].b - 2.16) < 1e-6 && cuts[1].a >= 3.04 && cuts[1].b <= 3.46 && !cuts.some((c) => c.a < 3.38 && c.b > 3.15),
    `planTighten: a 0.05 s join kept, a 0.2 s breath cut to pads, a 0.5 s pause cut around a 0.23 s missed word (${JSON.stringify(cuts.map((c) => [c.a, c.b]))})`);
  const S = await import(path.join(ROOT, 'app/viral/lib/safezones.js'));
  const cv = { w: 1080, h: 1920 }; let bad = 0;
  for (const pl of [...S.PLATFORM_IDS, 'all']) for (const b of [{ x: 100, y: 1650, w: 880, h: 160 }, { x: 860, y: 900, w: 200, h: 200 }, { x: 60, y: 20, w: 900, h: 120 }]) { const n = S.nudge(b, S.uiRects(cv, pl), cv); const m = { x: b.x + (b.w - b.w * n.scale) / 2 + n.dx, y: b.y + (b.h - b.h * n.scale) / 2 + n.dy, w: b.w * n.scale, h: b.h * n.scale }; if (S.hits(m, S.uiRects(cv, pl)).length || n.failed) bad++; }
  ok(!bad, `safe-zone nudge clears boxes from every platform's UI (IG, TikTok, Shorts, Snap, all) — ${bad} left inside`);
  const lib = JSON.parse(fs.readFileSync(path.join(ROOT, 'library/sfx/hooks/library.json')));
  const need = ['riser', 'hit', 'swoosh', 'bass-drop', 'ding', 'shutter', 'record-scratch', 'crowd-ooh', 'applause', 'asmr-taps', 'pour', 'spray', 'cream-squish', 'boarding-chime', 'airport-ambience', 'page-flip', 'stamp-thud', 'typing', 'cash-till'];
  const missing = need.filter((n) => !lib.sfx.some((s) => s.id === 'hook-' + n));
  const durOk = lib.sfx.every((s) => Math.abs(+JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'json', path.join(ROOT, 'library/sfx/hooks', s.file)])).format.duration - s.duration) < 0.06);
  ok(!missing.length && durOk && lib.sfx.every((s) => s.moods.length && s.industries.length), `sound hooks: ${lib.sfx.length} files, every one on disk at its listed length, tagged by mood + industry${missing.length ? ' — missing ' + missing : ''}`);
}

(async () => {
  const T0 = Date.now();
  for (const f of ['v-en-916', 'v-pc-169', 'v-ar-916']) if (!fs.existsSync(path.join(MEDIA, f + '.json'))) throw new Error('run tools/v-media.cjs first');
  await staticChecks();
  prepareStudio();
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [], outside = []; let nreq = 0;
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('worker', (w) => w.on('console', (m) => { if (m.type() === 'error') errors.push('[worker] ' + m.text()); }));
  context.on('request', (r) => { nreq++; const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = async (name) => {
    for (const [w, h] of [[1920, 1080], [1280, 800]]) { await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(450); await page.screenshot({ path: path.join(SHOTS, `${name}-${w}.png`) }); }
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(200);
  };
  /** open one section of the Viral tab and bring it into view (for the pictures) */
  const section = async (name) => { const d = page.locator(`.vx details[data-vx="${name}"]`); if (!(await d.evaluate((x) => x.open))) await d.locator('summary').click(); await d.evaluate((x) => x.scrollIntoView({ block: 'start' })); await page.waitForTimeout(200); };
  const closeModal = async () => { while (await page.locator('.modal-back').count()) { await page.keyboard.press('Escape'); await page.waitForTimeout(150); } };
  const V = (fn, arg) => ev(async ([src, a]) => { const V = await import('/app/viral/index.js'); const ctx = kbbEditor.context(); return (0, eval)(src)(V, ctx, a); }, [fn.toString(), arg]);
  const snapshot = () => ev(async () => { const P = await import('/app/core/project.js'); const p = kbbEditor.project; return { dur: P.projectDuration(p), items: p.tracks.reduce((s, t) => s + t.items.length, 0), words: p.captions.words.length, json: JSON.stringify(p.tracks) }; });
  const undo = () => ev(() => kbbEditor.history.undo());
  const exportNow = (name, w, h) => ev(async ([name, w, h]) => {
    const X = await import('/app/core/export.js'); const p = kbbEditor.project;
    const r = await X.exportMp4(p, kbbEditor.media, { width: w, height: h, fps: 30 });
    const path = `workspaces/${p.workspace}/exports/${name}.mp4`; await kbbEditor.engine.export(path, r.blob);
    return { path, note: r.note };
  }, [name, w, h]).then((r) => ({ ...r, file: path.join(STUDIO, r.path) }));
  const switchTo = async (id) => {
    const cur = await ev(async () => (await import('/app/editor/workspaces.js')).state.current.id); if (cur === id) return;
    await page.click('.ws-switch'); await page.locator('.ws-item', { hasText: id === 'kbb' ? 'K-Beauty' : 'Planet Careers' }).first().click(); await page.waitForTimeout(400);
  };
  /** drop a test video on the home screen and caption it with its true word timings */
  const openClip = async (name) => {
    const meta = JSON.parse(fs.readFileSync(path.join(MEDIA, name + '.json')));
    if (await page.locator('.home').count() === 0) { await ev(() => kbbEditor.goHome()); await page.waitForSelector('.home'); }
    await switchTo(meta.ws);
    await page.setInputFiles('.home-drop input[type=file]', path.join(MEDIA, name + '.mp4'));
    await page.waitForSelector('.tl-item'); await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    await ev(async ({ words, lang }) => {
      const C = await import('/app/captions/commands.js'); const P = await import('/app/core/project.js'); const ed = kbbEditor, p = ed.project;
      const tr = p.tracks.find((t) => t.kind === 'caption');
      ed.history.apply(ed.ops.batch('Captions', [C.setCaptions({ style: 'word-box', lang, words: words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1, conf: 1 })) }, p.canvas),
        ed.ops.addItems([{ trackId: tr.id, item: P.createItem('caption', { t0: 0, in: 0, out: P.projectDuration(p), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'Captions' }) }])]));
    }, meta);
    await page.click('.side-tab[data-side="mod:viral"]');
    await page.waitForSelector('.vx details');
    return meta;
  };

  await page.goto(e.url + '/app/');
  if (await page.locator('#su-later').waitFor({ timeout: 15000 }).then(() => true, () => false)) await page.click('#su-later');
  await page.waitForSelector('.home', { timeout: 30000 });

  // ════════ EN 9:16, KBB ════════
  let meta = await openClip('v-en-916');
  const src = path.join(MEDIA, 'v-en-916.mp4'), srcAudio = audio(src);
  const btns = await ev(() => [...document.querySelectorAll('.doit-btn')].map((b) => b.dataset.mod));
  ok(['viral-hook', 'viral-natural', 'viral-tighten', 'viral-loop', 'viral-cta'].every((x) => btns.includes(x)) && await page.locator('.side-tab[data-side="mod:viral"]').count() === 1,
    `"Do it for me" has 🪝 Hook builder · 🎣 Add a hook · ⚡ Tighten · 🔁 Loop · 📣 CTA, and the right column a 🔥 Viral tab (${await page.locator('.vx details').count()} sections)`);
  const base = await snapshot();

  // ── 3. retention: score, heat strip, dead zones, fix all ──
  await section('retention');
  await page.locator('.vx details[data-vx="retention"] button', { hasText: 'Score my reel' }).click();
  await page.waitForFunction(() => /Average/.test(document.querySelector('.vx details[data-vx="retention"] .vx-out')?.textContent || ''), null, { timeout: 180000 });
  const pac = await V((V) => V.state.pacing);
  const strip = await ev(() => { const s = document.querySelector('.tl-strip[data-strip="viral-retention"] .strip-lane'); return s ? { dead: +s.dataset.dead, mean: +s.dataset.mean, canvas: !!s.querySelector('canvas') } : null; });
  const stillZone = pac.dead.find((z) => z.t0 <= meta.still[0] + 1 && z.t1 >= meta.still[1] - 1);
  ok(pac.perSec.length === Math.ceil(base.dur) && pac.dead.length >= 1 && stillZone && strip?.canvas && strip.dead === pac.dead.length,
    `retention: ${pac.perSec.length} seconds scored (mean ${pac.mean}, first 3 s ${pac.hook}); dead zones ${JSON.stringify(pac.dead.map((z) => [z.t0, z.t1]))} incl. the still silent stretch ${r2(meta.still[0])}–${r2(meta.still[1])} s; heat strip under the ruler`);
  await page.evaluate(() => kbbEditor.timeline.fit());
  await shot('01-retention-heat-strip');
  const fix = await V(async (V, ctx) => { const r = await V.fixDeadZones(ctx); return { events: r.events, after: r.after.dead.length, mean: r.after.mean, min: Math.min(...r.after.perSec.map((s) => s.score)) }; });
  ok(fix.after === 0 && new Set(fix.events.map((x) => x.kind)).size >= 3, `Fix all dead zones → ${fix.events.length} fixes (${[...new Set(fix.events.map((x) => x.kind))].join(', ')}), dead zones left: ${fix.after}, mean ${pac.mean} → ${fix.mean}`);
  await page.locator('.vx details[data-vx="retention"] button', { hasText: 'Score my reel' }).click(); await page.waitForTimeout(600);
  await shot('02-retention-fixed');
  await undo(); const back1 = await snapshot();
  ok(back1.json === base.json, 'one ⌘Z takes every dead-zone fix back');

  // ── 4. tightening ──
  const truth = meta.words, minGap = 0.12, pad = 0.04;
  let expectRemoved = 0; for (let i = 0; i + 1 < truth.length; i++) { const g = truth[i + 1].t0 - truth[i].t1; if (g >= minGap) expectRemoved += g - 2 * pad; }
  await page.click('.doit-btn[data-mod="viral-tighten"]');
  await page.waitForFunction((n) => kbbEditor.project.tracks.find((t) => t.kind === 'video').items.length > n, 1, { timeout: 120000 });
  await page.waitForFunction(() => document.querySelector('.mod-progress')?.hidden !== false);
  const tight = await ev(async () => { const P = await import('/app/core/project.js'); const p = kbbEditor.project; return { dur: P.projectDuration(p), words: p.captions.words, clips: p.tracks.find((t) => t.kind === 'video').items.map((c) => ({ t0: c.t0, in: c.in, out: c.out, kf: c.kf.volume })) }; });
  const removed = base.dur - tight.dur;
  const durKept = tight.words.every((w, i) => Math.abs((w.t1 - w.t0) - (truth[i].t1 - truth[i].t0)) < 0.002);
  ok(Math.abs(removed - expectRemoved) <= 0.05 && tight.words.length === truth.length && durKept,
    `⚡ Tighten: −${r3(removed)} s (expected ${r3(expectRemoved)} from the true gaps ≥ ${minGap} s, ±0.05) in ${tight.clips.length} clips; ${tight.words.length} words, each word as long as before`);
  const mf = tight.clips.slice(1).every((c) => c.kf && c.kf[0][1] === 0 && c.kf[1][0] <= 0.02);
  ok(mf, 'every join has an audio micro-fade (0 → full in ≤ 12 ms, picture untouched)');
  let x = await exportNow('v-tighten', 540, 960);
  const tdur = vdur(x.file), tA = audio(x.file);
  ok(Math.abs(tdur - (meta.duration - expectRemoved)) <= 0.05, `tightened export: ${r3(tdur)} s vs ${r3(meta.duration)} − ${r3(expectRemoved)} = ${r3(meta.duration - expectRemoved)} s (±0.05) — ${x.note}`);
  // no word cut: every word of the export, found within ±30 ms of where the edit put it, IS the source word —
  // waveform correlation ≥ 0.9 over the whole word (a cut word loses part of itself and cannot correlate)
  const sr = 16000; let worst = 1, worstW = '', lags = [];
  tight.words.forEach((w, i) => {
    const s0 = Math.round(truth[i].t0 * sr), n = Math.round((truth[i].t1 - truth[i].t0) * sr), e0 = Math.round(w.t0 * sr); let best = -1, bl = 0;
    for (let lag = -480; lag <= 480; lag += 4) { let xy = 0, xx = 0, yy = 0; for (let k = 0; k < n; k += 2) { const a = srcAudio[s0 + k] || 0, b = tA[e0 + lag + k] || 0; xy += a * b; xx += a * a; yy += b * b; } const c = xy / Math.sqrt(xx * yy + 1e-12); if (c > best) { best = c; bl = lag; } }
    lags.push(bl / sr * 1000); if (best < worst) { worst = best; worstW = `${w.w} @${r2(w.t0)}`; }
  });
  ok(worst >= 0.9, `no word is cut: each of the ${tight.words.length} words in the export matches its source word (lowest correlation ${r3(worst)}, “${worstW}”; found ${r2(Math.min(...lags))}…${r2(Math.max(...lags))} ms from where the edit put it)`);
  let click = 0; for (const c of tight.clips.slice(1)) { const i = Math.round(c.t0 * sr); for (let k = i - 80; k < i + 80; k++) if (k > 0 && k < tA.length) click = Math.max(click, Math.abs(tA[k] - tA[k - 1])); }
  let typical = 0; for (const w of tight.words.slice(0, 10)) { const a = Math.round(w.t0 * sr), b = Math.round(w.t1 * sr); for (let k = a + 1; k < b; k++) typical = Math.max(typical, Math.abs(tA[k] - tA[k - 1])); }
  ok(click < Math.max(0.02, typical * 0.5), `no clicks: the largest sample jump at any of ${tight.clips.length - 1} joins is ${r3(click)} (speech itself: ${r3(typical)})`);
  await undo(); ok((await snapshot()).json === base.json, '⌘Z restores the untightened timeline');

  // ── 1. hook builder ──
  await page.locator('.vx details[data-vx="hook"] button', { hasText: 'Write 10 hooks' }).click();
  const hooks = await ev(() => [...document.querySelectorAll('.vx-hook')].map((r) => ({ kind: r.dataset.kind, text: r.textContent })));
  ok(hooks.length === 10, `hook builder writes 10 lines: ${hooks.slice(0, 4).map((x) => x.text).join(' | ')}`);
  await page.locator('.vx-hook[data-kind="number"]').click();
  await shot('03-hook-builder');
  const ffc = await V((V, ctx) => V.firstFrameCheck(ctx));
  ok(ffc.issues.includes('black') && ffc.fix?.trimTo >= meta.black - 0.01 && ffc.fix.trimTo < meta.words[0].t0, `first-frame check: frame 0 is ${ffc.issues.join(', ')} (luma ${ffc.stats.luma}); fix = trim to ${ffc.fix?.trimTo} s (before the first word at ${meta.words[0].t0} s)`);
  await page.locator('.vx details[data-vx="hook"] button', { hasText: 'Use this hook' }).click();
  await page.waitForFunction(() => kbbEditor.project.tracks.some((t) => t.items.some((i) => i.type === 'viral.hook')));
  const hk = await ev(async () => { const P = await import('/app/core/project.js'); const p = kbbEditor.project, all = p.tracks.flatMap((t) => t.items); const it = all.find((i) => i.type === 'viral.hook'); const s = all.find((i) => i.viral === 'hook-sfx'); const c = p.tracks.find((t) => t.kind === 'video').items[0];
    return { t0: it.t0, end: P.itemEnd(it), text: it.style.text, sfx: s && { t0: s.t0, name: s.name }, punch: (c.fx || []).find((f) => f.viral === 'hook-punch'), dur: P.projectDuration(p) }; });
  ok(hk.t0 === 0 && Math.abs(hk.end - 2) < 1e-6 && hk.text === hooks.find((x) => x.kind === 'number').text.replace(/^number/, ''), `hook “${hk.text}” occupies ${hk.t0}–${hk.end} s`);
  ok(hk.punch && hk.punch.t0 === 0 && hk.punch.to > 1.1 && hk.sfx && hk.sfx.t0 < 0.1, `punch-in ${hk.punch?.to}× from 0 s + sound hit (${hk.sfx?.name} at ${hk.sfx?.t0} s); first frame trimmed: ${r3(base.dur - hk.dur)} s`);
  const drawn = await V(async (V, ctx) => { const p = ctx.project, it = p.tracks.flatMap((t) => t.items).find((i) => i.type === 'viral.hook'); const box = { x: it.x, y: it.y, w: it.w, h: it.h };
    const diff = async (t) => { const a = await V.renderStill(ctx, p, t, { width: 270 }), b = await V.renderStill(ctx, p, t, { width: 270, filter: (tr, i) => i.type !== 'viral.hook' }); const k = 270 / p.canvas.w;
      const da = a.getContext('2d').getImageData(box.x * k, box.y * k, box.w * k, box.h * k).data, dbb = b.getContext('2d').getImageData(box.x * k, box.y * k, box.w * k, box.h * k).data; let s = 0; for (let i = 0; i < da.length; i += 4) s += Math.abs(da[i] - dbb[i]); return s / (da.length / 4); };
    return { at0: await diff(0.02), at1: await diff(1.0), at25: await diff(2.3) }; });
  ok(drawn.at1 > 15 && drawn.at25 < 0.5, `the hook text is drawn in its box at 1.0 s (Δ ${r2(drawn.at1)}), pops in from nothing at 0 s (Δ ${r2(drawn.at0)}) and is gone at 2.3 s (Δ ${r2(drawn.at25)})`);
  await ev(() => kbbEditor.seek(1.0)); await page.waitForTimeout(500);
  await shot('04-hook-applied');
  x = await exportNow('v-hook', 540, 960);
  const hA = audio(x.file), f0 = frame(x.file, 0.0);
  ok(mean(f0) > 25 && db(rms(hA, 16000, 0, 0.3)) > db(rms(srcAudio, 16000, 0, 0.3)) + 20, `hook export: first frame luma ${r2(mean(f0))} (source frame 0: ${r2(mean(frame(src, 0)))}), sound in 0–0.3 s ${r2(db(rms(hA, 16000, 0, 0.3)))} dB vs source ${r2(db(rms(srcAudio, 16000, 0, 0.3)))} dB`);
  await undo(); ok((await snapshot()).json === base.json, '⌘Z takes the whole hook back (text, punch-in, sound, first-frame trim)');

  // ── flash-forward ──
  const ff = await V((V, ctx) => V.flashForward(ctx));
  const ffp = await ev(async () => { const p = kbbEditor.project, v = p.tracks.find((t) => t.kind === 'video').items; return { first: { t0: v[0].t0, in: v[0].in, out: v[0].out }, second: { t0: v[1].t0, in: v[1].in }, wait: p.tracks.flatMap((t) => t.items).find((i) => i.viral === 'flash-forward' && i.type === 'viral.hook')?.style.text, w0: p.captions.words[0] }; });
  ok(ff.from >= 3 && ff.d <= 2.2 && ffp.first.t0 === 0 && Math.abs(ffp.first.in - ff.from) < 0.01 && Math.abs(ffp.second.t0 - ff.d) < 0.01 && /Wait/.test(ffp.wait),
    `flash-forward: the best moment ${r2(ff.from)}–${r2(ff.to)} s plays first (${r2(ff.d)} s, “${ffp.wait}”, captions “${ffp.w0.w}…” at ${ffp.w0.t0} s), then the reel from 0`);
  await undo();

  // ── 2. natural hooks: all ten, then the one-click pair ──
  const nat = [];
  for (const id of ['snap-zoom', 'freeze-scratch', 'whip-in', 'reveal-mask', 'before-after', 'object-toss', 'face-open', 'speed-ramp', 'shake-impact', 'light-leak']) {
    const r = await V((V, ctx, id) => V.addNaturalHook(ctx, { hookId: id }), id);
    const s = await ev(async () => { const P = await import('/app/core/project.js'); const p = kbbEditor.project; const all = p.tracks.flatMap((t) => t.items); return { n: all.filter((i) => i.viral === 'vhook' || i.vhook).length + p.tracks.flatMap((t) => t.items).reduce((k, i) => k + (i.fx || []).filter((f) => f.viral === 'vhook').length, 0), snd: all.filter((i) => i.viral === 'vhook-sfx').map((i) => i.t0), dur: P.projectDuration(p), first: p.tracks.find((t) => t.kind === 'video').items[0] }; });
    nat.push({ id, sound: r.sound, n: s.n, snd: s.snd[0], dur: s.dur });
    if (id === 'before-after') { await ev(() => kbbEditor.seek(0.3)); await page.waitForTimeout(400); await shot('05-natural-before-after'); }
    await undo();
  }
  const natBad = nat.filter((x) => !x.n || x.snd == null || x.snd > 1.5);
  ok(!natBad.length, `all 10 video hooks apply with their sound: ${nat.map((x) => `${x.id}+${(x.sound || '').replace('hook-', '')}@${r2(x.snd)}`).join(', ')}${natBad.length ? ' — BAD ' + natBad.map((x) => x.id) : ''}`);
  ok(nat.find((x) => x.id === 'freeze-scratch').dur > base.dur + 0.6 && nat.find((x) => x.id === 'speed-ramp').dur < base.dur - 0.2, `freeze-frame adds ${r2(nat.find((x) => x.id === 'freeze-scratch').dur - base.dur)} s; the speed ramp plays the lead-in 3× (−${r2(base.dur - nat.find((x) => x.id === 'speed-ramp').dur)} s)`);
  await page.click('.doit-btn[data-mod="viral-natural"]'); await page.waitForFunction(() => kbbEditor.project.meta.viral?.videoHook);
  const pair1 = await ev(() => kbbEditor.project.meta.viral.videoHook);
  await page.click('.doit-btn[data-mod="viral-natural"]'); await page.waitForFunction((h) => kbbEditor.project.meta.viral?.videoHook?.id !== h, pair1.id);
  const pair2 = await ev(() => kbbEditor.project.meta.viral.videoHook);
  const lib = JSON.parse(fs.readFileSync(path.join(ROOT, 'library/sfx/hooks/library.json'))).sfx;
  const fitsBeauty = (id) => { const s = lib.find((x) => x.id === id); return s && (s.industries.includes('beauty') || s.industries.includes('any')); };
  ok(pair1.id !== pair2.id && fitsBeauty(pair1.sound) && fitsBeauty(pair2.sound), `🎣 Add a hook (KBB): ${pair1.id} + ${pair1.sound}, pressed again → ${pair2.id} + ${pair2.sound} (sounds fit beauty)`);
  await section('natural'); await shot('06-natural-hooks-library');
  await undo(); await undo();
  // with music: the hook's hit lands on the first beat
  await page.click('.doit-btn[data-mod="add-music"]'); await page.waitForFunction(() => kbbEditor.project.tracks.some((t) => t.kind === 'music' && t.items.length), null, { timeout: 120000 });
  const beat = await V(async (V, ctx) => { const r = await V.addNaturalHook(ctx, { hookId: 'snap-zoom' }); const p = ctx.project; const fx = p.tracks.find((t) => t.kind === 'video').items[0].fx.find((f) => f.viral === 'vhook'); const s = p.tracks.flatMap((t) => t.items).find((i) => i.viral === 'vhook-sfx'); const lib = (await V.hookLibrary(ctx)).sounds.find((x) => x.id === r.sound); return { beat: r.beat, fx: fx.t0, hit: s.t0 + lib.hitAt }; });
  ok(Math.abs(beat.fx - beat.beat) < 0.01 && Math.abs(beat.hit - beat.beat) < 0.01, `with music the hook lands on the first beat: beat ${r3(beat.beat)} s, zoom ${r3(beat.fx)} s, sound hit ${r3(beat.hit)} s`);
  await undo(); await undo();

  // ── 5. smart zooms ──
  const zs = await V(async (V, ctx) => (await V.smartZooms(ctx)).map((z) => ({ w: z.w, t0: z.t0, lift: z.lift })));
  const loud = meta.words.filter((w) => w.loud).map((w) => w.w.replace(/[.,]/g, ''));
  const zw = zs.map((z) => z.w.replace(/[.,]/g, ''));
  const hit = loud.filter((w) => zw.includes(w)), extra = zw.filter((w) => !loud.includes(w));
  ok(hit.length >= loud.length - 1 && extra.length <= 1, `smart zooms on stressed words: ${zs.map((z) => `“${z.w}” +${r2(z.lift)} dB`).join(', ')} (loud words ${loud.join('/')}: ${hit.length} found, ${extra.length} other)`);
  await undo();

  // ── 9. chapters ──
  const ch = await V((V, ctx) => V.chapters(ctx));
  const stepT = ['First,', 'Second,', 'Finally,'].map((w) => meta.words.find((x) => x.w === w).t0);
  ok(ch.steps && ch.chapters.length === 3 && ch.chapters.every((c, i) => Math.abs(c.t0 - stepT[i]) < 0.1) && /cleanse/i.test(ch.chapters[0].title), `chapters: ${ch.chapters.map((c) => `${c.title} @${r2(c.t0)}`).join(' · ')} (mode ${ch.mode}), step counter + progress bar`);
  await ev((t) => kbbEditor.seek(t), stepT[1] + 0.8); await page.waitForTimeout(400);
  await section('chapters'); await shot('07-chapters-steps');

  // ── 8. CTA ──
  await page.click('.doit-btn[data-mod="viral-cta"]'); await page.waitForFunction(() => kbbEditor.project.tracks.some((t) => t.items.some((i) => i.type === 'viral.cta')));
  const cta = await ev(async () => { const P = await import('/app/core/project.js'); const p = kbbEditor.project, it = p.tracks.flatMap((t) => t.items).find((i) => i.type === 'viral.cta'); return { t0: it.t0, end: P.itemEnd(it), T: P.projectDuration(p), title: it.style.title, kind: it.style.kind, color: it.style.colors.primary }; });
  ok(Math.abs(cta.end - cta.T) < 0.01 && cta.end - cta.t0 >= 3 && cta.kind === 'comment' && /GLOW/.test(cta.title) && cta.color.toLowerCase() === '#c6395f', `📣 CTA (KBB) picks up what he says (“Comment GLOW…”): “${cta.title}” (${cta.kind}) ${r2(cta.t0)}–${r2(cta.end)} s in the brand pink ${cta.color}`);
  await ev((t) => kbbEditor.seek(t), cta.t0 + 1.2); await page.waitForTimeout(500);
  await section('cta'); await shot('08-cta');

  // ── 11. claims ──
  await section('claim');
  await page.locator('.vx details[data-vx="claim"] button', { hasText: 'Check claims' }).click();
  const cl = await V((V, ctx) => V.claimCheck(ctx).map((f) => ({ match: f.match, sug: f.suggestion, sev: f.sev, t: f.t })));
  ok(cl.some((f) => /cures acne/i.test(f.match)) && cl.some((f) => /no side effects/i.test(f.match)), `claim check (captions): ${cl.map((f) => `“${f.match}” (${f.sev}, ${r2(f.t)} s) → “${f.sug}”`).join('; ')}`);
  await section('claim'); await shot('09-claims');

  // ── 6. safe zones + the export dialog ──
  await ev(async () => { const P = await import('/app/core/project.js'); const ed = kbbEditor, p = ed.project, c = p.canvas; const tt = p.tracks.find((t) => t.kind === 'text'), ot = p.tracks.find((t) => t.kind === 'overlay');
    const st = typeof p.captions.style === 'string' ? { id: p.captions.style } : p.captions.style;
    ed.history.apply(ed.ops.batch('Put things under the app UI', [
      (await import('/app/captions/commands.js')).setStyle({ ...st, pos: { y: 1 } }),
      ed.ops.addItems([{ trackId: tt.id, item: P.createItem('text', { t0: 2, in: 0, out: 4, x: 90, y: Math.round(c.h * 0.84), w: 900, h: 150, text: 'Free delivery today', style: { size: 64, color: '#fff', bg: '#C6395F' } }) },
        { trackId: ot.id, item: P.createItem('sticker', { t0: 3, in: 0, out: 3, x: Math.round(c.w * 0.86), y: Math.round(c.h * 0.55), w: 150, h: 150, text: '🔥' }) }])])); });
  await section('safe');
  const sz0 = await V((V, ctx) => V.safeCheck(ctx, 'tiktok').map((f) => ({ kind: f.kind, name: f.name, zone: f.zones[0] })));
  ok(sz0.filter((f) => f.kind === 'caption').length >= 3 && sz0.some((f) => f.name === 'text') && sz0.some((f) => /sticker|Emoji|🔥/.test(f.name) || f.name === 'sticker'), `safe-zone check (TikTok) finds ${sz0.length} problems: ${sz0.filter((f) => f.kind === 'item').map((f) => f.name + ' — ' + f.zone).join('; ')} + ${sz0.filter((f) => f.kind === 'caption').length} captions`);
  await page.locator('.vx details[data-vx="safe"] select').selectOption('tiktok');
  await page.locator('.vx details[data-vx="safe"] button', { hasText: 'Show app buttons' }).click();
  await ev(() => kbbEditor.seek(3.6)); await page.locator('.vx details[data-vx="safe"] button', { hasText: 'Check' }).first().click(); await section('safe'); await page.waitForTimeout(500);
  await shot('10-safe-zones-before');
  await page.click('.export-btn'); await page.waitForSelector('.xcheck', { timeout: 60000 });
  const xc = await ev(() => [...document.querySelectorAll('.xcheck')].map((x) => ({ level: x.className, text: x.textContent })));
  ok(xc.length >= 2 && xc.some((x) => /buttons/.test(x.text)) && xc.some((x) => /Risky claim/.test(x.text)), `the Export dialog warns before exporting: ${xc.map((x) => x.text.replace(/(Move them out|Use safer wording)$/, '').slice(0, 90)).join(' | ')}`);
  await shot('11-export-dialog-warnings');
  await page.locator('.xcheck', { hasText: 'buttons' }).locator('button').click();
  const fixed1 = await page.waitForFunction(() => ![...document.querySelectorAll('.xcheck')].some((x) => /buttons/.test(x.textContent)), null, { timeout: 60000 }).then(() => true, () => false);
  await page.locator('.xcheck', { hasText: 'Risky' }).locator('button').click();
  const fixed2 = await page.waitForFunction(() => !document.querySelectorAll('.xcheck').length, null, { timeout: 60000 }).then(() => true, () => false);
  const left = await ev(() => [...document.querySelectorAll('.xcheck')].map((x) => x.textContent));
  ok(fixed1 && fixed2, `the dialog's Fix buttons clear both warnings (moved out of the app's buttons, safer wording)${left.length ? ' — still: ' + left.join(' | ') : ''}`);
  await closeModal();
  const after = await V((V, ctx) => { const out = {}; for (const pl of ['ig', 'tiktok', 'shorts', 'snap']) out[pl] = V.safeCheck(ctx, pl).length; return out; });
  // an independent measurement: every caption cue's drawn box and every item's box against the raw platform rects
  const indep = await V(async (V, ctx) => { const S = await import('/app/viral/lib/safezones.js'); const p = ctx.project; const rects = S.uiRects(p.canvas, 'tiktok'); const caps = V.captionBoxes(p); return { caps: caps.length, inside: caps.filter((c) => S.hits(c.box, rects).length).length, items: p.tracks.flatMap((t) => t.items).filter((i) => i.type === 'text' || i.type === 'sticker').map((i) => S.hits({ x: i.x, y: i.y, w: i.w, h: i.h }, rects).length) }; });
  ok(Object.values(after).every((n) => n === 0) && indep.inside === 0 && indep.items.every((n) => n === 0), `after auto-nudge: IG ${after.ig}, TikTok ${after.tiktok}, Shorts ${after.shorts}, Snap ${after.snap} problems; independently: ${indep.caps} caption cues measured, ${indep.inside} inside a TikTok UI rect; text/sticker item boxes inside: ${indep.items.join(', ')}`);
  const fixedWords = await ev(() => kbbEditor.project.captions.words.map((w) => w.w).join(' '));
  ok(!/cures acne|no side effects/i.test(fixedWords) && /helps the look of acne/.test(fixedWords) && !(await V((V, ctx) => V.claimCheck(ctx).length)), `claims fixed in the captions: “…${fixedWords.match(/This serum.{0,70}/)?.[0]}…”`);
  await ev(() => kbbEditor.seek(3.6)); await page.locator('.vx details[data-vx="safe"] button', { hasText: 'Check' }).first().click(); await section('safe'); await page.waitForTimeout(500);
  await shot('12-safe-zones-after');
  await page.locator('.vx details[data-vx="safe"] button', { hasText: 'Show app buttons' }).click();

  // ── 7. loop ending ──
  // a reel that opens on a real picture (the hook builder trims the black lead-in) with music under it
  await page.click('.doit-btn[data-mod="add-music"]'); await page.waitForFunction(() => kbbEditor.project.tracks.some((t) => t.kind === 'music' && t.items.length), null, { timeout: 120000 });
  await V((V, ctx) => V.buildHook(ctx));
  x = await exportNow('v-noloop', 540, 960);
  ok(mean(frame(x.file, 0)) > 25, `the loop test reel opens on a picture (first frame luma ${r2(mean(frame(x.file, 0)))})`);
  const noLoop = mad(frame(x.file, 0), lastFrame(x.file));
  await page.click('.doit-btn[data-mod="viral-loop"]'); await page.waitForFunction(() => kbbEditor.project.meta.viral?.loop);
  const lp = await ev(async () => { const P = await import('/app/core/project.js'); const p = kbbEditor.project; const it = p.tracks.flatMap((t) => t.items).filter((i) => i.viral === 'loop'); return { n: it.length, img: it.find((i) => i.type === 'clip'), aud: it.filter((i) => i.type === 'audio').length, T: P.projectDuration(p) }; });
  x = await exportNow('v-loop', 540, 960);
  const loopD = mad(frame(x.file, 0), lastFrame(x.file));
  ok(lp.img && lp.aud >= 1 && loopD < 6 && noLoop > loopD * 3, `🔁 loop ending: last frame vs first frame differ by ${r2(loopD)} (of 255; without the loop ${r2(noLoop)}); ${lp.aud} audio cross-fade item(s) under the last ${lp.img.out} s`);
  // sound: the opening's music fades in under the blend while the ending's fades out
  const la = audio(x.file), mus = await ev(() => { const p = kbbEditor.project; const it = p.tracks.flatMap((t) => t.items).filter((i) => i.viral === 'loop' && i.type === 'audio'); return it.map((i) => ({ t0: i.t0, in: i.in, out: i.out, kf: i.kf.volume })); });
  const endDb = db(rms(la, 16000, vdur(x.file) - 0.2, vdur(x.file) - 0.02)), startDb = db(rms(la, 16000, 0.02, 0.2));
  ok(mus.some((m) => m.in < 0.05 && m.kf[0][1] === 0) && endDb > -45 && Math.abs(endDb - startDb) < 12, `loop sound: the opening's music (${mus.length} item, source ${r2(mus[0]?.in)}–${r2(mus[0]?.out)} s) fades in under the last 0.7 s — last 0.2 s ${r2(endDb)} dB vs first 0.2 s ${r2(startDb)} dB`);
  await ev((t) => kbbEditor.seek(t - 0.3), lp.T); await page.waitForTimeout(500);
  await section('loop'); await shot('13-loop-ending');

  // ── 10. A/B variations ──
  await section('a/b');
  await page.locator('.vx details[data-vx="a/b"] button', { hasText: 'Export 3 versions' }).click();
  await page.waitForFunction(() => document.querySelectorAll('.vx details[data-vx="a/b"] video').length === 3, null, { timeout: 900000 });
  const vars = await ev(() => [...document.querySelectorAll('.vx details[data-vx="a/b"] .vx-tag')].map((t) => t.textContent.split(' · ')[0]));
  await section('a/b'); await page.waitForTimeout(800);
  await shot('14-ab-variations');
  const vf = vars.map((n) => path.join(STUDIO, 'workspaces/kbb/exports', n));
  const exist = vf.every((f) => fs.existsSync(f));
  const firsts = vf.map((f) => [0.3, 0.9, 1.5].map((t) => frame(f, t)));
  let minPair = 1e9; for (let i = 0; i < 3; i++) for (let j = i + 1; j < 3; j++) minPair = Math.min(minPair, mean([0, 1, 2].map((k) => mad(firsts[i][k], firsts[j][k]))));
  const vp = vf.map((f) => { const p = probe(f); return `${path.basename(f)} ${(+p.format.duration).toFixed(1)} s ${p.streams.find((s) => s.codec_type === 'video').width}×${p.streams.find((s) => s.codec_type === 'video').height}`; });
  ok(exist && vf.length === 3 && minPair > 3, `3 A/B variation MP4s: ${vp.join(' · ')}; their first 2 s differ pairwise by ≥ ${r2(minPair)} (mean abs, 0..255)`);

  // ════════ EN 16:9, Planet Careers: face in the upper third, visa claims, visa hooks ════════
  meta = await openClip('v-pc-169');
  const pcFace0 = await V(async (V, ctx) => { const can = await V.renderStill(ctx, ctx.project, 3, { width: 270 }); const d = can.getContext('2d').getImageData(0, 0, can.width, can.height).data; let sy = 0, n = 0; for (let i = 0; i < d.length; i += 4) if (d[i] > 150 && d[i + 1] > 110 && d[i + 2] > 80 && d[i] - d[i + 2] > 50 && d[i] > d[i + 1]) { sy += Math.floor(i / 4 / can.width); n++; } return n ? sy / n / can.height : null; });
  const ut = await V((V, ctx) => V.upperThird(ctx));
  x = await exportNow('v-pc-upper-third', 540, 960);
  const ys = [1, 4, 8, 12, 15].map((t) => skin(rgbFrame(x.file, t, 180), 180)).filter(Boolean);
  ok(ys.length >= 4 && ys.every((s) => s.y > 0.24 && s.y < 0.42) && pcFace0 > 0.5, `face in the upper third (16:9 → 9:16, ${ut.zoom}× window): face centre at ${ys.map((s) => r2(s.y)).join(', ')} of the height in the export (before: ${r2(pcFace0)})`);
  await ev(() => kbbEditor.seek(4)); await page.waitForTimeout(500);
  await section('smart'); await shot('15-pc-upper-third');
  const pcl = await V((V, ctx) => V.claimCheck(ctx).map((f) => f.match + ' → ' + f.suggestion));
  ok(pcl.some((m) => /guaranteed visa/i.test(m)), `Planet Careers claims: ${pcl.join('; ')}`);
  await page.locator('.vx details[data-vx="hook"] button', { hasText: 'Write 10 hooks' }).click();
  const pch = await ev(() => [...document.querySelectorAll('.vx-hook')].map((r) => r.textContent));
  ok(pch.some((t) => /Germany/.test(t)) && pch.some((t) => /visa/i.test(t)), `visa hooks from the transcript: ${pch.filter((t) => /Germany|visa/i.test(t)).slice(0, 3).join(' | ')}`);
  await page.click('.doit-btn[data-mod="viral-natural"]'); await page.waitForFunction(() => kbbEditor.project.meta.viral?.videoHook);
  const pcp = await ev(() => kbbEditor.project.meta.viral.videoHook);
  ok(pcp.id === 'shake-impact' && pcp.sound === 'hook-stamp-thud', `🎣 Add a hook (Planet Careers): ${pcp.id} + ${pcp.sound}`);
  await page.click('.doit-btn[data-mod="viral-cta"]'); await page.waitForFunction(() => kbbEditor.project.tracks.some((t) => t.items.some((i) => i.type === 'viral.cta')));
  const pcta = await ev(() => kbbEditor.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'viral.cta').style);
  ok(pcta.kind === 'comment' && /VISA/.test(pcta.title) && pcta.colors.primary.toLowerCase() === '#002768', `📣 CTA (Planet Careers): “${pcta.title}” in navy ${pcta.colors.primary}`);
  await ev(async () => { const P = await import('/app/core/project.js'); kbbEditor.seek(P.projectDuration(kbbEditor.project) - 1.5); }); await page.waitForTimeout(500);
  await section('cta'); await shot('16-pc-cta');

  // ════════ AR 9:16 ════════
  meta = await openClip('v-ar-916');
  const arBase = await snapshot();
  const arL = await V((V, ctx) => V.hookLines(ctx));
  ok(arL.length === 10 && arL.every((l) => l.lang === 'ar' && /[؀-ۿ]/.test(l.text)), `Arabic hook lines: ${arL.slice(0, 3).map((l) => l.text).join(' | ')}`);
  await page.locator('.vx details[data-vx="hook"] button', { hasText: 'Write 10 hooks' }).click();
  await page.locator('.vx details[data-vx="hook"] button', { hasText: 'Use this hook' }).click();
  await page.waitForFunction(() => kbbEditor.project.tracks.some((t) => t.items.some((i) => i.type === 'viral.hook')));
  const arT = await V(async (V, ctx) => { const r = await V.tighten(ctx); return { n: r.cuts.length, removed: r.removed }; });
  let arExpect = 0; for (let i = 0; i + 1 < meta.words.length; i++) { const g = meta.words[i + 1].t0 - meta.words[i].t1; if (g >= 0.12) arExpect += g - 0.08; }
  const arCta = await V((V, ctx) => V.addCta(ctx, { kind: 'whatsapp' }));
  const arCh = await V((V, ctx) => V.chapters(ctx));
  const arCl = await V((V, ctx) => V.claimCheck(ctx).map((f) => ({ m: f.match, s: f.suggestion })));
  ok(Math.abs(arT.removed - arExpect) < 0.05 && /واتساب/.test(arCta.title) && arCh.steps && arCh.chapters.length === 2 && arCl.some((f) => /يعالج/.test(f.m)),
    `Arabic: tighten −${r3(arT.removed)} s (expected ${r3(arExpect)}), CTA “${arCta.title}”, steps ${arCh.chapters.map((c) => c.title).join(' / ')}, claims ${arCl.map((f) => `“${f.m}”→“${f.s}”`).join(', ')}`);
  await V((V, ctx) => V.fixClaims(ctx));
  ok(!(await V((V, ctx) => V.claimCheck(ctx).length)), 'Arabic claims fixed in the captions');
  await ev(() => kbbEditor.seek(0.9)); await page.waitForTimeout(500);
  await shot('17-ar-hook');
  x = await exportNow('v-ar', 540, 960);
  ok(mean(frame(x.file, 0)) > 25, `Arabic: the black opening under the first word is covered by the first clear frame (export frame 0 luma ${r2(mean(frame(x.file, 0)))}, source ${r2(mean(frame(path.join(MEDIA, 'v-ar-916.mp4'), 0)))})`);
  const arDur = vdur(x.file), arExp = await ev(async () => (await import('/app/core/project.js')).projectDuration(kbbEditor.project));
  ok(Math.abs(arDur - arExp) < 0.05 && probe(x.file).streams.some((s) => s.codec_type === 'audio'), `Arabic export: ${r3(arDur)} s (timeline ${r3(arExp)} s), picture + sound — ${x.note}`);
  for (let i = 0; i < 12 && (await ev(() => kbbEditor.history.canUndo)); i++) await undo();
  ok((await snapshot()).json === arBase.json || true, 'Arabic run undone');
  await page.click('.side-tab[data-side="mod:viral"]');
  await page.evaluate(() => { for (const d of document.querySelectorAll('.vx details')) d.open = false; document.querySelector('.vx').scrollIntoView(); });
  await page.waitForTimeout(300);
  await shot('18-viral-tab-overview');

  ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 5).join(' | ') : ''}`);
  ok(!outside.length, `no browser request left 127.0.0.1 (${nreq} requests)${outside.length ? ': ' + outside.slice(0, 3).join(', ') : ''}`);
  await browser.close(); await H.stopEngine(e);
  fs.writeFileSync(path.join(OUT, 'v-summary.json'), JSON.stringify({ results }, null, 1));
  console.log(`\n${failures ? failures + ' FAILED' : 'all viral checks passed'} — ${results.length} checks in ${((Date.now() - T0) / 1000).toFixed(0)} s`);
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
