// KBB Studio · themes · TEXT BEHIND THE HOST — the commands. Every one is ONE undo step.
//
//   addTextBehind(ctx, opts)        one element at the playhead (or opts.t0) on a row just above the clip it stands on
//   setTb(ctx, id, patch, label)    style.tb keys (top / car merge one level deep); sliders coalesce into one step
//   setTbStyle(ctx, id, fn, label)  any change to a copy of the style (place, language, carousel list)
//   placeTb(ctx, id, where)         'head' (behind the head, from the person's own mask) | 'centre' | 'lower'
//   sizeTb(ctx, id, k)              the box's width as a share of the frame, about its centre (the words fit the box)
//   textBehindMentions(ctx, opts)   "Text behind me for every country I mention": one element per mention spanning
//                                   its sentence (≥ min s, the same place repeated merged), the word in the caption's
//                                   own script; opts.top puts "JOBS IN" (or opts.topText) above it
//   mentionSpansBySentence(...)     the pure part of it (the check calls it)
//
// And one history hook: the mention elements (item.auto 'tbehind') are derived again when the captions' words change,
// in the same undo step as the caption edit.
import { ops, registerHistoryHook } from '../core/history.js';
import { createItem, createTrack, itemEnd, clone, projectDuration, findMedia } from '../core/project.js';
import { drawItem } from '../core/render.js';
import { elementStyle, boxFor } from './elements.js';
import { themeById } from './themes.js';
import { overlayOf, colorsFor } from './apply.js';
import { findMentions, placeName } from './country-words.js';
import { reelEnd2 } from './el2-cmds.js';
import { hostClip, personAt, tbOf, loadTbAssets, tbSpan } from './textbehind.js';

export const AUTO_TB = 'tbehind';
const r3 = (x) => +(+x).toFixed(3);
const all = (p) => p.tracks.flatMap((tr) => tr.items.map((item) => ({ track: tr, item })));
const cur = (p, id) => all(p).find((x) => x.item.id === id)?.item || null;
const themeNow = (p) => overlayOf(p)?.style?.theme || 'travel';
const redraw = () => { try { globalThis.window?.kbbEditor?.preview?.draw?.(); } catch {} };

/** the row for an element over [t0, t1): free overlay row ABOVE the topmost video row, else a new one right above it */
function rowAbove(p, t0, t1, cmds, name = null) {
  const top = p.tracks.reduce((a, t, i) => (t.kind === 'video' ? i : a), -1);
  const free = (tr) => !tr.items.some((i) => i.t0 < t1 - 0.001 && itemEnd(i) > t0 + 0.001);
  const tr = p.tracks.find((t, i) => i > top && t.kind === 'overlay' && !t.locked && !t.theme && (name ? t.name === name : free(t)));
  if (tr) return tr;
  const nt = createTrack('overlay', name || undefined);
  cmds.push(ops.addTrack(nt, top + 1, 'Add track'));
  return nt;
}

/** one element (opts: t0, dur, code, lang, text {word, top}, box, tb) */
export function addTextBehind(ctx, opts = {}) {
  const p = ctx.project, W = p.canvas.w, H = p.canvas.h, th = themeById(themeNow(p)) || themeById('travel');
  let t0 = r3(Math.max(0, opts.t0 ?? ctx.playhead?.() ?? 0)), dur = r3(opts.dur || 3);
  if (opts.dur == null) ({ t0, dur } = tbSpan(p, t0, dur));   // at the playhead: never past the end of the video
  const style = elementStyle(th.id, 'tbehind', { colors: colorsFor(th, 'theme', ctx.workspace || {}), ws: ctx.workspace });
  if (opts.code) style.code = opts.code;
  if (opts.lang) style.lang = opts.lang;
  if (opts.text) style.text = { ...opts.text };
  if (opts.tb) style.tb = Object.assign(style.tb, clone(opts.tb));
  const box = opts.box || boxFor('tbehind', W, H);
  const cmds = [], tr = rowAbove(p, t0, t0 + dur, cmds);
  const item = createItem('theme.el', { t0, in: 0, out: dur, ...box, name: `🔤 Text behind · ${(style.text?.word || placeName(style.code, 'en'))}`, style });
  cmds.push(ops.addItems([{ trackId: tr.id, item }], 'Text behind the host'));
  ctx.history.apply(ops.batch('Text behind the host', cmds));
  loadTbAssets(style).then(redraw);
  return item;
}

