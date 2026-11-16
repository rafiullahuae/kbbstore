#!/usr/bin/env node
// KBB Studio — integration check (round 2): the whole app, end to end, in headless Chromium against the Linux engine.
//
//   static:  every recipe's caption style (EN + AR) exists in the captions module; both brand kits pass
//            brands/kit.schema.json; the model manifest holds every file the speech worker loads (WASM + WebGPU)
//   engine:  /models/ is served to workers without the token (COOP/COEP/CORP), the API is not; models/ is read-only
//   app:     first run → Setup screen → the REAL model download through the engine → home; then for KBB (9:16 talking
//            video) and Planet Careers (16:9 talking video, reframed): import → Auto captions (whisper-base, WASM) →
//            edit a word → Suggest style → Auto reel 15 s → Add music → Auto effects → Export MP4 → ffprobe
//            (duration ±0.2 s, 1080×1920, audio) → reload restores the project; an Arabic clip captioned too.
//            No console errors; no browser request leaves 127.0.0.1; the engine only downloads after the Allow.
//
// usage: tools/int-media.cjs first, then  NODE_PATH=/home/user/kbbstore/node_modules node tools/int-check.cjs [--fresh]
//   --fresh   start without models (the Setup screen downloads them for real); otherwise models already in
//             tools/out/e2e-studio/models are kept and the Setup screen is skipped
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT;
const OUT = path.join(ROOT, 'tools', 'out');
const MEDIA = path.join(OUT, 'int');
const STUDIO = path.join(OUT, 'e2e-studio');
const SHOTS = path.join(ROOT, 'shots', 'int');
const FRESH = process.argv.includes('--fresh');
fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0;
const results = [];
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const secs = (t) => ((Date.now() - t) / 1000).toFixed(1) + ' s';
const ffprobe = (f) => JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height,duration', '-of', 'json', f]).toString());

async function staticChecks() {
  const R = await import(path.join(ROOT, 'app/auto/recipes/recipes.js'));
  const CS = await import(path.join(ROOT, 'app/auto/recipes/caption-styles.js'));
  const S = await import(path.join(ROOT, 'app/captions/styles.js'));
  const ids = new Set(S.STYLES.map((s) => s.id));
  const bad = [];
  for (const r of R.RECIPES) for (const lang of ['en', 'ar']) { const id = CS.captionStyleFor(r, lang); if (!ids.has(id)) bad.push(`${r.id}/${lang}=${id}`); }
  ok(R.RECIPES.length === 70 && !bad.length, `all ${R.RECIPES.length} recipes map to a real caption style in EN and AR (${ids.size} styles)${bad.length ? ': ' + bad.join(', ') : ''}`);
  const E = await import(path.join(ROOT, 'app/auto/recipes/engine.js'));
  const unknown = R.RECIPES.filter((r) => !ids.has(E.resolveRecipe(r, {}).captionStyle) || !ids.has(E.resolveRecipe(r, { lang: 'ar' }).captionStyle));
  ok(!unknown.length, 'resolveRecipe() hands out only real caption style ids');

  const K = await import(path.join(ROOT, 'app/editor/kits.js'));
  const schema = JSON.parse(fs.readFileSync(path.join(ROOT, 'brands/kit.schema.json'), 'utf8'));
  for (const slug of ['kbb', 'planet-careers']) {
    const kit = JSON.parse(fs.readFileSync(path.join(ROOT, 'brands', slug, 'kit.json'), 'utf8'));
    const errs = K.validateKit(kit, schema);
    ok(!errs.length, `brands/${slug}/kit.json passes kit.schema.json${errs.length ? ': ' + errs.join('; ') : ''}`);
    const ws = K.workspaceFromKit(kit, {});
    ok(ws.colors.primary === kit.colors.primary && ws.fonts.heading === kit.fonts.heading.family && ws.captions.style === kit.defaults.captionStyle && ids.has(ws.captions.style),
      `${slug}: workspace seeded from the kit (primary ${ws.colors.primary}, ${ws.fonts.heading}, captions "${ws.captions.style}", ${ws.favRecipes.length} favourite recipes)`);
  }
  const bogus = K.validateKit({ v: 1, id: 'Bad Slug', name: 'x', colors: { primary: 'pink' }, fonts: {}, logos: {} }, schema);
  ok(bogus.length >= 4, `a broken kit is refused by the same validator (${bogus.length} problems: ${bogus.slice(0, 3).join('; ')})`);
  const nk = K.kitFromWorkspace({ id: 'sunrise-cafe', name: 'Sunrise Cafe', industry: 'Restaurant / cafe', colors: { primary: '#aa3300', secondary: '#221100', accent: '#ffcc00', text: '#ffffff', bg: '#000000' }, fonts: { heading: 'Georgia', body: 'system-ui' }, endCard: { title: 'Sunrise', cta: 'Visit us' }, contact: 'sunrise.example' });
  ok(!K.validateKit(nk, schema).length, 'a company made in the app produces a kit that passes kit.schema.json');

  const man = JSON.parse(fs.readFileSync(path.join(ROOT, 'models/manifest.json'), 'utf8'));
  const M = await import(path.join(ROOT, 'app/captions/models.js'));
  for (const key of Object.keys(M.SPEECH_MODELS)) {
    const sm = M.SPEECH_MODELS[key], entry = man.models.find((m) => m.id === sm.id);
    const have = new Set((entry?.files || []).map((f) => f.path));
    const need = ['wasm', 'webgpu'].flatMap((d) => M.filesFor(key, M.dtypesFor(d)).map((f) => f.file));
    const miss = need.filter((f) => !have.has(f));
    ok(entry && entry.repo === sm.repo && !miss.length, `manifest ${sm.id} = ${sm.repo}, holds the WASM (q8) and WebGPU (fp16 enc / q4 dec) files${miss.length ? ' — missing ' + miss.join(', ') : ''}`);
  }
  ok(!man.models.some((m) => m.id === 'face-detector'), 'no separate face model download: MediaPipe BlazeFace is vendored (vendor/mediapipe/models)');
}

