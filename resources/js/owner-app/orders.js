/*
 * Orders (screens 07–09 and the tablet split view) — Petal.
 *
 * Grouped Today / Yesterday / Earlier, status chips with counts, search that
 * waits for a pause in typing, bulk selection (long-press a row, tap a
 * customer's initials, or "Select" in the header; Lane ORD),
 * and the order itself: products, payment with Tabby/Tamara instalments,
 * shipping, customer, attribution and the notes timeline.
 *
 * Every write goes to the server, which runs the ADMIN's own action — the
 * same emails, the same stock, the same order notes.
 */
import { S, fn, esc, api, $, $$, ic, av, th, pill, stWord, top, back, toast, sheet, busy, debounce, paint, errorBox, day, time, when, year, isTablet,
  screen, onScreen, mark, once, landed, isFresh, onReset, merge, ln, blk, times, skChips, skNote } from './core.js';

const L = { q: '', status: '', from: '', to: '', payment: '', rows: [], next: null, counts: {}, payments: [], emails: {}, today: '', yesterday: '', sel: new Set(), mode: false, loaded: false, at: null, paged: false, lastQ: '', cur: 0 };
let O = null;
onReset(() => { Object.assign(L, { rows: [], next: null, counts: {}, payments: [], emails: {}, mode: false, loaded: false, at: null, paged: false, lastQ: '', cur: 0 }); L.sel.clear(); O = null; });

/* Selection mode (Lane ORD): on while anything is ticked, and also straight
   after "Select" with nothing ticked yet. */
const selecting = () => L.mode || L.sel.size > 0;
const SETTABLE = ['pending', 'processing', 'onhold', 'shipped', 'completed', 'cancelled'];
const appEl = () => document.getElementById('oa-app');

export const ordersState = () => L;

function query(extra) {
  const p = new URLSearchParams();
  [['q', L.q], ['status', L.status], ['from', L.from], ['to', L.to], ['payment', L.payment]].forEach(([k, v]) => { if (v) p.set(k, v); });
  if (extra) Object.keys(extra).forEach((k) => p.set(k, extra[k]));
  const s = p.toString();
  return 'orders' + (s ? '?' + s : '');
}

/* --------------------------------------------------------------- routing */

/*
 * Held and fresh: the list (and the open order) are drawn at once from
 * memory and refreshed silently. Otherwise grey bars in their shape until the
 * request lands. Either way one request per list and per order.
 */
export async function renderOrders(view, id) {
  const held = L.loaded && isFresh('orders');
  if (isTablet()) {
    const pd = $('[data-pd]', view);
    if (pd && L.loaded && held) {                 // the split is up and fresh: only the right pane changes
      markCur(view, id);
      if (id) await loadDetail(pd, id, true);
      return;
    }
    view.className = 'view';
    paint(view, '<div class="split"><div class="pane-l" data-pl>' + listShell(held) + '</div><div class="pane-d" data-pd></div></div>');
    mark(view, 'orders', 0, !held);
    wireSearch(view);
    const pane = $('[data-pd]', view);
    if (held) paintList(view);
    const first = id || (held && L.rows[0] && L.rows[0].id);
    if (first) {
      markCur(view, first);
      await Promise.all([fetchOrders(), loadDetail(pane, first, true)]);
      return;
    }
    screen(pane, 'order', 0, top('Order', '', '', '', 'nosync'), orderSkel(), true);
    await fetchOrders();
    const f = L.rows[0] && L.rows[0].id;
    markCur(view, f);
    if (f) await loadDetail(pane, f, true);
    else screen(pane, 'order', 0, top('Orders', '', '', '', 'nosync'), '<div class="card empty">No orders yet.</div>');
    return;
  }
  view.className = 'view single';
  if (id) { await loadDetail(view, id, false); return; }
  paint(view, listShell(held));
  mark(view, 'orders', 0, !held);
  wireSearch(view);
  if (held) paintList(view);
  await fetchOrders();
}

function markCur(view, id) {
  L.cur = +id || 0;
  $$('.row.ord', view).forEach((r) => r.classList.toggle('cur', +r.getAttribute('data-o') === L.cur));
}

/* ------------------------------------------------------------------ list */

const selHdr = () => '<header class="top selhdr"><button class="ib plain" type="button" data-act="selnone" aria-label="Clear selection">' + ic('x') + '</button><div class="tt"><h3><span data-count>0</span> selected</h3></div><button class="tbtn" type="button" data-act="selall">Select all</button></header>';
const bulkBar = () => '<div class="bulk" role="region" aria-label="Bulk actions"><div class="bulk-h"><span><b data-count>0</b> selected</span><button type="button" data-act="selnone">Cancel</button></div><div class="bulk-a">'
  + '<button type="button" data-act="bulk" data-v="processing">' + ic('refresh') + 'Processing</button><button type="button" data-act="bulk" data-v="completed">' + ic('check') + 'Complete</button>'
  + '<button type="button" data-act="bulk" data-v="onhold">' + ic('clock') + 'On hold</button><button type="button" data-act="bulk-more">' + ic('dots') + 'Set status…</button></div></div>';

