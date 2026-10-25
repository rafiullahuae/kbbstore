{{--
    DASHBOARD -> REVENUE (Lane REV). The owner, 24 October: "in the dashboard,
    and in the owner app dashboard, i need the total revenue as This month, last
    30 days, Month wise selection and custom date, and also year wise. so we can
    track our revenue."

    One card under the dashboard's tiles: three quick periods (This month, Last
    30 days, This year) and three the owner picks (a month, a year, two dates).
    It shows the Total -- the owner app's own figure, OwnerAppSales: paid orders
    less their refunds, in the shop's timezone, the Analytics screen's number --
    with paid orders, the average order, the change against the period before
    and one bar per day (per week for a year). GET /admin-api/revenue-report,
    analytics.view: a role without it gets a 403 and the card removes itself.

    Light: one request per period chosen, nothing on a timer, no layout
    measured. A late answer paints only while its own card is still on the
    page (the console is one page; CLAUDE.md "a late answer can paint over
    another screen"). Every value printed is escaped.
--}}
<style>
.rvc{margin-top:16px}
.rvc .rv-h{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.rvc .rv-h b{font-size:15px}
.rvc .rv-span{font-size:12.5px;color:var(--ink-soft,#6b7280)}
.rvc .rv-q{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
.rvc .rv-q button{border:1px solid var(--line,#e5e7eb);background:var(--surface,#fff);color:inherit;border-radius:999px;padding:7px 13px;font:inherit;font-size:13px;font-weight:600;cursor:pointer}
.rvc .rv-q button[aria-pressed="true"]{background:#15a85a;border-color:#15a85a;color:#fff}
.rvc .rv-p{display:flex;gap:10px 18px;flex-wrap:wrap;margin-top:10px;align-items:flex-end}
.rvc .rv-p fieldset{border:0;margin:0;padding:0;display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap}
.rvc .rv-p legend{font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft,#6b7280);padding:0;margin-bottom:4px}
.rvc .rv-p select,.rvc .rv-p input{border:1px solid var(--line,#e5e7eb);border-radius:9px;padding:6px 8px;font:inherit;font-size:13px;background:var(--surface,#fff);color:inherit;min-height:34px}
.rvc .rv-p .go{border:0;background:#2a2f3a;color:#fff;border-radius:9px;padding:0 12px;min-height:34px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.rvc .rv-err{color:#b42318;font-size:12.5px;margin-top:6px}
.rvc .rv-fig{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-top:16px}
.rvc .rv-fig small{font-size:14px;font-weight:700;color:var(--ink-soft,#6b7280)}
.rvc .rv-fig strong{font-size:34px;line-height:1;font-weight:800;letter-spacing:-.02em}
.rvc .rv-d{font-size:12.5px;font-weight:700;border-radius:999px;padding:4px 9px;background:#e7f6ee;color:#15803d}
.rvc .rv-d.dn{background:#fdecea;color:#b42318}
.rvc .rv-m{display:flex;gap:18px;flex-wrap:wrap;margin-top:8px;font-size:13px;color:var(--ink-soft,#6b7280)}
.rvc .rv-m b{color:var(--ink,#111);font-size:14px}
.rvc .rv-bars{display:flex;align-items:flex-end;gap:2px;height:110px;margin-top:16px;border-bottom:1px solid var(--line,#e5e7eb)}
.rvc .rv-bars i{flex:1 1 0;min-width:0;background:#15a85a;opacity:.75;border-radius:3px 3px 0 0}
.rvc .rv-bars i.z{opacity:.18;height:2px}
.rvc .rv-ax{display:flex;gap:2px;margin-top:4px;font-size:10.5px;color:var(--ink-soft,#6b7280)}
.rvc .rv-ax span{flex:1 1 0;min-width:0;text-align:center;white-space:nowrap;overflow:visible}
.rvc.busy .rv-body{opacity:.45}
</style>
@verbatim
<script>
(function () {
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var QUICK = [['month', 'This month'], ['30d', 'Last 30 days'], ['year', 'This year']];
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function cookie(n) { var m = document.cookie.match(new RegExp('(?:^|; )' + n + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  function pad(n) { return String(n).padStart(2, '0'); }

  function controls(range) {
    var now = new Date(), y = now.getFullYear(), years = '', today = y + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    for (var i = y; i >= 2015; i--) years += '<option value="' + i + '">' + i + '</option>';
    return '<div class="rv-q" role="group" aria-label="Revenue period">' + QUICK.map(function (q) { return '<button type="button" data-rq="' + q[0] + '" aria-pressed="' + (range === q[0]) + '">' + q[1] + '</button>'; }).join('') + '</div>'
      + '<div class="rv-p">'
      + '<fieldset><legend>A month</legend><select data-rmm aria-label="Month">' + MONTHS.map(function (m, i) { return '<option value="' + pad(i + 1) + '"' + (i === now.getMonth() ? ' selected' : '') + '>' + m + '</option>'; }).join('') + '</select><select data-rmy aria-label="Year of the month">' + years + '</select><button type="button" class="go" data-rp="m">Show</button></fieldset>'
      + '<fieldset><legend>A year</legend><select data-ry aria-label="Year">' + years + '</select><button type="button" class="go" data-rp="y">Show</button></fieldset>'
      + '<fieldset><legend>Custom dates</legend><input type="date" data-rf aria-label="From" min="2015-01-01" max="' + today + '"><input type="date" data-rt aria-label="To" min="2015-01-01" max="' + today + '" value="' + today + '"><button type="button" class="go" data-rp="c">Show</button></fieldset>'
      + '</div><div class="rv-err" data-rerr hidden></div>';
  }

  function body(d) {
    var bars = d.bars || [], vals = bars.map(function (b) { return b.value || 0; }), max = Math.max.apply(null, [1].concat(vals));
    var every = bars.length <= 12 ? 1 : bars.length <= 31 ? 5 : 8;
    var delta = d.delta === null || d.delta === undefined ? '' : '<span class="rv-d' + (d.delta < 0 ? ' dn' : '') + '">' + (d.delta < 0 ? '▼ ' : '▲ ') + Math.abs(d.delta) + '% ' + esc(d.vs) + '</span>';
    return '<div class="rv-fig"><small>' + esc(d.currency) + '</small><strong>' + esc(d.total) + '</strong>' + delta + '</div>'
      + '<div class="rv-m"><span><b>' + esc(d.orders) + '</b> paid orders</span><span>Average order <b>' + esc(d.average || '—') + '</b></span></div>'
      + (bars.length > 1 ? '<div class="rv-bars" aria-hidden="true">' + bars.map(function (b) {
          if (b.value === null) return '<i class="z" style="opacity:0"></i>';
          return b.value > 0 ? '<i style="height:' + Math.max(3, Math.round(b.value / max * 100)) + '%" title="' + esc(b.label) + ': ' + esc(d.currency) + ' ' + esc(Math.round(b.value).toLocaleString('en-US')) + '"></i>' : '<i class="z"></i>';
        }).join('') + '</div><div class="rv-ax" aria-hidden="true">' + bars.map(function (b, i) { return '<span>' + (i % every === 0 ? esc(b.label) : '') + '</span>'; }).join('') + '</div>' : '');
  }

  window.kbbRevenueCard = function (host) {
    if (!host) return;
    var range = 'month';
    host.innerHTML = '<section class="card pad rvc" aria-labelledby="rvH"><div class="rv-h"><b id="rvH">Revenue</b><span class="rv-span" data-rspan></span></div>' + controls(range) + '<div class="rv-body" data-rbody><p style="margin-top:16px;color:var(--ink-soft,#6b7280)">Loading…</p></div></section>';
    var card = host.firstElementChild;

    function load(key) {
      range = key;
      card.querySelectorAll('[data-rq]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-rq') === key)); });
      card.classList.add('busy');
      fetch(root() + '/admin-api/revenue-report?range=' + encodeURIComponent(key), { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' })
        .then(function (r) { if (r.status === 403) { if (card.isConnected) host.remove(); return null; } return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
          // A late answer for an old period, or for a card no longer on show, paints nothing.
          if (!d || !card.isConnected || key !== range) return;
          card.classList.remove('busy');
          // A picked month, year or two dates already says its dates; a quick period adds them.
          card.querySelector('[data-rspan]').textContent = d.label + (/^(month|30d|year)$/.test(d.range) && d.span ? ' · ' + d.span : '');
          card.querySelector('[data-rbody]').innerHTML = body(d);
        })
        .catch(function (status) {
          if (!card.isConnected || key !== range) return;
          card.classList.remove('busy');
          // A 404 here is the route cache, not the data: the remedy is one click (Platform → Cache).
          var msg = status === 404 ? 'Revenue is not in the server\'s route table yet. Clear the route cache (Platform → Cache) and reload.' : 'Revenue could not be loaded. Try again.';
          card.querySelector('[data-rbody]').innerHTML = '<p class="rv-err">' + esc(msg) + '</p>';
        });
    }

    card.addEventListener('click', function (e) {
      var q = e.target.closest('[data-rq]');
      if (q) { load(q.getAttribute('data-rq')); return; }
      var p = e.target.closest('[data-rp]');
      if (!p) return;
      var v = function (s) { var x = card.querySelector(s); return x ? x.value : ''; }, err = card.querySelector('[data-rerr]'), kind = p.getAttribute('data-rp'), key;
      err.hidden = true;
      if (kind === 'm') key = 'm:' + v('[data-rmy]') + '-' + v('[data-rmm]');
      else if (kind === 'y') key = 'y:' + v('[data-ry]');
      else {
        var f = v('[data-rf]'), t = v('[data-rt]');
        if (!f || !t) { err.textContent = 'Choose both dates.'; err.hidden = false; return; }
        if (t < f) { err.textContent = 'The second date is before the first.'; err.hidden = false; return; }
        key = 'c:' + f + ':' + t;
      }
      load(key);
    });
    load(range);
  };
  // The dashboard may already be on screen (drawn before this script ran).
  var waiting = document.getElementById('kbbRevCard');
  if (waiting && !waiting.firstChild) window.kbbRevenueCard(waiting);
})();
</script>
@endverbatim
