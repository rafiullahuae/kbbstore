// KBB Studio · themes · ELEMENTS 2 commands — every one is ONE undo step.
//
//   addLogo(ctx, shape, opts)        the company logo as a badge for the whole video (replaces the one already there)
//   setBadge(ctx, id, patch)         a logo badge's corner / size / ring / shadow / intro / plate (corner and size move
//                                    the box, inside the safe area)
//   badgeMentions(ctx, opts)         "Badge every country I mention": a country badge for each place the captions name,
//                                    from the word's time for N s, nearby mentions of one place merged
//   refreshContact(ctx, id)          a contact card takes ⚙ Configure → Contact & social again
//   afterCutPlaced(ctx, items)       a cut-out transition was placed: find the person (with progress) and say how it went
//
// And one history hook (same undo step as the edit that caused it):
//   · the whole-video items placed here (item.auto 'el2') end where the reel ends, through every trim;
//   · the mention badges (item.auto 'cbadge') are re-derived when the captions' words change.
import { ops, registerHistoryHook } from '../core/history.js';
import { createItem, createTrack, itemEnd, clone, projectDuration } from '../core/project.js';
import { elementStyle, boxFor } from './elements.js';
import { themeById } from './themes.js';
import { overlayOf, colorsFor } from './apply.js';
import { logoBox, badgeOf } from './logo-badge.js';
import { badgeBox } from './globe-badge.js';
import { findMentions, mentionSpans, placeName } from './country-words.js';
import { contactOf } from './cta.js';

export const AUTO_WHOLE = 'el2', AUTO_BADGE = 'cbadge';
const r3 = (x) => +(+x).toFixed(3);
const aspectOf = (p) => p.canvas.aspect || (p.canvas.w > p.canvas.h * 1.05 ? '16:9' : '9:16');
const OWN = new Set(['theme', AUTO_WHOLE, AUTO_BADGE]);
/** where the reel ends: the last thing on it that neither a theme nor these badges placed */
export function reelEnd2(p) {
  let end = 0;
  for (const tr of p.tracks) for (const it of tr.items) if (!OWN.has(it.auto)) end = Math.max(end, itemEnd(it));
  return r3(end || projectDuration(p) || 0);
}
const themeNow = (p) => overlayOf(p)?.style?.theme || p.tracks.flatMap((t) => t.items).find((i) => i.style?.theme && /^theme\./.test(i.type))?.style?.theme || 'travel';
const all = (p) => p.tracks.flatMap((tr) => tr.items.map((item) => ({ track: tr, item })));
/** a top overlay row for badges: an existing one named `name`, else a new one under the captions */
function laneFor(p, name, cmds) {
  let tr = p.tracks.find((t) => t.kind === 'overlay' && t.name === name && !t.locked);
  if (tr) return tr;
  tr = createTrack('overlay', name);
  const capIdx = p.tracks.findIndex((t) => t.kind === 'caption');
  cmds.push(ops.addTrack(tr, capIdx >= 0 ? capIdx : p.tracks.length, 'Add track'));
  return tr;
}

