// KBB Studio · automation → project commands. Every result is ONE undoable step (core/history.js ops.batch),
// so the owner reviews it on the timeline and Ctrl+Z takes it all back.
import { ops } from '../core/history.js';
import { createItem, uid, tracksOfKind, findMedia, fitBox, itemEnd, clone } from '../core/project.js';
import { keepRanges } from './lib/plan.js';
import { duckKeys } from './lib/music.js';
import { cropToItemKf } from './lib/video.js';

const trackOf = (p, kind) => tracksOfKind(p, kind)[0];
const AUTO = 'auto';   // item.auto = 'auto' marks what automation placed (so a re-run replaces it, never the owner's work)

/** media entry for a library file (added once per project) */
export function libraryMedia(p, src, kind, dur, name) {
  const have = p.media.find(m => m.src === src);
  if (have) return { media: have, cmd: null };
  const media = { id: uid('m'), kind, name: name || src.split('/').pop(), src, dur, w: 0, h: 0, fps: 0, hasAudio: true, size: 0 };
  return { media, cmd: ops.addMedia(media, 'Library') };
}
/** words mapped onto new timeline through [{in,out,t0}] segments; lines rebuilt per segment */
export function remapCaptions(captions, segs) {
  const words = [], lines = []; const src = captions?.words || [];
  const oldLine = new Map(); (captions?.lines || []).forEach(l => { for (let i = l.wordIdx[0]; i <= l.wordIdx[1]; i++) oldLine.set(i, l.id); });
  for (const s of segs) {
    let cur = null;
    src.forEach((w, i) => {
      if (w.t0 < s.in - 0.02 || w.t1 > s.out + 0.02) return;
      const nw = { ...w, t0: +(s.t0 + w.t0 - s.in).toFixed(3), t1: +(s.t0 + Math.min(w.t1, s.out) - s.in).toFixed(3) };
      words.push(nw); const ni = words.length - 1, lid = oldLine.get(i) ?? null;
      if (cur && cur.lid === lid && lid != null) { cur.line.wordIdx[1] = ni; cur.line.t1 = nw.t1; }
      else { const line = { id: uid('l'), t0: nw.t0, t1: nw.t1, wordIdx: [ni, ni] }; lines.push(line); cur = { lid, line }; }
    });
  }
  return { ...(captions || {}), words, lines };
}
const removeAuto = (p, kinds) => { const ids = []; for (const tr of p.tracks) if (!kinds || kinds.includes(tr.kind)) for (const it of tr.items) if (it.auto === AUTO) ids.push(it.id); return ids.length ? ops.removeItems(ids, 'Remove auto items') : null; };

/** kit fields copied into items (layers draw from item data only) */
export const kitForItems = (kit) => kit && ({ name: kit.name, fullName: kit.fullName, colors: kit.colors, gradient: kit.gradient, fonts: kit.fonts && Object.fromEntries(Object.entries(kit.fonts).map(([k, f]) => [k, { family: f.family, fallback: f.fallback }])), markPaths: kit.logos?.markPaths });

/**
 * Auto reel → one command.
 * a = { mediaId, plan:{segments, endCard}, reframe?:crop keyframes (source px), music?:{track, fit, srcBase}, speechTimeline:[[a,b]],
 *       effects?:planEffects() result, kit, kitBase:'brands/kbb/', recipe, endCardText:{title,sub,cta,contact,payments} , sfxLib }
 */
export function autoReelCommand(p, a) {
  const cmds = [], canvas = p.canvas, m = findMedia(p, a.mediaId);
  const vt = trackOf(p, 'video'), ot = trackOf(p, 'overlay'), mt = trackOf(p, 'music'), st = trackOf(p, 'sfx');
  // 1. replace the video track's clips of this media + earlier auto items
  const old = vt.items.filter(i => i.media === a.mediaId || i.auto === AUTO).map(i => i.id);
  if (old.length) cmds.push(ops.removeItems(old));
  cmds.push(removeAuto(p, ['overlay', 'music', 'sfx']));
  const box = fitBox(canvas, m?.w || canvas.w, m?.h || canvas.h, 'cover');
  const punches = a.effects?.punches || [];
  const clipItems = a.plan.segments.map(s => {
    const dur = s.out - s.in;
    const it = createItem('clip', { media: a.mediaId, t0: s.t0, in: s.in, out: s.out, ...box, auto: AUTO, name: s.role === 'hook' ? 'Hook' : s.role === 'cta' ? 'Call to action' : 'Highlight',
      fx: punches.filter(x => x.t0 >= s.t0 && x.t0 < s.t0 + dur).map(x => ({ type: 'auto.punch', t0: x.t0, dur: x.dur, amount: x.amount })) });
    if (a.reframe && m?.w) { const kf = cropToItemKf(a.reframe.filter(c => c.t >= s.in - 0.5 && c.t <= s.out + 0.5), { w: m.w, h: m.h }, canvas, 0, s.in); if (kf.x.length) { it.kf = kf; Object.assign(it, { x: kf.x[0][1], y: kf.y[0][1], w: kf.w[0][1], h: kf.h[0][1] }); } }
    return it;
  });
  cmds.push(ops.addItems(clipItems.map(item => ({ trackId: vt.id, item })), 'Auto reel clips'));
  // 2. captions follow the cut
  if (p.captions?.words?.length) cmds.push(ops.setCaptions(remapCaptions(p.captions, a.plan.segments)));
  // 3. end card
  const kitI = kitForItems(a.kit);
  if (a.plan.endCard) cmds.push(ops.addItems([{ trackId: ot.id, item: createItem('auto.endcard', { t0: a.plan.endCard.t0, in: 0, out: a.plan.endCard.dur, x: 0, y: 0, w: canvas.w, h: canvas.h, auto: AUTO, name: 'End card', fadeOut: 0, style: { kit: kitI, ...a.endCardText, payments: (a.endCardText?.payments || []) } }) }]));
  // 4. effects
  cmds.push(...effectsCommands(p, a.effects, { kit: a.kit, kitI, sfxLib: a.sfxLib, ot, st }));
  // 5. music
  if (a.music) cmds.push(...musicCommands(p, a.music, a.speechTimeline || []));
  return ops.batch('Auto reel', cmds.filter(Boolean));
}

