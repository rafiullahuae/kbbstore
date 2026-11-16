// KBB Studio · automation worker (module worker). All heavy maths runs here so the editor never blocks.
// Protocol: postMessage({id, op, args}) -> {id, result} | {id, error}
import { analyseSpeechTrack, beatGrid, loudness, vad, fillers } from './lib/audio.js';
import { analyseFrames, reframe, subjectCentroid } from './lib/video.js';
import { planAutoReel, planCuts, scoreUnits } from './lib/plan.js';
import { suggestRecipes } from './recipes/engine.js';

const OPS = {
  analyseAudio: ({ pcm, sr, words }) => analyseSpeechTrack(pcm, sr, words),
  beatGrid: ({ pcm, sr, opt }) => beatGrid(pcm, sr, opt),
  loudness: ({ channels, sr }) => ({ integrated: loudness(channels, sr).integrated }),
  vad: ({ pcm, sr, opt }) => { const v = vad(pcm, sr, opt); return { speech: v.speech, silences: v.silences, floorDb: v.floorDb, thrDb: v.thrDb }; },
  fillers: ({ words }) => fillers(words),
  analyseFrames: ({ frames, opt }) => {
    const a = analyseFrames(frames, opt);
    // fallback subjects (skin-tone centroid) for reframe when no face model ran
    a.subjects = frames.map(f => ({ t: f.t, ...(subjectCentroid(f) || { x: null, y: null, conf: 0 }) }));
    return a;
  },
  reframe: ({ subjects, src, aspect, opt }) => reframe(subjects, src, aspect, opt),
  planAutoReel: ({ ctx, opt }) => { const p = planAutoReel(ctx, opt); delete p.units; return p; },
  planCuts: ({ analysis, opt }) => planCuts(analysis, opt),
  scoreUnits: ({ ctx }) => scoreUnits(ctx),
  suggest: ({ input }) => suggestRecipes(input).map(s => ({ id: s.recipe.id, name: s.recipe.name, nameAr: s.recipe.nameAr, score: s.score, reasons: s.reasons, country: s.country })),
};

self.onmessage = async (e) => {
  const { id, op, args } = e.data || {};
  try {
    if (!OPS[op]) throw new Error('unknown op ' + op);
    const result = await OPS[op](args || {});
    self.postMessage({ id, result });
  } catch (err) { self.postMessage({ id, error: String(err && err.message || err) }); }
};
