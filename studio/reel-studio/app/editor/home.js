// KBB Studio — the home screen: company, new reel (recipe or "let it suggest"), drop a video, recent projects.

import { engine } from '../core/engine.js';
import { APP_NAME } from '../core/config.js';
import { createProject, ASPECTS } from '../core/project.js';
import { h, clear, toast, seg, confirmDialog } from './ui.js';
import { state as ws, workspaceSwitcher, companyDialog, wsBadge } from './workspaces.js';
import { loadRecipes } from './bin.js';
import { stopStudio } from './editor.js';
import { openSetup } from './setup.js';

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
  const drop = h('button.home-drop', { on: { click: () => fileIn.click() } }, h('span.big-ic', '⬇'), h('b', 'Drop a video here'), h('small', 'or click to choose — it opens straight in the editor'), fileIn);
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); if (e.dataTransfer.files.length) create([...e.dataTransfer.files]); });

  const recent = h('div.recent-list', h('p.muted', 'Loading…'));
  const colors = Object.values(w.colors || {}).slice(0, 5);

  root.appendChild(h('div.home',
    h('header.topbar.home-top',
      h('div.brand', h('span.logo-dot', '▶'), h('b', APP_NAME)),
      workspaceSwitcher({ onSwitch: rerender }),
      h('div.grow'),
      h('button.btn.ghost', { title: 'Download the AI models (once)', on: { click: () => openSetup() } }, '⚙ Models'),
      h('button.btn.ghost.stop-btn', { on: { click: () => stopStudio() } }, '⏻ Stop')),
    h('main.home-main',
      h('section.home-hero',
        wsBadge(w, true),
        h('div', h('h1', `Make a reel for ${w.fullName || w.name}`), h('p.muted', w.industry || 'Your company')),
        h('div.grow'),
        h('div.palette', colors.map((c) => h('span', { style: { background: c }, title: c }))),
        h('button.btn', { on: { click: () => companyDialog(w, rerender) } }, '✎ Edit company')),
      h('div.home-grid',
        h('section.card.new-card',
          h('h2', 'New reel'),
          name, recipeSel,
          h('div.row', h('span.lbl', 'Shape'), seg(Object.keys(ASPECTS).map((k) => [k, k, ASPECTS[k].label]), aspect, (v) => { aspect = v; }, '.aspect')),
          h('button.btn.primary.big', { on: { click: () => create(null) } }, '＋ Create reel')),
        h('section.card.drop-card', drop),
        h('section.card.recent-card', h('h2', 'Recent'), recent)))));

  loadRecipes(w).then((list) => {
    const groups = {};
    for (const r of list) (groups[r.group || 'Recipes'] ||= []).push(r);
    for (const [g, rs] of Object.entries(groups)) recipeSel.appendChild(h('optgroup', { label: g }, rs.map((r) => h('option', { value: r.id }, r.name))));
    if (!list.length) recipeSel.appendChild(h('option', { value: '', disabled: true }, 'More recipes arrive with the next update'));
  }).catch(() => {});

  try {
    const list = engine.available ? await engine.listProjects(w.id) : [];
    clear(recent);
    if (!list.length) recent.appendChild(h('p.muted', `No reels for ${w.name} yet.`));
    for (const r of list.slice(0, 12)) {
      recent.appendChild(h('div.recent', { role: 'button', tabindex: '0', on: { click: () => openById(r.id), keydown: (e) => e.key === 'Enter' && openById(r.id) } },
        h('span.r-aspect', r.aspect || ''), h('div', h('b', r.name), h('small', new Date(r.modified).toLocaleString())),
        h('button.icon-btn.del', { title: 'Delete this reel', on: { click: async (e) => { e.stopPropagation(); if (await confirmDialog('Delete reel?', `"${r.name}" and its copied media will be deleted.`, 'Delete')) { await engine.remove(`workspaces/${w.id}/projects/${r.id}`); rerender(); } } } }, '✕')));
    }
  } catch (e) { clear(recent); recent.appendChild(h('p.muted', 'Could not read projects: ' + e.message)); }

  async function openById(id) {
    try { onOpen(await engine.loadProject(w.id, id)); } catch (e) { toast('Could not open: ' + e.message, 'error'); }
  }
}
