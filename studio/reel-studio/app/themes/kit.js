// KBB Studio · themes · KITS: what a theme's complete package is made of — names in English and Arabic, the flag, the
// hero landmark, the flight route from the UAE, the currency sign, the job roles, the words on its cards. Plain data
// derived from the catalogue (themes.js) and the country packs. Nothing here states a visa rule, a fee or a salary:
// every number and contact line is an editable placeholder.
import { themeById } from './themes.js';
import { COUNTRIES } from '../auto/countries/countries.js';
import { HERO_OF, SECONDARY } from './landmarks2.js';

// the destination airport and city each country flies to from Dubai (public geography; lon, lat)
export const DEST = {
  gb: [-0.45, 51.47, 'LHR', 'London'], ca: [-79.63, 43.68, 'YYZ', 'Toronto'], au: [151.18, -33.94, 'SYD', 'Sydney'], nz: [174.79, -37.01, 'AKL', 'Auckland'],
  us: [-73.78, 40.64, 'JFK', 'New York'], ie: [-6.27, 53.42, 'DUB', 'Dublin'], de: [13.5, 52.36, 'BER', 'Berlin'], fr: [2.55, 49.01, 'CDG', 'Paris'],
  nl: [4.76, 52.31, 'AMS', 'Amsterdam'], it: [12.25, 41.8, 'FCO', 'Rome'], es: [-3.57, 40.49, 'MAD', 'Madrid'], pt: [-9.13, 38.77, 'LIS', 'Lisbon'],
  pl: [20.97, 52.17, 'WAW', 'Warsaw'], mt: [14.48, 35.86, 'MLA', 'Valletta'], hu: [19.26, 47.44, 'BUD', 'Budapest'], cz: [14.26, 50.1, 'PRG', 'Prague'],
  lt: [25.28, 54.64, 'VNO', 'Vilnius'], lv: [23.97, 56.92, 'RIX', 'Riga'], se: [17.92, 59.65, 'ARN', 'Stockholm'], fi: [24.96, 60.32, 'HEL', 'Helsinki'],
  dk: [12.65, 55.62, 'CPH', 'Copenhagen'], at: [16.57, 48.11, 'VIE', 'Vienna'], be: [4.48, 50.9, 'BRU', 'Brussels'], cy: [33.62, 34.88, 'LCA', 'Larnaca'],
  gr: [23.94, 37.94, 'ATH', 'Athens'], ch: [8.56, 47.46, 'ZRH', 'Zürich'], tr: [28.75, 41.26, 'IST', 'Istanbul'], ee: [24.83, 59.41, 'TLL', 'Tallinn'],
  ae: [55.36, 25.25, 'DXB', 'Dubai'], jp: [139.78, 35.55, 'HND', 'Tokyo'], kr: [126.45, 37.46, 'ICN', 'Seoul'], sg: [103.99, 1.36, 'SIN', 'Singapore'],
  my: [101.71, 2.75, 'KUL', 'Kuala Lumpur'],
};
export const DXB = [55.36, 25.25];
const CUR = { gb: '£', ca: 'C$', au: 'A$', nz: 'NZ$', us: '$', pl: 'zł', hu: 'Ft', cz: 'Kč', se: 'kr', dk: 'kr', ch: 'CHF', tr: '₺', ae: 'AED', jp: '¥', kr: '₩', sg: 'S$', my: 'RM' };
const WATER = new Set(['au', 'sg', 'nl', 'dk', 'tr', 'cz', 'hu', 'gb', 'us', 'se', 'fi', 'ie', 'mt', 'cy', 'gr', 'pt', 'ee', 'nz', 'ca', 'my', 'lv']);
const NIGHT = new Set(['ae', 'sg', 'my', 'jp', 'kr', 'us', 'fr', 'hu']);
const DAY = new Set(['ch', 'nz', 'gr', 'cy', 'mt', 'nl', 'au']);

// a theme's own roles, skylines and words (themes without a country)
const OWN = {
  europe: { skyline: ['big-ben', 'eiffel', 'brandenburg-gate', 'colosseum', 'windmill'], dest: [13.5, 52.36, 'EU', 'Europe'], flagKind: 'stars', flag: ['#003399', '#FFCC00'], sign: '€', light: 'gold', water: false },
  travel: { skyline: ['liberty', 'big-ben', 'eiffel', 'burj-khalifa', 'opera-house'], dest: [-0.45, 51.47, 'LHR', 'London'], sign: '✈', light: 'gold', water: true },
  passport: { skyline: ['big-ben', 'eiffel', 'liberty'], dest: [2.55, 49.01, 'CDG', 'Paris'], sign: '✓', light: 'gold' },
  study: { skyline: ['big-ben', 'cn-tower', 'brandenburg-gate', 'opera-house'], dest: [-79.63, 43.68, 'YYZ', 'Toronto'], sign: 'A+', light: 'day' },
  'work-trades': { skyline: ['burj-khalifa', 'cn-tower', 'big-ben'], dest: [13.5, 52.36, 'BER', 'Berlin'], roles: ['welder', 'electrician', 'builder', 'driver', 'warehouse'], sign: '€', light: 'gold' },
  'work-care': { skyline: ['big-ben', 'brandenburg-gate', 'cn-tower'], dest: [-0.45, 51.47, 'LHR', 'London'], roles: ['nurse', 'caregiver'], sign: '£', light: 'day' },
  'work-hospitality': { skyline: ['eiffel', 'burj-khalifa', 'marina-bay'], dest: [2.55, 49.01, 'CDG', 'Paris'], roles: ['chef', 'waiter', 'driver'], sign: '€', light: 'night' },
  'work-abroad': { skyline: ['burj-khalifa', 'big-ben', 'cn-tower', 'eiffel'], dest: [13.5, 52.36, 'BER', 'Berlin'], roles: ['nurse', 'driver', 'welder', 'chef', 'caregiver'], sign: '€', light: 'gold' },
};
const ROLES_FOR_COUNTRY = ['nurse', 'driver', 'welder', 'chef', 'caregiver'];
const byCode = new Map(COUNTRIES.map((c) => [c.code, c]));

