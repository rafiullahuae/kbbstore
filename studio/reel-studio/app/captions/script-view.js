// The Script tab: the whole transcript as paragraphs (optional timestamps, speaker turns), every paragraph editable
// in place; an edit is aligned word by word to the captions it came from (script.alignEdit) and applied as ONE
// undoable command, so the captions on the video change with it. Copy / TXT / SRT / VTT (and bilingual when a
// second language exists), saved into workspaces/<ws>/exports/ through the engine (or downloaded without one).

import { paragraphs, toTXT, toSRT, toVTT, toBilingualTXT, fmtClock, speakerName, alignEdit } from './script.js';
import { replaceSpan, setSpeakerName } from './commands.js';
import { isRtlText } from './model.js';
import { TAG_ICON } from './nonspeech.js';
import { exportsDir } from '../core/config.js';

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

/** Apply an edited paragraph: the non-tag words of words[a..b] are aligned to `text`; tags in the span stay put. */
export function editParagraphCmd(cap, a, b, text) {
  const span = cap.words.slice(a, b + 1);
  const plain = span.filter((w) => !w.tag);
  const next = alignEdit(plain, text);
  const merged = [...next, ...span.filter((w) => w.tag)].sort((x, y) => x.t0 - y.t0 || (x.tag ? 1 : -1));
  return replaceSpan(a, b, merged);
}

const slug = (s) => String(s || 'captions').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'captions';

/** Save `text` as `name` into the workspace's exports/ (engine), else download it. Returns the path or 'download'. */
export async function saveText(ctx, name, text, type = 'text/plain') {
  const ws = ctx.workspace?.id || ctx.project?.workspace;
  if (ctx.engine && (ctx.engine.available ?? true) && ws) {
    const path = `${exportsDir(ws)}/${name}`;
    await ctx.engine.writeFile(path, text);
    return path;
  }
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([text], { type: type + ';charset=utf-8' }));
  a.download = name; document.body.append(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  return 'download';
}

