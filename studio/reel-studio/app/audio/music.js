// KBB Studio · audio · the background music's own controls (the owner, 20 Nov: "we don't have controls on the background
// music when we add, we can't reduce the volume etc").
//
// What was wrong: a music clip did have a Sound section, but (1) it sat below a video clip's Speed panel ("Keep pitch —
// natural voice at any speed"), under the fold at 1440×900, and (2) music added by "Add music" carries its ducking as
// absolute volume keyframes (kf.volume), which OVERRIDE item.volume — so on that music the Volume slider moved and the
// sound did not change. Here the music clip gets its own "Music" section, first in the Inspector, and the gain is one
// pure function (core/audio-gain.js) that the preview and the export both use.
//
//   musicSection(insp, item, track, m)      the Inspector's 🎵 Music section (Volume, Mute, Fades, Start at, Loop,
//                                            Auto-duck under voice, Replace song…)
//   setVolume(ed, id, v)                     0–2; scales legacy ducking keyframes so they keep their shape
//   setStart(ed, id, s) / setLoop(ctx, id, on) / setDuck(ctx, id, {on, db, smooth}) / redetectSpeech(ctx, id)
//   replaceSong(ctx, itemId, track)          ONE undo step; keeps volume, mute, fades, ducking, position and length
//   timelineSpeech(ctx)                      speech on the timeline [[a,b]] (classifyAudio ∩ energy, the captions' own)
import { ops } from '../core/history.js';
import { findItem, findMedia, itemDur, itemEnd, projectDuration, clone } from '../core/project.js';
import { DUCK_DEFAULT } from '../core/audio-gain.js';
import { h, slider, toast, fmtDur } from '../editor/ui.js';
import { classifyAudio } from '../captions/nonspeech.js';
import { speechRanges } from './voice.js';
import { speechOnTimeline } from '../auto/commands.js';

const pct = (v) => Math.round(v * 100) + '%';
export const dbOf = (v) => (v <= 0.0005 ? '−∞ dB' : (v >= 1 ? '+' : '−') + Math.abs(20 * Math.log10(v)).toFixed(1) + ' dB');
const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
const secs = (v) => (Math.round(v * 10) / 10).toFixed(1) + ' s';

const isMusic = (p, id) => { const hit = findItem(p, id); return hit && hit.item.type === 'audio' && hit.track.kind === 'music' ? hit : null; };
/** the end of the pictures (video + overlay tracks), else the reel */
export function videoEnd(p) {
  let e = 0; for (const t of p.tracks) if (t.kind === 'video' || t.kind === 'overlay') for (const it of t.items) e = Math.max(e, itemEnd(it));
  return e || projectDuration(p);
}

// ── commands (each ONE undo step; slider drags coalesce into one) ──
const set = (ed, id, props, label) => ed.history.apply(ops.setItems([{ id, props }], label));

