// KBB Studio · Designs · reading a free-form brief with a language model -- offline first:
//   1. the rule reader (lib/brief.js) always runs: it is the fallback AND the validator
//   2. the local model (Qwen2.5-1.5B on the Mac's GPU, or 0.5B on the CPU -- the same files and worker as Post copy,
//      app/content/llm-worker.js) when it is installed: JSON reading + EN/AR copy suggestions
//   3. Claude, only when he connected it (⚙ Configure → Connect Claude), switched "Smart brief" on, and allowed it this
//      session: the engine sends ONLY the brief text and the catalogue names (app/settings/claude.js)
// Whatever a model says is checked by validateSpec: unknown products/countries/occupations dropped, invented numbers
// and promises refused.
import { engine } from '../core/engine.js';
import { parseBrief, validateSpec } from './lib/brief.js';
import { catalogueNames } from './catalogue.js';
import { claudeRun, claudeStatus } from '../settings/claude.js';

const MODELS = {
  gpu: { id: 'copy-llm', repo: 'onnx-community/Qwen2.5-1.5B-Instruct', device: 'webgpu', dtype: 'q4f16' },
  cpu: { id: 'copy-llm-lite', repo: 'onnx-community/Qwen2.5-0.5B-Instruct', device: 'wasm', dtype: 'q8' },
};
let W = null, seq = 0, loaded = null;
const pend = new Map();
const call = (msg) => new Promise((res, rej) => {
  if (!W) { W = new Worker(new URL('../content/llm-worker.js', import.meta.url), { type: 'module' }); W.onmessage = (e) => { const p = pend.get(e.data.id); if (!p || e.data.progress) return; pend.delete(e.data.id); e.data.error ? p.rej(new Error(e.data.error)) : p.res(e.data.result); }; }
  const id = ++seq; pend.set(id, { res, rej }); W.postMessage({ id, ...msg });
});

export async function localModel() {
  let gpu = false;
  if (navigator.gpu) { try { const a = await navigator.gpu.requestAdapter(); gpu = !!a && (a.features?.has?.('shader-f16') ?? false); } catch {} }
  let st; try { st = await engine.modelsStatus(); } catch { return null; }
  const have = (id) => !!st.models.find((m) => m.id === id)?.installed;
  if (gpu && have(MODELS.gpu.id)) return MODELS.gpu;
  if (have(MODELS.cpu.id)) return MODELS.cpu;
  return null;
}

const SYS = 'You turn an advertising brief into JSON. Use only ids from the catalogue list. Never invent prices, discounts, dates or promises. Keys: kind (offer|launch|hiring|study|visa|event|brand), occasion, products [ids], countries [ids], occupations [ids], audience, tone (luxury|playful|bold|professional|calm|urgent|warm), copy [{lang:"en"|"ar", headline, offer, cta}] with short lines.';

/** the model's reading of a brief (raw JSON object) or throws */
export async function localRead(brief, ws, cat, onProgress) {
  const m = await localModel();
  if (!m) throw new Error('The writing model is not installed (⚙ Configure → Models → Writing).');
  if (loaded !== m.id) {
    onProgress?.('Loading the writing model…');
    await call({ op: 'load', repo: m.repo, device: m.device, dtype: m.dtype, modelsUrl: new URL('/models/', location.href).href, vendorUrl: new URL('../../vendor/transformers/', import.meta.url).href });
    loaded = m.id;
  }
  onProgress?.('Reading the brief…');
  const names = catalogueNames(cat, ws.industryKind).slice(0, 160).join('; ');
  const r = await call({ op: 'gen', max: 360, prefix: '{"kind": "', messages: [{ role: 'system', content: SYS }, { role: 'user', content: `Company: ${ws.fullName || ws.name} (${ws.industry}). Catalogue: ${names}\nBrief: ${brief}` }] });
  return { json: parseJsonLoose(r.text), model: m.id, ms: r.ms };
}
export function parseJsonLoose(t) {
  const s = String(t || ''), a = s.indexOf('{');
  for (let b = s.lastIndexOf('}'); b > a; b = s.lastIndexOf('}', b - 1)) { try { return JSON.parse(s.slice(a, b + 1)); } catch {} }
  throw new Error('the model reply was not JSON');
}

/**
 * read a brief: rules always; then Claude (if on + allowed) or the local model (if installed); the result is the rule
 * reading improved by the model, never replaced by it. -> {spec, via:'rules'|'local'|'claude', note, accepted, refused}
 */
export async function readBrief(brief, ws, cat, { smart = false, useLocal = true, onProgress, ask } = {}) {
  const base = parseBrief(brief, ws, cat);
  let via = 'rules', note = '', accepted = [], refused = [];
  try {
    if (smart && (await claudeStatus()).set) {
      onProgress?.('Asking Claude…');
      const r = await claudeRun({ task: 'brief', brief, catalogue: catalogueNames(cat, base.industry), company: ws.fullName || ws.name, industry: ws.industry }, ask);
      const v = validateSpec(base, r.result, cat);
      return { spec: Object.assign(v.spec, { source: 'claude' }), via: 'claude', note: `Read with ${r.model} in ${(r.ms / 1000).toFixed(1)} s`, accepted: v.accepted, refused: v.refused };
    }
  } catch (e) { note = (e.denied ? 'Claude not allowed this time' : 'Claude: ' + e.message) + ' — used the offline reader.'; }
  if (useLocal && brief.split(/\s+/).length > 6) {
    try {
      const r = await localRead(brief, { ...ws, industryKind: base.industry }, cat, onProgress);
      const v = validateSpec(base, r.json, cat);
      return { spec: Object.assign(v.spec, { source: 'local' }), via: 'local', note: note || `Read offline by ${r.model} in ${(r.ms / 1000).toFixed(1)} s`, accepted: v.accepted, refused: v.refused };
    } catch (e) { if (!note) note = /not installed/.test(e.message) ? 'Read by the built-in reader (install the Writing model for free-form briefs).' : 'The writing model could not read it (' + e.message + ') — used the built-in reader.'; }
  }
  return { spec: base, via, note: note || 'Read by the built-in reader.', accepted, refused };
}
