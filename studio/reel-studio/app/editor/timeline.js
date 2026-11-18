// KBB Studio — the multi-track timeline: playhead, zoom, split, trim handles, move between tracks, snapping,
// marquee-select of clips, markers, waveforms, thumbnails, lock/mute/hide.

import { ops } from '../core/history.js';
import { findMedia, findTrack, findItem, itemDur, itemEnd, createTrack, VISUAL_KINDS, srcTime, clone } from '../core/project.js';
import { trimProps, stretchProps, MAX_SPEED } from '../core/retime.js';
import { speedBadge, clipMenu, retimeCommand, fmtSpeed } from './speed.js';
import { h, clear, toast, fmtDur } from './ui.js';
import { fits } from './items.js';
import { listTimelineStrips } from '../core/modules.js';
import * as TD from './tl-drag.js';   // drag & drop, blade, ranges, ripple trim (tl-drag.js / edit-ops.js)

const HEAD_W = 176;
const ROW_H = { video: 66, overlay: 50, text: 42, caption: 42, music: 50, sfx: 42 };
const KIND_ICON = { video: '🎬', overlay: '🖼', text: 'T', caption: '💬', music: '🎵', sfx: '🔊' };
const SNAP_PX = 8;

export class Timeline {
  constructor(ed, el) {
    this.ed = ed; this.el = el;
    this.pps = 40;
    this.snapOn = true;
    this.fitted = false;
    this.snapBtn = h('button.chip.on', { title: 'Snapping', on: { click: () => { this.snapOn = !this.snapOn; this.snapBtn.classList.toggle('on', this.snapOn); } } }, '🧲 Snap');
    const tools = h('div.tl-tools',
      h('button.tool', { title: 'Split at playhead (S or ⌘B)', on: { click: () => ed.split() } }, '✂ Split'),
      ...TD.extraTools(this),
      h('button.tool', { title: 'Delete (Delete) — Shift+Delete closes the gap', on: { click: (e) => ed.deleteSelection(e.shiftKey) } }, '🗑 Delete'),
      h('button.tool', { title: 'Duplicate (⌘D)', on: { click: () => ed.duplicate() } }, '⧉ Duplicate'),
      h('button.tool', { title: 'Add marker (M)', on: { click: () => ed.addMarker() } }, '⚑ Marker'),
      this.snapBtn,
      h('div.grow'),
      this.addTrackSel(),
      h('button.icon-btn', { title: 'Zoom out (−)', on: { click: () => this.zoom(0.8) } }, '−'),
      this.zoomIn = h('input.zoom', { type: 'range', min: 0, max: 100, value: 40, title: 'Timeline zoom', on: { input: () => { this.autoFit = false; this.setPps(this.sliderToPps(+this.zoomIn.value)); } } }),
      h('button.icon-btn', { title: 'Zoom in (+)', on: { click: () => this.zoom(1.25) } }, '+'),
      h('button.chip', { title: 'Fit the whole reel (\\)', on: { click: () => this.fit() } }, 'Fit'),
    );
    this.scroll = h('div.tl-scroll');
    this.content = h('div.tl-content');
    this.scroll.appendChild(this.content);
    el.append(tools, this.scroll);
    this.scroll.addEventListener('pointerdown', (e) => this.onDown(e));
    this.scroll.addEventListener('wheel', (e) => {
      if (e.ctrlKey || e.metaKey) { e.preventDefault(); this.zoom(e.deltaY < 0 ? 1.15 : 0.87, e.clientX); }
    }, { passive: false });
    this.scroll.addEventListener('dragover', (e) => TD.onDragOver(this, e));
    this.scroll.addEventListener('dragleave', (e) => TD.onDragLeave(this, e));
    this.scroll.addEventListener('drop', (e) => { if (!TD.onDrop(this, e)) this.onDrop(e); });
    this.autoFit = true;   // keep fitting the whole reel until the owner zooms by hand
    this.ro = new ResizeObserver(() => { if (this.autoFit && this.scroll.clientWidth > 0) this.fit(true); });
    this.ro.observe(this.scroll);
  }
  destroy() { this.ro.disconnect(); }

