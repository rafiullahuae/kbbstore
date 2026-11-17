// KBB Studio · Designs · DESIGN-FAMILY AD TEMPLATES (2026-11, the owner: "bulletin templates we have enough ... now make
// totally different careers based, traveling based, occupations based, bright future based designs"). Each template is
// one big family piece (themes/fam.js: a career staircase, a CV that builds itself, a suitcase opening onto the
// destination, a worker in uniform, a sunrise skyline ...) and the company's live words, in one of four compositions
// that look nothing like the bulletin ones (no bands, crawls or "breaking" bars):
//   stage   the piece full-bleed above, the words below on the family's ground
//   card    the piece full-bleed, the words on a floating white card
//   swiss   big left-aligned type, the piece in the middle, the button row under it
//   frame   the piece framed like a picture on the wall, the words above and below
// Same element ids in every size, Arabic mirrors (ads.js does it), facts are placeholders only, contact / button / logo
// / photo slots from ⚙ Configure. ads.js merges these into its catalogue; nothing here changes an existing template.
import { G, stack } from './layouts.js';
import { scheme } from './lib/palette.js';
import { mix } from './lib/color.js';
import { FAM_PAL, FAMILY_LABELS, famWords, tradeOf, TRADES } from '../themes/fam.js';

const PC = 'planet-careers';
const T_ = (id, fam, piece, arch, pal, name, nameAr, kinds, extra = {}) => ({ id, co: PC, fam, piece, arch, pal, name, nameAr, kinds, ...extra });
export const FAM_TEMPLATES = [
  T_('fam-ladder', 'careers', 'ladder', 'stage', 'light', 'Career staircase', 'سلّم المسيرة', ['hiring'], { cc: 'ca' }),
  T_('fam-cv', 'careers', 'cv', 'card', 'light', 'CV that builds itself', 'سيرة ذاتية تُبنى', ['hiring']),
  T_('fam-offer', 'careers', 'offer', 'frame', 'light', 'Job-offer letter', 'خطاب عرض العمل', ['hiring']),
  T_('fam-path', 'careers', 'path', 'swiss', 'light', 'Your next role · 5 steps', 'وظيفتك القادمة · ٥ خطوات', ['hiring', 'visa'], { font: 'Montserrat' }),
  T_('fam-handshake', 'careers', 'handshake', 'stage', 'deep', 'Handshake + contract', 'مصافحة وعقد', ['hiring']),
  T_('fam-route', 'travel', 'route', 'frame', 'light', 'Road-trip route map', 'خريطة الرحلة', ['visit', 'hiring'], { cc: 'ca' }),
  T_('fam-suitcase', 'travel', 'suitcase', 'stage', 'deep', 'Suitcase opens', 'حقيبة تنفتح', ['visit', 'study'], { cc: 'ca' }),
  T_('fam-window', 'travel', 'window', 'card', 'light', 'Plane window view', 'من نافذة الطائرة', ['visit', 'hiring'], { cc: 'gb' }),
  T_('fam-postcards', 'travel', 'postcards', 'swiss', 'deep', 'Postcard stack', 'بطاقات بريدية', ['study', 'visit'], { cc: 'ie' }),
  T_('fam-stamps', 'travel', 'stamps', 'frame', 'light', 'Passport stamp collage', 'جواز مليء بالأختام', ['visa', 'visit'], { cc: 'de' }),
  T_('fam-departures', 'travel', 'departures', 'stage', 'light', 'Departures board', 'لوحة المغادرة', ['hiring', 'visit'], { cc: 'pl', font: 'Montserrat', logo: 'below' }),
  T_('fam-worker', 'occupations', 'worker', 'card', 'light', 'Worker in uniform', 'عامل بزيّه', ['hiring'], { role: 'nurse' }),
  T_('fam-trades', 'occupations', 'trades', 'swiss', 'light', 'Pick your trade', 'اختر مهنتك', ['hiring'], { role: 'welder', font: 'Montserrat' }),
  T_('fam-day', 'occupations', 'day', 'frame', 'light', 'A day in the life', 'يوم في حياة', ['hiring'], { role: 'chef' }),
  T_('fam-morph', 'occupations', 'morph', 'stage', 'deep', 'Uniforms that morph', 'زيّ يتحوّل', ['hiring'], { role: 'electrician' }),
  T_('fam-isosite', 'occupations', 'isosite', 'card', 'light', 'Isometric work site', 'موقع عمل', ['hiring']),
  T_('fam-duotone', 'occupations', 'duotone', 'photo', 'deep', 'Duotone worker photo', 'صورة عامل بلونين', ['hiring'], { role: 'welder' }),
  T_('fam-sunrise', 'future', 'sunrise', 'card', 'deep', 'Sunrise skyline', 'شروق على مدينتك', ['pr', 'study', 'hiring'], { cc: 'au' }),
  T_('fam-door', 'future', 'door', 'stage', 'light', 'A door to a new country', 'باب إلى بلد جديد', ['pr', 'visa'], { cc: 'nz' }),
  T_('fam-tree', 'future', 'tree', 'frame', 'light', 'Tree of goals', 'شجرة الأهداف', ['study', 'pr']),
  T_('fam-home', 'future', 'home', 'stage', 'light', 'Home key + family', 'مفتاح البيت', ['pr', 'visa']),
  T_('fam-grad', 'future', 'grad', 'card', 'light', 'Graduation caps', 'قبعات التخرّج', ['study']),
  T_('fam-stars', 'future', 'stars', 'swiss', 'deep', 'Constellation of goals', 'كوكبة الأهداف', ['study', 'hiring']),
  T_('fam-growth', 'future', 'growth', 'frame', 'light', 'Growth chart', 'مخطط النمو', ['hiring', 'study'], { font: 'Montserrat' }),
  T_('fam-kinetic', 'future', 'kinetic', 'stage', 'light', 'Kinetic words', 'كلمات متحرّكة', ['hiring', 'study'], { logo: 'below' }),
  T_('fam-isocity', 'future', 'isocity', 'swiss', 'light', 'City of tomorrow', 'مدينة الغد', ['pr', 'study'], { cc: 'sg' }),
];
export const FAM_FILTERS = ['careers', 'travel', 'occupations', 'future'].map((f) => [f, FAMILY_LABELS[f]]);