/* "Select" in the header: selection mode without a long-press. */
const selBtn = () => '<button class="tbtn" type="button" data-act="selmode">Select</button>';

function listShell(held) {
  return selHdr() + top('Orders', '<span data-sub>' + (held ? '' : 'Syncing…') + '</span>', '', selBtn(), 'norm')
    + '<div class="body"><label class="search">' + ic('search', 's') + '<input type="search" data-search placeholder="Search orders, names, products" aria-label="Search orders" value="' + esc(L.q) + '" enterkeyhint="search"><kbd>/</kbd></label>'
    + '<div class="chips" role="group" aria-label="Filter by status" data-chips>' + (held ? '' : skChips(5)) + '</div><div data-list>' + (held ? '' : listSkel()) + '</div></div>' + bulkBar();
}

/* Grey bars shaped like the list: a day heading and order rows (initials,
   number and name, date line, total and status pill). */
const listSkel = () => skNote + '<div class="og"><div class="gh">' + ln(15, 'k-h') + '</div><div class="list">'
  + times(6, () => '<div class="row ord">' + blk('av') + '<div class="rm">' + ln(55) + ln(40, 'k-sm') + '</div><div class="re">' + ln(0, 'k-amt') + blk('k-pill') + '</div></div>') + '</div></div>';

/* …and the order: name and status, products, payment. */
const orderSkel = () => skNote + '<div class="od-sum">' + ln(55, 'k-nm') + '<div class="ln">' + blk('k-pill') + ln(35, 'k-sm') + '</div></div>'
  + '<section class="card"><h4 class="sh">' + ln(25, 'k-h') + '</h4>' + times(2, () => '<div class="row">' + blk('th') + '<div class="rm">' + ln(70) + ln(45, 'k-sm') + '</div><div class="re">' + ln(0, 'k-amt') + '</div></div>') + '</section>'
  + '<section class="card"><h4 class="sh">' + ln(20, 'k-h') + '</h4>' + times(4, () => '<div class="kv">' + ln(30) + ln(0, 'k-amt') + '</div>') + '</section>';

function wireSearch(view) {
  const input = $('[data-search]', view);
  if (!input) return;
  input.addEventListener('input', debounce(() => { L.q = input.value.trim(); loadList(view); }, 300));
}

/**
 * The list's one request for the current filters. Joins one already in the
 * air for the same filters; `force` starts afresh (after a write or a new
 * filter). Lands into the list if it is on screen — never under a finger
 * mid-selection. A newer first page keeps the pages he had already opened.
 */
export function fetchOrders(passive, force) {
  const q = query();
  return once('orders?' + q, async () => {
    const g = S.gen;
    const r = await api('GET', q, undefined, passive);
    if (g !== S.gen || q !== query()) return false;    // locked, or the filters moved on
    const host = onScreen('orders');
    if (!r.ok) {
      const list = host && $('[data-list]', host);
      if (list && host.hasAttribute('data-sk')) { paint(list, errorBox(r.data.message)); mark(host, 'orders', 0, false); }
      return false;
    }
    const keep = q === L.lastQ && L.paged;
    L.rows = merge(r.data.orders, L.rows, keep);
    if (!keep) { L.next = r.data.next; L.paged = false; }
    L.lastQ = q;
    if (r.data.counts) { L.counts = r.data.counts; L.payments = r.data.payments || []; L.emails = r.data.emails || {}; }
    L.today = r.data.today || L.today; L.yesterday = r.data.yesterday || L.yesterday;
    L.loaded = true;
    L.at = new Date().toISOString();
    landed('orders');
    if (host && !selecting()) { paintList(host); mark(host, 'orders', 0, false); }
    document.dispatchEvent(new CustomEvent('oa:badge'));
    return true;
  }, force);
}

async function loadList(view, more) {
  if (!more) { await fetchOrders(false, true); return; }
  const r = await api('GET', query({ before: L.next }));
  if (!r.ok) { toast(r.data.message || 'Could not load more.', true); return; }
  L.rows = L.rows.concat(r.data.orders);
  L.next = r.data.next;
  L.paged = true;
  if ($('[data-list]', view)) paintList(view);
}

