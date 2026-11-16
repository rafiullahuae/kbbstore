// Caption editor panel. The editor mounts it with mountCaptionPanel(el, ctx) (ctx = EditorContext,
// core/modules.js). It never mutates the project: every edit is a command through ctx.history.
//
// Transcript tab: click a word -> playhead jumps there; double-click -> edit in place (Enter keeps,
// Esc cancels, empty deletes); split / merge / delete / add / nudge; find & replace; shift all;
// fillers and low-confidence words are highlighted; emoji suggestions per line.
// Style tab: live tiles of every style (company default first) + every field of the current style.

import { STYLES, resolveStyle, diffStyle, styleForWorkspace, stylesForWorkspace, ANIMS, BG_TYPES, CASES } from './styles.js';
import { FONT_FAMILIES, ARABIC_FONTS, loadCaptionFonts } from './fonts.js';
import { isFiller, isRtlText, lineText } from './model.js';
import { suggestEmoji } from './emoji.js';
import { drawCaptions } from './render.js';
import * as C from './commands.js';
import { LANGS } from './stt.js';
import { SPEECH_MODELS } from './models.js';
import { autoCaptions } from './auto.js';

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

const SAMPLE_EN = 'Glow up with this serum'.split(' ');
const SAMPLE_AR = 'بشرة نضرة مع هذا السيروم'.split(' ');
const SAMPLE_PRO = 'Study in Germany this March'.split(' ');

