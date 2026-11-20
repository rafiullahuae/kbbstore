// KBB Studio · themes · TEXT BEHIND THE HOST in the editor. The owner, 19 Nov: "i want a separate on screen popup holding
// only those options, currently everything is mixing in one long panel". So:
//   tbGroups(host, item)   the editor's controls in four groups (words · look · position & motion · cut-out), built for
//                          the floating editor beside the video (themes/textbehind-popup.js), which holds every change
//                          as ONE draft until Apply
//   tbSections(insp, item) the Inspector keeps a one-line summary, the live status and "✎ Edit text behind…"
//   tbStatusBlock(ed, id)  the cut-out's status, live (progress, done, failed: look/vision.js events)
//   tbQuickRows(ctx, …)    its rows in the ✨ Themes tab's ⚡ One click card
import { h, clear, slider, seg, field, modal, toast } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { TB_FONTS, TB_IN, TB_OUT, TB_CAR, tbOf, tbStatus, carWord, noteSubjectReady, loadTbAssets, defaultWord } from './textbehind.js';
import { addTextBehind, setTb, setTbStyle, placeTb, sizeTb, textBehindMentions, mentionTbs } from './textbehind-cmds.js';
import { writeWords } from './text-panel.js';
import { REGIONS, placeName } from './country-words.js';
import { COUNTRIES, COUNTRY_GROUPS, CONTINENTS, arabicName, countryCap, continentCap, capId } from './capsules-data.js';
import { flagSvgs } from './capsules.js';
import { colorsOf, loadBadgeAssets } from './globe-badge.js';
import { TB_CMODES, TB_CSTYLES, TB_TOPC, palOf, ccOf, countryLook, codeOfEntry, fillCss } from './tb-colors.js';

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
.tb-quick-row label { display: flex; align-items: center; gap: 5px; font-size: 12px; cursor: pointer; }
.tb-sum { margin: 0; font-size: 12.5px; font-weight: 600; line-height: 1.4; overflow-wrap: anywhere; }
.tb-status { display: flex; flex-direction: column; gap: 6px; }
.tb-prog { width: 100%; height: 6px; }
.tb-edit { width: 100%; }
.tb-cc { display: flex; flex-direction: column; gap: 6px; }
.tb-cc-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 8px; align-items: center; padding: 6px 8px; border: 1px solid var(--line, #242634); border-radius: 9px; }
.tb-cc-name { display: flex; align-items: center; gap: 8px; min-width: 0; font-size: 12.5px; font-weight: 650; }
.tb-cc-name span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tb-cc-sw { flex: none; width: 44px; height: 18px; border-radius: 4px; box-shadow: inset 0 0 0 1px rgba(255,255,255,.18); }
.tb-cc-row select { min-height: 26px; font-size: 11.5px; }
.tb-cc-cols { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.tb-cc-cols input[type=color] { width: 28px; height: 24px; padding: 0; border: 1px solid var(--line2, rgba(255,255,255,.16)); border-radius: 5px; background: none; cursor: pointer; }
.tb-cc-cols small { color: var(--muted, #a9aec0); font-size: 11px; margin: 0 2px 0 6px; }
.tb-cc-cols button { margin-left: auto; height: 24px; min-height: 24px; padding: 0 8px; font-size: 11.5px; }
.tb-cc-row[data-tb-cc-own] { border-color: var(--accent, #e8508b); }`));
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

// ── the status line: where the cut-out stands, kept live (the owner, 19 Nov: the line said "Finding the person…" long
// after the person was found — nothing told it the analysis had finished). It listens to look/vision.js's progress,
// done and failed events, and lets go of them once it leaves the page.
let LAST_FAIL = null;   // the last analysis failure (message), until one succeeds
bus.on('vision:failed', (e) => { LAST_FAIL = e?.message || 'unknown error'; });
bus.on('vision:done', (e) => { LAST_FAIL = null; if (e?.ran) { try { window.kbbEditor?.preview?.draw?.(); } catch {} } });   // the masks arrived: the monitor shows the words behind him now, whoever asked for them

/** a live status block for the element (status line, progress, the one button that helps now) */
export function tbStatusBlock(ed, id) {
  css();
  const ctx = ed.context();
  const line = h('p.tb-note', { dataset: { tbStatus: 'pending' } }), bar = h('progress.tb-prog', { max: 1, hidden: true }), btns = h('div.tb-row');
  const box = h('div.tb-status', { dataset: { tbStatusBox: id } }, line, bar, btns);
  let busy = false;
  const find = async () => {
    if (busy) return; busy = true; paint();
    try { const { ensureAnalysis } = await import('../look/vision.js'); await ensureAnalysis(ed.project); } catch (e) { toast(e.message, 'error'); }
    finally { busy = false; ed.preview?.draw?.(); paint(); }
  };
  const install = async () => {
    if (busy) return; busy = true; paint();
    try { const S = await import('../look/subject.js'); await S.installSubjectModel((f) => { bar.hidden = false; bar.value = f; }); noteSubjectReady(); const { ensureAnalysis } = await import('../look/vision.js'); await ensureAnalysis(ed.project); } catch (e) { toast(e.message, 'error'); }
    finally { busy = false; ed.preview?.draw?.(); paint(); }
  };
  function paint(prog = null) {
    const it = cur(ed, id); if (!it) return;
    let st = tbStatus(ed.project, it, ed.t);
    if (st === 'pending' && LAST_FAIL && !busy && prog == null) st = 'failed';
    line.dataset.tbStatus = st;
    line.textContent = st === 'failed' ? `Could not find the person: ${LAST_FAIL}. Try again below.` : st === 'pending' && prog != null ? `Finding the person… ${Math.round(prog * 100)}% (once, offline)` : STATUS[st] || '';
    bar.hidden = !(st === 'pending' && prog != null); if (prog != null) bar.value = prog;
    clear(btns);
    if (st === 'pending' || st === 'failed') btns.append(h('button.btn.small', { type: 'button', disabled: busy, title: 'Find the person in the clip under it now (offline, once)', dataset: { tbFind: '1' }, on: { click: find } }, busy ? '🧍 Finding the person…' : st === 'failed' ? '↻ Try again' : '🧍 Find the person now'));
    if (st === 'model') {
      btns.append(h('button.btn.small', { type: 'button', disabled: busy, title: 'Download the main-subject cut-out model (U²-Net-p, Apache-2.0, 4.6 MB) once', dataset: { tbModel: '1' }, on: { click: install } }, '📦 Install the main-subject model'));
      // installed meanwhile from Look → Background: the cached "not installed" answer is stale
      import('../look/subject.js').then((S) => S.subjectReady()).then((r) => { if (r) { noteSubjectReady(); ed.preview?.draw?.(); paint(); } }).catch(() => {});
    }
  }
  // the panel that holds it is rebuilt often: it lets go of the bus the first time it hears something after leaving the
  // page (or straight away if it never made it onto the page)
  const offs = [], stop = () => { offs.forEach((o) => o()); offs.length = 0; };
  const live = (fn) => (e) => { if (!box.isConnected) { stop(); return; } fn(e); };
  queueMicrotask(() => { if (!box.isConnected) stop(); });
  offs.push(bus.on('vision:progress', live((e) => { if (e?.f != null && e.label) paint(e.f); })), bus.on('vision:done', live(() => paint())), bus.on('vision:failed', live(() => paint())), bus.on('playhead', live((e) => { if (!e?.playing) paint(); })));
  paint();
  return box;
}

/** one line saying what the element shows (the Inspector's summary) */
export function tbSummaryText(item) {
  const s = item.style || {}, tb = tbOf(s), t = s.text || {};
  const top = tb.top.on ? `${t.top ?? 'JOBS IN'} / ` : '';
  const main = !tb.main ? '' : tb.mode === 'carousel' ? `carousel of ${tb.car.items.length} word${tb.car.items.length === 1 ? '' : 's'} (${TB_CAR.find((x) => x[0] === tb.car.style)?.[1] || ''}, ${tb.car.per.toFixed(1)} s each)` : String(t.word ?? defaultWord(s));
  return `${top}${main} · ${tb.font} · ${tb.key === 'subject' ? 'main subject' : 'person'} in front`;
}

/** the Inspector's short section: what it shows, whether the host is cut out yet, and the button to the editor */
export function tbSections(insp, item) {
  css();
  const ed = insp.ed, id = item.id;
  const open = () => import('./textbehind-popup.js').then((m) => m.openTbEditor(ed, id));
  return [insp.section('Text behind the host',
    h('button.btn.primary.tb-edit', { type: 'button', title: 'Open the Text behind editor beside the video: words, look, position and the cut-out, live; Apply or Cancel', dataset: { tbEdit: '1' }, on: { click: open } }, '✎ Edit text behind…'),
    h('p.tb-sum', { dataset: { tbSummary: '1' } }, tbSummaryText(item)),
    tbStatusBlock(ed, id),
    h('p.tb-note', 'Or double-click it on the video or on the timeline.'))];
}

/**
 * The editor's groups for a Text behind the host element: { words, look, pos, cut }, each a list of sections.
 * host = { ed, ctx, section(title, ...kids), set(item, props, label), seal(), render() } (themes/textbehind-popup.js);
 * every write goes through the history, which the editor holds as one draft until Apply.
 */
export function tbGroups(host, item) {
  css();
  const ed = host.ed, ctx = host.ctx, s = item.style || {}, tb = tbOf(s), id = item.id, W = ed.project.canvas.w;
  const set = (patch, label, live) => { setTb(ctx, id, patch, label); if (!live) { host.seal(); host.render(); } };
  const done = () => host.seal();
  const G = { words: [], look: [], pos: [], cut: [] };

  // ── words: the two lines, the word or the carousel ──
  const text = s.text || {};
  const wordIn = (key, value, label, def) => {
    const inp = h('input', { type: 'text', value, dir: 'auto', 'aria-label': label, placeholder: def, title: `${label}: type your own (empty puts “${def}” back)`, dataset: key === 'top' ? { tbpTop: '1' } : { tbpWord: '1' } });
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
    inp.addEventListener('input', () => { const v = inp.value.trim(); writeWords(ed, id, key, v ? inp.value : null, label); });
    return inp;
  };
  const placeSel = h('select', { 'aria-label': 'Country or region', title: 'The place the main word names (its name is the default word)', dataset: { tbPlace: '1' }, on: { change: (e) => {
    const v = e.target.value; setTbStyle(ctx, id, (st2) => { st2.code = v; if (st2.text) delete st2.text.word; }, 'Text behind · ' + placeName(v, 'en'), () => ({ name: `🔤 Text behind · ${placeName(v, 'en')}` })); host.seal(); host.render();
  } } }, PLACES().map(([g, list]) => h('optgroup', { label: g }, list.map(([v, l]) => h('option', { value: v, selected: v === (s.code || 'europe') }, l)))));
  const lang = seg([['en', 'EN'], ['ar', 'عربي'], ['ur', 'اردو'], ['hi', 'हिन्दी']], s.lang || 'en', (v) => { setTbStyle(ctx, id, (st2) => { st2.lang = v; if (st2.text) delete st2.text.word; }, 'Text behind language'); host.seal(); host.render(); }, '.tb-lang');
  const lines = [
    h('div.tb-row',
      h('label', { title: 'A smaller line above the main one (e.g. JOBS IN)' }, h('input', { type: 'checkbox', checked: tb.top.on, dataset: { tbTopOn: '1' }, on: { change: (e) => set({ top: { on: e.target.checked } }, e.target.checked ? 'Top line on' : 'Top line off') } }), 'Top line'),
      h('label', { title: 'The big line (a word or a carousel of countries)' }, h('input', { type: 'checkbox', checked: tb.main, dataset: { tbMainOn: '1' }, on: { change: (e) => set({ main: e.target.checked }, e.target.checked ? 'Main line on' : 'Main line off') } }), 'Main line')),
    tb.top.on ? field('Top words', wordIn('top', text.top ?? 'JOBS IN', 'Top line words', 'JOBS IN')) : null,
    field('Main line', seg([['word', 'One word'], ['carousel', 'Carousel']], tb.mode, (v) => {
      // a carousel starts with the place the word names, so the switch never shows an empty line
      const items = tb.car.items.length ? null : [countryCapOf(s.code || 'europe')];
      set({ mode: v, ...(items && v === 'carousel' ? { car: { items } } : {}) }, v === 'carousel' ? 'Carousel' : 'One word');
    }, '.tb-mode')),
  ];
  if (tb.mode === 'word') lines.push(field('Main word', wordIn('word', text.word ?? defaultWord(s), 'Main word', defaultWord(s))), h('label.row', h('span.lbl', 'Place'), placeSel), h('div.tb-row', h('span.lbl', 'Name in'), lang), h('p.tb-note', 'The word is the place’s name until you type your own (English or Arabic).'));
  if (item.auto === 'tbehind') {
    const m = s.mention || {};
    lines.push(h('p.tb-note', `Placed for “${(m.words || []).slice(0, 3).join('”, “')}” in the captions. It follows caption edits; Re-scan applies this look to every one.`),
      h('button.btn.small', { type: 'button', title: 'Scan the captions again with this element’s look, box and top line', dataset: { tbRescan: '1' }, on: { click: () => { try { const r = textBehindMentions(ctx, {}); toast(`${r.n} text-behind element${r.n === 1 ? '' : 's'} (kept with Apply)`, 'ok'); host.render(); } catch (e) { toast(e.message, 'error'); } } } }, '↻ Re-scan captions'));
  }
  G.words.push(host.section('Lines', ...lines));
  if (tb.mode === 'carousel') G.words.push(carouselSection(host, item, tb, set, done));

  // ── look: the main line, then the top line's own ──
  const fontSel = h('select', { 'aria-label': 'Font', title: 'Bold display faces (bundled, offline); the Arabic ones draw Arabic words too', dataset: { tbFont: '1' }, on: { change: (e) => { set({ font: e.target.value }, 'Text behind font'); loadTbAssets(s).then(() => ed.preview?.draw?.()); } } }, TB_FONTS.map(([f, l]) => h('option', { value: f, selected: f === tb.font, style: { fontFamily: `"${f}"` } }, l)));
  const presets = fillPresets(ed, s), sig = JSON.stringify(tb.fill);
  const fills = h('div.tb-fills', presets.map(([k, l, f]) => h('button.tb-fill' + (JSON.stringify({ type: f.type, colors: f.colors, dir: f.dir || 'v', hard: !!f.hard }) === sig ? '.on' : ''), { type: 'button', title: `${l} colour${f.type === 'grad' ? ' gradient' : ''}`, dataset: { tbFill: k }, on: { click: async () => {
    let F = f;
    if (k === 'flag' || k === 'flagsoft') { await loadBadgeAssets(s.code || 'europe').catch(() => {}); F = { ...f, colors: colorsOf(s.code || 'europe') }; }
    set({ fill: F }, `${l} colour`);
  } } }, h('i', { style: { background: css3(f) } }), l)));
  const custom = h('input', { type: 'color', value: tb.fill.type === 'solid' ? tb.fill.colors[0] : '#ffffff', 'aria-label': 'Own colour', title: 'Any colour of your own', dataset: { tbColor: '1' }, on: { input: (e) => set({ fill: { type: 'solid', colors: [e.target.value] } }, 'Text behind colour', true), change: () => { done(); host.render(); } } });
  const outCol = h('input', { type: 'color', value: tb.outlineCol, 'aria-label': 'Outline colour', title: tb.cc.mode === 'country' ? 'Outline colour of White / One colour (each country has its own below)' : 'Outline colour', dataset: { tbOutlineCol: '1' }, on: { input: (e) => set({ outlineCol: e.target.value }, 'Outline colour', true), change: done } });
  const shownMode = tb.cc.mode || (tb.fill.type === 'solid' && /^#f{6}$/i.test(tb.fill.colors[0]) ? 'white' : 'one');
  G.look.push(host.section('Colours', ...colourRows(host, item, tb, shownMode, set, done, fills, custom)));
  G.look.push(host.section('Main line',
    field('Font', fontSel),
    tag(slider({ label: 'Size', min: 0.2, max: 1.6, step: 0.01, value: +(item.w / W).toFixed(2), fmt: pct, onInput: (v) => sizeTb(ctx, id, v), onDone: done }), 'tbSize'),
    tag(slider({ label: 'Spacing', min: -0.05, max: 0.5, step: 0.01, value: tb.track, fmt: pct, onInput: (v) => set({ track: v }, 'Letter spacing', true), onDone: done }), 'tbTrack'),
    h('div.tb-row', tag(slider({ label: 'Outline', min: 0, max: 0.15, step: 0.005, value: tb.outline, fmt: pct, onInput: (v) => set({ outline: v }, 'Outline', true), onDone: done }), 'tbOutline'), shownMode === 'country' ? null : outCol),
    tag(slider({ label: 'Shadow', min: 0, max: 1, step: 0.01, value: tb.shadow, fmt: pct, onInput: (v) => set({ shadow: v }, 'Shadow', true), onDone: done }), 'tbShadow'),
    tag(slider({ label: 'Opacity', min: 0.05, max: 1, step: 0.01, value: item.opacity ?? 1, fmt: pct, onInput: (v) => host.set(item, { opacity: v }, 'Text behind opacity'), onDone: done }), 'tbOpacity')));
  if (tb.top.on) {
    const topFont = h('select', { 'aria-label': 'Top line font', dataset: { tbTopFont: '1' }, on: { change: (e) => { set({ top: { font: e.target.value } }, 'Top line font'); loadTbAssets(s).then(() => ed.preview?.draw?.()); } } }, TB_FONTS.map(([f, l]) => h('option', { value: f, selected: f === tb.top.font }, l)));
    const topCol = h('input', { type: 'color', value: tb.top.color || '#ffffff', 'aria-label': 'Top line colour', dataset: { tbTopColor: '1' }, on: { input: (e) => set({ top: { color: e.target.value } }, 'Top line colour', true), change: done } });
    G.look.push(host.section('Top line',
      field('Font', topFont),
      tag(slider({ label: 'Size', min: 0.12, max: 1, step: 0.01, value: tb.top.size, fmt: pct, onInput: (v) => set({ top: { size: v } }, 'Top line size', true), onDone: done }), 'tbTopSize'),
      tag(slider({ label: 'Spacing', min: -0.05, max: 0.6, step: 0.01, value: tb.top.track, fmt: pct, onInput: (v) => set({ top: { track: v } }, 'Top line spacing', true), onDone: done }), 'tbTopTrack'),
      tag(slider({ label: 'Gap', min: -0.2, max: 0.6, step: 0.01, value: tb.top.gap, fmt: pct, onInput: (v) => set({ top: { gap: v } }, 'Gap between lines', true), onDone: done }), 'tbGap'),
      field('Colour', seg(TB_TOPC.map(([v, l]) => [v, l, { same: 'The same colours as the main line', white: 'White', follow: 'The colours of the country on show (follows the carousel)', manual: 'A colour of your own' }[v]]), tb.top.cmode, (v) => set({ top: { cmode: v, ...(v === 'manual' && !tb.top.color ? { color: '#ffffff' } : {}) } }, 'Top line colour'), '.tb-top-cmode')),
      tb.top.cmode === 'manual' ? h('div.tb-row', h('span.lbl', 'Own colour'), topCol) : null));
  }

  // ── position and motion ──
  const num = (k, label) => h('label.num', h('span', label), h('input', { type: 'number', value: Math.round(item[k]), step: 1, dataset: { tbPos: k }, on: { change: (e) => { host.set(item, { [k]: +e.target.value || 0 }, 'Position'); done(); } } }));
  G.pos.push(host.section('Position',
    h('div.tb-row', ...[['head', '🙂 Behind the head', 'Lines the words up behind your head (found from the person in the clip)'], ['centre', '⌖ Centre', 'In the middle of the frame'], ['lower', '▁ Lower', 'In the lower part of the frame']].map(([k, l, t]) => h('button.btn.small', { type: 'button', title: t, dataset: { tbQuick: k }, on: { click: () => { const r = placeTb(ctx, id, k); done(); host.render(); if (k === 'head' && !r?.head) toast('No person found at this moment: placed in the upper part instead', 'info'); } } }, l))),
    h('div.nums', num('x', 'X'), num('y', 'Y')),
    h('p.tb-note', 'Or drag it on the video (it stays live while this is open); drag a corner to resize it (the words fit the box).'),
    tag(slider({ label: 'Rotate', min: -45, max: 45, step: 1, value: item.rot || 0, fmt: (v) => Math.round(v) + '°', onInput: (v) => host.set(item, { rot: v }, 'Rotate'), onDone: done }), 'tbRot')));
  G.pos.push(host.section('Motion',
    field('In', seg(TB_IN, tb.animIn, (v) => set({ animIn: v }, 'Text behind in'), '.tb-in')),
    tag(slider({ label: 'In time', min: 0.1, max: 2, step: 0.05, value: tb.inDur, fmt: (v) => v.toFixed(2) + ' s', onInput: (v) => set({ inDur: v }, 'In time', true), onDone: done }), 'tbInDur'),
    field('Out', seg(TB_OUT, tb.animOut, (v) => set({ animOut: v }, 'Text behind out'), '.tb-out')),
    tag(slider({ label: 'Out time', min: 0.1, max: 2, step: 0.05, value: tb.outDur, fmt: (v) => v.toFixed(2) + ' s', onInput: (v) => set({ outDur: v }, 'Out time', true), onDone: done }), 'tbOutDur'),
    h('p.tb-note', 'Start and length: the Inspector, or drag and trim it on the timeline.')));

  // ── the cut-out ──
  G.cut.push(host.section('Cut-out',
    tbStatusBlock(ed, id),
    field('Cut out', seg([['person', '🧍 Person'], ['subject', '📦 Main subject']], tb.key, (v) => set({ key: v }, v === 'subject' ? 'Cut out the main subject' : 'Cut out the person'), '.tb-key')),
    tag(slider({ label: 'Feather', min: 0, max: 1, step: 0.01, value: tb.feather, fmt: pct, onInput: (v) => set({ feather: v }, 'Edge feather', true), onDone: done }), 'tbFeather'),
    tag(slider({ label: 'Grow', min: -1, max: 1, step: 0.01, value: tb.grow, fmt: (v) => (v > 0 ? '+' : '') + Math.round(v * 100), onInput: (v) => set({ grow: v }, 'Edge grow', true), onDone: done }), 'tbGrow'),
    tag(slider({ label: 'Smoothness', min: 0, max: 1, step: 0.01, value: tb.smooth, fmt: pct, onInput: (v) => set({ smooth: v }, 'Edge smoothness', true), onDone: done }), 'tbSmooth'),
    h('p.tb-note', 'Feather softens the edge, Grow pushes it out (or in), Smoothness steadies it from frame to frame.')));
  return G;
}

// ── the colours: White · Country colours · One colour, and each country's own (themes/tb-colors.js) ──
const nameOf = (code) => REGIONS[code]?.en || CONTINENTS.find((c) => c.id === code)?.en || COUNTRIES.find((c) => c[0] === code)?.[1] || code.toUpperCase();
/** the places this element names: the word's, or each carousel entry's (in order, once each) */
export function tbCodes(item) {
  const s = item.style || {}, tb = tbOf(s);
  if (tb.mode !== 'carousel' || !tb.car.items.length) return [s.code || 'europe'];
  return [...new Set(tb.car.items.map(codeOfEntry).filter(Boolean))];
}
function colourRows(host, item, tb, shown, set, done, fills, custom) {
  const ed = host.ed, ctx = host.ctx, id = item.id, raw = () => ccOf(cur(ed, id)?.style?.tb || {});
  // a change to the colours: the element's cc, written whole (one step per change; colour drags coalesce)
  const writeCc = (fn, label, live = false) => { const c = raw(); const n = { mode: c.mode || shown, style: c.style, over: JSON.parse(JSON.stringify(c.over)) }; fn(n); if (!Object.keys(n.over).length) delete n.over; set({ cc: n }, label, live); };
  const rows = [field('Colours', seg(TB_CMODES.map(([v, l]) => [v, l, { white: 'White words (the look it always had)', country: 'Each word in its own country’s flag colours: a carousel changes colours with every country', one: 'One colour or gradient for every word (choose it below)' }[v]]), shown, (v) => writeCc((n) => { n.mode = v; }, v === 'country' ? 'Country colours' : v === 'white' ? 'White words' : 'One colour'), '.tb-cmode'))];
  if (shown === 'one') rows.push(fills, h('div.tb-row', h('span.lbl', 'Own colour'), custom));
  if (shown !== 'country') return rows;
  rows.push(field('Style', seg(TB_CSTYLES.map(([v, l]) => [v, l, { stripes: 'The flag’s stripes across the letters', solid: 'The flag’s main colour', two: 'Two of its colours, a third as the outline' }[v]]), tb.cc.style, (v) => writeCc((n) => { n.style = v; for (const [k, x] of Object.entries(n.over)) if (!x.style) { delete x.colors; if (!Object.keys(x).length) delete n.over[k]; } }, 'Colour style'), '.tb-cstyle')));
  const codes = tbCodes(item), list = h('div.tb-cc', { dataset: { tbCcList: '1' } });
  for (const code of codes) {
    const o = tb.cc.over[code] || null, L = countryLook(code, tb.cc, tb.outline), p = palOf(code);
    if (!L) continue;
    const cols = L.fill.colors;
    const own = (fn, label, live) => writeCc((n) => { const x = (n.over[code] ||= {}); fn(x); if (!Object.keys(x).length) delete n.over[code]; }, label, live);
    const pick = (val, label, data, on) => h('input', { type: 'color', value: val.toLowerCase(), 'aria-label': `${nameOf(code)} · ${label}`, title: `${nameOf(code)}: ${label}`, dataset: data, on: { input: (e) => on(e.target.value, true), change: () => { done(); host.render(); } } });
    const colPicks = cols.map((c, i) => pick(c, cols.length > 1 ? `colour ${i + 1}` : 'colour', { tbCcCol: code + ':' + i }, (v, live) => own((x) => { const base = x.colors && x.colors.length >= cols.length ? x.colors.slice() : cols.slice(); base[i] = v; x.colors = base; }, `${nameOf(code)} colour`, live)));
    const olPick = pick(L.outlineCol, 'outline', { tbCcOl: code }, (v, live) => own((x) => { x.outline = v; }, `${nameOf(code)} outline`, live));
    const sel = h('select', { 'aria-label': `${nameOf(code)} style`, title: 'This country’s style (or the one above for all)', dataset: { tbCcStyle: code }, on: { change: (e) => own((x) => { if (e.target.value) x.style = e.target.value; else delete x.style; delete x.colors; }, `${nameOf(code)} style`) } },
      h('option', { value: '', selected: !o?.style }, 'As above'), TB_CSTYLES.map(([v, l]) => h('option', { value: v, selected: o?.style === v }, l)));
    const reset = h('button.btn.small', { type: 'button', title: p ? `Put ${nameOf(code)} back to its flag’s colours` : 'Remove your own colours', disabled: !o, dataset: { tbCcReset: code }, on: { click: () => { own((x) => { for (const k of Object.keys(x)) delete x[k]; }, `${nameOf(code)} back to its flag`); } } }, '↺ Flag colours');
    list.append(h('div.tb-cc-row', { dataset: { tbCcRow: code, ...(o ? { tbCcOwn: '1' } : {}) } },
      h('div.tb-cc-name', h('i.tb-cc-sw', { style: { background: fillCss(L.fill) } }), h('span', nameOf(code))), sel,
      h('div.tb-cc-cols', ...colPicks, h('small', 'outline'), olPick, reset)));
  }
  rows.push(list, h('p.tb-note', codes.length
    ? 'Each place in its own flag’s colours. Change any colour here; ↺ puts that country back to its flag. Words you typed yourself (no country) use the One colour (white unless you chose one).'
    : 'Add countries to the carousel: each one comes up in its own colours.'));
  return rows;
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
      h('button.btn.small', { type: 'button', title: 'A huge word behind you at the playhead: you stay in front of it, the real background behind (its editor opens beside the video: words, carousel, look, position, cut-out)', dataset: { tbAdd: '1' }, on: { click: () => {
        try { const it = addTextBehind(ctx); const ed = window.kbbEditor; ed?.select?.([it.id]); rerender?.(); if (ed) import('./textbehind-popup.js').then((m) => m.openTbEditor(ed, it.id, { fresh: true })); } catch (e) { toast(e.message, 'error'); }
      } } }, '🔤 Text behind me'),
      h('button.btn.small', { type: 'button', title: 'Scan the captions (English, Arabic, Urdu, Hindi) and put each country or region you name behind you, big, for the sentence you say it in', dataset: { tbMentions: '1' }, on: { click: () => {
        try {
          const r = textBehindMentions(ctx, { top: top.checked }); rerender?.();
          if (!r.n) { toast('No country or region named in the captions', 'info', 4200); return; }
          // the editor opens only when he asks for it: a toast with Edit (the first placed element)
          const ed = window.kbbEditor, first = r.items[0];
          import('./textbehind-popup.js').then((m) => m.actionToast(`${r.n} text-behind element${r.n === 1 ? '' : 's'}: ${r.codes.map((c) => placeName(c, 'en')).join(', ')} · ⌘Z undoes`, '✎ Edit', () => { ed?.select?.([first.id]); ed?.seek?.(first.t0 + 0.05); m.openTbEditor(ed, first.id); }));
        } catch (e) { toast(e.message, 'error'); }
      } } }, '🔤 Text behind me for every country I mention'),
      h('label', { title: 'Put a smaller “JOBS IN” line above each place (its words are editable)' }, top, 'JOBS IN above')),
    n ? h('p.tb-note', `${n} text-behind element${n === 1 ? '' : 's'} from the captions: they follow caption edits.`) : null,
  ];
}