export function mountScript(el, ctx, apply) {
  const P = () => ctx.project;
  const cap = () => P().captions || { words: [], lines: [] };
  const prefs = { timestamps: false, speakers: true };
  const tsBox = h('input', { type: 'checkbox' });
  const spBox = h('input', { type: 'checkbox', checked: true });
  tsBox.onchange = () => { prefs.timestamps = tsBox.checked; render(); };
  spBox.onchange = () => { prefs.speakers = spBox.checked; render(); };
  const opt = () => ({ timestamps: prefs.timestamps, speakers: prefs.speakers && hasSpk() });
  const hasSpk = () => cap().words.some((w) => (w.spk ?? 0) > 0);
  const base = () => slug(P().name) + '-' + new Date().toISOString().slice(0, 10);

  const exports = {
    txt: () => [base() + '.txt', toTXT(cap(), opt())],
    srt: () => [base() + '.srt', toSRT(cap())],
    vtt: () => [base() + '.vtt', toVTT(cap()), 'text/vtt'],
    'bi-txt': () => [base() + '-bilingual.txt', toBilingualTXT(cap(), opt())],
    'bi-srt': () => [base() + '-bilingual.srt', toSRT(cap(), { bilingual: true })],
  };
  async function doExport(kind) {
    const [name, text, type] = exports[kind]();
    try {
      const where = await saveText(ctx, name, text, type);
      ctx.toast?.(where === 'download' ? `${name} downloaded` : `Saved ${where}`, 'ok');
      (el.__saved ||= []).push(where);
      return where;
    } catch (e) { ctx.toast?.('Export failed: ' + e.message, 'error'); return null; }
  }
  async function copy() {
    const text = toTXT(cap(), opt());
    try { await navigator.clipboard.writeText(text); ctx.toast?.('Script copied', 'ok'); }
    catch { // no clipboard permission (or not focused): select it in a textarea instead
      const ta = h('textarea', { class: 'rs-cap-copyta' }); ta.value = text; el.append(ta); ta.select(); try { document.execCommand('copy'); } catch {} ta.remove();
      ctx.toast?.('Script copied', 'ok');
    }
    el.__copied = text;
    return text;
  }

  const biBtns = h('span', { class: 'rs-cap-row' });
  const bar = h('div', { class: 'rs-cap-scriptbar' },
    h('div', { class: 'rs-cap-row' },
      h('label', { class: 'rs-cap-check' }, tsBox, ' Timestamps'),
      h('label', { class: 'rs-cap-check', 'data-k': 'spk' }, spBox, ' Speakers'),
      h('span', { class: 'rs-cap-muted rs-cap-stat' })),
    h('div', { class: 'rs-cap-row' },
      h('button', { class: 'rs-cap-btn', 'data-x': 'copy', onclick: copy }, '📋 Copy'),
      h('button', { class: 'rs-cap-btn', 'data-x': 'txt', onclick: () => doExport('txt') }, 'TXT'),
      h('button', { class: 'rs-cap-btn', 'data-x': 'srt', onclick: () => doExport('srt') }, 'SRT'),
      h('button', { class: 'rs-cap-btn', 'data-x': 'vtt', onclick: () => doExport('vtt') }, 'VTT'),
      biBtns));
  const body = h('div', { class: 'rs-cap-script', role: 'list' });
  el.append(bar, body);

  function render() {
    const c = cap();
    biBtns.replaceChildren(...(c.alt?.words?.length ? [
      h('button', { class: 'rs-cap-btn', 'data-x': 'bi-txt', onclick: () => doExport('bi-txt') }, 'Bilingual TXT'),
      h('button', { class: 'rs-cap-btn', 'data-x': 'bi-srt', onclick: () => doExport('bi-srt') }, 'Bilingual SRT')] : []));
    bar.querySelector('[data-k="spk"]').hidden = !hasSpk();
    const ps = paragraphs(c, { mode: 'hide' });
    const words = c.words.filter((w) => !w.tag).length;
    bar.querySelector('.rs-cap-stat').textContent = c.words.length ? `${words} words · ${ps.filter((p) => !p.tagOnly).length} paragraphs` : '';
    if (!c.words.length) { body.replaceChildren(h('div', { class: 'rs-cap-empty' }, 'The script appears here after Auto captions.')); return; }
    let prev = null;
    const frag = document.createDocumentFragment();
    for (const p of ps) {
      if (p.tagOnly) {
        const kind = c.words[p.a].tag;
        frag.append(h('div', { class: 'rs-cap-ptag', title: 'Music / sound (not in the script)' }, `${TAG_ICON[kind] || '♪'} ${fmtClock(p.t0)}–${fmtClock(p.t1)}`));
        continue;
      }
      const head = [];
      if (prefs.timestamps) head.push(h('button', { class: 'rs-cap-time', onclick: () => ctx.seek?.(p.t0) }, fmtClock(p.t0)));
      if (prefs.speakers && hasSpk() && p.spk != null && p.spk !== prev) {
        const s = p.spk;
        head.push(h('button', { class: 'rs-cap-spk s' + s, title: 'Rename speaker', onclick: () => {
          const name = window.prompt('Speaker name', speakerName(c, s));
          if (name != null) apply(setSpeakerName(s, name));
        } }, speakerName(c, s)));
      }
      prev = p.spk;
      const text = c.words.slice(p.a, p.b + 1).filter((w) => !w.tag).map((w) => w.w).join(' ');
      const para = h('div', { class: 'rs-cap-para', contenteditable: 'plaintext-only', spellcheck: 'true', dir: isRtlText(text) ? 'rtl' : 'auto',
        'data-a': p.a, 'data-b': p.b }, text);
      if (para.contentEditable !== 'plaintext-only') para.contentEditable = 'true';
      para.addEventListener('focus', () => { para.dataset.orig = para.textContent; });
      para.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); para.blur(); }
        if (e.key === 'Escape') { para.textContent = para.dataset.orig; para.blur(); }
      });
      para.addEventListener('blur', () => commit(para));
      frag.append(h('div', { class: 'rs-cap-pwrap', role: 'listitem' }, head.length ? h('div', { class: 'rs-cap-phead' }, head) : null, para));
    }
    body.replaceChildren(frag);
  }

  function commit(para) {
    const v = para.textContent.replace(/\s+/g, ' ').trim();
    if (v === (para.dataset.orig || '').replace(/\s+/g, ' ').trim()) return;
    apply(editParagraphCmd(cap(), +para.dataset.a, +para.dataset.b, v));
  }

  render();
  return { render, copy, doExport, prefs, set(p) { Object.assign(prefs, p); tsBox.checked = prefs.timestamps; spBox.checked = prefs.speakers; render(); } };
}
