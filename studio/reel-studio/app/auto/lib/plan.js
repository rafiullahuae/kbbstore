// KBB Studio · automation · sentences, highlight scoring, Auto reel and Remove-silences planning (pure ESM).
import { normWord, fillers as findFillers } from './audio.js';

const END = /[.!?؟。…]$/;
/** Group transcript words into sentences: punctuation, a long pause, or a hard length cap. */
export function sentences(words = [], opt = {}) {
  const maxGap = opt.maxGap ?? 0.7, maxWords = opt.maxWords ?? 22, out = [];
  let a = 0;
  for (let i = 0; i < words.length; i++) {
    const w = words[i], next = words[i + 1];
    const brk = !next || END.test(String(w.w).trim()) || next.t0 - w.t1 > maxGap || i - a + 1 >= maxWords;
    if (brk) {
      const ws = words.slice(a, i + 1), text = ws.map(x => x.w).join(' ').replace(/\s+([,.!?؟،])/g, '$1');
      out.push({ i0: a, i1: i, t0: words[a].t0, t1: w.t1, text, question: /[?؟]\s*$/.test(text) });
      a = i + 1;
    }
  }
  return out;
}

const HOOK_EN = ['you', 'your', 'how', 'why', 'what', 'secret', 'best', 'never', 'stop', 'free', 'new', 'only', 'today', 'finally', 'mistake', 'must', 'need', 'wow', 'love', 'top', 'easy', 'fast', 'guaranteed', 'approved', 'instantly', 'sale', 'off', 'deal', 'hack', 'tip', 'truth', 'step', 'steps', 'watch', 'look', 'this'];
const HOOK_AR = ['انت', 'انتي', 'كيف', 'ليش', 'لماذا', 'سر', 'افضل', 'ابدا', 'مجانا', 'مجاني', 'جديد', 'فقط', 'اليوم', 'خصم', 'عرض', 'نصيحه', 'خطوات', 'شوف', 'لازم', 'وفر'];
const CTA_RX = [/link in (my )?bio/i, /shop now/i, /order (now|today)/i, /book (now|a|your)/i, /call (us|now)/i, /\bdm\b/i, /message us/i, /visit (us|our)/i, /follow (us|for)/i, /sign up/i, /register/i, /apply (now|today)/i, /whatsapp/i, /use (the )?code/i, /tap (the )?link/i, /get yours/i, /swipe up/i,
  /اطلب/, /احجز/, /تواصل/, /رابط/, /سجل/, /اتصل/, /قدم الان/, /تابع/, /واتساب/];
export const isCta = text => CTA_RX.some(r => r.test(text));
const HOOKS = new Set([...HOOK_EN, ...HOOK_AR.map(normWord)]);

function meanDb(energy, t0, t1) {
  if (!energy) return null;
  const a = Math.max(0, Math.floor(t0 / energy.hop)), b = Math.min(energy.db.length, Math.ceil(t1 / energy.hop));
  if (b <= a) return null; let s = 0; for (let i = a; i < b; i++) s += energy.db[i]; return s / (b - a);
}
function frameStat(frames, t0, t1) {
  if (!frames || !frames.length) return null;
  const fs = frames.filter(f => f.t >= t0 && f.t < t1); if (!fs.length) return null;
  return { motion: fs.reduce((s, f) => s + f.motion, 0) / fs.length, bad: fs.filter(f => f.bad).length / fs.length, faces: fs.filter(f => f.face).length / fs.length };
}

/**
 * Score candidate units (sentences, or 3 s windows when there is no transcript).
 * ctx = { duration, words, energy:{hop,db}, frames:[{t,motion,bad,face?}], keywords:[...] }
 */
