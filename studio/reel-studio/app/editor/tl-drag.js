// KBB Studio — timeline drag & drop, blade, ranges, ripple trim and the edit keys (the UI half of edit-ops.js).
//
//   drag any item: left/right in time, up/down between tracks (z-order = track order), onto the empty band above the top
//     track or below the last one for a NEW track, over something on an overlay/text/sound track -> a new track above it
//     (a picture-in-picture is just a clip on a track above the main one). The main video track is magnetic: dropping
//     clips between clips INSERTS them and pushes the later ones; the hole a moved clip leaves closes. Alt overwrites.
//     A transition dragged anywhere lands on the nearest cut. Several selected items move together.
//   snapping to the playhead, clip edges, markers, caption line boundaries and music beats; hold ⌘/Ctrl to drag free.
//   drops from outside (media bin, Themes, Finder files) use the same targets and the same indicator.
//   Speed: nothing is measured per pointermove. Row boxes, the content's left edge and the snap points are read ONCE at
//   drag start; moves only set transforms (one rAF per frame) and the project changes ONCE, on drop (one undo step).

import { findMedia, itemDur, itemEnd, findItem } from '../core/project.js';
import { h, toast } from './ui.js';
import { clipFromMedia, fits } from './items.js';
import { ops } from '../core/history.js';
import { mainTrack, isMain, insertPoint, placeCmd, newTrackFor, overlaps, splitCmd, rangeCmd, joinCmd, unlinkAudioCmd, nudgeTimeCmd, snapFrame, nearestCut, transitionOnCut, isTransition, rippleFollowers } from './edit-ops.js';
import './edit-follow.js';

export const HEAD_W = 176;
const SNAP_PX = 8;
const BAND = 14;          // px: the "new track" band above the first row / below the last
let CW = null;            // the cutaways module owns the I/O marks; ranges here share them
import('../cutaways/index.js').then((m) => { CW = m; }).catch(() => {});
let ownMarks = { in: null, out: null };
export const marks = () => (CW ? CW.marks() : { ...ownMarks });
export function setMarks(m) { if (CW) CW.setMarks(m); else { ownMarks = { in: m.in ?? null, out: m.out ?? null }; window.kbbEditor?.timeline?.render(); } }

// ── snap points ──
const libBeats = new Map();   // media src -> beats (s, source time), filled once from the music library
let libLoading = null;
function loadLibBeats(ed) {
  if (libLoading) return;
  libLoading = import('../auto/index.js').then((A) => A.library?.(ed.context())).then((lib) => { for (const x of lib?.music || []) if (x.beats?.length) libBeats.set(x.file, x.beats); }).catch(() => {});
}
/** sorted snap times: 0, playhead, markers, item edges (minus `exclude`), caption line edges, music beats */
export function snapPoints(ed, exclude = new Set()) {
  const p = ed.project, pts = [0, ed.t];
  for (const m of p.markers) pts.push(m.t);
  for (const tr of p.tracks) for (const it of tr.items) if (!exclude.has(it.id)) pts.push(it.t0, itemEnd(it));
  for (const L of p.captions?.lines || []) pts.push(L.t0, L.t1);
  loadLibBeats(ed);
  for (const tr of p.tracks) {
    if (tr.kind !== 'music' || tr.muted) continue;
    for (const it of tr.items) {
      const m = findMedia(p, it.media); if (!m) continue;
      const beats = m.beats || [...libBeats].find(([f]) => m.src?.endsWith('/' + f))?.[1] || [];
      for (const b of beats) if (b >= it.in && b <= it.out) pts.push(it.t0 + (b - it.in) / (it.speed || 1));
    }
  }
  for (const tr of p.tracks) for (const it of tr.items) if (it.type === 'theme.overlay') for (const b of it.style?.beats || []) pts.push(b);
  return Float64Array.from(pts).sort();
}
/** the smallest correction (s) that puts one of `vals` on a point within SNAP_PX, or 0 */
export function snapDelta(vals, pts, pps) {
  const thr = SNAP_PX / pps; let best = null;
  for (const v of vals) {
    let lo = 0, hi = pts.length;           // binary search for the first point >= v - thr
    while (lo < hi) { const m = (lo + hi) >> 1; if (pts[m] < v - thr) lo = m + 1; else hi = m; }
    for (let i = lo; i < pts.length && pts[i] <= v + thr; i++) { const d = pts[i] - v; if (best === null || Math.abs(d) < Math.abs(best)) best = d; }
  }
  return best || 0;
}

