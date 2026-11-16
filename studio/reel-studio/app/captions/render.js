// The `caption` layer: one canvas-2D renderer used by the preview AND the export (registered
// through core/render.registerLayer('caption', drawCaptions)). A pure function of (project, t):
// no timers, no state, no DOM measuring — the same t always paints the same pixels.
//
// Arabic: each word is drawn as its own string, so the browser shapes it (joining forms, lam-alef)
// and RTL cues are laid out right-to-left with ctx.direction = 'rtl'.

import { resolveStyle } from './styles.js';
import { lineAt, wrapRows, applyCase, isRtlText } from './model.js';
import { suggestEmoji } from './emoji.js';

const DEVA_RE = /[ऀ-ॿ]/;
const EMOJI_FALLBACK = '"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji"';

/** Platform-safe box (Reels/TikTok UI covers the top band, the bottom ~20 % and the right rail). */
export function safeArea(W, H) {
  if (H / W > 1.5) return { x: W * 0.07, y: H * 0.12, w: W * 0.86, h: H * 0.68 };   // 9:16 → bottom at 80 %
  return { x: W * 0.06, y: H * 0.08, w: W * 0.88, h: H * 0.84 };
}

const clamp01 = (x) => x < 0 ? 0 : x > 1 ? 1 : x;
const easeOutBack = (x) => { const c = 1.70158; return 1 + (c + 1) * (x - 1) ** 3 + c * (x - 1) ** 2; };
const easeOut = (x) => 1 - (1 - x) ** 3;

