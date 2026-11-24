// KBB Studio · 🏢 OFFICE TOUR — a standalone template for a walkthrough made of many clips (the owner, 24 Nov 2026:
// "we have around 15+ clips ... i need it as one super template, which will apply [to every] clip, and can be edit
// clip by clip also" ... "a stand alone template with tons of features, and stick in the top menu").
//
// The footage stays as shot: the template works on the joins between clips and around them.
//   one click   Make the office tour: room to move (handles) → transitions in a style → room names → one colour
//               → end card. Every step is its own undo step and each can be run or changed alone.
//   styles      Signature · Calm · Energetic · Minimal · Cinematic — a transition on every join (look/transitions.js)
//   per join    each join: its transition, its length, ▶ watch it (plays the join in the preview)
//   rooms       a name per clip, drawn small, white, no box (look/text.js "room"); on or off
//   colour      one look on every clip, or every clip matched to the first
//   handles     trims a little off each clip's start and end so both clips keep moving through a transition
//   ending      a brand end card after the last clip (name, website, phone)
//   music       a library track, and the joins snapped onto its beats
// Lives in the "Do it for me" bar (🏢 Office tour) and opens as its own tab beside the preview.
import { registerModule } from '../core/modules.js';
import { ops } from '../core/history.js';
import { createItem, itemEnd, findMedia } from '../core/project.js';
import { bus } from '../core/bus.js';
import { h, clear, select, toast } from '../editor/ui.js';
import { KINDS, tourKinds, planCommand, joinCommand, joins, cutsOf, ensureFreezeFrames } from '../look/transitions.js';
import { textItem } from '../look/text.js';

const TOUR = 'office-tour';
export const clipsOf = (p) => (p.tracks.find((t) => t.kind === 'video')?.items || []).filter((i) => i.type === 'clip').slice().sort((a, b) => a.t0 - b.t0);
const plain = (it) => String(it?.name || 'Room').replace(/\.[a-z0-9]{2,4}$/i, '').replace(/^IMG[_-]?\d+$/i, 'Room').trim();

// ── styles: one kind per join, for any number of clips ──
const cycle = (n, first, mid, last) => n <= 0 ? [] : n === 1 ? [mid[0]] : [first, ...Array.from({ length: n - 2 }, (_, i) => mid[i % mid.length]), last];
export const STYLES = {
  signature: { label: 'Signature', note: 'Zoom-through in, calm lens blur, a whip or push now and then, dip to white out', kinds: (n) => tourKinds(n) },
  calm: { label: 'Calm', note: 'Lens blur on every join, a slow dip to white at the end', kinds: (n) => cycle(n, 'lens', ['lens'], 'white') },
  energetic: { label: 'Energetic', note: 'Zoom-through and whip, alternating', kinds: (n) => cycle(n, 'zoom', ['whip', 'zoom', 'slide'], 'white') },
  minimal: { label: 'Minimal', note: 'Soft dissolves only', kinds: (n) => cycle(n, 'dissolve', ['dissolve'], 'white') },
  cinematic: { label: 'Cinematic', note: 'Lens blur with a zoom-through every fourth join', kinds: (n) => cycle(n, 'zoom', ['lens', 'lens', 'lens', 'zoom'], 'white') },
};

const redraw = () => window.kbbEditor?.preview?.draw?.();
async function settle(ctx) { try { await ensureFreezeFrames(ctx.project); } catch (e) { console.warn('[tour]', e.message); } redraw(); }

export async function applyStyle(ctx, style = 'signature') {
  const n = cutsOf(ctx.project).length; if (!n) throw new Error('Put two or more clips one after another on the timeline first');
  const S = STYLES[style] || STYLES.signature;
  ctx.history.apply(planCommand(ctx.project, S.kinds(n), `Office tour · ${S.label}`));
  await settle(ctx);
  return n;
}
export async function setJoin(ctx, i, kind, dur) { const c = joinCommand(ctx.project, i, kind, dur); if (c) { ctx.history.apply(c); await settle(ctx); } }

