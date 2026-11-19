// KBB Studio · ⚙ Configure → ⬆ Updates (the owner, 19 Nov 2026: "give me function to upload patch directly in the
// studio setting, to make updates onwards, build me that nice super nice system").
//
// Pick (or drop) the "KBB Studio v….zip" he receives → the engine checks every file of it before anything changes
// (engine/update.go) → a card shows from → to, what's new, the size and Install → the engine swaps the files, keeps
// the old version for "Roll back", restarts itself in the same Terminal window → this page waits for the new engine
// and reloads into the new version, which shows "Updated to v…". No polling loop outlives that wait.
import { engine, fmtBytes } from '../core/engine.js';
import { APP_NAME, APP_VERSION } from '../core/config.js';
import { h, clear, toast, confirmDialog } from '../editor/ui.js';

export const UPDATE_CSS = `
.upd-drop{border:2px dashed var(--line2);border-radius:var(--r-lg);padding:22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;transition:border-color var(--t),background-color var(--t)}
.upd-drop.over{border-color:var(--accent);background:var(--accent-soft)}
.upd-drop .big-ic{font-size:30px;line-height:1}
.upd-ver{font-size:28px;font-weight:750;letter-spacing:-.02em}
.upd-arrow{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:700}
.upd-arrow .to{color:var(--accent-text)}
.upd-notes{margin:0;padding-inline-start:20px;display:flex;flex-direction:column;gap:6px;line-height:1.5}
.upd-steps{display:flex;gap:8px;flex-wrap:wrap}
.upd-steps span{padding:4px 10px;border-radius:999px;background:var(--panel3);color:var(--muted);font-size:12px;font-weight:650}
.upd-steps span.on{background:var(--accent-soft);color:var(--text)}
.upd-steps span.done{background:rgba(60,207,145,.15);color:var(--ok)}
.upd-hist{margin:0;padding:0;list-style:none;display:flex;flex-direction:column}
.upd-hist li{display:flex;gap:10px;padding:8px 0;border-top:1px solid var(--line);font-size:13px}
.upd-hist li:first-child{border-top:0}
#upd-review:empty{display:none}
.upd-banner{border:1px solid color-mix(in srgb,var(--ok) 45%,var(--line));background:rgba(60,207,145,.1);border-radius:var(--r-lg);padding:14px 18px;font-weight:650}
`;

const DONE_KEY = 'kbb-updated';
const token = () => document.querySelector('meta[name="rs-token"]')?.content || '';
async function api(path, body) {
  const r = await fetch(path, { method: body ? 'POST' : 'GET', cache: 'no-store', headers: { 'X-RS-Token': token(), 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(j.error || 'The engine answered ' + r.status);
  return j;
}
const when = (iso) => { try { return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }); } catch { return iso; } };

/** the zip, to the engine, with upload progress (fetch has none) */
function sendZip(file, onProgress) {
  return new Promise((res, rej) => {
    const x = new XMLHttpRequest();
    x.open('POST', '/api/update/upload');
    x.setRequestHeader('X-RS-Token', token());
    x.setRequestHeader('Content-Type', 'application/zip');
    x.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total);
    x.onload = () => { let j = {}; try { j = JSON.parse(x.responseText); } catch {} if (x.status === 200) res(j); else rej(new Error(j.error || 'The engine answered ' + x.status)); };
    x.onerror = () => rej(new Error('The file did not reach the studio engine. Is the Terminal window still open?'));
    x.send(file);
  });
}

/** after Install / Roll back: wait for the NEW engine, then reload. Every engine start has its own session token, and
 *  the app page carries it, so "the page's token changed" is "the new engine answers" -- asked of the page itself, which
 *  needs no token (no 401s in the console). Bounded: 90 s, then plain words. */
async function waitAndReload(to, kind, say) {
  try { sessionStorage.setItem(DONE_KEY, JSON.stringify({ to, kind, at: Date.now() })); } catch {}
  engine.events?.close(); engine.events = null;   // the old engine's stream: it is going away, do not reconnect to it
  const mine = token(), t0 = Date.now();
  while (Date.now() - t0 < 90000) {
    await new Promise((r) => setTimeout(r, 700));
    try {
      const html = await (await fetch('/app/index.html', { cache: 'no-store' })).text();
      const t = /name="rs-token" content="([^"]+)"/.exec(html)?.[1];
      if (t && t !== mine) { location.reload(); return; }
    } catch { /* restarting: nobody answers for a moment */ }
  }
  say(`${APP_NAME} did not come back by itself. Close the Terminal window, then double-click “Start ${APP_NAME}”.`);
}