function paintList(view) {
  const total = Object.values(L.counts).reduce((a, b) => a + b, 0);
  const sub = $('[data-sub]', view);
  if (sub) sub.textContent = 'Updated ' + time(L.at) + ' · ' + total.toLocaleString('en-US') + ' order' + (total === 1 ? '' : 's');
  const chips = [['', 'All', total], ['processing', 'Processing'], ['pending', 'Pending'], ['onhold', 'On hold'], ['shipped', 'Shipped'], ['completed', 'Completed'], ['failed', 'Failed'], ['cancelled', 'Cancelled'], ['refunded', 'Refunded']]
    .filter(([k]) => k === '' || L.counts[k] || L.status === k);
  const filtered = L.from || L.to || L.payment;
  paint($('[data-chips]', view), chips.map(([k, l, n]) => '<button type="button" data-chip="' + k + '"' + (L.status === k ? ' class="on"' : '') + '>' + l + '<span>' + (k ? (L.counts[k] || 0) : n) + '</span></button>').join('')
    + '<button type="button" data-act="filters"' + (filtered ? ' class="on"' : '') + '>' + ic('filter', 's') + ' Filters</button>');

  const groups = [];
  L.rows.forEach((o) => {
    const g = o.day === L.today ? 'Today' : o.day === L.yesterday ? 'Yesterday' : 'Earlier';
    if (!groups.length || groups[groups.length - 1][0] !== g) groups.push([g, []]);
    groups[groups.length - 1][1].push(o);
  });
  paint($('[data-list]', view), (groups.length ? groups.map(([g, rows], i) => '<div class="og"><div class="gh"><span>' + g + '</span>' + (i === 0 ? '<button type="button" data-act="selall">Select all</button>' : '') + '</div><div class="list">' + rows.map(row).join('') + '</div></div>').join('')
    : '<div class="card empty">' + (L.q || L.status || filtered ? 'No orders match.' : 'No orders yet.') + '</div>')
    + (L.next ? '<button type="button" class="btn sec wide" data-act="more">Show more</button>' : ''));
  syncSel(view);
}

export function row(o) {
  return '<div class="row ord' + (L.sel.has(o.id) ? ' on' : '') + (o.id === L.cur ? ' cur' : '') + '" data-o="' + o.id + '"><button type="button" class="sel" data-act="sel" aria-label="Select order ' + esc(o.number) + '">' + av(o.name) + ic('check') + '</button>'
    + '<a class="rm" href="#/orders/' + o.id + '"><b>#' + esc(o.number) + ' <span>' + esc(o.name) + '</span></b><small>' + esc(day(o.created_at)) + ' · ' + esc(time(o.created_at))
    + (o.items !== null && o.items !== undefined ? ' · ' + o.items + ' item' + (o.items === 1 ? '' : 's') : '') + '</small>'
    + (o.source ? '<small class="src">' + esc(o.source) + '</small>' : '') + '</a>'
    + '<a class="re" href="#/orders/' + o.id + '" tabindex="-1"><b>' + esc(o.total_display) + '</b>' + pill(o.status) + '</a></div>';
}

/** A history row (customer screen): no selection. */
export const plainRow = (o) => '<a class="row" href="#/orders/' + o.id + '"><div class="rm"><b>#' + esc(o.number) + '</b><small>' + esc(day(o.created_at)) + ' ' + esc(year(o.created_at)) + '</small></div><div class="re re-row">' + pill(o.status) + '<b>' + esc(o.total_display) + '</b></div></a>';

function syncSel(view) {
  const host = $('[data-pl]', view) || view;
  const n = L.sel.size;
  host.classList.toggle('selecting', selecting());
  appEl().classList.toggle('selecting', selecting());
  $$('[data-count]', host).forEach((x) => { x.textContent = n; });
}

export function clearSelection() {
  L.sel.clear();
  L.mode = false;
  const app = appEl();
  if (app) app.classList.remove('selecting');
}

/* ---------------------------------------------------------------- detail */

async function loadDetail(host, id, pane) {
  if (O && O.id === +id && isFresh('order:' + id)) { O.pane = pane; paintDetail(host); }
  else screen(host, 'order', id, top('Order', '', pane ? '' : back('#/orders', 'Back to orders'), '', pane ? 'nosync' : ''), orderSkel(), true);
  await fetchOrder(id);
}

