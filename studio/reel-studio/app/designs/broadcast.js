// KBB Studio · Designs · BROADCAST ART PIECES: the TV-commercial art kit of the themes (app/themes/art.js -- waving
// cloth flags, landmark illustrations, postcard scenes, the flight globe, passport stamps, boarding passes, job-role
// badges, colour slabs, light leaks, confetti) as design elements, so an ad is made of the SAME drawings the reels
// use. No art is duplicated here: every piece below composes the themes' primitives; what is new is only the beauty
// art the themes did not have (silk, burst, podium, routine path, before/after split) and the photo-slot stand-ins.
//
// An art element:  { t:'art', kind, deco:true, cc?:'gb' (its country), theme?:'europe', words?:{...}, c1?/c2? (palette
// key or hex), rad?, pan? (spans a carousel), ... } drawn inside its box g at time t (seconds; the still is motion.js
// STILL). Words drawn inside a piece (a stamp, a boarding pass, a crawl) are in el.words: the editor edits them like
// any text; Arabic words are drawn right-to-left. Everything is a pure function of (element, box, t): preview = export.
import * as A from '../themes/art.js';
import { kitFor } from '../themes/kit.js';
import { mapPaths, loadAssets } from '../themes/draw.js';
import { FAM_PIECES, drawFam } from '../themes/fam.js';

const { TAU, clamp, easeOut, back, elastic, rnd, fr, mix, rgba, lum } = A;
const isAr = (s) => /[؀-ۿ]/.test(s || '');
export const PIECE_NAMES = {
  flag: 'Waving flag', scene: 'Postcard scene', landmark: 'Landmark', globe: 'Flight globe', passport: 'Passport + stamps', ticket: 'Boarding pass',
  roles: 'Job roles', slabs: 'Colour slabs', stripes: 'Flag stripes', crawl: 'News crawl', map: 'Country map', postcard: 'Postcard', confetti: 'Confetti',
  leaks: 'Light leaks', burst: 'Sale burst', glow: 'Glow & bokeh', silk: 'Silk ribbons', podium: 'Podium', petals: 'Petals', steps: 'Routine path',
  split: 'Before / after split', veil: 'Veil', panel: 'Panel', quote: 'Quote marks', rays: 'Stage lights', checks: 'Check marks', plane: 'Flight path', bigword: 'Big word', frame: 'Broadcast frame',
};
/** the words a piece draws, with defaults (English, Arabic) -- the editor shows these as fields */
export const PIECE_WORDS = {
  passport: { stamp: ['', ''], approved: ['VISA APPROVED', 'تمت الموافقة'], date: ['DD · MM · YYYY', 'DD · MM · YYYY'] },
  ticket: { from: ['DXB', 'DXB'], to: ['', ''], flight: ['PC 0001', 'PC 0001'], gate: ['--', '--'], seat: ['--', '--'], date: ['DD MMM', 'DD MMM'], brand: ['BOARDING PASS', 'BOARDING PASS'] },
  crawl: { tag: ['UPDATE', 'تحديث'], text: ['PLACEHOLDER UPDATE · VERIFY ON THE OFFICIAL WEBSITE · BOOK A FREE CONSULTATION · ', 'تحديث تجريبي · تحقق من الموقع الرسمي · احجز استشارة مجانية · '] },
  bigword: { word: ['NEW', 'جديد'] },
};
export const wordsFor = (kind, lang) => Object.fromEntries(Object.entries(PIECE_WORDS[kind] || {}).map(([k, v]) => [k, v[lang === 'ar' ? 1 : 0]]));

