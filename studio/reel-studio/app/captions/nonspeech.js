// Non-speech handling for captions. Pure functions (no DOM): run in the page, the speech worker or Node.
//
//   soundTagOf(word)                 'music' | 'applause' | 'laughter' | 'blank' | 'sound' | null   ("[Music]", "♪", "(تصفيق)")
//   tagWords(words)                  marks every word of a bracketed / ♪ tag with w.tag (multi-word "(upbeat music)" too)
//   classifyAudio(pcm16k)            per-second picture of the soundtrack: silence / speech / music (+ speechiness curve)
//   findHallucinations(words, an)    indices Whisper invented: "Thanks for watching" in silence or music, loops,
//                                    words sitting in pure silence, prompt echoes
//   cleanNonSpeech(words, an, opts)  -> { words, dropped, music }   the whole post-pass used by auto.js
//   displayWord(w, mode)             what a caption shows for a word under the "Music & sound tags" setting
//
// The setting lives in project.captions.soundTags: 'hide' (default) | 'icon' | 'text'.

const SR = 16000;
export const TAG_MODES = [
  { id: 'hide', label: 'Hide' }, { id: 'icon', label: 'Show ♪ icon' }, { id: 'text', label: 'Keep as text' },
];
export const DEFAULT_TAG_MODE = 'hide';
export const TAG_ICON = { music: '♪', applause: '👏', laughter: '😂', blank: '', sound: '🔊', lyrics: '♪' };

// What sits inside the brackets. English + Arabic (and a little Hindi/Urdu), lower-cased, diacritics off.
const KIND_WORDS = [
  ['music', /music|musik|musique|song|singing|instrumental|melody|beat|موسيق|اغني|أغني|غناء|لحن|संगीत|موسیقی/],
  ['applause', /applau|clap|cheer|تصفيق|تصفيقات|हुर्रे|ताली/],
  ['laughter', /laugh|chuckl|giggl|ضحك|ضحكات|हंसी|ہنسی/],
  ['blank', /blank_audio|blank audio|silence|no audio|inaudible|صمت|سكوت/],
  ['sound', /noise|sound|sigh|cough|breath|wind|bell|beep|door|footstep|whoosh|static|crowd|بكاء|ضجيج|صوت|سعال/],
];
const OPEN = /^[\[(（【♪♫🎵🎶*]/u, CLOSE = /[\])）】♪♫🎵🎶*][.,!?…،]*$/u;
const NOTE_RE = /[♪♫🎵🎶]/u;

function kindOf(inner) {
  const s = inner.toLowerCase().replace(/[ً-ْٰـ]/g, '');
  for (const [k, re] of KIND_WORDS) if (re.test(s)) return k;
  return null;
}

/** The tag kind of ONE word, or null for an ordinary word. */
export function soundTagOf(word) {
  const w = String(word || '').trim();
  if (!w) return null;
  if (/^[♪♫🎵🎶\s.,!?…-]+$/u.test(w)) return 'music';
  const m = /^[\[(（【*]\s*(.*?)\s*[\])）】*][.,!?…،]*$/u.exec(w);
  if (m) return kindOf(m[1]) || (m[1].length <= 24 ? 'sound' : null);
  return null;
}

/**
 * Mark tags on a word list (returns NEW word objects where something changed). Handles:
 *  "[Music]" / "(applause)" / "♪" as one word; "(upbeat" "music)" spread over up to 5 words;
 *  "♪ la la la ♪" (sung lyrics: kind 'lyrics'); "[BLANK_AUDIO]".
 */
export function tagWords(words) {
  const out = words.map((w) => (w.tag ? { ...w } : w));
  for (let i = 0; i < out.length; i++) {
    const w = String(out[i].w || '').trim();
    if (out[i].tag) continue;
    const one = soundTagOf(w);
    if (one) {
      out[i] = { ...out[i], tag: one };
      // ♪ opens sung lyrics when another ♪ closes them within 24 words: "♪ la la la ♪"
      if (one === 'music' && /^[♪♫🎵🎶]+$/u.test(w)) {
        let j = i + 1; while (j < out.length && j - i <= 24 && !NOTE_RE.test(out[j].w)) j++;
        if (j < out.length && j - i <= 24 && j > i + 1) { for (let k = i + 1; k <= j; k++) out[k] = { ...out[k], tag: NOTE_RE.test(out[k].w) && soundTagOf(out[k].w) ? 'music' : 'lyrics' }; i = j; }
      }
      continue;
    }
    // a word that starts a bracket and does not close it: look ahead for the closer
    if (OPEN.test(w) && !CLOSE.test(w)) {
      let j = i + 1, inner = w.slice(1);
      while (j < out.length && j - i <= 5) { inner += ' ' + out[j].w; if (CLOSE.test(String(out[j].w).trim())) break; j++; }
      if (j < out.length && j - i <= 5) {
        const k2 = kindOf(inner) || (inner.length <= 30 ? 'sound' : null);
        if (k2) { for (let k = i; k <= j; k++) out[k] = { ...out[k], tag: k2 }; i = j; continue; }
      }
    }
    // a word carrying a note inside ("♪Baby" / "love♪"): sung
    if (NOTE_RE.test(w)) out[i] = { ...out[i], tag: 'lyrics' };
  }
  return out;
}

