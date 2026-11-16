// KBB Studio · VIRAL ENGINE (Lane V). The editor imports this file; it registers the "Do it for me" buttons
// (🪝 Hook builder · 🎣 Add a hook · ⚡ Tighten · 🔁 Loop · 📣 CTA), the 🔥 Viral tab in the right column, the retention
// heat strip under the timeline ruler and two export checks (platform safe zones, risky claims).
// Every feature is ONE undoable step (ops.mutate) and the same functions are the API for scripts and tools/v-check.cjs:
//
//   const V = await import('/app/viral/index.js');
//   V.hookLines(ctx) · V.buildHook(ctx, {line}) · V.firstFrameCheck(ctx) · V.flashForward(ctx)
//   V.addNaturalHook(ctx, {hookId?, soundId?}) · V.hookLibrary(ctx)
//   V.pacing(ctx) · V.fixDeadZones(ctx) · V.tighten(ctx, opt) · V.smartZooms(ctx) · V.upperThird(ctx)
//   V.safeCheck(ctx, platform) · V.autoNudge(ctx, platform) · V.loopEnding(ctx) · V.addCta(ctx, {kind}) · V.chapters(ctx)
//   V.variations(ctx) · V.claimCheck(ctx) · V.fixClaims(ctx, findings)
import { registerModule, registerTimelineStrip, registerExportCheck } from '../core/modules.js';
import { registerPrepare, renderFrame, activeItems, drawItem, boxAt } from '../core/render.js';
import { ops } from '../core/history.js';
import { MediaStore } from '../core/media.js';
import { exportMp4 } from '../core/export.js';
import { projectDir, exportsDir } from '../core/config.js';
import { findMedia, srcTime, itemEnd, projectDuration, clone, findItem } from '../core/project.js';
import { bus } from '../core/bus.js';
import { resolveStyle } from '../captions/styles.js';
import * as auto from '../auto/index.js';
import { emphasis, emojiFor } from '../auto/lib/effects.js';
import { cropToItemKf } from '../auto/lib/video.js';
import { loadKitFonts } from '../auto/layers.js';
import { hookLines as makeHookLines, industryOf, isArabic, topicOf } from './lib/hooklines.js';
import { scorePacing } from './lib/pacing.js';
import { planTighten, removedBy } from './lib/tighten.js';
import { uiRects, hits, nudge as nudgeBox, PLATFORMS } from './lib/safezones.js';
import { detectChapters } from './lib/chapters.js';
import { scanClaims } from './lib/claims.js';
import { VIDEO_HOOKS, pickPair, soundsFor, TOSS } from './lib/naturalhooks.js';
import { mainClips, clipAt } from './lib/timeline.js';
import * as E from './commands.js';
import { zoneView } from './layers.js';

const r3 = (x) => +(+x).toFixed(3);
const apply = (ctx, label, fn) => { let out; ctx.history.apply(ops.mutate(label, (p) => { out = fn(p); })); return out; };
export const firstVideo = (p) => mainClips(p).find((c) => findMedia(p, c.media)?.kind === 'video')?.media || p.media.find((m) => m.kind === 'video')?.id || null;
const langOf = (ctx) => (ctx.project.captions?.lang === 'ar' || isArabic((ctx.project.captions?.words || []).slice(0, 30).map((w) => w.w).join(' ')) ? 'ar' : 'en');
const getJSON = async (ctx, path) => { try { if (ctx?.engine?.available) return await ctx.engine.readFile(path, 'json'); } catch {} const r = await fetch('/' + path); if (!r.ok) throw new Error(path + ' ' + r.status); return r.json(); };

// ── shared inputs ──
let HOOKLIB = null;
/** the sound hooks (library/sfx/hooks/library.json) + the video hooks */
export async function hookLibrary(ctx) {
  if (!HOOKLIB) HOOKLIB = (await getJSON(ctx, 'library/sfx/hooks/library.json')).sfx;
  return { sounds: HOOKLIB, video: VIDEO_HOOKS };
}
const soundById = async (ctx, id) => (await hookLibrary(ctx)).sounds.find((s) => s.id === id) || null;
const sfxById = async (ctx, id) => (await auto.library(ctx)).sfx.find((s) => s.id === id) || null;
/** audio + video analysis of the main video (app/auto, cached per media) */
export async function analysis(ctx, { faces = true } = {}) {
  const id = firstVideo(ctx.project); if (!id) throw new Error('Add a video first');
  return auto.analyse(ctx, id, { faces });
}
const kitOf = (ctx) => auto.brandKit(ctx);
/** timeline t -> source t of the main clip there */
const srcAt = (p, t) => { const c = clipAt(p, t); return c ? { clip: c, t: srcTime(c, t) } : null; };
/** the face (or subject) at timeline t, as fractions of the source frame */
export function faceAt(p, a, t) {
  const s = srcAt(p, t); const subs = a?.subjects || [];
  if (!s || !subs.length) return { x: 0.5, y: 0.4 };
  let best = null; for (const x of subs) if (x && x.x != null && (!best || Math.abs(x.t - s.t) < Math.abs(best.t - s.t))) best = x;
  return best ? { x: r3(best.x), y: r3(best.y) } : { x: 0.5, y: 0.4 };
}
/** speech ranges of the main media mapped onto the timeline */
function speechTimeline(p, a) {
  const id = firstVideo(p), out = [];
  for (const c of mainClips(p)) if (c.media === id) for (const [x, y] of a?.audio?.speech || []) {
    const s = Math.max(x, c.in), e = Math.min(y, c.out); if (e > s) out.push([c.t0 + (s - c.in) / (c.speed || 1), c.t0 + (e - c.in) / (c.speed || 1)]);
  }
  return out.sort((u, v) => u[0] - v[0]);
}
/** mean dB of the main media's energy curve between timeline t0..t1 */
function dbAt(p, a, t0, t1) {
  const en = a?.audio?.energy; if (!en) return null;
  const s0 = srcAt(p, t0), s1 = srcAt(p, Math.max(t0, t1 - 1e-3)); if (!s0 || !s1) return null;
  const i0 = Math.max(0, Math.floor(s0.t / en.hop)), i1 = Math.min(en.db.length, Math.ceil(s1.t / en.hop));
  if (i1 <= i0) return null; let s = 0; for (let i = i0; i < i1; i++) s += en.db[i]; return s / (i1 - i0);
}
/** the music's beats on the timeline (library tracks carry their beat grid) */
async function beatsOnTimeline(ctx) {
  const p = ctx.project, lib = (await auto.library(ctx)).music, out = [];
  for (const tr of p.tracks) if (tr.kind === 'music' && !tr.muted) for (const it of tr.items) {
    const m = findMedia(p, it.media); const track = m && lib.find((x) => m.src.endsWith('/' + x.file));
    for (const b of track?.beats || []) if (b >= it.in && b <= it.out) out.push(r3(it.t0 + (b - it.in) / (it.speed || 1)));
  }
  return out.sort((x, y) => x - y);
}

