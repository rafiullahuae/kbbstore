// KBB Studio · viral · project edits. Every function here edits a project IN PLACE and is wrapped by index.js in ONE
// ops.mutate step (so one ⌘Z takes a whole feature back) — or run on a clone for the A/B variations.
// Everything a feature places carries item.viral = '<feature>' (fx entries too), so pressing it again REPLACES its
// earlier result and never touches the owner's own work.
import { createItem, createTrack, uid, clone, itemDur, itemEnd, findMedia, projectDuration, VISUAL_KINDS } from '../core/project.js';
import { buildLines } from '../captions/model.js';
import { cutRanges, insertGap, mainClips, clipAt } from './lib/timeline.js';
import { nudge as nudgeBox } from './lib/safezones.js';
import { applyFixes } from './lib/claims.js';

const r3 = (x) => +(+x).toFixed(3);
export const isVertical = (c) => c.h / c.w > 1.3;

// ── helpers ──
/** first unlocked track of a kind (created when missing). top: a visual track drawn above every other one */
export function trackOf(p, kind, { top = false, name } = {}) {
  let tr = top ? p.tracks.find((t) => t.viralTop && t.kind === kind) : p.tracks.find((t) => t.kind === kind && !t.locked);
  if (tr) return tr;
  tr = createTrack(kind, name);
  if (top) tr.viralTop = true;
  let at = p.tracks.length;
  if (VISUAL_KINDS.has(kind)) {
    if (top) { const lastVis = p.tracks.map((t) => VISUAL_KINDS.has(t.kind)).lastIndexOf(true); at = lastVis + 1; }
    else { const last = p.tracks.map((t) => t.kind).lastIndexOf(kind); at = last >= 0 ? last + 1 : Math.max(0, p.tracks.findIndex((t) => !VISUAL_KINDS.has(t.kind))); }
  }
  p.tracks.splice(at, 0, tr);
  return tr;
}
/** media entry for a library file (added once per project) -> id */
export function libMedia(p, src, dur, name, kind = 'audio') {
  const have = p.media.find((m) => m.src === src); if (have) return have.id;
  const m = { id: uid('m'), kind, name: name || src.split('/').pop(), src, dur, w: 0, h: 0, fps: 0, hasAudio: kind !== 'image', size: 0 };
  p.media.push(m); return m.id;
}
/** remove what a feature placed earlier (items and fx entries) */
export function removeViral(p, feats) {
  const set = new Set(feats);
  for (const tr of p.tracks) {
    tr.items = tr.items.filter((i) => !set.has(i.viral));
    for (const it of tr.items) if (it.fx?.some((f) => set.has(f.viral))) it.fx = it.fx.filter((f) => !set.has(f.viral));
  }
  p.tracks = p.tracks.filter((t) => !(t.viralTop && !t.items.length));
}
/** a sound from a library entry, placed so its hit lands on `t` */
export function addSound(p, entry, t, { feat, volume = 0.8, base = 'library/sfx/hooks/' } = {}) {
  if (!entry) return null;
  const id = libMedia(p, base + entry.file, entry.duration, entry.title || entry.id);
  const it = createItem('audio', { media: id, t0: r3(Math.max(0, t - (entry.hitAt || 0))), in: 0, out: entry.duration, volume, name: 'Hook sound · ' + (entry.title || entry.id), viral: feat });
  trackOf(p, 'sfx').items.push(it);
  return it;
}
export function colorsOf(kit) {
  const c = kit?.colors || {};
  return { primary: c.primary || '#e8508b', accent: c.accent || '#ffd166', text: '#ffffff', ink: c.ink || '#14141a', onPrimary: '#ffffff', card: c.soft ? '#ffffff' : '#ffffff' };
}
export const fontsOf = (kit) => ({ font: kit?.fonts?.heading?.family || 'Montserrat', fontAr: kit?.fonts?.arabic?.family || 'Cairo' });
/** the box hook text sits in (top band, clear of every platform's UI) */
export function hookBox(c, small = false) {
  if (!isVertical(c)) return { x: Math.round(c.w * 0.08), y: Math.round(c.h * 0.08), w: Math.round(c.w * 0.84), h: Math.round(c.h * (small ? 0.18 : 0.3)) };
  return { x: Math.round(c.w * 0.07), y: Math.round(c.h * 0.115), w: Math.round(c.w * 0.76), h: Math.round(c.h * (small ? 0.1 : 0.19)) };
}
const pushFx = (clip, fx) => { clip.fx = [...(clip.fx || []), fx]; };

