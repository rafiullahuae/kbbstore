// KBB Studio — first-run setup: every model the studio uses (models/manifest.json), total size, free space,
// ONE "Allow" downloads them into models/ of KBB Studio Data (outside the app folder, so updates keep them). The engine resumes, retries and verifies.
//
// The list is grouped by the manifest's `group` (total size + licence per group; "Download group" on optional ones).
// First run: a full Setup SCREEN (before home): welcome → Allow and download → progress → "Open the studio" → home.
// Later: the same view in a dialog from ⚙ Models on the home screen.

import { engine, fmtBytes } from '../core/engine.js';
import { bus } from '../core/bus.js';
import { APP_NAME } from '../core/config.js';
import { h, modal, clear } from './ui.js';

const LATER = 'kbbstudio.setupLater';

// the models are listed by the manifest's `group` (Speech, Translation, Image AI…): each group shows its total size and
// licence, and an optional group can be fetched in one go ("Download group")
let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  // the page itself never scrolls (html, body overflow hidden), so the first-run screen scrolls on its own: with every
  // group listed it is taller than a 1280×800 window and "Allow and download" would sit out of reach below the fold
  s.textContent = `
.setup-screen { height: 100vh; overflow-y: auto; }
.model-group { display: flex; flex-direction: column; gap: 6px; }
.model-group + .model-group { margin-top: 8px; }
.mgroup-head { display: flex; align-items: center; gap: 10px; padding: 2px 2px 0; }
.mgroup-head h3 { margin: 0; font-size: 14px; flex: none; }
.mgroup-head .mgroup-meta { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; flex: 1 1 0; min-width: 0; }
.mgroup-head .btn { margin-left: auto; flex: none; white-space: nowrap; }`;
  document.head.appendChild(s);
}
/** the model list in groups, in the order the groups first appear in the manifest; needed groups first */
function groupsOf(models) {
  const map = new Map();
  for (const m of models) { const g = m.group || 'Other'; if (!map.has(g)) map.set(g, []); map.get(g).push(m); }
  const out = [...map].map(([name, list]) => ({ name, list, optional: !list.some((m) => m.required) }));
  return [...out.filter((g) => !g.optional), ...out.filter((g) => g.optional)];
}
const fmtEta = (s) => (!s || !isFinite(s) ? '' : s > 3600 ? `${Math.floor(s / 3600)} h ${Math.round((s % 3600) / 60)} min left` : s > 90 ? `${Math.round(s / 60)} min left` : `${Math.max(1, Math.round(s))} s left`);

/** should the first-run screen show? (a needed model is missing and he didn't say "Later" today, or a download runs) */
export async function needsSetup() {
  if (!engine.available) return null;
  let st; try { st = await engine.modelsStatus(); } catch { return null; }
  const missing = st.models.some((m) => m.required && !m.installed);
  let later = 0; try { later = +localStorage.getItem(LATER) || 0; } catch {}
  const busy = st.installer?.state === 'running';
  return (missing && Date.now() - later > 20 * 3600 * 1000) || busy ? st : null;
}

/** the first-run Setup screen, full page in `root`; resolves when he goes on to the studio */
export function setupScreen(root, st) {
  return new Promise((done) => {
    const view = setupView(st, { onFinish: () => { view.destroy(); done(); } });
    root.replaceChildren(h('div.setup-screen',
      h('header.setup-top', h('span.logo-dot', '▶'), h('b', APP_NAME)),
      h('main.setup-card', h('h1', `Welcome to ${APP_NAME}`), view.el)));
  });
}

/** the same view in a dialog (⚙ Models) */
export async function openSetup(initial) {
  if (document.querySelector('.setup-form')) return;
  const st = initial || await engine.modelsStatus();
  let dlg = null;
  const view = setupView(st, { onFinish: () => dlg?.close() });
  dlg = modal({ title: 'Set up the AI models', body: view.el, wide: true, onClose: () => view.destroy() });
  return dlg;
}

