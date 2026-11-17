// KBB Studio · themes · ART: the broadcast-style drawing primitives every theme element is made of — waving cloth flags,
// landmark illustrations with depth, postcard scenes, a 3D-feel globe with a flight arc, kinetic type cards in English
// and Arabic, colour-block slabs, light leaks and lens glints, passport stamps, boarding passes, job-role badges and
// confetti. Pure canvas code: no files, no network. Anything expensive and static (a landmark, a scene's sky and skyline)
// is painted once into an offscreen canvas and reused, so a frame costs a few drawImage calls.
//
// Continent outlines below are a coarse hand-made trace (lon/lat) for the globe; country shapes come from the country
// packs' Natural Earth maps (public domain), flags from flag-icons (MIT), both already in brands/planet-careers.
import { HERO, MATS, SIL_MAT } from './landmarks2.js';
import { LANDMARKS } from '../auto/countries/landmarks.js';

export const TAU = Math.PI * 2;
export const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
export const fr = (x) => x - Math.floor(x);
export const rnd = (i, k = 0) => fr(Math.sin(i * 127.1 + k * 311.7 + 0.5) * 43758.5453);
export const easeOut = (x) => 1 - Math.pow(1 - clamp(x), 3);
export const easeIn = (x) => Math.pow(clamp(x), 3);
export const easeIO = (x) => { x = clamp(x); return x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2; };
export const back = (x) => { x = clamp(x); const c = 1.7; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };
export const elastic = (x) => { x = clamp(x); return x === 0 || x === 1 ? x : Math.pow(2, -10 * x) * Math.sin((x * 10 - 0.75) * (TAU / 3)) + 1; };
const hex = (h) => { const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(h || ''); if (!m) return [255, 255, 255]; let s = m[1]; if (s.length === 3) s = s.split('').map((c) => c + c).join(''); const n = parseInt(s, 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; };
export const rgba = (h, a) => { const [r, g, b] = hex(h); return `rgba(${r},${g},${b},${clamp(a)})`; };
export const mix = (a, b, t) => { const A = hex(a), B = hex(b); return '#' + A.map((v, i) => Math.round(v + (B[i] - v) * clamp(t)).toString(16).padStart(2, '0')).join(''); };
export const lum = (h) => { const [r, g, b] = hex(h); return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255; };
/** a colour that reads on top of h */
export const onColor = (h) => (lum(h) > 0.62 ? '#111111' : '#ffffff');
export const FONT_EN = '"Anton", "Bebas Neue", Impact, "Arial Black", sans-serif';
export const FONT_UI = '"Montserrat", "Inter", "Helvetica Neue", Arial, sans-serif';
export const FONT_AR = '"Cairo", "Tajawal", "Noto Sans Arabic", sans-serif';

// ── offscreen caches ──
const CACHE = new Map();
function newCanvas(w, h) {
  w = Math.max(1, Math.ceil(w)); h = Math.max(1, Math.ceil(h));
  return typeof document !== 'undefined' ? Object.assign(document.createElement('canvas'), { width: w, height: h }) : new OffscreenCanvas(w, h);
}
/** paint once into an offscreen canvas of w×h device px, reuse while the key is the same (LRU of 48) */
export function cached(key, w, h, paint, gpu = false) {
  if (gpu) key += ':gpu';
  let rec = CACHE.get(key);
  if (!rec) {
    // software-backed: these pictures are drawn into the overlay's software layer every frame, and reading a GPU-backed
    // picture back for that would cost a round trip per draw
    // (gpu = true: a picture only ever drawn onto the GPU-backed frame — a software one would be uploaded on every draw)
    const cv = newCanvas(w, h), g = gpu ? cv.getContext('2d') : cv.getContext('2d', { willReadFrequently: true });
    try { paint(g, cv.width, cv.height); } catch (e) { console.warn('[themes] art', key, e); }
    rec = cv; CACHE.set(key, rec);
    if (CACHE.size > 48) CACHE.delete(CACHE.keys().next().value);
  } else { CACHE.delete(key); CACHE.set(key, rec); }
  return rec;
}
/** device px per canvas unit of the current transform (so caches match the output resolution) */
export const devScale = (c) => { const m = c.getTransform(); return Math.max(0.05, Math.hypot(m.a, m.b)); };
const q = (x) => Math.max(8, Math.round(x / 8) * 8);   // cache sizes in steps of 8 px

// ── paths ──
const PATHS = new Map();
export const path2d = (d) => { let p = PATHS.get(d); if (!p) { p = new Path2D(d); PATHS.set(d, p); } return p; };
export function rr(c, x, y, w, h, r) { r = Math.max(0, Math.min(r, w / 2, h / 2)); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r); c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath(); }
/** a parallelogram slab leaning by `skew` (fraction of its height) */
export function slab(c, x, y, w, h, skew, col) { const k = h * skew; c.fillStyle = col; c.beginPath(); c.moveTo(x + k, y); c.lineTo(x + w + k, y); c.lineTo(x + w - k, y + h); c.lineTo(x - k, y + h); c.closePath(); c.fill(); }
export function star(c, cx, cy, r, n = 5, inner = 0.42, rot = -Math.PI / 2) { c.beginPath(); for (let i = 0; i < n * 2; i++) { const a = rot + (i * Math.PI) / n, rr2 = i % 2 ? r * inner : r; i ? c.lineTo(cx + Math.cos(a) * rr2, cy + Math.sin(a) * rr2) : c.moveTo(cx + Math.cos(a) * rr2, cy + Math.sin(a) * rr2); } c.closePath(); }

// ── light: leaks, glints, glow ──
/** a soft round blob of light painted once (128 px); scaling a sprite costs far less than a big gradient each frame */
export function blob(col) {
  return cached('blob:' + col, 128, 128, (g) => { const gr = g.createRadialGradient(64, 64, 0, 64, 64, 64); gr.addColorStop(0, rgba(col, 0.9)); gr.addColorStop(0.35, rgba(col, 0.42)); gr.addColorStop(1, rgba(col, 0)); g.fillStyle = gr; g.fillRect(0, 0, 128, 128); });
}
/** a linear gradient rect from a cached 1-D sprite (stops = [[offset, css colour], ...]); dir 'v' top→bottom, 'h' left→right */
export function gradRect(c, x, y, w, h, stops, dir = 'v') {
  const key = 'grad:' + dir + JSON.stringify(stops);
  const cv = cached(key, dir === 'v' ? 1 : 96, dir === 'v' ? 96 : 1, (g, W, H) => { const gr = dir === 'v' ? g.createLinearGradient(0, 0, 0, H) : g.createLinearGradient(0, 0, W, 0); for (const [o, col] of stops) gr.addColorStop(o, col); g.fillStyle = gr; g.fillRect(0, 0, W, H); });
  c.drawImage(cv, x, y, w, h);
}
/** warm light leaks drifting along the frame edges (additive) */
export function lightLeaks(c, w, h, t, cols, a = 1, n = 2) {
  if (a <= 0.01) return;
  c.save(); c.globalCompositeOperation = 'lighter';
  const u = Math.min(w, h);
  for (let i = 0; i < n; i++) {
    const ph = t * (0.07 + 0.03 * i) + i * 0.37, side = i % 2, r = u * (0.36 + 0.08 * Math.sin(ph * 5 + i));
    const x = side ? w * (0.95 + 0.08 * Math.sin(ph * TAU)) : w * (0.05 - 0.08 * Math.sin(ph * TAU * 0.8)), y = h * fr(0.12 + i * 0.31 + t * 0.025);
    c.globalAlpha = clamp(a * (0.5 + 0.2 * Math.sin(t * 1.3 + i * 2)));
    c.drawImage(blob(cols[i % cols.length]), x - r, y - r, 2 * r, 2 * r);
  }
  c.restore();
}
/** an anamorphic lens glint: a 4-point star with a long horizontal streak */
export function glint(c, x, y, r, a, col = '#ffffff') {
  if (a <= 0.01 || r <= 0.5) return;
  c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha *= clamp(a);
  const g = c.createRadialGradient(x, y, 0, x, y, r * 1.6); g.addColorStop(0, rgba(col, 0.9)); g.addColorStop(0.2, rgba(col, 0.35)); g.addColorStop(1, rgba(col, 0));
  c.fillStyle = g; c.fillRect(x - r * 1.6, y - r * 1.6, r * 3.2, r * 3.2);
  const s = c.createLinearGradient(x - r * 6, y, x + r * 6, y); s.addColorStop(0, rgba(col, 0)); s.addColorStop(0.5, rgba(col, 0.75)); s.addColorStop(1, rgba(col, 0));
  c.fillStyle = s; c.fillRect(x - r * 6, y - r * 0.06, r * 12, r * 0.12);
  c.fillStyle = rgba('#ffffff', 0.95); star(c, x, y, r, 4, 0.12, 0); c.fill();
  c.restore();
}
/** a soft spotlight sweep across a box (a glossy pass of light) */
export function sheen(c, x, y, w, h, p, a = 0.35) {
  if (p <= 0 || p >= 1) return;
  const sx = x - w * 0.3 + (w * 1.6) * p, g = c.createLinearGradient(sx - w * 0.15, y, sx + w * 0.15, y + h * 0.4);
  g.addColorStop(0, 'rgba(255,255,255,0)'); g.addColorStop(0.5, `rgba(255,255,255,${a})`); g.addColorStop(1, 'rgba(255,255,255,0)');
  c.save(); c.fillStyle = g; c.fillRect(x, y, w, h); c.restore();
}

