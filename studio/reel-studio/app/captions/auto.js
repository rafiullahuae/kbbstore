// "Auto captions" in one click: the timeline's speech -> words -> cues -> a caption item, as ONE
// undo step. Uses the engine's permission gate for the first model download.

import { Transcriber } from './stt.js';
import { ensureModel, dtypesFor, pickDevice, DEFAULT_MODEL, SPEECH_MODELS } from './models.js';
import { buildLines } from './model.js';
import { setCaptions } from './commands.js';
import { styleForWorkspace } from './styles.js';
import { createItem, itemDur, findMedia, tracksOfKind } from '../core/project.js';

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
  const { device, f16 } = await pickDevice();
  try {
    await ensureModel(ctx.engine, model, dtypesFor(device, f16), (p) => report(`Downloading ${SPEECH_MODELS[model].label}`, p.loaded / p.total));
  } catch (e) {
    if (e.denied) { ctx.toast?.('Captions need the speech model once. Nothing was downloaded.', 'warn'); return 'Not allowed'; }
    // WebGPU files missing and not allowed/available: the WASM set may already be there
    if (device === 'webgpu') await ensureModel(ctx.engine, model, dtypesFor('wasm'), () => {});
    else throw e;
  }
  report('Listening…', 0);
  let r;
  try {
    r = await transcriber().transcribe(audio, {
      model, lang, signal: opts.signal,
      onProgress: (p) => p.phase === 'transcribe' ? report('Writing captions…', p.done / p.total) : report('Loading speech model…', p.total ? p.loaded / p.total : null),
    });
  } finally { report('', null); }
  const style = project.captions?.style || styleForWorkspace(ctx.workspace);
  const cap = { style, lang: r.lang, words: r.words, lines: buildLines(r.words, style, project.canvas) };
  ctx.history.apply(captionsWithItem(project, cap, ctx.ops));
  ctx.toast?.(`${r.words.length} words captioned (${r.lang}).`, 'ok');
  return `${r.words.length} words`;
}

/** setCaptions + make sure one caption item spans them, as a single undo step. */
export function captionsWithItem(project, cap, ops) {
  const cmds = [setCaptions(cap, project.canvas)];
  const end = cap.words.length ? cap.words[cap.words.length - 1].t1 + 1 : 1;
  const track = tracksOfKind(project, 'caption')[0];
  if (track && ops) {
    const existing = track.items.find((i) => i.type === 'caption');
    if (existing) cmds.push(ops.setItem(existing.id, { t0: 0, in: 0, out: Math.max(end, existing.out) }, 'Captions'));
    else cmds.push(ops.addItems([{ trackId: track.id, item: createItem('caption', { t0: 0, in: 0, out: end, x: 0, y: 0, w: project.canvas.w, h: project.canvas.h, name: 'Captions' }) }], 'Captions'));
  }
  return ops ? ops.batch('Auto captions', cmds) : cmds[0];
}
