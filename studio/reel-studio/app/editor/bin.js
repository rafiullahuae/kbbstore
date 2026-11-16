// KBB Studio — the left panel: Media bin (drop, thumbnails, durations) + Library tabs (Music, SFX, Text, Stickers,
// Templates).

import { engine } from '../core/engine.js';
import { ops } from '../core/history.js';
import { findTrack } from '../core/project.js';
import { h, clear, toast, fmtDur } from './ui.js';
import { TEXT_PRESETS, STICKERS, textItem, shapeItem, stickerItem } from './items.js';

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

  async libTab(b, kind) {
    b.appendChild(h('p.muted.small', kind === 'music' ? 'Music from library/music — drop your own tracks into that folder.' : 'Sound effects from library/sfx.'));
    const list = h('div.lib-list', h('p.muted', 'Loading…'));
    b.appendChild(list);
    let files = [];
    try { files = await this.walk(`library/${kind}`); } catch {}
    if (this.tab !== kind) return;
    clear(list);
    if (!files.length) { list.appendChild(h('p.muted', kind === 'music' ? 'No music yet. The starter library arrives with the next update, or drop .mp3/.m4a/.wav files into library/music.' : 'No sound effects yet. The starter pack arrives with the next update.')); return; }
    for (const f of files) {
      const play = h('button.icon-btn', { title: 'Listen', on: { click: () => this.preview(f.path, play) } }, '▶');
      const row = h('div.lib-row', { draggable: 'true' }, play, h('span.lname', { title: f.path }, f.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ')),
        h('button.add', { title: 'Add at the playhead', on: { click: () => this.addLibrary(f.path) } }, '+'));
      row.addEventListener('dragstart', (e) => { e.dataTransfer.setData('application/x-kbb-lib', f.path); });
      list.appendChild(row);
    }
  }

  async walk(dir, depth = 0) {
    const out = [];
    for (const e of await engine.listDir(dir)) {
      const p = `${dir}/${e.name}`;
      if (e.dir && depth < 2) out.push(...await this.walk(p, depth + 1));
      else if (/\.(mp3|m4a|aac|wav|ogg|opus|flac)$/i.test(e.name)) out.push({ name: e.name, path: p });
    }
    return out;
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
    b.appendChild(h('div.emoji-grid.big', STICKERS.map((g) => h('button.emoji', { title: 'Add ' + g, on: { click: () => ed.addItem(stickerItem(ed.project, g, ed.t), 'overlay') } }, g))));
  }

  async templatesTab(b) {
    b.appendChild(h('p.muted.small', 'Recipes build a whole reel in your company\'s style: structure, captions, music and effects.'));
    const box = h('div.lib-list');
    b.appendChild(box);
    let recipes = [];
    try { recipes = await loadRecipes(); } catch {}
    if (this.tab !== 'templates') return;
    if (!recipes.length) { box.appendChild(h('div.soon-card', h('b', 'Recipes are coming in the next update'), h('small', 'Unboxing, before/after, study-visa steps, country spotlight and many more.'))); return; }
    for (const r of recipes) box.appendChild(h('div.lib-row', h('span.lname', r.name), h('small.muted', r.group || '')));
  }
}

/** recipes R3 ships under app/auto/recipes/ (index.json); empty until then */
export async function loadRecipes() {
  if (!engine.available || !(await engine.exists('app/auto/recipes/index.json'))) return [];
  const j = await engine.readFile('app/auto/recipes/index.json', 'json');
  const list = Array.isArray(j) ? j : j.recipes || [];
  return list.map((r) => ({ id: r.id, name: r.name || r.title || r.id, group: r.group || r.industry || r.category || '' })).filter((r) => r.id);
}

export { ops };