export function effectsCommands(p, ev, { kitI, sfxLib, ot = trackOf(p, 'overlay'), st = trackOf(p, 'sfx') } = {}) {
  if (!ev) return [];
  const cmds = [], canvas = p.canvas, add = [];
  for (const tr of ev.transitions || []) add.push({ trackId: ot.id, item: createItem('auto.transition', { t0: +(tr.t - tr.dur / 2).toFixed(3), in: 0, out: tr.dur, x: 0, y: 0, w: canvas.w, h: canvas.h, auto: AUTO, name: 'Transition · ' + tr.kind, style: { kind: tr.kind, color: tr.kind === 'slide' || tr.kind === 'wipe' ? kitI?.colors?.primary : '#ffffff', accent: kitI?.colors?.accent } }) });
  for (const s of ev.stickers || []) {
    const sz = s.size, cx = s.x + sz / 2, cy = s.y + sz / 2, k = [[0, .2], [.16, 1.18], [.28, 1], [s.dur - .18, 1], [s.dur, .1]];
    add.push({ trackId: ot.id, item: createItem('sticker', { t0: s.t0, in: 0, out: s.dur, x: s.x, y: s.y, w: sz, h: sz, text: s.text, auto: AUTO, name: 'Emoji ' + s.text,
      kf: { w: k.map(([t, v]) => [t, sz * v, 'out']), h: k.map(([t, v]) => [t, sz * v, 'out']), x: k.map(([t, v]) => [t, cx - sz * v / 2, 'out']), y: k.map(([t, v]) => [t, cy - sz * v / 2, 'out']), rot: [[0, -14, 'out'], [.3, 6, 'inout'], [.6, 0, 'inout']] } }) });
  }
  if (ev.priceTag) { const w = Math.round(canvas.w * 0.58), h = Math.round(w * 0.36); add.push({ trackId: ot.id, item: createItem('auto.pricetag', { t0: ev.priceTag.t0, in: 0, out: ev.priceTag.dur, x: canvas.w - w - Math.round(canvas.w * 0.06), y: Math.round(canvas.h * 0.16), w, h, auto: AUTO, name: 'Price tag', style: { price: ev.priceTag.price, label: ev.priceTag.label, colors: kitI?.colors, kit: kitI } }) }); }
  if (ev.logoIntro) add.push({ trackId: ot.id, item: createItem('auto.logo', { t0: ev.logoIntro.t0, in: 0, out: ev.logoIntro.dur, x: 0, y: 0, w: canvas.w, h: canvas.h, auto: AUTO, name: 'Logo intro', style: { mode: 'intro', kit: kitI } }) });
  if (ev.logoOutro) add.push({ trackId: ot.id, item: createItem('auto.logo', { t0: ev.logoOutro.t0, in: 0, out: ev.logoOutro.dur, x: 0, y: 0, w: canvas.w, h: canvas.h, auto: AUTO, name: 'Logo outro', style: { mode: 'outro', kit: kitI } }) });
  if (add.length) cmds.push(ops.addItems(add, 'Auto effects'));
  // SFX: one media entry per sound, items timed so the sound's own hit lands on the cut
  const sfxAdds = [];
  for (const s of ev.sfx || []) {
    const lib = (sfxLib || []).find(x => x.id === s.id); if (!lib) continue;
    const { media, cmd } = libraryMedia(p, 'library/sfx/' + lib.file, 'audio', lib.duration, lib.id); if (cmd && !cmds.includes(cmd)) { cmds.push(cmd); p = { ...p, media: [...p.media, media] }; }
    const t0 = Math.max(0, s.align === 'hit' ? s.t - lib.hitAt : s.t);
    sfxAdds.push({ trackId: st.id, item: createItem('audio', { media: media.id, t0: +t0.toFixed(3), in: 0, out: lib.duration, auto: AUTO, name: 'SFX · ' + lib.id, volume: s.role === 'transition' ? 0.55 : 0.7 }) });
  }
  if (sfxAdds.length) cmds.push(ops.addItems(sfxAdds, 'Sound effects'));
  return cmds;
}