// ── 1. hook builder ──
/** opts = { line:{text, accent, lang}, kit, dur=2, punch=true, face:{x,y}, sound (hook library entry), anim } */
export function editHookText(p, o) {
  removeViral(p, ['hook-text', 'hook-punch', 'hook-sfx']);
  const dur = o.dur ?? 2, c = p.canvas;
  trackOf(p, 'text').items.push(createItem('viral.hook', { t0: 0, in: 0, out: dur, ...hookBox(c), name: 'Hook · ' + o.line.text.slice(0, 30), viral: 'hook-text',
    style: { text: o.line.text, accent: o.line.accent || '', lang: o.line.lang, colors: colorsOf(o.kit), ...fontsOf(o.kit), anim: o.anim || 'pop' } }));
  const first = clipAt(p, 0);
  if (o.punch !== false && first) pushFx(first, { type: 'viral.zoom', t0: 0, dur: Math.min(dur, itemEnd(first)), from: 1, to: 1.14, attack: 0.12, release: 0.35, cx: o.face?.x ?? 0.5, cy: o.face?.y ?? 0.42, viral: 'hook-punch' });
  if (o.sound) addSound(p, o.sound, 0.06, { feat: 'hook-sfx', volume: 0.75 });
  p.meta.viral = { ...(p.meta.viral || {}), hook: { text: o.line.text, kind: o.line.kind || null } };
}

/** first-frame fix: trim the lead-in to `trimTo` (s); or, when the black/blur runs under the first words, show the first
 *  good frame (`cover` = {still, until}) over it so the sound is untouched; or lift the colour of the first `vivid` s */
export function editFirstFrame(p, { trimTo = 0, vivid = 0, cover = null } = {}) {
  if (trimTo > 0.02) cutRanges(p, [{ a: 0, b: trimTo }], { fade: 0.012 });
  if (cover?.still) {
    removeViral(p, ['first-frame']);
    if (!p.media.some((m) => m.id === cover.still.id)) p.media.push(cover.still);
    trackOf(p, 'overlay').items.push(createItem('clip', { media: cover.still.id, t0: 0, in: 0, out: r3(cover.until), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, viral: 'first-frame', name: 'First good frame' }));
  }
  const first = clipAt(p, 0);
  if (vivid > 0 && first) { first.fx = (first.fx || []).filter((f) => f.viral !== 'first-frame'); pushFx(first, { type: 'viral.vivid', t0: 0, dur: vivid, b: 1.3, c: 1.18, s: 1.4, viral: 'first-frame' }); }
}

