// KBB Studio · cutaways — the "🎞 Cutaways" tab: mark a range, replace the picture, edit the selected cutaway (fit,
// transitions, its own sound, slide designer), the list, and Auto cutaways (proposals he ticks before anything moves).
import { h, clear, slider, select, seg, toast, fmtTime } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { findItem, itemDur, itemEnd } from '../core/project.js';
import { cutaways, isCutaway, FITS, TRANSITIONS } from './model.js';
import { defaultSlide, THEMES, ANIMS } from './slide.js';
import { catalogueFor, readJSON } from '../content/lib/catalogue.js';

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  s.textContent = `
.cw { padding-bottom: 20px; }
.cw-note { font-size: 12px; color: var(--muted); margin: 0 0 6px; }
.cw-marks { display: flex; gap: 6px; align-items: center; font-size: 12.5px; margin-bottom: 6px; flex-wrap: wrap; }
.cw-marks b { font-variant-numeric: tabular-nums; }
.cw-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.cw-btns .btn { justify-content: center; padding: 7px 8px; font-size: 12.5px; }
.cw-btns .btn.wide { grid-column: span 2; }
.cw-words { max-height: 112px; overflow: auto; display: flex; flex-wrap: wrap; gap: 3px; padding: 4px; background: var(--panel2); border-radius: 8px; margin-bottom: 6px; }
.cw-words span { font-size: 12px; padding: 1px 5px; border-radius: 5px; cursor: pointer; user-select: none; }
.cw-words span.on { background: rgba(255,209,102,.4); }
.cw-words span:hover { background: rgba(255,255,255,.1); }
.cw-list { display: flex; flex-direction: column; gap: 4px; }
.cw-row { display: flex; gap: 6px; align-items: center; font-size: 12px; padding: 5px 6px; border-radius: 7px; background: var(--panel2); cursor: pointer; }
.cw-row.on { outline: 2px solid var(--ws); }
.cw-row .grow { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cw-pick { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5px; max-height: 210px; overflow: auto; }
.cw-pick button { font-size: 11px; padding: 4px; border-radius: 7px; background: var(--panel2); color: var(--text); border: 1px solid transparent; cursor: pointer; text-align: center; overflow: hidden; }
.cw-pick button:hover { border-color: var(--ws); }
.cw-pick img { display: block; width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 5px; background: #111; }
.cw textarea, .cw input[type=text] { width: 100%; box-sizing: border-box; }
.cw textarea { min-height: 54px; resize: vertical; }
.cw-sg { display: flex; gap: 6px; align-items: center; font-size: 12px; padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,.06); }
.cw-sg .grow { flex: 1; }
.cw-icons { display: flex; flex-wrap: wrap; gap: 3px; }
.cw-icons button { font-size: 16px; padding: 2px 5px; background: var(--panel2); border: 1px solid transparent; border-radius: 6px; cursor: pointer; }
.cw-icons button.on { border-color: var(--ws); }`;
  document.head.appendChild(s);
}
const ICONS = ['', '✨', '🔥', '💖', '🛍️', '💸', '🎁', '✅', '📍', '✈️', '🎓', '🌍', '🧴', '💄', '🧰', '📞', '⏰', '🏆'];
const url = (src) => '/' + String(src).split('/').map(encodeURIComponent).join('/');

