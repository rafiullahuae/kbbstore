// KBB Studio — editor/hover-help.js: a short guide card on hover or keyboard focus (the owner, 18 Nov: "a short
// description for each function by mouse over little bit than normal, so user can be sure what this is for").
// One card, one set of delegated listeners on the document. Its words come from the same index as the search
// (core/search-store.js: the curated catalogue, else the control's crawl entry), or from a control's own data-help.
// A control's native title="" is moved to aria-description when hovered or focused, so no second tooltip appears
// and screen readers still hear it.
//   · shows after 400 ms, or at once while moving between neighbouring controls; hides on leave, Esc, press, scroll
//   · sits below the control (above when there is no room, beside it for a tall one), never on it, inside the window
//   · mouse, pen and keyboard only: nothing on touch

import { getStore, peekStore, entryForElement, NEEDS } from '../core/search-store.js';
import { CONTROL_SEL, indexable, tipText } from '../core/search-crawl.js';

const CSS = `
.kbh{position:fixed;z-index:120;max-width:320px;min-width:160px;padding:10px 12px;border-radius:12px;background:#1f2130;border:1px solid var(--line3,#454959);box-shadow:0 14px 40px rgba(0,0,0,.55);color:var(--text,#f2f3f8);font:13px/1.45 var(--font,system-ui);pointer-events:none;opacity:0;transform:translateY(2px);transition:opacity .12s ease,transform .12s ease}
.kbh.on{opacity:1;transform:none}
.kbh-t{display:flex;gap:8px;align-items:baseline;justify-content:space-between;font-weight:700;font-size:13.5px;margin-bottom:3px}
.kbh-t kbd{font:600 11px/1 var(--font,system-ui);color:var(--text,#f2f3f8);background:var(--panel,#14151c);border:1px solid var(--line3,#454959);border-radius:6px;padding:3px 6px;white-space:nowrap}
.kbh-d{color:#d5d8e4}
.kbh-m{color:var(--muted,#a9aec0);margin-top:4px}
.kbh-n{color:var(--sel,#ffd166);margin-top:5px;font-size:12px;font-weight:600}
`;
const SHOW_MS = 400, CHAIN_MS = 350;
let card = null, cur = null, timer = 0, lastHide = 0, installed = false;

/** the control under a node, if the index covers it */
function controlOf(node) {
  const el = node?.closest?.(CONTROL_SEL);
  if (!el || el.closest('.kbh, [data-kbs="pop"]')) return null;
  return el.dataset.help || indexable(el) ? el : null;
}

/** move native tooltips aside (the control and the boxes around it), keeping the words for assistive tech */
function quietTitles(el) {
  for (let p = el; p && p !== document.body; p = p.parentElement) {
    const t = p.getAttribute('title');
    if (t == null) continue;
    p.setAttribute('data-kbs-title', t);
    if (p === el && !p.hasAttribute('aria-description')) p.setAttribute('aria-description', t);
    p.removeAttribute('title');
  }
}

/** the card's words for a control: { title, key, desc, more, need } */
export function helpFor(el) {
  const own = el.dataset.help;
  const e = entryForElement(el);
  if (!e && !own) return null;
  const tip = tipText(el).split('\n')[0];
  const desc = own || e.desc || tip;
  let more = e?.more || '';
  if (!own && e?.crawl && tip && desc !== tip && !more) more = tip;
  return { title: e?.title || el.getAttribute('aria-label') || tip, key: e?.key || '', desc, more, need: NEEDS[e?.go?.need] || '' };
}

