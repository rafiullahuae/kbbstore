// KBB Studio · Designs · THE DESIGN EDITOR: pick a design → change anything → export.
//   canvas: click to select, drag to move, drag the corner to resize (snaps to the safe area and the centre line)
//   layers on the left; inspector on the right: text, size, font role, colour (scheme colour or any colour), plate,
//   picture swap (catalogue product, saved AI picture, his own file), hide / delete; colour scheme and artwork for the
//   whole design; the design rules re-checked live after every change; undo / redo (⌘Z / ⇧⌘Z)
//   ⬇ Export: PNG or JPG, Full HD (1080 wide) or 4K (2160 wide), this size or every size → workspaces/<co>/exports/designs/
//   🎬 Make a matching reel: a new reel with the brief's spec, the matching recipe and this design as its cover/end card
import { engine } from '../core/engine.js';
import { createProject } from '../core/project.js';
import { h, clear, toast } from '../editor/ui.js';
import { layout, prepare, draw, PX, renderCanvas, colorOf, toBlob } from './render.js';
import { SAFE } from './layouts.js';
import { recheck } from './generate.js';
import { schemesFor } from './lib/palette.js';
import { artFor, ART_KINDS } from './art.js';
import { claimWarnings } from './lib/copy.js';
import { searchIcons, loadIcons } from './icons.js';
const ap = (el, ...k) => { el.append(...k.flat(Infinity).filter((x) => x != null && x !== false)); return el; };

const PKEYS = [['ink', 'Text'], ['sub', 'Soft text'], ['accText', 'Accent text'], ['acc', 'Accent'], ['acc2', 'Accent 2'], ['onAcc', 'On accent'], ['card', 'Card'], ['cardInk', 'Card text'], ['bg', 'Background'], ['bg2', 'Background 2']];
export const designsDir = (ws) => `workspaces/${ws}/designs`;

export async function saveDesign(D) {
  D.modified = new Date().toISOString();
  const dir = designsDir(D.workspace);
  await engine.writeFile(`${dir}/${D.id}.json`, D);
  await prepare(D);
  const size = D.sizes.includes('post') ? 'post' : D.sizes[0];
  await engine.writeFile(`${dir}/${D.id}.png`, await toBlob(renderCanvas(D, size, 0.4), 'image/png'));
  return `${dir}/${D.id}.json`;
}
export async function listSaved(ws) {
  const dir = designsDir(ws);
  const ents = await engine.listDir(dir).catch(() => []);
  return ents.filter((e) => e.name.endsWith('.json') && !e.name.startsWith('palettes')).map((e) => ({ path: `${dir}/${e.name}`, thumb: `${dir}/${e.name.replace(/\.json$/, '.png')}`, name: e.name, mtime: e.mtime })).sort((a, b) => b.mtime - a.mtime);
}

/** export one design: sizes ('all' or one), scale 1 (full HD) | 2 (4K), type png|jpg -> [paths] */
export async function exportDesign(D, { sizes, scale = 1, type = 'png' } = {}) {
  await prepare(D);
  const out = [];
  for (const s of sizes || D.sizes) {
    const c = renderCanvas(D, s, scale);
    const blob = await toBlob(c, type === 'jpg' ? 'image/jpeg' : 'image/png', 0.95);
    const p = `workspaces/${D.workspace}/exports/designs/${(D.name || D.family).replace(/[^\w-]+/g, '-').replace(/-+/g, '-').toLowerCase()}-${s}-${c.width}x${c.height}.${type}`;
    await engine.writeFile(p, blob);
    out.push({ path: p, w: c.width, h: c.height, bytes: blob.size });
  }
  return out;
}

const RECIPE = (spec) => {
  const k = spec?.kind;
  if (k === 'hiring') return 'wa-we-are-hiring';
  if (k === 'study') return spec.countries?.[0] ? 'pc-spotlight-' + spec.countries[0] : 'pc-study-visa-steps';
  if (k === 'visa') return 'pc-country-spotlight';
  if (k === 'event') return 'pc-webinar-promo';
  if (k === 'launch') return 'kbb-unboxing';
  if (k === 'offer') return spec.industry === 'beauty' ? 'kbb-flash-sale' : 'announcement';
  return 'announcement';
};

