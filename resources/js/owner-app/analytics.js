/* Analytics, on the phone.                                         (Lane AN)

   The console's realtime board (design B) for a 390-wide screen: a dark live
   card with the active-visitor count and per-minute bars, the "Happening now"
   feed, pages / sources / countries now, today's strip, the funnel, and orders
   and revenue by source and by campaign.

   LIVE ONLY WHILE WATCHED. One GET of /api/analytics/live every 15 s, only
   while this screen is open AND the app is in the foreground; leaving the
   screen (stopAnalytics, called by show() on every route change) or hiding
   the app stops it, and coming back asks for the gap with `since` -- merged by
   id, so nothing repeats. setTimeout re-armed after each answer, never
   setInterval. The window (5/10/15/25 min, default 10) is remembered per
   device. Recording on the shop never stops; only this display rests.

   The app's CSP forbids inline styles, so widths go through data-sx (vars()).
   Every value passes through esc(). */
import { S, esc, api, top, screen, store } from './core.js';

const WINDOWS = [5, 10, 15, 25];
const RANGES = [['today', 'Today'], ['yesterday', 'Yesterday'], ['7d', '7 days'], ['30d', '30 days']];
const POLL_MS = 15000;
const CC = { AE: 'UAE', SA: 'Saudi Arabia', QA: 'Qatar', OM: 'Oman', KW: 'Kuwait', BH: 'Bahrain', US: 'USA', GB: 'UK', IN: 'India', PK: 'Pakistan', '': 'Unknown' };

const st = { tip: null, on: false, timer: 0, win: 10, range: 'today', since: 0, osince: 0, feed: [], live: null, sum: null, view: null };
{
  const w = parseInt(store.get('oa.an.win') || '', 10);
  if (WINDOWS.indexOf(w) !== -1) st.win = w;
}

const fmt = (n) => Number(n || 0).toLocaleString('en-US');
const aed = (f) => 'AED ' + Math.round((f || 0) / 100).toLocaleString('en-US');
const dec = (p) => { try { return decodeURIComponent(p); } catch (e) { return p; } };
const hhmm = (s) => { const d = new Date(s * 1000); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); };
const seg = (attr, list, cur) => '<div class="seg sm" role="group">' + list.map(([k, l]) => '<button type="button" ' + attr + '="' + k + '"' + (String(cur) === String(k) ? ' class="on"' : '') + '>' + l + '</button>').join('') + '</div>';

function rows(list, money) {
  if (!list.length) return '<p class="empty">Nothing yet.</p>';
  const max = Math.max(1, ...list.map((r) => r[1]));
  return list.map((r) => '<div class="row"><div class="rm"><b>' + esc(r[0]) + '</b>' + (r[2] ? '<small>' + esc(r[2]) + '</small>' : '')
    + '<div class="bar"><i data-sx="' + (r[1] / max).toFixed(2) + '"></i></div></div><div class="re"><b>' + (money ? aed(r[1]) : fmt(r[1])) + '</b></div></div>').join('');
}

/* Visitors per minute, like Google Analytics' realtime chart (Lane AN2): the
   server's round ticks (0 at the base) on the right with faint gridlines,
   "-30 min … -1 min" underneath, bars to scale against the top tick; a tap
   shows that minute's value inside the SVG (the app's CSP allows no inline
   style). No request, nothing measured. */