export function cutawayPanel(el, ctx, A) {
  css();
  const root = h('div.cw'); el.appendChild(root);
  const sec = (title, ...kids) => h('details.sec', { open: true }, h('summary', title), h('div.sec-body', kids));
  const run = async (label, fn) => { try { const r = await fn(); if (typeof r === 'string') toast(r, 'ok'); return r; } catch (e) { toast(`${label}: ${e.message || e}`, 'error'); console.warn('[cutaways]', e); } };
  const st = { fit: 'fill', tin: 'dissolve', tout: 'dissolve' };   // what the next cutaway is made with

  // ── mark ──
  const markLine = h('div.cw-marks'), words = h('div.cw-words', { title: 'Click a word, then Shift-click another, to mark what is said between them' });
  let wordA = null;
  const markBtns = h('div.cw-btns',
    h('button.btn', { on: { click: () => A.markIn(ctx) } }, '⇤ Mark in (I)'), h('button.btn', { on: { click: () => A.markOut(ctx) } }, 'Mark out (O) ⇥'));
  // ── replace with ──
  const pickBox = h('div');
  const fitSeg = seg(Object.entries(FITS).map(([k, l]) => [k, { fill: 'Fill', blur: 'Fit + blur', pip: 'Bubble', split: 'Split' }[k], l]), st.fit, (v) => { st.fit = v; });
  const tinSel = select(Object.entries(TRANSITIONS), st.tin, (v) => { st.tin = v; }), toutSel = select(Object.entries(TRANSITIONS), st.tout, (v) => { st.tout = v; });
  const add = (o) => run('Cutaway', async () => { const it = await A.addCutaway(ctx, { ...o, style: { fit: st.fit, tin: st.tin, tout: st.tout, ...(o.style || {}) } }); render(); return `${it.name} · ${itemDur(it).toFixed(2)} s`; });
  const replaceBtns = h('div.cw-btns',
    h('button.btn.primary', { on: { click: () => showPick('media') } }, '🖼 Image or video'),
    h('button.btn.primary', { on: { click: () => add({ slide: defaultSlide(ctx.workspace) }) } }, '▤ New slide'),
    h('button.btn', { on: { click: () => showPick('product') } }, '🛍 Product'), h('button.btn', { on: { click: () => showPick('country') } }, '🌍 Country'),
    h('button.btn.wide', { on: { click: () => showPick('occupation') } }, '👷 Occupation'));
  // ── selected ──
  const selBox = h('div');
  // ── list + auto ──
  const list = h('div.cw-list'), sugBox = h('div');

  root.append(
    sec('1 · Mark the range', h('p.cw-note', 'Press I and O while playing, drag on the 🎞 Cutaways strip under the ruler, or pick the words below. The voice and captions keep playing; only the picture changes.'), markLine, markBtns, words),
    sec('2 · Show instead', h('p.cw-note', 'Fit'), fitSeg, h('div.cw-btns', h('label.row', h('span.lbl', 'In'), tinSel), h('label.row', h('span.lbl', 'Out'), toutSel)), replaceBtns, pickBox),
    sec('Selected cutaway', selBox),
    sec('Cutaways in this reel', list),
    sec('✨ Auto cutaways', h('p.cw-note', 'Suggests a cutaway wherever you name a product, a price, a country or a job, or say a word your own clips are tagged with. Tick the ones you want.'),
      h('button.btn.wide', { on: { click: () => suggest() } }, '✨ Suggest from what I say'), sugBox));

  function renderMarks() {
    const m = A.marks();
    clear(markLine).append(h('span', 'In ', h('b', m.in != null ? fmtTime(m.in, ctx.project.canvas.fps, true) : '—')), h('span', 'Out ', h('b', m.out != null ? fmtTime(m.out, ctx.project.canvas.fps, true) : '—')),
      ...[m.in != null && m.out != null ? h('span', `(${(m.out - m.in).toFixed(2)} s)`) : null,
        m.in != null ? h('button.chip', { on: { click: () => A.setMarks({}) } }, 'Clear') : null].filter(Boolean));
    for (const b of replaceBtns.querySelectorAll('button')) b.disabled = m.in == null || m.out == null;
    for (const sp of words.children) { const i = +sp.dataset.i, w = ctx.project.captions.words[i]; sp.classList.toggle('on', !!w && m.in != null && m.out != null && w.t0 >= m.in - 0.01 && w.t1 <= m.out + 0.05); }
  }
  let wordsAt = -1;
  function renderWords() {
    const ws = ctx.project.captions?.words || [], t = ctx.playhead();
    if (!ws.length) { clear(words).append(h('span.cw-note', 'No captions yet: words appear here after Auto captions.')); return; }
    const a = Math.max(0, t - 12), b = t + 12, key = Math.floor(a / 6) + ':' + ws.length;
    if (key === wordsAt) return; wordsAt = key;
    clear(words);
    ws.forEach((w, i) => { if (w.t1 < a || w.t0 > b) return; words.appendChild(h('span', { dataset: { i }, title: `${w.t0.toFixed(2)} s`, on: { click: (e) => { if (e.shiftKey && wordA != null) A.markWords(ctx, wordA, i); else { wordA = i; A.markWords(ctx, i, i); ctx.seek(w.t0); } } } }, w.w)); });
    renderMarks();
  }

  async function showPick(kind) {
    clear(pickBox);
    const grid = h('div.cw-pick'), close = h('button.chip', { on: { click: () => clear(pickBox) } }, 'Close');
    pickBox.append(h('p.cw-note', { media: 'Pick from this reel’s media (or import a file):', product: 'Products:', country: 'Countries:', occupation: 'Jobs:' }[kind], ' ', close), grid);
    if (kind === 'media') {
      const speaker = new Set(ctx.project.tracks.filter((t) => t.kind === 'video').flatMap((t) => t.items.map((i) => i.media)));
      for (const m of ctx.project.media.filter((m) => (m.kind === 'image' || m.kind === 'video') && !speaker.has(m.id)))
        grid.appendChild(h('button', { title: m.name, on: { click: () => add({ media: m }) } }, m.kind === 'image' ? h('img', { src: url(m.src), alt: '' }) : h('div', { style: { aspectRatio: '1', display: 'grid', placeItems: 'center', background: '#111', borderRadius: '5px' } }, '🎬'), m.name));
      const inp = h('input', { type: 'file', accept: 'image/*,video/*', hidden: true });
      inp.addEventListener('change', async () => { const ed = window.kbbEditor; const got = await ed.importFiles([...inp.files], { addToTimeline: false }); if (got[0]) add({ media: got[0] }); });
      grid.appendChild(h('button', { on: { click: () => inp.click() } }, h('div', { style: { aspectRatio: '1', display: 'grid', placeItems: 'center', fontSize: '22px' } }, '＋'), 'Import…'), inp);
      return;
    }
    if (kind === 'occupation') {
      const occ = await readJSON('library/occupations/occupations.json').then((j) => j.occupations).catch(() => []);
      for (const o of occ.slice(0, 100)) grid.appendChild(h('button', { on: { click: () => add({ slide: defaultSlide(ctx.workspace, { title: o.name.en, icon: '🧰', bullets: (o.tools || []).slice(0, 3).map((t) => t.en) }) }) } }, o.name.en));
      return;
    }
    const cat = await catalogueFor(kind === 'product' ? { industry: 'beauty' } : { industry: 'visa' });
    if (kind === 'product') for (const p of cat.products.slice(0, 120)) grid.appendChild(h('button', { title: `${p.brand} ${p.name}`, on: { click: () => add({ slide: defaultSlide(ctx.workspace, { theme: 'minimal', title: `${p.brand} ${p.name}`, image: p.src, anim: 'pop' }) }) } }, h('img', { src: url(p.src), alt: '', loading: 'lazy' }), p.name));
    else for (const c of cat.countries) grid.appendChild(h('button', { title: c.name, on: { click: () => add({ src: c.landmarkSrc || c.flagSrc, name: c.name }) } }, h('img', { src: url(c.flagSrc), alt: '', loading: 'lazy' }), c.name));
  }

  let selId = null;
  function renderSelected(force = false) {
    const id = (ctx.selection?.() || []).find((x) => isCutaway(findItem(ctx.project, x)?.item));
    // typing in the slide designer: keep the fields (and the caret) while the same cutaway stays selected
    if (!force && id && id === selId && selBox.contains(document.activeElement) && /^(INPUT|TEXTAREA)$/.test(document.activeElement.tagName)) return;
    const fresh = id && id !== selId;
    selId = id; clear(selBox);
    if (fresh && el.offsetParent) requestAnimationFrame(() => selBox.parentElement?.parentElement?.scrollIntoView({ block: 'start', behavior: 'instant' }));
    const it = id && findItem(ctx.project, id).item;
    if (!it) { selBox.append(h('p.cw-note', 'Select a cutaway (on the timeline, the strip, or the list below) to change it.')); return; }
    const s = it.style || {}, fpsx = ctx.project.canvas.fps || 30, fr = 1 / fpsx;
    const up = (patch, label, key) => A.updateCutaway(ctx, it.id, patch, label, key);
    const edge = (which, d) => {
      const t0 = it.t0, end = itemEnd(it);
      if (which === 'in') { const n = Math.max(0, Math.min(end - fr, t0 + d)); up({ t0: n, out: +(it.out - (n - t0) * (it.speed || 1)).toFixed(4) }, 'Cutaway start'); }
      else { const n = Math.max(t0 + fr, end + d); up({ out: +(it.in + (n - t0) * (it.speed || 1)).toFixed(4) }, 'Cutaway end'); }
    };
    selBox.append(...[
      h('p.cw-note', h('b', it.name), ` · ${fmtTime(it.t0, fpsx, true)} → ${fmtTime(itemEnd(it), fpsx, true)} (${itemDur(it).toFixed(2)} s)${it.link ? ' · follows the clip under it' : ''}`),
      h('div.cw-marks', 'Start', h('button.chip', { on: { click: () => edge('in', -fr) } }, '−1f'), h('button.chip', { on: { click: () => edge('in', fr) } }, '+1f'),
        'End', h('button.chip', { on: { click: () => edge('out', -fr) } }, '−1f'), h('button.chip', { on: { click: () => edge('out', fr) } }, '+1f')),
      seg(Object.entries(FITS).map(([k, l]) => [k, { fill: 'Fill', blur: 'Fit + blur', pip: 'Bubble', split: 'Split' }[k], l]), s.fit || 'fill', (v) => up({ style: { fit: v } }, 'Cutaway fit')),
      s.fit === 'pip' ? seg([['tl', '↖'], ['tr', '↗'], ['bl', '↙'], ['br', '↘']], s.corner || 'tr', (v) => up({ style: { corner: v } }, 'Bubble corner')) : null,
      h('div.cw-btns', h('label.row', h('span.lbl', 'In'), select(Object.entries(TRANSITIONS), s.tin || 'none', (v) => up({ style: { tin: v } }, 'Transition in'))),
        h('label.row', h('span.lbl', 'Out'), select(Object.entries(TRANSITIONS), s.tout || 'none', (v) => up({ style: { tout: v } }, 'Transition out')))),
      slider({ label: 'Transition', min: 0, max: 1, step: 0.05, value: s.tdur ?? 0.25, fmt: (v) => v.toFixed(2) + ' s', onInput: (v) => up({ style: { tdur: v } }, 'Transition length', 'tdur') }),
      !s.slide ? slider({ label: 'Push-in', min: 0, max: 0.2, step: 0.01, value: s.kb ?? 0.08, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => up({ style: { kb: v } }, 'Ken Burns', 'kb') }) : null,
      it.media && ctx.project.media.find((m) => m.id === it.media)?.kind === 'video' ? slider({ label: 'Its own sound', min: 0, max: 1, step: 0.05, value: s.mix || 0, fmt: (v) => (v ? Math.round(v * 100) + '%' : 'muted'), onInput: (v) => up({ mix: v }, 'Cutaway sound', 'mix') }) : null,
      s.slide ? slideEditor(it, s.slide, (patch, label, key) => up({ style: { slide: patch } }, label, key)) : null,
      h('div.cw-btns', h('button.btn.danger.wide', { on: { click: () => { A.removeCutaway(ctx, it.id); render(); } } }, '🗑 Remove cutaway'))].filter(Boolean));
  }

  function slideEditor(it, sl, set) {
    const box = h('div');
    const title = h('input', { type: 'text', value: sl.title || '', placeholder: 'Headline' }); title.addEventListener('input', () => set({ title: title.value }, 'Slide title', 'sltitle'));
    const bullets = h('textarea', { value: (sl.bullets || []).join('\n'), placeholder: 'Bullet points, one per line' }); bullets.addEventListener('input', () => set({ bullets: bullets.value.split('\n').map((x) => x.trim()).filter(Boolean) }, 'Slide points', 'slbul'));
    const price = h('input', { type: 'text', value: sl.price || '', placeholder: 'Price (e.g. AED 99)' }); price.addEventListener('input', () => set({ price: price.value }, 'Slide price', 'slprice'));
    const offer = h('input', { type: 'text', value: sl.offer || '', placeholder: 'Offer (e.g. 20% off today)' }); offer.addEventListener('input', () => set({ offer: offer.value }, 'Slide offer', 'sloffer'));
    const icons = h('div.cw-icons', ICONS.map((ic) => h('button' + ((sl.icon || '') === ic ? '.on' : ''), { title: ic || 'No icon', on: { click: () => set({ icon: ic }, 'Slide icon') } }, ic || '∅')));
    const logo = h('input', { type: 'checkbox', checked: !!sl.logo, disabled: !ctx.workspace?.logo, on: { change: () => set({ logo: logo.checked ? ctx.workspace?.logo : null }, 'Slide logo') } });
    const imgs = ctx.project.media.filter((m) => m.kind === 'image');
    const bg = select([['brand', 'Brand colours'], ['solid', 'Solid colour'], ...imgs.map((m) => ['img:' + m.src, 'Photo · ' + m.name])], sl.bg?.kind === 'image' ? 'img:' + sl.bg.src : sl.bg?.kind || 'brand',
      (v) => set({ bg: v.startsWith('img:') ? { kind: 'image', src: v.slice(4) } : { kind: v, c1: sl.colors?.primary } }, 'Slide background'));
    box.append(h('p.cw-note', 'Slide'),
      seg(Object.entries(THEMES).map(([k, t]) => [k, t.label]), sl.theme || 'brand', (v) => set({ theme: v }, 'Slide theme')),
      title, bullets, h('div.cw-btns', price, offer), h('p.cw-note', 'Icon'), icons,
      h('label.row', h('span.lbl', 'Background'), bg), h('label.row', h('span.lbl', 'Logo'), logo),
      h('p.cw-note', 'Text animation'), seg(Object.entries(ANIMS), sl.anim || 'rise', (v) => set({ anim: v }, 'Slide animation')));
    return box;
  }

  function renderList() {
    clear(list);
    const sel = new Set(ctx.selection?.() || []), all = cutaways(ctx.project);
    if (!all.length) { list.append(h('p.cw-note', 'None yet.')); return; }
    for (const { item } of all.sort((a, b) => a.item.t0 - b.item.t0)) list.appendChild(h('div.cw-row' + (sel.has(item.id) ? '.on' : ''), { on: { click: () => { window.kbbEditor?.select([item.id]); ctx.seek(item.t0); } } },
      h('span', item.style?.slide ? '▤' : '🖼'), h('span.grow', item.name), h('span', `${item.t0.toFixed(2)}–${itemEnd(item).toFixed(2)} s`)));
  }

  let proposals = [];
  async function suggest() {
    clear(sugBox).append(h('p.cw-note', 'Listening for products, prices, countries and jobs…'));
    try { proposals = await A.suggestCutaways(ctx); } catch (e) { clear(sugBox).append(h('p.cw-note', e.message)); return; }
    clear(sugBox);
    if (!proposals.length) { sugBox.append(h('p.cw-note', 'Nothing to suggest: no product, price, country or job is named, or every one already has a cutaway.')); return; }
    const ticks = proposals.map((s) => {
      const cb = h('input', { type: 'checkbox', checked: true });
      sugBox.appendChild(h('label.cw-sg', cb, h('span.grow', h('b', s.label), ` · “${s.word}” · ${s.t0.toFixed(2)}–${s.t1.toFixed(2)} s`), h('button.chip', { on: { click: (e) => { e.preventDefault(); ctx.seek(s.t0); } } }, '▶')));
      return cb;
    });
    sugBox.appendChild(h('button.btn.primary', { on: { click: () => run('Auto cutaways', async () => { const n = await A.acceptSuggestions(ctx, proposals.filter((_, k) => ticks[k].checked)); clear(sugBox); render(); return `${n} cutaways added (one undo step)`; }) } }, 'Add the ticked cutaways'));
  }

  function render() { renderMarks(); renderWords(); renderSelected(); renderList(); }
  const offs = [bus.on('cutaways:marks', renderMarks), bus.on('selection', () => { renderSelected(); renderList(); }),
    bus.on('project:change', () => { wordsAt = -1; render(); }), bus.on('pause', renderWords), bus.on('playhead', (e) => { if (!e?.playing) renderWords(); })];
  render();
  return { destroy() { offs.forEach((o) => o?.()); } };
}
