/*
 * K-Beauty Bliss Owner — the entry (Lane MAC, Petal).
 *
 * One small bundle, no framework. The shell page is the same for everybody;
 * what a member sees is fetched after the PIN, every time.
 *
 * ── FRESH ON EVERY OPEN ────────────────────────────────────────────────────
 * Opening the app, and coming back to it (visibilitychange -> visible), lays
 * the blurred "Refreshing the app" card over the screen and re-fetches what is
 * on it, without a page load; each line of the card ticks as its data lands.
 * Nothing else shows it — not a full-screen change, not a poll.
 *
 * ── THE ONE TIMER ──────────────────────────────────────────────────────────
 * While the app is open AND visible it asks every 25 seconds what changed
 * since the last event it saw (GET /api/changes?after=N: one indexed range
 * query, an empty list most of the time). It is the only timer in the app,
 * justified only because the owner asked for the open app to notice new
 * orders on its own — a push message cannot update a page that is already
 * open. A setTimeout chain, never setInterval, so a slow network never stacks
 * requests; it stops the moment the page is hidden and never runs while
 * hidden. When the app is closed, Web Push carries the news instead.
 */
import { S, esc, api, $, $$, ic, logo, toast, onAuthLost, AuthError, store, paint } from './core.js';
import { initFullscreen, fsButton, syncFullscreen } from './fs.js';
import { renderPin, renderEnrol } from './auth.js';
import { renderDashboard, dashboardClick, renderNotifications, notificationsClick, renderMore, moreClick, syncPush, eventRow } from './store.js';
import { renderOrders, ordersClick, ordersState, clearSelection, refreshOrdersList } from './orders.js';
import { renderProducts, renderProduct, productsClick, productClick, setProductFilter, refreshProductsList } from './products.js';
import { renderCustomers, renderCustomer, customersClick } from './customers.js';

const POLL_MS = 25000;
const root = document.getElementById('oa-app');
let view = null, pollT = 0, route = { name: '', id: 0 }, refreshing = false;

S.seen = +(store.get('oa.seen') || 0);
initFullscreen();
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register(S.base + '/sw.js', { scope: S.base + '/' }).catch(() => {});
  navigator.serviceWorker.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'oa:open' && typeof e.data.url === 'string' && e.data.url.charAt(0) === '#') location.hash = e.data.url;
  });
}

onAuthLost((code) => gate(code === 'disabled' ? 'disabled' : code === 'no_device' ? 'enrol' : 'pin'));

/* -------------------------------------------------- the refresh overlay */

function overlay(pulse) {
  const steps = [];
  if (pulse.orders_today !== null) steps.push(['orders', 'Orders', pulse.orders_today + ' today']);
  if (pulse.low_stock !== null) steps.push(['stock', 'Stock levels', pulse.low_stock + ' low']);
  if (pulse.customers_today !== null) steps.push(['customers', 'Customers', pulse.customers_today + ' new']);
  steps.push(['notes', 'Notifications', '']);
  const el = document.createElement('div');
  el.className = 'rf';
  el.setAttribute('role', 'status');
  el.setAttribute('aria-label', 'Refreshing the app');
  el.innerHTML = '<div class="rf-card"><div class="rf-logo"><svg class="rf-ring" viewBox="0 0 84 84" aria-hidden="true"><circle class="bg" cx="42" cy="42" r="39"/><circle class="fg" cx="42" cy="42" r="39"/></svg>' + logo() + '</div>'
    + '<div class="rf-t"><h4 class="a">Refreshing the app</h4><h4 class="b">You’re up to date</h4></div><p>Pulling the latest from ' + esc(location.hostname) + '</p>'
    + '<div class="rf-bar"><i></i></div><ul class="rf-steps">' + steps.map(([k, l, v]) => '<li data-step="' + k + '">' + ic('check') + l + '<span>' + esc(v) + '</span></li>').join('') + '</ul></div>';
  document.body.appendChild(el);
  let done = 0;
  const bar = $('.rf-bar i', el);
  const tick = (k, text) => {
    const li = $('[data-step="' + k + '"]', el);
    if (!li || li.classList.contains('ok')) return;
    if (text !== undefined) li.querySelector('span').textContent = text;
    li.classList.add('ok');
    done++;
    bar.style.transform = 'scaleX(' + Math.max(0.08, done / steps.length) + ')';
  };
  return {
    tick,
    finish() {
      steps.forEach(([k]) => tick(k));
      el.classList.add('done');
      setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 520);
    },
    abort() { el.remove(); },
  };
}

/* ----------------------------------------------------------------- boot */

async function refresh() {
  if (refreshing) return;
  refreshing = true;
  stopPolling();
  let ov = null;
  try {
    const r = await api('GET', 'state');
    if (!r.ok) {
      root.className = 'app pinapp';
      paint(root, '<div class="pin">' + logo() + '<p class="kick">K-Beauty Bliss · Owner</p><h2 class="pin-h">Can’t reach the shop</h2><p class="pin-msg">' + esc(r.data.message || '') + '</p><button type="button" class="btn pri" data-retry>Try again</button></div>');
      return;
    }
    if (r.data.stage !== 'app') { gate(r.data.stage, r.data); return; }
    unlocked(r.data);
    ov = overlay(r.data.pulse || {});
    ['orders', 'stock', 'customers'].forEach((k) => ov.tick(k));
    await Promise.all([show(), catchUp(true).then((n) => ov.tick('notes', n ? n + ' new' : (S.unread ? S.unread + ' unread' : 'none new')))]);
  } catch (e) {
    if (!(e instanceof AuthError)) toast('Could not refresh. Pull down or reopen to try again.', true);
    if (ov) ov.abort();
    ov = null;
  } finally {
    refreshing = false;
    if (ov) ov.finish();
    startPolling();
  }
}