/** room to move: up to `sec` off each clip's start and end (only where the clip stays ≥ 1 s), clips packed again */
export async function giveHandles(ctx, sec = 0.4) {
  const p = ctx.project, cl = clipsOf(p); if (!cl.length) throw new Error('No clips on the timeline');
  const before = joins(p).map((j) => [j.kind, j.dur]);
  const cmds = []; let t = cl[0].t0, changed = 0;
  for (const c of cl) {
    const m = findMedia(p, c.media), sp = c.speed || 1, dur = m?.dur || c.out;
    let a = c.in, b = c.out;
    if (!c.curve && !c.reverse && m?.kind === 'video') {
      const room = (b - a) - 1;
      const cutIn = Math.max(0, Math.min(sec - a, room / 2)), cutOut = Math.max(0, Math.min(sec - (dur - b), room / 2));
      if (cutIn > 0.01) { a += cutIn; changed++; }
      if (cutOut > 0.01) { b -= cutOut; changed++; }
    }
    if (a !== c.in || b !== c.out || Math.abs(c.t0 - t) > 1e-4) cmds.push(ops.setItem(c.id, { in: +a.toFixed(4), out: +b.toFixed(4), t0: +t.toFixed(4) }, 'Room to move'));
    t += (b - a) / sp;
  }
  if (cmds.length) ctx.history.apply(ops.batch('Office tour · room to move', cmds));
  if (before.some(([k]) => k)) ctx.history.apply(planCommand(ctx.project, before.map((x) => x[0]), 'Office tour · transitions follow', before.map((x) => x[1])));
  await settle(ctx);
  return changed;
}

/** a room name on every clip (names[i] for clip i; empty = none on that clip); replaces the template's earlier ones */
export function setRooms(ctx, names) {
  const p = ctx.project, cl = clipsOf(p), tt = p.tracks.find((t) => t.kind === 'text') || p.tracks.find((t) => t.kind === 'overlay');
  const old = p.tracks.flatMap((t) => t.items).filter((i) => i.tour === 'room').map((i) => i.id);
  const add = [];
  cl.forEach((c, i) => {
    const name = (names?.[i] ?? '').trim(); const d = itemEnd(c) - c.t0;
    if (!name || d < 1.6) return;
    const it = textItem('room', p.canvas, ctx.workspace || {}, +(c.t0 + 0.5).toFixed(3), { text: name, out: +Math.min(3.2, d - 1).toFixed(3), name: 'Room · ' + name });
    it.tour = 'room';
    add.push({ trackId: tt.id, item: it });
  });
  ctx.history.apply(ops.batch(add.length ? 'Office tour · room names' : 'Office tour · no room names', [old.length ? ops.removeItems(old) : null, add.length ? ops.addItems(add) : null].filter(Boolean)));
  redraw();
  return add.length;
}
export const roomNames = (p) => { const cl = clipsOf(p), r = p.tracks.flatMap((t) => t.items).filter((i) => i.tour === 'room');
  return cl.map((c) => r.find((x) => x.t0 >= c.t0 - 0.01 && x.t0 < itemEnd(c))?.text ?? ''); };

/** a brand end card after the last clip */
export async function endCard(ctx, { title, cta, contact, secs = 3.5, on = true } = {}) {
  const p = ctx.project, cl = clipsOf(p); if (!cl.length) throw new Error('No clips on the timeline');
  const ot = p.tracks.find((t) => t.kind === 'overlay') || p.tracks[0];
  const old = p.tracks.flatMap((t) => t.items).filter((i) => i.tour === 'end').map((i) => i.id);
  const add = [];
  if (on) {
    const A = await import('../auto/index.js'), C = await import('../auto/commands.js');
    const kit = await A.brandKit(ctx).catch(() => null);
    const it = createItem('auto.endcard', { t0: +itemEnd(cl[cl.length - 1]).toFixed(3), in: 0, out: secs, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'End card', fadeOut: 0,
      style: { kit: C.kitForItems(kit) || {}, title: title || kit?.fullName || kit?.name || '', cta: cta || 'Visit us', contact: contact || '' } });
    it.tour = 'end'; add.push({ trackId: ot.id, item: it });
  }
  ctx.history.apply(ops.batch(on ? 'Office tour · end card' : 'Office tour · no end card', [old.length ? ops.removeItems(old) : null, add.length ? ops.addItems(add) : null].filter(Boolean)));
  redraw();
}

