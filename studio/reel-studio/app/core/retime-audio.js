// KBB Studio — core/retime-audio.js (owned by R1): the sound of a retimed clip (speed ≠ 1, a speed ramp, reverse),
// rendered ONCE in the audio worker (app/audio: WSOLA time-stretch with the pitch kept, or tape) and cached. The export
// mixes exactly this buffer and the preview monitor plays exactly this buffer, so what he hears while editing is what
// the MP4 carries — same pitch, same length to the sample, same silences at extreme speeds and while ramping.
//
//   await retimedSound(mediaStore, item, media) -> AudioBuffer (48 kHz stereo, item's timeline length) | null
//   needsRetimedSound(item) -> bool

import { retimeSig, retimeDur, isRetimed } from './retime.js';

const SR = 48000;
const cache = new Map();          // key -> Promise<AudioBuffer|null>   (small LRU: a few clips' worth of sound)
const MAX = 10;

export const needsRetimedSound = (it) => !!it && !it.freeze && isRetimed(it);

export function retimedSound(store, item, media) {
  if (!needsRetimedSound(item) || !media?.hasAudio) return Promise.resolve(null);
  const key = retimeSig(item) + '|' + (media.audio?.src || media.src);
  let p = cache.get(key);
  if (p) { cache.delete(key); cache.set(key, p); return p; }
  p = (async () => {
    const a = await store.decodeAudio(media.id);
    if (!a) return null;
    const len = Math.max(1, Math.round(retimeDur(item) * SR));
    const pad = Math.round(0.05 * SR);
    const from = Math.max(0, Math.floor(item.in * SR) - pad), to = Math.min(a.pcm48k.length, Math.ceil(item.out * SR) + pad);
    if (to <= from) return null;
    const ch = a.pcm48k.channels.map((c) => c.slice(from, to));
    const { retimeSound } = await import('../audio/client.js');
    const it = { in: item.in, out: item.out, speed: item.speed || 1, curve: item.curve || null, reverse: !!item.reverse, keepPitch: item.keepPitch !== false, rampMute: !!item.rampMute };
    const r = await retimeSound(ch, from, it, len);
    const buf = new AudioBuffer({ numberOfChannels: 2, length: len, sampleRate: SR });
    buf.copyToChannel(r.channels[0], 0); buf.copyToChannel(r.channels[1] || r.channels[0], 1);
    return buf;
  })();
  p.catch(() => cache.delete(key));
  cache.set(key, p);
  while (cache.size > MAX) cache.delete(cache.keys().next().value);
  return p;
}

/** the buffer if it is already rendered (no waiting), else undefined — the preview asks every frame */
const done = new WeakMap();
export function retimedSoundNow(store, item, media) {
  const p = retimedSound(store, item, media);
  if (done.has(p)) return done.get(p);
  p.then((b) => done.set(p, b), () => done.set(p, null));
  return undefined;
}
