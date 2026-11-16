// KBB Studio · themes · the procedural drawing engine. Registered with core/render.js, so the preview monitor, the hover
// previews and the MP4 export all draw a theme through this one file.
//
//   item.type 'theme.overlay'  (overlay track, spans the whole reel)  style = {
//       theme, intensity: subtle|normal|bold, colors:{primary, accent, soft, ink, extra:[]}, beat:bool, beats:[timeline s],
//       safe:bool, faces:[{t, x, y, w, h}] (canvas-normalised centre + size), defs:[layer specs from themes.js],
//       layers:{ [layerId]: {on, density, speed, color} } }
//   item.type 'theme.sting'    full-frame themed intro (iris reveal + hero burst)   style {colors, hero, icons}
//   item.type 'theme.outro'    full-frame themed outro (hero converge + iris close) style {colors, hero, icons}
//
// Every element is a pure function of time t (no state between frames), so scrubbing, the preview and the export agree
// frame for frame. The overlay is drawn into its own layer canvas, then the face boxes and the caption boxes are
// cut out of that layer (destination-out, feathered), so a theme never covers a face or a caption.
import { registerLayer, registerPrepare, roundRect } from '../core/render.js';
import { captionBlocks } from '../captions/render.js';
import { LANDMARKS } from '../auto/countries/landmarks.js';
import { CAPITALS, EUROPE_OUTLINE } from './themes.js';

export const INTENSITY = { subtle: { a: 0.55, n: 0.6 }, normal: { a: 0.85, n: 1 }, bold: { a: 1, n: 1.5 } };
const TAU = Math.PI * 2;
const fr = (x) => x - Math.floor(x);
const rnd = (i, k = 0) => fr(Math.sin(i * 127.1 + k * 311.7 + 0.5) * 43758.5453);
const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
const easeOut = (x) => 1 - Math.pow(1 - clamp(x), 3);
const easeIO = (x) => { x = clamp(x); return x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2; };
const back = (x) => { x = clamp(x); const c = 1.7; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };
const NAMED = { gold: '#D4AF37', blush: '#FFC8DD', orchid: '#C77DFF', white: '#ffffff', black: '#111111' };
export const rgba = (hex, a) => {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex || '');
  if (!m) return `rgba(255,255,255,${a})`;
  let s = m[1]; if (s.length === 3) s = s.split('').map((c) => c + c).join('');
  const n = parseInt(s, 16); return `rgba(${n >> 16 & 255},${n >> 8 & 255},${n & 255},${clamp(a)})`;
};

// ── beats ──
/** pulse 0..1 at t: 1 exactly on a beat, decaying over ~0.25 s (beats sorted, timeline seconds) */
export function pulseAt(beats, t) {
  if (!beats || !beats.length || t < beats[0]) return 0;
  let lo = 0, hi = beats.length - 1;
  while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (beats[mid] <= t) lo = mid; else hi = mid - 1; }
  const dt = t - beats[lo];
  return dt > 0.6 ? 0 : Math.exp(-dt / 0.11);
}
/** event times for timed elements: every Nth beat when beat-reactive, else a steady clock */
function events(S, every, phase = 0.4) {
  if (S.beatOn && S.beats.length > 3) {
    const iv = (S.beats[S.beats.length - 1] - S.beats[0]) / (S.beats.length - 1) || 0.5;
    const n = Math.max(1, Math.round(every / iv));
    return S.beats.filter((_, i) => i % n === 0);
  }
  const out = []; for (let k = 0; k * every + phase < S.end + every; k++) out.push(k * every + phase); return out;
}
const lastEvents = (list, t, span) => list.filter((e) => e <= t && t - e < span);

// ── assets (flags, country maps, occupation icons) ──
const IMG = new Map(), MAPS = new Map(), ICONS = new Map(), LM = new Map();
const loadImg = (key, src) => {
  if (IMG.has(key)) return IMG.get(key).ready;
  const img = new Image(), rec = { img, ok: false };
  rec.ready = new Promise((res) => { img.onload = () => { rec.ok = true; res(); }; img.onerror = () => res(); });
  img.src = src; IMG.set(key, rec); return rec.ready;
};
const img = (key) => { const r = IMG.get(key); return r && r.ok ? r.img : null; };
async function loadMap(code) {
  if (MAPS.has(code)) return MAPS.get(code).ready;
  const rec = { paths: null }; MAPS.set(code, rec);
  rec.ready = fetch(`/brands/planet-careers/countries/maps/${code}.svg`).then((r) => (r.ok ? r.text() : '')).then((s) => {
    rec.paths = [...s.matchAll(/\sd="([^"]+)"/g)].map((m) => new Path2D(m[1]));
  }).catch(() => { rec.paths = []; });
  return rec.ready;
}
async function loadIcon(name) {
  if (ICONS.has(name)) return ICONS.get(name).ready;
  const rec = { ops: null }; ICONS.set(name, rec);
  rec.ready = fetch(`/library/occupations/icons/${name}.svg`).then((r) => (r.ok ? r.text() : '')).then((s) => {
    const ops = [];
    for (const m of s.matchAll(/<(path|circle|rect)\b([^>]*)>/g)) {
      const at = (k) => { const x = new RegExp(`\\s${k}="([^"]*)"`).exec(m[2]); return x ? x[1] : null; };
      const p = new Path2D(), fill = at('fill');
      if (m[1] === 'path') p.addPath(new Path2D(at('d') || ''));
      else if (m[1] === 'circle') p.arc(+at('cx') || 0, +at('cy') || 0, +at('r') || 0, 0, TAU);
      else { const x = +at('x') || 0, y = +at('y') || 0, w = +at('width') || 0, hh = +at('height') || 0, r = +at('rx') || 0; if (r) roundRect(p, x, y, w, hh, r); else p.rect(x, y, w, hh); }
      ops.push({ p, fill: fill && fill !== 'none' });
    }
    rec.ops = ops;
  }).catch(() => { rec.ops = []; });
  return rec.ready;
}
const landmark = (id) => {
  if (!LM.has(id)) { const L = LANDMARKS[id]; LM.set(id, L ? { d: (L.d || []).map((x) => new Path2D(x)), h: (L.h || []).map((x) => new Path2D(x)) } : null); }
  return LM.get(id);
};
/** everything a style draws from files, loaded once (the export awaits this through registerPrepare) */
export function loadAssets(style) {
  const jobs = [];
  for (const L of style?.defs || []) {
    if (L.kind === 'flag') jobs.push(loadImg('flag:' + L.code, `/brands/planet-careers/countries/flags/${L.code}.svg`));
    if (L.kind === 'map') jobs.push(loadMap(L.code));
    if (L.kind === 'icons') for (const n of L.icons || []) jobs.push(loadIcon(n));
  }
  for (const n of style?.icons || []) jobs.push(loadIcon(n));
  return Promise.all(jobs);
}
registerPrepare('themes', async (project) => {
  const jobs = [];
  for (const tr of project.tracks || []) for (const it of tr.items) if (/^theme\./.test(it.type)) jobs.push(loadAssets(it.style));
  await Promise.all(jobs);
});

// ── shapes (unit size: radius ≈ 1, centred on 0,0) ──
const MAPLE = [[0, -1], [0.12, -0.72], [0.3, -0.8], [0.25, -0.42], [0.55, -0.6], [0.5, -0.42], [0.9, -0.48], [0.75, -0.2], [0.82, -0.12], [0.45, 0.12], [0.5, 0.3], [0.08, 0.25], [0.05, 0.85], [-0.05, 0.85], [-0.08, 0.25], [-0.5, 0.3], [-0.45, 0.12], [-0.82, -0.12], [-0.75, -0.2], [-0.9, -0.48], [-0.5, -0.42], [-0.55, -0.6], [-0.25, -0.42], [-0.3, -0.8], [-0.12, -0.72]];
const PLANE = [[1, 0], [0.55, 0.12], [0.1, 0.12], [-0.35, 0.75], [-0.55, 0.75], [-0.3, 0.12], [-0.75, 0.12], [-0.9, 0.38], [-1.02, 0.38], [-0.92, 0], [-1.02, -0.38], [-0.9, -0.38], [-0.75, -0.12], [-0.3, -0.12], [-0.55, -0.75], [-0.35, -0.75], [0.1, -0.12], [0.55, -0.12]];
const BOLT = [[0.2, -1], [-0.6, 0.15], [-0.05, 0.15], [-0.25, 1], [0.6, -0.2], [0.05, -0.2]];
const poly = (c, pts) => { c.beginPath(); pts.forEach(([x, y], i) => (i ? c.lineTo(x, y) : c.moveTo(x, y))); c.closePath(); };
const star = (c, n, inner) => { c.beginPath(); for (let i = 0; i < n * 2; i++) { const a = -Math.PI / 2 + (i * Math.PI) / n, r = i % 2 ? inner : 1; i ? c.lineTo(Math.cos(a) * r, Math.sin(a) * r) : c.moveTo(Math.cos(a) * r, Math.sin(a) * r); } c.closePath(); };
const petalPath = (c) => { c.beginPath(); c.moveTo(0, -1); c.bezierCurveTo(0.95, -0.55, 0.7, 0.75, 0, 1); c.bezierCurveTo(-0.7, 0.75, -0.95, -0.55, 0, -1); c.closePath(); };

