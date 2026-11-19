// KBB Studio — the inspector: settings for whatever is selected (clip, text, shape, sticker, sound, many items)
// or the project itself when nothing is.

import { ops } from '../core/history.js';
import { findMedia, fitBox, itemDur } from '../core/project.js';
import { FILTER_PRESETS, boxAt } from '../core/render.js';
import { h, clear, slider, field, select, seg, fmtDur } from './ui.js';
import { STICKERS } from './items.js';
import { bounds } from './preview.js';
import { speedSection } from './speed.js';
import { inspectorSections } from '../core/modules.js';
// the design-family elements (their placeholder words and trades), read once the themes module has loaded
let FAM_ELS = null; import('../themes/elements.js').then((m) => { FAM_ELS = m.ELEMENTS.filter((d) => d.fam); }).catch(() => {});
// the capsules carousel's own sections (themes/capsules-panel.js), loaded with the editor so the inspector builds them in step
let CAPS_PANEL = null; import('../themes/capsules-panel.js').then((m) => { CAPS_PANEL = m; }).catch(() => {});
// a theme element's Text and Text style sections (themes/text-panel.js), loaded with the editor the same way
let TEXT_PANEL = null; import('../themes/text-panel.js').then((m) => { TEXT_PANEL = m; }).catch(() => {});
// contact cards, the logo badge, country badges and the cut-out transitions (themes/el2-panel.js), loaded the same way
let EL2_PANEL = null; import('../themes/el2-panel.js').then((m) => { EL2_PANEL = m; }).catch(() => {});

const FONTS = ['system-ui', 'Helvetica Neue', 'Avenir Next', 'Futura', 'Gill Sans', 'Georgia', 'Didot', 'Baskerville', 'Times New Roman', 'Courier New', 'Arial Rounded MT Bold', 'Impact', 'Geeza Pro', 'Damascus', 'Baghdad'];
const pct = (v) => Math.round(v * 100) + '%';
const secs = (v) => (Math.round(v * 10) / 10).toFixed(1) + ' s';