// ── geometry, read once ──
function geometry(tl) {
  const cr = tl.content.getBoundingClientRect();
  const rows = tl.rows.map((r) => { const b = r.row.getBoundingClientRect(); return { track: r.track, top: b.top - cr.top, bottom: b.bottom - cr.top, el: r.row }; });
  return { left: cr.left, top: cr.top, sl: tl.scroll.scrollLeft, st: tl.scroll.scrollTop, rows };
}
/** content-space x/y of a client point (the scroll offset since the geometry was read is added, nothing measured) */
const local = (tl, g, cx, cy) => ({ x: cx - g.left + (tl.scroll.scrollLeft - g.sl), y: cy - g.top + (tl.scroll.scrollTop - g.st) });

/**
 * Where a drop at content point (x, y) goes, for items of `types` (block length L s, starting at t):
 *   { track, newTrack?, mode: 'insert'|'overwrite'|'free', insert?, t } or null (nowhere)
 */
function targetAt(tl, g, y, t, L, types, alt, skip, leadTrack) {
  const p = tl.ed.project, rows = g.rows, audio = types.every((x) => x === 'audio'), visual = types.every((x) => x !== 'audio');
  if (!rows.length || (!audio && !visual)) return null;
  const first = rows[0], last = rows[rows.length - 1];
  const fresh = (above) => ({ newTrack: newTrackFor(p, audio ? 'audio' : types.includes('clip') ? 'clip' : types[0], above || null), mode: 'free', t, band: true });
  if (y < first.top + 4) return visual ? { ...fresh(null), at: 'top' } : null;
  if (y > last.bottom - 2) return audio ? { ...fresh(null), at: 'bottom' } : { ...fresh(null), at: 'top' };
  const r = rows.find((x) => y >= x.top && y < x.bottom); if (!r) return null;
  const tr = r.track;
  if (tr.locked || !types.every((x) => fits(x, tr.kind))) {
    // a clip over the text track, a sticker over the main track: the nearest row above that takes it
    if (visual) { const up = rows.slice(0, rows.indexOf(r)).reverse().find((x) => !x.track.locked && types.every((k) => fits(k, x.track.kind)) && !isMain(p, x.track)); if (up) return { track: up.track, mode: alt ? 'overwrite' : 'free', t, row: up }; return { ...fresh(null), at: 'top' }; }
    return null;
  }
  if (isMain(p, tr)) {
    if (!types.every((x) => x === 'clip')) return { ...fresh(tr), at: r };
    if (alt) return { track: tr, mode: 'overwrite', t, row: r };
    return { track: tr, mode: 'insert', insert: insertPoint(p, tl.pointerT ?? t, skip), t, row: r };
  }
  if (alt) return { track: tr, mode: 'overwrite', t, row: r };
  if (tr !== leadTrack && overlaps(tr, t, t + L, skip)) return { ...fresh(tr), at: r };   // over something: a new track above it
  return { track: tr, mode: 'free', t, row: r };
}

