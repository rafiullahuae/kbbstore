// KBB Studio · themes · ENDING SCREENS: full-screen closing cards with the company's logo, phone and WhatsApp, website,
// email, handles and a call to action (the owner, 18 Nov: "ending screen templates ... having contact details, website
// link whatsapp number etc."). Eleven looks.
//
//   theme.el, style.el 'endscreen', style.variant = an END_DESIGNS id
//   style.contact / brandColors / palette / lang   as the contact cards (./cta.js): ⚙ Configure → Contact & social
//   style.logo                                     the brand kit's mark, prepared once by ./logo-badge.js
//   style.slides  1 | 2 | 3   one card, or the time split into slides: (1) logo + thank you, (2) the contacts, (3) the CTA
//                             (2 slides: logo + thank you, then contacts + CTA)
//   style.photo   a picture for the photo slot (the company's ad photos), else a drawn stand-in
//   style.code    the country of the flag backdrop (the theme's, else Europe)
// Placed at the end of the reel (themes/el2-cmds.js addEndScreen: whole 'end', it follows every trim). Its length is the
// element's duration (3 s by default, 1–10 s): entrance, hold, slide changes and exit re-time themselves to it.
// Every word goes through text.js (tx): an inspector field each, live, double-click on the video. Pure function of the
// item and its time: preview = export.
import { registerPrepare } from '../core/render.js';
import * as A from './art.js';
import { tx } from './text.js';
import { drawIcon } from './el2-icons.js';
import { qrMatrix, drawQr } from './qr.js';
import { chatLink } from './cta.js';
import { prepareLogo, preparedLogo, choosePlate } from './logo-badge.js';
import { flagOf, loadBadgeAssets } from './globe-badge.js';

const { clamp, easeOut, easeIn, back, TAU, mix, rgba, onColor, lum, rnd } = A;

export const END_DESIGNS = [
  { id: 'corporate', label: 'Clean corporate card' },
  { id: 'split', label: 'Photo + details' },
  { id: 'whatsapp', label: 'Big WhatsApp' },
  { id: 'social', label: 'Social grid' },
  { id: 'office', label: 'Office pin (map)' },
  { id: 'thanks', label: 'Thank you + logo' },
  { id: 'flag', label: 'Country flag backdrop' },
  { id: 'pastel', label: 'Beauty pastel' },
  { id: 'gradient', label: 'Bold brand gradient' },
  { id: 'minimal', label: 'Minimal' },
  { id: 'qr', label: 'Scan to chat (QR)' },
];
export const endById = (id) => END_DESIGNS.find((d) => d.id === id) || END_DESIGNS[0];
export const END_DUR = 3;

// ── assets: the logo (prepared like the badge's), the photo slot, the flag ──
const PHOTOS = new Map();
function loadPhoto(src) {
  if (!src) return Promise.resolve(null);
  if (PHOTOS.has(src)) return PHOTOS.get(src).ready;
  const rec = { img: null }, im = new Image();
  rec.ready = new Promise((res) => { im.onload = () => { try { const k = Math.min(1, 1280 / Math.max(im.naturalWidth, im.naturalHeight)), c = document.createElement('canvas'); c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k); c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); rec.img = c; } catch {} res(rec.img); }; im.onerror = () => res(null); });
  im.src = /^(data|blob):/.test(src) ? src : '/' + String(src).split('/').map(encodeURIComponent).join('/');
  PHOTOS.set(src, rec); return rec.ready;
}
export const loadEndAssets = (s) => Promise.all([prepareLogo(s?.logo), loadPhoto(s?.photo), loadBadgeAssets(s?.code || 'europe')]);
registerPrepare('el2-endscreen', async (project) => {
  const jobs = [];
  for (const tr of project.tracks || []) for (const it of tr.items) if (it.type === 'theme.el' && it.style?.el === 'endscreen') jobs.push(loadEndAssets(it.style));
  await Promise.all(jobs);
});
let pend = false;
const askRedraw = (p) => p.then(() => { if (pend) return; pend = true; requestAnimationFrame(() => { pend = false; window.kbbEditor?.preview?.draw?.(); }); });

