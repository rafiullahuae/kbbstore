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
@php
    /*
     * ── WHAT THIS SERVER WILL REALLY ACCEPT ─────────────────────────────────
     *
     * The import card says "Up to 25,000 rows and 4 MB", because 4096 is
     * ReviewsIoApiController::UPLOAD_MAX_KB. On the live box PHP stops at
     * upload_max_filesize=2M inside post_max_size=8M, so HALF the number this
     * screen printed was unreachable -- and a 3 MB WooCommerce export did not
     * come back "too big", it came back as a validation failure saying the file
     * was not a file, because PHP had already thrown the upload away.
     *
     * This is the same defect App\Support\ServerUploadLimits was written for on
     * the video screen, applied here. The number is READ, not assumed, and it
     * costs no round trip: ini_get() has the answer at render time.
     *
     * The app cap is duplicated here as a literal because UPLOAD_MAX_KB is
     * private. ReviewsIoUploadLimitTest reflects it and fails if the two ever
     * disagree, so it cannot drift.
     */
    $rioLimitReader = app(\App\Support\ServerUploadLimits::class);
    $rioLimits = $rioLimitReader->describe(4096 * 1024);
    $rioLimits['server'] = $rioLimitReader->raw();
@endphp
{{--
    A JSON island, not a window assignment. The two values under `server` are ini
    strings read off the host rather than constants of this application, so rule 5
    applies to them: all four HEX flags out, JSON.parse in a try/catch in, esc()
    before any of it reaches innerHTML.
--}}
<script type="application/json" id="rio-limits">@json($rioLimits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
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
.rio-headrow{display:grid;gap:3px;min-width:0;padding:0 2px}
.rio-title{font-weight:650;font-size:15px;margin:0}
.rio-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;margin:0;max-width:78ch;line-height:1.5}

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

/* ── the drop zone ────────────────────────────────────────────────────────
   THE WHOLE IMPORT CARD IS THE TARGET, not the dashed box inside it. A CSV let
   go two pixels outside a 90px box did not merely fail: nothing in this console
   prevents the default drop, so the browser NAVIGATED AWAY to the file -- and on
   this screen that means leaving a half-configured import behind to go and look
   at raw CSV. The dashed box is what says a drop is possible and is also the
   click path to the file dialog, so the affordance and the fallback are one
   control. */
