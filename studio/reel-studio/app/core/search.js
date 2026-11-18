// KBB Studio — core/search.js: the search engine behind the top bar's "Search anything… (⌘K)" (editor/search-ui.js).
// Plain data in, ranked entries out; no DOM, no network, no dependency.
//
//   const ix = createIndex(entries);      entries: { id, title, ar?, syn?[], desc, where, group, sub?, cat?, prio? }
//   ix.search('trimm')  -> [{ e, score }] best first        ix.suggest('xyzz') -> ['trim', …] close words
//
// Matching, per query word: exact, prefix ("sp" → speed), inside a word ("ramp" → "speedramp"), typo-tolerant (one
// edit up to 6 letters, two above: "trimm", "captoin", "denoize"), and joined words ("slowmo" → "slow-mo"). Synonyms
// work both ways: an entry lists its own (`syn`), and QUERY_SYNONYMS below widens common words for every entry
// ("noise" also finds Enhance voice, "logo" finds the brand kit, "arabic" finds Translate). Arabic is normalised
// (hamza forms of alef, ta marbuta, alef maqsura, tashkeel, tatweel), so "ترجمه" finds "ترجمة".
// Cost is flat per keystroke: a word's matches over the vocabulary are cached, scoring walks posting lists.

const STOP = new Set(['the', 'a', 'an', 'to', 'my', 'how', 'i', 'of', 'in', 'on', 'for', 'and', 'with', 'do', 'can', 'is', 'it', 'me', 'make', 'want', 'where', 'what', 'this', 'that', 'please', 'from', 'some', 'be']);

