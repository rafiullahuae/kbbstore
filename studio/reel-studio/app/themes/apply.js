// KBB Studio · themes · apply / tune / remove a theme. Applying is ONE undoable step (ops.batch): the overlay that runs
// through the whole reel, the intro sting, the outro, a transition on every cut, the themed title and the theme's
// sounds. Everything it places carries item.auto = 'theme', so applying another theme replaces it and never touches the
// owner's own items (or Auto reel's).
import { ops } from '../core/history.js';
import { createItem, createTrack, projectDuration, itemEnd, itemDur, findMedia, clone } from '../core/project.js';
import { themeById } from './themes.js';
import { textItem } from '../look/text.js';
import { libraryMedia } from '../auto/commands.js';
import { ELEMENTS, elementById, boxFor, elementStyle } from './elements.js';
import { transItem, transitionOf, cutsOf as cutPairs } from './transitions.js';

export const TAG = 'theme';
const r3 = (x) => +(+x).toFixed(3);
export const themeItems = (p) => p.tracks.flatMap((tr) => tr.items.filter((i) => i.auto === TAG).map((item) => ({ track: tr, item })));
export const overlayOf = (p) => themeItems(p).find((x) => x.item.type === 'theme.overlay')?.item || null;

/** the colours a theme draws in: its own palette, or the company's brand kit (flag colours always stay the flag's) */
export function colorsFor(th, mode, ws) {
  const pal = { ...th.palette, extra: (th.palette.extra || []).slice() };
  if (mode !== 'brand' || !ws?.colors) return pal;
  const c = ws.colors;
  return { primary: c.primary || pal.primary, accent: c.accent || pal.accent, soft: c.text || '#ffffff', ink: c.secondary || pal.ink, extra: pal.extra };
}

/** beat times on the timeline from the music items (library beat grids, shifted by each item's in/t0/speed) */
export async function beatsOf(ctx) {
  const p = ctx.project, items = p.tracks.filter((t) => t.kind === 'music' && !t.muted).flatMap((t) => t.items);
  if (!items.length) return [];
  let lib = null; try { lib = await (await import('../auto/index.js')).library(ctx); } catch { return []; }
  const out = [];
  for (const it of items) {
    const m = findMedia(p, it.media); const tr = m && lib.music.find((x) => (x.src || 'library/music/' + x.file) === m.src);
    if (!tr?.beats) continue;
    for (const b of tr.beats) if (b >= it.in && b <= it.out) out.push(r3(it.t0 + (b - it.in) / (it.speed || 1)));
  }
  return [...new Set(out)].sort((a, b) => a - b);
}

/** cut points between clips on the video track(s) */
export function cutsOf(p) {
  const out = [];
  for (const tr of p.tracks.filter((t) => t.kind === 'video')) {
    const its = tr.items.slice().sort((a, b) => a.t0 - b.t0);
    for (let i = 1; i < its.length; i++) if (Math.abs(itemEnd(its[i - 1]) - its[i].t0) < 0.08) out.push(r3(its[i].t0));
  }
  return out;
}

/**
 * faces on the timeline, canvas-normalised: [{t, x, y, w, h}] (centre + size). Sampled ~1 per second from every
 * clip with MediaPipe (app/auto/faces.js), mapped through each clip's cover fit and crop.
 */
