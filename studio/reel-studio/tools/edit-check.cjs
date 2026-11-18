#!/usr/bin/env node
// KBB Studio — timeline editing check (edit-ops.js, edit-follow.js, tl-drag.js, captions re-split in sync/follow.js),
// headless Chrome against the Linux engine. No speech models: captions are a synthetic word list (+ a translated track).
//
//   cut       split at arbitrary frames (S, blade click); ripple delete and lift of a middle chunk; rejoin; ripple trim
//   captions  after a cut the missing words are gone, the later ones shift EXACTLY, a line spanning the cut is re-split,
//             a piece left too short joins its neighbour, the translated track follows, line positions survive
//   follow    moving a clip carries its captions; theme elements and transitions follow cuts and moves; a lifted cut
//             loses its transition, a rippled one re-anchors it; music, b-roll and markers stay put
//   drag      between tracks, a new overlay track above the top one (z-order), insert vs overwrite on the main track,
//             snapping (playhead, caption lines, beats; ⌘ = free), multi-select drag, drops from the bin / Finder
//   misc      linked audio + Unlink, ONE undo step per edit, save/reload, an old project fixture opens unchanged and
//             follows edits, export = preview after edits, drag frame time with 200+ items
//   shots     shots/edit/*.png at 1440×900
//
//   bash tools/make-test-clips.sh && NODE_PATH=/home/user/kbbstore/node_modules node tools/edit-check.cjs
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, CLIPS = path.join(ROOT, 'tools', 'out'), OUT = path.join(CLIPS, 'edit'), SHOTS = path.join(ROOT, 'shots', 'edit');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0; const results = [];
const ok = (c, msg) => { const l = (c ? 'PASS ' : 'FAIL ') + msg; console.log(l); results.push(l); if (!c) failures++; };
const W = 1440, HH = 900;

