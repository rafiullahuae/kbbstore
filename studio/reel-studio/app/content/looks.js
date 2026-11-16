// KBB Studio · content · viral caption looks, one click: the look in the company's colour, 1–3 words at a time in the
// middle third, moved off faces and products moment by moment, and the product / price / country words popping up as
// their own editable items. ONE undo step.
import { resolveStyle, STYLES } from '../captions/styles.js';
import { buildLines } from '../captions/model.js';
import { safeArea } from '../captions/render.js';
import { captionsCommand } from '../captions/commands.js';
import { safeAreas, tracksOfKind, findMedia, projectDuration, createItem } from '../core/project.js';
import { catalogueFor, brandPop, contrast } from './lib/catalogue.js';
import { keywordHits } from './lib/keywords.js';
import { obstaclesAt, inter, area } from './lib/geo.js';
import { ensureTrack, studioMedia, popText, popImage, placedBy, ops, r1, brandText, uid } from './lib/items.js';

export const LOOKS = STYLES.filter((s) => s.group === 'Viral');
export const LOOK_TAG = 'content.look', POP_TAG = 'content.pop';

/** a look as a caption style spec in the company's colour (the field each look names in `brand`) */
export function lookSpec(id, ws) {
  const base = resolveStyle(id), c = brandPop(ws), spec = { id: base.id };
  if (base.brand === 'active') { spec.active = c; if (base.anim === 'one-word') spec.color = c; }
  else if (base.brand === 'activeBg') spec.activeBg = c;
  else if (base.brand === 'glow') spec.glow = { ...base.glow, color: c };
  else if (base.brand === 'bg') { spec.bg = { ...base.bg, color: c }; spec.color = contrast(c, '#111111') >= contrast(c, '#ffffff') ? '#111111' : '#ffffff'; }
  return spec;
}

/** where a one-row caption cue sits for a given centre (fraction of canvas height) — the renderer's own maths */
export function cueRect(canvas, st, cy, text = 'WORD WORD') {
  const W = canvas.w, H = canvas.h, k = W / 1080 * (H / W < 1 ? 0.75 : 1), safe = safeArea(W, H);
  const px = st.size * k, hgt = px * st.lineHeight + st.bg.pad * k * 1.4, pad = st.bg.pad * k;
  const posY = Math.max(0.03, Math.min(0.97, (cy * H - safe.y) / safe.h));
  let top = safe.y + posY * safe.h - hgt / 2;
  top = Math.max(safe.y + pad, Math.min(top, safe.y + safe.h - hgt - pad));
  const w = Math.min(safe.w, String(text).length * px * (st.case === 'upper' ? 0.66 : 0.56) + pad * 2);
  return { rect: { x: W / 2 - w / 2, y: top, w, h: hgt }, posY: +posY.toFixed(3) };
}

const CANDIDATES = [0.5, 0.42, 0.58, 0.35, 0.65, 0.28, 0.72];

/**
 * Place cues off faces/products. lines: caption cues; obstacles(t) -> rects. Returns segments [{t0,t1,posY,cy,hit}].
 * Keeps the previous height while it is clear (no jumping), prefers the middle third.
 */
export function placeCues(project, st, lines, words, obstacles) {
  const segs = [];
  let prev = 0.5;
  for (const L of lines) {
    const text = words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => w.w).join(' ');
    const ts = [L.t0 + 0.02, (L.t0 + L.t1) / 2, Math.max(L.t0, L.t1 - 0.03)];
    const obs = ts.flatMap((t) => obstacles(t));
    const cost = (cy) => {
      const { rect } = cueRect(project.canvas, st, cy, text);
      const hit = obs.reduce((s, o) => s + inter(rect, o), 0) / Math.max(1, area(rect));
      return { cy, hit, c: hit * 10 + Math.abs(cy - 0.5) * 0.8 + (cy === prev ? -0.05 : 0) + (cy < 1 / 3 || cy > 2 / 3 ? 0.3 : 0) };
    };
    const best = CANDIDATES.map(cost).sort((a, b) => a.c - b.c)[0];
    prev = best.cy;
    const posY = cueRect(project.canvas, st, best.cy, text).posY;
    const last = segs[segs.length - 1];
    if (last && last.cy === best.cy) { last.t1 = L.t1; last.hit = Math.max(last.hit, best.hit); last.n++; }
    else segs.push({ t0: L.t0, t1: L.t1, cy: best.cy, posY, hit: best.hit, n: 1, blocked: obs.length > 0 });
  }
  return segs;
}

