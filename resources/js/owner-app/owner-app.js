/*
 * K-Beauty Bliss Owner — the entry (Lane MAC, Petal).
 *
 * One small bundle, no framework. The shell page is the same for everybody;
 * what a member sees is fetched after the PIN, every time.
 *
 * ── SILENT WHEN FRESH, GREY BARS WHEN NOT (Lane OA2, the owner's words) ────
 * "if user open the page, and then open again after 1 minute, it should not
 * give any loading experience, it should silently refreshed. but if user open
 * after 40 minutes or 1 hour, it should give loading bars and instantly sync
 * all." Coming back (visibilitychange -> visible, or pageshow from the
 * back-forward cache) asks one question of what is on screen: did its section
 * last land within S.staleMs (Platform → Users & Roles → Owner app → "Show
 * loading bars after (minutes)", 30 by default)? Yes: nothing visible happens,
 * every section refreshes in the background (X-OA-Passive) and the values
 * swap in place. No: that screen turns to grey bars in its own shape and every
 * section syncs in parallel, the bars giving way as each request lands. There
 * is no overlay and no spinner on open. No timer measures the age — it is
 * read off Date.now() when the page comes back.
 *
 * ── SYNC NOW ───────────────────────────────────────────────────────────────
 * The refresh icon in every screen's header syncs everything the member may
 * see, plus the open order / product / customer. The icon spins (CSS; still
 * under reduced motion), the screen stays usable, a tap mid-sync joins the
 * sync in flight instead of starting another, and a failure is a small toast
 * over the data he already had.
 *
 * ── THE ONE TIMER ──────────────────────────────────────────────────────────
 * While the app is open AND visible it asks every 25 seconds (Customise app:
 * 15–120 s, or off — Lane OA4) what changed
 * since the last event it saw (GET /api/changes?after=N: one indexed range
 * query, an empty list most of the time). It is the only timer in the app,
 * justified only because the owner asked for the open app to notice new
 * orders on its own — a push message cannot update a page that is already
 * open. A setTimeout chain, never setInterval, so a slow network never stacks
 * requests; it stops the moment the page is hidden and never runs while
 * hidden. When the app is closed, Web Push carries the news instead.
 */
import { S, esc, api, $, $$, ic, logo, toast, onAuthLost, AuthError, store, paint, setKey, setSpin, resetData, isFresh, once, applyUi, scr, fn } from './core.js';
import { initFullscreen, fsButton, syncFullscreen, nudgeReinstall } from './fs.js';
import { renderPin, renderEnrol } from './auth.js';
import { renderDashboard, dashboardClick, renderNotifications, notificationsClick, renderMore, moreClick, syncPush, fetchDashboard, fetchNotifications, recount } from './store.js';
import { renderOrders, ordersClick, ordersState, clearSelection, refreshOrdersList, fetchOrders, fetchOrder } from './orders.js';
import { renderProducts, renderProduct, productsClick, productClick, setProductFilter, refreshProductsList, fetchProducts, fetchProduct } from './products.js';
import { renderCustomers, renderCustomer, customersClick, fetchCustomers, fetchCustomer } from './customers.js';
import { offerPush } from './ask.js';

/* The live check: 25 s unless Customise app sets 15–120 s, or switches it off (Lane OA4). */
const pollMs = () => Math.max(15, Math.min(120, +(S.ui && S.ui.live_seconds) || 25)) * 1000;
const root = document.getElementById('oa-app');
let view = null, pollT = 0, route = { name: '', id: 0 }, booting = false, syncing = null;

S.seen = +(store.get('oa.seen') || 0);
initFullscreen();
nudgeReinstall();
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register(S.base + '/sw.js', { scope: S.base + '/' }).catch(() => {});
  navigator.serviceWorker.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'oa:open' && typeof e.data.url === 'string' && e.data.url.charAt(0) === '#') location.hash = e.data.url;
  });
}

/* Several requests of one sync can fail auth together: the first one gates. */
onAuthLost((code) => { if (S.stage === 'app') gate(code === 'disabled' ? 'disabled' : code === 'no_device' ? 'enrol' : 'pin'); });

/* ----------------------------------------------------------------- boot */

