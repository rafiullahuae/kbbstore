// KBB Studio · themes · TEXT BEHIND THE HOST in the editor: its Inspector sections (status, lines, the word or the
// carousel, the top line, the look, position and motion, the cut-out edges) and its rows in the ✨ Themes tab's
// ⚡ One click card. Every change is ONE undo step (a slider drag coalesces into one).
import { h, clear, slider, seg, field, modal, toast } from '../editor/ui.js';
import { TB_FONTS, TB_IN, TB_OUT, TB_CAR, tbOf, tbStatus, carWord, noteSubjectReady, loadTbAssets } from './textbehind.js';
import { addTextBehind, setTb, setTbStyle, placeTb, sizeTb, textBehindMentions, mentionTbs } from './textbehind-cmds.js';
import { REGIONS, placeName } from './country-words.js';
import { COUNTRIES, COUNTRY_GROUPS, CONTINENTS, arabicName, countryCap, continentCap, capId } from './capsules-data.js';
import { flagSvgs } from './capsules.js';
import { colorsOf, loadBadgeAssets } from './globe-badge.js';

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  document.head.appendChild(h('style', { id: 'kbb-tbehind-css' }, `
.tb-note { font-size: 12px; color: var(--muted); margin: 0; line-height: 1.4; }
.tb-note[data-tb-status="ok"] { color: var(--ok, #5ad19a); }
.tb-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.tb-row label { display: flex; align-items: center; gap: 5px; font-size: 12px; cursor: pointer; }
.tb-row select { flex: 1 1 120px; min-width: 0; }
.tb-fills { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 5px; }
.tb-fill { border: 1px solid var(--line2, rgba(255,255,255,.16)); border-radius: 8px; padding: 0 0 3px; background: none; color: var(--text, #eee); font-size: 10.5px; cursor: pointer; overflow: hidden; min-width: 0; }
.tb-fill i { display: block; height: 16px; }
.tb-fill.on { border-color: var(--accent, #e8508b); box-shadow: inset 0 0 0 1px var(--accent, #e8508b); }
.tb-list { display: flex; flex-direction: column; gap: 4px; max-height: 260px; overflow: auto; }
.tb-li { display: grid; grid-template-columns: 22px minmax(0, 1fr) auto auto auto; gap: 4px; align-items: center; }
.tb-li img { width: 20px; height: 15px; object-fit: cover; border-radius: 2px; }
.tb-li input { min-width: 0; width: 100%; box-sizing: border-box; }
.tb-li button { height: 24px; min-height: 24px; padding: 0 6px; font-size: 12px; }
.tb-pick { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.tb-pick-cols { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 12px; min-height: 360px; max-height: 60vh; }
.tb-conts { display: flex; flex-direction: column; gap: 4px; overflow: auto; }
.tb-conts button { text-align: start; display: flex; justify-content: space-between; gap: 6px; }
.tb-conts button.on { border-color: var(--accent); }
.tb-countries { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 4px 10px; overflow: auto; align-content: start; }
.tb-countries label { display: flex; align-items: center; gap: 6px; font-size: 12.5px; cursor: pointer; }
.tb-countries img { width: 24px; height: 18px; object-fit: cover; border-radius: 2px; }
.tb-quick-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.tb-quick-row label { display: flex; align-items: center; gap: 5px; font-size: 12px; cursor: pointer; }`));
}

const cur = (ed, id) => ed.project.tracks.flatMap((t) => t.items).find((i) => i.id === id) || null;
const tag = (el, k) => { const i = el.querySelector('input'); if (i) i.dataset[k] = '1'; return el; };
const pct = (v) => Math.round(v * 100) + '%';
const STATUS = {
  ok: 'The host is cut out in front of the words.',
  none: 'No person in the clip under it at this moment: the words show in front.',
  pending: 'Finding the person (once, offline)… the words go behind you when it is done.',
  model: 'Main subject needs its cut-out model (4.6 MB, once): install it below, or use Person.',
  noclip: 'No video clip under it here: put it over a clip on the main video track.',
};

