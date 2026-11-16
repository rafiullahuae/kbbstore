// KBB Studio — the preview monitor: draws through core/render.js renderFrame (the same path as export) and handles
// on-canvas editing: select, move, resize, rotate, marquee (also when the drag starts over the video),
// Shift/Ctrl-click, snapping guides, safe-area guides.

import { renderFrame, activeItems, boxAt, fadeAt } from '../core/render.js';
import { findItem, findMedia, srcTime, isActive, safeAreas } from '../core/project.js';
import { ops } from '../core/history.js';
import { seekExact } from '../core/media.js';
import { h, clear, fmtTime } from './ui.js';

const SNAP_PX = 7;

export class Preview {
  constructor(ed, el) {
    this.ed = ed; this.el = el;
    this.guides = true;
    this.canvas = h('canvas.pv-canvas');
    this.ctx = this.canvas.getContext('2d');
    this.overlay = h('div.pv-overlay', { tabindex: '-1' });
    this.stage = h('div.pv-stage', this.canvas, this.overlay);
    this.timeEl = h('span.pv-time');
    this.playBtn = h('button.play-btn', { title: 'Play / pause (Space)', on: { click: () => ed.toggle() } }, '▶');
    this.guideBtn = h('button.chip.on', { title: 'Show safe areas', on: { click: () => { this.guides = !this.guides; this.guideBtn.classList.toggle('on', this.guides); this.drawOverlay(); } } }, '▣ Safe areas');
    el.appendChild(h('div.pv-wrap', this.stage));
    el.appendChild(h('div.transport',
      h('button.icon-btn', { title: 'Start (Home)', on: { click: () => ed.seek(0) } }, '⏮'),
      h('button.icon-btn', { title: 'Back one frame (←)', on: { click: () => ed.seek(ed.t - ed.frame()) } }, '◀︎'),
      this.playBtn,
      h('button.icon-btn', { title: 'Forward one frame (→)', on: { click: () => ed.seek(ed.t + ed.frame()) } }, '▶︎'),
      this.timeEl, h('div.grow'), this.guideBtn));
    this.ro = new ResizeObserver(() => this.layout());
    this.ro.observe(el);
    this.overlay.addEventListener('pointerdown', (e) => this.onDown(e));
    this.overlay.addEventListener('dblclick', (e) => this.onDbl(e));
    el.addEventListener('dragover', (e) => { if (e.dataTransfer?.types.includes('Files')) { e.preventDefault(); el.classList.add('drop'); } });
    el.addEventListener('dragleave', () => el.classList.remove('drop'));
    el.addEventListener('drop', (e) => { el.classList.remove('drop'); if (e.dataTransfer?.files.length) { e.preventDefault(); ed.importFiles([...e.dataTransfer.files]); } });
    this.pending = 0;
  }
  destroy() { this.stop(); this.ro.disconnect(); for (const el of this.mediaEls()) { el.pause?.(); } }

  layout() {
    const c = this.ed.project.canvas, wrap = this.stage.parentElement;
    const W = wrap.clientWidth - 24, H = wrap.clientHeight - 24;
    if (W <= 0 || H <= 0) return;
    const s = Math.min(W / c.w, H / c.h);
    const cw = Math.max(40, Math.floor(c.w * s)), ch = Math.max(40, Math.floor(c.h * s));
    this.k = cw / c.w;
    this.stage.style.width = cw + 'px'; this.stage.style.height = ch + 'px';
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const pw = Math.min(c.w, Math.round(cw * dpr)), ph = Math.round(pw * c.h / c.w);
    if (this.canvas.width !== pw || this.canvas.height !== ph) { this.canvas.width = pw; this.canvas.height = ph; }
    this.scale = pw / c.w;
    this.draw();
  }

  // ── media sync (preview only; export reads exact frames itself) ──
  mediaEls() { return [...this.ed.media.els.values()]; }

  sync(t) {
    const p = this.ed.project, playing = this.ed.playing, rate = this.ed.rate || 1;
    const used = new Set();
    for (const track of p.tracks) {
      for (const item of track.items) {
        if (!item.media || !isActive(item, t)) continue;
        const m = findMedia(p, item.media); if (!m || m.kind === 'image') continue;
        const el = this.ed.media.element(m.id); if (!el) continue;
        used.add(el);
        const want = srcTime(item, t);
        const audible = playing && !track.muted && !item.muted && m.hasAudio && rate === 1;
        el.muted = !audible;
        el.volume = Math.max(0, Math.min(1, item.volume * (track.volume ?? 1) * fadeAt(item, t)));
        if (playing) {
          el.playbackRate = (item.speed || 1) * rate;
          if (Math.abs(el.currentTime - want) > 0.25 || el.paused && el.readyState < 2) el.currentTime = want;
          if (el.paused) el.play().catch(() => {});
        } else {
          if (!el.paused) el.pause();
          if (Math.abs(el.currentTime - want) > 0.5 / (p.canvas.fps || 30)) {
            this.pending++;
            seekExact(el, want).then(() => { this.pending--; this.draw(); });
          }
        }
      }
    }
    for (const el of this.mediaEls()) if (!used.has(el) && el.pause && !el.paused) el.pause();
  }

