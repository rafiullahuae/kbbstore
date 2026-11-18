// KBB Studio — the preview monitor: draws through core/render.js renderFrame (the same path as export) and handles
// on-canvas editing: select, move, resize, rotate, marquee (also when the drag starts over the video),
// Shift/Ctrl-click, snapping guides, safe-area guides.

import { renderFrame, activeItems, boxAt, fadeAt, evalProp } from '../core/render.js';
import { findItem, findMedia, srcTime, isActive, safeAreas, itemEnd, speedAt } from '../core/project.js';
import { previewStream, usesFrameStream, blendWeight } from '../core/retime.js';
import { needsRetimedSound, retimedSoundNow } from '../core/retime-audio.js';
import { blendInto } from '../core/frames.js';
import { PreviewAudio } from './preview-audio.js';
import { ops } from '../core/history.js';
import { seekExact } from '../core/media.js';
import { canvasGrab, drawCanvasOverlays, canvasHover, canvasMenu } from '../core/modules.js';
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
    // module hooks (core/modules.js): hover cursor/box, right-click menu — e.g. a caption line's move box
    const hov = (e) => { if (e.buttons || ed.playing) return; const r = canvasHover(ed.project, ed.t, e.type === 'pointerleave' ? null : this.toCanvas(e), e); this.overlay.style.cursor = r?.cursor || ''; if (r?.changed) this.drawOverlay(); };
    this.overlay.addEventListener('pointermove', hov); this.overlay.addEventListener('pointerleave', hov);
    this.overlay.addEventListener('contextmenu', (e) => { if (canvasMenu(ed.project, ed.t, this.toCanvas(e), e, { apply: (cmd) => ed.apply(cmd), redraw: () => this.drawOverlay() })) e.preventDefault(); });
    el.addEventListener('dragover', (e) => { if (e.dataTransfer?.types.includes('Files')) { e.preventDefault(); el.classList.add('drop'); } });
    el.addEventListener('dragleave', () => el.classList.remove('drop'));
    el.addEventListener('drop', (e) => { el.classList.remove('drop'); if (e.dataTransfer?.files.length) { e.preventDefault(); ed.importFiles([...e.dataTransfer.files]); } });
    this.pending = 0;
    this.streams = new Map(); this.held = new Map(); this.blendCans = new Map(); this.awaiting = new Set();
    this.fallback = new Set(); this.lagN = new Map();
    this.audio = new PreviewAudio();
  }
  destroy() {
    this.stop(); this.ro.disconnect(); for (const el of this.mediaEls()) { el.pause?.(); }
    for (const s of this.streams.values()) if (s && s.close) s.close();
    this.streams.clear(); this.audio.close();
  }

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

  /**
   * Bring every source in step with timeline time t.
   *  · plain clips and constant speed: the <video> plays at the clip's speed and is steered SOFTLY onto the clock (its
   *    rate nudged by up to ±25 %), so it never falls into a seek-per-frame loop at 2–3×; only a real jump re-seeks.
   *  · ramps, reverse, freeze and smooth slow motion: a decoder stream (core/frames.js) reads the frames in order,
   *    ahead of the playhead in the direction of play; source() picks (and blends) the exact frames.
   *  · the sound of any retimed clip: the rendered buffer (core/retime-audio.js, the export's own) on Web Audio.
   */
  sync(t) {
    const p = this.ed.project, playing = this.ed.playing, rate = this.ed.rate || 1, store = this.ed.media;
    const used = new Set(), sounds = [];
    for (const track of p.tracks) {
      for (const item of track.items) {
        if (!item.media) continue;
        const active = isActive(item, t);
        const m = findMedia(p, item.media); if (!m || m.kind === 'image') continue;
        // the retimed sound: rendered once (prewarmed for every retimed item, so it is ready), played from Web Audio
        if (needsRetimedSound(item) && m.hasAudio && !item.muted && !track.muted) {
          const buf = retimedSoundNow(store, item, m);
          if (playing && rate === 1 && buf && t < itemEnd(item) && item.t0 - t < 0.35) sounds.push({ item, track, buf });
        }
        if (!active) continue;
        if (m.kind === 'video' && this.useStream(item, m)) {
          const fs = this.streamFor(m);
          if (fs) {
            // decode ahead for the fastest speed coming up in the next half second (a ramp that is about to speed up)
            const v = playing ? Math.max(speedAt(item, t), speedAt(item, t + 0.25), speedAt(item, t + 0.5)) * rate : 1;
            // paused on a reversed clip: keep the GOP below decoded too, so pressing play starts at once
            fs.target(srcTime(item, t), item.freeze ? 0 : item.reverse ? -1 : playing ? 1 : 0, v, !playing);
            if (this.preroll) this.prerollStreams.push([fs, srcTime(item, t)]);
            continue;
          }
          if (fs === undefined) continue;          // opening: source() holds the last picture meanwhile
        }
        const el = store.element(m.id); if (!el) continue;
        used.add(el);
        const want = srcTime(item, t);
        const rendered = needsRetimedSound(item) && rate === 1 && retimedSoundNow(store, item, m);
        const audible = playing && !track.muted && !item.muted && m.hasAudio && rate === 1 && !rendered && !item.reverse;
        // a media whose sound was replaced (media.audio, e.g. a cleaned voice) plays it from a second element
        const alt = store.audioElement?.(m);
        if (alt) used.add(alt);
        const v = Math.max(0.0625, Math.min(16, speedAt(item, t) * rate));
        for (const x of alt ? [el, alt] : [el]) {
          x.muted = !audible || (alt && x === el);
          x.volume = Math.max(0, Math.min(1, evalProp(item, 'volume', t) * (track.volume ?? 1) * fadeAt(item, t)));   // kf.volume = ducking
          if (playing) {
            x.preservesPitch = item.keepPitch !== false;
            const err = want - x.currentTime;
            if (Math.abs(err) > Math.max(0.3, 0.25 * v) || x.paused && x.readyState < 2) { x.currentTime = want; x.playbackRate = v; }
            else {
              // steer, don't seek: remove the error over ~0.4 s of timeline; a deadband of half a frame keeps the rate still
              const fd = 1 / (m.fps || 30), r = Math.abs(err) < fd * 0.5 ? v : Math.max(v * 0.75, Math.min(v * 1.25, v + err / 0.4));
              if (Math.abs(x.playbackRate - r) > v * 0.004) x.playbackRate = Math.max(0.0625, Math.min(16, r));
            }
            if (x.paused) x.play().catch(() => {});
          } else {
            if (!x.paused) x.pause();
            if (x === el && Math.abs(el.currentTime - want) > 0.5 / (p.canvas.fps || 30)) {
              this.pending++;
              seekExact(el, want).then(() => { this.pending--; this.draw(); });
            } else if (x !== el && Math.abs(x.currentTime - want) > 0.05) x.currentTime = want;
          }
        }
      }
    }
    for (const el of this.mediaEls()) if (!used.has(el) && el.pause && !el.paused) el.pause();
    if (playing) this.audio.sync(t, sounds); else this.audio.stop();
    return used;
  }

  /** the decoder stream for a media: the stream, undefined while it opens (a redraw follows), null when unavailable */
  streamFor(m) {
    const s = this.streams.get(m.id);
    if (s && s.then) return undefined;
    if (s !== undefined) return s;
    // frames about as large as the monitor shows them (a portrait canvas over a landscape clip shows its full height)
    const c = this.ed.project.canvas, k = Math.max(c.w / (m.w || c.w), c.h / (m.h || c.h)) * (this.scale || 0.5);
    const maxW = Math.min(m.w || 1920, Math.ceil((m.w || 1920) * Math.min(1, k * 1.5)));
    const p = this.ed.media.openStream(m.id, { maxW, budgetMB: 384, proxy: true }).then((fs) => { this.streams.set(m.id, fs); this.draw(); return fs; }, () => { this.streams.set(m.id, null); });
    this.streams.set(m.id, p);
    return undefined;
  }

  /**
   * Read this item from the decoder stream? Ramps, reverse, freeze and smooth slow motion always do. A plain constant
   * speed does too — every tick then shows its exact frame — unless this machine's decoder cannot keep up with it (4K at
   * 2–3× without a hardware decoder): after 8 ticks more than 2.5 frames behind, that clip goes back to the <video>
   * element for the rest of the play, which drops frames instead of falling behind the clock.
   */
  useStream(item, m) { return previewStream(item, m) && !(this.fallback.has(item.id) && !usesFrameStream(item)); }
  noteLag(item, lag) {
    if (!this.ed.playing || usesFrameStream(item)) return;
    const n = lag > 2.5 ? (this.lagN.get(item.id) || 0) + 1 : 0;
    this.lagN.set(item.id, n);
    if (n > 8) { this.fallback.add(item.id); this.lagN.delete(item.id); }
  }

  source = (item, st) => {
    const m = findMedia(this.ed.project, item.media); if (!m) return null;
    if (m.kind === 'video' && this.useStream(item, m)) {
      const fs = this.streamFor(m);
      if (fs !== null) {
        const t = st ?? srcTime(item, this.ed.t), wantB = !!blendWeight(item, this.ed.t);
        const r = fs?.pick(t);
        const settle = () => {
          if (!fs || this.ed.playing || this.awaiting.has(item.id)) return;
          this.awaiting.add(item.id); this.pending++;
          fs.frameAt(t, wantB, item.reverse && !item.freeze ? -1 : 0).catch(() => {}).finally(() => { this.awaiting.delete(item.id); this.pending--; this.draw(); });
        };
        if (r?.a) {
          let pic = r.a, at = r.ats / 1e6;
          if (wantB && r.b && r.exact) {
            let c = this.blendCans.get(item.id); if (!c) { c = document.createElement('canvas'); this.blendCans.set(item.id, c); }
            pic = blendInto(c, r.a, r.b, r.w); at = t;
          }
          pic.__srcT = at;
          if (this.ed.playing) this.noteLag(item, Math.abs(t - r.ats / 1e6) * fs.fps - 1);
          if (pic === r.a) fs.pin(item.id, pic);
          if (!r.exact || (wantB && !r.b)) settle();
          this.held.set(item.id, pic);
          return pic;
        }
        settle();
        return this.held.get(item.id) || null;    // hold the last picture while the decoder catches up
      }
    }
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
  // A short pre-roll (≤ 400 ms) holds the clock until the <video>s it plays are actually running and the decoder streams
  // hold the first frame, so picture, sound and
  // timeline start together instead of the picture trailing the clock by its start-up delay.
  start() { this.restartClock(); this.preroll = performance.now(); this.prerollStreams = []; this.loop(); }
  restartClock() { this.clock0 = performance.now(); this.t0 = this.ed.t; }
  stop() { cancelAnimationFrame(this.loopRaf); this.loopRaf = 0; this.preroll = 0; this.fallback.clear(); this.lagN.clear(); for (const el of this.mediaEls()) if (el.pause && !el.paused) el.pause(); this.audio.stop(); }
  loop = () => {
    const ed = this.ed;
    if (!ed.playing) return;
    const T = ed.duration();
    let t = this.preroll ? this.t0 : this.t0 + ((performance.now() - this.clock0) / 1000) * (ed.rate || 1);
    if (t >= T) { t = T; ed.t = t; ed.pause(); ed.timeline.renderPlayhead(); return; }
    ed.t = t;
    // the playhead on the bus while playing too (10 a second is plenty for the transcript's "now" word)
    if (Math.abs(t - (this.lastEmit ?? -1)) >= 0.1) { this.lastEmit = t; ed.bus.emit('playhead', { t, playing: true }); }
    this.lastUsed = this.sync(t);
    if (this.preroll) {
      const ready = [...this.lastUsed].every((x) => !x.paused && x.readyState >= 3) && this.prerollStreams.every(([fs, st]) => fs.pick(st)?.exact);
      if (ready || performance.now() - this.preroll > 400) { this.preroll = 0; this.restartClock(); }
      this.prerollStreams = [];
    }
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
    drawCanvasOverlays(ov, { project: ed.project, t: ed.t, k, playing: ed.playing });
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
    // a theme element's words: edit them in place on the video (themes/text-edit.js)
    else if (hit && /^theme\.(el|sting|outro|trans)$/.test(hit.item.type)) { const p = this.toCanvas(e); import('../themes/text-edit.js').then((m) => m.editOnCanvas(this, hit.item, p)); }
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
    // Alt reaches the grab too: on a caption it means "move without snapping" (elsewhere Alt still box-selects)
    const grab = canvasGrab(ed.project, ed.t, p0, e, { apply: (cmd) => ed.apply(cmd), k: this.k || 1, guides: (list) => this.showGuides(list) });
    if (grab) { this.track(e, (ev) => { const p = this.toCanvas(ev); grab.move(p.x - p0.x, p.y - p0.y, ev); }, (ev) => { if (grab.end?.(ev) === false) { const hit = this.hitTest(p0); if (hit) ed.select([hit.item.id]); } }); return; }
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
  /** a module's drag guides: [{ axis:'x'|'y', at, kind } | { rect:{x,y,w,h}, kind }] in canvas px (cleared on pointer-up) */
  showGuides(list) {
    this.clearSnap();
    const k = this.k, c = this.ed.project.canvas;
    for (const g of list || []) {
      if (g.rect) this.overlay.appendChild(h('div.snap.rect.' + (g.kind || 'box'), { style: box(g.rect, k) }));
      else if (g.axis === 'x') this.overlay.appendChild(h('div.snap.v.' + (g.kind || 'line'), { style: { left: g.at * k + 'px', height: c.h * k + 'px' } }));
      else this.overlay.appendChild(h('div.snap.h.' + (g.kind || 'line'), { style: { top: g.at * k + 'px', width: c.w * k + 'px' } }));
    }
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
