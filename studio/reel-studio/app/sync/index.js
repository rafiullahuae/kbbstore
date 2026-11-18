// KBB Studio · word-perfect sync (Lane S). Imported by the editor at start (core/modules.js MODULE_ENTRIES).
//
//  * captions follow the media time of every word through every edit (follow.js, a core/history.js follow-up hook);
//  * "Sync words to the voice": forced alignment + onset snapping over Whisper's words (align.js), automatically after
//    Auto captions and on demand, ONE undo step;
//  * caption timing nudge (± ms) and an optional lead, per project (meta.sync), applied where the caption layer draws,
//    so the preview and the export move together;
//  * the "⏱ Sync" tab: status, the controls above, and per-word drag on a waveform.
//
// API: syncWords(ctx, {signal, onProgress}) · followCaptions / anchorCaptions (follow.js) · alignWords (align.js)
import { registerModule } from '../core/modules.js';
import { registerLayer } from '../core/render.js';
import { registerHistoryHook } from '../core/history.js';
import { bus } from '../core/bus.js';
import '../captions/index.js';                       // the captions module registers its layer FIRST; ours wraps it below
import { drawCaptions, captionClock } from '../captions/render.js';
import { speechClips, layoutSig, anchorCaptions, followCaptions, captionsSwap } from './follow.js';
import { alignWords, sharedAligner, ALIGN_MODEL } from './align.js';
import { timelineSpeech } from '../captions/auto.js';
import { syncPanel } from './panel.js';

export { alignWords, ALIGN_MODEL };
export * as follow from './follow.js';

/** meta.sync = { auto (sync after Auto captions, default on), nudgeMs, leadMs, follow (default on), last:{...stats} } */
export const syncMeta = (p) => Object.assign({ auto: true, nudgeMs: 0, leadMs: 0, follow: true }, p?.meta?.sync);

// ── captions follow the media ──
registerHistoryHook({
  id: 'sync.captions',
  // words that carry no anchor yet (captions made before anchoring existed, an old project) are anchored against the
  // layout BEFORE the edit, so they follow it like every other word
  before: (p) => {
    const cap = p.captions, bare = cap?.words?.some((w) => !w.src) || cap?.alt?.words?.some((w) => !w.src);
    return { cap, words: cap?.words, n: cap?.words?.length || 0, sig: layoutSig(p), pre: bare && p.meta?.sync?.follow !== false ? anchorCaptions(cap, speechClips(p)) : null };
  },
  after(p, st) {
    const cap = p.captions;
    if (!cap || (!cap.words?.length && !cap.offcut?.length) || p.meta?.sync?.follow === false) return null;
    const clips = speechClips(p);
    const capChanged = cap !== st.cap || cap.words !== st.words || (cap.words?.length || 0) !== st.n;
    const next = capChanged ? anchorCaptions(cap, clips) : layoutSig(p) !== st.sig ? followCaptions(st.pre || cap, clips, p.canvas) : null;
    return next ? captionsSwap('Captions follow the clips', cap, next) : null;
  },
});

// ── timing nudge + lead where the captions draw (preview and export alike) ──
registerLayer('caption', (ctx, item, t, project, env) => drawCaptions(ctx, item, captionClock(project, t), project, env));

// ── the model (installed by the same installer as the first-run Setup screen) ──
async function modelState(engine) {
  try { const st = await engine.modelsStatus(); const m = st.models?.find((x) => x.id === ALIGN_MODEL.id); return { installed: !!m?.installed, entry: m, st }; }
  catch { return { installed: false }; }
}
/** ask once (with the size) and install the alignment model; resolves when installed. Throws err.denied on "no". */
export async function ensureSyncModel(ctx, onProgress = () => {}) {
  const { installed, entry, st } = await modelState(ctx.engine);
  if (installed) return true;
  if (!entry) throw new Error('models/manifest.json has no ' + ALIGN_MODEL.id);
  const ok = await ctx.engine.askPermission({ what: 'Word-perfect caption sync model — downloaded once, then sync works offline', size: Math.max(0, entry.size - entry.have),
    host: 'huggingface.co', url: `https://huggingface.co/${ALIGN_MODEL.repo}/`, scope: 'repo' });
  if (!ok) { const e = new Error('Download not allowed'); e.denied = true; throw e; }
  if (!(st.installer?.state === 'running' && (st.installer.ids || []).includes(ALIGN_MODEL.id))) await ctx.engine.installModels([entry]);
  await new Promise((res, rej) => {
    const off = bus.on('engine:models', (s) => {
      const m = s.models?.find((x) => x.id === ALIGN_MODEL.id), inst = s.installer || {};
      if (inst.total) onProgress(inst.done / inst.total);
      if (m?.installed) { off(); res(); } else if (['stalled', 'nospace', 'paused'].includes(inst.state)) { off(); rej(new Error(inst.message || 'The download stopped.')); }
    });
  });
  return true;
}

