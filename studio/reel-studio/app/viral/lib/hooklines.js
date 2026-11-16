// KBB Studio · viral · hook lines (pure ESM, runs in node for the checks).
// Ten opening lines from the transcript + the company's industry, in English or Arabic, one per proven hook family:
// curiosity, number, POV, mistake, question, result-first, myth, "Nobody tells you…", "Stop scrolling if…", and one
// industry line (beauty / visa / generic). Every line is short enough to read in the first two seconds.
import { normWord } from '../../auto/lib/audio.js';

export const HOOK_KINDS = ['curiosity', 'number', 'pov', 'mistake', 'question', 'result', 'myth', 'nobody', 'stop', 'industry'];

const STOP_EN = new Set(('a an the and or but if so to of in on at for with from by is are was were be been am i me my we our you your he she it its they them this that these those ' +
  'there here what which who how why when where just really very um uh like okay ok hi hello everyone welcome back today now then also too can will would should could do does did ' +
  'have has had not no yes get got one two three first then after before about into out up down over again more most some any all each every than as only own same both').split(' '));
const STOP_AR = new Set(['في', 'من', 'على', 'الى', 'إلى', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'و', 'او', 'أو', 'ثم', 'كل', 'هو', 'هي', 'انا', 'أنا', 'نحن', 'انت', 'أنت', 'كان', 'يكون', 'لا', 'نعم', 'ما', 'لم', 'لن', 'قد', 'بعد', 'قبل', 'اليوم', 'مرحبا', 'بكم'].map(normWord));

const COUNTRIES = [
  ['germany', 'Germany', 'ألمانيا'], ['canada', 'Canada', 'كندا'], ['uk', 'the UK', 'بريطانيا'], ['britain', 'the UK', 'بريطانيا'], ['australia', 'Australia', 'أستراليا'],
  ['france', 'France', 'فرنسا'], ['italy', 'Italy', 'إيطاليا'], ['spain', 'Spain', 'إسبانيا'], ['portugal', 'Portugal', 'البرتغال'], ['poland', 'Poland', 'بولندا'],
  ['malta', 'Malta', 'مالطا'], ['ireland', 'Ireland', 'أيرلندا'], ['netherlands', 'the Netherlands', 'هولندا'], ['usa', 'the USA', 'أمريكا'], ['america', 'the USA', 'أمريكا'],
  ['newzealand', 'New Zealand', 'نيوزيلندا'], ['hungary', 'Hungary', 'المجر'], ['lithuania', 'Lithuania', 'ليتوانيا'], ['czechia', 'Czechia', 'التشيك'], ['uae', 'the UAE', 'الإمارات'],
  [normWord('ألمانيا'), 'Germany', 'ألمانيا'], [normWord('كندا'), 'Canada', 'كندا'], [normWord('بريطانيا'), 'the UK', 'بريطانيا'], [normWord('فرنسا'), 'France', 'فرنسا'], [normWord('إيطاليا'), 'Italy', 'إيطاليا'],
];
const PRODUCTS = [['serum', 'serum', 'السيروم'], ['cleanser', 'cleanser', 'الغسول'], ['sunscreen', 'sunscreen', 'واقي الشمس'], ['spf', 'sunscreen', 'واقي الشمس'], ['toner', 'toner', 'التونر'],
  ['cream', 'cream', 'الكريم'], ['moisturizer', 'moisturiser', 'المرطب'], ['moisturiser', 'moisturiser', 'المرطب'], ['mask', 'mask', 'الماسك'], ['essence', 'essence', 'الإسنس'], ['lipstick', 'lipstick', 'أحمر الشفاه'],
  [normWord('السيروم'), 'serum', 'السيروم'], [normWord('سيروم'), 'serum', 'السيروم'], [normWord('كريم'), 'cream', 'الكريم'], [normWord('غسول'), 'cleanser', 'الغسول']];
const NUMWORDS = { one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9, ten: 10, hundreds: 100, hundred: 100 };

export const isArabic = (s) => /[؀-ۿ]/.test(s || '');

/** 'beauty' | 'visa' | 'generic' from a workspace (its kit industries, or its free-text industry) */
export function industryOf(ws) {
  const s = [...(ws?.industries || []), ws?.industry || '', ws?.name || ''].join(' ').toLowerCase();
  if (/immigra|visa|study|consult|education|career/.test(s)) return 'visa';
  if (/beauty|cosmetic|skin|makeup|k-beauty|salon|spa/.test(s)) return 'beauty';
  return 'generic';
}

