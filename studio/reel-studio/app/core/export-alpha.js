// KBB Studio — core/export-alpha.js: exports that keep the picture's transparency (a cut-out presenter or product to
// reuse in the Ad Studio or anywhere else).
//
//   await exportAlpha(project, media, { format: 'webm' | 'png', only: Set(itemIds) | null, onProgress, signal })
//        -> { blob, note, frames, ext, mime }
//   await framePng(project, media, t, { only }) -> Blob (one transparent PNG)
//
// Frames come from renderFrame() with { transparent: true } — the same function the preview and the MP4 export call —
// over the same exact source frames (export.js openFrames), so what is opaque here is exactly what the MP4 shows.
// `only`: export just these items (the cut-out clip), the timeline cut to their span; null = the whole reel.
//
// WebM with alpha: Chrome's WebCodecs cannot encode alpha itself ("Alpha encoding is not currently supported", Chrome
// 153), so the alpha is carried the way libvpx/Chrome/ffmpeg store it in WebM anyway: TWO VP9 streams, the colour and
// the alpha plane (as the luma of a second stream), the second written as BlockAdditional (BlockAddID 1) beside each
// colour frame, the track flagged AlphaMode 1. Chrome, Firefox, ffmpeg (libvpx), Premiere/After Effects (with WebM
// support) and the studio's own media import read it as a transparent video. Sound: Opus. The muxer below is ~120
// lines of plain EBML (no dependency). ProRes 4444 would need an encoder the browser does not have — not offered.
// PNG sequence: one RGBA PNG per frame in a .zip (stored, no recompression), for any tool.

import { renderFrame, prepareRender } from './render.js';
import { projectDuration, clone } from './project.js';
import { openFrames, mixAudio, postMix, audibleItems } from './export.js';

const cancelled = () => Object.assign(new Error('Export cancelled'), { cancelled: true });

/** the project cut down to `only` (its items, times shifted so the first starts at 0), or the project itself */
export function cutTo(project, only) {
  if (!only || !only.size) return project;
  const p = clone(project);
  let t0 = Infinity;
  for (const tr of p.tracks) { tr.items = tr.items.filter((i) => only.has(i.id)); for (const i of tr.items) t0 = Math.min(t0, i.t0); }
  if (!isFinite(t0)) throw new Error('Nothing to export');
  for (const tr of p.tracks) for (const i of tr.items) i.t0 -= t0;
  p.captions = Object.assign({}, p.captions, { words: [], lines: [] });
  return p;
}

function canvasFor(W, H) {
  const c = document.createElement('canvas'); c.width = W; c.height = H;
  return { c, x: c.getContext('2d', { alpha: true, willReadFrequently: true }) };
}

/** one transparent PNG of the frame at timeline time t */
export async function framePng(project, media, t, { only = null, width, height } = {}) {
  const p0 = project;
  const W = (width || p0.canvas.w) & ~1, H = (height || p0.canvas.h) & ~1;
  await prepareRender(p0);
  if (document.fonts) await document.fonts.ready;
  const skip = only?.size ? new Set(p0.tracks.flatMap((tr) => tr.items).filter((i) => !only.has(i.id)).map((i) => i.id)) : null;
  const f = await openFrames(p0, media, W / p0.canvas.w);
  try {
    await f.load(t);
    const { c, x } = canvasFor(W, H);
    renderFrame(x, p0, t, { scale: W / p0.canvas.w, source: f.source, preview: false, transparent: true, skip });
    return await new Promise((r) => c.toBlob(r, 'image/png'));
  } finally { f.close(); }
}

