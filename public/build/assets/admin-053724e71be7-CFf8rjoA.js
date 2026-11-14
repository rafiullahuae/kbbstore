
(function () {
  'use strict';
  var SCREEN = 'quiqup';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ── shared helpers (window.kbbQQ) ─────────────────────────────────── */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function url(p) { return BASE.replace(/\/[^\/]*$/, '') + '/admin-api/' + p; }
  async function call(path, body) {
    var opts = { headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r, j = null;
    try { r = await fetch(url(path), opts); } catch (e) { return { status: 0, body: {} }; }
    try { j = await r.json(); } catch (e) { j = null; }
    return { status: r.status, body: j || {} };
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fail(status, body, what) {
    if (status === 404) return 'The Quiqup endpoints are not in the server\'s route table yet. Platform → Cache → Clear everything, then try again.';
    if (status === 403) return 'Your role cannot ' + (what || 'do this') + '.';
    if (status === 419) return 'Your session expired. Sign in again in another tab, then retry.';
    if (status === 0) return 'The shop did not answer. Check your connection and try again.';
    return (body && body.message) || 'Something went wrong (HTTP ' + status + '). Nothing was changed.';
  }
  /* A toast: title + exact text. Errors stay until closed; successes leave
     after 5 s. One setTimeout per toast, cleared on close: no timer outlives it. */
  function toast(kind, title, text) {
    var box = document.getElementById('qqToasts');
    if (!box) return;
    var t = document.createElement('div');
    t.className = 'qq-toast' + (kind === 'ok' ? '' : ' ' + kind);
    t.setAttribute('role', kind === 'bad' ? 'alert' : 'status');
    t.innerHTML = '<span aria-hidden="true">' + (kind === 'ok' ? '✓' : kind === 'warn' ? '!' : '✕') + '</span>' +
      '<div class="qq-tx"><b>' + esc(title) + '</b>' + (text ? esc(text) : '') + '</div><button type="button" aria-label="Dismiss">×</button>';
    var timer = null;
    var close = function () { if (timer) clearTimeout(timer); if (t.parentNode) t.parentNode.removeChild(t); };
    t.querySelector('button').onclick = close;
    box.appendChild(t);
    while (box.children.length > 4) box.removeChild(box.firstChild);
    if (kind === 'ok') timer = setTimeout(close, 5000);
  }
  /* A native <dialog>: focus is trapped and Esc closes, by the browser. */
  function dialog(html, buttons) {
    return new Promise(function (resolve) {
      var d = document.createElement('dialog');
      d.className = 'qq-dlg';
      d.innerHTML = '<div class="qq-dlg-b">' + html + '</div><div class="qq-dlg-f">' + buttons.map(function (b, i) {
        return '<button type="button" class="qq-btn ' + (b.cls || '') + '" data-i="' + i + '"' + (b.disabled ? ' disabled' : '') + '>' + esc(b.label) + '</button>';
      }).join('') + '</div>';
      document.body.appendChild(d);
      var done = function (v) { try { d.close(); } catch (e) {} if (d.parentNode) d.parentNode.removeChild(d); resolve(v); };
      d.addEventListener('cancel', function (e) { e.preventDefault(); done(null); });
      d.querySelectorAll('[data-i]').forEach(function (b) { b.onclick = function () { done(buttons[+b.getAttribute('data-i')].value); }; });
      if (d.showModal) d.showModal(); else d.setAttribute('open', '');
      var first = d.querySelector('.qq-dlg-f .qq-btn:not([disabled])');
      if (first) first.focus();
    });
  }
  window.kbbQQ = { call: call, esc: esc, fail: fail, toast: toast, dialog: dialog };

  /* ── Store → Quiqup ─────────────────────────────────────────────────── */
  var S;
  function reset() { S = { data: null, tab: 'conn', busy: '', act: null, test: {}, err: {} }; }
  reset();

  function host() { return document.querySelector('[data-qq-screen]'); }
  function ours() { var h = host(), c = document.getElementById('content'); return !!(h && c && c.contains(h)); }
  function when(iso) {
    if (!iso) return 'never';
    try { return new Date(iso).toLocaleString('en-GB', { timeZone: 'Asia/Dubai', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); } catch (e) { return iso; }
  }
  var MODE_TEXT = {
    off: 'Off. Nothing is booked with Quiqup; bookings already made keep updating.',
    testing: 'Internal testing. Runs on this website against Quiqup\'s STAGING system with your staging key. Only your test orders can be booked, and only they will show delivery steps. Every other shopper sees the shop exactly as before.',
    live: 'Live. Every order can be booked with Quiqup\'s production system, and customers see their delivery steps.'
  };

  function spin(on, label) { return on ? '<span class="qq-spin" aria-hidden="true"></span>' + label : label; }

  function hero() {
    var d = S.data, m = d ? d.mode : 'off';
    var h = '<section class="qq-hero" aria-label="Quiqup mode"><div><h2>Quiqup deliveries ' +
      (d ? '<span class="qq-chip ' + m + '">' + esc(d.mode_labels[m]) + '</span>' : '') + '</h2>' +
      '<p>' + (d ? esc(MODE_TEXT[m]) : 'Loading…') + '</p></div>';
    if (d) {
      h += '<div class="qq-seg" role="radiogroup" aria-label="Mode">' + ['off', 'testing', 'live'].map(function (k) {
        return '<button type="button" role="radio" data-m="' + k + '" aria-checked="' + (m === k) + '" data-qq-mode="' + k + '"' + (S.busy ? ' disabled' : '') + '>' +
          (S.busy === 'mode:' + k ? spin(true, '') : '') + esc(d.mode_labels[k]) + '</button>';
      }).join('') + '</div>';
    }
    h += '</section>';
    if (d && !d.envs.sandbox.api_key.filled) {
      h += '<div class="qq-banner warn" role="note"><span aria-hidden="true">⚑</span><div><b>Add your staging API key</b> to start. Quiqdash (staging) → Organization → API keys → expiry <b>Never</b>, then paste it in the Sandbox card below. Nothing is sent to Quiqup until then.</div></div>';
    }
    return h;
  }

  function tabs() {
    return '<div class="qq-tabs" role="tablist">' + [['conn', 'Connection'], ['pick', 'Pickup & defaults'], ['test', 'Test customers'], ['stat', 'Statuses'], ['act', 'Activity']].map(function (t) {
      return '<button type="button" role="tab" id="qqtab-' + t[0] + '" aria-selected="' + (S.tab === t[0]) + '" tabindex="' + (S.tab === t[0] ? 0 : -1) + '" data-qq-tab="' + t[0] + '">' + t[1] + '</button>';
    }).join('') + '</div>';
  }

  function secretField(env, key, label, help) {
    var m = S.data.envs[env][key] || {};
    return '<div class="qq-f"><label for="qq_' + env + '_' + key + '">' + label + ' ' + (m.filled ? '<span class="qq-chip live">Saved ••••' + esc(m.last4) + '</span>' : '<span class="qq-chip off">Not set</span>') + '</label>' +
      '<input type="password" autocomplete="off" spellcheck="false" id="qq_' + env + '_' + key + '" placeholder="' + (m.filled ? 'Type to replace' : 'Paste it here') + '">' +
      '<small>' + help + '</small></div>';
  }

  function connTab() {
    var d = S.data;
    return '<div class="qq-envs">' + ['sandbox', 'live'].map(function (env) {
      var e = d.envs[env], t = S.test[env], used = (env === 'live') === (d.mode === 'live');
      return '<section class="qq-card qq-env ' + env + '" aria-labelledby="qq_h_' + env + '"><h3 id="qq_h_' + env + '">' + (env === 'live' ? 'Live · production' : 'Sandbox · staging') +
        ' <span class="qq-chip ' + (env === 'live' ? 'live' : 'testing') + '">' + esc(e.host) + '</span>' + (used && d.mode !== 'off' ? ' <span class="qq-chip info">In use</span>' : '') + '</h3>' +
        '<p class="qq-help">' + (env === 'live' ? 'Used only in Live mode. Every booking is a real, billed courier.' : 'Used in Internal testing. Bookings go to Quiqup\'s staging system: no real courier comes.') + '</p>' +
        '<div class="qq-grid">' +
          secretField(env, 'api_key', 'API key', 'Quiqdash → Organization → API keys, expiry <b>Never</b>.') +
          secretField(env, 'webhook_secret', 'Webhook signing secret', 'Quiqdash → Integrations → Webhooks → your subscription → eye icon.') +
        '</div>' +
        '<div class="qq-f" style="margin-top:12px"><label>Webhook address for this environment\'s Quiqdash</label><div class="qq-copy"><code>' + (e.webhook_url ? esc(e.webhook_url) : 'Save once to create it') + '</code>' +
          (e.webhook_url ? '<button type="button" class="qq-btn" data-qq-copy="' + esc(e.webhook_url) + '">Copy</button>' : '') +
          '<button type="button" class="qq-btn" data-qq-regen="' + env + '"' + (S.busy ? ' disabled' : '') + '>New address</button></div>' +
          '<small>New subscription → Quick select <b>All order events</b> → Active. Staging and production keep separate subscriptions.</small></div>' +
        '<div class="qq-meta"><span>Key verified: <b>' + esc(e.verified_at ? when(e.verified_at) : 'not yet') + '</b></span><span>Last webhook: <b>' + esc(when(e.last_webhook_at)) + '</b>' + (e.last_webhook_state ? ' · ' + esc(e.last_webhook_state) : '') + '</span></div>' +
        '<div class="qq-row"><button type="button" class="qq-btn pri" data-qq-savekeys="' + env + '"' + (S.busy ? ' disabled' : '') + '>' + spin(S.busy === 'keys:' + env, 'Save') + '</button>' +
          '<button type="button" class="qq-btn" data-qq-test="' + env + '"' + (S.busy || !e.api_key.filled ? ' disabled' : '') + '>' + spin(S.busy === 'test:' + env, 'Test connection') + '</button>' +
          (t ? '<span class="qq-chip ' + (t.ok ? 'live' : 'bad') + '">' + esc(t.text) + '</span>' : '') + '</div></section>';
    }).join('') + '</div>';
  }

  function txt(id, label, val, max, help, type) {
    var err = S.err[id];
    return '<div class="qq-f"><label for="' + id + '">' + label + '</label><input type="' + (type || 'text') + '" id="' + id + '" maxlength="' + max + '" value="' + esc(val) + '"' + (err ? ' aria-invalid="true" aria-describedby="' + id + '_e"' : '') + '>' +
      (err ? '<small class="qq-err" id="' + id + '_e">' + esc(err) + '</small>' : help ? '<small>' + help + '</small>' : '') + '</div>';
  }
  function chk(id, label, on) { return '<label class="qq-chk"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '><span>' + label + '</span></label>'; }

  function pickTab() {
    var o = S.data.options, p = o.pickup, sv = S.data.services, methods = S.data.shipping_methods || [];
    var h = '<section class="qq-card"><h3>Pickup</h3><p>Where the courier collects, and who they call.</p><div class="qq-grid">' +
      txt('qq_p_name', 'Contact name', p.contact_name, 100) + txt('qq_p_phone', 'Contact phone', p.contact_phone, 40, 'With the country code, e.g. +971 50 123 4567.', 'tel') +
      txt('qq_p_a1', 'Address line 1', p.address1, 200, 'Building and street.') + txt('qq_p_a2', 'Address line 2', p.address2, 200, 'Area or unit.') +
      txt('qq_p_town', 'Town', p.town, 100) +
      '<div class="qq-f"><label for="qq_p_em">Emirate</label><select id="qq_p_em">' + S.data.emirates.map(function (x) { return '<option' + (p.emirate === x ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select></div>' +
      txt('qq_p_notes', 'Notes for the courier', p.notes, 250) + '</div></section>';
    h += '<section class="qq-card"><h3>Services</h3><p>Tick only the services your account manager confirmed. Quiqup checks Same Day when booking; the others are accepted even when off.</p><div class="qq-svc">' +
      Object.keys(sv).map(function (k) { return '<label><input type="checkbox" id="qq_sv_' + k + '"' + (o.services.enabled.indexOf(k) >= 0 ? ' checked' : '') + '>' + esc(sv[k]) + '</label>'; }).join('') + '</div>' +
      '<div class="qq-grid" style="margin-top:14px"><div class="qq-f"><label for="qq_sv_def">Default service</label><select id="qq_sv_def">' +
      Object.keys(sv).map(function (k) { return '<option value="' + k + '"' + (o.services.default === k ? ' selected' : '') + '>' + esc(sv[k]) + '</option>'; }).join('') + '</select></div>' +
      '<div class="qq-f"><label for="qq_parcels">Parcels per order</label><input type="number" min="1" max="10" id="qq_parcels" value="' + esc(o.parcels) + '"></div>' +
      '<div class="qq-f"><label for="qq_4h">4-hour: earliest collection</label><input type="time" min="08:00" max="18:59" id="qq_4h" value="' + esc(o.four_hour_time) + '"><small>Dubai time, 08:00 to 18:59.</small></div></div>' +
      (methods.length ? '<h3 style="margin-top:18px">Service per delivery method</h3><p>The delivery methods customers chose in the last 90 days.</p><div class="qq-grid">' + methods.map(function (m, i) {
        var cur = o.services.by_method[m] || '';
        return '<div class="qq-f"><label for="qq_bm_' + i + '">' + esc(m) + '</label><select id="qq_bm_' + i + '" data-qq-method="' + esc(m) + '"><option value="">Default</option>' +
          Object.keys(sv).map(function (k) { return '<option value="' + k + '"' + (cur === k ? ' selected' : '') + '>' + esc(sv[k]) + '</option>'; }).join('') + '</select></div>';
      }).join('') + '</div>' : '') +
      '<h3 style="margin-top:18px">Cut-off times</h3><p>Only for a warning on the schedule sheet: Quiqup accepts a late order and collects it next cycle.</p><div class="qq-grid">' +
      Object.keys(sv).map(function (k) { return '<div class="qq-f"><label for="qq_co_' + k + '">' + esc(sv[k]) + '</label><input type="time" id="qq_co_' + k + '" value="' + esc(o.cutoffs[k] || '') + '"></div>'; }).join('') + '</div></section>';
    h += '<section class="qq-card"><h3>When booking</h3><div style="display:grid;gap:12px">' +
      chk('qq_release', 'Release to the courier as soon as it is booked', o.release) +
      chk('qq_products', 'Send the product list (SKU, short name, quantity) so Quiqup support can see what is in the box', o.send_products) +
      chk('qq_uae', 'UAE addresses only — Quiqup export needs HS codes, weights and sizes the shop does not hold', o.uae_only) +
      '<div class="qq-f" style="max-width:380px"><label for="qq_np">Note for the courier</label><select id="qq_np"><option value="customer"' + (o.note_policy === 'customer' ? ' selected' : '') + '>The customer\'s order note</option><option value="none"' + (o.note_policy === 'none' ? ' selected' : '') + '>None</option></select><small>Never the gift message: that one is private.</small></div>' +
      '</div><div class="qq-row"><button type="button" class="qq-btn pri" data-qq-save="pick"' + (S.busy ? ' disabled' : '') + '>' + spin(S.busy === 'save:pick', 'Save pickup & defaults') + '</button></div></section>';
    return h;
  }

  function tagList(kind, list) {
    return '<div class="qq-tags">' + (list.length ? list.map(function (v, i) {
      return '<span class="qq-tag"><span>' + esc(v) + '</span><button type="button" aria-label="Remove ' + esc(v) + '" data-qq-untag="' + kind + ':' + i + '">×</button></span>';
    }).join('') : '<small style="color:var(--ink-faint,#97a0b2)">None yet.</small>') + '</div>';
  }
  function testTab() {
    var t = S.data.options.test;
    return '<section class="qq-card"><h3>Who is a test customer <span class="qq-chip testing">Internal testing</span></h3>' +
      '<p>In Internal testing only these orders can be booked, and only they will show delivery steps on the track page and in My account. Place an order on the website with one of these, then book it from Store → Orders.</p>' +
      '<div class="qq-grid"><div class="qq-f"><label for="qq_t_email">Emails</label><div class="qq-copy"><input type="email" id="qq_t_email" placeholder="you@example.com" style="flex:1 1 200px"><button type="button" class="qq-btn" data-qq-tag="emails">Add</button></div>' + tagList('emails', t.emails) + '</div>' +
      '<div class="qq-f"><label for="qq_t_phone">Phones</label><div class="qq-copy"><input type="tel" id="qq_t_phone" placeholder="+971 50 123 4567" style="flex:1 1 200px"><button type="button" class="qq-btn" data-qq-tag="phones">Add</button></div>' + tagList('phones', t.phones) + '</div></div>' +
      '<p class="qq-help" style="margin-top:12px">You can also flag any single order as a test order from its row on Store → Orders (' + esc((t.orders || []).length) + ' flagged).</p>' +
      '<div class="qq-row"><button type="button" class="qq-btn pri" data-qq-save="test"' + (S.busy ? ' disabled' : '') + '>' + spin(S.busy === 'save:test', 'Save test customers') + '</button></div></section>';
  }

  function statTab() {
    var o = S.data.options.statuses, lab = S.data.state_labels;
    var name = { '': '— no change —', shipped: 'Shipped', completed: 'Completed', onhold: 'On hold', cancelled: 'Cancelled' };
    return '<section class="qq-card"><h3>What each Quiqup status does</h3><p>The order moves only forward (Processing → Shipped → Completed); a refunded or cancelled order is never touched. A move sends the same email as changing it by hand. Customer steps are for the delivery timeline.</p>' +
      '<div class="qq-st"><div class="qq-st-row head"><div>Quiqup status</div><div>Our order</div><div>Customer step · English</div><div>Arabic</div><div>Notify</div></div>' +
      S.data.states.map(function (st) {
        var r = o[st] || {};
        return '<div class="qq-st-row' + (r.status ? ' mapped' : '') + '"><div><b>' + esc(lab[st] || st) + '</b><small>' + esc(st) + '</small></div>' +
          '<div><select data-qq-st="' + st + ':status" aria-label="Order status when ' + esc(lab[st] || st) + '">' + S.data.targets.map(function (t) { return '<option value="' + t + '"' + (r.status === t ? ' selected' : '') + '>' + esc(name[t] || t) + '</option>'; }).join('') + '</select></div>' +
          '<div><input type="text" maxlength="60" data-qq-st="' + st + ':en" value="' + esc(r.en) + '" aria-label="English step for ' + esc(st) + '"></div>' +
          '<div><input type="text" maxlength="60" dir="rtl" lang="ar" data-qq-st="' + st + ':ar" value="' + esc(r.ar) + '" aria-label="Arabic step for ' + esc(st) + '"></div>' +
          '<div class="qq-flags"><label><input type="checkbox" data-qq-st="' + st + ':email"' + (r.email ? ' checked' : '') + '>Email</label><label><input type="checkbox" data-qq-st="' + st + ':push"' + (r.push ? ' checked' : '') + '>Push</label></div></div>';
      }).join('') + '</div><div class="qq-row"><button type="button" class="qq-btn pri" data-qq-save="stat"' + (S.busy ? ' disabled' : '') + '>' + spin(S.busy === 'save:stat', 'Save statuses') + '</button></div></section>';
  }

  function skeleton(n) {
    var h = '';
    for (var i = 0; i < n; i++) h += '<div class="qq-sk" style="height:' + (i ? 54 : 84) + 'px;margin-bottom:10px"></div>';
    return h;
  }

  function actTab() {
    var a = S.act;
    if (!a) return '<div class="qq-kpis">' + skeleton(1).repeat(3) + '</div>' + skeleton(3);
    if (a.error) return '<div class="qq-banner warn">' + esc(a.error) + '</div>';
    var h = '';
    if (a.webhook_stale) h += '<div class="qq-banner warn" role="note"><span aria-hidden="true">⚠</span><div><b>No webhook from Quiqup for over 6 hours while deliveries are moving.</b> Quiqup pauses a subscription after repeated failures and tells no one. Fix: Quiqdash → Integrations → Webhooks → Edit → Active → Save. Meanwhile the shop checks with Quiqup every few minutes.</div></div>';
    h += '<div class="qq-kpis"><div class="qq-kpi"><b>' + esc(a.inflight) + '</b><span>On the way</span></div><div class="qq-kpi"><b>' + esc(a.unapplied) + '</b><span>Updates waiting</span></div>' +
      '<div class="qq-kpi"><b>' + esc(when(a.last_webhook_at)) + '</b><span>Last webhook' + (a.last_webhook_state ? ' · ' + esc(a.last_webhook_state) : '') + '</span></div>' +
      '<div class="qq-kpi"><b>' + esc(when(a.last_poll_at)) + '</b><span>Last check with Quiqup</span></div></div>';
    if (a.last_poll_error) h += '<div class="qq-banner warn">' + esc(a.last_poll_error) + '</div>';
    h += '<section class="qq-card"><h3>Needs a look</h3>';
    if (!a.rows.length) {
      h += '<div class="qq-empty"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.5 2.5L16 9.5"/></svg>Nothing needs a look. Failed bookings, missed deliveries and returns appear here.</div>';
    } else {
      h += '<div class="qq-list">' + a.rows.map(function (r) {
        var detail = r.error || r.reason || (r.stuck_pending ? 'Still pending at Quiqup: open it in Quiqdash.' : '') || (r.state === 'returned_to_origin' ? 'Returned to you: decide what happens to the order.' : '');
        var bad = r.job === 'failed' || r.state === 'delivery_failed' || r.state === 'returned_to_origin';
        return '<div class="qq-item"><span class="qq-chip ' + (bad ? 'bad' : 'testing') + '">' + esc(r.state_label || r.job) + '</span><b>#' + esc(r.order_number) + (r.env === 'live' ? '' : ' <span class="qq-chip testing">STAGING</span>') + '</b>' +
          '<span>' + (r.tracking_url ? '<a href="' + esc(r.tracking_url) + '" target="_blank" rel="noopener noreferrer">Track ↗</a>' : (r.qq_id ? 'Quiqup ' + esc(r.qq_id) : '')) + '</span>' +
          (detail ? '<span class="qq-sub">' + esc(detail) + '</span>' : '') + '</div>';
      }).join('') + '</div>';
    }
    return h + '</section>';
  }

  function modeLog() {
    var log = (S.data && S.data.mode_log) || [];
    if (!log.length) return '';
    var L = S.data.mode_labels;
    return '<section class="qq-card"><h3>Mode history</h3><div class="qq-log">' + log.slice(0, 6).map(function (e) {
      return '<div>' + esc(when(e.at)) + ' · <b>' + esc(e.by) + '</b> · ' + esc(L[e.from] || e.from) + ' → ' + esc(L[e.to] || e.to) + '</div>';
    }).join('') + '</div></section>';
  }

  function render() {
    var h = host();
    if (!h) return;
    if (!S.data) { h.innerHTML = '<div class="qq-hero"><div style="width:100%">' + skeleton(1) + '</div></div>' + skeleton(4); return; }
    var body = S.tab === 'pick' ? pickTab() : S.tab === 'test' ? testTab() : S.tab === 'stat' ? statTab() : S.tab === 'act' ? actTab() : connTab() + modeLog();
    var focusId = document.activeElement && document.activeElement.id;
    h.innerHTML = hero() + tabs() + '<div role="tabpanel" aria-labelledby="qqtab-' + S.tab + '">' + body + '</div>';
    if (focusId) { var f = document.getElementById(focusId); if (f) f.focus(); }
  }

  function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
  function on(id) { var el = document.getElementById(id); return !!(el && el.checked); }

  function collect(tab) {
    if (tab === 'pick') {
      var bm = {};
      document.querySelectorAll('[data-qq-method]').forEach(function (s) { if (s.value) bm[s.getAttribute('data-qq-method')] = s.value; });
      var co = {}; Object.keys(S.data.services).forEach(function (k) { co[k] = val('qq_co_' + k); });
      return { options: {
        pickup: { contact_name: val('qq_p_name'), contact_phone: val('qq_p_phone'), address1: val('qq_p_a1'), address2: val('qq_p_a2'), town: val('qq_p_town'), emirate: val('qq_p_em'), notes: val('qq_p_notes') },
        services: { enabled: Object.keys(S.data.services).filter(function (k) { return on('qq_sv_' + k); }), default: val('qq_sv_def'), by_method: bm },
        parcels: parseInt(val('qq_parcels'), 10) || 1, four_hour_time: val('qq_4h'), cutoffs: co,
        release: on('qq_release'), send_products: on('qq_products'), uae_only: on('qq_uae'), note_policy: val('qq_np')
      } };
    }
    if (tab === 'test') return { options: { test: { emails: S.data.options.test.emails, phones: S.data.options.test.phones } } };
    var st = {};
    document.querySelectorAll('[data-qq-st]').forEach(function (el) {
      var p = el.getAttribute('data-qq-st').split(':');
      st[p[0]] = st[p[0]] || {};
      st[p[0]][p[1]] = el.type === 'checkbox' ? el.checked : el.value;
    });
    return { options: { statuses: st } };
  }

  /* Inline checks before anything is sent; the server checks again. */
  function validate(tab) {
    S.err = {};
    if (tab === 'pick') {
      var ph = val('qq_p_phone').replace(/[\s\-()]/g, '');
      if (ph && !/^(\+|00)?\d{9,15}$/.test(ph)) S.err.qq_p_phone = 'A full number with the country code, e.g. +971 50 123 4567.';
      if (!val('qq_p_a1').trim()) S.err.qq_p_a1 = 'Quiqup needs a street and building to collect from.';
    }
    return Object.keys(S.err).length === 0;
  }

  async function run(busy, path, body, okTitle, okText) {
    if (S.busy) return null;
    S.busy = busy; render();
    var r = await call(path, body);
    S.busy = '';
    if (!ours()) return r;
    if (r.status === 200 && r.body.ok !== false) {
      if (r.body.envs) S.data = Object.assign({}, S.data, r.body);
      render();
      if (okTitle) toast('ok', okTitle, okText || '');
    } else {
      render();
      toast('bad', 'Not saved', fail(r.status, r.body, 'change Store → Quiqup'));
    }
    return r;
  }

  async function save(tab) {
    if (!S.data) return;
    var c = collect(tab);
    if (!validate(tab)) { render(); toast('bad', 'Check the highlighted field', ''); return; }
    await run('save:' + tab, 'quiqup-settings', c, 'Saved', tab === 'stat' ? 'Status mapping updated.' : tab === 'test' ? 'Test customers updated.' : 'Pickup and defaults updated.');
  }

  async function saveKeys(env) {
    var k = val('qq_' + env + '_api_key').trim(), w = val('qq_' + env + '_webhook_secret').trim();
    var r = await run('keys:' + env, 'quiqup-settings', { secrets_env: env, api_key: k, webhook_secret: w },
      'Saved', k ? 'New ' + (env === 'live' ? 'live' : 'staging') + ' key stored. Press Test connection to verify it.' : 'Webhook address ready.');
    if (r && r.status === 200) S.test[env] = null;
  }

  async function regen(env) {
    var yes = await dialog('<h3>Make a new webhook address?</h3><p>The ' + (env === 'live' ? 'live' : 'staging') + ' address stops working at once. Paste the new one into that environment\'s Quiqdash straight away, or updates stop arriving.</p>',
      [{ label: 'Keep the old one', value: false }, { label: 'Make a new address', value: true, cls: 'danger' }]);
    if (yes) await run('regen:' + env, 'quiqup-settings', { secrets_env: env, regenerate_url: true }, 'New webhook address made', 'Paste it into Quiqdash now.');
  }

  async function test(env) {
    if (S.busy) return;
    S.busy = 'test:' + env; render();
    var r = await call('quiqup-test', { env: env });
    S.busy = '';
    if (!ours()) return;
    var ok = r.status === 200 && !!r.body.ok;
    S.test[env] = { ok: ok, text: r.status === 200 ? r.body.message || '' : fail(r.status, r.body, 'test the connection') };
    if (ok) S.data.envs[env].verified_at = new Date().toISOString();
    render();
    toast(ok ? 'ok' : 'bad', ok ? 'Quiqup answered' : 'Quiqup refused the test', S.test[env].text);
    if (ok) load(true);
  }

  async function setMode(to) {
    var d = S.data, from = d.mode;
    if (!d || to === from || S.busy) return;
    var L = d.mode_labels, html, buttons;
    if (to === 'live') {
      var cl = d.checklist, ready = cl.every(function (c) { return c.ok; });
      html = '<h3>Go Live with Quiqup?</h3><p>From the moment you confirm:</p><ul>' +
        '<li>New bookings go to Quiqup <b>production</b> (' + esc(d.envs.live.host) + ') with your live key ••••' + esc(d.envs.live.api_key.last4 || '—') + '. Each one is a real, billed courier.</li>' +
        '<li><b>Every</b> order can be booked, not just test orders.</li>' +
        '<li>Customers see their delivery steps on the track page and in My account.</li>' +
        '<li>Orders already booked in testing keep updating from staging.</li></ul>' +
        '<ul class="qq-cl">' + cl.map(function (c) { return '<li class="' + (c.ok ? 'ok' : '') + '"><i aria-hidden="true">' + (c.ok ? '✓' : '!') + '</i><div>' + esc(c.label) + '<small>' + esc(c.detail) + '</small></div></li>'; }).join('') + '</ul>' +
        (ready ? '' : '<p><b>Not ready yet.</b> Every item above needs a tick before Live can be switched on.</p>');
      buttons = [{ label: 'Not now', value: false }, { label: 'Go Live', value: true, cls: 'go', disabled: !ready }];
    } else if (to === 'testing') {
      html = '<h3>Switch to Internal testing?</h3><p>Bookings go to Quiqup <b>staging</b> (' + esc(d.envs.sandbox.host) + ') with your staging key. Only your test orders can be booked and only they show delivery steps; every other shopper sees the shop exactly as before.</p>' +
        (from === 'live' ? '<p>Live bookings already made keep updating from production.</p>' : '');
      buttons = [{ label: 'Cancel', value: false }, { label: from === 'live' ? 'Leave Live' : 'Start testing', value: true, cls: 'pri' }];
    } else {
      html = '<h3>Turn Quiqup off?</h3><p>Nothing new can be booked. Parcels already booked keep updating until they are delivered.</p>';
      buttons = [{ label: 'Cancel', value: false }, { label: 'Turn off', value: true, cls: from === 'live' ? 'danger' : 'pri' }];
    }
    var yes = await dialog(html, buttons);
    if (!yes || !ours()) return;
    var r = await run('mode:' + to, 'quiqup-mode', { mode: to, confirm: true }, 'Mode: ' + L[to], MODE_TEXT[to]);
    if (r && r.status === 422 && r.body.checklist && ours()) { S.data.checklist = r.body.checklist; render(); }
  }

  function addTag(kind) {
    var id = kind === 'emails' ? 'qq_t_email' : 'qq_t_phone', v = val(id).trim();
    if (!v) return;
    if (kind === 'emails' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { toast('bad', 'That is not an email address', v); return; }
    if (kind === 'phones' && !/^(\+|00|0)?\d{8,15}$/.test(v.replace(/[\s\-()]/g, ''))) { toast('bad', 'Add the number with its country code', v); return; }
    var list = S.data.options.test[kind];
    if (list.indexOf(v) < 0) list.push(kind === 'emails' ? v.toLowerCase() : v);
    render();
    var el = document.getElementById(id); if (el) el.focus();
  }

  async function loadActivity() {
    S.act = null; render();
    var r = await call('quiqup-activity');
    if (!ours() || S.tab !== 'act') return;
    S.act = r.status === 200 && r.body.ok ? r.body : { error: fail(r.status, r.body, 'open Activity') };
    render();
  }

  async function load(quiet) {
    var r = await call('quiqup-settings');
    if (!ours()) return;
    if (r.status !== 200 || !r.body.ok) { if (!quiet) { host().innerHTML = '<div class="qq-banner warn">' + esc(fail(r.status, r.body, 'open Store → Quiqup')) + '</div>'; } return; }
    S.data = r.body; render();
  }

  function copyText(value, button) {
    var done = function () { button.textContent = 'Copied ✓'; toast('ok', 'Copied', 'Paste it into Quiqdash → Integrations → Webhooks.'); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done, function () { toast('warn', 'Copy it by hand', value); });
    else toast('warn', 'Copy it by hand', value);
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-qq-screen]')) return;
    var b;
    if ((b = t.closest('[data-qq-tab]'))) { S.tab = b.getAttribute('data-qq-tab'); S.err = {}; render(); if (S.tab === 'act') loadActivity(); return; }
    if ((b = t.closest('[data-qq-mode]'))) { setMode(b.getAttribute('data-qq-mode')); return; }
    if ((b = t.closest('[data-qq-save]'))) { save(b.getAttribute('data-qq-save')); return; }
    if ((b = t.closest('[data-qq-savekeys]'))) { saveKeys(b.getAttribute('data-qq-savekeys')); return; }
    if ((b = t.closest('[data-qq-test]'))) { test(b.getAttribute('data-qq-test')); return; }
    if ((b = t.closest('[data-qq-regen]'))) { regen(b.getAttribute('data-qq-regen')); return; }
    if ((b = t.closest('[data-qq-tag]'))) { addTag(b.getAttribute('data-qq-tag')); return; }
    if ((b = t.closest('[data-qq-untag]'))) { var p = b.getAttribute('data-qq-untag').split(':'); S.data.options.test[p[0]].splice(+p[1], 1); render(); return; }
    if ((b = t.closest('[data-qq-copy]'))) { copyText(b.getAttribute('data-qq-copy'), b); }
  });
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-qq-screen]')) return;
    if (e.key === 'Enter' && (t.id === 'qq_t_email' || t.id === 'qq_t_phone')) { e.preventDefault(); addTag(t.id === 'qq_t_email' ? 'emails' : 'phones'); return; }
    var tab = t.closest('[role=tab]'), seg = t.closest('[role=radio]');
    var group = tab ? '[role=tab]' : seg ? '[role=radio]' : null;
    if (!group || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
    var all = Array.prototype.slice.call(t.parentNode.querySelectorAll(group)), i = all.indexOf(t.closest(group));
    var next = all[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length];
    e.preventDefault();
    if (next) next.focus();
  });

  function addNavEntry() {
    if (!window.kbbAddNavEntry) return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Quiqup',
      icon: '<path d="M2 7h11v9H2z"/><path d="M13 10h4.5l3.5 3.5V16h-8z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/><path d="M5 4h6"/>',
      group: 'Store',
      after: ['shipping']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = 'Store';
    if (title) title.textContent = 'Quiqup';
    if (side) side.classList.remove('open');
    var c = document.getElementById('content');
    if (!c) return undefined;
    c.innerHTML = '<div class="wrap qq" data-qq-screen></div>';
    reset();
    render();
    load(false);
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