// ── stills: a frame of the reel rendered through the export path, saved as a picture in the project ──
export async function renderStill(ctx, p, t, { width = p.canvas.w, filter = null } = {}) {
  const scale = width / p.canvas.w, W = Math.round(p.canvas.w * scale), H = Math.round(p.canvas.h * scale);
  const can = document.createElement('canvas'); can.width = W; can.height = H;
  const c2 = can.getContext('2d', { willReadFrequently: true });
  const frames = new Map();
  for (const { track, item } of activeItems(p, t)) {
    if (!item.media || (filter && !filter(track, item))) continue;
    const m = findMedia(p, item.media); if (!m || m.kind === 'audio') continue;
    if (m.kind === 'image') { const el = ctx.media.element(m.id); if (el && !el.complete) await el.decode().catch(() => {}); frames.set(item.id, el); }
    else frames.set(item.id, await ctx.media.frameAt(m.id, srcTime(item, t), Math.max(64, Math.round((m.w || p.canvas.w) * Math.min(1, scale * 1.6)))));
  }
  const proj = filter ? { ...p, tracks: p.tracks.map((tr) => ({ ...tr, items: tr.items.filter((i) => filter(tr, i)) })) } : p;
  renderFrame(c2, proj, t, { scale, source: (item) => frames.get(item.id) || null, preview: false });
  for (const f of frames.values()) f?.close?.();
  return can;
}
async function saveStill(ctx, can, name) {
  const blob = await new Promise((res) => can.toBlob(res, 'image/jpeg', 0.95));
  const file = new File([blob], name + '.jpg', { type: 'image/jpeg' });
  return ctx.media.importFile(file, projectDir(ctx.project.workspace, ctx.project.id));
}
function frameStats(can) {
  const c2 = can.getContext('2d'), { width: w, height: h } = can, d = c2.getImageData(0, 0, w, h).data;
  let sy = 0, sy2 = 0, sat = 0; const Y = new Float32Array(w * h);
  for (let i = 0, j = 0; i < d.length; i += 4, j++) { const r = d[i], g = d[i + 1], b = d[i + 2], y = 0.299 * r + 0.587 * g + 0.114 * b; Y[j] = y; sy += y; sy2 += y * y; const mx = Math.max(r, g, b), mn = Math.min(r, g, b); sat += mx ? (mx - mn) / mx : 0; }
  const n = w * h, mean = sy / n, sd = Math.sqrt(Math.max(0, sy2 / n - mean * mean));
  let lap = 0; for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) { const k = y * w + x; lap += Math.abs(4 * Y[k] - Y[k - 1] - Y[k + 1] - Y[k - w] - Y[k + w]); }
  return { luma: r3(mean), contrast: r3(sd), sat: r3(sat / n), sharp: r3(lap / ((w - 2) * (h - 2))) };
}

// ═══ 1. HOOK BUILDER ═══
export function hookLines(ctx, { lang, seed = 0 } = {}) {
  return makeHookLines({ words: ctx.project.captions?.words || [], workspace: ctx.workspace, lang: lang || langOf(ctx), seed });
}
const HIT = { beauty: 'hook-shutter', visa: 'hook-stamp-thud', generic: 'hook-hit' };
/** first-frame check: the opening frame must not be black, dark, blurred or dull. -> {stats, issues, best, fix} */
export async function firstFrameCheck(ctx) {
  const p = ctx.project, T = Math.min(2, projectDuration(p) - 0.05), firstWord = p.captions?.words?.[0]?.t0 ?? T;
  const samples = [];
  for (let t = 0; t <= T + 1e-6; t += 0.1) samples.push({ t: r3(t), ...frameStats(await renderStill(ctx, p, t, { width: 180, filter: (tr) => tr.kind === 'video' })) });
  const maxSharp = Math.max(...samples.map((s) => s.sharp), 1e-6);
  const issuesOf = (s) => [s.luma < 22 && 'black', s.luma >= 22 && s.luma < 48 && 'dark', s.sharp < Math.max(1.2, maxSharp * 0.45) && 'blurry', s.sat < 0.1 && s.contrast < 22 && 'dull'].filter(Boolean);
  const scoreOf = (s) => (s.luma < 22 ? 0 : Math.min(1, s.luma / 90)) * 0.4 + Math.min(1, s.sharp / maxSharp) * 0.35 + Math.min(1, s.sat / 0.35) * 0.15 + Math.min(1, s.contrast / 50) * 0.1;
  const first = samples[0], issues = issuesOf(first);
  // the best opening frame before the first word (cutting into a word is never a fix)
  const usable = samples.filter((s) => s.t <= Math.max(0, firstWord - 0.05));
  const best = usable.reduce((a, b) => (scoreOf(b) > scoreOf(a) + 1e-9 && !issuesOf(b).some((x) => x === 'black') ? b : a), first);
  // ...or, when it runs under the first words, the first clear frame shown over it (the sound stays as it is)
  const later = samples.filter((s) => s.t > best.t && !issuesOf(s).includes('black') && !issuesOf(s).includes('dark')).sort((a, b) => scoreOf(b) - scoreOf(a) || a.t - b.t)[0];
  let fix = null;
  if (issues.length) {
    if (best.t > 0 && issuesOf(best).length < issues.length && !issuesOf(best).includes('black')) fix = { trimTo: best.t };
    else if ((issues.includes('black') || issues.includes('blurry')) && later) fix = { cover: later.t };
    else if (issues.includes('dark') || issues.includes('dull')) fix = { vivid: Math.min(2, projectDuration(p)) };
  }
  return { stats: first, issues, best: { t: best.t, score: r3(scoreOf(best)), issues: issuesOf(best) }, fix, samples: samples.length };
}
/** the one-click hook: the best line as animated text 0–2 s, a punch-in on the face, a sound hit, the first frame fixed */
export async function buildHook(ctx, { line, punch = true, sound = true, fixFrame = true, lang } = {}) {
  const lines = hookLines(ctx, { lang });
  line = line || lines.slice().sort((a, b) => b.score - a.score)[0];
  const [kit, a, check] = await Promise.all([kitOf(ctx), analysis(ctx).catch(() => null), fixFrame ? firstFrameCheck(ctx) : null]);
  const snd = sound ? await soundById(ctx, HIT[industryOf(ctx.workspace)]) : null;
  if (check?.fix?.cover != null) check.fix.cover = { until: check.fix.cover, still: await saveStill(ctx, await renderStill(ctx, ctx.project, check.fix.cover, { filter: (tr) => tr.kind === 'video' }), 'first-good-frame') };
  apply(ctx, 'Hook · ' + line.text, (p) => {
    if (check?.fix) E.editFirstFrame(p, check.fix);
    E.editHookText(p, { line, kit, punch, face: faceAt(p, a, 0.3), sound: snd });
  });
  return { line, check, lines };
}
/** flash-forward opener: the reel's best moment (≤ 2 s) plays first with "wait for it", then from the top */
export async function flashForward(ctx, { maxLen = 2 } = {}) {
  const p = ctx.project, a = await analysis(ctx, { faces: false });
  if (p.meta.viral?.flashForward) throw new Error('This reel already opens with a flash-forward (undo it first)');
  const words = p.captions?.words || [];
  // score sentences with app/auto's highlight scorer, on the timeline
  const units = words.length ? await auto.call('scoreUnits', { ctx: { duration: projectDuration(p), words, energy: null, frames: null, keywords: [] } }) : [];
  for (const u of units) { const db = dbAt(p, a, u.t0, u.t1); u.score += db == null ? 0 : Math.max(0, Math.min(0.3, (db + 40) / 100)); }
  const cand = units.filter((u) => u.t0 >= 3 && !u.cta).sort((x, y) => y.score - x.score)[0];
  let s0, s1;
  if (cand) {
    // the loudest stretch of that sentence, cut on word edges, at most maxLen
    const ws = words.filter((w) => w.t0 >= cand.t0 - 0.01 && w.t1 <= cand.t1 + 0.01);
    let best = null;
    for (let i = 0; i < ws.length; i++) for (let j = i; j < ws.length && ws[j].t1 - ws[i].t0 <= maxLen; j++) {
      const d = ws[j].t1 - ws[i].t0; if (d < 0.9) continue;
      const sc = (dbAt(p, a, ws[i].t0, ws[j].t1) ?? -30) + d * 4;
      if (!best || sc > best.sc) best = { sc, a: ws[i].t0, b: ws[j].t1 };
    }
    if (best) { s0 = best.a - 0.06; s1 = best.b + 0.08; }
  }
  if (s0 == null) { const T = projectDuration(p); s0 = Math.max(0, T * 0.6); s1 = s0 + Math.min(maxLen, 1.6); }
  const [kit, rewind] = await Promise.all([kitOf(ctx), soundById(ctx, 'hook-rewind')]);
  const D = apply(ctx, 'Flash-forward opener', (q) => E.editFlashForward(q, { a: r3(s0), b: r3(s1), kit, lang: langOf(ctx), rewind }));
  return { from: r3(s0), to: r3(s1), d: D };
}

