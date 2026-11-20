// KBB Studio · themes · CONTACT CARDS (call to action): phone, WhatsApp, both side by side, email, website, the social
// handles, "Call or WhatsApp" stacked, a QR "scan to chat", "Book a free consultation", a contact strip and an animated
// tap on a button. The owner, 18 Nov: "phone number, whatsapp etc, with two icons phone + icons + number etc."
//
//   theme.el, style.el 'cta', style.variant = a CTA_DESIGNS id
//   style.contact   the company's ⚙ Configure → Contact & social, copied in when the card is placed (contactOf)
//   style.palette   'brand' (the company's colours, style.brandColors) | 'theme' (the theme's palette, style.colors)
//   style.lang      'en' | 'ar' (the default words; every word stays editable)
//
// Every word goes through text.js (tx + the art primitives): an inspector field each, live, double-click on the video,
// Text style. The QR's link is a field too ('qr'). Pure function of the item and its time: preview = export.
import * as A from './art.js';
import { tx, noteSlot } from './text.js';
import { drawIcon } from './el2-icons.js';
import { qrMatrix, drawQr } from './qr.js';
import { CTA2_DESIGNS, drawCta2, lookOf } from './cta2.js';

const { clamp, easeOut, easeIn, back, TAU, mix, rgba, onColor, lum } = A;

/** the designs: id, label, the card's shape (width / height), the note on its chip. The ten slim, always-moving bars of
 * ./cta2.js come first (the owner, 20 Nov: "less height … animated continues"); the first 15 cards follow, unchanged */
const CTA1_DESIGNS = [
  { id: 'call', label: 'Call us', ar: 3.6, note: 'Phone icon + your number' },
  { id: 'whatsapp', label: 'WhatsApp', ar: 3.6, note: 'Chat icon + your WhatsApp number' },
  { id: 'phonewa', label: 'Phone + WhatsApp', ar: 4.0, note: 'Two icons side by side + the number' },
  { id: 'stack', label: 'Call or WhatsApp (stacked)', ar: 1.9, note: 'Two rows: call, WhatsApp' },
  { id: 'email', label: 'Email', ar: 3.6, note: 'Envelope + your email' },
  { id: 'website', label: 'Website', ar: 3.6, note: 'Globe + your website' },
  { id: 'instagram', label: 'Instagram handle', ar: 3.6, note: 'Camera icon + @handle' },
  { id: 'tiktok', label: 'TikTok handle', ar: 3.6, note: 'Music-note icon + @handle' },
  { id: 'snapchat', label: 'Snapchat handle', ar: 3.6, note: 'Ghost icon + handle' },
  { id: 'youtube', label: 'YouTube channel', ar: 3.6, note: 'Play-button icon + channel' },
  { id: 'socials', label: 'Follow us (all socials)', ar: 2.4, note: 'Four icons + your handle' },
  { id: 'qr', label: 'Scan to chat (QR)', ar: 0.78, note: 'A real QR code to your WhatsApp' },
  { id: 'consult', label: 'Book a free consultation', ar: 1.75, note: 'Headline + a phone button' },
  { id: 'strip', label: 'Contact strip', ar: 5.2, note: 'Phone · WhatsApp · website · @handle' },
  { id: 'tap', label: 'Tap to call (animated)', ar: 2.1, note: 'A button pressed by a tapping hand' },
];
export const CTA_DESIGNS = [...CTA2_DESIGNS, ...CTA1_DESIGNS];
/** the design for an id (an unknown one draws as 'Call us', as it always has) */
export const ctaById = (id) => CTA_DESIGNS.find((d) => d.id === id) || CTA1_DESIGNS[0];
/** a newly placed Contact card's design: the first slim bar */
export const CTA_DEFAULT = CTA2_DESIGNS[0].id;

