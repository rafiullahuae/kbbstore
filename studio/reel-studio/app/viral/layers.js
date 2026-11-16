// KBB Studio · viral · layers and fx, registered with core/render.js so the preview and the MP4 export draw them
// through the same code path. Items carry everything they draw (text, colours, fonts) as plain JSON.
//
//   item.type 'viral.hook'      animated hook text / text pop   style {text, accent, lang, colors, font, fontAr, size, anim:'pop'|'slam'|'type', exit}
//   item.type 'viral.cta'       call-to-action card             style {kind, title, sub, keyword, colors, font, fontAr, lang}
//   item.type 'viral.progress'  progress bar + step counter     style {chapters:[{t0,title}], mode:'bar'|'steps'|'both', total, colors, font}
//   item.type 'viral.vfx'       full-frame hook effect          style {kind:'whip'|'iris'|'leak'|'flash', color, dir}
//   item.type 'viral.placeholder'  "drop b-roll here" (preview only, never exported)
//   fx 'viral.zoom'   {t0, dur, from, to, attack, release, cx, cy}   snap zoom / punch-in / held jump-cut framing
//   fx 'viral.shake'  {t0, dur, amp}                                  impact shake
//   fx 'viral.vivid'  {t0, dur, b, c, s}                              colour lift over a window (first-frame fix)
import { registerLayer, registerFx, registerOverlay, roundRect } from '../core/render.js';
import { uiRects } from './lib/safezones.js';

const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
const easeOut = (x) => 1 - Math.pow(1 - clamp(x), 3);
const easeIn = (x) => Math.pow(clamp(x), 2);
const back = (x) => { x = clamp(x); const c = 1.9; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };
const isAr = (s) => /[؀-ۿ]/.test(s || '');
const font = (st, w, px, text) => `${w} ${Math.round(px)}px ${isAr(text) ? `"${st.fontAr || 'Cairo'}", ` : ''}"${st.font || 'Montserrat'}", "Inter", -apple-system, "Segoe UI", Roboto, sans-serif`;

// ── fx ──
registerFx('viral.zoom', (ctx, item, t, project, fx, env) => {
  const lt = t - fx.t0; if (lt < 0 || lt > fx.dur) return;
  const a = fx.attack ?? 0.12, r = fx.release ?? 0.3, from = fx.from ?? 1, to = fx.to ?? 1.15;
  let k = to;
  if (a > 0 && lt < a) k = from + (to - from) * easeOut(lt / a);
  else if (r > 0 && lt > fx.dur - r) k = to + (from - to) * easeIn((lt - (fx.dur - r)) / r);
  const { w, h } = env.box, cx = (fx.cx ?? 0.5) * w, cy = (fx.cy ?? 0.42) * h;
  ctx.translate(cx, cy); ctx.scale(k, k); ctx.translate(-cx, -cy);
});
registerFx('viral.shake', (ctx, item, t, project, fx, env) => {
  const lt = t - fx.t0; if (lt < 0 || lt > fx.dur) return;
  const decay = 1 - lt / fx.dur, amp = (fx.amp ?? 0.025) * env.w * decay;
  const dx = Math.sin(lt * 71) * amp, dy = Math.cos(lt * 53) * amp * 0.8, rot = Math.sin(lt * 37) * 0.012 * decay;
  const { w, h } = env.box;
  ctx.translate(w / 2 + dx, h / 2 + dy); ctx.rotate(rot); ctx.scale(1.06, 1.06); ctx.translate(-w / 2, -h / 2);
});
registerFx('viral.vivid', (ctx, item, t, project, fx) => {
  const lt = t - fx.t0; if (lt < 0 || lt > fx.dur) return '';
  const f = lt > fx.dur - 0.4 ? clamp((fx.dur - lt) / 0.4) : 1;
  return `brightness(${1 + ((fx.b ?? 1.25) - 1) * f}) contrast(${1 + ((fx.c ?? 1.15) - 1) * f}) saturate(${1 + ((fx.s ?? 1.35) - 1) * f})`;
});