// ── the drop indicator ──
function indicator(tl) { if (!tl.dropEl) { tl.dropEl = h('div.tl-drop'); tl.content.appendChild(tl.dropEl); } return tl.dropEl; }
export function clearIndicator(tl) { tl.dropEl?.remove(); tl.dropEl = null; tl.content.querySelector('.snap-line')?.remove(); }
function showTarget(tl, g, tg, L) {
  const el = indicator(tl), pps = tl.pps;
  if (!tg) { el.className = 'tl-drop no'; el.style.display = 'none'; return; }
  el.style.display = '';
  if (tg.band) {
    const y = tg.at === 'bottom' ? g.rows[g.rows.length - 1].bottom - BAND / 2 : tg.at === 'top' ? g.rows[0].top - BAND / 2 + 2 : tg.at.top - BAND / 2;
    el.className = 'tl-drop band'; el.textContent = '＋ New track';
    Object.assign(el.style, { top: Math.max(0, y) + 'px', height: BAND + 'px', left: HEAD_W + tg.t * pps + 'px', width: Math.max(40, L * pps) + 'px' });
    return;
  }
  el.textContent = '';
  if (tg.mode === 'insert') {
    const x = (tg.insert?.anchor ? tg.insert.t : tg.t) * pps;
    el.className = 'tl-drop ins';
    Object.assign(el.style, { top: tg.row.top + 'px', height: tg.row.bottom - tg.row.top + 'px', left: HEAD_W + x - 2 + 'px', width: '4px' });
  } else {
    el.className = 'tl-drop box' + (tg.mode === 'overwrite' ? ' ow' : '');
    Object.assign(el.style, { top: tg.row.top + 2 + 'px', height: tg.row.bottom - tg.row.top - 4 + 'px', left: HEAD_W + tg.t * pps + 'px', width: Math.max(4, L * pps) + 'px' });
  }
}

