// KBB Studio · themes · the CAPSULES CAROUSEL in the inspector: themes (live thumbnails of the owner's own capsules), the
// rows (show, direction, speed, colours), the capsule lists (add from the ready lists, type your own, rename, drag to
// reorder, remove, colours per capsule), sizes, motion, language. Every change is ONE undo step on the item's style.
import { h, clear, slider, seg, modal, toast } from '../editor/ui.js';
import {
  PRESETS, presetById, cloneCaps, setPreset, hasOverrides, drawCapsules, capsHeight, rowLook, loadFlags, capsFlagCodes, flagSvgs, drawLineIcon, hex, COLOR_KEYS, CAP_COLOR_KEYS,
} from './capsules.js';
import { LINE_ICONS, ICON_IDS, FEATURED_JOBS, CONTINENTS, COUNTRIES, COUNTRY_GROUPS, loadOccupations, jobCap, countryCap, continentCap, capId, arabicName } from './capsules-data.js';

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  s.textContent = `
.cps-note { font-size: 12px; color: var(--muted); margin: 0; line-height: 1.4; }
.cps-presets { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
.cps-preset { border: 2px solid var(--line2); background: var(--panel2); border-radius: 9px; padding: 0 0 3px; color: var(--text); cursor: pointer; font-size: 11.5px; font-weight: 600; overflow: hidden; text-align: center; }
.cps-preset canvas { display: block; width: 100%; aspect-ratio: 16 / 9; height: auto; }
.cps-preset span { display: block; padding: 3px 4px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cps-preset.on { border-color: var(--accent); }
.cps-preset:focus-visible { outline: 2px solid var(--accent); }
.cps-row { border: 1px solid var(--line2); border-radius: 10px; padding: 8px; display: flex; flex-direction: column; gap: 8px; background: var(--panel2); }
.cps-row-head { display: flex; align-items: center; gap: 8px; justify-content: space-between; font-size: 13px; }
.cps-list { display: flex; flex-direction: column; gap: 3px; max-height: 300px; overflow: auto; }
.cps-item { display: grid; grid-template-columns: 14px 26px minmax(0, 1fr) 10px 28px; align-items: center; gap: 5px; padding: 3px 4px; border-radius: 7px; border: 1px solid transparent; }
.cps-item.sel { border-color: var(--accent); background: var(--accent-soft, rgba(209,72,125,.16)); }
.cps-item.drop { box-shadow: inset 0 2px 0 var(--accent); }
.cps-grip { cursor: grab; color: var(--muted); font-size: 12px; text-align: center; user-select: none; }
.cps-mark { width: 26px; height: 20px; display: block; border-radius: 4px; }
.cps-list .cps-item > input[type=text] { width: 100%; min-width: 0; height: 28px; min-height: 28px; padding-block: 0; box-sizing: border-box; }
.cps-list .cps-item > button.cps-x { width: 28px; height: 28px; min-height: 28px; }
.cps-dot { width: 10px; height: 10px; border-radius: 50%; border: 1px solid rgba(255,255,255,.6); }
.cps-x { width: 26px; height: 26px; min-height: 26px; padding: 0; border: 1px solid var(--line2); background: transparent; color: var(--text); border-radius: 6px; cursor: pointer; }
.cps-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.cps-cc { display: grid; grid-template-columns: 52px 30px minmax(0, 1fr) repeat(3, 18px) 26px; gap: 5px; align-items: center; font-size: 12px; }
.cps-cc .lbl { color: var(--muted); }
.cps-cc input[type=color] { width: 30px; height: 26px; padding: 0; border: 1px solid var(--line2); border-radius: 6px; background: none; }
.cps-cc input.cps-hex { height: 26px; min-height: 26px; padding: 0 6px; font-size: 12px; font-family: ui-monospace, Menlo, monospace; }
.cps-cc .cps-chip { width: 18px; height: 18px; min-height: 18px; padding: 0; border-radius: 50%; border: 1px solid rgba(255,255,255,.55); cursor: pointer; }
.cps-cc .cps-x { width: 26px; height: 26px; }
.cps-cc.own .lbl { color: var(--text); font-weight: 700; }
.cps-colors { display: flex; flex-direction: column; gap: 5px; }
.cps-pick { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.cps-pick-cols { display: grid; grid-template-columns: 190px minmax(0, 1fr); gap: 12px; min-height: 380px; max-height: 60vh; }
.cps-conts { display: flex; flex-direction: column; gap: 4px; overflow: auto; }
.cps-conts button { text-align: start; display: flex; justify-content: space-between; gap: 6px; }
.cps-conts button.on { border-color: var(--accent); }
.cps-countries { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 4px 10px; overflow: auto; align-content: start; padding-inline-end: 4px; }
.cps-countries label { display: flex; align-items: center; gap: 7px; font-size: 13px; min-width: 0; cursor: pointer; }
.cps-countries label span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cps-countries img { width: 24px; height: 18px; border-radius: 3px; flex: none; box-shadow: 0 0 0 1px rgba(255,255,255,.2); }
.cps-icons { display: grid; grid-template-columns: repeat(auto-fill, 38px); gap: 5px; max-height: 220px; overflow: auto; }
.cps-icons button { width: 38px; height: 38px; min-height: 38px; padding: 0; display: grid; place-items: center; border: 1px solid var(--line2); background: var(--panel3); border-radius: 8px; cursor: pointer; }
.cps-icons button.on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
.cps-jobs { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 4px 10px; overflow: auto; max-height: 50vh; align-content: start; }
.cps-jobs label { display: flex; align-items: center; gap: 7px; font-size: 13px; cursor: pointer; min-width: 0; }
.cps-jobs label span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cps-jobs h5 { grid-column: 1 / -1; margin: 8px 0 2px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.cps-two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px; }
.cps-two label { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: var(--muted); }`;
  document.head.appendChild(s);
}

