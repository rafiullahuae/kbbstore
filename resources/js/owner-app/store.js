/*
 * My store (screen 04), Notifications (05), More & settings (06) and the
 * add-to-home sheet (03) — Petal.
 */
import { S, esc, api, $, $$, ic, logo, top, back, toast, sheet, busy, tgl, ago, th, errorBox, store, standalone, isIOS, dateKey, time, isTablet,
  scr, fn, vars, syncBtn, screen, onScreen, once, landed, isFresh, onReset, ln, blk, times, skChips, skNote } from './core.js';
import { ordersState } from './orders.js';

/* ---------------------------------------------------------- my store */

/* view: the hero's measure; range: its period; top: the top sellers' period (Lane OA4). */
const D = { view: 'total', data: null, range: 'today', top: 'month' };
const RANGES = [['today', 'Today'], ['yesterday', 'Yesterday'], ['7d', 'Last 7 days'], ['month', 'This month'], ['last_month', 'Last month']];
onReset(() => { D.data = null; N.rows = []; N.held = false; });

/**
 * The dashboard's one request. Joins one already in the air; when it lands,
 * redraws the dashboard in place if it is on screen. Failure keeps what is
 * held (grey bars become an error card; real figures stay).
 */
export function fetchDashboard(passive) {
  return once('dash', async () => {
    const g = S.gen;
    const r = await api('GET', 'dashboard?range=' + D.range + '&top=' + D.top, undefined, passive);
    if (g !== S.gen) return false;
    const host = onScreen('dash');
    if (!r.ok) {
      if (host && host.hasAttribute('data-sk')) screen(host, 'dash', 0, header(), errorBox(r.data.message));
      return false;
    }
    D.data = r.data;
    if (!(D.data.figs && D.data.figs[D.view] !== undefined)) D.view = 'total';   // Gross/Net switched off meanwhile
    landed('dash');
    if (!ordersState().loaded) ordersState().counts.processing = r.data.counts.processing || 0;
    document.dispatchEvent(new CustomEvent('oa:badge'));
    if (host) { screen(host, 'dash', 0, header(), body(D.data)); offerInstall(); }
    return true;
  });
}

export async function renderDashboard(view) {
  view.className = 'view single';
  if (!S.me.can.orders) {
    screen(view, 'dash-none', 0, header(), '<div class="card empty">Your role does not include orders. Products and customers are in the tabs below.</div>');
    return;
  }
  if (D.data && isFresh('dash')) { screen(view, 'dash', 0, header(), body(D.data)); offerInstall(); }
  else screen(view, 'dash', 0, header(), dashSkel(), true);
  await fetchDashboard();
}

/* Grey bars in the dashboard's exact shape: hero (figure, three numbers,
   seven-day chart), "Needs you", two tiles, top performers. */
function dashSkel() {
  const met = '<div>' + ln(45, 'k-num') + ln(75, 'k-sm') + '</div>';
  const need = () => '<div class="row">' + blk('tone') + '<div class="rm">' + ln(55) + ln(80, 'k-sm') + '</div></div>';
  const tile = '<div class="card">' + ln(70, 'k-sm') + ln(55, 'k-tile') + '</div>';
  const perf = () => '<div class="row">' + blk('k-rk') + blk('th sm') + '<div class="rm">' + ln(65) + ln(40, 'k-sm') + blk('k-bar') + '</div><div class="re">' + ln(0, 'k-amt') + ln(0, 'k-sm k-short') + '</div></div>';
  return skNote + arrange({
    hero: '<section class="card sk-hero"><div class="hero-top">' + ln(20, 'k-kick') + blk('k-seg') + '</div>'
      + '<div class="fig">' + ln(55, 'k-fig') + '</div><div class="mets">' + met + met + met + '</div>'
      + '<div class="chart"><div class="sk-chart">' + times(7, () => blk('k-col')) + '</div><div class="axis bars">' + times(7, () => '<span>' + ln(55, 'k-ax') + '</span>') + '</div></div>'
      + '<div class="upd">' + ln(35, 'k-sm') + ln(20, 'k-sm') + '</div></section>',
    needs: '<section class="card att"><h4 class="sh">' + ln(25, 'k-h') + '</h4>' + need() + need() + '</section>',
    avg: tile, returning: tile,
    top: '<section class="card tp"><h4 class="sh">' + ln(45, 'k-h') + '</h4>' + times(3, perf) + '</section>',
  });
}

