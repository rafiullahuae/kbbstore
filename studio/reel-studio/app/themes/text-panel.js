// KBB Studio · themes · the inspector's TEXT sections for a theme element (and a transition that draws words):
//
//   Text        every word the element draws, prefilled with what is on the canvas; live while typing (one undo step per
//               edit), Enter or click-away commits, Esc puts it back, ↺ resets one field; Arabic beside English;
//               per-field size and colour (Aa); + Add line / + Add point; + Add text (a Text item on the element,
//               linked to its place and timing); the Text tool
//   Text style  font (bundled + the company's), size, weight, italic, letter spacing, case, alignment, text colour
//               (company chips, hex, theme), band and accent colours (company chips, "Use theme colours"), shadow, outline
//
// Writes go through ops.setItems on item.style.text / item.style.tstyle / item.style.colors: undo, save, export and
// the preview all read the same project. Nothing here measures the DOM; the canvas boxes come from the renderer.
import { ops } from '../core/history.js';
import { findItem, itemDur } from '../core/project.js';
import { h, slider, field, seg } from '../editor/ui.js';
import { textFieldsOf, nextLine, elOfItem, labelOf } from './text-fields.js';
import { transTextFields } from './transitions.js';
import { FONT_FAMILIES, loadCaptionFonts } from '../captions/fonts.js';
import { themeById } from './themes.js';
import { colorsFor } from './apply.js';

