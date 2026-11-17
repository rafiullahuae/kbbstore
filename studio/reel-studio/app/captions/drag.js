// Caption positioning on the canvas, the way Premiere / CapCut / Final Cut do it.
//
//  * Drag a caption block on the preview: it moves THAT line (line.ov.pos), on whichever caption track drew it — the
//    main captions or a translated second track (item.capTrack, captions.tracks[lang]). Shift (or the panel's
//    "Drag moves: All lines") moves every line of that track: the style's position and every line placed on its own.
//    ⌘/Ctrl-click a caption, or ⌘/Shift-click lines in the transcript, to pick several lines; dragging one of them
//    moves them all by the same amount.
//  * Hover shows the block's box with a move cursor; the picked lines keep an outline with corner handles; the first
//    time, a one-line hint says how it works.
//  * While dragging, the box snaps to the centre lines, the thirds, the platform safe box (render.js safeArea, the box
//    the renderer keeps captions inside), other lines' edges and a detected face's box; a caption over the face is
//    tinted as a warning. Alt/Option drags freely. Guides show only during the drag (editor/preview.js showGuides).
//  * Arrow keys nudge the picked lines 1 px (Shift: 10 px) while the preview has focus; right-click a caption for
//    "Reset position" (this line / picked lines / every line). One undo step per drag, and per nudge burst (commands
//    coalesce on a key within core/history.js COALESCE_MS).
//  * Each shape keeps its own layout (captions.layouts[aspect]): switching 9:16 → 16:9 stashes the 9:16 positions and
//    restores 16:9's, or maps them across the first time (same place in the frame, kept inside the new safe box). The
//    multi-shape export re-lays a copy the same way (editor/items.js reflowTo → core/modules.js runReflows), so each
//    export uses its own shape's positions.
// Positions are safe-area fractions (pos.x, pos.y = where the block's centre sits), exactly what render.js reads, so
// the exported frame shows a line where it was dropped. Nothing here measures the DOM: hit tests use the renderer's
// own layout (captionBlocks) on a scratch canvas, and there are no timers.

import { registerCanvasGrab, registerCanvasOverlay, registerCanvasHover, registerCanvasMenu, registerCanvasKey, registerReflow } from '../core/modules.js';
import { activeItems, boxAt } from '../core/render.js';
import { srcTime, findItem } from '../core/project.js';
import { bus } from '../core/bus.js';
import { frameTargets, boxTargets, mergeTargets, snapMove, overlaps } from '../core/guides.js';
import { captionBlocks, safeArea, captionClock } from './render.js';
import { resolveStyle } from './styles.js';
import { captionsCommand } from './commands.js';

export const dragPrefs = { scope: 'line' };   // 'line' | 'all' (the panel's toggle)
export const HINT = 'Drag to move this line · Shift-drag moves all lines';
const TRACK_POS = { x: 0.5, y: 0.08 };         // a translated second track's own default (translate/commands.js trackStyle)
const SNAP_PX = 7;                              // screen px
const SLACK = 12;                               // canvas px around a block that still grabs it
const HINT_KEY = 'kbb.capMoveHint';

let mctx = null;
const measureCtx = () => (mctx ||= (typeof OffscreenCanvas === 'function' ? new OffscreenCanvas(8, 8) : document.createElement('canvas')).getContext('2d'));
const clamp01 = (v) => (v < 0 ? 0 : v > 1 ? 1 : v);
const r4 = (v) => +(+v).toFixed(5);   // 1e-5 of the safe box: well under 0.05 px
/** keep a box inside its limits: the delta (dx, dy) clamped, and which axes hit an edge */
function keepIn(box, lim, dx, dy) {
  const cl = (d, lo, hi) => (lo > hi ? (lo + hi) / 2 : d < lo ? lo : d > hi ? hi : d);
  const nx = cl(dx, lim.x0 - box.x, lim.x1 - box.x - box.w), ny = cl(dy, lim.y0 - box.y, lim.y1 - box.y - box.h);
  return { dx: nx, dy: ny, ex: nx !== dx ? (nx > dx ? lim.x0 : lim.x1) : null, ey: ny !== dy ? (ny > dy ? lim.y0 : lim.y1) : null };
}
const inside = (b, p, s = SLACK) => p && p.x >= b.x - s && p.x <= b.x + b.w + s && p.y >= b.y - s && p.y <= b.y + b.h + s;
const dims = (project) => ({ W: project.canvas?.w || 1080, H: project.canvas?.h || 1920 });

