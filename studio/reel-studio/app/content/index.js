// KBB Studio · content & growth module (round 2, Lane C). The editor imports this file at start (core/modules.js
// MODULE_ENTRIES); it registers the "Create" tab in the right column, a "Create" card on the home screen, the layers
// its items draw with, and auto-tagging of media on import.
//
// Public API (also window.kbbContent, for the check scripts):
//   applyLook(ctx, {look, faces, pops})         viral caption looks (styles in captions/styles-extra.js)
//   autoBroll(ctx), libraryAssets(ctx), tagMedia(ws, media)
//   FORMATS, buildFormat(ctx, id), slotsOf(p), fillSlot(ctx, key, mediaId), autoFill(ctx)
//   loadSeries(ws), addEpisode(ctx, seriesId, {n}), upsertSeries(ws, s)
//   makeCover(ctx, {title})                     → 9:16 + 3:4 PNGs with readability metrics
//   writeCopy(ctx, {useModel})                  → EN + AR post copy (local LLM or rules)
//   layoutFor(p, aspect, faces), exportShapes(ctx, {shapes})
//   makeShorts(ctx), exportShorts(ctx, shorts)
//   openTeleprompter(ctx), Take, scoreTake(blob), saveTake, useTake
//   cueSheet(ctx, {bpm, offset})
import { registerModule } from '../core/modules.js';
import { bus } from '../core/bus.js';
import { createProject } from '../core/project.js';
import { engine } from '../core/engine.js';
import { h, toast, select } from '../editor/ui.js';
import './layers.js';
import { mountCreatePanel } from './panel.js';
import * as looks from './looks.js';
import * as broll from './broll.js';
import * as tagger from './tagger.js';
import * as formats from './formats.js';
import * as series from './series.js';
import * as cover from './cover.js';
import * as copy from './copy.js';
import * as relayout from './relayout.js';
import * as shorts from './shorts.js';
import * as recorder from './recorder.js';
import * as cues from './cues.js';
import * as keywords from './lib/keywords.js';
import * as plan from './lib/shorts-plan.js';

if (typeof document !== 'undefined' && !document.querySelector('link[data-rs-content]')) {
  const l = document.createElement('link');
  l.rel = 'stylesheet'; l.href = new URL('./content.css', import.meta.url).href; l.dataset.rsContent = '';
  document.head.append(l);
}

// auto-tag on import (in the background; tags live in workspaces/<ws>/library/tags.json, the project is untouched)
bus.on('media:added', ({ media }) => {
  const ws = window.kbbEditor?.project?.workspace;
  if (!ws || !media || (media.kind !== 'image' && media.kind !== 'video')) return;
  tagger.tagMedia(ws, media).then((t) => { if (t?.tags?.length) bus.emit('content:tagged', { media: media.id, tags: t.tags }); }).catch((e) => console.warn('[content] tag:', e.message));
});

function homeCard(el, home) {
  const w = home.workspace;
  const fs = formats.formatsFor(w);
  const fSel = select(fs.map((f) => [f.id, f.name]), fs[0]?.id, () => {});
  const newReel = async (name, files, after) => {
    const p = createProject({ name, workspace: w.id, brand: w.brandRef || w.id, aspect: '9:16' });
    if (engine.available) await engine.saveProject(p).catch(() => {});
    home.open(p, files);
    // the editor opens synchronously; give it a frame, then act on it
    await new Promise((r) => requestAnimationFrame(() => r()));
    const ed = window.kbbEditor; if (!ed) return;
    ed.showSide?.('create');
    await after?.(ed.context(), ed);
  };
  const longIn = h('input', { type: 'file', accept: 'video/*', hidden: true, on: { change: () => { const f = longIn.files[0]; if (f) newReel(f.name.replace(/\.[^.]+$/, ''), [f], async (ctx) => { toast('Imported. Press "Find the shorts" in Create → Long video → shorts', 'info', 5000); }); } } });
  // three start tiles on the home grid (the card is a subgrid row of editor.css .home-grid; content.css)
  const tile = (ic, title, sub, ...body) => h('div.card.cr-big', h('div.tile-head', h('span.tile-ic', { 'aria-hidden': 'true' }, ic), h('div', h('h2', title), h('small', sub))), ...body);
  el.append(
    tile('🎥', 'Record', 'Teleprompter + camera, best take picked',
      h('p.muted.tile-note', 'Read your script from the screen while the camera rolls; the studio keeps the cleanest take.'),
      h('div.tile-foot', h('button.btn.primary', { on: { click: () => newReel('Teleprompter take', null, (ctx) => recorder.openTeleprompter(ctx)) } }, '🎬 Start recording'))),
    tile('✂️', 'Webinar → shorts', '8–12 shorts from one long video',
      h('p.muted.tile-note', 'Drop a talk, webinar or live; the best moments become vertical shorts with captions.'),
      h('div.tile-foot', h('button.btn', { on: { click: () => longIn.click() } }, '📂 Choose a long video')), longIn),
    tile('🧩', 'Proven format', 'Start from a format that works', h('div.cr-fmt', fSel),
      h('div.tile-foot', h('button.btn.primary', { on: { click: () => { const f = formats.formatById(fSel.value); newReel(f.name, null, (ctx) => { formats.buildFormat(ctx, f.id); }); } } }, '⚡ Build it'))));
}

registerModule({
  id: 'create', label: 'Create', icon: '🚀', tab: '🚀 Create',
  panel: (el, ctx) => mountCreatePanel(el, ctx),
  home: homeCard,
});

const api = { looks, broll, tagger, formats, series, cover, copy, relayout, shorts, recorder, cues, keywords, plan,
  ...looks, ...broll, ...formats, ...series, ...cover, ...copy, ...relayout, ...shorts, ...cues,
  tagMedia: tagger.tagMedia, openTeleprompter: recorder.openTeleprompter, scoreTake: recorder.scoreTake, Take: recorder.Take, saveTake: recorder.saveTake, useTake: recorder.useTake };
if (typeof window !== 'undefined') window.kbbContent = api;
export default api;
