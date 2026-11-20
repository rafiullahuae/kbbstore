// KBB Studio — editor/search-nav.js: "take me exactly there" for a search result (editor/search-ui.js).
// goTo(entry, { run }) opens the right screen, tab, sub-tab and folded sections, selects what the control needs (the
// clip under the playhead, else the first clip; or says in one line what to select), scrolls the control into view,
// focuses it and rings it with a short pulse. With run, a one-shot action is also pressed (Split, Auto captions …).
// It drives the studio through its own buttons and methods (showSide, select, goHome), never around them.

import { crawl, currentScreen, splitIcon, labelOf } from '../core/search-crawl.js';
import { normalize } from '../core/search.js';
import { h, toast, modal } from './ui.js';
import { CTA2_IDS } from '../themes/cta2.js';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const frame = () => new Promise((r) => requestAnimationFrame(() => r()));
async function waitFor(fn, ms = 4000) {
  const t0 = performance.now();
  for (;;) { const v = fn(); if (v) return v; if (performance.now() - t0 > ms) return null; await sleep(40); }
}
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const words = (s) => normalize(splitIcon(s || '').text);
const vis = (e) => !!e && (!e.checkVisibility || e.checkVisibility());

/** close what sits on top (dialogs, Configure) unless it is where we are going */
async function clearLayers(keep) {
  if (keep !== 'modal') for (let i = 0; i < 4 && $('.modal-back'); i++) {
    const x = [...document.querySelectorAll('.modal-back')].pop().querySelector('.modal-head .icon-btn');
    if (x) x.click(); else document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await sleep(60);
  }
  if (keep !== 'configure' && $('.cfg-back')) { $('#cfg-close')?.click(); await waitFor(() => !$('.cfg-back'), 1500); }
}

async function toHome() {
  await clearLayers();
  if ($('.home')) return true;
  if (window.kbbEditor) { await window.kbbEditor.goHome(); return !!(await waitFor(() => $('.home'))); }
  const back = $$('button').find((b) => /^(← Home|◀ KBB Studio|▶ KBB Studio)/.test(b.textContent.trim()) || b.title === 'Home');
  back?.click();
  return !!(await waitFor(() => $('.home')));
}

/** be in the editor; from Home the latest reel opens (said in a toast) */
async function toEditor() {
  await clearLayers();
  if ($('.editor') && window.kbbEditor) return { ok: true };
  if (!$('.home')) await toHome();
  const recent = await waitFor(() => $('.recent') || ($('.recent-list .empty') && 'none'), 3000);
  if (!recent || recent === 'none') return { ok: false, msg: 'Open a reel first: drop a video on “Drop a video here”, then search again.', el: $('.home-drop') };
  const name = recent.querySelector('b')?.textContent || 'your latest reel';
  recent.click();
  if (!(await waitFor(() => $('.editor') && window.kbbEditor && $('.tl-item, .tl-lane')))) return { ok: false, msg: 'The reel did not open.' };
  toast(`Opened ${name}`, 'info');
  await sleep(150);
  return { ok: true };
}

