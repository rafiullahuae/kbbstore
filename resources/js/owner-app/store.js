/*
 * My store (screen 04), Notifications (05), More & settings (06) and the
 * add-to-home sheet (03) — Petal.
 */
import { S, esc, api, $, $$, ic, logo, top, back, toast, sheet, paint, busy, tgl, ago, th, errorBox, skel, store, standalone, isIOS, dateKey, time, isTablet } from './core.js';
import { ordersState } from './orders.js';

/* ---------------------------------------------------------- my store */

const D = { view: 'total', data: null };

export async function renderDashboard(view) {
  view.className = 'view single';
  if (!S.me.can.orders) {
    paint(view, header() + '<div class="body"><div class="card empty">Your role does not include orders. Products and customers are in the tabs below.</div></div>');
    return;
  }
  if (!D.data) paint(view, header() + '<div class="body">' + skel(1, true) + skel(3) + '</div>');
  const r = await api('GET', 'dashboard');
  if (!r.ok) { paint(view, header() + '<div class="body">' + errorBox(r.data.message) + '</div>'); return; }
  D.data = r.data;
  if (!ordersState().loaded) ordersState().counts.processing = r.data.counts.processing || 0;
  document.dispatchEvent(new CustomEvent('oa:badge'));
  paint(view, header() + '<div class="body" data-dash>' + body(r.data) + '</div>');
  offerInstall();
}

function header() {
  const d = D.data;
  return '<div class="lt"><div class="lt-row"><div><p class="kick">' + esc(d ? d.date_label : '') + '</p><h2>My store</h2></div>'
    + '<div class="hdr-acts"><a class="ib" href="#/notifications" aria-label="Notifications' + (S.unread ? ', ' + S.unread + ' unread' : '') + '">' + ic('bell') + (S.unread ? '<i class="dot"></i>' : '') + '</a>'
    + logo('sm') + '</div></div></div>';
}

function bars(d) {
  if (!d.bars || d.bars[0].value === null) return '';
  // A wider canvas on a tablet keeps the bars the height they are on a phone.
  const W = isTablet() ? 620 : 320, bw = 30, gap = (W - bw * 7) / 6;
  const max = Math.max(1, ...d.bars.map((b) => b.value));
  const rects = d.bars.map((b, i) => {
    const h = Math.max(6, (b.value / max) * 104), x = +(i * (bw + gap)).toFixed(1), y = (118 - h).toFixed(1);
    return '<rect x="' + x + '" y="' + y + '" width="' + bw + '" height="' + h.toFixed(1) + '" rx="10" fill="' + (b.today ? '#fff' : 'rgba(255,255,255,.28)') + '"/>'
      + (b.today ? '<text x="' + (x + bw / 2) + '" y="' + (110 - h).toFixed(1) + '" text-anchor="middle" font-size="11" font-weight="700" fill="#fff">' + esc(Math.round(b.value).toLocaleString('en-US')) + '</text>' : '');
  }).join('');
  return '<div class="chart"><svg viewBox="0 0 ' + W + ' 132" role="img" aria-label="Sales for the last 7 days">' + rects + '</svg>'
    + '<div class="axis bars">' + d.bars.map((b) => '<span>' + esc(b.label) + '</span>').join('') + '</div></div>';
}

