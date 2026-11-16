// KBB Studio · content · long video → shorts planner (pure, no DOM; runs in Node for the checks too).
// 1. sentences from the transcript (punctuation / pauses)
// 2. topic segmentation: TextTiling — lexical similarity of the 3 sentences before and after every boundary, dips in
//    similarity (depth) plus long pauses mark a change of topic
// 3. per topic, the best SELF-CONTAINED run of whole sentences of 20–60 s: it must not start on a dangling word
//    ("and", "so", "this", "it"…), it ends on a full stop, and it scores on hook strength, topic words, delivery
// 4. 8–12 shorts: one per topic, more from long topics when there are few, the best 12 when there are many
import { sentences } from '../../auto/lib/plan.js';
import { termVector, cosine, topTerms, normWord, STOP } from './keywords.js';
import { isFiller } from '../../captions/model.js';

const DANGLING = new Set(('and but so because also then which that it its this these those they them he she him her or plus anyway however as too ' +
  'و ف لكن بس هذا هذه ذلك هو هي هم').split(/\s+/).map(normWord));
const HOOK = /\?|\b(you|your|how|why|what|secret|mistake|never|always|biggest|best|worst|truth|nobody|stop|need to know|here's|here is|tip|number one|\d+)\b/i;
const END = /[.!?؟…]["')\]]*$/;

/** topic boundaries (indices into the sentence list where a new topic starts) */
export function topicBoundaries(sents, words, { k = 3, minSents = 4 } = {}) {
  const n = sents.length; if (n < minSents * 2) return [0];
  const vec = (a, b) => termVector(words.slice(sents[a].i0, sents[b].i1 + 1));
  const sim = [];
  for (let i = 0; i < n - 1; i++) {
    const L = vec(Math.max(0, i - k + 1), i), R = vec(i + 1, Math.min(n - 1, i + k));
    sim.push(cosine(L, R));
  }
  const depth = sim.map((s, i) => {
    let l = s; for (let j = i; j >= 0; j--) { if (sim[j] < l) break; l = sim[j]; }
    let r = s; for (let j = i; j < sim.length; j++) { if (sim[j] < r) break; r = sim[j]; }
    const pause = sents[i + 1].t0 - sents[i].t1;
    return (l - s) + (r - s) + (pause > 1.4 ? 0.25 : pause > 0.9 ? 0.1 : 0);
  });
  const mean = depth.reduce((a, b) => a + b, 0) / depth.length, sd = Math.sqrt(depth.reduce((a, b) => a + (b - mean) ** 2, 0) / depth.length);
  const cut = mean + 0.5 * sd, out = [0];
  const order = depth.map((d, i) => [d, i]).filter(([d]) => d > cut).sort((a, b) => b[0] - a[0]);
  for (const [, i] of order) { const b = i + 1; if (out.every((x) => Math.abs(x - b) >= minSents)) out.push(b); }
  return out.sort((a, b) => a - b);
}

/** score a run of sentences [a..b] as a stand-alone short */
function scoreRun(sents, words, a, b, topic, energy) {
  const first = sents[a], last = sents[b], dur = last.t1 - first.t0;
  const ws = words.slice(first.i0, last.i1 + 1);
  const firstWord = normWord(words[first.i0].w);
  const dangling = DANGLING.has(firstWord) && !first.question;   // "So, what…?" opens a topic; "So it…" hangs on the last one
  const hook = HOOK.test(first.text) ? 1 : 0;
  const ends = END.test(String(words[last.i1].w).trim()) ? 1 : 0;
  const tw = ws.filter((w) => topic.has(normWord(w.w))).length / Math.max(1, ws.length);
  const fill = ws.filter((w) => isFiller(w.w)).length / Math.max(1, ws.length);
  const len = dur < 25 ? (dur - 20) / 5 : dur > 50 ? (60 - dur) / 10 : 1;
  const en = energy ? energy(first.t0, last.t1) : 0.5;
  return { dur, score: 0.3 * hook + 0.2 * ends + 0.2 * Math.min(1, tw * 8) + 0.15 * Math.max(0, len) + 0.15 * en - 0.6 * fill - (dangling ? 1 : 0), dangling, hook, ends };
}

/**
 * Plan shorts. words: transcript of the long video (source times). opts: { min=20, max=60, count:[8,12], energy(t0,t1)->0..1 }
 * Returns { shorts:[{t0,t1,dur,s0,s1,text,title,topic,score,firstWord}], topics:[{s0,s1,t0,t1,terms}], sentences:n }.
 */
export function planShorts(words, opts = {}) {
  const min = opts.min ?? 20, max = opts.max ?? 60, [lo, hi] = opts.count || [8, 12];
  const sents = sentences(words, { maxGap: 1.1, maxWords: 48 });
  if (!sents.length) return { shorts: [], topics: [], sentences: 0 };
  const bounds = topicBoundaries(sents, words);
  const topics = bounds.map((s0, i) => { const s1 = (bounds[i + 1] ?? sents.length) - 1; const text = sents.slice(s0, s1 + 1).map((s) => s.text).join(' ');
    return { s0, s1, t0: sents[s0].t0, t1: sents[s1].t1, terms: topTerms(text, 8) }; });
  const runs = [];
  topics.forEach((tp, ti) => {
    const topic = new Set(tp.terms.map(normWord));
    for (let a = tp.s0; a <= tp.s1; a++) for (let b = a; b <= tp.s1; b++) {
      const d = sents[b].t1 - sents[a].t0;
      if (d > max) break; if (d < min) continue;
      const r = scoreRun(sents, words, a, b, topic, opts.energy);
      // a short that opens where its topic opens (or on a question) explains itself: it needs nothing said before it
      if (a === tp.s0 || sents[a].question) r.score += 0.2;
      if (r.dangling) continue;
      runs.push({ ti, a, b, ...r });
    }
  });
  runs.sort((x, y) => y.score - x.score);
  const chosen = [], overlaps = (r) => chosen.some((c) => !(r.b < c.a || r.a > c.b));
  for (const tp of topics.map((_, i) => i)) { const r = runs.find((x) => x.ti === tp && !overlaps(x)); if (r) chosen.push(r); }
  // too few topics: more runs from the longest topics; too many: keep the best
  while (chosen.length < lo) { const r = runs.find((x) => !overlaps(x)); if (!r) break; chosen.push(r); }
  chosen.sort((x, y) => y.score - x.score); chosen.length = Math.min(chosen.length, hi);
  chosen.sort((x, y) => x.a - y.a);
  const shorts = chosen.map((r) => {
    const text = sents.slice(r.a, r.b + 1).map((s) => s.text).join(' ');
    return { t0: sents[r.a].t0, t1: sents[r.b].t1, dur: +(sents[r.b].t1 - sents[r.a].t0).toFixed(2), s0: r.a, s1: r.b, i0: sents[r.a].i0, i1: sents[r.b].i1, text,
      title: hookTitle(sents[r.a].text, topics[r.ti].terms), topic: r.ti, score: +r.score.toFixed(3), firstWord: String(words[sents[r.a].i0].w).trim() };
  });
  return { shorts, topics, sentences: sents.length };
}

/** the on-screen hook: the opening line if it is short and hooky, else "The truth about <topic>" */
export function hookTitle(first, terms) {
  // drop a spoken lead-in ("So,", "Okay,", "Now,") and start with a capital
  let t = String(first).replace(/\s+/g, ' ').trim().replace(/^(so|okay|ok|now|well|right|and|alright)[,.]?\s+/i, '');
  t = t.charAt(0).toUpperCase() + t.slice(1);
  const words = t.split(' ');
  if (HOOK.test(t) && words.length <= 9) return t.replace(/[.,]$/, '');
  if (words.length <= 7) return t.replace(/[.,]$/, '');
  const term = (terms || []).find((x) => !STOP.has(x)) || words.find((w) => w.length > 4) || 'this';
  return /\?/.test(t) ? words.slice(0, 8).join(' ') + '…?' : `The truth about ${term}`;
}
