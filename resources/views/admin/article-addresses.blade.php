{{--
    Articles at addresses this shop already owns — Lane U3, Phase 13 item 3.

    THE LIST IS THE DELIVERABLE. Each row is a rename-and-redirect the owner
    performs in WordPress, on the other site, before the cutover — so the page
    is organised around "what do I do about this one" rather than around counts.
    The three groups are kept apart because the action differs: a reserved
    article is NOT imported and needs renaming; an adjusted one IS imported and
    needs a redirect; one with no address needs a title. Summing them into "12
    problems" would be a number with no remedy attached to it.

    STANDALONE, LIKE admin/media-progress.blade.php AND FOR ITS FIRST TWO
    REASONS: this project has no asset build step (CLAUDE.md), and a page that
    is read when a migration is going wrong must not depend on the console
    bundle. Everything here is inline. The third reason is this page's own —
    the content is fixed at render time, so there is nothing to poll and almost
    nothing to script.

    EVERY VALUE FROM THE REPORT IS TEXT. Titles and slugs come straight out of
    the owner's WordPress database and are printed with `{{ }}`, which escapes.
    The ONE value that becomes an `href` is `indexed_at`, and it was scheme
    checked to http/https in ReservedArticleReport::linkable() before it got
    here — CLAUDE.md rule 5: a URL from data is checked before it is a link,
    and the check lives at the source, not in a template.

    NO JAVASCRIPT MEASURES LAYOUT. CLAUDE.md rule 4. The single script is a
    substring filter over rows that are already on the page; sizing is `calc()`
    and CSS, and the table becomes cards under 720px by media query alone.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="same-origin">
<title>Article addresses · K-Beauty Bliss</title>
<style>
:root{
  --bg:#f6f7f9; --card:#fff; --ink:#151a21; --soft:#6a7482; --line:#e4e8ee;
  --good:#15a85a; --good-bg:#eefaf3; --warn:#b5730b; --warn-bg:#fdf6e7;
  --bad:#c23b3b; --bad-bg:#fdeeee; --live:#2f6fe0; --live-bg:#eef3fd;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
  font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  -webkit-text-size-adjust:100%}
.wrap{max-width:1100px;margin:0 auto;padding:22px 16px 60px}
h1{font-size:21px;margin:0 0 4px}
.sub{color:var(--soft);margin:0 0 6px;max-width:74ch}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:14px}
.card h2{font-size:15px;margin:0 0 2px}
.card .why{color:var(--soft);font-size:12.5px;margin:0 0 10px;max-width:80ch;overflow-wrap:anywhere}
.badge{display:inline-block;font-size:11.5px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;
  padding:3px 9px;border-radius:999px;margin-left:8px;vertical-align:2px}
.b-bad{background:var(--bad-bg);color:var(--bad)}
.b-warn{background:var(--warn-bg);color:var(--warn)}
.b-good{background:var(--good-bg);color:var(--good)}
.b-idle{background:#eef0f3;color:var(--soft)}
.nums{display:flex;flex-wrap:wrap;gap:16px;color:var(--soft);font-size:12.5px;margin:2px 0 0}
.nums b{color:var(--ink);font-weight:600}
.btns{margin:14px 0 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
a.btn,button{font:inherit;font-size:13px;font-weight:600;border-radius:9px;border:1px solid var(--line);
  background:#fff;color:var(--ink);padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-block}
a.btn.primary{background:var(--ink);color:#fff;border-color:var(--ink)}
input[type=search]{font:inherit;font-size:13px;padding:8px 12px;border:1px solid var(--line);
  border-radius:9px;background:#fff;color:var(--ink);width:min(320px,100%)}
table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px;table-layout:fixed}
/* Fixed widths rather than letting the browser size to content: the "what to
   do" sentence is the longest cell on every row and an auto table gives it
   most of the width, squeezing the live URL into a four-line ribbon. These are
   percentages, so nothing is measured and nothing is scripted. The last group
   has four columns instead of five and gets its own rule below. */
th:nth-child(1),td:nth-child(1){width:21%}
th:nth-child(2),td:nth-child(2){width:13%}
th:nth-child(3),td:nth-child(3){width:23%}
th:nth-child(4),td:nth-child(4){width:15%}
th:nth-child(5),td:nth-child(5){width:28%}
table.four th:nth-child(3),table.four td:nth-child(3){width:28%}
table.four th:nth-child(4),table.four td:nth-child(4){width:38%}
th{text-align:left;color:var(--soft);font-weight:600;padding:6px 8px;border-bottom:1px solid var(--line);
  white-space:nowrap}
/* A refusal reason quotes a whole URL, and a percent-encoded Arabic permalink
   is one 120-character token with nothing in it a browser may break at. Left to
   itself it pushes the page wider than the phone — measured, not guessed:
   document.documentElement.scrollWidth read 543 at a 390px viewport before this
   line. `anywhere` rather than `break-all` so ordinary prose still breaks at
   spaces. CSS, once, at render time — CLAUDE.md rule 4. */
td{padding:9px 8px;border-bottom:1px solid #f1f3f6;vertical-align:top;overflow-wrap:anywhere}
td.u{word-break:break-all}
td.t{min-width:14ch}
.u a{overflow-wrap:anywhere}
code{font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#f1f3f6;
  padding:1px 5px;border-radius:5px;word-break:break-all}
.do{color:var(--soft);font-size:12px;margin:5px 0 0;overflow-wrap:anywhere}
.none{color:var(--soft);font-size:12.5px;margin:6px 0 0}
.note{font-size:12.5px;border-radius:9px;padding:10px 12px;margin:10px 0 0;overflow-wrap:anywhere}
.note.warn{background:var(--warn-bg);color:var(--warn)}
.note.info{background:var(--live-bg);color:var(--live)}
.foot{color:var(--soft);font-size:12px;margin:18px 0 0}
.rowhead{display:none}
/* Under 720px the table stops being a table. No script and no measurement —
   the layout is the media query's, which is what CLAUDE.md rule 4 asks for. */
@media (max-width:720px){
  .wrap{padding:18px 16px 48px}
  table,thead,tbody,tr,th,td{display:block}
  table{table-layout:auto}
  /* Same specificity as the percentage rules above, or they keep winning and
     the first column stays a 21% ribbon inside a full-width card. */
  table th:nth-child(n),table td:nth-child(n){width:auto}
  thead{display:none}
  tr{border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin:0 0 10px;background:#fff}
  td{border:0;padding:3px 0}
  .rowhead{display:inline;color:var(--soft);font-weight:600;margin-right:6px}
  .do{max-width:none}
}
</style>
</head>
<body>
<div class="wrap">

  <h1>Articles at addresses this shop already owns</h1>
  <p class="sub">A preview of what the import will decide about every article's address, taken from the
  importer's own rule. <b>Nothing on this page writes anything</b> — no rows, no checkpoints, and an import
  running right now is not disturbed by opening it.</p>

  @php($r = $report)

  @if (! $r['present'])
    <div class="card">
      <h2>Nothing to read yet<span class="badge b-idle">no file</span></h2>
      <p class="why">{{ $r['note'] }}</p>
      <p class="why">Upload it on <b>Store &rarr; Import</b>, in the Journal group, then come back to this page.</p>
    </div>
  @else
    @php($clean = $r['counts']['reserved'] === 0 && $r['counts']['adjusted'] === 0 && $r['counts']['no_address'] === 0)

    <div class="card">
      <h2>What the import will do with {{ $r['articles'] }} article(s)
        @if ($clean)
          <span class="badge b-good">nothing to do</span>
        @elseif ($r['counts']['reserved'] > 0)
          <span class="badge b-bad">{{ $r['counts']['reserved'] }} cannot be served</span>
        @else
          <span class="badge b-warn">{{ $r['counts']['adjusted'] + $r['counts']['no_address'] }} need attention</span>
        @endif
      </h2>
      <p class="why">{{ $r['note'] }}</p>
      <p class="nums">
        <span><b>{{ $r['counts']['reserved'] }}</b> refused — the shop owns that address</span>
        <span><b>{{ $r['counts']['adjusted'] }}</b> imported at a changed address</span>
        <span><b>{{ $r['counts']['no_address'] }}</b> with no address at all</span>
      </p>

      @if ($r['permalinks_note'] !== '')
        <p class="note {{ $r['permalinks'] ? 'info' : 'warn' }}">{{ $r['permalinks_note'] }}</p>
      @endif

      <div class="btns">
        {{-- Relative to this page's own address, so it survives KBB_BASE_PATH,
             the admin path having been moved, and the shop being served from a
             subfolder — the note admin/media-progress.blade.php makes. --}}
        <a class="btn primary" href="article-addresses.csv">Download the whole list as a spreadsheet</a>
        <input type="search" id="q" placeholder="Filter by title, slug or address" autocomplete="off"
               aria-label="Filter the lists below">
      </div>
    </div>

    {{--
      THE THREE GROUPS, IN THE ORDER OF HOW BADLY THEY BITE. A reserved
      article is silently absent after the cutover; an adjusted one is present
      at a different address and 404s the old one; one with no address is
      absent and has nowhere to go.
    --}}

    <div class="card">
      <h2>Refused: this shop already answers that address
        <span class="badge {{ $r['counts']['reserved'] > 0 ? 'b-bad' : 'b-good' }}">{{ $r['counts']['reserved'] }}</span>
      </h2>
      <p class="why">Articles here are served from the site root — <code>/{slug}/</code>, exactly as WordPress
      serves them — and these slugs are first path segments the storefront itself owns. The import refuses each
      one by name rather than writing a row no request could ever reach. <b>Each is a rename and a redirect in
      WordPress</b>, before you export again.</p>

      @if ($r['reserved'] === [])
        <p class="none">None. No live article collides with an address this shop serves.</p>
      @else
        <table>
          <thead><tr>
            <th>Article</th><th>Slug</th><th>Live URL</th><th>Address here</th><th>What to do</th>
          </tr></thead>
          <tbody>
          @foreach ($r['reserved'] as $row)
            <tr class="row" data-find="{{ mb_strtolower($row['title'].' '.$row['slug'].' '.$row['wanted'].' '.$row['indexed_at']) }}">
              <td class="t"><b>{{ $row['title'] }}</b><br><span style="color:var(--soft)">WordPress id {{ $row['id'] !== '' ? $row['id'] : '—' }} · {{ $row['status'] }}</span></td>
              <td class="u"><span class="rowhead">Slug</span><code>{{ $row['slug'] !== '' ? $row['slug'] : '—' }}</code></td>
              <td class="u"><span class="rowhead">Live URL</span>
                @if ($row['indexed_at'] !== '')
                  <a href="{{ $row['indexed_at'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ $row['indexed_at'] }}</a>
                @else
                  <span style="color:var(--soft)">not in permalinks.csv</span>
                @endif
              </td>
              <td class="u"><span class="rowhead">Address here</span><code>{{ $row['wanted'] }}</code><br>
                <span style="color:var(--soft)">{{ $row['served_by'] }}</span></td>
              <td><span class="rowhead">What to do</span><p class="do">{{ $row['what_to_do'] }}</p></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>

    <div class="card">
      <h2>Imported, but at a different address
        <span class="badge {{ $r['counts']['adjusted'] > 0 ? 'b-warn' : 'b-good' }}">{{ $r['counts']['adjusted'] }}</span>
      </h2>
      <p class="why">The slug is nobody else's, but no URL can carry its shape — <code>My_Post</code>, or the
      percent-encoded Arabic WordPress writes for a non-Latin title. <b>These articles do arrive</b>, at a
      normalised address. The old address 404s until a redirect points at the new one:
      <b>Store &rarr; SEO &amp; Meta &rarr; Redirects</b>.</p>

      @if ($r['adjusted'] === [])
        <p class="none">None. Every article's slug is already an address this shop can serve unchanged.</p>
      @else
        <table>
          <thead><tr>
            <th>Article</th><th>Slug</th><th>Live URL</th><th>Imported at</th><th>What to do</th>
          </tr></thead>
          <tbody>
          @foreach ($r['adjusted'] as $row)
            <tr class="row" data-find="{{ mb_strtolower($row['title'].' '.$row['slug'].' '.$row['wanted'].' '.$row['indexed_at']) }}">
              <td class="t"><b>{{ $row['title'] }}</b><br><span style="color:var(--soft)">WordPress id {{ $row['id'] !== '' ? $row['id'] : '—' }} · {{ $row['status'] }}</span></td>
              <td class="u"><span class="rowhead">Slug</span><code>{{ $row['slug'] !== '' ? $row['slug'] : '—' }}</code></td>
              <td class="u"><span class="rowhead">Live URL</span>
                @if ($row['indexed_at'] !== '')
                  <a href="{{ $row['indexed_at'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ $row['indexed_at'] }}</a>
                @else
                  <span style="color:var(--soft)">not in permalinks.csv</span>
                @endif
              </td>
              <td class="u"><span class="rowhead">Imported at</span><code>{{ $row['imported_at'] }}</code></td>
              <td><span class="rowhead">What to do</span><p class="do">{{ $row['what_to_do'] }}</p></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>

    <div class="card">
      <h2>No address at all
        <span class="badge {{ $r['counts']['no_address'] > 0 ? 'b-warn' : 'b-good' }}">{{ $r['counts']['no_address'] }}</span>
      </h2>
      <p class="why">Neither the slug nor the title reduces to anything a URL can carry, so there is nowhere to
      serve the article from and it is not imported. Give it a title or a slug in WordPress and export again.</p>

      @if ($r['no_address'] === [])
        <p class="none">None.</p>
      @else
        <table class="four">
          <thead><tr><th>Article</th><th>Slug</th><th>Live URL</th><th>What to do</th></tr></thead>
          <tbody>
          @foreach ($r['no_address'] as $row)
            <tr class="row" data-find="{{ mb_strtolower($row['title'].' '.$row['slug'].' '.$row['indexed_at']) }}">
              <td class="t"><b>{{ $row['title'] }}</b><br><span style="color:var(--soft)">WordPress id {{ $row['id'] !== '' ? $row['id'] : '—' }} · {{ $row['status'] }}</span></td>
              <td class="u"><span class="rowhead">Slug</span><code>{{ $row['slug'] !== '' ? $row['slug'] : '—' }}</code></td>
              <td class="u"><span class="rowhead">Live URL</span>
                @if ($row['indexed_at'] !== '')
                  <a href="{{ $row['indexed_at'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ $row['indexed_at'] }}</a>
                @else
                  <span style="color:var(--soft)">not in permalinks.csv</span>
                @endif
              </td>
              <td><span class="rowhead">What to do</span><p class="do">{{ $row['what_to_do'] }}</p></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>
  @endif

  <p class="foot">Read from <code>{{ $r['file'] }}</code> in the import workspace. This page writes nothing.
  It is the same answer as <code>article-addresses.csv</code> and the same decision the import itself makes —
  <code>PostImporter::address()</code>, run once here and once there, never copied.</p>
</div>

<script>
(function(){
  'use strict';
  /* A substring filter over rows that are already in the document. It reads a
     data attribute the server wrote and toggles display; it measures nothing,
     fetches nothing and adds no markup. CLAUDE.md rule 4. */
  var q = document.getElementById('q');
  if (!q) return;
  var rows = [].slice.call(document.querySelectorAll('tr.row'));
  q.addEventListener('input', function(){
    var needle = q.value.trim().toLowerCase();
    for (var i = 0; i < rows.length; i++){
      rows[i].style.display = (needle === '' || rows[i].getAttribute('data-find').indexOf(needle) !== -1) ? '' : 'none';
    }
  });
})();
</script>
</body>
</html>