.rio-drop{display:block;width:100%;margin-top:8px;padding:14px 12px;text-align:center;
          font:inherit;font-size:12.5px;color:var(--ink-soft,#6b7280);background:none;cursor:pointer;
          border:1.5px dashed var(--border,#e6e6e6);border-radius:10px;min-width:0}
.rio-drop b{display:block;font-size:13px;font-weight:650;color:inherit;margin-bottom:3px}
.rio-drop span{display:block;font-size:11.5px;margin-top:3px;overflow-wrap:anywhere}
.rio-drop:hover,.rio-drop:focus-visible{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);
          background:rgba(21,168,90,.05)}
.rio-card.is-drag{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.rio-card.is-drag .rio-drop{border-color:var(--accent,#15a85a);border-width:2px;padding:13.5px 11.5px;
          color:var(--accent,#15a85a);background:rgba(21,168,90,.07)}

/* ── the progress bar ─────────────────────────────────────────────────────
   WHETHER IT EARNS ITS PLACE HERE, honestly: a review CSV is usually a few
   hundred KB and goes up in under a second, so on a fast line the bar is a
   flicker. It earns it in three cases that are not edge cases on this screen:

     1. THE FILE GOES UP TWICE. The documented order is Check, then Import, so
        every real import uploads the same file a second time.
     2. A WooCommerce export of three thousand reviews with reply text is
        comfortably over a megabyte, and the owner is on a domestic uplink.
     3. THE SERVER PHASE IS THE LONG ONE, and it is the part a bar alone cannot
        show. ReviewCsvImport writes in batches of rows; a bar that reaches 100%
        and then sits there for eight seconds reads as a hung screen. That is
        what the `server` stage below is for -- the bar stops being a percentage
        and says "Reading the file" instead.

   The drop zone is the bigger win. The bar is what stops the second half of the
   run looking like nothing is happening. */
.rio-up{display:grid;gap:5px;margin-top:10px;min-width:0}
.rio-up-top{display:flex;gap:10px;align-items:baseline;justify-content:space-between;min-width:0}
.rio-up-name{font-size:12px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rio-up-pct{font-size:11.5px;font-variant-numeric:tabular-nums;color:var(--ink-soft,#6b7280);flex:none}
.rio-up-x{font:inherit;font-size:11px;font-weight:600;padding:2px 7px;border-radius:6px;flex:none;
          border:1px solid var(--border,#e6e6e6);background:none;color:var(--ink-soft,#6b7280);cursor:pointer}
.rio-up-x[hidden]{display:none}
.rio-up-x:hover{border-color:#b3312c;color:#b3312c}
.rio-up-track{height:6px;border-radius:999px;background:rgba(127,127,127,.18);overflow:hidden}
.rio-up-track > i{display:block;height:100%;width:0;border-radius:999px;
                  background:var(--accent,#15a85a);transition:width .18s linear}
.rio-up.is-bad .rio-up-track > i{background:#b3312c}
.rio-up.is-bad .rio-up-pct{color:#b3312c}
/* Every byte is sent and the answer has not come: the rows are being parsed and
   written. Striped by a keyframe on background-position, so nothing is measured
   and no script runs per frame. */
.rio-up.is-server .rio-up-track > i{width:100% !important;
  background-image:linear-gradient(110deg,rgba(255,255,255,.45) 25%,transparent 25%,
    transparent 50%,rgba(255,255,255,.45) 50%,rgba(255,255,255,.45) 75%,transparent 75%);
  background-size:14px 14px;animation:rio-stripe .7s linear infinite}
@keyframes rio-stripe{from{background-position:0 0}to{background-position:14px 0}}
@media (prefers-reduced-motion: reduce){.rio-up-track > i{transition:none}
  .rio-up.is-server .rio-up-track > i{animation:none}}
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

  /* ── THE REAL CEILING, READ RATHER THAN ASSUMED ───────────────────────────
     Out of the JSON island this partial renders above:
     App\Support\ServerUploadLimits->describe(UPLOAD_MAX_KB) plus the two ini
     strings. Wrapped, and every reader falls back to the behaviour this screen
     had before the island existed -- "send it and let the server decide" -- so a
     malformed island leaves a working importer.

     Four readers, none interchangeable:
       capBytes()  the exact byte ceiling, for the pre-flight. effective_mb is
                   FLOORED, so refusing against effective_mb * 1048576 would
                   refuse a file this server would have taken.
       capWords()  the ceiling as an operator says it -- "512 KB", never "0 MB".
       cappedBy()  which of the three is capping, bounded to the two ini names
                   the island may carry and '' for anything else, because the
                   sentence below switches on it. Rule 5.
       serverIni() that value as the server spells it, for somebody about to go
                   and edit the line. */
  var LIMITS = (function(){
    try {
      var tag = document.getElementById('rio-limits');
      if (!tag) return null;
      var v = JSON.parse(tag.textContent || 'null');
      return (v && typeof v === 'object') ? v : null;
    } catch (e) { return null; }
  })();

  function capBytes(){
    var n = LIMITS && LIMITS.effective_bytes;
    return (typeof n === 'number' && n > 0) ? n : 0;      // 0 = no pre-flight
  }

  function capWords(){
    var w = LIMITS && LIMITS.effective_label;
    return (typeof w === 'string' && w) ? w : '';
  }

  function appWords(){
    var w = LIMITS && LIMITS.app_mb;
    return (typeof w === 'number' && w > 0) ? (w + ' MB') : '';
  }

  function cappedBy(){
    var by = LIMITS && LIMITS.capped_by;
    return (by === 'upload_max_filesize' || by === 'post_max_size') ? by : '';
  }

  function serverIni(name){
    var srv = LIMITS && LIMITS.server;
    if (name !== 'upload_max_filesize' && name !== 'post_max_size') return '';
    return (srv && typeof srv[name] === 'string' && srv[name] !== '') ? srv[name] : 'not readable';
  }

  /** A byte count as an operator would say it. */
  function kb(n){
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return Math.round(n / 1024) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  /**
   * The size half of the import card's sentence.
   *
   * THIS IS THE LINE THAT WAS WRONG. It read "and 4 MB", which is
   * ReviewsIoApiController::UPLOAD_MAX_KB and nothing else -- twice what this
   * server will take. When the server is the thing capping, the directive is
   * NAMED, because an operator cannot act on "2 MB" alone and the whole failure
   * mode being fixed here is a screen that blamed the file.
   */
  function capSentence(){
    var words = capWords();
    if (!words) return '';

    if (!cappedBy()) return words;

    /* NO LEADING "up to". This phrase goes in two places -- the drop zone, which
       puts "Up to " in front of it, and the legend, which reads "Up to 25,000
       rows and <this>". Carrying the words in the phrase made the legend say
       "rows and up to 2 MB", which was measured in Chromium before it was read. */
    var by = cappedBy();
    return words + ' — this server’s own ' + by + ' (' + serverIni(by) + '), '
      + 'not a limit of the shop’s' + (appWords() ? ', which allows ' + appWords() : '');
  }

  /* ── THE UPLOAD, AND WHY IT IS NOT window.kbbUpload ───────────────────────

     This screen does NOT route through the console's shared uploader, and the
     reason is one specific field. window.kbbUpload reports a failure as
     { status, message, retryable } -- the parsed response body is not in it.

     A 422 FROM THIS ENDPOINT IS THE REPORT. "No rating column", the per-row
     rejections with their reasons, the counts: all of it arrives in the body of a
     422, and run() below renders it as the report card rather than throwing it
     away behind a status code. Sending this screen through a callback that
     carries only `message` would replace a table of three hundred rejected rows
     with one sentence, silently. That is a regression, not a refactor.

     The drop zone IS taken from the kit -- window.kbbDropZone's contract fits
     this screen exactly -- so the divergence is one function wide and is
     recorded here rather than left to be discovered. The moment onFail carries
     the parsed body, this should become send({...}) like the other two screens.

     XMLHttpRequest and not fetch, for the reason every uploader in this console
     uses it: fetch cannot report UPLOAD progress. Its request body is consumed
     opaquely, so the best it can offer is a spinner. It is NOT a second import
     path -- same endpoint, same fields, same CSRF header, same server rules; a
     different transport.
   *
   * { url, file, field, extra, max, onProgress, onStage, onDone, onFail }
   * -> { cancel() }.  Deliberately the same shape as the shared kit, so the day
   * onFail grows a body this is a two-line change.
   */
  function rioUpload(o){
    var fired = false;

    function stage(st){ if (typeof o.onStage === 'function') o.onStage(st); }

    function fail(f){
      if (fired) return;
      fired = true;
      stage('failed');
      if (typeof o.onFail === 'function') o.onFail(f);
    }

    if (typeof o.max === 'number' && o.max > 0 && o.file && o.file.size > o.max) {
      fail({ status: 0, message: 'Larger than this server will accept.', body: null, retryable: false });
      return { cancel: function(){} };
    }

    var fd = new FormData();
    fd.append(o.field || 'file', o.file);

    var extra = o.extra || {};
    Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', o.url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    /* No Content-Type header, ever: only the browser knows the multipart
       boundary it is about to generate, and setting it by hand is the classic
       way to make every upload arrive empty. There is no csrf-token meta tag in
       this console, so the cookie is the only source for the token. */
    xhr.setRequestHeader('X-XSRF-TOKEN', cookie('XSRF-TOKEN'));

    if (xhr.upload) {
      xhr.upload.onprogress = function(e){
        // lengthComputable is false for a chunked request, and a fabricated
        // percentage is worse than none.
        if (!e.lengthComputable || !(e.total > 0)) return;
        if (typeof o.onProgress === 'function') {
          o.onProgress({
            loaded: e.loaded,
            total: e.total,
            /* Capped at 99 while sending. On this screen the gap between the
               last byte and the answer is not a formality -- it is the parse and
               up to 25,000 rows written in batches -- and a bar reading 100%
               through all of it is how a working import looks like a hung page. */
            pct: Math.min(99, Math.round((e.loaded / e.total) * 100))
          });
        }
      };
      xhr.upload.onload = function(){ stage('server'); };
    }

    xhr.onload = function(){
      if (fired) return;

      var body = null;
      try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }

      if (xhr.status >= 200 && xhr.status < 300) {
        fired = true;
        if (typeof o.onProgress === 'function') o.onProgress({ loaded: 1, total: 1, pct: 100 });
        stage('done');
        if (typeof o.onDone === 'function') o.onDone(body);
        return;
      }

      fail({
        status: xhr.status,
        message: (body && (body.message || body.error)) || '',
        body: body,
        retryable: xhr.status >= 500
      });
    };

    xhr.onerror = function(){
      fail({ status: 0, message: 'Could not reach the server.', body: null, retryable: true });
    };

    xhr.onabort = function(){
      if (fired) return;
      fired = true;
      stage('cancelled');
    };

    xhr.send(fd);

    return { cancel: function(){ try { xhr.abort(); } catch (e) {} } };
  }

  /* ── THE DROP ZONE, WHICH *IS* THE KIT'S ──────────────────────────────────
     window.kbbDropZone(el, {accept, multiple, onFiles}) when the console's
     shared kit is on the page; the wiring below when it is not. Guarded the way
     every call site here guards window.kbbPickMedia. A teardown function comes
     back either way, and this screen uses it: render() replaces #content
     wholesale on every repaint. */
  function dropZone(node, o){
    if (typeof window.kbbDropZone === 'function') return window.kbbDropZone(node, o);
    return localDropZone(node, o);
  }

  /** True when the pointer carries files from outside the page. */
  function carriesFiles(e){
    var types = e.dataTransfer && e.dataTransfer.types;
    if (!types) return false;
    return Array.prototype.indexOf.call(types, 'Files') !== -1;
  }

  function localDropZone(node, o){
    /* dragenter and dragleave fire once per element the pointer crosses, and this
       card is full of them -- rows, selects, switches. Counting is the only way to
       know the pointer has really left: a plain dragleave handler drops the
       highlight the moment the pointer moves from the card onto a control inside
       it, which reads as the target flickering. */
    var depth = 0;

    function enter(e){
      if (!carriesFiles(e)) return;
      e.preventDefault();
      depth++;
      node.classList.add('is-drag');
    }

    function over(e){
      if (!carriesFiles(e)) return;
      /* preventDefault is what makes this a valid drop target at all, and it is
         also what stops the browser navigating away to the dropped file. */
      e.preventDefault();
      try { e.dataTransfer.dropEffect = 'copy'; } catch (x) {}
    }

    function leave(e){
      if (!carriesFiles(e)) return;
      depth = Math.max(0, depth - 1);
      if (depth === 0) node.classList.remove('is-drag');
    }

    function drop(e){
      if (!carriesFiles(e)) return;
      e.preventDefault();
      depth = 0;
      node.classList.remove('is-drag');

      var files = (e.dataTransfer && e.dataTransfer.files) || null;
      if (!files || !files.length) return;

      var list = Array.prototype.slice.call(files).filter(function(f){
        return matchesAccept(f, o && o.accept);
      });

      if (!list.length) return;
      if (!(o && o.multiple)) list = list.slice(0, 1);
      if (o && typeof o.onFiles === 'function') o.onFiles(list);
    }

    node.addEventListener('dragenter', enter);
    node.addEventListener('dragover', over);
    node.addEventListener('dragleave', leave);
    node.addEventListener('drop', drop);

    return function teardown(){
      node.removeEventListener('dragenter', enter);
      node.removeEventListener('dragover', over);
      node.removeEventListener('dragleave', leave);
      node.removeEventListener('drop', drop);
      node.classList.remove('is-drag');
    };
  }

  /**
   * Does a dropped file match an `accept` string?
   *
   * Only the two forms this console uses are understood -- a type/* wildcard and
   * a .ext suffix -- and ANYTHING NOT UNDERSTOOD IS ACCEPTED. A client filter
   * that guesses wrong discards the operator's file and shows nothing, and this
   * screen's accept list is the awkward one: Excel, Numbers and half the
   * WordPress exporters write a .csv that the browser reports as text/plain, as
   * an empty type, or as application/vnd.ms-excel. So the EXTENSION is what
   * usually matches here, the server checks it again, and the parser trusts
   * neither. This exists to stop a dropped folder and an obviously wrong file.
   */
  function matchesAccept(file, accept){
    if (!accept) return true;

    var name = String((file && file.name) || '').toLowerCase();
    var type = String((file && file.type) || '').toLowerCase();

    return String(accept).split(',').some(function(rule){
      rule = rule.trim().toLowerCase();
      if (!rule) return false;
      if (rule.charAt(0) === '.') return name.slice(-rule.length) === rule;
      if (rule.slice(-2) === '/*') return type.indexOf(rule.slice(0, -1)) === 0;
      if (rule.indexOf('/') !== -1) return type === rule;
      return true;
    });
  }

  /* ── THE ONE UPLOAD IN FLIGHT ─────────────────────────────────────────────
     One file at a time on this screen, by definition: there is one importer and
     it takes one CSV. `up` is null when nothing is going up.
     {name, size, pct, state, error, handle};
     state is 'sending' | 'server' | 'done' | 'failed' | 'cancelled'. */
  var up = null;

  /** The teardown for the import card's drop zone; see dropZone(). */
  var zone = null;

  function upPct(){
    if (!up) return 0;
    if (up.state === 'done' || up.state === 'server') return 100;
    return up.pct || 0;
  }

  function upLabel(){
    if (!up) return '';
    if (up.state === 'failed') return up.error || 'Failed';
    if (up.state === 'cancelled') return 'Stopped';
    /* NOT "100%". The bytes are all gone and the rows are being parsed and
       written in batches -- naming that is the difference between a screen that
       looks busy and a screen that looks hung. */
    if (up.state === 'server') return 'Reading the file…';
    if (up.state === 'done') return 'Read';
    return upPct() + '%';
  }

  function upHTML(){
    if (!up) return '';

    var pct = upPct();
    var live = up.state === 'sending' || up.state === 'server';

    return '<div class="rio-up' + (up.state === 'failed' ? ' is-bad' : '')
      + (up.state === 'server' ? ' is-server' : '') + '" id="rio-up">'
      + '<div class="rio-up-top"><span class="rio-up-name">' + esc(up.name) + '</span>'
      +   '<span class="rio-up-pct">' + esc(upLabel()) + '</span>'
      +   '<button type="button" class="rio-up-x" id="rio-up-x"' + (live ? '' : ' hidden') + '>Stop</button>'
      + '</div>'
      /* aria-valuenow beside the width, so the bar is not a purely visual fact. */
      + '<div class="rio-up-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
      +   ' aria-valuenow="' + pct + '" aria-label="' + esc(up.name) + '"><i style="width:' + pct + '%"></i></div>'
      + '</div>';
  }

  /* Patched in place, NEVER through render(). A repaint on every progress event
     would replace the file input -- whose selection cannot be restored from
     script -- which is the defect the comment above `chosen` describes, and it
     would do it forty times a second. This touches the bar, its label, its class
     and its Stop. */
  function paintUp(){
    var row = document.querySelector('#rio-up');
    if (!row || !up) return;

    var pct = upPct();
    var bar = row.querySelector('.rio-up-track > i');
    var track = row.querySelector('.rio-up-track');
    var lab = row.querySelector('.rio-up-pct');
    var x = row.querySelector('#rio-up-x');

    if (bar) bar.style.width = pct + '%';
    if (track) track.setAttribute('aria-valuenow', String(pct));
    if (lab) lab.textContent = upLabel();
    if (x) x.hidden = !(up.state === 'sending' || up.state === 'server');
    row.className = 'rio-up' + (up.state === 'failed' ? ' is-bad' : '')
      + (up.state === 'server' ? ' is-server' : '');
  }

  /* ── STOP HAS TO SETTLE THE QUEUE, NOT JUST ABORT THE REQUEST ─────────────
     FOUND IN CHROMIUM, NOT IN A TEST, AND IT HUNG THE SCREEN. Cancelling is not
     a failure, so the contract reports it as onStage('cancelled') and NOT as
     onFail -- and the first version of this queue resolved its promise only from
     onDone and onFail. So pressing Stop aborted the request, painted the row
     "Stopped", and then awaited a promise that nothing would ever settle: the
     loop never reached the next file, `busy` stayed true, and the screen sat
     greyed out until it was reloaded. Measured: the row read "Stopped", the banner never
     appeared and both buttons stayed disabled.
     The upload therefore carries its own settle(), called from BOTH ends -- the
     'cancelled' stage, and stopOne() itself. Resolving a promise twice is a
     no-op, so the belt and the braces cannot disagree, and a transport that
     forgets to report the abort at all cannot wedge the queue. */

  function stopUp(){
    if (!up || !(up.state === 'sending' || up.state === 'server')) return;
    up.state = 'cancelled';
    if (up.handle && typeof up.handle.cancel === 'function') {
      try { up.handle.cancel(); } catch (e) {}
    }
    paintUp();

    // The braces. See the note above.
    if (typeof up.settle === 'function') up.settle();
  }

  /* ------------------------------------------------------------- the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    // No bar and no zone carried in from a previous visit to this screen.
    up = null;
    if (zone) { try { zone(); } catch (e) {} zone = null; }

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
  /* `keep` is set by the caller that has just put a message on screen it wants
     to survive the refresh. Reloading the totals after an import used to clear
     the banner that said the import had worked, so a successful import of three
     thousand reviews ended with a report card and no sentence above it. */
  async function load(keep){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/reviews-io/summary');
      if (mine !== seq) return;
      data = body;
      if (body.timezone) form.timezone = body.timezone;
      if (!keep) banner = null;
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

  /* THE CHOSEN FILE IS HELD HERE, NOT IN THE INPUT ELEMENT.

     A File object stays valid for as long as anything references it; the
     <input> that produced it does not. render() rewrites #content, which
     replaces that input with a fresh one holding nothing, and a file input's
     selection cannot be restored from script.

     run() re-renders twice — once to disable the buttons and print "Working…",
     once to draw the report — so by the time the report was on screen the input
     was empty while the caption under it still showed the file name. The
     documented sequence is CHECK first and then IMPORT, and the second press
     answered "Choose a CSV file first." with the file name printed directly
     above the message. Reproduced in Chromium: after Check, #rio-file.files.length
     was 0 and .rio-name still read "import-sample.csv".

     Holding the File itself costs nothing and survives any number of renders.
     bind() re-arms the input on every render and writes here; the input remains
     the only way to CHOOSE a file, it is simply no longer the only place the
     choice is kept. */
  var chosen = null;

  async function run(mode){
    var input = document.querySelector('#rio-file');
    var file = (input && input.files && input.files[0]) || chosen;

    if (!file) {
      banner = {kind:'err', text:'Choose a CSV file first.'};
      render();
      return;
    }

    chosen = file;
    form.filename = file.name;

    /* ── THE PRE-FLIGHT ──────────────────────────────────────────────────────
       Refused before a byte leaves the machine, with the size, the ceiling and
       the directive in one sentence.

       WITHOUT THIS, a 3 MB WooCommerce export on this box did not come back "too
       big". PHP's rfc1867 handler discards a file over upload_max_filesize and
       hands Laravel an upload with error=1 and size=0, so `required|file` failed
       and the 422 said the file was not a file. Over post_max_size it is worse:
       the whole body is thrown away before the router, ValidatePostSize answers
       413 with a `message` and no `error` key, and this screen printed "Could not
       read that file (413)". Neither refusal named a size, and both blamed a file
       that was fine. */
    var ceiling = capBytes();

    if (ceiling && file.size > ceiling) {
      banner = {kind:'err', text: 'That file is ' + kb(file.size) + '. This server accepts '
        + capWords() + (cappedBy() ? ' (' + cappedBy() + ' = ' + serverIni(cappedBy()) + ')' : '')
        + '. Split the export, or raise that limit on the server.'};
      render();
      return;
    }

    working = true;
    banner = null;
    report = null;
    up = { name: file.name || 'reviews.csv', size: file.size || 0, pct: 0, state: 'sending' };
    render();

    /* The body is assembled exactly as it was; only the transport changed, from
       fetch (which cannot report upload progress) to XMLHttpRequest. Same
       endpoint, same field names, same values. */
    var extra = {
      mode: mode,
      on_duplicate: form.on_duplicate,
      allow_business: form.allow_business ? '1' : '0',
      timezone: form.timezone
    };

    var outcome = await new Promise(function(resolve){
      /* The one place this upload's promise can be settled from. Assigned before
         the request starts, because Stop can arrive on the very next tick. */
      up.settle = function(){ resolve({ stopped: true }); };

      up.handle = rioUpload({
        url: apiBase() + '/reviews-io/import',
        file: file,
        field: 'file',
        extra: extra,
        // Belt on the pre-flight's braces; the two cannot both fire.
        max: ceiling || undefined,
        onProgress: function(p){
          if (!up || up.state !== 'sending') return;
          up.pct = Math.max(0, Math.min(100, Math.round((p && p.pct) || 0)));
          paintUp();
        },
        onStage: function(st){
          // The belt. Cancelling is reported here and never through onFail.
          if (st === 'cancelled') {
            if (up) { up.state = 'cancelled'; paintUp(); }
            resolve({ stopped: true });
            return;
          }
          if (!up || up.state === 'cancelled') return;
          if (st === 'server') { up.state = 'server'; paintUp(); }
        },
        onDone: function(body){
          if (up && up.state === 'cancelled') { resolve({ stopped: true }); return; }
          if (up) { up.state = 'done'; up.pct = 100; paintUp(); }
          resolve({ body: body });
        },
        onFail: function(f){
          if (up && up.state === 'cancelled') { paintUp(); resolve({ stopped: true }); return; }
          if (up) {
            up.state = 'failed';
            up.error = failWords(f);
            paintUp();
          }
          resolve({ fail: f });
        }
      });
    });

    if (outcome.stopped) {
      banner = {kind:'err', text: (mode === 'check' ? 'Check stopped.' : 'Import stopped.')
        + ' Nothing past the point it was stopped was written.'};
      working = false;
      render();
      return;
    }

    if (outcome.body) {
      report = outcome.body;
      banner = {
        kind: 'ok',
        text: mode === 'check'
          ? 'Checked. Nothing has been written — press Import to apply it.'
          : 'Imported. The product pages and the star ratings are up to date.'
      };
      say(mode === 'check' ? 'File checked' : 'Reviews imported');
      /* Cleared only after a real import. A checked file is still the file the
         owner is about to import, and dropping it here would put the defect
         above back one line lower down. */
      if (mode === 'import') { chosen = null; form.filename = ''; up = null; load(true); }
    } else {
      var f = outcome.fail || {};

      /* A 422 from the importer IS a report -- "no rating column", the row
         rejections -- and is far more useful than the status code. It is shown as
         the report rather than thrown away behind a generic failure. THIS is the
         reason this screen does not go through window.kbbUpload: its onFail
         carries { status, message, retryable } and not the body, and losing the
         body here loses the rejection table. */
      if (f.body && (f.body.message || f.body.rejects)) {
        report = f.body;
        banner = {kind:'err', text: f.body.message || 'That file could not be imported.'};
      } else {
        report = null;
        banner = {kind:'err', text: failWords(f)};
      }
    }

    working = false;
    render();
  }

  /**
   * What a failure says, in the operator's own units.
   *
   * 413 IS ITS OWN CASE. Laravel 11's global ValidatePostSize throws before the
   * router when the whole body is over post_max_size; its response carries
   * `message` and no `error` key. MEASURED in Chromium with a 9 MB CSV on this
   * box, the banner this screen printed was, in full:
   *
   *     The POST data is too large.
   *
   * That is Laravel's own wording, and it is what the owner was shown: no size,
   * no ceiling, no directive, and the same sentence for a 9 MB file as for a
   * 900 MB one. It is REPLACED here rather than printed.
   */
  function failWords(f){
    var status = (f && f.status) || 0;
    var msg = String((f && f.message) || '');

    if (status === 413) {
      var by = cappedBy() || 'post_max_size';
      return 'That file is too big for this server (' + by + ' = ' + serverIni(by) + ')'
        + (capWords() ? '. The most it takes is ' + capWords() : '')
        + '. Split the export, or raise that limit on the server.';
    }

    if (msg) return msg;
    return 'Could not read that file (' + (status || 'network') + ').';
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
    /* THE SIZE HALF OF THIS SENTENCE USED TO BE data.limits.max_kb / 1024, which
       is the controller's 4 MB and nothing else -- twice what this server takes.
       It is now the ceiling read off the host, and it names the directive when
       the host is the thing capping. The ROW limit still comes from the server
       payload, because 25,000 rows is genuinely the app's own rule. */
    var size = capSentence();

    return '<div class="rio-card" id="rio-import-card">' +
      '<p class="rio-legend">Import</p>' +
      '<p class="rio-legend-sub">A review export from WooCommerce, or a file from the Export box above. ' +
      'Up to ' + esc(num(data.limits.max_rows)) + ' rows' + (size ? ' and ' + esc(size) : '') +
      '; rows are written ' + esc(num(data.limits.batch)) + ' at a time so a large file cannot hold the ' +
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
        /* The dashed box says a drop is possible and is also the click path to
           the file dialog. The DROP itself belongs to the whole card -- see the
           note on .rio-drop. */
        '<button type="button" class="rio-drop" id="rio-drop"><b>Drop your CSV here</b>' +
          'or tap to choose a file' +
          (size ? '<span>Up to ' + esc(size) + '</span>' : '') + '</button>' +
        upHTML() +
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

    /* The screen's own heading as a header ROW, not a bordered card. The bar
       above #content already carries the name; a full-width card repeating it
       pushed the totals below the fold on a phone. */
    html += '<div class="rio-headrow">' +
            '<div class="rio-title">Review Import / Export</div>' +
            '<div class="rio-sub">Take your reviews out as a spreadsheet, or bring the ones from your old ' +
            'WooCommerce shop in. Importing the same file twice will not duplicate anything.</div>' +
            '</div>';

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
           and it is always in the markup for that reason. The File itself is
           kept in `chosen`, which is what run() falls back to once a render has
           emptied the input. */
        chosen = (file.files && file.files[0]) || null;
        form.filename = chosen ? chosen.name : '';
        banner = null;

        var label = document.querySelector('.rio-name');
        if (label) label.textContent = form.filename;

        var warn = document.querySelector('.rio-banner');
        if (warn && warn.parentNode) warn.parentNode.removeChild(warn);

        /* The previous file's bar goes with the previous file. Removed from the
           DOM by hand rather than by render(), for the reason above: a repaint
           replaces this input and its selection cannot be restored from script. */
        up = null;

        var old = document.querySelector('#rio-up');
        if (old && old.parentNode) old.parentNode.removeChild(old);
      };
    }

    /* ── THE DROP ZONE ───────────────────────────────────────────────────
       On the whole import card, registered through the console's shared kit when
       it is on the page. Torn down first: render() replaces #content wholesale,
       so the node this was bound to on the previous repaint is no longer in the
       document -- and this screen repaints on every switch, select and button. */
    if (zone) { try { zone(); } catch (e) {} zone = null; }

    var card = document.querySelector('#rio-import-card');

    if (card) {
      zone = dropZone(card, {
        /* The SAME list the input above declares, and it has to be repeated:
           a drop never goes near the input, and the browser applies an accept
           attribute to its own file dialog and to nothing else. Without it,
           dropping a .jpg on this card would post it to the review importer. */
        accept: '.csv,.txt,text/csv,text/plain',
        multiple: false,
        onFiles: function(files){
          var f = files[0];
          if (!f) return;

          /* THE INPUT IS EMPTIED, and that is load-bearing. run() reads
             `(input && input.files && input.files[0]) || chosen` -- the live input
             FIRST, because it is where a new choice normally comes from. So
             choosing A through the dialog and then dropping B would have imported
             A while the caption read B. Clearing the input is what makes the
             dropped file the chosen one. */
          var live = document.querySelector('#rio-file');
          if (live) { try { live.value = ''; } catch (e) {} }

          chosen = f;
          form.filename = f.name;
          banner = null;
          up = null;

          /* A full render is safe HERE and nowhere near it: there is no
             selection left in the input to lose -- it was just emptied -- and the
             card has to redraw to drop the old progress row and the old banner.
             The file itself lives in `chosen`, which survives any repaint. */
          render();
        }
      });
    }

    var stop = document.querySelector('#rio-up-x');
    if (stop) stop.onclick = function(){ stopUp(); };

    var dz = document.querySelector('#rio-drop');
    if (dz) dz.onclick = function(){
      var live = document.querySelector('#rio-file');
      if (live) live.click();
    };

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