/** draw one shape at (x, y), radius r, rotation rot */
function shape(c, kind, x, y, r, rot, col, a, S, flip = 1, extra) {
  if (r <= 0.2 || a <= 0.004) return;
  c.save(); c.translate(x, y); c.rotate(rot); c.scale(r, r * flip); c.globalAlpha = clamp(a);
  c.fillStyle = col; c.strokeStyle = col;
  switch (kind) {
    case 'star5': star(c, 5, 0.42); c.fill(); break;
    case 'star4': star(c, 4, 0.2); c.fill(); c.globalAlpha = clamp(a * 0.25); c.beginPath(); c.arc(0, 0, 0.45, 0, TAU); c.fill(); break;
    case 'petal': petalPath(c); c.fill(); c.globalAlpha = clamp(a * 0.35); c.fillStyle = '#ffffff'; c.beginPath(); c.ellipse(-0.18, -0.25, 0.18, 0.45, 0.3, 0, TAU); c.fill(); break;
    case 'sakura': for (let k = 0; k < 5; k++) { c.save(); c.rotate((k * TAU) / 5); c.beginPath(); c.moveTo(0, 0); c.bezierCurveTo(0.42, -0.25, 0.4, -0.85, 0.12, -1); c.lineTo(0, -0.86); c.lineTo(-0.12, -1); c.bezierCurveTo(-0.4, -0.85, -0.42, -0.25, 0, 0); c.fill(); c.restore(); } c.fillStyle = '#d1495b'; c.beginPath(); c.arc(0, 0, 0.16, 0, TAU); c.fill(); break;
    case 'maple': poly(c, MAPLE); c.fill(); break;
    case 'snow': c.lineWidth = 0.13; c.lineCap = 'round'; c.beginPath(); for (let k = 0; k < 6; k++) { const ax = Math.cos((k * TAU) / 6), ay = Math.sin((k * TAU) / 6); c.moveTo(0, 0); c.lineTo(ax, ay); const bx = ax * 0.55, by = ay * 0.55, px = -ay * 0.28, py = ax * 0.28; c.moveTo(bx, by); c.lineTo(bx + ax * 0.2 + px, by + ay * 0.2 + py); c.moveTo(bx, by); c.lineTo(bx + ax * 0.2 - px, by + ay * 0.2 - py); } c.stroke(); break;
    case 'confetti': c.fillRect(-1, -0.45, 2, 0.9); break;
    case 'dust': c.beginPath(); c.arc(0, 0, 1, 0, TAU); c.fill(); c.globalAlpha = clamp(a * 0.22); c.beginPath(); c.arc(0, 0, 2.4, 0, TAU); c.fill(); break;
    case 'dot': c.beginPath(); c.arc(0, 0, 1, 0, TAU); c.fill(); break;
    case 'bubble': {
      const g = c.createRadialGradient(0, 0, 0.2, 0, 0, 1); g.addColorStop(0, rgba('#ffffff', 0.02)); g.addColorStop(0.75, rgba(col, 0.18)); g.addColorStop(1, rgba(col, 0.5));
      c.fillStyle = g; c.beginPath(); c.arc(0, 0, 1, 0, TAU); c.fill();
      c.lineWidth = 0.06; c.strokeStyle = 'rgba(255,255,255,0.75)'; c.stroke();
      c.fillStyle = 'rgba(255,255,255,0.85)'; c.beginPath(); c.ellipse(-0.38, -0.42, 0.22, 0.12, -0.7, 0, TAU); c.fill(); break;
    }
    case 'bokeh': { const g = c.createRadialGradient(0, 0, 0, 0, 0, 1); g.addColorStop(0, rgba(col, 0.55)); g.addColorStop(0.7, rgba(col, 0.25)); g.addColorStop(1, rgba(col, 0)); c.fillStyle = g; c.beginPath(); c.arc(0, 0, 1, 0, TAU); c.fill(); break; }
    case 'heart': c.beginPath(); c.moveTo(0, 0.85); c.bezierCurveTo(-1.2, 0, -0.7, -1, 0, -0.4); c.bezierCurveTo(0.7, -1, 1.2, 0, 0, 0.85); c.fill(); break;
    case 'cap': {
      poly(c, [[-1, -0.25], [0, -0.68], [1, -0.25], [0, 0.18]]); c.fill();
      c.beginPath(); c.moveTo(-0.55, -0.02); c.lineTo(-0.55, 0.42); c.quadraticCurveTo(0, 0.68, 0.55, 0.42); c.lineTo(0.55, -0.02); c.lineTo(0, 0.18); c.closePath(); c.fill();
      if (extra) { c.strokeStyle = extra; c.lineWidth = 0.07; poly(c, [[-1, -0.25], [0, -0.68], [1, -0.25], [0, 0.18]]); c.stroke(); }
      c.strokeStyle = NAMED.gold; c.lineWidth = 0.08; c.beginPath(); c.moveTo(0, -0.25); c.lineTo(0.78, 0); c.lineTo(0.78, 0.5); c.stroke(); c.fillStyle = NAMED.gold; c.fillRect(0.68, 0.48, 0.2, 0.25); break;
    }
    case 'bulb': {
      c.globalAlpha = clamp(a * 0.35); const g = c.createRadialGradient(0, -0.25, 0, 0, -0.25, 1.4); g.addColorStop(0, rgba(col, 0.9)); g.addColorStop(1, rgba(col, 0)); c.fillStyle = g; c.beginPath(); c.arc(0, -0.25, 1.4, 0, TAU); c.fill();
      c.globalAlpha = clamp(a); c.fillStyle = col; c.beginPath(); c.arc(0, -0.25, 0.6, 0, TAU); c.fill(); c.fillStyle = '#cfcfcf'; c.fillRect(-0.28, 0.3, 0.56, 0.4); c.fillStyle = '#9a9a9a'; c.fillRect(-0.22, 0.7, 0.44, 0.14); break;
    }
    case 'shamrock': for (let k = 0; k < 3; k++) { c.save(); c.rotate((k * TAU) / 3); c.translate(0, -0.45); c.scale(0.55, 0.55); c.beginPath(); c.moveTo(0, 0.85); c.bezierCurveTo(-1.2, 0, -0.7, -1, 0, -0.4); c.bezierCurveTo(0.7, -1, 1.2, 0, 0, 0.85); c.fill(); c.restore(); } c.lineWidth = 0.1; c.beginPath(); c.moveTo(0, 0); c.quadraticCurveTo(0.15, 0.6, 0.35, 0.95); c.stroke(); break;
    case 'tulip': c.beginPath(); c.moveTo(-0.55, -0.6); c.lineTo(-0.28, -0.3); c.lineTo(0, -0.7); c.lineTo(0.28, -0.3); c.lineTo(0.55, -0.6); c.bezierCurveTo(0.6, 0.05, 0.3, 0.3, 0, 0.3); c.bezierCurveTo(-0.3, 0.3, -0.6, 0.05, -0.55, -0.6); c.fill(); c.strokeStyle = '#2d6a4f'; c.lineWidth = 0.1; c.beginPath(); c.moveTo(0, 0.3); c.lineTo(0, 1); c.stroke(); break;
    case 'leaf': c.beginPath(); c.moveTo(0, -1); c.quadraticCurveTo(0.65, 0, 0, 1); c.quadraticCurveTo(-0.65, 0, 0, -1); c.fill(); c.globalAlpha = clamp(a * 0.5); c.strokeStyle = '#ffffff'; c.lineWidth = 0.06; c.beginPath(); c.moveTo(0, -0.9); c.lineTo(0, 0.95); c.stroke(); break;
    case 'crescent': c.beginPath(); c.arc(0, 0, 1, Math.PI * 0.3, Math.PI * 1.7, false); c.arc(0.42, -0.08, 0.82, Math.PI * 1.55, Math.PI * 0.45, true); c.closePath(); c.fill(); break;
    case 'plane': poly(c, PLANE); c.fill(); break;
    case 'bolt': poly(c, BOLT); c.fill(); break;
    case 'cross': c.beginPath(); roundRect(c, -0.32, -1, 0.64, 2, 0.14); roundRect(c, -1, -0.32, 2, 0.64, 0.14); c.fill(); break;
    case 'lotus': for (let k = -2; k <= 2; k++) { c.save(); c.rotate(k * 0.42); c.scale(0.42, 0.85); petalPath(c); c.translate(0, -0.05); c.fill(); c.restore(); } break;
    case 'icon': {
      const rec = extra && ICONS.get(extra); const ops = rec && rec.ops;
      if (!ops) { c.beginPath(); c.arc(0, 0, 0.5, 0, TAU); c.fill(); break; }
      c.scale(1 / 12, 1 / 12); c.translate(-12, -12); c.lineWidth = 1.9; c.lineCap = 'round'; c.lineJoin = 'round';
      for (const o of ops) o.fill ? c.fill(o.p) : c.stroke(o.p); break;
    }
    default: c.beginPath(); c.arc(0, 0, 1, 0, TAU); c.fill();
  }
  c.restore();
}

// ── layout helpers ──
function corner(S, name, size) {
  const { W, H, u, land } = S, r = (size * u) / 2, m = u * 0.05;
  const top = land ? m : Math.max(m, H * 0.075), bottom = land ? m : Math.max(m, H * 0.1);
  return { cx: /l/.test(name) ? m + r : W - m - r, cy: /t/.test(name) ? top + r : H - bottom - r, r };
}

// ── elements: fn(c, L, S, o) — L = layer spec, o = per-layer overrides {density, speed, color} ──
const E = {};

E.particles = (c, L, S, o) => {
  const n = Math.round((L.count || 12) * S.n * (o.density ?? 1)), cols = S.cols(L, o), [s0, s1] = L.size || [0.01, 0.02];
  const v = { fall: 0.07, rise: 0.06, float: 0, drift: 0 }[L.dir || 'fall'] ?? 0.05;
  const t = S.t * (o.speed ?? 1), m = S.u * 0.08;
  for (let i = 0; i < n; i++) {
    const a1 = rnd(i, 1), a2 = rnd(i, 2), a3 = rnd(i, 3), a4 = rnd(i, 4), a5 = rnd(i, 5), a6 = rnd(i, 6);
    let x, y;
    if (L.dir === 'float' || L.dir === 'drift') {
      x = a2 * S.W + Math.sin(t * 0.22 * (0.5 + a3) + a5 * TAU) * S.u * 0.09;
      y = a1 * S.H + Math.cos(t * 0.18 * (0.5 + a4) + a6 * TAU) * S.u * 0.07;
    } else {
      const vv = v * (0.6 + 0.8 * a3) * S.H, span = S.H + 2 * m, p = fr(a1 + (t * vv) / span);
      y = L.dir === 'rise' ? S.H + m - p * span : -m + p * span;
      x = a2 * S.W + Math.sin(t * (0.6 + a4) + a5 * TAU) * S.u * 0.05;
    }
    const r = (s0 + (s1 - s0) * a3) * S.u * (1 + 0.35 * S.pulse);
    const tw = L.dir === 'float' ? 0.6 + 0.4 * Math.sin(t * 2 + a1 * 20) : 1;
    const rot = a6 * TAU + t * (L.spin || 0) * (a4 - 0.5) * 2;
    const flip = L.shape === 'confetti' || L.shape === 'petal' || L.shape === 'maple' || L.shape === 'leaf' ? Math.cos(t * (1.5 + a5 * 2) + a2 * 9) : 1;
    shape(c, L.shape, x, y, r, rot, cols[i % cols.length], S.a * (0.55 + 0.45 * a4) * tw, S, Math.abs(flip) < 0.15 ? 0.15 * Math.sign(flip || 1) : flip, L.shape === 'cap' ? S.col(L.outline || 'soft') : null);
  }
};