// ── move (and drop) items already on the timeline ──
export function dragMove(tl, e, id) {
  const ed = tl.ed, p = ed.project;
  const hits = ed.selectedItems().filter((x) => !x.track.locked);
  if (!hits.length) return;
  const lead = hits.find((x) => x.item.id === id) || hits[0];
  const g = geometry(tl);
  const skip = new Set(hits.map((x) => x.item.id));
  const pts = snapPoints(ed, skip);
  const lo = Math.min(...hits.map((x) => x.item.t0)), hi = Math.max(...hits.map((x) => itemEnd(x.item))), L = hi - lo;
  const rowOf = (tr) => g.rows.findIndex((r) => r.track === tr);
  const leadRow = rowOf(lead.track);
  const els = hits.map((x) => ({ hit: x, el: tl.content.querySelector(`.tl-item[data-id="${x.item.id}"]`), row: rowOf(x.track) }));
  const types = [...new Set(hits.map((x) => x.item.type))];
  const sameTrack = hits.every((x) => x.track === lead.track);
  const trans = isTransition(lead.item) && hits.length === 1;
  const mainHits = sameTrack ? [] : hits.filter((x) => isMain(p, x.track) && x.item.type === 'clip');
  const ownRows = new Map(hits.map((x) => [x.item.id, x.track.id]));
  const x0 = e.clientX, y0 = e.clientY, start = local(tl, g, x0, y0);
  let moved = false, last = null, raf = 0, ev1 = null;
  const frame = () => {
    raf = 0; const ev = ev1; if (!ev) return;
    const q = local(tl, g, ev.clientX, ev.clientY);
    let dt = (q.x - start.x) / tl.pps;
    dt = Math.max(dt, -lo);
    const free = ev.metaKey || ev.ctrlKey || !tl.snapOn;
    let sd = 0, snapAt = null;
    if (!free && !trans) {
      const a = snapDelta([lo + dt], pts, tl.pps), b = snapDelta([hi + dt], pts, tl.pps);
      sd = a && (!b || Math.abs(a) <= Math.abs(b)) ? a : b;
      if (sd) { snapAt = sd === a ? lo + dt + sd : hi + dt + sd; dt += sd; }
    }
    tl.pointerT = Math.max(0, (q.x - HEAD_W) / tl.pps);
    const t = Math.max(0, lo + dt);
    let tg;
    if (trans) {   // a transition goes to the nearest cut
      const c = nearestCut(p, t + itemDur(lead.item) / 2);
      tg = c ? { track: lead.track, mode: 'free', t: c.t - itemDur(lead.item) / 2, cut: c, row: g.rows[leadRow] } : null;
    } else if (sameTrack) tg = targetAt(tl, g, q.y, t, L, types, ev.altKey, skip, lead.track);
    else if (mainHits.length) {   // several tracks with main-track clips among them: magnetic on the main track, the rest ride along
      const mr = g.rows[rowOf(mainTrack(p))];
      tg = ev.altKey ? { track: mainTrack(p), mode: 'overwrite', t, row: mr, rows: ownRows } : { track: mainTrack(p), mode: 'insert', insert: insertPoint(p, tl.pointerT, skip), t, row: mr, rows: ownRows };
    } else tg = { track: null, mode: 'free', t, multi: true };   // several tracks: they move in time, each on its own row
    last = { tg, t, ev };
    // the dragged blocks follow the pointer (transform only)
    const dy = tg?.row ? tg.row.top - g.rows[leadRow].top : tg?.band ? (tg.at === 'top' ? g.rows[0].top - BAND - g.rows[leadRow].top : tg.at === 'bottom' ? g.rows[g.rows.length - 1].bottom - g.rows[leadRow].top : tg.at.top - BAND - g.rows[leadRow].top) : 0;
    const dx = ((tg?.t ?? t) - lo) * tl.pps;
    for (const x of els) if (x.el) x.el.style.transform = `translate(${dx}px, ${sameTrack ? dy : 0}px)`;
    if (tg && !tg.multi) showTarget(tl, g, tg, L); else if (tl.dropEl) tl.dropEl.style.display = 'none';
    tl.showSnapLine(snapAt);
    last.snapAt = snapAt;
  };
  tl.track(e, (ev) => {
    if (!moved && Math.hypot(ev.clientX - x0, ev.clientY - y0) < 4) return;
    if (!moved) { moved = true; for (const x of els) x.el?.classList.add('dragging'); }
    ev1 = ev; if (!raf) raf = requestAnimationFrame(frame);
  }, (ev) => {
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    if (moved) { ev1 = ev; frame(); }
    clearIndicator(tl); tl.pointerT = null;
    for (const x of els) if (x.el) { x.el.style.transform = ''; x.el.classList.remove('dragging'); }
    if (!moved || !last?.tg) { if (moved) tl.render(); return; }
    const { tg } = last;
    let cmd;
    if (trans) {
      if (!tg.cut) return;
      cmd = ops.setItems([{ id: lead.item.id, props: transitionOnCut(lead.item, tg.cut) }], 'Transition on a cut');
    } else if (tg.multi) {
      const d = tg.t - lo;
      cmd = ops.moveItems(hits.map((x) => ({ id: x.item.id, t0: Math.max(0, Math.round((x.item.t0 + d) * 10000) / 10000) })), 'Move');
    } else {
      const moving = hits.map((x) => ({ id: x.item.id, item: x.item, fromTrackId: x.track.id }));
      const target = tg.newTrack ? { newTrack: tg.newTrack } : { trackId: tg.track.id };
      const t0 = tg.rows ? tg.t + Math.min(...mainHits.map((x) => x.item.t0)) - lo : tg.t;   // placeCmd measures from the main-track clips
      cmd = placeCmd(p, moving, t0, target, tg.mode, { insert: tg.insert, rows: tg.rows || null, label: tg.newTrack ? 'Move to a new track' : tg.mode === 'insert' ? 'Insert' : tg.mode === 'overwrite' ? 'Overwrite' : 'Move' }).cmd;
    }
    ed.apply(cmd); ed.history.seal();
  });
}

