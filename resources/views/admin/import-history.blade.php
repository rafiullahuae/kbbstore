{{--
    What has been imported, from which export, and when — Lane GF.

    The owner asked for "all record". This is it, and it is a page rather than a
    log line because he has no shell and no log access: if the answer is not on
    a screen it does not exist for him.

    A STANDALONE DOCUMENT, for the reasons Lane GD's media-progress.blade.php
    gives and one more of its own:

      * IT MUST WORK WHEN THE CONSOLE DOES NOT. A record of what happened is
        exactly the screen somebody opens on the day something is wrong.
      * NO BUILD STEP. package.json defines no `build` script (CLAUDE.md),
        assets are built by hand and CI does not build them, so a page that
        needed compiling would ship broken. Everything here is inline.
      * IT IS NOT LIVE AND DOES NOT POLL. Nothing on it changes while it is
        open unless an import is running in another tab, and polling a shared
        host to watch a table that is not moving is a cost with no benefit.
        There is a Refresh button. The LIVE view is Lane GD's progress page and
        this page links to it rather than duplicating it.

    EVERY VALUE FROM THE SERVER IS TEXT, NEVER HTML. The note text on these rows
    is lifted out of the owner's own WooCommerce export — order statuses, author
    names, column values — and an export is not content this shop controls. See
    esc(); the console's impEsc() exists one level up for the same reason.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>What has been imported · K-Beauty Bliss</title>
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
.why{color:var(--soft);font-size:12.5px;margin:0 0 10px}
.badge{display:inline-block;font-size:11.5px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;
  padding:3px 9px;border-radius:999px;vertical-align:2px;margin-left:8px}
.b-live{background:var(--good-bg);color:var(--good)}
.b-preview{background:#eef0f3;color:var(--soft)}
.b-already{background:var(--warn-bg);color:var(--warn)}
.b-partial{background:var(--live-bg);color:var(--live)}
.b-fresh{background:var(--good-bg);color:var(--good)}
.b-nothing{background:#eef0f3;color:var(--soft)}
.head{font-size:15px;font-weight:600;margin:6px 0 2px}
.bar{height:9px;border-radius:999px;background:#eceff3;overflow:hidden;margin:10px 0 6px}
.bar>i{display:block;height:100%;background:var(--good);transition:width .4s ease}
.bar.is-running>i{background:var(--live)}
.nums{display:flex;flex-wrap:wrap;gap:16px;color:var(--soft);font-size:12.5px}
.nums b{color:var(--ink);font-weight:600}
button,a.btn{font:inherit;font-size:13px;font-weight:600;border-radius:9px;border:1px solid var(--line);
  background:#fff;color:var(--ink);padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-block}
a.btn.primary,button.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
.btns{margin:14px 0 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px}
th{text-align:left;color:var(--soft);font-weight:600;padding:6px 8px;border-bottom:1px solid var(--line)}
td{padding:7px 8px;border-bottom:1px solid #f1f3f6;vertical-align:top}
td.n{text-align:right;font-variant-numeric:tabular-nums}
code{font:12px ui-monospace,SFMono-Regular,Menlo,monospace;background:#f2f4f7;border-radius:5px;padding:1px 5px;
  word-break:break-all}
.note{background:#f8f9fb;border:1px solid var(--line);border-radius:9px;padding:10px 12px;color:var(--soft);
  font-size:12.5px;margin-top:10px}
.alert{border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:10px}
.a-bad{background:var(--bad-bg);color:#8a2b2b;border:1px solid #f0cccc}
.a-warn{background:var(--warn-bg);color:#7a4e07;border:1px solid #efdcb2}
.a-good{background:var(--good-bg);color:#0f6e3d;border:1px solid #bfe6d0}
.a-live{background:var(--live-bg);color:#1c4a99;border:1px solid #c8dafa}
.tag{font-size:10.5px;font-weight:700;text-transform:uppercase;padding:2px 7px;border-radius:999px}
.t-imported{background:var(--warn-bg);color:var(--warn)}
.t-changed{background:var(--live-bg);color:var(--live)}
.t-new{background:var(--good-bg);color:var(--good)}
.foot{color:var(--soft);font-size:12px;margin-top:22px}
.run{border:1px solid var(--line);border-radius:10px;padding:12px;margin-top:10px}
.run h3{font-size:13.5px;margin:0 0 2px}
@media(max-width:620px){table{font-size:11.5px}th,td{padding:5px 4px}}
</style>
</head>
<body>
<div class="wrap">
  <h1>What has been imported</h1>
  <p class="sub">Every import run from this shop&rsquo;s admin panel — which export it came from, when it was
    taken off the old site, and what each one did.</p>

  <div id="loaded"></div>
  <div id="history"></div>

  <div class="btns">
    <button type="button" class="primary" id="refresh">Refresh</button>
    <a class="btn" id="csv" href="#">Download the whole record</a>
    <a class="btn" id="live" href="#">Live progress</a>
  </div>

  <p class="foot" id="foot"></p>
</div>

<script>
(function(){
  'use strict';

  /* Same-origin, and built from this page's own URL rather than from a
     configured base: the admin path is a setting and this page is served from
     under it, so its own address is the only thing that is certainly right. */
  var BASE = window.location.pathname.replace(/\/history-page\/?$/, '');

  function esc(v){
    return String(v == null ? '' : v)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function num(n){ return (n == null ? '—' : String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')); }

  function when(v){
    if (!v) { return '—'; }
    return esc(String(v).replace('T',' ').replace(/\.\d+Z?$/,'').slice(0,19));
  }

  /* ------------------------------------------------- what is loaded now */

  function loadedCard(d){
    var m = d.manifest || {};
    var dup = d.duplicate || {};
    var o = d.overall || {};
    var html = '';

    var cls = dup.status === 'already' ? 'a-warn'
            : (dup.status === 'partial' ? 'a-live'
            : (dup.status === 'nothing' ? '' : 'a-good'));

    if (m.refusal) {
      html += '<div class="alert a-bad">' + esc(m.refusal) + '</div>';
    } else if (dup.sentence && cls) {
      html += '<div class="alert ' + cls + '">' + esc(dup.sentence) + '</div>';
    }

    var rows = '';
    (d.entities || []).filter(function(e){ return e.present; }).forEach(function(e){
      /* NO BAR WITHOUT A DENOMINATOR, and the reason is named in the cell
         rather than left blank: the server sends percent:null for a file whose
         row count it cannot vouch for, and a blank cell reads as a bug. */
      var cell;
      if (e.rows_mismatch) {
        cell = '<span style="color:var(--bad)">counts disagree</span>';
      } else if (e.percent == null) {
        cell = '<span class="why">no row count</span>';
      } else {
        cell = '<div class="bar" style="margin:0"><i style="width:' + e.percent + '%"></i></div>'
             + '<span class="why">' + e.percent + '% · from the ' + esc(e.denominator_source) + '</span>';
      }

      rows += '<tr><td>' + esc(e.label) + '</td>'
        + '<td class="n">' + num(e.processed) + (e.denominator == null ? '' : ' of ' + num(e.denominator)) + '</td>'
        + '<td style="min-width:150px">' + cell + '</td>'
        + '<td class="n">' + num(e.created) + '</td><td class="n">' + num(e.updated) + '</td>'
        + '<td class="n">' + num(e.unchanged) + '</td><td class="n">' + num(e.rejected) + '</td></tr>';
    });

    var mismatches = (d.entities || []).filter(function(e){ return e.rows_mismatch; });
    mismatches.forEach(function(e){
      html += '<div class="alert a-bad">' + esc(e.rows_mismatch.sentence) + '</div>';
    });

    var overall = '';
    if (o.percent == null) {
      overall = '<p class="why">' + esc(o.why_no_bar || '') + '</p>';
    } else {
      overall = '<div class="bar"><i style="width:' + o.percent + '%"></i></div>'
        + '<div class="nums"><span>' + num(o.done) + ' of <b>' + num(o.total) + '</b> rows across '
        + num(o.files) + ' files</span><span><b>' + o.percent + '%</b></span></div>';
    }

    return '<div class="card"><h2>The export loaded now'
      + '<span class="badge b-' + esc(dup.status || 'nothing') + '">' + esc(dup.status || 'nothing') + '</span></h2>'
      + '<p class="why">' + esc(m.note || '') + '</p>'
      + html
      + (m.usable
          ? '<div class="nums" style="margin-bottom:8px">'
            + '<span>taken from <b>' + esc((m.source && m.source.site_url) || 'an unnamed site') + '</b></span>'
            + '<span>taken at <b>' + when(m.generated_at) + '</b></span>'
            + '<span>export <code>' + esc(m.export_id || '—') + '</code></span>'
            + ((m.source && m.source.plugin_version)
                ? '<span>plugin <b>' + esc(m.source.plugin_version) + '</b></span>' : '')
            + '</div>'
          : '')
      + overall
      + (rows
          ? '<table><tr><th>file</th><th>rows done</th><th>progress</th><th>created</th><th>updated</th>'
            + '<th>unchanged</th><th>refused</th></tr>' + rows + '</table>'
          : '<div class="note">No export files are uploaded at the moment.</div>')
      + dupTable(dup)
      + '</div>';
  }

  function dupTable(dup){
    if (!dup || !dup.entities || !dup.entities.length) { return ''; }

    var rows = dup.entities.map(function(e){
      return '<tr><td><span class="tag t-' + esc(e.state) + '">' + esc(e.state) + '</span></td>'
        + '<td>' + esc(e.label) + '</td><td>' + esc(e.sentence) + '</td></tr>';
    }).join('');

    return '<table><tr><th>state</th><th>file</th><th>what the shop already knows about it</th></tr>'
      + rows + '</table>';
  }

  /* ------------------------------------------------------- past runs */

  function runCard(r){
    var rows = r.entities.map(function(e){
      var notes = Object.keys(e.notes || {}).map(function(n){
        return '<div class="why" style="margin:2px 0 0">' + esc(n)
          + (e.notes[n] > 1 ? ' <b>×' + num(e.notes[n]) + '</b>' : '') + '</div>';
      }).join('');

      return '<tr><td>' + esc(e.entity) + (e.file ? '<div class="why">' + esc(e.file) + '</div>' : '') + '</td>'
        + '<td class="n">' + num(e.processed)
        + (e.manifest_rows == null ? '' : ' of ' + num(e.manifest_rows)) + '</td>'
        + '<td class="n">' + num(e.created) + '</td><td class="n">' + num(e.updated) + '</td>'
        + '<td class="n">' + num(e.unchanged) + '</td><td class="n">' + num(e.rejected) + '</td>'
        + '<td class="n">' + num(e.adjusted) + '</td><td class="n">' + num(e.discarded) + '</td>'
        + '<td>' + (e.finished ? when(e.finished_at) : '<span class="why">not finished</span>') + '</td></tr>'
        + (notes ? '<tr><td colspan="9" style="padding-top:0">' + notes + '</td></tr>' : '');
    }).join('');

    var t = r.totals;

    return '<div class="run"><h3>' + when(r.started_at)
      + '<span class="badge b-' + esc(r.mode) + '">' + esc(r.mode) + '</span></h3>'
      + '<div class="nums" style="margin-bottom:6px">'
      + (r.source_site ? '<span>from <b>' + esc(r.source_site) + '</b></span>' : '<span>no manifest</span>')
      + (r.export_generated_at ? '<span>export taken <b>' + when(r.export_generated_at) + '</b></span>' : '')
      + (r.export_id ? '<span>export <code>' + esc(r.export_id) + '</code></span>' : '')
      + '<span>created <b>' + num(t.created) + '</b></span>'
      + '<span>updated <b>' + num(t.updated) + '</b></span>'
      + '<span>unchanged <b>' + num(t.unchanged) + '</b></span>'
      + '<span>refused <b>' + num(t.rejected) + '</b></span>'
      + '<span>adjusted <b>' + num(t.adjusted) + '</b></span>'
      + '<span>discarded <b>' + num(t.discarded) + '</b></span>'
      + '</div>'
      + '<table><tr><th>entity</th><th>rows</th><th>created</th><th>updated</th><th>unchanged</th>'
      + '<th>refused</th><th>adjusted</th><th>discarded</th><th>finished</th></tr>' + rows + '</table>'
      + (r.mode === 'preview'
          ? '<div class="note">A preview writes nothing and rolls itself back. These are the numbers it '
            + 'would have produced, not rows in the shop.</div>'
          : '')
      + '</div>';
  }

  function historyCard(d){
    if (!d.runs || !d.runs.length) {
      return '<div class="card"><h2>Nothing yet</h2><div class="note">' + esc(d.empty_note || '') + '</div></div>';
    }

    return '<div class="card"><h2>Every import, newest first</h2>'
      + '<p class="why">Read from <code>import_history</code>, which is written as each entity finishes and is '
      + 'not touched by &ldquo;Forget progress and start over&rdquo;.</p>'
      + d.runs.map(runCard).join('') + '</div>';
  }

  /* ------------------------------------------------------------ driver */

  function draw(d){
    document.getElementById('loaded').innerHTML = loadedCard(d);
    document.getElementById('history').innerHTML = historyCard(d);
    document.getElementById('foot').textContent = 'Read at ' + String(d.generated_at || '').replace('T',' ');
  }

  function load(){
    fetch(BASE + '/history', {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(function(r){ return r.json(); })
      .then(draw)
      .catch(function(e){
        document.getElementById('loaded').innerHTML =
          '<div class="alert a-bad">Could not read the record: ' + esc(e && e.message) + '</div>';
      });
  }

  document.getElementById('refresh').addEventListener('click', load);
  document.getElementById('csv').setAttribute('href', BASE + '/history.csv');
  document.getElementById('live').setAttribute(
    'href', BASE.replace(/\/import$/, '/urls-media') + '/progress-page');

  load();
})();
</script>
</body>
</html>