E.icons = (c, L, S, o) => {
  const n = Math.round((L.count || 8) * S.n * (o.density ?? 1)), cols = S.cols(L, o), t = S.t * (o.speed ?? 1), list = L.icons || [];
  for (let i = 0; i < n; i++) {
    const a1 = rnd(i, 11), a2 = rnd(i, 12), a3 = rnd(i, 13), a4 = rnd(i, 14);
    const span = S.H + S.u * 0.2, p = fr(a1 + (t * S.H * 0.035 * (0.6 + 0.6 * a3)) / span);
    const y = S.H + S.u * 0.1 - p * span, x = a2 * S.W + Math.sin(t * 0.5 + a4 * TAU) * S.u * 0.04;
    const r = S.u * (0.028 + 0.016 * a3) * (1 + 0.25 * S.pulse);
    // a soft disc behind each icon keeps it legible over any picture
    shape(c, 'dot', x, y, r * 1.35, 0, rgba(S.col('ink'), 1), S.a * 0.28, S);
    shape(c, 'icon', x, y, r, Math.sin(t * 0.7 + a4 * 9) * 0.25, cols[i % cols.length], S.a * (0.7 + 0.3 * a4), S, 1, list[i % Math.max(1, list.length)]);
  }
};

E.sparkles = (c, L, S, o) => {
  const n = Math.round((L.count || 10) * S.n * (o.density ?? 1)), col = S.col(o.color || L.color || 'soft'), t = S.t * (o.speed ?? 1);
  for (let i = 0; i < n; i++) {
    const a1 = rnd(i, 21), a2 = rnd(i, 22), a3 = rnd(i, 23), a4 = rnd(i, 24), per = 1.4 + 1.6 * a3;
    const k = Math.floor((t + a4 * per) / per), ph = fr((t + a4 * per) / per);   // each twinkle moves to a new spot
    const x = rnd(i * 31 + k, 25) * S.W, y = (L.zone === 'top' ? 0.42 : 1) * rnd(i * 17 + k, 26) * S.H;
    const s = Math.pow(Math.max(0, Math.sin(ph * Math.PI)), 3);
    shape(c, 'star4', x, y, S.u * (0.012 + 0.016 * a1) * (s + 0.6 * S.pulse * s), t * 0.6 + a2, col, S.a * s, S);
  }
};

E.starRing = (c, L, S, o) => {
  const { cx, cy, r } = corner(S, L.corner || 'tr', (L.size || 0.24) * (0.8 + 0.2 * (o.density ?? 1)));
  const t = S.t * (o.speed ?? 1), k = 1 + 0.12 * S.pulse, col = S.col(o.color || 'accent');
  c.globalAlpha = S.a * 0.22; c.strokeStyle = col; c.lineWidth = S.u * 0.002; c.beginPath(); c.arc(cx, cy, r * 0.8 * k, 0, TAU); c.stroke();
  for (let i = 0; i < 12; i++) {
    const ang = (i * TAU) / 12 + t * 0.18, tw = 0.75 + 0.25 * Math.sin(t * 2.4 + i * 1.7);
    const sx = cx + Math.cos(ang) * r * 0.8 * k, sy = cy + Math.sin(ang) * r * 0.8 * k;
    shape(c, 'dust', sx, sy, r * 0.06 * k, 0, col, S.a * 0.35 * tw, S); shape(c, 'star5', sx, sy, r * 0.13 * k * tw, 0, col, S.a * (0.8 + 0.2 * tw), S);
  }
};

const project2d = (lon, lat, box) => ({ x: box.x + ((lon + 11) / 52) * box.w, y: box.y + ((71 - lat) / 37) * box.h });
const ROUTES = [['Dublin', 'Berlin'], ['Madrid', 'Paris'], ['Rome', 'Vienna'], ['Lisbon', 'Amsterdam'], ['Warsaw', 'Prague'], ['London', 'Stockholm'], ['Athens', 'Budapest'], ['Helsinki', 'Riga'], ['Valletta', 'Rome'], ['Brussels', 'Copenhagen'], ['Bern', 'Vilnius'], ['Nicosia', 'Athens']];
E.routes = (c, L, S, o) => {
  const w = (S.land ? 0.34 : 0.5) * S.W * (L.size ? L.size / 0.42 : 1), h = w * 1.15;
  const m = S.u * 0.05, box = { x: /r/.test(L.corner || 'tl') ? S.W - m - w : m, y: S.land ? m : S.H * 0.075, w, h };
  const t = S.t * (o.speed ?? 1), col = S.col(o.color || 'soft'), acc = S.col('accent');
  c.lineJoin = 'round'; c.beginPath();
  EUROPE_OUTLINE.forEach(([lo, la], i) => { const p = project2d(lo, la, box); i ? c.lineTo(p.x, p.y) : c.moveTo(p.x, p.y); });
  c.closePath(); c.globalAlpha = S.a * 0.1; c.fillStyle = S.col('primary'); c.fill(); c.globalAlpha = S.a * 0.42; c.strokeStyle = col; c.lineWidth = S.u * 0.0025; c.stroke();
  const caps = Object.entries(CAPITALS);
  for (const [, [lo, la]] of caps) { const p = project2d(lo, la, box); shape(c, 'dot', p.x, p.y, S.u * 0.0045, 0, col, S.a * 0.75, S); }
  const per = 1.7, nShow = Math.max(1, Math.round(3 * (o.density ?? 1)));
  for (let j = 0; j < nShow; j++) {
    const k = Math.floor(t / per) - j; if (k < 0) continue;
    const [A, B] = ROUTES[k % ROUTES.length], pa = project2d(...CAPITALS[A], box), pb = project2d(...CAPITALS[B], box);
    const p = j === 0 ? easeIO(fr(t / per) * 1.15) : 1, fade = j === 0 ? 1 : 1 - j / (nShow + 0.5);
    const cx = (pa.x + pb.x) / 2, cy = (pa.y + pb.y) / 2 - Math.hypot(pb.x - pa.x, pb.y - pa.y) * 0.35;
    const at = (s) => ({ x: (1 - s) * (1 - s) * pa.x + 2 * (1 - s) * s * cx + s * s * pb.x, y: (1 - s) * (1 - s) * pa.y + 2 * (1 - s) * s * cy + s * s * pb.y });
    c.globalAlpha = S.a * 0.9 * fade; c.strokeStyle = acc; c.lineWidth = S.u * 0.004; c.setLineDash([S.u * 0.012, S.u * 0.008]); c.beginPath();
    for (let s = 0; s <= 24; s++) { const q = at((s / 24) * p); s ? c.lineTo(q.x, q.y) : c.moveTo(q.x, q.y); } c.stroke(); c.setLineDash([]);
    const hd = at(p); shape(c, 'dot', hd.x, hd.y, S.u * 0.008 * (1 + 0.5 * S.pulse), 0, acc, S.a * fade, S);
    if (j === 0 && p >= 1) { const rr = S.u * 0.02 * (1 + fr(t / per) * 2); c.globalAlpha = S.a * (1 - fr(t / per)); c.lineWidth = S.u * 0.003; c.beginPath(); c.arc(pb.x, pb.y, rr, 0, TAU); c.stroke(); }
  }
};

E.map = (c, L, S, o) => {
  const rec = MAPS.get(L.code); const { cx, cy, r } = corner(S, L.corner || 'tl', L.size || 0.2);
  const t = S.t * (o.speed ?? 1), col = S.col(o.color || 'soft');
  if (rec && rec.paths && rec.paths.length) {
    c.save(); c.translate(cx - r, cy - r); c.scale((2 * r) / 200, (2 * r) / 200);
    c.globalAlpha = S.a * 0.25; c.fillStyle = S.col('primary'); for (const p of rec.paths) c.fill(p);
    c.globalAlpha = S.a * 0.85; c.strokeStyle = col; c.lineWidth = 2.2; c.lineJoin = 'round'; for (const p of rec.paths) c.stroke(p);
    c.restore();
  }
  const ph = fr(t / 1.6); shape(c, 'dot', cx, cy, S.u * 0.008 * (1 + 0.6 * S.pulse), 0, S.col('accent'), S.a, S);
  c.globalAlpha = S.a * (1 - ph); c.strokeStyle = S.col('accent'); c.lineWidth = S.u * 0.003; c.beginPath(); c.arc(cx, cy, S.u * 0.01 + ph * r * 0.5, 0, TAU); c.stroke();
};

E.flag = (c, L, S, o) => {
  const { cx, cy, r } = corner(S, L.corner || 'tr', L.size || 0.15), w = r * 2, h = w * 0.7, x0 = cx - r, y0 = cy - h / 2, t = S.t * (o.speed ?? 1);
  const im = img('flag:' + L.code), N = 18, amp = h * 0.07 * (1 + 0.6 * S.pulse);
  c.globalAlpha = S.a * 0.9; c.strokeStyle = 'rgba(255,255,255,0.9)'; c.lineWidth = S.u * 0.004; c.beginPath(); c.moveTo(x0 - S.u * 0.004, y0 - h * 0.1); c.lineTo(x0 - S.u * 0.004, y0 + h * 1.5); c.stroke();
  for (let i = 0; i < N; i++) {
    const f = i / N, dy = Math.sin(t * 3.2 - f * 5.5) * amp * f, sx = (w / N) * i, sw = w / N + 0.6;
    c.globalAlpha = S.a;
    if (im) c.drawImage(im, (im.naturalWidth || 640) * f, 0, (im.naturalWidth || 640) / N + 1, im.naturalHeight || 480, x0 + sx, y0 + dy, sw, h);
    else { const ex = S.colors.extra || [S.col('primary'), S.col('soft'), S.col('accent')]; ex.forEach((col, k) => { c.fillStyle = col; c.fillRect(x0 + sx, y0 + dy + (k * h) / ex.length, sw, h / ex.length + 0.5); }); }
    const shade = Math.cos(t * 3.2 - f * 5.5) * 0.16 * f; c.globalAlpha = S.a * Math.abs(shade); c.fillStyle = shade > 0 ? '#ffffff' : '#000000'; c.fillRect(x0 + sx, y0 + dy, sw, h);
  }
};

