// KBB Studio · Designs · BROADCAST AD TEMPLATES (2026-11, the owner: "i also need it with like ad designs etc."): the
// Ad Studio's templates rebuilt to the TV-commercial standard of the approved themes. Each template is a full-frame
// composition made of the themes' art (broadcast.js: waving flags, landmarks, scenes, the globe, passport stamps,
// boarding passes, job-role badges, colour slabs, crawls) with every word a live, editable text element in English or
// Arabic (right to left: the whole composition mirrors), laid out for 1:1, 4:5, 9:16 and 16:9.
//
// A template returns the SAME element ids for every size (layouts.js's idea), so one design has one set of words and a
// position per size; it goes through the same renderer, rules, editor, save and export as every other design. Facts are
// placeholders only (‹edit›, XX, DD MMM): never a visa rule, fee, salary, approval rate or a medical claim. Contact,
// button wording and logo come from the company's ⚙ Configure details; photo slots too, when he has set them.
//
//   AD_TEMPLATES                       the catalogue (id, company, name, nameAr, slides for a carousel)
//   adTemplatesFor(ws | spec)          the templates that fit a company / a brief, best first
//   makeAd(id, ws, cat, opts)          -> D   (opts: {lang, sizes, cc, ccs, spec, copy, palette, slide, seed})
//   makeCarousel(id, ws, cat, opts)    -> [D, ...]  slides that read as one continuous design
import { SAFE, G, stack } from './layouts.js';
import { PX } from './render.js';
import { scheme } from './lib/palette.js';
import { withL, mix, lum, norm, hsl } from './lib/color.js';
import { wordsFor } from './broadcast.js';
import { brandOf, fontsFor } from './generate.js';
import { motionOf } from './motion.js';

export const AD_SIZES = ['story', 'post', 'square', 'wide'];
const uid = () => 'a' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
const PH = { money: '‹edit›', date: 'DD MMM', dateAr: 'DD MMM' };

// ── what a company's details give every ad (⚙ Configure → Contact & social, Companies) ──
export function contactOf(ws = {}, lang = 'en') {
  const ci = ws.contactInfo || {}, so = ws.social || {};
  const web = ci.website || (/([a-z0-9-]+\.)+[a-z]{2,}/i.exec(ws.contact || '') || [])[0] || 'yourwebsite.com';
  const wa = ci.whatsapp || ci.phone || '+971 XX XXX XXXX';
  const ig = so.instagram ? '@' + String(so.instagram).replace(/^@/, '') : '';
  // in Arabic the number and the address are isolated left-to-right runs (U+2066 … U+2069), so they keep their order
  return { web, wa, ig, line: lang === 'ar' ? `واتساب \u2066${wa}\u2069  ·  \u2066${web}\u2069` : `WhatsApp ${wa}  ·  ${web}` };
}
const ctaOf = (ws, lang, d) => (lang === 'ar' ? ws.endCard?.ctaAr : ws.endCard?.cta) || d;
const photoOf = (ws, k) => ws.adPhotos?.[k] || null;

// ── the broadcast colour schemes, from the company's kit (every one contrast-checked by scheme()) ──
export function broadcastPalettes(ws = {}) {
  const c = ws.colors || {};
  const P = norm(c.primary, '#1D3557'), S = norm(c.secondary, P), A = norm(c.accent, S);
  const l = hsl(P)[2];
  const acc = lum(S) > 0.03 && S !== P ? S : P, acc2 = A !== acc ? A : '#FFD166';
  const deep = scheme('bc-deep', 'Broadcast · deep', { bg: withL(P, Math.min(0.13, l)), bg2: withL(P, Math.min(0.24, l + 0.04)), ink: '#FFFFFF', acc: lum(acc) < 0.03 ? A : acc, acc2, card: '#FFFFFF', deco: [acc, acc2, P] });
  const soft = norm(c.soft, mix(P, '#FFFFFF', 0.92));
  const light = scheme('bc-light', 'Broadcast · light', { bg: soft, bg2: mix(soft, P, 0.14), ink: lum(P) < 0.1 ? P : withL(P, 0.18), acc: P, acc2: acc, card: '#FFFFFF', deco: [P, acc2] });
  return { deep, light };
}

// ── the catalogue ──
const PC = 'planet-careers', KBB = 'kbb';
export const AD_TEMPLATES = [
  { id: 'pc-hiring', co: PC, kinds: ['hiring'], name: 'Country hiring poster', nameAr: 'إعلان توظيف', cc: 'de', pal: 'deep' },
  { id: 'pc-stamp', co: PC, kinds: ['visa', 'pr', 'visit'], name: 'Visa stamp', nameAr: 'ختم التأشيرة', cc: 'ca', pal: 'deep' },
  { id: 'pc-ticket', co: PC, kinds: ['hiring', 'visit'], name: 'Boarding pass · Fly to work', nameAr: 'بطاقة صعود', cc: 'gb', pal: 'deep' },
  { id: 'pc-bulletin', co: PC, kinds: ['visa', 'study', 'pr'], name: 'Visa update bulletin', nameAr: 'نشرة التأشيرات', cc: 'au', pal: 'deep' },
  { id: 'pc-split', co: PC, kinds: ['hiring'], name: 'Worker photo + country', nameAr: 'صورة وبلد', cc: 'pl', pal: 'deep' },
  { id: 'pc-study', co: PC, kinds: ['study'], name: 'Study postcard', nameAr: 'بطاقة الدراسة', cc: 'ie', pal: 'light' },
  { id: 'pc-europe', co: PC, kinds: ['hiring'], name: 'Europe is hiring grid', nameAr: 'أوروبا توظّف', ccs: ['de', 'pl', 'nl', 'it'], pal: 'deep' },
  { id: 'pc-story', co: PC, kinds: ['hiring', 'study', 'visa'], name: 'Success story', nameAr: 'قصة نجاح', cc: 'gb', pal: 'deep' },
  { id: 'pc-event', co: PC, kinds: ['event', 'study'], name: 'Seminar announcement', nameAr: 'إعلان ندوة', cc: 'ae', pal: 'deep' },
  { id: 'pc-carousel', co: PC, kinds: ['hiring'], name: 'Carousel · Europe is hiring', nameAr: 'كاروسيل · أوروبا توظّف', ccs: ['de', 'pl', 'nl'], slides: 4, pal: 'deep' },
  { id: 'kbb-hero', co: KBB, kinds: ['launch', 'brand', 'generic'], name: 'Product hero', nameAr: 'المنتج البطل', pal: 'deep' },
  { id: 'kbb-offer', co: KBB, kinds: ['offer'], name: 'Sale burst', nameAr: 'عرض التخفيض', pal: 'deep' },
  { id: 'kbb-new', co: KBB, kinds: ['launch'], name: 'New arrival', nameAr: 'وصل حديثاً', pal: 'light' },
  { id: 'kbb-routine', co: KBB, kinds: ['brand', 'generic', 'launch'], name: 'Routine steps', nameAr: 'خطوات الروتين', pal: 'light' },
  { id: 'kbb-tips', co: KBB, kinds: ['brand', 'generic'], name: 'Before / after tips', nameAr: 'نصائح قبل وبعد', pal: 'deep' },
  { id: 'kbb-bundle', co: KBB, kinds: ['offer', 'brand'], name: 'Bundle', nameAr: 'باقة', pal: 'light' },
  { id: 'kbb-carousel', co: KBB, kinds: ['offer', 'launch', 'brand'], name: 'Carousel · routine', nameAr: 'كاروسيل · الروتين', slides: 4, pal: 'deep' },
];
export const AD_NAMES = Object.fromEntries(AD_TEMPLATES.map((t) => [t.id, t.name]));
export const adById = (id) => AD_TEMPLATES.find((t) => t.id === id) || null;
const isPC = (ws) => /planet|consult|visa|immigra/i.test((ws?.id || '') + ' ' + (ws?.industry || ''));
/** the templates that fit a company (and a brief's kind, best first) */
export function adTemplatesFor(ws, spec = null) {
  const co = isPC(ws) ? PC : KBB, list = AD_TEMPLATES.filter((t) => t.co === co);
  if (!spec) return list;
  const k = spec.kind, fit = list.filter((t) => t.kinds.includes(k) && !t.slides), rest = list.filter((t) => !fit.includes(t) && !t.slides);
  return [...fit, ...rest];
}

