// Caption editor panel. The editor mounts it with mountCaptionPanel(el, ctx) (ctx = EditorContext,
// core/modules.js). It never mutates the project: every edit is a command through ctx.history.
//
// Header: Auto captions (model, language), "Music & sound tags" (Hide / Show ♪ icon / Keep as text) and the
// company's vocabulary (words Whisper should spell right).
// Transcript tab: click a word -> playhead jumps there; double-click -> edit in place (Enter keeps,
// Esc cancels, empty deletes); split / merge / delete / add / nudge; find & replace; shift all;
// fillers and low-confidence words are highlighted; emoji suggestions per line; per-word and per-line style
// overrides ("Word style" / "Line style", shown with a dot) and "Reset to style"; words the cleaner removed.
// Script tab: script-view.js. Style tab: the template gallery (gallery.js) + every field of the current style.

import { resolveStyle, diffStyle, styleForWorkspace, ANIMS, BG_TYPES, CASES } from './styles.js';
import { FONT_FAMILIES, ARABIC_FONTS, loadCaptionFonts } from './fonts.js';
import { isFiller, isRtlText, lineText } from './model.js';
import { suggestEmoji } from './emoji.js';
import * as C from './commands.js';
import { LANGS } from './stt.js';
import { SPEECH_MODELS } from './models.js';
import { autoCaptions, captionScope, uncaptionedClips, captionDiagnostics, diagnosticsText } from './auto.js';
import { APP_VERSION } from '../core/config.js';
import { TAG_MODES, DEFAULT_TAG_MODE, TAG_ICON } from './nonspeech.js';
import { vocabFor } from './vocab.js';
import { alignEdit } from './script.js';
import { mountGallery } from './gallery.js';
import { mountScript } from './script-view.js';
import { dragPrefs, posSel, selectLines, lineKey, linePos, resetLines, resetAll } from './drag.js';
import { wsDir } from '../core/config.js';
import { mountTranslate } from './translate/panel.js';
import { lineFlags } from './translate/pipeline.js';
import { mountLangNotice } from './hinglish/notice.js';
import { SCRIPTS, setScript, isHindustani, currentScript } from './hinglish/script.js';

const h = (tag, attrs = {}, ...kids) => {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (v === true) el.setAttribute(k, '');
    else if (v !== false && v != null) el.setAttribute(k, v);
  }
  for (const c of kids.flat()) if (c != null && c !== false) el.append(c.nodeType ? c : document.createTextNode(String(c)));
  return el;
};
const fmt = (t) => { const m = Math.floor(t / 60), s = t - m * 60; return `${m}:${s.toFixed(1).padStart(4, '0')}`; };

