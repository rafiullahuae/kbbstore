/*
 * Orders (screens 07–09 and the tablet split view) — Petal.
 *
 * Grouped Today / Yesterday / Earlier, status chips with counts, search that
 * waits for a pause in typing, bulk selection (tap a customer's initials),
 * and the order itself: products, payment with Tabby/Tamara instalments,
 * shipping, customer, attribution and the notes timeline.
 *
 * Every write goes to the server, which runs the ADMIN's own action — the
 * same emails, the same stock, the same order notes.
 */
import { S, esc, api, $, $$, ic, av, th, pill, stWord, top, back, toast, sheet, busy, debounce, paint, errorBox, skel, day, time, when, year, isTablet } from './core.js';

const L = { q: '', status: '', from: '', to: '', payment: '', rows: [], next: null, counts: {}, payments: [], today: '', yesterday: '', sel: new Set(), loaded: false, at: null };
let O = null;
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

export async function renderOrders(view, id) {
  if (isTablet()) {
    const pd = $('[data-pd]', view);
    if (pd && L.loaded) {                         // the split is up: only the right pane changes
      markCur(view, id);
      if (id) await loadDetail(pd, id, true);
      return;
    }
    view.className = 'view';
    paint(view, '<div class="split"><div class="pane-l" data-pl>' + listShell() + '</div><div class="pane-d" data-pd>' + top('Order', '') + '<div class="body">' + skel(1, true) + '</div></div></div>');
    wireSearch(view);
    await loadList(view);
    const first = id || (L.rows[0] && L.rows[0].id);
    markCur(view, first);
    if (first) await loadDetail($('[data-pd]', view), first, true);
    else paint($('[data-pd]', view), top('Orders', '') + '<div class="body"><div class="card empty">No orders yet.</div></div>');
    return;
  }
  view.className = 'view single';
  if (id) { await loadDetail(view, id, false); return; }
  paint(view, listShell());
  wireSearch(view);
  await loadList(view);
}

function markCur(view, id) {
  $$('.row.ord', view).forEach((r) => r.classList.toggle('cur', +r.getAttribute('data-o') === +id));
}

/* ------------------------------------------------------------------ list */

const selHdr = () => '<header class="top selhdr"><button class="ib plain" type="button" data-act="selnone" aria-label="Clear selection">' + ic('x') + '</button><div class="tt"><h3><span data-count>0</span> selected</h3></div><button class="tbtn" type="button" data-act="selall">Select all</button></header>';
const bulkBar = () => '<div class="bulk" role="region" aria-label="Bulk actions"><div class="bulk-h"><span><b data-count>0</b> selected</span><button type="button" data-act="selnone">Cancel</button></div><div class="bulk-a">'
  + '<button type="button" data-act="bulk" data-v="processing">' + ic('refresh') + 'Processing</button><button type="button" data-act="bulk" data-v="completed">' + ic('check') + 'Complete</button>'
  + '<button type="button" data-act="bulk" data-v="onhold">' + ic('clock') + 'On hold</button><button type="button" data-act="bulk-more">' + ic('dots') + 'More</button></div></div>';

function listShell() {
  return selHdr() + top('Orders', '<span data-sub>Updated ' + esc(time(new Date().toISOString())) + '</span>', '', '', 'norm')
    + '<div class="body"><label class="search">' + ic('search', 's') + '<input type="search" data-search placeholder="Search orders, names, products" aria-label="Search orders" value="' + esc(L.q) + '" enterkeyhint="search"><kbd>/</kbd></label>'
    + '<div class="chips" role="group" aria-label="Filter by status" data-chips></div><div data-list>' + skel(5) + '</div></div>' + bulkBar();
}

function wireSearch(view) {
  const input = $('[data-search]', view);
  if (!input) return;
  input.addEventListener('input', debounce(() => { L.q = input.value.trim(); loadList(view); }, 300));
}

async function loadList(view, more) {
  const r = await api('GET', query(more ? { before: L.next } : null));
  const list = $('[data-list]', view);
  if (!list) return;
  if (!r.ok) { paint(list, errorBox(r.data.message)); return; }
  L.rows = more ? L.rows.concat(r.data.orders) : r.data.orders;
  L.next = r.data.next;
  if (r.data.counts) { L.counts = r.data.counts; L.payments = r.data.payments || []; }
  L.today = r.data.today || L.today; L.yesterday = r.data.yesterday || L.yesterday;
  L.loaded = true;
  L.at = new Date().toISOString();
  paintList(view);
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
  return '<div class="row ord' + (L.sel.has(o.id) ? ' on' : '') + '" data-o="' + o.id + '"><button type="button" class="sel" data-act="sel" aria-label="Select order ' + esc(o.number) + '">' + av(o.name) + ic('check') + '</button>'
    + '<a class="rm" href="#/orders/' + o.id + '"><b>#' + esc(o.number) + ' <span>' + esc(o.name) + '</span></b><small>' + esc(day(o.created_at)) + ' · ' + esc(time(o.created_at))
    + (o.items !== null && o.items !== undefined ? ' · ' + o.items + ' item' + (o.items === 1 ? '' : 's') : '') + '</small></a>'
    + '<a class="re" href="#/orders/' + o.id + '" tabindex="-1"><b>' + esc(o.total_display) + '</b>' + pill(o.status) + '</a></div>';
}

/** A history row (customer screen): no selection. */
export const plainRow = (o) => '<a class="row" href="#/orders/' + o.id + '"><div class="rm"><b>#' + esc(o.number) + '</b><small>' + esc(day(o.created_at)) + ' ' + esc(year(o.created_at)) + '</small></div><div class="re re-row">' + pill(o.status) + '<b>' + esc(o.total_display) + '</b></div></a>';