/** A fresh launch (or a reload): ask where this phone stands, then draw. */
async function boot() {
  if (booting) return;
  booting = true;
  stopPolling();
  try {
    const r = await api('GET', 'state');
    if (!r.ok) {
      root.className = 'app pinapp';
      paint(root, '<div class="pin">' + logo() + '<p class="kick">K-Beauty Bliss · Owner</p><h2 class="pin-h">Can’t reach the shop</h2><p class="pin-msg">' + esc(r.data.message || '') + '</p><button type="button" class="btn pri" data-retry>Try again</button></div>');
      return;
    }
    if (r.data.stage !== 'app') { gate(r.data.stage, r.data); return; }
    // The session cookie is alive but this tab holds no key (a fresh launch):
    // the PIN, which hands one back.
    if (!S.csrf) { gate('pin', { name: r.data.me && r.data.me.first, pin_length: r.data.pin_length, idle_hours: r.data.idle_hours }); return; }
    unlocked(r.data);
    opened();
  } catch (e) {
    if (!(e instanceof AuthError)) toast('Could not refresh. Tap the sync icon to try again.', true);
  } finally {
    booting = false;
  }
}

/** First draw after boot or unlock: nothing is held, so grey bars and every section at once. */
function opened() {
  show().catch(() => {});
  sync(false, false);
  startPolling();
}

/**
 * Back in front. Fresh: draw nothing new, refresh every section silently.
 * Stale: this screen turns to grey bars, and everything syncs.
 */
function resume() {
  if (S.stage !== 'app' || !view) { if (S.stage === 'app') boot(); return; }
  const stale = !freshHere();
  if (stale) show().catch(() => {});
  sync(false, !stale);
  startPolling();
}

/** Is the section on screen within the threshold? (More holds no shop data.) */
function freshHere() {
  const n = route.name, id = route.id, can = S.me.can;
  if (n === 'orders') return isFresh(id ? 'order:' + id : 'orders');
  if (n === 'products') return isFresh(id ? 'product:' + id : 'products');
  if (n === 'customers') return isFresh(id ? 'customer:' + id : 'customers');
  if (n === 'notifications') return isFresh('notes');
  if (n === '') return !can.orders || isFresh('dash');
  return true;
}

/**
 * Every section this member may see, plus the open record, in parallel —
 * one request each, and a section already in the air is joined, not asked
 * twice. A second trigger while one runs gets the same promise back.
 * `manual` is the header icon: it spins, and a failure says so. `passive`
 * marks the silent refresh, which must not count as use for the idle lock.
 */
function sync(manual, passive) {
  if (manual) setSpin(true);
  if (syncing) return syncing;
  const t0 = Date.now(), can = S.me.can, id = route.id, quiet = !manual && passive;
  const jobs = [
    api('GET', 'state', undefined, quiet).then((r) => {
      if (!r.ok) return false;
      if (r.data.stage !== 'app') { if (S.stage === 'app') gate(r.data.stage, r.data); return true; }
      unlocked(r.data);
      return true;
    }),
  ];
  // A screen or function switched off is never asked for: the server would refuse it.
  if (fn('live')) jobs.push(catchUp(true, quiet));
  if (scr('notifications')) jobs.push(fetchNotifications(quiet));
  if (can.orders && scr('store')) jobs.push(fetchDashboard(quiet));
  if (can.orders && scr('orders')) jobs.push(fetchOrders(quiet));
  if (can.products && scr('products')) jobs.push(fetchProducts(quiet));
  if (can.customers && scr('customers')) jobs.push(fetchCustomers(quiet));
  if (id && route.name === 'orders' && can.orders && scr('orders')) jobs.push(fetchOrder(id, quiet));
  if (id && route.name === 'products' && can.products && scr('products')) jobs.push(fetchProduct(id, quiet));
  if (id && route.name === 'customers' && can.customers && scr('customers')) jobs.push(fetchCustomer(id, quiet));
  syncing = Promise.allSettled(jobs).then((res) => {
    const failed = res.some((x) => (x.status === 'rejected' && !(x.reason instanceof AuthError)) || x.value === false);
    if (failed && S.stage === 'app' && (manual || S.spinning)) toast('Could not sync everything. Showing what you had.', true);
    badges();
  }).finally(() => {
    syncing = null;
    // A sync quicker than one turn still shows one turn, then stops.
    const left = 600 - (Date.now() - t0);
    if (S.spinning) { if (left > 0) setTimeout(() => setSpin(false), left); else setSpin(false); }
  });
  return syncing;
}