// ── hook text: words pop in one after another, the accent word sits on a brand-coloured band ──
function layoutWords(ctx, words, maxW, px, st, text) {
  ctx.font = font(st, 900, px, text);
  const space = ctx.measureText(' ').width, rows = [[]]; let x = 0;
  for (const w of words) {
    const ww = ctx.measureText(w).width;
    if (x > 0 && x + space + ww > maxW) { rows.push([]); x = 0; }
    rows[rows.length - 1].push({ w, ww }); x += (x > 0 ? space : 0) + ww;
  }
  return { rows, space };
}
registerLayer('viral.hook', (ctx, item, t, project, env) => {
  const st = item.style || {}, c = st.colors || {}, { w, h } = env.box, lt = env.local, d = item.out - item.in;
  const text = String(st.text || ''); if (!text.trim()) return;
  const rtl = isAr(text), words = text.split(/\s+/).filter(Boolean);
  let px = st.size || Math.round(env.w * 0.085), lay;
  for (let k = 0; k < 14; k++) { lay = layoutWords(ctx, words, w * 0.92, px, st, text); if (lay.rows.length <= (st.maxRows || 3) && lay.rows.length * px * 1.22 <= h) break; px *= 0.9; }
  const exit = st.exit === false ? 1 : 1 - easeIn((lt - (d - 0.18)) / 0.18);
  if (exit <= 0.01) return;
  const accent = String(st.accent || '').toLowerCase(), lineH = px * 1.22, total = lay.rows.length * lineH;
  ctx.textBaseline = 'middle'; ctx.textAlign = 'left'; ctx.direction = 'ltr';
  ctx.font = font(st, 900, px, text);
  let wi = 0;
  const stagger = st.anim === 'slam' ? 0 : st.anim === 'type' ? 0.11 : 0.07;
  lay.rows.forEach((row, ri) => {
    const rw = row.reduce((s, x) => s + x.ww, 0) + lay.space * (row.length - 1);
    let x = (w - rw) / 2; const y = h / 2 - total / 2 + lineH * (ri + 0.5);
    const order = rtl ? row.slice().reverse() : row;
    for (const wd of order) {
      const idx = wi + row.indexOf(wd);   // pops in reading order (right to left for Arabic)
      const a0 = idx * stagger, p = st.anim === 'type' ? (lt >= a0 ? 1 : 0) : back((lt - a0) / (st.anim === 'slam' ? 0.16 : 0.24));
      if (p > 0.001) {
        const isAcc = accent && (wd.w.toLowerCase().includes(accent) || accent.includes(wd.w.toLowerCase().replace(/[.,!?؟]/g, '')));
        const s = st.anim === 'slam' ? 1 + 1.4 * (1 - clamp((lt - a0) / 0.16)) : p;
        ctx.save(); ctx.translate(x + wd.ww / 2, y); ctx.scale(s * (2 - exit), s * (2 - exit)); ctx.globalAlpha *= clamp(p * 1.5) * exit;
        if (isAcc) { ctx.fillStyle = c.primary || '#ff2d55'; ctx.beginPath(); roundRect(ctx, -wd.ww / 2 - px * 0.14, -px * 0.62, wd.ww + px * 0.28, px * 1.18, px * 0.2); ctx.fill(); }
        ctx.lineJoin = 'round'; ctx.lineWidth = px * 0.16; ctx.strokeStyle = 'rgba(0,0,0,0.85)';
        if (!isAcc) ctx.strokeText(wd.w, -wd.ww / 2, 0);
        ctx.shadowColor = 'rgba(0,0,0,0.45)'; ctx.shadowBlur = px * 0.25; ctx.shadowOffsetY = px * 0.06;
        ctx.fillStyle = isAcc ? (c.onPrimary || '#ffffff') : (c.text || '#ffffff'); ctx.fillText(wd.w, -wd.ww / 2, 0);
        ctx.restore();
      }
      x += wd.ww + lay.space;
    }
    wi += row.length;
  });
});