async function gate(stage, info) {
  if (stage === 'pin' && !info) {
    // Locked from inside the app: ask how many digits, so the pad can unlock on the last one.
    const r = await api('GET', 'state').catch(() => null);
    if (r && r.ok && r.data.stage === 'pin') info = r.data;
  }
  stopPolling();
  clearSelection();
  S.stage = stage;
  S.csrf = null;
  view = null;
  if (stage === 'pin') renderPin(root, Object.assign({ name: S.me && S.me.first, idle_hours: S.idle }, info || {}), onIn, toEnrol);
  else if (stage === 'disabled') {
    root.className = 'app pinapp';
    paint(root, fsButton() + '<div class="pin">' + logo() + '<p class="kick">K-Beauty Bliss · Owner</p><h2 class="pin-h">No access</h2><p class="pin-msg">This account no longer has access to the app. Ask the owner.</p><button type="button" class="link" data-reenrol>Sign in as someone else</button></div>');
  } else toEnrol(null);
  syncFullscreen();
}

function toEnrol(note) { renderEnrol(root, onIn, note); syncFullscreen(); }

async function onIn(data) {
  unlocked(data);
  const ov = overlay(data.pulse || {});
  try {
    const st = await api('GET', 'state');
    if (st.ok && st.data.pulse) {
      const p = st.data.pulse;
      if (p.orders_today !== null) ov.tick('orders', p.orders_today + ' today');
      if (p.low_stock !== null) ov.tick('stock', p.low_stock + ' low');
      if (p.customers_today !== null) ov.tick('customers', p.customers_today + ' new');
    }
    await Promise.all([show(), catchUp(true).then((n) => ov.tick('notes', n ? n + ' new' : 'none new'))]);
  } catch (e) { /* gate() has drawn */ } finally { ov.finish(); startPolling(); }
  syncPush();
}

function unlocked(d) {
  const first = S.stage !== 'app' || !view;
  S.stage = 'app';
  S.me = d.me;
  S.csrf = d.csrf || S.csrf;
  S.vapid = d.vapid;
  S.idle = d.idle_hours || S.idle;
  S.tz = d.tz || S.tz;
  S.groups = d.notify_groups || S.groups;
  if (first) frame();
}

/* ---------------------------------------------------------------- frame */

const TABS = [['', 'My store', 'chart'], ['orders', 'Orders', 'receipt'], ['products', 'Products', 'box'], ['more', 'More', 'grid']];

function frame() {
  root.className = 'app';
  const can = S.me.can;
  paint(root, '<div class="main">' + fsButton() + '<div class="view" id="oa-view"></div></div><nav class="nav" aria-label="App sections">'
    + TABS.filter(([k]) => (k !== 'orders' || can.orders) && (k !== 'products' || can.products)).map(([k, l, i]) => '<a href="#/' + k + '" data-tab="' + k + '" aria-label="' + l + '"><em class="nw">' + ic(i) + (k === 'orders' ? '<b class="bdg" data-bdg="orders" hidden></b>' : k === 'more' ? '<b class="bdg" data-bdg="more" hidden></b>' : '') + '</em><span>' + l + '</span></a>').join('')
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
  if (e.target.closest('[data-retry]')) { refresh(); return; }
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

/** @returns the number of new events */
async function catchUp(initial) {
  const r = await api('GET', 'changes?after=' + S.cursor);
  if (!r.ok) return 0;
  const first = S.cursor === 0;
  S.cursor = r.data.cursor;
  if (first) {
    if (!S.seen) { S.seen = S.cursor; store.set('oa.seen', S.seen); }
    const u = await api('GET', 'notifications');
    if (u.ok) S.unread = u.data.events.filter((x) => x.id > S.seen).length;
    badges();
    return 0;
  }
  const ev = r.data.events;
  if (!ev.length) return 0;
  S.unread += ev.length;
  badges();
  if (!initial) {
    const last = ev[ev.length - 1];
    toast(ev.length === 1 ? last.title : ev.length + ' updates · ' + last.title);
    if (route.name === '') await renderDashboard(view);
    else if (route.name === 'orders') await refreshOrdersList(view);
    else if (route.name === 'products' && !route.id) await refreshProductsList(view);
    else if (route.name === 'notifications') { const l = $('.list', view); if (l) l.insertAdjacentHTML('afterbegin', ev.slice().reverse().map(eventRow).join('')); }
  }
  return ev.length;
}

function startPolling() {
  stopPolling();
  if (document.visibilityState !== 'visible' || S.stage !== 'app') return;
  pollT = setTimeout(async () => {
    pollT = 0;
    try { await catchUp(false); } catch (e) { return; }
    startPolling();
  }, POLL_MS);
}

function stopPolling() {
  if (pollT) clearTimeout(pollT);
  pollT = 0;
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') { stopPolling(); return; }
  if (S.stage === 'app') refresh();
});

function badges() {
  const more = $('[data-bdg="more"]', root), ord = $('[data-bdg="orders"]', root);
  if (more) { more.hidden = S.unread < 1; more.textContent = S.unread > 99 ? '99+' : String(S.unread); }
  const proc = ordersState().counts.processing;
  if (ord) { ord.hidden = !proc; ord.textContent = proc > 999 ? '999+' : String(proc || ''); }
}
document.addEventListener('oa:badge', badges);

refresh();
