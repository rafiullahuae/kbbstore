// Procedural art for the ad backgrounds: original, drawn on <canvas>, no
// stock and no copied brand art. Every layer takes t (0..1 entrance) and
// time (seconds, for motion ads) so the same code draws stills and video.
// Deterministic: a seed gives the same picture every time.

export const BRAND = { rose: '#C6395F', roseDeep: '#8E2445', plum: '#4A1830', blush: '#FCE8EE', petal: '#F6C9D6', cream: '#FFF8F4', gold: '#C9A15B', goldLight: '#EBD3A2', ink: '#2B1F26', ink2: '#5F4E57', white: '#FFFFFF' };

export function rng(seed) { let s = seed >>> 0 || 1; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296); }

// ---------- colour ----------
export function hex2rgb(h) { const v = parseInt(String(h).replace('#', '').padEnd(6, '0').slice(0, 6), 16); return [(v >> 16) & 255, (v >> 8) & 255, v & 255]; }
export function rgb2hex(r, g, b) { return '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join(''); }
export function mix(a, b, k) { const A = hex2rgb(a), B = hex2rgb(b); return rgb2hex(A[0] + (B[0] - A[0]) * k, A[1] + (B[1] - A[1]) * k, A[2] + (B[2] - A[2]) * k); }
export function rgba(h, a) { const [r, g, b] = hex2rgb(h); return `rgba(${r},${g},${b},${a})`; }
export function lum(h) { const c = hex2rgb(h).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; }
export function contrast(a, b) { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
function hue(h) { const [r, g, b] = hex2rgb(h).map((v) => v / 255); const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn; if (!d) return { h: 0, s: 0 }; let hh = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; return { h: (hh * 60 + 360) % 360, s: d / (1 - Math.abs(mx + mn - 1) || 1) }; }

// Theme: brand rose/gold, tuned towards the product's own colour so a green
// toner gets a fresh mint-blush set and a yellow serum a honey-blush one,
// while every ad still reads as kbeautybliss.
export function theme(palette) {
  const acc = (palette || []).find((c) => hue(c).s > 0.25) || BRAND.rose;
  const tint = mix(BRAND.blush, acc, 0.16);
  return {
    acc, bg1: mix(BRAND.cream, acc, 0.05), bg2: mix(BRAND.blush, acc, 0.18), tint, mid: mix(BRAND.petal, acc, 0.25),
    deep: mix(BRAND.roseDeep, acc, 0.12), rose: BRAND.rose, gold: BRAND.gold, goldLight: BRAND.goldLight, ink: BRAND.ink, ink2: BRAND.ink2, plum: BRAND.plum,
    accSoft: mix(acc, '#FFFFFF', 0.72),
  };
}

// ---------- layers ----------
export function wash(x, W, H, stops, angle = 90) {
  const a = (angle * Math.PI) / 180, r = Math.hypot(W, H) / 2;
  const g = x.createLinearGradient(W / 2 - Math.cos(a) * r, H / 2 - Math.sin(a) * r, W / 2 + Math.cos(a) * r, H / 2 + Math.sin(a) * r);
  stops.forEach((c, i) => g.addColorStop(i / Math.max(1, stops.length - 1), c));
  x.fillStyle = g; x.fillRect(0, 0, W, H);
}

// Soft "mesh gradient": large blurred colour fields.
export function meshBlobs(x, W, H, colors, seed, time = 0, alpha = 0.55) {
  const r = rng(seed);
  for (let i = 0; i < colors.length * 2; i++) {
    const cx = (r() * 1.2 - 0.1) * W + Math.sin(time * 0.4 + i) * W * 0.02, cy = (r() * 1.2 - 0.1) * H + Math.cos(time * 0.3 + i) * H * 0.02;
    const rad = (0.35 + r() * 0.35) * Math.max(W, H);
    const g = x.createRadialGradient(cx, cy, 0, cx, cy, rad);
    g.addColorStop(0, rgba(colors[i % colors.length], alpha)); g.addColorStop(1, rgba(colors[i % colors.length], 0));
    x.fillStyle = g; x.fillRect(0, 0, W, H);
  }
}

// Light leaks: warm diagonal streaks, screen-blended.
export function lightLeak(x, W, H, t = 1, time = 0, color = '#FFD9C2', strength = 0.5) {
  x.save(); x.globalCompositeOperation = 'screen';
  for (let i = 0; i < 3; i++) {
    const o = (i - 1) * 0.22 + Math.sin(time * 0.5 + i) * 0.03 + (1 - t) * -0.3;
    const g = x.createLinearGradient(W * (0.1 + o), 0, W * (0.6 + o), H);
    g.addColorStop(0, rgba(color, 0)); g.addColorStop(0.45, rgba(color, strength * (0.5 - i * 0.12))); g.addColorStop(0.55, rgba('#FFFFFF', strength * (0.35 - i * 0.08))); g.addColorStop(1, rgba(color, 0));
    x.fillStyle = g; x.fillRect(0, 0, W, H);
  }
  x.restore();
}

export function bokeh(x, W, H, seed, colors, n = 14, t = 1, time = 0, scale = 1) {
  const r = rng(seed);
  x.save(); x.globalCompositeOperation = 'screen';
  for (let i = 0; i < n; i++) {
    const cx = r() * W + Math.sin(time * 0.6 + i) * W * 0.01, cy = r() * H - time * 6 * (1 + (i % 3)), rad = (0.02 + r() * 0.07) * W * scale;
    const g = x.createRadialGradient(cx, cy, 0, cx, cy, rad);
    g.addColorStop(0, rgba(colors[i % colors.length], 0.7 * t)); g.addColorStop(0.6, rgba(colors[i % colors.length], 0.25 * t)); g.addColorStop(1, rgba(colors[i % colors.length], 0));
    x.fillStyle = g; x.beginPath(); x.arc(cx, cy, rad, 0, Math.PI * 2); x.fill();
  }
  x.restore();
}

// Concentric water ripples on a surface (ellipses), drifting outwards in motion.
export function ripples(x, cx, cy, rx, ry, color, time = 0, n = 6, t = 1) {
  x.save();
  for (let i = 0; i < n; i++) {
    const k = ((i / n) + time * 0.12) % 1, a = (1 - k) * 0.55 * t;
    x.strokeStyle = rgba(color, a); x.lineWidth = Math.max(1, rx * 0.012 * (1 - k * 0.6));
    x.beginPath(); x.ellipse(cx, cy, rx * (0.25 + k), ry * (0.25 + k), 0, 0, Math.PI * 2); x.stroke();
    x.strokeStyle = rgba('#FFFFFF', a * 0.8); x.lineWidth = Math.max(1, rx * 0.005);
    x.beginPath(); x.ellipse(cx, cy - ry * 0.04, rx * (0.25 + k), ry * (0.25 + k), 0, Math.PI * 1.05, Math.PI * 1.95); x.stroke();
  }
  x.restore();
}

// Caustic light: wavy bright lines like sun through water / glass.
export function caustics(x, W, H, y0, y1, color, time = 0, seed = 3, alpha = 0.22) {
  const r = rng(seed);
  x.save(); x.globalCompositeOperation = 'screen'; x.lineCap = 'round';
  for (let i = 0; i < 14; i++) {
    const y = y0 + (y1 - y0) * r(), amp = (y1 - y0) * (0.02 + r() * 0.05), f = 2 + r() * 4, ph = r() * 6 + time * (0.6 + r());
    x.strokeStyle = rgba(color, alpha * (0.4 + r() * 0.6)); x.lineWidth = W * (0.002 + r() * 0.004);
    x.beginPath();
    for (let s = 0; s <= 40; s++) { const px = (s / 40) * W, py = y + Math.sin((s / 40) * Math.PI * f + ph) * amp; s ? x.lineTo(px, py) : x.moveTo(px, py); }
    x.stroke();
  }
  x.restore();
}

// A petal: a soft bezier teardrop with a gradient and a crease.
export function petal(x, cx, cy, size, rot, c1, c2, alpha = 1) {
  x.save(); x.translate(cx, cy); x.rotate(rot); x.globalAlpha *= alpha;
  const g = x.createLinearGradient(0, -size, 0, size); g.addColorStop(0, c1); g.addColorStop(1, c2);
  x.fillStyle = g;
  x.beginPath(); x.moveTo(0, size); x.bezierCurveTo(size * 0.95, size * 0.35, size * 0.7, -size * 0.9, 0, -size * 0.7); x.bezierCurveTo(-size * 0.7, -size * 0.9, -size * 0.95, size * 0.35, 0, size); x.fill();
  x.strokeStyle = rgba('#FFFFFF', 0.35); x.lineWidth = size * 0.03; x.beginPath(); x.moveTo(0, size * 0.85); x.quadraticCurveTo(size * 0.08, 0, 0, -size * 0.55); x.stroke();
  x.restore();
}
export function petals(x, W, H, seed, n, colors, t = 1, time = 0, area = null) {
  const r = rng(seed);
  const A = area || { x: 0, y: 0, w: W, h: H };
  for (let i = 0; i < n; i++) {
    const sz = W * (0.018 + r() * 0.035), fall = ((r() + time * (0.05 + r() * 0.05)) % 1.2) - 0.1;
    const px = A.x + r() * A.w + Math.sin(time * 0.8 + i) * W * 0.015, py = A.y + fall * A.h - (1 - t) * H * 0.1;
    petal(x, px, py, sz, r() * 6.28 + time * (r() - 0.5), colors[i % colors.length], mix(colors[i % colors.length], '#FFFFFF', 0.5), 0.55 + r() * 0.45);
  }
}

// Leaves (botanical ingredient art): a leaf with veins.
export function leaf(x, cx, cy, len, rot, c1, c2) {
  x.save(); x.translate(cx, cy); x.rotate(rot);
  const g = x.createLinearGradient(-len * 0.3, 0, len * 0.3, 0); g.addColorStop(0, c1); g.addColorStop(1, c2);
  x.fillStyle = g;
  x.beginPath(); x.moveTo(0, 0); x.bezierCurveTo(len * 0.45, -len * 0.25, len * 0.45, -len * 0.8, 0, -len); x.bezierCurveTo(-len * 0.45, -len * 0.8, -len * 0.45, -len * 0.25, 0, 0); x.fill();
  x.strokeStyle = rgba('#FFFFFF', 0.45); x.lineWidth = len * 0.015;
  x.beginPath(); x.moveTo(0, 0); x.lineTo(0, -len * 0.95); x.stroke();
  for (let i = 1; i < 6; i++) { const y = -len * i * 0.15; x.beginPath(); x.moveTo(0, y); x.quadraticCurveTo(len * 0.12, y - len * 0.06, len * 0.24, y - len * 0.14); x.moveTo(0, y); x.quadraticCurveTo(-len * 0.12, y - len * 0.06, -len * 0.24, y - len * 0.14); x.stroke(); }
  x.restore();
}

// Water droplet with refraction highlight.
export function droplet(x, cx, cy, r, tint = '#FFFFFF', alpha = 1) {
  x.save(); x.globalAlpha *= alpha;
  const g = x.createRadialGradient(cx - r * 0.3, cy - r * 0.35, r * 0.1, cx, cy, r);
  g.addColorStop(0, 'rgba(255,255,255,0.95)'); g.addColorStop(0.35, rgba(tint, 0.25)); g.addColorStop(0.85, rgba(tint, 0.35)); g.addColorStop(1, 'rgba(255,255,255,0.7)');
  x.fillStyle = g; x.beginPath(); x.arc(cx, cy, r, 0, Math.PI * 2); x.fill();
  x.fillStyle = 'rgba(0,0,0,0.06)'; x.beginPath(); x.ellipse(cx + r * 0.15, cy + r * 1.05, r * 0.8, r * 0.18, 0, 0, Math.PI * 2); x.fill();
  x.fillStyle = 'rgba(255,255,255,0.95)'; x.beginPath(); x.ellipse(cx - r * 0.35, cy - r * 0.4, r * 0.22, r * 0.12, -0.6, 0, Math.PI * 2); x.fill();
  x.restore();
}

// Silk: flowing ribbons of light and shade.
export function silk(x, W, H, c1, c2, time = 0, seed = 5, bands = 5) {
  const r = rng(seed);
  for (let i = 0; i < bands; i++) {
    const y = H * (r() * 1.1 - 0.05), amp = H * (0.06 + r() * 0.1), th = H * (0.08 + r() * 0.18), ph = r() * 6 + time * 0.25;
    const g = x.createLinearGradient(0, y - th, 0, y + th);
    g.addColorStop(0, rgba(c1, 0)); g.addColorStop(0.45, rgba(c2, 0.55)); g.addColorStop(0.55, rgba('#FFFFFF', 0.28)); g.addColorStop(1, rgba(c1, 0));
    x.fillStyle = g; x.beginPath();
    for (let s = 0; s <= 30; s++) { const px = (s / 30) * W * 1.1 - W * 0.05, py = y + Math.sin((s / 30) * Math.PI * 1.6 + ph) * amp - th; s ? x.lineTo(px, py) : x.moveTo(px, py); }
    for (let s = 30; s >= 0; s--) { const px = (s / 30) * W * 1.1 - W * 0.05, py = y + Math.sin((s / 30) * Math.PI * 1.6 + ph + 0.5) * amp + th; x.lineTo(px, py); }
    x.closePath(); x.fill();
  }
}

// Plinth / podium: a cylinder (top ellipse + body) in stone or glass.
export function podium(x, cx, topY, w, h, c1, c2, kind = 'stone') {
  const rx = w / 2, ry = w * 0.09;
  x.save();
  const body = x.createLinearGradient(cx - rx, 0, cx + rx, 0);
  body.addColorStop(0, mix(c2, '#000000', 0.06)); body.addColorStop(0.35, c1); body.addColorStop(0.6, mix(c1, '#FFFFFF', 0.4)); body.addColorStop(1, mix(c2, '#000000', 0.1));
  x.fillStyle = body;
  x.beginPath(); x.moveTo(cx - rx, topY); x.lineTo(cx - rx, topY + h); x.ellipse(cx, topY + h, rx, ry, 0, Math.PI, 0, true); x.lineTo(cx + rx, topY); x.closePath(); x.fill();
  const top = x.createLinearGradient(0, topY - ry, 0, topY + ry);
  top.addColorStop(0, mix(c1, '#FFFFFF', 0.65)); top.addColorStop(1, mix(c1, '#FFFFFF', 0.25));
  x.fillStyle = top; x.beginPath(); x.ellipse(cx, topY, rx, ry, 0, 0, Math.PI * 2); x.fill();
  if (kind === 'glass') { x.strokeStyle = 'rgba(255,255,255,0.8)'; x.lineWidth = Math.max(1, w * 0.004); x.stroke(); }
  if (kind === 'stone') { // travertine pores
    const r = rng(Math.round(cx + topY));
    x.fillStyle = rgba(c2, 0.18);
    for (let i = 0; i < 40; i++) { const a = r() * Math.PI * 2, d = Math.sqrt(r()); x.beginPath(); x.ellipse(cx + Math.cos(a) * rx * d * 0.95, topY + Math.sin(a) * ry * d * 0.9, w * 0.006 * (0.5 + r()), w * 0.0025, 0, 0, Math.PI * 2); x.fill(); }
  }
  x.restore();
  return { rx, ry };
}

// Glossy floor line where the product's reflection sits.
export function horizon(x, W, y, H, c1, c2) {
  const g = x.createLinearGradient(0, y, 0, H);
  g.addColorStop(0, c1); g.addColorStop(1, c2);
  x.fillStyle = g; x.fillRect(0, y, W, H - y);
  const s = x.createLinearGradient(0, y - 2, 0, y + H * 0.04);
  s.addColorStop(0, 'rgba(255,255,255,0.55)'); s.addColorStop(1, 'rgba(255,255,255,0)');
  x.fillStyle = s; x.fillRect(0, y, W, H * 0.04);
}

// Cream / gel swatch swirl (texture family).
export function swirl(x, cx, cy, r, c1, c2, time = 0) {
  x.save(); x.translate(cx, cy); x.rotate(time * 0.15);
  for (let i = 0; i < 9; i++) {
    const k = i / 9, rr = r * (1 - k * 0.85);
    const g = x.createRadialGradient(-rr * 0.3, -rr * 0.3, rr * 0.1, 0, 0, rr);
    g.addColorStop(0, mix(c1, '#FFFFFF', 0.75)); g.addColorStop(0.7, i % 2 ? c1 : c2); g.addColorStop(1, mix(c2, '#000000', 0.06));
    x.fillStyle = g; x.beginPath();
    for (let s = 0; s <= 48; s++) { const a = (s / 48) * Math.PI * 2, wob = 1 + 0.06 * Math.sin(a * 5 + i * 1.7) + 0.04 * Math.sin(a * 3 - i); s ? x.lineTo(Math.cos(a) * rr * wob, Math.sin(a) * rr * wob * 0.92) : x.moveTo(Math.cos(a) * rr * wob, Math.sin(a) * rr * wob * 0.92); }
    x.closePath(); x.fill();
    x.rotate(0.55);
  }
  x.strokeStyle = 'rgba(255,255,255,0.75)'; x.lineWidth = r * 0.03; x.lineCap = 'round';
  x.beginPath(); x.arc(0, 0, r * 0.62, -2.4, -1.2); x.stroke();
  x.beginPath(); x.arc(0, 0, r * 0.36, 0.6, 1.5); x.stroke();
  x.restore();
}

// Sparkles: four-point stars (gold), twinkling in motion.
export function sparkles(x, W, H, seed, n, color, t = 1, time = 0, area = null) {
  const r = rng(seed), A = area || { x: 0, y: 0, w: W, h: H };
  x.save();
  for (let i = 0; i < n; i++) {
    const cx = A.x + r() * A.w, cy = A.y + r() * A.h, s = W * (0.006 + r() * 0.014) * (0.6 + 0.4 * Math.sin(time * 3 + i * 1.3)) * t;
    x.fillStyle = rgba(color, 0.9);
    x.beginPath(); x.moveTo(cx, cy - s * 2); x.quadraticCurveTo(cx, cy, cx + s * 2, cy); x.quadraticCurveTo(cx, cy, cx, cy + s * 2); x.quadraticCurveTo(cx, cy, cx - s * 2, cy); x.quadraticCurveTo(cx, cy, cx, cy - s * 2); x.fill();
  }
  x.restore();
}

// Film grain: a tiny cached noise tile, very low alpha (premium photo feel,
// and it hides gradient banding in the encoded video).
let grainTile = null;
export function grain(x, W, H, alpha = 0.035) {
  if (!grainTile) {
    grainTile = document.createElement('canvas'); grainTile.width = grainTile.height = 128;
    const g = grainTile.getContext('2d'), d = g.createImageData(128, 128), r = rng(99);
    for (let i = 0; i < d.data.length; i += 4) { const v = 110 + r() * 145; d.data[i] = d.data[i + 1] = d.data[i + 2] = v; d.data[i + 3] = 255; }
    g.putImageData(d, 0, 0);
  }
  x.save(); x.globalAlpha = alpha; x.globalCompositeOperation = 'overlay';
  x.fillStyle = x.createPattern(grainTile, 'repeat'); x.fillRect(0, 0, W, H);
  x.restore();
}

// Vignette for focus.
export function vignette(x, W, H, color = '#2B1F26', a = 0.18) {
  const g = x.createRadialGradient(W / 2, H * 0.48, Math.min(W, H) * 0.35, W / 2, H / 2, Math.max(W, H) * 0.75);
  g.addColorStop(0, rgba(color, 0)); g.addColorStop(1, rgba(color, a));
  x.fillStyle = g; x.fillRect(0, 0, W, H);
}

// Frosted glass panel: blurs what is behind it when the browser can.
export function glass(x, X, Y, w, h, r, tint = '#FFFFFF', a = 0.42) {
  x.save();
  x.beginPath(); x.roundRect ? x.roundRect(X, Y, w, h, r) : x.rect(X, Y, w, h); x.clip();
  if ('filter' in x) {
    try {
      const src = x.canvas, tmp = document.createElement('canvas');
      tmp.width = Math.max(1, Math.round(w)); tmp.height = Math.max(1, Math.round(h));
      const m = x.getTransform(), s = m.a || 1;
      tmp.getContext('2d').drawImage(src, (X * s + m.e), (Y * s + m.f), w * s, h * s, 0, 0, tmp.width, tmp.height);
      x.filter = `blur(${Math.round(Math.min(w, h) * 0.06)}px)`;
      x.drawImage(tmp, X - 10, Y - 10, w + 20, h + 20);
      x.filter = 'none';
    } catch { /* tainted or unsupported: the tint alone */ }
  }
  x.fillStyle = rgba(tint, a); x.fillRect(X, Y, w, h);
  const sh = x.createLinearGradient(X, Y, X, Y + h * 0.5); sh.addColorStop(0, 'rgba(255,255,255,0.45)'); sh.addColorStop(1, 'rgba(255,255,255,0)');
  x.fillStyle = sh; x.fillRect(X, Y, w, h * 0.5);
  x.restore();
  x.save(); x.strokeStyle = 'rgba(255,255,255,0.85)'; x.lineWidth = Math.max(1.5, w * 0.003);
  x.beginPath(); x.roundRect ? x.roundRect(X, Y, w, h, r) : x.rect(X, Y, w, h); x.stroke(); x.restore();
}
