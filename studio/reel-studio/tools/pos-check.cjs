// Caption positioning (Lane POS) — the REAL editor (Linux engine + headless Chromium), every case measured:
// one line moves alone, Shift moves all, a multi-select moves together, every snap target engages (Alt frees it),
// arrow nudges, per-shape layouts, each reset, undo/redo, the exported frame within 2 px of the drop (9:16 and 16:9),
// a translated second track and the sync lane's caption clock. Pictures at 1440x900 @2x into shots/pos/.
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/pos-check.cjs
// Needs: engine/kbbstudio-linux-amd64 (cd engine && GOOS=linux GOARCH=amd64 go build -o kbbstudio-linux-amd64 .), ffmpeg.
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools/out/pos'), SHOTS = path.join(ROOT, 'shots/pos');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
let fails = 0;
const ok = (c, msg) => { console.log(`${c ? 'PASS' : 'FAIL'}  ${msg}`); if (!c) fails++; };
const f2 = (v) => (v == null ? 'null' : (+v).toFixed(2));
const GREEN = { id: 'word-box', anim: 'none', case: 'none', color: '#00ff00', activeBg: null, outline: { w: 0, color: '#000000' }, shadow: { blur: 0, x: 0, y: 0, color: 'rgba(0,0,0,0)' },
  bg: { type: 'box', color: '#00ff00', opacity: 1, radius: 0, pad: 18 } };

