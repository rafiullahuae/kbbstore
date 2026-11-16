// Translation metrics, pure JS (no DOM): chrF (character n-gram F-score, Popović 2015, as sacreBLEU 2.x computes
// it: n = 1..6, beta = 2, whitespace removed, corpus statistics summed then F averaged over the orders) and BLEU
// (4-gram, brevity penalty, a 13a-like tokeniser that also splits Arabic / Urdu / Devanagari punctuation).
// Also used at run time: sentence chrF between the source and a back-translation is the per-line quality signal.

const strip = (s) => String(s || '').normalize('NFC').replace(/\s+/g, '');

function charGrams(s, n) {
  const m = new Map();
  const a = [...s];
  for (let i = 0; i + n <= a.length; i++) { const g = a.slice(i, i + n).join(''); m.set(g, (m.get(g) || 0) + 1); }
  return m;
}

/** chrF statistics for one pair: [hyp_n, ref_n, match_n] per order (flat). */
export function chrfStats(hyp, ref, order = 6) {
  const h = strip(hyp), r = strip(ref), out = [];
  for (let n = 1; n <= order; n++) {
    const H = charGrams(h, n), R = charGrams(r, n);
    let hn = 0, rn = 0, mt = 0;
    for (const v of H.values()) hn += v;
    for (const [g, v] of R) { rn += v; const x = H.get(g); if (x) mt += Math.min(x, v); }
    out.push(hn, rn, mt);
  }
  return out;
}

function chrfFromStats(st, beta = 2) {
  const f2 = beta * beta;
  let score = 0, eff = 0;
  for (let i = 0; i < st.length; i += 3) {
    const [hn, rn, mt] = st.slice(i, i + 3);
    const p = hn > 0 ? mt / hn : 1e-16, r = rn > 0 ? mt / rn : 1e-16;
    const d = f2 * p + r;
    score += d > 0 ? (1 + f2) * p * r / d : 1e-16;
    if (hn > 0 && rn > 0) eff++;
  }
  return eff ? 100 * score / eff : 0;
}

/** Sentence chrF (0..100). */
export const chrf = (hyp, ref) => chrfFromStats(chrfStats(hyp, ref));

/** Corpus chrF over parallel arrays. */
export function corpusChrf(hyps, refs) {
  let acc = null;
  hyps.forEach((h, i) => { const s = chrfStats(h, refs[i]); acc = acc ? acc.map((v, k) => v + s[k]) : s; });
  return acc ? chrfFromStats(acc) : 0;
}

// ------------------------------------------------------------------ BLEU
const PUNCT = /([.,!?;:()"'“”«»،؛؟।۔%])/g;
export const tokenize = (s) => String(s || '').normalize('NFC').toLowerCase().replace(PUNCT, ' $1 ').split(/\s+/).filter(Boolean);

export function corpusBleu(hyps, refs) {
  const match = [0, 0, 0, 0], total = [0, 0, 0, 0];
  let hl = 0, rl = 0;
  hyps.forEach((h0, k) => {
    const h = tokenize(h0), r = tokenize(refs[k]);
    hl += h.length; rl += r.length;
    for (let n = 1; n <= 4; n++) {
      const R = new Map();
      for (let i = 0; i + n <= r.length; i++) { const g = r.slice(i, i + n).join(' '); R.set(g, (R.get(g) || 0) + 1); }
      const H = new Map();
      for (let i = 0; i + n <= h.length; i++) { const g = h.slice(i, i + n).join(' '); H.set(g, (H.get(g) || 0) + 1); }
      for (const [g, v] of H) { total[n - 1] += v; match[n - 1] += Math.min(v, R.get(g) || 0); }
    }
  });
  if (!hl) return 0;
  let logp = 0;
  for (let n = 0; n < 4; n++) {
    if (!total[n]) return 0;
    logp += Math.log(Math.max(match[n], 1e-9) / total[n]) / 4;   // no smoothing beyond 1e-9 (corpus level)
  }
  const bp = hl > rl ? 1 : Math.exp(1 - rl / hl);
  return 100 * bp * Math.exp(logp);
}
