// KBB Studio · recipe caption styles -> the caption module's real style ids (app/captions/styles.js).
// ONE mapping table. Recipes were written with descriptive names; the captions module ships its own ids.
// Every recipe resolves through captionStyleFor(), and tools/int-check.cjs asserts that all 64 recipes (EN and AR)
// land on a style the captions module actually has.

export const CAPTION_STYLE_MAP = {
  'highlight-marker': 'marker',
  'news-lower-third': 'news',
  'lower-third': 'corporate',
  'word-highlight': 'word-box',
  'word-by-word': 'word-box',
  'karaoke-fill': 'karaoke',
  'pop': 'pop-in',
  'one-word-big': 'one-word',
  'outline': 'bold-outline',
  'pastel-pill': 'soft-beauty',
  'serif': 'luxury-serif',
  'arabic': 'arabic-first',
  'bilingual-en-ar': 'bilingual',
  'info': 'info-card',
};

export const FALLBACK_STYLE = 'word-box';
export const FALLBACK_STYLE_AR = 'arabic-first';

/** a recipe's caption style id, as the captions module knows it */
export const mapCaptionStyle = (id) => (id && CAPTION_STYLE_MAP[id]) || id || null;

/** caption style id for a recipe in a language ('ar' picks the Arabic one) */
export function captionStyleFor(recipe, lang = 'en') {
  if (!recipe) return null;
  return lang === 'ar' ? mapCaptionStyle(recipe.captionStyleAr) || FALLBACK_STYLE_AR : mapCaptionStyle(recipe.captionStyle) || FALLBACK_STYLE;
}
