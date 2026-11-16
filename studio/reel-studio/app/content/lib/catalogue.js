// KBB Studio · content · the offline catalogues: KBB product cut-outs (brands/kbb/products/index.json) and the
// Planet Careers country pack (brands/planet-careers/countries/countries.json). Read once, cached.
import { engine } from '../../core/engine.js';

const cache = new Map();
export async function readJSON(path) {
  if (cache.has(path)) return cache.get(path);
  const p = (async () => {
    try { if (engine.available) return await engine.readFile(path, 'json'); } catch {}
    const r = await fetch('/' + path); if (!r.ok) throw new Error(path + ' ' + r.status); return r.json();
  })();
  cache.set(path, p);
  p.catch(() => cache.delete(path));
  return p;
}

/** KBB products with their studio paths: [{id, brand, name, file, w, h, keywords, src}] */
export async function products() {
  try { return (await readJSON('brands/kbb/products/index.json')).products.map((p) => ({ ...p, src: 'brands/kbb/products/' + p.file })); } catch { return []; }
}
/** countries with absolute studio paths for flag / map / landmark / stickers */
export async function countries() {
  try {
    const base = 'brands/planet-careers/countries/';
    return (await readJSON(base + 'countries.json')).countries.map((c) => ({ ...c, flagSrc: base + c.flag, mapSrc: base + c.map, landmarkSrc: c.landmark?.file ? base + c.landmark.file : null,
      stickerSrc: Object.fromEntries(Object.entries(c.stickers || {}).map(([k, v]) => [k, base + v])) }));
  } catch { return []; }
}

/** which catalogues a company uses: KBB products for beauty, the country pack for consultancies (both if unsure) */
export function catalogueKind(ws) {
  const s = `${ws?.id || ''} ${ws?.brandRef || ''} ${ws?.industry || ''}`;
  if (/planet|consult|immigra|visa|study|education/i.test(s)) return 'countries';
  if (/kbb|beauty|skin|cosmetic|retail/i.test(s)) return 'products';
  return 'both';
}
export async function catalogueFor(ws) {
  const k = catalogueKind(ws);
  const [p, c] = await Promise.all([k !== 'countries' ? products() : [], k !== 'products' ? countries() : []]);
  return { products: p, countries: c };
}

/** relative luminance + WCAG contrast (also used by covers and looks) */
export function lum(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex || '').trim()); if (!m) return 0;
  const n = parseInt(m[1], 16), ch = [n >> 16, (n >> 8) & 255, n & 255].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
  return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
}
export const contrast = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
export const rgbHex = (r, g, b) => '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');

/** the brand colour that reads as a highlight ON VIDEO (bright enough against dark/mid footage) */
export function brandPop(ws) {
  const c = ws?.colors || {};
  const list = [c.primary, c.accent, c.secondary].filter((x) => /^#[0-9a-f]{6}$/i.test(x || ''));
  return list.find((x) => lum(x) > 0.12) || list[0] || '#ff2f7a';
}