(async () => {
  for (const f of ['portrait.mp4', 'wide.mp4', 'photo.png']) if (!fs.existsSync(path.join(CLIPS, f))) throw new Error('run tools/make-test-clips.sh first');
  const dir = H.makeStudio();
  fs.mkdirSync(path.join(dir, 'library', 'edit-test'), { recursive: true });
  fs.copyFileSync(path.join(CLIPS, 'portrait.mp4'), path.join(dir, 'library', 'edit-test', 'portrait.mp4'));
  const e = await H.startEngine(dir);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: W, height: HH } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const shot = async (name) => { await page.waitForTimeout(250); await page.screenshot({ path: path.join(SHOTS, name + '.png') }); };
  const settle = () => ev(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
  const boot = async () => {
    await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0, null, { timeout: 60000 });
    await ev(async () => {
      window.EO = await import('/app/editor/edit-ops.js'); window.TD = await import('/app/editor/tl-drag.js');
      window.CM = await import('/app/captions/model.js'); window.CC = await import('/app/captions/commands.js');
      window.P = await import('/app/core/project.js'); window.ED = window.kbbEditor;
      window.vt = () => ED.project.tracks.find((t) => t.kind === 'video').items.slice().sort((a, b) => a.t0 - b.t0);
      window.r3 = (x) => Math.round(x * 1000) / 1000;
      window.capSnap = () => JSON.stringify(ED.project.captions);
      window.steps = () => ED.history.undoStack.length;
    });
  };

  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  await page.setInputFiles('.home-drop input[type=file]', path.join(CLIPS, 'portrait.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await boot();
  await page.setInputFiles('.bin input[type=file]', [path.join(CLIPS, 'wide.mp4'), path.join(CLIPS, 'photo.png')]);
  await page.waitForFunction(() => kbbEditor.project.media.length === 3, null, { timeout: 60000 });
  // the bin import also put them on the timeline: take them off again (the reel starts as one 20 s clip)
  await ev(() => { const ids = ED.project.tracks.flatMap((t) => t.items).filter((i) => i.media && i.media !== ED.project.media[0].id).map((i) => i.id); if (ids.length) ED.apply(ED.ops.removeItems(ids)); ED.history.clear(); });

  // ── synthetic captions: 40 words, every 0.45 s from 0.5 s, a translated track with the same timing, set lines ──
  await ev(() => {
    const p = ED.project;
    ED.apply(ED.ops.setProject({ meta: { ...p.meta, sync: { auto: false, follow: true } } }, 'Sync settings'));
    const words = Array.from({ length: 40 }, (_, i) => ({ w: 'w' + i, t0: r3(0.5 + i * 0.45), t1: r3(0.85 + i * 0.45), conf: 1 }));
    const alt = words.map((w, i) => ({ ...w, w: 'a' + i }));
    const cuts = [[0, 5], [6, 14], [15, 21], [22, 27], [28, 33], [34, 39]];
    const lines = (ws, pre) => cuts.map(([a, b], k) => ({ id: pre + k, t0: ws[a].t0, t1: ws[b].t1, wordIdx: [a, b] }));
    const L = lines(words, 'L'); L[1].ov = { pos: { x: 0.31, y: 0.42 } }; L[3].ov = { pos: { x: 0.7, y: 0.25 } };
    const AL = lines(alt, 'A'); AL[1].ov = { pos: { x: 0.5, y: 0.2 } };
    CM.finishTimes(L, words, 0.7); CM.finishTimes(AL, alt, 0.7);
    const cap = { style: 'word-box', lang: 'en', words, lines: L, alt: { lang: 'ar', words: alt, lines: AL } };
    const tr = p.tracks.find((t) => t.kind === 'caption');
    ED.apply(ED.ops.batch('Captions', [CC.setCaptions(cap, p.canvas), ED.ops.addItems([{ trackId: tr.id, item: P.createItem('caption', { t0: 0, in: 0, out: 20, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'Captions' }) }])]));
    ED.history.clear();
  });
  const anchored = await ev(() => ED.project.captions.words.every((w) => w.src) && ED.project.captions.alt.words.every((w) => w.src));
  ok(anchored, 'captions: every word (and every translated word) is anchored to its media time');

  // ── 1. split at arbitrary frames ──
  let r = await ev(() => {
    const cap0 = capSnap(), s0 = steps();
    ED.select([vt()[0].id]); ED.seek(7.3); ED.split();
    ED.seek(95 / 30); ED.select([]); ED.split();            // nothing selected: everything under the playhead
    const v = vt();
    return { v: v.map((c) => [c.t0, c.in, c.out]), frames: v.every((c) => Math.abs(c.t0 * 30 - Math.round(c.t0 * 30)) < 1e-6 && Math.abs(c.in * 30 - Math.round(c.in * 30)) < 1e-6), contiguous: v.every((c, i) => !i || (Math.abs(v[i - 1].out - c.in) < 1e-9 && Math.abs(P.itemEnd(v[i - 1]) - c.t0) < 1e-9)), capSame: capSnap() === cap0, steps: steps() - s0 };
  });
  ok(r.v.length === 3 && r.frames && r.contiguous && Math.abs(r.v[1][0] - 95 / 30) < 1e-4 && Math.abs(r.v[2][0] - 7.3) < 1e-4, `split at 7.3 s and at frame 95: three pieces on the frame grid, source and timeline contiguous ${JSON.stringify(r.v)}`);
  ok(r.capSame, 'a blade cut alone leaves the captions exactly as they were');
  ok(r.steps === 2, `one undo step per split (${r.steps})`);
  // the blade tool: B, click a clip
  await page.keyboard.press('b');
  const box = await page.locator('.k-video .tl-item').nth(2).boundingBox();
  const pps = await ev(() => ED.timeline.pps);
  const lane = await page.locator('.k-video .tl-lane').boundingBox();
  const xAt = (t) => lane.x + t * pps;
  await page.mouse.click(xAt(12.4), box.y + box.height / 2);
  r = await ev(() => ({ v: vt().map((c) => c.t0), blade: ED.timeline.blade }));
  ok(r.v.length === 4 && r.v.some((t) => Math.abs(t * 30 - Math.round(t * 30)) < 1e-6 && Math.abs(t - 12.4) < 0.1), `blade (B) + click cuts the clip under the pointer at a frame (${r.v.map((x) => x.toFixed(3)).join(', ')})`);
  await page.mouse.move(xAt(10.2), box.y + box.height / 2); await page.waitForTimeout(150);
  await shot('01-blade-cut');
  await page.keyboard.press('b');
  // rejoin: the blade cut at ~12.4 joins back
  r = await ev(() => { const v = vt(), b = v.find((c) => Math.abs(c.t0 - 7.3) < 1e-6), s0 = steps(); ED.select([b.id]); EO.joinCmd && TD.join(ED); return { n: vt().length, end: P.itemEnd(vt()[2]), out: vt()[2].out, steps: steps() - s0 }; });
  ok(r.n === 3 && Math.abs(r.out - 20) < 1e-6 && r.steps === 1, `⌘J rejoins the two pieces of the same clip (3 pieces again, the last plays to 20 s) in one step`);
  await ev(() => { ED.history.undo(); ED.history.redo(); });

  // ── 2. ripple delete of a middle chunk (source 4–6 s) via a marked range ──
  await ev(() => ED.seek(9));
  const rl = await page.locator('.ruler-lane').boundingBox();
  await page.keyboard.down('Shift');
  await page.mouse.move(rl.x + 4 * pps, rl.y + 10); await page.mouse.down();
  await page.mouse.move(rl.x + 6 * pps, rl.y + 10, { steps: 6 }); await page.mouse.up();
  await page.keyboard.up('Shift');
  r = await ev(() => TD.marks());
  ok(Math.abs(r.in - 4) < 0.04 && Math.abs(r.out - 6) < 0.04, `Shift-drag on the ruler marks a range (${r.in}–${r.out} s)`);
  await ev(() => TD.setMarks({ in: 4, out: 6 }));   // exact for the numbers below
  await ev(() => { ED.showSide('captions'); ED.seek(3.7); });
  await page.waitForTimeout(500);
  await shot('02-range-selected');
  await shot('03-captions-before-cut');
  const before = await ev(() => ({ cap: JSON.parse(capSnap()), tracks: JSON.stringify(ED.project.tracks), dur: ED.duration(), s: steps() }));
  await page.keyboard.press('Delete');
  r = await ev(() => ({ cap: ED.project.captions, v: vt().map((c) => [r3(c.t0), r3(c.in), r3(c.out)]), dur: ED.duration(), s: steps(), marks: TD.marks(), long: ED.project.tracks.flatMap((t) => t.items.filter((i) => P.itemEnd(i) > 18.01).map((i) => t.kind + ':' + i.type + ':' + r3(P.itemEnd(i)))) }));
  const W0 = before.cap.words, W1 = r.cap.words;
  const gone = W0.filter((w) => w.t0 >= 4 - 1e-6 && w.t0 < 6).map((w) => w.w);
  const kept = W1.map((w) => w.w);
  ok(gone.length === 5 && gone.every((g) => !kept.includes(g)) && r.cap.offcut?.length === 5, `ripple delete: the words said in the cut are gone from the captions (${gone.join(' ')}) and wait off-cut`);
  const shiftErr = Math.max(...W0.filter((w) => w.t0 >= 6).map((w) => Math.abs(W1.find((x) => x.w === w.w).t0 - (w.t0 - 2))));
  const sameErr = Math.max(...W0.filter((w) => w.t1 <= 4).map((w) => Math.abs(W1.find((x) => x.w === w.w).t0 - w.t0)));
  ok(shiftErr < 1e-3 && sameErr < 1e-6, `words after the cut moved exactly −2.000 s (max error ${(shiftErr * 1000).toFixed(2)} ms), words before it did not move`);
  ok(Math.abs(r.dur - (before.dur - 2)) < 1e-3 && r.v.length === 4 && r.v[2][0] === 4 && r.v[2][1] === 6, `the gap closed: the reel is ${before.dur.toFixed(2)} → ${r.dur.toFixed(2)} s, video ${JSON.stringify(r.v)}`);
  const lineWords = (cap) => cap.lines.map((L) => cap.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => w.w));
  const lw = lineWords(r.cap);
  ok(!lw.some((l) => l.includes('w7') && l.includes('w13')) && lw.some((l) => l.join(' ') === 'w6 w7') && lw.some((l) => l[0] === 'w13'), `the line "w6 … w14" that spanned the cut is re-split at it: ${JSON.stringify(lw.slice(0, 4))}`);
  const covers = r.cap.lines.every((L, i) => !i || L.wordIdx[0] === r.cap.lines[i - 1].wordIdx[1] + 1) && r.cap.lines[r.cap.lines.length - 1].wordIdx[1] === W1.length - 1;
  ok(covers && CMval(r.cap), 'the re-split lines cover every word once, in order, with valid times');
  function CMval(cap) { return cap.lines.every((L) => L.t1 >= L.t0) && cap.words.every((w, i) => !i || w.t0 >= cap.words[i - 1].t0 - 1e-6); }
  const pos = (cap, w) => cap.lines.find((L) => cap.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).some((x) => x.w === w))?.ov?.pos;
  ok(JSON.stringify(pos(r.cap, 'w6')) === '{"x":0.31,"y":0.42}' && JSON.stringify(pos(r.cap, 'w13')) === '{"x":0.31,"y":0.42}' && JSON.stringify(pos(r.cap, 'w22')) === '{"x":0.7,"y":0.25}', 'per-line positions survive: both pieces of the split line keep its drag position, other lines keep theirs');
  const A0 = before.cap.alt.words, A1 = r.cap.alt.words;
  const altErr = Math.max(...A0.filter((w) => w.t0 >= 6).map((w) => Math.abs(A1.find((x) => x.w === w.w).t0 - (w.t0 - 2))));
  const alw = lineWords(r.cap.alt);
  ok(!A1.some((w) => ['a8', 'a9', 'a10', 'a11', 'a12'].includes(w.w)) && altErr < 1e-3 && !alw.some((l) => l.includes('a7') && l.includes('a13')) && JSON.stringify(pos(r.cap.alt, 'a13')) === '{"x":0.5,"y":0.2}', 'the translated track follows the same rules (words gone, times shifted exactly, its line re-split, its position kept)');
  ok(r.s - before.s === 1 && r.marks.in == null, `ONE undo step for the ripple delete (+${r.s - before.s}), the range is cleared`);
  await ev(() => ED.seek(3.7)); await page.waitForTimeout(400);
  await shot('04-captions-after-cut');
  // a second cut that leaves ONE word of a line alone: it joins its neighbour
  r = await ev(() => {
    // source 7.6–10.2 s is timeline 5.6–8.2 s now
    ED.apply(EO.rangeCmd(ED.project, 5.6, 8.2, { ripple: true }));
    return lineWords(ED.project.captions);
    function lineWords(cap) { return cap.lines.map((L) => cap.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => w.w)); }
  });
  ok(!r.some((l) => l.length === 1) && r.some((l) => l.includes('w15') && l.length > 1), `a piece left with one word ("w15") joins a neighbour: ${JSON.stringify(r.filter((l) => l.includes('w15')))}`);
  r = await ev(() => { ED.history.undo(); ED.history.undo(); return { tracks: JSON.stringify(ED.project.tracks), cap: capSnap() }; });
  ok(r.tracks === before.tracks && r.cap === JSON.stringify(before.cap), 'undo of the two cuts restores clips and captions exactly');
  // Remove silences / fillers cut the same way: the same source range through its command gives the same captions
  r = await ev(async () => {
    const AC = await import('/app/auto/commands.js'), p = ED.project, mid = p.media[0].id;
    const view = () => JSON.stringify({ w: p.captions.words.map((w) => [w.w, w.t0, w.t1]), l: p.captions.lines.map((L) => [L.wordIdx, L.ov || null]), a: p.captions.alt.words.map((w) => [w.w, w.t0]) });
    ED.apply(EO.rangeCmd(p, 4, 6, { ripple: true })); const manual = view(), mDur = ED.duration(); ED.history.undo();
    const firstId = vt()[0].id, s0 = steps();
    ED.apply(AC.removeCutsCommand(p, mid, [{ t0: 4, t1: 6 }])); const auto = view(), aDur = ED.duration(), keptId = vt()[0].id === firstId, n = steps() - s0;
    ED.history.undo();
    return { same: manual === auto, mDur, aDur, keptId, n, diff: manual === auto ? '' : manual.slice(0, 200) + ' ≠ ' + auto.slice(0, 200) };
  });
  ok(r.same && Math.abs(r.mDur - r.aDur) < 1e-3 && r.keptId && r.n === 1, `Remove silences cuts like a manual ripple delete: identical captions (words, lines, positions, translated track), same length (${r.aDur.toFixed(2)} s), the first piece keeps its clip id (transitions and elements still find it), one undo step ${r.diff}`);

  // ── 3. lift of a middle chunk (the gap stays) ──
  r = await ev(() => {
    const d0 = ED.duration(), w0 = ED.project.captions.words.map((w) => [w.w, w.t0]);
    TD.setMarks({ in: 12, out: 13 });
    TD.deleteRange(ED, true);
    const w1 = new Map(ED.project.captions.words.map((w) => [w.w, w.t0]));
    const moved = w0.filter(([w, t]) => w1.has(w) && Math.abs(w1.get(w) - t) > 1e-6).length;
    const goneW = w0.filter(([w, t]) => !w1.has(w)).map(([w]) => w);
    const v = vt();
    return { d0, d1: ED.duration(), moved, goneW, gap: v.some((c, i) => i && c.t0 - P.itemEnd(v[i - 1]) > 0.99) };
  });
  ok(Math.abs(r.d1 - r.d0) < 1e-6 && r.gap && r.moved === 0 && r.goneW.join() === 'w26,w27', `lift (Alt+Delete): the gap stays (${r.d0.toFixed(2)} s both), no word moved, the lifted words are gone (${r.goneW.join(' ')})`);
  await ev(() => ED.history.undo());

  // ── 4. moving a clip carries its captions ──
  r = await ev(() => {
    const v = vt(), last = v[v.length - 1], first = v[0];
    const w0 = new Map(ED.project.captions.words.map((w) => [w.w, w.t0]));
    // put the last piece (7.3–20 s) first: an INSERT before the first clip on the magnetic main track
    const cmd = EO.placeCmd(ED.project, [{ id: last.id, item: last, fromTrackId: ED.project.tracks.find((t) => t.kind === 'video').id }], 0, { trackId: ED.project.tracks.find((t) => t.kind === 'video').id }, 'insert', { insert: { anchor: first.id, side: 'before' } }).cmd;
    ED.apply(cmd);
    const w1 = new Map(ED.project.captions.words.map((w) => [w.w, w.t0]));
    const nv = vt();
    // w16 (7.7 s) was in the moved piece: it is now at 7.7 − 7.3; w2 (1.4 s) was in the first piece, now after the 12.7 s piece
    return { w16: [w0.get('w16'), w1.get('w16')], w2: [w0.get('w2'), w1.get('w2')], order: nv.map((c) => [r3(c.t0), r3(c.in)]), dur: ED.duration() };
  });
  ok(Math.abs(r.w16[1] - (r.w16[0] - 7.3)) < 1e-3 && Math.abs(r.w2[1] - (r.w2[0] + 12.7)) < 1e-3, `re-ordering clips moves their captions with them (w16 ${r.w16[0]} → ${r.w16[1]} s, w2 ${r.w2[0]} → ${r.w2[1]} s; clips ${JSON.stringify(r.order)})`);
  ok(Math.abs(r.dur - 20) < 1e-3, `an insert on the magnetic main track closes the hole it left (reel still ${r.dur.toFixed(2)} s)`);
  await ev(() => ED.history.undo());

  // ── 5. theme elements and transitions follow cuts and moves ──
  r = await ev(async () => {
    const A = await import('/app/themes/apply.js'), T = await import('/app/themes/transitions.js');
    const ctx = ED.context(), v = vt();     // pieces: 0–3.167, 3.167–7.3, 7.3–20
    const el = A.placeElement(ctx, 'stamp', { theme: 'travel', t0: 9.0 });             // over the third piece (source 9.0)
    const [tr] = T.placeTransition(ctx, 'flag', { at: 7.3, theme: 'travel' });          // on the cut at 7.3
    const music = P.createItem('audio', { media: ED.project.media[0].id, t0: 1, in: 0, out: 10, name: 'Music bed' });
    ED.apply(ED.ops.addItems([{ trackId: ED.project.tracks.find((t) => t.kind === 'music').id, item: music }], 'Music'));
    ED.apply(ED.ops.setProject({ markers: [{ id: 'k1', t: 15, label: 'M', color: '#ffd166' }] }, 'Marker'));
    const find = (id) => P.findItem(ED.project, id)?.item;
    const s0 = { el: find(el.id).t0, tr: find(tr.id).t0, music: find(music.id).t0, mk: ED.project.markers[0].t, n: steps() };
    // ripple delete 1–2 s (inside the first piece): everything after it on the main track moves 1 s left
    ED.apply(EO.rangeCmd(ED.project, 1, 2, { ripple: true }));
    const s1 = { el: find(el.id).t0, tr: find(tr.id)?.t0, trA: find(tr.id)?.style.a, trB: find(tr.id)?.style.b, music: find(music.id).t0, mk: ED.project.markers[0].t, n: steps() };
    const cut = EO.cutsOf(ED.project).find((c) => c.b.id === v[2].id);
    // move the piece with the sticker on it (source 7.3–20) to the front: the sticker rides along, the transition re-anchors
    const v2 = vt(), mainId = ED.project.tracks.find((t) => t.kind === 'video').id;
    const withEl = v2.find((c) => c.in <= 9 && c.out > 9);
    ED.apply(EO.placeCmd(ED.project, [{ id: withEl.id, item: withEl, fromTrackId: mainId }], 0, { trackId: mainId }, 'insert', { insert: { anchor: v2[0].id, side: 'before' } }).cmd);
    const s2 = { el: find(el.id).t0, tr: !!find(tr.id) };   // no clip before it any more: no cut into it, the transition goes
    ED.history.undo();
    // move the FIRST piece to the end: the cut and the clip after it both move 1 s left — transition and sticker follow
    const v5 = vt();
    const t5 = find(tr.id).t0, e5 = find(el.id).t0;
    ED.apply(EO.placeCmd(ED.project, [{ id: v5[0].id, item: v5[0], fromTrackId: mainId }], 0, { trackId: mainId }, 'insert', { insert: { anchor: v5[v5.length - 1].id, side: 'after' } }).cmd);
    const c5 = EO.cutsOf(ED.project).find((c) => c.b.id === find(tr.id)?.style.b);
    s2.move = { tr: [t5, find(tr.id)?.t0], el: [e5, find(el.id).t0], centred: !!c5 && Math.abs(find(tr.id).t0 + P.itemDur(find(tr.id)) / 2 - c5.t) < 1e-3 };
    ED.history.undo();
    // lift the transition's incoming clip: its cut becomes a gap — the transition goes
    const v3 = vt(), B = v3.find((c) => c.id === find(tr.id).style.b), Aout = v3.find((c) => c.id === find(tr.id).style.a);
    ED.apply(EO.rangeCmd(ED.project, B.t0, P.itemEnd(B), { ripple: false }));
    const s3 = { tr: !!find(tr.id) };
    ED.history.undo();
    // ripple-delete its outgoing clip: the incoming one now follows the piece before — the transition re-anchors there
    ED.apply(EO.rangeCmd(ED.project, Aout.t0, P.itemEnd(Aout), { ripple: true }));
    const t4 = find(tr.id), c4 = EO.cutsOf(ED.project).find((c) => c.b.id === B.id) || {};
    const s4 = { ok: !!t4 && !!c4.a && t4.style.a === c4.a.id && t4.style.a !== Aout.id && t4.style.b === c4.b.id && Math.abs(t4.t0 + P.itemDur(t4) / 2 - c4.t) < 1e-3 };
    ED.history.undo(); ED.history.undo();
    return { s0, s1, s2, s3, s4, cut: cut && r3(cut.t), elId: el.id, trId: tr.id, v2: v2.map((c) => c.id) };
  });
  ok(Math.abs(r.s1.el - (r.s0.el - 1)) < 1e-3 && Math.abs(r.s1.tr - (r.s0.tr - 1)) < 1e-3, `a ripple delete before them moves the theme element on the clip (${r.s0.el} → ${r.s1.el} s) and the transition on the cut (${r.s0.tr} → ${r.s1.tr} s) with the clips`);
  ok(r.s1.music === r.s0.music && r.s1.mk === r.s0.mk && r.s1.n - r.s0.n === 1, `music (${r.s1.music} s) and markers (${r.s1.mk} s) stay put; still one undo step`);
  ok(Math.abs(r.s2.el - 1.7) < 1e-3, `moving the clip to the front carries the sticker on it (now ${r.s2.el} s = 9.0 − 7.3)`);
  ok(r.s2.tr === false, 'moving the incoming clip to the very front (no clip before it, so no cut into it) removes its transition');
  ok(Math.abs(r.s2.move.tr[1] - (r.s2.move.tr[0] - 1)) < 1e-3 && Math.abs(r.s2.move.el[1] - (r.s2.move.el[0] - 1)) < 1e-3 && r.s2.move.centred, `moving the first clip to the end: the transition stays centred on its cut (${r.s2.move.tr[0]} → ${r.s2.move.tr[1]} s) and the sticker rides its clip (${r.s2.move.el[0]} → ${r.s2.move.el[1]} s)`);
  ok(r.s3.tr === false, 'lifting a clip (the cut becomes a gap) removes the transition that sat on it');
  ok(r.s4.ok, 'ripple-deleting a clip re-anchors its transition onto the new cut, centred on it');

  // ── 6. drag & drop: new overlay track above the top, insert vs overwrite, snapping, the indicator ──
  await ev(() => { ED.showSide('inspector'); ED.timeline.fit(); });
  await settle();
  // put the wide clip at the end of the main track, then drag it onto the band above the top row
  await ev(() => { const m = ED.project.media.find((x) => x.name === 'wide.mp4'); ED.addMediaToTimeline(m, { trackId: ED.project.tracks.find((t) => t.kind === 'video').id, t0: 20 }); });
  await settle();
  const geo = async () => ev(() => { const rows = [...document.querySelectorAll('.tl-row')].map((r) => { const b = r.getBoundingClientRect(); return { id: r.dataset.track, kind: [...r.classList].find((c) => c.startsWith('k-')), top: b.top, bottom: b.bottom }; }); const ln = document.querySelector('.k-video .tl-lane').getBoundingClientRect(); return { rows, x0: ln.left, pps: ED.timeline.pps }; });
  let g = await geo();
  const wideId = await ev(() => vt().find((c) => P.findMedia(ED.project, c.media).name === 'wide.mp4').id);
  const itemBox = (id) => page.locator(`.tl-item[data-id="${id}"]`).boundingBox();
  let b = await itemBox(wideId);
  const n0 = await ev(() => ED.project.tracks.length);
  await page.mouse.move(b.x + 20, b.y + b.height / 2); await page.mouse.down();
  await page.mouse.move(g.x0 + 3 * g.pps + 20, g.rows[0].top - 2, { steps: 14 });
  await page.waitForTimeout(120);
  r = await ev(() => ({ band: document.querySelector('.tl-drop.band')?.textContent || '' }));
  ok(/New track/.test(r.band), `dragging above the top track shows the "${r.band}" drop band`);
  await page.mouse.up(); await settle();
  r = await ev((id) => { const hit = P.findItem(ED.project, id), p = ED.project; return { kind: hit.track.kind, above: p.tracks.indexOf(hit.track) > p.tracks.findIndex((t) => t.kind === 'video'), n: p.tracks.length, t0: hit.item.t0, mainEnd: Math.max(...vt().map((c) => P.itemEnd(c))) }; }, wideId);
  ok(r.kind === 'overlay' && r.above && r.n === n0 + 1, `dropped on the band: a NEW overlay track is made and the clip sits on it, above the main track in z-order (picture over picture)`);
  ok(Math.abs(r.mainEnd - 20) < 1e-3, `the magnetic main track closed the hole the clip left (main ends at ${r.mainEnd.toFixed(2)} s)`);
  await ev((id) => { ED.select([id]); ED.seek(4); }, wideId); await page.waitForTimeout(400);
  await shot('06-overlay-track');
  // insert: drag it back down onto the main track, over the first half of the second piece -> inserted before it
  g = await geo(); b = await itemBox(wideId);
  const mainRow = g.rows.find((x) => x.kind === 'k-video');
  const sec = await ev(() => { const v = vt(); return { id: v[1].id, t0: v[1].t0, end: P.itemEnd(v[1]), after: v.slice(1).map((c) => [c.id, c.t0]) }; });
  await page.mouse.move(b.x + 10, b.y + b.height / 2); await page.mouse.down();
  await page.mouse.move(g.x0 + (sec.t0 + 0.4) * g.pps, mainRow.top + 20, { steps: 12 });
  await page.waitForTimeout(100);
  r = await ev(() => ({ ins: !!document.querySelector('.tl-drop.ins') }));
  await shot('05-drag-drop-indicator');
  await page.mouse.up(); await settle();
  r = await ev(({ id, sec }) => { const v = vt(), w = v.find((c) => c.id === id); return { on: P.findItem(ED.project, id).track.kind, t0: w?.t0, pushed: sec.after.map(([cid, t]) => r3(v.find((c) => c.id === cid).t0 - t)), wdur: P.itemDur(w) }; }, { id: wideId, sec });
  ok(r.on === 'video' && Math.abs(r.t0 - sec.t0) < 1e-3 && r.pushed.every((d) => Math.abs(d - r.wdur) < 1e-3), `insert on the main track: the clip lands before the clip it was dropped on (${r.t0?.toFixed(3)} s) and every later clip is pushed by its length (${r.pushed.join(', ')} s)`);
  // overwrite (Alt): drag the photo-less wide clip… use the first piece: Alt-drop it over the middle of the last clip
  await ev(() => ED.history.undo());   // back to the overlay layout? (undo the insert)
  await ev(() => ED.history.undo());   // and the move to the new track
  await settle();
  g = await geo(); b = await itemBox(wideId);
  const dur0 = await ev(() => ED.duration());
  await page.keyboard.down('Alt');
  await page.mouse.move(b.x + 10, b.y + b.height / 2); await page.mouse.down();
  await page.mouse.move(g.x0 + 10 * g.pps + 10, (await geo()).rows.find((x) => x.kind === 'k-video').top + 20, { steps: 10 });
  await page.waitForTimeout(80);
  const ow = await ev(() => !!document.querySelector('.tl-drop.box.ow'));
  await page.mouse.up(); await page.keyboard.up('Alt'); await settle();
  r = await ev((id) => {
    const w = P.findItem(ED.project, id).item, v = vt(); const s = w.t0, e2 = P.itemEnd(w);
    const next = v.find((c) => Math.abs(c.t0 - e2) < 1e-3 && c.id !== id), prev = v.find((c) => Math.abs(P.itemEnd(c) - s) < 1e-3 && c.id !== id);
    // the clip that was under the drop keeps playing its own source on both sides: cut at s, resumed at e2 (not pushed)
    return { t0: w.t0, overlapped: v.filter((c) => c.id !== id && c.t0 < e2 - 1e-3 && P.itemEnd(c) > s + 1e-3).length, nextOk: !!next && Math.abs(next.in - (7.3 + (e2 - 7.3))) < 1e-3, prevOk: !!prev };
  }, wideId);
  ok(ow && r.overlapped === 0 && r.nextOk && r.prevOk, `Alt-drop overwrites: the clip replaces what was under it (${r.t0.toFixed(2)} s) — that clip is cut before it and resumes after it at the same source time, nothing is pushed`);
  await ev(() => ED.history.undo()); await settle();
  // snapping: a text item dragged near the playhead lands exactly on it; with ⌘/Ctrl held it does not
  const tid = await ev(() => { const it = P.createItem('text', { t0: 2, in: 0, out: 1.5, text: 'Snap me', w: 600, h: 120, x: 240, y: 300, style: { size: 60, color: '#fff' }, name: 'Snap me' }); ED.apply(ED.ops.addItems([{ trackId: ED.project.tracks.find((t) => t.kind === 'text').id, item: it }])); ED.seek(9); ED.select([it.id]); return it.id; });
  await settle(); g = await geo(); b = await itemBox(tid);
  const grab = { x: b.x + b.width / 2, y: b.y + b.height / 2 };
  await page.mouse.move(grab.x, grab.y); await page.mouse.down();
  await page.mouse.move(grab.x + (9 - 2) * g.pps + 4, grab.y, { steps: 10 });
  await page.waitForTimeout(80);
  const snapLine = await ev(() => !!document.querySelector('.snap-line'));
  await shot('05b-drag-snap-line');
  await page.mouse.up(); await settle();
  let snapped = await ev((id) => P.findItem(ED.project, id).item.t0, tid);
  ok(snapLine && Math.abs(snapped - 9) < 1e-9, `snapping: dropped 4 px off the playhead it lands exactly on it (${snapped} s) and the snap line shows while dragging`);
  await ev(() => ED.history.undo()); await settle(); b = await itemBox(tid);
  await page.mouse.move(b.x + b.width / 2, grab.y); await page.mouse.down();
  await page.keyboard.down('Control');   // held after the press (a ⌘/Ctrl click toggles the selection)
  await page.mouse.move(b.x + b.width / 2 + (9 - 2) * g.pps + 4, grab.y, { steps: 10 }); await page.mouse.up();
  await page.keyboard.up('Control'); await settle();
  snapped = await ev((id) => P.findItem(ED.project, id).item.t0, tid);
  ok(Math.abs(snapped - 9) > 0.01, `holding ⌘/Ctrl drags free of the snap points (${snapped.toFixed(3)} s)`);
  r = await ev(() => { const p = ED.project; p.media[0].beats = [10.5]; const it = P.createItem('audio', { media: p.media[0].id, t0: 0, in: 0, out: 12 }); ED.apply(ED.ops.addItems([{ trackId: p.tracks.find((t) => t.kind === 'music').id, item: it }], 'beat test')); const pts = [...TD.snapPoints(ED, new Set())]; ED.history.undo(); delete p.media[0].beats; return { line: pts.includes(ED.project.captions.lines[2].t0), beat: pts.some((x) => Math.abs(x - 10.5) < 1e-9) }; });
  ok(r.line && r.beat, 'snap points include caption line boundaries and music beats');

  // ── 6b. drops from outside: the media bin, Themes, Finder — the same targets as a move ──
  const drop = async (data, x, y, { files = false, alt = false } = {}) => ev(async ({ data, x, y, files, alt }) => {
    const dt = new DataTransfer();
    for (const [k, v] of Object.entries(data)) dt.setData(k, v);
    if (files) { const c = new OffscreenCanvas(320, 240); const g = c.getContext('2d'); g.fillStyle = '#3a7'; g.fillRect(0, 0, 320, 240); const blob = await c.convertToBlob({ type: 'image/png' }); dt.items.add(new File([blob], 'dropped.png', { type: 'image/png' })); }
    const tgt = document.elementFromPoint(x, y) || ED.timeline.scroll;
    const o = { bubbles: true, cancelable: true, clientX: x, clientY: y, dataTransfer: dt, altKey: alt };
    tgt.dispatchEvent(new DragEvent('dragenter', o)); tgt.dispatchEvent(new DragEvent('dragover', o));
    const ind = document.querySelector('.tl-drop')?.className || '';
    tgt.dispatchEvent(new DragEvent('drop', o));
    return ind;
  }, { data, x, y, files, alt });
  await ev(() => { ED.select([]); ED.timeline.fit(); }); await settle(); g = await geo();
  const mainR = g.rows.find((x) => x.kind === 'k-video');
  r = await ev(() => ({ wide: ED.project.media.find((m) => m.name === 'wide.mp4').id, v: vt().map((c) => [c.id, c.t0]) }));
  const second = r.v[1];
  const ind1 = await drop({ 'application/x-kbb-media': r.wide }, g.x0 + (second[1] + 0.2) * g.pps, mainR.top + 20);
  r = await ev((sec) => { const v = vt(), w = v.find((c) => P.findMedia(ED.project, c.media).name === 'wide.mp4'); return { t0: w?.t0, d: P.itemDur(w), sec: v.find((c) => c.id === sec[0]).t0, steps: steps() }; }, second);
  ok(/ins/.test(ind1) && Math.abs(r.t0 - second[1]) < 1e-3 && Math.abs(r.sec - (second[1] + r.d)) < 1e-3, `a clip dragged from the media bin onto the main track shows the insert line and is INSERTED before the clip under it (${r.t0?.toFixed(2)} s; that clip pushed to ${r.sec?.toFixed(2)} s)`);
  await ev(() => ED.history.undo());
  await settle(); g = await geo();   // the insert grew the reel and the timeline re-fitted: read pps again
  const nT = await ev(() => ED.project.tracks.length);
  const ind2 = await drop({ 'application/x-kbb-media': (await ev(() => ED.project.media.find((m) => m.name === 'photo.png').id)) }, g.x0 + 2 * g.pps, g.rows[0].top - 2);
  r = await ev(() => { const it = ED.project.tracks.flatMap((t) => t.items).find((i) => i.media && P.findMedia(ED.project, i.media).name === 'photo.png'); const h = P.findItem(ED.project, it.id); return { kind: h.track.kind, n: ED.project.tracks.length, w: it.w, cw: ED.project.canvas.w, t0: it.t0 }; });
  ok(/band/.test(ind2) && r.kind === 'overlay' && r.n === nT + 1 && r.w < r.cw && Math.abs(r.t0 - 2) < 0.1, `a photo dropped above the top track lands on a NEW overlay track as a picture-in-picture (${r.w} px wide on a ${r.cw} px frame, at ${r.t0.toFixed(2)} s — dropped at 2 s)`);
  const tracksBefore = await ev(() => ED.project.tracks.map((t) => t.id));
  const ind3 = await drop({ 'application/x-kbb-theme-el': JSON.stringify({ el: 'stamp', theme: 'travel' }) }, g.x0 + 5 * g.pps, g.rows[0].top - 2);
  await page.waitForFunction(() => ED.project.tracks.flatMap((t) => t.items).some((i) => i.type === 'theme.el' && Math.abs(i.t0 - 5) < 0.3), null, { timeout: 10000 }).catch(() => {});
  r = await ev((tb) => { const it = ED.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.el' && Math.abs(i.t0 - 5) < 0.3); const h = it && P.findItem(ED.project, it.id); return { ok: !!it, fresh: !!h && !tb.includes(h.track.id) && h.track.items.length === 1, n: ED.project.tracks.length, label: ED.history.undoLabel }; }, tracksBefore);
  ok(r.ok && r.fresh && r.n === nT + 2 && /band/.test(ind3), `a theme element dragged from Themes onto the band lands where it was dropped, on its own new track (one undo step "${r.label}")`);
  const nM = await ev(() => ED.project.media.length);
  await drop({}, g.x0 + 1 * g.pps, (await geo()).rows.find((x) => x.kind === 'k-text').top + 10, { files: true });
  await page.waitForFunction((n) => ED.project.media.length > n && ED.project.tracks.flatMap((t) => t.items).some((i) => i.media && P.findMedia(ED.project, i.media)?.name === 'dropped.png'), nM, { timeout: 20000 }).catch(() => {});
  r = await ev(() => { const it = ED.project.tracks.flatMap((t) => t.items).find((i) => i.media && P.findMedia(ED.project, i.media)?.name === 'dropped.png'); return it ? { kind: P.findItem(ED.project, it.id).track.kind, t0: it.t0 } : null; });
  ok(!!r && r.kind === 'overlay', `a file dropped from Finder is imported and placed where it was dropped (over the text row → the overlay row above it: ${r?.kind}, ${r?.t0?.toFixed(2)} s)`);
  await ev(() => { for (let i = 0; i < 4 && ED.history.canUndo; i++) { const l = ED.history.undoLabel; if (/dropped|photo|stamp|Theme|theme|Add|Import/i.test(l)) ED.history.undo(); else break; } });

  // ── 6c. ripple trim (Alt + drag an edge): what comes after rides the edge ──
  await ev(() => ED.select([])); await settle(); g = await geo();
  r = await ev(() => vt().map((c) => [c.id, c.t0, P.itemEnd(c)]));
  const firstClip = r[0];
  await ev((id) => ED.select([id]), firstClip[0]); await settle();
  const fb = await page.locator(`.tl-item[data-id="${firstClip[0]}"] .trim.r`).boundingBox();
  await page.keyboard.down('Alt');
  await page.mouse.move(fb.x + fb.width / 2, fb.y + fb.height / 2); await page.mouse.down();
  await page.keyboard.up('Alt');
  await page.mouse.move(fb.x + fb.width / 2 - 1 * g.pps, fb.y + fb.height / 2, { steps: 6 }); await page.mouse.up(); await settle();
  r = await ev((before) => { const v = vt(); const d = P.itemEnd(v[0]) - before[0][2]; return { d, shifts: before.slice(1).map(([id, t0]) => (v.find((c) => c.id === id)?.t0 ?? NaN) - t0), contiguous: v.every((c, i) => !i || Math.abs(P.itemEnd(v[i - 1]) - c.t0) < 1e-3), label: ED.history.undoLabel }; }, r);
  ok(r.d < -0.5 && r.shifts.every((s) => Math.abs(s - r.d) < 1e-3) && r.contiguous && r.label === 'Ripple trim', `Alt + trim: the clip gets ${(-r.d).toFixed(2)} s shorter and every clip after it moves up by the same (${r.shifts.map((s) => s.toFixed(2)).join(', ')}), no gap`);
  await ev(() => ED.history.undo());

  // ── 7. multi-select drag ──
  const two = await ev(() => { const tr = ED.project.tracks.find((t) => t.kind === 'text'); const a = P.createItem('text', { t0: 12, in: 0, out: 1, text: 'A', name: 'A' }), b2 = P.createItem('sticker', { t0: 13.5, in: 0, out: 1, text: '✨', name: 'B' }); ED.apply(ED.ops.batch('two', [ED.ops.addItems([{ trackId: tr.id, item: a }]), ED.ops.addItems([{ trackId: ED.project.tracks.find((t) => t.kind === 'overlay').id, item: b2 }])])); ED.select([]); return [a.id, b2.id]; });
  await settle();
  let ba = await itemBox(two[0]); const bb = await itemBox(two[1]);
  await page.mouse.click(ba.x + ba.width / 2, ba.y + ba.height / 2);
  await page.keyboard.down('Shift'); await page.mouse.click(bb.x + bb.width / 2, bb.y + bb.height / 2); await page.keyboard.up('Shift');
  const s7 = await ev(() => steps());
  await page.mouse.move(ba.x + ba.width / 2, ba.y + ba.height / 2); await page.mouse.down();
  await page.keyboard.down('Control');   // free of snapping (held after the press)
  await page.mouse.move(ba.x + ba.width / 2 + 2 * g.pps, ba.y + ba.height / 2, { steps: 8 }); await page.mouse.up();
  await page.keyboard.up('Control'); await settle();
  r = await ev((ids) => ({ t: ids.map((id) => P.findItem(ED.project, id).item.t0), n: ED.sel.size, s: steps() }), two);
  ok(r.n === 2 && Math.abs((r.t[1] - r.t[0]) - 1.5) < 1e-3 && Math.abs(r.t[0] - 14) < 0.05 && r.s - s7 === 1, `multi-select (Shift-click) drags both together, keeping their spacing (${r.t.map((x) => x.toFixed(2)).join(', ')} s), in one undo step`);
  // keyboard nudge: comma / period one frame, Shift ten
  r = await ev((ids) => { const t0 = P.findItem(ED.project, ids[0]).item.t0; return t0; }, two);
  await page.keyboard.press('Period'); await page.keyboard.press('Shift+Period'); await page.keyboard.press('Comma');
  const t7 = await ev((ids) => P.findItem(ED.project, ids[0]).item.t0, two);
  ok(Math.abs(t7 - (Math.round((r + 10 / 30) * 30) / 30)) < 1e-3, `, and . nudge the selection by one frame, Shift by ten (${r.toFixed(3)} → ${t7.toFixed(3)} s)`);

  // ── 8. linked audio ──
  r = await ev(() => {
    const v = vt(), c = v[0]; ED.select([c.id]);
    const s0 = steps();
    TD.unlink(ED);
    const snd = ED.project.tracks.flatMap((t) => t.items).find((i) => i.unlinkedFrom === c.id);
    const sm = snd && P.findMedia(ED.project, snd.media);
    const res = { muted: P.findItem(ED.project, c.id).item.muted, snd: snd && [snd.t0, snd.in, snd.out], clip: [c.t0, c.in, c.out], kind: sm?.kind, src: sm?.src === P.findMedia(ED.project, c.media).src, track: snd && P.findItem(ED.project, snd.id).track.name, steps: steps() - s0 };
    // the clip moves; its unlinked sound stays
    ED.apply(ED.ops.moveItems([{ id: c.id, t0: c.t0 + 0.5 }])); res.after = P.findItem(ED.project, snd.id).item.t0;
    ED.history.undo(); ED.history.undo();
    res.back = !ED.project.tracks.flatMap((t) => t.items).some((i) => i.unlinkedFrom === c.id) && !P.findItem(ED.project, c.id).item.muted;
    return res;
  });
  ok(r.muted && JSON.stringify(r.snd) === JSON.stringify(r.clip) && r.kind === 'audio' && r.src && r.track === 'Voice' && r.steps === 1, `🔊 Unlink: the clip's own sound becomes an item on a "Voice" track (same range ${JSON.stringify(r.snd)}, its own audio element), the clip goes silent — one undo step`);
  ok(r.after === r.snd[0] && r.back, 'after unlinking, moving the clip leaves its sound where it is; undo links them again');

  // ── 9. one undo step per edit (every op above was counted; here the UI paths) ──
  r = await ev(() => {
    const out = {}; const v = vt();
    const one = (name, fn) => { const s0 = steps(); fn(); out[name] = steps() - s0; };
    one('split', () => { ED.select([]); ED.seek(v[2].t0 + 1); ED.split(); });
    one('ripple', () => { TD.setMarks({ in: 1, out: 1.5 }); TD.deleteRange(ED); });
    one('lift', () => { TD.setMarks({ in: 1, out: 1.5 }); TD.deleteRange(ED, true); });
    one('join', () => { const vv = vt(); const a = vv.find((c) => Math.abs(P.itemEnd(c) - (v[2].t0 + 0.5)) < 0.02) || vv[2]; ED.select([a.id]); TD.join(ED); });
    return out;
  });
  ok(Object.values(r).every((n) => n === 1), `one undo step per edit: ${JSON.stringify(r)}`);

  // ── 9b. ramped, reversed and frozen clips (core/retime.js): split, ripple delete, move, captions ──
  r = await ev(async () => {
    const RT = await import('/app/core/retime.js');
    while (ED.history.canUndo) ED.history.undo();
    const wide = ED.project.tracks.flatMap((t) => t.items).filter((i) => i.media && P.findMedia(ED.project, i.media).name !== 'portrait.mp4').map((i) => i.id);
    if (wide.length) ED.apply(ED.ops.removeItems(wide));
    const out = {}, c0 = () => vt()[0];
    // the timeline maps through the WHOLE clip before an edit; after it, the pieces must map every frame the same way
    const mapErr = (whole, ts, shift = (t) => t) => Math.max(...ts.map((t) => { const pc = vt().find((c) => t >= c.t0 - 1e-9 && t < P.itemEnd(c) - 1e-9); return pc ? Math.abs(P.srcTime(pc, t) - P.srcTime(whole, shift(t))) : 99; }));
    const sample = (a, b, n = 40) => Array.from({ length: n }, (_, k) => a + (b - a) * (k + 0.5) / n);
    for (const kind of ['ramp', 'reverse', 'freeze']) {
      const props = kind === 'ramp' ? RT.curveProps(c0(), RT.PRESETS['slowmo-hit'].pts, 'slowmo-hit') : kind === 'reverse' ? { reverse: true } : { freeze: true, out: c0().in + 6 };
      ED.apply(ED.ops.setItem(c0().id, props, 'Retime ' + kind));
      const whole = structuredClone(c0()), D = P.itemDur(whole), w0 = new Map(ED.project.captions.words.map((w) => [w.w, w.t0]));
      // split at an arbitrary frame
      ED.select([whole.id]); ED.seek(Math.round(D * 0.37 * 30) / 30); ED.split();
      const splitErr = mapErr(whole, sample(0, D)), n = vt().length, wSame = ED.project.captions.words.every((w) => Math.abs(w.t0 - w0.get(w.w)) < 2e-3);
      ED.history.undo();
      // ripple delete a middle chunk: everything after it plays what played d seconds later
      const a = Math.round(D * 0.3 * 30) / 30, b = Math.round(D * 0.5 * 30) / 30, d = b - a;
      ED.apply(EO.rangeCmd(ED.project, a, b, { ripple: true }));
      const rippleErr = Math.max(mapErr(whole, sample(0, a)), mapErr(whole, sample(a, D - d), (t) => t + d));
      // the captions after the cut moved by exactly −d; those inside it are gone
      const w1 = new Map(ED.project.captions.words.map((w) => [w.w, w.t0]));
      const capErr = kind === 'freeze' ? 0 : Math.max(0, ...[...w0].filter(([, t]) => t >= b + 0.05 && t < D).map(([w, t]) => (w1.has(w) ? Math.abs(w1.get(w) - (t - d)) : 9)));
      const capGone = kind === 'freeze' ? true : [...w0].filter(([, t]) => t > a + 0.05 && t < b - 0.4).every(([w]) => !w1.has(w));
      ED.history.undo();
      // move it later (insert after nothing: a free spot at 3 s): the mapping and the captions shift by 3 s
      ED.apply(ED.ops.moveItems([{ id: whole.id, t0: 3 }]));
      const moveErr = mapErr(whole, sample(3, 3 + D), (t) => t - 3);
      const w2 = new Map(ED.project.captions.words.map((w) => [w.w, w.t0]));
      const capMove = kind === 'freeze' ? 0 : Math.max(0, ...[...w0].filter(([, t]) => t < D - 0.1).map(([w, t]) => (w2.has(w) ? Math.abs(w2.get(w) - (t + 3)) : 9)));
      ED.history.undo(); ED.history.undo();
      out[kind] = { n, splitErr, wSame, rippleErr, capErr, capGone, moveErr, capMove };
    }
    return out;
  });
  for (const [k, x] of Object.entries(r)) ok(x.n === 2 && x.splitErr < 1e-6 && x.wSame && x.rippleErr < 1e-6 && x.capErr < 2e-3 && x.capGone && x.moveErr < 1e-6 && x.capMove < 2e-3, `${k} clip: a split at a frame keeps every frame's source (max error ${x.splitErr.toExponential(1)} s) and the captions; a ripple delete maps the rest exactly (${x.rippleErr.toExponential(1)} s) with its words gone and the later ones shifted (${(x.capErr * 1000).toFixed(2)} ms); a move carries clip and captions (${(x.capMove * 1000).toFixed(2)} ms)`);

  // ── 10. export = preview after the edits ──
  r = await ev(async () => {
    // a clean state: undo everything, then one ripple cut (source 4–6 s) and a blade cut
    while (ED.history.canUndo) ED.history.undo();
    const wide = ED.project.tracks.flatMap((t) => t.items).filter((i) => i.media && P.findMedia(ED.project, i.media).name === 'wide.mp4').map((i) => i.id);
    if (wide.length) ED.apply(ED.ops.removeItems(wide));
    ED.apply(EO.rangeCmd(ED.project, 4, 6, { ripple: true }));
    ED.seek(5); ED.select([]); ED.split();
    const E = await import('/app/core/export.js');
    const res = await E.exportMp4(ED.project, ED.media, { quality: 'high' });
    const file = `workspaces/${ED.project.workspace}/exports/edit-check.mp4`; await ED.engine.writeFile(file, res.blob);
    return { file, dur: ED.duration(), frames: res.frames };
  });
  const xfile = path.join(dir, r.file);
  const xdur = +execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', xfile]).toString().trim();
  ok(Math.abs(xdur - r.dur) < 0.1, `the export is as long as the edited reel (${xdur.toFixed(2)} s vs ${r.dur.toFixed(2)} s: the caption layer shrank with the ripple)`);
  for (const t of [3.5, 4.5]) {
    const png = path.join(OUT, `x-${t}.png`);
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-ss', String(t), '-i', xfile, '-frames:v', '1', '-vf', 'scale=270:480', png]);
    const diff = await ev(async ({ b64, t }) => {
      const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
      const R = await import('/app/core/render.js'); const p = ED.project, m = p.media[0];
      const v = document.createElement('video'); v.muted = true; v.src = ED.media.url(m); await new Promise((r) => (v.onloadeddata = r));
      const clip = p.tracks.find((x) => x.kind === 'video').items.find((c) => t >= c.t0 && t < P.itemEnd(c));
      v.currentTime = P.srcTime(clip, t); await new Promise((r) => (v.onseeked = r));
      await R.prepareRender?.(p);
      const a = new OffscreenCanvas(270, 480), c2 = new OffscreenCanvas(270, 480);
      R.renderFrame(a.getContext('2d'), p, t, { scale: 270 / p.canvas.w, source: (it) => (it.media === m.id ? v : null) });
      c2.getContext('2d').drawImage(img, 0, 0, 270, 480);
      const A = a.getContext('2d').getImageData(0, 0, 270, 480).data, B = c2.getContext('2d').getImageData(0, 0, 270, 480).data;
      let s = 0; for (let i = 0; i < A.length; i += 4) s += Math.abs(A[i] - B[i]) + Math.abs(A[i + 1] - B[i + 1]) + Math.abs(A[i + 2] - B[i + 2]);
      return s / (A.length / 4) / 3;
    }, { b64: fs.readFileSync(png).toString('base64'), t });
    ok(diff < 12, `export = preview after a ripple cut + split: frame at ${t} s differs by ${diff.toFixed(1)}/255 (captions included)`);
  }

  // ── 11. save / reload keeps everything ──
  r = await ev(async () => { await ED.save(); return JSON.stringify({ t: ED.project.tracks, c: ED.project.captions, m: ED.project.markers, me: ED.project.media }); });
  await page.reload(); await page.waitForSelector('.tl-item'); await boot();
  const re = await ev(() => JSON.stringify({ t: ED.project.tracks, c: ED.project.captions, m: ED.project.markers, me: ED.project.media }));
  ok(re === r, `save → reload: tracks, captions (anchors, offcut words, lines, positions), markers and media come back identical (${r.length} bytes)`);

  // ── 12. an old project (no anchors, a transition, text, a marker) opens unchanged and follows edits ──
  const legacy = JSON.parse(fs.readFileSync(path.join(ROOT, 'tools', 'fixtures', 'edit-legacy-project.json'), 'utf8'));
  await ev(async (lp) => { await ED.engine.saveProject(lp); }, legacy);
  await page.goto(e.url + `/app/#/p/${legacy.workspace}/${legacy.id}`); await page.reload();
  await page.waitForFunction((id) => window.kbbEditor?.project.id === id, legacy.id); await boot();
  r = await ev(async (lp) => {
    const norm = P.normalizeProject(lp), p = ED.project;
    const same = JSON.stringify({ t: p.tracks, c: p.captions, m: p.markers }) === JSON.stringify({ t: norm.tracks, c: norm.captions, m: norm.markers });
    const s0 = { text: P.findItem(p, 'itext').item.t0, tr: P.findItem(p, 'itrans').item.t0, w: p.captions.words.map((w) => w.t0) };
    ED.apply(EO.rangeCmd(p, 1, 2, { ripple: true }));
    const s1 = { text: P.findItem(p, 'itext').item.t0, tr: P.findItem(p, 'itrans').item.t0, w: p.captions.words.map((w) => [w.w, w.t0]), mk: p.markers[0].t, pos: p.captions.lines.find((L) => L.id === 'cl3')?.ov?.pos };
    ED.history.undo();
    const s2 = JSON.stringify({ t: p.tracks, c: p.captions }) === JSON.stringify({ t: norm.tracks, c: norm.captions });
    return { same, steps: ED.history.undoStack.length, s0, s1, s2 };
  }, legacy);
  ok(r.same, 'the old project (made before edit-ops) opens unchanged: tracks, captions and markers byte-identical to the file');
  ok(Math.abs(r.s1.text - 8) < 1e-3 && Math.abs(r.s1.tr - 6.75) < 1e-3 && r.s1.mk === 12, `an edit on it: its text (9 → ${r.s1.text} s) and transition (7.75 → ${r.s1.tr} s) follow the clips, the marker stays (${r.s1.mk} s)`);
  ok(r.s1.w.map(([w]) => w).join(' ') === 'one four five six seven eight nine ten' && Math.abs(r.s1.w.find(([w]) => w === 'seven')?.[1] - 7.5) < 1e-3 && JSON.stringify(r.s1.pos) === '{"x":0.5,"y":0.3}', `its un-anchored words follow too ("two three" cut out, "seven" 8.5 → 7.5 s), its line position is kept: ${JSON.stringify(r.s1.w)} ${JSON.stringify(r.s1.pos)}`);
  ok(r.s2, 'undo returns the old project to exactly what was on disk');

  // ── 13. drag frame time with 200+ items ──
  r = await ev(async () => {
    const p = ED.project, cmds = [];
    const tracks = []; for (let k = 0; k < 4; k++) { const t = P.createTrack('overlay', 'Load ' + k); tracks.push(t); cmds.push(ED.ops.addTrack(t, 2)); }
    const items = [];
    for (let i = 0; i < 200; i++) items.push({ trackId: tracks[i % 4].id, item: P.createItem('sticker', { t0: (i >> 2) * 1.3, in: 0, out: 1.2, text: '⭐', name: 's' + i }) });
    cmds.push(ED.ops.addItems(items, 'Load'));
    ED.apply(ED.ops.batch('Load', cmds));
    ED.timeline.fit();
    const n = p.tracks.reduce((a, t) => a + t.items.length, 0);
    // the old way, for comparison: a project change per pointer move re-renders the whole timeline
    const it0 = items[0].item; const T0 = performance.now();
    for (let k = 1; k <= 10; k++) ED.apply(ED.ops.moveItems([{ id: it0.id, t0: k * 0.01 }], 'probe'));
    const oldMs = (performance.now() - T0) / 10;
    ED.history.undo();
    return { n, oldMs };
  });
  await settle();
  const nItems = r.n, oldMs = r.oldMs;
  // frame times measured in the page (no DevTools round-trip per move): idle, then a drag driven one move per frame
  const sb = await page.locator('.tl-item.t-sticker').nth(40).boundingBox();
  r = await ev(async ({ x, y }) => {
    const frames = (n, onFrame) => new Promise((res) => { const out = []; let last = performance.now(), k = 0; const loop = (t) => { out.push(t - last); last = t; onFrame?.(k); if (++k < n) requestAnimationFrame(loop); else res(out.slice(2)); }; requestAnimationFrame(loop); });
    const stat = (f) => { const s = f.slice().sort((a, b) => a - b); return { p50: s[Math.floor(s.length * 0.5)], p95: s[Math.floor(s.length * 0.95)], max: s[s.length - 1], n: s.length }; };
    const idle = stat(await frames(90));
    const el = document.elementFromPoint(x, y), opt = { pointerId: 7, bubbles: true, button: 0, buttons: 1, isPrimary: true };
    el.dispatchEvent(new PointerEvent('pointerdown', { ...opt, clientX: x, clientY: y }));
    const work = [];
    const drag = stat(await frames(150, (k) => { const t0 = performance.now(); window.dispatchEvent(new PointerEvent('pointermove', { ...opt, clientX: x + k * 4, clientY: y + Math.sin(k / 6) * 30 })); work.push(performance.now() - t0); }));
    const s0 = ED.history.undoStack.length;
    window.dispatchEvent(new PointerEvent('pointerup', { ...opt, clientX: x + 600, clientY: y }));
    return { idle, drag, work: stat(work), steps: ED.history.undoStack.length - s0, moved: !!document.querySelector('.tl-item.dragging') };
  }, { x: sb.x + sb.width / 2, y: sb.y + sb.height / 2 });
  ok(nItems >= 200 && r.drag.p95 <= Math.max(18, r.idle.p95 + 2) && r.steps === 1, `drag with ${nItems} items on the timeline, one move per frame: frame time p50 ${r.drag.p50.toFixed(1)} / p95 ${r.drag.p95.toFixed(1)} / max ${r.drag.max.toFixed(1)} ms over ${r.drag.n} frames (idle p95 ${r.idle.p95.toFixed(1)} ms); pointer handler ${r.work.p95.toFixed(2)} ms p95; one undo step on drop. Before: every pointer move changed the project and re-rendered the whole timeline (${oldMs.toFixed(1)} ms each)`);

  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 5).join(' | ') : ''));
  ok(outside.length === 0, 'no request left 127.0.0.1' + (outside.length ? ': ' + outside.slice(0, 3).join(' ') : ''));
  await browser.close(); await H.stopEngine(e);
  fs.rmSync(dir, { recursive: true, force: true });
  fs.writeFileSync(path.join(OUT, 'results.txt'), results.join('\n') + '\n');
  console.log(failures ? `\n${failures} FAILED` : '\nall edit checks passed');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