export function scoreUnits(ctx) {
  const { words = [], duration } = ctx;
  let units;
  if (words.length) units = sentences(words);
  else {
    // no transcript: scene-aware windows of ~3 s
    units = []; const cuts = [0, ...(ctx.cuts || []).map(c => c.t), duration];
    for (let k = 0; k < cuts.length - 1; k++) for (let t = cuts[k]; t < cuts[k + 1] - 0.8; t += 3) units.push({ t0: t, t1: Math.min(cuts[k + 1], t + 3), text: '', i0: -1, i1: -1 });
  }
  const dbs = units.map(u => meanDb(ctx.energy, u.t0, u.t1)).filter(v => v != null).sort((a, b) => a - b);
  const lo = dbs[Math.floor(dbs.length * 0.1)] ?? -40, hi = dbs[Math.floor(dbs.length * 0.9)] ?? -10;
  const kw = new Set((ctx.keywords || []).map(normWord));
  const fl = words.length ? findFillers(words) : [];
  for (const u of units) {
    const d = u.t1 - u.t0, ws = u.i0 >= 0 ? words.slice(u.i0, u.i1 + 1).map(w => normWord(w.w)) : [];
    const e = meanDb(ctx.energy, u.t0, u.t1), energy = e == null ? 0.5 : Math.max(0, Math.min(1, (e - lo) / Math.max(3, hi - lo)));
    const v = frameStat(ctx.frames, u.t0, u.t1);
    const hookHits = ws.filter(w => HOOKS.has(w)).length + (u.question ? 2 : 0) + ws.filter(w => /\d/.test(w)).length;
    const kwHits = ws.filter(w => kw.has(w)).length;
    const fillerShare = ws.length ? fl.filter(f => f.i >= u.i0 && f.i <= u.i1 && f.kind !== 'soft').length / ws.length : 0;
    const rate = ws.length / Math.max(0.5, d);
    u.cta = isCta(u.text || '');
    u.hook = Math.min(1, hookHits / 3);
    u.score = 0.32 * energy + 0.22 * u.hook + 0.12 * Math.min(1, kwHits / 2) + 0.1 * Math.min(1, rate / 3)
      + (v ? 0.14 * Math.min(1, v.motion * 25) + 0.1 * v.faces - 0.35 * v.bad : 0.1)
      - 0.5 * fillerShare - (d > 9 ? 0.15 : 0) - (d < 0.8 ? 0.2 : 0);
    u.reasons = [energy > 0.66 && 'lively delivery', u.hook > 0.3 && 'hook words', kwHits && 'on-topic', v && v.bad > 0.3 && 'shaky/blurred/dark', u.cta && 'call to action'].filter(Boolean);
    u.dur = d;
  }
  return units;
}

/**
 * Auto reel. Picks whole sentences to a target length, strongest hook first, CTA last, cut points on beats.
 * opt = { target: 15|30|60, endCard: {min, max} | null, beats: [timeline beat s] | null, maxPadIn: .2, maxPadOut: .45 }
 * Returns { segments:[{in,out,t0,role,text,score}], endCard:{t0,dur}|null, total }.
 */
export function planAutoReel(ctx, opt = {}) {
  const target = opt.target || 30, end = opt.endCard === undefined ? { min: 1.5, max: 3.5 } : opt.endCard;
  const ex = opt._exclude || [], units = scoreUnits(ctx).filter(u => u.dur >= 0.6 && !ex.includes(u.t0));
  if (!units.length) return { segments: [], endCard: null, total: 0, units };
  const padIn = opt.maxPadIn ?? 0.2, padOut = opt.maxPadOut ?? 0.45, dur = ctx.duration;
  // room around each unit (silence before/after, bounded by neighbours)
  const sorted = units.slice().sort((a, b) => a.t0 - b.t0);
  sorted.forEach((u, k) => { u.roomIn = Math.min(padIn, Math.max(0, u.t0 - (k ? sorted[k - 1].t1 : 0))); u.roomOut = Math.min(padOut, Math.max(0, (k < sorted.length - 1 ? sorted[k + 1].t0 : dur) - u.t1)); });
  const L = u => u.dur + Math.min(0.05, u.roomIn) + Math.min(0.15, u.roomOut);
  const budget = target - (end ? (end.min + end.max) / 2 : 0);
  // hook: short and punchy; the first 2 s must carry speech
  const hook = units.filter(u => u.dur <= Math.max(6, target * 0.3) && !u.cta).sort((a, b) => (b.score + 0.6 * b.hook) - (a.score + 0.6 * a.hook))[0] || units[0];
  const cta = units.filter(u => u.cta && u !== hook).sort((a, b) => b.t0 - a.t0)[0] || null;
  let chosen = [hook]; let total = L(hook) + (cta ? L(cta) : 0);
  for (const u of units.slice().sort((a, b) => b.score - a.score)) {
    if (u === hook || u === cta) continue;
    if (total + L(u) <= budget + 0.25) { chosen.push(u); total += L(u); }
  }
  // fill: if far short (no end card or little material), take the best that still fits the hard target
  if (!end) for (const u of units.slice().sort((a, b) => b.score - a.score)) { if (chosen.includes(u) || u === cta) continue; if (total + L(u) <= target + 0.4) { chosen.push(u); total += L(u); } }
  const body = chosen.slice(1).sort((a, b) => a.t0 - b.t0);
  const order = [hook, ...body, ...(cta ? [cta] : [])];
  const segs = order.map((u, k) => ({ in: +(u.t0 - Math.min(0.05, u.roomIn)).toFixed(3), out: +(u.t1 + Math.min(0.15, u.roomOut)).toFixed(3), role: k === 0 ? 'hook' : (u === cta ? 'cta' : 'body'), text: u.text, score: +u.score.toFixed(3), room: [u.roomIn, u.roomOut], src: [u.t0, u.t1], reasons: u.reasons }));
  // cut on beats: move each segment's out (inside its silence room) so the cut lands on the nearest beat
  let t = 0;
  for (const s of segs) {
    s.t0 = +t.toFixed(3);
    let cut = t + (s.out - s.in);
    if (opt.beats && opt.beats.length) {
      const nb = opt.beats.reduce((a, b) => Math.abs(b - cut) < Math.abs(a - cut) ? b : a, opt.beats[0]);
      const delta = nb - cut, newOut = s.out + delta;
      if (Math.abs(delta) <= 0.3 && newOut >= s.src[1] + 0.03 && newOut <= s.src[1] + s.room[1] + 0.001) { s.out = +newOut.toFixed(3); cut = nb; s.onBeat = true; }
    }
    t = cut;
  }
  let endCard = null;
  if (end) {
    let ed = target - t;
    if (ed < end.min && segs.length > 2) { // too long: drop the weakest body segment and re-run once
      const weakest = segs.filter(s => s.role === 'body').sort((a, b) => a.score - b.score)[0];
      if (weakest) return planAutoReel(ctx, { ...opt, target, _exclude: [...ex, weakest.src[0]] });
    }
    ed = Math.max(0.8, ed); endCard = { t0: +t.toFixed(3), dur: +ed.toFixed(3) }; t += ed;
  } else if (Math.abs(target - t) > 0.4) { // stretch / trim the last segment's tail inside the source
    const last = segs[segs.length - 1], want = last.out + (target - t), maxOut = Math.min(dur, last.src[1] + 1.5);
    last.out = +Math.max(last.src[1] + 0.05, Math.min(maxOut, want)).toFixed(3); t = last.t0 + (last.out - last.in);
  }
  return { segments: segs, endCard, total: +t.toFixed(3), units };
}

