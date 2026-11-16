// KBB Studio · viral · auto chapters / step counters (pure ESM).
// A list or step video ("first… second… step three… finally…", "أولاً… ثانياً… أخيراً") becomes chapters with short
// titles; any other talk is split into sentence-sized chapters so the progress bar still has marks.
import { normWord } from '../../auto/lib/audio.js';
import { sentences } from '../../auto/lib/plan.js';

const ORD = ['first', 'firstly', 'second', 'secondly', 'third', 'thirdly', 'fourth', 'fifth', 'sixth', 'seventh', 'next', 'then', 'finally', 'lastly', 'last'];
const ORD_AR = ['اولا', 'ثانيا', 'ثالثا', 'رابعا', 'خامسا', 'بعدين', 'بعدها', 'ثم', 'اخيرا', 'واخيرا', 'التالي'].map(normWord);
const NUMW = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];
const STOPS = new Set(['the', 'a', 'an', 'is', 'it', 'this', 'that', 'my', 'your', 'you', 'and', 'to', 'of', 'i', 'we', 'then', 'step', 'tip', 'number', 'um', 'uh', 'so']);

/** -> { steps: boolean, chapters:[{t0, title, cue}] } */
export function detectChapters(words = [], { duration = 0, maxChapters = 8 } = {}) {
  const n = words.map((w) => normWord(w.w));
  const cues = [];
  for (let i = 0; i < words.length; i++) {
    const w = n[i], nx = n[i + 1] || '';
    let hit = false;
    if ((w === 'step' || w === 'tip' || w === 'number' || w === normWord('الخطوة') || w === normWord('خطوة')) && (/^\d+$/.test(nx) || NUMW.includes(nx) || /^(الاولى|الثانيه|الثالثه|الرابعه|الاخيره)$/.test(nx))) hit = true;
    else if (ORD.includes(w) && (i === 0 || /[.!?,]$/.test(String(words[i - 1].w).trim()) || words[i].t0 - words[i - 1].t1 > 0.25)) hit = true;
    else if (ORD_AR.includes(w)) hit = true;
    if (hit && (!cues.length || words[i].t0 - words[cues[cues.length - 1]].t0 > 1.2)) cues.push(i);
  }
  const titleFrom = (i, stop = words.length) => {
    const out = [];
    for (let j = i; j < Math.min(words.length, i + 9, stop) && out.length < 4; j++) {
      const raw = String(words[j].w).trim().replace(/[.,!?؟،]+$/, ''); if (!raw) continue;
      if (j > i && /[.!?؟]$/.test(String(words[j - 1].w).trim())) break;
      if (out.length === 0 && (STOPS.has(n[j]) || ORD.includes(n[j]) || ORD_AR.includes(n[j]) || NUMW.includes(n[j]) || /^\d+$/.test(n[j]) || n[j] === normWord('الخطوة'))) continue;
      out.push(raw);
    }
    const s = out.join(' ');
    return s ? s[0].toUpperCase() + s.slice(1) : '';
  };
  if (cues.length >= 2) {
    return { steps: true, chapters: cues.slice(0, maxChapters).map((i, k) => ({ t0: +(k === 0 ? Math.min(words[i].t0, words[i].t0) : words[i].t0 - 0.05).toFixed(3), title: titleFrom(i, cues[k + 1]) || `Step ${k + 1}`, cue: words[i].w })) };
  }
  // no step words: sentence groups of roughly equal length
  const ss = sentences(words);
  if (!ss.length) return { steps: false, chapters: [] };
  const want = Math.max(2, Math.min(maxChapters, Math.round((duration || ss[ss.length - 1].t1) / 8)));
  const per = Math.max(1, Math.round(ss.length / want)), chapters = [];
  for (let k = 0; k < ss.length; k += per) chapters.push({ t0: +ss[k].t0.toFixed(3), title: titleFrom(ss[k].i0) || `Part ${chapters.length + 1}`, cue: null });
  return { steps: false, chapters };
}
