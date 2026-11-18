// The `caption` layer: one canvas-2D renderer used by the preview AND the export (registered
// through core/render.registerLayer('caption', drawCaptions)). A pure function of (project, t):
// no timers, no state, no DOM measuring — the same t always paints the same pixels.
//
// Arabic: each word is drawn as its own string, so the browser shapes it (joining forms, lam-alef)
// and RTL cues are laid out right-to-left with ctx.direction = 'rtl'.
//
// Captions round (16 Nov):
//   * sound tags ("[Music]", "♪", "(applause)") follow captions.soundTags: hidden / an icon / the text, and a line
//     that shows nothing is not drawn; with the icon setting a ♪ shows through music-only stretches (captions.music);
//   * per-line overrides  line.ov = { size, color, active, activeBg, font, fontAr, weight, case, anim, pos:{x,y} }
//     and per-word overrides word.ov = { color, size, font, weight, hl (highlight colour), emoji } on top of the style;
//   * style.pos.x (0..1 across the safe area) and a line's own pos move the caption block (dragged on the canvas);
//   * more animations / backgrounds for the template gallery (see styles-k.js).

import { resolveStyle } from './styles.js';
import { lineAt, wrapRows, applyCase, isRtlText } from './model.js';
import { suggestEmoji } from './emoji.js';
import { displayWord, inMusic, DEFAULT_TAG_MODE } from './nonspeech.js';

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

const URDU_RE = /[ٹڈڑںےۓ]/;
function fontFor(st, text, lang) {
  // translation lane: Urdu reads in Nastaliq (Arabic-script fonts set it as Naskh, which Urdu readers find foreign)
  if (isRtlText(text) && (lang === 'ur' || (!lang && URDU_RE.test(text)))) return st.fontUr || 'Noto Nastaliq Urdu';
  if (isRtlText(text)) return st.fontAr || 'Cairo';
  if (DEVA_RE.test(text)) return 'Noto Sans Devanagari';
  return st.font;
}

// The style keys a line may override (anything else in line.ov is ignored by the renderer).
export const LINE_OV_KEYS = ['size', 'color', 'active', 'activeBg', 'font', 'fontAr', 'weight', 'case', 'anim', 'outline', 'bg', 'accent'];
export const WORD_OV_KEYS = ['color', 'size', 'font', 'weight', 'hl', 'emoji'];

/** The style one line draws with: the caption style + that line's overrides. */
export function lineStyle(st, L) {
  const ov = L && L.ov;
  if (!ov) return st;
  const out = { ...st };
  for (const k of LINE_OV_KEYS) {
    if (ov[k] == null) continue;
    out[k] = ov[k] && typeof ov[k] === 'object' && st[k] && typeof st[k] === 'object' ? { ...st[k], ...ov[k] } : ov[k];
  }
  return out;
}

const fontStr = (weight, px, family) => `${weight} ${px}px "${family}",${EMOJI_FALLBACK},sans-serif`;

/**
 * Lay out one cue: returns { rows:[{words:[{i,text,x,w,px,font}], w}], px, rowH, height, rtl, family } or null when
 * nothing in it shows. Nothing is drawn. x is relative to the row's left edge.
 */
