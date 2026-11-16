// KBB Studio · automation · Auto effects planner (pure). Turns a recipe + the edit into a list of effect events;
// commands.js turns events into project items drawn by layers.js (preview = export).
import { normWord } from './audio.js';

const EMOJI = [
  [/^(love|loved|lovely|obsessed|احب|حبيت|عشق)$/, '😍'], [/^(wow|amazing|incredible|رائع|واو|خيالي)$/, '🤩'], [/^(sale|deal|offer|discount|off|عرض|خصم|تخفيض)$/, '🔥'],
  [/^(price|aed|dirham|dirhams|cheap|سعر|درهم)$/, '💸'], [/^(glow|glowing|skin|dewy|radiant|نضاره|بشره|لمعه)$/, '✨'], [/^(gift|free|surprise|هديه|مجانا|مجاني)$/, '🎁'],
  [/^(visa|passport|تاشيره|فيزا|جواز)$/, '🛂'], [/^(flight|travel|fly|plane|سفر|طياره)$/, '✈️'], [/^(study|university|student|degree|دراسه|جامعه|طالب)$/, '🎓'],
  [/^(money|salary|scholarship|منحه|راتب|فلوس)$/, '💰'], [/^(approved|yes|done|success|تمت|نجاح|موافقه)$/, '✅'], [/^(cook|cooking|recipe|طبخ|وصفه)$/, '🍳'],
  [/^(delicious|tasty|yummy|لذيذ)$/, '😋'], [/^(home|house|apartment|villa|بيت|شقه|فيلا)$/, '🏠'], [/^(gym|workout|strong|تمرين|جيم)$/, '💪'],
  [/^(time|deadline|hurry|today|موعد|بسرعه|اليوم)$/, '⏰'], [/^(new|launch|جديد|اطلاق)$/, '🆕'], [/^(idea|tip|tips|نصيحه|فكره)$/, '💡'], [/^(water|hydrating|hydration|ترطيب|ماء)$/, '💧'],
  [/^(sun|spf|sunscreen|شمس|واقي)$/, '☀️'], [/^(coffee|latte|قهوه)$/, '☕'], [/^(party|celebrate|حفله|احتفال)$/, '🎉'], [/^(question|why|how|ليش|كيف|لماذا)$/, '🤔'],
];
export const emojiFor = w => { const n = normWord(w); for (const [rx, e] of EMOJI) if (rx.test(n)) return e; return null; };
const PRICE = /(?:(\d+(?:[.,]\d+)?)\s*(?:aed|dhs?|dirhams?|درهم|usd|\$|€|eur)|(?:aed|dhs|\$|€)\s*(\d+(?:[.,]\d+)?)|(\d{1,2})\s*(?:%|percent|٪|بالمئه)(?:\s*off)?)/i;

/** words with emphasis: keyword / number / caps / exclamation / emoji-able, spaced out */
export function emphasis(words, opt = {}) {
  const kw = new Set((opt.keywords || []).map(normWord)), minGap = opt.minGap ?? 2.5, out = [];
  for (let i = 0; i < words.length; i++) {
    const w = words[i], raw = String(w.w).trim(), n = normWord(raw);
    if (n.length < 2) continue;
    let s = 0;
    if (kw.has(n)) s += 2; if (/\d/.test(n)) s += 2; if (/!$/.test(raw)) s += 1.5;
    if (raw.length > 2 && raw === raw.toUpperCase() && /[A-Z]/.test(raw)) s += 1.5;
    if (emojiFor(raw)) s += 1; if (w.loud) s += 1.5;
    if (s >= 2 && (!out.length || w.t0 - out[out.length - 1].t0 >= minGap)) out.push({ i, t0: w.t0, t1: w.t1, w: raw, score: s, emoji: emojiFor(raw) });
  }
  return out;
}

/**
 * Plan effects for an edit on the TIMELINE.
 * input = { duration, cuts:[t], words:[{t0,t1,w}] (timeline), beats:[t], recipe, kit, canvas:{w,h}, text?:full transcript }
 */
