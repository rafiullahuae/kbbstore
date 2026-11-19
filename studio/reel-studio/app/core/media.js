// KBB Studio — core/media.js (CONTRACT, owned by R1)
//
//   const media = new MediaStore({ getProject, engine });
//   await media.importFile(file, dir)          -> Media entry (copied into <dir>/media/ through the engine)
//   media.url(m) / media.element(mediaId)      -> URL / a cached <video>/<img>/<audio> for live preview
//   await media.frameAt(mediaId, t, w)         -> ImageBitmap of the frame at source time t, w px wide
//   await media.thumbs(mediaId, {count, h})    -> [{t, bitmap}] (cached; the timeline's scrub proxies)
//   media.audio = {src}                         optional: the media's sound REPLACED by this file (same timeline as the
//                                                 source; e.g. a cleaned voice) — decodeAudio, the mixer and the preview
//                                                 monitor all use it instead of the sound inside the source file
//   await media.decodeAudio(mediaId)           -> { mono16k: Float32Array, pcm48k: {sampleRate, length, channels:[L,R]},
//                                                   buffer: AudioBuffer@48k } (null when the media has no sound)
//   await media.peaks(mediaId, perSecond=50)   -> Float32Array of 0..1 peaks (computed in a worker)
//   await media.openReader(mediaId)            -> { frame(srcT) -> drawable, close() } exact sequential frames for
//                                                 export: WebCodecs VideoDecoder over an mp4box demux (MP4/MOV, H.264,
//                                                 HEVC where the browser decodes it), exact element seeks otherwise.

import { findMedia, uid } from './project.js';

const VENDOR = new URL('../../vendor/', import.meta.url).href;
let mp4boxP = null;
export function loadMp4box() {
  if (globalThis.MP4Box?.createFile) return Promise.resolve(globalThis.MP4Box);
  return (mp4boxP ||= new Promise((res, rej) => {
    const s = document.createElement('script');
    s.src = VENDOR + 'mp4box-0.5.2.all.min.js';
    s.onload = () => res(globalThis.MP4Box); s.onerror = () => rej(new Error('mp4box failed to load'));
    document.head.appendChild(s);
  }));
}

export function kindOf(file) {
  const t = file.type || '', n = (file.name || '').toLowerCase();
  if (t.startsWith('video/') || /\.(mp4|mov|m4v|webm|mkv)$/.test(n)) return 'video';
  if (t.startsWith('audio/') || /\.(mp3|m4a|aac|wav|ogg|opus|flac)$/.test(n)) return 'audio';
  if (t.startsWith('image/') || /\.(png|jpe?g|webp|gif|svg|heic)$/.test(n)) return 'image';
  return null;
}

const safeName = (n) => String(n || 'file').replace(/[^\w.\- ]+/g, '_').replace(/\s+/g, '-').slice(-80);
const once = (el, ev) => new Promise((r) => el.addEventListener(ev, r, { once: true }));

export class MediaStore {
  constructor({ getProject, engine }) {
    this.getProject = getProject;
    this.engine = engine;
    this.els = new Map();       // preview elements per media id
    this.grab = new Map();      // hidden elements for thumbnails/frameAt
    this.thumbCache = new Map();
    this.audioCache = new Map();
    this.peakCache = new Map();
    this.queue = Promise.resolve();
  }

  media(id) { return findMedia(this.getProject(), id); }
  url(m) {
    if (!m) return '';
    if (/^(blob:|data:|https?:)/.test(m.src)) return m.src;
    return '/' + m.src.split('/').map(encodeURIComponent).join('/');
  }

  /** copy a dropped File into <dir>/media/ and probe it */
  async importFile(file, dir) {
    const kind = kindOf(file);
    if (!kind) throw new Error(`${file.name}: not a video, audio or picture file`);
    const id = uid('m');
    let src;
    if (this.engine?.available && dir) {
      src = `${dir}/media/${id}-${safeName(file.name)}`;
      await this.engine.writeFile(src, file);
    } else {
      src = URL.createObjectURL(file);
    }
    const m = { id, kind, name: file.name, src, dur: 0, w: 0, h: 0, fps: 30, hasAudio: kind !== 'image', size: file.size };
    Object.assign(m, await this.probe(m));
    return m;
  }