  addTrackSel() {
    const s = h('select.add-track', { title: 'Add a track' }, h('option', { value: '' }, '+ Track'),
      ['overlay', 'text', 'video', 'music', 'sfx'].map((k) => h('option', { value: k }, { overlay: 'Overlay (pictures, video)', text: 'Text', video: 'Video', music: 'Music', sfx: 'Sound FX' }[k])));
    s.addEventListener('change', () => {
      const kind = s.value; s.value = ''; if (!kind) return;
      const p = this.ed.project;
      // visual tracks go above the last track of their kind; audio at the end
      let at = p.tracks.length;
      if (VISUAL_KINDS.has(kind)) { const last = p.tracks.map((t) => t.kind).lastIndexOf(kind); at = last >= 0 ? last + 1 : p.tracks.findIndex((t) => !VISUAL_KINDS.has(t.kind)); }
      const n = p.tracks.filter((t) => t.kind === kind).length + 1;
      this.ed.apply(ops.addTrack(createTrack(kind, createTrack(kind).name + ' ' + n), at < 0 ? p.tracks.length : at));
    });
    return s;
  }

  // ── zoom ──
  sliderToPps(v) { return 4 * Math.pow(100, v / 100); }   // 4 .. 400 px per second
  ppsToSlider(p) { return Math.round((Math.log(p / 4) / Math.log(100)) * 100); }
  setPps(p, anchorX) {
    const old = this.pps; p = Math.max(4, Math.min(400, p));
    const rect = this.scroll.getBoundingClientRect();
    const ax = anchorX != null ? anchorX - rect.left - HEAD_W : this.ed.t * old - this.scroll.scrollLeft;
    const tAt = (this.scroll.scrollLeft + ax) / old;
    this.pps = p; this.zoomIn.value = this.ppsToSlider(p);
    this.render();
    this.scroll.scrollLeft = Math.max(0, tAt * p - ax);
  }
  zoom(f, x) { this.autoFit = false; this.setPps(this.pps * f, x); }
  fit(auto = false) {
    const w = this.scroll.clientWidth - HEAD_W - 30;
    if (w <= 0) return;
    if (!auto) this.autoFit = true;
    this.fitDur = this.ed.duration();
    this.setPps(w / Math.max(5, this.fitDur * 1.05));
    this.scroll.scrollLeft = 0;
  }

  /** display order: the top layer first (visual tracks reversed), then sound tracks */
  rowsOrder() {
    const t = this.ed.project.tracks;
    return [...t.filter((x) => VISUAL_KINDS.has(x.kind)).reverse(), ...t.filter((x) => !VISUAL_KINDS.has(x.kind))];
  }

