// KBB Studio — core/frames.js (CONTRACT, owned by R1): exact source frames, read IN ORDER by a WebCodecs decoder, for
// everything a <video> element cannot do smoothly: speed ramps, slow motion with frame blending, reverse, freeze
// frames and high-frame-rate footage (every real frame of a 120 fps clip at 0.25×).
//
//   const fs = await FrameStream.open(url, { maxW, budgetMB, proxy })   proxy: the preview may drop to a 1/2 or 1/4
//                                resolution copy when full copies miss the frame budget (frames-engine.js); never the export
//   fs.target(srcT, dir, rate)   where the playhead is (dir +1 forward, -1 reverse, 0 still) — decoding runs ahead of it
//                                in that direction in the background (never one seek per frame)
//   fs.pick(srcT)                -> { a, b, w, exact } decoded frames around srcT, now (never waits): a = the frame
//                                   showing at srcT, b = the next one, w = how far srcT is from a to b (for blending)
//   await fs.frameAt(srcT, needB) -> the same, once decoded (export; a paused preview)
//   fs.close()
//
// The decoding runs in a worker (core/frames-worker.js → frames-engine.js): the monitor's loop only picks from frames
// that are already here, so a decoder callback or a frame copy never lands inside a 16 ms frame.
//
// The file is opened with HTTP range requests (the moov box, then only the samples it decodes), so a long 4K clip is not
// read into memory. Frames are kept as ImageBitmaps no wider than maxW (the preview asks for its own size) and the
// decoder's own frames are closed at once, so the decoder never stalls for lack of buffers. Reverse decodes a GOP at a
// time from its keyframe and keeps it, so each frame is decoded once, not once per displayed frame.

import { loadMp4box, describeTrack, decoderConfig, trackRotation } from './media.js';

const CHUNK = 1 << 20;

/** demux an MP4/MOV's video track: { config, samples:[{ts, dur, key, off, size}] (decode order), pts:[ts sorted], fps, frameDur, w, h } */
export async function loadIndex(url) {
  const MP4Box = await loadMp4box();
  const file = MP4Box.createFile();
  let info = null, err = null, whole = null, total = Infinity;
  file.onReady = (i) => { info = i; };
  file.onError = (e) => { err = new Error(String(e)); };
  for (let pos = 0, guard = 0; !info && !err && pos < total && guard < 400; guard++) {
    const r = await fetch(url, { headers: { Range: `bytes=${pos}-${pos + CHUNK - 1}` } });
    if (r.status === 200) { whole = await r.arrayBuffer(); whole.fileStart = 0; file.appendBuffer(whole); file.flush(); break; }
    if (r.status !== 206) throw new Error('cannot read ' + url + ' (' + r.status + ')');
    const m = /\/(\d+)\s*$/.exec(r.headers.get('Content-Range') || ''); if (m) total = +m[1];
    const buf = await r.arrayBuffer(); if (!buf.byteLength) break;
    buf.fileStart = pos;
    const next = file.appendBuffer(buf);
    pos = next > pos ? next : pos + buf.byteLength;
    if (pos >= total) file.flush();
  }
  if (err) throw err;
  if (!info || !info.videoTracks.length) throw new Error('no video track');
  const track = info.videoTracks[0], trak = file.getTrackById(track.id);
  const config = await decoderConfig({ codec: track.codec.startsWith('vp08') ? 'vp8' : track.codec, codedWidth: track.video.width, codedHeight: track.video.height, description: describeTrack(file, track.id) });
  const raw = trak.samples || [];
  if (!raw.length) throw new Error('no samples');
  const base = Math.min(...raw.map((s) => s.cts));
  const samples = raw.map((s) => ({ ts: Math.round(((s.cts - base) * 1e6) / s.timescale), dur: Math.max(1, Math.round((s.duration * 1e6) / s.timescale)), key: !!s.is_sync, off: s.offset, size: s.size }));
  const pts = samples.map((s) => s.ts).sort((a, b) => a - b);
  const span = pts.length > 1 ? (pts[pts.length - 1] - pts[0]) / (pts.length - 1) : 33333;
  return { config, samples, pts, fps: 1e6 / span, frameDur: span, w: track.video.width, h: track.video.height, rot: trackRotation(track) };
}

const indexCache = new Map();
export const openIndex = (url) => { let p = indexCache.get(url); if (!p) { p = loadIndex(url); indexCache.set(url, p); p.catch(() => indexCache.delete(url)); } return p; };

/** first index i in sorted a with a[i] > x */
function upper(a, x) { let lo = 0, hi = a.length; while (lo < hi) { const m = (lo + hi) >> 1; if (a[m] <= x) lo = m + 1; else hi = m; } return lo; }