E.ribbons = (c, L, S, o) => {
  const keys = L.colors || ['c0', 'c1', 'c2'], n = Math.min(keys.length, Math.max(1, Math.round((L.count || 3) * (o.density ?? 1)))), t = S.t * (o.speed ?? 1);
  const edges = L.edge === 'both' ? ['top', 'bottom'] : [L.edge || 'top'];
  for (const edge of edges) for (let k = 0; k < n; k++) {
    const y0 = edge === 'top' ? (S.land ? 0.02 : 0.035) * S.H + k * S.u * 0.02 : S.H * (S.land ? 0.98 : 0.965) - k * S.u * 0.02;
    const th = S.u * 0.014, top = [], bot = [];
    for (let s = 0; s <= 28; s++) {
      const x = -0.05 * S.W + (s / 28) * S.W * 1.1, ph = (x / S.W) * TAU * 1.1 + t * 1.3 + k * 0.8;
      const y = y0 + Math.sin(ph) * S.u * 0.022 * (1 + 0.4 * S.pulse), tt = th * (0.75 + 0.35 * Math.cos(ph * 0.7));
      top.push([x, y - tt]); bot.push([x, y + tt]);
    }
    c.globalAlpha = S.a * 0.75; c.fillStyle = S.col(keys[k]); c.beginPath(); top.forEach(([x, y], i) => (i ? c.lineTo(x, y) : c.moveTo(x, y))); bot.reverse().forEach(([x, y]) => c.lineTo(x, y)); c.closePath(); c.fill();
  }
};

E.landmark = (c, L, S, o) => {
  const lm = landmark(L.id); if (!lm) return;
  const t = S.t * (o.speed ?? 1), hh = (S.land ? 0.34 : L.height || 0.2) * S.H, k = hh / 200, col = S.col(o.color || 'soft');
  const draw = (x, kk, alpha) => {
    c.save(); c.translate(x, S.H - 200 * kk); c.scale(kk, kk); c.globalAlpha = S.a * alpha; c.fillStyle = col; for (const p of lm.d) c.fill(p);
    c.globalCompositeOperation = 'destination-out'; c.globalAlpha = 1; for (const p of lm.h) c.fill(p); c.restore();
  };
  const par = Math.sin(t * 0.12) * S.u * 0.035;
  draw(S.W * 0.02 - 30 * k + par, k, 0.3);
  if (L.side === 'both') draw(S.W * 0.98 - 170 * k * 0.72 - par * 0.5, k * 0.72, 0.18);
};

E.constellation = (c, L, S, o) => {
  const { cx, cy, r } = corner(S, L.corner || 'tl', L.size || 0.18), t = S.t * (o.speed ?? 1), col = S.col(o.color || 'soft');
  const P = [[0, 0.9, 1], [0, -0.85, 0.9], [-0.62, -0.12, 0.8], [0.55, -0.28, 0.7], [0.28, 0.25, 0.45]];
  c.globalAlpha = S.a * 0.28; c.strokeStyle = col; c.lineWidth = S.u * 0.0018; c.beginPath(); c.moveTo(cx + P[0][0] * r, cy + P[0][1] * r); c.lineTo(cx + P[1][0] * r, cy + P[1][1] * r); c.moveTo(cx + P[2][0] * r, cy + P[2][1] * r); c.lineTo(cx + P[3][0] * r, cy + P[3][1] * r); c.stroke();
  P.forEach(([x, y, m], i) => { const tw = 0.7 + 0.3 * Math.sin(t * 2.2 + i * 2.1); shape(c, 'star5', cx + x * r, cy + y * r, S.u * 0.016 * m * tw * (1 + 0.3 * S.pulse), 0, col, S.a * tw, S); shape(c, 'star4', cx + x * r, cy + y * r, S.u * 0.03 * m * tw, t * 0.3, col, S.a * 0.35 * tw, S); });
};

E.stamps = (c, L, S, o) => {
  const texts = L.texts && L.texts.length ? L.texts : ['WELCOME'], evs = events(S, (L.every || 3) / (o.density ?? 1)), life = 1.7;
  const spots = S.land ? [[0.12, 0.2], [0.88, 0.22], [0.1, 0.78], [0.9, 0.75], [0.3, 0.14], [0.7, 0.86]] : [[0.18, 0.2], [0.8, 0.28], [0.2, 0.48], [0.82, 0.55], [0.75, 0.14], [0.22, 0.62]];
  const inks = ['#c1121f', '#1d4ed8', '#166534', S.col('primary')];
  for (const e of lastEvents(evs, S.t, life)) {
    const k = evs.indexOf(e), dt = S.t - e, sp = spots[Math.floor(rnd(k, 31) * spots.length)], text = texts[k % texts.length];
    const x = sp[0] * S.W, y = sp[1] * S.H, r = S.u * 0.085, rot = (rnd(k, 32) - 0.5) * 0.6;
    const s = dt < 0.14 ? 1.7 - 0.7 * easeOut(dt / 0.14) : 1, al = (dt < 0.06 ? dt / 0.06 : 1) * (dt > life - 0.4 ? (life - dt) / 0.4 : 1);
    const ink = o.color ? S.col(o.color) : inks[k % inks.length];
    c.save(); c.translate(x, y); c.rotate(rot); c.scale(s, s); c.globalAlpha = S.a * 0.88 * clamp(al); c.strokeStyle = ink; c.fillStyle = ink; c.textAlign = 'center'; c.textBaseline = 'middle';
    const round = rnd(k, 33) < 0.5;
    if (round) {
      c.lineWidth = r * 0.07; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.stroke(); c.lineWidth = r * 0.03; c.beginPath(); c.arc(0, 0, r * 0.82, 0, TAU); c.stroke();
      c.font = `800 ${Math.round(r * 0.3)}px "Courier New", ui-monospace, monospace`; c.fillText(text.slice(0, 11), 0, 0, r * 1.5);
      for (const sx of [-0.45, 0, 0.45]) shape(c, 'star5', sx * r, r * 0.52, r * 0.07, 0, ink, S.a * 0.88 * clamp(al), S);
    } else {
      const w = r * 2.3, h = r * 1.15; c.lineWidth = r * 0.06; c.beginPath(); roundRect(c, -w / 2, -h / 2, w, h, r * 0.12); c.stroke(); c.lineWidth = r * 0.025; c.beginPath(); roundRect(c, -w / 2 + r * 0.1, -h / 2 + r * 0.1, w - r * 0.2, h - r * 0.2, r * 0.08); c.stroke();
      c.font = `800 ${Math.round(r * 0.32)}px "Courier New", ui-monospace, monospace`; c.fillText(text.slice(0, 12), 0, -r * 0.08, w * 0.86);
      c.font = `700 ${Math.round(r * 0.16)}px "Courier New", ui-monospace, monospace`; c.fillText('✈  ARRIVAL  ✈', 0, r * 0.3, w * 0.8);
    }
    // worn ink: a few deterministic gaps
    c.globalCompositeOperation = 'destination-out'; for (let j = 0; j < 14; j++) { c.beginPath(); c.arc((rnd(k * 50 + j, 34) - 0.5) * r * 2, (rnd(k * 50 + j, 35) - 0.5) * r * 1.6, r * 0.04 * rnd(j, 36), 0, TAU); c.fill(); }
    c.restore();
  }
};

E.plane = (c, L, S, o) => {
  const per = (L.every || 6) / (o.speed ?? 1), k = Math.floor(S.t / per), p = fr(S.t / per), dir = k % 2 ? -1 : 1;
  const P0 = [-0.12, S.land ? 0.55 : 0.34], P1 = [0.45, S.land ? 0.05 : 0.08], P2 = [1.12, S.land ? 0.3 : 0.2];
  const at = (s) => { const a = (1 - s) * (1 - s), b = 2 * (1 - s) * s, cc = s * s; let x = a * P0[0] + b * P1[0] + cc * P2[0]; if (dir < 0) x = 1 - x; return [x * S.W, (a * P0[1] + b * P1[1] + cc * P2[1]) * S.H]; };
  const col = S.col(o.color || 'soft');
  c.globalAlpha = S.a * 0.7; c.strokeStyle = col; c.lineWidth = S.u * 0.004; c.setLineDash([S.u * 0.014, S.u * 0.012]); c.lineDashOffset = -S.t * S.u * 0.05; c.beginPath();
  for (let s = 0; s <= 30; s++) { const q = Math.max(0, p - 0.38) + (s / 30) * Math.min(p, 0.38); const [x, y] = at(q); s ? c.lineTo(x, y) : c.moveTo(x, y); } c.stroke(); c.setLineDash([]);
  const [x, y] = at(p), [x2, y2] = at(Math.min(1, p + 0.01));
  shape(c, 'plane', x, y, S.u * 0.034 * (1 + 0.2 * S.pulse), Math.atan2(y2 - y, x2 - x), col, S.a, S);
};

E.ticker = (c, L, S, o) => {
  const h = S.u * 0.05, w = S.W * 0.9, x0 = (S.W - w) / 2, y0 = S.land ? S.H * 0.035 : S.H * 0.06, t = S.t * (o.speed ?? 1);
  c.globalAlpha = S.a * 0.7; c.fillStyle = rgba(S.col('ink'), 1); c.beginPath(); roundRect(c, x0, y0, w, h, h * 0.25); c.fill();
  const stub = h * 2.6; c.globalAlpha = S.a * 0.95; c.fillStyle = S.col('accent'); c.beginPath(); roundRect(c, x0, y0, stub, h, h * 0.25); c.fill();
  c.fillStyle = S.col('ink'); c.font = `800 ${Math.round(h * 0.36)}px ui-monospace, Menlo, monospace`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText('BOARDING', x0 + stub / 2, y0 + h / 2, stub * 0.9);
  c.strokeStyle = 'rgba(255,255,255,0.6)'; c.setLineDash([h * 0.08, h * 0.08]); c.lineWidth = h * 0.04; c.beginPath(); c.moveTo(x0 + stub + h * 0.1, y0 + h * 0.12); c.lineTo(x0 + stub + h * 0.1, y0 + h * 0.88); c.stroke(); c.setLineDash([]);
  c.save(); c.beginPath(); c.rect(x0 + stub + h * 0.25, y0, w - stub - h * 0.35, h); c.clip();
  c.font = `600 ${Math.round(h * 0.42)}px ui-monospace, Menlo, monospace`; c.textAlign = 'left'; c.fillStyle = S.col('soft');
  const txt = (L.text || '') + '   ', tw = Math.max(1, c.measureText(txt).width), off = (t * S.u * 0.09) % tw;
  for (let x = x0 + stub + h * 0.3 - off; x < x0 + w; x += tw) c.fillText(txt, x, y0 + h / 2);
  c.restore();
};