// ── logo badge ──
export const wholeLogo = (p) => all(p).find((x) => x.item.type === 'theme.el' && x.item.style?.el === 'logo' && x.item.auto === AUTO_WHOLE)?.item || null;
export function addLogo(ctx, shape = 'circle', opts = {}) {
  const p = ctx.project, ws = ctx.workspace || {}, W = p.canvas.w, H = p.canvas.h, tid = themeNow(p), th = themeById(tid) || themeById('travel');
  if (!ws.logo && !ws.logoFull) throw new Error('This company has no logo yet: add one in ⚙ Configure → Companies');
  const old = wholeLogo(p), cmds = [];
  const style = elementStyle(th.id, 'logo', { colors: colorsFor(th, 'theme', ws), variant: shape, brand: ws.name || null, logo: ws.logo || null, ws });
  if (old?.style?.badge) style.badge = { ...old.style.badge, ...(opts.badge || {}) }; else if (opts.badge) Object.assign(style.badge, opts.badge);
  style.whole = 'span';
  const b = badgeOf(style), D = reelEnd2(p) || 10;
  const item = createItem('theme.el', { t0: 0, in: 0, out: r3(D), ...logoBox(shape, b.corner, b.size, W, H, aspectOf(p)), auto: AUTO_WHOLE, opacity: old?.opacity ?? 1, name: `🔰 Logo badge · ${shape}`, style });
  if (old) cmds.push(ops.removeItems([old.id], 'Replace logo badge'));
  const tr = laneFor(p, 'Logo', cmds);
  cmds.push(ops.addItems([{ trackId: tr.id, item }], 'Logo badge'));
  ctx.history.apply(ops.batch(`Logo badge · ${shape}`, cmds));
  return item;
}
/** change a logo badge's settings (corner / size reposition its box) — ONE step (sliders coalesce by key) */
export function setBadge(ctx, id, patch, label = 'Logo badge') {
  const p = ctx.project, it = all(p).find((x) => x.item.id === id)?.item; if (!it) return;
  const style = clone(it.style); style.badge = { ...badgeOf(style), ...patch };
  const props = { style };
  if ('corner' in patch || 'size' in patch || 'variant' in patch) {
    if (patch.variant) style.variant = patch.variant;
    const b = style.badge;
    if (b.corner !== 'free') Object.assign(props, logoBox(style.variant, b.corner, b.size, p.canvas.w, p.canvas.h, aspectOf(p)));
    else { const sh = style.variant === 'pill' ? 2.2 : 1, s = Math.min(p.canvas.w, p.canvas.h) * b.size, w = Math.round(sh > 1 ? s * 2.2 : s), h = Math.round(sh > 1 ? w / 2.6 : s); Object.assign(props, { x: Math.round(it.x + (it.w - w) / 2), y: Math.round(it.y + (it.h - h) / 2), w, h }); }
  }
  ctx.history.apply(Object.assign(ops.setItems([{ id, props }], label), { key: 'el2:' + id + ':' + Object.keys(patch).join(',') }));
}

// ── ending screen ──
export const endScreen = (p) => all(p).find((x) => x.item.type === 'theme.el' && x.item.style?.el === 'endscreen' && x.item.auto === AUTO_WHOLE)?.item || null;
/** the ending screen at the end of the reel (replaces the one there, keeping its slides, length, photo and words) */
export function addEndScreen(ctx, variant = 'corporate', opts = {}) {
  const p = ctx.project, ws = ctx.workspace || {}, W = p.canvas.w, H = p.canvas.h, th = themeById(themeNow(p)) || themeById('travel');
  const D = reelEnd2(p); if (!(D > 0.5)) throw new Error('Put a clip on the timeline first: the ending screen closes the reel');
  const old = endScreen(p), cmds = [];
  const style = elementStyle(th.id, 'endscreen', { colors: colorsFor(th, 'theme', ws), variant, brand: ws.name || null, logo: ws.logo || null, ws });
  if (old) for (const k of ['slides', 'photo', 'text', 'lang', 'palette', 'tstyle']) if (old.style?.[k] != null) style[k] = old.style[k];
  if (opts.slides) style.slides = opts.slides;
  style.whole = 'end';
  const dur = Math.min(D, Math.max(1, Math.min(10, opts.dur ?? (old ? (old.out - old.in) : 3))));
  const item = createItem('theme.el', { t0: r3(D - dur), in: 0, out: r3(dur), x: 0, y: 0, w: W, h: H, auto: AUTO_WHOLE, name: `🏁 Ending screen · ${variant}`, style });
  if (old) cmds.push(ops.removeItems([old.id], 'Replace ending screen'));
  const tr = laneFor(p, 'Ending screen', cmds);
  cmds.push(ops.addItems([{ trackId: tr.id, item }], 'Ending screen'));
  ctx.history.apply(ops.batch(`Ending screen · ${variant}`, cmds));
  return item;
}
/** an ending screen's length (1–10 s), still closing the reel — ONE step (a slider drag coalesces) */
export function setEndDuration(ctx, id, d) {
  const p = ctx.project, it = all(p).find((x) => x.item.id === id)?.item; if (!it) return;
  const D = it.style?.whole === 'end' && it.auto === AUTO_WHOLE ? reelEnd2(p) : itemEnd(it), dd = Math.min(D, Math.max(1, Math.min(10, d)));
  ctx.history.apply(Object.assign(ops.setItems([{ id, props: { t0: r3(D - dd), in: 0, out: r3(dd * (it.speed || 1)) } }], 'Ending screen length'), { key: 'el2dur:' + id }));
}

// ── contact card ──
export function refreshContact(ctx, id) {
  const it = all(ctx.project).find((x) => x.item.id === id)?.item; if (!it) return null;
  const style = clone(it.style); style.contact = contactOf(ctx.workspace || {});
  const c = ctx.workspace?.colors; if (c?.primary) style.brandColors = { primary: c.primary, secondary: c.secondary || null, accent: c.accent || c.secondary || null, text: c.text || null, soft: c.soft || null };
  ctx.history.apply(ops.setItems([{ id, props: { style } }], 'Contact from Configure'));
  return style.contact;
}