/** what a control needs selected; returns a one-line hint when it cannot be done for you */
function ensureSelection(need) {
  const ed = window.kbbEditor; if (!ed || !need) return null;
  const p = ed.project, t = ed.t;
  const all = p.tracks.flatMap((tr) => tr.items.map((item) => ({ tr, item })));
  const end = (it) => it.t0 + (it.out - it.in) / (it.speed || 1);
  const pick = (ok) => {
    const sel = ed.selectedItems().find((x) => ok(x.item, x.track));
    if (sel && ed.sel.size === 1) return true;
    const list = all.filter((x) => ok(x.item, x.tr));
    const hit = list.find((x) => x.item.t0 <= t && t < end(x.item)) || list[0];
    if (!hit) return false;
    ed.select([hit.item.id]);
    return true;
  };
  if (need === 'none') { if (ed.sel.size) ed.select([]); return null; }
  if (need === 'clip' && !pick((it, tr) => it.type === 'clip' && (tr.kind === 'video' || tr.kind === 'overlay'))) return 'Add a video first: Left panel → Media → “Add videos, photos, music”.';
  if (need === 'music' && !pick((it, tr) => it.type === 'audio' && tr.kind === 'music')) return 'Add music first: Do it for me → 🎵 Add music, or Left panel → Music → +.';
  if (need === 'text' && !pick((it) => it.type === 'text')) return 'Add a text first: Left panel → Text, then pick Title or Subtitle.';
  if (need === 'capsules' && !pick((it) => it.type === 'theme.el' && it.style?.el === 'capsules')) return 'Place a capsules carousel first: Themes → Capsules carousel → ＋.';
  if (need === 'theme' && !pick((it) => /^theme\.(el|sting|outro)$/.test(it.type) && !/^(capsules|grade|wipe|confetti)$/.test(it.style?.el || ''))) return 'Place a theme element first: Themes → Place elements (a lower third, an end card …) → ＋.';
  // contact cards, the logo badge, country badges, cut-out transitions (themes/el2-panel.js)
  const EL2 = { cta: ['cta', 'a contact card: Themes → Place elements → Contact card → ＋'], logo: ['logo', 'a logo badge: Themes → One click → 🔰 Logo badge'], cbadge: ['cbadge', 'a country badge: Themes → One click → 🌐 Badge every country I mention'], endscreen: ['endscreen', 'an ending screen: Themes → One click → 🏁 Add ending screen'], tbehind: ['tbehind', 'a text-behind element: Themes → One click → 🔤 Text behind me'] };
  if (EL2[need] && !pick((it) => it.type === 'theme.el' && it.style?.el === EL2[need][0])) return 'Place ' + EL2[need][1] + '.';
  // a slim contact card's own controls (Show · Colours · Motion) are there only for the slim designs (themes/cta2.js)
  if (need === 'cta2' && !pick((it) => it.type === 'theme.el' && it.style?.el === 'cta' && CTA2_IDS.has(it.style?.variant))) return 'Place a slim contact card first: Themes → Place elements → Contact card → ＋ (or pick a Slim design in Inspector → Contact card).';
  if (need === 'cutout' && !pick((it) => it.type === 'theme.trans' && /^cut-/.test(it.style?.kind || ''))) return 'Put a cut-out transition on a cut first: Themes → Transitions between clips → Person stays, background swaps → ＋.';
  if (need === 'multi' && ed.sel.size < 2) return 'Select two or more items first (Shift-click them on the timeline or drag a box on the video).';
  return null;
}

const MODAL_OPEN = {
  export: ['#x-go', () => $('.export-btn')?.click()],
  shortcuts: ['.modal .keys', () => $('.topbar button[title^="Keyboard"]')?.click()],
  company: ['.modal .company-form', () => $$('.home button').find((b) => /Edit company/.test(b.textContent))?.click()],
};

/** the element a control's place resolves to */
function scopeOf(go) {
  if (go.modal) return [...document.querySelectorAll('.modal-back')].pop() || document.body;
  if (go.side) return $$('.inspector.side .side-body').find((b) => !b.hidden) || document.body;
  if (go.bin) return $('aside.bin') || document.body;
  if (go.cfg) return $('.cfg-main') || document.body;
  if (go.region) return $(go.region) || document.body;
  return currentScreen().root;
}

function openDetailsFor(el) { for (let p = el.parentElement; p; p = p.parentElement) if (p.tagName === 'DETAILS' && !p.open) p.open = true; }

/** the control by its words in a scope (as the crawl reads them); exact first, then the words at the start */
export function findByLabel(scope, label, sub) {
  const want = words(label), recs = crawl(scope);
  return (recs.find((r) => normalize(r.label) === want && (!sub || normalize(r.sub || '') === normalize(sub)))
    || recs.find((r) => normalize(r.label) === want)
    || recs.find((r) => normalize(r.label).startsWith(want)) || null)?.el || null;
}