/** What the caption shows for a word: the text, the icon, or null (hidden). */
export function displayWord(w, mode = DEFAULT_TAG_MODE) {
  if (!w.tag) return w.w;
  if (mode === 'text') return w.w;
  if (mode === 'icon') {
    if (w.tag === 'lyrics') return null;                       // the ♪ around them already shows
    const ic = TAG_ICON[w.tag];
    return ic || null;
  }
  return null;
}

// ------------------------------------------------------------------ audio classification
//
// Per 20 ms frame: energy (dB) and zero-crossing rate. Per 1 s window (hop 0.25 s):
//   LSTER  share of frames well below the window's mean energy — speech has syllable gaps (high), music
//          and steady noise are continuous (low)
//   HZCRR  share of frames whose ZCR jumps above 1.5x the window mean — unvoiced consonants (speech)
//   flux   how much the spectrum-ish envelope changes — rough, but separates a drone from a voice
// speechiness = a blend of those three, 0..1. Not a neural VAD; good enough to tell "music only" from
// "someone talks", which is all captions need. Everything is O(n).

function frameFeatures(x) {
  const F = 320, n = Math.floor(x.length / F);
  const db = new Float32Array(n), zcr = new Float32Array(n), hf = new Float32Array(n);
  for (let f = 0; f < n; f++) {
    const o = f * F; let s = 0, z = 0, d = 0, prev = x[o];
    for (let i = 0; i < F; i++) { const v = x[o + i]; s += v * v; if ((v >= 0) !== (prev >= 0)) z++; if (i) d += (v - prev) * (v - prev); prev = v; }
    db[f] = 10 * Math.log10(s / F + 1e-12); zcr[f] = z / F; hf[f] = s > 1e-9 ? d / s : 0; // hf: high-frequency share (first difference energy)
  }
  return { db, zcr, hf, hop: F / SR };
}

function pct(arr, p) { const a = Array.from(arr).sort((x, y) => x - y); return a.length ? a[Math.min(a.length - 1, Math.max(0, Math.floor(p * (a.length - 1))))] : 0; }

/**
 * Classify a mono 16 kHz soundtrack. Returns
 *   { hop, speechiness:Float32Array (per hop), loud:Uint8Array (per hop, energy above the floor),
 *     silence:[[t0,t1]], speech:[[t0,t1]], music:[[t0,t1]], floorDb, thrDb }
 * music = loud, continuous stretches with low speechiness (they MAY hold speech over music; captions only
 * call a stretch "music only" when Whisper also found no ordinary word in it).
 */