// ── flags as cloth ──
/** a flag waving like cloth: the picture in vertical strips that ride a travelling wave, each lit or shaded by its slope */
export function flagCloth(c, img, cols, x, y, w, h, t, { amp = 0.07, waves = 1.35, speed = 2.4, pole = true, strips = 22 } = {}) {
  const A = h * amp;
  if (pole) {
    const pg = c.createLinearGradient(x - w * 0.05, 0, x, 0); pg.addColorStop(0, '#8a8f99'); pg.addColorStop(0.5, '#f4f6fa'); pg.addColorStop(1, '#6b707a');
    c.fillStyle = pg; c.fillRect(x - w * 0.045, y - h * 0.12, w * 0.04, h * 1.9);
    c.fillStyle = '#e8c46a'; c.beginPath(); c.arc(x - w * 0.025, y - h * 0.13, w * 0.032, 0, TAU); c.fill();
  }
  const sw = w / strips;
  for (let i = 0; i < strips; i++) {
    const u0 = i / strips, ph = u0 * waves * TAU - t * speed, env = Math.min(1, u0 * 1.6 + 0.08);
    const dy = Math.sin(ph) * A * env, slope = Math.cos(ph) * env;
    const sx = x + i * sw, sy = y + dy;
    if (img) c.drawImage(img, (i / strips) * img.width, 0, img.width / strips + 0.6, img.height, sx, sy, sw + 0.6, h);
    else { const n = cols.length || 1; for (let k = 0; k < n; k++) { c.fillStyle = cols[k]; c.fillRect(sx, sy + (k * h) / n, sw + 0.6, h / n + 0.5); } }
    c.fillStyle = slope > 0 ? `rgba(255,255,255,${0.2 * slope})` : `rgba(0,0,0,${-0.32 * slope})`;
    c.fillRect(sx, sy, sw + 0.6, h);
  }
  // fabric edge darkening top/bottom
  c.fillStyle = 'rgba(0,0,0,0.08)';
  for (let i = 0; i < strips; i++) { const u0 = i / strips, ph = u0 * waves * TAU - t * speed, env = Math.min(1, u0 * 1.6 + 0.08), dy = Math.sin(ph) * A * env; c.fillRect(x + i * sw, y + dy + h - h * 0.04, sw + 0.6, h * 0.04); }
}
/** a procedural flag for themes without a country (EU-style star ring, a theme's colour bands) */
export function bandFlag(cols, kind) {
  return cached('band:' + kind + cols.join(','), 160, 120, (g, w, h) => {
    if (kind === 'stars') {
      g.fillStyle = cols[0]; g.fillRect(0, 0, w, h); g.fillStyle = cols[1];
      for (let i = 0; i < 12; i++) { const a = (i / 12) * TAU; star(g, w / 2 + Math.cos(a) * h * 0.33, h / 2 + Math.sin(a) * h * 0.33, h * 0.055); g.fill(); }
    } else { for (let k = 0; k < cols.length; k++) { g.fillStyle = cols[k]; g.fillRect(0, (k * h) / cols.length, w, h / cols.length + 1); } }
  });
}

// ── landmarks with depth ──
const silParts = (id) => { const L = LANDMARKS[id]; if (!L) return null; return [...L.d.map((d) => [0, d]), ...(L.h || []).map((d) => [3, d])]; };
export const landmarkName = (id) => HERO[id]?.name || LANDMARKS[id]?.name || id;
export const hasLandmark = (id) => !!(HERO[id] || LANDMARKS[id]);
/** light: 'day' | 'gold' | 'night' — how the tones are painted */
function tones(mat, light) {
  const M = MATS[mat] || MATS.stone;
  if (light === 'night') return { 0: mix(M.shade, '#1a2244', 0.45), 1: mix(M.shade, '#0b1028', 0.65), 2: mix(M.dark, '#05060f', 0.5), 3: M.glow, 4: mix(M.lit, '#ffd9a0', 0.4), 5: M.acc };
  if (light === 'gold') return { 0: mix(M.lit, '#ffb36b', 0.28), 1: mix(M.shade, '#5a2a5e', 0.3), 2: M.dark, 3: M.glow, 4: mix(M.hi, '#ffe2b0', 0.4), 5: M.acc };
  return { 0: M.lit, 1: M.shade, 2: M.dark, 3: M.glow, 4: M.hi, 5: M.acc };
}
function paintLandmark(g, id, s, light) {
  const hero = HERO[id], parts = hero ? hero.parts : silParts(id); if (!parts) return;
  const T = tones(hero ? hero.mat : SIL_MAT[id] || 'stone', light);
  g.save(); g.scale(s / 200, s / 200);
  const glowing = light !== 'day';
  for (const [tone, d, kind] of parts) {
    if (kind === 'b') {   // a shaded sphere
      const [cx, cy, r] = d.split(' ').map(Number), rg = g.createRadialGradient(cx - r * 0.4, cy - r * 0.45, r * 0.1, cx, cy, r);
      rg.addColorStop(0, '#ffffff'); rg.addColorStop(0.35, T[0]); rg.addColorStop(1, T[1]);
      g.fillStyle = rg; g.beginPath(); g.arc(cx, cy, r, 0, TAU); g.fill();
      if (glowing) { g.fillStyle = T[3]; for (let k = 0; k < 5; k++) { const a = k * 1.25; g.beginPath(); g.arc(cx + Math.cos(a) * r * 0.55, cy + Math.sin(a) * r * 0.55, r * 0.09, 0, TAU); g.fill(); } }
      continue;
    }
    const p = path2d(d);
    if (tone === 3) { g.fillStyle = glowing ? T[3] : mix(T[2], '#9fc6ff', 0.35); if (glowing) { g.shadowColor = T[3]; g.shadowBlur = 6; } g.fill(p); g.shadowBlur = 0; continue; }
    if (tone === 0 || tone === 1) {
      const gr = g.createLinearGradient(0, 0, 0, 200); gr.addColorStop(0, tone ? mix(T[1], T[0], 0.15) : mix(T[0], '#ffffff', 0.12)); gr.addColorStop(1, tone ? mix(T[1], T[2], 0.35) : mix(T[0], T[1], 0.45));
      g.fillStyle = gr;
    } else g.fillStyle = T[tone] || T[0];
    g.fill(p);
  }
  // silhouettes from the packs: shade their right half so they read as solid forms
  if (!hero) {
    g.globalCompositeOperation = 'source-atop';
    const sg = g.createLinearGradient(0, 0, 200, 0); sg.addColorStop(0, 'rgba(255,255,255,0.18)'); sg.addColorStop(0.5, 'rgba(255,255,255,0)'); sg.addColorStop(0.5, 'rgba(0,0,0,0.28)'); sg.addColorStop(1, 'rgba(0,0,0,0.42)');
    g.fillStyle = sg; g.fillRect(0, 0, 200, 200);
  }
  g.restore();
}
/** a landmark illustration, size × size canvas units, standing on (x, y) (bottom-centre) */
export function landmark(c, id, x, y, size, light = 'gold', a = 1, rim = '#ffd59a') {
  if (!hasLandmark(id) || a <= 0.01) return;
  const k = devScale(c), px = q(size * k), pad = Math.ceil(px * 0.04);
  const cv = cached(`lm:${id}:${px}:${light}:${rim}`, px + pad * 2, px + pad * 2, (g) => {
    g.translate(pad, pad);
    // rim light: the shape in the rim colour a little up-left, the drawing over it leaves a bright edge
    if (rim) {
      g.save(); g.translate(-px * 0.012, -px * 0.012); paintLandmark(g, id, px, light);
      g.globalCompositeOperation = 'source-in'; g.fillStyle = rim; g.fillRect(-pad, -pad, px + pad * 2, px + pad * 2); g.restore();
    }
    paintLandmark(g, id, px, light);
  });
  c.save(); c.globalAlpha *= a; c.drawImage(cv, x - size / 2 - pad / k, y - size - pad / k, cv.width / k, cv.height / k); c.restore();
}

