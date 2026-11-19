// KBB Studio · themes · ELEMENTS 2 in the editor: the inspector sections of a contact card, a logo badge, a country
// badge and a cut-out transition, and the "One click" card of the ✨ Themes tab (Add logo · Badge every country I
// mention). Every change is ONE undo step (sliders coalesce into one while dragged).
import { h, clear, slider, toast } from '../editor/ui.js';
import { ops } from '../core/history.js';
import { CTA_DESIGNS, ctaById, drawCta, contactOf } from './cta.js';
import { LOGO_SHAPES, badgeOf, preparedLogo, prepareLogo, logoSrcOf, plateOf } from './logo-badge.js';
import { BADGE_LOOKS, loadBadgeAssets } from './globe-badge.js';
import { REGIONS, placeName } from './country-words.js';
import { COUNTRIES as FLAG_ROWS } from './capsules/countries.js';
import { kitFor } from './kit.js';
import { addLogo, logoCompany, setBadge, badgeMentions, refreshContact, afterCutPlaced, mentionBadges, wholeLogo, addEndScreen, endScreen, setEndDuration } from './el2-cmds.js';
import { END_DESIGNS, endById, loadEndAssets } from './endscreen.js';
import { cutStatus } from './cutout-trans.js';
import { EL2_IDS } from './el2.js';
import { badgeBox } from './globe-badge.js';

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  s.textContent = `
.e2-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 5px; }
.e2-grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.e2-grid.four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.e2-opt { border: 1px solid var(--line2, rgba(255,255,255,.16)); background: var(--panel2, rgba(255,255,255,.04)); color: var(--text, #eee); border-radius: 8px; padding: 0 0 3px; font-size: 11px; font-weight: 600; cursor: pointer; overflow: hidden; text-align: center; min-width: 0; }
.e2-opt canvas { display: block; width: 100%; aspect-ratio: 16 / 10; height: auto; background: #3b4f6b; }
.e2-opt span { display: block; padding: 3px 4px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.e2-opt.plain { padding: 6px 4px; }
.e2-opt.on { border-color: var(--accent, #e8508b); box-shadow: inset 0 0 0 1px var(--accent, #e8508b); }
.e2-opt:focus-visible { outline: 2px solid var(--accent, #e8508b); }
.e2-note { font-size: 12px; color: var(--muted); margin: 0; line-height: 1.4; }
.e2-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.e2-row label { display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; }
.e2-quick { border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: 8px 10px; display: flex; flex-direction: column; gap: 7px; background: rgba(255,255,255,.03); }
.e2-quick .chip { cursor: pointer; font-size: 11.5px; }
.e2-quick select { min-width: 0; flex: 1 1 120px; }
.e2-place { width: 100%; }`;
  document.head.appendChild(s);
}

const cur = (ed, id) => ed.project.tracks.flatMap((t) => t.items).find((i) => i.id === id) || null;
/** write a copy of the item's style (and box props) as ONE step */
function writeStyle(insp, item, fn, label, extra = null) {
  const it = cur(insp.ed, item.id); if (!it) return;
  const style = JSON.parse(JSON.stringify(it.style || {})); fn(style);
  insp.ed.apply(ops.setItems([{ id: it.id, props: { style, ...(extra ? extra(it, style) : {}) } }], label));
  insp.seal();
}
/** a grid of options: [[value, label, title?]] */
function picker(list, value, onPick, cls = '', thumb = null) {
  return h('div.e2-grid' + cls, list.map(([v, l, title]) => {
    const b = h('button.e2-opt' + (thumb ? '' : '.plain') + (v === value ? '.on' : ''), { type: 'button', title: title || l, 'aria-pressed': v === value ? 'true' : 'false', dataset: { e2: v }, on: { click: () => onPick(v) } }, thumb ? thumb(v) : null, h('span', l));
    return b;
  }));
}
const SHORT = { circle: 'Circle', rsquare: 'Rounded', square: 'Square', pill: 'Rectangle', bare: 'Bare' };
const CORNERS = [['tl', '↖ Top left'], ['tr', '↗ Top right'], ['bl', '↙ Bottom left'], ['br', '↘ Bottom right']];