// ── country badges for every mention ──
export const mentionBadges = (p) => all(p).filter((x) => x.item.type === 'theme.el' && x.item.auto === AUTO_BADGE).map((x) => x.item);
/** the caption words a scan reads: the main track, and a second-language track when there is one */
const captionWords = (p) => [...(p.captions?.words || []), ...(p.captions?.alt?.words || [])].filter((w) => w && w.w != null).sort((a, b) => (a.t0 || 0) - (b.t0 || 0));
const capSig = (p) => { const w = captionWords(p); let h = w.length; for (const x of w) { const s = String(x.w) + '@' + (+x.t0 || 0).toFixed(2); for (let i = 0; i < s.length; i++) h = (Math.imul(h, 31) + s.charCodeAt(i)) | 0; } return h; };

/** the badge items for the project's captions with these settings (pure: nothing is applied) */
function badgeItems(p, o) {
  const D = reelEnd2(p) || projectDuration(p) || 0;
  const spans = mentionSpans(findMentions(captionWords(p), { only: o.only }), { dur: o.dur, gap: o.gap, min: o.min ?? 1.2, end: D || Infinity });
  const th = themeById(themeNow(p)) || themeById('travel');
  const box = o.box || badgeBox(o.corner || 'tl', o.size || 0.3, p.canvas.w, p.canvas.h, aspectOf(p));
  return spans.map((s) => {
    const style = elementStyle(th.id, 'cbadge', { variant: o.look, ws: o.ws });
    Object.assign(style, { code: s.code, lang: o.lang || style.lang, flagIn: !!o.flagIn, mention: { dur: o.dur, gap: o.gap, min: o.min ?? 1.2, words: s.words.slice(0, 6) } });
    return createItem('theme.el', { t0: s.t0, in: 0, out: r3(s.t1 - s.t0), ...box, auto: AUTO_BADGE, name: `🌐 ${placeName(s.code, 'en')} badge`, style });
  });
}
/**
 * Badge every country I mention. opts = { look, dur (s, default 3), gap (merge, default 2), min (s on screen, 1.2), lang,
 * flagIn, corner, size }.
 * Replaces the mention badges already there. ONE undo step. Returns { n, codes, items }.
 */
export function badgeMentions(ctx, opts = {}) {
  const p = ctx.project;
  if (!captionWords(p).length) throw new Error('Add captions first: the badges follow the words you say (Captions → Auto captions)');
  const prev = mentionBadges(p)[0];
  const o = { look: opts.look || prev?.style?.variant || 'glass', dur: opts.dur ?? prev?.style?.mention?.dur ?? 3, gap: opts.gap ?? prev?.style?.mention?.gap ?? 2, min: opts.min ?? prev?.style?.mention?.min ?? 1.2, lang: opts.lang || prev?.style?.lang, flagIn: opts.flagIn ?? prev?.style?.flagIn, corner: opts.corner, size: opts.size, ws: ctx.workspace, only: opts.only,
    box: opts.corner || opts.size ? null : prev ? { x: prev.x, y: prev.y, w: prev.w, h: prev.h } : null };
  const items = badgeItems(p, o), cmds = [], old = mentionBadges(p).map((i) => i.id);
  if (old.length) cmds.push(ops.removeItems(old, 'Replace country badges'));
  if (items.length) { const tr = laneFor(p, 'Country badges', cmds); cmds.push(ops.addItems(items.map((item) => ({ trackId: tr.id, item })), 'Country badges')); }
  if (cmds.length) ctx.history.apply(ops.batch(items.length ? `Country badges · ${items.length}` : 'Country badges off', cmds));
  return { n: items.length, codes: [...new Set(items.map((i) => i.style.code))], items };
}