  source = (item) => {
    const m = findMedia(this.ed.project, item.media); if (!m) return null;
    const el = this.ed.media.element(m.id);
    if (!el) return null;
    if (el.tagName === 'IMG') return el.complete && el.naturalWidth ? el : (el.decode?.().then(() => this.draw(), () => {}), null);
    return el.readyState >= 2 ? el : (el.addEventListener('loadeddata', () => this.draw(), { once: true }), null);
  };

  draw() {
    if (this.raf) return;
    this.raf = requestAnimationFrame(() => { this.raf = 0; this.paint(); });
  }
  paint() {
    const ed = this.ed;
    if (!ed.playing) this.sync(ed.t);
    renderFrame(this.ctx, ed.project, ed.t, { scale: this.scale, preview: true, source: this.source });
    this.timeEl.textContent = `${fmtTime(ed.t)} / ${fmtTime(ed.duration())}`;
    this.playBtn.textContent = ed.playing ? '❚❚' : '▶';
    this.drawOverlay();
  }

  // ── playback clock ──
  start() { this.restartClock(); this.loop(); }
  restartClock() { this.clock0 = performance.now(); this.t0 = this.ed.t; }
  stop() { cancelAnimationFrame(this.loopRaf); this.loopRaf = 0; for (const el of this.mediaEls()) if (el.pause && !el.paused) el.pause(); }
  loop = () => {
    const ed = this.ed;
    if (!ed.playing) return;
    const T = ed.duration();
    let t = this.t0 + ((performance.now() - this.clock0) / 1000) * (ed.rate || 1);
    if (t >= T) { t = T; ed.t = t; ed.pause(); ed.timeline.renderPlayhead(); return; }
    ed.t = t;
    this.sync(t);
    renderFrame(this.ctx, ed.project, t, { scale: this.scale, preview: true, source: this.source });
    this.timeEl.textContent = `${fmtTime(t)} / ${fmtTime(T)}`;
    this.playBtn.textContent = '❚❚';
    this.drawOverlay();
    ed.timeline.renderPlayhead();
    this.loopRaf = requestAnimationFrame(this.loop);
  };

