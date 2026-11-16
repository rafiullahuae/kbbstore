// KBB Studio · cutaways — "Auto cutaways": editable proposals from what is SAID (the captions' words), using the
// b-roll matcher's catalogue and keyword spotting (app/content): product names -> the product shot on a brand slide,
// countries -> the landmark / flag, prices -> a price slide, occupations -> an occupation slide, and his own clips and
// photos whose tags or names match a spoken word. Nothing is placed until he accepts (one undo step).
import { keywordHits, stem, STOP, normWord } from '../content/lib/keywords.js';
import { catalogueFor, readJSON } from '../content/lib/catalogue.js';
import { libraryAssets } from '../content/broll.js';
import { studioMedia } from '../content/lib/items.js';
import { ops } from '../core/history.js';
import { makeCutaway, cutaways, cutawayTrack, snapRange } from './model.js';
import { defaultSlide, loadSlideImages } from './slide.js';
import { itemEnd } from '../core/project.js';

const r3 = (x) => Math.round(x * 1000) / 1000;

async function occupations() { try { return (await readJSON('library/occupations/occupations.json')).occupations || []; } catch { return []; } }

/**
 * Proposals: [{id, t0, t1, word, kind:'product'|'country'|'price'|'occupation'|'mine', label, what:{src?, slide?, kind}, fit}]
 * opts: { minLen=1.6, maxLen=3, gap=1, assets (test hook), tag (tag his media, default true) }
 */
export async function suggestCutaways(ctx, opts = {}) {
  const p = ctx.project, words = p.captions?.words || [];
  if (!words.length) throw new Error('Cutaways follow your words: make captions first');
  const { minLen = 1.6, maxLen = 3, gap = 1 } = opts, ws = ctx.workspace || {};
  const cat = await catalogueFor(ws);
  const hits = keywordHits(words, { ...cat, minGap: 0 });
  const occ = await occupations();
  const assets = opts.assets || await libraryAssets(ctx, { tag: opts.tag !== false }).catch(() => []);
  const mine = assets.filter((a) => a.source === 'mine');
  const out = [];
  const add = (i, i1, kind, label, what, score, fit = 'fill') => out.push({ i, t0: words[i].t0, t1: words[i1 ?? i].t1, word: words.slice(i, (i1 ?? i) + 1).map((w) => w.w).join(' '), kind, label, what, score, fit });
  for (const hh of hits) {
    if (hh.kind === 'product' && hh.item) add(hh.i, hh.i1, 'product', hh.label, { slide: defaultSlide(ws, { theme: 'minimal', title: `${hh.item.brand} ${hh.item.name}`, image: hh.item.src, anim: 'pop' }) }, hh.strong ? 3 : 1.5);
    else if (hh.kind === 'country') add(hh.i, hh.i1, 'country', hh.label, { src: hh.item.landmarkSrc || hh.item.flagSrc, kind: 'image', name: hh.item.name }, 3);
    else if (hh.kind === 'price') add(hh.i, hh.i1, 'price', hh.label, { slide: defaultSlide(ws, { theme: 'offer', title: 'Special price', price: hh.label, anim: 'pop' }) }, 2.8);
  }
  // occupations: the job's name (or plural) said aloud
  const occIdx = new Map();
  // the job's own names only (its topic keywords — "water", "pipes" — would fire on everyday words)
  for (const o of occ) for (const k of [o.name?.en, o.plural?.en, o.title?.en, o.name?.ar, o.plural?.ar].filter(Boolean)) {
    const ks = String(k).toLowerCase().split(/\s+/).map(normWord).filter(Boolean);
    if (ks.length === 1 && !STOP.has(ks[0]) && ks[0].length > 2) occIdx.set(stem(ks[0]), o);
  }
  words.forEach((w, i) => {
    const o = occIdx.get(stem(normWord(w.w))); if (!o) return;
    add(i, i, 'occupation', o.name.en, { slide: defaultSlide(ws, { theme: 'brand', title: o.name.en, icon: '🧰', bullets: (o.tools || []).slice(0, 3).map((t) => t.en), anim: 'rise' }) }, 2.6);
  });
  // his own clips and photos, by tag / name stems
  words.forEach((w, i) => {
    const s = stem(w.w); if (s.length < 3 || STOP.has(normWord(w.w))) return;
    for (const a of mine) if (a.words?.has(s)) { add(i, i, 'mine', a.name, { src: a.src, id: a.id, kind: a.kind, name: a.name }, 2.4 + (a.kind === 'video' ? 0.3 : 0)); break; }
  });
  // one proposal per stretch: best score first, never overlapping each other or an existing cutaway, ≥ gap apart
  out.sort((a, b) => b.score - a.score || a.t0 - b.t0);
  const taken = cutaways(p).map(({ item }) => [item.t0 - gap, itemEnd(item) + gap]), end = Math.max(...words.map((w) => w.t1));
  const keep = [];
  for (const s of out) {
    const t0 = Math.max(0, s.t0 - 0.1), t1 = Math.min(end, Math.max(s.t1 + 0.4, t0 + minLen), t0 + maxLen);
    if (t1 - t0 < minLen * 0.75) continue;
    if (taken.some(([a, b]) => t0 < b && t1 > a)) continue;
    const [a, b] = snapRange(p, t0, t1);
    keep.push({ ...s, id: 'sg' + keep.length, t0: a, t1: b });
    taken.push([a - gap, b + gap]);
  }
  return keep.sort((a, b) => a.t0 - b.t0);
}

/** place the accepted proposals as cutaways — ONE undo step */
export async function acceptSuggestions(ctx, list) {
  const p = ctx.project, cmds = [], pending = new Map(), items = [];
  const track = cutawayTrack(p); if (!track) throw new Error('No overlay track');
  for (const s of list) {
    let what;
    if (s.what.slide) {
      const slide = { ...s.what.slide };
      await loadSlideImages(slide);
      what = { slide, name: 'Slide · ' + s.label };
    } else {
      const m = s.what.id ? p.media.find((x) => x.id === s.what.id) : await studioMedia(ctx, p, s.what.src, s.what.name, cmds, pending);
      what = { media: m, name: 'Cutaway · ' + s.label };
    }
    const it = makeCutaway(p, s.t0, s.t1, what, { fit: s.fit || 'fill' });
    it.auto = 'cutaways'; items.push(it);
  }
  if (!items.length) return 0;
  cmds.push(ops.addItems(items.map((item) => ({ trackId: track.id, item })), 'Cutaways'));
  ctx.history.apply(ops.batch(`Auto cutaways · ${items.length}`, cmds));
  return items.length;
}

export { r3 };
