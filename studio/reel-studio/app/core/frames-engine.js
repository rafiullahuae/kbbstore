// KBB Studio — core/frames-engine.js: the decoding half of core/frames.js. Runs inside frames-worker.js (off the UI
// thread, so the monitor's 60 Hz loop never waits on a decoder callback or a frame copy). No imports, no DOM.
//
// It keeps the WINDOW of frames the playhead needs decoded (ahead of it in the direction of play), feeding the decoder
// samples in decode order fetched by HTTP range, and hands each decoded frame over once, as an ImageBitmap no wider than
// asked (sink.frame). Frames that leave the window are dropped (sink.drop). Reverse decodes one GOP from its keyframe
// and keeps it, so a frame is decoded once, not once per frame shown.
//
// The decoder is asked for the hardware path first (core/media.js decoderConfig): a Mac decodes 4K on its media engine
// and hands over GPU frames, so the picture copy is cheap. Without it (software decode, a software GPU) turning a 4K
// frame into a picture costs ~35–50 ms, more than a frame lasts, and a ramp or a reverse falls behind. The preview
// (opts.proxy) then switches to a PROXY copy, measured, not guessed: once the average copy misses the frame budget, each
// decoded frame's planes are read raw (copyTo) and every 2nd or 4th pixel taken — a half or quarter resolution picture,
// never smaller than the monitor shows when it can be helped — which costs a few ms. The export never asks for it: it
// reads every frame at full resolution.

/** first index i in sorted a with a[i] > x */
export function upper(a, x) { let lo = 0, hi = a.length; while (lo < hi) { const m = (lo + hi) >> 1; if (a[m] <= x) lo = m + 1; else hi = m; } return lo; }

export class FrameEngine {
  constructor(url, idx, { maxW = 0, budgetMB = 320, proxy = false } = {}, sink) {
    this.url = url; this.idx = idx; this.sink = sink;
    this.proxyOk = !!proxy; this.proxyK = 0; this.copyMs = 0; this.copies = 0; this.bufs = [];   // see _picture()
    this.bw = maxW && maxW < idx.w ? Math.max(16, Math.round(maxW)) & ~1 : idx.w;
    this.bh = Math.max(16, Math.round(this.bw * idx.h / idx.w)) & ~1;
    this.maxFrames = Math.max(8, Math.floor((budgetMB * 1048576) / (this.bw * this.bh * 4)));
    this.have = new Set(); this.keys = [];                // ts (µs) of frames handed over, sorted
    this.win = { lo: 0, hi: 0 }; this.dir = 0; this.busy = false; this.closed = false;
    this.byTs = new Map(idx.samples.map((s, i) => [s.ts, i]));
    this.pIdx = new Map(idx.pts.map((ts, i) => [ts, i])); this.stride = 1;
    this.keyList = []; idx.samples.forEach((s, i) => { if (s.key) this.keyList.push(i); });
    this.converting = 0; this.whole = null;
    this._open();
  }
  _open() {
    this.gen = (this.gen || 0) + 1;
    this.fed = -1; this.lastOut = -Infinity; this.keyTs = Infinity; this.flushed = false;   // fed: next sample (decode order) of the running decode
    const gen = this.gen;
    try { if (this.dec && this.dec.state !== 'closed') this.dec.close(); } catch {}
    this.dec = new VideoDecoder({ output: (f) => this._out(f, gen), error: (e) => {
      if (gen !== this.gen) return;
      if (this._software()) { this.wake?.(); if (!this.busy) this._pump(); return; }
      this.err = e; this.wake?.();
    } });
    this.dec.ondequeue = () => this.wake?.();
    this.dec.configure(this.idx.config);
  }

  /** the hardware decoder failed on this stream: the software one takes over, from the keyframe again (once) */
  _software() {
    if (this.closed || this.idx.config.hardwareAcceleration !== 'prefer-hardware') return false;
    const { hardwareAcceleration, ...sw } = this.idx.config;
    this.idx = { ...this.idx, config: sw }; this.err = null; this.fed = -1;
    this._open();
    return true;
  }

  async _fetch(list) {
    if (this.whole) return list.map((s) => new Uint8Array(this.whole, s.off, s.size));
    const lo = Math.min(...list.map((s) => s.off)), hi = Math.max(...list.map((s) => s.off + s.size));
    const r = await fetch(this.url, { headers: { Range: `bytes=${lo}-${hi - 1}` } });
    const buf = await r.arrayBuffer();
    if (r.status === 200) { this.whole = buf; return list.map((s) => new Uint8Array(buf, s.off, s.size)); }
    return list.map((s) => new Uint8Array(buf, s.off - lo, s.size));
  }

