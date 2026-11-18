// KBB Studio · themes · ELEMENTS: the pieces of a complete theme package, each one an ordinary timeline item so it can
// be dropped anywhere, trimmed, moved, deleted, undone, saved — or switched on for the whole video.
//
//   item.type 'theme.el'   style = { theme, el, colors, text:{...placeholders}, variant?, whole?, beats?, brand? }
//                          el ∈ ELEMENTS ids: intro · title · lower · wipe · globe · stamp · ticket · hiring · counter ·
//                          postcard · map · confetti · end · grade · sticker
//   item.type 'theme.sting' / 'theme.outro'   (placed by "Apply theme"; older projects carry them too) draw the same
//                          intro and end card as theme.el, so an old reel opens with the new art.
//
// Every element is a pure function of its local time and its duration (trim it and the animation re-times itself:
// in, hold, out), drawn inside the item's box (move / resize it like a sticker). Long ones repeat on a cycle.
import { registerLayer } from '../core/render.js';
import { flagImage, mapPaths, pulseAt, logoImage } from './draw.js';
import * as A from './art.js';
import { kitFor } from './kit.js';
import { themeById } from './themes.js';
import { drawCapsules, defaultCaps, capsHeight } from './capsules.js';
import { FAM_PIECES, FAM_PAL, FAMILY_ICONS, FAMILY_LABELS, TRADE_IDS, TRADES, drawFam } from './fam.js';

const { clamp, easeOut, easeIO, easeIn, back, elastic, TAU, rnd, fr, mix, rgba, onColor } = A;

/** the catalogue: id, label, icon, natural duration, where it sits, what "whole video" means for it */
export const ELEMENTS = [
  { id: 'breaking', label: 'Breaking-news band', icon: '📰', dur: 4, box: 'band', whole: 'span', note: 'Headline band + ticker crawl', bulletin: true },
  { id: 'bulletin', label: 'Country bulletin', icon: '📺', dur: 3.6, box: 'full', whole: 'start', note: 'Full screen: flag, landmark, headline', bulletin: true },
  { id: 'split', label: 'Split screen', icon: '◧', dur: 4, box: 'full', whole: 'span', note: 'You in one half, the country in the other', bulletin: true },
  { id: 'lowerbar', label: 'Bulletin bar + logo', icon: '▬', dur: 4, box: 'lowerbar', whole: 'span', note: 'Name bar with your logo slot', bulletin: true },
  { id: 'visa', label: 'Visa update board', icon: '📋', dur: 5, box: 'board', whole: 'span', note: 'Editable placeholder points', bulletin: true },
  { id: 'intro', label: 'Intro sting', icon: '🎬', dur: 0.9, box: 'full', whole: 'start', note: 'Colour slabs, the country card, a reveal' },
  { id: 'title', label: 'Title / country card', icon: '🔤', dur: 2.8, box: 'title', whole: 'span', note: 'Big kinetic name in English and Arabic' },
  { id: 'lower', label: 'Lower third', icon: '▁', dur: 3.5, box: 'lower', whole: 'span', note: 'Name and role bars' },
  { id: 'wipe', label: 'Transition wipe', icon: '🔀', dur: 0.5, box: 'full', whole: 'cuts', note: 'Diagonal colour slabs on a cut', hidden: true },
  { id: 'globe', label: 'Flight globe', icon: '🌍', dur: 3.4, box: 'globe', whole: 'span', cycle: 4.5, note: 'Spinning globe, UAE → destination' },
  { id: 'stamp', label: 'Passport stamp', icon: '🛂', dur: 2.8, box: 'stamp', whole: 'span', cycle: 3.8, note: 'Stamp slam + visa approved' },
  { id: 'ticket', label: 'Boarding pass', icon: '🎫', dur: 3, box: 'ticket', whole: 'span', cycle: 4.2, note: 'Ticket slides in' },
  { id: 'hiring', label: "We're hiring", icon: '📣', dur: 3.6, box: 'hiring', whole: 'span', note: 'Banner + job-role badges' },
  { id: 'counter', label: 'Counting number', icon: '🔢', dur: 2.6, box: 'counter', whole: 'span', cycle: 4.5, note: 'Your number counts up (placeholder)' },
  { id: 'postcard', label: 'Landmark postcard', icon: '🏛', dur: 3, box: 'postcard', whole: 'span', note: 'The hero landmark, illustrated' },
  { id: 'map', label: 'Map card', icon: '🗺', dur: 2.8, box: 'map', whole: 'span', note: 'The country outline in its flag' },
  { id: 'confetti', label: 'Confetti burst', icon: '🎉', dur: 2.2, box: 'full', whole: 'span', cycle: 3.2, note: 'Confetti and sparkles' },
  { id: 'end', label: 'End card', icon: '🏁', dur: 2.6, box: 'full', whole: 'end', note: 'Call to action + contact placeholders' },
  { id: 'grade', label: 'Colour grade', icon: '🎨', dur: 4, box: 'full', whole: 'span', note: 'The theme’s tint over the picture' },
  { id: 'capsules', label: 'Capsules carousel', icon: '💊', dur: 6, box: 'capsules', whole: 'span', note: 'Job and country pills scrolling for ever (edit in the inspector)' },
  { id: 'sticker', label: 'Sticker', icon: '⭐', dur: 3, box: 'sticker', whole: 'span', variants: ['flag', 'visa', 'plane', 'pin', 'coin', 'landmark', 'sparkle'], note: 'Animated sticker set', hidden: true },
  // the design families (2026-11): careers, travel, occupations, bright future -- big compositions, nothing bulletin
  ...famElements(),
];
/** one element per family piece: full-screen scenes start the reel, panels can run over the whole video */
function famElements() {
  const CARD = new Set(['cv', 'offer', 'path', 'departures', 'trades', 'growth', 'stamps', 'postcards', 'route', 'kinetic', 'tree', 'stars']);
  return Object.entries(FAM_PIECES).filter(([, pc]) => !pc.over).map(([id, pc]) => ({
    id: 'fam-' + id, label: pc.name, icon: FAMILY_ICONS[pc.fam], dur: 4.2, box: CARD.has(id) ? 'famcard' : 'full', whole: CARD.has(id) ? 'span' : 'start',
    fam: pc.fam, piece: id, note: `${FAMILY_LABELS[pc.fam]} · ${pc.style}`, words: Object.keys(pc.words || {}),
    ...(pc.roles || id === 'trades' || id === 'day' ? { variants: TRADE_IDS, variantLabels: Object.fromEntries(TRADE_IDS.map((r) => [r, TRADES[r].en])) } : {}),
  }));
}
export const elementById = (id) => ELEMENTS.find((e) => e.id === id) || null;
const FAM_DEFAULT_ROLE = { 'fam-worker': 'nurse', 'fam-trades': 'welder', 'fam-day': 'chef', 'fam-morph': 'electrician' };
export const STICKER_LABELS = { flag: 'Flag badge', visa: 'Visa approved', plane: 'Plane', pin: 'Map pin', coin: 'Currency coin', landmark: 'Landmark badge', sparkle: 'Sparkle' };

/** where an element sits by default (canvas px) */
export function boxFor(el, W, H) {
  const u = Math.min(W, H), land = W > H * 1.05, cw = (k, max) => Math.min(W * k, u * max);
  const at = (w, h, x, y) => ({ x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h) });
  const top = land ? H * 0.06 : H * 0.075;
  switch (elementById(el)?.box) {
    case 'title': { const w = cw(0.92, 1.2), h = w * 0.36; return at(w, h, (W - w) / 2, top); }
    case 'lower': { const w = cw(0.88, 1.15), h = w * 0.19; return at(w, h, W * 0.05, land ? H * 0.68 : H * 0.6); }
    case 'globe': { const s = u * 0.58; return at(s, s * 1.12, W - s - u * 0.03, top); }
    case 'stamp': { const w = u * 0.8, h = w * 0.66; return at(w, h, (W - w) / 2, top); }
    case 'ticket': { const w = cw(0.9, 1.15), h = w * 0.38; return at(w, h, (W - w) / 2, top); }
    case 'hiring': { const w = cw(0.92, 1.2), h = w * 0.5; return at(w, h, (W - w) / 2, top); }
    case 'counter': { const w = u * 0.6, h = w * 0.52; return at(w, h, u * 0.05, top); }
    case 'postcard': { const w = u * 0.7, h = w * 0.74; return at(w, h, W - w - u * 0.05, top); }
    case 'map': { const s = u * 0.52; return at(s, s * 1.1, u * 0.05, top); }
    case 'band': { const w = W, h = Math.min(W * 0.36, u * 0.42); return at(w, h, 0, land ? H * 0.62 : H * 0.6); }
    case 'lowerbar': { const w = cw(0.94, 1.25), h = w * 0.17; return at(w, h, (W - w) / 2, land ? H * 0.7 : H * 0.62); }
    case 'board': { const w = cw(0.9, 1.1), h = w * 0.82; return at(w, h, (W - w) / 2, top); }
    case 'sticker': { const s = u * 0.26; return at(s, s, W - s - u * 0.06, top + u * 0.05); }
    case 'capsules': { const h = Math.min(H * 0.4, capsHeight(defaultCaps())); return at(W, h, 0, land ? H * 0.7 : H * 0.72); }
    case 'famcard': { const w = land ? Math.min(W * 0.62, H * 0.84 * 1.45) : W * 0.9, h = land ? H * 0.84 : Math.min(H * 0.58, w * 1.3); return at(w, h, (W - w) / 2, land ? H * 0.08 : H * 0.12); }
    default: return at(W, H, 0, 0);
  }
}

