// Vocabulary bias for captions: the words a company says that Whisper spells wrong ("Cosrex", "Medi cube",
// "Schengen" -> "Shengen"). Two uses:
//   1. promptFor(vocab)  -> a short sentence of terms fed to Whisper as its "previous text" (the initial prompt),
//      which nudges the decoder towards those spellings;
//   2. correctTerms(words, vocab) -> a post-pass that fixes near-miss spellings of those terms (1-3 words joined,
//      fuzzy match), keeping each word's timing and punctuation.
//
// Sources: the company's own list (workspace.captions.vocab, editable in the panel), its name / full name / website,
// and a seed per industry: KBB = the brands the shop sells (its catalogue) + skincare terms; Planet Careers =
// countries + visa / study / work terms + occupations. A new company starts with its own name only.

import { COUNTRIES } from '../auto/countries/countries.js';

// The KBB catalogue's brands (kbeautybliss.com brand list) with their Arabic names.
export const KBB_BRANDS = [
  ['COSRX', 'كوسركس'], ['Beauty of Joseon', 'بيوتي أوف جوسون'], ['Anua', 'أنوا'], ['Medicube', 'ميديكوب'], ['Round Lab', 'راوند لاب'],
  ['Torriden', 'توريدن'], ['Isntree', 'إيزنتري'], ['SKIN1004', 'سكين 1004'], ['Axis-Y', 'أكسيس-واي'], ['Banila Co', 'بانيلا كو'],
  ['Biodance', 'بيودانس'], ['Benton', 'بنتون'], ['Celimax', 'سيليماكس'], ['Centellian24', 'سنتيليان 24'], ['CeraVe', 'سيرافي'],
  ['Dear Klairs', 'ديير كليرس'], ['Dr. Ceuracle', 'دكتور سيوراكل'], ['Dr.Althea', 'الدكتورة ألثيا'], ['Dr.G', 'دكتور جي'],
  ['Dr.Melaxin', 'دكتور ميلاكسين'], ['Equalberry', 'إيكوالبيري'], ['Etude', 'إتود'], ['Elizavecca', 'إليزافيكا'], ['Goodal', 'جودال'],
  ['Haruharu Wonder', 'هاروهارو وندر'], ['Heimish', 'هيميش'], ['House of Hur', 'هاوس أوف هير'], ["I'm from", 'آيم فروم'],
  ['Illiyoon', 'إيليون'], ['Innisfree', 'إنيسفري'], ['Jumiso', 'جوميسو'], ['Laneige', 'لانيج'], ['La Roche-Posay', 'لاروش بوزيه'],
  ['Medi-Peel', 'ميديبيل'], ['Mediheal', 'ميديهيل'], ['Missha', 'ميشا'], ['Manyo', 'مانيو'], ['Mary&May', 'ماري آند ماي'],
  ['Numbuzin', 'نومبوزين'], ['Mixsoon', 'ميكسون'], ['Purito Seoul', 'بيوريتو سيول'], ['Pyunkang Yul', 'بيونكانغ يول'],
  ['Skinfood', 'سكين فود'], ['Some By Mi', 'سوم باي مي'], ['TirTir', 'تيرتير'], ['Tocobo', 'توكوبو'], ['VT Cosmetics', 'في تي كوزمتكس'],
  ['beplain', 'بيبلين'], ["d'Alba", 'دالبا'], ['iUNIK', 'آي يونيك'], ['romand', 'رومند'], ['Ongredients', 'أونغريدينتس'],
  ['Shiseido', 'شيسيدو'], ['Bioderma', 'بيوديرما'], ['Eucerin', 'يوسيرين'], ['Olaplex', 'أولابلكس'], ['NuFACE', 'نيوفيس'],
  ['Kose', 'كوسيه'], ['Lirene', 'ليرين'], ['Seapuri', 'سيبوري'], ['Foodology', 'فودولوجي'], ['Fwee', 'فوي'], ['Tosowoong', 'توسوونغ'],
];
const KBB_TERMS = ['K-Beauty', 'K-Beauty Bliss', 'kbeautybliss.com', 'niacinamide', 'centella', 'hyaluronic acid', 'retinol', 'snail mucin',
  'ceramide', 'peptide', 'propolis', 'ginseng', 'mugwort', 'heartleaf', 'sunscreen', 'SPF', 'toner', 'essence', 'serum', 'ampoule', 'cushion',
  'cleansing oil', 'double cleanse', 'glass skin'];
