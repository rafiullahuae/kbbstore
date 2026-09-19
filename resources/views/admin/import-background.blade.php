{{--
    The import that keeps going with the tab closed — Lane GO.

    > "i want this job must be running in background, even i close the tab."

    This is the page the owner opens to see that it did. It is a standalone
    document, for the three reasons Lane GD's media-progress.blade.php gives and
    which apply here word for word:

      * IT MUST WORK WHEN THE CONSOLE DOES NOT. Its job is to be trustworthy at
        the moment something has gone wrong, and a panel inside a 20,000-line
        bundle depends on the thing most likely to be broken. It shares no
        state, no CSS and no script with app.blade.php — which this lane is also
        forbidden to edit, so this page is additionally how the feature is
        reachable at all before the integrator wires a button into the console.
      * NO BUILD STEP. package.json defines no `build` script and CI does not
        build assets (CLAUDE.md), so anything needing compilation would ship
        broken. Everything here is inline.
      * POLLING, NOT SOCKETS. Shared hosting. Three seconds while something is
        going, fifteen when it is not, and nothing at all while the tab is
        hidden — a page that hammers a shared host forever to watch nothing
        happen costs the owner money.

    THE STATES ARE THE POINT, and they are deliberately the same vocabulary Lane
    GD's page uses, so that the two read as one system:

        RUNNING  the server is importing and the last piece came back recently
        STALLED  a piece did not come back. Opening this page picks it up
        HALTED   the chain stopped on purpose and says why
        PAUSED   the owner pressed Pause
        IDLE     a run exists but a browser is driving it
        FINISHED the run is over

    A bar frozen at a stale number rendered identically to a live one is the
    silent half-success this whole design is against. A chain that cannot
    continue says so.

    EVERY VALUE FROM THE SERVER IS TEXT, NEVER HTML. The notes quote messages
    from the importer, which quote refused cells, which contain whatever was in
    the owner's WooCommerce database — impEsc() in the console exists for the
    same reason one level up.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Import progress · K-Beauty Bliss</title>
<style>
:root{
  --bg:#f6f7f9; --card:#fff; --ink:#151a21; --soft:#6a7482; --line:#e4e8ee;
  --good:#15a85a; --good-bg:#eefaf3; --warn:#b5730b; --warn-bg:#fdf6e7;
  --bad:#c23b3b; --bad-bg:#fdeeee; --live:#2f6fe0; --live-bg:#eef3fd;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
  font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:1020px;margin:0 auto;padding:22px 16px 60px}
h1{font-size:21px;margin:0 0 4px}
.sub{color:var(--soft);margin:0 0 18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:14px}
.card h2{font-size:15px;margin:0 0 2px}
.why{color:var(--soft);font-size:12.5px;margin:0 0 10px}
.badge{display:inline-block;font-size:11.5px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;
  padding:3px 9px;border-radius:999px;vertical-align:2px;margin-left:8px}
.b-running{background:var(--live-bg);color:var(--live)}
.b-idle,.b-unavailable{background:#eef0f3;color:var(--soft)}
.b-stalled,.b-halted{background:var(--bad-bg);color:var(--bad)}
.b-finished{background:var(--good-bg);color:var(--good)}
.b-paused{background:var(--warn-bg);color:var(--warn)}
.head{font-size:15px;font-weight:600;margin:6px 0 2px}
.bar{height:9px;border-radius:999px;background:#eceff3;overflow:hidden;margin:10px 0 6px}
.bar>i{display:block;height:100%;background:var(--good);transition:width .4s ease}
.bar.is-running>i{background:var(--live)}
.bar.is-stalled>i,.bar.is-halted>i{background:var(--bad)}
.nums{display:flex;flex-wrap:wrap;gap:16px;color:var(--soft);font-size:12.5px}
.nums b{color:var(--ink);font-weight:600}
.btns{margin:14px 0 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
button{font:inherit;padding:8px 14px;border-radius:8px;border:1px solid var(--line);background:#fff;cursor:pointer}
button.primary{background:var(--live);border-color:var(--live);color:#fff;font-weight:600}
button[disabled]{opacity:.5;cursor:default}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
th,td{text-align:left;padding:7px 8px;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--soft);font-weight:600;font-size:12px}
td.n{text-align:right;font-variant-numeric:tabular-nums}
.alert{border-radius:10px;padding:11px 13px;margin-bottom:12px;font-size:13px}
.a-bad{background:var(--bad-bg);color:#7d2020;border:1px solid #f3cccc}
.a-warn{background:var(--warn-bg);color:#75500a;border:1px solid #f0e0b8}
.a-good{background:var(--good-bg);color:#0e6b3a;border:1px solid #c7ecd7}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--live);margin-right:6px;
  animation:pulse 1.4s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
.foot{color:var(--soft);font-size:12px;margin-top:18px}
</style>
</head>
<body>
<div class="wrap">
  <h1>Import progress</h1>
  <p class="sub">What the shop is importing right now, whether or not anything has this page open.</p>

  <div id="alerts"></div>
  <div id="chain" class="card"></div>
  <div id="overall" class="card"></div>
  <div id="files" class="card"></div>

  <p class="foot" id="foot"></p>
</div>

<script>
(function () {
  'use strict';

  /*
   * The admin-api base, worked out from this page's own URL rather than
   * written down. The production host serves this application under
   * /kbb-upgrade (KBB_BASE_PATH), and a hard-coded /admin-api would 404 there.
   *
   * TWO SEGMENTS COME OFF, NOT ONE, and getting that wrong is not a subtle
   * failure — it is a page that renders its frame and then stays empty forever,
   * because every poll 404s at <base>/admin-api/import/import/background. It
   * was written with one segment, it looked right, and the browser rehearsal is
   * what found it. So the suffix is matched by name rather than counted, and
   * the page says so out loud if the address it is served from is not the one
   * it expects, instead of silently showing nothing.
   */
  var PAGE = /\/import\/background-page\/?$/;
  var API = window.location.pathname.replace(/\/+$/, '').replace(PAGE, '');

  if (API === window.location.pathname.replace(/\/+$/, '')) {
    document.getElementById('alerts').innerHTML =
      '<div class="alert a-bad"><b>This page is being served from an address it does not recognise (' +
      window.location.pathname + '), so it cannot work out where to ask for progress. ' +
      'It belongs at &lt;admin-api&gt;/import/background-page.</b></div>';
  }

  var snap = null;
  var timer = null;
  var busy = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function num(n) { return Number(n || 0).toLocaleString('en-US'); }

  function token() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  /* Never throws on an HTTP error: a 409 and a 500 mean different things here
     and both have to reach the screen. */
  function call(path, opts) {
    var o = Object.assign({ credentials: 'same-origin', headers: {} }, opts || {});
    o.headers = Object.assign({ 'X-CSRF-TOKEN': token(), 'Accept': 'application/json' }, o.headers);
    if (o.method && o.method !== 'GET') o.headers['Content-Type'] = 'application/json';

    return fetch(API + path, o).then(function (r) {
      return r.text().then(function (t) {
        var d = null;
        try { d = JSON.parse(t); } catch (e) { /* an error page, not an answer */ }
        return { status: r.status, data: d, raw: t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240) };
      });
    }, function (e) {
      return { status: 0, data: null, raw: String((e && e.message) || e) };
    });
  }

  function chainCard(c) {
    var running = c.state === 'running';

    return '<h2>The run<span class="badge b-' + esc(c.state) + '">'
      + (running ? '<span class="dot"></span>' : '') + esc(c.state) + '</span></h2>'
      + '<p class="why">' + esc(c.note) + '</p>'
      + '<div class="nums">'
      + '<span>pieces done <b>' + num(c.slices) + '</b></span>'
      + '<span>rows per piece <b>' + num(c.rows_per_slice) + '</b></span>'
      + (c.seconds_since_beat == null ? '' : '<span>last piece <b>' + num(c.seconds_since_beat) + 's ago</b></span>')
      + (c.fails ? '<span>failed in a row <b>' + num(c.fails) + '</b></span>' : '')
      + (c.mode ? '<span>mode <b>' + esc(c.mode) + '</b></span>' : '')
      + '</div>'
      + '<div class="btns">'
      + '<button id="go" class="primary"></button>'
      + '<button id="pause">Pause</button>'
      + '<button id="stop">Stop</button>'
      + '</div>';
  }

  function overallCard(o, state) {
    if (o.total == null) {
      return '<h2>Everything</h2><p class="why">No total for the whole export: one or more files has no row '
        + 'count that can be believed, so a single bar across all of them would run ahead of the work and '
        + 'settle at 100% with a file still to come. Each file that does have a count has its own bar below.</p>';
    }

    return '<h2>Everything</h2>'
      + '<div class="head">' + num(o.done) + ' of ' + num(o.total) + ' rows</div>'
      + '<div class="bar is-' + esc(state) + '"><i style="width:' + Number(o.percent || 0) + '%"></i></div>'
      + '<div class="nums"><span><b>' + Number(o.percent || 0) + '%</b></span></div>';
  }

  function filesCard(entities) {
    var rows = entities.filter(function (e) { return e.present; }).map(function (e) {
      return '<tr><td>' + esc(e.label) + '</td>'
        + '<td class="n">' + num(e.processed) + '</td>'
        + '<td class="n">' + (e.denominator == null ? '—' : num(e.denominator)) + '</td>'
        + '<td class="n">' + (e.percent == null ? '—' : e.percent + '%') + '</td>'
        + '<td class="n">' + num(e.created) + '</td>'
        + '<td class="n">' + num(e.updated) + '</td>'
        + '<td class="n">' + num(e.unchanged) + '</td>'
        + '<td class="n">' + num(e.rejected) + '</td>'
        + '<td>' + (e.finished ? 'done' : '') + '</td></tr>';
    }).join('');

    if (!rows) return '<h2>Files</h2><p class="why">No export files are uploaded.</p>';

    return '<h2>Files</h2>'
      + '<p class="why">Read straight out of the checkpoints, which were committed in the same transaction '
      + 'as the rows they count — so these numbers survive a request that was killed.</p>'
      + '<table><thead><tr><th>File</th><th class="n">Rows done</th><th class="n">Of</th><th class="n">%</th>'
      + '<th class="n">New</th><th class="n">Changed</th><th class="n">Same</th><th class="n">Refused</th>'
      + '<th></th></tr></thead><tbody>' + rows + '</tbody></table>';
  }

  function alerts(c) {
    if (c.state === 'stalled') {
      return '<div class="alert a-warn"><b>The last piece did not come back.</b> That is almost always the '
        + 'host cutting the request off at its time limit. Nothing is lost and nothing is half-written, and '
        + 'nothing has to be pressed — leaving this page open picks it back up from the row after the last '
        + 'one that was committed, as soon as the piece that was killed has let go of its claim.</div>';
    }
    if (c.state === 'halted') {
      return '<div class="alert a-bad"><b>The background run has stopped.</b> ' + esc(c.note) + '</div>';
    }
    if (c.state === 'unavailable') {
      return '<div class="alert a-bad"><b>Not available on this shop yet.</b> ' + esc(c.note) + '</div>';
    }
    if (c.state === 'finished') {
      return '<div class="alert a-good"><b>' + esc(c.note) + '.</b> Nothing it imported was undone.</div>';
    }
    return '';
  }

  function paint() {
    if (!snap) return;

    var c = snap.chain;

    document.getElementById('alerts').innerHTML = alerts(c);
    document.getElementById('chain').innerHTML = chainCard(c);
    document.getElementById('overall').innerHTML = overallCard(snap.overall, c.state);
    document.getElementById('files').innerHTML = filesCard(snap.entities);

    var go = document.getElementById('go');
    var pause = document.getElementById('pause');
    var stop = document.getElementById('stop');

    var over = c.state === 'finished' || c.state === 'unavailable';
    var goes = !over && c.state !== 'running';

    go.textContent = c.state === 'paused' ? 'Resume in the background'
      : (c.state === 'running' ? 'Running on the server' : 'Keep importing in the background');
    go.disabled = busy || !goes;
    pause.disabled = busy || over || c.state === 'paused' || !c.background;
    stop.disabled = busy || over;

    go.onclick = function () {
      act(c.state === 'paused'
        ? { path: '/import/background-control', body: { action: 'resume' } }
        : { path: '/import/background', body: {} });
    };
    pause.onclick = function () { act({ path: '/import/background-control', body: { action: 'pause' } }); };
    stop.onclick = function () {
      if (!confirm('Stop the import?\n\nNothing already imported is undone, and starting again continues from '
        + 'the row after the last one that was committed.')) return;
      act({ path: '/import/background-control', body: { action: 'stop' } });
    };

    document.getElementById('foot').textContent =
      'Refreshing every ' + (c.state === 'running' || c.state === 'stalled' ? 3 : 15) + ' seconds'
      + (document.hidden ? ' (paused while this tab is in the background).' : '.');
  }

  function act(what) {
    busy = true;
    paint();

    call(what.path, { method: 'POST', body: JSON.stringify(what.body) }).then(function (r) {
      busy = false;

      if (r.data && r.data.progress) snap = r.data.progress;

      /* A refusal is a sentence the owner can act on, and it is shown ABOVE
         everything rather than replacing the page: the run underneath it is
         untouched and its numbers are still true. */
      if (!r.data || r.data.ok === false) {
        paint();
        document.getElementById('alerts').innerHTML =
          '<div class="alert a-bad">' + esc((r.data && r.data.message) || r.raw || ('HTTP ' + r.status)) + '</div>'
          + document.getElementById('alerts').innerHTML;
        schedule();
        return;
      }

      paint();
      schedule();
    });
  }

  function refresh() {
    return call('/import/background').then(function (r) {
      if (r.data && r.data.ok) { snap = r.data; paint(); }
      else {
        document.getElementById('alerts').innerHTML =
          '<div class="alert a-bad">Could not read progress: ' + esc(r.raw || ('HTTP ' + r.status)) + '</div>';
      }
      schedule();
    });
  }

  function schedule() {
    if (timer) clearTimeout(timer);
    if (document.hidden) return;          // do not poll a tab nobody is looking at

    var state = snap && snap.chain ? snap.chain.state : 'idle';
    timer = setTimeout(refresh, (state === 'running' || state === 'stalled' ? 3 : 15) * 1000);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refresh();
  });

  refresh();
})();
</script>
</body>
</html>