// ── data ──
/** the captions object of a track: '' = the main captions, 'ar' = captions.tracks.ar */
export const capOf = (cap, lang) => (lang ? cap?.tracks?.[lang] : cap) || null;
export const lineKey = (lang, id) => (lang || '') + '|' + id;
export const parseKey = (k) => { const i = k.indexOf('|'); return { lang: k.slice(0, i), id: k.slice(i + 1) }; };

/** a line's position as the renderer resolves it (safe-area fractions; x 0.5 = centred) */
export function linePos(o, L) {
  const p = (L && L.ov && L.ov.pos) || resolveStyle(o.style).pos || {};
  return { x: p.x ?? 0.5, y: p.y ?? 0.72 };
}

/**
 * Set positions in one command: changes = [{ lang, id (a line id, or null = the track's style), pos {x,y} | null }].
 * pos null removes a line's own position (it follows the style again) or, for the style, goes back to the default.
 */
export const setPositions = (changes, label = 'Move caption', key) => captionsCommand('pos', label, (cap) => {
  const c = { ...cap }, touched = new Map();
  const get = (lang) => {
    if (touched.has(lang)) return touched.get(lang);
    let o = null;
    if (!lang) o = c;
    else if (cap.tracks?.[lang]) { c.tracks = { ...c.tracks }; o = c.tracks[lang] = { ...cap.tracks[lang] }; }
    if (o) o.lines = o.lines.slice();
    touched.set(lang, o);
    return o;
  };
  for (const ch of changes) {
    const o = get(ch.lang || ''); if (!o) continue;
    if (ch.id == null) {
      const spec = typeof o.style === 'string' ? { id: o.style } : { ...(o.style || { id: 'word-box' }) };
      if (ch.pos) spec.pos = { ...ch.pos }; else if (ch.lang) spec.pos = { ...TRACK_POS }; else delete spec.pos;
      o.style = spec;
      continue;
    }
    const k = o.lines.findIndex((L) => L.id === ch.id); if (k < 0) continue;
    const L = { ...o.lines[k] }, ov = { ...(L.ov || {}) };
    if (ch.pos) ov.pos = { ...ch.pos }; else delete ov.pos;
    if (Object.keys(ov).length) L.ov = ov; else delete L.ov;
    o.lines[k] = L;
  }
  return c;
}, key);

/** "Reset position": the given line keys follow the style again */
export const resetLines = (keys) => setPositions(keys.map((k) => ({ ...parseKey(k), pos: null })), keys.length > 1 ? 'Reset positions' : 'Reset position');
/** "Reset all lines": every line of a track (main: '') follows the style, and the style goes back to its default spot */
export function resetAll(cap, lang = '') {
  const o = capOf(cap, lang); if (!o) return null;
  return setPositions([{ lang, id: null, pos: null }, ...o.lines.filter((L) => L.ov?.pos).map((L) => ({ lang, id: L.id, pos: null }))], 'Reset all positions');
}

// ── selection (the lines the arrows / a multi-drag move) ──
export const posSel = new Set();
export function selectLines(keys, mode = 'replace') {
  if (mode === 'replace') { posSel.clear(); for (const k of keys) posSel.add(k); }
  else if (mode === 'add') for (const k of keys) posSel.add(k);
  else for (const k of keys) posSel.has(k) ? posSel.delete(k) : posSel.add(k);
  bus.emit('captions:possel', { keys: [...posSel] });
}
const clearSel = () => { if (posSel.size) selectLines([]); };
bus.on('project:load', clearSel);
bus.on('selection', ({ ids } = {}) => {
  const ed = typeof window !== 'undefined' ? window.kbbEditor : null;
  if (!ids?.length || !ed || !ids.every((id) => findItem(ed.project, id)?.item.type === 'caption')) clearSel();
});