  /** reference a file that already sits in the studio folder (library music, brand logos) */
  async importPath(path, name) {
    const kind = kindOf({ name: path });
    const m = { id: uid('m'), kind, name: name || path.split('/').pop(), src: path, dur: 0, w: 0, h: 0, fps: 30, hasAudio: kind !== 'image', size: 0 };
    Object.assign(m, await this.probe(m));
    return m;
  }

  async probe(m) {
    const url = this.url(m);
    if (m.kind === 'image') {
      const img = new Image(); img.src = url; await img.decode();
      return { w: img.naturalWidth, h: img.naturalHeight, dur: 5, hasAudio: false };
    }
    const el = document.createElement(m.kind === 'audio' ? 'audio' : 'video');
    el.preload = 'metadata'; el.muted = true; el.src = url;
    await Promise.race([once(el, 'loadedmetadata'), once(el, 'error').then(() => { throw new Error(`${m.name}: this browser cannot open it`); })]);
    const out = { dur: isFinite(el.duration) ? el.duration : 0, w: el.videoWidth || 0, h: el.videoHeight || 0 };
    el.removeAttribute('src'); el.load();
    if (m.kind === 'video') {
      // the real frame rate (an iPhone slo-mo clip is 120 or 240 fps): read from the file's sample table
      if (typeof VideoDecoder === 'function' && /\.(mp4|mov|m4v)(\||$)/i.test((m.name || '') + '|' + m.src)) {
        try { const ix = await (await import('./frames.js')).openIndex(url); if (ix.fps > 1) out.fps = Math.round(ix.fps * 100) / 100; } catch { /* keep 30 */ }
      }
      try { out.hasAudio = !!(await this.decodeAudio(m.id, m)); } catch { out.hasAudio = false; }
    } else out.hasAudio = true;
    return out;
  }

  /** the live element the preview draws/plays (one per media) */
  element(id) {
    let el = this.els.get(id);
    if (el) return el;
    const m = this.media(id); if (!m) return null;
    if (m.kind === 'image') { el = new Image(); el.decoding = 'async'; el.src = this.url(m); }
    else {
      el = document.createElement(m.kind === 'audio' ? 'audio' : 'video');
      el.preload = 'auto'; el.playsInline = true; el.src = this.url(m);
    }
    this.els.set(id, el);
    return el;
  }
  /** the <audio> element playing a media's replacement sound (media.audio.src), or null */
  audioElement(m) {
    if (!m?.audio?.src || MediaStore.hearOriginal) return null;   // A/B in the Audio tab: the original sound for a moment
    const key = 'audio:' + m.audio.src;
    let el = this.els.get(key);
    if (!el) { el = document.createElement('audio'); el.preload = 'auto'; el.src = this.url({ src: m.audio.src }); this.els.set(key, el); }
    return el;
  }
  release(id) {
    for (const map of [this.els, this.grab]) {
      const el = map.get(id); if (el && el.tagName !== 'IMG') { el.removeAttribute('src'); el.load(); } map.delete(id);
    }
  }

  async _grabEl(id) {
    let el = this.grab.get(id);
    if (!el) {
      const m = this.media(id);
      el = document.createElement('video'); el.muted = true; el.preload = 'auto'; el.src = this.url(m);
      this.grab.set(id, el);
    }
    if (el.readyState < 1) await once(el, 'loadedmetadata');
    return el;
  }

  /** ImageBitmap of the source frame at t (serialised so many callers don't fight over one element) */
  frameAt(id, t, w = 160) {
    const job = this.queue.then(async () => {
      const m = this.media(id); if (!m) return null;
      if (m.kind === 'image') { const img = this.element(id); if (!img.complete) await img.decode(); return createImageBitmap(img, { resizeWidth: w, resizeQuality: 'medium' }); }
      const el = await this._grabEl(id);
      await seekExact(el, Math.min(Math.max(0, t), Math.max(0, el.duration - 0.05)));
      return createImageBitmap(el, { resizeWidth: w, resizeHeight: Math.round(w * el.videoHeight / el.videoWidth) || w, resizeQuality: 'medium' });
    });
    this.queue = job.catch(() => {});
    return job;
  }