export function mountCaptionPanel(el, ctx) {
  const P = () => ctx.project;
  const cap = () => P().captions || { words: [], lines: [] };
  const apply = (cmd) => { ctx.history.apply(cmd); return cmd; };
  let sel = -1;                     // selected word index
  let tab = 'transcript';
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
  modelSel.value = meta.model || 'small'; langSel.value = meta.lang || 'auto';
  const bar = h('div', { class: 'rs-cap-bar' }, h('i'));
  const barLabel = h('span', { class: 'rs-cap-barlabel' });
  const cancelBtn = h('button', { class: 'rs-cap-btn ghost', onclick: () => running?.abort() }, 'Cancel');
  const progress = h('div', { class: 'rs-cap-progress', hidden: true }, barLabel, bar, cancelBtn);
  const goBtn = h('button', { class: 'rs-cap-btn primary', onclick: runAuto }, '💬 Auto captions');

  async function runAuto() {
    if (running) return;
    running = new AbortController();
    progress.hidden = false; goBtn.disabled = true;
    const set = (label, f) => { barLabel.textContent = label || ''; bar.firstChild.style.width = f == null ? '0' : Math.round(f * 100) + '%'; };
    try {
      await autoCaptions(ctx, { model: modelSel.value, lang: langSel.value, signal: running.signal, onProgress: set });
    } catch (e) {
      if (e.name !== 'AbortError') ctx.toast?.('Captions failed: ' + e.message, 'error');
    } finally { running = null; progress.hidden = true; goBtn.disabled = false; }
  }

  const tabs = h('div', { class: 'rs-cap-tabs', role: 'tablist' },
    h('button', { role: 'tab', 'data-tab': 'transcript', onclick: () => show('transcript') }, 'Transcript'),
    h('button', { role: 'tab', 'data-tab': 'style', onclick: () => show('style') }, 'Style'));

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
      h('span', { class: 'rs-cap-legend' }, h('b', { class: 'filler' }, 'filler'), ' ', h('b', { class: 'lowconf' }, 'check'))));

  const selBar = h('div', { class: 'rs-cap-sel', hidden: true });
  const list = h('div', { class: 'rs-cap-list', role: 'list' });
  const transcriptPane = h('div', { class: 'rs-cap-pane', 'data-pane': 'transcript' }, tools, selBar, list);

  function lineOfWord(i) { return cap().lines.find((L) => L.wordIdx[0] <= i && i <= L.wordIdx[1]); }

  function renderSelBar() {
    const c = cap(), w = c.words[sel];
    selBar.hidden = !w;
    if (!w) return;
    const L = lineOfWord(sel);
    selBar.replaceChildren(
      h('span', { class: 'rs-cap-selword', dir: 'auto' }, `“${w.w}”`), h('span', { class: 'rs-cap-muted' }, ` ${w.t0.toFixed(2)}–${w.t1.toFixed(2)} s`),
      h('div', { class: 'rs-cap-row' },
        h('button', { class: 'rs-cap-btn', title: 'Word 0.05 s earlier', onclick: () => apply(C.nudgeWord(sel, -0.05)) }, '−0.05'),
        h('button', { class: 'rs-cap-btn', title: 'Word 0.05 s later', onclick: () => apply(C.nudgeWord(sel, 0.05)) }, '+0.05'),
        h('button', { class: 'rs-cap-btn', title: 'Start a new line at this word', disabled: !L || L.wordIdx[0] === sel, onclick: () => apply(C.splitLine(sel)) }, 'Split here'),
        h('button', { class: 'rs-cap-btn', title: 'Join this line with the next', disabled: !L || L === c.lines[c.lines.length - 1], onclick: () => apply(C.mergeWithNext(L.id)) }, 'Merge next'),
        h('button', { class: 'rs-cap-btn', title: 'Add a word after this one', onclick: () => addAfter(sel) }, '+ Word'),
        h('button', { class: 'rs-cap-btn danger', title: 'Delete word', onclick: () => { apply(C.deleteWords(sel)); sel = Math.min(sel, cap().words.length - 1); } }, 'Delete')),
      L ? h('div', { class: 'rs-cap-row' }, h('span', { class: 'rs-cap-muted' }, 'Line'),
        h('button', { class: 'rs-cap-btn', onclick: () => apply(C.nudgeLine(L.id, -0.1)) }, '−0.1 s'),
        h('button', { class: 'rs-cap-btn', onclick: () => apply(C.nudgeLine(L.id, 0.1)) }, '+0.1 s')) : null);
  }

  function addAfter(i) {
    apply(C.insertWords(i, '…'));
    sel = i + 1; renderList();
    edit(sel);
  }

  function renderList() {
    const c = cap();
    list.replaceChildren();
    if (!c.words.length) {
      list.append(h('div', { class: 'rs-cap-empty' }, 'No captions yet. Press ', h('b', {}, 'Auto captions'), ' — or add a first word: ',
        h('button', { class: 'rs-cap-btn', onclick: () => { apply(C.insertWords(-1, '…')); sel = 0; renderList(); edit(0); } }, '+ Word')));
      renderSelBar(); return;
    }
    const frag = document.createDocumentFragment();
    for (const L of c.lines) {
      const words = [];
      for (let i = L.wordIdx[0]; i <= L.wordIdx[1]; i++) {
        const w = c.words[i];
        words.push(h('span', { class: 'rs-cap-w' + (isFiller(w.w) ? ' filler' : '') + ((w.conf ?? 1) < 0.6 ? ' lowconf' : '') + (i === sel ? ' sel' : ''),
          'data-i': i, title: `${w.t0.toFixed(2)}–${w.t1.toFixed(2)} s` }, w.w), ' ');
      }
      const words2 = c.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => w.w);
      const emo = suggestEmoji(words2, 3);
      const row = h('div', { class: 'rs-cap-line', role: 'listitem', 'data-line': L.id },
        h('button', { class: 'rs-cap-time', title: 'Go to this line', onclick: () => ctx.seek?.(L.t0) }, fmt(L.t0)),
        h('div', { class: 'rs-cap-text', dir: isRtlText(lineText(c, L)) ? 'rtl' : 'ltr' }, words),
        h('div', { class: 'rs-cap-emoji' },
          L.emoji ? h('button', { class: 'rs-cap-chip on', title: 'Remove emoji', onclick: () => apply(C.setLineEmoji(L.id, null)) }, L.emoji, ' ×') : null,
          emo.filter((e) => e !== L.emoji).map((e) => h('button', { class: 'rs-cap-chip', title: 'Add emoji to this line', onclick: () => apply(C.setLineEmoji(L.id, e)) }, e))));
      frag.append(row);
    }
    list.append(frag);
    renderSelBar();
    markNow(ctx.playhead?.() ?? 0);
  }

  list.addEventListener('click', (e) => {
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

  // ---------------------------------------------------------------- style tab
  const tilesEl = h('div', { class: 'rs-cap-tiles' });
  const form = h('div', { class: 'rs-cap-form' });
  const stylePane = h('div', { class: 'rs-cap-pane', 'data-pane': 'style', hidden: true }, tilesEl, form);
  let tiles = [];

  function sampleProject(spec, words) {
    const st = resolveStyle(spec);
    const src = words || (st.id === 'arabic-first' ? SAMPLE_AR : st.group === 'Pro' ? SAMPLE_PRO : SAMPLE_EN);
    const mk = (arr, t0 = 0.25) => arr.map((w, i) => ({ w, t0: t0 + i * 0.32, t1: t0 + i * 0.32 + 0.3, conf: 1 }));
    const ws = mk(src);
    const lines = st.anim === 'one-word' ? ws.map((w, i) => ({ id: 's' + i, t0: w.t0, t1: w.t1 + 0.02, wordIdx: [i, i] }))
      : [{ id: 's', t0: 0, t1: 99, wordIdx: [0, ws.length - 1] }];
    const captions = { style: spec, words: ws, lines };
    if (st.bilingual) { const a = mk(SAMPLE_AR); captions.alt = { words: a, lines: [{ id: 'a', t0: 0, t1: 99, wordIdx: [0, a.length - 1] }] }; }
    return { canvas: { w: 1080, h: 1920 }, captions, dur: ws[ws.length - 1].t1 + 0.8 };
  }

  function sampleWords() {
    const c = cap();
    const L = c.lines.find((x) => x.wordIdx[1] - x.wordIdx[0] >= 3) || c.lines[0];
    return L ? c.words.slice(L.wordIdx[0], Math.min(L.wordIdx[1], L.wordIdx[0] + 7) + 1).map((w) => w.w) : null;
  }

  function currentSpec() { return P().captions?.style || styleForWorkspace(ctx.workspace); }

  function renderTiles() {
    const wsDefault = styleForWorkspace(ctx.workspace);
    const cur = resolveStyle(currentSpec()).id;
    const words = sampleWords();
    tiles = stylesForWorkspace(ctx.workspace).map((s) => {
      const spec = s.id === wsDefault.id ? wsDefault : { id: s.id };
      const cv = h('canvas', { width: 216, height: 384 });
      const tile = h('button', { class: 'rs-cap-tile' + (s.id === cur ? ' on' : ''), 'data-style': s.id, title: s.name,
        onclick: () => { apply(C.setStyle(spec, true)); } },
        cv, h('span', {}, s.name), s.id === wsDefault.id && ctx.workspace ? h('em', {}, 'Company default') : null);
      // the project's own words where they suit the style (Arabic words on Arabic styles and vice versa)
      const wantsRtl = s.id === 'arabic-first';
      const fits = words && (s.bilingual ? !isRtlText(words.join(' ')) : isRtlText(words.join(' ')) === wantsRtl);
      // thumbnails are ~100 px wide: draw the text 1.7x so the look reads; the click applies the real size
      const big = { ...(typeof spec === 'string' ? { id: spec } : spec), size: Math.round(s.size * 1.7), rowChars: Math.max(8, Math.round(s.rowChars / 1.7)), outline: { ...s.outline, w: s.outline.w * 1.4 } };
      return { s, spec, cv, tile, proj: sampleProject(big, fits ? words.slice(0, 5) : null) };
    });
    tilesEl.replaceChildren(...tiles.map((x) => x.tile));
    drawTiles(1.2);
  }

  function drawTiles(t) {
    for (const x of tiles) {
      const c2 = x.cv.getContext('2d');
      c2.setTransform(216 / 1080, 0, 0, 384 / 1920, 0, 0);
      tileBackdrop(c2);
      drawCaptions(c2, null, t % x.proj.dur, x.proj);
    }
  }

  // one animation loop, only while the Style tab is on screen; ~15 fps is plenty for thumbnails
  let raf = 0, last = 0;
  const loop = (now) => {
    raf = 0;
    if (tab !== 'style' || !el.isConnected || document.hidden) return;
    if (now - last > 66) { last = now; drawTiles(now / 1000); }
    raf = requestAnimationFrame(loop);
  };
  const startLoop = () => { if (!raf) raf = requestAnimationFrame(loop); };

  function field(label, input) { return h('label', { class: 'rs-cap-field' }, h('span', {}, label), input); }
  function renderForm() {
    const spec = currentSpec(), st = resolveStyle(spec);
    const set = (patch, relayout = false) => {
      const full = { ...resolveStyle(currentSpec()), ...patch };
      apply(C.setStyle(diffStyle(full), relayout, undefined, 'captions.style'));
    };
    const sel2 = (opts, v, on) => { const s = h('select', { class: 'rs-cap-in' }, opts.map((o) => h('option', { value: o }, o))); s.value = v; s.onchange = () => on(s.value); return s; };
    const color = (v, on) => { const i = h('input', { type: 'color', value: toHex(v) }); i.oninput = () => on(i.value); return i; };
    const range = (min, max, step, v, on, relayout) => { const i = h('input', { type: 'range', min, max, step, value: v }); i.oninput = () => on(+i.value); if (relayout) i.onchange = () => apply(C.relayout()); return i; };
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
      field('Position', range(0, 1, 0.01, st.pos.y, (v) => set({ pos: { y: v } }))),
      field('Animation', sel2(ANIMS, st.anim, (v) => set({ anim: v }, v === 'one-word' || st.anim === 'one-word'))),
      field('Line length', range(8, 44, 1, st.rowChars, (v) => set({ rowChars: v }), true)),
      field('Rows', sel2(['1', '2'], String(st.maxRows), (v) => set({ maxRows: +v }, true))),
    );
  }

  // ---------------------------------------------------------------- wiring
  function show(t) {
    tab = t;
    for (const b of tabs.children) b.setAttribute('aria-selected', String(b.dataset.tab === t));
    transcriptPane.hidden = t !== 'transcript'; stylePane.hidden = t !== 'style';
    if (t === 'style') { renderTiles(); renderForm(); startLoop(); }
  }

  el.append(h('div', { class: 'rs-cap-head' }, h('div', { class: 'rs-cap-row' }, goBtn, modelSel, langSel), progress), tabs, transcriptPane, stylePane);

  let queued = false;
  const refresh = () => {
    if (queued) return; queued = true;
    requestAnimationFrame(() => {
      queued = false;
      if (sel >= cap().words.length) sel = cap().words.length - 1;
      if (tab === 'transcript') renderList(); else { renderForm(); for (const x of tiles) x.tile.classList.toggle('on', x.s.id === resolveStyle(currentSpec()).id); }
    });
  };
  if (ctx.bus) {
    offs.push(ctx.bus.on('project:change', refresh), ctx.bus.on('project:load', () => { sel = -1; refresh(); }),
      ctx.bus.on('playhead', (e) => tab === 'transcript' && markNow(e.t)),
      ctx.bus.on('workspace', () => tab === 'style' && renderTiles()));
  }
  modelSel.onchange = langSel.onchange = () => saveMeta();
  function saveMeta() { if (ctx.ops?.setProject) ctx.history.apply(ctx.ops.setProject({ meta: { ...(P().meta || {}), captions: { model: modelSel.value, lang: langSel.value } } }, 'Caption settings')); }

  loadCaptionFonts().then(() => tab === 'style' && drawTiles(1.2));
  show('transcript');
  renderList();
  return {
    show, refresh, runAuto, edit,
    select(i) { sel = i; renderList(); },
    destroy() { offs.forEach((f) => f()); cancelAnimationFrame(raf); el.replaceChildren(); el.classList.remove('rs-cap'); },
  };
}

function toHex(c) {
  if (/^#[0-9a-f]{6}$/i.test(c)) return c;
  if (/^#[0-9a-f]{3}$/i.test(c)) return '#' + [...c.slice(1)].map((x) => x + x).join('');
  const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(c || '');
  return m ? '#' + [m[1], m[2], m[3]].map((x) => (+x).toString(16).padStart(2, '0')).join('') : '#000000';
}

function tileBackdrop(c) {
  const g = c.createLinearGradient(0, 0, 1080, 1920);
  g.addColorStop(0, '#e9b9a0'); g.addColorStop(0.5, '#8e5a6c'); g.addColorStop(1, '#1f1a2e');
  c.fillStyle = g; c.fillRect(0, 0, 1080, 1920);
}