// ── faces (app/auto emits its analysis; the face box is a snap target and a warning) ──
const faces = new Map();   // mediaId -> [{ t, x, y, w, h }] (source-frame fractions, x/y = centre)
bus.on('auto:analysis', (a) => { if (a?.mediaId) faces.set(a.mediaId, (a.subjects || []).filter((f) => f && f.x != null && f.w > 0 && f.h > 0)); });
/** the face box on the canvas at t (canvas px), or null when no face is known there */
export function faceBox(project, t) {
  const list = activeItems(project, t).reverse();
  for (const { track, item } of list) {
    if (item.type !== 'clip' || !item.media || !faces.get(item.media)?.length || track.kind === 'caption') continue;
    const s = srcTime(item, t); let f = null;
    for (const x of faces.get(item.media)) if (!f || Math.abs(x.t - s) < Math.abs(f.t - s)) f = x;
    if (!f || Math.abs(f.t - s) > 1.5) continue;
    const b = boxAt(item, t);
    return { x: b.x + (f.x - f.w / 2) * b.w, y: b.y + (f.y - f.h / 2) * b.h, w: f.w * b.w, h: f.h * b.h };
  }
  return null;
}

// ── hit tests (the renderer's own layout) ──
function captionItems(project, t, withLocked = false) {
  return activeItems(project, t).filter(({ track, item }) => track.kind === 'caption' && item.type === 'caption' && (withLocked || !track.locked)).reverse();
}
/** every caption block on screen at t, top-most first: [{ item, lang, L, key, blocks, box (all blocks), cx, cy }] */
export function captionHits(project, t, withLocked = false) {
  const out = [], ct = captionClock(project, t);
  for (const { item } of captionItems(project, t, withLocked)) {
    const blocks = captionBlocks(measureCtx(), project, ct, item);
    const main = blocks.find((b) => b.main); if (!main) continue;
    const x0 = Math.min(...blocks.map((b) => b.box.x)), y0 = Math.min(...blocks.map((b) => b.box.y));
    const x1 = Math.max(...blocks.map((b) => b.box.x + b.box.w)), y1 = Math.max(...blocks.map((b) => b.box.y + b.box.h));
    const first = blocks[0], last = blocks[blocks.length - 1], lang = item.capTrack || '';
    // how far the box can go: render.js keeps the block inside the safe box (x: box edges; y: the block's top/bottom
    // plus the background pad, and room for an emoji above), so a drop never lands somewhere the renderer moves it from
    const { W, H } = dims(project), safe = safeArea(W, H), st0 = resolveStyle(item.style || capOf(project.captions, lang)?.style);
    const padY = st0.bg.pad * main.k, pad = main.st.bg.pad * main.k;
    const lim = { x0: safe.x, x1: safe.x + safe.w, y0: safe.y + padY + (st0.emoji ? st0.size * main.k * 1.4 : 0) - 0.7 * pad, y1: safe.y + safe.h - padY + 0.7 * pad };
    out.push({ item, lang, L: main.L, key: lineKey(lang, main.L.id), blocks, box: { x: x0, y: y0, w: x1 - x0, h: y1 - y0 }, lim,
      cx: main.box.x + main.box.w / 2, cy: (first.top + last.top + last.lay.height) / 2 });
  }
  return out;
}
/** The caption block at canvas point p (12 px of slack), or null. */
export function captionAt(project, t, p) {
  const hit = captionHits(project, t).find((x) => inside(x.box, p));
  return hit ? { ...hit.blocks.find((b) => b.main), ...hit } : null;
}

/** boxes of the lines that sit somewhere else (own positions), laid out at their own time — snap targets */
function otherBoxes(project, t, skip) {
  const out = [];
  for (const { item } of captionItems(project, t, true)) {
    const lang = item.capTrack || '', o = capOf(project.captions, lang); if (!o?.lines) continue;
    let n = 0;
    for (const L of o.lines) {
      if (skip.has(lineKey(lang, L.id)) || !L.ov?.pos || n >= 40) continue;
      const b = captionBlocks(measureCtx(), project, (L.t0 + L.t1) / 2, item).find((x) => x.main && x.L.id === L.id);
      if (b) { out.push(b.box); n++; }
    }
  }
  // what another track shows right now (e.g. the translation over the original)
  for (const h of captionHits(project, t, true)) if (!skip.has(h.key)) out.push(h.box);
  return out;
}

