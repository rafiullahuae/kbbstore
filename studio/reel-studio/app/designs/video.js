// KBB Studio · Designs · THE ANIMATED VERSION: a design as a short MP4 (H.264, no sound) with the themes' motion -- the
// same renderer at time t (render.js draw(..., {t})), the codec choice and the muxer of the reel export (core/export.js,
// vendor mp4-muxer). The frame at t = still is the still picture the PNG/JPG export writes: preview = export.
//
//   exportDesignVideo(D, size, {width, fps, dur, onProgress, signal}) -> {blob, w, h, frames, dur, fps}
import { Muxer, ArrayBufferTarget } from '../../vendor/mp4-muxer-5.2.2.mjs';
import { pickCodecs } from '../core/export.js';
import { prepare, draw, PX } from './render.js';
import { motionOf } from './motion.js';

export async function exportDesignVideo(D, size, opts = {}) {
  if (typeof VideoEncoder !== 'function') throw new Error('This browser cannot encode video (use Chrome)');
  const [W0, H0] = PX[size], fps = opts.fps || 30, mo = motionOf(D), dur = opts.dur || mo.dur;
  const w = (Math.round(opts.width || W0) & ~1), h = (Math.round((w * H0) / W0) & ~1), scale = w / W0;
  const { video: vc } = await pickCodecs(w, h, fps, Math.round(Math.min(20e6, w * h * fps * 0.14)), false);
  if (!vc) throw new Error(`This browser has no H.264 encoder for ${w}×${h}`);
  await prepare(D);
  if (document.fonts) await document.fonts.ready;
  const muxer = new Muxer({ target: new ArrayBufferTarget(), video: { codec: 'avc', width: w, height: h, frameRate: fps }, fastStart: 'in-memory', firstTimestampBehavior: 'offset' });
  let err = null;
  const enc = new VideoEncoder({ output: (c, m) => muxer.addVideoChunk(c, m), error: (e) => { err = e; } });
  enc.configure(vc);
  const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
  const ctx = cv.getContext('2d', { alpha: false });
  const N = Math.max(1, Math.round(dur * fps));
  for (let i = 0; i < N; i++) {
    if (err) throw err;
    if (opts.signal?.aborted) throw new Error('Cancelled');
    ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.fillStyle = '#000'; ctx.fillRect(0, 0, w, h);
    draw(ctx, D, size, { scale, t: i / fps });
    const vf = new VideoFrame(cv, { timestamp: Math.round((i * 1e6) / fps), duration: Math.round(1e6 / fps) });
    enc.encode(vf, { keyFrame: i % (fps * 2) === 0 }); vf.close();
    if (enc.encodeQueueSize > 6) await new Promise((r) => { enc.addEventListener('dequeue', r, { once: true }); setTimeout(r, 250); });
    if (i % 6 === 0) opts.onProgress?.(i / N, `Frame ${i + 1} of ${N}`);
  }
  await enc.flush(); enc.close();
  if (err) throw err;
  muxer.finalize();
  return { blob: new Blob([muxer.target.buffer], { type: 'video/mp4' }), w, h, frames: N, dur, fps };
}
