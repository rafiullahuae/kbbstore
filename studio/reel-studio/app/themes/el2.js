// KBB Studio · themes · ELEMENTS 2 (18 Nov 2026): contact cards, the logo badge and the country globe badges as theme
// elements — registered in elements.js's catalogue, drawer table, default style and default box, so each one is an
// ordinary theme.el item: placed from Themes → Place elements (or the one-click buttons), dragged, trimmed, run for the
// whole video, undone, saved, its words edited like every other element's.
//
//   cta     ./cta.js          25 contact-card designs (style.variant): 10 slim always-moving bars (./cta2.js, their
//                             show / colour / motion settings in style.ctaLook) + the first 15; Contact & social in style.contact
//   logo    ./logo-badge.js   circle · rounded square · square · pill · bare, the brand kit's logo prepared once
//   cbadge  ./globe-badge.js  6 looks, a country or a region (style.code), names in EN / AR / UR / HI
//   tbehind ./textbehind.js   a huge word (or two lines, or a carousel of countries) BEHIND the host: the person cut out
//                             in front of it, the real background behind
// Leaf module: it imports no editor code and nothing that imports elements.js (the commands are in ./el2-cmds.js).
import { CTA_DESIGNS, drawCta, ctaBox, contactOf, CTA_DEFAULT } from './cta.js';
import { LOGO_SHAPES, drawLogoBadge, logoBox, BADGE_DEFAULTS } from './logo-badge.js';
import { BADGE_LOOKS, drawCountryBadge, badgeBox } from './globe-badge.js';
import { END_DESIGNS, END_DUR, drawEndScreen } from './endscreen.js';
import { themeById } from './themes.js';
import { drawTextBehind, tbStyle, tbBox } from './textbehind.js';

const labels = (list) => Object.fromEntries(list.map((x) => [x.id, x.label]));
export const EL2_ELEMENTS = [
  { id: 'cta', label: 'Contact card', icon: '📞', dur: 4, box: 'cta', whole: 'span', el2: true, note: 'Slim animated bars, phone, WhatsApp, email, website, socials, QR · 25 designs', variants: CTA_DESIGNS.map((d) => d.id), variantLabels: labels(CTA_DESIGNS) },
  { id: 'logo', label: 'Logo badge', icon: '🔰', dur: 4, box: 'logo', whole: 'span', el2: true, note: 'Your logo on a plate, prepared automatically', variants: LOGO_SHAPES.map((d) => d.id), variantLabels: labels(LOGO_SHAPES) },
  { id: 'endscreen', label: 'Ending screen', icon: '🏁', dur: END_DUR, box: 'full', whole: 'end', el2: true, note: 'Closing card: logo, phone, WhatsApp, website, CTA · 11 looks', variants: END_DESIGNS.map((d) => d.id), variantLabels: labels(END_DESIGNS) },
  { id: 'tbehind', label: 'Text behind the host', icon: '🔤', dur: 3, box: 'tbehind', whole: 'span', el2: true, note: 'A huge word behind you: you stand in front of it, the real background stays' },
  { id: 'cbadge', label: 'Country globe badge', icon: '🌐', dur: 3.5, box: 'cbadge', whole: 'span', el2: true, note: 'Corner badge: turning globe, flag ring, name', variants: BADGE_LOOKS.map((d) => d.id), variantLabels: labels(BADGE_LOOKS) },
];
export const EL2_IDS = new Set(EL2_ELEMENTS.map((e) => e.id));
export const EL2_DRAW = { cta: drawCta, logo: drawLogoBadge, cbadge: drawCountryBadge, endscreen: drawEndScreen, tbehind: drawTextBehind };

const wsNow = () => { try { return globalThis.window?.kbbEditor?.workspace || null; } catch { return null; } };
const brandColorsOf = (ws) => { const c = ws?.colors || {}; return c.primary ? { primary: c.primary, secondary: c.secondary || null, accent: c.accent || c.secondary || null, text: c.text || null, soft: c.soft || null } : null; };

/** the extra style an el2 element starts with (merged into elements.js elementStyle); {} for any other element */
export function el2Style(el, { ws, variant, theme } = {}) {
  if (!EL2_IDS.has(el)) return {};
  ws = ws || wsNow() || {};
  const brandColors = brandColorsOf(ws);
  if (el === 'cta') return { variant: variant || CTA_DEFAULT, contact: contactOf(ws), brandColors, palette: brandColors ? 'brand' : 'theme', lang: 'en' };
  if (el === 'logo') return { variant: variant || 'circle', logo: ws.logo || null, logoFull: ws.logoFull || null, brandColors, badge: { ...BADGE_DEFAULTS } };
  const th = themeById(theme);
  if (el === 'tbehind') return tbStyle({ code: th?.country || 'europe' });
  if (el === 'endscreen') {
    const first = (v) => (Array.isArray(v) ? v[0] : v) || null, photo = Object.values(ws.adPhotos || {}).find(Boolean) || null;
    return { variant: variant || 'corporate', contact: contactOf(ws), brandColors, palette: brandColors ? 'brand' : 'theme', lang: 'en', logo: ws.logo || null, slides: 1, photo, code: th?.country || 'europe', ctaEn: first(ws.endCard?.cta), ctaAr: first(ws.endCard?.ctaAr) };
  }
  return { variant: variant || 'glass', code: th?.country || 'europe', lang: 'both', flagIn: false };
}

/** where an el2 element starts (canvas px), or null for any other element */
export function el2Box(el, W, H, variant) {
  const aspect = W > H * 1.05 ? '16:9' : H > W * 1.3 ? '9:16' : '1:1';
  if (el === 'cta') return ctaBox(variant || CTA_DEFAULT, W, H);
  if (el === 'logo') return logoBox(variant || 'circle', BADGE_DEFAULTS.corner, BADGE_DEFAULTS.size, W, H, aspect);
  if (el === 'cbadge') return badgeBox('tl', 0.3, W, H, aspect);
  if (el === 'endscreen') return { x: 0, y: 0, w: W, h: H };
  if (el === 'tbehind') return tbBox(W, H);
  return null;
}
