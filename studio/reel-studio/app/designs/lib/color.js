// KBB Studio · Designs · colour maths (pure): WCAG contrast, HSL moves, readable inks, mixes.
export const HEX = /^#[0-9a-f]{6}$/i;
export const norm = (c, d = '#000000') => (typeof c === 'string' && /^#[0-9a-f]{6}/i.test(c) ? c.slice(0, 7).toUpperCase() : d);
export function rgb(hex) { const n = parseInt(norm(hex).slice(1), 16); return [n >> 16, (n >> 8) & 255, n & 255]; }
export const toHex = (r, g, b) => '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('').toUpperCase();
const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
export function lumRGB(r, g, b) { return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b); }
export const lum = (hex) => lumRGB(...rgb(hex));
export const ratio = (l1, l2) => (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
export const contrast = (a, b) => ratio(lum(a), lum(b));
export function mix(a, b, t) { const x = rgb(a), y = rgb(b); return toHex(x[0] + (y[0] - x[0]) * t, x[1] + (y[1] - x[1]) * t, x[2] + (y[2] - x[2]) * t); }
export function rgba(hex, a) { const [r, g, b] = rgb(hex); return `rgba(${r},${g},${b},${a})`; }

export function hsl(hex) {
  let [r, g, b] = rgb(hex).map((v) => v / 255);
  const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2;
  let h = 0, s = 0;
  if (mx !== mn) {
    const d = mx - mn; s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
    h = mx === r ? (g - b) / d + (g < b ? 6 : 0) : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; h *= 60;
  }
  return [h, s, l];
}
export function fromHsl(h, s, l) {
  h = ((h % 360) + 360) % 360; s = Math.max(0, Math.min(1, s)); l = Math.max(0, Math.min(1, l));
  const c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = l - c / 2;
  const [r, g, b] = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
  return toHex((r + m) * 255, (g + m) * 255, (b + m) * 255);
}
export const withL = (hex, l) => { const [h, s] = hsl(hex); return fromHsl(h, s, l); };
export const shift = (hex, dh = 0, ds = 0, dl = 0) => { const [h, s, l] = hsl(hex); return fromHsl(h + dh, s + ds, l + dl); };

/** the colour nearest `c` (same hue) that reaches `min` contrast on `bg`; falls back to black / white */
export function reach(c, bg, min = 4.5) {
  if (contrast(c, bg) >= min) return norm(c);
  const [h, s, l0] = hsl(c), dark = lum(bg) > 0.3;
  for (let i = 1; i <= 40; i++) {
    const l = dark ? l0 - i * 0.025 : l0 + i * 0.025;
    if (l < 0 || l > 1) break;
    const x = fromHsl(h, s, l);
    if (contrast(x, bg) >= min) return x;
  }
  return contrast('#FFFFFF', bg) >= contrast('#111111', bg) ? '#FFFFFF' : '#111111';
}
/** black-ish or white, whichever reads better on bg */
export const ink = (bg, darkInk = '#161616') => (contrast('#FFFFFF', bg) >= contrast(darkInk, bg) ? '#FFFFFF' : darkInk);