// ── a postcard scene: sky, sun, distant skyline, hero (+ secondary) landmark, water or ground ──
export function sceneSky(K, light) {
  const P = K.colors;
  if (light === 'night') return ['#070b24', mix(P.primary, '#0b1030', 0.55), mix(P.primary, '#3a1c5a', 0.4)];
  if (light === 'day') return [mix('#2f7fe0', P.primary, 0.2), '#7cc3ff', '#e9f6ff'];
  return [mix(P.primary, '#141a4a', 0.55), mix('#ff4f7b', P.accent, 0.25), '#ffbe6b'];
}
function skyline(g, w, base, h, seed, col) {
  g.fillStyle = col; g.beginPath(); g.moveTo(0, base);
  let x = 0, i = 0;
  while (x < w) {
    const bw = w * (0.03 + 0.05 * rnd(i, seed)), bh = h * (0.25 + 0.75 * rnd(i, seed + 1));
    g.lineTo(x, base - bh); if (rnd(i, seed + 2) > 0.8) { g.lineTo(x + bw * 0.5, base - bh - h * 0.12); } g.lineTo(x + bw, base - bh);
    x += bw; i++;
  }
  g.lineTo(w, base); g.closePath(); g.fill();
}
/** paint the static part of a scene into a cache; returns the canvas (device px) */
export function sceneCanvas(c, K, w, h, light = 'gold', shade = 0) {
  const k = devScale(c), W = q(w * k), H = q(h * k);
  return cached(`scene:${K.id}:${W}x${H}:${light}:${K.colors.primary}:${shade}`, W, H, (g) => {
    const sky = sceneSky(K, light), gr = g.createLinearGradient(0, 0, 0, H);
    gr.addColorStop(0, sky[0]); gr.addColorStop(0.62, sky[1]); gr.addColorStop(1, sky[2]); g.fillStyle = gr; g.fillRect(0, 0, W, H);
    const horizon = H * (K.water ? 0.74 : 0.84), u = Math.min(W, H);
    // sun / moon with a halo
    const sx = W * 0.7, sy = horizon - H * 0.22, sr = u * 0.11;
    const halo = g.createRadialGradient(sx, sy, 0, sx, sy, sr * 4.5); halo.addColorStop(0, light === 'night' ? 'rgba(220,230,255,0.4)' : 'rgba(255,240,200,0.75)'); halo.addColorStop(1, 'rgba(255,200,120,0)');
    g.fillStyle = halo; g.fillRect(0, 0, W, H);
    g.fillStyle = light === 'night' ? '#f1f4ff' : '#fff3d1'; g.beginPath(); g.arc(sx, sy, sr, 0, TAU); g.fill();
    if (light === 'night') { g.fillStyle = '#ffffff'; for (let i = 0; i < 60; i++) { g.globalAlpha = 0.3 + 0.7 * rnd(i, 3); g.fillRect(rnd(i, 4) * W, rnd(i, 5) * horizon * 0.8, 1.5, 1.5); } g.globalAlpha = 1; }
    // two hazy skyline layers
    skyline(g, W, horizon, H * 0.16, 11, rgba(mix(sky[1], sky[0], 0.5), 0.55));
    skyline(g, W, horizon, H * 0.1, 23, rgba(mix(sky[0], '#000000', 0.3), 0.7));
    // ground or water
    if (K.water) {
      const wg = g.createLinearGradient(0, horizon, 0, H); wg.addColorStop(0, mix(sky[2], P0(K), 0.5)); wg.addColorStop(1, mix(sky[0], '#000000', 0.35));
      g.fillStyle = wg; g.fillRect(0, horizon, W, H - horizon);
    } else { const gg = g.createLinearGradient(0, horizon, 0, H); gg.addColorStop(0, mix(sky[0], '#000000', 0.25)); gg.addColorStop(1, mix(sky[0], '#000000', 0.55)); g.fillStyle = gg; g.fillRect(0, horizon, W, H - horizon); }
    // landmarks (hero big in the middle, secondary smaller behind)
    const s2 = u * 0.62, s1 = Math.min(u * 1.02, H * 0.82);
    const draw = (id, x, s, a) => {
      if (!id) return;
      landmark(g, id, x, horizon + s * 0.02, s, light, a, light === 'night' ? '#9fb6ff' : '#ffd59a');
      if (K.water) { g.save(); g.translate(0, horizon * 2 + s * 0.04); g.scale(1, -1); g.globalAlpha = 0.28; landmark(g, id, x, horizon + s * 0.02, s, light, 1, null); g.restore(); }
    };
    for (const [i, id] of (K.skyline || []).entries()) draw(id, W * (0.12 + 0.76 * (i + 0.5) / K.skyline.length), u * (0.36 + 0.12 * rnd(i, 9)), 0.95);
    if (K.secondary) draw(K.secondary, W * 0.26, s2, 0.92);
    if (K.landmark) draw(K.landmark, W * (K.secondary ? 0.6 : 0.5), s1, 1);
    // atmospheric bottom fade
    const fg = g.createLinearGradient(0, H * 0.82, 0, H); fg.addColorStop(0, 'rgba(0,0,0,0)'); fg.addColorStop(1, 'rgba(0,0,0,0.35)'); g.fillStyle = fg; g.fillRect(0, 0, W, H);
    if (shade) { const sg = g.createLinearGradient(0, H * 0.3, 0, H); sg.addColorStop(0, 'rgba(0,0,0,0)'); sg.addColorStop(1, rgba(mix(K.colors.primary, '#000000', 0.5), shade)); g.fillStyle = sg; g.fillRect(0, H * 0.3, W, H * 0.7); }
  });
}
const P0 = (K) => K.colors.primary;
/** a living scene: the cached picture plus drifting clouds, birds and water shimmer */
export function scene(c, K, x, y, w, h, t, light = 'gold', shade = 0) {
  const cv = sceneCanvas(c, K, w, h, light, shade);
  c.drawImage(cv, x, y, w, h);
  c.save(); c.beginPath(); c.rect(x, y, w, h); c.clip();
  const u = Math.min(w, h);
  c.fillStyle = light === 'night' ? 'rgba(180,190,255,0.12)' : 'rgba(255,255,255,0.5)';
  for (let i = 0; i < 3; i++) {
    const cx = x + fr(rnd(i, 41) + t * (0.02 + 0.01 * i)) * (w + u * 0.6) - u * 0.3, cy = y + h * (0.1 + 0.12 * i), r = u * (0.07 + 0.03 * i);
    c.beginPath(); c.ellipse(cx, cy, r * 2.2, r * 0.55, 0, 0, TAU); c.ellipse(cx + r * 0.7, cy - r * 0.3, r * 1.1, r * 0.5, 0, 0, TAU); c.fill();
  }
  c.strokeStyle = 'rgba(20,20,40,0.55)'; c.lineWidth = Math.max(1, u * 0.004); c.lineCap = 'round';
  for (let i = 0; i < 4; i++) {
    const bx = x + fr(0.1 + i * 0.13 + t * 0.06) * w, by = y + h * (0.22 + 0.05 * Math.sin(i * 2 + t)), s = u * 0.012, fl = Math.sin(t * 9 + i) * s * 0.6;
    c.beginPath(); c.moveTo(bx - s, by - fl); c.quadraticCurveTo(bx - s * 0.4, by - s * 0.2, bx, by); c.quadraticCurveTo(bx + s * 0.4, by - s * 0.2, bx + s, by - fl); c.stroke();
  }
  if (K.water) {
    const hz = y + h * 0.74;
    c.fillStyle = 'rgba(255,255,255,0.22)';
    for (let i = 0; i < 9; i++) { const wy = hz + (h - (hz - y)) * (0.08 + 0.1 * i), wx = x + fr(rnd(i, 51) + t * 0.05 * (i % 2 ? 1 : -1)) * w; c.fillRect(wx, wy, w * (0.06 + 0.06 * rnd(i, 52)), Math.max(1, u * 0.003)); }
  }
  c.restore();
}

// ── country map shapes (Natural Earth paths in a 200 box) ──
export function mapShape(c, paths, x, y, size, fill, stroke, lw, glow) {
  if (!paths || !paths.length) return false;
  c.save(); c.translate(x, y); c.scale(size / 200, size / 200);
  if (glow) { c.shadowColor = glow; c.shadowBlur = 18; }
  c.fillStyle = fill; for (const p of paths) c.fill(p);
  c.shadowBlur = 0;
  if (stroke) { c.strokeStyle = stroke; c.lineWidth = (lw || 2) * (200 / size); c.lineJoin = 'round'; for (const p of paths) c.stroke(p); }
  c.restore(); return true;
}