/** open the folded sections named in go.sec (outermost first); returns the last summary */
async function openSections(scope, secs) {
  let last = null;
  for (const s of secs || []) {
    const want = words(s);
    const sum = await waitFor(() => $$('details > summary', scope).find((x) => { const w = normalize(splitIcon(labelOf(x)).text); return w === want || w.startsWith(want); }), 2500);
    if (!sum) break;
    sum.parentElement.open = true; last = sum;
    await frame(); await sleep(40);
  }
  return last;
}

/** ring a control: scroll it to the middle of its column, focus it, pulse it */
export function reveal(el, { focus = true } = {}) {
  if (!el) return;
  openDetailsFor(el);
  const ring = el.matches('input[type=range], input[type=checkbox], input[type=color], select') ? el.closest('label.row, .row, label') || el : el;
  ring.scrollIntoView({ block: 'center', inline: 'nearest' });
  if (focus) {
    const f = el.matches('button, input, select, textarea, summary, a[href], [tabindex]') ? el : el.querySelector('button, input, select, textarea, summary, [tabindex]');
    const t = f || el;
    if (!t.matches('button, input, select, textarea, summary, a[href], [tabindex]')) t.setAttribute('tabindex', '-1');
    t.focus({ preventScroll: true });
  }
  ring.classList.remove('kbs-pulse'); void ring.offsetWidth; ring.classList.add('kbs-pulse');
  clearTimeout(ring._kbsT); ring._kbsT = setTimeout(() => ring.classList.remove('kbs-pulse'), 2600);
}

function helpCard(entry) {
  modal({ title: entry.title, body: h('div.kbs-help', h('p', entry.go.help || entry.desc), entry.where ? h('p.muted', entry.where) : null), actions: [{ label: 'OK', primary: true }] });
}

