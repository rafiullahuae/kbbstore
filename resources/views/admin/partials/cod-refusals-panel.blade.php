{{--
    STORE → PAYMENTS → CASH ON DELIVERY → REFUSED DELIVERIES.     (Lane CODR)

    The owner, 1 November 2026: hide cash on delivery from customers who did
    not accept a dispatched order, unless they have a completed one. This is
    the section on the Cash on delivery tab: the on/off switch, the list of
    customers it is hidden from, and "Allow COD again" per row.

    bindPayments() in app.blade.php calls window.kbbCodRefusals(ctx) after
    every paint; this file finds the Cash on delivery card itself and adds
    #pay_codr to it once per painted pane (the Payments screen names no
    gateway, by rule -- PaymentsGatewayTabsTest). One GET when drawn, one per search / page / action -- no polling, no
    timer, no per-keystroke request (search runs on Enter or the button).
    Every value is escaped through the console's own sesc(). A late answer only
    paints if its host is still in the document, so it cannot draw over
    another screen.
--}}
<style>
#pay_codr{margin-top:16px}
.codr-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.codr-tbl th,.codr-tbl td{padding:8px 10px;border-bottom:1px solid var(--line,#e6e9ef);text-align:start;vertical-align:middle}
.codr-tbl th{font-weight:600;color:var(--ink-soft,#5b6472)}
.codr-wrap{overflow-x:auto;margin-top:10px}
.codr-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}
.codr-bar .inp{flex:1 1 220px;min-width:0}
.codr-pg{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:10px;font-size:12.5px}
@media (max-width:640px){
  .codr-tbl thead{display:none}
  .codr-tbl tr{display:block;padding:8px 0;border-bottom:1px solid var(--line,#e6e9ef)}
  .codr-tbl td{display:block;border:0;padding:2px 4px;overflow-wrap:anywhere}
  .codr-tbl td:last-child{text-align:start!important;padding-top:6px}
}
</style>
<script>
(function () {
  window.kbbCodRefusals = function (ctx) {
    var card = document.querySelector('#content [data-paycard="cod"] .mmbody');
    if (!card || card.querySelector('#pay_codr')) return;
    var host = document.createElement('div');
    host.id = 'pay_codr';
    card.appendChild(host);
    var esc = ctx.esc, state = { q: '', page: 1, data: null, msg: '' };

    function alive() { return document.body.contains(host); }

    function when(s) {
      if (!s) return '—';
      var d = new Date(String(s).replace(' ', 'T'));
      return isNaN(d) ? esc(s) : esc(d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }));
    }

    function paint() {
      var d = state.data || { enabled: true, history_enabled: true, rows: [], total: 0, page: 1, pages: 1 };
      var rows = d.rows.map(function (r) {
        return '<tr><td>' + esc(r.email || '—') + '</td><td dir="ltr">' + esc(r.phone ? '+' + r.phone : '—') + '</td>' +
          '<td><a href="#" data-codrorder="' + esc(r.order_id) + '">#' + esc(r.order_number) + '</a></td>' +
          '<td>' + when(r.refused_at) + '</td>' +
          '<td>' + (r.source === 'history' ? 'Past cancelled order' : 'Refused at delivery') + '</td>' +
          '<td style="text-align:end"><button type="button" class="btn ghost sm" data-codrallow="' + esc(r.id) + '">Allow COD again</button></td></tr>';
      }).join('');

      host.innerHTML =
        '<div class="card mmcard" data-codr><div class="mmhd"><b>Refused deliveries</b></div><div class="mmbody">' +
        '<div class="ecopt istog"><div class="ecom"><div class="ecl"><label>Hide cash on delivery from customers who refused a delivery</label></div>' +
        '<div class="echelp">A customer whose cash on delivery order went from Shipped to Cancelled is not offered cash on delivery again — matched by email and phone, never by IP. ' +
        'Anyone with a completed order is never affected. Card, Apple Pay, Tabby and Tamara stay open to them.</div></div>' +
        '<div class="ecctl"><span class="ectog' + (d.enabled ? ' on' : '') + '" data-codrtog="enabled" role="switch" aria-checked="' + (d.enabled ? 'true' : 'false') + '" tabindex="0"></span></div></div>' +
        /* 2.60.636: the second switch, for every customer with a cancelled
           order from before that package and no completed one. */
        '<div class="ecopt istog"><div class="ecom"><div class="ecl"><label>Also hide it from customers with a past cancelled order</label></div>' +
        '<div class="echelp">Every cash on delivery order cancelled before this switch arrived, however it was cancelled, when that customer has no completed order. Card, Tabby and Tamara orders never count. ' +
        'Off offers them cash on delivery again; the refused deliveries above stay hidden.</div></div>' +
        '<div class="ecctl"><span class="ectog' + (d.history_enabled ? ' on' : '') + '" data-codrtog="history_enabled" role="switch" aria-checked="' + (d.history_enabled ? 'true' : 'false') + '" tabindex="0"></span></div></div>' +
        '<div class="codr-bar"><input type="search" class="inp" data-codrq placeholder="Search email, phone or order number" value="' + esc(state.q) + '" maxlength="120">' +
        '<button type="button" class="btn ghost" data-codrgo>Search</button>' +
        '<span class="echelp" style="margin:0">' + esc(d.total) + ' order' + (d.total === 1 ? '' : 's') + ' on this list</span></div>' +
        (state.msg ? '<p class="echelp" style="margin:8px 0 0">' + esc(state.msg) + '</p>' : '') +
        (rows
          ? '<div class="codr-wrap"><table class="codr-tbl"><thead><tr><th>Customer email</th><th>Phone</th><th>Order</th><th>Date</th><th>Why</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
          : '<p class="echelp" style="margin:12px 0 0">' + (state.q ? 'Nobody matches that search.' : 'Nobody — every customer is offered cash on delivery.') + '</p>') +
        (d.pages > 1
          ? '<div class="codr-pg"><button type="button" class="btn ghost sm" data-codrpg="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>Previous</button>' +
            '<span>Page ' + esc(d.page) + ' of ' + esc(d.pages) + '</span>' +
            '<button type="button" class="btn ghost sm" data-codrpg="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>Next</button></div>'
          : '') +
        '</div></div>';
      bind();
    }

    async function load() {
      try {
        var d = await ctx.api('/admin-api/cod-refusals?q=' + encodeURIComponent(state.q) + '&page=' + state.page);
        if (!alive()) return;
        state.data = d;
      } catch (e) {
        if (!alive()) return;
        state.msg = e && e.status === 403 ? 'Your role cannot see refused deliveries.' : 'Could not load refused deliveries.';
      }
      paint();
    }

    function bind() {
      host.querySelectorAll('[data-codrtog]').forEach(function (tog) {
        var key = tog.dataset.codrtog === 'history_enabled' ? 'history_enabled' : 'enabled';
        var name = key === 'enabled' ? 'Refused deliveries' : 'Past cancelled orders';
        var flip = async function () {
          var on = !tog.classList.contains('on');
          var body = {}; body[key] = on;
          try {
            await ctx.api('/admin-api/cod-refusals/settings', { method: 'POST', body: JSON.stringify(body) });
            if (!alive()) return;
            if (state.data) state.data[key] = on;
            state.msg = name + (on ? ': on — cash on delivery is hidden from them.' : ': off — they are offered cash on delivery.');
            ctx.toast && ctx.toast(name + (on ? ': on' : ': off'));
          } catch (e) { state.msg = 'Could not save — nothing changed.'; }
          if (alive()) paint();
        };
        tog.onclick = flip;
        tog.onkeydown = function (e) { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); flip(); } };
      });
      var q = host.querySelector('[data-codrq]');
      var go = function () { state.q = q ? q.value.trim() : ''; state.page = 1; state.msg = ''; load(); };
      var gb = host.querySelector('[data-codrgo]');
      if (gb) gb.onclick = go;
      if (q) q.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } };
      host.querySelectorAll('[data-codrpg]').forEach(function (b) {
        b.onclick = function () { state.page = +b.dataset.codrpg || 1; state.msg = ''; load(); };
      });
      host.querySelectorAll('[data-codrorder]').forEach(function (a) {
        a.onclick = function (e) { e.preventDefault(); if (ctx.openOrder) ctx.openOrder(+a.dataset.codrorder); };
      });
      host.querySelectorAll('[data-codrallow]').forEach(function (b) {
        b.onclick = async function () {
          b.disabled = true;
          try {
            await ctx.api('/admin-api/cod-refusals/' + encodeURIComponent(b.dataset.codrallow) + '/allow', { method: 'POST', body: '{}' });
            state.msg = 'Cash on delivery is offered to that customer again.';
          } catch (e) { state.msg = 'Could not allow it — nothing changed.'; }
          if (alive()) load();
        };
      });
    }

    paint();
    load();
  };
})();
</script>
