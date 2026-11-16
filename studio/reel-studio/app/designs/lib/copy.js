// KBB Studio · Designs · ad copy in English AND Arabic from a spec (pure). Several variants per brief (headline / offer /
// kicker / supporting line / CTA / small print), honest by construction: no number the brief did not give, no promise
// ("guaranteed", "100%"), no medical claim; Planet Careers lines carry "Requirements confirmed at consultation".
// A model's suggestions (spec.copySuggestions, already checked by validateSpec) are used first when there are any.
import { OCCASIONS } from './occasions.js';

const pick = (arr, i) => arr[((i % arr.length) + arr.length) % arr.length];
const joinAnd = (xs, and = '&') => (xs.length <= 1 ? xs[0] || '' : and === 'و' ? xs.slice(0, -1).join('، ') + ' و' + xs[xs.length - 1] : xs.slice(0, -1).join(', ') + ` ${and} ` + xs[xs.length - 1]);
const AR_BRAND = { 'Beauty of Joseon': 'بيوتي أوف جوسون', Anua: 'أنوا', medicube: 'ميديكيوب', "d'Alba": 'دالبا', Biodance: 'بايودانس', numbuzin: 'نمبوزين', celimax: 'سيليماكس', 'Mary&May': 'ماري آند ماي', COSRX: 'كوسركس' };

