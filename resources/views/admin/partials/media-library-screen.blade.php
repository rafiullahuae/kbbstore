{{--
    Content - Media Library (Lane AX).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go, toast() and the design tokens
    this screen borrows. Its own file rather than more lines inside a
    13,000-line Blade: several lanes edit that file at once, and a screen that
    lives on its own can be reviewed, reverted and merged on its own. The shape
    is deliberately the same as admin/partials/category-tree-screen.blade.php;
    that is the precedent.

    WHAT THIS SCREEN IS FOR, and what was here before it.

    The console's Media Library entry pointed at `kbb-admin-media.html`, a
    standalone page this repository has never shipped. A previous lane replaced
    the resulting 404 with an honest "isn't installed yet" card, and that card
    is what the owner was looking at when they asked for this:

        "any images or media upload, it must be show on the Media Page as grid.
         and with proper search functinality via name, date, product or brand or
         by category to search the images."

    Meanwhile /admin-api/media/upload -- the ONE upload endpoint in this
    application, shared by the product gallery, brand logos, category images and
    the SEO share image -- had been writing files into public/uploads/ for
    months and recording nothing. The `media` table has existed since the
    original schema and App\Models\Media had ZERO call sites in the tree. So
    uploads happened constantly and there was no way to see one.

    That is fixed at the source rather than here: the upload endpoint now
    records each upload, and a backfill migration catalogues everything already
    on disk. No second upload path was added, and this screen does not upload --
    it lists, searches, inspects and deletes.

    THE SEARCH BY PRODUCT / BRAND / CATEGORY IS DERIVED, NOT JOINED, and the
    screen says so out loud rather than letting the owner assume otherwise.
    Nothing in the schema records which product an image belongs to: the owning
    row keeps a URL string (products.image, products.images, brands.logo,
    categories.image) and that is the entire association. App\Support\MediaUsage
    carries the full reasoning and the proposal for recording it properly.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.

    The whole body is wrapped in one so that the {{ }} inside JavaScript
    template literals is not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Media Library. Every rule is prefixed mlib- and appears nowhere else in the
   console, so this file can never restyle another screen by accident. The same
   goes for every id and every data- attribute below: a lane on this project
   found its rows firing another screen's handler because both used a bare
   `data-open`.

   THE LAYOUT RULE, and it is the one that has already cost this project a
   shipped bug. min-width:0 on every grid and flex child that can contain
   something wide. A grid or flex item's default min-width is `auto`, meaning
   "at least as wide as my content" -- so a card refuses to shrink below the
   width of a long filename inside it, the tile's own wrapping never gets the
   chance to apply, and the whole screen is stretched to that filename's natural
   width. A media library is ALL long filenames.

   And the reason it is invisible in testing: this admin sets
   body{overflow-x:hidden} and scrolls inside #content, so
   document.documentElement.scrollWidth reads a comforting zero no matter how
   over-wide the screen is. #content is the element that has to be measured.
--------------------------------------------------------------------------- */
.mlib-wrap{display:grid;gap:16px;min-width:0}
.mlib-wrap > *{min-width:0}

.mlib-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
           border-radius:var(--r,12px);padding:16px;min-width:0}

.mlib-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;min-width:0}
.mlib-head > *{min-width:0}
.mlib-title{font-weight:650;font-size:15px}
.mlib-sub{color:var(--ink-soft,#626c80);font-size:12.5px;overflow-wrap:anywhere}

.mlib-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:12px;min-width:0}
.mlib-stats > *{min-width:0}
.mlib-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
           border-radius:var(--r,12px);padding:12px 14px;min-width:0}
.mlib-stat b{display:block;font-size:20px;line-height:1.3;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.mlib-stat span{color:var(--ink-soft,#626c80);font-size:12px}

/* The filter bar. auto-fit rather than a fixed column count so it collapses to
   one field per row on a phone without a second breakpoint to keep in step. */
.mlib-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(190px,100%),1fr));
              gap:10px;min-width:0;align-items:end}