export function mountCaptionPanel(el, ctx) {
  const P = () => ctx.project;
  const cap = () => P().captions || { words: [], lines: [] };
  const apply = (cmd) => { ctx.history.apply(cmd); return cmd; };
  let sel = -1;                     // selected word index
  let tab = 'transcript';
  let ovMode = null;                // null | 'word' | 'line': the override editor under the selection bar
  let running = null;               // AbortController while transcribing
  const offs = [];

  el.classList.add('rs-cap');
  el.innerHTML = '';
  el.addEventListener('keydown', (e) => e.stopPropagation()); // typing here never drives the timeline (J/K/L, space)

  // ---------------------------------------------------------------- header / auto captions
  const modelSel = h('select', { class: 'rs-cap-in', title: 'Speech model' },
    Object.values(SPEECH_MODELS).map((m) => h('option', { value: m.key }, m.label)));
  const langSel = h('select', { class: 'rs-cap-in', title: 'Language' }, LANGS.map((l) => h('option', { value: l.id }, l.label)));
  const meta = P().meta?.captions || {};
  // Hindi / Urdu captions in: auto (Roman for Hinglish, else as heard) or one script, for this project
  const scriptSel = h('select', { class: 'rs-cap-in', title: 'Script for Hindi / Urdu captions', 'data-k': 'capScript' },
    h('option', { value: 'auto' }, 'Auto (Roman for Hinglish)'), SCRIPTS.map((s) => h('option', { value: s.id }, s.title)));
  modelSel.value = meta.model || 'small'; langSel.value = meta.lang || 'auto'; scriptSel.value = meta.script || 'auto';
  const bar = h('div', { class: 'rs-cap-bar' }, h('i'));
  const barLabel = h('span', { class: 'rs-cap-barlabel' });
  const cancelBtn = h('button', { class: 'rs-cap-btn ghost', onclick: () => running?.abort() }, 'Cancel');
  const progress = h('div', { class: 'rs-cap-progress', hidden: true }, barLabel, bar, cancelBtn);
  const goBtn = h('button', { class: 'rs-cap-btn primary', onclick: () => runAuto() }, '💬 Auto captions');
  // a run that failed or ended with no words says why IN PLACE (not only in a toast that is gone in seconds), with
  // Try again and Copy diagnostics (the owner, 20 Nov: "I run multiple times auto captions, but nothing generating")
  let lastRun = null;
  const failMsg = h('span', { class: 'rs-cap-failmsg', role: 'alert' });
  const copyDiag = async () => {
    const text = diagnosticsText(captionDiagnostics(), { version: APP_VERSION });
    let done = false;
    try { await navigator.clipboard.writeText(text); done = true; } catch { /* below */ }
    if (!done) { const ta = h('textarea', { style: 'position:fixed;left:-9999px' }); ta.value = text; document.body.append(ta); ta.select(); try { done = document.execCommand('copy'); } catch {} ta.remove(); }
    ctx.toast?.(done ? 'Diagnostics copied — paste them in your message' : 'Could not copy the diagnostics', done ? 'ok' : 'warn');
  };
  const failBox = h('div', { class: 'rs-cap-fail', hidden: true, 'data-cap-fail': '' }, failMsg,
    h('div', { class: 'rs-cap-row' },
      h('button', { class: 'rs-cap-btn', 'data-x': 'cap-retry', onclick: () => { const a = lastRun || []; runAuto(...a); } }, '↻ Try again'),
      h('button', { class: 'rs-cap-btn ghost', 'data-x': 'cap-diag', onclick: copyDiag }, '📋 Copy diagnostics')));
  // Whole video / Selected clips: follows the timeline selection (a clip selected -> Selected clips), he can change it
  const scopeSel = h('select', { class: 'rs-cap-in', 'data-k': 'capScope', 'aria-label': 'Caption what', title: 'Caption the whole video, or only the clips selected on the timeline' },
    h('option', { value: 'all' }, 'Whole video'), h('option', { value: 'selected' }, 'Selected clips'));
  const newBtn = h('button', { class: 'rs-cap-btn', 'data-x': 'caption-new-clips', onclick: () => runAuto(uncaptionedClips(P()).map((c) => c.it.id)) }, 'Caption the new clips');
  const newRow = h('div', { class: 'rs-cap-row rs-cap-new', hidden: true }, h('span', { class: 'rs-cap-muted' }), newBtn);
  function renderScope(follow) {
    const sc = captionScope(ctx);
    if (follow) scopeSel.value = sc.scope;
    const list = scopeSel.value === 'selected' ? sc.selected : sc.all, names = list.map((c) => c.name).join(', ');
    goBtn.title = scopeSel.value === 'selected'
      ? (list.length ? `Captions for ${list.length} selected clip${list.length === 1 ? '' : 's'} (${names}); the rest stay as they are` : 'Select a clip on the timeline first')
      : `Captions for the whole video (${list.length} clip${list.length === 1 ? '' : 's'})`;
    const fresh = uncaptionedClips(P());
    newRow.hidden = !fresh.length;
    newRow.firstChild.textContent = fresh.length ? `No captions yet: ${fresh.map((c) => c.name).join(', ')}` : '';
  }

  async function runAuto(clips, scope) {
    if (running) return;
    running = new AbortController();
    lastRun = [clips, scope];
    progress.hidden = false; failBox.hidden = true; goBtn.disabled = true; newBtn.disabled = true;
    const set = (label, f) => { barLabel.textContent = label || ''; bar.firstChild.style.width = f == null ? '0' : Math.round(f * 100) + '%'; };
    let fail = null;
    try {
      await autoCaptions(ctx, { model: modelSel.value, lang: langSel.value, signal: running.signal, onProgress: set, ...(clips ? { clips } : { scope: scope || scopeSel.value }) });
      fail = captionDiagnostics()?.empty || null;
    } catch (e) {
      if (e.name !== 'AbortError') { fail = 'Captions failed: ' + e.message; ctx.toast?.(fail, 'error'); }
    } finally { running = null; progress.hidden = true; goBtn.disabled = false; newBtn.disabled = false; }
    failMsg.textContent = fail || ''; failBox.hidden = !fail;
  }

  // Music & sound tags (per project, undoable)
  const tagSel = h('select', { class: 'rs-cap-in', title: 'What captions do with [Music], ♪, (applause)…', 'data-k': 'soundTags' },
    TAG_MODES.map((m) => h('option', { value: m.id }, m.label)));
  tagSel.onchange = () => apply(C.setSoundTags(tagSel.value));
  // Vocabulary: the company's words (workspace.captions.vocab), saved into workspace.json
  const vocabTa = h('textarea', { class: 'rs-cap-in rs-cap-vocab', rows: 3, placeholder: 'One per line or comma-separated: brand, product, country, visa words…' });
  const vocabCount = h('span', { class: 'rs-cap-muted' });
  const vocabBox = h('details', { class: 'rs-cap-vocabbox' },
    h('summary', {}, 'Words to spell right ', vocabCount),
    vocabTa,
    h('div', { class: 'rs-cap-row' }, h('button', { class: 'rs-cap-btn', onclick: saveVocab }, 'Save for this company'),
      h('span', { class: 'rs-cap-muted' }, 'Whisper is told these words before it listens, and near-misses are corrected after.')));
  function renderVocab() {
    const ws = ctx.workspace;
    vocabTa.value = Array.isArray(ws?.captions?.vocab) ? ws.captions.vocab.join('\n') : (ws?.captions?.vocab || '');
    vocabCount.textContent = `(${vocabFor(ws).length})`;
  }
  async function saveVocab() {
    const ws = ctx.workspace; if (!ws) { ctx.toast?.('Pick a company first', 'warn'); return; }
    ws.captions = { ...(ws.captions || {}), vocab: vocabTa.value.split(/[\n,]/).map((s) => s.trim()).filter(Boolean) };
    ws.modified = new Date().toISOString();
    try { if (ctx.engine && (ctx.engine.available ?? true) && ws.id) await ctx.engine.writeFile(`${wsDir(ws.id)}/workspace.json`, ws); ctx.toast?.('Vocabulary saved', 'ok'); }
    catch (e) { ctx.toast?.('Could not save: ' + e.message, 'warn'); }
    renderVocab();
  }

  const tabs = h('div', { class: 'rs-cap-tabs', role: 'tablist' },
    h('button', { role: 'tab', 'data-tab': 'transcript', onclick: () => show('transcript') }, 'Transcript'),
    h('button', { role: 'tab', 'data-tab': 'script', onclick: () => show('script') }, 'Script'),
    h('button', { role: 'tab', 'data-tab': 'style', onclick: () => show('style') }, 'Style'),
    h('button', { role: 'tab', 'data-tab': 'translate', onclick: () => show('translate') }, 'Translate'));

  // ---------------------------------------------------------------- transcript tab
  const findIn = h('input', { class: 'rs-cap-in', placeholder: 'Find', size: 8 });
  const replIn = h('input', { class: 'rs-cap-in', placeholder: 'Replace with', size: 8 });
  const replBtn = h('button', { class: 'rs-cap-btn', onclick: () => {
    const cmd = C.findReplace(findIn.value, replIn.value);
    apply(cmd);
    ctx.toast?.(cmd.count ? `Replaced ${cmd.count}` : 'Nothing found', cmd.count ? 'ok' : 'warn');
  } }, 'Replace all');
  const shiftIn = h('input', { class: 'rs-cap-in', type: 'number', step: '0.1', value: '0.1', style: 'width:4.5em', title: 'seconds' });
  const tools = h('div', { class: 'rs-cap-tools' },
    h('div', { class: 'rs-cap-row' }, findIn, replIn, replBtn),
    h('div', { class: 'rs-cap-row' }, h('span', { class: 'rs-cap-muted' }, 'Shift all'),
      h('button', { class: 'rs-cap-btn', title: 'Earlier', onclick: () => apply(C.shiftAll(-Math.abs(+shiftIn.value || 0))) }, '◀'),
      shiftIn,
      h('button', { class: 'rs-cap-btn', title: 'Later', onclick: () => apply(C.shiftAll(Math.abs(+shiftIn.value || 0))) }, '▶'),
      h('span', { class: 'rs-cap-legend' }, h('b', { class: 'filler' }, 'filler'), ' ', h('b', { class: 'lowconf' }, 'check'), ' ', h('b', { class: 'ovdot' }, 'styled'))));

  const selBar = h('div', { class: 'rs-cap-sel', hidden: true });
  const ovBox = h('div', { class: 'rs-cap-ov', hidden: true });
  const droppedBox = h('details', { class: 'rs-cap-dropped', hidden: true });
  const list = h('div', { class: 'rs-cap-list', role: 'list' });
  const transcriptPane = h('div', { class: 'rs-cap-pane', 'data-pane': 'transcript' }, tools, selBar, ovBox, list, droppedBox);

  function lineOfWord(i) { return cap().lines.find((L) => L.wordIdx[0] <= i && i <= L.wordIdx[1]); }
  const lineHasOv = (c, L) => !!L.ov || c.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).some((w) => w.ov);

  // The bar keeps its place and size while words exist, selected or not: if it appeared above the transcript on the
  // first click of a double-click, the words would move down and the second click would land on the bar instead.
  function renderSelBar() {
    const c = cap(), w0 = c.words[sel];
    selBar.hidden = !c.words.length;
    if (!c.words.length) { ovBox.hidden = true; return; }
    const w = w0 || { w: '', t0: 0, t1: 0 };
    const L = w0 ? lineOfWord(sel) : null;
    selBar.classList.toggle('idle', !w0);
    selBar.replaceChildren(
      h('div', { class: 'rs-cap-selhead' }, w0
        ? [h('span', { class: 'rs-cap-selword', dir: 'auto' }, `“${w.w}”`), h('span', { class: 'rs-cap-muted' }, ` ${w.t0.toFixed(2)}–${w.t1.toFixed(2)} s`)]
        : h('span', { class: 'rs-cap-muted' }, 'Click a word to jump there · double-click to correct it')),
      h('div', { class: 'rs-cap-row' },
        h('button', { class: 'rs-cap-btn', title: 'Word 0.05 s earlier', disabled: !w0, onclick: () => apply(C.nudgeWord(sel, -0.05)) }, '−0.05'),
        h('button', { class: 'rs-cap-btn', title: 'Word 0.05 s later', disabled: !w0, onclick: () => apply(C.nudgeWord(sel, 0.05)) }, '+0.05'),
        h('button', { class: 'rs-cap-btn', title: 'Start a new line at this word', disabled: !L || L.wordIdx[0] === sel, onclick: () => apply(C.splitLine(sel)) }, 'Split here'),
        h('button', { class: 'rs-cap-btn', title: 'Join this line with the next', disabled: !L || L === c.lines[c.lines.length - 1], onclick: () => apply(C.mergeWithNext(L.id)) }, 'Merge next'),
        h('button', { class: 'rs-cap-btn', title: 'Add a word after this one', disabled: !w0, onclick: () => addAfter(sel) }, '+ Word'),
        h('button', { class: 'rs-cap-btn danger', title: 'Delete word', disabled: !w0, onclick: () => { apply(C.deleteWords(sel)); sel = Math.min(sel, cap().words.length - 1); } }, 'Delete')),
      h('div', { class: 'rs-cap-row' }, h('span', { class: 'rs-cap-muted' }, 'Line'),
        h('button', { class: 'rs-cap-btn', disabled: !L, onclick: () => apply(C.nudgeLine(L.id, -0.1)) }, '−0.1 s'),
        h('button', { class: 'rs-cap-btn', disabled: !L, onclick: () => apply(C.nudgeLine(L.id, 0.1)) }, '+0.1 s'),
        h('button', { class: 'rs-cap-btn' + (ovMode === 'word' ? ' on' : ''), 'data-ov': 'word', disabled: !w0, onclick: () => { ovMode = ovMode === 'word' ? null : 'word'; renderSelBar(); } }, '🎨 Word style'),
        h('button', { class: 'rs-cap-btn' + (ovMode === 'line' ? ' on' : ''), 'data-ov': 'line', disabled: !L, onclick: () => { ovMode = ovMode === 'line' ? null : 'line'; renderSelBar(); } }, '🎨 Line style')));
    renderOv(w0, L);
  }

  // ---- per-word / per-line overrides (on top of the global style)
  function renderOv(w0, L) {
    if (!ovMode || (ovMode === 'word' && !w0) || (ovMode === 'line' && !L)) { ovBox.hidden = true; ovBox.replaceChildren(); return; }
    ovBox.hidden = false;
    const st = resolveStyle(cap().style);
    const isWord = ovMode === 'word';
    const ov = (isWord ? w0.ov : L.ov) || {};
    const id = isWord ? 'w' + sel : L.id;
    const set = (patch, field) => apply(isWord ? C.setWordOverride(sel, patch, 'captions.ov:' + id + ':' + field) : C.setLineOverride(L.id, patch, 'captions.ov:' + id + ':' + field));
    const num = (v, d) => (v == null ? d : v);
    const range = (min, max, step, v, on) => { const i = h('input', { type: 'range', min, max, step, value: v }); i.oninput = () => on(+i.value); return i; };
    const color = (v, on) => { const i = h('input', { type: 'color', value: toHex(v) }); i.oninput = () => on(i.value); return i; };
    const select = (opts, v, on) => { const s = h('select', { class: 'rs-cap-in' }, opts.map((o) => h('option', { value: o[0] }, o[1]))); s.value = v; s.onchange = () => on(s.value); return s; };
    const textIn = h('input', { class: 'rs-cap-in', dir: 'auto', 'data-f': 'text', value: isWord ? w0.w : lineText(cap(), L) });
    textIn.onchange = () => {
      const v = textIn.value.replace(/\s+/g, ' ').trim();
      if (isWord) apply(C.editWord(sel, v));
      else { const c = cap(); apply(C.replaceSpan(L.wordIdx[0], L.wordIdx[1], alignEdit(c.words.slice(L.wordIdx[0], L.wordIdx[1] + 1), v), 'Edit line')); }
    };
    const fonts = [['', 'Style font'], ...FONT_FAMILIES.map((f) => [f, f])];
    const rows = [
      field('Text', textIn),
      field('Size', h('span', { class: 'rs-cap-pair' }, range(20, 220, 1, num(ov.size, st.size), (v) => set({ size: v }, 'size')),
        h('button', { class: 'rs-cap-btn ghost', title: 'Back to the style size', onclick: () => set({ size: null }, 'size0') }, '↺'))),
      field('Colour', h('span', { class: 'rs-cap-pair' }, color(ov.color || st.color, (v) => set({ color: v }, 'color')),
        h('button', { class: 'rs-cap-btn ghost', onclick: () => set({ color: null }, 'color0') }, '↺'))),
      field('Font', select(fonts, ov.font || '', (v) => set({ font: v || null }, 'font'))),
      field('Weight', select([['', 'Style'], ...[300, 400, 500, 600, 700, 800, 900].map((x) => [String(x), String(x)])], ov.weight ? String(ov.weight) : '', (v) => set({ weight: v ? +v : null }, 'weight'))),
    ];
    if (isWord) {
      const hl = h('input', { type: 'checkbox', checked: !!ov.hl, 'data-f': 'hl' });
      const hlc = color(ov.hl || st.accent || '#ffd400', (v) => set({ hl: v }, 'hl'));
      hl.onchange = () => set({ hl: hl.checked ? hlc.value : null }, 'hlon');
      const emo = h('input', { class: 'rs-cap-in', 'data-f': 'emoji', value: ov.emoji || '', size: 4, placeholder: '✨' });
      emo.onchange = () => set({ emoji: emo.value.trim().slice(0, 8) || null }, 'emoji');
      const sugg = suggestEmoji([w0.w], 4);
      rows.push(field('Highlight', h('span', { class: 'rs-cap-pair' }, hl, hlc)),
        field('Emoji', h('span', { class: 'rs-cap-pair' }, emo, sugg.map((e) => h('button', { class: 'rs-cap-chip', onclick: () => set({ emoji: e }, 'emoji') }, e)))));
    } else {
      rows.push(
        field('Spoken word', color(ov.active || st.active || st.color, (v) => set({ active: v, ...(st.activeBg ? { activeBg: v } : {}) }, 'active'))),
        field('Animation', select([['', 'Style'], ...ANIMS.map((a) => [a, a])], ov.anim || '', (v) => set({ anim: v || null }, 'anim'))),
        field('Position', h('span', { class: 'rs-cap-pair' },
          range(0, 1, 0.01, num(ov.pos?.x, st.pos.x ?? 0.5), (v) => set({ pos: { ...(L.ov?.pos || { y: st.pos.y }), x: v } }, 'posx')),
          range(0, 1, 0.01, num(ov.pos?.y, st.pos.y), (v) => set({ pos: { x: st.pos.x ?? 0.5, ...(L.ov?.pos || {}), y: v } }, 'posy')))),
        posFields(L, set),
        h('div', { class: 'rs-cap-muted' }, 'Tip: drag the caption on the video to move this line (Shift-drag moves all lines · ⌘/Shift-click lines here to move several · arrows nudge).'));
    }
    ovBox.replaceChildren(
      h('div', { class: 'rs-cap-formhead' }, h('b', {}, isWord ? `Word “${w0.w}”` : 'This line'),
        h('button', { class: 'rs-cap-btn ghost', 'data-x': 'reset', title: 'Remove this line’s own style (and its words’)', onclick: () => apply(isWord ? C.setWordOverride(sel, { color: null, size: null, font: null, weight: null, hl: null, emoji: null }) : C.resetOverrides(L.id)) }, 'Reset to style')),
      ...rows);
  }
  // the exact X / Y the canvas drag writes (percent of the safe area), and "Reset position"
  function posFields(L, set) {
    const p = linePos(cap(), L);
    const numIn = (f, v) => { const i = h('input', { class: 'rs-cap-in rs-cap-posval', type: 'number', min: 0, max: 100, step: 0.01, 'data-f': f, value: (v * 100).toFixed(2) });
      i.onchange = () => set({ pos: { ...linePos(cap(), L), [f === 'posx' ? 'x' : 'y']: Math.max(0, Math.min(1, +(+i.value / 100).toFixed(4))) } }, f); return i; };
    return field('X / Y %', h('span', { class: 'rs-cap-pair' }, numIn('posx', p.x), numIn('posy', p.y),
      h('button', { class: 'rs-cap-btn ghost', 'data-x': 'reset-pos', title: 'Back to the style’s position', disabled: !L.ov?.pos, onclick: () => apply(resetLines([lineKey('', L.id)])) }, '↺')));
  }
  function field(label, input) { return h('label', { class: 'rs-cap-field' }, h('span', {}, label), input); }

  function addAfter(i) {
    apply(C.insertWords(i, '…'));
    sel = i + 1; renderList();
    edit(sel);
  }

  function renderDropped() {
    const d = cap().dropped || [];
    droppedBox.hidden = !d.length;
    if (!d.length) return;
    const why = { outro: 'outro in silence/music', loop: 'repeated', silent: 'in silence', prompt: 'prompt echo' };
    droppedBox.replaceChildren(h('summary', {}, `Removed by the cleaner (${d.length})`),
      ...d.map((x, k) => h('div', { class: 'rs-cap-row' }, h('span', { class: 'rs-cap-muted' }, fmt(x.t0)), h('span', { dir: 'auto' }, x.w),
        h('span', { class: 'rs-cap-muted' }, `· ${why[x.why] || x.why}`), h('button', { class: 'rs-cap-btn ghost', onclick: () => apply(C.restoreDropped(k)) }, 'Put back'))));
  }

  function renderList() {
    const c = cap();
    tagSel.value = c.soundTags || DEFAULT_TAG_MODE;
    tagChip.textContent = '· ♪ ' + (TAG_MODES.find((m) => m.id === tagSel.value)?.label || '');
    list.replaceChildren();
    if (!c.words.length) {
      list.append(h('div', { class: 'rs-cap-empty' }, 'No captions yet. Press ', h('b', {}, 'Auto captions'), ' — or add a first word: ',
        h('button', { class: 'rs-cap-btn', onclick: () => { apply(C.insertWords(-1, '…')); sel = 0; renderList(); edit(0); } }, '+ Word')));
      renderSelBar(); renderDropped(); return;
    }
    const frag = document.createDocumentFragment();
    const trFlags = c.tr?.mode === 'replace' && c.tr.active && c.tr.langs?.[c.tr.active] ? lineFlags(c.tr.langs[c.tr.active].sents) : {};
    for (const L of c.lines) {
      const words = [];
      for (let i = L.wordIdx[0]; i <= L.wordIdx[1]; i++) {
        const w = c.words[i];
        words.push(h('span', { class: 'rs-cap-w' + (isFiller(w.w) ? ' filler' : '') + ((w.conf ?? 1) < 0.6 ? ' lowconf' : '') + (i === sel ? ' sel' : '') + (w.ov ? ' ov' : '') + (w.tag ? ' tag' : ''),
          'data-i': i, title: `${w.t0.toFixed(2)}–${w.t1.toFixed(2)} s${w.tag ? ' · sound tag (' + (c.soundTags || DEFAULT_TAG_MODE) + ')' : ''}` }, w.tag && (c.soundTags || DEFAULT_TAG_MODE) === 'icon' ? (TAG_ICON[w.tag] || '♪') + ' ' + w.w : w.w), ' ');
      }
      const words2 = c.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => w.w);
      const emo = suggestEmoji(words2, 3);
      const styled = lineHasOv(c, L);
      const row = h('div', { class: 'rs-cap-line' + (styled ? ' has-ov' : '') + (trFlags[L.id] ? ' tr-flag' : '') + (posSel.has(lineKey('', L.id)) ? ' pos-sel' : ''), role: 'listitem', 'data-line': L.id, title: trFlags[L.id] ? 'Translation: ' + trFlags[L.id].map((f) => f.msg).join(' · ') : null },
        h('button', { class: 'rs-cap-time', title: styled ? 'Go to this line · this line has its own style' : 'Go to this line', onclick: () => ctx.seek?.(L.t0) }, fmt(L.t0), styled ? h('i', { class: 'rs-cap-dot', 'aria-label': 'styled' }) : null),
        h('div', { class: 'rs-cap-text', dir: isRtlText(lineText(c, L)) ? 'rtl' : 'ltr' }, words),
        h('div', { class: 'rs-cap-emoji' },
          L.emoji ? h('button', { class: 'rs-cap-chip on', title: 'Remove emoji', onclick: () => apply(C.setLineEmoji(L.id, null)) }, L.emoji, ' ×') : null,
          emo.filter((e) => e !== L.emoji).map((e) => h('button', { class: 'rs-cap-chip', title: 'Add emoji to this line', onclick: () => apply(C.setLineEmoji(L.id, e)) }, e)),
          styled ? h('button', { class: 'rs-cap-chip', 'data-x': 'reset-line', title: 'Reset this line to the style', onclick: () => apply(C.resetOverrides(L.id)) }, '↺ style') : null));
      frag.append(row);
    }
    list.append(frag);
    renderSelBar();
    renderDropped();
    markNow(ctx.playhead?.() ?? 0);
  }

  // ⌘/Ctrl-click a line: add it to (or take it out of) the lines a canvas drag moves; Shift-click: the range up to it
  let anchor = null;
  list.addEventListener('click', (e) => {
    const row = e.target.closest('.rs-cap-line');
    if (row && (e.metaKey || e.ctrlKey || e.shiftKey) && !e.target.isContentEditable) {
      e.preventDefault();
      const ids = cap().lines.map((L) => L.id), b = ids.indexOf(row.dataset.line), a = anchor ? ids.indexOf(anchor) : -1;
      if (e.shiftKey && a >= 0) selectLines(ids.slice(Math.min(a, b), Math.max(a, b) + 1).map((id) => lineKey('', id)), 'add');
      else selectLines([lineKey('', row.dataset.line)], 'toggle');
      anchor = row.dataset.line;
      return;
    }
    if (row) { anchor = row.dataset.line; selectLines([lineKey('', row.dataset.line)]); }
    const s = e.target.closest('.rs-cap-w'); if (!s || s.isContentEditable) return;
    const i = +s.dataset.i;
    list.querySelector('.rs-cap-w.sel')?.classList.remove('sel');
    s.classList.add('sel'); sel = i; renderSelBar();
    ctx.seek?.(cap().words[i].t0);
  });
  list.addEventListener('dblclick', (e) => { const s = e.target.closest('.rs-cap-w'); if (s) edit(+s.dataset.i); });

  function edit(i) {
    const s = list.querySelector(`.rs-cap-w[data-i="${i}"]`); if (!s) return;
    const orig = s.textContent;
    s.contentEditable = 'plaintext-only';
    if (s.contentEditable !== 'plaintext-only') s.contentEditable = 'true';
    s.classList.add('editing'); s.focus();
    const r = document.createRange(); r.selectNodeContents(s); const gs = getSelection(); gs.removeAllRanges(); gs.addRange(r);
    let done = false;
    const finish = (keep) => {
      if (done) return; done = true;
      s.contentEditable = 'false'; s.classList.remove('editing');
      const v = s.textContent.replace(/\s+/g, ' ').trim();
      if (keep && v !== orig) apply(C.editWord(i, v)); else s.textContent = orig;
    };
    s.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    s.addEventListener('blur', () => finish(true), { once: true });
  }

  let nowEl = null;
  function markNow(t) {
    const c = cap(); if (!c.words.length) return;
    let lo = 0, hi = c.words.length - 1, k = -1;
    while (lo <= hi) { const m = (lo + hi) >> 1; if (c.words[m].t0 <= t) { k = m; lo = m + 1; } else hi = m - 1; }
    const el2 = k >= 0 && t < c.words[k].t1 + 0.05 ? list.querySelector(`.rs-cap-w[data-i="${k}"]`) : null;
    if (el2 === nowEl) return;
    nowEl?.classList.remove('now'); nowEl = el2; nowEl?.classList.add('now');
  }

  // ---------------------------------------------------------------- script tab
  const scriptPane = h('div', { class: 'rs-cap-pane', 'data-pane': 'script', hidden: true });
  let script = null;

  // ---------------------------------------------------------------- translate tab (translate/panel.js)
  const translatePane = h('div', { class: 'rs-cap-pane', 'data-pane': 'translate', hidden: true });
  let trView = null;

  // ---------------------------------------------------------------- style tab (gallery + fields)
  const galleryEl = h('div', { class: 'rs-cap-gallery' });
  const form = h('div', { class: 'rs-cap-form' });
  const stylePane = h('div', { class: 'rs-cap-pane', 'data-pane': 'style', hidden: true }, galleryEl, form);
  const currentSpec = () => P().captions?.style || styleForWorkspace(ctx.workspace);
  const gallery = mountGallery(galleryEl, ctx, {
    currentId: () => resolveStyle(currentSpec()).id,
    onPick: (spec) => apply(C.setStyle(spec, true)),
    sampleWords() {
      const c = cap();
      const L = c.lines.find((x) => x.wordIdx[1] - x.wordIdx[0] >= 3 && !c.words[x.wordIdx[0]].tag) || c.lines[0];
      return L ? c.words.slice(L.wordIdx[0], Math.min(L.wordIdx[1], L.wordIdx[0] + 7) + 1).map((w) => w.w) : null;
    },
  });

  function renderForm() {
    const spec = currentSpec(), st = resolveStyle(spec);
    const set = (patch, relayout = false) => {
      const full = { ...resolveStyle(currentSpec()), ...patch };
      apply(C.setStyle(diffStyle(full), relayout, undefined, 'captions.style'));
    };
    const sel2 = (opts, v, on) => { const s = h('select', { class: 'rs-cap-in' }, opts.map((o) => h('option', { value: o }, o))); s.value = v; s.onchange = () => on(s.value); return s; };
    const color = (v, on) => { const i = h('input', { type: 'color', value: toHex(v) }); i.oninput = () => on(i.value); return i; };
    const range = (min, max, step, v, on, relayout) => { const i = h('input', { type: 'range', min, max, step, value: v }); i.oninput = () => on(+i.value); if (relayout) i.onchange = () => apply(C.relayout()); return i; };
    const anyOv = cap().lines.some((L) => L.ov) || cap().words.some((w) => w.ov);
    const scope = h('select', { class: 'rs-cap-in', 'data-k': 'dragScope' }, h('option', { value: 'line' }, 'This line'), h('option', { value: 'all' }, 'All lines'));
    scope.value = dragPrefs.scope; scope.onchange = () => { dragPrefs.scope = scope.value; };
    form.replaceChildren(
      h('div', { class: 'rs-cap-formhead' }, h('b', {}, st.name),
        h('button', { class: 'rs-cap-btn ghost', onclick: () => apply(C.setStyle({ id: st.id }, true)) }, 'Reset'),
        ctx.workspace ? h('button', { class: 'rs-cap-btn ghost', onclick: () => apply(C.setStyle(styleForWorkspace(ctx.workspace), true)) }, 'Company default') : null),
      field('Font', sel2(FONT_FAMILIES, st.font, (v) => set({ font: v }))),
      field('Arabic font', sel2(ARABIC_FONTS, st.fontAr, (v) => set({ fontAr: v }))),
      field('Size', range(28, 180, 1, st.size, (v) => set({ size: v }))),
      field('Weight', range(300, 900, 100, st.weight, (v) => set({ weight: v }))),
      field('Case', sel2(CASES, st.case, (v) => set({ case: v }))),
      field('Text', color(st.color, (v) => set({ color: v }))),
      field('Spoken word', color(st.active || st.color, (v) => set({ active: v, ...(st.activeBg ? { activeBg: v } : {}) }))),
      field('Outline', h('span', { class: 'rs-cap-pair' }, range(0, 24, 1, st.outline.w, (v) => set({ outline: { ...st.outline, w: v } })), color(st.outline.color, (v) => set({ outline: { ...st.outline, color: v } })))),
      field('Shadow', h('span', { class: 'rs-cap-pair' }, range(0, 40, 1, st.shadow.blur, (v) => set({ shadow: { ...st.shadow, blur: v, y: Math.round(v / 4) } })), color(st.shadow.color, (v) => set({ shadow: { ...st.shadow, color: v } })))),
      field('Background', h('span', { class: 'rs-cap-pair' }, sel2(BG_TYPES, st.bg.type, (v) => set({ bg: { ...st.bg, type: v } })), color(st.bg.color, (v) => set({ bg: { ...st.bg, color: v } })))),
      field('Bg opacity', range(0, 1, 0.05, st.bg.opacity, (v) => set({ bg: { ...st.bg, opacity: v } }))),
      field('Position', range(0, 1, 0.01, st.pos.y, (v) => set({ pos: { ...st.pos, y: v } }))),
      field('Left / right', range(0, 1, 0.01, st.pos.x ?? 0.5, (v) => set({ pos: { ...st.pos, x: v } }))),
      field('Animation', sel2(ANIMS, st.anim, (v) => set({ anim: v }, v === 'one-word' || st.anim === 'one-word'))),
      field('Line length', range(8, 44, 1, st.rowChars, (v) => set({ rowChars: v }), true)),
      field('Rows', sel2(['1', '2'], String(st.maxRows), (v) => set({ maxRows: +v }, true))),
      field('Drag moves', scope),
      h('button', { class: 'rs-cap-btn', 'data-x': 'reset-pos-all', title: 'Every line back to the style’s position, in this shape', onclick: () => apply(resetAll(cap())) }, '↺ Reset all positions'),
      anyOv ? h('button', { class: 'rs-cap-btn', 'data-x': 'reset-all', onclick: () => apply(C.resetOverrides()) }, '↺ Reset every line to the style') : null,
    );
  }

  // ---------------------------------------------------------------- wiring
  function show(t) {
    tab = t;
    for (const b of tabs.children) b.setAttribute('aria-selected', String(b.dataset.tab === t));
    transcriptPane.hidden = t !== 'transcript'; stylePane.hidden = t !== 'style'; scriptPane.hidden = t !== 'script'; translatePane.hidden = t !== 'translate';
    if (t === 'translate') { if (!trView) trView = mountTranslate(translatePane, ctx, apply); else trView.render(); }
    if (t === 'style') { gallery.render(); renderForm(); gallery.start(); } else gallery.stop();
    if (t === 'script') { if (!script) script = mountScript(scriptPane, ctx, apply); else script.render(); }
    if (t === 'transcript') renderList();
  }

  // compact header: the button + one Settings fold (model, language, music & sound tags, vocabulary), so the
  // transcript keeps the room on a 1280x800 screen; the current tag setting shows on the fold's label
  const tagChip = h('span', { class: 'rs-cap-muted rs-cap-tagchip' });
  const settings = h('details', { class: 'rs-cap-settings' },
    h('summary', {}, '⚙ Settings ', tagChip),
    h('div', { class: 'rs-cap-row' }, modelSel, langSel),
    h('div', { class: 'rs-cap-row' }, h('span', { class: 'rs-cap-muted' }, 'Hindi / Urdu in'), scriptSel),
    h('div', { class: 'rs-cap-row' }, h('span', { class: 'rs-cap-muted' }, 'Music & sound tags'), tagSel),
    h('div', { class: 'rs-cap-row' }, h('span', { class: 'rs-cap-muted' }, 'Something wrong?'), h('button', { class: 'rs-cap-btn ghost', 'data-x': 'cap-diag-settings', onclick: copyDiag }, '📋 Copy diagnostics')),
    vocabBox);
  // ⏱ Sync to voice, beside the captions he edits: re-times every word to the voice (sync/index.js syncWords: forced
  // alignment + onset snapping; only the times move, one undo step). After an edit it lights up and says so.
  const syncHint = h('p', { class: 'rs-cap-muted', 'data-x': 'sync-hint', hidden: true, style: 'margin:4px 0 0;color:var(--accent,#e33)' }, 'Captions edited — press ⏱ Sync to voice to put every word back on the voice.');
  const syncBtn = h('button', { class: 'rs-cap-btn', 'data-x': 'sync-voice', title: 'Listen again and put every caption word exactly on the voice (after you edit the words). Undo puts the old timing back.', onclick: async () => {
    if (!cap().words?.length) { ctx.toast?.('Make captions first (Auto captions)', 'warn'); return; }
    syncBtn.disabled = true; const was = syncBtn.textContent; syncBtn.textContent = '⏱ Syncing…';
    try {
      const S = await import('../sync/index.js');
      const ed = typeof window !== 'undefined' && window.kbbEditor ? window.kbbEditor.context() : ctx;
      const s = await S.syncWords(ed);
      ctx.toast?.(`Synced to the voice: ${s.words} words${s.model ? '' : ' (sound only — download the sync model in the ⏱ Sync tab for word-perfect timing)'}`, 'ok');
    } catch (e) { if (!e?.denied) ctx.toast?.(`Sync: ${e.message || e}`, 'error'); }
    finally { syncBtn.disabled = false; syncBtn.textContent = was; refresh(); }
  } }, '⏱ Sync to voice');
  const syncState = async () => {
    const c = cap(), last = P().meta?.sync?.last;
    if (!c?.words?.length) { syncHint.hidden = true; syncBtn.classList.remove('on'); return; }
    const { wordsSig } = await import('../sync/index.js');
    const edited = !!last?.sig && last.sig !== wordsSig(c.words);
    syncHint.hidden = !edited; syncBtn.classList.toggle('on', edited);
  };
  const trBtn = h('button', { class: 'rs-cap-btn', 'data-x': 'open-translate', title: 'Translate the captions (Urdu, Arabic, English, Hindi …)', onclick: () => show('translate') }, '🌐 Translate');
  // what Whisper heard + the Hindi / Urdu script switch (hinglish/notice.js)
  const notice = mountLangNotice({ cap, apply, canvas: () => P().canvas, relisten: (l) => { langSel.value = l; saveMeta(); runAuto(null, 'all'); } });
  el.append(h('div', { class: 'rs-cap-head' }, h('div', { class: 'rs-cap-row' }, goBtn, scopeSel, syncBtn, trBtn, settings), syncHint, newRow, progress, failBox, notice.el),
    tabs, transcriptPane, scriptPane, stylePane, translatePane);

  let queued = false;
  const refresh = () => {
    if (queued) return; queued = true;
    requestAnimationFrame(() => {
      queued = false;
      if (sel >= cap().words.length) sel = cap().words.length - 1;
      renderScope(false);
      tagSel.value = cap().soundTags || DEFAULT_TAG_MODE;
      tagChip.textContent = '· ♪ ' + (TAG_MODES.find((m) => m.id === tagSel.value)?.label || '');
      notice.render();
      syncState();
      if (tab === 'transcript') renderList();
      else if (tab === 'script') { if (!scriptPane.contains(document.activeElement)) script?.render(); }
      else if (tab === 'translate') { if (!translatePane.contains(document.activeElement) || document.activeElement.tagName !== 'DIV') trView?.render(); }
      else { renderForm(); gallery.mark(resolveStyle(currentSpec()).id); }
    });
  };
  if (ctx.bus) {
    offs.push(ctx.bus.on('project:change', refresh), ctx.bus.on('project:load', () => { sel = -1; refresh(); renderScope(true); }),
      ctx.bus.on('selection', () => renderScope(true)),
      ctx.bus.on('playhead', (e) => tab === 'transcript' && markNow(e.t)),
      ctx.bus.on('captions:possel', () => { for (const r of list.querySelectorAll('.rs-cap-line')) r.classList.toggle('pos-sel', posSel.has(lineKey('', r.dataset.line))); }),
      ctx.bus.on('workspace', () => { renderVocab(); if (tab === 'style') gallery.render(); }));
  }
  modelSel.onchange = langSel.onchange = () => saveMeta();
  // the script setting also re-writes Hindi / Urdu captions already made (one undo step with the setting)
  scriptSel.onchange = () => {
    const c = cap(), to = scriptSel.value === 'auto' ? (c.detect?.hinglish ? 'roman' : c.lang === 'ur' ? 'urdu' : 'deva') : scriptSel.value;
    const redo = isHindustani(c) && c.words.length && to !== currentScript(c) ? setScript(to, P().canvas) : null;
    saveMeta(redo);
  };
  function metaCmd() {
    const m = P().meta?.captions || {}, script = scriptSel.value;
    return ctx.ops.setProject({ meta: { ...(P().meta || {}), captions: { model: modelSel.value, lang: langSel.value, ...(script !== 'auto' || m.script ? { script } : {}) } } }, 'Caption settings');
  }
  function saveMeta(extra) {
    if (!ctx.ops?.setProject) { if (extra) apply(extra); return; }
    ctx.history.apply(extra && ctx.ops.batch ? ctx.ops.batch('Caption script', [metaCmd(), extra]) : metaCmd());
  }

  loadCaptionFonts().then(() => tab === 'style' && gallery.draw(1.2, true));
  renderVocab(); renderScope(true);
  scopeSel.onchange = () => renderScope(false);
  show('transcript');
  return {
    show, refresh, runAuto, edit, gallery, get script() { return script; }, get translate() { return trView; },
    select(i) { sel = i; renderList(); },
    overrides(mode) { ovMode = mode; renderSelBar(); },
    destroy() { offs.forEach((f) => f()); gallery.destroy(); el.replaceChildren(); el.classList.remove('rs-cap'); },
  };
}

function toHex(c) {
  if (/^#[0-9a-f]{6}$/i.test(c)) return c;
  if (/^#[0-9a-f]{3}$/i.test(c)) return '#' + [...c.slice(1)].map((x) => x + x).join('');
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(c || '');
  return m ? '#' + [m[1], m[2], m[3]].map((x) => (+x).toString(16).padStart(2, '0')).join('') : '#000000';
}