// ── default words per template (English, Arabic). Country words fill {c} / {ca}. Placeholders, never facts. ──
const W = {
  'pc-hiring': { kicker: ['JOBS IN {C}', 'وظائف في {ca}'], headline: ["WE'RE HIRING", 'نحن نوظّف'], sub: ['Skilled workers wanted for {c}', 'مطلوب عمال مهرة للعمل في {ca}'], cta: ['Apply on WhatsApp', 'قدّم عبر واتساب'], trust: ['Details are placeholders · requirements confirmed at consultation', 'التفاصيل للتعديل · تُؤكَّد المتطلبات عند الاستشارة'] },
  'pc-stamp': { kicker: ['VISA GUIDANCE · {C}', 'إرشاد التأشيرات · {ca}'], headline: ['FROM APPLICATION TO STAMP', 'من الطلب حتى الختم'], sub: ['Step-by-step visa guidance for {c}', 'إرشاد خطوة بخطوة لتأشيرة {ca}'], cta: ['Book a free consultation', 'احجز استشارة مجانية'], trust: ['Illustration only · every case is assessed individually', 'صورة توضيحية فقط · تُقيَّم كل حالة على حدة'] },
  'pc-ticket': { kicker: ['WORK ABROAD', 'العمل في الخارج'], headline: ['FLY TO WORK IN {C}', 'سافر للعمل في {ca}'], sub: ['Jobs ‹edit› · Start date ‹edit›', 'الوظائف ‹عدّل› · تاريخ البدء ‹عدّل›'], cta: ['Apply on WhatsApp', 'قدّم عبر واتساب'], trust: ['Placeholder details · confirmed at consultation', 'تفاصيل للتعديل · تُؤكَّد عند الاستشارة'] },
  'pc-bulletin': { kicker: ['VISA UPDATE', 'تحديث التأشيرات'], headline: ['{C}', '{ca}'], date: ['DD MMM YYYY', 'DD MMM YYYY'], p1: ['Update one: edit before publishing', 'التحديث الأول: عدّله قبل النشر'], p2: ['Update two: edit before publishing', 'التحديث الثاني: عدّله قبل النشر'], p3: ['Update three: edit before publishing', 'التحديث الثالث: عدّله قبل النشر'], cta: ['Ask us on WhatsApp', 'اسألنا عبر واتساب'], trust: ['Verify on the official government website before publishing', 'تحقق من الموقع الرسمي للحكومة قبل النشر'] },
  'pc-split': { kicker: ['WORK IN {C}', 'اعمل في {ca}'], headline: ['BUILD YOUR CAREER ABROAD', 'ابنِ مسيرتك في الخارج'], sub: ['Roles ‹edit› · Contract ‹edit›', 'الوظائف ‹عدّل› · العقد ‹عدّل›'], cta: ['Apply on WhatsApp', 'قدّم عبر واتساب'], trust: ['Placeholder details · confirmed at consultation', 'تفاصيل للتعديل · تُؤكَّد عند الاستشارة'] },
  'pc-study': { kicker: ['ADMISSIONS OPEN', 'التسجيل مفتوح'], headline: ['STUDY IN {C}', 'ادرس في {ca}'], sub: ['Bachelor & master guidance · intake ‹edit›', 'إرشاد البكالوريوس والماجستير · الدفعة ‹عدّل›'], cta: ['Book a free consultation', 'احجز استشارة مجانية'], trust: ['Requirements confirmed at consultation', 'تُؤكَّد المتطلبات عند الاستشارة'] },
  'pc-europe': { kicker: ['JOBS ABROAD', 'وظائف في الخارج'], headline: ['EUROPE IS HIRING', 'أوروبا توظّف'], sub: ['Roles ‹edit› in four countries', 'وظائف ‹عدّل› في أربع دول'], cta: ['Apply on WhatsApp', 'قدّم عبر واتساب'], trust: ['Placeholder details · confirmed at consultation', 'تفاصيل للتعديل · تُؤكَّد عند الاستشارة'] },
  'pc-story': { kicker: ['SUCCESS STORY', 'قصة نجاح'], headline: ['“Your client’s words go here, with their permission.”', '«كلمات عميلك هنا، بإذنه.»'], name: ['Client name', 'اسم العميل'], detail: ['Now in {c} · ‹role›', 'الآن في {ca} · ‹الوظيفة›'], cta: ['Start your journey', 'ابدأ رحلتك'], trust: ['Shared with the client’s permission', 'تُنشر بإذن العميل'] },
  'pc-event': { kicker: ['FREE SEMINAR', 'ندوة مجانية'], headline: ['STUDY & WORK ABROAD SEMINAR', 'ندوة الدراسة والعمل في الخارج'], day: ['DD', 'DD'], month: ['MMM', 'MMM'], sub: ['Venue ‹edit›  ·  Time ‹edit›', 'المكان ‹عدّل›  ·  الوقت ‹عدّل›'], cta: ['Register free', 'سجّل مجاناً'], trust: ['Seats limited · details to confirm', 'المقاعد محدودة · التفاصيل للتأكيد'] },
  'pc-carousel': { kicker: ['SWIPE · JOBS ABROAD', 'اسحب · وظائف في الخارج'], headline: ['EUROPE IS HIRING', 'أوروبا توظّف'], sub: ['Roles ‹edit› · details at consultation', 'الوظائف ‹عدّل› · التفاصيل عند الاستشارة'], cta: ['Apply on WhatsApp', 'قدّم عبر واتساب'], trust: ['Placeholder details · confirmed at consultation', 'تفاصيل للتعديل · تُؤكَّد عند الاستشارة'] },
  'kbb-hero': { kicker: ['{B}', '{B}'], headline: ['MEET YOUR NEW FAVOURITE', 'مفضّلتك الجديدة'], sub: ['{P}', '{P}'], cta: ['Shop now', 'تسوّقي الآن'], trust: ['Authentic Korean skincare · UAE delivery', 'منتجات كورية أصلية · توصيل في الإمارات'] },
  'kbb-offer': { kicker: ['LIMITED TIME', 'لفترة محدودة'], headline: ['THE GLOW SALE', 'تخفيضات الإشراقة'], big: ['XX%', 'XX%'], bigLabel: ['OFF', 'خصم'], cta: ['Shop the offer', 'تسوّقي العرض'], trust: ['Selected products · while stocks last', 'منتجات مختارة · حتى نفاد الكمية'] },
  'kbb-new': { kicker: ['NEW ARRIVAL', 'وصل حديثاً'], headline: ['JUST LANDED', 'جديدنا وصل'], sub: ['{B} {P}', '{B} {P}'], cta: ['Shop now', 'تسوّقي الآن'], trust: ['Authentic Korean skincare · UAE delivery', 'منتجات كورية أصلية · توصيل في الإمارات'] },
  'kbb-routine': { kicker: ['K-BEAUTY ROUTINE', 'روتين العناية الكوري'], headline: ['YOUR GLOW ROUTINE', 'روتين إشراقتك'], s1: ['Cleanse', 'تنظيف'], s2: ['Tone', 'تونر'], s3: ['Moisturise', 'ترطيب'], s4: ['Sunscreen (AM)', 'واقي الشمس (صباحاً)'], cta: ['Shop the routine', 'تسوّقي الروتين'], trust: ['General tips · not medical advice', 'نصائح عامة · ليست نصيحة طبية'] },
  'kbb-tips': { kicker: ['ROUTINE TIPS', 'نصائح الروتين'], headline: ['LAYER IT RIGHT', 'رتّبي طبقاتك'], before: ['RUSHED', 'على عجل'], after: ['LAYERED', 'بطبقات'], t1: ['Thinnest texture first', 'الأخف قواماً أولاً'], t2: ['Pat in, don’t rub', 'ربّتي ولا تفركي'], t3: ['Finish with sunscreen in the morning', 'اختمي بواقي الشمس صباحاً'], cta: ['Shop the routine', 'تسوّقي الروتين'], trust: ['General tips · not medical advice', 'نصائح عامة · ليست نصيحة طبية'] },
  'kbb-bundle': { kicker: ['BUNDLE', 'باقة'], headline: ['THE GLOW BUNDLE', 'باقة الإشراقة'], offer: ['Bundle price AED ‹XX›', 'سعر الباقة ‹XX› درهم'], cta: ['Get the bundle', 'اطلبي الباقة'], trust: ['While stocks last', 'حتى نفاد الكمية'] },
  'kbb-carousel': { kicker: ['SWIPE · YOUR ROUTINE', 'اسحبي · روتينك'], headline: ['GLOW IN FOUR STEPS', 'إشراقتك بأربع خطوات'], s1: ['Cleanse', 'تنظيف'], s2: ['Treat', 'سيروم'], s3: ['Moisturise', 'ترطيب'], sub: ['Authentic Korean skincare · UAE delivery', 'منتجات كورية أصلية · توصيل في الإمارات'], cta: ['Shop now', 'تسوّقي الآن'], trust: ['General tips · not medical advice', 'نصائح عامة · ليست نصيحة طبية'] },
};
const ROLE_AR = { nurse: 'ممرض/ة', caregiver: 'مقدّم رعاية', driver: 'سائق', welder: 'لحّام', chef: 'طاهٍ', electrician: 'كهربائي', builder: 'عامل بناء', waiter: 'موظف فندق', warehouse: 'مستودع' };
const ROLE_EN = { nurse: 'NURSE', caregiver: 'CAREGIVER', driver: 'DRIVER', welder: 'WELDER', chef: 'CHEF', electrician: 'ELECTRICIAN', builder: 'BUILDER', waiter: 'HOTEL STAFF', warehouse: 'WAREHOUSE' };
/** the themes' drawn job roles that match the brief's occupations (else a trades trio) */
function rolesFor(spec) {
  const map = (o) => (/weld/.test(o) ? 'welder' : /electric/.test(o) ? 'electrician' : /nurse|doctor|paramedic/.test(o) ? 'nurse' : /care|elderly|nanny|babysit/.test(o) ? 'caregiver' : /driver|courier|rider|taxi/.test(o) ? 'driver' : /chef|cook|baker|kitchen/.test(o) ? 'chef' : /waiter|barista|housekeep|front-desk|steward|bartender/.test(o) ? 'waiter' : /warehouse|forklift|packer|factory/.test(o) ? 'warehouse' : /mason|carpenter|labour|builder|scaffold|steel|tiler|plaster|roof|painter|plumb/.test(o) ? 'builder' : null);
  const got = [...new Set((spec?.occupations || []).map(map).filter(Boolean))];
  for (const r of ['welder', 'electrician', 'driver', 'builder']) if (got.length < 3 && !got.includes(r)) got.push(r);
  return got.slice(0, 3);
}

/** the words of one template in one language, with the country / product filled in */
function wordsOf(tpl, lang, { country, product, brand, roles }) {
  const i = lang === 'ar' ? 1 : 0, out = {};
  for (const [k, v] of Object.entries(W[tpl.id] || {})) {
    out[k] = v[i].replace(/\{C\}/g, (country?.name || 'Your country').toUpperCase()).replace(/\{c\}/g, country?.name || 'your country').replace(/\{ca\}/g, country?.nameAr || 'بلدك')
      .replace(/\{B\}/g, brand || 'K-BEAUTY').replace(/\{P\}/g, product || 'Your product name');
  }
  (roles || []).forEach((r, k) => { out['role' + k] = lang === 'ar' ? ROLE_AR[r] || r : ROLE_EN[r] || r.toUpperCase(); });
  return out;
}