/** -> [{id, en:{kicker, headline, offer, sub, cta, trust}, ar:{...}}] (≥ 6 variants) */
export function copyVariants(spec, ws = {}, cat = {}) {
  const k = spec.kind, occ = spec.occasion && OCCASIONS.find((o) => o.id === spec.occasion.id);
  const brands = spec.brands.length ? spec.brands : [...new Set((cat.products || []).filter((p) => spec.products.includes(p.id)).map((p) => p.brand))];
  const brandEn = joinAnd(brands), brandAr = joinAnd(brands.map((b) => AR_BRAND[b] || b), 'و');
  const country = (cat.countries || []).find((c) => c.code === spec.countries[0]);
  const cEn = country?.name || '', cAr = country?.nameAr || '';
  const occs = (cat.occupations || []).filter((o) => spec.occupations.includes(o.id));
  const o0 = occs[0];
  const cta = spec.cta || { en: 'Learn more', ar: 'اعرف المزيد' };
  const company = spec.companyName || ws.fullName || ws.name || '';
  const V = [];
  const add = (en, ar) => V.push({ id: 'c' + V.length, en: Object.assign({ cta: cta.en }, en), ar: Object.assign({ cta: cta.ar }, ar) });

  if (spec.copySuggestions?.length) {
    const en = spec.copySuggestions.filter((c) => c.lang === 'en'), ar = spec.copySuggestions.filter((c) => c.lang === 'ar');
    en.forEach((c, i) => add({ headline: c.headline, offer: c.offer || spec.offer?.en || '', cta: c.cta || cta.en, kicker: '', sub: '', trust: '' }, { headline: ar[i]?.headline || ar[0]?.headline || '', offer: ar[i]?.offer || spec.offer?.ar || '', cta: ar[i]?.cta || cta.ar }));
  }

  if (k === 'offer' || k === 'launch' || k === 'brand' || k === 'generic' || k === 'event') {
    const heads = occ ? occ.head : k === 'launch' ? { en: ['Just Landed', 'New In', 'Meet Your New Favourite', 'Fresh From Seoul'], ar: ['وصل حديثاً', 'جديدنا', 'مفضّلتك الجديدة'] }
      : spec.offer ? { en: ['The Glow Sale', 'Treat Your Skin', 'Your Glow, For Less', 'Limited-Time Offer'], ar: ['تخفيضات الإشراقة', 'دلّلي بشرتك', 'عرض لفترة محدودة'] }
        : { en: ['Glow Starts Here', `Discover ${company}`, 'Made for Your Skin'], ar: ['إشراقتك تبدأ هنا', 'اكتشفي ' + company] };
    const off = spec.offer;
    const offerEn = off ? (brandEn ? `${off.en} ${brandEn}` : off.en) : brandEn ? `${brandEn} favourites` : '';
    const offerAr = off ? (brandAr ? `${off.ar} على ${brandAr}` : off.ar) : brandAr ? `مفضّلات ${brandAr}` : '';
    const kick = occ ? [occ.en.toUpperCase(), 'LIMITED TIME', 'THIS WEEK ONLY', 'CELEBRATE IN GLOW'] : ['LIMITED TIME', 'JUST FOR YOU', 'NEW SEASON', 'SHOP NOW'];
    const kickAr = occ ? [occ.ar, 'لفترة محدودة', 'هذا الأسبوع فقط'] : ['لفترة محدودة', 'خصيصاً لكِ'];
    const subs = spec.industry === 'beauty' ? ['Authentic Korean skincare, delivered across the UAE', 'Selected products · while stocks last', 'Original K-beauty, fast UAE delivery'] : ['Selected items · while stocks last'];
    const subsAr = spec.industry === 'beauty' ? ['منتجات كورية أصلية مع توصيل لكل الإمارات', 'منتجات مختارة · حتى نفاد الكمية'] : ['منتجات مختارة · حتى نفاد الكمية'];
    for (let i = 0; i < 6; i++) add(
      { kicker: pick(kick, i), headline: pick(heads.en, i), offer: offerEn, sub: pick(subs, i), trust: off ? 'Selected products · while stocks last' : '' },
      { kicker: pick(kickAr, i), headline: pick(heads.ar, i), offer: offerAr, sub: pick(subsAr, i), trust: off ? 'منتجات مختارة · حتى نفاد الكمية' : '' });
  } else if (k === 'study') {
    const intake = spec.intake ? spec.intake.replace(/^(\w{3}).*/, (m) => ({ Jan: 'January', Feb: 'February', Mar: 'March', Apr: 'April', May: 'May', Jun: 'June', Jul: 'July', Aug: 'August', Sep: 'September', Oct: 'October', Nov: 'November', Dec: 'December' }[m] || m)) : '';
    const heads = cEn ? [`Study in ${cEn}`, `Your Degree in ${cEn}`, `${cEn} Is Calling`, `Start Your Future in ${cEn}`, `Study Abroad: ${cEn}`, `Your Next Chapter: ${cEn}`] : ['Study Abroad', 'Your Degree Abroad', 'Start Your Future'];
    const headsAr = cAr ? [`ادرس في ${cAr}`, `شهادتك من ${cAr}`, `مستقبلك في ${cAr}`, `الدراسة في ${cAr}`] : ['ادرس في الخارج', 'مستقبلك يبدأ هنا'];
    const offEn = intake ? [`${intake} intake now open`, `Applications open for ${intake}`, `${intake} intake · apply now`] : ['Applications now open'];
    const offAr = spec.intakeAr ? [`التسجيل مفتوح لدفعة ${spec.intakeAr}`, `دفعة ${spec.intakeAr} · قدّم الآن`] : ['التسجيل مفتوح الآن'];
    for (let i = 0; i < 6; i++) add(
      { kicker: pick(['STUDY ABROAD', 'ADMISSIONS OPEN', 'BACHELOR & MASTER', 'STUDENT VISA GUIDANCE'], i), headline: pick(heads, i), offer: pick(offEn, i), sub: pick(['Bachelor and master programmes · guidance from application to visa', 'Expert guidance from application to arrival', 'Free first consultation'], i), trust: 'Requirements and terms confirmed at consultation.' },
      { kicker: pick(['الدراسة في الخارج', 'التسجيل مفتوح'], i), headline: pick(headsAr, i), offer: pick(offAr, i), sub: pick(['بكالوريوس وماجستير · إرشاد من التقديم حتى التأشيرة', 'استشارتك الأولى مجانية'], i), trust: 'تُؤكَّد المتطلبات والشروط خلال الاستشارة.' });
  } else if (k === 'hiring') {
    const plEn = o0 ? o0.plural?.en || o0.name.en + 's' : 'Skilled workers', plAr = o0 ? (o0.plural?.ar || o0.name.ar) : 'عمال مهرة';
    const one = o0 ? o0.name.en.toLowerCase() : 'skilled worker';
    const heads = cEn ? [`${plEn} Wanted for ${cEn}`, `Work in ${cEn} as a ${one}`, `We're Hiring ${plEn}`, `${cEn} Needs ${plEn}`, `Your Next Job: ${cEn}`, `${plEn}: Work in ${cEn}`] : [`${plEn} Wanted`, "We're Hiring", `Work Abroad as a ${one}`];
    const headsAr = cAr ? [`مطلوب ${plAr} للعمل في ${cAr}`, `اعمل في ${cAr} كـ${o0?.name.ar || 'عامل'}`, `نوظّف ${plAr}`, `فرصتك في ${cAr}`] : [`مطلوب ${plAr}`, 'نحن نوظّف'];
    for (let i = 0; i < 6; i++) add(
      { kicker: pick(["WE'RE HIRING", 'WORK ABROAD', 'NOW RECRUITING', 'JOBS ABROAD'], i), headline: pick(heads, i), offer: pick(['Salary ‹edit›  ·  Visa ‹edit›', 'Contract ‹edit›  ·  Start ‹edit›', 'Experience ‹edit›'], i), sub: pick(['Bring your skills and experience', 'Work permit guidance from start to finish', 'Interviews by appointment'], i), trust: 'Requirements and terms confirmed at consultation.' },
      { kicker: pick(['نحن نوظّف', 'العمل في الخارج', 'فرص عمل'], i), headline: pick(headsAr, i), offer: pick(['الراتب ‹عدّل›  ·  التأشيرة ‹عدّل›', 'العقد ‹عدّل›  ·  البدء ‹عدّل›'], i), sub: pick(['إرشاد كامل لتصريح العمل', 'المقابلات بموعد مسبق'], i), trust: 'تُؤكَّد المتطلبات والشروط خلال الاستشارة.' });
  } else { // visa / pr / visit
    const heads = cEn ? [`Your Route to ${cEn}`, `${cEn}: Visa Guidance`, `Move to ${cEn}`, `Plan Your ${cEn} Journey`] : ['Your Visa, Guided', 'Plan Your Move Abroad'];
    const headsAr = cAr ? [`طريقك إلى ${cAr}`, `تأشيرة ${cAr}`] : ['تأشيرتك بإرشاد خبير'];
    for (let i = 0; i < 6; i++) add(
      { kicker: pick(['VISA GUIDANCE', 'IMMIGRATION', 'PLAN YOUR MOVE'], i), headline: pick(heads, i), offer: pick(['Expert guidance, step by step', 'Documents, applications and appointments'], i), sub: 'Free first consultation', trust: 'Requirements and terms confirmed at consultation.' },
      { kicker: pick(['إرشاد التأشيرات', 'الهجرة'], i), headline: pick(headsAr, i), offer: pick(['إرشاد خبير خطوة بخطوة', 'المستندات والطلبات والمواعيد'], i), sub: 'استشارتك الأولى مجانية', trust: 'تُؤكَّد المتطلبات والشروط خلال الاستشارة.' });
  }
  if (spec.offer?.code) V.forEach((v) => { v.en.code = 'Code ' + spec.offer.code; v.ar.code = 'كود ' + spec.offer.code; });
  if (spec.missingBrands?.length) V.forEach((v) => { v.missing = spec.missingBrands.slice(); });
  return V;
}

/** claim safety for anything typed into a design later (the editor warns; nothing is changed silently) */
export function claimWarnings(text) {
  const out = [];
  if (/guarante|ضمان|مضمون/i.test(text)) out.push('“Guaranteed” is a promise: say “guidance” or “support” instead.');
  if (/100\s*%/.test(text)) out.push('“100%” reads as a promise; remove it unless it is literally true.');
  if (/\bcure|treats? (acne|eczema)|whiten/i.test(text)) out.push('Medical / whitening claims are not allowed for cosmetics ads.');
  return out;
}
