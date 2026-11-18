// KBB Studio · themes · EDIT ON THE CANVAS: double-click a theme element's words on the video and type over them.
// The box comes from the renderer itself (./text.js records where every string of the last preview frame landed, in
// the item's own coordinates), so there is no DOM measuring: one input is laid over that box, in the item's rotation,
// at the word's size. Typing is live (one undo step per edit), Enter or a click away commits, Esc puts it back.
// The matching inspector field flashes; a double-click away from any word focuses the first field instead.
// Generic by design: every box the renderer recorded with a key is editable — a tx() word (item.style.text) or a
// slot an element registered itself (themes/text.js: registerTextSlots, e.g. the capsules' labels).
import { boxAt } from '../core/render.js';
import { lastBoxes, slotKind } from './text.js';
import { fieldsFor, writeWords, writeStyle, Burst, injectCss } from './text-panel.js';

let OPEN = null;
/** the word under canvas point p (item-local boxes, nearest within a little slack), or null */
export function wordAt(item, p, t) {
  const boxes = (lastBoxes(item.id)?.boxes || []).filter((b) => b.key && b.w > 0 && b.h > 0); if (!boxes.length) return null;
  const B = boxAt(item, t), a = -((item.rot || 0) * Math.PI) / 180, dx = p.x - (B.x + B.w / 2), dy = p.y - (B.y + B.h / 2);
  const x = dx * Math.cos(a) - dy * Math.sin(a) + B.w / 2, y = dx * Math.sin(a) + dy * Math.cos(a) + B.h / 2;
  const dist = (b) => Math.hypot(Math.max(b.x - x, 0, x - b.x - b.w), Math.max(b.y - y, 0, y - b.y - b.h));
  let best = null, bd = Infinity;
  for (const b of boxes) { const d = dist(b), area = b.w * b.h; if (d < bd - 0.5 || (Math.abs(d - bd) <= 0.5 && area < best.w * best.h)) { best = b; bd = d; } }
  return bd <= Math.max(12, Math.min(B.w, B.h) * 0.06) ? best : null;
}

/** close the open editor (commit) */
export function closeCanvasEditor() { OPEN?.close(true); }

/** a double-click on a theme item at canvas point p (preview: the editor's Preview) */
export function editOnCanvas(preview, item, p) {
  const ed = preview.ed; OPEN?.close(true); injectCss();
  ed.select([item.id]);
  const hit = wordAt(item, p, ed.t), fields = fieldsFor(item, ed.project), kind = hit && slotKind(hit.key);
  // a tx() word has its field; a registered slot reads and writes itself
  let f = hit && !kind ? fields.find((x) => x.key === hit.key) : null;
  if (kind) { const v = kind.read(item, hit.key); if (v != null) f = { key: hit.key, value: v, label: kind.label, ar: hit.ar }; }
  if (!f) { if (fields.length) { ed.inspector.focusThemeText(fields[0].key); return true; } return false; }
  const write = kind ? (v, label = 'Edit text') => writeStyle(ed, item.id, (s) => kind.write(s, f.key, v), label) : (v, label = 'Element text') => writeWords(ed, item.id, f.key, v, label);
  const k = preview.k || 1, B = boxAt(item, ed.t);
  const holder = document.createElement('div');
  holder.className = 'tx-canvas-edit';
  Object.assign(holder.style, { position: 'absolute', left: B.x * k + 'px', top: B.y * k + 'px', width: B.w * k + 'px', height: B.h * k + 'px', transform: item.rot ? `rotate(${item.rot}deg)` : '', pointerEvents: 'none', zIndex: 5 });
  const fs = Math.max(11, Math.min(64, hit.size * k * 0.8)), w = Math.min(Math.max(B.w * k, 140), Math.max(hit.w * k + fs * 1.5, 140)), hgt = Math.max(hit.h * k, fs * 1.6);
  const left = Math.max(0, Math.min(B.w * k - w, hit.align === 'right' && !hit.ar ? (hit.x + hit.w) * k - w + fs * 0.75 : hit.x * k - fs * 0.75));
  const inp = document.createElement('input');
  Object.assign(inp, { type: 'text', value: f.value, dir: f.ar ? 'rtl' : 'auto', spellcheck: false });
  inp.setAttribute('aria-label', f.label + ' (on the video)'); inp.title = f.label + ': Enter to keep, Esc to undo';
  inp.dataset.thCanvasText = f.key;
  Object.assign(inp.style, { position: 'absolute', left: left + 'px', top: Math.max(0, hit.y * k - (hgt - hit.h * k) / 2) + 'px', width: w + 'px', height: hgt + 'px', font: `700 ${fs}px "Montserrat", "Inter", system-ui, sans-serif`, color: '#ffffff', background: 'rgba(14,16,28,0.88)', border: '2px solid #e8508b', borderRadius: '6px', padding: '0 ' + fs * 0.4 + 'px', boxSizing: 'border-box', pointerEvents: 'auto', outline: 'none', boxShadow: '0 6px 24px rgba(0,0,0,.5)' });
  holder.appendChild(inp); preview.stage.appendChild(holder);
  const burst = new Burst(ed), orig = kind ? f.value : item.style?.text?.[f.key];
  let done = false;
  const close = (commit) => {
    if (done) return; done = true; OPEN = null;
    if (commit) burst.end();
    else { burst.next = null; if (burst.n) { write(orig ?? null); burst.end(); } }
    holder.remove(); ed.inspector.render(true); preview.overlay.focus({ preventScroll: true });
  };
  OPEN = { close, key: f.key, input: inp };
  inp.addEventListener('input', () => { const v = inp.value; burst.write(() => write(v)); });
  inp.addEventListener('keydown', (e) => { e.stopPropagation(); if (e.key === 'Enter') { e.preventDefault(); close(true); } else if (e.key === 'Escape') { e.preventDefault(); close(false); } });
  inp.addEventListener('blur', () => close(true));
  inp.addEventListener('pointerdown', (e) => e.stopPropagation());
  inp.focus(); inp.select();
  // the inspector shows the same field, flashed
  const own = ed.inspector.el.querySelector(kind?.focus ? kind.focus(f.key) : `[data-th-text="${CSS.escape(f.key)}"]`);
  const fl = own?.closest('.tx-field') || own;
  const cls = own?.closest('.tx-field') ? 'flash' : 'tx-flash-on';
  if (fl) { fl.scrollIntoView({ block: 'nearest' }); fl.classList.add(cls); setTimeout(() => fl.classList.remove(cls), 1200); }
  return true;
}
