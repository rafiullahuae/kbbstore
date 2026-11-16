// KBB Studio · automation module (R3). The editor imports this file; it registers the "Do it for me" slots
// auto-reel · remove-silences · add-music · suggest-style, and exposes the same features as an API for scripts/tests.
//
//   import * as auto from './auto/index.js';
//   const a = await auto.analyse(ctx, mediaId)                    audio + video analysis (worker), cached per media
//   const s = await auto.suggest(ctx, mediaId)                   top 3 recipes with reasons
//   const r = await auto.autoReel(ctx, {mediaId, recipeId, length})   applies ONE undoable command, returns the plan
//   const list = await auto.silenceReview(ctx, mediaId)         review list [{id, kind, t0, t1, text, checked}]
//   auto.applyCuts(ctx, mediaId, list)                           ONE undoable command
//   await auto.addMusic(ctx, {trackId?, recipeId?})              picks, fits, ducks
//   auto.library()  -> {music, sfx}                              starter + user tracks (user ones analysed on first use)
import { registerModule } from '../core/modules.js';
import { findMedia, projectDuration, itemEnd } from '../core/project.js';
import './layers.js';
import { preloadImages, loadKitFonts } from './layers.js';
import { recipeById, recipesFor, recipesOnlyFor, RECIPES } from './recipes/recipes.js';
import { captionStyleFor } from './recipes/caption-styles.js';
import { registerPrepare } from '../core/render.js';
import { setStyle as setCaptionStyle } from '../captions/commands.js';
import { styleForWorkspace } from '../captions/styles.js';
import { resolveRecipe } from './recipes/engine.js';
import { rankTracks, fitTrack, wantFor } from './lib/music.js';
import { planEffects } from './lib/effects.js';
import { autoReelCommand, removeCutsCommand, addMusicCommand, speechOnTimeline, effectsCommands, kitForItems } from './commands.js';
import { ops } from '../core/history.js';

// ── worker ──
let W = null, seq = 0; const pending = new Map();
function worker() {
  if (W) return W;
  W = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
  W.onmessage = (e) => { const p = pending.get(e.data.id); if (!p) return; pending.delete(e.data.id); e.data.error ? p.rej(new Error(e.data.error)) : p.res(e.data.result); };
  W.onerror = (e) => { for (const p of pending.values()) p.rej(new Error(e.message || 'automation worker failed')); pending.clear(); W = null; };
  return W;
}
export function call(op, args, transfer = []) { return new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); worker().postMessage({ id, op, args }, transfer); }); }

// ── library ──
const getJSON = async (ctx, path) => { try { if (ctx?.engine?.available) return await ctx.engine.readFile(path, 'json'); } catch {} const r = await fetch('/' + path); if (!r.ok) throw new Error(path + ' ' + r.status); return r.json(); };
let LIB = null;
export async function library(ctx) {
  if (LIB) return LIB;
  const [music, sfx] = await Promise.all([getJSON(ctx, 'library/music/library.json'), getJSON(ctx, 'library/sfx/library.json')]);
  const tracks = music.tracks.map(t => ({ ...t, src: 'library/music/' + t.file }));
  // the owner's own tracks: library/music/user/*  (analysed once, cached in library/music/user/library.json)
  try {
    const list = ctx?.engine?.available ? await ctx.engine.listDir('library/music/user') : [];
    let cache = {};
    if (list.length && await ctx.engine.exists('library/music/user/library.json')) { try { cache = (await ctx.engine.readFile('library/music/user/library.json', 'json')).tracks || {}; } catch {} }
    let changed = false;
    for (const f of list) {
      if (f.dir || !/\.(m4a|mp3|wav|aac|ogg|flac)$/i.test(f.name)) continue;
      let e = cache[f.name];
      if (!e || e.size !== f.size) { e = await analyseUserTrack(ctx, 'library/music/user/' + f.name, f); cache[f.name] = e; changed = true; }
      tracks.push(e);
    }
    if (changed) await ctx.engine.writeFile('library/music/user/library.json', { v: 1, tracks: cache });
  } catch (e) { console.warn('[auto] user music skipped:', e.message); }
  return (LIB = { music: tracks, sfx: sfx.sfx });
}
async function analyseUserTrack(ctx, src, f) {
  const buf = await (await fetch('/' + src)).arrayBuffer();
  const ac = new OfflineAudioContext(1, 22050, 22050);
  const ab = await ac.decodeAudioData(buf);
  const sr = 22050, len = Math.floor(ab.duration * sr), oc = new OfflineAudioContext(1, len, sr), s = oc.createBufferSource();
  s.buffer = ab; s.connect(oc.destination); s.start(); const mono = (await oc.startRendering()).getChannelData(0);
  const g = await call('beatGrid', { pcm: mono, sr });
  const lo = await call('loudness', { channels: [mono], sr });
  const bpm = g.bpm, energy = bpm > 112 ? 'high' : bpm < 85 ? 'low' : 'mid';
  return { id: 'user-' + f.name.replace(/\W+/g, '-').toLowerCase(), file: f.name, src, title: f.name.replace(/\.[^.]+$/, ''), mood: 'user', tags: [], energy, bpm, duration: +ab.duration.toFixed(3), beatsPerBar: 4, firstBeat: g.beats[0] || 0, barSec: +(240 / bpm).toFixed(4), beats: g.beats, lufs: +lo.integrated.toFixed(1), source: 'user', size: f.size };
}