(async () => {
  const clip = path.join(OUT, 'pos-clip.mp4');
  if (!fs.existsSync(clip)) execFileSync('ffmpeg', ['-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=0x202028:s=720x1280:r=30:d=4.5', '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo',
    '-t', '4.5', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'veryfast', '-c:a', 'aac', '-shortest', clip]);
  const STUDIO = H.makeStudio();
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const page = await context.newPage();
  page.setDefaultTimeout(60000);
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = (name) => page.screenshot({ path: path.join(OUT, 'm-' + name + '.png') });   // measuring-phase pictures (green test boxes)
  const pic = (name) => page.screenshot({ path: path.join(SHOTS, name + '.png') });          // the pictures, taken last in the real look

  await page.goto(e.url + '/app/');
  if (await page.locator('#su-later').waitFor({ timeout: 15000 }).then(() => true, () => false)) await page.click('#su-later');
  await page.waitForSelector('.home', { timeout: 30000 });
  await page.setInputFiles('.home-drop input[type=file]', clip);
  await page.waitForSelector('.tl-item');
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);

  // three lines ("screens") of captions, one at a time, plus the page helpers
  await ev(async (style) => {
    const C = await import('/app/captions/commands.js'), P = await import('/app/core/project.js'); const ed = kbbEditor, p = ed.project;
    const tr = p.tracks.find((t) => t.kind === 'caption');
    const W = (s, t0, t1) => s.split(' ').map((w, i, a) => ({ w, t0: +(t0 + (t1 - t0) * i / a.length).toFixed(3), t1: +(t0 + (t1 - t0) * (i + 1) / a.length).toFixed(3), conf: 1 }));
    const words = [...W('First line here', 0, 1.5), ...W('Second one now', 1.5, 3), ...W('Third line', 3, 4.5)];
    const lines = [{ id: 'LA', t0: 0, t1: 1.5, wordIdx: [0, 2] }, { id: 'LB', t0: 1.5, t1: 3, wordIdx: [3, 5] }, { id: 'LC', t0: 3, t1: 4.5, wordIdx: [6, 7] }];
    ed.history.apply(ed.ops.batch('Captions', [C.setCaptions({ style, lang: 'en', words, lines }, p.canvas),
      ed.ops.addItems([{ trackId: tr.id, item: P.createItem('caption', { t0: 0, in: 0, out: P.projectDuration(p), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'Captions' }) }])]));
    ed.history.undoStack.length = 0;
    const D = await import('/app/captions/drag.js'), R = await import('/app/captions/render.js'), B = await import('/app/core/bus.js');
    window.__pc = {
      D, R, bus: B.bus,
      hit(lang = '', t = kbbEditor.t) { const h = D.captionHits(kbbEditor.project, t, true).find((x) => x.lang === lang); return h ? { key: h.key, line: h.L.id, box: h.box, cx: h.cx, cy: h.cy } : null; },
      view() { const r = document.querySelector('.pv-overlay').getBoundingClientRect(); return { left: r.left, top: r.top, k: r.width / kbbEditor.project.canvas.w }; },
      pos(lang, id) { const o = D.capOf(kbbEditor.project.captions, lang); return id ? (o.lines.find((L) => L.id === id).ov?.pos || null) : (typeof o.style === 'object' ? o.style.pos || null : null); },
      safe() { const c = kbbEditor.project.canvas; return R.safeArea(c.w, c.h); },
      steps: () => kbbEditor.history.undoStack.length,
    };
  }, GREEN);
  await page.click('.side-tab[data-side="captions"]');
  await page.waitForSelector('.rs-cap-line');
  const seek = async (t) => { await ev((x) => kbbEditor.seek(x), t); await page.waitForTimeout(60); };
  const hit = (lang = '') => ev((l) => __pc.hit(l), lang);
  const pos = (lang, id) => ev(([l, i]) => __pc.pos(l, i), [lang, id]);
  const tA = 0.7, tB = 2.2, tC = 3.7;

  /** drag the block of track `lang` at the playhead by (dx, dy) canvas px; mods: Shift/Alt/Control. mid(): runs with the button down */
  async function drag(lang, dx, dy, mods = [], mid) {
    const h = await hit(lang), v = await ev(() => __pc.view());
    const x0 = v.left + (h.box.x + h.box.w / 2) * v.k, y0 = v.top + (h.box.y + h.box.h / 2) * v.k;
    for (const m of mods) await page.keyboard.down(m);
    await page.mouse.move(x0, y0); await page.mouse.down();
    const sx = Math.round(dx * v.k), sy = Math.round(dy * v.k), n = 8;
    for (let i = 1; i <= n; i++) await page.mouse.move(x0 + sx * i / n, y0 + sy * i / n);
    const r = mid ? await mid() : null;
    await page.mouse.up();
    for (const m of mods) await page.keyboard.up(m);
    await page.waitForTimeout(80);
    return { before: h, k: v.k, sdx: sx / v.k, sdy: sy / v.k, mid: r };
  }
  const guides = () => ev(() => [...document.querySelectorAll('.pv-overlay .snap')].map((n) => n.className.replace('snap ', '')));

  // ── 1. the redesigned editor: drag line A moves A only, B stays; one undo step
  await seek(tA);
  let s0 = await ev(() => __pc.steps());
  let d = await drag('', 0, -260, ['Alt']);
  const pA = await pos('', 'LA'), afterA = await hit('');
  ok(pA && (await pos('', 'LB')) === null && (await pos('', 'LC')) === null && (await pos('', null)) === null, `drag line A on the preview: A gets its own position ${JSON.stringify(pA)}, B, C and the style stay put`);
  ok(Math.abs(afterA.box.y - (d.before.box.y + d.sdy)) < 0.15 && Math.abs(afterA.box.x - (d.before.box.x + d.sdx)) < 0.15, `A's box lands where it was dropped (Δy ${f2(afterA.box.y - d.before.box.y)} for a ${f2(d.sdy)} px drag)`);
  ok((await ev(() => __pc.steps())) === s0 + 1, 'one drag = one undo step');
  await seek(tB);
  const bBox = await hit('');
  await seek(tA);
  // ── 2. scrub to another line and drag it: only that line
  await seek(tB);
  d = await drag('', 60, 60, ['Alt']);
  const pB = await pos('', 'LB');
  ok(pB && JSON.stringify(await pos('', 'LA')) === JSON.stringify(pA), `scrub to line B and drag: B gets ${JSON.stringify(pB)}, A keeps ${JSON.stringify(pA)}`);
  { const b2 = (await hit('')).box; ok(Math.abs(b2.x - (bBox.box.x + d.sdx)) < 0.15 && Math.abs(b2.y - (bBox.box.y + d.sdy)) < 0.15, `B lands where it was dropped (Δ ${f2(b2.x - bBox.box.x)},${f2(b2.y - bBox.box.y)} for ${f2(d.sdx)},${f2(d.sdy)})`); }

  // two lines in different places (one picture each, side by side)
  await seek(tA); await page.mouse.move(5, 5); await page.waitForTimeout(150); await shot('two-lines-a');
  await seek(tB); await page.waitForTimeout(150); await shot('two-lines-b');

  // ── 3. undo / redo of a drag
  await ev(() => kbbEditor.history.undo());
  ok((await pos('', 'LB')) === null && JSON.stringify(await pos('', 'LA')) === JSON.stringify(pA), 'undo puts B back (A unchanged)');
  await ev(() => kbbEditor.history.redo());
  ok(JSON.stringify(await pos('', 'LB')) === JSON.stringify(pB), 'redo moves B again');

  // ── 4. Shift-drag moves every line: the style and each line placed on its own, by the same amount
  await seek(tC);
  const cBefore = await hit('');
  s0 = await ev(() => __pc.steps());
  d = await drag('', 0, -100, ['Shift', 'Alt']);
  const sty = await pos('', null), pA2 = await pos('', 'LA'), pB2 = await pos('', 'LB'), cAfter = await hit(''), safe = await ev(() => __pc.safe());
  const dyF = d.sdy / safe.h;
  ok(sty && Math.abs(cAfter.box.y - (cBefore.box.y + d.sdy)) < 0.15 && Math.abs(pA2.y - (pA.y + dyF)) < 2e-4 && Math.abs(pB2.y - (pB.y + dyF)) < 2e-4 && (await ev(() => __pc.steps())) === s0 + 1,
    `Shift-drag moves all lines by ${f2(d.sdy)} px: C (style) Δ${f2(cAfter.box.y - cBefore.box.y)}, A ${f2(pA.y)}→${f2(pA2.y)}, B ${f2(pB.y)}→${f2(pB2.y)}, one step`);
  await ev(() => kbbEditor.history.undo());

  // ── 5. multi-select in the transcript (Ctrl/⌘-click lines) and drag one: the picked lines move together
  await page.locator('.rs-cap-line[data-line="LA"] .rs-cap-text').click();
  await page.locator('.rs-cap-line[data-line="LC"] .rs-cap-text').click({ modifiers: ['Control'] });
  const picked = await ev(() => [...__pc.D.posSel]);
  const rows = await ev(() => [...document.querySelectorAll('.rs-cap-line.pos-sel')].map((r) => r.dataset.line));
  ok(picked.join() === '|LA,|LC' && rows.join() === 'LA,LC', `Ctrl-click in the transcript picks lines A and C (rows marked: ${rows.join(', ')})`);
  await seek(tA);
  const cy0 = await (async () => { await seek(tC); const h = await hit(''); await seek(tA); return h; })();
  d = await drag('', 80, 0, ['Alt']);
  const pA3 = await pos('', 'LA'), pC3 = await pos('', 'LC'), pB3 = await pos('', 'LB');
  await seek(tC); const cyz = await hit(''); await seek(tA);
  ok(pA3 && pC3 && Math.abs(cyz.box.x - (cy0.box.x + d.sdx)) < 0.15 && JSON.stringify(pB3) === JSON.stringify(pB), `drag A with A + C picked: both move ${f2(d.sdx)} px (C Δx ${f2(cyz.box.x - cy0.box.x)}), B stays`);
  await ev(() => kbbEditor.history.undo());
  await ev(() => __pc.D.selectLines([]));

  // ── 6. snapping: each target engages; Alt disables it; guides only during the drag
  const C = await ev(() => kbbEditor.project.canvas);
  async function snapTo(axis, edge, target, kind, alt = false, lang = '') {
    const h = await hit(lang), v = await ev(() => __pc.view());
    const cur = axis === 'x' ? [h.box.x, h.box.x + h.box.w / 2, h.box.x + h.box.w][edge] : [h.box.y, h.box.y + h.box.h / 2, h.box.y + h.box.h][edge];
    const want = target - cur + 2 / v.k;                       // land 2 screen px off the target
    const r = await drag(lang, axis === 'x' ? want : 0, axis === 'y' ? want : 0, alt ? ['Alt'] : [], guides);
    const a = await hit(lang);
    const now = axis === 'x' ? [a.box.x, a.box.x + a.box.w / 2, a.box.x + a.box.w][edge] : [a.box.y, a.box.y + a.box.h / 2, a.box.y + a.box.h][edge];
    const left = await guides();
    return { off: now - target, g: r.mid, left };
  }
  await seek(tA);
  await drag('', 137, 0, ['Alt']);                             // A off the centre; the centre test drags C (its edges meet no other line's)
  await seek(tC);
  let r = await snapTo('x', 1, C.w / 2, 'centre');
  ok(Math.abs(r.off) < 0.15 && r.g.some((g) => /\bv centre\b/.test(g)) && !r.left.length, `centre line: the box centre snaps to x=${C.w / 2} (off ${f2(r.off)} px), pink guide during the drag only (${r.g.join(' + ')})`);
  r = await snapTo('x', 1, C.w / 2, 'centre', true);
  ok(Math.abs(r.off) > 1 && !r.g.some((g) => /\bv\b/.test(g)), `Alt-drag: no snapping (lands ${f2(r.off)} px off the centre, no guide)`);
  r = await snapTo('y', 0, C.h / 3, 'third');
  ok(Math.abs(r.off) < 0.15 && r.g.some((g) => /\bh third\b/.test(g)), `rule of thirds: the top edge snaps to y=${f2(C.h / 3)} (off ${f2(r.off)} px; ${r.g.join(' + ')})`);
  await seek(tC);
  r = await snapTo('x', 0, safe.x, 'safe');
  ok(Math.abs(r.off) < 0.15 && r.g.some((g) => /\bv safe\b/.test(g)), `platform safe zone: the left edge snaps to x=${f2(safe.x)} (off ${f2(r.off)} px; guides ${r.g.join(' + ')})`);
  // the safe box is a hard edge: dragged far past it, C stops there, and its stored X is where it is drawn
  { const d2 = await drag('', -900, 0, ['Alt'], guides), hc = await hit(''), pc = await pos('', 'LC');
    ok(Math.abs(hc.box.x - safe.x) < 0.1 && Math.abs(safe.x + pc.x * safe.w - hc.cx) < 0.05 && d2.mid.some((g) => /\bv safe\b/.test(g)), `dragged 900 px past the left edge: C stops at the safe box (left ${f2(hc.box.x)}), stored X ${f2(pc.x * 100)} % = where it is drawn`); }
  // another line's edge: B's top to where A sits (A moved off the thirds first, so the only target there is A)
  await seek(tA); await drag('', 0, 160, ['Alt']); const aBox = (await hit('')).box;
  await seek(tB);
  r = await snapTo('y', 0, aBox.y, 'line');
  ok(Math.abs(r.off) < 0.15 && r.g.some((g) => /\bh line\b/.test(g)), `other lines' edges: B's top snaps to A's top y=${f2(aBox.y)} (off ${f2(r.off)} px; ${r.g.join(' + ')})`);
  // the face box (as app/auto's analysis reports it): a snap target, and a warning tint over it
  await ev(() => { const m = kbbEditor.project.media[0]; __pc.bus.emit('auto:analysis', { mediaId: m.id, subjects: [0, 1, 2, 3, 4].map((t) => ({ t, x: 0.5, y: 0.26, w: 0.37, h: 0.208, conf: 0.9 })) }); });
  const face = await ev(() => __pc.D.faceBox(kbbEditor.project, kbbEditor.t));
  r = await snapTo('y', 0, face.y + face.h, 'face');
  ok(face && Math.abs(r.off) < 0.15 && r.g.some((g) => /\bh face\b/.test(g)) && r.g.some((g) => /rect face\b/.test(g)) && !r.g.some((g) => /face-warn/.test(g)), `face box: the top edge snaps under the face at y=${f2(face.y + face.h)} (off ${f2(r.off)} px), face outlined, no warning; guides ${r.g.join(' + ')}`);
  const fh = await hit('');
  const warn = await drag('', 0, face.y + face.h / 2 - (fh.box.y + fh.box.h / 2), ['Alt'], async () => { const g = await guides(); await shot('guides-face-warning'); return g; });
  ok(warn.mid.some((g) => /face-warn/.test(g)), 'dragged onto the face: the caption is tinted as a warning (picture: guides-face-warning.png)');
  await ev(() => kbbEditor.history.undo());
  await ev(() => { const m = kbbEditor.project.media[0]; __pc.bus.emit('auto:analysis', { mediaId: m.id, subjects: [] }); });
  // a picture of the guides mid-drag (centre + thirds)
  await seek(tA);
  { const h = await hit(''), v = await ev(() => __pc.view());
    const x0 = v.left + (h.box.x + h.box.w / 2) * v.k, y0 = v.top + (h.box.y + h.box.h / 2) * v.k;
    await page.mouse.move(x0, y0); await page.mouse.down();
    const tx = v.left + (C.w / 2 + 2) * v.k, ty = v.top + (C.h / 3 + h.box.h / 2 + 2) * v.k;
    for (let i = 1; i <= 8; i++) await page.mouse.move(x0 + (tx - x0) * i / 8, y0 + (ty - y0) * i / 8);
    const g = await guides(); await shot('guides-during-drag');
    await page.mouse.up(); await page.waitForTimeout(80);
    ok(g.length >= 2 && !(await guides()).length, `guides during a drag: ${g.join(' + ')}; none after the drop (picture: guides-during-drag.png)`); }

  // ── 7. arrow nudges: 1 px, Shift 10 px; one undo step per burst
  await seek(tA);
  { const h = await hit(''), v = await ev(() => __pc.view());
    await page.mouse.click(v.left + (h.box.x + h.box.w / 2) * v.k, v.top + (h.box.y + h.box.h / 2) * v.k); }
  const nb0 = await hit(''); s0 = await ev(() => __pc.steps());
  for (let i = 0; i < 5; i++) await page.keyboard.press('ArrowRight');
  const nb1 = await hit(''), s1 = await ev(() => __pc.steps());
  await page.keyboard.press('Shift+ArrowDown');
  const nb2 = await hit('');
  ok(Math.abs(nb1.box.x - nb0.box.x - 5) < 0.2 && s1 === s0 + 1, `5 × → nudges the line 5 px (Δx ${f2(nb1.box.x - nb0.box.x)}), one undo step for the burst`);
  ok(Math.abs(nb2.box.y - nb1.box.y - 10) < 0.2, `Shift+↓ nudges 10 px (Δy ${f2(nb2.box.y - nb1.box.y)})`);
  ok(Math.abs((await ev(() => kbbEditor.t)) - tA) < 1e-6, 'the playhead did not move while nudging');

  // ── 8. resets: the right-click menu (this line / all lines), the panel's ↺ Position and Reset all positions
  { const h = await hit(''), v = await ev(() => __pc.view());
    await page.mouse.move(v.left + (h.box.x + h.box.w / 2) * v.k, v.top + (h.box.y + h.box.h / 2) * v.k);
    await page.mouse.click(v.left + (h.box.x + h.box.w / 2) * v.k, v.top + (h.box.y + h.box.h / 2) * v.k, { button: 'right' }); }
  await page.waitForSelector('.cap-menu');
  await page.waitForTimeout(100);
  await shot('right-click-menu');
  await page.click('.cap-menu [data-x="reset-line"]');
  ok((await pos('', 'LA')) === null && (await pos('', 'LB')) !== null && !(await ev(() => !!document.querySelector('.cap-menu'))), 'right-click → Reset position: line A follows the style again, B keeps its place, menu closed');
  await seek(tB);
  { const h = await hit(''), v = await ev(() => __pc.view());
    await page.mouse.click(v.left + (h.box.x + h.box.w / 2) * v.k, v.top + (h.box.y + h.box.h / 2) * v.k, { button: 'right' }); }
  await page.click('.cap-menu [data-x="reset-all"]');
  ok((await ev(() => kbbEditor.project.captions.lines.every((L) => !L.ov?.pos))) && (await pos('', null)) === null, 'right-click → Reset all lines: no line keeps its own position');
  await ev(() => kbbEditor.history.undo());
  ok((await pos('', 'LB')) !== null, 'undo brings the positions back');
  // the panel: a line's X / Y shows the same values the drag wrote, and ↺ Position resets it
  await page.locator('.rs-cap-line[data-line="LB"] .rs-cap-w').first().click();
  await page.locator('.rs-cap-sel [data-ov="line"]').click();
  const shown = await ev(() => [...document.querySelectorAll('.rs-cap-ov [data-f="posx"], .rs-cap-ov [data-f="posy"]')].map((i) => +i.value));
  const pBn = await pos('', 'LB');
  ok(Math.abs(shown[0] - pBn.x * 100) < 0.006 && Math.abs(shown[1] - pBn.y * 100) < 0.006, `panel X / Y for line B: ${shown.join(' / ')} % = the position the drag wrote`);
  await page.fill('.rs-cap-ov [data-f="posy"]', '40'); await page.locator('.rs-cap-ov [data-f="posy"]').dispatchEvent('change');
  ok(Math.abs((await pos('', 'LB')).y - 0.4) < 1e-6, 'typing Y = 40 % in the panel moves the line there');
  await page.click('.rs-cap-ov [data-x="reset-pos"]');
  ok((await pos('', 'LB')) === null, 'panel ↺ (position) resets the line');
  { const fit = await ev(() => { const o = document.querySelector('.rs-cap-ov'), c = document.querySelector('.rs-cap'), wide = [...o.querySelectorAll('*')].filter((n) => n.getBoundingClientRect().right > o.getBoundingClientRect().right + 1).map((n) => n.tagName + '.' + n.className + '[' + (n.dataset.f || n.dataset.x || '') + ']').slice(0, 4);
      return { o: [o.scrollWidth, o.clientWidth], c: [c.scrollWidth, c.clientWidth], wide }; });
    ok(fit.o[0] <= fit.o[1] && fit.c[0] <= fit.c[1], `the line form with X / Y fits the captions column (form ${fit.o.join('/')}, column ${fit.c.join('/')} ${fit.wide.join(' ')})`); }
  await ev(() => kbbEditor.history.undo()); await ev(() => kbbEditor.history.undo());
  await page.click('.rs-cap-tabs button[data-tab="style"]');
  await page.click('[data-x="reset-pos-all"]');
  ok(await ev(() => kbbEditor.project.captions.lines.every((L) => !L.ov?.pos)), 'Style tab → Reset all positions');
  await ev(() => kbbEditor.history.undo());
  await page.click('.rs-cap-tabs button[data-tab="transcript"]');
  // picked lines from the menu
  await ev(() => __pc.D.selectLines(['|LA', '|LB']));
  await seek(tB);
  { const h = await hit(''), v = await ev(() => __pc.view());
    await page.mouse.click(v.left + (h.box.x + h.box.w / 2) * v.k, v.top + (h.box.y + h.box.h / 2) * v.k, { button: 'right' }); }
  await page.click('.cap-menu [data-x="reset-picked"]');
  ok((await pos('', 'LB')) === null, 'right-click → Reset picked lines');
  await ev(() => kbbEditor.history.undo()); await ev(() => __pc.D.selectLines([]));

  // ── 9. per-shape layouts: 9:16 and 16:9 keep their own places; 1:1 and 4:5 start mapped
  await seek(tA);
  await drag('', 0, -300, ['Alt']);
  const at916 = { A: await pos('', 'LA'), B: await pos('', 'LB') };
  const frac = async () => { const h = await hit(''), c = await ev(() => kbbEditor.project.canvas); return { x: (h.box.x + h.box.w / 2) / c.w, y: (h.box.y + h.box.h / 2) / c.h }; };
  const f916 = await frac();
  await page.click('.aspect button[data-v="16:9"]'); await page.waitForTimeout(250);
  const f169 = await frac();
  ok(Math.abs(f169.y - f916.y) < 0.02 && Math.abs(f169.x - f916.x) < 0.02, `16:9 starts mapped: line A at ${f2(f169.x * 100)}%/${f2(f169.y * 100)}% of the frame (9:16: ${f2(f916.x * 100)}%/${f2(f916.y * 100)}%)`);
  await seek(tA);
  await drag('', 300, 120, ['Alt']);
  const at169 = await pos('', 'LA');
  await page.mouse.move(5, 5); await page.waitForTimeout(100); await shot('per-format-16x9');
  for (const a of ['1:1', '4:5']) { await page.click(`.aspect button[data-v="${a}"]`); await page.waitForTimeout(200); }
  const f45 = await frac();
  await page.click('.aspect button[data-v="9:16"]'); await page.waitForTimeout(250);
  ok(JSON.stringify(await pos('', 'LA')) === JSON.stringify(at916.A) && JSON.stringify(await pos('', 'LB')) === JSON.stringify(at916.B), `back to 9:16: A and B exactly where they were (${JSON.stringify(at916.A)})`);
  await seek(tA); await page.mouse.move(5, 5); await page.waitForTimeout(100); await shot('per-format-9x16');
  await page.click('.aspect button[data-v="16:9"]'); await page.waitForTimeout(250);
  ok(JSON.stringify(await pos('', 'LA')) === JSON.stringify(at169), `back to 16:9: A exactly where it was dropped in 16:9 (${JSON.stringify(at169)})`);
  ok(f45.x > 0 && f45.y > 0, `4:5 started from a mapped spot (${f2(f45.x * 100)}%/${f2(f45.y * 100)}% of the frame)`);

  // ── 10. preview = export: the exported frame shows the box within 2 px of the drop, 16:9 then 9:16
  async function exportCheck(label) {
    await seek(tA);
    const want = (await hit('')).box;
    await ev(() => { kbbEditor.lastExport = null; });
    await page.click('.export-btn'); await page.waitForSelector('#x-go'); await page.click('#x-go');
    await page.waitForFunction(() => kbbEditor.lastExport, null, { timeout: 300000 });
    const lx = await ev(() => kbbEditor.lastExport); await page.click('#x-close');
    const file = path.join(STUDIO, lx.path || 'none');
    if (lx.error || !fs.existsSync(file)) { ok(false, `${label} export: ${lx.error || 'no file'}`); return; }
    const pr = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-select_streams', 'v', '-show_entries', 'stream=width,height', '-of', 'json', file]).toString()).streams[0];
    const raw = execFileSync('ffmpeg', ['-v', 'error', '-ss', String(tA), '-i', file, '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 64 << 20 });
    let x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1;
    for (let y = 0; y < pr.height; y++) for (let x = 0; x < pr.width; x++) { const i = (y * pr.width + x) * 3; if (raw[i + 1] > 128 && raw[i] < 110 && raw[i + 2] < 110) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; } }
    const c = await ev(() => kbbEditor.project.canvas), s = c.w / pr.width;
    const got = { x: x0 * s, y: y0 * s, w: (x1 + 1 - x0) * s, h: (y1 + 1 - y0) * s };
    const err = Math.max(Math.abs(got.x - want.x), Math.abs(got.y - want.y), Math.abs(got.x + got.w - want.x - want.w), Math.abs(got.y + got.h - want.y - want.h));
    ok(err <= 2, `${label} export ${pr.width}×${pr.height}: caption box at ${f2(got.x)},${f2(got.y)} ${f2(got.w)}×${f2(got.h)} vs preview ${f2(want.x)},${f2(want.y)} ${f2(want.w)}×${f2(want.h)} — worst edge ${f2(err)} px`);
    return { file, got, want };
  }
  await exportCheck('16:9');
  await page.click('.aspect button[data-v="9:16"]'); await page.waitForTimeout(250);
  await exportCheck('9:16');

  // ── 11. a translated second track (captions.tracks.ar, its own caption item): each track's lines move on their own
  await ev(async () => {
    const P = await import('/app/core/project.js'); const ed = kbbEditor, p = ed.project, c = p.captions;
    const words = [{ w: 'السطر', t0: 0, t1: 0.7 }, { w: 'الأول', t0: 0.7, t1: 1.5 }, { w: 'الثاني', t0: 1.5, t1: 3 }, { w: 'الثالث', t0: 3, t1: 4.5 }].map((w) => ({ ...w, conf: 1 }));
    const lines = [{ id: 'RA', t0: 0, t1: 1.5, wordIdx: [0, 1] }, { id: 'RB', t0: 1.5, t1: 3, wordIdx: [2, 2] }, { id: 'RC', t0: 3, t1: 4.5, wordIdx: [3, 3] }];
    const track = P.createTrack('caption', 'Captions · AR'), idx = p.tracks.findIndex((t) => t.kind === 'caption') + 1;
    const C = await import('/app/captions/commands.js');
    ed.history.apply(ed.ops.batch('Translate', [C.captionsCommand('tr', 'Translate', (cap) => ({ ...cap, tracks: { ar: { lang: 'ar', words, lines, style: { id: 'word-box', pos: { x: 0.5, y: 0.08 }, bg: { type: 'box', color: '#2050ff', opacity: 1, radius: 0, pad: 18 }, outline: { w: 0, color: '#000' }, anim: 'none' } } } })),
      ed.ops.addTrack(track, idx), ed.ops.addItems([{ trackId: track.id, item: P.createItem('caption', { capTrack: 'ar', t0: 0, in: 0, out: P.projectDuration(p), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'Arabic captions' }) }])]));
  });
  await seek(tA);
  const mainA0 = await pos('', 'LA'), ar0 = await hit('ar');
  d = await drag('ar', -150, 200, ['Alt']);
  const arA = await pos('ar', 'RA'), ar1 = await hit('ar');
  ok(ar0 && arA && JSON.stringify(await pos('', 'LA')) === JSON.stringify(mainA0) && (await pos('ar', 'RB')) === null && Math.abs(ar1.box.y - (ar0.box.y + d.sdy)) < 0.15,
    `translated track: dragging the Arabic line moves captions.tracks.ar line RA only (${JSON.stringify(arA)}); the English line and RB stay`);
  const en0 = await hit('');
  d = await drag('', 0, 80, ['Alt']);
  { const en1 = (await hit('')).box; ok(JSON.stringify(await pos('ar', 'RA')) === JSON.stringify(arA) && Math.abs(en1.y - (en0.box.y + d.sdy)) < 0.15, `with the translation present, dragging the English line moves the English line only (Δy ${f2(en1.y - en0.box.y)} for ${f2(d.sdy)})`); }
  await seek(tB);
  const arB0 = await hit('ar');
  d = await drag('ar', 0, 120, ['Shift', 'Alt']);
  const arSt = await pos('ar', null), enSt = await pos('', null);
  ok(arSt && Math.abs(arSt.y - (0.08 + d.sdy / safe.h)) < 0.003 && enSt === null && Math.abs((await hit('ar')).box.y - (arB0.box.y + d.sdy)) < 0.15, `Shift-drag on the Arabic line moves the Arabic track (style y ${f2(arSt && arSt.y)}), the English style untouched`);
  await page.mouse.move(5, 5); await seek(tA); await page.waitForTimeout(100); await shot('translated-track');

  // ── 12. the sync lane's caption clock (wrapped layer): the grab finds the line the layer DRAWS
  await ev(() => kbbEditor.apply(kbbEditor.ops.setProject({ meta: { ...kbbEditor.project.meta, sync: { nudgeMs: 400, leadMs: 0 } } }, 'Nudge')));
  await seek(1.3);                                   // the layer draws t + 0.4 = 1.7 s → line B
  const drawnLine = (await hit('')).line;
  const pAbefore = await pos('', 'LA'), pBbefore = await pos('', 'LB');
  await drag('', 0, -60, ['Alt']);
  ok(drawnLine === 'LB' && JSON.stringify(await pos('', 'LA')) === JSON.stringify(pAbefore) && JSON.stringify(await pos('', 'LB')) !== JSON.stringify(pBbefore),
    'with a +400 ms caption nudge (sync/index.js wrapper), dragging at 1.3 s moves line B — the one on screen — not A');
  await ev(() => kbbEditor.apply(kbbEditor.ops.setProject({ meta: { ...kbbEditor.project.meta, sync: { nudgeMs: 0, leadMs: 0 } } }, 'Nudge')));

  // ── 13. hover: box + move cursor + the first-time hint; the picked line keeps its outline
  await ev(() => { try { localStorage.removeItem('kbb.capMoveHint'); } catch {} __pc.D.selectLines([]); });
  await seek(tC);
  { const h = await hit(''), v = await ev(() => __pc.view());
    await page.mouse.move(v.left + (h.box.x + h.box.w / 2) * v.k, v.top + (h.box.y + h.box.h / 2) * v.k); }
  await page.waitForTimeout(120);
  const hov = await ev(() => ({ box: !!document.querySelector('.cap-box.hover'), hint: document.querySelector('.cap-hint')?.textContent, cursor: document.querySelector('.pv-overlay').style.cursor }));
  await shot('hover-box');
  ok(hov.box && hov.cursor === 'move' && hov.hint === 'Drag to move this line · Shift-drag moves all lines', `hover: dashed box, move cursor, hint “${hov.hint}” (picture: hover-box.png)`);
  await drag('', 0, -40, ['Alt']);
  const sel = await ev(() => ({ box: !!document.querySelector('.cap-box.sel'), hdl: document.querySelectorAll('.cap-box.sel .cap-hdl').length, hint: !!document.querySelector('.cap-hint') }));
  await page.mouse.move(5, 5); await page.waitForTimeout(100);
  ok(sel.box && sel.hdl === 4 && !(await ev(() => !!document.querySelector('.cap-hint'))), 'after the first drag: the line keeps an outline with 4 handles, and the hint does not come back');
  await shot('selected-line');

  // ── pictures in the real look (word-box style, nothing selected, the Arabic track on top)
  const hstack = (a, b, out) => { execFileSync('ffmpeg', ['-y', '-v', 'error', '-i', path.join(SHOTS, a + '.png'), '-i', path.join(SHOTS, b + '.png'), '-filter_complex', '[0][1]hstack', path.join(SHOTS, out + '.png')]); fs.rmSync(path.join(SHOTS, a + '.png')); fs.rmSync(path.join(SHOTS, b + '.png')); };
  for (const f of fs.readdirSync(SHOTS)) fs.rmSync(path.join(SHOTS, f));
  await ev(async () => { const C = await import('/app/captions/commands.js'); kbbEditor.apply(C.setStyle({ id: 'word-box', ...(kbbEditor.project.captions.style.pos ? { pos: kbbEditor.project.captions.style.pos } : {}) })); kbbEditor.apply(__pc.D.resetAll(kbbEditor.project.captions, 'ar')); kbbEditor.select([]); __pc.D.selectLines([]); try { localStorage.removeItem('kbb.capMoveHint'); } catch {} });
  const centre = async (lang = '') => { const h = await hit(lang), v = await ev(() => __pc.view()); return { x: v.left + (h.box.x + h.box.w / 2) * v.k, y: v.top + (h.box.y + h.box.h / 2) * v.k, v, h }; };
  await seek(tA); await page.mouse.move(5, 5);
  { const c = await centre(); await page.mouse.move(c.x, c.y); await page.waitForTimeout(150); await pic('hover-box'); }
  { const c = await centre(); await page.mouse.down();
    const tx = c.v.left + (C.w / 2 + 2) * c.v.k, ty = c.v.top + (C.h / 3 + c.h.box.h / 2 + 2) * c.v.k;
    for (let i = 1; i <= 8; i++) await page.mouse.move(c.x + (tx - c.x) * i / 8, c.y + (ty - c.y) * i / 8);
    await page.waitForTimeout(100); await pic('guides-during-drag'); await page.mouse.up(); await ev(() => kbbEditor.history.undo()); }
  await ev(() => { const m = kbbEditor.project.media[0]; __pc.bus.emit('auto:analysis', { mediaId: m.id, subjects: [0, 1, 2, 3, 4].map((t) => ({ t, x: 0.5, y: 0.3, w: 0.42, h: 0.22, conf: 0.9 })) }); });
  { const c = await centre(), f = await ev(() => __pc.D.faceBox(kbbEditor.project, kbbEditor.t)); await page.keyboard.down('Alt'); await page.mouse.move(c.x, c.y); await page.mouse.down();
    const ty = c.v.top + (f.y + f.h * 0.75) * c.v.k;
    for (let i = 1; i <= 8; i++) await page.mouse.move(c.x, c.y + (ty - c.y) * i / 8);
    await page.waitForTimeout(100); await pic('guides-face-warning'); await page.mouse.up(); await page.keyboard.up('Alt'); await ev(() => kbbEditor.history.undo()); }
  await ev(() => { const m = kbbEditor.project.media[0]; __pc.bus.emit('auto:analysis', { mediaId: m.id, subjects: [] }); });
  await page.mouse.move(5, 5); await seek(tA); await page.waitForTimeout(120); await pic('two-lines-a');
  await seek(tB); await page.waitForTimeout(120); await pic('two-lines-b'); hstack('two-lines-a', 'two-lines-b', 'two-lines');
  await seek(tA);
  { const c = await centre(); await page.mouse.click(c.x, c.y, { button: 'right' }); await page.waitForSelector('.cap-menu'); await page.waitForTimeout(120); await pic('right-click-menu'); await page.keyboard.press('Escape'); }
  await page.mouse.move(5, 5); await page.waitForTimeout(100); await pic('selected-line');
  await pic('per-format-9x16');
  await page.click('.aspect button[data-v="16:9"]'); await page.waitForTimeout(300); await seek(tA); await page.mouse.move(5, 5); await page.waitForTimeout(150); await pic('per-format-16x9');
  hstack('per-format-9x16', 'per-format-16x9', 'per-format');
  await page.click('.aspect button[data-v="9:16"]'); await page.waitForTimeout(200);

  const real = errors.filter((x) => !/No available adapters|AudioContext was not allowed/.test(x));
  ok(real.length === 0, 'no console errors' + (real.length ? ' — ' + real.slice(0, 3).join(' | ') : ''));
  await browser.close(); await H.stopEngine(e);
  fs.rmSync(STUDIO, { recursive: true, force: true });
  console.log(fails ? `${fails} FAILED` : 'ALL PASS');
  process.exit(fails ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