// ── element builders ──
const art = (id, kind, o = {}) => Object.assign({ id, t: 'art', role: 'art', name: o.name || kind, kind, deco: true }, o);
const shape = (id, o = {}) => Object.assign({ id, t: 'shape', role: 'deco', name: o.name || 'Panel', deco: true, sh: 'rect', fill: 'card' }, o);
function texts(X) {
  const s = X.txt, ar = X.ar;
  const t = (id, role, name, o = {}) => (s[id] ? Object.assign({ id, t: 'text', role, name, txt: s[id], font: 'body', fw: 700, pc: 'ink', lh: ar ? 1.32 : 1.2, maxLines: 2 }, o) : null);
  return {
    t,
    kicker: (o) => t('kicker', 'kicker', 'Kicker', Object.assign({ fw: 800, ls: ar ? 0 : 12, up: !ar, pc: 'onAcc', plate: { pc: 'acc', pad: 0.36, rad: 8 }, maxLines: 1 }, o)),
    headline: (o) => t('headline', 'headline', 'Headline', Object.assign({ font: 'display', fw: ar ? 800 : 400, up: !ar, lh: ar ? 1.2 : 0.98, ls: ar ? 0 : 1, maxLines: 3 }, o)),
    sub: (o) => t('sub', 'sub', 'Supporting line', Object.assign({ fw: 600, lh: 1.3 }, o)),
    offer: (o) => t('offer', 'offer', 'Offer', Object.assign({ fw: 800, pc: 'onAcc', plate: { pc: 'acc', pad: 0.4 } }, o)),
    trust: (o) => t('trust', 'trust', 'Small print', Object.assign({ fw: 500, lh: 1.25 }, o)),
    contact: (o) => t('contact', 'contact', 'Contact', Object.assign({ fw: 700, maxLines: 1 }, o)),
    cta: (o) => (s.cta ? Object.assign({ id: 'cta', t: 'btn', role: 'cta', name: 'Button', txt: s.cta, font: 'body', fw: 800, pc: 'onAcc', fill: 'acc', sh: 'pill', lh: 1.1, maxLines: 1 }, o) : null),
    logo: (o) => Object.assign({ id: 'logo', t: 'logo', role: 'logo', name: 'Logo', pc: 'ink' }, o),
  };
}
const logoChip = (X, x, y, w, h) => [[shape('logoChip', { name: 'Logo chip', fill: 'card', rad: h / 2, shadow: true }), G(x, y, w, h)], [X.T.logo({ pc: 'cardInk' }), G(x + h * 0.3, y + h * 0.16, w - h * 0.6, h * 0.68)]];
/** the button, contact and small print stacked in a column */
const CHIP = { pc: 'cardInk', plate: { pc: 'card', pad: 0.34 } };
const footer = (X, x, w, y0, y1, o = {}) => stack(X.stk, [[o.noSub ? null : X.T.sub(o.sub), o.subFs || (X.story ? 40 : 34)], [X.T.cta(), X.story ? 42 : X.wide ? 36 : 34, { bw: o.bw || (X.wide ? 600 : X.story ? 760 : 660) }], [X.T.contact(o.chips ? CHIP : o.contact), X.story ? 32 : 28], [X.T.trust(o.chips ? CHIP : o.trust), 25]], x, w, y0, y1, { gap: o.gap || (X.story ? 22 : 16), align: o.align || 'top', al: o.al || 'c' });

// ── the compositions (always drawn left-to-right; Arabic mirrors them afterwards) ──
const C = {};

C['pc-hiring'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [], cc = X.cc;
  const roles = X.roles;
  if (wide) {
    const sx = Wc * 0.44;
    out.push([art('flag', 'flag', { cc, shade: 'left', dark: 0.55 }), G(sx - 40, -30, Wc - sx + 70, H + 60)]);
    out.push([art('landmark', 'landmark', { cc }), G(Wc * 0.58, 40, Wc * 0.44, H * 0.62)]);
    out.push([art('panel', 'panel', { cc, side: 'right', skew: 0.12 }), G(-10, -10, sx + 10, H + 20)]);
    out.push(...logoChip(X, A.x0, A.y0, 270, 74));
    out.push([T.kicker(), G(A.x0, A.y0 + 120, sx - A.x0 - 120, 0, 30, 'l')]);
    out.push([art('band', 'slabs', { cols: ['acc'], skew: 0.3 }), G(-30, A.y0 + 190, sx + 40, 230)]);
    out.push([T.headline({ pc: 'onAcc', maxLines: 2 }), G(A.x0, A.y0 + 190, sx - A.x0 - 90, 230, 150, 'l')]);
    out.push(...footer(X, A.x0, sx - A.x0 - 140, A.y0 + 470, A.y1, { al: 'l', bw: 560 }));
    const r = 112, gap = (Wc - sx - 60) / 3;
    out.push([art('roles', 'roles', { cc, roles, ring: '#ffffff' }), G(sx + 60, H - 420, gap * 3, r * 2)]);
    roles.forEach((_, i) => out.push([T.t('role' + i, 'label', 'Job role ' + (i + 1), { fw: 800, pc: 'ink', maxLines: 1, plate: { pc: 'bg', pad: 0.32, rad: 10 } }), G(sx + 60 + gap * i + 12, H - 420 + r * 2 + 20, gap - 24, 0, 30)]));
    return out;
  }
  const fh = H * (story ? 0.58 : sq ? 0.55 : 0.56);
  out.push([art('flag', 'flag', { cc, shade: 'bottom', dark: 0.7 }), G(-30, -30, Wc + 60, fh + 90)]);
  out.push([art('landmark', 'landmark', { cc }), G(Wc * 0.46, story ? 300 : 110, Wc * 0.6, fh * (story ? 0.62 : 0.72))]);
  out.push(...logoChip(X, A.x1 - 270, A.y0, 270, story ? 78 : 66));
  out.push([T.kicker(), G(A.x0, A.y0 + (story ? 14 : 10), aw - 300, 0, story ? 30 : 26, 'l')]);
  const by = A.y0 + (story ? 140 : sq ? 100 : 110), bh = story ? 230 : sq ? 160 : 180;
  out.push([art('band', 'slabs', { cols: ['acc'], skew: 0.3 }), G(-30, by, Wc * 0.84, bh)]);
  out.push([T.headline({ pc: 'onAcc', maxLines: 2 }), G(A.x0, by, Wc * 0.84 - A.x0 - 70, bh, story ? 190 : 140, 'l')]);
  out.push([art('panel', 'panel', { cc, skew: 0.07 }), G(-10, fh - 40, Wc + 20, H - fh + 50)]);
  const r = story ? 150 : sq ? 96 : 116, cy = fh - (story ? 40 : 30), gap = aw / 3;
  out.push([art('roles', 'roles', { cc, roles, ring: '#ffffff' }), G(A.x0, cy - r, aw, r * 2)]);
  const ly = cy + r + (story ? 22 : 14);
  roles.forEach((_, i) => out.push([T.t('role' + i, 'label', 'Job role ' + (i + 1), { fw: 800, pc: 'ink', maxLines: 1 }), G(A.x0 + gap * i + 8, ly, gap - 16, 0, story ? 36 : 28)]));
  out.push(...footer(X, A.x0, aw, ly + (story ? 70 : 50), A.y1, { subFs: story ? 40 : 32 }));
  return out;
};

C['pc-stamp'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T, cc } = X, out = [];
  out.push([art('flag', 'flag', { cc, shade: wide ? 'left' : 'bottom', dark: 0.9 }), G(-30, -30, Wc + 60, H + 60)]);
  out.push([art('veil', 'panel', { cc, c1: 'bg', c2: 'bg2', bars: false, skew: 0 }), wide ? G(-10, -10, Wc * 0.5, H + 20) : G(-10, -10, Wc + 20, story ? A.y0 + 470 : sq ? 300 : 330)]);
  out.push([art('confetti', 'confetti', { cc, n: 40 }), G(0, 0, Wc, H)]);
  if (wide) {
    out.push(...logoChip(X, A.x0, A.y0, 260, 70));
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 3 }), 118]], A.x0, Wc * 0.42, A.y0 + 110, A.y0 + 560, { gap: 22, al: 'l' }));
    out.push(...footer(X, A.x0, Wc * 0.4, A.y0 + 600, A.y1, { al: 'l', bw: 520 }));
    out.push([art('passport', 'passport', { cc, words: X.words.passport, tilt: -0.06 }), G(Wc * 0.5, H * 0.17, Wc * 0.44, Wc * 0.44 * 0.64)]);
    return out;
  }
  out.push(...logoChip(X, (Wc - 270) / 2, A.y0, 270, story ? 78 : 64));
  const ty = A.y0 + (story ? 110 : 84), tb = story ? A.y0 + 470 : sq ? 290 : 320;
  out.push(...stack(X.stk, [[T.kicker(), story ? 30 : 26], [T.headline({ maxLines: 2 }), story ? 120 : sq ? 84 : 96]], A.x0 + 10, aw - 20, ty, tb - 16, { gap: 18 }));
  const pw = Wc * (story ? 0.92 : sq ? 0.66 : 0.8), ph = pw * 0.64, py = tb + (story ? 40 : 22);
  out.push([art('base', 'panel', { cc, skew: 0.06 }), G(-10, py + ph * 0.7, Wc + 20, H - py - ph * 0.7 + 10)]);
  out.push([art('passport', 'passport', { cc, words: X.words.passport }), G((Wc - pw) / 2, py, pw, ph)]);
  out.push(...footer(X, A.x0, aw, py + ph + (story ? 50 : 26), A.y1));
  return out;
};

C['pc-ticket'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T, cc } = X, out = [];
  if (wide) {
    out.push([art('scene', 'scene', { cc, shade: 0.5 }), G(0, 0, Wc, H)]);
    out.push([art('panel', 'panel', { cc, side: 'right', skew: 0.1 }), G(-10, -10, Wc * 0.46, H + 20)]);
    out.push([art('plane', 'plane', { cc }), G(Wc * 0.5, 60, Wc * 0.46, H * 0.3)]);
    out.push(...logoChip(X, A.x0, A.y0, 260, 70));
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 3 }), 130]], A.x0, Wc * 0.36, A.y0 + 110, A.y0 + 560, { gap: 22, al: 'l' }));
    out.push(...footer(X, A.x0, Wc * 0.36, A.y0 + 590, A.y1, { al: 'l', bw: 520 }));
    out.push([art('ticket', 'ticket', { cc, words: X.words.ticket }), G(Wc * 0.5, H * 0.46, Wc * 0.46, Wc * 0.46 * 0.38)]);
    return out;
  }
  const sh = H * (story ? 0.54 : sq ? 0.56 : 0.56);
  out.push([art('scene', 'scene', { cc, shade: 0.35 }), G(0, 0, Wc, sh + 40)]);
  out.push([art('plane', 'plane', { cc }), G(0, story ? 260 : 60, Wc, sh * 0.32)]);
  out.push([art('veil', 'panel', { cc, c1: 'bg', c2: 'bg', bars: false, skew: 0 }), G(-10, -10, Wc + 20, A.y0 + (story ? 330 : sq ? 230 : 250))]);
  out.push(...logoChip(X, A.x1 - 260, A.y0, 260, story ? 74 : 62));
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 2 }), story ? 118 : sq ? 80 : 92]], A.x0, aw - 280, A.y0, A.y0 + (story ? 320 : sq ? 220 : 240), { gap: 14, al: 'l' }));
  out.push([art('panel', 'panel', { cc, skew: 0.06 }), G(-10, sh, Wc + 20, H - sh + 10)]);
  const tw = Wc * (story ? 0.94 : sq ? 0.8 : 0.88), th = tw * 0.38, ty = sh - th * 0.55;
  out.push([art('ticket', 'ticket', { cc, words: X.words.ticket }), G((Wc - tw) / 2, ty, tw, th)]);
  out.push(...footer(X, A.x0, aw, ty + th + (story ? 50 : 26), A.y1));
  return out;
};