/** volume 0..2. Legacy ducking keyframes (absolute gains) are scaled by the same ratio from `base` (the drag's start) */
export function setVolume(ed, id, v, base = null) {
  const hit = isMusic(ed.project, id) || findItem(ed.project, id); if (!hit) return;
  const it = hit.item, from = base || { volume: it.volume ?? 1, kf: it.kf };
  v = Math.max(0, Math.min(2, +v || 0));
  const props = { volume: v };
  if (from.kf?.volume?.length && from.volume > 0) props.kf = Object.assign({}, from.kf, { volume: from.kf.volume.map(([t, g, e]) => [t, +(g * v / from.volume).toFixed(5), e]) });
  set(ed, id, props, 'Music volume');
}
/** start the song at s (source seconds); the clip keeps its length (a song without loop ends where the file ends) */
export function setStart(ed, id, s) {
  const hit = findItem(ed.project, id); if (!hit) return;
  const it = hit.item, m = findMedia(ed.project, it.media), dur = m?.dur || Infinity, span = it.out - it.in;
  s = Math.max(0, Math.min(+s || 0, dur - 0.5));
  const out = it.loop ? s + span : Math.min(dur, s + span);
  const props = { in: +s.toFixed(3), out: +out.toFixed(3) };
  // a loop that starts after its own region would never come back: the region begins where the song now starts
  if (it.loop && s >= it.loop.b - 0.5) props.loop = { a: 0, b: it.loop.b };
  set(ed, id, props, 'Music start');
}
/** loop region for a song: its seamless loop (tools/r4-music.cjs), else the body before its written ending (bars), else the whole file */
async function loopRegion(ctx, m) {
  try {
    const A = await import('../auto/index.js'), lib = await A.library(ctx);
    const t = lib.music.find((x) => x.src === m.src || 'library/music/' + x.file === m.src);
    if (t?.loop) return { a: t.loop.a, b: t.loop.b };   // the viral-style tracks: their body loops seamlessly
    if (t?.endingStart != null) { const a = t.sections?.find((s) => s.name !== 'intro')?.t0 ?? 0; return { a: +a.toFixed(3), b: +t.endingStart.toFixed(3) }; }
  } catch {}
  return { a: 0, b: +(m.dur || 0).toFixed(3) };
}
/** Loop to fill the video: on = the song repeats until the pictures end; off = it plays once (ends with the file) */
export async function setLoop(ctx, id, on) {
  const p = ctx.project, hit = findItem(p, id); if (!hit) return;
  const it = hit.item, m = findMedia(p, it.media); if (!m) return;
  if (on) {
    const loop = await loopRegion(ctx, m), want = videoEnd(p) - it.t0, sp = it.speed || 1;
    if (it.in >= loop.b - 0.5) loop.a = 0;
    const out = it.in + Math.max(0.5, want > 0.5 ? want : itemDur(it)) * sp;
    ctx.history.apply(ops.setItems([{ id, props: { loop, out: +out.toFixed(3) } }], 'Loop music'));
  } else ctx.history.apply(ops.setItems([{ id, props: { loop: null, out: +Math.min(it.out, m.dur || it.out).toFixed(3) } }], 'Music plays once'));
}

