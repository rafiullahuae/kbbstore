// KBB Studio · themes · TEXT BEHIND THE HOST — its own editor, a floating window beside the video (the owner, 19 Nov:
// "i want a separate on screen popup holding only those options … and also the apply button along with popup once i
// preview it on live video. please change the right panel to individual side popup so i can see the edit frame also").
//
//   openTbEditor(ed, id, { tab, focus })   open it for one element (Words · Look · Position & motion · Cut-out)
//   closeTbEditor(commit)                  Apply (true) or Cancel (false)
//   actionToast(text, label, fn)           a toast with one button ("✎ Edit" after every country I mention)
//
// Live and undoable as ONE step: while it is open every change (its own controls, a drag of the words on the video,
// ⌘Z inside it) runs through the history as a DRAFT — applied to the project at once, so the monitor, the playhead and
// "▶ Play preview" show it, but not yet an undo step. Apply turns the whole draft into one step; Cancel (or Esc) undoes
// it command by command, so the project is back byte for byte. It opens docked over the right column (the frame
// stays fully in view), can be dragged anywhere that does not cover the frame, and remembers where it was put.
import { h, clear, toast } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { History, ops } from '../core/history.js';
import { findItem, itemEnd } from '../core/project.js';
import { tbGroups, tbSummaryText } from './textbehind-panel.js';
import { tbStatus } from './textbehind.js';

const RECT_KEY = 'kbbstudio.tbEditor.rect';
export const TB_TABS = [['words', '🔤 Words', 'Words: the top line, the main word or the carousel of countries'], ['look', '🎨 Look', 'Look: font, size, spacing, colours, outline, shadow, opacity'], ['pos', '🙂 Position', 'Position & motion: behind the head, centre, lower, X / Y, rotate, in and out animation'], ['cut', '✂ Cut-out', 'Cut-out: person or main subject, feather, grow, smoothness, and whether the person is found']];
let OPEN = null;

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  document.head.appendChild(h('style', { id: 'kbb-tbp-css' }, `
.tbp { position: fixed; z-index: 70; display: flex; flex-direction: column; background: var(--panel, #14151c); border: 1px solid var(--line2, #343747); border-radius: 14px; box-shadow: 0 24px 64px rgba(0,0,0,.55), 0 2px 8px rgba(0,0,0,.4); color: var(--text, #f2f3f8); min-width: 0; overflow: hidden; }
.tbp-head { display: flex; align-items: center; gap: 8px; padding: 9px 10px 8px 14px; cursor: grab; user-select: none; border-bottom: 1px solid var(--line, #242634); background: var(--panel2, #1b1c25); touch-action: none; }
.tbp-head.drag { cursor: grabbing; }
.tbp-title { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.tbp-title b { font-size: 13.5px; }
.tbp-title small { color: var(--muted, #a9aec0); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tbp-tabs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 2px; padding: 6px; border-bottom: 1px solid var(--line, #242634); flex: none; }
.tbp-tab { border: 0; background: transparent; padding: 6px 2px; font-size: 11.5px; font-weight: 650; color: var(--muted, #a9aec0); border-radius: 7px; cursor: pointer; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tbp-tab:hover { background: var(--panel3, #242632); color: var(--text, #f2f3f8); }
.tbp-tab.on { color: var(--text, #f2f3f8); background: var(--accent-soft, rgba(209,72,125,.18)); box-shadow: inset 0 -2px 0 var(--accent, #d1487d); }
.tbp-body { flex: 1; min-height: 0; overflow: auto; padding: 4px 0 12px; }
.tbp-sec { padding: 10px 14px 4px; display: flex; flex-direction: column; gap: 10px; }
.tbp-sec + .tbp-sec { border-top: 1px solid var(--line, #242634); }
.tbp-sec h4 { margin: 0; font-size: 11px; font-weight: 750; letter-spacing: .07em; text-transform: uppercase; color: var(--muted, #a9aec0); }
.tbp-body :is(input:not([type]), input[type=text], input[type=number], select) { min-height: 32px; }
.tbp-chip { margin: 0; padding: 6px 14px; font-size: 12px; color: var(--muted, #a9aec0); border-top: 1px solid var(--line, #242634); background: rgba(255,209,102,.08); }
.tbp-chip[hidden] { display: none; }
.tbp-foot { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-top: 1px solid var(--line, #242634); background: var(--panel2, #1b1c25); flex: none; }
.tbp-foot .grow { flex: 1; }
.tbp-play[aria-pressed="true"] { border-color: var(--accent, #d1487d); color: var(--text, #fff); box-shadow: inset 0 0 0 1px var(--accent, #d1487d); }
.tbp-toast { position: fixed; left: 50%; bottom: 78px; transform: translateX(-50%); z-index: 90; display: flex; align-items: center; gap: 12px; pointer-events: auto; }`));
}