// ── the kit an element draws: its country (or theme) ──
export const kitOf = (el) => kitFor(el.theme || (el.cc ? 'country-' + el.cc : 'travel'));
const col = (P, v, d) => (v && /^#[0-9a-f]{6}/i.test(v) ? v.slice(0, 7) : (v && P[v]) || d);
const accOf = (K) => (K.colors.accent === '#ffffff' || lum(K.colors.accent) > 0.92 ? '#ffd166' : K.colors.accent);

// ── flags at print resolution (the themes rasterise at 320 px for video overlays; a full-bleed 4K ad needs more) ──
const FLAGS = new Map();
function loadFlag(code) {
  if (!code) return Promise.resolve(null);
  if (!FLAGS.has(code)) FLAGS.set(code, (async () => {
    let txt = await (await fetch(`/brands/planet-careers/countries/flags/${code}.svg`)).text();
    txt = txt.replace(/<svg\b([^>]*)>/, (m, a) => `<svg${a.replace(/\s(width|height)="[^"]*"/g, '')} width="1280" height="960" preserveAspectRatio="none">`);
    const url = URL.createObjectURL(new Blob([txt], { type: 'image/svg+xml' })), im = new Image(); im.src = url; await im.decode();
    const c = document.createElement('canvas'); c.width = 1280; c.height = 960; c.getContext('2d', { willReadFrequently: true }).drawImage(im, 0, 0, 1280, 960); URL.revokeObjectURL(url);
    FLAGS.get(code).img = c; return c;
  })().catch((e) => { console.warn('[designs] flag', code, e.message); return null; }));
  return FLAGS.get(code);
}
const flagHi = (code) => FLAGS.get(code)?.img || null;
function euFlag() {
  return A.cached('eu-hi', 1280, 960, (g, w, h) => { g.fillStyle = '#003399'; g.fillRect(0, 0, w, h); g.fillStyle = '#FFCC00'; for (let i = 0; i < 12; i++) { const a = (i / 12) * TAU; A.star(g, w / 2 + Math.cos(a) * h * 0.33, h / 2 + Math.sin(a) * h * 0.33, h * 0.055); g.fill(); } });
}
const flagImg = (K) => (K.code ? flagHi(K.code) : K.flagKind === 'stars' ? euFlag() : A.emblem(K));

/** load what the pieces of a design draw: print-size flags, country maps, the broadcast fonts */
export async function preparePieces(D) {
  const jobs = [];
  for (const el of D.els) {
    if (el.t !== 'art' && !(el.t === 'img' && el.slot)) continue;
    const ccs = [el.cc, ...(el.ccs || [])].filter(Boolean);
    for (const cc of ccs) { jobs.push(loadFlag(cc)); jobs.push(loadAssets({ theme: 'country-' + cc })); }
    if (!ccs.length) jobs.push(loadAssets({ theme: el.theme || 'travel' }));
    if (el.fam) jobs.push(loadAssets({ theme: 'europe' }), loadAssets({ theme: 'travel' }));   // the postcard stack draws these too
  }
  await Promise.all(jobs);
}

// ── the pieces ──
const P_ = {};

// a waving cloth flag; `shade` darkens toward one side so words read over it
P_.flag = (c, { x, y, w, h }, t, el, P, K) => {
  A.flagCloth(c, flagImg(K), K.flag, x, y, w, h, t, { pole: !!el.pole, strips: el.strips || 30, amp: el.amp ?? 0.035, waves: 1.2, speed: 1.5 });
  const dk = mix(col(P, el.c1, P.bg), '#000000', 0.35), s = el.shade;
  if (s) {
    const st = s === 'full' ? [[0, rgba(dk, (el.dark ?? 0.8) * 0.85)], [1, rgba(dk, el.dark ?? 0.8)]] : s === 'bottom' ? [[0, rgba(dk, 0)], [0.45, rgba(dk, 0.25)], [1, rgba(dk, el.dark ?? 0.92)]] : s === 'top' ? [[0, rgba(dk, el.dark ?? 0.92)], [0.55, rgba(dk, 0.25)], [1, rgba(dk, 0)]] : [[0, rgba(dk, s === 'left' ? el.dark ?? 0.9 : 0)], [1, rgba(dk, s === 'left' ? 0 : el.dark ?? 0.9)]];
    A.gradRect(c, x, y, w, h, st, s === 'left' || s === 'right' ? 'h' : 'v');
  }
  if (el.leaks !== false) { c.save(); c.translate(x, y); A.lightLeaks(c, w, h, t, [accOf(K), '#ff8a3d'], 0.35, 2); c.restore(); }
};
P_.scene = (c, { x, y, w, h }, t, el, P, K) => { A.scene(c, K, x, y, w, h, t, el.light || K.light, el.shade ?? 0); };
P_.landmark = (c, { x, y, w, h }, t, el, P, K) => {
  const id = el.lm || K.landmark || (K.skyline || [])[0]; if (!id) return;
  const s = Math.min(w, h), halo = A.blob(el.glow || '#ffe2b0');
  c.save(); c.globalAlpha *= 0.55; c.drawImage(halo, x + w / 2 - s * 0.55, y + h - s * 0.95, s * 1.1, s * 1.0); c.restore();
  A.landmark(c, id, x + w / 2, y + h, s, el.light || (K.light === 'day' ? 'gold' : K.light), 1);
  if (K.secondary && el.second !== false) A.landmark(c, K.secondary, x + w * 0.16, y + h, s * 0.5, el.light || 'gold', 0.9);
};
P_.globe = (c, { x, y, w, h }, t, el, P, K) => {
  const r = Math.min(w, h) * 0.46, cx = x + w / 2, cy = y + h / 2, prog = easeOut((t - 0.5) / 2.2);
  const mid = A.slerp(K.from, K.to, 0.5), lon0 = mid[0] - 6 + Math.sin(t * 0.25) * 4, lat0 = 18 + (mid[1] - 18) * 0.5;
  A.globe(c, cx, cy, r, { lon0, lat0, pal: { ocean: mix('#1f7ae0', K.colors.primary, 0.15), ocean2: mix('#041b4d', K.colors.primary, 0.12), land: '#f4f8ff', arc: accOf(K), glow: '#7fd3ff' }, from: K.from, to: K.to, prog, labels: el.labels || [K.fromCode, K.iata], t });
};
P_.passport = (c, { x, y, w, h }, t, el, P, K) => {
  const W2 = el.words || {};
  c.save(); c.translate(x + w / 2, y + h / 2); c.rotate(el.tilt ?? -0.05); c.translate(-w / 2, -h / 2);
  A.passportPage(c, 0, 0, w, h, K);
  const stamp = (t0, sx, sy, r, text, sub, colr, rot, kind) => {
    const d = t - t0; if (d < -0.12) return;
    const sc = d < 0 ? 2.2 - (1 + d / 0.12) * 1.2 : 1, a = d < 0 ? clamp(1 + d / 0.12) * 0.4 : 1;
    c.save(); c.translate(sx, sy); c.scale(sc, sc);
    if (isAr(text) && kind === 'rect') { c.direction = 'rtl'; }
    A.stampMark(c, 0, 0, r, text, sub, colr, rot, a * 0.92, kind); c.restore();
  };
  stamp(0.9, w * 0.72, h * 0.36, h * 0.25, W2.stamp || (K.city || K.name || '').toUpperCase(), K.iata || '✈', mix(K.colors.primary, '#1a1a6b', 0.3), -0.22, 'round');
  stamp(1.5, w * 0.7, h * 0.74, h * 0.18, W2.approved || 'VISA APPROVED', W2.date || 'DD · MM · YYYY', '#1f9d55', 0.1, 'rect');
  c.restore();
};
P_.ticket = (c, { x, y, w, h }, t, el, P, K) => {
  const W2 = el.words || {};
  c.save(); c.translate(x + w / 2, y + h / 2); c.rotate((el.tilt ?? -0.04) + Math.sin(t * 1.3) * 0.006); c.translate(-w / 2, -h / 2);
  A.boardingPass(c, 0, 0, w, h, K, t, { from: W2.from || K.fromCode, to: W2.to || K.iata, flight: W2.flight, gate: W2.gate, seat: W2.seat, date: W2.date, brand: W2.brand || 'BOARDING PASS' });
  c.restore();
};
P_.plane = (c, { x, y, w, h }, t, el, P, K) => {
  const p = el.loop ? fr(t / 5) : easeOut((t - 0.3) / 2.2), a = col(P, el.c1, '#ffffff');
  c.save(); c.strokeStyle = rgba(a, 0.75); c.lineWidth = Math.max(3, h * 0.025); c.setLineDash([h * 0.07, h * 0.05]); c.lineCap = 'round'; c.beginPath();
  const pt = (s) => [x + w * (el.flip ? 1 - s : s), y + h * (0.85 - 0.7 * Math.sin(Math.PI * s * 0.9))];
  for (let i = 0; i <= 40 * p; i++) { const [X, Y] = pt(i / 40); i ? c.lineTo(X, Y) : c.moveTo(X, Y); } c.stroke(); c.restore();
  const [X, Y] = pt(p), [X2, Y2] = pt(Math.min(1, p + 0.01));
  A.plane(c, X, Y, h * 0.22, Math.atan2(Y2 - Y, X2 - X), '#ffffff');
};
P_.roles = (c, { x, y, w, h }, t, el, P, K) => {
  const roles = el.roles || K.roles.slice(0, 3), n = roles.length, gap = w / n, r = Math.min(gap * 0.44, h / 2);
  roles.forEach((role, i) => {
    const s = elastic(clamp((t - 0.5 - i * 0.15) / 0.7)); if (s <= 0.01) return;
    const cx = x + gap * (i + 0.5), cy = y + h / 2;
    c.save(); c.translate(cx, cy); c.scale(s, s);
    c.fillStyle = 'rgba(0,0,0,0.28)'; c.beginPath(); c.arc(r * 0.04, r * 0.08, r, 0, TAU); c.fill();
    A.roleBadge(c, role, 0, 0, r, col(P, el.ring, '#ffffff'), col(P, el.c1, K.colors.primary)); c.restore();
  });
};
P_.slabs = (c, { x, y, w, h }, t, el, P, K) => {
  const cols = (el.cols || ['acc', 'bg2']).map((v) => col(P, v, v)), n = cols.length, sk = el.skew ?? 0.35;
  cols.forEach((cc, i) => { const yy = y + (h / n) * i; A.slab(c, x - h * sk, yy, w + h * sk * 2, h / n + 1, sk, cc); });
  c.save(); c.beginPath(); c.rect(x, y, w, h); c.clip(); A.sheen(c, x, y, w, h, fr(t / 3.2), 0.28); c.restore();
};
// a broadcast panel: solid colour with a slanted leading edge and the flag's colours running along it
P_.panel = (c, { x, y, w, h }, t, el, P, K) => {
  const a = col(P, el.c1, P.bg), b = col(P, el.c2, P.bg2), sk = el.skew ?? 0.06, side = el.side || 'top', bars = el.bars === false ? [] : (el.barCols ? el.barCols.map((v) => col(P, v, v)) : K.flag.slice(0, 3)), bw = Math.max(8, Math.min(w, h) * 0.018);
  const poly = (off) => { c.beginPath();
    if (side === 'top') { const d = w * sk; c.moveTo(x, y + d - off); c.lineTo(x + w, y - off); c.lineTo(x + w, y + h); c.lineTo(x, y + h); }
    else if (side === 'left') { const d = h * sk; c.moveTo(x - off, y); c.lineTo(x + w, y); c.lineTo(x + w, y + h); c.lineTo(x + d - off, y + h); }
    else { const d = h * sk; c.moveTo(x, y); c.lineTo(x + w - d + off, y); c.lineTo(x + w + off, y + h); c.lineTo(x, y + h); }
    c.closePath(); };
  bars.slice().reverse().forEach((bc, i) => { c.fillStyle = bc; poly(bw * (bars.length - i)); c.fill(); });
  const g = side === 'top' ? c.createLinearGradient(0, y, 0, y + h) : c.createLinearGradient(x, 0, x + w, 0); g.addColorStop(0, a); g.addColorStop(1, b);
  c.fillStyle = g; poly(0); c.fill();
  c.save(); poly(0); c.clip(); A.sheen(c, x, y, w, h, fr(t / 4), 0.12); c.restore();
};
// a veil in the scheme's background colour: words read over any picture (a0 at the start, a1 at the end)
P_.veil = (c, { x, y, w, h }, t, el, P) => { const k = col(P, el.c1, P.bg); A.gradRect(c, x, y, w, h, [[0, rgba(k, el.a0 ?? 0.6)], [1, rgba(k, el.a1 ?? 0.9)]], el.dir || 'v'); };
P_.stripes = (c, { x, y, w, h }, t, el, P, K) => {
  const cols = K.flag.slice(0, 3), vert = h > w, n = cols.length;
  cols.forEach((cc, i) => { c.fillStyle = cc; vert ? c.fillRect(x + (w / n) * i, y, w / n + 0.5, h) : c.fillRect(x, y + (h / n) * i, w, h / n + 0.5); });
};
P_.crawl = (c, { x, y, w, h }, t, el, P, K) => {
  const W2 = el.words || {}, text = W2.text || '', tag = W2.tag || 'UPDATE', ar = isAr(text);
  c.save(); c.fillStyle = 'rgba(8,10,24,0.94)'; c.fillRect(x, y, w, h);
  const lw = Math.min(w * 0.3, h * 3.4), lx = ar ? x + w - lw : x;
  c.fillStyle = col(P, el.c1, '#d7263d'); c.fillRect(lx, y, lw, h);
  c.save(); if (isAr(tag)) c.direction = 'rtl'; A.uiText(c, tag, lx + lw / 2, y + h * 0.66, h * 0.44, { col: '#ffffff', align: 'center', weight: 900, maxW: lw * 0.86 }); c.restore();
  c.beginPath(); c.rect(ar ? x : x + lw, y, w - lw, h); c.clip();
  c.font = `700 ${Math.round(h * 0.46)}px ${ar ? A.FONT_AR : A.FONT_UI}`; c.textBaseline = 'alphabetic'; c.textAlign = 'left'; c.fillStyle = '#ffffff'; if (ar) c.direction = 'rtl';
  const tw = Math.max(1, c.measureText(text).width), off = (t * h * 2.2) % tw;
  if (ar) { c.textAlign = 'right'; for (let xx = x + w - lw - h * 0.4 + off; xx > x - tw; xx -= tw) c.fillText(text, xx, y + h * 0.66); }
  else for (let xx = x + lw + h * 0.4 - off; xx < x + w; xx += tw) c.fillText(text, xx, y + h * 0.66);
  c.restore();
};
P_.map = (c, { x, y, w, h }, t, el, P, K) => {
  const s = Math.min(w, h), paths = K.code ? mapPaths(K.code) : null, ox = x + (w - s) / 2, oy = y + (h - s) / 2;
  const g = c.createRadialGradient(ox + s / 2, oy + s / 2, 0, ox + s / 2, oy + s / 2, s * 0.55); g.addColorStop(0, rgba(K.colors.primary, 0.9)); g.addColorStop(1, rgba(mix(K.colors.primary, '#000000', 0.5), 0.9));
  c.fillStyle = g; c.beginPath(); c.arc(ox + s / 2, oy + s / 2, s * 0.5, 0, TAU); c.fill(); c.strokeStyle = 'rgba(255,255,255,0.85)'; c.lineWidth = s * 0.014; c.stroke();
  if (paths?.length) {
    const ms = s * 0.78, clip = new Path2D(); for (const p of paths) clip.addPath(p);
    c.save(); c.translate(ox + (s - ms) / 2, oy + (s - ms) / 2); c.scale(ms / 200, ms / 200);
    c.shadowColor = 'rgba(0,0,0,0.5)'; c.shadowBlur = 12; c.fillStyle = '#ffffff'; c.fill(clip); c.shadowBlur = 0;
    c.save(); c.clip(clip); const im = flagImg(K); if (im) c.drawImage(im, 0, 0, 200, 200); c.restore();
    c.strokeStyle = '#ffffff'; c.lineWidth = 2.2; c.lineJoin = 'round'; c.stroke(clip); c.restore();
  }
};
P_.postcard = (c, { x, y, w, h }, t, el, P, K) => {
  c.save(); c.translate(x + w / 2, y + h / 2); c.rotate(el.tilt ?? 0.035); c.translate(-w / 2, -h / 2);
  c.fillStyle = 'rgba(0,0,0,0.35)'; c.fillRect(w * 0.02, h * 0.025, w, h);
  c.fillStyle = '#fffaf0'; c.fillRect(0, 0, w, h);
  const m = Math.min(w, h) * 0.045; A.scene(c, K, m, m, w - 2 * m, h - 2 * m, t, el.light || K.light);
  // a flag "postage stamp" with a perforated edge and a postmark ring
  const ps = Math.min(w, h) * 0.24, px = w - m - ps - m * 0.5, py = m * 1.5;
  c.fillStyle = '#ffffff'; c.fillRect(px, py, ps, ps * 0.8);
  c.fillStyle = '#fffaf0'; for (let i = 0; i <= 10; i++) for (const [ax, ay] of [[px + (ps * i) / 10, py], [px + (ps * i) / 10, py + ps * 0.8]]) { c.beginPath(); c.arc(ax, ay, ps * 0.025, 0, TAU); c.fill(); }
  c.save(); c.beginPath(); c.rect(px + ps * 0.08, py + ps * 0.08, ps * 0.84, ps * 0.64); c.clip(); A.flagCloth(c, flagImg(K), K.flag, px + ps * 0.08, py + ps * 0.08, ps * 0.84, ps * 0.64, t, { pole: false, strips: 10, amp: 0.04 }); c.restore();
  c.strokeStyle = 'rgba(40,40,60,0.45)'; c.lineWidth = ps * 0.02; c.beginPath(); c.arc(px - ps * 0.05, py + ps * 0.62, ps * 0.32, 0, TAU); c.stroke();
  for (let k = 0; k < 3; k++) { c.beginPath(); for (let i = 0; i <= 20; i++) { const X = px - ps * 0.5 + i * ps * 0.05, Y = py + ps * (0.95 + k * 0.08) + Math.sin(i * 0.9) * ps * 0.02; i ? c.lineTo(X, Y) : c.moveTo(X, Y); } c.stroke(); }
  c.restore();
};
P_.confetti = (c, { x, y, w, h }, t, el, P, K) => {
  const cols = el.cols ? el.cols.map((v) => col(P, v, v)) : [...new Set([...K.flag, accOf(K), '#ffffff'])], u = Math.min(w, h), n = el.n || 60;
  c.save(); c.beginPath(); c.rect(x, y, w, h); c.clip();
  for (let i = 0; i < n; i++) {
    const sp = 0.06 + 0.06 * rnd(i, 2), yy = y + fr(rnd(i, 1) + t * sp) * (h + u * 0.1) - u * 0.05, xx = x + rnd(i, 3) * w + Math.sin(t * 1.3 + i) * u * 0.02;
    const s = u * (0.008 + 0.012 * rnd(i, 4)), rot = t * (1 + 2 * rnd(i, 5)) + i, flip = Math.cos(t * (2 + 3 * rnd(i, 6)) + i);
    c.save(); c.translate(xx, yy); c.rotate(rot); c.scale(1, Math.abs(flip) < 0.2 ? 0.2 : flip); c.fillStyle = cols[i % cols.length];
    if (i % 3) c.fillRect(-s, -s * 0.45, s * 2, s * 0.9); else { c.beginPath(); c.arc(0, 0, s * 0.7, 0, TAU); c.fill(); }
    c.restore();
  }
  c.restore();
};
P_.leaks = (c, { x, y, w, h }, t, el, P, K) => {
  c.save(); c.translate(x, y); A.lightLeaks(c, w, h, t, [col(P, el.c1, accOf(K)), '#ff8a3d'], el.a ?? 0.7, 2); A.sparkles(c, w, h * 0.7, t, el.n || 10, '#ffffff', Math.min(w, h)); c.restore();
};
P_.burst = (c, { x, y, w, h }, t, el, P) => {
  const cx = x + w / 2, cy = y + h / 2, r = Math.min(w, h) / 2, a = col(P, el.c1, P.acc), b = col(P, el.c2, P.acc2 || P.acc);
  c.save(); c.translate(cx, cy); c.rotate(t * 0.12);
  c.fillStyle = rgba(mix(a, '#ffffff', 0.4), 0.35); A.star(c, 0, 0, r, 24, 0.86, 0); c.fill();
  const g = c.createRadialGradient(-r * 0.3, -r * 0.3, r * 0.1, 0, 0, r); g.addColorStop(0, a); g.addColorStop(1, mix(b, '#000000', 0.3));
  c.shadowColor = 'rgba(0,0,0,0.3)'; c.shadowBlur = r * 0.12; c.shadowOffsetY = r * 0.04;
  c.fillStyle = g; A.star(c, 0, 0, r * 0.9, 20, 0.84, 0); c.fill(); c.shadowColor = 'transparent';
  c.strokeStyle = rgba('#ffffff', 0.7); c.lineWidth = r * 0.02; c.beginPath(); c.arc(0, 0, r * 0.68, 0, TAU); c.stroke();
  c.restore();
};
P_.glow = (c, { x, y, w, h }, t, el, P) => {
  const cols = [col(P, el.c1, P.acc), col(P, el.c2, P.acc2 || '#ffffff'), el.white === false ? col(P, el.c1, P.acc) : '#ffffff'], u = Math.max(w, h);
  c.save(); c.beginPath(); c.rect(x, y, w, h); c.clip(); c.globalCompositeOperation = el.add === false ? 'source-over' : 'screen';
  for (let i = 0; i < (el.n || 14); i++) {
    const s = u * (0.06 + 0.16 * rnd(i, 11)), xx = x + fr(rnd(i, 12) + t * 0.01 * (1 + rnd(i, 13))) * w, yy = y + rnd(i, 14) * h + Math.sin(t * 0.6 + i) * u * 0.01;
    c.globalAlpha = (el.a ?? 1) * (0.14 + 0.24 * rnd(i, 15)); c.drawImage(A.blob(cols[i % 3]), xx - s, yy - s, s * 2, s * 2);
  }
  c.restore();
};
P_.silk = (c, { x, y, w, h }, t, el, P) => {
  const cols = (el.cols || ['acc', 'acc2', 'card']).map((v) => col(P, v, v)), n = cols.length;
  for (let k = 0; k < n; k++) {
    const th = h * (0.16 - k * 0.03), ph = t * 0.5 + k * 1.3, base = y + h * (0.35 + k * 0.17);
    const yAt = (u) => base + Math.sin(u * TAU * 0.9 + ph) * h * 0.12 + Math.sin(u * TAU * 2.1 + ph * 0.7) * h * 0.04;
    const g = c.createLinearGradient(x, y, x + w, y + h); g.addColorStop(0, mix(cols[k], '#000000', 0.15)); g.addColorStop(0.45, mix(cols[k], '#ffffff', 0.35)); g.addColorStop(0.55, cols[k]); g.addColorStop(1, mix(cols[k], '#000000', 0.25));
    c.fillStyle = g; c.globalAlpha = el.op2 ?? 0.92; c.beginPath();
    for (let i = 0; i <= 48; i++) { const u = i / 48, X = x + u * w; i ? c.lineTo(X, yAt(u)) : c.moveTo(X, yAt(u)); }
    for (let i = 48; i >= 0; i--) { const u = i / 48, X = x + u * w; c.lineTo(X, yAt(u) + th * (0.6 + 0.4 * Math.sin(u * TAU * 1.3 + ph))); }
    c.closePath(); c.fill(); c.globalAlpha = 1;
  }
};
P_.podium = (c, { x, y, w, h }, t, el, P) => {
  const a = col(P, el.c1, P.card), top = h * 0.22, cx = x + w / 2;
  c.save(); c.globalAlpha *= 0.7; c.drawImage(A.blob(col(P, el.c2, '#ffffff')), cx - w * 0.6, y - h * 0.9, w * 1.2, h * 1.4); c.restore();
  const g = c.createLinearGradient(x, 0, x + w, 0); g.addColorStop(0, mix(a, '#000000', 0.18)); g.addColorStop(0.35, mix(a, '#ffffff', 0.25)); g.addColorStop(1, mix(a, '#000000', 0.28));
  c.fillStyle = g; c.beginPath(); c.moveTo(x, y + top / 2); c.lineTo(x, y + h - top / 2); c.ellipse(cx, y + h - top / 2, w / 2, top / 2, 0, Math.PI, 0, true); c.lineTo(x + w, y + top / 2); c.closePath(); c.fill();
  const tg = c.createLinearGradient(0, y, 0, y + top); tg.addColorStop(0, mix(a, '#ffffff', 0.4)); tg.addColorStop(1, mix(a, '#ffffff', 0.1));
  c.fillStyle = tg; c.beginPath(); c.ellipse(cx, y + top / 2, w / 2, top / 2, 0, 0, TAU); c.fill();
  c.strokeStyle = rgba(col(P, el.ring, P.acc), 0.8); c.lineWidth = Math.max(2, h * 0.02); c.beginPath(); c.ellipse(cx, y + top / 2, w / 2 * 0.96, top / 2 * 0.9, 0, 0, TAU); c.stroke();
};
P_.petals = (c, { x, y, w, h }, t, el, P) => {
  const cols = [col(P, el.c1, P.acc), mix(col(P, el.c1, P.acc), '#ffffff', 0.55), '#ffffff'], u = Math.min(w, h);
  c.save(); c.beginPath(); c.rect(x, y, w, h); c.clip();
  for (let i = 0; i < (el.n || 22); i++) {
    const sp = 0.04 + 0.04 * rnd(i, 21), yy = y + fr(rnd(i, 22) + t * sp) * (h + u * 0.2) - u * 0.1, xx = x + rnd(i, 23) * w + Math.sin(t * 0.8 + i) * u * 0.04, s = u * (0.025 + 0.035 * rnd(i, 24));
    c.save(); c.translate(xx, yy); c.rotate(t * 0.5 + i); c.scale(1, 0.55 + 0.45 * Math.cos(t + i));
    const g = c.createLinearGradient(-s, 0, s, 0); g.addColorStop(0, rgba(cols[i % 3], 0.95)); g.addColorStop(1, rgba(mix(cols[i % 3], '#ffffff', 0.5), 0.9));
    c.fillStyle = g; c.beginPath(); c.moveTo(-s, 0); c.quadraticCurveTo(0, -s * 0.7, s, 0); c.quadraticCurveTo(0, s * 0.7, -s, 0); c.fill(); c.restore();
  }
  c.restore();
};
// the routine path: a ribbon that runs through n stations (the step numbers and words are text elements on top)
P_.steps = (c, { x, y, w, h }, t, el, P) => {
  const pts = (el.pts || []).map(([px, py]) => [x + px, y + py]), a = col(P, el.c1, P.acc), r = el.r || Math.min(w, h) * 0.09, p = easeOut((t - 0.3) / 2);
  if (pts.length > 1) {
    c.save(); c.strokeStyle = rgba(a, 0.55); c.lineWidth = r * 0.28; c.lineCap = 'round'; c.setLineDash([r * 0.3, r * 0.35]); c.beginPath();
    const tot = pts.length - 1, upto = p * tot;
    for (let i = 0; i < tot && i < upto; i++) { const [x0, y0] = pts[i], [x1, y1] = pts[i + 1], f = Math.min(1, upto - i); const mx = (x0 + x1) / 2, my = Math.min(y0, y1) - r * 1.2; if (!i) c.moveTo(x0, y0); c.quadraticCurveTo(mx, my, x0 + (x1 - x0) * f, y0 + (y1 - y0) * f); }
    c.stroke(); c.restore();
  }
  pts.forEach(([px, py], i) => {
    const s = back(clamp((t - 0.4 - i * 0.25) / 0.5)); if (s <= 0.01) return;
    c.save(); c.translate(px, py); c.scale(s, s);
    c.fillStyle = 'rgba(0,0,0,0.22)'; c.beginPath(); c.arc(r * 0.06, r * 0.1, r, 0, TAU); c.fill();
    const g = c.createLinearGradient(0, -r, 0, r); g.addColorStop(0, a); g.addColorStop(1, mix(a, '#000000', 0.25));
    c.fillStyle = g; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.fill(); c.strokeStyle = '#ffffff'; c.lineWidth = r * 0.1; c.stroke(); c.restore();
  });
};
P_.split = (c, { x, y, w, h }, t, el, P) => {
  const a = col(P, el.c1, P.bg2), b = col(P, el.c2, P.acc), k = el.k ?? 0.5, sk = w * 0.06;
  c.fillStyle = mix(a, '#808080', 0.25); c.fillRect(x, y, w, h);
  const g = c.createLinearGradient(x + w * k, y, x + w, y + h); g.addColorStop(0, mix(b, '#ffffff', 0.15)); g.addColorStop(1, mix(b, '#000000', 0.1));
  c.fillStyle = g; c.beginPath(); c.moveTo(x + w * k + sk, y); c.lineTo(x + w, y); c.lineTo(x + w, y + h); c.lineTo(x + w * k - sk, y + h); c.closePath(); c.fill();
  c.save(); c.beginPath(); c.moveTo(x + w * k + sk, y); c.lineTo(x + w, y); c.lineTo(x + w, y + h); c.lineTo(x + w * k - sk, y + h); c.closePath(); c.clip(); A.sparkles(c, w * 2, h, t, 14, '#ffffff', Math.min(w, h)); A.sheen(c, x, y, w, h, fr(t / 3), 0.3); c.restore();
  c.strokeStyle = '#ffffff'; c.lineWidth = Math.max(4, w * 0.008); c.beginPath(); c.moveTo(x + w * k + sk, y); c.lineTo(x + w * k - sk, y + h); c.stroke();
  const kr = Math.min(w, h) * 0.05; c.fillStyle = '#ffffff'; c.beginPath(); c.arc(x + w * k, y + h / 2, kr, 0, TAU); c.fill();
  c.fillStyle = b; for (const s of [-1, 1]) { c.beginPath(); c.moveTo(x + w * k + s * kr * 0.25, y + h / 2 - kr * 0.35); c.lineTo(x + w * k + s * kr * 0.65, y + h / 2); c.lineTo(x + w * k + s * kr * 0.25, y + h / 2 + kr * 0.35); c.fill(); }
};
P_.quote = (c, { x, y, w, h }, t, el, P) => {
  const a = col(P, el.c1, P.acc), s = Math.min(w / 2.3, h);
  c.fillStyle = a;
  for (let i = 0; i < 2; i++) { const cx = x + s * 0.5 + i * s * 1.25, cy = y + s * 0.42; c.beginPath(); c.arc(cx, cy, s * 0.4, 0, TAU); c.fill(); c.beginPath(); c.moveTo(cx - s * 0.05, cy + s * 0.2); c.quadraticCurveTo(cx + s * 0.38, cy + s * 0.55, cx - s * 0.32, cy + s * 0.98); c.quadraticCurveTo(cx + s * 0.1, cy + s * 0.55, cx - s * 0.36, cy + s * 0.25); c.closePath(); c.fill(); }
};
P_.rays = (c, { x, y, w, h }, t, el, P) => {
  const a = col(P, el.c1, '#ffffff');
  c.save(); c.beginPath(); c.rect(x, y, w, h); c.clip(); c.globalCompositeOperation = 'screen';
  for (let i = 0; i < 3; i++) {
    const ox = x + w * (0.15 + i * 0.35), ang = Math.PI / 2 + Math.sin(t * 0.6 + i * 2) * 0.35, L = Math.hypot(w, h);
    const g = c.createLinearGradient(ox, y, ox + Math.cos(ang) * L, y + Math.sin(ang) * L); g.addColorStop(0, rgba(a, 0.45)); g.addColorStop(1, rgba(a, 0));
    c.fillStyle = g; c.beginPath(); c.moveTo(ox, y); c.lineTo(ox + Math.cos(ang - 0.16) * L, y + Math.sin(ang - 0.16) * L); c.lineTo(ox + Math.cos(ang + 0.16) * L, y + Math.sin(ang + 0.16) * L); c.closePath(); c.fill();
  }
  c.restore();
};
P_.checks = (c, { x, y, w, h }, t, el, P) => {
  const ys = el.ys || [], r = el.r || w / 2, a = col(P, el.c1, '#1f9d55');
  ys.forEach((cy0, i) => {
    const cy = y + cy0;
    const s = back(clamp((t - 0.8 - i * 0.3) / 0.45)); if (s <= 0.01) return;
    const cx = x + w / 2; c.save(); c.translate(cx, cy); c.scale(s, s);
    c.fillStyle = a; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.fill();
    c.strokeStyle = '#ffffff'; c.lineWidth = r * 0.26; c.lineCap = 'round'; c.lineJoin = 'round'; c.beginPath(); c.moveTo(-r * 0.45, 0); c.lineTo(-r * 0.1, r * 0.35); c.lineTo(r * 0.5, -r * 0.35); c.stroke(); c.restore();
  });
};
P_.bigword = (c, { x, y, w, h }, t, el, P) => {
  const word = el.words?.word || 'NEW', a = col(P, el.c1, P.acc), ar = isAr(word);
  c.save(); c.font = `${ar ? 900 : 400} ${Math.round(h)}px ${ar ? A.FONT_AR : A.FONT_EN}`; c.textBaseline = 'alphabetic'; c.textAlign = 'center'; if (ar) c.direction = 'rtl';
  const m = c.measureText(word).width, k = Math.min(1, w / Math.max(1, m)); c.translate(x + w / 2, y + h * 0.92); c.scale(k, k);
  c.globalAlpha *= el.op2 ?? 0.92; c.fillStyle = col(P, el.c2, '#ffffff'); c.fillText(word, 0, 0); c.globalAlpha = 1;
  c.strokeStyle = a; c.lineWidth = h * 0.014 / k; c.lineJoin = 'round'; c.strokeText(word, 0, 0); c.restore();
};
P_.frame = (c, { x, y, w, h }, t, el, P) => {
  const a = col(P, el.c1, P.acc), L = Math.min(w, h) * 0.08, lw = Math.max(4, Math.min(w, h) * 0.008);
  c.strokeStyle = a; c.lineWidth = lw; c.lineCap = 'square';
  for (const [px, py, sx, sy] of [[x, y, 1, 1], [x + w, y, -1, 1], [x, y + h, 1, -1], [x + w, y + h, -1, -1]]) { c.beginPath(); c.moveTo(px, py + sy * L); c.lineTo(px, py); c.lineTo(px + sx * L, py); c.stroke(); }
};

// ── photo slots without a photo: a drawn stand-in at the slot's full size (never an empty hole, never a tiny icon) ──
export function drawSlot(c, D, el, g, t) {
  const P = D.palette, { x, y, w, h } = g, a = P.acc, r = el.rad ?? 0;
  c.save(); c.beginPath(); if (el.mask === 'circle') c.ellipse(x + w / 2, y + h / 2, w / 2, h / 2, 0, 0, TAU); else { A.rr(c, x, y, w, h, r); } c.clip();
  const bg = c.createLinearGradient(x, y, x, y + h); bg.addColorStop(0, mix(P.card, a, 0.18)); bg.addColorStop(1, mix(P.card, a, 0.38)); c.fillStyle = bg; c.fillRect(x, y, w, h);
  c.save(); c.globalAlpha = 0.5; c.drawImage(A.blob('#ffffff'), x + w * 0.1, y - h * 0.1, w * 0.8, h * 0.8); c.restore();
  if (el.slot === 'product') {
    // a bottle silhouette
    const bw = Math.min(w * 0.36, h * 0.3), bh = bw * 2.2, bx = x + (w - bw) / 2, by = y + h * 0.92 - bh;
    const gg = c.createLinearGradient(bx, 0, bx + bw, 0); gg.addColorStop(0, mix(a, '#ffffff', 0.55)); gg.addColorStop(0.4, '#ffffff'); gg.addColorStop(1, mix(a, '#ffffff', 0.35));
    c.fillStyle = gg; c.beginPath(); A.rr(c, bx, by + bh * 0.22, bw, bh * 0.78, bw * 0.16); c.fill();
    c.fillStyle = mix(a, '#000000', 0.15); c.beginPath(); A.rr(c, bx + bw * 0.28, by, bw * 0.44, bh * 0.24, bw * 0.06); c.fill();
    c.fillStyle = rgba(a, 0.35); c.fillRect(bx + bw * 0.12, by + bh * 0.5, bw * 0.76, bh * 0.2);
  } else if (el.slot === 'worker' && el.role2) {
    const rr2 = Math.min(w, h) * 0.42; A.roleBadge(c, el.role2, x + w / 2, y + h - rr2 * 1.02, rr2, '#ffffff', mix(P.bg, a, 0.2));
  } else {
    // a person: head and shoulders
    const s = Math.min(w, h * 0.9), cx = x + w / 2, base = y + h;
    c.fillStyle = mix(a, '#ffffff', 0.3); c.beginPath(); c.moveTo(cx - s * 0.46, base); c.bezierCurveTo(cx - s * 0.46, base - s * 0.42, cx - s * 0.24, base - s * 0.5, cx, base - s * 0.5); c.bezierCurveTo(cx + s * 0.24, base - s * 0.5, cx + s * 0.46, base - s * 0.42, cx + s * 0.46, base); c.fill();
    c.fillStyle = mix(a, '#ffffff', 0.55); c.beginPath(); c.arc(cx, base - s * 0.72, s * 0.2, 0, TAU); c.fill();
  }
  c.restore();
  // the slot's frame: says "a photo goes here" without words
  c.save(); c.setLineDash([Math.max(8, w * 0.03), Math.max(6, w * 0.02)]); c.strokeStyle = rgba('#ffffff', 0.85); c.lineWidth = Math.max(3, Math.min(w, h) * 0.008);
  c.beginPath(); if (el.mask === 'circle') c.ellipse(x + w / 2, y + h / 2, w / 2 - c.lineWidth, h / 2 - c.lineWidth, 0, 0, TAU); else A.rr(c, x + c.lineWidth, y + c.lineWidth, w - 2 * c.lineWidth, h - 2 * c.lineWidth, Math.max(0, r - c.lineWidth)); c.stroke(); c.restore();
}

/** draw one art element at t inside its box (canvas units of the design) */
export function drawPiece(c, D, el0, g, t) {
  const el = g.o ? Object.assign({}, el0, g.o) : el0;
  const fn = P_[el.kind]; if (!fn) return;
  const K = kitOf(el), box = { x: g.x, y: g.y, w: g.w, h: g.h || g.w };
  c.save();
  try {
    if (el.rad != null || el.clip) { c.beginPath(); el.mask === 'circle' ? c.ellipse(box.x + box.w / 2, box.y + box.h / 2, box.w / 2, box.h / 2, 0, 0, TAU) : A.rr(c, box.x, box.y, box.w, box.h, el.rad || 0); c.clip(); }
    fn(c, box, t, el, D.palette, K);
  } catch (e) { console.warn('[designs] piece', el.kind, e.message); }
  c.restore();
}
// ── the design families (themes/fam.js): careers, travel, occupations, bright future -- kind 'fam-<piece>' ──
for (const [id, pc] of Object.entries(FAM_PIECES)) {
  P_['fam-' + id] = (c, box, t, el, P, K) => drawFam(c, id, box, t, { fam: el.fam || pc.fam, K, flag: flagImg(K), words: el.words, role: el.role, rtl: el.rtl && pc.dir, ar: !!el.rtl || Object.values(el.words || {}).some(isAr) });
  PIECE_NAMES['fam-' + id] = pc.name;
}
export const PIECE_KINDS = Object.keys(P_);
