// "Auto captions" in one click: the timeline's speech -> words -> cues -> a caption item, as ONE
// undo step. Uses the engine's permission gate for the first model download.

import { Transcriber } from './stt.js';
import { ensureModel, DEFAULT_MODEL, SPEECH_MODELS } from './models.js';
import { buildLines, finishTimes } from './model.js';
import { setCaptions } from './commands.js';
import { styleForWorkspace, resolveStyle } from './styles.js';
import { createItem, itemDur, findMedia, tracksOfKind, srcTime } from '../core/project.js';
import { cleanNonSpeech, DEFAULT_TAG_MODE } from './nonspeech.js';
import { vocabFor, promptFor, correctTerms } from './vocab.js';

const SR = 16000;
let shared = null;
export const transcriber = () => (shared ||= new Transcriber());

/**
 * The clips Auto captions can listen to: every clip with a recording (not a picture) on the video/overlay tracks, in
 * track order, or only those whose ids are in `ids`. [{ it, m, name }]
 */
export function captionClips(project, ids = null) {
  const out = [];
  for (const tr of project.tracks) {
    if (!['video', 'overlay'].includes(tr.kind)) continue;
    for (const it of tr.items) {
      if (it.type !== 'clip' || !it.media || (ids && !ids.includes(it.id))) continue;
      const m = findMedia(project, it.media);
      if (m && m.kind !== 'image') out.push({ it, m, name: m.name || it.name || 'clip' });
    }
  }
  return out;
}

/**
 * Mix every audible clip on the video/overlay tracks into one 16 kHz mono buffer on the timeline
 * clock (so word times ARE timeline times). Muted tracks/items are skipped.
 * opts: { only: Set of item ids (just those clips), skipped: [] (filled with {it, name, why} for each clip left out) }
 */
export async function timelineSpeech(project, media, opts = {}) {
  const parts = [];
  for (const tr of project.tracks) {
    if (!['video', 'overlay'].includes(tr.kind)) continue;
    for (const it of tr.items) {
      const m = it.media && findMedia(project, it.media);
      if (!m || m.kind === 'image' || it.type !== 'clip' || (opts.only && !opts.only.has(it.id))) continue;
      const why = tr.muted ? 'its track is muted' : it.muted ? 'it is muted' : m.hasAudio === false ? 'it has no sound' : null;
      if (why) { opts.skipped?.push({ it, name: m.name, why }); continue; }
      let a = null;
      try { a = await media.decodeAudio(m.id); } catch { /* reported below */ }
      if (a && a.mono16k) parts.push({ it, pcm: a.mono16k });
      else opts.skipped?.push({ it, name: m.name, why: 'its sound could not be read' });
    }
  }
  if (!parts.length) return null;
  const end = Math.max(...parts.map((p) => p.it.t0 + itemDur(p.it)));
  const out = new Float32Array(Math.ceil(end * SR));
  for (const { it, pcm } of parts) {
    const sp = it.speed || 1, n = Math.floor(itemDur(it) * SR), o = Math.floor(it.t0 * SR), src0 = it.in * SR, mapped = !!(it.curve || it.reverse);
    for (let i = 0; i < n; i++) {
      const s = mapped ? Math.floor(srcTime(it, it.t0 + i / SR) * SR) : Math.floor(src0 + i * sp);   // ramps / reverse through core/retime.js
      if (s >= pcm.length) break;
      out[o + i] += pcm[s] * (it.volume ?? 1);
    }
  }
  return out;
}

/** 'all' | 'selected': what Auto captions does now (opts.scope wins; otherwise the selection when it holds a clip) */
export function captionScope(ctx, scope) {
  const sel = captionClips(ctx.project, ctx.selection?.() || []);
  return { scope: scope || (sel.length ? 'selected' : 'all'), selected: sel, all: captionClips(ctx.project) };
}

/**
 * Clips that came onto the timeline after the captions were made and have none: not listened to yet (captions.heard)
 * and no caption word inside them. Clips with no sound at all are left out.
 */
export function uncaptionedClips(project) {
  const cap = project.captions;
  if (!cap?.words?.length) return [];
  const heard = new Set(cap.heard || []);
  return captionClips(project).filter(({ it, m }) => !heard.has(it.id) && m.hasAudio !== false && !it.muted &&
    !cap.words.some((w) => { const mid = (w.t0 + w.t1) / 2; return mid >= it.t0 && mid < it.t0 + itemDur(it); }));
}

const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;
/** time ranges of clips, touching ones joined */
function rangesOf(clips) {
  const rs = clips.map(({ it }) => [it.t0, it.t0 + itemDur(it)]).sort((a, b) => a[0] - b[0]), out = [];
  for (const r of rs) { const l = out[out.length - 1]; if (l && r[0] <= l[1] + 1e-3) l[1] = Math.max(l[1], r[1]); else out.push(r.slice()); }
  return out;
}
const inRanges = (rs, w) => { const mid = (w.t0 + w.t1) / 2; return rs.some(([a, b]) => mid >= a && mid < b); };

/**
 * Captions with the words inside `ranges` replaced by `fresh`; every word elsewhere, its line (position, style
 * overrides, emoji) and the style stay. Lines that lose a word to the ranges are rebuilt with the new words.
 */