// ── the words (English, Arabic). {c}/{ca}/{C} fill in the country. Placeholders, never facts. ──
const CTA = ['Book a free consultation', 'احجز استشارة مجانية'], WA = ['Apply on WhatsApp', 'قدّم عبر واتساب'];
const TRUST = ['Placeholder details · confirmed at consultation', 'تفاصيل للتعديل · تُؤكَّد عند الاستشارة'];
const w = (kicker, headline, sub, cta = WA, trust = TRUST) => ({ kicker, headline, sub, cta, trust });
export const FAM_W = {
  'fam-ladder': w(['CAREERS ABROAD', 'وظائف في الخارج'], ['CLIMB TO YOUR NEXT ROLE', 'اصعد إلى وظيفتك القادمة'], ['Step by step, with guidance at every stage', 'خطوة بخطوة، مع إرشاد في كل مرحلة']),
  'fam-cv': w(['YOUR CV, READY', 'سيرتك جاهزة'], ['GET YOUR CV JOB-READY', 'جهّز سيرتك للعمل'], ['CV review and interview guidance', 'مراجعة السيرة الذاتية وإرشاد المقابلة'], CTA),
  'fam-offer': w(['WORK ABROAD', 'العمل في الخارج'], ['FROM APPLICATION TO OFFER', 'من الطلب حتى عرض العمل'], ['Roles ‹edit› · employers ‹edit›', 'الوظائف ‹عدّل› · أصحاب العمل ‹عدّل›']),
  'fam-path': w(['YOUR NEXT ROLE', 'وظيفتك القادمة'], ['FIVE STEPS TO WORK ABROAD', 'خمس خطوات للعمل في الخارج'], ['We guide you through each step', 'نرشدك في كل خطوة'], CTA),
  'fam-handshake': w(['JOBS IN {C}', 'وظائف في {ca}'], ['YOUR CONTRACT STARTS HERE', 'عقدك يبدأ من هنا'], ['Employers ‹edit› · roles ‹edit›', 'أصحاب العمل ‹عدّل› · الوظائف ‹عدّل›']),
  'fam-route': w(['YOUR ROUTE TO {C}', 'طريقك إلى {ca}'], ['THE ROAD TO YOUR NEW LIFE', 'الطريق إلى حياتك الجديدة'], ['Visit, study and work routes ‹edit›', 'مسارات الزيارة والدراسة والعمل ‹عدّل›'], CTA),
  'fam-suitcase': w(['PACK FOR {C}', 'جهّز حقيبتك إلى {ca}'], ['YOUR NEXT DESTINATION', 'وجهتك القادمة'], ['Travel and visa guidance', 'إرشاد السفر والتأشيرات'], CTA),
  'fam-window': w(['NEXT STOP · {C}', 'المحطة القادمة · {ca}'], ['THE VIEW FROM YOUR NEW LIFE', 'المنظر من حياتك الجديدة'], ['Jobs ‹edit› · start date ‹edit›', 'الوظائف ‹عدّل› · تاريخ البدء ‹عدّل›']),
  'fam-postcards': w(['STUDY ABROAD', 'الدراسة في الخارج'], ['SEND A POSTCARD FROM {C}', 'أرسل بطاقة من {ca}'], ['Admissions guidance · intake ‹edit›', 'إرشاد القبول · الدفعة ‹عدّل›'], CTA),
  'fam-stamps': w(['VISA GUIDANCE', 'إرشاد التأشيرات'], ['FILL YOUR PASSPORT WITH NEW STAMPS', 'املأ جوازك بأختام جديدة'], ['Visit and work visa guidance', 'إرشاد تأشيرات الزيارة والعمل'], CTA),
  'fam-departures': w(['NOW BOARDING', 'الصعود الآن'], ['YOUR FLIGHT TO WORK IN {C}', 'رحلتك للعمل في {ca}'], ['Roles ‹edit› · start ‹edit›', 'الوظائف ‹عدّل› · البدء ‹عدّل›']),
  'fam-worker': w(['NOW HIRING · {R}', 'مطلوب · {ra}'], ['WEAR YOUR NEW UNIFORM ABROAD', 'ارتدِ زيّك الجديد في الخارج'], ['Skilled roles ‹edit› · contract ‹edit›', 'وظائف مهنية ‹عدّل› · العقد ‹عدّل›']),
  'fam-trades': w(['13 TRADES', '١٣ مهنة'], ['PICK YOUR TRADE', 'اختر مهنتك'], ['Tell us your skills, we guide the rest', 'أخبرنا بمهاراتك ونرشدك في الباقي']),
  'fam-day': w(['A DAY IN THE LIFE', 'يوم في حياة'], ['LIFE AS A {R} ABROAD', 'حياتك {ra} في الخارج'], ['Real days, real work · details ‹edit›', 'أيام حقيقية وعمل حقيقي · التفاصيل ‹عدّل›']),
  'fam-morph': w(['MANY TRADES, ONE STEP', 'مهن كثيرة وخطوة واحدة'], ['WHICH UNIFORM IS YOURS?', 'أي زيّ هو زيّك؟'], ['Roles ‹edit› in several countries', 'وظائف ‹عدّل› في عدة دول']),
  'fam-isosite': w(['SITES ARE HIRING', 'المواقع توظّف'], ['BUILD YOUR FUTURE ABROAD', 'ابنِ مستقبلك في الخارج'], ['Construction & warehouse roles ‹edit›', 'وظائف البناء والمستودعات ‹عدّل›']),
  'fam-duotone': w(['NOW HIRING · {R}', 'مطلوب · {ra}'], ['YOUR SKILLS ARE WANTED', 'مهاراتك مطلوبة'], ['Roles ‹edit› · contract ‹edit›', 'الوظائف ‹عدّل› · العقد ‹عدّل›']),
  'fam-sunrise': w(['A NEW DAY IN {C}', 'يوم جديد في {ca}'], ['YOUR BRIGHT FUTURE STARTS HERE', 'مستقبلك المشرق يبدأ هنا'], ['Study, work and settle guidance', 'إرشاد الدراسة والعمل والاستقرار'], CTA),
  'fam-door': w(['OPEN THE DOOR', 'افتح الباب'], ['A NEW COUNTRY IS WAITING', 'بلد جديد بانتظارك'], ['Residency and visa guidance', 'إرشاد الإقامة والتأشيرات'], CTA),
  'fam-tree': w(['GROW WITH US', 'انمُ معنا'], ['PLANT YOUR FUTURE TODAY', 'ازرع مستقبلك اليوم'], ['Study and career guidance', 'إرشاد الدراسة والمسيرة المهنية'], CTA),
  'fam-home': w(['TOGETHER AGAIN', 'معاً من جديد'], ['A NEW HOME FOR YOUR FAMILY', 'بيت جديد لعائلتك'], ['Family and residency guidance', 'إرشاد لمّ الشمل والإقامة'], CTA),
  'fam-grad': w(['ADMISSIONS OPEN', 'التسجيل مفتوح'], ['GRADUATE ABROAD', 'تخرّج في الخارج'], ['Bachelor & master guidance · intake ‹edit›', 'إرشاد البكالوريوس والماجستير · الدفعة ‹عدّل›'], CTA),
  'fam-stars': w(['DREAM BIG', 'احلم بكبر'], ['CONNECT YOUR GOALS', 'اربط أهدافك'], ['One plan from dream to arrival', 'خطة واحدة من الحلم حتى الوصول'], CTA),
  'fam-growth': w(['YOUR GROWTH', 'نموّك'], ['GROW YOUR CAREER ABROAD', 'طوّر مسيرتك في الخارج'], ['Figures are placeholders · details ‹edit›', 'الأرقام للتعديل · التفاصيل ‹عدّل›']),
  'fam-kinetic': w(['PLANET CAREERS', 'بلانيت كارييرز'], ['YOUR NEXT CHAPTER', 'فصلك القادم'], ['Work, travel and study guidance', 'إرشاد العمل والسفر والدراسة'], CTA),
  'fam-isocity': w(['CITY OF TOMORROW', 'مدينة الغد'], ['BUILD A LIFE IN {C}', 'ابنِ حياتك في {ca}'], ['Study, work and settle guidance', 'إرشاد الدراسة والعمل والاستقرار'], CTA),
};

