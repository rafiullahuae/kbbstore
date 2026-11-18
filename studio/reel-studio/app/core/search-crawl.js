// KBB Studio — core/search-crawl.js: the automatic half of the search index (the owner, 18 Nov: "make sure search has
// every single details about our studio app"). It walks the RENDERED interface and turns every button, tab, section
// header, slider, field and setting it can see into a search entry, by its own words (label, title, aria-label), with
// the place it lives and the way back to it (screen → tab → sub-tab → folded sections → the control).
//
// Used three ways:
//   · at run time, each time the search box opens: whatever is on screen now joins the index (search-ui.js);
//   · by tools/search-check.cjs, which visits every screen and tab the ui-audit visits and FAILS on a visible control
//     the index does not know, so a new button without a label or an entry cannot slip in;
//   · by `tools/search-check.cjs --write`, which saves the full crawl into core/search-crawl-data.js so the search knows
//     every panel from a cold start, before its tab was ever opened.
// Reads the DOM only (no layout measuring beyond checkVisibility), never changes it.

/** controls a person can act on */
export const CONTROL_SEL = 'button, [role=tab], [role=button], [role=menuitem], summary, select, textarea, a[href], '
  + 'input:not([type=hidden]):not([type=file]), label:has(> input[type=file]), .chip';

/** content and machinery, not controls: the preview, the timeline's clips, toasts, the search itself, reels in Recent,
 *  single emoji in a picker, a caption transcript's words */
const SKIP = '.pv-wrap, .tl-lane, .tl-item, .de-stage, canvas, .toasts, .kbs, .recent-list, .emoji-grid, .sticker-grid, '
  + '.rs-cap-words, .rs-word, .ws-menu .ws-del, .thumbs, .hdl, .snap';

const LETTER = /[\p{L}\p{N}]/u;
const clean = (s) => String(s || '').replace(/\s+/g, ' ').trim();
/** "⏪ Reverse" → { icon: '⏪', text: 'Reverse' } (an emoji or symbol in front is the control's icon) */
export function splitIcon(s) {
  s = clean(s);
  const m = /^([^\p{L}\p{N}]{1,6}?)\s*(?=[\p{L}\p{N}])/u.exec(s);
  if (!m || !m[1].trim()) return { icon: '', text: s };
  return { icon: m[1].trim(), text: s.slice(m[0].length) };
}