const faceCache = new Map();
export async function detectFaces(ctx, { perSec = 1, max = 40 } = {}) {
  const p = ctx.project, W = p.canvas.w, H = p.canvas.h, clips = p.tracks.filter((t) => t.kind === 'video' && !t.hidden).flatMap((t) => t.items.filter((i) => i.type === 'clip' && i.media));
  const key = clips.map((c) => [c.media, c.t0, c.in, c.out, c.x, c.y, c.w, c.h, JSON.stringify(c.crop || 0)].join(':')).join('|') + '@' + W + 'x' + H;
  if (faceCache.has(key)) return faceCache.get(key);
  const samples = [];
  for (const c of clips) {
    const m = findMedia(p, c.media); if (!m || m.kind !== 'video' && m.kind !== 'image') continue;
    const d = itemEnd(c) - c.t0, n = Math.max(1, Math.min(Math.ceil(d * perSec), Math.ceil(max / Math.max(1, clips.length))));
    for (let i = 0; i < n; i++) samples.push({ c, m, t: r3(c.t0 + (i + 0.5) * (d / n)) });
  }
  if (!samples.length) return [];
  const frames = [];
  for (const s of samples) {
    try { const bm = await ctx.media.frameAt(s.m.id, s.m.kind === 'image' ? 0 : s.c.in + (s.t - s.c.t0) * (s.c.speed || 1), 320); if (bm) frames.push({ t: s.t, bitmap: bm, s }); } catch { /* skip that frame */ }
    ctx.progress?.('Finding faces…', frames.length / samples.length * 0.5);
  }
  const dims = frames.map((f) => [f.bitmap.width, f.bitmap.height]);
  let found = [];
  try { const { trackFaces } = await import('../auto/faces.js'); found = await trackFaces(frames.map((f) => ({ t: f.t, bitmap: f.bitmap })), (x) => ctx.progress?.('Finding faces…', 0.5 + x * 0.5)); }
  catch (e) { console.warn('[themes] face detection unavailable:', e.message); }
  const out = [];
  found.forEach((f, i) => {
    if (f.x == null) return;
    const { c, m } = frames[i].s, sw = m.w || dims[i][0], sh = m.h || dims[i][1], cr = c.crop || { l: 0, t: 0, r: 0, b: 0 };
    const cx = sw * cr.l, cy = sh * cr.t, cw = sw * (1 - cr.l - cr.r), ch = sh * (1 - cr.t - cr.b), s = Math.max(c.w / cw, c.h / ch);
    const vx = cx + (cw - c.w / s) / 2, vy = cy + (ch - c.h / s) / 2;
    out.push({ t: frames[i].t, x: r3((c.x + (f.x * sw - vx) * s) / W), y: r3((c.y + (f.y * sh - vy) * s) / H), w: r3((f.w * sw * s) / W), h: r3((f.h * sh * s) / H) });
  });
  ctx.progress?.(null);
  faceCache.set(key, out); return out;
}

let HOOKS = null;
async function sfxLibrary(ctx) {
  const main = await (await import('../auto/index.js')).library(ctx).then((l) => l.sfx.map((s) => ({ ...s, src: 'library/sfx/' + s.file }))).catch(() => []);
  if (!HOOKS) HOOKS = await fetch('/library/sfx/hooks/library.json').then((r) => (r.ok ? r.json() : { sfx: [] })).then((j) => j.sfx.map((s) => ({ ...s, src: 'library/sfx/hooks/' + s.file }))).catch(() => []);
  return [...main, ...HOOKS];
}

/** the overlay item's style for a theme + options (also what the hover previews draw) */
export function themeStyle(th, opts = {}, ws = null) {
  return {
    theme: th.id, intensity: opts.intensity || 'normal', colorMode: opts.colorMode || 'theme', colors: colorsFor(th, opts.colorMode, ws),
    beat: opts.beat !== false, beats: opts.beats || [], safe: opts.safe !== false, faces: opts.faces || [],
    defs: clone(th.layers), layers: clone(opts.layers || {}),
  };
}

/**
 * Apply a theme: ONE undo step. opts = { intensity, colorMode:'theme'|'brand', beat, safe, sting, outro, transitions, title
 * (true | false | text), sfx, faces (skip detection), layers:{id:{on,density,speed,color}} }. Returns what it placed.
 */
