// KBB Studio · Audio (Lane L): pro sound in one click — voice cleanup (RNNoise + de-hum + EQ + de-ess + levelling +
// compression), platform loudness on export (-14 LUFS, -1 dBTP, measured), SFX on cuts, beat-synced cuts and a riser
// before the reveal. Every action is one undo step. The "🔊 Audio" tab of the editor's right column.
//
// API (also used by tools/l-check.cjs):
//   cleanVoice(ctx, {mediaIds, denoise, eq, deess, level, compress, dehum})   undoVoice(ctx, {mediaIds})
//   setLoudness(ctx, platformId | null)     measureMix(ctx)     loudnessReport()
//   sfxOnCuts(ctx, {sounds})                beatSync(ctx, {window})             riserBeforeReveal(ctx, {at})
import { registerModule } from '../core/modules.js';
import { registerMixPost, mixAudio, postMix } from '../core/export.js';
import { ops } from '../core/history.js';
import { bus } from '../core/bus.js';
import { projectDir } from '../core/config.js';
import { findItem, findMedia, createItem, itemEnd, projectDuration, tlTime } from '../core/project.js';
import { EQ_PRESETS, wav } from './dsp.js';
import { cleanVoice as cleanInWorker, master, measure } from './client.js';
import { audioPanel } from './panel.js';
import { DEFAULT_VOICE, STAGES, strengthLabel, strengthDb, speechRanges } from './voice.js';
import { MediaStore } from '../core/media.js';
import { duckKeys } from '../auto/lib/music.js';
import { speechOnTimeline } from '../auto/commands.js';

export const PLATFORMS = {
  instagram: { label: 'Instagram Reels', lufs: -14, tp: -1 },
  tiktok: { label: 'TikTok', lufs: -14, tp: -1 },
  youtube: { label: 'YouTube / Shorts', lufs: -14, tp: -1 },
};
export { EQ_PRESETS };

const hash = (o) => { const s = JSON.stringify(o); let h = 2166136261; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return (h >>> 0).toString(36); };

/** media whose voice to clean: the selected clips' media, else every video/audio used on the video track with sound */
export function voiceMedia(ctx, ids) {
  const p = ctx.project;
  const sel = (ids || ctx.selection?.() || []).map((id) => findItem(p, id)?.item).filter((i) => i?.media);
  const used = sel.length ? sel : p.tracks.filter((t) => t.kind === 'video' || t.kind === 'overlay').flatMap((t) => t.items).filter((i) => i.media);
  return [...new Set(used.map((i) => i.media))].map((id) => findMedia(p, id)).filter((m) => m && m.hasAudio && m.kind !== 'image');
}

export async function cleanVoice(ctx, opt = {}) {
  const p = ctx.project, list = opt.mediaIds ? opt.mediaIds.map((id) => findMedia(p, id)).filter(Boolean) : voiceMedia(ctx);
  if (!list.length) throw new Error('No clip with sound to clean');
  const settings = opt.v === 2 ? { v: 2, strength: opt.strength, stages: opt.stages, eq: opt.eq, deess: opt.deess, reverb: opt.reverb, lufs: opt.lufs, tp: opt.tp }
    : { denoise: opt.denoise ?? 1, eq: opt.eq || 'clear', deess: opt.deess ?? 0.5, level: opt.level !== false, compress: opt.compress !== false, dehum: opt.dehum !== false };
  const cmds = [], report = [];
  for (const m of list) {
    ctx.progress?.(`Cleaning the voice in ${m.name}…`, null);
    const orig = await ctx.media.decodeAudio(m.id, Object.assign({}, m, { audio: null }));   // always from the original sound
    if (!orig) continue;
    const { L, stats } = await cleanInWorker(orig.pcm48k.channels[0], orig.pcm48k.channels[1], settings);
    const blob = new Blob([wav([L], 48000)], { type: 'audio/wav' });
    const path = `${projectDir(p.workspace, p.id)}/media/${m.id}-voice-${hash(settings)}.wav`;
    let src = path;
    if (ctx.engine?.available) await ctx.engine.writeFile(path, blob); else src = URL.createObjectURL(blob);
    const r = { floorBefore: +stats.floorBefore.toFixed(1), floorAfter: +stats.floorAfter.toFixed(1), lufs: +stats.lufs.toFixed(1), hum: stats.hum };
    if (settings.v === 2) Object.assign(r, { tp: +(+stats.tp).toFixed(1), stages: stats.stages, humDb: stats.humDb });
    cmds.push(ops.setMedia(m.id, { audio: { src, settings, stats: r } }, 'Clean voice'));
    report.push({ media: m.id, name: m.name, ...r });
  }
  ctx.progress?.(null, null);
  if (cmds.length) ctx.history.apply(ops.batch('Clean up voice', cmds));
  return report;
}
export function undoVoice(ctx, { mediaIds } = {}) {
  const list = (mediaIds ? mediaIds.map((id) => findMedia(ctx.project, id)) : ctx.project.media).filter((m) => m?.audio);
  if (list.length) ctx.history.apply(ops.batch('Original sound', list.map((m) => ops.setMedia(m.id, { audio: null }))));
  return list.length;
}

