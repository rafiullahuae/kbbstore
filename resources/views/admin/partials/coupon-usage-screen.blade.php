{{--
    Store - Coupons.

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go, toast() and the design tokens
    this screen borrows.

    Its own file rather than more lines inside a 7,400-line Blade: several lanes
    edit that file at once, and a screen that lives on its own can be reviewed,
    reverted and merged on its own. The cost is that it cannot reach
    app.blade.php's module-scoped constants -- NAV, TITLES and ADMIN_BASE are
    const, not window properties -- so it appends its own sidebar entry to the
    rendered nav and wraps window.go instead. Both are surfaces the console
    already exposes for exactly this. The shape is deliberately the same as
    admin/partials/manual-order-screen.blade.php; that is the precedent.

    WHAT THIS SCREEN IS FOR. Until the redemption package, coupons.usage_count
    was never written, so a code's usage was not merely unshown, it was not
    recorded. It is recorded now, which makes "how many times has this been
    used, and by whom" a question with an answer -- and it is the question that
    tells the owner a code has been posted somewhere public.

    Read-only on purpose. Making the counter true is one job; editing coupons
    while orders are being placed against them is another.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.

    The whole body is wrapped in one so that the {{ }} inside JavaScript
    template literals is not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Coupons screen. Every rule is prefixed cu- and appears nowhere else in the
   console, so this file can never restyle another screen by accident.

   Same layout rule as the New Order screen: nothing here may be wider than its
   column at 390px, because the owner reviews on a phone. The table sits in its
   own scroller and no element carries a min-width larger than the narrowest
   content box.
--------------------------------------------------------------------------- */
.cu-wrap{display:grid;gap:16px}
.cu-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:16px}
.cu-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
.cu-title{font-weight:650;font-size:15px}
.cu-sub{color:var(--ink-soft,#6b7280);font-size:12.5px}

.cu-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.cu-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:12px 14px}
.cu-stat b{display:block;font-size:20px;line-height:1.3;font-variant-numeric:tabular-nums}
.cu-stat span{color:var(--ink-soft,#6b7280);font-size:12px}

.cu-search{display:flex;gap:8px;flex-wrap:wrap}
.cu-search input{flex:1 1 180px;min-width:0;padding:8px 10px;font:inherit;
                 border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}

.cu-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.cu-table{width:100%;border-collapse:collapse;font-size:13px}
.cu-table th,.cu-table td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--border,#e6e6e6);
                          white-space:nowrap;vertical-align:middle}
.cu-table th{font-weight:600;color:var(--ink-soft,#6b7280);font-size:11.5px;
             text-transform:uppercase;letter-spacing:.04em}
.cu-table tbody tr{cursor:pointer}
.cu-table tbody tr:hover{background:rgba(127,127,127,.06)}
.cu-num{font-variant-numeric:tabular-nums}

.cu-code{font-weight:650;letter-spacing:.02em}

/* The usage bar. Width is a percentage of the limit; an uncapped code gets no
   bar at all rather than a full one, because "unlimited" and "all used up"
   must never look alike. */
.cu-bar{position:relative;height:6px;border-radius:999px;background:rgba(127,127,127,.18);
        min-width:80px;overflow:hidden}
.cu-bar i{position:absolute;inset:0 auto 0 0;border-radius:999px;background:#1f7d52}
.cu-bar.is-warn i{background:#b7791f}
.cu-bar.is-done i{background:#b4443c}

.cu-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11.5px;
         border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280)}
.cu-pill.is-done{border-color:#b4443c;color:#b4443c}

.cu-empty{padding:26px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.cu-back{background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer;
         text-decoration:underline;opacity:.75}
.cu-pager{display:flex;gap:8px;align-items:center;justify-content:flex-end;font-size:12.5px}
.cu-pager button{padding:5px 10px;border:1px solid var(--border,#e6e6e6);border-radius:8px;
                 background:transparent;color:inherit;font:inherit;cursor:pointer}
.cu-pager button[disabled]{opacity:.4;cursor:default}

@media (max-width:640px){
  .cu-card{padding:13px}
  .cu-table th,.cu-table td{padding:8px}
}
</style>

<script>
(function(){
  'use strict';

  /* The console builds its sidebar and its router before this runs. Both are
     const inside that script's own scope, so neither can be read from here —
     the entry is appended to the rendered DOM and the router is wrapped. */

  var SCREEN = 'coupon-usage';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var list = null;        // the coupon list payload
  var detail = null;      // one coupon's redemptions, when open
  var query = '';
  var page = 1;
  var detailPage = 1;
  var banner = null;
  var busy = false;
  var seq = 0;            // guards against an older response landing last

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path){
    var opts = {headers:{'Accept':'application/json'}, credentials:'same-origin'};
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function icon(d){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" ' +
           'stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">' + d + '</svg>';
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    if (document.querySelector('[data-go="' + SCREEN + '"]')) return;

    // Anchored to the Orders entry, like the New Order screen, because the
    // Store group is the one the owner already opens for money questions.
    var anchor = document.querySelector('#nav [data-go="order-new"]')
              || document.querySelector('#nav [data-go="orders"]');
    if (!anchor) return;

    var b = document.createElement('button');
    b.className = 'nav-item';
    b.dataset.go = SCREEN;
    b.innerHTML = icon('<path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6z"/><path d="M14 8v8"/>')
                + '<span>Coupons</span>';
    b.onclick = function(){ window.go(SCREEN); };
    anchor.parentNode.insertBefore(b, anchor.nextSibling);
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Store';
    if (title) title.textContent = 'Coupons';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    loadList();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function loadList(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/coupons?page=' + page + (query ? '&q=' + encodeURIComponent(query) : ''));
      if (mine !== seq) return;
      list = body;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      // A 404 here almost always means the route file was shipped without the
      // clear_caches migration having run, so the compiled route table does
      // not know these paths yet. Said plainly rather than rendering an empty
      // screen, which reads as "no coupons".
      banner = e.status === 404
        ? 'The coupon endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load coupons.';
      list = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function openCoupon(id){
    var mine = ++seq;
    busy = true;
    render();

    try {
      detail = await api('/coupons/' + id + '?page=' + detailPage);
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = 'Could not load that coupon.';
      detail = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  /* ---------------------------------------------------------------- views */
  function usageCell(c){
    if (c.usage_limit === null || c.usage_limit === undefined) {
      return '<span class="cu-num">' + esc(c.usage_count) + '</span> '
           + '<span class="cu-pill">no limit</span>';
    }

    var pct = c.usage_limit > 0 ? Math.min(100, Math.round(c.usage_count / c.usage_limit * 100)) : 100;
    var cls = c.exhausted ? ' is-done' : (pct >= 80 ? ' is-warn' : '');

    return '<div style="display:flex;gap:9px;align-items:center">'
         + '<span class="cu-num">' + esc(c.usage_count) + ' / ' + esc(c.usage_limit) + '</span>'
         + '<span class="cu-bar' + cls + '"><i style="width:' + pct + '%"></i></span>'
         + '</div>';
  }

  function listView(){
    var rows = (list && list.coupons) || [];

    var head = '<div class="cu-stats">'
      + '<div class="cu-stat"><b class="cu-num">' + esc((list && list.summary.coupons) || 0) + '</b><span>Coupons</span></div>'
      + '<div class="cu-stat"><b class="cu-num">' + esc((list && list.summary.redemptions) || 0) + '</b><span>Total redemptions</span></div>'
      + '<div class="cu-stat"><b class="cu-num">' + esc((list && list.summary.exhausted) || 0) + '</b><span>Fully redeemed</span></div>'
      + '</div>';

    var body;

    if (busy && !list) {
      body = '<div class="cu-empty">Loading…</div>';
    } else if (!rows.length) {
      body = '<div class="cu-empty">' + (query ? 'No code matches that search.' : 'No coupons yet.') + '</div>';
    } else {
      body = '<div class="cu-scroll"><table class="cu-table"><thead><tr>'
        + '<th>Code</th><th>Value</th><th>Used</th><th>Left</th><th>Per customer</th><th>Expires</th>'
        + '</tr></thead><tbody>'
        + rows.map(function(c){
            return '<tr data-coupon="' + esc(c.id) + '">'
              + '<td><span class="cu-code">' + esc(c.code) + '</span>'
                + (c.exhausted ? ' <span class="cu-pill is-done">fully redeemed</span>' : '') + '</td>'
              + '<td>' + esc(c.amount_display) + '</td>'
              + '<td>' + usageCell(c) + '</td>'
              + '<td class="cu-num">' + (c.remaining === null || c.remaining === undefined ? '—' : esc(c.remaining)) + '</td>'
              + '<td class="cu-num">' + (c.usage_limit_per_user === null || c.usage_limit_per_user === undefined ? '—' : esc(c.usage_limit_per_user)) + '</td>'
              + '<td>' + (c.expires_at ? esc(String(c.expires_at).slice(0, 10)) : '—') + '</td>'
              + '</tr>';
          }).join('')
        + '</tbody></table></div>'
        + pager(list, function(n){ page = n; loadList(); });
    }

    return head
      + '<div class="cu-card">'
      + '<div class="cu-head"><div><div class="cu-title">Coupon usage</div>'
      + '<div class="cu-sub">How many times each code has actually been redeemed. Select one to see who used it.</div></div></div>'
      + '<div class="cu-search" style="margin:12px 0">'
      + '<input id="cu-q" type="search" placeholder="Search by code" value="' + esc(query) + '" autocomplete="off">'
      + '</div>'
      + body
      + '</div>';
  }

  function detailView(){
    var c = detail.coupon;
    var rows = detail.redemptions || [];

    var body;

    if (!rows.length) {
      body = '<div class="cu-empty">This code has not been redeemed yet.</div>';
    } else {
      body = '<div class="cu-scroll"><table class="cu-table"><thead><tr>'
        + '<th>When</th><th>Customer</th><th>Order</th><th>Status</th><th>Discount</th>'
        + '</tr></thead><tbody>'
        + rows.map(function(r){
            return '<tr>'
              + '<td>' + (r.redeemed_at ? esc(String(r.redeemed_at).slice(0, 16)) : '—') + '</td>'
              + '<td>' + esc(r.email || '—') + '</td>'
              + '<td>' + esc(r.order_number || '—') + '</td>'
              + '<td>' + esc(r.order_status || '—') + '</td>'
              + '<td class="cu-num">' + esc(r.amount_display) + '</td>'
              + '</tr>';
          }).join('')
        + '</tbody></table></div>'
        + pager(detail, function(n){ detailPage = n; openCoupon(c.id); });
    }

    return '<div class="cu-card">'
      + '<button class="cu-back" id="cu-back">Back to all coupons</button>'
      + '<div class="cu-head" style="margin-top:12px"><div>'
      + '<div class="cu-title"><span class="cu-code">' + esc(c.code) + '</span> — ' + esc(c.amount_display) + '</div>'
      + '<div class="cu-sub">'
        + esc(c.usage_count) + ' redemption' + (c.usage_count === 1 ? '' : 's')
        + (c.usage_limit === null || c.usage_limit === undefined
            ? ', no limit set'
            : ' of ' + esc(c.usage_limit) + ', ' + esc(c.remaining) + ' left')
        + (c.usage_limit_per_user ? '. Limit ' + esc(c.usage_limit_per_user) + ' per customer.' : '')
      + '</div></div></div>'
      + '<div style="margin-top:12px">' + body + '</div>'
      + '</div>';
  }

  function pager(payload, onGo){
    if (!payload || payload.pages <= 1) return '';

    var current = payload.page;

    // The handler is attached in bind(); storing it here keeps the markup a
    // string and the wiring in one place.
    pager.go = onGo;

    return '<div class="cu-pager" style="margin-top:12px">'
      + '<button id="cu-prev"' + (current <= 1 ? ' disabled' : '') + '>Previous</button>'
      + '<span>Page ' + esc(current) + ' of ' + esc(payload.pages) + '</span>'
      + '<button id="cu-next"' + (current >= payload.pages ? ' disabled' : '') + '>Next</button>'
      + '</div>';
  }

  function render(){
    var host = document.querySelector('#view') || document.querySelector('#main') || document.querySelector('.content');
    if (!host) return;

    // Only paint when this screen is the one showing, so a render triggered by
    // a late response cannot overwrite whatever the operator navigated to.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="cu-wrap">';

    if (banner) {
      html += '<div class="cu-card" style="border-color:#b4443c;color:#b4443c">' + esc(banner) + '</div>';
    }

    html += detail ? detailView() : listView();
    html += '</div>';

    host.innerHTML = html;
    bind();
  }

  function bind(){
    var q = document.querySelector('#cu-q');
    if (q) {
      var timer = null;
      q.oninput = function(){
        clearTimeout(timer);
        var value = q.value;
        timer = setTimeout(function(){
          query = value.trim();
          page = 1;
          loadList();
        }, 220);
      };
    }

    document.querySelectorAll('.cu-table tbody tr[data-coupon]').forEach(function(tr){
      tr.onclick = function(){
        detailPage = 1;
        openCoupon(Number(tr.dataset.coupon));
      };
    });

    var back = document.querySelector('#cu-back');
    if (back) back.onclick = function(){ detail = null; render(); };

    var prev = document.querySelector('#cu-prev');
    var next = document.querySelector('#cu-next');
    var payload = detail || list;

    if (prev && payload) prev.onclick = function(){ if (payload.page > 1) pager.go(payload.page - 1); };
    if (next && payload) next.onclick = function(){ if (payload.page < payload.pages) pager.go(payload.page + 1); };
  }

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
