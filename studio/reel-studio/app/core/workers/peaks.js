// KBB Studio — waveform peaks, off the main thread.
// in: { samples: Float32Array, sampleRate, perSecond }  out: { result: Float32Array (0..1 per bucket) }
self.onmessage = (e) => {
  try {
    const { samples, sampleRate, perSecond } = e.data;
    const step = Math.max(1, Math.round(sampleRate / perSecond));
    const n = Math.ceil(samples.length / step);
    const out = new Float32Array(n);
    let max = 1e-6;
    for (let b = 0; b < n; b++) {
      let p = 0; const end = Math.min(samples.length, (b + 1) * step);
      for (let i = b * step; i < end; i++) { const v = samples[i] < 0 ? -samples[i] : samples[i]; if (v > p) p = v; }
      out[b] = p; if (p > max) max = p;
    }
    for (let b = 0; b < n; b++) out[b] = Math.min(1, out[b] / max);
    self.postMessage({ result: out }, [out.buffer]);
  } catch (err) {
    self.postMessage({ error: String(err && err.message || err) });
  }
};
