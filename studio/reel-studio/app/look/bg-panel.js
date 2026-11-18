// KBB Studio · look · the background-removal controls, in two places:
//   🎨 Look tab → Background         pick what to cut out and what goes behind, then "🧍 Remove background"
//   Inspector → 🧍 Background         for the selected clip once it has the effect: every chip applies at once
// Both: Edges (Feather, Shrink/grow, Smoothness, Light wrap), Placement for a transparent cut-out, and the transparent
// exports (cut-out video as WebM with alpha, PNG sequence, this frame as PNG).
import { h, clear, slider, toast } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { findItem } from '../core/project.js';
import { BG_MODES, BG_KEYS, BG_DEFAULTS, removeBackground, setBg, clearBg, placeCutout, exportCutout, exportFrame, bgOf, isTransparent, clipTargets } from './bg.js';
import { subjectReady, SUBJECT_MODEL } from './subject.js';

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  s.textContent = `
.bgp { display: grid; gap: 8px; }
.bgp .bgp-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.bgp .bgp-chips .chip { cursor: pointer; }
.bgp .bgp-grp { font-size: 11.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); margin: 4px 0 0; }
.bgp .bgp-note { font-size: 12px; color: var(--muted); margin: 0; }
.bgp .bgp-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.bgp .bgp-btns .btn { justify-content: center; padding: 7px 8px; font-size: 12.5px; }
.bgp .bgp-btns .btn.wide { grid-column: span 2; }
.bgp input[type=color] { width: 44px; height: 28px; padding: 0 2px; }`;
  document.head.appendChild(s);
}

/**
 * The controls. opts:
 *   live     true in the inspector: chips and pickers apply to the clip at once (it already has the effect)
 *   ids()    the clip ids to act on (inspector: [item.id], or every clip with "All clips"); Look tab: the selection
 *   chipCls  extra class on the chip rows (the Look tab keeps its .lk-chips)
 */