// ── the globe ──
const CONT = [
  [[-168, 66], [-156, 71.3], [-140, 69.6], [-128, 70], [-115, 68.5], [-95, 72], [-85, 70], [-80, 63], [-94, 59], [-92, 57], [-82, 55], [-79, 52], [-77, 59], [-71, 61], [-64, 60], [-61, 55], [-56, 52], [-60, 47], [-66, 45], [-70, 42], [-74, 40], [-76, 35], [-81, 31], [-80, 25.5], [-82, 27], [-83, 29.5], [-89, 30], [-94, 29.5], [-97, 26], [-97.5, 21], [-95, 18.5], [-91, 18.5], [-87, 21], [-88, 16], [-83, 15], [-83.5, 11], [-79.5, 9], [-77.5, 8], [-80, 7.5], [-85, 10], [-88, 13.5], [-92, 14.5], [-96, 16], [-105, 19.5], [-106, 23], [-110, 24], [-112, 29], [-114.5, 31], [-117, 32.5], [-120.5, 34.5], [-122.5, 37.5], [-124, 40.5], [-124, 46], [-123, 49], [-127, 51], [-131, 54.5], [-135, 57.5], [-140, 59.8], [-146, 61], [-152, 59], [-158, 57], [-165, 54.5], [-158, 58.5], [-162, 60], [-165, 62.5], [-164, 64.5]],
  [[-73, 78], [-60, 82], [-30, 83.5], [-20, 81], [-18, 76], [-22, 70], [-32, 68], [-40, 65], [-43, 60], [-48, 61], [-52, 65], [-54, 69], [-58, 75], [-66, 77]],
  [[-77.5, 8], [-72, 12], [-63, 10.7], [-60, 8.5], [-52, 5], [-50, 0], [-44, -2.5], [-35, -5.5], [-35, -9], [-39, -13], [-39, -18], [-41, -22.5], [-48, -26], [-48.5, -28], [-53, -33.5], [-57, -36], [-58, -38.5], [-62, -39], [-65, -42], [-63.5, -42.8], [-67, -46], [-66, -47.5], [-69, -51], [-68.5, -52.5], [-71, -54], [-74.5, -52], [-75.5, -47], [-73.5, -42], [-73.5, -37], [-71.5, -30], [-70.5, -18], [-76, -14], [-80.5, -6], [-81, -3.5], [-80, 0], [-78.5, 2]],
  [[-17, 21], [-16.5, 14.5], [-12, 8.5], [-7.5, 4.5], [-2, 4.8], [4, 6.3], [8.5, 4.5], [9.5, 2], [9, -1], [13, -6], [12, -13], [13.5, -21], [15, -27], [17, -29], [18.5, -34], [20, -34.8], [25, -34], [30, -31], [32.5, -28.5], [33, -25.5], [35.5, -24], [35, -18], [40.5, -15], [40, -10.5], [39.5, -7], [41.5, -1.5], [48, 4.5], [51, 10.5], [51, 12], [44, 10.5], [43, 12.5], [39.5, 15.5], [37.5, 18.5], [35.5, 23.5], [32.5, 30], [29, 30.8], [25, 31.8], [20, 30.8], [19, 30.3], [15.5, 31.5], [11, 33.3], [10.5, 37], [3, 36.8], [-2, 35], [-6, 35.8], [-9.5, 32.5], [-10, 29], [-13, 27.5], [-15, 24.5]],
  [[-9.5, 43], [-9, 37], [-6, 36.2], [-2, 36.8], [0.5, 38.8], [3.2, 41.9], [6, 43.1], [8.8, 44.4], [12.4, 41.8], [16, 38], [18.5, 40.2], [13.6, 43.5], [12.3, 45.3], [19.4, 41.8], [21.5, 37], [23.5, 38], [26, 40.8], [27, 37.5], [30, 36.3], [36, 36.5], [35.5, 33], [34.3, 31.3], [34.9, 29.5], [35, 28], [39, 22], [42.5, 16.5], [43.3, 12.7], [45, 12.8], [52, 15.6], [55.5, 17.5], [59.8, 22.5], [56.3, 26.2], [56, 24.2], [54.5, 24.2], [52, 24], [51.5, 25.7], [50, 26.5], [48, 29.5], [50, 30], [56.5, 27.1], [61.5, 25.2], [66.5, 25.4], [68.5, 23.5], [72.7, 21], [73.5, 15.8], [76.5, 8.2], [78, 8.3], [80.3, 13.3], [80.3, 15.8], [86, 19.8], [89, 22], [92, 21.5], [94.5, 17], [97.5, 16.8], [98.5, 12], [98.3, 8], [100.3, 4.5], [103.4, 1.4], [104.3, 1.4], [103, 5.5], [100.5, 13.5], [105, 8.7], [106.7, 10.5], [109.3, 13.5], [108.3, 18.3], [106.5, 20.5], [110, 21.5], [114, 22.3], [119.5, 25.5], [121.6, 30.5], [120.5, 34], [122.5, 37.3], [118, 38.5], [121.5, 40.8], [124.5, 40], [126.5, 37.8], [129.3, 35.2], [129.5, 40.5], [132.5, 43], [135.5, 43.8], [141, 48.5], [140.5, 52], [137.5, 54], [141.5, 59.3], [149, 59.5], [155.5, 59.5], [163, 62], [160, 60], [156.7, 51], [163.5, 56.5], [162.5, 61], [175, 62.5], [179.5, 65], [180, 68.5], [170, 70], [160, 69.6], [150, 71.5], [140, 72.5], [130, 71], [120, 73], [113, 73.7], [105, 77.5], [98, 76], [87, 75], [80, 73.5], [72, 72.8], [68, 76], [60, 69], [55, 68.5], [44, 68.5], [40.5, 64.5], [37, 66.5], [32, 67], [29, 69.8], [25.6, 71.1], [19, 70], [14, 68], [12.3, 65.8], [10.2, 63.6], [5.1, 61.8], [5.6, 59], [8.2, 58.1], [10.6, 59.4], [11.5, 58.9], [12.6, 55.6], [14.2, 55.4], [16.5, 56.7], [18.9, 59.4], [17.5, 62], [21.4, 64.6], [25.3, 65.5], [21.5, 61.5], [21.6, 60.6], [26.5, 60.3], [29.7, 60], [23, 59.8], [24, 58.2], [21.5, 57.4], [21, 56], [19.7, 54.4], [14.5, 53.9], [10.9, 54.1], [9.6, 54.9], [8.6, 55.8], [8.1, 56.9], [10.6, 57.7], [10.5, 56], [8.6, 53.9], [4.8, 52.9], [3.6, 51.4], [1.6, 50.9], [-1.4, 49.7], [-4.7, 48.4], [-2.2, 47.1], [-1.8, 43.4]],
  [[-5.7, 50.05], [1.4, 51.2], [1.7, 52.7], [0.2, 53.5], [-1.6, 55.6], [-2.1, 57.2], [-3.4, 58.6], [-5, 58.6], [-6.2, 56.8], [-4.9, 55.7], [-3, 54.9], [-3.3, 53.4], [-4.7, 52.8], [-4.2, 51.6]],
  [[-6, 52.2], [-6.2, 53.9], [-5.6, 55.2], [-7.3, 55.4], [-8.5, 54.6], [-10.1, 53.5], [-9.9, 51.6], [-8.3, 51.6]],
  [[-24, 65.5], [-21, 66.3], [-15, 66.5], [-13.5, 65], [-18, 63.4], [-22.5, 63.8]],
  [[130, 31], [131.5, 31.5], [134, 33.5], [135.5, 33.5], [137, 34.6], [139.8, 35], [140.9, 36.9], [141, 38.5], [142, 39.5], [141.5, 41.4], [140, 40.5], [139.5, 38], [136.8, 37.2], [135.5, 35.6], [133, 35.5], [131, 34.4], [130, 33.5]],
  [[140, 41.5], [141.5, 42.5], [143.5, 42], [145.5, 43.3], [142, 45.5], [141.5, 44]],
  [[113.5, -22], [114, -26.5], [115, -34], [118, -35], [124, -33.8], [129, -31.6], [131.5, -31.5], [135.5, -34.8], [138, -35.6], [140, -38], [146.5, -39], [150, -37.5], [153.5, -28], [153, -25], [150.5, -22.5], [146, -19], [145.5, -15], [143.5, -14], [142.5, -10.7], [141.5, -13], [141.5, -16.5], [139.5, -17.5], [136, -15.5], [136.8, -12], [132.5, -11.3], [130, -13], [129.5, -15], [126, -14], [122, -17.5], [121, -19.5], [117, -20.6]],
  [[172.7, -34.5], [174.5, -36], [176, -37.5], [178.5, -37.7], [177, -39.5], [176, -41.3], [174.7, -41.3], [175, -39.5], [173.8, -39.2], [174.5, -37.5]],
  [[172.7, -40.5], [174.3, -41.7], [173, -43.8], [171, -44.5], [169, -46.6], [166.5, -46], [167, -45], [170.5, -43]],
  [[109, 1.5], [111, 2.5], [113, 3.2], [115.5, 5.3], [117.5, 6.5], [119, 5], [118, 1], [117, -1], [116.5, -3.8], [114, -3.5], [111, -3], [110, -1.5]],
  [[95.3, 5.6], [98, 4], [100.3, 1], [104, -1.5], [106, -6], [104.5, -5.8], [101, -3], [98.5, 0.5]],
  [[105.5, -6.5], [110, -6.8], [114.5, -7.8], [110, -8.2], [106, -7.6]],
  [[131, -1], [138, -1.5], [144, -3.5], [150.5, -10.5], [147, -10], [141, -9], [137.5, -5.5], [132, -4]],
  [[49.3, -12], [50.5, -15.5], [47.2, -25], [44, -24.8], [43.5, -21.5], [44.3, -16]],
  [[120, 18.5], [122.5, 18.3], [122, 14], [124.2, 12.5], [125.5, 7], [123.5, 7.5], [121, 13.5]],
];
const D2R = Math.PI / 180;
function proj(lon, lat, lon0, lat0, lift = 1) {
  const l = lon * D2R, p = lat * D2R, l0 = lon0 * D2R, p0 = lat0 * D2R, cp = Math.cos(p);
  return { x: lift * cp * Math.sin(l - l0), y: -lift * (Math.cos(p0) * Math.sin(p) - Math.sin(p0) * cp * Math.cos(l - l0)), z: Math.sin(p0) * Math.sin(p) + Math.cos(p0) * cp * Math.cos(l - l0) };
}
/** points along the great circle a→b ([lon,lat]) at s ∈ 0..1 */
export function slerp(a, b, s) {
  const v = ([lon, lat]) => [Math.cos(lat * D2R) * Math.cos(lon * D2R), Math.cos(lat * D2R) * Math.sin(lon * D2R), Math.sin(lat * D2R)];
  const A = v(a), B = v(b), d = Math.acos(clamp(A[0] * B[0] + A[1] * B[1] + A[2] * B[2], -1, 1)) || 1e-6;
  const k1 = Math.sin((1 - s) * d) / Math.sin(d), k2 = Math.sin(s * d) / Math.sin(d), x = k1 * A[0] + k2 * B[0], y = k1 * A[1] + k2 * B[1], z = k1 * A[2] + k2 * B[2];
  return [Math.atan2(y, x) / D2R, Math.atan2(z, Math.hypot(x, y)) / D2R];
}
/**
 * a 3D-feel globe: ocean sphere with shading and an atmosphere rim, land, graticule, and a flight arc from `from` to `to`
 * drawn up to `prog` (0..1) with a plane at its head. rot = the centre longitude.
 */