// ── the draft: the history, held ──
function beginDraft(ed) {
  const H = ed.history, p = ed.project, list = [], redo = [], modified = p.modified;
  const emit = (kind) => bus.emit('project:change', { cmd: null, kind });
  H.txn = list;
  // the real apply (follow-up hooks included) collects into the draft instead of the undo stack; the editor redraws
  H.apply = function (cmd) { if (!cmd) return; this.txn = list; History.prototype.apply.call(this, cmd); redo.length = 0; emit('draft'); };
  H.begin = () => {}; H.end = () => {};   // a command that groups its own steps folds into the draft
  H.undo = () => { const c = list.pop(); if (!c) return false; c.undo(p); redo.push(c); H.version++; emit('undo'); return true; };
  H.redo = () => { const c = redo.pop(); if (!c) return false; c.do(p); list.push(c); H.version++; emit('redo'); return true; };
  const restore = () => { for (const k of ['apply', 'begin', 'end', 'undo', 'redo']) delete H[k]; H.txn = null; };
  return {
    get n() { return list.length; },
    /** ONE undo step for everything (nothing changed: no step) */
    commit(label) { restore(); if (!list.length) return false; list.label = label; H.txn = list; History.prototype.end.call(H); return true; },
    /** every command undone, newest first: the project exactly as it was */
    cancel() { restore(); for (let i = list.length - 1; i >= 0; i--) list[i].undo(p); p.modified = modified; H.version++; emit('undo'); },
  };
}

// ── where it sits: docked over the right column, never over the frame ──
const vw = () => window.innerWidth, vh = () => window.innerHeight;
const overlaps = (a, b) => a.x < b.right && a.x + a.w > b.left && a.y < b.bottom && a.y + a.h > b.top;
function dockRect(ed) {
  const r = (ed.inspEl || document.querySelector('.inspector.side'))?.getBoundingClientRect();
  if (!r || !r.width) return { x: Math.max(8, vw() - 368), y: 70, w: 360, h: vh() - 78 };
  return { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(vh() - r.top - 8) };
}
function fit(rect, ed) {
  const d = dockRect(ed), w = Math.min(Math.max(300, rect.w || d.w), vw() - 16), hh = Math.min(Math.max(320, rect.h || d.h), vh() - 16);
  const r = { x: Math.round(Math.min(Math.max(8, rect.x), vw() - w - 8)), y: Math.round(Math.min(Math.max(8, rect.y), vh() - hh - 8)), w, h: hh };
  const st = ed.preview?.stage?.getBoundingClientRect();
  if (st && st.width && overlaps(r, st)) {
    // never over the frame: beside it, on the side with room, else back on the dock
    if (st.right + 8 + r.w <= vw() - 8) r.x = Math.round(st.right + 8);
    else if (st.left - 8 - r.w >= 8) r.x = Math.round(st.left - 8 - r.w);
    else return d;
    if (overlaps(r, st)) return d;
  }
  return r;
}
const saved = () => { try { const r = JSON.parse(localStorage.getItem(RECT_KEY) || 'null'); return r && Number.isFinite(r.x) && Number.isFinite(r.y) ? r : null; } catch { return null; } };
const save = (r) => { try { localStorage.setItem(RECT_KEY, JSON.stringify(r)); } catch {} };
function place(O) { Object.assign(O.el.style, { left: O.rect.x + 'px', top: O.rect.y + 'px', width: O.rect.w + 'px', height: O.rect.h + 'px' }); }

