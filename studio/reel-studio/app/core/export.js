// KBB Studio — core/export.js (CONTRACT, owned by R1)
//
//   const { blob, note } = await exportMp4(project, mediaStore, { width, height, fps, quality, onProgress, signal });
//
// MP4: H.264 video (WebCodecs VideoEncoder) + AAC audio (AudioEncoder; Opus in MP4 where the browser has no AAC
// encoder, e.g. Chrome on Linux — Chrome on a Mac has AAC). Frames come from renderFrame(), the SAME function the
// preview monitor calls, so preview = export. Sound: every audible item on every unmuted track mixed once in an
// OfflineAudioContext (volume, track volume, speed, fades).

import { Muxer, ArrayBufferTarget } from '../../vendor/mp4-muxer-5.2.2.mjs';
import { renderFrame, activeItems, prepareRender, evalProp } from './render.js';
import { projectDuration, findMedia, itemDur, srcTime } from './project.js';

export const QUALITY = {
  small: { label: 'Small file', bpp: 0.07 },
  standard: { label: 'Standard', bpp: 0.12 },
  high: { label: 'High', bpp: 0.2 },
};

function avcLevel(W, H, fps) {
  const mbs = Math.ceil(W / 16) * Math.ceil(H / 16), r = mbs * fps;
  return mbs <= 8192 && r <= 245760 ? '28' : mbs <= 8704 && r <= 522240 ? '2a' : mbs <= 22080 && r <= 983040 ? '33' : '34';
}

export async function pickCodecs(W, H, fps, bitrate, wantAudio) {
  const lv = avcLevel(W, H, fps);
  let video = null;
  for (const codec of ['avc1.6400' + lv, 'avc1.4d00' + lv, 'avc1.42e0' + lv]) {
    const cfg = { codec, width: W, height: H, bitrate, framerate: fps, avc: { format: 'avc' }, latencyMode: 'quality' };
    try { if ((await VideoEncoder.isConfigSupported(cfg)).supported) { video = cfg; break; } } catch {}
  }
  let audio = null;
  if (wantAudio && typeof AudioEncoder === 'function') {
    for (const [codec, mux] of [['mp4a.40.2', 'aac'], ['opus', 'opus']]) {
      const cfg = { codec, sampleRate: 48000, numberOfChannels: 2, bitrate: 160000 };
      try { if ((await AudioEncoder.isConfigSupported(cfg)).supported) { audio = { cfg, mux }; break; } } catch {}
    }
  }
  return { video, audio };
}

/** every audible item: [{item, track, media}] */
export function audibleItems(project) {
  const out = [];
  for (const track of project.tracks) {
    if (track.muted || track.volume <= 0) continue;
    for (const item of track.items) {
      if (!item.media || item.muted || item.volume <= 0) continue;
      const m = findMedia(project, item.media);
      if (m && m.kind !== 'image' && m.hasAudio) out.push({ item, track, media: m });
    }
  }
  return out;
}

const gainFade = (item, t, d, fi, fo) => {
  const lt = t - item.t0;
  let f = 1;
  if (fi > 0 && lt < fi) f *= Math.max(0, lt / fi);
  if (fo > 0 && lt > d - fo) f *= Math.max(0, (d - lt) / fo);
  return f;
};