export function planEffects(input) {
  const { duration, recipe = {}, canvas = { w: 1080, h: 1920 } } = input;
  const fx = recipe.effects || {}, pacing = recipe.pacing || {}, words = input.words || [];
  const ev = { punches: [], transitions: [], sfx: [], stickers: [], priceTag: null, logoIntro: null, logoOutro: null };
  // punch-ins on emphasised words
  const em = emphasis(words, { keywords: [...(recipe.keywords?.en || []), ...(recipe.keywords?.ar || [])] });
  const maxPunch = Math.round((pacing.punchIn ?? 0.5) * duration / 4);
  em.slice().sort((a, b) => b.score - a.score).slice(0, maxPunch).sort((a, b) => a.t0 - b.t0)
    .forEach(e => ev.punches.push({ t0: +Math.max(0, e.t0 - 0.04).toFixed(3), dur: +Math.min(1.2, Math.max(0.45, e.t1 - e.t0 + 0.35)).toFixed(3), amount: e.score >= 3 ? 1.16 : 1.1, word: e.w }));
  // transitions on cuts (snapped to beats when close), rotating through the recipe's list
  const kinds = (fx.transitions || ['flash']).filter(k => k && k !== 'cut');
  (input.cuts || []).forEach((t, k) => {
    if (!kinds.length) return;
    let tt = t; const b = (input.beats || []).find(x => Math.abs(x - t) < 0.08); if (b != null) tt = b;
    const kind = kinds[k % kinds.length], dur = { flash: 0.24, whip: 0.32, zoom: 0.34, glitch: 0.3, leak: 0.7, dissolve: 0.5, slide: 0.36, wipe: 0.4 }[kind] || 0.3;
    ev.transitions.push({ t: +tt.toFixed(3), kind, dur });
    if (fx.sfx?.transition) ev.sfx.push({ id: fx.sfx.transition, t: +tt.toFixed(3), align: 'hit', role: 'transition' });
  });
  // emoji / sticker accents
  if (fx.emoji) {
    let n = 0;
    for (const e of em) {
      if (!e.emoji || n >= Math.max(2, Math.round(duration / 6))) continue;
      const side = n % 2 ? 0.72 : 0.28, size = Math.round(canvas.w * 0.16);
      ev.stickers.push({ t0: e.t0, dur: 1.4, text: e.emoji, x: Math.round(canvas.w * side - size / 2), y: Math.round(canvas.h * (0.3 + 0.08 * (n % 3))), size });
      if (fx.sfx?.pop) ev.sfx.push({ id: fx.sfx.pop, t: e.t0, align: 'start', role: 'pop' });
      n++;
    }
  }
  // price / offer tag
  if (fx.priceTag) {
    const txt = input.text || words.map(w => w.w).join(' ');
    const m = PRICE.exec(txt);
    let at = 0.6;
    if (m) { const tok = (m[1] || m[2] || m[3]); const w = words.find(x => String(x.w).includes(tok)); if (w) at = w.t0; }
    ev.priceTag = { t0: +Math.max(0.3, at - 0.1).toFixed(3), dur: 3.2, price: m ? (m[3] ? `${m[3]}% OFF` : `AED ${m[1] || m[2]}`) : (recipe.id?.includes('sale') ? 'SALE' : 'NEW'), label: recipe.id?.includes('gift') ? 'FREE GIFT' : 'OFFER' };
    if (fx.sfx?.accent) ev.sfx.push({ id: fx.sfx.accent, t: ev.priceTag.t0 + 0.15, align: 'start', role: 'accent' });
  }
  if (fx.logoIntro) { ev.logoIntro = { t0: 0, dur: 1.3 }; }
  if (fx.logoOutro && input.endCard == null) ev.logoOutro = { t0: Math.max(0, duration - 1.6), dur: 1.6 };
  return ev;
}