const CH = { W: 320, H: 104, PW: 292, TOP: 14, BASE: 84 };
function bars(d) {
  const series = d.bars || [], ticks = d.bar_ticks || [1], n = series.length || 1, top = ticks[ticks.length - 1] || 1;
  const bw = CH.PW / n, span = CH.BASE - CH.TOP;
  let o = ticks.map((t) => { const y = (CH.BASE - t / top * span).toFixed(1); return '<line x1="0" x2="' + CH.PW + '" y1="' + y + '" y2="' + y + '" class="' + (t === 0 ? 'base' : 'grid') + '"/><text class="ax" x="' + (CH.PW + 6) + '" y="' + (+y + 3).toFixed(1) + '">' + t + '</text>'; }).join('');
  o += series.map((v, i) => { const h = v / top * span; return '<rect x="' + (i * bw + 1).toFixed(1) + '" y="' + (CH.BASE - h).toFixed(1) + '" width="' + Math.max(1, bw - 2).toFixed(1) + '" height="' + Math.max(0, h).toFixed(1) + '" rx="1.5" class="' + (i === n - 1 ? 'now' : '') + (st.tip === i ? ' on' : '') + '"/><rect data-ani="' + i + '" x="' + (i * bw).toFixed(1) + '" y="' + CH.TOP + '" width="' + bw.toFixed(1) + '" height="' + span + '" class="hit"/>'; }).join('');
  o += [30, 25, 20, 15, 10, 5, 1].map((k, j, all) => { const x = ((n - k) * bw + bw / 2).toFixed(1); return '<text class="ax" x="' + (j === 0 ? 0 : (j === all.length - 1 ? CH.PW : x)) + '" y="' + (CH.H - 4) + '" text-anchor="' + (j === 0 ? 'start' : (j === all.length - 1 ? 'end' : 'middle')) + '">−' + k + (j === 0 || j === all.length - 1 ? ' min' : '') + '</text>'; }).join('');
  if (st.tip !== null && series[st.tip] != null) {
    const v = series[st.tip], at = d.now - (n - 1 - st.tip) * 60, x = Math.min(CH.PW - 50, Math.max(50, st.tip * bw + bw / 2));
    o += '<g class="tip"><rect x="' + (x - 50) + '" y="0" width="100" height="13" rx="3"/><text x="' + x + '" y="9.5" text-anchor="middle">' + hhmm(at) + ' · ' + v + ' visitor' + (v === 1 ? '' : 's') + '</text></g>';
  }
  return o;
}

function funnel(t) {
  const topN = Math.max(1, t.visitors || 0);
  return [['Visitors', t.visitors], ['Added to cart', t.carts], ['Checkout', t.checkouts], ['Orders', t.orders]].map(([l, v], i) => {
    const w = Math.max(3, Math.min(300, (v || 0) / topN * 300));
    return '<rect x="0" y="' + (i * 34) + '" width="' + w.toFixed(1) + '" height="20" rx="5" class="f' + i + '"/><text x="' + (w + 6 > 210 ? 6 : w + 6) + '" y="' + (i * 34 + 14) + '">' + esc(l) + ' · ' + fmt(v) + (i ? ' (' + ((v || 0) / topN * 100).toFixed(1) + '%)' : '') + '</text>';
  }).join('');
}

function liveHTML() {
  const d = st.live;
  const lt = d ? (d.langs.en || 0) + (d.langs.ar || 0) : 0;
  const known = d ? d.countries.filter((c) => c.cc) : [];
  return '<section class="card an-live" data-blk="live"><div class="an-h"><span><i class="an-dot' + (st.on && document.visibilityState === 'visible' ? '' : ' off') + '"></i>Online now</span><span class="an-wl">last ' + seg('data-anw', WINDOWS.map((w) => [w, String(w)]), st.win) + ' min</span></div>'
    + '<div class="an-big"><b>' + (d ? fmt(d.online) : '–') + '</b><small>' + (d ? 'on ' + fmt(d.online_pages) + ' page' + (d.online_pages === 1 ? '' : 's') + ' now<br>' + fmt(d.active) + ' visitors · ' + fmt(d.views) + ' views · ' + fmt(d.carts) + ' carts in ' + st.win + ' min' : '') + '</small></div>'
    + '<svg class="an-bars" viewBox="0 0 ' + CH.W + ' ' + CH.H + '" aria-label="Visitors per minute, last 30 minutes">' + (d ? bars(d) : '') + '</svg>'
    + '<div class="an-axis"><span>visitors per minute · tap a bar</span></div>'
    + '<div class="an-minis"><div><b>' + (d && d.active ? d.mobile_pct + '%' : '–') + '</b><small>on mobile · ' + st.win + ' min</small></div>'
    + '<div><b>' + (lt ? Math.round(d.langs.en / lt * 100) + ' / ' + Math.round(d.langs.ar / lt * 100) : '–') + '</b><small>EN / AR</small></div>'
    + '<div><b>' + (known.length ? esc(CC[known[0].cc] || known[0].cc) : (d && !d.country_db ? 'Needs data' : '–')) + '</b><small>top country · ' + st.win + ' min</small></div>'
    + '<div><b>' + (d && d.sources.length ? esc(d.sources[0].label) : '–') + '</b><small>top source · ' + st.win + ' min</small></div></div></section>'
    + '<section class="card" data-blk="feed"><h4 class="sh">Happening now</h4>' + (st.feed.length ? st.feed.slice(0, 15).map((f) => {
      const lab = f.type === 'order' ? 'order' : f.type === 'cart' ? 'cart' : 'view';
      const main = f.type === 'order' ? 'Order AED ' + fmt(f.aed) + ' · ' + f.source : f.type === 'cart' ? 'Added to cart' : (f.title || dec(f.path));
      const meta = f.type === 'order' ? '' : [CC[f.cc] || f.cc, f.dev, f.source].filter(Boolean).join(' · ');
      return '<div class="row an-feed"><time>' + hhmm(f.at) + '</time><div class="rm"><b>' + esc(main) + '</b><small>' + esc(meta) + '</small></div><span class="an-ev ' + lab + '">' + lab + '</span></div>';
    }).join('') : '<p class="empty">Quiet for the last 30 minutes.</p>') + '</section>'
    + '<section class="card" data-blk="pagesnow"><h4 class="sh">Pages being read now</h4>' + rows(d ? d.pages.map((p) => [p.title || dec(p.path), p.n, dec(p.path)]) : []) + '</section>'
    + '<section class="card" data-blk="srcnow"><h4 class="sh">Sources · last ' + st.win + ' min</h4>' + rows(d ? d.sources.map((s) => [s.label, s.n]) : []) + '</section>'
    + '<section class="card" data-blk="ccnow"><h4 class="sh">Countries · last ' + st.win + ' min</h4>' + (d && !d.country_db && !known.length
      ? '<p class="empty">Countries need the country database: in the admin, Store → Security → Firewall → Data → Download country database.</p>'
      : rows(d ? d.countries.map((c) => [CC[c.cc] || c.cc, c.n]) : [])) + '</section>';
}