export async function applyTheme(ctx, id, opts = {}) {
  const th = themeById(id); if (!th) throw new Error('No such theme: ' + id);
  const p = ctx.project, W = p.canvas.w, H = p.canvas.h, ws = ctx.workspace || {};
  const D = reelEnd(p) || projectDuration(p) || 10;
  const beats = opts.beats || await beatsOf(ctx);
  const faces = opts.faces || (opts.safe === false ? [] : await detectFaces(ctx));
  const style = themeStyle(th, { ...opts, beats, faces }, ws);
  const cmds = [], placed = { overlay: null, sting: null, outro: null, transitions: 0, title: null, signature: null, lower: null, grade: null, sfx: 0 };
  const old = themeItems(p).map((x) => x.item.id); if (old.length) cmds.push(ops.removeItems(old, 'Replace theme'));
  // the overlay gets its own "Theme" lane right above the video (under the owner's stickers, text and captions);
  // the sting, outro and transitions go on the ordinary overlay track
  const lastVideo = p.tracks.reduce((a, t, i) => (t.kind === 'video' ? i : a), -1);
  let tt = p.tracks.find((x) => x.kind === 'overlay' && x.theme);
  if (!tt) { tt = Object.assign(createTrack('overlay', 'Theme'), { theme: true }); cmds.push(ops.addTrack(tt, lastVideo + 1)); }
  const track = (kind) => {
    let t = p.tracks.find((x) => x.kind === kind && !x.locked && !x.theme);
    if (!t) { t = createTrack(kind); cmds.push(ops.addTrack(t, kind === 'overlay' ? lastVideo + 2 : undefined)); }
    return t;
  };
  const ot = track('overlay'), name = th.name;
  const ov = createItem('theme.overlay', { t0: 0, in: 0, out: r3(D), x: 0, y: 0, w: W, h: H, auto: TAG, name: 'Theme · ' + name, style });
  const add = [{ trackId: tt.id, item: ov }]; placed.overlay = ov.id;
  // the complete package, one family: grade (whole reel) · intro sting · title card · signature element · lower third ·
  // a colour-slab wipe on every cut · end card with CTA. All theme.* items, all tagged, so the next theme replaces them.
  const es = (el, extra = {}) => elementStyle(th.id, el, { colors: style.colors, brand: ws?.name || null, logo: ws?.logo || null, ...extra });
  const el = (type, el2, t0, dur, props = {}) => createItem(type, { t0: r3(t0), in: 0, out: r3(dur), auto: TAG, name: `Theme ${elementById(el2)?.label || el2} · ${name}`, ...boxFor(el2, W, H), ...props });
  if (opts.grade !== false) { const it = el('theme.el', 'grade', 0, D, { style: es('grade', { whole: 'span' }) }); add.unshift({ trackId: tt.id, item: it }); placed.grade = it.id; }
  // a design-family package (th.pkg) opens and closes on its own big compositions instead of the slab sting / end card
  const pk = th.pkg || null, introDur = pk ? Math.min(3.2, Math.max(1.6, D * 0.25)) : 0.9;
  if (opts.sting !== false && D >= 3) { const it = pk ? el('theme.el', pk.intro, 0, introDur, { style: es(pk.intro) }) : el('theme.sting', 'intro', 0, 0.9, { style: es('intro') }); it.name = 'Theme intro · ' + name; add.push({ trackId: ot.id, item: it }); placed.sting = it.id; }
  const endDur = pk ? Math.min(3.2, Math.max(1.6, D * 0.25)) : D >= 9 ? 2.6 : 1.6;
  if (opts.outro !== false && D >= 5) { const it = pk ? el('theme.el', pk.end, D - endDur, endDur, { style: es(pk.end) }) : el('theme.outro', 'end', D - endDur, endDur, { style: es('end') }); it.name = 'Theme outro · ' + name; add.push({ trackId: ot.id, item: it }); placed.outro = it.id; }
  const cuts = opts.transitions === false ? [] : cutsOf(p);
  const owned = p.tracks.flatMap((t) => t.items).filter((i) => /transition/.test(i.type) && i.auto !== TAG);
  const tk = transitionOf(th);
  for (const c of cuts) {
    if (owned.some((i) => c >= i.t0 - 0.05 && c <= itemEnd(i) + 0.05)) continue;   // the owner's own transition stays
    const pair = cutPairs(p).find((x) => Math.abs(x.t - c) < 0.01);
    // a full-screen country transition between the two clips (both clips show through it); a plain wipe where the cut
    // is not between two clips of the main track
    add.push({ trackId: ot.id, item: pair ? transItem(p, pair, tk, { theme: th.id, colors: style.colors, auto: TAG }) : el('theme.el', 'wipe', Math.max(0, c - 0.25), 0.5, { style: es('wipe') }) });
    placed.transitions++;
  }
  const lane = track('text'), body = D - (placed.outro ? endDur : 0);
  let t1 = placed.sting ? (pk ? introDur + 0.3 : 0.8) : 0.3;
  if (opts.title !== false && !pk && body > t1 + 2) {
    const it = el('theme.el', 'title', t1, Math.min(2.8, body - t1 - 0.2), { style: es('title') });
    await placeTitle(p, it, faces); add.push({ trackId: lane.id, item: it }); placed.title = it.id; t1 += it.out + 0.4;
  }
  const sig = opts.signature === false ? null : signatureOf(th);
  if (sig && body > t1 + 2.4) {
    const it = el('theme.el', sig, t1, Math.min(elementById(sig).dur + 0.4, body - t1 - 0.2), { style: es(sig) });
    await placeTitle(p, it, faces); add.push({ trackId: ot.id, item: it }); placed.signature = it.id; t1 += it.out + 0.6;
  }
  if (opts.lower !== false && !pk && body > t1 + 3) {
    const it = el('theme.el', 'lowerbar', t1, 3.5, { style: es('lowerbar') });
    await placeTitle(p, it, faces); add.push({ trackId: lane.id, item: it }); placed.lower = it.id;
  }
  if (opts.sfx !== false && th.sfx) {
    const lib = await sfxLibrary(ctx), st = track('sfx'); let pp = p;
    const put = (sid, at, vol, label) => {
      const s = lib.find((x) => x.id === sid); if (!s || at < 0 || at >= D) return;
      const { media, cmd } = libraryMedia(pp, s.src, 'audio', s.duration, s.title || s.id);
      if (cmd) { cmds.push(cmd); pp = { ...pp, media: [...pp.media, media] }; }
      add.push({ trackId: st.id, item: createItem('audio', { media: media.id, t0: r3(Math.max(0, at - (s.hitAt || 0))), in: 0, out: s.duration, volume: vol, auto: TAG, name: `Theme SFX · ${label} · ${s.id}` }) });
      placed.sfx++;
    };
    if (placed.sting) put(th.sfx.intro, 0.05, 0.6, 'intro');
    if (placed.outro) put(th.sfx.outro, D - 1.2, 0.55, 'outro');
    if (placed.title) put(th.sfx.accent, (placed.sting ? 0.8 : 0.3) + 0.1, 0.45, 'title');
    for (const c of cuts.slice(0, 24)) put(th.sfx.transition, c, 0.4, 'transition');
  }
  cmds.push(ops.addItems(add, 'Theme'));
  ctx.history.apply(ops.batch('Theme · ' + name, cmds));
  return { theme: th.id, ...placed, beats: beats.length, faces: faces.length, duration: D };
}