/** One order's request; lands into whichever pane or screen shows it. */
export function fetchOrder(id, passive, force) {
  return once('order:' + id, async () => {
    const g = S.gen;
    const r = await api('GET', 'orders/' + id, undefined, passive);
    if (g !== S.gen) return false;
    const host = onScreen('order', id);
    const pane = !!host && host.hasAttribute('data-pd');
    if (!r.ok) {
      if (host && host.hasAttribute('data-sk')) screen(host, 'order', id, top('Order', '', pane ? '' : back('#/orders'), '', pane ? 'nosync' : ''), errorBox(r.data.message || 'This order could not be opened.'));
      return false;
    }
    O = r.data.order;
    O.pane = pane;
    landed('order:' + id);
    if (host) paintDetail(host);
    return true;
  }, force);
}

function payBlock(o) {
  const p = o.payment, ins = p.instalments;
  if (ins) {
    const prov = p.kind === 'tabby' ? 'Tabby' : 'Tamara';
    return '<div class="paid' + (p.paid ? '' : ' wait') + '">' + ic(p.paid ? 'check' : 'clock') + '<div><b>' + (p.paid ? 'Paid ' : 'Awaiting ') + esc(o.totals.total) + ' via ' + prov + '</b>'
      + esc(ins.plan) + ' × ' + esc(ins.each_display) + '. No interest, no fees.' + (p.paid ? ' The shop is paid in full.' : '') + '</div>'
      + '<div class="inst' + (ins.parts.length === 3 ? ' n3' : '') + '">' + ins.parts.map((x) => '<div class="' + (x.done ? 'pd' : '') + '"><i></i><b>' + esc(x.amount.replace(/^[A-Z]{3}\s*/, '')) + '</b>' + esc(x.date) + '</div>').join('') + '</div></div>';
  }
  return '<div class="paid' + (p.paid ? '' : ' wait') + '">' + ic(p.paid ? 'card' : 'clock') + '<div><b>' + (p.paid ? 'Paid ' : 'Not paid · ') + esc(o.totals.total) + ' · ' + esc(p.title) + '</b>' + (p.paid_at ? esc(when(p.paid_at)) : (p.paid ? '' : 'Waiting for payment')) + '</div></div>';
}

function addr(a) {
  if (!a) return '<div class="addr"><span>None given</span></div>';
  return '<div class="addr"><b>' + esc(a.name) + '</b>' + [a.company, a.line1, a.line2, [a.city, a.state].filter(Boolean).join(', ')].filter(Boolean).map((x) => '<br>' + esc(x)).join('')
    + '<br><span>' + esc([a.country, a.phone].filter(Boolean).join(' · ')) + '</span></div>';
}