E.clouds = (c, L, S, o) => {
  const n = Math.round((L.count || 4) * S.n * (o.density ?? 1)), t = S.t * (o.speed ?? 1);
  for (let i = 0; i < n; i++) {
    const cw = S.u * (0.16 + 0.12 * rnd(i, 41)), x = fr(rnd(i, 42) + t * 0.012 * (0.5 + rnd(i, 43))) * (S.W + 2 * cw) - cw, y = (0.05 + 0.25 * rnd(i, 44)) * S.H;
    for (const [dx, dy, rr] of [[0, 0, 0.5], [0.35, 0.08, 0.38], [-0.35, 0.1, 0.36], [0.1, -0.15, 0.4]]) {
      const g = c.createRadialGradient(x + dx * cw, y + dy * cw, 0, x + dx * cw, y + dy * cw, rr * cw);
      g.addColorStop(0, 'rgba(255,255,255,0.5)'); g.addColorStop(1, 'rgba(255,255,255,0)'); c.globalAlpha = S.a * 0.7; c.fillStyle = g; c.beginPath(); c.arc(x + dx * cw, y + dy * cw, rr * cw, 0, TAU); c.fill();
    }
  }
};

E.globe = (c, L, S, o) => {
  const { cx, cy, r } = corner(S, L.corner || 'br', L.size || 0.13), t = S.t * (o.speed ?? 1), col = S.col(o.color || 'soft'), k = 1 + 0.08 * S.pulse;
  c.globalAlpha = S.a * 0.3; c.fillStyle = S.col('primary'); c.beginPath(); c.arc(cx, cy, r * k, 0, TAU); c.fill();
  c.strokeStyle = col; c.lineWidth = S.u * 0.0025; c.globalAlpha = S.a * 0.85; c.beginPath(); c.arc(cx, cy, r * k, 0, TAU); c.stroke();
  for (let i = 0; i < 6; i++) { const ph = (i * Math.PI) / 6 + t * 0.5, rx = Math.abs(Math.cos(ph)) * r * k; c.globalAlpha = S.a * (0.25 + 0.5 * Math.abs(Math.sin(ph))); c.beginPath(); c.ellipse(cx, cy, Math.max(0.5, rx), r * k, 0, 0, TAU); c.stroke(); }
  for (const la of [-0.55, 0, 0.55]) { c.globalAlpha = S.a * 0.5; c.beginPath(); c.ellipse(cx, cy + la * r * k, r * k * Math.cos(Math.asin(la)), r * k * 0.08, 0, 0, TAU); c.stroke(); }
  const a = t * 1.1; shape(c, 'plane', cx + Math.cos(a) * r * 1.25, cy + Math.sin(a) * r * 0.45, S.u * 0.014, a + Math.PI / 2, S.col('accent'), S.a, S);
};

const DOODLES = [
  (c) => { c.moveTo(-1, 0.2); c.quadraticCurveTo(-0.2, -0.6, 0.8, -0.1); c.moveTo(0.45, -0.4); c.lineTo(0.85, -0.1); c.lineTo(0.4, 0.15); },               // arrow
  (c) => { for (let i = 0; i <= 10; i++) { const a = -Math.PI / 2 + (i * Math.PI * 4) / 5; i ? c.lineTo(Math.cos(a), Math.sin(a)) : c.moveTo(Math.cos(a), Math.sin(a)); } }, // star
  (c) => { c.moveTo(-1, 0); for (let i = 1; i <= 8; i++) c.quadraticCurveTo(-1 + (i - 0.5) * 0.25, i % 2 ? -0.3 : 0.3, -1 + i * 0.25, 0); },              // squiggle
  (c) => { c.ellipse(0, 0, 1, 0.6, -0.15, 0.2, TAU + 0.6); },                                                                                         // scribble circle
  (c) => { for (let i = 0; i <= 40; i++) { const a = i * 0.45, r = (i / 40) * 1; i ? c.lineTo(Math.cos(a) * r, Math.sin(a) * r) : c.moveTo(0, 0); } },   // spiral
  (c) => { c.moveTo(-0.7, -0.2); c.lineTo(-0.2, 0.4); c.lineTo(0.8, -0.6); },                                                                         // tick
];
E.doodles = (c, L, S, o) => {
  const per = 2.4 / (o.speed ?? 1), spots = S.land ? [[0.12, 0.2], [0.88, 0.25], [0.1, 0.8], [0.9, 0.7]] : [[0.16, 0.16], [0.84, 0.22], [0.15, 0.44], [0.85, 0.5], [0.8, 0.12]];
  const boil = Math.floor(S.t / 0.12);
  for (const off of [0, 0.5]) {
    const tt = S.t / per + off, k = Math.floor(tt), p = fr(tt), sp = spots[(k * 3 + (off ? 1 : 0)) % spots.length], d = DOODLES[(k + (off ? 2 : 0)) % DOODLES.length];
    const sz = S.u * 0.06, al = p > 0.8 ? (1 - p) / 0.2 : 1;
    c.save(); c.translate(sp[0] * S.W + (rnd(boil, 51) - 0.5) * S.u * 0.003, sp[1] * S.H + (rnd(boil, 52) - 0.5) * S.u * 0.003); c.rotate((rnd(k, 53) - 0.5) * 0.5); c.scale(sz, sz);
    c.beginPath(); d(c); c.lineWidth = 0.09; c.lineCap = 'round'; c.lineJoin = 'round'; c.strokeStyle = S.col(o.color || (k % 2 ? 'accent' : 'soft'));
    c.globalAlpha = S.a * al; c.setLineDash([clamp(p / 0.3) * 9 + 0.001, 1000]); c.stroke(); c.restore();
  }
};

E.lotus = (c, L, S, o) => {
  const t = S.t * (o.speed ?? 1), bloom = (0.88 + 0.12 * Math.sin(t * 0.9)) * (1 + 0.1 * S.pulse), sz = (L.size || 0.2) * S.u;
  for (const [x, y, rot] of [[S.u * 0.02, S.H - S.u * 0.02, -0.5], [S.W - S.u * 0.02, S.land ? S.u * 0.02 : S.H * 0.06, Math.PI - 0.5]]) {
    c.save(); c.translate(x, y); c.rotate(rot);
    for (let k = 0; k < 7; k++) {
      const a = (-0.9 + k * 0.3) * bloom, len = sz * (0.6 + 0.4 * Math.cos((k - 3) * 0.45));
      c.save(); c.rotate(a); c.scale(len * 0.28, len * 0.5); c.translate(0, -1);
      const g = c.createLinearGradient(0, 1, 0, -1); g.addColorStop(0, rgba(S.col('primary'), 0.7)); g.addColorStop(1, rgba(S.col(o.color || 'accent'), 0.85));
      c.globalAlpha = S.a * 0.8; c.fillStyle = g; petalPath(c); c.fill(); c.globalAlpha = S.a * 0.45; c.strokeStyle = '#ffffff'; c.lineWidth = 0.04; c.stroke(); c.restore();
    }
    c.restore();
  }
};

E.swirl = (c, L, S, o) => {
  const { cx, cy, r } = corner(S, L.corner || 'bl', L.size || 0.3), t = S.t * (o.speed ?? 1);
  c.save(); c.translate(cx, cy); c.rotate(t * 0.25); c.lineCap = 'round';
  const g = c.createLinearGradient(-r, -r, r, r); g.addColorStop(0, '#fff3e6'); g.addColorStop(1, S.col(o.color || 'accent'));
  for (const [lw, al, col] of [[0.16, 0.45, g], [0.05, 0.55, 'rgba(255,255,255,0.9)']]) {
    c.beginPath(); for (let i = 0; i <= 80; i++) { const a = (i / 80) * TAU * 2, rr = r * (0.12 + (i / 80) * 0.85); i ? c.lineTo(Math.cos(a) * rr, Math.sin(a) * rr) : c.moveTo(Math.cos(a) * rr, Math.sin(a) * rr); }
    c.lineWidth = r * lw * (1 + 0.15 * S.pulse); c.strokeStyle = col; c.globalAlpha = S.a * al; c.stroke();
  }
  c.restore();
};

E.sweep = (c, L, S, o) => {
  const per = (L.every || 4) / (o.speed ?? 1), p = fr(S.t / per), x = (-0.4 + 1.8 * easeIO(p / 0.6)) * S.W; if (p > 0.6) return;
  c.save(); c.translate(x, S.H / 2); c.rotate(0.38); const bw = S.u * 0.3;
  const g = c.createLinearGradient(-bw, 0, bw, 0); g.addColorStop(0, rgba(S.col(o.color || L.color || 'soft'), 0)); g.addColorStop(0.5, rgba(S.col(o.color || L.color || 'soft'), 0.42)); g.addColorStop(1, rgba(S.col(o.color || L.color || 'soft'), 0));
  c.globalAlpha = S.a; c.fillStyle = g; c.fillRect(-bw, -S.H, bw * 2, S.H * 2); c.restore();
};

E.hazard = (c, L, S, o) => {
  const h = S.u * 0.02, t = S.t * (o.speed ?? 1), sw = h * 1.4, off = (t * S.u * 0.06) % (sw * 2);
  for (const y0 of [S.land ? 0 : S.H * 0.045, S.H - h - (S.land ? 0 : S.H * 0.004)]) {
    c.save(); c.beginPath(); c.rect(0, y0, S.W, h); c.clip(); c.globalAlpha = S.a * 0.92; c.fillStyle = '#ffbe0b'; c.fillRect(0, y0, S.W, h); c.fillStyle = '#111111';
    for (let x = -sw * 2 - off; x < S.W + sw * 2; x += sw * 2) { c.beginPath(); c.moveTo(x, y0 + h); c.lineTo(x + sw, y0 + h); c.lineTo(x + sw + h, y0); c.lineTo(x + h, y0); c.closePath(); c.fill(); }
    c.restore();
  }
};

E.crossPulse = (c, L, S, o) => {
  const { cx, cy, r } = corner(S, L.corner || 'tr', L.size || 0.1), t = S.t * (o.speed ?? 1);
  const hb = (x) => { const f = fr(x / 1.05); return Math.exp(-Math.pow((f - 0.05) / 0.035, 2)) + 0.6 * Math.exp(-Math.pow((f - 0.22) / 0.035, 2)); };
  const k = 1 + 0.12 * Math.max(hb(t), S.pulse);
  c.globalAlpha = S.a * 0.5 * (1 - fr(t / 1.05)); c.strokeStyle = S.col('accent'); c.lineWidth = S.u * 0.004; c.beginPath(); c.arc(cx, cy, r * (0.9 + fr(t / 1.05) * 0.9), 0, TAU); c.stroke();
  shape(c, 'cross', cx, cy, r * 0.62 * k, 0, '#ffffff', S.a, S); shape(c, 'cross', cx, cy, r * 0.5 * k, 0, S.col(o.color || 'accent'), S.a, S);
  // ECG line under the cross, drawing itself
  const w = r * 2.6, x0 = cx + r - w, y0 = cy + r * 1.35, p = fr(t / 2.1);
  c.globalAlpha = S.a * 0.9; c.strokeStyle = S.col('soft'); c.lineWidth = S.u * 0.003; c.lineJoin = 'round'; c.beginPath();
  for (let i = 0; i <= 60 * p; i++) { const f = i / 60, x = x0 + f * w, ff = fr(f * 2), y = y0 - (ff > 0.4 && ff < 0.46 ? (ff - 0.4) / 0.06 : ff >= 0.46 && ff < 0.52 ? 1 - (ff - 0.46) / 0.03 : 0) * r * 0.7; i ? c.lineTo(x, y) : c.moveTo(x, y); }
  c.stroke();
};

