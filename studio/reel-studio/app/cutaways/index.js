// KBB Studio · cutaways (Lane S): show something while talking. Imported by the editor at start (MODULE_ENTRIES).
//
// Mark In / Out (I and O keys, drag a range on the "Cutaways" strip under the ruler, or pick caption words) ->
// Replace picture with an image, another video (muted unless mixed in), a product / country / occupation asset or a
// new slide. Voice, music and captions stay exactly as they are. Cutaways follow the clip under them through every
// edit (a core/history.js follow-up hook) and go with it on a ripple delete.
//
// API (also driven by tools/s-check.cjs):
//   marks() / setMarks({in, out}) / markIn(ctx, t) / markOut(ctx, t) / markWords(ctx, a, b)
//   addCutaway(ctx, { t0?, t1?, media?, slide?, style?, name? })  -> item      (marks when t0/t1 are not given)
//   updateCutaway(ctx, id, patch)   removeCutaway(ctx, id)
//   suggestCutaways(ctx) -> proposals ;  acceptSuggestions(ctx, proposals) -> n
import { registerModule, registerTimelineStrip, registerKey, registerExportCheck } from '../core/modules.js';
import { registerHistoryHook, ops } from '../core/history.js';
import { bus } from '../core/bus.js';
import { findItem, itemDur, itemEnd } from '../core/project.js';
import './layer.js';
import { layoutSig } from '../sync/follow.js';
import { makeCutaway, cutaways, cutawayTrack, snapRange, cutawayState, followChanges, itemsSwap, isCutaway, FITS, TRANSITIONS } from './model.js';
import { defaultSlide, loadSlideImages, THEMES, ANIMS } from './slide.js';
import { suggestCutaways, acceptSuggestions } from './auto.js';
import { cutawayPanel } from './panel.js';

export { makeCutaway, cutaways, suggestCutaways, acceptSuggestions, defaultSlide, FITS, TRANSITIONS, THEMES, ANIMS };

// ── marks (editor-session state; not part of the project) ──
let M = { in: null, out: null };
export const marks = () => ({ ...M });
export function setMarks(m) { M = { in: m.in ?? null, out: m.out ?? null }; bus.emit('cutaways:marks', marks()); bus.emit('timeline:redraw'); }
const fps = (ctx) => ctx.project.canvas.fps || 30;
const snap = (ctx, t) => Math.round(t * fps(ctx)) / fps(ctx);
export function markIn(ctx, t = ctx.playhead()) { const a = snap(ctx, t); setMarks({ in: a, out: M.out != null && M.out > a ? M.out : null }); }
export function markOut(ctx, t = ctx.playhead()) { const b = snap(ctx, t); setMarks({ in: M.in != null && M.in < b ? M.in : Math.max(0, b - 2), out: b }); }
/** mark the range said by caption words a..b (inclusive) */
export function markWords(ctx, a, b = a) {
  const ws = ctx.project.captions?.words || []; if (!ws[a] || !ws[b]) return;
  const [x, y] = snapRange(ctx.project, ws[Math.min(a, b)].t0, ws[Math.max(a, b)].t1);
  setMarks({ in: x, out: y });
}

/** one cutaway over [t0, t1) (default: the marks) — ONE undo step; returns the new item */
export async function addCutaway(ctx, o = {}) {
  const p = ctx.project, t0 = o.t0 ?? M.in, t1 = o.t1 ?? M.out;
  if (t0 == null || t1 == null || t1 - t0 < 1 / fps(ctx)) throw new Error('Mark a start (I) and an end (O) first');
  const track = cutawayTrack(p); if (!track) throw new Error('No overlay track');
  const cmds = [];
  let media = o.media || null;
  if (o.src && !media) { media = p.media.find((m) => m.src === o.src) || await ctx.media.importPath(o.src, o.name); if (!p.media.some((m) => m.id === media.id)) cmds.push(ops.addMedia(media, 'Library')); }
  const slide = o.slide ? { ...defaultSlide(ctx.workspace), ...o.slide } : null;
  if (slide) await loadSlideImages(slide);
  const it = makeCutaway(p, t0, t1, { media, slide, name: o.name }, o.style || {});
  cmds.push(ops.addItems([{ trackId: track.id, item: it }], 'Cutaway'));
  ctx.history.apply(ops.batch(slide ? 'Add slide cutaway' : 'Replace picture', cmds));
  setMarks({ in: null, out: null });
  window.kbbEditor?.select?.([it.id]);
  return findItem(ctx.project, it.id)?.item || it;
}
export function updateCutaway(ctx, id, patch, label = 'Cutaway', key) {
  const it = findItem(ctx.project, id)?.item; if (!isCutaway(it)) return;
  const props = {};
  if (patch.style) props.style = { ...it.style, ...patch.style, ...(patch.style.slide ? { slide: { ...it.style.slide, ...patch.style.slide } } : {}) };
  if ('mix' in patch) { props.muted = !(patch.mix > 0); props.volume = patch.mix; props.style = { ...(props.style || it.style), mix: patch.mix }; }
  for (const k of ['t0', 'out', 'name']) if (k in patch) props[k] = patch[k];
  const cmd = ops.setItem(id, props, label);
  if (key) cmd.key = key + id;
  ctx.history.apply(cmd);
  if (props.style?.slide) loadSlideImages(props.style.slide).then(() => window.kbbEditor?.preview?.draw());
}
export function removeCutaway(ctx, id) { ctx.history.apply(ops.removeItems([id], 'Remove cutaway')); }

