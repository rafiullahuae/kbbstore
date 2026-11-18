// KBB Studio — the speed tool: constant speed, speed ramps (presets + a curve editor with draggable points), smooth slow
// motion, reverse, freeze frames, the sound options — in the inspector, on a clip's right-click menu and by Shift-dragging
// a clip's edge on the timeline. The maths is core/retime.js; this file is the editing.
//
// Every retime is ONE undoable step that keeps the edit in step:
//   · later clips on the same track ripple by the change in length (no gaps, no overlaps);
//   · on the main video track, what sits ON the clip (theme elements, stickers, text, transitions, sound effects) stays on
//     the same moment of the footage — its start goes through the old and the new time mapping — and what comes after
//     the clip moves with the clips that rippled;
//   · caption words follow by themselves (sync/follow.js re-places every word from its source anchor).
// A drag (slider, curve point, Shift-drag) computes every step from the project as it was when the drag began and
// always touches the same items, so the coalesced undo step is exact.

import { ops } from '../core/history.js';
import { findItem, findMedia, itemDur, itemEnd, clone, uid } from '../core/project.js';
import { srcTime, tlTime, curveV, curveProps, curveMean, PRESETS, MIN_SPEED, MAX_SPEED } from '../core/retime.js';
import { h, toast, fmtDur, select } from './ui.js';

const RT_KEYS = ['t0', 'in', 'out', 'speed', 'curve', 'reverse', 'freeze', 'smooth', 'keepPitch', 'rampMute'];
const EPS = 1e-4;
const r5 = (x) => Math.round(x * 1e5) / 1e5;
const hasCurve = (it) => !!(it?.curve && it.curve.pts && it.curve.pts.length >= 2);

/** one command that retimes item `id` to `props`, computed from `base` (the project when the gesture began) */
export function retimeCommand(base, id, props, label = 'Speed') {
  const hit = findItem(base, id); if (!hit) return null;
  const { item, track } = hit, after = Object.assign({}, item, props);
  const end0 = itemEnd(item), end1 = itemEnd(after), delta = end1 - end0;
  const sameDir = !item.reverse === !after.reverse;
  const list = [{ id, props: Object.fromEntries(RT_KEYS.map((k) => [k, after[k] === undefined ? null : after[k]])) }];
  for (const tr of base.tracks) {
    if (tr.locked && tr.id !== track.id) continue;
    for (const it of tr.items) {
      if (it.id === id) continue;
      if (tr.id === track.id) { if (it.t0 >= end0 - EPS) list.push({ id: it.id, props: { t0: r5(it.t0 + delta) } }); continue; }
      // only the main video track carries its overlays along; music, captions, cutaways (they follow their own link) stay
      if (track.kind !== 'video' || tr.kind === 'music' || tr.kind === 'caption' || tr.kind === 'video' || it.type === 'cutaway' || it.type === 'caption') continue;
      if (it.t0 >= item.t0 - EPS && it.t0 < end0 - EPS) {
        if (it.t0 <= item.t0 + EPS && itemEnd(it) >= end0 - EPS) continue;           // spans the whole clip (a theme overlay)
        const nt = sameDir && !after.freeze ? tlTime(after, srcTime(item, it.t0)) : after.t0 + (it.t0 - item.t0);
        list.push({ id: it.id, props: { t0: r5(Math.max(0, nt)) } });
      } else if (it.t0 >= end0 - EPS) list.push({ id: it.id, props: { t0: r5(it.t0 + delta) } });
    }
  }
  return ops.setItems(list, label);
}

/**
 * Freeze frame: hold the frame under the playhead for `secs` seconds — the clip is split at the playhead, a silent hold of
 * that frame goes between the halves, and everything after moves on by `secs` (one undo step).
 */