function body(d) {
  const fig = d.figs ? d.figs[D.view] : null;
  const delta = d.delta_pct === null || d.delta_pct === undefined ? ''
    : '<div class="delta-row"><span class="delta' + (d.delta_pct < 0 ? ' dn' : '') + '">' + ic(d.delta_pct < 0 ? 'down' : 'up-r') + Math.abs(d.delta_pct) + '% vs last ' + esc(d.date_label.split(',')[0]) + '</span></div>';
  const hero = '<section class="card hero"><div class="hero-top"><p class="kick">Today</p>'
    + (d.figs ? '<div class="seg" role="group" aria-label="Sales measure">' + [['total', 'Total'], ['gross', 'Gross'], ['net', 'Net']].map(([k, l]) => '<button type="button" data-dview="' + k + '"' + (D.view === k ? ' class="on"' : '') + '>' + l + '</button>').join('') + '</div>' : '') + '</div>'
    + (fig !== null ? '<div class="fig"><small>' + esc(d.currency) + '</small><b data-fig>' + esc(fig) + '</b></div>' + delta : '<p class="kick">Sales figures need the “See revenue and analytics” permission.</p>')
    + '<div class="mets"><div><b>' + d.paid_orders + '</b><span>Paid orders</span></div><div><b>' + d.product_views + '</b><span>Product views</span></div><div><b>' + (d.conversion === null ? '—' : d.conversion + '%') + '</b><span>Conversion</span></div></div>'
    + bars(d) + '<div class="upd"><span class="live">Live · updated ' + esc(time(d.updated_at)) + '</span><span>' + (d.bars && d.bars[0].value !== null ? 'Last 7 days' : '') + '</span></div></section>';

  const needs = d.needs.length ? '<section class="card att"><h4 class="sh">Needs you <span class="muted">' + d.needs.length + '</span></h4>'
    + d.needs.map((n) => '<a class="row" href="' + esc(n.href) + '"><span class="tone t-' + esc(n.tone) + '">' + ic(n.icon) + '</span><div class="rm"><b>' + esc(n.title) + '</b><small>' + esc(n.sub) + '</small></div>' + ic('chev', 'chev s') + '</a>').join('') + '</section>'
    : '<section class="card att"><h4 class="sh">Needs you</h4><div class="row"><span class="tone t-good">' + ic('check') + '</span><div class="rm"><b>All clear</b><small>No failed payments, nothing waiting, no empty shelves.</small></div></div></section>';

  const tiles = '<div class="tiles"><div class="card"><small>Avg. order · ' + esc(d.month_label) + '</small><b>' + esc(d.tiles.avg_order || '—') + '</b></div>'
    + '<div class="card"><small>Returning customers</small><b>' + (d.tiles.returning_pct === null ? '—' : d.tiles.returning_pct + '%') + '</b></div></div>';

  const tp = '<section class="card tp"><h4 class="sh">Top performers · ' + esc(d.month_label) + '</h4>'
    + (d.top.length ? d.top.map((t, i) => '<a class="row" href="' + (t.id ? '#/products/' + t.id : '#/products') + '"><span class="rk">' + (i + 1) + '</span>' + th(t.thumb, 'sm')
      + '<div class="rm"><b>' + esc(t.name) + '</b>' + (t.sales_display ? '<small>Net sales ' + esc(t.sales_display) + '</small>' : '') + '<div class="bar"><i data-sx="' + (t.pct / 100).toFixed(2) + '"></i></div></div>'
      + '<div class="re"><b>' + t.qty + '</b><small class="muted">sold</small></div></a>').join('') : '<p class="empty">No sales yet this month.</p>') + '</section>';

  return hero + needs + tiles + tp;
}

export function dashboardClick(e, view) {
  const b = e.target.closest('[data-dview]');
  if (!b || !D.data) return false;
  D.view = b.getAttribute('data-dview');
  $$('[data-dview]', view).forEach((x) => x.classList.toggle('on', x === b));
  const f = $('[data-fig]', view);
  if (f && D.data.figs) f.textContent = D.data.figs[D.view];
  return true;
}

/* ----------------------------------------------------- add to home */

let installEvent = null;
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); installEvent = e; });
window.addEventListener('appinstalled', () => { installEvent = null; toast('Installed — it’s on your home screen'); });

/** Offered once, on the first visit, unless the app already runs from the home screen. */
function offerInstall() {
  if (standalone() || store.get('oa.a2')) return;
  store.set('oa.a2', '1');
  setTimeout(openInstall, 600);
}