/** the "Updated to v…" note, once, after the reload (main.js opens this section when it is there) */
export function takeUpdatedNote() {
  try { const v = JSON.parse(sessionStorage.getItem(DONE_KEY) || 'null'); sessionStorage.removeItem(DONE_KEY); return v && Date.now() - v.at < 600000 ? v : null; } catch { return null; }
}
export function hasUpdatedNote() { try { return !!sessionStorage.getItem(DONE_KEY); } catch { return false; } }

export async function renderUpdates(sec) {
  const st = await api('/api/update');
  const note = takeUpdatedNote();
  sec.append(h('h2', 'Updates'));
  if (note) sec.append(h('div.upd-banner#upd-done', { role: 'status' }, note.kind === 'rollback' ? `✓ Back on version ${APP_VERSION}.` : `✓ Updated to version ${APP_VERSION}.`, h('span.cfg-muted', ' Your companies, reels, models and keys are all as you left them.')));

  // ── now ──
  const msg = h('p.cfg-muted#upd-msg', { role: 'status' });
  const say = (t, bad) => { msg.className = bad ? 'cfg-bad' : 'cfg-muted'; msg.textContent = t; };
  const fileIn = h('input#upd-file', { type: 'file', accept: '.zip,application/zip', hidden: true, 'aria-label': 'Update file (zip)' });
  const pick = h('button.btn.primary#upd-pick', { disabled: !st.canUpdate, on: { click: () => fileIn.click() } }, '⬆ Install update…');
  const drop = h('div.upd-drop#upd-drop', h('span.big-ic', { 'aria-hidden': 'true' }, '📦'),
    h('div', h('b', 'Drop the update zip here'), h('div.cfg-muted', `The “${APP_NAME} v….zip” file you received. Nothing changes until you press Install.`)), h('div.grow'), pick, fileIn);
  const review = h('div#upd-review');
  sec.append(h('div.cfg-card', { dataset: { upd: 'now' } },
    h('div.cfg-row', h('div', h('div.cfg-muted', 'You have'), h('div.upd-ver#upd-version', `${APP_NAME} ${APP_VERSION}`))),
    st.canUpdate ? drop : h('p.cfg-bad', st.why || 'Updating from here is not available.'), msg), review);

  const take = async (file) => {
    if (!file) return;
    if (!/\.zip$/i.test(file.name)) return say('That is not a zip file. Choose the “KBB Studio v….zip” you received.', true);
    clear(review); pick.disabled = true;
    const bar = h('i', { style: { width: '0%' } });
    const steps = h('div.upd-steps', h('span.on', '1 · Checking the file'), h('span', '2 · Install'), h('span', '3 · Restart'));
    review.append(h('div.cfg-card', steps, h('div.cfg-bar', bar), h('p.cfg-muted#upd-checking', `Checking ${file.name} (${fmtBytes(file.size)})… every file is compared with its fingerprint before anything changes.`)));
    try {
      const p = await sendZip(file, (f) => { bar.style.width = Math.round(f * 100) + '%'; });
      showReview(p, file.name);
    } catch (e) {
      clear(review);
      review.append(h('div.cfg-card#upd-refused', h('h3', '✗ Not installed'), h('p.cfg-bad', e.message), h('p.cfg-muted', 'Nothing was changed. You are still on version ' + APP_VERSION + '.')));
    } finally { pick.disabled = !st.canUpdate; fileIn.value = ''; }
  };
  fileIn.addEventListener('change', () => take(fileIn.files[0]));
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); take(e.dataTransfer.files[0]); });

  function showReview(p, name) {
    clear(review);
    const steps = h('div.upd-steps', h('span.done', '1 · Checked ✓'), h('span.on', '2 · Install'), h('span', '3 · Restart'));
    const out = h('p.cfg-muted', { role: 'status' });
    const cancel = h('button.btn#upd-cancel', { on: { click: async () => { await api('/api/update/cancel', {}).catch(() => {}); clear(review); say('Cancelled. Nothing was changed.'); } } }, 'Cancel');
    const install = h('button.btn.primary.big#upd-install', { on: { click: async () => {
      install.disabled = cancel.disabled = true;
      steps.children[1].className = 'on'; out.className = 'cfg-muted'; out.textContent = 'Installing… keep this window open.';
      try {
        const r = await api('/api/update/install', { id: p.id });
        steps.children[1].className = 'done'; steps.children[1].textContent = '2 · Installed ✓'; steps.children[2].className = 'on';
        out.textContent = `Restarting ${APP_NAME} ${r.to}… this page reloads by itself.`;
        await waitAndReload(r.to, 'update', (t) => { out.className = 'cfg-bad'; out.textContent = t; });
      } catch (e) { out.className = 'cfg-bad'; out.textContent = e.message; install.disabled = cancel.disabled = false; }
    } } }, `Install ${p.to}`);
    review.append(h('div.cfg-card#upd-card', { dataset: { upd: 'review' } },
      steps,
      h('div.upd-arrow', h('span', p.from || '?'), h('span', { 'aria-hidden': 'true' }, '→'), h('span.to', p.to)),
      h('div.cfg-muted', [name, fmtBytes(p.size), `${p.files} files`, p.date ? 'made ' + p.date : null].filter(Boolean).join(' · ')),
      h('h3', 'What’s new'),
      p.notes?.length ? h('ul.upd-notes', p.notes.map((n) => h('li', n))) : h('p.cfg-muted', 'No notes came with this update.'),
      h('p.cfg-muted', `Your companies, reels, exports, models and keys are not touched. Version ${p.from} is kept, so you can roll back.`),
      h('div.cfg-row', install, cancel), out));
    install.focus();
  }
  if (st.pending) showReview(st.pending, 'the file you chose');

  // ── roll back ──
  if (st.previous) {
    const out = h('p.cfg-muted', { role: 'status' });
    const back = h('button.btn#upd-rollback', { disabled: !st.canUpdate, on: { click: async () => {
      if (!(await confirmDialog(`Roll back to ${st.previous.version}?`, `${APP_NAME} goes back to version ${st.previous.version} and restarts. Your companies, reels, models and keys stay as they are. You can come back to ${APP_VERSION} the same way.`, `Roll back to ${st.previous.version}`))) return;
      back.disabled = true; out.className = 'cfg-muted'; out.textContent = 'Putting version ' + st.previous.version + ' back…';
      try {
        const r = await api('/api/update/rollback', {});
        out.textContent = `Restarting ${APP_NAME} ${r.to}… this page reloads by itself.`;
        await waitAndReload(r.to, 'rollback', (t) => { out.className = 'cfg-bad'; out.textContent = t; });
      } catch (e) { out.className = 'cfg-bad'; out.textContent = e.message; back.disabled = false; }
    } } }, `↩ Roll back to ${st.previous.version}`);
    sec.append(h('div.cfg-card', { dataset: { upd: 'rollback' } }, h('h3', 'Something wrong after an update?'),
      h('p.cfg-muted', `Version ${st.previous.version} is kept on this Mac (since ${when(st.previous.at)}). Rolling back puts it back exactly as it was.`), h('div.cfg-row', back), out));
  }

  // ── history ──
  if (st.history?.length) {
    sec.append(h('div.cfg-card', { dataset: { upd: 'history' } }, h('h3', 'History'),
      h('ul.upd-hist', st.history.slice(0, 8).map((x) => h('li', h('span.cfg-muted', when(x.at)), h('span', x.kind === 'rollback' ? `Rolled back ${x.from} → ${x.to}` : `Updated ${x.from} → ${x.to}`))))));
  }
  sec.append(h('p.cfg-muted', `Updates come only from the zip you choose: ${APP_NAME} checks that every file is exactly as it was packed, and refuses a damaged, changed or older package. It never looks for updates online.`));
  if (note) toast(note.kind === 'rollback' ? `Back on version ${APP_VERSION}` : `Updated to version ${APP_VERSION}`, 'ok', 4000);
}