// ── which kit an item draws: its own theme, else the reel's theme (old stings / outros carry only colours) ──
function kitOfItem(item, project) {
  const s = item.style || {};
  let id = s.theme;
  if (!id || !themeById(id)) id = project?.tracks?.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay')?.style?.theme || null;
  return kitFor(id || 'travel', s.colors || null);
}
const imgOf = (K) => (K.code ? flagImage(K.code) : K.flagKind === 'stars' ? A.bandFlag(K.flag, 'stars') : A.emblem(K));

// ── timing: in / hold / out inside the duration; long elements repeat on their cycle ──
function timing(def, local, d) {
  let tl = local, dd = d, k = 0;
  if (def?.cycle && d > def.cycle * 1.6) { const n = Math.round(d / def.cycle), cl = d / n; k = Math.min(n - 1, Math.floor(local / cl)); tl = local - k * cl; dd = cl; }
  return { tl, d: dd, k, inn: (s) => easeOut(tl / s), out: (s) => 1 - easeIn((tl - (dd - s)) / s) };
}

const D = {};

// ── INTRO: slabs sweep in, the country card (landmark scene, flag, kinetic name, Arabic), slabs reveal the video ──
D.intro = (c, K, S) => {
  const { w, h, U } = S, p = clamp(S.tl / S.d), cols = K.slabs;
  const card = p > 0.28 && p < 0.74;
  if (card) {
    const cp = (p - 0.28) / 0.46;
    c.save(); const z = 1.06 - 0.06 * easeOut(cp); c.translate(w / 2, h / 2); c.scale(z, z); c.translate(-w / 2, -h / 2);
    A.scene(c, K, 0, 0, w, h, S.t, K.light, 0.85); c.restore();
    nameBlock(c, K, S, w / 2, h * 0.56, U, cp * 1.6, 'center');
    A.lightLeaks(c, w, h, S.t, [K.colors.accent, '#ff8a3d'], 0.8, 2);
    A.glint(c, w * (0.2 + 0.6 * cp), h * 0.47, U * 0.06, Math.sin(Math.PI * cp), '#ffffff');
  }
  const sp = p <= 0.28 ? (p / 0.28) * 0.5 : p >= 0.74 ? 0.5 + ((p - 0.74) / 0.26) * 0.5 : -1;
  if (sp >= 0) A.slabWipe(c, w, h, cols, sp, { dir: 1 });
};
/** the flag + kicker + kinetic name + Arabic stack used by the intro and the end card */
function nameBlock(c, K, S, x, y, U, p, align) {
  const W2 = K.words, txt = S.txt, name = txt.title || W2.title, nameAr = txt.titleAr ?? W2.titleAr;
  const fh = U * 0.13, fw = fh * 1.5;
  c.save(); c.globalAlpha *= clamp(p * 2);
  A.flagCloth(c, imgOf(K), K.flag, x - fw / 2, y - U * 0.42 - (1 - easeOut(p)) * U * 0.05, fw, fh, S.t, { pole: false, strips: 16 });
  c.restore();
  A.uiText(c, txt.kicker || W2.kicker, x, y - U * 0.2, U * 0.036, { col: rgba('#ffffff', clamp(p * 2)), align: 'center', weight: 800, track: 0.25, maxW: S.w * 0.9 });
  A.kineticText(c, name, x, y, U * 0.17, p, { col: '#ffffff', maxW: S.w * 0.9 });
  c.fillStyle = K.colors.accent; c.fillRect(x - U * 0.18 * easeOut(p - 0.2), y + U * 0.03, U * 0.36 * easeOut(p - 0.2), U * 0.012);
  A.arabicText(c, nameAr, x, y + U * 0.13, U * 0.075, clamp(p - 0.25), { col: '#ffffff', maxW: S.w * 0.9 });
}

// ── TITLE: kinetic name card in the flag's colours ──
D.title = (c, K, S) => {
  const { w, h } = S, ii = S.inn(0.5), oo = S.out(0.35), P = K.colors, txt = S.txt, W2 = K.words;
  if (oo <= 0) return;
  const slide = (1 - ii) * -w * 0.9 + (1 - oo) * w * 1.1;
  c.save(); c.translate(slide, 0);
  // shadow + two slabs
  c.fillStyle = 'rgba(0,0,0,0.28)'; A.slab(c, h * 0.12, h * 0.16, w * 0.94, h * 0.78, 0.14, 'rgba(0,0,0,0.28)');
  const g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, mix(P.primary, '#ffffff', 0.08)); g.addColorStop(1, mix(P.primary, '#000000', 0.25));
  A.slab(c, h * 0.06, h * 0.06, w * 0.92, h * 0.74, 0.14, g);
  A.slab(c, h * 0.3, h * 0.8, w * 0.62 * easeOut((S.tl - 0.15) / 0.4), h * 0.16, 0.5, P.accent);
  // flag stripe edge
  K.flag.slice(0, 3).forEach((col, i) => A.slab(c, h * 0.02 + i * h * 0.05, h * 0.06, h * 0.05, h * 0.74, 0.14, col));
  c.save(); c.beginPath(); c.rect(h * 0.2, h * 0.06, w, h * 0.74); c.clip();
  const fh = h * 0.36, fw = fh * 1.45;
  A.flagCloth(c, imgOf(K), K.flag, h * 0.32, h * 0.24, fw, fh, S.t, { pole: false, strips: 14 });
  const tx = h * 0.32 + fw + h * 0.16, mw = w - tx - h * 0.2;
  A.uiText(c, txt.kicker || W2.kicker, tx, h * 0.22, h * 0.075, { col: rgba(onColor(P.primary), 0.85), weight: 800, track: 0.22, maxW: mw });
  A.kineticText(c, txt.title || W2.title, tx, h * 0.58, h * 0.34, clamp((S.tl - 0.12) / 0.6), { col: onColor(P.primary), align: 'left', maxW: mw });
  A.sheen(c, 0, 0, w, h, fr(S.tl / 2.2), 0.3);
  c.restore();
  A.arabicText(c, txt.titleAr ?? W2.titleAr, w - h * 0.32, h * 0.93, h * 0.13, clamp((S.tl - 0.35) / 0.4), { col: onColor(P.accent), align: 'right', maxW: w * 0.5 });
  if (S.pulse > 0.05) A.glint(c, w * 0.86, h * 0.12, h * 0.16 * S.pulse, S.pulse, '#ffffff');
  c.restore();
};

// ── LOWER THIRD ──
D.lower = (c, K, S) => {
  const { w, h } = S, P = K.colors, ii = S.inn(0.45), oo = S.out(0.3), txt = S.txt, W2 = K.words, k = Math.min(ii, oo);
  if (k <= 0) return;
  const bh = h * 0.56, bw = w * easeOut(k);
  c.save(); c.globalAlpha *= clamp(k * 3);
  c.fillStyle = 'rgba(0,0,0,0.25)'; c.fillRect(h * 0.08, h * 0.1, bw, h * 0.9);
  // badge
  const bs = h * 0.98; c.fillStyle = P.accent; c.fillRect(0, 0, bs, bs);
  c.save(); c.beginPath(); c.rect(0, 0, bs, bs); c.clip(); A.flagCloth(c, imgOf(K), K.flag, bs * 0.12, bs * 0.25, bs * 0.76, bs * 0.5, S.t, { pole: false, strips: 10, amp: 0.09 }); c.restore();
  // bars
  const g = c.createLinearGradient(bs, 0, w, 0); g.addColorStop(0, P.primary); g.addColorStop(1, mix(P.primary, '#000000', 0.3));
  c.fillStyle = g; c.fillRect(bs, 0, Math.max(0, bw - bs), bh);
  c.fillStyle = '#ffffff'; c.fillRect(bs, bh, Math.max(0, (bw - bs) * 0.78), h - bh);
  K.flag.slice(0, 3).forEach((col, i) => { c.fillStyle = col; c.fillRect(bs + i * h * 0.06, bh - h * 0.05, h * 0.06, h * 0.05); });
  c.save(); c.beginPath(); c.rect(bs, 0, Math.max(0, bw - bs), h); c.clip();
  A.uiText(c, (txt.name || W2.lowerName).toUpperCase(), bs + h * 0.22, bh * 0.68, bh * 0.5, { col: onColor(P.primary), weight: 900, maxW: w - bs - h * 0.4, track: 0.04 });
  A.uiText(c, txt.role || W2.lowerRole, bs + h * 0.22, bh + (h - bh) * 0.72, (h - bh) * 0.58, { col: '#1b1f2e', weight: 700, maxW: (w - bs) * 0.74 });
  A.sheen(c, bs, 0, w - bs, bh, fr(S.tl / 3), 0.35);
  c.restore();
  // live dot
  const pu = 0.5 + 0.5 * Math.sin(S.t * 5); c.fillStyle = rgba('#ff3b3b', 0.5 + 0.5 * pu); c.beginPath(); c.arc(bw - h * 0.2, bh * 0.5, h * 0.06, 0, TAU); c.fill();
  c.restore();
};