  async thumbs(id, { count = 12, h = 64 } = {}) {
    const key = id + ':' + count + ':' + h;
    if (this.thumbCache.has(key)) return this.thumbCache.get(key);
    const p = (async () => {
      const m = this.media(id); if (!m || m.kind === 'audio') return [];
      const w = Math.max(16, Math.round(h * (m.w || 16) / (m.h || 9)));
      const out = [];
      for (let i = 0; i < count; i++) { const t = (m.dur || 1) * (i + 0.5) / count; out.push({ t, bitmap: await this.frameAt(id, t, w) }); }
      return out;
    })();
    this.thumbCache.set(key, p);
    return p;
  }

  /** decode the media's sound once: 16 kHz mono (speech models) and 48 kHz stereo PCM (mixdown) */
  decodeAudio(id, mHint) {
    const m0 = mHint || this.media(id), key = id + (m0?.audio?.src ? '|' + m0.audio.src : '');
    if (this.audioCache.has(key)) return this.audioCache.get(key);
    const p = (async () => {
      const m = m0; if (!m || m.kind === 'image') return null;
      const bytes = await (await fetch(m.audio?.src ? this.url({ src: m.audio.src }) : this.url(m))).arrayBuffer();
      let b48;
      try { b48 = await new OfflineAudioContext(2, 1, 48000).decodeAudioData(bytes.slice(0)); } catch { return null; }
      if (!b48 || !b48.length) return null;
      const b16 = await new OfflineAudioContext(1, 1, 16000).decodeAudioData(bytes);
      const mono16k = new Float32Array(b16.length);
      for (let c = 0; c < b16.numberOfChannels; c++) { const d = b16.getChannelData(c); for (let i = 0; i < d.length; i++) mono16k[i] += d[i] / b16.numberOfChannels; }
      // a stereo recording whose two sides are out of phase (some phone / lapel mic setups) cancels to near silence
      // when the sides are averaged: speech recognition then hears nothing in a voice everybody can hear. Use the
      // louder side instead when the average lost most of the sound.
      if (b16.numberOfChannels === 2) {
        const L = b16.getChannelData(0), R = b16.getChannelData(1), ms = (d) => { let e = 0; for (let i = 0; i < d.length; i += 7) e += d[i] * d[i]; return e; };
        const eL = ms(L), eR = ms(R), eM = ms(mono16k);
        if (Math.max(eL, eR) > 1e-6 && eM < 0.12 * Math.max(eL, eR)) mono16k.set(eL >= eR ? L : R);
      }
      const L = b48.getChannelData(0), R = b48.numberOfChannels > 1 ? b48.getChannelData(1) : L;
      return { mono16k, pcm48k: { sampleRate: 48000, length: b48.length, channels: [L, R] }, buffer: b48 };
    })();
    this.audioCache.set(key, p);
    return p;
  }

  peaks(id, perSecond = 50) {
    const key = id + ':' + perSecond + ':' + (this.media(id)?.audio?.src || '');
    if (this.peakCache.has(key)) return this.peakCache.get(key);
    const p = this.decodeAudio(id).then((a) => {
      if (!a) return null;
      const L = a.pcm48k.channels[0].slice(0);
      return runWorker('peaks.js', { samples: L, sampleRate: 48000, perSecond }, [L.buffer]);
    });
    this.peakCache.set(key, p);
    return p;
  }

  /** exact, forward-moving frame source for export (and anything that needs every frame) */
  async openReader(id) {
    const m = this.media(id);
    if (!m) return null;
    if (m.kind === 'image') { const img = this.element(id); if (!img.complete) await img.decode(); return { frame: () => img, close() {} }; }
    if (typeof VideoDecoder === 'function' && /\.(mp4|mov|m4v)$/i.test(m.src)) {
      try { return await Mp4FrameReader.open(this.url(m)); } catch (e) { console.warn('[media] WebCodecs reader unavailable, seeking instead:', e.message); }
    }
    return SeekReader.open(this.url(m));
  }

