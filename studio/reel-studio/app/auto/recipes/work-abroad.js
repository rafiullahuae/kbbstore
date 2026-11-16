// KBB Studio · recipes · WORK ABROAD (owner, 16 Nov 2026: "not just for study abroad, i need work abroad also, for
// different occupations ... focus more on the images, specially the pictures of the workers"). Plain data in the shape
// recipes.js documents; recipes.js appends these to RECIPES. Every recipe is image-first (worker photos / clips in
// uniform: the 📷 Photos tab saves them into library/people/<occupation>/), and honest: salary, visa type, contract and
// requirements are never stated as fact -- the hints say "‹edit›" and the end card says they are confirmed at consultation.
const P = (part, share, hint, overlay) => ({ part, share, hint, ...(overlay ? { overlay } : {}) });
const WA = { workspaces: ['planet-careers'], industries: ['immigration', 'recruitment', 'consultancy', 'manpower'] };
const CTA = {
  cta: ['Apply now — WhatsApp us', 'Book your free assessment', 'Requirements and terms confirmed at consultation'],
  ctaAr: ['قدّم الآن — راسلنا واتساب', 'احجز تقييمك المجاني', 'الشروط والمتطلبات تُؤكد عند الاستشارة'],
};
const KW = {
  en: ['work', 'job', 'jobs', 'hiring', 'vacancy', 'vacancies', 'abroad', 'permit', 'employer', 'salary', 'contract', 'visa', 'apply', 'worker', 'workers', 'uniform'],
  ar: ['عمل', 'وظيفه', 'وظائف', 'مطلوب', 'توظيف', 'الخارج', 'تصريح', 'صاحب', 'راتب', 'عقد', 'فيزا', 'تقديم', 'عمال'],
};
const kw = (en, ar) => ({ en: [...KW.en, ...en], ar: [...KW.ar, ...ar] });
const r = (o) => Object.assign({
  ...WA, aspect: '9:16', lengths: [15, 30, 60], defaultLength: 30, family: 'work-abroad', imageFirst: true,
  pacing: { shot: [1.2, 3], cutOnBeat: true, punchIn: 0.5 },
  captionStyle: 'bold-outline', captionStyleAr: 'arabic-first',
  effects: { transitions: ['whip', 'slide'], sfx: { transition: 'whoosh', pop: 'pop', accent: 'ding' }, emoji: true, priceTag: false, logoIntro: true, logoOutro: true },
  overlays: ['flag'], endCard: { template: 'pc-consult', ...CTA }, scene: { motion: 'mid', faces: true, cuts: 'some' },
  media: { people: 'library/people/<occupation>/', stills: 'ken-burns' },
}, o);

