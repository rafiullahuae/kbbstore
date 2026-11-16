// KBB Studio · ⚙ CONFIGURE -- the one page for everything he has to enter (the owner, 16 Nov: "i need one configure page
// where i need to enter the logins, apis etc."): Companies · Contact & social · Connect Claude · Photo sources · Models ·
// Folders & storage · Languages · About. Opens from the top bar (⚙ Configure) and from the home screen.
// Keys: show/hide, Save, Test, Remove, plain error messages; the engine keeps every key in settings/ (0600) and the page
// only ever sees "set" + the last four characters.
import { engine, fmtBytes } from '../core/engine.js';
import { bus } from '../core/bus.js';
import { APP_NAME, APP_VERSION, wsDir } from '../core/config.js';
import { h, clear, toast, confirmDialog } from '../editor/ui.js';
import { state as wsState, companyDialog, wsBadge } from '../editor/workspaces.js';
import { claudeStatus, claudeSave, claudeDisconnect, claudeRun } from './claude.js';
import { SOURCES, testUrl } from '../stock/api.js';
const ap = (el, ...k) => { el.append(...k.flat(Infinity).filter((x) => x != null && x !== false)); return el; };

const CSS = `
.cfg-back{position:fixed;inset:0;z-index:70;background:var(--bg);display:flex;flex-direction:column}
.cfg-top{display:flex;align-items:center;gap:10px;height:54px;padding:0 14px;background:var(--panel);border-bottom:1px solid var(--line)}
.cfg-top h1{font-size:17px;margin:0}
.cfg-body{flex:1;display:grid;grid-template-columns:230px 1fr;min-height:0}
.cfg-nav{border-right:1px solid var(--line);padding:12px 8px;display:flex;flex-direction:column;gap:2px;overflow:auto;background:var(--panel)}
.cfg-nav button{border:0;background:transparent;text-align:left;padding:9px 12px;border-radius:9px;color:var(--muted);font-weight:600;display:flex;gap:8px;align-items:center}
.cfg-nav button.on{background:var(--panel3);color:var(--text)}
.cfg-nav button .dot{margin-left:auto;width:8px;height:8px;border-radius:50%;background:var(--ok)}
.cfg-main{overflow:auto;padding:22px 28px 60px}
.cfg-sec{max-width:880px;display:flex;flex-direction:column;gap:14px}
.cfg-sec h2{margin:0;font-size:22px}
.cfg-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:10px}
.cfg-card h3{margin:0;font-size:15px}
.cfg-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.cfg-row>label{min-width:150px;color:var(--muted)}
.cfg-row input[type=text],.cfg-row input[type=password],.cfg-row input[type=url],.cfg-row select{flex:1;min-width:220px}
.cfg-key{display:flex;gap:6px;flex:1;min-width:260px}.cfg-key input{flex:1}
.cfg-ok{color:var(--ok);font-weight:600}.cfg-bad{color:var(--err);font-weight:600}.cfg-muted{color:var(--muted);font-size:13px}
.cfg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
.cfg-co{display:flex;gap:10px;align-items:center}
.cfg-model{display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--line)}
.cfg-model:first-child{border-top:0}
.cfg-tag{font-size:11px;padding:2px 7px;border-radius:999px;background:var(--panel3);color:var(--muted)}
.cfg-tag.on{background:rgba(60,207,145,.15);color:var(--ok)}
.cfg-bar{height:8px;border-radius:5px;background:var(--panel3);overflow:hidden}.cfg-bar i{display:block;height:100%;background:var(--accent)}
`;
let styled = false;
const SECTIONS = [['companies', '🏢 Companies'], ['contact', '☎ Contact & social'], ['claude', '✦ Connect Claude'], ['photos', '📷 Photo sources'], ['models', '🧠 Models'], ['folders', '📁 Folders & storage'], ['languages', '🌐 Languages'], ['about', 'ℹ About']];
const tokenH = () => ({ 'X-RS-Token': document.querySelector('meta[name="rs-token"]')?.content || '', 'Content-Type': 'application/json' });
async function api(path, body) { const r = await fetch(path, { method: body ? 'POST' : 'GET', cache: 'no-store', headers: tokenH(), body: body ? JSON.stringify(body) : undefined }); const j = await r.json().catch(() => ({})); if (!r.ok) throw Object.assign(new Error(j.error || r.status + ''), { status: r.status }); return j; }

