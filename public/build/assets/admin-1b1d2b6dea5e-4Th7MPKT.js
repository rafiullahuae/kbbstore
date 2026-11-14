
(function () {
  'use strict';
  function Q() { return window.kbbQQ; }

  /* ── the selection bar ─────────────────────────────────────────────── */
  function selected() {
    return Array.prototype.map.call(document.querySelectorAll('#content [data-olsel].cbx.on'), function (b) { return parseInt(b.getAttribute('data-olsel'), 10); })
      .filter(function (n) { return n > 0; });
  }

  function addBar() {
    var clear = document.getElementById('olClearSel');
    if (!clear || !clear.parentNode || clear.parentNode.querySelector('.qqo-bar')) return;
    var span = document.createElement('span');
    span.className = 'qqo-bar';
    span.innerHTML = '<button type="button" class="btn sm" data-qqo-open><span class="qqo-dot" aria-hidden="true"></span>Schedule with Quiqup…</button>' +
      '<button type="button" class="btn ghost sm" data-qqo-labels>Quiqup labels</button>';
    clear.parentNode.insertBefore(span, clear.nextSibling);
  }

  /* ── the sheet ─────────────────────────────────────────────────────── */
  var SH = null;   // {token, ids, data, rows:{id:overrides}, defs, phase, results:{}, done, total}
  var token = 0;

  function sheetOpen(t) { return !!(SH && SH.token === t && document.getElementById('qqoSheet')); }

  function chip(cls, text) { return '<span class="qq-chip ' + cls + '">' + Q().esc(text) + '</span>'; }

  function rowState(r) {
    var res = SH.results[r.id];
    if (res) return res.ok ? 'ok' : res.job === 'queued' || res.job === 'creating' ? 'busy' : 'bad';
    if (r.booked) return 'skip';
    return r.blocks.length ? 'fix' : 'ready';
  }

  function rowHtml(r) {
    var e = Q().esc, st = rowState(r), o = SH.rows[r.id] || {}, res = SH.results[r.id];
    var fields = r.blocks.map(function (b) { return b.field; });
    var services = SH.data.services;
    var h = '<article class="qqo-row ' + (st === 'ok' ? 'ok' : st === 'bad' ? 'bad' : st === 'skip' ? 'skip' : '') + '" aria-label="Order ' + e(r.number) + '">' +
      '<div class="qqo-top"><b>#' + e(r.number) + '</b><span class="qqo-who">' + e(r.customer || '—') + '</span>' +
      (r.test ? chip('testing', 'Test order') : '') +
      (st === 'ok' ? chip('live', 'Booked') : st === 'bad' ? chip('bad', 'Refused') : st === 'busy' ? chip('info', 'Sending…') : st === 'skip' ? chip('off', 'Already booked') : st === 'fix' ? chip('bad', 'Needs a fix') : chip('live', 'Ready')) + '</div>' +
      '<div class="qqo-facts"><span>' + e(r.phone || 'no phone') + '</span><span>' + e([r.address1, r.address2, r.town, r.emirate !== r.town ? r.emirate : ''].filter(Boolean).join(', ')) + '</span>' +
      '<span>' + (r.payment_mode === 'paid_on_delivery' ? 'COD AED ' + e(r.cod_aed) : 'Prepaid') + '</span><span>' + e(r.status) + '</span></div>';

    if (res) {
      h += res.ok
        ? '<div class="qqo-res ok">✓ Quiqup #' + e(res.qq_id) + (res.tracking_url ? ' · <a href="' + e(res.tracking_url) + '" target="_blank" rel="noopener noreferrer">Track ↗</a>' : '') + '</div>'
        : '<div class="qqo-res ' + (st === 'busy' ? '' : 'bad') + '">' + (st === 'busy' ? '' : '✗ ') + e(res.error || 'Waiting for Quiqup…') + '</div>';
    } else if (r.booked) {
      h += '<div class="qqo-res">Quiqup #' + e(r.booked.qq_id || '—') + ' · ' + e(r.booked.state_label || r.booked.job) + (r.booked.tracking_url ? ' · <a href="' + e(r.booked.tracking_url) + '" target="_blank" rel="noopener noreferrer">Track ↗</a>' : '') + '</div>';
    } else {
      var msgs = r.blocks.map(function (b) { return '<div class="b">' + e(b.text) + '</div>'; }).join('') + r.warns.map(function (w) { return '<div class="w">' + e(w) + '</div>'; }).join('');
      if (msgs) h += '<div class="qqo-msgs">' + msgs + '</div>';
      var editable = fields.indexOf('mode') < 0 && fields.indexOf('status') < 0 && fields.indexOf('payment') < 0 && fields.indexOf('country') < 0;
      if (editable && SH.phase === 'review') {
        h += '<div class="qqo-fix">';
        if (fields.indexOf('phone') >= 0) h += '<label>Mobile for the courier<input type="tel" data-qqo-f="' + r.id + ':phone" value="' + e(o.phone || '') + '" placeholder="+971 5X XXX XXXX" aria-invalid="true"></label>';
        if (fields.indexOf('address1') >= 0) h += '<label>Street and building<input type="text" maxlength="300" data-qqo-f="' + r.id + ':address1" value="' + e(o.address1 || '') + '" aria-invalid="true"></label>';
        if (fields.indexOf('emirate') >= 0) h += '<label>Emirate<select data-qqo-f="' + r.id + ':emirate" aria-invalid="true"><option value="">Choose…</option>' + SH.data.emirates.map(function (x) { return '<option' + (o.emirate === x ? ' selected' : '') + '>' + e(x) + '</option>'; }).join('') + '</select></label>';
        h += '<label>Service<select data-qqo-f="' + r.id + ':service">' + Object.keys(services).map(function (k) { return '<option value="' + k + '"' + ((o.service || r.service) === k ? ' selected' : '') + '>' + e(services[k]) + '</option>'; }).join('') + '</select></label>' +
          '<label>Parcels<input type="number" min="1" max="10" data-qqo-f="' + r.id + ':parcels" value="' + e(o.parcels || r.parcels) + '"></label>' +
          '<label style="grid-column:1/-1">Note for the courier<input type="text" maxlength="250" data-qqo-f="' + r.id + ':note" value="' + e(o.note !== undefined ? o.note : r.note) + '"></label></div>';
      }
    }
    return h + '</article>';
  }

  function counts() {
    var c = { ready: 0, fix: 0, skip: 0, ok: 0, bad: 0, busy: 0 };
    (SH.data ? SH.data.rows : []).forEach(function (r) { c[rowState(r)]++; });
    return c;
  }

  function paint() {
    var el = document.getElementById('qqoSheet');
    if (!el) return;
    var e = Q().esc, d = SH.data, body = el.querySelector('.qqo-body'), foot = el.querySelector('.qqo-foot'), sum = el.querySelector('.qqo-sum'), defs = el.querySelector('.qqo-defs');
    if (!d) {
      body.innerHTML = [0, 1, 2].map(function () { return '<div class="qq-sk" style="height:96px"></div>'; }).join('');
      return;
    }
    var c = counts();
    el.querySelector('[data-qqo-mode]').innerHTML = chip(d.mode === 'live' ? 'live' : d.mode === 'testing' ? 'testing' : 'off', d.mode === 'live' ? 'LIVE' : d.mode === 'testing' ? 'INTERNAL TESTING · STAGING' : 'OFF');
    defs.innerHTML = '<label>Service for all<select data-qqo-def="service"' + (SH.phase !== 'review' ? ' disabled' : '') + '><option value="">Per order</option>' + Object.keys(d.services).map(function (k) { return '<option value="' + k + '"' + (SH.defs.service === k ? ' selected' : '') + '>' + e(d.services[k]) + '</option>'; }).join('') + '</select></label>' +
      '<label>Parcels<input type="number" min="1" max="10" data-qqo-def="parcels" value="' + e(SH.defs.parcels || d.parcels) + '"' + (SH.phase !== 'review' ? ' disabled' : '') + '></label>' +
      '<label class="qqo-inl"><input type="checkbox" data-qqo-def="allow_shipped"' + (SH.defs.allow_shipped ? ' checked' : '') + (SH.phase !== 'review' ? ' disabled' : '') + '> Include orders already marked shipped</label>' +
      '<button type="button" class="qq-btn" data-qqo-recheck' + (SH.phase !== 'review' || SH.checking ? ' disabled' : '') + '>' + (SH.checking ? '<span class="qq-spin" aria-hidden="true"></span>' : '') + 'Check again</button>';
    sum.innerHTML = (c.ready ? chip('live', c.ready + ' ready') : '') + (c.fix ? chip('bad', c.fix + ' need a fix') : '') + (c.skip ? chip('off', c.skip + ' already booked') : '') +
      (c.ok ? chip('live', c.ok + ' booked') : '') + (c.bad ? chip('bad', c.bad + ' refused') : '') + (c.busy ? chip('info', c.busy + ' sending') : '');
    body.innerHTML = d.rows.length ? d.rows.map(rowHtml).join('') : '<div class="qq-empty">No orders selected.</div>';

    var pct = SH.total ? Math.round(100 * SH.done / SH.total) : 0;
    var acts = '';
    if (SH.phase === 'review') {
      acts = '<span class="qqo-note">' + (c.fix ? 'Orders that need a fix are left out unless you fix them and press Check again.' : 'Pressing twice or closing this panel can never book an order twice.') + '</span>' +
        '<button type="button" class="qq-btn" data-qqo-close>Cancel</button>' +
        '<button type="button" class="qq-btn go" data-qqo-go' + (c.ready ? '' : ' disabled') + '>Schedule ' + c.ready + ' order' + (c.ready === 1 ? '' : 's') + '</button>';
    } else if (SH.phase === 'sending') {
      acts = '<span class="qqo-note" role="status">Booking ' + SH.done + ' of ' + SH.total + '… you can close this panel; the shop finishes the rest within a minute.</span>';
    } else {
      acts = '<span class="qqo-note" role="status">' + (c.ok ? c.ok + ' booked. ' : '') + (c.bad ? c.bad + ' refused: fix and retry, or book in Quiqdash.' : '') + (SH.waiting ? ' ' + SH.waiting + ' will be retried by the shop automatically.' : '') + '</span>' +
        (c.bad ? '<button type="button" class="qq-btn" data-qqo-retry>Retry refused</button>' : '') +
        (c.ok ? '<button type="button" class="qq-btn" data-qqo-print>Print labels</button>' : '') +
        '<button type="button" class="qq-btn pri" data-qqo-close>Done</button>';
    }
    foot.innerHTML = (SH.phase !== 'review' ? '<div class="qqo-prog" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '" aria-label="Booking progress"><i style="width:' + pct + '%"></i></div>' : '') + '<div class="qqo-acts">' + acts + '</div>';
  }

  function close() {
    var s = document.getElementById('qqoSheet'), c = document.getElementById('qqoScrim');
    if (s) s.parentNode.removeChild(s);
    if (c) c.parentNode.removeChild(c);
    var opener = SH && SH.opener;
    SH = null;
    document.removeEventListener('keydown', onKey, true);
    if (opener && opener.isConnected) opener.focus();
  }

  function onKey(ev) {
    if (!SH) return;
    if (ev.key === 'Escape' && SH.phase !== 'sending') { ev.preventDefault(); close(); return; }
    if (ev.key === 'Tab') {
      var el = document.getElementById('qqoSheet');
      var f = el ? el.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),a[href]') : [];
      if (!f.length) return;
      if (ev.shiftKey && document.activeElement === f[0]) { ev.preventDefault(); f[f.length - 1].focus(); }
      else if (!ev.shiftKey && document.activeElement === f[f.length - 1]) { ev.preventDefault(); f[0].focus(); }
    }
  }

  async function preview() {
    var t = SH.token;
    SH.checking = true; paint();
    var r = await Q().call('quiqup-preview', { ids: SH.ids, defaults: SH.defs, rows: SH.rows });
    if (!sheetOpen(t)) return;
    SH.checking = false;
    if (r.status !== 200 || !r.body.ok) { Q().toast('bad', 'Could not prepare the bookings', Q().fail(r.status, r.body, 'use Quiqup')); close(); return; }
    SH.data = r.body; paint();
  }

  function open(ids, opener) {
    if (!Q()) return;
    if (!ids.length) { Q().toast('warn', 'Tick the orders first', 'Then choose Schedule with Quiqup.'); return; }
    if (ids.length > 100) { Q().toast('warn', 'Up to 100 orders at a time', ''); return; }
    close();
    SH = { token: ++token, ids: ids, data: null, rows: {}, defs: {}, phase: 'review', results: {}, done: 0, total: 0, opener: opener, waiting: 0 };
    var scrim = document.createElement('div');
    scrim.className = 'qqo-scrim'; scrim.id = 'qqoScrim';
    scrim.onclick = function () { if (SH && SH.phase !== 'sending') close(); };
    var el = document.createElement('aside');
    el.className = 'qqo-sheet'; el.id = 'qqoSheet';
    el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true'); el.setAttribute('aria-labelledby', 'qqoTitle');
    el.innerHTML = '<div class="qqo-head"><h2 id="qqoTitle">Schedule with Quiqup · ' + ids.length + ' order' + (ids.length === 1 ? '' : 's') + '</h2><span data-qqo-mode></span><button type="button" class="qqo-x" data-qqo-close aria-label="Close">×</button></div>' +
      '<div class="qqo-defs"></div><div class="qqo-sum" aria-live="polite"></div><div class="qqo-body"></div><div class="qqo-foot"></div>';
    document.body.appendChild(scrim);
    document.body.appendChild(el);
    document.addEventListener('keydown', onKey, true);
    el.querySelector('.qqo-x').focus();
    paint();
    preview();
  }

  function readInputs() {
    var el = document.getElementById('qqoSheet');
    if (!el) return;
    el.querySelectorAll('[data-qqo-f]').forEach(function (f) {
      var p = f.getAttribute('data-qqo-f').split(':');
      SH.rows[p[0]] = SH.rows[p[0]] || {};
      SH.rows[p[0]][p[1]] = f.value;
    });
    el.querySelectorAll('[data-qqo-def]').forEach(function (f) {
      var k = f.getAttribute('data-qqo-def');
      SH.defs[k] = f.type === 'checkbox' ? f.checked : f.value;
      if (SH.defs[k] === '' || SH.defs[k] === false) delete SH.defs[k];
    });
  }

  /* Book: write the queue in one request, then step until nothing is left.
     Each request is triggered by the previous answer; at most 40 rounds. */
  async function go(ids) {
    var t = SH.token;
    readInputs();
    SH.phase = 'sending';
    var r = await Q().call('quiqup-schedule', { ids: ids, defaults: SH.defs, rows: SH.rows });
    if (!sheetOpen(t)) return;
    if (r.status !== 200 || !r.body.ok) { SH.phase = 'review'; paint(); Q().toast('bad', 'Nothing was booked', Q().fail(r.status, r.body, 'book couriers')); return; }
    var queued = r.body.queued || [];
    (r.body.blocked || []).forEach(function (b) { SH.results[b.id] = { ok: false, job: 'blocked', error: (b.blocks[0] || {}).text || 'Blocked.' }; });
    queued.forEach(function (id) { SH.results[id] = { ok: false, job: 'queued' }; });
    SH.total = queued.length; SH.done = 0; paint();

    for (var round = 0; round < 40 && queued.length; round++) {
      var s = await Q().call('quiqup-step', { ids: queued });
      if (!sheetOpen(t)) return;
      if (s.status !== 200 || !s.body.ok) { Q().toast('bad', 'The shop stopped answering', Q().fail(s.status, s.body, 'book couriers') + ' The minute cron will finish the queue.'); break; }
      (s.body.results || []).forEach(function (x) { SH.results[x.order_id] = x; });
      SH.done = queued.filter(function (id) { var x = SH.results[id]; return x && x.job !== 'queued' && x.job !== 'creating'; }).length;
      SH.waiting = s.body.waiting || 0;
      paint();
      if (!s.body.remaining || (s.body.results || []).length === 0) break;
    }
    SH.phase = 'done'; paint();
    var c = counts();
    if (c.ok) Q().toast('ok', c.ok + ' booked with Quiqup', c.bad ? c.bad + ' refused: see the red rows.' : 'Labels are ready to print.');
    if (c.bad && !c.ok) Q().toast('bad', 'Quiqup refused ' + c.bad, (SH.data.rows.map(function (x) { return SH.results[x.id]; }).filter(function (x) { return x && !x.ok && x.error; })[0] || {}).error || '');
    if (typeof window.go === 'function' && document.getElementById('olClearSel')) { /* the list repaints itself on its own next load */ }
  }

  async function labels(ids) {
    if (!ids.length) { Q().toast('warn', 'Tick booked orders first', ''); return; }
    for (var i = 0; i < ids.length; i += 25) {
      var chunk = ids.slice(i, i + 25), res;
      try {
        res = await fetch(window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/quiqup-labels?ids=' + chunk.join(','), { credentials: 'same-origin', headers: { 'Accept': 'application/pdf, application/json' } });
      } catch (e) { Q().toast('bad', 'Labels did not download', 'The shop did not answer.'); return; }
      var type = res.headers.get('Content-Type') || '';
      if (res.status === 404) { Q().toast('bad', 'No labels', 'The Quiqup label address is not in the server\'s route table yet. Platform → Cache → Clear everything, then try again.'); return; }
      if (res.status !== 200 || type.indexOf('application/pdf') !== 0) {
        var j = {}; try { j = await res.json(); } catch (e) {}
        Q().toast('bad', 'No labels', Q().fail(res.status, j, 'print labels'));
        return;
      }
      var blob = await res.blob(), a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'quiqup-labels-' + (i / 25 + 1) + '.pdf';
      document.body.appendChild(a); a.click(); a.parentNode.removeChild(a);
      (function (u) { setTimeout(function () { URL.revokeObjectURL(u); }, 4000); })(a.href);
    }
    Q().toast('ok', 'Labels downloaded', ids.length > 25 ? 'One PDF per 25 orders.' : '');
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target, b;
    if (!t || !t.closest) return;
    if ((b = t.closest('[data-qqo-open]'))) { open(selected(), b); return; }
    if ((b = t.closest('[data-qqo-labels]'))) { labels(selected()); return; }
    if (!t.closest('#qqoSheet') || !SH) return;
    if (t.closest('[data-qqo-close]')) { close(); return; }
    if (t.closest('[data-qqo-recheck]')) { readInputs(); preview(); return; }
    if (t.closest('[data-qqo-go]')) {
      readInputs();
      go(SH.data.rows.filter(function (r) { return rowState(r) === 'ready'; }).map(function (r) { return r.id; }));
      return;
    }
    if (t.closest('[data-qqo-retry]')) {
      var bad = SH.data.rows.filter(function (r) { return rowState(r) === 'bad'; }).map(function (r) { return r.id; });
      bad.forEach(function (id) { delete SH.results[id]; });
      SH.phase = 'review'; preview();
      return;
    }
    if (t.closest('[data-qqo-print]')) { labels(SH.data.rows.filter(function (r) { return rowState(r) === 'ok'; }).map(function (r) { return r.id; })); }
  });

  /* ── the Delivery card on an order ─────────────────────────────────── */
  var openedId = null, card = null, cardFor = null, cardBusy = '';

  document.addEventListener('click', function (ev) {
    var el = ev.target && ev.target.closest ? ev.target.closest('[data-olview]') : null;
    if (el && /^[0-9]+$/.test(el.getAttribute('data-olview') || '')) openedId = el.getAttribute('data-olview');
  }, true);

  function heading() {
    var h = document.querySelector('#content .page-head h2');
    if (!h) return null;
    var tx = (h.textContent || '').trim(), at = tx.indexOf('#');
    return tx.indexOf('Order') === 0 && at >= 0 ? tx.slice(at + 1).trim() : null;
  }

  function when(iso) {
    if (!iso) return '';
    try { return new Date(iso).toLocaleString('en-GB', { timeZone: 'Asia/Dubai', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); } catch (e) { return iso; }
  }

  function cardHtml() {
    var e = Q().esc, c = card, s = c.shipment;
    var h = '<div class="card pad qqo-card" data-qqo-card><div class="qq-card" style="border:0;box-shadow:none;padding:0;margin:0"><h3>Delivery · Quiqup ' +
      (s ? chip(s.env === 'live' ? 'live' : 'testing', s.env === 'live' ? 'LIVE' : 'STAGING') : '') + (c.is_test ? chip('testing', 'Test order') : '') + '</h3>';
    if (!s) {
      h += '<p class="qq-help">Not booked. Tick it on Store → Orders and choose Schedule with Quiqup.</p>';
    } else {
      h += '<dl class="qqo-kv"><dt>Status</dt><dd><b>' + e(s.job === 'created' || s.job === 'cancelled' && s.state ? s.state_label || s.job : ({ failed: 'Booking failed', queued: 'Waiting to send', creating: 'Sending to Quiqup…', cancelled: 'Cancelled' })[s.job] || s.job) + '</b>' + (s.attempts ? ' · attempt ' + e(s.attempts) : '') + '</dd>' +
        (s.qq_id ? '<dt>Quiqup #</dt><dd>' + e(s.qq_id) + (s.tracking_url ? ' · <a href="' + e(s.tracking_url) + '" target="_blank" rel="noopener noreferrer">Track ↗</a>' : '') + '</dd>' : '') +
        '<dt>Service</dt><dd>' + e(s.service) + ' · ' + e(s.payment) + ' · ' + e(s.parcels) + ' parcel' + (s.parcels === 1 ? '' : 's') + '</dd>' +
        (s.delivery_before ? '<dt>Expected by</dt><dd>' + e(when(s.delivery_before)) + '</dd>' : '') +
        (s.reason ? '<dt>Courier note</dt><dd>' + e(s.reason) + '</dd>' : '') +
        (s.error && s.job !== 'created' ? '<dt>Last error</dt><dd style="color:#a8261d">' + e(s.error) + '</dd>' : '') + '</dl>';
      if (c.events.length) {
        h += '<ol class="qqo-tl">' + c.events.slice().reverse().slice(0, 8).map(function (ev) {
          return '<li><span></span><div>' + e(ev.label || ev.state) + '<small>' + e(when(ev.at)) + ' · ' + e(ev.source) + '</small></div></li>';
        }).join('') + '</ol>';
      }
    }
    h += '<div class="qq-row">';
    if (s && s.qq_id) h += '<button type="button" class="qq-btn" data-qqo-c="refresh"' + (cardBusy ? ' disabled' : '') + '>Refresh from Quiqup</button>';
    if (c.can_ship && s && s.job === 'created') h += '<button type="button" class="qq-btn" data-qqo-c="label">Label</button>';
    if (c.can_ship && s && s.cancellable) h += '<button type="button" class="qq-btn" data-qqo-c="rebook"' + (cardBusy ? ' disabled' : '') + '>Re-book…</button><button type="button" class="qq-btn danger" data-qqo-c="cancel"' + (cardBusy ? ' disabled' : '') + '>Cancel delivery…</button>';
    if (s && s.job === 'created' && !s.cancellable && s.state && ['delivery_complete', 'returned_to_origin', 'cancelled'].indexOf(s.state) < 0) h += '<a class="qq-btn" href="' + e(s.dashboard) + '" target="_blank" rel="noopener noreferrer">Change in Quiqdash ↗</a>';
    if (c.can_flag && c.mode !== 'live') h += '<label class="qq-chk" style="margin-inline-start:auto"><input type="checkbox" data-qqo-c="flag"' + (c.is_test ? ' checked' : '') + '>Test order</label>';
    return h + '</div></div></div>';
  }

  function drawCard() {
    var side = document.querySelector('#content .odside');
    if (!side || !card || cardFor !== heading()) return;
    var old = side.querySelector('[data-qqo-card]'), tmp = document.createElement('div');
    tmp.innerHTML = cardHtml();
    if (old) side.replaceChild(tmp.firstChild, old); else side.insertBefore(tmp.firstChild, side.firstChild);
  }

  var loadingFor = null;
  async function loadCard(num) {
    if (!openedId || loadingFor === openedId + num || !Q()) return;
    loadingFor = openedId + num;
    var r = await Q().call('quiqup-shipment?order=' + encodeURIComponent(openedId));
    loadingFor = null;
    if (r.status !== 200 || !r.body.ok || r.body.order_number !== heading()) return;
    card = r.body; cardFor = r.body.order_number;
    drawCard();
  }

  async function cardAction(what, input) {
    var Qq = Q(), id = openedId, num = cardFor, r;
    if (what === 'label') { labels([parseInt(id, 10)]); return; }
    if (what === 'flag') {
      r = await Qq.call('quiqup-test-order', { order_id: parseInt(id, 10), on: input.checked });
      if (r.status === 200 && r.body.ok) { card.is_test = r.body.test; Qq.toast('ok', r.body.test ? 'Marked as a test order' : 'No longer a test order', ''); }
      else { input.checked = !input.checked; Qq.toast('bad', 'Not changed', Qq.fail(r.status, r.body, 'flag test orders')); }
      if (cardFor === num) drawCard();
      return;
    }
    if (what === 'cancel' || what === 'rebook') {
      var yes = await Qq.dialog(what === 'cancel'
        ? '<h3>Cancel this delivery at Quiqup?</h3><p>The courier booking is cancelled. The order itself is not changed: it stays ' + Qq.esc('as it is') + ', and nothing is refunded.</p>'
        : '<h3>Re-book this delivery?</h3><p>The current Quiqup booking is cancelled and a new one is made with today\'s pickup and defaults. Use this to change the service, the cash amount or the parcels.</p>',
        [{ label: 'Keep it', value: false }, { label: what === 'cancel' ? 'Cancel delivery' : 'Re-book', value: true, cls: what === 'cancel' ? 'danger' : 'pri' }]);
      if (!yes) return;
    }
    cardBusy = what; drawCard();
    r = await Qq.call('quiqup-' + what, { order_id: parseInt(id, 10) });
    cardBusy = '';
    if (cardFor !== num || heading() !== num) return;
    if (r.status === 200 && r.body.ok) { if (r.body.shipment) card = Object.assign({}, card, r.body.shipment); Qq.toast('ok', r.body.message || 'Done', ''); }
    else Qq.toast('bad', what === 'refresh' ? 'Quiqup did not answer' : 'Quiqup refused', Qq.fail(r.status, r.body, 'change this delivery'));
    drawCard();
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target && ev.target.closest ? ev.target.closest('[data-qqo-c]') : null;
    if (!b || !b.closest('[data-qqo-card]') || b.tagName === 'INPUT') return;
    cardAction(b.getAttribute('data-qqo-c'), b);
  });
  document.addEventListener('change', function (ev) {
    var b = ev.target;
    if (b && b.matches && b.matches('input[data-qqo-c=flag]')) cardAction('flag', b);
  });

  /* ── one observer, one check per frame ─────────────────────────────── */
  var pending = false;
  function inject() {
    pending = false;
    if (document.getElementById('olClearSel')) addBar();
    var side = document.querySelector('#content .odside');
    if (side && !side.querySelector('[data-qqo-card]')) {
      var num = heading();
      if (num && card && cardFor === num) drawCard(); else if (num) loadCard(num);
    }
  }
  function start() {
    var host = document.getElementById('content');
    if (!host || typeof window.MutationObserver !== 'function') return;
    new window.MutationObserver(function () {
      if (pending) return;
      pending = true;
      (window.requestAnimationFrame || function (f) { setTimeout(f, 16); })(inject);
    }).observe(host, { childList: true, subtree: true });
    inject();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