  render() {
    // while auto-fitting, a reel that grows past the right edge refits (never while the owner drags)
    if (this.autoFit && !this.dragging && this.ed.duration() > (this.fitDur || 0) * 1.05 && this.scroll.clientWidth > 0) { this.fitDur = this.ed.duration(); this.pps = Math.max(4, Math.min(400, (this.scroll.clientWidth - HEAD_W - 30) / Math.max(5, this.fitDur * 1.05))); this.zoomIn.value = this.ppsToSlider(this.pps); }
    const ed = this.ed, p = ed.project, pps = this.pps;
    const T = Math.max(ed.duration(), 5);
    const W = Math.max(this.scroll.clientWidth - HEAD_W, (T + 10) * pps);
    clear(this.content);
    this.content.style.width = W + HEAD_W + 'px';
    // ruler
    const ruler = h('div.tl-ruler', h('div.tl-corner', fmtDur(ed.duration())));
    const lane = h('div.ruler-lane', { style: { width: W + 'px' } });
    const steps = [0.1, 0.25, 0.5, 1, 2, 5, 10, 15, 30, 60];
    const step = steps.find((s) => s * pps >= 64) || 60;
    for (let s = 0; s * pps < W; s += step) {
      lane.appendChild(h('div.tick', { style: { left: s * pps + 'px' } }, h('span', step < 1 ? s.toFixed(step < 0.5 ? 2 : 1) + 's' : (s >= 60 ? `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}` : Math.round(s) + 's'))));
      for (let q = 1; q < 4; q++) if ((s + (q * step) / 4) * pps < W) lane.appendChild(h('div.tick.minor', { style: { left: (s + (q * step) / 4) * pps + 'px' } }));
    }
    for (const m of p.markers) {
      lane.appendChild(h('div.marker', { title: `${m.label} — click to jump, right-click to remove`, style: { left: m.t * pps + 'px', '--mc': m.color || '#ffd166' }, dataset: { marker: m.id } }));
    }
    ruler.appendChild(lane);
    this.content.appendChild(ruler);
    // strips modules draw under the ruler (core/modules.js registerTimelineStrip), e.g. the retention heat map
    for (const st of listTimelineStrips()) {
      const sl = h('div.strip-lane', { style: { width: W + 'px' } });
      try { st.draw(sl, { project: p, pps, width: W }); } catch (e) { console.warn('[strip]', st.id, e); }
      this.content.appendChild(h('div.tl-strip', { dataset: { strip: st.id } }, h('div.tl-corner.strip-head', { title: st.title || st.label || '' }, st.label || ''), sl));
    }
    // rows
    this.rows = [];
    for (const track of this.rowsOrder()) {
      const row = h('div.tl-row.k-' + track.kind + (track.locked ? '.locked' : '') + (track.hidden || track.muted ? '.dim' : ''), { style: { height: (ROW_H[track.kind] || 46) + 'px' }, dataset: { track: track.id } });
      const isAudio = track.kind === 'music' || track.kind === 'sfx';
      const head = h('div.tl-head',
        h('span.k-ic', KIND_ICON[track.kind] || '•'), h('span.t-name', { title: track.name }, track.name),
        h('div.t-btns',
          h('button.tb' + (track.locked ? '.on' : ''), { title: track.locked ? 'Unlock track' : 'Lock track', on: { click: () => ed.apply(ops.setTrack(track.id, { locked: !track.locked }, 'Lock track')) } }, track.locked ? '🔒' : '🔓'),
          !isAudio ? h('button.tb' + (track.hidden ? '.on' : ''), { title: track.hidden ? 'Show track' : 'Hide track', on: { click: () => ed.apply(ops.setTrack(track.id, { hidden: !track.hidden }, 'Hide track')) } }, track.hidden ? '🙈' : '👁') : null,
          isAudio || track.kind === 'video' || track.kind === 'overlay' ? h('button.tb' + (track.muted ? '.on' : ''), { title: track.muted ? 'Unmute track' : 'Mute track', on: { click: () => ed.apply(ops.setTrack(track.id, { muted: !track.muted }, 'Mute track')) } }, track.muted ? '🔇' : '🔈') : null,
          track.items.length === 0 && p.tracks.filter((t) => t.kind === track.kind).length > 1 ? h('button.tb', { title: 'Remove empty track', on: { click: () => ed.apply(ops.removeTrack(track.id)) } }, '✕') : null,
        ));
      const laneEl = h('div.tl-lane', { style: { width: W + 'px' } });
      for (const it of track.items) laneEl.appendChild(this.itemEl(it, track));
      row.append(head, laneEl);
      this.content.appendChild(row);
      this.rows.push({ track, row, lane: laneEl });
    }
    this.playhead = h('div.tl-playhead', h('div.ph-knob'));
    this.content.appendChild(this.playhead);
    TD.drawRange(this);
    this.dropEl = null;
    this.renderPlayhead();
    this.renderSelection();
  }

  itemEl(it, track) {
    const ed = this.ed, pps = this.pps, m = it.media ? findMedia(ed.project, it.media) : null;
    const w = Math.max(4, itemDur(it) * pps);
    const label = it.type === 'text' ? (it.text || 'Text').split('\n')[0] : it.type === 'sticker' ? it.text : it.name || m?.name || it.type;
    const badge = speedBadge(it);
    const el = h('div.tl-item.t-' + it.type + (m ? '.m-' + m.kind : ''), { dataset: { id: it.id }, style: { left: it.t0 * pps + 'px', width: w + 'px' }, title: `${label} · ${fmtDur(itemDur(it))}${badge ? ' · ' + badge : ''}${m && m.kind !== 'image' ? ' · right-click: Speed · Shift-drag an edge: stretch' : ''}` },
      badge && w > 34 ? h('span.sp-badge', { 'aria-label': 'Speed ' + badge }, badge) : null,
      h('span.it-lbl', label),
      h('div.trim.l', { dataset: { trim: 'l' } }), h('div.trim.r', { dataset: { trim: 'r' } }));
    if (it.group) el.classList.add('grouped');
    if (m && (m.kind === 'video' || m.kind === 'image') && w > 24) {
      const c = h('canvas.thumbs'); el.prepend(c); this.drawThumbs(c, it, m, w);
    }
    if (m && m.hasAudio && w > 10 && (m.kind === 'audio' || track.kind === 'video')) {
      const c = h('canvas.wave'); el.appendChild(c); this.drawWave(c, it, m, w);
    }
    return el;
  }