// ── CTA card ──
function icon(ctx, kind, cx, cy, r, col) {
  ctx.save(); ctx.translate(cx, cy); ctx.fillStyle = col; ctx.strokeStyle = col; ctx.lineWidth = r * 0.16; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
  const u = r / 10;
  ctx.beginPath();
  if (kind === 'comment') { roundRect(ctx, -7 * u, -6 * u, 14 * u, 10 * u, 3 * u); ctx.fill(); ctx.beginPath(); ctx.moveTo(-3 * u, 3 * u); ctx.lineTo(-5 * u, 8 * u); ctx.lineTo(1 * u, 3 * u); ctx.fill(); }
  else if (kind === 'save') { ctx.moveTo(-5 * u, -7 * u); ctx.lineTo(5 * u, -7 * u); ctx.lineTo(5 * u, 7 * u); ctx.lineTo(0, 3 * u); ctx.lineTo(-5 * u, 7 * u); ctx.closePath(); ctx.fill(); }
  else if (kind === 'share' || kind === 'dm') { ctx.moveTo(-7 * u, -1 * u); ctx.lineTo(7 * u, -7 * u); ctx.lineTo(2 * u, 7 * u); ctx.lineTo(0, 1 * u); ctx.closePath(); ctx.fill(); }
  else if (kind === 'link') { ctx.save(); ctx.rotate(-Math.PI / 4); roundRect(ctx, -8 * u, -2.6 * u, 9 * u, 5.2 * u, 2.6 * u); ctx.stroke(); ctx.beginPath(); roundRect(ctx, -1 * u, -2.6 * u, 9 * u, 5.2 * u, 2.6 * u); ctx.stroke(); ctx.restore(); }
  else { // call / whatsapp: a handset (whatsapp in a speech circle)
    if (kind === 'whatsapp') { ctx.arc(0, 0, 7.5 * u, 0.6, Math.PI * 2 + 0.3); ctx.stroke(); ctx.beginPath(); ctx.moveTo(-5.3 * u, 5.3 * u); ctx.lineTo(-7.5 * u, 7.5 * u); ctx.lineTo(-3 * u, 6.8 * u); ctx.fill(); ctx.beginPath(); }
    const k = kind === 'whatsapp' ? 0.55 : 1;
    ctx.save(); ctx.scale(k, k); ctx.rotate(-0.5);
    roundRect(ctx, -2.2 * u, -7 * u, 4.4 * u, 14 * u, 2 * u); ctx.fill();
    ctx.beginPath(); roundRect(ctx, -4 * u, -7.6 * u, 8 * u, 3.6 * u, 1.6 * u); ctx.fill(); ctx.beginPath(); roundRect(ctx, -4 * u, 4 * u, 8 * u, 3.6 * u, 1.6 * u); ctx.fill();
    ctx.restore();
  }
  ctx.restore();
}
registerLayer('viral.cta', (ctx, item, t, project, env) => {
  const st = item.style || {}, c = st.colors || {}, { w, h } = env.box, lt = env.local, d = item.out - item.in;
  const inP = back(lt / 0.42), outP = 1 - easeIn((lt - (d - 0.25)) / 0.25);
  if (inP <= 0.001 || outP <= 0.001) return;
  const title = String(st.title || ''), sub = String(st.sub || ''), rtl = isAr(title + sub);
  ctx.save();
  ctx.globalAlpha *= clamp(inP) * outP;
  ctx.translate(0, (1 - inP) * h * 0.6);
  const pulse = 1 + 0.025 * Math.sin(Math.max(0, lt - 0.5) * 6.5);
  ctx.translate(w / 2, h / 2); ctx.scale(pulse, pulse); ctx.translate(-w / 2, -h / 2);
  ctx.shadowColor = 'rgba(0,0,0,0.35)'; ctx.shadowBlur = h * 0.18; ctx.shadowOffsetY = h * 0.06;
  ctx.fillStyle = c.card || '#ffffff'; ctx.beginPath(); roundRect(ctx, 0, 0, w, h, h * 0.28); ctx.fill();
  ctx.shadowColor = 'transparent';
  const r = h * 0.3, ix = rtl ? w - h * 0.5 : h * 0.5;
  ctx.fillStyle = c.primary || '#e8508b'; ctx.beginPath(); ctx.arc(ix, h / 2, r, 0, Math.PI * 2); ctx.fill();
  icon(ctx, st.kind || 'comment', ix, h / 2, r * 0.95, c.onPrimary || '#ffffff');
  const tx = rtl ? w - h * 0.95 : h * 0.95, maxW = w - h * 1.25;
  ctx.textAlign = rtl ? 'right' : 'left'; ctx.direction = rtl ? 'rtl' : 'ltr'; ctx.textBaseline = 'middle';
  ctx.fillStyle = c.ink || '#14141a';
  let tpx = h * 0.31; ctx.font = font(st, 900, tpx, title); while (ctx.measureText(title).width > maxW && tpx > h * 0.12) { tpx *= 0.92; ctx.font = font(st, 900, tpx, title); }
  const kw = String(st.keyword || '');
  if (kw && title.includes(kw)) { // the keyword sits on a chip
    const pre = title.slice(0, title.indexOf(kw)), pw = ctx.measureText(pre).width, kwW = ctx.measureText(kw).width, y0 = sub ? h * 0.37 : h / 2;
    const kx = rtl ? tx - ctx.measureText(title).width + (ctx.measureText(title).width - pw - kwW) : tx + pw;
    ctx.fillStyle = c.accent || '#ffd166'; ctx.beginPath(); roundRect(ctx, kx - tpx * 0.12, y0 - tpx * 0.62, kwW + tpx * 0.24, tpx * 1.2, tpx * 0.25); ctx.fill();
    ctx.fillStyle = c.ink || '#14141a';
  }
  ctx.fillText(title, tx, sub ? h * 0.37 : h / 2, maxW);
  if (sub) { ctx.globalAlpha *= 0.75; ctx.font = font(st, 600, h * 0.19, sub); ctx.fillText(sub, tx, h * 0.68, maxW); }
  ctx.restore();
  // a bouncing arrow towards the app's buttons (comment / share / save live on the right rail)
  if (['comment', 'share', 'save', 'dm'].includes(st.kind) && lt > 0.4) {
    const b = Math.sin((lt - 0.4) * 9) * h * 0.08, ax = w + h * 0.12 + b, ay = h * 0.5;
    ctx.save(); ctx.globalAlpha *= clamp((lt - 0.4) / 0.3) * outP; ctx.fillStyle = c.accent || '#ffd166';
    ctx.beginPath(); ctx.moveTo(ax, ay - h * 0.16); ctx.lineTo(ax + h * 0.22, ay); ctx.lineTo(ax, ay + h * 0.16); ctx.closePath(); ctx.fill(); ctx.restore();
  }
});