/** a key field: password input with 👁 show/hide */
function keyInput(placeholder, label) {
  const inp = h('input', { type: 'password', placeholder, autocomplete: 'off', spellcheck: 'false', 'aria-label': label });
  const eye = h('button.btn.small', { type: 'button', title: 'Show / hide', 'aria-label': 'Show or hide ' + label, on: { click: () => { inp.type = inp.type === 'password' ? 'text' : 'password'; eye.textContent = inp.type === 'password' ? '👁 Show' : '🙈 Hide'; } } }, '👁 Show');
  return { inp, el: h('div.cfg-key', inp, eye) };
}

export function openConfigure(section = 'companies', { onClose } = {}) {
  if (!styled) { document.head.appendChild(h('style', CSS)); styled = true; }
  document.querySelector('.cfg-back')?.remove();
  const main = h('main.cfg-main');
  const nav = h('nav.cfg-nav', { 'aria-label': 'Configure sections' });
  const close = () => { back.remove(); document.removeEventListener('keydown', onKey, true); onClose?.(); };
  const onKey = (e) => { if (e.key === 'Escape' && !document.querySelector('.modal-back')) { e.stopPropagation(); close(); } };
  const back = h('div.cfg-back', { role: 'dialog', 'aria-label': 'Configure' },
    h('header.cfg-top', h('span.logo-dot', '⚙'), h('h1', 'Configure'), h('span.cfg-muted', `everything ${APP_NAME} needs, in one place`), h('div.grow'), h('button.btn#cfg-close', { on: { click: close } }, '✕ Close')),
    h('div.cfg-body', nav, main));
  document.body.appendChild(back);
  document.addEventListener('keydown', onKey, true);
  const go = async (id) => {
    for (const b of nav.children) b.classList.toggle('on', b.dataset.sec === id);
    clear(main);
    const sec = h('section.cfg-sec', { dataset: { sec: id } });
    main.appendChild(sec);
    try { await RENDER[id](sec, { go, refresh: () => go(id) }); } catch (e) { sec.appendChild(h('p.cfg-bad', 'Could not load: ' + e.message)); }
  };
  for (const [id, label] of SECTIONS) nav.appendChild(h('button', { dataset: { sec: id }, on: { click: () => go(id) } }, label));
  go(section);
  return { close, go };
}

