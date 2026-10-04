{{--
    Edit presence + Take over — the console half. Lane RL; the server half is
    App\Support\EditPresence, which is the authority (a save is refused with a
    409 while somebody else holds the record, with or without this script).

    The owner: "if any user is editing something ... it should notify to
    another user if he wants to edit the same. and have function to take
    control over it. must be smooth, and in real time, and super light".

    ── ONE HOOK, NOT TWENTY ────────────────────────────────────────────────
    window.fetch is wrapped once. A successful GET of ONE record (OPEN below:
    the product, page, article, coupon, order, email-template and campaign
    editors' own load calls) opens presence for it; a GET of that editor's list,
    or any go(), closes it. Every non-GET admin-api request carries the lock
    token in X-KBB-Edit-Lock. Category and Brand edit inside a dialog with no
    per-record GET, so those two dialogs call kbbPresence.open()/close() —
    one line each. No editor's own save code changes.

    ── SUPER LIGHT ─────────────────────────────────────────────────────────
    One setTimeout at a time: 15s with a record open, 30s elsewhere (for
    "online now"), and NONE while the tab is hidden — visibilitychange stops
    it and restarts it with an immediate beat. pagehide releases the lock with
    fetch({keepalive:true}): sendBeacon cannot carry the X-XSRF-TOKEN header
    this console signs with and there is no raw token on the page, so a beacon
    would be a 419. Nothing here measures layout; the banner is fixed and sized
    by CSS alone.

    Everything the server sends is esc()'d before innerHTML.
--}}
@verbatim
<style>
.kbp{position:fixed;z-index:9000;top:12px;left:50%;transform:translateX(-50%);width:min(640px,calc(100vw - 24px));box-sizing:border-box;display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:12px 14px;border-radius:14px;background:var(--surface,#fff);color:var(--ink,#1d1b1c);border:1px solid var(--border,#e6e1e3);box-shadow:0 10px 30px rgba(20,10,15,.14);font-size:13.5px;line-height:1.45}
.kbp[hidden]{display:none}
.kbp .kbp-t{flex:1 1 260px;min-width:0;overflow-wrap:anywhere}
.kbp .kbp-t b{font-weight:700}
.kbp .kbp-d{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px;vertical-align:1px;background:#e0922f}
.kbp.bad{background:#fff4f2;border-color:#f3c9c2}
.kbp.bad .kbp-d{background:#d2493c}
.kbp.ok .kbp-d{background:#15a85a}
.kbp .kbp-a{display:flex;gap:8px;flex-wrap:wrap}
.kbp button{font:inherit;font-size:12.5px;font-weight:600;padding:7px 12px;border-radius:99px;border:1px solid var(--border,#e6e1e3);background:var(--surface,#fff);color:var(--ink,#1d1b1c);cursor:pointer}
.kbp button.pri{background:var(--ink,#1d1b1c);border-color:var(--ink,#1d1b1c);color:#fff}
.kbp button:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.kbp.mini{width:auto;max-width:calc(100vw - 24px);padding:7px 12px;font-size:12.5px}
.kbp.mini .kbp-t{flex:0 1 auto}
@media (prefers-reduced-motion:no-preference){.kbp{transition:opacity .15s}}
</style>
<div class="kbp" id="kbpBar" role="status" aria-live="polite" hidden></div>
<script>
(function () {
  'use strict';
  if (window.kbbPresence || typeof window.fetch !== 'function') return;

  var EDIT_MS = 15000, IDLE_MS = 30000;
  var OPEN = [
    [/\/admin-api\/product-editor-load\/(\d+)$/, 'product'], [/\/admin-api\/products\/(\d+)$/, 'product'],
    [/\/admin-api\/page-editor-load\/(\d+)$/, 'page'], [/\/admin-api\/post-editor-load\/(\d+)$/, 'post'],
    [/\/admin-api\/coupons\/manage\/(\d+)$/, 'coupon'], [/\/admin-api\/orders\/(\d+)\/detail$/, 'order'],
    [/\/admin-api\/emails\/templates\/([a-z0-9_]{1,40})$/, 'email_template'],
    [/\/admin-api\/email-marketing\/campaigns\/(\d+)$/, 'campaign']
  ];
  var LIST = {
    product: /\/admin-api\/product-editor-list$/, page: /\/admin-api\/page-editor-list$/, post: /\/admin-api\/posts$/,
    coupon: /\/admin-api\/coupons\/manage$/, order: /\/admin-api\/orders$/, campaign: /\/admin-api\/email-marketing\/campaigns$/
  };
  var WORD = { product: 'product', category: 'category', brand: 'brand', page: 'page', post: 'article', coupon: 'coupon', order: 'order', email_template: 'email template', campaign: 'campaign' };

  var S = { type: null, id: null, token: null, freed: null, holding: false, displaced: false, blocked: false, mini: false, stopped: false, timer: 0, busy: false, last: null };
  var rawFetch = window.fetch.bind(window);

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/presence/'; }
  function path(u) { try { return new URL(String(u), window.location.href).pathname.replace(/\/+$/, ''); } catch (e) { return ''; } }
  function ago(s) { s = +s || 0; return s < 60 ? 'just now' : s < 3600 ? Math.floor(s / 60) + ' min' : Math.floor(s / 3600) + ' h'; }
  function visible() { return document.visibilityState !== 'hidden'; }

  function post(what, body, keep) {
    return rawFetch(base() + what, {
      method: 'POST', credentials: 'same-origin', keepalive: !!keep,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      body: JSON.stringify(body)
    });
  }

  /* ------------------------------------------------------------ the beat */

  function schedule() {
    clearTimeout(S.timer); S.timer = 0;
    if (!S.stopped && visible()) S.timer = setTimeout(beat, S.type ? EDIT_MS : IDLE_MS);
  }

  async function beat() {
    clearTimeout(S.timer); S.timer = 0;
    if (S.stopped || S.busy || !visible()) return;
    S.busy = true;
    var want = S.type, wid = S.id, r = null, d = null;
    try { r = await post('beat', want ? { type: want, id: wid, token: S.token } : {}); d = await r.json(); } catch (e) { d = null; }
    S.busy = false;
    if (r && (r.status === 401 || r.status === 403 || r.status === 419 || r.status === 503)) { S.stopped = true; paint(); return; }
    if (d && r && r.ok && want && want === S.type && wid === S.id) adopt(d);
    schedule();
  }

  function adopt(d) {
    if (d.token) S.token = d.token;
    S.last = d;
    if (d.taken_over_by) { S.displaced = true; S.holding = false; }
    else if (d.you_hold) { if (S.blocked && !S.holding) S.freed = d; S.holding = true; S.blocked = false; }
    else { S.holding = false; S.blocked = !!d.holder; }
    paint();
  }

  /* ---------------------------------------------------------- the banner */

  function bar() { return document.getElementById('kbpBar'); }

  function paint() {
    var b = bar(); if (!b) return;
    var d = S.last || {}, what = WORD[S.type] || 'record', html = '', cls = 'kbp';
    if (S.type && S.displaced) {
      cls += ' bad' + (S.mini ? ' mini' : '');
      html = S.mini ? '<span class="kbp-t"><span class="kbp-d"></span>View only — <b>' + esc(d.taken_over_by) + '</b> has this ' + what + '</span><span class="kbp-a"><button type="button" data-kbp="reload">Reload</button></span>'
        : '<span class="kbp-t"><span class="kbp-d"></span><b>' + esc(d.taken_over_by) + '</b> took over editing. You are now view-only — copy your unsaved changes, then reload to see theirs.</span>' +
          '<span class="kbp-a"><button type="button" data-kbp="mini">Got it</button><button type="button" class="pri" data-kbp="reload">Reload</button></span>';
    } else if (S.type && S.blocked) {
      cls += S.mini ? ' mini' : '';
      html = S.mini ? '<span class="kbp-t"><span class="kbp-d"></span>View only — <b>' + esc(d.holder) + '</b> is editing</span>' + (d.can_take ? '<span class="kbp-a"><button type="button" data-kbp="take">Take over</button></span>' : '')
        : '<span class="kbp-t"><span class="kbp-d"></span><b>' + esc(d.holder) + '</b> is editing this ' + what + ' · since ' + ago(d.since) + '</span>' +
          '<span class="kbp-a"><button type="button" data-kbp="mini">View only</button>' + (d.can_take ? '<button type="button" class="pri" data-kbp="take">Take over</button>' : '') + '</span>';
    } else if (S.type && S.freed) {
      cls += ' ok';
      html = '<span class="kbp-t"><span class="kbp-d"></span>' + (S.freed.took ? 'You have control of this ' + what + '. Reload to start from the last saved version.' : 'The other editor has left. Reload before you change anything, so you start from their last save.') + '</span>' +
        '<span class="kbp-a"><button type="button" data-kbp="dismiss">Dismiss</button><button type="button" class="pri" data-kbp="reload">Reload</button></span>';
    }
    b.className = cls;
    b.innerHTML = html;
    b.hidden = html === '';
  }

  async function take() {
    if (!S.type) return;
    var r = null, d = null;
    try { r = await post('take', { type: S.type, id: S.id }); d = await r.json(); } catch (e) { d = null; }
    if (r && r.ok && d) { adopt(d); if (d.you_hold) { S.freed = { took: true }; paint(); } }
    else { S.last = Object.assign({}, S.last, { can_take: false }); paint(); }
    schedule();
  }

  /* ------------------------------------------------- open, close, leave */

  function open(type, id) {
    id = String(id == null ? '' : id);
    if (!WORD[type] || !/^[a-z0-9_]{1,64}$/.test(id)) return;
    if (S.type === type && S.id === id) return;
    close();
    S.type = type; S.id = id; S.mini = false;
    beat();
  }

  function close(type) {
    if (!S.type || (type && type !== S.type)) return;
    if (S.holding && S.token) { try { post('release', { type: S.type, id: S.id, token: S.token }, true); } catch (e) {} }
    S.type = S.id = S.token = S.last = S.freed = null;
    S.holding = S.displaced = S.blocked = S.mini = false;
    paint(); schedule();
  }

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest && ev.target.closest('[data-kbp]'); if (!a) return;
    var k = a.getAttribute('data-kbp');
    if (k === 'take') take();
    else if (k === 'reload') window.location.reload();
    else if (k === 'mini') { S.mini = true; paint(); }
    else if (k === 'dismiss') { S.freed = null; paint(); }
  });

  document.addEventListener('visibilitychange', function () { if (visible()) beat(); else { clearTimeout(S.timer); S.timer = 0; } });
  window.addEventListener('pagehide', function () {
    clearTimeout(S.timer);
    if (S.holding && S.token) { try { post('release', { type: S.type, id: S.id, token: S.token }, true); } catch (e) {} }
  });

  /* ------------------------------------------------- the one fetch hook */

  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    if (url.indexOf('admin-api/') === -1 || url.indexOf('/admin-api/presence/') !== -1) return rawFetch(input, init);
    var p = path(url);

    if (method !== 'GET' && S.token && typeof input === 'string') {
      init = Object.assign({}, init);
      var h = init.headers instanceof Headers ? init.headers : new Headers(init.headers || {});
      h.set('X-KBB-Edit-Lock', S.token);
      init.headers = h;
    }

    var res = rawFetch(input, init);
    res.then(function (r) {
      if (method === 'GET' && r.ok) {
        for (var i = 0; i < OPEN.length; i++) { var m = p.match(OPEN[i][0]); if (m) { open(OPEN[i][1], m[1]); return; } }
        if (S.type && LIST[S.type] && LIST[S.type].test(p)) close(S.type);
      } else if (r.status === 409 && S.type) beat();
    }, function () {});
    return res;
  };

  /* go() is every screen change; a record open on the last screen is not open now. */
  if (typeof window.go === 'function') {
    var prevGo = window.go;
    window.go = function () { close(); return prevGo.apply(this, arguments); };
  }

  window.kbbPresence = { open: open, close: close, state: S, beat: beat };
  beat();   /* one at load, so "online now" is true from the first second */
})();
</script>
@endverbatim
