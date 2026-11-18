// KBB Studio · themes · CAPSULES DATA: the line icons the job capsules draw (original, drawn for KBB Studio on a 24-unit
// grid, stroked with round ends -- free to use), the 100-occupation ready list (library/occupations/occupations.json),
// the continents and the default capsules of the owner's screenshot (Planet Careers).
import { COUNTRIES, COUNTRY_GROUPS } from './capsules/countries.js';
export { COUNTRIES, COUNTRY_GROUPS };

/** id -> [label, SVG path data on a 24×24 grid, stroked] */
export const LINE_ICONS = {
  wheel: ['Steering wheel', 'M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0M9.5 12a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0 -5 0M3.4 10.5h6.2M14.4 10.5h6.2M12 14.5V21'],
  headset: ['Headset', 'M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v5H5a1 1 0 0 1-1-1zM20 14h-3v5h2a1 1 0 0 0 1-1zM18 19c0 1.6-2 2.5-5 2.5'],
  cross: ['Medical cross', 'M9.5 3.5h5v6h6v5h-6v6h-5v-6h-6v-5h6z'],
  stetho: ['Stethoscope', 'M6 3v5a4 4 0 0 0 8 0V3M10 12v3a5 5 0 0 0 10 0v-2M18 11a2 2 0 1 0 4 0a2 2 0 1 0 -4 0'],
  grad: ['Graduation cap', 'M2 9l10-5 10 5-10 5zM6 11v5c0 1.5 3 3 6 3s6-1.5 6-3v-5M22 9v6'],
  book: ['Book', 'M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM4 21V5M19 19v2H6'],
  leaf: ['Leaf', 'M5 19C5 10 10 5 20 4c0 10-5 15-14 15zM5 19l8-8'],
  sprout: ['Sprout', 'M12 21v-9M12 12C12 8 9 6 5 6c0 4 3 6 7 6zM12 14c0-4 3-6 7-6 0 4-3 6-7 6z'],
  roller: ['Paint roller', 'M4 4h13v5H4zM17 6.5h3v5h-8v3M11 14.5h2V21h-2z'],
  camera: ['Camera', 'M3 8h4l2-3h6l2 3h4v11H3zM8.5 13.5a3.5 3.5 0 1 0 7 0a3.5 3.5 0 1 0 -7 0'],
  video: ['Video camera', 'M3 7h12v10H3zM15 10l6-3v10l-6-3'],
  scooter: ['Delivery scooter', 'M3.5 16.5a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0 -5 0M15.5 16.5a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0 -5 0M8.5 16.5h7M14 5h3l1.5 9M5 12h7l3 4.5M5 8h5v4'],
  parcel: ['Parcel', 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10M7.5 5l9 4'],
  bell: ['Desk bell', 'M4 18h16M5 18a7 7 0 0 1 14 0M12 9V7M10 7h4'],
  heart: ['Caring heart', 'M12 20s-8-4.5-8-10a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 5.5-8 10-8 10zM8.5 11.5h2l1-2 1.5 4 1-2h1.5'],
  wrench: ['Wrench', 'M15 3.5a5 5 0 0 0-4.6 6.9l-6.9 7a1.9 1.9 0 0 0 2.7 2.7l6.9-7A5 5 0 0 0 20.5 9l-3 1-3-3 1-3.5z'],
  briefcase: ['Briefcase', 'M3 8h18v11H3zM8 8V5h8v3M3 13h18M11 13v2h2v-2'],
  hardhat: ['Hard hat', 'M3 17h18M5 17v-3a7 7 0 0 1 14 0v3M10 7.4V11M14 7.4V11'],
  bed: ['Hotel bed', 'M3 19V6M3 15h18v4M21 15v-3a3 3 0 0 0-3-3h-7v6M5.5 11.5a1.5 1.5 0 1 0 3 0a1.5 1.5 0 1 0 -3 0'],
  cloche: ['Serving cloche', 'M3 17h18M5 17a7 7 0 0 1 14 0M12 8v2M10.5 8h3'],
  chefhat: ['Chef hat', 'M7 14v6h10v-6M7 14a4 4 0 0 1-1-7.8A4.5 4.5 0 0 1 12 4a4.5 4.5 0 0 1 6 2.2A4 4 0 0 1 17 14M7 17h10'],
  truck: ['Truck', 'M2 6h12v10H2zM14 9h4l3 3v4h-7M4.5 18a2 2 0 1 0 4 0a2 2 0 1 0 -4 0M15 18a2 2 0 1 0 4 0a2 2 0 1 0 -4 0'],
  bus: ['Bus', 'M5 4h14v13H5zM5 11h14M7 20v-3M17 20v-3M8 14h.01M16 14h.01'],
  car: ['Car', 'M3 16v-4l2-5h14l2 5v4zM3 16v2h3v-2M18 16v2h3v-2M3 12h18M7 14h.01M17 14h.01'],
  warehouse: ['Warehouse', 'M3 21V9l9-5 9 5v12M7 21v-8h10v8M7 17h10'],
  bolt: ['Bolt', 'M13 2L5 13h6l-1 9 8-11h-6z'],
  flame: ['Flame', 'M12 21a6 6 0 0 0 6-6c0-4-3-6-4-9-1 2-2 3-3.5 3.5C9 10 6 12 6 15a6 6 0 0 0 6 6z'],
  hammer: ['Hammer', 'M3 21l10-10M12 6l3-3 6 6-3 3z'],
  brick: ['Bricks', 'M3 6h18v12H3zM3 10h18M3 14h18M9 6v4M15 10v4M9 14v4'],
  snow: ['Cooling', 'M12 3v18M4.2 7.5l15.6 9M4.2 16.5l15.6-9'],
  crane: ['Crane', 'M5 21V4M5 4h15M5 8l4-4M17 4v6M15 10h4v3h-4zM3 21h6'],
  broom: ['Broom', 'M14 3l-4 9M6 12h8l2 9H4zM8 16v5M12 16v5'],
  shield: ['Shield', 'M12 3l8 3v6c0 5-4 8-8 9-4-1-8-4-8-9V6zM9 12l2 2 4-4'],
  cart: ['Cart', 'M3 4h2l2.5 11h11L21 8H6M8 20a1 1 0 1 0 2 0a1 1 0 1 0 -2 0M16 20a1 1 0 1 0 2 0a1 1 0 1 0 -2 0'],
  scissors: ['Scissors', 'M3.5 6a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0 -5 0M3.5 18a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0 -5 0M8 7.5L20 18M8 16.5L20 6'],
  sparkle: ['Sparkle', 'M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z'],
  smile: ['Child care', 'M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0M9 10h.01M15 10h.01M8.5 14.5c2 2 5 2 7 0'],
  house: ['House', 'M3 11l9-7 9 7M5 10v10h14V10M10 20v-6h4v6'],
  wheat: ['Wheat', 'M12 21V7M12 7c-2-1-3-2.5-3-4.5 2 0 3 2 3 4.5zM12 7c2-1 3-2.5 3-4.5-2 0-3 2-3 4.5zM12 12c-2-1-4-2-4-5 2 0 4 2 4 5zM12 12c2-1 4-2 4-5-2 0-4 2-4 5zM12 17c-2-1-4-2-4-5 2 0 4 2 4 5zM12 17c2-1 4-2 4-5-2 0-4 2-4 5z'],
  tractor: ['Tractor', 'M4 17a3 3 0 1 0 6 0a3 3 0 1 0 -6 0M16 18a2 2 0 1 0 4 0a2 2 0 1 0 -4 0M7 14V7h6l2 5h4v4M10 17h6'],
  fish: ['Fish', 'M3 12c3-4 8-6 13-3l5-3v12l-5-3c-5 3-10 1-13-3zM8 11h.01'],
  gear: ['Gear', 'M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9L7 7M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1M8 12a4 4 0 1 0 8 0a4 4 0 1 0 -8 0'],
  factory: ['Factory', 'M3 21V10l5 3v-3l5 3v-3l5 3V4h3v17zM7 17h2M12 17h2M17 17h1'],
  laptop: ['Laptop', 'M5 5h14v10H5zM2 19h20M5 15l-3 4M19 15l3 4'],
  code: ['Code', 'M8 8l-4 4 4 4M16 8l4 4-4 4M13.5 6l-3 12'],
  compass: ['Drafting compass', 'M12 3v2M12 6.5L6 21M12 6.5L18 21M7.5 17h9M10.5 5a1.5 1.5 0 1 0 3 0a1.5 1.5 0 1 0 -3 0'],
  calc: ['Calculator', 'M5 3h14v18H5zM8 6h8v4H8zM8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01'],
  people: ['Team', 'M5.5 7.5a3.5 3.5 0 1 0 7 0a3.5 3.5 0 1 0 -7 0M2 20a7 7 0 0 1 14 0M16 4a3.5 3.5 0 0 1 0 7M18 13.5a7 7 0 0 1 4 6.5'],
  user: ['Person', 'M8 8a4 4 0 1 0 8 0a4 4 0 1 0 -8 0M4 21a8 8 0 0 1 16 0'],
  cup: ['Coffee', 'M4 8h12v6a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5zM16 10h2a2 2 0 0 1 0 4h-2M8 3v2M12 3v2'],
  bread: ['Bread', 'M4 11a4 4 0 0 1 4-6h8a4 4 0 0 1 4 6v8H4zM9 9v3M15 9v3'],
  knife: ['Knife', 'M4 20L16 8l3.5 3.5C16 15 12 17.5 7 18.5zM16 8l2-2'],
  pill: ['Pill', 'M4.5 12.5l8-8a4.24 4.24 0 0 1 6 6l-8 8a4.24 4.24 0 0 1-6-6zM8.5 8.5l6 6'],
  flask: ['Lab flask', 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3M7.5 15h9'],
  tooth: ['Tooth', 'M7 3c-2 0-4 2-3 6 1 3 1 6 2 9 .5 2 2 2 2.5 0L10 14h4l1.5 4c.5 2 2 2 2.5 0 1-3 1-6 2-9 1-4-1-6-3-6-2 0-3 1-5 1S9 3 7 3z'],
  pulse: ['Pulse', 'M3 12h4l2-5 4 10 2-5h6'],
  dumbbell: ['Dumbbell', 'M6 7v10M3 9v6M18 7v10M21 9v6M6 12h12'],
  phone: ['Phone', 'M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z'],
  drop: ['Water drop', 'M12 3c3 4 6 7.5 6 11a6 6 0 0 1-12 0c0-3.5 3-7 6-11z'],
  spool: ['Thread spool', 'M6 4h12M6 20h12M8 4v16M16 4v16M8 8l8 3M8 12l8 3'],
  chip: ['Chip', 'M7 7h10v10H7zM10 10h4v4h-4zM9 3v4M15 3v4M9 17v4M15 17v4M3 9h4M3 15h4M17 9h4M17 15h4'],
  coins: ['Coins', 'M4 7a8 3 0 1 0 16 0a8 3 0 1 0 -16 0M4 7v5c0 1.7 3.6 3 8 3s8-1.3 8-3V7M4 12v5c0 1.7 3.6 3 8 3s8-1.3 8-3v-5'],
  clipboard: ['Checklist', 'M8 4h8v3H8zM6 5H5v16h14V5h-1M9 13l2 2 4-4'],
  globe: ['Globe', 'M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18'],
  plane: ['Plane', 'M2.5 13.5l7.5-2 4-7.5h2l-1.2 7 5.7.5v2.5l-5.7.5 1.2 6h-2l-4-5.5-7.5-1z'],
  star: ['Star', 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z'],
  check: ['Check', 'M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0M8 12l3 3 5-6'],
};
export const ICON_IDS = Object.keys(LINE_ICONS);

/** an occupation id (library/occupations) -> the nearest line icon */
const OCC_ICON = [
  [/plumb|pipe/, 'wrench'], [/electric/, 'bolt'], [/weld/, 'flame'], [/carpent|glazier/, 'hammer'], [/mason|tiler|plaster/, 'brick'], [/painter/, 'roller'],
  [/ac-tech/, 'snow'], [/steel|scaffold|roofer|labourer|supervisor/, 'hardhat'], [/crane|heavy-equip/, 'crane'], [/forklift|warehouse/, 'warehouse'],
  [/bus/, 'bus'], [/heavy-driver/, 'truck'], [/delivery|courier/, 'scooter'], [/taxi|family-driver|light-driver/, 'wheel'],
  [/auto-elec|mechanic|maintenance/, 'wrench'], [/nurse/, 'cross'], [/caregiver|elderly/, 'heart'], [/doctor/, 'stetho'], [/pharmac/, 'pill'], [/lab-tech/, 'flask'],
  [/physio|trainer/, 'dumbbell'], [/dental/, 'tooth'], [/paramedic/, 'pulse'], [/chef|cook|kitchen/, 'chefhat'], [/baker|pastry/, 'bread'], [/waiter|steward/, 'cloche'],
  [/barista|bartender/, 'cup'], [/housekeep|housemaid|domestic/, 'house'], [/front-desk|receptionist/, 'bell'], [/cleaner|cleaning/, 'broom'], [/security/, 'shield'],
  [/salesperson|sales-exec/, 'cart'], [/cashier/, 'coins'], [/beautician/, 'sparkle'], [/barber|tailor|hairdress/, 'scissors'], [/call-centre/, 'headset'], [/car-wash/, 'drop'],
  [/gardener|greenhouse/, 'sprout'], [/teacher|teaching/, 'grad'], [/nanny|babysit/, 'smile'], [/farm|fruit/, 'wheat'], [/butcher|meat/, 'knife'], [/fish/, 'fish'],
  [/food-pack|packer|dairy/, 'parcel'], [/factory|production|assembly|textile/, 'factory'], [/machine|cnc/, 'gear'], [/quality/, 'clipboard'], [/electronics/, 'chip'],
  [/it-spec/, 'laptop'], [/software/, 'code'], [/architect|civil|engineer/, 'compass'], [/accountant/, 'calc'], [/admin/, 'briefcase'], [/hr-/, 'people'], [/tractor/, 'tractor'],
];
export const iconForOccupation = (id) => (id === 'tractor-driver' ? 'tractor' : (OCC_ICON.find(([re]) => re.test(id)) || [0, 'user'])[1]);

/** the 100 occupations, read once (the library file; the app works without it -- the featured list below is built in) */
let OCC = null;
export function loadOccupations() {
  return (OCC ||= fetch(new URL('../../library/occupations/occupations.json', import.meta.url)).then((r) => (r.ok ? r.json() : null)).then((d) => ({
    sectors: (d?.sectors || []).map((s) => ({ id: s.id, en: s.en, ar: s.ar })),
    list: (d?.occupations || []).map((o) => ({ id: o.id, sector: o.sector, en: o.plural?.en || o.name.en, ar: o.name.ar, icon: iconForOccupation(o.id) })),
  })).catch(() => ({ sectors: [], list: [] })));
}

/** the jobs row the owner asked for, in his order (EN + AR) */
export const FEATURED_JOBS = [
  ['Drivers', 'سائقون', 'wheel'], ['Customer care', 'خدمة العملاء', 'headset'], ['Nurses', 'ممرضون', 'cross'], ['Doctors', 'أطباء', 'stetho'],
  ['Teachers', 'معلمون', 'grad'], ['Gardeners', 'بستانيون', 'sprout'], ['Painters', 'دهّانون', 'roller'], ['Photographers', 'مصورون', 'camera'],
  ['Camera operators', 'مصورو فيديو', 'video'], ['Delivery riders', 'سائقو توصيل', 'scooter'], ['Receptionists', 'موظفو استقبال', 'bell'], ['Caregivers', 'مقدمو رعاية', 'heart'],
  ['Skilled trades', 'حِرف ماهرة', 'wrench'], ['Administration', 'الإدارة', 'briefcase'], ['Construction', 'البناء', 'hardhat'], ['Hospitality', 'الضيافة', 'bed'], ['Chefs', 'طهاة', 'chefhat'],
];

/** the continents as capsules: Europe wears the EU mark, the others a globe */
export const CONTINENTS = [
  { id: 'europe', en: 'Europe', ar: 'أوروبا', group: 'Europe', flag: 'eu' },
  { id: 'asia', en: 'Asia', ar: 'آسيا', group: 'Asia' },
  { id: 'middle-east', en: 'Middle East', ar: 'الشرق الأوسط', group: 'Middle East' },
  { id: 'north-america', en: 'North America', ar: 'أمريكا الشمالية', group: 'North America' },
  { id: 'south-america', en: 'South America', ar: 'أمريكا الجنوبية', group: 'South America' },
  { id: 'africa', en: 'Africa', ar: 'أفريقيا', group: 'Africa' },
  { id: 'oceania', en: 'Oceania', ar: 'أوقيانوسيا', group: 'Oceania' },
];
export const continentById = (id) => CONTINENTS.find((c) => c.id === id) || null;

// Arabic country names: the browser's own CLDR data (offline), with the few the platform does not name
const AR_EXTRA = { 'gb-eng': 'إنجلترا', 'gb-sct': 'اسكتلندا', 'gb-wls': 'ويلز', 'gb-nir': 'أيرلندا الشمالية', 'sh-ac': 'جزيرة أسينشين', 'sh-hl': 'سانت هيلينا', 'sh-ta': 'تريستان دا كونا', xk: 'كوسوفو', ic: 'جزر الكناري', dg: 'دييغو غارسيا', cp: 'جزيرة كليبرتون' };
let AR_NAMES = null;
export function arabicName(code, en) {
  if (AR_EXTRA[code]) return AR_EXTRA[code];
  try { AR_NAMES ||= new Intl.DisplayNames(['ar'], { type: 'region' }); const n = AR_NAMES.of(code.toUpperCase()); if (n && n.toUpperCase() !== code.toUpperCase()) return n; } catch {}
  return en;
}
export const countryByCode = (code) => COUNTRIES.find((c) => c[0] === code) || null;

let seq = 0;
export const capId = () => 'c' + Date.now().toString(36).slice(-5) + (seq++).toString(36) + Math.floor(Math.random() * 1296).toString(36);
export const jobCap = (en, ar, icon) => ({ id: capId(), en, ar: ar || '', icon: icon || 'star' });
export function countryCap(code) { const c = countryByCode(code); return { id: capId(), en: c ? c[1] : code.toUpperCase(), ar: arabicName(code, c ? c[1] : code), flag: code }; }
export function continentCap(id) { const c = continentById(id); return { id: capId(), en: c.en, ar: c.ar, cont: id, ...(c.flag ? { flag: c.flag } : {}) }; }

export const DEFAULT_COUNTRIES = ['ca', 'nz', 'gb', '@europe', 'de', 'pl', 'au', 'ie', 'cz', 'mt'];