/**
 * the title must not sit on the captions or a face: try its own place, then the top band, then the lower band, and keep
 * the first that is clear for its whole duration
 */
export function placeTitle(p, it, faces = []) {
  const W = p.canvas.w, H = p.canvas.h, land = W > H;
  let ctx = null; try { ctx = document.createElement('canvas').getContext('2d'); } catch { return; }
  let cap = null, blocks = null;
  try { cap = p.tracks.find((t) => t.kind === 'caption' && !t.hidden)?.items.find((i) => i.type === 'caption'); } catch {}
  const cost = (y) => {   // how much of the title would sit on a face or a caption, summed over its duration
    const box = { x: it.x, y, w: it.w, h: it.h }; let c = 0;   // (it.x / w / h are the size being tried)
    for (let t = it.t0; t < it.t0 + (it.out - it.in); t += 0.25) {
      const others = [];
      for (const f of faces) if (f.t == null || Math.abs(f.t - t) < 0.8) others.push({ x: (f.x - f.w / 2) * W, y: (f.y - f.h / 2) * H, w: f.w * W, h: f.h * H, k: 1 });
      if (cap && blocks) { try { for (const b of blocks(ctx, p, t, cap)) others.push({ ...b.box, k: 3 }); } catch {} }   // captions matter most
      for (const o of others) c += o.k * Math.max(0, Math.min(o.x + o.w, box.x + box.w) - Math.max(o.x, box.x)) * Math.max(0, Math.min(o.y + o.h, box.y + box.h) - Math.max(o.y, box.y));
    }
    return c;
  };
  return import('../captions/render.js').then((R) => { blocks = R.captionBlocks; }).catch(() => {}).then(() => {
    // its own place first, then every other height (nearest first), then the same a little smaller; the first clear
    // one wins, else the least covered
    const x0 = it.x, w0 = it.w, h0 = it.h, y0 = it.y, cx = x0 + w0 / 2, cy = y0 + h0 / 2;
    let best = null, bc = Infinity;
    for (const k of [1, 0.82, 0.66]) {
      it.w = Math.round(w0 * k); it.h = Math.round(h0 * k); it.x = Math.round(cx - it.w / 2);
      const top = Math.round(H * (land ? 0.05 : 0.07)), bottom = Math.round(H * (land ? 0.95 : 0.86)) - it.h, own = Math.round(cy - it.h / 2);
      const ys = [own]; for (let y = top; y <= bottom; y += Math.round(H * 0.02)) ys.push(y);
      ys.sort((a, b) => Math.abs(a - own) - Math.abs(b - own));
      for (const y of ys) { const c = cost(y); if (c < bc) { bc = c; best = { x: it.x, y, w: it.w, h: it.h }; } if (c === 0) break; }
      if (bc === 0) break;
    }
    Object.assign(it, best || { x: x0, y: y0, w: w0, h: h0 });
    return bc === 0;
  });
}