const clean = (s) => String(s ?? '').trim();
const handle = (s) => { const v = clean(s).replace(/^https?:\/\/(www\.)?[a-z.]+\.[a-z]+\/(@)?/i, '').replace(/[/?#].*$/, '').replace(/^@/, ''); return v ? '@' + v : ''; };
/** what a company's Configure → Contact & social gives a card (placeholders where it says nothing) */
export function contactOf(ws = {}) {
  const ci = ws.contactInfo || {}, so = ws.social || {}, line = ws.contact || '';
  const site = clean(ci.website) || (/([a-z0-9-]+\.)+[a-z]{2,}(\/\S*)?/i.exec(line.replace(/\S+@\S+/g, '')) || [])[0] || '';
  const num = clean(ci.phone) || (/\+?\d[\d\s-]{6,}\d/.exec(line) || [])[0] || '';
  return {
    phone: num, whatsapp: clean(ci.whatsapp) || num, email: clean(ci.email) || (/\S+@\S+\.\S+/.exec(line) || [])[0] || '', website: site.replace(/^https?:\/\//i, '').replace(/\/$/, ''),
    instagram: handle(so.instagram), tiktok: handle(so.tiktok), snapchat: handle(so.snapchat), youtube: handle(so.youtube), facebook: clean(so.facebook), linkedin: clean(so.linkedin),
    name: ws.fullName || ws.name || '', address: clean(ci.address || ws.address || ''),
  };
}
const PH = { phone: '+971 XX XXX XXXX', email: 'hello@yourcompany.com', website: 'yourwebsite.com', handle: '@yourhandle' };
/** the wa.me link a QR opens (from the WhatsApp number's digits), else the website */
export function chatLink(con) {
  const d = String(con?.whatsapp || con?.phone || '').replace(/[^\d]/g, '');
  if (d.length >= 8) return 'https://wa.me/' + d;
  return con?.website ? 'https://' + con.website.replace(/^https?:\/\//, '') : 'https://wa.me/';
}

// the default words, English and Arabic (every one is a text slot)
const WORDS = {
  en: { callLbl: 'CALL US NOW', waLbl: 'WHATSAPP US', bothLbl: 'CALL OR WHATSAPP', mailLbl: 'EMAIL US', webLbl: 'VISIT OUR WEBSITE', followLbl: 'FOLLOW US', snapLbl: 'ADD US ON SNAPCHAT', ytLbl: 'SUBSCRIBE', scanLbl: 'SCAN TO CHAT', scanSub: 'Opens WhatsApp', consultLbl: 'BOOK A FREE CONSULTATION', consultSub: 'Talk to an expert today', tapLbl: 'TAP TO CALL', callRow: 'Call', waRow: 'WhatsApp' },
  ar: { callLbl: 'اتصل بنا الآن', waLbl: 'راسلنا واتساب', bothLbl: 'اتصل أو واتساب', mailLbl: 'راسلنا بالبريد', webLbl: 'زر موقعنا', followLbl: 'تابعنا', snapLbl: 'أضفنا على سناب شات', ytLbl: 'اشترك الآن', scanLbl: 'امسح للدردشة', scanSub: 'يفتح واتساب', consultLbl: 'احجز استشارة مجانية', consultSub: 'تحدث مع خبير اليوم', tapLbl: 'اضغط للاتصال', callRow: 'اتصال', waRow: 'واتساب' },
};

/** the card's colours: the company's (default) or the theme's */
function palette(S, K) {
  const s = S.style || {}, b = s.palette !== 'theme' && s.brandColors ? s.brandColors : null;
  const primary = (b?.primary || K.colors.primary || '#1d3557');
  let accent = b ? (b.accent || b.secondary || '#ffd166') : (K.colors.accent || '#ffd166');
  // an accent that disappears on the card (too close in brightness) becomes white
  if (Math.abs(lum(accent) - lum(primary)) < 0.12) accent = lum(primary) < 0.5 ? '#ffffff' : '#111111';
  return { bg: primary, bg2: mix(primary, '#000000', 0.28), fg: onColor(primary), acc: accent, accFg: onColor(accent), soft: mix(primary, '#ffffff', 0.85) };
}

// ── the moves every card shares ──
/** in (slide up + pop), hold, out (drop + fade) -> {k: 0..1 visibility, y: offset share, s: scale} */
function motion(S) {
  const ii = S.inn(0.5), oo = S.out(0.35), k = Math.min(ii, oo);
  return { k, a: clamp(Math.min(S.tl / 0.25, oo * 1.4)), y: (1 - easeOut(S.tl / 0.5)) * 0.35 + (1 - oo) * 0.25, s: 0.86 + 0.14 * back(S.tl / 0.5) - (1 - oo) * 0.06, icon: back((S.tl - 0.18) / 0.45), txt: clamp((S.tl - 0.25) / 0.35) };
}
function card(c, x, y, w, h, r, P, U) {
  c.save();
  c.shadowColor = 'rgba(0,0,0,0.35)'; c.shadowBlur = U * 0.035 * A.devScale(c); c.shadowOffsetY = U * 0.012 * A.devScale(c);
  const g = c.createLinearGradient(x, y, x, y + h); g.addColorStop(0, mix(P.bg, '#ffffff', 0.08)); g.addColorStop(1, P.bg2);
  c.fillStyle = g; c.beginPath(); A.rr(c, x, y, w, h, r); c.fill();
  c.restore();
}
/** an icon in a round badge; ring: the attention ripple (0..1) */
function badge(c, id, cx, cy, r, P, k = 1, ring = 0, fill = P.acc, ink = P.accFg) {
  if (k <= 0.01) return;
  c.save(); c.translate(cx, cy); c.scale(k, k);
  if (ring > 0 && ring < 1) { c.strokeStyle = rgba(fill, 0.6 * (1 - ring)); c.lineWidth = r * 0.12; c.beginPath(); c.arc(0, 0, r * (1 + ring * 0.7), 0, TAU); c.stroke(); }
  c.fillStyle = fill; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.fill();
  drawIcon(c, id, 0, 0, r * 1.15, ink, { inner: fill });
  c.restore();
}
/** a phone that rings: a little shake every 1.6 s */
const ringShake = (t) => { const f = (t % 1.6) / 1.6; return f < 0.25 ? Math.sin(f * 4 * TAU * 3) * 0.12 * (1 - f * 4) : 0; };

const isAr = (S) => (S.style?.lang || 'en') === 'ar';
const W0 = (S, key) => (isAr(S) ? WORDS.ar : WORDS.en)[key];
/** a label line in the card's language (Arabic right-aligned from the text block's right edge) */
function label(c, S, key, x, y, size, col, maxW, p = 1, xr = null) {
  const v = tx(S, key, W0(S, key));
  if (isAr(S)) A.arabicText(c, v, xr ?? x + maxW, y, size * 1.08, p, { col, align: 'right', maxW, shadow: false });
  else A.uiText(c, v, x, y, size, { col: rgba(col, clamp(p)), weight: 800, track: 0.12, maxW });
}
// a value (a number, an address) is never squeezed: its size comes down until it fits its column (measured with the
// font it draws in, every frame: a font that arrives late cannot leave a stale size behind)
function fitSize(c, text, size, weight, maxW) {
  c.save(); c.font = `${weight} ${Math.round(size)}px ${A.FONT_UI}`; const w = c.measureText(text).width; c.restore();
  return w > maxW ? Math.max(size * 0.5, size * maxW / w) : size;
}
function valueText(c, S, key, def, x, y, size, col, maxW, align = 'left', p = 1) {
  const v = tx(S, key, def);
  return A.uiText(c, v, x, y, fitSize(c, v, size, 800, maxW), { col: rgba(col, clamp(p)), weight: 800, maxW, align });
}

const C = (S) => S.style?.contact || {};
const num = (S) => C(S).phone || C(S).whatsapp || PH.phone;
const wnum = (S) => C(S).whatsapp || C(S).phone || PH.phone;

// ── a row card: one or two icon badges on the left, a label over a value ──
function rowCard(c, S, P, cw, ch, icons, lblKey, valKey, valDef, U) {
  const m = motion(S), r = ch * 0.5, bx = ch * 0.5, br = ch * 0.3, step = icons.length > 1 ? br * 1.72 : 0;
  card(c, 0, 0, cw, ch, r, P, U);
  // a sheen passing over the card every few seconds
  c.save(); c.beginPath(); A.rr(c, 0, 0, cw, ch, r); c.clip(); A.sheen(c, 0, 0, cw, ch, ((S.tl + 0.6) % 3.2) / 3.2, 0.22); c.restore();
  let x = bx;
  icons.forEach((id, i) => {
    const rot = id === 'phone' ? ringShake(S.tl + i * 0.3) : 0, ring = ((S.tl + i * 0.5) % 2.2) / 1.1;
    // a second icon overlaps the first a little, with a ring in the card's colour between them
    if (i) { c.fillStyle = P.bg; c.beginPath(); c.arc(x, ch / 2, br * 1.12, 0, TAU); c.fill(); }
    c.save(); c.translate(x, ch / 2); c.rotate(rot); badge(c, id, 0, 0, br, P, back((S.tl - 0.18 - i * 0.12) / 0.45), ring < 1 ? ring : 0); c.restore();
    if (i < icons.length - 1) x += step;
  });
  const tx0 = x + br + ch * 0.2, mw = cw - tx0 - ch * 0.34;
  label(c, S, lblKey, tx0, ch * 0.41, ch * 0.135, mix(P.fg, P.acc, 0.35), mw, m.txt);
  valueText(c, S, valKey, valDef, tx0, ch * 0.74, ch * 0.24, P.fg, mw, 'left', m.txt);
}

// ── the designs ──
const D = {};
D.call = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['phone'], 'callLbl', 'phone', num(S), U);
D.whatsapp = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['chat'], 'waLbl', 'whatsapp', wnum(S), U);
D.phonewa = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['phone', 'chat'], 'bothLbl', 'phone', num(S), U);
D.email = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['email'], 'mailLbl', 'email', C(S).email || PH.email, U);
D.website = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['globe'], 'webLbl', 'website', C(S).website || PH.website, U);
D.instagram = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['camera'], 'followLbl', 'instagram', C(S).instagram || PH.handle, U);
D.tiktok = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['note'], 'followLbl', 'tiktok', C(S).tiktok || C(S).instagram || PH.handle, U);
D.snapchat = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['ghost'], 'snapLbl', 'snapchat', C(S).snapchat || C(S).instagram || PH.handle, U);
D.youtube = (c, S, P, cw, ch, U) => rowCard(c, S, P, cw, ch, ['play'], 'ytLbl', 'youtube', C(S).youtube || C(S).instagram || PH.handle, U);