function layoutCue(ctx, cap, L, st, k, maxW, mode) {
  const words = [];
  for (let i = L.wordIdx[0]; i <= L.wordIdx[1]; i++) {
    const W = cap.words[i]; if (!W) continue;
    const shown = displayWord(W, mode);
    if (!shown) continue;
    let text = applyCase(String(shown).trim(), st.case);
    if (W.ov?.emoji) text += ' ' + W.ov.emoji;
    if (text) words.push({ i, text, ov: W.ov || null });
  }
  if (!words.length) return null;
  const all = words.map((w) => w.text).join(' ');
  const rtl = isRtlText(all);
  const family = fontFor(st, all, cap.lang);
  let px = st.size * k;
  const spacing = rtl ? 0 : st.tracking * k;
  const setSpacing = () => { if ('letterSpacing' in ctx) ctx.letterSpacing = spacing ? `${spacing}px` : '0px'; };
  ctx.direction = rtl ? 'rtl' : 'ltr';
  setSpacing();
  let maxScale = 1;
  const measure = () => {
    for (const w of words) {
      const sc = w.ov?.size ? w.ov.size / st.size : 1;
      maxScale = Math.max(maxScale, sc);
      w.px = px * sc;
      w.font = fontStr(w.ov?.weight || st.weight, w.px, w.ov?.font ? (isRtlText(w.text) ? st.fontAr || w.ov.font : w.ov.font) : family);
      ctx.font = w.font;
      w.w = ctx.measureText(w.text).width;
    }
  };
  measure();
  ctx.font = fontStr(st.weight, px, family);
  // styles that grow the spoken word get extra room between words so it never touches its neighbours
  const grow = st.anim === 'highlight-scale' || st.anim === 'pop' || st.anim === 'stamp';
  let space = ctx.measureText(' ').width + (grow ? 0.1 * px : 0);
  const ranges = wrapRows(words.map((w) => w.text), st.rowChars * (rtl ? 1.15 : 1), st.maxRows);
  const rowW = (a, b) => { let s = 0; for (let j = a; j <= b; j++) s += words[j].w; return s + space * (b - a); };
  let widest = Math.max(...ranges.map(([a, b]) => rowW(a, b)));
  // Too wide for the safe box (big font, long word): shrink the whole cue, never overflow.
  if (widest > maxW) {
    const f = Math.max(0.45, maxW / widest);
    px *= f; space *= f; widest *= f;
    for (const w of words) { w.w *= f; w.px *= f; w.font = fontStr(w.ov?.weight || st.weight, w.px, w.ov?.font ? (isRtlText(w.text) ? st.fontAr || w.ov.font : w.ov.font) : family); }
  }
  const rows = ranges.map(([a, b]) => {
    const ws = words.slice(a, b + 1);
    const w = rowW(a, b);
    let x = rtl ? w : 0;
    for (const word of ws) {
      if (rtl) { x -= word.w; word.x = x; x -= space; } else { word.x = x; x += word.w + space; }
    }
    // bidi: inside a right-to-left row, a run of left-to-right words (English in Urdu / Arabic: "Beauty of Joseon",
    // "How to apply") keeps its own reading order — the run's slots are refilled left to right
    if (rtl) {
      const ltr = (wd) => /[A-Za-z0-9]/.test(wd.text) && !isRtlText(wd.text);
      for (let j = 0; j < ws.length;) {
        if (!ltr(ws[j])) { j++; continue; }
        let k = j; while (k + 1 < ws.length && ltr(ws[k + 1])) k++;
        if (k > j) { let lx = ws[k].x; for (let q = j; q <= k; q++) { ws[q].x = lx; lx += ws[q].w + space; } }
        j = k + 1;
      }
    }
    return { words: ws, w };
  });
  const rowH = px * st.lineHeight * maxScale * (family === 'Noto Nastaliq Urdu' ? 1.45 : 1);   // Nastaliq's tall ascenders / deep descenders
  return { rows, px, rowH, height: rows.length * rowH, rtl, family, spacing, widest };
}

function wordState(w, t) {
  if (t < w.t0) return { s: 'future', p: 0, age: t - w.t0 };
  if (t >= w.t1) return { s: 'past', p: 1, age: t - w.t0 };
  return { s: 'active', p: (t - w.t0) / Math.max(0.01, w.t1 - w.t0), age: t - w.t0 };
}

// deterministic pseudo-random in [-1,1] from a number (glitch / shake: same t → same pixels)
const jitter = (x) => { const s = Math.sin(x * 12.9898) * 43758.5453; return (s - Math.floor(s)) * 2 - 1; };