/** change the overlay's style (intensity, colours, a layer's toggle/density/speed/colour): one coalescing undo step */
export function tuneTheme(ctx, patch, label = 'Theme') {
  const it = overlayOf(ctx.project); if (!it) throw new Error('No theme on this reel yet');
  const D = reelEnd(ctx.project);
  const style = Object.assign(clone(it.style), patch);
  if (patch.colorMode) { const th = themeById(style.theme); if (th) style.colors = colorsFor(th, patch.colorMode, ctx.workspace); }
  const props = { style };
  if (D > 0 && Math.abs(it.out - D) > 0.05) props.out = r3(D);   // keeps running through the whole reel after edits
  ctx.history.apply(ops.setItem(it.id, props, label));
  return style;
}
/** one layer's overrides: tuneLayer(ctx, 'petals', { density: 1.4 }) */
export function tuneLayer(ctx, layerId, patch, label = 'Theme element') {
  const it = overlayOf(ctx.project); if (!it) throw new Error('No theme on this reel yet');
  const layers = clone(it.style.layers || {}); layers[layerId] = Object.assign({}, layers[layerId], patch);
  return tuneTheme(ctx, { layers }, label);
}
export function removeTheme(ctx) {
  const p = ctx.project, ids = themeItems(p).map((x) => x.item.id); if (!ids.length) return 0;
  const lanes = p.tracks.filter((t) => t.theme && t.items.every((i) => i.auto === TAG)).map((t) => ops.removeTrack(t.id, 'Remove theme'));
  ctx.history.apply(ops.batch('Remove theme', [ops.removeItems(ids, 'Remove theme'), ...lanes])); return ids.length;
}
/** where the reel ends: the last thing on it that the theme did not place (the overlay must not hold the reel open) */
export function reelEnd(p) {
  let end = 0;
  for (const tr of p.tracks) for (const it of tr.items) if (it.auto !== TAG) end = Math.max(end, itemEnd(it));
  return r3(end);
}
/**
 * keep the theme on the reel after ANY edit (trim, silence removal, reorder, undo/redo): the overlay ends where the reel
 * ends and the outro closes it. Their timing is derived from the reel, never chosen by the owner, so this re-derives it
 * in place right after each change instead of adding an undo step (undo of the trim re-derives it back). Returns true
 * when it moved something. Pure on the project; index.js runs it from the 'project:change' event.
 */