// ── WIPE: diagonal colour slabs across the cut ──
D.wipe = (c, K, S) => {
  const { w, h, U } = S, p = clamp(S.tl / S.d);
  const cov = A.slabWipe(c, w, h, [...K.slabs, K.colors.accent], p, { dir: 1, skew: 0.22 });
  if (cov > 0.9) { const fh = U * 0.16; A.flagCloth(c, imgOf(K), K.flag, w / 2 - fh * 0.75, h / 2 - fh / 2, fh * 1.5, fh, S.t, { pole: false, strips: 12 }); }
  A.glint(c, w * (1.1 - p * 1.2), h * 0.4, U * 0.08, Math.sin(Math.PI * p), '#ffffff');
};

// ── GLOBE: spin from the UAE toward the destination, the arc draws, a plane rides it ──
D.globe = (c, K, S) => {
  const { w, h } = S, ii = S.inn(0.45), oo = S.out(0.35), k = Math.min(ii, oo);
  if (k <= 0) return;
  const r = Math.min(w, h / 1.12) * 0.42, cx = w / 2, cy = r * 1.18;
  const prog = easeIO((S.tl - 0.35) / Math.max(0.6, S.d * 0.62));
  const mid = A.slerp(K.from, K.to, 0.5), lon0 = K.from[0] + ((mid[0] - K.from[0]) * easeIO(S.tl / Math.max(0.5, S.d * 0.7))) - 8 + S.tl * 4, lat0 = 18 + (mid[1] - 18) * 0.5;
  c.save(); c.translate(cx, cy); const z = back(ii) * (0.6 + 0.4 * oo); c.scale(z, z); c.translate(-cx, -cy);
  A.globe(c, cx, cy, r, { lon0, lat0, pal: { ocean: mix('#1f7ae0', K.colors.primary, 0.15), ocean2: mix('#041b4d', K.colors.primary, 0.12), land: '#f4f8ff', arc: K.colors.accent === '#ffffff' ? '#ffd166' : K.colors.accent, glow: '#8fd8ff' }, from: K.from, to: K.to, prog, labels: [K.fromCode, K.iata], t: S.t });
  // route chip
  const chW = w * 0.82, chH = h * 0.1, chY = cy + r * 1.2;
  c.fillStyle = 'rgba(8,12,30,0.82)'; c.beginPath(); A.rr(c, cx - chW / 2, chY, chW, chH, chH / 2); c.fill();
  c.fillStyle = K.colors.accent; c.beginPath(); A.rr(c, cx - chW / 2, chY, chH * 1.6, chH, chH / 2); c.fill();
  A.plane(c, cx - chW / 2 + chH * 0.8, chY + chH / 2, chH * 0.32, 0, '#ffffff', false);
  A.uiText(c, `${S.txt.from || (K.fromCode === 'DXB' ? 'Dubai' : 'Mumbai')}  →  ${S.txt.to || K.city}`, cx + chH * 0.5, chY + chH * 0.66, chH * 0.42, { col: '#ffffff', align: 'center', weight: 800, maxW: chW - chH * 2 });
  c.restore();
};

// ── STAMP: a passport page, the country stamp slams, then VISA APPROVED ──
D.stamp = (c, K, S) => {
  const { w, h } = S, ii = S.inn(0.35), oo = S.out(0.3), k = Math.min(ii, oo);
  if (k <= 0) return;
  const hit1 = 0.45, hit2 = Math.min(S.d - 0.7, 1.15);
  const shake = (t0) => { const d = S.tl - t0; return d > 0 && d < 0.18 ? Math.sin(d * 90) * (1 - d / 0.18) * h * 0.012 : 0; };
  c.save(); c.globalAlpha *= clamp(k * 2); c.translate(shake(hit1) + shake(hit2), (1 - ii) * h * 0.25);
  A.passportPage(c, 0, 0, w, h, K);
  const st = (t0, x, y, r, text, sub, col, rot, kind) => {
    const d = S.tl - t0; if (d < -0.12) return;
    const a = d < 0 ? clamp(1 + d / 0.12) * 0.4 : 1, s = d < 0 ? 2.4 : 1 + 1.4 * Math.pow(1 - clamp(d / 0.12), 2) * 0;
    c.save(); c.translate(x, y); const sc = d < 0 ? 2.2 - (1 + d / 0.12) * 1.2 : 1; c.scale(sc, sc);
    A.stampMark(c, 0, 0, r, text, sub, col, rot, a * 0.92, kind); c.restore();
    if (d > 0 && d < 0.4) { c.save(); c.globalAlpha *= 1 - d / 0.4; c.strokeStyle = col; c.lineWidth = r * 0.04; c.beginPath(); c.arc(x, y, r * (1 + d * 3), 0, TAU); c.stroke(); c.restore(); }
    return s;
  };
  st(hit1, w * 0.72, h * 0.42, h * 0.24, S.txt.stamp || (K.city || K.name).toUpperCase(), K.iata || '✈', mix(K.colors.primary, '#1a1a6b', 0.3), -0.22, 'round');
  st(hit2, w * 0.7, h * 0.76, h * 0.17, S.txt.approved || 'VISA APPROVED', S.txt.date || 'DD · MM · YYYY', '#1f9d55', 0.12, 'rect');
  c.restore();
};

// ── TICKET: a boarding pass slides in with a tilt, a plane passes ──
D.ticket = (c, K, S) => {
  const { w, h } = S, ii = S.inn(0.5), oo = S.out(0.35);
  if (oo <= 0) return;
  const x = (1 - back(ii)) * w * 1.1 - (1 - oo) * w * 1.2, rot = (1 - ii) * 0.12 - (1 - oo) * 0.08 + Math.sin(S.t * 1.4) * 0.008;
  c.save(); c.translate(w / 2 + x, h / 2); c.rotate(rot); c.translate(-w / 2, -h / 2);
  A.boardingPass(c, 0, 0, w, h, K, S.tl, { from: S.txt.from || K.fromCode, to: S.txt.to || K.iata, flight: S.txt.flight, gate: S.txt.gate, seat: S.txt.seat, date: S.txt.date, brand: S.txt.brand || 'BOARDING PASS' });
  c.restore();
  const pp = clamp((S.tl - 0.6) / 1.2); if (pp > 0 && pp < 1) A.plane(c, -w * 0.1 + pp * w * 1.2, h * (0.05 - 0.1 * Math.sin(pp * Math.PI)), h * 0.16, -0.12, '#ffffff');
};

// ── HIRING: the banner slams in, job-role badges pop in, they rotate through the theme's roles ──
D.hiring = (c, K, S) => {
  const { w, h } = S, P = K.colors, ii = S.inn(0.4), oo = S.out(0.3), txt = S.txt, W2 = K.words;
  if (oo <= 0) return;
  c.save(); c.globalAlpha *= clamp(oo * 2);
  const bh = h * 0.34, yb = (1 - back(ii)) * -h * 0.6;
  const acc = P.accent === '#ffffff' || A.lum(P.accent) > 0.92 ? '#ffd166' : P.accent;
  A.slab(c, w * 0.02, yb + h * 0.03, w * 0.96, bh, 0.25, 'rgba(0,0,0,0.3)');
  A.slab(c, 0, yb, w * 0.96, bh, 0.25, acc);
  A.kineticText(c, txt.hiring || W2.hiring, w * 0.48, yb + bh * 0.8, bh * 0.68, clamp(S.tl / 0.6), { col: onColor(acc), maxW: w * 0.82, shadow: false });
  A.slab(c, w * 0.5, yb + bh * 0.98, w * 0.46, bh * 0.3, 0.5, P.primary);
  A.arabicText(c, txt.hiringAr ?? W2.hiringAr, w * 0.92, yb + bh * 1.21, bh * 0.22, clamp((S.tl - 0.3) / 0.4), { col: onColor(P.primary), align: 'right', maxW: w * 0.42 });
  // three role badges; after 1.8 s they slide on to the next roles
  const roles = K.roles, per = 1.8, step = Math.floor(Math.max(0, S.tl - 0.6) / per), ph = clamp((Math.max(0, S.tl - 0.6) % per) / 0.35);
  const r = Math.min(w * 0.13, h * 0.2), y = h * 0.66;
  const nb = Math.min(3, roles.length);
  for (let i = 0; i < nb; i++) {
    const role = roles[(step * nb + i) % roles.length], d = S.tl - 0.45 - i * 0.12, s = step === 0 || roles.length <= nb ? elastic(clamp(d / 0.6)) : 0.85 + 0.15 * easeOut(ph);
    if (s <= 0.01) continue;
    const x = w * (0.5 + (i - (nb - 1) / 2) * 0.3);
    c.save(); c.translate(x, y); c.scale(s, s); A.roleBadge(c, role, 0, 0, r, '#ffffff', P.primary); c.restore();
    const R0 = A.ROLES[role];
    c.save(); c.globalAlpha *= clamp(s);
    const lw = r * 2.1, lh = r * 0.42; c.fillStyle = 'rgba(8,12,30,0.8)'; c.beginPath(); A.rr(c, x - lw / 2, y + r * 1.08, lw, lh, lh / 2); c.fill();
    A.uiText(c, (txt['role' + i] || R0.en).toUpperCase(), x, y + r * 1.08 + lh * 0.72, lh * 0.55, { col: '#ffffff', align: 'center', weight: 800, maxW: lw * 0.9 });
    c.restore();
  }
  c.restore();
};