// ── progress bar + step counter ──
registerLayer('viral.progress', (ctx, item, t, project, env) => {
  const st = item.style || {}, c = st.colors || {}, { w, h } = env.box, lt = env.local;
  const T = st.total || (item.out - item.in), ch = (st.chapters || []).slice().sort((a, b) => a.t0 - b.t0);
  const bh = Math.max(6, env.w * 0.011), u = env.w / 1080;
  if (st.mode !== 'steps') {
    ctx.fillStyle = 'rgba(255,255,255,0.28)'; ctx.beginPath(); roundRect(ctx, 0, 0, w, bh, bh / 2); ctx.fill();
    ctx.fillStyle = c.primary || '#e8508b'; ctx.beginPath(); roundRect(ctx, 0, 0, Math.max(bh, w * clamp(lt / T)), bh, bh / 2); ctx.fill();
    ctx.fillStyle = 'rgba(0,0,0,0.55)';
    for (const k of ch) if (k.t0 > 0.05) ctx.fillRect(w * clamp(k.t0 / T) - 2 * u, 0, 4 * u, bh);
  }
  if (st.mode === 'bar' || !ch.length) return;
  let cur = -1; for (let i = 0; i < ch.length; i++) if (lt >= ch[i].t0 - 1e-3) cur = i;
  if (cur < 0) return;
  const since = lt - ch[cur].t0, pop = back(since / 0.3);
  const label = `${st.label || 'STEP'} ${cur + 1}/${ch.length}`, title = ch[cur].title || '';
  const y = bh + 18 * u, px = 44 * u;
  ctx.save(); ctx.translate(0, y);
  ctx.font = font(st, 900, px, label); const lw = ctx.measureText(label).width + px;
  ctx.save(); ctx.translate(lw / 2, px * 0.7); ctx.scale(pop, pop); ctx.translate(-lw / 2, -px * 0.7);
  ctx.fillStyle = c.accent || '#ffd166'; ctx.beginPath(); roundRect(ctx, 0, 0, lw, px * 1.4, px * 0.7); ctx.fill();
  ctx.fillStyle = c.ink || '#14141a'; ctx.textBaseline = 'middle'; ctx.textAlign = 'center'; ctx.fillText(label, lw / 2, px * 0.72);
  ctx.restore();
  if (title) {
    ctx.globalAlpha *= clamp(since / 0.35);
    const tpx = 46 * u; ctx.font = font(st, 800, tpx, title); ctx.textBaseline = 'middle'; ctx.textAlign = isAr(title) ? 'right' : 'left'; ctx.direction = isAr(title) ? 'rtl' : 'ltr';
    const tw = Math.min(w - lw - px, ctx.measureText(title).width + tpx * 0.8), x0 = lw + px * 0.4;
    ctx.fillStyle = 'rgba(0,0,0,0.55)'; ctx.beginPath(); roundRect(ctx, x0, 0, tw, px * 1.4, px * 0.5); ctx.fill();
    ctx.fillStyle = c.text || '#ffffff'; ctx.fillText(title, isAr(title) ? x0 + tw - tpx * 0.4 : x0 + tpx * 0.4, px * 0.72, tw - tpx * 0.8);
  }
  ctx.restore();
});

