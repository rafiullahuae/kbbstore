{{--
    Store -> Gateway webhooks. (Lane TM.)

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    Tamara is a live BNPL gateway on this shop and its entire admin API was
    unreachable from the console. Five endpoints, a 351-line controller, 2,200
    lines of tests, five capability rules and a clear_caches migration shipped,
    and NOT ONE LINE OF THE ADMIN CALLED ANY OF THEM:

        grep -rni "tamara" resources/views/admin/ resources/js/ \
          | grep -E "fetch|api\(|admin-api"      ->  0 matches

    The endpoints were proved by a Playwright harness that POSTs to them
    directly over HTTP (tools/pg1-tamara-shots/shots.mjs), which is why the
    absence never showed up in a screenshot. This is the same shape as
    routes/checkout-card.php: built, tested, photographed, unreachable.

    WHAT IT COST. routes/payments-tamara.php's own header says the webhook is
    how Tamara tells this shop about a DECLINE, and that without it a decline
    "is invisible until somebody audits `pending` orders". Tamara's merchant
    portal has no webhook screen, so until this screen existed the registration
    could not be done at all from anywhere a person without a shell could
    reach. `sweep` had a console command; registerWebhook, unregisterWebhook,
    refreshLimits and show had no command, no button and no caller of any kind.

    Tabby is the same shape one gateway over: GET and POST
    /admin-api/payments/tabby/webhooks are live, capability-mapped and called
    by nothing. Its card is on this screen for that reason and no other -- the
    two gateways answer the same question ("is my webhook registered, and if
    not, register it") and a second screen for the second answer is a screen
    nobody finds.

    ── WHY A SCREEN OF ITS OWN RATHER THAN A PANEL ON Store -> Payments ─────

    resources/views/admin/app.blade.php is the integrator's file and CLAUDE.md
    forbids a lane editing it -- three lanes are in there at once. Every new
    screen in this console is therefore a partial that appends its own sidebar
    row through window.kbbAddNavEntry and WRAPS window.go, exactly as
    cache-screen, security-screen and the four Appearance screens do. The one
    line the integrator adds is the include; nothing else in that file changes.

    The screen is still reachable from where the owner will look for it: this
    file also drops ONE button into the Tamara card on Store -> Payments, from
    the outside, through a MutationObserver on #content. It is additive and
    idempotent -- it adds nothing if its own button is already there, and if it
    never fires the payments screen is exactly what it is today.

    ── WHAT THIS SCREEN NEVER SHOWS ─────────────────────────────────────────

    Nothing this screen draws is a credential, because it is handed none.
    TamaraAdminController returns the webhook id (opaque, useless without the
    API token) and the two basket limits, and deliberately NOT the webhook URL,
    which embeds the webhook secret. TabbyWebhookController returns
    `webhook_url_ready` as a boolean for the same reason. Both allowlist their
    own payloads; this screen prints what arrives and prints all of it escaped.

    ── MONEY ────────────────────────────────────────────────────────────────

    The two limits arrive as the major-unit strings the settings hold ("100.00")
    and are printed as strings. Nothing on this screen parses one into a number,
    and nothing on this screen posts an amount, an order id or a merchant id --
    the sweep names no order and takes three bounded integers, and the limit
    refresh takes a market chosen from a fixed list of six that the gateway
    allowlists again on arrival.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    The owner reviews on a phone. Every grid and flex child that can hold
    something long carries min-width:0, because a grid item's default min-width
    is `auto` and that exact defect shipped on the Coupons screen. The admin
    sets body{overflow:hidden} and scrolls inside #content, so #content's own
    scrollWidth is the number that matters here as well as the document's.

    No element-measuring API is called anywhere below: the screen sizes with
    CSS grid and clamp(), which two tests in this suite forbid by name.

    EVERY CLASS IS PREFIXED tmw- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and
    this file claims no bare data- attribute: app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name -- [data-open], [data-tg], [data-pp] -- and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
.tmw-wrap{display:grid;gap:14px;padding:16px;max-width:1080px;margin:0 auto;min-width:0}
.tmw-head h2{margin:0 0 4px;font-size:clamp(17px,2.4vw,21px);line-height:1.25}
.tmw-head p{margin:0;color:var(--ink-soft,#6b7280);font-size:13px;line-height:1.55;max-width:74ch}
.tmw-card{border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:16px;background:var(--card,#fff);min-width:0}
.tmw-card > h3{margin:0 0 3px;font-size:15px;line-height:1.3}
.tmw-card > .tmw-sub{margin:0 0 13px;color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55}
.tmw-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:0 0 13px;min-width:0}
.tmw-fact{border:1px solid var(--border,#e6e6e6);border-radius:11px;padding:10px 12px;min-width:0}
.tmw-fact dt{margin:0 0 4px;font-size:11px;font-weight:650;letter-spacing:.04em;
             text-transform:uppercase;color:var(--ink-soft,#6b7280)}
.tmw-fact dd{margin:0;font-size:13.5px;font-weight:600;line-height:1.45;overflow-wrap:anywhere}
.tmw-fact dd small{display:block;margin-top:3px;font-weight:500;font-size:11.5px;
                   color:var(--ink-soft,#6b7280);overflow-wrap:anywhere}
.tmw-yes{color:#15803d}
.tmw-no{color:#b45309}
.tmw-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:11px;min-width:0}
.tmw-row > *{min-width:0}
.tmw-num{width:88px;padding:7px 9px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);
         border-radius:9px;background:transparent;color:inherit}
.tmw-sel{padding:7px 9px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);
         border-radius:9px;background:transparent;color:inherit;max-width:100%}
.tmw-lab{font-size:11.5px;font-weight:650;letter-spacing:.03em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280)}
.tmw-said{margin-top:12px;border:1px solid var(--border,#e6e6e6);border-left-width:3px;border-radius:10px;
          padding:11px 12px;font-size:13px;line-height:1.55;min-width:0;overflow-wrap:anywhere}
.tmw-said.is-good{border-left-color:#15803d}
.tmw-said.is-bad{border-left-color:#b91c1c}
.tmw-said.is-plain{border-left-color:var(--border,#e6e6e6)}
.tmw-said b{display:block;margin-bottom:3px}
.tmw-danger{margin-top:12px;border:1px solid #f0c9a0;border-radius:10px;padding:12px;background:rgba(180,83,9,.06);
            font-size:13px;line-height:1.55;min-width:0}
.tmw-danger p{margin:0 0 10px}
.tmw-list{margin:11px 0 0;padding:0;list-style:none;display:grid;gap:7px;min-width:0}
.tmw-list li{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:9px 11px;font-size:12.5px;
             line-height:1.5;min-width:0;overflow-wrap:anywhere}
.tmw-list li b{font-weight:650}
.tmw-note{margin:12px 0 0;color:var(--ink-soft,#6b7280);font-size:12px;line-height:1.6;max-width:74ch}
.tmw-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.tmw-banner{border:1px solid #f0c9a0;border-radius:11px;padding:12px 13px;font-size:13px;line-height:1.55;
            background:rgba(180,83,9,.06);min-width:0}
@media (max-width:640px){
  .tmw-wrap{padding:13px;gap:12px}
  .tmw-card{padding:13px}
  .tmw-num{width:76px}
  .tmw-row .btn{flex:1 1 auto}
}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'paygw';

  /* ------------------------------------------------------------------ state
     Everything drawn below came out of a response a moment ago. Nothing here
     is derived, remembered across a reload, or optimistically patched after a
     click: each action's response carries the gateway's own state and that is
     what is redrawn, so the screen can never claim a registration the server
     does not have. */
  var tamara = null;      // GET  /admin-api/payments/tamara
  var tabby = null;       // GET  /admin-api/payments/tabby/webhooks
  var said = null;        // {tone, title, text} — the last thing an action said
  var sweepRows = null;   // the sweep's per-order lines, or null
  var tabbyRows = null;   // Tabby's per-country lines, or null
  var confirming = false; // the unregister confirmation strip is showing
  var busy = false;
  var banner = null;
  var seq = 0;

  /* --------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* The console's own fixAdminApiUrl() is module-scoped in app.blade.php and
     not on window, so this repeats its one expression. Every caller below
     passes a FULL '/admin-api/...' literal rather than a suffix, which is what
     puts these five paths inside AdminConsoleControlsAreLiveTest's net: that
     guard reads '/admin-api/...' literals out of the file and resolves each
     against the real router. A path assembled from a base and a suffix is
     invisible to it. */
  function apiUrl(path){
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + path;
  }

  /*
   * A 4xx here is an ANSWER, not a transport failure.
   *
   * TamaraAdminController returns 422 with the operator's own sentence for the
   * two things only the owner can fix ("Tamara has no API token stored yet",
   * "this gateway has no webhook secret yet") and 502 with a different one for
   * Tamara's end. app.blade.php's api() throws away the body and leaves the
   * caller with a status code, which on this screen would turn four distinct,
   * actionable messages into one "request failed". So this resolves with the
   * status and the parsed body and the caller decides.
   */
  async function call(method, path, body){
    var opts = {method: method, credentials: 'same-origin',
                headers: {'Accept': 'application/json'}};

    if (method !== 'GET') {
      opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body || {});
    }

    var r = await fetch(apiUrl(path), opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }

    return {status: r.status, ok: r.ok, body: payload || {}};
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  /* A 404 on these paths almost always means the package shipped without its
     clear_caches migration having run, so the compiled route table on the host
     does not know them. Said plainly, because on THIS screen an empty panel
     would read as "no webhook is registered" -- the exact wrong conclusion. */
  function notRouted(res){
    return res.status === 404 && !(res.body && res.body.error === 'unsupported');
  }

  function explain(res, fallback){
    if (notRouted(res)) {
      return 'These endpoints are not in this server\'s compiled route table yet. '
           + 'Clear the route cache (Platform → Cache, or php artisan route:clear) and reload.';
    }
    if (res.body && res.body.error === 'unsupported') {
      return 'This build does not ship that gateway, so there is nothing to register.';
    }
    if (res.body && typeof res.body.message === 'string' && res.body.message !== '') return res.body.message;
    if (res.body && typeof res.body.error === 'string' && res.body.error !== '') return res.body.error;
    return fallback;
  }

  function yesNo(flag, yes, no){
    return '<span class="' + (flag ? 'tmw-yes' : 'tmw-no') + '">' + esc(flag ? yes : no) + '</span>';
  }

  /* ---------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Gateway webhooks',
      icon:   '<path d="M12 3a4 4 0 0 1 3.4 6.1l2.8 4.6"/><path d="M8.2 20a4 4 0 0 1-1.4-7.1L9.6 8"/><path d="M18 20a4 4 0 0 0 1-7.9H13"/>',
      group:  'Store',
      after:  ['payments', 'orders']
    });
    watchPaymentsScreen();
  }

  /* ------------------------------------------------------------------ route */
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
    if (title) title.textContent = 'Gateway webhooks';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    said = null;
    sweepRows = null;
    tabbyRows = null;
    confirming = false;

    render();
    load();
    return undefined;
  };

  /* ------------------------------------------------------------------- data */
  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var a = await call('GET', '/admin-api/payments/tamara');
      if (mine !== seq) return;
      tamara = a.ok ? a.body : null;
      banner = a.ok ? null : explain(a, 'Tamara’s settings could not be read.');

      var b = await call('GET', '/admin-api/payments/tabby/webhooks');
      if (mine !== seq) return;
      tabby = b.ok ? b.body : null;
    } catch (e) {
      if (mine !== seq) return;
      banner = 'The gateway endpoints could not be reached at all.';
      tamara = null;
      tabby = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  /* ------------------------------------------------------------------ views */
  function tamaraFacts(){
    var t = tamara || {};
    var events = (t.webhook_events || []).join(', ');

    return '<div class="tmw-facts">'
      + '<div class="tmw-fact"><dt>Keys stored</dt><dd>'
        + yesNo(!!t.configured, 'Yes', 'Not yet')
        + (t.configured ? '' : '<small>Paste the API token and notification token on Store → Payments → Tamara and save, then come back.</small>')
      + '</dd></div>'
      + '<div class="tmw-fact"><dt>Webhook</dt><dd>'
        + yesNo(!!t.webhook_registered, 'Registered', 'Not registered')
        + (t.webhook_registered
            ? '<small>Tamara’s id for it: ' + esc(t.webhook_id || '—') + '</small>'
            : '<small>Declines and expiries are not reaching this shop. A refused order stays <b>pending</b> until somebody looks.</small>')
      + '</dd></div>'
      + '<div class="tmw-fact"><dt>Events it sends</dt><dd>' + esc(events || '—') + '</dd></div>'
      + '<div class="tmw-fact"><dt>Basket limits</dt><dd>'
        + esc((t.min_limit || '—') + ' – ' + (t.max_limit || '—'))
        + '<small>' + esc(t.min_limit && t.max_limit
            ? 'Tamara is offered only between these two totals.'
            : 'Not pulled yet, so no limit is applied and a basket Tamara will refuse can still pick it.') + '</small>'
      + '</dd></div>'
      + '</div>';
  }

  function tamaraCard(){
    var t = tamara || {};
    var registered = !!t.webhook_registered;

    var html = '<section class="tmw-card"><h3>Tamara</h3>'
      + '<p class="tmw-sub">Tamara delivers <b>order_declined</b> and <b>order_expired</b> only to an endpoint '
      + 'registered through its own API, and its merchant portal has no screen for it. This is the only place it can be done.</p>'
      + tamaraFacts();

    html += '<div class="tmw-row">'
      + '<button type="button" class="btn" id="tm-register">'
      + (registered ? 'Re-check registration' : 'Register the webhook') + '</button>'
      + (registered ? '<button type="button" class="btn ghost" id="tm-unregister">Remove the registration…</button>' : '')
      + '</div>';

    if (confirming) {
      html += '<div class="tmw-danger">'
        + '<p><b>Remove it?</b> Tamara stops telling this shop when an order is declined or expires. '
        + 'Those orders will sit at <b>pending</b>, holding their stock and their coupon use, until somebody audits them by hand. '
        + 'Nothing else about Tamara changes and shoppers can still pay with it.</p>'
        + '<div class="tmw-row" style="margin-top:0">'
        + '<button type="button" class="btn" id="tm-unreg-yes">Yes, remove it</button>'
        + '<button type="button" class="btn ghost" id="tm-unreg-no">Keep it registered</button>'
        + '</div></div>';
    }

    html += '<div class="tmw-row">'
      + '<span class="tmw-lab">Basket limits for</span>'
      + '<select class="tmw-sel" id="tm-market">'
      + '<option value="">This shop’s own market</option>'
      + '<option value="AE:AED">United Arab Emirates (AED)</option>'
      + '<option value="SA:SAR">Saudi Arabia (SAR)</option>'
      + '<option value="KW:KWD">Kuwait (KWD)</option>'
      + '<option value="BH:BHD">Bahrain (BHD)</option>'
      + '<option value="QA:QAR">Qatar (QAR)</option>'
      + '<option value="OM:OMR">Oman (OMR)</option>'
      + '</select>'
      + '<button type="button" class="btn ghost" id="tm-limits">Pull the limits from Tamara</button>'
      + '</div>';

    html += '<div class="tmw-row">'
      + '<span class="tmw-lab">Sweep</span>'
      + '<label class="tmw-lab" for="tm-minutes">skip newer than</label>'
      + '<input type="number" class="tmw-num" id="tm-minutes" min="0" max="1440" step="1" value="15"> '
      + '<label class="tmw-lab" for="tm-days">look back (days)</label>'
      + '<input type="number" class="tmw-num" id="tm-days" min="1" max="180" step="1" value="30"> '
      + '<label class="tmw-lab" for="tm-limit">at most</label>'
      + '<input type="number" class="tmw-num" id="tm-limit" min="1" max="500" step="1" value="50"> '
      + '<button type="button" class="btn ghost" id="tm-sweep">Run the sweep</button>'
      + '</div>'
      + '<p class="tmw-note">The sweep asks Tamara about this shop’s own <b>pending</b> Tamara orders and marks the '
      + 'approved ones paid. It names no order and carries no amount, so it cannot be pointed at one. Newly placed orders '
      + 'are skipped because the shopper may still be on Tamara’s page. The same thing runs from a shell as '
      + '<code>php artisan payments:tamara-sweep</code>.</p>';

    if (sweepRows && sweepRows.length) {
      html += '<ul class="tmw-list">';
      for (var i = 0; i < sweepRows.length; i++) {
        var row = sweepRows[i] || {};
        html += '<li><b>' + esc(row.order) + '</b> — ' + esc(row.outcome) + '. ' + esc(row.message) + '</li>';
      }
      html += '</ul>';
    }

    return html + '</section>';
  }

  function tabbyCard(){
    var t = tabby || {};

    var html = '<section class="tmw-card"><h3>Tabby</h3>'
      + '<p class="tmw-sub">The same question one gateway over. Tabby registers a webhook per market, and these two '
      + 'endpoints shipped with nothing in the console calling them either.</p>';

    if (!tabby) {
      html += '<div class="tmw-empty">Tabby’s webhook state could not be read.</div></section>';
      return html;
    }

    html += '<div class="tmw-facts">'
      + '<div class="tmw-fact"><dt>Webhook address</dt><dd>'
        + yesNo(!!t.webhook_url_ready, 'Ready', 'Not generated yet')
        + (t.webhook_url_ready ? '' : '<small>Save the Tabby settings once — that generates it.</small>')
      + '</dd></div>'
      + '<div class="tmw-fact"><dt>Registered</dt><dd>'
        + yesNo(!!t.registered_anywhere, 'In at least one market', 'Nowhere')
      + '</dd></div>'
      + '<div class="tmw-fact"><dt>Keys</dt><dd>'
        + esc(t.is_test ? 'Test keys' : 'Live keys')
        + (t.keys_disagree ? '<small class="tmw-no">The secret key and the public key are not from the same account.</small>' : '')
      + '</dd></div>'
      + '</div>';

    html += '<div class="tmw-row">'
      + '<button type="button" class="btn" id="tb-sync">Register / re-sync Tabby’s webhooks</button>'
      + '<button type="button" class="btn ghost" id="tb-check">Re-read the state</button>'
      + '</div>';

    var rows = tabbyRows || t.countries || [];

    if (rows.length) {
      html += '<ul class="tmw-list">';
      for (var i = 0; i < rows.length; i++) {
        var row = rows[i] || {};
        html += '<li><b>' + esc(row.country || '—') + '</b> — ' + esc(row.state || 'unknown')
             + (row.message ? '. ' + esc(row.message) : '')
             + (row.error ? ' (' + esc(row.error) + ')' : '')
             + '</li>';
      }
      html += '</ul>';
    }

    return html + '</section>';
  }

  function saidView(){
    if (!said) return '';

    return '<div class="tmw-said is-' + esc(said.tone) + '">'
      + '<b>' + esc(said.title) + '</b>' + esc(said.text) + '</div>';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    /* Only paint when this screen is the one on show. The console navigates
       before an async load finishes and a late response must not redraw
       somebody else's page. */
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="tmw-wrap">'
      + '<div class="tmw-head"><h2>Gateway webhooks</h2>'
      + '<p>The two things a payment provider has to be told that cannot be typed into a settings box: '
      + 'where to send its notifications, and — for Tamara — which basket totals it will accept. '
      + 'Nothing on this screen shows a key, a token or a webhook address.</p></div>';

    if (banner) html += '<div class="tmw-banner">' + esc(banner) + '</div>';

    if (busy && !tamara && !tabby) {
      html += '<div class="tmw-card"><div class="tmw-empty">Reading…</div></div>';
    } else {
      html += saidView();
      html += tamara ? tamaraCard() : '<div class="tmw-card"><div class="tmw-empty">This build does not ship the Tamara gateway.</div></div>';
      html += tabbyCard();
    }

    host.innerHTML = html + '</div>';
    bind();
  }

  /* ---------------------------------------------------------------- actions */

  /* One place that takes an action's response and turns it into the strip at
     the top plus a redraw. The state ALWAYS comes from `res.body.tamara` --
     the controller re-reads the gateway's credentials after the write and puts
     the truth in every response, success and failure alike -- so a failed
     register cannot leave the screen claiming one. */
  function settle(res, title, fallback){
    if (res.body && res.body.tamara) tamara = res.body.tamara;

    var ok = res.ok && res.body && res.body.ok !== false;

    said = {
      tone: ok ? 'good' : 'bad',
      title: title,
      text: ok ? String(res.body.message || 'Done.') : explain(res, fallback)
    };

    render();
  }

  function num(id, fallback){
    var el = document.getElementById(id);
    var v = el ? parseInt(el.value, 10) : NaN;

    return isFinite(v) ? v : fallback;
  }

  function bind(){
    var reg = document.getElementById('tm-register');
    if (reg) reg.onclick = async function(){
      reg.disabled = true;
      confirming = false;
      settle(await call('POST', '/admin-api/payments/tamara/webhook'),
             'Registering the webhook', 'Tamara refused the registration. Nothing was changed.');
    };

    var unreg = document.getElementById('tm-unregister');
    if (unreg) unreg.onclick = function(){ confirming = true; said = null; render(); };

    var no = document.getElementById('tm-unreg-no');
    if (no) no.onclick = function(){ confirming = false; render(); };

    var yes = document.getElementById('tm-unreg-yes');
    if (yes) yes.onclick = async function(){
      yes.disabled = true;
      confirming = false;
      settle(await call('DELETE', '/admin-api/payments/tamara/webhook'),
             'Removing the registration', 'Tamara refused. The registration is unchanged.');
    };

    var lim = document.getElementById('tm-limits');
    if (lim) lim.onclick = async function(){
      lim.disabled = true;

      /* A SELECT STORES ONE OF ITS OWN OPTIONS. The value is split on a colon
         and each half is checked against the six markets drawn above before it
         is sent; anything else is sent as "no market named", which makes the
         gateway use the shop's own currency. The controller then bounds both
         halves again (size 2 / size 3, alpha) and TamaraGateway allowlists the
         country a third time, so a country from a browser can never become
         part of a URL this shop calls. */
      var sel = document.getElementById('tm-market');
      var pair = String((sel && sel.value) || '').split(':');
      var markets = ['AE:AED','SA:SAR','KW:KWD','BH:BHD','QA:QAR','OM:OMR'];
      var body = markets.indexOf(String((sel && sel.value) || '')) === -1
        ? {}
        : {country: pair[0], currency: pair[1]};

      settle(await call('POST', '/admin-api/payments/tamara/limits', body),
             'Pulling the basket limits',
             'Tamara did not return limits for that market. The limits already stored are untouched.');
    };

    var sweep = document.getElementById('tm-sweep');
    if (sweep) sweep.onclick = async function(){
      sweep.disabled = true;
      sweepRows = null;

      var res = await call('POST', '/admin-api/payments/tamara/sweep', {
        minutes: num('tm-minutes', 15),
        days: num('tm-days', 30),
        limit: num('tm-limit', 50)
      });

      if (res.ok && res.body && res.body.report && res.body.report.orders) {
        sweepRows = res.body.report.orders;
      }

      settle(res, 'Sweeping for approvals that never arrived',
             'The sweep could not be run. No order was changed.');
    };

    var sync = document.getElementById('tb-sync');
    if (sync) sync.onclick = async function(){
      sync.disabled = true;
      await tabbyCall('POST', 'Registering Tabby’s webhooks');
    };

    var check = document.getElementById('tb-check');
    if (check) check.onclick = async function(){
      check.disabled = true;
      await tabbyCall('GET', 'Re-reading Tabby’s webhooks');
    };
  }

  async function tabbyCall(method, title){
    var res = await call(method, '/admin-api/payments/tabby/webhooks');

    if (res.ok && res.body) {
      tabby = res.body;
      tabbyRows = res.body.countries || null;
    }

    var ok = res.ok && res.body && res.body.ok !== false;

    said = {
      tone: ok ? 'good' : 'bad',
      title: title,
      text: ok
        ? (res.body.message || 'Tabby answered. The per-market state is below.')
        : explain(res, 'Tabby refused. Nothing was changed.')
    };

    render();
  }

  /* ------------------------------------- the one button on Store -> Payments

     The owner goes to Store -> Payments -> Tamara to paste his keys, and that
     is where he will look for this. That screen is drawn by app.blade.php,
     which this lane may not edit, so the button is added from the outside.

     A MutationObserver rather than a wrapper around window.go, because
     paySave() repaints the whole payments screen from the endpoint after every
     save -- a one-shot injection after navigation would survive until the
     owner's first save and then quietly disappear, which is a worse failure
     than never appearing. Adding it is idempotent (it looks for its own id
     first), so the observer firing on its own insertion does nothing, and if
     the observer never fires the payments screen is byte-for-byte what it is
     today.

     No layout is measured and nothing existing is moved or rewritten: one
     button is appended to the footer row of one card. */
  function injectPaymentsButton(){
    var foot = document.querySelector('[data-paycard="tamara"] .payfoot');
    if (!foot || document.getElementById('tm-jump')) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn ghost';
    btn.id = 'tm-jump';
    btn.textContent = 'Webhook & limits…';
    btn.onclick = function(){ try { window.go(SCREEN); } catch (e) {} };

    // Before "Save Tamara", which stays the rightmost thing on the row.
    var save = foot.querySelector('[data-paysave]');
    if (save) foot.insertBefore(btn, save); else foot.appendChild(btn);
  }

  /* COALESCED, because the observer is on #content with subtree:true and some
     screens mutate it in bursts -- the import progress bar rewrites a row every
     poll. One check per animation frame rather than one per mutation, and the
     check itself is two selector queries that stop at the first miss on every
     screen that is not Payments. Nothing is measured and nothing is laid out. */
  var pending = false;

  function watchPaymentsScreen(){
    var host = document.querySelector('#content');
    if (!host || typeof window.MutationObserver !== 'function') return;

    var schedule = function(){
      if (pending) return;
      pending = true;
      var run = function(){ pending = false; injectPaymentsButton(); };
      if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(run);
      else window.setTimeout(run, 16);
    };

    new window.MutationObserver(schedule).observe(host, {childList: true, subtree: true});

    injectPaymentsButton();
  }

  /* ------------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
