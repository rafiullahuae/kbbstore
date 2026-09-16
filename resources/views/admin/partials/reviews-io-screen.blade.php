{{--
    Reviews - Export / Import (Lane BE).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, toast() and the design
    tokens this screen borrows.

    Its own file rather than more lines inside a 13,000-line Blade: several
    lanes edit that file at once, and a screen that lives on its own can be
    reviewed, reverted and merged on its own. The shape is deliberately the same
    as admin/partials/review-settings-screen.blade.php; that is the precedent.

    WHAT THIS SCREEN IS FOR. 'rev-io' was an entry in REV_SRC pointing at
    kbb-admin-exportimport.html, a standalone file this repo has never shipped,
    so the screen rendered the "isn't installed yet" card. Meanwhile this store
    is a WooCommerce port whose owner has thousands of real reviews in the old
    shop and no way to bring them across.

    NO NAV ENTRY IS ADDED HERE. Unlike the Coupons and New Order screens, this
    id is ALREADY in app.blade.php's NAV const (Reviews - Export / Import) and
    in TITLES. Appending a second button would give the owner two. All this file
    does is take over the route.

    'rev-io' IS ALSO ADDED TO LIVE_RENDERED in app.blade.php, which is the other
    half and is not optional. Without it, go('rev-io') reaches
    renderReviewFrame() -> mountFrame(), which fires a HEAD request for a file
    that is not there on every single visit and then paints the not-built card a
    moment before this screen overwrites it. That is exactly what Lane AM did
    for 'rev-all', what Lane BB did for 'rev-settings', and what the LANE AV
    comment block above LIVE_RENDERED describes.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.

    The whole body is wrapped in one so that the braces inside JavaScript
    template literals are not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Export / Import. Every rule is prefixed rio- and every id is rio-, so this
   file can never restyle or collide with another screen in the console. The
   console is one document: an unprefixed .card or #save here would reach into
   whatever else is mounted.

   Same layout rule as the sibling screens: nothing may be wider than its column
   at 390px, because the owner reviews on a phone. Measured on #content, not on
   documentElement -- the document metric is structurally blind here, because
   the console's shell is what scrolls, not the page.

   min-width:0 on the grid AND on its children is load-bearing, not tidiness. A
   grid item's default min-width is auto -- "at least as wide as my content" --
   so a card refuses to shrink below its widest child and drags the whole column
   out past the viewport with no way to scroll back. The reject table below is
   exactly such a child: its reasons are long sentences.
--------------------------------------------------------------------------- */
.rio-wrap{display:grid;gap:16px;min-width:0;max-width:1400px}
.rio-wrap > *{min-width:0}

.rio-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.rio-legend{font-weight:650;font-size:14px;margin:0 0 3px}
.rio-legend-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:0 0 14px;max-width:80ch;line-height:1.5}
.rio-title{font-weight:650;font-size:15px;margin:0}
.rio-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:3px 0 0;max-width:80ch;line-height:1.5}

/* One row per control. At 560px they stack rather than squeezing the control
   to nothing. */