export async function exportAlpha(project0, media, opts = {}) {
  const format = opts.format === 'png' ? 'png' : 'webm';
  const project = cutTo(project0, opts.only);
  const fps = opts.fps || project.canvas.fps || 30;
  const W = (opts.width || project.canvas.w) & ~1, H = (opts.height || project.canvas.h) & ~1;
  const T = opts.duration || projectDuration(project);
  if (T <= 0) throw new Error('The timeline is empty');
  const N = Math.max(1, Math.round(T * fps));
  const progress = opts.onProgress || (() => {}), signal = opts.signal;
  let err = null;
  const check = () => { if (signal?.aborted) throw cancelled(); if (err) throw err; };
  const t0 = performance.now();
  await prepareRender(project);
  if (document.fonts) await document.fonts.ready;
  const scale = W / project.canvas.w;
  const { c, x } = canvasFor(W, H);
  const frames = await openFrames(project, media, scale);
  try {
    if (format === 'png') {
      const zip = new ZipWriter(), base = (opts.name || 'frame').replace(/[^\w-]+/g, '-');
      for (let i = 0; i < N; i++) {
        check();
        const t = i / fps;
        await frames.load(t);
        renderFrame(x, project, t, { scale, source: frames.source, preview: false, transparent: true });
        const png = await new Promise((r) => c.toBlob(r, 'image/png'));
        zip.add(`${base}-${String(i + 1).padStart(5, '0')}.png`, new Uint8Array(await png.arrayBuffer()));
        if (i % 3 === 0) progress(0.02 + (i / N) * 0.95, `Frame ${i + 1} of ${N}`);
      }
      const blob = zip.finish();
      const secs = (performance.now() - t0) / 1000;
      progress(1, 'Done');
      return { blob, ext: 'zip', mime: 'application/zip', frames: N, seconds: secs, note: `PNG sequence with alpha · ${W}×${H} · ${fps} fps · ${N} frames in ${secs.toFixed(1)} s` };
    }

    // ── WebM: VP9 colour + VP9 alpha, Opus sound ──
    if (typeof VideoEncoder !== 'function') throw new Error('This browser cannot encode video (use Chrome)');
    const bitrate = Math.round(Math.min(40e6, W * H * fps * 0.12));
    let vcfg = null;
    for (const codec of ['vp09.00.40.08', 'vp09.00.31.08', 'vp09.00.10.08']) {
      const cfg = { codec, width: W, height: H, bitrate, framerate: fps, latencyMode: 'quality' };
      try { if ((await VideoEncoder.isConfigSupported(cfg)).supported) { vcfg = cfg; break; } } catch {}
    }
    if (!vcfg) throw new Error(`This browser has no VP9 encoder for ${W}×${H}: use “PNG sequence” instead`);
    const acfg = { codec: 'opus', sampleRate: 48000, numberOfChannels: 2, bitrate: 160000 };
    let abuf = null;
    if (opts.audio !== false && audibleItems(project).length && typeof AudioEncoder === 'function') {
      try { if ((await AudioEncoder.isConfigSupported(acfg)).supported) { const m = await mixAudio(project, media, T); abuf = m ? await postMix(m, project) : null; } } catch (e) { console.warn('[export-alpha] sound', e); }
    }
    check();
    const colour = [], alpha = [], audio = [];
    let opusHead = null;
    const grab = (list) => (chunk) => { const b = new Uint8Array(chunk.byteLength); chunk.copyTo(b); list.push({ ts: chunk.timestamp, key: chunk.type === 'key', data: b }); };
    const vA = new VideoEncoder({ output: grab(colour), error: (e) => { err = e; } });
    const vB = new VideoEncoder({ output: grab(alpha), error: (e) => { err = e; } });
    vA.configure(vcfg); vB.configure(Object.assign({}, vcfg, { bitrate: Math.round(bitrate * 0.35) }));
    let aenc = null;
    try {
      if (abuf) {
        aenc = new AudioEncoder({ output: (ch, m) => { const ds = m?.decoderConfig?.description; if (!opusHead && ds) opusHead = new Uint8Array(ArrayBuffer.isView(ds) ? ds.buffer.slice(ds.byteOffset, ds.byteOffset + ds.byteLength) : ds.slice(0)); grab(audio)(ch); }, error: (e) => { err = e; } });
        aenc.configure(acfg);
        const L = abuf.getChannelData(0), R = abuf.getChannelData(1), n0 = abuf.length;
        for (let off = 0; off < n0; off += 4800) {
          const n = Math.min(4800, n0 - off), d = new Float32Array(n * 2);
          d.set(L.subarray(off, off + n), 0); d.set(R.subarray(off, off + n), n);
          const ad = new AudioData({ format: 'f32-planar', sampleRate: 48000, numberOfFrames: n, numberOfChannels: 2, timestamp: Math.round((off / 48000) * 1e6), data: d });
          aenc.encode(ad); ad.close();
        }
      }
      const Y = new Uint8Array(W * H + (W * H) / 2); Y.fill(128, W * H);    // the alpha frame: I420, luma = alpha
      for (let i = 0; i < N; i++) {
        check();
        const t = i / fps, ts = Math.round((i * 1e6) / fps), dur = Math.round(1e6 / fps), key = i % (fps * 2) === 0;
        await frames.load(t);
        renderFrame(x, project, t, { scale, source: frames.source, preview: false, transparent: true });
        const px = x.getImageData(0, 0, W, H).data;               // straight (not premultiplied) RGBA
        for (let j = 0, k = 3; j < W * H; j++, k += 4) Y[j] = px[k];
        const fa = new VideoFrame(px, { format: 'RGBX', codedWidth: W, codedHeight: H, timestamp: ts, duration: dur });
        const fb = new VideoFrame(Y, { format: 'I420', codedWidth: W, codedHeight: H, timestamp: ts, duration: dur });
        vA.encode(fa, { keyFrame: key }); vB.encode(fb, { keyFrame: key }); fa.close(); fb.close();
        if (vA.encodeQueueSize > 4) await new Promise((r) => { vA.addEventListener('dequeue', r, { once: true }); setTimeout(r, 250); });
        if (i % 3 === 0) progress(0.02 + (i / N) * 0.95, `Frame ${i + 1} of ${N}`);
      }
      check();
      progress(0.98, 'Finishing…');
      await vA.flush(); await vB.flush(); if (aenc) await aenc.flush();
      check();
    } finally {
      for (const e of [vA, vB, aenc]) try { if (e && e.state !== 'closed') e.close(); } catch {}
    }
    if (colour.length !== alpha.length) throw new Error('The alpha stream lost frames');
    const blob = webm({ W, H, durationMs: T * 1000, colour, alpha, audio, opusHead });
    const secs = (performance.now() - t0) / 1000;
    progress(1, 'Done');
    return { blob, ext: 'webm', mime: 'video/webm', frames: N, seconds: secs, audio: audio.length ? 'opus' : null,
      note: `WebM VP9 with alpha${audio.length ? ' + Opus' : ', no sound'} · ${W}×${H} · ${fps} fps · ${N} frames in ${secs.toFixed(1)} s` };
  } finally { frames.close(); }
}