  _out(f, gen) {
    const ts = f.timestamp;
    if (gen === this.gen) this.lastOut = Math.max(this.lastOut, ts);
    const fd = this.idx.frameDur;
    if (this.closed || this.have.has(ts) || ts < this.win.lo - fd * 1.5 || ts > this.win.hi + fd * 1.5 || (this.pIdx.get(ts) ?? 0) % this.stride) { f.close(); this.wake?.(); return; }
    this.converting++;
    this.have.add(ts); this.keys.splice(upper(this.keys, ts), 0, ts);
    this._picture(f)
      .then((b) => { if (this.closed || !this.have.has(ts)) { b.close(); return; } this.sink.frame(ts, b); })
      .catch(() => { this.have.delete(ts); const i = this.keys.indexOf(ts); if (i >= 0) this.keys.splice(i, 1); })
      .finally(() => { f.close(); this.converting--; this.wake?.(); });
  }

  /** the decoded frame as a picture no wider than asked: the full copy, or the proxy copy once the full one is too slow */
  async _picture(f) {
    if (this.proxyK) { try { return await this._proxy(f, this.proxyK); } catch { this.proxyK = 0; this.proxyOk = false; } }
    const a = performance.now();
    const b = await createImageBitmap(f, this.bw !== this.idx.w ? { resizeWidth: this.bw, resizeHeight: this.bh, resizeQuality: 'low' } : {});
    if (this.proxyOk) {
      // the frame budget: how many pictures a second the playhead needs from this clip (its speed × fps, at most the
      // screen's 60, at least 24 so a still preview never switches); several copies run at once, so the average is
      // compared with the time a picture may take, with a little room
      const ms = performance.now() - a, need = Math.max(24, Math.min(60, (Math.abs(this.rate || 1) * this.idx.fps) / (this.stride || 1)));
      this.copyMs = this.copies ? this.copyMs * 0.8 + ms * 0.2 : ms; this.copies++;
      const vw = f.visibleRect?.width || f.codedWidth, fmt = f.format;
      if (this.copies >= 6 && this.copyMs > 900 / need && (fmt === 'I420' || fmt === 'NV12') && vw >= 1280) {
        this.proxyK = Math.floor(vw / 4) >= this.bw ? 4 : 2;
        this.sink.proxy?.({ k: this.proxyK, copyMs: +this.copyMs.toFixed(1), need: Math.round(need) });
      }
    }
    return b;
  }

  /** a 1/k resolution picture straight from the frame's planes (every k-th pixel; I420 or NV12) */
  async _proxy(f, k) {
    const size = f.allocationSize(), buf = this.bufs.pop() || new Uint8Array(size), B = buf.byteLength >= size ? buf : new Uint8Array(size);
    try {
      const lay = await f.copyTo(B), r = f.visibleRect || { x: 0, y: 0, width: f.codedWidth, height: f.codedHeight };
      const w = Math.floor(r.width / k) & ~1, h = Math.floor(r.height / k) & ~1, cw = w >> 1, ch = h >> 1, o = new Uint8Array(w * h + 2 * cw * ch);
      const Y = lay[0];
      for (let y = 0; y < h; y++) { const so = Y.offset + (r.y + y * k) * Y.stride + r.x, d = y * w; for (let x = 0; x < w; x++) o[d + x] = B[so + x * k]; }
      const du0 = w * h, dv0 = du0 + cw * ch, cx = r.x >> 1, cy = r.y >> 1;
      if (f.format === 'NV12') {
        const UV = lay[1];
        for (let y = 0; y < ch; y++) { const s0 = UV.offset + (cy + y * k) * UV.stride + cx * 2, du = du0 + y * cw, dv = dv0 + y * cw; for (let x = 0; x < cw; x++) { o[du + x] = B[s0 + x * k * 2]; o[dv + x] = B[s0 + x * k * 2 + 1]; } }
      } else {
        const U = lay[1], V = lay[2];
        for (let y = 0; y < ch; y++) { const su = U.offset + (cy + y * k) * U.stride + cx, sv = V.offset + (cy + y * k) * V.stride + cx, du = du0 + y * cw, dv = dv0 + y * cw; for (let x = 0; x < cw; x++) { o[du + x] = B[su + x * k]; o[dv + x] = B[sv + x * k]; } }
      }
      const vf = new VideoFrame(o, { format: 'I420', codedWidth: w, codedHeight: h, timestamp: f.timestamp, colorSpace: f.colorSpace });
      try { return await createImageBitmap(vf, w > this.bw ? { resizeWidth: this.bw, resizeHeight: this.bh, resizeQuality: 'low' } : {}); } finally { vf.close(); }
    } finally { if (this.bufs.length < 4) this.bufs.push(B); }
  }

