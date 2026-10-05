/*
 * Customers (screens 13–14) — Petal: search, VIP / repeat / new filters, and
 * the whole relationship on one screen.
 */
import { S, esc, api, $, ic, av, th, top, back, busy, debounce, paint, errorBox, skel, day, ago } from './core.js';
import { plainRow } from './orders.js';

const C = { q: '', filter: 'all', rows: [], next: null, total: null, fresh: null };
const FILTERS = [['all', 'All'], ['vip', 'VIP'], ['repeat', 'Repeat'], ['new', 'New']];

export async function renderCustomers(view) {
  view.className = 'view single';
  paint(view, top('Customers', '<span data-sub>&nbsp;</span>', back('#/more')) + '<div class="body"><label class="search">' + ic('search', 's') + '<input type="search" data-search placeholder="Name, phone or email" aria-label="Search customers" value="' + esc(C.q) + '" enterkeyhint="search"></label>'
    + '<div class="chips" role="group" aria-label="Filter customers" data-chips></div><div data-list>' + skel(6) + '</div></div>');
  const input = $('[data-search]', view);
  input.addEventListener('input', debounce(() => { C.q = input.value.trim(); load(view); }, 300));
  await load(view);
}

async function load(view, more) {
  const p = new URLSearchParams();
  if (C.q) p.set('q', C.q);
  if (C.filter !== 'all') p.set('filter', C.filter);
  if (more) p.set('before', C.next);
  const r = await api('GET', 'customers' + (p.toString() ? '?' + p : ''));
  const list = $('[data-list]', view);
  if (!list) return;
  if (!r.ok) { paint(list, errorBox(r.data.message)); return; }
  C.rows = more ? C.rows.concat(r.data.customers) : r.data.customers;
  C.next = r.data.next;
  if (r.data.total !== null && r.data.total !== undefined) { C.total = r.data.total; C.fresh = r.data.new_this_month; }
  const sub = $('[data-sub]', view);
  if (sub && C.total !== null) sub.textContent = C.total.toLocaleString('en-US') + ' customers · ' + C.fresh + ' new this month';
  paint($('[data-chips]', view), FILTERS.map(([k, l]) => '<button type="button" data-cf="' + k + '"' + (C.filter === k ? ' class="on"' : '') + '>' + l + '</button>').join(''));
  paint(list, (C.rows.length ? '<div class="list">' + C.rows.map((c) => '<a class="row" href="#/customers/' + c.id + '">' + av(c.name)
    + '<div class="rm"><b>' + esc(c.name) + '</b><small>' + esc(c.email) + '</small></div><div class="re"><b>' + esc(c.spent_display) + '</b><small class="muted">' + c.orders + ' order' + (c.orders === 1 ? '' : 's') + '</small></div></a>').join('') + '</div>'
    : '<div class="card empty">No customers match.</div>') + (C.next ? '<button type="button" class="btn sec wide" data-more>Show more</button>' : ''));
}

export async function customersClick(e, view) {
  const f = e.target.closest('[data-cf]');
  if (f) { C.filter = f.getAttribute('data-cf'); await load(view); return true; }
  const m = e.target.closest('[data-more]');
  if (m) { await busy(m, () => load(view, true)); return true; }
  return false;
}

export async function renderCustomer(view, id) {
  view.className = 'view single';
  paint(view, top('Customer', '', back('#/customers', 'Back to customers')) + '<div class="body">' + skel(1, true) + skel(3) + '</div>');
  const r = await api('GET', 'customers/' + id);
  if (!r.ok) { paint(view, top('Customer', '', back('#/customers')) + '<div class="body">' + errorBox(r.data.message || 'This customer could not be opened.') + '</div>'); return; }
  const c = r.data.customer;
  const tel = c.phone ? c.phone.replace(/[^\d+]/g, '') : '';
  const max = Math.max(1, ...c.months.map((m) => m.fils));
  const since = c.since ? new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric', timeZone: S.tz || undefined }).format(new Date(c.since)) : '';
  paint(view, top('Customer', '', back('#/customers', 'Back to customers')) + '<div class="body">'
    + '<section class="card cu-h">' + av(c.name, 'xl') + '<h4>' + esc(c.name) + '</h4><p>' + esc(['Customer since ' + since, c.place].filter(Boolean).join(' · ')) + '</p>'
    + c.tags.map((t) => '<span class="tag">' + esc(t) + '</span>').join(' ')
    + '<div class="cu-act">' + (tel ? '<a href="tel:' + esc(tel) + '">' + ic('phone') + 'Call</a><a href="https://wa.me/' + esc(tel.replace(/\D/g, '')) + '" target="_blank" rel="noopener noreferrer">' + ic('chat') + 'WhatsApp</a>' : '<span>' + ic('phone') + 'Call</span><span>' + ic('chat') + 'WhatsApp</span>')
    + '<a href="mailto:' + esc(c.email) + '">' + ic('mail') + 'Email</a></div></section>'
    + '<div class="tiles"><div class="card"><small>Lifetime value</small><b>' + esc(c.spent_display) + '</b></div><div class="card"><small>Orders</small><b>' + c.paid_orders + (c.orders !== c.paid_orders ? ' <span class="muted">of ' + c.orders + '</span>' : '') + '</b></div>'
    + '<div class="card"><small>Average order</small><b>' + esc(c.average_display || '—') + '</b></div><div class="card"><small>Last order</small><b>' + esc(c.last_order_at ? (ago(c.last_order_at) === 'now' ? 'Just now' : day(c.last_order_at)) : '—') + '</b></div></div>'
    + '<section class="card"><h4 class="sh">Spend by month</h4><div class="spark" role="img" aria-label="Monthly spend, last eight months">' + c.months.map((m, i) => '<i' + (i === c.months.length - 1 ? ' class="on"' : '') + ' data-sy="' + Math.max(0.05, m.fils / max).toFixed(3) + '"></i>').join('') + '</div>'
    + '<div class="axis">' + [0, 2, 5, 7].map((i) => '<span>' + esc(c.months[i] ? c.months[i].label : '') + '</span>').join('') + '</div></section>'
    + '<section class="card"><h4 class="sh">Purchase history <span class="muted">' + c.history.length + '</span></h4>' + (c.history.length ? c.history.map(plainRow).join('') : '<p class="empty">No orders yet.</p>') + '</section>'
    + (c.top_products.length ? '<section class="card"><h4 class="sh">Buys most</h4><div class="favs">' + c.top_products.map((t) => '<a href="' + (t.id ? '#/products/' + t.id : '#/products') + '" aria-label="' + esc(t.name) + ' × ' + t.qty + '">' + th(t.thumb) + '</a>').join('') + '</div></section>' : '')
    + (c.address.length ? '<section class="card"><h4 class="sh">Address</h4><div class="addr">' + c.address.map(esc).join('<br>') + '<br><span>' + esc([c.phone, c.email].filter(Boolean).join(' · ')) + '</span></div></section>' : '')
    + '</div>');
}