/**
 * Remove silences / fillers: a review list of cuts on the SOURCE timeline.
 * analysis = { duration, silences:[[a,b]], fillers:[{t0,t1,kind,text}] }
 */
export function planCuts(analysis, opt = {}) {
  const keep = opt.keepPad ?? 0.12, minSil = opt.minSilence ?? 0.45, list = [];
  for (const [a, b] of analysis.silences || []) {
    if (b - a < minSil) continue;
    const t0 = a === 0 ? 0 : a + keep, t1 = b >= analysis.duration - 1e-3 ? analysis.duration : b - keep;
    if (t1 - t0 > 0.12) list.push({ kind: 'silence', t0: +t0.toFixed(3), t1: +t1.toFixed(3), text: `${(b - a).toFixed(1)} s pause`, checked: true });
  }
  for (const f of analysis.fillers || []) list.push({ kind: f.kind === 'soft' ? 'filler-soft' : 'filler', t0: +(f.t0 - 0.03).toFixed(3), t1: +(f.t1 + 0.03).toFixed(3), text: f.text, checked: f.kind !== 'soft' });
  list.sort((a, b) => a.t0 - b.t0);
  list.forEach((c, i) => { c.id = 'cut' + i; c.dur = +(c.t1 - c.t0).toFixed(3); });
  return list;
}
/** Merge the checked cuts and return the kept source ranges [[in,out]...]. */
export function keepRanges(duration, cuts, minKeep = 0.08) {
  const cs = cuts.filter(c => c.checked !== false).map(c => [Math.max(0, c.t0), Math.min(duration, c.t1)]).sort((a, b) => a[0] - b[0]);
  const m = []; for (const c of cs) { const l = m[m.length - 1]; if (l && c[0] <= l[1] + 0.02) l[1] = Math.max(l[1], c[1]); else m.push(c.slice()); }
  const keep = []; let t = 0;
  for (const [a, b] of m) { if (a - t >= minKeep) keep.push([t, a]); t = Math.max(t, b); }
  if (duration - t >= minKeep) keep.push([t, duration]);
  return keep;
}
/** Map a source time onto the new timeline after cutting to `keep` ranges laid back-to-back; null if cut. */
export function remap(t, keep) {
  let acc = 0;
  for (const [a, b] of keep) { if (t >= a && t <= b) return acc + (t - a); acc += b - a; }
  return null;
}
