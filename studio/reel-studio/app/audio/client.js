// KBB Studio · audio · the page side of worker.js (one worker, promise per job).
let W = null, seq = 0; const pending = new Map();
function worker() {
  if (W) return W;
  W = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
  W.onmessage = (e) => { const p = pending.get(e.data.id); if (!p) return; pending.delete(e.data.id); e.data.error ? p.rej(new Error(e.data.error)) : p.res(e.data.result); };
  W.onerror = (e) => { e.preventDefault?.(); for (const p of pending.values()) p.rej(new Error(e.message || 'audio worker failed')); pending.clear(); W = null; };
  return W;
}
export const callAudio = (op, args, transfer = []) => new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); worker().postMessage({ id, op, args }, transfer); });

/** voice chain on a copy of the samples (the decoded originals stay cached and untouched) */
export function cleanVoice(L, R, opt) { const l = L.slice(), r = R && R !== L ? R.slice() : null; return callAudio('voice', { L: l, R: r, opt }, [l.buffer, ...(r ? [r.buffer] : [])]); }
export function master(channels, target) { const ch = channels.map((c) => c.slice()); return callAudio('master', { channels: ch, target }, ch.map((c) => c.buffer)); }
export function measure(channels) { const ch = channels.map((c) => c.slice()); return callAudio('measure', { channels: ch }, ch.map((c) => c.buffer)); }
export function stretchPieces(channels, segs) { const ch = channels.map((c) => c.slice()); return callAudio('stretch', { channels: ch, segs }, ch.map((c) => c.buffer)); }
export function retimeSound(channels, from, item, len) { return callAudio('retime', { channels, from, item, len }, channels.map((c) => c.buffer)); }