export function freezeCommand(base, id, t, secs = 2) {
  const hit = findItem(base, id); if (!hit) return null;
  const { item, track } = hit;
  if (item.type !== 'clip' || t < item.t0 - EPS || t > itemEnd(item) + EPS) return null;
  const s = srcTime(item, Math.min(itemEnd(item) - 1e-3, Math.max(item.t0, t)));
  const hold = Object.assign(clone(item), { id: uid('i'), t0: r5(t), in: r5(s), out: r5(s + secs), speed: 1, curve: null, reverse: null, freeze: true, muted: true, fadeIn: 0, fadeOut: 0, kf: {}, name: '❄ Freeze · ' + (item.name || 'clip') });
  const cmds = [];
  const later = [];
  for (const tr of base.tracks) {
    if (tr.locked && tr.id !== track.id) continue;
    for (const it of tr.items) {
      if (it.id === id) continue;
      const onClip = tr.id === track.id || (track.kind === 'video' && !['music', 'caption', 'video'].includes(tr.kind) && it.type !== 'cutaway' && it.type !== 'caption');
      if (onClip && it.t0 >= t - EPS && !(it.t0 <= item.t0 + EPS && itemEnd(it) >= itemEnd(item) - EPS)) later.push({ id: it.id, t0: r5(it.t0 + secs) });
    }
  }
  const inside = t > item.t0 + 0.02 && t < itemEnd(item) - 0.02;
  if (inside) {
    // split exactly like the S key does (ramps, reverse included), then the hold, then the right half
    return import('../core/retime.js').then(({ splitProps }) => {
      const { left, right: rp } = splitProps(item, t);
      const right = Object.assign(clone(item), rp, { id: uid('i'), fadeIn: 0, kf: {} }); right.t0 = r5(t + secs);
      cmds.push(ops.setItem(id, Object.assign(left, { fadeOut: 0 }), 'Freeze frame'));
      if (later.length) cmds.push(ops.moveItems(later, 'Freeze frame'));
      const i = track.items.indexOf(item);
      cmds.push(ops.addItems([{ trackId: track.id, item: hold, index: i + 1 }, { trackId: track.id, item: right, index: i + 2 }], 'Freeze frame'));
      return { cmd: ops.batch('Freeze frame', cmds), hold };
    });
  }
  // at the very start or end of the clip: the hold sits before / after it
  const at = t <= item.t0 + 0.02 ? item.t0 : itemEnd(item);
  hold.t0 = r5(at); hold.in = r5(at === item.t0 ? srcTime(item, item.t0) : srcTime(item, itemEnd(item) - 1e-3)); hold.out = r5(hold.in + secs);
  const moves = later.map((x) => x.id === id ? x : x);
  if (at === item.t0) moves.push({ id, t0: r5(item.t0 + secs) });
  if (moves.length) cmds.push(ops.moveItems(moves, 'Freeze frame'));
  cmds.push(ops.addItems([{ trackId: track.id, item: hold, index: track.items.indexOf(item) + (at === item.t0 ? 0 : 1) }], 'Freeze frame'));
  return Promise.resolve({ cmd: ops.batch('Freeze frame', cmds), hold });
}

/** short text for the timeline badge: '0.5×', '⏪ 2×', '〰 ramp', '❄ 2.0s' (null for a plain 1× clip) */
export function speedBadge(it) {
  if (it.freeze) return '❄ ' + fmtDur(itemDur(it));
  const parts = [];
  if (it.reverse) parts.push('⏪');
  if (hasCurve(it)) parts.push('〰 ' + (PRESETS[it.curve.preset]?.label || 'Ramp'));
  else if ((it.speed || 1) !== 1) parts.push(fmtSpeed(it.speed));
  return parts.length ? parts.join(' ') : null;
}
export const fmtSpeed = (v) => (Math.round(v * 100) / 100).toString().replace(/^0\./, '0.') + '×';

// ── the editor actions (inspector, right-click menu and keys use the same ones) ──