export function globe(c, cx, cy, r, { lon0, lat0 = 22, pal, from, to, prog = 1, labels = [], t = 0 }) {
  const ocean0 = pal.ocean || '#1f7ae0', ocean1 = pal.ocean2 || '#062659', land = pal.land || '#eaf6ff', arcCol = pal.arc || '#ffd166';
  // atmosphere
  const at = c.createRadialGradient(cx, cy, r * 0.92, cx, cy, r * 1.22); at.addColorStop(0, rgba(pal.glow || '#7fd3ff', 0.55)); at.addColorStop(1, rgba(pal.glow || '#7fd3ff', 0));
  c.fillStyle = at; c.beginPath(); c.arc(cx, cy, r * 1.22, 0, TAU); c.fill();
  const og = c.createRadialGradient(cx - r * 0.35, cy - r * 0.4, r * 0.1, cx, cy, r); og.addColorStop(0, mix(ocean0, '#ffffff', 0.25)); og.addColorStop(0.6, ocean0); og.addColorStop(1, ocean1);
  c.fillStyle = og; c.beginPath(); c.arc(cx, cy, r, 0, TAU); c.fill();
  c.save(); c.beginPath(); c.arc(cx, cy, r, 0, TAU); c.clip();
  // graticule
  c.strokeStyle = 'rgba(255,255,255,0.13)'; c.lineWidth = Math.max(1, r * 0.006);
  for (let lo = -180; lo < 180; lo += 30) { c.beginPath(); let pen = false; for (let la = -80; la <= 80; la += 8) { const p = proj(lo, la, lon0, lat0); if (p.z < 0) { pen = false; continue; } const X = cx + p.x * r, Y = cy + p.y * r; pen ? c.lineTo(X, Y) : c.moveTo(X, Y); pen = true; } c.stroke(); }
  for (let la = -60; la <= 60; la += 30) { c.beginPath(); let pen = false; for (let lo = -180; lo <= 180; lo += 8) { const p = proj(lo, la, lon0, lat0); if (p.z < 0) { pen = false; continue; } const X = cx + p.x * r, Y = cy + p.y * r; pen ? c.lineTo(X, Y) : c.moveTo(X, Y); pen = true; } c.stroke(); }
  // land (points behind the limb are pulled onto it)
  const lg = c.createLinearGradient(cx - r, cy - r, cx + r, cy + r); lg.addColorStop(0, land); lg.addColorStop(1, mix(land, ocean1, 0.45));
  c.fillStyle = lg; c.beginPath();
  for (const poly of CONT) {
    let vis = false; const pts = poly.map(([lo, la]) => { const p = proj(lo, la, lon0, lat0); if (p.z > 0) vis = true; if (p.z < 0) { const k = Math.hypot(p.x, p.y) || 1; return [p.x / k, p.y / k]; } return [p.x, p.y]; });
    if (!vis) continue;
    pts.forEach(([x, y], i) => (i ? c.lineTo(cx + x * r, cy + y * r) : c.moveTo(cx + x * r, cy + y * r))); c.closePath();
  }
  c.fill();
  // terminator shading + specular
  const sh = c.createRadialGradient(cx - r * 0.45, cy - r * 0.5, r * 0.2, cx, cy, r * 1.05); sh.addColorStop(0, 'rgba(255,255,255,0.18)'); sh.addColorStop(0.55, 'rgba(0,0,0,0)'); sh.addColorStop(1, 'rgba(0,0,30,0.55)');
  c.fillStyle = sh; c.fillRect(cx - r, cy - r, 2 * r, 2 * r);
  c.restore();
  c.strokeStyle = rgba('#ffffff', 0.35); c.lineWidth = Math.max(1, r * 0.012); c.beginPath(); c.arc(cx, cy, r, 0, TAU); c.stroke();
  if (!from || !to) return;
  // flight arc, lifted off the surface
  const N = 48, pts = [];
  for (let i = 0; i <= N; i++) { const s = i / N, [lo, la] = slerp(from, to, s), lift = 1 + 0.16 * Math.sin(Math.PI * s), p = proj(lo, la, lon0, lat0, lift); pts.push({ x: cx + p.x * r, y: cy + p.y * r, z: p.z, s }); }
  const pin = (p, label, k) => {
    if (p.z < 0) return;
    const pr = r * 0.035, pulse = fr(t * 0.9 + k * 0.5);
    c.strokeStyle = rgba(arcCol, 1 - pulse); c.lineWidth = Math.max(1, r * 0.01); c.beginPath(); c.arc(p.x, p.y, pr * (1 + 2.6 * pulse), 0, TAU); c.stroke();
    c.fillStyle = '#ffffff'; c.beginPath(); c.arc(p.x, p.y, pr, 0, TAU); c.fill(); c.fillStyle = arcCol; c.beginPath(); c.arc(p.x, p.y, pr * 0.55, 0, TAU); c.fill();
    if (label) { const fs = r * 0.11; c.font = `700 ${Math.round(fs)}px ${FONT_UI}`; c.textAlign = 'center'; c.textBaseline = 'bottom'; const tw = c.measureText(label).width + fs * 0.7; c.fillStyle = 'rgba(8,12,30,0.78)'; c.beginPath(); rr(c, p.x - tw / 2, p.y - pr * 2 - fs * 1.25, tw, fs * 1.2, fs * 0.3); c.fill(); c.fillStyle = '#ffffff'; c.fillText(label, p.x, p.y - pr * 2 - fs * 0.13); }
  };
  const p0 = proj(from[0], from[1], lon0, lat0), p1 = proj(to[0], to[1], lon0, lat0);
  pin({ x: cx + p0.x * r, y: cy + p0.y * r, z: p0.z }, labels[0], 0);
  const head = Math.max(1, Math.round(N * clamp(prog)));
  c.lineCap = 'round';
  for (const [lw, col] of [[r * 0.04, rgba(arcCol, 0.25)], [r * 0.014, arcCol]]) {
    c.strokeStyle = col; c.lineWidth = Math.max(1, lw); c.setLineDash(lw < r * 0.02 ? [r * 0.035, r * 0.025] : []); c.beginPath();
    for (let i = 0; i <= head; i++) { const p = pts[i]; if (p.z < -0.15) continue; i ? c.lineTo(p.x, p.y) : c.moveTo(p.x, p.y); }
    c.stroke();
  }
  c.setLineDash([]);
  if (prog >= 0.98) pin({ x: cx + p1.x * r, y: cy + p1.y * r, z: p1.z }, labels[1], 1);
  if (prog < 1) { const a = pts[Math.max(0, head - 1)], b = pts[head]; plane(c, b.x, b.y, r * 0.09, Math.atan2(b.y - a.y, b.x - a.x), '#ffffff'); }
}
const PLANE = 'M10 0L5.5 1.2L1 1.2L-3.5 7.5L-5.5 7.5L-3 1.2L-7.5 1.2L-9 3.8L-10.2 3.8L-9.2 0L-10.2 -3.8L-9 -3.8L-7.5 -1.2L-3 -1.2L-5.5 -7.5L-3.5 -7.5L1 -1.2L5.5 -1.2Z';
export function plane(c, x, y, s, rot, col = '#ffffff', shadow = true) {
  c.save(); c.translate(x, y); c.rotate(rot); c.scale(s / 10, s / 10);
  if (shadow) { c.fillStyle = 'rgba(0,0,0,0.3)'; c.translate(1.2, 1.6); c.fill(path2d(PLANE)); c.translate(-1.2, -1.6); }
  const g = c.createLinearGradient(0, -8, 0, 8); g.addColorStop(0, '#ffffff'); g.addColorStop(1, mix(col, '#9aa6b8', 0.4)); c.fillStyle = g; c.fill(path2d(PLANE));
  c.restore();
}

// ── type ──
/** big kinetic English type: each letter rises out of a mask with a stagger and a little overshoot; returns its width */
export function kineticText(c, text, x, y, size, p, { col = '#ffffff', align = 'center', maxW = 1e9, stagger = 0.06, shadow = true, font = FONT_EN, weight = '400', track = 0.02 } = {}) {
  text = String(text || '').toUpperCase(); if (!text) return 0;
  let fs = size; c.font = `${weight} ${Math.round(fs)}px ${font}`;
  let w = c.measureText(text).width + track * fs * text.length;
  if (w > maxW) { fs *= maxW / w; c.font = `${weight} ${Math.round(fs)}px ${font}`; w = maxW; }
  let cx = align === 'center' ? x - w / 2 : align === 'right' ? x - w : x;
  c.save(); c.textAlign = 'left'; c.textBaseline = 'alphabetic';
  c.beginPath(); c.rect(cx - fs, y - fs * 1.05, w + fs * 2, fs * 1.32); c.clip();
  const n = text.length;
  for (let i = 0; i < n; i++) {
    const ch = text[i], cw = c.measureText(ch).width, k = back(clamp((p - i * stagger / Math.max(1, n / 8)) / 0.5));
    const dy = (1 - k) * fs * 1.1;
    if (shadow) { c.fillStyle = 'rgba(0,0,0,0.28)'; c.fillText(ch, cx + fs * 0.03, y + dy + fs * 0.04); }
    c.fillStyle = col; c.fillText(ch, cx, y + dy);
    cx += cw + track * fs;
  }
  c.restore();
  return w;
}
/** Arabic line (right to left, shaped by the browser); fades and slides in with p */
export function arabicText(c, text, x, y, size, p, { col = '#ffffff', align = 'center', maxW = 1e9, weight = 800, shadow = true } = {}) {
  if (!text) return;
  const k = easeOut(p);
  c.save(); c.globalAlpha *= clamp(k); c.direction = 'rtl'; c.font = `${weight} ${Math.round(size)}px ${FONT_AR}`; c.textAlign = align === 'center' ? 'center' : align === 'left' ? 'left' : 'right'; c.textBaseline = 'alphabetic';
  if (shadow && lum(col) > 0.5) { c.fillStyle = 'rgba(0,0,0,0.35)'; c.fillText(text, x + (1 - k) * size * 0.8 + size * 0.04, y + size * 0.05, maxW); }
  c.fillStyle = col; c.fillText(text, x + (1 - k) * size * 0.8, y, maxW); c.restore();
}
export function uiText(c, text, x, y, size, { col = '#ffffff', align = 'left', weight = 700, maxW = 1e9, base = 'alphabetic', track = 0 } = {}) {
  if (!text) return 0;
  c.font = `${weight} ${Math.round(size)}px ${FONT_UI}`; c.textAlign = align; c.textBaseline = base; c.fillStyle = col;
  if (track && 'letterSpacing' in c) c.letterSpacing = `${(size * track).toFixed(1)}px`;
  c.fillText(text, x, y, maxW);
  if (track && 'letterSpacing' in c) c.letterSpacing = '0px';
  return Math.min(maxW, c.measureText(text).width);
}

// ── colour blocks ──
/**
 * diagonal colour slabs sweeping across w×h: p 0→0.5 they cover the frame, 0.5→1 they leave it (dir 1: right→left).
 * Returns the cover fraction so callers can put a card under them.
 */
