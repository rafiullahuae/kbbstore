// KBB Studio — core/audio-gain.js: the gain of a sound item at timeline time t, ONE pure function shared by the preview
// monitor (editor/preview.js, editor/preview-audio.js) and the export mix (core/export.js), so what he hears is what is
// exported (the owner, 20 Nov: "we don't have controls on the background music when we add, we can't reduce the volume").
//
//   gain = volume × duck(t) × fades
//     volume   item.volume (0–2), or the legacy keyframed volume (kf.volume: Auto music's ducking written as absolute
//              gains, base × dip). The Inspector's Volume slider scales those keyframes, so both forms answer it.
//     duck     item.duck = { on, db, smooth, speech:[[a,b]] timeline s }: the music dips by `db` under each speech range,
//              ramping down `att` s before it and back up over `rel` s after it (smoothstep, so no corner is audible).
//              Speech ranges are timeline-anchored (the voice does not move when the music does), found once by the
//              captions' speech detector (audio/music.js) and stored on the item: the gain stays a pure function.
//     fades    item.fadeIn / fadeOut (linear, as before)
//
// item.loop = { a, b } (source seconds) repeats a..b to fill the item: core/retime.js srcTime() wraps, the export loops
// the buffer (AudioBufferSourceNode loopStart / loopEnd).

import { evalProp, fadeAt } from './render.js';

export const DUCK_DEFAULT = { db: 10, smooth: 0.5 };
/** attack (before speech) and release (after it) in seconds for smoothness 0..1 */
export const duckTimes = (smooth = DUCK_DEFAULT.smooth) => { const s = Math.max(0, Math.min(1, +smooth || 0)); return { att: 0.04 + 0.26 * s, rel: 0.12 + 0.88 * s }; };
const ss = (x) => (x <= 0 ? 0 : x >= 1 ? 1 : x * x * (3 - 2 * x));

/** 0..1: how far the music is pushed down at timeline time t (1 = fully ducked) */
export function duckDepth(duck, t) {
  if (!duck?.on || !duck.speech?.length) return 0;
  const { att, rel } = duckTimes(duck.smooth);
  let d = 0;
  for (const [a, b] of duck.speech) {
    if (t < a - att || t > b + rel) continue;
    const v = t < a ? ss((t - (a - att)) / att) : t <= b ? 1 : 1 - ss((t - b) / rel);
    if (v > d) d = v; if (d >= 1) break;
  }
  return d;
}
/** the duck multiplier at t (1 = untouched) */
export function duckGain(item, t) {
  const d = duckDepth(item.duck, t); if (!d) return 1;
  const low = Math.pow(10, -Math.max(0, Math.min(60, +item.duck.db || 0)) / 20);
  return 1 - d * (1 - low);
}
/** volume (keyframed or not) × duck, without fades */
export function levelAt(item, t) {
  const v = item.kf?.volume?.length ? evalProp(item, 'volume', t) : (item.volume ?? 1);
  return Math.max(0, v) * duckGain(item, t);
}
/** the whole gain at t: volume × duck × fades (track volume not included) */
export const audioGain = (item, t) => levelAt(item, t) * fadeAt(item, t);
/** true when the gain changes over the item's life by more than its fades (needs a curve in the export) */
export const hasGainCurve = (item) => !!(item.kf?.volume?.length || (item.duck?.on && item.duck.speech?.length));