.rio-row{display:grid;grid-template-columns:1fr auto;gap:10px 16px;align-items:center;
         padding:11px 0;border-top:1px solid var(--border,#e6e6e6);min-width:0}
.rio-row:first-of-type{border-top:0}
.rio-row > *{min-width:0}
.rio-lab{font-size:13.5px;font-weight:600;max-width:72ch}
.rio-hint{color:var(--ink-soft,#6b7280);font-size:12px;margin-top:2px;line-height:1.45;max-width:72ch}
.rio-ctl{display:flex;align-items:center;gap:8px;justify-self:end;flex-wrap:wrap}

@media (max-width:560px){
  .rio-row{grid-template-columns:1fr}
  .rio-ctl{justify-self:start}
}

.rio-sel,.rio-file{max-width:100%;padding:7px 9px;font:inherit;border:1px solid var(--border,#e6e6e6);
                   border-radius:9px;background:transparent;color:inherit}
.rio-file{width:100%}

.rio-sw{position:relative;display:inline-block;width:42px;height:24px;flex:none}
.rio-sw input{position:absolute;inset:0;opacity:0;margin:0;width:100%;height:100%;cursor:pointer}
.rio-sw i{position:absolute;inset:0;border-radius:999px;background:rgba(127,127,127,.32);
          transition:background .16s ease;pointer-events:none;display:block}
.rio-sw i::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;
                 background:#fff;transition:transform .16s ease;box-shadow:0 1px 3px rgba(0,0,0,.28)}
.rio-sw input:checked + i{background:var(--accent,#15a85a)}
.rio-sw input:checked + i::after{transform:translateX(18px)}
.rio-sw input:focus-visible + i{outline:2px solid var(--accent,#15a85a);outline-offset:2px}

.rio-btn{appearance:none;border:1px solid var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;
         font:inherit;font-weight:600;padding:9px 16px;border-radius:10px;cursor:pointer}
.rio-btn[disabled]{opacity:.55;cursor:default}
.rio-ghost{background:transparent;color:inherit;border-color:var(--border,#e6e6e6)}
.rio-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

.rio-banner{border:1px solid #d9534f;border-radius:10px;padding:11px 13px;font-size:13px;line-height:1.5;
            color:#b3312c;background:rgba(217,83,79,.07)}
.rio-banner.rio-ok{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);background:rgba(21,168,90,.07)}

/* The count tiles. auto-fit rather than a fixed column count, so they reflow to
   one column on a phone instead of overflowing. */
.rio-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;min-width:0}
.rio-tile{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px;min-width:0}
.rio-tile b{display:block;font-size:20px;font-variant-numeric:tabular-nums;line-height:1.2}
.rio-tile span{display:block;color:var(--ink-soft,#6b7280);font-size:12px;margin-top:2px}
.rio-tile.rio-bad b{color:#b3312c}

/* The reject list. Its own scroller, because a reason is a whole sentence and
   the alternative is the page itself scrolling sideways. */
.rio-scroll{overflow:auto;-webkit-overflow-scrolling:touch;max-height:340px;border:1px solid var(--border,#e6e6e6);
            border-radius:10px;min-width:0}
.rio-tab{width:100%;border-collapse:collapse;font-size:12.5px;min-width:420px}
.rio-tab th,.rio-tab td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--border,#e6e6e6);vertical-align:top}
.rio-tab th{font-weight:650;position:sticky;top:0;background:var(--surface,#fff);z-index:1}
.rio-tab tr:last-child td{border-bottom:0}
.rio-tab td.rio-n{font-variant-numeric:tabular-nums;white-space:nowrap;width:1%;color:var(--ink-soft,#6b7280)}

.rio-cols{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.rio-chip{border:1px solid var(--border,#e6e6e6);border-radius:999px;padding:3px 9px;font-size:12px;
          font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.rio-chip.rio-dim{color:var(--ink-soft,#6b7280);opacity:.75}

.rio-note{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.5;max-width:80ch}
.rio-note code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
.rio-warn{border-left:3px solid #d9a13d;padding-left:11px;margin-top:10px}

/* Always in the markup, even with nothing in it — see the note in the import
   card. :empty keeps it from leaving a gap under the file input when no file
   has been chosen yet. */
.rio-name{font-size:12.5px;color:var(--ink-soft,#6b7280);word-break:break-all;margin-top:6px}
.rio-name:empty{display:none}
</style>

<script>
/* =========================================================================
   Reviews -> Export / Import (Lane BE)

   The two halves and what each is for:

     EXPORT   GET /admin-api/reviews-io/export
              Every review, in a shape this screen's own import reads back. The
              columns that make that round trip work are review_id, source,
              source_id and product_sku -- see the controller.

              This is NOT the same export as the one on All Reviews. That one
              (Admin\ReviewsApiController::export) streams the moderation
              screen's current filtered view, which is the right export for
              that screen and is untouched. It carries no source_id and no sku,
              so it cannot be re-imported without duplicating everything. The
              card below says so, because an owner with two Export buttons
              needs to know which one they are pressing.

     IMPORT   POST /admin-api/reviews-io/import
              A WooCommerce review export, or this screen's own file. Header
              spellings are mapped rather than required -- a WooCommerce review
              is a WordPress comment and every exporter names the columns
              differently -- and the column reference at the foot of the screen
              is drawn from the importer's own alias table, so it cannot go
              stale.

   THE CHECK BUTTON COMES FIRST, deliberately, and is the default action. It
   runs the whole parse and reports what would happen without writing a row.
   An owner about to merge three thousand reviews into a live shop should be
   able to find out what the file contains before it is in the database.
   ========================================================================= */
(function(){
  'use strict';

  var SCREEN = 'rev-io';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;      // the summary: counts, sources, columns, aliases, limits
  var report = null;    // the last import/check report
  var banner = null;    // {kind:'err'|'ok', text}
  var busy = false;
  var working = false;  // an import or check is in flight
  var seq = 0;          // guards against an older response landing last

  var form = {
    status: 'all',
    emails: true,
    mode: 'check',
    on_duplicate: 'skip',
    allow_business: false,
    timezone: 'UTC',
    filename: ''
  };

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* The console lives one segment below the site root (its path is the secret
     admin path), so the API prefix is this page's path with its last segment
     removed. Identical to the sibling screens rather than re-derived. */
  function apiBase(){
    return BASE.replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  async function api(path, opts){
    var o = opts || {};
    o.headers = o.headers || {};
    o.headers['Accept'] = 'application/json';
    o.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
    o.credentials = 'same-origin';

    var r = await fetch(apiBase() + path, o);
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function num(n){
    return Number(n || 0).toLocaleString();
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* ------------------------------------------------------------- the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    /* The console's own go() is NOT called for this id. It would reach
       renderReviewFrame(), and although 'rev-io' is in LIVE_RENDERED -- so
       mountFrame() paints the startup message instead of probing for a file
       that is not there -- there is no reason to paint it at all when this
       screen is about to draw. The nav, crumb and title below are the only
       things go() would have done for us. */
    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    document.querySelectorAll('#nav .nav-group').forEach(function(g){
      var has = [].slice.call(g.querySelectorAll('.nav-item')).some(function(b){
        return b.dataset.go === SCREEN;
      });
      g.classList.toggle('open', has);
    });

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Reviews';
    if (title) title.textContent = 'Review Import / Export';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.scrollTop = 0;

    render();
    load();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/reviews-io/summary');
      if (mine !== seq) return;
      data = body;
      if (body.timezone) form.timezone = body.timezone;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      data = null;
      /* A 404 here almost always means the route file shipped without its
         clear_caches migration having run, so the compiled route table does not
         know these paths yet. Said plainly rather than rendering an empty
         screen, which reads as "there is nothing to export". */
      banner = {kind:'err', text: e.status === 404
        ? 'The export/import endpoints are not registered on this server yet. Clear the route cache and reload.'
        : 'Could not load the review totals (' + (e.status || 'network') + ').'};
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  function exportUrl(){
    var q = '?status=' + encodeURIComponent(form.status) + '&emails=' + (form.emails ? '1' : '0');
    return apiBase() + '/reviews-io/export' + q;
  }

  async function run(mode){
    var input = document.querySelector('#rio-file');
    var file = input && input.files && input.files[0];

    if (!file) {
      banner = {kind:'err', text:'Choose a CSV file first.'};
      render();
      return;
    }

    working = true;
    banner = null;
    render();

    var body = new FormData();
    body.append('file', file);
    body.append('mode', mode);
    body.append('on_duplicate', form.on_duplicate);
    body.append('allow_business', form.allow_business ? '1' : '0');
    body.append('timezone', form.timezone);

    try {
      /* No Content-Type header: the browser has to set it, because only the
         browser knows the multipart boundary it is about to generate. Setting
         it by hand here is the classic way to make every upload arrive empty. */
      report = await api('/reviews-io/import', {method:'POST', body: body});
      banner = {
        kind: 'ok',
        text: mode === 'check'
          ? 'Checked. Nothing has been written — press Import to apply it.'
          : 'Imported. The product pages and the star ratings are up to date.'
      };
      say(mode === 'check' ? 'File checked' : 'Reviews imported');
      if (mode === 'import') load();
    } catch (e) {
      /* A 422 from the importer IS a report -- "no rating column", the row
         rejections -- and is far more useful than the status code. It is shown
         as the report rather than thrown away behind a generic failure. */
      if (e.body && (e.body.message || e.body.rejects)) {
        report = e.body;
        banner = {kind:'err', text: e.body.message || 'That file could not be imported.'};
      } else {
        report = null;
        banner = {kind:'err', text:'Could not read that file (' + (e.status || 'network') + ').'};
      }
    } finally {
      working = false;
      render();
    }
  }

  /* ---------------------------------------------------------------- markup */
  function sw(key){
    return '<label class="rio-sw"><input type="checkbox" data-rio-bool="' + key + '"' +
           (form[key] ? ' checked' : '') + '><i></i></label>';
  }

  function sel(key, options){
    var opts = options.map(function(o){
      return '<option value="' + esc(o[0]) + '"' + (form[key] === o[0] ? ' selected' : '') + '>' +
             esc(o[1]) + '</option>';
    }).join('');
    return '<select class="rio-sel" data-rio-enum="' + key + '">' + opts + '</select>';
  }

  function row(label, hint, control){
    return '<div class="rio-row"><div><div class="rio-lab">' + esc(label) + '</div>' +
           '<div class="rio-hint">' + hint + '</div></div>' +
           '<div class="rio-ctl">' + control + '</div></div>';
  }

  function tile(value, label, bad){
    return '<div class="rio-tile' + (bad ? ' rio-bad' : '') + '"><b>' + esc(num(value)) + '</b>' +
           '<span>' + esc(label) + '</span></div>';
  }

  function totalsCard(){
    var c = data.counts || {};
    return '<div class="rio-card">' +
      '<p class="rio-legend">What is in the store now</p>' +
      '<p class="rio-legend-sub">Counted from the reviews table, not from the product totals.</p>' +
      '<div class="rio-tiles">' +
        tile(c.all, 'reviews in total') +
        tile(c.approved, 'published') +
        tile(c.pending, 'waiting for you') +
        tile(c.spam, 'refused') +
        tile(data.products_with_reviews, 'products reviewed') +
        tile(data.business, 'about the shop') +
      '</div></div>';
  }

  function exportCard(){
    return '<div class="rio-card">' +
      '<p class="rio-legend">Export</p>' +
      '<p class="rio-legend-sub">A CSV you can keep as a backup, edit in a spreadsheet, or bring back in ' +
      'through the Import box below. It carries the columns that let this screen recognise a review it has ' +
      'already seen, so re-importing the file updates those reviews instead of creating a second copy of ' +
      'every one.</p>' +
      row('Which reviews', 'All of them, or just one moderation state.',
          sel('status', [['all','Everything'],['approved','Published only'],['pending','Waiting for approval'],['spam','Refused only']])) +
      row('Include reviewer email addresses',
          'On, the file can be imported into another copy of this store with the reviewers intact. Off, ' +
          'leave the column out — the file is then safe to hand to somebody outside the business.',
          sw('emails')) +
      '<div class="rio-actions" style="margin-top:14px">' +
        '<button class="rio-btn" id="rio-export">Download CSV</button>' +
        '<span class="rio-note">Up to ' + esc(num(data.limits.export_max)) + ' rows per file.</span>' +
      '</div>' +
      '<p class="rio-note rio-warn">Not the same button as the one on <b>All Reviews</b>. That export ' +
      'gives you exactly the rows you are looking at there, with your filters applied — better for reading, ' +
      'but it cannot be imported back without duplicating everything. This one is the round trip.</p>' +
      '</div>';
  }

  function importCard(){
    return '<div class="rio-card">' +
      '<p class="rio-legend">Import</p>' +
      '<p class="rio-legend-sub">A review export from WooCommerce, or a file from the Export box above. ' +
      'Up to ' + esc(num(data.limits.max_rows)) + ' rows and ' + esc(num(data.limits.max_kb / 1024)) +
      ' MB; rows are written ' + esc(num(data.limits.batch)) + ' at a time so a large file cannot hold the ' +
      'database open.</p>' +
      '<div class="rio-row rio-wide" style="grid-template-columns:1fr">' +
        '<div><div class="rio-lab">The file</div>' +
        '<div class="rio-hint">CSV. Commas or semicolons — it works out which.</div></div>' +
        '<div class="rio-ctl" style="justify-self:stretch;display:block">' +
        '<input class="rio-file" type="file" id="rio-file" accept=".csv,.txt,text/csv,text/plain">' +
        /* ALWAYS rendered, even empty. It used to be conditional on a file
           having been chosen, which meant the first choice had nowhere to write
           its caption and fell back to a full render() — and a re-render
           replaces the file input, whose selection cannot be restored from
           script. So the FIRST file an owner picked was silently thrown away
           and the next click answered "Choose a CSV file first"; the second
           pick worked, because by then this element existed. Found in the
           browser, not in a test: nothing server-side can see it. */
        '<div class="rio-name">' + esc(form.filename) + '</div>' +
        '</div></div>' +
      row('If a review is already here',
          'Reviews are matched on their id from the system they came from. <b>Leave it alone</b> is the safe ' +
          'default: it will not undo an approval you have already made here. <b>Overwrite</b> replaces the ' +
          'review with the one in the file, including whether it is published.',
          sel('on_duplicate', [['skip','Leave it alone'],['update','Overwrite it from the file']])) +
      row('Rows with no product',
          'In WooCommerce a review of the shop itself has no product. Off, those rows are refused and listed ' +
          'so you can see them. On, they are imported as shop reviews.',
          sw('allow_business')) +
      row('Dates in the file are in',
          'A review date with no timezone on it is read as this zone. Getting it wrong shifts every review ' +
          'by a few hours; it does not lose anything.',
          sel('timezone', [['UTC','UTC'],['Asia/Dubai','Asia/Dubai'],['Asia/Riyadh','Asia/Riyadh'],
                           ['Europe/London','Europe/London'],['America/New_York','America/New_York']])) +
      '<div class="rio-actions" style="margin-top:14px">' +
        '<button class="rio-btn" id="rio-check"' + (working ? ' disabled' : '') + '>' +
        (working ? 'Working…' : 'Check the file') + '</button>' +
        '<button class="rio-btn rio-ghost" id="rio-import"' + (working ? ' disabled' : '') + '>Import</button>' +
        '<span class="rio-note">Check writes nothing. It tells you what the file contains first.</span>' +
      '</div>' +
      '</div>';
  }

  function reportCard(){
    var r = report;

    var html = '<div class="rio-card">' +
      '<p class="rio-legend">' + (r.mode === 'check' ? 'What that file would do' : 'What was imported') + '</p>' +
      '<p class="rio-legend-sub">' +
      (r.file ? esc(r.file) + ' — ' : '') +
      esc(num(r.rows_read)) + ' rows read.' +
      (r.mode === 'check' ? ' Nothing has been written.' : '') +
      '</p>';

    html += '<div class="rio-tiles">' +
      tile(r.created, r.mode === 'check' ? 'would be new' : 'added') +
      tile(r.updated, r.mode === 'check' ? 'would be overwritten' : 'overwritten') +
      tile(r.unchanged, 'already here, left alone') +
      tile(r.rejected, 'refused', r.rejected > 0) +
      tile(r.products_touched, 'products affected') +
      (r.mode === 'check' ? '' : tile(r.ratings_refreshed, 'star ratings recalculated')) +
      '</div>';

    if (r.rejects && r.rejects.length) {
      html += '<p class="rio-legend" style="margin-top:16px">Rows that were refused</p>' +
        '<p class="rio-legend-sub">The row number is the line in your spreadsheet, counting the header as ' +
        'row 1. Everything else in the file was' + (r.mode === 'check' ? ' still' : '') + ' fine.</p>' +
        '<div class="rio-scroll"><table class="rio-tab"><thead><tr><th>Row</th><th>Why</th></tr></thead><tbody>' +
        r.rejects.map(function(x){
          return '<tr><td class="rio-n">' + esc(x.row) + '</td><td>' + esc(x.reason) + '</td></tr>';
        }).join('') +
        '</tbody></table></div>';
    }

    if (r.notes && r.notes.length) {
      html += '<p class="rio-legend" style="margin-top:16px">Rows that needed tidying</p>' +
        '<p class="rio-legend-sub">These were imported. This is what had to be changed to do it.</p>' +
        '<div class="rio-scroll"><table class="rio-tab"><thead><tr><th>Row</th><th>What</th></tr></thead><tbody>' +
        r.notes.map(function(x){
          return '<tr><td class="rio-n">' + esc(x.row) + '</td><td>' + esc(x.note) + '</td></tr>';
        }).join('') +
        '</tbody></table></div>';
    }

    if (r.truncated_lists) {
      html += '<p class="rio-note" style="margin-top:10px">Only the first few hundred are listed. ' +
              'The totals above count all of them.</p>';
    }

    if (r.columns_used && Object.keys(r.columns_used).length) {
      html += '<p class="rio-legend" style="margin-top:16px">Columns it read</p>' +
        '<div class="rio-cols">' +
        Object.keys(r.columns_used).map(function(k){
          return '<span class="rio-chip">' + esc(r.columns_used[k]) + ' &rarr; ' + esc(k) + '</span>';
        }).join('') + '</div>';
    }

    if (r.columns_ignored && r.columns_ignored.length) {
      html += '<p class="rio-legend" style="margin-top:14px">Columns it ignored</p>' +
        '<p class="rio-legend-sub">Nothing is wrong with these — this screen simply has nowhere to put them.</p>' +
        '<div class="rio-cols">' +
        r.columns_ignored.map(function(c){
          return '<span class="rio-chip rio-dim">' + esc(c) + '</span>';
        }).join('') + '</div>';
    }

    return html + '</div>';
  }

  function referenceCard(){
    var aliases = data.aliases || {};

    var friendly = {
      review_id: 'this store\'s own review id',
      source: 'where the review came from',
      source_id: 'the id it had there — this is what stops a second import duplicating it',
      product_id: 'the product',
      wc_post_id: 'the WooCommerce post id of the product',
      product_sku: 'the product\'s SKU',
      product_name: 'the product\'s name (only used if nothing above matches)',
      author: 'the reviewer\'s name',
      email: 'the reviewer\'s email address',
      rating: 'stars, 1 to 5 — required',
      title: 'the review headline',
      content: 'the review itself',
      status: 'published, waiting, or refused',
      verified: 'whether the reviewer bought it',
      helpful: 'how many people found it helpful',
      reply: 'your reply to the review',
      created_at: 'when it was written'
    };

    var rows = Object.keys(aliases).map(function(k){
      return '<tr><td><b>' + esc(friendly[k] || k) + '</b></td><td>' +
        aliases[k].map(function(a){ return '<span class="rio-chip">' + esc(a) + '</span>'; }).join(' ') +
        '</td></tr>';
    }).join('');

    return '<div class="rio-card">' +
      '<p class="rio-legend">Which column names it understands</p>' +
      '<p class="rio-legend-sub">Your file does not have to use these exact names. Case, spaces, dots and ' +
      'dashes are all ignored, so <code>Comment Author E-mail</code> and <code>comment_author_email</code> ' +
      'are the same column. Only <b>rating</b> and one of the product columns are required.</p>' +
      '<div class="rio-scroll"><table class="rio-tab"><thead><tr><th>Means</th><th>Any of these names</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<p class="rio-note rio-warn">If your file has no id column at all, this screen works one out from ' +
      'the review itself so that importing the same file twice still does not duplicate anything. The catch ' +
      'is that editing a row between two imports makes it look like a different review, and you get both. A ' +
      'file with a real id column — WooCommerce always has one — does not have that problem.</p>' +
      '</div>';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    // Only paint when this screen is the one showing, so a render triggered by
    // a late response cannot overwrite whatever the owner navigated to.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="rio-wrap">';

    html += '<div class="rio-card"><div>' +
            '<h2 class="rio-title">Review Import / Export</h2>' +
            '<p class="rio-sub">Take your reviews out as a spreadsheet, or bring the ones from your old ' +
            'WooCommerce shop in. Importing the same file twice will not duplicate anything.</p>' +
            '</div></div>';

    if (banner) {
      html += '<div class="rio-banner' + (banner.kind === 'ok' ? ' rio-ok' : '') + '">' +
              esc(banner.text) + '</div>';
    }

    if (busy && !data) {
      html += '<div class="rio-card rio-note">Loading…</div>';
    } else if (data) {
      html += totalsCard();
      html += exportCard();
      html += importCard();
      if (report) html += reportCard();
      html += referenceCard();
    }

    html += '</div>';

    host.innerHTML = html;
    bind();
  }

  function bind(){
    document.querySelectorAll('[data-rio-bool]').forEach(function(el){
      el.onchange = function(){ form[el.dataset.rioBool] = el.checked; };
    });

    document.querySelectorAll('[data-rio-enum]').forEach(function(el){
      el.onchange = function(){ form[el.dataset.rioEnum] = el.value; };
    });

    var file = document.querySelector('#rio-file');
    if (file) {
      file.onchange = function(){
        /* NEVER render() from here. A re-render replaces the input element, and
           a file input's selection cannot be restored from script, so the file
           the owner just chose would be discarded. Only the caption is touched,
           and it is always in the markup for that reason. run() reads the live
           element rather than anything stashed here. */
        form.filename = (file.files && file.files[0]) ? file.files[0].name : '';
        banner = null;

        var label = document.querySelector('.rio-name');
        if (label) label.textContent = form.filename;

        var warn = document.querySelector('.rio-banner');
        if (warn && warn.parentNode) warn.parentNode.removeChild(warn);
      };
    }

    var x = document.querySelector('#rio-export');
    if (x) x.onclick = function(){
      /* A plain navigation, not fetch(): the response is a file download with
         a Content-Disposition on it, and the session cookie goes with it. */
      window.location.href = exportUrl();
    };

    var c = document.querySelector('#rio-check');
    if (c) c.onclick = function(){ run('check'); };

    var i = document.querySelector('#rio-import');
    if (i) i.onclick = function(){ run('import'); };
  }

  /* A bookmark straight to this screen — /admin?go=rev-io, or the hash. That
     navigation is performed by the boot block at the end of the FIRST script in
     this document, which runs before this one exists, so go() lands on
     renderReviewFrame() and paints the startup message. Nothing has been drawn
     at this point in parsing, so rendering here is not a flicker: it is the
     first thing the browser paints. */
  function bootIfCurrent(){
    var active = document.querySelector('.side .nav-item.on');
    if (active && active.dataset.go === SCREEN) { render(); load(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootIfCurrent);
  } else {
    bootIfCurrent();
  }
})();
</script>
@endverbatim
