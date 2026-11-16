// KBB Studio · automation · Auto music: pick a track, fit it to length with a musical ending, duck under speech.

const ENERGY = { low: 0, mid: 1, high: 2 };

/**
 * Rank library tracks. want = { moods:[...], energy:'low'|'mid'|'high', duration, exclude:[ids] }
 * Returns [{track, score, reasons}] best first.
 */
export function rankTracks(tracks, want = {}) {
  const moods = (want.moods || []).map(m => m.toLowerCase());
  return tracks.filter(t => !(want.exclude || []).includes(t.id)).map(t => {
    const reasons = []; let s = 0;
    const mi = moods.indexOf(t.mood);
    if (mi >= 0) { s += 3 - mi * 0.6; reasons.push(`mood: ${t.mood}`); }
    else if ((t.tags || []).some(x => moods.includes(x))) { s += 1.5; reasons.push('related mood'); }
    if (want.energy && t.energy) { const d = Math.abs(ENERGY[want.energy] - ENERGY[t.energy]); s += 1 - d * 0.7; if (!d) reasons.push(`${t.energy} energy`); }
    if (want.duration) { const fit = t.duration >= want.duration ? 1 : t.duration / want.duration; s += 0.8 * fit; if (fit === 1) reasons.push('long enough without looping'); }
    if (t.source === 'user') { s += 0.3; reasons.push('your own track'); }
    return { track: t, score: +s.toFixed(3), reasons };
  }).sort((a, b) => b.score - a.score || a.track.id.localeCompare(b.track.id));
}

/**
 * Fit a track to L seconds. Library tracks end on their own written ending (last 2 bars + tail), spliced on a bar line;
 * tracks shorter than L loop their body bars; user tracks without an ending cut on a bar line and fade.
 * Returns { items:[{in,out,t0,fadeIn,fadeOut}], beats:[timeline s], total }.
 */
export function fitTrack(track, L, opt = {}) {
  const bar = track.barSec || (track.bpm ? 240 / track.bpm : 2), first = track.firstBeat || 0;
  const srcBeats = track.beats && track.beats.length ? track.beats : null;
  const items = [];
  const push = (a, b, t0) => { if (b - a > 0.01) items.push({ in: +a.toFixed(4), out: +b.toFixed(4), t0: +t0.toFixed(4), fadeIn: 0, fadeOut: 0 }); };
  const startAt = opt.skipIntro && track.sections?.[0]?.name === 'intro' ? track.sections[0].t1 : 0;
  if (track.endingStart != null) {
    const E = track.duration - track.endingStart, Ebars = (track.musicEnd ?? track.duration) - track.endingStart;
    const loopA = track.sections?.find(s => s.name !== 'intro')?.t0 ?? first, loopB = track.endingStart;
    let t = 0, pos = startAt;
    let body = L - Ebars;               // what the body must fill (whole bars)
    body = Math.max(0, Math.floor((body + 1e-6) / bar) * bar);
    while (t < body - 1e-6) {
      const room = loopB - pos, take = Math.min(room, body - t);
      push(pos, pos + take, t); t += take; pos += take;
      if (pos >= loopB - 1e-6) pos = loopA;
    }
    const endLen = Math.min(E, L - t);
    push(track.endingStart, track.endingStart + endLen, t); t += endLen;
    if (items.length > 1) for (let i = 1; i < items.length; i++) { items[i].fadeIn = 0.012; items[i - 1].fadeOut = 0.012; }
    const last = items[items.length - 1]; last.fadeOut = endLen < E - 0.05 ? Math.min(1.2, Math.max(0.3, endLen - Ebars)) : 0.05;
    if (startAt > 0) items[0].fadeIn = 0.25;
    return { items, beats: timelineBeats(items, srcBeats, bar, first), total: +t.toFixed(3) };
  }
  // user / foreign track
  const usable = Math.min(L, track.duration - startAt);
  const cut = track.duration > L ? Math.max(bar, Math.round(usable / bar) * bar) : usable;
  const len = Math.min(cut, L);
  push(startAt, startAt + len, 0);
  items[0].fadeIn = startAt > 0 ? 0.25 : 0.02; items[0].fadeOut = Math.min(2, len * 0.15);
  return { items, beats: timelineBeats(items, srcBeats, bar, first), total: +len.toFixed(3) };
}
function timelineBeats(items, srcBeats, bar, first) {
  const out = [];
  for (const it of items) {
    const list = srcBeats || (() => { const b = []; for (let t = first; t < it.out + 1; t += bar / 4) b.push(t); return b; })();
    for (const b of list) if (b >= it.in - 1e-4 && b < it.out - 1e-4) out.push(+(it.t0 + b - it.in).toFixed(4));
  }
  return out;
}

/**
 * Ducking: volume keyframes for a music item so it dips under speech.
 * speech: [[t0,t1]] on the TIMELINE. Returns kf.volume [[tRelToItem, gain, ease]].
 */
export function duckKeys(speech, item, opt = {}) {
  const base = opt.base ?? 1, low = opt.duck ?? 0.3, att = opt.attack ?? 0.12, rel = opt.release ?? 0.4;
  const s0 = item.t0, s1 = item.t0 + (item.out - item.in);
  const merged = [];
  for (const [a, b] of speech) { if (b < s0 - rel || a > s1 + att) continue; const l = merged[merged.length - 1]; if (l && a - l[1] < att + rel + 0.1) l[1] = Math.max(l[1], b); else merged.push([a, b]); }
  const k = [[0, base, 'linear']];
  for (const [a, b] of merged) {
    const ta = Math.max(0, a - att - s0), tb = Math.max(ta + att, b - s0);
    if (ta > 0) k.push([+ta.toFixed(3), base, 'linear']);
    k.push([+(ta + att).toFixed(3), low, 'linear'], [+tb.toFixed(3), low, 'linear'], [+(tb + rel).toFixed(3), base, 'linear']);
  }
  // keep strictly increasing times
  const out = []; for (const e of k) { if (out.length && e[0] <= out[out.length - 1][0]) { out[out.length - 1][1] = Math.min(out[out.length - 1][1], e[1]); continue; } out.push(e); }
  return out;
}

/** Mood + energy from a recipe and the analysis (speech rate, motion, cuts). */
export function wantFor(recipe, analysis = {}) {
  const moods = (recipe && recipe.music && recipe.music.moods) || ['corporate'];
  let energy = (recipe && recipe.music && recipe.music.energy) || 'mid';
  const m = analysis.video?.stats?.motion, cpm = analysis.video?.stats?.cutsPerMin;
  if (m != null && cpm != null) { if (m > 0.04 || cpm > 20) energy = energy === 'low' ? 'mid' : 'high'; else if (m < 0.01 && cpm < 4 && energy === 'high') energy = 'mid'; }
  return { moods, energy };
}
