// KBB Studio · Designs · the BRIEF READER (rules; pure, no DOM). A brief in his own words, English or Arabic, free form
// -> a structured spec the design engine uses. The local language model (llm.js) and Claude (optional) produce the same
// shape; `validateSpec` checks theirs against the catalogue, and anything they get wrong or leave out comes from here.
//
// spec = { v, company, industry, kind, occasion, offer, products, brands, missingBrands, countries, visa, intake,
//          occupations, audience, tone, languages, sizes, cta:{en,ar}, deadline, notes, brief, source, found:[...] }
import { OCCASIONS, TONES } from './occasions.js';

const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩', FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
export const westernDigits = (s) => String(s || '').replace(/[٠-٩۰-۹]/g, (d) => String(AR_DIGITS.indexOf(d) >= 0 ? AR_DIGITS.indexOf(d) : FA_DIGITS.indexOf(d))).replace(/٪/g, '%');
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
// a whole word/phrase (Latin: word boundaries; Arabic: optional ال / و / ب / ل prefixes)
export const arNorm = (s) => String(s || '').replace(/[ً-ْٰـ]/g, '').replace(/[أإآ]/g, 'ا');
function has(text, w) {
  if (/[؀-ۿ]/.test(w)) { w = arNorm(w); text = arNorm(text); }
  if (/[؀-ۿ]/.test(w)) return new RegExp('(^|[\\s،,.!؟:;()\\-])(?:و|ب|ل|ال|وال|بال|لل)?' + esc(w.replace(/^ال/, '')) + '', 'u').test(text) || text.includes(w);
  return new RegExp('(^|[^a-z0-9])' + esc(w) + '(?=$|[^a-z0-9])', 'i').test(text);
}

/** brands the owner writes in short (and their Arabic names) */
export const BRAND_ALIASES = {
  'Beauty of Joseon': ['boj', 'beauty of joseon', 'joseon', 'بيوتي أوف جوسون', 'جوسون'],
  Anua: ['anua', 'أنوا', 'انوا'],
  medicube: ['medicube', 'medi cube', 'ميديكيوب'],
  "d'Alba": ["d'alba", 'dalba', 'دالبا'],
  'Mary&May': ['mary&may', 'mary & may', 'mary and may', 'ماري اند ماي'],
  celimax: ['celimax', 'سيليماكس'],
  eos: ['eos'],
  Biodance: ['biodance', 'bio dance', 'بايودانس'],
  numbuzin: ['numbuzin', 'نمبوزين'],
  COSRX: ['cosrx', 'كوسركس'],
  'Round Lab': ['round lab', 'roundlab'],
  SKIN1004: ['skin1004', 'skin 1004'],
  Torriden: ['torriden'],
  'Some By Mi': ['some by mi', 'somebymi'],
  Laneige: ['laneige', 'لانيج'],
  Innisfree: ['innisfree'],
  "I'm From": ["i'm from", 'im from'],
  Isntree: ['isntree'],
};

const ADJ = { gb: ['uk', 'britain', 'british', 'england', 'english universities', 'بريطانيا'], us: ['usa', 'america', 'american', 'أمريكا'], de: ['german', 'ألمانيا'], fr: ['french', 'فرنسا'], nl: ['dutch', 'holland', 'هولندا'], it: ['italian', 'إيطاليا'], es: ['spanish', 'إسبانيا'], pt: ['portuguese', 'البرتغال'], pl: ['polish', 'بولندا'], mt: ['maltese', 'مالطا'], hu: ['hungarian', 'المجر'], cz: ['czech', 'czech republic', 'التشيك'], lt: ['lithuanian', 'ليتوانيا'], lv: ['latvian', 'لاتفيا'], se: ['swedish', 'السويد'], fi: ['finnish', 'فنلندا'], dk: ['danish', 'الدنمارك'], at: ['austrian', 'النمسا'], be: ['belgian', 'بلجيكا'], cy: ['cypriot', 'قبرص'], gr: ['greek', 'اليونان'], ch: ['swiss', 'سويسرا'], ae: ['uae', 'emirates', 'dubai', 'abu dhabi', 'الإمارات'], jp: ['japanese', 'اليابان'], kr: ['korean', 'korea', 'كوريا'], sg: ['singaporean', 'سنغافورة'], my: ['malaysian', 'ماليزيا'], tr: ['turkey', 'turkish', 'تركيا'], ee: ['estonian', 'إستونيا'], ca: ['canadian', 'كندا'], au: ['australian', 'aus', 'أستراليا'], nz: ['kiwi', 'نيوزيلندا'], ie: ['irish', 'أيرلندا'] };
const MONTHS = [['Jan', 'january', 'يناير'], ['Feb', 'february', 'فبراير'], ['Mar', 'march', 'مارس'], ['Apr', 'april', 'أبريل'], ['May', 'may', 'مايو'], ['Jun', 'june', 'يونيو'], ['Jul', 'july', 'يوليو'], ['Aug', 'august', 'أغسطس'], ['Sep', 'september', 'سبتمبر'], ['Oct', 'october', 'أكتوبر'], ['Nov', 'november', 'نوفمبر'], ['Dec', 'december', 'ديسمبر']];
const MONTH_AR = Object.fromEntries(MONTHS.map((m) => [m[0], m[2]]));

