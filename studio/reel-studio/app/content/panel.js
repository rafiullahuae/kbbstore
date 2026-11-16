// KBB Studio · content · the "Create" tab (right column): every content feature as one big button with its result
// shown right under it. Each button is ONE undoable step on the reel (⌘Z takes it back).
import { h, toast, clear, select } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { projectDir } from '../core/config.js';
import { ops } from '../core/history.js';
import { LOOKS, applyLook } from './looks.js';
import { autoBroll } from './broll.js';
import { tagMedia, taggerReady } from './tagger.js';
import { formatsFor, buildFormat, slotsOf, fillSlot, autoFill } from './formats.js';
import { loadSeries, addEpisode, upsertSeries, episodeTitle, OPENERS } from './series.js';
import { makeCover, suggestTitle } from './cover.js';
import { writeCopy, pickModel } from './copy.js';
import { SHAPES, exportShapes } from './relayout.js';
import { makeShorts, exportShorts } from './shorts.js';
import { openTeleprompter } from './recorder.js';
import { cueSheet, measureSound, tapBpm } from './cues.js';
import { hoverPreview, setPreview, lazySpec, formatSpec } from '../themes/hover.js';

const fmt = (t) => `${Math.floor(t / 60)}:${String(Math.round(t % 60)).padStart(2, '0')}`;

function card(id, icon, title, sub, ...body) {
  const out = h('div.cr-out');
  const el = h('section.cr-card', { dataset: { cr: id } }, h('header.cr-head', h('span.cr-ic', icon), h('div', h('b', title), h('small.muted', sub))), ...body, out);
  el.out = out; return el;
}
/** a button that runs an async job with progress and shows the result (or the error) in the card */
function action(c, label, job, { primary = true } = {}) {
  const b = h('button.btn' + (primary ? '.primary' : '') + '.cr-go', { on: { click: async () => {
    if (b.disabled) return;
    b.disabled = true; const old = b.textContent; b.textContent = 'Working…';
    try { const r = await job(); if (r instanceof Node || Array.isArray(r)) c.out.replaceChildren(...[].concat(r)); else if (r != null) c.out.replaceChildren(h('p.cr-ok', String(r))); }
    catch (e) { if (!e?.denied) { console.warn('[content]', label, e); c.out.replaceChildren(h('p.cr-err', e?.message || String(e))); } }
    finally { b.disabled = false; b.textContent = old; }
  } } }, label);
  return b;
}