D.stack = (c, S, P, cw, ch, U) => {
  const m = motion(S), r = ch * 0.12;
  card(c, 0, 0, cw, ch, r, P, U);
  const hh = ch * 0.3;
  c.fillStyle = P.acc; c.beginPath(); A.rr(c, 0, 0, cw, hh, r); c.rect(0, hh * 0.5, cw, hh * 0.5); c.fill();
  if (isAr(S)) A.arabicText(c, tx(S, 'bothLbl', W0(S, 'bothLbl')), cw / 2, hh * 0.68, hh * 0.42, m.txt, { col: P.accFg, align: 'center', maxW: cw * 0.9, shadow: false });
  else A.uiText(c, tx(S, 'bothLbl', W0(S, 'bothLbl')), cw / 2, hh * 0.66, hh * 0.36, { col: rgba(P.accFg, m.txt), weight: 900, align: 'center', track: 0.1, maxW: cw * 0.9 });
  const rows = [['phone', 'callRow', 'phone', num(S)], ['chat', 'waRow', 'whatsapp', wnum(S)]], rh = (ch - hh) / 2;
  rows.forEach(([id, lk, vk, vd], i) => {
    const y = hh + rh * i, cy = y + rh / 2, a = clamp((S.tl - 0.3 - i * 0.18) / 0.35);
    if (i) { c.fillStyle = rgba(P.fg, 0.15); c.fillRect(cw * 0.06, y, cw * 0.88, Math.max(1, ch * 0.006)); }
    c.save(); c.globalAlpha *= a; c.translate((1 - easeOut(a)) * -cw * 0.08, 0);
    c.save(); c.translate(rh * 0.6, cy); c.rotate(id === 'phone' ? ringShake(S.tl) : 0); badge(c, id, 0, 0, rh * 0.32, P, 1); c.restore();
    const x0 = rh * 1.12, mw = cw - x0 - cw * 0.05;
    label(c, S, lk, x0, cy - rh * 0.06, rh * 0.18, mix(P.fg, P.acc, 0.35), mw, 1);
    valueText(c, S, vk, vd, x0, cy + rh * 0.26, rh * 0.3, P.fg, mw);
    c.restore();
  });
};