export function mergeCaptions(old, fresh, ranges, canvas) {
  const keep = old.words.map((w, i) => ({ w, i })).filter((x) => !inRanges(ranges, x.w));
  const all = [...keep, ...fresh.map((w) => ({ w, i: -1 }))].sort((a, b) => a.w.t0 - b.w.t0 || (a.i < 0) - (b.i < 0));
  const words = all.map((x) => x.w), at = new Map();
  all.forEach((x, k) => { if (x.i >= 0) at.set(x.i, k); });
  const lines = [], covered = new Uint8Array(words.length);
  for (const L of old.lines || []) {
    const [a, b] = L.wordIdx, na = at.get(a);
    let whole = na != null;
    for (let i = a; whole && i <= b; i++) whole = at.get(i) === na + (i - a);
    if (!whole) continue;
    lines.push({ ...L, wordIdx: [na, na + b - a] });
    covered.fill(1, na, na + b - a + 1);
  }
  for (let k = 0; k < words.length;) {
    if (covered[k]) { k++; continue; }
    let e = k; while (e + 1 < words.length && !covered[e + 1]) e++;
    for (const N of buildLines(words.slice(k, e + 1), old.style, canvas)) lines.push({ ...N, wordIdx: [N.wordIdx[0] + k, N.wordIdx[1] + k] });
    k = e + 1;
  }
  lines.sort((x, y) => x.wordIdx[0] - y.wordIdx[0]);
  finishTimes(lines, words, resolveStyle(old.style).minDur);
  return { ...old, words, lines };
}

/**
 * Run from the "Do it for me" bar and the Captions panel: ctx = EditorContext (core/modules.js).
 * opts: { model:'small'|'base', lang:'auto'|'en'|'ar'|'hi'|'ur', signal, onProgress,
 *         scope:'all'|'selected' (default: the selected clips when a clip is selected, else the whole video),
 *         clips:[item ids] (caption exactly these, e.g. "Caption the new clips") }
 * Whole video: every clip with sound on every video/overlay track. Selected clips: only their time ranges are
 * (re)captioned and merged into the captions there are; words, lines, positions and styles elsewhere stay.
 * Each clip that cannot be heard (no sound, muted, unreadable sound, no speech found) is named in a toast. ONE undo step.
 */
export async function autoCaptions(ctx, opts = {}) {
  const project = ctx.project;
  const model = opts.model || project.meta?.captions?.model || DEFAULT_MODEL;
  const lang = opts.lang || project.meta?.captions?.lang || 'auto';
  const report = (label, f) => { ctx.progress?.(label, f); opts.onProgress?.(label, f); };
  const sc = captionScope(ctx, opts.clips ? 'selected' : opts.scope);
  const targets = opts.clips ? captionClips(project, opts.clips) : sc.scope === 'selected' ? sc.selected : sc.all;
  const partial = !!opts.clips || sc.scope === 'selected';
  if (!targets.length) {
    ctx.toast?.(partial ? 'Select a clip on the timeline first (or choose Whole video).' : 'No clip with sound on the timeline yet.', 'warn');
    return partial ? 'No clip selected' : 'No speech to caption';
  }
  const skipped = [];
  const audio = await timelineSpeech(project, ctx.media, { only: new Set(targets.map((c) => c.it.id)), skipped });
  const why = (list) => list.map((x) => `${x.name}: ${x.why}`).join(' · ');
  if (!audio) { ctx.toast?.(`Nothing to caption — ${why(skipped) || 'no clip with sound'}.`, 'warn', 7000); return 'No speech to caption'; }
  try {
    // the same installer (and the same models/ folder) as the first-run Setup screen
    await ensureModel(ctx.engine, model, (p) => report(`Downloading ${SPEECH_MODELS[model].label}`, p.loaded / p.total), ctx.bus);
  } catch (e) {
    if (e.denied) { ctx.toast?.('Captions need the speech model once. Nothing was downloaded.', 'warn'); return 'Not allowed'; }
    throw e;
  }
  report('Listening…', 0);
  let r;
  try {
    r = await captureWords(transcriber(), audio, {
      model, lang, signal: opts.signal, device: opts.device || project.meta?.captions?.device, workspace: ctx.workspace,
      onProgress: (p) => p.phase === 'transcribe' ? report('Writing captions…', p.done / p.total) : p.phase === 'post' ? report('Tidying the script…', null) : report('Loading speech model…', p.total ? p.loaded / p.total : null),
    });
  } finally { report('', null); }
  const p = ctx.project;
  const ranges = rangesOf(targets);
  const words = r.words.filter((w) => inRanges(ranges, w));
  // a clip that was decoded but gave no word: say so (a quiet clip, music only, speech in another language)
  const quiet = targets.filter(({ it }) => !skipped.some((x) => x.it === it) && !words.some((w) => inRanges(rangesOf([{ it }]), w)))
    .map(({ it, name }) => ({ it, name, why: 'no speech heard' }));
  const heard = [...new Set([...(partial ? p.captions?.heard || [] : []), ...targets.map((c) => c.it.id)])];
  const style = p.captions?.style || styleForWorkspace(ctx.workspace);
  const music = (r.music || []).filter((m) => inRanges(ranges, { t0: m[0], t1: m[1] }));
  let cap;
  if (partial && p.captions?.words?.length) {
    cap = mergeCaptions({ ...p.captions, style }, words, ranges, p.canvas);
    cap.music = [...(p.captions.music || []).filter((m) => !inRanges(ranges, { t0: m[0], t1: m[1] })), ...music].sort((x, y) => x[0] - y[0]);
    cap.dropped = [...(p.captions.dropped || []).filter((w) => !inRanges(ranges, w)), ...r.dropped];
    cap.heard = heard;
  } else {
    cap = { style, lang: r.lang, words, lines: buildLines(words, style, p.canvas),
      soundTags: p.captions?.soundTags || DEFAULT_TAG_MODE, music, dropped: r.dropped, speakers: r.speakers, heard };
    if (p.captions?.alt) cap.alt = p.captions.alt;
  }
  ctx.history.apply(captionsWithItem(p, cap, ctx.ops, { label: partial ? 'Auto captions · selected clips' : 'Auto captions', grow: partial }));
  const names = targets.map((c) => c.name);
  const head = partial ? `Captioned ${plural(targets.length, 'clip')} (${names.slice(0, 3).join(', ')}${names.length > 3 ? '…' : ''})`
    : `Captions for the whole video (${plural(targets.length, 'clip')})`;
  const extra = [r.dropped.length ? `${r.dropped.length} invented words removed` : '', r.fixes.length ? `${r.fixes.length} names spelled right` : '',
    r.speakers > 1 ? `${r.speakers} speakers` : ''].filter(Boolean).join(', ');
  ctx.toast?.(`${head}: ${words.length} words (${r.lang})${extra ? ' · ' + extra : ''}.`, 'ok', 4500);
  if (skipped.length || quiet.length) ctx.toast?.(`No captions for ${why([...skipped, ...quiet])}.`, 'warn', 8000);
  return `${words.length} words`;
}