export function openInstall() {
  const host = location.hostname;
  sheet('', '<div class="a2-head">' + logo() + '<div><b>Add K-Beauty Bliss Owner to your home screen</b><small>' + esc(host) + ' · under 200 KB</small></div></div>'
    + '<ul class="a2-list"><li><span class="tone t-acc">' + ic('bolt') + '</span>Opens full screen in one tap</li><li><span class="tone t-acc">' + ic('bell') + '</span>Order and stock alerts on this phone</li><li><span class="tone t-acc">' + ic('lock') + '</span>Locked with your PIN</li></ul>'
    + (isIOS || !installEvent ? '<div class="a2-ios">On iPhone: tap ' + ic('share') + ' <b>Share</b>, then <b>Add to Home Screen</b> — it opens full screen</div>' : '')
    + '<div class="btns"><button class="btn sec" type="button" data-close>Not now</button>' + (installEvent ? '<button class="btn pri" type="button" data-install>Install app</button>' : '<button class="btn pri" type="button" data-close>Got it</button>') + '</div>',
  (panel, close) => {
    const b = $('[data-install]', panel);
    if (b) b.addEventListener('click', async () => {
      const ev = installEvent;
      installEvent = null;
      close();
      if (ev) { ev.prompt(); await ev.userChoice.catch(() => null); }
    });
  }, 'a2');
}

/* -------------------------------------------------------- notifications */

const N = { filter: 'all', rows: [] };
const KIND = { orders: ['order.new', 'order.status'], stock: ['stock.low', 'stock.out'], payments: ['order.failed', 'order.refunded'] };
const TONE = { 'order.new': ['acc', 'receipt'], 'order.status': ['info', 'refresh'], 'order.failed': ['bad', 'alert'], 'order.refunded': ['acc', 'undo'], 'stock.low': ['warn', 'stack'], 'stock.out': ['bad', 'box'] };

export async function renderNotifications(view) {
  view.className = 'view single';
  paint(view, nTop() + '<div class="body">' + skel(4) + '</div>');
  const r = await api('GET', 'notifications');
  if (!r.ok) { paint(view, nTop() + '<div class="body">' + errorBox(r.data.message) + '</div>'); return; }
  N.rows = r.data.events;
  paintN(view);
}

const unreadIn = () => N.rows.filter((e) => e.id > S.seen).length;
const nTop = () => top('Notifications', N.rows.length ? (unreadIn() ? unreadIn() + ' unread' : 'All caught up') : '', back('#/more'), '<button class="tbtn" type="button" data-readall>Mark all read</button>');

export function eventRow(e) {
  const [tone, icon] = TONE[e.type] || ['acc', 'bell'];
  const href = e.type.indexOf('stock.') === 0 ? '#/products/' + e.ref : '#/orders/' + e.ref;
  return '<a class="row nt' + (e.id > S.seen ? ' unread' : '') + '" href="' + href + '"><span class="tone t-' + tone + '">' + ic(icon) + '</span><div class="rm"><b>' + esc(e.title) + '</b><small>' + esc(e.body) + '</small></div><time>' + esc(ago(e.at)) + '</time></a>';
}

function paintN(view) {
  const rows = N.rows.filter((e) => N.filter === 'all' || KIND[N.filter].indexOf(e.type) !== -1);
  const today = dateKey(new Date()), yest = dateKey(new Date(Date.now() - 864e5));
  const groups = [];
  rows.forEach((e) => {
    const d = dateKey(new Date(e.at)), g = d === today ? 'Today' : d === yest ? 'Yesterday' : 'Earlier';
    if (!groups.length || groups[groups.length - 1][0] !== g) groups.push([g, []]);
    groups[groups.length - 1][1].push(e);
  });
  paint(view, nTop() + '<div class="body"><div class="chips" role="group" aria-label="Filter notifications">'
    + [['all', 'All'], ['orders', 'Orders'], ['stock', 'Stock'], ['payments', 'Payments']].map(([k, l]) => '<button type="button" data-nf="' + k + '"' + (N.filter === k ? ' class="on"' : '') + '>' + l + '</button>').join('') + '</div>'
    + (groups.length ? groups.map(([g, l]) => '<div class="og"><div class="gh"><span>' + g + '</span></div><div class="list">' + l.map(eventRow).join('') + '</div></div>').join('')
      : '<div class="card empty">Nothing yet. New orders, status changes, payment problems and stock alerts will appear here.</div>') + '</div>');
}

