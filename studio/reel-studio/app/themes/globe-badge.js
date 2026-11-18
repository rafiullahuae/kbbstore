// KBB Studio · themes · COUNTRY GLOBE BADGES: a round animated badge for a corner of the video — a globe turning to the
// country (or region), the country lit, its flag ring, its name in English / Arabic / Urdu / Hindi, the flag inside if
// wanted. Six looks: glass globe, flat map disc, flag ring, neon outline, stamp seal, minimal.
//
//   theme.el, style.el 'cbadge', style.variant = a BADGE_LOOKS id
//   style.code   a country ('de', 'gb' ... every flag in capsules/flags-4x3.json) or a region ('europe', 'gulf', 'asia',
//                'north-america', 'oceania')
//   style.lang   'en' | 'ar' | 'both' | 'ur' | 'hi'      style.flagIn  the flag inside the circle (glass, neon, minimal)
//   item.auto 'cbadge' (+ style.mention) when "Badge every country I mention" placed it (themes/el2.js re-derives those
//   when the captions change, in the same undo step)
//
// Globe pictures are rendered once per (look, turn quantised to ½°, size) into a small LRU and reused, so a badge that
// runs for the whole reel costs a drawImage per frame. Pure function of the item and its time: preview = export.
import { registerPrepare } from '../core/render.js';
import * as A from './art.js';
import { tx, noteSlot } from './text.js';
import { loadFlags, flagCanvas } from './capsules.js';
import { REGIONS, centreOf, placeName, isRegion } from './country-words.js';
import { COUNTRIES as PACKS } from '../auto/countries/countries.js';

const { clamp, easeOut, easeIn, back, TAU, mix, rgba, onColor, lum, rnd } = A;

export const BADGE_LOOKS = [
  { id: 'glass', label: 'Glass globe' },
  { id: 'flat', label: 'Flat map disc' },
  { id: 'flagring', label: 'Flag ring' },
  { id: 'neon', label: 'Neon outline' },
  { id: 'stamp', label: 'Stamp seal' },
  { id: 'minimal', label: 'Minimal' },
];
export const lookById = (id) => BADGE_LOOKS.find((x) => x.id === id) || BADGE_LOOKS[0];
const packOf = new Map(PACKS.map((c) => [c.code, c]));

