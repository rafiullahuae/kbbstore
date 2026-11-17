// KBB Studio · themes · COUNTRY TRANSITIONS: full-screen switches from one clip to the next, in a theme's colours and
// with its country's own things — the flag sweeping across as cloth, a zoom into the globe, a plane and its contrail, a
// passport page turning, the map outline and the landmark silhouette opening onto the next clip, colour slabs pushing
// through, a boarding pass tearing in two.
//
//   item.type 'theme.trans' (overlay track) over [cut − d/2, cut + d/2]
//   style { theme, kind, a: clipIdA, b: clipIdB, colors, whole? }
//
// Both clips show: the outgoing clip (what the frame holds before the cut, A's last frame after it) → the graphic → the
// incoming clip (B's first frame before the cut, what the frame holds after it). The other side comes from the look
// module's freeze handles (look/transitions.js), so preview and export draw the same pixels.
import { registerLayer } from '../core/render.js';
import { createItem, createTrack, itemEnd } from '../core/project.js';
import { ops } from '../core/history.js';
import { transitionSides } from '../look/transitions.js';
import * as A from './art.js';
import { kitFor } from './kit.js';
import { themeById } from './themes.js';
import { flagImage, mapPaths } from './draw.js';
import { HERO } from './landmarks2.js';
import { LANDMARKS } from '../auto/countries/landmarks.js';

const { clamp, easeIO, easeOut, easeIn, TAU, mix, rgba } = A;

export const TRANSITIONS = [
  { id: 'flag', label: 'Flag sweep', icon: '🏳️', dur: 0.9, note: 'The flag waves across as cloth' },
  { id: 'globe', label: 'Globe zoom', icon: '🌍', dur: 1.1, note: 'Zoom into the globe, land on the next clip' },
  { id: 'plane', label: 'Plane + contrail', icon: '✈️', dur: 0.9, note: 'A plane flies through, its contrail wipes' },
  { id: 'page', label: 'Passport page turn', icon: '🛂', dur: 0.8, note: 'A passport page turns over' },
  { id: 'map', label: 'Map iris', icon: '🗺', dur: 1.0, note: 'The country outline zooms open' },
  { id: 'slabs', label: 'Colour-slab push', icon: '🟥', dur: 0.6, note: 'Flag-colour slabs push through' },
  { id: 'landmark', label: 'Landmark reveal', icon: '🏛', dur: 1.0, note: 'The landmark silhouette opens onto the next clip' },
  { id: 'ticket', label: 'Boarding-pass tear', icon: '🎫', dur: 0.8, note: 'A boarding pass slams in and tears' },
];
export const transitionById = (id) => TRANSITIONS.find((x) => x.id === id) || null;

/** the transition a theme uses on its cuts */
export function transitionOf(th) {
  if (!th) return 'slabs';
  if (th.group === 'Countries') return ['flag', 'map', 'landmark', 'globe', 'slabs'][(th.country || 'xx').charCodeAt(0) % 5];
  return { europe: 'flag', travel: 'plane', passport: 'page', study: 'slabs', 'work-trades': 'slabs', 'work-care': 'slabs', 'work-hospitality': 'ticket', 'work-abroad': 'plane' }[th.id] || 'slabs';
}