export const SIZES = { story: { w: 1080, h: 1920, label: 'Story 9:16' }, post: { w: 1080, h: 1350, label: 'Post 4:5' }, square: { w: 1080, h: 1080, label: 'Square 1:1' } };

/** the catalogue the reader matches against: {products:[{id,brand,name,keywords}], countries:[{code,name,nameAr}], occupations:[{id,name:{en,ar},plural,keywords}]} */
export function parseBrief(text, ws = {}, cat = {}) {
  const raw = String(text || '').trim();
  const t = westernDigits(raw).toLowerCase().replace(/[’`]/g, "'");
  const isAr = /[؀-ۿ]/.test(raw) && (raw.match(/[؀-ۿ]/g) || []).length > raw.replace(/\s/g, '').length * 0.4;
  const found = [];
  const note = (k, v) => found.push([k, v]);
  const industry = industryOf(ws);
  const spec = { v: 1, company: ws.id || '', companyName: ws.fullName || ws.name || '', industry, kind: 'generic', occasion: null, offer: null, products: [], brands: [], missingBrands: [], countries: [], visa: null, intake: null, occupations: [], audience: null, tone: null, languages: [], sizes: [], cta: null, deadline: null, notes: [], brief: raw, briefLang: isAr ? 'ar' : 'en', source: 'rules', found };

  // occasion (longest, most specific first: "eid al adha" before "eid")
  for (const o of OCCASIONS) { const w = o.words.find((x) => has(t, x)); if (w) { spec.occasion = { id: o.id, en: o.en, ar: o.ar, colors: o.colors.slice(), mood: o.mood, art: o.art, icon: o.icon, country: o.country || null }; note('occasion', o.en); break; } }

  // offer
  let m;
  if ((m = /(?:up to|upto|حتى)?\s*(\d{1,2})\s*%\s*(?:off|discount|خصم)?|(?:خصم|تخفيض)\s*(\d{1,2})\s*%/.exec(t))) {
    const v = +(m[1] || m[2]), upTo = /(up to|upto|حتى)\s*\d/.test(m[0]);
    spec.offer = { type: 'percent', value: v, upTo, en: `${upTo ? 'Up to ' : ''}${v}% OFF`, ar: `${upTo ? 'حتى ' : ''}خصم ${v}%` };
  } else if ((m = /(?:aed|dhs?|درهم)\s*(\d{1,5})\s*(?:off)?|(\d{1,5})\s*(?:aed|dhs?|درهم)\s*(off|خصم)?/.exec(t)) && /off|خصم|discount|save|وفر/.test(t)) {
    const v = +(m[1] || m[2]);
    spec.offer = { type: 'amount', value: v, en: `AED ${v} OFF`, ar: `خصم ${v} درهم` };
  } else if (/\b(bogo|buy 1 get 1|buy one get one|1\+1)\b|اشتر[يِ]? واحد/.test(t)) spec.offer = { type: 'bogo', en: 'Buy 1 Get 1 Free', ar: 'اشترِ ١ واحصلي على ١ مجاناً' };
  else if (/free (delivery|shipping)|توصيل مجاني/.test(t)) spec.offer = { type: 'free-delivery', en: 'Free Delivery', ar: 'توصيل مجاني' };
  else if (/free gift|gift with|هدية مجانية|هدية مع/.test(t)) spec.offer = { type: 'gift', en: 'Free Gift With Every Order', ar: 'هدية مجانية مع كل طلب' };
  if (spec.offer) note('offer', spec.offer.en);
  if (/(code|كود)\s*[:\-]?\s*([a-z0-9]{3,16})/i.test(raw)) { spec.offer = spec.offer || {}; spec.offer.code = /(?:code|كود)\s*[:\-]?\s*([A-Za-z0-9]{3,16})/i.exec(raw)[1].toUpperCase(); }

  // brands + products from the catalogue
  const prods = cat.products || [];
  const catBrands = [...new Set(prods.map((p) => p.brand))];
  const brandHits = new Set();
  for (const [brand, al] of Object.entries(BRAND_ALIASES)) if (al.some((a) => has(t, a))) brandHits.add(brand);
  for (const b of catBrands) if (has(t, b.toLowerCase())) brandHits.add(BRAND_ALIASES[b] ? b : catBrands.find((x) => x.toLowerCase() === b.toLowerCase()) || b);
  const pos = (b) => { const al = [b.toLowerCase(), ...(BRAND_ALIASES[b] || [])].map((a) => (/[\u0600-\u06FF]/.test(a) ? arNorm(t).indexOf(arNorm(a)) : t.indexOf(a))).filter((i) => i >= 0); return al.length ? Math.min(...al) : 1e9; };
  for (const b of [...brandHits].sort((x, y) => pos(x) - pos(y))) {
    const mine = prods.filter((p) => p.brand.toLowerCase() === b.toLowerCase());
    spec.brands.push(b);
    if (!mine.length) spec.missingBrands.push(b);
  }
  // named products ("PDRN serum", "heartleaf mist") win; else every product of the named brands
  const named = prods.filter((p) => { const n = p.name.toLowerCase(); return has(t, n) || (p.keywords || []).filter((k) => k.length > 3 && !catBrands.some((b) => b.toLowerCase() === k) && has(t, k)).length >= 2; });
  spec.products = (named.length ? named : prods.filter((p) => spec.brands.some((b) => b.toLowerCase() === p.brand.toLowerCase()))).map((p) => p.id);
  if (spec.brands.length) note('brands', spec.brands.join(', '));
  if (spec.missingBrands.length) spec.notes.push(`No product photo for ${spec.missingBrands.join(', ')} in the catalogue yet: the brand is named in the copy; add a cut-out in brands/kbb/products to show it.`);

  // countries, visa, intake, occupations (consultancy)
  for (const c of cat.countries || []) {
    const words = [c.name.toLowerCase(), c.code === 'gb' ? 'united kingdom' : null, c.nameAr, ...(ADJ[c.code] || [])].filter(Boolean);
    if (words.some((w) => has(t, w))) spec.countries.push(c.code);
  }
  if (spec.occasion?.country && spec.countries.length === 1 && spec.countries[0] === spec.occasion.country && industry !== 'consultancy') spec.countries = [];
  if (spec.countries.length) note('countries', spec.countries.join(', '));
  if (/\b(study|student|university|universities|college|bachelor|master'?s?|degree|scholarship|intake|admission)\b|دراسة|الدراسة|جامعة|منحة|طلاب/.test(t)) spec.visa = 'study';
  if (/\b(work|job|jobs|hiring|vacanc(y|ies)|recruit\w*|employment|workers?|wanted|work permit)\b|عمل|وظائف|وظيفة|مطلوب|توظيف/.test(t)) spec.visa = spec.visa === 'study' && !/\bwork permit|hiring|wanted|vacanc/.test(t) ? 'study' : 'work';
  if (/\b(pr|permanent residen\w*|immigrat\w*|migrate)\b|هجرة|إقامة دائمة/.test(t)) spec.visa = spec.visa || 'pr';
  if (/\b(visit visa|tourist|visit)\b|زيارة|سياحية/.test(t) && !spec.visa) spec.visa = 'visit';
  for (const [abbr, full, ar] of MONTHS) {
    const re = new RegExp(`\\b(${abbr.toLowerCase()}|${full})\\b(\\s*(20\\d\\d))?`);
    const mm = re.exec(t) || (t.includes(ar) ? [ar] : null);
    if (mm && (spec.visa === 'study' || /intake|deadline|start|موعد|دفعة/.test(t))) { spec.intake = abbr + (mm[3] ? ' ' + mm[3] : ''); spec.intakeAr = MONTH_AR[abbr] + (mm[3] ? ' ' + mm[3] : ''); break; }
  }
  for (const o of cat.occupations || []) {
    const words = [o.name?.en, o.plural?.en, o.name?.ar, o.plural?.ar, o.title?.en].filter(Boolean).map((w) => w.toLowerCase());
    if (words.some((w) => w.length > 2 && has(t, w))) spec.occupations.push(o.id);
  }
  if (spec.occupations.length) { spec.visa = spec.visa || 'work'; note('occupations', spec.occupations.join(', ')); }
  if (spec.visa) note('visa', spec.visa);

  // kind
  spec.kind = spec.occupations.length || /hiring|wanted|vacanc|recruit|مطلوب|توظيف/.test(t) ? 'hiring'
    : spec.visa === 'study' ? 'study' : spec.visa ? 'visa'
      : spec.offer ? 'offer' : /new arrival|launch|just landed|now available|جديد|وصل حديثاً/.test(t) ? 'launch'
        : /webinar|seminar|event|workshop|open day|ندوة|فعالية/.test(t) ? 'event' : spec.occasion ? 'offer' : spec.products.length ? 'launch' : 'brand';
  if (spec.kind === 'offer' && !spec.offer && spec.occasion) spec.offer = null;

  // audience, tone
  const AUD = [['women', /\b(women|woman|ladies|her|girls|mums?|moms?)\b|سيدات|نساء|بنات/], ['men', /\b(men|man|him|guys)\b|رجال/], ['students', /\bstudents?\b|طلاب|الطلبة/], ['workers', /\bworkers?|skilled|labou?r\b|عمال/], ['families', /\bfamil(y|ies)\b|عائلات|أسر/], ['parents', /\bparents?\b|أولياء الأمور/]];
  for (const [a, re] of AUD) if (re.test(t)) { spec.audience = a; break; }
  if (!spec.audience) spec.audience = industry === 'beauty' ? 'women' : spec.kind === 'hiring' ? 'workers' : spec.kind === 'study' ? 'students' : null;
  for (const [tone, words] of Object.entries(TONES)) if (words.some((w) => has(t, w))) { spec.tone = tone; break; }
  if (!spec.tone) spec.tone = spec.kind === 'hiring' ? 'bold' : spec.kind === 'study' || spec.kind === 'visa' ? 'professional' : spec.occasion?.mood === 'proud' ? 'luxury' : industry === 'beauty' ? (spec.offer ? 'luxury' : 'calm') : 'professional';
  if (/\b(deadline|ends?|until|till|last day)\b|ينتهي|حتى تاريخ/.test(t)) { const d = /(\d{1,2}\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\w*|(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\w*\s*\d{1,2})/.exec(t); if (d) spec.deadline = d[0]; }

  // languages
  const wantsAr = /\barabic\b|\bar\b|bilingual|\ben\s*\/\s*ar\b|عربي|بالعربية|ثنائي/.test(t) || isAr;
  const onlyEn = /english only|no arabic|en only/.test(t), onlyAr = /arabic only|ar only|عربي فقط|بالعربية فقط/.test(t);
  spec.languages = onlyAr ? ['ar'] : onlyEn ? ['en'] : isAr ? ['ar', 'en'] : wantsAr ? ['en', 'ar'] : ['en', 'ar'];
  // sizes
  if (/\b(stor(y|ies)|reels?|9\s*[:x]\s*16|status|tiktok)\b|ستوري|قصة/.test(t)) spec.sizes.push('story');
  if (/\b(posts?|feed|4\s*[:x]\s*5|portrait)\b|منشور|بوست/.test(t)) spec.sizes.push('post');
  if (/\b(square|1\s*[:x]\s*1)\b|مربع/.test(t)) spec.sizes.push('square');
  if (!spec.sizes.length) spec.sizes = ['story', 'post', 'square'];

  // CTA: written ("CTA: …" / "call to action …") or the kind's default
  const ctaM = /(?:cta|call to action|button)\s*[:\-]\s*["“]?([^"\n”.;]{3,40})/i.exec(raw);
  spec.cta = ctaM ? { en: ctaM[1].trim(), ar: defaultCta(spec, ws).ar } : defaultCta(spec, ws);
  if (/whatsapp|واتساب|واتس/.test(t)) spec.cta = spec.kind === 'hiring' ? { en: 'Apply on WhatsApp', ar: 'قدّم عبر واتساب' } : { en: 'Message us on WhatsApp', ar: 'راسلنا على واتساب' };
  spec.confidence = Math.min(1, found.length / 4);
  return spec;
}

export function industryOf(ws) {
  const s = `${ws?.id || ''} ${ws?.industry || ''} ${(ws?.industries || []).join(' ')}`.toLowerCase();
  if (/beaut|skin|cosmetic|kbb/.test(s)) return 'beauty';
  if (/immigra|visa|consult|study|educat|planet/.test(s)) return 'consultancy';
  if (/restaurant|cafe|food/.test(s)) return 'food';
  if (/estate/.test(s)) return 'realestate';
  if (/fitness|gym/.test(s)) return 'fitness';
  return 'generic';
}

export function defaultCta(spec, ws) {
  const ec = ws?.endCard || {};
  if (spec.kind === 'hiring') return { en: 'Apply now', ar: 'قدّم الآن' };
  if (spec.kind === 'study' || spec.kind === 'visa') return { en: 'Book a free consultation', ar: 'احجز استشارة مجانية' };
  if (spec.kind === 'event') return { en: 'Register now', ar: 'سجّل الآن' };
  if (spec.industry === 'beauty') return { en: spec.offer ? 'Shop the offer' : 'Shop now', ar: ec.ctaAr && !/<edit/.test(ec.ctaAr) ? ec.ctaAr : 'تسوقي الآن' };
  return { en: (ec.cta && !/<edit/.test(ec.cta) ? ec.cta : 'Learn more'), ar: 'اعرف المزيد' };
}

const KINDS = ['offer', 'launch', 'hiring', 'study', 'visa', 'event', 'brand', 'generic'];
const TONE_LIST = Object.keys(TONES);
const clipS = (s, n) => (typeof s === 'string' ? s.replace(/[\u0000-\u001f]/g, ' ').trim().slice(0, n) : '');

/**
 * Merge a model's reading (local LLM or Claude) INTO the rule reading: every field checked against the catalogue and the
 * known lists; ids that are not real are dropped; numbers the brief does not contain are refused (no invented
 * discounts); copy lines are kept as copy suggestions (length-limited, no "guarantee"). Returns {spec, accepted, refused}.
 */
export function validateSpec(base, model, cat = {}) {
  const spec = structuredClone(base), accepted = [], refused = [];
  if (!model || typeof model !== 'object') return { spec, accepted, refused };
  const briefDigits = new Set((westernDigits(base.brief).match(/\d+/g) || []));
  const ok = (field, v) => { accepted.push(field); return v; };
  if (KINDS.includes(model.kind) && (base.kind === 'generic' || base.kind === 'brand')) spec.kind = ok('kind', model.kind);
  if (TONE_LIST.includes(model.tone) && !base.found.some(([k]) => k === 'tone')) spec.tone = ok('tone', model.tone);
  const prodIds = new Set((cat.products || []).map((p) => p.id));
  const mp = (Array.isArray(model.products) ? model.products : []).filter((id) => { const good = prodIds.has(id); if (!good) refused.push('product ' + id); return good; });
  if (mp.length && !base.products.length) spec.products = ok('products', mp.slice(0, 8));
  const cc = new Set((cat.countries || []).map((c) => c.code));
  const mc = (Array.isArray(model.countries) ? model.countries : []).map((x) => String(x).toLowerCase()).filter((x) => { const g = cc.has(x); if (!g) refused.push('country ' + x); return g; });
  if (mc.length && !base.countries.length) spec.countries = ok('countries', mc.slice(0, 4));
  const oc = new Set((cat.occupations || []).map((o) => o.id));
  const mo = (Array.isArray(model.occupations) ? model.occupations : []).filter((x) => { const g = oc.has(x); if (!g) refused.push('occupation ' + x); return g; });
  if (mo.length && !base.occupations.length) spec.occupations = ok('occupations', mo.slice(0, 3));
  if (!base.audience && clipS(model.audience, 40)) spec.audience = ok('audience', clipS(model.audience, 40));
  if (!base.occasion && model.occasion) { const o = OCCASIONS.find((x) => x.id === model.occasion || x.en.toLowerCase() === String(model.occasion).toLowerCase()); if (o) spec.occasion = ok('occasion', { id: o.id, en: o.en, ar: o.ar, colors: o.colors.slice(), mood: o.mood, art: o.art, icon: o.icon, country: o.country || null }); }
  // copy: honest by construction
  const copy = (Array.isArray(model.copy) ? model.copy : []).map((c) => ({ lang: c.lang === 'ar' ? 'ar' : 'en', headline: clipS(c.headline, 60), offer: clipS(c.offer, 60), cta: clipS(c.cta, 32) }))
    .filter((c) => {
      const all = `${c.headline} ${c.offer} ${c.cta}`;
      if (!c.headline) return false;
      if (/guarante|100\s*%|ضمان|مضمون|cure|whiten/i.test(all)) { refused.push('copy with a promise: ' + c.headline); return false; }
      const nums = westernDigits(all).match(/\d+/g) || [];
      if (nums.some((n) => !briefDigits.has(n))) { refused.push('copy with a number the brief does not have: ' + all); return false; }
      return true;
    });
  if (copy.length) spec.copySuggestions = ok('copy', copy.slice(0, 8));
  spec.source = base.source === 'rules' ? 'rules+model' : base.source;
  return { spec, accepted, refused };
}