// ── analysis (cached per media) ──
const cache = new Map();
const FRAME_W = 96;
export async function analyse(ctx, mediaId, { faces = true, onProgress } = {}) {
  const key = mediaId + ':' + (ctx.project.captions?.words?.length || 0);
  if (cache.has(key)) return cache.get(key);
  const m = findMedia(ctx.project, mediaId); if (!m) throw new Error('media not found');
  const prog = (l, x) => { ctx.progress?.(l, x); onProgress?.(l, x); };
  const words = ctx.project.captions?.words || [];
  prog('Listening…', 0.05);
  let audio = null;
  if (m.hasAudio !== false && m.kind !== 'image') {
    const a = await ctx.media.decodeAudio(mediaId);
    if (a?.mono16k) { const pcm = a.mono16k.slice(); audio = await call('analyseAudio', { pcm, sr: 16000, words }, [pcm.buffer]); }
  }
  let video = null, subjects = null;
  if (m.kind === 'video') {
    const dur = m.dur || 0, fps = dur <= 120 ? 3 : dur <= 600 ? 1 : 0.5, n = Math.max(2, Math.floor(dur * fps));
    const frames = [], bitmaps = [];
    const oc = new OffscreenCanvas(FRAME_W, Math.max(2, Math.round(FRAME_W * (m.h || 9) / (m.w || 16)))), c2 = oc.getContext('2d', { willReadFrequently: true });
    for (let i = 0; i < n; i++) {
      const t = Math.min(dur - 0.05, i / fps);
      const bmp = await ctx.media.frameAt(mediaId, t, 256);
      c2.drawImage(bmp, 0, 0, oc.width, oc.height);
      const d = c2.getImageData(0, 0, oc.width, oc.height);
      frames.push({ t, w: oc.width, h: oc.height, data: d.data });
      if (faces && i % 2 === 0) bitmaps.push({ t, bitmap: bmp }); else bmp.close?.();
      if (i % 6 === 0) prog('Watching…', 0.1 + 0.5 * i / n);
    }
    video = await call('analyseFrames', { frames, opt: { duration: dur } }, frames.map(f => f.data.buffer));
    subjects = video.subjects;
    if (faces && bitmaps.length) {
      try {
        const { trackFaces } = await import('./faces.js');
        const fs = await trackFaces(bitmaps, x => prog('Finding faces…', 0.6 + 0.3 * x));
        if (fs.some(f => f.x != null)) { subjects = fs; const set = new Map(fs.map(f => [f.t, f])); for (const fr of video.frames) { const near = fs.reduce((a, b) => Math.abs(b.t - fr.t) < Math.abs(a.t - fr.t) ? b : a, fs[0]); fr.face = near && near.x != null; } video.faceShare = fs.filter(f => f.x != null).length / fs.length; }
      } catch (e) { console.warn('[auto] face model unavailable, using subject centroid:', e.message); }
      for (const b of bitmaps) b.bitmap.close?.();
    }
  }
  prog('Done', 1); ctx.progress?.(null);
  const res = { mediaId, duration: m.dur, audio, video, subjects, words };
  cache.set(key, res); return res;
}