/** words people type → words the studio uses (both directions are fine; every alternative is tried) */
export const QUERY_SYNONYMS = {
  trim: ['trim', 'cut', 'shorten', 'split', 'blade', 'ripple'], trimming: ['trim'], shorten: ['trim', 'tighten', 'cut'],
  cut: ['cut', 'split', 'blade', 'trim'], chop: ['split', 'blade'], razor: ['blade'], scissors: ['split'],
  noise: ['noise', 'denoise', 'enhancevoice', 'hum'], noisy: ['noise', 'enhancevoice'], hiss: ['noise', 'enhancevoice'], hum: ['hum', 'enhancevoice'], echo: ['echo', 'enhancevoice'], wind: ['noise', 'enhancevoice'],
  slowmo: ['speed', 'slowmo', 'slow'], slomo: ['speed', 'slowmo'], slow: ['slow', 'speed'], fast: ['speed', 'fast'], faster: ['speed'], slower: ['speed'], timelapse: ['speed'],
  bigger: ['size', 'bigger', 'scale'], larger: ['size', 'scale'], smaller: ['size', 'scale'], resize: ['size', 'scale'],
  logo: ['logo', 'brandkit', 'brand'], watermark: ['logo', 'brandkit'], branding: ['brandkit', 'brand'],
  arabic: ['arabic', 'translate', 'عربي', 'ar'], urdu: ['urdu', 'translate'], hindi: ['hindi', 'translate'], english: ['english', 'translate'], language: ['language', 'translate'],
  subtitle: ['captions', 'caption', 'subtitle'], subtitles: ['captions', 'caption'], subs: ['captions'], srt: ['srt', 'captions'], transcript: ['transcript', 'captions'],
  cutout: ['cutout', 'removebackground', 'background'], greenscreen: ['removebackground', 'background'],
  color: ['colour'], colors: ['colour'], colour: ['colour', 'color'], grade: ['look', 'colour', 'grade'], grading: ['look', 'colour'], lut: ['look', 'colour'], filter: ['filter', 'look'], filters: ['filter', 'look'],
  center: ['centre'], centre: ['centre', 'center'], gray: ['grey'], favorite: ['favourite'], favorites: ['favourite'],
  song: ['music'], songs: ['music'], soundtrack: ['music'], audio: ['audio', 'sound'], loud: ['volume', 'loudness'], louder: ['volume', 'loudness'], quiet: ['volume', 'mute'], volume: ['volume', 'loudness'],
  render: ['export'], save: ['save', 'export'], download: ['download', 'export'], mp4: ['export', 'mp4'], uhd: ['4k'], hd: ['1080p', 'hd'],
  fps: ['fps', 'framerate'], framerate: ['framerate', 'fps'],
  delete: ['delete', 'remove'], remove: ['remove', 'delete'], erase: ['delete'], undo: ['undo'], back: ['undo', 'back'],
  apikey: ['key', 'api'], token: ['key'], password: ['key'], stock: ['photos', 'pexels', 'unsplash', 'stock'],
  font: ['font', 'typeface'], typeface: ['font'], text: ['text', 'title'], title: ['title', 'text'], headline: ['headline', 'title'],
  sticker: ['sticker', 'stickers', 'emoji'], emoji: ['emoji', 'sticker'], gif: ['sticker'],
  zoom: ['zoom', 'kenburns', 'punch'], kenburns: ['zoom'], rotate: ['rotate'], flip: ['rotate', 'reverse'], crop: ['crop'],
  transition: ['transition', 'transitions'], effect: ['effect', 'effects', 'look'], effects: ['effects', 'look'],
  silence: ['silence', 'silences', 'tighten'], silences: ['silences', 'tighten'], pause: ['silences', 'pause'], gaps: ['silences', 'tighten', 'gap'],
  keyboard: ['keyboard', 'shortcuts'], shortcut: ['shortcuts', 'keyboard'], hotkey: ['shortcuts'], hotkeys: ['shortcuts'],
  company: ['company', 'companies', 'brand'], brand: ['brand', 'brandkit', 'company'],
  freeze: ['freeze', 'hold'], still: ['freeze'], backwards: ['reverse'], rewind: ['reverse'],
  duck: ['duck', 'ducking'], ducking: ['duck', 'ducking'], sidechain: ['duck'],
  instagram: ['instagram', 'safe'], tiktok: ['tiktok', 'safe'], youtube: ['youtube'],
  ad: ['ad', 'adstudio', 'designs'], ads: ['ad', 'adstudio', 'designs'], poster: ['designs'], banner: ['designs'],
  photo: ['photo', 'photos', 'picture'], photos: ['photos', 'photo'], picture: ['picture', 'photo'], image: ['image', 'picture', 'photo'], images: ['image', 'photo'],
  ai: ['ai', 'models', 'auto'], model: ['models', 'model'], models: ['models'],
  folder: ['folder', 'folders'], storage: ['storage', 'folders'], disk: ['storage', 'folders'], finder: ['finder', 'folders'],
  help: ['help', 'guide', 'how'], guide: ['guide', 'help'], tutorial: ['guide'],
  'قص': ['trim', 'split', 'قص'], 'سرعة': ['speed'], 'ترجمة': ['translate', 'ترجمة'], 'موسيقى': ['music'], 'تصدير': ['export'], 'نص': ['text'], 'شعار': ['logo', 'brandkit'], 'صوت': ['audio', 'sound', 'voice'], 'ضجيج': ['noise', 'enhancevoice'], 'خلفية': ['background'],
};
/** phrases people type → one word the index knows */
export const PHRASES = [
  ['slow motion', 'slowmo'], ['slow mo', 'slowmo'], ['speed up', 'speedup'], ['speed ramp', 'speedramp'], ['background noise', 'noise'], ['remove noise', 'noise'],
  ['bigger text', 'textsize'], ['text size', 'textsize'], ['font size', 'textsize'], ['caption size', 'textsize'], ['bigger captions', 'textsize'], ['larger text', 'textsize'],
  ['remove background', 'removebackground'], ['background remover', 'removebackground'], ['cut out', 'cutout'], ['green screen', 'greenscreen'],
  ['frame rate', 'framerate'], ['api key', 'apikey'], ['safe area', 'safe'], ['safe areas', 'safe'], ['safe zone', 'safe'], ['safe zones', 'safe'],
  ['brand kit', 'brandkit'], ['ken burns', 'kenburns'], ['picture in picture', 'pip'], ['word sync', 'wordsync'], ['lip sync', 'wordsync'],
  ['freeze frame', 'freeze'], ['still frame', 'freeze'], ['sound effects', 'sfx'], ['sound effect', 'sfx'], ['ad studio', 'adstudio'], ['older engine', 'olderengine'],
  ['keyboard shortcuts', 'shortcuts'], ['voice over', 'voiceover'], ['call to action', 'cta'], ['before after', 'beforeafter'],
];

const AR_MAP = { 'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ى': 'ي', 'ة': 'ه', 'ؤ': 'و', 'ئ': 'ي', 'ـ': '' };
/** lower-case, Latin accents off, Arabic letter forms unified, punctuation to spaces */
export function normalize(s) {
  return String(s || '').toLowerCase()
    .normalize('NFKD').replace(/[̀-ًͯ-ٰٟ]/g, '')
    .replace(/[أإآٱىةؤئـ]/g, (c) => AR_MAP[c])
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x660))
    .replace(/&/g, ' and ').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
}
const words = (s) => normalize(s).split(' ').filter(Boolean);
/** the synonym table with normalised keys (Arabic keys too) */
const NSYN = new Map(Object.entries(QUERY_SYNONYMS).map(([k, v]) => [normalize(k).replace(/ /g, ''), v]));
const NPHRASES = PHRASES.map(([a, b]) => [normalize(a), b]);