// ── words and colours ──
const WORDS = {
  en: { thanks: 'THANK YOU FOR WATCHING', cta: 'BOOK A FREE CONSULTATION', waTitle: 'CHAT WITH US ON WHATSAPP', follow: 'FOLLOW US', visit: 'VISIT US', scan: 'SCAN TO CHAT', address: 'Your office address, City' },
  ar: { thanks: 'شكراً للمشاهدة', cta: 'احجز استشارة مجانية', waTitle: 'راسلنا على واتساب', follow: 'تابعنا', visit: 'زورونا', scan: 'امسح للدردشة', address: 'عنوان مكتبك، المدينة' },
};
const PH = { phone: '+971 XX XXX XXXX', email: 'hello@yourcompany.com', website: 'yourwebsite.com', handle: '@yourhandle' };
const isAr = (S) => S.style?.lang === 'ar';
const W0 = (S, k) => (isAr(S) ? WORDS.ar : WORDS.en)[k];
const C = (S) => S.style?.contact || {};
function palette(S, K) {
  const s = S.style || {}, b = s.palette !== 'theme' && s.brandColors ? s.brandColors : null;
  const primary = b?.primary || K.colors.primary || '#1d3557';
  let accent = b ? (b.accent || b.secondary || '#ffd166') : (K.colors.accent || '#ffd166');
  if (Math.abs(lum(accent) - lum(primary)) < 0.12) accent = lum(primary) < 0.5 ? '#ffffff' : '#111111';
  const second = b?.secondary || mix(primary, '#000000', 0.35);
  return { bg: primary, bg2: mix(primary, '#000000', 0.35), second, fg: onColor(primary), acc: accent, accFg: onColor(accent), soft: b?.soft || mix(primary, '#ffffff', 0.88) };
}
// the default CTA: the kit's own end-card line when there is one ("Shop now at kbeautybliss.com"), else a consultation
const ctaDef = (S) => (isAr(S) ? S.style?.ctaAr : S.style?.ctaEn) || W0(S, 'cta');

