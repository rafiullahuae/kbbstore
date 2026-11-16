// KBB Studio · content · trending-sound cue sheet. Instagram / TikTok add the trending sound IN THE APP, from its
// start; so the cuts must already sit on that sound's beats. Give the studio the sound's tempo (tap it, type the BPM,
// or drop the sound in to have it measured) and it
//   1. snaps every cut on the main track to the nearest beat (≤ ¼ beat away, ripple trim — one undo step),
//   2. puts a marker on every beat that carries a cut ("Beat 9 · CUT"),
//   3. exports a cue sheet (cues.csv + cues.txt) and a GUIDE TRACK (WAV clicks, the cut beats accented) that he can
//      lay under the edit to hear it, then mute: the reel exported without it lines up with the sound in the app.
import { exportsDir } from '../core/config.js';
import { ops } from '../core/history.js';
import { projectDuration, uid } from '../core/project.js';
import { r1 } from './lib/items.js';

/** beat grid of a tempo: beats at offset + k·60/bpm up to T */
export const beatGrid = (bpm, offset, T) => { const out = [], d = 60 / bpm; for (let t = offset; t <= T + 1e-6; t += d) out.push(r1(t)); return out; };

/** the main-track cut points (clip boundaries inside the reel) */
export function cutPoints(p) {
  const vt = p.tracks.find((t) => t.kind === 'video'); if (!vt) return [];
  const its = vt.items.slice().sort((a, b) => a.t0 - b.t0), out = [];
  for (let i = 1; i < its.length; i++) out.push({ t: its[i].t0, prev: its[i - 1].id, next: its[i].id });
  return out;
}

/**
 * Snap the main track's cuts to the beat grid IN PLACE (ripple: the clip before a cut is trimmed to end on the beat,
 * everything after the cut moves with it, so sound and picture stay in sync and nothing leaves a gap). A cut further
 * than maxShift from any beat stays. Returns [{from, to, beat}] in final timeline time.
 */
export function snapTimeline(q, beats, maxShift) {
  const vt = q.tracks.find((t) => t.kind === 'video'); if (!vt) return [];
  const its = vt.items.slice().sort((a, b) => a.t0 - b.t0), out = [];
  for (let i = 1; i < its.length; i++) {
    const prev = its[i - 1], cur = its[i].t0;
    let best = null; for (let k = 0; k < beats.length; k++) { const d = Math.abs(beats[k] - cur); if (!best || d < best.d) best = { d, k }; }
    if (!best || best.d > maxShift || best.d < 1e-3) { out.push({ from: r1(cur), to: r1(cur), beat: best && best.d < 1e-3 ? best.k + 1 : null }); continue; }
    const d = beats[best.k] - cur;
    if ((prev.out - prev.in) / (prev.speed || 1) + d < 0.2) { out.push({ from: r1(cur), to: r1(cur), beat: null }); continue; }
    prev.out = r1(prev.out + d * (prev.speed || 1));
    for (const tr of q.tracks) for (const it of tr.items) if (it !== prev && it.t0 >= cur - 1e-3) it.t0 = r1(it.t0 + d);
    out.push({ from: r1(cur), to: beats[best.k], beat: best.k + 1 });
  }
  return out;
}

/** a guide track: a click on every beat, accented (higher, louder) on the beats with a cut; 48 kHz mono WAV */
export function guideWav(beats, cutBeats, T, { sr = 48000, beatsPerBar = 4 } = {}) {
  const n = Math.ceil((T + 0.5) * sr), x = new Float32Array(n), cuts = new Set(cutBeats);
  beats.forEach((t, k) => {
    const acc = cuts.has(k + 1), bar = k % beatsPerBar === 0, f = acc ? 1760 : bar ? 1320 : 880, amp = acc ? 0.9 : bar ? 0.5 : 0.32, len = Math.round(sr * (acc ? 0.06 : 0.03));
    const i0 = Math.round(t * sr);
    for (let i = 0; i < len && i0 + i < n; i++) x[i0 + i] += amp * Math.sin(2 * Math.PI * f * i / sr) * Math.exp(-i / (len / 4));
  });
  const b = new DataView(new ArrayBuffer(44 + n * 2)), w = (o, s) => { for (let i = 0; i < s.length; i++) b.setUint8(o + i, s.charCodeAt(i)); };
  w(0, 'RIFF'); b.setUint32(4, 36 + n * 2, true); w(8, 'WAVEfmt '); b.setUint32(16, 16, true); b.setUint16(20, 1, true); b.setUint16(22, 1, true);
  b.setUint32(24, sr, true); b.setUint32(28, sr * 2, true); b.setUint16(32, 2, true); b.setUint16(34, 16, true); w(36, 'data'); b.setUint32(40, n * 2, true);
  for (let i = 0; i < n; i++) b.setInt16(44 + i * 2, Math.round(Math.max(-1, Math.min(1, x[i])) * 32767), true);
  return new Blob([b.buffer], { type: 'audio/wav' });
}

