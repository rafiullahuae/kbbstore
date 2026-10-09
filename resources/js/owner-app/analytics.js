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

const st = { on: false, timer: 0, win: 10, range: 'today', since: 0, osince: 0, feed: [], live: null, sum: null, view: null };
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

function bars(series) {
  const n = series.length || 1, max = Math.max(1, ...series), bw = 300 / n;
  return series.map((v, i) => { const h = v / max * 58; return '<rect x="' + (i * bw + 1).toFixed(1) + '" y="' + (60 - h).toFixed(1) + '" width="' + Math.max(1, bw - 2).toFixed(1) + '" height="' + h.toFixed(1) + '" rx="1.5" class="' + (i === n - 1 ? 'now' : '') + '"/>'; }).join('');
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
  return '<section class="card an-live"><div class="an-h"><span><i class="an-dot' + (st.on && document.visibilityState === 'visible' ? '' : ' off') + '"></i>Active · last ' + st.win + ' min</span>' + seg('data-anw', WINDOWS.map((w) => [w, String(w)]), st.win) + '</div>'
    + '<div class="an-big"><b>' + (d ? fmt(d.active) : '–') + '</b><small>' + (d ? fmt(d.views) + ' page views<br>' + fmt(d.carts) + ' added to cart' : '') + '</small></div>'
    + '<svg class="an-bars" viewBox="0 0 300 60" preserveAspectRatio="none" aria-label="Visitors per minute, last 30 minutes">' + (d ? bars(d.bars) : '') + '</svg>'
    + '<div class="an-axis"><span>−30 min</span><span>now</span></div>'
    + '<div class="an-minis"><div><b>' + (d && d.active ? d.mobile_pct + '%' : '–') + '</b><small>on mobile</small></div>'
    + '<div><b>' + (lt ? Math.round(d.langs.en / lt * 100) + ' / ' + Math.round(d.langs.ar / lt * 100) : '–') + '</b><small>EN / AR</small></div>'
    + '<div><b>' + (d && d.countries.length ? esc(CC[d.countries[0].cc] || d.countries[0].cc) : '–') + '</b><small>top country</small></div>'
    + '<div><b>' + (d && d.sources.length ? esc(d.sources[0].label) : '–') + '</b><small>top source</small></div></div></section>'
    + '<section class="card"><h4 class="sh">Happening now</h4>' + (st.feed.length ? st.feed.slice(0, 15).map((f) => {
      const lab = f.type === 'order' ? 'order' : f.type === 'cart' ? 'cart' : 'view';
      const main = f.type === 'order' ? 'Order AED ' + fmt(f.aed) + ' · ' + f.source : f.type === 'cart' ? 'Added to cart' : (f.title || dec(f.path));
      const meta = f.type === 'order' ? '' : [CC[f.cc] || f.cc, f.dev, f.source].filter(Boolean).join(' · ');
      return '<div class="row an-feed"><time>' + hhmm(f.at) + '</time><div class="rm"><b>' + esc(main) + '</b><small>' + esc(meta) + '</small></div><span class="an-ev ' + lab + '">' + lab + '</span></div>';
    }).join('') : '<p class="empty">Quiet for the last 30 minutes.</p>') + '</section>'
    + '<section class="card"><h4 class="sh">Pages being read now</h4>' + rows(d ? d.pages.map((p) => [p.title || dec(p.path), p.n, dec(p.path)]) : []) + '</section>'
    + '<section class="card"><h4 class="sh">Sources now</h4>' + rows(d ? d.sources.map((s) => [s.label, s.n]) : []) + '</section>'
    + '<section class="card"><h4 class="sh">Countries now</h4>' + rows(d ? d.countries.map((c) => [CC[c.cc] || c.cc, c.n]) : []) + '</section>';
}

function sumHTML() {
  const s = st.sum;
  if (!s) return '<p class="empty">Loading…</p>';
  const t = s.totals;
  const bounce = t.sessions ? Math.round(t.bounces / t.sessions * 100) : 0;
  const tile = (v, l) => '<div class="card"><b>' + v + '</b><small>' + l + '</small></div>';
  const dl = (list, f, lab) => (list || []).map((r) => [lab ? lab(r) : (r.label || r.val), r[f]]);
  return '<div class="an-range">' + seg('data-anr', RANGES, st.range) + '</div>'
    + '<div class="an-strip">' + tile(fmt(t.visitors), 'Visitors') + tile(fmt(t.views), 'Page views') + tile(fmt(t.sessions), 'Sessions')
    + tile(bounce + '%', 'Bounce rate') + tile(fmt(t.orders), 'Orders') + tile(aed(t.revenue_fils), 'Revenue') + '</div>'
    + '<section class="card"><h4 class="sh">Orders &amp; revenue by source</h4>' + rows((s.orders_by_channel || []).map((c) => [c.label, c.revenue_fils, c.orders + (c.orders === 1 ? ' order' : ' orders') + (c.rate != null ? ' · ' + c.rate + '% conv.' : '')]), true) + '</section>'
    + '<section class="card"><h4 class="sh">Orders by campaign</h4>' + rows((s.orders_by_campaign || []).map((c) => [c.campaign, c.revenue_fils, c.channel + ' · ' + c.orders + (c.orders === 1 ? ' order' : ' orders')]), true) + '</section>'
    + '<section class="card"><h4 class="sh">Checkout funnel</h4><svg class="an-funnel" viewBox="0 0 320 136">' + funnel(t) + '</svg></section>'
    + '<section class="card"><h4 class="sh">Sources</h4>' + rows(dl(s.dims.channel, 'sessions')) + '</section>'
    + '<section class="card"><h4 class="sh">Top pages</h4>' + rows(dl(s.dims.page, 'views', (r) => r.label || dec(r.val))) + '</section>'
    + '<section class="card"><h4 class="sh">Searched on the shop</h4>' + rows((s.search || []).map((r) => [r.term, r.hits, r.results === 0 ? 'no results' : ''])) + '</section>'
    + '<section class="card"><h4 class="sh">Devices</h4>' + rows(dl(s.dims.device, 'sessions')) + '</section>';
}

function paint() {
  if (!st.view || !st.on) return;
  screen(st.view, 'analytics', 0, top('Analytics', 'Live and ' + (RANGES.find((r) => r[0] === st.range) || RANGES[0])[1].toLowerCase()), liveHTML() + sumHTML());
}

function merge(items) {
  const seen = new Set(st.feed.map((f) => f.id));
  items.forEach((f) => { if (!seen.has(f.id)) { st.feed.push(f); seen.add(f.id); } });
  const cut = Date.now() / 1000 - 1800;
  st.feed = st.feed.filter((f) => f.at >= cut).sort((a, b) => b.at - a.at).slice(0, 40);
}

async function loadLive() {
  if (!st.on) return;
  const r = await api('GET', 'analytics/live?w=' + st.win + '&since=' + st.since + '&osince=' + st.osince, undefined, true);
  if (!st.on || !r.ok) return;
  st.live = r.data;
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
  const w = e.target.closest('[data-anw]');
  if (w) { st.win = parseInt(w.getAttribute('data-anw'), 10) || 10; store.set('oa.an.win', String(st.win)); loadLive().catch(() => {}).then(schedule); return true; }
  const r = e.target.closest('[data-anr]');
  if (r) { st.range = r.getAttribute('data-anr'); loadSum().catch(() => {}); return true; }
  return false;
}