// Spelled right by the post-pass only, never put in Whisper's prompt (it knows them, and a prompted place name
// gets inserted where it was not said: measured, 'ask what you can Abu do').
const KBB_POST = ['Tabby', 'Tamara', 'AED', 'dirhams', 'UAE', 'Dubai', 'Abu Dhabi', 'Sharjah'];
const PC_TERMS = ['Planet Careers', 'Schengen', 'visa', 'student visa', 'work permit', 'residence permit', 'blue card', 'EU Blue Card',
  'Chancenkarte', 'Opportunity Card', 'PR', 'permanent residency', 'IELTS', 'TOEFL', 'PTE', 'Duolingo', 'APS', 'blocked account',
  'Sperrkonto', 'Uni-Assist', 'intake', 'bachelor', 'master', 'MBA', 'PhD', 'scholarship', 'DAAD', 'Erasmus', 'Stipendium Hungaricum',
  'embassy', 'consulate', 'VFS', 'biometrics', 'apostille', 'attestation', 'NOC', 'offer letter', 'CAS', 'GIC', 'SOP', 'LOR',
  'Express Entry', 'CRS', 'subclass 500', 'sponsorship', 'job seeker visa', 'D7', 'golden visa', 'Emirates ID', 'tuition fees'];
// Occupations the Work Abroad round recruits for (EN / AR).
export const OCCUPATIONS = [
  ['plumber', 'سباك'], ['electrician', 'كهربائي'], ['welder', 'لحام'], ['carpenter', 'نجار'], ['mason', 'بناء'], ['painter', 'دهان'],
  ['HVAC technician', 'فني تكييف'], ['pipe fitter', 'فني أنابيب'], ['steel fixer', 'حداد مسلح'], ['scaffolder', 'فني سقالات'],
  ['forklift operator', 'سائق رافعة شوكية'], ['crane operator', 'مشغل رافعة'], ['heavy driver', 'سائق ثقيل'], ['delivery rider', 'مندوب توصيل'],
  ['warehouse worker', 'عامل مستودع'], ['nurse', 'ممرض'], ['caregiver', 'مقدم رعاية'], ['doctor', 'طبيب'], ['pharmacist', 'صيدلي'],
  ['chef', 'شيف'], ['cook', 'طباخ'], ['waiter', 'نادل'], ['barista', 'باريستا'], ['housekeeping', 'تدبير منزلي'], ['cleaner', 'عامل نظافة'],
  ['security guard', 'حارس أمن'], ['cashier', 'كاشير'], ['beautician', 'خبيرة تجميل'], ['barber', 'حلاق'], ['tailor', 'خياط'],
  ['teacher', 'معلم'], ['nanny', 'مربية'], ['farm worker', 'عامل زراعي'], ['butcher', 'جزار'], ['baker', 'خباز'], ['mechanic', 'ميكانيكي'],
  ['machine operator', 'مشغل آلات'], ['accountant', 'محاسب'], ['engineer', 'مهندس'], ['IT specialist', 'أخصائي تقنية معلومات'],
];