/** a line of text that never squeezes: its size comes down until it fits (Arabic drawn right to left) */
function line(c, S, key, def, x, y, size, col, maxW, { align = 'center', weight = 800, track = 0, a = 1, kinetic = false, p = 1 } = {}) {
  const v = tx(S, key, def); if (!v) return;
  if (/[؀-ۿ]/.test(v)) { A.arabicText(c, v, x, y, size * 1.05, p, { col: rgba(col, a), align, maxW, shadow: false }); return; }
  if (kinetic) { A.kineticText(c, v, x, y, size, p, { col, align, maxW, shadow: false }); return; }
  c.save(); c.font = `${weight} ${Math.round(size)}px ${A.FONT_UI}`; const w = c.measureText(v).width * (1 + track * 0.9); c.restore();
  A.uiText(c, v, x, y, w > maxW ? Math.max(size * 0.5, size * maxW / w) : size, { col: rgba(col, a), weight, align, maxW, track });
}
/** an icon in a round badge */
function badge(c, id, cx, cy, r, fill, ink, k = 1) {
  if (k <= 0.01) return;
  c.save(); c.translate(cx, cy); c.scale(k, k); c.fillStyle = fill; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.fill(); drawIcon(c, id, 0, 0, r * 1.15, ink, { inner: fill }); c.restore();
}
/** contact rows: [icon, key, default] each a badge + the value, staggered in */
function rows(c, S, P, list, x, y, w, rh, t0, { fill = P.acc, ink = P.accFg, col = P.fg } = {}) {
  list.forEach(([id, key, def], i) => {
    const a = clamp((S.tl - t0 - i * 0.07) / 0.3); if (a <= 0) return;
    c.save(); c.globalAlpha *= a; c.translate((1 - easeOut(a)) * -w * 0.06, 0);
    const cy = y + rh * i + rh / 2;
    badge(c, id, x + rh * 0.36, cy, rh * 0.3, fill, ink);
    line(c, S, key, def, x + rh * 0.8, cy + rh * 0.13, rh * 0.36, col, w - rh * 0.85, { align: 'left' });
    c.restore();
  });
}
const contactList = (S) => [['phone', 'phone', C(S).phone || C(S).whatsapp || PH.phone], ['chat', 'whatsapp', C(S).whatsapp || C(S).phone || PH.phone], ['globe', 'website', C(S).website || PH.website], ['email', 'email', C(S).email || PH.email], ['camera', 'instagram', C(S).instagram || C(S).tiktok || PH.handle]];
/** the company logo, fitted in (cx, cy, size), on a plate when the logo needs one for contrast against `under` */
function logo(c, S, cx, cy, size, under, k = 1, plate = 'auto') {
  const src = S.style?.logo, L = preparedLogo(src);
  if (src && !L) askRedraw(prepareLogo(src));
  if (k <= 0.01) return;
  c.save(); c.translate(cx, cy); c.scale(k, k);
  const pl = plate === 'auto' ? (L ? choosePlate(L, S.style?.brandColors || {}) : '#ffffff') : plate;
  if (plate !== 'none') { c.save(); c.shadowColor = 'rgba(0,0,0,0.3)'; c.shadowBlur = size * 0.12 * A.devScale(c); c.fillStyle = pl; c.beginPath(); c.arc(0, 0, size / 2, 0, TAU); c.fill(); c.restore(); }
  if (L) { const r = size * (plate === 'none' ? 0.5 : 0.36), la = L.w / L.h; let w = 2 * r * la / Math.hypot(la, 1), h = 2 * r / Math.hypot(la, 1); if (plate === 'none') { w = la >= 1 ? size : size * la; h = w / la; } c.drawImage(L.cv, -w / 2, -h / 2, w, h); }
  else { const n = tx(S, 'brand', (S.style?.brand || C(S).name || 'Logo').trim()); A.uiText(c, n.slice(0, 2).toUpperCase(), 0, size * 0.12, size * 0.34, { col: onColor(pl), weight: 900, align: 'center', maxW: size * 0.8 }); }
  c.restore();
  void under;
}
function ctaPill(c, S, P, cx, y, w, h, k = 1, fill = P.acc, ink = P.accFg) {
  if (k <= 0.01) return;
  const pulse = 1 + Math.sin(S.tl * 4) * 0.015;
  c.save(); c.globalAlpha *= clamp(k); c.translate(cx, y + h / 2); c.scale(pulse * (0.9 + 0.1 * k), pulse * (0.9 + 0.1 * k));
  c.fillStyle = fill; c.beginPath(); A.rr(c, -w / 2, -h / 2, w, h, h / 2); c.fill();
  line(c, S, 'cta', ctaDef(S), 0, h * 0.17, h * 0.38, ink, w * 0.88, { weight: 900, track: 0.04 });
  c.restore();
}
function cover(c, img, x, y, w, h) { const k = Math.max(w / img.width, h / img.height); c.drawImage(img, x + (w - img.width * k) / 2, y + (h - img.height * k) / 2, img.width * k, img.height * k); }
/** the photo slot: the picture, else a drawn stand-in (a soft gradient with the logo's colours) */
function photo(c, S, P, x, y, w, h) {
  const src = S.style?.photo, rec = src ? PHOTOS.get(src) : null;
  if (src && !rec) askRedraw(loadPhoto(src));
  c.save(); c.beginPath(); c.rect(x, y, w, h); c.clip();
  if (rec?.img) cover(c, rec.img, x, y, w, h);
  else {
    A.gradRect(c, x, y, w, h, [[0, mix(P.bg, '#ffffff', 0.45)], [1, P.bg2]]);
    for (let i = 0; i < 7; i++) { c.fillStyle = rgba('#ffffff', 0.08 + rnd(i) * 0.08); c.beginPath(); c.arc(x + rnd(i, 1) * w, y + rnd(i, 2) * h, (0.1 + rnd(i, 3) * 0.25) * Math.min(w, h), 0, TAU); c.fill(); }
  }
  c.restore();
}

