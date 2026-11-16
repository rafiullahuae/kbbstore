// "Auto captions" in one click: the timeline's speech -> words -> cues -> a caption item, as ONE
// undo step. Uses the engine's permission gate for the first model download.

import { Transcriber } from './stt.js';
import { ensureModel, DEFAULT_MODEL, SPEECH_MODELS } from './models.js';
import { buildLines } from './model.js';
import { setCaptions } from './commands.js';
import { styleForWorkspace } from './styles.js';
import { createItem, itemDur, findMedia, tracksOfKind } from '../core/project.js';
import { cleanNonSpeech, DEFAULT_TAG_MODE } from './nonspeech.js';
import { vocabFor, promptFor, correctTerms } from './vocab.js';

const SR = 16000;
let shared = null;
export const transcriber = () => (shared ||= new Transcriber());

/**
 * Mix every audible clip on the video/overlay tracks into one 16 kHz mono buffer on the timeline
 * clock (so word times ARE timeline times). Muted tracks/items are skipped.
 */
export async function timelineSpeech(project, media) {
  const parts = [];
  for (const tr of project.tracks) {
    if (!['video', 'overlay'].includes(tr.kind) || tr.muted) continue;
    for (const it of tr.items) {
      const m = it.media && findMedia(project, it.media);
      if (!m || m.hasAudio === false || it.muted || it.type !== 'clip') continue;
      const a = await media.decodeAudio(m.id);
      if (a && a.mono16k) parts.push({ it, pcm: a.mono16k });
    }
  }
  if (!parts.length) return null;
  const end = Math.max(...parts.map((p) => p.it.t0 + itemDur(p.it)));
  const out = new Float32Array(Math.ceil(end * SR));
  for (const { it, pcm } of parts) {
    const sp = it.speed || 1, n = Math.floor(itemDur(it) * SR), o = Math.floor(it.t0 * SR), src0 = it.in * SR;
    for (let i = 0; i < n; i++) {
      const s = Math.floor(src0 + i * sp);
      if (s >= pcm.length) break;
      out[o + i] += pcm[s] * (it.volume ?? 1);
    }
  }
  return out;
}

/**
 * Run from the "Do it for me" bar: ctx = EditorContext (core/modules.js).
 * opts: { model:'small'|'base', lang:'auto'|'en'|'ar'|'hi'|'ur', signal, onProgress }
 */
export async function autoCaptions(ctx, opts = {}) {
  const project = ctx.project;
  const model = opts.model || project.meta?.captions?.model || DEFAULT_MODEL;
  const lang = opts.lang || project.meta?.captions?.lang || 'auto';
  const report = (label, f) => { ctx.progress?.(label, f); opts.onProgress?.(label, f); };
  const audio = await timelineSpeech(project, ctx.media);
  if (!audio) { ctx.toast?.('No clip with sound on the timeline yet.', 'warn'); return 'No speech to caption'; }
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
  const style = project.captions?.style || styleForWorkspace(ctx.workspace);
  const cap = { style, lang: r.lang, words: r.words, lines: buildLines(r.words, style, project.canvas),
    soundTags: project.captions?.soundTags || DEFAULT_TAG_MODE, music: r.music, dropped: r.dropped, speakers: r.speakers };
  if (project.captions?.alt) cap.alt = project.captions.alt;
  ctx.history.apply(captionsWithItem(project, cap, ctx.ops));
  const extra = [r.dropped.length ? `${r.dropped.length} invented words removed` : '', r.fixes.length ? `${r.fixes.length} names spelled right` : '',
    r.speakers > 1 ? `${r.speakers} speakers` : ''].filter(Boolean).join(', ');
  ctx.toast?.(`${r.words.length} words captioned (${r.lang})${extra ? ' · ' + extra : ''}.`, 'ok');
  return `${r.words.length} words`;
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

/** setCaptions + make sure one caption item spans them, as a single undo step. */
export function captionsWithItem(project, cap, ops) {
  const cmds = [setCaptions(cap, project.canvas)];
  // the caption item never makes the reel longer than its pictures and sound (the export length is the last item's end)
  let content = 0;
  for (const tr of project.tracks) if (tr.kind !== 'caption') for (const it of tr.items) content = Math.max(content, it.t0 + itemDur(it));
  const last = cap.words.length ? cap.words[cap.words.length - 1].t1 + 1 : 1;
  const end = content > 0 ? Math.min(last, content) : last;
  const track = tracksOfKind(project, 'caption')[0];
  if (track && ops) {
    const existing = track.items.find((i) => i.type === 'caption');
    if (existing) cmds.push(ops.setItem(existing.id, { t0: 0, in: 0, out: end }, 'Captions'));
    else cmds.push(ops.addItems([{ trackId: track.id, item: createItem('caption', { t0: 0, in: 0, out: end, x: 0, y: 0, w: project.canvas.w, h: project.canvas.h, name: 'Captions' }) }], 'Captions'));
  }
  return ops ? ops.batch('Auto captions', cmds) : cmds[0];
}
