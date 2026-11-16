// KBB Studio · look · speed ramps (slow-fast-slow and friends) with easing curves, on any clip.
// The clip becomes N short pieces at constant speeds that follow the curve (core's model: one speed per item), grouped so
// they move together; later clips on the video track ripple by the change. Sound: muted, or pitch-kept — rendered once
// (WSOLA time-stretch per piece, app/audio) into a WAV beside the project and laid on the SFX track under the ramp.
import { ops } from '../core/history.js';
import { createItem, itemDur, itemEnd, findItem, uid, findMedia } from '../core/project.js';
import { projectDir } from '../core/config.js';

export const CURVES = {
  'slow-fast-slow': { label: 'Slow · fast · slow', fn: (u) => 1 - Math.abs(2 * u - 1), hi: true },
  'fast-slow-fast': { label: 'Fast · slow · fast (hero moment)', fn: (u) => 1 - Math.abs(2 * u - 1), hi: false },
  'ramp-up': { label: 'Ramp up', fn: (u) => u, hi: true },
  'ramp-down': { label: 'Ramp down', fn: (u) => 1 - u, hi: true },
};
export const EASES = {
  sine: { label: 'Smooth (sine)', fn: (x) => (1 - Math.cos(Math.PI * x)) / 2 },
  quad: { label: 'Quad', fn: (x) => (x < 0.5 ? 2 * x * x : 1 - Math.pow(-2 * x + 2, 2) / 2) },
  expo: { label: 'Snappy (expo)', fn: (x) => (x <= 0 ? 0 : x >= 1 ? 1 : x < 0.5 ? Math.pow(2, 20 * x - 10) / 2 : (2 - Math.pow(2, -20 * x + 10)) / 2) },
  linear: { label: 'Linear', fn: (x) => x },
};

/** the pieces of a ramp: [{in, out, speed, dur}] and the new total duration */
export function rampPlan(item, { curve = 'slow-fast-slow', min = 0.4, max = 2.5, ease = 'sine', pieces = 16 } = {}) {
  const c = CURVES[curve] || CURVES['slow-fast-slow'], e = (EASES[ease] || EASES.sine).fn;
  const S = item.out - item.in, n = Math.max(4, Math.min(48, pieces)), ds = S / n, out = [];
  let total = 0;
  for (let i = 0; i < n; i++) {
    const k = e(c.fn((i + 0.5) / n)), v = c.hi ? min + (max - min) * k : max - (max - min) * k;
    const sp = +Math.max(0.1, Math.min(16, v)).toFixed(4), a = item.in + ds * i, b = i === n - 1 ? item.out : item.in + ds * (i + 1);
    const dur = (b - a) / sp; out.push({ in: a, out: b, speed: sp, dur }); total += dur;
  }
  return { pieces: out, duration: total };
}

/**
 * The ramp as ONE undoable step. opts.audio: 'mute' | 'pitch' (needs ctx for the stretch + the file) | 'keep' (tape-speed).
 * Returns { cmd, duration, pieces }.
 */
export async function rampCommand(ctx, itemId, opts = {}) {
  const p = ctx.project, hit = findItem(p, itemId);
  if (!hit || hit.item.type !== 'clip') throw new Error('Select a video clip on the timeline first');
  const { item, track } = hit;
  const plan = rampPlan(item, opts);
  const audio = opts.audio || 'mute', g = item.group || uid('g');
  let t = item.t0;
  const pieces = plan.pieces.map((pc, i) => {
    const it = createItem('clip', Object.assign(structuredClone(item), { id: uid('i'), t0: +t.toFixed(5), in: +pc.in.toFixed(5), out: +pc.out.toFixed(5), speed: pc.speed, group: g, kf: {},
      fadeIn: i === 0 ? item.fadeIn : 0, fadeOut: i === plan.pieces.length - 1 ? item.fadeOut : 0, muted: audio !== 'keep' ? true : item.muted, name: (item.name || 'Clip') + ` · ramp ${i + 1}/${plan.pieces.length}`, ramp: { curve: opts.curve || 'slow-fast-slow', of: item.id } }));
    t += pc.dur; return it;
  });
  const delta = plan.duration - itemDur(item), end0 = itemEnd(item);
  const cmds = [ops.removeItems([item.id]), ops.addItems(pieces.map((it, i) => ({ trackId: track.id, item: it, index: track.items.indexOf(item) + i })), 'Speed ramp')];
  const later = track.items.filter((x) => x.id !== item.id && x.t0 >= end0 - 1e-4);
  if (later.length && Math.abs(delta) > 1e-4) cmds.push(ops.moveItems(later.map((x) => ({ id: x.id, t0: +(x.t0 + delta).toFixed(5) })), 'Ripple'));
  // transitions that pointed at the old clip now point at the first / last piece
  const tfix = [];
  for (const tr of p.tracks) for (const it of tr.items) if (it.type === 'look.transition' && (it.style?.a === item.id || it.style?.b === item.id)) {
    const st = Object.assign({}, it.style); if (st.a === item.id) st.a = pieces[pieces.length - 1].id; if (st.b === item.id) st.b = pieces[0].id;
    tfix.push({ id: it.id, props: { style: st, t0: st.b === pieces[0].id ? it.t0 : +(it.t0 + delta).toFixed(5) } });
  }
  if (tfix.length) cmds.push(ops.setItems(tfix, 'Transitions follow the ramp'));
  const m = findMedia(p, item.media);
  if (audio === 'pitch' && m?.hasAudio && !item.muted) {
    const a = await ctx.media.decodeAudio(m.id);
    if (a) {
      const { stretchPieces } = await import('../audio/client.js');
      const SR = 48000, L = a.pcm48k.channels[0], R = a.pcm48k.channels[1];
      const segs = plan.pieces.map((pc) => ({ a: Math.round(pc.in * SR), b: Math.round(pc.out * SR), rate: pc.speed, len: Math.round(pc.dur * SR) }));
      const out = await stretchPieces([L, R], segs);
      const path = `${projectDir(p.workspace, p.id)}/media/ramp-${item.id}-${Date.now().toString(36)}.wav`;
      const blob = new Blob([out.wav], { type: 'audio/wav' });
      let src = path;
      if (ctx.engine?.available) await ctx.engine.writeFile(path, blob); else src = URL.createObjectURL(blob);
      const media = { id: uid('m'), kind: 'audio', name: 'Ramp sound (pitch kept)', src, dur: plan.duration, w: 0, h: 0, fps: 30, hasAudio: true, size: blob.size };
      const st = p.tracks.find((x) => x.kind === 'sfx') || p.tracks.find((x) => x.kind === 'music');
      cmds.push(ops.addMedia(media), ops.addItems([{ trackId: st.id, item: createItem('audio', { media: media.id, t0: item.t0, in: 0, out: +plan.duration.toFixed(5), volume: item.volume, name: 'Ramp sound', group: g }) }]));
    }
  }
  return { cmd: ops.batch(`Speed ramp (${CURVES[opts.curve || 'slow-fast-slow'].label.toLowerCase()})`, cmds), duration: plan.duration, pieces: pieces.length, delta };
}
