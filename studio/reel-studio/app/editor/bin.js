// KBB Studio — the left panel: Media bin (drop, thumbnails, durations) + Library tabs (Music, SFX, Text, Stickers,
// Templates).

import { engine } from '../core/engine.js';
import { ops } from '../core/history.js';
import { findTrack, createTrack } from '../core/project.js';
import { h, clear, toast, fmtDur } from './ui.js';
import { TEXT_PRESETS, STICKERS, textItem, shapeItem, stickerItem } from './items.js';
import { hoverPreview, hoverToggles, audioSpec, lazySpec, recipeSpec } from '../themes/hover.js';

const TABS = [['media', '🎞', 'Media'], ['music', '🎵', 'Music'], ['sfx', '🔊', 'SFX'], ['text', 'T', 'Text'], ['stickers', '✨', 'Stickers'], ['templates', '▦', 'Templates']];

export class Bin {
  constructor(ed, el) {
    this.ed = ed; this.el = el; this.tab = 'media';
    this.tabsEl = h('nav.bin-tabs', { role: 'tablist' });
    this.body = h('div.bin-body');
    this.fileIn = h('input', { type: 'file', multiple: true, accept: 'video/*,audio/*,image/*', hidden: true, on: { change: () => { ed.importFiles([...this.fileIn.files], { addToTimeline: false }); this.fileIn.value = ''; } } });
    el.append(this.tabsEl, this.body, this.fileIn);
    el.addEventListener('dragover', (e) => { if (e.dataTransfer?.types.includes('Files')) { e.preventDefault(); el.classList.add('drop'); } });
    el.addEventListener('dragleave', () => el.classList.remove('drop'));
    el.addEventListener('drop', (e) => { el.classList.remove('drop'); if (e.dataTransfer?.files.length) { e.preventDefault(); ed.importFiles([...e.dataTransfer.files], { addToTimeline: false }); } });
    this.audioPrev = null;
    this.renderTabs(); this.show('media');
  }

  renderTabs() {
    clear(this.tabsEl);
    for (const [id, ic, label] of TABS) {
      this.tabsEl.appendChild(h('button.bin-tab' + (id === this.tab ? '.on' : ''), { role: 'tab', 'aria-selected': id === this.tab ? 'true' : 'false', title: label, on: { click: () => this.show(id) } }, h('span.ic', ic), h('span', label)));
    }
  }
  show(id) { this.tab = id; this.renderTabs(); this.stopPreview(); this.renderBody(); }
  renderMedia() {
    const k = this.ed.project.media.map((m) => m.id).join();
    if (k === this.mk) return;
    this.mk = k;
    if (this.tab === 'media') this.renderBody();
  }

  renderBody() {
    const b = clear(this.body);
    ({ media: () => this.mediaTab(b), music: () => this.libTab(b, 'music'), sfx: () => this.libTab(b, 'sfx'), text: () => this.textTab(b), stickers: () => this.stickerTab(b), templates: () => this.templatesTab(b) })[this.tab]();
  }

