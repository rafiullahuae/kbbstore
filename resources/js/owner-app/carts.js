/* Cart tracking, on the phone.                                     (Lane QK10)

   The owner, 9 October: "also give this cart tracking access to the owner app
   too so i can check that from the mobile too." The console's Carts tab, read
   only: when, what is in it, its value in AED, who (when the shop knows), and
   whether it became an order. A tap opens the cart in a sheet drawn from the
   row already in hand -- no second request.

   LIGHT. One GET of /api/carts when the screen opens, one per chip or "Show
   more" (the server's own 50-a-page paging), and one on "Sync now". Nothing
   polls and no timer runs. Every value passes through esc(). */
import { S, esc, api, $, top, back, toast, busy, paint, errorBox, ago, when, pill, sheet, screen, onScreen, mark, once, landed, isFresh, onReset, ln, blk, times, skChips, skNote } from './core.js';

const PERIODS = [['today', 'Today'], ['7d', '7 days'], ['30d', '30 days'], ['all', 'All time']];
const SHOW = [['', 'All carts'], ['no', 'Not bought'], ['yes', 'Became orders']];

const C = { period: '7d', bought: '', rows: [], page: 1, pages: 1, total: null, sum: null, loaded: false, key: '' };
onReset(() => Object.assign(C, { rows: [], page: 1, pages: 1, total: null, sum: null, loaded: false, key: '' }));

const fmt = (n) => Number(n || 0).toLocaleString('en-US');
const query = (page) => 'carts?period=' + C.period + (C.bought ? '&bought=' + C.bought : '') + (page > 1 ? '&page=' + page : '');
const count = (r) => r.items.reduce((n, l) => n + l.qty, 0);
const since = (iso) => { const v = ago(iso); return v === 'now' ? 'just now' : (/^\d+[mh]$/.test(v) ? v + ' ago' : v); };
const who = (r) => (r.customer && r.customer.name) || r.email || 'Guest';

export async function renderCarts(view) {
  view.className = 'view single';
  const held = C.loaded && isFresh('carts') && C.key === query(1);
  screen(view, 'carts', 0, top('Cart tracking', '<span data-sub>' + (held ? '' : 'Syncing…') + '</span>', back('#/more')),
    '<div class="tiles" data-sum>' + (held ? '' : times(4, () => '<div class="card">' + ln(70, 'k-sm') + ln(45, 'k-tile') + '</div>')) + '</div>'
    + '<div class="chips" role="group" aria-label="Period" data-cp>' + (held ? '' : skChips(4)) + '</div>'
    + '<div class="chips" role="group" aria-label="Show" data-cb>' + (held ? '' : skChips(3)) + '</div>'
    + '<div data-list>' + (held ? '' : skNote + '<div class="list">' + times(6, () => '<div class="row"><div class="rm">' + ln(50) + ln(75, 'k-sm') + '</div><div class="re">' + ln(0, 'k-amt') + ln(0, 'k-sm k-short') + '</div></div>') + '</div>') + '</div>', !held);
  if (held) paintCarts(view);
  await fetchCarts();
}

/** The screen's one request for the current period and filter (page 1). */
export function fetchCarts(passive, force) {
  const q = query(1);
  return once(q, async () => {
    const g = S.gen;
    const r = await api('GET', q, undefined, passive);
    if (g !== S.gen || q !== query(1)) return false;
    const host = onScreen('carts');
    if (!r.ok) {
      const list = host && $('[data-list]', host);
      if (list) { paint(list, errorBox(r.data.message)); mark(host, 'carts', 0, false); }
      return false;
    }
    take(r.data, false);
    C.key = q;
    if (host) { paintCarts(host); mark(host, 'carts', 0, false); }
    return true;
  }, force);
}

function take(d, append) {
  C.rows = append ? C.rows.concat(d.rows) : d.rows;
  C.page = d.page;
  C.pages = d.pages;
  C.total = d.total;
  C.sum = d.summary;
  C.loaded = true;
  landed('carts');
}