const ROW_NAME = { jobs: 'Top row · jobs', countries: 'Bottom row · countries' };
const COL_LABEL = { fill: 'Fill', text: 'Text', icon: 'Icon', border: 'Border', shadow: 'Shadow' };

/** the swatch the list and the pickers show for a capsule: its line icon or its flag */
function markCanvas(cap, color = '#f2f3f8') {
  const cv = h('canvas.cps-mark', { width: 52, height: 40, 'aria-hidden': 'true' }), g = cv.getContext('2d');
  const paint = (flags) => {
    g.clearRect(0, 0, 52, 40);
    if (cap.flag && flags?.[cap.flag]) { const im = new Image(); im.onload = () => { g.drawImage(im, 0, 0, 52, 40); }; im.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(flags[cap.flag]); }
    else drawLineIcon(g, cap.icon || (cap.cont ? 'globe' : 'star'), 12, 6, 28, color, 2.2);
  };
  if (cap.flag) flagSvgs().then(paint); else paint(null);
  return cv;
}

/** a colour control: swatch + hex + the company's colours + reset ("↺ theme") */
function colorCtl(label, value, own, company, onPick, onReset) {
  const v = hex(value && /^#/.test(value) ? value : '#ffffff');
  const sw = h('input', { type: 'color', value: v, 'aria-label': label + ' colour', title: label + ' colour' });
  const tx = h('input.cps-hex', { type: 'text', value: v.toUpperCase(), maxlength: 7, 'aria-label': label + ' hex', spellcheck: false });
  sw.addEventListener('input', () => { tx.value = sw.value.toUpperCase(); onPick(sw.value, true); });
  sw.addEventListener('change', () => onPick(sw.value, false));
  tx.addEventListener('keydown', (e) => e.stopPropagation());
  tx.addEventListener('change', () => { const x = tx.value.trim(); if (/^#?[0-9a-f]{6}$/i.test(x)) { const c = '#' + x.replace('#', '').toLowerCase(); sw.value = c; onPick(c, false); } else tx.value = sw.value.toUpperCase(); });
  const chips = company.slice(0, 3).map(([n, c]) => h('button.cps-chip', { type: 'button', title: `${n} ${c}`, 'aria-label': `${label}: ${n}`, style: { background: c }, on: { click: () => onPick(hex(c), false) } }));
  while (chips.length < 3) chips.push(h('span'));
  return h('div.cps-cc' + (own ? '.own' : ''), { dataset: { cc: label.toLowerCase() } }, h('span.lbl', label), sw, tx, ...chips,
    h('button.cps-x', { type: 'button', title: 'Reset to theme', 'aria-label': `Reset ${label.toLowerCase()} to theme`, disabled: !own, dataset: { reset: label.toLowerCase() }, on: { click: onReset } }, '↺'));
}

/**
 * the inspector section(s) for a capsules item, built into `box`. insp = the editor's Inspector (set, seal, render, section).
 * The selection of capsules lives on the inspector so it survives the section's own redraws.
 */
export function capsulesInspector(insp, item, box) {
  css();
  const ed = insp.ed, ws = ed.context?.().workspace || {};
  const wc = ws.colors || {};
  const company = [['Main', wc.primary], ['Accent', wc.accent || wc.secondary], ['Second', wc.secondary], ['Text', wc.text]].filter(([, c]) => /^#[0-9a-f]{6}$/i.test(c || '')).filter((x, i, a) => a.findIndex((y) => y[1].toLowerCase() === x[1].toLowerCase()) === i);
  company.push(['White', '#ffffff'], ['Navy', '#0B2A66']);
  const caps = () => item.style?.caps;
  insp.capsSel ||= { id: null, set: new Set() };
  if (insp.capsSel.id !== item.id) insp.capsSel = { id: item.id, set: new Set() };
  const sel = insp.capsSel.set;

  /** one undo step: change a copy of the model, write it (and the box height when it changes) */
  const commit = (label, fn, { live = false, refresh = true } = {}) => {
    const k = cloneCaps(caps()); fn(k);
    const props = { style: Object.assign({}, item.style, { caps: k }) };
    const nh = capsHeight(k);
    if (Math.abs(nh - item.h) > 1 && !item.kf?.h) { props.h = nh; props.y = Math.round(item.y + (item.h - nh) / 2); }
    if (!live) insp.seal();
    insp.set(item, props, label);
    if (!live) { insp.seal(); if (refresh) insp.render(true); }
    loadFlags(capsFlagCodes(k)).then(() => ed.preview?.draw?.());
  };

  const k0 = caps(); if (!k0) return;
  loadFlags(capsFlagCodes(k0)).then(() => { ed.preview?.draw?.(); redrawThumbs(); });

  // ── 1 · themes: a live thumbnail of the owner's own capsules in each look ──
  const thumbs = [];
  function thumb(p) {
    const cv = h('canvas', { width: 256, height: 144 });
    const draw = (t) => {
      const g = cv.getContext('2d'), k = Object.assign({}, caps(), { preset: p.id, fade: caps().fade });
      g.setTransform(1, 0, 0, 1, 0, 0);
      const bg = g.createLinearGradient(0, 0, 256, 144); bg.addColorStop(0, '#6f8fb3'); bg.addColorStop(0.5, '#c8a58a'); bg.addColorStop(1, '#3b4f6b'); g.fillStyle = bg; g.fillRect(0, 0, 256, 144);
      g.fillStyle = 'rgba(255,255,255,.35)'; g.beginPath(); g.arc(70, 40, 26, 0, 7); g.fill(); g.fillStyle = 'rgba(20,30,50,.35)'; g.fillRect(150, 20, 60, 110);
      const VW = 560, sc = 256 / VW, vh = 144 / sc;
      g.setTransform(sc, 0, 0, sc, 0, 0);
      try { drawCapsules(g, k, { w: VW, h: vh, t, tg: t, beats: [] }); } catch (e) { console.warn('[capsules] thumb', e); }
    };
    let raf = 0, t0 = 0;
    const loop = (now) => { if (!t0) t0 = now; draw(1.2 + (now - t0) / 1000); raf = requestAnimationFrame(loop); };
    const stop = () => { if (raf) cancelAnimationFrame(raf); raf = 0; t0 = 0; draw(1.2); };
    const on = caps().preset === p.id;
    const b = h('button.cps-preset' + (on ? '.on' : ''), { type: 'button', title: `${p.name}: ${p.note}. Keeps your capsules, sizes and position${hasOverrides(caps()) ? ' and your own colours' : ''}.`, 'aria-pressed': on ? 'true' : 'false', dataset: { capsPreset: p.id },
      on: { click: () => { if (caps().preset !== p.id) commit('Capsules theme · ' + p.name, (k) => { k.preset = p.id; }); }, mouseenter: () => { if (!raf) raf = requestAnimationFrame(loop); }, mouseleave: stop, focus: () => { if (!raf) raf = requestAnimationFrame(loop); }, blur: stop } },
      cv, h('span', p.name));
    thumbs.push(() => draw(1.2)); draw(1.2);
    return b;
  }
  function redrawThumbs() { for (const f of thumbs) f(); }
  const over = hasOverrides(k0);
  const secTheme = insp.section('Capsules · theme',
    h('p.cps-note', `${PRESETS.length} looks. Hover to play, click to switch: your capsules, sizes and position stay${over ? ', and so do your own colours' : ''}.`),
    h('div.cps-presets', PRESETS.map(thumb)),
    h('div.cps-btns',
      h('button.btn.small', { type: 'button', disabled: !over, title: 'Use the theme’s colours everywhere: clears every row and capsule colour of your own', dataset: { capsClear: '1' }, on: { click: () => { commit('Apply theme to all', (k) => Object.assign(k, setPreset(k, k.preset, { clear: true }))); sel.clear(); toast('Theme colours on every capsule · ⌘Z brings yours back', 'ok'); } } }, 'Apply theme to all (clear overrides)')),
    h('label.check', { title: 'Draw every theme in the company’s own colours (Appearance of the company)' }, h('input', { type: 'checkbox', checked: !!k0.brand, dataset: { capsBrand: '1' }, on: { change: (e) => commit(e.target.checked ? 'Company colours' : 'Theme colours', (k) => { k.brand = e.target.checked ? { primary: wc.primary || '#0B2A66', accent: wc.accent || wc.secondary || '#D7263D' } : null; }) } }), h('span', `Use company colours${ws.name ? ' (' + ws.name + ')' : ''}`)),
    h('div.row', h('span.lbl', 'Language'), seg([['en', 'English'], ['ar', 'عربي']], k0.lang || 'en', (v) => commit('Capsules language', (k) => { k.lang = v; }), '.cps-lang')));

  // ── 2 · the rows ──
  const rowCards = k0.rows.map((row, ri) => rowCard(row, ri));
  function rowCard(row, ri) {
    const L = rowLook(k0, row);
    const list = h('div.cps-list', { role: 'listbox', 'aria-multiselectable': 'true', 'aria-label': ROW_NAME[row.kind] || 'Capsules', dataset: { capsList: row.kind } });
    let dragFrom = -1;
    row.items.forEach((cap, i) => {
      const isSel = sel.has(cap.id), own = cap.col && Object.keys(cap.col).length;
      const lab = h('input', { type: 'text', value: k0.lang === 'ar' ? cap.ar || '' : cap.en || '', dir: k0.lang === 'ar' ? 'rtl' : 'auto', placeholder: k0.lang === 'ar' ? cap.en : 'Label', 'aria-label': 'Capsule label', dataset: { capsLabel: cap.id } });
      lab.addEventListener('keydown', (e) => e.stopPropagation());
      lab.addEventListener('change', () => commit('Rename capsule', (k) => { const c = k.rows[ri].items.find((x) => x.id === cap.id); if (c) c[k.lang === 'ar' ? 'ar' : 'en'] = lab.value.trim(); }));
      const el = h('div.cps-item' + (isSel ? '.sel' : ''), { role: 'option', 'aria-selected': isSel ? 'true' : 'false', draggable: 'true', dataset: { capsItem: cap.id, idx: String(i) }, title: 'Click to select (Shift / ⌘ for more) · drag to reorder',
        on: {
          click: (e) => { if (e.target.closest('input,button')) return; if (e.shiftKey || e.metaKey || e.ctrlKey) { sel.has(cap.id) ? sel.delete(cap.id) : sel.add(cap.id); } else if (sel.size === 1 && sel.has(cap.id)) sel.clear(); else { sel.clear(); sel.add(cap.id); } insp.render(true); },
          dragstart: (e) => { dragFrom = i; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/x-kbb-capsule', String(i)); },
          dragover: (e) => { if (dragFrom < 0) return; e.preventDefault(); el.classList.add('drop'); },
          dragleave: () => el.classList.remove('drop'),
          drop: (e) => { e.preventDefault(); el.classList.remove('drop'); const from = dragFrom; dragFrom = -1; if (from >= 0 && from !== i) commit('Reorder capsules', (k) => { const a = k.rows[ri].items; const [m] = a.splice(from, 1); a.splice(i, 0, m); }); },
          dragend: () => { dragFrom = -1; },
        } },
        h('span.cps-grip', { 'aria-hidden': 'true' }, '⋮⋮'), markCanvas(cap, L.icon && /^#/.test(L.icon) && row.kind === 'jobs' ? '#ff8a98' : '#f2f3f8'), lab,
        own ? h('span.cps-dot', { title: 'Has its own colours', style: { background: cap.col.fill || cap.col.text || cap.col.icon || cap.col.border }, dataset: { capsOwn: cap.id } }) : h('span'),
        h('button.cps-x', { type: 'button', title: 'Remove this capsule', 'aria-label': 'Remove ' + (cap.en || 'capsule'), dataset: { capsRemove: cap.id }, on: { click: () => { sel.delete(cap.id); commit('Remove capsule', (k) => { k.rows[ri].items = k.rows[ri].items.filter((x) => x.id !== cap.id); }); } } }, '✕'));
      list.append(el);
    });
    const adds = row.kind === 'countries'
      ? [h('button.btn.small', { type: 'button', dataset: { capsAddCountries: '1' }, on: { click: () => countryPicker(ri) } }, '＋ Countries & continents'), h('button.btn.small', { type: 'button', dataset: { capsAddCustom: row.kind }, on: { click: () => customDialog(ri) } }, '＋ Custom')]
      : [h('button.btn.small', { type: 'button', dataset: { capsAddJobs: '1' }, on: { click: () => jobPicker(ri) } }, '＋ Jobs list'), h('button.btn.small', { type: 'button', dataset: { capsAddCustom: row.kind }, on: { click: () => customDialog(ri) } }, '＋ Custom')];
    const rc = row.col || {};
    const rowColors = h('details', { dataset: { capsRowColors: row.kind } }, h('summary.cps-note', { style: { cursor: 'pointer' } }, 'Row colours' + (Object.keys(rc).length ? ' · your own' : '')),
      h('div.cps-colors', { style: { marginTop: '6px' } }, COLOR_KEYS.map((key) => colorCtl(COL_LABEL[key], rc[key] || (key === 'fill' && !L.fill ? '#ffffff' : L[key]), !!rc[key], company,
        (c, live) => commit('Row colour', (k) => { k.rows[ri].col = Object.assign({}, k.rows[ri].col, { [key]: c }); }, { live, refresh: !live }),
        () => commit('Row colour · theme', (k) => { delete k.rows[ri].col[key]; })))));
    return h('div.cps-row', { dataset: { capsRow: row.kind } },
      h('div.cps-row-head', h('label.check', h('input', { type: 'checkbox', checked: row.on !== false, dataset: { capsShow: row.kind }, on: { change: (e) => commit(e.target.checked ? 'Show row' : 'Hide row', (k) => { k.rows[ri].on = e.target.checked; }) } }), h('b', ROW_NAME[row.kind] || 'Row')), h('small.cps-note', `${row.items.length}`)),
      h('div.row', h('span.lbl', 'Direction'), seg([['left', '← Left'], ['right', 'Right →']], row.dir || 'left', (v) => commit('Row direction', (k) => { k.rows[ri].dir = v; }), '.cps-dir')),
      slider({ label: 'Speed', min: 0, max: 300, step: 1, value: row.speed ?? 60, fmt: (v) => Math.round(v) + ' px/s', onInput: (v) => commit('Row speed', (k) => { k.rows[ri].speed = v; }, { live: true }), onDone: insp.seal }),
      list, h('div.cps-btns', adds), rowColors);
  }

  // ── 3 · the selected capsules' own colours ──
  const selCaps = k0.rows.flatMap((r, ri) => r.items.filter((c) => sel.has(c.id)).map((c) => ({ c, ri, L: rowLook(k0, r) })));
  const selBox = selCaps.length ? h('div.cps-row', { dataset: { capsSelBox: String(selCaps.length) } },
    h('div.cps-row-head', h('b', selCaps.length === 1 ? `“${selCaps[0].c.en || selCaps[0].c.ar}” · its own colours` : `${selCaps.length} capsules · their own colours`), h('button.btn.small', { type: 'button', title: 'Clear the selection', on: { click: () => { sel.clear(); insp.render(true); } } }, 'Done')),
    h('div.cps-colors', CAP_COLOR_KEYS.map((key) => {
      const own = selCaps.some((x) => x.c.col?.[key]), cur = selCaps.find((x) => x.c.col?.[key])?.c.col[key] || selCaps[0].L[key] || '#ffffff';
      return colorCtl(COL_LABEL[key], cur, own, company,
        (c, live) => commit('Capsule colour', (k) => { for (const r of k.rows) for (const x of r.items) if (sel.has(x.id)) x.col = Object.assign({}, x.col, { [key]: c }); }, { live, refresh: !live }),
        () => commit('Capsule colour · theme', (k) => { for (const r of k.rows) for (const x of r.items) if (sel.has(x.id) && x.col) { delete x.col[key]; if (!Object.keys(x.col).length) delete x.col; } }));
    })),
    h('button.btn.small', { type: 'button', dataset: { capsResetSel: '1' }, disabled: !selCaps.some((x) => x.c.col), on: { click: () => commit('Capsule colours · theme', (k) => { for (const r of k.rows) for (const x of r.items) if (sel.has(x.id)) delete x.col; }) } }, '↺ Reset to theme'))
    : h('p.cps-note', 'Select a capsule in a list (Shift / ⌘ for several) to give it its own colours.');

  const secRows = insp.section('Capsules · rows', ...rowCards, selBox);

  // ── 4 · size, motion, text of the tabs ──
  const z = k0.size || {};
  const size = (key, label, min, max, step, unit = ' px') => slider({ label, min, max, step, value: z[key], fmt: (v) => (unit === '%' ? Math.round(v * 100) + '%' : Math.round(v) + unit), onInput: (v) => commit('Capsule ' + label.toLowerCase(), (k) => { k.size = Object.assign({}, k.size, { [key]: v }); }, { live: true }), onDone: insp.seal });
  const tabsIn = ['jobs', 'countries'].map((kk) => { const key = (k0.lang === 'ar' ? kk + 'Ar' : kk); const i = h('input', { type: 'text', value: k0.tabs?.[key] || '', dir: k0.lang === 'ar' ? 'rtl' : 'auto', 'aria-label': `Tab label, ${kk} row`, dataset: { capsTab: kk } }); i.addEventListener('keydown', (e) => e.stopPropagation()); i.addEventListener('change', () => commit('Tab label', (k) => { k.tabs = Object.assign({}, k.tabs, { [key]: i.value.trim() }); })); return h('label', kk === 'jobs' ? 'Top tab' : 'Bottom tab', i); });
  const W = ed.project.canvas.w, H = ed.project.canvas.h;
  const place = (where) => { const y = where === 'top' ? Math.round(H * 0.06) : where === 'mid' ? Math.round((H - item.h) / 2) : Math.round(H * 0.94 - item.h); insp.seal(); insp.set(item, { x: 0, y, w: W }, 'Carousel position'); insp.seal(); insp.render(true); };
  const secSize = insp.section('Capsules · size & motion',
    slider({ label: 'Scale', min: 0.4, max: 2.5, step: 0.01, value: k0.scale || 1, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => commit('Carousel scale', (k) => { k.scale = v; }, { live: true }), onDone: insp.seal }),
    size('h', 'Height', 28, 160, 1), size('font', 'Text', 10, 72, 1), size('icon', 'Icon', 10, 80, 1), size('gap', 'Gap', 0, 80, 1), size('radius', 'Corners', 0, 1, 0.01, '%'), size('rowGap', 'Row spacing', 0, 120, 1),
    h('div.row', h('span.lbl', 'Motion'), seg([['smooth', 'Smooth'], ['ease', 'Glide & rest'], ['beat', 'On the beat']], k0.motion || 'smooth', (v) => commit('Carousel motion', (k) => { k.motion = v; }), '.cps-motion')),
    slider({ label: 'Fade edges', min: 0, max: 0.4, step: 0.01, value: k0.fade || 0, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => commit('Fade edges', (k) => { k.fade = v; }, { live: true }), onDone: insp.seal }),
    h('div.row', h('span.lbl', 'Place'), h('div.cps-btns', h('button.btn.small', { type: 'button', on: { click: () => place('top') } }, 'Top'), h('button.btn.small', { type: 'button', on: { click: () => place('mid') } }, 'Middle'), h('button.btn.small', { type: 'button', on: { click: () => place('bottom') } }, 'Bottom'))),
    h('p.cps-note', 'Drag the carousel on the video to move it. Tilt and opacity are under Position & size (Rotate, Opacity).'),
    presetById(k0.preset).look('jobs', { primary: '#000000', accent: '#000000' }).tab ? h('div.cps-two', tabsIn) : null);

  clear(box).append(secTheme, secRows, secSize);

  // ── pickers ──
  function countryPicker(ri) {
    const have = new Set(caps().rows[ri].items.filter((c) => !c.cont).map((c) => c.flag).filter(Boolean)), haveCont = new Set(caps().rows[ri].items.map((c) => c.cont).filter(Boolean));
    const pick = new Set(have), pickCont = new Set(haveCont);
    let group = 'Europe', q = '';
    const contBox = h('div.cps-conts'), grid = h('div.cps-countries', { dataset: { capsCountries: '1' } });
    const search = h('input', { type: 'search', placeholder: 'Search a country…', 'aria-label': 'Search countries' });
    search.addEventListener('input', () => { q = search.value.trim().toLowerCase(); paint(); });
    search.addEventListener('keydown', (e) => e.stopPropagation());
    let SV = null; flagSvgs().then((s) => { SV = s; paint(); });
    const inGroup = (g) => COUNTRIES.filter((c) => c[2] === g);
    function paint() {
      clear(contBox).append(...COUNTRY_GROUPS.filter((g) => inGroup(g).length).map((g) => { const n = inGroup(g).filter((c) => pick.has(c[0])).length; return h('button.btn.small' + (g === group && !q ? '.on' : ''), { type: 'button', dataset: { capsGroup: g }, on: { click: () => { group = g; q = ''; search.value = ''; paint(); } } }, h('span', g), h('small', `${n}/${inGroup(g).length}`)); }));
      const list = q ? COUNTRIES.filter((c) => c[1].toLowerCase().includes(q) || arabicName(c[0], c[1]).includes(q)) : inGroup(group);
      const cont = CONTINENTS.find((c) => c.group === group);
      clear(grid).append(
        q ? null : h('div', { style: { gridColumn: '1 / -1', display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' } },
          h('button.btn.small', { type: 'button', dataset: { capsAll: group }, on: { click: () => { for (const c of list) pick.add(c[0]); paint(); } } }, `Select all ${group}`),
          h('button.btn.small', { type: 'button', dataset: { capsNone: group }, on: { click: () => { for (const c of list) pick.delete(c[0]); paint(); } } }, 'None'),
          cont ? h('label.check', { title: `A single “${cont.en}” capsule` }, h('input', { type: 'checkbox', checked: pickCont.has(cont.id), dataset: { capsCont: cont.id }, on: { change: (e) => { e.target.checked ? pickCont.add(cont.id) : pickCont.delete(cont.id); } } }), h('span', `“${cont.en}” as a capsule`)) : null),
        ...list.map(([code, name]) => h('label', { title: name, dataset: { capsCountry: code } }, h('input', { type: 'checkbox', checked: pick.has(code), on: { change: (e) => { e.target.checked ? pick.add(code) : pick.delete(code); } } }),
          SV?.[code] ? h('img', { alt: '', src: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(SV[code]) }) : h('span', { style: { width: '24px' } }), h('span', name))));
    }
    paint();
    modal({ title: 'Countries & continents', wide: true, body: h('div.cps-pick', h('p.cps-note', 'Tick countries in any continent (Select all takes a whole continent), or the continent itself as one capsule. Countries you untick are removed from the row.'), search, h('div.cps-pick-cols', contBox, grid)),
      actions: [{ label: 'Cancel' }, { label: 'Apply', primary: true, id: 'caps-pick-apply', onClick: () => commit('Countries', (k) => {
        const r = k.rows[ri];
        r.items = r.items.filter((c) => (c.flag && !c.cont ? pick.has(c.flag) || !COUNTRIES.some((x) => x[0] === c.flag) : c.cont ? pickCont.has(c.cont) : true));
        for (const id of pickCont) if (!r.items.some((c) => c.cont === id)) r.items.push(continentCap(id));
        for (const code of pick) if (!r.items.some((c) => c.flag === code && !c.cont)) r.items.push(countryCap(code));
      }) }] });
  }

  async function jobPicker(ri) {
    const O = await loadOccupations();
    const have = new Set(caps().rows[ri].items.map((c) => (c.en || '').toLowerCase()));
    const pick = new Map();
    let q = '';
    const grid = h('div.cps-jobs', { dataset: { capsJobs: '1' } });
    const search = h('input', { type: 'search', placeholder: 'Search jobs: nurse, driver, welder…', 'aria-label': 'Search jobs' });
    search.addEventListener('input', () => { q = search.value.trim().toLowerCase(); paint(); });
    search.addEventListener('keydown', (e) => e.stopPropagation());
    const row = (key, en, ar, icon) => {
      const on = pick.has(key) || have.has(en.toLowerCase());
      return h('label', { title: en + (ar ? ' · ' + ar : ''), dataset: { capsJob: key } }, h('input', { type: 'checkbox', checked: on, disabled: have.has(en.toLowerCase()), on: { change: (e) => { e.target.checked ? pick.set(key, { en, ar, icon }) : pick.delete(key); } } }), markCanvas({ icon }), h('span', en));
    };
    function paint() {
      const m = (en, ar) => !q || en.toLowerCase().includes(q) || (ar || '').includes(q);
      const kids = [h('h5', 'Featured')];
      for (const [en, ar, ic] of FEATURED_JOBS) if (m(en, ar)) kids.push(row('f:' + en, en, ar, ic));
      for (const s of O.sectors) { const L = O.list.filter((o) => o.sector === s.id && m(o.en, o.ar)); if (!L.length) continue; kids.push(h('h5', s.en)); for (const o of L) kids.push(row('o:' + o.id, o.en, o.ar, o.icon)); }
      clear(grid).append(...kids);
    }
    paint();
    modal({ title: 'Jobs · ready list', wide: true, body: h('div.cps-pick', h('p.cps-note', `${O.list.length} occupations in ${O.sectors.length} sectors, English and Arabic, each with its line icon. Ticked ones are added to the end of the row.`), search, grid),
      actions: [{ label: 'Cancel' }, { label: 'Add', primary: true, id: 'caps-jobs-apply', onClick: () => { if (pick.size) commit(`Add ${pick.size} job${pick.size > 1 ? 's' : ''}`, (k) => { for (const v of pick.values()) k.rows[ri].items.push(jobCap(v.en, v.ar, v.icon)); }); } }] });
  }

  function customDialog(ri) {
    const isC = caps().rows[ri].kind === 'countries';
    let icon = isC ? 'globe' : 'star', flag = '';
    const en = h('input', { type: 'text', placeholder: isC ? 'e.g. Schengen' : 'e.g. Welders', 'aria-label': 'Label (English)', dataset: { capsCustomEn: '1' } });
    const ar = h('input', { type: 'text', dir: 'rtl', placeholder: 'بالعربية (اختياري)', 'aria-label': 'Label (Arabic)', dataset: { capsCustomAr: '1' } });
    for (const i of [en, ar]) i.addEventListener('keydown', (e) => e.stopPropagation());
    const flagSel = h('select', { 'aria-label': 'Flag', dataset: { capsCustomFlag: '1' } }, h('option', { value: '' }, 'No flag (use the icon)'), COUNTRIES.map(([c, n]) => h('option', { value: c }, n)));
    flagSel.addEventListener('change', () => { flag = flagSel.value; });
    const icons = h('div.cps-icons', { role: 'radiogroup', 'aria-label': 'Icon' }, ICON_IDS.map((id) => {
      const cv = h('canvas', { width: 52, height: 52, style: { width: '26px', height: '26px' }, 'aria-hidden': 'true' }); drawLineIcon(cv.getContext('2d'), id, 6, 6, 40, '#f2f3f8', 2.2);
      return h('button' + (id === icon ? '.on' : ''), { type: 'button', role: 'radio', 'aria-checked': id === icon ? 'true' : 'false', title: LINE_ICONS[id][0], 'aria-label': LINE_ICONS[id][0], dataset: { capsIcon: id }, on: { click: (e) => { icon = id; for (const b of icons.children) { b.classList.toggle('on', b === e.currentTarget); b.setAttribute('aria-checked', b === e.currentTarget ? 'true' : 'false'); } } } }, cv);
    }));
    modal({ title: 'Custom capsule', body: h('div.cps-pick', h('div.cps-two', h('label', 'English', en), h('label', 'Arabic', ar)), isC ? h('label.row', h('span.lbl', 'Flag'), flagSel) : null, h('b', { style: { fontSize: '12px' } }, 'Icon'), icons),
      actions: [{ label: 'Cancel' }, { label: 'Add capsule', primary: true, id: 'caps-custom-apply', onClick: () => {
        const e1 = en.value.trim(), a1 = ar.value.trim(); if (!e1 && !a1) { toast('Type a label first', 'error'); return false; }
        commit('Add custom capsule', (k) => { k.rows[ri].items.push(flag ? { id: capId(), en: e1 || a1, ar: a1, flag } : { id: capId(), en: e1 || a1, ar: a1, icon }); });
      } }] });
  }
}