C['pc-bulletin'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T, cc } = X, out = [];
  const pts = ['p1', 'p2', 'p3'];
  const top = wide ? H : H * (story ? 0.46 : 0.42);
  out.push([art('flag', 'flag', { cc, shade: wide ? 'left' : 'full', dark: 0.82 }), G(-30, -30, (wide ? Wc * 0.62 : Wc) + 60, top + 60)]);
  out.push([art('landmark', 'landmark', { cc, second: false }), wide ? G(Wc * 0.28, 120, Wc * 0.34, H * 0.62) : G(Wc * 0.5, story ? 330 : 90, Wc * 0.52, top * (story ? 0.56 : 0.66))]);
  const crH = story ? 70 : wide ? 64 : 56, crY = wide ? H - crH : story ? A.y1 + 30 : H - crH;
  out.push([art('crawl', 'crawl', { cc, words: X.words.crawl }), G(0, crY, Wc, crH)]);
  if (wide) {
    out.push([T.kicker(), G(A.x0, A.y0, 560, 0, 30, 'l')]);
    out.push([T.t('date', 'date', 'Date', { fw: 700, pc: 'ink', maxLines: 1, plate: { pc: 'bg', pad: 0.3, rad: 6 } }), G(A.x0 + 600, A.y0 + 6, 340, 0, 28, 'l')]);
    out.push([T.headline({ maxLines: 2, shadowText: true }), G(A.x0, A.y0 + 120, Wc * 0.5, 0, 150, 'l')]);
    out.push([shape('board', { fill: 'card', rad: 26, shadow: true }), G(Wc * 0.6, A.y0, A.x1 - Wc * 0.6, H - crH - A.y0 - 40)]);
    const bx = Wc * 0.6, bw = A.x1 - bx, rowH = 120;
    out.push([art('checks', 'checks', { ys: pts.map((_, i) => 70 + i * rowH), r: 30 }), G(bx + 30, A.y0, 60, rowH * 3)]);
    pts.forEach((p, i) => out.push([T.t(p, 'point', 'Point ' + (i + 1), { fw: 700, pc: 'cardInk', maxLines: 2 }), G(bx + 110, A.y0 + 30 + i * rowH, bw - 140, 0, 32, 'l')]));
    out.push(...stack(X.stk, [[T.cta(), 34, { bw: 480 }], [T.contact({ pc: 'cardInk' }), 28], [T.trust({ pc: 'cardInk' }), 25]], bx + 40, bw - 80, A.y0 + rowH * 3 + 40, H - crH - 60, { gap: 16, align: 'top', al: 'l' }));
    out.push(...logoChip(X, A.x0, H - crH - 110, 250, 66));
    return out;
  }
  out.push([T.kicker(), G(A.x0, A.y0, aw * 0.55, 0, story ? 32 : 26, 'l')]);
  out.push([T.t('date', 'date', 'Date', { fw: 700, pc: 'ink', maxLines: 1, plate: { pc: 'bg', pad: 0.3, rad: 6 } }), G(A.x0 + aw * 0.58, A.y0 + 4, aw * 0.42, 0, story ? 28 : 24, 'r')]);
  out.push([T.headline({ maxLines: 2, shadowText: true }), G(A.x0, A.y0 + (story ? 110 : 80), aw * 0.62, 0, story ? 150 : sq ? 92 : 110, 'l')]);
  const by = top - 20, bb = story ? A.y1 - 10 : crY - 16;
  out.push([shape('board', { fill: 'card', rad: 28, shadow: true }), G(A.x0 - 8, by, aw + 16, bb - by)]);
  const rowH = story ? 120 : sq ? 74 : 88, r = story ? 30 : 22;
  out.push([art('checks', 'checks', { ys: pts.map((_, i) => 30 + rowH * i + rowH * 0.35), r }), G(A.x0 + 16, by, r * 2, rowH * 3)]);
  pts.forEach((p, i) => out.push([T.t(p, 'point', 'Point ' + (i + 1), { fw: 700, pc: 'cardInk', maxLines: 2 }), G(A.x0 + 24 + r * 2 + 20, by + 30 + rowH * i, aw - r * 2 - 70, 0, story ? 34 : 28, 'l')]));
  const fy = by + 40 + rowH * 3;
  out.push(...stack(X.stk, [[T.cta(), story ? 40 : 30, { bw: 560 }], [T.contact({ pc: 'cardInk' }), story ? 30 : 26], [T.trust({ pc: 'cardInk' }), 25]], A.x0 + 30, aw - 60, fy, bb - 20, { gap: story ? 18 : 10, align: 'top', al: 'l' }));
  out.push(...logoChip(X, A.x1 - 240, by - (story ? 100 : 80), 240, story ? 66 : 58));
  return out;
};

C['pc-split'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T, cc, ws } = X, out = [];
  const src = photoOf(ws, 'worker') || X.workerSrc;
  const photo = (g) => [Object.assign({ id: 'photo', t: 'img', role: 'hero', name: 'Worker photo', fit: 'cover', fy: 0.25, slot: 'worker', role2: X.roles[0], rad: 0, bleed: true, z: 0 }, src ? { src } : {}), g];
  if (wide) {
    out.push(photo(G(0, 0, Wc * 0.46, H)));
    out.push([art('flag', 'flag', { cc, shade: 'right', dark: 0.25 }), G(Wc * 0.46, -30, Wc * 0.56, H + 60)]);
    out.push([art('panel', 'panel', { cc, side: 'left', skew: 0.12 }), G(Wc * 0.44, -10, Wc * 0.58, H + 20)]);
    out.push([art('map', 'map', { cc }), G(Wc * 0.78, A.y0 + 10, 300, 300)]);
    out.push(...logoChip(X, Wc * 0.52, A.y0, 260, 70));
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 3 }), 116]], Wc * 0.52, Wc * 0.26, A.y0 + 120, A.y0 + 560, { gap: 20, al: 'l' }));
    out.push(...footer(X, Wc * 0.52, A.x1 - Wc * 0.52, A.y0 + 600, A.y1, { al: 'l', bw: 540 }));
    return out;
  }
  const ph = H * (story ? 0.47 : sq ? 0.5 : 0.48);
  out.push(photo(G(0, 0, Wc, ph)));
  out.push([art('flag', 'flag', { cc, shade: 'top', dark: 0.25 }), G(-30, ph - 30, Wc + 60, H - ph + 60)]);
  out.push([art('panel', 'panel', { cc, skew: 0.08 }), G(-10, ph - 30, Wc + 20, H - ph + 40)]);
  const ms = story ? 260 : sq ? 170 : 200;
  out.push([art('map', 'map', { cc }), G(A.x1 - ms, ph - ms * 0.62, ms, ms)]);
  out.push(...logoChip(X, A.x0, A.y0, 260, story ? 74 : 62));
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 2 }), story ? 108 : sq ? 72 : 84]], A.x0, aw - ms - 20, ph + (story ? 70 : 40), ph + (story ? 470 : sq ? 270 : 330), { gap: 16, al: 'l', align: 'top' }));
  out.push(...footer(X, A.x0, aw, ph + (story ? 490 : sq ? 290 : 350), A.y1, { al: 'l' }));
  return out;
};

C['pc-study'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T, cc } = X, out = [];
  out.push([art('glow', 'glow', { c1: 'acc', c2: 'acc2', add: false, n: 10 }), G(0, 0, Wc, H)]);
  out.push([art('stripes', 'stripes', { cc }), wide ? G(0, 0, 26, H) : G(0, 0, Wc, 22)]);
  if (wide) {
    out.push([art('plane', 'plane', { c1: 'acc' }), G(Wc * 0.05, 40, Wc * 0.5, 220)]);
    out.push(...logoChip(X, A.x0 + 10, A.y0, 260, 70));
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 2 }), 140]], A.x0 + 10, Wc * 0.4, A.y0 + 200, A.y0 + 560, { gap: 22, al: 'l' }));
    out.push(...footer(X, A.x0 + 10, Wc * 0.4, A.y0 + 600, A.y1, { al: 'l', bw: 540 }));
    out.push([art('postcard', 'postcard', { cc, tilt: 0.04 }), G(Wc * 0.5, H * 0.13, Wc * 0.44, Wc * 0.44 * 0.7)]);
    return out;
  }
  out.push([art('plane', 'plane', { c1: 'acc' }), G(0, story ? 240 : 40, Wc, story ? 200 : 150)]);
  out.push(...logoChip(X, (Wc - 260) / 2, A.y0, 260, story ? 74 : 62));
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 2 }), story ? 130 : sq ? 88 : 100]], A.x0, aw, A.y0 + (story ? 110 : 84), A.y0 + (story ? 440 : sq ? 270 : 300), { gap: 18 }));
  const pw = Wc * (story ? 0.9 : sq ? 0.62 : 0.78), ph = pw * 0.7, py = A.y0 + (story ? 470 : sq ? 285 : 320);
  out.push([art('postcard', 'postcard', { cc }), G((Wc - pw) / 2, py, pw, ph)]);
  out.push(...footer(X, A.x0, aw, py + ph + (story ? 50 : 24), A.y1));
  return out;
};

C['pc-europe'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [], ccs = X.ccs;
  out.push([art('flag', 'flag', { theme: 'europe', shade: 'full', dark: 0.86 }), G(-30, -30, Wc + 60, H + 60)]);
  const tile = (i, x, y, w, h) => {
    out.push([art('tile' + i, 'scene', { cc: ccs[i], rad: 26 }), G(x, y, w, h)]);
    out.push([art('tbar' + i, 'stripes', { cc: ccs[i], rad: 0 }), G(x, y + h - 12, w, 12)]);
    out.push([T.t('c' + i, 'label', 'Country ' + (i + 1), { fw: 800, pc: 'ink', maxLines: 1, up: !X.ar, ls: X.ar ? 0 : 6, plate: { pc: 'bg', pad: 0.34, rad: 8 } }), G(x + 14, y + h - (story ? 84 : 64), w - 28, 0, story ? 32 : 24)]);
  };
  if (wide) {
    out.push(...logoChip(X, A.x0, A.y0, 260, 70));
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 1 }), 150]], A.x0 + 300, aw - 300, A.y0, A.y0 + 230, { gap: 14, al: 'l' }));
    const gw = (aw - 3 * 24) / 4, gy = A.y0 + 260, gh = 400;
    for (let i = 0; i < 4; i++) tile(i, A.x0 + i * (gw + 24), gy, gw, gh);
    out.push(...stack(X.stk, [[T.cta(), 34, { bw: 520 }], [T.contact(), 28]], A.x0, aw * 0.5, gy + gh + 40, A.y1, { gap: 14, align: 'top', al: 'l' }));
    out.push(...stack(X.stk, [[T.sub(), 30], [T.trust(), 25]], A.x0 + aw * 0.52, aw * 0.48, gy + gh + 40, A.y1, { gap: 10, align: 'top', al: 'r' }));
    return out;
  }
  out.push(...logoChip(X, (Wc - 260) / 2, A.y0, 260, story ? 74 : 60));
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 2 }), story ? 140 : sq ? 84 : 100]], A.x0, aw, A.y0 + (story ? 110 : 80), A.y0 + (story ? 380 : sq ? 230 : 260), { gap: 16 }));
  const gy = A.y0 + (story ? 400 : sq ? 245 : 280), gh = story ? 640 : sq ? 470 : 600, gap = 22, tw = (aw - gap) / 2, th = (gh - gap) / 2;
  for (let i = 0; i < 4; i++) tile(i, A.x0 + (i % 2) * (tw + gap), gy + Math.floor(i / 2) * (th + gap), tw, th);
  out.push(...footer(X, A.x0, aw, gy + gh + (story ? 30 : 18), A.y1));
  return out;
};