/** the e2e studio folder: fresh app/vendor/brands/library every run; models kept unless --fresh */
function prepareStudio() {
  if (FRESH) fs.rmSync(STUDIO, { recursive: true, force: true });
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
}

async function engineChecks(e) {
  const g = (p, h = {}) => fetch(e.url + p, { headers: h });
  const r1 = await g('/models/manifest.json');
  ok(r1.status === 200 && r1.headers.get('cross-origin-embedder-policy') === 'require-corp' && r1.headers.get('cross-origin-opener-policy') === 'same-origin' && r1.headers.get('cross-origin-resource-policy') === 'same-origin',
    '/models/ served WITHOUT the token, with COOP/COEP/CORP (what the speech worker fetches)');
  const r1b = await g('/models/manifest.json', { Range: 'bytes=0-9' });
  ok(r1b.status === 206, '/models/ answers Range requests (206)');
  ok((await g('/api/list?path=models')).status === 401, '...while every API call still needs the token (401 without it)');
  ok((await fetch(e.url + '/models/x.onnx', { method: 'PUT', body: 'x' })).status === 405, 'models/ is read-only over plain HTTP (PUT 405)');
  ok((await fetch(e.url + '/api/write?path=models%2Fx', { method: 'PUT', body: 'x' })).status === 401, '...and the write API refuses without the token');
}