function toHex(c) {
  if (!c) return '#000000';
  if (/^#[0-9a-f]{6}$/i.test(c)) return c;
  if (/^#[0-9a-f]{3}$/i.test(c)) return '#' + c.slice(1).split('').map((x) => x + x).join('');
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(c);
  return m ? '#' + [m[1], m[2], m[3]].map((x) => (+x).toString(16).padStart(2, '0')).join('') : '#000000';
}

export class Inspector {
  constructor(ed, el) { this.ed = ed; this.el = el; this.key = ''; }

  render(force = false) {
    const ed = this.ed, hits = ed.selectedItems();
    const key = hits.map((x) => x.item.id + ':' + x.item.type).join(',') + '|' + ed.project.canvas.aspect + '|' + ed.project.media.length;
    // don't rebuild under the owner's fingers while they drag a slider or type here
    if (!force && key === this.key && this.el.contains(document.activeElement)) return;
    this.key = key;
    const scroll = this.el.scrollTop;
    clear(this.el);
    if (!hits.length) this.projectPanel();
    else if (hits.length > 1) this.multiPanel(hits);
    else this.itemPanel(hits[0]);
    this.el.scrollTop = scroll;
  }

  focusText() { this.render(true); this.el.querySelector('textarea')?.focus(); }
  /** a theme element's field for key: focused and flashed (a double-click on its words on the video) */
  focusThemeText(key) { return !!TEXT_PANEL?.focusThemeField(this, key); }

  /** the one-item header's line: name · length (· locked) */
  headText(item, track, m) { return (m?.name || item.name || '') + ' · ' + fmtDur(itemDur(item)) + (track.locked ? ' · 🔒 locked track' : ''); }
  /** re-read the header from the project while a control keeps the panel from rebuilding (a drag inside it) */
  refreshHead() {
    const hits = this.ed.selectedItems(), el = this.el.querySelector('.insp-head small'); if (hits.length !== 1 || !el) return;
    const { item, track } = hits[0]; el.textContent = this.headText(item, track, item.media ? findMedia(this.ed.project, item.media) : null);
  }

  set(item, props, label) { this.ed.apply(ops.setItems([{ id: item.id, props }], label)); }
  seal = () => this.ed.history.seal();

  section(title, ...kids) { return h('details.sec', { open: true }, h('summary', title), h('div.sec-body', kids)); }

  // ── nothing selected: the project ──
  projectPanel() {
    const ed = this.ed, p = ed.project, c = p.canvas;
    this.el.append(
      h('div.insp-head', h('b', 'Project'), h('small', `${c.w}×${c.h} · ${c.fps} fps · ${fmtDur(ed.duration())}`)),
      this.section('Format',
        field('Frame rate', seg([['30', '30 fps'], ['60', '60 fps']], String(c.fps), (v) => ed.apply(ops.setCanvas({ fps: +v }, 'Frame rate')))),
        field('Background', h('input', { type: 'color', value: toHex(c.bg), on: { input: (e) => ed.apply(ops.setCanvas({ bg: e.target.value }, 'Background')), change: this.seal } })),
      ),
      this.section('Tips',
        h('ul.tips',
          h('li', 'Drop videos, photos or music anywhere.'),
          h('li', 'Drag on the video to box-select several things at once.'),
          h('li', 'S splits at the playhead · Shift+Delete closes the gap.'),
          h('li', 'Everything can be undone with ⌘Z.'))),
    );
  }

  // ── several selected ──
  multiPanel(hits) {
    const ed = this.ed, t = ed.t;
    const align = (mode) => {
      const boxes = hits.map((x) => ({ id: x.item.id, ...boxAt(x.item, t) }));
      const bb = bounds(boxes);
      const props = boxes.map((b) => {
        const o = {};
        if (mode === 'l') o.x = bb.x; if (mode === 'r') o.x = bb.x + bb.w - b.w; if (mode === 'c') o.x = Math.round(bb.x + bb.w / 2 - b.w / 2);
        if (mode === 't') o.y = bb.y; if (mode === 'b') o.y = bb.y + bb.h - b.h; if (mode === 'm') o.y = Math.round(bb.y + bb.h / 2 - b.h / 2);
        return { id: b.id, props: o };
      });
      ed.apply(ops.setItems(props, 'Align'));
    };
    const distribute = (axis) => {
      const boxes = hits.map((x) => ({ id: x.item.id, ...boxAt(x.item, t) })).sort((a, b) => (axis === 'h' ? a.x - b.x : a.y - b.y));
      if (boxes.length < 3) return;
      const first = boxes[0], last = boxes[boxes.length - 1];
      const span = axis === 'h' ? last.x + last.w - first.x : last.y + last.h - first.y;
      const total = boxes.reduce((s, b) => s + (axis === 'h' ? b.w : b.h), 0);
      const gap = (span - total) / (boxes.length - 1);
      let pos = axis === 'h' ? first.x : first.y;
      ed.apply(ops.setItems(boxes.map((b) => { const o = axis === 'h' ? { x: Math.round(pos) } : { y: Math.round(pos) }; pos += (axis === 'h' ? b.w : b.h) + gap; return { id: b.id, props: o }; }), 'Distribute'));
    };
    const grouped = hits.every((x) => x.item.group && x.item.group === hits[0].item.group);
    this.el.append(
      h('div.insp-head', h('b', `${hits.length} items selected`), h('small', grouped ? 'Grouped' : 'Shift-click to add or remove')),
      this.section('Align',
        h('div.btn-grid',
          h('button.tool', { title: 'Align left edges', on: { click: () => align('l') } }, '⇤ Left'),
          h('button.tool', { title: 'Align centres', on: { click: () => align('c') } }, '↔ Centre'),
          h('button.tool', { title: 'Align right edges', on: { click: () => align('r') } }, '⇥ Right'),
          h('button.tool', { title: 'Align tops', on: { click: () => align('t') } }, '⤒ Top'),
          h('button.tool', { title: 'Align middles', on: { click: () => align('m') } }, '↕ Middle'),
          h('button.tool', { title: 'Align bottoms', on: { click: () => align('b') } }, '⤓ Bottom'))),
      this.section('Distribute',
        h('div.btn-grid.two',
          h('button.tool', { disabled: hits.length < 3, title: 'Equal gaps left to right (3 or more)', on: { click: () => distribute('h') } }, '⋯ Horizontally'),
          h('button.tool', { disabled: hits.length < 3, title: 'Equal gaps top to bottom (3 or more)', on: { click: () => distribute('v') } }, '⋮ Vertically'))),
      this.section('Arrange',
        h('div.btn-grid.two',
          grouped ? h('button.tool', { on: { click: () => ed.ungroup() } }, '⊟ Ungroup') : h('button.tool', { on: { click: () => ed.group() } }, '⊞ Group'),
          h('button.tool.danger', { on: { click: () => ed.deleteSelection() } }, '🗑 Delete'))),
    );
  }

  // ── one item ──
  itemPanel({ item, track }) {
    const ed = this.ed, m = item.media ? findMedia(ed.project, item.media) : null;
    const title = { clip: m?.kind === 'image' ? 'Picture' : 'Video clip', audio: track.kind === 'sfx' ? 'Sound effect' : 'Music', text: 'Text', shape: 'Shape', sticker: 'Sticker', caption: 'Captions', 'auto.transition': 'Transition', 'theme.el': 'Theme element', 'theme.sting': 'Theme intro', 'theme.outro': 'Theme end card', 'theme.overlay': 'Theme overlay', 'auto.pricetag': 'Price tag', 'auto.logo': 'Logo sting', 'auto.endcard': 'End card' }[item.type] || item.type;
    this.el.append(h('div.insp-head', h('b', title), h('small', this.headText(item, track, m))));
    if (item.type === 'text') this.textPanel(item);
    if (item.type === 'shape') this.shapePanel(item);
    if (item.type === 'sticker') this.stickerPanel(item);
    if (/^theme\./.test(item.type)) this.themePanel(item, track);
    if (item.type === 'clip' || item.type === 'audio') this.timingPanel(item, m);
    if (m?.hasAudio && (item.type === 'clip' || item.type === 'audio')) this.audioPanel(item);
    for (const sec of inspectorSections(item, track, m)) { try { const el = sec.render(ed.context(), item, track, m); if (el) this.el.append(el); } catch (e) { console.warn('[inspector]', sec.id, e); } }
    if (item.type === 'clip') { this.lookPanel(item); this.cropZoomPanel(item, m); }
    // text behind the host: its own editor beside the video holds position, size, rotation and opacity
    const tbehind = item.type === 'theme.el' && item.style?.el === 'tbehind';
    if (item.type !== 'audio' && item.type !== 'caption' && !tbehind) this.transformPanel(item, m);
    if (item.type !== 'audio' && !(m?.hasAudio) && !tbehind) this.fadePanel(item);
    if (item.type === 'caption') this.el.append(this.section('Captions', h('p.muted', 'Caption styles and the transcript are edited in Auto captions.')));
  }

  timingPanel(item, m) {
    // speed, ramps (presets + curve), smooth slow motion, reverse, freeze, sound options — editor/speed.js
    this.el.append(speedSection(this, item, m));
  }

  audioPanel(item) {
    this.el.append(this.section('Sound',
      slider({ label: 'Volume', min: 0, max: 2, step: 0.01, value: item.volume ?? 1, fmt: pct, onInput: (v) => this.set(item, { volume: v }, 'Volume'), onDone: this.seal }),
      h('label.row.check', h('input', { type: 'checkbox', checked: !!item.muted, on: { change: (e) => { this.set(item, { muted: e.target.checked }, 'Mute'); this.seal(); } } }), h('span', 'Mute this clip')),
      slider({ label: 'Fade in', min: 0, max: 3, step: 0.1, value: item.fadeIn || 0, fmt: secs, onInput: (v) => this.set(item, { fadeIn: v }, 'Fade in'), onDone: this.seal }),
      slider({ label: 'Fade out', min: 0, max: 3, step: 0.1, value: item.fadeOut || 0, fmt: secs, onInput: (v) => this.set(item, { fadeOut: v }, 'Fade out'), onDone: this.seal }),
    ));
  }

  /** a country transition on a cut: its length (it stays centred on the cut) and which switch it is */
  transPanel(item) {
    const ctx = this.ed.context();
    import('../themes/transitions.js').then((T) => {
      const len = h('input', { type: 'number', step: 0.1, min: 0.2, max: 2.5, value: (item.out - item.in).toFixed(2), dataset: { th: 'duration' }, title: 'Length of the switch, centred on the cut' });
      len.addEventListener('change', () => { const v = +len.value; if (Number.isFinite(v)) { T.setTransitionDuration(ctx, item.id, v); this.seal(); } });
      len.addEventListener('keydown', (e) => e.stopPropagation());
      const kind = select(T.TRANSITIONS.map((x) => [x.id, x.label]), item.style?.kind || 'slabs', (v) => { const style = JSON.parse(JSON.stringify(item.style || {})); style.kind = v; this.set(item, { style, name: `${T.TRANSITIONS.find((x) => x.id === v)?.icon || ''} ${T.TRANSITIONS.find((x) => x.id === v)?.label || v}` }, 'Transition kind'); this.seal(); });
      kind.dataset.th = 'kind';
      this.el.append(this.section('Country transition', field('Duration', len), field('Switch', kind), h('small.muted', 'It sits on the cut between two clips: both clips show through it. Delete removes it; Themes → Transitions puts one on every cut.')));
      if (EL2_PANEL && /^cut-/.test(item.style?.kind || '')) this.el.append(EL2_PANEL.cutTransSection(this, item));
      // a page turn or a boarding pass draws words: they are edited like an element's
      if (TEXT_PANEL && TEXT_PANEL.fieldsFor(item, this.ed.project).length) this.el.append(...TEXT_PANEL.themeTextSections(this, item));
    });
  }

  /** a theme element: start and duration as numbers, its placeholder words, the sticker kind */
  /** a design-family element's catalogue entry (its words and trades); the themes module is loaded with the editor */
  famDef(el) { return /^fam-/.test(el || '') ? (FAM_ELS || []).find((d) => d.id === el) || null : null; }

  themePanel(item, track) {
    if (item.type === 'theme.trans') return this.transPanel(item);
    const ed = this.ed, num = (label, value, min, onSet, title) => field(label, h('input', { type: 'number', step: 0.1, min, value: (+value).toFixed(2), title, dataset: { th: label.toLowerCase() }, on: { change: (e) => { const v = +e.target.value; if (Number.isFinite(v) && v >= min) { onSet(v); this.seal(); } } } }));
    const dur = itemDur(item);
    const kids = [
      num('Start', item.t0, 0, (v) => this.set(item, { t0: v }, 'Element start'), 'When it starts on the timeline (seconds)'),
      num('Duration', dur, 0.2, (v) => this.set(item, { out: item.in + v * (item.speed || 1) }, 'Element duration'), 'How long it runs; the animation re-times itself'),
    ];
    if (item.auto === 'theme') kids.push(h('small.muted', item.style?.whole ? 'Whole video: follows the reel’s length (your words stay when the theme is applied again). Switch it off in Themes → Place elements.' : 'Placed by the theme: applying a theme again replaces it but keeps your words.'));
    const el = item.type === 'theme.sting' ? 'intro' : item.type === 'theme.outro' ? 'end' : item.style?.el;
    if (el === 'sticker') {
      const vs = ['flag', 'visa', 'plane', 'pin', 'coin', 'landmark', 'sparkle'];
      kids.push(field('Sticker', select(vs.map((v) => [v, v[0].toUpperCase() + v.slice(1)]), item.style?.variant || 'flag', (v) => { const style = JSON.parse(JSON.stringify(item.style || {})); style.variant = v; this.set(item, { style }, 'Sticker'); this.seal(); })));
    }
    const fd = this.famDef?.(el);
    if (fd?.variants) kids.push(field('Trade', select(fd.variants.map((v) => [v, fd.variantLabels?.[v] || v]), item.style?.variant || fd.variants[0], (v) => { const style = JSON.parse(JSON.stringify(item.style || {})); style.variant = v; this.set(item, { style }, 'Trade'); this.seal(); })));
    this.el.append(this.section('Theme element', ...kids));
    if (EL2_PANEL?.handles(el)) this.el.append(...EL2_PANEL.inspectorSections(this, item));
    // every word it draws, prefilled and live, plus its text style (themes/text-panel.js)
    // (text behind the host: a summary and "✎ Edit text behind…" only; its words are in its own editor)
    if (el !== 'capsules' && el !== 'tbehind' && TEXT_PANEL) this.el.append(...TEXT_PANEL.themeTextSections(this, item));
    if (el === 'capsules' && CAPS_PANEL) { const box = h('div', { dataset: { capsInsp: item.id } }); this.el.append(box); CAPS_PANEL.capsulesInspector(this, item, box); }
  }

  fadePanel(item) {
    this.el.append(this.section('Fade',
      slider({ label: 'Fade in', min: 0, max: 3, step: 0.1, value: item.fadeIn || 0, fmt: secs, onInput: (v) => this.set(item, { fadeIn: v }, 'Fade in'), onDone: this.seal }),
      slider({ label: 'Fade out', min: 0, max: 3, step: 0.1, value: item.fadeOut || 0, fmt: secs, onInput: (v) => this.set(item, { fadeOut: v }, 'Fade out'), onDone: this.seal }),
    ));
  }

  lookPanel(item) {
    const get = (type, d) => (item.fx || []).find((f) => f.type === type)?.v ?? d;
    const setFx = (type, v, d) => {
      const fx = (item.fx || []).filter((f) => f.type !== type);
      if (Math.abs(v - d) > 1e-3) fx.push({ type, v });
      this.set(item, { fx }, 'Adjust');
    };
    const chips = h('div.chips', Object.keys(FILTER_PRESETS).map((name) => h('button.chip' + (item.look === name || (!item.look && name === 'none') ? '.on' : ''), {
      // a filter preset replaces only the colour filters: other effects on the clip (punch-ins, GPU looks) stay
      on: { click: () => { const keep = (item.fx || []).filter((f) => !['brightness', 'contrast', 'saturate', 'hue', 'blur', 'grayscale', 'sepia', 'warmth'].includes(f.type)); this.set(item, { fx: [...keep, ...structuredClone(FILTER_PRESETS[name])], look: name }, 'Filter'); this.seal(); this.render(true); } },
    }, name[0].toUpperCase() + name.slice(1))));
    // the quick CSS filters (older projects use them); the GPU sliders live in 🎚 Adjust above, so this stays folded
    // unless the clip already uses one
    const used = (item.fx || []).some((f) => ['brightness', 'contrast', 'saturate', 'hue', 'blur', 'grayscale', 'sepia', 'warmth'].includes(f.type));
    const sec = this.section('Quick filters',
      chips,
      slider({ label: 'Brightness', min: 0.5, max: 1.5, value: get('brightness', 1), fmt: pct, onInput: (v) => setFx('brightness', v, 1), onDone: this.seal }),
      slider({ label: 'Contrast', min: 0.5, max: 1.5, value: get('contrast', 1), fmt: pct, onInput: (v) => setFx('contrast', v, 1), onDone: this.seal }),
      slider({ label: 'Saturation', min: 0, max: 2, value: get('saturate', 1), fmt: pct, onInput: (v) => setFx('saturate', v, 1), onDone: this.seal }),
      slider({ label: 'Warmth', min: -1, max: 1, value: get('warmth', 0), fmt: (v) => (v > 0 ? '+' : '') + Math.round(v * 100), onInput: (v) => setFx('warmth', v, 0), onDone: this.seal }),
      slider({ label: 'Blur', min: 0, max: 30, step: 0.5, value: get('blur', 0), fmt: (v) => v + ' px', onInput: (v) => setFx('blur', v, 0), onDone: this.seal }),
    );
    sec.open = used;
    this.el.append(sec);
  }

  cropZoomPanel(item, m) {
    const c = item.crop || { l: 0, t: 0, r: 0, b: 0 };
    const setCrop = (k, v) => this.set(item, { crop: Object.assign({}, item.crop || { l: 0, t: 0, r: 0, b: 0 }, { [k]: v }) }, 'Crop');
    const z = item.zoom || { from: 1, to: 1, cx: 0.5, cy: 0.5 };
    const setZoom = (props) => this.set(item, { zoom: Object.assign({ from: 1, to: 1, cx: 0.5, cy: 0.5 }, item.zoom || {}, props) }, 'Zoom');
    this.el.append(
      this.section('Zoom (Ken Burns)',
        seg([['none', 'None'], ['in', 'Slow zoom in'], ['out', 'Slow zoom out'], ['punch', 'Punch in']], z.from === 1 && z.to === 1 ? 'none' : z.to > z.from ? 'in' : z.from > z.to ? 'out' : 'punch', (v) => {
          const map = { none: { from: 1, to: 1 }, in: { from: 1, to: 1.18 }, out: { from: 1.18, to: 1 }, punch: { from: 1.3, to: 1.3 } };
          setZoom(map[v]); this.seal(); this.render(true);
        }),
        slider({ label: 'Start', min: 1, max: 2, value: z.from, fmt: (v) => v.toFixed(2) + '×', onInput: (v) => setZoom({ from: v }), onDone: this.seal }),
        slider({ label: 'End', min: 1, max: 2, value: z.to, fmt: (v) => v.toFixed(2) + '×', onInput: (v) => setZoom({ to: v }), onDone: this.seal }),
      ),
      this.section('Crop',
        ...[['l', 'Left'], ['r', 'Right'], ['t', 'Top'], ['b', 'Bottom']].map(([k, l]) => slider({ label: l, min: 0, max: 0.45, value: c[k] || 0, fmt: pct, onInput: (v) => setCrop(k, v), onDone: this.seal })),
      ),
    );
  }

  transformPanel(item, m) {
    const ed = this.ed, cv = ed.project.canvas;
    const num = (k, label) => h('label.num', h('span', label), h('input', { type: 'number', value: Math.round(item[k]), step: 1, on: { change: (e) => { this.set(item, { [k]: +e.target.value || 0 }, 'Position'); this.seal(); } } }));
    const fit = (mode) => { const b = fitBox(cv, m?.w || item.w, m?.h || item.h, mode); this.set(item, b, mode === 'cover' ? 'Fill frame' : 'Fit frame'); this.seal(); this.render(true); };
    this.el.append(this.section('Position & size',
      h('div.nums', num('x', 'X'), num('y', 'Y'), num('w', 'W'), num('h', 'H')),
      h('div.btn-grid',
        m ? h('button.tool', { title: 'Fill the frame (crop the edges)', on: { click: () => fit('cover') } }, '⛶ Fill') : null,
        m ? h('button.tool', { title: 'Fit inside the frame', on: { click: () => fit('contain') } }, '▭ Fit') : null,
        h('button.tool', { title: 'Centre in the frame', on: { click: () => { this.set(item, { x: Math.round((cv.w - item.w) / 2), y: Math.round((cv.h - item.h) / 2) }, 'Centre'); this.seal(); this.render(true); } } }, '⌖ Centre')),
      slider({ label: 'Rotate', min: -180, max: 180, step: 1, value: item.rot || 0, fmt: (v) => Math.round(v) + '°', onInput: (v) => this.set(item, { rot: v }, 'Rotate'), onDone: this.seal }),
      slider({ label: 'Opacity', min: 0, max: 1, value: item.opacity ?? 1, fmt: pct, onInput: (v) => this.set(item, { opacity: v }, 'Opacity'), onDone: this.seal }),
    ));
  }

  textPanel(item) {
    const s = item.style || {};
    const setS = (props, label = 'Text style') => this.set(item, { style: Object.assign({}, item.style, props) }, label);
    const ta = h('textarea.text-edit', { rows: 3, value: item.text || '', 'aria-label': 'Text' });
    ta.addEventListener('input', () => this.set(item, { text: ta.value }, 'Type'));
    ta.addEventListener('change', this.seal);
    const color = (k, label) => h('label.swatch', h('input', { type: 'color', value: toHex(s[k] || '#ffffff'), on: { input: (e) => setS({ [k]: e.target.value }), change: this.seal } }), h('small', label));
    this.el.append(
      this.section('Text', ta,
        field('Font', select(FONTS.map((f) => [f, f]), s.font || 'system-ui', (v) => { setS({ font: v }); this.seal(); })),
        slider({ label: 'Size', min: 12, max: 260, step: 1, value: s.size || 72, fmt: (v) => v + ' px', onInput: (v) => setS({ size: v }), onDone: this.seal }),
        field('Weight', seg([['400', 'Regular'], ['600', 'Semi'], ['800', 'Bold'], ['900', 'Black']], String(s.weight || 700), (v) => { setS({ weight: +v }); this.seal(); })),
        field('Align', seg([['left', '⯇'], ['center', '≡'], ['right', '⯈']], s.align || 'center', (v) => { setS({ align: v }); this.seal(); })),
        h('div.row', h('span.lbl', 'Colours'), h('div.swatches', color('color', 'Text'), h('label.swatch', h('input', { type: 'color', value: toHex(s.bg || '#000000'), on: { input: (e) => setS({ bg: e.target.value }), change: this.seal } }), h('small', 'Box')), color('stroke', 'Outline'))),
        h('div.checks',
          h('label.check', h('input', { type: 'checkbox', checked: !!s.bg, on: { change: (e) => { setS({ bg: e.target.checked ? (s.bg || '#000000') : null }); this.seal(); this.render(true); } } }), 'Box behind'),
          h('label.check', h('input', { type: 'checkbox', checked: !!s.shadow, on: { change: (e) => { setS({ shadow: e.target.checked }); this.seal(); } } }), 'Shadow'),
          h('label.check', h('input', { type: 'checkbox', checked: !!s.upper, on: { change: (e) => { setS({ upper: e.target.checked }); this.seal(); } } }), 'CAPITALS'),
          h('label.check', h('input', { type: 'checkbox', checked: !!s.italic, on: { change: (e) => { setS({ italic: e.target.checked }); this.seal(); } } }), 'Italic')),
        slider({ label: 'Outline', min: 0, max: 16, step: 1, value: s.strokeW || 0, fmt: (v) => v + ' px', onInput: (v) => setS({ strokeW: v, stroke: s.stroke || '#000000' }), onDone: this.seal }),
        slider({ label: 'Corners', min: 0, max: 120, step: 1, value: s.radius ?? 20, fmt: (v) => v + ' px', onInput: (v) => setS({ radius: v }), onDone: this.seal }),
      ),
      this.section('Timing',
        slider({ label: 'Duration', min: 0.5, max: 30, step: 0.1, value: itemDur(item), fmt: secs, onInput: (v) => this.set(item, { out: item.in + v * (item.speed || 1) }, 'Duration'), onDone: this.seal })),
    );
  }

  shapePanel(item) {
    const s = item.style || {};
    const setS = (props) => this.set(item, { style: Object.assign({}, item.style, props) }, 'Shape');
    this.el.append(this.section('Shape',
      field('Shape', seg([['rect', '▭ Box'], ['ellipse', '◯ Circle'], ['line', '― Line']], s.shape || 'rect', (v) => { setS({ shape: v }); this.seal(); })),
      h('div.row', h('span.lbl', 'Colours'), h('div.swatches',
        h('label.swatch', h('input', { type: 'color', value: toHex(s.fill || '#e8508b'), on: { input: (e) => setS({ fill: e.target.value }), change: this.seal } }), h('small', 'Fill')),
        h('label.swatch', h('input', { type: 'color', value: toHex(s.stroke || '#ffffff'), on: { input: (e) => setS({ stroke: e.target.value }), change: this.seal } }), h('small', 'Line')))),
      slider({ label: 'Line', min: 0, max: 40, step: 1, value: s.strokeW || 0, fmt: (v) => v + ' px', onInput: (v) => setS({ strokeW: v }), onDone: this.seal }),
      slider({ label: 'Corners', min: 0, max: 300, step: 1, value: s.radius || 0, fmt: (v) => v + ' px', onInput: (v) => setS({ radius: v }), onDone: this.seal }),
      slider({ label: 'Duration', min: 0.5, max: 30, step: 0.1, value: itemDur(item), fmt: secs, onInput: (v) => this.set(item, { out: item.in + v }, 'Duration'), onDone: this.seal }),
    ));
  }

  stickerPanel(item) {
    this.el.append(this.section('Sticker',
      h('div.emoji-grid', STICKERS.map((g) => h('button.emoji' + (g === item.text ? '.on' : ''), { on: { click: () => { this.set(item, { text: g, name: g }, 'Sticker'); this.seal(); this.render(true); } } }, g))),
      slider({ label: 'Duration', min: 0.5, max: 30, step: 0.1, value: itemDur(item), fmt: secs, onInput: (v) => this.set(item, { out: item.in + v }, 'Duration'), onDone: this.seal }),
    ));
  }
}