// ── features ──
export async function suggest(ctx, mediaId) {
  const a = mediaId ? await analyse(ctx, mediaId) : null;
  return call('suggest', { input: { words: ctx.project.captions?.words || [], video: a?.video ? { stats: a.video.stats } : null, faces: a?.video?.faceShare ?? null, workspace: ctx.workspace, limit: 3 } });
}

const kitCache = new Map();
export async function brandKit(ctx) {
  const ref = ctx.workspace?.brandRef || ctx.workspace?.id || ctx.project?.workspace || 'kbb';
  if (kitCache.has(ref)) return kitCache.get(ref);
  let kit = null; try { kit = await getJSON(ctx, `brands/${ref}/kit.json`); } catch {}
  if (kit) { await loadKitFonts(kit, `brands/${ref}/`); kit.base = `brands/${ref}/`; }
  // the workspace's own edits win over the seed kit
  const ws = ctx.workspace || {};
  kit = Object.assign({}, kit || {}, { colors: Object.assign({}, kit?.colors, ws.colors), contactLine: [ws.contact, kit?.contactLine].find(x => x && !/^<edit/.test(x)) || '', endCard: Object.assign({}, kit?.endCard, ws.endCard) });
  kitCache.set(ref, kit); return kit;
}

export async function autoReel(ctx, { mediaId, recipeId, length = 30, music = true, effects = true, lang } = {}) {
  mediaId ||= firstVideo(ctx.project); if (!mediaId) throw new Error('Add a video first');
  const a = await analyse(ctx, mediaId);
  const recipe = recipeById(recipeId || ctx.project.recipe) || recipesFor(ctx.workspace)[0];
  const kit = await brandKit(ctx), lib = await library(ctx);
  const R = resolveRecipe(recipe, { length, lang: lang || ctx.project.captions?.lang, kit });
  // music first: its beat grid decides where the cuts land
  let mus = null;
  if (music) { const pick = rankTracks(lib.music, { ...wantFor(recipe, a), duration: R.length })[0]; if (pick) mus = { track: pick.track, fit: fitTrack(pick.track, R.length), srcBase: '' }; }
  const plan = await call('planAutoReel', { ctx: { duration: a.duration, words: a.words, energy: a.audio?.energy, frames: a.video?.frames, cuts: a.video?.cuts, keywords: [...(recipe.keywords?.en || []), ...(recipe.keywords?.ar || [])] }, opt: { target: R.length, endCard: R.endCard ? { min: 1.5, max: 3.5 } : null, beats: mus?.fit.beats } });
  if (!plan.segments.length) throw new Error('Nothing to cut from: the clip has no speech or usable shots');
  let crop = null;
  const m = findMedia(ctx.project, mediaId), cv = ctx.project.canvas;
  if (m?.w && Math.abs(m.w / m.h - cv.w / cv.h) > 0.05 && a.subjects) crop = await call('reframe', { subjects: a.subjects, src: { w: m.w, h: m.h }, aspect: { w: cv.w, h: cv.h }, opt: { duration: a.duration } });
  const cuts = plan.segments.slice(1).map(s => s.t0).concat(plan.endCard ? [plan.endCard.t0] : []);
  const words = remapWords(a.words, plan.segments);
  const ev = effects ? planEffects({ duration: plan.total, cuts, words, beats: mus?.fit.beats, recipe, canvas: cv, endCard: plan.endCard }) : null;
  const payments = R.endCard?.payments ? (kit.endCard?.payments || []).map(f => kit.base + f) : [];
  await preloadImages(payments);
  const speech = (a.audio?.speech || []).flatMap(([s0, s1]) => plan.segments.map(s => [Math.max(s0, s.in), Math.min(s1, s.out), s]).filter(([x, y]) => y > x).map(([x, y, s]) => [s.t0 + x - s.in, s.t0 + y - s.in]));
  const cmd = autoReelCommand(ctx.project, { mediaId, plan, reframe: crop, music: mus ? { ...mus, srcBase: mus.track.source === 'user' ? 'library/music/user/' : 'library/music/' } : null, speechTimeline: speech, effects: ev, kit, recipe,
    endCardText: R.endCard ? { title: R.endCard.title || kit.fullName, sub: R.endCard.sub, cta: R.endCard.cta, contact: R.endCard.contact, payments } : null, sfxLib: lib.sfx });
  // the recipe's caption style (mapped to the captions module's ids) in the company's colours, re-flowed for the cut
  const style = captionStyleCommand(ctx, recipe, lang || ctx.project.captions?.lang);
  ctx.history.apply(ops.batch('Auto reel', [cmd, ctx.project.recipe === recipe.id ? null : ops.setProject({ recipe: recipe.id }, 'Recipe'), style]));
  return { plan, recipe: recipe.id, music: mus?.track.id || null, effects: ev, reframed: !!crop, captionStyle: style ? captionStyleFor(recipe, (lang || ctx.project.captions?.lang) === 'ar' ? 'ar' : 'en') : null };
}
/** setStyle command for a recipe's caption style (null when there are no captions yet) */
export function captionStyleCommand(ctx, recipe, lang) {
  if (!ctx.project.captions?.words?.length || !recipe) return null;
  const id = captionStyleFor(recipe, lang === 'ar' ? 'ar' : 'en');
  const ws = ctx.workspace || {};
  return setCaptionStyle(styleForWorkspace({ ...ws, captions: { ...(ws.captions || {}), style: id } }), true, ctx.project.canvas);
}
/** apply a recipe: remember it on the project and dress the captions in its style — ONE undo step */
export function useRecipe(ctx, recipeId) {
  const recipe = recipeById(recipeId); if (!recipe) throw new Error('No such recipe');
  ctx.history.apply(ops.batch('Recipe · ' + recipe.name, [ops.setProject({ recipe: recipe.id }, 'Recipe'), captionStyleCommand(ctx, recipe, ctx.project.captions?.lang)]));
  return recipe;
}
const remapWords = (words, segs) => { const out = []; for (const s of segs) for (const w of words) if (w.t0 >= s.in - 0.02 && w.t1 <= s.out + 0.02) out.push({ ...w, t0: s.t0 + w.t0 - s.in, t1: s.t0 + w.t1 - s.in }); return out; };
const firstVideo = p => { for (const tr of p.tracks) if (tr.kind === 'video') for (const it of tr.items) if (it.media) return it.media; return p.media.find(m => m.kind === 'video')?.id; };