// ═══ 2. NATURAL HOOKS ═══
/** one click: a video hook + a sound hook that fit the company and the recipe, its hit on the first beat */
export async function addNaturalHook(ctx, { hookId, soundId, index } = {}) {
  const p = ctx.project, { sounds } = await hookLibrary(ctx), industry = industryOf(ctx.workspace);
  const recipe = auto.recipeById(p.recipe);
  const meta = p.meta.viral || {};
  const idx = index ?? (meta.hookIndex == null ? 0 : meta.hookIndex + 1);
  let pair = hookId ? { hook: hookId, sound: soundId || soundsFor(hookId, sounds, industry)[0] } : pickPair(sounds, { industry, energy: recipe?.music?.energy, index: idx });
  if (soundId) pair.sound = soundId;
  const hook = VIDEO_HOOKS.find((h) => h.id === pair.hook);
  const [kit, a, beats] = await Promise.all([kitOf(ctx), analysis(ctx).catch(() => null), beatsOnTimeline(ctx)]);
  const first = beats.find((b) => b >= 0 && b <= 0.6) ?? 0;
  const stills = {};
  if (hook.needs === 'first') stills.first = await saveStill(ctx, await renderStill(ctx, p, 0, { filter: (tr) => tr.kind === 'video' }), 'freeze');
  if (hook.needs === 'last') stills.last = await saveStill(ctx, await renderStill(ctx, p, Math.max(0, projectDuration(p) - 0.6), { filter: (tr) => tr.kind === 'video' }), 'after');
  const sound = sounds.find((s) => s.id === pair.sound) || null;
  apply(ctx, `Hook · ${hook.name}${sound ? ' + ' + sound.title : ''}`, (q) => {
    E.editVideoHook(q, hook.id, { t: hook.id === 'reveal-mask' || hook.id === 'light-leak' ? 0 : first, kit, face: faceAt(q, a, 0.2), firstWord: q.captions?.words?.[0]?.t0 ?? 0, industry, sound, stills, emoji: TOSS[industry], lang: langOf(ctx) });
    q.meta.viral = { ...(q.meta.viral || {}), hookIndex: hookId ? meta.hookIndex ?? -1 : idx };
  });
  return { hook: hook.id, sound: sound?.id || null, beat: first };
}

