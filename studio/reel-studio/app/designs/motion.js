// KBB Studio · Designs · MOTION: the animated version of a design is the SAME drawing at a time t (render.js draw(...,
// {t})), so the editor's ▶ Play, the MP4 export and the still PNG are one code path. A design holds still from
// `still` seconds on: every entrance has finished by then and the frame at t = still is the still picture, pixel for
// pixel (the PNG/JPG export and every preview draw that frame). Art pieces (broadcast.js) keep living after that: the
// flag waves, clouds drift, the crawl scrolls.
//
//   motionOf(D)                -> {dur, still}
//   entrances(D, items, size)  -> Map(el.id -> {k, at, d})   a default per role, el.anim overrides
//   enter(ctx, e, box, t, ar)  -> false when the element is not on screen yet; otherwise applies alpha + transform
const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
const easeOut = (x) => 1 - Math.pow(1 - clamp(x), 3);
const back = (x) => { x = clamp(x); const c = 1.7; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };

export const DUR = 8, STILL = 4;
export const motionOf = (D) => ({ dur: D?.motion?.dur || DUR, still: D?.motion?.still ?? STILL });
export const ANIM_KINDS = ['fade', 'rise', 'slide', 'pop', 'grow', 'drop', 'wipe'];

/** the entrance of every element: big art first, then top-to-bottom text with a stagger, the button last */
export function entrances(D, items) {
  const out = new Map();
  const order = items.filter((it) => !it.el.deco && it.el.t !== 'art').sort((a, b) => (a.text?.box || a.box).y - (b.text?.box || b.box).y);
  const rank = new Map(order.map((it, i) => [it.el.id, i]));
  for (const it of items) {
    const el = it.el;
    if (el.anim) { out.set(el.id, { k: el.anim.k || 'fade', at: el.anim.at ?? 0, d: el.anim.d ?? 0.6 }); continue; }
    if (el.t === 'art') { out.set(el.id, { k: el.pan ? 'fade' : el.full ? 'fade' : 'grow', at: el.full ? 0 : 0.15, d: el.full ? 0.5 : 0.7 }); continue; }
    if (el.deco) { out.set(el.id, { k: 'wipe', at: 0.2, d: 0.55 }); continue; }
    const i = rank.get(el.id) || 0, at = Math.min(2.2, 0.45 + i * 0.16);
    const k = el.role === 'cta' ? 'pop' : el.role === 'headline' || el.role === 'big' ? 'rise' : el.role === 'kicker' || el.role === 'contact' ? 'slide' : el.t === 'logo' ? 'fade' : el.t === 'img' || el.t === 'prod' ? 'pop' : 'rise';
    out.set(el.id, { k, at: el.role === 'cta' ? Math.max(at, 1.6) : at, d: k === 'pop' ? 0.55 : 0.6 });
  }
  return out;
}

/** apply an entrance at t (ctx already saved by the caller); false = not visible yet */
export function enter(ctx, e, box, t, ar) {
  if (!e) return true;
  const p = clamp((t - e.at) / e.d);
  if (p >= 1) return true;            // finished: the element draws exactly as in the still
  if (p <= 0) return false;
  const q = easeOut(p), cx = box.x + box.w / 2, cy = box.y + (box.h || 0) / 2;
  ctx.globalAlpha *= clamp(p * 1.6);
  if (e.k === 'rise') ctx.translate(0, (1 - q) * Math.min(90, (box.h || 60) * 0.6));
  else if (e.k === 'drop') ctx.translate(0, -(1 - q) * Math.min(120, (box.h || 60)));
  else if (e.k === 'slide') ctx.translate((ar ? 1 : -1) * (1 - q) * 120, 0);
  else if (e.k === 'pop' || e.k === 'grow') { const s = e.k === 'pop' ? 0.7 + 0.3 * back(p) : 0.9 + 0.1 * q; ctx.translate(cx, cy); ctx.scale(s, s); ctx.translate(-cx, -cy); }
  else if (e.k === 'wipe') { ctx.beginPath(); ar ? ctx.rect(box.x + box.w * (1 - q), box.y - 4, box.w * q + 4, (box.h || 0) + 8) : ctx.rect(box.x - 4, box.y - 4, box.w * q + 4, (box.h || 0) + 8); ctx.clip(); }
  return true;
}
