// KBB Studio · Planet Careers country packs (data). Flags: brands/planet-careers/countries/flags/<code>.svg
// (flag-icons, MIT); landmark silhouettes: countries/landmarks.js (original); maps: Natural Earth (public domain).
//
// facts: neutral, checkable basics only (capital, currency, languages). Everything about visas, fees, intakes,
// salaries or rules is an EDITABLE PLACEHOLDER — the owner must verify it on the official government / embassy site
// before publishing. Nothing legal is baked in.
const VERIFY = 'Verify current requirements on the official government / embassy website before publishing.';
const VERIFY_AR = 'تحقق من المتطلبات الحالية على الموقع الرسمي للحكومة أو السفارة قبل النشر.';
const C = (code, name, nameAr, colors, landmark, capital, currency, languages, cities = [], citiesAr = [], extra = {}) => ({
  code, name, nameAr, colors, landmark, routes: extra.routes || ['study', 'work', 'pr', 'visit'], region: extra.region || 'europe',
  flag: `brands/planet-careers/countries/flags/${code}.svg`, map: `brands/planet-careers/countries/maps/${code}.svg`,
  facts: {
    capital, currency, languages,
    editable: [
      { label: 'Popular intakes', value: '<edit: e.g. Sep / Jan>' },
      { label: 'Typical study route', value: '<edit>' },
      { label: 'Work while studying', value: '<edit after checking the official rules>' },
      { label: 'Post-study options', value: '<edit after checking the official rules>' },
    ],
    note: VERIFY, noteAr: VERIFY_AR,
  },
  keywords: { en: [name.toLowerCase(), ...name.toLowerCase().split(' '), ...cities.map(c => c.toLowerCase())], ar: [nameAr, ...citiesAr] },
});