E.grid = (c, L, S, o) => {
  const sp = S.u * 0.12 / Math.max(0.4, o.density ?? 1), t = S.t * (o.speed ?? 1), off = (t * S.u * 0.01) % sp, col = S.col(o.color || L.color || 'soft');
  c.globalAlpha = S.a * 0.12; c.strokeStyle = col; c.lineWidth = S.u * 0.0012; c.beginPath();
  for (let x = -off; x < S.W; x += sp) { c.moveTo(x, 0); c.lineTo(x, S.H); }
  for (let y = -off; y < S.H; y += sp) { c.moveTo(0, y); c.lineTo(S.W, y); }
  c.stroke();
  c.globalAlpha = S.a * 0.35; c.lineWidth = S.u * 0.0018; c.beginPath();
  for (let x = -off + sp * 2; x < S.W; x += sp * 3) for (let y = -off + sp * 2; y < S.H; y += sp * 3) { c.moveTo(x - sp * 0.08, y); c.lineTo(x + sp * 0.08, y); c.moveTo(x, y - sp * 0.08); c.lineTo(x, y + sp * 0.08); }
  c.stroke();
};

E.dataDots = (c, L, S, o) => {
  const t = S.t * (o.speed ?? 1), n = Math.round(10 * S.n * (o.density ?? 1)), sp = S.u * 0.12, col = S.col(o.color || 'accent');
  for (let i = 0; i < n; i++) {
    const hor = rnd(i, 61) < 0.5, line = Math.floor(rnd(i, 62) * 20) * sp, p = fr(rnd(i, 63) + t * 0.06 * (0.5 + rnd(i, 64)));
    const x = hor ? p * S.W : line % S.W, y = hor ? line % S.H : p * S.H;
    shape(c, 'dust', x, y, S.u * 0.004 * (1 + 0.6 * S.pulse), 0, col, S.a * 0.85, S);
  }
  const { cx, cy, r } = corner(S, L.corner || 'tr', 0.22), x0 = cx - r, y0 = cy + r * 0.5, w = r * 2, h = r, p = clamp(fr(t / 4) / 0.6);
  c.globalAlpha = S.a * 0.5; c.strokeStyle = S.col('soft'); c.lineWidth = S.u * 0.0018; c.beginPath(); c.moveTo(x0, y0 - h); c.lineTo(x0, y0); c.lineTo(x0 + w, y0); c.stroke();
  const pts = [0.15, 0.3, 0.25, 0.5, 0.45, 0.7, 0.62, 0.9];
  for (let i = 0; i < pts.length; i++) { const bh = pts[i] * h * clamp(p * 1.3 - i * 0.05); c.globalAlpha = S.a * 0.25; c.fillStyle = col; c.fillRect(x0 + (i + 0.2) * (w / pts.length), y0 - bh, (w / pts.length) * 0.55, bh); }
  c.globalAlpha = S.a; c.strokeStyle = col; c.lineWidth = S.u * 0.004; c.beginPath();
  for (let i = 0; i <= (pts.length - 1) * p; i++) { const x = x0 + (i + 0.5) * (w / pts.length), y = y0 - pts[i] * h; i ? c.lineTo(x, y) : c.moveTo(x, y); } c.stroke();
};

E.steam = (c, L, S, o) => {
  const n = Math.round((L.count || 4) * (o.density ?? 1)), t = S.t * (o.speed ?? 1);
  for (let i = 0; i < n; i++) {
    const x0 = S.W * (0.15 + 0.7 * rnd(i, 71)), env = Math.sin(fr(t * 0.12 + rnd(i, 72)) * Math.PI);
    for (const [lw, al] of [[0.05, 0.08], [0.028, 0.14], [0.012, 0.2]]) {
      c.beginPath();
      for (let j = 0; j <= 24; j++) { const f = j / 24, y = S.H * (0.98 - f * 0.62), x = x0 + Math.sin(f * 5 - t * 1.4 + i * 2) * S.u * 0.05 * (f + 0.25); j ? c.lineTo(x, y) : c.moveTo(x, y); }
      const g = c.createLinearGradient(0, S.H, 0, S.H * 0.35); g.addColorStop(0, 'rgba(255,255,255,0)'); g.addColorStop(0.35, `rgba(255,255,255,${al})`); g.addColorStop(1, 'rgba(255,255,255,0)');
      c.strokeStyle = g; c.lineWidth = S.u * lw; c.lineCap = 'round'; c.globalAlpha = S.a * env; c.stroke();
    }
  }
};

E.streaks = (c, L, S, o) => {
  const n = Math.round((L.count || 7) * S.n * (o.density ?? 1)), t = S.t * (o.speed ?? 1);
  for (let i = 0; i < n; i++) {
    const per = 0.9 + rnd(i, 81), p = fr(t / per + rnd(i, 82)), k = Math.floor(t / per + rnd(i, 82));
    const y = rnd(i * 13 + k, 83) * S.H, len = S.u * (0.18 + 0.2 * rnd(i, 84)), x = -len + p * (S.W + len * 2), ang = -0.35;
    c.save(); c.translate(x, y); c.rotate(ang);
    const g = c.createLinearGradient(-len, 0, 0, 0), col = S.col(i % 2 ? 'accent' : (o.color || 'primary'));
    g.addColorStop(0, rgba(col, 0)); g.addColorStop(1, rgba(col, 0.9)); c.strokeStyle = g; c.lineWidth = S.u * (0.004 + 0.004 * rnd(i, 85)) * (1 + S.pulse); c.lineCap = 'round';
    c.globalAlpha = S.a * (0.6 + 0.4 * S.pulse); c.beginPath(); c.moveTo(-len, 0); c.lineTo(0, 0); c.stroke(); c.restore();
  }
};

E.blueprint = (c, L, S, o) => {
  const { cx, cy, r } = corner(S, L.corner || 'tr', L.size || 0.3), t = S.t * (o.speed ?? 1), p = clamp(fr(t / 6) / 0.7), col = S.col(o.color || 'accent');
  const x0 = cx - r, y0 = cy - r * 0.75, w = r * 2, h = r * 1.5;
  const segs = [[0, 0, 1, 0], [1, 0, 1, 1], [1, 1, 0, 1], [0, 1, 0, 0], [0.55, 0, 0.55, 0.62], [0, 0.55, 0.38, 0.55], [0.55, 0.62, 1, 0.62]];
  c.globalAlpha = S.a * 0.85; c.strokeStyle = col; c.lineWidth = S.u * 0.0035; c.lineCap = 'square'; c.beginPath();
  segs.forEach(([a, b, cc, d], i) => { const q = clamp(p * segs.length - i); if (q <= 0) return; c.moveTo(x0 + a * w, y0 + b * h); c.lineTo(x0 + (a + (cc - a) * q) * w, y0 + (b + (d - b) * q) * h); });
  c.stroke();
  if (p > 0.6) { c.globalAlpha = S.a * 0.7 * clamp((p - 0.6) / 0.2); c.beginPath(); c.arc(x0 + 0.38 * w, y0 + 0.55 * h, w * 0.14, -Math.PI / 2, 0); c.stroke(); c.lineWidth = S.u * 0.0018; c.beginPath(); c.moveTo(x0, y0 - h * 0.1); c.lineTo(x0 + w, y0 - h * 0.1); c.moveTo(x0, y0 - h * 0.15); c.lineTo(x0, y0 - h * 0.05); c.moveTo(x0 + w, y0 - h * 0.15); c.lineTo(x0 + w, y0 - h * 0.05); c.stroke();
    c.fillStyle = col; c.font = `600 ${Math.round(S.u * 0.018)}px ui-monospace, Menlo, monospace`; c.textAlign = 'center'; c.fillText('12.40 m', x0 + w / 2, y0 - h * 0.13); }
};

E.fireworks = (c, L, S, o) => {
  const evs = events(S, (L.every || 2.5) / (o.density ?? 1), 0.2), cols = (L.colors || ['accent', 'soft']).map((k) => S.col(k)), life = 1.5;
  for (const e of lastEvents(evs, S.t, life + 0.35)) {
    const k = evs.indexOf(e), cx = (0.15 + 0.7 * rnd(k, 91)) * S.W, cy = (0.08 + 0.25 * rnd(k, 92)) * S.H, dt = S.t - e;
    if (dt < 0.35) { const yy = S.H * 0.6 + (cy - S.H * 0.6) * easeOut(dt / 0.35); shape(c, 'dust', cx, yy, S.u * 0.004, 0, cols[0], S.a, S); continue; }
    const d = dt - 0.35, fade = 1 - d / life; if (fade <= 0) continue;
    for (let i = 0; i < 30; i++) {
      const ang = (i / 30) * TAU + rnd(k, 93), v = S.u * (0.18 + 0.08 * rnd(i, 94)), g = S.u * 0.12;
      const x = cx + Math.cos(ang) * v * easeOut(d / 0.9), y = cy + Math.sin(ang) * v * easeOut(d / 0.9) + 0.5 * g * d * d;
      shape(c, 'dust', x, y, S.u * 0.0045 * (0.6 + fade), 0, cols[(i + k) % cols.length], S.a * fade, S);
    }
  }
};