// ═══ 3. RETENTION PACING ═══
const state = { pacing: null, pacingFor: null, auto: false };
const TEXTY = new Set(['text', 'sticker', 'viral.hook', 'viral.cta', 'auto.pricetag', 'auto.logo', 'auto.endcard']);
function pacingEvents(p, a) {
  const T = projectDuration(p), ev = { duration: T, cuts: [], zooms: [], texts: [], captions: [], motion: [], speech: [] };
  for (const tr of p.tracks) for (const it of tr.items) {
    if (tr.kind === 'video' && it.t0 > 0.05) ev.cuts.push(it.t0);
    if (tr.kind === 'overlay' && it.media && findMedia(p, it.media)?.kind !== 'audio') ev.cuts.push(it.t0);
    if (it.type === 'auto.transition' || it.type === 'viral.vfx') ev.cuts.push(it.t0 + (it.out - it.in) / 2);
    if (TEXTY.has(it.type)) ev.texts.push(it.t0);
    if (it.type === 'viral.progress') for (const c of it.style?.chapters || []) ev.texts.push(it.t0 + c.t0);
    for (const f of it.fx || []) if (typeof f.t0 === 'number') ev.zooms.push(f.t0);
  }
  for (const l of p.captions?.lines || []) ev.captions.push(l.t0);
  if (a) {
    const id = firstVideo(p);
    for (const c of mainClips(p)) if (c.media === id) {
      const sp = c.speed || 1;
      for (const f of a.video?.frames || []) if (f.t >= c.in && f.t < c.out) ev.motion.push({ t: c.t0 + (f.t - c.in) / sp, v: Math.min(1, f.motion * 25) });
      // speech: how much of each quarter second is voice (VAD), lifted a little by how lively it is
      const en = a.audio?.energy, voice = a.audio?.speech || [];
      if (en) {
        const thr = a.audio.thrDb ?? -45, top = thr + 24;
        for (let s = Math.ceil(c.in / 0.25) * 0.25; s < c.out; s += 0.25) {
          const i0 = Math.floor(s / en.hop), i1 = Math.min(en.db.length, Math.floor((s + 0.25) / en.hop)); if (i1 <= i0) continue;
          let m = 0; for (let i = i0; i < i1; i++) m += en.db[i]; m /= i1 - i0;
          let cov = 0; for (const [x, y] of voice) cov += Math.max(0, Math.min(y, s + 0.25) - Math.max(x, s));
          ev.speech.push({ t: c.t0 + (s - c.in) / sp, v: Math.min(1, (cov / 0.25) * 0.75 + 0.25 * Math.max(0, Math.min(1, (m - thr) / (top - thr)))) });
        }
      }
    }
  }
  return ev;
}
/** score every second; remembered for the heat strip */
export async function pacing(ctx, opt = {}) {
  const a = await analysis(ctx, { faces: false }).catch(() => null);
  const res = scorePacing(pacingEvents(ctx.project, a), opt);
  state.pacing = res; state.pacingFor = ctx.project.id; state.analysis = a; state.auto = true;
  bus.emit('timeline:redraw');
  return res;
}
const FIX_ORDER = ['punch', 'text', 'jump', 'broll'];
/** one-click fixes for every dead zone (or the ones given): events every ~1.5 s, rotating jump cut / punch-in / text pop / b-roll */
export async function fixDeadZones(ctx, { zones, kinds } = {}) {
  const before = zones ? null : await pacing(ctx);
  zones = zones || before.dead;
  if (!zones.length) return { fixed: 0, before, after: before };
  const [kit, a, pop] = await Promise.all([kitOf(ctx), analysis(ctx).catch(() => null), sfxById(ctx, 'pop')]);
  const p = ctx.project, words = p.captions?.words || [];
  const plan = [];
  let k = 0;
  for (const z of zones) {
    for (let t = z.t0; t < z.t1 - 0.4; t += 1.5) {
      const kind = (kinds || FIX_ORDER)[k++ % (kinds || FIX_ORDER).length], sub = { t0: t, t1: Math.min(z.t1, t + 1.5) };
      const zw = words.filter((w) => w.t0 >= sub.t0 - 0.2 && w.t1 <= sub.t1 + 0.6);
      const kw = topicOf(zw).keywords[0] || zw.find((w) => String(w.w).length > 3)?.w || '';
      const gap = zw.slice(0, -1).map((w, i) => ({ at: (w.t1 + zw[i + 1].t0) / 2, g: zw[i + 1].t0 - w.t1 })).filter((g) => g.at > sub.t0 + 0.2).sort((x, y) => y.g - x.g)[0];
      plan.push({ zone: sub, kind, env: { kit, sfxPop: pop, keyword: String(kw).replace(/[.,!?؟،]+$/, ''), emoji: zw.map((w) => emojiFor(w.w)).find(Boolean) || null, face: faceAt(p, a, t + 0.3), splitAt: gap?.at ?? null } });
    }
  }
  // what is still flat after the first pass gets a second event in the same step (a text pop or a punch-in)
  apply(ctx, `Fix ${zones.length} dead zone${zones.length > 1 ? 's' : ''}`, (q) => {
    for (const x of plan) E.editDeadZoneFix(q, x.zone, x.kind, x.env);
    const left = scorePacing(pacingEvents(q, a)).dead.filter((d) => zones.some((z) => d.t0 < z.t1 && d.t1 > z.t0));
    for (const d of left) for (let t = d.t0; t < d.t1 - 0.2; t += 1) { const k = Math.round(t) % 2 ? 'text' : 'punch'; E.editDeadZoneFix(q, { t0: t, t1: Math.min(d.t1, t + 1) }, k, { ...plan[0].env, face: faceAt(q, a, t) }); plan.push({ zone: { t0: t }, kind: k }); }
  });
  const after = await pacing(ctx);
  return { fixed: zones.length, events: plan.map((x) => ({ t: r3(x.zone.t0), kind: x.kind })), before, after };
}

// ═══ 4. JUMP-CUT TIGHTENING ═══
export async function planTightening(ctx, opt = {}) {
  const p = ctx.project, words = p.captions?.words || [];
  if (words.length < 2) throw new Error('Tightening needs captions (word timings): press Auto captions first');
  const a = await analysis(ctx, { faces: false }).catch(() => null);
  // a stretch the voice detector hears inside a gap is a missed word only when it is near the words' own loudness
  const wdb = words.map((w) => dbAt(p, a, w.t0, w.t1)).filter((x) => x != null).sort((x, y) => x - y), med = wdb[wdb.length >> 1];
  const voiced = med == null ? null : (lo, hi) => (dbAt(p, a, lo, hi) ?? -100) >= med - 15;
  return planTighten(words, a ? speechTimeline(p, a) : null, { voiced, ...opt });
}
export async function tighten(ctx, opt = {}) {
  const cuts = await planTightening(ctx, opt);
  if (!cuts.length) return { cuts, removed: 0 };
  const removed = removedBy(cuts);
  const res = apply(ctx, `Tighten (−${removed.toFixed(2)} s, ${cuts.length} gaps)`, (p) => E.editTighten(p, cuts));
  return { cuts, removed: r3(removed), joins: res.joins };
}