export async function colourAll(ctx, lookId) { const L = await import('../look/index.js'); L.applyLook(ctx, lookId, { ids: clipsOf(ctx.project).map((c) => c.id) }); redraw(); }
export async function matchAll(ctx) { const L = await import('../look/index.js'), cl = clipsOf(ctx.project); if (cl.length < 2) throw new Error('Two or more clips needed'); await L.matchTo(ctx, cl[0].id); redraw(); }
export async function snapToBeat(ctx) {
  const AU = await import('../audio/index.js');
  const before = joins(ctx.project).map((j) => [j.kind, j.dur]);
  const r = await AU.beatSync(ctx);
  if (before.some(([k]) => k)) ctx.history.apply(planCommand(ctx.project, before.map((x) => x[0]), 'Office tour · transitions follow', before.map((x) => x[1])));
  await settle(ctx); return r;
}

/** ▶ a join in the preview: from 1.2 s before it to 1.2 s after */
export function watchJoin(ctx, i) {
  const ed = window.kbbEditor, j = joins(ctx.project)[i]; if (!ed || !j) return;
  ed.pause?.(); ed.seek(Math.max(0, j.t - 1.2)); ed.play();
  clearTimeout(watchJoin.t); watchJoin.t = setTimeout(() => ed.pause?.(), 2400);
}

/** the whole template in one go */
export async function makeTour(ctx, { style = 'signature', rooms = null, match = true, end = true, endOpts = {}, handles = true } = {}) {
  if (cutsOf(ctx.project).length < 1) throw new Error('Put two or more clips one after another on the timeline first');
  if (handles) await giveHandles(ctx);
  const n = await applyStyle(ctx, style);
  if (rooms) setRooms(ctx, rooms);
  if (match && clipsOf(ctx.project).length > 1) { try { await matchAll(ctx); } catch (e) { console.warn('[tour] match:', e.message); } }
  if (end) await endCard(ctx, endOpts);
  return n;
}

// ── the panel ──
let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  s.textContent = `
.tour { padding-bottom: 24px; display: grid; gap: 4px; }
.tour-note { font-size: 12px; color: var(--muted); margin: 0 0 6px; }
.tour-styles { display: flex; flex-wrap: wrap; gap: 5px; }
.tour-styles .chip { cursor: pointer; }
.tour-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 6px; }
.tour-btns .btn { justify-content: center; padding: 7px 8px; font-size: 12.5px; }
.tour-btns .btn.wide { grid-column: span 2; }
.tour-list { display: grid; gap: 4px; max-height: 360px; overflow: auto; }
.tour-join { display: grid; grid-template-columns: minmax(0, 1fr) auto auto auto; gap: 6px; align-items: center; font-size: 12px; }
.tour-join .nm { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text); }
.tour-join input[type=number] { width: 56px; }
.tour-join .play { padding: 3px 8px; }
.tour-room { display: grid; grid-template-columns: 22px minmax(0, 1fr); gap: 6px; align-items: center; font-size: 12px; }
.tour-room input, .tour-end input { width: 100%; }
.tour-end { display: grid; gap: 6px; }
.tour-head { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); }`;
  document.head.appendChild(s);
}

