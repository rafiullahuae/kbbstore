// KBB Studio · content · auto b-roll from HIS library, one click: find the dead spots (long stretches of one talking
// shot with nothing changing on screen) and cut in a shot that matches what is being SAID right there:
//   his own clips / photos  (this reel's bin + workspaces/<ws>/library/), matched by tags (tagger.js) and file names
//   KBB product shots       (brands/kbb/products, when a product or its category is named)
//   Planet Careers country  (flag / landmark / map from the country pack, when a country is named)
// ONE undo step; a second press replaces the earlier automatic b-roll (item.auto = 'content.broll'), never his own.
import { findMedia, projectDuration, createItem, safeAreas } from '../core/project.js';
import { kindOf } from '../core/media.js';
import { catalogueFor, brandPop } from './lib/catalogue.js';
import { keywordHits, stem, STOP, normWord } from './lib/keywords.js';
import { tagMedia } from './tagger.js';
import { ensureTrack, studioMedia, fullClip, placedBy, ops, r1, uid, brandText, mainVideo } from './lib/items.js';

export const BROLL_TAG = 'content.broll';

/** everything b-roll may use: [{id, src, name, kind, dur, source:'mine'|'product'|'country', words:Set(stems), item?}] */
export async function libraryAssets(ctx, { tag = true } = {}) {
  const p = ctx.project, ws = ctx.workspace?.id || p.workspace, out = [];
  const main = mainVideo(p)?.media?.id;
  const mine = p.media.filter((m) => (m.kind === 'image' || m.kind === 'video') && m.id !== main && !/^brands\//.test(m.src));
  try {
    for (const f of await ctx.engine.listDir(`workspaces/${ws}/library`)) {
      if (f.dir) continue; const kind = kindOf({ name: f.name }); if (kind !== 'image' && kind !== 'video') continue;
      const src = `workspaces/${ws}/library/${f.name}`; if (!mine.some((m) => m.src === src)) mine.push({ id: null, src, name: f.name, kind, size: f.size });
    }
  } catch { /* no library folder yet */ }
  for (const m of mine) {
    const t = tag ? await tagMedia(ws, m) : null;
    const words = new Set(t ? t.words : []);
    if (words.size) out.push({ id: m.id, src: m.src, name: m.name, kind: m.kind, dur: m.dur || 0, source: 'mine', words, tags: t?.tags || [] });
  }
  const cat = await catalogueFor(ctx.workspace);
  for (const pr of cat.products) out.push({ src: pr.src, name: `${pr.brand} ${pr.name}`, kind: 'image', source: 'product', words: new Set(pr.keywords.map(stem)), item: pr });
  for (const c of cat.countries) out.push({ src: c.landmarkSrc || c.flagSrc, name: c.name, kind: 'image', source: 'country', words: new Set([c.name, ...(c.name.split(' '))].map(stem)), item: c });
  return out;
}

/** visual change points on the timeline (cuts and overlay starts/ends) */
function changes(p, skip) {
  const ts = new Set([0, projectDuration(p)]);
  for (const tr of p.tracks) {
    if (tr.kind !== 'video' && tr.kind !== 'overlay') continue;
    for (const it of tr.items) {
      // a cut, or an overlay that takes over the picture (half the frame or more); small pops and stickers do not
      if (skip.has(it.id) || (tr.kind === 'overlay' && it.w * it.h < p.canvas.w * p.canvas.h * 0.5)) continue;
      ts.add(r1(it.t0)); ts.add(r1(it.t0 + (it.out - it.in) / (it.speed || 1)));
    }
  }
  return [...ts].sort((a, b) => a - b);
}

/** dead spots: gaps between visual changes longer than minDead seconds that have speech in them */
export function deadSpots(p, words, { minDead = 4, skip = new Set() } = {}) {
  const c = changes(p, skip), out = [];
  for (let i = 0; i < c.length - 1; i++) { const a = c[i], b = c[i + 1]; if (b - a >= minDead && words.some((w) => w.t0 >= a && w.t1 <= b)) out.push({ t0: a, t1: b }); }
  return out;
}

/** candidates for word i: [{asset, score, why}] — catalogue hits are exact; his media by tag/name stems */
function matchesFor(i, words, hitsByWord, assets) {
  const out = [], h = hitsByWord.get(i), s = stem(words[i].w);
  if (h?.kind === 'product') for (const it of (h.item ? [h.item] : h.items || [])) { const a = assets.find((x) => x.source === 'product' && x.item?.id === it.id); if (a) out.push({ asset: a, score: h.strong ? 3 : 1.6, why: h.label }); }
  if (h?.kind === 'country') { const a = assets.find((x) => x.source === 'country' && x.item?.code === h.item.code); if (a) out.push({ asset: a, score: 3, why: h.label }); }
  if (s.length > 2 && !STOP.has(normWord(words[i].w))) for (const a of assets) if (a.source === 'mine' && a.words.has(s)) out.push({ asset: a, score: 2.4 + (a.kind === 'video' ? 0.3 : 0), why: words[i].w });
  return out;
}

/**
 * Fill dead spots with matched b-roll. opts: { minDead=4, minLen=1.6, maxLen=2.8, gap=1.2 }.
 * Returns { placements:[{t0,t1,word,i,asset:{src,source,name}}], spots, assets }.
 */
export async function autoBroll(ctx, opts = {}) {
  let p = ctx.project;
  if (!p.captions?.words?.length) {
    const { autoCaptions } = await import('../captions/auto.js'); await autoCaptions(ctx); p = ctx.project;
    if (!p.captions?.words?.length) throw new Error('B-roll matches your words: captions are needed first');
  }
  const { minDead = 4, minLen = 1.6, maxLen = 2.8, gap = 1.2 } = opts;
  const words = p.captions.words, ws = ctx.workspace || {};
  const assets = opts.assets || await libraryAssets(ctx);
  const cat = await catalogueFor(ws);
  const hits = keywordHits(words, { ...cat, minGap: 0 }), hitsByWord = new Map();
  for (const h of hits) if (h.kind === 'product' || h.kind === 'country') hitsByWord.set(h.i, h);
  const old = new Set(placedBy(p, BROLL_TAG));
  const spots = deadSpots(p, words, { minDead, skip: old });
  const placements = [], used = new Map();
  for (const sp of spots) {
    let free = [[sp.t0 + 0.6, sp.t1 - 0.6]];
    const cands = [];
    for (let i = 0; i < words.length; i++) {
      if (words[i].t0 < sp.t0 + 0.6 || words[i].t1 > sp.t1 - 0.4) continue;
      for (const m of matchesFor(i, words, hitsByWord, assets)) cands.push({ i, ...m, score: m.score - (used.get(m.asset.src) || 0) * 1.2 });
    }
    cands.sort((a, b) => b.score - a.score || words[a.i].t0 - words[b.i].t0);
    for (const c of cands) {
      const w = words[c.i];
      const t0 = Math.max(w.t0 - 0.15, sp.t0 + 0.6), dur = Math.min(maxLen, Math.max(minLen, (c.asset.kind === 'video' ? Math.min(c.asset.dur || maxLen, maxLen) : maxLen)));
      const t1 = t0 + dur;
      const slot = free.find(([a, b]) => t0 >= a && t1 <= b + 0.3);
      if (!slot) continue;
      const end = Math.min(t1, slot[1] + 0.3);
      if (end - t0 < minLen) continue;
      if (placements.some((x) => x.asset.src === c.asset.src && Math.abs(x.t0 - t0) < 6)) continue;
      placements.push({ t0: r1(t0), t1: r1(end), word: w.w, i: c.i, why: c.why, score: c.score, asset: c.asset });
      used.set(c.asset.src, (used.get(c.asset.src) || 0) + 1);
      free = free.flatMap(([a, b]) => (t0 >= a && end <= b + 0.3) ? [[a, t0 - gap], [end + gap, b]].filter(([x, y]) => y - x >= minLen) : [[a, b]]);
      if (!free.length) break;
    }
  }
  placements.sort((a, b) => a.t0 - b.t0);
  // build the items
  const cmds = [];
  if (old.size) cmds.push(ops.removeItems([...old], 'B-roll'));
  const ot = ensureTrack(p, 'overlay', cmds), pending = new Map(), adds = [];
  const W = p.canvas.w, H = p.canvas.h, pop = brandPop(ws), soft = ws.colors?.soft || '#fff0f4';
  for (const pl of placements) {
    const a = pl.asset, dur = pl.t1 - pl.t0, group = uid('g');
    const m = a.id ? findMedia(p, a.id) : await studioMedia(ctx, p, a.src, a.name, cmds, pending);
    if (a.source === 'mine') {
      const srcIn = m.kind === 'video' && m.dur > dur ? Math.max(0, Math.min(m.dur - dur, m.dur * 0.25)) : 0;
      const it = fullClip(p, m, pl.t0, dur, srcIn, { auto: BROLL_TAG, name: 'B-roll · ' + (m.name || a.name), volume: 0, muted: true });
      if (m.kind === 'image') { it.in = 0; it.out = r1(dur); }
      if (m.kind === 'video' && m.dur && m.dur < dur) it.speed = r1(m.dur / dur);
      adds.push(it);
    } else {
      // a catalogue shot: the cut-out / landmark big on a brand (or flag-coloured) card, its name underneath
      const bg = a.source === 'country' ? (a.item.colors?.[0] || '#0b2545') : soft;
      const ink = a.source === 'country' ? '#ffffff' : (ws.colors?.ink || '#2a2228');
      adds.push(createItem('shape', { t0: pl.t0, in: 0, out: r1(dur), x: 0, y: 0, w: W, h: H, style: { shape: 'rect', fill: bg }, fadeIn: 0.1, fadeOut: 0.1, auto: BROLL_TAG, group, name: 'B-roll card' }));
      const TS = safeAreas(p.canvas).title, nameH = Math.min(TS.h * 0.14, H * 0.09);   // all inside the title-safe area
      const k = m.w && m.h ? m.w / m.h : 1, bw = TS.w * 0.8, bh = TS.h - nameH - TS.h * 0.1;
      let iw = bw, ih = bw / k; if (ih > bh) { ih = bh; iw = ih * k; }
      adds.push(createItem('clip', { media: m.id, t0: pl.t0, in: 0, out: r1(dur), x: r1(TS.x + (TS.w - iw) / 2), y: r1(TS.y + TS.h * 0.03 + (bh - ih) / 2), w: r1(iw), h: r1(ih), zoom: null, fx: [{ type: 'content.pop', dur: 0.3 }], fadeOut: 0.1, auto: BROLL_TAG, group, name: 'B-roll · ' + a.name, content: a.source === 'product' ? { product: a.item.id } : { country: a.item.code } }));
      adds.push(createItem('text', { t0: r1(pl.t0 + 0.1), in: 0, out: r1(dur - 0.1), x: r1(TS.x), y: r1(TS.y + TS.h - nameH), w: r1(TS.w), h: r1(nameH), text: a.source === 'product' ? a.item.brand + ' · ' + a.item.name : a.item.name,
        style: brandText(ws, { size: Math.round(Math.min(W, H) * 0.055), color: a.source === 'country' ? ink : '#ffffff', bg: a.source === 'country' ? null : pop, upper: a.source === 'country' }), fadeOut: 0.1, auto: BROLL_TAG, group, name: 'B-roll name' }));
    }
  }
  if (adds.length) cmds.push(ops.addItems(adds.map((item) => ({ trackId: ot.id, item })), 'B-roll'));
  if (!cmds.length) return { placements: [], spots, assets: assets.length };
  ctx.history.apply(ops.batch(`Auto b-roll · ${placements.length} shots`, cmds));
  return { placements: placements.map((x) => ({ ...x, asset: { src: x.asset.src, source: x.asset.source, name: x.asset.name, tags: x.asset.tags } })), spots, assets: assets.length };
}
