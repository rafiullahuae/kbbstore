// One-click caption translation: picks the engine for the pair (ROUTES, chosen by tools/t-eval.cjs — see the README's
// translation table), makes sure its model is installed (ONE question, then the engine's installer), translates the
// track sentence by sentence, back-translates for the quality flags when the reverse model is installed, and applies
// the result as one undoable command.

import { Translator } from './client.js';
import { ENGINES, engineCovers } from './engines.js';
import { translateTrack, translateBatch, assess, sentencesOf, layout } from './pipeline.js';
import { translateWithClaude } from './claude.js';
import { glossaryFor, westernDigits } from './glossary.js';
import { sourceLangOf, LANG, langName } from './langs.js';
import { applyTranslation, editSentence, sourceOf } from './commands.js';
import { requestFor } from './engines.js';
import { createTrack, createItem, projectDuration } from '../../core/project.js';

// The engine per direction, best first, measured on the 60-sentence ad test set (tools/t-eval.cjs; README
// "Translation"): M2M-100 418M wins all six EN<->AR/UR/HI directions (chrF 45-70 vs OPUS-MT 30-62). OPUS-MT stays as
// the fallback and as the fast, INDEPENDENT back-translation checker (planFor). Every other pair goes through M2M-100
// (any of its 100 languages to any other) — the European OPUS-MT pairs were not measured on our set, so not shipped.
export const ROUTES = {
  'en-ar': ['mt-m2m100', 'mt-en-ar'], 'ar-en': ['mt-m2m100', 'mt-ar-en'],
  'en-ur': ['mt-m2m100'], 'ur-en': ['mt-m2m100', 'mt-mul-en'],
  'en-hi': ['mt-m2m100', 'mt-en-hi'], 'hi-en': ['mt-m2m100', 'mt-hi-en'],
};
/** Engines the studio ships in models/manifest.json (group "Translation"); the rest were evaluated and not chosen. */
export const SHIPPED = new Set(['mt-m2m100', 'mt-en-ar', 'mt-ar-en', 'mt-en-hi', 'mt-hi-en', 'mt-mul-en']);

/** Engines able to do src -> tgt, best first. */
export function candidatesFor(src, tgt) {
  const list = [...(ROUTES[`${src}-${tgt}`] || []), 'mt-m2m100'];
  return [...new Set(list)].filter((id) => SHIPPED.has(id) && engineCovers(ENGINES[id], src, tgt));
}

/** installed ids from the engine (modelsStatus); without an engine (dev page) everything counts as installed. */
export async function installedSet(ctx) {
  try {
    if (!ctx?.engine?.modelsStatus) return null;
    const st = await ctx.engine.modelsStatus();
    return new Set(st.models.filter((m) => m.installed).map((m) => m.id));
  } catch { return null; }
}

/** The plan for a pair: { eng (best installed, else best), install (ids to download, best first), back (reverse engine if installed) } */
export async function planFor(ctx, src, tgt, inst) {
  inst = inst === undefined ? await installedSet(ctx) : inst;
  const has = (id) => !inst || inst.has(id);
  const cands = candidatesFor(src, tgt);
  const best = cands[0];
  const eng = cands.find(has) || null;
  // the back-translation check is most telling from a DIFFERENT model than the one that translated (its mistakes are
  // not the same), and the small OPUS-MT ones are 5-7x faster: those first, the same model as a last resort
  const fwd = eng || best;
  const backC = candidatesFor(tgt, src).sort((a, b) => (a === fwd) - (b === fwd) || (ENGINES[a].kind !== 'marian') - (ENGINES[b].kind !== 'marian'));
  const back = backC.find(has) || null;
  return { best, eng, needInstall: !has(best) ? best : null, back, cands };
}

function waitInstalled(engine, bus, id, onProgress) {
  return new Promise((res, rej) => {
    let off = null, poll = 0;
    const end = (fn) => { off?.(); clearInterval(poll); fn(); };
    const check = (st) => {
      const m = st.models?.find((x) => x.id === id), inst = st.installer || {};
      if (inst.total) onProgress?.(inst.done / inst.total, `Downloading ${langPairLabel(id)} …`);
      if (m?.installed) end(res);
      else if (['stalled', 'nospace', 'paused'].includes(inst.state)) end(() => rej(new Error(inst.message || 'The download stopped.')));
    };
    off = bus?.on('engine:models', check) || null;
    poll = setInterval(() => engine.modelsStatus().then(check, () => {}), 3000);
  });
}
const langPairLabel = (id) => ENGINES[id]?.label || id;