function paintDetail(host) {
  const o = O, t = o.totals;
  const right = '<a class="ib plain" href="' + (o.next_id ? '#/orders/' + o.next_id : '#/orders/' + o.id) + '" aria-label="Newer order"' + (o.next_id ? '' : ' aria-disabled="true"') + '>' + ic('up') + '</a>'
    + '<a class="ib plain" href="' + (o.prev_id ? '#/orders/' + o.prev_id : '#/orders/' + o.id) + '" aria-label="Older order"' + (o.prev_id ? '' : ' aria-disabled="true"') + '>' + ic('down') + '</a>';
  const primary = o.can.paid ? '<button type="button" class="btn pri" data-act="paid">' + ic('card', 's') + 'Mark as paid</button>'
    : (o.can.status && ['processing', 'onhold', 'shipped'].indexOf(o.status) !== -1 ? '<button type="button" class="btn pri" data-act="setst" data-v="completed">' + ic('check', 's') + 'Mark complete</button>' : '');
  const phone = o.phone || (o.shipping && o.shipping.phone) || '';
  screen(host, 'order', o.id, top('Order #' + esc(o.number), esc(day(o.created_at)) + ' · ' + esc(t.total), O.pane ? '' : back('#/orders', 'Back to orders'), right, O.pane ? 'nosync' : ''), ''
    + '<div class="od-sum"><p class="nm">' + esc(o.customer.name || o.email) + '</p><div class="ln">'
    + (o.can.status ? '<button type="button" class="pill" data-s="' + esc(pillCode(o.status)) + '" data-act="status" aria-label="Change status, now ' + esc(stWord(o.status)) + '">' + esc(stWord(o.status)) + ' ' + ic('edit') + '</button>' : pill(o.status))
    + '<span>' + esc(when(o.created_at)) + '</span></div></div>'
    + (primary ? '<div class="btns od-act">' + primary + '</div>' : '')
    + '<section class="card"><h4 class="sh">Products <span class="muted">' + o.items.reduce((a, i) => a + i.qty, 0) + ' items</span></h4>'
    + o.items.map((i) => '<' + (i.product_id ? 'a href="#/products/' + i.product_id + '"' : 'div') + ' class="row">' + th(i.thumb) + '<div class="rm"><b class="cl2">' + esc(i.name) + '</b><small>' + (i.variant ? esc(i.variant) + ' · ' : '') + i.qty + ' × ' + esc(i.unit_display) + (i.sku ? ' · ' + esc(i.sku) : '') + '</small></div><div class="re"><b>' + esc(i.total_display) + '</b></div></' + (i.product_id ? 'a' : 'div') + '>').join('') + '</section>'
    + '<section class="card"><h4 class="sh">Payment</h4><div class="kv"><span>Products</span><span>' + esc(t.subtotal) + '</span></div>'
    + (t.discount ? '<div class="kv"><span>Discount' + (t.coupon ? ' <span class="tag">' + esc(t.coupon) + '</span>' : '') + '</span><span>−' + esc(t.discount) + '</span></div>' : '')
    + '<div class="kv"><span>Shipping' + (o.shipping_method ? ' · ' + esc(o.shipping_method) : '') + '</span><span>' + esc(t.shipping) + '</span></div>'
    + (t.fees ? '<div class="kv"><span>Fees</span><span>' + esc(t.fees) + '</span></div>' : '')
    + (t.tax ? '<div class="kv"><span>VAT</span><span>' + esc(t.tax) + '</span></div>' : '')
    + '<div class="kv tot"><span>Order total</span><span>' + esc(t.total) + '</span></div>'
    + (t.refunded ? '<div class="kv"><span>Refunded</span><span class="danger">−' + esc(t.refunded) + '</span></div>' : '') + payBlock(o) + '</section>'
    + '<section class="card"><h4 class="sh">Shipping</h4>' + addr(o.shipping || o.billing) + (o.shipping_method ? '<div class="kv"><span>Method</span><span>' + esc(o.shipping_method) + '</span></div>' : '')
    + '<details><summary class="lnk">' + ic('down', 's') + 'Show billing</summary>' + addr(o.billing) + '</details></section>'
    + '<section class="card"><h4 class="sh">Customer</h4><' + (o.customer.id ? 'a href="#/customers/' + o.customer.id + '"' : 'div') + ' class="row">' + av(o.customer.name || o.email) + '<div class="rm"><b>' + esc(o.customer.name || o.email) + '</b><small>' + o.customer.orders + ' order' + (o.customer.orders === 1 ? '' : 's') + ' · ' + esc(o.customer.spent_display) + ' lifetime</small></div>' + (o.customer.id ? ic('chev', 'chev s') : '') + '</' + (o.customer.id ? 'a' : 'div') + '>'
    + '<div class="cu-act"><a href="mailto:' + esc(o.email) + '">' + ic('mail') + 'Email</a>'
    + (phone ? '<a href="tel:' + esc(phone) + '">' + ic('phone') + 'Call</a><a href="https://wa.me/' + esc(phone.replace(/\D/g, '')) + '" target="_blank" rel="noopener noreferrer">' + ic('chat') + 'WhatsApp</a>' : '<span>' + ic('phone') + 'Call</span><span>' + ic('chat') + 'WhatsApp</span>') + '</div>'
    + (o.customer_note ? '<div class="kv"><span>Customer note</span><span>' + esc(o.customer_note) + '</span></div>' : '') + '</section>'
    + (o.origin ? '<section class="card"><h4 class="sh">Attribution</h4><div class="kv"><span>Origin</span><span>' + esc(o.origin) + '</span></div></section>' : '')
    + '<section class="card"><h4 class="sh">Order notes' + (o.can.note ? ' <button type="button" class="tbtn" data-act="note">+ Add a note</button>' : '') + '</h4><ul class="tl" data-tl>'
    + o.notes.map(noteLi).join('') + '</ul></section>');
}

const noteLi = (n, fresh) => '<li class="' + (n.customer ? 'c ' : '') + esc(n.tone || '') + (fresh ? ' new' : '') + '">' + esc(n.content) + '<small>' + esc(when(n.at)) + (n.author ? ' · ' + esc(n.author) : '') + '</small></li>';
const pillCode = (s) => ({ pending: 'pend', processing: 'proc', onhold: 'hold', shipped: 'ship', completed: 'done', cancelled: 'canc', refunded: 'ref', failed: 'fail' }[s] || 'pend');

/* ---------------------------------------------------------------- clicks */

