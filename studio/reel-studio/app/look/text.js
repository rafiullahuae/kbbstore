// KBB Studio · look · animated text presets, branded per company, drawn by core/render.js (preview = export).
//
//   item.type 'look.text' (text track), item.text = main text, style = { preset, sub, value, prefix, suffix, items:[..],
//     colors:{primary, accent, text, bg}, font, fontAr, size }
//
// Arabic is shaped by the browser's own text engine: Arabic text is only ever drawn as WHOLE words (never cut into
// letters), revealed with clips and word timing, with direction 'rtl' — so joining forms stay correct while it animates.
import { registerLayer, registerPrepare, roundRect } from '../core/render.js';
import { createItem } from '../core/project.js';

export const PRESETS = {
  typewriter: { label: 'Typewriter', text: 'Your glow starts here', box: [0.08, 0.42, 0.84, 0.14], dur: 3 },
  kinetic: { label: 'Kinetic word stack', text: 'Glass skin in 7 days', box: [0.1, 0.3, 0.8, 0.36], dur: 3.2 },
  highlight: { label: 'Highlight sweep', text: 'The serum everyone is talking about', box: [0.08, 0.4, 0.84, 0.2], dur: 3 },
  price: { label: 'Price slam', text: 'AED 99', sub: 'AED 149', box: [0.12, 0.36, 0.76, 0.22], dur: 2.6 },
  countdown: { label: 'Countdown', text: 'Sale ends in', value: 3, box: [0.25, 0.32, 0.5, 0.3], dur: 3 },
  checklist: { label: 'Checklist ticks', text: 'What you need', items: ['Passport', 'Bank statement', 'Offer letter'], box: [0.1, 0.3, 0.8, 0.36], dur: 3.6 },
  room: { label: 'Room name (minimal)', text: 'Reception', box: [0.05, 0.84, 0.6, 0.07], dur: 3 },
  lowerthird: { label: 'Lower third', text: 'Dr. Sara Khan', sub: 'Skin specialist', box: [0.06, 0.72, 0.72, 0.1], dur: 4 },
  counter: { label: 'Number counter', text: 'happy students', value: 10000, suffix: '+', box: [0.1, 0.36, 0.8, 0.22], dur: 2.6 },
  'rtl-kinetic': { label: 'Arabic kinetic (RTL)', text: 'بشرة نضرة في سبعة أيام', box: [0.08, 0.38, 0.84, 0.18], dur: 3, ar: true },
  'rtl-reveal': { label: 'Arabic reveal (right → left)', text: 'احجز استشارتك المجانية اليوم', box: [0.06, 0.42, 0.88, 0.12], dur: 3, ar: true },
  bilingual: { label: 'Bilingual stack EN / AR', text: 'Study in Germany', sub: 'ادرس في ألمانيا', box: [0.08, 0.36, 0.84, 0.22], dur: 3.4, ar: true },
};

const isAr = (s) => /[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]/.test(s || '');
const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
const easeOut = (x) => 1 - Math.pow(1 - clamp(x), 3);
const back = (x) => { x = clamp(x); const c = 1.70158; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };
const font = (s, w, px, text) => `${w} ${Math.round(px)}px ${isAr(text) ? `"${s.fontAr || 'Cairo'}", "Geeza Pro", ` : ''}"${s.font || 'Inter'}", "Cairo", -apple-system, "Segoe UI", sans-serif`;
const C = (s) => Object.assign({ primary: '#C6395F', accent: '#ffd166', text: '#ffffff', bg: 'rgba(0,0,0,0.55)' }, s.colors);

/** exit: scale/fade over the last 0.25 s */
function exitK(local, dur) { const d = 0.25; return local > dur - d ? 1 - easeOut((local - (dur - d)) / d) : 1; }

function words(text) { return String(text || '').split(/\s+/).filter(Boolean); }