/** flash-forward opener: the best moment [a,b] (timeline) plays first, "wait for it", then the reel from the top */
export function editFlashForward(p, { a, b, kit, lang = 'en', rewind }) {
  const clip = clipAt(p, a); if (!clip) throw new Error('No clip under the best moment');
  b = Math.min(b, itemEnd(clip)); const D = r3(b - a), sp = clip.speed || 1;
  const teaser = clone(clip); teaser.id = uid('i'); teaser.t0 = 0; teaser.in = r3(clip.in + (a - clip.t0) * sp); teaser.out = r3(clip.in + (b - clip.t0) * sp);
  teaser.fx = (teaser.fx || []).filter((f) => typeof f.t0 !== 'number'); teaser.fadeIn = 0; teaser.fadeOut = 0; teaser.viral = 'flash-forward'; teaser.name = 'Flash-forward';
  if (teaser.kf) for (const k of Object.keys(teaser.kf)) teaser.kf[k] = teaser.kf[k].map(([kt, v, e]) => [r3(kt - (a - clip.t0)), v, e]);
  const twords = (p.captions?.words || []).filter((w) => w.t0 >= a - 0.02 && w.t1 <= b + 0.02).map((w) => ({ ...w, t0: r3(w.t0 - a), t1: r3(w.t1 - a) }));
  insertGap(p, 0, D);
  const vt = p.tracks.find((t) => t.kind === 'video'); vt.items.unshift(teaser);
  const c = p.canvas, ar = lang === 'ar';
  trackOf(p, 'text').items.push(createItem('viral.hook', { t0: 0, in: 0, out: D, ...hookBox(c, true), name: 'Wait for it', viral: 'flash-forward',
    style: { text: ar ? 'استنّى للآخر 👀' : 'Wait for it… 👀', accent: ar ? 'استنّى' : 'Wait', lang, colors: colorsOf(kit), ...fontsOf(kit), anim: 'pop', size: Math.round(c.w * 0.075) } }));
  trackOf(p, 'overlay', { top: true, name: 'Hooks' }).items.push(createItem('viral.vfx', { t0: r3(D - 0.02), in: 0, out: 0.32, x: 0, y: 0, w: c.w, h: c.h, viral: 'flash-forward', name: 'Flash', style: { kind: 'flash' } }));
  if (rewind) addSound(p, rewind, D, { feat: 'flash-forward', volume: 0.7 });
  if (p.captions?.words?.length && twords.length) {
    p.captions.words = [...twords, ...p.captions.words];
    p.captions.lines = buildLines(p.captions.words, p.captions.style, c);
  }
  p.meta.viral = { ...(p.meta.viral || {}), flashForward: { d: D, from: r3(a) } };
  return D;
}

// ── 2. natural video hooks ──
/**
 * env = { t (hit time), kit, face:{x,y}, words, firstWord (t0 of the first word), industry, sound (library entry),
 *         stills:{ first?: mediaEntry, last?: mediaEntry }, emoji }
 */
