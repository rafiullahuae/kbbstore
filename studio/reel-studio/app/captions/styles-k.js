// Captions round (16 Nov): 37 more caption templates + the gallery's categories. Data only, deltas from the base
// style in styles.js (which merges them in after its own list and Lane C's extras).
//
// New renderer features these use (render.js): anim 'rise' | 'wave' | 'shake' | 'zoom' | 'glitch' | 'underline' |
// 'stamp' | 'outline-fill' | 'rainbow'; bg.type 'tape' | 'bubble' | 'gradient' | 'outline'; grad (a second colour:
// the text is filled top→bottom from `color` to `grad`).

export const CATEGORIES = ['Viral', 'Minimal', 'Luxury', 'Bold', 'Corporate', 'Arabic', 'Bilingual', 'Fun'];

// The first 20 (styles.js) and any style without its own `cat`: by id, then by group.
const CAT_BY_ID = {
  'word-box': 'Viral', 'word-color': 'Viral', 'word-scale': 'Viral', karaoke: 'Viral', 'pop-in': 'Viral', 'one-word': 'Viral',
  'bold-outline': 'Bold', 'soft-beauty': 'Luxury', 'luxury-serif': 'Luxury', neon: 'Bold', typewriter: 'Minimal', bounce: 'Fun',
  'emoji-accent': 'Fun', marker: 'Bold', news: 'Corporate', minimal: 'Minimal', 'arabic-first': 'Arabic', bilingual: 'Bilingual',
  corporate: 'Corporate', 'info-card': 'Corporate',
};
const CAT_BY_GROUP = { 'word by word': 'Viral', animated: 'Viral', classic: 'Bold', brand: 'Luxury', pro: 'Corporate', arabic: 'Arabic',
  bilingual: 'Bilingual', minimal: 'Minimal', luxury: 'Luxury', bold: 'Bold', corporate: 'Corporate', fun: 'Fun', viral: 'Viral' };

/** Gallery category of a style object. */
export function catOf(s) {
  if (s.cat && CATEGORIES.includes(s.cat)) return s.cat;
  if (CAT_BY_ID[s.id]) return CAT_BY_ID[s.id];
  if (s.bilingual) return 'Bilingual';
  const g = CAT_BY_GROUP[String(s.group || '').toLowerCase()];
  if (g) return g;
  if (/arab/i.test(s.id)) return 'Arabic';
  return 'Fun';
}

/** Search keywords of a style (name, id, group, category, font, animation, a few look words). */
export function keywordsOf(s) {
  return [s.name, s.id, s.group, catOf(s), s.font, s.fontAr, s.anim, s.bg?.type, s.tags].filter(Boolean).join(' ').toLowerCase();
}

const OUT = (w, color = '#000') => ({ w, color });
const SH = (blur, y = 4, color = 'rgba(0,0,0,0.7)') => ({ blur, x: 0, y, color });

