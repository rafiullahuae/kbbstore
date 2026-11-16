// KBB Studio · Designs · PROCEDURAL ARTWORK (instant, no model): backgrounds and decoration drawn on a canvas from the
// palette and a seed -- gradients, mesh, blobs, patterns, frames, ribbons, confetti, light effects (bokeh, leaks, glow,
// rays), stars, hearts, petals, lanterns. Always on-brand (only the scheme's colours), never text, deterministic per seed.
//
//   drawArt(ctx, W, H, art, palette)       art = {base:'linear'|'radial'|'mesh'|'solid', layers:[{k, ...}], seed}
//   artFor(spec, palette, family, seed)    -> an art recipe that suits the brief (occasion art, mood, industry)
//   ART_KINDS                              every layer kind, for the editor's picker
import { rgba, mix, lum } from './lib/color.js';

export const ART_KINDS = ['mesh', 'blobs', 'dots', 'grid', 'stripes', 'waves', 'rays', 'frame', 'double-frame', 'ribbons', 'confetti', 'bokeh', 'leak', 'glow', 'stars', 'sparkles', 'hearts', 'petals', 'lanterns', 'arcs', 'bold-shapes', 'flag-wave'];

export function rng(seed) { let a = (seed >>> 0) || 1; return () => { a = (a + 0x6D2B79F5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }

export function drawArt(ctx, W, H, art, P) {
  const r = rng(art.seed || 1), S = Math.min(W, H) / 1080;
  ctx.save();
  // base
  const base = art.base || 'linear';
  if (base === 'solid') { ctx.fillStyle = P.bg; ctx.fillRect(0, 0, W, H); }
  else if (base === 'radial') {
    const g = ctx.createRadialGradient(W * (art.cx ?? 0.5), H * (art.cy ?? 0.35), 0, W * 0.5, H * 0.5, Math.hypot(W, H) * 0.65);
    g.addColorStop(0, P.bg); g.addColorStop(1, P.bg2); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
  } else if (base === 'mesh') {
    ctx.fillStyle = P.bg; ctx.fillRect(0, 0, W, H);
    const cols = [P.bg2, mix(P.bg, P.acc, 0.18), mix(P.bg2, P.acc2 || P.acc, 0.12), P.bg];
    for (let i = 0; i < 5; i++) {
      const x = r() * W, y = r() * H, rad = (0.45 + r() * 0.5) * Math.max(W, H);
      const g = ctx.createRadialGradient(x, y, 0, x, y, rad); g.addColorStop(0, rgba(cols[i % cols.length], 0.85)); g.addColorStop(1, rgba(cols[i % cols.length], 0));
      ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    }
  } else {
    const a = ((art.angle ?? 160) * Math.PI) / 180, dx = Math.cos(a), dy = Math.sin(a), L = (Math.abs(W * dx) + Math.abs(H * dy)) / 2;
    const g = ctx.createLinearGradient(W / 2 - dx * L, H / 2 - dy * L, W / 2 + dx * L, H / 2 + dy * L);
    g.addColorStop(0, P.bg); g.addColorStop(1, P.bg2); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
  }
  for (const L of art.layers || []) { ctx.save(); ctx.globalAlpha = L.op ?? 1; try { (LAYER[L.k] || (() => {}))(ctx, W, H, P, r, S, L); } finally { ctx.restore(); } }
  ctx.restore();
}

const deco = (P, i) => { const d = P.deco?.length ? P.deco : [P.acc, P.acc2]; return d[((i % d.length) + d.length) % d.length]; };
const softOn = (P) => (lum(P.bg) > 0.5 ? '#FFFFFF' : '#FFFFFF');

const LAYER = {
  mesh(c, W, H, P, r) {
    for (let i = 0; i < 4; i++) { const x = r() * W, y = r() * H, R = (0.3 + r() * 0.4) * Math.max(W, H); const g = c.createRadialGradient(x, y, 0, x, y, R); g.addColorStop(0, rgba(deco(P, i), 0.22)); g.addColorStop(1, rgba(deco(P, i), 0)); c.fillStyle = g; c.fillRect(0, 0, W, H); }
  },
  blobs(c, W, H, P, r, S, L) {
    const n = L.n || 3;
    for (let i = 0; i < n; i++) {
      const cx = (L.xs?.[i] ?? (i % 2 ? 0.92 : 0.06) + (r() - 0.5) * 0.1) * W, cy = (L.ys?.[i] ?? 0.1 + (i / n) * 0.85) * H, R = (0.16 + r() * 0.16) * W;
      c.fillStyle = rgba(deco(P, i), L.alpha ?? 0.22); c.beginPath();
      const pts = 8, ph = r() * 6;
      for (let k = 0; k <= pts; k++) {
        const a = (k / pts) * Math.PI * 2, rr = R * (0.82 + 0.25 * Math.sin(a * 3 + ph)), x = cx + Math.cos(a) * rr, y = cy + Math.sin(a) * rr;
        if (!k) c.moveTo(x, y); else { const pa = ((k - 0.5) / pts) * Math.PI * 2, pr = R * (0.95 + 0.2 * Math.sin(pa * 2 + ph)); c.quadraticCurveTo(cx + Math.cos(pa) * pr * 1.08, cy + Math.sin(pa) * pr * 1.08, x, y); }
      }
      c.fill();
    }
  },
  dots(c, W, H, P, r, S, L) { c.fillStyle = rgba(L.col || P.ink, L.alpha ?? 0.07); const g = 46 * S; for (let y = g / 2; y < H; y += g) for (let x = ((y / g) % 2 ? g / 2 : 0) + g / 4; x < W; x += g) { c.beginPath(); c.arc(x, y, 3.2 * S, 0, 7); c.fill(); } },
  grid(c, W, H, P, r, S, L) { c.strokeStyle = rgba(L.col || P.ink, L.alpha ?? 0.06); c.lineWidth = 1.5 * S; const g = 72 * S; for (let x = 0; x < W; x += g) { c.beginPath(); c.moveTo(x, 0); c.lineTo(x, H); c.stroke(); } for (let y = 0; y < H; y += g) { c.beginPath(); c.moveTo(0, y); c.lineTo(W, y); c.stroke(); } },
  stripes(c, W, H, P, r, S, L) { c.fillStyle = rgba(L.col || P.acc, L.alpha ?? 0.07); const g = 64 * S; c.translate(W / 2, H / 2); c.rotate(-Math.PI / 5); for (let x = -H * 1.5; x < H * 1.5; x += g * 2) c.fillRect(x, -H * 1.5, g, H * 3); },
  waves(c, W, H, P, r, S, L) {
    for (let i = 0; i < (L.n || 3); i++) {
      const y0 = H * (L.y ?? 0.8) + i * 38 * S, amp = 26 * S * (1 + i * 0.3), ph = r() * 6;
      c.strokeStyle = rgba(deco(P, i), L.alpha ?? 0.3); c.lineWidth = (5 - i) * S * 1.6; c.beginPath();
      for (let x = 0; x <= W; x += 8) { const y = y0 + Math.sin(x / (170 * S) + ph) * amp; if (!x) c.moveTo(x, y); else c.lineTo(x, y); }
      c.stroke();
    }
  },
  rays(c, W, H, P, r, S, L) { const cx = W * (L.x ?? 0.5), cy = H * (L.y ?? 0.4), n = 22; c.fillStyle = rgba(L.col || P.acc, L.alpha ?? 0.07); for (let i = 0; i < n; i += 2) { const a1 = (i / n) * Math.PI * 2, a2 = ((i + 1) / n) * Math.PI * 2, R = Math.hypot(W, H); c.beginPath(); c.moveTo(cx, cy); c.lineTo(cx + Math.cos(a1) * R, cy + Math.sin(a1) * R); c.lineTo(cx + Math.cos(a2) * R, cy + Math.sin(a2) * R); c.fill(); } },
  frame(c, W, H, P, r, S, L) { const m = (L.m ?? 34) * S; c.strokeStyle = L.col || rgba(P.acc, 0.9); c.lineWidth = (L.w ?? 3) * S; c.strokeRect(m, m, W - 2 * m, H - 2 * m); },
  'double-frame'(c, W, H, P, r, S, L) { const m = (L.m ?? 30) * S; c.strokeStyle = rgba(P.acc, 0.85); c.lineWidth = 2.5 * S; c.strokeRect(m, m, W - 2 * m, H - 2 * m); c.lineWidth = 1.2 * S; c.strokeRect(m + 12 * S, m + 12 * S, W - 2 * m - 24 * S, H - 2 * m - 24 * S); },
  ribbons(c, W, H, P, r, S, L) {
    // satin ribbons sweeping across a corner, in the occasion/flag colours, with a light sheen
    const cols = L.cols || P.deco || [P.acc, P.acc2];
    const corner = L.corner || 'tr', w = 34 * S;
    cols.forEach((col, i) => {
      c.save();
      const x0 = corner.includes('r') ? W * 1.05 : -W * 0.05, y0 = corner.includes('t') ? H * (0.02 + i * 0.035) : H * (0.98 - i * 0.035);
      const x3 = corner.includes('r') ? W * 0.35 : W * 0.65, y3 = corner.includes('t') ? -H * 0.05 : H * 1.05;
      const g = c.createLinearGradient(x0, y0, x3, y3); g.addColorStop(0, col); g.addColorStop(0.45, mix(col, '#FFFFFF', 0.28)); g.addColorStop(1, mix(col, '#000000', 0.18));
      c.strokeStyle = g; c.lineWidth = w; c.lineCap = 'butt'; c.beginPath();
      c.moveTo(x0, y0 + i * w); c.bezierCurveTo(W * 0.75, y0 + H * 0.18 + i * w, W * 0.55, y3 + H * 0.12, x3 - i * w * 1.2, y3);
      c.stroke(); c.restore();
    });
  },
  confetti(c, W, H, P, r, S, L) {
    const n = L.n || 70;
    for (let i = 0; i < n; i++) {
      const x = r() * W, y = (L.top ? r() * H * 0.45 : r() * H), s = (6 + r() * 12) * S;
      // keep the middle calmer: confetti thins out towards the centre (text lives there)
      if (!L.all && Math.abs(x - W / 2) < W * 0.28 && y > H * 0.25 && y < H * 0.8 && r() < 0.8) continue;
      c.save(); c.translate(x, y); c.rotate(r() * Math.PI); c.fillStyle = deco(P, i); c.globalAlpha *= 0.85;
      if (i % 3 === 0) { c.beginPath(); c.arc(0, 0, s / 2, 0, 7); c.fill(); } else c.fillRect(-s / 2, -s / 5, s, s / 2.4);
      c.restore();
    }
  },
  bokeh(c, W, H, P, r, S, L) { for (let i = 0; i < (L.n || 22); i++) { const x = r() * W, y = r() * H, R = (18 + r() * 70) * S, g = c.createRadialGradient(x, y, 0, x, y, R); const col = i % 2 ? softOn(P) : deco(P, i); g.addColorStop(0, rgba(col, 0.28)); g.addColorStop(0.7, rgba(col, 0.12)); g.addColorStop(1, rgba(col, 0)); c.fillStyle = g; c.beginPath(); c.arc(x, y, R, 0, 7); c.fill(); } },
  leak(c, W, H, P, r, S, L) { const x = W * (L.x ?? 0.95), y = H * (L.y ?? 0.05), g = c.createRadialGradient(x, y, 0, x, y, Math.max(W, H) * 0.7); g.addColorStop(0, rgba(L.col || '#FFD9A0', 0.55)); g.addColorStop(0.4, rgba(L.col2 || P.acc, 0.18)); g.addColorStop(1, rgba(P.acc, 0)); c.globalCompositeOperation = 'screen'; c.fillStyle = g; c.fillRect(0, 0, W, H); },
  glow(c, W, H, P, r, S, L) { const x = W * (L.x ?? 0.5), y = H * (L.y ?? 0.62), R = W * (L.r ?? 0.55), g = c.createRadialGradient(x, y, 0, x, y, R); g.addColorStop(0, rgba(L.col || '#FFFFFF', L.alpha ?? 0.55)); g.addColorStop(1, rgba(L.col || '#FFFFFF', 0)); c.fillStyle = g; c.fillRect(0, 0, W, H); },
  stars(c, W, H, P, r, S, L) { for (let i = 0; i < (L.n || 60); i++) { const x = r() * W, y = r() * H * (L.top ?? 0.6), s = (1 + r() * 2.6) * S; c.fillStyle = rgba(L.col || '#FFFFFF', 0.3 + r() * 0.6); c.beginPath(); c.arc(x, y, s, 0, 7); c.fill(); } },
  sparkles(c, W, H, P, r, S, L) {
    for (let i = 0; i < (L.n || 9); i++) {
      const x = (L.pts?.[i]?.[0] ?? r()) * W, y = (L.pts?.[i]?.[1] ?? r()) * H, s = (14 + r() * 26) * S;
      c.fillStyle = rgba(L.col || deco(P, i), 0.9); c.beginPath();
      for (let k = 0; k < 8; k++) { const a = (k / 8) * Math.PI * 2, rr = k % 2 ? s * 0.18 : s; c.lineTo(x + Math.cos(a) * rr, y + Math.sin(a) * rr); }
      c.closePath(); c.fill();
    }
  },
  hearts(c, W, H, P, r, S, L) { for (let i = 0; i < (L.n || 14); i++) { const x = r() * W, y = r() * H, s = (16 + r() * 34) * S; c.save(); c.translate(x, y); c.rotate((r() - 0.5) * 0.8); c.fillStyle = rgba(deco(P, i), 0.5); c.beginPath(); c.moveTo(0, s * 0.3); c.bezierCurveTo(-s, -s * 0.4, -s * 0.4, -s, 0, -s * 0.45); c.bezierCurveTo(s * 0.4, -s, s, -s * 0.4, 0, s * 0.3); c.fill(); c.restore(); } },
  petals(c, W, H, P, r, S, L) { for (let i = 0; i < (L.n || 12); i++) { const x = (i % 2 ? 0.85 + r() * 0.15 : r() * 0.15) * W, y = r() * H, s = (24 + r() * 40) * S; c.save(); c.translate(x, y); c.rotate(r() * Math.PI * 2); const g = c.createLinearGradient(-s, 0, s, 0); g.addColorStop(0, rgba(deco(P, i), 0.75)); g.addColorStop(1, rgba(mix(deco(P, i), '#FFFFFF', 0.5), 0.75)); c.fillStyle = g; c.beginPath(); c.ellipse(0, 0, s, s * 0.45, 0, 0, 7); c.fill(); c.restore(); } },
  lanterns(c, W, H, P, r, S, L) {
    for (let i = 0; i < (L.n || 3); i++) {
      const x = W * (0.12 + i * 0.13 + (i > 1 ? 0.5 : 0)), len = (120 + r() * 160) * S, s = 46 * S;
      c.strokeStyle = rgba(P.acc, 0.8); c.lineWidth = 2 * S; c.beginPath(); c.moveTo(x, 0); c.lineTo(x, len); c.stroke();
      c.fillStyle = rgba(P.acc, 0.9); c.beginPath(); c.moveTo(x, len); c.lineTo(x + s * 0.6, len + s * 0.5); c.lineTo(x + s * 0.45, len + s * 1.5); c.lineTo(x - s * 0.45, len + s * 1.5); c.lineTo(x - s * 0.6, len + s * 0.5); c.closePath(); c.fill();
      const g = c.createRadialGradient(x, len + s, 0, x, len + s, s * 2); g.addColorStop(0, rgba('#FFE6A8', 0.5)); g.addColorStop(1, rgba('#FFE6A8', 0)); c.fillStyle = g; c.beginPath(); c.arc(x, len + s, s * 2, 0, 7); c.fill();
    }
  },
  arcs(c, W, H, P, r, S, L) { c.lineWidth = 2.5 * S; for (let i = 0; i < 4; i++) { c.strokeStyle = rgba(deco(P, i), 0.35); c.beginPath(); c.arc(W * (L.x ?? 1), H * (L.y ?? 0), (220 + i * 90) * S, 0, Math.PI * 2); c.stroke(); } },
  'bold-shapes'(c, W, H, P, r, S, L) { c.fillStyle = rgba(P.acc, L.alpha ?? 0.9); c.beginPath(); c.moveTo(0, H * 0.72); c.lineTo(W, H * 0.6); c.lineTo(W, H); c.lineTo(0, H); c.fill(); c.fillStyle = rgba(P.acc2 || P.ink, 0.25); c.beginPath(); c.arc(W * 0.95, H * 0.08, W * 0.22, 0, 7); c.fill(); },
  'flag-wave'(c, W, H, P, r, S, L) {
    const cols = L.cols || P.deco || [P.acc]; const band = (L.h ?? 26) * S, y0 = H * (L.y ?? 0.06);
    cols.forEach((col, i) => { c.fillStyle = col; c.beginPath(); for (let x = 0; x <= W; x += 10) { const y = y0 + i * band + Math.sin(x / (160 * S)) * 12 * S; if (!x) c.moveTo(x, y); else c.lineTo(x, y); } for (let x = W; x >= 0; x -= 10) c.lineTo(x, y0 + (i + 1) * band + Math.sin(x / (160 * S)) * 12 * S); c.fill(); });
  },
};

/** the art recipe for a brief + family (seeded, so "Regenerate" gives a fresh one and the same seed gives the same) */
export function artFor(spec, P, family, seed = 1) {
  const r = rng(seed * 7919 + family.length), occ = spec.occasion, tone = spec.tone;
  const layers = [];
  let base = ['mesh', 'linear', 'radial'][Math.floor(r() * 3)];
  if (occ?.id === 'uae-national-day') { layers.push({ k: 'ribbons', cols: ['#EF3340', '#FFFFFF', '#00843D', '#111111'], corner: r() < 0.5 ? 'tr' : 'bl', op: 0.95 }); layers.push({ k: r() < 0.5 ? 'confetti' : 'sparkles', n: 40, op: 0.8 }); }
  else if (occ?.art) for (const k of occ.art.slice(0, 2)) layers.push({ k: k === 'pattern' ? 'dots' : k === 'light' ? 'leak' : k, op: 0.8 });
  if (spec.industry === 'beauty') { layers.push({ k: tone === 'luxury' ? 'bokeh' : 'blobs', n: 3, op: 0.9 }); if (!occ) layers.push({ k: 'sparkles', n: 6, op: 0.8 }); }
  if (spec.kind === 'study' || spec.kind === 'visa') { layers.push({ k: r() < 0.5 ? 'arcs' : 'dots', op: 0.8 }); layers.push({ k: 'glow', y: 0.3, alpha: 0.25 }); }
  if (spec.kind === 'hiring') { layers.push({ k: r() < 0.5 ? 'stripes' : 'grid', op: 0.9 }); if (tone === 'bold') base = 'linear'; }
  if (tone === 'urgent' || tone === 'bold') layers.push({ k: 'rays', alpha: 0.05 });
  if (family === 'frame') layers.push({ k: 'double-frame' });
  return { base, angle: 140 + Math.floor(r() * 60), seed, layers };
}