export class FrameStream {
  static async open(url, opts = {}) { const idx = await openIndex(url); return new FrameStream(url, idx, opts); }
  constructor(url, idx, opts = {}) {
    this.idx = idx;
    this.cache = new Map(); this.keys = [];           // ts (µs) -> ImageBitmap, keys sorted
    this.pins = new Map(); this.dead = new Set();     // pictures the monitor still holds on screen are closed only when replaced
    this.closed = false; this.err = null; this.last = null;
    this.w = new Worker(new URL('./frames-worker.js', import.meta.url), { type: 'module' });
    this.w.onmessage = (e) => this._msg(e.data);
    this.w.onerror = (e) => { e.preventDefault?.(); this.err = new Error(e.message || 'frame worker failed'); this.wake?.(); };
    const { fetch: _f, ...plain } = idx;
    this.w.postMessage({ op: 'init', url, idx: plain, opts });
  }
  _msg(d) {
    if (this.closed) { if (d.bmp) d.bmp.close(); return; }
    if (d.op === 'frame') {
      const old = this.cache.get(d.ts); if (old) this._close(old);
      this.cache.set(d.ts, d.bmp); if (!old) this.keys.splice(upper(this.keys, d.ts), 0, d.ts);
      this.onFrame?.();
    } else if (d.op === 'drop') {
      const gone = new Set(d.list);
      for (const ts of d.list) { const b = this.cache.get(ts); if (b) this._close(b); this.cache.delete(ts); }
      this.keys = this.keys.filter((ts) => !gone.has(ts));
    } else if (d.op === 'error') { this.err = new Error(d.message); console.warn('[frames]', d.message); }
    else if (d.op === 'proxy') this.proxy = { k: d.k, copyMs: d.copyMs, need: d.need };   // the preview went to its 1/k proxy copy
    this.wake?.();
  }
  _close(b) { let pinned = false; for (const p of this.pins.values()) if (p === b) pinned = true; if (pinned) this.dead.add(b); else b.close(); }
  /** keep picture b alive for `key` (the monitor holds it on screen while the decoder catches up) */
  pin(key, b) { const old = this.pins.get(key); if (old === b) return; this.pins.set(key, b); if (old && this.dead.has(old) && ![...this.pins.values()].includes(old)) { this.dead.delete(old); old.close(); } }

  get fps() { return this.idx.fps; }
  get frameDur() { return this.idx.frameDur / 1e6; }
  /** presentation timestamp (µs) of the frame showing at source time t */
  frameTs(t) { const p = this.idx.pts, i = upper(p, Math.round(t * 1e6) + 500) - 1; return p[Math.max(0, i)]; }
  nextTs(ts) { const p = this.idx.pts, i = upper(p, ts); return i < p.length ? p[i] : null; }

  /** where the playhead is: decoding follows it in the worker (dir +1 forward, -1 reverse, 0 still) */
  target(t, dir = 0, rate = 1, all = false) {
    if (this.closed) return;
    const T = Math.round(t * 1e6), l = this.last, q = this.idx.frameDur / 4;
    if (l && l.dir === dir && l.all === all && Math.abs(l.T - T) < q && Math.abs(l.rate - rate) < 0.05) return;   // nothing new to say
    this.last = { T, dir, rate, all };
    this.w.postMessage({ op: 'target', T, dir, rate, all });
  }

  /** decoded frames around t, now: { a, b, w, ats, exact } (a/b ImageBitmaps; b null when not decoded or at the end) */
  pick(t) {
    if (!this.keys.length) return null;
    const want = this.frameTs(t), T = Math.round(t * 1e6);
    let a = this.cache.get(want), exact = !!a, ats = want;
    if (!a) {   // nearest decoded frame (holds the picture while the decoder catches up)
      const i = upper(this.keys, T) - 1;
      ats = this.keys[Math.max(0, i)]; a = this.cache.get(ats);
    }
    const nts = this.nextTs(ats), b = nts != null ? this.cache.get(nts) || null : null;
    const w = b ? Math.min(1, Math.max(0, (T - ats) / (nts - ats))) : 0;
    return { a, b, w, ats, exact };
  }

  /** the frames at t once decoded (export; a still preview). dir/rate: the export says where it goes next, so a reverse
   *  clip decodes each GOP once instead of once per frame */
  async frameAt(t, needB = false, dir = 0, rate = 1) {
    this.target(t, dir, rate, true);
    for (let i = 0; i < 6000 && !this.closed; i++) {
      if (this.err) throw this.err;
      const r = this.pick(t);
      if (r && r.exact && (!needB || r.b || this.nextTs(r.ats) == null)) return r;
      await new Promise((res) => { this.wake = res; setTimeout(res, 20); });
    }
    return this.pick(t);
  }

  close() {
    if (this.closed) return;
    this.closed = true;
    try { this.w.postMessage({ op: 'close' }); } catch {}
    setTimeout(() => this.w.terminate(), 200);
    for (const b of this.cache.values()) b.close();
    for (const b of this.dead) b.close();
    this.cache.clear(); this.keys = []; this.pins.clear(); this.dead.clear();
  }
}

/** a cross-fade of two frames into `canvas` (blend slow motion): a at 1 − w, b at w */
export function blendInto(canvas, a, b, w) {
  const W = a.width, H = a.height;
  if (canvas.width !== W || canvas.height !== H) { canvas.width = W; canvas.height = H; }
  const c = canvas.getContext('2d');
  c.globalAlpha = 1; c.globalCompositeOperation = 'copy'; c.drawImage(a, 0, 0, W, H);
  if (b && w > 0.002) { c.globalCompositeOperation = 'source-over'; c.globalAlpha = w; c.drawImage(b, 0, 0, W, H); c.globalAlpha = 1; }
  return canvas;
}
