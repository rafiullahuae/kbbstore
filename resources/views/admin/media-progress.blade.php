{{--
    The live migration progress page — Lane GD.

    "with a live progress url of everything." The owner's words, and this is the
    URL. It is a standalone document on purpose:

      * IT MUST WORK WHEN THE CONSOLE DOES NOT. Its whole job is to be
        trustworthy at the moment something has gone wrong, and making it a
        panel inside a 20,000-line bundle would make it depend on the thing most
        likely to be broken. It shares no state, no CSS and no script with
        app.blade.php.
      * NO BUILD STEP AND NO DEPENDENCY. package.json defines no `build` script
        (CLAUDE.md), assets are built by hand, and CI does not build them — so a
        page that needed compiling would ship broken. Everything here is inline.
      * POLLING, NOT SOCKETS. Shared hosting. The SERVER decides the interval
        and says so in the payload: three seconds while a run is going, fifteen
        when it is not, and polling stops entirely when the tab is hidden. A
        page that hammers a shared host every two seconds forever is a page that
        costs the owner money to watch nothing happen.

    THE THREE STATES ARE THE POINT. "Idle, 412 still to fetch", "Running, 118 of
    530" and "Stalled, the last batch did not come back" are different facts and
    a single bar renders all three identically. That identical rendering IS the
    silent half-success that MediaAudit's header refused a downloader over, so
    telling them apart is part of the answer to that objection, not decoration.

    EVERY VALUE FROM THE SERVER IS TEXT, NEVER HTML. Failure reasons quote the
    old host's URLs and the old host's error pages, which is content this shop
    does not control — see esc() below and impEsc() in the console, which exists
    for the same reason one level up.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Migration progress · K-Beauty Bliss</title>
<style>
:root{
  --bg:#f6f7f9; --card:#fff; --ink:#151a21; --soft:#6a7482; --line:#e4e8ee;
  --good:#15a85a; --good-bg:#eefaf3; --warn:#b5730b; --warn-bg:#fdf6e7;
  --bad:#c23b3b; --bad-bg:#fdeeee; --live:#2f6fe0; --live-bg:#eef3fd;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
  font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:1060px;margin:0 auto;padding:22px 16px 60px}
h1{font-size:21px;margin:0 0 4px}
.sub{color:var(--soft);margin:0 0 18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:14px}
.card h2{font-size:15px;margin:0 0 2px}
.card .why{color:var(--soft);font-size:12.5px;margin:0 0 10px}
.badge{display:inline-block;font-size:11.5px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;
  padding:3px 9px;border-radius:999px;vertical-align:2px;margin-left:8px}
.b-running{background:var(--live-bg);color:var(--live)}
.b-idle{background:#eef0f3;color:var(--soft)}
.b-stalled{background:var(--bad-bg);color:var(--bad)}
.b-done{background:var(--good-bg);color:var(--good)}
.b-attention{background:var(--warn-bg);color:var(--warn)}
.b-never{background:#eef0f3;color:var(--soft)}
.b-unknown{background:#eef0f3;color:var(--soft)}
.head{font-size:15px;font-weight:600;margin:6px 0 2px}
.bar{height:9px;border-radius:999px;background:#eceff3;overflow:hidden;margin:10px 0 6px}
.bar>i{display:block;height:100%;background:var(--good);transition:width .4s ease}
.bar.is-running>i{background:var(--live)}
.bar.is-stalled>i{background:var(--bad)}
.nums{display:flex;flex-wrap:wrap;gap:16px;color:var(--soft);font-size:12.5px}
.nums b{color:var(--ink);font-weight:600}
.btns{margin:14px 0 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
button{font:inherit;font-weight:600;border-radius:9px;border:1px solid var(--line);background:#fff;color:var(--ink);
  padding:8px 14px;cursor:pointer}
button.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
button.danger{color:var(--bad);border-color:#f0cccc}
button[disabled]{opacity:.45;cursor:default}
table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px}
th{text-align:left;color:var(--soft);font-weight:600;padding:6px 8px;border-bottom:1px solid var(--line)}
td{padding:7px 8px;border-bottom:1px solid #f1f3f6;vertical-align:top}
td.u{word-break:break-all;max-width:330px}
.tag{font-size:10.5px;font-weight:700;text-transform:uppercase;padding:2px 7px;border-radius:999px}
.t-failed{background:var(--warn-bg);color:var(--warn)}
.t-refused{background:var(--bad-bg);color:var(--bad)}
.note{background:#f8f9fb;border:1px solid var(--line);border-radius:9px;padding:10px 12px;color:var(--soft);font-size:12.5px}
.alert{border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:10px}
.a-bad{background:var(--bad-bg);color:#8a2b2b;border:1px solid #f0cccc}
.a-warn{background:var(--warn-bg);color:#7a4e07;border:1px solid #efdcb2}
.foot{color:var(--soft);font-size:12px;margin-top:22px}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--live);margin-right:6px;
  animation:p 1.1s infinite}
@keyframes p{0%,100%{opacity:.25}50%{opacity:1}}
@media(max-width:620px){td.u{max-width:160px}}
</style>
</head>
<body>
<div class="wrap">
  <h1>Migration progress</h1>
  <p class="sub">Everything this shop is still taking off the old WordPress site. This page refreshes itself.</p>

  <div id="alerts"></div>
  <div id="stages"></div>

  <div class="card">
    <h2>Failures and refusals</h2>
    <p class="why">Every picture that did not come across, with the reason. A <b>failure</b> is worth pressing
      Fetch again for; a <b>refusal</b> will not change on its own and needs a decision.</p>
    <div id="failures"></div>
    <div class="btns">
      <a href="sideload.csv"><button type="button">Download as a spreadsheet</button></a>
      <button type="button" id="retry">Try the failures again</button>
    </div>
  </div>

  <p class="foot" id="foot">&nbsp;</p>
</div>

<script>
(function(){
  'use strict';

  /* Relative to this page's own address, which is .../admin-api/urls-media/
     progress-page. That makes every call immune to KBB_BASE_PATH, to the admin
     path having been moved, and to the shop being served from a subfolder —
     three things that have each broken a link in this project before. */
  var API = function(p){ return new URL(p, window.location.href).toString(); };

  var token = document.querySelector('meta[name=csrf-token]').content;
  var snap = null;
  var looping = false;     // the owner pressed Fetch and wants it to keep going
  var busy = false;        // a request is in flight right now
  var timer = null;

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  /* Never throws on an HTTP status: a 507 (no disk) and a 500 mean different
     things to the loop and both have to reach it. Same contract as impApi() in
     the console, and for the same reason. */
  function call(path, opts){
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({
      'X-CSRF-TOKEN': token,
      'Accept': 'application/json'
    }, opts.headers || {});
    if (opts.method && opts.method !== 'GET') opts.headers['Content-Type'] = 'application/json';

    return fetch(API(path), opts).then(function(r){
      return r.text().then(function(text){
        var data = null;
        try { data = JSON.parse(text); } catch (e) {}
        return { status: r.status, data: data,
                 raw: data ? '' : text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,200) };
      });
    }).catch(function(e){
      return { status: 0, data: null, raw: String(e && e.message || e) };
    });
  }

  /* ------------------------------------------------------------- rendering */

  function num(n){ return (n == null ? '—' : String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')); }

  function stageCard(s){
    /* NO BAR WITHOUT A DENOMINATOR. The catalogue stage has none — nothing
       records how many rows a CSV holds until it has been read — and drawing it
       at 0/0 filled a full green bar under the words "No catalogue import has
       been run", which is the exact species of number this page exists to stop
       showing. A stage with no total gets its sentence and its counts, and no
       bar at all. */
    var hasBar = s.total > 0;
    var pct = hasBar ? Math.round((s.done / s.total) * 100) : 0;
    var barClass = s.state === 'running' ? 'bar is-running' : (s.state === 'stalled' ? 'bar is-stalled' : 'bar');

    var nums = '<div class="nums">'
      + '<span>done <b>' + num(s.done) + '</b></span>'
      + (s.total ? '<span>of <b>' + num(s.total) + '</b></span>' : '')
      + (s.remaining != null ? '<span>remaining <b>' + num(s.remaining) + '</b></span>' : '')
      + (s.failed != null ? '<span>failed <b>' + num(s.failed) + '</b></span>' : '')
      + (s.refused != null ? '<span>refused <b>' + num(s.refused) + '</b></span>' : '')
      + (s.bytes_label ? '<span>on disk <b>' + esc(s.bytes_label) + '</b></span>' : '')
      + '</div>';

    var extra = '';
    if (s.key === 'pictures') {
      extra = '<div class="nums" style="margin-top:6px">'
        + '<span>estimated still to download <b>' + esc(s.estimate_label) + '</b></span>'
        + '<span>free on the volume <b>' + esc(s.free_label) + '</b></span>'
        + (s.hosts && s.hosts.length ? '<span>from <b>' + esc(s.hosts.join(', ')) + '</b></span>' : '')
        + '</div>';
    }
    if (s.key === 'catalogue' && s.entities && s.entities.length) {
      extra = '<table><tr><th>entity</th><th>rows</th><th>created</th><th>updated</th><th>refused</th><th>finished</th></tr>';
      s.entities.forEach(function(e){
        extra += '<tr><td>' + esc(e.entity) + '</td><td>' + num(e.processed) + '</td><td>' + num(e.created)
          + '</td><td>' + num(e.updated) + '</td><td>' + num(e.rejected) + '</td><td>'
          + (e.finished_at ? esc(e.finished_at) : '—') + '</td></tr>';
      });
      extra += '</table>';
    }
    if (s.key === 'paths' && s.breakdown) {
      extra = '<div class="nums" style="margin-top:6px">'
        + '<span>on disk <b>' + num(s.breakdown.present) + '</b></span>'
        + '<span>missing <b>' + num(s.breakdown.missing) + '</b></span>'
        + '<span>still on the old site <b>' + num(s.breakdown.remote) + '</b></span></div>';
    }

    var buttons = '';
    if (s.key === 'pictures') {
      buttons = '<div class="btns">'
        + '<button type="button" class="primary" id="fetch"></button>'
        + '<button type="button" id="stop">Stop</button>'
        + '<button type="button" id="once">One batch only</button>'
        + '</div>';
    }

    return '<div class="card">'
      + '<h2>' + esc(s.title) + '<span class="badge b-' + esc(s.state) + '">'
      + (s.state === 'running' ? '<span class="dot"></span>' : '') + esc(s.state) + '</span></h2>'
      + '<div class="head">' + esc(s.headline) + '</div>'
      + (hasBar ? '<div class="' + barClass + '"><i style="width:' + pct + '%"></i></div>' : '')
      + nums + extra
      + (s.note ? '<p class="why" style="margin-top:10px">' + esc(s.note) + '</p>' : '')
      + buttons
      + '</div>';
  }

  function failureRows(rows){
    if (!rows || !rows.length) {
      return '<div class="note">Nothing has failed or been refused.</div>';
    }
    var html = '<table><tr><th>what</th><th>address</th><th>tries</th><th>status</th><th>why</th></tr>';
    rows.forEach(function(f){
      html += '<tr><td><span class="tag t-' + esc(f.state) + '">' + esc(f.state) + '</span></td>'
        + '<td class="u">' + esc(f.url) + '</td>'
        + '<td>' + num(f.attempts) + '</td>'
        + '<td>' + (f.status_code == null ? '—' : esc(f.status_code)) + '</td>'
        + '<td>' + esc(f.reason) + '</td></tr>';
    });
    return html + '</table>';
  }

  function paint(){
    if (!snap) return;

    var alerts = '';
    var pictures = snap.stages.filter(function(s){ return s.key === 'pictures'; })[0];

    if (pictures && pictures.enough_room === false) {
      alerts += '<div class="alert a-bad"><b>Not enough room on the disk.</b> '
        + esc(pictures.estimate_label) + ' is the estimate for what is left and the volume has '
        + esc(pictures.free_label) + ' free. Nothing will be written until there is space — '
        + 'half-filling the volume would take the whole site down, not just the pictures.</div>';
    }
    if (pictures && pictures.state === 'stalled') {
      alerts += '<div class="alert a-warn"><b>The last batch did not come back.</b> '
        + 'That is almost always the host cutting the request off at its time limit. '
        + 'Nothing is lost and nothing is half-written — press Fetch and it continues from exactly the '
        + 'pictures that are not yet on disk.</div>';
    }
    document.getElementById('alerts').innerHTML = alerts;

    document.getElementById('stages').innerHTML = snap.stages.map(stageCard).join('');
    document.getElementById('failures').innerHTML =
      failureRows(snap.sideload && snap.sideload.failures);

    var fetchBtn = document.getElementById('fetch');
    if (fetchBtn) {
      var left = pictures ? pictures.remaining : 0;
      fetchBtn.textContent = looping ? 'Fetching…'
        : (left > 0 ? 'Fetch the pictures (' + num(left) + ' to go)' : 'Nothing left to fetch');
      fetchBtn.disabled = looping || left === 0;
      document.getElementById('stop').disabled = !looping;
      document.getElementById('once').disabled = looping || left === 0;
      fetchBtn.onclick = function(){ looping = true; paint(); step(); };
      document.getElementById('once').onclick = function(){ looping = false; step(); };
      document.getElementById('stop').onclick = function(){
        looping = false;
        call('sideload', { method: 'POST', body: JSON.stringify({ action: 'stop' }) }).then(refresh);
      };
    }

    document.getElementById('retry').onclick = function(){
      call('sideload', { method: 'POST', body: JSON.stringify({ action: 'retry' }) }).then(refresh);
    };

    document.getElementById('foot').textContent =
      'Last read ' + new Date().toLocaleTimeString() + '. '
      + (snap.poll ? 'Refreshing every ' + snap.poll_seconds + 's while a run is going.'
                   : 'Refreshing every ' + snap.poll_seconds + 's.');
  }

  /* ---------------------------------------------------------------- the loop */

  function refresh(){
    return call('progress').then(function(r){
      if (r.data && r.data.ok) { snap = r.data; paint(); }
      else {
        document.getElementById('alerts').innerHTML =
          '<div class="alert a-bad">Could not read progress: ' + esc(r.raw || ('HTTP ' + r.status)) + '</div>';
      }
      schedule();
    });
  }

  /* One bounded batch, then decide whether to ask for another. The decision is
     made HERE and from the server's own remaining count — not from a client-side
     tally, which is the thing that goes stale when a request is killed. */
  function step(){
    if (busy) return;
    busy = true;

    call('sideload', { method: 'POST', body: JSON.stringify({ action: 'fetch' }) })
      .then(function(r){
        busy = false;

        if (!r.data || r.data.ok !== true) {
          looping = false;
          document.getElementById('alerts').innerHTML =
            '<div class="alert a-bad"><b>The batch stopped.</b> '
            + esc((r.data && r.data.stopped) || r.raw || ('HTTP ' + r.status)) + '</div>';
          return refresh();
        }

        return refresh().then(function(){
          var left = r.data.plan ? r.data.plan.remaining : 0;
          /* Stop looping when there is nothing left, and ALSO when a whole
             batch fetched nothing at all — otherwise a set of references that
             all fail would spin forever against the old host. */
          if (looping && left > 0 && (r.data.fetched > 0 || r.data.refused > 0)) {
            setTimeout(step, 250);
          } else {
            looping = false;
            paint();
          }
        });
      });
  }

  function schedule(){
    if (timer) clearTimeout(timer);
    if (document.hidden) return;                  // do not poll a tab nobody is looking at
    var seconds = (snap && snap.poll_seconds) || 15;
    timer = setTimeout(refresh, seconds * 1000);
  }

  document.addEventListener('visibilitychange', function(){
    if (!document.hidden) refresh();
    else if (timer) clearTimeout(timer);
  });

  refresh();
})();
</script>
</body>
</html>