/** The vocabulary for a workspace: [{term, ar?}] — company list first (it wins), then its seed. */
export function vocabFor(ws) {
  const out = [], seen = new Set();
  const add = (term, ar, own, post) => { const k = String(term || '').trim(); if (!k || seen.has(k.toLowerCase())) return; seen.add(k.toLowerCase()); const v = ar ? { term: k, ar } : { term: k }; if (own) v.own = true; if (post) v.post = true; out.push(v); };
  if (!ws) return out;
  const own = ws.captions?.vocab;
  for (const t of Array.isArray(own) ? own : String(own || '').split(/[\n,]/)) add(t, null, true);
  add(ws.name); add(ws.fullName);
  if (typeof ws.contact === 'string' && /\.\w{2,}/.test(ws.contact)) add(ws.contact);
  if (ws.contact?.website) add(ws.contact.website);
  const ind = String(ws.industry || '') + ' ' + (ws.industries || []).join(' ') + ' ' + (ws.brandRef || '') + ' ' + ws.id;
  if (/beaut|cosmet|skin|kbb/i.test(ind)) { for (const [en, ar] of KBB_BRANDS) add(en, ar); for (const t of KBB_TERMS) add(t); for (const t of KBB_POST) add(t, null, false, true); }
  if (/immigra|visa|study|consult|career|recruit|planet/i.test(ind)) {
    for (const t of PC_TERMS) add(t);
    for (const c of COUNTRIES) add(c.name, c.nameAr);
    for (const [en, ar] of OCCUPATIONS) add(en, ar);
  }
  return out;
}

/**
 * Whisper's initial prompt: a short, natural list of the terms most likely to be misspelt (distinctive ones first —
 * brand-like words with capitals, digits or odd letter pairs), capped so it never crowds out the audio
 * (Whisper keeps at most 224 prompt tokens; ~60 words is plenty). Arabic gets the Arabic names.
 */