async function gate(stage, info) {
  // Locked, signed out or barred: nothing of the shop stays in this page.
  S.stage = stage;
  stopPolling();
  clearSelection();
  setKey(null);
  resetData();
  setSpin(false);
  view = null;
  if (stage === 'pin' && !info) {
    // Locked from inside the app: ask how many digits, so the pad can unlock on the last one.
    const r = await api('GET', 'state').catch(() => null);
    if (r && r.ok && r.data.stage === 'pin') info = r.data;
    else if (r && r.ok && r.data.stage === 'app' && r.data.me) info = { name: r.data.me.first, pin_length: r.data.pin_length };
  }
  if (S.stage !== stage) return;
  if (stage === 'pin') renderPin(root, Object.assign({ name: S.me && S.me.first, idle_hours: S.idle }, info || {}), onIn, toEnrol);
  else if (stage === 'disabled') {
    root.className = 'app pinapp';
    paint(root, fsButton() + '<div class="pin">' + logo() + '<p class="kick">K-Beauty Bliss · Owner</p><h2 class="pin-h">No access</h2><p class="pin-msg">This account no longer has access to the app. Ask the owner.</p><button type="button" class="link" data-reenrol>Sign in as someone else</button></div>');
  } else toEnrol(null);
  syncFullscreen();
}

function toEnrol(note) { S.stage = 'enrol'; setKey(null); resetData(); renderEnrol(root, onIn, note); syncFullscreen(); }

/** A correct PIN (or a new sign-in): the response carries the key. */
function onIn(data) {
  setKey(data.csrf);
  unlocked(data);
  opened();
  syncPush();
  // Installed, permission undecided, setting on: offer "Allow notifications" (ask.js, Lane NT).
  offerPush(syncPush);
}

function unlocked(d) {
  const first = S.stage !== 'app' || !view;
  S.stage = 'app';
  S.me = d.me || S.me;
  S.vapid = d.vapid;
  S.askPush = d.ask_push === true;
  S.idle = d.idle_hours || S.idle;
  S.staleMs = (d.stale_minutes || Math.round(S.staleMs / 60000)) * 60000;
  if (typeof d.store === 'string' && d.store) S.store = d.store;
  S.tz = d.tz || S.tz;
  S.groups = d.notify_groups || S.groups;
  // Customise app (Lane OA4): classes on <html> before the frame is drawn; a
  // change to the screens redraws the frame on the next sync.
  const tabs = navKey();
  if (d.ui) applyUi(d.ui);
  if (first || tabs !== navKey()) { frame(); if (!first) show().catch(() => {}); }
  if (!first && !pollT && fn('live')) startPolling();   // switched back on while open
}

/* ---------------------------------------------------------------- frame */

const TABS = [['', 'My store', 'chart'], ['orders', 'Orders', 'receipt'], ['products', 'Products', 'box'], ['more', 'More', 'grid']];
/* The tabs this member sees: role first, then Customise app. More is always there. */
const tabsOn = () => TABS.filter(([k]) => (k !== '' || scr('store')) && (k !== 'orders' || (S.me.can.orders && scr('orders'))) && (k !== 'products' || (S.me.can.products && scr('products'))));
const navKey = () => (S.me ? tabsOn().map(([k]) => k).join() : '');
/* A screen that is switched off, by name: the address falls through to the first tab still on. */
const SCR = { '': 'store', orders: 'orders', products: 'products', customers: 'customers', notifications: 'notifications' };
const offHere = (n) => SCR[n] !== undefined && !scr(SCR[n]);

function frame() {
  root.className = 'app';
  paint(root, '<div class="main"><div class="view" id="oa-view"></div></div><nav class="nav" aria-label="App sections">'
    + tabsOn().map(([k, l, i]) => '<a href="#/' + k + '" data-tab="' + k + '" aria-label="' + l + '"><em class="nw">' + ic(i) + (k === 'orders' ? '<b class="bdg" data-bdg="orders" hidden></b>' : k === 'more' ? '<b class="bdg" data-bdg="more" hidden></b>' : '') + '</em><span>' + l + '</span></a>').join('')
    + '</nav>');
  view = document.getElementById('oa-view');
  syncFullscreen();
}

function parse() {
  const [path, q] = (location.hash || '#/').slice(2).split('?');
  const parts = path.split('/').filter(Boolean);
  return { name: parts[0] || '', id: parts[1] ? parseInt(parts[1], 10) || 0 : 0, q: q || '' };
}