// ── COUNTER: a number card counting up to an editable placeholder ──
D.counter = (c, K, S) => {
  const { w, h } = S, P = K.colors, ii = S.inn(0.4), oo = S.out(0.3), txt = S.txt;
  if (oo <= 0) return;
  const raw = String(txt.value ?? '100+'), m = /^(\D*)([\d.,]+)(.*)$/.exec(raw), target = m ? parseFloat(m[2].replace(/,/g, '')) : 0;
  const k = easeOut((S.tl - 0.25) / 1.3), n = m ? Math.round(target * k) : 0, shown = m ? m[1] + n.toLocaleString('en-US') + (k > 0.98 ? m[3] : '') : raw;
  c.save(); c.globalAlpha *= clamp(oo * 2); c.translate(0, (1 - back(ii)) * h * 0.3);
  c.fillStyle = 'rgba(0,0,0,0.3)'; c.beginPath(); A.rr(c, w * 0.02, h * 0.05, w, h * 0.95, h * 0.12); c.fill();
  const g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, mix(P.primary, '#ffffff', 0.1)); g.addColorStop(1, mix(P.primary, '#000000', 0.3));
  c.fillStyle = g; c.beginPath(); A.rr(c, 0, 0, w, h * 0.92, h * 0.12); c.fill();
  c.fillStyle = P.accent; c.fillRect(0, h * 0.8, w * easeOut((S.tl - 0.2) / 0.5), h * 0.06);
  A.coin(c, w * 0.16, h * 0.4, h * 0.2, K.sign, Math.cos(S.t * 2.2), '#f6c445');
  c.font = `400 ${Math.round(h * 0.5)}px ${A.FONT_EN}`; c.textAlign = 'left'; c.textBaseline = 'alphabetic'; c.fillStyle = onColor(P.primary);
  c.fillText(shown, w * 0.32, h * 0.6, w * 0.64);
  A.uiText(c, txt.label || 'Your number · edit me', w * 0.32, h * 0.74, h * 0.09, { col: rgba(onColor(P.primary), 0.85), weight: 700, maxW: w * 0.64 });
  A.sheen(c, 0, 0, w, h * 0.92, fr(S.tl / 2.5), 0.25);
  c.restore();
};

// ── POSTCARD: the hero landmark scene on a tilted card ──
D.postcard = (c, K, S) => {
  const { w, h } = S, ii = S.inn(0.5), oo = S.out(0.35), k = Math.min(ii, oo);
  if (k <= 0) return;
  c.save(); c.translate(w / 2, h / 2); c.rotate(0.05 - (1 - ii) * 0.35 + (1 - oo) * 0.3); const z = back(ii) * (0.5 + 0.5 * oo); c.scale(z, z); c.translate(-w / 2, -h / 2);
  c.fillStyle = 'rgba(0,0,0,0.35)'; c.fillRect(w * 0.025, h * 0.03, w, h);
  c.fillStyle = '#fffaf0'; c.fillRect(0, 0, w, h);
  const m = w * 0.04; A.scene(c, K, m, m, w - 2 * m, h * 0.78 - m, S.t, K.light);
  A.uiText(c, (S.txt.greet || 'GREETINGS FROM').toUpperCase(), m, h * 0.87, h * 0.05, { col: '#6b6f80', weight: 800, track: 0.2 });
  c.font = `400 ${Math.round(h * 0.11)}px ${A.FONT_EN}`; c.fillStyle = K.colors.primary === '#ffffff' ? '#222' : K.colors.primary; c.textAlign = 'left'; c.fillText((S.txt.title || K.city || K.name).toUpperCase(), m, h * 0.97, w * 0.62);
  A.arabicText(c, S.txt.titleAr ?? K.nameAr, w - m, h * 0.96, h * 0.07, 1, { col: '#333333', align: 'right', maxW: w * 0.3 });
  // a little flag "postage stamp"
  const ps = w * 0.16; c.fillStyle = '#ffffff'; c.fillRect(w - m - ps, m * 1.5, ps, ps * 0.8); c.save(); c.beginPath(); c.rect(w - m - ps + ps * 0.08, m * 1.5 + ps * 0.08, ps * 0.84, ps * 0.64); c.clip();
  A.flagCloth(c, imgOf(K), K.flag, w - m - ps + ps * 0.08, m * 1.5 + ps * 0.08, ps * 0.84, ps * 0.64, S.t, { pole: false, strips: 8, amp: 0.04 }); c.restore();
  c.restore();
};

// ── MAP: the country's outline filled with its flag, a pulsing pin and the city ──
D.map = (c, K, S) => {
  const { w, h } = S, ii = S.inn(0.5), oo = S.out(0.35), k = Math.min(ii, oo), s = Math.min(w, h / 1.1);
  if (k <= 0) return;
  const paths = K.code ? mapPaths(K.code) : null;
  c.save(); c.globalAlpha *= clamp(k * 2); c.translate(w / 2, s / 2); c.rotate((1 - ii) * -0.4); const z = back(ii); c.scale(z, z); c.translate(-s / 2, -s / 2);
  const g = c.createRadialGradient(s / 2, s / 2, 0, s / 2, s / 2, s * 0.55); g.addColorStop(0, rgba(K.colors.primary, 0.85)); g.addColorStop(1, rgba(mix(K.colors.primary, '#000000', 0.5), 0.85));
  c.fillStyle = g; c.beginPath(); c.arc(s / 2, s / 2, s * 0.5, 0, TAU); c.fill();
  c.strokeStyle = rgba('#ffffff', 0.8); c.lineWidth = s * 0.012; c.stroke();
  if (paths && paths.length) {
    const ms = s * 0.78, mx = (s - ms) / 2;
    // flag-colour bands clipped to the country
    c.save(); c.translate(mx, mx); c.scale(ms / 200, ms / 200);
    const clip = new Path2D(); for (const p of paths) clip.addPath(p);
    c.shadowColor = 'rgba(0,0,0,0.5)'; c.shadowBlur = 12; c.fillStyle = '#ffffff'; c.fill(clip); c.shadowBlur = 0;
    c.save(); c.clip(clip);
    const fl = K.flag, n = fl.length; for (let i = 0; i < n; i++) { c.fillStyle = fl[i]; c.fillRect(0, (200 * i) / n, 200, 200 / n + 1); }
    const sh = c.createLinearGradient(0, 0, 200, 200); sh.addColorStop(0, 'rgba(255,255,255,0.35)'); sh.addColorStop(0.5, 'rgba(255,255,255,0)'); sh.addColorStop(1, 'rgba(0,0,0,0.3)'); c.fillStyle = sh; c.fillRect(0, 0, 200, 200);
    c.restore();
    c.strokeStyle = '#ffffff'; c.lineWidth = 2.2; c.lineJoin = 'round'; c.stroke(clip);
    c.restore();
  } else { A.flagCloth(c, imgOf(K), K.flag, s * 0.2, s * 0.32, s * 0.6, s * 0.4, S.t, { pole: false }); }
  const pu = fr(S.t * 0.8), px = s * 0.5, py = s * 0.5;
  c.strokeStyle = rgba('#ffffff', 1 - pu); c.lineWidth = s * 0.008; c.beginPath(); c.arc(px, py, s * 0.03 * (1 + 3 * pu), 0, TAU); c.stroke();
  c.fillStyle = K.colors.accent === '#ffffff' ? '#ffd166' : K.colors.accent; c.beginPath(); c.arc(px, py, s * 0.025, 0, TAU); c.fill();
  c.restore();
  const lw = w * 0.86, lh = h - s - h * 0.01, ly = s + h * 0.005;
  if (lh > 4) { c.save(); c.globalAlpha *= clamp(k * 2); c.fillStyle = 'rgba(8,12,30,0.82)'; c.beginPath(); A.rr(c, (w - lw) / 2, ly, lw, lh, lh / 2); c.fill(); A.uiText(c, (S.txt.title || K.name).toUpperCase(), w / 2, ly + lh * 0.68, lh * 0.5, { col: '#ffffff', align: 'center', weight: 900, maxW: lw * 0.86, track: 0.06 }); c.restore(); }
};

// ── CONFETTI ──
D.confetti = (c, K, S) => {
  const { w, h, U } = S, cols = [...new Set([...K.flag, K.colors.accent, '#ffffff', '#ffd166'])];
  A.confettiBurst(c, -U * 0.02, h * 0.92, S.tl, U, cols, { ang: -Math.PI * 0.32, spread: 0.32, speed: 1.6, seed: 3 + S.k, n: 46 });
  A.confettiBurst(c, w + U * 0.02, h * 0.92, S.tl - 0.12, U, cols, { ang: -Math.PI * 0.68, spread: 0.32, speed: 1.6, seed: 9 + S.k, n: 46 });
  c.save(); A.sparkles(c, w, h * 0.6, S.t, 10, '#ffffff', U); c.restore();
};