const RENDER = {
  async companies(sec, { refresh }) {
    ap(sec, h('h2', 'Companies'), h('p.cfg-muted', 'Each company has its own brand kit (logo, colours, fonts, contact line, button wording), projects, exports and designs. Switch companies from the top-left menu.'));
    const grid = h('div.cfg-grid');
    for (const w of wsState.list) {
      grid.appendChild(h('div.cfg-card', { dataset: { co: w.id } },
        h('div.cfg-co', wsBadge(w, false), h('div', h('b', w.fullName || w.name), h('div.cfg-muted', w.industry || ''))),
        h('div.cfg-row', ...Object.values(w.colors || {}).slice(0, 5).map((c) => h('span', { title: c, style: { width: '22px', height: '22px', borderRadius: '6px', background: c, border: '1px solid var(--line2)' } }))),
        h('div.cfg-muted', `Fonts: ${w.fonts?.heading || '—'} / ${w.fonts?.body || '—'}`),
        h('div.cfg-row', h('button.btn.small', { on: { click: () => companyDialog(w, refresh) } }, '✎ Edit kit (logo, colours, fonts)'))));
    }
    grid.appendChild(h('div.cfg-card', h('h3', 'Another company'), h('p.cfg-muted', 'Its own kit, projects and designs.'), h('button.btn.primary', { on: { click: () => companyDialog(null, refresh) } }, '＋ New company')));
    sec.appendChild(grid);
  },

  async contact(sec) {
    ap(sec, h('h2', 'Contact & social'), h('p.cfg-muted', 'Per company: what goes on end cards, ad footers and call-to-action buttons. Saved in the company file (workspaces/<company>/workspace.json).'));
    const F = [['website', 'Website'], ['phone', 'Phone'], ['whatsapp', 'WhatsApp'], ['email', 'Email'], ['instagram', 'Instagram @'], ['tiktok', 'TikTok @'], ['snapchat', 'Snapchat @'], ['facebook', 'Facebook page'], ['linkedin', 'LinkedIn'], ['youtube', 'YouTube']];
    for (const w of wsState.list) {
      const ci = Object.assign({}, w.contactInfo), so = Object.assign({}, w.social);
      const ins = {};
      const rows = F.map(([k, l]) => { const v = ci[k] ?? so[k] ?? ''; ins[k] = h('input', { type: 'text', value: v, placeholder: l, 'aria-label': `${w.name} ${l}`, dataset: { k } }); return h('div.cfg-row', h('label', l), ins[k]); });
      const line = h('input', { type: 'text', value: w.contact || '', placeholder: 'e.g. kbeautybliss.com · WhatsApp 05x xxx xxxx', 'aria-label': `${w.name} contact line` });
      const ctaEn = h('input', { type: 'text', value: w.endCard?.cta || '', placeholder: 'Shop now', 'aria-label': `${w.name} button wording (English)` });
      const ctaAr = h('input', { type: 'text', value: w.endCard?.ctaAr || '', placeholder: 'تسوقي الآن', dir: 'rtl', 'aria-label': `${w.name} button wording (Arabic)` });
      const out = h('span.cfg-muted');
      const save = h('button.btn.primary.small', { on: { click: async () => {
        const nw = structuredClone(w);
        nw.contactInfo = {}; nw.social = {};
        for (const [k] of F) { const v = ins[k].value.trim().slice(0, 120); if (!v) continue; (['instagram', 'tiktok', 'snapchat', 'facebook', 'linkedin', 'youtube'].includes(k) ? nw.social : nw.contactInfo)[k] = v; }
        const site = nw.contactInfo.website || '';
        if (site && !/^(https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(\/\S*)?$/i.test(site)) { out.className = 'cfg-bad'; out.textContent = 'The website does not look like an address (e.g. kbeautybliss.com).'; return; }
        if (nw.contactInfo.email && !/^\S+@\S+\.\S+$/.test(nw.contactInfo.email)) { out.className = 'cfg-bad'; out.textContent = 'The email does not look right.'; return; }
        nw.contact = line.value.trim().slice(0, 140);
        nw.endCard = Object.assign({}, nw.endCard, { cta: ctaEn.value.trim().slice(0, 40), ctaAr: ctaAr.value.trim().slice(0, 40) });
        nw.modified = new Date().toISOString();
        try { await engine.writeFile(`${wsDir(w.id)}/workspace.json`, nw); Object.assign(w, nw); out.className = 'cfg-ok'; out.textContent = '✓ Saved'; } catch (e) { out.className = 'cfg-bad'; out.textContent = 'Not saved: ' + e.message; }
      } } }, 'Save');
      sec.appendChild(h('div.cfg-card', { dataset: { contact: w.id } }, h('div.cfg-co', wsBadge(w), h('h3', w.fullName || w.name)), h('div.cfg-row', h('label', 'Contact line'), line), h('div.cfg-row', h('label', 'Button (English)'), ctaEn), h('div.cfg-row', h('label', 'Button (Arabic)'), ctaAr), ...rows, h('div.cfg-row', save, out)));
    }
  },

  async claude(sec, { refresh }) {
    const st = await claudeStatus();
    ap(sec, h('h2', 'Connect Claude (optional)'),
      h('p.cfg-muted', `${APP_NAME} works fully offline. Connecting Claude adds “Smart brief” (a richer reading of free-form briefs, better copy) and “Best quality” translation. Off by default. When on, ONLY the brief text and the catalogue names it needs (or the caption lines to translate) are sent — never files, photos, videos or customer data — and the studio asks you once per session first.`));
    const status = h('div.cfg-row', st.set ? h('span.cfg-ok#cl-status', `● Connected · key ${st.hint}`) : h('span.cfg-muted#cl-status', '○ Not connected'), st.lastTest ? h('span.cfg-muted', 'Last test: ' + st.lastTest) : null);
    const key = keyInput(st.set ? `Saved (${st.hint}). Paste a new key to replace it` : 'sk-ant-…', 'Claude API key');
    key.inp.id = 'cl-key';
    const out = h('span.cfg-muted#cl-out');
    const model = h('select#cl-model', { 'aria-label': 'Claude model' }, (st.models || []).map((m) => h('option', { value: m.id, selected: m.id === st.model }, m.label)));
    if (st.model && !(st.models || []).some((m) => m.id === st.model)) model.appendChild(h('option', { value: st.model, selected: true }, st.model));
    const custom = h('input#cl-custom', { type: 'text', placeholder: 'or type another model id', 'aria-label': 'Other model id' });
    const msg = (cls, t) => { out.className = cls; out.textContent = t; };
    const saveBtn = h('button.btn.primary#cl-save', { on: { click: async () => {
      const k = key.inp.value.trim(), m = custom.value.trim() || model.value;
      if (!k && !st.set) return msg('cfg-bad', 'Paste your key first (console.anthropic.com → API keys).');
      try { const r = await claudeSave(Object.assign({ model: m }, k ? { key: k } : {})); key.inp.value = ''; msg('cfg-ok', '✓ Saved · ' + (r.hint || '')); setTimeout(refresh, 500); } catch (e) { msg('cfg-bad', e.message); }
    } } }, 'Save');
    const testBtn = h('button.btn#cl-test', { on: { click: async () => {
      if (!(await claudeStatus()).set) return msg('cfg-bad', 'Save a key first.');
      msg('cfg-muted', 'Testing…');
      try { const r = await claudeRun({ task: 'test' }); msg('cfg-ok', `✓ Works · ${r.model} answered in ${(r.ms / 1000).toFixed(1)} s`); } catch (e) { msg('cfg-bad', e.denied ? 'Not tested (you said not now).' : '✗ ' + e.message); }
    } } }, 'Test');
    const discBtn = h('button.btn.danger#cl-disc', { disabled: !st.set, on: { click: async () => { if (!(await confirmDialog('Disconnect Claude?', 'The key is deleted from this Mac. Smart brief and Best-quality translation turn off; everything else keeps working offline.', 'Disconnect'))) return; await claudeDisconnect(); toast('Claude disconnected', 'ok'); refresh(); } } }, 'Disconnect');
    const prefs = await api('/api/prefs').catch(() => ({}));
    const smart = h('input#cl-smart', { type: 'checkbox', checked: !!prefs.smartBrief, on: { change: async () => { const p = await api('/api/prefs').catch(() => ({})); p.smartBrief = smart.checked; await api('/api/prefs', p); toast(smart.checked ? 'Smart brief on (asks once per session)' : 'Smart brief off', 'ok'); } } });
    ap(sec, h('div.cfg-card', status,
      h('div.cfg-row', h('label', 'API key'), key.el),
      h('div.cfg-row', h('label', 'Model'), model, custom),
      h('div.cfg-row', saveBtn, testBtn, discBtn, out),
      h('p.cfg-muted', 'Get a key: console.anthropic.com → Settings → API keys (billing is on your Anthropic account). The key is kept by the studio engine in the settings folder (only this Mac’s user can read it), never in a project, a design, an export or a log; this page only shows its last four characters. Default model: Claude Sonnet 5.5.')),
      h('div.cfg-card', h('h3', 'Use it for'), h('label.cfg-row', smart, h('span', h('b', 'Smart brief'), ' — Brief → Designs reads free-form briefs with Claude (the offline reader still checks every answer)')),
        h('p.cfg-muted', 'Captions → Translate → “Best quality (Claude)” appears when this is connected.')));
  },

  async photos(sec) {
    ap(sec, h('h2', 'Photo sources'), h('p.cfg-muted', 'Your own free Pexels and Unsplash keys, for worker and scene photos (📷 Photos tab in the editor). Kept by the engine in settings/; only the last four characters show here.'));
    let keys = await api('/api/photo-keys');
    for (const k of Object.keys(SOURCES)) {
      const S = SOURCES[k], key = keyInput(`${S.name} API key`, `${S.name} API key`), out = h('span.cfg-muted'), st = h('span');
      key.inp.dataset.src = k;
      const paint = () => { const s = keys[k] || {}; st.className = s.set ? 'cfg-ok' : 'cfg-muted'; st.textContent = s.set ? '● key saved ' + s.hint : '○ no key yet'; };
      const msg = (c, t) => { out.className = c; out.textContent = t; };
      const save = h('button.btn.primary.small', { on: { click: async () => { if (!key.inp.value.trim()) return msg('cfg-bad', 'Paste the key first.'); try { keys = await api('/api/photo-keys', { source: k, key: key.inp.value.trim() }); key.inp.value = ''; paint(); msg('cfg-ok', '✓ Saved'); } catch (e) { msg('cfg-bad', e.message); } } } }, 'Save');
      const test = h('button.btn.small', { on: { click: async () => {
        if (!keys[k]?.set) return msg('cfg-bad', 'Save a key first.');
        const url = testUrl(k);
        try {
          if (!(await engine.askPermission({ what: `Test the ${S.name} key: one search for “worker”`, url, scope: 'exact', host: S.api }))) return msg('cfg-muted', 'Not tested.');
          msg('cfg-muted', 'Testing…');
          const r = await fetch('/net/get?url=' + encodeURIComponent(url), { headers: tokenH() });
          msg(r.status === 200 ? 'cfg-ok' : 'cfg-bad', r.status === 200 ? '✓ Works' : r.status === 401 || r.status === 403 ? `✗ ${S.name} refused the key (${r.status})` : `✗ ${S.name} answered ${r.status}`);
        } catch (e) { msg('cfg-bad', '✗ ' + e.message); }
      } } }, 'Test');
      const rm = h('button.btn.small', { on: { click: async () => { keys = await api('/api/photo-keys', { source: k, key: '' }); paint(); msg('cfg-muted', 'Removed'); } } }, 'Remove');
      paint();
      sec.appendChild(h('div.cfg-card', { dataset: { photo: k } }, h('div.cfg-row', h('h3', S.name), st), h('div.cfg-row', h('label', 'Access key'), key.el), h('div.cfg-row', save, test, rm, out), h('p.cfg-muted', 'Free key: ', h('a', { href: S.keysUrl, target: '_blank', rel: 'noopener' }, S.keysUrl.replace('https://', '')), k === 'unsplash' ? ' (the “Access Key”).' : '. ', `Licence: ${S.licence}.`)));
    }
  },

  async models(sec, { refresh }) {
    const st = await engine.modelsStatus();
    const total = st.models.filter((m) => m.installed).reduce((s, m) => s + m.size, 0);
    ap(sec, h('h2', 'AI models'), h('p.cfg-muted', `Downloaded once from Hugging Face into the models folder; after that nothing goes online. Installed: ${fmtBytes(total)} · free on this Mac: ${st.free >= 0 ? fmtBytes(st.free) : '?'}.`));
    const card = h('div.cfg-card');
    for (const m of st.models) {
      const [what, ...rest] = m.purpose.split(':');
      const dl = h('button.btn.small', { hidden: m.installed, on: { click: async () => { dl.disabled = true; try { await engine.installModels([m]); toast('Downloading ' + what + '…', 'ok'); watch(); } catch (e) { toast(e.message, 'error'); dl.disabled = false; } } } }, `⬇ Download ${fmtBytes(m.size - (m.have || 0))}`);
      const rm = h('button.btn.small', { hidden: !m.installed, on: { click: async () => { if (!(await confirmDialog(`Remove ${what}?`, `Frees ${fmtBytes(m.size)}. You can download it again later.`, 'Remove'))) return; try { await api('/api/models/remove', { id: m.id }); refresh(); } catch (e) { toast(e.message, 'error'); } } } }, '🗑 Remove');
      card.appendChild(h('div.cfg-model', { dataset: { model: m.id } },
        h('div', h('b', what), ' ', h('span.cfg-tag' + (m.installed ? '.on' : ''), m.installed ? 'Installed' : m.required ? 'Needed' : 'Optional'), m.group ? h('span.cfg-tag', m.group) : null, h('div.cfg-muted', rest.join(':').trim()), h('div.cfg-muted', `${m.repo} · ${fmtBytes(m.size)}${m.licence ? ' · ' + m.licence : ''}`)),
        h('span.cfg-muted', m.installed ? fmtBytes(m.size) : m.partial ? fmtBytes(m.partial) + ' so far' : ''), h('div.cfg-row', dl, rm)));
    }
    const prog = h('div.cfg-muted#cfg-mprog');
    ap(sec, card, prog, h('button.btn', { on: { click: () => import('../editor/setup.js').then((s) => s.openSetup()) } }, 'Open the download manager (pause / resume / retry)'));
    let unsub = null;
    function watch() {
      unsub?.();
      unsub = bus.on('engine:models', (s) => { const i = s.installer || {}; prog.textContent = i.state === 'running' ? `Downloading ${fmtBytes(i.done)} of ${fmtBytes(i.total)}` : i.message || ''; if (i.state === 'done') { unsub?.(); refresh(); } });
    }
  },

  async folders(sec) {
    const f = await api('/api/folders');
    ap(sec, h('h2', 'Folders & storage'), h('p.cfg-muted', `Everything ${APP_NAME} makes stays inside its folder: ${f.root}. Free on this Mac: ${fmtBytes(f.free)}.`));
    const open = async (p) => { try { const r = await api('/api/open', { path: p }); toast(r.opened ? 'Opened in Finder' : 'Folder: ' + p, 'ok'); } catch (e) { toast(e.message, 'error'); } };
    const card = h('div.cfg-card');
    for (const x of f.folders) card.appendChild(h('div.cfg-model', { dataset: { folder: x.id } }, h('div', h('b', x.label), h('div.cfg-muted', x.path + '/')), h('span.cfg-muted', `${fmtBytes(x.size)} · ${x.files} files`), h('button.btn.small', { on: { click: () => open(x.path) } }, 'Open in Finder')));
    sec.appendChild(card);
    const per = h('div.cfg-card', h('h3', 'Per company'));
    for (const w of wsState.list) per.appendChild(h('div.cfg-row', wsBadge(w), h('b', w.name), h('div.grow'), ...[['projects', 'Projects'], ['exports', 'Exports'], ['designs', 'Designs'], ['library', 'Library']].map(([d, l]) => h('button.btn.small', { on: { click: () => open(`${wsDir(w.id)}/${d}`) } }, l))));
    sec.appendChild(per);
  },

  async languages(sec) {
    const p = await api('/api/prefs').catch(() => ({}));
    const L = [['auto', 'Auto-detect'], ['en', 'English'], ['ar', 'Arabic'], ['ur', 'Urdu'], ['hi', 'Hindi']];
    const cap = h('select#lang-cap', { 'aria-label': 'Caption language' }, L.map(([v, l]) => h('option', { value: v, selected: (p.captionLang || 'auto') === v }, l)));
    const targets = ['ar', 'en', 'ur', 'hi', 'fr', 'de', 'ru', 'tl', 'bn', 'ml'].map((c) => h('label.chip' + ((p.translateTargets || ['ar', 'en']).includes(c) ? '.on' : ''), h('input', { type: 'checkbox', value: c, hidden: true, checked: (p.translateTargets || ['ar', 'en']).includes(c), on: { change: (e) => e.target.parentElement.classList.toggle('on', e.target.checked) } }), c.toUpperCase()));
    const design = h('select#lang-design', { 'aria-label': 'Design copy languages' }, [['en+ar', 'English + Arabic line'], ['ar+en', 'Arabic + English line'], ['en', 'English only'], ['ar', 'Arabic only']].map(([v, l]) => h('option', { value: v, selected: (p.designLangs || 'en+ar') === v }, l)));
    const out = h('span.cfg-muted');
    ap(sec, h('h2', 'Languages'), h('div.cfg-card',
      h('div.cfg-row', h('label', 'Captions are spoken in'), cap),
      h('div.cfg-row', h('label', 'Translate captions to'), h('div.cfg-row', ...targets)),
      h('div.cfg-row', h('label', 'Ad copy (designs)'), design),
      h('div.cfg-row', h('button.btn.primary#lang-save', { on: { click: async () => {
        const np = Object.assign(await api('/api/prefs').catch(() => ({})), { captionLang: cap.value, translateTargets: targets.map((t) => t.querySelector('input')).filter((i) => i.checked).map((i) => i.value), designLangs: design.value });
        try { await api('/api/prefs', np); out.className = 'cfg-ok'; out.textContent = '✓ Saved'; } catch (e) { out.className = 'cfg-bad'; out.textContent = e.message; }
      } } }, 'Save'), out)));
  },

  async about(sec) {
    const info = engine.info || {};
    ap(sec, h('h2', 'About'), h('div.cfg-card',
      h('div', h('b', `${APP_NAME} ${APP_VERSION}`), h('span.cfg-muted', ` · engine ${info.version || '?'} · ${info.os || ''}/${info.arch || ''}`)),
      h('p.cfg-muted', 'Runs on this Mac. Goes online only through the engine, only after you allow it, and only to: Hugging Face (models, once), Pexels / Unsplash (photo searches, your keys) and api.anthropic.com (only if you connect Claude).'),
      h('h3', 'Open-source parts and their licences'),
      h('ul.cfg-muted', ...[
        'Picture model: SDXS-512-DreamShaper (IDKiro; ONNX conversion creador/sdxs-512-dreamshaper-onnx) — CreativeML Open RAIL++-M: commercial use allowed, with use restrictions (no illegal, harmful, deceptive or discriminatory uses; see the licence). Made on this Mac; you own what you make with it.',
        'Upscaler: Swin2SR x2 (Apache-2.0) · Writing: Qwen2.5 Instruct (Apache-2.0) · Speech: Whisper (MIT) · Tagging: CLIP (MIT)',
        'Icons: Lucide 0.460 (ISC) · Runtime: ONNX Runtime Web (MIT), transformers.js (Apache-2.0)',
        'Fonts: Playfair Display, Poppins, Cairo, Afacad, Montserrat, Bebas Neue, Inter, Tajawal, Amiri, Anton (SIL Open Font Licence)',
      ].map((t) => h('li', t)))));
  },
};
