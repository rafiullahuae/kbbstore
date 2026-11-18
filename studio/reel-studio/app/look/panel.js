// KBB Studio · look · the "🎨 Look" tab: every control is one click with a finished, undoable result.
import { h, clear, slider, select, toast } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { findItem, srcTime } from '../core/project.js';
import { ops } from '../core/history.js';
import { LOOKS, gradeFx } from './looks.js';
import { processLook } from './fx.js';
import { KINDS } from './transitions.js';
import { CURVES, EASES, rampPlan } from './ramp.js';
import { PRESETS } from './text.js';
import { hoverPreview, lookSpec, transitionSpec } from '../themes/hover.js';
import { bgControls } from './bg-panel.js';

let cssDone = false;
export function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  s.textContent = `
.lk { padding-bottom: 24px; }
.lk-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.lk-tile { border: 2px solid transparent; background: var(--panel2); border-radius: 9px; padding: 0 0 4px; font-size: 11px; color: var(--text); cursor: pointer; overflow: hidden; text-align: center; }
.lk-tile canvas { display: block; width: 100%; aspect-ratio: 3 / 4; height: auto; background: #111; }
.lk-tile.on { border-color: var(--ws); }
.lk-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.lk-chips .chip { cursor: pointer; }
.lk-note { font-size: 12px; color: var(--muted); margin: 0; }
.lk-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.lk-btns .btn { justify-content: center; padding: 7px 8px; font-size: 12.5px; }
.lk-btns .btn.wide { grid-column: span 2; }
.lk-txt { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.lk-txt .btn { justify-content: flex-start; font-size: 12px; padding: 7px 8px; white-space: normal; text-align: left; }
.lk textarea { width: 100%; min-height: 52px; resize: vertical; }`;
  document.head.appendChild(s);
}