const DRAW = {
  typewriter(ctx, s, w, h, lt, dur, text) {
    const c = C(s), size = s.size || h * 0.42, ar = isAr(text);
    ctx.font = font(s, 800, size, text); ctx.textBaseline = 'middle';
    const ws = words(text), shown = clamp(lt / Math.max(0.5, dur * 0.6));
    let txt;
    if (ar) txt = ws.slice(0, Math.ceil(shown * ws.length)).join(' ');                     // whole words only (shaping)
    else { const n = Math.round(shown * text.length); txt = text.slice(0, n); }
    const full = ctx.measureText(text).width, pad = size * 0.4, bw = Math.min(w, full + pad * 2);
    ctx.fillStyle = c.bg; ctx.beginPath(); roundRect(ctx, (w - bw) / 2, 0, bw, h, h * 0.22); ctx.fill();
    ctx.direction = ar ? 'rtl' : 'ltr'; ctx.textAlign = ar ? 'right' : 'left';
    const x0 = ar ? (w + full) / 2 : (w - full) / 2;
    ctx.fillStyle = c.text; ctx.fillText(txt, x0, h / 2, w - pad);
    const tw = ctx.measureText(txt).width, caretX = ar ? x0 - tw - size * 0.08 : x0 + tw + size * 0.06;
    if (Math.floor(lt * 2.4) % 2 === 0 || shown < 1) { ctx.fillStyle = c.accent; ctx.fillRect(caretX, h / 2 - size * 0.45, size * 0.07, size * 0.9); }
  },
  kinetic(ctx, s, w, h, lt, dur, text) {
    const c = C(s), ws = words(text), lines = [];
    for (let i = 0; i < ws.length; i += 2) lines.push(ws.slice(i, i + 2));
    const lh = h / Math.max(1, lines.length);
    let k = 0;
    lines.forEach((ln, li) => {
      const big = li % 2 === 0, size = Math.min(lh * (big ? 0.86 : 0.7), (s.size || 999));
      ctx.font = font(s, 900, size, ln.join(' ')); ctx.textBaseline = 'middle'; ctx.textAlign = 'center';
      const lw = ctx.measureText(ln.join(' ')).width, sc = Math.min(1, (w * 0.98) / lw);
      let x = w / 2 - (lw * sc) / 2;
      for (const wd of ln) {
        const t0 = k * 0.16, f = back((lt - t0) / 0.3), ww = ctx.measureText(wd + ' ').width * sc;
        if (lt >= t0) {
          ctx.save(); ctx.translate(x + (ww - ctx.measureText(' ').width * sc) / 2, lh * li + lh / 2); ctx.scale(f * sc, f * sc); ctx.rotate((1 - clamp((lt - t0) / 0.3)) * (k % 2 ? 0.2 : -0.2));
          ctx.lineJoin = 'round'; ctx.lineWidth = size * 0.12; ctx.strokeStyle = 'rgba(0,0,0,0.55)'; ctx.strokeText(wd, 0, 0);
          ctx.fillStyle = big ? c.text : c.accent; ctx.fillText(wd, 0, 0); ctx.restore();
        }
        x += ww; k++;
      }
    });
  },
  highlight(ctx, s, w, h, lt, dur, text) {
    const c = C(s), size = s.size || h * 0.34, ar = isAr(text);
    ctx.font = font(s, 800, size, text); ctx.textBaseline = 'middle'; ctx.direction = ar ? 'rtl' : 'ltr'; ctx.textAlign = 'center';
    const ws = words(text), lines = [], maxW = w * 0.96; let cur = '';
    for (const wd of ws) { const tl = cur ? cur + ' ' + wd : wd; if (ctx.measureText(tl).width > maxW && cur) { lines.push(cur); cur = wd; } else cur = tl; }
    if (cur) lines.push(cur);
    const lh = size * 1.25, y0 = h / 2 - (lines.length * lh) / 2 + lh / 2;
    lines.forEach((ln, i) => {
      const lw = ctx.measureText(ln).width, f = easeOut((lt - 0.2 - i * 0.25) / 0.45);
      ctx.fillStyle = c.accent; const hw = lw * f + size * 0.3 * f;
      if (ar) ctx.fillRect(w / 2 + lw / 2 + size * 0.15 - hw, y0 + i * lh - size * 0.42, hw, size * 0.84);
      else ctx.fillRect(w / 2 - lw / 2 - size * 0.15, y0 + i * lh - size * 0.42, hw, size * 0.84);
      ctx.fillStyle = f > 0.5 ? '#111' : c.text; ctx.fillText(ln, w / 2, y0 + i * lh);
    });
  },
  price(ctx, s, w, h, lt, dur, text) {
    const c = C(s), size = s.size || h * 0.62;
    const f = lt < 0.28 ? 2.6 - 1.6 * easeOut(lt / 0.28) : 1, shake = lt > 0.28 && lt < 0.5 ? Math.sin(lt * 90) * h * 0.03 * (1 - (lt - 0.28) / 0.22) : 0;
    // burst
    ctx.save(); ctx.translate(w / 2, h * 0.58); ctx.rotate(lt * 0.4); ctx.fillStyle = c.primary; ctx.globalAlpha *= clamp(lt / 0.3);
    ctx.beginPath(); const R1 = h * 0.85, R0 = h * 0.62; for (let i = 0; i < 32; i++) { const a = (i * Math.PI) / 16, r = i % 2 ? R0 : R1; ctx.lineTo(Math.cos(a) * r * 1.4, Math.sin(a) * r * 0.75); } ctx.closePath(); ctx.fill(); ctx.restore();
    if (s.sub) { ctx.font = font(s, 700, size * 0.36, s.sub); ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = c.text; ctx.globalAlpha *= 0.85; ctx.fillText(s.sub, w / 2, h * 0.12); const sw = ctx.measureText(s.sub).width; ctx.strokeStyle = c.accent; ctx.lineWidth = size * 0.05; ctx.beginPath(); ctx.moveTo(w / 2 - sw / 2 - 6, h * 0.16); ctx.lineTo(w / 2 + sw / 2 + 6, h * 0.08); ctx.stroke(); ctx.globalAlpha /= 0.85; }
    ctx.save(); ctx.translate(w / 2 + shake, h * 0.6); ctx.scale(f, f);
    ctx.font = font(s, 900, size, text); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.lineJoin = 'round'; ctx.lineWidth = size * 0.1; ctx.strokeStyle = '#111'; ctx.strokeText(text, 0, 0, w * 0.95);
    ctx.fillStyle = c.accent; ctx.fillText(text, 0, 0, w * 0.95); ctx.restore();
    if (lt > 0.26 && lt < 0.4) { ctx.fillStyle = '#fff'; ctx.globalAlpha *= 0.5 * (1 - (lt - 0.26) / 0.14); ctx.fillRect(-w, -h, w * 3, h * 3); }
  },
  countdown(ctx, s, w, h, lt, dur, text) {
    const c = C(s), n = Math.max(1, Math.round(s.value || 3)), per = dur / n, i = Math.min(n - 1, Math.floor(lt / per)), u = (lt - i * per) / per;
    const r = Math.min(w, h) * 0.38, cx = w / 2, cy = h * 0.58;
    if (text) { ctx.font = font(s, 800, h * 0.12, text); ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = c.text; ctx.fillText(text, cx, h * 0.08, w); }
    ctx.fillStyle = c.bg; ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = c.accent; ctx.lineWidth = r * 0.12; ctx.lineCap = 'round'; ctx.beginPath(); ctx.arc(cx, cy, r * 0.92, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * (1 - u)); ctx.stroke();
    const k = 1.35 - 0.35 * easeOut(u / 0.3);
    ctx.save(); ctx.translate(cx, cy); ctx.scale(k, k); ctx.font = font(s, 900, r * 1.1, '0'); ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillStyle = c.text;
    ctx.fillText(String(n - i), 0, r * 0.05); ctx.restore();
  },
  checklist(ctx, s, w, h, lt, dur, text) {
    const c = C(s), list = (s.items && s.items.length ? s.items : words(text)), rows = list.length + (text ? 1 : 0), lh = h / Math.max(1, rows), size = Math.min(lh * 0.55, s.size || 999);
    let y = lh / 2; const ar = isAr(list.join(' ') + text);
    ctx.textBaseline = 'middle'; ctx.direction = ar ? 'rtl' : 'ltr';
    if (text) { ctx.font = font(s, 900, size * 1.1, text); ctx.textAlign = ar ? 'right' : 'left'; ctx.fillStyle = c.accent; ctx.fillText(text, ar ? w : 0, y, w); y += lh; }
    list.forEach((it, i) => {
      const t0 = 0.3 + i * 0.55, f = clamp((lt - t0) / 0.25), tick = clamp((lt - t0 - 0.2) / 0.25);
      if (f <= 0) return;
      ctx.globalAlpha *= f; const bx = ar ? w - size * 1.1 : 0, sz = size * 1.0;
      ctx.fillStyle = c.bg; ctx.beginPath(); roundRect(ctx, bx, y - sz / 2, sz, sz, sz * 0.2); ctx.fill();
      if (tick > 0) { ctx.strokeStyle = c.accent; ctx.lineWidth = sz * 0.16; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.beginPath();
        const p1 = [bx + sz * 0.2, y], p2 = [bx + sz * 0.42, y + sz * 0.22], p3 = [bx + sz * 0.82, y - sz * 0.26];
        ctx.moveTo(...p1); const a = Math.min(1, tick * 2); ctx.lineTo(p1[0] + (p2[0] - p1[0]) * a, p1[1] + (p2[1] - p1[1]) * a);
        if (tick > 0.5) { const b = (tick - 0.5) * 2; ctx.lineTo(p2[0] + (p3[0] - p2[0]) * b, p2[1] + (p3[1] - p2[1]) * b); } ctx.stroke(); }
      ctx.font = font(s, 700, size, it); ctx.textAlign = ar ? 'right' : 'left'; ctx.fillStyle = c.text;
      ctx.fillText(it, ar ? w - size * 1.5 - (1 - f) * size : size * 1.5 + (1 - f) * size, y, w - size * 1.6);
      ctx.globalAlpha /= f; y += lh;
    });
  },
  // the Office tour's room name: small, white, spaced capitals, no box; it drifts in and fades (exitK fades it out)
  room(ctx, s, w, h, lt, dur, text) {
    const f = easeOut(lt / 0.7), ar = isAr(text), size = h * 0.42;
    ctx.globalAlpha *= f; ctx.textBaseline = 'middle'; ctx.direction = ar ? 'rtl' : 'ltr'; ctx.textAlign = ar ? 'right' : 'left';
    ctx.font = font(s, 500, size, text); if ('letterSpacing' in ctx && !ar) ctx.letterSpacing = `${(size * 0.22).toFixed(1)}px`;
    ctx.shadowColor = 'rgba(0,0,0,0.35)'; ctx.shadowBlur = size * 0.6; ctx.fillStyle = s.colors?.text || '#ffffff';
    const label = ar ? text : text.toUpperCase();
    ctx.fillText(label, ar ? w - (1 - f) * size : (1 - f) * size, h / 2, w);
    if ('letterSpacing' in ctx) ctx.letterSpacing = '0px'; ctx.shadowBlur = 0;
  },
  lowerthird(ctx, s, w, h, lt, dur, text) {
    const c = C(s), f = easeOut(lt / 0.45), bw = w * f;
    ctx.fillStyle = c.primary; ctx.beginPath(); roundRect(ctx, 0, 0, bw, h * 0.62, h * 0.08); ctx.fill();
    ctx.fillStyle = c.accent; ctx.fillRect(0, 0, Math.max(0, bw) * 0.02 + 4, h * 0.62);
    ctx.fillStyle = 'rgba(0,0,0,0.6)'; ctx.fillRect(0, h * 0.64, bw * 0.78, h * 0.36);
    ctx.save(); ctx.beginPath(); ctx.rect(0, 0, bw, h); ctx.clip();
    const ar = isAr(text); ctx.direction = ar ? 'rtl' : 'ltr'; ctx.textAlign = ar ? 'right' : 'left'; ctx.textBaseline = 'middle';
    ctx.font = font(s, 800, h * 0.36, text); ctx.fillStyle = c.text; ctx.fillText(text, ar ? bw - h * 0.25 : h * 0.25, h * 0.31, w - h * 0.4);
    if (s.sub) { ctx.font = font(s, 600, h * 0.22, s.sub); ctx.fillStyle = c.accent; ctx.direction = isAr(s.sub) ? 'rtl' : 'ltr'; ctx.textAlign = isAr(s.sub) ? 'right' : 'left'; ctx.fillText(s.sub, isAr(s.sub) ? bw * 0.78 - h * 0.25 : h * 0.25, h * 0.82, w * 0.74); }
    ctx.restore();
  },
  counter(ctx, s, w, h, lt, dur, text) {
    const c = C(s), v = Math.round((s.value || 100) * easeOut(lt / Math.max(0.4, dur * 0.65)));
    const num = (s.prefix || '') + v.toLocaleString('en-US') + (lt >= dur * 0.65 ? s.suffix || '' : '');
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.font = font(s, 900, s.size || h * 0.56, num); ctx.lineJoin = 'round'; ctx.lineWidth = h * 0.05; ctx.strokeStyle = 'rgba(0,0,0,0.5)'; ctx.strokeText(num, w / 2, h * 0.38, w);
    ctx.fillStyle = c.accent; ctx.fillText(num, w / 2, h * 0.38, w);
    if (text) { ctx.font = font(s, 700, h * 0.2, text); ctx.direction = isAr(text) ? 'rtl' : 'ltr'; ctx.fillStyle = c.text; ctx.fillText(text, w / 2, h * 0.85, w); }
  },
  'rtl-kinetic'(ctx, s, w, h, lt, dur, text) {
    const c = C(s), size = s.size || h * 0.5, ws = words(text);
    ctx.font = font(s, 800, size, text); ctx.textBaseline = 'middle'; ctx.direction = 'rtl'; ctx.textAlign = 'right';
    const sp = ctx.measureText(' ').width, widths = ws.map((x) => ctx.measureText(x).width), total = widths.reduce((a, b) => a + b, 0) + sp * (ws.length - 1);
    const sc = Math.min(1, (w * 0.96) / total);
    ctx.save(); ctx.translate(w / 2, h / 2); ctx.scale(sc, sc);
    let x = total / 2;                          // the first word stands at the RIGHT
    ws.forEach((wd, i) => {
      const t0 = i * 0.18, f = clamp((lt - t0) / 0.32);
      if (f > 0) { ctx.globalAlpha *= f; ctx.fillStyle = i % 2 ? c.accent : c.text; ctx.lineJoin = 'round'; ctx.lineWidth = size * 0.1; ctx.strokeStyle = 'rgba(0,0,0,0.5)';
        const dx = (1 - easeOut(f)) * size * 0.9; ctx.strokeText(wd, x + dx, 0); ctx.fillText(wd, x + dx, 0); ctx.globalAlpha /= f; }
      x -= widths[i] + sp;
    });
    ctx.restore();
  },
  'rtl-reveal'(ctx, s, w, h, lt, dur, text) {
    const c = C(s), size = s.size || h * 0.5, f = easeOut(lt / Math.max(0.4, dur * 0.45));
    ctx.fillStyle = c.primary; ctx.beginPath(); roundRect(ctx, w * (1 - f), 0, w * f, h, h * 0.2); ctx.fill();
    ctx.save(); ctx.beginPath(); ctx.rect(w * (1 - f), 0, w * f, h); ctx.clip();
    ctx.font = font(s, 800, size, text); ctx.textBaseline = 'middle'; ctx.direction = 'rtl'; ctx.textAlign = 'right';
    ctx.fillStyle = c.text; ctx.fillText(text, w - h * 0.3, h / 2, w - h * 0.6); ctx.restore();
    const ex = w * (1 - f); const g = ctx.createLinearGradient(ex, 0, ex + h * 0.4, 0); g.addColorStop(0, c.accent); g.addColorStop(1, 'rgba(255,255,255,0)');
    if (f < 1) { ctx.fillStyle = g; ctx.fillRect(ex, 0, h * 0.4, h); }
  },
  bilingual(ctx, s, w, h, lt, dur, text) {
    const c = C(s), fa = easeOut(lt / 0.45), fb = easeOut((lt - 0.2) / 0.45), dl = easeOut((lt - 0.1) / 0.5);
    ctx.textBaseline = 'middle'; ctx.textAlign = 'center';
    ctx.font = font(s, 800, s.size || h * 0.3, text); ctx.direction = 'ltr'; ctx.fillStyle = c.text; ctx.globalAlpha *= fa || 0.0001;
    ctx.fillText(text, w / 2 - (1 - fa) * w * 0.3, h * 0.27, w); ctx.globalAlpha /= fa || 0.0001;
    ctx.fillStyle = c.accent; ctx.fillRect(w / 2 - (w * 0.4 * dl), h * 0.5 - h * 0.015, w * 0.8 * dl, h * 0.03);
    if (s.sub) { ctx.font = font(s, 800, (s.size || h * 0.3) * 0.95, s.sub); ctx.direction = 'rtl'; ctx.fillStyle = c.text; ctx.globalAlpha *= fb || 0.0001;
      ctx.fillText(s.sub, w / 2 + (1 - fb) * w * 0.3, h * 0.75, w); ctx.globalAlpha /= fb || 0.0001; }
  },
};