  async drawThumbs(c, it, m, w) {
    const H = (ROW_H.video - 6);
    c.width = Math.min(4096, Math.round(w)); c.height = H;
    const list = await this.ed.media.thumbs(m.id, { count: m.kind === 'image' ? 1 : 16, h: 56 }).catch(() => []);
    if (!c.isConnected || !list.length) return;
    const x = c.getContext('2d');
    const tw = list[0].bitmap.width * (H / list[0].bitmap.height);
    for (let px = 0; px < c.width; px += tw) {
      const st = srcTime(it, it.t0 + (px + tw / 2) / this.pps);
      let best = list[0]; for (const th of list) if (Math.abs(th.t - st) < Math.abs(best.t - st)) best = th;
      x.drawImage(best.bitmap, px, 0, tw, H);
    }
  }

  async drawWave(c, it, m, w) {
    const peaks = await this.ed.media.peaks(m.id, 50).catch(() => null);
    if (!c.isConnected || !peaks) return;
    const H = m.kind === 'audio' ? 36 : 18;
    c.width = Math.min(4096, Math.round(w)); c.height = H;
    const x = c.getContext('2d');
    x.fillStyle = m.kind === 'audio' ? 'rgba(255,255,255,.75)' : 'rgba(120,230,190,.9)';
    const vol = Math.min(1.5, it.volume ?? 1);
    for (let px = 0; px < c.width; px++) {
      const st = srcTime(it, it.t0 + px / this.pps);
      const v = (peaks[Math.floor(st * 50)] || 0) * vol;
      const bh = Math.max(1, v * H);
      x.fillRect(px, m.kind === 'audio' ? (H - bh) / 2 : H - bh, 1, bh);
    }
  }

  renderSelection() {
    for (const el of this.content.querySelectorAll('.tl-item')) el.classList.toggle('sel', this.ed.sel.has(el.dataset.id));
  }

  renderPlayhead() {
    if (!this.playhead) return;
    const x = this.ed.t * this.pps;
    this.playhead.style.transform = `translateX(${x + HEAD_W}px)`;
    if (this.ed.playing) {
      const sl = this.scroll.scrollLeft, vw = this.scroll.clientWidth - HEAD_W;
      if (x > sl + vw - 40 || x < sl) this.scroll.scrollLeft = Math.max(0, x - 80);
    }
  }

  // ── pointer ──
  timeAt(clientX) {
    const r = this.content.getBoundingClientRect();
    return Math.max(0, (clientX - r.left - HEAD_W) / this.pps);
  }
  rowAt(clientY) {
    for (const r of this.rows) { const b = r.row.getBoundingClientRect(); if (clientY >= b.top && clientY < b.bottom) return r; }
    return null;
  }
  /** playhead, item edges, markers, caption line edges, beats (tl-drag.js) */
  snapPoints(exclude) { return TD.snapPoints(this.ed, exclude); }
  snapT(vals, pts) { return !this.snapOn || this.freeSnap ? 0 : TD.snapDelta(vals, pts, this.pps); }
  track(e, move, up) {
    const id = e.pointerId;
    const mv = (ev) => { if (ev.pointerId === id) move(ev); };
    this.dragging = true;
    const end = (ev) => { if (ev.pointerId !== id) return; this.dragging = false; window.removeEventListener('pointermove', mv); window.removeEventListener('pointerup', end); window.removeEventListener('pointercancel', end); up?.(ev); this.ed.history.seal(); this.content.querySelector('.snap-line')?.remove(); };
    window.addEventListener('pointermove', mv); window.addEventListener('pointerup', end); window.addEventListener('pointercancel', end);
  }
  showSnapLine(t) {
    let l = this.content.querySelector('.snap-line');
    if (t == null) { l?.remove(); return; }
    if (!l) { l = h('div.snap-line'); this.content.appendChild(l); }
    l.style.transform = `translateX(${t * this.pps + HEAD_W}px)`;
  }