function setupView(initial, { onFinish }) {
  css();
  let st = initial;
  // preselected: everything the studio needs now (the "Needed" ones); optional models can be ticked
  const chosen = new Set(st.models.filter((m) => !m.installed && m.required).map((m) => m.id));
  const list = h('div.model-list');
  const summary = h('div.setup-summary');
  const prog = h('div.setup-progress');
  const later = h('button.btn#su-later', { on: { click: () => { try { localStorage.setItem(LATER, String(Date.now())); } catch {} onFinish(); } } }, 'Later');
  const go = h('button.btn.primary#su-go', { on: { click: () => act() } }, 'Allow and download');
  // the models live in KBB Studio Data (engine -data), outside the app folder an update replaces (engine/data.go)
  const data = engine.info?.dataSeparate ? engine.info.data : '';
  const el = h('div.setup-form',
    h('p', `${APP_NAME} works offline. For captions it needs its speech models. They are downloaded `, h('b', 'once'), ' from Hugging Face (huggingface.co) into the ', h('code', 'models'), ` folder ${data ? 'of KBB Studio Data' : `inside ${APP_NAME}`} — nothing is uploaded and nothing else goes online.`),
    data ? h('p.setup-data', h('b', 'Your models are kept in KBB Studio Data and survive updates.'), h('br'), 'They are in ', h('code', data + '/models'), ' — replacing the KBB Studio folder with a new version never asks for them again.') : null,
    list, summary, prog, h('div.setup-actions', later, go));
  const off = bus.on('engine:models', (s) => { st = s; paint(); });

  const remaining = () => st.models.filter((m) => chosen.has(m.id) && !m.installed).reduce((s, m) => s + m.size - m.have, 0);

  function paint() {
    const inst = st.installer || { state: 'idle' };
    const running = inst.state === 'running';
    clear(list);
    for (const g of groupsOf(st.models)) {
      const total = g.list.reduce((s, m) => s + m.size, 0), left = g.list.filter((m) => !m.installed);
      const lic = [...new Set(g.list.map((m) => m.licence).filter(Boolean))];
      const all = g.optional && left.length ? h('button.btn.small', { type: 'button', disabled: running, dataset: { groupGo: g.name }, title: `Download every ${g.name} model (${fmtBytes(left.reduce((s, m) => s + m.size - m.have, 0))})`,
        on: { click: () => { for (const m of left) chosen.add(m.id); act(left); } } }, 'Download group') : null;
      const box = h('section.model-group', { dataset: { group: g.name } },
        h('div.mgroup-head', h('h3', g.name),
          h('span.mgroup-meta', [`${fmtBytes(total)}`, left.length ? (left.length < g.list.length ? `${g.list.length - left.length} of ${g.list.length} installed` : '') : '✓ installed', lic.length ? `Licence: ${lic.join(' · ')}` : '', g.optional ? 'Optional' : 'Needed'].filter(Boolean).join(' · ')),
          all));
      for (const m of g.list) {
        const cb = h('input', { type: 'checkbox', checked: m.installed || chosen.has(m.id), disabled: m.installed || m.required || running, on: { change: (e) => { e.target.checked ? chosen.add(m.id) : chosen.delete(m.id); paint(); } } });
        box.appendChild(h('label.model-row' + (m.installed ? '.done' : ''), { dataset: { model: m.id } }, cb,
          h('div', h('b', m.purpose.split(':')[0]), h('small', m.purpose.split(':').slice(1).join(':').trim() || m.repo)),
          h('span.msize', m.installed ? '✓ Installed' : fmtBytes(m.size) + (m.partial ? ` (${fmtBytes(m.partial)} already here)` : '')),
          h('span.mtag', m.required ? 'Needed' : 'Optional')));
      }
      list.appendChild(box);
    }
    const need = remaining();
    const short = st.free >= 0 && st.free < need + Math.max(300e6, need / 20);
    summary.replaceChildren(
      h('span', need ? `To download: ${fmtBytes(need)}` : 'Everything you picked is installed.'),
      h('span' + (short ? '.warn' : '.muted'), st.free >= 0 ? `Free space on this Mac: ${fmtBytes(st.free)}` : ''),
    );
    clear(prog);
    if (inst.state !== 'idle') {
      const f = inst.total ? inst.done / inst.total : 0;
      prog.append(
        h('progress.xbar', { max: 1, value: f }),
        h('div.setup-line',
          h('b', { done: '✓ ', stalled: '⚠ ', nospace: '⚠ ', paused: '⏸ ' }[inst.state] || ''),
          h('span.setup-msg', inst.state === 'running' ? `${fmtBytes(inst.done)} of ${fmtBytes(inst.total)} · ${Math.round(f * 100)}%` : inst.message),
          inst.state === 'running' && inst.speed > 1 ? h('span.muted', `${fmtBytes(inst.speed)}/s · ${fmtEta(inst.eta)}`) : null),
        h('div.file-list', (inst.files || []).filter((x) => x.state !== 'done' && x.state !== 'queued').slice(0, 4).map((x) => h('div.file-row',
          h('span', x.path.split('/').pop()), h('small.muted', x.message || x.state), h('progress', { max: x.size, value: x.done })))));
    }
    later.textContent = running ? 'Hide' : need ? 'Later' : 'Close';
    later.hidden = !need && !running;
    if (running) { go.textContent = '⏸ Pause'; go.disabled = false; }
    else if (inst.state === 'paused') { go.textContent = '▶ Resume'; go.disabled = false; }
    else if (inst.state === 'stalled' || inst.state === 'nospace') { go.textContent = '↻ Retry'; go.disabled = false; }
    else if (!need) { go.textContent = `Open ${APP_NAME}`; go.disabled = false; }
    else { go.textContent = `Allow and download (${fmtBytes(need)})`; go.disabled = short; }
  }

  /** the main button; `fresh` = a group's models ("Download group"): starts a download of exactly those, even after a pause */
  async function act(fresh = null) {
    const inst = st.installer || {};
    try {
      if (fresh) st = await engine.installModels(fresh);
      else if (inst.state === 'running') st = await engine.pauseModels();
      else if (inst.state === 'paused' || inst.state === 'stalled' || inst.state === 'nospace') st = await engine.resumeModels();
      else if (!remaining()) { onFinish(); return; }
      else st = await engine.installModels(st.models.filter((m) => chosen.has(m.id) && !m.installed));
    } catch (e) { st.installer = Object.assign({}, st.installer, { state: 'stalled', message: e.message }); }
    paint();
  }
  paint();
  return { el, destroy: () => off?.() };
}