// ── the drag ──
/** Start a drag at p: returns { move(dx,dy,ev), end() } or null when no caption is there. api = { apply, k, guides } */
export function grabCaption(project, t, p, ev, api = {}) {
  const hit = captionHits(project, t).find((x) => inside(x.box, p));
  if (!hit) return null;
  hover = null;                                                      // the hover box and hint step aside while dragging
  if (ev && (ev.metaKey || ev.ctrlKey)) { selectLines([hit.key], 'toggle'); return { line: hit.L.id, move() {}, end: () => true }; }
  const cap = project.captions, { W, H } = dims(project), safe = safeArea(W, H);
  const all = !!ev?.shiftKey || dragPrefs.scope === 'all';
  if (!all && !posSel.has(hit.key)) selectLines([hit.key]);
  const o0 = capOf(cap, hit.lang);
  const drawn = { x: (hit.cx - safe.x) / safe.w, y: (hit.cy - safe.y) / safe.h };   // where the grabbed line sits now
  // start positions of everything that moves
  let movers;
  if (all) {
    const st = resolveStyle(o0.style).pos || {};
    movers = [{ lang: hit.lang, id: null, p: hit.L.ov?.pos ? { x: st.x ?? 0.5, y: st.y ?? 0.72 } : drawn },
      ...o0.lines.filter((L) => L.ov?.pos).map((L) => ({ lang: hit.lang, id: L.id, p: L.id === hit.L.id ? drawn : linePos(o0, L) }))];
  } else {
    movers = [...posSel].map(parseKey).map(({ lang, id }) => {
      const o = capOf(cap, lang), L = o?.lines?.find((x) => x.id === id);
      return L ? { lang, id, p: lineKey(lang, id) === hit.key ? drawn : linePos(o, L) } : null;
    }).filter(Boolean);
  }
  const skip = new Set(all ? capOf(cap, hit.lang).lines.map((L) => lineKey(hit.lang, L.id)) : movers.map((m) => lineKey(m.lang, m.id)));
  const key = (all ? 'captions.moveAll:' : 'captions.move:') + (all ? hit.lang : movers.map((m) => lineKey(m.lang, m.id)).join(','));
  const label = all ? 'Move captions' : movers.length > 1 ? `Move ${movers.length} captions` : 'Move caption';
  let targets = null, face;
  let moved = false;
  return {
    line: hit.L.id, all, key: hit.key,
    move(dx, dy, e) {
      if (!moved && Math.hypot(dx, dy) < 3) return;
      if (!moved) { targets = mergeTargets(frameTargets(W, H, safe), boxTargets(otherBoxes(project, t, skip), 'line')); face = faceBox(project, t); if (face) targets = mergeTargets(targets, boxTargets([face], 'face')); }
      moved = true;
      const k = api.k || 1, guides = [];
      if (!e?.altKey) { const s = snapMove(hit.box, dx, dy, targets, SNAP_PX / k); dx = s.dx; dy = s.dy; guides.push(...s.guides); }
      const kept = keepIn(hit.box, hit.lim, dx, dy); dx = kept.dx; dy = kept.dy;   // the safe box is a hard edge
      if (kept.ex != null) guides.push({ axis: 'x', at: kept.ex, kind: 'safe' });
      if (kept.ey != null) guides.push({ axis: 'y', at: kept.ey, kind: 'safe' });
      const nb = { x: hit.box.x + dx, y: hit.box.y + dy, w: hit.box.w, h: hit.box.h };
      if (face) guides.push({ rect: face, kind: 'face' }, ...(overlaps(nb, face) ? [{ rect: nb, kind: 'face-warn' }] : []));
      api.guides?.(guides);
      const fx = dx / safe.w, fy = dy / safe.h;
      api.apply(setPositions(movers.map((m) => ({ lang: m.lang, id: m.id, pos: { x: r4(clamp01(m.p.x + fx)), y: r4(clamp01(m.p.y + fy)) } })), label, key));
    },
    end() { if (moved) hintSeen(true); return moved; },
  };
}

