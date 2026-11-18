// KBB Studio · themes · TEXT: the one place a theme element's words go through on their way to the canvas.
//
// The draw functions ask for every string with tx(S, key, default) and draw it with the art primitives (uiText,
// kineticText, arabicText, the families' T, rawText). While an element draws (drawElement brackets it with begin/end)
// those primitives hand each string here first, which
//   · ties it back to its key (the value tx just returned; a typewriter prefix or a capitalised copy still matches),
//   · applies the element's text style (item.style.tstyle: font, size, weight, italic, spacing, case, alignment, colour,
//     shadow / outline, per-field size and colour) and fits long text (shrink to a minimum, then an ellipsis),
//   · records where it landed (item-local box) so the preview can put an editor on it and the inspector knows which
//     fields the element draws and what they show.
// With no style and no edited words nothing changes: an old project draws exactly as before.
//
// Pure functions of the item and the time (no DOM, no timers): preview and export draw the same pixels.

let CUR = null;
const N = (s) => String(s ?? '').trim().toUpperCase();
const FIT = new Map();   // font|text|maxW|track -> {size, text}  (text layout cache: one measure per string per font)

/** start an element's text pass. opts: { rec: record boxes, fields: Map to collect the fields into } */
export function textBegin(c, tstyle, txt, opts = {}) {
  const prev = CUR;
  CUR = { c, s: cleanStyle(tstyle), txt: txt || {}, q: [], rec: opts.rec ? [] : null, fields: opts.fields || null, M0i: null, draws: opts.draws || null };
  if (CUR.rec) { try { CUR.M0i = c.getTransform().inverse(); } catch { CUR.rec = null; } }
  return prev;
}
/** end it; returns the pass ({rec: boxes}) */
export function textEnd(prev) { const o = CUR; CUR = prev; return o; }
export const textActive = () => !!CUR;

/** a style object with only the keys that change something (null when none do) */
export function cleanStyle(s) {
  if (!s || typeof s !== 'object') return null;
  const o = {};
  for (const [k, v] of Object.entries(s)) {
    if (v == null || v === '' || (k === 'scale' && +v === 1)) continue;
    if ((k === 'sizes' || k === 'colors') && (typeof v !== 'object' || !Object.keys(v).length)) continue;
    o[k] = v;
  }
  return Object.keys(o).length ? o : null;
}

/**
 * The string an element draws for `key`: the owner's words when set (an empty string hides the line), else the
 * default. While a pass is running the value is queued so the primitive that draws it knows its key.
 */
export function tx(S, key, def) {
  const t = S?.txt, has = !!t && t[key] != null;
  const v = has ? String(t[key]) : def == null ? '' : String(def);
  if (CUR) {
    CUR.q.push({ key, v, user: has });
    if (CUR.q.length > 24) CUR.q.shift();
    if (CUR.fields && !CUR.fields.has(key)) CUR.fields.set(key, { key, def: def == null ? '' : String(def), user: has, val: v, shown: '' });
  }
  return v;
}
/** the families' words: a read of o.words[key] is a tx (their defaults come from the piece's own table) */
export function wordsProxy(words, defs, userKeys) {
  if (!CUR) return words;
  return new Proxy(words, {
    get(o, k) {
      const v = o[k];
      if (CUR && typeof k === 'string' && typeof v === 'string') {
        CUR.q.push({ key: k, v, user: userKeys.has(k) }); if (CUR.q.length > 24) CUR.q.shift();
        if (CUR.fields && !CUR.fields.has(k)) CUR.fields.set(k, { key: k, def: defs[k] ?? '', user: userKeys.has(k), val: v, shown: '' });
      }
      return v;
    },
  });
}

const titleCase = (s) => s.toLowerCase().replace(/(^|[\s\-·/(])(\p{L})/gu, (m, a, b) => a + b.toUpperCase());
const isAr = (s) => /[\u0600-\u06FF]/.test(s);
function alphaOf(col) { const m = /^rgba\([^)]*,\s*([\d.]+)\s*\)$/.exec(col || ''); return m ? +m[1] : 1; }

