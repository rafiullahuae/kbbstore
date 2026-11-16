// KBB Studio — first-run setup: every model the studio uses (models/manifest.json), total size, free space,
// ONE "Allow" downloads them all into models/ inside the studio folder. The engine resumes, retries and verifies.

import { engine, fmtBytes } from '../core/engine.js';
import { bus } from '../core/bus.js';
import { APP_NAME } from '../core/config.js';
import { h, modal, clear } from './ui.js';

const LATER = 'kbbstudio.setupLater';
const fmtEta = (s) => (!s || !isFinite(s) ? '' : s > 3600 ? `${Math.floor(s / 3600)} h ${Math.round((s % 3600) / 60)} min left` : s > 90 ? `${Math.round(s / 60)} min left` : `${Math.max(1, Math.round(s))} s left`);

/** open the setup on first run when a required model is missing (and he didn't say "Later" today) */
export async function maybeFirstRun() {
  if (!engine.available) return;
  let st; try { st = await engine.modelsStatus(); } catch { return; }
  const missing = st.models.some((m) => m.required && !m.installed);
  let later = 0; try { later = +localStorage.getItem(LATER) || 0; } catch {}
  const busy = st.installer?.state === 'running';
  if ((missing && Date.now() - later > 20 * 3600 * 1000) || busy) openSetup(st);
}

export async function openSetup(initial) {
  if (document.querySelector('.setup-form')) return;
  let st = initial || await engine.modelsStatus();
  const chosen = new Set(st.models.filter((m) => !m.installed).map((m) => m.id));
  const list = h('div.model-list');
  const summary = h('div.setup-summary');
  const prog = h('div.setup-progress');
  const body = h('div.setup-form',
    h('p', `${APP_NAME} works offline. For captions, auto reels and smart suggestions it needs a few AI models. They are downloaded `, h('b', 'once'), ' from Hugging Face (huggingface.co) into the ', h('code', 'models'), ' folder inside KBB Studio — nothing is uploaded and nothing else goes online.'),
    list, summary, prog);
  let off = null;
  const dlg = modal({
    title: 'Set up the AI models', body, wide: true,
    actions: [
      { label: 'Later', id: 'su-later', onClick: () => { try { localStorage.setItem(LATER, String(Date.now())); } catch {} } },
      { label: 'Allow and download', primary: true, id: 'su-go', onClick: () => { act(); return false; } },
    ],
    onClose: () => off?.(),
  });
  const go = dlg.box.querySelector('#su-go'), later = dlg.box.querySelector('#su-later');
  off = bus.on('engine:models', (s) => { st = s; paint(); });

  function remaining() {
    return st.models.filter((m) => chosen.has(m.id) && !m.installed).reduce((s, m) => s + m.size - m.have, 0);
  }

  function paint() {
    const inst = st.installer || { state: 'idle' };
    const running = inst.state === 'running';
    clear(list);
    for (const m of st.models) {
      const cb = h('input', { type: 'checkbox', checked: m.installed || chosen.has(m.id), disabled: m.installed || m.required || running, on: { change: (e) => { e.target.checked ? chosen.add(m.id) : chosen.delete(m.id); paint(); } } });
      list.appendChild(h('label.model-row' + (m.installed ? '.done' : ''), cb,
        h('div', h('b', m.purpose.split(':')[0]), h('small', m.purpose.split(':').slice(1).join(':').trim() || m.repo)),
        h('span.msize', m.installed ? '✓ Installed' : fmtBytes(m.size) + (m.partial ? ` (${fmtBytes(m.partial)} already here)` : '')),
        h('span.mtag', m.required ? 'Needed' : 'Optional')));
    }
    const need = remaining();
    const short = st.free >= 0 && st.free < need + Math.max(300e6, need / 20);
    summary.replaceChildren(
      h('span', need ? `To download: ${fmtBytes(need)}` : 'Everything you picked is installed.'),
      h('span' + (short ? '.warn' : '.muted'), st.free >= 0 ? `Free space on this Mac: ${fmtBytes(st.free)}` : ''),
    );
    // progress
    clear(prog);
    if (inst.state !== 'idle') {
      const f = inst.total ? inst.done / inst.total : 0;
      prog.append(
        h('progress.xbar', { max: 1, value: f }),
        h('div.setup-line',
          h('b', { done: '✓ ', stalled: '⚠ ', nospace: '⚠ ', paused: '⏸ ' }[inst.state] || ''),
          h('span', inst.state === 'running' ? `${fmtBytes(inst.done)} of ${fmtBytes(inst.total)} · ${Math.round(f * 100)}%` : inst.message),
          inst.state === 'running' && inst.speed > 1 ? h('span.muted', `${fmtBytes(inst.speed)}/s · ${fmtEta(inst.eta)}`) : null),
        h('div.file-list', (inst.files || []).filter((x) => x.state !== 'done' && x.state !== 'queued').slice(0, 4).map((x) => h('div.file-row',
          h('span', x.path.split('/').pop()), h('small.muted', x.message || x.state), h('progress', { max: x.size, value: x.done })))));
    }
    // buttons
    if (running) { go.textContent = '⏸ Pause'; go.disabled = false; later.textContent = 'Hide'; }
    else if (inst.state === 'paused') { go.textContent = '▶ Resume'; go.disabled = false; }
    else if (inst.state === 'stalled' || inst.state === 'nospace') { go.textContent = '↻ Retry'; go.disabled = false; }
    else if (!need) { go.textContent = 'Done'; go.disabled = false; }
    else { go.textContent = `Allow and download (${fmtBytes(need)})`; go.disabled = short; }
  }

  async function act() {
    const inst = st.installer || {};
    try {
      if (inst.state === 'running') st = await engine.pauseModels();
      else if (inst.state === 'paused' || inst.state === 'stalled' || inst.state === 'nospace') st = await engine.resumeModels();
      else if (!remaining()) { dlg.close(); return; }
      else st = await engine.installModels(st.models.filter((m) => chosen.has(m.id) && !m.installed));
    } catch (e) { st.installer = Object.assign({}, st.installer, { state: 'stalled', message: e.message }); }
    paint();
  }
  paint();
  return dlg;
}