// ── masks in canvas units ──
const SIL = new Map();
function silhouette(id) {   // every part of a landmark as one path (200 box, standing on y = 200)
  if (SIL.has(id)) return SIL.get(id);
  const P = new Path2D(), H = HERO[id];
  if (H) for (const [, d, k] of H.parts) { if (k === 'b') { const [cx, cy, r] = d.split(' ').map(Number); P.moveTo(cx + r, cy); P.arc(cx, cy, r, 0, TAU); } else P.addPath(A.path2d(d)); }
  else if (LANDMARKS[id]) for (const d of LANDMARKS[id].d || []) P.addPath(A.path2d(d));
  else P.rect(60, 60, 80, 140);
  SIL.set(id, P); return P;
}
/** the incoming region at progress p for the mask kinds (a path in canvas units), or null for the cover kinds */
function maskPath(kind, K, p, W, H) {
  const u = Math.min(W, H);
  if (kind === 'map' || kind === 'landmark') {
    const paths = kind === 'map' && K.code ? mapPaths(K.code) : null;
    const s = (u / 200) * 0.25 * Math.pow(60, easeIn(p));   // a small shape that zooms until it holds the whole frame
    const m = new DOMMatrix().translate(W / 2, H * (kind === 'landmark' ? 0.62 : 0.5)).scale(s, s).translate(-100, kind === 'landmark' ? -185 : -100);
    const out = new Path2D();
    if (kind === 'map' && paths && paths.length) for (const q of paths) out.addPath(q, m);
    else if (kind === 'landmark') out.addPath(silhouette(K.landmark || (K.skyline || [])[0] || 'big-ben'), m);
    else { const r = u * 0.12 * Math.pow(14, easeIn(p)); out.arc(W / 2, H / 2, r, 0, TAU); }
    return out;
  }
  return null;
}