/** tempo from a sound the owner drops in (the automation module's beat tracker) */
export async function measureSound(ctx, fileOrMediaId) {
  const auto = await import('../auto/index.js');
  let buf;
  if (typeof fileOrMediaId === 'string') { const m = ctx.project.media.find((x) => x.id === fileOrMediaId); buf = await (await fetch(ctx.media.url(m))).arrayBuffer(); }
  else buf = await fileOrMediaId.arrayBuffer();
  const sr = 22050, ab = await new OfflineAudioContext(1, 1, sr).decodeAudioData(buf), oc = new OfflineAudioContext(1, Math.ceil(ab.duration * sr), sr), s = oc.createBufferSource();
  s.buffer = ab; s.connect(oc.destination); s.start();
  const pcm = (await oc.startRendering()).getChannelData(0);
  const g = await auto.call('beatGrid', { pcm, sr });
  return { bpm: +g.bpm.toFixed(1), offset: +(g.beats[0] || 0).toFixed(3), beats: g.beats.length, dur: +ab.duration.toFixed(2) };
}

/** tap tempo: times (ms) of taps → bpm */
export function tapBpm(taps) {
  if (taps.length < 3) return null;
  const d = []; for (let i = 1; i < taps.length; i++) d.push(taps[i] - taps[i - 1]);
  d.sort((a, b) => a - b); const med = d[Math.floor(d.length / 2)];
  return +(60000 / med).toFixed(1);
}

/**
 * Make the cue sheet. opts: { bpm, offset=0, snap=true, maxShift (s, default ¼ beat), save=true, name }.
 * Returns { bpm, offset, beats, cuts:[{from,to,beat}], markers, files:{csv,txt,wav}, shifted }.
 */
export async function cueSheet(ctx, opts = {}) {
  const p = ctx.project;
  const bpm = Math.max(40, Math.min(220, +opts.bpm || defaultBpm(p))), offset = Math.max(0, +opts.offset || 0);
  const T = projectDuration(p);
  if (T <= 0) throw new Error('The timeline is empty');
  const beats = beatGrid(bpm, offset, T), maxShift = opts.maxShift ?? 60 / bpm / 4;
  const ms = opts.snap === false ? 0 : maxShift;
  // a dry run on a copy gives the plan; the same snap then runs as ONE undoable step
  const plan = snapTimeline(structuredClone(p), beats, ms);
  const cmds = [];
  const moves = plan.filter((c) => Math.abs(c.to - c.from) > 1e-3);
  if (moves.length) cmds.push(ops.mutate('Snap cuts to the beat', (q) => { snapTimeline(q, beats, ms); }));
  const cutBeats = plan.filter((c) => c.beat).map((c) => c.beat);
  const markers = [...(p.markers || []).filter((m) => !m.cue), ...plan.filter((c) => c.beat).map((c) => ({ id: uid('k'), t: c.to, label: `Beat ${c.beat} · CUT`, color: '#ffd400', cue: true }))];
  cmds.push(ops.setProject({ markers, meta: { ...p.meta, content: { ...(p.meta?.content || {}), cues: { bpm, offset, cuts: plan.map((c) => ({ from: c.from, to: c.to, beat: c.beat })) } } } }, 'Beat markers'));
  ctx.history.apply(ops.batch(`Cue sheet · ${bpm} BPM`, cmds));
  const T2 = projectDuration(ctx.project);
  const rows = [['#', 'time (s)', 'time', 'beat', 'bar', 'what']];
  const fmt = (t) => `${Math.floor(t / 60)}:${(t % 60).toFixed(2).padStart(5, '0')}`;
  for (const c of plan) rows.push([rows.length, c.to.toFixed(3), fmt(c.to), c.beat ?? '', c.beat ? Math.floor((c.beat - 1) / 4) + 1 + '.' + (((c.beat - 1) % 4) + 1) : '', c.beat ? 'CUT on the beat' : 'cut (no beat within ¼ beat)']);
  const csv = rows.map((r) => r.join(',')).join('\n') + '\n';
  const txt = `Trending sound cue sheet — ${p.name}\nTempo ${bpm} BPM · first beat ${offset.toFixed(2)} s · reel ${T2.toFixed(2)} s\n\n` +
    `In Instagram / TikTok: add the sound and start it at 0:00${offset ? ` (its first beat is at ${offset.toFixed(2)} s)` : ''}. Every cut below sits on its beat.\n\n` +
    plan.map((c, i) => `${String(i + 1).padStart(2)}. ${fmt(c.to)}  ${c.beat ? 'beat ' + c.beat : 'off-beat'}`).join('\n') + '\n';
  const files = {};
  const wav = guideWav(beatGrid(bpm, offset, T2), cutBeats, T2);
  if (opts.save !== false && ctx.engine?.available) {
    const base = (opts.name || String(p.name || 'reel')).replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').slice(0, 40) || 'reel', dir = exportsDir(p.workspace);
    files.csv = await ctx.engine.writeFile(`${dir}/${base}-cues.csv`, csv);
    files.txt = await ctx.engine.writeFile(`${dir}/${base}-cues.txt`, txt);
    files.wav = await ctx.engine.writeFile(`${dir}/${base}-guide-${Math.round(bpm)}bpm.wav`, wav);
  }
  return { bpm, offset, beats: beats.length, cuts: plan.map((c) => ({ from: c.from, to: c.to, beat: c.beat })), markers: markers.filter((m) => m.cue).length, files, shifted: moves.length, csv, txt, wavBytes: wav.size, wavUrl: URL.createObjectURL(wav) };
}

function defaultBpm(p) {
  const m = p.tracks.find((t) => t.kind === 'music')?.items[0];
  const bpm = m?.bpm || m?.meta?.bpm || p.meta?.content?.cues?.bpm;
  return bpm || 120;
}