D.socials = (c, S, P, cw, ch, U) => {
  const m = motion(S), r = ch * 0.16;
  card(c, 0, 0, cw, ch, r, P, U);
  if (isAr(S)) A.arabicText(c, tx(S, 'followLbl', W0(S, 'followLbl')), cw / 2, ch * 0.27, ch * 0.16, m.txt, { col: P.fg, align: 'center', maxW: cw * 0.9, shadow: false });
  else A.uiText(c, tx(S, 'followLbl', W0(S, 'followLbl')), cw / 2, ch * 0.26, ch * 0.13, { col: rgba(P.fg, m.txt), weight: 900, align: 'center', track: 0.2, maxW: cw * 0.9 });
  const ids = ['camera', 'note', 'ghost', 'play'], br = ch * 0.13, gap = br * 2.9, x0 = cw / 2 - gap * 1.5;
  ids.forEach((id, i) => { const k = back((S.tl - 0.2 - i * 0.1) / 0.4), bob = Math.sin(S.tl * 3 + i) * ch * 0.012; badge(c, id, x0 + gap * i, ch * 0.5 + bob, br, P, k); });
  valueText(c, S, 'instagram', C(S).instagram || C(S).tiktok || PH.handle, cw / 2, ch * 0.86, ch * 0.15, P.fg, cw * 0.88, 'center', m.txt);
};