// ── drops from outside: media bin, Themes, Finder ──
const typesOf = (dt) => [...(dt?.types || [])];
function dropTypes(ed, e) {
  const ts = typesOf(e.dataTransfer);
  if (ts.includes('application/x-kbb-theme-trans')) return { kind: 'trans' };
  if (ts.includes('application/x-kbb-theme-el')) return { kind: 'el', types: ['theme.el'], L: 3 };
  if (ts.includes('application/x-kbb-lib')) return { kind: 'lib', types: ['audio'], L: 4 };
  if (ts.includes('application/x-kbb-media')) return { kind: 'media', types: null, L: 4 };   // the type is known only on drop
  if (ts.includes('Files')) return { kind: 'files', types: null, L: 4 };
  return null;
}
export function onDragOver(tl, e) {
  const d = dropTypes(tl.ed, e); if (!d) return;
  e.preventDefault();
  if (!tl.dropGeo || performance.now() - tl.dropGeoAt > 400) { tl.dropGeo = geometry(tl); tl.dropGeoAt = performance.now(); }
  const g = tl.dropGeo, q = local(tl, g, e.clientX, e.clientY);
  const t = Math.max(0, (q.x - HEAD_W) / tl.pps); tl.pointerT = t;
  if (d.kind === 'trans') {
    const c = nearestCut(tl.ed.project, t);
    if (!c) { showTarget(tl, g, null, 0); return; }
    const mr = g.rows.find((r) => r.track === mainTrack(tl.ed.project));
    showTarget(tl, g, mr ? { mode: 'insert', insert: { anchor: 1, t: c.t }, t: c.t, row: mr } : null, 0);
    return;
  }
  // media of unknown kind: a video/image shows as a clip, sound as audio (the row under the pointer decides)
  let types = d.types;
  if (!types) { const r = g.rows.find((x) => q.y >= x.top && q.y < x.bottom); types = r && (r.track.kind === 'music' || r.track.kind === 'sfx') ? ['audio'] : ['clip']; }
  showTarget(tl, g, targetAt(tl, g, q.y, t, d.L, types, e.altKey, new Set(), null), d.L);
}
export function onDragLeave(tl, e) { if (!tl.scroll.contains(e.relatedTarget)) { clearIndicator(tl); tl.dropGeo = null; } }

/** a drop: returns true when handled here */
export function onDrop(tl, e) {
  const ed = tl.ed, d = dropTypes(ed, e); if (!d) return false;
  const g = tl.dropGeo || geometry(tl); tl.dropGeo = null; clearIndicator(tl);
  const q = local(tl, g, e.clientX, e.clientY), t = Math.max(0, (q.x - HEAD_W) / tl.pps), alt = e.altKey;
  tl.pointerT = t;
  const place = (item, m) => {
    const tg = targetAt(tl, g, q.y, item.t0, itemDur(item), [item.type], alt, new Set(), null);
    const p = ed.project, cmds = [];
    if (m && !p.media.some((x) => x.id === m.id)) cmds.push(ops.addMedia(m));
    let target, mode = 'free', insert = null;
    if (!tg) { const tr = p.tracks.find((x) => !x.locked && fits(item.type, x.kind) && (item.type !== 'clip' || x.kind === 'video')); if (!tr) return null; target = { trackId: tr.id }; }
    else { target = tg.newTrack ? { newTrack: tg.newTrack } : { trackId: tg.track.id }; mode = tg.mode; insert = tg.insert; }
    // a media clip on a non-main track is a picture-in-picture: it keeps the small box clipFromMedia gives overlays
    const trackKind = target.newTrack ? target.newTrack.track.kind : findItemTrackKind(p, target.trackId);
    if (m && item.type === 'clip' && trackKind) Object.assign(item, clipFromMedia(p, m, trackKind, item.t0), { id: item.id });
    const r = placeCmd(p, [{ item }], tg ? tg.t : item.t0, target, mode, { insert, label: 'Add ' + (item.name || item.type) });
    cmds.push(r.cmd);
    ed.apply(ops.batch('Add ' + (item.name || item.type), cmds));
    ed.select([item.id]);
    return item;
  };
  e.preventDefault();
  if (d.kind === 'trans') return false;   // the timeline's own handler places transitions on the nearest cut
  if (d.kind === 'el') {
    let spec = null; try { spec = JSON.parse(e.dataTransfer.getData('application/x-kbb-theme-el')); } catch { return true; }
    const tg = targetAt(tl, g, q.y, t, 3, ['theme.el'], alt, new Set(), null);
    // themes/apply.js makes the element (one undo step with the move to a new track when the drop asked for one)
    import('../themes/apply.js').then((A) => {
      ed.history.begin('Add theme element');
      try {
        let keep = tg && !tg.newTrack && tg.track && /^(overlay|text)$/.test(tg.track.kind) && !tg.track.theme ? tg.track.id : null;
        if (tg?.newTrack) { const nt = tg.newTrack.track.kind === 'text' ? Object.assign(tg.newTrack.track, { kind: 'overlay' }) : tg.newTrack.track; ed.apply(ops.addTrack(nt, tg.newTrack.index, 'New track')); keep = nt.id; }
        const it = A.placeElement(ed.context(), spec.el, { theme: spec.theme, variant: spec.variant, t0: t, trackId: keep });
        ed.history.end(); ed.select([it.id]);
        if (spec.el === 'tbehind') import('../themes/textbehind-popup.js').then((m) => m.openTbEditor(ed, it.id));
      } catch (err) { ed.history.end(); console.warn('[timeline] theme element', err); }
    }).catch((err) => console.warn('[timeline] theme element', err));
    return true;
  }
  if (d.kind === 'lib') return false;     // music / SFX rows: the timeline's own handler (imports first)
  if (d.kind === 'media') {
    const m = findMedia(ed.project, e.dataTransfer.getData('application/x-kbb-media')); if (!m) return true;
    place(clipFromMedia(ed.project, m, 'video', t), m);
    return true;
  }
  if (d.kind === 'files') {
    const files = [...e.dataTransfer.files];
    (async () => { let at = t; for (const m of await ed.importFiles(files, { addToTimeline: false })) { const it = place(clipFromMedia(ed.project, m, 'video', at), m); if (it) at = itemEnd(findItem(ed.project, it.id)?.item || it); } })();
    return true;
  }
  return false;
}
const findItemTrackKind = (p, id) => p.tracks.find((x) => x.id === id)?.kind || null;