// ── ✨ Enhance voice: the switchable chain (voice.js), rendered once into a WAV the preview plays and the export mixes ──
export { DEFAULT_VOICE, STAGES, strengthLabel, strengthDb };
export async function enhanceVoice(ctx, opt = {}) {
  const settings = Object.assign({}, DEFAULT_VOICE, opt, { v: 2, stages: Object.assign({}, DEFAULT_VOICE.stages, opt.stages || {}) });
  delete settings.mediaIds;
  const report = await cleanVoice(ctx, Object.assign({ mediaIds: opt.mediaIds }, settings));
  // music under the voice dips while he speaks (only music that has no ducking of its own yet)
  let ducked = 0;
  if (opt.duck !== false) { try { ducked = await duckMusic(ctx, { onlyNew: true }); } catch (e) { console.warn('[audio] ducking', e); } }
  return Object.assign(report, { ducked });
}
/** A/B: hear the original sound (true) or the enhanced one (false) in the preview; nothing in the project changes */
export function hearOriginal(on) { MediaStore.hearOriginal = !!on; bus.emit('audio:ab', { original: !!on }); window.kbbEditor?.preview?.draw?.(); return !!on; }
export const hearingOriginal = () => !!MediaStore.hearOriginal;

/** music dips under speech: volume keyframes on every music item (one undo step). onlyNew: skip music already ducked */
export async function duckMusic(ctx, { onlyNew = false, depth = 0.3 } = {}) {
  const p = ctx.project, music = p.tracks.filter((t) => t.kind === 'music').flatMap((t) => t.items).filter((i) => i.media && !(onlyNew && i.kf?.volume?.length));
  if (!music.length) return 0;
  let speech = [];
  for (const m of voiceMedia(ctx, [])) {
    const a = await ctx.media.decodeAudio(m.id); if (!a) continue;
    speech = speech.concat(speechOnTimeline(p, m.id, speechRanges(a.mono16k, 16000)));
  }
  if (!speech.length) return 0;
  speech.sort((x, y) => x[0] - y[0]);
  ctx.history.apply(ops.batch('Duck music under speech', music.map((it) => {
    const base = it.volume ?? 1;   // the keyframes carry the volume itself (as Auto music writes them)
    return ops.setItems([{ id: it.id, props: { kf: Object.assign({}, it.kf, { volume: duckKeys(speech, it, { base, duck: base * depth }) }) } }], 'Duck music');
  })));
  return music.length;
}

// ── platform loudness, on export ──
let REPORT = null;
export const loudnessReport = () => REPORT;
export function setLoudness(ctx, platform) {
  const meta = Object.assign({}, ctx.project.meta || {});
  const P = PLATFORMS[platform];
  meta.audio = Object.assign({}, meta.audio, { target: P ? { platform, lufs: P.lufs, tp: P.tp } : null });
  ctx.history.apply(ops.setProject({ meta }, P ? `Loudness · ${P.label}` : 'Loudness off'));
  return P ? `${P.label}: ${P.lufs} LUFS, true peak ${P.tp} dBTP on export` : 'Loudness target off';
}
registerMixPost('audio.loudness', async (buf, project) => {
  const tg = project.meta?.audio?.target;
  if (!tg || !tg.lufs || !buf) return null;
  const L = buf.getChannelData(0), R = buf.numberOfChannels > 1 ? buf.getChannelData(1) : L;
  // the limiter aims 0.3 dB under the ceiling: AAC/Opus encoding can add a few tenths of overshoot
  const r = await master([L, R], { lufs: tg.lufs, tp: tg.tp, margin: 0.5 });
  const out = new AudioBuffer({ length: buf.length, numberOfChannels: 2, sampleRate: buf.sampleRate });
  out.copyToChannel(r.channels[0], 0); out.copyToChannel(r.channels[1], 1);
  REPORT = { platform: tg.platform, target: tg.lufs, tpTarget: tg.tp, ...Object.fromEntries(Object.entries(r.report).map(([k, v]) => [k, +(+v).toFixed(2)])) };
  bus.emit('audio:loudness', REPORT);
  return out;
});
/** the export's mix, measured (what the export would deliver) */
export async function measureMix(ctx) {
  const T = projectDuration(ctx.project); if (T <= 0) return null;
  const buf = await mixAudio(ctx.project, ctx.media, T); if (!buf) return null;
  const out = await postMix(buf, ctx.project);
  return measure([out.getChannelData(0), out.numberOfChannels > 1 ? out.getChannelData(1) : out.getChannelData(0)]);
}

