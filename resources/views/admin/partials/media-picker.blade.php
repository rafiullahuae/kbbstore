{{--
    Shared media picker — the one dialog every image field in the console opens.

    WHY IT IS ITS OWN PARTIAL, INCLUDED BEFORE THE SCREENS.
    Five screens reach images through imgUploadField()/wireImgUpload() in
    app.blade.php, the product editor reaches them through three of its own
    controls, and the rich-text boxes had no way to insert one at all. Building
    a picker into any one of those would have left the others uploading the same
    photograph again — which is the owner's actual complaint. One module, called
    from all of them, is the only shape that fixes it everywhere at once.

    It is a PURE CONSUMER of endpoints that already exist: GET /admin-api/media
    for the grid and its search, POST /admin-api/media/upload for a new file.
    Nothing in app/ changes to support it, which also means it cannot collide
    with work in flight on the Media Library screen itself.

    THE DIALOG IS BUILT ONCE AND REUSED. A picker rebuilt per call would lose
    the browser's own focus handling and leak a listener per open.
--}}
@php
    /*
     * ── THE REAL CEILING, READ FROM THIS SERVER RATHER THAN ASSUMED ─────────
     *
     * The owner's words for this lane were "put on max limit, without assuming
     * anything", and the reason this dialog needs it is the reason
     * App\Support\ServerUploadLimits exists: Admin\MediaUploadController allows
     * 5 MB, this box's PHP allows 2M per file inside an 8M body, and until now
     * the picker advertised NO number and refused a perfectly good 3 MB
     * photograph with "The file field must be a file."
     *
     * Rendered here, server-side, rather than fetched: this partial is a PURE
     * CONSUMER of two endpoints that already existed (MediaPickerWiringTest
     * pins that every apiBase() call in this file is a /media one), and a
     * third round trip to learn a number PHP already knows at render time
     * would be a query budget spent on nothing.
     */
    $mpLimitReader = app(\App\Support\ServerUploadLimits::class);

    /*
     * Admin\MediaUploadController::MAX_BYTES, which is private. Duplicated
     * here as a literal and NOT left to drift: MediaPickerUploadLimitTest
     * reflects that constant and fails if the two disagree, which is the same
     * trade the console makes everywhere it prints a controller's cap.
     */
    $mpAppCap = 5 * 1024 * 1024;

    $mpLimits = $mpLimitReader->describe($mpAppCap);
    $mpLimits['server'] = $mpLimitReader->raw();