// ═══ 5. SMART ZOOMS + FACE IN THE UPPER THIRD ═══
export async function stressedWords(ctx, { minGap = 2.2, loudDb = 6, around = 2.5 } = {}) {
  const p = ctx.project, words = p.captions?.words || [];
  if (!words.length) throw new Error('Smart zooms need captions: press Auto captions first');
  const a = await analysis(ctx).catch(() => null);
  const dbs = words.map((w) => dbAt(p, a, w.t0, w.t1));
  // stress is relative to the words around it (±2.5 s), so a loud speaker or a quiet one both work
  const lift = dbs.map((d, i) => { if (d == null) return 0; const nb = dbs.filter((x, j) => j !== i && x != null && Math.abs(words[j].t0 - words[i].t0) <= around).sort((x, y) => x - y); return nb.length ? d - nb[nb.length >> 1] : 0; });
  const em = new Map(emphasis(words.map((w, i) => ({ ...w, loud: lift[i] >= loudDb })), { minGap: 0 }).map((e) => [e.i, e.score]));
  const cand = words.map((w, i) => ({ i, w: w.w, t0: w.t0, t1: w.t1, lift: lift[i], em: em.get(i) || 0 }))
    .filter((c) => c.lift >= loudDb || c.em >= 3).map((c) => ({ ...c, s: c.lift / 3 + c.em })).sort((x, y) => y.s - x.s);
  const out = [];
  for (const c of cand) if (out.every((o) => Math.abs(o.t0 - c.t0) >= minGap)) out.push(c);
  return out.sort((x, y) => x.t0 - y.t0).map((c) => ({ ...c, amount: r3(1.12 + Math.min(0.08, Math.max(0, c.lift) * 0.01)), ...(() => { const f = faceAt(p, a, c.t0); return { cx: f.x, cy: f.y }; })() }));
}
export async function smartZooms(ctx, opt) {
  const list = await stressedWords(ctx, opt);
  apply(ctx, `Smart zooms (${list.length})`, (p) => E.editSmartZooms(p, list));
  return list;
}
/** reframe so the face sits on the upper third: app/auto's reframe() follows the face sideways (smooth, dead-zoned);
 *  this adds the vertical: a tighter window (zoom) whose top keeps the face one third down */
export async function upperThird(ctx, { zoom, follow = 0.45, maxZoom = 1.8 } = {}) {
  const p = ctx.project, a = await analysis(ctx);
  const id = firstVideo(p), m = findMedia(p, id), cv = p.canvas;
  if (!a.subjects?.some((s) => s && s.x != null)) throw new Error('No face found in this clip');
  const crops = await auto.call('reframe', { subjects: a.subjects, src: { w: m.w, h: m.h }, aspect: { w: cv.w, h: cv.h }, opt: { duration: a.duration } });
  const cw = crops[0].w, ch = crops[0].h;
  // the tightest window it takes for the face to be able to sit one third down (a low face needs room below it,
  // a high face room above), never less than a gentle 1.12× and never more than maxZoom
  const fys = a.subjects.filter((s) => s && s.x != null).map((s) => s.y * m.h).sort((x, y) => x - y), fyMed = fys[fys.length >> 1];
  zoom = zoom || +Math.max(1.12, Math.min(maxZoom, Math.max(ch / (1.5 * Math.max(1, m.h - fyMed)), ch / (3 * Math.max(1, fyMed))) * 1.03)).toFixed(3);
  const w2 = cw / zoom, h2 = ch / zoom;
  const cxAt = (t) => { let k = 0; while (k < crops.length - 2 && crops[k + 1].t <= t) k++; const A = crops[k], B = crops[Math.min(crops.length - 1, k + 1)], f = B.t > A.t ? Math.max(0, Math.min(1, (t - A.t) / (B.t - A.t))) : 0; return A.x + (B.x - A.x) * f + cw / 2; };
  const fyAt = (t) => { let best = null; for (const s of a.subjects) { if (s && s.x != null && s.t <= t + 1e-6) best = s; else if (s.t > t) break; } return (best || a.subjects.find((s) => s && s.x != null)).y * m.h; };
  const step = 0.2, out = []; let fy = fyAt(0);
  for (let t = 0; t <= a.duration + 1e-6; t += step) {
    fy += (fyAt(t) - fy) * Math.min(1, step / follow);                              // smooth vertical follow
    const cx = Math.max(w2 / 2, Math.min(m.w - w2 / 2, cxAt(t)));
    const y = Math.max(0, Math.min(m.h - h2, fy - h2 / 3));
    out.push({ t: r3(t), x: r3(cx - w2 / 2), y: r3(y), w: r3(w2), h: r3(h2) });
  }
  const kfs = new Map();
  for (const c of mainClips(p)) if (c.media === id) {
    const sp = c.speed || 1;
    const kf = cropToItemKf(out.filter((k) => k.t >= c.in - step && k.t <= c.out + step).map((k) => ({ ...k, t: c.in + (k.t - c.in) / sp })), { w: m.w, h: m.h }, cv, 0, c.in);
    kfs.set(c.id, kf);
  }
  apply(ctx, 'Face in the upper third', (q) => E.editReframe(q, kfs));
  return { keys: out.length, zoom };
}

