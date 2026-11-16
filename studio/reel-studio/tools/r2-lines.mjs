// Lane R2: print how a transcript is cut into caption lines per style (node tools/r2-lines.mjs).
import { buildLines } from '../app/captions/model.js';
const text = process.argv[2] || 'Hello everyone, welcome back to my channel. Today I am unboxing the new vitamin C serum from Korea. Um the texture is light and it absorbs really fast.';
const words = text.split(' ').map((w, i) => ({ w, t0: +(0.2 + i * 0.38).toFixed(2), t1: +(0.2 + i * 0.38 + 0.33).toFixed(2), conf: 1 }));
for (const id of ['word-box', 'minimal', 'news', 'one-word']) {
  const L = buildLines(words, id, { w: 1080, h: 1920 });
  console.log(id.padEnd(10), L.slice(0, 8).map((l) => words.slice(l.wordIdx[0], l.wordIdx[1] + 1).map((w) => w.w).join(' ')).join(' | '));
}