function hexA(color, a) {
  if (a >= 1 || !/^#([0-9a-f]{6})$/i.test(color)) return color;
  const n = parseInt(color.slice(1), 16);
  return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`;
}

function rr(ctx, x, y, w, h, r) {
  r = Math.max(0, Math.min(r, h / 2, w / 2));
  ctx.beginPath();
  ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
}

function fontFor(st, text) {
  if (isRtlText(text)) return st.fontAr || 'Cairo';
  if (DEVA_RE.test(text)) return 'Noto Sans Devanagari';
  return st.font;
}

/**
 * Lay out one cue: returns { rows:[{words:[{i,text,x,w}], w}], px, rowH, height, rtl, family }.
 * Nothing is drawn. x is relative to the row's left edge.
 */
function layoutCue(ctx, cap, L, st, k, maxW) {
  const words = [];
  for (let i = L.wordIdx[0]; i <= L.wordIdx[1]; i++) words.push({ i, text: applyCase(cap.words[i].w.trim(), st.case) });
  const all = words.map((w) => w.text).join(' ');
  const rtl = isRtlText(all);
  const family = fontFor(st, all);
  let px = st.size * k;
  const spacing = rtl ? 0 : st.tracking * k;
  const setFont = () => {
    ctx.font = `${st.weight} ${px}px "${family}",${EMOJI_FALLBACK},sans-serif`;
    if ('letterSpacing' in ctx) ctx.letterSpacing = spacing ? `${spacing}px` : '0px';
  };
  setFont();
  ctx.direction = rtl ? 'rtl' : 'ltr';
  for (const w of words) w.w = ctx.measureText(w.text).width;
  // styles that grow the spoken word get extra room between words so it never touches its neighbours
  let space = ctx.measureText(' ').width + (st.anim === 'highlight-scale' || st.anim === 'pop' ? 0.1 * px : 0);
  const ranges = wrapRows(words.map((w) => w.text), st.rowChars * (rtl ? 1.15 : 1), st.maxRows);
  const rowW = (a, b) => { let s = 0; for (let j = a; j <= b; j++) s += words[j].w; return s + space * (b - a); };
  let widest = Math.max(...ranges.map(([a, b]) => rowW(a, b)));
  // Too wide for the safe box (big font, long word): shrink the whole cue, never overflow.
  if (widest > maxW) {
    const f = Math.max(0.45, maxW / widest);
    px *= f; space *= f; widest *= f;
    for (const w of words) w.w *= f;
    setFont();
  }
  const rows = ranges.map(([a, b]) => {
    const ws = words.slice(a, b + 1);
    const w = rowW(a, b);
    let x = rtl ? w : 0;
    for (const word of ws) {
      if (rtl) { x -= word.w; word.x = x; x -= space; } else { word.x = x; x += word.w + space; }
    }
    return { words: ws, w };
  });
  const rowH = px * st.lineHeight;
  return { rows, px, rowH, height: rows.length * rowH, rtl, family, spacing, widest };
}

function wordState(w, t) {
  if (t < w.t0) return { s: 'future', p: 0, age: t - w.t0 };
  if (t >= w.t1) return { s: 'past', p: 1, age: t - w.t0 };
  return { s: 'active', p: (t - w.t0) / Math.max(0.01, w.t1 - w.t0), age: t - w.t0 };
}

/** Draw one laid-out cue with its top-left at (bx, by) and width bw (row alignment inside it). */
function drawCue(ctx, cap, L, st, lay, t, k, bx, by, bw, align) {
  const { rows, px, rowH, rtl, family } = lay;
  const pad = st.bg.pad * k;
  let alpha = 1, dx = 0;
  const inT = t - L.t0, outT = L.t1 - t;
  if (st.anim === 'fade') alpha = clamp01(Math.min(inT, outT) / 0.25);
  if (st.anim === 'slide') { const e = easeOut(clamp01(inT / 0.3)); dx = (rtl ? 1 : -1) * (1 - e) * 60 * k; alpha = e * clamp01(outT / 0.15); }
  if (alpha <= 0) return;
  ctx.save();
  ctx.globalAlpha *= alpha;
  ctx.translate(dx, 0);
  ctx.direction = rtl ? 'rtl' : 'ltr';
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.lineJoin = 'round';
  ctx.miterLimit = 2;
  const font = `${st.weight} ${px}px "${family}",${EMOJI_FALLBACK},sans-serif`;
  ctx.font = font;
  if ('letterSpacing' in ctx) ctx.letterSpacing = lay.spacing ? `${lay.spacing}px` : '0px';

  const rowX = (r) => align === 'start' ? (rtl ? bx + bw - r.w : bx) : bx + (bw - r.w) / 2;
  const bgCol = hexA(st.bg.color, st.bg.opacity);
  // ---- block backgrounds
  if (st.bg.type === 'box' || st.bg.type === 'card' || st.bg.type === 'bar') {
    const w = Math.max(...rows.map((r) => r.w));
    let x0 = align === 'start' ? (rtl ? bx + bw - w : bx) : bx + (bw - w) / 2;
    if (st.bg.type === 'bar') x0 -= 0;
    const box = { x: x0 - pad - (st.bg.type === 'bar' ? 10 * k : 0), y: by - pad * 0.7, w: w + pad * 2 + (st.bg.type === 'bar' ? 10 * k : 0), h: lay.height + pad * 1.4 };
    if (st.bg.type === 'card') { ctx.save(); ctx.shadowColor = 'rgba(0,0,0,0.35)'; ctx.shadowBlur = 24 * k; ctx.shadowOffsetY = 8 * k; }
    ctx.fillStyle = bgCol; rr(ctx, box.x, box.y, box.w, box.h, st.bg.radius * k); ctx.fill();
    if (st.bg.type === 'card') {
      ctx.restore();
      ctx.fillStyle = st.accent; rr(ctx, box.x + box.w * 0.08, box.y - 3 * k, box.w * 0.84, 6 * k, 3 * k); ctx.fill();
    }
    if (st.bg.type === 'bar') { // accent stripe on the reading-start side
      ctx.fillStyle = st.accent;
      ctx.fillRect(rtl ? box.x + box.w - 10 * k : box.x, box.y, 10 * k, box.h);
    }
  }
  if (st.bg.type === 'pill') {
    rows.forEach((r, j) => { ctx.fillStyle = bgCol; rr(ctx, rowX(r) - pad * 1.3, by + j * rowH - pad * 0.25, r.w + pad * 2.6, rowH + pad * 0.5, st.bg.radius * k); ctx.fill(); });
  }

  // ---- per word
  const emojiCue = st.emoji || L.emoji;
  rows.forEach((r, j) => {
    const cy = by + j * rowH + rowH / 2;
    const x0 = rowX(r);
    for (const word of r.words) {
      const W = cap.words[word.i], ws = wordState(W, t);
      const x = x0 + word.x, w = word.w;
      let text = word.text, scale = 1, oy = 0, color = st.color, show = true;
      const isActive = ws.s === 'active';
      if (st.dim && ws.s === 'future') color = st.dim;
      if (st.active && isActive && st.anim !== 'karaoke') color = st.active;
      switch (st.anim) {
        case 'highlight-scale': if (isActive) scale = 1 + 0.18 * easeOut(clamp01(ws.age / 0.09)); break;
        case 'pop':
          if (ws.s === 'future') show = false;
          else scale = ws.age < 0.2 ? 0.55 + 0.45 * easeOutBack(clamp01(ws.age / 0.2)) : 1;
          break;
        case 'bounce': if (isActive) oy = -Math.sin(ws.p * Math.PI) * 0.2 * px; break;
        case 'typewriter':
          if (ws.s === 'future') show = false;
          else if (isActive) text = [...text].slice(0, Math.max(1, Math.ceil(ws.p * [...text].length))).join('');
          break;
        case 'karaoke': if (ws.s === 'past') color = st.active || st.color; break;
        default:
      }
      if (!show) continue;
      ctx.save();
      ctx.translate(x + w / 2, cy + oy);
      if (scale !== 1) ctx.scale(scale, scale);
      const lx = -w / 2;
      // word backgrounds
      if (st.anim === 'highlight-box' && isActive && st.activeBg) {
        const e = 0.92 + 0.08 * easeOutBack(clamp01(ws.age / 0.12));
        ctx.save(); ctx.scale(e, e);
        ctx.fillStyle = st.activeBg; rr(ctx, lx - 0.16 * px, -0.62 * px, w + 0.32 * px, 1.24 * px, 0.22 * px); ctx.fill();
        ctx.restore();
      }
      if (st.bg.type === 'word') { ctx.fillStyle = bgCol; rr(ctx, lx - 0.12 * px, -0.6 * px, w + 0.24 * px, 1.2 * px, st.bg.radius * k); ctx.fill(); }
      if (st.bg.type === 'marker' && ws.s !== 'future') {
        const f = isActive ? easeOut(clamp01(ws.p * 1.6)) : 1;
        ctx.fillStyle = bgCol;
        const mw = (w + 0.2 * px) * f, mx = rtl ? lx + w + 0.1 * px - mw : lx - 0.1 * px;
        ctx.beginPath(); ctx.moveTo(mx + 0.06 * px, -0.42 * px); ctx.lineTo(mx + mw, -0.48 * px); ctx.lineTo(mx + mw - 0.04 * px, 0.5 * px); ctx.lineTo(mx, 0.44 * px); ctx.closePath(); ctx.fill();
      }
      paintText(ctx, st, text, lx, color, k, isActive);
      if (st.anim === 'karaoke' && isActive) { // sweep the fill across the word in reading direction
        ctx.save();
        ctx.beginPath();
        const fw = w * ws.p;
        ctx.rect(rtl ? lx + w - fw : lx - 4, -px, fw + 4, px * 2);
        ctx.clip();
        paintText(ctx, { ...st, outline: { w: 0 }, shadow: { blur: 0, x: 0, y: 0 } }, text, lx, st.active || st.color, k, true);
        ctx.restore();
      }
      if (st.anim === 'typewriter' && isActive && Math.floor(t * 3) % 2 === 0) {
        const tw = ctx.measureText(text).width;
        ctx.fillStyle = st.color; ctx.fillRect(rtl ? lx + w - tw - 0.12 * px : lx + tw + 0.06 * px, -0.4 * px, 0.07 * px, 0.8 * px);
      }
      ctx.restore();
    }
  });
  if (emojiCue) {
    const e = L.emoji || suggestEmoji(cap.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => w.w))[0];
    if (e) {
      const s = 0.6 + 0.4 * easeOutBack(clamp01(inT / 0.25));
      ctx.save();
      ctx.font = `${Math.round(px * 1.15)}px ${EMOJI_FALLBACK},sans-serif`;
      ctx.textAlign = 'center';
      ctx.translate(bx + bw / 2, by - px * 0.85);
      ctx.scale(s, s);
      ctx.fillText(e, 0, 0);
      ctx.restore();
    }
  }
  ctx.restore();
}

function paintText(ctx, st, text, x, color, k, active) {
  const o = st.outline, sh = st.shadow, g = st.glow;
  if (g && g.blur > 0) { // neon: coloured glow, stronger on the spoken word
    ctx.save(); ctx.shadowColor = g.color; ctx.shadowBlur = g.blur * k * (active ? 1.6 : 1);
    ctx.fillStyle = g.color; ctx.fillText(text, x, 0); ctx.fillText(text, x, 0);
    ctx.restore();
  }
  if (sh && (sh.blur > 0 || sh.x || sh.y)) {
    ctx.save(); ctx.shadowColor = sh.color; ctx.shadowBlur = sh.blur * k; ctx.shadowOffsetX = sh.x * k; ctx.shadowOffsetY = sh.y * k;
    if (o && o.w > 0) { ctx.strokeStyle = o.color; ctx.lineWidth = o.w * k; ctx.strokeText(text, x, 0); }
    else { ctx.fillStyle = color; ctx.fillText(text, x, 0); }
    ctx.restore();
  }
  if (o && o.w > 0) { ctx.strokeStyle = o.color; ctx.lineWidth = o.w * k; ctx.strokeText(text, x, 0); }
  ctx.fillStyle = color; ctx.fillText(text, x, 0);
}

/**
 * Layer entry point (core/render.js): drawCaptions(ctx, item, t, project, env).
 * The caption item is a 'caption' item on the caption track spanning the captions; core translates
 * ctx to the item's box, so we undo that and lay out against the whole canvas and its safe area.
 * item.style (optional) overrides captions.style for that item.
 */
export function drawCaptions(ctx, item, t, project, env) {
  const cap = project && project.captions;
  if (!cap || !cap.words || !cap.words.length || !cap.lines) return;
  const st = resolveStyle(item && item.style ? item.style : cap.style);
  const W = project.canvas?.w || 1080, H = project.canvas?.h || 1920;
  const k = W / 1080 * (H / W < 1 ? 0.75 : 1);   // landscape canvases: a touch smaller
  const safe = safeArea(W, H);
  const align = st.bg.type === 'bar' ? 'start' : 'center';
  const maxW = safe.w - st.bg.pad * k * 2 - (st.bg.type === 'bar' ? 10 * k : 0);

  const blocks = [];
  const L = lineAt(cap.lines, t);
  if (L) blocks.push({ cap, L });
  if (st.bilingual && cap.alt && cap.alt.lines) {
    const A = lineAt(cap.alt.lines, t);
    if (A) blocks.push({ cap: cap.alt, L: A });
  }
  if (!blocks.length) return;
  ctx.save();
  if (env && env.box) ctx.translate(-env.box.x, -env.box.y);
  const lays = blocks.map((b) => ({ ...b, lay: layoutCue(ctx, b.cap, b.L, st, k, maxW) }));
  if (st.bilingual) lays.sort((a, b) => (a.lay.rtl ? 1 : 0) - (b.lay.rtl ? 1 : 0)); // English on top, Arabic below
  const gap = st.size * k * 0.35;
  const total = lays.reduce((s, b) => s + b.lay.height, 0) + gap * (lays.length - 1);
  const padY = st.bg.pad * k;
  let top = safe.y + st.pos.y * safe.h - total / 2;
  top = Math.max(safe.y + padY + (st.emoji ? st.size * k * 1.4 : 0), Math.min(top, safe.y + safe.h - total - padY));
  for (const b of lays) {
    drawCue(ctx, b.cap, b.L, st, b.lay, t, k, safe.x + (safe.w - maxW) / 2, top, maxW, align);
    top += b.lay.height + gap;
  }
  ctx.restore();
}

/** Register with R1's core renderer. Call once at boot: registerCaptionLayer(coreRender). */
export function registerCaptionLayer(core) { core.registerLayer('caption', drawCaptions); }