  onDown(e) {
    if (e.button !== 0) return;
    const ed = this.ed, tgt = e.target;
    if (tgt.closest('.tl-head') || tgt.closest('.tl-corner')) return;
    const mk = tgt.closest('.marker');
    if (mk) { const m = ed.project.markers.find((x) => x.id === mk.dataset.marker); if (m) ed.seek(m.t); return; }
    if (e.shiftKey && tgt.closest('.tl-ruler')) { e.preventDefault(); TD.dragRange(this, e); return; }   // Shift-drag on the ruler: a range
    if (tgt.closest('.tl-ruler') || tgt.closest('.tl-strip') || tgt.closest('.tl-playhead')) { e.preventDefault(); ed.pause(); ed.seek(this.timeAt(e.clientX)); this.track(e, (ev) => ed.seek(this.timeAt(ev.clientX))); return; }
    const itEl = tgt.closest('.tl-item');
    if (itEl) {
      e.preventDefault();
      const id = itEl.dataset.id;
      if (this.blade) { TD.bladeAt(this, e, id); return; }
      if ((e.shiftKey && !tgt.dataset.trim) || e.metaKey || e.ctrlKey) { ed.select([id], 'toggle'); return; }
      if (!ed.sel.has(id)) ed.select([id]);
      const trim = tgt.dataset.trim;
      return trim ? (e.shiftKey ? this.dragStretch(e, id, trim) : this.dragTrim(e, id, trim)) : TD.dragMove(this, e, id);
    }
    if (tgt.closest('.tl-lane') || tgt === this.content || tgt === this.scroll) { e.preventDefault(); this.dragMarquee(e); }
  }

  /** move: tl-drag.js (drop indicator, insert / overwrite, new tracks, one undo step on drop) */
  dragMove(e, id) { return TD.dragMove(this, e, id); }

  /** trim either edge; Alt = ripple trim (the items after it on the track ride the edge) */
  dragTrim(e, id, side) {
    const ed = this.ed, hit = ed.selectedItems().find((x) => x.item.id === id) || null;
    const it = hit?.item; if (!it || hit.track.locked) return;
    const m = it.media ? findMedia(ed.project, it.media) : null;
    const hasSrc = m && m.kind !== 'image';
    const s0 = { t0: it.t0, in: it.in, out: it.out, dur: itemDur(it), end: itemEnd(it) }, sp = it.speed || 1;
    const pts = this.snapPoints(new Set([id]));
    const ripple = e.altKey, followers = ripple ? TD.trimFollowers(hit.track, it) : [];
    const x0 = e.clientX, MIN = 0.1;
    if (hasSrc && (it.curve || it.reverse || it.freeze)) {
      // a ramp, a reversed clip or a hold: trim through its time mapping, so the frames that stay keep their timing
      const end0 = itemEnd(it), it0 = clone(it);
      this.track(e, (ev) => {
        let dt = (ev.clientX - x0) / this.pps;
        const edge = side === 'l' ? s0.t0 + dt : end0 + dt, sd = this.snapT([edge], pts); dt += sd; this.showSnapLine(sd ? edge + sd : null);
        // Alt on the left edge (ripple): the clip keeps its start and the trimmed time comes off its end, so the edge may
        // reach back past 0 (footage before the in point) — the props are worked out where the edge is, then moved home
        const a = side === 'l' ? Math.min(end0 - MIN, ripple ? s0.t0 + dt : Math.max(0, s0.t0 + dt)) : s0.t0, b = side === 'l' ? end0 : Math.max(s0.t0 + MIN, end0 + dt);
        const props = trimProps(it0, a, b, it.freeze ? Infinity : (m.dur || Infinity));
        if (ripple && side === 'l') props.t0 = s0.t0;
        // Alt (ripple): what follows on the track rides the new end
        const rip = ripple ? TD.rippleProps(followers, (props.t0 ?? a) + itemDur({ ...it0, ...props }) - end0) : [];
        ed.apply(ops.setItems([{ id, props }, ...rip], ripple ? 'Ripple trim' : 'Trim'));
      });
      return;
    }
    this.track(e, (ev) => {
      this.freeSnap = ev.metaKey || ev.ctrlKey;
      let dt = (ev.clientX - x0) / this.pps;
      let props, dEnd;
      if (side === 'l') {
        if (!ripple) { const sd = this.snapT([s0.t0 + dt], pts); dt += sd; this.showSnapLine(sd ? s0.t0 + dt : null); }
        if (!ripple) dt = Math.max(dt, -s0.t0);
        dt = Math.min(dt, s0.dur - MIN);
        if (hasSrc) { dt = Math.max(dt, -s0.in / sp); props = ripple ? { in: s0.in + dt * sp } : { t0: s0.t0 + dt, in: s0.in + dt * sp }; }
        else props = ripple ? { out: s0.out - dt * sp } : { t0: s0.t0 + dt, out: s0.out - dt * sp };
        dEnd = -dt;
      } else {
        const sd = this.snapT([s0.end + dt], pts); dt += sd; this.showSnapLine(sd ? s0.end + dt : null);
        let out = s0.out + dt * sp;
        out = Math.max(s0.in + MIN * sp, out);
        if (hasSrc) out = Math.min(out, m.dur || out);
        props = { out }; dEnd = (out - s0.out) / sp;
      }
      ed.apply(ops.setItems([{ id, props }, ...(ripple ? TD.rippleProps(followers, dEnd) : [])], ripple ? 'Ripple trim' : 'Trim'));
    }, () => { this.freeSnap = false; });
  }