/**
 * Speech -> clean caption words, the whole capture pipeline (also what the checks drive):
 *   transcribe (VAD-gated, pause-aligned windows, second listen, vocabulary prompt)
 *   -> sound tags marked, hallucinations dropped, music-only stretches found (nonspeech.js)
 *   -> near-miss spellings of the company's terms corrected (vocab.js)
 *   -> speaker turns (best effort, worker).
 * opts: Transcriber.transcribe opts + { workspace, vocab (overrides the workspace's), speakers (default true) }.
 * Returns { words, lang, music, dropped, fixes, speakers, raw (the transcriber's result) }.
 */
export async function captureWords(tr, audio, opts = {}) {
  const vocab = opts.vocab || vocabFor(opts.workspace);
  const prompt = opts.prompt ?? (vocab.length ? { en: promptFor(vocab, 'en'), ar: promptFor(vocab, 'ar') } : null);
  const raw = await tr.transcribe(audio, { ...opts, prompt });
  opts.onProgress?.({ phase: 'post' });
  const ns = cleanNonSpeech(raw.words, raw.analysis, { prompt: raw.prompt });
  const fx = correctTerms(ns.words, vocab, { lang: raw.lang });
  let words = fx.words, speakers = 1;
  if (opts.speakers !== false && words.length >= 6) {
    try { const sp = await tr.speakers(audio, words); words = sp.words; speakers = sp.n; } catch { /* best effort */ }
  }
  return { words, lang: raw.lang, music: ns.music, dropped: ns.dropped, fixes: fx.fixes, speakers, raw };
}

/**
 * setCaptions + make sure one caption item spans them, as a single undo step. The item runs from 0 to the last word
 * (+1 s), never past the pictures and sound; opts.grow (a re-run on some clips) only ever lengthens it.
 */
export function captionsWithItem(project, cap, ops, opts = {}) {
  const cmds = [setCaptions(cap, project.canvas)];
  // the caption item never makes the reel longer than its pictures and sound (the export length is the last item's end)
  let content = 0;
  for (const tr of project.tracks) if (tr.kind !== 'caption') for (const it of tr.items) content = Math.max(content, it.t0 + itemDur(it));
  const last = cap.words.length ? cap.words[cap.words.length - 1].t1 + 1 : 1;
  let end = content > 0 ? Math.min(last, content) : last;
  const track = tracksOfKind(project, 'caption')[0];
  if (track && ops) {
    const existing = track.items.find((i) => i.type === 'caption');
    if (existing && opts.grow) end = Math.max(end, Math.min(existing.t0 + itemDur(existing), content || Infinity));
    if (existing) cmds.push(ops.setItem(existing.id, { t0: 0, in: 0, out: end }, 'Captions'));
    else cmds.push(ops.addItems([{ trackId: track.id, item: createItem('caption', { t0: 0, in: 0, out: end, x: 0, y: 0, w: project.canvas.w, h: project.canvas.h, name: 'Captions' }) }], 'Captions'));
  }
  return ops ? ops.batch(opts.label || 'Auto captions', cmds) : cmds[0];
}
