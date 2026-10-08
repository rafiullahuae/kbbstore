
(function () {
  'use strict';
  var S = { data: null, busy: '', running: false, stopAsked: false, msg: '', bad: false, ip: '', http: false, loading: false };

  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/urls-media/old-server'; }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }

  async function call(body) {
    var o = { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    try {
      var r = await fetch(base(), o), j = null;
      try { j = await r.json(); } catch (e) { j = null; }
      return { status: r.status, body: j || {} };
    } catch (e) { return { status: 0, body: { message: 'The shop could not be reached. Check your connection; press Resume to carry on.' } }; }
  }
  function why(r) {
    if (r.status === 403) return 'Your role cannot do this (it needs the Import permission).';
    if (r.status === 404) return 'Not in the route table yet: Platform → Cache → Clear everything, then reload.';
    if (r.status === 419) return 'Your session expired. Reload the page and sign in again.';
    return (r.body && r.body.message) || ('Something went wrong (' + r.status + '). Press Resume to carry on.');
  }
  function take(b) {
    var s = b && (b.summary || (b.missing !== undefined ? b : null));
    if (s) { S.data = Object.assign({}, s, { missing_list: b.missing_list || (S.data && S.data.missing_list) || [] }); }
    if (S.data && !S.ip) S.ip = S.data.from_ip || '';
  }

  function panel() {
    var d = S.data;
    var h = '<div class="opx" data-opx-root><h4>Fetch missing pictures from the old server</h4>'
      + '<p>Some product pictures were never copied from the old WordPress server (Hostinger). Since kbeautybliss.com now points here, they show as blank. '
      + 'This asks the old server <b>by its address</b> for every picture the shop names but does not have, and saves each one at the same path — the links already in your products start working, nothing else changes.</p>'
      + '<div class="opx-warn"><b>Do not cancel Hostinger until this shows 0 missing.</b></div>';
    if (!d) return h + '<div class="opx-msg">' + (S.loading ? 'Loading…' : esc(S.msg || 'Loading…')) + '</div></div>';
    var never = !d.scanned_at;
    var lost = (d.gone || 0) + (d.refused || 0);
    var total = (d.fetched || 0) + (d.missing || 0);
    var pct = total ? Math.round((d.fetched || 0) / total * 100) : 100;
    h += '<div class="opx-grid">'
      + '<div class="opx-n ' + (d.missing ? 'is-bad' : 'is-good') + '"><b>' + (never ? '–' : num(d.missing)) + '</b><span>missing</span></div>'
      + '<div class="opx-n is-good"><b>' + num(d.fetched) + '</b><span>fetched</span></div>'
      + '<div class="opx-n"><b>' + num(d.remaining) + '</b><span>still to try</span></div>'
      + '<div class="opx-n' + (d.failed ? ' is-bad' : '') + '"><b>' + num(d.failed) + '</b><span>failed (can retry)</span></div>'
      + '<div class="opx-n' + (lost ? ' is-bad' : '') + '"><b>' + num(lost) + '</b><span>lost (404 there too) or refused</span></div>'
      + '</div>'
      + '<div class="opx-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><div class="opx-fill" style="width:' + pct + '%"></div></div>';
    if (never) h += '<p>Not checked yet. <b>Check</b> counts what is missing and who uses it, without contacting anything.</p>';
    else {
      var hosts = Object.keys(d.by_host || {});
      h += '<p>' + num(d.referenced) + ' picture(s) named in products, galleries, descriptions, banners, pages and settings; ' + num(d.present) + ' on this server.'
        + (hosts.length ? ' Missing, by the address they are named with: ' + hosts.map(function (k) { return esc(k) + ' ' + num(d.by_host[k]); }).join(' · ') + '.' : '') + '</p>';
    }
    var off = S.busy !== '' || S.running;
    h += '<div class="opx-row"><label>Old server address <input type="text" data-opx-ip inputmode="decimal" autocomplete="off" spellcheck="false" value="' + esc(S.ip) + '"' + (off ? ' disabled' : '') + '></label>'
      + '<label><input type="checkbox" data-opx-http' + (S.http ? ' checked' : '') + (off ? ' disabled' : '') + '> Use plain HTTP (only if the certificate fails)</label></div>'
      + '<div class="opx-row">'
      + '<button type="button" class="btn" data-opx="check"' + (off ? ' disabled' : '') + '>' + (S.busy === 'check' ? 'Checking…' : 'Check (counts only)') + '</button>'
      + (S.running
        ? '<button type="button" class="btn" data-opx="stop"' + (S.stopAsked ? ' disabled' : '') + '>' + (S.stopAsked ? 'Stopping…' : 'Stop') + '</button>'
        : '<button type="button" class="btn primary" data-opx="fetch"' + (off || (!never && !d.remaining) ? ' disabled' : '') + '>'
          + ((d.run && d.run.state === 'stopped') || (d.fetched && d.remaining) ? 'Resume' : 'Fetch missing pictures') + '</button>')
      + (d.failed ? '<button type="button" class="btn" data-opx="retry"' + (off ? ' disabled' : '') + '>Retry the ' + num(d.failed) + ' failed</button>' : '')
      + '<a class="btn ghost" href="' + esc(base()) + '.csv">Download the list</a>'
      + '</div>';
    if (S.msg) h += '<div class="opx-msg' + (S.bad ? ' is-bad' : '') + '" role="status">' + esc(S.msg) + '</div>';
    var list = d.missing_list || [];
    if (list.length) {
      h += '<details style="margin-top:9px"' + (lost && !S.running ? ' open' : '') + '><summary style="cursor:pointer;font-size:12.5px">The ' + num(d.missing) + ' missing picture(s), why, and where they are used</summary><ul class="opx-list">'
        + list.slice(0, 100).map(function (r) {
          return '<li><code>' + esc(r.path) + '</code> — <b>' + esc(r.state === 'gone' ? 'not on the old server' : r.state) + '</b>' + (r.reason ? ': ' + esc(r.reason) : '')
            + (r.owners && r.owners.length ? '<br><span style="color:var(--ink-soft,#64748b)">Used by ' + esc(r.owners.join('; ')) + '</span>' : '') + '</li>';
        }).join('') + '</ul>' + (d.missing > 100 ? '<p>Showing 100. “Download the list” has every one.</p>' : '') + '</details>';
    }
    h += '<div class="opx-cli">Thousands of files? Over SSH, in the app folder: <code>php artisan kbb:fetch-missing-pictures --from-ip=' + esc(S.ip || d.from_ip) + '</code></div>';
    return h + '</div>';
  }

  function paint() {
    document.querySelectorAll('[data-oldpics]').forEach(function (el) { el.innerHTML = panel(); });
  }

  async function load() {
    if (S.loading) return;
    S.loading = true; paint();
    var r = await call(null);
    S.loading = false;
    if (r.status === 200) take(r.body); else { S.msg = why(r); S.bad = true; }
    paint();
  }

  async function run() {
    S.running = true; S.stopAsked = false; S.bad = false; S.msg = 'Asking the old server…'; paint();
    var first = true;
    while (S.running) {
      var r = await call({ action: 'fetch', ip: S.ip, http: S.http, 'continue': !first });
      first = false;
      if (r.status !== 200 || !r.body.ok) { S.msg = why(r); S.bad = true; take(r.body); break; }
      take(r.body);
      S.msg = r.body.message || '';
      S.bad = !!r.body.tls_failed;
      if (S.stopAsked || r.body.stopped) { S.msg = 'Stopped. ' + num(S.data && S.data.remaining) + ' still to try. Press Resume to carry on from where it stopped.'; break; }
      if (!r.body.more) break;
      paint();
    }
    S.running = false; S.stopAsked = false; paint();
  }

  async function act(action) {
    if (action === 'fetch') { run(); return; }
    if (action === 'stop') {
      S.stopAsked = true; paint();
      var s = await call({ action: 'stop' });
      if (s.status === 200) { take(s.body); S.msg = s.body.message; }
      return;
    }
    S.busy = action; S.bad = false; paint();
    var r = await call({ action: action });
    S.busy = '';
    if (r.status === 200) { take(r.body); S.msg = r.body.message || ''; } else { S.msg = why(r); S.bad = true; }
    paint();
  }

  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('[data-opx]') : null;
    if (b && !b.disabled && b.closest('[data-oldpics]')) act(b.getAttribute('data-opx'));
  });
  document.addEventListener('input', function (e) {
    if (e.target && e.target.matches && e.target.matches('[data-opx-ip]')) S.ip = e.target.value.trim();
  });
  document.addEventListener('change', function (e) {
    if (e.target && e.target.matches && e.target.matches('[data-opx-http]')) S.http = !!e.target.checked;
  });

  /* Called by a screen after it has drawn its HTML: fills its [data-oldpics]. */
  window.kbbOldPictures = {
    mountAll: function (root) {
      if (!root || !root.querySelector('[data-oldpics]')) return;
      paint();
      if (!S.data && !S.loading) load();
    }
  };
})();
