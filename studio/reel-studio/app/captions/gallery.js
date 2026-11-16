// The caption template gallery: every style as a live animated tile, searchable, filterable by category, with
// favourites per company (workspace.captions.favs, saved into workspaces/<ws>/workspace.json through the engine).
// Only tiles on screen animate (IntersectionObserver), at ~15 fps, and only while the gallery is visible.

import { resolveStyle, styleForWorkspace, stylesForWorkspace, CATEGORIES, catOf, keywordsOf } from './styles.js';
import { drawCaptions } from './render.js';
import { isRtlText } from './model.js';
import { wsDir } from '../core/config.js';

const SAMPLE_EN = 'Glow up with this serum'.split(' ');
const SAMPLE_AR = 'بشرة نضرة مع هذا السيروم'.split(' ');
const SAMPLE_PRO = 'Study in Germany this March'.split(' ');
const SAMPLE_PRO_AR = 'ادرس في ألمانيا هذا الشهر'.split(' ');

export const favsOf = (ws) => (Array.isArray(ws?.captions?.favs) ? ws.captions.favs : []);

/** Toggle a favourite on the workspace and save workspace.json (engine). Returns the new list. */
export async function toggleFav(ctx, id) {
  const ws = ctx.workspace; if (!ws) return [];
  const cur = favsOf(ws);
  const next = cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id];
  ws.captions = { ...(ws.captions || {}), favs: next };
  ws.modified = new Date().toISOString();
  if (ctx.engine && (ctx.engine.available ?? true) && ws.id) {
    try { await ctx.engine.writeFile(`${wsDir(ws.id)}/workspace.json`, ws); } catch (e) { ctx.toast?.('Could not save favourites: ' + e.message, 'warn'); }
  }
  ctx.bus?.emit?.('workspace:captions', { workspace: ws });
  return next;
}

const isArabicStyle = (s) => catOf(s) === 'Arabic';

export function sampleProject(spec, words, { lang } = {}) {
  const st = resolveStyle(spec);
  const ar = lang === 'ar' || (!lang && isArabicStyle(st));
  const pro = catOf(st) === 'Corporate' || st.group === 'Pro';
  const src = words || (ar ? (pro ? SAMPLE_PRO_AR : SAMPLE_AR) : pro ? SAMPLE_PRO : SAMPLE_EN);
  const mk = (arr, t0 = 0.25) => arr.map((w, i) => ({ w, t0: t0 + i * 0.32, t1: t0 + i * 0.32 + 0.3, conf: 1 }));
  const ws = mk(src);
  const lines = st.anim === 'one-word' ? ws.map((w, i) => ({ id: 's' + i, t0: w.t0, t1: w.t1 + 0.02, wordIdx: [i, i] }))
    : [{ id: 's', t0: 0, t1: 99, wordIdx: [0, ws.length - 1] }];
  const captions = { style: spec, words: ws, lines };
  if (st.bilingual) { const a = mk(SAMPLE_AR); captions.alt = { words: a, lines: [{ id: 'a', t0: 0, t1: 99, wordIdx: [0, a.length - 1] }] }; }
  return { canvas: { w: 1080, h: 1920 }, captions, dur: ws[ws.length - 1].t1 + 0.8 };
}

export function tileBackdrop(c) {
  const g = c.createLinearGradient(0, 0, 1080, 1920);
  g.addColorStop(0, '#e9b9a0'); g.addColorStop(0.5, '#8e5a6c'); g.addColorStop(1, '#1f1a2e');
  c.fillStyle = g; c.fillRect(0, 0, 1080, 1920);
}

const h = (tag, attrs = {}, ...kids) => {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (v === true) el.setAttribute(k, '');
    else if (v !== false && v != null) el.setAttribute(k, v);
  }
  for (const c of kids.flat()) if (c != null && c !== false) el.append(c.nodeType ? c : document.createTextNode(String(c)));
  return el;
};

/**
 * Mount the gallery into `el`. opts: { currentId(), onPick(spec, style), sampleWords() }.
 * Returns { render(), mark(), start(), stop(), destroy(), state }.
 */