// ── END CARD: CTA + contact placeholders over the landmark scene and the flag colours ──
D.end = (c, K, S) => {
  const { w, h, U } = S, P = K.colors, txt = S.txt, p = S.tl;
  const sp = clamp(p / 0.5) * 0.5;   // slabs in, then the card under them
  if (p > 0.24) {
    const cp = clamp((p - 0.24) / 0.9);
    const top = h * 0.46;
    A.scene(c, K, 0, 0, w, top + U * 0.16, S.t, K.light);
    c.save(); c.beginPath(); c.moveTo(0, top + U * 0.12); c.lineTo(w, top - U * 0.04); c.lineTo(w, h); c.lineTo(0, h); c.closePath(); c.clip(); A.gradRect(c, 0, top - U * 0.05, w, h - top + U * 0.05, [[0, mix(P.primary, '#ffffff', 0.05)], [1, mix(P.primary, '#000000', 0.45)]]); c.restore();
    c.fillStyle = g; c.beginPath(); c.moveTo(0, top + U * 0.12); c.lineTo(w, top - U * 0.04); c.lineTo(w, h); c.lineTo(0, h); c.closePath(); c.fill();
    K.flag.slice(0, 3).forEach((col, i) => { c.fillStyle = col; c.beginPath(); const y0 = top + U * 0.12 - i * U * 0.018; c.moveTo(0, y0); c.lineTo(w, y0 - U * 0.16); c.lineTo(w, y0 - U * 0.16 - U * 0.016); c.lineTo(0, y0 - U * 0.016); c.closePath(); c.fill(); });
    const fh = U * 0.12; A.flagCloth(c, imgOf(K), K.flag, U * 0.08, top + U * 0.2, fh * 1.5, fh, S.t, { pole: true, strips: 14 });
    A.uiText(c, (txt.brand || S.brand || 'YOUR COMPANY').toUpperCase(), U * 0.08 + fh * 1.75, top + U * 0.29, U * 0.05, { col: rgba('#ffffff', clamp(cp * 3)), weight: 900, track: 0.12, maxW: w - U * 0.3 - fh * 1.75 });
    const cy = top + U * 0.58;
    A.kineticText(c, txt.cta || 'BOOK YOUR FREE CONSULTATION', w / 2, cy, U * 0.11, cp * 1.4, { col: '#ffffff', maxW: w * 0.9 });
    A.arabicText(c, txt.ctaAr ?? 'احجز استشارتك المجانية', w / 2, cy + U * 0.1, U * 0.06, clamp(cp * 2 - 0.3), { col: P.accent === '#ffffff' ? '#ffd166' : mix(P.accent, '#ffffff', 0.2), maxW: w * 0.9 });
    const rows = [['CALL', txt.phone || '+971 XX XXX XXXX'], ['WEB', txt.web || 'www.yourwebsite.com'], ['FOLLOW', txt.handle || '@yourhandle']];
    const pw = Math.min(w * 0.86, U * 0.95), ph = U * 0.085;
    rows.forEach(([k, v], i) => {
      const a = easeOut(cp * 2.2 - 0.5 - i * 0.25); if (a <= 0) return;
      const y = cy + U * 0.17 + i * ph * 1.22, x = (w - pw) / 2 + (1 - a) * w * 0.3;
      c.save(); c.globalAlpha *= a; c.fillStyle = 'rgba(255,255,255,0.95)'; c.beginPath(); A.rr(c, x, y, pw, ph, ph / 2); c.fill();
      c.fillStyle = P.accent === '#ffffff' || A.lum(P.accent) > 0.9 ? P.primary : P.accent; c.beginPath(); A.rr(c, x, y, ph * 2.3, ph, ph / 2); c.fill();
      A.uiText(c, k, x + ph * 1.15, y + ph * 0.66, ph * 0.38, { col: onColor(c.fillStyle), align: 'center', weight: 900, track: 0.1 });
      A.uiText(c, v, x + ph * 2.6, y + ph * 0.68, ph * 0.46, { col: '#14182b', weight: 800, maxW: pw - ph * 3 });
      c.restore();
    });
    A.lightLeaks(c, w, h, S.t, [P.accent, '#ff8a3d'], 0.6, 2);
    A.sparkles(c, w, top, S.t, 8, '#ffffff', U);
  }
  if (p < 0.5) A.slabWipe(c, w, h, K.slabs, sp, { dir: -1 });
};

// ── GRADE: the theme's tint over the picture (soft light), a vignette and a faint leak ──
D.grade = (c, K, S) => {
  if (S.covered) return;
  // a tint from the top in the primary, warm from the bottom in the accent, a clear middle (skin stays natural) and a
  // vignette — baked into one small picture and stretched over the frame (one blit instead of two full-frame gradients)
  const { w, h } = S, P = K.colors, a = (S.style.amount ?? 1) * Math.min(S.inn(0.6), S.out(0.6));
  if (a <= 0.01) return;
  const warm = P.accent === '#ffffff' || A.lum(P.accent) > 0.92 ? '#ffb36b' : P.accent;
  const sp = A.cached(`gradefx:${P.primary}:${warm}`, 90, 160, (g, W2, H2) => {
    const gr = g.createLinearGradient(0, 0, 0, H2); gr.addColorStop(0, rgba(P.primary, 0.34)); gr.addColorStop(0.3, rgba(P.primary, 0.08)); gr.addColorStop(0.62, rgba(warm, 0.04)); gr.addColorStop(1, rgba(warm, 0.3));
    g.fillStyle = gr; g.fillRect(0, 0, W2, H2);
    const v = g.createRadialGradient(W2 / 2, H2 * 0.45, W2 * 0.35, W2 / 2, H2 * 0.45, Math.hypot(W2, H2) * 0.62); v.addColorStop(0, 'rgba(0,0,0,0)'); v.addColorStop(1, rgba(mix(P.primary, '#000000', 0.7), 0.45));
    g.fillStyle = v; g.fillRect(0, 0, W2, H2);
  });
  c.globalAlpha *= a; c.drawImage(sp, 0, 0, w, h);
};

// ── STICKERS ──
D.sticker = (c, K, S) => {
  const { w, h } = S, s = Math.min(w, h), ii = S.inn(0.5), oo = S.out(0.3), k = elastic(clamp(S.tl / 0.7)) * oo;
  if (k <= 0.01) return;
  const v = S.style.variant || 'flag', P = K.colors, r = s * 0.42;
  c.save(); c.translate(w / 2, h / 2 + Math.sin(S.t * 2.2) * s * 0.02); c.rotate(Math.sin(S.t * 1.6) * 0.06); c.scale(k * (1 + 0.08 * S.pulse), k * (1 + 0.08 * S.pulse)); c.globalAlpha *= clamp(ii * 3);
  const disc = (fill) => { c.fillStyle = 'rgba(0,0,0,0.3)'; c.beginPath(); c.arc(s * 0.02, s * 0.03, r, 0, TAU); c.fill(); c.fillStyle = fill; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.fill(); };
  const ring = () => { c.strokeStyle = '#ffffff'; c.lineWidth = s * 0.05; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.stroke(); };
  if (v === 'flag') { disc('#ffffff'); c.save(); c.beginPath(); c.arc(0, 0, r * 0.94, 0, TAU); c.clip(); A.flagCloth(c, imgOf(K), K.flag, -r * 1.25, -r * 0.95, r * 2.5, r * 1.9, S.t, { pole: false, strips: 14, amp: 0.05 }); c.restore(); ring(); }
  else if (v === 'visa') { disc('#ffffff'); A.stampMark(c, 0, 0, r * 0.86, 'VISA APPROVED', '✓', '#1f9d55', -0.18, 1, 'round'); ring(); }
  else if (v === 'plane') { disc(P.primary); c.strokeStyle = rgba('#ffffff', 0.6); c.lineWidth = s * 0.02; c.setLineDash([s * 0.04, s * 0.03]); c.beginPath(); c.arc(0, 0, r * 0.62, Math.PI * 0.7, Math.PI * 0.7 + fr(S.t * 0.5) * TAU); c.stroke(); c.setLineDash([]); const a = Math.PI * 0.7 + fr(S.t * 0.5) * TAU; A.plane(c, Math.cos(a) * r * 0.62, Math.sin(a) * r * 0.62, r * 0.36, a + Math.PI / 2, '#ffffff'); ring(); }
  else if (v === 'pin') { c.fillStyle = 'rgba(0,0,0,0.3)'; c.beginPath(); c.ellipse(0, r * 1.05, r * 0.35, r * 0.1, 0, 0, TAU); c.fill(); c.fillStyle = P.primary === '#ffffff' ? '#e63946' : P.primary; c.beginPath(); c.moveTo(0, r * 1.0); c.bezierCurveTo(-r * 0.6, r * 0.25, -r * 0.85, -r * 0.15, -r * 0.85, -r * 0.35); c.arc(0, -r * 0.35, r * 0.85, Math.PI, 0); c.bezierCurveTo(r * 0.85, -r * 0.15, r * 0.6, r * 0.25, 0, r * 1.0); c.fill(); c.save(); c.beginPath(); c.arc(0, -r * 0.35, r * 0.58, 0, TAU); c.clip(); A.flagCloth(c, imgOf(K), K.flag, -r * 0.8, -r * 0.95, r * 1.6, r * 1.2, S.t, { pole: false, strips: 10, amp: 0.05 }); c.restore(); c.strokeStyle = '#ffffff'; c.lineWidth = s * 0.03; c.beginPath(); c.arc(0, -r * 0.35, r * 0.58, 0, TAU); c.stroke(); }
  else if (v === 'coin') { A.coin(c, 0, 0, r, K.sign, Math.cos(S.t * 2.4), '#f6c445'); A.glint(c, -r * 0.4, -r * 0.4, r * 0.3, 0.5 + 0.5 * Math.sin(S.t * 3), '#ffffff'); }
  else if (v === 'landmark') { disc(K.colors.primary); c.save(); c.beginPath(); c.arc(0, 0, r * 0.94, 0, TAU); c.clip(); A.scene(c, K, -r, -r, r * 2, r * 2, S.t, K.light); c.restore(); ring(); }
  else { for (let i = 0; i < 3; i++) { const a = S.t * 0.8 + i * 2.1, rr2 = r * (0.3 + 0.35 * i); c.fillStyle = i ? '#ffffff' : (P.accent === '#ffffff' ? '#ffd166' : P.accent); A.star(c, Math.cos(a) * r * 0.3 * i, Math.sin(a) * r * 0.3 * i, rr2 * (0.7 + 0.3 * Math.sin(S.t * 4 + i)), 4, 0.22, S.t * 0.5); c.fill(); } }
  c.restore();
};