export function promptFor(vocab, lang = 'en', maxWords = 48) {
  if (!vocab || !vocab.length) return '';
  const pick = [];
  let words = 0;
  // the company's own words first; then brand-like words (capitals inside, digits, dots); never short acronyms
  // ("SPF", "PR": Whisper knows them, and a prompt full of capitals makes it shout ordinary words: "VISA")
  const score = (v) => (v.own ? 9 : /[A-Z].*[a-z].*[A-Z]|\d|[.&'-]/.test(v.term) ? 2 : /^[A-Z]/.test(v.term) ? 1 : 0);
  const list = vocab.filter((v) => v.own || !v.post && !(/^[A-Z0-9]{2,5}$/.test(v.term) || v.term.replace(/[^\p{L}]/gu, '').length < 4))
    .map((v, i) => ({ v, i, s: score(v) })).sort((a, b) => b.s - a.s || a.i - b.i);
  for (const { v } of list) {
    const t = lang === 'ar' && v.ar ? v.ar : v.term;
    const n = t.split(/\s+/).length;
    if (words + n > maxWords) continue;
    pick.push(t); words += n;
  }
  return pick.join(', ') + '.';
}

// ------------------------------------------------------------------ near-miss correction

const fold = (s) => String(s || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
  .replace(/[ً-ْٰـ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
  .replace(/[^\p{L}\p{N}]+/gu, '');

/** Damerau-Levenshtein (optimal string alignment) distance, early exit past `max`. */
export function editDistance(a, b, max = 99) {
  const n = a.length, m = b.length;
  if (Math.abs(n - m) > max) return max + 1;
  let p2 = null, p = Array.from({ length: m + 1 }, (_, j) => j);
  for (let i = 1; i <= n; i++) {
    const c = [i]; let best = i;
    for (let j = 1; j <= m; j++) {
      let v = Math.min(p[j] + 1, c[j - 1] + 1, p[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      if (p2 && i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) v = Math.min(v, p2[j - 2] + 1);
      c.push(v); if (v < best) best = v;
    }
    if (best > max) return max + 1;
    p2 = p; p = c;
  }
  return p[m];
}

// Ordinary words a term must never swallow ("serum" is a term; "rum" is not a near miss of it).
const COMMON = new Set(('the and for you are with this that have from your they will what about when there their would make like time just know ' +
  'take people into year good some could them other than then look only come over think also back after work first well even want because ' +
  'these give most very skin face day today glow new one two three week really can our out all now get use how its it\'s more best love ' +
  'study visit live apply step steps free book call team every next march may june april').split(' '));

/**
 * Fix near-miss spellings of vocabulary terms. Tries 3-, 2- and 1-word windows (so "Medi cube" -> "Medicube" and
 * "Beauty of Joe son" -> "Beauty of Joseon"); a window matches when its folded letters are within ~20 % edit
 * distance of a folded term (at least 4 letters, never an exact common word). The corrected word takes the window's
 * timing and keeps its outer punctuation. Returns { words, fixes:[{from,to,t0}] }.
 */
export function correctTerms(words, vocab, { lang } = {}) {
  if (!words?.length || !vocab?.length) return { words, fixes: [] };
  const terms = [];
  for (const v of vocab) {
    for (const t of [v.term, v.ar].filter(Boolean)) {
      const f = fold(t);
      if (f.length < 4) continue;
      terms.push({ t, f, n: t.trim().split(/\s+/).length });
    }
  }
  const byFold = new Map(terms.map((x) => [x.f, x]));
  const out = [], fixes = [];
  let i = 0;
  while (i < words.length) {
    let done = false;
    for (let L = 3; L >= 1 && !done; L--) {
      if (i + L > words.length) continue;
      const span = words.slice(i, i + L);
      if (span.some((w) => w.tag)) continue;
      const raw = span.map((w) => w.w).join(' ');
      // a possessive / plural tail ("Torridan's", "Joseon's") is matched without it and kept on the result
      const poss = /(['’]s|s['’])[^\p{L}\p{N}]*$/u.exec(span[L - 1].w)?.[1] || '';
      const f = fold(poss ? raw.slice(0, raw.length - span[L - 1].w.length) + span[L - 1].w.replace(/(['’]s|s['’])([^\p{L}\p{N}]*)$/u, '$2') : raw);
      if (f.length < 4) continue;
      if (L === 1 && COMMON.has(f)) continue;
      let hit = byFold.get(f);
      if (!hit) {
        const max = f.length >= 9 ? 2 : 1;
        let best = null, bd = max + 1;
        for (const x of terms) {
          if (Math.abs(x.f.length - f.length) > max || x.f[0] !== f[0] && !(x.f.length > 6 && editDistance(x.f.slice(0, 2), f.slice(0, 2)) <= 1)) continue;
          const d = editDistance(f, x.f, max);
          if (d < bd) { bd = d; best = x; }
        }
        // An Arabic word that opens with the article "ال" is an ordinary word, not a brand transliteration: Whisper's
        // "اليون" (a mishearing of "اليوم", today) became the brand إيليون (Illiyoon) one letter away (16 Nov).
        if (best && f.startsWith('ال') && !best.f.startsWith('ال')) best = null;
        if (best && bd <= max && bd / best.f.length <= 0.22) hit = best;
      }
      if (!hit) continue;
      // already spelled right (same letters, same word count): leave the words exactly as Whisper wrote them
      const lead = span[0].w.match(/^[^\p{L}\p{N}]*/u)[0], trail = span[L - 1].w.match(/[^\p{L}\p{N}]*$/u)[0];
      const target = hit.t.trim().split(/\s+/);
      const same = target.length === L && span.every((w, k) => fold(w.w) === fold(target[k])) && span.map((w) => w.w.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '')).join(' ') === hit.t;
      if (same || lead + hit.t + poss + trail === raw || (fold(raw) === hit.f && raw.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '') === hit.t)) { out.push(...span); i += L; done = true; break; }
      if (L === 1 && target.length > 1 && !byFold.has(f)) continue; // one word becoming several needs an exact letter match
      const t0 = span[0].t0, t1 = span[L - 1].t1, conf = Math.min(...span.map((w) => w.conf ?? 1));
      const tot = target.reduce((s, x) => s + x.length, 0);
      let t = t0;
      target.forEach((x, k) => {
        const d = (t1 - t0) * x.length / tot;
        const w = { ...span[Math.min(k, L - 1)], w: (k === 0 ? lead : '') + x + (k === target.length - 1 ? poss + trail : ''), t0: +t.toFixed(3), t1: +(t + d).toFixed(3), conf };
        out.push(w); t += d;
      });
      fixes.push({ from: raw, to: hit.t, t0 });
      i += L; done = true;
    }
    if (!done) { out.push(words[i]); i++; }
  }
  return { words: out, fixes };
}
