// KBB Studio · Designs · ICONS: the open Lucide set (ISC, 1,539 icons, vendor/lucide-0.460.0/icons.json, offline),
// chosen by MEANING from the brief and restyled: line · fill · duotone · badge, in the scheme's colours.
//   iconsFor(spec, n)             -> icon names that fit the brief (kind, occasion, industry, visa, occupation)
//   searchIcons(words)            -> names whose name/tags match (the editor's icon picker)
//   iconSvg(name, style, colors)  -> an SVG string (constant markup built from the vendored nodes only)
//   iconImage(name, style, colors)-> Promise<HTMLImageElement> (cached) for the canvas renderer
let SET = null;
export async function loadIcons() {
  if (SET) return SET;
  const r = await fetch(new URL('../../vendor/lucide-0.460.0/icons.json', import.meta.url));
  SET = (await r.json()).icons;
  return SET;
}
export const iconCount = () => (SET ? Object.keys(SET).length : 0);

const MEANING = {
  offer: ['badge-percent', 'tag', 'gift', 'sparkles', 'shopping-bag', 'truck', 'heart', 'star'],
  launch: ['sparkles', 'star', 'package-open', 'heart', 'droplets', 'flower-2'],
  beauty: ['sparkles', 'droplets', 'flower-2', 'heart', 'sun', 'leaf', 'gem', 'smile'],
  study: ['graduation-cap', 'book-open', 'plane', 'file-check', 'school', 'calendar-check', 'globe', 'messages-square'],
  visa: ['stamp', 'file-check', 'plane', 'globe', 'id-card', 'calendar-check', 'map-pin', 'shield-check'],
  hiring: ['hard-hat', 'briefcase', 'badge-check', 'file-text', 'plane', 'map-pin', 'handshake', 'calendar-check'],
  event: ['calendar', 'mic', 'users', 'map-pin', 'clock'],
  brand: ['sparkles', 'heart', 'star', 'gem'],
  generic: ['sparkles', 'star', 'circle-check'],
  'uae-national-day': ['flag', 'star', 'heart', 'sparkles', 'gift'],
  ramadan: ['moon', 'star', 'sparkles', 'lamp'], eid: ['moon-star', 'gift', 'sparkles'], 'eid-al-fitr': ['moon-star', 'gift'], 'eid-al-adha': ['moon-star', 'gift'],
  valentines: ['heart', 'gift', 'sparkles'], 'mothers-day': ['heart', 'flower-2', 'gift'], 'white-friday': ['zap', 'tag', 'badge-percent'], '11-11': ['zap', 'tag'],
  summer: ['sun', 'umbrella', 'waves'], 'new-year': ['party-popper', 'sparkles', 'star'], christmas: ['gift', 'tree-pine', 'star'], 'back-to-school': ['backpack', 'pencil', 'book-open'],
  whatsapp: ['message-circle', 'phone'],
  welder: ['flame', 'hard-hat', 'shield'], electrician: ['zap', 'plug'], plumber: ['wrench', 'droplet'], nurse: ['stethoscope', 'heart-pulse', 'cross'], driver: ['truck', 'car', 'car-front'], chef: ['chef-hat', 'utensils'], teacher: ['book-open', 'graduation-cap'],
};
/** benefit lines with an icon each, by kind (labels EN/AR) -- the "grid" layout and the icon chips use them */
export const BENEFITS = {
  study: [['graduation-cap', 'Bachelor & master', 'بكالوريوس وماجستير'], ['file-check', 'Application support', 'دعم التقديم'], ['stamp', 'Student visa guidance', 'إرشاد تأشيرة الطالب'], ['house', 'Accommodation help', 'مساعدة في السكن']],
  hiring: [['hard-hat', 'Skilled roles', 'وظائف مهنية'], ['file-text', 'Work permit guidance', 'إرشاد تصريح العمل'], ['plane', 'Travel support', 'دعم السفر'], ['handshake', 'Interview by appointment', 'مقابلة بموعد']],
  visa: [['file-check', 'Documents checked', 'تدقيق المستندات'], ['calendar-check', 'Appointments', 'المواعيد'], ['shield-check', 'Honest guidance', 'إرشاد صادق'], ['messages-square', 'Free first consultation', 'استشارة أولى مجانية']],
  offer: [['truck', 'Fast UAE delivery', 'توصيل سريع في الإمارات'], ['badge-check', '100% authentic', 'أصلي'], ['gift', 'Gift-ready', 'جاهز للإهداء'], ['credit-card', 'Pay later', 'ادفع لاحقاً']],
  launch: [['sparkles', 'Just landed', 'وصل حديثاً'], ['badge-check', 'Authentic K-beauty', 'جمال كوري أصلي'], ['truck', 'Fast UAE delivery', 'توصيل سريع']],
};
BENEFITS.offer[1] = ['badge-check', 'Authentic K-beauty', 'جمال كوري أصلي']; // no "100%" claims

