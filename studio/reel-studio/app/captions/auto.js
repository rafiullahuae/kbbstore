// "Auto captions" in one click: the timeline's speech -> words -> cues -> a caption item, as ONE
// undo step. Uses the engine's permission gate for the first model download.

import { Transcriber, gpuDistrusted } from './stt.js';
import { ensureModel, DEFAULT_MODEL, SPEECH_MODELS } from './models.js';
import { buildLines, finishTimes } from './model.js';
import { setCaptions } from './commands.js';
import { styleForWorkspace, resolveStyle } from './styles.js';
import { itemDur, findMedia, srcTime } from '../core/project.js';
import { cleanNonSpeech, DEFAULT_TAG_MODE } from './nonspeech.js';
import { vocabFor, promptFor, correctTerms } from './vocab.js';
import { heardLanguage, withHeardLanguage } from './hinglish/script.js';
import { captionItemCmds } from './items.js';

const SR = 16000;
let shared = null;
export const transcriber = () => (shared ||= new Transcriber());

/**
 * What the last Auto captions run did, clip by clip (the Captions panel's "Copy diagnostics" and the reasons an empty
 * run gives): { at, model, lang, scope, stage, device, clips:[{ name, secs, rms, words, raw, dropped, why }], error }.
 * The owner, 20 Nov: "I run multiple times auto captions, but nothing generating" — a run that ends with no words
 * must say why for every clip, and the report must be something he can paste.
 */