export function editVideoHook(p, hookId, env) {
  removeViral(p, ['vhook', 'vhook-sfx']);
  const c = p.canvas, t = env.t || 0, face = env.face || { x: 0.5, y: 0.42 };
  const top = () => trackOf(p, 'overlay', { top: true, name: 'Hooks' });
  const vfx = (kind, t0, dur, extra = {}) => top().items.push(createItem('viral.vfx', { t0: r3(t0), in: 0, out: dur, x: 0, y: 0, w: c.w, h: c.h, viral: 'vhook', name: 'Hook · ' + kind, style: { kind, ...extra } }));
  const clip0 = () => clipAt(p, t) || mainClips(p)[0];
  const zoom = (fx) => { const cl = clip0(); if (cl) pushFx(cl, { type: 'viral.zoom', cx: face.x, cy: face.y, viral: 'vhook', ...fx }); };
  let soundAt = t;
  switch (hookId) {
    case 'snap-zoom': zoom({ t0: t, dur: 1.1, from: 1, to: 1.32, attack: 0.08, release: 0.45 }); vfx('flash', t, 0.16, { color: '#ffffff' }); break;
    case 'face-open': zoom({ t0: t, dur: 1.25, from: 1, to: 1.7, attack: 0, release: 1.2 }); break;
    case 'shake-impact': { const cl = clip0(); if (cl) pushFx(cl, { type: 'viral.shake', t0: t, dur: 0.45, amp: 0.03, viral: 'vhook' }); vfx('flash', t, 0.18); break; }
    case 'whip-in': vfx('whip', t, 0.38, { dir: 1 }); zoom({ t0: t, dur: 0.45, from: 1, to: 1.12, attack: 0, release: 0.45 }); soundAt = t + 0.05; break;
    case 'reveal-mask': vfx('iris', 0, 0.75, { cx: face.x, cy: face.y, color: colorsOf(env.kit).accent }); soundAt = 0.7; break;
    case 'light-leak': vfx('leak', 0, 0.95); soundAt = 0.12; break;
    case 'freeze-scratch': {
      const st = env.stills?.first; if (!st) throw new Error('freeze-frame needs the first frame');
      const D = 0.7;
      insertGap(p, 0, D);
      if (!p.media.some((m) => m.id === st.id)) p.media.push(st);
      p.tracks.find((tr) => tr.kind === 'video').items.unshift(createItem('clip', { media: st.id, t0: 0, in: 0, out: D, x: 0, y: 0, w: c.w, h: c.h, viral: 'vhook', name: 'Freeze frame',
        fx: [{ type: 'viral.zoom', t0: 0, dur: D, from: 1, to: 1.08, attack: 0.06, release: 0, cx: face.x, cy: face.y, viral: 'vhook' }, { type: 'grayscale', v: 0.35 }] }));
      vfx('flash', D - 0.02, 0.2); soundAt = 0.28; break;
    }
    case 'before-after': {
      const st = env.stills?.last; if (!st) throw new Error('before/after needs a frame from the end');
      if (!p.media.some((m) => m.id === st.id)) p.media.push(st);
      trackOf(p, 'overlay').items.push(createItem('clip', { media: st.id, t0: 0, in: 0, out: 0.6, x: 0, y: 0, w: c.w, h: c.h, viral: 'vhook', name: 'After (flash)' }));   // under the labels
      const lb = (text, t0, dur, accent) => trackOf(p, 'text').items.push(createItem('viral.hook', { t0, in: 0, out: dur, ...hookBox(c, true), viral: 'vhook', name: 'Label ' + text,
        style: { text, accent, colors: colorsOf(env.kit), ...fontsOf(env.kit), anim: 'slam', size: Math.round(c.w * 0.1) } }));
      lb(env.lang === 'ar' ? 'بعد ✨' : 'AFTER ✨', 0, 0.6, env.lang === 'ar' ? 'بعد' : 'AFTER'); lb(env.lang === 'ar' ? 'قبل' : 'BEFORE', 0.62, 1.1, '');
      vfx('flash', 0.58, 0.22); soundAt = 0.02; break;
    }
    case 'object-toss': {
      const sz = Math.round(c.w * 0.95), d = 0.6, k = [[0, -0.6], [d, 1.25]];
      top().items.push(createItem('sticker', { t0: r3(t), in: 0, out: d, x: -sz, y: c.h * 0.7, w: sz, h: sz, text: env.emoji || '🎁', viral: 'vhook', name: 'Object toss',
        kf: { x: k.map(([kt, f]) => [kt, Math.round(f * c.w - sz / 2), 'linear']), y: [[0, Math.round(c.h * 0.85), 'out'], [d / 2, Math.round(c.h * 0.32), 'linear'], [d, Math.round(-sz * 0.3), 'in']], rot: [[0, -50, 'linear'], [d, 260, 'linear']] } }));
      soundAt = t + d * 0.45; break;
    }
    case 'speed-ramp': {
      const lead = Math.min(2.4, (env.firstWord ?? 0) - 0.08);
      const first = mainClips(p)[0];
      if (first && lead >= 0.45 && first.t0 < 0.01 && itemEnd(first) > lead) {
        const fast = 3, keep = lead / fast;
        cutRanges(p, [{ a: keep, b: lead }], { fade: 0.012 });
        const a = mainClips(p)[0]; a.out = r3(a.in + lead * (a.speed || 1)); a.speed = fast * (a.speed || 1); a.muted = true; a.vhook = 'speed-ramp'; a.name = 'Speed ramp';
        a.kf = Object.fromEntries(Object.entries(a.kf || {}).filter(([k]) => k !== 'volume'));
        soundAt = keep;
      } else { zoom({ t0: 0, dur: 0.55, from: 1, to: 1.25, attack: 0, release: 0.55 }); vfx('whip', 0, 0.3); soundAt = 0.3; }
      break;
    }
    default: throw new Error('Unknown hook ' + hookId);
  }
  if (env.sound) addSound(p, env.sound, Math.max(env.sound.hitAt || 0, soundAt), { feat: 'vhook-sfx', volume: env.sound.role === 'ambience' ? 0.45 : 0.75 });
  p.meta.viral = { ...(p.meta.viral || {}), videoHook: { id: hookId, sound: env.sound?.id || null, t: r3(t) } };
}