export function classifyAudio(x) {
  const { db, zcr, hf, hop: fh } = frameFeatures(x);
  const n = db.length, dur = x.length / SR;
  const floor = pct(db, 0.1), peak = pct(db, 0.98);
  const thr = Math.max(floor + Math.max(8, 0.25 * (peak - floor)), -58);   // speech-vs-gap level (pauses)
  // "something is playing": an absolute-ish level, so a bed of music under the whole clip still counts as sound
  // (relative to the clip's own floor, music that never stops WOULD be the floor and read as silence)
  const qthr = Math.max(-55, Math.min(-42, floor + 12));
  const W = 50, H = 12;                       // 1 s windows, 0.24 s hop
  const m = Math.max(1, Math.floor((n - W) / H) + 1);
  const sp = new Float32Array(m), loud = new Uint8Array(m);
  for (let k = 0; k < m; k++) {
    const a = k * H, b = Math.min(n, a + W);
    let e = 0, z = 0, on = 0; for (let f = a; f < b; f++) { e += Math.pow(10, db[f] / 10); z += zcr[f]; if (db[f] > qthr) on++; }
    const len = b - a, meanE = e / len, meanZ = z / len;
    let low = 0, hz = 0, flux = 0;
    for (let f = a; f < b; f++) {
      if (Math.pow(10, db[f] / 10) < 0.5 * meanE) low++;
      if (zcr[f] > 1.5 * meanZ) hz++;
      if (f > a) flux += Math.abs(db[f] - db[f - 1]);
    }
    const lster = low / len, hzcrr = hz / len, fl = flux / (len - 1);
    let hv = 0; for (let f = a; f < b; f++) hv += hf[f]; hv /= len;
    loud[k] = on / len > 0.3 ? 1 : 0;
    // speech: syllable gaps (lster 0.25-0.6), bursts of consonants, a moving envelope
    const s = 0.5 * clamp((lster - 0.08) / 0.3) + 0.25 * clamp((hzcrr - 0.04) / 0.12) + 0.25 * clamp((fl - 1.6) / 3.5);
    sp[k] = loud[k] ? s : 0;
  }
  const H_S = H * fh, at = (k) => k * H_S + (W * fh) / 2;
  // runs
  const runs = (pred) => {
    const out = []; let s0 = -1;
    for (let k = 0; k <= m; k++) {
      const v = k < m && pred(k);
      if (v && s0 < 0) s0 = k;
      if (!v && s0 >= 0) { out.push([+Math.max(0, at(s0) - H_S / 2).toFixed(2), +Math.min(dur, at(k - 1) + H_S / 2).toFixed(2)]); s0 = -1; }
    }
    return out;
  };
  const music = bridge(runs((k) => loud[k] && sp[k] < 0.32), 0.6).filter((r) => r[1] - r[0] >= 1.5);
  const silence = runs((k) => !loud[k]).filter((r) => r[1] - r[0] >= 0.6);
  const speech = runs((k) => loud[k] && sp[k] >= 0.32);
  // pauses at frame resolution (20 ms): where a decoding window can end without cutting a word
  const pauses = []; let p0 = -1;
  for (let f = 0; f <= n; f++) {
    const q = f < n && db[f] < thr - 3;
    if (q && p0 < 0) p0 = f;
    if (!q && p0 >= 0) { if ((f - p0) * fh >= 0.2) pauses.push([+(p0 * fh).toFixed(2), +(f * fh).toFixed(2)]); p0 = -1; }
  }
  // a music-only stretch is also a fine place to end a decoding window (nobody is talking there)
  // (a cut point only: music is never SKIPPED, someone may be talking over it)
  const cuts = [...pauses, ...music.filter((r) => r[1] - r[0] >= 2).map((r) => [+(r[0] + 0.5).toFixed(2), +(r[1] - 0.5).toFixed(2)])].sort((x, y) => x[0] - y[0]);
  return { hop: H_S, off: (W * fh) / 2, speechiness: sp, loud, silence, speech, music, pauses, cuts, floorDb: floor, thrDb: thr, quietDb: qthr, dur };
}

const clamp = (v) => (v < 0 ? 0 : v > 1 ? 1 : v);
/** merge runs whose gap is under `gap` seconds (a one-window blip inside a stretch of music) */
function bridge(rs, gap) {
  const out = [];
  for (const r of rs) { const l = out[out.length - 1]; if (l && r[0] - l[1] < gap) l[1] = r[1]; else out.push(r.slice()); }
  return out;
}

/** Mean speechiness and loud share over [t0,t1] (seconds). */
export function evidence(an, t0, t1) {
  if (!an) return { speech: 1, loud: 1 };
  const k0 = Math.max(0, Math.floor((t0 - an.off) / an.hop)), k1 = Math.min(an.speechiness.length - 1, Math.ceil((t1 - an.off) / an.hop));
  let s = 0, l = 0, c = 0;
  for (let k = k0; k <= k1; k++) { s += an.speechiness[k]; l += an.loud[k]; c++; }
  return c ? { speech: s / c, loud: l / c } : { speech: 0, loud: 0 };
}

const inRuns = (runs, t0, t1, slack = 0) => runs.some((r) => r[0] - slack <= t0 && t1 <= r[1] + slack);