/** what the clip is about: top content words, numbers, a country, a product */
export function topicOf(words = []) {
  const freq = new Map(); let num = null, country = null, product = null;
  words.forEach((w, i) => {
    const raw = String(w.w || '').trim(), n = normWord(raw);
    if (!n) return;
    if (num == null) { const d = /\d+/.exec(raw); if (d) num = +d[0]; else if (NUMWORDS[n] && n !== 'one') num = NUMWORDS[n]; }
    const c = COUNTRIES.find((x) => x[0] === n) || (n === 'new' && normWord(words[i + 1]?.w) === 'zealand' ? COUNTRIES.find((x) => x[0] === 'newzealand') : null);
    if (c && !country) country = c;
    const p = PRODUCTS.find((x) => x[0] === n); if (p && !product) product = p;
    if (n.length < 3 || STOP_EN.has(n) || STOP_AR.has(n) || /^\d+$/.test(n)) return;
    freq.set(n, (freq.get(n) || 0) + 1 + (i < 25 ? 0.25 : 0));
  });
  const top = [...freq].sort((a, b) => b[1] - a[1]).map(([k]) => k);
  const byNorm = new Map(); for (const w of words) { const n = normWord(w.w); if (!byNorm.has(n)) byNorm.set(n, String(w.w).trim().replace(/[.,!?؟،]+$/, '')); }
  return { keywords: top.slice(0, 6).map((k) => byNorm.get(k) || k), num, country, product };
}

const pick = (arr, seed) => arr[Math.abs(seed) % arr.length];

/**
 * Ten hook lines. input = { words:[{w,t0,t1}], workspace, lang:'en'|'ar'|'auto', seed }
 * -> [{ id, kind, text, lang, accent (the word to highlight), score }]
 */