export function followReel(p) {
  if (!p?.tracks) return false;
  const ov = overlayOf(p); if (!ov && !themeItems(p).some((x) => x.item.style?.whole)) return false;
  const D = reelEnd(p); if (!(D > 0)) return false;
  let moved = false;
  if (ov && Math.abs(itemEnd(ov) - D) > 0.001) { ov.t0 = 0; ov.in = 0; ov.out = r3(D * (ov.speed || 1)); moved = true; }
  for (const { item } of themeItems(p)) {
    const wh = item.style?.whole; if (!wh || item.type !== 'theme.el') continue;
    if (wh === 'span' && (Math.abs(item.t0) > 0.001 || Math.abs(itemEnd(item) - D) > 0.001)) { item.t0 = 0; item.in = 0; item.out = r3(D * (item.speed || 1)); moved = true; }
    if (wh === 'end') { const t0 = r3(Math.max(0, D - itemDur(item))); if (Math.abs(item.t0 - t0) > 0.001) { item.t0 = t0; moved = true; } }
  }
  const outro = themeItems(p).find((x) => x.item.type === 'theme.outro')?.item;
  if (outro) {
    const t0 = r3(Math.max(0, D - itemDur(outro))), dt = t0 - outro.t0;
    if (Math.abs(dt) > 0.001) {
      outro.t0 = t0; moved = true;
      // the outro's sound goes with it (else it would hold a shortened reel open past its end)
      for (const { item } of themeItems(p)) if (item.type === 'audio' && /^Theme SFX · outro/.test(item.name || '')) item.t0 = r3(Math.max(0, item.t0 + dt));
    }
  }
  return moved;
}
export async function resyncBeats(ctx) { const beats = await beatsOf(ctx); tuneTheme(ctx, { beats }, 'Theme beats'); return beats.length; }

/** each theme's signature moment in the package */
export function signatureOf(th) {
  if (!th) return null;
  if (th.pkg) return th.pkg.sig;
  if (th.group === 'Countries') return 'breaking';
  if (th.id === 'europe') return 'globe';
  return { travel: 'ticket', passport: 'stamp', study: 'postcard', 'work-trades': 'hiring', 'work-care': 'hiring', 'work-hospitality': 'hiring', 'work-abroad': 'hiring' }[th.id]
    || (/^(Offers|Seasonal)$/.test(th.group) ? 'confetti' : 'sticker');
}
/** the theme an element placed now should draw: the reel's theme, else the given one */
const themeNow = (p, fallback) => fallback || overlayOf(p)?.style?.theme || themeItems(p).find((x) => x.item.style?.theme)?.item.style.theme || 'travel';

/**
 * Place one package element as an ordinary timeline item (the owner's own: drag it, trim it, delete it). ONE undo step.
 * opts = { theme, t0, dur, variant, trackId, box:{x,y,w,h}, text }. 'overlay' places the running overlay as a clip.
 */
export function placeElement(ctx, el, opts = {}) {
  const p = ctx.project, W = p.canvas.w, H = p.canvas.h, id = themeNow(p, opts.theme), th = themeById(id); if (!th) throw new Error('No such theme: ' + id);
  const ws = ctx.workspace || {}, mode = overlayOf(p)?.style?.colorMode || 'theme';
  const cmds = []; let item;
  if (el === 'overlay') {
    const style = themeStyle(th, { intensity: overlayOf(p)?.style?.intensity || 'normal', colorMode: mode, beats: overlayOf(p)?.style?.beats || [], faces: overlayOf(p)?.style?.faces || [] }, ws);
    item = createItem('theme.overlay', { t0: r3(Math.max(0, opts.t0 ?? 0)), in: 0, out: r3(opts.dur || 4), x: 0, y: 0, w: W, h: H, name: 'Theme overlay · ' + th.name, style });
  } else {
    const def = elementById(el); if (!def) throw new Error('No such element: ' + el);
    const style = elementStyle(th.id, el, { colors: colorsFor(th, mode, ws), variant: opts.variant, text: opts.text, brand: ws?.name || null, logo: ws?.logo || null });
    item = createItem('theme.el', { t0: r3(Math.max(0, opts.t0 ?? 0)), in: 0, out: r3(opts.dur || def.dur), ...(opts.box || boxFor(el, W, H)), name: `${def.icon} ${def.label}${opts.variant ? ' · ' + opts.variant : ''} · ${th.name}`, style });
  }
  // the row it was dropped on when that row is free at that time, else the first free overlay row, else a new one
  const t1 = item.t0 + (item.out - item.in), free = (t) => !t.items.some((i) => i.t0 < t1 - 0.001 && itemEnd(i) > item.t0 + 0.001);
  let tr = opts.trackId && p.tracks.find((t) => t.id === opts.trackId && !t.locked && /^(overlay|text)$/.test(t.kind) && free(t));
  if (!tr) tr = p.tracks.find((t) => t.kind === 'overlay' && !t.locked && !t.theme && free(t));
  if (!tr) { tr = createTrack('overlay'); const lastVideo = p.tracks.reduce((a, t, i) => (t.kind === 'video' ? i : a), -1); cmds.push(ops.addTrack(tr, lastVideo + 2)); }
  cmds.push(ops.addItems([{ trackId: tr.id, item }], 'Add ' + item.name));
  ctx.history.apply(ops.batch('Add ' + item.name, cmds));
  return item;
}