/**
 * A primitive is about to draw d = {kind:'ui'|'kinetic'|'ar'|'fam'|'raw', text, x, y, size, col, align, weight, family,
 * maxW, track, base, shadow}. Returns d with its key (or null), and with custom=true when the style or the owner's words
 * change how it draws; then d holds the final text, size, font, x, align, colour, alpha, shadow, outline.
 */
export function textStyle(d) {
  if (!CUR) return null;
  const t = String(d.text), nt = N(t);
  // first in, first out: the strings are asked for in drawing order, so two equal placeholders ("--", "--") keep theirs
  let hit = -1;
  if (d.forceKey) hit = CUR.q.findIndex((e) => e.key === d.forceKey);
  else if (nt) hit = CUR.q.findIndex((e) => { const ne = N(e.v); return ne && (ne === nt || ne.startsWith(nt)); });
  const e = hit >= 0 ? CUR.q.splice(hit, 1)[0] : null;
  d.key = e?.key || d.forceKey || null; d.user = e ? !!e.user : !!(d.forceKey && CUR.txt[d.forceKey] != null);
  d.raw = e ? e.v : d.user ? String(CUR.txt[d.forceKey]) : null;
  if (CUR.fields && d.key) { const f = CUR.fields.get(d.key); if (f && t.length > (f.shown || '').length) f.shown = t; }
  if (CUR.draws) CUR.draws.push({ key: d.key, text: t, kind: d.kind });
  const s = CUR.s;
  d.custom = !!(s || d.user);
  if (!d.custom) return d;
  d.alpha = 1; d.font = null;
  if (s) {
    // case: upper / title / as typed (from the owner's own words when we know them)
    if (s.case && d.kind !== 'ar' && !isAr(t)) {
      const src = d.raw != null && N(d.raw).startsWith(nt) ? d.raw.slice(0, t.length) : t;
      d.text = s.case === 'upper' ? src.toUpperCase() : s.case === 'title' ? titleCase(src) : src;
    }
    const k = (+s.scale || 1) * (+(d.key && s.sizes?.[d.key]) || 1);
    d.size *= Math.max(0.3, Math.min(3, k));
    if (s.weight) d.weight = +s.weight;
    if (s.italic) d.italic = true;
    if (s.track != null && s.track !== '' && d.kind !== 'ar') d.track = +s.track;
    if (s.font) d.family = `"${String(s.font).replace(/["\\]/g, '')}", ${d.family}`;
    const col = (d.key && s.colors?.[d.key]) || s.color;
    if (col) { d.alpha = alphaOf(d.col); d.col = col; }
    if (s.shadow === 'on') d.shadow = 'on'; else if (s.shadow === 'off') d.shadow = false;
    if (s.outline) d.outline = s.outlineCol || 'rgba(0,0,0,0.85)';
    if (s.align && d.maxW < 1e8 && /^(left|center|right)$/.test(s.align) && s.align !== d.align) {
      const L = d.align === 'center' ? d.x - d.maxW / 2 : d.align === 'right' ? d.x - d.maxW : d.x;
      d.x = s.align === 'center' ? L + d.maxW / 2 : s.align === 'right' ? L + d.maxW : L; d.align = s.align;
    }
  }
  return d;
}

/** the font string for d at size */
export const fontOf = (d, size) => `${d.italic ? 'italic ' : ''}${d.weight} ${Math.round(size)}px ${d.family}`;

/**
 * Fit d into its maxW: shrink down to 55 % of its size, then cut with an ellipsis. Cached per font + text + width, so a
 * string is measured once, not every frame. Sets d.size / d.text.
 */