// ── trim with ripple (Alt): the items after the edge move with it ──
/** extra props for a ripple trim: the followers' new t0 for a change of the clip's end by dEnd seconds */
export const rippleProps = (followers, dEnd) => followers.map((f) => ({ id: f.id, props: { t0: Math.max(0, Math.round((f.t0 + dEnd) * 10000) / 10000) } }));
export { rippleFollowers };

// ── blade ──
export function bladeAt(tl, e, id) {
  const ed = tl.ed, g = geometry(tl), q = local(tl, g, e.clientX, e.clientY);
  let t = Math.max(0, (q.x - HEAD_W) / tl.pps);
  if (tl.snapOn && !(e.metaKey || e.ctrlKey) && Math.abs(t - ed.t) * tl.pps < SNAP_PX) t = ed.t;   // onto the playhead
  const hit = findItem(ed.project, id);
  const r = splitCmd(ed.project, e.shiftKey ? null : hit ? [{ track: hit.track, item: hit.item }] : null, t);   // Shift: every track
  if (!r) return;
  ed.apply(r.cmd); ed.seek(snapFrame(ed.project, t)); ed.select(r.ids);
}
export function setBlade(tl, on) {
  tl.blade = on; tl.scroll.classList.toggle('blade', on); tl.bladeBtn?.classList.toggle('on', on);
}

// ── range on the ruler: Shift-drag (or drag with the range tool) marks In/Out ──
export function dragRange(tl, e) {
  const ed = tl.ed, g = geometry(tl), fps = ed.project.canvas.fps || 30;
  const tAt = (cx) => Math.max(0, (local(tl, g, cx, 0).x - HEAD_W) / tl.pps);
  const snap = (t) => Math.round(t * fps) / fps;
  const a = tAt(e.clientX);
  tl.track(e, (ev) => { const b = tAt(ev.clientX); setMarks({ in: snap(Math.min(a, b)), out: snap(Math.max(a, b)) }); });
}
/** the marked range drawn across every row (a band you can see the cut in) */
export function drawRange(tl) {
  const m = marks(); if (m.in == null) return;
  const z = m.out ?? m.in;
  tl.content.appendChild(h('div.tl-range', { style: { left: HEAD_W + m.in * tl.pps + 'px', width: Math.max(2, (z - m.in) * tl.pps) + 'px' }, title: `Range ${m.in.toFixed(2)}–${z.toFixed(2)} s · Delete removes it (the gap closes) · Alt+Delete lifts it (the gap stays)` }));
}

