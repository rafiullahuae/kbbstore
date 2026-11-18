// KBB Studio — editor/search-ui.js: "Search anything… (⌘K)" in the top bar of the editor, Home and Configure
// (the owner, 18 Nov: "a super functional friendly search box … if i write trim, it should give the trim option which
// take me exactly to the trim box"). Results as you type, grouped Actions / Settings / Panels / Help, each with an
// icon, a breadcrumb, a one-line guide and its shortcut; ↑ ↓ Enter, or a click, goes there (editor/search-nav.js).
// A one-shot action also offers Run (⌘↵). Nothing leaves this Mac: the index is local (core/search-store.js).
//
//   searchBox()  → the element to put in a top bar (its own flexible slot; shrinks before anything else does)

import { getStore } from '../core/search-store.js';
import { goTo } from './search-nav.js';
import { h } from './ui.js';
import { installHoverHelp } from './hover-help.js';

const GROUPS = ['Actions', 'Settings', 'Panels', 'Help'];
const TRY = ['trim', 'noise', 'slow motion', 'bigger text', 'logo', 'export 4k', 'arabic captions', 'remove background'];
const RECENT_KEY = 'kbbstudio.search.recent';
const CSS = `
.kbs{flex:1 1 0;min-width:38px;max-width:460px;margin-inline:auto;position:relative;display:flex;align-items:center;container-type:inline-size}
/* a narrow bar (1280 with a long company name): only the ⌕ shows, and the field opens wide over its neighbours while typing */
@container (max-width:159px){.kbs-in:not(:focus){padding:0;color:transparent}.kbs-in:not(:focus)::placeholder{color:transparent}.kbs-in:focus{position:absolute;inset-inline-start:0;width:320px;z-index:6}}
.kbs-in{width:100%;height:36px;padding:0 14px 0 34px;border-radius:999px;border:1px solid var(--line2);background:var(--panel2);color:var(--text);font-size:13.5px;outline:none;transition:border-color var(--t),background-color var(--t)}
.kbs-in::placeholder{color:var(--dim)}
.kbs-in:hover{border-color:var(--line3)}
.kbs-in:focus{border-color:var(--accent-line);background:var(--panel3);box-shadow:0 0 0 3px var(--accent-soft)}
.kbs-in::-webkit-search-cancel-button{display:none}
.kbs-ic{position:absolute;inset-inline-start:12px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--muted);font-size:14px}
.kbs-pop{position:fixed;z-index:95;width:min(620px,calc(100vw - 24px));max-height:min(70vh,640px);overflow:auto;background:var(--panel2);border:1px solid var(--line2);border-radius:14px;box-shadow:var(--sh-3);padding:6px;color:var(--text);font:13px/1.4 var(--font);overscroll-behavior:contain}
.kbs-pop[hidden]{display:none}
.kbs-g{font:700 11px/1 var(--font);letter-spacing:.06em;text-transform:uppercase;color:var(--muted);padding:10px 10px 6px}
.kbs-o{display:grid;grid-template-columns:28px 1fr auto;gap:2px 10px;align-items:center;padding:8px 10px;border-radius:10px;cursor:pointer}
.kbs-o.on{background:var(--panel4)}
.kbs-o .oi{grid-row:span 3;font-size:17px;text-align:center;align-self:start;padding-top:2px}
.kbs-o .ot{font-weight:650;font-size:14px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kbs-o .ow{font-size:11.5px;color:var(--accent-text);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;grid-column:2}
.kbs-o .od{font-size:12.5px;color:var(--muted);grid-column:2 / span 2}
.kbs-o .ok{grid-column:3;grid-row:1;display:flex;gap:6px;align-items:center;justify-self:end}
.kbs-o kbd{display:inline-block;width:auto;min-width:0;font:600 11px/1 var(--font);color:var(--text);background:var(--panel);border:1px solid var(--line3);border-radius:6px;padding:4px 6px;white-space:nowrap}
.kbs-o .ob{height:24px;padding:0 9px;border-radius:999px;border:1px solid var(--line3);background:var(--panel);color:var(--text);font:600 11.5px/1 var(--font);cursor:pointer}
.kbs-o .ob.run{background:var(--accent);border-color:var(--accent);color:var(--accent-fg)}
.kbs-o .ob:hover{filter:brightness(1.15)}
.kbs-empty{padding:14px 12px;color:var(--muted)}
.kbs-empty b{color:var(--text)}
.kbs-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.kbs-chips button{height:28px;padding:0 11px;border-radius:999px;border:1px solid var(--line3);background:var(--panel3);color:var(--text);font:600 12px/1 var(--font);cursor:pointer}
.kbs-chips button:hover{border-color:var(--accent-line)}
.kbs-foot{display:flex;gap:14px;justify-content:flex-end;padding:8px 10px 4px;color:var(--muted);font-size:11.5px;border-top:1px solid var(--line);margin-top:4px}
@keyframes kbsPulse{0%{box-shadow:0 0 0 0 rgba(255,209,102,.95),0 0 0 2px var(--sel)}70%{box-shadow:0 0 0 12px rgba(255,209,102,0),0 0 0 2px var(--sel)}100%{box-shadow:0 0 0 0 rgba(255,209,102,0),0 0 0 2px var(--sel)}}
.kbs-pulse{animation:kbsPulse .9s ease-out 3;outline:2px solid var(--sel)!important;outline-offset:2px;border-radius:8px}
@media (prefers-reduced-motion:reduce){.kbs-pulse{animation:none}}
`;
let styled = false;
const style = () => { if (!styled) { document.head.appendChild(h('style', { dataset: { kbs: 'css' } }, CSS)); styled = true; } };