/*
 * The dashboard's sections in the order Customise app gives (Lane OA4), the
 * ones switched off left out. Two tiles side by side share one row, as
 * today; a tile on its own takes the full width.
 */
/* A dashboard link into a screen switched off is plain text, not a dead end. */
const goes = (href) => { const m = /^#\/(orders|products|customers|notifications)/.exec(href || ''); return !m || scr(m[1]); };
const SECTIONS = ['hero', 'needs', 'avg', 'returning', 'top'];
const isTile = (k) => k === 'avg' || k === 'returning';
function arrange(p) {
  const u = S.ui || {}, off = u.sections_off || [];
  const keys = (u.sections || SECTIONS).filter((k) => off.indexOf(k) === -1 && p[k] !== undefined);
  let out = '';
  for (let i = 0; i < keys.length; i++) {
    if (!isTile(keys[i])) { out += p[keys[i]]; continue; }
    const pair = isTile(keys[i + 1]);
    out += '<div class="tiles' + (pair ? '' : ' solo') + '">' + p[keys[i]] + (pair ? p[keys[++i]] : '') + '</div>';
  }
  return out;
}

function header() {
  const d = D.data;
  return '<div class="lt lt-dash"><div class="lt-row"><div><p class="kick">' + (d ? esc(d.date_label) : S.me.can.orders ? ln(0, 'k-sm k-date') : '') + '</p><h2 title="' + esc(S.store || 'My store') + '">' + esc(S.store || 'My store') + '</h2></div>'
    + '<div class="hdr-acts">' + syncBtn() + (scr('notifications') ? '<a class="ib" href="#/notifications" aria-label="Notifications' + (S.unread ? ', ' + S.unread + ' unread' : '') + '">' + ic('bell') + (S.unread ? '<i class="dot"></i>' : '') + '</a>' : '')
    + logo('k-sm sm') + '</div></div></div>';
}

/*
 * The range's bars in the chosen measure: hourly for a day, daily beyond.
 * The bar we are in now (else the best one) is white and carries its figure;
 * a bar still to come is not drawn. Labels thin out so they never collide.
 */
function bars(d) {
  const m = d.bars && d.bars.length && d.bars[0][D.view] !== undefined ? D.view : 'total';
  if (!d.bars || !d.bars.length || d.bars[0][m] === undefined) return '';
  const n = d.bars.length, W = isTablet() ? 620 : 320, gap = n > 10 ? 3 : (W - 30 * n) / (n - 1 || 1), bw = n > 10 ? (W - gap * (n - 1)) / n : 30;
  const max = Math.max(1, ...d.bars.map((b) => b[m]));
  const every = n <= 7 ? 1 : n <= 24 ? 6 : 7;
  const rects = d.bars.map((b, i) => {
    if (b.future) return '';
    const on = i === d.mark, h = Math.max(n > 10 ? 3 : 6, (b[m] / max) * 104), x = +(i * (bw + gap)).toFixed(1), y = (118 - h).toFixed(1);
    return '<rect x="' + x + '" y="' + y + '" width="' + bw.toFixed(1) + '" height="' + h.toFixed(1) + '" rx="' + Math.min(10, bw / 3).toFixed(1) + '" fill="' + (on ? '#fff' : 'rgba(255,255,255,.28)') + '"/>'
      + (on ? '<text x="' + Math.min(W - 14, Math.max(14, x + bw / 2)).toFixed(1) + '" y="' + (110 - h).toFixed(1) + '" text-anchor="middle" font-size="11" font-weight="700" fill="#fff">' + esc(Math.round(b[m]).toLocaleString('en-US')) + '</text>' : '');
  }).join('');
  return '<div class="chart"><svg viewBox="0 0 ' + W + ' 132" role="img" aria-label="Sales, ' + esc(d.range_label) + '">' + rects + '</svg>'
    + '<div class="axis bars">' + d.bars.map((b, i) => '<span>' + (i % every === 0 || n <= 7 ? esc(b.label) : '') + '</span>').join('') + '</div></div>';
}

/* The hero alone: redrawn in place when the range or the measure changes. */
function hero(d) {
  const fig = d.figs ? d.figs[D.view] : null;
  const dp = d.delta ? d.delta[D.view] : null;
  const delta = dp === null || dp === undefined ? ''
    : '<div class="delta-row"><span class="delta' + (dp < 0 ? ' dn' : '') + '">' + ic(dp < 0 ? 'down' : 'up-r') + Math.abs(dp) + '% ' + esc(d.vs) + '</span></div>';
  const label = (RANGES.find(([k]) => k === d.range) || RANGES[0])[1];
  return '<section class="card hero" data-hero><div class="hero-top">'
    + (fn('range') ? '<button type="button" class="rng" data-range aria-label="Sales period: ' + esc(label) + '">' + esc(label) + ic('down', 's') + '</button>' : '<p class="kick">Today</p>')
    + (d.figs && d.figs.gross !== undefined ? '<div class="seg" role="group" aria-label="Sales measure">' + [['total', 'Total'], ['gross', 'Gross'], ['net', 'Net']].map(([k, l]) => '<button type="button" data-dview="' + k + '"' + (D.view === k ? ' class="on"' : '') + '>' + l + '</button>').join('') + '</div>' : '') + '</div>'
    + (fig !== null && fig !== undefined ? '<div class="fig"><small>' + esc(d.currency) + '</small><b data-fig>' + esc(fig) + '</b></div>' + delta : '<p class="kick">Sales figures need the “See revenue and analytics” permission.</p>')
    + '<div class="mets"><div><b>' + d.paid_orders + '</b><span>Paid orders</span></div><div><b>' + d.product_views + '</b><span>Product views</span></div><div><b>' + (d.conversion === null ? '—' : d.conversion + '%') + '</b><span>Conversion</span></div></div>'
    + bars(d) + '<div class="upd"><span class="live">Live · updated ' + esc(time(d.updated_at)) + '</span><span>' + esc(d.figs ? d.span_label : '') + '</span></div></section>';
}

/* Top sellers, with the 7 days | This month switch where the month used to be. */
function topCard(d) {
  return '<section class="card tp" data-top><h4 class="sh">' + (fn('top_period')
    ? 'Top performers<div class="seg sm" role="group" aria-label="Top sellers period">' + [['7d', '7 days'], ['month', 'This month']].map(([k, l]) => '<button type="button" data-tp="' + k + '"' + (d.top_period === k ? ' class="on"' : '') + '>' + l + '</button>').join('') + '</div>'
    : 'Top performers · ' + esc(d.month_label)) + '</h4>'
    + (d.top.length ? d.top.map((t, i) => (scr('products') ? '<a class="row" href="' + (t.id ? '#/products/' + t.id : '#/products') + '">' : '<div class="row">') + '<span class="rk">' + (i + 1) + '</span>' + th(t.thumb, 'k-sm')
      + '<div class="rm"><b>' + esc(t.name) + '</b>' + (t.sales_display ? '<small>Net sales ' + esc(t.sales_display) + '</small>' : '') + '<div class="bar"><i data-sx="' + (t.pct / 100).toFixed(2) + '"></i></div></div>'
      + '<div class="re"><b>' + t.qty + '</b><small class="muted">sold</small></div>' + (scr('products') ? '</a>' : '</div>')).join('') : '<p class="empty">' + (d.top_period === '7d' ? 'No sales in the last 7 days.' : 'No sales yet this month.') + '</p>') + '</section>';
}

function body(d) {
  const needs = d.needs.length ? '<section class="card att"><h4 class="sh">Needs you <span class="muted">' + d.needs.length + '</span></h4>'
    + d.needs.map((n) => (goes(n.href) ? '<a class="row" href="' + esc(n.href) + '">' : '<div class="row">') + '<span class="tone t-' + esc(n.tone) + '">' + ic(n.icon) + '</span><div class="rm"><b>' + esc(n.title) + '</b><small>' + esc(n.sub) + '</small></div>' + (goes(n.href) ? ic('chev', 'chev s') + '</a>' : '</div>')).join('') + '</section>'
    : '<section class="card att"><h4 class="sh">Needs you</h4><div class="row"><span class="tone t-good">' + ic('check') + '</span><div class="rm"><b>All clear</b><small>No failed payments, nothing waiting, no empty shelves.</small></div></div></section>';

  const avg = '<div class="card"><small>Avg. order · ' + esc(d.month_label) + '</small><b>' + esc(d.tiles.avg_order || '—') + '</b></div>';
  const returning = '<div class="card"><small>Returning customers</small><b>' + (d.tiles.returning_pct === null ? '—' : d.tiles.returning_pct + '%') + '</b></div>';

  return arrange({ hero: hero(d), needs, avg, returning, top: topCard(d) });
}

export function dashboardClick(e, view) {
  if (!D.data) return false;
  const b = e.target.closest('[data-dview],[data-range],[data-tp]');
  if (!b) return false;
  if (b.hasAttribute('data-dview')) { D.view = b.getAttribute('data-dview'); swap(view, '[data-hero]', hero(D.data)); return true; }
  if (b.hasAttribute('data-range')) {
    sheet('Sales period', '<div class="stlist">' + RANGES.map(([k, l]) => '<button type="button" data-rk="' + k + '"' + (D.range === k ? ' class="cur"' : '') + '><b>' + l + '</b>' + ic('check') + '</button>').join('') + '</div>', (panel, close) => {
      panel.addEventListener('click', (ev) => {
        const r = ev.target.closest('[data-rk]');
        if (!r) return;
        close();
        if (r.getAttribute('data-rk') !== D.range) { D.range = r.getAttribute('data-rk'); part(view, 'sales', '[data-hero]'); }
      });
    });
    return true;
  }
  if (b.getAttribute('data-tp') !== D.top) { D.top = b.getAttribute('data-tp'); part(view, 'top', '[data-top]'); }
  return true;
}

/* One element of the dashboard replaced in place; nothing else redrawn. */
function swap(view, sel, html) {
  const el = $(sel, view);
  if (!el) return;
  el.insertAdjacentHTML('afterend', html);
  const fresh = el.nextElementSibling;
  el.remove();
  vars(fresh.parentNode);
}

/* A range or period change: one request for that part alone (`part=`), the rest kept. */
async function part(view, which, sel) {
  const el = $(sel, view);
  if (el) el.classList.add('busy');
  const g = S.gen;
  const r = await api('GET', which === 'sales' ? 'dashboard?part=sales&range=' + D.range : 'dashboard?part=top&top=' + D.top);
  if (g !== S.gen || !D.data) return;
  if (!r.ok) { if (el) el.classList.remove('busy'); toast(r.data.message || 'Could not load that period.', true); return; }
  Object.assign(D.data, r.data);
  if (which === 'sales' && !(D.data.figs && D.data.figs[D.view] !== undefined)) D.view = 'total';
  if (onScreen('dash')) swap(view, sel, which === 'sales' ? hero(D.data) : topCard(D.data));
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

export function fetchNotifications(passive) {
  return once('notes', async () => {
    const g = S.gen;
    const r = await api('GET', 'notifications', undefined, passive);
    if (g !== S.gen) return false;
    const host = onScreen('notes');
    if (!r.ok) {
      if (host && host.hasAttribute('data-sk')) screen(host, 'notes', 0, nTop(), errorBox(r.data.message));
      return false;
    }
    N.rows = r.data.events;
    N.held = true;
    landed('notes');
    recount();
    if (host) paintN(host);
    return true;
  });
}

/** Unread = held events newer than the last one he saw. */
export function recount() {
  if (!N.held) return;
  S.unread = S.seen ? N.rows.filter((e) => e.id > S.seen).length : 0;
  document.dispatchEvent(new CustomEvent('oa:badge'));
}

export async function renderNotifications(view) {
  view.className = 'view single';
  if (N.held && isFresh('notes')) paintN(view);
  else screen(view, 'notes', 0, nTop(), skNote + '<div class="chips" aria-hidden="true">' + skChips(4) + '</div><div class="og"><div class="gh">' + ln(20, 'k-h') + '</div><div class="list">'
    + times(5, () => '<div class="row nt">' + blk('tone') + '<div class="rm">' + ln(75) + ln(90, 'k-sm') + '</div>' + ln(0, 'k-tm') + '</div>') + '</div></div>', true);
  await fetchNotifications();
}

const unreadIn = () => N.rows.filter((e) => e.id > S.seen).length;
const nTop = () => top('Notifications', N.rows.length ? (unreadIn() ? unreadIn() + ' unread' : 'All caught up') : '', back('#/more'), '<button class="ib" type="button" data-readall aria-label="Mark all read" title="Mark all read">' + ic('check') + '</button>');

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
  screen(view, 'notes', 0, nTop(), '<div class="chips" role="group" aria-label="Filter notifications">'
    + [['all', 'All'], ['orders', 'Orders'], ['stock', 'Stock'], ['payments', 'Payments']].map(([k, l]) => '<button type="button" data-nf="' + k + '"' + (N.filter === k ? ' class="on"' : '') + '>' + l + '</button>').join('') + '</div>'
    + (groups.length ? groups.map(([g, l]) => '<div class="og"><div class="gh"><span>' + g + '</span></div><div class="list">' + l.map(eventRow).join('') + '</div></div>').join('')
      : '<div class="card empty">Nothing yet. New orders, status changes, payment problems and stock alerts will appear here.</div>'));
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

/**
 * On each unlock: if this phone said yes before, make sure the server has its
 * subscription. Never asks by itself. Resolves true when the server took it
 * (the "Allow notifications" sheet, ask.js, says so in its toast).
 */
export async function syncPush() {
  try {
    if (!pushable() || Notification.permission !== 'granted' || !S.vapid) return false;
    let sub = await currentSub();
    if (sub && !sameKey(sub)) { await sub.unsubscribe(); sub = null; }
    if (!sub) sub = await (await navigator.serviceWorker.ready).pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(S.vapid) });
    return (await api('POST', 'push', sub.toJSON())).ok === true;
  } catch (e) { return false; /* the switch on More shows the truth */ }
}