function syncSel(view) {
  const host = $('[data-pl]', view) || view;
  const n = L.sel.size;
  host.classList.toggle('selecting', n > 0);
  appEl().classList.toggle('selecting', n > 0);
  $$('[data-count]', host).forEach((x) => { x.textContent = n; });
}

export function clearSelection() {
  L.sel.clear();
  const app = appEl();
  if (app) app.classList.remove('selecting');
}

/* ---------------------------------------------------------------- detail */

async function loadDetail(host, id, pane) {
  if (!pane) paint(host, top('Order', '', back('#/orders', 'Back to orders')) + '<div class="body">' + skel(1, true) + skel(2) + '</div>');
  const r = await api('GET', 'orders/' + id);
  if (!r.ok) { paint(host, top('Order', '', pane ? '' : back('#/orders')) + '<div class="body">' + errorBox(r.data.message || 'This order could not be opened.') + '</div>'); return; }
  O = r.data.order;
  O.pane = pane;
  paintDetail(host);
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
  paint(host, top('Order #' + esc(o.number), esc(day(o.created_at)) + ' · ' + esc(t.total), O.pane ? '' : back('#/orders', 'Back to orders'), right)
    + '<div class="body">'
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
    + o.notes.map(noteLi).join('') + '</ul></section></div>');
}

const noteLi = (n, fresh) => '<li class="' + (n.customer ? 'c ' : '') + esc(n.tone || '') + (fresh ? ' new' : '') + '">' + esc(n.content) + '<small>' + esc(when(n.at)) + (n.author ? ' · ' + esc(n.author) : '') + '</small></li>';
const pillCode = (s) => ({ pending: 'pend', processing: 'proc', onhold: 'hold', shipped: 'ship', completed: 'done', cancelled: 'canc', refunded: 'ref', failed: 'fail' }[s] || 'pend');

/* ---------------------------------------------------------------- clicks */

export async function ordersClick(e, view) {
  const b = e.target.closest('[data-act],[data-chip]');
  const act = b ? b.getAttribute('data-act') : null;
  const rowEl = e.target.closest('.row.ord');

  if (b && b.hasAttribute('data-chip')) { L.status = b.getAttribute('data-chip'); await loadList(view); return true; }
  if (act === 'sel' && rowEl) { e.preventDefault(); toggle(view, rowEl); return true; }
  if (rowEl && L.sel.size && !act) { e.preventDefault(); toggle(view, rowEl); return true; }
  if (act === 'selall') { L.rows.forEach((o) => L.sel.add(o.id)); $$('.row.ord', view).forEach((r) => r.classList.add('on')); syncSel(view); return true; }
  if (act === 'selnone') { L.sel.clear(); $$('.row.ord.on', view).forEach((r) => r.classList.remove('on')); syncSel(view); return true; }
  if (act === 'more') { await busy(b, () => loadList(view, true)); return true; }
  if (act === 'filters') { filters(view); return true; }
  if (act === 'bulk') { await bulk(view, b.getAttribute('data-v'), b); return true; }
  if (act === 'bulk-more') { statusSheet('Change ' + L.sel.size + ' orders', '', (s) => bulk(view, s)); return true; }

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
  syncSel(view);
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
  await loadDetail(host, O.id, O.pane);
  const lr = L.rows.find((x) => x.id === O.id);
  if (lr) { lr.status = r.data.status; const el = document.querySelector('.row.ord[data-o="' + O.id + '"] .pill'); if (el) { el.setAttribute('data-s', pillCode(r.data.status)); el.textContent = stWord(r.data.status); } }
  if (was !== r.data.status) { L.counts[was] = Math.max(0, (L.counts[was] || 1) - 1); L.counts[r.data.status] = (L.counts[r.data.status] || 0) + 1; }
}

async function bulk(view, status, btn) {
  const ids = Array.from(L.sel);
  if (!ids.length) return;
  await busy(btn, async () => {
    let r = await api('POST', 'orders-bulk-status', { ids, status });
    const force = r.ok ? r.data.skipped.filter((s) => s.forceable) : [];
    if (force.length) {
      const ok = await confirmSheet(force.length + ' paid order' + (force.length === 1 ? '' : 's') + ' would leave the revenue figures', 'Change ' + (force.length === 1 ? 'it' : 'them') + ' to ' + stWord(status) + ' anyway?', 'Change anyway');
      if (ok) {
        const r2 = await api('POST', 'orders-bulk-status', { ids: force.map((s) => s.id), status, force: true });
        if (r2.ok) r.data.changed += r2.data.changed;
      }
    }
    if (!r.ok) { toast(r.data.message || 'Not changed.', true); return; }
    const refused = r.data.skipped.filter((s) => !s.forceable);
    toast(r.data.changed + ' order' + (r.data.changed === 1 ? '' : 's') + ' marked ' + stWord(status) + (refused.length ? ' · ' + refused.length + ' refused: ' + refused[0].reason : ''), refused.length > 0);
    L.sel.clear();
    await loadList(view);
    if (O && ids.indexOf(O.id) !== -1) { const pd = $('[data-pd]', view); if (pd) await loadDetail(pd, O.id, true); }
  });
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
        await loadDetail(host, O.id, O.pane);
      });
    });
  });
}

/** Refresh the list quietly (the live poll found changes). */
export async function refreshOrdersList(view) {
  if (L.sel.size || !$('[data-list]', view)) return;
  await loadList(view);
}