// ── the follow hook ──
function swapCmd(p, removeIds, addList, setList) {
  const before = new Map(), recs = [];
  for (const tr of p.tracks) tr.items.forEach((it, i) => { if (removeIds.includes(it.id) || setList.some((s) => s.id === it.id)) before.set(it.id, { trackId: tr.id, index: i, item: clone(it) }); });
  const doIt = (q) => {
    for (const tr of q.tracks) tr.items = tr.items.filter((i) => !removeIds.includes(i.id));
    for (const s of setList) for (const tr of q.tracks) { const i = tr.items.findIndex((x) => x.id === s.id); if (i >= 0) tr.items[i] = Object.assign(clone(tr.items[i]), s.props); }
    for (const a of addList) { const tr = q.tracks.find((t) => t.id === a.trackId); if (tr && !tr.items.some((x) => x.id === a.item.id)) tr.items.push(clone(a.item)); }
  };
  const undo = (q) => {
    const added = new Set(addList.map((a) => a.item.id));
    for (const tr of q.tracks) tr.items = tr.items.filter((i) => !added.has(i.id));
    for (const [id, b] of [...before.entries()].sort((x, y) => x[1].index - y[1].index)) {
      const tr = q.tracks.find((t) => t.id === b.trackId); if (!tr) continue;
      const i = tr.items.findIndex((x) => x.id === id);
      if (i >= 0) tr.items[i] = clone(b.item); else tr.items.splice(Math.min(b.index, tr.items.length), 0, clone(b.item));
    }
  };
  void recs;
  return { label: 'Badges follow the reel', do: doIt, undo };
}
registerHistoryHook({
  id: 'el2.follow',
  // nothing to follow (no whole-video badge, no mention badge): no work on every edit
  before: (p) => (p?.tracks && p.tracks.some((t) => t.items.some((i) => i.auto === AUTO_WHOLE || i.auto === AUTO_BADGE)) ? { end: reelEnd2(p), cap: capSig(p) } : null),
  after: (p, st) => {
    if (!st || !p?.tracks) return null;
    const end = reelEnd2(p), cap = capSig(p), set = [], rem = [], add = [];
    // whole-video logo / cards: from 0 to the reel's end
    if (Math.abs(end - st.end) > 1e-3 && end > 0) for (const { item } of all(p)) if (item.auto === AUTO_WHOLE && item.style?.whole === 'span' && Math.abs(itemEnd(item) - end) > 1e-3) set.push({ id: item.id, props: { t0: 0, in: 0, out: r3(end * (item.speed || 1)) } });
    // ending screens close the reel: their end sits on the reel's end (after a trim, or a new duration)
    if (end > 0) for (const { item } of all(p)) if (item.auto === AUTO_WHOLE && item.style?.whole === 'end' && Math.abs(itemEnd(item) - end) > 1e-3) { const d = Math.min(end, (item.out - item.in) / (item.speed || 1)); set.push({ id: item.id, props: { t0: r3(end - d), out: r3(item.in + d * (item.speed || 1)) } }); }
    // mention badges: the captions changed -> derive them again with the same settings and place
    if (cap !== st.cap) {
      const cur = mentionBadges(p);
      if (cur.length) {
        const prev = cur[0], tr = p.tracks.find((t) => t.items.some((i) => i.id === prev.id));
        const items = badgeItems(p, { look: prev.style?.variant, dur: prev.style?.mention?.dur ?? 3, gap: prev.style?.mention?.gap ?? 2, min: prev.style?.mention?.min ?? 1.2, lang: prev.style?.lang, flagIn: prev.style?.flagIn, box: { x: prev.x, y: prev.y, w: prev.w, h: prev.h } });
        const same = items.length === cur.length && items.every((it, i) => it.style.code === cur[i].style?.code && Math.abs(it.t0 - cur[i].t0) < 1e-3 && Math.abs(it.out - cur[i].out) < 1e-3);
        if (!same) { rem.push(...cur.map((i) => i.id)); add.push(...items.map((item) => ({ trackId: tr.id, item }))); }
      }
    }
    return set.length || rem.length || add.length ? swapCmd(p, rem, add, set) : null;
  },
});

// ── cut-out transitions: the person, found once with progress ──
export async function afterCutPlaced(ctx, items) {
  const [{ ensureAnalysis }, { cutStatus }] = await Promise.all([import('../look/vision.js'), import('./cutout-trans.js')]);
  ctx.progress?.('Finding the person for the cut-out transition…', 0);
  try { await ensureAnalysis(ctx.project, { onProgress: (l, f) => ctx.progress?.(l || 'Finding the person…', f) }); }
  finally { ctx.progress?.(null); }
  window.kbbEditor?.preview?.draw?.();
  const live = (items || []).map((it) => ctx.project.tracks.flatMap((t) => t.items).find((i) => i.id === it.id)).filter(Boolean);
  return live.map((it) => cutStatus(ctx.project, it));
}
export { boxFor };
