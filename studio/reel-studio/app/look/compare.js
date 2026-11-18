// KBB Studio · look · before / after in the preview monitor: the untouched clip left of a divider, the adjusted clip
// right of it. Drag the divider (it sits on top of the picture); the export never sees it. fx.js draws the split in the
// same GPU pass (COMPARE), this file is the divider, its handle and the toggle.
import { registerCanvasOverlay, registerCanvasGrab, registerCanvasHover } from '../core/modules.js';
import { bus } from '../core/bus.js';
import { COMPARE, setCompare } from './fx.js';

const ed = () => window.kbbEditor;
const redraw = () => { ed()?.preview?.draw(); ed()?.preview?.drawOverlay?.(); };
export const compareOn = () => COMPARE.on;
export function toggleCompare(on = !COMPARE.on, x) { setCompare(on, x ?? COMPARE.x); redraw(); ed()?.inspector?.render(true); bus.emit('look:compare', { on: COMPARE.on, x: COMPARE.x }); return COMPARE.on; }

let css = false;
function style() {
  if (css) return; css = true;
  const s = document.createElement('style');
  s.textContent = `
.cmp-line { position: absolute; top: 0; bottom: 0; width: 2px; margin-left: -1px; background: #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.35); pointer-events: none; }
.cmp-knob { position: absolute; top: 50%; width: 30px; height: 30px; margin: -15px 0 0 -15px; border-radius: 50%; background: #fff; color: #111; font: 700 13px/30px system-ui; text-align: center; box-shadow: 0 1px 6px rgba(0,0,0,.45); pointer-events: none; }
.cmp-tag { position: absolute; top: 10px; padding: 3px 8px; border-radius: 6px; background: rgba(0,0,0,.6); color: #fff; font: 600 11.5px system-ui; pointer-events: none; }`;
  document.head.appendChild(s);
}
const div = (cls, st, txt) => { const d = document.createElement('div'); d.className = cls; Object.assign(d.style, st); if (txt) d.textContent = txt; return d; };

registerCanvasOverlay({ id: 'look-compare', draw(el, { project, k }) {
  if (!COMPARE.on) return;
  style();
  const W = project.canvas.w * k, x = COMPARE.x * W;
  el.appendChild(div('cmp-line', { left: x + 'px' }));
  el.appendChild(div('cmp-knob', { left: x + 'px' }, '⇔'));
  el.appendChild(div('cmp-tag', { left: Math.max(6, x - 70) + 'px' }, 'Before'));
  el.appendChild(div('cmp-tag', { left: Math.min(W - 52, x + 8) + 'px' }, 'After'));
} });
const near = (project, p, k) => COMPARE.on && Math.abs(p.x - COMPARE.x * project.canvas.w) < 22 / (k || 1);
registerCanvasHover({ id: 'look-compare', hover(project, t, p) { return p && near(project, p, ed()?.preview?.k) ? { cursor: 'ew-resize' } : null; } });
registerCanvasGrab({ id: 'look-compare', grab(project, t, p, ev, api) {
  if (!near(project, p, api.k)) return null;
  const x0 = COMPARE.x, W = project.canvas.w;
  return { move(dx) { setCompare(true, x0 + dx / W); redraw(); }, end() { bus.emit('look:compare', { on: true, x: COMPARE.x }); return true; } };
} });
