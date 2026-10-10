
(function () {
  'use strict';

  var R = { id: null, pane: 'summary', q: '', filter: 'all', page: 1, rec: null, busy: false, h: null };
  var PANES = [['summary', 'Summary'], ['links', 'Links & products'], ['people', 'Recipients']];
  var FILTERS = [['all', 'Everyone'], ['opened', 'Opened'], ['not_opened', 'Not opened'], ['clicked', 'Clicked'], ['ordered', 'Ordered'], ['bounced', 'Bounced'], ['unsubscribed', 'Unsubscribed']];
  var KIND = { human: 'Opened', proxy: 'Opened (Gmail)', apple: 'Apple Mail auto-open', scanner: 'Scanner only', click: 'Opened (clicked)' };

  function pct(v) { return v === null || v === undefined ? '—' : String(v) + '%'; }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function kpi(h, label, big, line, small) {
    return '<div class="mke-kpi"><span>' + h.esc(label) + '</span><b>' + big + '</b><span>' + line + '</span>' + (small ? '<small>' + small + '</small>' : '') + '</div>';
  }

  /* ------------------------------------------------------------- the list */

  function list(S, h) {
    R.h = h;
    var on = S.reportsOpenTracking !== false;
    var rows = (S.reports || []).map(function (c) {
      var i = c.insights || {};
      return '<tr class="click" data-mke-r="' + h.esc(c.id) + '"><td><b>' + h.esc(c.name) + '</b><div class="sub">' + h.esc(c.subject || '') + '</div></td>'
        + '<td>' + h.statusPill(c) + '</td>'
        + '<td>' + h.num(i.delivered != null ? i.delivered : c.sent) + '</td>'
        + '<td>' + pct(i.open_rate) + (i.apple ? '<div class="sub">' + h.num(i.apple) + ' Apple auto</div>' : '') + '</td>'
        + '<td>' + pct(i.click_rate) + '</td>'
        + '<td class="mke-hide-sm">' + h.num(i.visits || 0) + '</td>'
        + '<td class="mke-hide-sm">' + (i.orders ? h.num(i.orders) + ' · ' + h.esc(i.revenue) : '—') + '</td></tr>';
    }).join('');
    return switchCard(on, h)
      + '<div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Campaign</th><th>Status</th><th>Delivered</th><th>Opened*</th><th>Clicked</th><th class="mke-hide-sm">Visits</th><th class="mke-hide-sm">Orders (7 days)</th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="7"><div class="mke-empty">No campaign has been sent yet.</div></td></tr>') + '</tbody></table></div>'
      + '<div class="mke-h" style="margin-top:8px">* Opens are an estimate: Apple Mail loads every picture by itself, and some programs block pictures. Clicks and orders are exact. Rates are of delivered emails.</div>';
  }

  function switchCard(on, h) {
    return '<div class="mke-card" style="margin:0 0 12px" data-mke-stop><div class="mkr-switch"><label><input type="checkbox" data-mkr-track' + (on ? ' checked' : '') + '> Open tracking</label>'
      + '<span class="mke-h" style="flex:1 1 260px">' + (on ? 'On: each email carries an invisible 1×1 picture, so the report can estimate opens.' : 'Off: new emails carry no picture and opens are not counted. Clicks and orders are still exact.') + '</span></div></div>';
  }

  /* ----------------------------------------------------------- one report */

  function panel(r, h) {
    R.h = h;
    if (R.id !== r.id) { R.id = r.id; R.pane = 'summary'; R.q = ''; R.filter = 'all'; R.page = 1; R.rec = null; }
    var tabs = '<div class="mke-tabs mke-panel-tabs" data-mke-stop role="tablist" aria-label="Report">' + PANES.map(function (p) {
      var on = p[0] === R.pane;
      return '<button type="button" role="tab" class="mke-tab' + (on ? ' on' : '') + '" aria-selected="' + on + '" data-mkr-pane="' + p[0] + '">' + h.esc(p[1]) + '</button>';
    }).join('') + '</div>';
    var body = PANES.map(function (p) {
      var inner = p[0] === 'summary' ? summary(r, h) : p[0] === 'links' ? links(r, h) : people(r, h);
      return '<div data-mke-stop data-mkr-body="' + p[0] + '"' + (p[0] === R.pane ? '' : ' hidden') + '>' + inner + '</div>';
    }).join('');
    if (R.pane === 'people' && !R.rec) Promise.resolve().then(loadPeople);
    return '<button type="button" class="mke-back" data-mke="reports">← All reports</button>' + tabs + body;
  }

  function summary(r, h) {
    var x = r.insights, d = x.delivery, o = x.opens, c = x.clicks, s = x.site, w = x.orders;
    var honest = '<div class="mkr-honest">Opens are an estimate: Apple Mail loads every picture by itself, and some programs block pictures. Clicks and orders are exact.'
      + (o.tracking ? '' : ' <b>Open tracking is off</b> — new emails carry no picture.') + '</div>';
    var delivery = '<div class="mkr-h">Delivery</div><div class="mkr-kpis">'
      + kpi(h, 'Sent', h.num(d.sent), 'handed to mail servers', d.pending ? h.num(d.pending) + ' still to go' : (d.skipped ? h.num(d.skipped) + ' skipped' : ''))
      + kpi(h, 'Delivered', h.num(d.delivered), 'sent minus bounced', '')
      + kpi(h, 'Bounced', h.num(d.bounced), h.num(d.hard) + ' hard · ' + h.num(d.soft) + ' soft', '')
      + kpi(h, 'Unsubscribed', h.num(d.unsubscribed), h.num(d.complaints) + ' spam complaints', '') + '</div>';
    var engage = '<div class="mkr-h">Opened and clicked</div><div class="mkr-kpis">'
      + kpi(h, 'Unique opens (estimated)', h.num(o.unique) + ' · ' + pct(o.rate), 'of which Apple Mail auto-opens: ' + h.num(o.apple), 'Without them: ' + pct(o.rate_without_apple) + (o.scanner ? ' · ' + h.num(o.scanner) + ' scanner loads not counted' : ''))
      + kpi(h, 'Clicked (unique)', h.num(c.unique) + ' · ' + pct(c.rate), h.num(c.total) + ' clicks in all', 'of delivered')
      + kpi(h, 'Click-to-open', pct(c.cto), 'clicked, of those who opened', '')
      + kpi(h, 'Opens counted', h.num(o.loads), 'picture loads in all', o.proxy ? h.num(o.proxy) + ' via Gmail’s image proxy (it caches: repeat opens are not seen)' : '') + '</div>';
    var site = '<div class="mkr-h">Came to the website</div><div class="mkr-kpis">'
      + kpi(h, 'Visits', h.num(s.visits), h.num(s.views) + ' pages viewed', 'from links tagged ' + h.esc(x.utm))
      + kpi(h, 'Added to cart', h.num(s.carts), 'visitors from this email', '')
      + kpi(h, 'Checkouts started', h.num(s.checkouts), 'visitors from this email', '')
      + kpi(h, 'Orders within ' + h.num(w.window_days) + ' days', h.num(w.orders) + ' · ' + h.esc(w.revenue), 'paid, by people who clicked', s.orders ? 'Site analytics also credits ' + h.num(s.orders) + ' (' + h.esc(s.revenue) + ') to these links' : '') + '</div>';
    return honest + delivery + engage + site + timeline(x.timeline, h) + devices(c.devices, h) + failures(r, h);
  }

  function bars(list, label, h) {
    var max = 1;
    list.forEach(function (b) { max = Math.max(max, b.opens, b.clicks); });
    var cols = list.map(function (b) {
      var human = b.opens - b.apple;
      var t = label(b) + ': ' + b.opens + ' opens (' + b.apple + ' Apple auto), ' + b.clicks + ' clicks';
      return '<div class="mkr-col" title="' + h.esc(t) + '"><i class="o" style="height:' + (human / max * 100).toFixed(1) + '%"></i><i class="a" style="height:' + (b.apple / max * 100).toFixed(1) + '%"></i><i class="c" style="height:' + (b.clicks / max * 100).toFixed(1) + '%"></i></div>';
    }).join('');
    var axis = list.map(function (b, i) { return '<span>' + (i % 6 === 0 ? h.esc(label(b)) : '') + '</span>'; }).join('');
    return '<div class="mkr-bars" role="img" aria-label="' + h.esc('Opens and clicks, ' + list.length + ' bars') + '">' + cols + '</div><div class="mkr-axis" aria-hidden="true">' + axis + '</div>';
  }

  function timeline(t, h) {
    if (!t || !t.start) return '';
    var legend = '<div class="mkr-legend"><span><b style="background:#f1a7bd"></b>Opens</span><span><b style="background:#d9d2e9"></b>Apple Mail auto-opens</span><span><b style="background:var(--ink)"></b>Clicks</span></div>';
    var out = '<div class="mkr-h">The first 48 hours, by the hour</div><div class="mke-card">' + bars(t.hours, function (b) { return '+' + b.hour + 'h'; }, h) + legend + '</div>';
    if (t.days && t.days.length) out += '<div class="mkr-h">After that, by the day</div><div class="mke-card">' + bars(t.days, function (b) { return 'day ' + b.day; }, h) + legend + '</div>';
    return out;
  }

  function devices(d, h) {
    var all = d.mobile + d.tablet + d.desktop;
    if (!all && !d.bot) return '';
    var seg = function (n, col) { return all ? '<i style="width:' + (n / all * 100).toFixed(1) + '%;background:' + col + '"></i>' : ''; };
    var p = function (n) { return all ? Math.round(n / all * 100) + '%' : '—'; };
    return '<div class="mkr-h">Clicks by device</div><div class="mke-card"><div class="mkr-split">' + seg(d.mobile, '#e0457b') + seg(d.tablet, '#f1a7bd') + seg(d.desktop, '#3b3b45') + '</div>'
      + '<div class="mkr-legend"><span><b style="background:#e0457b"></b>Mobile ' + p(d.mobile) + ' (' + h.num(d.mobile) + ')</span><span><b style="background:#f1a7bd"></b>Tablet ' + p(d.tablet) + '</span><span><b style="background:#3b3b45"></b>Desktop ' + p(d.desktop) + ' (' + h.num(d.desktop) + ')</span>'
      + (d.bot ? '<span>' + h.num(d.bot) + ' by link scanners, not in the split</span>' : '') + '</div></div>';
  }

  function failures(r, h) {
    var fails = (r.failures || []).map(function (f) { return '<tr><td>' + h.esc(f.email) + '</td><td>' + (f.status === 'failed' ? 'Refused' : 'Skipped') + '</td><td class="mke-hide-sm">' + h.esc(f.error) + '</td></tr>'; }).join('');
    return fails ? '<div class="mkr-h">Not delivered</div><div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Address</th><th>What happened</th><th class="mke-hide-sm">The mail server said</th></tr></thead><tbody>' + fails + '</tbody></table></div>' : '';
  }

  function links(r, h) {
    var c = r.insights.clicks;
    var lr = (c.links || []).map(function (l) { return '<tr><td>' + h.esc(l.label) + '<div class="sub">' + h.esc(l.url) + '</div></td><td>' + h.num(l.clicks) + '</td><td>' + h.num(l.people) + '</td></tr>'; }).join('');
    var pr = (c.products || []).map(function (p) { return '<tr><td>' + h.esc(p.name) + '</td><td>' + h.num(p.clicks) + '</td><td>' + h.num(p.people) + '</td></tr>'; }).join('');
    return '<div class="mkr-h">Clicks per product</div><div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Product</th><th>Clicks</th><th>People</th></tr></thead><tbody>'
      + (pr || '<tr><td colspan="3"><div class="mke-empty">No product in this email has been clicked yet.</div></td></tr>') + '</tbody></table></div>'
      + '<div class="mkr-h">Every link</div><div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Link</th><th>Clicks</th><th>People</th></tr></thead><tbody>'
      + (lr || '<tr><td colspan="3"><div class="mke-empty">No links yet.</div></td></tr>') + '</tbody></table></div>';
  }

  function people(r, h) {
    var opts = FILTERS.map(function (f) { return '<option value="' + f[0] + '"' + (f[0] === R.filter ? ' selected' : '') + '>' + h.esc(f[1]) + '</option>'; }).join('');
    return '<form class="mkr-tools" data-mkr-form><input class="mke-in" type="search" name="q" maxlength="100" placeholder="Search an address" value="' + h.esc(R.q) + '" aria-label="Search an address">'
      + '<select class="mke-in" name="filter" aria-label="Show">' + opts + '</select><button type="submit" class="btn sm">Search</button>'
      + '<a class="btn ghost sm" data-mkr-csv download href="' + h.esc(h.base + '/reports/' + encodeURIComponent(r.id) + '/export') + '"' + (R.rec && R.rec.can_export === false ? ' hidden' : '') + '>Export CSV</a></form>'
      + '<div data-mkr-people>' + peopleTable(h) + '</div>';
  }

  function yes(b, word) { return b ? '<span class="mkr-yes">' + word + '</span>' : '<span class="mkr-no">—</span>'; }

  function peopleTable(h) {
    var d = R.rec;
    if (!d) return '<div class="mke-empty">Loading…</div>';
    var rows = (d.rows || []).map(function (p) {
      var open = p.opened ? (KIND[p.open_kind] || 'Opened') : (p.open_kind === 'scanner' ? 'Scanner only' : '—');
      return '<tr><td>' + h.esc(p.email) + (p.unsubscribed ? '<div class="sub">Unsubscribed</div>' : '') + '</td>'
        + '<td class="mke-hide-sm">' + (p.bounce ? '<span class="mkr-bad">Bounced (' + h.esc(p.bounce) + ')</span>' : (p.status === 'sent' ? 'Delivered' : h.esc(p.status))) + '</td>'
        + '<td>' + (p.opened ? '<span class="mkr-yes">' + h.esc(open) + '</span>' : '<span class="mkr-no">' + h.esc(open) + '</span>') + '</td>'
        + '<td>' + yes(p.clicked, h.num(p.clicks) + '×') + '</td>'
        + '<td>' + (p.orders ? '<span class="mkr-yes">' + h.num(p.orders) + ' · ' + h.esc(p.revenue) + '</span>' : '<span class="mkr-no">—</span>') + '</td></tr>';
    }).join('');
    return '<div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Address</th><th class="mke-hide-sm">Delivery</th><th>Opened*</th><th>Clicked</th><th>Ordered</th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="5"><div class="mke-empty">Nobody matches.</div></td></tr>') + '</tbody></table></div>'
      + '<div class="mkr-pager"><span>' + h.num(d.total) + ' people · page ' + h.num(d.page) + ' of ' + h.num(d.pages) + '</span>'
      + '<button type="button" class="btn ghost sm" data-mkr-page="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>← Previous</button>'
      + '<button type="button" class="btn ghost sm" data-mkr-page="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>Next →</button></div>';
  }

  async function loadPeople() {
    if (R.busy || !R.h || R.id === null) return;
    R.busy = true;
    var h = R.h, box;
    try {
      var url = h.base + '/reports/' + encodeURIComponent(R.id) + '/recipients?filter=' + encodeURIComponent(R.filter) + '&page=' + R.page + '&q=' + encodeURIComponent(R.q);
      var res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      R.rec = res.ok ? await res.json() : { rows: [], total: 0, page: 1, pages: 1 };
    } catch (e) { R.rec = { rows: [], total: 0, page: 1, pages: 1 }; }
    R.busy = false;
    box = document.querySelector('[data-mkr-people]');
    if (box) box.innerHTML = peopleTable(h);
    var csv = document.querySelector('[data-mkr-csv]');
    if (csv) csv.hidden = R.rec.can_export === false;
  }

  /* ---------------------------------------------------------------- events */

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-mkr-pane],[data-mkr-page]');
    if (!t || !R.h) return;
    if (t.hasAttribute('data-mkr-pane')) {
      R.pane = t.getAttribute('data-mkr-pane');
      document.querySelectorAll('[data-mkr-pane]').forEach(function (b) { var on = b === t; b.classList.toggle('on', on); b.setAttribute('aria-selected', String(on)); });
      document.querySelectorAll('[data-mkr-body]').forEach(function (p) { p.hidden = p.getAttribute('data-mkr-body') !== R.pane; });
      if (R.pane === 'people' && !R.rec) loadPeople();
      return;
    }
    var n = parseInt(t.getAttribute('data-mkr-page'), 10);
    if (n >= 1) { R.page = n; loadPeople(); }
  });

  document.addEventListener('submit', function (e) {
    var f = e.target.closest && e.target.closest('[data-mkr-form]');
    if (!f) return;
    e.preventDefault();
    R.q = String(f.elements.q.value || '').slice(0, 100);
    R.filter = String(f.elements.filter.value || 'all');
    R.page = 1;
    loadPeople();
  });

  document.addEventListener('change', async function (e) {
    var t = e.target;
    if (!t.hasAttribute || !t.hasAttribute('data-mkr-track') || !R.h) return;
    var on = t.checked;
    t.disabled = true;
    try {
      var res = await fetch(R.h.base + '/open-tracking', {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
        body: JSON.stringify({ on: on })
      });
      if (!res.ok) throw new Error(String(res.status));
      var d = await res.json();
      var card = t.closest('.mke-card');
      if (card) card.outerHTML = switchCard(d.open_tracking, R.h);
      if (window.kbbMarketingEmails && window.kbbMarketingEmails.state) window.kbbMarketingEmails.state.reportsOpenTracking = d.open_tracking;
    } catch (err) {
      t.checked = !on; t.disabled = false;
      try { if (typeof window.toast === 'function') window.toast(err && err.message === '403' ? 'Your role cannot change this.' : 'Could not save. Try again.'); } catch (x) {}
    }
  });

  window.kbbMktReport = { list: list, panel: panel };
})();
