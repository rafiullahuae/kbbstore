
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
  /*
   * NO SIDEBAR ENTRY.
   *
   * This screen had one called "Coupons" and the editor added a second called
   * "Manage Coupons" beneath it. The owner opened "Coupons", got this
   * read-only report, and concluded the editor had never shipped. One name,
   * one entry: the editor takes "Coupons" and links here from inside it.
   * Kept as a function rather than deleted so the call sites below, and the
   * reason, stay visible.
   */
  function addNavEntry(){
    return;
  }

  function addNavEntryDisabled(){
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
      + '<div class="cu-stat"><span>Coupons</span><b class="cu-num">' + esc((list && list.summary.coupons) || 0) + '</b></div>'
      + '<div class="cu-stat"><span>Total redemptions</span><b class="cu-num">' + esc((list && list.summary.redemptions) || 0) + '</b></div>'
      + '<div class="cu-stat"><span>Fully redeemed</span><b class="cu-num">' + esc((list && list.summary.exhausted) || 0) + '</b></div>'
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
      + '<div class="cu-sub">How many times each code has actually been redeemed. Select one to see who used it. '
        + '<button type="button" class="cu-link" id="cu-manage">Back to coupons</button>.</div></div></div>'
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

    /* Deliberately NOT cu-back: that id is already taken by the button that
       clears a selected coupon's detail view, and querySelector returns the
       first match in the document, so reusing it would have handed this
       handler that button and broken it. */
    var manage = document.querySelector('#cu-manage');
    if (manage) manage.onclick = function(){ window.go('coupon-editor'); };

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