export const WORK_ABROAD_RECIPES = [
  r({ id: 'wa-we-are-hiring', name: 'Work abroad · We’re hiring', nameAr: 'مطلوب للعمل في الخارج', lengths: [15, 30], defaultLength: 15,
    structure: [P('hook', .15, '"We’re hiring <occupation>s for <country>!" over the best worker photo, big', 'title'), P('workers', .45, 'Worker photos in uniform, one per beat, each with the job title', 'lower-third'), P('details', .2, 'Salary / visa type / contract as ‹edit› badges — only what you can confirm', 'facts'), P('cta', .2, 'Apply / WhatsApp + "terms confirmed at consultation"', 'endcard')],
    pacing: { shot: [0.8, 2], cutOnBeat: true, punchIn: 0.7 }, captionStyle: 'pop-in',
    music: { moods: ['upbeat-pop', 'inspiring'], energy: 'high' },
    effects: { transitions: ['whip', 'flash'], sfx: { transition: 'whoosh', pop: 'pop', accent: 'success-chime' }, emoji: true, priceTag: false, logoIntro: true, logoOutro: true },
    overlays: ['flag', 'landmark', 'facts'], keywords: kw(['hiring', 'now', 'urgent', 'openings', 'positions', 'needed', 'required'], ['مطلوب', 'عاجل', 'فرص', 'شواغر']) }),
  r({ id: 'wa-job-spotlight', name: 'Work abroad · Job spotlight', nameAr: 'وظيفة تحت الضوء',
    structure: [P('hook', .12, 'The occupation’s name + flag: "Welder in Poland"', 'title'), P('job', .4, 'What the work looks like: uniform, tools, the workplace (photos or clips)'), P('fit', .28, 'Who it suits: skills, experience ‹edit›', 'checklist'), P('cta', .2, 'Check if you qualify', 'endcard')],
    captionStyle: 'highlight-marker', music: { moods: ['corporate', 'inspiring'], energy: 'mid' },
    overlays: ['flag', 'lower-third', 'checklist'], keywords: kw(['spotlight', 'role', 'skills', 'experience', 'tools', 'duties', 'shift'], ['مهارات', 'خبره', 'مهام', 'دوام']) }),
  r({ id: 'wa-day-at-work', name: 'Work abroad · A day at work', nameAr: 'يوم في العمل بالخارج', defaultLength: 30,
    structure: [P('hook', .12, 'Morning in <country>: "A day as a <occupation> abroad"', 'title'), P('morning', .25, 'Getting ready, uniform on, the commute'), P('work', .38, 'On the job: real tasks, tools, the team'), P('after', .1, 'After work: the city, the landmark'), P('cta', .15, 'Your day could look like this', 'endcard')],
    pacing: { shot: [1.5, 3.5], cutOnBeat: true, punchIn: 0.4 }, captionStyle: 'typewriter',
    music: { moods: ['lo-fi', 'travel'], energy: 'mid' }, effects: { transitions: ['dissolve', 'slide'], sfx: { transition: 'swoosh', pop: 'click', accent: 'ding' }, emoji: true, priceTag: false, logoIntro: false, logoOutro: true },
    overlays: ['flag', 'landmark'], scene: { motion: 'mid', faces: true, cuts: 'many' },
    keywords: kw(['day', 'morning', 'shift', 'routine', 'life', 'vlog', 'commute', 'team'], ['يوم', 'صباح', 'روتين', 'حياه', 'فريق']) }),
  r({ id: 'wa-home-to-abroad', name: 'Work abroad · From home to abroad', nameAr: 'من بلدك إلى الخارج', defaultLength: 60,
    structure: [P('hook', .12, '"From <home city> to <country>" — before / after worker photo', 'title'), P('before', .22, 'Where they started (with permission)'), P('journey', .26, 'The steps: assessment, documents, permit, travel', 'step-counter'), P('now', .22, 'Working abroad now, in uniform'), P('cta', .18, 'Start your own journey', 'endcard')],
    pacing: { shot: [2, 4.5], cutOnBeat: true, punchIn: 0.4 }, captionStyle: 'word-color',
    music: { moods: ['cinematic', 'inspiring'], energy: 'mid' }, effects: { transitions: ['dissolve', 'leak'], sfx: { transition: 'swoosh', pop: 'pop', accent: 'success-chime' }, emoji: true, priceTag: false, logoIntro: true, logoOutro: true },
    overlays: ['flag', 'step-counter'], scene: { motion: 'low', faces: true, cuts: 'few' },
    keywords: kw(['story', 'journey', 'home', 'dream', 'family', 'arrived', 'landed', 'finally', 'started'], ['قصه', 'رحله', 'حلم', 'عائله', 'وصلت', 'بدات']) }),
  r({ id: 'wa-work-permit-docs', name: 'Work abroad · Work permit documents', nameAr: 'مستندات تصريح العمل',
    structure: [P('hook', .15, '"Applying for a work permit? Get these ready" over a worker photo', 'title'), P('list', .6, 'Tick each document on screen — the list depends on country and employer ‹edit›', 'checklist'), P('cta', .25, 'We confirm your exact list at consultation', 'endcard')],
    pacing: { shot: [1.5, 3], cutOnBeat: true, punchIn: 0.3 }, captionStyle: 'minimal',
    music: { moods: ['corporate', 'lo-fi'], energy: 'mid' }, effects: { transitions: ['slide'], sfx: { transition: 'swipe', pop: 'click', accent: 'success-chime' }, emoji: true, priceTag: false, logoIntro: false, logoOutro: true },
    overlays: ['checklist', 'flag'], keywords: kw(['documents', 'passport', 'certificate', 'experience', 'letter', 'medical', 'police', 'clearance', 'attested', 'cv'], ['مستندات', 'جواز', 'شهاده', 'خبره', 'فحص', 'طبي', 'حسن', 'سيره']) }),
  r({ id: 'wa-interview-tips', name: 'Work abroad · Interview tips', nameAr: 'نصائح مقابلة العمل',
    structure: [P('hook', .12, '"3 things employers ask <occupation>s"', 'title'), P('tips', .66, 'One tip per beat, numbered, with a worker photo or a clip of you speaking', 'step-counter'), P('cta', .22, 'Practise with us — book a session', 'endcard')],
    pacing: { shot: [2, 4], cutOnBeat: true, punchIn: 0.5 }, captionStyle: 'highlight-marker',
    music: { moods: ['lo-fi', 'corporate'], energy: 'mid' }, overlays: ['step-counter'], scene: { motion: 'low', faces: true, cuts: 'some' },
    keywords: kw(['interview', 'tips', 'questions', 'employer', 'trade', 'test', 'answer', 'prepare', 'english'], ['مقابله', 'نصائح', 'اسئله', 'اختبار', 'تحضير']) }),
];