export async function silenceReview(ctx, mediaId, opt = {}) {
  mediaId ||= firstVideo(ctx.project); if (!mediaId) throw new Error('Add a video first');
  const a = await analyse(ctx, mediaId, { faces: false });
  if (!a.audio) throw new Error('This clip has no sound');
  return call('planCuts', { analysis: { duration: a.duration, silences: a.audio.silences, fillers: a.audio.fillers }, opt });
}
export function applyCuts(ctx, mediaId, list) {
  mediaId ||= firstVideo(ctx.project);
  const cmd = removeCutsCommand(ctx.project, mediaId, list); if (!cmd) throw new Error('That clip is not on the timeline');
  ctx.history.apply(cmd); return cmd.removed;
}

export async function addMusic(ctx, { trackId, recipeId, volume = 0.8 } = {}) {
  const lib = await library(ctx), recipe = recipeById(recipeId || ctx.project.recipe) || recipesFor(ctx.workspace)[0];
  const L = projectDuration(ctx.project) || 30;
  const mediaId = firstVideo(ctx.project); const a = mediaId ? await analyse(ctx, mediaId, { faces: false }) : null;
  const track = trackId ? lib.music.find(t => t.id === trackId) : rankTracks(lib.music, { ...wantFor(recipe, a || {}), duration: L })[0]?.track;
  if (!track) throw new Error('No music in the library');
  const fit = fitTrack(track, L);
  ctx.history.apply(addMusicCommand(ctx.project, { track, fit, srcBase: track.source === 'user' ? 'library/music/user/' : 'library/music/', volume }, a?.audio ? speechOnTimeline(ctx.project, mediaId, a.audio.speech) : []));
  return { track: track.id, items: fit.items.length, total: fit.total };
}