E.neon = (c, L, S, o) => {
  const t = S.t * (o.speed ?? 1), m = S.u * 0.035, col = S.col(o.color || 'accent');
  const flick = fr(t * 0.37) < 0.06 && Math.sin(t * 60) > 0 ? 0.35 : 1, k = flick * (0.85 + 0.15 * Math.sin(t * 3)) + 0.4 * S.pulse;
  for (const [lw, al] of [[0.02, 0.12], [0.009, 0.3], [0.003, 0.95]]) { c.globalAlpha = S.a * al * clamp(k); c.strokeStyle = lw < 0.004 ? '#ffffff' : col; c.lineWidth = S.u * lw; c.beginPath(); roundRect(c, m, S.land ? m : S.H * 0.05, S.W - 2 * m, S.H - (S.land ? 2 * m : S.H * 0.05 + m), S.u * 0.04); c.stroke(); }
  if (L.text) {
    const y = S.land ? S.H * 0.14 : S.H * 0.13, fs = S.u * 0.075; c.font = `900 ${Math.round(fs)}px "Bebas Neue", "Anton", Impact, sans-serif`; c.textAlign = 'center'; c.textBaseline = 'middle';
    for (const [lw, al] of [[0.018, 0.2], [0.007, 0.45]]) { c.globalAlpha = S.a * al * clamp(k); c.strokeStyle = col; c.lineWidth = S.u * lw; c.strokeText(L.text, S.W / 2, y, S.W * 0.8); }
    c.globalAlpha = S.a * clamp(k); c.fillStyle = '#ffffff'; c.fillText(L.text, S.W / 2, y, S.W * 0.8);
  }
};

E.tags = (c, L, S, o) => {
  const t = S.t * (o.speed ?? 1), x0 = S.W * 0.82, len = S.H * (S.land ? 0.18 : 0.12), ang = 0.22 * Math.sin(t * 2.1) + 0.25 * S.pulse * Math.sin(t * 9);
  const tw = S.u * 0.2, th = S.u * 0.1, col = S.col(o.color || 'primary'), top = S.land ? 0 : S.H * 0.04;
  c.save(); c.translate(x0, top); c.rotate(ang);
  c.globalAlpha = S.a; c.strokeStyle = 'rgba(255,255,255,0.85)'; c.lineWidth = S.u * 0.003; c.beginPath(); c.moveTo(0, 0); c.lineTo(0, len); c.stroke();
  c.translate(0, len); c.rotate(Math.PI / 2);
  c.fillStyle = col; c.beginPath(); c.moveTo(0, 0); c.lineTo(th * 0.45, -th / 2); c.lineTo(tw, -th / 2); c.lineTo(tw, th / 2); c.lineTo(th * 0.45, th / 2); c.closePath(); c.fill();
  c.fillStyle = '#ffffff'; c.beginPath(); c.arc(th * 0.35, 0, th * 0.1, 0, TAU); c.fill();
  c.rotate(-Math.PI / 2); c.fillStyle = S.col('accent'); c.font = `900 ${Math.round(th * 0.5)}px "Anton", Impact, sans-serif`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(L.text || '-50%', 0, th * 0.55 + tw * 0.45, tw * 0.8);
  c.restore();
};

E.banner = (c, L, S, o) => {
  const t = S.t * (o.speed ?? 1), w = S.u * 0.5, h = S.u * 0.075, top = S.land ? 0 : S.H * 0.035;
  c.save(); c.translate(0, top); c.beginPath(); c.rect(0, 0, S.W, S.H); c.clip(); c.translate(w * 0.28, w * 0.28); c.rotate(-Math.PI / 4);
  c.globalAlpha = S.a; c.fillStyle = S.col(o.color || 'primary'); c.fillRect(-w, -h / 2, w * 2, h);
  const sx = (fr(t / 2.5) * 3 - 1) * w; const g = c.createLinearGradient(sx - h, 0, sx + h, 0); g.addColorStop(0, 'rgba(255,255,255,0)'); g.addColorStop(0.5, 'rgba(255,255,255,0.45)'); g.addColorStop(1, 'rgba(255,255,255,0)'); c.fillStyle = g; c.fillRect(-w, -h / 2, w * 2, h);
  c.fillStyle = S.col('accent'); c.font = `900 ${Math.round(h * 0.62)}px "Anton", Impact, sans-serif`; c.textAlign = 'center'; c.textBaseline = 'middle'; c.fillText(L.text || 'SALE', 0, h * 0.04, w);
  c.restore();
};

E.lanterns = (c, L, S, o) => {
  const n = Math.round((L.count || 3) * (o.density ?? 1)), t = S.t * (o.speed ?? 1), xs = [0.84, 0.66, 0.16, 0.34];
  for (let i = 0; i < n; i++) {
    const x = xs[i % xs.length] * S.W, len = S.H * (S.land ? 0.1 : 0.07) * (0.6 + 0.8 * rnd(i, 101)), top = S.land ? 0 : S.H * 0.03, ang = 0.08 * Math.sin(t * 1.2 + i * 1.7);
    const s = S.u * 0.06 * (0.8 + 0.35 * rnd(i, 102)), gold = S.col(o.color || 'accent');
    c.save(); c.translate(x, top); c.rotate(ang); c.globalAlpha = S.a; c.strokeStyle = gold; c.lineWidth = S.u * 0.002; c.beginPath(); c.moveTo(0, 0); c.lineTo(0, len); c.stroke(); c.translate(0, len + s * 1.4);
    const fl = clamp(0.8 + 0.2 * Math.sin(t * 7 + i * 3) * Math.sin(t * 3.1 + i) + 0.35 * S.pulse);
    const halo = c.createRadialGradient(0, 0, 0, 0, 0, s * 3); halo.addColorStop(0, rgba('#ffd27a', 0.42 * fl)); halo.addColorStop(1, rgba('#ffd27a', 0)); c.fillStyle = halo; c.beginPath(); c.arc(0, 0, s * 3, 0, TAU); c.fill();
    c.fillStyle = gold; c.beginPath(); c.arc(0, -s * 1.55, s * 0.12, 0, TAU); c.fill();                                   // ring
    c.beginPath(); c.moveTo(-s * 0.55, -s * 0.95); c.quadraticCurveTo(0, -s * 1.75, s * 0.55, -s * 0.95); c.closePath(); c.fill();   // dome
    c.fillRect(-s * 0.62, -s * 1.0, s * 1.24, s * 0.12);
    const body = () => { c.beginPath(); c.moveTo(-s * 0.5, -s * 0.88); c.lineTo(s * 0.5, -s * 0.88); c.lineTo(s * 0.68, -s * 0.1); c.lineTo(s * 0.42, s * 0.78); c.lineTo(-s * 0.42, s * 0.78); c.lineTo(-s * 0.68, -s * 0.1); c.closePath(); };
    const pane = c.createRadialGradient(0, -s * 0.05, s * 0.05, 0, -s * 0.05, s * 0.9); pane.addColorStop(0, rgba('#fff6d6', fl)); pane.addColorStop(0.55, rgba('#ffc75a', 0.85 * fl)); pane.addColorStop(1, rgba('#c9792b', 0.75));
    body(); c.fillStyle = pane; c.fill(); c.lineWidth = s * 0.09; c.strokeStyle = gold; c.stroke();
    c.save(); body(); c.clip(); c.lineWidth = s * 0.05; c.beginPath();
    for (const k of [-0.25, 0.25]) { c.moveTo(s * k, -s * 0.88); c.lineTo(s * k * 0.85, s * 0.78); }
    for (let d = -1.2; d <= 1.2; d += 0.4) { c.moveTo(s * d, -s * 0.9); c.lineTo(s * (d + 0.8), s * 0.8); c.moveTo(s * d, s * 0.8); c.lineTo(s * (d + 0.8), -s * 0.9); }
    c.globalAlpha = S.a * 0.55; c.stroke(); c.restore();
    c.globalAlpha = S.a; c.fillStyle = gold; c.fillRect(-s * 0.48, s * 0.76, s * 0.96, s * 0.14); c.beginPath(); c.moveTo(-s * 0.3, s * 0.9); c.lineTo(0, s * 1.35); c.lineTo(s * 0.3, s * 0.9); c.closePath(); c.fill();
    c.restore();
  }
};

E.crescent = (c, L, S, o) => {
  const { cx, cy, r } = corner(S, L.corner || 'tl', L.size || 0.11), t = S.t * (o.speed ?? 1), col = S.col(o.color || 'accent');
  const g = c.createRadialGradient(cx, cy, 0, cx, cy, r * 2.2); g.addColorStop(0, rgba(col, 0.25 + 0.2 * S.pulse)); g.addColorStop(1, rgba(col, 0)); c.globalAlpha = S.a; c.fillStyle = g; c.beginPath(); c.arc(cx, cy, r * 2.2, 0, TAU); c.fill();
  shape(c, 'crescent', cx, cy, r, -0.4 + Math.sin(t * 0.4) * 0.05, col, S.a, S);
  shape(c, 'star5', cx + r * 0.75, cy - r * 0.15, r * 0.24 * (0.85 + 0.15 * Math.sin(t * 2.5)), 0, col, S.a, S);
};

E.burst = (c, L, S, o) => {
  const evs = events(S, (L.every || 2) / (o.density ?? 1), 0.3), cols = (L.colors || ['primary', 'accent', 'soft']).map((k) => S.col(k)), life = 1.6;
  for (const e of lastEvents(evs, S.t, life)) {
    const k = evs.indexOf(e), d = S.t - e, fade = 1 - d / life;
    for (const side of [0, 1]) {
      const ox = side ? S.W * 0.98 : S.W * 0.02, oy = S.H * (S.land ? 0.98 : 0.86);
      for (let i = 0; i < 22; i++) {
        const a = -Math.PI / 2 + (side ? -1 : 1) * (0.25 + 0.6 * rnd(i + k * 40, 111)), v = S.u * (0.9 + 0.7 * rnd(i, 112)), g = S.u * 1.4;
        const x = ox + Math.cos(a) * v * d, y = oy + Math.sin(a) * v * d + 0.5 * g * d * d;
        shape(c, 'confetti', x, y, S.u * 0.008, d * 8 * (rnd(i, 113) - 0.5), cols[(i + side) % cols.length], S.a * fade, S, Math.cos(d * 12 + i));
      }
    }
  }
};

E.glow = (c, L, S, o) => {
  const g0 = S.beatOn && S.beats.length ? 0.1 + 0.55 * S.pulse : 0.12 + 0.05 * Math.sin(S.t * 1.3), col = S.col(o.color || L.color || 'primary'), d = S.u * 0.11;
  if (g0 <= 0.005) return;
  c.globalAlpha = clamp(S.a * g0 * 1.4);
  for (const [x0, y0, x1, y1, rx, ry, rw, rh] of [[0, 0, 0, d, 0, 0, S.W, d], [0, S.H, 0, S.H - d, 0, S.H - d, S.W, d], [0, 0, d, 0, 0, 0, d, S.H], [S.W, 0, S.W - d, 0, S.W - d, 0, d, S.H]]) {
    const g = c.createLinearGradient(x0, y0, x1, y1); g.addColorStop(0, rgba(col, 0.9)); g.addColorStop(1, rgba(col, 0)); c.fillStyle = g; c.fillRect(rx, ry, rw, rh);
  }
};

