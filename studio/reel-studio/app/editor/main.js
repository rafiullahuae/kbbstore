// KBB Studio — boot: connect to the engine, load companies, load modules, route between home and the editor.

import { engine, fmtBytes } from '../core/engine.js';
import { APP_NAME } from '../core/config.js';
import { normalizeProject } from '../core/project.js';
import { MODULE_ENTRIES } from '../core/modules.js';
import { h, modal } from './ui.js';
import { loadWorkspaces, switchWorkspace, state as ws } from './workspaces.js';
import { renderHome } from './home.js';
import { Editor } from './editor.js';
import { maybeFirstRun } from './setup.js';

const root = document.getElementById('app');
document.title = APP_NAME;
let editor = null;

// the permission question is the app's own dialog; the engine only remembers the answer for this session
engine.setPermissionUI(({ what, size, host }) => new Promise((res) => {
  modal({
    title: 'Allow a one-time download?',
    body: h('div.perm', h('p', h('b', what)), h('p.muted', `${size ? fmtBytes(size) + ' from ' : 'From '}${host}. It is saved in the models folder inside ${APP_NAME} and never downloaded again.`)),
    actions: [{ label: 'Not now', value: false }, { label: 'Allow', primary: true, value: true }],
    onClose: (v) => res(v === true),
  });
}));

async function loadModules() {
  for (const rel of MODULE_ENTRIES) {
    const url = new URL(rel, new URL('../core/modules.js', import.meta.url));
    const path = url.pathname.replace(/^\//, '');
    // check first, so a module that isn't there yet never shows up as a console error
    if (!(await engine.exists(decodeURIComponent(path)))) continue;
    try { await import(url.href); } catch (e) { console.warn('[modules] could not load', path, e); }
  }
}

function goHome() {
  editor?.destroy(); editor = null;
  history.replaceState(null, '', '#/');
  renderHome(root, { onOpen: openProject });
}

function openProject(p, files) {
  editor?.destroy();
  const project = normalizeProject(p);
  if (project.workspace && project.workspace !== ws.current?.id) switchWorkspace(project.workspace);
  editor = new Editor(root, project, { onHome: goHome });
  history.replaceState(null, '', `#/p/${project.workspace}/${project.id}`);
  if (files?.length) editor.importFiles(files);
}

async function boot() {
  if (!(await engine.connect())) {
    root.replaceChildren(h('div.stopped', h('h1', `${APP_NAME} needs its engine`), h('p', `Open the studio by double-clicking "Start ${APP_NAME}" in the ${APP_NAME} folder.`), h('p.muted', 'If you just pressed Stop, that is why — start it again the same way.')));
    return;
  }
  await loadWorkspaces();
  await loadModules();
  const m = /^#\/p\/([\w-]+)\/([\w-]+)/.exec(location.hash);
  if (m) {
    try { openProject(await engine.loadProject(m[1], m[2])); return; } catch { /* fall through to home */ }
  }
  goHome();
  maybeFirstRun();
}

boot().catch((e) => {
  console.error(e);
  root.replaceChildren(h('div.stopped', h('h1', 'Something went wrong'), h('p', String(e.message || e))));
});