// ⌘K / Ctrl+K from anywhere, "/" when not typing: the box on the screen that is showing
let keysOn = false, placing = null;
function installKeys() {
  if (keysOn) return; keysOn = true;
  addEventListener('resize', () => placing?.());
  document.addEventListener('keydown', (e) => {
    // Esc in the box closes the results (or clears the words), not Configure or a dialog behind it
    if (e.key === 'Escape' && e.target?._kbsEsc) { e.preventDefault(); e.stopImmediatePropagation(); e.target._kbsEsc(); return; }
    const k = (e.key || '').toLowerCase();
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target?.tagName) || e.target?.isContentEditable;
    if (((e.metaKey || e.ctrlKey) && k === 'k' && !e.shiftKey && !e.altKey) || (k === '/' && !typing && !e.metaKey && !e.ctrlKey && !document.querySelector('.modal-back'))) {
      const boxes = [...document.querySelectorAll('.kbs-in')].filter((b) => b.checkVisibility?.() !== false);
      const box = boxes.find((b) => b.closest('.cfg-back')) || boxes.pop();
      if (!box) return;
      e.preventDefault(); e.stopPropagation();
      box.focus(); box.select();
    }
  }, true);
}

const recent = {
  get() { try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').slice(0, 5); } catch { return []; } },
  add(t) { try { const l = [t, ...this.get().filter((x) => x !== t)].slice(0, 5); localStorage.setItem(RECENT_KEY, JSON.stringify(l)); } catch { /* private window: no history */ } },
};

export function searchBox() {
  style(); installKeys(); installHoverHelp();
  const id = 'kbs-' + Math.random().toString(36).slice(2, 8);
  const input = h('input.kbs-in', { type: 'search', placeholder: 'Search anything… (⌘K)', 'aria-label': 'Search KBB Studio', role: 'combobox', 'aria-expanded': 'false', 'aria-controls': id, 'aria-autocomplete': 'list', autocomplete: 'off', spellcheck: 'false', dataset: { help: 'Find any tool, setting or tab by name (trim, noise, slow motion, logo…) and jump straight to it. ⌘K or / from anywhere.' } });
  const pop = h('div.kbs-pop', { id, role: 'listbox', hidden: true, 'aria-label': 'Search results', dataset: { kbs: 'pop' } });
  const box = h('div.kbs', { dataset: { kbs: 'box' } }, h('span.kbs-ic', { 'aria-hidden': 'true' }, '⌕'), input);
  let results = [], active = 0, store = null, seq = 0;

  const place = () => {
    const r = input.getBoundingClientRect();
    const w = Math.min(620, innerWidth - 24);
    pop.style.top = Math.round(r.bottom + 8) + 'px';
    pop.style.left = Math.round(Math.max(12, Math.min(r.left + r.width / 2 - w / 2, innerWidth - w - 12))) + 'px';
  };
  const open = () => { if (!pop.isConnected) document.body.appendChild(pop); place(); placing = place; pop.hidden = false; input.setAttribute('aria-expanded', 'true'); };
  const close = () => { pop.hidden = true; pop.remove(); placing = null; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); };

  const choose = async (r, run = false) => {
    close(); input.blur(); input.value = '';
    recent.add(r.e.title);
    const res = await goTo(r.e, { run });
    if (!res?.ok && res?.msg) { const { toast } = await import('./ui.js'); toast(res.msg, 'info', 4200); }
  };

  const option = (r, i) => {
    const e = r.e, oid = `${id}-o${i}`;
    const acts = e.go?.run ? [h('button.ob.run', { type: 'button', tabindex: '-1', title: 'Run it now (⌘↵)', on: { click: (ev) => { ev.stopPropagation(); choose(r, true); } } }, 'Run'),
      h('button.ob', { type: 'button', tabindex: '-1', on: { click: (ev) => { ev.stopPropagation(); choose(r, false); } } }, 'Show me where')] : [];
    return h('div.kbs-o' + (i === active ? '.on' : ''), { id: oid, role: 'option', 'aria-selected': String(i === active), dataset: { i: String(i), id: e.id },
      on: { mousemove: () => setActive(i), click: () => choose(r, false) } },
    h('span.oi', { 'aria-hidden': 'true' }, e.icon || '•'),
    h('span.ot', e.title),
    h('span.ok', e.key ? h('kbd', e.key) : null, ...acts),
    h('span.ow', e.where || ''),
    h('span.od', e.desc || ''));
  };
  const setActive = (i) => {
    if (i === active) return;
    const prev = pop.querySelector('.kbs-o.on');
    if (prev) { prev.classList.remove('on'); prev.setAttribute('aria-selected', 'false'); }
    active = i;
    const el = pop.querySelector(`.kbs-o[data-i="${i}"]`);
    if (el) { el.classList.add('on'); el.setAttribute('aria-selected', 'true'); el.scrollIntoView({ block: 'nearest' }); input.setAttribute('aria-activedescendant', el.id); }
  };
  const chips = (list, label) => h('div.kbs-chips', { 'aria-label': label }, list.map((t) => h('button', { type: 'button', tabindex: '-1', on: { mousedown: (ev) => ev.preventDefault(), click: () => { input.value = t; render(); input.focus(); } } }, t)));

  function render() {
    const q = input.value.trim();
    if (!store) return;
    pop.replaceChildren();
    if (!q) {
      results = []; active = 0;
      const rec = recent.get();
      pop.append(h('div.kbs-empty', h('b', 'Search every tool, setting and tab'), h('div', 'Type what you want to do: “trim”, “noise”, “slow motion”, “logo”, “export 4k”…'), chips(TRY, 'Try'),
        rec.length ? h('div', { style: { marginTop: '12px' } }, h('div.kbs-g', { style: { padding: '0 0 2px' } }, 'Recent'), chips(rec, 'Recent searches')) : null));
      pop.append(h('div.kbs-foot', h('span', '↑ ↓ move'), h('span', '↵ go there'), h('span', '⌘↵ run'), h('span', 'Esc close'), h('span', `${store.entries.length} things indexed`)));
      return;
    }
    const t0 = performance.now();
    const all = store.index.search(q, { limit: 60 });
    window.__kbsLastMs = performance.now() - t0;
    // the best one first, then the rest by group, a few each
    results = [];
    if (all.length) {
      results.push(all[0]);
      for (const g of GROUPS) results.push(...all.slice(1).filter((r) => r.e.group === g).slice(0, g === 'Help' ? 3 : 6));
    }
    active = 0;
    if (!results.length) {
      const sug = store.index.suggest(q);
      pop.append(h('div.kbs-empty', h('div', 'No result for ', h('b', `“${q}”`), '.'), sug.length ? h('div', { style: { marginTop: '6px' } }, 'Did you mean:', chips(sug, 'Did you mean')) : h('div', 'Try fewer words, or one of:'), sug.length ? null : chips(TRY.slice(0, 5), 'Try')));
      return;
    }
    let i = 0;
    const by = new Map();
    pop.append(h('div.kbs-g', 'Top result'), option(results[0], i++));
    for (const r of results.slice(1)) { if (!by.has(r.e.group)) by.set(r.e.group, []); by.get(r.e.group).push(r); }
    for (const g of GROUPS) {
      const list = by.get(g); if (!list?.length) continue;
      pop.append(h('div.kbs-g', g));
      for (const r of list) { results[i] = r; pop.append(option(r, i++)); }
    }
    input.setAttribute('aria-activedescendant', `${id}-o0`);
  }

  input.addEventListener('focus', async () => {
    const my = ++seq;
    open();
    if (!store) pop.replaceChildren(h('div.kbs-empty', 'Getting the index ready…'));
    store = await getStore();
    if (my === seq && document.activeElement === input) { render(); place(); }
  });
  input.addEventListener('input', () => { if (store) { open(); render(); } });
  input.addEventListener('blur', () => setTimeout(() => { if (document.activeElement !== input) close(); }, 120));
  pop.addEventListener('mousedown', (e) => e.preventDefault());   // keep the focus in the box while clicking
  input._kbsEsc = () => { if (input.value) { input.value = ''; render(); } else { close(); input.blur(); } };
  input.addEventListener('keydown', (e) => {
    e.stopPropagation();   // the editor's own single-key shortcuts stay quiet while typing here
    if (pop.hidden) open();
    const n = results.length;
    if (e.key === 'ArrowDown' && n) { e.preventDefault(); setActive((active + 1) % n); }
    else if (e.key === 'ArrowUp' && n) { e.preventDefault(); setActive((active - 1 + n) % n); }
    else if (e.key === 'Enter' && n) { e.preventDefault(); choose(results[active], (e.metaKey || e.ctrlKey) && !!results[active].e.go?.run); }
  });
  return box;
}