// ═══ 6. SAFE ZONES ═══
const CHECKED = new Set(['text', 'sticker', 'viral.hook', 'viral.cta', 'auto.pricetag', 'viral.progress', 'shape']);
/** the drawn bounding box of one item at t (alpha scan of a small render through the real layer) */
function drawnBox(p, track, item, t, k = 0.25) {
  const W = Math.round(p.canvas.w * k), H = Math.round(p.canvas.h * k);
  const can = document.createElement('canvas'); can.width = W; can.height = H;
  const c2 = can.getContext('2d', { willReadFrequently: true }); c2.setTransform(k, 0, 0, k, 0, 0);
  drawItem(c2, item, t, p, { w: p.canvas.w, h: p.canvas.h, scale: k, preview: false, source: () => null, project: p, track });
  const d = c2.getImageData(0, 0, W, H).data; let x0 = W, y0 = H, x1 = -1, y1 = -1;
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) if (d[(y * W + x) * 4 + 3] > 40) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
  return x1 < 0 ? null : { x: x0 / k, y: y0 / k, w: (x1 - x0 + 1) / k, h: (y1 - y0 + 1) / k };
}
const union = (bs) => { bs = bs.filter(Boolean); if (!bs.length) return null; const x = Math.min(...bs.map((b) => b.x)), y = Math.min(...bs.map((b) => b.y)); return { x, y, w: Math.max(...bs.map((b) => b.x + b.w)) - x, h: Math.max(...bs.map((b) => b.y + b.h)) - y }; };
/** caption cue boxes (one per line, measured at the middle of the line) */
export function captionBoxes(p) {
  const out = [];
  for (const tr of p.tracks) if (tr.kind === 'caption' && !tr.hidden) for (const it of tr.items) if (it.type === 'caption') {
    for (const L of p.captions?.lines || []) { const t = Math.min((L.t0 + L.t1) / 2, itemEnd(it) - 0.01); if (t < it.t0) continue; const b = drawnBox(p, tr, it, t); if (b) out.push({ line: L.id, t: r3(t), box: b }); }
  }
  return out;
}
/** everything on screen that sits inside the platform's UI: [{kind:'item'|'caption', id, name, box, zones:[what]}] */
export function safeCheck(ctx, platform = currentPlatform(ctx)) {
  const p = ctx.project, rects = uiRects(p.canvas, platform), out = [];
  for (const tr of p.tracks) {
    if (tr.hidden || tr.kind === 'video' || tr.kind === 'music' || tr.kind === 'sfx') continue;
    for (const it of tr.items) {
      if (!CHECKED.has(it.type) || it.media) continue;
      const d = it.out - it.in, ts = [0.35, d / 2, d - 0.25].map((x) => it.t0 + Math.max(0, Math.min(d - 0.02, x)));
      // what is drawn; for a sticker its whole box too (a glyph sits a little inside it, and he will move the box)
      const box = union([...ts.map((t) => drawnBox(p, tr, it, t)), ...(it.type === 'sticker' ? ts.map((t) => boxAt(it, t)) : [])]);
      if (!box) continue;
      const h = hits(box, rects); if (h.length) out.push({ kind: 'item', id: it.id, name: it.name || it.type, box, zones: h.map((r) => `${PLATFORMS[r.platform]?.name || ''}: ${r.what}`) });
    }
  }
  for (const c of captionBoxes(p)) { const h = hits(c.box, rects); if (h.length) out.push({ kind: 'caption', id: c.line, name: 'Caption at ' + c.t.toFixed(1) + ' s', box: c.box, zones: h.map((r) => `${PLATFORMS[r.platform]?.name || ''}: ${r.what}`) }); }
  return out;
}
export const currentPlatform = (ctx) => ctx.project.meta?.viral?.platform || 'all';
/** move every offending item (and the captions) out of the UI zones — ONE undo step. -> {before, after, moves, caption} */
export function autoNudge(ctx, platform = currentPlatform(ctx)) {
  const p = ctx.project, rects = uiRects(p.canvas, platform);
  const before = safeCheck(ctx, platform);
  const moves = [];
  for (const f of before.filter((x) => x.kind === 'item')) { const n = nudgeBox(f.box, rects, p.canvas); if (n) moves.push({ id: f.id, ...n }); }
  // captions: try positions and sizes (measured through the caption renderer) until no cue touches a zone
  let caption = null;
  if (before.some((x) => x.kind === 'caption')) {
    const st = resolveStyle(p.captions.style), y0 = st.pos?.y ?? 0.72, cands = [];
    for (const s of [1, 0.9, 0.8, 0.7, 0.6]) for (let y = 0; y <= 1.0001; y += 0.05) cands.push({ posY: r3(y), size: Math.round(st.size * s), cost: Math.abs(y - y0) * 2 + (1 - s) * 1.5 });
    cands.sort((a, b) => a.cost - b.cost);
    for (const c of cands) {
      const q = { ...p, captions: { ...p.captions, style: { ...(typeof p.captions.style === 'string' ? { id: p.captions.style } : p.captions.style || {}), pos: { ...(st.pos || {}), y: c.posY }, size: c.size } } };
      if (!captionBoxes(q).some((b) => hits(b.box, rects).length)) { caption = { posY: c.posY, size: c.size === st.size ? null : c.size }; break; }
    }
  }
  if (moves.length || caption) apply(ctx, `Move out of ${platform === 'all' ? 'every app' : PLATFORMS[platform]?.name}'s buttons`, (q) => { E.editNudge(q, moves, caption); q.meta.viral = { ...(q.meta.viral || {}), nudged: platform }; });
  const after = safeCheck(ctx, platform);
  return { before, after, moves, caption };
}
export function setPlatform(ctx, platform) { ctx.history.apply(ops.setProject({ meta: { ...ctx.project.meta, viral: { ...(ctx.project.meta.viral || {}), platform } } }, 'Platform')); }

// ═══ 7. LOOP ENDING ═══
export async function loopEnding(ctx, { d = 0.7 } = {}) {
  const p = ctx.project; d = Math.max(0.5, Math.min(1, d));
  if (projectDuration(p) < 3) throw new Error('The reel is too short for a loop');
  const probe = clone(p); E.removeViral(probe, ['loop']);
  const still = await saveStill(ctx, await renderStill(ctx, probe, 0), 'loop-first-frame');
  apply(ctx, 'Loop ending', (q) => E.editLoop(q, { still, d }));
  return { d, still: still.id };
}