export function setSpeed(ed, id, speed, label = 'Speed', base = null) {
  const b = base || clone(ed.project), it = findItem(b, id)?.item; if (!it) return;
  const v = Math.max(0.1, Math.min(MAX_SPEED, +speed));
  const props = { speed: v, curve: null };
  if (v < 1 && it.smooth == null && it.type === 'clip') props.smooth = 'blend';      // slow motion: frame blending by default
  ed.apply(retimeCommand(b, id, props, label));
}
export function setPreset(ed, id, key) {
  const b = clone(ed.project), it = findItem(b, id)?.item; if (!it) return;
  if (!key) { ed.apply(retimeCommand(b, id, { curve: null, speed: 1 }, 'Speed ramp off')); return; }
  const P = PRESETS[key]; const props = curveProps(it, P.pts, key);
  if (it.smooth == null) props.smooth = 'blend';
  ed.apply(retimeCommand(b, id, props, 'Speed ramp · ' + P.label));
}
export function toggleReverse(ed, id) {
  const b = clone(ed.project), it = findItem(b, id)?.item; if (!it) return;
  ed.apply(retimeCommand(b, id, { reverse: !it.reverse || null }, it.reverse ? 'Play forwards' : 'Reverse'));
}
export async function freezeFrame(ed, id, secs = 2) {
  const r = await freezeCommand(clone(ed.project), id, ed.t, secs);
  if (!r) { toast('Put the playhead over the clip to freeze a frame'); return; }
  ed.apply(r.cmd); ed.select([r.hold.id]); ed.history.seal();
  toast(`Frame held for ${secs} s`);
}

// ── the curve editor: speed (log scale, 0.1×–4×) over the clip; drag a point, double-click to add / remove one ──

const CW = 264, CH = 112, PAD = 10, VMIN = 0.1, VMAX = 4;
const yOf = (v) => PAD + (1 - Math.log(v / VMIN) / Math.log(VMAX / VMIN)) * (CH - 2 * PAD);
const vOf = (y) => VMIN * Math.pow(VMAX / VMIN, 1 - (y - PAD) / (CH - 2 * PAD));
const xOf = (u) => PAD + u * (CW - 2 * PAD);
const uOf = (x) => (x - PAD) / (CW - 2 * PAD);