// ── open / close ──
/** open the editor for element `id` (opts: tab 'words' | 'look' | 'pos' | 'cut', focus: a word key to type into) */
export function openTbEditor(ed, id, opts = {}) {
  if (!ed || !findItem(ed.project, id)) return null;
  css();
  if (OPEN && OPEN.id === id && OPEN.ed === ed) { if (opts.tab) OPEN.tab = opts.tab; render(); focusKey(opts.focus); return OPEN.el; }
  if (OPEN) closeTbEditor(true);
  import('./text-edit.js').then((m) => m.closeCanvasEditor()).catch(() => {});
  ed.select([id]);
  showWords(ed, findItem(ed.project, id).item);
  const O = OPEN = { ed, id, tab: opts.tab || 'words', draft: beginDraft(ed), offs: [], playing: false, sig: '' };
  O.chip = h('p.tbp-chip', { dataset: { tbpChip: '1' }, hidden: true });
  O.sub = h('small', { dataset: { tbpName: '1' } });
  O.body = h('div.tbp-body');
  O.tabs = h('div.tbp-tabs', { role: 'tablist' }, TB_TABS.map(([k, l, t]) => h('button.tbp-tab', { type: 'button', role: 'tab', dataset: { tbpTab: k }, title: t, on: { click: () => { O.tab = k; render(); } } }, l)));
  O.play = h('button.btn.small.tbp-play', { type: 'button', 'aria-pressed': 'false', title: 'Play the element on the video, again and again, while you adjust it (Space plays and pauses too)', dataset: { tbpPlay: '1' }, on: { click: () => setPlay(!O.playing) } }, '▶ Play preview');
  const head = h('div.tbp-head', { title: 'Drag to move this window (it never covers the video)' },
    h('div.tbp-title', h('b', '🔤 Text behind the host'), O.sub),
    h('button.icon-btn', { type: 'button', title: 'Cancel: put it back exactly as it was (Esc)', 'aria-label': 'Cancel', dataset: { tbpClose: '1' }, on: { click: () => closeTbEditor(false) } }, '✕'));
  O.el = h('div.tbp', { role: 'dialog', 'aria-label': 'Text behind the host', dataset: { tbp: id } },
    head, O.tabs, O.body, O.chip,
    h('div.tbp-foot', O.play, h('div.grow'),
      h('button.btn.small', { type: 'button', title: 'Put it back exactly as it was (Esc)', dataset: { tbpCancel: '1' }, on: { click: () => closeTbEditor(false) } }, 'Cancel'),
      h('button.btn.small.primary', { type: 'button', title: 'Keep every change as one step (⌘↵) — ⌘Z undoes it', dataset: { tbpApply: '1' }, on: { click: () => closeTbEditor(true) } }, '✓ Apply')));
  const was = saved(); O.docked = !was;
  O.rect = fit(was || dockRect(ed), ed);
  place(O);
  document.body.appendChild(O.el);
  drag(O, head);
  // keys: Esc cancels, ⌘↵ applies (a dialog on top — the country picker, a download question — keeps its own keys)
  O.onKey = (e) => {
    if (document.querySelector('.modal-back')) return;
    // a field elsewhere that has its own Esc (⌘K search, a word typed on the video) keeps it
    if (!O.el.contains(e.target) && e.target?.matches?.('input, textarea, [contenteditable="true"]')) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeTbEditor(false); }
    else if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); e.stopPropagation(); closeTbEditor(true); }
  };
  document.addEventListener('keydown', O.onKey, true);
  // docked (never moved): it follows the right column; moved: it stays where he put it, inside the window
  O.onResize = () => { O.rect = fit(O.docked ? dockRect(ed) : O.rect, ed); place(O); };
  window.addEventListener('resize', O.onResize);
  O.offs.push(
    bus.on('project:change', () => { if (OPEN !== O) return; if (!findItem(ed.project, id)) { closeTbEditor(true); toast('Text behind removed · ⌘Z undoes', 'info'); return; } refresh(); }),
    bus.on('project:load', () => { if (OPEN === O) teardown(O); }),
    // another item picked: this one is done (kept, as one step), so a Cancel never takes back edits made elsewhere
    bus.on('selection', (e) => { if (OPEN === O && e?.ids?.length && !e.ids.includes(id)) closeTbEditor(true); }),
    bus.on('vision:progress', (e) => { if (OPEN === O) chip(e?.f); }), bus.on('vision:done', () => { if (OPEN === O) chip(); }), bus.on('vision:failed', () => { if (OPEN === O) chip(); }),
    // the playhead goes out on the bus ten times a second while playing: loop a little before the end
    bus.on('playhead', (e) => { if (OPEN !== O || !O.playing || !e?.playing) return; const it = findItem(ed.project, id)?.item; if (it && (e.t >= itemEnd(it) - 0.1 || e.t < it.t0 - 0.05)) ed.seek(it.t0); }),
    // stopped by the end of the reel (the element ends with it): round again; stopped by him (Space): the toggle goes off
    bus.on('pause', () => {
      if (OPEN !== O || !O.playing || O.stopping) return;
      const it = findItem(ed.project, id)?.item;
      if (it && ed.t >= Math.min(itemEnd(it), ed.duration()) - 0.15) { setTimeout(() => { if (OPEN === O && O.playing) { ed.seek(it.t0); ed.play(); } }, 0); return; }
      O.playing = false; O.play.setAttribute('aria-pressed', 'false'); O.play.textContent = '▶ Play preview';
    }));
  render();
  focusKey(opts.focus);
  ed.preview?.draw?.();
  return O.el;
}

