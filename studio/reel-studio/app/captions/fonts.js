// Offline caption fonts (vendor/caption-fonts, all OFL-1.1 except Special Elite: Apache-2.0).
// Each family is registered once as a FontFace per unicode subset, so "Cairo" covers both
// its Arabic and its Latin file and the browser shapes Arabic itself.
const BASE = new URL('../../vendor/caption-fonts/', import.meta.url).href;

const LATIN = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
const ARABIC = 'U+0600-06FF,U+0750-077F,U+0870-088E,U+0890-0891,U+0897-08E1,U+08E3-08FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FE74,U+FE76-FEFC';
const DEVA = 'U+0900-097F,U+1CD0-1CF9,U+200C-200D,U+20A8,U+20B9,U+20F0,U+25CC,U+A830-A839,U+A8E0-A8FF';

// family -> [[file, unicodeRange, weight]]  (weight '100 900' = variable)
export const FONT_FILES = {
  'Montserrat': [['montserrat-latin-wght-normal.woff2', LATIN, '100 900']],
  'Inter': [['inter-latin-wght-normal.woff2', LATIN, '100 900']],
  'Playfair Display': [['playfair-display-latin-wght-normal.woff2', LATIN, '400 900']],
  'Cairo': [['cairo-arabic-wght-normal.woff2', ARABIC, '200 1000'], ['cairo-latin-wght-normal.woff2', LATIN, '200 1000']],
  'Noto Naskh Arabic': [['noto-naskh-arabic-arabic-wght-normal.woff2', ARABIC, '400 700'], ['noto-naskh-arabic-latin-wght-normal.woff2', LATIN, '400 700']],
  // translation lane: Urdu captions in Nastaliq (the script Urdu readers expect), and a clean Arabic sans
  'Noto Nastaliq Urdu': [['noto-nastaliq-urdu-arabic-400-normal.woff2', ARABIC, '400'], ['noto-nastaliq-urdu-latin-400-normal.woff2', LATIN, '400'],
                         ['noto-nastaliq-urdu-arabic-700-normal.woff2', ARABIC, '700'], ['noto-nastaliq-urdu-latin-700-normal.woff2', LATIN, '700']],
  'Noto Sans Arabic': [['noto-sans-arabic-arabic-wght-normal.woff2', ARABIC, '100 900'], ['noto-sans-arabic-latin-wght-normal.woff2', LATIN, '100 900']],
  'Noto Sans Devanagari': [['noto-sans-devanagari-devanagari-wght-normal.woff2', DEVA, '100 900'], ['noto-sans-devanagari-latin-wght-normal.woff2', LATIN, '100 900']],
  'Tajawal': [['tajawal-arabic-400-normal.woff2', ARABIC, '400'], ['tajawal-latin-400-normal.woff2', LATIN, '400'],
              ['tajawal-arabic-700-normal.woff2', ARABIC, '700'], ['tajawal-latin-700-normal.woff2', LATIN, '700']],
  'Amiri': [['amiri-arabic-700-normal.woff2', ARABIC, '700'], ['amiri-latin-700-normal.woff2', LATIN, '700']],
  'Anton': [['anton-latin-400-normal.woff2', LATIN, '400']],
  'Bebas Neue': [['bebas-neue-latin-400-normal.woff2', LATIN, '400']],
  'Pacifico': [['pacifico-latin-400-normal.woff2', LATIN, '400']],
  'Special Elite': [['special-elite-latin-400-normal.woff2', LATIN, '400']],
  'Bangers': [['bangers-latin-400-normal.woff2', LATIN, '400']],
};
export const FONT_FAMILIES = Object.keys(FONT_FILES);
export const ARABIC_FONTS = ['Cairo', 'Tajawal', 'Noto Naskh Arabic', 'Noto Sans Arabic', 'Amiri', 'Noto Nastaliq Urdu'];

let registered = null;
function register() {
  if (registered) return registered;
  registered = [];
  if (typeof FontFace === 'undefined' || typeof document === 'undefined') return registered;
  for (const [family, files] of Object.entries(FONT_FILES)) {
    for (const [file, range, weight] of files) {
      const ff = new FontFace(family, `url(${BASE}${file}) format('woff2')`, { unicodeRange: range, weight, display: 'block' });
      document.fonts.add(ff);
      registered.push(ff);
    }
  }
  return registered;
}

/** Load every caption font (≈0.8 MB, local). Export awaits this so frame 1 is never a fallback face. */
let all = null;
export function loadCaptionFonts() {
  if (!all) all = Promise.all(register().map((f) => f.load().catch(() => null))).then(() => true);
  return all;
}
export function fontsReady() { return !!registered && registered.every((f) => f.status === 'loaded'); }
// Register as soon as the module loads so the faces exist before the first draw asks for them.
register();