/** style.tb keys; `top` and `car` merge one level deep. Sliders: same key while dragging = one step */
export function setTb(ctx, id, patch, label = 'Text behind', coalesce = true) {
  const it = cur(ctx.project, id); if (!it) return;
  const style = clone(it.style || {}); const tb = (style.tb ||= {});
  for (const [k, v] of Object.entries(patch)) {
    if ((k === 'top' || k === 'car') && v && typeof v === 'object' && !Array.isArray(v)) tb[k] = { ...(tb[k] || {}), ...v };
    else if (k === 'key') { style.key = v; tb.key = v; }
    else tb[k] = v;
  }
  const cmd = ops.setItems([{ id, props: { style } }], label);
  ctx.history.apply(coalesce ? Object.assign(cmd, { key: 'tb:' + id + ':' + Object.keys(patch).join(',') }) : cmd);
  if (patch.car?.flags || patch.car?.items) loadTbAssets(style).then(redraw);
}
export function setTbStyle(ctx, id, fn, label = 'Text behind', extra = null) {
  const it = cur(ctx.project, id); if (!it) return;
  const style = clone(it.style || {}); fn(style);
  ctx.history.apply(ops.setItems([{ id, props: { style, ...(extra ? extra(it, style) : {}) } }], label));
  loadTbAssets(style).then(redraw);
}

/** where the person's head is at t, in canvas px ({top, cx, w} of the topmost tenth of the person), or null */
export function headOf(project, item, t) {
  const P = personAt(project, item, t, tbOf(item.style)); if (P.state !== 'ok') return null;
  const W = project.canvas.w, H = project.canvas.h, k = 160 / Math.max(W, H), cw = Math.round(W * k), ch = Math.round(H * k);
  const cv = document.createElement('canvas'); cv.width = cw; cv.height = ch; const g = cv.getContext('2d', { willReadFrequently: true });
  g.setTransform(k, 0, 0, k, 0, 0);
  drawItem(g, { ...P.host.clip, fx: [], look: null, opacity: 1, fadeIn: 0, fadeOut: 0 }, t, project, { w: W, h: H, scale: k, preview: false, track: P.host.track, project, source: () => P.cv });
  const d = g.getImageData(0, 0, cw, ch).data;
  let top = -1, bot = -1;
  for (let y = 0; y < ch && top < 0; y++) for (let x = 0; x < cw; x++) if (d[(y * cw + x) * 4 + 3] > 127) { top = y; break; }
  if (top < 0) return null;
  for (let y = ch - 1; y >= top; y--) { let any = false; for (let x = 0; x < cw; x++) if (d[(y * cw + x) * 4 + 3] > 127) { any = true; break; } if (any) { bot = y; break; } }
  const band = Math.max(2, Math.round((bot - top) * 0.12));
  let sx = 0, n = 0, x0 = cw, x1 = 0;
  for (let y = top; y < Math.min(ch, top + band); y++) for (let x = 0; x < cw; x++) if (d[(y * cw + x) * 4 + 3] > 127) { sx += x; n++; x0 = Math.min(x0, x); x1 = Math.max(x1, x); }
  return { top: top / k, cx: (n ? sx / n : cw / 2) / k, w: (x1 - x0 + 1) / k, height: (bot - top) / k };
}
/** quick placement: behind the head (the word's lower part behind it), centre, lower */
export function placeTb(ctx, id, where = 'head') {
  const p = ctx.project, it = cur(p, id); if (!it) return null;
  const W = p.canvas.w, H = p.canvas.h, t = ctx.playhead?.() >= it.t0 && ctx.playhead?.() < itemEnd(it) ? ctx.playhead() : it.t0 + Math.min(0.5, (it.out - it.in) / 2);
  let cy;
  const head = where === 'head' ? headOf(p, it, t) : null;
  if (where === 'head') cy = head ? head.top + Math.min(it.h * 0.18, head.height * 0.12) : H * 0.2;
  else cy = where === 'lower' ? H * 0.66 : H * 0.42;
  const y = Math.round(Math.max(-it.h * 0.3, Math.min(H - it.h * 0.7, cy - it.h / 2))), x = Math.round((W - it.w) / 2);
  ctx.history.apply(ops.setItems([{ id, props: { x, y } }], where === 'head' ? 'Behind the head' : where === 'lower' ? 'Lower' : 'Centre'));
  return { x, y, head };
}
/** the box's width as a share of the frame (k), the same shape, about its centre */
export function sizeTb(ctx, id, k) {
  const p = ctx.project, it = cur(p, id); if (!it) return;
  const W = p.canvas.w, w = Math.round(W * Math.max(0.2, Math.min(1.6, k))), hh = Math.round(w * it.h / Math.max(1, it.w));
  ctx.history.apply(Object.assign(ops.setItems([{ id, props: { w, h: hh, x: Math.round(it.x + (it.w - w) / 2), y: Math.round(it.y + (it.h - hh) / 2) } }], 'Text size'), { key: 'tbsize:' + id }));
}