  /** Shift-drag on a clip's edge: retime it — the same footage stretched or squeezed to the new length (later clips ripple) */
  dragStretch(e, id, side) {
    const ed = this.ed, hit = ed.selectedItems().find((x) => x.item.id === id) || null;
    const it = hit?.item; if (!it || hit.track.locked) return;
    const base = clone(ed.project), t00 = it.t0, end0 = itemEnd(it), x0 = e.clientX, pts = this.snapPoints(new Set([id]));
    const tip = h('div.sp-stretch-tip'); this.content.appendChild(tip);
    this.track(e, (ev) => {
      let dt = (ev.clientX - x0) / this.pps;
      const edge = side === 'l' ? t00 + dt : end0 + dt, sd = this.snapT([edge], pts); dt += sd; this.showSnapLine(sd ? edge + sd : null);
      let d = side === 'l' ? end0 - Math.max(0, t00 + dt) : end0 + dt - t00;
      const S = it.out - it.in; d = Math.max(S / MAX_SPEED, Math.min(S / 0.1, d));
      const props = Object.assign(stretchProps(it, d), side === 'l' ? { t0: end0 - d } : {});
      if (props.speed < 1 && it.smooth == null && it.type === 'clip') props.smooth = 'blend';
      ed.apply(retimeCommand(base, id, props, 'Stretch'));
      tip.textContent = (it.curve ? 'avg ' : '') + fmtSpeed(props.speed) + ' · ' + fmtDur(d);
      tip.style.transform = `translateX(${(side === 'l' ? end0 - d : t00 + d) * this.pps + HEAD_W}px)`;
    }, () => tip.remove());
  }