// ── 3. retention fixes ──
/** env = { kit, sfxPop (library entry, base library/sfx/), keyword, emoji, face, splitAt (a word gap in the zone) } */
export function editDeadZoneFix(p, zone, kind, env) {
  const c = p.canvas, t = r3(zone.t0 + 0.15), face = env.face || { x: 0.5, y: 0.42 };
  const cl = clipAt(p, t);
  if (kind === 'punch' && cl) pushFx(cl, { type: 'viral.zoom', t0: t, dur: 1.0, from: 1, to: 1.16, attack: 0.1, release: 0.3, cx: face.x, cy: face.y, viral: 'retention' });
  else if (kind === 'jump' && cl) {
    // a jump cut: split at a word gap and re-frame the second half tighter
    const at = env.splitAt ?? t;
    const sp = cl.speed || 1, cut = cl.in + (at - cl.t0) * sp;
    if (at > cl.t0 + 0.2 && at < itemEnd(cl) - 0.2) {
      const right = clone(cl); right.id = uid('i'); right.t0 = r3(at); right.in = r3(cut); right.fadeIn = 0;
      if (right.kf) for (const k of Object.keys(right.kf)) right.kf[k] = right.kf[k].map(([kt, v, e]) => [r3(kt - (at - cl.t0)), v, e]);
      cl.out = r3(cut); cl.fadeOut = 0;
      const end = Math.min(itemEnd(right), zone.t1 + 1.5);
      right.fx = [...(right.fx || []).filter((f) => typeof f.t0 !== 'number' || f.t0 >= at), { type: 'viral.zoom', t0: r3(at), dur: r3(end - at), from: 1, to: 1.15, attack: 0, release: 0, cx: face.x, cy: face.y, viral: 'retention' }];
      const vt = p.tracks.find((tr) => tr.items.includes(cl)); vt.items.splice(vt.items.indexOf(cl) + 1, 0, right);
    }
  } else if (kind === 'text') {
    const b = hookBox(c, true), w = Math.round(b.w * 0.8);
    trackOf(p, 'text').items.push(createItem('viral.hook', { t0: t, in: 0, out: 1.3, x: b.x + Math.round((b.w - w) / 2), y: Math.round(c.h * (isVertical(c) ? 0.24 : 0.2)), w, h: b.h, viral: 'retention', name: 'Text pop · ' + (env.keyword || ''),
      style: { text: (env.keyword || '👀').toUpperCase(), accent: (env.keyword || '').toUpperCase(), colors: colorsOf(env.kit), ...fontsOf(env.kit), anim: 'slam', size: Math.round(c.w * 0.1) } }));
    if (env.sfxPop) addSound(p, env.sfxPop, t, { feat: 'retention', volume: 0.55, base: 'library/sfx/' });
  } else if (kind === 'broll') {
    if (env.emoji) {
      const sz = Math.round(c.w * 0.2), x = Math.round(c.w * 0.62 - sz / 2), y = Math.round(c.h * (isVertical(c) ? 0.3 : 0.25)), k = [[0, 0.2], [0.16, 1.2], [0.3, 1], [1.2, 1], [1.45, 0.1]];
      trackOf(p, 'overlay').items.push(createItem('sticker', { t0: t, in: 0, out: 1.45, x, y, w: sz, h: sz, text: env.emoji, viral: 'retention', name: 'Sticker ' + env.emoji,
        kf: { w: k.map(([a, v]) => [a, sz * v, 'out']), h: k.map(([a, v]) => [a, sz * v, 'out']), x: k.map(([a, v]) => [a, x + sz / 2 - (sz * v) / 2, 'out']), y: k.map(([a, v]) => [a, y + sz / 2 - (sz * v) / 2, 'out']), rot: [[0, -14, 'out'], [0.3, 6, 'inout'], [0.6, 0, 'inout']] } }));
      if (env.sfxPop) addSound(p, env.sfxPop, t, { feat: 'retention', volume: 0.5, base: 'library/sfx/' });
    } else {
      const w = Math.round(c.w * 0.62), h = Math.round(w * 0.6);
      trackOf(p, 'overlay').items.push(createItem('viral.placeholder', { t0: t, in: 0, out: r3(Math.max(1.5, zone.t1 - zone.t0 - 0.3)), x: Math.round((c.w - w) / 2), y: Math.round(c.h * 0.25), w, h, viral: 'retention', name: 'B-roll placeholder', style: { hint: env.keyword || '' } }));
    }
  }
}