/**
 * The playhead where the words can be seen. Added at the playhead, an element STARTS there, and its first frame is the
 * start of its rise: nothing on the video (the owner, 19 Nov: "not working properly" — the editor opened on an empty
 * frame). Outside it, or in the first half of its way in, the playhead moves to just after it is fully in.
 */
function showWords(ed, it) {
  const tb = it.style?.tb || {}, inDur = Number.isFinite(+tb.inDur) ? Math.min(2, Math.max(0.1, +tb.inDur)) : 0.6, end = itemEnd(it), t = ed.t;
  if (t >= it.t0 + inDur * 0.5 && t < end - 0.05) return;
  ed.seek(Math.min(it.t0 + inDur + 0.05, it.t0 + (end - it.t0) * 0.5));
}

/** Apply (commit = true): every change as ONE undo step. Cancel: the project exactly as before it opened. */
export function closeTbEditor(commit) {
  const O = OPEN; if (!O) return;
  setPlay(false);
  OPEN = null;   // the commit's / cancel's own change event must not re-render a closing window
  const it = findItem(O.ed.project, O.id)?.item;
  if (commit) { if (O.draft.commit(`✎ ${(it?.name || '').replace(/^🔤\s*/, '') || 'Text behind'}`) && it) toast('Applied · ⌘Z undoes it in one step', 'ok'); }
  else O.draft.cancel();
  teardown(O);
  O.ed.inspector?.render?.(true);
  O.ed.preview?.draw?.();
}
export const tbEditorOpen = () => (OPEN ? { id: OPEN.id, tab: OPEN.tab, el: OPEN.el } : null);

function teardown(O) {
  if (OPEN === O) OPEN = null;
  for (const off of O.offs) off();
  document.removeEventListener('keydown', O.onKey, true);
  window.removeEventListener('resize', O.onResize);
  O.el.remove();
}