// ── every country I mention ──
const captionLists = (p) => [p.captions?.words || [], p.captions?.alt?.words || []].map((l) => l.filter((w) => w && w.w != null).slice().sort((a, b) => (+a.t0 || 0) - (+b.t0 || 0))).filter((l) => l.length);
const capSig = (p) => { let h = 7; for (const l of captionLists(p)) for (const x of l) { const s = String(x.w) + '@' + (+x.t0 || 0).toFixed(2); for (let i = 0; i < s.length; i++) h = (Math.imul(h, 31) + s.charCodeAt(i)) | 0; } return h; };
/** sentences of a caption list: [t0, t1] each, split at . ! ? ؟ । and at pauses over `pause` s */
function sentences(words, pause = 0.7) {
  const out = []; let s = null;
  words.forEach((w, i) => {
    const t0 = +w.t0 || 0, t1 = +(w.t1 ?? w.t0) || t0;
    if (!s) s = { t0, t1 };
    s.t1 = Math.max(s.t1, t1);
    const next = words[i + 1];
    if (/[.!?؟।]["'”’)]*$/.test(String(w.w).trim()) || !next || (+next.t0 || 0) - t1 > pause) { out.push(s); s = null; }
  });
  return out;
}
/** the script a mention was said in: its name for the element is written in it */
const langOf = (word) => (/[ऀ-ॿ]/.test(word) ? 'hi' : /[ٹڈڑںےۓہھ]/.test(word) ? 'ur' : /[؀-ۿ]/.test(word) ? 'ar' : 'en');
/**
 * Mentions -> element spans: each mention covers the sentence it was said in (at least `min` s); the same place said
 * again in an overlapping or near (≤ gap s) span merges; a different place in the same sentence starts at its own
 * word and ends the one before (which keeps at least `min` s). Clamped to [0, end].
 */
export function mentionSpansBySentence(p, { min = 1.2, gap = 1, end = Infinity, only } = {}) {
  const raw = [];
  for (const words of captionLists(p)) {
    const sents = sentences(words);
    for (const m of findMentions(words, { only })) {
      const s = sents.find((x) => m.t0 >= x.t0 - 1e-3 && m.t0 <= x.t1 + 1e-3) || { t0: m.t0, t1: m.t1 };
      raw.push({ code: m.code, lang: langOf(m.word), t0: s.t0, t1: Math.max(s.t1, s.t0 + min), at: m.t0, words: [m.word] });
    }
  }
  raw.sort((a, b) => a.at - b.at);
  const spans = [];
  for (const r of raw) {
    const last = spans[spans.length - 1];
    if (last && last.code === r.code && r.t0 <= last.t1 + gap) { last.t1 = Math.max(last.t1, r.t1); last.words.push(...r.words); continue; }
    let t0 = r.t0;
    if (last && t0 < last.t1) {
      t0 = Math.max(r.at - 0.1, last.t0 + min);
      if (t0 > r.t1 - min * 0.5) continue;
      last.t1 = t0;
    }
    spans.push({ ...r, t0, t1: Math.max(t0 + min, r.t1) });
  }
  return spans.map((s) => ({ ...s, t0: r3(Math.max(0, s.t0)), t1: r3(Math.min(end, s.t1)) })).filter((s) => s.t1 - s.t0 > 0.3);
}
export const mentionTbs = (p) => all(p).filter((x) => x.item.type === 'theme.el' && x.item.auto === AUTO_TB).map((x) => x.item);
const ROW = 'Text behind';
function mentionItems(p, o) {
  const D = reelEnd2(p) || projectDuration(p) || Infinity;
  const spans = mentionSpansBySentence(p, { min: o.min ?? 1.2, end: D });
  const th = themeById(themeNow(p)) || themeById('travel'), box = o.box || boxFor('tbehind', p.canvas.w, p.canvas.h);
  return spans.map((s) => {
    const style = elementStyle(th.id, 'tbehind', { ws: o.ws });
    const word = placeName(s.code, s.lang);
    Object.assign(style, { code: s.code, lang: s.lang, mention: { min: o.min ?? 1.2, words: s.words.slice(0, 6) } });
    if (o.tb) style.tb = Object.assign(style.tb, clone(o.tb));
    style.tb.top = { ...(style.tb.top || {}), on: !!o.top };
    if (o.topText != null) style.text = { ...(style.text || {}), top: o.topText };
    return createItem('theme.el', { t0: s.t0, in: 0, out: r3(s.t1 - s.t0), ...box, auto: AUTO_TB, name: `🔤 ${s.lang === 'en' ? word.toUpperCase() : word} behind`, style });
  });
}
/**
 * Text behind me for every country I mention. opts = { top (bool, "JOBS IN" above), topText, min }. Replaces the
 * mention elements already there (keeping their look, box and top line). ONE undo step. Returns { n, codes, items }.
 */
export function textBehindMentions(ctx, opts = {}) {
  const p = ctx.project;
  if (!captionLists(p).length) throw new Error('Add captions first: the words follow what you say (Captions → Auto captions)');
  const prev = mentionTbs(p)[0], ptb = prev ? clone(prev.style?.tb || {}) : null;
  if (ptb) delete ptb.top;
  const o = { ws: ctx.workspace, min: opts.min ?? prev?.style?.mention?.min ?? 1.2, top: opts.top ?? !!prev?.style?.tb?.top?.on, topText: opts.topText ?? prev?.style?.text?.top ?? null, tb: ptb, box: prev ? { x: prev.x, y: prev.y, w: prev.w, h: prev.h } : null };
  if (o.top && ptb && prev?.style?.tb?.top) o.tb.top = { ...prev.style.tb.top };
  const items = mentionItems(p, o), cmds = [], old = mentionTbs(p).map((i) => i.id);
  if (old.length) cmds.push(ops.removeItems(old, 'Replace text behind'));
  if (items.length) { const tr = rowAbove(p, 0, 0, cmds, ROW); cmds.push(ops.addItems(items.map((item) => ({ trackId: tr.id, item })), 'Text behind')); }
  if (cmds.length) ctx.history.apply(ops.batch(items.length ? `Text behind · ${items.length}` : 'Text behind off', cmds));
  Promise.all(items.map((i) => loadTbAssets(i.style))).then(redraw);
  return { n: items.length, codes: [...new Set(items.map((i) => i.style.code))], items };
}

// ── the follow hook: caption edits re-derive the mention elements (same undo step) ──
function swapCmd(p, removeIds, addList) {
  const before = [];
  for (const tr of p.tracks) tr.items.forEach((it, i) => { if (removeIds.includes(it.id)) before.push({ trackId: tr.id, index: i, item: clone(it) }); });
  return {
    label: 'Text behind follows the captions',
    do: (q) => { for (const tr of q.tracks) tr.items = tr.items.filter((i) => !removeIds.includes(i.id)); for (const a of addList) { const tr = q.tracks.find((t) => t.id === a.trackId); if (tr && !tr.items.some((x) => x.id === a.item.id)) tr.items.push(clone(a.item)); } },
    undo: (q) => {
      const added = new Set(addList.map((a) => a.item.id));
      for (const tr of q.tracks) tr.items = tr.items.filter((i) => !added.has(i.id));
      for (const b of before.sort((x, y) => x.index - y.index)) { const tr = q.tracks.find((t) => t.id === b.trackId); if (tr && !tr.items.some((x) => x.id === b.item.id)) tr.items.splice(Math.min(b.index, tr.items.length), 0, clone(b.item)); }
    },
  };
}
registerHistoryHook({
  id: 'tbehind.follow',
  before: (p) => (p?.tracks && p.tracks.some((t) => t.items.some((i) => i.auto === AUTO_TB)) ? { cap: capSig(p) } : null),
  after: (p, st) => {
    if (!st || !p?.tracks) return null;
    if (capSig(p) === st.cap) return null;
    const curr = mentionTbs(p); if (!curr.length) return null;
    const prev = curr[0], tr = p.tracks.find((t) => t.items.some((i) => i.id === prev.id)), ptb = clone(prev.style?.tb || {});
    const items = mentionItems(p, { min: prev.style?.mention?.min ?? 1.2, top: !!ptb.top?.on, topText: prev.style?.text?.top ?? null, tb: ptb, box: { x: prev.x, y: prev.y, w: prev.w, h: prev.h } });
    const same = items.length === curr.length && items.every((it, i) => it.style.code === curr[i].style?.code && it.style.lang === curr[i].style?.lang && Math.abs(it.t0 - curr[i].t0) < 1e-3 && Math.abs(it.out - curr[i].out) < 1e-3);
    return same ? null : swapCmd(p, curr.map((i) => i.id), items.map((item) => ({ trackId: tr.id, item })));
  },
});
export { hostClip, findMedia };