export function iconsFor(spec, n = 4) {
  const out = [];
  const push = (xs) => { for (const x of xs || []) if (!out.includes(x) && (!SET || SET[x])) out.push(x); };
  if (spec.occasion) push(MEANING[spec.occasion.id]);
  for (const o of spec.occupations || []) push(MEANING[o]);
  push(MEANING[spec.kind]);
  if (spec.industry === 'beauty') push(MEANING.beauty);
  if (/whatsapp/i.test(spec.cta?.en || '')) push(MEANING.whatsapp);
  push(MEANING.generic);
  return out.slice(0, n);
}

export function searchIcons(words, limit = 60) {
  if (!SET) return [];
  const q = String(words || '').toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
  if (!q.length) return Object.keys(SET).slice(0, limit);
  const scored = [];
  for (const [name, v] of Object.entries(SET)) {
    let s = 0;
    for (const w of q) { if (name === w) s += 5; else if (name.includes(w)) s += 3; if (v.t.some((t) => t === w)) s += 2; else if (v.t.some((t) => t.includes(w))) s += 1; }
    if (s) scored.push([s, name]);
  }
  return scored.sort((a, b) => b[0] - a[0] || a[1].localeCompare(b[1])).slice(0, limit).map((x) => x[1]);
}

const attr = (o) => Object.entries(o).filter(([k]) => /^[a-z][a-z0-9-]*$/i.test(k)).map(([k, v]) => `${k}="${String(v).replace(/[^-0-9a-zA-Z .,#%()]/g, '')}"`).join(' ');
const okCol = (c, d) => (/^#[0-9a-f]{6}$/i.test(c || '') ? c : d);
/** style: line | fill | duotone | badge ; colors: {fg, bg, accent} */
export function iconSvg(name, style = 'line', colors = {}) {
  const v = SET?.[name] || SET?.['sparkles'];
  if (!v) return '';
  const fg = okCol(colors.fg, '#111111'), bg = okCol(colors.bg, '#FFFFFF'), ac = okCol(colors.accent, fg);
  const body = v.n.filter(([tag]) => /^(path|circle|rect|line|polyline|polygon|ellipse)$/.test(tag)).map(([tag, a]) => `<${tag} ${attr(a)}/>`).join('');
  const stroke = (col, w = 2) => `fill="none" stroke="${col}" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round"`;
  let inner;
  if (style === 'fill') inner = `<g fill="${fg}" fill-opacity=".22" stroke="${fg}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">${body}</g>`;
  else if (style === 'duotone') inner = `<g fill="${ac}" fill-opacity=".35" stroke="none">${body}</g><g ${stroke(fg, 2)}>${body}</g>`;
  else if (style === 'badge') inner = `<circle cx="12" cy="12" r="12" fill="${bg}"/><g transform="translate(5.4 5.4) scale(.55)" ${stroke(fg, 2.4)}>${body}</g>`;
  else inner = `<g ${stroke(fg, 2)}>${body}</g>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="240" height="240">${inner}</svg>`;
}
const cache = new Map();
export function iconImage(name, style, colors) {
  const svg = iconSvg(name, style, colors);
  if (cache.has(svg)) return cache.get(svg);
  const p = new Promise((res, rej) => { const im = new Image(); im.onload = () => res(im); im.onerror = rej; im.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg); });
  cache.set(svg, p);
  return p;
}
