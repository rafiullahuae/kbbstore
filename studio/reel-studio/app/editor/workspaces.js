// KBB Studio — workspaces (companies): load, seed, switch, create/edit.
// On disk: workspaces/<slug>/workspace.json, brand/, projects/, exports/.

import { engine } from '../core/engine.js';
import { bus } from '../core/bus.js';
import { createWorkspace, slugify } from '../core/project.js';
import { SEED_WORKSPACES, wsDir } from '../core/config.js';
import { h, modal, toast, field } from './ui.js';
import { workspaceFromKit, kitFromWorkspace, validateKit, initialMarkSvg, industryTags } from './kits.js';

const LAST_KEY = 'kbbstudio.workspace';
export const state = { list: [], current: null };

const lsGet = (k) => { try { return localStorage.getItem(k); } catch { return null; } };
const lsSet = (k, v) => { try { localStorage.setItem(k, v); } catch {} };

/** a brand kit under brands/<ref>/kit.json (R3), or null */
async function brandKit(ref) {
  if (!ref || !/^[a-z0-9-]{1,40}$/.test(ref)) return null;
  const p = `brands/${ref}/kit.json`;
  try { if (await engine.exists(p)) return await engine.readFile(p, 'json'); } catch {}
  return null;
}

export async function loadWorkspaces() {
  let dirs = [];
  if (engine.available) dirs = (await engine.listDir('workspaces')).filter((d) => d.dir);
  const list = [];
  for (const d of dirs) {
    // a company folder can exist before its workspace.json (e.g. clips dropped into workspaces/kbb/library first)
    if (!(await engine.exists(`${wsDir(d.name)}/workspace.json`))) continue;
    try { list.push(await engine.readFile(`${wsDir(d.name)}/workspace.json`, 'json')); } catch {}
  }
  // seed the two companies the owner named, once, from their brand kits
  for (const seed of SEED_WORKSPACES) {
    const id = seed.brandRef || slugify(seed.name);
    const have = list.find((w) => w.id === id || w.id === slugify(seed.name));
    // a company seeded before the kits were read (KBB in pink #e8508b, fonts as objects): refresh it from its kit once
    if (have && have.kitSeeded) continue;
    const kit = await brandKit(seed.brandRef);
    const base = have || Object.assign(createWorkspace(seed), { fullName: seed.fullName });
    const ws = kit ? workspaceFromKit(kit, base) : base;
    if (have) { ws.name = have.name; ws.created = have.created; }
    ws.modified = new Date().toISOString();
    if (engine.available) await engine.writeFile(`${wsDir(ws.id)}/workspace.json`, ws);
    if (have) list.splice(list.indexOf(have), 1, ws); else list.push(ws);
  }
  list.sort((a, b) => (a.created || '').localeCompare(b.created || '') || a.name.localeCompare(b.name));
  state.list = list;
  const last = lsGet(LAST_KEY);
  state.current = list.find((w) => w.id === last) || list[0];
  applyTheme(state.current);
  return state;
}

export function switchWorkspace(id) {
  const ws = state.list.find((w) => w.id === id);
  if (!ws || ws === state.current) return ws;
  state.current = ws; lsSet(LAST_KEY, id);
  applyTheme(ws);
  bus.emit('workspace', { workspace: ws });
  return ws;
}

/** the company's accent colour tints the UI a little, so it is obvious which company is open */
export function applyTheme(ws) {
  const c = ws?.colors?.primary || '#e8508b';
  document.documentElement.style.setProperty('--ws', c);
}

export const logoUrl = (ws) => (ws?.logo ? '/' + ws.logo.split('/').map(encodeURIComponent).join('/') + '?v=' + encodeURIComponent(ws.modified || '') : '');

export function wsBadge(ws, big = false) {
  const url = logoUrl(ws);
  return h('span.ws-badge' + (big ? '.big' : ''), { style: { background: ws?.colors?.primary || '#555' } },
    url ? h('img', { src: url, alt: '' }) : (ws?.name || '?').slice(0, 1).toUpperCase());
}

