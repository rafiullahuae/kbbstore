// KBB Studio · look · GPU transitions over a cut between two clips (A ends where B starts).
//
//   item.type 'look.transition' on the overlay track, active over [cut - dur/2, cut + dur/2]
//   style { kind: whip|zoom|flash|glitch|slide|spin|luma|burn|cover|dissolve, a: clipIdA, b: clipIdB, color }
//
// Drawn as a layer: the side that is on screen (A before the cut, B after) is what the frame already holds; the other
// side is that clip drawn by core/render.js's drawItem at its edge (A's last frame / B's first frame, "freeze handles")
// with the clip's own crop, zoom and look — then both go through gl.js's `trans` shader. Same path for preview and export.
import { registerLayer, drawItem } from '../core/render.js';
import { findItem, itemEnd, srcTime, findMedia, createItem, uid } from '../core/project.js';
import { MediaStore } from '../core/media.js';
import { ops } from '../core/history.js';
import { gl, TRANSITION_KINDS } from './gl.js';
import { segAt } from './vision.js';

export const KINDS = {
  whip: { label: 'Whip', dur: 0.36 }, zoom: { label: 'Zoom-through', dur: 0.5 }, flash: { label: 'Flash', dur: 0.3 },
  glitch: { label: 'Glitch', dur: 0.4 }, slide: { label: 'Slide', dur: 0.45 }, spin: { label: 'Spin', dur: 0.5 },
  luma: { label: 'Luma fade', dur: 0.8 }, burn: { label: 'Film burn', dur: 0.7 }, cover: { label: 'Object cover', dur: 0.6 },
  dissolve: { label: 'Dissolve', dur: 0.5 },
};
const FREEZE_LONG = 1280;
const FROZEN = new Map();   // `${mediaId}@${t}` -> ImageBitmap
const fkey = (mediaId, t) => `${mediaId}@${t.toFixed(3)}`;

/** the edge of each side: [{item, t (timeline), srcT}] for one transition */
function edges(project, tr) {
  const A = findItem(project, tr.style?.a)?.item, B = findItem(project, tr.style?.b)?.item;
  if (!A || !B) return null;
  const tA = itemEnd(A) - 1e-4, tB = B.t0 + 1e-4;
  return { A, B, cut: B.t0, tA, tB, sA: srcTime(A, tA), sB: srcTime(B, tB) };
}
const needs = (project) => {
  const out = [];
  for (const tr of project.tracks) for (const it of tr.items) {
    if (it.type !== 'look.transition' && it.type !== 'theme.trans') continue;   // themes/transitions.js uses the same freeze handles
    const e = edges(project, it); if (!e) continue;
    for (const [x, s] of [[e.A, e.sA], [e.B, e.sB]]) if (x.media) out.push({ media: x.media, t: s });
  }
  return out;
};

/** decode every freeze frame the project's transitions need (export awaits this; the editor runs it after edits) */
let queue = Promise.resolve();
export function ensureFreezeFrames(project) {
  const run = queue.then(() => decodeFreezeFrames(project));
  queue = run.catch(() => {});
  return run;
}
async function decodeFreezeFrames(project) {
  const want = needs(project).filter((n) => !FROZEN.has(fkey(n.media, n.t)));
  if (!want.length) return false;
  const byMedia = new Map();
  for (const n of want) { if (!byMedia.has(n.media)) byMedia.set(n.media, []); byMedia.get(n.media).push(n.t); }
  for (const [id, ts] of byMedia) {
    const m = findMedia(project, id); if (!m || m.kind === 'audio') continue;
    const r = await new MediaStore({ getProject: () => project, engine: null }).openReader(id);
    try {
      for (const t of [...new Set(ts)].sort((a, b) => a - b)) {
        const f = await r.frame(m.kind === 'image' ? 0 : t);
        const sw = f.displayWidth || f.videoWidth || f.naturalWidth || f.width, sh = f.displayHeight || f.videoHeight || f.naturalHeight || f.height;
        const k = Math.min(1, FREEZE_LONG / Math.max(sw, sh));
        FROZEN.set(fkey(id, t), await createImageBitmap(f, { resizeWidth: Math.round(sw * k), resizeHeight: Math.round(sh * k), resizeQuality: 'high' }));
      }
    } finally { r?.close(); }
  }
  return true;
}

let off = null;