export async function renderMore(view) {
  view.className = 'view single';
  const me = S.me;
  let on = false;
  try { on = !!(await currentSub()) && Notification.permission === 'granted'; } catch (e) { on = false; }
  const r = (icon, t, right, sub, attrs) => '<' + (attrs && attrs.indexOf('href') === 0 ? 'a ' + attrs : 'div') + ' class="row"><span class="ico">' + ic(icon) + '</span><div class="rm"><b>' + t + '</b>' + (sub ? '<small>' + sub + '</small>' : '') + '</div>' + right + '</' + (attrs && attrs.indexOf('href') === 0 ? 'a' : 'div') + '>';
  const ch = ic('chev', 'chev s');
  // Screens switched off under Customise app (Lane OA4) leave More's list.
  const go = (me.can.customers && scr('customers') ? r('users', 'Customers', ch, '', 'href="#/customers"') : '')
    + (scr('notifications') ? r('bell', 'Notifications', ch, S.unread ? S.unread + ' unread' : 'All caught up', 'href="#/notifications"') : '')
    + (me.can.products && scr('products') ? r('stack', 'Low stock', ch, '', 'href="#/products?low"') : '');
  const g = (k, icon, label) => r(icon, esc(label), tgl(me.notify.indexOf(k) !== -1, label, 'data-group="' + k + '"' + (on ? '' : ' disabled')));
  screen(view, 'more', 0, top('More', ''), ''
    + '<section class="card"><div class="prof">' + logo() + '<div><b>' + esc(me.name) + ' · ' + esc(S.store || 'K-Beauty Bliss') + '</b><small>' + esc(location.hostname) + ' · ' + esc(me.role || '') + '</small></div></div></section>'
    + (go ? '<section class="card set plain-list">' + go + '</section>' : '')
    + '<div class="gh"><span>App</span></div><section class="card set plain-list">'
    + r('homeadd', 'Add to home screen', standalone() ? '<span class="muted">Installed</span>' : '<button type="button" class="tbtn" data-a2>Install</button>', standalone() ? 'Opens full screen from your home screen' : 'Not installed on this phone')
    + r('lock', 'Unlock with PIN', '<span class="muted">Always</span>', 'Locks after ' + S.idle + ' hours away')
    + r('refresh', 'Sync on open', '<span class="muted">Silent</span>', 'Loading bars after ' + esc(Math.round(S.staleMs / 60000)) + ' min away') + '</section>'
    + '<div class="gh"><span>Notifications</span>' + (on ? '<button type="button" data-test>Send a test</button>' : '') + '</div><section class="card set plain-list">'
    + r('device', 'On this phone', tgl(on, 'Notifications on this phone', 'data-push'), pushable() ? (isIOS && !standalone() ? 'On iPhone, add the app to your home screen first' : '') : 'This browser cannot receive notifications')
    + g('orders', 'receipt', 'New orders') + g('status', 'edit', 'Status changes') + g('stock', 'stack', 'Low and out of stock') + g('payments', 'alert', 'Failed payments and refunds') + '</section>'
    + '<div class="gh"><span>This device</span></div><section class="card set plain-list">'
    + r('device', esc(me.device), '<span class="muted">Current</span>', 'Signed in as ' + esc(me.name))
    + '<button type="button" class="row" data-lock><span class="ico">' + ic('lock') + '</span><div class="rm"><b>Lock now</b></div></button>'
    + '<button type="button" class="row" data-forget><span class="ico">' + ic('logout') + '</span><div class="rm"><b class="danger">Log out of this phone</b></div></button></section>'
    + '<p class="ver">Owner app 1.0 · ' + esc(location.hostname) + '</p>');
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