export async function ordersClick(e, view) {
  const b = e.target.closest('[data-act],[data-chip]');
  const act = b ? b.getAttribute('data-act') : null;
  const rowEl = e.target.closest('.row.ord');

  if (b && b.hasAttribute('data-chip')) { L.status = b.getAttribute('data-chip'); await loadList(view); return true; }
  // Bulk status change switched off (Customise app, Lane OA4): no selecting at all; the server refuses it too.
  if (!fn('bulk') && ['sel', 'selall', 'selnone', 'bulk', 'bulk-more'].indexOf(act) !== -1) return true;
  if (!fn('bulk') && act === 'selmode') return true;
  // The click a long-press ends with is not a tap (Lane ORD).
  if (rowEl && LP.fired) { LP.fired = false; e.preventDefault(); return true; }
  if (act === 'sel' && rowEl) { e.preventDefault(); toggle(view, rowEl); return true; }
  if (rowEl && selecting() && !act) { e.preventDefault(); toggle(view, rowEl); return true; }
  if (act === 'selmode') { L.mode = true; syncSel(view); return true; }
  if (act === 'selall') { L.mode = true; L.rows.forEach((o) => L.sel.add(o.id)); $$('.row.ord', view).forEach((r) => r.classList.add('on')); syncSel(view); return true; }
  if (act === 'selnone') { L.sel.clear(); L.mode = false; $$('.row.ord.on', view).forEach((r) => r.classList.remove('on')); syncSel(view); return true; }
  if (act === 'more') { await busy(b, () => loadList(view, true)); return true; }
  if (act === 'filters') { filters(view); return true; }
  if (act === 'bulk') { askBulk(view, b.getAttribute('data-v')); return true; }
  if (act === 'bulk-more') { if (L.sel.size) statusSheet('Set ' + L.sel.size + ' order' + (L.sel.size === 1 ? '' : 's') + ' to…', '', (s) => askBulk(view, s)); return true; }

  if (!O) return false;
  const host = O.pane ? $('[data-pd]', view) : view;
  if (act === 'status') { statusSheet('Order status', O.status, (s) => setStatus(host, s)); return true; }
  if (act === 'setst') { await busy(b, () => setStatus(host, b.getAttribute('data-v'))); return true; }
  if (act === 'note') { noteSheet(host); return true; }
  if (act === 'paid') { paidSheet(host); return true; }
  return false;
}

function toggle(view, rowEl) {
  const id = +rowEl.getAttribute('data-o');
  if (L.sel.has(id)) L.sel.delete(id); else L.sel.add(id);
  rowEl.classList.toggle('on', L.sel.has(id));
  // Untick the last one and selection mode ends, as it always did.
  if (!L.sel.size) L.mode = false;
  syncSel(view);
}

/* ------------------------------------------------------------ long-press */

/*
 * LONG-PRESS A ROW TO START SELECTING (Lane ORD). Pointer events, so one path
 * serves a finger, a stylus and a mouse; no library, no timer left running.
 *
 *   held LP_MS without moving more than LP_SLOP px  -> selection mode, that
 *                                                      row ticked, a buzz
 *   moved further (a scroll, a swipe)               -> nothing
 *   the browser takes the gesture (pointercancel)   -> nothing
 *
 * The click that follows the release is swallowed by ordersClick (LP.fired),
 * so the row is not toggled straight back off or opened. The phone's own
 * long-press menu ("Open in new tab") is held back on these rows only.
 */
export const LP_MS = 450;
export const LP_SLOP = 10;
const LP = { t: 0, x: 0, y: 0, row: null, fired: false };

function lpStop() { clearTimeout(LP.t); LP.t = 0; LP.row = null; }

function lpDown(e) {
  LP.fired = false;
  if (e.button > 0 || !fn('bulk')) return;
  const rowEl = e.target.closest && e.target.closest('.list .row.ord');
  if (!rowEl || e.target.closest('[data-act]')) return;
  lpStop();
  LP.row = rowEl; LP.x = e.clientX; LP.y = e.clientY;
  LP.t = setTimeout(() => {
    const r = LP.row;
    lpStop();
    if (!r || !r.isConnected) return;
    const view = r.closest('.view');
    if (!view) return;
    LP.fired = true;
    L.mode = true;
    const id = +r.getAttribute('data-o');
    if (!L.sel.has(id)) { L.sel.add(id); r.classList.add('on'); }
    syncSel(view);
    try { if (navigator.vibrate) navigator.vibrate(12); } catch (err) { /* no haptics here */ }
  }, LP_MS);
}

function lpMove(e) {
  if (!LP.t) return;
  if (Math.abs(e.clientX - LP.x) > LP_SLOP || Math.abs(e.clientY - LP.y) > LP_SLOP) lpStop();
}

if (typeof document !== 'undefined') {
  document.addEventListener('pointerdown', lpDown, { passive: true });
  document.addEventListener('pointermove', lpMove, { passive: true });
  document.addEventListener('pointerup', lpStop, { passive: true });
  document.addEventListener('pointercancel', lpStop, { passive: true });
  document.addEventListener('scroll', lpStop, { passive: true, capture: true });
  document.addEventListener('contextmenu', (e) => {
    if ((LP.t || LP.fired) && e.target.closest && e.target.closest('.list .row.ord')) e.preventDefault();
  });
}