// ── drawing ──
function drawTrans(ctx, item, t, project, env) {
  const sides = transitionSides(ctx, item, t, project, env); if (!sides) return;
  const s = item.style || {}, K = kitFor(themeById(s.theme) ? s.theme : project.tracks.flatMap((x) => x.items).find((i) => i.type === 'theme.overlay')?.style?.theme || 'travel', s.colors || null);
  const W = project.canvas.w, H = project.canvas.h, u = Math.min(W, H), { p, before } = sides, kind = s.kind || 'slabs';
  const otherOn = (clipPath, inverse) => {   // draw the other side through a clip (device px canvas, drawn in canvas units)
    ctx.save();
    const path = new Path2D(); if (inverse) path.rect(-W, -H, W * 3, H * 3); path.addPath(clipPath);
    ctx.clip(path, inverse ? 'evenodd' : 'nonzero');
    ctx.drawImage(sides.other, 0, 0, W, H); ctx.restore();
  };
    // mask kinds: before the cut the incoming clip shows inside the shape; after it the outgoing one shows outside it
  const mask = maskPath(kind, K, p, W, H);
  if (mask) {
    if (before) otherOn(mask, false); else if (p < 0.97) otherOn(mask, true);
    ctx.save(); ctx.lineJoin = 'round'; ctx.strokeStyle = '#ffffff'; ctx.lineWidth = u * 0.012; ctx.shadowColor = rgba(K.colors.accent === '#ffffff' ? '#ffd166' : K.colors.accent, 0.9); ctx.shadowBlur = u * 0.03;
    ctx.globalAlpha *= clamp((1 - p) * 4) * clamp(p * 8); ctx.stroke(mask); ctx.restore();
    A.glint(ctx, W * 0.5, H * 0.5, u * 0.12 * Math.sin(Math.PI * p), Math.sin(Math.PI * p) * 0.8, '#ffffff');
    return;
  }
  const img = K.code ? flagImage(K.code) : K.flagKind === 'stars' ? A.bandFlag(K.flag, 'stars') : A.emblem(K);
  if (kind === 'slabs') {
    // flag-colour slabs push across; the incoming clip rides in behind the last slab
    A.slabWipe(ctx, W, H, [...K.slabs, K.colors.accent], p, { dir: 1, skew: 0.2 });
    return;
  }
  if (kind === 'flag') {
    // a flag as tall as the frame sweeps left → right; behind its trailing edge the next clip is already there
    const fw = W * 1.25, fh = H * 1.08, x = -fw - W * 0.1 + (W + fw + W * 0.2) * p, y = -H * 0.04;
    const trail = x + fw * 0.12;
    if (before && trail > 0) { const r = new Path2D(); r.rect(-W, -H, trail + W, H * 3); otherOn(r, false); }
    if (!before && trail < W) { const r = new Path2D(); r.rect(trail, -H, W * 2, H * 3); otherOn(r, false); }
    ctx.save(); A.flagCloth(ctx, img, K.flag, x, y, fw, fh, t * 1.6, { pole: false, strips: 26, amp: 0.035, waves: 1.6, speed: 5 }); ctx.restore();
    A.gradRect(ctx, x - W * 0.06, 0, W * 0.07, H, [[0, 'rgba(0,0,0,0)'], [1, 'rgba(0,0,0,0.35)']], 'h');
    return;
  }
  if (kind === 'plane') {
    // a plane crosses on a diagonal; its contrail swells until it fills the frame at the cut, then thins away over B
    const k = easeIO(p), px = -W * 0.2 + W * 1.4 * k, py = H * (0.8 - 0.6 * k), ang = Math.atan2(-0.6 * H, 1.4 * W);
    const width = u * (0.05 + 3.2 * Math.sin(Math.PI * clamp(p * 1.0)) ** 2), a = p < 0.5 ? 1 : 1 - easeIn((p - 0.5) / 0.5);
    ctx.save(); ctx.globalAlpha *= a; ctx.lineCap = 'round';
    for (const [lw, al] of [[1, 0.55], [0.75, 0.75], [0.5, 1]]) { ctx.strokeStyle = rgba('#ffffff', al); ctx.lineWidth = width * lw; ctx.beginPath(); ctx.moveTo(-W * 0.3, H * 0.86); ctx.lineTo(px - Math.cos(ang) * u * 0.08, py - Math.sin(ang) * u * 0.08); ctx.stroke(); }
    ctx.globalAlpha = clamp(Math.sin(Math.PI * p) * 1.6) * 0.5; A.lightLeaks(ctx, W, H, t, [K.colors.accent, '#9fd3ff'], 1, 2);
    ctx.restore();
    A.plane(ctx, px, py, u * 0.22, ang, '#ffffff');
    return;
  }
  if (kind === 'page') {
    // a passport page turns over from the right edge: it covers the frame at the cut, then lifts away to the left
    const k = p < 0.5 ? easeOut(p / 0.5) : 1 - easeIn((p - 0.5) / 0.5);
    const x0 = p < 0.5 ? W * (1 - k) : 0, x1 = p < 0.5 ? W : W * k;
    if (x1 - x0 > 1) {
      const P = K.colors;
      ctx.save(); ctx.beginPath(); ctx.rect(x0, 0, x1 - x0, H); ctx.clip();
      A.gradRect(ctx, 0, 0, W, H, [[0, '#fbf6ea'], [1, '#e8dcc2']]);
      ctx.strokeStyle = rgba(P.primary, 0.12); ctx.lineWidth = u * 0.003;
      for (let i = 0; i < 18; i++) { ctx.beginPath(); for (let q = 0; q <= 30; q++) { const X = (q / 30) * W, Y = H * (0.05 + i * 0.055) + Math.sin(q / 30 * TAU * 2 + i) * H * 0.02; q ? ctx.lineTo(X, Y) : ctx.moveTo(X, Y); } ctx.stroke(); }
      A.uiText(ctx, 'VISAS', W / 2, H * 0.1, u * 0.06, { col: rgba(P.primary, 0.5), align: 'center', weight: 800, track: 0.3 });
      A.stampMark(ctx, W * 0.5, H * 0.42, u * 0.22, (K.city || K.name || '').toUpperCase(), K.iata || '✈', mix(P.primary, '#1a1a6b', 0.3), -0.2, 0.85, 'round');
      A.stampMark(ctx, W * 0.5, H * 0.66, u * 0.16, 'APPROVED', 'DD · MM · YYYY', '#1f9d55', 0.1, 0.85, 'rect');
      ctx.restore();
      const ex = p < 0.5 ? x0 : x1, dir = p < 0.5 ? 1 : -1;   // the curling edge: a highlight and a cast shadow
      A.gradRect(ctx, ex - (dir > 0 ? 0 : u * 0.08), 0, u * 0.08, H, dir > 0 ? [[0, 'rgba(255,255,255,0.9)'], [1, 'rgba(0,0,0,0.25)']] : [[0, 'rgba(0,0,0,0.25)'], [1, 'rgba(255,255,255,0.9)']], 'h');
      A.gradRect(ctx, dir > 0 ? ex - u * 0.12 : ex, 0, u * 0.12, H, dir > 0 ? [[0, 'rgba(0,0,0,0)'], [1, 'rgba(0,0,0,0.35)']] : [[0, 'rgba(0,0,0,0.35)'], [1, 'rgba(0,0,0,0)']], 'h');
    }
    return;
  }
  if (kind === 'globe') {
    // the globe swells from the middle until it fills the frame at the cut; the next clip opens from the destination pin
    const R = u * 0.18 * Math.pow(9, easeIn(clamp(p / 0.5))), lon0 = K.to[0] - 30 * (1 - p), lat0 = K.to[1] * 0.6;
    if (!before) { const r = Math.hypot(W, H) * easeOut((p - 0.5) / 0.5); const hole = new Path2D(); hole.arc(W / 2, H / 2, Math.max(0.1, r), 0, TAU); ctx.save(); const inv = new Path2D(); inv.rect(-W, -H, W * 3, H * 3); inv.addPath(hole); ctx.clip(inv, 'evenodd'); }
    A.globe(ctx, W / 2, H / 2, Math.max(R, before ? 0 : Math.hypot(W, H) * 0.75), { lon0, lat0, pal: { ocean: '#1f7ae0', ocean2: '#041b4d', land: '#f4f8ff', arc: K.colors.accent === '#ffffff' ? '#ffd166' : K.colors.accent, glow: '#8fd8ff' }, from: K.from, to: K.to, prog: clamp(p * 2), labels: [K.fromCode, K.iata], t });
    if (!before) ctx.restore();
    if (!before) { const r = Math.hypot(W, H) * easeOut((p - 0.5) / 0.5); ctx.save(); ctx.strokeStyle = '#ffffff'; ctx.lineWidth = u * 0.012; ctx.globalAlpha *= 1 - p; ctx.beginPath(); ctx.arc(W / 2, H / 2, r, 0, TAU); ctx.stroke(); ctx.restore(); }
    return;
  }
  if (kind === 'ticket') {
    // a giant boarding pass slams in (turned to fill a tall frame), then tears along its perforation
    const land = W > H, cw = land ? W * 1.04 : H * 1.04, ch = land ? H * 1.04 : W * 1.04;
    const draw = (half) => {
      ctx.save(); ctx.translate(W / 2, H / 2); if (!land) ctx.rotate(-Math.PI / 2);
      if (half) { ctx.beginPath(); if (half < 0) ctx.rect(-cw / 2, -ch / 2, cw * 0.72, ch); else ctx.rect(-cw / 2 + cw * 0.72, -ch / 2, cw * 0.28, ch); ctx.clip(); }
      A.boardingPass(ctx, -cw / 2, -ch / 2, cw, ch, K, t, { from: K.fromCode, to: K.iata });
      ctx.restore();
    };
    if (p < 0.5) { const k = easeOut(p / 0.42); ctx.save(); ctx.translate(0, (1 - k) * H * 1.1); ctx.translate(W / 2, H / 2); ctx.rotate((1 - k) * 0.25); ctx.translate(-W / 2, -H / 2); draw(0); ctx.restore(); }
    else {
      const k = easeIn((p - 0.5) / 0.5), dy = k * H * 1.2, rot = k * 0.35;
      for (const half of [-1, 1]) { ctx.save(); ctx.translate(W / 2, H / 2); ctx.rotate(half * rot * 0.5); ctx.translate(half * k * W * 0.3, half * dy * (land ? 0.3 : 1)); ctx.translate(-W / 2, -H / 2); draw(half); ctx.restore(); }
    }
  }
}
registerLayer('theme.trans', (ctx, item, t, project, env) => {
  ctx.save(); ctx.translate(-env.box.x, -env.box.y);
  try { drawTrans(ctx, item, t, project, env); } finally { ctx.restore(); }
});