registerLayer('look.transition', (ctx, item, t, project, env) => {
  const e = edges(project, item); const g = gl();
  if (!e || !g) return;
  const dur = Math.max(0.05, item.out - item.in), p = Math.max(0, Math.min(1, 0.5 + (t - e.cut) / dur));
  const kind = Math.max(0, TRANSITION_KINDS.indexOf(item.style.kind));
  const before = t < e.cut, other = before ? e.B : e.A, oT = before ? e.tB : e.tA, oS = before ? e.sB : e.sA;
  const frozen = other.media ? FROZEN.get(fkey(other.media, oS)) : null;
  const cv = ctx.canvas, CW = cv.width, CH = cv.height;
  if (!off) off = document.createElement('canvas');
  if (off.width !== CW || off.height !== CH) { off.width = CW; off.height = CH; }
  const o = off.getContext('2d');
  o.setTransform(1, 0, 0, 1, 0, 0); o.globalAlpha = 1; o.filter = 'none';
  o.fillStyle = project.canvas.bg || '#000'; o.fillRect(0, 0, CW, CH);
  if (frozen) {
    o.setTransform(env.scale, 0, 0, env.scale, 0, 0);
    const track = project.tracks.find((tr) => tr.items.includes(other));
    drawItem(o, other, oT, project, Object.assign({}, env, { track, source: () => frozen }));
  } else if (env.preview) requestFreeze(project);
  const live = g.upload('tLive', cv, CW, CH), still = g.upload('tStill', off, CW, CH);
  const u = { p, kind, res: [CW, CH], seed: (item.id.charCodeAt(item.id.length - 1) % 17) + 1, col: hexRgb(item.style.color || '#ffffff'), hasM: 0, mc: [0.5, 0.5] };
  const tex = { A: before ? live : still, B: before ? still : live };
  if (item.style.kind === 'cover' && e.A.media) {
    const m = segAt(e.A.media, e.sA);
    const c = m && maskCentre(m);
    if (c && c[2] > 0.02) { tex.M = g.uploadR8('tMask', m.data, m.w, m.h); u.hasM = 1; u.mc = [c[0], c[1]]; }   // else: a round cover from the centre
  }
  g.size(CW, CH);
  g.run('trans', null, u, tex);
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.globalAlpha = 1; ctx.filter = 'none'; ctx.globalCompositeOperation = 'source-over';
  ctx.drawImage(g.canvas, 0, 0, CW, CH);
  ctx.restore();
});

