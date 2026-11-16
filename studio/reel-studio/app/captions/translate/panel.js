// The Translate tab of the captions panel: one-click translation (source auto → target, Replace / Bilingual /
// Second track), "Get this language" through the engine installer, the company glossary, and the side-by-side review
// (source | translation, edit any line, ↻ re-translate one, ✓ approve, lines to check highlighted), per-language
// exports (TXT / SRT / VTT). Every change is a command through ctx.history (undoable); nothing here touches the
// project directly.

import { LANGS, LANG, langName, sourceLangOf, isRtlLang } from './langs.js';
import { ENGINES } from './engines.js';
import { translateCaptions, planFor, installFor, installedSet, candidatesFor, retranslateSentence, backCheck, trackItemCmds } from './service.js';
import * as TC from './commands.js';
import { glossaryFor } from './glossary.js';
import { lineFlags } from './pipeline.js';
import { claudeStatus, CLAUDE_NOTE } from './claude.js';
import { toTXT, toSRT, toVTT } from '../script.js';
import { saveText } from '../script-view.js';
import { wsDir } from '../../core/config.js';

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
const fmtMB = (b) => b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : Math.round(b / 1e6) + ' MB';
const MODES = [['replace', 'Replace captions'], ['bilingual', 'Bilingual (both)'], ['track', 'Add as a second track']];
const FLAG_ICON = { terms: '🏷', numbers: '#', keep: '⌗', length: '↔', loop: '🔁', script: 'Aa', meaning: '≠', empty: '∅' };

if (typeof document !== 'undefined' && !document.querySelector('link[data-rs-translate]')) {
  const l = document.createElement('link');
  l.rel = 'stylesheet'; l.href = new URL('./translate.css', import.meta.url).href; l.dataset.rsTranslate = '';
  document.head.append(l);
}