// ── placing ──
/** cuts on the main video track: [{a, b, t}] */
export function cutsOf(project) {
  const vt = project.tracks.find((tr) => tr.kind === 'video'); if (!vt) return [];
  const list = vt.items.filter((i) => i.type === 'clip').slice().sort((x, y) => x.t0 - y.t0), out = [];
  for (let i = 0; i < list.length - 1; i++) if (Math.abs(itemEnd(list[i]) - list[i + 1].t0) < 0.05) out.push({ a: list[i], b: list[i + 1], t: list[i + 1].t0 });
  return out;
}
/** a theme.trans item for one cut (half its length each side, never longer than either clip allows) */
export function transItem(project, cut, kind, { theme, colors, dur, auto, whole } = {}) {
  const d = dur || transitionById(kind)?.dur || 0.8, half = Math.min(d / 2, Math.max(0.1, (itemEnd(cut.a) - cut.a.t0) / 2), Math.max(0.1, (itemEnd(cut.b) - cut.b.t0) / 2));
  const tr = transitionById(kind);
  return createItem('theme.trans', { t0: +(cut.t - half).toFixed(4), in: 0, out: +(half * 2).toFixed(4), x: 0, y: 0, w: project.canvas.w, h: project.canvas.h, ...(auto ? { auto } : {}),
    name: `${tr?.icon || '🔀'} ${tr?.label || kind}${theme && themeById(theme) ? ' · ' + themeById(theme).name : ''}`, style: { theme, kind, a: cut.a.id, b: cut.b.id, colors: colors || null, whole: whole || false } });
}
/**
 * Put a country transition on the cut nearest `at` (or on every cut with all: true), replacing whatever theme or look
 * transition sat on that cut. ONE undo step; returns the items placed.
 */