// ── a minimal WebM (Matroska) writer: EBML, one VP9 track with alpha BlockAdditions, one Opus track ──
const te = new TextEncoder();
function idBytes(id) { const out = []; for (let v = id; v > 0; v = Math.floor(v / 256)) out.unshift(v & 255); return out; }
function sizeBytes(n) { const b = [0x01]; for (let i = 6; i >= 0; i--) b.push(Math.floor(n / 2 ** (8 * i)) & 255); return b; }   // 8-byte vint
const cat = (parts) => { let n = 0; for (const p of parts) n += p.length; const o = new Uint8Array(n); let k = 0; for (const p of parts) { o.set(p, k); k += p.length; } return o; };
function el(id, body) { const b = body instanceof Uint8Array ? body : cat(body); return cat([new Uint8Array(idBytes(id)), new Uint8Array(sizeBytes(b.length)), b]); }
function uint(id, v) { const b = []; let x = v; do { b.unshift(x & 255); x = Math.floor(x / 256); } while (x > 0); return el(id, new Uint8Array(b)); }
function float(id, v) { const b = new Uint8Array(8); new DataView(b.buffer).setFloat64(0, v); return el(id, b); }
const str = (id, s) => el(id, te.encode(s));
function block(track, rel, flags, data) { const h = new Uint8Array(4); h[0] = 0x80 | track; new DataView(h.buffer).setInt16(1, rel); h[3] = flags; return cat([h, data]); }
function opusHeadFallback() {
  const b = new Uint8Array(19); b.set(te.encode('OpusHead')); const d = new DataView(b.buffer);
  b[8] = 1; b[9] = 2; d.setUint16(10, 312, true); d.setUint32(12, 48000, true); d.setUint16(16, 0, true); b[18] = 0; return b;
}
export function webm({ W, H, durationMs, colour, alpha, audio = [], opusHead = null }) {
  const head = el(0x1A45DFA3, [uint(0x4286, 1), uint(0x42F7, 1), uint(0x42F2, 4), uint(0x42F3, 8), str(0x4282, 'webm'), uint(0x4287, 4), uint(0x4285, 2)]);
  const info = el(0x1549A966, [uint(0x2AD7B1, 1000000), str(0x4D80, 'KBB Studio'), str(0x5741, 'KBB Studio'), float(0x4489, durationMs)]);
  const tracks = [el(0xAE, [uint(0xD7, 1), uint(0x73C5, 1), uint(0x83, 1), uint(0x9C, 0), str(0x86, 'V_VP9'), el(0xE0, [uint(0xB0, W), uint(0xBA, H), uint(0x53C0, 1)])])];
  if (audio.length) tracks.push(el(0xAE, [uint(0xD7, 2), uint(0x73C5, 2), uint(0x83, 2), uint(0x9C, 0), str(0x86, 'A_OPUS'), el(0x63A2, opusHead && opusHead.length >= 19 ? opusHead : opusHeadFallback()),
    uint(0x56AA, 6500000), uint(0x56BB, 80000000), el(0xE1, [float(0xB5, 48000), uint(0x9F, 2)])]));
  // every block in time order; a new cluster at each colour key frame (≤ 2 s apart, well inside the int16 ms range)
  const ev = colour.map((c, i) => ({ v: true, ms: Math.round(c.ts / 1000), c, a: alpha[i] }));
  for (const a of audio) ev.push({ v: false, ms: Math.round(a.ts / 1000), a });
  ev.sort((p, q) => p.ms - q.ms || (p.v ? -1 : 1));
  const clusters = [], cues = [];
  let cur = null, base = 0;
  const flush = () => { if (cur) clusters.push(el(0x1F43B675, [uint(0xE7, base), ...cur])); cur = null; };
  for (const e of ev) {
    if (!cur || (e.v && e.c.key) || e.ms - base > 30000) { flush(); cur = []; base = e.ms; if (e.v && e.c.key) cues.push({ ms: base, idx: clusters.length }); }
    if (e.v) {
      const parts = [el(0xA1, block(1, e.ms - base, 0, e.c.data))];
      if (e.a?.data?.length) parts.push(el(0x75A1, [el(0xA6, [uint(0xEE, 1), el(0xA5, e.a.data)])]));
      if (!e.c.key) parts.push(el(0xFB, new Uint8Array([0xFF])));       // ReferenceBlock -1: not a key frame
      cur.push(el(0xA0, parts));
    } else cur.push(el(0xA3, block(2, e.ms - base, 0x80, e.a.data)));
  }
  flush();
  // cue positions: relative to the segment body (info, tracks, clusters, cues follow in that order)
  const tracksEl = el(0x1654AE6B, tracks);
  let off = info.length + tracksEl.length; const at = [];
  for (const c of clusters) { at.push(off); off += c.length; }
  const cuesEl = el(0x1C53BB6B, cues.map((c) => el(0xBB, [uint(0xB3, c.ms), el(0xB7, [uint(0xF7, 1), uint(0xF1, at[c.idx])])])));
  const seg = cat([info, tracksEl, ...clusters, cuesEl]);
  return new Blob([head, cat([new Uint8Array([0x18, 0x53, 0x80, 0x67]), new Uint8Array(sizeBytes(seg.length))]), seg], { type: 'video/webm' });
}