export function bgControls(ctx, { live = false, ids = () => undefined, chipCls = '' } = {}) {
  css();
  const first = () => clipTargets(ctx, ids())[0];
  const cur = bgOf(first()), v2 = cur && (cur.q || 1) >= 2 ? cur : null;
  const st = Object.assign({ key: 'person', mode: 'blur', country: 'gb', size: 0.75, color: ctx.workspace?.colors?.secondary || '#16161c' }, BG_DEFAULTS,
    cur ? { mode: cur.mode, country: cur.country || 'gb', color: cur.color || '#16161c' } : {}, v2 || {});
  st.key = v2?.key || 'person';
  const media = (kinds) => (ctx.project.media || []).filter((m) => kinds.includes(m.kind));
  const run = async (label, fn) => {
    try { const r = await fn(); if (typeof r === 'string') toast(r, 'ok'); return r; }
    catch (e) { if (!e?.denied) { toast(`${label}: ${e.message || e}`, 'error'); console.warn('[bg]', e); } }
    finally { ctx.progress?.(null, null); }
  };
  const apply = () => run('Background', async () => {
    const img = media(['image'])[0];
    const src = st.mode === 'image' ? (st.src || img?.src) : undefined;
    const r = await removeBackground(ctx, { ids: ids(), mode: st.mode, key: st.key, country: st.country, color: st.mode === 'color' ? st.color : undefined, src,
      under: st.mode === 'clip' ? st.under : undefined, keyColor: st.key === 'chroma' ? st.keyColor : undefined,
      feather: st.feather, grow: st.grow, smooth: st.smooth, wrap: st.wrap, tol: st.tol, spill: st.spill });
    if (r.keyColor) st.keyColor = r.keyColor;
    rerender();
    const cov = r.coverage.filter((x) => x != null), what = st.key === 'subject' ? 'subject' : 'person';
    if (st.mode === 'none') return `Transparent on ${r.clips} clip${r.clips > 1 ? 's' : ''}: what is on the tracks below shows through (a “Background” track is under it to drop b-roll or a photo on)`;
    if (st.key === 'chroma') return `Green screen keyed on ${r.clips} clip${r.clips > 1 ? 's' : ''} (key ${r.keyColor})`;
    return cov.length ? `Background replaced on ${r.clips} clip${r.clips > 1 ? 's' : ''} (${what} ${Math.round(cov[0] * 100)}% of the frame)` : 'Background effect added';
  });
  const root = h('div.bgp');
  const chipRow = (list, attr, val, onPick) => {
    const row = h('div.bgp-chips' + chipCls);
    for (const [v, l] of list) row.appendChild(h('button.chip' + (v === val ? '.on' : ''), { type: 'button', dataset: { [attr]: v }, on: { click: () => { for (const x of row.children) x.classList.toggle('on', x.dataset[attr] === v); onPick(v); } } }, l));
    return row;
  };
  const extra = h('div.bgp'), edges = h('div.bgp'), place = h('div.bgp'), modelNote = h('p.bgp-note');
  const live1 = (patch, label) => { if (live || clipTargets(ctx, ids()).some((i) => bgOf(i))) setBg(ctx, patch, { ids: ids(), label }); };
  const countries = h('select', { 'aria-label': 'Country' }, h('option', { value: 'gb' }, 'United Kingdom'));
  fetch('/brands/planet-careers/countries/countries.json').then((r) => (r.ok ? r.json() : { countries: [] })).then((j) => {
    if (j.countries?.length) countries.replaceChildren(...j.countries.map((c) => h('option', { value: c.code, selected: c.code === st.country }, c.name)));
  }).catch(() => {});
  countries.addEventListener('change', () => { st.country = countries.value; if (live) apply(); });

  function renderExtra() {
    clear(extra);
    if (st.mode === 'color') {
      const c = h('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(st.color) ? st.color : '#16161c', 'aria-label': 'Background colour' });
      c.addEventListener('change', () => { st.color = c.value; if (live) apply(); });
      extra.append(h('label.row', h('span.lbl', 'Colour'), c));
    }
    if (st.mode === 'flag' || st.mode === 'landmark') extra.append(h('label.row', h('span.lbl', 'Country'), countries));
    if (st.mode === 'image' || st.mode === 'clip') {
      // "Another clip": any other video in the media bin (never the cut-out clip's own), then pictures
      const own = new Set(clipTargets(ctx, ids()).map((i) => i.media));
      const list = st.mode === 'clip' ? [...media(['video']), ...media(['image'])].filter((m) => !own.has(m.id)) : media(['image']), cur = st.mode === 'clip' ? st.under : st.src;
      if (!list.length) extra.append(h('p.bgp-note', st.mode === 'clip' ? 'Import the clip to put behind (media bin, left), then pick it here.' : 'Import a picture first (media bin, left).'));
      else {
        const s = h('select', { 'aria-label': st.mode === 'clip' ? 'Clip behind' : 'Picture behind' }, ...list.map((m) => h('option', { value: st.mode === 'clip' ? m.id : m.src, selected: (st.mode === 'clip' ? m.id : m.src) === cur }, m.name || m.id)));
        s.addEventListener('change', () => { if (st.mode === 'clip') st.under = s.value; else st.src = s.value; if (live) apply(); });
        if (st.mode === 'clip') st.under = list.some((m) => m.id === st.under) ? st.under : list[0].id; else st.src = st.src || list[0].src;
        extra.append(h('label.row', h('span.lbl', st.mode === 'clip' ? 'Clip' : 'Picture'), s));
      }
    }
    if (st.mode === 'none' || st.mode === 'clip') extra.append(h('p.bgp-note', st.mode === 'none' ? 'The person floats over whatever is on the tracks below: b-roll, a photo, a theme scene. Export it with alpha below.' : 'The clip goes on the track under this one (muted, looped); trim or swap it there.'));
    if (st.key === 'chroma') {
      const kc = h('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(st.keyColor || '') ? st.keyColor : '#20c040', 'aria-label': 'Key colour' });
      kc.addEventListener('change', () => { st.keyColor = kc.value; live1({ keyColor: kc.value }, 'Key colour'); ctx.history.seal(); });
      extra.append(h('label.row', h('span.lbl', 'Key colour'), kc),
        slider({ label: 'Tolerance', min: 0.02, max: 0.3, step: 0.01, value: st.tol, fmt: (v) => Math.round(v * 100), onInput: (v) => { st.tol = v; live1({ tol: v }, 'Key tolerance'); }, onDone: () => ctx.history.seal() }),
        slider({ label: 'Spill', min: 0, max: 1, step: 0.05, value: st.spill, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.spill = v; live1({ spill: v }, 'Spill suppression'); }, onDone: () => ctx.history.seal() }));
    }
  }
  async function renderModel() {
    if (st.key !== 'subject') { modelNote.textContent = ''; modelNote.hidden = true; return; }
    modelNote.hidden = false;
    modelNote.textContent = (await subjectReady()) ? 'Cuts out the main subject (a product, an object or a person) — U²-Net-p, offline.'
      : `Needs a small model the first time (${(SUBJECT_MODEL.size / 1e6).toFixed(1)} MB, ${SUBJECT_MODEL.licence}): it asks once, then works offline.`;
  }
  function renderEdges() {
    clear(edges);
    edges.append(h('div.bgp-grp', 'Edges'),
      slider({ label: 'Feather', min: 0, max: 1, step: 0.05, value: st.feather, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.feather = v; live1({ feather: v }, 'Edge feather'); }, onDone: () => ctx.history.seal() }),
      slider({ label: 'Shrink / grow', min: -1, max: 1, step: 0.05, value: st.grow, fmt: (v) => (v > 0 ? '+' : '') + Math.round(v * 100), onInput: (v) => { st.grow = v; live1({ grow: v }, 'Edge shrink / grow'); }, onDone: () => ctx.history.seal() }),
      slider({ label: 'Smoothness', min: 0, max: 1, step: 0.25, value: st.smooth, fmt: (v) => ['Off', 'Low', 'Medium', 'High', 'Max'][Math.round(v * 4)], onInput: (v) => { st.smooth = v; live1({ smooth: v }, 'Edge smoothness'); }, onDone: () => ctx.history.seal() }),
      slider({ label: 'Light wrap', min: 0, max: 1, step: 0.05, value: st.wrap, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.wrap = v; live1({ wrap: v }, 'Light wrap'); }, onDone: () => ctx.history.seal() }));
  }
  function renderPlace() {
    clear(place);
    if (st.mode !== 'none' && st.mode !== 'clip') return;
    place.append(h('div.bgp-grp', 'Placement'),
      chipRow([['full', 'Full frame'], ['left', 'Left'], ['centre', 'Centre'], ['right', 'Right']], 'place', null, (v) => run('Placement', () => { placeCutout(ctx, v, { ids: ids(), size: st.size }); })),
      slider({ label: 'Size', min: 0.3, max: 1, step: 0.05, value: st.size, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.size = v; }, onDone: () => run('Placement', () => { placeCutout(ctx, 'centre', { ids: ids(), size: st.size }); }) }),
      h('p.bgp-note', 'Or drag and resize it on the video like any item.'));
  }
  function rerender() { renderExtra(); renderPlace(); renderModel(); }

  const keyRow = chipRow(BG_KEYS, 'key', st.key, (v) => { st.key = v; rerender(); if (live) apply(); });
  const modeRow = chipRow(BG_MODES, 'bg', st.mode, (v) => { st.mode = v; rerender(); if (live) apply(); });
  renderEdges(); rerender();
  const doExport = (label, fn) => run(label, async () => { const r = await fn(); return `Saved ${r.file.split('/').pop()}${r.note ? ' · ' + r.note : ''}`; });
  root.append(...[
    h('div.bgp-grp', 'Cut out'), keyRow, modelNote,
    h('div.bgp-grp', 'Background'), modeRow, extra,
    live ? null : h('div.bgp-btns',
      h('button.btn.primary', { type: 'button', dataset: { act: 'remove-bg' }, on: { click: apply } }, '🧍 Remove background'),
      h('button.btn', { type: 'button', on: { click: () => run('Background', () => (clearBg(ctx, { ids: ids() }), 'Background back')) } }, 'Remove')),
    edges, place,
    h('div.bgp-grp', 'Export with transparency'),
    h('div.bgp-btns',
      h('button.btn', { type: 'button', title: 'The cut-out clip alone, transparent around it: WebM (VP9 with alpha) for the Ad Studio, Premiere, After Effects, Canva', dataset: { act: 'export-webm' }, on: { click: () => doExport('Export', () => exportCutout(ctx, { what: 'clip', format: 'webm', ids: ids() })) } }, '⬇ Cut-out video'),
      h('button.btn', { type: 'button', title: 'The cut-out clip as numbered transparent PNGs in a zip (any editor)', dataset: { act: 'export-png' }, on: { click: () => doExport('Export', () => exportCutout(ctx, { what: 'clip', format: 'png', ids: ids() })) } }, '⬇ PNG sequence'),
      h('button.btn', { type: 'button', title: 'The frame at the playhead as one transparent PNG', dataset: { act: 'export-frame' }, on: { click: () => doExport('Frame', () => exportFrame(ctx, { ids: ids() })) } }, '⬇ This frame (PNG)'),
      h('button.btn', { type: 'button', title: 'The whole reel as WebM with alpha (transparent wherever nothing is drawn)', dataset: { act: 'export-reel' }, on: { click: () => doExport('Export', () => exportCutout(ctx, { what: 'reel', format: 'webm' })) } }, '⬇ Whole reel (WebM)')),
  ].filter(Boolean));
  return root;
}

/** Inspector → 🧍 Background (a clip that has the effect) */
export function bgSection(ctx, item) {
  const scope = { all: false };
  const body = h('div.sec-body');
  const ids = () => (scope.all ? clipTargets(ctx, []).filter((i) => bgOf(i)).map((i) => i.id) : [item.id]);
  const allBtn = h('label.check', { title: 'Every change here also goes to every other clip that has its background removed' },
    h('input', { type: 'checkbox', on: { change: (e) => { scope.all = e.target.checked; } } }), 'Change all cut-out clips');
  body.append(allBtn, bgControls(ctx, { live: true, ids }),
    h('div.bgp', h('div.bgp-btns', h('button.btn.wide', { type: 'button', on: { click: async () => { clearBg(ctx, { ids: ids() }); window.kbbEditor?.inspector?.render(true); } } }, '↺ Background back'))));
  return h('details.sec', { open: true, dataset: { sec: 'background' } }, h('summary', isTransparent(bgOf(item)) ? '🧍 Background · transparent' : '🧍 Background'), body);
}
export { bus, findItem };