// ── cutaways follow the clip under them ──
registerHistoryHook({
  id: 'cutaways.follow',
  before: (p) => (p.tracks.some((t) => t.items.some(isCutaway)) ? cutawayState(p) : null),
  after(p, st) {
    // no cutaway before this edit: any there now is new, and is linked from where it sits
    if (!st) { if (!p.tracks.some((t) => t.items.some(isCutaway))) return null; st = { sig: layoutSig(p), cuts: new Map() }; }
    const ch = followChanges(p, st);
    return ch.length ? itemsSwap(p, 'Cutaways follow the clips', ch) : null;
  },
});

// ── keys: I / O ──
registerKey({ key: 'i', label: 'Mark in (cutaway start)', run: (ctx) => markIn(ctx) });
registerKey({ key: 'o', label: 'Mark out (cutaway end)', run: (ctx) => markOut(ctx) });

// ── the strip under the ruler: marks + cutaways; drag on it to mark a range ──
registerTimelineStrip({
  id: 'cutaways', label: '🎞 Cutaways', title: 'Drag here to mark a range for a cutaway (or press I and O)',
  draw(el, { project, pps }) {
    el.style.position = 'relative'; el.style.cursor = 'crosshair';
    for (const { item } of cutaways(project)) {
      const b = document.createElement('div');
      b.title = `${item.name} · ${item.t0.toFixed(2)}–${itemEnd(item).toFixed(2)} s`;
      b.dataset.cutaway = item.id;
      Object.assign(b.style, { position: 'absolute', top: '3px', bottom: '3px', left: item.t0 * pps + 'px', width: Math.max(3, itemDur(item) * pps) + 'px', background: item.style?.slide ? '#7c5cff' : '#2bb3a3', borderRadius: '4px', opacity: '0.9', overflow: 'hidden', font: '600 10px system-ui', color: '#fff', padding: '0 4px', whiteSpace: 'nowrap', lineHeight: '16px' });
      b.textContent = item.style?.slide ? '▤ ' + (item.style.slide.title || 'Slide') : '🖼 ' + (item.name || '').replace(/^Cutaway · /, '');
      el.appendChild(b);
    }
    if (M.in != null) {
      const r = document.createElement('div'); r.className = 'cw-mark';
      const a = M.in, z = M.out ?? M.in;
      Object.assign(r.style, { position: 'absolute', top: '0', bottom: '0', left: a * pps + 'px', width: Math.max(2, (z - a) * pps) + 'px', background: 'rgba(255,209,102,0.35)', borderLeft: '2px solid #ffd166', borderRight: M.out != null ? '2px solid #ffd166' : '0', pointerEvents: 'none' });
      el.appendChild(r);
    }
    el.onpointerdown = (e) => {
      if (e.button !== 0) return;
      const ed = window.kbbEditor; if (!ed) return;
      e.stopPropagation(); e.preventDefault();
      const hit = e.target.closest?.('[data-cutaway]');
      const rect = el.getBoundingClientRect(), tAt = (x) => Math.max(0, (x - rect.left) / pps);
      if (hit) { ed.select([hit.dataset.cutaway]); ed.seek(findItem(ed.project, hit.dataset.cutaway)?.item.t0 ?? tAt(e.clientX)); ed.showSide?.('mod:cutaways'); return; }
      const ctx = ed.context(), a = tAt(e.clientX);
      let moved = false;
      const mv = (ev) => { const b = tAt(ev.clientX); if (Math.abs(b - a) * pps > 3) moved = true; if (moved) setMarks({ in: snap(ctx, Math.min(a, b)), out: snap(ctx, Math.max(a, b)) }); };
      const up = () => { window.removeEventListener('pointermove', mv); window.removeEventListener('pointerup', up); if (!moved) ed.seek(a); else ed.showSide?.('mod:cutaways'); };
      window.addEventListener('pointermove', mv); window.addEventListener('pointerup', up);
    };
  },
});

// ── export check: a cutaway whose picture is missing ──
registerExportCheck({
  id: 'cutaways',
  run(project) {
    const out = [];
    for (const { item } of cutaways(project)) if (!item.style?.slide && !(item.media && project.media.some((m) => m.id === item.media))) out.push({ level: 'warn', text: `Cutaway "${item.name}" has no picture — it will show the speaker.` });
    return out;
  },
});

registerModule({
  id: 'cutaways',
  label: 'Cutaways',
  icon: '🎞',
  tab: { label: 'Cutaways', icon: '🎞', order: 14 },
  panel: (el, ctx) => cutawayPanel(el, ctx, { marks, setMarks, markIn, markOut, markWords, addCutaway, updateCutaway, removeCutaway, suggestCutaways, acceptSuggestions }),
});