export function curveEditor(ed, id, onChange) {
  const cv = h('canvas.sp-curve', { width: CW * 2, height: CH * 2, tabindex: '0', title: 'Drag a point to change the speed there · double-click to add or remove a point', 'aria-label': 'Speed curve' });
  const live = () => findItem(ed.project, id)?.item;
  const ptsOf = (it) => (hasCurve(it) ? it.curve.pts.map((p) => p.slice()) : [[0, it?.speed || 1], [1, it?.speed || 1]]);
  function draw() {
    const it = live(); if (!it) return;
    const c = cv.getContext('2d'), pts = ptsOf(it);
    c.setTransform(2, 0, 0, 2, 0, 0);
    c.clearRect(0, 0, CW, CH);
    c.fillStyle = '#17171d'; c.fillRect(0, 0, CW, CH);
    c.font = '9px system-ui'; c.textBaseline = 'middle';
    for (const g of [0.25, 0.5, 1, 2, 4]) {
      const y = yOf(g); c.strokeStyle = g === 1 ? 'rgba(255,255,255,.28)' : 'rgba(255,255,255,.08)'; c.lineWidth = 1;
      c.beginPath(); c.moveTo(PAD, y); c.lineTo(CW - PAD, y); c.stroke();
      c.fillStyle = 'rgba(255,255,255,.55)'; c.fillText(g + '×', 2, Math.min(CH - 5, Math.max(5, y - 5)));
    }
    // the window this piece shows (after a split / trim)
    const a = hasCurve(it) ? it.curve.a ?? 0 : 0, b = hasCurve(it) ? it.curve.b ?? 1 : 1;
    c.fillStyle = 'rgba(232,80,139,.10)'; c.fillRect(xOf(Math.max(0, a)), PAD, xOf(Math.min(1, b)) - xOf(Math.max(0, a)), CH - 2 * PAD);
    c.strokeStyle = '#e8508b'; c.lineWidth = 2; c.beginPath();
    for (let i = 0; i <= 120; i++) { const u = i / 120, y = yOf(Math.max(VMIN, Math.min(VMAX, curveV(pts, u)))); i ? c.lineTo(xOf(u), y) : c.moveTo(xOf(u), y); }
    c.stroke();
    for (const [u, v] of pts) { c.fillStyle = '#fff'; c.beginPath(); c.arc(xOf(u), yOf(Math.max(VMIN, Math.min(VMAX, v))), 4.5, 0, Math.PI * 2); c.fill(); }
    // the playhead, when it is over this clip
    if (ed.t >= it.t0 && ed.t < itemEnd(it)) { const u = a + ((ed.t - it.t0) / Math.max(1e-6, itemDur(it))) * (b - a); c.fillStyle = 'rgba(255,209,102,.9)'; c.fillRect(xOf(u) - 0.5, PAD, 1, CH - 2 * PAD); }
  }
  let base = null, drag = -1, pts0 = null;
  const apply = (pts, label) => { const it = findItem(base, id)?.item; if (!it) return; const props = curveProps(it, pts, null); if (it.smooth == null) props.smooth = 'blend'; ed.apply(retimeCommand(base, id, props, label)); draw(); onChange?.(); };
  const at = (e) => ({ u: Math.min(1, Math.max(0, uOf(e.offsetX))), v: Math.min(VMAX, Math.max(VMIN, vOf(e.offsetY))) });
  const nearest = (pts, e) => { let k = -1, bd = 12; pts.forEach(([u, v], i) => { const d = Math.hypot(xOf(u) - e.offsetX, yOf(v) - e.offsetY); if (d < bd) { bd = d; k = i; } }); return k; };
  cv.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    cv.focus({ preventScroll: true });
    base = clone(ed.project); const it = findItem(base, id)?.item; if (!it) return;
    pts0 = ptsOf(it); drag = nearest(pts0, e);
    if (drag < 0) return;
    cv.setPointerCapture(e.pointerId);
  });
  cv.addEventListener('pointermove', (e) => {
    if (drag < 0 || !pts0) return;
    const p = at(e), pts = pts0.map((x) => x.slice());
    const lo = drag > 0 ? pts[drag - 1][0] + 0.02 : 0, hi = drag < pts.length - 1 ? pts[drag + 1][0] - 0.02 : 1;
    pts[drag] = [drag === 0 ? 0 : drag === pts.length - 1 ? 1 : Math.min(hi, Math.max(lo, p.u)), Math.round(p.v * 100) / 100];
    apply(pts, 'Speed curve');
  });
  const end = () => { if (drag >= 0) ed.history.seal(); drag = -1; pts0 = null; };
  cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);
  cv.addEventListener('dblclick', (e) => {
    base = clone(ed.project); const it = findItem(base, id)?.item; if (!it) return;
    const pts = ptsOf(it), k = nearest(pts, e);
    if (k > 0 && k < pts.length - 1) pts.splice(k, 1);
    else if (k < 0) { const p = at(e); pts.push([p.u, Math.round(curveV(pts, p.u) * 100) / 100]); pts.sort((x, y) => x[0] - y[0]); }
    else return;
    apply(pts, 'Speed curve'); ed.history.seal();
  });
  draw();
  return { el: cv, draw };
}