function sumHTML() {
  const s = st.sum;
  if (!s) return '<p class="empty">Loading…</p>';
  const t = s.totals;
  const bounce = t.sessions ? Math.round(t.bounces / t.sessions * 100) : 0;
  const tile = (v, l) => '<div class="card"><b>' + v + '</b><small>' + l + '</small></div>';
  const dl = (list, f, lab) => (list || []).map((r) => [lab ? lab(r) : (r.label || r.val), r[f]]);
  return '<div class="an-range">' + seg('data-anr', RANGES, st.range) + (s.updated ? '<small class="an-up">Updated ' + hhmm(Date.parse(s.updated) / 1000) + '</small>' : '') + '</div>'
    + '<div class="an-strip" data-blk="strip">' + tile(fmt(t.visitors), 'Visitors') + tile(fmt(t.views), 'Page views') + tile(fmt(t.sessions), 'Sessions')
    + tile(bounce + '%', 'Bounce rate') + tile(fmt(t.orders), 'Orders') + tile(aed(t.revenue_fils), 'Revenue') + '</div>'
    + '<section class="card" data-blk="revsrc"><h4 class="sh">Orders &amp; revenue by source</h4>' + rows((s.orders_by_channel || []).map((c) => [c.label, c.revenue_fils, c.orders + (c.orders === 1 ? ' order' : ' orders') + (c.rate != null ? ' · ' + c.rate + '% conv.' : '')]), true) + '</section>'
    + '<section class="card" data-blk="campaigns"><h4 class="sh">Orders by campaign</h4>' + rows((s.orders_by_campaign || []).map((c) => [c.campaign, c.revenue_fils, c.channel + ' · ' + c.orders + (c.orders === 1 ? ' order' : ' orders')]), true) + '</section>'
    + '<section class="card" data-blk="funnel"><h4 class="sh">Checkout funnel</h4><svg class="an-funnel" viewBox="0 0 320 136">' + funnel(t) + '</svg><p class="an-note">Same period for every bar: visitors, visitors who added to cart, reached checkout, paid orders.</p></section>'
    + '<section class="card" data-blk="sources"><h4 class="sh">Sources</h4>' + rows(dl(s.dims.channel, 'sessions')) + '</section>'
    + '<section class="card" data-blk="pages"><h4 class="sh">Top pages</h4>' + rows(dl(s.dims.page, 'views', (r) => r.label || dec(r.val))) + '</section>'
    + '<section class="card" data-blk="search"><h4 class="sh">Searched on the shop</h4>' + rows((s.search || []).map((r) => [r.term, r.hits, r.results === 0 ? 'no results' : ''])) + '</section>'
    + '<section class="card" data-blk="devices"><h4 class="sh">Devices</h4>' + rows(dl(s.dims.device, 'sessions')) + '</section>';
}