export function mountTranslate(el, ctx, apply) {
  const P = () => ctx.project;
  const cap = () => P().captions || { words: [], lines: [] };
  const prefs = { tgt: P().meta?.translate?.tgt || 'ar', mode: P().meta?.translate?.mode || 'replace', onlyFlagged: false, view: null };
  let running = null, statusTok = 0;

  el.classList.add('rs-tr');
  const srcSel = h('select', { class: 'rs-cap-in', 'data-k': 'src', title: 'Captions are in' });
  const tgtSel = h('select', { class: 'rs-cap-in', 'data-k': 'tgt', title: 'Translate into' }, LANGS.map((l) => h('option', { value: l.id }, `${l.native === l.name ? l.name : l.native + ' · ' + l.name}`)));
  const modeSel = h('select', { class: 'rs-cap-in', 'data-k': 'mode', title: 'How the translation goes on the video' }, MODES.map(([v, t]) => h('option', { value: v }, t)));
  tgtSel.value = prefs.tgt; modeSel.value = prefs.mode;
  const status = h('div', { class: 'rs-tr-status rs-cap-muted' });
  const goBtn = h('button', { class: 'rs-cap-btn primary', 'data-x': 'translate', onclick: () => run() }, '🌐 Translate');
  const cl = claudeStatus(ctx);
  const claudeBtn = h('button', { class: 'rs-cap-btn', 'data-x': 'claude', disabled: !cl.connected, title: cl.note, onclick: () => run('claude') }, '✨ Best quality (Claude)');
  const restoreBtn = h('button', { class: 'rs-cap-btn', 'data-x': 'restore', onclick: restore }, '↺ Restore original');
  const getBtn = h('button', { class: 'rs-cap-btn', 'data-x': 'get', hidden: true, onclick: getLang }, '⬇ Get this language');
  const bar = h('div', { class: 'rs-cap-bar' }, h('i'));
  const barLabel = h('span', { class: 'rs-cap-barlabel' });
  const cancelBtn = h('button', { class: 'rs-cap-btn ghost', onclick: () => running?.abort() }, 'Cancel');
  const progress = h('div', { class: 'rs-cap-progress', hidden: true }, barLabel, bar, cancelBtn);
  const setProg = (f, label) => { barLabel.textContent = label || ''; bar.firstChild.style.width = Math.round(Math.max(0, Math.min(1, f || 0)) * 100) + '%'; };

  // ---- glossary (per company)
  const glossTa = h('textarea', { class: 'rs-cap-in rs-tr-gloss', rows: 4, spellcheck: 'false',
    placeholder: 'One per line. A name to keep as it is:  Planet Careers\nA fixed translation:  Schengen = ar: شنغن | ur: شینگن | hi: शेंगेन' });
  const glossCount = h('span', { class: 'rs-cap-muted' });
  const glossBox = h('details', { class: 'rs-tr-glossbox' }, h('summary', {}, 'Glossary & do-not-translate ', glossCount), glossTa,
    h('div', { class: 'rs-cap-row' }, h('button', { class: 'rs-cap-btn', 'data-x': 'gloss-save', onclick: saveGlossary }, 'Save for this company'),
      h('span', { class: 'rs-cap-muted' }, 'Brand and product names, numbers, prices, codes, links and emoji always stay exactly as written.')));

  // ---- review
  const langChips = h('div', { class: 'rs-cap-row rs-tr-langs' });
  const onlyBox = h('input', { type: 'checkbox', 'data-k': 'only' });
  onlyBox.onchange = () => { prefs.onlyFlagged = onlyBox.checked; renderReview(); };
  const reviewHead = h('div', { class: 'rs-tr-revhead' });
  const review = h('div', { class: 'rs-tr-review', role: 'list' });
  const expSel = h('select', { class: 'rs-cap-in', 'data-k': 'exp' });
  const exports = h('div', { class: 'rs-cap-row rs-tr-exports' }, h('span', { class: 'rs-cap-muted' }, 'Export'), expSel,
    ...['txt', 'srt', 'vtt'].map((k) => h('button', { class: 'rs-cap-btn', 'data-x': 'exp-' + k, onclick: () => doExport(k) }, k.toUpperCase())));

  el.append(
    h('div', { class: 'rs-tr-head' },
      h('div', { class: 'rs-cap-row' }, h('span', { class: 'rs-cap-muted' }, 'From'), srcSel, h('span', {}, '→'), tgtSel),
      h('div', { class: 'rs-cap-row' }, modeSel, goBtn),
      h('div', { class: 'rs-cap-row' }, getBtn, restoreBtn, claudeBtn),
      cl.connected ? null : h('div', { class: 'rs-cap-muted rs-tr-claude', 'data-k': 'claude-note' }, '✨ ' + cl.note),
      status, progress, glossBox),
    langChips, reviewHead, review, exports);

  // ------------------------------------------------------------ status line: which model, installed or not
  async function renderStatus() {
    const tok = ++statusTok;
    const c = cap(), src = srcSel.value === 'auto' ? autoSrc() : srcSel.value, tgt = tgtSel.value;
    getBtn.hidden = true;
    if (!c.words.length) { status.textContent = 'Make captions first (Auto captions), then translate them here.'; goBtn.disabled = true; return; }
    goBtn.disabled = !!running;
    if (src === tgt) { status.textContent = `The captions are already in ${langName(tgt)}.`; goBtn.disabled = true; return; }
    const cands = candidatesFor(src, tgt);
    if (!cands.length) { status.textContent = `No offline model for ${langName(src)} → ${langName(tgt)}.`; goBtn.disabled = true; return; }
    let plan = null, st = null;
    try { st = ctx.engine?.modelsStatus ? await ctx.engine.modelsStatus() : null; } catch { st = null; }
    if (tok !== statusTok) return;
    const inst = st ? new Set(st.models.filter((m) => m.installed).map((m) => m.id)) : null;
    plan = await planFor(ctx, src, tgt, inst);
    if (tok !== statusTok) return;
    const eng = ENGINES[plan.best];
    const entry = st?.models.find((m) => m.id === plan.best);
    const backTxt = plan.back ? ' · lines checked by back-translation' : '';
    if (plan.needInstall) {
      getBtn.hidden = false;
      getBtn.textContent = `⬇ Get ${langName(tgt)} (${entry ? fmtMB(Math.max(0, entry.size - (entry.have || 0))) : 'download'})`;
      status.replaceChildren(h('span', {}, `Offline · ${eng.label} — not downloaded yet`), plan.eng ? h('span', {}, ` (${ENGINES[plan.eng].label} is installed and will be used)`) : null);
    } else status.textContent = `Offline · ${eng.label} ✓${backTxt}`;
  }
  const autoSrc = () => sourceLangOf({ ...TC.sourceOf(cap()), lang: TC.sourceOf(cap()).lang || cap().lang });
  function renderSrc() {
    const a = autoSrc();
    const cur = srcSel.value || 'auto';
    srcSel.replaceChildren(h('option', { value: 'auto' }, `Auto (${langName(a)})`), ...LANGS.map((l) => h('option', { value: l.id }, l.name)));
    srcSel.value = cur;
  }

  async function getLang() {
    const src = srcSel.value === 'auto' ? autoSrc() : srcSel.value, tgt = tgtSel.value;
    const plan = await planFor(ctx, src, tgt);
    const ids = [plan.best, ...(candidatesFor(tgt, src).slice(0, 1))].filter(Boolean);
    running = new AbortController(); progress.hidden = false; goBtn.disabled = true;
    try { await installFor(ctx, ids, setProg); ctx.toast?.(`${langName(tgt)} is ready offline`, 'ok'); }
    catch (e) { ctx.toast?.(e.denied ? 'Download not allowed' : 'Download failed: ' + e.message, e.denied ? 'warn' : 'error'); }
    finally { running = null; progress.hidden = true; renderStatus(); }
  }

  async function run(engineId) {
    if (running) return;
    if (typeof engineId !== 'string') engineId = undefined;
    const tgt = tgtSel.value, mode = modeSel.value;
    running = new AbortController();
    progress.hidden = false; goBtn.disabled = true; setProg(0, 'Starting…');
    try {
      const src = srcSel.value === 'auto' ? undefined : srcSel.value;
      const { result } = await translateCaptions(ctx, tgt, mode, { src, engineId, onProgress: setProg, signal: running.signal });
      prefs.view = tgt;
      const n = result.sents.filter((s) => s.flags.length).length;
      ctx.toast?.(`Translated into ${langName(tgt)} · ${result.sents.length} sentences${n ? ` · ${n} to check` : ''}`, n ? 'warn' : 'ok');
      saveMeta(tgt, mode);
    } catch (e) {
      if (e.name !== 'AbortError') ctx.toast?.(e.denied ? 'Download not allowed — translation needs its model once.' : 'Translation failed: ' + e.message, e.denied ? 'warn' : 'error');
    } finally { running = null; progress.hidden = true; render(); }
  }
  function saveMeta(tgt, mode) {
    if (ctx.ops?.setProject) ctx.history.apply(ctx.ops.setProject({ meta: { ...(P().meta || {}), translate: { tgt, mode } } }, 'Translate settings'));
  }

  function restore() {
    const c = cap();
    const own = P().tracks.filter((t) => t.items.length && t.items.every((i) => i.capTrack));        // tracks made for a translation
    const items = P().tracks.filter((t) => !own.includes(t)).flatMap((t) => t.items.filter((i) => i.capTrack).map((i) => i.id));
    const cmds = [TC.restoreOriginal()];
    if ((own.length || items.length) && ctx.ops) {
      cmds.push(...own.map((t) => ctx.ops.removeTrack(t.id, 'Remove translated track')), items.length ? ctx.ops.removeItems(items, 'Remove translated track') : null,
        ...Object.keys(c.tracks || {}).map((l) => TC.dropTrack(l)));
    }
    apply(cmds.length > 1 && ctx.ops ? ctx.ops.batch('Restore original captions', cmds) : cmds[0]);
    ctx.toast?.('Original captions restored (translations are kept — show them again any time)', 'ok');
  }

  // ------------------------------------------------------------ glossary
  function renderGlossary() {
    const ws = ctx.workspace;
    const own = ws?.translate?.glossary || [];
    glossTa.value = own.map((g) => (typeof g === 'string' ? g : g.keep && !Object.keys(g).some((k) => k.length === 2) ? g.term
      : g.term + ' = ' + Object.entries(g).filter(([k]) => k.length === 2).map(([k, v]) => `${k}: ${v}`).join(' | '))).join('\n');
    glossCount.textContent = `(${glossaryFor(ws).length} names)`;
  }
  async function saveGlossary() {
    const ws = ctx.workspace; if (!ws) { ctx.toast?.('Pick a company first', 'warn'); return; }
    const list = glossTa.value.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => {
      const [term, rest] = l.split(/\s*=\s*/);
      if (!rest) return { term, keep: true };
      const g = { term };
      for (const part of rest.split('|')) { const m = /^\s*([a-z]{2})\s*:\s*(.+?)\s*$/.exec(part); if (m && LANG[m[1]]) g[m[1]] = m[2]; }
      if (Object.keys(g).length === 1) g.keep = true;
      return g;
    });
    ws.translate = { ...(ws.translate || {}), glossary: list };
    ws.modified = new Date().toISOString();
    try { if (ctx.engine && (ctx.engine.available ?? true) && ws.id) await ctx.engine.writeFile(`${wsDir(ws.id)}/workspace.json`, ws); ctx.toast?.('Glossary saved', 'ok'); }
    catch (e) { ctx.toast?.('Could not save: ' + e.message, 'warn'); }
    renderGlossary();
  }

  // ------------------------------------------------------------ review
  function viewLang() {
    const tr = cap().tr;
    const langs = Object.keys(tr?.langs || {});
    if (prefs.view && langs.includes(prefs.view)) return prefs.view;
    return tr?.active || langs[langs.length - 1] || null;
  }
  function renderLangs() {
    const tr = cap().tr, langs = Object.keys(tr?.langs || {});
    restoreBtn.disabled = !tr?.source || (!tr.active && !cap().alt?.fromTr && cap().words === tr.source.words);
    langChips.replaceChildren(...(langs.length ? [h('span', { class: 'rs-cap-muted' }, 'Translations'), ...langs.map((l) => {
      const T = tr.langs[l], todo = T.sents.filter((s) => s.flags?.length && !s.approved).length;
      const on = tr.active === l || cap().tracks?.[l];
      return h('button', { class: 'rs-cap-chip' + (viewLang() === l ? ' on' : ''), 'data-lang': l, title: `${langName(l)} · ${ENGINES[T.engine]?.label || T.engine}${on ? ' · on the video' : ''}`,
        onclick: () => { prefs.view = l; render(); } }, `${l.toUpperCase()}${on ? ' ●' : ''}${todo ? ` · ${todo}⚑` : ''}`);
    })] : []));
  }
  function renderReview() {
    const lang = viewLang(), c = cap(), T = lang && c.tr?.langs?.[lang];
    if (!T) { reviewHead.replaceChildren(); review.replaceChildren(h('div', { class: 'rs-cap-empty' }, 'Translations appear here side by side: original | translation. Edit any line, ↻ re-translate it, ✓ approve it.')); return; }
    const src = T.src || TC.sourceOf(c).lang;
    const shown = c.tr.active === lang ? (c.tr.mode === 'replace' ? 'replaces the captions' : 'shown with the original') : c.tracks?.[lang] ? 'second track' : 'not on the video';
    const todo = T.sents.filter((s) => s.flags?.length && !s.approved).length;
    reviewHead.replaceChildren(
      h('div', { class: 'rs-cap-row' }, h('b', {}, `${langName(src)} → ${langName(lang)}`), h('span', { class: 'rs-cap-muted' }, `· ${shown} · ${ENGINES[T.engine]?.label || T.engine}`)),
      h('div', { class: 'rs-cap-row' },
        h('label', { class: 'rs-cap-check' }, onlyBox, ` Only lines to check (${todo})`),
        h('button', { class: 'rs-cap-btn', 'data-x': 'approve-all', disabled: !todo, onclick: () => apply(TC.approve(lang)) }, '✓ Approve all'),
        ...(['replace', 'bilingual', 'track'].map((m) => h('button', { class: 'rs-cap-btn ghost', 'data-show': m, title: 'Show this language as: ' + MODES.find((x) => x[0] === m)[1],
          onclick: () => showAs(lang, m) }, { replace: 'Replace', bilingual: 'Bilingual', track: '2nd track' }[m])))));
    const rtl = isRtlLang(lang), srtl = isRtlLang(src);
    const frag = document.createDocumentFragment();
    T.sents.forEach((s, i) => {
      const flagged = s.flags?.length && !s.approved;
      if (prefs.onlyFlagged && !flagged) return;
      const t0 = c.tr.source.words[s.idx[0]]?.t0 ?? 0;
      const out = h('div', { class: 'rs-tr-out', contenteditable: 'plaintext-only', dir: rtl ? 'rtl' : 'ltr', lang, spellcheck: 'true', 'data-i': i }, s.out);
      if (out.contentEditable !== 'plaintext-only') out.contentEditable = 'true';
      out.addEventListener('focus', () => { out.dataset.orig = out.textContent; });
      out.addEventListener('keydown', (e) => {
        e.stopPropagation();
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); out.blur(); }
        if (e.key === 'Escape') { out.textContent = out.dataset.orig; out.blur(); }
      });
      out.addEventListener('blur', () => commitEdit(lang, i, out));
      frag.append(h('div', { class: 'rs-tr-row' + (flagged ? ' flag' : '') + (s.approved ? ' ok' : ''), role: 'listitem', 'data-i': i },
        h('button', { class: 'rs-cap-time', onclick: () => ctx.seek?.(t0) }, fmt(t0)),
        h('div', { class: 'rs-tr-src', dir: srtl ? 'rtl' : 'ltr', lang: src }, s.text),
        out,
        h('div', { class: 'rs-tr-acts' },
          (s.flags || []).map((f) => h('span', { class: 'rs-tr-flag', title: f.msg, 'data-flag': f.k }, FLAG_ICON[f.k] || '!')),
          s.bt != null ? h('span', { class: 'rs-tr-bt', title: 'Back-translation match (higher is closer to the original)' + (s.back ? `: “${s.back}”` : '') }, s.bt) : null,
          h('button', { class: 'rs-cap-btn ghost', 'data-x': 'retr', title: 'Re-translate this line', onclick: () => retr(lang, i) }, '↻'),
          h('button', { class: 'rs-cap-btn ghost' + (s.approved ? ' on' : ''), 'data-x': 'ok', title: s.approved ? 'Approved — click to undo' : 'Approve this line', onclick: () => apply(TC.approve(lang, i, !s.approved)) }, '✓'))));
    });
    review.replaceChildren(frag);
    expSel.replaceChildren(h('option', { value: 'src' }, `Original (${langName(src)})`), ...Object.keys(c.tr.langs).map((l) => h('option', { value: l }, langName(l))));
    expSel.value = lang;
  }
  async function commitEdit(lang, i, out) {
    const v = out.textContent.replace(/\s+/g, ' ').trim();
    if (v === (out.dataset.orig || '').replace(/\s+/g, ' ').trim()) return;
    const glossary = glossaryFor(ctx.workspace);
    let back = {};
    try { back = await backCheck(ctx, lang, v); } catch { back = {}; }
    apply(TC.editSentence(lang, i, v, { glossary, back: back.back ?? null, approved: true }));
  }
  async function retr(lang, i) {
    const row = review.querySelector(`.rs-tr-row[data-i="${i}"]`); row?.classList.add('busy');
    try { apply(await retranslateSentence(ctx, lang, i)); }
    catch (e) { ctx.toast?.('Re-translate failed: ' + e.message, 'error'); row?.classList.remove('busy'); }
  }
  function showAs(lang, mode) {
    const cmds = [TC.showLang(lang, mode)];
    if (mode === 'track' && ctx.ops) cmds.push(...trackItemCmds(ctx, lang));
    apply(cmds.length > 1 ? ctx.ops.batch(`Show ${lang.toUpperCase()} as a second track`, cmds) : cmds[0]);
  }

  // ------------------------------------------------------------ exports per language
  const slug = (s) => String(s || 'captions').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'captions';
  async function doExport(kind) {
    const c = cap(), lang = expSel.value || 'src';
    const set = TC.captionsIn(c, lang);
    if (!set) return null;
    const code = lang === 'src' ? (set.lang && set.lang !== 'auto' ? set.lang : 'orig') : lang;
    const name = `${slug(P().name)}-${new Date().toISOString().slice(0, 10)}-${code}.${kind}`;
    const text = kind === 'srt' ? toSRT(set) : kind === 'vtt' ? toVTT(set) : toTXT(set);
    try {
      const where = await saveText(ctx, name, text, kind === 'vtt' ? 'text/vtt' : 'text/plain');
      ctx.toast?.(where === 'download' ? `${name} downloaded` : `Saved ${where}`, 'ok');
      (el.__saved ||= []).push(where);
      return where;
    } catch (e) { ctx.toast?.('Export failed: ' + e.message, 'error'); return null; }
  }

  function render() { renderSrc(); renderLangs(); renderReview(); renderStatus(); }
  srcSel.onchange = tgtSel.onchange = () => renderStatus();
  modeSel.onchange = () => { prefs.mode = modeSel.value; };
  renderSrc(); renderGlossary();
  render();
  return { render, run, restore, doExport, getLang, renderGlossary, prefs,
    set(p) { if (p.tgt) tgtSel.value = p.tgt; if (p.mode) modeSel.value = p.mode; if (p.src) srcSel.value = p.src; renderStatus(); },
    lineFlags: () => { const T = cap().tr?.langs?.[viewLang()]; return T ? lineFlags(T.sents) : {}; } };
}

export { CLAUDE_NOTE };
