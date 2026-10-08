
(function(){
  'use strict';

  var SCREEN = 'media';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;          // the last successful grid response
  /*
   * `kind` IS 'all' HERE AND 'image' AT THE ENDPOINT, and the two are not in
   * disagreement -- they are two callers with two jobs.
   *
   * GET /admin-api/media defaults to excluding video, because its other caller
   * is the shared picker that every image field in the console opens and a .mp4
   * offered as a brand logo is a regression. This screen is the LIBRARY, and the
   * owner asked in as many words for his uploads to show up in it, so it sends
   * kind=all on every request and offers him the narrowing as a control.
   */
  var state = {page: 1, q: '', from: '', to: '', attached: '', attached_q: '', kind: 'all'};

  /* How many tiles across. A way of looking at the library rather than a
     property of anything in it, so it lives in localStorage per browser and
     never reaches the server. Wrapped because localStorage throws in a private
     window and a throw here would take the screen down for a preference. */
  var COLS = ['auto', 4, 6, 8, 10];

  var cols = (function(){
    try {
      var v = localStorage.getItem('kbb.mlib.cols');
      if (v === 'auto') return 'auto';
      var n = parseInt(v, 10);
      return COLS.indexOf(n) !== -1 ? n : 'auto';
    } catch (e) { return 'auto'; }
  })();

  function colsBar(){
    return '<span class="mlib-colpick" role="group" aria-label="Columns">'
      + COLS.map(function(c){
          return '<button type="button" data-mlib-cols="' + c + '"'
            + (String(c) === String(cols) ? ' class="on"' : '')
            + ' aria-pressed="' + (String(c) === String(cols) ? 'true' : 'false') + '"'
            + ' title="' + (c === 'auto' ? 'As many as fit' : c + ' columns') + '">'
            + (c === 'auto' ? 'Auto' : c) + '</button>';
        }).join('')
      + '</span>';
  }

  /* Set on the grid rather than re-rendering: a repaint would cost a request
     and throw away the operator's scroll position for a column count. */
  function applyCols(){
    var g = document.querySelector('#content .mlib-grid');
    if (!g) return;
    if (cols === 'auto') g.style.removeProperty('--mlib-cols');
    else g.style.setProperty('--mlib-cols', String(cols));
  }

  function setCols(v){
    cols = v;
    try { localStorage.setItem('kbb.mlib.cols', String(v)); } catch (e) {}
    applyCols();
    document.querySelectorAll('#content .mlib-colpick button').forEach(function(b){
      var on = b.dataset.mlibCols === String(v);
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }
  var busy = false;
  var sizes = null;         // the phone-sized-copy tally, once it has been asked for
  var sizing = false;       // a batch is in flight
  var sizingMade = 0;       // copies written this run
  var sizingSized = 0;      // photographs finished this run
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
      /* A 404 WITH NO JSON BODY IS THE ROUTE CACHE, and it is worth telling
         apart from the other 404 this endpoint really does return.

         Every package that adds a route ships a clear_caches migration
         precisely because the compiled route table wins over routes/web.php
         until it is cleared; when that migration does not run, these paths are
         simply not known and Laravel answers its own 404 HTML PAGE. A media row
         that has genuinely been deleted answers 404 with a JSON body carrying a
         message. So the presence of a parsed body is the discriminator, and it
         is exact -- no status ladder can tell those two apart, and "Request
         failed (404)" is the same sentence for both while the remedies are
         "clear the route cache" and "it is already gone". */
      var silent = !j || (!j.message && !j.error);
      var err = new Error((j && j.message) || (r.status === 404 && silent
        ? 'The Media Library endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform \u2192 Cache) and reload.'
        : ('Request failed (' + r.status + ')')));
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
    /* The owner search no longer requires a "Used by" to be chosen first.
       Typing a product name is the most direct thing an owner can do — they
       know the product, not which kind of thing it is — and making them pick
       a category of owner before they may type its name was a step that
       existed only because the query was built that way. With no kind chosen
       the endpoint searches every kind, which is what 'any' means.

       Still suppressed for 'unused', where it would contradict itself: an
       image nothing uses has no owner whose name could match. */
    if (state.attached !== 'unused' && state.attached_q) {
      p.push('attached_q=' + encodeURIComponent(state.attached_q));

      // The endpoint keys the owner search off `attached`, so a search with no
      // kind chosen has to say "any" explicitly rather than send nothing.
      if (!state.attached) p.push('attached=any');
    }
    /* Always sent, never omitted: the endpoint's own default is images-only for
       the picker's sake, so "everything" has to be asked for out loud. */
    if (state.kind) p.push('kind=' + encodeURIComponent(state.kind));
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
      /* THE SENTENCE HAS TO LIST WHAT ACTUALLY LANDS HERE, and twice now it has
          not. It named four sources and every one of them was an image, which
          was true until Shoppable video; it then opened "Every file uploaded
          through the admin", which was true until three writers that are not
          admin uploads at all started registering their files:

            Import\MediaSideloader        images pulled in by a store import
            Store\ReviewController        photos a CUSTOMER attaches to a review
            Instagram\InstagramSync       whatever the Instagram sync downloads

          The first sentence an owner reads is not the place to be wrong about
          where his files come from, and the two customer-facing ones are the
          ones he most needs to know are in here — a shopper's photograph sitting
          in the library unannounced is a surprise, and the library is where it
          gets deleted from. So the sentence no longer claims an origin it cannot
          promise: it says these all land here, and names every writer.
          MediaLibraryBlurbNamesEverySourceTest pins the list against the call
          sites, so a seventh writer cannot be added without this going red.

          THE LINE BREAKS ARE NOT FREE. MediaEverywhereTest reads THIS FILE AS
          TEXT and pins the phrase "the clips and covers from Shoppable video",
          so wrapping the sentence through the middle of it turns a true
          sentence into a red test with a confusing message. It gets a line of
          its own for that reason. */
      + '<div class="mlib-sub">Every file this shop keeps a copy of lands here — product photos, '
      + 'brand logos, category images, the SEO share image, '
      + 'the clips and covers from Shoppable video, '
      + 'images brought in by a store import, photos customers attach to their reviews, '
      + 'the pictures on your banner cards, '
      + 'the placeholder shots in the demo products\' galleries, '
      + 'and anything the Instagram sync downloads.</div></div>'
      + colsBar()
      + '<button class="mlib-btn" id="mlib-rescan"' + (busy ? ' disabled' : '') + '>Rescan folder</button>'
      + '<button class="mlib-btn" id="mlib-sizes"' + (sizing ? ' disabled' : '') + '>'
      + (sizing ? 'Making copies…' : 'Make phone-sized copies') + '</button>'
      /* Lane WP: Content -> Media Library -> WebP images, drawn by
         admin/partials/webp-screen. Offered only when that partial loaded. */
      + (window.kbbWebpOpen ? '<button class="mlib-btn" id="mlib-webp">WebP images</button>' : '')
      + '</div>'
      + '<div class="mlib-stats" style="margin-top:14px">'
      + '<div class="mlib-stat"><b>' + esc(String(total)) + '</b><span>'
      /* "files", not "images": the count includes videos. */
      + (filtered() ? 'files match' : 'files in the library') + '</span></div>'
      + '<div class="mlib-stat"><b>' + esc(bytes(size)) + '</b><span>total size</span></div>'
      + sizesStat()
      + '</div></div>';
  }

  /* ------------------------------------------------- phone-sized copies */
  /* A product photograph is a 1000x1000 file painted into a tile no wider than
     399 pixels. A shopper on a phone downloads the whole thing. The fix is a
     smaller copy of each photograph, and on this host -- no shell, no queue
     worker, no cron -- the only thing that can make copies of the photographs
     already in the shop is the owner, from here. See
     App\Http\Controllers\Admin\ImageSizesApiController for what else was
     considered and why none of it exists on this host.

     The batch is driven from the browser rather than run server-side in one
     go, because one request that resized three thousand photographs would run
     for seven minutes and be killed by max_execution_time somewhere in the
     middle of it. Each request below does a few seconds of work and hands back
     a cursor. Stopping early is safe and losing the tab is safe: the work is
     idempotent, and a photograph without its copies simply loads the full-size
     one, exactly as every photograph does today. */
  function sizesStat(){
    if (!sizes) return '';

    if (sizes.available === false) {
      return '<div class="mlib-stat"><b>—</b><span>this server has no image library (GD), '
        + 'so it cannot make smaller copies</span></div>';
    }

    if (sizing) {
      return '<div class="mlib-stat"><b>' + esc(String(sizingSized)) + '</b>'
        + '<span>photographs done this run — leave this tab open</span></div>';
    }

    return '<div class="mlib-stat"><b>' + esc(String(sizes.remaining)) + '</b>'
      + '<span>' + (sizes.remaining
        ? 'product photographs still full size on a phone'
        : 'left to do — every product photograph has a phone-sized copy')
      + '</span></div>';
  }

  async function loadSizes(){
    try { sizes = await api('/media/image-sizes', 'GET'); }
    catch (e) { sizes = null; }
    render();
  }

  async function makeSizes(){
    if (sizing) return;

    if (!sizes) await loadSizes();

    if (sizes && sizes.available === false) {
      say('This server has no image library (GD), so it cannot make smaller copies.');
      return;
    }

    sizing = true;
    sizingMade = 0;
    sizingSized = 0;
    render();

    var cursor = '';
    /* The catalogue is walked in bounded steps, so the number of steps is
       bounded too. This only stops a bug in the cursor from becoming an
       infinite loop against the owner's own server. */
    var steps = 0;

    try {
      for (;;) {
        var j = await api('/media/image-sizes/run', 'POST', {after: cursor});
        sizingMade += j.made;
        sizingSized += j.sized;
        cursor = j.cursor;
        render();
        if (j.done || ++steps > 5000) break;
      }

      say(sizingMade
        ? ('Made ' + sizingMade + ' smaller cop' + (sizingMade === 1 ? 'y' : 'ies') + '.')
        : 'Every product photograph already has its phone-sized copies.');
    } catch (e) {
      /* Whatever was finished before the failure stays finished. */
      say(e.message);
    }

    sizing = false;
    await loadSizes();
  }

  function filtered(){
    /* kind counts as a filter ONLY when it is narrowing. 'all' is this screen's
       resting state, so a fresh library must not offer to clear a filter nobody
       set -- the "Clear filters" button being live on an untouched screen is how
       somebody concludes the grid is already hiding something. */
    return !!(state.q || state.from || state.to || state.attached || (state.kind && state.kind !== 'all'));
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
    /* Enabled unless the operator has asked for images nothing uses, where an
       owner name is a contradiction rather than a filter. Everything else —
       including no kind chosen at all — searches owners by name. */
    var ownerable = state.attached !== 'unused';

    // How many of the folded-away filters are actually set, so the toggle can
    // say so — a collapsed panel silently narrowing the grid is how somebody
    // concludes the library has lost their images.
    var extras = [state.attached, state.from, state.to,
                  (state.kind && state.kind !== 'all') ? state.kind : ''].filter(Boolean).length;

    /* Pictures or videos. Three options, and the resting one is "everything" --
       a library that hid half of what was in it by default would be the same
       screen the owner has been complaining about. */
    var kinds = [
      ['all', 'Everything'],
      ['image', 'Pictures only'],
      ['video', 'Videos only']
    ].map(function(o){
      return '<option value="' + esc(o[0]) + '"' + (state.kind === o[0] ? ' selected' : '') + '>'
           + esc(o[1]) + '</option>';
    }).join('');

    return '<div class="mlib-card"><div class="mlib-filters">'

      + '<div class="mlib-field"><label for="mlib-q">Search by name</label>'
      + '<input id="mlib-q" type="search" placeholder="filename or alt text" value="' + esc(state.q) + '"></div>'

      + '<button class="mlib-btn mlib-toggle" id="mlib-more" aria-expanded="' + (extraOpen ? 'true' : 'false') + '">'
      + (extraOpen ? 'Hide filters' : 'More filters')
      + (extras ? ' (' + extras + ' on)' : '')
      + '</button>'

      + '<div class="mlib-extra' + (extraOpen ? ' is-open' : '') + '">'

      + '<div class="mlib-field"><label for="mlib-kind">Show</label>'
      + '<select id="mlib-kind">' + kinds + '</select></div>'

      + '<div class="mlib-field"><label for="mlib-attached">Used by</label>'
      + '<select id="mlib-attached">' + opts + '</select></div>'

      + '<div class="mlib-field"><label for="mlib-attachedq">Name of the product, brand or category</label>'
      + '<input id="mlib-attachedq" type="search"'
      + ' placeholder="' + (ownerable ? 'e.g. COSRX, or a product name' : 'not used by anything') + '"'
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
      return '<div class="mlib-empty"><b>Nothing matches</b>'
        + '<p>Nothing in the library matches these filters. Clear them to see everything.</p>'
        + '<button class="mlib-btn" id="mlib-clear2">Clear filters</button></div>';
    }

    return '<div class="mlib-empty"><b>Nothing here yet</b>'
      + '<p>Media appears here as soon as it is uploaded — from the product editor, a brand logo, '
      + 'a category image, the SEO share image, or a clip or cover from Content → Shoppable video. '
      + 'If you know there are files on the server already, '
      + 'press Rescan folder and they will be catalogued.</p></div>';
  }

  /*
   * The film strip a video tile draws. A CONSTANT, and printed unescaped only
   * because it is one -- rule 5. No src, no request, no measurement.
   */
  function filmIcon(){
    return '<svg viewBox="0 0 24 24" aria-hidden="true" stroke-linecap="round" stroke-linejoin="round">'
      + '<rect x="2.5" y="5" width="19" height="14" rx="2"/>'
      + '<path d="M7 5v14M17 5v14M2.5 9.7h4.5M2.5 14.3h4.5M17 9.7h4.5M17 14.3h4.5"/></svg>';
  }

  /* The container, upper-cased, from the mime this shop stored. Falls back to
     the plain word rather than printing "VIDEO/" for an odd spelling. */
  function videoLabel(item){
    var m = String(item.mime || '');
    var slash = m.indexOf('/');
    var sub = slash >= 0 ? m.slice(slash + 1) : '';
    return sub ? sub.toUpperCase() : 'VIDEO';
  }

  function tile(item){
    var badge = (item.is_video ? '<span class="mlib-pill is-video">video</span>' : '')
      + (item.used
        ? '<span class="mlib-pill">' + esc(item.used_types.join(', ')) + '</span>'
        : '<span class="mlib-pill is-free">unused</span>');
    /* (Lane IR) The Image SEO score, when the picture has one, and the green
       tick the owner asked for: renamed by Catalog -> Image SEO AND 8/10+. */
    if (item.seo_ten !== null && item.seo_ten !== undefined) {
      var seoBand = item.seo_ten >= 8 ? 'is-good' : (item.seo_ten >= 5 ? 'is-mid' : 'is-low');
      badge += '<span class="mlib-pill is-seo ' + seoBand + '" title="'
        + esc('Image SEO score ' + item.seo_ten + '/10' + (item.seo_renamed ? ', renamed by Image SEO' : ', not renamed yet')) + '">'
        + (item.seo_tick ? '✓ ' : '') + esc(String(item.seo_ten)) + '/10</span>';
    }

    /*
     * A VIDEO DRAWS A FILM STRIP, NOT AN <img>.
     *
     * THE DEFECT THIS AVOIDS, and it is the reason this branch exists at all:
     * every row in this grid used to be an image, so the tile emitted
     * `<img src=item.url>` unconditionally. From this lane the library also
     * holds .mp4 and .webm -- the owner asked for it -- and an <img> pointed at
     * an mp4 is a broken-image glyph. Not a missing thumbnail: a BROKEN one,
     * which reads as "this file is corrupt" for a clip that plays perfectly on
     * the shop.
     *
     * Why a placeholder and not a <video>: see the note on .mlib-film in the
     * stylesheet above. 24 tiles x one metadata fetch each, per page view, on a
     * shared plan, to paint a frame the owner identifies by name anyway. The
     * playable preview is on the detail panel, where there is one of them.
     */
    var img = item.is_video
      ? '<div class="mlib-film">' + filmIcon() + '<b>' + esc(videoLabel(item)) + '</b></div>'
      : (item.url
        /* loading="lazy" and decoding="async" because a full page of 24 images
           is the whole point of the screen.

           (Lane IM2) AND `thumb` RATHER THAN `url`. This tile drew the
           catalogue ORIGINAL -- ~290KB apiece on this shop's sizes -- into a
           box whose width is `--mlib-cols`, so a page of 24 was about 7MB to
           paint 24 tiles. `thumb` is the 400w copy when one is on disk and IS
           `url` when there is not, so a library that has never been through
           Make phone-sized copies below draws exactly what it drew before.
           The detail panel, the copy-address control and the delete path all
           go on using `url`, which is still the file itself. */
        ? '<img src="' + esc(item.thumb || item.url) + '" alt="" loading="lazy" decoding="async">'
        : '<div class="mlib-noimg">no file</div>');

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
      + (item.seo_renamed ? '<span class="mlib-dim">' + esc(item.filename) + '</span>' : '')
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

    /* A select stores one of its own options or the default -- rule 5, applied
       on the way out as well as in the endpoint. An option this screen never
       rendered cannot be sent by using it, and the endpoint falls back to its
       own default for anything it does not recognise. */
    on('mlib-kind', 'change', function(e){
      var v = String(e.target.value);
      state.kind = (v === 'image' || v === 'video') ? v : 'all';
      state.page = 1;
      load();
    });

    on('mlib-from', 'change', function(e){ state.from = e.target.value; state.page = 1; load(); });
    on('mlib-to', 'change', function(e){ state.to = e.target.value; state.page = 1; load(); });

    on('mlib-clear', 'click', clear);
    on('mlib-clear2', 'click', clear);

    on('mlib-prev', 'click', function(){ if (state.page > 1) { state.page--; load(); } });
    on('mlib-next', 'click', function(){ if (data && state.page < data.pages) { state.page++; load(); } });

    document.querySelectorAll('#content .mlib-colpick button').forEach(function(b){
      b.addEventListener('click', function(){
        setCols(b.dataset.mlibCols === 'auto' ? 'auto' : parseInt(b.dataset.mlibCols, 10));
      });
    });

    // The stored choice has to be re-applied after every render, because the
    // grid element is new each time and carries no inline property of its own.
    applyCols();

    on('mlib-rescan', 'click', rescan);
    on('mlib-sizes', 'click', makeSizes);
    on('mlib-webp', 'click', function(){ if (window.kbbWebpOpen) window.kbbWebpOpen(); });

    on('mlib-more', 'click', function(){ extraOpen = !extraOpen; render(); });

    /* Namespaced to this screen. A bare data-open on these tiles fired another
       screen's delegated handler on this project once already. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-mlib-open]'), function(el){
      el.addEventListener('click', function(){ open(parseInt(el.getAttribute('data-mlib-open'), 10)); });
    });
  }

  function clear(){
    /* Back to 'all', which is this screen's resting state and not the
       endpoint's -- see the note on `state` at the top. Clearing the filters on
       the Media Library must show the owner everything he has, including his
       videos, or the button hides work rather than revealing it. */
    state = {page: 1, q: '', from: '', to: '', attached: '', attached_q: '', kind: 'all'};
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
      /*
       * HERE the video really is played, because here there is one of it and the
       * operator opened the panel on purpose. `preload="metadata"` and no
       * autoplay: enough to paint the first frame and show the duration, and
       * nothing that starts making noise in a back office. `controls` because
       * the reason to open this panel on a clip is to check it is the right one.
       */
      + (item.url
          ? (item.is_video
              ? '<video src="' + esc(item.url) + '" controls preload="metadata" playsinline></video>'
              : '<img src="' + esc(item.url) + '" alt="' + esc(item.alt) + '">')
          : '<span class="mlib-sub">no file</span>')
      + '</div>'
      + '<dl class="mlib-facts">'
      + fact('Dimensions', esc(dims(item)))
      + fact('File size', esc(bytes(item.size)))
      + fact('Type', esc(item.mime || '—'))
      + fact('Uploaded', esc(when(item.created_at)))
      + fact('Alt text', item.alt ? esc(item.alt) : '<span class="mlib-sub">none</span>')
      + (item.seo_ten !== null && item.seo_ten !== undefined
        ? fact('Image SEO', esc((item.seo_tick ? '✓ ' : '') + item.seo_ten + '/10' + (item.seo_renamed ? ' · renamed by Catalog → Image SEO' : ' · not renamed yet')))
        : '')
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
    /* Asked for once when the screen opens, so the tally is on the card
       before the owner has to click anything to find out there is work to do.
       Deliberately not awaited: it walks the catalogue, and the grid must not
       wait for it. */
    loadSizes();
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