// ── BULLETIN TEMPLATES (2026-11, the owner: "proper templates like bulletin"): broadcast-size, every word editable,
//    every fact a placeholder, no real channel's name or look ──
const acc = (K) => (K.colors.accent === '#ffffff' || A.lum(K.colors.accent) > 0.92 ? '#ffd166' : K.colors.accent);
const crawlText = (K, txt) => txt.crawl || `${(K.name || '').toUpperCase()} · YOUR HEADLINE HERE · BOOK A FREE CONSULTATION · PLACEHOLDER UPDATE — VERIFY ON THE OFFICIAL WEBSITE · `;
/** a news crawl: a label block and text scrolling right → left inside (x, y, w, h) */
function crawl(c, K, x, y, w, h, t, text, label = 'LIVE') {
  c.save(); c.fillStyle = 'rgba(8,10,24,0.92)'; c.fillRect(x, y, w, h);
  const lw = h * 3.2; c.fillStyle = '#d7263d'; c.fillRect(x, y, lw, h);
  A.uiText(c, label, x + lw / 2, y + h * 0.68, h * 0.46, { col: '#ffffff', align: 'center', weight: 900, track: 0.08, maxW: lw * 0.88 });
  c.beginPath(); c.rect(x + lw, y, w - lw, h); c.clip();
  c.font = `700 ${Math.round(h * 0.5)}px ${A.FONT_UI}`; c.textBaseline = 'alphabetic'; c.textAlign = 'left'; c.fillStyle = '#ffffff';
  const tw = Math.max(1, c.measureText(text).width), off = (t * h * 2.4) % tw;
  for (let xx = x + lw + h * 0.4 - off; xx < x + w; xx += tw) c.fillText(text, xx, y + h * 0.68);
  c.restore();
}
/** a full-bleed waving flag (or the theme's emblem), darkened toward the bottom */
function flagField(c, K, w, h, t, dark = 0.55) {
  A.flagCloth(c, imgOf(K), K.flag, -w * 0.06, -h * 0.03, w * 1.12, h * 1.06, t, { pole: false, strips: 24, amp: 0.025, waves: 1.2, speed: 1.6 });
  A.gradRect(c, 0, 0, w, h, [[0, 'rgba(0,0,0,0.15)'], [0.45, rgba(mix(K.colors.primary, '#000000', 0.6), dark * 0.6)], [1, rgba(mix(K.colors.primary, '#000000', 0.7), dark + 0.3)]]);
}

// BREAKING-style headline band with a crawl
D.breaking = (c, K, S) => {
  const { w, h } = S, P = K.colors, txt = S.txt, ii = S.inn(0.5), oo = S.out(0.35), k = Math.min(ii, oo);
  if (k <= 0) return;
  const tagH = h * 0.26, bandY = tagH, bandH = h * 0.46, crawlY = bandY + bandH, crawlH = h - crawlY;
  c.save(); c.beginPath(); c.rect(0, 0, w * easeOut(k), h); c.clip();
  c.fillStyle = 'rgba(0,0,0,0.3)'; c.fillRect(h * 0.04, h * 0.06, w, h);
  // tag row: the label block + a live dot + a time slot
  const tagW = Math.min(w * 0.46, h * 1.55);
  c.fillStyle = '#d7263d'; c.fillRect(0, 0, tagW, tagH);
  A.kineticText(c, txt.tag || 'BREAKING', tagW / 2, tagH * 0.8, tagH * 0.72, clamp(S.tl / 0.5), { col: '#ffffff', maxW: tagW * 0.86, shadow: false });
  c.fillStyle = mix(P.primary, '#000000', 0.35); c.fillRect(tagW, 0, w - tagW, tagH);
  const pu = 0.5 + 0.5 * Math.sin(S.t * 6); c.fillStyle = rgba('#ff4d4d', 0.5 + 0.5 * pu); c.beginPath(); c.arc(tagW + tagH * 0.5, tagH * 0.5, tagH * 0.17, 0, TAU); c.fill();
  A.uiText(c, txt.time || 'LIVE · 00:00', tagW + tagH * 0.85, tagH * 0.68, tagH * 0.42, { col: '#ffffff', weight: 800, maxW: w - tagW - tagH });
  // the headline band in the flag's colours
  const g = c.createLinearGradient(0, bandY, w, bandY + bandH); g.addColorStop(0, mix(P.primary, '#ffffff', 0.08)); g.addColorStop(1, mix(P.primary, '#000000', 0.2));
  c.fillStyle = g; c.fillRect(0, bandY, w, bandH);
  K.flag.slice(0, 3).forEach((col, i) => { c.fillStyle = col; c.fillRect(0, bandY + (i * bandH) / 3, h * 0.06, bandH / 3 + 0.5); });
  A.kineticText(c, txt.headline || `STUDY & WORK IN ${(K.name || '').toUpperCase()}`, h * 0.2, bandY + bandH * 0.72, bandH * 0.56, clamp((S.tl - 0.15) / 0.6), { col: onColor(P.primary), align: 'left', maxW: w - h * 0.4 });
  A.sheen(c, 0, bandY, w, bandH, fr(S.tl / 2.6), 0.3);
  crawl(c, K, 0, crawlY, w, crawlH, S.t, crawlText(K, txt), txt.crawlTag || 'UPDATE');
  c.restore();
};

// a full-screen COUNTRY BULLETIN card
D.bulletin = (c, K, S) => {
  const { w, h, U } = S, P = K.colors, txt = S.txt, W2 = K.words, d = S.d, tl = S.tl;
  const cover = tl > 0.24 && tl < d - 0.22;
  if (cover) {
    flagField(c, K, w, h, S.t);
    if (K.landmark || K.skyline) A.landmark(c, K.landmark || K.skyline[0], w * 0.72, h * 1.0, Math.min(w * 0.75, h * 0.48), K.light === 'day' ? 'gold' : K.light, 0.95);
    const cp = clamp((tl - 0.24) / 0.8);
    // top bar
    const tb = U * 0.11; c.fillStyle = 'rgba(8,10,24,0.88)'; c.fillRect(0, h * 0.05, w, tb);
    c.fillStyle = acc(K); c.fillRect(0, h * 0.05, U * 0.42, tb);
    A.uiText(c, txt.label || 'COUNTRY BULLETIN', U * 0.21, h * 0.05 + tb * 0.64, tb * 0.36, { col: onColor(acc(K)), align: 'center', weight: 900, track: 0.08, maxW: U * 0.4 });
    A.uiText(c, txt.date || 'DD MMM YYYY', w - U * 0.05, h * 0.05 + tb * 0.64, tb * 0.34, { col: '#ffffff', align: 'right', weight: 700 });
    // the country, big, in English and Arabic
    A.kineticText(c, txt.title || W2.title, U * 0.07, h * 0.36, U * 0.2, cp * 1.4, { col: '#ffffff', align: 'left', maxW: w - U * 0.14 });
    A.arabicText(c, txt.titleAr ?? W2.titleAr, w - U * 0.07, h * 0.36 + U * 0.12, U * 0.085, clamp(cp * 2 - 0.3), { col: '#ffffff', align: 'right', maxW: w * 0.8 });
    // headline + subline plate
    const py = h * 0.36 + U * 0.2, pw = w - U * 0.14, ph = U * 0.3, a = easeOut(cp * 2 - 0.4);
    c.save(); c.globalAlpha *= a; c.translate((1 - a) * -w * 0.2, 0);
    c.fillStyle = 'rgba(255,255,255,0.95)'; c.fillRect(U * 0.07, py, pw, ph); c.fillStyle = P.primary === '#ffffff' ? '#d7263d' : P.primary; c.fillRect(U * 0.07, py, U * 0.025, ph);
    A.uiText(c, txt.headline || 'Your headline here', U * 0.13, py + ph * 0.42, ph * 0.24, { col: '#11142a', weight: 900, maxW: pw - U * 0.1 });
    A.uiText(c, txt.sub || 'Subline: edit this in the inspector', U * 0.13, py + ph * 0.78, ph * 0.15, { col: '#3b4160', weight: 600, maxW: pw - U * 0.1 });
    c.restore();
    crawl(c, K, 0, h - U * 0.1, w, U * 0.07, S.t, crawlText(K, txt), txt.crawlTag || 'UPDATE');
    A.lightLeaks(c, w, h, S.t, [acc(K), '#ff8a3d'], 0.45, 2);
  }
  if (tl < 0.5) A.slabWipe(c, w, h, K.slabs, clamp(tl / 0.5) * 0.5, { dir: 1 });
  else if (tl > d - 0.45) A.slabWipe(c, w, h, K.slabs, 0.5 + clamp((tl - (d - 0.45)) / 0.45) * 0.5, { dir: 1 });
};