/** the words a control shows or announces (the label a person would search for) */
export function labelOf(e) {
  const aria = e.getAttribute('aria-label');
  if (aria && LETTER.test(aria)) return clean(aria);
  if (e.tagName === 'SUMMARY') return clean(firstLine(e));
  if (e.tagName === 'INPUT' || e.tagName === 'SELECT' || e.tagName === 'TEXTAREA') {
    const lab = e.closest('label');
    const own = lab ? clean((lab.querySelector('.lbl, span, small') || lab).textContent) : '';
    if (own && LETTER.test(own) && own.length < 60) return own;
    const prev = e.previousElementSibling;
    if (prev && /LABEL|SPAN|SMALL|B/.test(prev.tagName) && LETTER.test(prev.textContent) && prev.textContent.length < 60) return clean(prev.textContent);
    const row = e.closest('.row, .cfg-row, .field');
    const rl = row && row.querySelector(':scope > .lbl, :scope > label, :scope > span');
    if (rl && rl !== e && LETTER.test(rl.textContent) && rl.textContent.length < 60) return clean(rl.textContent);
    if (e.placeholder && LETTER.test(e.placeholder)) return clean(e.placeholder);
    if (tipText(e) && LETTER.test(tipText(e))) return clean(tipText(e).split(/ [—·(]/)[0]);
    // a tick or a list with no label of its own: the words of the row it sits in, else what it is set to
    if (e.type === 'checkbox' || e.type === 'radio') { const rw = rowText(e); if (rw) return rw; }
    if (e.tagName === 'SELECT' && e.selectedOptions?.[0]) return 'Choose ' + clean(e.selectedOptions[0].textContent).slice(0, 50);
    return '';
  }
  // a tile whose tooltip starts with its name on a line of its own ("Rose petals\nwhy\nHover to preview")
  if (tipText(e).includes('\n')) return clean(tipText(e).split('\n')[0]);
  // a tile with its name in bold and a line under it
  const main = e.firstElementChild?.matches?.('b, strong') ? e.firstElementChild : e.querySelector(':scope > span.ic + b, :scope > span.ic + strong, :scope > .ic + span');
  if (main && LETTER.test(main.textContent) && clean(e.textContent).length > clean(main.textContent).length + 3) {
    const ic = e.querySelector(':scope > .ic, :scope > .st-ic');
    return clean((ic && !LETTER.test(ic.textContent) ? ic.textContent + ' ' : '') + main.textContent);
  }
  const txt = clean(e.innerText || e.textContent).replace(/\s*[☆★]\s*/g, ' ').trim();
  // only numbers and signs ("¼×", "− 0.05", "0:02.5"): the tooltip says what it does
  if (tipText(e) && !/\p{L}/u.test(txt) && LETTER.test(tipText(e))) return clean(tipText(e).split(/ [—·]/)[0]);
  if (txt && LETTER.test(txt) && txt.length <= 70) return txt;
  if (tipText(e) && LETTER.test(tipText(e))) return clean(tipText(e).split(/ [—·]/)[0]);
  if (txt && !LETTER.test(txt)) {
    // a symbol-only choice in a labelled row (Text → Align ⯇ ≡ ⯈): the row's name and the symbol
    const row = e.closest('.row, label'); const rl = row?.querySelector('.lbl');
    if (rl) return clean(rl.textContent) + ' ' + (SYMBOL[txt] || txt);
    if (SYMBOL[txt]) return SYMBOL[txt];
  }
  return txt.length > 70 ? txt.slice(0, 70) : txt;
}
/** symbol-only buttons the studio uses, in words */
const SYMBOL = { '▶': 'Listen', '+': 'Add', '＋': 'Add', '⯇': 'Align left', '⟸': 'Align left', '⇤': 'Align left', '≡': 'Align centre', '⯈': 'Align right', '⟹': 'Align right', '⇥': 'Align right' };
/** the words of the row a bare tick sits in (a model's name next to its tick) */
function rowText(e) {
  let p = e.parentElement;
  for (let i = 0; p && i < 3; i++, p = p.parentElement) {
    const t = clean([...p.querySelectorAll('b, strong, span, label, div')].filter((x) => !x.contains(e) && x.children.length === 0).map((x) => x.textContent).join(' '));
    if (LETTER.test(t)) return t.slice(0, 60);
  }
  return '';
}
function firstLine(sum) {
  // a summary's own words, not a status chip it carries
  const t = [...sum.childNodes].map((n) => (n.nodeType === 3 ? n.textContent : n.matches?.('.muted, small, .chip, .badge') ? '' : n.textContent)).join(' ');
  return clean(t) || clean(sum.textContent);
}

const vis = (e) => {
  if (e.checkVisibility && !e.checkVisibility({ opacityProperty: true, visibilityProperty: true })) return false;
  for (let p = e.parentElement; p; p = p.parentElement) if (p.tagName === 'DETAILS' && !p.open) {
    const s = [...p.children].find((c) => c.tagName === 'SUMMARY'); if (!s || !s.contains(e)) return false;
  }
  const r = e.getBoundingClientRect(); return r.width > 1 && r.height > 1;
};

/** which screen is on top, and its root element */
export function currentScreen(doc = document) {
  const q = (s) => doc.querySelector(s);
  const modal = [...doc.querySelectorAll('.modal-back')].pop();
  if (modal) return { screen: 'modal', name: clean(modal.querySelector('.modal-head h2')?.textContent) || 'Dialog', root: modal, under: currentScreen.under(doc) };
  if (q('.cfg-back')) return { screen: 'configure', name: 'Configure', root: q('.cfg-back') };
  if (q('.de')) return { screen: 'design-editor', name: 'Design editor', root: q('.de') };
  if (q('.dz')) return { screen: 'designs', name: 'Designs', root: q('.dz') };
  if (q('.editor')) return { screen: 'editor', name: 'Editor', root: q('.editor') };
  if (q('.setup-screen')) return { screen: 'setup', name: 'Setup', root: q('.setup-screen') };
  if (q('.home')) return { screen: 'home', name: 'Home', root: q('.home') };
  return { screen: 'other', name: '', root: doc.body };
}
currentScreen.under = (doc) => (doc.querySelector('.cfg-back') ? 'configure' : doc.querySelector('.editor') ? 'editor' : doc.querySelector('.dz') ? 'designs' : doc.querySelector('.home') ? 'home' : 'other');

/** the editor's regions, by the class of the column the control sits in */
const REGIONS = [['.topbar', 'Top bar'], ['.doit-row', 'Do it for me'], ['aside.bin', 'Left panel'], ['.inspector.side', 'Right panel'], ['.monitor', 'Preview'], ['section.timeline', 'Timeline'],
  ['.cfg-nav', 'Configure'], ['.cfg-top', 'Configure'], ['.cfg-main', 'Configure'], ['.home-top', 'Top bar'], ['.home-hero', 'Home'], ['.home-grid', 'Home'], ['.dz', 'Designs'], ['.de', 'Design editor']];

/** where a control lives: { path: ['Right panel', 'Inspector', 'Speed'], ctx: {side, bin, sub, sec}, scope } */
export function placeOf(e, scr = currentScreen()) {
  const path = [], ctx = { screen: scr.screen === 'modal' ? scr.under : scr.screen };
  if (scr.screen === 'modal') { ctx.modal = scr.name; path.push(scr.name); }
  else {
    const reg = REGIONS.find(([s]) => e.closest(s));
    const side = e.closest('.side-body') && document.querySelector('.side-tab.on');
    const bin = e.closest('aside.bin') && !e.closest('.bin-tabs') && document.querySelector('.bin-tab.on');
    const cfg = e.closest('.cfg-main') && document.querySelector('.cfg-nav button.on');
    const dz = e.closest('.dz') && !e.closest('.dz-tabs') && e.closest('.dz-col, .dz-left') && e.closest('.dz-col, .dz-left').querySelector('.dz-tabs [data-t].on');
    if (side) {
      ctx.side = side.dataset.side; path.push(splitIcon(side.textContent).text);
      if (ctx.side === 'inspector') { const ih = clean(document.querySelector('.insp-body .insp-head b')?.textContent); if (ih) ctx.insp = ih; }
    }
    else if (bin) { ctx.bin = splitIcon(bin.textContent).text; path.push(ctx.bin); }
    else if (cfg) { ctx.cfg = cfg.dataset.sec; path.push('Configure', splitIcon(cfg.textContent).text); }
    else if (dz) { ctx.dz = dz.dataset.t; path.push('Designs', splitIcon(dz.textContent).text); }
    else if (reg) path.push(reg[1]);
    // a panel's own sub-tabs (Captions: Transcript / Script / Style / Translate …)
    const subBar = e.closest('.side-body, aside.bin')?.querySelector('.rs-cap-tabs, [role=tablist]:not(.side-tabs):not(.bin-tabs)');
    if (subBar && !subBar.contains(e)) { const on = subBar.querySelector('.on, [aria-selected=true]'); if (on && on !== e) { ctx.sub = { bar: barSel(subBar), label: labelOf(on) }; path.push(splitIcon(labelOf(on)).text); } }
  }
  // folded sections it sits in, outermost first
  const secs = [];
  for (let p = e.parentElement; p && p !== scr.root; p = p.parentElement) {
    if (p.tagName === 'DETAILS') { const s = [...p.children].find((c) => c.tagName === 'SUMMARY'); if (s && s !== e && !s.contains(e)) secs.unshift(splitIcon(firstLine(s)).text); }
  }
  if (secs.length) { ctx.sec = secs; path.push(...secs.slice(-2)); }
  else {
    // an unfolded block's own heading
    const hd = e.closest('section, fieldset, .card, .cfg-card, .cfg-sec, .mod-sec, .lk-sec, .vr-sec')?.querySelector(':scope > h2, :scope > h3, :scope > h4, :scope > legend, :scope > .tile-head h2, :scope > header h3');
    if (hd && !hd.contains(e) && LETTER.test(hd.textContent)) path.push(splitIcon(clean(hd.textContent)).text.slice(0, 40));
  }
  return { path: path.filter((x, i, a) => x && a.indexOf(x) === i), ctx };
}
function barSel(bar) {
  if (bar.classList.contains('rs-cap-tabs')) return '.rs-cap-tabs';
  return bar.id ? '#' + bar.id : '[role=tablist]';
}

const kindOf = (e) => (e.tagName === 'SUMMARY' ? 'section' : e.matches('[role=tab], .side-tab, .bin-tab, .cfg-nav button, .dz-tabs [data-t], .rs-cap-tabs button') ? 'tab'
  : e.type === 'range' ? 'slider' : e.type === 'checkbox' ? 'switch' : e.tagName === 'SELECT' ? 'choice' : /INPUT|TEXTAREA/.test(e.tagName) ? 'field' : 'button');

/** the title text a control carries (or carried: editor/hover-help.js moves it aside so no native tooltip doubles) */
export const tipText = (e) => e.getAttribute('title') || e.getAttribute('data-kbs-title') || '';

/** one control → its record, or null when it has no words (out.unlabelled in crawl()) */
export function recordOf(e, scr = currentScreen()) {
  const raw = labelOf(e);
  const { icon, text } = splitIcon(raw);
  if (!text || !LETTER.test(text)) return null;
  const { path, ctx } = placeOf(e, scr);
  const tip = clean(tipText(e).split('\n').filter((l) => !/^Hover to preview/.test(l)).join(' · '));
  const rec = { label: text, icon, title: tip, kind: kindOf(e), path, ctx, tag: e.tagName.toLowerCase() };
  if (GENERIC.test(text)) { const rn = rowName(e); if (rn) rec.sub = rn; }
  rec.key = keyOf(rec);
  return rec;
}

/** is this a control the index covers (visible, outside the preview/timeline clips and the search itself)? */
export const indexable = (e) => e.matches(CONTROL_SEL) && !e.closest(SKIP) && !e.closest('[data-kbs]') && vis(e);

/** one crawl of what is on screen now: [{ label, icon, title, kind, path, ctx, key, el }] */
export function crawl(root) {
  const scr = currentScreen();
  root = root || scr.root;
  const out = [], seen = new Set(); out.unlabelled = [];
  for (const e of root.querySelectorAll(CONTROL_SEL)) {
    if (e.closest(SKIP) || e.closest('[data-kbs]') || !vis(e)) continue;
    const rec = recordOf(e, scr);
    if (!rec) { out.unlabelled.push(e); continue; }
    if (seen.has(rec.key)) continue; seen.add(rec.key);
    rec.el = e;
    out.push(rec);
  }
  return out;
}
/** a stable identity for a control: where it lives and what it says */
/** buttons whose words only make sense with the row they sit in ("Save", "Test", "⬇ Download 661.7 MB") */
const GENERIC = /^(save|test|remove|delete|reset|use|add|listen|play|open|show|edit|download\b.*|open in finder|choose|change|apply|clear|retry|pause|resume|projects|exports|designs|library|show or hide.*)$/i;
function rowName(e) {
  let p = e.parentElement;
  for (let i = 0; p && i < 5; i++, p = p.parentElement) {
    const hd = [...p.querySelectorAll('b, strong, h3, h4, .cfg-name')].find((x) => !x.contains(e) && !e.contains(x) && LETTER.test(x.textContent) && (x.compareDocumentPosition(e) & Node.DOCUMENT_POSITION_FOLLOWING));
    if (hd) return clean(hd.textContent).slice(0, 50);
  }
  return '';
}
const keyLabel = (s) => s.toLowerCase().replace(/\d+(\.\d+)?\s?(kb|mb|gb)\b/g, '').replace(/\s+/g, ' ').trim();
export const keyOf = (r) => `${r.ctx.screen}|${r.ctx.modal || ''}|${r.ctx.insp || ''}|${r.ctx.side || r.ctx.bin || r.ctx.cfg || r.ctx.dz || ''}|${r.ctx.sub?.label || ''}|${(r.ctx.sec || []).join('>')}|${keyLabel(r.label)}|${(r.sub || '').toLowerCase()}`;