/** go to a search entry. Returns { ok, el, msg } (also used by tools/search-check.cjs) */
export async function goTo(entry, { run = false } = {}) {
  const go = entry.go || {};
  if (go.help && !go.screen) { helpCard(entry); return { ok: true, help: true }; }
  const screen = go.screen || 'editor';
  if (screen === 'adstudio') { const m = await import('../adstudio/entry.js'); m.openAdStudio(); return { ok: true }; }
  if (screen === 'models') { await clearLayers(); const m = await import('./setup.js'); m.openSetup(); return { ok: true, el: await waitFor(() => $('.modal .setup-form')) }; }
  if (screen === 'configure') {
    await clearLayers('configure');
    if (!$('.cfg-back')) { $('#configure-btn')?.click(); if (!(await waitFor(() => $('.cfg-back [data-sec]')))) return { ok: false, msg: 'Configure did not open.' }; }
  } else if (screen === 'home') {
    if (!(await toHome())) return { ok: false, msg: 'Home did not open.' };
  } else if (screen === 'designs' || screen === 'design-editor') {
    if (screen === 'designs' && $('.de')) $('#de-back')?.click();
    if (!$('.dz') && !$('.de')) { if (!(await toHome())) return { ok: false }; $$('.home button').find((b) => /Open Designs/.test(b.textContent))?.click(); }
    if (!(await waitFor(() => $('.dz, .de')))) return { ok: false, msg: 'Designs did not open.' };
    if (screen === 'design-editor' && !$('.de')) {
      const card = await waitFor(() => $('.dz-card'), 1500);
      if (!card) { const b = $('.dz-brief'); toast('Make a design first: type a brief and press ✨ Make designs, then open one.', 'info', 4200); if (b) reveal(b); return { ok: false, el: b }; }
      card.click(); if (!(await waitFor(() => $('#de-canvas')))) return { ok: false };
      await sleep(200);
    }
    if (!go.label && !go.sel) { const el = $('.dz-brief') || $('.dz, .de'); reveal(el); return { ok: true, el }; }
  } else if (screen === 'editor') {
    const r = await toEditor(); if (!r.ok) { if (r.el) reveal(r.el); if (r.msg) toast(r.msg, 'info', 4200); return r; }
  }
  // what it needs selected
  let hint = null;
  if (screen === 'editor') {
    hint = ensureSelection(go.need);
    if (hint && go.need === 'capsules') { toast(hint, 'info', 4200); return goTo({ go: { screen: 'editor', side: 'mod:themes', label: 'Capsules carousel' } }); }
    if (hint && go.need === 'theme') { toast(hint, 'info', 4200); return goTo({ go: { screen: 'editor', side: 'mod:themes', label: 'Add at the playhead' } }); }
    if (hint && /^(cta|cta2|logo|cbadge|cutout|endscreen|tbehind)$/.test(go.need)) { toast(hint, 'info', 4200); return goTo({ go: { screen: 'editor', side: 'mod:themes', sel: go.need === 'cutout' ? '[data-place-trans="cut-bgswap"]' : /^cta2?$/.test(go.need) ? '[data-place="cta"]' : go.need === 'tbehind' ? '[data-tb-add]' : '[data-e2-quick]' } }); }
    if (hint && go.need === 'text') { toast(hint, 'info', 4200); return goTo({ go: { screen: 'editor', bin: 'Text', label: 'Title' } }); }
    if (hint) toast(hint, 'info', 4200);
  }
  if (go.side) window.kbbEditor?.showSide(go.side);
  // a control of the Text behind editor: open it on its tab (themes/textbehind-popup.js)
  if (go.tbp) {
    const ed = window.kbbEditor, it = ed?.selectedItems().map((x) => x.item).find((i) => i.style?.el === 'tbehind');
    if (it) { const P = await import('../themes/textbehind-popup.js'); P.openTbEditor(ed, it.id, { tab: go.tbp }); await waitFor(() => $('.tbp')); await sleep(60); }
  }
  if (go.bin) { const tab = $$('.bin-tab').find((b) => words(b.textContent) === words(go.bin) || words(b.textContent).endsWith(words(go.bin))); tab?.click(); await sleep(60); }
  if (go.captab) { const b = await waitFor(() => $(`.rs-cap-tabs button[data-tab="${go.captab}"]`), 3000); if (b && !b.classList.contains('on')) { b.click(); await sleep(80); } }
  if (go.modal) {
    const m = MODAL_OPEN[go.modal];
    if (m && !$(m[0])) { m[1](); if (!(await waitFor(() => $(m[0])))) return { ok: false, msg: 'That window did not open.' }; await sleep(120); }
  }
  if (go.cfg) {
    const b = $(`.cfg-nav [data-sec="${go.cfg}"]`); if (b && !b.classList.contains('on')) b.click();
    await waitFor(() => $(`.cfg-sec[data-sec="${go.cfg}"]`)?.children.length);
    await sleep(60);
  }
  if (go.dz) { const b = $(`.dz-tabs [data-t="${go.dz}"]`); if (b && !b.classList.contains('on')) { b.click(); await sleep(250); } }
  if (go.sub) { const bar = $(go.sub.bar, scopeOf(go)); const b = bar && findByLabel(bar, go.sub.label); if (b && !b.classList.contains('on')) { b.click(); await sleep(80); } }
  await frame();
  const scope = scopeOf(go);
  const lastSum = await openSections(scope, go.sec);
  let el = null;
  if (go.sel) el = await waitFor(() => [...scope.querySelectorAll(go.sel), ...document.querySelectorAll(go.sel)].find(vis) || null, 2500);
  if (!el && go.label) el = await waitFor(() => findByLabel(scope, go.label, go.subLabel), 2500);
  if (!el) el = lastSum || (go.side && $(`.side-tab[data-side="${go.side}"]`)) || (go.cfg && $(`.cfg-nav [data-sec="${go.cfg}"]`)) || (go.bin && $('.bin-tab.on')) || null;
  if (!el) return { ok: false, msg: `Could not find “${entry.title}” on this screen.` };
  reveal(el);
  if (go.help) toast(go.help, 'info', 6000);
  if (run && go.run) { await sleep(120); el.click(); }
  return { ok: true, el, hint };
}