// ═══ 8. CTA ═══
export const CTA_KINDS = {
  comment: { en: (k) => `Comment "${k}"`, ar: (k) => `اكتب "${k}" بالتعليقات`, subEn: 'and I\'ll send you the details', subAr: 'ونرسل لك التفاصيل' },
  link: { en: () => 'Link in bio', ar: () => 'الرابط في البايو', subEn: 'tap it before it\'s gone', subAr: 'اضغط عليه قبل لا يخلص' },
  save: { en: () => 'Save this for later', ar: () => 'احفظ الفيديو', subEn: 'and share it with a friend', subAr: 'وشاركه مع صديق' },
  dm: { en: (k) => `DM us "${k}"`, ar: (k) => `راسلنا "${k}"`, subEn: 'we reply in minutes', subAr: 'نرد عليك خلال دقائق' },
  whatsapp: { en: () => 'WhatsApp us now', ar: () => 'راسلنا واتساب الحين', subEn: '', subAr: '' },
  call: { en: () => 'Call us today', ar: () => 'اتصل فينا اليوم', subEn: '', subAr: '' },
};
export async function addCta(ctx, { kind, lang, keyword, title, sub, dur = 3.2 } = {}) {
  const p = ctx.project, kit = await kitOf(ctx), ind = industryOf(ctx.workspace), L = lang || langOf(ctx);
  // what he already says on camera wins: "comment GLOW", "DM us", "WhatsApp", "link in bio", "save this", "call us"
  const said = (p.captions?.words || []).map((w) => w.w).join(' ');
  const cm = /\b(?:comment|type)\s+["“']?([A-Za-z0-9]{2,15})/i.exec(said) || /(?:اكتب|اكتبي)\s+["“']?([\u0600-\u06FFA-Za-z0-9]{2,15})/.exec(said);
  const heard = cm ? 'comment' : /\b(dm|direct message|message us)\b|راسلنا/i.test(said) ? 'dm' : /whats\s?app|واتساب/i.test(said) ? 'whatsapp' : /link in (my |the )?bio|الرابط/i.test(said) ? 'link' : /\bsave (this|it)\b|احفظ/i.test(said) ? 'save' : /\bcall (us|now)\b|اتصل/i.test(said) ? 'call' : null;
  kind = kind || heard || (ind === 'visa' ? 'comment' : 'link');
  if (!keyword && cm && kind === 'comment') keyword = cm[1].toUpperCase();
  keyword = keyword || (ind === 'visa' ? (L === 'ar' ? 'فيزا' : 'VISA') : ind === 'beauty' ? (L === 'ar' ? 'جلو' : 'GLOW') : (L === 'ar' ? 'نعم' : 'YES'));
  const K = CTA_KINDS[kind] || CTA_KINDS.link;
  const contact = ctx.workspace?.contact || kit.contactLine || '';
  title = title || K[L === 'ar' ? 'ar' : 'en'](keyword);
  sub = sub ?? ((kind === 'whatsapp' || kind === 'call') && contact ? contact : K[L === 'ar' ? 'subAr' : 'subEn']);
  const T = projectDuration(p), end = p.tracks.flatMap((t) => t.items).find((i) => i.type === 'auto.endcard');
  const stop = end ? end.t0 : T, t0 = Math.max(0, stop - dur);
  const sound = await soundById(ctx, 'hook-ding');
  const tops = captionBoxes(p).filter((b) => b.t >= t0 - 0.5 && b.t <= stop + 0.5).map((b) => b.box.y);
  const above = tops.length ? Math.min(...tops) : null;
  apply(ctx, 'CTA · ' + title, (q) => E.editCta(q, { kind, title, sub, keyword: kind === 'comment' || kind === 'dm' ? keyword : '', lang: L, kit, t0: r3(t0), dur: r3(stop - t0), sound, above }));
  return { kind, title, sub, t0: r3(t0), dur: r3(stop - t0) };
}

// ═══ 9. CHAPTERS / STEPS / PROGRESS ═══
export async function chapters(ctx, { mode } = {}) {
  const p = ctx.project, kit = await kitOf(ctx);
  const d = detectChapters(p.captions?.words || [], { duration: projectDuration(p) });
  mode = mode || (d.steps ? 'both' : 'bar');
  apply(ctx, d.steps ? `Steps (${d.chapters.length})` : 'Progress bar', (q) => E.editChapters(q, { chapters: d.chapters, mode, kit, label: langOf(ctx) === 'ar' ? 'خطوة' : 'STEP' }));
  return { ...d, mode };
}

// ═══ 10. A/B VARIATIONS ═══
/** three versions with different openers, each exported as its own MP4. -> [{label, opener, path, size, note}] */
export async function variations(ctx, { size = 1080, fps, onProgress, signal } = {}) {
  const p0 = ctx.project, lines = hookLines(ctx);
  const byKind = (k) => lines.find((l) => l.kind === k) || lines[0];
  const [kit, a, { sounds }] = await Promise.all([kitOf(ctx), analysis(ctx).catch(() => null), hookLibrary(ctx)]);
  const ind = industryOf(ctx.workspace), snd = (id) => sounds.find((s) => s.id === id);
  const strip = (q) => E.removeViral(q, ['hook-text', 'hook-punch', 'hook-sfx', 'vhook', 'vhook-sfx']);
  const flash = await (async () => { // the flash-forward window, chosen once on the live project
    const words = p0.captions?.words || []; if (p0.meta.viral?.flashForward || !words.length) return null;
    const units = await auto.call('scoreUnits', { ctx: { duration: projectDuration(p0), words, energy: null, frames: null, keywords: [] } });
    const u = units.filter((x) => x.t0 >= 3 && !x.cta).sort((x, y) => y.score - x.score)[0]; if (!u) return null;
    const ws = words.filter((w) => w.t0 >= u.t0 - 0.01 && w.t1 <= u.t1 + 0.01); let j = 0; while (j + 1 < ws.length && ws[j + 1].t1 - ws[0].t0 <= 2) j++;
    return { a: r3(ws[0].t0 - 0.06), b: r3(ws[j].t1 + 0.08) };
  })();
  const VARS = [
    { label: 'A', opener: `Hook text (${byKind('number').kind}) + snap zoom`, build: (q) => { strip(q); E.editHookText(q, { line: byKind('number'), kit, face: faceAt(q, a, 0.3), sound: null, punch: false }); E.editVideoHook(q, 'snap-zoom', { t: 0, kit, face: faceAt(q, a, 0.2), sound: snd(HIT[ind]) }); } },
    flash ? { label: 'B', opener: 'Flash-forward: the best moment first', build: (q) => { strip(q); E.editFlashForward(q, { ...flash, kit, lang: langOf(ctx), rewind: snd('hook-rewind') }); } }
      : { label: 'B', opener: 'Reveal mask + "Nobody tells you"', build: (q) => { strip(q); E.editHookText(q, { line: byKind('nobody'), kit, punch: false }); E.editVideoHook(q, 'reveal-mask', { t: 0, kit, face: faceAt(q, a, 0.2), sound: snd('hook-riser') }); } },
    { label: 'C', opener: `Question hook + whip-in`, build: (q) => { strip(q); E.editHookText(q, { line: byKind('question'), kit, punch: false, anim: 'slam' }); E.editVideoHook(q, 'whip-in', { t: 0, kit, face: faceAt(q, a, 0.2), sound: snd('hook-swoosh') }); } },
  ];
  const out = [], base = String(p0.name || 'reel').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').slice(0, 40) || 'reel';
  const short = Math.min(p0.canvas.w, p0.canvas.h), W = Math.round(p0.canvas.w * size / short / 2) * 2, H = Math.round(p0.canvas.h * size / short / 2) * 2;
  for (let i = 0; i < VARS.length; i++) {
    const v = VARS[i], q = clone(p0); v.build(q);
    const store = new MediaStore({ getProject: () => q, engine: ctx.engine });
    try {
      const r = await exportMp4(q, store, { width: W, height: H, fps: fps || q.canvas.fps || 30, signal, onProgress: (f, txt) => onProgress?.((i + f) / VARS.length, `${v.label}: ${txt}`) });
      const path = `${exportsDir(p0.workspace)}/${base}-${v.label}.mp4`;
      if (ctx.engine?.available) await ctx.engine.export(path, r.blob);
      out.push({ label: v.label, opener: v.opener, path, size: r.blob.size, note: r.note, duration: r3(projectDuration(q)), url: URL.createObjectURL(r.blob) });
    } finally { for (const m of q.media) store.release(m.id); }
  }
  return out;
}

// ═══ 11. CLAIM SAFETY ═══
export function claimCheck(ctx) {
  const p = ctx.project, sources = [];
  const words = p.captions?.words || [];
  if (words.length) {
    let txt = '', starts = [];
    words.forEach((w, i) => { starts.push(txt.length); txt += (i ? ' ' : '') + String(w.w).trim(); if (i) starts[i] += 1; });
    const idxAt = (ci) => { let k = 0; while (k + 1 < starts.length && starts[k + 1] <= ci) k++; return k; };
    sources.push({ source: 'captions', text: txt, map: (ci) => words[idxAt(ci)].t0, idxAt });
  }
  for (const tr of p.tracks) for (const it of tr.items) {
    if (it.type === 'text' && it.text) sources.push({ source: 'text', itemId: it.id, field: 'text', text: it.text, t: it.t0 });
    for (const f of ['text', 'title', 'sub', 'cta']) if (it.type !== 'text' && typeof it.style?.[f] === 'string' && it.style[f]) sources.push({ source: it.type, itemId: it.id, field: f, text: it.style[f], t: it.t0 });
  }
  const found = scanClaims(sources);
  for (const f of found) {
    const s = sources.find((x) => x.source === f.source && (x.itemId || null) === f.itemId && (f.source !== 'captions' || x.idxAt));
    if (f.source === 'captions') f.wordIdx = [s.idxAt(f.start), s.idxAt(f.end - 1)];
    else f.field = sources.find((x) => x.itemId === f.itemId && x.text.slice(f.start, f.end) === f.match)?.field || 'text';
  }
  return found;
}
export function fixClaims(ctx, findings = claimCheck(ctx)) {
  if (!findings.length) return 0;
  apply(ctx, `Safer wording (${findings.length})`, (p) => E.editClaims(p, findings));
  if (ctx.project.meta.viral?.nudged && findings.some((f) => f.source === 'captions')) autoNudge(ctx, ctx.project.meta.viral.nudged);
  return findings.length;
}

// ── registration ──
const run = (fn, msg) => async (ctx) => msg(await fn(ctx));
registerModule({ id: 'viral-hook', label: 'Hook builder', icon: '🪝', run: run(buildHook, (r) => `Hook: “${r.line.text}”${r.check?.fix ? ' · first frame fixed' : ''}`) });
registerModule({ id: 'viral-natural', label: 'Add a hook', icon: '🎣', run: run(addNaturalHook, (r) => `Hook: ${VIDEO_HOOKS.find((h) => h.id === r.hook).name}${r.sound ? ' + ' + r.sound.replace('hook-', '') : ''} (press again for another)`) });
registerModule({ id: 'viral-tighten', label: 'Tighten', icon: '⚡', run: run(tighten, (r) => (r.cuts.length ? `Tightened ${r.cuts.length} gaps, −${r.removed.toFixed(2)} s` : 'Already tight: no gaps to cut')) });
registerModule({ id: 'viral-loop', label: 'Loop ending', icon: '🔁', run: run(loopEnding, (r) => `The last ${r.d} s now blends into the first frame`) });
registerModule({ id: 'viral-cta', label: 'CTA', icon: '📣', run: run(addCta, (r) => `CTA: ${r.title}`) });
registerModule({ id: 'viral', label: 'Viral', icon: '🔥', tab: { label: 'Viral', icon: '🔥' }, panel: (el, ctx) => import('./panel.js').then((m) => m.mountPanel(el, ctx, api)) });

// the retention heat strip under the ruler (filled after "Score my reel"; follows every edit from then on)
registerTimelineStrip({
  id: 'viral-retention', label: '🔥 Retention', title: 'Retention score per second (Viral tab → Retention)',
  draw(el, { project, pps, width }) {
    const r = state.pacingFor === project.id ? state.pacing : null; if (!r) return;
    const c = document.createElement('canvas'), H = 14; c.width = Math.min(16000, Math.ceil(width)); c.height = H; c.style.cssText = `width:${c.width}px;height:${H}px;display:block`;
    const g = c.getContext('2d');
    for (const s of r.perSec) { const hue = Math.round(Math.max(0, Math.min(100, s.score)) * 1.3); g.fillStyle = `hsl(${hue} 80% 48%)`; g.fillRect(s.t * pps, 0, Math.max(1, pps - 0.5), H); }
    for (const z of r.dead) { g.strokeStyle = '#fff'; g.lineWidth = 2; g.strokeRect(z.t0 * pps + 1, 1, (z.t1 - z.t0) * pps - 2, H - 2); }
    el.appendChild(c); el.dataset.mean = r.mean; el.dataset.dead = r.dead.length;
  },
});
let rescore = 0;
bus.on('project:change', () => {
  const ed = globalThis.kbbEditor; if (!state.auto || !ed || state.pacingFor !== ed.project.id) return;
  clearTimeout(rescore);
  rescore = setTimeout(() => { state.pacing = scorePacing(pacingEvents(ed.project, state.analysis)); bus.emit('timeline:redraw'); }, 250);
});
bus.on('project:load', () => { state.pacing = null; state.pacingFor = null; state.auto = false; zoneView.platform = null; });

registerExportCheck({
  id: 'viral-safe-zones',
  run: (p, ctx) => {
    const pl = currentPlatform(ctx), list = safeCheck(ctx, pl);
    if (!list.length) return [];
    const name = pl === 'all' ? 'Reels / TikTok / Shorts / Snapchat' : PLATFORMS[pl].name;
    return [{ level: 'warn', text: `${list.length} caption${list.length > 1 ? 's / items sit' : ' / item sits'} under ${name} buttons (${[...new Set(list.map((x) => x.zones[0].split(': ')[1]))].slice(0, 2).join('; ')})`, fixLabel: 'Move them out', fix: () => { autoNudge(ctx, pl); } }];
  },
});
registerExportCheck({
  id: 'viral-claims',
  run: (p, ctx) => {
    const f = claimCheck(ctx); if (!f.length) return [];
    return [{ level: f.some((x) => x.sev === 'high') ? 'error' : 'warn', text: `Risky claim${f.length > 1 ? 's' : ''}: ${f.slice(0, 3).map((x) => `“${x.match}” → “${x.suggestion}”`).join(' · ')}${f.length > 3 ? ` (+${f.length - 3})` : ''}`, fixLabel: 'Use safer wording', fix: () => { fixClaims(ctx, f); } }];
  },
});

// a reopened project draws its viral items in the company's fonts
const fontsDone = new Set();
registerPrepare('viral', async (project) => {
  const ref = project.brand || project.workspace;
  if (!ref || !/^[a-z0-9-]{1,40}$/.test(ref) || fontsDone.has(ref) || !project.tracks.some((t) => t.items.some((i) => /^viral\./.test(i.type)))) return;
  fontsDone.add(ref);
  try { const kit = await getJSON(null, `brands/${ref}/kit.json`); await loadKitFonts(kit, `brands/${ref}/`); } catch {}
});

const api = { hookLines, buildHook, firstFrameCheck, flashForward, addNaturalHook, hookLibrary, pacing, fixDeadZones, tighten, planTightening, smartZooms, stressedWords, upperThird,
  safeCheck, autoNudge, setPlatform, currentPlatform, loopEnding, addCta, chapters, variations, claimCheck, fixClaims, state, zoneView, CTA_KINDS, PLATFORMS, VIDEO_HOOKS };
export { api, state, zoneView, PLATFORMS, VIDEO_HOOKS };
