// KBB Studio · content · series kits, per company (workspaces/<ws>/series.json): a recurring show ("Visa Tip #12",
// "K-Beauty Find #4") with its own opener, episode number and one consistent look. "Add episode" drops the opener and
// the episode badge on the reel and puts the captions in the series' look — ONE undo step — and counts the episode.
import { engine } from '../core/engine.js';
import { createItem, projectDuration } from '../core/project.js';
import { ops, r1, uid, ensureTrack, brandText, placedBy } from './lib/items.js';
import { brandPop, contrast } from './lib/catalogue.js';
import { lookSpec } from './looks.js';
import { captionsCommand } from '../captions/commands.js';
import { buildLines } from '../captions/model.js';

export const SERIES_TAG = 'content.series';
export const OPENERS = { slam: 'Number slam', stamp: 'Stamp', slide: 'Slide-in card' };

const SEED = {
  kbb: [
    { id: 'skin-tip', name: 'Skin Tip', title: 'Skin Tip #{n}', n: 1, look: 'v-pop', opener: 'slam', hashtag: '#KBBSkinTip' },
    { id: 'kbeauty-find', name: 'K-Beauty Find', title: 'K-Beauty Find #{n}', n: 1, look: 'v-luxe', opener: 'slide', hashtag: '#KBeautyFind' },
  ],
  'planet-careers': [
    { id: 'visa-tip', name: 'Visa Tip', title: 'Visa Tip #{n}', n: 1, look: 'v-box', opener: 'stamp', hashtag: '#VisaTip' },
    { id: 'country-spotlight', name: 'Country Spotlight', title: 'Country Spotlight #{n}', n: 1, look: 'v-clean', opener: 'slide', hashtag: '#StudyAbroad' },
  ],
};
const path = (ws) => `workspaces/${ws}/series.json`;

export async function loadSeries(ws) {
  try { if (await engine.exists(path(ws))) return (await engine.readFile(path(ws), 'json')).series || []; } catch {}
  return structuredClone(SEED[ws] || [{ id: 'tips', name: 'Tips', title: 'Tip #{n}', n: 1, look: 'v-pop', opener: 'slam', hashtag: '' }]);
}
export async function saveSeries(ws, list) { if (engine.available) await engine.writeFile(path(ws), { v: 1, series: list }); return list; }
export async function upsertSeries(ws, s) {
  const list = await loadSeries(ws);
  const one = { id: s.id || (String(s.name || 'series').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || uid('s')), name: s.name || 'Series', title: s.title || `${s.name || 'Episode'} #{n}`,
    n: Math.max(1, Math.round(+s.n || 1)), look: s.look || 'v-pop', opener: OPENERS[s.opener] ? s.opener : 'slam', hashtag: s.hashtag || '' };
  const i = list.findIndex((x) => x.id === one.id);
  if (i >= 0) list[i] = { ...list[i], ...one }; else list.push(one);
  await saveSeries(ws, list);
  return one;
}
export const episodeTitle = (s, n = s.n) => s.title.replace('{n}', String(n));