export function openDesignEditor(D0, { ws, cat, spec, size, home, onSaved }) {
  let D = structuredClone(D0);
  const undo = [], redo = [];
  let cur = D.sizes.includes(size) ? size : D.sizes[0], sel = null, scale = 0.4, items = [];
  const cv = h('canvas#de-canvas');
  const stage = h('div.de-stage', cv), layers = h('nav.de-layers'), insp = h('aside.de-insp'), rules = h('div.de-rules#de-rules');
  const sizeSeg = h('div.seg#de-sizes');
  const snap = () => { undo.push(JSON.stringify(D)); if (undo.length > 80) undo.shift(); redo.length = 0; };
  const back = h('div.de', { role: 'dialog', 'aria-label': 'Design editor' },
    h('header.topbar',
      h('button.btn#de-back', { on: { click: () => close() } }, '← Designs'),
      h('input.proj-name#de-name', { value: D.name, 'aria-label': 'Design name', on: { change: (e) => { snap(); D.name = e.target.value.trim() || D.name; } } }),
      sizeSeg, h('div.grow'),
      h('button.icon-btn#de-undo', { title: 'Undo (⌘Z)', on: { click: () => doUndo() } }, '↶'), h('button.icon-btn#de-redo', { title: 'Redo (⇧⌘Z)', on: { click: () => doRedo() } }, '↷'),
      h('button.btn#de-save', { on: { click: async () => { await saveDesign(D); toast('Saved in designs/', 'ok'); onSaved?.(); } } }, '💾 Save'),
      h('button.btn#de-reel', { on: { click: () => matchingReel() } }, '🎬 Make a matching reel'),
      h('button.btn.primary#de-export', { on: { click: () => exportDialog() } }, '⬇ Export')),
    h('div.de-body', layers, stage, insp));
  document.body.appendChild(back);
  const onKey = (e) => {
    if (document.querySelector('.modal-back')) return;
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? doRedo() : doUndo(); }
    else if (e.key === 'Escape') { sel = null; paint(); }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && sel) { snap(); D.els = D.els.filter((x) => x.id !== sel); sel = null; changed(); }
    else if (sel && e.key.startsWith('Arrow')) { e.preventDefault(); snap(); const g = D.geo[cur][sel], d = e.shiftKey ? 10 : 2; if (e.key === 'ArrowLeft') g.x -= d; if (e.key === 'ArrowRight') g.x += d; if (e.key === 'ArrowUp') g.y -= d; if (e.key === 'ArrowDown') g.y += d; changed(); }
  };
  document.addEventListener('keydown', onKey, true);
  function close() { document.removeEventListener('keydown', onKey, true); ro.disconnect(); back.remove(); }
  function doUndo() { if (!undo.length) return; redo.push(JSON.stringify(D)); D = JSON.parse(undo.pop()); changed(); }
  function doRedo() { if (!redo.length) return; undo.push(JSON.stringify(D)); D = JSON.parse(redo.pop()); changed(); }

  // ── drawing ──
  function fit() { const [W, H] = PX[cur]; const r = stage.getBoundingClientRect(); scale = Math.max(0.1, Math.min((r.width - 40) / W, (r.height - 40) / H)); cv.width = Math.round(W * scale * devicePixelRatio); cv.height = Math.round(H * scale * devicePixelRatio); cv.style.width = Math.round(W * scale) + 'px'; cv.style.height = Math.round(H * scale) + 'px'; }
  function paint() {
    const x = cv.getContext('2d'), s = scale * devicePixelRatio, [W, H] = PX[cur];
    items = layout(D, cur);
    x.clearRect(0, 0, cv.width, cv.height);
    draw(x, D, cur, { scale: s, items });
    x.save(); x.scale(s, s);
    const A = SAFE[cur]; x.setLineDash([10, 8]); x.strokeStyle = 'rgba(108,140,255,.35)'; x.lineWidth = 2 / scale * 0.5; x.strokeRect(A.x0, A.y0, A.x1 - A.x0, A.y1 - A.y0);
    const it = items.find((i) => i.el.id === sel);
    if (it) { const b = it.text ? it.text.box : it.el.t === 'prod' || it.el.t === 'logo' ? it.box : it.g; x.setLineDash([]); x.strokeStyle = '#ffd166'; x.lineWidth = 3 / scale * 0.6; x.strokeRect(b.x, b.y, b.w, b.h || it.box.h); x.fillStyle = '#ffd166'; const hs = 14 / scale * 0.6; x.fillRect(it.g.x + it.g.w - hs / 2, it.g.y + (it.g.h || it.box.h) - hs / 2, hs, hs); }
    x.restore();
    paintLayers(); paintSizes();
  }
  let rT = 0;
  async function changed() { await prepare(D); paint(); paintInsp(); clearTimeout(rT); rT = setTimeout(async () => paintRules(await recheck(D)), 250); }
  function paintRules(r) {
    ap(clear(rules), h('b', r.pass ? `✓ Every design rule passes (score ${r.score})` : '✗ Some rules fail — fix before exporting, or export anyway'));
    for (const [s, checks] of Object.entries(r.bySize)) for (const c of checks) if (!c.ok || s === cur) rules.appendChild(h('div.' + (c.ok ? 'ok' : 'bad'), `${c.ok ? '✓' : '✗'} ${s} · ${c.id}: ${c.msg}`));
  }
  function paintSizes() { clear(sizeSeg); for (const s of D.sizes) sizeSeg.appendChild(h('button' + (s === cur ? '.on' : ''), { dataset: { v: s }, on: { click: () => { cur = s; fit(); paint(); } } }, s)); }
  function paintLayers() {
    clear(layers).appendChild(h('b', { style: { padding: '4px 8px' } }, 'Layers'));
    for (const el of [...D.els].reverse()) { if (D.geo[cur]?.[el.id]?.hd) continue; layers.appendChild(h('button' + (el.id === sel ? '.on' : ''), { dataset: { el: el.id }, on: { click: () => { sel = el.id; paint(); paintInsp(); } } }, { text: '𝐓', btn: '▭', shape: '◆', icon: '✳', prod: '🧴', img: '🖼', logo: '◎' }[el.t] || '•', ' ', el.name || el.id, el.hide ? ' (hidden)' : '')); }
  }

  // ── pointer: select / move / resize ──
  let drag = null;
  cv.addEventListener('pointerdown', (e) => {
    const r = cv.getBoundingClientRect(), px = (e.clientX - r.left) / scale, py = (e.clientY - r.top) / scale;
    const cur0 = items.find((i) => i.el.id === sel);
    if (cur0) { const g = cur0.g, hs = 18 / scale * 0.6; if (Math.abs(px - (g.x + g.w)) < hs && Math.abs(py - (g.y + (g.h || cur0.box.h))) < hs) { snap(); drag = { mode: 'size', id: sel, px, py, g0: { ...g } }; cv.setPointerCapture(e.pointerId); return; } }
    const hit = [...items].reverse().filter((i) => { const b = i.text ? i.text.box : i.g; return px >= b.x && px <= b.x + b.w && py >= b.y && py <= b.y + (b.h || i.box.h); });
    const pick = hit.find((i) => !i.el.deco) || hit[0];
    sel = pick?.el.id || null;
    if (pick) { snap(); drag = { mode: 'move', id: sel, px, py, g0: { ...D.geo[cur][sel] } }; cv.setPointerCapture(e.pointerId); }
    paint(); paintInsp();
  });
  cv.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const r = cv.getBoundingClientRect(), px = (e.clientX - r.left) / scale, py = (e.clientY - r.top) / scale, g = D.geo[cur][drag.id], [W] = PX[cur], A = SAFE[cur];
    if (drag.mode === 'move') {
      g.x = Math.round(drag.g0.x + px - drag.px); g.y = Math.round(drag.g0.y + py - drag.py);
      const cx = g.x + g.w / 2; if (Math.abs(cx - W / 2) < 10) g.x = Math.round(W / 2 - g.w / 2); // snap to centre
      if (Math.abs(g.x - A.x0) < 10) g.x = A.x0; if (Math.abs(g.x + g.w - A.x1) < 10) g.x = A.x1 - g.w;
    } else {
      const k = Math.max(0.2, (drag.g0.w + px - drag.px) / drag.g0.w);
      g.w = Math.round(drag.g0.w * k); if (drag.g0.h) g.h = Math.round(drag.g0.h * (e.shiftKey ? (drag.g0.h + py - drag.py) / drag.g0.h : k)); if (drag.g0.fs && !drag.g0.h) g.fs = Math.round(drag.g0.fs * k * 10) / 10;
    }
    paint();
  });
  cv.addEventListener('pointerup', () => { if (drag) { drag = null; changed(); } });

  // ── inspector ──
  function colourPicker(get, set, label = 'Colour') {
    const s = h('select', { 'aria-label': label }, h('option', { value: '' }, 'Custom…'), PKEYS.map(([k, l]) => h('option', { value: k }, l)));
    const c = h('input', { type: 'color' });
    const v = get(); s.value = v.pc && !v.col ? v.pc : ''; c.value = colorOf(D, v.pc, v.col);
    s.addEventListener('change', () => { snap(); set(s.value ? { pc: s.value, col: undefined } : { col: c.value }); changed(); });
    c.addEventListener('change', () => { snap(); set({ col: c.value }); changed(); });
    return h('div.row', h('span', label), s, c);
  }
  function paintInsp() {
    clear(insp);
    const el = D.els.find((x) => x.id === sel);
    if (!el) { paintDesignPanel(); return; }
    const g = D.geo[cur][el.id];
    ap(insp, h('h3', el.name || el.id));
    if (el.t === 'text' || el.t === 'btn') {
      const ta = h('textarea#de-text', { value: el.txt, dir: 'auto', 'aria-label': 'Text' });
      const warn = h('div.dz-note');
      ta.addEventListener('change', () => { snap(); el.txt = ta.value; warn.textContent = claimWarnings(ta.value).join(' '); changed(); });
      const fs = h('input#de-fs', { type: 'range', min: 18, max: 400, value: g.fs || 40 });
      fs.addEventListener('input', () => { g.fs = +fs.value; paint(); }); fs.addEventListener('change', () => { snap(); changed(); });
      const font = h('select', { 'aria-label': 'Font' }, [['head', 'Heading'], ['body', 'Body'], ['serif', 'Serif'], ['display', 'Display (tall)']].map(([v, l]) => h('option', { value: v, selected: el.font === v }, l)));
      font.addEventListener('change', () => { snap(); el.font = font.value; changed(); });
      const fw = h('select', { 'aria-label': 'Weight' }, [400, 500, 600, 700, 800, 900].map((w) => h('option', { value: w, selected: +el.fw === w }, w)));
      fw.addEventListener('change', () => { snap(); el.fw = +fw.value; changed(); });
      const al = h('div.seg', [['l', '⟸'], ['c', '≡'], ['r', '⟹']].map(([v, l]) => h('button' + ((g.al || el.al || 'c') === v ? '.on' : ''), { on: { click: () => { snap(); g.al = v; changed(); } } }, l)));
      ap(insp, ta, warn, h('div.row', h('span', 'Size'), fs), h('div.row', h('span', 'Font'), font, fw), h('div.row', h('span', 'Align'), al), colourPicker(() => el, (v) => Object.assign(el, v)));
      if (el.t === 'btn') ap(insp, colourPicker(() => ({ pc: el.fill, col: el.fillCol }), (v) => { el.fill = v.pc || el.fill; el.fillCol = v.col; }, 'Button'));
      else { const pl = h('input', { type: 'checkbox', checked: !!el.plate }); pl.addEventListener('change', () => { snap(); el.plate = pl.checked ? { pc: 'card', op: 0.95, pad: 0.4 } : null; changed(); }); ap(insp, h('label.row', pl, 'Plate behind the text'), el.plate ? colourPicker(() => el.plate, (v) => Object.assign(el.plate, v), 'Plate') : null); }
    } else if (el.t === 'shape') ap(insp, colourPicker(() => ({ pc: el.fill, col: el.fillCol }), (v) => { el.fill = v.pc || el.fill; el.fillCol = v.col; }, 'Fill'));
    else if (el.t === 'icon') {
      const q = h('input', { type: 'search', placeholder: 'Find another icon', 'aria-label': 'Find icon' }), res = h('div.dz-row');
      q.addEventListener('input', async () => { await loadIcons(); clear(res); for (const n of searchIcons(q.value, 12)) res.appendChild(h('button.chip', { on: { click: () => { snap(); el.ic = n; changed(); } } }, n)); });
      const st = h('div.seg', ['line', 'fill', 'duotone', 'badge'].map((v) => h('button' + (el.style === v ? '.on' : ''), { on: { click: () => { snap(); el.style = v; changed(); } } }, v)));
      ap(insp, h('div.row', h('span', 'Style'), st), colourPicker(() => el, (v) => Object.assign(el, v)), q, res);
    } else if (el.t === 'prod' || el.t === 'img') {
      const pick = h('div.dz-row');
      if (el.t === 'prod') for (const p of cat.products) pick.appendChild(h('button.chip', { title: p.brand + ' ' + p.name, on: { click: () => { snap(); el.src = p.src; el.name = p.name; changed(); } } }, h('img', { src: '/' + p.src, alt: '', style: { height: '26px' } })));
      const file = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp' });
      file.addEventListener('change', async () => { const f = file.files[0]; if (!f) return; const p = `workspaces/${ws.id}/designs/images/upload-${Date.now().toString(36)}.${(f.name.split('.').pop() || 'png').toLowerCase().replace(/[^a-z]/g, '')}`; await engine.writeFile(p, f); snap(); el.src = p; if (el.t === 'prod') el.fit = 'contain'; changed(); });
      const saved = h('div.dz-row');
      engine.listDir(`workspaces/${ws.id}/designs/images`).then((ls) => { for (const f of ls.filter((x) => /\.(png|jpe?g|webp)$/.test(x.name)).slice(-12)) saved.appendChild(h('button.chip', { on: { click: () => { snap(); el.src = `workspaces/${ws.id}/designs/images/${f.name}`; changed(); } } }, h('img', { src: `/workspaces/${ws.id}/designs/images/${f.name}`, alt: '', style: { height: '30px' } }))); }).catch(() => {});
      ap(insp, h('div.dz-small', el.t === 'prod' ? 'Swap the product (real cut-outs only):' : 'Swap the picture:'), pick, h('div.dz-small', 'Your AI pictures and uploads:'), saved, h('label.btn.small', 'Upload a picture…', file));
    } else if (el.t === 'logo') ap(insp, colourPicker(() => el, (v) => Object.assign(el, v), 'Logo on'));
    const num = (k) => { const i = h('input.de-num', { type: 'number', value: Math.round(g[k] || 0), 'aria-label': k }); i.addEventListener('change', () => { snap(); g[k] = +i.value; changed(); }); return h('span', k.toUpperCase() + ' ', i); };
    const op = h('input', { type: 'range', min: 10, max: 100, value: el.op ?? 100 }); op.addEventListener('change', () => { snap(); el.op = +op.value; changed(); });
    ap(insp, h('div.row', num('x'), num('y')), h('div.row', num('w'), num('h')), h('div.row', h('span', 'Opacity'), op),
      h('div.row', h('button.btn.small', { on: { click: () => { snap(); g.hd = true; sel = null; changed(); } } }, 'Hide in this size'), h('button.btn.small.danger', { on: { click: () => { snap(); D.els = D.els.filter((x) => x !== el); sel = null; changed(); } } }, 'Delete')),
      h('h3', 'Design rules'), rules);
  }
  function paintDesignPanel() {
    const pals = schemesFor(spec || { ...D.spec, languages: D.spec.languages || ['en'], sizes: D.sizes, offer: null }, ws, cat);
    const box = h('div');
    for (const p of pals) box.appendChild(h('div.dz-pal', { dataset: { pal: p.id }, on: { click: () => { snap(); D.palette = { ...p }; D.paletteId = p.id; for (const el of D.els) { if (el.col && !el.keepCol) delete el.col; if (el.plate?.col) delete el.plate.col; } changed(); } } }, h('span.dz-sw', [p.bg, p.bg2, p.acc, p.ink].map((c) => h('i', { style: { background: c } }))), h('span', p.name), p.id === D.paletteId ? h('b', '✓') : null));
    const arts = h('div.dz-row', ART_KINDS.map((k) => { const on = D.art.layers.some((l) => l.k === k); return h('button.chip' + (on ? '.on' : ''), { on: { click: () => { snap(); D.art.layers = on ? D.art.layers.filter((l) => l.k !== k) : D.art.layers.concat([{ k, op: 0.85 }]); changed(); } } }, k); }));
    const base = h('div.seg', ['linear', 'radial', 'mesh', 'solid'].map((b) => h('button' + (D.art.base === b ? '.on' : ''), { on: { click: () => { snap(); D.art.base = b; changed(); } } }, b)));
    ap(insp, h('h3', 'Colour scheme'), box, h('h3', 'Artwork'), h('div.row', base, h('button.btn.small', { on: { click: () => { snap(); D.art.seed = (D.art.seed || 1) + 1; changed(); } } }, '↻ Shuffle')), arts,
      D.bgImage ? h('button.btn.small', { on: { click: () => { snap(); D.bgImage = null; changed(); } } }, 'Remove the background picture') : null,
      h('h3', 'Design rules'), rules, h('p.dz-small', 'Click anything on the design to change it. Drag to move; drag the yellow corner to resize; arrows nudge (Shift = 10 px).'));
  }

  // ── export + matching reel ──
  async function exportDialog() {
    const { modal } = await import('../editor/ui.js');
    const which = h('select#de-x-sizes', h('option', { value: cur }, `This size (${cur})`), h('option', { value: 'all' }, `Every size (${D.sizes.join(', ')})`));
    const q = h('select#de-x-q', h('option', { value: 1 }, 'Full HD (1080 wide)'), h('option', { value: 2 }, '4K (2160 wide)'));
    const t = h('select#de-x-type', h('option', { value: 'png' }, 'PNG'), h('option', { value: 'jpg' }, 'JPG'));
    const r = await recheck(D);
    modal({ title: 'Export design', body: h('div', h('div.row', which), h('div.row', q, t), r.pass ? h('p.dz-small', '✓ Every design rule passes.') : h('p.dz-note', '⚠ Some design rules fail (see the list in the editor). You can still export.')),
      actions: [{ label: 'Cancel' }, { label: '⬇ Export', primary: true, id: 'de-x-go', onClick: async () => {
        const res = await exportDesign(D, { sizes: which.value === 'all' ? D.sizes : [which.value], scale: +q.value, type: t.value });
        toast(`Saved ${res.length} file${res.length > 1 ? 's' : ''} in exports/designs (${res.map((x) => x.w + '×' + x.h).join(', ')})`, 'ok', 4000);
        back.dataset.lastExport = JSON.stringify(res);
      } }] });
  }
  async function matchingReel() {
    const sp = spec || D.spec;
    await saveDesign(D);
    const story = D.sizes.includes('story') ? 'story' : D.sizes[0];
    const [png] = await exportDesign(D, { sizes: [story], scale: 1, type: 'png' });
    const lib = `workspaces/${ws.id}/library/designs/${D.id}-${story}.png`;
    await engine.writeFile(lib, await engine.readFile(png.path, 'blob'));
    const p = createProject({ name: (D.name || 'Design') + ' — reel', workspace: ws.id, brand: ws.brandRef || ws.id, recipe: RECIPE(sp), aspect: '9:16' });
    p.meta = { brief: { text: sp.brief, spec: { kind: sp.kind, occasion: sp.occasion?.id || sp.occasion, offer: sp.offer, products: sp.products, countries: sp.countries, occupations: sp.occupations, tone: sp.tone, languages: sp.languages, cta: sp.cta } }, design: { id: D.id, image: lib, copy: D.els.filter((e) => e.t === 'text' || e.t === 'btn').map((e) => ({ role: e.role, text: e.txt })) } };
    await engine.saveProject(p);
    toast('New reel made from this brief — opening it', 'ok');
    close();
    document.querySelector('.dz')?.remove();
    home?.open(p);
  }

  const ro = new ResizeObserver(() => { fit(); paint(); });
  ro.observe(stage);
  prepare(D).then(() => { fit(); paint(); paintInsp(); recheck(D).then(paintRules); });
  return { close, get design() { return D; }, select(id) { sel = id; paint(); paintInsp(); }, exportDesign: (o) => exportDesign(D, o), setSize(s) { cur = s; fit(); paint(); } };
}