// ── a minimal zip writer (stored entries: PNGs are already compressed) ──
const CRC = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
function crc32(b) { let c = 0xFFFFFFFF; for (let i = 0; i < b.length; i++) c = CRC[(c ^ b[i]) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }
export class ZipWriter {
  constructor() { this.parts = []; this.dir = []; this.off = 0; }
  add(name, data) {
    const nm = te.encode(name), crc = crc32(data), h = new Uint8Array(30), d = new DataView(h.buffer);
    d.setUint32(0, 0x04034b50, true); d.setUint16(4, 20, true); d.setUint16(6, 0x0800, true); d.setUint16(8, 0, true);
    d.setUint16(10, 0, true); d.setUint16(12, 0x21, true); d.setUint32(14, crc, true); d.setUint32(18, data.length, true); d.setUint32(22, data.length, true);
    d.setUint16(26, nm.length, true); d.setUint16(28, 0, true);
    this.parts.push(h, nm, data);
    this.dir.push({ nm, crc, size: data.length, off: this.off });
    this.off += 30 + nm.length + data.length;
  }
  finish() {
    const cd = [];
    for (const e of this.dir) {
      const h = new Uint8Array(46), d = new DataView(h.buffer);
      d.setUint32(0, 0x02014b50, true); d.setUint16(4, 20, true); d.setUint16(6, 20, true); d.setUint16(8, 0x0800, true); d.setUint16(10, 0, true);
      d.setUint16(12, 0, true); d.setUint16(14, 0x21, true); d.setUint32(16, e.crc, true); d.setUint32(20, e.size, true); d.setUint32(24, e.size, true);
      d.setUint16(28, e.nm.length, true); d.setUint32(42, e.off, true);
      cd.push(h, e.nm);
    }
    const cdLen = cd.reduce((s, p) => s + p.length, 0), end = new Uint8Array(22), d = new DataView(end.buffer);
    d.setUint32(0, 0x06054b50, true); d.setUint16(8, this.dir.length, true); d.setUint16(10, this.dir.length, true); d.setUint32(12, cdLen, true); d.setUint32(16, this.off, true);
    return new Blob([...this.parts, ...cd, end], { type: 'application/zip' });
  }
}
