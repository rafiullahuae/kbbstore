// KBB Studio · viral · the NATURAL HOOKS library (pure ESM): ten video hooks (pattern interrupts) and how they pair
// with the synthesised sound hooks in library/sfx/hooks/library.json, per industry and mood.

export const VIDEO_HOOKS = [
  { id: 'snap-zoom', name: 'Snap zoom', icon: '🔍', what: 'A hard punch-in with a white pop on the first frame', role: 'hit' },
  { id: 'freeze-scratch', name: 'Freeze-frame + record scratch', icon: '⏸️', what: 'The first frame freezes, a record scratch, then it plays', role: 'accent', needs: 'first' },
  { id: 'whip-in', name: 'Whip-in', icon: '💨', what: 'The picture streaks in from the side and settles', role: 'transition' },
  { id: 'reveal-mask', name: 'Reveal mask', icon: '⭕', what: 'A circle opens from black on the subject', role: 'riser' },
  { id: 'before-after', name: 'Before → after flash', icon: '✨', what: 'The result flashes first ("AFTER"), then "BEFORE" and the story', role: 'accent', needs: 'last' },
  { id: 'object-toss', name: 'Object toss', icon: '🎯', what: 'A product / passport flies across and wipes into the video', role: 'transition' },
  { id: 'face-open', name: 'Face close-up open', icon: '🙂', what: 'Opens tight on the face and pulls back', role: 'hit' },
  { id: 'speed-ramp', name: 'Speed-ramp in', icon: '⏩', what: 'The lead-in plays fast and lands on the first word', role: 'riser' },
  { id: 'shake-impact', name: 'Shake / impact', icon: '💥', what: 'A camera shake and flash on the hit', role: 'hit' },
  { id: 'light-leak', name: 'Light-leak reveal', icon: '🌅', what: 'A warm light leak washes the picture in', role: 'accent' },
];
export const hookById = (id) => VIDEO_HOOKS.find((h) => h.id === id) || null;

/** the pairs "Add a hook" rotates through, best first, per industry */
export const PAIRS = {
  beauty: [['snap-zoom', 'hook-shutter'], ['light-leak', 'hook-spray'], ['before-after', 'hook-shutter'], ['face-open', 'hook-asmr-taps'], ['object-toss', 'hook-swoosh'],
    ['reveal-mask', 'hook-ding'], ['freeze-scratch', 'hook-record-scratch'], ['whip-in', 'hook-whip'], ['shake-impact', 'hook-bass-drop'], ['speed-ramp', 'hook-riser']],
  visa: [['shake-impact', 'hook-stamp-thud'], ['snap-zoom', 'hook-hit'], ['reveal-mask', 'hook-boarding-chime'], ['whip-in', 'hook-page-flip'], ['object-toss', 'hook-swoosh'],
    ['freeze-scratch', 'hook-record-scratch'], ['face-open', 'hook-bass-drop'], ['speed-ramp', 'hook-riser'], ['light-leak', 'hook-ding'], ['before-after', 'hook-shutter']],
  generic: [['snap-zoom', 'hook-hit'], ['whip-in', 'hook-swoosh'], ['freeze-scratch', 'hook-record-scratch'], ['shake-impact', 'hook-bass-drop'], ['reveal-mask', 'hook-riser'],
    ['face-open', 'hook-hit'], ['object-toss', 'hook-swoosh'], ['light-leak', 'hook-ding'], ['speed-ramp', 'hook-riser'], ['before-after', 'hook-shutter']],
};
/** an object for the toss, per industry */
export const TOSS = { beauty: '🧴', visa: '🛂', generic: '🎁' };

const CALM = new Set(['calm', 'asmr', 'luxury', 'corporate']);
/**
 * Pick the pair for this reel. want = { industry, energy:'low'|'mid'|'high', index (rotation), exclude:[hookId] }
 * Calm recipes (spa, luxury) get texture/accent sounds before hits; energetic ones keep hits first.
 */
export function pickPair(sounds, want = {}) {
  const list = (PAIRS[want.industry] || PAIRS.generic).filter(([h]) => !(want.exclude || []).includes(h));
  const scored = list.map(([h, s], i) => {
    const e = sounds.find((x) => x.id === s);
    const calm = e?.moods?.some((m) => CALM.has(m)) ? 1 : 0;
    const bias = want.energy === 'low' ? -calm * 2.5 : want.energy === 'high' ? calm * 1.5 : 0;
    return { hook: h, sound: s, k: i + bias };
  }).sort((a, b) => a.k - b.k);
  return scored[(want.index || 0) % scored.length];
}

/** sounds for a hook outside the rotation (the library panel): the pair's own sound, else a role match */
export function soundsFor(hookId, sounds, industry) {
  const own = (PAIRS[industry] || PAIRS.generic).find(([h]) => h === hookId)?.[1];
  const role = hookById(hookId)?.role;
  return [own, ...sounds.filter((s) => s.role === role && (s.industries.includes('any') || s.industries.includes(industry === 'visa' ? 'immigration' : industry))).map((s) => s.id)].filter((x, i, a) => x && a.indexOf(x) === i);
}