function statusSheet(title, cur, pick) {
  sheet(title, '<div class="stlist">' + SETTABLE.map((s) => '<button type="button" data-st="' + s + '"' + (s === cur ? ' class="cur"' : '') + '>' + pill(s) + ic('check') + '</button>').join('') + '</div>', (panel, close) => {
    panel.addEventListener('click', (e) => {
      const b = e.target.closest('[data-st]');
      if (!b) return;
      close();
      if (b.getAttribute('data-st') !== cur) pick(b.getAttribute('data-st'));
    });
  });
}

async function setStatus(host, status) {
  const r = await api('POST', 'orders/' + O.id + '/status', { status });
  if (!r.ok) { toast(r.data.message || 'Not changed.', true); return; }
  const was = O.status;
  toast('#' + O.number + ' is now ' + stWord(r.data.status));
  await fetchOrder(O.id, false, true);
  const lr = L.rows.find((x) => x.id === O.id);
  if (lr) { lr.status = r.data.status; const el = document.querySelector('.row.ord[data-o="' + O.id + '"] .pill'); if (el) { el.setAttribute('data-s', pillCode(r.data.status)); el.textContent = stWord(r.data.status); } }
  if (was !== r.data.status) { L.counts[was] = Math.max(0, (L.counts[was] || 1) - 1); L.counts[r.data.status] = (L.counts[r.data.status] || 0) + 1; }
}

/*
 * THE CONFIRMATION STEP (Lane ORD). Nothing is sent when a status is picked:
 * a sheet says what Proceed will do -- how many orders, to what, and whether
 * the customers are emailed -- and only Proceed sends the one request.
 */
function askBulk(view, status) {
  const n = L.sel.size;
  if (!n || SETTABLE.indexOf(status) === -1) return;
  sheet('Confirm', '<p class="hint" data-confirm>Set <b>' + n + '</b> order' + (n === 1 ? '' : 's') + ' to <b>' + esc(stWord(status)) + '</b>?'
    + (L.emails[status] ? ' Customers will be emailed.' : '') + ' A note is added to each order.</p>'
    + '<div class="btns"><button class="btn sec" type="button" data-close>Cancel</button><button class="btn pri" type="button" data-proceed>Proceed</button></div>', (panel, close) => {
    $('[data-proceed]', panel).addEventListener('click', async (e) => {
      await busy(e.currentTarget, () => bulk(view, status));
      close();
    });
  });
}

async function bulk(view, status) {
  const ids = Array.from(L.sel);
  if (!ids.length) return;
  const r = await api('POST', 'orders-bulk-status', { ids, status });
  if (!r.ok) { toast(r.data.message || 'Not changed.', true); return; }
  const moved = (r.data.changed_ids || []).slice();
  const force = r.data.skipped.filter((s) => s.forceable);
  if (force.length) {
    const ok = await confirmSheet(force.length + ' paid order' + (force.length === 1 ? '' : 's') + ' would leave the revenue figures', 'Change ' + (force.length === 1 ? 'it' : 'them') + ' to ' + stWord(status) + ' anyway?', 'Change anyway');
    if (ok) {
      const r2 = await api('POST', 'orders-bulk-status', { ids: force.map((s) => s.id), status, force: true });
      if (r2.ok) { r.data.changed += r2.data.changed; moved.push(...(r2.data.changed_ids || [])); r.data.skipped = r.data.skipped.filter((s) => !s.forceable); }
    }
  }
  // The rows move in place at once; the list's own request follows to bring
  // the chip counts and the groups up to date.
  moved.forEach((id) => {
    const lr = L.rows.find((x) => x.id === id);
    if (lr) lr.status = status;
    const el = document.querySelector('.row.ord[data-o="' + id + '"] .pill');
    if (el) { el.setAttribute('data-s', pillCode(status)); el.textContent = stWord(status); }
  });
  const same = (r.data.unchanged_ids || []).length;
  const left = r.data.skipped.filter((s) => s.forceable).length;
  const refused = r.data.skipped.filter((s) => !s.forceable);
  toast(r.data.changed + ' updated' + (same ? ', ' + same + ' skipped: already ' + stWord(status) : '') + (left ? ', ' + left + ' left alone' : '')
    + (refused.length ? ', ' + refused.length + ' refused: ' + refused[0].reason : ''), refused.length > 0);
  L.sel.clear();
  L.mode = false;
  $$('.row.ord.on', view).forEach((x) => x.classList.remove('on'));
  syncSel(view);
  await loadList(view);
  if (O && ids.indexOf(O.id) !== -1) await fetchOrder(O.id, false, true);
}