// ── assets: flags (the capsules' bundled sprite), the 33 packs' map outlines ──
const MAPS = new Map();
function loadMapOf(code) {
  if (MAPS.has(code)) return MAPS.get(code).ready;
  const rec = { paths: null };
  rec.ready = packOf.has(code) ? fetch(`/brands/planet-careers/countries/maps/${code}.svg`).then((r) => (r.ok ? r.text() : '')).then((s) => { rec.paths = [...s.matchAll(/\sd="([^"]+)"/g)].map((m) => new Path2D(m[1])); }).catch(() => { rec.paths = []; }) : Promise.resolve();
  MAPS.set(code, rec); return rec.ready;
}
const mapOf = (code) => MAPS.get(code)?.paths || null;
export function loadBadgeAssets(code) { return Promise.all([loadFlags([isRegion(code) ? (code === 'europe' ? 'eu' : null) : code].filter(Boolean)), loadMapOf(code)]); }
registerPrepare('el2-cbadge', async (project) => {
  const jobs = [];
  for (const tr of project.tracks || []) for (const it of tr.items) if (it.type === 'theme.el' && it.style?.el === 'cbadge') jobs.push(loadBadgeAssets(it.style.code || 'europe'));
  await Promise.all(jobs);
});

// the colours of a place: a pack's own, a region's ring, else three read off its flag (once)
const COLS = new Map();
export function colorsOf(code) {
  if (REGIONS[code]) return REGIONS[code].ring;
  if (packOf.get(code)?.colors) return packOf.get(code).colors;
  if (COLS.has(code)) return COLS.get(code);
  const cv = flagCanvas(code); if (!cv) return ['#1d3557', '#ffffff', '#e63946'];
  let out = ['#1d3557', '#ffffff', '#e63946'];
  try {
    const d = cv.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, cv.width, cv.height).data, hist = new Map();
    for (let i = 0; i < d.length; i += 4 * 37) { const k = ((d[i] >> 5) << 6) | ((d[i + 1] >> 5) << 3) | (d[i + 2] >> 5); hist.set(k, (hist.get(k) || 0) + 1); }
    const top = [...hist.entries()].sort((a, b) => b[1] - a[1]).slice(0, 3).map(([k]) => '#' + [(k >> 6) & 7, (k >> 3) & 7, k & 7].map((v) => (v * 36 + 18).toString(16).padStart(2, '0')).join(''));
    if (top.length) out = top;
  } catch {}
  COLS.set(code, out); return out;
}
export const flagOf = (code) => (code === 'europe' ? flagCanvas('eu') || A.bandFlag(['#003399', '#FFCC00'], 'stars') : isRegion(code) ? A.bandFlag(REGIONS[code].ring, 'bands') : flagCanvas(code));

// ── the globe, cached ──
const GLOBES = new Map();
function globePic(look, lon0, lat0, R, ds, pal) {
  const px = Math.max(16, Math.round(R * 2.5 * ds / 8) * 8), key = [look, lon0.toFixed(1), lat0.toFixed(1), px, pal.ocean, pal.land].join('|');
  let cv = GLOBES.get(key);
  if (!cv) {
    cv = document.createElement('canvas'); cv.width = cv.height = px;
    const g = cv.getContext('2d'), r = px / 2.5;
    A.globe(g, px / 2, px / 2, r, { lon0, lat0, pal, t: 0 });
    GLOBES.set(key, cv); if (GLOBES.size > 72) GLOBES.delete(GLOBES.keys().next().value);
  } else { GLOBES.delete(key); GLOBES.set(key, cv); }
  return cv;
}
const D2R = Math.PI / 180;
const proj = (lon, lat, lon0, lat0) => { const l = lon * D2R, p = lat * D2R, l0 = lon0 * D2R, p0 = lat0 * D2R, cp = Math.cos(p); return { x: cp * Math.sin(l - l0), y: -(Math.cos(p0) * Math.sin(p) - Math.sin(p0) * cp * Math.cos(l - l0)), z: Math.sin(p0) * Math.sin(p) + Math.cos(p0) * cp * Math.cos(l - l0) }; };

/** a ring of the place's colours round the disc (Europe: blue with twelve gold stars turning slowly) */
function flagRing(c, cx, cy, R, wd, code, t) {
  const cols = colorsOf(code);
  if (code === 'europe' || REGIONS[code]?.stars) {
    c.strokeStyle = '#003399'; c.lineWidth = wd; c.beginPath(); c.arc(cx, cy, R, 0, TAU); c.stroke();
    c.fillStyle = '#FFCC00';
    for (let i = 0; i < 12; i++) { const a = (i / 12) * TAU + t * 0.25; A.star(c, cx + Math.cos(a) * R, cy + Math.sin(a) * R, wd * 0.36); c.fill(); }
    return;
  }
  const n = cols.length, seg = TAU / (n * 2);
  c.lineWidth = wd; c.lineCap = 'butt';
  for (let i = 0; i < n * 2; i++) { c.strokeStyle = cols[i % n]; c.beginPath(); c.arc(cx, cy, R, -Math.PI / 2 + i * seg + t * 0.2, -Math.PI / 2 + (i + 1) * seg + t * 0.2 + 0.01); c.stroke(); }
  c.strokeStyle = rgba('#000000', 0.18); c.lineWidth = Math.max(1, wd * 0.08); c.beginPath(); c.arc(cx, cy, R - wd / 2, 0, TAU); c.stroke();
}
/** the lit spot: a pin with pulsing rings where the country is on the turning globe */
function pin(c, x, y, r, col, t) {
  for (let k = 0; k < 2; k++) { const f = A.fr(t * 0.8 + k * 0.5); c.strokeStyle = rgba(col, 0.9 * (1 - f)); c.lineWidth = Math.max(1, r * 0.25); c.beginPath(); c.arc(x, y, r * (1 + 2.6 * f), 0, TAU); c.stroke(); }
  c.fillStyle = '#ffffff'; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill(); c.fillStyle = col; c.beginPath(); c.arc(x, y, r * 0.6, 0, TAU); c.fill();
}
function flagDisc(c, code, x, y, r, ring = '#ffffff') {
  const img = flagOf(code); if (!img) return;
  c.save(); c.beginPath(); c.arc(x, y, r, 0, TAU); c.clip();
  const k = Math.max((r * 2) / img.width, (r * 2) / img.height); c.drawImage(img, x - (img.width * k) / 2, y - (img.height * k) / 2, img.width * k, img.height * k);
  c.restore();
  c.strokeStyle = ring; c.lineWidth = r * 0.16; c.beginPath(); c.arc(x, y, r, 0, TAU); c.stroke();
}
function mapShape(c, code, cx, cy, size, fill, stroke, lw) {
  const paths = mapOf(code); if (!paths || !paths.length) return false;
  c.save(); c.translate(cx - size / 2, cy - size / 2); c.scale(size / 200, size / 200);
  const all = new Path2D(); for (const p of paths) all.addPath(p);
  c.save(); c.clip(all);
  if (fill instanceof HTMLCanvasElement || fill?.width) { c.drawImage(fill, 0, 0, 200, 200); } else { c.fillStyle = fill; c.fillRect(0, 0, 200, 200); }
  c.restore();
  c.lineJoin = 'round'; c.strokeStyle = stroke; c.lineWidth = lw * (200 / size); c.stroke(all);
  c.restore(); return true;
}
/** text along the top (or bottom) of a circle, one glyph at a time (the stamp's words) */
function arcText(c, text, cx, cy, r, size, col, top = true) {
  if (!text) return;
  c.save(); c.font = `800 ${Math.round(size)}px ${A.FONT_UI}`; c.fillStyle = col; c.textAlign = 'center'; c.textBaseline = 'middle';
  const chars = [...text], ws = chars.map((ch) => c.measureText(ch).width + size * 0.12), total = ws.reduce((a, b) => a + b, 0), span = Math.min(Math.PI * 1.15, total / r);
  let a = (top ? -Math.PI / 2 : Math.PI / 2) + (top ? -span / 2 : span / 2);
  chars.forEach((ch, i) => {
    const da = (ws[i] / total) * span, m = a + (top ? da / 2 : -da / 2);
    c.save(); c.translate(cx + Math.cos(m) * r, cy + Math.sin(m) * r); c.rotate(m + (top ? Math.PI / 2 : -Math.PI / 2)); c.fillText(ch, 0, 0); c.restore();
    a += top ? da : -da;
  });
  c.restore();
}

/** the badge's name lines in its language(s): [{key, text, ar}] */
function names(S, code) {
  const lang = S.style?.lang || 'both', out = [];
  if (lang !== 'ar') out.push({ key: 'country', text: tx(S, 'country', placeName(code, 'en')), ar: false });
  if (lang === 'ar' || lang === 'both') out.push({ key: 'countryAr', text: tx(S, 'countryAr', placeName(code, 'ar')), ar: true });
  if (lang === 'ur') out.push({ key: 'countryUr', text: tx(S, 'countryUr', placeName(code, 'ur')), ar: true });
  if (lang === 'hi') out.push({ key: 'countryHi', text: tx(S, 'countryHi', placeName(code, 'hi')), ar: false });
  return out;
}
function nameRibbon(c, S, code, cx, y, w, size, P, a, light) {
  const lines = names(S, code); if (!lines.length || a <= 0.01) return;
  const h = size * (lines.length > 1 ? 2.35 : 1.45), bw = w;
  c.save(); c.globalAlpha *= a; c.translate(0, (1 - a) * size * 0.6);
  c.fillStyle = light ? 'rgba(255,255,255,0.95)' : rgba(P.dark, 0.88); c.beginPath(); A.rr(c, cx - bw / 2, y, bw, h, Math.min(h / 2, size * 0.7)); c.fill();
  const ink = light ? P.dark : '#ffffff';
  lines.forEach((L, i) => {
    const yy = y + size * (1.08 + i * 1.02);
    if (L.ar) A.arabicText(c, L.text, cx, yy, size * 0.92, 1, { col: i ? rgba(ink, 0.88) : ink, align: 'center', maxW: bw * 0.88, shadow: false });
    else A.uiText(c, i ? L.text : L.text.toUpperCase(), cx, yy, i ? size * 0.82 : size, { col: i ? rgba(ink, 0.85) : ink, weight: i ? 700 : 900, align: 'center', track: i ? 0 : 0.08, maxW: bw * 0.88 });
  });
  c.restore();
}

/** draw a country badge inside the item's box (S from elements.js) */
export function drawCountryBadge(c, K, S) {
  const s = S.style || {}, code = s.code || K.code || 'europe', look = lookById(s.variant).id;
  if (!flagOf(code) && !isRegion(code)) loadBadgeAssets(code);
  else if (look === 'flat' || look === 'stamp') loadMapOf(code);
  const { w, h } = S, oo = S.out(0.35), ii = clamp(S.tl / 0.6);
  const vis = Math.min(oo, clamp(ii * 3)); if (vis <= 0.002) return;
  const D = Math.min(w, h / 1.32), R = D * 0.42, cx = w / 2, cy = D / 2, ds = A.devScale(c), t = S.t;
  const cols = colorsOf(code), P = { a: cols[0], b: cols[1] || '#ffffff', dark: mix(cols[0], '#000000', lum(cols[0]) > 0.5 ? 0.75 : 0.45), acc: (s.accent || K.colors.accent || '#ffd166') };
  const [lon, lat] = centreOf(code);
  // the globe turns in from 60° away, then sways ±6°
  const turn = 60 * (1 - easeOut(S.tl / 1.4)) + 6 * Math.sin(t * 0.55), lon0 = Math.round((lon - turn) * 2) / 2, lat0 = Math.round(clamp(lat * 0.85, -60, 60) * 2) / 2;
  let sc = look === 'stamp' ? 1 + 0.6 * (1 - easeOut(ii)) : 0.3 + 0.7 * back(ii); sc *= 1 - (1 - oo) * 0.25;
  c.save(); c.globalAlpha *= vis; c.translate(cx, cy); c.scale(sc, sc); if (look === 'stamp') c.rotate(-0.12 - (1 - easeOut(ii)) * 0.3); c.translate(-cx, -cy);
  // soft shadow under every look
  c.save(); c.shadowColor = 'rgba(0,0,0,0.35)'; c.shadowBlur = R * 0.18 * ds; c.shadowOffsetY = R * 0.05 * ds; c.fillStyle = look === 'neon' ? '#070b18' : look === 'minimal' ? '#ffffff' : P.a; c.beginPath(); c.arc(cx, cy, R * (look === 'stamp' ? 1.06 : 1.1), 0, TAU); c.fill(); c.restore();
  const globeAt = (pal, r = R) => {
    const pic = globePic(look, lon0, lat0, r, ds, pal), gr = pic.width / 2.5 / ds;
    c.drawImage(pic, cx - pic.width / ds / 2, cy - pic.height / ds / 2, pic.width / ds, pic.height / ds);
    const p = proj(lon, lat, lon0, lat0); if (p.z > 0.05) pin(c, cx + p.x * gr, cy + p.y * gr, r * 0.07, pal.arc || P.acc, t);
  };
  if (look === 'glass') {
    flagRing(c, cx, cy, R * 1.0, R * 0.16, code, t);
    globeAt({ ocean: '#2a8de8', ocean2: '#062659', land: '#f4f8ff', glow: '#9fdcff', arc: P.acc }, R * 0.86);
    const gl = c.createRadialGradient(cx - R * 0.35, cy - R * 0.45, R * 0.05, cx - R * 0.2, cy - R * 0.25, R * 0.9); gl.addColorStop(0, 'rgba(255,255,255,0.55)'); gl.addColorStop(0.35, 'rgba(255,255,255,0.08)'); gl.addColorStop(1, 'rgba(255,255,255,0)');
    c.fillStyle = gl; c.beginPath(); c.arc(cx, cy, R * 0.86, 0, TAU); c.fill();
  } else if (look === 'flat') {
    const g = c.createLinearGradient(cx - R, cy - R, cx + R, cy + R); g.addColorStop(0, mix(P.a, '#ffffff', 0.15)); g.addColorStop(1, mix(P.a, '#000000', 0.3));
    c.fillStyle = g; c.beginPath(); c.arc(cx, cy, R, 0, TAU); c.fill();
    c.save(); c.beginPath(); c.arc(cx, cy, R, 0, TAU); c.clip(); c.strokeStyle = rgba(onColor(P.a), 0.14); c.lineWidth = Math.max(1, R * 0.012);
    for (let k = -3; k <= 3; k++) { c.beginPath(); c.moveTo(cx - R, cy + k * R * 0.28); c.lineTo(cx + R, cy + k * R * 0.28); c.stroke(); c.beginPath(); c.ellipse(cx, cy, Math.abs(k) * R * 0.3 + 0.1, R, 0, 0, TAU); c.stroke(); }
    c.restore();
    const breathe = 1 + Math.sin(t * 1.6) * 0.02;
    if (!mapShape(c, code, cx, cy, R * 1.45 * breathe, flagOf(code) || P.b, '#ffffff', R * 0.035)) globeAt({ ocean: mix(P.a, '#000000', 0.2), ocean2: mix(P.a, '#000000', 0.5), land: '#ffffff', glow: P.b, arc: P.acc }, R * 0.8);
    c.strokeStyle = '#ffffff'; c.lineWidth = R * 0.06; c.beginPath(); c.arc(cx, cy, R, 0, TAU); c.stroke();
  } else if (look === 'flagring') {
    const img = flagOf(code);
    c.save(); c.beginPath(); c.arc(cx, cy, R * 0.9, 0, TAU); c.clip();
    if (img) A.flagCloth(c, img, cols, cx - R * 1.25, cy - R * 0.95, R * 2.5, R * 1.9, t, { pole: false, strips: 18, amp: 0.05 });
    else { c.fillStyle = P.a; c.fillRect(cx - R, cy - R, R * 2, R * 2); }
    const sh = c.createRadialGradient(cx, cy, R * 0.55, cx, cy, R * 0.92); sh.addColorStop(0, 'rgba(0,0,0,0)'); sh.addColorStop(1, 'rgba(0,0,0,0.35)'); c.fillStyle = sh; c.fillRect(cx - R, cy - R, R * 2, R * 2);
    c.restore();
    c.strokeStyle = '#ffffff'; c.lineWidth = R * 0.07; c.beginPath(); c.arc(cx, cy, R * 0.92, 0, TAU); c.stroke();
    flagRing(c, cx, cy, R * 1.03, R * 0.12, code, t);
  } else if (look === 'neon') {
    const neon = lum(P.acc) > 0.35 ? P.acc : '#3ef2ff';
    globeAt({ ocean: '#0a1530', ocean2: '#03060f', land: neon, glow: neon, arc: '#ffffff' }, R * 0.84);
    c.save(); c.shadowColor = neon; c.shadowBlur = R * 0.22 * ds; c.strokeStyle = neon; c.lineWidth = R * 0.05;
    c.beginPath(); c.arc(cx, cy, R * 0.98, 0, TAU); c.stroke();
    c.globalAlpha *= 0.6 + 0.4 * Math.sin(t * 3) ** 2; c.lineWidth = R * 0.02; c.beginPath(); c.arc(cx, cy, R * 1.08, 0, TAU); c.stroke(); c.restore();
  } else if (look === 'stamp') {
    const ink = lum(P.a) > 0.7 ? mix(P.b, '#000000', 0.2) : P.a;
    c.save(); c.globalAlpha *= 0.92;
    c.fillStyle = mix(ink, '#ffffff', 0.92); c.beginPath(); for (let i = 0; i <= 72; i++) { const a = (i / 72) * TAU, rr = R * (i % 2 ? 0.97 : 1.03); i ? c.lineTo(cx + Math.cos(a) * rr, cy + Math.sin(a) * rr) : c.moveTo(cx + Math.cos(a) * rr, cy + Math.sin(a) * rr); } c.closePath(); c.fill();
    c.strokeStyle = ink; c.lineWidth = R * 0.05; c.stroke();
    c.lineWidth = R * 0.03; c.beginPath(); c.arc(cx, cy, R * 0.86, 0, TAU); c.stroke(); c.beginPath(); c.arc(cx, cy, R * 0.6, 0, TAU); c.stroke();
    const nm = names(S, code)[0];
    arcText(c, (nm?.ar ? nm.text : (nm?.text || '').toUpperCase()), cx, cy, R * 0.73, R * 0.15, ink, true);
    if (nm) noteSlot({ key: nm.key, text: nm.text, x: cx - R * 0.7, y: cy - R * 0.9, w: R * 1.4, h: R * 0.3, size: R * 0.15, ar: nm.ar, align: 'center' });
    const sub = tx(S, 'stampSub', isRegion(code) ? 'WORK · STUDY · LIVE' : 'APPROVED');
    arcText(c, sub.toUpperCase(), cx, cy, R * 0.73, R * 0.12, ink, false);
    noteSlot({ key: 'stampSub', text: sub, x: cx - R * 0.6, y: cy + R * 0.6, w: R * 1.2, h: R * 0.28, size: R * 0.12, align: 'center' });
    if (!mapShape(c, code, cx, cy, R * 0.95, ink, ink, R * 0.01)) { if (code === 'europe') { c.fillStyle = ink; for (let i = 0; i < 12; i++) { const a = (i / 12) * TAU; A.star(c, cx + Math.cos(a) * R * 0.38, cy + Math.sin(a) * R * 0.38, R * 0.06); c.fill(); } } else { c.fillStyle = ink; A.star(c, cx, cy, R * 0.3); c.fill(); } }
    // ink texture: a few paper-coloured specks, always the same (painted over, never erased: that would cut the video)
    const ga = c.globalAlpha; c.fillStyle = mix(ink, '#ffffff', 0.92);
    for (let i = 0; i < 90; i++) { const a = rnd(i, 3) * TAU, d = Math.sqrt(rnd(i, 5)) * R, r = R * (0.008 + rnd(i, 7) * 0.02); c.globalAlpha = ga * (0.25 + rnd(i, 9) * 0.35); c.beginPath(); c.arc(cx + Math.cos(a) * d, cy + Math.sin(a) * d, r, 0, TAU); c.fill(); }
    c.restore();
  } else {   // minimal
    c.fillStyle = '#ffffff'; c.beginPath(); c.arc(cx, cy, R, 0, TAU); c.fill();
    flagDisc(c, code, cx, cy, R * 0.66, '#ffffff');
    c.strokeStyle = rgba(P.dark, 0.25); c.lineWidth = R * 0.03; c.beginPath(); c.arc(cx, cy, R * 0.66, 0, TAU); c.stroke();
    c.strokeStyle = P.acc; c.lineWidth = R * 0.045; c.beginPath(); c.arc(cx, cy, R * 0.92, -Math.PI / 2, -Math.PI / 2 + TAU * clamp(S.tl / 1.2)); c.stroke();
  }
  if (s.flagIn && (look === 'glass' || look === 'neon')) flagDisc(c, code, cx + R * 0.62, cy + R * 0.62, R * 0.26, '#ffffff');
  c.restore();
  // the name, under the disc (not under the stamp: the stamp carries it round its edge)
  if (look !== 'stamp') nameRibbon(c, S, code, cx, D * 0.92, Math.min(w, D * 1.05), D * 0.11, P, clamp((S.tl - 0.35) / 0.4) * Math.min(oo, 1), look === 'minimal');
}

/** a badge's box: a corner of the frame inside the safe area (canvas px) */
export function badgeBox(corner, size, W, H, aspect) {
  const u = Math.min(W, H), w = Math.round(clamp(+size || 0.3, 0.12, 0.6) * u), h = Math.round(w * 1.32);
  const tall = aspect === '9:16' || H > W * 1.3, sx = W * (tall ? 0.06 : 0.05), sy = H * (tall ? 0.08 : 0.05), ex = W - W * (tall ? 0.12 : 0.05), ey = H - H * (tall ? 0.2 : 0.05);
  const left = corner !== 'tr' && corner !== 'br', top = corner !== 'bl' && corner !== 'br';
  return { x: Math.round(left ? sx : ex - w), y: Math.round(top ? sy : ey - h), w, h };
}