// ── tools in the timeline bar ──
export function extraTools(tl) {
  const ed = tl.ed;
  tl.bladeBtn = h('button.tool', { title: 'Blade (B): click a clip to cut it at that frame (Shift-click: every track)', on: { click: () => setBlade(tl, !tl.blade) } }, '🔪 Blade');
  return [
    tl.bladeBtn,
    h('button.tool', { title: 'Delete the marked range (Delete): the gap closes · Alt: lift it (the gap stays). Mark it with I / O or Shift-drag on the ruler', on: { click: (e) => deleteRange(ed, e.altKey) } }, '⌫ Range'),
    h('button.tool', { title: 'Rejoin the selected pieces of the same clip (⌘J)', on: { click: () => join(ed) } }, '⛓ Join'),
    h('button.tool', { title: 'Unlink the selected clips\' own sound (it can then move on its own)', on: { click: () => unlink(ed) } }, '🔊 Unlink'),
  ];
}

// ── edits the keys and buttons run ──
export function deleteRange(ed, lift = false) {
  const m = marks();
  if (m.in == null || m.out == null) { toast('Mark a range first: I and O, or Shift-drag on the ruler'); return false; }
  const cmd = rangeCmd(ed.project, m.in, m.out, { ripple: !lift });
  if (!cmd) { toast('Nothing on the video track in that range'); return false; }
  ed.apply(cmd); setMarks({ in: null, out: null });
  ed.seek(Math.min(ed.t, ed.duration()));
  return true;
}
export function join(ed) {
  const ids = [...ed.sel];
  let cmd = joinCmd(ed.project, ids);
  if (!cmd && !ids.length) {   // nothing selected: the cut under the playhead
    const vt = mainTrack(ed.project), f = 1 / (ed.project.canvas.fps || 30);
    const a = vt?.items.find((c) => Math.abs(itemEnd(c) - ed.t) <= f);
    if (a) cmd = joinCmd(ed.project, [a.id]);
  }
  if (!cmd) { toast('Select two pieces of the same clip that play on from each other'); return; }
  ed.apply(cmd);
}
export function unlink(ed) {
  const r = unlinkAudioCmd(ed.project, ed.selectedItems());
  if (!r) { toast('Select a video clip with sound'); return; }
  ed.apply(r.cmd); ed.select(r.ids); toast('Sound unlinked: it moves on its own now');
}

/** editor keys this file owns; true = handled */
export function timelineKey(ed, e) {
  const mod = e.metaKey || e.ctrlKey, k = e.key, tl = ed.timeline;
  if (mod && !e.altKey && k.toLowerCase() === 'b') { ed.split(); return true; }
  if (mod && !e.altKey && k.toLowerCase() === 'j') { join(ed); return true; }
  if (mod) return false;
  if ((k === 'b' || k === 'B') && !e.altKey) { setBlade(tl, !tl.blade); return true; }
  if (k === 'Escape' && tl.blade) { setBlade(tl, false); return false; }
  if (e.code === 'Comma' || e.code === 'Period') {
    const hits = ed.selectedItems(); if (!hits.length) return false;
    const cmd = nudgeTimeCmd(ed.project, hits, (e.code === 'Comma' ? -1 : 1) * (e.shiftKey ? 10 : 1));
    if (cmd) ed.apply(cmd);
    return true;
  }
  if (k === 'Delete' || k === 'Backspace') {
    const m = marks();
    if (m.in != null && m.out != null) { deleteRange(ed, e.altKey); return true; }
    // the main track is magnetic: deleting a piece of it closes the gap (Alt keeps it); elsewhere a delete leaves a gap
    const main = ed.selectedItems().some((x) => isMain(ed.project, x.track));
    ed.deleteSelection(e.shiftKey || (main && !e.altKey));
    return true;
  }
  return false;
}

/** live ripple trim support for Timeline.dragTrim: the other items on the track that ride the edge */
export function trimFollowers(track, item) { return rippleFollowers(track, item); }
export { itemDur, itemEnd };