/**
 * "Get this language": install the model(s) for a pair through the engine's installer, after ONE question with the
 * size. Throws err.denied when he says no.
 */
export async function installFor(ctx, ids, onProgress) {
  const st = await ctx.engine.modelsStatus();
  const entries = ids.map((id) => st.models.find((m) => m.id === id)).filter((m) => m && !m.installed);
  if (!entries.length) return { downloaded: 0 };
  const need = entries.reduce((s, m) => s + Math.max(0, m.size - (m.have || 0)), 0);
  for (const m of entries) {
    const ok = await ctx.engine.askPermission({ what: `Translation model "${m.purpose.split(':')[0]}" — downloaded once into the models folder, then translation works offline`,
      size: Math.max(0, m.size - (m.have || 0)), host: 'huggingface.co', url: `https://huggingface.co/${m.repo}/`, scope: 'repo' });
    if (!ok) { const e = new Error('Download not allowed'); e.denied = true; throw e; }
  }
  await ctx.engine.installModels(entries);
  for (const m of entries) await waitInstalled(ctx.engine, ctx.bus, m.id, onProgress);
  return { downloaded: need };
}

let shared = null, sharedBack = null;
const translator = () => (shared ||= new Translator());
const backTranslator = () => (sharedBack ||= new Translator());
export function disposeTranslators() { shared?.dispose(); sharedBack?.dispose(); shared = sharedBack = null; }

/**
 * One click: translate the captions into `tgt` and apply them in `mode` ('replace' | 'bilingual' | 'track').
 * opts: { src (default: the transcript's language), check (back-translate when possible, default true), onProgress,
 *   signal, engineId (force one), install (allow the one-question download, default true) }
 * Returns { result, cmd }.
 */
export async function translateCaptions(ctx, tgt, mode = 'replace', opts = {}) {
  const P = ctx.project, cap = P.captions;
  if (!cap?.words?.length) throw new Error('No captions to translate yet — press Auto captions first.');
  const source = sourceOf(cap);
  const src = opts.src || sourceLangOf({ ...source, lang: source.lang || cap.lang });
  if (src === tgt) throw new Error(`The captions are already in ${langName(tgt)}.`);
  const onProgress = opts.onProgress || (() => {});
  if (opts.engineId === 'claude') {
    // no key, "Not now", or a failed call: fall back to the offline engine without fuss (a short note says why)
    try { return await translateCaptionsClaude(ctx, source, src, tgt, mode, opts); }
    catch (e) {
      if (e.name === 'AbortError') throw e;
      if (!e.notConnected) ctx.toast?.(e.denied ? 'Claude not used — translating offline.' : 'Claude did not answer (' + e.message + ') — translating offline.', 'warn');
      opts = { ...opts, engineId: undefined };
    }
  }
  let plan = await planFor(ctx, src, tgt);
  const engineId = opts.engineId || (plan.needInstall && opts.install !== false ? plan.best : plan.eng);
  if (!engineId) throw new Error(`No translation model for ${langName(src)} → ${langName(tgt)} yet — press “Get this language”.`);
  if (ctx.engine?.modelsStatus && opts.install !== false) {
    const inst = await installedSet(ctx);
    if (inst && !inst.has(engineId)) { onProgress(0, 'Downloading the translation model…'); await installFor(ctx, [engineId], onProgress); plan = await planFor(ctx, src, tgt); }
  }
  const eng = ENGINES[engineId];
  const tr = translator();
  tr.onProgress = (p) => p.total && onProgress(p.loaded / p.total * 0.1, 'Loading the model…');
  onProgress(0.02, 'Loading the model…');
  await tr.load(eng);
  const glossary = glossaryFor(ctx.workspace);
  let back = null;
  if (opts.check !== false && plan.back) {
    const bt = backTranslator(); await bt.load(ENGINES[plan.back]);
    back = { eng: ENGINES[plan.back], tr: bt };
  }
  const result = await translateTrack({ ...source, lang: src }, { src, tgt, eng, tr, back, glossary, onProgress, signal: opts.signal });
  const cmd = applyTranslation(result, mode);
  if (mode === 'track' && ctx.ops && ctx.history) {
    const items = trackItemCmds(ctx, tgt);
    ctx.history.apply(ctx.ops.batch(`Translate captions → ${tgt.toUpperCase()} (second track)`, [cmd, ...items]));
  } else ctx.history.apply(cmd);
  return { result, cmd };
}

