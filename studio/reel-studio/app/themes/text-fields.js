// KBB Studio · themes · TEXT FIELDS: which words a theme element draws, what each one shows now, and what it would
// show by default — found by drawing the element itself (./text.js records every tx() and every string it draws), so a
// new element or a new word never needs a list kept in step by hand.
//
//   textFieldsOf(item, project) -> [{key, label, def, value, user, ar, pair}]   in drawing order, Arabic after English
//   elOfItem(item)                -> the element id an item draws ('intro' for a sting, 'end' for an outro)
//   ADD_LINE                      -> the elements that take an added line, and the key the next one goes in
import { drawElement, elementById } from './elements.js';
import { setArtProbe } from './art.js';

export const elOfItem = (item) => (item?.type === 'theme.sting' ? 'intro' : item?.type === 'theme.outro' ? 'end' : item?.type === 'theme.el' ? item.style?.el || null : null);
/** elements that draw no words at all (the capsules carousel has its own editor) */
export const NO_TEXT = new Set(['wipe', 'confetti', 'grade', 'capsules']);

const LABELS = {
  title: 'Title', titleAr: 'Title (Arabic)', kicker: 'Small line', name: 'Name', role: 'Role', line3: 'Third line',
  cta: 'Call to action', ctaAr: 'Call to action (Arabic)', phone: 'Phone', web: 'Website', handle: 'Handle', brand: 'Company',
  callLbl: 'Label · phone', webLbl: 'Label · website', followLbl: 'Label · handle', extra: 'Extra line',
  value: 'Number', label: 'Label', sign: 'Currency sign', from: 'From', to: 'To', fromCode: 'From (code)', toCode: 'To (code)',
  flight: 'Flight', gate: 'Gate', seat: 'Seat', date: 'Date', flightLbl: 'Label · flight', gateLbl: 'Label · gate', seatLbl: 'Label · seat',
  dateLbl: 'Label · date', stub: 'Stub line', stamp: 'Stamp', stampCode: 'Stamp centre', approved: 'Second stamp', visas: 'Page title',
  hiring: 'Banner', hiringAr: 'Banner (Arabic)', greet: 'Small line', tag: 'Tag', time: 'Time', headline: 'Headline', sub: 'Subline',
  crawl: 'Crawl text', crawlTag: 'Crawl label', country: 'Country', note: 'Footnote', skills: 'Skills', seal: 'Seal', doc: 'Document',
  scroll: 'Scroll', tagline: 'Tagline', logoTop: 'Logo slot · top', logoBottom: 'Logo slot · bottom', badge: 'Badge', status: 'Status', mat: 'Door mat', v: 'Value',
  // contact cards and badges (./el2.js)
  waLbl: 'Label · WhatsApp', bothLbl: 'Label · call or WhatsApp', mailLbl: 'Label · email', snapLbl: 'Label · Snapchat', ytLbl: 'Label · YouTube', scanLbl: 'Label · scan', scanSub: 'Under the label',
  consultLbl: 'Headline', consultSub: 'Subline', tapLbl: 'Button', callRow: 'Row label · call', waRow: 'Row label · WhatsApp', whatsapp: 'WhatsApp number', email: 'Email', website: 'Website',
  instagram: 'Handle', tiktok: 'TikTok handle', snapchat: 'Snapchat handle', youtube: 'YouTube channel', qr: 'QR link', countryUr: 'Country (Urdu)', countryHi: 'Country (Hindi)', stampSub: 'Stamp line',
  thanks: 'Thank-you line', waTitle: 'WhatsApp headline', follow: 'Follow line', visit: 'Visit line', scan: 'Scan line', address: 'Address / office', company: 'Company name',
};
const PER_EL = { ticket: { brand: 'Card title', from: 'From (code)', to: 'To (code)' }, counter: { label: 'Label' }, bulletin: { label: 'Top label' }, split: { label: 'Top label' } };
const SERIES = { s: 'Step', g: 'Goal', w: 'Word', p: 'Point', c: 'Card', r: 'Row', l: 'Line', role: 'Role', k: 'Key', d: 'Day' };
export function labelOf(key, el) {
  if (PER_EL[el]?.[key]) return PER_EL[el][key];
  if (LABELS[key]) return LABELS[key];
  let m = /^role(\d)$/.exec(key); if (m) return 'Role ' + (+m[1] + 1);
  m = /^trade_(.+)$/.exec(key); if (m) return 'Trade · ' + m[1][0].toUpperCase() + m[1].slice(1).replace(/[-_]/g, ' ');
  m = /^city(\d)$/.exec(key); if (m) return 'City ' + m[1];
  m = /^([a-z]+?)(\d+)$/.exec(key);
  if (m) return (elementById(el)?.fam && m[1] === 'p' ? 'Panel' : SERIES[m[1]] || m[1][0].toUpperCase() + m[1].slice(1)) + ' ' + m[2];
  const ar = /Ar$/.test(key); const base = key.replace(/Ar$/, '');
  return (LABELS[base] || base.replace(/([A-Z])/g, ' $1').replace(/^./, (x) => x.toUpperCase())) + (ar ? ' (Arabic)' : '');
}