/** the family's colour scheme (contrast-checked by scheme()): light = its paper, deep = its sky */
export function famPalette(tpl) {
  const F = FAM_PAL[tpl.fam];
  return tpl.pal === 'deep'
    ? scheme('fam-' + tpl.fam + '-deep', FAMILY_LABELS[tpl.fam] + ' · deep', { bg: F.sky, bg2: mix(F.sky, '#000000', 0.35), ink: '#FFFFFF', acc: F.b, acc2: F.a, card: '#FFFFFF', deco: [F.c, F.d] })
    : scheme('fam-' + tpl.fam + '-light', FAMILY_LABELS[tpl.fam] + ' · light', { bg: F.paper, bg2: mix(F.paper, F.a, 0.12), ink: F.ink, acc: F.a, acc2: F.b, card: '#FFFFFF', deco: [F.c, F.d] });
}
/** the trade a template shows: the brief's occupation, else its own */
export const roleOf = (tpl, spec) => (spec?.occupations || []).map(tradeOf).find(Boolean) || tpl.role || 'nurse';
export const roleWords = (role, ar) => (ar ? TRADES[role]?.ar : (TRADES[role]?.en || '').toUpperCase()) || '';
export const pieceWords = (tpl, lang) => famWords(tpl.piece, lang);

// ── the compositions (always drawn left to right; Arabic mirrors them afterwards) ──
/** H = ads.js's builders {art, shape, logoChip, CHIP} */
export function famCompose(tpl, X, H0) {
  let H = H0;
  const { W: Wc, H: Hc, A, aw, story, sq, wide, T } = X, out = [];
  const piece = (g, o = {}) => [H.art('piece', 'fam-' + tpl.piece, { name: X.pieceName, fam: tpl.fam, cc: X.cc, words: X.pieceWords, ...(tpl.role ? { role: X.role } : {}), ...o }), g];
  const fs = (s, p, q, w) => (story ? s : wide ? w : sq ? q : p);
  const all = (o = {}) => [[T.kicker(o.kicker), fs(30, 26, 24, 28)], [T.headline(Object.assign({ maxLines: 3 }, o.head)), fs(112, 90, 76, 104)], [o.noSub ? null : T.sub(o.sub), fs(38, 32, 28, 34)], [T.cta(), fs(42, 36, 34, 36), { bw: fs(760, 660, 640, 560) }], [T.contact(o.contact), fs(32, 28, 26, 28)], [T.trust(o.trust), 25]];
  const font = tpl.font ? { font: 'display', fw: 900, ls: 0 } : {};
  // the logo chip and the card sit over the piece
  H = { ...H, logoChip: (...a) => H0.logoChip(...a).map(([el, g]) => [el.t === 'shape' ? { ...el, z: 2.6 } : el, g]) };
  if (tpl.arch === 'stage' || tpl.arch === 'photo') {
    if (wide) {
      const sx = Wc * 0.47;
      if (tpl.arch === 'photo') out.push(...photo(X, H, G(sx, 0, Wc - sx, Hc))); else out.push(piece(G(sx, 0, Wc - sx, Hc)));
      out.push(...H.logoChip(X, A.x0, A.y0, 260, 70));
      out.push(...stack(X.stk, all({ head: font }), A.x0, sx - A.x0 - 70, A.y0 + 110, A.y1, { gap: 20, al: 'l' }));
      return out;
    }
    const ah = tpl.logo === 'below' ? fs(940, 670, 500) : fs(1080, 730, 520);
    if (tpl.arch === 'photo') out.push(...photo(X, H, G(0, 0, Wc, ah))); else out.push(piece(G(0, 0, Wc, ah)));
    // a piece with its own title bar along the top (the departures board, the kinetic words) keeps the logo under it
    const below = tpl.logo === 'below', lh = story ? 74 : 62;
    if (below) out.push(...H.logoChip(X, (Wc - 260) / 2, ah + fs(24, 18, 14), 260, lh));
    else { out.push(...H.logoChip(X, A.x0, A.y0, 260, lh)); out.push([H.shape('rule', { name: 'Accent rule', fill: 'acc', rad: 6 }), G((Wc - 160) / 2, ah + fs(28, 22, 16), 160, 10)]); }
    out.push(...stack(X.stk, all({ head: font }), A.x0, aw, below ? ah + fs(24, 18, 14) + lh + fs(18, 12, 8) : ah + fs(60, 50, 40), A.y1, { gap: fs(20, 14, 10), al: 'c' }));
    return out;
  }
  if (tpl.arch === 'card') {
    const CK = { pc: 'cardInk' };
    if (wide) {
      const cx = A.x0 - 24, cw = 760;
      out.push(piece(G(cx + cw - 80, 0, Wc - cx - cw + 80, Hc)));
      out.push([H.shape('card', { name: 'Glass card', fill: 'card', rad: 44, shadow: true, op: 96, z: 2.5 }), G(cx, A.y0 - 16, cw, A.y1 - A.y0 + 32)]);
      out.push(...H.logoChip(X, A.x0 + 20, A.y0 + 20, 250, 66));
      out.push(...stack(X.stk, all({ head: { pc: 'cardInk', ...font }, sub: CK, contact: CK, trust: CK }), A.x0 + 24, cw - 96, A.y0 + 112, A.y1 - 24, { gap: 18, al: 'l' }));
      return out;
    }
    const top = fs(1000, 740, 560);
    out.push(piece(G(0, 0, Wc, top + 80)));
    // the logo rides on the card in the tall formats, over the picture in the square one
    const inCard = !sq, ly = inCard ? top + 28 : A.y0, lh = story ? 70 : 62;
    out.push(...H.logoChip(X, inCard ? A.x1 - 24 - 250 : A.x0, ly, 250, lh));
    out.push([H.shape('card', { name: 'Glass card', fill: 'card', rad: 44, shadow: true, op: 96, z: 2.5 }), G(A.x0 - 20, top, aw + 40, Math.min(Hc - 20, A.y1 + 30) - top)]);
    out.push(...stack(X.stk, all({ head: { pc: 'cardInk', ...font }, sub: CK, contact: CK, trust: CK }), A.x0 + 24, aw - 48, inCard ? ly + lh + 18 : top + 26, A.y1 - 4, { gap: fs(18, 12, 8), al: 'l' }));
    return out;
  }
  if (tpl.arch === 'swiss') {
    const head = { up: !X.ar, lh: X.ar ? 1.2 : 0.95, maxLines: 3, ...font };
    if (wide) {
      const sx = Wc * 0.5;
      out.push(piece(G(sx, 0, Wc - sx, Hc)));
      out.push(...H.logoChip(X, A.x0, A.y0, 250, 66));
      out.push(...stack(X.stk, all({ head }), A.x0, sx - A.x0 - 70, A.y0 + 104, A.y1, { gap: 18, al: 'l' }));
      return out;
    }
    const t1 = fs(650, 410, 310), a1 = fs(1250, 990, 750);
    out.push(...H.logoChip(X, A.x1 - 250, A.y0, 250, story ? 70 : 60));
    out.push([T.kicker(), G(A.x0, A.y0 + (story ? 14 : 10), aw - 290, 0, fs(28, 26, 25), 'l')]);
    out.push(...stack(X.stk, [[T.headline(head), fs(124, 100, 82)]], A.x0, aw, A.y0 + fs(100, 86, 76), t1, { gap: 0, al: 'l' }));
    out.push([H.shape('rule', { name: 'Swiss rule', fill: 'ink', rad: 0 }), G(A.x0, t1 + 6, aw, 8)]);
    out.push(piece(G(0, t1 + 24, Wc, a1 - t1 - 24)));
    out.push(...stack(X.stk, [[T.cta(), fs(42, 36, 32), { bw: fs(720, 620, 600) }], [T.contact(), fs(30, 27, 25)], [T.trust(), 25]], A.x0, aw, a1 + fs(26, 20, 14), A.y1, { gap: fs(16, 12, 8), al: 'l' }));
    return out;
  }
  // frame
  if (wide) {
    const fx = Wc * 0.5, fy = A.y0 + 10, fw = A.x1 - fx, fh = A.y1 - fy - 10;
    out.push([H.shape('frame', { name: 'Picture frame', fill: 'card', rad: 40, shadow: true }), G(fx - 18, fy - 18, fw + 36, fh + 36)]);
    out.push(piece(G(fx, fy, fw, fh), { rad: 28 }));
    out.push(...H.logoChip(X, A.x0, A.y0, 250, 66));
    out.push(...stack(X.stk, all({ head: font }), A.x0, fx - A.x0 - 90, A.y0 + 104, A.y1, { gap: 18, al: 'l' }));
    return out;
  }
  const lh = fs(70, 58, 50), t1 = fs(640, 380, 304), f0 = t1 + fs(36, 30, 24), f1 = fs(1220, 980, 760);
  out.push(...H.logoChip(X, (Wc - 250) / 2, A.y0, 250, lh));
  out.push(...stack(X.stk, [[T.kicker(), fs(28, 24, 22)], [T.headline({ maxLines: 2, ...font }), fs(104, 84, 64)]], A.x0, aw, A.y0 + lh + 16, t1, { gap: fs(16, 10, 8), al: 'c' }));
  const fw = Math.min(aw - 40, (f1 - f0) * (sq ? 1.4 : 1.2)), fx = (Wc - fw) / 2;
  out.push([H.shape('frame', { name: 'Picture frame', fill: 'card', rad: 40, shadow: true }), G(fx - 16, f0 - 16, fw + 32, f1 - f0 + 32)]);
  out.push(piece(G(fx, f0, fw, f1 - f0), { rad: 28 }));
  out.push(...stack(X.stk, [[T.sub(), fs(36, 30, 26)], [T.cta(), fs(42, 36, 32), { bw: fs(720, 620, 600) }], [T.contact(), fs(30, 27, 25)], [T.trust(), 25]], A.x0, aw, f1 + fs(40, 32, 24), A.y1, { gap: fs(16, 10, 8), al: 'c' }));
  return out;
}
/** the duotone template: the company's worker photo (or a drawn stand-in) with the family's duotone over it */
function photo(X, H, g) {
  const src = X.ws.adPhotos?.worker || null;
  return [
    [Object.assign({ id: 'photo', t: 'img', role: 'hero', name: 'Worker photo', fit: 'cover', fy: 0.25, slot: 'worker', role2: ['nurse', 'caregiver', 'driver', 'welder', 'chef', 'electrician', 'builder', 'waiter', 'warehouse'].includes(X.role) ? X.role : 'welder', rad: 0, bleed: true, z: 0 }, src ? { src } : {}), g],
    [H.art('piece', 'fam-duotone', { name: X.pieceName, fam: 'occupations', words: {}, z: 3 }), g],
  ];
}