// ── contact card ──
function ctaSections(insp, item) {
  const ed = insp.ed, s = item.style || {}, K = kitFor(s.theme || 'travel', s.colors || null);
  const thumbs = [];
  const thumb = (id) => {
    const cv = h('canvas', { width: 192, height: 120 }); thumbs.push([cv, id]); return cv;
  };
  const paint = () => {
    for (const [cv, id] of thumbs) {
      const g = cv.getContext('2d'); g.setTransform(1, 0, 0, 1, 0, 0); g.clearRect(0, 0, cv.width, cv.height);
      const des = ctaById(id), W = 560, sc = cv.width / W, bw = W * 0.9, bh = Math.min(bw / des.ar, cv.height / sc * 0.9), w = Math.min(bw, bh * des.ar);
      g.setTransform(sc, 0, 0, sc, (cv.width - w * sc) / 2, (cv.height - (w / des.ar) * sc) / 2);
      const st = { ...s, variant: id };
      try { drawCta(g, K, { w, h: w / des.ar, tl: 1.6, d: 4, t: 1.6, inn: () => 1, out: () => 1, txt: s.text || {}, style: st }); } catch (e) { console.warn('[cta] thumb', e); }
    }
  };
  requestAnimationFrame(paint);
  const pick = (id) => writeStyle(insp, item, (st) => { st.variant = id; }, 'Contact card · ' + ctaById(id).label, (it) => {
    // the box takes the new design's shape, around the same centre
    const des = ctaById(id), area = it.w * it.h, w = Math.round(Math.min(ed.project.canvas.w * 0.96, Math.sqrt(area * des.ar))), hh = Math.round(w / des.ar);
    return { w, h: hh, x: Math.round(it.x + it.w / 2 - w / 2), y: Math.round(it.y + it.h / 2 - hh / 2) };
  });
  const con = s.contact || {}, ws = ed.context?.().workspace || {}, fresh = contactOf(ws);
  const have = ['phone', 'whatsapp', 'email', 'website', 'instagram', 'tiktok'].filter((k) => con[k]);
  const differs = JSON.stringify(fresh) !== JSON.stringify(con);
  const sec = insp.section('Contact card',
    picker(CTA_DESIGNS.map((d) => [d.id, d.label, `${d.label}: ${d.note}`]), s.variant || 'phonewa', (v) => { pick(v); insp.render(true); }, '', thumb),
    h('div.e2-row', h('span.lbl', 'Colours'), segBtns([['brand', 'Company'], ['theme', 'Theme']], s.palette || 'brand', (v) => writeStyle(insp, item, (st) => { st.palette = v; }, 'Contact card colours'))),
    h('div.e2-row', h('span.lbl', 'Words'), segBtns([['en', 'English'], ['ar', 'عربي']], s.lang || 'en', (v) => writeStyle(insp, item, (st) => { st.lang = v; }, 'Contact card language'))),
    h('p.e2-note', have.length ? `From ⚙ Configure → Contact & social: ${have.join(', ')}. Every word is editable in Text below, or double-click it on the video.` : 'Nothing in ⚙ Configure → Contact & social yet: the card shows placeholders. Fill them there, or type over them in Text below.'),
    h('button.btn.small', { type: 'button', title: 'Take the phone, WhatsApp, email, website and handles from ⚙ Configure → Contact & social again', disabled: !differs, dataset: { e2Refresh: '1' }, on: { click: () => { refreshContact(ed.context(), item.id); insp.seal(); insp.render(true); toast('Contact card updated from Configure · ⌘Z undoes', 'ok'); } } }, differs ? '↻ Use Configure’s contact details' : '✓ Matches Configure'));
  return [sec];
}
function segBtns(list, value, onPick) {
  const box = h('div.seg');
  for (const [v, l] of list) box.appendChild(h('button' + (v === value ? '.on' : ''), { type: 'button', title: l, dataset: { v }, on: { click: () => { for (const b of box.children) b.classList.toggle('on', b.dataset.v === v); onPick(v); } } }, l));
  return box;
}