/** Damerau-Levenshtein (optimal string alignment), stops early past max; three reused rows, no allocation */
const R0 = new Int32Array(66), R1 = new Int32Array(66), R2 = new Int32Array(66);
export function editDistance(a, b, max = 2, upTo = b.length) {
  const la = a.length, lb = Math.min(upTo, b.length);   // upTo: compare with the start of b only (no slice)
  if (Math.abs(la - lb) > max) return max + 1;
  if (la > 64 || lb > 64) return a === b ? 0 : max + 1;
  let p2 = R0, p1 = R1, cur = R2;
  for (let j = 0; j <= lb; j++) p1[j] = j;
  for (let i = 1; i <= la; i++) {
    cur[0] = i; let best = i;
    const ai = a.charCodeAt(i - 1), ai1 = i > 1 ? a.charCodeAt(i - 2) : -1;
    for (let j = 1; j <= lb; j++) {
      const bj = b.charCodeAt(j - 1);
      let v = p1[j - 1] + (ai === bj ? 0 : 1);
      if (p1[j] + 1 < v) v = p1[j] + 1;
      if (cur[j - 1] + 1 < v) v = cur[j - 1] + 1;
      if (i > 1 && j > 1 && ai === b.charCodeAt(j - 2) && ai1 === bj && p2[j - 2] + 1 < v) v = p2[j - 2] + 1;
      cur[j] = v; if (v < best) best = v;
    }
    if (best > max) return max + 1;
    const t = p2; p2 = p1; p1 = cur; cur = t;
  }
  return p1[lb];
}

const FIELDS = [['title', 3], ['ar', 3], ['syn', 2.3], ['sub', 1.4], ['where', 0.7], ['desc', 0.45]];