/** Draw one laid-out cue with its top-left at (bx, by) and width bw (row alignment inside it). */
function drawCue(ctx, cap, L, st, lay, t, k, bx, by, bw, align) {
  const { rows, px, rowH, rtl, family } = lay;
  const pad = st.bg.pad * k;
  let alpha = 1, dx = 0, cueScale = 1;
  const inT = t - L.t0, outT = L.t1 - t;
  if (st.anim === 'fade') alpha = clamp01(Math.min(inT, outT) / 0.25);
  if (st.anim === 'slide') { const e = easeOut(clamp01(inT / 0.3)); dx = (rtl ? 1 : -1) * (1 - e) * 60 * k; alpha = e * clamp01(outT / 0.15); }
  if (st.anim === 'zoom') { const e = easeOutBack(clamp01(inT / 0.3)); cueScale = 0.7 + 0.3 * e; alpha = clamp01(inT / 0.12); }
  if (alpha <= 0) return;
  ctx.save();
  ctx.globalAlpha *= alpha;
  ctx.translate(dx, 0);
  if (cueScale !== 1) { const cx = bx + bw / 2, cy = by + lay.height / 2; ctx.translate(cx, cy); ctx.scale(cueScale, cueScale); ctx.translate(-cx, -cy); }
  ctx.direction = rtl ? 'rtl' : 'ltr';
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.lineJoin = 'round';
  ctx.miterLimit = 2;
  const font = fontStr(st.weight, px, family);
  ctx.font = font;
  if ('letterSpacing' in ctx) ctx.letterSpacing = lay.spacing ? `${lay.spacing}px` : '0px';

  const rowX = (r) => align === 'start' ? (rtl ? bx + bw - r.w : bx) : bx + (bw - r.w) / 2;
  const bgCol = hexA(st.bg.color, st.bg.opacity);
  // ---- block backgrounds
  const bt = st.bg.type;
  if (bt === 'box' || bt === 'card' || bt === 'bar' || bt === 'gradient' || bt === 'outline' || bt === 'tape' || bt === 'bubble') {
    const w = Math.max(...rows.map((r) => r.w));
    const x0 = align === 'start' ? (rtl ? bx + bw - w : bx) : bx + (bw - w) / 2;
    const box = { x: x0 - pad - (bt === 'bar' ? 10 * k : 0), y: by - pad * 0.7, w: w + pad * 2 + (bt === 'bar' ? 10 * k : 0), h: lay.height + pad * 1.4 };
    if (bt === 'card') { ctx.save(); ctx.shadowColor = 'rgba(0,0,0,0.35)'; ctx.shadowBlur = 24 * k; ctx.shadowOffsetY = 8 * k; }
    if (bt === 'tape') { ctx.save(); ctx.translate(box.x + box.w / 2, box.y + box.h / 2); ctx.rotate(-0.035); ctx.translate(-(box.x + box.w / 2), -(box.y + box.h / 2)); }
    if (bt === 'gradient') {
      const g = ctx.createLinearGradient(box.x, 0, box.x + box.w, 0);
      g.addColorStop(0, bgCol); g.addColorStop(1, hexA(st.accent, st.bg.opacity));
      ctx.fillStyle = g;
    } else ctx.fillStyle = bgCol;
    rr(ctx, box.x, box.y, box.w, box.h, st.bg.radius * k); ctx.fill();
    if (bt === 'outline') { ctx.lineWidth = 4 * k; ctx.strokeStyle = st.accent; rr(ctx, box.x, box.y, box.w, box.h, st.bg.radius * k); ctx.stroke(); }
    if (bt === 'bubble') { // a tail under the bubble, towards the speaker's side
      ctx.beginPath(); const tx = box.x + box.w * (rtl ? 0.78 : 0.22);
      ctx.moveTo(tx - 22 * k, box.y + box.h - 2); ctx.lineTo(tx + (rtl ? 26 : -26) * k, box.y + box.h + 38 * k); ctx.lineTo(tx + 22 * k, box.y + box.h - 2); ctx.closePath(); ctx.fill();
      ctx.lineWidth = 5 * k; ctx.strokeStyle = st.accent; rr(ctx, box.x, box.y, box.w, box.h, st.bg.radius * k); ctx.stroke();
    }
    if (bt === 'tape') {
      ctx.fillStyle = 'rgba(255,255,255,0.35)'; ctx.fillRect(box.x - 6 * k, box.y - 8 * k, 46 * k, 22 * k); ctx.fillRect(box.x + box.w - 40 * k, box.y + box.h - 14 * k, 46 * k, 22 * k);
      ctx.restore();
    }
    if (bt === 'card') {
      ctx.restore();
      ctx.fillStyle = st.accent; rr(ctx, box.x + box.w * 0.08, box.y - 3 * k, box.w * 0.84, 6 * k, 3 * k); ctx.fill();
    }
    if (bt === 'bar') { // accent stripe on the reading-start side
      ctx.fillStyle = st.accent;
      ctx.fillRect(rtl ? box.x + box.w - 10 * k : box.x, box.y, 10 * k, box.h);
    }
  }
  if (bt === 'pill') {
    rows.forEach((r, j) => { ctx.fillStyle = bgCol; rr(ctx, rowX(r) - pad * 1.3, by + j * rowH - pad * 0.25, r.w + pad * 2.6, rowH + pad * 0.5, st.bg.radius * k); ctx.fill(); });
  }

  // ---- per word
  const emojiCue = st.emoji || L.emoji;
  let wordNo = 0;
  rows.forEach((r, j) => {
    const cy = by + j * rowH + rowH / 2;
    const x0 = rowX(r);
    for (const word of r.words) {
      const W = cap.words[word.i], ws = wordState(W, t), wov = word.ov;
      const x = x0 + word.x, w = word.w, wpx = word.px || px;
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
        case 'stamp':
          if (ws.s === 'future') show = false;
          else if (ws.age < 0.16) scale = 1.7 - 0.7 * easeOut(clamp01(ws.age / 0.16));
          break;
        case 'rise':
          if (ws.s === 'future') show = false;
          else if (ws.age < 0.25) oy = (1 - easeOut(clamp01(ws.age / 0.25))) * 0.45 * wpx;
          break;
        case 'bounce': if (isActive) oy = -Math.sin(ws.p * Math.PI) * 0.2 * wpx; break;
        case 'wave': oy = Math.sin(t * 5 + wordNo * 0.9) * 0.08 * wpx; if (isActive) oy -= 0.12 * wpx; break;
        case 'shake': if (isActive) { oy = jitter(Math.floor(t * 30) + word.i) * 0.05 * wpx; scale = 1.08; } break;
        case 'typewriter':
          if (ws.s === 'future') show = false;
          else if (isActive) text = [...text].slice(0, Math.max(1, Math.ceil(ws.p * [...text].length))).join('');
          break;
        case 'karaoke': if (ws.s === 'past') color = st.active || st.color; break;
        case 'rainbow': if (ws.s !== 'future') color = `hsl(${(word.i * 53) % 360} 95% ${isActive ? 62 : 70}%)`; if (isActive) scale = 1.1; break;
        default:
      }
      if (wov?.color) color = wov.color;
      wordNo++;
      if (!show) continue;
      ctx.save();
      if (st.anim === 'rise' && ws.age < 0.25) ctx.globalAlpha *= easeOut(clamp01(ws.age / 0.25));
      ctx.translate(x + w / 2, cy + oy);
      if (scale !== 1) ctx.scale(scale, scale);
      if (word.font && word.font !== font) ctx.font = word.font;
      const lx = -w / 2;
      // word backgrounds
      if (wov?.hl) { ctx.fillStyle = wov.hl; rr(ctx, lx - 0.14 * wpx, -0.6 * wpx, w + 0.28 * wpx, 1.2 * wpx, 0.2 * wpx); ctx.fill(); }
      if (st.anim === 'highlight-box' && isActive && st.activeBg) {
        const e = 0.92 + 0.08 * easeOutBack(clamp01(ws.age / 0.12));
        ctx.save(); ctx.scale(e, e);
        ctx.fillStyle = st.activeBg; rr(ctx, lx - 0.16 * wpx, -0.62 * wpx, w + 0.32 * wpx, 1.24 * wpx, 0.22 * wpx); ctx.fill();
        ctx.restore();
      }
      if (bt === 'word') { ctx.fillStyle = bgCol; rr(ctx, lx - 0.12 * wpx, -0.6 * wpx, w + 0.24 * wpx, 1.2 * wpx, st.bg.radius * k); ctx.fill(); }
      if (bt === 'marker' && ws.s !== 'future') {
        const f = isActive ? easeOut(clamp01(ws.p * 1.6)) : 1;
        ctx.fillStyle = bgCol;
        const mw = (w + 0.2 * wpx) * f, mx = rtl ? lx + w + 0.1 * wpx - mw : lx - 0.1 * wpx;
        ctx.beginPath(); ctx.moveTo(mx + 0.06 * wpx, -0.42 * wpx); ctx.lineTo(mx + mw, -0.48 * wpx); ctx.lineTo(mx + mw - 0.04 * wpx, 0.5 * wpx); ctx.lineTo(mx, 0.44 * wpx); ctx.closePath(); ctx.fill();
      }
      if (st.anim === 'glitch' && isActive) {
        const j = jitter(Math.floor(t * 24) + word.i) * 0.06 * wpx;
        ctx.save(); ctx.globalCompositeOperation = 'lighter';
        ctx.fillStyle = 'rgba(255,0,80,0.85)'; ctx.fillText(text, lx + j - 0.03 * wpx, 0);
        ctx.fillStyle = 'rgba(0,220,255,0.85)'; ctx.fillText(text, lx - j + 0.03 * wpx, 0);
        ctx.restore();
      }
      if (st.anim === 'outline-fill' && ws.s === 'future') {
        ctx.lineWidth = Math.max(2, st.outline.w * k); ctx.strokeStyle = st.outline.color; ctx.strokeText(text, lx, 0);
      } else paintText(ctx, st, text, lx, color, k, isActive, wpx);
      if (st.anim === 'underline' && (isActive || ws.s === 'past')) {
        const f = isActive ? easeOut(clamp01(ws.p * 1.4)) : 1;
        ctx.fillStyle = st.accent; const uw = w * f;
        ctx.fillRect(rtl ? lx + w - uw : lx, 0.52 * wpx, uw, Math.max(3, 0.07 * wpx));
      }
      if (st.anim === 'karaoke' && isActive) { // sweep the fill across the word in reading direction
        ctx.save();
        ctx.beginPath();
        const fw = w * ws.p;
        ctx.rect(rtl ? lx + w - fw : lx - 4, -wpx, fw + 4, wpx * 2);
        ctx.clip();
        paintText(ctx, { ...st, grad: null, outline: { w: 0 }, shadow: { blur: 0, x: 0, y: 0 } }, text, lx, wov?.color || st.active || st.color, k, true, wpx);
        ctx.restore();
      }
      if (st.anim === 'typewriter' && isActive && Math.floor(t * 3) % 2 === 0) {
        const tw = ctx.measureText(text).width;
        ctx.fillStyle = st.color; ctx.fillRect(rtl ? lx + w - tw - 0.12 * wpx : lx + tw + 0.06 * wpx, -0.4 * wpx, 0.07 * wpx, 0.8 * wpx);
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

function paintText(ctx, st, text, x, color, k, active, px) {
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
  if (st.grad && color === st.color && px) { // two-colour fill, top → bottom
    const g2 = ctx.createLinearGradient(0, -0.5 * px, 0, 0.5 * px);
    g2.addColorStop(0, st.color); g2.addColorStop(1, st.grad);
    ctx.fillStyle = g2;
  } else ctx.fillStyle = color;
  ctx.fillText(text, x, 0);
}

/**
 * Where the caption blocks go at time t: [{ cap, L, st, lay, box:{x,y,w,h}, align, bx, bw, top }]. Shared by the
 * renderer and the on-canvas drag (hit test), so what you grab is exactly what is drawn.
 */
/** The caption clock at timeline t: the sync lane's per-project nudge + lead (meta.sync, ms), applied where the layer
 *  draws (sync/index.js) AND where the canvas drag hit-tests (drag.js), so a grab always finds the line on screen. */
export const captionClock = (project, t) => { const s = project && project.meta && project.meta.sync; return s ? t + ((s.nudgeMs || 0) + (s.leadMs || 0)) / 1000 : t; };

/** The captions an item draws: the main track, or a translated second track (item.capTrack = 'ar', translation lane). */
export function captionsFor(project, item) {
  const cap = project && project.captions;
  if (item && item.capTrack) return (cap && cap.tracks && cap.tracks[item.capTrack]) || null;
  return cap;
}

export function captionBlocks(ctx, project, t, item) {
  const cap = captionsFor(project, item);
  if (!cap || !cap.words || !cap.words.length || !cap.lines) return [];
  const st0 = resolveStyle(item && item.style ? item.style : cap.style);
  const mode = cap.soundTags || DEFAULT_TAG_MODE;
  const W = project.canvas?.w || 1080, H = project.canvas?.h || 1920;
  const k = W / 1080 * (H / W < 1 ? 0.75 : 1);   // landscape canvases: a touch smaller
  const safe = safeArea(W, H);
  const blocks = [];
  const L = lineAt(cap.lines, t);
  if (L) blocks.push({ cap, L, main: true });
  if (st0.bilingual && cap.alt && cap.alt.lines) {
    const A = lineAt(cap.alt.lines, t);
    if (A) blocks.push({ cap: cap.alt, L: A });
  }
  if (!blocks.length) return [];
  const lays = [];
  for (const b of blocks) {
    const st = b.main ? lineStyle(st0, b.L) : st0;
    const align = st.bg.type === 'bar' ? 'start' : 'center';
    const maxW = safe.w - st.bg.pad * k * 2 - (st.bg.type === 'bar' ? 10 * k : 0);
    const lay = layoutCue(ctx, b.cap, b.L, st, k, maxW, b.main ? mode : DEFAULT_TAG_MODE);
    if (lay) lays.push({ ...b, st, lay, align, maxW });
  }
  if (!lays.length) return [];
  if (st0.bilingual) lays.sort((a, b) => (a.lay.rtl ? 1 : 0) - (b.lay.rtl ? 1 : 0)); // English on top, Arabic below
  const gap = st0.size * k * 0.35;
  const total = lays.reduce((s, b) => s + b.lay.height, 0) + gap * (lays.length - 1);
  const padY = st0.bg.pad * k;
  // position: the main line's own (dragged) position, else the style's
  const mainL = lays.find((b) => b.main)?.L;
  const pos = (mainL && mainL.ov && mainL.ov.pos) || st0.pos || {};
  const py = pos.y ?? 0.72, pxv = pos.x;
  let top = safe.y + py * safe.h - total / 2;
  top = Math.max(safe.y + padY + (st0.emoji ? st0.size * k * 1.4 : 0), Math.min(top, safe.y + safe.h - total - padY));
  const out = [];
  for (const b of lays) {
    let bx = safe.x + (safe.w - b.maxW) / 2, bw = b.maxW;
    if (pxv != null) { // moved sideways: centre the block on pos.x, kept inside the safe area
      const half = b.lay.widest / 2 + b.st.bg.pad * k;
      const cx = Math.max(safe.x + half, Math.min(safe.x + safe.w - half, safe.x + pxv * safe.w));
      bx = cx - b.lay.widest / 2; bw = b.lay.widest;
    }
    const w = b.lay.widest;
    const x0 = b.align === 'start' ? (b.lay.rtl ? bx + bw - w : bx) : bx + (bw - w) / 2;
    const pad = b.st.bg.pad * k;
    out.push({ ...b, bx, bw, top, k, box: { x: x0 - pad, y: top - pad * 0.7, w: w + pad * 2, h: b.lay.height + pad * 1.4 } });
    top += b.lay.height + gap;
  }
  return out;
}

/**
 * Layer entry point (core/render.js): drawCaptions(ctx, item, t, project, env).
 * The caption item is a 'caption' item on the caption track spanning the captions; core translates
 * ctx to the item's box, so we undo that and lay out against the whole canvas and its safe area.
 * item.style (optional) overrides captions.style for that item.
 */
export function drawCaptions(ctx, item, t, project, env) {
  const cap = captionsFor(project, item);
  if (!cap || !cap.words || !cap.words.length || !cap.lines) return;
  ctx.save();
  if (env && env.box) ctx.translate(-env.box.x, -env.box.y);
  const list = captionBlocks(ctx, project, t, item);
  if (!list.length) {
    if ((cap.soundTags || DEFAULT_TAG_MODE) === 'icon' && inMusic(cap.music, t)) drawMusicIcon(ctx, project, t, item);
    ctx.restore();
    return;
  }
  for (const b of list) drawCue(ctx, b.cap, b.L, b.st, b.lay, t, b.k, b.bx, b.top, b.bw, b.align);
  ctx.restore();
}

/** A soft ♪ badge at the caption position while music plays with nobody talking (the "Show ♪ icon" setting). */
function drawMusicIcon(ctx, project, t, item) {
  const cap = project.captions, st = resolveStyle(item && item.style ? item.style : cap.style);
  const W = project.canvas?.w || 1080, H = project.canvas?.h || 1920, k = W / 1080 * (H / W < 1 ? 0.75 : 1);
  const safe = safeArea(W, H), pos = st.pos || {};
  const cx = safe.x + (pos.x ?? 0.5) * safe.w, cy = safe.y + (pos.y ?? 0.72) * safe.h;
  const r = st.size * k * 0.75, bob = Math.sin(t * 4) * 0.08 * r;
  ctx.save();
  ctx.globalAlpha *= 0.92;
  ctx.fillStyle = 'rgba(0,0,0,0.45)';
  ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fill();
  ctx.fillStyle = st.color || '#fff';
  ctx.font = `700 ${Math.round(r * 1.25)}px ${EMOJI_FALLBACK},"Noto Sans Symbols",sans-serif`;
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillText('♪', cx, cy + bob);
  ctx.restore();
}

/** Register with R1's core renderer. Call once at boot: registerCaptionLayer(coreRender). */
export function registerCaptionLayer(core) { core.registerLayer('caption', drawCaptions); }