// ── its contents ──
function host(O) {
  const ed = O.ed;
  return {
    ed, ctx: ed.context(),
    section: (title, ...kids) => h('section.tbp-sec', h('h4', title), ...kids),
    set: (item, props, label) => ed.apply(ops.setItems([{ id: item.id, props }], label)),
    seal: () => ed.history.seal(),
    render: () => render(),
  };
}
/** what the window shows depends on (a drag on the video only moves x / y: those two fields follow without a rebuild) */
const sigOf = (it) => JSON.stringify([it.style, it.w, it.h, it.rot || 0, it.opacity ?? 1, it.auto || null, it.name]);
function render() {
  const O = OPEN; if (!O) return;
  const it = findItem(O.ed.project, O.id)?.item; if (!it) return;
  const scroll = O.body.scrollTop, same = O.shown === O.tab;
  const G = tbGroups(host(O), it);
  clear(O.body).append(...(G[O.tab] || G.words));
  for (const b of O.tabs.children) { const on = b.dataset.tbpTab === O.tab; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); }
  O.sub.textContent = tbSummaryText(it);
  O.body.scrollTop = same ? scroll : 0; O.shown = O.tab; O.sig = sigOf(it);
  chip();
}
/** after a change: rebuild unless he is typing or dragging a slider in it (then only the x / y fields follow) */
function refresh() {
  const O = OPEN; if (!O) return;
  const it = findItem(O.ed.project, O.id)?.item; if (!it) return;
  const typing = O.el.contains(document.activeElement) && document.activeElement !== O.el;
  if (typing || sigOf(it) === O.sig) {
    for (const inp of O.body.querySelectorAll('[data-tb-pos]')) if (inp !== document.activeElement) inp.value = Math.round(it[inp.dataset.tbPos]);
    O.sub.textContent = tbSummaryText(it);
    return;
  }
  render();
}
/** one line under the tabs while the words are not behind him yet (the Cut-out tab says why and how to fix it) */
function chip(f = null) {
  const O = OPEN; if (!O) return;
  const it = findItem(O.ed.project, O.id)?.item; if (!it) return;
  const st = tbStatus(O.ed.project, it, O.ed.t);
  const msg = st === 'pending' ? `Finding the person${f != null ? ` ${Math.round(f * 100)}%` : ''}… the words show in front until then` : st === 'model' ? 'Main subject needs its cut-out model: Cut-out tab' : st === 'none' ? 'No person in the clip at this moment: the words show in front' : st === 'noclip' ? 'No video clip under it here' : '';
  O.chip.hidden = !msg; O.chip.textContent = msg; O.chip.dataset.tbpChip = st;
}
function focusKey(key) {
  const O = OPEN; if (!O || !key) return;
  if (O.tab !== 'words') { O.tab = 'words'; render(); }
  const sel = key === 'top' ? '[data-tbp-top]' : key === 'word' ? '[data-tbp-word]' : key.startsWith('tbcar:') ? `[data-tb-car-word="${CSS.escape(key.slice(6))}"]` : null;
  const inp = sel && O.body.querySelector(sel);
  if (inp) { inp.scrollIntoView({ block: 'nearest' }); inp.focus(); inp.select?.(); }
}

// ── ▶ Play preview: the element's stretch, again and again ──
function setPlay(on) {
  const O = OPEN; if (!O || O.playing === on) return;
  const ed = O.ed, it = findItem(ed.project, O.id)?.item;
  O.playing = on; O.play.setAttribute('aria-pressed', on ? 'true' : 'false'); O.play.textContent = on ? '❚❚ Stop preview' : '▶ Play preview';
  if (on && it) { if (ed.t < it.t0 || ed.t >= itemEnd(it) - 0.05) ed.seek(it.t0); ed.play(); }
  else if (!on && ed.playing) { O.stopping = true; ed.pause(); O.stopping = false; }
}

// ── moving it ──
function drag(O, head) {
  head.addEventListener('pointerdown', (e) => {
    if (e.button !== 0 || e.target.closest('button')) return;
    e.preventDefault(); head.setPointerCapture(e.pointerId); head.classList.add('drag');
    const x0 = e.clientX - O.rect.x, y0 = e.clientY - O.rect.y;
    const mv = (ev) => { O.rect = { ...O.rect, x: Math.round(Math.min(Math.max(0, ev.clientX - x0), vw() - 60)), y: Math.round(Math.min(Math.max(0, ev.clientY - y0), vh() - 40)) }; place(O); };
    const up = () => { head.classList.remove('drag'); head.removeEventListener('pointermove', mv); head.removeEventListener('pointerup', up); head.removeEventListener('pointercancel', up); O.rect = fit(O.rect, O.ed); O.docked = false; place(O); save(O.rect); };
    head.addEventListener('pointermove', mv); head.addEventListener('pointerup', up); head.addEventListener('pointercancel', up);
  });
}

/** a toast with one button (it stays a little longer and takes clicks) */
export function actionToast(text, label, fn, ms = 7000) {
  const t = h('div.toast.ok.tbp-toast', { role: 'status' }, h('span', text), h('button.btn.small.primary', { type: 'button', dataset: { tbpToastEdit: '1' }, on: { click: () => { t.remove(); fn(); } } }, label));
  document.body.appendChild(t);
  setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, ms);
  return t;
}