const curItem = (ed, id) => findItem(ed.project, id)?.item || null;
const HEX = /^#[0-9a-f]{6}$/i;
function toHex(c) {
  if (!c) return '#000000';
  if (HEX.test(c)) return c.toLowerCase();
  if (/^#[0-9a-f]{3}$/i.test(c)) return '#' + c.slice(1).split('').map((x) => x + x).join('');
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(c);
  return m ? '#' + [m[1], m[2], m[3]].map((x) => (+x).toString(16).padStart(2, '0')).join('') : '#000000';
}

// ── writes (each one ops.setItems on the item's style: absolute values, so typing coalesces exactly) ──
function writeStyle(ed, id, fn, label) {
  const it = curItem(ed, id); if (!it) return;
  const style = structuredClone(it.style || {}); fn(style);
  ed.apply(ops.setItems([{ id, props: { style } }], label));
}
/** one word: a string sets it ('' hides the line), null puts the theme's default back */
export function writeWords(ed, id, key, value, label = 'Element text') {
  writeStyle(ed, id, (s) => { s.text = { ...(s.text || {}) }; if (value == null) delete s.text[key]; else s.text[key] = String(value); }, label);
}
/** the text style: patch keys set, null keys removed; an empty style is dropped */
export function writeTStyle(ed, id, patch, label = 'Text style') {
  writeStyle(ed, id, (s) => {
    const t = { ...(s.tstyle || {}) };
    for (const [k, v] of Object.entries(patch)) { if (v == null) delete t[k]; else t[k] = v; }
    for (const k of ['sizes', 'colors']) if (t[k] && !Object.keys(t[k]).length) delete t[k];
    if (Object.keys(t).length) s.tstyle = t; else delete s.tstyle;
  }, label);
}

/**
 * An edit burst: every keystroke writes (at most once a frame) and they all merge into ONE undo step until the burst
 * ends (Enter, Esc, click-away). The history merges same-key steps written within its window; a burst keeps that
 * window open while the owner pauses mid-word.
 */
export class Burst {
  constructor(ed) { this.ed = ed; this.n = 0; this.raf = 0; this.next = null; }
  write(fn) { this.next = fn; if (!this.raf) this.raf = requestAnimationFrame(() => this.flush()); }
  flush() {
    if (this.raf) cancelAnimationFrame(this.raf); this.raf = 0;
    const fn = this.next; this.next = null; if (!fn) return;
    if (this.n++ > 0) this.ed.history.lastAt = performance.now();
    fn();
  }
  end() { this.flush(); if (this.n) this.ed.history.seal(); this.n = 0; }
}

// ── the fields an item draws (cached per structure: typing never re-draws the probe) ──
const CACHE = new Map();
export function fieldsFor(item, project) {
  const text = item.style?.text || {};
  if (item.type === 'theme.trans') {
    return transTextFields(item, project).map((f) => ({ key: f.key, label: labelOf(f.key, 'ticket'), def: f.def, user: text[f.key] != null, value: text[f.key] != null ? String(text[f.key]) : f.def, ar: false, pair: null }));
  }
  const s = item.style || {};
  const key = [item.id, elOfItem(item), s.theme, s.variant, item.w, item.h, itemDur(item).toFixed(2), Object.keys(text).sort().join(','), JSON.stringify(s.colors || null), s.brand || ''].join('|');
  let raw = CACHE.get(key);
  if (!raw) { raw = textFieldsOf(item, project); CACHE.set(key, raw); if (CACHE.size > 40) CACHE.delete(CACHE.keys().next().value); }
  return raw.map((f) => (text[f.key] != null ? { ...f, user: true, value: String(text[f.key]) } : f));
}

const STYLE_CSS = `
.tx-field { display: grid; gap: 4px; margin: 0 0 8px; }
.tx-head { display: flex; align-items: center; gap: 6px; min-height: 24px; }
.tx-head .lbl { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tx-head button { flex: 0 0 auto; height: 24px; min-height: 24px; padding: 0 7px; font-size: 12px; }
.tx-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.tx-field input[type=text], .tx-field textarea { width: 100%; min-width: 0; box-sizing: border-box; }
.tx-field textarea { resize: vertical; min-height: 52px; font: inherit; }
.tx-field.on input, .tx-field.on textarea { outline: 2px solid var(--accent, #e8508b); outline-offset: 1px; }
.tx-field.flash input, .tx-field.flash textarea { box-shadow: 0 0 0 3px var(--accent, #e8508b); transition: box-shadow .2s; }
.tx-more { display: grid; gap: 4px; padding: 6px 8px; border: 1px solid var(--line2, #333); border-radius: 8px; }
.tx-more[hidden] { display: none; }
.tx-cols { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.tx-cols input[type=color] { width: 30px; height: 26px; padding: 0; border: 1px solid var(--line2, #333); border-radius: 6px; background: none; }
.tx-cols input.tx-hex { width: 84px; height: 26px; min-height: 26px; padding: 0 6px; font-size: 12px; font-family: ui-monospace, Menlo, monospace; }
.tx-chip { width: 24px; height: 24px; min-height: 24px; padding: 0; border-radius: 50%; border: 2px solid var(--line2, #444); }
.tx-chip.on { border-color: var(--text, #fff); }
.tx-cols button.small { height: 26px; min-height: 26px; padding: 0 8px; font-size: 12px; }
.tx-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 4px; }
.tx-btns button { min-height: 30px; }
.tx-note { display: block; margin: 2px 0 6px; }
`;
let styled = false;
const injectCss = () => { if (styled) return; styled = true; document.head.appendChild(h('style', { id: 'kbb-theme-text-css' }, STYLE_CSS)); };

/** a slider's range input marked with a data attribute (the search index and the checks find it by it) */
const tag = (el, k) => { const i = el.querySelector('input'); if (i) i.dataset[k] = '1'; return el; };
/** the company's colours (unique), then white and near-black */
function chipColours(ws) {
  const c = ws?.colors || {}, out = [];
  for (const v of [c.primary, c.secondary, c.accent, c.text, c.bg, '#ffffff', '#111111']) { const x = v && toHex(v); if (x && !out.includes(x)) out.push(x); }
  return out.slice(0, 7);
}
/** a colour picker: company chips, the swatch, a hex box and a reset; onPick(hex|null, done) */
function colourRow(label, value, ws, onPick, resetLabel = 'Theme', data = {}) {
  const v = value ? toHex(value) : null;
  const sw = h('input', { type: 'color', value: v || '#ffffff', 'aria-label': label + ' colour', title: label + ' colour' });
  const hex = h('input.tx-hex', { type: 'text', value: v ? v.toUpperCase() : '', placeholder: resetLabel, maxlength: 7, spellcheck: false, 'aria-label': label + ' hex' });
  sw.addEventListener('input', () => { hex.value = sw.value.toUpperCase(); onPick(sw.value, false); });
  sw.addEventListener('change', () => onPick(sw.value, true));
  hex.addEventListener('keydown', (e) => e.stopPropagation());
  hex.addEventListener('change', () => { const x = hex.value.trim(); if (!x) return onPick(null, true); const y = /^#?[0-9a-f]{6}$/i.test(x) ? '#' + x.replace('#', '') : null; if (y) { sw.value = y.toLowerCase(); onPick(y.toLowerCase(), true); } else hex.value = v ? v.toUpperCase() : ''; });
  const chips = chipColours(ws).map((c) => h('button.tx-chip' + (v === c ? '.on' : ''), { type: 'button', title: `${label}: ${c.toUpperCase()}`, 'aria-label': `${label} ${c}`, 'data-sample': '1', style: { background: c }, on: { click: () => onPick(c, true) } }));
  return h('div.row', { dataset: data }, h('span.lbl', label), h('div.tx-cols', chips, sw, hex, h('button.btn.small', { type: 'button', title: `${label}: back to the ${resetLabel.toLowerCase()}`, disabled: !v, on: { click: () => onPick(null, true) } }, '↺')));
}

/**
 * The Text and Text style sections for a theme item. insp is the Inspector (ed, el, section(), render()).
 * Returns the sections (the caller appends them).
 */
export function themeTextSections(insp, item) {
  injectCss();
  const ed = insp.ed, id = item.id, ws = ed.workspace || {}, s = item.style || {}, ts = s.tstyle || {};
  const fields = fieldsFor(item, ed.project), out = [];
  const burst = new Burst(ed);
  const rows = [];
  const fieldBox = (f) => {
    const long = f.key === 'crawl' || f.key === 'note' || (f.value || '').length > 46;
    const inp = h(long ? 'textarea' : 'input', { ...(long ? { rows: 2 } : { type: 'text' }), value: f.value, placeholder: 'Empty: this line is hidden', dir: f.ar ? 'rtl' : 'auto', lang: f.ar ? 'ar' : undefined, spellcheck: !f.ar, 'aria-label': f.label, title: f.label + (f.def && f.def !== f.value ? ` · default “${f.def}”` : ''), dataset: { thText: f.key } });
    let orig;
    inp.addEventListener('focus', () => { orig = curItem(ed, id)?.style?.text?.[f.key]; inp.closest('.tx-field')?.classList.add('on'); });
    inp.addEventListener('input', () => { const v = inp.value; burst.write(() => writeWords(ed, id, f.key, v)); reset.disabled = false; });
    inp.addEventListener('change', () => burst.end());
    inp.addEventListener('blur', () => { burst.end(); inp.closest('.tx-field')?.classList.remove('on'); });
    inp.addEventListener('keydown', (e) => {
      e.stopPropagation();
      if (e.key === 'Enter' && !(long && e.shiftKey)) { e.preventDefault(); inp.blur(); }
      if (e.key === 'Escape') { e.preventDefault(); burst.next = null; if (burst.n) { writeWords(ed, id, f.key, orig ?? null, 'Element text'); burst.end(); } inp.value = orig ?? f.value; inp.blur(); }
    });
    const reset = h('button.btn.small', { type: 'button', title: `Reset to default${f.def ? ` (“${f.def}”)` : ''}`, 'aria-label': 'Reset ' + f.label, disabled: !f.user, dataset: { thReset: f.key }, on: { click: () => { burst.end(); writeWords(ed, id, f.key, null, 'Reset text'); ed.history.seal(); insp.render(true); } } }, '↺');
    // Aa: this field's own size and colour
    const sz = +(ts.sizes?.[f.key]) || 1, col = ts.colors?.[f.key] || null;
    const more = h('div.tx-more', { hidden: !(sz !== 1 || col) },
      slider({ label: 'Size', min: 0.5, max: 2, step: 0.05, value: sz, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => writeTStyle(ed, id, { sizes: { ...(curItem(ed, id)?.style?.tstyle?.sizes || {}), [f.key]: v } }, 'Text size'), onDone: () => ed.history.seal() }),
      colourRow('Colour', col, ws, (c, done) => { const cur = { ...(curItem(ed, id)?.style?.tstyle?.colors || {}) }; if (c) cur[f.key] = c; else delete cur[f.key]; writeTStyle(ed, id, { colors: cur }, 'Text colour'); if (done) { ed.history.seal(); if (!c) insp.render(true); } }, 'Element colour', { thCol: 'field' }));
    const aa = h('button.btn.small', { type: 'button', dataset: { thAa: f.key }, title: f.label + ': its own size and colour', 'aria-label': f.label + ' size and colour', 'aria-expanded': String(!more.hidden), on: { click: () => { more.hidden = !more.hidden; aa.setAttribute('aria-expanded', String(!more.hidden)); } } }, 'Aa');
    return { inp, head: h('div.tx-head', h('span.lbl', f.label), reset, aa), more };
  };
  for (const f of fields) {
    if (f.pair) continue;   // drawn next to its English field
    const a = fieldBox(f), ar = fields.find((x) => x.pair === f.key);
    if (ar) {
      const b = fieldBox(ar);
      rows.push(h('div.tx-field', { dataset: { thField: f.key } }, h('div.tx-pair', h('div', a.head), h('div', b.head)), h('div.tx-pair', a.inp, b.inp), a.more, b.more));
    } else rows.push(h('div.tx-field', { dataset: { thField: f.key } }, a.head, a.inp, a.more));
  }
  if (!fields.length) rows.push(h('small.muted.tx-note', 'This element draws no words.'));
  else if (fields.some((f) => /phone|value/.test(f.key))) rows.push(h('small.muted.tx-note', 'Placeholders: put in your own details. Nothing here states a visa rule, a fee or a salary.'));
  rows.push(h('small.muted.tx-note', 'Double-click a word on the video to edit it there.'));
  // + Add line · + Add text · the Text tool
  const nl = nextLine(item);
  const addLine = nl ? h('button.btn', { type: 'button', title: 'Add another line to this element', dataset: { thAddLine: nl.key }, on: { click: () => { writeWords(ed, id, nl.key, nl.words, 'Add line'); ed.history.seal(); insp.render(true); setTimeout(() => focusThemeField(insp, nl.key, true), 0); } } }, item.style?.el === 'visa' ? '+ Add point' : '+ Add line') : null;
  const addText = item.type === 'theme.trans' ? null : h('button.btn', { type: 'button', title: 'A free Text item on this element, moving and timed with it', dataset: { thAddText: '1' }, on: { click: () => addLinkedText(ed, id) } }, '+ Add text');
  const tool = h('button.btn', { type: 'button', title: 'Open the Text tool (titles, captions, call to action)', dataset: { thTextTool: '1' }, on: { click: () => { ed.bin?.show?.('text'); } } }, 'T Text tool');
  rows.push(h('div.tx-btns', addLine, addText, tool));
  const sec = insp.section('Text', ...rows); sec.dataset.thTextSec = '1';
  out.push(sec);
  if (fields.length) out.push(styleSection(insp, item));
  return out;
}

function styleSection(insp, item) {
  const ed = insp.ed, id = item.id, ws = ed.workspace || {}, s = item.style || {}, ts = s.tstyle || {};
  const set = (patch, label, done = true) => { writeTStyle(ed, id, patch, label); if (done) ed.history.seal(); };
  const brandFonts = [ws.fonts?.heading, ws.fonts?.body].filter((f) => f && typeof f === 'string');
  const fonts = [...new Set([...brandFonts, ...FONT_FAMILIES])];
  const fontSel = h('select', { 'aria-label': 'Font', title: 'Font for every word of this element', dataset: { thFont: '1' } },
    h('option', { value: '', selected: !ts.font }, 'Theme font'),
    ...fonts.map((f) => h('option', { value: f, selected: ts.font === f, style: { fontFamily: `"${f}"` } }, brandFonts.includes(f) ? `${f} · company` : f)));
  fontSel.addEventListener('change', () => { const f = fontSel.value || null; set({ font: f }, 'Font'); if (f) loadFont(f).then(() => ed.preview?.draw?.()); });
  const colors = s.colors || {}, th = themeById(s.theme) || null;
  const band = (k, label) => colourRow(label, colors[k], ws, (c, done) => {
    writeStyle(ed, id, (st) => { const base = st.colors || (th ? colorsFor(th, 'theme') : {}); st.colors = { ...base, [k]: c || (th ? colorsFor(th, 'theme')[k] : base[k]) }; }, 'Element colours');
    if (done) ed.history.seal();
  }, 'Theme', { thCol: k });
  const sec = insp.section('Text style',
    field('Font', fontSel),
    tag(slider({ label: 'Size', min: 0.5, max: 2, step: 0.05, value: +ts.scale || 1, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => set({ scale: Math.abs(v - 1) < 0.001 ? null : v }, 'Text size', false), onDone: () => ed.history.seal() }), 'thScale'),
    field('Weight', seg([['', 'Theme'], ['400', 'Regular'], ['800', 'Bold'], ['900', 'Black']], String(ts.weight || ''), (v) => set({ weight: v ? +v : null }, 'Text weight'), '.tx-weight')),
    field('Case', seg([['', 'Theme'], ['upper', 'UPPER'], ['title', 'Title'], ['asis', 'As typed']], ts.case || '', (v) => set({ case: v || null }, 'Text case'), '.tx-case')),
    field('Align', seg([['', 'Theme'], ['left', '⯇', 'Left'], ['center', '≡', 'Centre'], ['right', '⯈', 'Right']], ts.align || '', (v) => set({ align: v || null }, 'Text alignment'), '.tx-align')),
    tag(slider({ label: 'Spacing', min: -0.05, max: 0.4, step: 0.01, value: ts.track ?? 0, fmt: (v) => (v * 100).toFixed(0) + '%', onInput: (v) => set({ track: v }, 'Letter spacing', false), onDone: () => ed.history.seal() }), 'thTrack'),
    h('div.checks',
      h('label.check', h('input', { type: 'checkbox', checked: !!ts.italic, dataset: { thItalic: '1' }, on: { change: (e) => set({ italic: e.target.checked || null }, 'Italic') } }), 'Italic'),
      h('label.check', h('input', { type: 'checkbox', checked: !!ts.outline, dataset: { thOutline: '1' }, on: { change: (e) => set({ outline: e.target.checked || null }, 'Outline') } }), 'Outline')),
    field('Shadow', seg([['', 'Theme'], ['on', 'On'], ['off', 'Off']], ts.shadow || '', (v) => set({ shadow: v || null }, 'Text shadow'), '.tx-shadow')),
    colourRow('Text', ts.color, ws, (c, done) => set({ color: c }, 'Text colour', done), 'Theme', { thCol: 'text' }),
    band('primary', 'Band'), band('accent', 'Accent'),
    h('div.tx-btns',
      h('button.btn', { type: 'button', title: 'Draw this element in its theme’s own colours again', dataset: { thThemeColours: '1' }, disabled: !th, on: { click: () => { writeStyle(ed, id, (st) => { st.colors = colorsFor(th, 'theme'); }, 'Use theme colours'); ed.history.seal(); insp.render(true); } } }, 'Use theme colours'),
      h('button.btn', { type: 'button', title: 'Put every text-style choice back to the theme’s', dataset: { thStyleReset: '1' }, disabled: !s.tstyle, on: { click: () => { writeStyle(ed, id, (st) => { delete st.tstyle; }, 'Reset text style'); ed.history.seal(); insp.render(true); } } }, 'Reset text style')),
  );
  sec.dataset.thStyleSec = '1';
  return sec;
}

/** make a font usable before the next frame (bundled ones are local files; a company font may be a system font) */
export function loadFont(f) {
  if (typeof document === 'undefined' || !document.fonts) return Promise.resolve();
  return (FONT_FAMILIES.includes(f) ? loadCaptionFonts() : Promise.resolve()).then(() => document.fonts.load(`700 40px "${f}"`)).catch(() => {});
}

/** "+ Add text": a normal Text item on the element (same start and length, just below it, or inside when no room) */
export async function addLinkedText(ed, elId) {
  const el = curItem(ed, elId); if (!el) return null;
  const { textItem } = await import('../editor/items.js');
  const cv = ed.project.canvas, it = textItem(ed.project, 'subtitle', el.t0, ed.workspace);
  it.text = 'Your text'; it.name = 'Text · ' + (el.name || 'theme element').replace(/^[^\w]+/, '');
  it.out = it.in + itemDur(el); it.w = Math.round(Math.min(el.w, cv.w * 0.9)); it.x = Math.round(el.x + (el.w - it.w) / 2);
  const gap = Math.round(cv.h * 0.01);
  it.y = el.y + el.h + gap + it.h <= cv.h ? el.y + el.h + gap : el.y - it.h - gap >= 0 ? el.y - it.h - gap : Math.round(el.y + (el.h - it.h) / 2);
  it.link = el.id;   // themes/text-link.js keeps it on the element when the element moves or is re-timed
  ed.addItem(it, 'text');
  ed.history.seal();
  ed.inspector?.focusText?.();
  return it;
}

/** put the cursor in a field and flash it (double-click on the canvas, + Add line) */
export function focusThemeField(insp, key, select = false) {
  const inp = insp.el.querySelector(`[data-th-text="${CSS.escape(key)}"]`); if (!inp) return false;
  const sec = inp.closest('details'); if (sec) sec.open = true;
  inp.scrollIntoView({ block: 'center' });
  inp.focus({ preventScroll: true }); if (select) inp.select?.();
  const box = inp.closest('.tx-field'); box?.classList.add('flash'); setTimeout(() => box?.classList.remove('flash'), 1200);
  return true;
}
