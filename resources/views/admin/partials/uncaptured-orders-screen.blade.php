{{--
    STORE → UNCAPTURED ORDERS (Tabby). Lists Tabby orders authorised at checkout
    but never captured, with a Capture button per row that posts the EXISTING,
    idempotent /admin-api/orders/{id}/capture (PaymentSettlementController). This
    screen reads the list and reuses that one capture endpoint — it adds no new
    money path and changes no payment code.

    Wraps window.go for 'tabbycaptures'; its sidebar row is AdminNav's late entry
    (Store group, cap orders.money). Super light: one GET when opened, no polling,
    no timer, no new dependency. Every string is escaped. Guards with the same
    ours() shape the console expects — it only paints into #content when its own
    [data-uc] root is on show.
--}}
<script>
(function () {
  var SCREEN = 'tabbycaptures';
  var data = null, status = '', statusErr = false, busy = {};

  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ours() { return !!document.querySelector('#content [data-uc]'); }

  async function apiGet() {
    var r = await fetch(base() + '/admin-api/orders/uncaptured', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    var j = null; try { j = await r.json(); } catch (e) {}
    if (!r.ok) { var e = new Error((j && (j.error || j.message)) || ('uncaptured ' + r.status)); e.status = r.status; throw e; }
    return j;
  }
  async function apiCapture(id) {
    var r = await fetch(base() + '/admin-api/orders/' + id + '/capture', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin'
    });
    var j = null; try { j = await r.json(); } catch (e) {}
    if (!r.ok) { var e = new Error((j && (j.error || j.message)) || ('capture ' + r.status)); e.status = r.status; throw e; }
    return j;
  }

  async function load() {
    status = ''; statusErr = false; data = null; paint();
    try { data = await apiGet(); }
    catch (e) {
      statusErr = true;
      status = e && e.status === 403 ? 'Your role cannot capture payments. An owner or manager can.'
        : (e && e.status === 404 ? "This screen is not in the server's route table yet. Apply the package (it clears the route cache) and reload."
        : 'Could not load the list. Try again in a moment.');
    }
    paint();
  }

  async function capture(id, btn) {
    if (busy[id]) return; busy[id] = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Capturing…'; }
    try {
      await apiCapture(id);
      await load(); // the captured row drops out and the totals refresh
    } catch (e) {
      busy[id] = false;
      var cell = document.querySelector('[data-uc-row="' + id + '"] [data-uc-msg]');
      if (cell) { cell.textContent = e.message; cell.className = 'uc-msg uc-err'; }
      if (btn) { btn.disabled = false; btn.textContent = 'Capture'; }
    }
  }

  function rowsHTML() {
    if (!data.orders.length) return '<div class="uc-empty">No uncaptured Tabby orders — everything is captured.</div>';
    var h = '<table class="uc-tbl"><thead><tr><th>Order</th><th>Amount</th><th>Status</th><th>Authorised</th><th>Age</th><th></th></tr></thead><tbody>';
    data.orders.forEach(function (o) {
      var flag = o.expired ? '<span class="uc-tag uc-tag-exp">likely expired</span>' : '<span class="uc-tag uc-tag-now">capture now</span>';
      h += '<tr data-uc-row="' + esc(o.id) + '"><td class="uc-no">' + esc(o.order_number) + '</td>'
        + '<td class="uc-amt">' + esc(o.amount) + ' ' + esc(o.currency) + '</td>'
        + '<td>' + esc(o.status) + '</td>'
        + '<td>' + esc(o.authorised_at || '') + '</td>'
        + '<td>' + esc(o.days_since_auth) + 'd ' + flag + '</td>'
        + '<td class="uc-act"><button type="button" class="btn" data-uc-cap="' + esc(o.id) + '">Capture</button><div class="uc-msg" data-uc-msg></div></td></tr>';
    });
    return h + '</tbody></table>';
  }

  function paint() {
    var host = document.getElementById('content'); if (!host) return;
    if (!host.querySelector('[data-uc]')) {
      host.innerHTML = '<div class="wrap"><div class="page-head"><h2>Uncaptured orders</h2>'
        + '<p>Tabby authorises at checkout and pays nothing until captured — an authorisation nobody captures within about 30 days is voided by Tabby. These Tabby orders are authorised but not captured; capture the ones marked <b>capture now</b> before they lapse.</p></div>'
        + '<div data-uc></div></div>'
        + '<style>'
        + '.uc-sum{display:flex;gap:18px;flex-wrap:wrap;margin:0 0 12px;font-size:13px}'
        + '.uc-sum b{font-weight:800}.uc-sum .now{color:#b26a00}.uc-sum .exp{color:#b11}'
        + '.uc-tbl{width:100%;border-collapse:collapse;font-size:13px}'
        + '.uc-tbl th,.uc-tbl td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line,#eee);vertical-align:top}'
        + '.uc-tbl th{font-weight:700;color:var(--ink-soft,#777);font-size:12px}'
        + '.uc-no{font-weight:700}.uc-amt{font-variant-numeric:tabular-nums;white-space:nowrap}'
        + '.uc-tag{display:inline-block;margin-inline-start:6px;font-size:11px;font-weight:700;border-radius:999px;padding:1px 8px}'
        + '.uc-tag-now{background:#fff3e0;color:#b26a00}.uc-tag-exp{background:#fdecec;color:#b11}'
        + '.uc-act{white-space:nowrap}.uc-msg{font-size:11px;margin-top:4px}.uc-err{color:#b11}'
        + '.uc-empty,.uc-help{padding:16px;color:var(--ink-soft,#777)}.uc-help.uc-err{color:#b11}'
        + '</style>';
    }
    var root = host.querySelector('[data-uc]');
    if (!data) { root.innerHTML = '<div class="uc-help' + (statusErr ? ' uc-err' : '') + '">' + esc(status || 'Loading…') + '</div>'; return; }
    var sum = '<div class="uc-sum"><span><b>' + esc(data.count) + '</b> uncaptured</span>'
      + '<span class="now">Capturable: <b>' + esc(data.capturable_total) + ' ' + esc(data.currency) + '</b></span>'
      + '<span class="exp">Likely expired: <b>' + esc(data.expired_total) + ' ' + esc(data.currency) + '</b></span></div>';
    root.innerHTML = sum + rowsHTML();
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-uc-cap]');
    if (b && ours()) capture(b.getAttribute('data-uc-cap'), b);
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Uncaptured orders',
      icon: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M14.5 15.5l2 2 3.5-3.5"/>',
      group: 'Store',
      after: ['payments']
    });
  }
  if (window.kbbAddNavEntry) addNavEntry();

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]'); if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Store'; if (title) title.textContent = 'Uncaptured orders';
    var side = document.querySelector('#side'); if (side) side.classList.remove('open');
    load();
  };
})();
</script>