// ── one card's composition per look (all groups at once) ──
const L = {};
L.corporate = (c, S, P, w, h, U, show) => {
  c.fillStyle = P.bg2; c.fillRect(0, 0, w, h);
  A.gradRect(c, 0, 0, w, h, [[0, rgba(P.bg, 0.9)], [1, rgba(P.bg2, 1)]]);
  const cw = Math.min(w * 0.86, U * 0.9), ch = Math.min(h * 0.78, cw * 1.55), x = (w - cw) / 2, y = (h - ch) / 2, k = easeOut(S.tl / 0.5);
  c.save(); c.translate(0, (1 - k) * h * 0.06); c.globalAlpha *= k;
  c.save(); c.shadowColor = 'rgba(0,0,0,0.35)'; c.shadowBlur = U * 0.05 * A.devScale(c); c.fillStyle = '#ffffff'; c.beginPath(); A.rr(c, x, y, cw, ch, U * 0.04); c.fill(); c.restore();
  c.fillStyle = P.bg; c.fillRect(x, y + ch * 0.02, cw * 0.012, ch * 0.96);
  groups(c, S, P, x, y, cw, ch, U, show, { ink: '#1b1f2b', sub: mix('#1b1f2b', '#ffffff', 0.35), plate: 'none', rowFill: P.bg, rowInk: P.fg });
  c.restore();
};
L.split = (c, S, P, w, h, U, show) => {
  const ph = h * 0.46; photo(c, S, P, 0, 0, w, ph);
  c.fillStyle = P.bg; c.fillRect(0, ph, w, h - ph);
  c.fillStyle = P.acc; c.fillRect(0, ph - U * 0.012, w * easeOut(S.tl / 0.6), U * 0.024);
  if (show.a) logo(c, S, w / 2, ph, U * 0.26, P.bg, back((S.tl - 0.1) / 0.5));
  groups(c, S, P, 0, ph + U * 0.12, w, h - ph - U * 0.12, U, { ...show, a: show.a ? 'text' : false }, { n: 3 });
};
L.whatsapp = (c, S, P, w, h, U, show) => {
  const g = '#1f8f55'; A.gradRect(c, 0, 0, w, h, [[0, '#25b866'], [1, '#0f5c37']]);
  for (let i = 0; i < 6; i++) { c.fillStyle = rgba('#ffffff', 0.05); c.beginPath(); c.arc(w * rnd(i, 1), h * rnd(i, 2), U * (0.1 + rnd(i, 3) * 0.2), 0, TAU); c.fill(); }
  const k = back((S.tl - 0.1) / 0.5), ring = (S.tl % 1.6) / 1.6;
  if (show.b || show.single) {
    c.strokeStyle = rgba('#ffffff', 0.6 * (1 - ring)); c.lineWidth = U * 0.012; c.beginPath(); c.arc(w / 2, h * 0.3, U * (0.2 + 0.1 * ring), 0, TAU); c.stroke();
    badge(c, 'chat', w / 2, h * 0.3, U * 0.2, '#ffffff', g, k);
    line(c, S, 'waTitle', W0(S, 'waTitle'), w / 2, h * 0.47, U * 0.06, '#ffffff', w * 0.9, { a: clamp((S.tl - 0.3) / 0.3), weight: 900, track: 0.08 });
    line(c, S, 'whatsapp', C(S).whatsapp || C(S).phone || PH.phone, w / 2, h * 0.56, U * 0.11, '#ffffff', w * 0.9, { a: clamp((S.tl - 0.4) / 0.3), weight: 900 });
  }
  if (show.a && !show.single) { logo(c, S, w / 2, h * 0.36, U * 0.34, g, back((S.tl - 0.1) / 0.5)); line(c, S, 'thanks', W0(S, 'thanks'), w / 2, h * 0.56, U * 0.07, '#ffffff', w * 0.88, { weight: 900, kinetic: true, p: clamp(S.tl / 0.6) }); }
  if (show.single) logo(c, S, w / 2, h * 0.11, U * 0.16, g, back((S.tl - 0.2) / 0.5));
  if (show.c) ctaPill(c, S, P, w / 2, h * (show.single ? 0.66 : 0.7), w * 0.8, U * 0.12, clamp((S.tl - 0.5) / 0.3), '#ffffff', g);
  if (show.single) line(c, S, 'website', C(S).website || PH.website, w / 2, h * 0.84, U * 0.05, rgba('#ffffff', 0.9), w * 0.8, { a: clamp((S.tl - 0.6) / 0.3), weight: 700 });
};
L.social = (c, S, P, w, h, U, show) => {
  A.gradRect(c, 0, 0, w, h, [[0, P.bg], [1, P.bg2]]);
  if (show.a) { logo(c, S, w / 2, h * 0.16, U * 0.22, P.bg, back(S.tl / 0.5)); line(c, S, 'follow', W0(S, 'follow'), w / 2, h * 0.29, U * 0.08, P.fg, w * 0.9, { weight: 900, track: 0.2, a: clamp((S.tl - 0.2) / 0.3) }); }
  if (show.b) {
    const tiles = [['camera', 'instagram', C(S).instagram || PH.handle], ['note', 'tiktok', C(S).tiktok || C(S).instagram || PH.handle], ['ghost', 'snapchat', C(S).snapchat || C(S).instagram || PH.handle], ['play', 'youtube', C(S).youtube || C(S).instagram || PH.handle]];
    const tw = w * 0.42, th = U * 0.3, gx = w * 0.05, x0 = (w - tw * 2 - gx) / 2, y0 = h * (show.a ? 0.34 : 0.22);
    tiles.forEach(([id, key, def], i) => {
      const a = back((S.tl - 0.25 - i * 0.08) / 0.4), x = x0 + (i % 2) * (tw + gx), y = y0 + Math.floor(i / 2) * (th + gx);
      if (a <= 0.01) return;
      c.save(); c.translate(x + tw / 2, y + th / 2); c.scale(a, a); c.translate(-tw / 2, -th / 2);
      c.fillStyle = rgba('#ffffff', 0.12); c.beginPath(); A.rr(c, 0, 0, tw, th, th * 0.16); c.fill();
      badge(c, id, tw / 2, th * 0.38, th * 0.2, P.acc, P.accFg);
      line(c, S, key, def, tw / 2, th * 0.82, th * 0.13, P.fg, tw * 0.9);
      c.restore();
    });
    line(c, S, 'website', C(S).website || PH.website, w / 2, y0 + th * 2 + gx * 2.2, U * 0.05, rgba(P.fg, 0.9), w * 0.8, { a: clamp((S.tl - 0.6) / 0.3), weight: 700 });
  }
  if (show.c) ctaPill(c, S, P, w / 2, h * 0.86, w * 0.8, U * 0.11, clamp((S.tl - 0.6) / 0.3));
};
L.office = (c, S, P, w, h, U, show) => {
  // a drawn street map in the brand's tint (no map data, no network)
  c.fillStyle = mix(P.soft, '#e9ecef', 0.5); c.fillRect(0, 0, w, h);
  c.strokeStyle = '#ffffff'; c.lineCap = 'round';
  for (let i = 0; i < 9; i++) { c.lineWidth = U * (0.018 + (i % 3 === 0 ? 0.02 : 0)); c.beginPath(); c.moveTo(-w * 0.1, h * (i / 8)); c.lineTo(w * 1.1, h * (i / 8) + h * 0.12 * (rnd(i) - 0.5)); c.stroke(); c.beginPath(); c.moveTo(w * (i / 8), -h * 0.1); c.lineTo(w * (i / 8) + w * 0.15 * (rnd(i, 4) - 0.5), h * 1.1); c.stroke(); }
  c.fillStyle = rgba(P.bg, 0.12); for (let i = 0; i < 5; i++) { c.beginPath(); A.rr(c, w * rnd(i, 7), h * rnd(i, 8), U * 0.18, U * 0.12, U * 0.02); c.fill(); }
  const k = easeOut(S.tl / 0.5), py = h * 0.3 - (1 - k) * h * 0.2;
  c.save(); c.globalAlpha *= clamp(S.tl / 0.3); c.fillStyle = rgba('#000000', 0.2); c.beginPath(); c.ellipse(w / 2, h * 0.36, U * 0.08 * k, U * 0.02, 0, 0, TAU); c.fill();
  drawIcon(c, 'pin', w / 2, py, U * 0.34, P.bg, { inner: '#ffffff' }); c.restore();
  const cw = w * 0.88, ch = h * 0.5, x = (w - cw) / 2, y = h * 0.44, a = clamp((S.tl - 0.25) / 0.35);
  c.save(); c.globalAlpha *= a; c.translate(0, (1 - a) * h * 0.04);
  c.save(); c.shadowColor = 'rgba(0,0,0,0.25)'; c.shadowBlur = U * 0.04 * A.devScale(c); c.fillStyle = P.bg; c.beginPath(); A.rr(c, x, y, cw, ch, U * 0.04); c.fill(); c.restore();
  if (show.a) { line(c, S, 'visit', W0(S, 'visit'), w / 2, y + ch * 0.12, U * 0.06, P.acc, cw * 0.9, { weight: 900, track: 0.2 }); line(c, S, 'address', C(S).address || W0(S, 'address'), w / 2, y + ch * 0.22, U * 0.045, P.fg, cw * 0.9, { weight: 700 }); }
  if (show.b) rows(c, S, P, contactList(S).slice(0, 3), x + cw * 0.06, y + ch * 0.28, cw * 0.88, ch * 0.15, 0.4);
  if (show.c) ctaPill(c, S, P, w / 2, y + ch * 0.77, cw * 0.84, ch * 0.15, clamp((S.tl - 0.6) / 0.3));
  c.restore();
};
L.thanks = (c, S, P, w, h, U, show) => {
  A.gradRect(c, 0, 0, w, h, [[0, P.bg2], [0.5, P.bg], [1, P.bg2]]);
  A.sparkles(c, w, h, S.t, 26, P.acc, U, 5);
  if (show.a) { logo(c, S, w / 2, h * 0.24, U * 0.34, P.bg, back(S.tl / 0.55)); line(c, S, 'thanks', W0(S, 'thanks'), w / 2, h * 0.43, U * 0.1, P.fg, w * 0.92, { kinetic: true, p: clamp((S.tl - 0.15) / 0.6) }); }
  if (show.b) { const list = contactList(S).slice(0, 3); rows(c, S, P, list, w * 0.12, h * (show.a ? 0.5 : 0.3), w * 0.78, U * 0.13, 0.35); }
  if (show.c) ctaPill(c, S, P, w / 2, h * 0.82, w * 0.8, U * 0.12, clamp((S.tl - 0.6) / 0.3));
};
L.flag = (c, S, P, w, h, U, show) => {
  const img = flagOf(S.style?.code || 'europe');
  if (img) { c.save(); A.flagCloth(c, img, [P.bg, P.acc], -w * 0.15, -h * 0.05, w * 1.3, h * 1.1, S.t, { pole: false, strips: 24, amp: 0.03 }); c.restore(); } else { c.fillStyle = P.bg; c.fillRect(0, 0, w, h); }
  A.gradRect(c, 0, 0, w, h, [[0, 'rgba(5,10,30,0.35)'], [1, 'rgba(5,10,30,0.82)']]);
  const cw = w * 0.86, x = (w - cw) / 2;
  c.save(); c.fillStyle = 'rgba(255,255,255,0.12)'; c.strokeStyle = 'rgba(255,255,255,0.35)'; c.lineWidth = U * 0.004; c.beginPath(); A.rr(c, x, h * 0.36, cw, h * 0.56, U * 0.04); c.fill(); c.stroke(); c.restore();
  groups(c, S, P, x, h * 0.06, cw, h * 0.86, U, show, { ink: '#ffffff', plate: 'auto', rowFill: '#ffffff', rowInk: P.bg, aTop: true });
};
L.pastel = (c, S, P, w, h, U, show) => {
  A.gradRect(c, 0, 0, w, h, [[0, '#FFF0F4'], [0.55, mix(P.soft, '#fde2ec', 0.5)], [1, '#F9D7E3']]);
  for (let i = 0; i < 14; i++) { const r = U * (0.03 + rnd(i, 2) * 0.06), y = (h * rnd(i, 3) + S.t * U * 0.03 * (1 + rnd(i))) % (h + r * 2) - r; c.fillStyle = rgba(['#f6b8cb', '#f9d58e', '#cdb4f2', '#ffffff'][i % 4], 0.6); c.beginPath(); c.ellipse(w * rnd(i, 1), y, r, r * 0.7, rnd(i, 5) * 3, 0, TAU); c.fill(); }
  const ink = mix(P.bg, '#2a2228', 0.4);
  groups(c, S, P, w * 0.08, h * 0.06, w * 0.84, h * 0.88, U, show, { ink, plate: '#ffffff', rowFill: P.bg, rowInk: '#ffffff', ctaFill: P.bg, ctaInk: '#ffffff', serif: true });
};
L.gradient = (c, S, P, w, h, U, show) => {
  A.gradRect(c, 0, 0, w, h, [[0, mix(P.bg, '#ffffff', 0.1)], [0.55, P.second || P.bg2], [1, P.bg2]]);
  const k = easeOut(S.tl / 0.6);
  for (let i = 0; i < 3; i++) A.slab(c, -w * 0.2 + w * 1.4 * (1 - k) * (i + 1) * 0.3, h * (0.08 + i * 0.3), w * 1.4, h * 0.1, 0.4, rgba(i % 2 ? P.acc : '#ffffff', 0.08));
  groups(c, S, P, w * 0.07, h * 0.05, w * 0.86, h * 0.9, U, show, { ink: P.fg, plate: 'auto', big: true });
};
L.minimal = (c, S, P, w, h, U, show) => {
  c.fillStyle = '#ffffff'; c.fillRect(0, 0, w, h);
  c.fillStyle = P.bg; c.fillRect(w * 0.1, h * 0.035, w * 0.8 * easeOut(S.tl / 0.6), U * 0.006);
  groups(c, S, P, w * 0.1, h * 0.08, w * 0.8, h * 0.84, U, show, { ink: '#111111', sub: '#555555', plate: 'none', rowFill: '#111111', rowInk: '#ffffff', ctaFill: '#111111', ctaInk: '#ffffff' });
};
L.qr = (c, S, P, w, h, U, show) => {
  A.gradRect(c, 0, 0, w, h, [[0, P.bg], [1, P.bg2]]);
  if (show.a) { logo(c, S, w / 2, h * 0.11, U * 0.18, P.bg, back(S.tl / 0.5)); line(c, S, 'scan', W0(S, 'scan'), w / 2, h * 0.22, U * 0.08, P.fg, w * 0.9, { weight: 900, track: 0.16, a: clamp((S.tl - 0.2) / 0.3) }); }
  if (show.b || show.single) {
    const side = Math.min(w * 0.62, h * 0.34), qx = (w - side) / 2, qy = h * (show.a ? 0.27 : 0.15), link = tx(S, 'qr', chatLink(C(S)));
    c.fillStyle = '#ffffff'; c.beginPath(); A.rr(c, qx - side * 0.05, qy - side * 0.05, side * 1.1, side * 1.1, side * 0.06); c.fill();
    c.save(); c.beginPath(); c.rect(qx, qy, side, side * clamp((S.tl - 0.2) / 0.5)); c.clip(); drawQr(c, qrMatrix(link), qx, qy, side, '#111111', '#ffffff', 1); c.restore();
    rows(c, S, P, contactList(S).slice(1, 3), w * 0.12, qy + side * 1.12, w * 0.78, U * 0.12, 0.5);
  }
  if (show.c) ctaPill(c, S, P, w / 2, h * 0.86, w * 0.8, U * 0.11, clamp((S.tl - 0.6) / 0.3));
};

