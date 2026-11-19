// A clip's captions from a script the owner typed (Script tab → the box under a clip). The words are laid over the
// stretches of that clip where its sound is on (classifyAudio on the clip's own sound), by length, and saved as ONE
// undo step whose label starts "Auto captions", so "Sync words to the voice" runs straight after and puts every word
// on the voice (sync/index.js). The clip gets its own caption bar (items.js); every other clip's words, lines, bars
// and styles stay as they are.

import { speechParts, speechGroups, groupSound, mergeCaptions, captionsWithItem } from './auto.js';
import { classifyAudio, DEFAULT_TAG_MODE } from './nonspeech.js';
import { buildLines } from './model.js';
import { styleForWorkspace } from './styles.js';

/** words over [0, dur): spread over `runs` ([[t0,t1]] where the sound is on, by word length), else over the clip */
export function spreadWords(text, dur, runs = []) {
  const ws = String(text).trim().split(/\s+/).filter(Boolean);
  if (!ws.length || !(dur > 0)) return [];
  let rs = runs.map(([a, b]) => [Math.max(0, a), Math.min(dur, b)]).filter(([a, b]) => b - a >= 0.15);
  if (!rs.length) rs = [[Math.min(0.15, dur * 0.05), Math.max(dur * 0.95, dur - 0.15)]];
  const total = rs.reduce((s, [a, b]) => s + b - a, 0), wt = ws.map((w) => Math.max(2, w.length) + 1), sum = wt.reduce((s, x) => s + x, 0);
  const at = (x) => { for (const [a, b] of rs) { if (x <= b - a) return a + x; x -= b - a; } return rs[rs.length - 1][1]; };
  const out = []; let acc = 0;
  ws.forEach((w, i) => {
    const s = acc * total / sum, e = (acc + wt[i]) * total / sum;
    acc += wt[i];
    const t0 = at(s), t1 = Math.max(t0 + 0.05, at(e - 1e-6));
    out.push({ w, t0: +t0.toFixed(3), t1: +t1.toFixed(3), conf: 1, typed: true });
  });
  return out;
}

/** the command that gives span `s` (items.js captionSpans) the typed `text` as its captions (null: nothing to do) */
export async function typedClipCommand(ctx, s, text) {
  const p = ctx.project;
  let runs = [];
  try {
    const parts = await speechParts(p, ctx.media, { only: new Set(s.ids) });
    const g = speechGroups(parts)[0];
    if (g) {
      const an = classifyAudio(groupSound(g)), off = g.t0 - s.t0;
      runs = an.silence.length || an.speech.length ? invert(an.silence, an.dur).map(([a, b]) => [a + off, b + off]) : [];
    }
  } catch { /* no sound to read: spread over the clip */ }
  const fresh = spreadWords(text, s.t1 - s.t0, runs).map((w) => ({ ...w, t0: +(w.t0 + s.t0).toFixed(3), t1: +(w.t1 + s.t0).toFixed(3) }));
  if (!fresh.length) return null;
  const old = p.captions;
  let cap;
  if (old?.words?.length) {
    cap = mergeCaptions(old, fresh, [[s.t0, s.t1]], p.canvas);
  } else {
    const style = old?.style || styleForWorkspace(ctx.workspace);
    cap = { style, lang: /[؀-ۿ]/.test(text) ? 'ar' : 'en', words: fresh, lines: buildLines(fresh, style, p.canvas), soundTags: old?.soundTags || DEFAULT_TAG_MODE, music: [], dropped: [], speakers: 1 };
  }
  cap.heard = [...new Set([...(old?.heard || []), ...s.ids])];
  return captionsWithItem(p, cap, ctx.ops, { label: 'Auto captions · typed script', only: new Set(s.ids) });
}

/** where the sound is on: the gaps between silences */
function invert(sil, dur) {
  const out = []; let t = 0;
  for (const [a, b] of sil) { if (a > t) out.push([t, a]); t = Math.max(t, b); }
  if (t < dur) out.push([t, dur]);
  return out;
}
