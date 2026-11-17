// KBB Studio — core/prefs.js: the owner's preferences survive restarts, updates and a cleared browser.
//
// Browser storage belongs to the page's ORIGIN (http://127.0.0.1:<port>). Until 17 Nov the engine took a random port
// on every launch, so every launch was a new origin and everything kept in localStorage was gone: "Later" on the Setup
// screen, the company last open, the themes options, the translate panel's last language, the design brief draft.
// The engine now keeps one port (engine/main.go preferredPort), and on top of that these preferences are kept by the
// engine in KBB Studio Data/settings/prefs.json under "ui", so they survive even when the port is busy, the browser
// changes or its site data is cleared.
//
// How: restorePrefs() (boot, before any screen reads them) copies the engine's values into localStorage, and from then
// on every localStorage write to a key on the SYNC list is passed on to the engine (POST /api/prefs?merge=1, batched).
// The code that owns each key keeps using localStorage exactly as before; nothing else changes. Per-browser trivia (a
// hint seen once) is not on the list and stays in this browser only.

const SYNC = [/^kbbstudio\.setupLater$/, /^kbbstudio\.workspace$/, /^kbbstudio\.brief\.[\w-]+$/, /^kbb\.translate\.last$/, /^kbbstudio\.themes\.opts$/, /^kbbstudio\.hover\.\w+$/];
const MAX = 4000; // one value; prefs.json as a whole stays under the engine's 16 KB
const synced = (k) => typeof k === 'string' && SYNC.some((re) => re.test(k));
const token = () => document.querySelector('meta[name="rs-token"]')?.content || '';

let pending = null;
let timer = 0;
function send(keepalive = false) {
  clearTimeout(timer); timer = 0;
  if (!pending) return;
  const ui = pending; pending = null;
  fetch('/api/prefs?merge=1', { method: 'POST', keepalive, headers: { 'X-RS-Token': token(), 'Content-Type': 'application/json' }, body: JSON.stringify({ ui }) })
    .catch(() => { pending = Object.assign(ui, pending || {}); }); // engine gone (Stop): kept for the next write
}
function queue(k, v) {
  if (v != null && String(v).length > MAX) return;
  (pending ||= {})[k] = v == null ? null : String(v);
  if (!timer) timer = setTimeout(send, 300);
}

let installed = false;
/** copy the engine's preferences into localStorage, then pass every later write of a synced key on to the engine */
export async function restorePrefs() {
  let ui = {};
  try {
    const r = await fetch('/api/prefs', { cache: 'no-store', headers: { 'X-RS-Token': token() } });
    if (r.ok) ui = (await r.json())?.ui || {};
  } catch { return; } // no engine: localStorage alone, as before
  let ls = null; try { ls = window.localStorage; } catch {}
  if (!ls || installed) return;
  installed = true;
  const P = Storage.prototype, set = P.setItem, rm = P.removeItem;
  for (const [k, v] of Object.entries(ui)) if (synced(k) && typeof v === 'string') { try { set.call(ls, k, v); } catch {} }
  // a value this browser has and the engine does not (set before this version, or while the engine was busy): keep it
  for (let i = 0; i < ls.length; i++) { const k = ls.key(i); if (synced(k) && !(k in ui)) queue(k, ls.getItem(k)); }
  P.setItem = function (k, v) { set.call(this, k, v); if (this === ls && synced(k)) queue(k, v); };
  P.removeItem = function (k) { rm.call(this, k); if (this === ls && synced(k)) queue(k, null); };
  addEventListener('pagehide', () => send(true));
}