export async function applyEffects(ctx, { recipeId } = {}) {
  const recipe = recipeById(recipeId || ctx.project.recipe) || recipesFor(ctx.workspace)[0];
  const kit = await brandKit(ctx), lib = await library(ctx), p = ctx.project;
  const vt = p.tracks.find(t => t.kind === 'video'), clips = vt.items.slice().sort((a, b) => a.t0 - b.t0);
  // an end card already on the timeline (Auto reel) ends the reel: no logo outro on top of it, no cut into it
  const endItem = p.tracks.flatMap(t => t.items).find(i => i.type === 'auto.endcard');
  const endCard = endItem ? { t0: endItem.t0, dur: itemEnd(endItem) - endItem.t0 } : null;
  const cuts = clips.slice(1).map(c => c.t0).concat(endCard ? [endCard.t0] : []), dur = projectDuration(p);
  const ev = planEffects({ duration: dur, cuts, words: p.captions?.words || [], recipe, canvas: p.canvas, endCard });
  // a second press REPLACES the earlier automatic effects (never the owner's own items, never the end card or music)
  const FX = new Set(['auto.transition', 'auto.pricetag', 'auto.logo', 'sticker']);
  const old = p.tracks.flatMap(t => t.items.filter(i => i.auto === 'auto' && (FX.has(i.type) || t.kind === 'sfx')).map(i => i.id));
  const punch = clips.map(c => { const d = (c.out - c.in) / (c.speed || 1), mine = ev.punches.filter(x => x.t0 >= c.t0 && x.t0 < c.t0 + d).map(x => ({ type: 'auto.punch', t0: x.t0, dur: x.dur, amount: x.amount }));
    const keep = (c.fx || []).filter(f => f.type !== 'auto.punch'); return (c.fx || []).some(f => f.type === 'auto.punch') || mine.length ? { id: c.id, props: { fx: [...keep, ...mine] } } : null; }).filter(Boolean);
  ctx.history.apply(ops.batch('Auto effects', [old.length ? ops.removeItems(old) : null, punch.length ? ops.setItems(punch, 'Punch-ins') : null, ...effectsCommands(p, ev, { kitI: kitForItems(kit), sfxLib: lib.sfx })]));
  return ev;
}

// ── "Do it for me" slots ──
const el = (tag, props = {}, ...kids) => { const e = Object.assign(document.createElement(tag), props); for (const k of kids) e.append(k); return e; };
const css = 'font:13px system-ui;display:grid;gap:8px';
const btn = (label, on) => el('button', { textContent: label, onclick: on, style: 'padding:8px 12px;border-radius:8px;border:0;background:#2f7bff;color:#fff;font-weight:600;cursor:pointer' });