// ── 4. tightening ──
export const editTighten = (p, cuts) => cutRanges(p, cuts.map((c) => ({ a: c.a, b: c.b })), { fade: 0.012 });

// ── 5. smart zooms + upper-third reframe ──
/** zooms = [{t0, t1, amount, cx, cy, word}] on the timeline */
export function editSmartZooms(p, zooms) {
  removeViral(p, ['smart-zoom']);
  for (const z of zooms) {
    const cl = clipAt(p, z.t0); if (!cl) continue;
    const dur = Math.min(itemEnd(cl) - z.t0, Math.max(0.55, z.t1 - z.t0 + 0.3));
    pushFx(cl, { type: 'viral.zoom', t0: r3(z.t0 - 0.03), dur: r3(dur), from: 1, to: z.amount, attack: 0.09, release: 0.28, cx: z.cx ?? 0.5, cy: z.cy ?? 0.4, viral: 'smart-zoom', word: z.word });
  }
}
/** kfs: Map clipId -> {x,y,w,h} keyframes (item-relative), from index.js upperThird() */
export function editReframe(p, kfs) {
  for (const cl of mainClips(p)) {
    const k = kfs.get(cl.id); if (!k || !k.x.length) continue;
    cl.kf = { ...(cl.kf || {}), x: k.x, y: k.y, w: k.w, h: k.h };
    Object.assign(cl, { x: k.x[0][1], y: k.y[0][1], w: k.w[0][1], h: k.h[0][1] });
  }
  p.meta.viral = { ...(p.meta.viral || {}), reframe: 'upper-third' };
}

// ── 6. safe zones ──
/** moves = [{id, dx, dy, scale}] for items; caption = {posY, size, rowChars} | null */
export function editNudge(p, moves, caption) {
  const byId = new Map(); for (const tr of p.tracks) for (const it of tr.items) byId.set(it.id, it);
  for (const m of moves) {
    const it = byId.get(m.id); if (!it) continue;
    const s = m.scale ?? 1, cx = it.x + it.w / 2, cy = it.y + it.h / 2;
    const tx = (x, w) => cx + (x + w / 2 - cx) * s - (w * s) / 2 + m.dx, ty = (y, h) => cy + (y + h / 2 - cy) * s - (h * s) / 2 + m.dy;
    // keyframed boxes move with their keys (stickers that pop, tossed objects): every key shifts by the same amount
    if (it.kf?.x?.length) it.kf.x = it.kf.x.map(([t, v, e]) => [t, Math.round(v + m.dx), e]);
    if (it.kf?.y?.length) it.kf.y = it.kf.y.map(([t, v, e]) => [t, Math.round(v + m.dy), e]);
    const nx = tx(it.x, it.w), ny = ty(it.y, it.h);
    it.w = Math.round(it.w * s); it.h = Math.round(it.h * s); it.x = Math.round(nx); it.y = Math.round(ny);
    if (s < 1 && it.style?.size) it.style = { ...it.style, size: Math.max(12, Math.round(it.style.size * s)) };
  }
  if (caption && p.captions) {
    const base = typeof p.captions.style === 'string' ? { id: p.captions.style } : { ...(p.captions.style || {}) };
    base.pos = { ...(base.pos || {}), y: caption.posY };
    if (caption.size) base.size = caption.size;
    if (caption.rowChars) base.rowChars = caption.rowChars;
    p.captions.style = base;
    if (caption.rowChars) p.captions.lines = buildLines(p.captions.words, base, p.canvas);
  }
}
export { nudgeBox };