/** mix the whole timeline to a 48 kHz stereo AudioBuffer (null when nothing is audible) */
export async function mixAudio(project, media, T) {
  const list = audibleItems(project);
  if (!list.length || T <= 0) return null;
  const SR = 48000;
  const ctx = new OfflineAudioContext(2, Math.ceil(T * SR), SR);
  let any = false;
  for (const { item, track, media: m } of list) {
    const a = await media.decodeAudio(m.id);
    if (!a) continue;
    const buf = a.buffer, sp = item.speed || 1;
    const start = Math.max(0, item.t0), skip = Math.max(0, -item.t0) * sp;
    const off = item.in + skip, len = Math.min(item.out, buf.duration) - off;
    if (len <= 0 || start >= T) continue;
    const s = ctx.createBufferSource(), g = ctx.createGain();
    s.buffer = buf; s.playbackRate.value = sp;
    s.connect(g); g.connect(ctx.destination);
    const v = item.volume * (track.volume ?? 1), d = itemDur(item);
    const fi = Math.min(item.fadeIn || 0, d), fo = Math.min(item.fadeOut || 0, d);
    if (item.kf?.volume?.length && d > 0) {
      // keyframed volume (music ducked under speech) x fades, as one gain curve at 200 points/s — the same
      // numbers the preview uses (evalProp + fadeAt), so what he hears is what is exported
      const n = Math.max(2, Math.ceil(d * 200) + 1), curve = new Float32Array(n);
      for (let i = 0; i < n; i++) {
        const t = item.t0 + (d * i) / (n - 1);
        curve[i] = Math.max(0, evalProp(item, 'volume', t)) * (track.volume ?? 1) * gainFade(item, t, d, fi, fo);
      }
      g.gain.value = curve[0];      // no other event may sit inside the curve's time range
      g.gain.setValueCurveAtTime(curve, Math.max(0, item.t0), d);
    } else {
      // fades as exact linear ramps (gain is piecewise linear between these points)
      g.gain.setValueAtTime(fi > 0 ? 0 : v, item.t0 < 0 ? 0 : item.t0);
      if (fi > 0) g.gain.linearRampToValueAtTime(v, Math.max(0, item.t0 + fi));
      if (fo > 0) { g.gain.setValueAtTime(v, Math.max(0, item.t0 + d - fo)); g.gain.linearRampToValueAtTime(0, Math.max(0, item.t0 + d)); }
    }
    s.start(start, off, len);
    any = true;
  }
  return any ? ctx.startRendering() : null;
}

/**
 * registerMixPost(id, async fn(buffer, project) -> AudioBuffer | null) — a whole-mix pass on the export's sound after
 * mixAudio() (platform loudness target, true-peak limiter). Runs in order of registration; null keeps the buffer.
 */
const mixPosts = new Map();
export function registerMixPost(id, fn) { mixPosts.set(id, fn); }
export async function postMix(abuf, project) {
  for (const [id, fn] of mixPosts) { try { abuf = (await fn(abuf, project)) || abuf; } catch (e) { console.warn('[export] mix pass', id, e); } }
  return abuf;
}

const cancelled = () => Object.assign(new Error('Export cancelled'), { cancelled: true });