export function slabWipe(c, w, h, cols, p, { dir = 1, skew = 0.18 } = {}) {
  const n = cols.length, ext = h * skew, span = w + ext * 2, bw = span / n;
  let cover = 1;
  for (let i = 0; i < n; i++) {
    const k = dir > 0 ? i : n - 1 - i, d = k * 0.055, pin = easeIO((p - d) / 0.4), pout = easeIO((p - 0.5 - d) / 0.4);
    cover = Math.min(cover, pin, 1 - pout);
    if (pin <= 0 || pout >= 1) continue;
    const off = ((1 - pin) - pout) * span * dir, x0 = -ext + i * bw + off - 1, x1 = x0 + bw + 2;
    c.fillStyle = cols[i]; c.beginPath(); c.moveTo(x0 + ext, 0); c.lineTo(x1 + ext, 0); c.lineTo(x1 - ext, h); c.lineTo(x0 - ext, h); c.closePath(); c.fill();
    c.fillStyle = 'rgba(255,255,255,0.18)'; c.beginPath(); c.moveTo(x1 + ext - bw * 0.06, 0); c.lineTo(x1 + ext, 0); c.lineTo(x1 - ext, h); c.lineTo(x1 - ext - bw * 0.06, h); c.closePath(); c.fill();
  }
  return clamp(cover);
}
/** a circle iris in col: cover 1 = closed, 0 = open */
export function iris(c, w, h, cover, col, ring) {
  const R = Math.hypot(w, h) / 2 * (1 - clamp(cover)) * 1.03;
  if (R >= Math.hypot(w, h) / 2) return;
  c.save(); c.fillStyle = col; c.beginPath(); c.rect(0, 0, w, h); c.arc(w / 2, h / 2, Math.max(0, R), 0, TAU, true); c.fill('evenodd');
  if (ring && R > 2) { c.strokeStyle = ring; c.lineWidth = Math.min(w, h) * 0.014; c.beginPath(); c.arc(w / 2, h / 2, R, 0, TAU); c.stroke(); }
  c.restore();
}
/** a full-bleed gradient colour field with soft diagonal bands */
export function colorField(c, w, h, cols, t = 0) {
  const g = c.createLinearGradient(0, 0, w * 0.6, h); g.addColorStop(0, cols[0]); g.addColorStop(0.55, cols[1] || cols[0]); g.addColorStop(1, cols[2] || cols[1] || cols[0]);
  c.fillStyle = g; c.fillRect(0, 0, w, h);
  c.save(); c.globalAlpha *= 0.12; c.fillStyle = '#ffffff';
  for (let i = 0; i < 4; i++) { const x = ((i * 0.33 + t * 0.08) % 1.4 - 0.2) * w; slab(c, x, 0, w * 0.08, h, 0.25, '#ffffff'); }
  c.restore();
}

// ── confetti, sparkle ──
const CONF = ['rect', 'rect', 'circle', 'ribbon', 'star'];
/** a burst of confetti from (ox, oy) launched at angle ang±spread, d s after launch */
export function confettiBurst(c, ox, oy, d, u, cols, { n = 40, ang = -Math.PI / 2, spread = 0.9, speed = 1.4, seed = 1, life = 2.2 } = {}) {
  if (d < 0 || d > life) return;
  const fade = 1 - clamp((d - life * 0.7) / (life * 0.3));
  for (let i = 0; i < n; i++) {
    const a = ang + (rnd(i, seed) - 0.5) * 2 * spread, v = u * speed * (0.55 + 0.6 * rnd(i, seed + 1)), g = u * 1.25, drag = 1 / (1 + d * 0.9);
    const x = ox + Math.cos(a) * v * d * drag + Math.sin(d * 3 + i) * u * 0.02 * d, y = oy + Math.sin(a) * v * d * drag + 0.5 * g * d * d * 0.55;
    const s = u * (0.009 + 0.008 * rnd(i, seed + 2)), rot = d * (4 + 8 * rnd(i, seed + 3)) + i, flip = Math.cos(d * (6 + 6 * rnd(i, seed + 4)) + i);
    c.save(); c.translate(x, y); c.rotate(rot); c.scale(1, Math.abs(flip) < 0.15 ? 0.15 : flip); c.globalAlpha *= fade;
    c.fillStyle = cols[i % cols.length];
    const kind = CONF[i % CONF.length];
    if (kind === 'rect') c.fillRect(-s, -s * 0.45, s * 2, s * 0.9);
    else if (kind === 'circle') { c.beginPath(); c.arc(0, 0, s * 0.6, 0, TAU); c.fill(); }
    else if (kind === 'star') { star(c, 0, 0, s * 0.9, 5, 0.45); c.fill(); }
    else { c.lineWidth = s * 0.35; c.strokeStyle = cols[i % cols.length]; c.beginPath(); c.moveTo(-s * 1.4, 0); c.bezierCurveTo(-s * 0.5, -s, s * 0.5, s, s * 1.4, 0); c.stroke(); }
    c.restore();
  }
}
export function sparkles(c, w, h, t, n, col, u, seed = 7) {
  for (let i = 0; i < n; i++) {
    const per = 1.1 + rnd(i, seed) * 1.3, ph = fr(t / per + rnd(i, seed + 1)), k = Math.floor(t / per + rnd(i, seed + 1));
    const x = rnd(i * 13 + k, seed + 2) * w, y = rnd(i * 7 + k, seed + 3) * h, s = Math.pow(Math.sin(ph * Math.PI), 3);
    if (s < 0.02) continue;
    c.fillStyle = col; c.globalAlpha = s; star(c, x, y, u * (0.01 + 0.014 * rnd(i, seed + 4)) * s, 4, 0.18, t * 0.8); c.fill();
  }
  c.globalAlpha = 1;
}