// ── nudge (arrow keys) ──
export function nudgeCmd(project, keys, dx, dy, t) {
  const { W, H } = dims(project), safe = safeArea(W, H), changes = [];
  const shown = new Map(t == null ? [] : captionHits(project, t, true).map((h) => [h.key, h]));
  for (const key of keys) {
    const { lang, id } = parseKey(key), o = capOf(project.captions, lang), L = o?.lines?.find((x) => x.id === id); if (!L) continue;
    const h = shown.get(key), k = h ? keepIn(h.box, h.lim, dx, dy) : { dx, dy };
    const p = h ? { x: (h.cx - safe.x) / safe.w, y: (h.cy - safe.y) / safe.h } : linePos(o, L);   // on screen: from where it is drawn
    changes.push({ lang, id, pos: { x: r4(clamp01(p.x + k.dx / safe.w)), y: r4(clamp01(p.y + k.dy / safe.h)) } });
  }
  return changes.length ? setPositions(changes, changes.length > 1 ? `Nudge ${changes.length} captions` : 'Nudge caption', 'captions.nudge:' + keys.join(',')) : null;
}

// ── per-shape layouts ──
function snapshot(cap) {
  const one = (o) => ({ style: o.style && typeof o.style === 'object' && o.style.pos ? { ...o.style.pos } : null,
    lines: Object.fromEntries((o.lines || []).map((L) => [L.id, L.ov?.pos ? { ...L.ov.pos } : null])) });
  return { ...one(cap), tracks: Object.fromEntries(Object.entries(cap.tracks || {}).map(([k, o]) => [k, one(o)])) };
}
/** a position from one shape to another: the same place in the frame, inside the new safe box */
export function mapPos(pos, from, to) {
  if (!pos) return pos;
  const a = safeArea(from.w, from.h), b = safeArea(to.w, to.h), out = { ...pos };
  if (pos.x != null) out.x = r4(clamp01(((a.x + pos.x * a.w) / from.w * to.w - b.x) / b.w));
  if (pos.y != null) out.y = r4(clamp01(((a.y + pos.y * a.h) / from.h * to.h - b.y) / b.h));
  return out;
}
export function relayoutCaptions(cap, from, to) {
  if (!cap?.lines || !from.aspect || !to.aspect || from.aspect === to.aspect) return cap;
  const layouts = { ...(cap.layouts || {}), [from.aspect]: snapshot(cap) }, tgt = cap.layouts?.[to.aspect] || null;
  const one = (o, s, track) => {
    const n = { ...o };
    if (o.style && typeof o.style === 'object') {
      const spec = { ...o.style };
      if (s) { if (s.style) spec.pos = { ...s.style }; else if (track) spec.pos = { ...TRACK_POS }; else delete spec.pos; }
      else if (spec.pos) spec.pos = mapPos(spec.pos, from, to);
      n.style = spec;
    }
    n.lines = o.lines.map((L) => {
      const has = s && Object.prototype.hasOwnProperty.call(s.lines, L.id);
      const pos = has ? s.lines[L.id] : L.ov?.pos ? mapPos(L.ov.pos, from, to) : null;
      if (!pos && !L.ov?.pos) return L;
      const ov = { ...(L.ov || {}) }; if (pos) ov.pos = { ...pos }; else delete ov.pos;
      const nl = { ...L }; if (Object.keys(ov).length) nl.ov = ov; else delete nl.ov;
      return nl;
    });
    return n;
  };
  const out = one(cap, tgt);
  if (cap.tracks) out.tracks = Object.fromEntries(Object.entries(cap.tracks).map(([k, o]) => [k, o?.lines ? one(o, tgt?.tracks?.[k], true) : o]));
  out.layouts = layouts;
  return out;
}
registerReflow({ id: 'captions', run(p, from, to) { if (p.captions) p.captions = relayoutCaptions(p.captions, from, to); } });

// ── the editor hooks ──
registerCanvasGrab({ id: 'captions', grab: (project, t, p, ev, api) => grabCaption(project, t, p, ev, api) });

let hover = null;   // { key, box }
function hintSeen(set) {
  try { if (set) localStorage.setItem(HINT_KEY, '1'); return localStorage.getItem(HINT_KEY) === '1'; } catch { return !!set; }
}
registerCanvasHover({ id: 'captions', hover(project, t, p) {
  const hit = p ? captionHits(project, t).find((x) => inside(x.box, p)) : null;
  const sig = (h) => (h ? h.key + ':' + Math.round(h.box.x) + ',' + Math.round(h.box.y) : '');
  const changed = sig(hit) !== sig(hover);
  hover = hit ? { key: hit.key, box: hit.box } : null;
  return { cursor: hit ? 'move' : '', changed };
} });