  /** a FrameStream (core/frames.js) over the media's frames: decoder-ordered reads for ramps, slow motion, reverse,
   *  freeze (null when the file or the browser cannot do it — callers then use the element / openReader) */
  async openStream(id, opts = {}) {
    const m = this.media(id);
    if (!m || m.kind !== 'video' || typeof VideoDecoder !== 'function' || !/\.(mp4|mov|m4v)(\||$)/i.test((m.name || '') + '|' + m.src)) return null;
    try { return await (await import('./frames.js')).FrameStream.open(this.url(m), opts); } catch (e) { console.warn('[media] frame stream unavailable:', e.message); return null; }
  }
}

export function seekExact(el, t) {
  return new Promise((res) => {
    if (Math.abs(el.currentTime - t) < 1e-4 && el.readyState >= 2) return res();
    const done = () => { clearTimeout(dog); el.removeEventListener('seeked', done); res(); };
    const dog = setTimeout(done, 3000);
    el.addEventListener('seeked', done);
    el.currentTime = t;
  });
}

// ── workers ──
const WORKERS = new URL('./workers/', import.meta.url).href;
export function runWorker(file, msg, transfer = []) {
  return new Promise((res, rej) => {
    const w = new Worker(WORKERS + file, { type: 'module' });
    w.onmessage = (e) => { w.terminate(); e.data?.error ? rej(new Error(e.data.error)) : res(e.data.result); };
    w.onerror = (e) => { w.terminate(); rej(new Error(e.message || 'worker failed')); };
    w.postMessage(msg, transfer);
  });
}

// ── export frame readers ──

class SeekReader {
  static async open(url) {
    const el = document.createElement('video'); el.muted = true; el.preload = 'auto'; el.src = url;
    if (el.readyState < 2) await Promise.race([once(el, 'loadeddata'), once(el, 'error')]);
    return new SeekReader(el);
  }
  constructor(el) { this.el = el; }
  async frame(t) { await seekExact(this.el, Math.min(t, Math.max(0, this.el.duration - 0.03))); return this.el; }
  close() { this.el.removeAttribute('src'); this.el.load(); }
}