export function createIndex(entries) {
  const post = new Map();     // token -> Map(entry index -> weight)
  const titleTok = new Map(); // token -> how many titles use it (for "did you mean")
  const add = (tok, i, w) => {
    if (!tok) return;
    let m = post.get(tok); if (!m) post.set(tok, (m = new Map()));
    if ((m.get(i) || 0) < w) m.set(i, w);
  };
  entries.forEach((e, i) => {
    for (const [f, w] of FIELDS) {
      const v = e[f]; if (!v) continue;
      for (const part of Array.isArray(v) ? v : [v]) {
        const ws = words(part);
        ws.forEach((t, k) => {
          add(t, i, w);
          if (k > 0) add(ws[k - 1] + t, i, w * 0.9);   // joined words: "slow mo" is also "slowmo"
          if (f === 'title') titleTok.set(t, (titleTok.get(t) || 0) + 1);
        });
        if (ws.length > 1 && ws.length <= 4) add(ws.join(''), i, w * 0.95);
      }
    }
  });
  // posting lists as typed arrays (fast to walk on every keystroke)
  const P = new Map();
  for (const [t, m] of post) P.set(t, { ids: Int32Array.from(m.keys()), ws: Float32Array.from(m.values()) });
  const vocab = [...post.keys()].sort();
  const score = new Float32Array(entries.length), gbest = new Float32Array(entries.length), hit = new Uint8Array(entries.length);
  // a query that equals an entry's own synonym is as good as its title ("logo" → the brand kit)
  const synExact = new Map();
  entries.forEach((e, i) => { for (const sy of e.syn || []) { const n = normalize(sy); if (!synExact.has(n)) synExact.set(n, []); synExact.get(n).push(i); } });
  const synHit = new Set();
  const byLen = [];
  for (const v of vocab) (byLen[v.length] ||= []).push(v);
  const cache = new Map();
  const lower = (q) => { let lo = 0, hi = vocab.length; while (lo < hi) { const m = (lo + hi) >> 1; if (vocab[m] < q) lo = m + 1; else hi = m; } return lo; };

  /** vocabulary words a query word matches, with how well (1 = exact). Synonyms (fuzzy=false): exact and prefix only */
  function matches(q, fuzzy = true) {
    const ck = (fuzzy ? 'f' : 'p') + q;
    let r = cache.get(ck); if (r) return r;
    r = []; const seen = new Set();
    const L = q.length;
    for (let i = lower(q); i < vocab.length && vocab[i].startsWith(q); i++) { const v = vocab[i]; seen.add(v); r.push([v, v === q ? 1 : 0.93 - 0.3 * (1 - L / v.length)]); }
    if (fuzzy) {
      if (L >= 3) for (const v of vocab) if (v.length > L && !seen.has(v) && v.includes(q)) { seen.add(v); r.push([v, 0.55]); }
      const maxd = L >= 7 ? 2 : L >= 4 ? 1 : 0;
      if (maxd) {
        for (let n = L - maxd; n <= L + maxd; n++) for (const v of byLen[n] || []) if (!seen.has(v)) { const d = editDistance(q, v, maxd); if (d <= maxd) { seen.add(v); r.push([v, L <= 4 ? 0.5 : 0.8 - 0.12 * d]); } }
        if (L >= 5) { const c0 = q.charCodeAt(0), c1 = q.charCodeAt(1); for (let n = L + 1; n < byLen.length; n++) for (const v of byLen[n] || []) { const v0 = v.charCodeAt(0); if ((v0 === c0 || v0 === c1) && !seen.has(v) && editDistance(q, v, 1, L) <= 1) { seen.add(v); r.push([v, 0.6]); } } }
      }
    }
    if (cache.size > 600) cache.clear();
    cache.set(ck, r);
    return r;
  }

  /** the query as groups of alternatives: every group must match (any one alternative is enough) */
  function parse(q) {
    let n = ' ' + normalize(q) + ' ';
    for (const [ph, w] of NPHRASES) if (n.includes(' ' + ph + ' ')) n = n.replace(' ' + ph + ' ', ' ' + w + ' ');
    const toks = n.split(' ').filter((t) => t && !STOP.has(t));
    const use = toks.length ? toks : n.split(' ').filter(Boolean);
    return use.map((t) => {
      const alts = [[t, 1]];
      for (const s of NSYN.get(t) || []) { const ns = normalize(s).replace(/ /g, ''); if (ns !== t) alts.push([ns, 0.65]); }
      return { t, alts };
    });
  }

  function search(q, { limit = 40 } = {}) {
    const groups = parse(q);
    if (!groups.length) return [];
    const nq = normalize(q);
    score.fill(0); hit.fill(0);
    const touched = [];
    for (const g of groups) {
      const gt = [];
      for (const [alt, aw] of g.alts) {
        // a synonym counts only as itself or a close prefix; a typo of a synonym would wander too far
        const ms = aw < 1 ? matches(alt, false).filter(([, sc]) => sc >= 0.75) : matches(alt);
        for (const [v, sc] of ms) {
          const pl = P.get(v), f = sc * aw, ids = pl.ids, ws = pl.ws;
          for (let k = 0; k < ids.length; k++) { const i = ids[k], x = f * ws[k]; if (x > gbest[i]) { if (!gbest[i]) gt.push(i); gbest[i] = x; } }
        }
      }
      for (const i of gt) { if (!hit[i]) touched.push(i); score[i] += gbest[i]; hit[i]++; gbest[i] = 0; }
    }
    synHit.clear(); for (const i of synExact.get(nq) || []) synHit.add(i);
    const need = groups.length;
    let out = [];
    for (const i of touched) if (hit[i] >= need) out.push(i);
    const partial = !out.length;
    if (partial) for (const i of touched) if (hit[i] >= Math.ceil(need / 2)) out.push(i);
    out = out.map((i) => {
      const e = entries[i], nt = e._nt || (e._nt = normalize(e.title));
      let s = score[i];
      if (nt === nq || synHit.has(i) || (e._nar && e._nar === nq)) s += e.cat ? 6 : 4.5; else if (nt.startsWith(nq) && nq.length >= 2) s += 2.5;
      if (e.cat) s *= 1.3;
      if (e.group === 'Help' && !/\b(how|why|where|help|guide)\b/.test(nq)) s *= 0.8;
      if (e.prio) s *= 1 + 0.1 * e.prio;
      if (partial) s *= 0.5;
      return { e, score: s };
    }).sort((a, b) => b.score - a.score || a.e.title.length - b.e.title.length);
    return out.slice(0, limit);
  }

  /** close words from the titles, for "No result — did you mean …" */
  function suggest(q, n = 4) {
    const out = new Map();
    for (const g of parse(q)) {
      const t = g.t;
      for (const [v, uses] of titleTok) {
        if (v.length < 3 || /^\d+$/.test(v)) continue;
        const d = editDistance(t, v, 3);
        const pre = v.startsWith(t.slice(0, 3)) ? 0.5 : 0;
        if (d <= 3 || pre) { const s = (d <= 3 ? 4 - d : 0) + pre + Math.min(uses, 5) * 0.05; if ((out.get(v) || 0) < s) out.set(v, s); }
      }
    }
    return [...out].sort((a, b) => b[1] - a[1]).slice(0, n).map(([v]) => v);
  }

  for (const e of entries) if (e.ar) e._nar = normalize(e.ar);
  return { entries, search, suggest, size: entries.length, vocab: vocab.length, clearCache: () => cache.clear() };
}