/** the whole-video item(s) of an element (null when it is off) */
export const wholeItems = (p, el, variant) => themeItems(p).filter((x) => (el === 'overlay' ? x.item.type === 'theme.overlay' : x.item.type === 'theme.el' && x.item.style?.el === el && x.item.style?.whole && (!variant || x.item.style.variant === variant))).map((x) => x.item);
export const isWhole = (p, el, variant) => wholeItems(p, el, variant).length > 0;

/**
 * Switch an element on or off for the whole video. ONE undo step. What "whole video" means comes from the catalogue:
 * span = from the first frame to the last (and it follows every trim), start = at the top, end = closing the reel,
 * cuts = on every cut. The overlay toggles the running overlay itself.
 */
export async function setWhole(ctx, el, on, opts = {}) {
  const p = ctx.project, W = p.canvas.w, H = p.canvas.h, D = reelEnd(p) || projectDuration(p) || 10;
  const cur = wholeItems(p, el, opts.variant), cmds = [];
  if (!on) { if (!cur.length) return 0; ctx.history.apply(ops.batch('Whole video off', [ops.removeItems(cur.map((i) => i.id), 'Whole video off')])); return 0; }
  if (cur.length) return cur.length;
  const id = themeNow(p, opts.theme), th = themeById(id); if (!th) throw new Error('No such theme: ' + id);
  const ws = ctx.workspace || {}, mode = overlayOf(p)?.style?.colorMode || 'theme';
  const lastVideo = p.tracks.reduce((a, t, i) => (t.kind === 'video' ? i : a), -1);
  let tt = p.tracks.find((x) => x.kind === 'overlay' && x.theme);
  if (!tt) { tt = Object.assign(createTrack('overlay', 'Theme'), { theme: true }); cmds.push(ops.addTrack(tt, lastVideo + 1)); }
  const add = [];
  if (el === 'overlay') {
    const style = themeStyle(th, { intensity: opts.intensity || 'normal', colorMode: mode, beats: opts.beats || await beatsOf(ctx), faces: opts.faces || (opts.safe === false ? [] : await detectFaces(ctx)) }, ws);
    add.push({ trackId: tt.id, item: createItem('theme.overlay', { t0: 0, in: 0, out: r3(D), x: 0, y: 0, w: W, h: H, auto: TAG, name: 'Theme · ' + th.name, style }) });
  } else {
    const def = elementById(el); if (!def) throw new Error('No such element: ' + el);
    const style = (whole) => elementStyle(th.id, el, { colors: colorsFor(th, mode, ws), variant: opts.variant, whole, brand: ws?.name || null, logo: ws?.logo || null });
    const mk = (t0, dur, whole) => createItem('theme.el', { t0: r3(Math.max(0, t0)), in: 0, out: r3(dur), ...boxFor(el, W, H), auto: TAG, name: `${def.icon} ${def.label} · whole video · ${th.name}`, style: style(whole) });
    const lane = def.box === 'full' && el !== 'grade' ? (p.tracks.find((t) => t.kind === 'overlay' && !t.locked && !t.theme) || tt) : tt;
    if (def.whole === 'span') add.push({ trackId: lane.id, item: mk(0, D, 'span') });
    else if (def.whole === 'start') add.push({ trackId: lane.id, item: mk(0, Math.min(def.dur, D), 'start') });
    else if (def.whole === 'end') add.push({ trackId: lane.id, item: mk(Math.max(0, D - def.dur), Math.min(def.dur, D), 'end') });
    else if (def.whole === 'cuts') for (const c of cutsOf(p)) add.push({ trackId: lane.id, item: mk(c - def.dur / 2, def.dur, 'cuts') });
  }
  if (!add.length) return 0;
  cmds.push(ops.addItems(add, 'Whole video'));
  ctx.history.apply(ops.batch(`Whole video · ${elementById(el)?.label || 'Theme overlay'}`, cmds));
  return add.length;
}
export { ELEMENTS };