let lastDiag = null;
export const captionDiagnostics = () => lastDiag;
const rmsOf = (a) => { let e = 0, n = 0; for (let i = 0; i < a.length; i += 16) { e += a[i] * a[i]; n++; } return n ? Math.sqrt(e / n) : 0; };
/** the plain-text report "Copy diagnostics" puts on the clipboard */
export function diagnosticsText(d = lastDiag, extra = {}) {
  if (!d) return 'KBB Studio · Auto captions: not run yet in this window.';
  const L = [`KBB Studio${extra.version ? ' ' + extra.version : ''} · Auto captions diagnostics · ${d.at}`,
    `model ${d.model} · language ${d.lang} · scope ${d.scope} · device ${d.device || '?'}${d.fallback ? ` (${d.fallback})` : ''}`,
    `stage reached: ${d.stage || '?'}${d.ms ? ` · ${(d.ms / 1000).toFixed(1)} s` : ''}`];
  if (d.heard) L.push(`heard: ${d.heard}`);
  for (const c of d.clips || []) L.push(`- ${c.name}: ${c.secs != null ? c.secs.toFixed(1) + ' s decoded' : 'not decoded'}${c.rms != null ? `, RMS ${c.rms.toFixed(4)}` : ''}${c.raw != null ? `, ${c.raw} words heard` : ''}${c.dropped ? `, ${c.dropped} dropped as non-speech` : ''}${c.words != null ? `, ${c.words} kept` : ''}${c.why ? ` — ${c.why}` : ''}`);
  if (d.error) L.push(`error: ${d.error}`);
  const gpu = gpuDistrusted(); if (gpu) L.push(`GPU not used for speech on this Mac: ${gpu}`);
  if (extra.engine) L.push(`engine: ${extra.engine}`);
  L.push(`browser: ${typeof navigator !== 'undefined' ? navigator.userAgent : '?'}`);
  return L.join('\n');
}

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
 * The audible clips on the video/overlay tracks, each decoded: [{ it, m, name, pcm (the media's mono 16 kHz) }].
 * Muted tracks/items and clips with no sound are skipped (and named in opts.skipped).
 * opts: { only: Set of item ids (just those clips), skipped: [] (filled with {it, name, why} for each clip left out) }
 */
export async function speechParts(project, media, opts = {}) {
  const parts = [];
  for (const tr of project.tracks) {
    if (!['video', 'overlay'].includes(tr.kind)) continue;
    for (const it of tr.items) {
      const m = it.media && findMedia(project, it.media);
      if (!m || m.kind === 'image' || it.type !== 'clip' || (opts.only && !opts.only.has(it.id))) continue;
      const why = tr.muted ? 'its track is muted' : it.muted || (it.volume ?? 1) <= 0.001 ? 'it is muted' : m.hasAudio === false ? 'it has no sound' : null;
      if (why) { opts.skipped?.push({ it, name: m.name, why }); continue; }
      let a = null;
      try { a = await media.decodeAudio(m.id); } catch { /* reported below */ }
      if (a && a.mono16k) parts.push({ it, m, name: m.name || it.name || 'clip', pcm: a.mono16k });
      else opts.skipped?.push({ it, name: m.name, why: 'its sound could not be read' });
    }
  }
  return parts;
}

/**
 * One clip's sound as it plays on the timeline (trim, speed, ramps, reverse), from its own start, at a SPEECH level:
 * a quiet recording is lifted (up to 24x) so its loud half sits near -20 dBFS. Listening is level-sensitive (the
 * analysis floor, the hallucination filter, Whisper's own normalisation, the aligner), and in a mix the quiet clip
 * of three read as silence: "no speech heard" on a normal voice. Never made quieter; the clip's volume (ducking,
 * a fade) is not a speech level and is left out. opts.level false: the plain sound.
 */
export function clipSound(it, pcm, opts = {}) {
  const sp = it.speed || 1, n = Math.max(0, Math.floor(itemDur(it) * SR)), src0 = it.in * SR, mapped = !!(it.curve || it.reverse);
  const out = new Float32Array(n);
  for (let i = 0; i < n; i++) {
    const s = mapped ? Math.floor(srcTime(it, it.t0 + i / SR) * SR) : Math.floor(src0 + i * sp);   // ramps / reverse through core/retime.js
    if (s < 0 || s >= pcm.length) { if (s >= pcm.length) break; continue; }
    out[i] = pcm[s];
  }
  if (opts.level === false) return out;
  const g = speechGain(out);
  if (g > 1.01) for (let i = 0; i < n; i++) { const v = out[i] * g; out[i] = v > 0.98 ? 0.98 : v < -0.98 ? -0.98 : v; }
  return out;
}
/** the lift that brings the louder half of a recording's 50 ms frames to ~0.1 RMS (-20 dBFS); 1 = leave it */
export function speechGain(x) {
  const f = 800, rs = [];
  for (let i = 0; i + f <= x.length; i += f) { let e = 0; for (let k = i; k < i + f; k += 2) e += x[k] * x[k]; const r = Math.sqrt(e / (f / 2)); if (r > 2e-4) rs.push(r); }
  if (rs.length < 5) return 1;
  rs.sort((a, b) => a - b);
  const loud = rs[Math.floor(rs.length * 0.75)];
  return Math.max(1, Math.min(24, 0.1 / loud));
}

/** clips that play at the same moment are heard together (one group); clips one after another each on their own */
export function speechGroups(parts) {
  const ps = parts.map((p) => ({ ...p, a: p.it.t0, b: p.it.t0 + itemDur(p.it) })).filter((p) => p.b - p.a > 1e-3).sort((x, y) => x.a - y.a);
  const out = [];
  for (const p of ps) {
    const l = out[out.length - 1];
    if (l && p.a < l.t1 - 0.05) { l.parts.push(p); l.t1 = Math.max(l.t1, p.b); } else out.push({ t0: p.a, t1: p.b, parts: [p] });
  }
  return out;
}
/** a group's sound on its own clock (0 = the group's start) */
export function groupSound(g, opts) {
  const out = new Float32Array(Math.ceil((g.t1 - g.t0) * SR));
  for (const p of g.parts) {
    const seg = clipSound(p.it, p.pcm, opts), o = Math.floor((p.it.t0 - g.t0) * SR);
    for (let i = 0; i < seg.length && o + i < out.length; i++) out[o + i] += seg[i];
  }
  return out;
}

/**
 * Every audible clip on the video/overlay tracks in one 16 kHz mono buffer on the timeline clock (so word times ARE
 * timeline times), each clip at a speech level (clipSound). Muted tracks/items are skipped.
 * opts: { only, skipped } as speechParts, level (default true).
 */
export async function timelineSpeech(project, media, opts = {}) {
  const parts = await speechParts(project, media, opts);
  if (!parts.length) return null;
  return mixGroups(speechGroups(parts), opts);
}
function mixGroups(groups, opts) {
  const end = Math.max(...groups.map((g) => g.t1));
  const out = new Float32Array(Math.ceil(end * SR));
  for (const g of groups) { const s = groupSound(g, opts), o = Math.floor(g.t0 * SR); for (let i = 0; i < s.length && o + i < out.length; i++) out[o + i] = s[i]; }
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
  const sc = captionScope(ctx, opts.clips ? 'selected' : opts.scope);
  const targets = opts.clips ? captionClips(project, opts.clips) : sc.scope === 'selected' ? sc.selected : sc.all;
  const partial = !!opts.clips || sc.scope === 'selected';
  const diag = lastDiag = { at: new Date().toISOString(), model, lang, scope: opts.clips ? 'new clips' : partial ? 'selected clips' : 'whole video', stage: 'starting', clips: targets.map(({ it, name }) => ({ id: it.id, name })) };
  const t0 = typeof performance !== 'undefined' ? performance.now() : 0;
  const report = (label, f) => { if (label) diag.stage = label; ctx.progress?.(label, f); opts.onProgress?.(label, f); };
  try {
    return await runCaptions(ctx, opts, { project, model, lang, sc, targets, partial, diag, report });
  } catch (e) {
    if (e.name !== 'AbortError') diag.error = e.message + (e.op ? ` (${e.op})` : '');
    else diag.stage += ' · cancelled';
    throw e;
  } finally { diag.ms = (typeof performance !== 'undefined' ? performance.now() : 0) - t0; }
}

async function runCaptions(ctx, opts, { project, model, lang, targets, partial, diag, report }) {
  const clipDiag = (it) => diag.clips.find((c) => c.id === it.id) || {};
  if (!targets.length) {
    diag.empty = partial ? 'Select a clip on the timeline first (or choose Whole video).' : 'No clip with sound on the timeline yet.';
    ctx.toast?.(diag.empty, 'warn');
    return partial ? 'No clip selected' : 'No speech to caption';
  }
  const skipped = [];
  report('Reading the clips\u2019 sound…', null);
  const parts = await speechParts(project, ctx.media, { only: new Set(targets.map((c) => c.it.id)), skipped });
  for (const x of skipped) clipDiag(x.it).why = x.why;
  for (const p of parts) { const seg = clipSound(p.it, p.pcm, { level: false }); Object.assign(clipDiag(p.it), { secs: seg.length / SR, rms: rmsOf(seg) }); }
  const why = (list) => list.map((x) => `${x.name}: ${x.why}`).join(' · ');
  if (!parts.length) { diag.empty = `Nothing to caption — ${why(skipped) || 'no clip with sound'}.`; ctx.toast?.(diag.empty, 'warn', 7000); return 'No speech to caption'; }
  try {
    // the same installer (and the same models/ folder) as the first-run Setup screen
    await ensureModel(ctx.engine, model, (p) => report(`Downloading ${SPEECH_MODELS[model].label}`, p.loaded / p.total), ctx.bus);
  } catch (e) {
    if (e.denied) { ctx.toast?.('Captions need the speech model once. Nothing was downloaded.', 'warn'); return 'Not allowed'; }
    throw e;
  }
  // each clip is listened to ON ITS OWN (clips playing at the same moment together): its own language, its own level
  // and its own quiet/loud floor. One mix of every clip let the loudest clip set the floor and the language for all
  // of them, and a quieter clip, or one in another language, came back "no speech heard".
  const groups = speechGroups(parts);
  report('Listening…', 0);
  let r;
  try {
    r = await listenGroups(transcriber(), groups, {
      model, lang, signal: opts.signal, device: opts.device || project.meta?.captions?.device, workspace: ctx.workspace,
      report,
    });
  } finally { report('', null); }
  const p = ctx.project;
  const ranges = rangesOf(targets);
  const words = r.words.filter((w) => inRanges(ranges, w));
  diag.device = r.device; diag.fallback = r.fallback || null;
  diag.heard = r.detect ? `${r.detect.lang} ${Math.round((r.detect.conf || 0) * 100)}%` : r.lang;
  for (const g of r.groups) for (const id of g.ids) { const c = diag.clips.find((x) => x.id === id); if (c) Object.assign(c, { raw: g.raw, dropped: g.dropped }); }
  // a clip that was decoded but gave no word: say WHY (silent, every word removed as non-speech, or the model wrote
  // nothing at all over clear sound — the case that used to read as a blank "no speech heard" every time)
  const quiet = [];
  for (const { it, name } of targets) {
    const c = clipDiag(it), mine = rangesOf([{ it }]);
    c.words = words.filter((w) => inRanges(mine, w)).length;
    if (skipped.some((x) => x.it === it) || c.words) continue;
    c.why = c.rms != null && c.rms < 0.002 ? 'its sound is silent'
      : c.raw > 0 ? `the ${c.raw} words heard were all removed as non-speech`
      : `the speech model wrote no words over ${c.secs != null ? c.secs.toFixed(1) + ' s of ' : ''}sound (${r.device || 'device ?'})`;
    quiet.push({ it, name, why: c.why });
  }
  report('Writing the captions…', null);
  const heard = [...new Set([...(partial ? p.captions?.heard || [] : []), ...targets.map((c) => c.it.id)])];
  const style = p.captions?.style || styleForWorkspace(ctx.workspace);
  const music = (r.music || []).filter((m) => inRanges(ranges, { t0: m[0], t1: m[1] }));
  let cap;
  if (partial && p.captions?.words?.length) {
    cap = mergeCaptions({ ...p.captions, style }, words, ranges, p.canvas);
    cap.music = [...(p.captions.music || []).filter((m) => !inRanges(ranges, { t0: m[0], t1: m[1] })), ...music].sort((x, y) => x[0] - y[0]);
    cap.dropped = [...(p.captions.dropped || []).filter((w) => !inRanges(ranges, w)), ...r.dropped];
    cap.heard = heard;
    // every earlier word was re-heard: the language is the new one (else the clips kept keep theirs)
    if (!p.captions.words.some((w) => !inRanges(ranges, w))) cap.lang = r.lang;
  } else {
    cap = { style, lang: r.lang, words, lines: buildLines(words, style, p.canvas),
      soundTags: p.captions?.soundTags || DEFAULT_TAG_MODE, music, dropped: r.dropped, speakers: r.speakers, heard };
    if (p.captions?.alt) cap.alt = p.captions.alt;
  }
  Object.assign(cap, withHeardLanguage(cap, r, lang, p));   // what was heard + the Hindi / Urdu script (hinglish/)
  ctx.history.apply(captionsWithItem(p, cap, ctx.ops, { label: partial ? 'Auto captions · selected clips' : 'Auto captions', only: partial ? new Set(targets.map((c) => c.it.id)) : null }));
  const names = targets.map((c) => c.name);
  const head = partial ? `Captioned ${plural(targets.length, 'clip')} (${names.slice(0, 3).join(', ')}${names.length > 3 ? '…' : ''})`
    : `Captions for the whole video (${plural(targets.length, 'clip')})`;
  const extra = [r.dropped.length ? `${r.dropped.length} invented words removed` : '', r.fixes.length ? `${r.fixes.length} names spelled right` : '',
    r.speakers > 1 ? `${r.speakers} speakers` : ''].filter(Boolean).join(', ');
  diag.stage = 'done';
  if (words.length) ctx.toast?.(`${head}: ${words.length} words (${r.lang})${extra ? ' · ' + extra : ''}.`, 'ok', 4500);
  if (skipped.length || quiet.length) ctx.toast?.(`No captions for ${why([...skipped, ...quiet])}.`, words.length ? 'warn' : 'error', 12000);
  // nothing at all: the panel shows this in place (with Try again and Copy diagnostics), not only a toast
  if (!words.length) { diag.empty = `No words came back — ${why([...skipped, ...quiet]) || 'no speech heard'}.`; return diag.empty; }
  return `${words.length} words`;
}

/**
 * Caption words for each group of clips, listened to one group at a time (captureWords on the group's own sound),
 * placed on the timeline clock. Speakers are told apart once, over all of them. Returns captureWords' shape plus
 * groups: [{ t0, t1, ids, words (count), lang }].
 */
export async function listenGroups(tr, groups, opts = {}) {
  const words = [], music = [], dropped = [], fixes = [], per = [];
  const n = groups.length, report = opts.report || (() => {});
  for (let gi = 0; gi < n; gi++) {
    const g = groups[gi], at = (t) => +(t + g.t0).toFixed(3), of = n > 1 ? ` · clip ${gi + 1} of ${n}` : '';
    const r = await captureWords(tr, groupSound(g), {
      ...opts, speakers: false,
      onProgress: (p) => p.phase === 'transcribe' ? report(`Writing captions${of}…`, (gi + p.done / p.total) / n) : p.phase === 'post' ? report(`Tidying the script${of}…`, null) : report('Loading speech model…', p.total ? p.loaded / p.total : null),
    });
    const end = g.t1 - g.t0;
    for (const w of r.words) if ((w.t0 + w.t1) / 2 < end) words.push({ ...w, t0: at(w.t0), t1: at(Math.min(w.t1, end)) });
    for (const m of r.music || []) music.push([at(m[0]), at(Math.min(m[1], end))]);
    for (const w of r.dropped || []) dropped.push({ ...w, t0: at(w.t0), t1: at(w.t1) });
    fixes.push(...(r.fixes || []));
    per.push({ t0: g.t0, t1: g.t1, ids: g.parts.map((p) => p.it.id), words: r.words.length, lang: r.lang, hinglish: r.hinglish, detect: r.detect, raw: r.raw,
      device: r.raw?.device, fallback: r.raw?.fallback, dropped: (r.dropped || []).length, rawWords: r.raw?.words?.length ?? null });
  }
  // the captions' language: the one most words were heard in (a clip in another language keeps its own words)
  const tally = new Map();
  for (const g of per) tally.set(g.lang, (tally.get(g.lang) || 0) + g.words + 1e-3);
  const lang = [...tally].sort((a, b) => b[1] - a[1])[0]?.[0] || 'en';
  const main = per.filter((g) => g.lang === lang).sort((a, b) => b.words - a.words)[0] || per[0];
  let out = words, speakers = 1;
  if (opts.speakers !== false && words.length >= 6) {
    try { const sp = await tr.speakers(mixGroups(groups), words); out = sp.words; speakers = sp.n; } catch { /* best effort */ }
  }
  return { words: out, lang, music, dropped, fixes, speakers, raw: main?.raw, hinglish: per.some((g) => g.lang === lang && g.hinglish), detect: main?.detect || null,
    groups: per.map((g) => ({ ...g, raw: g.rawWords })), device: per.map((g) => g.device).filter(Boolean)[0] || null, fallback: per.map((g) => g.fallback).filter(Boolean)[0] || null };
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
  return { words, lang: raw.lang, music: ns.music, dropped: ns.dropped, fixes: fx.fixes, speakers, raw, ...heardLanguage(raw, words) };
}

/**
 * setCaptions + one caption bar per clip that holds caption words (items.js), as a single undo step. opts.only = Set
 * of clip ids: only those clips' bars are made or re-fitted (Auto captions on selected clips, a typed clip script).
 */
export function captionsWithItem(project, cap, ops, opts = {}) {
  const cmds = [setCaptions(cap, project.canvas)];
  if (!ops) return cmds[0];
  // the bars are worked out on the timeline with the NEW words (a clip with none gets no bar)
  cmds.push(...captionItemCmds(project, cap, ops, { only: opts.only || null }));
  return ops.batch(opts.label || 'Auto captions', cmds);
}