export function fitText(c, d) {
  if (!(d.maxW < 1e8) || !d.text) return d;
  const key = fontOf(d, d.size) + '|' + d.text + '|' + Math.round(d.maxW) + '|' + (d.track || 0);
  let r = FIT.get(key);
  if (!r) {
    const tw = (s, size) => { c.font = fontOf(d, size); return c.measureText(s).width + (d.track || 0) * size * s.length; };
    let size = d.size, text = d.text, w = tw(text, size);
    if (w > d.maxW) {
      size = Math.max(d.size * 0.55, d.size * d.maxW / w);
      w = tw(text, size);
      if (w > d.maxW * 1.001) {
        let lo = 0, hi = text.length;
        while (lo < hi) { const m = (lo + hi + 1) >> 1; if (tw(text.slice(0, m).trimEnd() + '…', size) <= d.maxW) lo = m; else hi = m - 1; }
        text = text.slice(0, lo).trimEnd() + '…';
      }
    }
    r = { size, text };
    if (FIT.size > 3000) FIT.clear();
    FIT.set(key, r);
  }
  d.size = r.size; d.text = r.text;
  return d;
}

/** draw d (a prepared, custom string) with fillText: shadow, outline, spacing, direction */
export function drawPlain(c, d) {
  c.save();
  if (d.alpha < 1) c.globalAlpha *= d.alpha;
  c.font = fontOf(d, d.size); c.textAlign = d.align; c.textBaseline = d.base || 'alphabetic';
  if (d.kind === 'ar' || isAr(d.text)) c.direction = 'rtl';
  if (d.track && 'letterSpacing' in c) c.letterSpacing = `${(d.size * d.track).toFixed(1)}px`;
  if (d.shadow === 'on') { c.shadowColor = 'rgba(0,0,0,0.55)'; c.shadowBlur = d.size * 0.14; c.shadowOffsetY = d.size * 0.05; }
  const mw = d.maxW < 1e8 ? d.maxW : undefined, fill = (fn, x, y) => (mw ? fn.call(c, d.text, x, y, mw) : fn.call(c, d.text, x, y));
  if (d.shadow === true && d.kind === 'ar') { c.fillStyle = 'rgba(0,0,0,0.35)'; fill(c.fillText, d.x + d.size * 0.04, d.y + d.size * 0.05); }
  if (d.outline) { c.lineJoin = 'round'; c.strokeStyle = d.outline; c.lineWidth = Math.max(1, d.size * 0.12); fill(c.strokeText, d.x, d.y); }
  c.fillStyle = d.col; fill(c.fillText, d.x, d.y);
  const w = c.measureText(d.text).width;
  c.restore();
  return w;
}

/** record where a string landed (item-local box), when the pass records */
export function noteBox(c, d, w) {
  if (!CUR?.rec || !d) return;
  const size = d.size, a = d.align || 'left', base = d.base || 'alphabetic';
  const x = a === 'center' ? d.x - w / 2 : a === 'right' || (a === 'start' && d.kind === 'ar') ? d.x - w : d.x;
  const y = base === 'middle' ? d.y - size * 0.55 : base === 'top' ? d.y : base === 'bottom' ? d.y - size * 1.05 : d.y - size * 0.82;
  const m = CUR.M0i.multiply(c.getTransform());
  let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
  for (const [px, py] of [[x, y], [x + w, y], [x, y + size * 1.05], [x + w, y + size * 1.05]]) {
    const X = m.a * px + m.c * py + m.e, Y = m.b * px + m.d * py + m.f;
    if (X < x0) x0 = X; if (X > x1) x1 = X; if (Y < y0) y0 = Y; if (Y > y1) y1 = Y;
  }
  const k = Math.hypot(m.a, m.b) || 1;
  CUR.rec.push({ key: d.key || null, text: d.text, x: x0, y: y0, w: x1 - x0, h: y1 - y0, size: size * k, ar: d.kind === 'ar' || isAr(d.text), align: a });
}

// ── the boxes the preview drew last, per item (for editing on the canvas) ──
const LAST = new Map();
export function setLastBoxes(id, t, boxes) { LAST.set(id, { t, boxes }); if (LAST.size > 64) LAST.delete(LAST.keys().next().value); }
export const lastBoxes = (id) => LAST.get(id) || null;
