// KBB Studio · themes · ELEMENTS 2 (18 Nov 2026): contact cards, the logo badge and the country globe badges as theme
// elements — registered in elements.js's catalogue, drawer table, default style and default box, so each one is an
// ordinary theme.el item: placed from Themes → Place elements (or the one-click buttons), dragged, trimmed, run for the
// whole video, undone, saved, its words edited like every other element's.
//
//   cta     ./cta.js          15 contact-card designs (style.variant), the company's Contact & social in style.contact
//   logo    ./logo-badge.js   circle · rounded square · square · pill · bare, the brand kit's logo prepared once
//   cbadge  ./globe-badge.js  6 looks, a country or a region (style.code), names in EN / AR / UR / HI
// Leaf module: it imports no editor code and nothing that imports elements.js (the commands are in ./el2-cmds.js).
import { CTA_DESIGNS, drawCta, ctaBox, contactOf } from './cta.js';
import { LOGO_SHAPES, drawLogoBadge, logoBox, BADGE_DEFAULTS } from './logo-badge.js';
import { BADGE_LOOKS, drawCountryBadge, badgeBox } from './globe-badge.js';
import { themeById } from './themes.js';

const labels = (list) => Object.fromEntries(list.map((x) => [x.id, x.label]));
export const EL2_ELEMENTS = [
  { id: 'cta', label: 'Contact card', icon: '📞', dur: 4, box: 'cta', whole: 'span', el2: true, note: 'Phone, WhatsApp, email, website, socials, QR · 15 designs', variants: CTA_DESIGNS.map((d) => d.id), variantLabels: labels(CTA_DESIGNS) },
  { id: 'logo', label: 'Logo badge', icon: '🔰', dur: 4, box: 'logo', whole: 'span', el2: true, note: 'Your logo on a plate, prepared automatically', variants: LOGO_SHAPES.map((d) => d.id), variantLabels: labels(LOGO_SHAPES) },
  { id: 'cbadge', label: 'Country globe badge', icon: '🌐', dur: 3.5, box: 'cbadge', whole: 'span', el2: true, note: 'Corner badge: turning globe, flag ring, name', variants: BADGE_LOOKS.map((d) => d.id), variantLabels: labels(BADGE_LOOKS) },
];
export const EL2_IDS = new Set(EL2_ELEMENTS.map((e) => e.id));
export const EL2_DRAW = { cta: drawCta, logo: drawLogoBadge, cbadge: drawCountryBadge };

const wsNow = () => { try { return globalThis.window?.kbbEditor?.workspace || null; } catch { return null; } };
const brandColorsOf = (ws) => { const c = ws?.colors || {}; return c.primary ? { primary: c.primary, secondary: c.secondary || null, accent: c.accent || c.secondary || null, text: c.text || null, soft: c.soft || null } : null; };

/** the extra style an el2 element starts with (merged into elements.js elementStyle); {} for any other element */
export function el2Style(el, { ws, variant, theme } = {}) {
  if (!EL2_IDS.has(el)) return {};
  ws = ws || wsNow() || {};
  const brandColors = brandColorsOf(ws);
  if (el === 'cta') return { variant: variant || 'phonewa', contact: contactOf(ws), brandColors, palette: brandColors ? 'brand' : 'theme', lang: 'en' };
  if (el === 'logo') return { variant: variant || 'circle', logo: ws.logo || null, logoFull: ws.logoFull || null, brandColors, badge: { ...BADGE_DEFAULTS } };
  const th = themeById(theme);
  return { variant: variant || 'glass', code: th?.country || 'europe', lang: 'both', flagIn: false };
}

/** where an el2 element starts (canvas px), or null for any other element */
export function el2Box(el, W, H, variant) {
  const aspect = W > H * 1.05 ? '16:9' : H > W * 1.3 ? '9:16' : '1:1';
  if (el === 'cta') return ctaBox(variant || 'phonewa', W, H);
  if (el === 'logo') return logoBox(variant || 'circle', BADGE_DEFAULTS.corner, BADGE_DEFAULTS.size, W, H, aspect);
  if (el === 'cbadge') return badgeBox('tl', 0.3, W, H, aspect);
  return null;
}