export function notificationsClick(e, view) {
  const f = e.target.closest('[data-nf]');
  if (f) { N.filter = f.getAttribute('data-nf'); paintN(view); return true; }
  if (e.target.closest('[data-readall]')) {
    markSeen(Math.max(S.cursor, ...N.rows.map((x) => x.id)));
    paintN(view);
    return true;
  }
  return false;
}

export function markSeen(id) {
  S.seen = Math.max(S.seen, id || 0);
  store.set('oa.seen', S.seen);
  S.unread = 0;
  document.dispatchEvent(new CustomEvent('oa:badge'));
}

/* ---------------------------------------------------------- more */

const pushable = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

function keyBytes(b64) {
  const raw = atob((b64 + '='.repeat((4 - (b64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}
function sameKey(sub) {
  const k = sub && sub.options && sub.options.applicationServerKey;
  if (!k || !S.vapid) return false;
  const a = new Uint8Array(k), b = keyBytes(S.vapid);
  return a.length === b.length && a.every((v, i) => v === b[i]);
}
async function currentSub() {
  if (!pushable()) return null;
  const reg = await navigator.serviceWorker.ready;
  return reg.pushManager.getSubscription();
}

/** On each unlock: if this phone said yes before, make sure the server has its subscription. Never asks by itself. */
export async function syncPush() {
  try {
    if (!pushable() || Notification.permission !== 'granted' || !S.vapid) return;
    let sub = await currentSub();
    if (sub && !sameKey(sub)) { await sub.unsubscribe(); sub = null; }
    if (!sub) sub = await (await navigator.serviceWorker.ready).pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(S.vapid) });
    await api('POST', 'push', sub.toJSON());
  } catch (e) { /* the switch on More shows the truth */ }
}

export async function renderMore(view) {
  view.className = 'view single';
  const me = S.me;
  let on = false;
  try { on = !!(await currentSub()) && Notification.permission === 'granted'; } catch (e) { on = false; }
  const r = (icon, t, right, sub, attrs) => '<' + (attrs && attrs.indexOf('href') === 0 ? 'a ' + attrs : 'div') + ' class="row"><span class="ico">' + ic(icon) + '</span><div class="rm"><b>' + t + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</div>' + right + '</' + (attrs && attrs.indexOf('href') === 0 ? 'a' : 'div') + '>';
  const ch = ic('chev', 'chev s');
  const g = (k, icon, label) => r(icon, esc(label), tgl(me.notify.indexOf(k) !== -1, label, 'data-group="' + k + '"' + (on ? '' : ' disabled')));
  paint(view, top('More', '') + '<div class="body">'
    + '<section class="card"><div class="prof">' + logo() + '<div><b>' + esc(me.name) + ' · K-Beauty Bliss</b><small>' + esc(location.hostname) + ' · ' + esc(me.role || '') + '</small></div></div></section>'
    + '<section class="card set plain-list">'
    + (me.can.customers ? r('users', 'Customers', ch, '', 'href="#/customers"') : '')
    + r('bell', 'Notifications', ch, S.unread ? S.unread + ' unread' : 'All caught up', 'href="#/notifications"')
    + (me.can.products ? r('stack', 'Low stock', ch, '', 'href="#/products?low"') : '') + '</section>'
    + '<div class="gh"><span>App</span></div><section class="card set plain-list">'
    + r('homeadd', 'Add to home screen', standalone() ? '<span class="muted">Installed</span>' : '<button type="button" class="tbtn" data-a2>Install</button>', standalone() ? 'Opens full screen from your home screen' : 'Not installed on this phone')
    + r('lock', 'Unlock with PIN', '<span class="muted">Always</span>', 'Locks after ' + S.idle + ' hours away')
    + r('refresh', 'Refresh on every open', '<span class="muted">Always</span>') + '</section>'
    + '<div class="gh"><span>Notifications</span>' + (on ? '<button type="button" data-test>Send a test</button>' : '') + '</div><section class="card set plain-list">'
    + r('device', 'On this phone', tgl(on, 'Notifications on this phone', 'data-push'), pushable() ? (isIOS && !standalone() ? 'On iPhone, add the app to your home screen first' : '') : 'This browser cannot receive notifications')
    + g('orders', 'receipt', 'New orders') + g('status', 'edit', 'Status changes') + g('stock', 'stack', 'Low and out of stock') + g('payments', 'alert', 'Failed payments and refunds') + '</section>'
    + '<div class="gh"><span>This device</span></div><section class="card set plain-list">'
    + r('device', esc(me.device), '<span class="muted">Current</span>', 'Signed in as ' + esc(me.name))
    + '<button type="button" class="row" data-lock><span class="ico">' + ic('lock') + '</span><div class="rm"><b>Lock now</b></div></button>'
    + '<button type="button" class="row" data-forget><span class="ico">' + ic('logout') + '</span><div class="rm"><b class="danger">Log out of this phone</b></div></button></section>'
    + '<p class="ver">Owner app 1.0 · ' + esc(location.hostname) + '</p></div>');
}

export async function moreClick(e, view, gate) {
  const t = e.target;
  if (t.closest('[data-a2]')) { openInstall(); return; }
  const push = t.closest('[data-push]');
  if (push) {
    await busy(push, async () => {
      if (push.getAttribute('aria-checked') === 'true') {
        const sub = await currentSub().catch(() => null);
        if (sub) await sub.unsubscribe().catch(() => {});
        await api('POST', 'push/off', {});
        toast('Notifications off on this phone');
      } else {
        if (!pushable()) { toast(isIOS && !standalone() ? 'On iPhone, add the app to your home screen first.' : 'This browser cannot receive notifications.', true); return; }
        if (!S.vapid) { toast('Notifications are not set up on the server yet.', true); return; }
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { toast('Notifications are blocked for this site in the browser settings.', true); return; }
        await syncPush();
        toast('Notifications on');
      }
    });
    renderMore(view);
    return;
  }
  const grp = t.closest('[data-group]');
  if (grp && !grp.disabled) {
    grp.setAttribute('aria-checked', grp.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
    const groups = $$('[data-group]', view).filter((x) => x.getAttribute('aria-checked') === 'true').map((x) => x.getAttribute('data-group'));
    const r = await api('POST', 'notify', { groups });
    if (r.ok) { S.me.notify = r.data.groups; toast('Saved'); }
    return;
  }
  if (t.closest('[data-test]')) { const r = await api('POST', 'push/test', {}); toast(r.data.message || (r.ok ? 'Sent' : 'Not sent'), !r.ok); return; }
  if (t.closest('[data-lock]')) { await api('POST', 'lock', {}); gate('pin'); return; }
  if (t.closest('[data-forget]')) {
    sheet('Log out of this phone?', '<p class="hint">You will need your email and PIN to use the app on this phone again. Notifications here stop.</p><div class="btns"><button class="btn sec" type="button" data-close>Cancel</button><button class="btn pri" type="button" data-yes>Log out</button></div>', (panel, close) => {
      $('[data-yes]', panel).addEventListener('click', async () => {
        close();
        const sub = await currentSub().catch(() => null);
        if (sub) await sub.unsubscribe().catch(() => {});
        await api('POST', 'forget', {});
        gate('enrol');
      });
    });
  }
}
