// KBB Studio · audio · RNNoise (Xiph/Mozilla, BSD-3; WASM build from @jitsi/rnnoise-wasm 0.2.1, Apache-2.0), vendored at
// vendor/rnnoise-0.2.1/rnnoise.wasm (110 KB, the network's weights are compiled in: no model download).
// Loaded WITHOUT the emscripten glue (two imports), so the same code runs in a module worker, the page and Node.
//
//   const rn = await createRnnoise(wasmBytesOrUrl);   rn.denoise(Float32Array @48 kHz, mix 0..1) -> Float32Array

const FRAME = 480;              // 10 ms at 48 kHz
const DELAY = FRAME;            // RNNoise's output lags its input by one frame; removed so the voice stays in sync

export async function createRnnoise(src) {
  const bytes = src instanceof ArrayBuffer || ArrayBuffer.isView(src) ? src : await (await fetch(src)).arrayBuffer();
  let mem = null;
  const imports = { a: {
    a: (size) => { try { mem.grow(Math.ceil((size - mem.buffer.byteLength) / 65536)); return 1; } catch { return 0; } },   // resize_heap
    b: (dest, from, num) => { new Uint8Array(mem.buffer).copyWithin(dest, from, from + num); },                            // memcpy_big
  } };
  const { instance } = await WebAssembly.instantiate(bytes, imports);
  const X = instance.exports;   // c memory, d ctors, f create, g malloc, h destroy, i free, j process_frame
  mem = X.c; X.d();
  return {
    /** denoise mono 48 kHz audio; mix = how much of the cleaned signal (1 = all) */
    denoise(x, mix = 1) {
      const st = X.f(0), pin = X.g(FRAME * 4), pout = X.g(FRAME * 4);
      const n = x.length, total = n + DELAY, y = new Float32Array(n), vad = new Float32Array(Math.ceil(total / FRAME));
      for (let o = 0, f = 0; o < total; o += FRAME, f++) {
        const H = new Float32Array(mem.buffer);
        for (let i = 0; i < FRAME; i++) H[pin / 4 + i] = (o + i < n ? x[o + i] : 0) * 32768;
        vad[f] = X.j(st, pout, pin);
        const O = new Float32Array(mem.buffer);
        for (let i = 0; i < FRAME; i++) { const k = o + i - DELAY; if (k >= 0 && k < n) y[k] = O[pout / 4 + i] / 32768; }
      }
      X.i(pin); X.i(pout); X.h(st);
      if (mix < 1) for (let i = 0; i < n; i++) y[i] = mix * y[i] + (1 - mix) * x[i];
      return { y, vad };
    },
  };
}