// ── stamps, passport, ticket ──
/** a rubber stamp: double ring, curved text, centre word, a little ink texture. r = radius */
export function stampMark(c, x, y, r, text, sub, col, rot = -0.2, a = 1, kind = 'round') {
  if (a <= 0.01) return;
  c.save(); c.translate(x, y); c.rotate(rot); c.globalAlpha *= clamp(a);
  c.strokeStyle = col; c.fillStyle = col;
  if (kind === 'rect') {
    c.lineWidth = r * 0.07; c.beginPath(); rr(c, -r * 1.25, -r * 0.55, r * 2.5, r * 1.1, r * 0.12); c.stroke();
    c.lineWidth = r * 0.025; c.beginPath(); rr(c, -r * 1.14, -r * 0.45, r * 2.28, r * 0.9, r * 0.08); c.stroke();
    c.font = `400 ${Math.round(r * 0.48)}px ${FONT_EN}`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(String(text).toUpperCase(), 0, -r * 0.05, r * 2.1);
    if (sub) { c.font = `700 ${Math.round(r * 0.17)}px ${FONT_UI}`; c.fillText(sub, 0, r * 0.3, r * 2); }
  } else {
    c.lineWidth = r * 0.08; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.stroke();
    c.lineWidth = r * 0.03; c.beginPath(); c.arc(0, 0, r * 0.78, 0, TAU); c.stroke();
    const t2 = String(text).toUpperCase(); c.font = `700 ${Math.round(r * 0.17)}px ${FONT_UI}`; c.textAlign = 'center'; c.textBaseline = 'middle';
    const span = Math.min(Math.PI * 1.3, t2.length * 0.2);
    for (let i = 0; i < t2.length; i++) { const ang = -Math.PI / 2 - span / 2 + (span * (i + 0.5)) / t2.length; c.save(); c.rotate(ang + Math.PI / 2); c.fillText(t2[i], 0, -r * 0.89); c.restore(); }
    c.font = `400 ${Math.round(r * 0.36)}px ${FONT_EN}`; c.fillText(sub || '✓', 0, 0, r * 1.4);
    star(c, -r * 0.55, r * 0.5, r * 0.08); c.fill(); star(c, r * 0.55, r * 0.5, r * 0.08); c.fill();
  }
  c.restore();
}
/** an open passport page (w×h) with guilloche lines, a photo box, MRZ lines; returns nothing */
export function passportPage(c, x, y, w, h, K) {
  const P = K.colors;
  c.save();
  c.fillStyle = 'rgba(0,0,0,0.35)'; c.beginPath(); rr(c, x + w * 0.02, y + h * 0.03, w, h, w * 0.03); c.fill();
  c.fillStyle = mix(P.primary, '#000000', 0.25); c.beginPath(); rr(c, x - w * 0.015, y - h * 0.015, w * 1.03, h * 1.03, w * 0.035); c.fill();
  const pg = c.createLinearGradient(x, y, x + w, y + h); pg.addColorStop(0, '#fbf6ea'); pg.addColorStop(0.5, '#f1e9d6'); pg.addColorStop(1, '#e8dcc2');
  c.fillStyle = pg; c.beginPath(); rr(c, x, y, w, h, w * 0.025); c.fill();
  c.save(); c.beginPath(); rr(c, x, y, w, h, w * 0.025); c.clip();
  c.strokeStyle = rgba(P.primary, 0.12); c.lineWidth = Math.max(1, w * 0.003);
  for (let i = 0; i < 14; i++) { c.beginPath(); for (let k = 0; k <= 40; k++) { const u = k / 40, X = x + u * w, Y = y + h * (0.1 + i * 0.065) + Math.sin(u * TAU * 2 + i * 0.7) * h * 0.03; k ? c.lineTo(X, Y) : c.moveTo(X, Y); } c.stroke(); }
  // spine
  const sg = c.createLinearGradient(x + w * 0.47, 0, x + w * 0.53, 0); sg.addColorStop(0, 'rgba(0,0,0,0)'); sg.addColorStop(0.5, 'rgba(0,0,0,0.2)'); sg.addColorStop(1, 'rgba(0,0,0,0)'); c.fillStyle = sg; c.fillRect(x + w * 0.44, y, w * 0.12, h);
  // left page: photo + lines
  c.fillStyle = rgba(P.primary, 0.18); c.beginPath(); rr(c, x + w * 0.05, y + h * 0.16, w * 0.15, h * 0.42, w * 0.01); c.fill();
  c.fillStyle = rgba(P.primary, 0.32); c.beginPath(); c.arc(x + w * 0.125, y + h * 0.3, w * 0.035, 0, TAU); c.fill(); c.beginPath(); c.ellipse(x + w * 0.125, y + h * 0.5, w * 0.06, h * 0.08, 0, Math.PI, 0); c.fill();
  c.fillStyle = rgba('#333333', 0.35); for (let i = 0; i < 5; i++) c.fillRect(x + w * 0.23, y + h * (0.18 + i * 0.08), w * (0.14 + 0.06 * rnd(i, 71)), h * 0.022);
  c.font = `600 ${Math.round(h * 0.045)}px "Courier New", monospace`; c.fillStyle = rgba('#333333', 0.55); c.textAlign = 'left'; c.textBaseline = 'alphabetic';
  c.fillText('P<' + (K.code || 'XXX').toUpperCase() + '<<YOUR<NAME<<<<<<<', x + w * 0.04, y + h * 0.82, w * 0.42); c.fillText('X0000000<0XXX0000000<<<<<<<<', x + w * 0.04, y + h * 0.9, w * 0.42);
  // title on the right page
  uiText(c, 'VISAS', x + w * 0.75, y + h * 0.12, h * 0.06, { col: rgba(P.primary, 0.55), align: 'center', weight: 800, track: 0.3 });
  c.restore(); c.restore();
}
/** a boarding pass card w×h; p: how much of it is printed (0..1) */
export function boardingPass(c, x, y, w, h, K, t, txt = {}) {
  const P = K.colors, from = txt.from || 'DXB', to = txt.to || K.iata || 'XXX';
  c.save();
  c.fillStyle = 'rgba(0,0,0,0.3)'; c.beginPath(); rr(c, x + w * 0.012, y + h * 0.04, w, h, h * 0.08); c.fill();
  // body with two notches at the tear line
  const tx = x + w * 0.72;
  c.beginPath(); rr(c, x, y, w, h, h * 0.08); c.moveTo(tx + h * 0.06, y); c.arc(tx, y, h * 0.06, 0, TAU); c.moveTo(tx + h * 0.06, y + h); c.arc(tx, y + h, h * 0.06, 0, TAU); c.save(); c.clip('evenodd');
  c.fillStyle = '#ffffff'; c.fillRect(x, y, w, h);
  const hg = c.createLinearGradient(x, y, x + w, y); hg.addColorStop(0, P.primary); hg.addColorStop(1, mix(P.primary, P.accent, 0.55)); c.fillStyle = hg; c.fillRect(x, y, w, h * 0.26);
  for (let i = 0; i < 3; i++) { c.fillStyle = (K.flag || [])[i] || P.accent; c.fillRect(x + w * (0.9 + i * 0.033), y, w * 0.033, h * 0.26); }
  uiText(c, txt.brand || 'BOARDING PASS', x + w * 0.04, y + h * 0.17, h * 0.1, { col: onColor(P.primary), weight: 800, track: 0.12 });
  c.font = `400 ${Math.round(h * 0.34)}px ${FONT_EN}`; c.fillStyle = '#10162e'; c.textAlign = 'left'; c.textBaseline = 'alphabetic';
  c.fillText(from, x + w * 0.04, y + h * 0.68);
  c.textAlign = 'right'; c.fillText(to, tx - w * 0.04, y + h * 0.68);
  const mid = (x + w * 0.04 + c.measureText(from).width + tx - w * 0.04 - c.measureText(to).width) / 2;
  c.strokeStyle = rgba('#10162e', 0.35); c.lineWidth = Math.max(1, h * 0.012); c.setLineDash([h * 0.04, h * 0.03]); c.beginPath(); c.moveTo(mid - w * 0.08, y + h * 0.56); c.lineTo(mid + w * 0.08, y + h * 0.56); c.stroke(); c.setLineDash([]);
  plane(c, mid, y + h * 0.56, h * 0.09, 0, P.primary, false);
  const labels = [['FLIGHT', txt.flight || 'PC 0001'], ['GATE', txt.gate || '--'], ['SEAT', txt.seat || '--'], ['DATE', txt.date || 'DD MMM']];
  labels.forEach(([k, v], i) => { const lx = x + w * (0.04 + i * 0.165); uiText(c, k, lx, y + h * 0.8, h * 0.065, { col: '#7a8199', weight: 700 }); uiText(c, v, lx, y + h * 0.92, h * 0.085, { col: '#10162e', weight: 800 }); });
  // stub
  c.fillStyle = mix('#ffffff', P.accent, 0.12); c.fillRect(tx, y + h * 0.26, x + w - tx, h * 0.74);
  for (let i = 0; i < 26; i++) { const bw = h * (0.008 + 0.018 * rnd(i, 81)); c.fillStyle = '#10162e'; c.fillRect(tx + w * 0.03 + i * (x + w - tx - w * 0.06) / 26, y + h * 0.36, bw, h * 0.4); }
  uiText(c, to + ' · ' + (K.city || ''), tx + (x + w - tx) / 2, y + h * 0.92, h * 0.07, { col: '#10162e', align: 'center', weight: 800, maxW: (x + w - tx) * 0.9 });
  sheen(c, x, y, w, h, fr(t / 2.4), 0.45);
  c.restore();
  c.fillStyle = '#cfd4df'; for (let i = 1; i < 10; i++) { c.beginPath(); c.arc(tx, y + h * (0.04 + i * 0.096), h * 0.014, 0, TAU); c.fill(); }
  c.restore();
}