/** the switcher: a button with a menu of companies + New / Edit */
export function workspaceSwitcher({ onSwitch }) {
  const btn = h('button.ws-switch', { type: 'button', title: 'Switch company', 'aria-haspopup': 'menu' },
    wsBadge(state.current), h('span.ws-name', state.current?.name || 'Company'), h('span.caret', '▾'));
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    document.querySelector('.ws-menu')?.remove();
    const r = btn.getBoundingClientRect();
    const menu = h('div.ws-menu', { role: 'menu', style: { left: r.left + 'px', top: r.bottom + 6 + 'px' } },
      state.list.map((ws) => h('button.ws-item' + (ws === state.current ? '.on' : ''), { role: 'menuitem', on: { click: () => { menu.remove(); if (ws !== state.current) { switchWorkspace(ws.id); onSwitch?.(ws); } } } },
        wsBadge(ws), h('span', h('b', ws.name), h('small', ws.industry || '')))),
      h('hr'),
      h('button.ws-item', { on: { click: () => { menu.remove(); companyDialog(null, onSwitch); } } }, h('span.ws-badge.plus', '+'), h('span', h('b', 'New company'))),
      h('button.ws-item', { on: { click: () => { menu.remove(); companyDialog(state.current, onSwitch); } } }, h('span.ws-badge.plus', '✎'), h('span', h('b', 'Edit company'), h('small', state.current?.name))),
    );
    document.body.appendChild(menu);
    const off = (ev) => { if (!menu.contains(ev.target)) { menu.remove(); document.removeEventListener('pointerdown', off, true); } };
    document.addEventListener('pointerdown', off, true);
  });
  return btn;
}

const FONTS = ['system-ui', 'Helvetica Neue', 'Avenir Next', 'Futura', 'Gill Sans', 'Georgia', 'Didot', 'Baskerville', 'Times New Roman', 'Courier New', 'Arial Rounded MT Bold', 'Geeza Pro', 'Damascus', 'Baghdad'];
const INDUSTRIES = ['Beauty / K-beauty retail', 'Immigration & study-visa consultancy', 'Restaurant / cafe', 'Real estate', 'Fitness', 'Travel', 'Education', 'Retail', 'Services', 'Other'];