D.qr = (c, S, P, cw, ch, U) => {
  const m = motion(S), r = cw * 0.08;
  card(c, 0, 0, cw, ch, r, P, U);
  const link = tx(S, 'qr', chatLink(C(S))), q = qrMatrix(link), side = cw * 0.72, qx = (cw - side) / 2, qy = cw * 0.14;
  // the code builds in row by row, then holds (a real, scannable code once it is in)
  c.save(); c.beginPath(); A.rr(c, qx - side * 0.04, qy - side * 0.04, side * 1.08, side * 1.08, side * 0.06); c.fillStyle = '#ffffff'; c.fill();
  c.beginPath(); c.rect(qx, qy, side, side * clamp((S.tl - 0.2) / 0.6)); c.clip();
  drawQr(c, q, qx, qy, side, '#111111', '#ffffff', 1);
  c.restore();
  noteSlot({ key: 'qr', text: link, x: qx, y: qy, w: side, h: side, size: side * 0.08 });
  // the chat badge in the middle of the code (inside its error-correction budget: ~7 % of the area)
  const cr = side * 0.1; c.fillStyle = '#ffffff'; c.beginPath(); c.arc(cw / 2, qy + side / 2, cr * 1.18, 0, TAU); c.fill();
  badge(c, 'chat', cw / 2, qy + side / 2, cr, P, back((S.tl - 0.7) / 0.4));
  const ty = qy + side + side * 0.17;
  if (isAr(S)) A.arabicText(c, tx(S, 'scanLbl', W0(S, 'scanLbl')), cw / 2, ty, cw * 0.09, m.txt, { col: P.fg, align: 'center', maxW: cw * 0.9, shadow: false });
  else A.uiText(c, tx(S, 'scanLbl', W0(S, 'scanLbl')), cw / 2, ty, cw * 0.075, { col: rgba(P.fg, m.txt), weight: 900, align: 'center', track: 0.14, maxW: cw * 0.9 });
  const sub = tx(S, 'scanSub', W0(S, 'scanSub'));
  if (isAr(S)) A.arabicText(c, sub, cw / 2, ty + cw * 0.1, cw * 0.06, m.txt, { col: mix(P.fg, P.acc, 0.4), align: 'center', maxW: cw * 0.9, shadow: false });
  else A.uiText(c, sub, cw / 2, ty + cw * 0.085, cw * 0.05, { col: rgba(mix(P.fg, P.acc, 0.4), m.txt), weight: 700, align: 'center', maxW: cw * 0.9 });
  // a scanning line sweeps the code now and then
  const sw = ((S.tl - 1) % 2.6) / 1.2;
  if (S.tl > 1 && sw < 1) { const y = qy + side * sw; const g = c.createLinearGradient(0, y - side * 0.06, 0, y); g.addColorStop(0, rgba(P.acc, 0)); g.addColorStop(1, rgba(P.acc, 0.55)); c.fillStyle = g; c.fillRect(qx, y - side * 0.06, side, side * 0.06); }
};

