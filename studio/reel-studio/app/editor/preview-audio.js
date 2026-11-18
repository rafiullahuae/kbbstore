// KBB Studio — the preview monitor's sound for retimed clips (speed, ramps, reverse): the buffers core/retime-audio.js
// renders for the export, played on Web Audio in step with the monitor's clock. A source is (re)started only when the
// clock and the sound drift apart by more than 45 ms (a seek, a pause, an edit) — never per frame — and its gain
// follows the clip's volume, ducking keyframes and fades smoothly (15 ms), so nothing clicks.

import { evalProp, fadeAt } from '../core/render.js';

export class PreviewAudio {
  constructor() { this.nodes = new Map(); this.ac = null; }
  ctx() {
    if (!this.ac) { try { this.ac = new AudioContext({ latencyHint: 'interactive' }); } catch { this.ac = null; } }
    return this.ac;
  }
  kill(id) {
    const n = this.nodes.get(id); if (!n) return;
    try { n.g.gain.setTargetAtTime(0, this.ac.currentTime, 0.004); n.src.stop(this.ac.currentTime + 0.02); } catch {}
    setTimeout(() => { try { n.src.disconnect(); n.g.disconnect(); } catch {} }, 60);
    this.nodes.delete(id);
  }
  stop() { for (const id of [...this.nodes.keys()]) this.kill(id); }
  close() { this.stop(); try { this.ac?.close(); } catch {} this.ac = null; }

  /** list: [{item, track, buf}] — retimed clips that are playing now or start within a few hundred ms */
  sync(t, list) {
    const ac = list.length || this.nodes.size ? this.ctx() : null;
    if (!ac) return;
    if (ac.state === 'suspended') ac.resume().catch(() => {});
    const lat = ac.outputLatency || ac.baseLatency || 0, now = ac.currentTime, seen = new Set();
    for (const { item, track, buf } of list) {
      seen.add(item.id);
      const off = t - item.t0 + lat;                        // where in the buffer the ear should be now
      let n = this.nodes.get(item.id);
      if (n && (n.buf !== buf || Math.abs(now - n.at + n.off - off) > 0.045)) { this.kill(item.id); n = null; }
      if (!n && off < buf.duration - 0.02) {
        const src = ac.createBufferSource(), g = ac.createGain();
        src.buffer = buf; src.connect(g); g.connect(ac.destination); g.gain.value = 0;
        if (off >= 0) { src.start(now, off); n = { src, g, buf, at: now, off }; }
        else { src.start(now - off, 0); n = { src, g, buf, at: now - off, off: 0 }; }
        this.nodes.set(item.id, n);
      }
      if (n) {
        const v = Math.max(0, Math.min(2, evalProp(item, 'volume', t) * (track.volume ?? 1) * fadeAt(item, t)));
        n.g.gain.setTargetAtTime(v, now, 0.015);
      }
    }
    for (const id of [...this.nodes.keys()]) if (!seen.has(id)) this.kill(id);
  }
}