.mlib-filters > *{min-width:0}
.mlib-field{display:grid;gap:4px;min-width:0}
.mlib-field label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft,#626c80)}
.mlib-field input,.mlib-field select{width:100%;min-width:0;box-sizing:border-box;
    border:1px solid var(--border,#e6e9f2);border-radius:9px;padding:8px 10px;
    font:inherit;font-size:13px;background:var(--surface,#fff);color:var(--ink,#101729)}
.mlib-field input:focus,.mlib-field select:focus{outline:2px solid var(--accent,#15a85a);outline-offset:1px}

.mlib-btn{border:1px solid var(--border,#e6e9f2);background:var(--surface,#fff);
          border-radius:9px;padding:8px 12px;font:inherit;font-size:12.5px;cursor:pointer;
          color:var(--ink-2,#3c465c);white-space:nowrap}
.mlib-btn:hover{border-color:var(--ink,#101729)}
.mlib-btn:disabled{opacity:.5;cursor:default}

/* The secondary filters fold away on a phone.
   Measured, not guessed: at 390px the five fields stacked one per row filled
   the entire first screen, so the owner scrolled past a full page of empty
   inputs before reaching a single image — on the one screen whose whole job is
   showing images. The search box stays out in the open because it is the one
   people use; the rest are one tap away. Above 700px there is room for all of
   them at once, so the toggle disappears and the panel is simply always there —
   one code path, no second markup. */
.mlib-extra{display:none;gap:10px;min-width:0}
.mlib-extra.is-open{display:grid}
.mlib-extra > *{min-width:0}
@media (min-width:700px){
  /* display:contents dissolves the wrapper so its fields become items of the
     SAME grid as the search box, and all six align on one row as though the
     phone's fold had never been added. A second markup path for wide screens
     would be two layouts to keep in step; this is one. */
  .mlib-extra,.mlib-extra.is-open{display:contents}
  .mlib-toggle{display:none}
}
.mlib-btn.is-primary{background:var(--ink,#101729);color:#fff;border-color:var(--ink,#101729)}
.mlib-btn.is-danger{color:var(--danger,#d6455a);border-color:var(--danger,#d6455a)}
.mlib-btn.is-danger:hover{background:var(--danger,#d6455a);color:#fff}

/* ── the grid ──────────────────────────────────────────────────────────────
   minmax(min(150px,100%),1fr): the min() is what stops a 150px track from
   being wider than the container on a 390px phone with the console's own
   padding already taken out. Without it the grid forces a horizontal scroll
   that #content cannot hide. */
.mlib-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(150px,100%),1fr));
           gap:12px;min-width:0}
.mlib-grid > *{min-width:0}

.mlib-tile{border:1px solid var(--border,#e6e9f2);border-radius:11px;overflow:hidden;
           background:var(--surface,#fff);text-align:left;padding:0;font:inherit;color:inherit;
           cursor:pointer;display:grid;grid-template-rows:auto 1fr;min-width:0}
.mlib-tile:hover{border-color:var(--ink,#101729)}
.mlib-tile:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}

/* A fixed aspect box, so a portrait shot and a landscape one do not make a
   ragged grid, and so the tile has a height before the image has loaded. */
.mlib-thumb{position:relative;aspect-ratio:4 / 3;background:var(--surface-2,#f2f4fb);
            display:grid;place-items:center;overflow:hidden;min-width:0}
.mlib-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.mlib-thumb .mlib-noimg{font-size:11px;color:var(--ink-faint,#97a0b2);padding:8px;text-align:center;overflow-wrap:anywhere}

.mlib-badge{position:absolute;top:6px;left:6px;display:flex;flex-wrap:wrap;gap:4px;max-width:calc(100% - 12px)}
.mlib-pill{display:inline-block;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:600;
           background:rgba(16,23,41,.72);color:#fff;white-space:nowrap}
.mlib-pill.is-free{background:rgba(98,108,128,.72)}

.mlib-meta{padding:9px 10px;display:grid;gap:2px;min-width:0}
/* overflow-wrap:anywhere, not ellipsis: a media filename is the only way to
   tell two shots of the same product apart, and truncating it to
   "20260916-101…" makes the grid unusable for the one job it has. */
.mlib-name{font-size:12px;font-weight:600;overflow-wrap:anywhere;line-height:1.35}
.mlib-dim{font-size:11px;color:var(--ink-soft,#626c80);overflow-wrap:anywhere}
/* The "used by" line carries more weight than the dimensions beside it — it is
   the one an owner scans for — so it takes the body ink rather than the muted
   grey, while keeping the same size and the same wrapping. */
.mlib-used{color:var(--ink-2,#3c465c);font-weight:500}

.mlib-empty{padding:30px 16px;text-align:center;color:var(--ink-soft,#626c80);font-size:13px;
            display:grid;gap:8px;justify-items:center;min-width:0}
.mlib-empty b{font-size:14px;color:var(--ink,#101729)}
.mlib-empty p{margin:0;max-width:52ch;overflow-wrap:anywhere}

.mlib-pager{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center;min-width:0}
.mlib-pager span{font-size:12.5px;color:var(--ink-soft,#626c80);font-variant-numeric:tabular-nums}

/* ── the detail dialog ─────────────────────────────────────────────────── */
.mlib-back{position:fixed;inset:0;background:rgba(10,14,25,.55);z-index:120;
           display:grid;place-items:center;padding:16px;overflow:auto}
.mlib-modal{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);border-radius:14px;
            width:min(760px,100%);max-height:calc(100vh - 32px);overflow:auto;
            padding:18px;display:grid;gap:14px;min-width:0}
.mlib-modal > *{min-width:0}
.mlib-modal h3{margin:0;font-size:15px;overflow-wrap:anywhere}

/* Two columns on a wide dialog, stacked on a phone. Both children carry
   min-width:0 or the preview's intrinsic width wins and the dialog overflows
   its own max-width. */
.mlib-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;min-width:0}
.mlib-cols > *{min-width:0}
@media (max-width:640px){.mlib-cols{grid-template-columns:minmax(0,1fr)}}

.mlib-preview{background:var(--surface-2,#f2f4fb);border-radius:10px;display:grid;place-items:center;
              padding:10px;min-height:150px;min-width:0}
.mlib-preview img{max-width:100%;max-height:300px;object-fit:contain;display:block}

.mlib-facts{display:grid;gap:6px;font-size:12.5px;min-width:0}
.mlib-fact{display:grid;grid-template-columns:minmax(0,88px) minmax(0,1fr);gap:8px;min-width:0}
.mlib-fact > *{min-width:0}
.mlib-fact dt{color:var(--ink-soft,#626c80)}
.mlib-fact dd{margin:0;overflow-wrap:anywhere}
.mlib-fact code{font-family:var(--mono,ui-monospace,monospace);font-size:11px;overflow-wrap:anywhere}

.mlib-uses{display:grid;gap:6px;min-width:0}
.mlib-use{border:1px solid var(--border,#e6e9f2);border-radius:9px;padding:7px 10px;font-size:12px;
          display:flex;flex-wrap:wrap;gap:4px 8px;align-items:baseline;min-width:0}
.mlib-use > *{min-width:0}
.mlib-use b{overflow-wrap:anywhere}
.mlib-use span{color:var(--ink-soft,#626c80);font-size:11.5px}

.mlib-warn{border:1px solid #f0c9a0;background:#fdf3e6;color:#8a5216;border-radius:10px;
           padding:10px 12px;font-size:12.5px;overflow-wrap:anywhere}
.mlib-ok{border:1px solid var(--border,#e6e9f2);background:var(--surface-2,#f2f4fb);
         color:var(--ink-soft,#626c80);border-radius:10px;padding:10px 12px;font-size:12.5px}

.mlib-acts{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;min-width:0}
@media (max-width:420px){
  /* Stacked, full width. Three buttons side by side at 390px each end up
     narrower than their own labels, which is how a Delete button ends up
     reading "Del…" next to a Cancel the operator meant to press. */
  .mlib-acts{justify-content:stretch}
  .mlib-acts .mlib-btn{flex:1 1 100%}
}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'media';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;          // the last successful grid response
  var state = {page: 1, q: '', from: '', to: '', attached: '', attached_q: ''};
  var busy = false;
  var extraOpen = false;    // phone only; above 700px CSS shows the panel regardless
  var seq = 0;              // guards against an out-of-order response painting
  var modal = null;
  var detail = null;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function endpoint(path){
    return BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path;
  }

  /* One fetch wrapper, so every call site is a try/catch around one line
     rather than a status-code ladder. A refusal carries the controller's own
     `message`, written for the operator, and the parsed body is kept on the
     error because the 409 from DELETE carries the usage list the confirm
     dialog has to show. */
  async function api(path, method, body){
    var opts = {
      method: method || 'GET',
      credentials: 'same-origin',
      headers: {'Accept':'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN')}
    };

    if (body !== undefined && body !== null) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var r = await fetch(endpoint(path), opts);
    var j = null;
    try { j = await r.json(); } catch (e) { j = null; }

    if (!r.ok) {
      var err = new Error((j && j.message) || ('Request failed (' + r.status + ')'));
      err.status = r.status;
      err.body = j;
      throw err;
    }

    return j;
  }

  /* Every operator-supplied string -- a filename, alt text, a product name, an
     error message that quotes one back -- goes through this before it reaches
     innerHTML. Media filenames are chosen by whoever uploaded the file. */
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function say(msg){
    if (typeof window.toast === 'function') window.toast(msg);
  }

  function bytes(n){
    if (n == null) return '—';
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function when(iso){
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString(undefined, {year:'numeric', month:'short', day:'numeric'});
  }

  function dims(item){
    if (!item.width || !item.height) {
      // SVG has no pixel dimensions, and getimagesize() returns nothing for
      // one. Saying so beats printing "0 x 0", which reads as a broken file.
      return item.mime === 'image/svg+xml' ? 'vector' : '—';
    }
    return item.width + ' x ' + item.height;
  }

  function query(){
    var p = [];
    if (state.q) p.push('q=' + encodeURIComponent(state.q));
    if (state.from) p.push('from=' + encodeURIComponent(state.from));
    if (state.to) p.push('to=' + encodeURIComponent(state.to));
    if (state.attached) p.push('attached=' + encodeURIComponent(state.attached));
    if (state.attached && state.attached !== 'unused' && state.attached_q) {
      p.push('attached_q=' + encodeURIComponent(state.attached_q));
    }
    if (state.page > 1) p.push('page=' + state.page);
    return p.length ? ('?' + p.join('&')) : '';
  }

  /* ------------------------------------------------------------------ load */
  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      var j = await api('/media' + query(), 'GET');
      if (mine !== seq) return;      // a later request already answered
      data = j;
    } catch (e) {
      if (mine !== seq) return;
      data = {error: e.message, items: [], total: 0, pages: 1, page: 1, bytes: 0};
    }

    busy = false;
    render();
  }

  /* ---------------------------------------------------------------- render */
  function render(){
    var root = document.getElementById('mlib-root');
    if (!root) return;

    root.innerHTML =
      '<div class="mlib-wrap">'
      + headCard()
      + filterCard()
      + gridCard()
      + '</div>';

    wire();
  }

  function headCard(){
    var total = data ? data.total : 0;
    var size = data ? data.bytes : 0;

    return '<div class="mlib-card"><div class="mlib-head">'
      + '<div><div class="mlib-title">Media Library</div>'
      + '<div class="mlib-sub">Every image uploaded through the admin — product photos, brand logos, '
      + 'category images and the SEO share image all land here.</div></div>'
      + '<button class="mlib-btn" id="mlib-rescan"' + (busy ? ' disabled' : '') + '>Rescan folder</button>'
      + '</div>'
      + '<div class="mlib-stats" style="margin-top:14px">'
      + '<div class="mlib-stat"><b>' + esc(String(total)) + '</b><span>'
      + (filtered() ? 'images match' : 'images in the library') + '</span></div>'
      + '<div class="mlib-stat"><b>' + esc(bytes(size)) + '</b><span>total size</span></div>'
      + '</div></div>';
  }

  function filtered(){
    return !!(state.q || state.from || state.to || state.attached);
  }

  function filterCard(){
    var opts = [
      ['', 'Anywhere — no filter'],
      ['any', 'Attached to anything'],
      ['product', 'Attached to a product'],
      ['brand', 'Attached to a brand'],
      ['category', 'Attached to a category'],
      ['unused', 'Not used anywhere']
    ].map(function(o){
      return '<option value="' + esc(o[0]) + '"' + (state.attached === o[0] ? ' selected' : '') + '>'
           + esc(o[1]) + '</option>';
    }).join('');

    // The owner box only means something for the three that HAVE an owner;
    // offering it beside "Not used anywhere" would be a control that does
    // nothing, which is the fault this project has hit three times.
    var ownerable = state.attached && state.attached !== 'unused';

    // How many of the folded-away filters are actually set, so the toggle can
    // say so — a collapsed panel silently narrowing the grid is how somebody
    // concludes the library has lost their images.
    var extras = [state.attached, state.from, state.to].filter(Boolean).length;

    return '<div class="mlib-card"><div class="mlib-filters">'

      + '<div class="mlib-field"><label for="mlib-q">Search by name</label>'
      + '<input id="mlib-q" type="search" placeholder="filename or alt text" value="' + esc(state.q) + '"></div>'

      + '<button class="mlib-btn mlib-toggle" id="mlib-more" aria-expanded="' + (extraOpen ? 'true' : 'false') + '">'
      + (extraOpen ? 'Hide filters' : 'More filters')
      + (extras ? ' (' + extras + ' on)' : '')
      + '</button>'

      + '<div class="mlib-extra' + (extraOpen ? ' is-open' : '') + '">'

      + '<div class="mlib-field"><label for="mlib-attached">Used by</label>'
      + '<select id="mlib-attached">' + opts + '</select></div>'

      + '<div class="mlib-field"><label for="mlib-attachedq">Name of the product, brand or category</label>'
      + '<input id="mlib-attachedq" type="search"'
      + ' placeholder="' + (ownerable ? 'e.g. COSRX' : 'pick a Used by first') + '"'
      + ' value="' + esc(state.attached_q) + '"' + (ownerable ? '' : ' disabled') + '></div>'

      + '<div class="mlib-field"><label for="mlib-from">Uploaded from</label>'
      + '<input id="mlib-from" type="date" value="' + esc(state.from) + '"></div>'

      + '<div class="mlib-field"><label for="mlib-to">Uploaded to</label>'
      + '<input id="mlib-to" type="date" value="' + esc(state.to) + '"></div>'

      + '<div class="mlib-field"><label>&nbsp;</label>'
      + '<button class="mlib-btn" id="mlib-clear"' + (filtered() ? '' : ' disabled') + '>Clear filters</button></div>'

      + '</div></div></div>';
  }

  function gridCard(){
    if (busy && !data) {
      return '<div class="mlib-card"><div class="mlib-empty"><p>Loading the library…</p></div></div>';
    }

    if (data && data.error) {
      return '<div class="mlib-card"><div class="mlib-empty"><b>The library could not be loaded</b>'
        + '<p>' + esc(data.error) + '</p></div></div>';
    }

    var items = (data && data.items) || [];

    if (!items.length) {
      return '<div class="mlib-card">' + emptyState() + '</div>';
    }

    var tiles = items.map(tile).join('');

    return '<div class="mlib-card"><div class="mlib-grid">' + tiles + '</div>'
      + pager() + '</div>';
  }

  /* An empty grid has two completely different meanings and they must not read
     the same. "Your filter found nothing" is a normal result; "there is nothing
     here at all" is the state this screen was built to end, and it names the
     reason rather than leaving the owner to wonder whether it is broken. */
  function emptyState(){
    if (filtered()) {
      return '<div class="mlib-empty"><b>No images match</b>'
        + '<p>Nothing in the library matches these filters. Clear them to see everything.</p>'
        + '<button class="mlib-btn" id="mlib-clear2">Clear filters</button></div>';
    }

    return '<div class="mlib-empty"><b>Nothing here yet</b>'
      + '<p>Images appear here as soon as they are uploaded — from the product editor, a brand logo, '
      + 'a category image or the SEO share image. If you know there are images on the server already, '
      + 'press Rescan folder and they will be catalogued.</p></div>';
  }

  function tile(item){
    var badge = item.used
      ? '<span class="mlib-pill">' + esc(item.used_types.join(', ')) + '</span>'
      : '<span class="mlib-pill is-free">unused</span>';

    // loading="lazy" and decoding="async" because a full page of 24 originals
    // is the whole point of the screen and none of them are resized server-side
    // -- the `sizes` column exists but nothing has ever generated a variant.
    var img = item.url
      ? '<img src="' + esc(item.url) + '" alt="" loading="lazy" decoding="async">'
      : '<div class="mlib-noimg">no file</div>';

    /* The operator's own name is the title. UNDERNEATH IT goes what the image
       is used by — the product, brand or category — because that is what the
       owner recognises an image by and it is exact rather than guessed.

       It used to print the stored filename there: Ymd-His-<random>.ext, which
       the upload endpoint generates so the browser cannot choose what lands in
       the web root. Useful when chasing a URL, and useless the other 99% of
       the time — the owner asked for it replaced, and they are right. It has
       not been thrown away: the detail panel still shows it, which is where
       somebody chasing a URL is looking anyway.

       An image nothing uses says so, which is worth more than a filename: it
       is the line that tells the owner this one is safe to delete. */
    var title = item.original_name || item.filename;

    var names = Array.isArray(item.used_names) ? item.used_names.filter(Boolean) : [];
    var extra = (item.used_count || 0) - names.length;

    var sub = names.length
      ? '<span class="mlib-dim mlib-used">' + esc(names.join(', '))
        + (extra > 0 ? esc(' +' + extra + ' more') : '') + '</span>'
      : '<span class="mlib-dim">Not used yet</span>';

    return '<button type="button" class="mlib-tile" data-mlib-open="' + esc(String(item.id)) + '">'
      + '<span class="mlib-thumb">' + img + '<span class="mlib-badge">' + badge + '</span></span>'
      + '<span class="mlib-meta">'
      + '<span class="mlib-name">' + esc(title) + '</span>'
      + sub
      + '<span class="mlib-dim">' + esc(dims(item)) + ' · ' + esc(bytes(item.size)) + '</span>'
      + '<span class="mlib-dim">' + esc(when(item.created_at)) + '</span>'
      + '</span></button>';
  }

  function pager(){
    if (!data || data.pages <= 1) return '';

    return '<div class="mlib-pager" style="margin-top:14px">'
      + '<button class="mlib-btn" id="mlib-prev"' + (data.page <= 1 ? ' disabled' : '') + '>Previous</button>'
      + '<span>Page ' + esc(String(data.page)) + ' of ' + esc(String(data.pages)) + '</span>'
      + '<button class="mlib-btn" id="mlib-next"' + (data.page >= data.pages ? ' disabled' : '') + '>Next</button>'
      + '</div>';
  }

  /* ------------------------------------------------------------------ wire */
  function on(id, event, fn){
    var el = document.getElementById(id);
    if (el) el.addEventListener(event, fn);
  }

  function wire(){
    var t = null;

    on('mlib-q', 'input', function(e){
      clearTimeout(t);
      var v = e.target.value;
      t = setTimeout(function(){ state.q = v.trim(); state.page = 1; load(); }, 250);
    });

    on('mlib-attachedq', 'input', function(e){
      clearTimeout(t);
      var v = e.target.value;
      t = setTimeout(function(){ state.attached_q = v.trim(); state.page = 1; load(); }, 250);
    });

    on('mlib-attached', 'change', function(e){
      state.attached = e.target.value;
      // The owner box is meaningless without an owner kind, and leaving a stale
      // value in it while it is disabled would silently narrow the next search.
      if (!state.attached || state.attached === 'unused') state.attached_q = '';
      state.page = 1;
      load();
    });

    on('mlib-from', 'change', function(e){ state.from = e.target.value; state.page = 1; load(); });
    on('mlib-to', 'change', function(e){ state.to = e.target.value; state.page = 1; load(); });

    on('mlib-clear', 'click', clear);
    on('mlib-clear2', 'click', clear);

    on('mlib-prev', 'click', function(){ if (state.page > 1) { state.page--; load(); } });
    on('mlib-next', 'click', function(){ if (data && state.page < data.pages) { state.page++; load(); } });

    on('mlib-rescan', 'click', rescan);

    on('mlib-more', 'click', function(){ extraOpen = !extraOpen; render(); });

    /* Namespaced to this screen. A bare data-open on these tiles fired another
       screen's delegated handler on this project once already. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-mlib-open]'), function(el){
      el.addEventListener('click', function(){ open(parseInt(el.getAttribute('data-mlib-open'), 10)); });
    });
  }

  function clear(){
    state = {page: 1, q: '', from: '', to: '', attached: '', attached_q: ''};
    load();
  }

  async function rescan(){
    if (busy) return;

    try {
      var j = await api('/media/rescan', 'POST');
      say(j.added ? ('Catalogued ' + j.added + ' image' + (j.added === 1 ? '' : 's') + '.')
                  : 'Nothing new on disk — the library is up to date.');
      state.page = 1;
      load();
    } catch (e) {
      say(e.message);
    }
  }

  /* ---------------------------------------------------------------- detail */
  async function open(id){
    if (!id && id !== 0) return;

    try {
      var j = await api('/media/' + id, 'GET');
      detail = j.item;
      showModal(detailHTML(detail));
      wireModal();
    } catch (e) {
      say(e.message);
    }
  }

  function detailHTML(item){
    var uses = (item.usage || []).map(function(u){
      return '<div class="mlib-use"><b>' + esc(u.name) + '</b>'
        + '<span>' + esc(u.type) + ' · ' + esc(u.field) + '</span></div>';
    }).join('');

    var where = item.usage && item.usage.length
      ? '<div class="mlib-uses">' + uses + '</div>'
      + '<div class="mlib-warn">Deleting this image would leave a broken image on '
      + (item.usage.length === 1 ? 'that page' : 'those pages') + '.</div>'
      : '<div class="mlib-ok">Nothing on the store points at this image, so it is safe to delete.</div>';

    return '<h3>' + esc(item.original_name || item.filename) + '</h3>'
      + '<div class="mlib-cols">'
      + '<div class="mlib-preview">'
      + (item.url ? '<img src="' + esc(item.url) + '" alt="' + esc(item.alt) + '">' : '<span class="mlib-sub">no file</span>')
      + '</div>'
      + '<dl class="mlib-facts">'
      + fact('Dimensions', esc(dims(item)))
      + fact('File size', esc(bytes(item.size)))
      + fact('Type', esc(item.mime || '—'))
      + fact('Uploaded', esc(when(item.created_at)))
      + fact('Alt text', item.alt ? esc(item.alt) : '<span class="mlib-sub">none</span>')
      + fact('Stored as', '<code>' + esc(item.filename) + '</code>')
      + fact('Path', '<code>' + esc(item.path) + '</code>')
      + '</dl></div>'
      + '<div><div class="mlib-title" style="margin-bottom:6px">Used by</div>' + where + '</div>'
      + '<div class="mlib-acts">'
      + '<button class="mlib-btn" id="mlib-copy">Copy URL</button>'
      + '<button class="mlib-btn is-danger" id="mlib-del">Delete</button>'
      + '<button class="mlib-btn is-primary" id="mlib-close">Close</button>'
      + '</div>';
  }

  function fact(label, value){
    return '<div class="mlib-fact"><dt>' + esc(label) + '</dt><dd>' + value + '</dd></div>';
  }

  function wireModal(){
    on('mlib-close', 'click', closeModal);
    on('mlib-copy', 'click', function(){
      var url = detail && detail.url;
      if (!url) return;

      // navigator.clipboard is unavailable on an insecure origin, and this
      // admin is reached over plain http on at least one of the owner's
      // machines. Falling back rather than failing silently.
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function(){ say('URL copied.'); },
                                                function(){ prompt('Copy this URL:', url); });
      } else {
        prompt('Copy this URL:', url);
      }
    });

    on('mlib-del', 'click', function(){ confirmDelete(detail); });
  }

  /* The delete confirmation, which is where the refusal actually lands.
     The server refuses a referenced file with 409 and the list of what uses it;
     nothing here decides that on its own, so a stale grid cannot talk the
     server into a delete it would not otherwise allow. */
  function confirmDelete(item){
    if (!item) return;

    var used = item.usage && item.usage.length;

    var body = '<h3>Delete ' + esc(item.original_name || item.filename) + '?</h3>'
      + (used
          ? '<div class="mlib-warn"><b>This image is still in use.</b><br>'
            + (item.usage || []).map(function(u){
                return esc(u.type) + ' “' + esc(u.name) + '” (' + esc(u.field) + ')';
              }).join('<br>')
            + '<br><br>Deleting it will leave a broken image on '
            + (used === 1 ? 'that page' : 'those pages')
            + '. Change the image there first if you can.</div>'
          : '<div class="mlib-ok">Nothing points at this image. The file will be removed from the server.</div>')
      + '<div class="mlib-acts">'
      + '<button class="mlib-btn" id="mlib-cancel">Cancel</button>'
      + '<button class="mlib-btn is-danger" id="mlib-confirm">'
      + (used ? 'Delete anyway' : 'Delete') + '</button>'
      + '</div>';

    showModal(body);

    on('mlib-cancel', 'click', function(){ open(item.id); });
    on('mlib-confirm', 'click', function(){ doDelete(item, !!used); });
  }

  async function doDelete(item, force){
    try {
      await api('/media/' + item.id + (force ? '?force=1' : ''), 'DELETE');
      closeModal();
      say('Deleted ' + (item.original_name || item.filename) + '.');
      load();
    } catch (e) {
      if (e.status === 409 && e.body && e.body.usage) {
        // The grid was stale: something started using this image since the
        // detail panel was drawn. Re-ask, with the list the server just gave.
        item.usage = e.body.usage;
        confirmDelete(item);
        say(e.message);
        return;
      }
      say(e.message);
    }
  }

  /* ----------------------------------------------------------------- modal */
  function showModal(inner){
    closeModal();

    modal = document.createElement('div');
    modal.className = 'mlib-back';
    modal.innerHTML = '<div class="mlib-modal" role="dialog" aria-modal="true">' + inner + '</div>';

    modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', escKey);
    document.body.appendChild(modal);
  }

  function escKey(e){ if (e.key === 'Escape') closeModal(); }

  function closeModal(){
    document.removeEventListener('keydown', escKey);
    if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
    modal = null;
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });

    if (typeof window.syncNavOpen === 'function') window.syncNavOpen(SCREEN);

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Content';
    if (title) title.textContent = 'Media Library';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) { content.innerHTML = '<div class="wrap"><div id="mlib-root"></div></div>'; content.scrollTop = 0; }

    /* The console's own `cur` is declared `let cur='dash'` at the top level of
       its script, which is a script-scope binding and NOT a property of window,
       so this screen cannot set it and does not pretend to. It only matters to
       mountFrame(), which decides whether an in-flight probe is still wanted —
       and 'media' is no longer in FRAME_SRC, so no probe is ever started for
       this screen and there is nothing to race. */

    state.page = 1;
    data = null;
    load();
    return undefined;
  };

  /* Exposed for the layout test, which drives the screen in a real browser
     without an admin session to log into. */
  window.__mlibRenderForTest = function(fixture){
    data = fixture;
    busy = false;
    render();
  };
  window.__mlibState = function(patch){ Object.assign(state, patch || {}); };
  window.__mlibDetail = function(item){ detail = item; showModal(detailHTML(item)); wireModal(); };
  window.__mlibConfirm = function(item){ detail = item; confirmDelete(item); };
})();
</script>
@endverbatim