export const K_STYLES = [
  // ---------------------------------------------------------------- Viral
  { id: 'k-hormozi', cat: 'Viral', name: 'Hormozi yellow', group: 'Word by word', font: 'Montserrat', weight: 900, size: 84, case: 'upper',
    outline: OUT(12), shadow: SH(10, 6), active: '#ffe500', anim: 'highlight-scale', rowChars: 14, tags: 'podcast talking head' },
  { id: 'k-beast', cat: 'Viral', name: 'Beast stamp', group: 'Word by word', font: 'Bangers', weight: 400, size: 104, tracking: 2,
    outline: OUT(12), active: '#39ff14', anim: 'stamp', rowChars: 14, tags: 'youtube energetic' },
  { id: 'k-tiktok', cat: 'Viral', name: 'TikTok boxes', group: 'Word by word', font: 'Montserrat', weight: 800, size: 64,
    color: '#ffffff', bg: { type: 'word', color: '#000000', opacity: 0.82, radius: 10, pad: 12 }, active: '#fe2c55', anim: 'highlight-color', rowChars: 20 },
  { id: 'k-pink-pop', cat: 'Viral', name: 'Pink pop box', group: 'Word by word', size: 80, weight: 900, case: 'upper',
    outline: OUT(9), activeBg: '#ff2d87', anim: 'highlight-box', rowChars: 15, tags: 'beauty kbb' },
  { id: 'k-one-stamp', cat: 'Viral', name: 'One word stamp', group: 'Animated', font: 'Anton', weight: 400, size: 160, case: 'upper',
    outline: OUT(12), active: '#ffd400', anim: 'one-word', pos: { y: 0.5 }, rowChars: 12, maxRows: 1, glow: { blur: 0, color: '#ffd400' } },
  { id: 'k-gradient-pop', cat: 'Viral', name: 'Gradient pop', group: 'Animated', font: 'Montserrat', weight: 900, size: 82, case: 'upper',
    color: '#ffffff', grad: '#ffb3d9', outline: OUT(8, '#2a0a1e'), anim: 'pop', rowChars: 15 },
  { id: 'k-rise-reel', cat: 'Viral', name: 'Rise up', group: 'Animated', font: 'Montserrat', weight: 800, size: 74,
    outline: OUT(8), active: '#ffd400', anim: 'rise', rowChars: 18 },
  // ---------------------------------------------------------------- Minimal
  { id: 'k-clean', cat: 'Minimal', name: 'Clean white', group: 'Minimal', font: 'Inter', weight: 600, size: 54,
    shadow: SH(16, 3, 'rgba(0,0,0,0.65)'), anim: 'none', pos: { y: 0.8 }, rowChars: 30 },
  { id: 'k-lower-soft', cat: 'Minimal', name: 'lowercase soft', group: 'Minimal', font: 'Inter', weight: 500, size: 56, case: 'lower',
    shadow: SH(12, 2), dim: 'rgba(255,255,255,0.55)', anim: 'karaoke', active: '#ffffff', rowChars: 28 },
  { id: 'k-thin-line', cat: 'Minimal', name: 'Underline', group: 'Minimal', font: 'Inter', weight: 500, size: 54,
    shadow: SH(10, 2), accent: '#ffffff', anim: 'underline', rowChars: 28 },
  { id: 'k-film', cat: 'Minimal', name: 'Film subtitle', group: 'Minimal', font: 'Inter', weight: 600, size: 50,
    color: '#ffe14d', outline: OUT(5), anim: 'none', pos: { y: 0.9 }, rowChars: 36 },
  { id: 'k-glass', cat: 'Minimal', name: 'Frosted bar', group: 'Minimal', font: 'Inter', weight: 600, size: 48,
    color: '#ffffff', bg: { type: 'box', color: '#ffffff', opacity: 0.18, radius: 16, pad: 18 }, anim: 'fade', pos: { y: 0.84 }, rowChars: 32 },
  // ---------------------------------------------------------------- Luxury
  { id: 'k-gold-serif', cat: 'Luxury', name: 'Gold serif', group: 'Luxury', font: 'Playfair Display', fontAr: 'Amiri', weight: 700, size: 76,
    color: '#fff3c4', grad: '#c9962c', shadow: SH(14, 4, 'rgba(0,0,0,0.75)'), anim: 'fade', rowChars: 22 },
  { id: 'k-champagne', cat: 'Luxury', name: 'Champagne pill', group: 'Luxury', font: 'Playfair Display', fontAr: 'Amiri', weight: 600, size: 58,
    color: '#5a3d2b', active: '#b0742f', bg: { type: 'pill', color: '#f7e7ce', opacity: 0.95, radius: 44, pad: 22 }, anim: 'highlight-color', rowChars: 26 },
  { id: 'k-noir', cat: 'Luxury', name: 'Noir elegance', group: 'Luxury', font: 'Playfair Display', fontAr: 'Amiri', weight: 500, size: 56, tracking: 1,
    color: '#ffffff', bg: { type: 'box', color: '#000000', opacity: 0.78, radius: 0, pad: 20 }, anim: 'fade', pos: { y: 0.82 }, rowChars: 30 },
  { id: 'k-rose-gold', cat: 'Luxury', name: 'Rose gold caps', group: 'Luxury', font: 'Montserrat', weight: 500, size: 52, case: 'upper', tracking: 5,
    color: '#ffe1d6', grad: '#d48a73', shadow: SH(12, 3), anim: 'rise', rowChars: 24, tags: 'beauty kbb' },
  { id: 'k-spa', cat: 'Luxury', name: 'Spa calm', group: 'Luxury', font: 'Montserrat', weight: 500, size: 54,
    color: '#2f4a3a', active: '#6b9e7a', bg: { type: 'pill', color: '#eaf4ec', opacity: 0.94, radius: 40, pad: 20 }, anim: 'highlight-color', rowChars: 26 },
  // ---------------------------------------------------------------- Bold
  { id: 'k-impact', cat: 'Bold', name: 'Impact yellow', group: 'Bold', font: 'Anton', weight: 400, size: 112, case: 'upper', tracking: 1,
    color: '#ffe500', outline: OUT(14), shadow: SH(0, 8, '#000'), anim: 'none', rowChars: 16 },
  { id: 'k-red-alert', cat: 'Bold', name: 'Red alert', group: 'Bold', font: 'Bebas Neue', weight: 400, size: 104, case: 'upper', tracking: 2,
    color: '#ffffff', bg: { type: 'word', color: '#e10600', opacity: 1, radius: 6, pad: 10 }, anim: 'highlight-scale', active: '#ffffff', rowChars: 16 },
  { id: 'k-stamp', cat: 'Bold', name: 'Stamp', group: 'Bold', font: 'Anton', weight: 400, size: 100, case: 'upper',
    outline: OUT(12), active: '#ff3b3b', anim: 'stamp', rowChars: 15 },
  { id: 'k-hype', cat: 'Bold', name: 'Hype shake', group: 'Bold', font: 'Bangers', weight: 400, size: 100, tracking: 2,
    color: '#ffffff', active: '#ff8a00', outline: OUT(10, '#1b1b1b'), anim: 'shake', rowChars: 15 },
  { id: 'k-glitch', cat: 'Bold', name: 'Glitch', group: 'Bold', font: 'Montserrat', weight: 900, size: 80, case: 'upper',
    color: '#ffffff', active: '#ffffff', anim: 'glitch', shadow: SH(0, 0, 'rgba(0,0,0,0)'), outline: OUT(4), rowChars: 16 },
  { id: 'k-outline-fill', cat: 'Bold', name: 'Outline to fill', group: 'Bold', font: 'Montserrat', weight: 900, size: 84, case: 'upper',
    color: '#ffffff', outline: OUT(6, '#ffffff'), anim: 'outline-fill', rowChars: 15 },
  // ---------------------------------------------------------------- Corporate
  { id: 'k-lower-third', cat: 'Corporate', name: 'Clean lower-third', group: 'Pro', font: 'Inter', fontAr: 'Tajawal', weight: 600, size: 46,
    color: '#ffffff', bg: { type: 'bar', color: '#14213d', opacity: 0.94, radius: 6, pad: 22 }, accent: '#fca311', anim: 'slide', pos: { y: 0.9 }, rowChars: 36 },
  { id: 'k-outline-box', cat: 'Corporate', name: 'Outline frame', group: 'Pro', font: 'Inter', fontAr: 'Tajawal', weight: 700, size: 50,
    color: '#ffffff', bg: { type: 'outline', color: '#000000', opacity: 0.35, radius: 14, pad: 22 }, accent: '#ffffff', anim: 'fade', rowChars: 30 },
  { id: 'k-subtitle-card', cat: 'Corporate', name: 'White card', group: 'Pro', font: 'Inter', fontAr: 'Tajawal', weight: 700, size: 52,
    color: '#0b1f3a', active: '#1f6feb', bg: { type: 'card', color: '#ffffff', opacity: 0.97, radius: 20, pad: 26 }, accent: '#1f6feb',
    anim: 'highlight-color', pos: { y: 0.8 }, rowChars: 28, tags: 'planet careers consultancy' },
  { id: 'k-headline', cat: 'Corporate', name: 'Headline news', group: 'Pro', font: 'Bebas Neue', fontAr: 'Cairo', weight: 400, size: 72, case: 'upper', tracking: 1,
    color: '#ffffff', bg: { type: 'bar', color: '#111111', opacity: 0.95, radius: 0, pad: 20 }, accent: '#e10600', anim: 'slide', pos: { y: 0.88 }, rowChars: 28 },
  { id: 'k-gradient-bar', cat: 'Corporate', name: 'Gradient band', group: 'Pro', font: 'Montserrat', fontAr: 'Tajawal', weight: 700, size: 52,
    color: '#ffffff', bg: { type: 'gradient', color: '#002768', opacity: 0.95, radius: 18, pad: 22 }, accent: '#be0b32', anim: 'fade', pos: { y: 0.82 }, rowChars: 28,
    tags: 'planet careers navy red' },
  // ---------------------------------------------------------------- Arabic
  { id: 'k-ar-gold', cat: 'Arabic', name: 'Arabic gold (Amiri)', group: 'Arabic', font: 'Amiri', fontAr: 'Amiri', weight: 700, size: 82,
    color: '#fff3c4', grad: '#c9962c', shadow: SH(14, 4, 'rgba(0,0,0,0.8)'), anim: 'fade', rowChars: 20 },
  { id: 'k-ar-box', cat: 'Arabic', name: 'Arabic word box', group: 'Arabic', font: 'Cairo', fontAr: 'Cairo', weight: 900, size: 76,
    outline: OUT(9), activeBg: '#c6395f', anim: 'highlight-box', rowChars: 18 },
  { id: 'k-ar-soft', cat: 'Arabic', name: 'Arabic soft pill', group: 'Arabic', font: 'Tajawal', fontAr: 'Tajawal', weight: 700, size: 60,
    color: '#5b2a3c', active: '#c2185b', bg: { type: 'pill', color: '#ffe3ec', opacity: 0.95, radius: 40, pad: 22 }, anim: 'highlight-color', rowChars: 24 },
  { id: 'k-ar-naskh', cat: 'Arabic', name: 'Naskh classic', group: 'Arabic', font: 'Noto Naskh Arabic', fontAr: 'Noto Naskh Arabic', weight: 700, size: 62,
    color: '#ffffff', bg: { type: 'box', color: '#000000', opacity: 0.6, radius: 12, pad: 16 }, anim: 'none', pos: { y: 0.84 }, rowChars: 30 },
  // ---------------------------------------------------------------- Bilingual
  { id: 'k-bi-card', cat: 'Bilingual', name: 'Bilingual card', group: 'Bilingual', font: 'Inter', fontAr: 'Tajawal', weight: 700, size: 50,
    color: '#ffffff', active: '#f2c14e', bg: { type: 'card', color: '#0b2545', opacity: 0.95, radius: 20, pad: 24 }, accent: '#f2c14e',
    bilingual: true, anim: 'highlight-color', pos: { y: 0.78 }, rowChars: 28, maxRows: 1 },
  { id: 'k-bi-minimal', cat: 'Bilingual', name: 'Bilingual minimal', group: 'Bilingual', font: 'Inter', fontAr: 'Cairo', weight: 600, size: 46,
    color: '#ffffff', bg: { type: 'box', color: '#000000', opacity: 0.55, radius: 10, pad: 14 }, bilingual: true, anim: 'none', pos: { y: 0.84 }, rowChars: 34, maxRows: 1 },
  { id: 'k-bi-pop', cat: 'Bilingual', name: 'Bilingual pop', group: 'Bilingual', font: 'Montserrat', fontAr: 'Cairo', weight: 900, size: 58, case: 'upper',
    outline: OUT(8), active: '#ff2d87', bilingual: true, anim: 'pop', pos: { y: 0.72 }, rowChars: 22, maxRows: 1 },
  // ---------------------------------------------------------------- Fun
  { id: 'k-comic', cat: 'Fun', name: 'Comic bubble', group: 'Fun', font: 'Bangers', weight: 400, size: 78, tracking: 1,
    color: '#111111', active: '#e10600', bg: { type: 'bubble', color: '#ffffff', opacity: 1, radius: 36, pad: 24 }, accent: '#111111', anim: 'highlight-color', rowChars: 20 },
  { id: 'k-wave', cat: 'Fun', name: 'Wave party', group: 'Fun', font: 'Pacifico', weight: 400, size: 72,
    color: '#ffffff', outline: OUT(8, '#5b2a86'), active: '#ffd400', anim: 'wave', rowChars: 20 },
  { id: 'k-tape', cat: 'Fun', name: 'Sticker tape', group: 'Fun', font: 'Bangers', weight: 400, size: 82, tracking: 1,
    color: '#111111', bg: { type: 'tape', color: '#ffe066', opacity: 1, radius: 4, pad: 18 }, active: '#d6336c', anim: 'highlight-color', rowChars: 18 },
  { id: 'k-rainbow', cat: 'Fun', name: 'Rainbow words', group: 'Fun', font: 'Montserrat', weight: 900, size: 80, case: 'upper',
    outline: OUT(9), anim: 'rainbow', rowChars: 16 },
  { id: 'k-emoji-pop', cat: 'Fun', name: 'Emoji pop', group: 'Fun', font: 'Montserrat', weight: 900, size: 76,
    outline: OUT(9), active: '#00e5ff', emoji: true, anim: 'pop', rowChars: 16 },
];