export function hookLines({ words = [], workspace = null, lang = 'auto', seed = 0 } = {}) {
  const text = words.map((w) => w.w).join(' ');
  const L = lang === 'auto' || !lang ? (isArabic(text) ? 'ar' : 'en') : lang;
  const ind = industryOf(workspace);
  const t = topicOf(words);
  const en = L === 'en';
  const kw = t.keywords[0] || (ind === 'beauty' ? 'skincare' : ind === 'visa' ? 'visas' : 'this');
  const prod = t.product ? (en ? t.product[1] : t.product[2]) : (ind === 'beauty' ? (en ? 'skincare' : 'العناية بالبشرة') : (en ? kw : kw));
  const country = t.country ? (en ? t.country[1] : t.country[2]) : (en ? 'Europe' : 'أوروبا');
  const n = t.num && t.num < 1000 ? t.num : (ind === 'visa' ? 3 : 7);
  const topic = ind === 'visa' ? (en ? `studying in ${country}` : `الدراسة في ${country}`) : ind === 'beauty' ? prod : kw;
  const T = en ? {
    curiosity: [ind === 'visa' ? `What studying in ${country} really takes` : `I tried ${ind === 'beauty' ? 'this ' + prod : topic} so you don't have to`, `This changed everything about ${topic}`, `The ${topic} secret nobody shares`],
    number: [`${n} things I wish I knew about ${topic}`, `${n} seconds that will change your ${ind === 'beauty' ? 'routine' : 'plans'}`, `${n} mistakes with ${topic}`],
    pov: [`POV: you finally found the right ${ind === 'beauty' ? prod : ind === 'visa' ? 'consultant' : kw}`, `POV: ${ind === 'visa' ? `your ${country} visa just got approved` : 'your skin after 7 days'}`],
    mistake: ind === 'visa' ? [`The ${country} visa mistake everyone makes`, `Stop making this visa mistake`] : ind === 'beauty' ? [`Stop making this ${prod} mistake`, `You're using your ${prod} wrong`] : [`Stop making this ${kw} mistake`, `You're doing ${kw} wrong`],
    question: [`Want ${ind === 'visa' ? `to study in ${country}` : ind === 'beauty' ? 'glass skin' : `better ${kw}`}?`, `Why does nobody talk about ${topic}?`],
    result: [ind === 'visa' ? `Approved in weeks — here's how` : ind === 'beauty' ? `Brighter skin in ${n} days — here's how` : `Here's the result first`, `Watch the result first`],
    myth: [`Myth: ${ind === 'visa' ? 'you need to be rich to study abroad' : ind === 'beauty' ? 'expensive means better' : `${kw} is hard`}`, `The biggest ${topic} myth`],
    nobody: [`Nobody tells you this about ${topic}`, `Nobody tells you this before ${ind === 'visa' ? 'you apply' : 'you buy'}`],
    stop: [`Stop scrolling if you ${ind === 'visa' ? `want to move to ${country}` : ind === 'beauty' ? 'have dull skin' : `care about ${kw}`}`, `Stop scrolling — this is for you`],
    industry: ind === 'visa' ? [`Visa rejected? Watch this first`, `Your ${country} visa checklist in 30 seconds`, `The document that gets visas refused`]
      : ind === 'beauty' ? [`The ${prod} my skin can't live without`, `Glass skin in ${n} days?`, `K-beauty's best-kept secret`]
        : [`Save this before it's gone`, `The ${kw} hack you need`],
  } : {
    curiosity: [`جربت ${topic} عشان ما تحتاج تجرب`, `هذا غيّر كل شي في ${topic}`, `سر ${topic} اللي ما أحد يقوله`],
    number: [`${n} أشياء تمنيت أعرفها عن ${topic}`, `${n} أخطاء في ${topic}`],
    pov: [`لما تلاقي أخيرًا ${ind === 'beauty' ? prod : ind === 'visa' ? 'المستشار الصح' : kw} المناسب`, ind === 'visa' ? `لما تنقبل فيزتك إلى ${country}` : `بشرتك بعد ٧ أيام`],
    mistake: [`لا تسوي هذا الغلط في ${topic}`, `أنت تسوي ${topic} غلط`],
    question: [ind === 'visa' ? `تبغى تدرس في ${country}؟` : ind === 'beauty' ? `تبغين بشرة زجاجية؟` : `تبغى ${kw} أفضل؟`, `ليش ما أحد يتكلم عن ${topic}؟`],
    result: [ind === 'visa' ? `انقبلت خلال أسابيع — وهذي الطريقة` : ind === 'beauty' ? `بشرة أنضر في ${n} أيام — وهذي الطريقة` : `شوف النتيجة أول`, `النتيجة أولًا`],
    myth: [ind === 'visa' ? `خرافة: لازم تكون غني عشان تدرس برا` : ind === 'beauty' ? `خرافة: الغالي دايمًا أفضل` : `خرافة: ${kw} صعب`, `أكبر خرافة عن ${topic}`],
    nobody: [`ما أحد يقول لك هذا عن ${topic}`, `محد يقولك هذا قبل ${ind === 'visa' ? 'ما تقدّم' : 'ما تشتري'}`],
    stop: [`وقّف التمرير إذا ${ind === 'visa' ? `تبغى تسافر ${country}` : ind === 'beauty' ? 'بشرتك باهتة' : `يهمك ${kw}`}`, `لا تمرر — هذا لك`],
    industry: ind === 'visa' ? [`انرفضت فيزتك؟ شوف هذا أول`, `أوراق فيزا ${country} في ٣٠ ثانية`] : ind === 'beauty' ? [`${prod} اللي بشرتي ما تستغني عنه`, `سر الكوري للبشرة الزجاجية`] : [`احفظ هذا قبل لا يروح`, `حيلة ${kw} اللي تحتاجها`],
  };
  const out = HOOK_KINDS.map((kind, k) => {
    const line = pick(T[kind], seed + k);
    const accent = (/\d+/.exec(line) || [])[0] || (en ? (line.match(/\b(secret|mistake|wrong|myth|nobody|stop|approved|result|first|glass skin|rejected)\b/i) || [])[0] : (line.match(/(سر|غلط|خرافة|وقّف|النتيجة|انقبلت|انرفضت)/) || [])[0]) || '';
    // a light score: numbers, "you", questions and industry words read best in the first second
    const score = +(0.5 + (/\d/.test(line) ? 0.15 : 0) + (/\byou|your\b|تبغى|لك/i.test(line) ? 0.1 : 0) + (/[?؟]/.test(line) ? 0.08 : 0) + (kind === 'industry' ? 0.1 : 0) - Math.max(0, line.length - 42) * 0.01).toFixed(3);
    return { id: 'h' + k, kind, text: line, lang: L, accent, score: +score };
  });
  return out;
}