// ── logo badge ──
function logoSections(insp, item) {
  const ed = insp.ed, ctx = ed.context(), s = item.style || {}, b = badgeOf(s), src = logoSrcOf(s), L = preparedLogo(src);
  if (src && !L) prepareLogo(src).then(() => { if (cur(ed, item.id)) insp.render(true); });
  const set = (patch, label, live) => { setBadge(ctx, item.id, patch, label); if (!live) { insp.seal(); insp.render(true); } };
  const plate = plateOf(s, L);
  const report = !src ? 'No logo in the brand kit: add one in ⚙ Configure → Companies (the badge shows the initials).'
    : !L ? 'Preparing the logo…'
      : `Prepared: ${L.removed === 'white' ? 'white background removed · ' : L.removed === 'solid' ? `solid background (${L.bg}) removed · ` : L.removed === 'transparent' ? 'transparent margins trimmed · ' : ''}trimmed to ${L.w}×${L.h} px · plate ${plate}${b.plate === 'auto' ? ' (chosen for contrast)' : ''}.`;
  const sec = insp.section('Logo badge',
    picker(LOGO_SHAPES.map((x) => [x.id, SHORT[x.id] || x.label, x.label]), s.variant || 'circle', (v) => set({ variant: v }, 'Logo shape')),
    h('div.e2-row', h('span.lbl', 'Corner')), picker(CORNERS, b.corner, (v) => set({ corner: v }, 'Logo corner'), '.four'),
    slider({ label: 'Size', min: 0.08, max: 0.5, step: 0.01, value: b.size, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => set({ size: v }, 'Logo size', true), onDone: () => { insp.seal(); } }),
    slider({ label: 'Opacity', min: 0.1, max: 1, step: 0.01, value: item.opacity ?? 1, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => insp.set(item, { opacity: v }, 'Logo opacity'), onDone: insp.seal }),
    h('div.e2-row', h('span.lbl', 'Intro'), segBtns([['pop', 'Pop'], ['fade', 'Fade'], ['spin', 'Spin']], b.intro, (v) => set({ intro: v }, 'Logo intro'))),
    h('div.e2-row', h('span.lbl', 'Plate'), segBtns([['auto', 'Auto'], ['light', 'Light'], ['dark', 'Dark'], ['brand', 'Brand']], /^#/.test(b.plate) ? 'auto' : b.plate, (v) => set({ plate: v }, 'Logo plate'))),
    slider({ label: 'Padding', min: 0, max: 0.3, step: 0.01, value: b.pad, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => set({ pad: v }, 'Logo padding', true), onDone: () => insp.seal() }),
    h('div.e2-row',
      h('label', { title: 'A ring round the plate' }, h('input', { type: 'checkbox', checked: !!b.ring, dataset: { e2Ring: '1' }, on: { change: (e) => set({ ring: e.target.checked }, 'Logo ring') } }), 'Ring'),
      h('label', { title: 'A soft shadow under the badge' }, h('input', { type: 'checkbox', checked: !!b.shadow, dataset: { e2Shadow: '1' }, on: { change: (e) => set({ shadow: e.target.checked }, 'Logo shadow') } }), 'Shadow')),
    h('p.e2-note', { dataset: { e2Prep: '1' } }, report));
  return [sec];
}

// ── country badge ──
const PLACE_OPTIONS = () => [
  ['Regions', Object.entries(REGIONS).map(([id, R]) => [id, R.en])],
  ['Countries', FLAG_ROWS.filter((r) => !/-/.test(r[0])).map((r) => [r[0], r[1]]).sort((a, b) => a[1].localeCompare(b[1]))],
];
function badgeSections(insp, item) {
  const ed = insp.ed, s = item.style || {}, code = s.code || 'europe';
  const placeSel = h('select.e2-place', { 'aria-label': 'Country or region', title: 'The country or region the badge shows', dataset: { e2Place: '1' }, on: { change: (e) => { const v = e.target.value; loadBadgeAssets(v).then(() => ed.preview?.draw?.()); writeStyle(insp, item, (st) => { st.code = v; st.text = {}; }, 'Badge · ' + placeName(v, 'en'), (it) => ({ name: `🌐 ${placeName(v, 'en')} badge` })); insp.render(true); } } },
    PLACE_OPTIONS().map(([g, list]) => h('optgroup', { label: g }, list.map(([v, l]) => h('option', { value: v, selected: v === code }, l)))));
  const corner = (v) => writeStyle(insp, item, () => {}, 'Badge corner', (it) => { const aspect = ed.project.canvas.aspect, b = badgeBox(v, it.w / Math.min(ed.project.canvas.w, ed.project.canvas.h), ed.project.canvas.w, ed.project.canvas.h, aspect); return b; });
  const size = (v) => { const it = cur(ed, item.id); if (!it) return; const W = ed.project.canvas.w, H = ed.project.canvas.h, w = Math.round(v * Math.min(W, H)), hh = Math.round(w * 1.32); ed.apply(Object.assign(ops.setItems([{ id: it.id, props: { w, h: hh, x: Math.round(it.x + (it.w - w) / 2), y: Math.round(it.y + (it.h - hh) / 2) } }], 'Badge size'), { key: 'e2size:' + it.id })); };
  const kids = [
    picker(BADGE_LOOKS.map((x) => [x.id, x.label]), s.variant || 'glass', (v) => { writeStyle(insp, item, (st) => { st.variant = v; }, 'Badge look'); insp.render(true); }, ''),
    h('label.row', h('span.lbl', 'Place'), placeSel),
    h('div.e2-row', h('span.lbl', 'Name'), segBtns([['en', 'EN'], ['ar', 'عربي'], ['both', 'EN + عربي'], ['ur', 'اردو'], ['hi', 'हिन्दी']], s.lang || 'both', (v) => writeStyle(insp, item, (st) => { st.lang = v; }, 'Badge language'))),
    h('div.e2-row', h('label', { title: 'A small flag inside the circle (glass and neon looks)' }, h('input', { type: 'checkbox', checked: !!s.flagIn, dataset: { e2FlagIn: '1' }, on: { change: (e) => writeStyle(insp, item, (st) => { st.flagIn = e.target.checked; }, 'Badge flag') } }), 'Flag inside the circle')),
    h('div.e2-row', h('span.lbl', 'Corner')), picker(CORNERS, null, corner, '.four'),
    slider({ label: 'Size', min: 0.14, max: 0.5, step: 0.01, value: +(item.w / Math.min(ed.project.canvas.w, ed.project.canvas.h)).toFixed(2), fmt: (v) => Math.round(v * 100) + '%', onInput: size, onDone: insp.seal }),
  ];
  if (item.auto === 'cbadge') {
    const m = s.mention || {};
    kids.push(h('p.e2-note', `Placed for “${(m.words || []).slice(0, 3).join('”, “')}” in the captions. It follows caption edits; Re-scan applies this look to every mention badge.`),
      h('button.btn.small', { type: 'button', title: 'Scan the captions again with this badge’s look, length and corner', dataset: { e2Rescan: '1' }, on: { click: () => { try { const r = badgeMentions(ed.context(), { look: s.variant, lang: s.lang, flagIn: s.flagIn }); toast(`${r.n} country badge${r.n === 1 ? '' : 's'} · ⌘Z undoes`, 'ok'); } catch (e) { toast(e.message, 'error'); } } } }, '↻ Re-scan captions'));
  }
  return [insp.section('Country badge', ...kids)];
}

// ── ending screen ──
function endSections(insp, item) {
  const ed = insp.ed, s = item.style || {}, ws = ed.context?.().workspace || {};
  loadEndAssets(s).then(() => ed.preview?.draw?.());
  const dur = (item.out - item.in) / (item.speed || 1);
  const photos = [...Object.entries(ws.adPhotos || {}).filter(([, v]) => v).map(([k, v]) => [v, 'Company photo · ' + k]), ...ed.project.media.filter((m) => m.kind === 'image').map((m) => [m.src, m.name])];
  const photoSel = h('select', { 'aria-label': 'Photo slot', title: 'The picture in the photo slot (Photo + details); none draws a soft stand-in', dataset: { e2Photo: '1' }, on: { change: (e) => { const v = e.target.value || null; writeStyle(insp, item, (st) => { st.photo = v; }, 'Ending screen photo'); loadEndAssets({ ...s, photo: v }).then(() => ed.preview?.draw?.()); } } },
    h('option', { value: '', selected: !s.photo }, 'None (a drawn stand-in)'), photos.map(([v, l]) => h('option', { value: v, selected: v === s.photo }, l)));
  const con = s.contact || {}, differs = JSON.stringify(contactOf(ws)) !== JSON.stringify(con);
  return [insp.section('Ending screen',
    picker(END_DESIGNS.map((d) => [d.id, d.label]), s.variant || 'corporate', (v) => { writeStyle(insp, item, (st) => { st.variant = v; }, 'Ending screen · ' + endById(v).label, () => ({ name: `🏁 Ending screen · ${v}` })); insp.render(true); }, '.two'),
    h('div.e2-row', h('span.lbl', 'Slides'), segBtns([['1', '1'], ['2', '2'], ['3', '3']], String(s.slides || 1), (v) => writeStyle(insp, item, (st) => { st.slides = +v; }, 'Ending screen slides'))),
    h('p.e2-note', 'One card, or 2–3 slides: logo + thank you → contacts → call to action.'),
    slider({ label: 'Duration', min: 1, max: 10, step: 0.1, value: +dur.toFixed(1), fmt: (v) => v.toFixed(1) + ' s', onInput: (v) => setEndDuration(ed.context(), item.id, v), onDone: () => { insp.seal(); insp.render(true); } }),
    h('label.row', h('span.lbl', 'Photo'), photoSel),
    h('div.e2-row', h('span.lbl', 'Colours'), segBtns([['brand', 'Company'], ['theme', 'Theme']], s.palette || 'brand', (v) => writeStyle(insp, item, (st) => { st.palette = v; }, 'Ending screen colours'))),
    h('div.e2-row', h('span.lbl', 'Words'), segBtns([['en', 'English'], ['ar', 'عربي']], s.lang || 'en', (v) => writeStyle(insp, item, (st) => { st.lang = v; }, 'Ending screen language'))),
    h('p.e2-note', 'Phone, WhatsApp, website, email and handles come from ⚙ Configure → Contact & social; every word is editable in Text below or by double-clicking it on the video.'),
    h('button.btn.small', { type: 'button', title: 'Take the contact details from ⚙ Configure → Contact & social again', disabled: !differs, dataset: { e2Refresh: '1' }, on: { click: () => { refreshContact(ed.context(), item.id); insp.seal(); insp.render(true); } } }, differs ? '↻ Use Configure’s contact details' : '✓ Matches Configure'))];
}

/** the inspector's sections for an el2 element */
export const handles = (el) => EL2_IDS.has(el);
export function inspectorSections(insp, item) {
  css();
  const el = item.style?.el;
  if (el === 'cta') return ctaSections(insp, item);
  if (el === 'logo') return logoSections(insp, item);
  if (el === 'cbadge') return badgeSections(insp, item);
  if (el === 'endscreen') return endSections(insp, item);
  return [];
}

/** a cut-out transition: whether the person was found, and a button to look now */
export function cutTransSection(insp, item) {
  css();
  const st = cutStatus(insp.ed.project, item), note = h('p.e2-note', { dataset: { e2Cut: st.fallback ? 'fallback' : st.pending ? 'pending' : 'ok' } }, st.msg);
  const btn = h('button.btn.small', { type: 'button', title: 'Find the person in both clips now (offline, once)', dataset: { e2FindPerson: '1' }, on: { click: async () => { btn.disabled = true; try { const r = await afterCutPlaced(insp.ed.context(), [item]); note.textContent = r[0]?.msg || ''; } finally { btn.disabled = false; } } } }, '🧍 Find the person');
  return insp.section('Cut-out', note, st.pending ? btn : null);
}

/** the ✨ Themes tab's one-click card */
export function quickCard(ctx) {
  css();
  const box = h('div.e2-quick', { dataset: { e2Quick: '1' } });
  const render = () => {
    const p = ctx.project, ws = ctx.workspace || {}, logo = wholeLogo(p), nB = mentionBadges(p).length;
    const ends = h('select', { 'aria-label': 'Ending screen look', title: 'The look of the ending screen', dataset: { e2EndLook: '1' } }, END_DESIGNS.map((x) => h('option', { value: x.id, selected: x.id === endScreen(p)?.style?.variant }, x.label)));
    // whose logo: any company's logo on any company's reel (the owner); the reel's colours stay this company's
    const lw = logoCompany(ctx), comps = (ctx.workspaces || []).filter((w) => w.logo || w.logoFull || w.id === ws.id);
    const from = h('select', { 'aria-label': 'Logo of company', title: 'Whose logo goes on the reel: any company’s logo can be used here', dataset: { e2LogoFrom: '1' }, on: { change: () => {
      if (!logo) return render();
      try { addLogo(ctx, logo.style.variant, { from: from.value }); const e = endScreen(ctx.project); if (e) addEndScreen(ctx, e.style.variant, { from: from.value }); toast(`Logo of ${from.selectedOptions[0].textContent} · ⌘Z undoes`, 'ok'); render(); } catch (e) { toast(e.message, 'error'); render(); }
    } } }, comps.map((w) => h('option', { value: w.id, selected: w.id === lw.id }, w.name + (w.id === ws.id ? ' (this company)' : ''))));
    const looks = h('select', { 'aria-label': 'Badge look', title: 'The look of the country badges', dataset: { e2Look: '1' } }, BADGE_LOOKS.map((x) => h('option', { value: x.id }, x.label)));
    clear(box).append(
      h('b', '⚡ One click'),
      h('div.e2-row', h('span', '🔰 Logo badge'), ...LOGO_SHAPES.map((x) => h('button.chip' + (logo?.style?.variant === x.id ? '.on' : ''), { type: 'button', title: `Your logo on a ${x.label.toLowerCase()} for the whole video (corner, size and look in the Inspector)`, dataset: { e2Logo: x.id }, on: { click: () => {
        try { const it = addLogo(ctx, x.id, { from: from.value }); window.kbbEditor?.select?.([it.id]); window.kbbEditor?.showSide?.('inspector'); toast(`Logo badge · ${x.label} · whole video · ⌘Z undoes`, 'ok'); render(); } catch (e) { toast(e.message, 'error'); }
      } } }, x.label.replace(/ \(.*\)$/, '')))),
      comps.length > 1 ? h('div.e2-row', h('span', 'Logo of'), from) : null,
      lw.logo || lw.logoFull ? null : h('p.e2-note', 'No logo in this company’s brand kit yet: add one in ⚙ Configure → Companies, or pick another company’s logo.'),
      h('div.e2-row', h('button.btn.small', { type: 'button', title: 'Scan the captions for countries and regions (English, Arabic, Urdu, Hindi) and put a globe badge in the corner for each mention', dataset: { e2Mentions: '1' }, on: { click: () => {
        try { const r = badgeMentions(ctx, { look: looks.value }); toast(r.n ? `${r.n} country badge${r.n === 1 ? '' : 's'}: ${r.codes.map((c) => placeName(c, 'en')).join(', ')} · ⌘Z undoes` : 'No country or region named in the captions', r.n ? 'ok' : 'info', 4200); render(); } catch (e) { toast(e.message, 'error'); }
      } } }, '🌐 Badge every country I mention'), looks),
      h('div.e2-row', h('button.btn.small', { type: 'button', title: 'A closing card at the end of the reel: logo, phone, WhatsApp, website, call to action (it follows the reel’s length)', dataset: { e2End: '1' }, on: { click: () => {
        try { const it = addEndScreen(ctx, ends.value, { from: from.value }); window.kbbEditor?.select?.([it.id]); window.kbbEditor?.showSide?.('inspector'); toast(`Ending screen · ${endById(ends.value).label} · the last ${(it.out - it.in).toFixed(1)} s · ⌘Z undoes`, 'ok'); render(); } catch (e) { toast(e.message, 'error'); }
      } } }, endScreen(p) ? '🏁 Replace ending screen' : '🏁 Add ending screen'), ends),
      h('p.e2-note', nB ? `${nB} mention badge${nB === 1 ? '' : 's'} on the reel: they follow caption edits.` : 'Contact cards (15 designs) and single badges are in Place elements below.'));
  };
  render();
  return { el: box, render };
}

/** after a cut-out transition is placed from the panel: find the person (with progress), say how it went */
export function cutPlaced(ctx, items) {
  afterCutPlaced(ctx, items).then((st) => { const fb = st.filter((x) => x.fallback).length; if (fb) toast(`${st[0].msg}${st.length > 1 ? ` (${fb} of ${st.length} cuts)` : ''}`, 'info', 5200); }).catch((e) => toast(e.message, 'error'));
}