class Mp4FrameReader {
  static async open(url) {
    const MP4Box = await loadMp4box();
    const buf = await (await fetch(url)).arrayBuffer();
    const file = MP4Box.createFile();
    let info = null; const samples = [];
    file.onError = (e) => { throw new Error(e); };
    file.onReady = (i) => { info = i; };
    buf.fileStart = 0;
    file.appendBuffer(buf); file.flush();
    if (!info || !info.videoTracks.length) throw new Error('no video track');
    const track = info.videoTracks[0];
    file.onSamples = (_id, _u, s) => { for (const x of s) samples.push(x); };
    file.setExtractionOptions(track.id, null, { nbSamples: 1e7 });
    file.start(); file.flush();
    if (!samples.length) throw new Error('no samples');
    const config = await decoderConfig({ codec: track.codec.startsWith('vp08') ? 'vp8' : track.codec, codedWidth: track.video.width, codedHeight: track.video.height, description: describeTrack(file, track.id) });
    const base = Math.min(...samples.map((s) => s.cts));
    const list = samples.map((s) => ({ ts: Math.round(((s.cts - base) * 1e6) / s.timescale), dur: Math.round((s.duration * 1e6) / s.timescale), key: s.is_sync, data: s.data }));
    return new Mp4FrameReader(config, list);
  }
  constructor(config, list) {
    this.config = config; this.list = list; this.frames = []; this.next = 0; this.flushed = false; this.wake = null; this.err = null;
    this.frameDur = list.length > 1 ? Math.max(1000, Math.round((list[list.length - 1].ts - list[0].ts) / (list.length - 1))) : 33333;
    this._open();
  }
  _open() {
    const dec = this.dec = new VideoDecoder({
      output: (f) => { if (dec !== this.dec) { f.close(); return; } this.frames.push(f); this.frames.sort((a, b) => a.timestamp - b.timestamp); this.wake?.(); },
      error: (e) => { if (dec === this.dec) { this.err = e; this.wake?.(); } },
    });
    this.dec.configure(this.config);
  }
  /** the hardware decoder failed on this stream: the software one takes over from the keyframe (once) */
  _software(target) {
    if (this.config.hardwareAcceleration !== 'prefer-hardware') return false;
    const { hardwareAcceleration, ...sw } = this.config; this.config = sw; this.err = null;
    try { this.dec.close(); } catch {}
    for (const f of this.frames) f.close();
    this.frames = []; this._open(); this._seek(target);
    return true;
  }
  _seek(target) {
    let k = 0;
    for (let i = 0; i < this.list.length; i++) if (this.list[i].key && this.list[i].ts <= target) k = i;
    for (const f of this.frames) f.close();
    this.frames = []; this.next = k; this.flushed = false;
    this.dec.reset(); this.dec.configure(this.config);
  }
  _pick(target) {
    const fr = this.frames;
    while (fr.length > 1 && fr[1].timestamp <= target) fr.shift().close();
    const f = fr[0];
    if (f && f.timestamp <= target && f.timestamp + (f.duration || this.frameDur) > target) return f;
    return null;
  }
  async frame(t) {
    try { return await this._frame(t); } catch (e) {
      if (!this._software(Math.round(t * 1e6) + 500)) throw e;
      console.warn('[media] hardware decode failed, decoding in software:', e.message || e);
      return this._frame(t);
    }
  }
  async _frame(t) {
    if (this.err) throw this.err;
    const target = Math.round(t * 1e6) + 500;  // half a millisecond of slack against float rounding
    const first = this.frames[0];
    const lastFed = this.next > 0 ? this.list[this.next - 1].ts : -Infinity;
    if ((first && target < first.timestamp - 1000 && this.next > 0) || target > lastFed + 3e6 || (!first && this.next === 0 && target > 2e6)) this._seek(target);
    for (let guard = 0; guard < 100000; guard++) {
      if (this.err) throw this.err;
      const f = this._pick(target); if (f) return f;
      if (this.next < this.list.length) {
        const until = Math.min(this.list.length, this.next + 8);
        for (; this.next < until; this.next++) {
          const s = this.list[this.next];
          this.dec.decode(new EncodedVideoChunk({ type: s.key ? 'key' : 'delta', timestamp: s.ts, duration: s.dur, data: s.data }));
        }
        if (this.dec.decodeQueueSize > 4 || this.frames.length === 0) await new Promise((r) => { this.wake = r; setTimeout(r, 20); });
      } else if (!this.flushed) { this.flushed = true; await this.dec.flush(); }
      else return this.frames[this.frames.length - 1] || null;   // past the end: hold the last frame
    }
    return null;
  }
  close() { for (const f of this.frames) f.close(); this.frames = []; try { this.dec.close(); } catch {} }
}

/**
 * The decoder config to use: the hardware decoder preferred (a Mac decodes 4K H.264 / HEVC on its media engine at a
 * fraction of the CPU), the plain config where the browser says it cannot (then the software decoder). Throws when the
 * codec cannot be decoded at all.
 */
export async function decoderConfig(base) {
  for (const cfg of [{ ...base, hardwareAcceleration: 'prefer-hardware' }, base]) {
    try { if ((await VideoDecoder.isConfigSupported(cfg)).supported) return cfg; } catch { /* next */ }
  }
  throw new Error('decoder does not support ' + base.codec);
}

export function describeTrack(file, trackId) {
  const trak = file.getTrackById(trackId);
  for (const entry of trak.mdia.minf.stbl.stsd.entries) {
    const box = entry.avcC || entry.hvcC || entry.vpcC || entry.av1C;
    if (box) {
      const DS = globalThis.DataStream;
      const stream = new DS(undefined, 0, DS.BIG_ENDIAN);
      box.write(stream);
      return new Uint8Array(stream.buffer, 8);
    }
  }
  return undefined;
}
