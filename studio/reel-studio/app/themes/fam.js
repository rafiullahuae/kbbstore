// KBB Studio · themes · DESIGN FAMILIES: the one entry point the Ad Studio and the reels use.
//
//   FAM_PIECES                  id -> {fam, name, nameAr, style, words, roles?, over?, draw}
//   famOf(id) / famPieceIds(fam) which family a piece is in / the pieces of a family
//   famWords(id, lang)          the words a piece draws, defaults in English or Arabic
//   drawFam(c, id, box, t, o)   draw a piece inside box {x, y, w, h} at t (o: {P?, fam?, K, flag, words, role, rtl, ar})
import { FAM_PAL, FAMILIES, FAMILY_LABELS, FAMILY_LABELS_AR, FAMILY_ICONS, TRADES, TRADE_IDS, tradeOf, setMirror } from './families.js';
import { PIECES } from './fampieces.js';
import './fam-travel.js';
import './fam-work.js';
import './fam-future.js';

export { FAM_PAL, FAMILIES, FAMILY_LABELS, FAMILY_LABELS_AR, FAMILY_ICONS, TRADES, TRADE_IDS, tradeOf };
export const FAM_PIECES = PIECES;
export const famOf = (id) => PIECES[id]?.fam || null;
export const famPieceIds = (fam) => Object.keys(PIECES).filter((k) => !fam || PIECES[k].fam === fam);
export const famWords = (id, lang = 'en') => Object.fromEntries(Object.entries(PIECES[id]?.words || {}).map(([k, v]) => [k, v[lang === 'ar' ? 1 : 0]]));

/** draw one piece inside its box; a right-to-left piece runs its direction mirrored while its words still read */
export function drawFam(c, id, { x, y, w, h }, t, o = {}) {
  const pc = PIECES[id]; if (!pc || w < 2 || h < 2) return;
  const P = o.P || FAM_PAL[o.fam || pc.fam];
  const words = Object.assign(famWords(id, o.ar ? 'ar' : 'en'), o.words || {});
  c.save();
  c.beginPath(); c.rect(x, y, w, h); c.clip();
  c.translate(x, y);
  if (o.rtl) { c.translate(w, 0); c.scale(-1, 1); }
  setMirror(!!o.rtl);
  try { pc.draw(c, w, h, Math.max(0, t), { ...o, P, words, K: o.K }); }
  finally { setMirror(false); c.restore(); }
}