// ── 7. loop ending ──
/** still = a media entry of the composite FIRST frame; mirror = audible items under [0,d] to cross-fade in at the end */
export function editLoop(p, { still, d = 0.7 }) {
  removeViral(p, ['loop']);
  const T = projectDuration(p), c = p.canvas;
  if (!p.media.some((m) => m.id === still.id)) p.media.push(still);
  // the first frame fades in over the last d seconds, settling from a slight push-in to the exact framing of frame 1
  const z = 1.05, w0 = Math.round(c.w * z), h0 = Math.round(c.h * z);
  const hold = r3(d * 0.75);
  trackOf(p, 'overlay', { top: true, name: 'Loop' }).items.push(createItem('clip', { media: still.id, t0: r3(T - d), in: 0, out: d, x: 0, y: 0, w: c.w, h: c.h, viral: 'loop', name: 'Loop blend',
    kf: { opacity: [[0, 0, 'inout'], [hold, 1, 'linear']], w: [[0, w0, 'out'], [hold, c.w, 'linear']], h: [[0, h0, 'out'], [hold, c.h, 'linear']], x: [[0, Math.round((c.w - w0) / 2), 'out'], [hold, 0, 'linear']], y: [[0, Math.round((c.h - h0) / 2), 'out'], [hold, 0, 'linear']] } }));
  // sound: whatever plays at the end fades out while the opening's sound fades in under the blend
  const sfx = trackOf(p, 'sfx');
  for (const tr of p.tracks) {
    if (tr.muted || tr.kind === 'sfx') continue;
    for (const it of tr.items.slice()) {
      if (!it.media || it.viral === 'loop' || it.muted) continue;
      const m = findMedia(p, it.media); if (!m || m.kind === 'image' || !m.hasAudio) continue;
      const s = it.t0, e = itemEnd(it), dd = itemDur(it), v = it.volume ?? 1;
      if (e > T - d + 1e-3 && !it.kf?.volume?.length) it.kf = { ...(it.kf || {}), volume: [[0, v, 'linear'], [r3(Math.max(0, T - d - s)), v, 'linear'], [r3(dd), 0, 'linear']] };
      if (s < d - 1e-3) {
        const sp = it.speed || 1, inn = it.in + Math.max(0, -s) * sp, len = Math.min(d, e) - Math.max(0, s);
        if (len > 0.05) sfx.items.push(createItem('audio', { media: it.media, t0: r3(T - d + Math.max(0, s)), in: r3(inn), out: r3(inn + len * sp), speed: sp, volume: v, viral: 'loop', name: 'Loop cross-fade',
          kf: { volume: [[0, 0, 'linear'], [r3(len), v * (tr.volume ?? 1), 'linear']] } }));
      }
    }
  }
  p.meta.viral = { ...(p.meta.viral || {}), loop: { d } };
}