/** the shared stack (logo + thank you · contacts · CTA) inside (x, y, w, h), for the card looks and for every slide */
function groups(c, S, P, x, y, w, h, U, show, o = {}) {
  const ink = o.ink || P.fg, sub = o.sub || rgba(ink, 0.8), cx = x + w / 2;
  const nA = show.a ? 1 : 0, nB = show.b ? 1 : 0, nC = show.c ? 1 : 0;
  // heights: logo block 0.36, contacts 0.42, CTA 0.14 (of h), spread over what is shown
  const parts = [nA && ['a', 0.34], nB && ['b', 0.44], nC && ['c', 0.14]].filter(Boolean), tot = parts.reduce((s, p) => s + p[1], 0), gap = (1 - tot) / (parts.length + 1);
  let yy = y + h * gap;
  for (const [g, f] of parts) {
    const ph = h * f;
    if (g === 'a') {
      if (show.a !== 'text') logo(c, S, cx, yy + ph * 0.36, Math.min(ph * 0.62, U * 0.3), P.bg, back(S.tl / 0.5), o.plate || 'auto');
      line(c, S, 'thanks', W0(S, 'thanks'), cx, yy + ph * (show.a === 'text' ? 0.3 : 0.86), Math.min(U * (o.big ? 0.1 : 0.075), ph * 0.2), ink, w * 0.94, { weight: 900, kinetic: !!o.big, p: clamp((S.tl - 0.15) / 0.6), a: clamp((S.tl - 0.15) / 0.3) });
      if (show.a === 'text') line(c, S, 'company', C(S).name || '', cx, yy + ph * 0.55, Math.min(U * 0.05, ph * 0.14), sub, w * 0.9, { weight: 700, a: clamp((S.tl - 0.25) / 0.3) });
    } else if (g === 'b') {
      const list = contactList(S).slice(0, o.n || 5), rh = Math.min(ph / list.length, U * 0.13);
      rows(c, S, P, list, x + w * 0.04, yy + (ph - rh * list.length) / 2, w * 0.92, rh, 0.3, { fill: o.rowFill || P.acc, ink: o.rowInk || P.accFg, col: ink });
    } else ctaPill(c, S, P, cx, yy, Math.min(w * 0.94, U * 0.95), ph, clamp((S.tl - 0.55) / 0.3), o.ctaFill || P.acc, o.ctaInk || P.accFg);
    yy += ph + h * gap;
  }
}

