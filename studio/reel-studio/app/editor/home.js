// KBB Studio — the home screen: company, new reel (recipe or "let it suggest"), drop a video, recent projects.

import { engine } from '../core/engine.js';
import { APP_NAME } from '../core/config.js';
import { createProject, ASPECTS } from '../core/project.js';
import { h, clear, toast, seg, confirmDialog, modal } from './ui.js';
import { state as ws, workspaceSwitcher, companyDialog, wsBadge, switchWorkspace } from './workspaces.js';
import { loadRecipes } from './bin.js';
import { stopStudio } from './editor.js';
import { openSetup } from './setup.js';
import { listModules } from '../core/modules.js';
import { adStudioButton, adStudioTile } from '../adstudio/entry.js';
import { searchBox } from './search-ui.js';

export async function renderHome(root, { onOpen, onDropFiles }) {
  clear(root);
  const w = ws.current;
  const rerender = () => renderHome(root, { onOpen, onDropFiles });
  const name = h('input.big-in', { placeholder: 'Name your reel', value: '', 'aria-label': 'Reel name' });
  const recipeSel = h('select.big-in', { 'aria-label': 'Recipe' }, h('option', { value: '' }, '✨ Let it suggest (from your video)'));
  let aspect = '9:16';
  const create = async (files) => {
    const p = createProject({ name: name.value.trim() || (files?.[0]?.name.replace(/\.[^.]+$/, '') ?? 'New reel'), workspace: w.id, brand: w.brandRef || w.id, recipe: recipeSel.value || null, aspect });
    if (engine.available) await engine.saveProject(p).catch((e) => toast('Could not save: ' + e.message, 'error'));
    onOpen(p, files);
  };
  const fileIn = h('input', { type: 'file', multiple: true, accept: 'video/*,image/*,audio/*', hidden: true, on: { change: () => fileIn.files.length && create([...fileIn.files]) } });
  const drop = h('button.home-drop', { on: { click: () => fileIn.click() } },
    h('span.big-ic', '⬇'), h('b', 'Drop a video here'), h('small', 'or click to choose — it opens straight in the editor'),
    h('span.btn.fake-btn', { 'aria-hidden': 'true' }, '📂 Choose a video'), fileIn);
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); if (e.dataTransfer.files.length) create([...e.dataTransfer.files]); });

  const recent = h('div.recent-list', h('p.muted', 'Loading…'));
  const recentCount = h('span.recent-count', { hidden: true });
  const colors = Object.values(w.colors || {}).slice(0, 5);
  const hr = new Date().getHours();
  const hello = hr < 5 ? 'Working late' : hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening';
  const tileHead = (ic, title, sub) => h('div.tile-head', h('span.tile-ic', { 'aria-hidden': 'true' }, ic), h('div', h('h2', title), h('small', sub)));

  root.appendChild(h('div.home',
    h('header.topbar.home-top',
      h('div.brand', h('span.logo-dot', '▶'), h('b', APP_NAME)),
      workspaceSwitcher({ onSwitch: rerender }),
      searchBox(),
      h('button.btn.ghost#configure-btn', { title: 'Companies, keys, Claude, models, folders, languages', on: { click: () => import('../settings/configure.js').then((m) => m.openConfigure('companies', { onClose: rerender })) } }, '⚙ Configure'),
      h('button.btn.ghost', { title: 'Download the AI models (once)', on: { click: () => openSetup() } }, '🧠 Models'),
      adStudioButton(),
      h('div.sep'),
      h('button.btn.ghost.stop-btn', { title: `Stop ${APP_NAME}`, on: { click: () => stopStudio() } }, '⏻ Stop')),
    h('main.home-main',
      h('section.home-hero',
        wsBadge(w, true),
        h('div.hero-text', h('span.hero-hi', `${hello} 👋`), h('h1', `Make a reel for ${w.fullName || w.name}`), h('p', w.industry || 'Your company')),
        h('div.grow'),
        h('div.hero-side',
          h('div.palette', { title: 'Brand colours' }, colors.map((c) => h('span', { style: { background: c }, title: c }))),
          h('button.btn', { on: { click: () => companyDialog(w, rerender) } }, '✎ Edit company'))),
      h('div.section-head', h('h2', 'Start something'), h('span.muted', 'Pick one — everything stays on this Mac')),
      h('div.home-grid',
        h('section.card.new-card',
          tileHead('🎬', 'New reel', 'Empty reel in your brand kit'),
          name, recipeSel,
          h('div.row', h('span.lbl', 'Shape'), seg(Object.keys(ASPECTS).map((k) => [k, k, ASPECTS[k].label]), aspect, (v) => { aspect = v; }, '.aspect')),
          h('div.tile-foot', h('button.btn.primary.big', { on: { click: () => create(null) } }, '＋ Create reel'))),
        h('section.card.drop-card', drop),
        adStudioTile(), // the full Ad Studio (app/adstudio): templates, saved ads, companies, video, cut-out
        // module cards (core/modules.js `home`)
        ...listModules().filter((m) => typeof m.home === 'function').map((m) => {
          const el = h('section.card.mod-card', { dataset: { modHome: m.id } });
          try { m.home(el, { workspace: w, open: onOpen, rerender }); } catch (e) { console.warn('[home]', m.id, e); }
          return el;
        }),
        h('section.card.recent-card', h('div.section-row', h('h2', 'Recent reels'), recentCount), recent)))));

  loadRecipes(w).then((list) => {
    const groups = {};
    for (const r of list) (groups[r.group || 'Recipes'] ||= []).push(r);
    for (const [g, rs] of Object.entries(groups)) recipeSel.appendChild(h('optgroup', { label: g }, rs.map((r) => h('option', { value: r.id }, r.name))));
    if (!list.length) recipeSel.appendChild(h('option', { value: '', disabled: true }, 'More recipes arrive with the next update'));
  }).catch(() => {});

  try {
    const list = engine.available ? await engine.listProjects(w.id) : [];
    clear(recent);
    if (!list.length) recent.appendChild(h('div.empty', h('span.empty-art', '🎞'),
      h('div', h('b', `No reels for ${w.name} yet`), h('p', 'Your first one is a single drop away. Reels you make show up here.')),
      h('button.btn', { on: { click: () => fileIn.click() } }, '📂 Choose a video')));
    if (list.length) { recentCount.hidden = false; recentCount.textContent = String(list.length); }
    for (const r of list.slice(0, 12)) {
      recent.appendChild(h('div.recent', { role: 'button', tabindex: '0', title: r.name, on: { click: () => openById(r.id), keydown: (e) => e.key === 'Enter' && openById(r.id) } },
        h('span.r-aspect', { dataset: { a: r.aspect || '' } }, r.aspect || ''), h('div', h('b', { dir: 'auto' }, r.name), h('small', new Date(r.modified).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }))),
        ws.list.length > 1 ? h('button.icon-btn.move', { title: 'Move or copy this reel to another company', 'aria-label': 'Move to another company', on: { click: (e) => { e.stopPropagation(); moveDialog(r); } } }, '⇄') : null,
        h('button.icon-btn.del', { title: 'Delete this reel', on: { click: async (e) => { e.stopPropagation(); if (await confirmDialog('Delete reel?', `"${r.name}" and its copied media will be deleted.`, 'Delete')) { await engine.remove(`workspaces/${w.id}/projects/${r.id}`); rerender(); } } } }, '✕')));
    }
  } catch (e) { clear(recent); recent.appendChild(h('p.muted', 'Could not read projects: ' + e.message)); }

  async function openById(id) {
    try { onOpen(await engine.loadProject(w.id, id)); } catch (e) { toast('Could not open: ' + e.message, 'error'); }
  }

  // ⇄ Move to company (the owner, 19 Nov: "allow me facility to move the project from one company to another"): the
  // reel's folder, media and all, goes to the company picked here (engine/move.go). It keeps its own look.
  function moveDialog(r) {
    const others = ws.list.filter((c) => c.id !== w.id);
    let pick = others[0]?.id;
    const err = h('p.move-err', { role: 'alert', hidden: true });
    const opts = h('div.move-list', { role: 'radiogroup', 'aria-label': 'Company' }, others.map((c, i) => h('label.move-opt', { dataset: { co: c.id } },
      h('input', { type: 'radio', name: 'move-to', value: c.id, checked: i === 0, on: { change: () => { pick = c.id; } } }), wsBadge(c), h('div', h('b', c.fullName || c.name), h('small.muted', c.industry || '')))));
    const go = (copy) => async () => {
      err.hidden = true;
      try {
        const res = await engine.moveProject(w.id, pick, r.id, copy);
        rerender();
        const open = h('button.btn.small#move-open', { on: { click: async () => { switchWorkspace(res.to); try { onOpen(await engine.loadProject(res.to, res.id)); } catch (e) { toast('Could not open: ' + e.message, 'error'); } } } }, 'Open it there');
        toast(h('span.move-toast', `“${r.name}” ${copy ? 'copied' : 'moved'} to ${res.toName}. `, open), 'ok', 7000);
      } catch (e) { err.textContent = e.message; err.hidden = false; return false; }
    };
    modal({
      title: `Move “${r.name}”`,
      body: h('div.move-dlg', h('p', 'To which company?'), opts,
        h('p.muted', `Its clips and pictures go with it, and it keeps its own look (colours, fonts, logo). “Move” takes it out of ${w.name}; “Copy” keeps it here too.`), err),
      actions: [{ label: 'Cancel', value: null }, { label: 'Copy there', id: 'move-copy', onClick: go(true) }, { label: 'Move', primary: true, id: 'move-go', onClick: go(false) }],
    });
  }
}