C['pc-story'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T, cc, ws } = X, out = [];
  const src = photoOf(ws, 'person');
  const photo = (g) => [Object.assign({ id: 'photo', t: 'img', role: 'hero', name: 'Client photo (with permission)', fit: 'cover', fy: 0.2, slot: 'person', mask: 'circle', ring: 'acc', ringW: 12 }, src ? { src } : {}), g];
  out.push([art('flag', 'flag', { cc, shade: 'full', dark: 0.9 }), G(-30, -30, Wc + 60, H + 60)]);
  out.push([art('landmark', 'landmark', { cc, second: false, light: 'night' }), wide ? G(Wc * 0.62, H * 0.3, Wc * 0.4, H * 0.7) : G(Wc * 0.45, H * 0.6, Wc * 0.6, H * 0.4)]);
  const quote = T.headline({ font: 'serif', fw: 700, up: false, lh: 1.12, ls: 0, maxLines: 4 });
  const nm = T.t('name', 'name', 'Name', { fw: 800, pc: 'ink', maxLines: 1 }), det = T.t('detail', 'detail', 'Detail', { fw: 600, pc: 'ink', maxLines: 1 });
  if (wide) {
    out.push(photo(G(A.x0 + 20, A.y0 + 120, 420, 420)));
    out.push([art('quote', 'quote', { c1: 'acc' }), G(A.x0 + 520, A.y0 + 60, 200, 90)]);
    out.push([T.kicker(), G(A.x0, A.y0, 420, 0, 28, 'l')]);
    out.push(...stack(X.stk, [[quote, 74], [nm, 36], [det, 28]], A.x0 + 520, aw - 560, A.y0 + 170, A.y0 + 640, { gap: 18, al: 'l' }));
    out.push(...stack(X.stk, [[T.cta(), 34, { bw: 480 }], [T.contact(), 28], [T.trust(), 25]], A.x0 + 520, aw - 560, A.y0 + 670, A.y1, { gap: 14, align: 'top', al: 'l' }));
    out.push(...logoChip(X, A.x0 + 60, A.y1 - 80, 260, 70));
    return out;
  }
  out.push(...logoChip(X, A.x1 - 250, A.y0, 250, story ? 70 : 58));
  out.push([T.kicker(), G(A.x0, A.y0 + 8, aw - 280, 0, story ? 28 : 24, 'l')]);
  const ps = story ? 470 : sq ? 250 : 320, py = A.y0 + (story ? 120 : 90);
  out.push(photo(G(A.x0, py, ps, ps)));
  out.push([art('quote', 'quote', { c1: 'acc' }), G(A.x0 + ps + 40, py + ps * 0.12, story ? 200 : 150, story ? 90 : 66)]);
  out.push(...stack(X.stk, [[nm, story ? 38 : 30], [det, story ? 30 : 25]], A.x0 + ps + 40, aw - ps - 40, py + ps * 0.45, py + ps, { gap: 10, al: 'l' }));
  out.push(...stack(X.stk, [[quote, story ? 84 : sq ? 54 : 64]], A.x0, aw, py + ps + (story ? 50 : 26), py + ps + (story ? 560 : sq ? 260 : 330), { gap: 10, al: 'l', align: 'top' }));
  out.push(...stack(X.stk, [[T.cta(), story ? 40 : 32, { bw: 560 }], [T.contact(), story ? 30 : 26], [T.trust(), 25]], A.x0, aw, py + ps + (story ? 590 : sq ? 280 : 350), A.y1, { gap: 14, align: 'top', al: 'l' }));
  return out;
};

C['pc-event'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T, cc } = X, out = [];
  out.push([art('scene', 'scene', { cc, light: 'night', shade: 0.85 }), G(0, 0, Wc, H)]);
  out.push([art('rays', 'rays', { c1: 'acc2' }), G(0, 0, Wc, H)]);
  out.push([art('veil', 'veil', { a0: 0.55, a1: 0.92, dir: wide ? 'h' : 'v', z: 0 }), wide ? G(0, 0, Wc * 0.66, H) : G(0, 0, Wc, H)]);
  out.push([art('frame', 'frame', { c1: 'acc' }), G(A.x0 - 20, A.y0 - 20, aw + 40, A.y1 - A.y0 + 40)]);
  const day = T.t('day', 'big', 'Day', { font: 'display', fw: 400, pc: 'onAcc', lh: 0.9, maxLines: 1 }), mon = T.t('month', 'bigLabel', 'Month', { font: 'display', fw: 400, pc: 'onAcc', lh: 1, maxLines: 1, ls: 6, up: !X.ar });
  const dateBox = (x, y, s) => { out.push([shape('dateCard', { fill: 'acc', rad: 24, shadow: true }), G(x, y, s, s * 1.1)]); out.push(...stack(X.stk, [[day, s * 0.5], [mon, s * 0.2]], x + s * 0.1, s * 0.8, y + s * 0.1, y + s * 1.0, { gap: 4 })); };
  if (wide) {
    out.push(...logoChip(X, A.x0, A.y0, 260, 70));
    dateBox(A.x1 - 300, A.y0, 300);
    out.push(...stack(X.stk, [[T.kicker(), 30], [T.headline({ maxLines: 2 }), 130], [T.sub(), 36]], A.x0, aw - 380, A.y0 + 140, A.y0 + 640, { gap: 20, al: 'l' }));
    out.push(...stack(X.stk, [[T.cta(), 36, { bw: 520 }], [T.contact(), 28], [T.trust(), 25]], A.x0, aw * 0.6, A.y0 + 680, A.y1, { gap: 14, align: 'top', al: 'l' }));
    return out;
  }
  out.push(...logoChip(X, A.x0, A.y0, 260, story ? 74 : 60));
  const ds = story ? 300 : sq ? 190 : 220;
  dateBox(A.x1 - ds, A.y0, ds);
  out.push(...stack(X.stk, [[T.kicker(), story ? 30 : 24], [T.headline({ maxLines: 3 }), story ? 128 : sq ? 80 : 96], [T.sub(), story ? 38 : 30]], A.x0, aw, A.y0 + ds * 1.1 + (story ? 60 : 30), A.y0 + (story ? 1000 : sq ? 690 : 820), { gap: 18, al: 'l', align: 'top' }));
  out.push(...stack(X.stk, [[T.cta(), story ? 42 : 32, { bw: 600 }], [T.contact(), story ? 30 : 26], [T.trust(), 25]], A.x0, aw, A.y0 + (story ? 1020 : sq ? 700 : 840), A.y1, { gap: 16, align: 'top', al: 'l' }));
  return out;
};

// ── KBB ──
const prodEl = (X, i, o = {}) => { const p = X.products[i % X.products.length]; return p ? Object.assign({ id: 'p' + i, t: 'prod', role: 'product', name: p.name || 'Product', src: p.src, fit: 'contain' }, o) : Object.assign({ id: 'p' + i, t: 'img', role: 'product', name: 'Product photo', slot: 'product', fit: 'contain', rad: 30 }, o); };
const prodBox = (X, i, x, y, w, h) => { const p = X.products[i % X.products.length]; if (!p) return G(x, y, w, h); const a = p.w / p.h, bw = Math.min(w, h * a), bh = bw / a; return G(x + (w - bw) / 2, y + h - bh, bw, bh); };

C['kbb-hero'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [];
  out.push([art('glow', 'glow', { c1: 'acc', c2: 'acc2', n: 16 }), G(0, 0, Wc, H)]);
  out.push([art('silk', 'silk', { cols: ['acc', 'acc2', 'card'] }), wide ? G(0, H * 0.55, Wc, H * 0.5) : G(0, H * 0.62, Wc, H * 0.42)]);
  out.push([art('leaks', 'leaks', { c1: 'acc2', a: 0.5, n: 8 }), G(0, 0, Wc, H)]);
  if (wide) {
    out.push([art('podium', 'podium', { c1: 'card', ring: 'acc2' }), G(Wc * 0.58, H * 0.72, Wc * 0.3, H * 0.2)]);
    out.push([prodEl(X, 0), prodBox(X, 0, Wc * 0.62, H * 0.1, Wc * 0.22, H * 0.66)]);
    out.push([X.T.logo(), G(A.x0, A.y0, 300, 80, null, 'l')]);
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ font: 'serif', fw: 700, up: false, ls: 0, lh: 1.05 }), 116], [T.sub(), 36]], A.x0, Wc * 0.44, A.y0 + 130, A.y0 + 600, { gap: 20, al: 'l' }));
    out.push(...stack(X.stk, [[T.cta(), 36, { bw: 560 }], [T.contact(CHIP), 28], [T.trust(CHIP), 25]], A.x0, Wc * 0.44, A.y0 + 640, A.y1, { gap: 14, align: 'top', al: 'l' }));
    return out;
  }
  out.push([X.T.logo(), G((Wc - 300) / 2, A.y0, 300, story ? 84 : 70)]);
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ font: 'serif', fw: 700, up: false, ls: 0, lh: 1.05 }), story ? 112 : sq ? 74 : 88], [T.sub(), story ? 38 : 30]], A.x0, aw, A.y0 + (story ? 120 : 90), A.y0 + (story ? 470 : sq ? 290 : 340), { gap: 16 }));
  const py = A.y0 + (story ? 470 : sq ? 300 : 350), phh = story ? 540 : sq ? 360 : 460;
  out.push([art('podium', 'podium', { c1: 'card', ring: 'acc2' }), G(Wc * 0.22, py + phh - 70, Wc * 0.56, story ? 150 : 110)]);
  out.push([prodEl(X, 0), prodBox(X, 0, Wc * 0.3, py, Wc * 0.4, phh - 40)]);
  out.push(...footer(X, A.x0, aw, py + phh + (story ? 100 : 56), A.y1, { noSub: true, chips: true }));
  return out;
};