export const COUNTRIES = [
  C('gb', 'United Kingdom', 'المملكة المتحدة', ['#012169', '#C8102E', '#FFFFFF'], 'big-ben', 'London', 'Pound sterling (GBP)', 'English', ['uk', 'britain', 'england', 'london', 'manchester', 'scotland', 'edinburgh', 'birmingham'], ['بريطانيا', 'لندن', 'انجلترا', 'مانشستر']),
  C('ca', 'Canada', 'كندا', ['#D80621', '#FFFFFF', '#D80621'], 'cn-tower', 'Ottawa', 'Canadian dollar (CAD)', 'English, French', ['toronto', 'vancouver', 'montreal', 'calgary', 'ontario'], ['تورنتو', 'فانكوفر', 'مونتريال'], { region: 'north-america' }),
  C('au', 'Australia', 'أستراليا', ['#00008B', '#FFFFFF', '#E4002B'], 'opera-house', 'Canberra', 'Australian dollar (AUD)', 'English', ['sydney', 'melbourne', 'brisbane', 'perth', 'adelaide'], ['سيدني', 'ملبورن', 'استراليا'], { region: 'oceania' }),
  C('nz', 'New Zealand', 'نيوزيلندا', ['#00247D', '#CC142B', '#FFFFFF'], 'sky-tower', 'Wellington', 'New Zealand dollar (NZD)', 'English, Māori, NZ Sign Language', ['auckland', 'wellington', 'christchurch', 'zealand'], ['اوكلاند'], { region: 'oceania' }),
  C('us', 'United States', 'الولايات المتحدة', ['#0A3161', '#B31942', '#FFFFFF'], 'liberty', 'Washington, D.C.', 'US dollar (USD)', 'English', ['usa', 'america', 'states', 'new york', 'california', 'texas', 'boston'], ['امريكا', 'نيويورك', 'كاليفورنيا'], { region: 'north-america' }),
  C('ie', 'Ireland', 'أيرلندا', ['#169B62', '#FFFFFF', '#FF883E'], 'hapenny-bridge', 'Dublin', 'Euro (EUR)', 'Irish, English', ['dublin', 'cork', 'galway', 'irish'], ['دبلن', 'ايرلندا']),
  C('de', 'Germany', 'ألمانيا', ['#000000', '#DD0000', '#FFCE00'], 'brandenburg-gate', 'Berlin', 'Euro (EUR)', 'German', ['berlin', 'munich', 'hamburg', 'frankfurt', 'german', 'deutschland', 'blocked', 'chancenkarte'], ['برلين', 'ميونخ', 'هامبورغ', 'فرانكفورت', 'المانيا']),
  C('fr', 'France', 'فرنسا', ['#002654', '#FFFFFF', '#CE1126'], 'eiffel', 'Paris', 'Euro (EUR)', 'French', ['paris', 'lyon', 'marseille', 'french', 'campus'], ['باريس', 'ليون']),
  C('nl', 'Netherlands', 'هولندا', ['#AE1C28', '#FFFFFF', '#21468B'], 'windmill', 'Amsterdam', 'Euro (EUR)', 'Dutch', ['amsterdam', 'rotterdam', 'holland', 'dutch', 'utrecht', 'eindhoven'], ['امستردام', 'روتردام']),
  C('it', 'Italy', 'إيطاليا', ['#009246', '#FFFFFF', '#CE2B37'], 'pisa', 'Rome', 'Euro (EUR)', 'Italian', ['rome', 'milan', 'florence', 'bologna', 'italian', 'turin'], ['روما', 'ميلانو', 'ايطاليا']),
  C('es', 'Spain', 'إسبانيا', ['#AA151B', '#F1BF00', '#AA151B'], 'sagrada', 'Madrid', 'Euro (EUR)', 'Spanish', ['madrid', 'barcelona', 'valencia', 'seville', 'spanish'], ['مدريد', 'برشلونه', 'اسبانيا']),
  C('pt', 'Portugal', 'البرتغال', ['#006600', '#FF0000', '#FFFF00'], 'belem', 'Lisbon', 'Euro (EUR)', 'Portuguese', ['lisbon', 'porto', 'portuguese', 'd7', 'coimbra'], ['لشبونه', 'بورتو', 'البرتغال']),
  C('pl', 'Poland', 'بولندا', ['#FFFFFF', '#DC143C', '#DC143C'], 'palace-culture', 'Warsaw', 'Złoty (PLN)', 'Polish', ['warsaw', 'krakow', 'polish', 'wroclaw', 'gdansk'], ['وارسو', 'كراكوف', 'بولندا']),
  C('mt', 'Malta', 'مالطا', ['#FFFFFF', '#CF142B', '#CF142B'], 'valletta-dome', 'Valletta', 'Euro (EUR)', 'Maltese, English', ['valletta', 'maltese', 'gozo', 'sliema'], ['فاليتا', 'مالطا']),
  C('hu', 'Hungary', 'المجر', ['#CE2939', '#FFFFFF', '#477050'], 'parliament-hu', 'Budapest', 'Forint (HUF)', 'Hungarian', ['budapest', 'hungarian', 'debrecen', 'stipendium', 'hungaricum'], ['بودابست', 'هنغاريا']),
  C('cz', 'Czechia', 'التشيك', ['#11457E', '#FFFFFF', '#D7141A'], 'prague-tower', 'Prague', 'Czech koruna (CZK)', 'Czech', ['prague', 'czech', 'republic', 'brno'], ['براغ', 'التشيك']),
  C('lt', 'Lithuania', 'ليتوانيا', ['#FDB913', '#006A44', '#C1272D'], 'gediminas', 'Vilnius', 'Euro (EUR)', 'Lithuanian', ['vilnius', 'kaunas', 'lithuanian'], ['فيلنيوس', 'ليتوانيا']),
  C('lv', 'Latvia', 'لاتفيا', ['#9E3039', '#FFFFFF', '#9E3039'], 'riga-spire', 'Riga', 'Euro (EUR)', 'Latvian', ['riga', 'latvian'], ['ريغا', 'لاتفيا']),
  C('se', 'Sweden', 'السويد', ['#006AA7', '#FECC02', '#006AA7'], 'stockholm-hall', 'Stockholm', 'Swedish krona (SEK)', 'Swedish', ['stockholm', 'gothenburg', 'swedish', 'uppsala', 'lund'], ['ستوكهولم', 'السويد']),
  C('fi', 'Finland', 'فنلندا', ['#FFFFFF', '#002F6C', '#FFFFFF'], 'helsinki-cathedral', 'Helsinki', 'Euro (EUR)', 'Finnish, Swedish', ['helsinki', 'finnish', 'tampere', 'espoo'], ['هلسنكي', 'فنلندا']),
  C('dk', 'Denmark', 'الدنمارك', ['#C8102E', '#FFFFFF', '#C8102E'], 'nyhavn', 'Copenhagen', 'Danish krone (DKK)', 'Danish', ['copenhagen', 'danish', 'aarhus'], ['كوبنهاغن', 'الدنمارك']),
  C('at', 'Austria', 'النمسا', ['#C8102E', '#FFFFFF', '#C8102E'], 'riesenrad', 'Vienna', 'Euro (EUR)', 'German', ['vienna', 'austrian', 'salzburg', 'graz', 'innsbruck'], ['فيينا', 'النمسا']),
  C('be', 'Belgium', 'بلجيكا', ['#000000', '#FDDA24', '#EF3340'], 'atomium', 'Brussels', 'Euro (EUR)', 'Dutch, French, German', ['brussels', 'belgian', 'antwerp', 'ghent', 'leuven'], ['بروكسل', 'بلجيكا']),
  C('cy', 'Cyprus', 'قبرص', ['#FFFFFF', '#D57800', '#4E5B31'], 'aphrodite-rock', 'Nicosia', 'Euro (EUR)', 'Greek, Turkish', ['nicosia', 'limassol', 'larnaca', 'cypriot', 'paphos'], ['نيقوسيا', 'ليماسول', 'قبرص']),
  C('gr', 'Greece', 'اليونان', ['#0D5EAF', '#FFFFFF', '#0D5EAF'], 'parthenon', 'Athens', 'Euro (EUR)', 'Greek', ['athens', 'greek', 'thessaloniki', 'golden'], ['اثينا', 'اليونان']),
  C('ch', 'Switzerland', 'سويسرا', ['#DA291C', '#FFFFFF', '#DA291C'], 'matterhorn', 'Bern', 'Swiss franc (CHF)', 'German, French, Italian, Romansh', ['zurich', 'geneva', 'swiss', 'bern', 'lausanne'], ['زيورخ', 'جنيف', 'سويسرا']),
  C('ae', 'United Arab Emirates', 'الإمارات', ['#00732F', '#FFFFFF', '#FF0000'], 'burj-khalifa', 'Abu Dhabi', 'UAE dirham (AED)', 'Arabic', ['uae', 'dubai', 'abu', 'dhabi', 'sharjah', 'emirates', 'golden', 'visa'], ['الامارات', 'دبي', 'ابوظبي', 'الشارقه', 'الذهبيه'], { region: 'middle-east' }),
  C('jp', 'Japan', 'اليابان', ['#FFFFFF', '#BC002D', '#FFFFFF'], 'fuji-torii', 'Tokyo', 'Japanese yen (JPY)', 'Japanese', ['tokyo', 'osaka', 'kyoto', 'japanese', 'mext'], ['طوكيو', 'اوساكا', 'اليابان'], { region: 'asia' }),
  C('kr', 'South Korea', 'كوريا الجنوبية', ['#FFFFFF', '#CD2E3A', '#0047A0'], 'seoul-tower', 'Seoul', 'South Korean won (KRW)', 'Korean', ['korea', 'seoul', 'busan', 'korean', 'gks', 'topik'], ['كوريا', 'سيول', 'بوسان'], { region: 'asia' }),
  C('sg', 'Singapore', 'سنغافورة', ['#EF3340', '#FFFFFF', '#EF3340'], 'marina-bay', 'Singapore', 'Singapore dollar (SGD)', 'English, Malay, Mandarin, Tamil', ['singapore', 'nus', 'ntu'], ['سنغافوره'], { region: 'asia' }),
  C('my', 'Malaysia', 'ماليزيا', ['#010066', '#CC0001', '#FFCC00'], 'petronas', 'Kuala Lumpur', 'Ringgit (MYR)', 'Malay', ['malaysia', 'kuala', 'lumpur', 'penang'], ['ماليزيا', 'كوالالمبور'], { region: 'asia' }),
  C('tr', 'Türkiye', 'تركيا', ['#E30A17', '#FFFFFF', '#E30A17'], 'galata', 'Ankara', 'Turkish lira (TRY)', 'Turkish', ['turkey', 'turkiye', 'istanbul', 'ankara', 'izmir'], ['تركيا', 'اسطنبول', 'انقره'], { region: 'europe-asia' }),
  C('ee', 'Estonia', 'إستونيا', ['#0072CE', '#000000', '#FFFFFF'], 'tallinn-towers', 'Tallinn', 'Euro (EUR)', 'Estonian', ['tallinn', 'estonian', 'tartu'], ['تالين', 'استونيا']),
];
export const countryByCode = code => COUNTRIES.find(c => c.code === code) || null;

/** Sticker set every pack gets (rendered by tools/r3-countries.cjs into SVG and drawable live by the badge layer). */
export const STICKERS = [
  { id: 'flag-pin', label: 'Flag pin' },
  { id: 'map', label: 'Map outline' },
  { id: 'study-in', label: 'Study in X', text: c => `STUDY IN ${c.name.toUpperCase()}`, textAr: c => `ادرس في ${c.nameAr}` },
  { id: 'visa-approved', label: 'Visa approved', text: () => 'VISA APPROVED', textAr: () => 'تمت الموافقة على التأشيرة' },
  { id: 'intake-open', label: 'Intake open', text: () => 'INTAKE OPEN', textAr: () => 'باب القبول مفتوح' },
  { id: 'scholarships', label: 'Scholarships', text: () => 'SCHOLARSHIPS', textAr: () => 'منح دراسية' },
];
