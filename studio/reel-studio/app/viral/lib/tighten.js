// KBB Studio · viral · jump-cut tightening (pure ESM).
// Every gap between two caption words longer than `minGap` is cut down to `pad` of breathing room on each side —
// never inside a word (cut edges keep `pad` from the words), and never over sound the voice detector calls speech
// (a word Whisper missed). The cut is then made on the TIMELINE by cutRanges() (timeline.js), with micro-fades.

/**
 * words   [{t0,t1,w}] on the timeline, sorted
 * speech  [[a,b]] voice-activity ranges on the timeline (optional)
 * opt     { minGap: 0.12, pad: 0.04, maxGap: Infinity (gaps longer than this are left: a deliberate pause), minCut: 0.05,
 *           voiced(a,b) -> bool: is that stretch loud enough to be a word (not a breath)? }
 * -> [{ a, b, gap, kind:'breath'|'pause' }]   ranges to remove (timeline s), ascending, non-overlapping
 */
export function planTighten(words = [], speech = null, opt = {}) {
  const minGap = opt.minGap ?? 0.12, pad = opt.pad ?? 0.04, maxGap = opt.maxGap ?? Infinity, minCut = opt.minCut ?? 0.05;
  const out = [];
  const ws = words.filter((w) => w && w.t1 > w.t0).slice().sort((x, y) => x.t0 - y.t0);
  for (let i = 0; i + 1 < ws.length; i++) {
    const a0 = ws[i].t1, b0 = ws[i + 1].t0, gap = b0 - a0;
    if (gap < minGap || gap > maxGap) continue;
    let ranges = [[a0 + pad, b0 - pad]];
    // keep any stretch the voice detector heard as speech longer than a breath (> 0.15 s): a missed word
    if (speech) for (const [s0, s1] of speech) {
      if (s1 - s0 < 0.15) continue;
      const lo = Math.max(s0, a0 + 0.02), hi = Math.min(s1, b0 - 0.02);
      if (hi - lo < 0.15) continue;   // the edges of the neighbouring words, not a word of its own
      if (opt.voiced && !opt.voiced(lo, hi)) continue;   // a breath: far quieter than the words around it
      ranges = ranges.flatMap(([x, y]) => (hi <= x || lo >= y ? [[x, y]] : [[x, lo - pad], [hi + pad, y]]));
    }
    for (const [x, y] of ranges) if (y - x >= minCut) out.push({ a: +x.toFixed(4), b: +y.toFixed(4), gap: +gap.toFixed(4), kind: gap < 0.3 ? 'breath' : 'pause' });
  }
  return out;
}

/** total seconds removed */
export const removedBy = (cuts) => cuts.reduce((s, c) => s + (c.b - c.a), 0);