const hexRgb = (c) => { const m = /^#?([0-9a-f]{6})$/i.exec(c); const n = m ? parseInt(m[1], 16) : 0xffffff; return [(n >> 16 & 255) / 255, (n >> 8 & 255) / 255, (n & 255) / 255]; };
const centres = new WeakMap();
function maskCentre(m) {
  if (centres.has(m.data)) return centres.get(m.data);
  let sx = 0, sy = 0, s = 0;
  for (let y = 0; y < m.h; y++) for (let x = 0; x < m.w; x++) { const v = m.data[y * m.w + x]; if (v > 127) { sx += x; sy += y; s++; } }
  const c = s ? [sx / s / m.w, sy / s / m.h, s / (m.w * m.h)] : [0.5, 0.5, 0]; centres.set(m.data, c); return c;
}

let pendingFreeze = null;
export function requestFreeze(project) {
  if (pendingFreeze) return;
  pendingFreeze = ensureFreezeFrames(project).catch((err) => console.warn('[look] freeze frames:', err.message)).finally(() => { pendingFreeze = null; window.kbbEditor?.preview?.draw(); });
}

// ── commands ──

/** cuts on the main video track: [{a, b, t}] where clip a ends and clip b starts (within one frame) */
export function cutsOf(project) {
  const vt = project.tracks.find((tr) => tr.kind === 'video'); if (!vt) return [];
  const list = vt.items.filter((i) => i.type === 'clip').slice().sort((x, y) => x.t0 - y.t0), out = [];
  for (let i = 0; i < list.length - 1; i++) if (Math.abs(itemEnd(list[i]) - list[i + 1].t0) < 0.05) out.push({ a: list[i], b: list[i + 1], t: list[i + 1].t0 });
  return out;
}

/** add (or replace) a transition of `kind` on every cut, or on the cut nearest `at` */
export function transitionsCommand(project, kind, { at = null, dur = null, color } = {}) {
  let cuts = cutsOf(project);
  if (at != null && cuts.length) cuts = [cuts.reduce((b, c) => (Math.abs(c.t - at) < Math.abs(b.t - at) ? c : b))];
  if (!cuts.length) return null;
  const ot = project.tracks.find((tr) => tr.kind === 'overlay') || project.tracks[0];
  const d = dur || KINDS[kind]?.dur || 0.4;
  const old = [];
  for (const tr of project.tracks) for (const it of tr.items) if (it.type === 'look.transition' && cuts.some((c) => it.style?.a === c.a.id && it.style?.b === c.b.id)) old.push(it.id);
  const items = cuts.map((c) => {
    const half = Math.min(d / 2, Math.max(0.05, (itemEnd(c.a) - c.a.t0) / 2), Math.max(0.05, (itemEnd(c.b) - c.b.t0) / 2));
    return createItem('look.transition', { t0: +(c.t - half).toFixed(4), in: 0, out: +(half * 2).toFixed(4), x: 0, y: 0, w: project.canvas.w, h: project.canvas.h, name: 'Transition · ' + (KINDS[kind]?.label || kind), style: { kind, a: c.a.id, b: c.b.id, color: color || '#ffffff' } });
  });
  return ops.batch(`${KINDS[kind]?.label || kind} transition${items.length > 1 ? 's' : ''}`, [old.length ? ops.removeItems(old) : null, ops.addItems(items.map((item) => ({ trackId: ot.id, item })))]);
}

/** match-cut helper: move B's in-point (±1 s) to the frame that looks most like A's last frame; returns a command */
export async function matchCutCommand(project, cut, { search = 1, step = 1 / 15 } = {}) {
  const { a, b } = cut; const mA = findMedia(project, a.media), mB = findMedia(project, b.media);
  if (!mA || !mB || mB.kind !== 'video') return null;
  const store = new MediaStore({ getProject: () => project, engine: null });
  const thumb = async (r, t) => { const f = await r.frame(t); const bm = await createImageBitmap(f, { resizeWidth: 32, resizeHeight: 32, resizeQuality: 'medium' }); const c = new OffscreenCanvas(32, 32).getContext('2d'); c.drawImage(bm, 0, 0); bm.close(); return c.getImageData(0, 0, 32, 32).data; };
  const rA = await store.openReader(a.media); const ref = await thumb(rA, srcTime(a, itemEnd(a) - 1e-3)); rA.close();
  const rB = await store.openReader(b.media);
  let best = { d: Infinity, t: b.in };
  const lo = Math.max(0, b.in - search), hi = Math.min(mB.dur - 0.1, b.in + search);
  for (let t = lo; t <= hi; t += step) {
    const px = await thumb(rB, t); let d = 0;
    for (let i = 0; i < px.length; i += 4) { const dl = (px[i] + px[i + 1] + px[i + 2]) - (ref[i] + ref[i + 1] + ref[i + 2]); d += dl * dl; }
    if (d < best.d) best = { d, t };
  }
  rB.close();
  const shift = best.t - b.in;
  if (Math.abs(shift) < step / 2) return { cmd: null, shift: 0 };
  const out = Math.min(mB.dur, b.out + shift);
  return { cmd: ops.setItem(b.id, { in: +best.t.toFixed(4), out: +out.toFixed(4) }, 'Match cut'), shift };
}

export { uid };

/**
 * For a transition item of any kind (themes/transitions.js): progress p (0..1, the cut at 0.5), which side is live, and
 * the OTHER side drawn into its own offscreen canvas at the frame's device size — A's last frame after the cut, B's first
 * frame before it (freeze handles, decoded ahead like look.transition's). A clip without media (the drawn sample in the
 * hover previews) is drawn through env.source. Returns null when the item has lost its clips.
 */
const sides = new Map();
export function transitionSides(ctx, item, t, project, env) {
  const e = edges(project, item); if (!e) return null;
  const dur = Math.max(0.05, (item.out - item.in) / (item.speed || 1)), p = Math.max(0, Math.min(1, 0.5 + (t - e.cut) / dur));
  const before = t < e.cut, other = before ? e.B : e.A, oT = before ? e.tB : e.tA, oS = before ? e.sB : e.sA;
  const cv = ctx.canvas, CW = cv.width, CH = cv.height, key = item.id;
  let o = sides.get(key);
  if (!o) { o = document.createElement('canvas'); sides.set(key, o); if (sides.size > 6) sides.delete(sides.keys().next().value); }
  if (o.width !== CW || o.height !== CH) { o.width = CW; o.height = CH; }
  const g = o.getContext('2d');
  g.setTransform(1, 0, 0, 1, 0, 0); g.globalAlpha = 1; g.filter = 'none'; g.fillStyle = project.canvas.bg || '#000'; g.fillRect(0, 0, CW, CH);
  const track = project.tracks.find((tr) => tr.items.includes(other)), frozen = other.media ? FROZEN.get(fkey(other.media, oS)) : null;
  g.setTransform(env.scale, 0, 0, env.scale, 0, 0);
  if (frozen) drawItem(g, other, oT, project, Object.assign({}, env, { track, source: () => frozen }));
  else if (!other.media) drawItem(g, other, oT, project, Object.assign({}, env, { track }));
  else if (env.preview) requestFreeze(project);
  return { p, before, other: o, cut: e.cut, ready: !!frozen || !other.media };
}