function paint() {
  if (!st.view || !st.on) return;
  screen(st.view, 'analytics', 0, top('Analytics', 'Live and ' + (RANGES.find((r) => r[0] === st.range) || RANGES[0])[1].toLowerCase()), liveHTML() + sumHTML());
  // The admin's own block order and hidden blocks, arranged on the console's
  // board (Lane AN2, BoardLayout) -- the same order here. DOM moves only.
  const L = st.sum && st.sum.layout;
  const body = st.view.querySelector(':scope > .body');
  if (L && body) {
    L.order.forEach((id) => { const b = body.querySelector(':scope > [data-blk="' + id + '"]'); if (b) body.appendChild(b); });
    L.hidden.forEach((id) => { const b = body.querySelector(':scope > [data-blk="' + id + '"]'); if (b) b.hidden = true; });
  }
}

function merge(items) {
  const seen = new Set(st.feed.map((f) => f.id));
  items.forEach((f) => { if (!seen.has(f.id)) { st.feed.push(f); seen.add(f.id); } });
  const cut = Date.now() / 1000 - 1800;
  st.feed = st.feed.filter((f) => f.at >= cut).sort((a, b) => b.at - a.at).slice(0, 40);
}

async function loadLive() {
  if (!st.on) return;
  const r = await api('GET', 'analytics/live?w=' + st.win + '&since=' + st.since + '&osince=' + st.osince + (st.range === 'today' ? '&today=1' : ''), undefined, true);
  if (!st.on || !r.ok) return;
  st.live = r.data;
  // Today's figures ride on the same poll, so they never lag the live card.
  if (r.data.today && st.range === 'today') st.sum = r.data.today;
  st.since = Math.max(st.since, r.data.since || 0);
  st.osince = Math.max(st.osince, r.data.order_since || 0);
  merge((r.data.feed || []).concat(r.data.orders || []));
  paint();
}

async function loadSum() {
  const r = await api('GET', 'analytics?range=' + st.range);
  if (!st.on) return;
  if (r.ok) st.sum = r.data;
  paint();
}

function schedule() {
  clear();
  // Locked, logged out, or another screen drawn over this one: asleep.
  if (S.stage !== 'app' || !st.view || st.view.getAttribute('data-scr') !== 'analytics') st.on = false;
  if (!st.on || document.visibilityState !== 'visible') return;
  st.timer = setTimeout(async () => { st.timer = 0; try { await loadLive(); } catch (e) { /* next tick */ } schedule(); }, POLL_MS);
}
function clear() { if (st.timer) clearTimeout(st.timer); st.timer = 0; }

/** Leaving the screen: show() calls this on every route change. */
export function stopAnalytics() { st.on = false; clear(); }

document.addEventListener('visibilitychange', () => {
  if (!st.on) return;
  if (document.visibilityState !== 'visible') { clear(); paint(); return; }
  loadLive().catch(() => {}).then(schedule);
});

export async function renderAnalytics(view) {
  view.className = 'view single';
  st.view = view; st.on = true; st.since = 0; st.osince = 0; st.feed = []; st.live = null;
  if (!S.me || !S.me.can.sales) { screen(view, 'analytics', 0, top('Analytics', ''), '<p class="empty">Your role does not include Analytics.</p>'); st.on = false; return; }
  paint();
  await Promise.all([loadLive().catch(() => {}), loadSum().catch(() => {})]);
  schedule();
}

export function analyticsClick(e) {
  const b = e.target.closest('[data-ani]');
  if (b) { const i = parseInt(b.getAttribute('data-ani'), 10); st.tip = st.tip === i ? null : i; paint(); return true; }
  const w = e.target.closest('[data-anw]');
  if (w) { st.win = parseInt(w.getAttribute('data-anw'), 10) || 10; store.set('oa.an.win', String(st.win)); loadLive().catch(() => {}).then(schedule); return true; }
  const r = e.target.closest('[data-anr]');
  if (r) { st.range = r.getAttribute('data-anr'); loadSum().catch(() => {}); return true; }
  return false;
}