export function mountGallery(el, ctx, opts) {
  const state = { q: '', cat: 'All' };
  const search = h('input', { class: 'rs-cap-in rs-cap-search', type: 'search', placeholder: 'Search templates (gold, arabic, karaoke…)', 'aria-label': 'Search templates' });
  const chips = h('div', { class: 'rs-cap-cats', role: 'tablist' });
  const count = h('span', { class: 'rs-cap-muted rs-cap-count' });
  const grid = h('div', { class: 'rs-cap-tiles' });
  el.append(h('div', { class: 'rs-cap-galhead' }, search, count), chips, grid);
  let tiles = [];
  const visible = new Set();
  const io = typeof IntersectionObserver === 'function' ? new IntersectionObserver((es) => {
    for (const e of es) { const x = tiles.find((t) => t.tile === e.target); if (!x) continue; if (e.isIntersecting) visible.add(x); else visible.delete(x); }
  }, { root: null, rootMargin: '80px' }) : null;

  search.addEventListener('input', () => { state.q = search.value.trim().toLowerCase(); filter(); });

  function renderChips() {
    const favs = favsOf(ctx.workspace);
    const all = ['All', '★ Favourites', ...CATEGORIES];
    chips.replaceChildren(...all.map((c) => h('button', { class: 'rs-cap-chip cat' + (state.cat === c ? ' on' : ''), 'data-cat': c, role: 'tab', 'aria-selected': String(state.cat === c),
      onclick: () => { state.cat = c; renderChips(); filter(); } }, c === '★ Favourites' ? `★ ${favs.length}` : c)));
  }

  function render() {
    const wsDefault = styleForWorkspace(ctx.workspace);
    const cur = opts.currentId();
    const words = opts.sampleWords?.() || null;
    const favs = favsOf(ctx.workspace);
    io?.disconnect(); visible.clear();
    const list = stylesForWorkspace(ctx.workspace);
    // favourites first (after the company default), the rest in gallery order
    list.sort((a, b) => (b.id === wsDefault.id) - (a.id === wsDefault.id) || favs.includes(b.id) - favs.includes(a.id));
    tiles = list.map((s) => {
      const spec = s.id === wsDefault.id ? wsDefault : { id: s.id };
      const cv = h('canvas', { width: 216, height: 384 });
      const fav = favs.includes(s.id);
      const star = h('span', { class: 'rs-cap-fav' + (fav ? ' on' : ''), role: 'button', title: fav ? 'Remove from favourites' : 'Add to favourites', 'aria-label': 'Favourite', 'data-fav': s.id,
        onclick: async (e) => { e.stopPropagation(); await toggleFav(ctx, s.id); render(); } }, fav ? '★' : '☆');
      const tile = h('button', { class: 'rs-cap-tile' + (s.id === cur ? ' on' : ''), 'data-style': s.id, 'data-cat': catOf(s), title: `${s.name} · ${catOf(s)}`,
        onclick: () => opts.onPick(spec, s) },
        cv, h('span', {}, s.name), star, s.id === wsDefault.id && ctx.workspace ? h('em', {}, 'Company default') : null);
      const wantsRtl = isArabicStyle(s);
      const fits = words && (s.bilingual ? !isRtlText(words.join(' ')) : isRtlText(words.join(' ')) === wantsRtl);
      // thumbnails are ~100 px wide: draw the text 1.7x so the look reads; the click applies the real size
      const big = { ...(typeof spec === 'string' ? { id: spec } : spec), size: Math.round(s.size * 1.7), rowChars: Math.max(8, Math.round(s.rowChars / 1.7)), outline: { ...s.outline, w: s.outline.w * 1.4 } };
      return { s, spec, cv, tile, kw: keywordsOf(s), proj: sampleProject(big, fits ? words.slice(0, 5) : null) };
    });
    grid.replaceChildren(...tiles.map((x) => x.tile));
    for (const x of tiles) io?.observe(x.tile);
    renderChips();
    filter();
    draw(1.2, true);
  }

  function filter() {
    const favs = favsOf(ctx.workspace);
    let n = 0;
    for (const x of tiles) {
      const okCat = state.cat === 'All' || (state.cat === '★ Favourites' ? favs.includes(x.s.id) : catOf(x.s) === state.cat);
      const okQ = !state.q || state.q.split(/\s+/).every((q) => x.kw.includes(q));
      x.tile.hidden = !(okCat && okQ);
      if (!x.tile.hidden) n++;
    }
    count.textContent = `${n} of ${tiles.length}`;
  }

  function draw(t, all = false) {
    for (const x of all || !io ? tiles : visible) {
      if (x.tile.hidden) continue;
      const c2 = x.cv.getContext('2d');
      c2.setTransform(216 / 1080, 0, 0, 384 / 1920, 0, 0);
      tileBackdrop(c2);
      drawCaptions(c2, null, t % x.proj.dur, x.proj);
    }
  }

  let raf = 0, last = 0, on = false;
  const loop = (now) => {
    raf = 0;
    if (!on || !el.isConnected || document.hidden) return;
    if (now - last > 66) { last = now; draw(now / 1000); }
    raf = requestAnimationFrame(loop);
  };
  return {
    state, render, filter, draw,
    get tiles() { return tiles; },
    get live() { return { visible: visible.size, on, raf: !!raf }; },
    mark(id) { for (const x of tiles) x.tile.classList.toggle('on', x.s.id === id); },
    start() { on = true; if (!raf) raf = requestAnimationFrame(loop); },
    stop() { on = false; cancelAnimationFrame(raf); raf = 0; },
    destroy() { on = false; cancelAnimationFrame(raf); io?.disconnect(); },
  };
}