D.consult = (c, S, P, cw, ch, U) => {
  const m = motion(S), r = ch * 0.1;
  card(c, 0, 0, cw, ch, r, P, U);
  badge(c, 'calendar', cw * 0.14, ch * 0.3, ch * 0.13, P, m.icon);
  const x0 = cw * 0.27, mw = cw * 0.68;
  if (isAr(S)) A.arabicText(c, tx(S, 'consultLbl', W0(S, 'consultLbl')), cw * 0.95, ch * 0.31, ch * 0.13, m.txt, { col: P.fg, align: 'right', maxW: mw, shadow: false });
  else A.kineticText(c, tx(S, 'consultLbl', W0(S, 'consultLbl')), x0, ch * 0.33, ch * 0.13, clamp((S.tl - 0.15) / 0.6), { col: P.fg, align: 'left', maxW: mw, shadow: false });
  const sub = tx(S, 'consultSub', W0(S, 'consultSub'));
  if (isAr(S)) A.arabicText(c, sub, cw * 0.95, ch * 0.47, ch * 0.075, m.txt, { col: mix(P.fg, P.acc, 0.4), align: 'right', maxW: mw, shadow: false });
  else A.uiText(c, sub, x0, ch * 0.47, ch * 0.07, { col: rgba(mix(P.fg, P.acc, 0.4), m.txt), weight: 700, maxW: mw });
  // the phone button: pulses gently
  const bw = cw * 0.84, bh = ch * 0.27, bx = (cw - bw) / 2, by = ch * 0.6, pk = clamp((S.tl - 0.45) / 0.4), pulse = 1 + Math.sin(S.tl * 4) * 0.012;
  c.save(); c.globalAlpha *= pk; c.translate(cw / 2, by + bh / 2); c.scale(pulse, pulse); c.translate(-cw / 2, -(by + bh / 2));
  c.fillStyle = P.acc; c.beginPath(); A.rr(c, bx, by, bw, bh, bh / 2); c.fill();
  c.save(); c.translate(bx + bh * 0.55, by + bh / 2); c.rotate(ringShake(S.tl)); drawIcon(c, 'phone', 0, 0, bh * 0.5, P.accFg); c.restore();
  valueText(c, S, 'phone', num(S), bx + bh * 1.0, by + bh * 0.66, bh * 0.4, P.accFg, bw - bh * 1.25);
  c.restore();
};

D.strip = (c, S, P, cw, ch, U) => {
  const m = motion(S), r = ch * 0.5;
  card(c, 0, 0, cw, ch, r, P, U);
  const cells = [['phone', 'phone', num(S)], ['chat', 'whatsapp', wnum(S)], ['globe', 'website', C(S).website || PH.website], ['camera', 'instagram', C(S).instagram || PH.handle]];
  const pad = ch * 0.3, cwid = (cw - pad * 2) / 2, rows = 2, rh = ch / rows;
  cells.forEach(([id, key, def], i) => {
    const col = i % 2, row = Math.floor(i / 2), x = pad + col * cwid, cy = rh * row + rh / 2, a = clamp((S.tl - 0.2 - i * 0.1) / 0.3);
    c.save(); c.globalAlpha *= a;
    badge(c, id, x + rh * 0.32, cy, rh * 0.27, P, 1);
    valueText(c, S, key, def, x + rh * 0.72, cy + rh * 0.12, rh * 0.31, P.fg, cwid - rh * 0.85);
    c.restore();
  });
  c.fillStyle = rgba(P.fg, 0.16); c.fillRect(cw / 2, ch * 0.18, Math.max(1, ch * 0.012), ch * 0.64); c.fillRect(pad, ch / 2, cw - pad * 2, Math.max(1, ch * 0.008));
  void m;
};

