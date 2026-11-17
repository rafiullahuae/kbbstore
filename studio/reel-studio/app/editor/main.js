// KBB Studio — boot: connect to the engine, load companies, load modules, route between home and the editor.

import { engine, fmtBytes } from '../core/engine.js';
import { APP_NAME } from '../core/config.js';
import { normalizeProject } from '../core/project.js';
import { MODULE_ENTRIES } from '../core/modules.js';
import { h, modal } from './ui.js';
import { loadWorkspaces, switchWorkspace, state as ws } from './workspaces.js';
import { renderHome } from './home.js';
import { Editor } from './editor.js';
import { needsSetup, setupScreen } from './setup.js';
import { restorePrefs } from '../core/prefs.js';

const root = document.getElementById('app');
document.title = APP_NAME;
let editor = null;

// the permission question is the app's own dialog; the engine only remembers the answer for this session
engine.setPermissionUI(({ what, size, host }) => new Promise((res) => {
  modal({
    title: 'Allow a one-time download?',
    body: h('div.perm', h('p', h('b', what)), h('p.muted', `${size ? fmtBytes(size) + ' from ' : 'From '}${host}. ${engine.info?.dataSeparate ? 'It is saved in KBB Studio Data and never downloaded again, not even after an update.' : `It is saved in the models folder inside ${APP_NAME} and never downloaded again.`}`)),
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

const ENGINE_API = 4; // engine/main.go apiLevel

async function boot() {
  if (!(await engine.connect())) {
    root.replaceChildren(h('div.stopped', h('h1', `${APP_NAME} needs its engine`), h('p', `Open the studio by double-clicking "Start ${APP_NAME}" in the ${APP_NAME} folder.`), h('p.muted', 'If you just pressed Stop, that is why — start it again the same way.')));
    return;
  }
  // An engine left over from an earlier version (old Terminal window still open, or the old engine folder copied
  // over the new one) answers the old endpoints only: say so plainly instead of "no such endpoint" on new pages.
  if ((engine.info?.api || 0) < ENGINE_API) {
    root.replaceChildren(h('div.stopped', h('h1', 'An older engine is running'),
      h('p', `This ${APP_NAME} is newer than the engine that opened it. Close every Terminal window of ${APP_NAME}, then double-click "Start ${APP_NAME}" inside the NEW ${APP_NAME} folder.`),
      h('p.muted', 'There is nothing to copy from the old folder: your models, companies and keys are kept in KBB Studio Data in your home folder.')));
    return;
  }
  await restorePrefs(); // the owner's preferences from KBB Studio Data, before any screen reads them (core/prefs.js)
  await loadWorkspaces();
  await loadModules();
  // first run: the Setup screen (models download through the engine) comes before anything else
  const st = await needsSetup();
  if (st) await setupScreen(root, st);
  const m = /^#\/p\/([\w-]+)\/([\w-]+)/.exec(location.hash);
  if (m) {
    try { openProject(await engine.loadProject(m[1], m[2])); return; } catch { /* fall through to home */ }
  }
  goHome();
}

boot().catch((e) => {
  console.error(e);
  root.replaceChildren(h('div.stopped', h('h1', 'Something went wrong'), h('p', String(e.message || e))));
});