  /** where the playhead is (µs of source): the window of frames to have decoded follows it */
  target(T, dir = 0, rate = 1, all = false) {
    if (this.closed) return;
    const fd = this.idx.frameDur;
    const ahead = Math.max(6 * fd, Math.abs(rate) * 0.7e6);
    let lo, hi;
    if (dir > 0) { lo = T - fd; hi = T + ahead; }
    else if (dir < 0) {
      hi = T + fd;
      // back to the keyframe before the lookahead: one decode pass serves the whole GOP (within the memory budget)
      const s = this.idx.samples[this._keyBefore(T - ahead)];
      lo = Math.max(s ? s.ts : 0, hi - this.maxFrames * fd * 0.9);
    } else { lo = T - fd; hi = T + 2 * fd; }
    // faster than the screen can show (2× of 60 fps, 3× of 30 fps …): only every stride-th frame is turned into a picture —
    // the monitor could not show the others anyway — so the copies never fall behind the decoder
    this.stride = all || !dir ? 1 : Math.max(1, Math.round((Math.abs(rate) * this.idx.fps) / 60));
    this.rate = dir ? rate : 0;
    this.win = { lo, hi }; this.dir = dir;
    this._evict();
    this._pump();
  }

  _keyBefore(ts) {
    const S = this.idx.samples, K = this.keyList;
    let lo = 0, hi = K.length - 1, k = K[0] ?? 0;
    while (lo <= hi) { const m = (lo + hi) >> 1; if (S[K[m]].ts <= ts) { k = K[m]; lo = m + 1; } else hi = m - 1; }
    return k;
  }

  _evict() {
    const fd = this.idx.frameDur, lo = this.win.lo - fd * 2, hi = this.win.hi + fd * 2, keys = this.keys;
    if (keys.length <= this.maxFrames && (!keys.length || keys[0] >= lo && keys[keys.length - 1] <= hi)) return;
    const keep = [], drop = [];
    for (const ts of keys) { if (ts < lo || ts > hi) drop.push(ts); else keep.push(ts); }
    // over budget: drop the frames farthest from the playhead side of the window
    while (keep.length > this.maxFrames) drop.push(this.dir < 0 ? keep.shift() : keep.pop());
    for (const ts of drop) this.have.delete(ts);
    this.keys = keep;
    if (drop.length) this.sink.drop(drop);
  }

  /** the first frame in the window that is not decoded yet (presentation order), or null */
  _missing() {
    const p = this.idx.pts, { lo, hi } = this.win;
    let i = Math.max(0, upper(p, lo) - 1); i -= i % this.stride;
    for (; i < p.length && p[i] <= hi; i += this.stride) if (!this.have.has(p[i])) return p[i];
    return null;
  }

  async _pump() {
    if (this.busy || this.closed) return;
    this.busy = true;
    let again = false;
    const S = this.idx.samples, wait = (ms) => new Promise((r) => { this.wake = r; setTimeout(r, ms); });
    try {
      for (let guard = 0; guard < 1e6 && !this.closed; guard++) {
        if (this.err) throw this.err;
        const miss = this._missing();
        if (miss == null) break;
        const k = this._keyBefore(miss);
        // can the running decode still deliver it? Only if it started at or before that frame's keyframe, has not
        // output past it yet, and no later keyframe lets it skip ahead
        const run = this.fed >= 0 && !this.flushed;
        if (!run || miss <= this.lastOut || S[k].ts < this.keyTs || k > this.fed) {
          if (this.fed >= 0) this._open();
          this.fed = k; this.keyTs = S[k].ts;
        }
        if (this.fed < S.length) {
          // up to 12 samples in decode order (one range request), then let the decoder work
          const until = Math.min(S.length, this.fed + 12), list = S.slice(this.fed, until), gen = this.gen;
          const data = await this._fetch(list);
          if (gen !== this.gen || this.closed) continue;
          for (let j = 0; j < list.length; j++) { const s = list[j]; this.dec.decode(new EncodedVideoChunk({ type: s.key ? 'key' : 'delta', timestamp: s.ts, duration: s.dur, data: data[j] })); }
          this.fed = until;
          const di = this.byTs.get(miss);
          // the end of the file, or the frame held in the decoder's reorder buffer far too long: flush it out
          if (this.fed >= S.length || (di != null && this.fed > di + 24)) { const fg = this.gen; await this.dec.flush().catch(() => {}); if (fg === this.gen) this.flushed = true; }
        } else if (!this.flushed) { const fg = this.gen; await this.dec.flush().catch(() => {}); if (fg === this.gen) this.flushed = true; }
        if (this.dec.decodeQueueSize > 6 || this.converting > 6) await wait(15);
      }
    } catch (e) {
      // decode() on a hardware decoder that has just failed throws before its error callback runs: same fallback
      if (this._software()) again = true;
      else { this.err = e; this.sink.error?.(String(e.message || e)); }
    }
    finally { this.busy = false; }
    if (again) this._pump();
  }

  close() { this.closed = true; try { if (this.dec.state !== 'closed') this.dec.close(); } catch {} }
}