// ── sound design ──
async function sfxLib(ctx) { const A = await import('../auto/index.js'); return (await A.library(ctx)).sfx; }
async function libMedia(p, s) { const C = await import('../auto/commands.js'); return C.libraryMedia(p, 'library/sfx/' + s.file, 'audio', s.duration, s.id); }
const cutTimes = (p) => {
  const vt = p.tracks.find((t) => t.kind === 'video'); if (!vt) return [];
  const list = vt.items.slice().sort((a, b) => a.t0 - b.t0), out = [];
  for (let i = 0; i < list.length - 1; i++) if (Math.abs(itemEnd(list[i]) - list[i + 1].t0) < 0.05 && !(list[i].ramp && list[i + 1].ramp && list[i].group === list[i + 1].group)) out.push({ t: list[i + 1].t0, a: list[i], b: list[i + 1] });
  return out;
};
export async function sfxOnCuts(ctx, { sounds = ['whoosh', 'swoosh', 'swipe'], volume = 0.5 } = {}) {
  const p = ctx.project, cuts = cutTimes(p); if (!cuts.length) throw new Error('There is no cut yet');
  const lib = await sfxLib(ctx), st = p.tracks.find((t) => t.kind === 'sfx');
  const old = st.items.filter((i) => i.by === 'audio.cuts').map((i) => i.id);
  const cmds = old.length ? [ops.removeItems(old)] : [], items = [], added = new Map();
  let pp = p;
  for (const [k, c] of cuts.entries()) {
    const s = lib.find((x) => x.id === sounds[k % sounds.length]) || lib[0]; if (!s) continue;
    let media = added.get(s.id);
    if (!media) { const r = await libMedia(pp, s); media = r.media; if (r.cmd) { cmds.push(r.cmd); pp = { ...pp, media: [...pp.media, media] }; } added.set(s.id, media); }
    items.push({ trackId: st.id, item: createItem('audio', { media: media.id, t0: +Math.max(0, c.t - (s.hitAt || 0)).toFixed(4), in: 0, out: s.duration, volume, name: 'SFX · ' + s.id, by: 'audio.cuts' }) });
  }
  cmds.push(ops.addItems(items, 'SFX on cuts'));
  ctx.history.apply(ops.batch('SFX on every cut', cmds));
  return items.length;
}