D.tap = (c, S, P, cw, ch, U) => {
  const m = motion(S);
  // the button
  const bw = cw * 0.86, bh = ch * 0.42, bx = (cw - bw) / 2, by = ch * 0.1;
  const per = 1.6, f = ((S.tl - 0.6) % per) / per, pressing = S.tl > 0.6 && f > 0.38 && f < 0.52, press = S.tl > 0.6 ? Math.max(0, 1 - Math.abs(f - 0.45) / 0.07) : 0;
  const bs = 1 - press * 0.06;
  c.save(); c.translate(cw / 2, by + bh / 2); c.scale(bs, bs); c.translate(-cw / 2, -(by + bh / 2));
  c.save(); c.shadowColor = 'rgba(0,0,0,0.35)'; c.shadowBlur = U * 0.03 * A.devScale(c) * (1 - press * 0.6); c.shadowOffsetY = U * 0.012 * A.devScale(c) * (1 - press);
  const g = c.createLinearGradient(0, by, 0, by + bh); g.addColorStop(0, mix(P.acc, '#ffffff', 0.15)); g.addColorStop(1, mix(P.acc, '#000000', 0.12));
  c.fillStyle = g; c.beginPath(); A.rr(c, bx, by, bw, bh, bh / 2); c.fill(); c.restore();
  c.save(); c.translate(bx + bh * 0.6, by + bh / 2); c.rotate(ringShake(S.tl)); drawIcon(c, 'phone', 0, 0, bh * 0.48, P.accFg); c.restore();
  if (isAr(S)) A.arabicText(c, tx(S, 'tapLbl', W0(S, 'tapLbl')), bx + bw - bh * 0.4, by + bh * 0.66, bh * 0.36, m.txt, { col: P.accFg, align: 'right', maxW: bw - bh * 1.4, shadow: false });
  else A.uiText(c, tx(S, 'tapLbl', W0(S, 'tapLbl')), bx + bh * 1.1, by + bh * 0.63, bh * 0.34, { col: rgba(P.accFg, m.txt), weight: 900, track: 0.1, maxW: bw - bh * 1.4 });
  c.restore();
  // the ripple where the finger lands
  const tx0 = bx + bw * 0.72, ty0 = by + bh * 0.55;
  if (S.tl > 0.6 && f > 0.42 && f < 0.95) { const rp = (f - 0.42) / 0.53; c.strokeStyle = rgba('#ffffff', 0.85 * (1 - rp)); c.lineWidth = ch * 0.02; c.beginPath(); c.arc(tx0, ty0, ch * (0.04 + 0.22 * easeOut(rp)), 0, TAU); c.stroke(); }
  // the hand: comes in, taps, lifts
  const hin = easeOut((S.tl - 0.3) / 0.4), lift = pressing ? 0 : Math.min(1, Math.abs(f - 0.45) / 0.2), hs = ch * 0.48;
  c.save(); c.globalAlpha *= hin; c.translate(tx0 + hs * 0.05 + (1 - hin) * cw * 0.3, ty0 + hs * 0.48 + lift * ch * 0.06 + (1 - hin) * ch * 0.3); c.rotate(-0.25);
  c.shadowColor = 'rgba(0,0,0,0.3)'; c.shadowBlur = U * 0.02 * A.devScale(c);
  drawIcon(c, 'hand', 0, 0, hs, '#ffe0c8', { inner: 'rgba(120,70,40,0.35)' });
  c.restore();
  valueText(c, S, 'phone', num(S), cw / 2, ch * 0.82, ch * 0.16, '#ffffff', cw * 0.9, 'center', m.txt);
};

/**
 * draw a CTA card inside the item's box (S from elements.js: w, h, tl, d, inn, out, txt, style, t). The card keeps its
 * design's shape, centred in the box; in and out it slides and pops.
 */
export function drawCta(c, K, S) {
  const des0 = ctaById(S.style?.variant);
  if (des0.slim) { drawCta2(c, S, des0, palette(S, K), { num: num(S), ar: isAr(S), word: (k) => W0(S, k) }); return; }
  const des = des0, m = motion(S); if (m.k <= 0.001) return;
  const P = palette(S, K), U = Math.min(S.w, S.h) * 3;
  const cw = Math.min(S.w, S.h * des.ar), ch = cw / des.ar, x = (S.w - cw) / 2, y = (S.h - ch) / 2;
  c.save(); c.globalAlpha *= m.a;
  c.translate(x + cw / 2, y + ch / 2 + m.y * ch); c.scale(m.s, m.s); c.translate(-cw / 2, -ch / 2);
  (D[des.id] || D.call)(c, S, P, cw, ch, Math.min(U, cw));
  c.restore();
}

/** the box a card starts in: its shape, in the lower middle of the frame (canvas px) */
export function ctaBox(variant, W, H) {
  const des = ctaById(variant), u = Math.min(W, H), land = W > H * 1.05;
  let w = Math.min(W * 0.86, u * (des.slim ? 0.95 : 1.1)); if (des.ar < 1.2) w = u * 0.5; else if (des.ar < 2.2) w = Math.min(W * 0.8, u * 0.86);
  const h = w / des.ar;
  return { x: Math.round((W - w) / 2), y: Math.round(Math.min(H * (land ? 0.62 : 0.6) - h / 2, H - h - H * 0.06)), w: Math.round(w), h: Math.round(h) };
}

/** a slim card's colours as drawn now (the inspector's colour pickers start from them) */
export function ctaColors(style, K) {
  const des = ctaById(style?.variant); if (!des.slim) return null;
  return lookOf(style, des, palette({ style }, K)).C;
}