const POP_BOXES = (W, H, title) => {
  // a grid of places in the title-safe area at full and at 60 % size (a big face leaves only small corners free)
  const out = [];
  for (const k of [1, 0.6]) {
    const bw = W * 0.42 * k, bh = H * (H > W ? 0.16 : 0.24) * k;
    for (let j = 0; j <= 4; j++) for (let i = 0; i <= 2; i++) out.push({ x: r1(title.x + (title.w - bw) * i / 2), y: r1(title.y + (title.h - bh) * j / 4), w: r1(bw), h: r1(bh), k });
  }
  // tall places down the sides (beside a face that fills the middle): a bottle stands up in them
  const tw = W * 0.25, th = H * (H > W ? 0.3 : 0.5);
  for (let j = 0; j <= 3; j++) for (const x of [title.x, title.x + title.w - tw]) out.push({ x: r1(x), y: r1(title.y + (title.h - th) * j / 3), w: r1(tw), h: r1(th), k: 0.8, tall: true });
  return out;
};

/**
 * Apply a viral look. opts: { look='v-pop', faces: {mediaId:[{t,x,y,w,h}]} (else from auto analysis), pops=true,
 *   placement=true, maxPops }. Needs captions (runs Auto captions first when there are none).
 * Returns { look, cues, segments, moved, pops:[{kind,label,t0}], avoided }.
 */