@endphp
{{--
    A JSON island rather than a `window.` assignment, and every one of the four
    HEX flags on purpose: the two values in `server` are ini strings read off
    the host, so they are not this application's constants and rule 5 applies to
    them exactly as it would to a setting. JSON_HEX_TAG alone makes a closing
    script tag unrepresentable; the client parses this in a try/catch and prints
    every string through the same esc() the tiles use.
--}}
<script type="application/json" id="mp-limits">@json($mpLimits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
@verbatim
<style>
/* Prefix mp-, used nowhere else in the console. */
.mp-back{position:fixed;inset:0;background:rgba(16,24,40,.55);z-index:900;
         display:flex;align-items:center;justify-content:center;padding:18px}
.mp-back[hidden]{display:none}
.mp-box{background:var(--surface,#fff);border-radius:var(--r,16px);width:min(1080px,100%);
        max-height:min(860px,92vh);display:flex;flex-direction:column;min-width:0;min-height:0;
        box-shadow:var(--sh-l,0 24px 60px -22px rgba(16,24,40,.30));overflow:hidden}
.mp-head{display:flex;gap:12px;align-items:center;justify-content:space-between;
         padding:15px 18px;border-bottom:1px solid var(--border,#e6e9f2);min-width:0}
.mp-title{font-weight:650;font-size:15px;margin:0;min-width:0}
.mp-sub{font-size:11.5px;color:var(--ink-soft,#626c80);margin:2px 0 0}
.mp-tools{display:grid;gap:10px;padding:12px 18px;min-width:0;
          border-bottom:1px solid var(--border,#e6e9f2)}
/* Two rows: search and Upload new on top, the narrowing filters beneath.
   Filters wrap into as many columns as fit rather than being forced onto one
   line, so a 390px dialog stacks them instead of squeezing each to nothing. */
.mp-row{display:flex;gap:9px;align-items:center;flex-wrap:wrap;min-width:0}
.mp-filters{display:grid;gap:9px;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));min-width:0}
.mp-fld{display:grid;gap:3px;min-width:0}
.mp-lab{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
        color:var(--ink-soft,#626c80)}
.mp-q{flex:1 1 220px;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
      border:1px solid var(--border,#e6e9f2);border-radius:9px;background:var(--surface,#fff);color:inherit}
.mp-in{width:100%;min-width:0;padding:7px 9px;font:inherit;font-size:12.5px;
       border:1px solid var(--border,#e6e9f2);border-radius:8px;background:var(--surface,#fff);color:inherit}
.mp-in:disabled{opacity:.5;cursor:default}
.mp-cols{display:flex;gap:4px;align-items:center;flex:none}
.mp-cols button{font:inherit;font-size:12px;font-weight:600;min-width:30px;padding:6px 7px;
                border-radius:7px;border:1px solid var(--border,#e6e9f2);
                background:var(--surface,#fff);color:var(--ink-soft,#626c80);cursor:pointer}
.mp-cols button.on{border-color:var(--accent,#15a85a);color:var(--accent-ink,#0b6e3a);
                   background:var(--accent-soft,#e7f7ee)}
.mp-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 12px;border-radius:9px;
        border:1px solid var(--border,#e6e9f2);background:var(--surface,#fff);color:inherit;cursor:pointer}
.mp-btn:hover{border-color:var(--accent,#15a85a);color:var(--accent-ink,#0b6e3a)}
.mp-btn.mp-primary{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.mp-btn.mp-primary:hover{background:var(--accent-strong,#0f8f4b);color:#fff}
.mp-btn[disabled]{opacity:.45;cursor:default}
.mp-btn[disabled]:hover{border-color:var(--border,#e6e9f2);color:inherit}
.mp-body{flex:1 1 auto;overflow:auto;padding:16px 18px;min-height:0;min-width:0}
/* auto-fill, not auto-fit: with two images in the library, auto-fit stretches
   each tile across the whole dialog. */
/* The column count is an inline custom property set by the chooser; the
   fallback keeps auto-fill for a browser that never receives one. */
.mp-grid{display:grid;gap:12px;min-width:0;
         grid-template-columns:repeat(auto-fill,minmax(140px,1fr))}
.mp-grid[style*="--mp-cols"]{grid-template-columns:repeat(var(--mp-cols),minmax(0,1fr))}
.mp-tile{display:flex;flex-direction:column;gap:0;text-align:left;padding:0;min-width:0;cursor:pointer;
         background:var(--surface,#fff);border:1.5px solid var(--border,#e6e9f2);border-radius:11px;
         overflow:hidden;color:inherit;font:inherit}
.mp-tile:hover{border-color:var(--accent,#15a85a)}
.mp-tile.on{border-color:var(--accent,#15a85a);box-shadow:0 0 0 3px rgba(21,168,90,.16)}
/* ── THE SKELETON (Lane SP) ──────────────────────────────────────────────
   The dialog used to open on one grey sentence and sit there until /media
   answered, which on a slow connection reads as a dialog that has not loaded.
   Now it opens on the SHAPE of the grid, so the frame, the columns and the
   tile size are all on screen before the first byte comes back and the real
   tiles land into a layout that is already there instead of pushing one into
   existence.

   PURE CSS, AND NO ANIMATION THAT IGNORES A PREFERENCE. The shimmer is a
   background-position keyframe on the placeholder alone -- nothing moves, no
   layout is measured, and prefers-reduced-motion stops it dead and leaves a
   plain grey block, which is still the right shape. */
.mp-skel{border:1.5px solid var(--border,#e6e9f2);border-radius:11px;overflow:hidden;
         background:var(--surface,#fff)}
.mp-skel i{display:block;aspect-ratio:1;background:var(--surface-2,#f2f4fb)}
.mp-skel u{display:block;height:58px;padding:7px 9px 9px;box-sizing:border-box;text-decoration:none}
.mp-skel u:before{content:"";display:block;height:9px;border-radius:4px;
                  background:var(--surface-2,#f2f4fb);width:78%}
.mp-skel u:after{content:"";display:block;height:8px;border-radius:4px;margin-top:7px;
                 background:var(--surface-2,#f2f4fb);width:46%}
.mp-skel i,.mp-skel u:before,.mp-skel u:after{
  background-image:linear-gradient(90deg,rgba(255,255,255,0) 0%,rgba(255,255,255,.72) 50%,rgba(255,255,255,0) 100%);
  background-size:240px 100%;background-repeat:no-repeat;animation:mp-sheen 1.15s linear infinite}
@keyframes mp-sheen{from{background-position:-240px 0}to{background-position:calc(100% + 240px) 0}}
@media (prefers-reduced-motion:reduce){
  .mp-skel i,.mp-skel u:before,.mp-skel u:after{animation:none;background-image:none}
}
.mp-thumb{position:relative;aspect-ratio:1;background:var(--surface-2,#f2f4fb);display:block}
.mp-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.mp-tick{position:absolute;top:6px;right:6px;width:21px;height:21px;border-radius:50%;
         background:var(--accent,#15a85a);color:#fff;font-size:12px;line-height:21px;text-align:center;display:none}
.mp-tile.on .mp-tick{display:block}
/* EVERY TILE THE SAME HEIGHT. Captions are filenames and run from one word to
   five, so left alone the rows came out ragged — a grid of steps rather than a
   grid. The name is clamped to two lines and the caption height is fixed, so
   tiles line up whatever they are called. */
.mp-cap{padding:7px 9px 9px;min-width:0;display:grid;gap:3px;align-content:start;height:58px}
/* -webkit-line-clamp hides the overflowing TEXT but does not shrink the box it
   is in, so inside a fixed-height grid the third line still took its row and
   sat on top of the dimensions underneath. The explicit height is what keeps
   the two apart; the clamp is what stops the third line being drawn. Both are
   needed — the screenshot of a long product name is what showed it. */
.mp-name{font-size:11.5px;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;
         -webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere;
         line-height:1.3;height:2.6em}
.mp-dim{font-size:10.5px;color:var(--ink-soft,#626c80);display:block;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mp-foot{display:flex;gap:10px;align-items:center;justify-content:space-between;
         padding:12px 18px;border-top:1px solid var(--border,#e6e9f2);flex-wrap:wrap;min-width:0}
.mp-count{font-size:12px;color:var(--ink-soft,#626c80);min-width:0}
.mp-note{padding:26px 12px;text-align:center;color:var(--ink-soft,#626c80);font-size:13px}
.mp-ups{display:grid;gap:8px;margin-bottom:14px;min-width:0}
.mp-up{display:grid;gap:5px;min-width:0}
.mp-up-top{display:flex;gap:10px;justify-content:space-between;align-items:baseline;min-width:0}
.mp-up-name{font-size:12px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mp-up-pct{font-size:11.5px;font-variant-numeric:tabular-nums;color:var(--ink-soft,#626c80);flex:none}
.mp-up-track{height:6px;border-radius:999px;background:var(--surface-2,#f2f4fb);overflow:hidden}
.mp-up-track > i{display:block;height:100%;width:0;border-radius:999px;
                 background:var(--accent,#15a85a);transition:width .18s linear}
.mp-up.is-bad .mp-up-track > i{background:var(--red,#e3493f)}
.mp-up.is-bad .mp-up-pct{color:var(--red,#e3493f)}
/* A file whose bytes are all sent but whose answer has not arrived. The stripe
   is what stops a bar parked at 99% reading as a stall -- that pause is the
   server reading, resizing and recording the image, and on a 4000x4000 JPEG it
   is half a second of real work. Animated with a background-position keyframe
   rather than by script, so nothing measures and nothing runs per frame in JS. */
.mp-up.is-server .mp-up-track > i{width:100% !important;
  background-image:linear-gradient(110deg,rgba(255,255,255,.45) 25%,transparent 25%,
    transparent 50%,rgba(255,255,255,.45) 50%,rgba(255,255,255,.45) 75%,transparent 75%);
  background-size:14px 14px;animation:mp-stripe .7s linear infinite}
@keyframes mp-stripe{from{background-position:0 0}to{background-position:14px 0}}
.mp-up.is-gone .mp-up-track > i{background:var(--ink-soft,#626c80)}
/* Cancel sits on the row, not in the footer: a batch of six photographs has six
   things that might need stopping and only one of them is the one in flight. */
.mp-up-x{font:inherit;font-size:11px;font-weight:600;padding:2px 7px;border-radius:6px;flex:none;
         border:1px solid var(--border,#e6e9f2);background:var(--surface,#fff);color:var(--ink-soft,#626c80);cursor:pointer}
.mp-up-x:hover{border-color:var(--red,#e3493f);color:var(--red,#e3493f)}
.mp-up-x[hidden]{display:none}
.mp-up-head{display:flex;gap:10px;align-items:baseline;justify-content:space-between;
            font-size:11.5px;font-weight:650;color:var(--ink-soft,#626c80);min-width:0}
.mp-up-stop{font:inherit;font-size:11px;font-weight:600;padding:2px 8px;border-radius:6px;flex:none;
            border:1px solid var(--border,#e6e9f2);background:var(--surface,#fff);color:inherit;cursor:pointer}

/* ── the drop zone ────────────────────────────────────────────────────────
   Two targets, deliberately, and they are not redundant:

   .mp-drop is the one that TELLS the operator dropping is possible, and it is
   also the click target that opens the file dialog -- so the affordance and the
   fallback are the same control.

   The whole dialog is the second, because a 1080px modal with a 140px dashed
   box in it makes the operator aim. Dropping a photograph anywhere over the
   picker uploads it. That also closes a real hole: nothing in this console
   prevents the default drop, so a file let go over an ordinary part of the page
   makes the browser NAVIGATE AWAY to it -- losing whatever was half-typed
   behind the dialog. While the picker is open, that can no longer happen. */
.mp-drop{display:block;width:100%;text-align:center;font:inherit;color:var(--ink-soft,#626c80);
         border:1.5px dashed var(--border,#e6e9f2);border-radius:11px;padding:14px 12px;margin-bottom:14px;
         background:var(--surface,#fff);cursor:pointer;min-width:0}
.mp-drop b{display:block;font-size:13px;font-weight:650;color:inherit;margin-bottom:3px}
.mp-drop span{display:block;font-size:11.5px;overflow-wrap:anywhere}
.mp-drop:hover,.mp-drop:focus-visible{border-color:var(--accent,#15a85a);color:var(--accent-ink,#0b6e3a);
        background:var(--accent-soft,#e7f7ee)}
/* is-over is set on the ZONE while the pointer carries files anywhere over the
   dialog, not only over the box -- the whole dialog is the target, so lighting
   up only the box would understate where a drop will land. */
.mp-back.is-drag .mp-drop{border-color:var(--accent,#15a85a);border-width:2px;padding:13.5px 11.5px;
        color:var(--accent-ink,#0b6e3a);background:var(--accent-soft,#e7f7ee)}
.mp-back.is-drag .mp-box{box-shadow:0 0 0 3px var(--accent,#15a85a),var(--sh-l,0 24px 60px -22px rgba(16,24,40,.30))}
@media (prefers-reduced-motion: reduce){.mp-up-track > i{transition:none}
  .mp-up.is-server .mp-up-track > i{animation:none}}
@media (max-width:560px){
  .mp-back{padding:0}
  .mp-box{max-height:100vh;height:100vh;border-radius:0}
  .mp-grid{grid-template-columns:repeat(auto-fill,minmax(112px,1fr))}
}
</style>

<script>
(function(){
  'use strict';

  var PER_LOAD = 24;

  var el = null;          // the dialog, built on first open and kept
  var opts = null;        // the open() call in flight
  var chosen = [];        // urls, in the order they were ticked
  var items = [];         // what the grid is showing
  var page = 1, pages = 1, query = '', busy = false, uploads = [];

  /* ── THE WARM RESULT (Lane SP) ─────────────────────────────────────────────

     The owner: "i click on to choose media, the media popup has a little delay
     while showing, please it should load very immidiate."

     Two things were making the wait, and this is the second of them. The first
     was that the dialog opened on a sentence rather than on the grid -- see
     .mp-skel above. The second is that EVERY open refetched page 1 of the
     library from scratch, including the open two seconds after the last one,
     while choosing a main image and then a gallery image on the same screen.

     So the FIRST PAGE OF THE UNFILTERED LIBRARY is kept, and a reopen paints it
     immediately and revalidates behind it. Nothing else is cached: a search, a
     filter and page 2 all go to the server as they always did.

     ▲ IT IS REVALIDATED, NOT TRUSTED. The tiles are on screen in one frame and
       the request still goes out; when it answers, the grid is repainted with
       whatever came back. An upload made in another tab is at most one open
       stale and never sticks. TTL is short for the same reason.

     ▲ AND IT IS DROPPED THE MOMENT THIS DIALOG CHANGES THE LIBRARY. takeFiles()
       adds an upload to the library, so warm.at is cleared there -- a cache
       that outlived the upload the operator just made would show them a grid
       without it, which is worse than the delay this removes. */
  var warm = { items: [], pages: 1, at: 0 };

  var WARM_TTL = 45000;

  function warmIsUsable(){
    return warm.at > 0 && (Date.now() - warm.at) < WARM_TTL && warm.items.length > 0;
  }

  /* The same filters the Media Library screen offers, because the owner asked
     for exactly that and because the endpoint already takes every one of them —
     q, attached, attached_q, from, to. No backend change is needed to support
     any of this. */
  var filt = { attached: '', owner: '', from: '', to: '' };

  /* Columns. Auto is the default and means "as many as fit", which is what the
     grid did before the chooser existed. A number overrides it, and is
     remembered per browser — it is a way of looking at the library, not a
     property of anything in it. Every access is wrapped: localStorage throws in
     a private window, and a throw here would take the dialog down. */
  var COLS = ['auto', 4, 6, 8, 10];

  var cols = (function(){
    try {
      var v = localStorage.getItem('kbb.mp.cols');
      return (v && COLS.indexOf(v === 'auto' ? 'auto' : parseInt(v, 10)) !== -1)
        ? (v === 'auto' ? 'auto' : parseInt(v, 10))
        : 'auto';
    } catch (e) { return 'auto'; }
  })();

  function setCols(v){
    cols = v;
    try { localStorage.setItem('kbb.mp.cols', String(v)); } catch (e) {}
    applyCols();
    el.querySelectorAll('.mp-cols button').forEach(function(b){
      b.classList.toggle('on', b.dataset.mpCols === String(v));
      b.setAttribute('aria-pressed', b.dataset.mpCols === String(v) ? 'true' : 'false');
    });
  }

  /* Set on the grid rather than re-rendering it: the operator changing the
     column count has usually already ticked something, and a repaint would
     drop the selection and their scroll position with it. */
  function applyCols(){
    var g = el.querySelector('.mp-grid');
    if (!g) return;
    if (cols === 'auto') g.style.removeProperty('--mp-cols');
    else g.style.setProperty('--mp-cols', String(cols));
  }

  function filtered(){
    return !!(query || filt.attached || filt.owner || filt.from || filt.to);
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* The console's own base path, derived the same way the screens derive it so
     a sub-folder install (KBB_BASE_PATH) works without configuration. */
  function apiBase(){
    var p = window.location.pathname.replace(/\/+$/, '');
    return p.replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  async function api(path, o){
    o = o || {};
    o.headers = o.headers || {};
    o.headers['Accept'] = 'application/json';
    o.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
    o.credentials = 'same-origin';

    var r = await fetch(apiBase() + path, o);
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }
    if (!r.ok) { var err = new Error('media ' + r.status); err.body = body; throw err; }
    return body;
  }

  function bytes(n){
    if (n == null) return '';
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  /* ─────────────────────────── the real ceiling ────────────────────────────
     Read out of the JSON island this partial renders above, which is
     App\Support\ServerUploadLimits->describe(MediaUploadController's cap) plus
     the two ini strings. Wrapped, because a missing or malformed island must
     leave a working picker rather than a dead one -- every reader below falls
     back to a value that behaves exactly as this dialog behaved before the
     island existed, which is "send it and let the server decide".

     WHY THE NUMBERS ARE NOT INTERCHANGEABLE, and why there are four readers:
       capBytes()  the exact byte ceiling, for the pre-flight refusal. effective_mb
                   is FLOORED, so refusing against effective_mb * 1048576 would
                   refuse a file this server would have taken.
       capWords()  the ceiling as an operator says it -- below a megabyte that
                   has to read "512 KB" and not "0 MB".
       cappedBy()  which of the three ceilings is doing the capping, bounded to
                   the two ini names the island may carry and '' for anything
                   else, because the sentence below switches on it. Rule 5.
       serverIni() one of those two ini values as the server spells it, for an
                   operator who is about to go and edit the line. */
  var LIMITS = (function(){
    try {
      var tag = document.getElementById('mp-limits');
      if (!tag) return null;
      var v = JSON.parse(tag.textContent || 'null');
      return (v && typeof v === 'object') ? v : null;
    } catch (e) { return null; }
  })();

  function capBytes(){
    var n = LIMITS && LIMITS.effective_bytes;
    return (typeof n === 'number' && n > 0) ? n : 0;   // 0 means "no pre-flight"
  }

  function capWords(){
    var w = LIMITS && LIMITS.effective_label;
    return (typeof w === 'string' && w) ? w : '';
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

  /* The sentence under the drop zone. The point of it is that the number is
     THIS server's, and that when the server is the thing capping it the
     operator is told which line to raise -- they cannot act on "2 MB" alone. */
  function capSentence(){
    var words = capWords();
    if (!words) return 'JPG, PNG, WebP, GIF or SVG.';

    var by = cappedBy();

    if (!by) return 'JPG, PNG, WebP, GIF or SVG, up to ' + words + ' each.';

    return 'JPG, PNG, WebP, GIF or SVG, up to ' + words + ' each — this server’s own '
      + by + ' (' + serverIni(by) + '), not a limit of the shop’s.';
  }

  function build(){
    if (el) return el;

    el = document.createElement('div');
    el.className = 'mp-back';
    el.hidden = true;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', 'Choose an image');

    el.innerHTML =
      '<div class="mp-box">'
      + '<div class="mp-head"><div style="min-width:0">'
      +   '<p class="mp-title" id="mp-title">Choose an image</p>'
      +   '<p class="mp-sub" id="mp-sub"></p>'
      + '</div><button type="button" class="mp-btn" id="mp-x" aria-label="Close">Close</button></div>'
      + '<div class="mp-tools">'
      +   '<div class="mp-row">'
      +     '<input class="mp-q" id="mp-q" type="search" autocomplete="off" placeholder="Search by name or description…">'
      +     '<span class="mp-cols" role="group" aria-label="Columns">'
      +       COLS.map(function(c){
              return '<button type="button" data-mp-cols="' + c + '" aria-pressed="false"'
                + ' title="' + (c === 'auto' ? 'As many as fit' : c + ' columns') + '">'
                + (c === 'auto' ? 'Auto' : c) + '</button>';
            }).join('')
      +     '</span>'
      +     '<button type="button" class="mp-btn mp-primary" id="mp-upload">Upload new</button>'
      +     '<input type="file" id="mp-file" accept="image/*" multiple hidden>'
      +   '</div>'
      +   '<div class="mp-filters">'
      +     '<label class="mp-fld"><span class="mp-lab">Used by</span>'
      +       '<select class="mp-in" id="mp-attached">'
      +         '<option value="">Anywhere — no filter</option>'
      +         '<option value="product">A product</option>'
      +         '<option value="brand">A brand</option>'
      +         '<option value="category">A category</option>'
      +         '<option value="site">Site-wide images</option>'
      +         '<option value="unused">Not used yet</option>'
      +       '</select></label>'
      +     '<label class="mp-fld"><span class="mp-lab">Product, brand or category</span>'
      +       '<input class="mp-in" id="mp-owner" type="search" autocomplete="off" placeholder="e.g. COSRX, or a product name"></label>'
      +     '<label class="mp-fld"><span class="mp-lab">Uploaded from</span>'
      +       '<input class="mp-in" id="mp-from" type="date"></label>'
      +     '<label class="mp-fld"><span class="mp-lab">Uploaded to</span>'
      +       '<input class="mp-in" id="mp-to" type="date"></label>'
      +     '<label class="mp-fld"><span class="mp-lab">&nbsp;</span>'
      +       '<button type="button" class="mp-btn" id="mp-clear" disabled>Clear filters</button></label>'
      +   '</div>'
      + '</div>'
      + '<div class="mp-body" id="mp-body"></div>'
      + '<div class="mp-foot">'
      +   '<span class="mp-count" id="mp-count"></span>'
      +   '<span style="display:flex;gap:9px">'
      +     '<button type="button" class="mp-btn" id="mp-more" hidden>Load more</button>'
      +     '<button type="button" class="mp-btn" id="mp-cancel">Cancel</button>'
      +     '<button type="button" class="mp-btn mp-primary" id="mp-ok" disabled>Use image</button>'
      +   '</span>'
      + '</div></div>';

    document.body.appendChild(el);

    var q = el.querySelector('#mp-q');
    var t = null;

    /* Debounced, because every keystroke is a request otherwise and the grid
       flickering under the operator's fingers reads as the search fighting
       them. 220ms is under the threshold where typing feels laggy. */
    q.addEventListener('input', function(){
      clearTimeout(t);
      t = setTimeout(function(){ query = q.value; page = 1; load(false); }, 220);
    });

    /* Every filter reloads from page one. Staying on page four of the previous
       result set and asking the server for page four of a narrower one is how
       a filter appears to return nothing. */
    function refilter(){ page = 1; syncFilters(); load(false); }

    var owner = el.querySelector('#mp-owner');
    var ot = null;

    el.querySelector('#mp-attached').addEventListener('change', function(e){
      filt.attached = e.target.value;
      // "Not used yet" and an owner name contradict each other: an image
      // nothing uses has no owner whose name could match.
      if (filt.attached === 'unused') { filt.owner = ''; owner.value = ''; }
      refilter();
    });

    owner.addEventListener('input', function(){
      clearTimeout(ot);
      ot = setTimeout(function(){ filt.owner = owner.value; refilter(); }, 220);
    });

    el.querySelector('#mp-from').addEventListener('change', function(e){ filt.from = e.target.value; refilter(); });
    el.querySelector('#mp-to').addEventListener('change', function(e){ filt.to = e.target.value; refilter(); });

    el.querySelector('#mp-clear').addEventListener('click', function(){
      query = '';
      filt = { attached: '', owner: '', from: '', to: '' };
      el.querySelector('#mp-q').value = '';
      owner.value = '';
      el.querySelector('#mp-attached').value = '';
      el.querySelector('#mp-from').value = '';
      el.querySelector('#mp-to').value = '';
      refilter();
    });

    el.querySelectorAll('.mp-cols button').forEach(function(b){
      b.addEventListener('click', function(){
        setCols(b.dataset.mpCols === 'auto' ? 'auto' : parseInt(b.dataset.mpCols, 10));
      });
    });

    el.querySelector('#mp-x').addEventListener('click', close);
    el.querySelector('#mp-cancel').addEventListener('click', close);
    el.querySelector('#mp-more').addEventListener('click', function(){ page++; load(true); });
    el.querySelector('#mp-ok').addEventListener('click', accept);

    el.querySelector('#mp-upload').addEventListener('click', function(){
      el.querySelector('#mp-file').click();
    });
    el.querySelector('#mp-file').addEventListener('change', function(e){
      takeFiles(e.target.files);
      e.target.value = '';           // the same file twice in a row still fires
    });

    // Clicking the backdrop closes; clicking the dialog must not.
    el.addEventListener('click', function(e){ if (e.target === el) close(); });

    /* ONE delegated listener for everything inside the dialog that paint()
       rebuilds. Binding per element would have to be redone on every repaint,
       and a repaint happens on every search keystroke -- which is how a console
       ends up leaking a listener per keypress. */
    el.querySelector('.mp-box').addEventListener('click', function(e){
      var hit = e.target.closest ? e.target.closest('[data-mp-url],[data-mp-upx],#mp-drop,#mp-upstop') : null;
      if (!hit) return;

      if (hit.id === 'mp-drop') { el.querySelector('#mp-file').click(); return; }
      if (hit.id === 'mp-upstop') { stopRest(); return; }

      if (hit.hasAttribute('data-mp-upx')) {
        stopOne(parseInt(hit.getAttribute('data-mp-upx'), 10));
        return;
      }

      toggle(hit.getAttribute('data-mp-url'));
    });

    /* THE WHOLE DIALOG IS THE DROP TARGET, bound once here rather than on the
       dashed box paint() redraws.

       `accept` is the same 'image/*' the #mp-file input above declares, because
       a drop never goes near that input and the browser applies an input's
       accept attribute to its own file dialog and to nothing else -- without
       this, dragging a .zip onto the picker would send it to an image endpoint.
       `multiple` likewise mirrors the input: the library takes as many as are
       dropped, whatever the caller asked to pick. */
    dropZone(el, {
      accept: 'image/*',
      multiple: true,
      onFiles: function(files){ takeFiles(files); }
    });

    return el;
  }

  function onKey(e){
    if (e.key === 'Escape') { e.preventDefault(); close(); }
  }

  function toggle(url){
    if (!url) return;

    var at = chosen.indexOf(url);

    if (opts && opts.multiple) {
      if (at === -1) chosen.push(url); else chosen.splice(at, 1);
    } else {
      chosen = (at === -1) ? [url] : [];
    }

    paintSelection();
  }

  /* Only the tick marks and the footer are repainted on a selection change.
     Re-rendering the grid would reset its scroll position, which after a
     "Load more" is several screens up from where the operator is looking. */
  function syncFilters(){
    var owner = el.querySelector('#mp-owner');
    var clear = el.querySelector('#mp-clear');

    if (owner) owner.disabled = filt.attached === 'unused';
    if (clear) clear.disabled = !filtered();
  }

  function paintSelection(){
    el.querySelectorAll('[data-mp-url]').forEach(function(t){
      t.classList.toggle('on', chosen.indexOf(t.getAttribute('data-mp-url')) !== -1);
    });

    var ok = el.querySelector('#mp-ok');
    ok.disabled = chosen.length === 0;
    ok.textContent = chosen.length > 1 ? ('Use ' + chosen.length + ' images') : 'Use image';

    el.querySelector('#mp-count').textContent = chosen.length
      ? (chosen.length + ' selected')
      : (items.length ? items.length + ' shown' : '');
  }

  function tileHTML(it){
    var name = it.original_name || it.filename || '';
    var dim = (it.width && it.height) ? (it.width + ' × ' + it.height) : '';

    return '<button type="button" class="mp-tile" data-mp-url="' + esc(it.url) + '">'
      /* (Lane IM2) `thumb` and not `url`: .mp-thumb is a square tile at least
         112px across, and this grid was pulling the full-resolution original
         for every one of the 24 on a page. `thumb` is the 400w copy when the
         file has been through Make phone-sized copies and is the original
         itself when it has not, so nothing changes on a library that has not.
         data-mp-url below still carries the ORIGINAL, which is what the picker
         hands back to whatever opened it. */
      + '<span class="mp-thumb"><img src="' + esc(it.thumb || it.url) + '" alt="" loading="lazy">'
      +   '<span class="mp-tick">✓</span></span>'
      + '<span class="mp-cap"><span class="mp-name">' + esc(name) + '</span>'
      +   '<span class="mp-dim">' + esc([dim, bytes(it.size)].filter(Boolean).join(' · ')) + '</span>'
      + '</span></button>';
  }

  /**
   * The always-visible drop target, and the sentence that says what this server
   * will really take.
   *
   * A BUTTON, not a div: it is the click path to the file dialog as well as the
   * drop path, so the affordance and the keyboard route are the same control.
   * Rendered on every paint because .mp-body is replaced wholesale; the drag
   * handlers are bound ONCE, on the dialog, so nothing here has to be re-armed.
   */
  function dropHTML(){
    return '<button type="button" class="mp-drop" id="mp-drop">'
      + '<b>Drop images here to upload</b>'
      + '<span>' + esc(capSentence()) + '</span>'
      + '</button>';
  }

  function paint(){
    var body = el.querySelector('#mp-body');

    var head = dropHTML() + uploadsHTML();

    if (busy && !items.length) {
      /* The SHAPE of the grid, not a sentence about it. One skeleton tile per
         column-ish; twelve is two full rows at the default width and one at the
         widest, which is enough to read as "a grid is coming" without being a
         wall of grey. (Lane SP) */
      var skeleton = '';

      for (var i = 0; i < 12; i++) skeleton += '<div class="mp-skel"><i></i><u></u></div>';

      body.innerHTML = head + '<div class="mp-grid" aria-busy="true" aria-label="Loading your images">'
        + skeleton + '</div>';
      applyCols();
      return;
    }

    if (!items.length) {
      body.innerHTML = head + '<p class="mp-note">'
        + (query ? 'No image matches “' + esc(query) + '”.' : 'No images yet. Drop one above, or use <b>Upload new</b>.')
        + '</p>';
      paintSelection();
      return;
    }

    body.innerHTML = head + '<div class="mp-grid">' + items.map(tileHTML).join('') + '</div>';
    applyCols();
    el.querySelector('#mp-more').hidden = page >= pages;
    paintSelection();
  }

  async function load(append){
    busy = true;

    if (!append) {
      /* PAINT THE LAST RESULT FIRST when this is the plain, unfiltered first
         page and it is fresh -- the grid is on screen in the same frame the
         dialog opens in, and the request below repaints it when it answers.
         Anything filtered, searched or paged clears the grid exactly as it did
         before. (Lane SP) */
      items = (page === 1 && query === '' && !filt.attached && !filt.owner && !filt.from && !filt.to
        && warmIsUsable())
        ? warm.items.slice()
        : [];

      if (items.length) { pages = warm.pages; }
    }

    paint();

    try {
      var qs = ['page=' + page, 'q=' + encodeURIComponent(query)];

      if (filt.attached) qs.push('attached=' + encodeURIComponent(filt.attached));

      if (filt.owner && filt.attached !== 'unused') {
        qs.push('attached_q=' + encodeURIComponent(filt.owner));
        // The endpoint keys the owner search off `attached`, so searching with
        // no kind chosen has to say "any" explicitly rather than send nothing.
        if (!filt.attached) qs.push('attached=any');
      }

      if (filt.from) qs.push('from=' + encodeURIComponent(filt.from));
      if (filt.to) qs.push('to=' + encodeURIComponent(filt.to));

      var body = await api('/media?' + qs.join('&'));
      var fresh = (body && body.items) || [];
      items = append ? items.concat(fresh) : fresh;
      pages = (body && body.pages) || 1;

      // Keep only the plain first page. A search result or a filtered page is
      // not what the next open wants to see. (Lane SP)
      if (!append && page === 1 && query === ''
          && !filt.attached && !filt.owner && !filt.from && !filt.to) {
        warm = { items: fresh.slice(), pages: pages, at: Date.now() };
      }
    } catch (e) {
      items = append ? items : [];
      el.querySelector('#mp-body').innerHTML =
        '<p class="mp-note">Your images could not be loaded. Close this and try again.</p>';
      busy = false;
      return;
    }

    busy = false;
    paint();
  }

  /* ── ONE UPLOADER, TWO IMPLEMENTATIONS, ONE CONTRACT ──────────────────────

     window.kbbUpload is the console's shared uploader (upload-kit.blade.php).
     It is preferred whenever it is on the page, and the local XHR below is what
     this dialog falls back to when it is not -- exactly the way every call site
     in the console already guards window.kbbPickMedia. Both honour the same
     option names and the same four callbacks, so takeFiles() below is written
     once against one shape and cannot behave differently depending on which one
     answered.

     WHY XMLHttpRequest AND NOT fetch, in the fallback and in the kit alike:
     fetch cannot report UPLOAD progress. Its request body is consumed opaquely,
     so the most a fetch-based uploader can show is a spinner -- which for
     somebody pushing six photographs over a domestic uplink is
     indistinguishable from a hung page. That is the whole reason this file had
     a bar before it had a drop zone.

     It is NOT a second upload path. Same endpoint, same folder field, same CSRF
     header, same server rules; a different transport. A genuinely second path
     is the trap this repo has avoided twice, because the one that drifts is
     always the one carrying the content-type and size rules. */
  function send(o){
    if (typeof window.kbbUpload === 'function') return window.kbbUpload(o);
    return localUpload(o);
  }

  /**
   * The fallback uploader. Same options and callbacks as window.kbbUpload:
   * { url, file, field, extra, max, onProgress, onStage, onDone, onFail }
   * and it returns { cancel() }.
   */
  function localUpload(o){
    var field = o.field || 'file';
    var fired = false;

    function stage(s){ if (typeof o.onStage === 'function') o.onStage(s); }
    function fail(f){
      if (fired) return;
      fired = true;
      stage('failed');
      if (typeof o.onFail === 'function') o.onFail(f);
    }

    /* Refused BEFORE a byte is sent, when a ceiling was given. Sending a file
       this server cannot accept and then reporting the refusal at 100% is the
       exact defect this lane was opened for: the bar filled, the server had
       thrown the body away on the way in, and the screen blamed the file. */
    if (typeof o.max === 'number' && o.max > 0 && o.file && o.file.size > o.max) {
      fail({ status: 0, message: 'Larger than this server will accept.', retryable: false });
      return { cancel: function(){} };
    }

    var fd = new FormData();
    fd.append(field, o.file);

    var extra = o.extra || {};
    Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', o.url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    /* There is no csrf-token meta tag in this console; the cookie is the only
       source. Named X-XSRF-TOKEN because that is the header Laravel reads the
       encrypted cookie value from. */
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
            // Capped at 99 while sending. 100% has to mean "the server has
            // answered", not "the last byte left this machine" -- the answer
            // can still be a 422, and a bar that reads 100% beside a refusal is
            // how a screen loses the operator's trust.
            pct: Math.min(99, Math.round(e.loaded / e.total * 100))
          });
        }
      };
      /* Every byte is gone and nothing has come back. This is its own stage
         rather than a bar parked at 99%: on a 4000x4000 JPEG the server is
         spending real time here writing the file, recording the row and making
         the phone-sized copies. */
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
        // 5xx is worth pressing again; a 413 or a 422 will answer the same way
        // for ever, and telling somebody to retry a file the server will never
        // take is how the same photograph gets uploaded four times.
        retryable: xhr.status >= 500
      });
    };

    xhr.onerror = function(){
      fail({ status: 0, message: 'Could not reach the server.', retryable: true });
    };

    xhr.onabort = function(){
      if (fired) return;
      fired = true;
      stage('cancelled');
    };

    xhr.send(fd);

    return { cancel: function(){ try { xhr.abort(); } catch (e) {} } };
  }

  /* ── ONE DROP ZONE HELPER, THE SAME TWO-IMPLEMENTATION SHAPE ──────────────
     window.kbbDropZone(el, {accept, multiple, onFiles}) when the kit is on the
     page, and the wiring below when it is not. Returns a teardown function in
     both cases; this dialog never calls it, because the dialog is built once and
     lives for the life of the document. */
  function dropZone(node, o){
    /*
     * ── THE LOCAL ONE, ALWAYS, AND THIS IS A BUG FIX ───────────────────────
     *
     * This used to prefer window.kbbDropZone when the upload kit was on the
     * page. The kit arriving broke this dialog in TWO ways at once, and the
     * owner reported the first: "the manual option is opening the media
     * downside, not in popup as normal".
     *
     * 1. LAYOUT. kbbDropZone marks its target with `.kbbu-zone`, which carries
     *    `position:relative; display:grid` because the kit's zones are standing
     *    dashed boxes. The node it is handed HERE is `.mp-back` — the
     *    full-screen backdrop, whose whole existence is `position:fixed;
     *    inset:0; display:flex` centring the dialog. The class won on cascade
     *    order, `fixed` became `relative`, and the dialog dropped out of the
     *    viewport and into the page flow at the bottom of the document.
     *    Measured in the browser rather than guessed: computed position read
     *    `relative` and display read `grid`, with `.kbbu-zone` the only other
     *    matching rule.
     *
     * 2. THE DRAG STATE. The kit writes `.is-over` and `.is-bad`. Every rule in
     *    this file is written against `.mp-back.is-drag` — this dialog's own
     *    state class, chosen before the kit existed. So while the kit was
     *    driving, nothing in here lit up on a drag at all.
     *
     * localDropZone is not a fallback and is not lesser: it counts
     * dragenter/dragleave depth because this dialog is full of tiles to cross,
     * which is the problem the kit solves too — it simply writes this file's
     * class names instead of the kit's.
     *
     * A drop target that is also a layout container must not be handed to a
     * helper that styles its target. Left as a named function rather than
     * inlined so the next reader sees the choice.
     */
    return localDropZone(node, o);
  }

  /** True when the pointer is carrying files from outside the page. */
  function carriesFiles(e){
    var types = e.dataTransfer && e.dataTransfer.types;
    if (!types) return false;
    return Array.prototype.indexOf.call(types, 'Files') !== -1;
  }

  function localDropZone(node, o){
    /* dragenter and dragleave fire once per element the pointer crosses, and the
       dialog is full of them -- tiles, captions, images. Counting them is the
       only way to know when the pointer has really left: a plain dragleave
       handler switches the highlight off the moment the pointer moves from the
       dialog onto a tile inside it, which reads as the drop target flickering. */
    var depth = 0;

    function lit(on){
      depth = on ? depth : 0;
      node.classList.toggle('is-drag', on);
    }

    function enter(e){
      if (!carriesFiles(e)) return;
      e.preventDefault();
      depth++;
      node.classList.add('is-drag');
    }

    function over(e){
      if (!carriesFiles(e)) return;
      /* preventDefault is what makes this a valid drop target AT ALL, and it is
         also what stops the browser navigating away to the dropped file -- which
         is what the console does today anywhere outside a zone. */
      e.preventDefault();
      try { e.dataTransfer.dropEffect = 'copy'; } catch (x) {}
    }

    function leave(e){
      if (!carriesFiles(e)) return;
      depth = Math.max(0, depth - 1);
      if (depth === 0) lit(false);
    }

    function drop(e){
      if (!carriesFiles(e)) return;
      e.preventDefault();
      lit(false);

      var files = (e.dataTransfer && e.dataTransfer.files) || null;
      if (!files || !files.length) return;

      var list = Array.prototype.slice.call(files);

      /* The accept string is honoured here as well as on the input, because a
         drop bypasses the input entirely -- the browser applies `accept` to its
         own file dialog and to nothing else. A folder dropped on a zone arrives
         as a zero-length, type-less entry, which is why a directory is dropped
         rather than uploaded as an empty file. */
      list = list.filter(function(f){ return matchesAccept(f, o && o.accept); });

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
      lit(false);
    };
  }

  /**
   * Does this dropped file match an `accept` string?
   *
   * Only the two forms this console actually uses are understood -- a type/*
   * wildcard and a .ext suffix -- and ANYTHING NOT UNDERSTOOD IS ACCEPTED. A
   * client-side filter that guesses wrong silently discards the operator's file
   * and shows nothing; the server is the authority on what an image is, and it
   * reads the bytes rather than the name. This exists to stop a dropped folder
   * and an obviously wrong file, not to enforce a policy.
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

  /* ─────────────────────────── the upload queue ────────────────────────────

     One row per file, each with its own bar and its own ✕. What was rejected,
     and why:

       ONE AGGREGATE BAR over the whole batch. Rejected. Uploads here are
       sequential, so the aggregate is one live number plus N zeros and it
       advances in steps; worse, a single file that fails or stalls is invisible
       inside it, and which file failed is the one thing the operator has to
       know.

       PARALLEL UPLOADS. Rejected. Six photographs over one uplink share the
       same bandwidth: six bars crawl together and none finishes until nearly
       all of them do. It would also multiply this server's post_max_size
       problem by six at once.

     Sequential, one bar each, and the batch line above them counts. A failure
     does not abandon the rest of the run -- an earlier version of this loop
     stopped at the first bad file and silently dropped everything after it. */

  function pctOf(u){
    if (u.state === 'done' || u.state === 'server') return 100;
    return u.pct || 0;
  }

  function stateLabel(u){
    if (u.state === 'failed') return u.error || 'Failed';
    if (u.state === 'cancelled') return 'Stopped';
    if (u.state === 'server') return 'Sent — saving…';
    if (u.state === 'waiting') return 'Waiting…';
    return pctOf(u) + '%';
  }

  function rowClass(u){
    return 'mp-up'
      + (u.state === 'failed' ? ' is-bad' : '')
      + (u.state === 'cancelled' ? ' is-gone' : '')
      + (u.state === 'server' ? ' is-server' : '');
  }

  /** Can this row still be stopped? Only one that has not finished one way or another. */
  function stoppable(u){
    return u.state === 'waiting' || u.state === 'sending' || u.state === 'server';
  }

  function uploadsHTML(){
    if (!uploads.length) return '';

    var live = uploads.filter(stoppable).length;
    var done = uploads.filter(function(u){ return u.state === 'done'; }).length;
    var bad = uploads.filter(function(u){ return u.state === 'failed' || u.state === 'cancelled'; }).length;

    var rows = uploads.map(function(u, i){
      return '<div class="' + rowClass(u) + '" data-mp-up="' + i + '">'
        + '<div class="mp-up-top"><span class="mp-up-name">' + esc(u.name) + '</span>'
        +   '<span class="mp-up-pct">' + esc(stateLabel(u)) + '</span>'
        +   '<button type="button" class="mp-up-x" data-mp-upx="' + i + '"'
        +     (stoppable(u) ? '' : ' hidden') + ' aria-label="Stop uploading ' + esc(u.name) + '">Stop</button>'
        + '</div>'
        /* aria-valuenow beside the width, so the bar is not a purely visual
           fact. A screen reader gets the same number the sighted operator has. */
        + '<div class="mp-up-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
        +   ' aria-valuenow="' + pctOf(u) + '" aria-label="' + esc(u.name) + '"><i style="width:'
        +   pctOf(u) + '%"></i></div></div>';
    }).join('');

    return '<div class="mp-ups">'
      + '<div class="mp-up-head"><span>' + esc(batchLine(done, bad)) + '</span>'
      +   (live > 1 ? '<button type="button" class="mp-up-stop" id="mp-upstop">Stop the rest</button>' : '')
      + '</div>' + rows + '</div>';
  }

  function batchLine(done, bad){
    return 'Uploading ' + uploads.length + (uploads.length === 1 ? ' image' : ' images')
      + ' — ' + done + ' done' + (bad ? ', ' + bad + ' not added' : '');
  }

  /* Patched in place, never through paint(). Repainting the dialog on every
     progress event would rebuild the grid dozens of times a second and take the
     operator's ticks and their scroll position with it. This touches the bar's
     width, its two labels, its class and its ✕ -- nothing else. */
  function paintUploads(){
    uploads.forEach(function(u, i){
      var row = el.querySelector('[data-mp-up="' + i + '"]');
      if (!row) return;

      var pct = pctOf(u);
      var bar = row.querySelector('.mp-up-track > i');
      var track = row.querySelector('.mp-up-track');
      var lab = row.querySelector('.mp-up-pct');
      var x = row.querySelector('.mp-up-x');

      if (bar) bar.style.width = pct + '%';
      if (track) track.setAttribute('aria-valuenow', String(pct));
      if (lab) lab.textContent = stateLabel(u);
      if (x) x.hidden = !stoppable(u);
      row.className = rowClass(u);
    });

    var head = el.querySelector('.mp-up-head > span');
    if (head) {
      head.textContent = batchLine(
        uploads.filter(function(u){ return u.state === 'done'; }).length,
        uploads.filter(function(u){ return u.state === 'failed' || u.state === 'cancelled'; }).length
      );
    }

    var stop = el.querySelector('#mp-upstop');
    if (stop) stop.hidden = uploads.filter(stoppable).length < 2;
  }

  /* ── STOP HAS TO SETTLE THE QUEUE, NOT JUST ABORT THE REQUEST ─────────────
     FOUND IN CHROMIUM, NOT IN A TEST, AND IT HUNG THE SCREEN. Cancelling is not
     a failure, so the contract reports it as onStage('cancelled') and NOT as
     onFail -- and the first version of this queue resolved its promise only from
     onDone and onFail. So pressing Stop aborted the request, painted the row
     "Stopped", and then awaited a promise that nothing would ever settle: the
     loop never reached the next file, `busy` stayed true, and the screen sat
     greyed out until it was reloaded. Measured: three rows all reading "Stopped"
     and no render after them.
     Every row therefore carries its own settle(), called from BOTH ends -- the
     'cancelled' stage, and stopOne() itself. Resolving a promise twice is a
     no-op, so the belt and the braces cannot disagree, and a transport that
     forgets to report the abort at all cannot wedge the queue. */

  /** Stop one row: the request if it is in flight, the place in the queue if not. */
  function stopOne(i){
    var u = uploads[i];
    if (!u || !stoppable(u)) return;

    u.state = 'cancelled';

    if (u.handle && typeof u.handle.cancel === 'function') {
      try { u.handle.cancel(); } catch (e) {}
    }

    paintUploads();

    // The braces. See the note above.
    if (typeof u.settle === 'function') u.settle();
  }

  function stopRest(){
    /* Every outstanding row is marked, and the loop below reads the ROW rather
       than a run-wide flag. One less piece of state that can be left true. */
    uploads.forEach(function(u, i){ if (stoppable(u)) stopOne(i); });
  }

  /**
   * One file, sent, as a promise of its url or null.
   *
   * The row is the only state this touches; the caller decides what a url means.
   */
  function sendOne(row, file){
    return new Promise(function(resolve){
      row.state = 'sending';
      row.pct = 0;
      /* The one place this row's promise can be settled from. Assigned before
         the request starts, because Stop can arrive on the very next tick. */
      row.settle = function(){ resolve(null); };
      paintUploads();

      row.handle = send({
        url: apiBase() + '/media/upload',
        file: file,
        field: 'file',
        extra: { folder: (opts && opts.folder) || 'products' },
        /* Passed as well as pre-flighted in takeFiles(), and the two cannot
           both fire: takeFiles refuses first, so this is the belt on the kit's
           braces for a caller that ever reaches here with an oversized file. */
        max: capBytes() || undefined,
        onProgress: function(p){
          if (row.state !== 'sending') return;   // a cancelled row stops moving
          row.pct = Math.max(0, Math.min(100, Math.round((p && p.pct) || 0)));
          paintUploads();
        },
        onStage: function(st){
          // The belt. Cancelling is reported here and never through onFail.
          if (st === 'cancelled') { row.state = 'cancelled'; paintUploads(); resolve(null); return; }
          if (row.state === 'cancelled') return;
          if (st === 'server') { row.state = 'server'; paintUploads(); }
        },
        onDone: function(body){
          if (row.state === 'cancelled') { resolve(null); return; }
          var url = body && body.url;
          if (url) { row.state = 'done'; row.pct = 100; }
          else { row.state = 'failed'; row.error = 'No image came back'; }
          paintUploads();
          resolve(url || null);
        },
        onFail: function(f){
          if (row.state === 'cancelled') { paintUploads(); resolve(null); return; }
          row.state = 'failed';
          row.error = failWords(f, row);
          paintUploads();
          resolve(null);
        }
      });
    });
  }

  /**
   * What to put on a failed row, in the operator's units.
   *
   * 413 IS ITS OWN CASE AND HAS TO BE. Laravel 11's global ValidatePostSize
   * throws before the router when the whole body is over post_max_size, and its
   * response carries `message` and no `error` key. MEASURED in Chromium against
   * a 9 MB body on this box, that message is exactly:
   *
   *     The POST data is too large.
   *
   * which names no size, no ceiling and no directive, and is the same sentence
   * whether the file was 9 MB or 900. So the message is REPLACED rather than
   * printed: the operator is told what their file was, what the most this server
   * takes is, and which ini line is doing it.
   */
  function failWords(f, row){
    var status = (f && f.status) || 0;
    var msg = String((f && f.message) || '');

    if (status === 413) {
      var by = cappedBy() || 'post_max_size';
      return 'Too big for this server (' + by + ' = ' + serverIni(by) + '). '
        + bytes(row.size) + (capWords() ? ' — the most it takes is ' + capWords() : '');
    }

    if (msg) return msg.slice(0, 80);
    return status ? ('Upload failed (' + status + ')') : 'Upload failed';
  }

  async function takeFiles(files){
    var list = Array.prototype.slice.call(files || []);
    if (!list.length) return;

    /* THE WARM RESULT IS DROPPED BEFORE THE FIRST BYTE GOES UP. This dialog is
       about to change the library, and a cache that outlived the upload the
       operator just made would show them, on the next open, a grid without the
       picture they had just put there. (Lane SP) */
    warm = { items: [], pages: 1, at: 0 };

    uploads = list.map(function(f){
      return { name: f.name || 'image', size: f.size || 0, pct: 0, state: 'waiting' };
    });

    paint();

    var fresh = [];
    var ceiling = capBytes();

    for (var i = 0; i < list.length; i++) {
      var row = uploads[i];

      // Already cancelled means "Stop the rest", or Close, reached this row
      // while it was still waiting its turn.
      if (row.state === 'cancelled') { paintUploads(); continue; }

      /* ── THE PRE-FLIGHT, AND WHY IT IS HERE AND NOT ONLY IN THE UPLOADER ──
         Refused before a byte leaves the machine, with the number and the
         reason in the same sentence. The uploader's own `max` would refuse it
         too, but it cannot name this server's ini line -- and "which line do I
         raise" is the only actionable part of the message. A refused file does
         NOT stop the batch: the other five photographs still go up. */
      if (ceiling && row.size > ceiling) {
        row.state = 'failed';
        row.error = bytes(row.size) + ' — over this server’s ' + capWords() + ' limit'
          + (cappedBy() ? ' (' + cappedBy() + ' = ' + serverIni(cappedBy()) + ')' : '');
        paintUploads();
        continue;
      }

      var url = await sendOne(row, list[i]);
      if (url) fresh.push(url);
    }

    /* Successes disappear and failures stay on screen, which is the whole point
       of keeping the rows after the run: what is left IS the list of what did
       not make it, with the reason on each line. */
    uploads = uploads.filter(function(u){ return u.state !== 'done'; });

    /* A new upload lands IN THE LIBRARY FIRST and is then pre-ticked, which is
       the flow the owner asked for: the file joins the library rather than
       being attached straight to whatever opened the picker. Page 1 without a
       search, because the newest rows are what the grid orders first and a
       search still in the box would hide the thing just uploaded. */
    // Nothing to repaint into if the operator closed the dialog mid-run.
    if (el.hidden) return;

    if (fresh.length) {
      query = '';
      var q = el.querySelector('#mp-q');
      if (q) q.value = '';
      page = 1;
      await load(false);

      chosen = (opts && opts.multiple) ? fresh.slice() : fresh.slice(-1);
      paintSelection();
    } else {
      paint();
    }
  }

  function accept(){
    if (!chosen.length) return;

    var picked = chosen.slice();
    var cb = opts && opts.onPick;

    close();

    if (typeof cb === 'function') cb(picked);
  }

  var lastFocus = null;

  function close(){
    if (!el) return;
    /* An upload in flight is abandoned when the dialog is closed. "Close" has to
       mean it: leaving a request running behind a dismissed dialog puts a file
       in the library that the operator cancelled and cannot see arrive. Files
       already finished stay in the library, because they are finished. */
    stopRest();
    el.hidden = true;
    document.removeEventListener('keydown', onKey, true);
    opts = null;
    chosen = [];
    uploads = [];
    // Focus goes back where it came from, or the operator is left at the top of
    // the document with no idea which control they just used.
    try { if (lastFocus && lastFocus.focus) lastFocus.focus(); } catch (e) {}
    lastFocus = null;
  }

  /**
   * window.kbbPickMedia({ multiple, title, note, folder, onPick })
   *
   * onPick receives an array of urls, always — a single-select call gets an
   * array of one rather than a bare string, so a caller cannot be written
   * against the wrong shape and work by accident.
   */
  window.kbbPickMedia = function(options){
    options = options || {};
    build();

    lastFocus = document.activeElement;
    opts = options;
    chosen = [];
    uploads = [];
    query = '';
    filt = { attached: '', owner: '', from: '', to: '' };
    page = 1;
    items = [];

    el.querySelector('#mp-title').textContent = options.title || 'Choose an image';
    el.querySelector('#mp-sub').textContent = options.note
      || (options.multiple
            ? 'Pick as many as you like, or upload new ones.'
            : 'Pick one, or upload a new one.');

    var q = el.querySelector('#mp-q');
    q.value = '';

    ['#mp-owner', '#mp-from', '#mp-to'].forEach(function(sel){
      var f = el.querySelector(sel);
      if (f) f.value = '';
    });
    el.querySelector('#mp-attached').value = '';

    el.hidden = false;
    document.addEventListener('keydown', onKey, true);

    setCols(cols);
    syncFilters();

    paintSelection();
    load(false);

    try { q.focus(); } catch (e) {}
  };
})();
</script>
@endverbatim