// ── 8. CTA ──
/** spec = { kind, title, sub, keyword, lang, kit, t0, dur, sound } */
export function editCta(p, s) {
  removeViral(p, ['cta']);
  const c = p.canvas, v = isVertical(c);
  const w = Math.round(c.w * (v ? 0.72 : 0.6)), h = Math.round(w * (v ? 0.25 : 0.2));
  // above the captions when they would meet (s.above = the top of the highest caption cue in its time), else the lower middle
  const x = Math.round(v ? c.w * 0.07 : (c.w - w) / 2), dflt = Math.round(v ? c.h * 0.6 : c.h * 0.66);
  const y = s.above != null && s.above < dflt + h + 24 ? Math.max(Math.round(c.h * (v ? 0.14 : 0.1)), Math.round(s.above - h - 36)) : dflt;
  trackOf(p, 'overlay', { top: true, name: 'Hooks' }).items.push(createItem('viral.cta', { t0: r3(s.t0), in: 0, out: r3(s.dur), x, y, w, h, viral: 'cta', name: 'CTA · ' + s.kind,
    style: { kind: s.kind, title: s.title, sub: s.sub || '', keyword: s.keyword || '', lang: s.lang, colors: colorsOf(s.kit), ...fontsOf(s.kit) } }));
  if (s.sound) addSound(p, s.sound, s.t0 + 0.12, { feat: 'cta', volume: 0.6 });
}

// ── 9. chapters / progress ──
export function editChapters(p, { chapters, mode = 'both', kit, label }) {
  removeViral(p, ['chapters']);
  const c = p.canvas, T = projectDuration(p), v = isVertical(c);
  const box = v ? { x: Math.round(c.w * 0.06), y: Math.round(c.h * 0.096), w: Math.round(c.w * 0.88), h: Math.round(c.h * 0.06) } : { x: Math.round(c.w * 0.05), y: Math.round(c.h * 0.055), w: Math.round(c.w * 0.9), h: Math.round(c.h * 0.1) };
  trackOf(p, 'overlay', { top: true, name: 'Hooks' }).items.push(createItem('viral.progress', { t0: 0, in: 0, out: r3(T), ...box, viral: 'chapters', name: mode === 'bar' ? 'Progress bar' : 'Steps + progress',
    style: { chapters, mode, total: r3(T), colors: colorsOf(kit), ...fontsOf(kit), label: label || 'STEP' } }));
  p.markers = [...(p.markers || []).filter((m) => !m.viral), ...chapters.map((ch, i) => ({ id: uid('k'), t: ch.t0, label: ch.title || `Step ${i + 1}`, color: '#ffd166', viral: 'chapters' }))].sort((a, b) => a.t - b.t);
}

// ── 11. claim fixes ──
/** findings from scanClaims(); captions findings carry wordIdx [i,j]; item findings carry itemId + field */
export function editClaims(p, findings) {
  const byItem = new Map();
  for (const f of findings) if (f.itemId) { if (!byItem.has(f.itemId)) byItem.set(f.itemId, []); byItem.get(f.itemId).push(f); }
  for (const tr of p.tracks) for (const it of tr.items) {
    const fs = byItem.get(it.id); if (!fs) continue;
    const field = fs[0].field;
    if (field === 'text') it.text = applyFixes(it.text, fs);
    else it.style = { ...it.style, [field]: applyFixes(it.style[field], fs) };
  }
  // captions: replace the words a finding covers with the suggestion, spread over the same time
  const caps = findings.filter((f) => f.source === 'captions' && f.wordIdx).sort((a, b) => b.wordIdx[0] - a.wordIdx[0]);
  if (caps.length && p.captions?.words?.length) {
    const words = p.captions.words.slice();
    for (const f of caps) {
      const [i, j] = f.wordIdx, t0 = words[i].t0, t1 = words[j].t1;
      const tail = (/[.,!?؟،]+$/.exec(words[j].w) || [''])[0];
      const parts = f.suggestion.split(/\s+/).filter(Boolean), step = (t1 - t0) / parts.length;
      words.splice(i, j - i + 1, ...parts.map((w, k) => ({ w: k === parts.length - 1 ? w + tail : w, t0: r3(t0 + k * step), t1: r3(t0 + (k + 1) * step), conf: 1 })));
    }
    p.captions.words = words;
    p.captions.lines = buildLines(words, p.captions.style, p.canvas);
  }
}