registerModule({
  id: 'auto-reel', label: 'Auto reel', icon: '✨',
  run: async (ctx) => { const r = await autoReel(ctx, { length: 30 }); return `Auto reel: ${r.plan.segments.length} moments, ${r.plan.total.toFixed(1)} s (${r.recipe})`; },
  panel: (root, ctx) => {
    root.replaceChildren(); const box = el('div', { style: css });
    const len = el('select', {}, ...[15, 30, 60].map(v => el('option', { value: v, textContent: v + ' s', selected: v === 30 })));
    const rec = el('select', {}, ...recipesFor(ctx.workspace).map(r => el('option', { value: r.id, textContent: r.name, selected: r.id === ctx.project.recipe })));
    const out = el('div');
    box.append(el('label', { textContent: 'Length ' }, len), el('label', { textContent: 'Recipe ' }, rec),
      btn('Make my reel', async () => { out.textContent = 'Working…'; try { const r = await autoReel(ctx, { length: +len.value, recipeId: rec.value }); out.replaceChildren(...r.plan.segments.map(s => el('div', { textContent: `${s.role.toUpperCase()} ${s.t0.toFixed(1)}s · “${(s.text || '').slice(0, 60)}”${s.onBeat ? ' ♪' : ''}` })), el('div', { textContent: `Total ${r.plan.total.toFixed(2)} s · music ${r.music || 'none'} · Ctrl+Z undoes it all` })); } catch (e) { out.textContent = e.message; } }), out);
    root.append(box);
  },
});
registerModule({
  id: 'remove-silences', label: 'Remove silences', icon: '✂️',
  run: async (ctx) => { const list = await silenceReview(ctx); const s = applyCuts(ctx, null, list); return `Removed ${s.toFixed(1)} s (${list.filter(c => c.checked).length} cuts)`; },
  panel: async (root, ctx) => {
    root.replaceChildren(el('div', { textContent: 'Listening…' }));
    let list; try { list = await silenceReview(ctx); } catch (e) { root.replaceChildren(el('div', { textContent: e.message })); return; }
    const rows = list.map(c => { const cb = el('input', { type: 'checkbox', checked: c.checked, onchange: () => { c.checked = cb.checked; } }); return el('label', { style: 'display:flex;gap:6px;align-items:center;cursor:pointer', onclick: (e) => { if (e.target !== cb) ctx.seek?.(c.t0); } }, cb, el('span', { textContent: `${c.t0.toFixed(2)}–${c.t1.toFixed(2)} s · ${c.kind === 'silence' ? 'pause' : c.kind === 'filler-soft' ? 'maybe filler' : 'filler'} · ${c.text}` })); });
    root.replaceChildren(el('div', { style: css }, el('b', { textContent: `${list.length} cuts found — untick any you want to keep` }), ...rows, btn('Cut the ticked ones', () => { const s = applyCuts(ctx, null, list); ctx.toast?.(`Removed ${s.toFixed(1)} s`); })));
  },
});
registerModule({
  id: 'add-music', label: 'Add music', icon: '🎵',
  run: async (ctx) => { const r = await addMusic(ctx); return `Music: ${r.track} (${r.total.toFixed(1)} s, ducked under speech)`; },
  panel: async (root, ctx) => {
    const lib = await library(ctx); const recipe = recipeById(ctx.project.recipe) || recipesFor(ctx.workspace)[0];
    const ranked = rankTracks(lib.music, { ...wantFor(recipe), duration: projectDuration(ctx.project) || 30 });
    let audio = null;
    root.replaceChildren(el('div', { style: css }, ...ranked.map(({ track, reasons }) => el('div', { style: 'display:flex;gap:6px;align-items:center' },
      el('button', { textContent: '▶', onclick: () => { audio?.pause(); audio = new Audio('/' + (track.src || 'library/music/' + track.file)); audio.play(); } }),
      el('span', { style: 'flex:1', textContent: `${track.title} · ${track.mood} · ${track.bpm} bpm · ${Math.round(track.duration)} s${reasons.length ? ' — ' + reasons.join(', ') : ''}` }),
      btn('Use', async () => { audio?.pause(); await addMusic(ctx, { trackId: track.id }); ctx.toast?.('Music added'); })))));
  },
});
registerModule({
  id: 'suggest-style', label: 'Suggest style', icon: '🎨',
  run: async (ctx) => { const s = await suggest(ctx, firstVideo(ctx.project)); ctx.openPanel?.('suggest-style'); return s.length ? `Try: ${s.map(x => x.name).join(' · ')}` : 'No suggestion yet'; },
  panel: async (root, ctx) => {
    root.replaceChildren(el('div', { textContent: 'Looking…' }));
    const s = await suggest(ctx, firstVideo(ctx.project));
    root.replaceChildren(el('div', { style: css }, ...s.map(x => el('div', { style: 'border:1px solid #8884;border-radius:8px;padding:8px' }, el('b', { textContent: x.name }), el('div', { textContent: x.reasons.join(' · ') || 'a good general fit' }),
      btn('Use this recipe', () => { useRecipe(ctx, x.id); ctx.toast?.(`Recipe: ${x.name}`); })))));
  },
});
registerModule({ id: 'auto-effects', label: 'Auto effects', icon: '💥', run: async (ctx) => { const ev = await applyEffects(ctx); return `Effects: ${ev.transitions.length} transitions, ${ev.punches.length} punch-ins, ${ev.stickers.length} emoji`; } });

// before the export (and when a project opens): decode the end card's badges and load the brand kit's fonts, so the
// synchronous layers draw a reloaded project exactly like the one that was saved
registerPrepare('auto', async (project) => {
  const imgs = [];
  for (const tr of project.tracks) for (const it of tr.items) if (it.type === 'auto.endcard') imgs.push(...(it.style?.payments || []));
  await preloadImages(imgs);
  const ref = project.brand || project.workspace;
  if (ref && /^[a-z0-9-]{1,40}$/.test(ref) && project.tracks.some(t => t.items.some(i => /^auto\./.test(i.type)))) {
    if (!fontsLoaded.has(ref)) { fontsLoaded.add(ref); try { const kit = await getJSON(null, `brands/${ref}/kit.json`); await loadKitFonts(kit, `brands/${ref}/`); } catch {} }
  }
});
const fontsLoaded = new Set();

export { RECIPES, recipesFor, recipesOnlyFor, recipeById, captionStyleFor };