function paintCarts(view) {
  const s = C.sum;
  const sub = $('[data-sub]', view);
  if (sub && C.total !== null) sub.textContent = fmt(C.total) + ' cart' + (C.total === 1 ? '' : 's') + ' · ' + (PERIODS.find(([k]) => k === C.period) || [0, ''])[1];
  if (s) {
    paint($('[data-sum]', view), '<div class="card"><small>Carts</small><b>' + fmt(s.carts) + '</b></div>'
      + '<div class="card"><small>Became orders</small><b>' + fmt(s.bought) + ' <span class="muted ct-pc">· ' + esc(s.conversion) + '%</span></b></div>'
      + '<div class="card"><small>Left in carts</small><b>' + esc(s.open_value) + '</b></div>'
      + '<div class="card"><small>Bought value</small><b>' + esc(s.bought_value) + '</b></div>');
  }
  paint($('[data-cp]', view), PERIODS.map(([k, l]) => '<button type="button" data-ctp="' + k + '"' + (C.period === k ? ' class="on"' : '') + '>' + l + '</button>').join(''));
  paint($('[data-cb]', view), SHOW.map(([k, l]) => '<button type="button" data-ctb="' + k + '"' + (C.bought === k ? ' class="on"' : '') + '>' + l + '</button>').join(''));
  paint($('[data-list]', view), (C.rows.length
    ? '<div class="list">' + C.rows.map((r, i) => '<button type="button" class="row ct-row" data-cto="' + i + '"><div class="rm"><b>' + esc(who(r)) + '</b>'
      + '<small>' + fmt(count(r)) + ' item' + (count(r) === 1 ? '' : 's') + ' · ' + esc(since(r.last)) + (r.country ? ' · ' + esc(r.country) : '') + '</small>'
      + '<span class="ct-st">' + (r.order ? pill(r.order.status, '') + '<span class="muted">#' + esc(r.order.number) + '</span>' : '<span class="tag ct-open">Not bought</span>') + (r.bot ? '<span class="tag ct-bot">Bot</span>' : '') + '</span></div>'
      + '<div class="re"><b>' + esc(r.value_label) + '</b></div></button>').join('') + '</div>'
    : '<div class="card empty">No carts in this period.</div>')
    + (C.page < C.pages ? '<button type="button" class="btn sec wide" data-ctm>Show more</button>' : ''));
}

function openCart(r) {
  const can = S.me.can;
  const link = (href, label) => '<a class="tbtn" href="' + href + '" data-close>' + esc(label) + '</a>';
  sheet('Cart #' + r.id, '<div class="ct-sheet">'
    + '<p class="hint">Last active ' + esc(when(r.last)) + (r.first && r.first !== r.last ? '<br>Started ' + esc(when(r.first)) : '') + (r.country ? '<br>' + esc(r.country) : '') + '</p>'
    + '<section class="card"><h4 class="sh">In the cart <span class="muted">' + esc(r.value_label) + '</span></h4>'
    + (r.items.length ? r.items.map((l) => '<div class="row"><div class="rm"><b>' + esc(l.name) + '</b></div><div class="re"><b>× ' + fmt(l.qty) + '</b>'
      + (l.id && can.products ? link('#/products/' + l.id, 'Open') : '') + '</div></div>').join('') : '<p class="empty">Empty now.</p>')
    + (r.removed ? '<p class="muted ct-rm">' + fmt(r.removed) + ' removed along the way</p>' : '') + '</section>'
    + '<section class="card"><h4 class="sh">Customer</h4><div class="row"><div class="rm"><b>' + esc(who(r)) + '</b>' + (r.email && r.customer ? '<small>' + esc(r.email) + '</small>' : '') + '</div><div class="re">'
      + (r.email ? '<a class="tbtn" href="mailto:' + esc(r.email) + '">Email</a>' : '')
      + (r.customer && can.customers ? link('#/customers/' + r.customer.id, 'Open') : '') + '</div></div>'
      + (r.bot ? '<p class="muted ct-rm">Looks like a bot.</p>' : '') + '</section>'
    + '<section class="card"><h4 class="sh">Order</h4>' + (r.order
      ? '<div class="row"><div class="rm"><b>#' + esc(r.order.number) + '</b><small>' + esc(r.order.total) + '</small></div><div class="re">' + pill(r.order.status, '') + (can.orders ? link('#/orders/' + r.order.id, 'Open') : '') + '</div></div>'
      : '<p class="muted">Not bought yet.</p>') + '</section></div>');
}

async function load(view, more) {
  if (!more) { await fetchCarts(false, true); return; }
  const r = await api('GET', query(C.page + 1));
  if (!r.ok) { toast(r.data.message || 'Could not load more.', true); return; }
  take(r.data, true);
  if ($('[data-list]', view)) paintCarts(view);
}

export async function cartsClick(e, view) {
  const p = e.target.closest('[data-ctp]');
  if (p) { C.period = p.getAttribute('data-ctp'); await load(view); return true; }
  const b = e.target.closest('[data-ctb]');
  if (b) { C.bought = b.getAttribute('data-ctb'); await load(view); return true; }
  const m = e.target.closest('[data-ctm]');
  if (m) { await busy(m, () => load(view, true)); return true; }
  const o = e.target.closest('[data-cto]');
  if (o) { const r = C.rows[+o.getAttribute('data-cto')]; if (r) openCart(r); return true; }
  return false;
}