// SPLIT SCREEN: the presenter keeps talking in one half, the country panel fills the other
let SNAP = null;
D.split = (c, K, S) => {
  const { w, h, U } = S, P = K.colors, txt = S.txt, W2 = K.words, k = easeIO(Math.min(S.tl / 0.5, (S.d - S.tl) / 0.4));
  if (k <= 0.001) return;
  const land = w > h * 1.05, cv = c.canvas, m = c.getTransform(), sc = Math.hypot(m.a, m.b) || 1;
  if (!SNAP) SNAP = document.createElement('canvas');
  if (SNAP.width !== cv.width || SNAP.height !== cv.height) { SNAP.width = cv.width; SNAP.height = cv.height; }
  const sg = SNAP.getContext('2d'); sg.setTransform(1, 0, 0, 1, 0, 0); sg.clearRect(0, 0, SNAP.width, SNAP.height); sg.drawImage(cv, 0, 0);   // the picture so far
  const fy = (S.face?.y ?? 0.42) * h, fx = (S.face?.x ?? 0.5) * w;
  if (!land) {
    const top = h * 0.44 * k, srcY = Math.max(0, Math.min(h - (h - top), fy - (h - top) / 2)) * k;
    c.fillStyle = '#000'; c.fillRect(0, top, w, h - top);
    c.drawImage(SNAP, 0, srcY * sc, w * sc, (h - top) * sc, 0, top, w, h - top);
    c.save(); c.beginPath(); c.rect(0, 0, w, top); c.clip(); c.translate(0, top - h * 0.44);
    panel(c, K, S, 0, 0, w, h * 0.44); c.restore();
    K.flag.slice(0, 3).forEach((col, i) => { c.fillStyle = col; c.fillRect(0, top - U * 0.012 * (3 - i), w, U * 0.012); });
  } else {
    const left = w * 0.46 * k, srcX = Math.max(0, Math.min(w - (w - left), fx - (w - left) / 2)) * k;
    c.fillStyle = '#000'; c.fillRect(left, 0, w - left, h);
    c.drawImage(SNAP, srcX * sc, 0, (w - left) * sc, h * sc, left, 0, w - left, h);
    c.save(); c.beginPath(); c.rect(0, 0, left, h); c.clip(); c.translate(left - w * 0.46, 0);
    panel(c, K, S, 0, 0, w * 0.46, h); c.restore();
    K.flag.slice(0, 3).forEach((col, i) => { c.fillStyle = col; c.fillRect(left - U * 0.012 * (3 - i), 0, U * 0.012, h); });
  }
  function panel(g, K2, S2, x, y, pw, ph) {
    A.scene(g, K2, x, y, pw, ph, S2.t, K2.light, 0.7);
    const u2 = Math.min(pw, ph);
    g.fillStyle = 'rgba(8,10,24,0.85)'; g.fillRect(x, y + ph * 0.08, pw * 0.62, u2 * 0.11);
    A.uiText(g, txt.label || 'COUNTRY FOCUS', x + u2 * 0.06, y + ph * 0.08 + u2 * 0.075, u2 * 0.05, { col: '#ffffff', weight: 900, track: 0.12, maxW: pw * 0.56 });
    A.kineticText(g, txt.title || W2.title, x + u2 * 0.06, y + ph * 0.62, u2 * 0.2, clamp(S2.tl / 0.7), { col: '#ffffff', align: 'left', maxW: pw - u2 * 0.12 });
    A.arabicText(g, txt.titleAr ?? W2.titleAr, x + pw - u2 * 0.06, y + ph * 0.62 + u2 * 0.12, u2 * 0.08, clamp(S2.tl / 0.9), { col: '#ffffff', align: 'right', maxW: pw * 0.8 });
    A.uiText(g, txt.headline || 'Your headline here', x + u2 * 0.06, y + ph * 0.62 + u2 * 0.25, u2 * 0.06, { col: '#ffffff', weight: 800, maxW: pw - u2 * 0.12 });
  }
};

// the LOWER-THIRD BULLETIN BAR with a logo slot for the company's own logo
D.lowerbar = (c, K, S) => {
  const { w, h } = S, P = K.colors, txt = S.txt, W2 = K.words, ii = S.inn(0.45), oo = S.out(0.3), k = Math.min(ii, oo);
  if (k <= 0) return;
  const ls = h, top = h * 0.58;
  c.save(); c.globalAlpha *= clamp(k * 3);
  c.fillStyle = 'rgba(0,0,0,0.3)'; c.fillRect(h * 0.05, h * 0.08, w * easeOut(k), h);
  // logo slot: the company's logo on a white tile, or a placeholder
  const lg = S.style.logo ? logoImage(S.style.logo) : null;
  c.fillStyle = '#ffffff'; c.fillRect(0, 0, ls, ls);
  if (lg) { const r = Math.min((ls * 0.78) / lg.width, (ls * 0.78) / lg.height); c.drawImage(lg, (ls - lg.width * r) / 2, (ls - lg.height * r) / 2, lg.width * r, lg.height * r); }
  else { c.strokeStyle = '#9aa1b8'; c.setLineDash([ls * 0.06, ls * 0.04]); c.lineWidth = ls * 0.025; c.strokeRect(ls * 0.14, ls * 0.14, ls * 0.72, ls * 0.72); c.setLineDash([]); A.uiText(c, 'YOUR', ls / 2, ls * 0.47, ls * 0.14, { col: '#6b7290', align: 'center', weight: 900 }); A.uiText(c, 'LOGO', ls / 2, ls * 0.65, ls * 0.14, { col: '#6b7290', align: 'center', weight: 900 }); }
  const bw = (w - ls) * easeOut(k);
  c.save(); c.beginPath(); c.rect(ls, 0, bw, h); c.clip();
  const g = c.createLinearGradient(ls, 0, w, 0); g.addColorStop(0, P.primary); g.addColorStop(1, mix(P.primary, '#000000', 0.3));
  c.fillStyle = g; c.fillRect(ls, 0, w - ls, top);
  c.fillStyle = '#ffffff'; c.fillRect(ls, top, w - ls, h - top);
  c.fillStyle = acc(K); c.fillRect(ls, top - h * 0.05, w - ls, h * 0.05);
  A.uiText(c, (txt.name || W2.lowerName).toUpperCase(), ls + h * 0.2, top * 0.7, top * 0.5, { col: onColor(P.primary), weight: 900, maxW: w - ls - h * 1.6, track: 0.03 });
  A.uiText(c, txt.role || W2.lowerRole, ls + h * 0.2, top + (h - top) * 0.72, (h - top) * 0.55, { col: '#14182b', weight: 700, maxW: w - ls - h * 0.4 });
  // a LIVE tag and a clock slot on the right
  const tw = h * 1.1; c.fillStyle = '#d7263d'; c.fillRect(w - tw, 0, tw, top * 0.5);
  A.uiText(c, txt.tag || 'LIVE', w - tw / 2, top * 0.38, top * 0.3, { col: '#ffffff', align: 'center', weight: 900, track: 0.1 });
  A.uiText(c, txt.time || '00:00', w - tw / 2, top * 0.86, top * 0.28, { col: rgba('#ffffff', 0.9), align: 'center', weight: 800 });
  A.sheen(c, ls, 0, w - ls, top, fr(S.tl / 3), 0.35);
  c.restore(); c.restore();
};