  dragMarquee(e) {
    const ed = this.ed, r0 = this.content.getBoundingClientRect();
    const x0 = e.clientX - r0.left, y0 = e.clientY - r0.top, add = e.shiftKey || e.metaKey || e.ctrlKey;
    let box = null, moved = false;
    this.track(e, (ev) => {
      const r = this.content.getBoundingClientRect();
      const x1 = ev.clientX - r.left, y1 = ev.clientY - r.top;
      if (!moved && Math.hypot(x1 - x0, y1 - y0) < 4) return;
      moved = true;
      if (!box) { box = h('div.tl-marquee'); this.content.appendChild(box); }
      const b = { l: Math.min(x0, x1), t: Math.min(y0, y1), w: Math.abs(x1 - x0), h: Math.abs(y1 - y0) };
      Object.assign(box.style, { left: b.l + 'px', top: b.t + 'px', width: b.w + 'px', height: b.h + 'px' });
      this.mq = b;
    }, (ev) => {
      box?.remove();
      if (!moved) { ed.seek(this.timeAt(ev.clientX)); if (!add) ed.select([]); return; }
      const r = this.content.getBoundingClientRect(), b = this.mq, ids = [];
      for (const el of this.content.querySelectorAll('.tl-item')) {
        const q = el.getBoundingClientRect();
        const l = q.left - r.left, t = q.top - r.top;
        if (l < b.l + b.w && l + q.width > b.l && t < b.t + b.h && t + q.height > b.t) {
          const tr = this.rows.find((x) => x.row.contains(el))?.track;
          if (tr && !tr.locked) ids.push(el.dataset.id);
        }
      }
      ed.select(ids, add ? 'add' : 'replace');
    });
  }

  onDrop(e) {
    const ed = this.ed;
    const t0 = this.timeAt(e.clientX);
    const row = this.rowAt(e.clientY);
    if (e.dataTransfer.files?.length) { e.preventDefault(); ed.importFiles([...e.dataTransfer.files], { trackId: row?.track.id, t0 }); return; }
    const mid = e.dataTransfer.getData('application/x-kbb-media');
    const lib = e.dataTransfer.getData('application/x-kbb-lib');
    const tel = e.dataTransfer.getData('application/x-kbb-theme-el');
    const ttr = e.dataTransfer.getData('application/x-kbb-theme-trans');
    if (ttr) {   // a country transition: lands on the cut nearest the drop
      e.preventDefault(); let spec = null; try { spec = JSON.parse(ttr); } catch { return; }
      import('../themes/transitions.js').then((T) => { const [it] = T.placeTransition(ed.context(), spec.kind, { at: t0, theme: spec.theme }); ed.select([it.id]); }).catch((err) => { console.warn('[timeline] transition', err); import('./ui.js').then((u) => u.toast(err.message, 'error')); });
      return;
    }
    e.preventDefault();
    if (tel) {   // a theme element from Themes → Place elements: lands at the drop time, on the row under the pointer when it fits
      let spec = null; try { spec = JSON.parse(tel); } catch { return; }
      import('../themes/apply.js').then((A) => { const it = A.placeElement(ed.context(), spec.el, { theme: spec.theme, variant: spec.variant, t0, trackId: row && !row.track.locked && /^(overlay|text)$/.test(row.track.kind) && !row.track.theme ? row.track.id : null }); ed.select([it.id]); }).catch((err) => console.warn('[timeline] theme element', err));
      return;
    }
    if (mid) {
      const m = findMedia(ed.project, mid); if (!m) return;
      const type = m.kind === 'audio' ? 'audio' : 'clip';
      const ok = row && fits(type, row.track.kind) && !row.track.locked;
      ed.addMediaToTimeline(m, { trackId: ok ? row.track.id : null, t0 });
    } else if (lib) {
      ed.bin.addLibrary(lib, { trackId: row && fits('audio', row.track.kind) ? row.track.id : null, t0 });
    }
  }
}

// right-click a clip: the Speed menu (editor/speed.js)
document.addEventListener('contextmenu', (e) => {
  const el = e.target.closest?.('.tl-item'); const ed = window.kbbEditor;
  if (!el || !ed) return;
  const it = findItem(ed.project, el.dataset.id)?.item; if (!it || !(it.type === 'clip' || it.type === 'audio')) return;
  const m = it.media ? findMedia(ed.project, it.media) : null; if (!m || m.kind === 'image') return;
  e.preventDefault();
  if (!ed.sel.has(it.id)) ed.select([it.id]);
  clipMenu(ed, it.id, e.clientX, e.clientY);
});

// right-click a marker to remove it
document.addEventListener('contextmenu', (e) => {
  const mk = e.target.closest?.('.marker'); const ed = window.kbbEditor;
  if (!mk || !ed) return;
  e.preventDefault();
  ed.apply(ops.setProject({ markers: ed.project.markers.filter((m) => m.id !== mk.dataset.marker) }, 'Remove marker'));
  toast('Marker removed');
});

export { findTrack };