// the words on the cards (English + Arabic). Group defaults; a country fills in its own name.
function words(th, c) {
  if (c) return { kicker: 'STUDY · WORK · LIVE', kickerAr: 'ادرس · اعمل · عش', title: c.name, titleAr: c.nameAr, sub: th.text?.title || `Study in ${c.name}`, subAr: th.text?.titleAr || '', lowerName: 'Your name here', lowerRole: `${c.name} visa consultant`, hiring: `JOBS IN ${c.name.toUpperCase()}`, hiringAr: `وظائف في ${c.nameAr}` };
  const g = th.group, t = th.text || {};
  const base = { kicker: g === 'Work abroad' ? 'CAREERS ABROAD' : g === 'Travel & study' ? 'YOUR NEXT CHAPTER' : (g || '').toUpperCase(), kickerAr: g === 'Work abroad' ? 'وظائف في الخارج' : 'فصلك القادم', title: th.name.replace(/^Work abroad · /, ''), titleAr: th.nameAr || '', sub: t.title || th.name, subAr: t.titleAr || '', lowerName: 'Your name here', lowerRole: 'Your title · Your company', hiring: "WE'RE HIRING", hiringAr: 'نوظف الآن' };
  if (/^work-/.test(th.id)) Object.assign(base, { title: t.title || th.name, titleAr: t.titleAr || th.nameAr, sub: th.name.replace(/^Work abroad · /, 'Work abroad: ') });
  return base;
}

/**
 * The kit for a theme (memoised per theme + colours): {id, code, name, nameAr, colors, flag:[...], flagKind, landmark,
 * secondary, skyline, water, light, iata, city, from, to, sign, roles, motif, words}
 */
const MEMO = new Map();
export function kitFor(themeId, colors) {
  const key = themeId + '|' + JSON.stringify(colors || null);
  if (MEMO.has(key)) return MEMO.get(key);
  const th = themeById(themeId) || themeById('travel');
  const c = th?.country ? byCode.get(th.country) : null, own = OWN[th?.id] || {};
  const pal = Object.assign({ primary: '#1d3557', accent: '#ffd166', soft: '#ffffff', ink: '#111111' }, th?.palette || {}, colors || {});
  const flag = c ? c.colors.slice() : own.flag || [pal.primary, pal.soft, pal.accent];
  const d = c ? DEST[c.code] : own.dest || DEST.gb;
  const kit = {
    id: th?.id || 'travel', group: th?.group || '', code: c?.code || null, name: c?.name || th?.name || '', nameAr: c?.nameAr || th?.nameAr || '',
    colors: { primary: pal.primary, accent: pal.accent, soft: pal.soft || '#ffffff', ink: pal.ink || '#111111' },
    flag, flagKind: c ? 'image' : own.flagKind || 'bands',
    landmark: c ? HERO_OF[c.code] || c.landmark : null, secondary: c ? (SECONDARY[c.code] !== undefined ? SECONDARY[c.code] : null) : null,
    skyline: c ? null : own.skyline || ['big-ben', 'eiffel', 'burj-khalifa'],
    water: c ? WATER.has(c.code) : !!own.water,
    light: c ? (NIGHT.has(c.code) ? 'night' : DAY.has(c.code) ? 'day' : 'gold') : own.light || 'gold',
    iata: d[2], city: d[3], to: [d[0], d[1]], from: c?.code === 'ae' ? [72.87, 19.09] : DXB, fromCode: c?.code === 'ae' ? 'BOM' : 'DXB',
    sign: c ? CUR[c.code] || '€' : own.sign || '★',
    roles: own.roles || ROLES_FOR_COUNTRY,
    hero: th?.sting?.hero || 'star4',
    icons: (th?.layers?.find((l) => l.kind === 'icons')?.icons || []).slice(0, 4),
    words: words(th || {}, c),
    beauty: !c && !OWN[th?.id],
  };
  // keep the flag colours readable as slabs: never three whites in a row
  kit.slabs = [kit.colors.primary, ...kit.flag.filter((x) => x.toLowerCase() !== kit.colors.primary.toLowerCase())].slice(0, 3);
  while (kit.slabs.length < 3) kit.slabs.push(kit.slabs.length === 1 ? kit.colors.accent : '#ffffff');
  MEMO.set(key, kit); if (MEMO.size > 120) MEMO.delete(MEMO.keys().next().value);
  return kit;
}
/** the hero landmark each country shows (for the report and the panel) */
export const heroOf = (code) => { const c = byCode.get(code); return c ? HERO_OF[code] || c.landmark : null; };