export const ELEMENT_KINDS = Object.keys(E);

// ── one frame of a theme ──
/** palette helpers for one style (theme colours or the brand kit's, with per-layer overrides) */
function paletteOf(style) {
  const C = Object.assign({ primary: '#1d3557', accent: '#ffd166', soft: '#ffffff', ink: '#111111' }, style.colors || {});
  const ex = C.extra || [];
  const col = (k) => (k && k[0] === '#' ? k : NAMED[k] || (/^c\d$/.test(k || '') ? ex[+k[1]] || [C.primary, C.soft, C.accent, C.ink][+k[1]] : C[k]) || C.soft);
  return { C, col, cols: (L, o) => { const list = (L.colors || [L.color || 'soft']).map(col); if (o.color) list[0] = col(o.color); return list; } };
}
function faceAt(faces, t) {
  if (!faces || !faces.length) return [];
  if (faces[0].t == null) return faces;
  let best = null, bd = 1e9;
  for (let i = 0; i < faces.length; i++) { const d = Math.abs(faces[i].t - t); if (d < bd) { bd = d; best = i; } }
  if (bd > 1.2) return [];
  const a = faces[best], j = faces[best].t <= t ? best + 1 : best - 1, b = faces[j];
  if (!b || Math.abs(b.t - a.t) > 1.5) return [a];
  const f = clamp((t - a.t) / (b.t - a.t || 1));
  return [{ x: a.x + (b.x - a.x) * f, y: a.y + (b.y - a.y) * f, w: Math.max(a.w, b.w), h: Math.max(a.h, b.h) }];
}
/** the boxes a theme must stay off at t (canvas px): faces + caption blocks */
export function avoidBoxes(project, style, t, measureCtx) {
  const W = project.canvas.w, H = project.canvas.h, out = [];
  for (const f of faceAt(style.faces, t)) out.push({ kind: 'face', x: (f.x - f.w / 2) * W, y: (f.y - f.h / 2) * H, w: f.w * W, h: f.h * H });
  try {
    const cap = (project.tracks || []).find((tr) => tr.kind === 'caption' && !tr.hidden)?.items.find((i) => i.type === 'caption' && t >= i.t0 && t < i.t0 + (i.out - i.in) / (i.speed || 1));
    if (cap && measureCtx) for (const b of captionBlocks(measureCtx, project, t, cap)) out.push({ kind: 'caption', ...b.box });
  } catch { /* captions not loaded: nothing to avoid */ }
  return out;
}

let LAYERS = [];
function layerCanvas(w, h) {
  let rec = LAYERS.find((r) => r.c.width === w && r.c.height === h && !r.busy);
  if (!rec) { const c = typeof document !== 'undefined' ? Object.assign(document.createElement('canvas'), { width: w, height: h }) : new OffscreenCanvas(w, h); rec = { c, x: c.getContext('2d') }; LAYERS.unshift(rec); LAYERS = LAYERS.slice(0, 4); }
  return rec;
}

/**
 * Draw a theme overlay into its own layer canvas (devW × devH device px, `xf` = the canvas-unit → device transform) and
 * cut the face and caption boxes out of it. Returns { canvas, boxes }.
 */
export function renderThemeLayer(project, item, t, devW, devH, xf) {
  const style = item.style || {}, rec = layerCanvas(devW, devH), c = rec.x;
  c.setTransform(1, 0, 0, 1, 0, 0); c.globalAlpha = 1; c.globalCompositeOperation = 'source-over'; c.clearRect(0, 0, devW, devH);
  c.setTransform(xf.a, xf.b, xf.c, xf.d, xf.e, xf.f);
  const W = project.canvas.w, H = project.canvas.h, I = INTENSITY[style.intensity] || INTENSITY.normal, P = paletteOf(style);
  const beats = style.beat ? style.beats || [] : [];
  const S = { t, W, H, u: Math.min(W, H), land: W > H * 1.05, a: I.a, n: I.n, beats, beatOn: !!style.beat, pulse: pulseAt(beats, t), col: P.col, cols: P.cols, colors: P.C, end: (item.out - item.in) + (item.t0 || 0) };
  for (const L of style.defs || []) {
    const o = (style.layers || {})[L.id] || {}; if (o.on === false) continue;
    const fn = E[L.kind]; if (!fn) continue;
    c.save();
    try { fn(c, L, S, o); } catch (e) { if (!warned.has(L.kind)) { warned.add(L.kind); console.warn('[themes]', L.kind, e); } }
    c.restore();
  }
  let boxes = [];
  if (style.safe !== false) {
    boxes = avoidBoxes(project, style, t, c);
    c.save(); c.globalCompositeOperation = 'destination-out';
    for (const b of boxes) {
      if (b.kind === 'face') {
        // an ellipse that contains the whole (padded) face box, fading out softly beyond it — no hard edge on screen
        const pad = 0.12 * Math.max(b.w, b.h) + S.u * 0.01, rx = (b.w / 2 + pad) * Math.SQRT2, ry = (b.h / 2 + pad) * Math.SQRT2, f = 1 + (S.u * 0.12) / Math.max(rx, ry);
        c.save(); c.translate(b.x + b.w / 2, b.y + b.h / 2); c.scale(rx, ry);
        const g = c.createRadialGradient(0, 0, 0, 0, 0, f); g.addColorStop(0, 'rgba(0,0,0,1)'); g.addColorStop(1 / f, 'rgba(0,0,0,1)'); g.addColorStop(1, 'rgba(0,0,0,0)');
        c.globalAlpha = 1; c.fillStyle = g; c.beginPath(); c.arc(0, 0, f, 0, TAU); c.fill(); c.restore();
      } else {
        const pad = S.u * 0.012, f = S.u * 0.035;
        c.globalAlpha = 1; c.beginPath(); roundRect(c, b.x - pad, b.y - pad, b.w + 2 * pad, b.h + 2 * pad, pad); c.fill();
        for (let k = 1; k <= 3; k++) { const e = pad + (f * k) / 3; c.globalAlpha = 0.45; c.beginPath(); roundRect(c, b.x - e, b.y - e, b.w + 2 * e, b.h + 2 * e, e); c.fill(); }
      }
    }
    c.restore();
  }
  return { canvas: rec.c, boxes };
}
const warned = new Set();

registerLayer('theme.overlay', (ctx, item, t, project, env) => {
  ctx.translate(-env.box.x, -env.box.y);
  const m = ctx.getTransform(), cv = ctx.canvas;
  const { canvas } = renderThemeLayer(project, item, t, cv.width, cv.height, m);
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.filter = 'none'; ctx.drawImage(canvas, 0, 0); ctx.restore();
});

// ── intro sting / outro ──
function heroAt(c, style, kind, x, y, r, rot, col, a) {
  const S = { u: 1 };
  if (kind === 'icon') return shape(c, 'icon', x, y, r, rot, col, a, S, 1, (style.icons || [])[0]);
  shape(c, kind || 'star4', x, y, r, rot, col, a, S);
}
function card(c, style, W, H, holeR, alpha) {
  const P = paletteOf(style), g = c.createLinearGradient(0, 0, W, H);
  g.addColorStop(0, P.col('primary')); g.addColorStop(1, rgba(P.col('accent'), 1));
  c.save(); c.globalAlpha *= alpha; c.fillStyle = g; c.beginPath(); c.rect(0, 0, W, H);
  if (holeR > 0) c.arc(W / 2, H / 2, holeR, 0, TAU, true);
  c.fill('evenodd');
  c.fillStyle = 'rgba(0,0,0,0.18)'; c.fill('evenodd');
  if (holeR > 0) { c.strokeStyle = P.col('soft'); c.lineWidth = Math.min(W, H) * 0.012; c.beginPath(); c.arc(W / 2, H / 2, holeR, 0, TAU); c.stroke(); }
  c.restore();
}
registerLayer('theme.sting', (ctx, item, t, project, env) => {
  ctx.translate(-env.box.x, -env.box.y);
  const s = item.style || {}, W = env.w, H = env.h, u = Math.min(W, H), d = Math.max(0.3, item.out - item.in), p = clamp(env.local / d), diag = Math.hypot(W, H) / 2;
  const P = paletteOf(s);
  card(ctx, s, W, H, diag * easeIO((p - 0.32) / 0.6) * 1.02, 1);
  const k = easeOut(p / 0.55);
  for (let i = 0; i < 16; i++) {
    const a = (i / 16) * TAU + rnd(i, 121) * 0.3, dist = u * (0.06 + 0.55 * k * (0.6 + 0.4 * rnd(i, 122)));
    heroAt(ctx, s, s.hero, W / 2 + Math.cos(a) * dist, H / 2 + Math.sin(a) * dist, u * (0.02 + 0.02 * rnd(i, 123)) * (1 - p * 0.5), a + p * 3, i % 2 ? P.col('soft') : P.col('accent'), clamp(1.4 - p * 1.4));
  }
  heroAt(ctx, s, s.hero, W / 2, H / 2, u * 0.11 * back(p / 0.35) * (1 - easeIO((p - 0.45) / 0.4)), 0, P.col('soft'), 1);
});
registerLayer('theme.outro', (ctx, item, t, project, env) => {
  ctx.translate(-env.box.x, -env.box.y);
  const s = item.style || {}, W = env.w, H = env.h, u = Math.min(W, H), d = Math.max(0.3, item.out - item.in), p = clamp(env.local / d), diag = Math.hypot(W, H) / 2;
  const P = paletteOf(s);
  card(ctx, s, W, H, diag * (1 - easeIO(p / 0.75)) * 1.02, 1);
  const k = easeIO(p / 0.8);
  for (let i = 0; i < 16; i++) {
    const a = (i / 16) * TAU + rnd(i, 131) * 0.3, dist = u * (0.75 - 0.62 * k) * (0.6 + 0.4 * rnd(i, 132));
    heroAt(ctx, s, s.hero, W / 2 + Math.cos(a) * dist, H / 2 + Math.sin(a) * dist, u * 0.022, a - p * 3, i % 2 ? P.col('soft') : P.col('accent'), clamp(p * 3) * (1 - clamp((p - 0.85) / 0.15) * 0.6));
  }
  if (p > 0.7) heroAt(ctx, s, s.hero, W / 2, H / 2, u * 0.12 * back((p - 0.7) / 0.25), 0, P.col('soft'), 1);
});