registerLayer('look.text', (ctx, item, t, project, env) => {
  const s = item.style || {}, fn = DRAW[s.preset] || DRAW.typewriter;
  const dur = Math.max(0.1, item.out - item.in), lt = env.local, { w, h } = env.box;
  const k = exitK(lt, dur);
  if (k <= 0.001) return;
  ctx.globalAlpha *= k;
  if (k < 1) { ctx.translate(w / 2, h / 2); ctx.scale(0.9 + 0.1 * k, 0.9 + 0.1 * k); ctx.translate(-w / 2, -h / 2); }
  ctx.direction = 'ltr';
  fn(ctx, s, w, h, lt, dur, String(item.text ?? ''));
  ctx.direction = 'ltr';
});

// the Arabic and Latin caption fonts (Cairo, Inter …) are vendored by the captions module: load them before any export
registerPrepare('look.text-fonts', async (project) => {
  if (!project.tracks.some((tr) => tr.items.some((i) => i.type === 'look.text'))) return;
  try { const F = await import('../captions/fonts.js'); await F.loadCaptionFonts(); } catch { /* the system fonts draw instead */ }
});

/** a new animated-text item for preset `id`, branded from the workspace kit, at time t0 */
export function textItem(id, canvas, ws = {}, t0 = 0, over = {}) {
  const P = PRESETS[id] || PRESETS.typewriter, [bx, by, bw, bh] = P.box, col = ws.colors || {};
  const heading = ws.fonts?.heading && ws.fonts.heading !== 'system-ui' ? ws.fonts.heading : 'Inter';
  return createItem('look.text', Object.assign({
    t0, in: 0, out: P.dur, x: Math.round(canvas.w * bx), y: Math.round(canvas.h * by), w: Math.round(canvas.w * bw), h: Math.round(canvas.h * bh),
    text: P.text, name: 'Text · ' + P.label,
    style: { preset: id, sub: P.sub, value: P.value, suffix: P.suffix, items: P.items ? P.items.slice() : undefined, font: heading, fontAr: 'Cairo',
      colors: { primary: col.primary || '#C6395F', accent: col.accent || '#ffd166', text: col.text || '#ffffff', bg: 'rgba(0,0,0,0.55)' } },
  }, over));
}
