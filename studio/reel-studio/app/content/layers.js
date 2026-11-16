// KBB Studio · content · what the content features draw, registered with the core renderer so the preview and the
// export paint the same pixels (core/render.js).
//   fx    content.pop    {dur}          scale-in entrance about the item's centre (0.55 → 1.12 → 1)
//   fx    content.wipe   {t0, dur, dir} reveal the item left→right (or 'up') between item-local t0 and t0+dur
//   layer content.slot                  a format's empty slot: brand-tinted panel, what to film, how long
import { registerFx, registerLayer, registerPrepare, roundRect } from '../core/render.js';

const clamp01 = (x) => (x < 0 ? 0 : x > 1 ? 1 : x);
const easeOutBack = (x) => { const c = 1.70158; return 1 + (c + 1) * (x - 1) ** 3 + c * (x - 1) ** 2; };

registerFx('content.pop', (ctx, item, t, project, fx, env) => {
  const d = fx.dur || 0.28, x = (t - item.t0) / d;
  if (x >= 1) return;
  const k = x <= 0 ? 0.55 : 0.55 + 0.45 * easeOutBack(clamp01(x));
  const { w, h } = env.box;
  ctx.translate(w / 2, h / 2); ctx.scale(k, k); ctx.translate(-w / 2, -h / 2);
});

registerFx('content.wipe', (ctx, item, t, project, fx, env) => {
  const f = clamp01((t - item.t0 - (fx.t0 || 0)) / Math.max(0.01, fx.dur || 1));
  const { w, h } = env.box, e = f < 0.5 ? 2 * f * f : 1 - (-2 * f + 2) ** 2 / 2;
  ctx.beginPath();
  if (fx.dir === 'up') ctx.rect(0, h * (1 - e), w, h * e); else ctx.rect(0, 0, w * e, h);
  ctx.clip();
});

/** item.slot = {label, guide, kind, key, n}; item.style = {color, accent} */
registerLayer('content.slot', (ctx, item, t, project, env) => {
  const { w, h } = env.box, s = item.slot || {}, st = item.style || {};
  const k = Math.min(project.canvas.w, project.canvas.h) / 1080;
  const g = ctx.createLinearGradient(0, 0, w, h);
  g.addColorStop(0, st.color || '#2a2228'); g.addColorStop(1, st.color2 || '#120d11');
  ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
  if (env.preview) {   // the dashed "drop here" frame only while editing
    ctx.save(); ctx.setLineDash([22 * k, 14 * k]); ctx.lineWidth = 5 * k; ctx.strokeStyle = 'rgba(255,255,255,.55)';
    ctx.beginPath(); roundRect(ctx, 14 * k, 14 * k, w - 28 * k, h - 28 * k, 26 * k); ctx.stroke(); ctx.restore();
  }
  const fs = Math.max(26, Math.min(w, h) * 0.075);
  ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
  ctx.fillStyle = st.accent || '#ffd400';
  ctx.font = `900 ${fs * 0.6}px Montserrat, system-ui, sans-serif`;
  const dur = Math.max(0, item.out - item.in);
  ctx.fillText(`${s.n ? 'SLOT ' + s.n + ' · ' : ''}${dur.toFixed(1)} s`.toUpperCase(), w / 2, h / 2 - fs * 1.3);
  ctx.fillStyle = '#ffffff';
  ctx.font = `900 ${fs}px Montserrat, system-ui, sans-serif`;
  wrap(ctx, String(s.label || 'Your clip').toUpperCase(), w * 0.84).forEach((ln, i, a) => ctx.fillText(ln, w / 2, h / 2 + (i - (a.length - 1) / 2) * fs * 1.1));
  if (s.guide) {
    ctx.fillStyle = 'rgba(255,255,255,.75)'; ctx.font = `600 ${fs * 0.42}px Montserrat, system-ui, sans-serif`;
    wrap(ctx, s.guide, w * 0.8).slice(0, 3).forEach((ln, i) => ctx.fillText(ln, w / 2, h / 2 + fs * 1.4 + i * fs * 0.55));
  }
});

function wrap(ctx, text, maxW) {
  const out = []; let line = '';
  for (const wd of String(text).split(/\s+/)) { const tl = line ? line + ' ' + wd : wd; if (ctx.measureText(tl).width <= maxW || !line) line = tl; else { out.push(line); line = wd; } }
  if (line) out.push(line); return out;
}

// the fonts the content items use come from the captions module's offline set; make sure they are loaded before export
registerPrepare('content', async (project) => {
  if (!document.fonts || !project.tracks.some((t) => t.items.some((i) => i.auto && /^content\./.test(i.auto)))) return;
  const fams = new Set(['Montserrat', 'Anton']);
  for (const t of project.tracks) for (const i of t.items) if (i.style?.font) fams.add(i.style.font);
  await Promise.all([...fams].map((f) => document.fonts.load(`900 40px "${f}"`).catch(() => {})));
});