  // ── overlay: guides, selection boxes, handles ──
  drawOverlay() {
    const ov = this.overlay, k = this.k || 1, ed = this.ed, c = ed.project.canvas;
    for (const n of [...ov.children]) if (!n.classList.contains('marquee') && !n.classList.contains('snap')) n.remove();
    if (this.guides) {
      const sa = safeAreas(c);
      for (const [name, r] of Object.entries(sa)) ov.appendChild(h('div.safe.' + name, { style: box(r, k) }));
    }
    const sel = ed.selectedItems().filter(({ track, item }) => !track.hidden && isActive(item, ed.t));
    if (!sel.length || ed.playing) return;
    const single = sel.length === 1 ? sel[0] : null;
    for (const { item, track } of sel) {
      const b = boxAt(item, ed.t);
      const el = h('div.sel-box' + (track.locked ? '.locked' : ''), { style: Object.assign(box(b, k), { transform: `rotate(${item.rot || 0}deg)` }), dataset: { id: item.id } });
      if (single && !track.locked) {
        for (const d of ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']) el.appendChild(h('div.hdl.' + d, { dataset: { h: d } }));
        el.appendChild(h('div.hdl.rot', { dataset: { h: 'rot' }, title: 'Rotate (Shift: 15° steps)' }));
      }
      ov.appendChild(el);
    }
    if (sel.length > 1) {
      const bb = bounds(sel.map((s) => boxAt(s.item, ed.t)));
      ov.appendChild(h('div.group-box', { style: box(bb, k) }, h('span.count', `${sel.length} selected`)));
    }
  }

  toCanvas(e) {
    const r = this.overlay.getBoundingClientRect(), k = this.k || 1;
    return { x: (e.clientX - r.left) / k, y: (e.clientY - r.top) / k };
  }

  hitTest(p) {
    const ed = this.ed;
    const list = activeItems(ed.project, ed.t).reverse();
    for (const { track, item } of list) {
      if (track.locked || track.hidden) continue;
      const b = boxAt(item, ed.t), cx = b.x + b.w / 2, cy = b.y + b.h / 2, a = -((item.rot || 0) * Math.PI) / 180;
      const dx = p.x - cx, dy = p.y - cy;
      const lx = dx * Math.cos(a) - dy * Math.sin(a), ly = dx * Math.sin(a) + dy * Math.cos(a);
      if (Math.abs(lx) <= b.w / 2 && Math.abs(ly) <= b.h / 2) return { track, item };
    }
    return null;
  }

  onDbl(e) {
    const hit = this.hitTest(this.toCanvas(e));
    if (hit?.item.type === 'text') { this.ed.select([hit.item.id]); this.ed.inspector.focusText(); }
  }

  onDown(e) {
    if (e.button !== 0) return;
    const ed = this.ed;
    if (ed.playing) ed.pause();
    e.preventDefault();
    this.overlay.focus({ preventScroll: true });
    const p0 = this.toCanvas(e);
    const hd = e.target.dataset?.h;
    if (hd) { const id = e.target.closest('.sel-box').dataset.id; return hd === 'rot' ? this.dragRotate(e, id, p0) : this.dragResize(e, id, hd, p0); }
    const multi = e.shiftKey || e.metaKey || e.ctrlKey;
    const hit = e.altKey ? null : this.hitTest(p0);
    if (hit && multi) { ed.select([hit.item.id], 'toggle'); return; }
    if (hit && ed.sel.has(hit.item.id)) return this.dragMove(e, p0);
    if (hit && hit.track.kind !== 'video') { ed.select([hit.item.id]); return this.dragMove(e, p0); }
    // empty space or the background video: a drag draws a marquee, a click selects (the video) or clears
    this.dragMarquee(e, p0, hit, multi);
  }

  track(e, onMove, onUp) {
    const id = e.pointerId;
    const move = (ev) => { if (ev.pointerId === id) onMove(ev); };
    const up = (ev) => { if (ev.pointerId !== id) return; window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up); onUp?.(ev); this.ed.history.seal(); this.clearSnap(); };
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up); window.addEventListener('pointercancel', up);
  }

  dragMarquee(e, p0, hit, add) {
    const ed = this.ed, k = this.k;
    let mq = null, moved = false;
    this.track(e, (ev) => {
      const p = this.toCanvas(ev);
      if (!moved && Math.hypot(p.x - p0.x, p.y - p0.y) * k < 4) return;
      moved = true;
      const r = { x: Math.min(p0.x, p.x), y: Math.min(p0.y, p.y), w: Math.abs(p.x - p0.x), h: Math.abs(p.y - p0.y) };
      if (!mq) { mq = h('div.marquee'); this.overlay.appendChild(mq); }
      Object.assign(mq.style, box(r, k));
      this.mq = r;
    }, () => {
      mq?.remove();
      if (!moved) { if (hit) ed.select([hit.item.id], add ? 'toggle' : 'replace'); else if (!add) ed.select([]); return; }
      const r = this.mq;
      const ids = activeItems(ed.project, ed.t).filter(({ track, item }) => {
        if (track.locked || track.hidden) return false;
        const b = boxAt(item, ed.t);
        // the full-frame video only joins when the box swallows it whole; everything else on any overlap
        if (track.kind === 'video') return b.x >= r.x && b.y >= r.y && b.x + b.w <= r.x + r.w && b.y + b.h <= r.y + r.h;
        return b.x < r.x + r.w && b.x + b.w > r.x && b.y < r.y + r.h && b.y + b.h > r.y;
      }).map(({ item }) => item.id);
      ed.select(ids, add ? 'add' : 'replace');
    });
  }

  dragMove(e, p0) {
    const ed = this.ed;
    const hits = ed.selectedItems().filter(({ track, item }) => !track.locked && isActive(item, ed.t));
    if (!hits.length) return;
    const start = hits.map(({ item }) => ({ id: item.id, x: item.x, y: item.y, w: item.w, h: item.h }));
    const bb0 = bounds(start);
    const ids = new Set(start.map((s) => s.id));
    const c = ed.project.canvas, sa = safeAreas(c).title;
    const others = activeItems(ed.project, ed.t).filter(({ item, track }) => !ids.has(item.id) && track.kind !== 'video').map(({ item }) => boxAt(item, ed.t));
    const xs = [0, c.w / 2, c.w, sa.x, sa.x + sa.w, ...others.flatMap((b) => [b.x, b.x + b.w / 2, b.x + b.w])];
    const ys = [0, c.h / 2, c.h, sa.y, sa.y + sa.h, ...others.flatMap((b) => [b.y, b.y + b.h / 2, b.y + b.h])];
    let moved = false;
    this.track(e, (ev) => {
      const p = this.toCanvas(ev);
      let dx = p.x - p0.x, dy = p.y - p0.y;
      if (!moved && Math.hypot(dx, dy) * this.k < 3) return;
      moved = true;
      const thr = SNAP_PX / this.k;
      const sx = ev.altKey ? null : snap([bb0.x + dx, bb0.x + bb0.w / 2 + dx, bb0.x + bb0.w + dx], xs, thr);
      const sy = ev.altKey ? null : snap([bb0.y + dy, bb0.y + bb0.h / 2 + dy, bb0.y + bb0.h + dy], ys, thr);
      if (sx) dx += sx.d; if (sy) dy += sy.d;
      this.showSnap(sx?.line, sy?.line);
      ed.apply(ops.setItems(start.map((s) => ({ id: s.id, props: { x: Math.round(s.x + dx), y: Math.round(s.y + dy) } })), 'Move'));
    });
  }

  dragResize(e, id, hd, p0) {
    const ed = this.ed, it = findItem(ed.project, id)?.item; if (!it) return;
    const b0 = { x: it.x, y: it.y, w: it.w, h: it.h }, th = ((it.rot || 0) * Math.PI) / 180;
    const cos = Math.cos(th), sin = Math.sin(th);
    const c0 = { x: b0.x + b0.w / 2, y: b0.y + b0.h / 2 };
    const sx = hd.includes('e') ? 1 : hd.includes('w') ? -1 : 0, sy = hd.includes('s') ? 1 : hd.includes('n') ? -1 : 0;
    const corner = sx && sy;
    const size0 = it.style?.size;
    this.track(e, (ev) => {
      const p = this.toCanvas(ev);
      const gx = p.x - p0.x, gy = p.y - p0.y;
      const lx = gx * cos + gy * sin, ly = -gx * sin + gy * cos;   // pointer delta in the item's own frame
      let w = Math.max(12, b0.w + sx * lx), hh = Math.max(12, b0.h + sy * ly);
      if (!sx) w = b0.w; if (!sy) hh = b0.h;
      const keep = corner && !ev.shiftKey;
      if (keep) { const s = Math.max(w / b0.w, hh / b0.h); w = b0.w * s; hh = b0.h * s; }
      // keep the opposite edge/corner fixed in canvas space
      const ax = -sx * b0.w / 2, ay = -sy * b0.h / 2;              // anchor in local coords (old centre)
      const ncx = ax + sx * w / 2, ncy = ay + sy * hh / 2;           // new centre in local coords
      const cx = c0.x + ncx * cos - ncy * sin, cy = c0.y + ncx * sin + ncy * cos;
      const props = { x: Math.round(cx - w / 2), y: Math.round(cy - hh / 2), w: Math.round(w), h: Math.round(hh) };
      if (keep && it.type === 'text' && size0) props.style = Object.assign({}, it.style, { size: Math.max(8, Math.round(size0 * w / b0.w)) });
      ed.apply(ops.setItems([{ id, props }], 'Resize'));
    });
  }

  dragRotate(e, id, p0) {
    const ed = this.ed, it = findItem(ed.project, id)?.item; if (!it) return;
    const c = { x: it.x + it.w / 2, y: it.y + it.h / 2 }, r0 = it.rot || 0;
    const a0 = Math.atan2(p0.y - c.y, p0.x - c.x);
    this.track(e, (ev) => {
      const p = this.toCanvas(ev);
      let r = r0 + ((Math.atan2(p.y - c.y, p.x - c.x) - a0) * 180) / Math.PI;
      r = ((r + 540) % 360) - 180;
      if (ev.shiftKey) r = Math.round(r / 15) * 15;
      else for (const s of [-180, -90, 0, 90, 180]) if (Math.abs(r - s) < 3) r = s;
      ed.apply(ops.setItems([{ id, props: { rot: Math.round(r * 10) / 10 } }], 'Rotate'));
    });
  }

  showSnap(x, y) {
    this.clearSnap();
    const k = this.k, c = this.ed.project.canvas;
    if (x != null) this.overlay.appendChild(h('div.snap.v', { style: { left: x * k + 'px', height: c.h * k + 'px' } }));
    if (y != null) this.overlay.appendChild(h('div.snap.h', { style: { top: y * k + 'px', width: c.w * k + 'px' } }));
  }
  clearSnap() { for (const n of [...this.overlay.querySelectorAll('.snap')]) n.remove(); }
}

function box(r, k) { return { left: r.x * k + 'px', top: r.y * k + 'px', width: r.w * k + 'px', height: r.h * k + 'px' }; }
export function bounds(list) {
  const x = Math.min(...list.map((b) => b.x)), y = Math.min(...list.map((b) => b.y));
  return { x, y, w: Math.max(...list.map((b) => b.x + b.w)) - x, h: Math.max(...list.map((b) => b.y + b.h)) - y };
}
/** closest snap of any of `vals` to any of `lines` within thr: {d (offset to add), line} */
function snap(vals, lines, thr) {
  let best = null;
  for (const v of vals) for (const l of lines) { const d = l - v; if (Math.abs(d) <= thr && (!best || Math.abs(d) < Math.abs(best.d))) best = { d, line: l }; }
  return best;
}