export function mountCreatePanel(root, ctx) {
  clear(root);
  root.classList.add('cr-panel');
  const ws = () => ctx.workspace || {};
  const prog = (label, f) => ctx.progress?.(label, f);
  const offs = [];

  // 1 · viral captions
  const lookSel = select(LOOKS.map((s) => [s.id, s.name]), ctx.project.meta?.content?.look || 'v-pop', () => {});
  const pops = h('input', { type: 'checkbox', checked: true });
  const c1 = card('looks', '🔥', 'Viral captions', '1–3 words, spoken word in your colour, off faces & products, keyword pops',
    h('div.cr-row', lookSel, h('label.cr-check', pops, ' pops')),
    null);
  c1.insertBefore(h('div.cr-row', action(c1, 'Apply look', async () => {
    const r = await applyLook(ctx, { look: lookSel.value, pops: pops.checked });
    return [h('p.cr-ok', `${r.cues} cues (max ${r.maxWords} words) · ${r.segments.length} placement${r.segments.length > 1 ? 's' : ''}${r.moved ? `, moved off faces/products ${r.moved}×` : ''} · ${r.pops.length} pops`),
      h('div.cr-chips', r.pops.map((p) => h('span.chip', { title: p.word, on: { click: () => ctx.seek(p.t0) } }, `${fmt(p.t0)} ${p.kind === 'emoji' ? '' : p.kind + ':'} ${p.label}`)))];
  })), c1.out);

  // 2 · b-roll
  const c2 = card('broll', '🎞️', 'Auto b-roll', 'Fills dead spots with your clips, KBB products or country shots that match the words', null);
  const libInfo = h('small.muted');
  taggerReady().then((ok) => { libInfo.textContent = ok ? 'Image tagger installed: new media is tagged on import.' : 'Tagger not installed (⚙ Models): matching uses file names and the catalogue.'; });
  c2.insertBefore(h('div.cr-row', action(c2, 'Fill dead spots', async () => {
    const r = await autoBroll(ctx);
    if (!r.placements.length) return `No dead spot with a matching shot (${r.spots.length} dead spot${r.spots.length === 1 ? '' : 's'}, ${r.assets} shots in your library). Add clips to workspaces/${ws().id}/library or the bin.`;
    return [h('p.cr-ok', `${r.placements.length} b-roll shots in ${r.spots.length} dead spots`), h('div.cr-list', r.placements.map((p) => h('div.cr-li', { on: { click: () => ctx.seek(p.t0) } }, h('b', fmt(p.t0)), ` “${p.word}” → ${p.asset.name} `, h('small.muted', p.asset.source))))];
  }), action(c2, 'Tag my media', async () => {
    let n = 0; for (const m of ctx.project.media) if (m.kind === 'image' || m.kind === 'video') { const t = await tagMedia(ws().id, m); if (t) { n++; } }
    return `${n} files tagged`;
  }, { primary: false })), c2.out);
  c2.insertBefore(libInfo, c2.out);

  // 3 · proven formats
  const fmtPrev = () => lazySpec('format:' + fSel.value, fSel.selectedOptions[0]?.textContent || '', () => formatSpec(fSel.value, ws(), ctx.project.canvas.aspect), { aspect: ctx.project.canvas.aspect, dur: 4 });
  const fSel = select(formatsFor(ws()).map((f) => [f.id, f.name]), ctx.project.meta?.content?.format?.id || formatsFor(ws())[0]?.id, () => { renderSlots(); setPreview(fSel, fmtPrev()); });
  hoverPreview(fSel, fmtPrev());   // hover or focus the list: the chosen format plays (arrow keys switch it)
  const slotBox = h('div.cr-slots');
  const c3 = card('formats', '🧩', 'Proven formats', 'A ready timeline: drop your footage into the slots', h('div.cr-row', fSel), null);
  c3.insertBefore(h('div.cr-row', action(c3, 'Build timeline', async () => { const r = buildFormat(ctx, fSel.value); renderSlots(); return `${r.slots.length} slots · ${r.length.toFixed(1)} s · text, look and CTA in place`; }),
    action(c3, 'Fill from bin', async () => `${autoFill(ctx)} slots filled from the bin`, { primary: false })), c3.out);
  c3.insertBefore(slotBox, c3.out);
  function renderSlots() {
    const slots = slotsOf(ctx.project);
    slotBox.replaceChildren(...slots.map((s) => {
      const media = ctx.project.media.filter((m) => m.kind === 'video' || m.kind === 'image');
      const sel = select([['', s.filled ? '✓ ' + (ctx.project.media.find((m) => m.id === s.filled)?.name || 'filled') : '— pick a clip —'], ...media.map((m) => [m.id, m.name])], '', (v) => { if (v) { try { fillSlot(ctx, s.key, v); renderSlots(); } catch (e) { toast(e.message, 'error'); } } });
      const row = h('div.cr-slot' + (s.filled ? '.filled' : ''), h('span.cr-n', String(s.n)), h('div', h('b', s.label), h('small.muted', `${s.dur.toFixed(1)} s · ${s.guide || ''}`)), sel);
      // drop a file from Finder straight onto the slot
      row.addEventListener('dragover', (e) => { e.preventDefault(); row.classList.add('over'); });
      row.addEventListener('dragleave', () => row.classList.remove('over'));
      row.addEventListener('drop', async (e) => {
        e.preventDefault(); row.classList.remove('over');
        const f = e.dataTransfer.files?.[0]; if (!f) return;
        try { const m = await ctx.media.importFile(f, projectDir(ctx.project.workspace, ctx.project.id)); ctx.history.apply(ops.addMedia(m)); fillSlot(ctx, s.key, m.id); renderSlots(); } catch (err) { toast(err.message, 'error'); }
      });
      return row;
    }));
  }
  renderSlots();

  // 4 · series kits
  const sSel = h('select'); const epIn = h('input.cr-num', { type: 'number', min: 1, step: 1, 'aria-label': 'Episode' });
  const loadS = async () => { const list = await loadSeries(ws().id); sSel.replaceChildren(...list.map((s) => h('option', { value: s.id }, `${s.name} (next #${s.n})`))); const s = list.find((x) => x.id === sSel.value) || list[0]; if (s) epIn.value = s.n; return list; };
  sSel.addEventListener('change', loadS.bind(null));
  const c4 = card('series', '📺', 'Series kit', 'Opener, episode number and the same look on every episode', h('div.cr-row', sSel, h('span.muted', '#'), epIn), null);
  c4.insertBefore(h('div.cr-row', action(c4, 'Add episode', async () => { const r = await addEpisode(ctx, sSel.value, { n: +epIn.value || undefined }); await loadS(); return `${r.title}: opener + badge added (next episode #${r.next})`; }),
    action(c4, '＋ New series', async () => {
      const name = prompt('Series name (e.g. "Visa Tip")'); if (!name) return null;
      const s = await upsertSeries(ws().id, { name, title: `${name} #{n}`, look: LOOKS[0].id, opener: Object.keys(OPENERS)[0] }); await loadS(); sSel.value = s.id; return `Series "${s.name}" made`;
    }, { primary: false })), c4.out);
  loadS();

  // 5 · cover
  const tIn = h('input.cr-wide', { placeholder: 'Cover title', value: '' });
  const c5 = card('cover', '🖼️', 'Cover & grid thumbnail', 'Best frame + bold title; checked at 1/6 size on the grid', h('div.cr-row', tIn), null);
  c5.insertBefore(h('div.cr-row', action(c5, 'Make covers', async () => {
    const r = await makeCover(ctx, { title: tIn.value.trim() || suggestTitle(ctx.project) });
    tIn.value = r.title;
    const m = r.metrics.grid;
    return [h('div.cr-covers', r.files.map((f) => h('figure', h('img', { src: f.url, alt: f.path }), h('figcaption', `${f.w}×${f.h}`))), h('figure', h('img.grid', { src: r.gridThumb, alt: 'grid preview' }), h('figcaption', '1/6 grid'))),
      h('p.' + (m.readable ? 'cr-ok' : 'cr-err'), `${m.readable ? 'Readable on the grid' : 'Hard to read on the grid'} · title ${m.gridPx}px at 1/6 · contrast ${m.contrast}:1${m.popContrast ? ` (keyword ${m.popContrast}:1)` : ''}`),
      h('small.muted', `Saved in ${ctx.project.workspace ? 'workspaces/' + ctx.project.workspace + '/exports/' : 'exports'}`)];
  })), c5.out);

  // 6 · post copy
  const modelInfo = h('small.muted');
  pickModel().then((m) => { modelInfo.textContent = m ? `Local writer: ${m.id} (${m.device === 'webgpu' ? 'Mac GPU' : 'CPU'})` : 'No writing model installed: the built-in writer is used (⚙ Models to add one).'; });
  const c6 = card('copy', '✍️', 'Post copy (EN + AR)', 'Caption, search keywords, 5 hashtags, comment prompts — offline', modelInfo, null);
  c6.insertBefore(h('div.cr-row', action(c6, 'Write post copy', async () => {
    const r = await writeCopy(ctx, { onProgress: prog }); prog(null);
    const block = (l) => h('div.cr-copy', { dir: l === 'ar' ? 'rtl' : 'ltr', lang: l }, h('b', l === 'ar' ? 'العربية' : 'English'), h('pre', r[l].caption), h('p', r[l].hashtags.join(' ')),
      h('small.muted', (l === 'ar' ? 'كلمات البحث: ' : 'Search: ') + r[l].keywords.join(', ')), h('ul', r[l].comments.map((c) => h('li', c))),
      h('button.btn.small', { on: { click: () => navigator.clipboard?.writeText(`${r[l].caption}\n\n${r[l].hashtags.join(' ')}`).then(() => toast('Copied', 'ok')) } }, 'Copy'));
    return [h('small.muted', `${r.model ? 'Written by ' + r.model.id : 'Built-in writer'} · ${(r.ms / 1000).toFixed(1)} s${r.file ? ' · saved ' + r.file : ''}`), block('en'), block('ar')];
  })), c6.out);

  // 7 · every shape
  const shapeChecks = SHAPES.map((a) => h('label.cr-check', h('input', { type: 'checkbox', checked: true, value: a }), ' ' + a));
  const c7 = card('shapes', '📐', 'Every shape', 'One edit → 9:16, 4:5, 1:1, 16:9 re-laid out (text, captions, stickers, face-aware reframe)', h('div.cr-row', shapeChecks), null);
  c7.insertBefore(h('div.cr-row', action(c7, 'Export all shapes', async () => {
    const shapes = shapeChecks.map((l) => l.firstChild).filter((i) => i.checked).map((i) => i.value);
    const r = await exportShapes(ctx, { shapes, onProgress: prog }); prog(null);
    return h('div.cr-list', r.map((x) => h('div.cr-li', h('b', x.aspect), ` ${x.w}×${x.h} · ${(x.bytes / 1e6).toFixed(1)} MB `, h('a', { href: x.url, download: x.path.split('/').pop() }, 'save a copy'))));
  })), c7.out);

  // 8 · long → shorts
  let lastShorts = null;
  const c8 = card('shorts', '✂️', 'Long video → shorts', 'A 10–30 min webinar becomes 8–12 shorts of 20–60 s, each with hook, captions and CTA', null);
  c8.insertBefore(h('div.cr-row', action(c8, 'Find the shorts', async () => {
    const r = await makeShorts(ctx, { onProgress: prog }); lastShorts = r.shorts; prog(null);
    return [h('p.cr-ok', `${r.shorts.length} shorts from ${r.topics} topics (${r.sentences} sentences) — each saved as its own reel`),
      h('div.cr-list', r.shorts.map((s, i) => h('div.cr-li', h('b', `${i + 1}. ${s.title}`), h('small.muted', ` ${fmt(s.t0)}–${fmt(s.t1)} · ${s.dur.toFixed(0)} s`))))];
  }), action(c8, 'Export all', async () => {
    if (!lastShorts) throw new Error('Find the shorts first');
    const r = await exportShorts(ctx, lastShorts, { onProgress: prog }); prog(null);
    return `${r.length} MP4s saved in workspaces/${ctx.project.workspace}/exports/`;
  }, { primary: false })), c8.out);

  // 9 · teleprompter
  const c9 = card('record', '🎬', 'Teleprompter & record', 'Record from the Mac camera with a scrolling script; the best take is picked for you', null);
  c9.insertBefore(h('div.cr-row', h('button.btn.primary.cr-go', { on: { click: () => openTeleprompter(ctx) } }, 'Open teleprompter')), c9.out);

  // 10 · cue sheet
  const bpm = h('input.cr-num', { type: 'number', min: 40, max: 220, step: 0.1, value: ctx.project.meta?.content?.cues?.bpm || 120, 'aria-label': 'BPM' });
  const off = h('input.cr-num', { type: 'number', min: 0, max: 5, step: 0.01, value: ctx.project.meta?.content?.cues?.offset || 0, 'aria-label': 'First beat (s)' });
  const taps = [];
  const tap = h('button.btn.small', { on: { click: () => { const t = performance.now(); if (taps.length && t - taps[taps.length - 1] > 2000) taps.length = 0; taps.push(t); const b = tapBpm(taps); if (b) bpm.value = b; } } }, 'Tap');
  const soundIn = h('input', { type: 'file', accept: 'audio/*,video/*', hidden: true, on: { change: async () => { const f = soundIn.files[0]; if (!f) return; try { const r = await measureSound(ctx, f); bpm.value = r.bpm; off.value = r.offset; c10.out.replaceChildren(h('p.cr-ok', `${f.name}: ${r.bpm} BPM, first beat at ${r.offset} s`)); } catch (e) { c10.out.replaceChildren(h('p.cr-err', e.message)); } } } });
  const c10 = card('cues', '🥁', 'Trending-sound cue sheet', 'Cuts on the beat of the sound you will add in Instagram / TikTok',
    h('div.cr-row', h('span', 'BPM'), bpm, tap, h('span', 'first beat'), off, h('button.btn.small', { on: { click: () => soundIn.click() } }, 'Measure a sound…'), soundIn), null);
  c10.insertBefore(h('div.cr-row', action(c10, 'Make cue sheet', async () => {
    const r = await cueSheet(ctx, { bpm: +bpm.value, offset: +off.value });
    return [h('p.cr-ok', `${r.bpm} BPM · ${r.cuts.filter((c) => c.beat).length}/${r.cuts.length} cuts on the beat (${r.shifted} moved) · ${r.markers} markers`),
      h('small.muted', Object.values(r.files).join(' · ')), h('audio', { controls: true, src: r.wavUrl })];
  })), c10.out);

  root.append(h('div.cr-intro', h('b', 'Create'), h('small.muted', ' — one click each, all undoable (⌘Z)')), c1, c2, c3, c4, c5, c6, c7, c8, c9, c10);
  offs.push(bus.on('project:change', () => { if (!slotBox.isConnected) return; renderSlots(); }));
  offs.push(bus.on('workspace', () => { fSel.replaceChildren(...formatsFor(ws()).map((f) => h('option', { value: f.id }, f.name))); loadS(); }));
  return { destroy() { for (const off of offs) off(); } };
}
