// KBB Studio · stock photos · the 📷 Photos tab (right column).
//
//   search Pexels or Unsplash (his own free keys, ⚙ Photo sources at the bottom of this tab) -> a grid of previews with
//   the photographer's credit -> ⬇ Save puts the photo into library/people/<occupation>/ with a sidecar JSON (source,
//   photographer, licence, URL) -> + Add to reel / ⬇ For Ad Studio from "Saved photos".
// Online ONLY when he searches, tests a key or saves a photo, and only after he allows it: every URL is granted to the
// engine one by one (/api/permission, scope exact), the engine adds the key, nothing else ever leaves the Mac.
import { h, clear, modal, toast } from '../editor/ui.js';
import { ops } from '../core/history.js';
import { createTrack } from '../core/project.js';
import { clipFromMedia } from '../editor/items.js';
import { SOURCES, searchUrl, testUrl, normalize, credit, sourceLink, savePath, sidecar } from './api.js';

const CSS = `
.sx { display: grid; gap: 10px; padding: 10px 10px 24px; font-size: 13px; min-width: 0; overflow-x: hidden; }
.sx details { border: 1px solid var(--line); border-radius: var(--r2); background: var(--panel2); min-width: 0; }
.sx summary { cursor: pointer; padding: 9px 11px; font-weight: 700; list-style: none; display: flex; gap: 8px; align-items: center; }
.sx summary::-webkit-details-marker { display: none; }
.sx summary small { margin-left: auto; color: var(--muted); font-weight: 500; }
.sx .sx-body { display: grid; gap: 8px; padding: 0 11px 11px; min-width: 0; }
.sx .sx-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; min-width: 0; }
.sx .sx-row > input, .sx .sx-row > select { flex: 1 1 0; min-width: 70px; }
.sx button.sx-b { border: 1px solid var(--line2); background: var(--panel3); border-radius: var(--r-sm); padding: 0 10px; min-height: 32px; font-weight: 600; font-size: 12px; transition: background-color var(--t), border-color var(--t); }
.sx button.sx-b:hover:not(:disabled) { border-color: var(--line3); }
.sx button.sx-b.pri { background: var(--accent); border-color: transparent; color: var(--accent-fg); }
.sx button.sx-b.on { border-color: var(--ws); background: color-mix(in srgb, var(--ws) 18%, transparent); }
.sx button.sx-b:disabled { opacity: .5; }
.sx .sx-chips { display: flex; gap: 5px; flex-wrap: wrap; }
.sx .sx-chips button { font-size: 11px; padding: 0 9px; min-height: 26px; border-radius: 999px; }
.sx .sx-muted { color: var(--muted); font-size: 12px; }
.sx .sx-muted a, .sx .sx-cr a { color: inherit; text-decoration: underline; }
.sx .sx-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.sx .sx-card { border: 1px solid var(--line); border-radius: 8px; overflow: hidden; background: var(--panel3); display: grid; min-width: 0; }
.sx .sx-img { aspect-ratio: 4 / 5; background: #333; display: block; width: 100%; object-fit: cover; }
.sx .sx-cr { font-size: 11px; padding: 5px 6px 0; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sx .sx-act { display: flex; gap: 4px; padding: 5px 6px 6px; }
.sx .sx-act button { flex: 1; font-size: 11px; padding: 4px 6px; }
.sx .sx-ok { color: #3DBB6B; } .sx .sx-bad { color: #E5534B; }
`;