(async () => {
  const T0 = Date.now();
  for (const f of ['kbb-talk-916.mp4', 'pc-talk-169.mp4', 'kbb-ar-916.mp4']) if (!fs.existsSync(path.join(MEDIA, f))) throw new Error('run tools/int-media.cjs first');
  await staticChecks();
  prepareStudio();
  const e = await H.startEngine(STUDIO);
  await engineChecks(e);

  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  page.setDefaultTimeout(60000);
  const errors = [], outside = [], reqs = { n: 0 };
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { reqs.n++; const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  page.on('worker', (w) => w.on('console', (m) => { if (m.type() === 'error') errors.push('[worker] ' + m.text()); }));
  const shot = async (name) => {
    for (const [w, h] of [[1920, 1080], [1280, 800]]) {
      await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(500);
      await page.screenshot({ path: path.join(SHOTS, `${name}-${w}.png`) });
    }
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(250);
  };
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const waitEd = (fn, arg, timeout = 600000) => page.waitForFunction(fn, arg, { timeout, polling: 500 });
  const closeModal = async () => { while (await page.locator('.modal-back').count()) { await page.keyboard.press('Escape'); await page.waitForTimeout(150); } };
  const doit = async (id) => { await page.click(`.doit-btn[data-mod="${id}"]`); };
  const idle = () => waitEd(() => document.querySelector('.mod-progress')?.hidden !== false);

  // ── first run: Setup screen → real download → home ──
  await page.goto(e.url + '/app/');
  const setupShown = await page.locator('.setup-screen').waitFor({ timeout: 15000 }).then(() => true, () => false);
  const modelsThere = fs.existsSync(path.join(STUDIO, 'models/onnx-community/whisper-base_timestamped/onnx/encoder_model_quantized.onnx'));
  if (FRESH || !modelsThere) {
    ok(setupShown, 'first run opens the Setup screen before anything else');
    await page.waitForTimeout(400);
    await shot('01-setup');
    const t = Date.now();
    const engineLogBefore = e.log || '';
    ok(!/download: redirect/.test(engineLogBefore), 'nothing was downloaded before the Allow');
    await page.click('#su-go');
    await waitEd(() => /running|done/.test(document.querySelector('.setup-msg')?.textContent || '') || document.querySelector('.setup-progress progress'), null, 30000);
    await waitEd(() => { const p = document.querySelector('.setup-progress progress.xbar'); return p && p.value > 0.15; }, null, 300000).catch(() => {});
    await shot('02-setup-progress');
    await waitEd(() => /Open KBB Studio/.test(document.querySelector('#su-go')?.textContent || ''), null, 1800000);
    const st = await ev(async () => (await (await fetch('/api/models', { headers: { 'X-RS-Token': document.querySelector('meta[name=rs-token]').content } })).json()));
    const req = st.models.filter((m) => m.required);
    ok(req.every((m) => m.installed), `Setup downloaded every needed model for real through the engine in ${secs(t)}: ${req.map((m) => `${m.id} ${(m.size / 1e6).toFixed(0)} MB`).join(', ')}`);
    await shot('03-setup-done');
    const log = e.log || '';
    const firstGrant = log.indexOf('permission granted'), firstDl = log.indexOf('download: redirect');
    ok(firstGrant >= 0 && firstDl > firstGrant, 'the engine went online only after the Allow (grant logged before the first download)');
    const hosts = [...new Set([...log.matchAll(/download: redirect to ([\w.-]+)/g)].map((m) => m[1]))];
    ok(hosts.length && hosts.every((h) => /(^|\.)(hf\.co|huggingface\.co)$/.test(h)) && !/refused/.test(log), 'model downloads followed only Hugging Face hosts: ' + hosts.join(', '));
    for (const m of req) {
      const entry = JSON.parse(fs.readFileSync(path.join(STUDIO, 'models/manifest.json'), 'utf8')).models.find((x) => x.id === m.id);
      const sizes = entry.files.every((f) => fs.statSync(path.join(STUDIO, 'models', entry.repo, f.path)).size === f.size);
      ok(sizes, `${m.id}: every file on disk at its manifest size (sha-verified by the engine before rename)`);
    }
    await page.click('#su-go');
  } else {
    ok(!setupShown, 'models already installed: no Setup screen (home opens straight away)');
  }
  await page.waitForSelector('.home', { timeout: 30000 });

  // ── homes ──
  const wsState = () => ev(async () => { const W = await import('/app/editor/workspaces.js'); return W.state.list.map((w) => ({ id: w.id, primary: w.colors.primary, heading: w.fonts.heading, captions: w.captions?.style, industries: w.industries, kitSeeded: w.kitSeeded })); });
  const list = await wsState();
  const kbb = list.find((w) => w.id === 'kbb'), pc = list.find((w) => w.id === 'planet-careers');
  ok(kbb && kbb.primary === '#C6395F' && kbb.heading === 'Playfair Display' && kbb.captions === 'soft-beauty', `KBB seeded from brands/kbb/kit.json (${JSON.stringify(kbb)})`);
  ok(pc && pc.primary === '#002768' && pc.captions === 'info-card' && pc.industries.includes('immigration'), `Planet Careers seeded from its kit (${JSON.stringify(pc)})`);
  const switchTo = async (name) => {
    await page.click('.ws-switch');
    await page.locator('.ws-item', { hasText: name }).first().click();
    await page.waitForTimeout(400);
  };
  const cur = await ev(async () => (await import('/app/editor/workspaces.js')).state.current.id);
  if (cur !== 'kbb') await switchTo('K-Beauty');
  await shot('04-home-kbb');
  await switchTo('Planet Careers');
  await shot('05-home-planet-careers');

  const runs = [
    { ws: 'kbb', name: 'K-Beauty', file: 'kbb-talk-916.mp4', tag: 'kbb', src: [1080, 1920] },
    { ws: 'planet-careers', name: 'Planet Careers', file: 'pc-talk-169.mp4', tag: 'pc', src: [1920, 1080] },
  ];
  const summary = [];
  for (const run of runs) {
    const R = { ws: run.ws };
    const curWs = await ev(async () => (await import('/app/editor/workspaces.js')).state.current.id);
    if (curWs !== run.ws) await switchTo(run.name);
    // import: drop the talking video on the home screen
    let t = Date.now();
    await page.setInputFiles('.home-drop input[type=file]', path.join(MEDIA, run.file));
    await page.waitForSelector('.tl-item', { timeout: 60000 });
    await waitEd(() => window.kbbEditor?.project.media[0]?.dur > 0);
    const media = await ev(() => kbbEditor.project.media[0]);
    ok(media.w === run.src[0] && media.h === run.src[1] && media.hasAudio, `${run.ws}: imported ${run.file} (${media.w}×${media.h}, ${media.dur.toFixed(2)} s, sound) in ${secs(t)}`);

    // Auto captions: the button in the "Do it for me" bar, whisper-base on WASM (chosen in the Captions panel)
    await page.click('.side-tab[data-side="captions"]');
    await page.evaluate(() => document.querySelectorAll('.rs-cap-settings').forEach((d) => { d.open = true; }));   // the model/language pickers live under ⚙ Settings since the captions round
    await page.locator('.rs-cap select.rs-cap-in').nth(0).selectOption('base');
    await page.locator('.rs-cap select.rs-cap-in').nth(1).selectOption('en');
    t = Date.now();
    await doit('auto-captions');
    await waitEd(() => kbbEditor.project.captions.words.length > 5, null, 600000);
    await idle();
    const cap = await ev(async () => { const A = await import('/app/captions/auto.js'); const L = A.transcriber().loaded || {}; const c = kbbEditor.project.captions; return { words: c.words.length, lines: c.lines.length, lang: c.lang, style: c.style?.id || c.style, text: c.words.map((w) => w.w).join(' '), device: L.device, dtype: L.dtype, model: L.modelKey, items: kbbEditor.project.tracks.find((x) => x.kind === 'caption').items.length }; });
    R.captions = { ms: Date.now() - t, ...cap };
    ok(cap.device === 'wasm' && cap.model === 'base' && cap.words > 30 && cap.items === 1, `${run.ws}: Auto captions (whisper-base, ${cap.device} ${JSON.stringify(cap.dtype)}) → ${cap.words} words, ${cap.lines} lines, "${cap.lang}", style ${cap.style}, in ${secs(t)}`);
    console.log('   transcript:', cap.text.slice(0, 220));
    const lowered = cap.text.toLowerCase();
    const expect = run.ws === 'kbb' ? ['unboxing', 'serum', 'skin', 'order'] : ['germany', 'visa', 'passport', 'consultation'];
    const heard = expect.filter((w) => lowered.includes(w));
    ok(heard.length >= 3, `${run.ws}: the transcript heard ${heard.join(', ')}`);

    // edit a word in the transcript (double-click, type, Enter) — one undo step
    const wi = 3;
    await page.dblclick(`.rs-cap-w[data-i="${wi}"]`);
    await page.keyboard.press('Control+A');
    await page.keyboard.type('GLOWING');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);
    const edited = await ev((i) => kbbEditor.project.captions.words[i].w, wi);
    ok(edited === 'GLOWING', `${run.ws}: a word edited in the transcript panel (word ${wi} → "${edited}")`);
    await ev(() => kbbEditor.history.undo()); await page.waitForTimeout(150);
    const undone = await ev((i) => kbbEditor.project.captions.words[i].w, wi);
    await ev(() => kbbEditor.history.redo());
    ok(undone !== 'GLOWING', `${run.ws}: ...and ⌘Z puts it back ("${undone}")`);
    const mid = await ev(() => { const l = kbbEditor.project.captions.lines[2] || kbbEditor.project.captions.lines[0]; return (l.t0 + l.t1) / 2; });
    await ev((t) => kbbEditor.seek(t), mid); await page.waitForTimeout(600);
    await shot(`06-${run.tag}-captions`);
    if (run.ws === 'kbb') {
      await page.click('.rs-cap-tabs [data-tab="style"]'); await page.waitForTimeout(500);
      await shot('07-caption-styles');
      await page.click('.rs-cap-tabs [data-tab="transcript"]');
    }

    // Suggest style: the button, then "Use this recipe" on the first suggestion
    t = Date.now();
    await doit('suggest-style');
    await page.waitForSelector('.mod-panel button', { timeout: 300000 });
    const sugg = await ev(() => [...document.querySelectorAll('.mod-panel b')].map((b) => b.textContent));
    await page.locator('.mod-panel button', { hasText: 'Use this recipe' }).first().click();
    await closeModal();
    const rec = await ev(async () => { const A = await import('/app/auto/index.js'); const p = kbbEditor.project; const r = A.recipeById(p.recipe); return { id: p.recipe, style: p.captions.style?.id || p.captions.style, want: A.captionStyleFor(r, 'en'), ws: r?.workspaces }; });
    R.suggest = { list: sugg, ...rec };
    ok(rec.id && rec.style === rec.want && (rec.ws.includes(run.ws) || rec.ws.includes('*')), `${run.ws}: Suggest style → ${sugg.join(' · ')}; used "${rec.id}", captions now "${rec.style}" (the recipe's style) in ${secs(t)}`);

    // Auto reel 15 s from its panel
    t = Date.now();
    await ev(() => kbbEditor.openModulePanel('auto-reel'));
    await page.locator('.mod-panel select').first().selectOption('15');
    await page.locator('.mod-panel button', { hasText: 'Make my reel' }).click();
    await waitEd(() => /Total/.test(document.querySelector('.mod-panel')?.textContent || ''), null, 600000);
    const reelTxt = await ev(() => document.querySelector('.mod-panel').textContent);
    await closeModal();
    const reel = await ev(async () => { const P = await import('/app/core/project.js'); const p = kbbEditor.project; const tr = (k) => p.tracks.filter((x) => x.kind === k).flatMap((x) => x.items); return { dur: P.projectDuration(p), clips: tr('video').length, overlay: tr('overlay').map((i) => i.type), music: tr('music').length, sfx: tr('sfx').length, words: p.captions.words.length, kf: tr('video').some((i) => i.kf && i.kf.x && i.kf.x.length > 1), style: p.captions.style?.id }; });
    R.reel = { ms: Date.now() - t, ...reel };
    ok(reel.clips >= 2 && reel.dur > 8 && reel.dur <= 15.6, `${run.ws}: Auto reel 15 s → ${reel.clips} clips, ${reel.dur.toFixed(2)} s, overlay [${[...new Set(reel.overlay)].join(', ')}], ${reel.music} music + ${reel.sfx} SFX items, ${reel.words} caption words kept, in ${secs(t)}`);
    if (run.src[0] > run.src[1]) ok(reel.kf, `${run.ws}: the 16:9 source is reframed to 9:16 (crop keyframes on the clips)`);
    console.log('   ' + reelTxt.replace(/\s+/g, ' ').slice(0, 300));
    await ev(() => kbbEditor.timeline.fit?.()); await ev(() => kbbEditor.seek(1.2)); await page.waitForTimeout(500);
    await shot(`08-${run.tag}-auto-reel`);

    // Add music (the button) — ducked under speech, fades
    t = Date.now();
    await doit('add-music'); await idle();
    const mus = await ev(() => { const it = kbbEditor.project.tracks.filter((x) => x.kind === 'music').flatMap((x) => x.items); return { n: it.length, duck: it.some((i) => (i.kf?.volume || []).length > 2), fades: it.some((i) => i.fadeOut > 0), name: it[0]?.name }; });
    ok(mus.n >= 1 && mus.duck && mus.fades, `${run.ws}: Add music → ${mus.name} (${mus.n} item(s), volume keyframes duck it under speech, fade out) in ${secs(t)}`);

    // Auto effects (the button)
    t = Date.now();
    await doit('auto-effects'); await idle();
    const fx = await ev(() => { const it = kbbEditor.project.tracks.flatMap((x) => x.items); return { trans: it.filter((i) => i.type === 'auto.transition').length, punch: it.filter((i) => (i.fx || []).some((f) => f.type === 'auto.punch')).length, stickers: it.filter((i) => i.type === 'sticker').length, sfx: kbbEditor.project.tracks.filter((x) => x.kind === 'sfx').flatMap((x) => x.items).length }; });
    ok(fx.trans >= 1 && fx.sfx >= 1, `${run.ws}: Auto effects → ${fx.trans} transitions, ${fx.punch} clips with punch-ins, ${fx.stickers} emoji, ${fx.sfx} SFX, in ${secs(t)}`);
    const clash = await ev(() => { const it = kbbEditor.project.tracks.flatMap((x) => x.items), e = it.find((i) => i.type === 'auto.endcard'); const n = (k) => it.filter((i) => i.type === k).length;
      return { over: e ? it.filter((i) => i.type === 'auto.logo' && i.style?.mode === 'outro' && i.t0 < e.t0 + e.out - e.in && i.t0 + i.out - i.in > e.t0).length : 0, trans: n('auto.transition') }; });
    await doit('auto-effects'); await idle();
    const again = await ev(() => kbbEditor.project.tracks.flatMap((x) => x.items).filter((i) => i.type === 'auto.transition').length);
    ok(clash.over === 0 && again === clash.trans, `${run.ws}: no logo outro over the end card; pressing Auto effects again replaces its effects (${clash.trans} → ${again} transitions)`);
    const draws = await ev(async () => { const Rn = await import('/app/core/render.js'); return ['auto.transition', 'auto.endcard', 'auto.logo', 'auto.pricetag', 'caption'].filter((k) => Rn.hasLayer(k)); });
    ok(draws.length === 5, 'renderer dispatches every auto.* layer and the caption layer: ' + draws.join(', '));

    // library tabs
    if (run.ws === 'kbb') {
      await page.click('.bin-tab[title="Music"]'); await page.waitForSelector('.lib-row[draggable=true]');
      const nMusic = await page.locator('.lib-row').count();
      await page.locator('.lib-row .icon-btn').first().click(); await page.waitForTimeout(400);
      const playing = await ev(() => !!document.querySelector('.lib-row .icon-btn')?.textContent.includes('■'));
      await page.locator('.lib-row .icon-btn').first().click();
      ok(nMusic >= 24 && playing, `Music tab lists library.json (${nMusic} tracks), ▶ previews`);
      await shot('09-music-tab');
      // drag one track onto the music track of the timeline
      const before = await ev(() => kbbEditor.project.tracks.filter((x) => x.kind === 'music').flatMap((x) => x.items).length);
      const lane = page.locator('.tl-row', { has: page.locator('.tl-head', { hasText: 'Music' }) }).first().locator('.tl-lane');
      await lane.scrollIntoViewIfNeeded();
      const lb = await lane.boundingBox();
      await page.locator('.lib-row').nth(5).dragTo(lane, { targetPosition: { x: Math.max(30, Math.min(lb.width - 30, 300)), y: lb.height / 2 } });
      await page.waitForTimeout(800);
      const after = await ev(() => kbbEditor.project.tracks.filter((x) => x.kind === 'music').flatMap((x) => x.items).length);
      ok(after === before + 1, `a library track dragged onto the timeline lands on the Music track (${before} → ${after})`);
      await ev(() => kbbEditor.history.undo()); await page.waitForTimeout(300);
      await page.click('.bin-tab[title="SFX"]'); await page.waitForSelector('.lib-row[draggable=true]');
      const nSfx = await page.locator('.lib-row').count();
      ok(nSfx >= 20, `SFX tab lists library.json (${nSfx} sounds)`);
      await shot('10-sfx-tab');
      await page.click('.bin-tab[title="Templates"]'); await page.waitForSelector('.recipe-row');
      const tpl = await ev(() => [...document.querySelectorAll('.recipe-row')].map((r) => r.dataset.recipe));
      const tplOk = await ev(async (ids) => { const R = await import('/app/auto/recipes/recipes.js'); return ids.every((id) => { const r = R.recipeById(id); return r.workspaces.includes('kbb') || r.workspaces.includes('*'); }); }, tpl);
      ok(tpl.length >= 15 && tplOk && !tpl.some((id) => id.startsWith('pc-')), `Templates tab: ${tpl.length} recipes for KBB (beauty + generic, none of Planet Careers')`);
      await shot('11-templates-tab');
      await page.click('.bin-tab[title="Media"]');
    } else {
      await page.click('.bin-tab[title="Templates"]'); await page.waitForSelector('.recipe-row');
      const tpl = await ev(() => [...document.querySelectorAll('.recipe-row')].map((r) => r.dataset.recipe));
      ok(tpl.some((id) => id.startsWith('pc-spotlight-')) && !tpl.some((id) => id.startsWith('kbb-')), `Templates tab: ${tpl.length} recipes for Planet Careers (incl. ${tpl.filter((x) => x.startsWith('pc-spotlight-')).length} country spotlights, no KBB ones)`);
      await page.click('.bin-tab[title="Stickers"]'); await page.waitForSelector('.sticker-img');
      await page.locator('.country-sel').selectOption('de'); await page.waitForTimeout(400);
      const nSt = await page.locator('.sticker-img').count();
      await page.locator('.sticker-img').nth(2).click(); await page.waitForTimeout(800);
      const st = await ev(() => { const it = kbbEditor.project.tracks.filter((x) => x.kind === 'overlay').flatMap((x) => x.items).filter((i) => i.type === 'sticker' && i.media); return it.map((i) => i.name); });
      ok(nSt >= 6 && st.length === 1, `Stickers tab: country packs for Planet Careers (${nSt} for Germany); one added → "${st[0]}"`);
      await shot('12-pc-country-stickers');
      await ev(() => kbbEditor.history.undo());
      await page.click('.bin-tab[title="Media"]');
    }

    // Export MP4
    t = Date.now();
    const expectDur = await ev(async () => (await import('/app/core/project.js')).projectDuration(kbbEditor.project));
    await page.click('.export-btn');
    await page.click('#x-go');
    await waitEd(() => kbbEditor.lastExport && (kbbEditor.lastExport.path || kbbEditor.lastExport.error), null, 900000);
    const le = await ev(() => kbbEditor.lastExport);
    await page.waitForTimeout(800);
    if (run.ws === 'kbb') await shot('13-export-done');
    await closeModal();
    ok(!le.error, `${run.ws}: Export finished in ${secs(t)}: ${le.note || le.error}`);
    if (le.path) {
      const f = path.join(STUDIO, le.path), p = ffprobe(f);
      const v = p.streams.find((s) => s.codec_type === 'video'), a = p.streams.find((s) => s.codec_type === 'audio');
      const d = +p.format.duration;
      R.export = { file: le.path, bytes: fs.statSync(f).size, duration: d, expect: expectDur, video: v && `${v.codec_name} ${v.width}x${v.height}`, audio: a && a.codec_name, ms: Date.now() - t };
      ok(Math.abs(d - expectDur) <= 0.2, `${run.ws}: ffprobe duration ${d.toFixed(3)} s vs timeline ${expectDur.toFixed(3)} s (±0.2)`);
      ok(v && v.width === 1080 && v.height === 1920 && v.codec_name === 'h264', `${run.ws}: video ${v && v.codec_name} ${v && v.width}×${v && v.height}`);
      ok(!!a, `${run.ws}: audio present (${a && a.codec_name})`);
      // the music is audible and ducked: loudness of the mix exists (not silent)
      const vol = (spawnSync('ffmpeg', ['-hide_banner', '-i', f, '-af', 'volumedetect', '-vn', '-f', 'null', '-']).stderr.toString().match(/mean_volume: [-\d.]+ dB/) || ['?'])[0];
      ok(/mean_volume: -\d/.test(vol) && !/-91/.test(vol), `${run.ws}: the exported sound is not silent (${vol.replace(/.*mean_volume/, 'mean_volume')})`);
    }

    // reload restores the project
    const snap = await ev(() => { const p = kbbEditor.project; return JSON.stringify({ id: p.id, recipe: p.recipe, words: p.captions.words.map((w) => w.w), style: p.captions.style, tracks: p.tracks.map((t) => t.items.map((i) => [i.type, +i.t0.toFixed(3), +i.out.toFixed(3)])) }); });
    await ev(() => kbbEditor.save());
    await page.reload();
    await page.waitForSelector('.tl-item', { timeout: 60000 });
    await page.waitForTimeout(800);
    const snap2 = await ev(() => { const p = kbbEditor.project; return JSON.stringify({ id: p.id, recipe: p.recipe, words: p.captions.words.map((w) => w.w), style: p.captions.style, tracks: p.tracks.map((t) => t.items.map((i) => [i.type, +i.t0.toFixed(3), +i.out.toFixed(3)])) }); });
    ok(snap === snap2, `${run.ws}: reload restores the project exactly (${JSON.parse(snap2).tracks.flat().length} items, captions, recipe, style)`);
    summary.push(R);
    await ev(() => kbbEditor.goHome());
    await page.waitForSelector('.home');
  }

  // ── an Arabic sample ──
  {
    const curWs = await ev(async () => (await import('/app/editor/workspaces.js')).state.current.id);
    if (curWs !== 'kbb') await switchTo('K-Beauty');
    await page.setInputFiles('.home-drop input[type=file]', path.join(MEDIA, 'kbb-ar-916.mp4'));
    await page.waitForSelector('.tl-item'); await waitEd(() => kbbEditor.project.media[0]?.dur > 0);
    await page.click('.side-tab[data-side="captions"]');
    await page.evaluate(() => document.querySelectorAll('.rs-cap-settings').forEach((d) => { d.open = true; }));   // the model/language pickers live under ⚙ Settings since the captions round
    await page.locator('.rs-cap select.rs-cap-in').nth(0).selectOption('base');
    await page.locator('.rs-cap select.rs-cap-in').nth(1).selectOption('ar');
    const t = Date.now();
    await doit('auto-captions');
    await waitEd(() => kbbEditor.project.captions.words.length > 5, null, 600000); await idle();
    const ar = await ev(() => { const c = kbbEditor.project.captions; return { n: c.words.length, lang: c.lang, text: c.words.map((w) => w.w).join(' ') }; });
    ok(ar.lang === 'ar' && /[؀-ۿ]/.test(ar.text), `Arabic clip: ${ar.n} words in Arabic in ${secs(t)} — ${ar.text.slice(0, 80)}`);
    await page.locator('.rs-cap-tile[data-style="arabic-first"]').click().catch(async () => { await page.click('.rs-cap-tabs [data-tab="style"]'); await page.locator('.rs-cap-tile[data-style="arabic-first"]').click(); });
    const mid = await ev(() => { const l = kbbEditor.project.captions.lines[1] || kbbEditor.project.captions.lines[0]; return (l.t0 + l.t1) / 2; });
    await page.click('.rs-cap-tabs [data-tab="transcript"]').catch(() => {});
    await ev((t) => kbbEditor.seek(t), mid); await page.waitForTimeout(700);
    await shot('14-kbb-captions-arabic');
    await ev(() => kbbEditor.goHome()); await page.waitForSelector('.home');
  }

  // ── + New company: kit written and valid ──
  {
    await page.click('.ws-switch'); await page.locator('.ws-item', { hasText: 'New company' }).click();
    await page.fill('.company-form input[placeholder="Company name"]', 'Sunrise Cafe');
    await page.fill('.company-form input[list="kbb-ind"]', 'Restaurant / cafe');
    await page.locator('.modal-foot .btn.primary').click();
    await page.waitForTimeout(800);
    const kitPath = path.join(STUDIO, 'brands/sunrise-cafe/kit.json');
    const K = await import(path.join(ROOT, 'app/editor/kits.js'));
    const schema = JSON.parse(fs.readFileSync(path.join(ROOT, 'brands/kit.schema.json'), 'utf8'));
    const kit = fs.existsSync(kitPath) ? JSON.parse(fs.readFileSync(kitPath, 'utf8')) : null;
    ok(kit && !K.validateKit(kit, schema).length && fs.existsSync(path.join(STUDIO, 'brands/sunrise-cafe/logo-mark.svg')), `+ New company writes brands/sunrise-cafe/kit.json (valid against kit.schema.json) and a logo mark`);
    const tpl = await ev(async () => (await import('/app/editor/bin.js')).loadRecipes((await import('/app/editor/workspaces.js')).state.current));
    ok(tpl.length && tpl.every((r) => r.group === 'Any company'), `a new company's recipes: the ${tpl.length} generic ones`);
  }

  ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 5).join(' | ') : ''}`);
  ok(!outside.length, `no browser request left 127.0.0.1 (${reqs.n} requests)${outside.length ? ': ' + outside.slice(0, 5).join(', ') : ''}`);
  await browser.close();
  await H.stopEngine(e);
  fs.writeFileSync(path.join(OUT, 'int-summary.json'), JSON.stringify({ results, summary }, null, 1));
  console.log(`\n${failures ? failures + ' FAILED' : 'all integration checks passed'} — ${results.length} checks in ${secs(T0)}`);
  for (const s of summary) console.log(JSON.stringify({ ws: s.ws, captions: { words: s.captions.words, ms: s.captions.ms }, reel: s.reel && { dur: +s.reel.dur.toFixed(2), clips: s.reel.clips, ms: s.reel.ms }, export: s.export }));
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
