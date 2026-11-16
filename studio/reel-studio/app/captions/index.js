// KBB Studio — captions module entry. The editor imports this file at start (core/modules.js
// MODULE_ENTRIES); importing it registers the `caption` layer and the "Auto captions" slot.
//
// Public API for other modules / the integration round:
//   autoCaptions(ctx, {model, lang, signal})      one-click captions (also the slot's run())
//   mountCaptionPanel(el, ctx)                     the caption editor panel (also the slot's panel())
//   drawCaptions(ctx2d, item, t, project, env)     the renderer (already registered as 'caption')
//   STYLES, resolveStyle, styleForWorkspace        styles as data; per-company default
//   commands (editWord, deleteWords, insertWords, splitLine, mergeWithNext, retimeWord, nudgeWord,
//     nudgeLine, shiftAll, findReplace, setStyle, relayout, setLineEmoji, setAlt, setCaptions)
//   Transcriber                                    raw speech-to-text (words with times)
//   captureWords(transcriber, pcm16k, opts)        the capture pipeline (VAD windows, second listen, vocabulary
//                                                  prompt, sound tags / hallucinations, spelling, speakers)
//   script: paragraphs, toTXT, toSRT, toVTT, alignEdit, parseSubtitles   the full script + exports
//   nonspeech / vocab                              tag + hallucination detection, company vocabulary

import { registerModule } from '../core/modules.js';
import { registerLayer } from '../core/render.js';
import { drawCaptions } from './render.js';
import { mountCaptionPanel } from './panel.js';
import { autoCaptions, captureWords } from './auto.js';
import './drag.js';                                   // drag a caption line on the canvas (core canvas grab)
import { loadCaptionFonts } from './fonts.js';

export * as commands from './commands.js';
export { STYLES, resolveStyle, styleForWorkspace, diffStyle } from './styles.js';
export { Transcriber } from './stt.js';
export { buildLines } from './model.js';
export * as script from './script.js';
export * as nonspeech from './nonspeech.js';
export * as vocab from './vocab.js';
export { captureWords };
export { drawCaptions, mountCaptionPanel, autoCaptions, loadCaptionFonts };

registerLayer('caption', drawCaptions);

// The panel's stylesheet, once.
if (typeof document !== 'undefined' && !document.querySelector('link[data-rs-captions]')) {
  const l = document.createElement('link');
  l.rel = 'stylesheet'; l.href = new URL('./captions.css', import.meta.url).href; l.dataset.rsCaptions = '';
  document.head.append(l);
}
loadCaptionFonts();

registerModule({
  id: 'auto-captions',
  label: 'Auto captions',
  icon: '💬',
  run: async (ctx) => { ctx.openPanel?.('auto-captions'); return autoCaptions(ctx); },
  panel: (el, ctx) => mountCaptionPanel(el, ctx),
});