async function show() {
  if (S.stage !== 'app' || !view) return;
  const prev = route.name;
  route = parse();
  if (offHere(route.name)) { const t = tabsOn()[0][0]; route = { name: t, id: 0, q: '' }; history.replaceState(null, '', '#/' + t); }
  const tab = ['customers', 'notifications'].indexOf(route.name) !== -1 ? 'more' : route.name;
  $$('[data-tab]', root).forEach((t) => {
    const on = t.getAttribute('data-tab') === tab;
    t.classList.toggle('on', on);
    if (on) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
  });
  if (route.name !== 'orders' || prev !== 'orders') clearSelection();
  const n = route.name;
  if (n === 'orders' && route.q) { ordersState().status = route.q; history.replaceState(null, '', '#/orders'); }
  if (n === 'products' && route.q) { setProductFilter(route.q); history.replaceState(null, '', '#/products'); }
  if (n === 'orders') await renderOrders(view, route.id);
  else if (n === 'products') await (route.id ? renderProduct(view, route.id) : renderProducts(view));
  else if (n === 'customers') await (route.id ? renderCustomer(view, route.id) : renderCustomers(view));
  else if (n === 'notifications') await renderNotifications(view);
  else if (n === 'more') await renderMore(view);
  else await renderDashboard(view);
  badges();
}

window.addEventListener('hashchange', () => { if (S.stage === 'app') show().catch(() => {}); });

root.addEventListener('click', async (e) => {
  if (e.target.closest('[data-retry]')) { boot(); return; }
  if (e.target.closest('[data-sync]')) { sync(true); return; }
  if (e.target.closest('[data-reenrol]')) { toEnrol(null); return; }
  if (S.stage !== 'app' || !view || !view.contains(e.target)) return;
  try {
    const n = route.name;
    if (n === 'orders' && await ordersClick(e, view)) return;
    if (n === 'products' && !route.id && await productsClick(e, view)) return;
    if (n === 'products' && route.id && await productClick(e, view)) return;
    if (n === 'customers' && await customersClick(e, view)) return;
    if (n === 'notifications' && notificationsClick(e, view)) return;
    if (n === '' && dashboardClick(e, view)) return;
    if (n === 'more') await moreClick(e, view, gate);
  } catch (err) {
    if (!(err instanceof AuthError)) toast('That did not work. Try again.', true);
  }
});

/* "/" focuses the search on a keyboard (tablets with one). */
document.addEventListener('keydown', (e) => {
  if (e.key !== '/' || /input|textarea|select/i.test((e.target && e.target.tagName) || '')) return;
  const s = view && $('[data-search]', view);
  if (s) { e.preventDefault(); s.focus(); }
});

/* ------------------------------------------------------- live: the cursor */

/** @returns false when the request failed, otherwise true */
function catchUp(initial, passive) {
  return once('changes', async () => {
    const g = S.gen;
    const r = await api('GET', 'changes?after=' + S.cursor, undefined, passive);
    if (!r.ok || g !== S.gen) return r.ok;
    const first = S.cursor === 0;
    S.cursor = r.data.cursor;
    if (first) {
      if (!S.seen) { S.seen = S.cursor; store.set('oa.seen', S.seen); }
      recount();
      return true;
    }
    const ev = r.data.events;
    if (!ev.length) return true;
    S.unread += ev.length;
    badges();
    if (!initial) {
      const last = ev[ev.length - 1];
      toast(ev.length === 1 ? last.title : ev.length + ' updates · ' + last.title);
      // Only what is on screen, silently, in place.
      if (route.name === '') await fetchDashboard(true);
      else if (route.name === 'orders') await refreshOrdersList();
      else if (route.name === 'products' && !route.id) await refreshProductsList();
      else if (route.name === 'notifications') await fetchNotifications(true);
    }
    return true;
  });
}

function startPolling() {
  stopPolling();
  if (document.visibilityState !== 'visible' || S.stage !== 'app' || !fn('live')) return;
  pollT = setTimeout(async () => {
    pollT = 0;
    try { await catchUp(false, true); } catch (e) { return; }
    startPolling();
  }, pollMs());
}

function stopPolling() {
  if (pollT) clearTimeout(pollT);
  pollT = 0;
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') { stopPolling(); return; }
  if (S.stage === 'app') resume();
});
// Back from the back-forward cache: the page never unloaded, so treat it as a return.
window.addEventListener('pageshow', (e) => { if (e.persisted && S.stage === 'app') resume(); });

function badges() {
  const more = $('[data-bdg="more"]', root), ord = $('[data-bdg="orders"]', root);
  if (more) { more.hidden = S.unread < 1 || !scr('notifications'); more.textContent = S.unread > 99 ? '99+' : String(S.unread); }
  const proc = ordersState().counts.processing;
  if (ord) { ord.hidden = !proc; ord.textContent = proc > 999 ? '999+' : String(proc || ''); }
}
document.addEventListener('oa:badge', badges);

boot();