export async function exportMp4(project, media, opts = {}) {
  const fps = opts.fps || project.canvas.fps || 30;
  const W = (opts.width || project.canvas.w) & ~1, H = (opts.height || project.canvas.h) & ~1;
  const q = QUALITY[opts.quality] || QUALITY.standard;
  const T = opts.duration || projectDuration(project);
  if (T <= 0) throw new Error('The timeline is empty');
  const N = Math.max(1, Math.round(T * fps));
  const bitrate = Math.round(Math.min(50e6, W * H * fps * q.bpp));
  const progress = opts.onProgress || (() => {});
  const signal = opts.signal;
  const check = () => { if (signal?.aborted) throw cancelled(); if (err) throw err; };
  let err = null;
  const t0 = performance.now();

  if (typeof VideoEncoder !== 'function') throw new Error('This browser cannot encode video (use Chrome)');
  const wantAudio = audibleItems(project).length > 0 && opts.audio !== false;
  const { video: vc, audio: ac } = await pickCodecs(W, H, fps, bitrate, wantAudio);
  if (!vc) throw new Error(`This browser has no H.264 encoder for ${W}×${H}`);
  await prepareRender(project);              // badge images, brand-kit + caption fonts: everything layers draw
  if (document.fonts) await document.fonts.ready;

  progress(0.01, wantAudio ? 'Mixing the sound…' : 'Preparing…');
  const mixed = wantAudio && ac ? await mixAudio(project, media, T) : null;
  const abuf = mixed ? await postMix(mixed, project) : null;
  check();

  const muxer = new Muxer({
    target: new ArrayBufferTarget(),
    video: { codec: 'avc', width: W, height: H, frameRate: fps },
    audio: abuf ? { codec: ac.mux, numberOfChannels: 2, sampleRate: 48000 } : undefined,
    fastStart: 'in-memory',
    firstTimestampBehavior: 'offset',
  });
  const venc = new VideoEncoder({ output: (c, m) => muxer.addVideoChunk(c, m), error: (e) => { err = e; } });
  venc.configure(vc);
  let aenc = null;
  const readers = new Map();
  try {
    if (abuf) {
      aenc = new AudioEncoder({ output: (c, m) => muxer.addAudioChunk(c, m), error: (e) => { err = e; } });
      aenc.configure(ac.cfg);
      const L = abuf.getChannelData(0), R = abuf.getChannelData(1), n0 = abuf.length;
      for (let off = 0; off < n0; off += 4800) {
        const n = Math.min(4800, n0 - off), d = new Float32Array(n * 2);
        d.set(L.subarray(off, off + n), 0); d.set(R.subarray(off, off + n), n);
        const ad = new AudioData({ format: 'f32-planar', sampleRate: 48000, numberOfFrames: n, numberOfChannels: 2, timestamp: Math.round((off / 48000) * 1e6), data: d });
        aenc.encode(ad); ad.close();
      }
    }

    const can = document.createElement('canvas'); can.width = W; can.height = H;
    const ctx = can.getContext('2d', { alpha: false });
    const scale = W / project.canvas.w;
    const frames = new Map();
    const source = (item) => frames.get(item.id) || null;
    const readerFor = async (id) => { if (!readers.has(id)) readers.set(id, await media.openReader(id)); return readers.get(id); };

    for (let i = 0; i < N; i++) {
      check();
      const t = i / fps;
      frames.clear();
      for (const { item } of activeItems(project, t)) {
        if (!item.media) continue;
        const m = findMedia(project, item.media); if (!m || m.kind === 'audio') continue;
        const r = await readerFor(m.id);
        if (r) frames.set(item.id, await r.frame(m.kind === 'image' ? 0 : srcTime(item, t)));
      }
      renderFrame(ctx, project, t, { scale, source, preview: false });
      const vf = new VideoFrame(can, { timestamp: Math.round((i * 1e6) / fps), duration: Math.round(1e6 / fps) });
      venc.encode(vf, { keyFrame: i % (fps * 2) === 0 });
      vf.close();
      if (venc.encodeQueueSize > 6) await new Promise((r) => { venc.addEventListener('dequeue', r, { once: true }); setTimeout(r, 250); });
      if (i % 3 === 0) progress(0.02 + (i / N) * 0.95, `Frame ${i + 1} of ${N}`);
    }
    check();
    progress(0.98, 'Finishing…');
    await venc.flush();
    if (aenc) await aenc.flush();
    check();
    muxer.finalize();
    const blob = new Blob([muxer.target.buffer], { type: 'video/mp4' });
    const secs = (performance.now() - t0) / 1000;
    const note = `H.264${abuf ? (ac.mux === 'aac' ? ' + AAC' : ' + Opus (this browser has no AAC encoder)') : wantAudio ? ', no sound (this browser cannot encode sound)' : ', no sound'} · ${W}×${H} · ${fps} fps · ${N} frames in ${secs.toFixed(1)} s`;
    progress(1, 'Done');
    return { blob, note, frames: N, audio: abuf ? ac.mux : null, seconds: secs };
  } finally {
    for (const r of readers.values()) r?.close();
    try { if (venc.state !== 'closed') venc.close(); } catch {}
    try { if (aenc && aenc.state !== 'closed') aenc.close(); } catch {}
  }
}