  mediaTab(b) {
    const ed = this.ed;
    b.appendChild(h('button.drop-zone', { on: { click: () => this.fileIn.click() } }, h('b', '+ Add videos, photos, music'), h('small', 'or drop files anywhere')));
    const grid = h('div.media-grid');
    const list = ed.project.media.filter((m) => !/^library\//.test(m.src) || m.kind !== 'audio');
    if (!list.length) grid.appendChild(h('p.muted', 'Nothing here yet.'));
    for (const m of list) {
      const thumb = h('div.mthumb', m.kind === 'audio' ? h('span.big-ic', '🎵') : null);
      const card = h('div.mcard', { draggable: 'true', title: `${m.name}\n${m.w ? m.w + '×' + m.h + ' · ' : ''}${fmtDur(m.dur)}${m.hasAudio ? ' · sound' : ''}\nDrag to the timeline or press +` },
        thumb, h('div.mmeta', h('span.mname', m.name), h('small', m.kind === 'image' ? 'Photo' : fmtDur(m.dur))),
        h('button.add', { title: 'Add to the timeline', on: { click: (e) => { e.stopPropagation(); ed.addMediaToTimeline(m); } } }, '+'));
      card.addEventListener('dragstart', (e) => { e.dataTransfer.setData('application/x-kbb-media', m.id); e.dataTransfer.effectAllowed = 'copy'; });
      card.addEventListener('dblclick', () => ed.addMediaToTimeline(m));
      if (m.kind !== 'audio') ed.media.thumbs(m.id, { count: 1, h: 90 }).then((t) => { if (t[0]) { const c = h('canvas'); c.width = t[0].bitmap.width; c.height = t[0].bitmap.height; c.getContext('2d').drawImage(t[0].bitmap, 0, 0); thumb.replaceChildren(c); } }).catch(() => {});
      grid.appendChild(card);
    }
    b.appendChild(grid);
  }

  /** Music / SFX: the starter libraries (library/music/library.json, library/sfx/library.json) + his own tracks
   *  (library/music/user/). Listen, press + (at the playhead) or drag a row onto the timeline. */
  async libTab(b, kind) {
    b.appendChild(h('p.muted.small', kind === 'music'
      ? 'Starter music (made for the studio, free to use) and your own tracks — drop .mp3/.m4a/.wav files into library/music/user.'
      : 'Sound effects (made for the studio, free to use). Drag one onto the SFX track, or press +.'));
    const filter = h('input.lib-filter', { type: 'search', placeholder: kind === 'music' ? 'Search title, mood, bpm…' : 'Search sounds…', 'aria-label': 'Search' });
    const list = h('div.lib-list', h('p.muted', 'Loading…'));
    b.append(hoverToggles({ sound: true, mine: false }), filter, list);
    let rows = [];
    try { rows = await libraryRows(this.ed, kind); } catch (e) { console.warn('[library]', e.message); }
    if (this.tab !== kind) return;
    const paint = () => {
      clear(list);
      const q = filter.value.trim().toLowerCase();
      const shown = rows.filter((r) => !q || r.search.includes(q));
      if (!rows.length) { list.appendChild(h('p.muted', kind === 'music' ? 'No music found in library/music.' : 'No sound effects found in library/sfx.')); return; }
      if (!shown.length) list.appendChild(h('p.muted', 'Nothing matches.'));
      for (const r of shown) {
        const play = h('button.icon-btn', { title: 'Listen', on: { click: () => this.preview(r.src, play) } }, '▶');
        const row = h('div.lib-row', { draggable: 'true', dataset: { src: r.src }, title: `${r.title}\n${r.meta}\nDrag onto the timeline, or press + to add at the playhead` }, play,
          h('span.lcol', h('span.lname', r.title), h('small.lmeta', r.meta)),
          h('button.add', { title: 'Add at the playhead', on: { click: () => this.addLibrary(r.src) } }, '+'));
        row.addEventListener('dragstart', (e) => { e.dataTransfer.setData('application/x-kbb-lib', r.src); e.dataTransfer.effectAllowed = 'copy'; });
        hoverPreview(row, audioSpec(r.src, { duration: r.dur, kind }));   // hover: a few seconds (muted unless the sound toggle is on)
        list.appendChild(row);
      }
    };
    filter.addEventListener('input', paint);
    filter.addEventListener('keydown', (e) => e.stopPropagation());
    paint();
  }
  preview(path, btn) {
    if (this.audioPrev?.dataset.path === path) { this.stopPreview(); return; }
    this.stopPreview();
    const a = new Audio('/' + path.split('/').map(encodeURIComponent).join('/'));
    a.dataset.path = path; a.play().catch(() => {}); a.onended = () => this.stopPreview();
    this.audioPrev = a; this.prevBtn = btn; btn.textContent = '■';
  }
  stopPreview() { if (this.audioPrev) { this.audioPrev.pause(); this.audioPrev = null; } if (this.prevBtn) { this.prevBtn.textContent = '▶'; this.prevBtn = null; } }

  async addLibrary(path, { trackId, t0 } = {}) {
    const ed = this.ed;
    try {
      let m = ed.project.media.find((x) => x.src === path);
      if (!m) m = await ed.media.importPath(path);
      const kind = /\/sfx\//.test(path) ? 'sfx' : 'music';
      const track = (trackId && findTrack(ed.project, trackId)) || ed.project.tracks.find((t) => t.kind === kind && !t.locked);
      ed.addMediaToTimeline(m, { trackId: track?.id, t0: t0 ?? ed.t });
    } catch (e) { toast(e.message, 'error'); }
  }

  textTab(b) {
    const ed = this.ed;
    b.appendChild(h('div.preset-list', Object.entries(TEXT_PRESETS).map(([k, p]) => h('button.preset', {
      on: { click: () => ed.addItem(textItem(ed.project, k, ed.t, ed.workspace), 'text') },
      style: { fontWeight: p.style.weight, color: p.style.bg ? p.style.color : undefined, background: p.style.bg || undefined, WebkitTextStroke: p.style.strokeW ? '1px #000' : undefined },
    }, p.label))));
    b.appendChild(h('h4', 'Shapes'));
    b.appendChild(h('div.btn-grid', [['rect', '▭ Box'], ['ellipse', '◯ Circle'], ['line', '― Line']].map(([s, l]) => h('button.tool', { on: { click: () => ed.addItem(shapeItem(ed.project, s, ed.t, ed.workspace), 'overlay') } }, l))));
  }

  stickerTab(b) {
    const ed = this.ed;
    const emoji = h('div.emoji-grid.big', STICKERS.map((g) => h('button.emoji', { title: 'Add ' + g, on: { click: () => ed.addItem(stickerItem(ed.project, g, ed.t), 'overlay') } }, g)));
    // country packs first: Planet Careers (and any company in immigration / study / travel) gets flags, maps, stickers
    if (!countryWorkspace(ed.workspace)) { b.appendChild(emoji); return; }
    const box = h('div.country-pack', h('p.muted.small', 'Loading the country packs…'));
    b.append(h('h4', 'Country packs'), box, h('h4', 'Emoji'), emoji);
    loadCountries().then((list) => {
      if (this.tab !== 'stickers' || !list.length) return;
      let code = this.country || list[0].code;
      const sel = h('select.country-sel', { 'aria-label': 'Country' }, list.map((c) => h('option', { value: c.code, selected: c.code === code }, c.name)));
      const grid = h('div.sticker-grid');
      const paint = () => {
        const c = list.find((x) => x.code === code) || list[0];
        clear(grid);
        for (const [label, file] of c.items) {
          const src = 'brands/planet-careers/countries/' + file;
          grid.appendChild(h('button.sticker-img', { title: `${c.name} · ${label}`, dataset: { src }, on: { click: () => this.addImageSticker(src, `${c.name} · ${label}`) } },
            h('img', { src: '/' + src, alt: label, loading: 'lazy' }), h('small', label)));
        }
      };
      sel.addEventListener('change', () => { code = this.country = sel.value; paint(); });
      box.replaceChildren(sel, grid); paint();
    }).catch(() => box.replaceChildren(h('p.muted.small', 'Country packs are not in this copy of the studio.')));
  }

  /** a picture sticker (flag, map, country badge): a media entry pointing at the file inside the studio folder */
  async addImageSticker(src, name) {
    const ed = this.ed;
    try {
      let m = ed.project.media.find((x) => x.src === src);
      const cmds = [];
      if (!m) { m = await ed.media.importPath(src, name); cmds.push(ops.addMedia(m, 'Sticker')); }
      const it = stickerItem(ed.project, '', ed.t);
      const k = m.w && m.h ? m.h / m.w : 1;
      Object.assign(it, { media: m.id, text: '', name, h: Math.round(it.w * k) });
      let track = ed.project.tracks.find((t) => t.kind === 'overlay' && !t.locked);
      if (!track) { track = createTrack('overlay'); cmds.push(ops.addTrack(track)); }
      cmds.push(ops.addItems([{ trackId: track.id, item: it }], 'Add ' + name));
      ed.apply(ops.batch('Add ' + name, cmds));
      ed.select([it.id]);
    } catch (e) { toast(e.message, 'error'); }
  }

  /** recipes for the open company (its own, its industries', generic): Use = recipe + caption style; Make reel = Auto reel */
  async templatesTab(b) {
    const ed = this.ed;
    b.appendChild(h('p.muted.small', `Recipes for ${ed.workspace?.name || 'this company'}: structure, caption style, music and effects. “Use” sets it for this reel; “Make reel” cuts one now. Hover one to preview it.`));
    b.appendChild(hoverToggles({ mine: true }));
    const box = h('div.lib-list');
    b.appendChild(box);
    let recipes = [];
    try { recipes = await loadRecipes(ed.workspace); } catch {}
    if (this.tab !== 'templates') return;
    if (!recipes.length) { box.appendChild(h('div.soon-card', h('b', 'No recipes found'), h('small', 'The automation module is missing from this copy of the studio.'))); return; }
    let group = null;
    for (const r of recipes) {
      if (r.group !== group) { group = r.group; box.appendChild(h('h4.lib-group', group)); }
      const on = ed.project.recipe === r.id;
      box.appendChild(hoverPreview(h('div.lib-row.recipe-row' + (on ? '.on' : ''), { dataset: { recipe: r.id }, title: r.hint, tabIndex: 0 },
        h('span.lcol', h('span.lname', r.name), h('small.lmeta', r.meta)),
        h('span.rbtns',
          h('button.btn.small', { title: 'Use this recipe for this reel', on: { click: async () => { const A = await import('../auto/index.js'); A.useRecipe(ed.context(), r.id); toast(`Recipe: ${r.name}`, 'ok'); this.renderBody(); } } }, on ? '✓ In use' : 'Use'),
          h('button.btn.small.primary', { title: 'Cut a reel from your video with this recipe', on: { click: () => this.makeReel(r) } }, 'Make reel'))),
        lazySpec('recipe:' + r.id, r.name, async () => recipeSpec((await import('../auto/recipes/recipes.js')).recipeById(r.id), ed.workspace))));
    }
  }

  async makeReel(r) {
    const ed = this.ed;
    try {
      const A = await import('../auto/index.js');
      ed.setProgress('Auto reel · ' + r.name, 0);
      const out = await A.autoReel(ed.context(), { recipeId: r.id, length: r.defaultLength });
      toast(`Auto reel: ${out.plan.segments.length} moments, ${out.plan.total.toFixed(1)} s`, 'ok');
      this.renderBody();
    } catch (e) { toast(e.message, 'error'); } finally { ed.setProgress(null); }
  }
}

/** the rows the Music / SFX tabs list, from the libraries' own JSON (titles, moods, bpm, durations) */
export async function libraryRows(ed, kind) {
  const A = await import('../auto/index.js');
  const lib = await A.library(ed.context());
  if (kind === 'music') return lib.music.map((t) => ({ src: t.src || 'library/music/' + t.file, title: t.title, dur: t.duration, meta: [t.source === 'user' ? 'your track' : t.mood, t.bpm ? Math.round(t.bpm) + ' bpm' : '', fmtDur(t.duration)].filter(Boolean).join(' · '), search: [t.title, t.mood, ...(t.tags || []), t.bpm, t.energy].join(' ').toLowerCase() }));
  return lib.sfx.map((s) => ({ src: 'library/sfx/' + s.file, dur: s.duration, title: s.id.replace(/[-_]+/g, ' '), meta: [s.category, fmtDur(s.duration)].filter(Boolean).join(' · '), search: [s.id, s.category].join(' ').toLowerCase() }));
}

const countryWorkspace = (ws) => !!ws && (ws.brandRef === 'planet-careers' || ws.id === 'planet-careers' || /immigra|visa|study|consult|travel|education/i.test(`${ws.industry || ''} ${(ws.industries || []).join(' ')}`));
let COUNTRIES_P = null;
/** the country packs (brands/planet-careers/countries/countries.json): flag, map and the six stickers of each */
function loadCountries() {
  return (COUNTRIES_P ||= engine.readFile('brands/planet-careers/countries/countries.json', 'json').then((j) => (j.countries || []).map((c) => ({
    code: c.code, name: c.name,
    items: [['Flag', c.flag], ['Map', c.map], ...Object.entries(c.stickers || {}).filter(([k]) => k !== 'map').map(([k, f]) => [k.replace(/-/g, ' '), f])].filter(([, f]) => f),
  }))).catch((e) => { COUNTRIES_P = null; throw e; }));
}

const GROUPS = { kbb: 'KBB', 'planet-careers': 'Planet Careers', '*': 'Any company' };
/** recipes from app/auto/recipes (filtered by the company's industries), grouped for lists */
export async function loadRecipes(workspace) {
  const R = await import('../auto/recipes/recipes.js');
  return R.recipesOnlyFor(workspace || {}).map((r) => ({
    id: r.id, name: r.name, defaultLength: r.defaultLength,
    group: r.country ? 'Country spotlights' : GROUPS[r.workspaces[0]] || (r.industries[0] || 'Recipes'),
    meta: [r.lengths?.join(' / ') + ' s', r.music?.moods?.[0]].filter(Boolean).join(' · '),
    hint: (r.structure || []).map((s) => s.part).join(' → '),
  }));
}
export { ops };