/** create (ws = null) or edit a company */
export function companyDialog(ws, onDone) {
  const isNew = !ws;
  const w = isNew ? createWorkspace({ name: '' }) : structuredClone(ws);
  let logoFile = null;
  const name = h('input', { value: w.name, placeholder: 'Company name', maxlength: 60, required: true });
  const industry = h('input', { value: w.industry || '', list: 'kbb-ind', placeholder: 'e.g. Beauty, Consultancy' });
  const contact = h('input', { value: w.contact || '', placeholder: 'website · phone · @instagram' });
  const colorKeys = [['primary', 'Main'], ['secondary', 'Second'], ['accent', 'Accent'], ['text', 'Text'], ['bg', 'Background']];
  const colors = colorKeys.map(([k, l]) => { const i = h('input', { type: 'color', value: w.colors[k] || '#000000' }); i.dataset.k = k; return h('label.swatch', i, h('small', l)); });
  const fontSel = (v) => h('select', [...new Set([v, ...FONTS].filter(Boolean))].map((f) => h('option', { value: f, selected: f === v, style: { fontFamily: f } }, f)));
  const fh = fontSel(w.fonts.heading), fb = fontSel(w.fonts.body);
  const endTitle = h('input', { value: w.endCard?.title || '', placeholder: 'End card title' });
  const endCta = h('input', { value: w.endCard?.cta || '', placeholder: 'Call to action, e.g. Shop now' });
  const logoPrev = h('div.logo-prev', w.logo ? h('img', { src: logoUrl(w), alt: 'logo' }) : h('span', 'No logo yet'));
  const logoIn = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/svg+xml,image/webp' });
  logoIn.addEventListener('change', () => { logoFile = logoIn.files[0]; if (logoFile) logoPrev.replaceChildren(h('img', { src: URL.createObjectURL(logoFile), alt: 'logo' })); });

  const body = h('div.company-form',
    h('datalist#kbb-ind', INDUSTRIES.map((i) => h('option', { value: i }))),
    field('Name', name), field('Industry', industry),
    h('div.row', h('span.lbl', 'Logo'), h('div.logo-pick', logoPrev, h('label.btn.small', 'Upload logo…', logoIn))),
    h('div.row', h('span.lbl', 'Colours'), h('div.swatches', colors)),
    field('Heading font', fh), field('Body font', fb),
    field('Contact line', contact),
    field('End card', h('div.two', endTitle, endCta)),
  );
  modal({
    title: isNew ? 'New company' : 'Edit company', body, wide: true,
    actions: [{ label: 'Cancel' }, {
      label: isNew ? 'Create company' : 'Save', primary: true, onClick: async () => {
        const nm = name.value.trim();
        if (!nm) { name.focus(); toast('Give the company a name', 'warn'); return false; }
        w.name = nm; w.industry = industry.value.trim(); w.contact = contact.value.trim();
        for (const c of colors) { const i = c.querySelector('input'); w.colors[i.dataset.k] = i.value; }
        w.fonts = { heading: fh.value, body: fb.value };
        w.endCard = Object.assign({}, w.endCard, { title: endTitle.value.trim() || nm, cta: endCta.value.trim() });
        w.industries = industryTags(w.industry);
        if (isNew) {
          let id = slugify(nm), n = 2;
          while (state.list.some((x) => x.id === id) || await engine.exists(`brands/${id}/kit.json`)) id = slugify(nm) + '-' + n++;
          w.id = id; w.brandRef = id; w.ownKit = 1;
        }
        w.modified = new Date().toISOString();
        try {
          let full = null;
          if (logoFile) {
            const ext = (logoFile.name.split('.').pop() || 'png').toLowerCase().replace(/[^a-z0-9]/g, '');
            w.logo = `${wsDir(w.id)}/brand/logo.${ext}`;
            await engine.writeFile(w.logo, logoFile);
            if (w.ownKit) { full = `logo.${ext}`; await engine.writeFile(`brands/${w.id}/${full}`, logoFile); }
          }
          // a company made here has its own brand kit (brands/<slug>/kit.json), checked against brands/kit.schema.json
          if (w.ownKit) {
            const kit = kitFromWorkspace(w, { mark: full || 'logo-mark.svg', full: full || undefined });
            let schema = null;
            try { schema = await engine.readFile('brands/kit.schema.json', 'json'); } catch {}
            const errs = schema ? validateKit(kit, schema) : [];
            if (errs.length) { toast('The brand kit is not valid: ' + errs.slice(0, 3).join('; '), 'error'); return false; }
            if (!full) await engine.writeFile(`brands/${w.id}/logo-mark.svg`, new Blob([initialMarkSvg(w)], { type: 'image/svg+xml' }));
            if (!full && !w.logo) w.logo = `brands/${w.id}/logo-mark.svg`;
            await engine.writeFile(`brands/${w.id}/kit.json`, kit);
          }
          await engine.writeFile(`${wsDir(w.id)}/workspace.json`, w);
        } catch (e) { toast('Could not save: ' + e.message, 'error'); return false; }
        const i = state.list.findIndex((x) => x.id === w.id);
        if (i >= 0) state.list[i] = w; else state.list.push(w);
        state.current = w; lsSet(LAST_KEY, w.id); applyTheme(w);
        bus.emit('workspace', { workspace: w });
        toast(isNew ? `${nm} created` : 'Saved', 'ok');
        onDone?.(w);
      },
    }],
  });
}