C['kbb-offer'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [];
  out.push([art('glow', 'glow', { c1: 'acc', c2: 'acc2', n: 12 }), G(0, 0, Wc, H)]);
  out.push([art('confetti', 'confetti', { cols: ['acc', 'acc2', '#FFFFFF'], n: 50 }), G(0, 0, Wc, H)]);
  const big = T.t('big', 'big', 'Big number', { font: 'display', fw: 400, pc: 'onAcc', lh: 0.9, maxLines: 1 }), lab = T.t('bigLabel', 'bigLabel', 'Big number label', { font: 'display', fw: 400, pc: 'onAcc', lh: 1, maxLines: 1, ls: X.ar ? 0 : 8, up: !X.ar });
  const burst = (x, y, s) => { out.push([art('burst', 'burst', { c1: 'acc', c2: 'acc2' }), G(x, y, s, s)]); out.push(...stack(X.stk, [[big, s * 0.3], [lab, s * 0.12]], x + s * 0.22, s * 0.56, y + s * 0.26, y + s * 0.74, { gap: 2 })); };
  const prods = Math.min(3, Math.max(1, X.products.length));
  if (wide) {
    out.push([X.T.logo(), G(A.x0, A.y0, 300, 80, null, 'l')]);
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 2 }), 140]], A.x0, Wc * 0.4, A.y0 + 130, A.y0 + 520, { gap: 18, al: 'l' }));
    out.push(...stack(X.stk, [[T.cta(), 36, { bw: 560 }], [T.contact(CHIP), 28], [T.trust(CHIP), 25]], A.x0, Wc * 0.4, A.y0 + 560, A.y1, { gap: 14, align: 'top', al: 'l' }));
    burst(Wc * 0.44, A.y0, 460);
    out.push([art('podium', 'podium', { c1: 'card', ring: 'acc2' }), G(Wc * 0.62, H * 0.78, Wc * 0.32, H * 0.16)]);
    for (let i = 0; i < prods; i++) out.push([prodEl(X, i), prodBox(X, i, Wc * 0.64 + i * Wc * 0.095, H * 0.36, Wc * 0.09, H * 0.46)]);
    return out;
  }
  out.push([X.T.logo(), G(A.x0, A.y0, 280, story ? 80 : 66, null, 'l')]);
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 2 }), story ? 150 : sq ? 96 : 116]], A.x0, aw * (sq ? 0.56 : 0.6), A.y0 + (story ? 110 : 90), A.y0 + (story ? 520 : sq ? 360 : 420), { gap: 16, al: 'l' }));
  const bs = story ? 440 : sq ? 360 : 400;
  burst(A.x1 - bs + 30, A.y0 + (story ? 80 : 40), bs);
  const py = A.y0 + (story ? 560 : sq ? 420 : 470), phh = story ? 520 : sq ? 260 : 360;
  out.push([art('podium', 'podium', { c1: 'card', ring: 'acc2' }), G(Wc * 0.14, py + phh - 60, Wc * 0.72, story ? 140 : 100)]);
  for (let i = 0; i < prods; i++) out.push([prodEl(X, i), prodBox(X, i, Wc * 0.2 + i * Wc * 0.21, py, Wc * 0.18, phh - 30)]);
  out.push(...footer(X, A.x0, aw, py + phh + (story ? 100 : 56), A.y1, { chips: true }));
  return out;
};

C['kbb-new'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [];
  out.push([art('slabs', 'slabs', { cols: ['acc', 'acc2'], skew: 0.5, z: 0 }), wide ? G(Wc * 0.62, H * 0.46, Wc * 0.44, H * 0.4) : G(-40, H * (story ? 0.5 : 0.5), Wc + 80, H * 0.22)]);
  out.push([art('petals', 'petals', { c1: 'acc', n: 18 }), G(0, 0, Wc, H)]);
  if (wide) {
    out.push([art('bigword', 'bigword', { words: X.words.bigword, c1: 'acc' }), G(Wc * 0.46, H * 0.08, Wc * 0.5, H * 0.5)]);
    out.push([prodEl(X, 0), prodBox(X, 0, Wc * 0.58, H * 0.14, Wc * 0.26, H * 0.74)]);
    out.push([X.T.logo(), G(A.x0, A.y0, 300, 80, null, 'l')]);
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 2 }), 150], [T.sub(), 36]], A.x0, Wc * 0.4, A.y0 + 130, A.y0 + 620, { gap: 20, al: 'l' }));
    out.push(...stack(X.stk, [[T.cta(), 36, { bw: 560 }], [T.contact(CHIP), 28], [T.trust(CHIP), 25]], A.x0, Wc * 0.4, A.y0 + 660, A.y1, { gap: 14, align: 'top', al: 'l' }));
    return out;
  }
  out.push([X.T.logo(), G((Wc - 300) / 2, A.y0, 300, story ? 84 : 70)]);
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 1 }), story ? 170 : sq ? 110 : 130], [T.sub(), story ? 36 : 30]], A.x0, aw, A.y0 + (story ? 120 : 90), A.y0 + (story ? 420 : sq ? 270 : 300), { gap: 14 }));
  const py = A.y0 + (story ? 440 : sq ? 285 : 320), phh = story ? 600 : sq ? 410 : 540;
  out.push([art('bigword', 'bigword', { words: X.words.bigword, c1: 'acc' }), G(A.x0 - 20, py + phh * 0.06, aw + 40, phh * 0.62)]);
  out.push([prodEl(X, 0), prodBox(X, 0, Wc * 0.3, py, Wc * 0.4, phh)]);
  out.push(...footer(X, A.x0, aw, py + phh + (story ? 50 : 24), A.y1, { chips: true, noSub: true }));
  return out;
};

C['kbb-routine'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [], n = 4;
  out.push([art('glow', 'glow', { c1: 'acc', c2: 'acc2', add: false, n: 10 }), G(0, 0, Wc, H)]);
  out.push([art('silk', 'silk', { cols: ['acc', 'card'] }), G(0, H * 0.8, Wc, H * 0.22)]);
  const stepAt = wide ? (i) => [A.x0 + 170 + i * ((aw - 340) / (n - 1)), A.y0 + 470] : story ? (i) => [i % 2 ? A.x1 - 120 : A.x0 + 120, A.y0 + 470 + i * 205] : (i) => [A.x0 + 90 + i * ((aw - 180) / (n - 1)), sq ? A.y0 + 430 : A.y0 + 500];
  const r = story ? 64 : sq ? 54 : 58, pts = [...Array(n)].map((_, i) => stepAt(i));
  out.push([X.T.logo(), G(wide ? A.x0 : (Wc - 300) / 2, A.y0, 300, story ? 84 : 70, null, wide ? 'l' : 'c')]);
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 2 }), story ? 120 : sq ? 84 : 100]], wide ? A.x0 + 340 : A.x0, wide ? aw - 340 : aw, A.y0 + (wide ? 0 : story ? 120 : 90), A.y0 + (wide ? 300 : story ? 400 : sq ? 300 : 340), { gap: 16, al: wide ? 'l' : 'c' }));
  out.push([art('steps', 'steps', { pts, r, c1: 'acc' }), G(0, 0, Wc, H)]);
  pts.forEach(([px, py], i) => {
    out.push([T.t('n' + i, 'big', 'Step number ' + (i + 1), { font: 'display', fw: 400, pc: 'onAcc', maxLines: 1, lh: 1 }), G(px - r * 0.7, py - r * 0.7, r * 1.4, r * 1.4, r * 0.95)]);
    const lw = story ? aw - 2 * r - 80 : Math.min(wide ? (aw - 340) / (n - 1) - 20 : (aw - 180) / (n - 1) - 10, 2 * Math.min(px - A.x0, A.x1 - px));
    const g = story ? (i % 2 ? G(A.x0, py - 30, lw, 0, 42, 'r') : G(px + r + 30, py - 30, lw, 0, 42, 'l')) : G(px - lw / 2, py + r + 20, lw, 0, wide ? 34 : sq ? 28 : 30);
    out.push([T.t('s' + (i + 1), 'label', 'Step ' + (i + 1), { fw: 800, pc: 'cardInk', maxLines: 2, plate: { pc: 'card', pad: 0.3 } }), g]);
  });
  out.push(...footer(X, A.x0, aw, wide ? A.y0 + 660 : story ? A.y0 + 1180 : sq ? A.y0 + 640 : A.y0 + 760, A.y1, { al: wide ? 'l' : 'c', bw: wide ? 560 : 660, chips: true }));
  return out;
};

