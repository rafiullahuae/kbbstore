// Captions round (Lane K) — the captions panel inside the REAL editor (engine + Chrome), pictures at 1920x1080 and
// 1280x800 into shots/k/, and the on-canvas drag through editor/preview.js (core canvas-grab hook).
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/k-editor.cjs
// Needs: engine/kbbstudio-linux-amd64 (bash engine/build.sh, or go build), ffmpeg, and tools/out/k/capture-music.json
// from tools/k-check.cjs (the words Whisper gave the music + speech clip; the editor shows exactly those).
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools/out/k'), SHOTS = path.join(ROOT, 'shots/k');
fs.mkdirSync(SHOTS, { recursive: true });
let fails = 0;
const ok = (c, msg) => { console.log(`${c ? 'PASS' : 'FAIL'}  ${msg}`); if (!c) fails++; };

(async () => {
  const cap = JSON.parse(fs.readFileSync(path.join(OUT, 'capture-music.json')));
  const wav = path.join(ROOT, 'models/_r2-test/k-music.wav');
  const clip = path.join(OUT, 'k-clip.mp4');
  if (!fs.existsSync(clip)) execFileSync('ffmpeg', ['-y', '-v', 'error', '-f', 'lavfi', '-i', 'gradients=s=720x1280:r=30:c0=0xe9b9a0:c1=0x8e5a6c:c2=0x1f1a2e:speed=0.01', '-i', wav,
    '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'veryfast', '-c:a', 'aac', '-b:a', '128k', clip]);
  const STUDIO = H.makeStudio();
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  page.setDefaultTimeout(60000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = async (name) => {
    for (const [w, h] of [[1920, 1080], [1280, 800]]) { await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(450); await page.screenshot({ path: path.join(SHOTS, `editor-${name}-${w}x${h}.png`) }); }
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(200);
  };

  await page.goto(e.url + '/app/');
  if (await page.locator('#su-later').waitFor({ timeout: 15000 }).then(() => true, () => false)) await page.click('#su-later');
  await page.waitForSelector('.home', { timeout: 30000 });
  await page.setInputFiles('.home-drop input[type=file]', clip);
  await page.waitForSelector('.tl-item');
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  // the words the capture pipeline produced for this very clip (tags kept, music-only stretches, Hide)
  await ev(async (c) => {
    const C = await import('/app/captions/commands.js'); const P = await import('/app/core/project.js'); const ed = kbbEditor, p = ed.project;
    const tr = p.tracks.find((t) => t.kind === 'caption');
    const words = c.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1, conf: 1, ...(w.tag ? { tag: w.tag } : {}) }));
    ed.history.apply(ed.ops.batch('Captions', [C.setCaptions({ style: 'word-box', lang: 'en', words, music: c.music, soundTags: 'hide',
      dropped: [{ w: 'Thanks', t0: 31.9, t1: 32.2, why: 'outro' }, { w: 'for', t0: 32.2, t1: 32.4, why: 'outro' }, { w: 'watching.', t0: 32.4, t1: 32.9, why: 'outro' }] }, p.canvas),
      ed.ops.addItems([{ trackId: tr.id, item: P.createItem('caption', { t0: 0, in: 0, out: P.projectDuration(p), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'Captions' }) }])]));
  }, cap);
  await page.click('.side-tab[data-side="captions"]');
  await page.waitForSelector('.rs-cap-line');
  const jfkT = cap.words.find((w) => /fellow/.test(w.w)).t0 + 0.05;
  await ev((t) => kbbEditor.seek(t), jfkT);
  await shot('transcript');

  // ---- drag a caption line on the real preview (editor/preview.js -> core canvasGrab -> captions/drag.js)
  const before = await ev(async (t) => {
    const R = await import('/app/captions/render.js'); const ed = kbbEditor, p = ed.project;
    const b = R.captionBlocks(document.createElement('canvas').getContext('2d'), p, t, null)[0];
    const ov = document.querySelector('.pv-overlay').getBoundingClientRect(), k = ov.width / p.canvas.w;
    return { x: ov.left + (b.box.x + b.box.w / 2) * k, y: ov.top + (b.box.y + b.box.h / 2) * k, line: b.L.id, steps: ed.history.undoStack.length };
  }, jfkT);
  await page.mouse.move(before.x, before.y); await page.mouse.down();
  for (let i = 1; i <= 8; i++) await page.mouse.move(before.x + i * 3, before.y - i * 22);
  await page.mouse.up();
  await page.waitForTimeout(150);
  const after = await ev((id) => { const ed = kbbEditor, L = ed.project.captions.lines.find((x) => x.id === id); return { pos: L.ov?.pos, steps: ed.history.undoStack.length, moved: ed.project.captions.lines.filter((x) => x.ov?.pos).length }; }, before.line);
  ok(after.pos && after.pos.y < 0.6 && after.moved === 1 && after.steps === before.steps + 1, `real editor: dragging the caption on the preview moves that line (${JSON.stringify(after.pos)}), one undo step`);
  await ev(() => kbbEditor.history.undo());
  ok(await ev((id) => !kbbEditor.project.captions.lines.find((x) => x.id === id).ov, before.line), 'real editor: undo puts the line back');
  await ev(() => kbbEditor.history.redo());
  // a word + line style for the pictures
  const L = await ev((t) => { const c = kbbEditor.project.captions; const L = c.lines.find((x) => x.t0 <= t && t < x.t1); return { a: L.wordIdx[0] }; }, jfkT);
  await page.locator(`.rs-cap-w[data-i="${L.a + 2}"]`).click();
  await page.locator('.rs-cap-sel [data-ov="word"]').click();
  await ev(() => { const box = document.querySelector('.rs-cap-ov'); const f = (n) => [...box.querySelectorAll('.rs-cap-field')].find((x) => x.firstChild.textContent === n);
    f('Highlight').querySelector('input[type=checkbox]').click(); const c = f('Colour').querySelector('input[type=color]'); c.value = '#111111'; c.dispatchEvent(new Event('input', { bubbles: true })); });
  await page.waitForTimeout(100);
  await ev((t) => kbbEditor.seek(t), jfkT + 0.4);
  await shot('word-style');
  const exp = await ev(async (t) => {
    // the export path draws the same pixels as the preview: one renderFrame for both
    const R = await import('/app/core/render.js'); const p = kbbEditor.project;
    const a = document.createElement('canvas'); a.width = p.canvas.w; a.height = p.canvas.h; R.renderFrame(a.getContext('2d'), p, t, {});
    return a.toDataURL('image/jpeg', 0.85);
  }, jfkT + 0.4);
  fs.writeFileSync(path.join(SHOTS, 'editor-export-frame-1080x1920.jpg'), Buffer.from(exp.split(',')[1], 'base64'));

  // ---- Script tab
  await page.click('.rs-cap-tabs button[data-tab="script"]');
  await page.locator('.rs-cap-check input').first().check();
  await page.waitForTimeout(150);
  await shot('script');
  await page.click('.rs-cap-scriptbar [data-x="srt"]');
  await page.waitForTimeout(300);
  const srt = fs.readdirSync(path.join(STUDIO, 'workspaces')).flatMap((w) => { const d = path.join(STUDIO, 'workspaces', w, 'exports'); return fs.existsSync(d) ? fs.readdirSync(d).map((f) => path.join(d, f)) : []; }).filter((f) => f.endsWith('.srt'));
  ok(srt.length === 1 && /-->/.test(fs.readFileSync(srt[0], 'utf8')) && !/\[music\]/i.test(fs.readFileSync(srt[0], 'utf8')), `real editor: SRT saved by the engine into ${srt[0] ? path.relative(STUDIO, srt[0]) : '(nothing)'}`);
  if (srt[0]) fs.copyFileSync(srt[0], path.join(OUT, 'editor-export.srt'));

  // ---- Style tab: the gallery
  await page.click('.rs-cap-tabs button[data-tab="style"]');
  await page.waitForTimeout(600);
  await page.locator('.rs-cap-tile[data-style="k-gold-serif"] .rs-cap-fav').click();
  await page.waitForTimeout(300);
  const wsFile = fs.readdirSync(path.join(STUDIO, 'workspaces')).map((w) => path.join(STUDIO, 'workspaces', w, 'workspace.json')).find((f) => fs.existsSync(f) && /k-gold-serif/.test(fs.readFileSync(f, 'utf8')));
  ok(!!wsFile, `real editor: favourite saved by the engine in ${wsFile ? path.relative(STUDIO, wsFile) : '(nothing)'}`);
  await shot('gallery');
  await page.click('.rs-cap-cats [data-cat="Luxury"]');
  await page.locator('.rs-cap-tile[data-style="k-gold-serif"]').click();
  await page.waitForTimeout(300);
  await ev((t) => kbbEditor.seek(t), jfkT + 1);
  await shot('gallery-luxury');

  // ---- Music & sound tags: the ♪ icon through the music-only stretch
  await page.click('.rs-cap-settings summary');
  await page.selectOption('[data-k="soundTags"]', 'icon');
  await ev((t) => kbbEditor.seek(t), (cap.music[cap.music.length - 1][0] + cap.music[cap.music.length - 1][1]) / 2);
  await page.click('.rs-cap-tabs button[data-tab="transcript"]');
  await page.waitForTimeout(300);
  await shot('music-icon');

  const real = errors.filter((x) => !/No available adapters|AudioContext was not allowed/.test(x));
  ok(real.length === 0, 'real editor: no console errors' + (real.length ? ' — ' + real.slice(0, 3).join(' | ') : ''));
  ok(outside.length === 0, 'real editor: no network outside the local engine' + (outside.length ? ' — ' + outside.slice(0, 3).join(' ') : ''));
  await browser.close(); await H.stopEngine(e);
  fs.rmSync(STUDIO, { recursive: true, force: true });
  console.log(fails ? `${fails} FAILED` : 'ALL PASS');
  process.exit(fails ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