export function tourPanel(el, ctx) {
  css();
  const root = h('div.tour', { dataset: { x: 'tour' } }); el.appendChild(root);
  const st = { style: 'signature', look: 'clean-clinic' };
  const run = async (label, fn) => {
    try { ctx.progress?.(label, null); const r = await fn(); if (typeof r === 'string') toast(r, 'ok'); return r; }
    catch (e) { toast(`${label}: ${e.message || e}`, 'error'); console.warn('[tour]', e); }
    finally { ctx.progress?.(null, null); }
  };
  const sec = (title, ...kids) => h('details.sec', { open: true }, h('summary', title), h('div.sec-body', kids));
  const head = h('div.tour-head'), list = h('div.tour-list', { dataset: { x: 'tour-joins' } }), rooms = h('div.tour-list', { dataset: { x: 'tour-rooms' } });
  const styleNote = h('p.tour-note');
  const chips = h('div.tour-styles', ...Object.entries(STYLES).map(([k, v]) => h('button.chip' + (k === st.style ? '.on' : ''), { dataset: { style: k }, title: v.note,
    on: { click: (e) => { st.style = k; for (const x of chips.children) x.classList.toggle('on', x === e.currentTarget); styleNote.textContent = v.note; } } }, v.label)));
  styleNote.textContent = STYLES[st.style].note;
  const site = 'theplanetcareers.com';
  const endT = h('input', { placeholder: 'Title (your company name)', dataset: { x: 'end-title' } });
  const endC = h('input', { placeholder: 'Website, e.g. ' + site, dataset: { x: 'end-cta' } });
  const endP = h('input', { placeholder: 'Phone or WhatsApp', dataset: { x: 'end-contact' } });
  const LOOKS_OPTS = [['clean-clinic', 'Clean & bright'], ['original', 'Natural (correction only)']];
  const lookSel = select(LOOKS_OPTS, st.look, (v) => { st.look = v; });
  import('../look/looks.js').then((L) => { const opts = L.LOOKS.map((x) => [x.id, x.label]); clear(lookSel); for (const [v, t] of opts) lookSel.append(h('option', { value: v }, t)); lookSel.value = st.look; }).catch(() => {});

  const names = () => [...rooms.querySelectorAll('input[data-room]')].map((i) => i.value);
  function render() {
    const p = ctx.project, cl = clipsOf(p), js = joins(p);
    const dur = cl.length ? itemEnd(cl[cl.length - 1]) - cl[0].t0 : 0;
    head.textContent = `${cl.length} clip${cl.length === 1 ? '' : 's'} · ${js.length} join${js.length === 1 ? '' : 's'} · ${dur.toFixed(1)} s`;
    clear(list);
    if (!js.length) list.append(h('p.tour-note', 'Put your clips one after another on the timeline (drag them in, in walking order). Each join gets a row here.'));
    for (const j of js) {
      const sel = select([['', 'Cut'], ...Object.entries(KINDS).map(([k, v]) => [k, v.label])], j.kind || '', (v) => run('Join', async () => { await setJoin(ctx, j.i, v || null); return `Join ${j.i + 1}: ${v ? KINDS[v].label : 'Cut'}`; }));
      sel.dataset.join = String(j.i);
      const len = h('input', { type: 'number', min: '0.2', max: '2', step: '0.1', value: j.dur ? j.dur.toFixed(1) : '', placeholder: '—', disabled: !j.kind, title: 'Length in seconds', dataset: { len: String(j.i) } });
      len.addEventListener('change', () => { const v = Math.max(0.2, Math.min(2, +len.value || 0)); if (v) run('Join', async () => { await setJoin(ctx, j.i, undefined, v); }); });
      list.append(h('div.tour-join', { title: `${j.a.name || ''} → ${j.b.name || ''} at ${j.t.toFixed(2)} s` },
        h('span.nm', `${j.i + 1}. ${plain(j.a)} → ${plain(j.b)}`), sel, len,
        h('button.btn.play', { title: 'Watch this join', dataset: { watch: String(j.i) }, on: { click: () => watchJoin(ctx, j.i) } }, '▶')));
    }
    if (!rooms.contains(document.activeElement)) {
      const have = roomNames(p); clear(rooms);
      cl.forEach((c, i) => rooms.append(h('label.tour-room', h('span', String(i + 1)), h('input', { value: have[i] || '', placeholder: plain(c) === 'Room' ? `Room ${i + 1} name` : plain(c), dataset: { room: String(i) } }))));
    }
  }
  const off = bus.on('project:change', () => { if (!root.isConnected) { off?.(); return; } render(); });

  root.append(
    head,
    sec('One click',
      h('p.tour-note', 'Makes the whole tour from your clips in walking order: room to move at every join, a transition on every join in the style below, room names (if typed), one colour across the clips and an end card. Each step can be undone or changed below.'),
      h('div.tour-btns', h('button.btn.primary.wide', { dataset: { act: 'make-tour' }, on: { click: () => run('Office tour', async () => {
        const nm = names(); const n = await makeTour(ctx, { style: st.style, rooms: nm.some((x) => x.trim()) ? nm : null, endOpts: { title: endT.value, cta: endC.value || site, contact: endP.value } });
        return `Office tour made: ${n} join${n === 1 ? '' : 's'}, ${STYLES[st.style].label} style`; }) } }, '🏢 Make the office tour'))),
    sec('Style',
      chips, styleNote,
      h('div.tour-btns', h('button.btn.wide', { dataset: { act: 'apply-style' }, on: { click: () => run('Style', async () => { const n = await applyStyle(ctx, st.style); return `${STYLES[st.style].label} on ${n} join${n === 1 ? '' : 's'}`; }) } }, 'Apply this style to every join'))),
    sec('Joins (clip by clip)', h('p.tour-note', 'Change any join’s transition or length; ▶ plays it in the preview.'), list),
    sec('Room names',
      h('p.tour-note', 'One line per clip, small white text with no box. Leave a line empty for no name on that clip.'), rooms,
      h('div.tour-btns',
        h('button.btn.primary', { dataset: { act: 'rooms' }, on: { click: () => run('Room names', async () => { const n = setRooms(ctx, names()); return n ? `${n} room name${n === 1 ? '' : 's'} on the video` : 'Type a name on at least one line'; }) } }, 'Show room names'),
        h('button.btn', { on: { click: () => run('Room names', async () => { setRooms(ctx, []); return 'Room names removed'; }) } }, 'Remove'))),
    sec('Colour',
      h('label.row', h('span.lbl', 'Look'), lookSel),
      h('div.tour-btns',
        h('button.btn', { on: { click: () => run('Colour', async () => { await colourAll(ctx, st.look); return 'One look on every clip'; }) } }, 'Same look on all'),
        h('button.btn', { on: { click: () => run('Colour', async () => { await matchAll(ctx); return 'Every clip matched to the first'; }) } }, 'Match all to clip 1'))),
    sec('Smooth moves',
      h('p.tour-note', 'Trims about 0.4 s off each clip’s start and end (never below 1 s) so both clips keep moving through every transition. Your transitions follow.'),
      h('div.tour-btns', h('button.btn.wide', { dataset: { act: 'handles' }, on: { click: () => run('Room to move', async () => { const n = await giveHandles(ctx); return n ? `Room to move on ${n} clip edge${n === 1 ? '' : 's'}` : 'Every clip already has room to move'; }) } }, 'Give every join room to move'))),
    sec('Ending',
      h('div.tour-end', endT, endC, endP),
      h('div.tour-btns',
        h('button.btn.primary', { dataset: { act: 'end-card' }, on: { click: () => run('End card', async () => { await endCard(ctx, { title: endT.value, cta: endC.value || site, contact: endP.value }); return 'End card after the last clip'; }) } }, 'Add end card'),
        h('button.btn', { on: { click: () => run('End card', async () => { await endCard(ctx, { on: false }); return 'End card removed'; }) } }, 'Remove'))),
    sec('Music',
      h('div.tour-btns',
        h('button.btn', { on: { click: () => run('Music', async () => { const M = (await import('../core/modules.js')).getModule('add-music'); if (!M) throw new Error('The music library is not available'); return await M.run(ctx); }) } }, '🎵 Add music'),
        h('button.btn', { on: { click: () => run('Beat', async () => { const r = await snapToBeat(ctx); return `${r?.moved || 0} join${r?.moved === 1 ? '' : 's'} moved onto the beat`; }) } }, 'Joins on the beat'))),
  );
  render();
}

// the "Do it for me" button, and the tab it opens beside the preview
registerModule({ id: TOUR, label: 'Office tour', icon: '🏢', run: (ctx) => { ctx.openPanel?.('tour'); return null; } });
registerModule({ id: 'tour', label: 'Office tour', icon: '🏢', tab: { label: 'Tour', icon: '🏢', order: 9 }, panel: (el, ctx) => tourPanel(el, ctx) });