export function placeTransition(ctx, kind, { at = null, all = false, theme, dur, auto, whole } = {}) {
  const p = ctx.project; let cuts = cutsOf(p); if (!cuts.length) throw new Error('No cut on the timeline yet: put two clips end to end first');
  if (!all) { const t = at ?? 0; cuts = [cuts.reduce((b, c) => (Math.abs(c.t - t) < Math.abs(b.t - t) ? c : b))]; }
  const tid = theme || p.tracks.flatMap((x) => x.items).find((i) => i.type === 'theme.overlay')?.style?.theme || 'travel';
  const old = [];
  for (const tr of p.tracks) for (const it of tr.items) if ((it.type === 'theme.trans' || it.type === 'look.transition') && cuts.some((c) => it.style?.a === c.a.id && it.style?.b === c.b.id)) old.push(it.id);
  for (const tr of p.tracks) for (const it of tr.items) if (it.type === 'theme.el' && it.style?.el === 'wipe' && cuts.some((c) => Math.abs(it.t0 + (it.out - it.in) / 2 - c.t) < 0.05)) old.push(it.id);
  const cmds = []; let ot = p.tracks.find((x) => x.kind === 'overlay' && !x.locked && !x.theme);
  if (!ot) { ot = createTrack('overlay'); cmds.push(ops.addTrack(ot, p.tracks.reduce((a, t2, i) => (t2.kind === 'video' ? i : a), -1) + 1)); }
  const items = cuts.map((c) => transItem(p, c, kind, { theme: tid, dur, auto, whole }));
  if (old.length) cmds.push(ops.removeItems(old, 'Replace transition'));
  cmds.push(ops.addItems(items.map((item) => ({ trackId: ot.id, item })), 'Transition'));
  ctx.history.apply(ops.batch(`${transitionById(kind)?.label || kind}${items.length > 1 ? ` on ${items.length} cuts` : ''}`, cmds));
  return items;
}
/** a transition's new length, kept centred on its cut */
export function setTransitionDuration(ctx, id, d) {
  const it = ctx.project.tracks.flatMap((t) => t.items).find((i) => i.id === id); if (!it) return;
  const cut = cutsOf(ctx.project).find((c) => c.a.id === it.style?.a && c.b.id === it.style?.b), mid = cut ? cut.t : it.t0 + (it.out - it.in) / 2;
  const dd = Math.max(0.2, Math.min(2.5, d));
  ctx.history.apply(ops.setItems([{ id, props: { t0: +(mid - dd / 2).toFixed(4), in: 0, out: +dd.toFixed(4) } }], 'Transition length'));
}
