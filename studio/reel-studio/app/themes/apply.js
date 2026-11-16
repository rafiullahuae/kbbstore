// KBB Studio · themes · apply / tune / remove a theme. Applying is ONE undoable step (ops.batch): the overlay that runs
// through the whole reel, the intro sting, the outro, a transition on every cut, the themed title and the theme's
// sounds. Everything it places carries item.auto = 'theme', so applying another theme replaces it and never touches the
// owner's own items (or Auto reel's).
import { ops } from '../core/history.js';
import { createItem, createTrack, projectDuration, itemEnd, findMedia, clone } from '../core/project.js';
import { themeById } from './themes.js';
import { textItem } from '../look/text.js';
import { libraryMedia } from '../auto/commands.js';

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
  const D = projectDuration(p) || 10;
  const beats = opts.beats || await beatsOf(ctx);
  const faces = opts.faces || (opts.safe === false ? [] : await detectFaces(ctx));
  const style = themeStyle(th, { ...opts, beats, faces }, ws);
  const cmds = [], placed = { overlay: null, sting: null, outro: null, transitions: 0, title: null, sfx: 0 };
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
  const kit = { colors: style.colors, hero: th.sting?.hero, icons: (th.layers.find((l) => l.kind === 'icons')?.icons || []).slice(0, 1) };
  if (opts.sting !== false && D >= 3) { const it = createItem('theme.sting', { t0: 0, in: 0, out: 1.1, x: 0, y: 0, w: W, h: H, auto: TAG, name: 'Theme intro · ' + name, style: clone(kit) }); add.push({ trackId: ot.id, item: it }); placed.sting = it.id; }
  if (opts.outro !== false && D >= 5) { const it = createItem('theme.outro', { t0: r3(D - 1.4), in: 0, out: 1.4, x: 0, y: 0, w: W, h: H, auto: TAG, name: 'Theme outro · ' + name, style: clone(kit) }); add.push({ trackId: ot.id, item: it }); placed.outro = it.id; }
  const cuts = opts.transitions === false ? [] : cutsOf(p);
  const owned = p.tracks.flatMap((t) => t.items).filter((i) => /transition/.test(i.type) && i.auto !== TAG);
  for (const c of cuts) {
    if (owned.some((i) => c >= i.t0 - 0.05 && c <= itemEnd(i) + 0.05)) continue;   // the owner's own transition stays
    add.push({ trackId: ot.id, item: createItem('auto.transition', { t0: r3(Math.max(0, c - 0.2)), in: 0, out: 0.4, x: 0, y: 0, w: W, h: H, auto: TAG, name: 'Theme transition · ' + th.transition, style: { kind: th.transition, color: th.transition === 'slide' || th.transition === 'wipe' ? style.colors.primary : '#ffffff', accent: style.colors.accent } }) });
    placed.transitions++;
  }
  if (opts.title !== false && th.text?.title) {
    const lang = p.captions?.lang === 'ar' ? 'ar' : 'en', txt = typeof opts.title === 'string' ? opts.title : lang === 'ar' && th.text.titleAr && th.text.preset !== 'bilingual' ? th.text.titleAr : th.text.title;
    const t0 = placed.sting ? 1.05 : 0.3;
    if (D > t0 + 2) {
      const it = textItem(th.text.preset, p.canvas, { ...ws, colors: { primary: style.colors.primary, accent: style.colors.accent, text: '#ffffff' } }, t0, { text: txt, auto: TAG, name: 'Theme title · ' + txt });
      if (th.text.preset === 'bilingual' && th.text.titleAr) it.style.sub = th.text.titleAr;
      await placeTitle(p, it, faces);
      add.push({ trackId: track('text').id, item: it }); placed.title = it.id;
    }
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
    if (placed.title) put(th.sfx.accent, (placed.sting ? 1.05 : 0.3) + 0.1, 0.45, 'title');
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
  const D = projectDuration(ctx.project);
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
export async function resyncBeats(ctx) { const beats = await beatsOf(ctx); tuneTheme(ctx, { beats }, 'Theme beats'); return beats.length; }
