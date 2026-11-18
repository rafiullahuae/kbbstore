// KBB Studio · look · the "🎚 Adjust" section of the inspector for a selected video clip or picture:
// ✨ Enhance video (with each part switchable), Light, Colour and Detail sliders, before / after compare.
// Every slider: double-click it (or ↺) to put it back to zero; a drag is one undo step.
import { h, clear, seg, toast } from '../editor/ui.js';
import { SLIDERS, PARTS, fmt } from './adjust.js';
import { adjustOf, setAdjust, resetAdjust, enhanceVideo, setEnhancePart, enhanceOff, adjustAllLike } from './enhance.js';
import { toggleCompare, compareOn } from './compare.js';

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  s.textContent = `
.adj .adj-row { display: grid; grid-template-columns: 84px minmax(0, 1fr) 44px 26px; align-items: center; gap: 6px; }
.adj .adj-row .lbl { color: var(--muted); font-size: 12.5px; }
.adj .adj-row .val { font-size: 12px; text-align: end; font-variant-numeric: tabular-nums; }
.adj .adj-row.on .lbl { color: var(--text); }
.adj .adj-rst { width: 26px; height: 26px; min-height: 26px; padding: 0; border-radius: 6px; border: 1px solid var(--line2); background: transparent; color: var(--muted); cursor: pointer; font-size: 13px; }
.adj .adj-rst:disabled { opacity: .35; cursor: default; }
.adj .adj-grp { font-size: 11.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); margin: 8px 0 2px; }
.adj .adj-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.adj .adj-btns .btn { justify-content: center; padding: 7px 8px; font-size: 12.5px; }
.adj .adj-btns .btn.wide { grid-column: span 2; }
.adj .adj-parts { display: flex; flex-wrap: wrap; gap: 5px; }
.adj .adj-parts .chip { cursor: pointer; }
.adj .adj-note { font-size: 12px; color: var(--muted); margin: 0; }`;
  document.head.appendChild(s);
}

/** the section element for one clip (ctx = the editor's module context) */
export function adjustSection(ctx, item) {
  css();
  const a = adjustOf(item) || {}, scope = { all: false };
  const opt = () => (scope.all ? { all: true } : { ids: [item.id] });
  const rerender = () => window.kbbEditor?.inspector?.render(true);
  const run = async (label, fn) => {
    try { const r = await fn(); if (typeof r === 'string') toast(r, 'ok'); return r; }
    catch (e) { toast(`${label}: ${e.message || e}`, 'error'); console.warn('[adjust]', e); }
  };
  const rows = [];
  const row = ([, key, label, min, max, step, f]) => {
    const show = f || fmt, v0 = +(a[key] || 0);
    const out = h('span.val', show(v0));
    const inp = h('input', { type: 'range', min, max, step, value: v0, 'aria-label': label, dataset: { adj: key } });
    const rst = h('button.adj-rst', { type: 'button', title: `Reset ${label.toLowerCase()} to zero`, 'aria-label': `Reset ${label}`, disabled: !v0 }, '↺');
    const r = h('div.adj-row' + (v0 ? '.on' : ''), h('span.lbl', label), inp, out, rst);
    const set = (v, seal) => { out.textContent = show(v); rst.disabled = !v; r.classList.toggle('on', !!v); setAdjust(ctx, { [key]: v }, opt()); if (seal) ctx.history.seal(); };
    inp.addEventListener('input', () => set(parseFloat(inp.value), false));
    inp.addEventListener('change', () => ctx.history.seal());
    const zero = () => { inp.value = 0; set(0, true); };
    inp.addEventListener('dblclick', zero); rst.addEventListener('click', zero);
    rows.push(r); return r;
  };
  const group = (g, title) => [h('div.adj-grp', title), ...SLIDERS.filter((s) => s[0] === g).map(row)];

  // ── Enhance video ──
  const e = a.enh, parts = h('div.adj-parts'), note = h('p.adj-note');
  if (e) {
    for (const [k, label, tip] of PARTS) {
      if (k === 'up' && !e.up && !e.parts?.up) continue;
      const on = !!e.parts?.[k];
      parts.appendChild(h('button.chip' + (on ? '.on' : ''), { type: 'button', title: tip, dataset: { part: k }, 'aria-pressed': on ? 'true' : 'false', on: { click: () => run('Enhance', () => { setEnhancePart(ctx, k, !on, opt()); rerender(); }) } }, (on ? '✓ ' : '') + label));
    }
    const r = e.report || {};
    note.textContent = `Measured: brightness ${Math.round((r.mean || 0) * 100)}%, grain ${r.noise ?? 0}/255` + (e.up ? `, ${r.src}px source → upscaled on export` : '') + '. Switch any part off; the sliders below add on top.';
  } else note.textContent = 'One click: exposure and white balance, low-light lift, denoise, sharpening and skin-tone protection — measured from the clip.';
  const upChk = h('input', { type: 'checkbox', checked: !!a.up, on: { change: (ev) => run('Upscale', () => setAdjust(ctx, { up: ev.target.checked }, opt())) } });
  const cmpBtn = h('button.btn' + (compareOn() ? '.primary' : ''), { type: 'button', title: 'Split the preview: the untouched clip on the left, drag the divider', dataset: { act: 'compare' }, on: { click: () => { toggleCompare(); rerender(); } } }, compareOn() ? '◧ Hide before / after' : '◧ Before / after');

  return h('details.sec.adj', { open: true, dataset: { sec: 'adjust' } }, h('summary', '🎚 Adjust'), h('div.sec-body',
    h('label.row', h('span.lbl', 'Apply to'), seg([['one', 'This clip'], ['all', 'All clips']], 'one', (v) => { scope.all = v === 'all'; })),
    h('div.adj-grp', '✨ Enhance'),
    h('div.adj-btns',
      h('button.btn.primary', { type: 'button', dataset: { act: 'enhance' }, title: 'Measures the clip, then fixes light, colour, grain and sharpness', on: { click: () => run('Enhance video', async () => { const r = await enhanceVideo(ctx, opt()); rerender(); return `Enhanced ${r.length} clip${r.length === 1 ? '' : 's'}`; }) } }, '✨ Enhance video'),
      e ? h('button.btn', { type: 'button', on: { click: () => run('Enhance', () => { enhanceOff(ctx, opt()); rerender(); }) } }, 'Enhance off') : cmpBtn,
      e ? h('div.wide', { style: { gridColumn: 'span 2' } }, cmpBtn) : null),
    e ? parts : null, note,
    ...group('light', '☀ Light'),
    ...group('colour', '🎨 Colour'),
    ...group('detail', '🔍 Detail'),
    h('label.check', { title: 'A small clip is enlarged to the export size with a sharp Lanczos filter (export only: the preview is smaller than the clip)' }, upChk, 'Upscale low-res clip on export'),
    h('div.adj-btns',
      h('button.btn', { type: 'button', title: 'Copy this clip’s adjustments to every clip', on: { click: () => run('All clips', () => { adjustAllLike(ctx, item); return 'Adjustments on every clip'; }) } }, 'Use on all clips'),
      h('button.btn', { type: 'button', title: 'Every slider back to zero (Enhance stays)', on: { click: () => run('Reset', () => { resetAdjust(ctx, null, opt()); rerender(); }) } }, '↺ Reset sliders')),
  ));
}
export { clear };