/** the inspector's Speed section for a clip or a sound */
export function speedSection(insp, item, m) {
  const ed = insp.ed, id = item.id, isClip = item.type === 'clip' && m?.kind === 'video';
  const info = h('small.muted.sp-info');
  const curve = isClip ? curveEditor(ed, id, () => note()) : null;
  function note() {
    const it = findItem(ed.project, id)?.item; if (!it) return;
    const S = it.out - it.in, D = itemDur(it);
    const fps = m?.fps || 30, hi = fps >= 50 ? ` · ${Math.round(fps)} fps source: real frames down to ${fmtSpeed(Math.max(0.05, 30 / fps))}` : '';
    info.textContent = it.freeze ? `Holds one frame for ${fmtDur(D)}` : `${fmtDur(S)} of footage plays in ${fmtDur(D)}${hasCurve(it) ? ` (average ${fmtSpeed(S / Math.max(1e-6, D))})` : ''}${hi}`;
    curve?.draw();
  }
  let base = null;
  const cur = hasCurve(item) ? '' : String(item.speed || 1);
  const speeds = [['0.25', '¼×'], ['0.5', '½×'], ['1', '1×'], ['1.5', '1.5×'], ['2', '2×'], ['3', '3×']];
  const segEl = h('div.seg.sp-seg', speeds.map(([v, l]) => h('button' + (v === cur ? '.on' : ''), { type: 'button', title: `Play at ${l}`, dataset: { v }, on: { click: (e) => { for (const b of e.target.parentNode.children) b.classList.toggle('on', b === e.target); setSpeed(ed, id, +v); ed.history.seal(); insp.render(true); } } }, l)));
  const sl = h('input', { type: 'range', min: 0.1, max: 4, step: 0.05, value: item.speed || 1, 'aria-label': 'Custom speed' });
  const slv = h('span.val', fmtSpeed(item.speed || 1));
  sl.addEventListener('input', () => { base ||= clone(ed.project); const v = +sl.value; slv.textContent = fmtSpeed(v); setSpeed(ed, id, v, 'Speed', base); note(); });
  sl.addEventListener('change', () => { base = null; ed.history.seal(); });
  const kids = [segEl, h('label.row.slider', h('span.lbl', 'Custom'), sl, slv)];
  if (isClip) {
    const presetKey = hasCurve(item) ? item.curve.preset || 'custom' : '';
    kids.push(h('div.sp-sub', 'Speed ramp'),
      h('div.sp-presets', h('button.chip' + (!presetKey ? '.on' : ''), { type: 'button', title: 'No ramp: one speed for the whole clip', on: { click: () => { setPreset(ed, id, null); ed.history.seal(); insp.render(true); } } }, 'None'),
        ...Object.entries(PRESETS).map(([k, P]) => h('button.chip' + (presetKey === k ? '.on' : ''), { type: 'button', dataset: { preset: k }, title: P.label + ' — ' + P.pts.map((p) => fmtSpeed(p[1])).filter((x, i, a) => a.indexOf(x) === i).join(' → '), on: { click: () => { setPreset(ed, id, k); ed.history.seal(); insp.render(true); } } }, P.icon + ' ' + P.label))),
      curve.el);
  }
  kids.push(info);
  if (isClip) {
    kids.push(h('label.row', { title: 'Smooth slow motion: how the frames between two real frames are made (frame blend live and in the export; optical flow in the export)' }, h('span.lbl', 'Slow-mo'), select([['off', 'Off'], ['blend', 'Frame blend'], ['flow', 'Optical flow (export)']], item.smooth || 'off', (v) => { insp.set(item, { smooth: v === 'off' ? null : v }, 'Smooth slow-mo'); insp.seal(); })));
    kids.push(h('div.btn-grid.two.sp-btns',
      h('button.tool' + (item.reverse ? '.on' : ''), { type: 'button', title: 'Play the clip backwards', on: { click: () => { toggleReverse(ed, id); ed.history.seal(); insp.render(true); } } }, item.reverse ? '⏩ Forwards' : '⏪ Reverse'),
      h('button.tool', { type: 'button', title: 'Hold the frame under the playhead (splits the clip, adds the hold, moves the rest on)', on: { click: () => freezeFrame(ed, id, insp.freezeSecs || 2) } }, '❄ Freeze frame')));
    kids.push(h('label.row', h('span.lbl', 'Hold for'), select([['1', '1 s'], ['2', '2 s'], ['3', '3 s'], ['5', '5 s']], String(insp.freezeSecs || 2), (v) => { insp.freezeSecs = +v; })));
  }
  if (m?.hasAudio) {
    kids.push(h('label.row.check', h('input', { type: 'checkbox', checked: item.keepPitch !== false, on: { change: (e) => { insp.set(item, { keepPitch: e.target.checked ? null : false }, 'Keep pitch'); insp.seal(); } } }), h('span', 'Keep pitch (natural voice at any speed)')));
    if (isClip) kids.push(h('label.row.check', h('input', { type: 'checkbox', checked: !!item.rampMute, on: { change: (e) => { insp.set(item, { rampMute: e.target.checked || null }, 'Mute while ramping'); insp.seal(); } } }), h('span', 'Mute sound while ramping')));
  }
  if (m && m.kind !== 'image') kids.push(h('small.muted', `Uses ${fmtDur(item.out - item.in)} of ${fmtDur(m.dur)} source`));
  note();
  return insp.section('Speed', ...kids);
}