const div = (cls, style, text) => { const d = document.createElement('div'); d.className = cls; Object.assign(d.style, style); if (text) d.textContent = text; return d; };
const px = (r, k) => ({ left: r.x * k + 'px', top: r.y * k + 'px', width: r.w * k + 'px', height: r.h * k + 'px' });
registerCanvasOverlay({ id: 'captions', draw(el, { project, t, k, playing }) {
  if (playing || (!posSel.size && !hover)) return;
  const hits = captionHits(project, t);
  for (const h of hits) {
    const on = posSel.has(h.key), hov = hover && hover.key === h.key;
    if (!on && !hov) continue;
    const b = div('cap-box' + (on ? ' sel' : '') + (hov ? ' hover' : ''), px(h.box, k));
    b.dataset.line = h.key;
    if (on) for (const c of ['nw', 'ne', 'sw', 'se']) b.appendChild(div('cap-hdl ' + c, {}));
    el.appendChild(b);
    if (hov && !hintSeen()) {
      const below = h.box.y * k < 34;
      el.appendChild(div('cap-hint' + (below ? ' below' : ''), { left: (h.box.x + h.box.w / 2) * k + 'px', top: (below ? h.box.y + h.box.h : h.box.y) * k + 'px' }, HINT));
    }
  }
} });

let menuEl = null;
function closeMenu() { if (!menuEl) return; menuEl.remove(); menuEl = null; document.removeEventListener('pointerdown', outside, true); document.removeEventListener('keydown', escKey, true); }
const outside = (e) => { if (menuEl && !menuEl.contains(e.target)) closeMenu(); };
const escKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); closeMenu(); } };
registerCanvasMenu({ id: 'captions', menu(project, t, p, ev, api) {
  const hit = captionHits(project, t).find((x) => inside(x.box, p));
  if (!hit) return null;
  closeMenu();
  if (!posSel.has(hit.key)) selectLines([hit.key]);
  const picked = [...posSel], o = capOf(project.captions, hit.lang);
  const item = (x, label, run, off = false) => { const b = document.createElement('button'); b.type = 'button'; b.dataset.x = x; b.textContent = label; b.disabled = off; b.onclick = () => { closeMenu(); run(); }; return b; };
  menuEl = div('cap-menu', { left: ev.clientX + 'px', top: ev.clientY + 'px' });
  menuEl.setAttribute('role', 'menu');
  menuEl.append(
    item('reset-line', 'Reset position', () => api.apply(resetLines([hit.key])), !hit.L.ov?.pos),
    ...(picked.length > 1 ? [item('reset-picked', `Reset ${picked.length} picked lines`, () => api.apply(resetLines(picked)))] : []),
    item('reset-all', 'Reset all lines', () => api.apply(resetAll(project.captions, hit.lang)), !(o.lines.some((L) => L.ov?.pos) || (o.style && typeof o.style === 'object' && o.style.pos && !hit.lang))),
    div('cap-menu-tip', {}, 'Drag moves this line · Shift-drag all · ←↑↓→ nudge'));
  document.body.appendChild(menuEl);
  document.addEventListener('pointerdown', outside, true); document.addEventListener('keydown', escKey, true);
  api.redraw?.();
  return true;
} });

registerCanvasKey({ id: 'captions', key(e, ctx) {
  if (e.key === 'Escape') { clearSel(); return null; }
  if (!posSel.size || !/^Arrow(Left|Right|Up|Down)$/.test(e.key) || e.metaKey || e.ctrlKey || e.altKey) return null;
  if (!document.activeElement?.classList?.contains('pv-overlay')) return null;   // arrows only nudge while the preview has focus
  const s = e.shiftKey ? 10 : 1, d = { ArrowLeft: [-s, 0], ArrowRight: [s, 0], ArrowUp: [0, -s], ArrowDown: [0, s] }[e.key];
  const cmd = nudgeCmd(ctx.project, [...posSel], d[0], d[1], ctx.playhead());
  if (cmd) ctx.history.apply(cmd);
  return true;
} });