// ── full-frame hook effects ──
registerLayer('viral.vfx', (ctx, item, t, project, env) => {
  const st = item.style || {}, W = env.w, H = env.h, d = Math.max(0.01, item.out - item.in), p = clamp(env.local / d);
  const cv = ctx.canvas, dpr = env.scale || 1;
  switch (st.kind) {
    case 'flash': ctx.fillStyle = st.color || '#ffffff'; ctx.globalAlpha *= Math.pow(1 - p, 1.8); ctx.fillRect(0, 0, W, H); break;
    case 'whip': { // the frame streaks in from the side and settles
      const dir = st.dir || 1, k = 1 - easeOut(p);
      ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
      for (let i = 1; i <= 7; i++) { ctx.globalAlpha = 0.2 * k; ctx.drawImage(cv, -dir * k * W * 0.09 * i * dpr, 0); }
      ctx.restore(); break;
    }
    case 'iris': { // reveal mask: a circle opens from the centre
      const R = Math.hypot(W, H) / 2 * easeOut(p * 1.05), cx = W * (st.cx ?? 0.5), cy = H * (st.cy ?? 0.45);
      ctx.fillStyle = '#000'; ctx.beginPath(); ctx.rect(0, 0, W, H); ctx.arc(cx, cy, Math.max(0.1, R), 0, Math.PI * 2, true); ctx.fill('evenodd');
      ctx.strokeStyle = st.color || '#ffffff'; ctx.lineWidth = W * 0.012; ctx.globalAlpha *= 1 - p; ctx.beginPath(); ctx.arc(cx, cy, Math.max(0.1, R), 0, Math.PI * 2); ctx.stroke(); break;
    }
    case 'leak': {
      ctx.save(); ctx.fillStyle = '#ffffff'; ctx.globalAlpha *= Math.pow(1 - clamp(p * 2.2), 2); ctx.fillRect(0, 0, W, H); ctx.restore();
      const g = ctx.createRadialGradient(W * (0.05 + 0.9 * p), H * 0.25, 0, W * (0.05 + 0.9 * p), H * 0.25, W * 0.95);
      g.addColorStop(0, 'rgba(255,196,120,0.95)'); g.addColorStop(0.4, 'rgba(255,92,150,0.5)'); g.addColorStop(1, 'rgba(255,92,150,0)');
      ctx.globalCompositeOperation = 'screen'; ctx.globalAlpha *= Math.sin(Math.PI * p); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H); break;
    }
    default: break;
  }
});

registerLayer('viral.placeholder', (ctx, item, t, project, env) => {
  if (!env.preview) return;   // a note to the editor, never in the export
  const { w, h } = env.box, st = item.style || {};
  ctx.setLineDash([18, 12]); ctx.strokeStyle = '#ffd166'; ctx.lineWidth = 6; ctx.strokeRect(3, 3, w - 6, h - 6); ctx.setLineDash([]);
  ctx.fillStyle = 'rgba(0,0,0,0.45)'; ctx.fillRect(0, 0, w, h);
  ctx.fillStyle = '#ffd166'; ctx.font = `800 ${Math.round(h * 0.12)}px system-ui, sans-serif`; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillText('Drop B-roll here', w / 2, h * 0.42); ctx.font = `600 ${Math.round(h * 0.08)}px system-ui, sans-serif`; ctx.fillText(st.hint || '', w / 2, h * 0.6, w * 0.9);
});

// ── the platform UI drawn over the preview while the safe-zone check is on (never in the export) ──
export const zoneView = { platform: null };
registerOverlay('viral.safezones', (ctx, t, project, env) => {
  if (!env.preview || !zoneView.platform) return;
  for (const r of uiRects(project.canvas, zoneView.platform)) {
    ctx.fillStyle = 'rgba(255,60,90,0.22)'; ctx.fillRect(r.x, r.y, r.w, r.h);
    ctx.strokeStyle = 'rgba(255,60,90,0.9)'; ctx.lineWidth = 3; ctx.setLineDash([12, 8]); ctx.strokeRect(r.x, r.y, r.w, r.h); ctx.setLineDash([]);
  }
}, 50);

export const LAYER_TYPES = ['viral.hook', 'viral.cta', 'viral.progress', 'viral.vfx', 'viral.placeholder'];
export const FX_TYPES = ['viral.zoom', 'viral.shake', 'viral.vivid'];
