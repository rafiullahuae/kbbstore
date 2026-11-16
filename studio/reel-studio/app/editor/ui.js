// KBB Studio — small DOM helpers shared by the editor screens.

/** h('div.cls#id', {attrs, on:{click}}, ...children) */
export function h(sel, attrs, ...kids) {
  const m = /^([a-z0-9-]+)?((?:[.#][\w-]+)*)$/i.exec(sel) || [];
  const el = document.createElement(m[1] || 'div');
  for (const part of (m[2] || '').match(/[.#][\w-]+/g) || []) {
    if (part[0] === '.') el.classList.add(part.slice(1)); else el.id = part.slice(1);
  }
  if (attrs && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) { kids.unshift(attrs); attrs = null; }
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v == null || v === false) continue;
    if (k === 'on') for (const [ev, fn] of Object.entries(v)) el.addEventListener(ev, fn);
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k in el && k !== 'list' && k !== 'type' && typeof v !== 'string') el[k] = v;
    else if (k === 'value' || k === 'checked' || k === 'selected') el[k] = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  append(el, kids);
  return el;
}
function append(el, kids) {
  for (const k of kids.flat(Infinity)) {
    if (k == null || k === false) continue;
    el.appendChild(k instanceof Node ? k : document.createTextNode(String(k)));
  }
}
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
export const clear = (el) => { while (el.firstChild) el.removeChild(el.firstChild); return el; };

export function fmtTime(t, fps = 30, frames = false) {
  t = Math.max(0, t || 0);
  const m = Math.floor(t / 60), s = Math.floor(t % 60);
  const base = `${m}:${String(s).padStart(2, '0')}`;
  if (!frames) return base + '.' + String(Math.floor((t % 1) * 10));
  return base + ':' + String(Math.floor((t % 1) * fps)).padStart(2, '0');
}
export const fmtDur = (t) => (t >= 60 ? `${Math.floor(t / 60)}:${String(Math.round(t % 60)).padStart(2, '0')}` : `${(Math.round(t * 10) / 10).toFixed(1)}s`);

// ── toast ──
let toastBox = null;
export function toast(text, kind = 'info', ms = 2600) {
  if (!toastBox) { toastBox = h('div.toasts'); document.body.appendChild(toastBox); }
  const t = h('div.toast.' + kind, { role: 'status' }, text);
  toastBox.appendChild(t);
  setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, ms);
}

// ── modal ──
/** modal({title, body: Node, actions:[{label, primary, danger, onClick -> false keeps it open}], wide, onClose}) */
export function modal({ title, body, actions = [], wide = false, onClose, dismissable = true }) {
  const close = (v) => { back.remove(); document.removeEventListener('keydown', onKey, true); onClose?.(v); };
  const onKey = (e) => { if (e.key === 'Escape' && dismissable) { e.stopPropagation(); close(null); } };
  const box = h('div.modal' + (wide ? '.wide' : ''), { role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
    h('div.modal-head', h('h2', title), dismissable ? h('button.icon-btn', { title: 'Close', on: { click: () => close(null) } }, '✕') : null),
    h('div.modal-body', body),
    actions.length ? h('div.modal-foot', actions.map((a) => h('button.btn' + (a.primary ? '.primary' : '') + (a.danger ? '.danger' : ''), {
      on: { click: async () => { const r = await a.onClick?.(); if (r !== false) close(a.value ?? a.label); } },
      disabled: a.disabled, id: a.id,
    }, a.label))) : null);
  const back = h('div.modal-back', { on: { pointerdown: (e) => { if (e.target === back && dismissable) close(null); } } }, box);
  document.body.appendChild(back);
  document.addEventListener('keydown', onKey, true);
  setTimeout(() => box.querySelector('input,textarea,select,button.primary')?.focus(), 0);
  return { close, box, back };
}

export function confirmDialog(title, text, okLabel = 'OK') {
  return new Promise((res) => {
    modal({ title, body: h('p', text), actions: [{ label: 'Cancel', value: false }, { label: okLabel, primary: true, value: true }], onClose: (v) => res(v === true) });
  });
}

/** labelled range slider bound to a value; onInput(v) while dragging, onDone() on release */
export function slider({ label, min, max, step = 0.01, value, fmt = (v) => v, onInput, onDone }) {
  const out = h('span.val', fmt(value));
  const inp = h('input', { type: 'range', min, max, step, value });
  inp.addEventListener('input', () => { const v = parseFloat(inp.value); out.textContent = fmt(v); onInput(v); });
  inp.addEventListener('change', () => onDone?.());
  return h('label.row.slider', h('span.lbl', label), inp, out);
}

export function field(label, control) { return h('label.row', h('span.lbl', label), control); }

export function select(options, value, onChange) {
  const s = h('select', options.map(([v, l]) => h('option', { value: v, selected: String(v) === String(value) }, l)));
  s.addEventListener('change', () => onChange(s.value));
  return s;
}

export function seg(options, value, onChange, cls = '') {
  const box = h('div.seg' + cls);
  for (const [v, l, title] of options) {
    box.appendChild(h('button' + (v === value ? '.on' : ''), { type: 'button', title: title || l, dataset: { v }, on: { click: () => { for (const b of box.children) b.classList.toggle('on', b.dataset.v === v); onChange(v); } } }, l));
  }
  return box;
}

export const isTyping = (e) => {
  const t = e.target;
  return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) && !(t.type === 'range' || t.type === 'checkbox' || t.type === 'button' || t.type === 'color'));
};