// ── speech on the timeline (Auto-duck) ──
const SPEECH = new Map();   // media id + src -> source-time speech ranges
async function mediaSpeech(ctx, m) {
  const key = m.id + '|' + (m.audio?.src || m.src);
  if (SPEECH.has(key)) return SPEECH.get(key);
  const a = await ctx.media.decodeAudio(m.id); if (!a?.mono16k) { SPEECH.set(key, []); return []; }
  // the captions' speech-vs-music classifier says WHERE someone talks (music under a voice is not speech);
  // the 20 ms energy ranges give the edges. Kept: energy ranges that overlap a speech window.
  const an = classifyAudio(a.mono16k), en = speechRanges(a.mono16k, 16000);
  const win = an.speech.map(([x, y]) => [x - 0.25, y + 0.25]);
  const out = en.filter(([x, y]) => win.some(([u, v]) => x < v && y > u));
  SPEECH.set(key, out);
  return out;
}
/** every stretch of speech on the timeline (voices on the video and overlay tracks), merged, sorted */
export async function timelineSpeech(ctx) {
  const p = ctx.project, seen = new Set(), all = [];
  for (const tr of p.tracks) if (tr.kind === 'video' || tr.kind === 'overlay') for (const it of tr.items) {
    if (!it.media || seen.has(it.media) || it.muted || tr.muted) continue; seen.add(it.media);
    const m = findMedia(p, it.media); if (!m || m.kind === 'image' || m.hasAudio === false) continue;
    const r = await mediaSpeech(ctx, m);
    // speechOnTimeline maps through the video track's clips; an overlay clip maps by its own times
    const onTl = speechOnTimeline(p, m.id, r);
    if (onTl.length) all.push(...onTl);
    else for (const t2 of p.tracks) if (t2.kind === 'overlay') for (const c of t2.items) if (c.media === m.id) for (const [a, b] of r) { const s = Math.max(a, c.in), e = Math.min(b, c.out); if (e > s) all.push([c.t0 + (s - c.in) / (c.speed || 1), c.t0 + (e - c.in) / (c.speed || 1)]); }
  }
  all.sort((x, y) => x[0] - y[0]);
  const merged = [];
  for (const [a, b] of all) { const l = merged[merged.length - 1]; if (l && a - l[1] < 0.25) l[1] = Math.max(l[1], b); else merged.push([a, b]); }
  return merged.map(([a, b]) => [+a.toFixed(3), +b.toFixed(3)]);
}
/** Auto-duck: on/off, depth (dB) and smoothness. Turning it on (or "detect again") finds the speech once. */
export async function setDuck(ctx, id, opt = {}) {
  const hit = findItem(ctx.project, id); if (!hit) return null;
  const cur = hit.item.duck || {}, next = Object.assign({ on: false, db: DUCK_DEFAULT.db, smooth: DUCK_DEFAULT.smooth }, cur, opt);
  if (next.on && (!next.speech || opt.redetect)) next.speech = await timelineSpeech(ctx);
  delete next.redetect;
  // legacy keyframed ducking (Auto music) would duck twice: its keyframes flatten to the level they started at
  const props = { duck: next };
  const kv = hit.item.kf?.volume;
  if (next.on && kv?.length) { props.kf = Object.assign({}, hit.item.kf); delete props.kf.volume; props.volume = Math.max(...kv.map((k) => k[1])); }
  ctx.history.apply(ops.setItems([{ id, props }], opt.on === true ? 'Auto-duck on' : opt.on === false ? 'Auto-duck off' : 'Auto-duck'));
  return next;
}
export const redetectSpeech = (ctx, id) => setDuck(ctx, id, { on: true, redetect: true });

/** replace the song of music item `itemId` with library track `t` ({src,title,duration}) — ONE undo step. Keeps the
 *  volume, mute, fades, ducking and where it sits; pieces of the same song on that track (Auto music splices one song
 *  into several) become one clip over their span. A song shorter than the clip loops to keep the bed under the video. */
export async function replaceSong(ctx, itemId, t) {
  const p = ctx.project, hit = findItem(p, itemId); if (!hit) throw new Error('That music clip is gone');
  const { item, track } = hit, src = t.src || 'library/music/' + t.file;
  const pieces = track.items.filter((x) => x.media === item.media).sort((a, b) => a.t0 - b.t0);
  const t0 = pieces[0].t0, end = Math.max(...pieces.map(itemEnd)), first = pieces[0], last = pieces[pieces.length - 1];
  let m = p.media.find((x) => x.src === src);
  const cmds = [];
  if (!m) { m = await ctx.media.importPath(src); cmds.push(ops.addMedia(m)); }
  const dur = m.dur || t.duration || 0, span = end - t0;
  const legacyDuck = pieces.some((x) => x.kf?.volume?.length > 1);
  const vol = item.kf?.volume?.length ? Math.max(...item.kf.volume.map((k) => k[1])) : (item.volume ?? 1);
  const props = {
    media: m.id, name: `Music · ${t.title || m.name}`, t0, in: 0, volume: vol, muted: !!item.muted,
    fadeIn: first.fadeIn || 0, fadeOut: last.fadeOut || 0, speed: 1, curve: null, reverse: false, freeze: false,
    kf: Object.assign({}, item.kf), duck: item.duck ? clone(item.duck) : null, loop: null,
  };
  delete props.kf.volume;
  let looped = false;
  if (dur >= span - 0.02 && !item.loop) props.out = +Math.min(span, dur).toFixed(3);
  else { props.loop = await loopRegion(ctx, m); props.out = +span.toFixed(3); looped = dur < span - 0.02; }
  // Auto music's ducking was keyframes for the OLD song's pieces: the new song ducks the same way, as Auto-duck
  if (legacyDuck && !props.duck?.on) props.duck = { on: true, db: DUCK_DEFAULT.db, smooth: DUCK_DEFAULT.smooth, speech: await timelineSpeech(ctx) };
  if (pieces.length > 1) cmds.push(ops.removeItems(pieces.filter((x) => x.id !== item.id).map((x) => x.id)));
  cmds.push(ops.setItems([{ id: item.id, props }], 'Replace song'));
  ctx.history.apply(ops.batch(`🎵 ${t.title || 'Replace song'}`, cmds));
  return { id: item.id, looped, pieces: pieces.length };
}