/** which groups slide i of n shows */
const SLIDES = { 1: [{ a: true, b: true, c: true, single: true }], 2: [{ a: true }, { b: true, c: true }], 3: [{ a: true }, { b: true }, { c: true }] };

/**
 * draw an ending screen in the item's box. The time is split into its slides; each slide's own clock (tl) restarts so
 * its entrance plays, and slides change with a short push.
 */
export function drawEndScreen(c, K, S) {
  const s = S.style || {}, look = endById(s.variant).id, n = [1, 2, 3].includes(+s.slides) ? +s.slides : 1, P = palette(S, K);
  if (s.code && !flagOf(s.code) && look === 'flag') askRedraw(loadBadgeAssets(s.code));
  const { w, h } = S, U = Math.min(w, h), d = S.d, outT = Math.min(0.35, d * 0.15), oo = 1 - easeIn((S.tl - (d - outT)) / (outT - 0.03));   // fully gone a frame before the end
  const inT = Math.min(0.45, d * 0.18), ii = easeOut(S.tl / inT);
  if (oo <= 0.002 || ii <= 0.002) return;
  const per = d / n, i = Math.min(n - 1, Math.floor(S.tl / per)), tl = S.tl - i * per;
  // the entrance timings are written for ~3 s; a short card plays them faster, a long one holds longer
  const speed = Math.max(1, 1.2 / Math.max(0.3, per * 0.6));
  const SS = Object.create(S); SS.tl = tl * speed;
  const show = SLIDES[n][i], draw = L[look] || L.corporate;
  c.save(); c.globalAlpha *= Math.min(oo, ii);
  // the whole card rises in and drops out a little; a slide change pushes the next one in from the right
  c.translate(0, (1 - ii) * h * 0.04 + (1 - oo) * h * 0.03);
  const push = i > 0 ? 1 - easeOut(tl / 0.35) : 0;
  if (push > 0.001) { c.save(); c.translate(-w * (1 - push) * 0.25, 0); c.globalAlpha *= push; const prev = Object.create(S); prev.tl = per * speed; draw(c, prev, P, w, h, U, SLIDES[n][i - 1]); c.restore(); }
  c.save(); if (push > 0.001) { c.translate(w * push * 0.4, 0); c.globalAlpha *= 1 - push; } draw(c, SS, P, w, h, U, show); c.restore();
  // slide dots
  if (n > 1) for (let k = 0; k < n; k++) { c.fillStyle = rgba(look === 'minimal' || look === 'pastel' || look === 'office' ? '#333333' : '#ffffff', k === i ? 0.95 : 0.35); c.beginPath(); c.arc(w / 2 + (k - (n - 1) / 2) * U * 0.04, h * 0.965, U * 0.009, 0, TAU); c.fill(); }
  c.restore();
}