/** "Best quality (Claude)": the same sentences, glossary, checks and re-split, translated through Connect Claude
 *  (claude.js -> app/settings/claude.js claudeRun; the engine makes the call). Throws when Claude cannot be used. */
async function translateCaptionsClaude(ctx, source, src, tgt, mode, opts) {
  const glossary = glossaryFor(ctx.workspace);
  const sents = sentencesOf({ ...source, lang: src });
  opts.onProgress?.(0.1, 'Asking Claude…');
  const outs = await translateWithClaude(ctx, { src, tgt, sentences: sents.map((s) => s.text), glossary });
  sents.forEach((s, i) => { const out = westernDigits(String(outs[i] || '')).replace(/\s+/g, ' ').trim(); Object.assign(s, { out, attempt: 'claude' }, assess(s.text, out, tgt, glossary, null)); });
  const result = { lang: tgt, src, engine: 'claude', back: null, ...layout(source, sents), sents: sents.map(({ text, out, idx, chunks, flags, score, bt, attempt }) => ({ text, out, idx, chunks, flags, score, bt, attempt })) };
  const cmd = applyTranslation(result, mode);
  ctx.history.apply(mode === 'track' && ctx.ops ? ctx.ops.batch(`Translate captions → ${tgt.toUpperCase()} (second track)`, [cmd, ...trackItemCmds(ctx, tgt)]) : cmd);
  return { result, cmd };
}

/** The commands that give a translated second track its own caption item (once per language). */
export function trackItemCmds(ctx, lang) {
  const p = ctx.project;
  if (p.tracks.some((t) => t.items.some((i) => i.capTrack === lang))) return [];
  const track = createTrack('caption', `Captions · ${lang.toUpperCase()}`);
  const item = createItem('caption', { capTrack: lang, name: `${langName(lang)} captions`, t0: 0, in: 0, out: Math.max(1, projectDuration(p)),
    x: 0, y: 0, w: p.canvas.w, h: p.canvas.h });
  const capIdx = p.tracks.findIndex((t) => t.kind === 'caption');
  return [ctx.ops.addTrack(track, capIdx < 0 ? undefined : capIdx + 1, 'Add caption track'), ctx.ops.addItems([{ trackId: track.id, item }])];
}

/** Re-translate one sentence (review panel ↻), with the same engine; returns the command (not applied). */
export async function retranslateSentence(ctx, lang, i) {
  const cap = ctx.project.captions, T = cap.tr?.langs?.[lang];
  if (!T?.sents?.[i]) throw new Error('Nothing to re-translate');
  const src = T.src || sourceOf(cap).lang;
  const eng = ENGINES[T.engine] || ENGINES['mt-m2m100'];
  const tr = translator(); await tr.load(eng);
  const glossary = glossaryFor(ctx.workspace);
  // a different reading: the sentence on its own plus a gentle repetition penalty, so it is not the identical output
  const r = await translateBatch({ tr: (texts, o) => tr.tr(texts, { ...o, rep: 1.2 }), chat: (p, o) => tr.chat(p, o) }, eng, [T.sents[i].text], src, lang, { glossary });
  let backTxt = null;
  if (T.back && ENGINES[T.back]) {
    const bt = backTranslator(); await bt.load(ENGINES[T.back]);
    backTxt = (await bt.tr([r[0].out], { ...requestFor(ENGINES[T.back], lang, src), max: 256 })).texts[0];
  }
  return editSentence(lang, i, r[0].out, { glossary, back: backTxt, retranslated: true, label: 'Re-translate line' });
}

/** Re-check an edited sentence (back-translation when the reverse model is loaded) — returns { back } or {}. */
export async function backCheck(ctx, lang, text) {
  const cap = ctx.project.captions, T = cap.tr?.langs?.[lang];
  if (!T?.back || !ENGINES[T.back]) return {};
  const src = T.src || sourceOf(cap).lang;
  const bt = backTranslator(); await bt.load(ENGINES[T.back]);
  return { back: (await bt.tr([text], { ...requestFor(ENGINES[T.back], lang, src), max: 256 })).texts[0] };
}

export { assess, LANG };