C['kbb-tips'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [];
  out.push([art('glow', 'glow', { c1: 'acc', c2: 'acc2', n: 10 }), G(0, 0, Wc, H)]);
  const lab = (id, o) => T.t(id, 'label', id === 'before' ? 'Left label' : 'Right label', Object.assign({ fw: 800, maxLines: 1, up: !X.ar, ls: X.ar ? 0 : 8 }, o));
  const tipsFs = story ? 36 : 30;
  if (wide) {
    out.push([X.T.logo(), G(A.x0, A.y0, 300, 80, null, 'l')]);
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 2 }), 130]], A.x0, Wc * 0.4, A.y0 + 120, A.y0 + 420, { gap: 18, al: 'l' }));
    out.push(...stack(X.stk, [[T.t('t1', 'point', 'Tip 1', { fw: 600 }), 32], [T.t('t2', 'point', 'Tip 2', { fw: 600 }), 32], [T.t('t3', 'point', 'Tip 3', { fw: 600 }), 32]], A.x0, Wc * 0.4, A.y0 + 450, A.y0 + 700, { gap: 14, align: 'top', al: 'l' }));
    out.push(...stack(X.stk, [[T.cta(), 34, { bw: 480 }], [T.trust(), 25]], A.x0, Wc * 0.4, A.y0 + 720, A.y1, { gap: 14, align: 'top', al: 'l' }));
    const sx = Wc * 0.48, sw = A.x1 - sx, sy = A.y0, sh = A.y1 - A.y0;
    out.push([art('split', 'split', { c1: 'bg2', c2: 'acc', rad: 30 }), G(sx, sy, sw, sh)]);
    out.push([prodEl(X, 0), prodBox(X, 0, sx + sw * 0.6, sy + sh * 0.2, sw * 0.3, sh * 0.62)]);
    out.push([lab('before', { pc: 'ink', plate: { pc: 'bg', pad: 0.34, rad: 8 } }), G(sx + 30, sy + 30, sw * 0.4, 0, 30, 'l')]);
    out.push([lab('after', { pc: 'onAcc', plate: { pc: 'acc', pad: 0.34, rad: 8 } }), G(sx + sw * 0.55, sy + 30, sw * 0.4, 0, 30, 'r')]);
    return out;
  }
  out.push([X.T.logo(), G((Wc - 300) / 2, A.y0, 300, story ? 84 : 66)]);
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 1 }), story ? 130 : sq ? 84 : 100]], A.x0, aw, A.y0 + (story ? 110 : 80), A.y0 + (story ? 330 : sq ? 230 : 250), { gap: 14 }));
  const sy = A.y0 + (story ? 350 : sq ? 240 : 270), sh = story ? 560 : sq ? 330 : 420;
  out.push([art('split', 'split', { c1: 'bg2', c2: 'acc', rad: 30 }), G(A.x0, sy, aw, sh)]);
  out.push([prodEl(X, 0), prodBox(X, 0, A.x0 + aw * 0.62, sy + sh * 0.22, aw * 0.3, sh * 0.7)]);
  out.push([lab('before', { pc: 'ink', plate: { pc: 'bg', pad: 0.34, rad: 8 } }), G(A.x0 + 24, sy + 24, aw * 0.4, 0, story ? 32 : 26, 'l')]);
  out.push([lab('after', { pc: 'onAcc', plate: { pc: 'acc', pad: 0.34, rad: 8 } }), G(A.x0 + aw * 0.55, sy + 24, aw * 0.42, 0, story ? 32 : 26, 'r')]);
  out.push(...stack(X.stk, [[T.t('t1', 'point', 'Tip 1', { fw: 600 }), tipsFs], [T.t('t2', 'point', 'Tip 2', { fw: 600 }), tipsFs], [T.t('t3', 'point', 'Tip 3', { fw: 600 }), tipsFs]], A.x0, aw, sy + sh + (story ? 40 : 20), sy + sh + (story ? 330 : sq ? 170 : 220), { gap: story ? 16 : 8, align: 'top', al: 'l' }));
  out.push(...stack(X.stk, [[T.cta(), story ? 40 : 32, { bw: 600 }], [T.trust(), 25]], A.x0, aw, sy + sh + (story ? 350 : sq ? 180 : 240), A.y1, { gap: 14, align: 'top' }));
  return out;
};

C['kbb-bundle'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [];
  out.push([art('glow', 'glow', { c1: 'acc', c2: 'acc2', add: false, n: 12 }), G(0, 0, Wc, H)]);
  out.push([art('silk', 'silk', { cols: ['acc', 'acc2'] }), wide ? G(0, H * 0.6, Wc, H * 0.42) : G(0, H * 0.58, Wc, H * 0.3)]);
  const n = Math.min(3, Math.max(1, X.products.length));
  if (wide) {
    out.push([X.T.logo(), G(A.x0, A.y0, 300, 80, null, 'l')]);
    out.push(...stack(X.stk, [[T.kicker(), 28], [T.headline({ maxLines: 2 }), 130], [T.offer(), 40]], A.x0, Wc * 0.4, A.y0 + 120, A.y0 + 600, { gap: 20, al: 'l' }));
    out.push(...stack(X.stk, [[T.cta(), 36, { bw: 560 }], [T.contact(CHIP), 28], [T.trust(CHIP), 25]], A.x0, Wc * 0.4, A.y0 + 640, A.y1, { gap: 14, align: 'top', al: 'l' }));
    out.push([art('podium', 'podium', { c1: 'card', ring: 'acc' }), G(Wc * 0.5, H * 0.74, Wc * 0.44, H * 0.18)]);
    for (let i = 0; i < n; i++) out.push([prodEl(X, i), prodBox(X, i, Wc * 0.53 + i * Wc * 0.13, H * 0.2, Wc * 0.12, H * 0.6)]);
    return out;
  }
  out.push([X.T.logo(), G((Wc - 300) / 2, A.y0, 300, story ? 84 : 70)]);
  out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ maxLines: 2 }), story ? 130 : sq ? 84 : 100], [T.offer(), story ? 44 : 34]], A.x0, aw, A.y0 + (story ? 110 : 84), A.y0 + (story ? 500 : sq ? 330 : 380), { gap: 16 }));
  const py = A.y0 + (story ? 520 : sq ? 350 : 400), phh = story ? 520 : sq ? 360 : 460;
  out.push([art('podium', 'podium', { c1: 'card', ring: 'acc' }), G(Wc * 0.08, py + phh - 70, Wc * 0.84, story ? 160 : 110)]);
  for (let i = 0; i < n; i++) out.push([prodEl(X, i), prodBox(X, i, Wc * 0.13 + i * Wc * 0.26, py, Wc * 0.22, phh - 40)]);
  out.push(...footer(X, A.x0, aw, py + phh + (story ? 110 : 60), A.y1, { noSub: true, chips: true }));
  return out;
};

// ── carousels: one panorama, cut into slides; art with pan:true runs across the cut ──
C['pc-carousel'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [], n = X.slides, i = X.slide, ox = -i * Wc, PW = Wc * n;
  const bandY = wide ? H * 0.74 : H * (story ? 0.63 : 0.72), bandH = wide ? 120 : story ? 150 : 110;
  out.push([art('sky', 'flag', { theme: 'europe', shade: 'full', dark: 0.8, pan: true, leaks: false }), G(ox - 30, -30, PW + 60, H + 60)]);
  out.push([art('band', 'slabs', { cols: ['acc', 'bg2'], skew: 0.4, pan: true }), G(ox - 40, bandY, PW + 80, bandH)]);
  out.push([art('route', 'plane', { c1: '#FFFFFF', pan: true }), G(ox + Wc * 0.5, H * 0.12, PW - Wc, H * 0.22)]);
  if (i === 0) out.push([art('globe', 'globe', { theme: 'europe' }), wide ? G(Wc * 0.6, H * 0.08, H * 0.66, H * 0.66) : G(Wc * 0.54, bandY - Wc * 0.5, Wc * 0.44, Wc * 0.44)]);
  if (i === 0) {
    out.push(...logoChip(X, A.x0, A.y0, 260, story ? 74 : 62));
    out.push(...stack(X.stk, [[T.kicker(), story ? 30 : 26], [T.headline({ maxLines: 3 }), story ? 170 : wide ? 150 : 120], [T.sub(), story ? 38 : 32]], A.x0, wide ? aw * 0.56 : aw * 0.52, A.y0 + (story ? 300 : 120), bandY - 30, { gap: 20, al: 'l' }));
  } else if (i < n - 1) {
    // a country stop on the route: its scene and flag, its name, the roles drawn large on the band, the roles in words
    const cc = X.ccs[(i - 1) % X.ccs.length], sw = wide ? aw * 0.52 : aw, sh = wide ? H * 0.58 : story ? 620 : sq ? 330 : 430, sy = A.y0 + (story ? 40 : 0);
    const r = wide ? 96 : story ? 120 : sq ? 70 : 90;
    out.push([art('scene' + i, 'scene', { cc, rad: 30 }), G(A.x0, sy, sw, sh)]);
    out.push([art('flag' + i, 'flag', { cc, rad: 20, leaks: false }), G(A.x0 + 24, sy + 24, 150, 100)]);
    out.push([T.t('country', 'headline', 'Country', { font: 'display', fw: X.ar ? 800 : 400, up: !X.ar, maxLines: 2, lh: X.ar ? 1.2 : 1 }), wide ? G(A.x0 + sw + 40, A.y0 + 60, aw - sw - 40, 0, 120, 'l') : G(A.x0, sy + sh + 30, aw, 0, story ? 130 : 96, 'l')]);
    out.push([art('roles' + i, 'roles', { cc, roles: X.roles, ring: '#ffffff' }), wide ? G(A.x0 + sw + 40, bandY + bandH / 2 - r, aw - sw - 40, r * 2) : G(A.x0, bandY + bandH / 2 - r, aw, r * 2)]);
    out.push([T.t('roles', 'sub', 'Roles', { fw: 700, maxLines: 2 }), wide ? G(A.x0 + sw + 40, A.y0 + 330, aw - sw - 40, 0, 36, 'l') : G(A.x0, bandY + bandH / 2 + r + 24, aw, 0, story ? 38 : 30, 'c')]);
  } else {
    out.push(...logoChip(X, A.x0, A.y0, 260, story ? 74 : 62));
    out.push(...stack(X.stk, [[T.t('end', 'headline', 'Closing line', { font: 'display', fw: X.ar ? 800 : 400, up: !X.ar, maxLines: 3, lh: X.ar ? 1.2 : 1 }), story ? 130 : 96], [T.cta(), story ? 42 : 34, { bw: 600 }], [T.contact(), story ? 32 : 28], [T.trust(), 25]], A.x0, wide ? aw * 0.7 : aw, A.y0 + (story ? 300 : 120), bandY - 30, { gap: 22, al: 'l' }));
  }
  return out;
};
C['kbb-carousel'] = (X) => {
  const { W: Wc, H, A, aw, story, sq, wide, T } = X, out = [], n = X.slides, i = X.slide, ox = -i * Wc, PW = Wc * n;
  out.push([art('glow', 'glow', { c1: 'acc', c2: 'acc2', n: 30, pan: true, white: false, a: 0.8 }), G(ox, 0, PW, H)]);
  out.push([art('silk', 'silk', { cols: ['acc', 'acc2', 'card'], pan: true }), G(ox, H * (wide ? 0.58 : 0.64), PW, H * 0.36)]);
  if (i === 0) {
    out.push([X.T.logo(), G(A.x0, A.y0, 300, story ? 84 : 70, null, 'l')]);
    out.push(...stack(X.stk, [[T.kicker(), story ? 28 : 24], [T.headline({ font: 'serif', fw: 700, up: false, ls: 0, lh: 1.05, maxLines: 3 }), story ? 130 : 96], [T.sub(), story ? 36 : 30]], A.x0, wide ? aw * 0.5 : aw, A.y0 + (story ? 160 : 110), H * (wide ? 0.56 : 0.62), { gap: 20, al: 'l' }));
  } else if (i < n - 1 || n === 2) {
    const k = i - 1, r = story ? 80 : 60;
    out.push([art('podium' + i, 'podium', { c1: 'card', ring: 'acc2' }), wide ? G(Wc * 0.5, H * 0.7, Wc * 0.36, H * 0.16) : G(Wc * 0.2, H * 0.6, Wc * 0.6, story ? 150 : 100)]);
    out.push([prodEl(X, k), wide ? prodBox(X, k, Wc * 0.56, H * 0.12, Wc * 0.24, H * 0.62) : prodBox(X, k, Wc * 0.3, A.y0 + (story ? 300 : sq ? 190 : 210), Wc * 0.4, H * 0.6 - A.y0 - (story ? 280 : sq ? 170 : 190))]);
    out.push([art('dot' + i, 'steps', { pts: [[A.x0 + r, A.y0 + r]], r, c1: 'acc' }), G(0, 0, Wc, H)]);
    out.push([T.t('n' + i, 'big', 'Step number', { font: 'display', fw: 400, pc: 'onAcc', maxLines: 1, lh: 1 }), G(A.x0 + r * 0.3, A.y0 + r * 0.3, r * 1.4, r * 1.4, r * 0.95)]);
    out.push([T.t('s' + (k + 1), 'headline', 'Step', { font: 'serif', fw: 700, maxLines: 2 }), wide ? G(A.x0, A.y0 + 220, Wc * 0.42, 0, 110, 'l') : G(A.x0 + r * 2 + 30, A.y0 + r * 0.25, aw - r * 2 - 30, 0, story ? 100 : 76, 'l')]);
  } else {
    out.push([X.T.logo(), G(A.x0, A.y0, 300, story ? 84 : 70, null, 'l')]);
    out.push(...stack(X.stk, [[T.t('s3', 'headline', 'Step', { font: 'serif', fw: 700, maxLines: 2 }), story ? 110 : 84], [T.cta(), story ? 42 : 34, { bw: 660 }], [T.contact(CHIP), story ? 32 : 28], [T.trust(CHIP), 25]], A.x0, wide ? aw * 0.6 : aw, A.y0 + (story ? 180 : 110), H * (wide ? 0.56 : 0.62), { gap: 20, al: 'l' }));
  }
  return out;
};