/** "+ Add line": which elements take one, the next free key and its starting words */
export const ADD_LINE = {
  lower: { keys: ['line3'], words: 'Third line', label: '+ Add line' },
  lowerbar: { keys: ['line3'], words: 'Third line', label: '+ Add line' },
  end: { keys: ['extra'], words: 'Your extra line', label: '+ Add line' },
  visa: { keys: ['p5', 'p6', 'p7', 'p8'], words: 'New point: edit me', label: '+ Add point' },
};
export function nextLine(item) {
  const a = ADD_LINE[elOfItem(item)]; if (!a) return null;
  const t = item.style?.text || {}, key = a.keys.find((k) => t[k] == null);
  return key ? { key, words: a.words } : null;
}

const N = (s) => String(s ?? '').trim().toUpperCase();
let CV = null;
/**
 * The fields of an item: drawn at a dozen moments of its own duration on a tiny canvas (art caches untouched), every
 * tx() collected. Called when the inspector builds (on selection), never while typing.
 */
export function textFieldsOf(item, project) {
  const el = elOfItem(item); if (!el || NO_TEXT.has(el)) return [];
  const def = elementById(el), fields = new Map();
  const d = Math.max(0.1, (item.out - item.in) / (item.speed || 1));
  const w = Math.max(1, item.w || project.canvas.w), h = Math.max(1, item.h || project.canvas.h), k = 48 / Math.max(w, h);
  CV ||= (typeof document !== 'undefined' ? document.createElement('canvas') : new OffscreenCanvas(48, 48));
  CV.width = CV.height = 48;
  const c = CV.getContext('2d');
  const times = [...[0.04, 0.12, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 0.97].map((f) => f * d), ...[0.6, 1.0, 1.4, 2.0, 2.6].filter((x) => x < d)].sort((a, b) => a - b);
  setArtProbe(true);
  try {
    for (const t of times) {
      c.setTransform(k, 0, 0, k, 0, 0); c.clearRect(0, 0, w, h);
      try { drawElement(c, el, item, project, { box: { x: 0, y: 0, w, h }, local: t, preview: false, w: project.canvas.w, h: project.canvas.h }, null, false, { fields }); } catch (e) { console.warn('[themes] text fields', el, e); }
    }
  } finally { setArtProbe(false); }
  // a family piece lists every word it has, drawn yet or not
  if (def?.fam) for (const key of def.words || []) if (!fields.has(key)) fields.set(key, { key, def: '', user: item.style?.text?.[key] != null, val: item.style?.text?.[key] ?? '', shown: '' });
  // added lines last; an Arabic word straight after its English one
  const late = (f) => (/^(line3|extra)$/.test(f.key) || (el === 'visa' && /^p[5-8]$/.test(f.key)) ? 1 : 0);
  const sorted = [...fields.values()].sort((a, b) => late(a) - late(b)), list = [];
  for (const f of sorted) {
    if (/Ar$/.test(f.key) && fields.has(f.key.replace(/Ar$/, ''))) continue;
    list.push(f); if (fields.has(f.key + 'Ar')) list.push(fields.get(f.key + 'Ar'));
  }
  return list.map((f) => ({
    key: f.key, label: labelOf(f.key, el), def: f.def, user: f.user, ar: /Ar$/.test(f.key) || /[؀-ۿ]/.test(f.def),
    // what the field shows: the owner's words, else what is on the canvas (in its capitals), else the default
    value: f.user ? f.val : f.shown && N(f.shown) === N(f.def) ? f.shown : f.def,
    pair: /Ar$/.test(f.key) && fields.has(f.key.replace(/Ar$/, '')) ? f.key.replace(/Ar$/, '') : null,
  }));
}