// ------------------------------------------------------------------ hallucinations
// Phrases Whisper invents over silence and music (from its YouTube training captions). Normalised text.
const HALLU = [
  'thanks for watching', 'thank you for watching', 'thank you so much for watching', 'thanks for watching and see you next time',
  'please subscribe', 'subscribe to my channel', 'subscribe to the channel', 'like and subscribe', 'dont forget to subscribe',
  'please like and subscribe', 'see you in the next video', 'see you next time', 'bye bye', 'thank you',
  'subtitles by the amara org community', 'subtitles by', 'amara org', 'transcribed by', 'captions by', 'translated by',
  'i ll see you in the next video', 'you',
  'شكرا للمشاهدة', 'شكرا على المشاهدة', 'شكرا لكم على المشاهدة', 'اشتركوا في القناه', 'اشترك في القناه', 'لا تنسوا الاشتراك',
  'ترجمه نانسي قنقر', 'نانسي قنقر', 'موسيقى', 'شكرا',
].map((s) => normPhrase(s));

export function normPhrase(s) {
  return String(s || '').toLowerCase().replace(/[ً-ْٰـ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
    .replace(/[’']/g, '').replace(/[^\p{L}\p{N} ]+/gu, ' ').replace(/\s+/g, ' ').trim();
}
const nw = (w) => normPhrase(w.w);

/**
 * Indices of invented words, each with a reason.
 *  - 'outro'  a known YouTube outro phrase that sits in silence / music-only (low speech evidence)
 *  - 'loop'   the same 1-8 word phrase repeated 3+ times back to back (Whisper's decoding loop): extra copies go
 *  - 'silent' an ordinary word whose whole span is inside a silent stretch (nothing was said)
 *  - 'prompt' the vocabulary prompt echoed back in a stretch without speech
 * an = classifyAudio() result (optional: without it only loops are found).
 */
export function findHallucinations(words, an, opts = {}) {
  const drop = new Map();
  const n = words.length, norm = words.map(nw);
  // outros: match the longest listed phrase starting at i
  const maxLen = 9;
  for (let i = 0; i < n; i++) {
    if (words[i].tag) continue;
    for (let L = Math.min(maxLen, n - i); L >= 1; L--) {
      const ph = norm.slice(i, i + L).join(' ').trim();
      if (!ph || !HALLU.includes(ph)) continue;
      const t0 = words[i].t0, t1 = words[i + L - 1].t1;
      const ev = evidence(an, t0, t1);
      // "you" / "thank you" / "شكرا" alone are real words: only drop them when nothing at all was said nearby
      const weak = ph.split(' ').length <= 2;
      const isolated = (i === 0 || t0 - words[i - 1].t1 > 1.2) && (i + L >= n || words[i + L].t0 - t1 > 1.2);
      const quietOrMusic = !an ? false : ev.loud < 0.35 || ev.speech < (weak ? 0.12 : 0.2) || inRuns(an.silence, t0, t1, 0.2);
      if (quietOrMusic && (!weak || isolated)) { for (let k = i; k < i + L; k++) drop.set(k, 'outro'); i += L - 1; }
      break;
    }
  }
  // loops: phrase of length L repeated >= 3 times consecutively (or one word 4+ times)
  for (let L = 1; L <= 8; L++) {
    for (let i = 0; i + 2 * L < n; i++) {
      let reps = 1;
      while (i + (reps + 1) * L <= n && norm.slice(i + reps * L, i + (reps + 1) * L).join(' ') === norm.slice(i, i + L).join(' ')) reps++;
      const need = L === 1 ? 4 : 3;
      if (reps >= need && norm.slice(i, i + L).join('').length > 0) {
        for (let k = i + L; k < i + reps * L; k++) if (!drop.has(k)) drop.set(k, 'loop');
        i += reps * L - 1;
      }
    }
  }
  // words in pure silence
  if (an) {
    for (let i = 0; i < n; i++) {
      if (drop.has(i) || words[i].tag) continue;
      const w = words[i];
      if (inRuns(an.silence.filter((r) => r[1] - r[0] >= 1.0), w.t0 + 0.05, w.t1 - 0.05, 0) && evidence(an, w.t0, w.t1).loud < 0.1) drop.set(i, 'silent');
    }
  }
  // the prompt echoed: a run of 3+ vocabulary words in a stretch without speech
  if (an && opts.prompt) {
    const P = ' ' + normPhrase(opts.prompt) + ' ';
    for (let i = 0; i < n; i++) {
      let j = i; while (j < n && !words[j].tag && P.includes(' ' + norm[j] + ' ')) j++;
      if (j - i >= 3 && evidence(an, words[i].t0, words[j - 1].t1).speech < 0.2) for (let k = i; k < j; k++) if (!drop.has(k)) drop.set(k, 'prompt');
      i = Math.max(i, j - 1);
    }
  }
  return drop;
}

/**
 * The whole post-pass: tag sound words, drop hallucinations, find music-only stretches.
 * Returns { words, dropped:[{w,t0,t1,why}], music:[[t0,t1]] } — music = loud, speech-less stretches holding no
 * ordinary word, where captions hide (or show ♪).
 */
export function cleanNonSpeech(words0, an, opts = {}) {
  const tagged = tagWords(snapOnsets(words0, an));
  const drop = findHallucinations(tagged, an, opts);
  const words = [], dropped = [];
  tagged.forEach((w, i) => { if (drop.has(i)) dropped.push({ w: w.w, t0: w.t0, t1: w.t1, why: drop.get(i) }); else words.push(w); });
  return { words, dropped, music: musicOnly(words, an) };
}

/**
 * Whisper often stretches the first word after music or silence back over the whole quiet part ("Book" 20.1–26.5 s
 * when it is said at 26.1 s), so the caption would show seconds early. A word longer than 1.2 s that STARTS inside a
 * silent or music-only stretch which ends before the word does, starts where that stretch ends.
 */
export function snapOnsets(words, an) {
  if (!an) return words;
  const quiet = bridge([...an.silence, ...an.music].sort((a, b) => a[0] - b[0]), 0.6);
  return words.map((w) => {
    if (w.tag || w.t1 - w.t0 <= 1.2) return w;
    const r = quiet.find((q) => q[0] <= w.t0 + 0.6 && q[1] > w.t0 && q[1] < w.t1 - 0.1);
    if (!r) return w;
    return { ...w, t0: +Math.min(w.t1 - 0.12, r[1]).toFixed(3), conf: Math.min(w.conf ?? 1, 0.8) };
  });
}

/** Loud, speech-less stretches that hold no ordinary (non-tag) word; plus every stretch a [Music] tag covers. */
export function musicOnly(words, an) {
  const out = [];
  const plain = words.filter((w) => !w.tag);
  const hasWord = (a, b) => plain.some((w) => w.t1 > a + 0.15 && w.t0 < b - 0.15);
  if (an) for (const r of an.music) if (!hasWord(r[0], r[1])) out.push([r[0], r[1]]);
  // [Music] tags Whisper wrote: the span until the next ordinary word (at most 30 s)
  for (let i = 0; i < words.length; i++) {
    const w = words[i];
    if (w.tag !== 'music') continue;
    const next = plain.find((p) => p.t0 >= w.t1);
    const prev = [...plain].reverse().find((p) => p.t1 <= w.t0);
    const a = Math.max(prev ? prev.t1 : 0, w.t0 - 0.5), b = Math.min(next ? next.t0 : w.t1 + 3, w.t0 + 30);
    if (b - a > 0.3) out.push([+a.toFixed(2), +b.toFixed(2)]);
  }
  out.sort((x, y) => x[0] - y[0]);
  const merged = [];
  for (const r of out) { const l = merged[merged.length - 1]; if (l && r[0] <= l[1] + 0.3) l[1] = Math.max(l[1], r[1]); else merged.push(r.slice()); }
  return merged;
}

/** Is time t inside a music-only stretch? (binary search over sorted, merged runs) */
export function inMusic(music, t) {
  if (!music || !music.length) return false;
  let lo = 0, hi = music.length - 1;
  while (lo <= hi) { const m = (lo + hi) >> 1; if (t < music[m][0]) hi = m - 1; else if (t >= music[m][1]) lo = m + 1; else return true; }
  return false;
}

/** Speech stretches (VAD) that no word covers: where the second pass should listen again. */
export function uncoveredSpeech(words, an, { minDur = 0.6, pad = 0.35 } = {}) {
  if (!an) return [];
  const out = [];
  for (const r of an.speech) {
    if (r[1] - r[0] < minDur) continue;
    // the part of the run not covered by any word
    let t = r[0]; const segs = [];
    for (const w of words) {
      if (w.t1 <= t || w.t0 >= r[1]) continue;
      if (w.t0 - t >= minDur) segs.push([t, w.t0]);
      t = Math.max(t, w.t1);
    }
    if (r[1] - t >= minDur) segs.push([t, r[1]]);
    for (const s of segs) out.push([Math.max(0, s[0] - pad), s[1] + pad]);
  }
  const merged = [];
  for (const s of out) { const l = merged[merged.length - 1]; if (l && s[0] <= l[1] + 0.5) l[1] = Math.max(l[1], s[1]); else merged.push(s); }
  return merged;
}