/** the opener + badge items for an episode (pure) */
export function episodeItems(s, n, canvas, ws = {}) {
  const W = canvas.w, H = canvas.h, k = Math.min(W, H) / 1080, pop = brandPop(ws), ink = contrast(pop, '#111111') > contrast(pop, '#ffffff') ? '#111111' : '#ffffff';
  const dark = ws.colors?.secondary && contrast(ws.colors.secondary, '#ffffff') > 4.5 ? ws.colors.secondary : (ws.colors?.primary && contrast(ws.colors.primary, '#ffffff') > 4.5 ? ws.colors.primary : '#111111');
  const g = uid('g'), D = 1.6, out = [];
  const tag = { auto: SERIES_TAG, group: g };
  out.push(createItem('shape', { t0: 0, in: 0, out: D, x: 0, y: 0, w: W, h: H, opacity: 0.86, style: { shape: 'rect', fill: dark }, fadeOut: 0.25, ...tag, name: 'Series opener · backdrop' }));
  const name = s.name.toUpperCase(), num = '#' + n;
  if (s.opener === 'stamp') {
    out.push(createItem('text', { t0: 0.05, in: 0, out: D - 0.05, x: W * 0.1, y: H * 0.36, w: W * 0.8, h: 150 * k, text: name, style: brandText(ws, { size: 72 * k, color: '#ffffff' }), fadeOut: 0.25, ...tag, name: 'Series name' }));
    out.push(createItem('text', { t0: 0.35, in: 0, out: D - 0.35, x: W * 0.25, y: H * 0.45, w: W * 0.5, h: 240 * k, rot: -8, text: num, style: { ...brandText(ws, { size: 170 * k, font: 'Anton', weight: 400, color: pop, bg: null }), stroke: pop, strokeW: 0 }, fx: [{ type: 'content.pop', dur: 0.22 }], fadeOut: 0.25, ...tag, name: 'Episode number' }));
    out.push(createItem('shape', { t0: 0.35, in: 0, out: D - 0.35, x: W * 0.23, y: H * 0.445, w: W * 0.54, h: 250 * k, rot: -8, style: { shape: 'rect', stroke: pop, strokeW: 10 * k, radius: 26 * k }, fx: [{ type: 'content.pop', dur: 0.22 }], fadeOut: 0.25, ...tag, name: 'Stamp frame' }));
  } else if (s.opener === 'slide') {
    out.push(createItem('text', { t0: 0, in: 0, out: D, x: W * 0.08, y: H * 0.38, w: W * 0.84, h: 160 * k, text: name, style: brandText(ws, { size: 84 * k, color: ink, bg: pop, radius: 30 * k }),
      kf: { x: [[0, -W, 'out'], [0.35, W * 0.08, 'linear']] }, fadeOut: 0.25, ...tag, name: 'Series name' }));
    out.push(createItem('text', { t0: 0.25, in: 0, out: D - 0.25, x: W * 0.08, y: H * 0.38 + 175 * k, w: W * 0.84, h: 200 * k, text: num, style: brandText(ws, { size: 150 * k, font: 'Anton', weight: 400, color: '#ffffff' }),
      kf: { x: [[0, W, 'out'], [0.35, W * 0.08, 'linear']] }, fadeOut: 0.25, ...tag, name: 'Episode number' }));
  } else {
    out.push(createItem('text', { t0: 0, in: 0, out: D, x: W * 0.06, y: H * 0.3, w: W * 0.88, h: 150 * k, text: name, style: brandText(ws, { size: 78 * k, color: '#ffffff' }), fadeOut: 0.25, ...tag, name: 'Series name' }));
    out.push(createItem('text', { t0: 0.15, in: 0, out: D - 0.15, x: W * 0.1, y: H * 0.39, w: W * 0.8, h: 380 * k, text: num, style: brandText(ws, { size: 320 * k, font: 'Anton', weight: 400, color: pop, stroke: '#000000', strokeW: 8 * k }), fx: [{ type: 'content.pop', dur: 0.3 }], fadeOut: 0.25, ...tag, name: 'Episode number' }));
  }
  // the episode badge stays in the corner for the whole reel (inside the title-safe area)
  return { opener: out, badge: (end) => createItem('text', { t0: D, in: 0, out: r1(Math.max(0.5, end - D)), x: r1(W * 0.08), y: r1(H * (H > W ? 0.13 : 0.1)), w: r1(W * 0.42), h: r1(76 * k), text: `${s.name} ${num}`,
    style: { ...brandText(ws, { size: 40 * k, color: ink, bg: pop, radius: 20 * k }), align: 'center' }, auto: SERIES_TAG, name: 'Episode badge' }) };
}

/** add the series opener + badge to the open reel, put the captions in the series look, count the episode */
export async function addEpisode(ctx, seriesId, { n } = {}) {
  const p = ctx.project, ws = ctx.workspace || {}, wsId = ws.id || p.workspace;
  const list = await loadSeries(wsId), s = list.find((x) => x.id === seriesId) || list[0];
  if (!s) throw new Error('Make a series first');
  const ep = n || s.n, cmds = [];
  const old = placedBy(p, SERIES_TAG); if (old.length) cmds.push(ops.removeItems(old, 'Series'));
  const tt = ensureTrack(p, 'text', cmds);
  const { opener, badge } = episodeItems(s, ep, p.canvas, ws);
  const end = Math.max(projectDuration(p), 3);
  cmds.push(ops.addItems([...opener, badge(end)].map((item) => ({ trackId: tt.id, item })), 'Series opener'));
  if (p.captions?.words?.length) { const spec = lookSpec(s.look, ws); cmds.push(captionsCommand('style', 'Series look', (cap) => ({ ...cap, style: spec, lines: buildLines(cap.words, spec, p.canvas) }))); }
  cmds.push(ops.setProject({ name: p.name && !/^(Untitled|New) reel/i.test(p.name) ? p.name : episodeTitle(s, ep), meta: { ...p.meta, content: { ...(p.meta?.content || {}), series: { id: s.id, n: ep, title: episodeTitle(s, ep), hashtag: s.hashtag }, look: s.look } } }, 'Series'));
  ctx.history.apply(ops.batch('Series · ' + episodeTitle(s, ep), cmds));
  if (!n || n >= s.n) { s.n = ep + 1; await saveSeries(wsId, list); }
  return { series: s.id, n: ep, title: episodeTitle(s, ep), items: opener.length + 1, next: s.n };
}