export async function applyLook(ctx, opts = {}) {
  let p = ctx.project;
  if (!p.captions?.words?.length) {
    const { autoCaptions } = await import('../captions/auto.js');
    await autoCaptions(ctx);
    p = ctx.project;
    if (!p.captions?.words?.length) throw new Error('No speech found to caption');
  }
  const ws = ctx.workspace || {};
  const id = opts.look || p.meta?.content?.look || (/[؀-ۿ]/.test(p.captions.words.slice(0, 20).map((w) => w.w).join('')) ? 'v-arabic' : 'v-pop');
  const spec = lookSpec(id, ws), st = resolveStyle(spec);
  const words = p.captions.words, lines = buildLines(words, spec, p.canvas);
  const faces = opts.faces || (opts.placement === false ? {} : await facesFor(ctx));
  const skip = new Set(placedBy(p, POP_TAG));
  const obstacles = (t) => obstaclesAt(p, t, faces, { skip });
  const segments = opts.placement === false ? [{ t0: lines[0]?.t0 || 0, t1: lines[lines.length - 1]?.t1 || 0, cy: 0.5, posY: st.pos.y, hit: 0, n: lines.length }] : placeCues(p, st, lines, words, obstacles);

  const cmds = [];
  // 1) the look itself (cues rebuilt for 1–3 words) — the same command the caption panel uses, so it stays editable
  cmds.push(captionsCommand('style', 'Caption look', (cap) => ({ ...cap, style: spec, lines: buildLines(cap.words, spec, p.canvas) })));
  // 2) caption items: one per placement segment (the renderer reads item.style for its time range)
  const ct = tracksOfKind(p, 'caption')[0] || ensureTrack(p, 'caption', cmds);
  const oldCaps = ct.items.filter((i) => i.type === 'caption');
  const end = Math.max(projectDuration(p), ...oldCaps.map((i) => i.t0 + i.out - i.in), lines.length ? lines[lines.length - 1].t1 : 0);
  if (oldCaps.length) cmds.push(ops.removeItems(oldCaps.map((i) => i.id), 'Captions'));
  const capItems = segments.map((s, i) => {
    const t0 = i === 0 ? 0 : s.t0, t1 = i === segments.length - 1 ? end : segments[i + 1].t0;
    return createItem('caption', { t0: r1(t0), in: 0, out: r1(Math.max(0.05, t1 - t0)), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: segments.length > 1 ? `Captions ${i + 1}` : 'Captions',
      style: Math.abs(s.posY - st.pos.y) > 1e-3 ? { ...spec, pos: { y: s.posY } } : undefined, auto: LOOK_TAG });
  });
  cmds.push(ops.addItems(capItems.map((item) => ({ trackId: ct.id, item })), 'Captions'));

  // 3) keyword pops
  const pops = [];
  if (opts.pops !== false) {
    const old = placedBy(p, POP_TAG); if (old.length) cmds.push(ops.removeItems(old, 'Pops'));
    const cat = await catalogueFor(ws);
    const hits = keywordHits(words, cat).filter((h) => (h.kind === 'product' && h.strong) || h.kind === 'price' || h.kind === 'country' || h.kind === 'number' || h.kind === 'emoji');
    const rank = { price: 0, product: 1, country: 1, number: 2, emoji: 3 };
    const T = projectDuration(p) || (words[words.length - 1]?.t1 ?? 0), maxPops = opts.maxPops ?? Math.max(2, Math.round(T / 3.5));
    const chosen = [];
    for (const h of [...hits].sort((a, b) => rank[a.kind] - rank[b.kind] || a.t0 - b.t0)) {
      if (chosen.length >= maxPops) break;
      if (chosen.some((c) => Math.abs(c.t0 - h.t0) < 2.2)) continue;
      chosen.push(h);
    }
    chosen.sort((a, b) => a.t0 - b.t0);
    const ot = ensureTrack(p, 'overlay', cmds), adds = [], pending = new Map();
    const W = p.canvas.w, H = p.canvas.h, title = safeAreas(p.canvas).title, color = brandPop(ws);
    // the captions' band for every placement live during the pop (full safe width: a long cue can reach the edges)
    const capsDuring = (a, b) => segments.filter((x) => x.t0 < b + 0.3 && x.t1 > a - 0.3).map((x) => { const r = cueRect(p.canvas, st, x.cy, 'WORD').rect; const sa = safeArea(W, H); return { x: sa.x, y: r.y - r.h * 0.15, w: sa.w, h: r.h * 1.3 }; });
    for (const h of chosen) {
      const t0 = Math.max(0, h.t0 - 0.08), dur = Math.min(2.2, Math.max(1.2, h.t1 - h.t0 + 1));
      const obs = [...capsDuring(t0, t0 + dur), ...obstacles(h.t0 + 0.2), ...obstacles(t0 + dur - 0.1), ...adds.filter((a) => a.t0 < t0 + dur && a.t0 + a.dur > t0).map((a) => a.box)];
      const box = POP_BOXES(W, H, title).map((b) => ({ b, c: obs.reduce((s, o) => s + inter(b, o), 0) / area(b) + Math.abs(b.y - title.y) / H * 0.2 + (b.k < 0.7 ? 0.12 : 0) + (b.tall && h.kind !== 'product' && h.kind !== 'emoji' ? 0.25 : 0) })).sort((a, b) => a.c - b.c)[0].b;
      const group = uid('g'), items = [], k = box.k;
      const label = (txt, y, size = 54) => popText(txt, { x: box.x, y, w: box.w, h: Math.round(size * k * 1.6) }, t0 + 0.05, dur - 0.05,
        brandText(ws, { size: Math.max(30, Math.round(size * k)), bg: color, color: contrast(color, '#111111') > contrast(color, '#ffffff') ? '#111111' : '#ffffff' }), { auto: POP_TAG, group, name: 'Pop · ' + txt });
      if (h.kind === 'product' && h.item?.src) {
        const m = await studioMedia(ctx, p, h.item.src, h.item.name, cmds, pending);
        items.push(popImage(m, { ...box, h: box.h * 0.78 }, t0, dur, { auto: POP_TAG, group, content: { product: h.item.id }, name: 'Pop · ' + h.item.name }));
        items.push(label(h.item.brand + ' ' + h.item.name.split(' ').slice(0, 2).join(' '), box.y + box.h * 0.8, 36));
      } else if (h.kind === 'country' && h.item?.flagSrc) {
        const m = await studioMedia(ctx, p, h.item.flagSrc, h.item.name + ' flag', cmds, pending);
        items.push(popImage(m, { ...box, h: box.h * 0.66 }, t0, dur, { auto: POP_TAG, group, name: 'Pop · ' + h.item.name }));
        items.push(label(h.item.name, box.y + box.h * 0.72, 46));
      } else if (h.kind === 'price') {
        items.push(popText(h.label, { x: box.x, y: box.y + box.h * 0.2, w: box.w, h: box.h * 0.6 }, t0, dur, brandText(ws, { size: Math.round(88 * k), bg: color, color: contrast(color, '#111111') > contrast(color, '#ffffff') ? '#111111' : '#ffffff' }), { auto: POP_TAG, group, rot: -5, name: 'Pop · ' + h.label }));
      } else if (h.kind === 'number') {
        items.push(popText(h.label, box, t0, dur, brandText(ws, { size: Math.round(150 * k), font: 'Anton', weight: 400, color, stroke: '#000000', strokeW: 7 }), { auto: POP_TAG, group, name: 'Pop · ' + h.label }));
      } else if (h.kind === 'emoji') {
        const s = Math.min(box.w, box.h) * 0.9;
        items.push(createItem('sticker', { t0: r1(t0), in: 0, out: r1(dur), x: r1(box.x + (box.w - s) / 2), y: r1(box.y + (box.h - s) / 2), w: r1(s), h: r1(s), text: h.emoji, fx: [{ type: 'content.pop', dur: 0.28 }], fadeOut: 0.2, auto: POP_TAG, group, name: 'Pop · ' + h.emoji }));
      }
      if (!items.length) continue;
      adds.push({ t0, dur, box });
      for (const item of items) cmds.push(ops.addItems([{ trackId: ot.id, item }], 'Pop'));
      pops.push({ kind: h.kind, label: h.label, t0: r1(t0), dur: r1(dur), box, word: words.slice(h.i, h.i1 + 1).map((w) => w.w).join(' ') });
    }
  }
  cmds.push(ops.setProject({ meta: { ...p.meta, content: { ...(p.meta?.content || {}), look: id } } }, 'Look'));
  ctx.history.apply(ops.batch('Viral captions · ' + resolveStyle(id).name, cmds));
  const moved = segments.filter((s) => Math.abs(s.cy - 0.5) > 1e-3);
  return { look: id, spec, cues: lines.length, maxWords: Math.max(0, ...lines.map((L) => L.wordIdx[1] - L.wordIdx[0] + 1)), segments, moved: moved.length, avoided: segments.filter((s) => s.blocked).length, pops };
}

/** faces per video media on the timeline, from the automation module's analysis (MediaPipe, offline; cached there) */
export async function facesFor(ctx) {
  const out = {};
  const ids = new Set(); for (const tr of ctx.project.tracks) if (tr.kind === 'video') for (const it of tr.items) if (it.media && findMedia(ctx.project, it.media)?.kind === 'video') ids.add(it.media);
  if (!ids.size) return out;
  try {
    const auto = await import('../auto/index.js');
    for (const id of ids) { const a = await auto.analyse(ctx, id); if (a?.subjects?.some((s) => s.w)) out[id] = a.subjects; }
  } catch (e) { console.warn('[content] faces unavailable:', e.message); }
  return out;
}