/** music = { track (library entry), fit (fitTrack result), srcBase:'library/music/' } */
export function musicCommands(p, music, speechTimeline) {
  const mt = trackOf(p, 'music'), cmds = [];
  const old = mt.items.filter(i => i.auto === AUTO).map(i => i.id); if (old.length) cmds.push(ops.removeItems(old));
  const { media, cmd } = libraryMedia(p, (music.srcBase || 'library/music/') + music.track.file, 'audio', music.track.duration, music.track.title);
  if (cmd) cmds.push(cmd);
  const items = music.fit.items.map((it, k) => { const item = createItem('audio', { media: media.id, t0: it.t0, in: it.in, out: it.out, fadeIn: it.fadeIn, fadeOut: it.fadeOut, volume: music.volume ?? 0.8, auto: AUTO, name: `Music · ${music.track.title}${music.fit.items.length > 1 ? ' ' + (k + 1) : ''}` }); item.kf = { volume: duckKeys(speechTimeline, it, { base: music.volume ?? 0.8, duck: (music.volume ?? 0.8) * 0.3 }) }; return item; });
  cmds.push(ops.addItems(items.map(item => ({ trackId: mt.id, item })), 'Music'));
  return cmds;
}
export const addMusicCommand = (p, music, speech) => ops.batch('Add music', musicCommands(p, music, speech));

/**
 * Remove silences / fillers: cuts on the SOURCE time of media `mediaId`. Each clip of that media is split into its kept
 * ranges laid back-to-back; everything later on every track ripples left by the time removed before it.
 */
export function removeCutsCommand(p, mediaId, cuts) {
  const vt = trackOf(p, 'video'), m = findMedia(p, mediaId), dur = m?.dur || Math.max(...cuts.map(c => c.t1));
  const keep = keepRanges(dur, cuts);
  const clips = vt.items.filter(i => i.media === mediaId).sort((a, b) => a.t0 - b.t0);
  if (!clips.length) return null;
  const removedAt = []; // [timelineT, removedSeconds] in old-timeline terms, for rippling
  const adds = [], segs = [];
  for (const c of clips) {
    let t = c.t0; const sp = c.speed || 1;
    const parts = keep.map(([a, b]) => [Math.max(a, c.in), Math.min(b, c.out)]).filter(([a, b]) => b - a > 0.04);
    let prevEnd = c.in;
    for (const [a, b] of parts) {
      if (a > prevEnd) removedAt.push([c.t0 + (prevEnd - c.in) / sp, (a - prevEnd) / sp]);
      const it = clone(c); it.id = uid('i'); it.in = a; it.out = b; it.t0 = +t.toFixed(4);
      if (it.kf) for (const k of Object.keys(it.kf)) it.kf[k] = it.kf[k].map(([kt, v, e]) => [Math.max(0, kt - (a - c.in) / sp), v, e]);
      adds.push({ trackId: vt.id, item: it }); segs.push({ in: a, out: b, t0: it.t0 }); t += (b - a) / sp; prevEnd = b;
    }
    if (c.out > prevEnd) removedAt.push([c.t0 + (prevEnd - c.in) / sp, (c.out - prevEnd) / sp]);
  }
  const shift = (t) => { let s = 0; for (const [at, d] of removedAt) if (at < t - 1e-6) s += Math.min(d, t - at); return s; };
  const moves = [];
  for (const tr of p.tracks) for (const it of tr.items) if (!(tr.id === vt.id && it.media === mediaId)) { const d = shift(it.t0); if (d > 1e-4) moves.push({ id: it.id, t0: +(it.t0 - d).toFixed(4) }); }
  const cmds = [ops.removeItems(clips.map(c => c.id)), ops.addItems(adds, 'Kept parts')];
  if (moves.length) cmds.push(ops.moveItems(moves, 'Ripple'));
  if (p.captions?.words?.length) cmds.push(ops.setCaptions(remapCaptions(p.captions, segs)));
  const removed = removedAt.reduce((a, b) => a + b[1], 0);
  const cmd = ops.batch(`Remove silences (−${removed.toFixed(1)} s)`, cmds); cmd.removed = removed; cmd.keep = keep;
  return cmd;
}

/** speech ranges of `mediaId` mapped onto the project timeline through its clips */
export function speechOnTimeline(p, mediaId, speech) {
  const out = [];
  for (const tr of p.tracks) if (tr.kind === 'video') for (const c of tr.items) if (c.media === mediaId) for (const [a, b] of speech) {
    const s = Math.max(a, c.in), e = Math.min(b, c.out); if (e > s) out.push([c.t0 + (s - c.in) / (c.speed || 1), c.t0 + (e - c.in) / (c.speed || 1)]);
  }
  return out.sort((x, y) => x[0] - y[0]);
}
export { itemEnd };
