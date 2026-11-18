// KBB Studio · themes · two small hooks for the elements' text:
//   · a Text item added with "+ Add text" (item.link = the element's id) moves and re-times with its element — one
//     follow-up in the same undo step (core/history.js hooks), absolute values, nothing when the element did not move;
//   · a font chosen in Text style is loaded before an export's first frame and when a project opens (prepareRender).
import { registerHistoryHook, ops } from '../core/history.js';
import { registerPrepare } from '../core/render.js';

const dur = (it) => (it.out - it.in) / (it.speed || 1);
const snap = (it) => ({ x: it.x, y: it.y, t0: it.t0, d: dur(it) });

registerHistoryHook({
  id: 'theme-text-link',
  before(p) {
    let txts = null;
    for (const tr of p.tracks) for (const it of tr.items) if (it.link) (txts ||= new Map()).set(it.id, { link: it.link, ...snap(it) });
    if (!txts) return null;
    const els = new Map();
    for (const tr of p.tracks) for (const it of tr.items) if (/^theme\./.test(it.type)) for (const v of txts.values()) if (v.link === it.id) els.set(it.id, snap(it));
    return els.size ? { txts, els } : null;
  },
  after(p, st) {
    if (!st) return null;
    const now = new Map(); for (const tr of p.tracks) for (const it of tr.items) now.set(it.id, it);
    const list = [];
    for (const [id, b] of st.txts) {
      const t = now.get(id), e0 = st.els.get(b.link), e = now.get(b.link); if (!t || !e0 || !e) continue;
      const dx = e.x - e0.x, dy = e.y - e0.y, dt = e.t0 - e0.t0, dd = dur(e) - e0.d;
      if (!dx && !dy && Math.abs(dt) < 1e-6 && Math.abs(dd) < 1e-6) continue;
      const props = {};
      if (dx || dy) { const x = b.x + dx, y = b.y + dy; if (t.x !== x) props.x = x; if (t.y !== y) props.y = y; }
      if (Math.abs(dt) > 1e-6) { const t0 = Math.max(0, +(b.t0 + dt).toFixed(3)); if (Math.abs(t.t0 - t0) > 1e-6) props.t0 = t0; }
      // a text that ran exactly as long as its element keeps doing so
      if (Math.abs(dd) > 1e-6 && Math.abs(b.d - e0.d) < 1e-3) props.out = +(t.in + dur(e) * (t.speed || 1)).toFixed(3);
      if (Object.keys(props).length) list.push({ id, props });
    }
    return list.length ? ops.setItems(list, 'Text follows its element') : null;
  },
});

registerPrepare('theme-text-fonts', async (project) => {
  const fonts = new Set();
  for (const tr of project?.tracks || []) for (const it of tr.items) { const f = it.style?.tstyle?.font; if (f && /^theme\./.test(it.type)) fonts.add(f); }
  if (!fonts.size) return;
  const { loadFont } = await import('./text-panel.js');
  await Promise.all([...fonts].map(loadFont));
});