export function lookPanel(el, ctx, A) {
  css();
  const root = h('div.lk'); el.appendChild(root);
  const st = { amount: 1, strength: 0.35, eyes: 0.5, under: 0.5, trans: 'whip', tdur: 0, curve: 'slow-fast-slow', min: 0.4, max: 2.5, ease: 'sine', audio: 'mute' };
  const run = async (label, fn) => {
    try { ctx.progress?.(label, null); const r = await fn(); if (typeof r === 'string') toast(r, 'ok'); return r; }
    catch (e) { toast(`${label}: ${e.message || e}`, 'error'); console.warn('[look]', e); }
    finally { ctx.progress?.(null, null); }
  };
  const sec = (title, ...kids) => h('details.sec', { open: true }, h('summary', title), h('div.sec-body', kids));
  const targetNote = h('p.lk-note');
  const tiles = h('div.lk-tiles');
  const textEdit = h('div');

  function scopeText() {
    const n = A.clipTargets(ctx).length, sel = (ctx.selection?.() || []).map((id) => findItem(ctx.project, id)?.item).filter((i) => i?.type === 'clip').length;
    targetNote.textContent = n ? (sel ? `Applies to the ${n} selected clip${n > 1 ? 's' : ''}` : `Applies to all ${n} clip${n > 1 ? 's' : ''} (select clips to narrow it)`) : 'Add a video to start';
  }

  // ── looks ──
  const tileEls = LOOKS.map((L) => {
    const cv = h('canvas', { width: 96, height: 128 });
    const b = h('button.lk-tile', { title: L.hint, dataset: { look: L.id }, on: { click: () => run('Look', () => A.applyLook(ctx, L.id, { amount: st.amount })).then(mark) } }, cv, h('div', L.label));
    hoverPreview(b, lookSpec(L.id, cv, () => thumbs()));   // hover: the look on a moving clip; leaving restores the still
    tiles.appendChild(b); return { L, cv, b };
  });
  function mark() {
    const it = A.clipTargets(ctx)[0], cur = (it?.fx || []).find((f) => f.type === 'look.grade')?.look || 'original';
    for (const t of tileEls) t.b.classList.toggle('on', t.L.id === cur);
  }
  let thumbJob = 0;
  async function thumbs() {
    const job = ++thumbJob, it = A.clipTargets(ctx)[0];
    if (!it || !ctx.media) return;
    const t = Math.min(Math.max(ctx.playhead?.() ?? it.t0, it.t0), it.t0 + (it.out - it.in) / (it.speed || 1) - 0.05);
    let bm; try { bm = await ctx.media.frameAt(it.media, srcTime(it, t), 192); } catch { return; }
    if (job !== thumbJob || !bm) return;
    const base = (it.fx || []).find((f) => f.type === 'look.grade') || gradeFx();
    for (const { L, cv } of tileEls) {
      const fake = { id: 'thumb', type: 'clip', media: it.media, t0: 0, in: 0, out: 1, speed: 1, fx: [Object.assign({}, base, { look: L.id, amount: 1 })] };
      const out = processLook(bm, fake, 0, ctx.project, { box: { w: 96, h: 128 }, scale: 1, preview: true });
      const c = cv.getContext('2d'); c.fillStyle = '#111'; c.fillRect(0, 0, 96, 128);
      const src = out || bm, sw = out ? out.width : bm.width, sh = out ? out.height : bm.height, k = Math.max(96 / sw, 128 / sh);
      c.drawImage(src, (96 - sw * k) / 2, (128 - sh * k) / 2, sw * k, sh * k);
    }
    bm.close?.();
  }

  // ── text editing for a selected look.text ──
  function renderTextEdit() {
    clear(textEdit);
    const sel = (ctx.selection?.() || []).map((id) => findItem(ctx.project, id)?.item).filter((i) => i?.type === 'look.text');
    if (sel.length !== 1) return;
    const it = sel[0], s = it.style || {};
    const set = (props, label) => ctx.history.apply(ops.setItem(it.id, props, label));
    const ta = h('textarea', { value: it.text || '', 'aria-label': 'Text' }); ta.addEventListener('input', () => set({ text: ta.value }, 'Edit text'));
    textEdit.append(h('p.lk-note', `Editing: ${PRESETS[s.preset]?.label || 'text'}`), ta);
    if ('sub' in s && s.sub != null) { const sub = h('input', { value: s.sub, 'aria-label': 'Second line' }); sub.addEventListener('input', () => set({ style: Object.assign({}, s, { sub: sub.value }) }, 'Edit text')); textEdit.append(sub); }
    if (s.items) { const li = h('textarea', { value: s.items.join('\n'), 'aria-label': 'Checklist lines' }); li.addEventListener('input', () => set({ style: Object.assign({}, s, { items: li.value.split('\n').filter(Boolean) }) }, 'Edit checklist')); textEdit.append(li); }
    if (s.value != null) { const v = h('input', { type: 'number', value: s.value, 'aria-label': 'Number' }); v.addEventListener('input', () => set({ style: Object.assign({}, s, { value: +v.value || 0 }) }, 'Edit number')); textEdit.append(v); }
    const col = (k, label) => { const c = h('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(s.colors?.[k] || '') ? s.colors[k] : '#ffffff', title: label }); c.addEventListener('input', () => set({ style: Object.assign({}, s, { colors: Object.assign({}, s.colors, { [k]: c.value }) }) }, 'Text colour')); return c; };
    textEdit.append(h('div.lk-chips', col('primary', 'Main colour'), col('accent', 'Accent'), col('text', 'Text colour')));
  }

  const rampInfo = h('p.lk-note');
  function rampNote() {
    const it = (ctx.selection?.() || []).map((id) => findItem(ctx.project, id)?.item).find((i) => i?.type === 'clip');
    if (!it) { rampInfo.textContent = 'Select one clip on the timeline.'; return; }
    const p = rampPlan(it, st);
    rampInfo.textContent = `${((it.out - it.in) / (it.speed || 1)).toFixed(2)} s → ${p.duration.toFixed(2)} s`;
  }

  root.append(
    h('div.insp-head', h('b', '🎨 Look'), targetNote),
    sec('Colour look',
      tiles,
      slider({ label: 'Strength', min: 0, max: 1, step: 0.05, value: st.amount, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.amount = v; const items = A.clipTargets(ctx).filter((i) => (i.fx || []).some((f) => f.type === 'look.grade')); if (items.length) ctx.history.apply(ops.setItems(items.map((i) => ({ id: i.id, props: { fx: i.fx.map((f) => (f.type === 'look.grade' ? Object.assign({}, f, { amount: v }) : f)) } })), 'Look strength')); }, onDone: () => ctx.history.seal() }),
      h('div.lk-btns',
        h('button.btn', { title: 'White balance and exposure, measured from the clip', on: { click: () => run('Auto colour', async () => { const r = await A.autoColour(ctx); return `Auto colour on ${r.length} clip${r.length > 1 ? 's' : ''}`; }) } }, '✨ Auto colour'),
        h('button.btn', { title: 'Match every other clip to the selected one', on: { click: () => run('Match clips', async () => { const sel = A.clipTargets(ctx)[0]; const r = await A.matchTo(ctx, sel?.id); return `${r.matched} clip${r.matched > 1 ? 's' : ''} matched to this one`; }) } }, '🎯 Match all to this'),
        h('button.btn.wide', { on: { click: () => run('Look', () => (A.clearFx(ctx, 'look.grade'), 'Colour back to original')) } }, '↺ Original colour'))),
    sec('✨ Enhance video',
      h('p.lk-note', 'Measures each clip, then fixes exposure, white balance, dark footage, grain and sharpness, keeping skin natural. Fine-tune every part in the clip’s 🎚 Adjust (Inspector).'),
      h('div.lk-btns',
        h('button.btn.primary', { dataset: { act: 'enhance-video' }, on: { click: () => run('Enhance video', async () => { const r = await A.enhanceVideo(ctx); return `Enhanced ${r.length} clip${r.length === 1 ? '' : 's'}`; }) } }, '✨ Enhance video'),
        h('button.btn', { title: 'Split the preview: untouched on the left, drag the divider', on: { click: () => { const on = A.toggleCompare(); toast(on ? 'Before / after: drag the divider on the preview' : 'Before / after off', 'ok'); } } }, '◧ Before / after'))),
    sec('Skin touch-up',
      h('p.lk-note', 'Finds the face (offline) and smooths skin while keeping its texture. Subtle by default.'),
      slider({ label: 'Smoothing', min: 0, max: 1, step: 0.05, value: st.strength, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.strength = v; liveTouch(); }, onDone: () => ctx.history.seal() }),
      slider({ label: 'Eyes', min: 0, max: 1, step: 0.05, value: st.eyes, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.eyes = v; liveTouch(); }, onDone: () => ctx.history.seal() }),
      slider({ label: 'Under-eye', min: 0, max: 1, step: 0.05, value: st.under, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.under = v; liveTouch(); }, onDone: () => ctx.history.seal() }),
      h('div.lk-btns',
        h('button.btn.primary', { on: { click: () => run('Touch-up', async () => { const r = await A.touchUp(ctx, st); return r.withFaces ? `Touch-up on ${r.withFaces} clip${r.withFaces > 1 ? 's' : ''} with a face` : 'Touch-up added — no face found yet in these clips'; }) } }, '💄 Touch up'),
        h('button.btn', { on: { click: () => run('Touch-up', () => (A.clearFx(ctx, 'look.touchup'), 'Touch-up removed')) } }, 'Remove'))),
    sec('Background',
      h('p.lk-note', 'Cuts out the person, the main subject (a product) or a green screen, offline, with clean steady edges — then puts a new background behind, or nothing (transparent).'),
      bgControls(ctx, { chipCls: '.lk-chips', ids: () => undefined })),
    sec('Transitions',
      h('div.lk-chips', ...Object.entries(KINDS).map(([k, v]) => { const c = h('button.chip' + (k === st.trans ? '.on' : ''), { dataset: { trans: k }, on: { click: () => { st.trans = k; for (const x of c.parentNode.children) x.classList.toggle('on', x === c); } } }, v.label); return hoverPreview(c, transitionSpec(k, v.label)); })),
      slider({ label: 'Length', min: 0, max: 1.2, step: 0.05, value: 0, fmt: (v) => (v ? v.toFixed(2) + ' s' : 'auto'), onInput: (v) => { st.tdur = v; } }),
      h('div.lk-btns',
        h('button.btn.primary', { on: { click: () => run('Transitions', async () => { const n = await A.addTransitions(ctx, st.trans, { dur: st.tdur || null }); return `${KINDS[st.trans].label} on every cut (${n} in the edit)`; }) } }, 'On every cut'),
        h('button.btn', { on: { click: () => run('Transition', async () => { await A.addTransitions(ctx, st.trans, { at: ctx.playhead(), dur: st.tdur || null }); return `${KINDS[st.trans].label} on the nearest cut`; }) } }, 'Nearest cut'),
        h('button.btn.wide', { title: 'Moves the next clip’s start to the frame that best continues the last one', on: { click: () => run('Match cut', async () => { const s = await A.matchCut(ctx); return s ? `Match cut: next clip starts ${s > 0 ? '+' : ''}${s.toFixed(2)} s` : 'Already the best match'; }) } }, '🔗 Match-cut helper'))),
    sec('Speed ramp',
      h('label.row', h('span.lbl', 'Curve'), select(Object.entries(CURVES).map(([k, v]) => [k, v.label]), st.curve, (v) => { st.curve = v; rampNote(); })),
      h('label.row', h('span.lbl', 'Easing'), select(Object.entries(EASES).map(([k, v]) => [k, v.label]), st.ease, (v) => { st.ease = v; rampNote(); })),
      slider({ label: 'Slowest', min: 0.2, max: 1, step: 0.05, value: st.min, fmt: (v) => v.toFixed(2) + '×', onInput: (v) => { st.min = v; rampNote(); } }),
      slider({ label: 'Fastest', min: 1, max: 6, step: 0.1, value: st.max, fmt: (v) => v.toFixed(1) + '×', onInput: (v) => { st.max = v; rampNote(); } }),
      h('label.row', h('span.lbl', 'Sound'), select([['mute', 'Mute'], ['pitch', 'Keep (natural pitch)']], st.audio, (v) => { st.audio = v; })),
      rampInfo,
      h('div.lk-btns', h('button.btn.primary.wide', { on: { click: () => run('Speed ramp', async () => {
        const it = (ctx.selection?.() || []).map((id) => findItem(ctx.project, id)?.item).find((i) => i?.type === 'clip');
        if (!it) throw new Error('Select one clip on the timeline');
        const r = await A.speedRamp(ctx, it.id, st); return `Speed ramp: ${r.pieces} steps, now ${r.duration.toFixed(2)} s`;
      }) } }, '⏩ Ramp the selected clip'))),
    sec('Text animation',
      h('div.lk-txt', ...Object.entries(PRESETS).map(([k, v]) => h('button.btn', { dataset: { text: k }, on: { click: () => run('Text', () => { A.addText(ctx, k); return `${v.label} added at the playhead`; }) } }, v.label))),
      textEdit),
  );

  function liveTouch() {
    const items = A.clipTargets(ctx).filter((i) => (i.fx || []).some((f) => f.type === 'look.touchup'));
    if (items.length) ctx.history.apply(ops.setItems(items.map((i) => ({ id: i.id, props: { fx: i.fx.map((f) => (f.type === 'look.touchup' ? Object.assign({}, f, { strength: st.strength, eyes: st.eyes, under: st.under }) : f)) } })), 'Touch-up strength'));
  }

  let tt = null;
  const refresh = () => { scopeText(); mark(); renderTextEdit(); rampNote(); clearTimeout(tt); tt = setTimeout(thumbs, 250); };
  const offs = [bus.on('selection', refresh), bus.on('project:load', refresh), bus.on('pause', () => { clearTimeout(tt); tt = setTimeout(thumbs, 250); }),
    bus.on('project:change', () => { scopeText(); mark(); if (!root.contains(document.activeElement)) renderTextEdit(); })];
  refresh();
  return { destroy() { for (const o of offs) o(); clearTimeout(tt); root.remove(); } };
}