// ── the Inspector section ──
/** one drag of a slider = ONE undo step, however slowly it is dragged (the history coalesces only within 700 ms) */
function holdSlider(ed, el, onStart) {
  const inp = el.querySelector('input[type=range]'); let down = false;
  inp.addEventListener('pointerdown', () => { down = true; onStart?.(); });
  inp.addEventListener('input', () => { if (down) ed.history.lastAt = performance.now(); }, true);
  const up = () => { if (!down) return; down = false; ed.history.seal(); };
  inp.addEventListener('pointerup', up); inp.addEventListener('change', up); inp.addEventListener('pointercancel', up);
  return el;
}
const tag = (el, k) => { el.dataset.music = k; el.querySelector('input')?.setAttribute('data-music-in', k); return el; };

export function musicSection(insp, item, track, m) {
  const ed = insp.ed, ctx = ed.context(), id = item.id, live = () => findItem(ed.project, id)?.item || item;
  const dur = m?.dur || 0, seal = () => ed.history.seal();
  let base = null;
  const vol = holdSlider(ed, tag(slider({ label: 'Volume', min: 0, max: 2, step: 0.01, value: item.kf?.volume?.length ? Math.max(...item.kf.volume.map((k) => k[1])) : (item.volume ?? 1), fmt: (v) => `${pct(v)} ${dbOf(v).replace(' dB', 'dB')}`, onInput: (v) => setVolume(ed, id, v, base || { volume: live().volume ?? 1, kf: live().kf }), onDone: () => { base = null; seal(); } }), 'volume'), () => { const it = live(); base = { volume: it.volume ?? 1, kf: clone(it.kf || {}) }; });
  vol.title = 'The music’s loudness: 100 % = as the song is, 50 % = −6 dB, 200 % = +6 dB. Heard live while the video plays.';
  const mute = h('label.row.check', { dataset: { music: 'mute' } }, h('input', { type: 'checkbox', checked: !!item.muted, on: { change: (e) => { set(ed, id, { muted: e.target.checked }, e.target.checked ? 'Mute music' : 'Unmute music'); seal(); } } }), h('span', 'Mute the music'));
  const fi = holdSlider(ed, tag(slider({ label: 'Fade in', min: 0, max: 10, step: 0.1, value: item.fadeIn || 0, fmt: secs, onInput: (v) => set(ed, id, { fadeIn: v }, 'Music fade in'), onDone: seal }), 'fadein'));
  const fo = holdSlider(ed, tag(slider({ label: 'Fade out', min: 0, max: 10, step: 0.1, value: item.fadeOut || 0, fmt: secs, onInput: (v) => set(ed, id, { fadeOut: v }, 'Music fade out'), onDone: seal }), 'fadeout'));
  const start = holdSlider(ed, tag(slider({ label: 'Start at', min: 0, max: Math.max(0.5, Math.floor(dur - 0.5)), step: 0.5, value: item.in || 0, fmt: mmss, onInput: (v) => setStart(ed, id, v), onDone: seal }), 'start'));
  start.title = 'Start the song at: skip the song’s intro: the music starts from this point of the song (its place on the timeline stays).';
  const loop = h('label.row.check', { dataset: { music: 'loop' }, title: 'On: the song repeats until the video ends. Off: it plays once.' },
    h('input', { type: 'checkbox', checked: !!item.loop, on: { change: async (e) => { await setLoop(ctx, id, e.target.checked); seal(); insp.render(true); } } }), h('span', 'Loop to fill the video'));
  // Auto-duck under voice
  const d = item.duck || {}, on = !!d.on;
  const status = h('small.muted', { dataset: { music: 'duck-status' } }, on ? (d.speech?.length ? `Dips under ${d.speech.length} stretch${d.speech.length === 1 ? '' : 'es'} of speech (${d.speech.reduce((s, [a, b]) => s + b - a, 0).toFixed(1)} s).` : 'No speech found on the video’s sound.') : item.kf?.volume?.length > 2 ? 'Dipped under speech by Add music. Tick to set how much and how smoothly here.' : 'Off: the music stays at its volume while someone talks.');
  const duckOn = h('label.row.check', { dataset: { music: 'duck' }, title: 'The music dips while someone speaks in the video and comes back up in the pauses (the captions’ speech detection).' },
    h('input', { type: 'checkbox', checked: on, on: { change: async (e) => { status.textContent = e.target.checked ? 'Listening for speech…' : ''; try { await setDuck(ctx, id, { on: e.target.checked }); } catch (err) { toast(err.message, 'error'); } seal(); insp.render(true); } } }), h('span', 'Auto-duck under voice'));
  const amount = holdSlider(ed, tag(slider({ label: 'Duck by', min: 3, max: 24, step: 1, value: d.db ?? DUCK_DEFAULT.db, fmt: (v) => `−${v} dB`, onInput: (v) => set(ed, id, { duck: Object.assign({}, live().duck, { db: v }) }, 'Duck amount'), onDone: seal }), 'duck-db'));
  const smooth = holdSlider(ed, tag(slider({ label: 'Smoothness', min: 0, max: 1, step: 0.05, value: d.smooth ?? DUCK_DEFAULT.smooth, fmt: pct, onInput: (v) => set(ed, id, { duck: Object.assign({}, live().duck, { smooth: v }) }, 'Duck smoothness'), onDone: seal }), 'duck-smooth'));
  const again = h('button.btn.small', { style: { alignSelf: 'flex-start' }, dataset: { music: 'duck-again' }, title: 'Listen again (after you moved, cut or replaced the video)', on: { click: async () => { status.textContent = 'Listening for speech…'; await redetectSpeech(ctx, id); seal(); insp.render(true); } } }, '↻ Detect speech again');
  amount.title = 'How far the music dips while someone speaks (−10 dB ≈ half as loud).';
  smooth.title = '0 %: a quick dip right at the words · 100 %: a gentle fade down before and back up after.';
  for (const x of [amount, smooth, again]) x.hidden = !on;
  const replace = h('button.btn.primary', { style: { flex: 'none', padding: '0 12px' }, dataset: { music: 'replace' }, title: 'Opens the Music Library beside the video (double-click the music on the timeline does too)', on: { click: () => import('./music-library.js').then((L) => L.openMusicLibrary(ed, { itemId: id })) } }, 'Replace song…');
  const sec = insp.section('🎵 Music',
    h('div.music-now', { style: { display: 'flex', alignItems: 'center', gap: '10px' } },
      h('div', { style: { display: 'flex', flexDirection: 'column', gap: '2px', minWidth: '0', flex: '1' } }, h('b', { title: item.name || m?.name || '', style: { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, (item.name || m?.name || 'Music').replace(/^Music · /, '')), h('small.muted', { style: { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, `${dur ? 'Song ' + mmss(dur) : ''}${item.loop ? ' · loops' : ''} · ${fmtDur(itemDur(item))} used`)),
      replace),
    vol, mute, fi, fo, start, loop,
    h('div.music-duck', { style: { display: 'flex', flexDirection: 'column', gap: '8px' } }, duckOn, amount, smooth, again, status));
  sec.dataset.music = 'section';
  return sec;
}