/** beats of the music on the timeline (timeline seconds) */
export async function timelineBeats(ctx) {
  const p = ctx.project, A = await import('../auto/index.js'), lib = (await A.library(ctx)).music, out = [];
  for (const tr of p.tracks.filter((t) => t.kind === 'music')) for (const it of tr.items) {
    const m = findMedia(p, it.media); if (!m) continue;
    let beats = lib.find((x) => x.src === m.src)?.beats;
    if (!beats) {
      const a = await ctx.media.decodeAudio(m.id); if (!a) continue;
      const pcm = a.mono16k.slice(); beats = (await A.call('beatGrid', { pcm, sr: 16000 }, [pcm.buffer])).beats;
    }
    for (const b of beats) if (b >= it.in && b <= it.out) out.push(tlTime(it, b));
  }
  return out.sort((a, b) => a - b);
}
/** snap every cut on the video track to the nearest music beat (± window s) — ripples the clips after it */
export async function beatSync(ctx, { window = 0.45 } = {}) {
  const beats = await timelineBeats(ctx);
  if (!beats.length) throw new Error('Add music first (Add music, or a track from the Music tab)');
  let moved = 0;
  ctx.history.apply(ops.mutate('Cuts on the beat', (p) => {
    const vt = p.tracks.find((t) => t.kind === 'video'); const list = vt.items.slice().sort((a, b) => a.t0 - b.t0);
    let shift = 0;
    for (let i = 0; i < list.length; i++) {
      const it = list[i]; it.t0 = +(it.t0 + shift).toFixed(5);
      if (i === list.length - 1) break;
      const nx = list[i + 1]; if (Math.abs(itemEnd(it) - (nx.t0 + shift)) > 0.05 || (it.ramp && nx.ramp && it.group === nx.group)) continue;
      // the nearest beat the clip can reach (a clip cannot run past the end of its source: then the beat before)
      const cut = itemEnd(it), m = findMedia(p, it.media), sp = it.speed || 1;
      const cands = beats.filter((b) => Math.abs(b - cut) <= window).sort((x, y) => Math.abs(x - cut) - Math.abs(y - cut));
      const b = cands.find((bb) => { const o = it.out + (bb - cut) * sp; return o > it.in + 0.2 && !(m && m.kind !== 'image' && m.dur && o > m.dur + 1e-3); });
      if (b == null) continue;
      const d = b - cut; if (Math.abs(d) < 1 / 60) continue;
      it.out = +(it.out + d * sp).toFixed(5); shift += d; moved++;
    }
    // transitions stay on their cuts
    for (const tr of p.tracks) for (const x of tr.items) if (x.type === 'look.transition') {
      const b = findItem(p, x.style?.b)?.item; if (b) x.t0 = +(b.t0 - (x.out - x.in) / 2).toFixed(5);
    }
  }));
  return { moved, beats: beats.length };
}

/** a riser that peaks exactly on the reveal (the selected item's start, the end card, a price/text pop, or the playhead) */
export async function riserBeforeReveal(ctx, { at = null, hit = true } = {}) {
  const p = ctx.project;
  let t = at;
  if (t == null) {
    const sel = (ctx.selection?.() || []).map((id) => findItem(p, id)?.item).find((i) => i && i.type !== 'clip');
    const all = p.tracks.flatMap((x) => x.items);
    const rev = sel || all.find((i) => i.type === 'auto.endcard') || all.find((i) => i.type === 'auto.pricetag' || (i.type === 'look.text' && i.style?.preset === 'price'));
    t = rev ? rev.t0 : ctx.playhead?.() ?? 0;
  }
  const lib = await sfxLib(ctx), riser = lib.find((s) => s.id === 'riser'), boom = lib.find((s) => s.id === 'impact');
  if (!riser) throw new Error('The riser sound is missing from library/sfx');
  const st = p.tracks.find((x) => x.kind === 'sfx'), cmds = [], items = [];
  let pp = p;
  for (const [s, at0, vol] of [[riser, t - riser.hitAt, 0.6], ...(hit && boom ? [[boom, t - boom.hitAt, 0.7]] : [])]) {
    const r = await libMedia(pp, s); if (r.cmd) { cmds.push(r.cmd); pp = { ...pp, media: [...pp.media, r.media] }; }
    const skip = Math.max(0, -at0);
    items.push({ trackId: st.id, item: createItem('audio', { media: r.media.id, t0: +Math.max(0, at0).toFixed(4), in: +skip.toFixed(4), out: s.duration, volume: vol, fadeIn: s === riser ? 0.4 : 0, name: 'SFX · ' + s.id + ' (reveal)', by: 'audio.reveal' }) });
  }
  cmds.push(ops.addItems(items, 'Riser'));
  ctx.history.apply(ops.batch('Riser before the reveal', cmds));
  return t;
}

registerModule({
  id: 'audio', label: 'Audio', icon: '🔊',
  tab: { label: '🔊 Audio', order: 20 },
  panel: (el, ctx) => audioPanel(el, ctx, { cleanVoice, undoVoice, setLoudness, measureMix, loudnessReport, sfxOnCuts, beatSync, riserBeforeReveal, voiceMedia, PLATFORMS, enhanceVoice, hearOriginal, hearingOriginal, duckMusic }),
});
// in the editor's "Do it for me" bar
registerModule({
  id: 'enhance-voice', label: 'Enhance voice', icon: '🎙',
  run: async (ctx) => {
    const r = await enhanceVoice(ctx, {});
    window.kbbEditor?.showSide?.('mod:audio');
    return r.map((x) => `🎙 ${x.name}: noise ${x.floorBefore} → ${x.floorAfter} dB, ${x.lufs} LUFS`).join(' · ') + (r.ducked ? ` · music ducked under speech` : '') + '. A/B and every stage: 🔊 Audio.';
  },
});