// ── job roles: drawn busts in work wear ──
export const ROLES = {
  nurse: { en: 'Nurse', ar: 'ممرض/ة', top: '#2ec4b6', hat: 'nurse' },
  caregiver: { en: 'Caregiver', ar: 'مقدّم رعاية', top: '#9b8cff', hat: 'none', badge: 'heart' },
  driver: { en: 'Driver', ar: 'سائق', top: '#3a86ff', hat: 'cap', badge: 'wheel' },
  welder: { en: 'Welder', ar: 'لحّام', top: '#5c677d', hat: 'mask', badge: 'spark' },
  chef: { en: 'Chef', ar: 'طاهٍ', top: '#ffffff', hat: 'toque' },
  electrician: { en: 'Electrician', ar: 'كهربائي', top: '#ff9f1c', hat: 'hard', hatCol: '#ffd60a', badge: 'bolt' },
  builder: { en: 'Builder', ar: 'عامل بناء', top: '#ff7b00', hat: 'hard', hatCol: '#ffd60a', vest: true },
  waiter: { en: 'Hotel staff', ar: 'موظف فندق', top: '#1d1d2b', hat: 'none', bow: true },
  warehouse: { en: 'Warehouse', ar: 'مستودع', top: '#2b9348', hat: 'hard', hatCol: '#f8f9fa', vest: true },
};
/** a round role badge: background disc, a person in that role's work wear, a role mark */
export function roleBadge(c, role, x, y, r, ring = '#ffffff', bg = '#1d3557') {
  const R0 = ROLES[role] || ROLES.nurse, skin = ['#e0ac8a', '#c68863', '#8d5a3b', '#f1c7a5'][Math.floor(rnd(role.length, 3) * 4)];
  c.save(); c.translate(x, y);
  const bgG = c.createLinearGradient(0, -r, 0, r); bgG.addColorStop(0, mix(bg, '#ffffff', 0.25)); bgG.addColorStop(1, mix(bg, '#000000', 0.25));
  c.fillStyle = bgG; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.fill();
  c.save(); c.beginPath(); c.arc(0, 0, r * 0.94, 0, TAU); c.clip();
  // shoulders
  const tg = c.createLinearGradient(-r, 0, r, 0); tg.addColorStop(0, mix(R0.top, '#ffffff', 0.15)); tg.addColorStop(1, mix(R0.top, '#000000', 0.25));
  c.fillStyle = tg; c.beginPath(); c.moveTo(-r * 0.9, r); c.bezierCurveTo(-r * 0.9, r * 0.4, -r * 0.55, r * 0.3, -r * 0.18, r * 0.28); c.lineTo(r * 0.18, r * 0.28); c.bezierCurveTo(r * 0.55, r * 0.3, r * 0.9, r * 0.4, r * 0.9, r); c.fill();
  if (R0.top === '#ffffff') { c.fillStyle = '#d9dde6'; for (const s of [-1, 1]) for (let k = 0; k < 2; k++) { c.beginPath(); c.arc(s * r * 0.14, r * (0.52 + k * 0.18), r * 0.035, 0, TAU); c.fill(); } }
  if (R0.vest) { c.fillStyle = '#f7d000'; c.fillRect(-r * 0.62, r * 0.42, r * 0.3, r * 0.7); c.fillRect(r * 0.32, r * 0.42, r * 0.3, r * 0.7); c.fillStyle = '#e9ecef'; c.fillRect(-r * 0.62, r * 0.66, r * 1.24, r * 0.07); }
  if (R0.bow) { c.fillStyle = '#ffffff'; c.beginPath(); c.moveTo(-r * 0.18, r * 0.28); c.lineTo(0, r * 0.62); c.lineTo(r * 0.18, r * 0.28); c.fill(); c.fillStyle = '#c1121f'; c.beginPath(); c.moveTo(0, r * 0.36); c.lineTo(-r * 0.12, r * 0.29); c.lineTo(-r * 0.12, r * 0.43); c.closePath(); c.moveTo(0, r * 0.36); c.lineTo(r * 0.12, r * 0.29); c.lineTo(r * 0.12, r * 0.43); c.closePath(); c.fill(); }
  // neck + head
  c.fillStyle = mix(skin, '#000000', 0.12); c.fillRect(-r * 0.1, r * 0.1, r * 0.2, r * 0.22);
  const hg = c.createRadialGradient(-r * 0.08, -r * 0.18, r * 0.04, 0, -r * 0.08, r * 0.32); hg.addColorStop(0, mix(skin, '#ffffff', 0.18)); hg.addColorStop(1, mix(skin, '#000000', 0.12));
  c.fillStyle = hg; c.beginPath(); c.ellipse(0, -r * 0.1, r * 0.25, r * 0.3, 0, 0, TAU); c.fill();
  c.fillStyle = '#2b1d16'; c.beginPath(); c.ellipse(0, -r * 0.3, r * 0.27, r * 0.15, 0, Math.PI, 0); c.fill();
  c.fillStyle = '#2b1d16'; for (const s of [-1, 1]) { c.beginPath(); c.arc(s * r * 0.09, -r * 0.09, r * 0.025, 0, TAU); c.fill(); }
  c.strokeStyle = '#7a3b2e'; c.lineWidth = r * 0.025; c.beginPath(); c.arc(0, -r * 0.02, r * 0.08, 0.2, Math.PI - 0.2); c.stroke();
  // hats
  if (R0.hat === 'nurse') { c.fillStyle = '#ffffff'; c.beginPath(); c.moveTo(-r * 0.24, -r * 0.33); c.lineTo(-r * 0.2, -r * 0.52); c.lineTo(r * 0.2, -r * 0.52); c.lineTo(r * 0.24, -r * 0.33); c.fill(); c.fillStyle = '#e63946'; c.fillRect(-r * 0.025, -r * 0.5, r * 0.05, r * 0.14); c.fillRect(-r * 0.07, -r * 0.455, r * 0.14, r * 0.05); }
  if (R0.hat === 'cap') { c.fillStyle = '#14213d'; c.beginPath(); c.ellipse(0, -r * 0.33, r * 0.27, r * 0.15, 0, Math.PI, 0); c.fill(); c.fillRect(-r * 0.05, -r * 0.35, r * 0.4, r * 0.06); }
  if (R0.hat === 'hard') { c.fillStyle = R0.hatCol || '#ffd60a'; c.beginPath(); c.ellipse(0, -r * 0.32, r * 0.3, r * 0.22, 0, Math.PI, 0); c.fill(); c.fillRect(-r * 0.36, -r * 0.34, r * 0.72, r * 0.06); c.fillStyle = 'rgba(255,255,255,0.45)'; c.fillRect(-r * 0.04, -r * 0.52, r * 0.08, r * 0.18); }
  if (R0.hat === 'toque') { c.fillStyle = '#ffffff'; c.beginPath(); c.arc(-r * 0.14, -r * 0.55, r * 0.15, 0, TAU); c.arc(r * 0.14, -r * 0.55, r * 0.15, 0, TAU); c.arc(0, -r * 0.62, r * 0.17, 0, TAU); c.fill(); c.fillRect(-r * 0.24, -r * 0.52, r * 0.48, r * 0.2); c.fillStyle = '#e5e8ee'; c.fillRect(-r * 0.24, -r * 0.37, r * 0.48, r * 0.05); }
  if (R0.hat === 'mask') { c.fillStyle = '#343a40'; c.beginPath(); c.ellipse(0, -r * 0.12, r * 0.3, r * 0.36, 0, 0, TAU); c.fill(); c.fillStyle = '#0b0f14'; c.beginPath(); rr(c, -r * 0.2, -r * 0.2, r * 0.4, r * 0.12, r * 0.03); c.fill(); c.fillStyle = 'rgba(120,200,255,0.5)'; c.fillRect(-r * 0.17, -r * 0.18, r * 0.12, r * 0.03); }
  c.restore();
  // role mark (bottom right)
  const bx = r * 0.62, by = r * 0.58, br = r * 0.26;
  if (R0.badge || R0.hat === 'nurse' || R0.hat === 'toque') {
    c.fillStyle = '#ffffff'; c.beginPath(); c.arc(bx, by, br, 0, TAU); c.fill();
    const b = R0.badge || (R0.hat === 'nurse' ? 'cross' : 'fork');
    c.fillStyle = c.strokeStyle = { heart: '#e63946', wheel: '#14213d', spark: '#ff7b00', bolt: '#f8961e', cross: '#e63946', fork: '#6c757d' }[b];
    c.lineWidth = br * 0.16; c.lineCap = 'round';
    if (b === 'heart') { c.beginPath(); c.moveTo(bx, by + br * 0.5); c.bezierCurveTo(bx - br * 0.9, by - br * 0.05, bx - br * 0.45, by - br * 0.75, bx, by - br * 0.25); c.bezierCurveTo(bx + br * 0.45, by - br * 0.75, bx + br * 0.9, by - br * 0.05, bx, by + br * 0.5); c.fill(); }
    if (b === 'wheel') { c.beginPath(); c.arc(bx, by, br * 0.6, 0, TAU); c.stroke(); c.beginPath(); c.moveTo(bx - br * 0.6, by); c.lineTo(bx + br * 0.6, by); c.moveTo(bx, by); c.lineTo(bx, by + br * 0.6); c.stroke(); }
    if (b === 'spark') { for (let k = 0; k < 8; k++) { const a = k * TAU / 8; c.beginPath(); c.moveTo(bx + Math.cos(a) * br * 0.2, by + Math.sin(a) * br * 0.2); c.lineTo(bx + Math.cos(a) * br * 0.65, by + Math.sin(a) * br * 0.65); c.stroke(); } }
    if (b === 'bolt') { c.beginPath(); c.moveTo(bx + br * 0.1, by - br * 0.65); c.lineTo(bx - br * 0.35, by + br * 0.08); c.lineTo(bx - br * 0.02, by + br * 0.08); c.lineTo(bx - br * 0.12, by + br * 0.65); c.lineTo(bx + br * 0.35, by - br * 0.1); c.lineTo(bx + br * 0.02, by - br * 0.1); c.closePath(); c.fill(); }
    if (b === 'cross') { c.fillRect(bx - br * 0.15, by - br * 0.55, br * 0.3, br * 1.1); c.fillRect(bx - br * 0.55, by - br * 0.15, br * 1.1, br * 0.3); }
    if (b === 'fork') { c.beginPath(); c.moveTo(bx - br * 0.25, by - br * 0.55); c.lineTo(bx - br * 0.25, by + br * 0.55); c.moveTo(bx + br * 0.25, by - br * 0.55); c.lineTo(bx + br * 0.25, by + br * 0.55); c.stroke(); }
  }
  c.strokeStyle = ring; c.lineWidth = r * 0.07; c.beginPath(); c.arc(0, 0, r * 0.97, 0, TAU); c.stroke();
  c.restore();
}
/** a coin with the currency sign, gold rim, spinning edge-on by `spin` (-1..1) */
export function coin(c, x, y, r, sign, spin = 1, col = '#f6c445') {
  c.save(); c.translate(x, y); c.scale(Math.max(0.08, Math.abs(spin)), 1);
  const g = c.createRadialGradient(-r * 0.3, -r * 0.35, r * 0.1, 0, 0, r); g.addColorStop(0, '#fff6c8'); g.addColorStop(0.5, col); g.addColorStop(1, mix(col, '#7a4b00', 0.55));
  c.fillStyle = mix(col, '#7a4b00', 0.6); c.beginPath(); c.arc(r * 0.06, r * 0.06, r, 0, TAU); c.fill();
  c.fillStyle = g; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.fill();
  c.strokeStyle = mix(col, '#7a4b00', 0.4); c.lineWidth = r * 0.07; c.beginPath(); c.arc(0, 0, r * 0.8, 0, TAU); c.stroke();
  if (spin > 0) { c.fillStyle = mix(col, '#5a3500', 0.55); c.font = `800 ${Math.round(r * (sign.length > 2 ? 0.55 : sign.length > 1 ? 0.75 : 1.0))}px ${FONT_UI}`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(sign, 0, r * 0.05, r * 1.4); }
  c.restore();
}

/** the waving emblem of a theme without a country: its colours and its sign (plane, cap, stamp, a job role ...) */
export function emblem(K) {
  return cached('emblem:' + K.id + K.colors.primary + K.colors.accent, 192, 128, (g, w, h) => {
    const P = K.colors, gr = g.createLinearGradient(0, 0, w, h); gr.addColorStop(0, mix(P.primary, '#ffffff', 0.1)); gr.addColorStop(1, mix(P.primary, '#000000', 0.25));
    g.fillStyle = gr; g.fillRect(0, 0, w, h);
    const acc = P.accent === '#ffffff' || lum(P.accent) > 0.92 ? '#ffd166' : P.accent;
    g.fillStyle = acc; g.beginPath(); g.moveTo(0, h * 0.78); g.lineTo(w, h * 0.62); g.lineTo(w, h); g.lineTo(0, h); g.closePath(); g.fill();
    const cx = w * 0.5, cy = h * 0.42, r = h * 0.3, kind = K.sign === '✈' || K.id === 'travel' ? 'plane' : K.id === 'study' ? 'cap' : K.id === 'passport' ? 'stamp' : K.roles && /^work-/.test(K.id) ? 'role' : 'star';
    if (kind === 'plane') plane(g, cx, cy, r * 1.1, -0.35, '#ffffff', false);
    else if (kind === 'role') roleBadge(g, K.roles[0], cx, cy, r * 1.05, '#ffffff', mix(P.primary, '#000000', 0.2));
    else if (kind === 'stamp') stampMark(g, cx, cy, r, 'APPROVED', '✓', '#ffffff', -0.15, 1, 'round');
    else if (kind === 'cap') { g.fillStyle = '#ffffff'; g.beginPath(); g.moveTo(cx - r * 1.2, cy - r * 0.1); g.lineTo(cx, cy - r * 0.62); g.lineTo(cx + r * 1.2, cy - r * 0.1); g.lineTo(cx, cy + r * 0.38); g.closePath(); g.fill(); g.fillRect(cx - r * 0.62, cy + r * 0.05, r * 1.24, r * 0.5); g.strokeStyle = acc; g.lineWidth = r * 0.1; g.beginPath(); g.moveTo(cx, cy - r * 0.1); g.lineTo(cx + r * 0.95, cy + r * 0.12); g.lineTo(cx + r * 0.95, cy + r * 0.6); g.stroke(); }
    else { g.fillStyle = '#ffffff'; star(g, cx, cy, r, 5, 0.45); g.fill(); }
  });
}
