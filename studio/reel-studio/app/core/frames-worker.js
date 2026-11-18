// KBB Studio — core/frames-worker.js: one FrameEngine (core/frames-engine.js) per worker. Messages:
//   in : {op:'init', url, idx, opts} · {op:'target', T, dir, rate, all} · {op:'close'}
//   out: {op:'frame', ts, bmp} (transferred) · {op:'drop', list} · {op:'error', message}
import { FrameEngine } from './frames-engine.js';

let eng = null;
self.onmessage = (e) => {
  const d = e.data || {};
  if (d.op === 'init') {
    eng = new FrameEngine(d.url, d.idx, d.opts, {
      frame: (ts, bmp) => self.postMessage({ op: 'frame', ts, bmp }, [bmp]),
      drop: (list) => self.postMessage({ op: 'drop', list }),
      error: (message) => self.postMessage({ op: 'error', message }),
    });
  } else if (d.op === 'target') eng?.target(d.T, d.dir, d.rate, d.all);
  else if (d.op === 'close') { eng?.close(); self.close(); }
};