// ── colour presets ──
function fillPresets(ed, s) {
  const ws = ed.workspace || {}, c = ws.colors || {};
  const flag = colorsOf(s.code || 'europe');
  return [
    ['white', 'White', { type: 'solid', colors: ['#ffffff'] }],
    ['gold', 'Gold', { type: 'grad', dir: 'v', colors: ['#fff3b0', '#f5c542', '#b8780a'] }],
    ['brand', 'Company', { type: 'grad', dir: 'v', colors: [c.primary || '#C6395F', c.accent || c.secondary || '#2A2228'].filter((x) => /^#[0-9a-f]{6}$/i.test(x)) }],
    ['flag', 'Flag stripes', { type: 'grad', dir: 'v', hard: true, colors: flag }],
    ['flagsoft', 'Flag blend', { type: 'grad', dir: 'h', colors: flag }],
    ['uae', 'UAE flag', { type: 'grad', dir: 'h', colors: ['#EF3340', '#00843D', '#FFFFFF', '#1A1A1A'] }],
    ['sunset', 'Sunset', { type: 'grad', dir: 'v', colors: ['#ffd166', '#ef476f', '#7b2cbf'] }],
    ['black', 'Black', { type: 'solid', colors: ['#111111'] }],
  ];
}
const css3 = (f) => (f.type === 'grad' ? `linear-gradient(${f.dir === 'h' ? '90deg' : '180deg'}, ${f.hard ? f.colors.map((c, i, a) => `${c} ${(i / a.length) * 100}% ${((i + 1) / a.length) * 100}%`).join(', ') : f.colors.join(', ')})` : f.colors[0]);

const PLACES = () => [
  ['Regions', Object.entries(REGIONS).map(([id, R]) => [id, R.en])],
  ['Countries', COUNTRIES.filter((r) => !/-/.test(r[0])).map((r) => [r[0], r[1]]).sort((a, b) => a[1].localeCompare(b[1]))],
];

/** the Inspector's sections for a Text behind the host element */
export function tbSections(insp, item) {
  css();
  const ed = insp.ed, ctx = ed.context(), s = item.style || {}, tb = tbOf(s), id = item.id, W = ed.project.canvas.w;
  const set = (patch, label, live) => { setTb(ctx, id, patch, label); if (!live) { insp.seal(); insp.render(true); } };
  const done = () => insp.seal();
  const out = [];

  // ── status, lines, the word ──
  const st = tbStatus(ed.project, item, ed.t);
  const status = h('p.tb-note', { dataset: { tbStatus: st } }, STATUS[st] || '');
  const findBtn = st === 'pending' ? h('button.btn.small', { type: 'button', title: 'Find the person in the clip under it now (offline, once)', dataset: { tbFind: '1' }, on: { click: async () => {
    findBtn.disabled = true;
    try { const { ensureAnalysis } = await import('../look/vision.js'); ctx.progress?.('Finding the person…', 0); await ensureAnalysis(ed.project, { onProgress: (l, f) => ctx.progress?.(l || 'Finding the person…', f) }); } catch (e) { toast(e.message, 'error'); }
    finally { ctx.progress?.(null); ed.preview?.draw?.(); insp.render(true); }
  } } }, '🧍 Find the person now') : null;
  const modelBtn = st === 'model' ? h('button.btn.small', { type: 'button', title: 'Download the main-subject cut-out model (U²-Net-p, Apache-2.0, 4.6 MB) once', dataset: { tbModel: '1' }, on: { click: async () => {
    modelBtn.disabled = true;
    try { const S = await import('../look/subject.js'); await S.installSubjectModel((f) => ctx.progress?.('Getting the cut-out model…', f)); noteSubjectReady(); const { ensureAnalysis } = await import('../look/vision.js'); await ensureAnalysis(ed.project, { onProgress: (l, f) => ctx.progress?.(l, f) }); } catch (e) { toast(e.message, 'error'); }
    finally { ctx.progress?.(null); ed.preview?.draw?.(); insp.render(true); }
  } } }, '📦 Install the main-subject model') : null;

  const placeSel = h('select', { 'aria-label': 'Country or region', title: 'The place the main word names (its name is the default word)', dataset: { tbPlace: '1' }, on: { change: (e) => {
    const v = e.target.value; setTbStyle(ctx, id, (st2) => { st2.code = v; if (st2.text) delete st2.text.word; }, 'Text behind · ' + placeName(v, 'en'), () => ({ name: `🔤 Text behind · ${placeName(v, 'en')}` })); insp.seal(); insp.render(true);
  } } }, PLACES().map(([g, list]) => h('optgroup', { label: g }, list.map(([v, l]) => h('option', { value: v, selected: v === (s.code || 'europe') }, l)))));
  const lang = seg([['en', 'EN'], ['ar', 'عربي'], ['ur', 'اردو'], ['hi', 'हिन्दी']], s.lang || 'en', (v) => { setTbStyle(ctx, id, (st2) => { st2.lang = v; if (st2.text) delete st2.text.word; }, 'Text behind language'); insp.seal(); insp.render(true); }, '.tb-lang');
  const main = [
    status, findBtn, modelBtn,
    h('div.tb-row',
      h('label', { title: 'A smaller line above the main one (e.g. JOBS IN)' }, h('input', { type: 'checkbox', checked: tb.top.on, dataset: { tbTopOn: '1' }, on: { change: (e) => set({ top: { on: e.target.checked } }, e.target.checked ? 'Top line on' : 'Top line off') } }), 'Top line'),
      h('label', { title: 'The big line (a word or a carousel of countries)' }, h('input', { type: 'checkbox', checked: tb.main, dataset: { tbMainOn: '1' }, on: { change: (e) => set({ main: e.target.checked }, e.target.checked ? 'Main line on' : 'Main line off') } }), 'Main line')),
    field('Main line', seg([['word', 'One word'], ['carousel', 'Carousel']], tb.mode, (v) => {
      // a carousel starts with the place the word names, so the switch never shows an empty line
      const items = tb.car.items.length ? null : [countryCapOf(s.code || 'europe')];
      set({ mode: v, ...(items && v === 'carousel' ? { car: { items } } : {}) }, v === 'carousel' ? 'Carousel' : 'One word');
    }, '.tb-mode')),
  ];
  if (tb.mode === 'word') main.push(h('label.row', h('span.lbl', 'Place'), placeSel), h('div.tb-row', h('span.lbl', 'Name in'), lang), h('p.tb-note', 'The word is the place’s name: type your own (any text, English or Arabic) in Text below, or double-click it on the video.'));
  if (item.auto === 'tbehind') {
    const m = s.mention || {};
    main.push(h('p.tb-note', `Placed for “${(m.words || []).slice(0, 3).join('”, “')}” in the captions. It follows caption edits; Re-scan applies this look to every one.`),
      h('button.btn.small', { type: 'button', title: 'Scan the captions again with this element’s look, box and top line', dataset: { tbRescan: '1' }, on: { click: () => { try { const r = textBehindMentions(ctx, {}); toast(`${r.n} text-behind element${r.n === 1 ? '' : 's'} · ⌘Z undoes`, 'ok'); } catch (e) { toast(e.message, 'error'); } } } }, '↻ Re-scan captions'));
  }
  out.push(insp.section('Text behind the host', ...main));

  // ── the carousel ──
  if (tb.mode === 'carousel') out.push(carouselSection(insp, item, tb, set, done));

  // ── the top line ──
  if (tb.top.on) {
    const topFont = h('select', { 'aria-label': 'Top line font', dataset: { tbTopFont: '1' }, on: { change: (e) => { set({ top: { font: e.target.value } }, 'Top line font'); loadTbAssets(s).then(() => ed.preview?.draw?.()); } } }, TB_FONTS.map(([f, l]) => h('option', { value: f, selected: f === tb.top.font }, l)));
    const topCol = h('input', { type: 'color', value: tb.top.color || '#ffffff', 'aria-label': 'Top line colour', dataset: { tbTopColor: '1' }, on: { input: (e) => set({ top: { color: e.target.value } }, 'Top line colour', true), change: done } });
    out.push(insp.section('Top line',
      h('p.tb-note', 'Its words are in Text below (default “JOBS IN”); double-click it on the video to type over it.'),
      field('Font', topFont),
      tag(slider({ label: 'Size', min: 0.12, max: 1, step: 0.01, value: tb.top.size, fmt: pct, onInput: (v) => set({ top: { size: v } }, 'Top line size', true), onDone: done }), 'tbTopSize'),
      tag(slider({ label: 'Spacing', min: -0.05, max: 0.6, step: 0.01, value: tb.top.track, fmt: pct, onInput: (v) => set({ top: { track: v } }, 'Top line spacing', true), onDone: done }), 'tbTopTrack'),
      tag(slider({ label: 'Gap', min: -0.2, max: 0.6, step: 0.01, value: tb.top.gap, fmt: pct, onInput: (v) => set({ top: { gap: v } }, 'Gap between lines', true), onDone: done }), 'tbGap'),
      h('div.tb-row', h('span.lbl', 'Colour'), h('label', { title: 'The same colour or gradient as the main line' }, h('input', { type: 'checkbox', checked: !tb.top.color, dataset: { tbTopSame: '1' }, on: { change: (e) => set({ top: { color: e.target.checked ? null : '#ffffff' } }, 'Top line colour') } }), 'Same as main'), tb.top.color ? topCol : null)));
  }

  // ── the look ──
  const fontSel = h('select', { 'aria-label': 'Font', title: 'Bold display faces (bundled, offline); the Arabic ones draw Arabic words too', dataset: { tbFont: '1' }, on: { change: (e) => { set({ font: e.target.value }, 'Text behind font'); loadTbAssets(s).then(() => ed.preview?.draw?.()); } } }, TB_FONTS.map(([f, l]) => h('option', { value: f, selected: f === tb.font, style: { fontFamily: `"${f}"` } }, l)));
  const presets = fillPresets(ed, s), sig = JSON.stringify(tb.fill);
  const fills = h('div.tb-fills', presets.map(([k, l, f]) => h('button.tb-fill' + (JSON.stringify({ type: f.type, colors: f.colors, dir: f.dir || 'v', hard: !!f.hard }) === sig ? '.on' : ''), { type: 'button', title: `${l} colour${f.type === 'grad' ? ' gradient' : ''}`, dataset: { tbFill: k }, on: { click: async () => {
    let F = f;
    if (k === 'flag' || k === 'flagsoft') { await loadBadgeAssets(s.code || 'europe').catch(() => {}); F = { ...f, colors: colorsOf(s.code || 'europe') }; }
    set({ fill: F }, `${l} colour`);
  } } }, h('i', { style: { background: css3(f) } }), l)));
  const custom = h('input', { type: 'color', value: tb.fill.type === 'solid' ? tb.fill.colors[0] : '#ffffff', 'aria-label': 'Own colour', title: 'Any colour of your own', dataset: { tbColor: '1' }, on: { input: (e) => set({ fill: { type: 'solid', colors: [e.target.value] } }, 'Text behind colour', true), change: done } });
  const outCol = h('input', { type: 'color', value: tb.outlineCol, 'aria-label': 'Outline colour', dataset: { tbOutlineCol: '1' }, on: { input: (e) => set({ outlineCol: e.target.value }, 'Outline colour', true), change: done } });
  out.push(insp.section('Look',
    field('Font', fontSel),
    tag(slider({ label: 'Size', min: 0.2, max: 1.6, step: 0.01, value: +(item.w / W).toFixed(2), fmt: pct, onInput: (v) => sizeTb(ctx, id, v), onDone: done }), 'tbSize'),
    tag(slider({ label: 'Spacing', min: -0.05, max: 0.5, step: 0.01, value: tb.track, fmt: pct, onInput: (v) => set({ track: v }, 'Letter spacing', true), onDone: done }), 'tbTrack'),
    fills, h('div.tb-row', h('span.lbl', 'Own colour'), custom),
    h('div.tb-row', tag(slider({ label: 'Outline', min: 0, max: 0.15, step: 0.005, value: tb.outline, fmt: pct, onInput: (v) => set({ outline: v }, 'Outline', true), onDone: done }), 'tbOutline'), outCol),
    tag(slider({ label: 'Shadow', min: 0, max: 1, step: 0.01, value: tb.shadow, fmt: pct, onInput: (v) => set({ shadow: v }, 'Shadow', true), onDone: done }), 'tbShadow'),
    tag(slider({ label: 'Opacity', min: 0.05, max: 1, step: 0.01, value: item.opacity ?? 1, fmt: pct, onInput: (v) => insp.set(item, { opacity: v }, 'Text behind opacity'), onDone: done }), 'tbOpacity')));

  // ── position and motion ──
  const num = (k, label) => h('label.num', h('span', label), h('input', { type: 'number', value: Math.round(item[k]), step: 1, dataset: { tbPos: k }, on: { change: (e) => { insp.set(item, { [k]: +e.target.value || 0 }, 'Position'); done(); } } }));
  out.push(insp.section('Position & motion',
    h('div.tb-row', ...[['head', '🙂 Behind the head', 'Lines the words up behind your head (found from the person in the clip)'], ['centre', '⌖ Centre', 'In the middle of the frame'], ['lower', '▁ Lower', 'In the lower part of the frame']].map(([k, l, t]) => h('button.btn.small', { type: 'button', title: t, dataset: { tbQuick: k }, on: { click: () => { const r = placeTb(ctx, id, k); done(); insp.render(true); if (k === 'head' && !r?.head) toast('No person found at this moment: placed in the upper part instead', 'info'); } } }, l))),
    h('div.nums', num('x', 'X'), num('y', 'Y')),
    h('p.tb-note', 'Or drag it on the video; drag a corner to resize it (the words fit the box).'),
    tag(slider({ label: 'Rotate', min: -45, max: 45, step: 1, value: item.rot || 0, fmt: (v) => Math.round(v) + '°', onInput: (v) => insp.set(item, { rot: v }, 'Rotate'), onDone: done }), 'tbRot'),
    field('In', seg(TB_IN, tb.animIn, (v) => set({ animIn: v }, 'Text behind in'), '.tb-in')),
    tag(slider({ label: 'In time', min: 0.1, max: 2, step: 0.05, value: tb.inDur, fmt: (v) => v.toFixed(2) + ' s', onInput: (v) => set({ inDur: v }, 'In time', true), onDone: done }), 'tbInDur'),
    field('Out', seg(TB_OUT, tb.animOut, (v) => set({ animOut: v }, 'Text behind out'), '.tb-out')),
    tag(slider({ label: 'Out time', min: 0.1, max: 2, step: 0.05, value: tb.outDur, fmt: (v) => v.toFixed(2) + ' s', onInput: (v) => set({ outDur: v }, 'Out time', true), onDone: done }), 'tbOutDur'),
    h('p.tb-note', 'Start and length: Theme element above, or drag and trim it on the timeline.')));

  // ── the cut-out edges ──
  out.push(insp.section('Cut-out edges',
    field('Cut out', seg([['person', '🧍 Person'], ['subject', '📦 Main subject']], tb.key, (v) => set({ key: v }, v === 'subject' ? 'Cut out the main subject' : 'Cut out the person'), '.tb-key')),
    tag(slider({ label: 'Feather', min: 0, max: 1, step: 0.01, value: tb.feather, fmt: pct, onInput: (v) => set({ feather: v }, 'Edge feather', true), onDone: done }), 'tbFeather'),
    tag(slider({ label: 'Grow', min: -1, max: 1, step: 0.01, value: tb.grow, fmt: (v) => (v > 0 ? '+' : '') + Math.round(v * 100), onInput: (v) => set({ grow: v }, 'Edge grow', true), onDone: done }), 'tbGrow'),
    tag(slider({ label: 'Smoothness', min: 0, max: 1, step: 0.01, value: tb.smooth, fmt: pct, onInput: (v) => set({ smooth: v }, 'Edge smoothness', true), onDone: done }), 'tbSmooth'),
    h('p.tb-note', 'Feather softens the edge, Grow pushes it out (or in), Smoothness steadies it from frame to frame.')));
  return out;
}

const countryCapOf = (code) => (REGIONS[code] ? (CONTINENTS.find((c) => c.id === code) ? continentCap(code) : { id: capId(), en: REGIONS[code].en, ar: REGIONS[code].ar }) : countryCap(code));

function carouselSection(insp, item, tb, set, done) {
  const ed = insp.ed, ctx = ed.context(), id = item.id, car = tb.car;
  const write = (fn, label) => { setTbStyle(ctx, id, (st) => { st.tb ||= {}; st.tb.car = { ...(st.tb.car || {}) }; st.tb.car.items = (st.tb.car.items || []).map((x) => ({ ...x })); fn(st.tb.car); }, label); insp.seal(); insp.render(true); };
  let SV = null;
  const list = h('div.tb-list', { dataset: { tbCarList: '1' } });
  const paint = () => clear(list).append(...car.items.map((it, i) => {
    const inp = h('input', { type: 'text', value: carWord(it, car.lang), dir: 'auto', 'aria-label': `Word ${i + 1}`, title: 'Type over it to change this word (empty puts its name back)', dataset: { tbCarWord: it.id } });
    inp.addEventListener('keydown', (e) => { e.stopPropagation(); if (e.key === 'Enter') inp.blur(); });
    inp.addEventListener('change', () => write((c) => { const x = c.items.find((y) => y.id === it.id); const v = inp.value.trim(); const name = car.lang === 'ar' ? x.ar || x.en : x.en || x.ar; if (!v || v === name) delete x.text; else x.text = v; }, 'Carousel word'));
    const mv = (d) => write((c) => { const j = i + d; if (j < 0 || j >= c.items.length) return; [c.items[i], c.items[j]] = [c.items[j], c.items[i]]; }, 'Carousel order');
    return h('div.tb-li', SV?.[it.flag] ? h('img', { alt: '', src: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(SV[it.flag]) }) : h('span'), inp,
      h('button.btn.small', { type: 'button', title: 'Move up', 'aria-label': 'Move up', disabled: i === 0, on: { click: () => mv(-1) } }, '↑'),
      h('button.btn.small', { type: 'button', title: 'Move down', 'aria-label': 'Move down', disabled: i === car.items.length - 1, on: { click: () => mv(1) } }, '↓'),
      h('button.btn.small', { type: 'button', title: 'Remove this word', 'aria-label': 'Remove', dataset: { tbCarDel: it.id }, on: { click: () => write((c) => { c.items = c.items.filter((y) => y.id !== it.id); }, 'Remove word') } }, '✕'));
  }));
  paint(); flagSvgs().then((s) => { SV = s; paint(); });
  const add = h('input', { type: 'text', placeholder: 'Type a word, Enter adds it', 'aria-label': 'Add your own word', dir: 'auto', dataset: { tbCarAdd: '1' } });
  add.addEventListener('keydown', (e) => { e.stopPropagation(); if (e.key === 'Enter' && add.value.trim()) { const v = add.value.trim(); add.value = ''; write((c) => { c.items.push({ id: capId(), en: v, text: v }); }, 'Add word'); } });
  return insp.section('Carousel',
    h('p.tb-note', `${car.items.length} word${car.items.length === 1 ? '' : 's'}: they take turns behind you, every one cut out round you.`),
    list,
    h('div.tb-row', h('button.btn.small', { type: 'button', title: 'Tick countries by continent (Select all takes a whole continent), or a continent itself', dataset: { tbPick: '1' }, on: { click: () => pickCountries(insp, item, car, write) } }, '🌍 Pick countries…'), add),
    field('Style', seg(TB_CAR, car.style, (v) => set({ car: { style: v } }, 'Carousel style'), '.tb-car-style')),
    tag(slider({ label: car.style === 'ticker' ? 'Speed' : 'Per word', min: 0.4, max: 6, step: 0.1, value: car.per, fmt: (v) => v.toFixed(1) + ' s', onInput: (v) => set({ car: { per: v } }, 'Time per word', true), onDone: done }), 'tbPer'),
    field('Order', seg([['list', 'As listed'], ['shuffle', 'Shuffle']], car.order, (v) => set({ car: { order: v } }, 'Carousel order'), '.tb-order')),
    field('Names in', seg([['en', 'English'], ['ar', 'عربي']], car.lang, (v) => set({ car: { lang: v } }, 'Carousel language'), '.tb-car-lang')),
    h('div.tb-row',
      h('label', { title: 'Start again after the last word (off: stay on the last word)' }, h('input', { type: 'checkbox', checked: car.loop, dataset: { tbLoop: '1' }, on: { change: (e) => set({ car: { loop: e.target.checked } }, 'Carousel loop') } }), 'Loop'),
      h('label', { title: 'A small flag before each country name' }, h('input', { type: 'checkbox', checked: car.flags, dataset: { tbFlags: '1' }, on: { change: (e) => set({ car: { flags: e.target.checked } }, 'Carousel flags') } }), 'Flags')));
}

/** the countries & continents dialog (the capsules carousel's lists): Apply writes the carousel's list, ONE step */
function pickCountries(insp, item, car, write) {
  const have = new Set(car.items.filter((c) => c.flag && !c.cont).map((c) => c.flag)), haveCont = new Set(car.items.map((c) => c.cont).filter(Boolean));
  const pick = new Set(have), pickCont = new Set(haveCont);
  let group = 'Europe', q = '', SV = null;
  const contBox = h('div.tb-conts'), grid = h('div.tb-countries', { dataset: { tbCountries: '1' } });
  const search = h('input', { type: 'search', placeholder: 'Search a country…', 'aria-label': 'Search countries' });
  search.addEventListener('input', () => { q = search.value.trim().toLowerCase(); paint(); });
  search.addEventListener('keydown', (e) => e.stopPropagation());
  flagSvgs().then((s) => { SV = s; paint(); });
  const inGroup = (g) => COUNTRIES.filter((c) => c[2] === g);
  function paint() {
    clear(contBox).append(...COUNTRY_GROUPS.filter((g) => inGroup(g).length).map((g) => { const n = inGroup(g).filter((c) => pick.has(c[0])).length; return h('button.btn.small' + (g === group && !q ? '.on' : ''), { type: 'button', dataset: { tbGroup: g }, on: { click: () => { group = g; q = ''; search.value = ''; paint(); } } }, h('span', g), h('small', `${n}/${inGroup(g).length}`)); }));
    const list = q ? COUNTRIES.filter((c) => c[1].toLowerCase().includes(q) || arabicName(c[0], c[1]).includes(q)) : inGroup(group);
    const cont = CONTINENTS.find((c) => c.group === group);
    clear(grid).append(
      q ? null : h('div', { style: { gridColumn: '1 / -1', display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' } },
        h('button.btn.small', { type: 'button', dataset: { tbAll: group }, on: { click: () => { for (const c of list) pick.add(c[0]); paint(); } } }, `Select all ${group}`),
        h('button.btn.small', { type: 'button', dataset: { tbNone: group }, on: { click: () => { for (const c of list) pick.delete(c[0]); paint(); } } }, 'None'),
        cont ? h('label', { title: `“${cont.en}” itself as one word` }, h('input', { type: 'checkbox', checked: pickCont.has(cont.id), dataset: { tbCont: cont.id }, on: { change: (e) => { e.target.checked ? pickCont.add(cont.id) : pickCont.delete(cont.id); } } }), h('span', `“${cont.en}” as a word`)) : null),
      ...list.map(([code, name]) => h('label', { title: name, dataset: { tbCountry: code } }, h('input', { type: 'checkbox', checked: pick.has(code), on: { change: (e) => { e.target.checked ? pick.add(code) : pick.delete(code); } } }),
        SV?.[code] ? h('img', { alt: '', src: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(SV[code]) }) : h('span', { style: { width: '24px' } }), h('span', name))));
  }
  paint();
  modal({ title: 'Countries & continents', wide: true, body: h('div.tb-pick', h('p.tb-note', 'Tick countries in any continent (Select all takes a whole continent), or the continent itself as one word. Unticked countries leave the carousel; your own typed words stay.'), search, h('div.tb-pick-cols', contBox, grid)),
    actions: [{ label: 'Cancel' }, { label: 'Apply', primary: true, id: 'tb-pick-apply', onClick: () => write((c) => {
      c.items = c.items.filter((x) => (x.flag && !x.cont ? pick.has(x.flag) || !COUNTRIES.some((y) => y[0] === x.flag) : x.cont ? pickCont.has(x.cont) : true));
      for (const cid of pickCont) if (!c.items.some((x) => x.cont === cid)) c.items.push(continentCap(cid));
      for (const code of pick) if (!c.items.some((x) => x.flag === code && !x.cont)) c.items.push(countryCap(code));
    }, 'Carousel countries') }] });
}

/** the ⚡ One click rows (themes/el2-panel.js quickCard) */
export function tbQuickRows(ctx, rerender) {
  css();
  const n = mentionTbs(ctx.project).length, prev = mentionTbs(ctx.project)[0];
  const top = h('input', { type: 'checkbox', checked: !!prev?.style?.tb?.top?.on, dataset: { tbMentionsTop: '1' } });
  return [
    h('div.tb-quick-row',
      h('button.btn.small', { type: 'button', title: 'A huge word behind you at the playhead: you stay in front of it, the real background behind (place, words, carousel and look in the Inspector)', dataset: { tbAdd: '1' }, on: { click: () => {
        try { const it = addTextBehind(ctx); window.kbbEditor?.select?.([it.id]); window.kbbEditor?.showSide?.('inspector'); toast('Text behind you · 3 s at the playhead · ⌘Z undoes', 'ok'); rerender?.(); } catch (e) { toast(e.message, 'error'); }
      } } }, '🔤 Text behind me'),
      h('button.btn.small', { type: 'button', title: 'Scan the captions (English, Arabic, Urdu, Hindi) and put each country or region you name behind you, big, for the sentence you say it in', dataset: { tbMentions: '1' }, on: { click: () => {
        try { const r = textBehindMentions(ctx, { top: top.checked }); toast(r.n ? `${r.n} text-behind element${r.n === 1 ? '' : 's'}: ${r.codes.map((c) => placeName(c, 'en')).join(', ')} · ⌘Z undoes` : 'No country or region named in the captions', r.n ? 'ok' : 'info', 4200); rerender?.(); } catch (e) { toast(e.message, 'error'); }
      } } }, '🔤 Text behind me for every country I mention'),
      h('label', { title: 'Put a smaller “JOBS IN” line above each place (its words are editable)' }, top, 'JOBS IN above')),
    n ? h('p.tb-note', `${n} text-behind element${n === 1 ? '' : 's'} from the captions: they follow caption edits.`) : null,
  ];
}