// an animated VISA UPDATE board: editable placeholder points appear one by one
D.visa = (c, K, S) => {
  const { w, h } = S, P = K.colors, txt = S.txt, ii = S.inn(0.45), oo = S.out(0.35), k = Math.min(ii, oo);
  if (k <= 0) return;
  c.save(); c.globalAlpha *= clamp(k * 2); c.translate(0, (1 - easeOut(ii)) * h * 0.15);
  c.fillStyle = 'rgba(0,0,0,0.35)'; c.fillRect(w * 0.02, h * 0.02, w, h);
  c.fillStyle = 'rgba(250,251,255,0.97)'; c.fillRect(0, 0, w, h);
  const hh = h * 0.2; const g = c.createLinearGradient(0, 0, w, 0); g.addColorStop(0, P.primary); g.addColorStop(1, mix(P.primary, '#000000', 0.3));
  c.fillStyle = g; c.fillRect(0, 0, w, hh);
  c.save(); c.beginPath(); c.rect(hh * 0.18, hh * 0.18, hh * 0.96, hh * 0.64); c.clip(); A.flagCloth(c, imgOf(K), K.flag, hh * 0.18, hh * 0.18, hh * 0.96, hh * 0.64, S.t, { pole: false, strips: 10, amp: 0.05 }); c.restore();
  A.kineticText(c, txt.title || 'VISA UPDATE', hh * 1.4, hh * 0.62, hh * 0.46, clamp(S.tl / 0.6), { col: onColor(P.primary), align: 'left', maxW: w - hh * 1.6, shadow: false });
  A.uiText(c, (txt.country || K.name || '').toUpperCase(), hh * 1.4, hh * 0.88, hh * 0.15, { col: rgba(onColor(P.primary), 0.85), weight: 800, track: 0.14, maxW: w - hh * 1.6 });
  const pts = [txt.p1 || 'Point one: edit me', txt.p2 || 'Point two: edit me', txt.p3 || 'Point three: edit me', txt.p4 || 'Point four: edit me'].filter((x) => x && x !== '-');
  const rowH = (h - hh - h * 0.14) / Math.max(3, pts.length), per = Math.max(0.35, (S.d - 1.2) / pts.length);
  const cur = Math.min(pts.length - 1, Math.floor(Math.max(0, S.tl - 0.5) / per));
  pts.forEach((pt, i) => {
    const a = easeOut((S.tl - 0.5 - i * per) / 0.35); if (a <= 0) return;
    const y = hh + h * 0.04 + i * rowH;
    if (i === cur) { c.fillStyle = rgba(acc(K), 0.22); c.fillRect(0, y, w, rowH * 0.9); }
    c.save(); c.globalAlpha *= a; c.translate((1 - a) * w * 0.1, 0);
    const r = rowH * 0.26, cx = w * 0.07, cy = y + rowH * 0.45;
    c.fillStyle = '#1f9d55'; c.beginPath(); c.arc(cx, cy, r, 0, TAU); c.fill();
    c.strokeStyle = '#ffffff'; c.lineWidth = r * 0.28; c.lineCap = 'round'; c.beginPath(); c.moveTo(cx - r * 0.45, cy); c.lineTo(cx - r * 0.1, cy + r * 0.35); c.lineTo(cx + r * 0.5, cy - r * 0.35); c.stroke();
    A.uiText(c, pt, w * 0.13, cy + rowH * 0.13, rowH * 0.34, { col: '#14182b', weight: 700, maxW: w * 0.83 });
    c.restore();
  });
  c.fillStyle = '#eef0f6'; c.fillRect(0, h - h * 0.1, w, h * 0.1);
  A.uiText(c, txt.note || 'Placeholder points: verify on the official government website before publishing.', w * 0.04, h - h * 0.035, h * 0.034, { col: '#5b6280', weight: 600, maxW: w * 0.92 });
  c.restore();
};

// ── the design families: the same drawings as the Ad Studio (themes/fam.js), re-timed to the clip's length ──
for (const def of ELEMENTS.filter((e) => e.fam)) {
  D[def.id] = (c, K, S) => {
    const { w, h } = S, U = Math.min(w, h), ii = S.inn(0.4), oo = S.out(0.35); if (oo <= 0 || ii <= 0) return;
    // the piece builds in about 3.8 s; a shorter clip plays it faster so it always finishes
    const ta = S.tl * Math.max(1, 3.8 / Math.max(0.6, S.d * 0.85));
    const words = Object.fromEntries(Object.entries(S.txt || {}).filter(([k, v]) => def.words.includes(k) && v));
    const card = def.box === 'famcard', r = card ? U * 0.05 : 0;
    c.save(); c.globalAlpha *= Math.min(ii, oo);
    const z = 0.94 + 0.06 * ii; c.translate(w / 2, h / 2); c.scale(z, z); c.translate(-w / 2, -h / 2);
    if (card) { c.save(); c.shadowColor = 'rgba(20,20,60,0.35)'; c.shadowBlur = U * 0.06; c.shadowOffsetY = U * 0.02; c.fillStyle = FAM_PAL[def.fam].paper; c.beginPath(); A.rr(c, 0, 0, w, h, r); c.fill(); c.restore(); c.beginPath(); A.rr(c, 0, 0, w, h, r); c.clip(); }
    drawFam(c, def.piece, { x: 0, y: 0, w, h }, ta, { fam: def.fam, K, flag: K.code ? flagImage(K.code) : null, words, role: S.style?.variant || undefined });
    c.restore();
  };
}

// ── CAPSULES CAROUSEL (./capsules.js): the item's own model, its time and the music's beats ──
D.capsules = (c, K, S) => drawCapsules(c, S.style.caps || defaultCaps(), { w: S.w, h: S.h, t: S.tl, tg: S.t, beats: S.beats });

export const ELEMENT_DRAW = D;

// ── one renderer for theme.el, theme.sting, theme.outro ──
export function drawElement(ctx, el, item, project, env, styleOverride, inLayer = false) {
  const def = elementById(el), s = styleOverride || item.style || {};
  const K = kitOfItem(item, project), local = env.local, dur = Math.max(0.1, (item.out - item.in) / (item.speed || 1));
  const T = timing(def, local, dur), W = project.canvas.w, H = project.canvas.h;
  const fn = D[el]; if (!fn) return;
  const beats = s.beats || project.tracks?.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay')?.style?.beats || [];
  const S = { tl: T.tl, d: T.d, k: T.k, inn: T.inn, out: T.out, w: env.box.w, h: env.box.h, U: Math.min(W, H), t: item.t0 + local, pulse: s.beat === false ? 0 : pulseAt(beats, item.t0 + local), txt: s.text || {}, style: s, brand: s.brand, face: (project.tracks?.flatMap((t) => t.items).find((i) => i.type === 'theme.overlay')?.style?.faces || [])[0] || null, beats, covered: el === 'grade' && !inLayer && (coveredAt(project, item.t0 + local) || overlayAt(project, item.t0 + local)) };
  ctx.save();
  try { fn(ctx, K, S); } finally { ctx.restore(); }
}
registerLayer('theme.el', (ctx, item, t, project, env) => drawElement(ctx, item.style?.el, item, project, env));
// the intro and end card that "Apply theme" places (and that older projects carry) draw the same way
registerLayer('theme.sting', (ctx, item, t, project, env) => drawElement(ctx, 'intro', item, project, env));
registerLayer('theme.outro', (ctx, item, t, project, env) => drawElement(ctx, 'end', item, project, env));

/** a theme.el item for el at t0 (canvas px box); text placeholders start empty so the kit's words show */
export function elementStyle(themeId, el, { colors, variant, whole, text, brand, logo } = {}) {
  return { theme: themeId, el, colors: colors || null, variant: variant || (el === 'sticker' ? 'flag' : elementById(el)?.variants && elementById(el)?.fam ? FAM_DEFAULT_ROLE[el] || 'nurse' : undefined), whole: whole || false, text: text || {}, brand: brand || null, logo: logo || null, ...(el === 'capsules' ? { caps: defaultCaps() } : {}) };
}

/**
 * true while a full-frame card (the intro's country card, an end card after its slabs) hides the whole picture at t —
 * the overlay and the grade skip their drawing then (nothing of them would be seen). Pure function of the project and t,
 * so preview and export agree.
 */
export function coveredAt(project, t) {
  for (const tr of project.tracks || []) {
    if (tr.hidden) continue;
    for (const it of tr.items) {
      const ty = it.type, el = ty === 'theme.sting' ? 'intro' : ty === 'theme.outro' ? 'end' : ty === 'theme.el' ? it.style?.el : null;
      if (el !== 'intro' && el !== 'end' && !(ty === 'theme.el' && el === 'bulletin')) continue;
      const d = (it.out - it.in) / (it.speed || 1), l = t - it.t0; if (l < 0 || l >= d) continue;
      if ((it.opacity ?? 1) < 1 || it.x || it.y || it.w < project.canvas.w || it.h < project.canvas.h || it.fadeIn || it.fadeOut || (it.keys && Object.keys(it.keys).length)) continue;
      if (el === 'intro' && l / d > 0.3 && l / d < 0.72) return true;
      if (el === 'end' && l > 0.45 && d > 0.8) return true;
      if (el === 'bulletin' && l > 0.3 && l < d - 0.3) return true;
    }
  }
  return false;
}

const live = (it, t) => t >= it.t0 && t < it.t0 + (it.out - it.in) / (it.speed || 1);
/** is a running overlay on screen at t? (then the grade is painted inside the overlay's layer: one full-frame pass less) */
export function overlayAt(project, t) {
  return (project.tracks || []).some((tr) => !tr.hidden && tr.items.some((i) => i.type === 'theme.overlay' && live(i, t)));
}
/** the grade items on screen at t (the overlay paints them first in its layer) */
export function gradesAt(project, t) {
  const out = [];
  for (const tr of project.tracks || []) if (!tr.hidden) for (const i of tr.items) if (i.type === 'theme.el' && i.style?.el === 'grade' && live(i, t)) out.push(i);
  return out;
}