function build(info) {
  if (!card) { card = document.createElement('div'); card.className = 'kbh'; card.setAttribute('role', 'tooltip'); card.id = 'kbh-card'; }
  card.replaceChildren();
  const t = document.createElement('div'); t.className = 'kbh-t';
  const b = document.createElement('span'); b.textContent = info.title; t.append(b);
  if (info.key) { const k = document.createElement('kbd'); k.textContent = info.key; t.append(k); }
  card.append(t);
  for (const [cls, txt] of [['kbh-d', info.desc], ['kbh-m', info.more], ['kbh-n', info.need]]) if (txt) { const d = document.createElement('div'); d.className = cls; d.textContent = txt; card.append(d); }
  if (!card.isConnected) document.body.appendChild(card);
}

/** below the control, else above, else beside it; always inside the window, never over the control */
function position(el) {
  const r = el.getBoundingClientRect(), c = card.getBoundingClientRect();
  const vw = innerWidth, vh = innerHeight, gap = 8, m = 8;
  let top, left = r.left + r.width / 2 - c.width / 2;
  if (r.bottom + gap + c.height <= vh - m) top = r.bottom + gap;
  else if (r.top - gap - c.height >= m) top = r.top - gap - c.height;
  else {
    top = Math.min(Math.max(m, r.top + r.height / 2 - c.height / 2), vh - c.height - m);
    left = r.right + gap + c.width <= vw - m ? r.right + gap : r.left - gap - c.width;
  }
  left = Math.max(m, Math.min(left, vw - c.width - m));
  card.style.top = Math.round(top) + 'px'; card.style.left = Math.round(left) + 'px';
}

function show(el) {
  if (!el.isConnected) return;
  const info = helpFor(el);
  if (!info || !info.desc) return;
  build(info); position(el);
  card.classList.add('on');
  el.setAttribute('aria-describedby', 'kbh-card');
  cur = el;
}
export function hideHelp() {
  clearTimeout(timer); timer = 0;
  if (cur) { cur.removeAttribute('aria-describedby'); lastHide = performance.now(); }
  cur = null;
  card?.classList.remove('on');
}
function arm(el, now) {
  clearTimeout(timer);
  quietTitles(el);
  if (!peekStore()) getStore({ live: false }).catch(() => {});
  const go = () => { if (peekStore()) show(el); else getStore({ live: false }).then(() => show(el)).catch(() => {}); };
  if (now) go(); else timer = setTimeout(go, SHOW_MS);
}

export function installHoverHelp() {
  if (installed) return; installed = true;
  const st = document.createElement('style'); st.dataset.kbh = 'css'; st.textContent = CSS; document.head.appendChild(st);
  document.addEventListener('pointerover', (e) => {
    if (e.pointerType === 'touch') return;
    const el = controlOf(e.target);
    if (el === cur || (el && el === e.relatedTarget?.closest?.(CONTROL_SEL))) return;
    const chain = !!cur || performance.now() - lastHide < CHAIN_MS;
    if (cur) hideHelp();
    if (el) arm(el, chain);
  }, true);
  document.addEventListener('pointerout', (e) => {
    if (!cur && !timer) return;
    const to = e.relatedTarget && controlOf(e.relatedTarget);
    const from = controlOf(e.target);
    if (from && to === from) return;
    if (!e.relatedTarget || !from?.contains(e.relatedTarget)) { if (cur) hideHelp(); else clearTimeout(timer); }
  }, true);
  document.addEventListener('focusin', (e) => {
    const el = controlOf(e.target);
    if (!el || !el.matches(':focus-visible') || el.matches('.kbs-in')) return;   // the search box shows its own list
    if (cur && cur !== el) hideHelp();
    arm(el, false);
  }, true);
  document.addEventListener('focusout', (e) => { if (e.target === cur) hideHelp(); }, true);
  document.addEventListener('keydown', () => { if (cur || timer) hideHelp(); }, true);   // Esc, or typing: the card goes
  document.addEventListener('pointerdown', () => { if (cur || timer) hideHelp(); }, true);
  addEventListener('scroll', () => { if (cur) hideHelp(); }, true);
  addEventListener('wheel', () => { if (cur) hideHelp(); }, { passive: true, capture: true });
}