/**
 * Re-time every caption word to the voice: forced alignment (when the model is installed) + onset snapping.
 * ONE undo step ('Sync words to the voice'). opts: { signal, onProgress(label, f), model: false (snapping only) }.
 */
export async function syncWords(ctx, opts = {}) {
  const p = ctx.project, cap = p.captions;
  if (!cap?.words?.length) throw new Error('Make captions first (Auto captions)');
  const report = (l, f) => { ctx.progress?.(l, f); opts.onProgress?.(l, f); };
  report('Listening to every word…', null);
  try {
    const audio = await timelineSpeech(p, ctx.media);
    if (!audio) throw new Error('No clip with sound on the timeline');
    const have = opts.model !== false && (await modelState(ctx.engine)).installed;
    const r = await alignWords(cap.words, audio, { aligner: have ? sharedAligner() : null, signal: opts.signal, onProgress: (f) => report('Syncing words to the voice…', f) });
    if (ctx.project !== p || p.captions?.words?.length !== cap.words.length) throw new Error('The captions changed while syncing — run it again');
    // only the times move: text, styles, lines and overrides stay; lines are re-timed from their words
    const { finishTimes } = await import('../captions/model.js'), { resolveStyle } = await import('../captions/styles.js');
    const next = { ...p.captions, words: p.captions.words.map((w, i) => ({ ...w, t0: r.words[i].t0, t1: r.words[i].t1 })), lines: p.captions.lines.map((L) => ({ ...L, wordIdx: [...L.wordIdx] })) };
    finishTimes(next.lines, next.words, resolveStyle(next.style).minDur);
    const stats = { ...r.stats, at: new Date().toISOString() };
    ctx.history.apply(ctx.ops.batch('Sync words to the voice', [captionsSwap('Sync words to the voice', p.captions, next), ctx.ops.setProject({ meta: { ...p.meta, sync: { ...syncMeta(p), last: stats } } })]));
    return stats;
  } finally { report(null, null); }
}

// after Auto captions: sync straight away (the owner asked for it to just happen)
let autoBusy = false;
bus.on('project:change', ({ cmd, kind } = {}) => {
  if (kind !== 'apply' || !cmd || !/^Auto captions/.test(cmd.label || '')) return;
  const ed = typeof window !== 'undefined' ? window.kbbEditor : null;
  if (!ed || autoBusy || syncMeta(ed.project).auto === false) return;
  autoBusy = true;
  setTimeout(() => syncWords(ed.context()).then((s) => bus.emit('toast', { text: `Captions synced to the voice: ${s.aligned || s.snapped} words placed${s.model ? '' : ' (sound only — download the sync model in the ⏱ Sync tab for word-perfect timing)'}`, kind: 'ok' }))
    .catch((e) => console.warn('[sync]', e)).finally(() => { autoBusy = false; }), 0);
});

registerModule({
  id: 'sync',
  label: 'Word-perfect sync',
  icon: '⏱',
  tab: { label: 'Sync', icon: '⏱', order: 12 },
  run: async (ctx) => { const s = await syncWords(ctx); return `${s.words} words synced${s.model ? '' : ' (sound only)'}`; },
  panel: (el, ctx) => syncPanel(el, ctx, { syncWords, ensureSyncModel, modelState, syncMeta }),
});