// ── right-click a clip on the timeline → Speed ──
let menuEl = null;
export function closeMenu() { menuEl?.remove(); menuEl = null; }
export function clipMenu(ed, id, x, y) {
  closeMenu();
  const it = findItem(ed.project, id)?.item; if (!it) return;
  const m = it.media ? findMedia(ed.project, it.media) : null, video = it.type === 'clip' && m?.kind === 'video';
  const act = (fn) => () => { closeMenu(); fn(); ed.history.seal(); ed.inspector.render(true); };
  const btn = (label, fn, on = false, title) => h('button' + (on ? '.on' : ''), { type: 'button', role: 'menuitem', title: title || label, on: { click: act(fn) } }, label);
  const rows = [h('div.sp-menu-h', 'Speed')];
  const cur = hasCurve(it) ? null : it.speed || 1;
  rows.push(h('div.sp-menu-row', ...[0.25, 0.5, 1, 1.5, 2, 3].map((v) => btn(fmtSpeed(v), () => setSpeed(ed, id, v), cur === v, `Play at ${fmtSpeed(v)}`))));
  if (video) {
    rows.push(h('div.sp-menu-h', 'Speed ramp'));
    for (const [k, P] of Object.entries(PRESETS)) rows.push(btn(P.icon + ' ' + P.label, () => setPreset(ed, id, k), hasCurve(it) && it.curve.preset === k));
    rows.push(h('div.sp-menu-sep'));
    rows.push(btn(it.reverse ? '⏩ Play forwards' : '⏪ Reverse', () => toggleReverse(ed, id)));
    rows.push(btn('❄ Freeze frame here (2 s)', () => freezeFrame(ed, id, 2)));
  }
  if (cur !== 1 || hasCurve(it) || it.reverse) rows.push(btn('↺ Normal speed', () => ed.apply(retimeCommand(clone(ed.project), id, { speed: 1, curve: null, reverse: null }, 'Normal speed'))));
  menuEl = h('div.sp-menu', { role: 'menu', style: { left: Math.round(x) + 'px', top: Math.round(y) + 'px' } }, rows);
  document.body.appendChild(menuEl);
  // keep it on screen: open upwards / leftwards when it would run past the window (one read of its own size)
  const r = menuEl.getBoundingClientRect();
  if (r.bottom > innerHeight - 6) menuEl.style.top = Math.max(6, y - r.height) + 'px';
  if (r.right > innerWidth - 6) menuEl.style.left = Math.max(6, x - r.width) + 'px';
  menuEl.querySelector('button')?.focus({ preventScroll: true });
}
addEventListener('pointerdown', (e) => { if (menuEl && !menuEl.contains(e.target)) closeMenu(); }, true);
addEventListener('keydown', (e) => { if (menuEl && e.key === 'Escape') { e.stopPropagation(); closeMenu(); } }, true);
addEventListener('blur', closeMenu);

export { curveMean, MIN_SPEED };