export async function mountPanel(el, ctx) {
  if (!document.getElementById('sx-css')) document.head.appendChild(h('style', { id: 'sx-css' }, CSS));
  const E = ctx.engine;
  // the engine's own answers (401 from a refused key, 403 from the gate) are read, not thrown: E._fetch throws on them
  const tok = () => document.querySelector('meta[name="rs-token"]')?.content || '';
  const call = (p, o = {}) => fetch(p, Object.assign({ cache: 'no-store' }, o, { headers: Object.assign({ 'X-RS-Token': tok() }, o.headers || {}) }));
  const net = (u) => call('/net/get?url=' + encodeURIComponent(u));
  const st = { source: 'pexels', occ: '', query: '', orientation: 'portrait', region: '', people: true, page: 1, results: [], pages: 0, urls: [], session: false, keys: {}, occs: [], sectors: [] };
  try { const j = await E.readFile('library/occupations/occupations.json', 'json'); st.occs = j.occupations || []; st.sectors = j.sectors || []; } catch { /* the library is optional */ }
  const occById = (id) => st.occs.find((o) => o.id === id);

  const root = h('div.sx');
  clear(el).appendChild(root);

  // ── permission: one question per search (or "for this session"), every URL granted to the engine one by one ──
  const grant = (url, what) => E._fetch('/api/permission', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url, scope: 'exact', what }) });
  function ask(title, lines) {
    if (st.session) return Promise.resolve(true);
    return new Promise((res) => modal({ title, body: h('div.perm', lines.map((l) => h('p', l))),
      actions: [{ label: 'Not now', value: 'no' }, { label: 'Allow once', value: 'once' }, { label: 'Allow for this session', primary: true, value: 'session', id: 'sx-allow-session' }],
      onClose: (v) => { if (v === 'session') st.session = true; res(v === 'once' || v === 'session'); } }));
  }
  async function netJson(url, what) {
    await grant(url, what);
    const r = await net(url);
    let j = null; try { j = await r.json(); } catch { /* not JSON */ }
    return { status: r.status, json: j };
  }

  // ── search ──
  const q = h('input', { type: 'search', placeholder: 'e.g. welder, nurse uniform, delivery driver', 'aria-label': 'Search photos', value: '' });
  const srcBtns = Object.keys(SOURCES).map((k) => h('button.sx-b', { dataset: { src: k }, on: { click: () => { st.source = k; syncSrc(); } } }, SOURCES[k].name));
  const syncSrc = () => srcBtns.forEach((b) => b.classList.toggle('on', b.dataset.src === st.source));
  const occSel = h('select', { 'aria-label': 'Occupation', on: { change: () => pickOcc(occSel.value) } }, h('option', { value: '' }, 'Occupation… (sets the folder)'),
    st.sectors.map((s) => h('optgroup', { label: s.en }, st.occs.filter((o) => o.sector === s.id).map((o) => h('option', { value: o.id }, o.name.en)))));
  const orient = h('select', { 'aria-label': 'Orientation', on: { change: () => { st.orientation = orient.value; } } }, [['', 'Any shape'], ['portrait', 'Portrait'], ['landscape', 'Landscape'], ['square', 'Square']].map(([v, l]) => h('option', { value: v, selected: v === st.orientation }, l)));
  const region = h('select', { 'aria-label': 'Region keyword', on: { change: () => { st.region = region.value; } } }, [['', 'Any region'], ['South Asian', 'South Asian'], ['Filipino', 'Filipino'], ['African', 'African'], ['Arab', 'Arab'], ['European', 'European'], ['East Asian', 'East Asian'], ['Latin American', 'Latin American']].map(([v, l]) => h('option', { value: v }, l)));
  const people = h('input', { type: 'checkbox', checked: true, on: { change: () => { st.people = people.checked; } } });
  const QUICK = ['plumber', 'electrician', 'welder', 'nurse', 'caregiver', 'teacher', 'housemaid', 'heavy-driver', 'delivery-rider', 'chef', 'security-guard', 'factory-worker', 'farm-worker', 'construction-labourer'];
  const chips = h('div.sx-chips', QUICK.filter(occById).map((id) => h('button.sx-b', { dataset: { occ: id }, on: { click: () => { pickOcc(id); doSearch(1); } } }, occById(id).title.en)));
  function pickOcc(id) {
    st.occ = id; occSel.value = id;
    chips.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b.dataset.occ === id));
    const o = occById(id); if (o) q.value = o.photo.search[0];
  }
  const status = h('div.sx-muted', { 'aria-live': 'polite' });
  const grid = h('div.sx-grid');
  const more = h('button.sx-b', { hidden: true, on: { click: () => doSearch(st.page + 1) } }, 'More results');
  const providedBy = h('div.sx-muted');
  const fullQuery = () => [q.value.trim(), st.people && !/\b(person|people|worker|man|woman)\b/i.test(q.value) ? 'worker' : '', st.region].filter(Boolean).join(' ');

  async function doSearch(page) {
    const src = st.source, S = SOURCES[src];
    if (!st.keys[src]?.set) { status.textContent = `Add your free ${S.name} key in ⚙ Photo sources (below) first.`; detKeys.open = true; return; }
    let url; try { url = searchUrl(src, { query: fullQuery(), page, orientation: st.orientation }); } catch (e) { status.textContent = e.message; return; }
    if (!(await ask(`Search ${S.name}?`, [`“${fullQuery()}” goes to ${S.api} with your ${S.name} key, and up to 24 previews load from ${S.img}.`, 'Nothing else leaves this Mac. Saving a photo is a separate click.']))) { status.textContent = 'Not searched.'; return; }
    status.textContent = 'Searching ' + S.name + '…';
    try {
      const r = await netJson(url, `${S.name} search “${fullQuery()}”`);
      if (r.status === 401 || r.status === 403) { status.innerHTML = ''; status.append(h('span.sx-bad', `${S.name} refused the key (${r.status}). Check it in ⚙ Photo sources.`)); return; }
      if (r.status !== 200) { status.textContent = `${S.name} answered ${r.status}. Try again in a minute.`; return; }
      const n = normalize(src, r.json);
      if (page === 1) { st.urls.forEach((u) => URL.revokeObjectURL(u)); st.urls = []; st.results = []; clear(grid); }
      st.page = page; st.pages = n.pages; st.results.push(...n.photos); st.lastQuery = fullQuery();
      status.textContent = n.total ? `${n.total.toLocaleString()} photos on ${S.name} · showing ${st.results.length}` : 'Nothing found. Try fewer words.';
      clear(providedBy).append(h('a', { href: sourceLink(src), target: '_blank', rel: 'noopener' }, `Photos provided by ${S.name}`), ` · free to use under the ${S.licence}; the photographer is credited on each photo and saved with it.`);
      more.hidden = page >= n.pages;
      const cards = n.photos.map(card); cards.forEach((c) => grid.appendChild(c.el));
      await loadThumbs(cards);
    } catch (e) { status.textContent = 'Search failed: ' + (e.message || e); }
  }
  // previews: the URLs the API returned (hotlinked through the engine, never re-hosted), 6 at a time
  async function loadThumbs(cards) {
    let i = 0;
    const next = async () => { while (i < cards.length) { const c = cards[i++]; try { await grant(c.p.thumb, 'preview'); const r = await net(c.p.thumb); if (r.ok) { const u = URL.createObjectURL(await r.blob()); st.urls.push(u); c.img.src = u; } } catch { /* a preview that fails stays grey */ } } };
    await Promise.all(Array.from({ length: 6 }, next));
  }
  function creditEl(p) {
    return h('div.sx-cr', { title: credit(p) }, 'Photo by ', p.photographerUrl ? h('a', { href: p.photographerUrl, target: '_blank', rel: 'noopener' }, p.photographer) : p.photographer,
      ' on ', h('a', { href: p.pageUrl || sourceLink(p.source), target: '_blank', rel: 'noopener' }, SOURCES[p.source].name));
  }
  function card(p) {
    const img = h('img.sx-img', { alt: p.alt || credit(p), style: { background: p.color } });
    const save = h('button.sx-b.pri', { title: 'Downloads this photo into library/people (goes online once)', on: { click: () => savePhoto(p, save) } }, '⬇ Save');
    const el = h('div.sx-card', { dataset: { id: p.source + '-' + p.id } }, img, creditEl(p), h('div.sx-act', save));
    return { el, img, p };
  }
  async function savePhoto(p, btn) {
    const S = SOURCES[p.source], to = savePath(p, st.occ);
    if (!(await ask(`Save this photo?`, [`${credit(p)} (${S.licence}) is downloaded from ${S.img} into ${to.dir}/, with its credit and licence saved beside it.`, p.source === 'unsplash' ? 'Unsplash is told about the download, as its rules ask.' : '']))) return;
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      if (p.downloadLocation) { await grant(p.downloadLocation, 'Unsplash download notice'); await net(p.downloadLocation); }   // Unsplash: trigger the download endpoint
      await grant(p.full, 'photo ' + p.id);
      const r = await net(p.full);
      if (!r.ok) throw new Error(S.name + ' answered ' + r.status);
      const blob = await r.blob();
      if (!/^image\//.test(blob.type || 'image/jpeg') || blob.size < 1000) throw new Error('that was not a picture');
      await E.writeFile(to.image, blob);
      await E.writeFile(to.sidecar, sidecar(p, { occupation: st.occ, query: st.lastQuery || '', downloadedFrom: p.full }));
      btn.textContent = '✓ Saved'; toast('Saved to ' + to.image, 'ok'); refreshSaved();
    } catch (e) { btn.disabled = false; btn.textContent = '⬇ Save'; toast('Not saved: ' + (e.message || e), 'error'); }
  }

  // ── saved photos (library/people/<occupation>/) ──
  const savedBox = h('div.sx-grid');
  const savedNote = h('div.sx-muted');
  async function refreshSaved() {
    clear(savedBox);
    const dirs = (await E.listDir('library/people')).filter((d) => d.dir);
    let n = 0;
    for (const d of dirs) {
      const files = await E.listDir('library/people/' + d.name);
      for (const f of files.filter((x) => /\.(jpe?g|png|webp)$/i.test(x.name))) {
        const path = `library/people/${d.name}/${f.name}`, side = path.replace(/\.[a-z]+$/i, '.json');
        let meta = null; try { meta = await E.readFile(side, 'json'); } catch { /* his own photo, no sidecar */ }
        const url = '/' + path.split('/').map(encodeURIComponent).join('/');
        const add = h('button.sx-b.pri', { title: 'Adds the photo to the reel at the playhead', on: { click: () => addToReel(path, meta) } }, '+ Add to reel');
        const ad = h('button.sx-b', { title: 'Saves a copy to Downloads for the Ad Studio (Put a photo here…)', on: { click: () => forAdStudio(url, f.name) } }, '⬇ For Ad Studio');
        const cr = meta ? h('div.sx-cr', { title: meta.credit + ' · ' + meta.licence }, meta.credit + ' · ' + (occById(d.name)?.title.en || d.name)) : h('div.sx-cr', d.name);
        savedBox.appendChild(h('div.sx-card', { dataset: { path } }, h('img.sx-img', { src: url, alt: meta?.credit || f.name, loading: 'lazy' }), cr, h('div.sx-act', add, ad)));
        n++;
      }
    }
    savedNote.textContent = n ? `${n} saved · library/people/<occupation>/ · each has its credit and licence in a .json beside it` : 'Nothing saved yet. Search, then ⬇ Save.';
    detSaved.querySelector('summary small').textContent = n ? String(n) : '';
  }
  async function addToReel(path, meta) {
    try {
      const p = ctx.project, name = meta ? meta.credit : path.split('/').pop();
      const m = await ctx.media.importPath(path, name);
      let track = p.tracks.find((t) => t.kind === 'video' && !t.locked); const cmds = [];
      if (!track) { track = createTrack('video'); cmds.push(ops.addTrack(track)); }
      const item = clipFromMedia(p, m, 'video', ctx.playhead());
      cmds.push(ops.addMedia(m), ops.addItems([{ trackId: track.id, item }]));
      ctx.history.apply(ops.batch('Add photo', cmds));
      toast('Added at the playhead · ' + name, 'ok');
    } catch (e) { toast('Not added: ' + (e.message || e), 'error'); }
  }
  function forAdStudio(url, name) { const a = h('a', { href: url, download: name }); document.body.appendChild(a); a.click(); a.remove(); }

  // ── ⚙ Photo sources (his keys; the engine keeps them) ──
  const keyRows = Object.keys(SOURCES).map((k) => {
    const S = SOURCES[k], inp = h('input', { type: 'password', placeholder: `${S.name} API key`, autocomplete: 'off', 'aria-label': S.name + ' API key' }), out = h('span.sx-muted');
    const save = h('button.sx-b', { on: { click: async () => {
      const r = await call('/api/photo-keys', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ source: k, key: inp.value.trim() }) });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) { out.className = 'sx-bad'; out.textContent = j.error || 'not saved'; return; }
      inp.value = ''; st.keys = j; syncKeys();
    } } }, 'Save');
    const test = h('button.sx-b', { on: { click: async () => {
      if (!st.keys[k]?.set) { out.className = 'sx-bad'; out.textContent = 'Paste and save a key first'; return; }
      if (!(await ask(`Test the ${S.name} key?`, [`One search for “worker” goes to ${S.api} with your key.`]))) return;
      out.className = 'sx-muted'; out.textContent = 'Testing…';
      try { const r = await netJson(testUrl(k), S.name + ' key test'); out.className = r.status === 200 ? 'sx-ok' : 'sx-bad'; out.textContent = r.status === 200 ? '✓ Works' : r.status === 401 || r.status === 403 ? `✗ ${S.name} refused it (${r.status})` : `✗ ${S.name} answered ${r.status}`; }
      catch (e) { out.className = 'sx-bad'; out.textContent = '✗ ' + (e.message || e); }
    } } }, 'Test');
    return { k, out, el: h('div', h('div.sx-row', h('b', S.name), out), h('div.sx-row', inp, save, test),
      h('div.sx-muted', 'Free key: ', h('a', { href: S.keysUrl, target: '_blank', rel: 'noopener' }, S.keysUrl.replace('https://', '')), k === 'unsplash' ? ' (the “Access Key”).' : '.')) };
  });
  function syncKeys() { keyRows.forEach((r) => { const s = st.keys[r.k] || {}; r.out.className = s.set ? 'sx-ok' : 'sx-muted'; r.out.textContent = s.set ? 'key saved ' + s.hint : 'no key yet'; }); detKeys.querySelector('summary small').textContent = Object.values(st.keys).filter((x) => x.set).length + ' of 2'; }

  const detSearch = h('details', { open: true }, h('summary', '🔎 Find worker photos', h('small', 'Pexels · Unsplash')), h('div.sx-body',
    h('div.sx-row', srcBtns), h('div.sx-row', occSel), chips,
    h('form.sx-row', { on: { submit: (e) => { e.preventDefault(); doSearch(1); } } }, q, h('button.sx-b.pri', { type: 'submit' }, 'Search')),
    h('div.sx-row', orient, region, h('label.sx-muted', people, ' people')),
    status, grid, more, providedBy));
  const detSaved = h('details', { open: true }, h('summary', '🗂 Saved photos', h('small', '')), h('div.sx-body', savedNote, savedBox));
  const detKeys = h('details', h('summary', '⚙ Photo sources', h('small', '')), h('div.sx-body',
    h('div.sx-muted', 'Your own free API keys. They stay in this Mac’s KBB Studio folder (settings/), never in a project, an export or a company file; this app only ever sees the last four characters.'),
    keyRows.map((r) => r.el)));
  root.append(detSearch, detSaved, detKeys);
  syncSrc();
  try { st.keys = await (await call('/api/photo-keys')).json(); } catch { st.keys = {}; }
  syncKeys(); refreshSaved().catch(() => {});
  return { destroy() { st.urls.forEach((u) => URL.revokeObjectURL(u)); } };
}
