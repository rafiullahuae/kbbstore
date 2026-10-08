
(function(){
  'use strict';

  var PANEL = 'srs-panel';
  var status = null;     // GET /admin-api/payments/stripe/webhook
  var log = null;        // GET /admin-api/payments/stripe/log, on request
  var said = null;       // {ok, text} -- what the last button press reported
  var busy = false;

  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* Same expression as the console's fixAdminApiUrl(), which is module-scoped
     in app.blade.php. Callers pass full '/admin-api/...' literals so the
     controls-are-live guard can resolve each against the router. */
  function apiUrl(path){
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + path;
  }

  async function call(method, path){
    var opts = {method: method, credentials: 'same-origin', headers: {'Accept': 'application/json'}};
    if (method !== 'GET') {
      opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      opts.headers['Content-Type'] = 'application/json';
      opts.body = '{}';
    }
    try {
      var r = await fetch(apiUrl(path), opts);
      var body = null;
      try { body = await r.json(); } catch (e) { body = null; }
      return {ok: r.ok, status: r.status, body: body || {}};
    } catch (e) {
      return {ok: false, status: 0, body: {}};
    }
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function when(iso){
    if (!iso) return '';
    try { return new Date(iso).toLocaleString(); } catch (e) { return String(iso); }
  }

  function explain(res, fallback){
    if (res.status === 404) return 'These Stripe endpoints are not in this server\'s route table yet. Clear the route cache (Platform → Cache) and reload.';
    if (res.status === 403) return 'Your role cannot use this. Ask the owner.';
    if (res.body && typeof res.body.error === 'string' && res.body.error !== '') return res.body.error;
    return fallback;
  }

  function line(entry, empty){
    if (!entry) return '<span class="pill grey">' + esc(empty) + '</span>';
    var bits = [when(entry.at)];
    if (entry.event_type) bits.push(entry.event_type);
    if (entry.mode) bits.push(entry.mode + ' mode');
    return '<b>' + esc(bits.join(' · ')) + '</b><br>' + esc(entry.message || '') +
      (entry.outcome ? ' <span class="pill grey">' + esc(entry.outcome) + '</span>' : '') +
      (entry.reason ? ' — ' + esc(entry.reason) : '');
  }

  /* ----------------------------------------------------------- drawing */
  function draw(){
    var host = document.getElementById(PANEL);
    if (!host) return;

    if (!status) {
      host.innerHTML = '<div class="srs-box"><p class="srs-help" style="margin:0">' +
        esc(said ? said.text : 'Loading the Stripe status…') + '</p></div>';
      return;
    }

    var s = status, w = s.webhook || {}, st = s.statement || {}, k = s.keys || {};
    var test = !!s.test_mode;

    var html = '<div class="srs-mode ' + (test ? 'is-test' : 'is-live') + '" id="srs-mode">' +
      '<b>' + (test ? 'TEST MODE' : 'LIVE') + '</b>' +
      '<span>' + esc(test
        ? 'Payments use your TEST keys. Only Stripe test cards work and no real money moves. Shoppers see nothing different.'
        : 'Payments use your LIVE keys. Real cards are charged.') + '</span>' +
      '<span class="pill ' + (k.test_set ? 'green' : 'grey') + '">Test keys ' + (k.test_set ? 'saved' : 'not saved') + '</span>' +
      '<span class="pill ' + (k.live_set ? 'green' : 'grey') + '">Live keys ' + (k.live_set ? 'saved' : 'not saved') + '</span>' +
      '</div>';

    (s.warnings || []).forEach(function(text){ html += '<p class="srs-warn">' + esc(text) + '</p>'; });

    /* Lane ST: why the last card payment did not open, until one opens again.
       The message is written in plain words by StripeGateway::failureReason(). */
    if (s.last_failure) {
      var f = s.last_failure;
      html += '<p class="srs-warn" id="srs-last-failure"><b>Last card payment that could not start</b> (' + esc(when(f.at)) +
        (f.order ? ', order ' + esc(f.order) : '') + '): ' + esc(f.message) + '</p>';
    }

    html += '<div class="srs-box"><h4>What customers and Stripe will see</h4><dl class="srs-dl">' +
      '<dt>Card statement (example, order 10234)</dt><dd><code>' + esc(st.card_example || '') + '</code></dd>' +
      '<dt>Payment description in Stripe</dt><dd><code>' + esc(s.description_example || '') + '</code></dd>' +
      '<dt>Full statement descriptor</dt><dd><code>' + esc(st.full || '—') + '</code>' + (st.full_is_default ? ' <span class="pill grey">from your shop name</span>' : '') + '</dd>' +
      '<dt>Your Stripe account says</dt><dd>' + (st.account_descriptor
        ? '<code>' + esc(st.account_descriptor) + '</code>' + (st.account_prefix ? ' · prefix <code>' + esc(st.account_prefix) + '</code>' : '')
        : '<span class="pill grey">not read yet — press Set up webhook automatically</span>') + '</dd>' +
      '<dt>Capture</dt><dd>' + (s.capture_later ? 'Authorise only — you capture each order' : 'Taken at checkout') + '</dd>' +
      '<dt>Stripe email receipts</dt><dd>' + (s.receipt_email ? 'On' : 'Off') + '</dd>' +
      '</dl>' +
      '<p class="srs-help">Card statements always start with your Stripe account\'s own name; Stripe does not let a shop replace it per payment. Change it in Stripe → Settings → Business → Public details.</p></div>';

    html += '<div class="srs-box"><h4>Webhook — how Stripe tells this shop a payment went through</h4><dl class="srs-dl">' +
      '<dt>Address</dt><dd>' + (w.url ? '<code>' + esc(w.url) + '</code>' : '<span class="pill amber">not generated — save this tab once</span>') + '</dd>' +
      '<dt>Endpoint at Stripe (' + esc(s.mode) + ')</dt><dd>' + (w.endpoint_id
        ? '<code>' + esc(w.endpoint_id) + '</code>' + (k.has_signing_secret ? ' <span class="pill green">signing secret stored</span>' : ' <span class="pill amber">no signing secret</span>')
        : '<span class="pill amber">not set up in ' + esc(s.mode) + ' mode</span>') + '</dd>' +
      '<dt>Events</dt><dd>' + esc((w.events || []).join(', ')) + '</dd>' +
      '<dt>Last event received</dt><dd id="srs-last-event">' + line(w.last_event, 'none yet') + '</dd>' +
      '<dt>Last signature failure</dt><dd id="srs-last-fail">' + line(w.last_signature_failure, 'none') + '</dd>' +
      '<dt>Last setup</dt><dd>' + line(w.last_setup, 'never from this screen') + '</dd>' +
      '</dl>' +
      '<div class="srs-row">' +
      '<button type="button" class="btn" id="srs-setup"' + (busy ? ' disabled' : '') + '>Set up webhook automatically</button>' +
      '<button type="button" class="btn ghost" id="srs-refresh"' + (busy ? ' disabled' : '') + '>Refresh</button>' +
      '</div>' +
      (said ? '<p class="srs-msg ' + (said.ok ? 'is-good' : 'is-bad') + '" id="srs-said">' + esc(said.text) + '</p>' : '') +
      '<div class="srs-help">To see an event arrive: <ol>' +
      '<li>Keep Mode on Sandbox / test, press <b>Set up webhook automatically</b>.</li>' +
      '<li>Place an order on the shop with the test card 4242 4242 4242 4242, any future date, any CVC.</li>' +
      '<li>Press <b>Refresh</b>: Last event received shows <code>payment_intent.succeeded</code>. In Stripe: Developers → Webhooks → this endpoint → the delivery and its 200 response.</li>' +
      '</ol></div></div>';

    html += '<div class="srs-box"><h4>Payment log</h4>' +
      '<p class="srs-help" style="margin-top:0">Gateway events, Stripe errors and webhook outcomes, newest first (the last 500 are kept). Never card numbers, keys or secrets.</p>' +
      '<div class="srs-row"><button type="button" class="btn ghost" id="srs-log-btn">' + (log ? 'Reload the log' : 'Show the payment log') + '</button></div>';

    if (log) {
      var rows = log.rows || [];
      html += rows.length
        ? '<div class="srs-logwrap"><table class="srs-log" id="srs-log"><colgroup><col style="width:24%"><col style="width:22%"><col><col class="srs-c-ctx" style="width:30%"></colgroup>' +
          '<thead><tr><th>When</th><th>Event</th><th>What happened</th><th class="srs-c-ctx">Details</th></tr></thead><tbody>' +
          rows.map(function(r){
            var ctx = r.context || {};
            var details = Object.keys(ctx).map(function(key){ return key + ': ' + ctx[key]; }).join(' · ');
            return '<tr class="' + (r.level === 'error' ? 'is-error' : '') + '"><td>' + esc(when(r.at)) + (r.mode ? '<br>' + esc(r.mode) : '') + '</td>' +
              '<td>' + esc(r.event) + '</td><td>' + esc(r.message) + '</td><td class="srs-c-ctx">' + esc(details) + '</td></tr>';
          }).join('') + '</tbody></table></div>'
        : '<p class="srs-help">Nothing logged yet.</p>';
    }

    html += '</div>';
    host.innerHTML = html;

    var setup = document.getElementById('srs-setup');
    if (setup) setup.onclick = setupWebhook;
    var refresh = document.getElementById('srs-refresh');
    if (refresh) refresh.onclick = function(){ said = null; load(); };
    var logBtn = document.getElementById('srs-log-btn');
    if (logBtn) logBtn.onclick = loadLog;
  }

  /* ----------------------------------------------------------- actions */
  async function load(){
    var res = await call('GET', '/admin-api/payments/stripe/webhook');
    if (res.ok) { status = res.body; } else { said = {ok: false, text: explain(res, 'The Stripe status could not be read.')}; }
    draw();
  }

  async function setupWebhook(){
    if (busy) return;
    busy = true; said = {ok: true, text: 'Talking to Stripe…'}; draw();
    var res = await call('POST', '/admin-api/payments/stripe/webhook/setup');
    busy = false;
    if (res.ok && res.body.ok) {
      status = res.body.status || status;
      var verb = {created: 'created', replaced: 'replaced', reused: 'already in place', reused_events_updated: 'updated with the missing events'}[res.body.action] || 'set up';
      said = {ok: true, text: 'Webhook ' + verb + ' at Stripe (' + res.body.mode + ' mode), signing secret stored.' +
        (res.body.stale_removed ? ' Removed ' + res.body.stale_removed + ' old endpoint(s) pointing at this shop\'s previous address.' : '')};
    } else {
      said = {ok: false, text: explain(res, 'Stripe refused. Nothing was changed.')};
    }
    draw();
  }

  async function loadLog(){
    var res = await call('GET', '/admin-api/payments/stripe/log');
    if (res.ok) { log = res.body; } else { said = {ok: false, text: explain(res, 'The payment log could not be read.')}; }
    draw();
  }

  /* ------------------------------------------- attaching to the Stripe card */

  /* The Mode row's help line is PAY_MODE_HELP in app.blade.php, whose script
     block ships as a built file (AdminConsoleAssets) -- editing it there means
     a console rebuild. Until that rebuild, the sentence is corrected here: with
     two key sets, Mode is no longer "a label for your own records". */
  var MODE_HELP = 'Picks which key set the shop uses: Sandbox / test uses the Test keys, Live uses the Live keys. Switching back and forth keeps both sets.';

  function fixModeHelp(){
    var select = document.getElementById('pay_mode_stripe');
    var row = select && select.closest('.ecopt');
    var help = row && row.querySelector('.echelp');
    if (help && help.textContent !== MODE_HELP) help.textContent = MODE_HELP;
  }

  function inject(){
    fixModeHelp();
    var card = document.querySelector('[data-paycard="stripe"] .mmbody');
    if (!card || document.getElementById(PANEL)) return;

    var host = document.createElement('div');
    host.id = PANEL;
    host.className = 'srs';

    var lede = card.querySelector('.paylede');
    if (lede && lede.nextSibling) card.insertBefore(host, lede.nextSibling); else card.insertBefore(host, card.firstChild);

    // A repaint after a save is a new card: read the saved state again.
    status = null;
    draw();
    load();
  }

  var pending = false;

  function watch(){
    var root = document.querySelector('#content');
    if (!root || typeof window.MutationObserver !== 'function') return;

    var schedule = function(){
      if (pending) return;
      pending = true;
      var run = function(){ pending = false; inject(); };
      if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(run);
      else window.setTimeout(run, 16);
    };

    new window.MutationObserver(schedule).observe(root, {childList: true, subtree: true});
    inject();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch);
  else watch();
})();