function confirmSheet(title, text, yes) {
  return new Promise((resolve) => {
    let answered = false;
    sheet(title, '<p class="hint">' + esc(text) + '</p><div class="btns"><button class="btn sec" type="button" data-no>Leave them</button><button class="btn pri" type="button" data-yes>' + esc(yes) + '</button></div>', (panel, close) => {
      panel.addEventListener('click', (e) => {
        if (e.target.closest('[data-yes]')) { answered = true; close(); resolve(true); }
        else if (e.target.closest('[data-no],[data-close]')) { answered = true; close(); resolve(false); }
      });
    });
    setTimeout(() => { if (!answered && !document.querySelector('.sheet.open')) resolve(false); }, 60000);
  });
}

function filters(view) {
  const pay = '<option value="">Any method</option>' + L.payments.map((p) => '<option value="' + esc(p.id) + '"' + (L.payment === p.id ? ' selected' : '') + '>' + esc(p.title) + '</option>').join('');
  sheet('Filter orders', '<label class="fld"><span>From</span><span class="inp"><input type="date" name="from" value="' + esc(L.from) + '"></span></label>'
    + '<label class="fld"><span>To</span><span class="inp"><input type="date" name="to" value="' + esc(L.to) + '"></span></label>'
    + '<label class="fld"><span>Payment method</span><span class="inp"><select name="payment">' + pay + '</select></span></label>'
    + '<div class="btns"><button class="btn sec" type="button" data-clear>Clear</button><button class="btn pri" type="button" data-apply>Show orders</button></div>', (panel, close) => {
    panel.addEventListener('click', (e) => {
      if (e.target.closest('[data-clear]')) { L.from = L.to = L.payment = ''; }
      else if (e.target.closest('[data-apply]')) { L.from = panel.querySelector('[name=from]').value; L.to = panel.querySelector('[name=to]').value; L.payment = panel.querySelector('[name=payment]').value; }
      else return;
      close();
      loadList(view);
    });
  });
}

function noteSheet(host) {
  sheet('Add a note', '<textarea class="ta" maxlength="5000" placeholder="Only your team sees this" aria-label="Note"></textarea><p class="alert" data-err hidden></p><button class="btn pri wide" type="button" data-save>Add note</button>', (panel, close) => {
    const ta = $('textarea', panel);
    $('[data-save]', panel).addEventListener('click', async (e) => {
      const content = ta.value.trim();
      if (!content) { ta.focus(); return; }
      await busy(e.currentTarget, async () => {
        const r = await api('POST', 'orders/' + O.id + '/notes', { content });
        if (!r.ok) { const er = $('[data-err]', panel); er.hidden = false; er.textContent = r.data.message || 'Not added.'; return; }
        close();
        O.notes.unshift(r.data.note);
        const tl = $('[data-tl]', host);
        if (tl) tl.insertAdjacentHTML('afterbegin', noteLi(r.data.note, true));
        toast('Note added');
      });
    });
  });
}

function paidSheet(host) {
  const opts = O.paid_methods.map((m) => '<option value="' + esc(m.id) + '">' + esc(m.title) + '</option>').join('');
  sheet('Mark as paid', '<label class="fld"><span>Paid with</span><span class="inp"><select name="m">' + opts + '</select></span></label>'
    + '<label class="fld"><span>Reference (optional)</span><span class="inp"><input name="ref" maxlength="120"></span></label>'
    + '<label class="fld"><span>Then move it to</span><span class="inp"><select name="st"><option value="processing">Processing</option><option value="completed">Completed</option><option value="">Leave the status</option></select></span></label>'
    + '<p class="alert" data-err hidden></p><button class="btn pri wide" type="button" data-save>Mark as paid</button>', (panel, close) => {
    $('[data-save]', panel).addEventListener('click', async (e) => {
      const body = { payment_method: panel.querySelector('[name=m]').value, reference: panel.querySelector('[name=ref]').value || null };
      const st = panel.querySelector('[name=st]').value;
      if (st) body.status = st;
      await busy(e.currentTarget, async () => {
        const r = await api('POST', 'orders/' + O.id + '/mark-paid', body);
        if (!r.ok) { const er = $('[data-err]', panel); er.hidden = false; er.textContent = r.data.message || 'Not saved.'; return; }
        close();
        toast(r.data.message || 'Marked as paid');
        await fetchOrder(O.id, false, true);
      });
    });
  });
}

/** Refresh the list quietly (the live poll found changes). */
export async function refreshOrdersList() {
  if (selecting() || !onScreen('orders')) return;
  await fetchOrders(true);
}