// ── assembling a design ──
const BG_KINDS = new Set(['flag', 'scene', 'glow', 'rays', 'silk', 'petals', 'confetti', 'leaks', 'frame', 'stripes']);
const MIRROR = (W, [el, g]) => {
  const g2 = { ...g, x: Math.round(W - g.x - g.w) };
  if (g.al === 'l') g2.al = 'r'; else if (g.al === 'r') g2.al = 'l';
  let e = el;
  if (el.t === 'art') {
    e = { ...el };
    if (el.shade === 'left') e.shade = 'right'; else if (el.shade === 'right') e.shade = 'left';
    if (el.side === 'left') e.side = 'right'; else if (el.side === 'right') e.side = 'left';
    if (el.pts) e.pts = el.pts.map(([x, y]) => [W - x, y]);
    if (el.tilt != null) e.tilt = -el.tilt;
    if (el.kind === 'plane') e.flip = true;
  }
  return [e, g2];
};

/**
 * one design from a template. opts: {lang:'en'|'ar', sizes, cc, ccs, spec, copy:{headline,...} (a brief's copy: replaces
 * the template's words), palette, slide, seed, products:[catalogue ids]}
 */
export function makeAd(id, ws, cat = {}, opts = {}) {
  const tpl = adById(id); if (!tpl) throw new Error('No ad template ' + id);
  const lang = opts.lang || 'en', ar = lang === 'ar', spec = opts.spec || null;
  const sizes = (opts.sizes || AD_SIZES).filter((s) => AD_SIZES.includes(s));
  const cc = opts.cc || spec?.countries?.[0] || tpl.cc || null;
  const ccs = (opts.ccs || (spec?.countries?.length > 1 ? spec.countries : null) || tpl.ccs || [cc]).slice(0, 4);
  while (ccs.length < (tpl.id === 'pc-europe' ? 4 : 1)) ccs.push(['de', 'pl', 'nl', 'it'].find((x) => !ccs.includes(x)));
  const country = (cat.countries || []).find((c) => c.code === cc) || null;
  const pids = opts.products || spec?.products || [];
  const products = (pids.length ? pids : (cat.products || []).slice(0, 3).map((p) => p.id)).map((pid) => (cat.products || []).find((p) => p.id === pid)).filter(Boolean)
    .map((p) => ({ id: p.id, name: p.name, brand: p.brand, src: p.src || 'brands/kbb/products/' + p.file, w: p.w || 400, h: p.h || 800 }));
  if (photoOf(ws, 'product') && !pids.length) products.unshift({ id: 'own', name: 'Your product', brand: '', src: photoOf(ws, 'product'), w: 800, h: 1000 });
  const roles = rolesFor(spec);
  const occ = (cat.occupations || []).find((o) => spec?.occupations?.includes(o.id));
  const pal = opts.palette || broadcastPalettes(ws)[tpl.pal || 'deep'];
  const ct = contactOf(ws, lang);
  const words = wordsOf(tpl, lang, { country, product: products[0]?.name, brand: (products[0]?.brand || '').toUpperCase(), roles });
  words.cta = ctaOf(ws, lang, words.cta);
  words.contact = ct.line;
  if (tpl.id === 'pc-europe') ccs.forEach((c, k) => { const C2 = (cat.countries || []).find((x) => x.code === c); words['c' + k] = ar ? C2?.nameAr || c : (C2?.name || c).toUpperCase(); });
  if (tpl.id === 'kbb-routine') for (let k = 0; k < 4; k++) words['n' + k] = String(k + 1);
  if (spec && opts.copy?.cta) words.cta = opts.copy.cta;   // a brief's own button wording wins over the company default
  if (spec && opts.copy) for (const k of ['kicker', 'headline', 'offer', 'sub', 'trust']) if (opts.copy[k] && (k !== 'headline' || !['pc-bulletin', 'pc-story', 'pc-event'].includes(tpl.id))) words[k] = opts.copy[k];
  if (spec?.offer && tpl.id === 'kbb-offer') { const o = spec.offer; if (o.type === 'percent') { words.big = o.value + '%'; } else if (o.type === 'amount') { words.big = String(o.value); words.bigLabel = ar ? 'درهم خصم' : 'AED OFF'; } }
  // the pieces' own words (stamp, boarding pass, crawl, big word)
  const pw = { passport: wordsFor('passport', lang), ticket: wordsFor('ticket', lang), crawl: wordsFor('crawl', lang), bigword: wordsFor('bigword', lang) };
  if (country) { pw.passport.stamp = (country.name || '').toUpperCase(); }
  // carousel slide words
  const slides = tpl.slides || 0, slide = opts.slide || 0;
  if (tpl.id === 'pc-carousel' && slide > 0 && slide < slides - 1) {
    const c2 = (cat.countries || []).find((x) => x.code === ccs[(slide - 1) % ccs.length]);
    words.country = ar ? c2?.nameAr || '' : (c2?.name || '').toUpperCase();
    words.roles = roles.map((r) => (ar ? ROLE_AR[r] : ROLE_EN[r])).join(ar ? ' · ' : ' · ');
  }
  if (tpl.id === 'pc-carousel' && slide === slides - 1) words.end = ar ? 'وظيفتك القادمة تبدأ هنا' : 'YOUR NEXT JOB STARTS HERE';
  if (tpl.id === 'kbb-carousel' && slide > 0) words['n' + slide] = String(slide);
  const fonts = Object.assign(fontsFor(ws), { display: 'Anton' });
  const D = {
    v: 1, id: uid(), name: `${tpl.name}${slides ? ` · ${slide + 1}/${slides}` : ''} · ${ar ? 'عربي' : 'EN'}`, workspace: ws.id, family: tpl.id, ad: { tpl: tpl.id, cc, ccs, slide: slides ? slide : undefined, slides: slides || undefined, set: opts.set },
    lang, copyId: opts.copy?.id || null, paletteId: pal.id, sizes: sizes.slice(), palette: { ...pal }, art: { base: 'linear', angle: 160, seed: opts.seed || 1, layers: [] }, bgImage: null,
    brand: brandOf(ws), fonts, els: [], geo: {}, motion: motionOf(null),
    spec: spec ? { kind: spec.kind, brief: spec.brief, occasion: spec.occasion?.id || null, products: spec.products, countries: spec.countries, occupations: spec.occupations, languages: spec.languages, tone: spec.tone, industry: spec.industry } : { kind: tpl.kinds[0], brief: '', products: products.map((p) => p.id), countries: ccs, occupations: [], languages: [lang], industry: tpl.co === PC ? 'consultancy' : 'beauty' },
    created: new Date().toISOString(),
  };
  const seen = new Map();
  for (const size of sizes) {
    const [Wc, H] = PX[size], A = SAFE[size];
    const X = { size, W: Wc, H, A, aw: A.x1 - A.x0, story: size === 'story', post: size === 'post', sq: size === 'square', wide: size === 'wide', ar, P: pal, txt: words, words: pw, cc, ccs, roles, ws, products, slide, slides,
      workerSrc: occ ? 'library/occupations/' + (occ.figure?.file || 'figures/trades.svg') : null, stk: { measureD: D, textK: opts.textK || 1 } };
    X.T = texts(X);
    let list = C[tpl.id](X).filter(([el]) => el);
    if (ar) list = list.map((x) => MIRROR(Wc, x));
    D.geo[size] = {};
    for (const [el, g] of list) {
      if (!seen.has(el.id)) { seen.set(el.id, el); D.els.push(el); }
      else {
        // what this size draws differently (a shade, a panel side, a path) rides on its geometry: g.o
        const base = seen.get(el.id), o = {};
        for (const k of new Set([...Object.keys(base), ...Object.keys(el)])) if (k !== 'id' && JSON.stringify(base[k]) !== JSON.stringify(el[k])) o[k] = el[k] === undefined ? null : el[k];
        if (Object.keys(o).length) g.o = o;
      }
      D.geo[size][el.id] = g;
    }
  }
  for (const size of sizes) for (const el of D.els) if (!D.geo[size][el.id]) D.geo[size][el.id] = { x: 0, y: 0, w: 1, h: 1, hd: true };
  // one drawing order for every size: backgrounds, panels, art, cards, then words and pictures
  const Z = (el) => el.z ?? (el.t === 'art' ? (BG_KINDS.has(el.kind) ? 0 : el.kind === 'panel' ? 1 : 2) : el.t === 'shape' ? 1.5 : 4);
  D.els = D.els.map((el, i) => [el, i]).sort((a, b) => Z(a[0]) - Z(b[0]) || a[1] - b[1]).map(([el]) => el);
  return D;
}

/** a carousel: n slides of one template, sharing a set id (they read as one design when swiped) */
export function makeCarousel(id, ws, cat, opts = {}) {
  const tpl = adById(id), n = tpl?.slides || 4, set = 'set' + Date.now().toString(36);
  return [...Array(n)].map((_, i) => makeAd(id, ws, cat, { ...opts, slide: i, set }));
}
