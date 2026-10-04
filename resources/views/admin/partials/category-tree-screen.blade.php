{{--
    Store - Catalog - Categories & Brands (Lane AQ).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go, toast() and the design tokens
    this screen borrows. Its own file rather than more lines inside a
    14,000-line Blade: several lanes edit that file at once, and a screen that
    lives on its own can be reviewed, reverted and merged on its own. The shape
    is deliberately the same as admin/partials/coupon-usage-screen.blade.php;
    that is the precedent.

    WHAT THIS SCREEN IS FOR. A parallel lane is giving a product many
    categories, which makes the category tree the owner's main merchandising
    tool. The existing Catalog - Categories tab can create, rename, re-parent
    and delete one category at a time, which is most of the job. What it cannot
    do is the part that makes the tree safe to actually use:

      - the product count beside a category DID NOT MATCH the category page.
        It counted every row in the pivot that was not soft-deleted, drafts and
        hidden products included, while the page lists Product::visible(). A
        category with one live product, one draft and one hidden one was
        labelled "3" beside a page listing 1.

      - renaming a slug silently broke an indexed URL, and because an unknown
        category path renders "Shop all" with a 200 rather than 404ing, it broke
        it invisibly. This screen names the URL, warns before it moves, and
        shows the redirects that were created.

      - there was no merge, so the only way to retire a category holding forty
        products was to force-delete it and empty an archive page.

      - brands had no reorder at all. brands.position has existed since the
        original schema and nothing has ever written it.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.

    The whole body is wrapped in one so that the {{ }} inside JavaScript
    template literals is not read as Blade.

    LANE QC: the Category header panel draws its live preview and its design
    tiles with window.kbbTH, from admin/partials/title-header-kit.blade.php --
    included HERE, once, for the whole console: app.blade.php includes this
    partial before site-layout-screen, which uses the same kit.
--}}
@include('admin.partials.title-header-kit')
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Categories & Brands. Every rule is prefixed ct- and appears nowhere else in
   the console, so this file can never restyle another screen by accident.

   THE LAYOUT RULE, and it is the one that has already cost this project a
   shipped bug. min-width:0 on every grid and flex child that can contain
   something wide. A grid or flex item's default min-width is `auto`, which
   means "at least as wide as my content" -- so a card refuses to shrink below
   the width of the table inside it, the scroller's overflow-x:auto never gets
   the chance to scroll, and the whole screen is stretched to the table's
   natural width. That is exactly what shipped on the Coupons screen this week.

   And the reason it is invisible in testing: this admin sets
   body{overflow-x:hidden} and scrolls inside #content, so
   document.documentElement.scrollWidth reads a comforting zero no matter how
   over-wide the screen is. #content is the element that has to be measured.
--------------------------------------------------------------------------- */
.ct-wrap{display:grid;gap:16px;min-width:0}
.ct-wrap > *{min-width:0}

.ct-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:16px;min-width:0}
.ct-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;min-width:0}
.ct-head > *{min-width:0}
.ct-title{font-weight:650;font-size:15px}
.ct-sub{color:var(--ink-soft,#6b7280);font-size:12.5px}

.ct-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.ct-tab{border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);
        border-radius:999px;padding:6px 13px;font:inherit;font-size:12.5px;cursor:pointer;
        color:var(--ink-soft,#6b7280)}
.ct-tab.on{background:var(--ink,#111);color:#fff;border-color:var(--ink,#111)}

.ct-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(140px,100%),1fr));gap:12px;min-width:0}
.ct-stats > *{min-width:0}
.ct-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:12px 14px;min-width:0}
.ct-stat b{display:block;font-size:20px;line-height:1.3;font-variant-numeric:tabular-nums}
.ct-stat span{color:var(--ink-soft,#6b7280);font-size:12px}

/* The tree. Rows are a grid, not a table, because a table cannot reflow to a
   stacked layout on a phone without losing its header association. */
.ct-tree{display:grid;gap:6px;min-width:0}
.ct-row{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;
        border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:9px 11px;
        background:var(--surface,#fff);min-width:0}
.ct-row > *{min-width:0}
.ct-row.is-drag{opacity:.45}
.ct-row.is-over{border-color:var(--ink,#111);box-shadow:0 0 0 2px var(--ink,#111) inset}
.ct-grip{cursor:grab;color:var(--ink-faint,#9ca3af);font-size:15px;line-height:1;
         padding:2px 4px;touch-action:none;user-select:none}
.ct-grip:active{cursor:grabbing}

.ct-main{display:flex;flex-wrap:wrap;gap:4px 9px;align-items:baseline;min-width:0}
.ct-main > *{min-width:0}
.ct-name{font-weight:600;font-size:13.5px;overflow-wrap:anywhere}
/* The URL is the longest thing on the row and the most likely to blow the
   layout out. It gets its own line on a phone and wraps anywhere. */
.ct-path{font-family:var(--mono,ui-monospace,monospace);font-size:11px;
         color:var(--ink-soft,#6b7280);overflow-wrap:anywhere;min-width:0}
.ct-counts{font-size:11.5px;color:var(--ink-soft,#6b7280);white-space:nowrap}
.ct-acts{display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end;min-width:0}
.ct-btn{border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);
        border-radius:8px;padding:5px 9px;font:inherit;font-size:11.5px;cursor:pointer;
        color:var(--ink-2,#374151)}
.ct-btn:hover{border-color:var(--ink,#111)}
.ct-btn.is-danger{color:var(--danger,#d6455a)}
.ct-btn.is-primary{background:var(--ink,#111);color:#fff;border-color:var(--ink,#111)}

.ct-pill{display:inline-block;border-radius:999px;padding:2px 8px;font-size:10.5px;
         background:var(--chip,#f3f4f6);color:var(--ink-soft,#6b7280);white-space:nowrap}
.ct-pill.is-warn{background:#fef3c7;color:#92400e}
.ct-pill.is-zero{background:#fee2e2;color:#991b1b}

.ct-empty{padding:22px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}

/* Forms. Every field is a block and every input is width:100% with
   min-width:0, so nothing in the editor can be wider than the modal. */
.ct-fld{display:block;margin-bottom:12px;min-width:0}
.ct-fld > label{display:block;font-size:12px;font-weight:600;margin-bottom:5px}
.ct-fld input,.ct-fld select,.ct-fld textarea{
  width:100%;min-width:0;box-sizing:border-box;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:var(--surface,#fff);
  color:inherit}
.ct-fld .ct-note{margin:5px 0 0;font-size:11.5px;color:var(--ink-soft,#6b7280)}
.ct-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:0 12px;min-width:0}
.ct-grid2 > *{min-width:0}

.ct-warn{border:1px solid #fcd34d;background:#fffbeb;color:#92400e;
         border-radius:10px;padding:10px 12px;font-size:12.5px;margin-bottom:12px;min-width:0;
         overflow-wrap:anywhere}
.ct-danger{border:1px solid #fca5a5;background:#fef2f2;color:#991b1b;
           border-radius:10px;padding:10px 12px;font-size:12.5px;margin-bottom:12px;min-width:0;
           overflow-wrap:anywhere}

.ct-thumb{width:46px;height:46px;border-radius:8px;object-fit:cover;
          border:1px solid var(--border,#e6e6e6);background:var(--chip,#f3f4f6);flex:0 0 auto}

.ct-modal{position:fixed;inset:0;background:rgba(0,0,0,.42);z-index:900;
          display:flex;align-items:flex-start;justify-content:center;padding:18px;overflow:auto}
.ct-modal-box{background:var(--surface,#fff);border-radius:14px;width:min(560px,100%);
              min-width:0;box-sizing:border-box;padding:18px;margin:auto}
.ct-modal-h{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:14px;min-width:0}
.ct-modal-h b{font-size:15px;min-width:0;overflow-wrap:anywhere}
.ct-link{border:0;background:none;padding:0;font:inherit;color:var(--accent,#15a85a);
         text-decoration:underline;cursor:pointer}
.ct-x{border:0;background:none;font-size:18px;line-height:1;cursor:pointer;color:var(--ink-soft,#6b7280);flex:0 0 auto}
.ct-modal-f{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-top:14px;min-width:0}

/* The redirects table is the one genuinely tabular thing here, so it keeps a
   table and gets its own scroller. Per the layout rule above, the scroller
   only works because every ancestor carries min-width:0. */
.ct-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0}
.ct-table{width:100%;border-collapse:collapse;font-size:12.5px}
.ct-table th,.ct-table td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--border,#e6e6e6);
                          vertical-align:top}
.ct-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft,#6b7280)}
.ct-table code{font-family:var(--mono,ui-monospace,monospace);font-size:11px;overflow-wrap:anywhere}

/* The category header panel in the editor (Lane PY; QC: preview, banner, tiles). */
.ct-hdr-live{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px;margin-bottom:12px;min-width:0;
  background:var(--chip,#f9fafb)}
.ct-hdr-live > b{font-size:12.5px}
.ct-banner-prev{margin-top:7px;border:1px solid var(--border,#e6e6e6);border-radius:9px;overflow:hidden;
  background:repeating-linear-gradient(45deg,#f3f4f6 0 10px,#fff 10px 20px);min-width:0}
.ct-banner-prev img{display:block;width:100%;height:auto;max-height:200px;object-fit:contain}
.ct-banner-acts{display:flex;flex-wrap:wrap;gap:7px;align-items:center;margin-top:8px;min-width:0}
.ct-banner-acts .ct-btn{padding:7px 11px;font-size:12px}
.ct-hdr-look{border-top:1px solid var(--border,#e6e6e6);padding-top:10px;margin:4px 0 12px;min-width:0}
.ct-hdr-cols{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));margin-top:10px;min-width:0}
.ct-hdr-cols > *{min-width:0}
.ct-colour{display:flex;gap:7px;align-items:center;min-width:0}
.ct-colour input[type=color]{width:40px;height:34px;padding:2px;flex:none}
.ct-colour input[type=text]{flex:1 1 auto}
.ct-hdr{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px;margin-bottom:12px;min-width:0}
.ct-hdr > summary{cursor:pointer;font-size:13px;min-width:0;overflow-wrap:anywhere}
.ct-hdr-body{margin-top:12px;min-width:0}
.ct-hdr .ct-note{font-size:11.5px;color:var(--ink-soft,#6b7280)}

@media (max-width:560px){
  /* The action buttons move under the name rather than competing with it for
     the row's width. Without this the three-column grid keeps a column for
     them at every width and the name is squeezed to nothing. */
  .ct-row{grid-template-columns:auto 1fr}
  .ct-acts{grid-column:1 / -1;justify-content:flex-start}
}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'category-tree';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var tab = 'categories';
  var cats = [];
  var brands = [];
  var redirects = null;
  var redirectPage = 1;
  var redirectQuery = '';
  var busy = false;
  var seq = 0;
  var modal = null;

  // Escape is bound once for the life of the screen, not once per dialog.
  var escBound = false;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function endpoint(path){
    return BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path;
  }

  /* One fetch wrapper, so every call site is a try/catch around one line
     rather than a status-code ladder. A 422 carries either Laravel's `errors`
     bag or the controller's own `message`; both are written for the operator,
     so both are surfaced. */
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
      var msg = (j && j.message) || '';
      if (j && j.errors) {
        msg = Object.keys(j.errors).map(function(k){ return j.errors[k][0]; }).join(' ');
      }
      var err = new Error(msg || ('Request failed (' + r.status + ')'));
      err.status = r.status;
      err.body = j;
      throw err;
    }

    return j;
  }

  /* Every operator-supplied string -- a category name, a slug, a path, an
     error message that quotes one back -- goes through this before it reaches
     innerHTML. */
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* ------------------------------------------------------------ tree maths */
  function kidsOf(parentId){
    return cats.filter(function(c){
      var p = c.parent_id == null ? null : Number(c.parent_id);
      return p === parentId;
    });
  }

  function treeRows(parentId, depth, out){
    kidsOf(parentId).forEach(function(c){
      out.push({cat: c, depth: depth});
      treeRows(Number(c.id), depth + 1, out);
    });
    return out;
  }

  /* Rows the tree walk never reached: a category whose parent_id points at a
     row that is not in the list. It cannot happen through this screen, but an
     import can leave one, and silently not drawing a category the owner can
     see in the database is exactly the "looks like it works" this screen
     exists to stop. */
  function allRows(){
    var rows = treeRows(null, 0, []);
    var drawn = {};
    rows.forEach(function(r){ drawn[r.cat.id] = 1; });
    cats.forEach(function(c){
      if (!drawn[c.id]) rows.push({cat: c, depth: 0, orphan: true});
    });
    return rows;
  }

  function subtreeIds(id){
    var ids = [id];
    kidsOf(id).forEach(function(k){ ids = ids.concat(subtreeIds(Number(k.id))); });
    return ids;
  }

  function catById(id){
    return cats.filter(function(c){ return Number(c.id) === Number(id); })[0];
  }

  /* The empty shape of the Arabic boxes, for the Add-category form. A category
     being created has no translations but still has to draw a box for every
     translatable field. Asked of the server rather than listed here, so
     Category::$translatable stays the one place the answer lives. */
  var ARABIC_SHAPE = null;

  /**
   * T4b — the Arabic counterpart of one category field.
   *
   * The maxlength mirrors the English control's own: the Arabic box must not
   * accept what the English box would refuse. CategoriesApiController enforces
   * the same bound server-side, derived from that same English rule rather
   * than restated, so the two cannot drift apart.
   */
  function arabicBox(cat, field, label, fromSelector, maxlength, type){
    /* The shared helper is included by resources/views/admin/app.blade.php. A
       build with this screen and not the helper draws no Arabic boxes rather
       than throwing and taking the categories screen down with it. */
    if (!window.KBBArabic) return '';

    var shape = (cat && cat.translations) || ARABIC_SHAPE;

    if (!shape || !shape[KBBArabic.locale]
        || !Object.prototype.hasOwnProperty.call(shape[KBBArabic.locale], field)) {
      return '';
    }

    return KBBArabic.box({
      field: field,
      label: label,
      prefill: (cat && cat.translations) || null,
      type: type || 'text',
      maxlength: maxlength,
      rows: 3,
      from: fromSelector
    });
  }

  /* ----------------------------------------------------------------- data */
  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      if (tab === 'categories') {
        var d = await api('/categories');
        if (mine !== seq) return;
        cats = (d && d.categories) || [];
        ARABIC_SHAPE = (d && d.translatable) || ARABIC_SHAPE;
      } else if (tab === 'brands') {
        var b = await api('/brands-tree');
        if (mine !== seq) return;
        brands = (b && b.brands) || [];
      } else {
        var q = '/categories/redirects?page=' + redirectPage
              + (redirectQuery ? '&q=' + encodeURIComponent(redirectQuery) : '');
        var rd = await api(q);
        if (mine !== seq) return;
        redirects = rd;
      }
    } catch (e) {
      if (mine === seq) say(e.message);
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  /* ---------------------------------------------------------------- views */
  function countPill(c){
    var live = Number(c.products_count || 0);
    var filed = Number(c.filed_count || 0);
    var hidden = Math.max(0, filed - live);

    // The headline is what the page shows. The difference is named rather
    // than hidden, because "0 products" on a category the owner just filled
    // with drafts reads as a bug in the screen.
    var cls = live === 0 ? ' is-zero' : '';
    var out = '<span class="ct-pill' + cls + '">' + esc(live) + ' on the page</span>';

    if (hidden > 0) {
      out += ' <span class="ct-pill is-warn">' + esc(hidden) + ' not published</span>';
    }

    return out;
  }

  function categoriesView(){
    var rows = allRows();
    var live = cats.reduce(function(n, c){ return n + Number(c.products_count || 0); }, 0);

    var head = '<div class="ct-stats">'
      + '<div class="ct-stat"><b>' + esc(cats.length) + '</b><span>Categories</span></div>'
      + '<div class="ct-stat"><b>' + esc(live) + '</b><span>Published placements</span></div>'
      + '<div class="ct-stat"><b>' + esc(cats.filter(function(c){ return Number(c.products_count||0) === 0; }).length)
      + '</b><span>Empty on the storefront</span></div>'
      /* Lane PY: how many categories show a header title other than their
         name -- the old shop's, or one typed here. */
      + '<div class="ct-stat" data-hdr-count><b>' + esc(cats.filter(function(c){ return !!c.header_title; }).length)
      + '</b><span>With their own header title</span></div>'
      + '</div>';

    var body;

    if (busy && !cats.length) {
      body = '<div class="ct-empty">Loading categories…</div>';
    } else if (!rows.length) {
      body = '<div class="ct-empty">No categories yet — add the first one.</div>';
    } else {
      body = '<div class="ct-tree">' + rows.map(function(r){
        var c = r.cat;
        var pad = Math.min(r.depth, 6) * 16;

        return '<div class="ct-row" draggable="true" data-id="' + esc(c.id) + '">'
          + '<span class="ct-grip" title="Drag to reorder within this level">⋮⋮</span>'
          + '<div class="ct-main" style="padding-left:' + pad + 'px">'
            + '<span class="ct-name">' + esc(c.name) + '</span>'
            + countPill(c)
            + (r.orphan ? ' <span class="ct-pill is-warn">parent missing</span>' : '')
            + (Number(c.children_count || 0) ? ' <span class="ct-pill">' + esc(c.children_count) + ' sub</span>' : '')
            /* Lane PY: what this category's header carries, so an imported
               title is visible from the list and not only inside the dialog. */
            + (c.header_title ? ' <span class="ct-pill' + (c.header_source ? ' is-warn' : '') + '" data-hdr-title>'
                + (c.header_source ? 'imported header title' : 'own header title') + '</span>' : '')
            + (c.header_image ? ' <span class="ct-pill">header picture</span>' : '')
            + '<span class="ct-path">/collections/' + esc(c.path || c.slug) + '/</span>'
          + '</div>'
          + '<div class="ct-acts">'
            + '<button class="ct-btn" data-edit="' + esc(c.id) + '">Edit</button>'
            + '<button class="ct-btn" data-merge="' + esc(c.id) + '">Merge</button>'
            + '<button class="ct-btn is-danger" data-del="' + esc(c.id) + '">Delete</button>'
          + '</div>'
        + '</div>';
      }).join('') + '</div>';
    }

    return head
      + '<div class="ct-card">'
      + '<div class="ct-head"><div style="min-width:0">'
        + '<div class="ct-title">Category tree</div>'
        + '<div class="ct-sub">Drag a row by its handle to reorder it among its own siblings. '
        + 'The count is what the category page actually lists.</div></div>'
        + '<button class="ct-btn is-primary" id="ct-add">+ Add category</button>'
      + '</div>'
      + '<div style="margin-top:13px;min-width:0">' + body + '</div>'
      + '</div>';
  }

  function brandsView(){
    var body;

    if (busy && !brands.length) {
      body = '<div class="ct-empty">Loading brands…</div>';
    } else if (!brands.length) {
      body = '<div class="ct-empty">No brands yet.</div>';
    } else {
      body = '<div class="ct-tree">' + brands.map(function(b){
        return '<div class="ct-row" draggable="true" data-bid="' + esc(b.id) + '">'
          + '<span class="ct-grip" title="Drag to reorder">⋮⋮</span>'
          + '<div class="ct-main">'
            + '<span class="ct-name">' + esc(b.name) + '</span>'
            + countPill(b)
            + '<span class="ct-path">/shop/?filter_brands=' + esc(b.slug) + '</span>'
          + '</div>'
          + '<div class="ct-acts"></div>'
        + '</div>';
      }).join('') + '</div>';
    }

    return '<div class="ct-card">'
      + '<div class="ct-head"><div style="min-width:0">'
      + '<div class="ct-title">Brand order</div>'
      + '<div class="ct-sub">Drag to set the order they appear in the shop filter. '
      + 'Adding, renaming, banners and deleting are on the <button type="button" class="ct-link" id="ct-tobrands">Brands</button> screen. '
      + 'Brand pages are /shop/?filter_brands=… — a query parameter, not a path (URL contract U-05).</div>'
      + '</div></div>'
      + '<div style="margin-top:13px;min-width:0">' + body + '</div>'
      + '</div>';
  }

  function redirectsView(){
    var rows = (redirects && redirects.redirects) || [];
    var body;

    if (busy && !redirects) {
      body = '<div class="ct-empty">Loading…</div>';
    } else if (!rows.length) {
      body = '<div class="ct-empty">' + (redirectQuery
        ? 'No moved URL matches that search.'
        : 'No category URL has moved yet. Renaming a slug or moving a category will add one here.') + '</div>';
    } else {
      body = '<div class="ct-scroll"><table class="ct-table"><thead><tr>'
        + '<th>Old URL</th><th>Now points at</th><th>Why</th><th></th>'
        + '</tr></thead><tbody>'
        + rows.map(function(r){
            return '<tr>'
              + '<td><code>' + esc(r.from) + '</code></td>'
              + '<td>' + (r.to
                  ? esc(r.target_name || '') + '<br><code>' + esc(r.to) + '</code>'
                  : '<span class="ct-pill is-zero">nothing — this URL 404s</span>') + '</td>'
              + '<td>' + esc(r.reason) + '</td>'
              + '<td><button class="ct-btn is-danger" data-rdel="' + esc(r.id) + '">Remove</button></td>'
              + '</tr>';
          }).join('')
        + '</tbody></table></div>';
    }

    var pager = '';
    if (redirects && redirects.last_page > 1) {
      pager = '<div class="ct-head" style="margin-top:12px">'
        + '<span class="ct-sub">Page ' + esc(redirects.page) + ' of ' + esc(redirects.last_page)
        + ' — ' + esc(redirects.total) + ' in total</span>'
        + '<span class="ct-acts">'
        + '<button class="ct-btn" id="ct-rprev"' + (redirects.page <= 1 ? ' disabled' : '') + '>Previous</button>'
        + '<button class="ct-btn" id="ct-rnext"' + (redirects.page >= redirects.last_page ? ' disabled' : '') + '>Next</button>'
        + '</span></div>';
    }

    return '<div class="ct-card">'
      + '<div class="ct-head"><div style="min-width:0">'
      + '<div class="ct-title">URLs that moved</div>'
      + '<div class="ct-sub">Every rename, move, merge and delete leaves the old address working as a redirect. '
      + 'Remove one to make that address 404 again.</div></div></div>'
      + '<div class="ct-fld" style="margin:12px 0 0">'
      + '<input id="ct-rq" type="search" placeholder="Search an old path or a category name" value="' + esc(redirectQuery) + '" autocomplete="off">'
      + '</div>'
      + '<div style="margin-top:12px;min-width:0">' + body + '</div>'
      + pager
      + '</div>';
  }

  function render(){
    var host = document.getElementById('ct-root');
    if (!host) return;

    /* The owner asked, of the Catalog screen, what a row of tab names is for
       and why the tabs are one screen. It is the same question here and it
       deserves the same answer on the page rather than in a lane report, so
       the strip carries a caption. `ectabs-hint` is the console's existing
       class for exactly this, styled in app.blade.php, so this reads like the
       other explained tab strips instead of inventing a second look. */
    var tabs = '<div class="ct-tabs">'
      + [['categories','Categories'],['brands','Brands'],['redirects','URLs that moved']].map(function(t){
          return '<button class="ct-tab' + (tab === t[0] ? ' on' : '') + '" data-tab="' + t[0] + '">'
               + esc(t[1]) + '</button>';
        }).join('')
      + '</div>'
      + '<p class="ectabs-hint">Three ways into the same catalogue structure, which is why they are one screen rather than three. '
      + '<b>Categories</b> is the tree shoppers browse and the order they see it in; <b>Brands</b> is the other way into the same '
      + 'products; <b>URLs that moved</b> is every old WooCommerce address that now needs somewhere to land \u2014 renaming or '
      + 'merging a category on the first tab is what puts a row on the third one.</p>';

    var view = tab === 'categories' ? categoriesView()
             : tab === 'brands' ? brandsView()
             : redirectsView();

    host.innerHTML = '<div class="ct-wrap">' + tabs + view + '</div>';
    wire();
  }

  /* ------------------------------------------------------------- dragging */
  /* Reorder is per sibling group: only the dragged row's own siblings are
     sent, so a move never renumbers a branch the operator is not looking at.
     A drop onto a row in a different group is ignored rather than guessed at
     -- re-parenting is what the Parent field in the editor is for, and doing
     it by accident with a drag would move an indexed URL. */
  var dragId = null;

  function siblingsOf(id){
    if (tab === 'brands') return brands.map(function(b){ return Number(b.id); });
    var c = catById(id);
    if (!c) return [];
    return kidsOf(c.parent_id == null ? null : Number(c.parent_id))
      .map(function(s){ return Number(s.id); });
  }

  async function commitOrder(order){
    try {
      await api(tab === 'brands' ? '/brands-tree/reorder' : '/categories/reorder', 'POST', {order: order});
      say('Order saved');
      await load();
    } catch (e) {
      say(e.message);
      await load();
    }
  }

  function wireDrag(){
    var rows = document.querySelectorAll('#ct-root .ct-row');

    rows.forEach(function(row){
      var idAttr = tab === 'brands' ? 'bid' : 'id';
      var id = Number(row.dataset[idAttr]);
      if (!id) return;

      row.addEventListener('dragstart', function(ev){
        dragId = id;
        row.classList.add('is-drag');
        try { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', String(id)); } catch (e) {}
      });

      row.addEventListener('dragend', function(){
        dragId = null;
        rows.forEach(function(r){ r.classList.remove('is-drag','is-over'); });
      });

      row.addEventListener('dragover', function(ev){
        if (dragId == null || dragId === id) return;
        if (siblingsOf(dragId).indexOf(id) === -1) return;  // different level
        ev.preventDefault();
        row.classList.add('is-over');
      });

      row.addEventListener('dragleave', function(){ row.classList.remove('is-over'); });

      row.addEventListener('drop', function(ev){
        row.classList.remove('is-over');
        if (dragId == null || dragId === id) return;

        var sibs = siblingsOf(dragId);
        var from = sibs.indexOf(dragId);
        var to = sibs.indexOf(id);
        if (from === -1 || to === -1) return;

        ev.preventDefault();
        sibs.splice(to, 0, sibs.splice(from, 1)[0]);
        commitOrder(sibs);
      });
    });
  }

  /* ---------------------------------------------------------------- modal */
  function openModal(html){
    closeModal();
    modal = document.createElement('div');
    modal.className = 'ct-modal';
    modal.innerHTML = '<div class="ct-modal-box">' + html + '</div>';
    modal.addEventListener('click', function(ev){ if (ev.target === modal) closeModal(); });

    /*
     * WIRE THE CLOSE BUTTONS HERE, not in the callers.
     *
     * Every dialog on this screen puts `<button class="ct-x" data-close>` in
     * its header, and wiring them was left to whoever opened the dialog. The
     * edit path called wireModal() afterwards; the ADD path did not, so the X
     * on "Add category" did nothing at all and the only way out was Cancel or
     * the backdrop. Same for any dialog added later by someone who did not
     * know the rule.
     *
     * Doing it where the markup is inserted means a caller cannot forget. The
     * Escape key is wired for the same reason: a modal you cannot dismiss with
     * Escape is a trap for anyone not using a mouse.
     */
    modal.querySelectorAll('[data-close]').forEach(function(b){ b.onclick = closeModal; });

    if (!escBound) {
        document.addEventListener('keydown', function(ev){
            if (ev.key === 'Escape' && modal) closeModal();
        });
        escBound = true;
    }

    document.body.appendChild(modal);
    return modal;
  }

  function closeModal(){
    if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
    modal = null;
    /* Lane RL: the dialog is the record; closing it frees the edit lock. */
    if (window.kbbPresence) window.kbbPresence.close('category');
  }

  function val(id){
    var el = document.getElementById(id);
    return el ? String(el.value || '').trim() : '';
  }

  /* --------------------------------------------- the category header (PY) */
  /*
   * "i can be able to update that background iamge, title font size etc and
   * description etc for each category." -- the owner, Lane PY.
   *
   * One panel in this dialog, saved by this dialog's button in the same
   * request as everything else. EVERY BOX BLANK MEANS "FOLLOW THE SHOP"
   * (Appearance -> Site layout -> Category header): a blank title is the
   * category's name, a blank description is the Description above, a blank
   * size is the shop's size, "Use the shop setting" is the shop's choice.
   *
   * The custom title is ALSO WHERE AN IMPORTED OLD-SHOP TITLE LANDS. It is
   * shown here, marked as imported, and "Use the category name" empties it --
   * which is how the owner undoes something he did not type.
   *
   * The design choices are tiles drawn by window.kbbTH (title-header-kit),
   * whose lists are the server's own (App\Services\SiteLayout::TREATMENTS
   * and BOX_STYLES, App\Support\TitleHeader::ALIGNS) -- Lane QC. The
   * numbers' bounds below are the matching sliders' -- PyCategoryHeaderOptions-
   * Test reads both files and requires them to agree. The server is the judge
   * regardless: it keeps a choice only when it is on its list and clamps a
   * number into its range.
   */
  /* [box id, style key, label, min, max] -- the bounds of the matching slider. */
  var HDR_NUMBERS = [
    ['ct-hdrtsp', 'title_phone', 'Title size · phone (px)', 16, 56],
    ['ct-hdrtsd', 'title_desktop', 'Title size · laptop (px)', 18, 80],
    ['ct-hdrhp', 'h_phone', 'Height · phone (px)', 80, 480],
    ['ct-hdrhd', 'h_desktop', 'Height · laptop (px)', 100, 640]
  ];

  /*
   * ── LANE QC: PER DEVICE, AS PICTURES, WITH A LIVE PREVIEW ─────────────────
   *
   * "i need the same designs on backend to choose the category banner
   * designs, text style etc and for mobile also. i will set and upload the
   * banners manually" -- and -- "along with previews of designs to choose, and
   * also along with LIVE preview."
   *
   * So the panel opens with a live preview of THIS category -- its banner, its
   * title, its description, with its own choices over the shop's -- redrawn
   * as he uploads a banner, types, or clicks a design. Then the banner itself:
   * an Upload banner button (the shared Media Library, opened straight onto
   * the file chooser, so the file lands in the library like every picture),
   * the library, Remove, a preview of the picture and the size to make it.
   * Then the designs, as tiles, for the phone and for the laptop, each with
   * "Shop setting" first.
   *
   * WHY TILES HERE AND NOT SELECTS. The dialog is 318px of content on a 390px
   * phone. A select with a swatch can say "B · Cream icons" but cannot show a
   * frosted panel or a dark fade, which are the whole point of choosing; a
   * compact tile -- three to a row, one small header each, drawn by the shop's
   * stylesheet over this category's own banner when it has one -- can. Seven
   * tiles (Shop + A-F) are three rows of about 75px.
   *
   * THE STORED SHAPE. `align_phone` / `align_desktop` (and treatment, box,
   * text the same way), `focus`, `bg`, `ic`, beside PY's numbers. PY's
   * both-devices `align` / `treatment` / `box` are read as both devices'
   * values when the dialog opens and sent back as nulls, so a category saved
   * before this keeps its look exactly and is rewritten in the new shape on
   * its next save. TitleHeader::sanitizeStyle() is the judge either way.
   */
  var HDR_GROUPS = { 'ct-box': 'box', 'ct-treat': 'treatment', 'ct-align': 'align', 'ct-text': 'text', 'ct-valign': 'valign' };
  var hdr = { dev: 'phone', st: {}, shop: null, shopBusy: false };

  /* PY's both-devices keys, as both devices' values. */
  function hdrOpenStyle(raw){
    var st = {};
    var src = (raw && typeof raw === 'object') ? raw : {};
    Object.keys(src).forEach(function(k){ st[k] = src[k]; });
    ['align', 'treatment', 'box'].forEach(function(c){
      if (st[c] != null && st[c] !== '') {
        if (st[c + '_phone'] == null) st[c + '_phone'] = st[c];
        if (st[c + '_desktop'] == null) st[c + '_desktop'] = st[c];
      }
      delete st[c];
    });
    return st;
  }

  function hdrShop(){ return hdr.shop || (window.kbbTH ? window.kbbTH.DEFAULTS : {}); }

  async function hdrLoadShop(){
    if (hdr.shop || hdr.shopBusy || !window.kbbTH) return;
    hdr.shopBusy = true;
    try {
      var body = await api('/site-layout');
      hdr.shop = window.kbbTH.flatten(body && body.tabs);
    } catch (e) {
      hdr.shop = null;   // no Site layout rights: the shipped defaults stand in
    } finally {
      hdr.shopBusy = false;
      hdrPaint();
    }
  }

  function headerPanel(cat){
    hdr.st = hdrOpenStyle(cat.header_style);
    hdr.dev = 'phone';
    var st = hdr.st;

    var imported = cat.header_source
      ? '<p class="ct-note" data-hdr-imported>Brought across from the old shop'
        + (cat.header_title ? ', with this title' : '') + '. Change it or clear it here.</p>'
      : '';

    var colour = function(id, label, v){
      return '<div class="ct-fld"><label for="' + id + '">' + esc(label) + '</label>'
        + '<div class="ct-colour"><input type="color" data-ct-hdrpair="' + id + '" value="' + esc(/^#[0-9A-Fa-f]{6}$/.test(v || '') ? v : '#ffffff') + '" aria-label="' + esc(label) + '">'
        + '<input id="' + id + '" maxlength="7" value="' + esc(v || '') + '" placeholder="The style’s own" autocomplete="off" spellcheck="false"></div></div>';
    };

    return '<details class="ct-hdr" id="ct-hdr" open>'
      + '<summary><b>Category header</b> <span class="ct-note" style="display:inline">'
      + 'banner, title, description and look on this category’s page</span></summary>'
      + '<div class="ct-hdr-body">'
      + '<p class="ct-note" style="margin:0 0 10px">Anything left blank or on <b>Shop setting</b> follows '
      + '<b>Appearance → Site layout → Category header</b>.</p>'

      /* 1. The live preview of THIS category. */
      + '<div class="ct-hdr-live" data-ct-hdrlive></div>'

      /* 2. The banner, first of the controls. */
      + '<div class="ct-fld"><label for="ct-hdrimg">Header picture (banner)</label>'
        + '<div class="ct-banner-prev" id="ct-hdrwrap"' + (cat.header_image ? '' : ' hidden') + '>'
        + '<img id="ct-hdrthumb" alt="The banner as uploaded"' + (cat.header_image ? ' src="' + esc(cat.header_image) + '"' : '') + '></div>'
        + '<div class="ct-banner-acts">'
        + '<button type="button" class="ct-btn is-primary" id="ct-hdrup">Upload banner</button>'
        + '<button type="button" class="ct-btn" id="ct-hdrlib">Choose from Media Library</button>'
        + '<button type="button" class="ct-btn" id="ct-hdrdrop">Remove picture</button>'
        + '</div>'
        + '<p class="ct-note" id="ct-hdrupstate" aria-live="polite"></p>'
        + '<p class="ct-note" data-hdr-size><b>Make it 2400 × 600 px</b> (4 : 1), JPG or WebP. '
        + 'The header is 1236 × 300 on a 1280px laptop and up to 1636 × 300 on a wide screen, so 2400 wide stays sharp on a high-density laptop. '
        + 'It is cropped to fill, never stretched: on a laptop almost all of it shows; on a 390px phone the header is 346 × 190, '
        + 'so only the <b>middle 45% of its width</b> shows -- keep what matters there, or set <b>Phone crop</b> below. '
        + 'With Start alignment the words sit on the left: keep that side calm.</p>'
        + '<label for="ct-hdrimg" class="ct-note" style="display:block;margin-top:7px">Picture address</label>'
        + '<input id="ct-hdrimg" value="' + esc(cat.header_image || '') + '" placeholder="No picture: the light box shows instead">'
        + '<div data-ct-hdrfocus></div>'
      + '</div>'

      + '<div class="ct-fld"><label for="ct-hdrtitle">Title</label>'
        + '<input id="ct-hdrtitle" maxlength="300" value="' + esc(cat.header_title || '') + '"'
        + ' placeholder="Blank: the category name (' + esc(cat.name || '') + ')">'
        + imported
        + '<p class="ct-note">Blank uses the category name. '
        + '<button type="button" class="ct-link" id="ct-hdrtitleclear">Use the category name</button></p>'
      + '</div>'

      + '<div class="ct-fld"><label for="ct-hdrsub">Line under the title</label>'
        + '<input id="ct-hdrsub" maxlength="300" value="' + esc(cat.header_subtitle || '') + '" placeholder="Optional">'
      + '</div>'

      + '<div class="ct-fld"><label for="ct-hdrdesc">Description in the header</label>'
        + '<textarea id="ct-hdrdesc" rows="3" maxlength="5000" placeholder="Blank: the Description above">'
        + esc(cat.header_description || '') + '</textarea>'
        + '<p class="ct-note">English pages. The Arabic page shows the category’s Arabic name and description.</p>'
      + '</div>'

      /* 3. The designs, per device. */
      + '<div class="ct-hdr-look"><b style="font-size:13px">The design</b>'
        + '<p class="ct-note" style="margin:3px 0 8px">Phone and laptop separately. <b>Shop setting</b> follows the shop.</p>'
        + '<div data-ct-hdrdevbar></div>'
        + '<div data-ct-hdrlook></div>'
        + '<div class="ct-hdr-cols">'
          + colour('ct-hdrbg', 'This category’s box colour', st.bg)
          + colour('ct-hdric', 'This category’s icon colour', st.ic)
        + '</div>'
        + '<p class="ct-note" style="margin-top:-4px">For the light box (no banner), on both devices. Blank keeps the chosen style’s own colours. '
        + '<button type="button" class="ct-link" id="ct-hdrcolclear">Use the style’s own colours</button></p>'
      + '</div>'

      + '<div class="ct-grid2">'
        + HDR_NUMBERS.map(function(n){
            var v = st[n[1]];
            return '<div class="ct-fld"><label for="' + n[0] + '">' + esc(n[2]) + '</label>'
              + '<input id="' + n[0] + '" type="number" inputmode="numeric" min="' + n[3] + '" max="' + n[4] + '"'
              + ' value="' + esc(v == null ? '' : v) + '" placeholder="Shop setting"></div>';
          }).join('')
      + '</div>'
      + '</div></details>';
  }

  /* The category's look as the preview needs it: the chosen tiles, the two
     colours and the size boxes as they stand, unsaved. */
  function hdrOwn(){
    var own = {};
    Object.keys(hdr.st).forEach(function(k){ own[k] = hdr.st[k]; });
    own.bg = val('ct-hdrbg') || null;
    own.ic = val('ct-hdric') || null;
    HDR_NUMBERS.forEach(function(n){
      var raw = val(n[0]);
      own[n[1]] = /^\d+$/.test(raw) ? Math.max(n[3], Math.min(n[4], parseInt(raw, 10))) : null;
    });
    return own;
  }

  function hdrImage(){
    var th = window.kbbTH;
    var shop = hdrShop();
    return th.safeImage(val('ct-hdrimg')) || (th.on(shop.cat_header_fallback) ? th.safeImage(val('ct-image')) : '');
  }

  function hdrLiveHTML(){
    var th = window.kbbTH;
    var shop = hdrShop();
    var image = hdrImage();
    var title = val('ct-hdrtitle') || val('ct-name') || 'Category';
    var desc = val('ct-hdrdesc') || th.plain(val('ct-desc'));
    var off = !th.on(shop.cat_header) ? 'The header is switched off for the whole shop (Appearance → Site layout → Category header): this page shows its plain title.'
      : (!image && !th.on(shop.cat_header_box) ? 'No banner, and the light box is switched off for the shop: this page shows its plain title. This is how it would look.' : '');

    return '<b>Live preview · ' + (hdr.dev === 'laptop' ? 'Laptop' : 'Phone') + '</b>'
      + '<div style="margin-top:7px">' + th.deviceSwitch('data-ct-hdrdev', hdr.dev, 'Preview and edit for') + '</div>'
      + th.frame(hdr.dev, th.header({ values: shop, own: hdrOwn(), kind: image ? 'img' : 'box', image: image, generic: true,
          dev: hdr.dev, title: title, sub: val('ct-hdrsub'), desc: desc, long: desc.length > 180 }))
      + '<p class="thk-livecap">' + (hdr.dev === 'laptop'
          ? 'Laptop: as a 1280px screen draws it (1236px wide), scaled to fit.'
          : 'Phone: as a 390px phone draws it (346px wide).')
        + (image ? ' This category’s banner.' : ' No banner: the light box.')
        + (desc ? '' : ' No description, so the shop’s line for a category with none shows.')
        + (hdr.shop ? '' : ' (The shop’s own settings could not be read, so the shipped ones stand in.)')
        + (off ? ' ' + esc(off) : '') + '</p>';
  }

  function hdrLookHTML(){
    var th = window.kbbTH;
    var shop = hdrShop();
    var dev = hdr.dev === 'laptop' ? 'laptop' : 'phone';
    var sfx = dev === 'laptop' ? '_desktop' : '_phone';
    var image = hdrImage();
    var own = hdrOwn();
    var shopR = th.resolve(shop, {}, image ? 'img' : 'box', dev);
    var shopBox = th.resolve(shop, {}, 'box', dev);
    var ctx = { own: own, image: image, title: val('ct-hdrtitle') || val('ct-name') || '' };
    var word = dev === 'laptop' ? 'laptop' : 'phone';
    var says = function(n){ return n ? n[0] + (n[1] && n[0].length < 3 ? ' · ' + n[1] : '') : ''; };

    return th.tiles({ group: 'ct-box', kind: 'box', compact: true, shop: true, label: 'Light box style · ' + word + ' (when there is no banner)',
        shopSays: says(th.BOX_NAMES[shopBox.box]), value: st0(own, 'box' + sfx), values: shop, dev: dev, ctx: ctx })
      + th.tiles({ group: 'ct-treat', kind: image ? 'treat-img' : 'treat-box', compact: true, shop: true,
        label: 'Keep the words readable · ' + word, shopSays: says(th.TREATMENT_NAMES[shopR.treatment]),
        value: st0(own, 'treatment' + sfx), values: shop, dev: dev, ctx: ctx })
      + th.tiles({ group: 'ct-valign', kind: 'valign', compact: true, shop: true, label: 'Where the words sit · ' + word,
        shopSays: (th.VALIGN_NAMES[shopR.valign] || [''])[0], value: st0(own, 'valign' + sfx), values: shop, dev: dev, ctx: ctx })
      + th.tiles({ group: 'ct-align', kind: 'align', compact: true, shop: true, label: 'Text alignment · ' + word,
        hint: 'Start is right-aligned on Arabic pages.', shopSays: (th.ALIGN_NAMES[shopR.align] || [''])[0],
        value: st0(own, 'align' + sfx), values: shop, dev: dev, ctx: ctx })
      + th.tiles({ group: 'ct-text', kind: 'text', compact: true, shop: true, label: 'Text colour · ' + word,
        shopSays: (th.TEXT_NAMES[shop['cat_header_text' + (dev === 'laptop' ? '_desktop' : '')] || 'auto'] || [''])[0],
        value: st0(own, 'text' + sfx), values: shop, dev: dev, ctx: ctx });
  }

  function st0(own, k){ return own[k] == null ? '' : String(own[k]); }

  function hdrFocusHTML(){
    var th = window.kbbTH;
    var image = hdrImage();
    if (!image) return '';
    return th.tiles({ group: 'ct-focus', kind: 'focus', compact: true, label: 'Phone crop -- which part of the banner a phone keeps',
      hint: 'A phone shows about the middle half of a wide banner. Laptops show nearly all of it whatever this says.',
      value: hdr.st.focus || 'center', values: hdrShop(), dev: 'phone',
      ctx: { own: hdrOwn(), image: image, title: val('ct-hdrtitle') || val('ct-name') || '' } });
  }

  /* Redraw the preview and the tiles. Nothing here is a control being held. */
  function hdrPaint(){
    if (!window.kbbTH || !document.getElementById('ct-hdr')) return;
    var live = document.querySelector('[data-ct-hdrlive]');
    if (live) live.innerHTML = hdrLiveHTML();
    var look = document.querySelector('[data-ct-hdrlook]');
    if (look) look.innerHTML = hdrLookHTML();
    var fx = document.querySelector('[data-ct-hdrfocus]');
    if (fx) fx.innerHTML = hdrFocusHTML();
    var dv = document.querySelector('[data-ct-hdrdevbar]');
    if (dv) dv.innerHTML = window.kbbTH.deviceSwitch('data-ct-hdrdev', hdr.dev, 'Edit the design for');
  }

  /* The panel's values for the save request. A number box that holds
     something other than digits is sent as typed, so the server refuses it
     with its own message rather than this screen guessing what was meant. */
  function headerPayload(){
    var st = hdr.st;
    var style = {
      // PY's both-devices keys, retired on this save (read above as both devices').
      align: null, treatment: null, box: null,
      align_phone: st.align_phone || null, align_desktop: st.align_desktop || null,
      treatment_phone: st.treatment_phone || null, treatment_desktop: st.treatment_desktop || null,
      box_phone: st.box_phone || null, box_desktop: st.box_desktop || null,
      text_phone: st.text_phone || null, text_desktop: st.text_desktop || null,
      valign_phone: st.valign_phone || null, valign_desktop: st.valign_desktop || null,
      focus: (st.focus && st.focus !== 'center') ? st.focus : null,
      bg: val('ct-hdrbg') || null,
      ic: val('ct-hdric') || null
    };
    HDR_NUMBERS.forEach(function(n){
      var raw = val(n[0]);
      style[n[1]] = raw === '' ? null : (/^\d+$/.test(raw) ? parseInt(raw, 10) : raw);
    });
    return {
      header_image: val('ct-hdrimg'),
      header_title: val('ct-hdrtitle'),
      header_subtitle: val('ct-hdrsub'),
      header_description: val('ct-hdrdesc'),
      header_style: style
    };
  }

  function wireHeaderPanel(){
    var state = document.getElementById('ct-hdrupstate');

    function applyHeaderImage(url){
      var box = document.getElementById('ct-hdrimg');
      var thumb = document.getElementById('ct-hdrthumb');
      var wrap = document.getElementById('ct-hdrwrap');
      if (box) box.value = url || '';
      var safe = window.kbbTH ? window.kbbTH.safeImage(url) : '';
      if (thumb) {
        if (safe) thumb.src = safe; else thumb.removeAttribute('src');
      }
      if (wrap) wrap.hidden = !safe;
      hdrPaint();
    }

    /* UPLOAD BANNER. Not a second upload path: the shared Media Library,
       opened straight onto the computer's file chooser (`upload`) and taking
       the uploaded picture as the choice (`pickUploaded`). The file lands in
       the library first, like every picture in this console. */
    var up = document.getElementById('ct-hdrup');
    if (up) up.onclick = function(e){
      e.preventDefault();
      if (typeof window.kbbPickMedia !== 'function') { say('The Media Library is not available.'); return; }
      window.kbbPickMedia({
        title: 'Upload a banner',
        note: 'Best at 2400 × 600. It joins the Media Library, and is used here as soon as it is up.',
        folder: 'categories',
        upload: true,
        pickUploaded: true,
        onPick: function(urls){
          if (!urls || !urls.length) return;
          applyHeaderImage(urls[0]);
          if (state) state.textContent = 'Uploaded to the Media Library. Press Save category to keep it on this category.';
          say('Banner uploaded');
        }
      });
    };

    var lib = document.getElementById('ct-hdrlib');
    if (lib) lib.onclick = function(e){
      e.preventDefault();
      if (typeof window.kbbPickMedia !== 'function') { say('The Media Library is not available.'); return; }
      window.kbbPickMedia({
        title: 'Choose the header picture',
        note: 'Best at 2400 × 600. Pick one already in the library, or upload a new one — it joins the library first.',
        folder: 'categories',
        onPick: function(urls){
          if (!urls || !urls.length) return;
          applyHeaderImage(urls[0]);
          say('Header picture chosen');
        }
      });
    };

    var drop = document.getElementById('ct-hdrdrop');
    if (drop) drop.onclick = function(e){ e.preventDefault(); applyHeaderImage(''); if (state) state.textContent = ''; };

    var box = document.getElementById('ct-hdrimg');
    if (box) box.oninput = function(){ applyHeaderImage(box.value.trim()); };

    var clear = document.getElementById('ct-hdrtitleclear');
    if (clear) clear.onclick = function(e){
      e.preventDefault();
      var t = document.getElementById('ct-hdrtitle');
      if (t) { t.value = ''; t.focus(); }
      hdrPaint();
    };

    var colClear = document.getElementById('ct-hdrcolclear');
    if (colClear) colClear.onclick = function(e){
      e.preventDefault();
      ['ct-hdrbg', 'ct-hdric'].forEach(function(id){ var el = document.getElementById(id); if (el) el.value = ''; });
      hdrPaint();
    };

    var root = document.getElementById('ct-hdr');
    var dlg = root ? root.closest('.ct-modal-box') : null;
    if (dlg) {
      /* Anything typed that the preview shows redraws it: the banner address,
         the words, the colours, the sizes -- and the name and description
         above, which a blank title and description fall back to. */
      dlg.addEventListener('input', function(e){
        var t = e.target;
        if (!t || !t.id && !t.getAttribute('data-ct-hdrpair')) return;
        var pair = t.getAttribute('data-ct-hdrpair');
        if (pair) { var to = document.getElementById(pair); if (to) to.value = t.value.toUpperCase(); }
        else if (t.id === 'ct-hdrbg' || t.id === 'ct-hdric') {
          var picker = dlg.querySelector('[data-ct-hdrpair="' + t.id + '"]');
          if (picker && /^#[0-9A-Fa-f]{6}$/.test(t.value)) picker.value = t.value;
        }
        if (pair || /^ct-(hdr|name$|desc$|image$)/.test(t.id || '')) hdrPaint();
      });
      dlg.addEventListener('click', function(e){
        var d = e.target.closest ? e.target.closest('[data-ct-hdrdev]') : null;
        if (!d || d.tagName !== 'BUTTON') return;
        hdr.dev = d.getAttribute('data-ct-hdrdev') === 'laptop' ? 'laptop' : 'phone';
        hdrPaint();
        var again = dlg.querySelector('[data-ct-hdrlive] [data-ct-hdrdev="' + hdr.dev + '"]');
        if (again && d.closest('[data-ct-hdrlive]')) again.focus();
      });
      dlg.addEventListener('kbb-th-pick', function(e){
        var g = (e.detail || {}).group, v = (e.detail || {}).value;
        if (g === 'ct-focus') { hdr.st.focus = v === 'center' ? null : v; hdrPaint(); return; }
        if (!HDR_GROUPS[g]) return;
        var key = HDR_GROUPS[g] + (hdr.dev === 'laptop' ? '_desktop' : '_phone');
        if (v) hdr.st[key] = v; else delete hdr.st[key];
        hdrPaint();
      });
    }

    hdrPaint();
    hdrLoadShop();
  }

  /* -------------------------------------------------------------- editor */
  function editor(cat){
    var isNew = !cat;
    /* Lane RL: "X is editing this category" and Take over. One record, one
       dialog. After this tick, so openModal()'s own closeModal() of any
       previous dialog cannot release the lock this one is about to take. */
    if (!isNew && window.kbbPresence) { var lockId = cat.id; setTimeout(function(){ window.kbbPresence.open('category', lockId); }, 0); }
    cat = cat || {name:'', slug:'', parent_id:null, description:'', image:'', position:0, seo:null};
    var seo = cat.seo || {};
    var banned = isNew ? [] : subtreeIds(Number(cat.id));

    var opts = '<option value="">— top level —</option>'
      + treeRows(null, 0, []).filter(function(r){ return banned.indexOf(Number(r.cat.id)) === -1; })
          .map(function(r){
            var sel = (cat.parent_id != null && Number(cat.parent_id) === Number(r.cat.id)) ? ' selected' : '';
            return '<option value="' + esc(r.cat.id) + '"' + sel + '>'
                 + esc(new Array(r.depth + 1).join('— ') + r.cat.name) + '</option>';
          }).join('');

    // The warning is the whole point of naming the slug separately: the owner
    // has to be able to rename "Sun Care" to "Suncare & SPF" without being made
    // to think about URLs, and has to be stopped from editing the slug without
    // realising an indexed address is moving.
    var slugWarn = isNew ? '' :
      '<div class="ct-warn">Changing the slug moves this category’s address. '
      + 'The old one keeps working as a redirect, and appears under <b>URLs that moved</b>. '
      + 'Changing only the <b>name</b> leaves the address exactly as it is.</div>';

    openModal(
      '<div class="ct-modal-h"><b>' + (isNew ? 'Add category' : 'Edit ' + esc(cat.name)) + '</b>'
      + '<button class="ct-x" data-close>✕</button></div>'
      + slugWarn
      + '<div class="ct-fld"><label for="ct-name">Name</label>'
        + '<input id="ct-name" value="' + esc(cat.name) + '">'
        /* T4b — the Arabic name, in this form, saved by this form's button, on
           the Add dialog as well as the Edit one. Blank means "not translated
           yet" and never "same as the English". */
        + arabicBox(cat, 'name', 'Name', '#ct-name', 255)
        + '</div>'
      + '<div class="ct-fld"><label for="ct-slug">URL slug</label>'
        + '<input id="ct-slug" value="' + esc(cat.slug) + '" placeholder="left blank, made from the name">'
        + '<p class="ct-note">One segment of /collections/…/. Lower case, numbers and single hyphens. '
        + 'The full path is built from the parents.</p></div>'
      + '<div class="ct-fld"><label for="ct-parent">Parent</label>'
        + '<select id="ct-parent">' + opts + '</select>'
        + (isNew ? '' : '<p class="ct-note">This category and everything under it are not offered — a category cannot sit inside itself. '
          + 'Moving it rewrites the address of every sub-category too; each old address becomes a redirect.</p>')
      + '</div>'
      /* THE LIBRARY IS THE WAY IN, NOT A SECOND ONE.
         This field used to be a bare browser "Choose File" sitting next to the
         URL box, and it is the control in the owner's screenshot. A raw file
         input can only ever do one thing — send a file from this computer — so
         an image already in the library had to be found, downloaded and sent
         again. The button below opens the shared picker, which lists what is
         already there AND carries its own "Upload new" that files the new image
         in the library before selecting it. Nothing is lost: the address box is
         still here and still editable, so a URL that lives somewhere else can
         still be pasted straight in. */
      + '<div class="ct-fld"><label for="ct-image">Image</label>'
        + '<div style="display:flex;gap:9px;align-items:center;min-width:0">'
        /* Hidden until there is something to show. src="" resolves to the page
           itself, which the browser then draws as a broken-image icon — and a
           category with no image yet is the normal case, so the field opened
           looking like a failure. */
        + '<img class="ct-thumb" id="ct-thumb" alt=""' + (cat.image ? '' : ' style="display:none"')
        +   ' src="' + esc(cat.image || '') + '">'
        + '<button type="button" class="ct-btn" id="ct-lib" style="flex:0 0 auto">Choose from Media Library</button>'
        + '</div>'
        + '<label for="ct-image" class="ct-note" style="display:block;margin-top:7px">Image address</label>'
        + '<input id="ct-image" style="width:100%;min-width:0" value="' + esc(cat.image || '') + '" placeholder="https://…">'
        + '<p class="ct-note">Pick one already in the library, or upload a new one from inside it — '
        + 'either way it joins the library first, so no photograph has to be uploaded twice. '
        + 'An address from elsewhere can still be pasted in above.</p></div>'
      + '<div class="ct-fld"><label for="ct-desc">Description</label>'
        + '<textarea id="ct-desc" rows="3">' + esc(cat.description || '') + '</textarea>'
        + '<p class="ct-note">Shown under the heading on the category page.</p>'
        + arabicBox(cat, 'description', 'Description', '#ct-desc', 5000, 'textarea')
        + '</div>'
      /* LANE PY -- the category's own title header. */
      + headerPanel(cat)
      /* LANE S7 — WHAT GOOGLE WILL ACTUALLY SHOW, above the two boxes that
         decide it. A mount point and nothing else: no control, no stored value,
         nothing added to the save payload below.

         The two boxes under it collected a title and a description and showed
         no consequence, which is why they get filled in badly. An empty title
         box does not publish an empty title — it publishes the category's own
         name through `seo_title_template`, which appends " | K-Beauty Bliss" on
         this store; a filled one is the WHOLE title with no site name added.
         Those are opposite answers to "how long is my title" and neither is
         visible on this screen.

         window.kbbSeoPreview() is defined by
         resources/views/admin/partials/seo-back-office.blade.php and asks the
         server for the real emitted tag — App\Support\Seo, the class the
         category page itself renders with. Guarded below rather than assumed,
         the same way this file guards window.kbbPickMedia. */
      + '<div class="ct-fld" id="ct-seoprev"></div>'
      + '<div class="ct-fld"><label for="ct-seotitle">SEO title</label>'
        + '<input id="ct-seotitle" value="' + esc(seo.title || '') + '" maxlength="255" placeholder="Defaults to the category name">'
        + '</div>'
      + '<div class="ct-fld"><label for="ct-seodesc">SEO description</label>'
        + '<textarea id="ct-seodesc" rows="2" maxlength="500" placeholder="Defaults to a generated sentence">' + esc(seo.description || '') + '</textarea>'
        + '<p class="ct-note">Google truncates past roughly 155 characters on desktop and 120 on mobile.</p></div>'
      /* The three the STOREFRONT ALREADY READ off categories.seo and this
         screen had no box for. Store\ShopController resolves all three for the
         archive, and Store\SeoFilesController reads noindex again to decide
         whether the sitemap may advertise the category. */
      + '<div class="ct-fld"><label for="ct-seocanon">Canonical URL</label>'
        + '<input id="ct-seocanon" maxlength="500" value="' + esc(seo.canonical || '') + '" placeholder="Leave empty to use this page\u2019s own address">'
        + '<p class="ct-note">A full address, including https://. Use it only when this archive duplicates one somewhere else.</p></div>'
      + '<div class="ct-fld"><label for="ct-seoog">Share image</label>'
        + '<input id="ct-seoog" maxlength="500" value="' + esc(seo.og_image || '') + '" placeholder="Defaults to the category banner">'
        + '<p class="ct-note">Shown when the category page is shared on WhatsApp, Facebook or X.</p></div>'
      + '<div class="ct-fld"><label for="ct-seonoindex" style="display:flex;gap:9px;align-items:flex-start">'
        + '<input type="checkbox" id="ct-seonoindex" style="width:auto;margin-top:2px"' + (seo.noindex ? ' checked' : '') + '>'
        + '<span><b>Ask Google not to list this category page</b>'
        + '<span class="ct-note" style="display:block;margin-top:3px;font-weight:400">Removes the archive from search results AND from the sitemap. '
        + 'The products stay listed \u2014 this hides the category page only.</span></span></label></div>'
      + '<div class="ct-grid2"><div class="ct-fld"><label for="ct-pos">Position</label>'
        + '<input id="ct-pos" type="number" min="0" value="' + esc(cat.position || 0) + '">'
        + '<p class="ct-note">Or just drag the row.</p></div><div></div></div>'
      + '<div class="ct-modal-f">'
        + '<button class="ct-btn" data-close>Cancel</button>'
        + '<button class="ct-btn is-primary" id="ct-save">' + (isNew ? 'Create category' : 'Save category') + '</button>'
      + '</div>'
    );

    /* One place that writes the image into the dialog, whichever way it
       arrived — the picker, or the operator typing an address. The saved value
       is unchanged by any of this: it is still the URL string read out of
       #ct-image by val('ct-image') below, exactly as the direct upload used to
       leave there. */
    function applyImage(url){
      if (!url) return;
      var box = document.getElementById('ct-image');
      var thumb = document.getElementById('ct-thumb');
      if (box) box.value = url;
      if (thumb) { thumb.src = url; thumb.style.display = ''; }
    }

    var lib = document.getElementById('ct-lib');
    if (lib) {
      lib.onclick = function(e){
        e.preventDefault();

        /* The picker is its own partial, included before this one. Guarded
           rather than assumed: a package that shipped this screen without it
           would otherwise throw on the first click, which reads to the operator
           as a dead button with no explanation. */
        if (typeof window.kbbPickMedia !== 'function') { say('The Media Library is not available.'); return; }

        window.kbbPickMedia({
          title: 'Choose the category image',
          note: 'Pick one already in the library, or upload a new one — it joins the library first.',
          folder: 'categories',
          onPick: function(urls){
            if (!urls || !urls.length) return;
            applyImage(urls[0]);
            say('Image chosen');
          }
        });
      };
    }

    /* Typing or pasting an address keeps the thumbnail honest. Previously only
       an upload could move it, so a pasted URL saved a picture the dialog was
       never showing. */
    var imgBox = document.getElementById('ct-image');
    if (imgBox) imgBox.oninput = function(){ applyImage(imgBox.value); };

    wireHeaderPanel();

    /* LANE S7 — the Google-result preview. `id` is null while creating, which
       is a supported state and not a gap: the preview then reads the Name and
       Address boxes on this very form, which is the moment the wording matters
       most. Guarded the way kbbPickMedia is above — a package that shipped this
       screen without the preview partial draws the dialog it drew before rather
       than throwing on open. */
    if (typeof window.kbbSeoPreview === 'function') {
      window.kbbSeoPreview({
        mount: '#ct-seoprev',
        kind: 'category',
        id: isNew ? null : cat.id,
        title: '#ct-seotitle',
        description: '#ct-seodesc',
        name: '#ct-name',
        slug: '#ct-slug',
        /* What an empty SEO description box falls back to on a category: the
           category's own Description, which is a box on this same form. */
        fallback: '#ct-desc'
      });
    }

    /* Attaches the Translate buttons and reveals them only if an API key is
       configured. With no key they stay hidden and every manual path here works
       unchanged — typing Arabic needs no account and costs nothing. */
    if (window.KBBArabic) KBBArabic.wire(modal || document);

    document.getElementById('ct-save').onclick = async function(){
      var parent = val('ct-parent');
      var payload = Object.assign(headerPayload(), {
        name: val('ct-name'),
        slug: val('ct-slug'),
        parent_id: parent === '' ? null : parseInt(parent, 10),
        description: val('ct-desc'),
        image: val('ct-image'),
        position: parseInt(val('ct-pos'), 10) || 0,
        /* Every box on every save, including an unchecked checkbox as an
           explicit false. The server treats a key it RECEIVES as authoritative
           and leaves one it does not receive alone, so omitting the checkbox
           when it is off would mean a noindex could be set and never cleared.
           See App\Support\ProductSeo::mergeFromForm(). */
        seo: {
          title: val('ct-seotitle'),
          description: val('ct-seodesc'),
          canonical: val('ct-seocanon'),
          og_image: val('ct-seoog'),
          noindex: !!(document.getElementById('ct-seonoindex') || {}).checked
        },
        /* The Arabic goes up in the SAME request as the English. Scoped to this
           dialog, because this screen opens several. Blank fields are sent
           rather than omitted: blank means "not translated yet" and has to
           reach the server to delete the row. */
        translations: window.KBBArabic ? KBBArabic.collect(modal || document) : {}
      });

      if (!payload.name) { say('A category needs a name'); return; }

      try {
        var res = isNew
          ? await api('/categories', 'POST', payload)
          : await api('/categories/' + Number(cat.id), 'PUT', payload);

        var moved = (res && res.redirects) || [];
        say(moved.length
          ? (isNew ? 'Category created' : 'Saved — ' + moved.length + ' URL' + (moved.length === 1 ? '' : 's') + ' now redirect')
          : (isNew ? 'Category created' : 'Category saved'));

        closeModal();
        await load();
      } catch (e) { say(e.message); }
    };
  }

  /* --------------------------------------------------------------- merge */
  function mergeDialog(cat){
    var banned = subtreeIds(Number(cat.id));
    var opts = treeRows(null, 0, []).filter(function(r){ return banned.indexOf(Number(r.cat.id)) === -1; })
      .map(function(r){
        return '<option value="' + esc(r.cat.id) + '">'
             + esc(new Array(r.depth + 1).join('— ') + r.cat.name) + '</option>';
      }).join('');

    if (!opts) {
      openModal('<div class="ct-modal-h"><b>Merge ' + esc(cat.name) + '</b><button class="ct-x" data-close>✕</button></div>'
        + '<div class="ct-danger">There is no other category to merge into.</div>'
        + '<div class="ct-modal-f"><button class="ct-btn" data-close>Close</button></div>');
      wireModal();
      return;
    }

    var filed = Number(cat.filed_count || 0);
    var kids = Number(cat.children_count || 0);

    openModal(
      '<div class="ct-modal-h"><b>Merge ' + esc(cat.name) + '</b><button class="ct-x" data-close>✕</button></div>'
      + '<div class="ct-warn">'
      + '<b>' + esc(filed) + '</b> product' + (filed === 1 ? '' : 's')
      + ' will be moved into the category you choose'
      + (kids ? ', and <b>' + esc(kids) + '</b> sub-categor' + (kids === 1 ? 'y' : 'ies') + ' will move up a level' : '')
      + '. <b>' + esc(cat.name) + '</b> is then deleted, and its address '
      + '<code>/collections/' + esc(cat.path || cat.slug) + '/</code> redirects to the one you pick. '
      + 'No product is deleted.</div>'
      + '<div class="ct-fld"><label for="ct-mtarget">Merge into</label>'
      + '<select id="ct-mtarget">' + opts + '</select></div>'
      + '<div class="ct-modal-f">'
      + '<button class="ct-btn" data-close>Cancel</button>'
      + '<button class="ct-btn is-primary" id="ct-mgo">Merge and delete</button>'
      + '</div>'
    );

    document.getElementById('ct-mgo').onclick = async function(){
      try {
        var res = await api('/categories/' + Number(cat.id) + '/merge', 'POST',
          {target_id: parseInt(val('ct-mtarget'), 10)});
        say('Merged — ' + (res.moved || 0) + ' product placement' + (res.moved === 1 ? '' : 's') + ' moved');
        closeModal();
        await load();
      } catch (e) { say(e.message); }
    };

    wireModal();
  }

  /* -------------------------------------------------------------- delete */
  /* The screen says what will happen BEFORE it happens, in the same words the
     API uses, and it says it whether or not anything is attached. */
  function deleteDialog(cat){
    var live = Number(cat.products_count || 0);
    var filed = Number(cat.filed_count || 0);
    var primary = Number(cat.primary_count || 0);
    var kids = Number(cat.children_count || 0);
    var parent = cat.parent_id == null ? null : catById(Number(cat.parent_id));

    var body;

    if (filed || primary || kids) {
      var bits = [];
      if (filed) bits.push('<b>' + esc(filed) + '</b> product' + (filed === 1 ? '' : 's')
        + ' filed under it (' + esc(live) + ' of them live on the page)');
      if (primary) bits.push('<b>' + esc(primary) + '</b> with it as their primary category');
      if (kids) bits.push('<b>' + esc(kids) + '</b> sub-categor' + (kids === 1 ? 'y' : 'ies'));

      body = '<div class="ct-danger">Deleting <b>' + esc(cat.name) + '</b> affects ' + bits.join(', ') + '.<br><br>'
        + 'Its archive page stops existing. Products are <b>not</b> deleted — they are unfiled from this category. '
        + (kids ? 'Sub-categories move up a level, and each of their addresses becomes a redirect. ' : '')
        + 'The address <code>/collections/' + esc(cat.path || cat.slug) + '/</code> will '
        + (parent
            ? 'redirect to <b>' + esc(parent.name) + '</b>.'
            : '<b>return 404</b>, because there is no parent to send it to.')
        + '</div>'
        + '<div class="ct-warn">If you only want to retire this category, <b>Merge</b> keeps the products '
        + 'and sends the old address somewhere real.</div>';
    } else {
      body = '<div class="ct-warn">Nothing is attached to <b>' + esc(cat.name) + '</b>. '
        + 'Its address <code>/collections/' + esc(cat.path || cat.slug) + '/</code> will '
        + (parent ? 'redirect to <b>' + esc(parent.name) + '</b>.' : '<b>return 404</b>.')
        + ' This cannot be undone.</div>';
    }

    openModal(
      '<div class="ct-modal-h"><b>Delete category</b><button class="ct-x" data-close>✕</button></div>'
      + body
      + '<div class="ct-modal-f">'
      + '<button class="ct-btn" data-close>Cancel</button>'
      + (filed || primary || kids
          ? '<button class="ct-btn" id="ct-dmerge">Merge instead</button>' : '')
      + '<button class="ct-btn is-danger" id="ct-dgo">Delete</button>'
      + '</div>'
    );

    var alt = document.getElementById('ct-dmerge');
    if (alt) alt.onclick = function(){ closeModal(); mergeDialog(cat); };

    document.getElementById('ct-dgo').onclick = async function(){
      try {
        // force=1 is what the operator just confirmed: without it the API
        // refuses to detach products and re-parent children behind their back.
        await api('/categories/' + Number(cat.id) + '?force=1', 'DELETE');
        say('Category deleted');
        closeModal();
        await load();
      } catch (e) { say(e.message); }
    };

    wireModal();
  }

  function wireBrandsLink(){
    var b = document.getElementById('ct-tobrands');
    if (b) b.onclick = function(){ window.go('brands-manager'); };
  }

  function wireModal(){
    if (!modal) return;
    modal.querySelectorAll('[data-close]').forEach(function(b){ b.onclick = closeModal; });
  }

  /* ---------------------------------------------------------------- wiring */
  function wire(){
    var host = document.getElementById('ct-root');
    if (!host) return;

    wireBrandsLink();

    host.querySelectorAll('[data-tab]').forEach(function(b){
      b.onclick = function(){
        if (tab === b.dataset.tab) return;
        tab = b.dataset.tab;
        load();
      };
    });

    var add = document.getElementById('ct-add');
    if (add) add.onclick = function(){ editor(null); };

    host.querySelectorAll('[data-edit]').forEach(function(b){
      b.onclick = function(){ var c = catById(b.dataset.edit); if (c) { editor(c); wireModal(); } };
    });

    host.querySelectorAll('[data-merge]').forEach(function(b){
      b.onclick = function(){ var c = catById(b.dataset.merge); if (c) mergeDialog(c); };
    });

    host.querySelectorAll('[data-del]').forEach(function(b){
      b.onclick = function(){ var c = catById(b.dataset.del); if (c) deleteDialog(c); };
    });

    host.querySelectorAll('[data-rdel]').forEach(function(b){
      b.onclick = async function(){
        try {
          await api('/categories/redirects/' + Number(b.dataset.rdel), 'DELETE');
          say('Redirect removed — that address now 404s');
          await load();
        } catch (e) { say(e.message); }
      };
    });

    var rq = document.getElementById('ct-rq');
    if (rq) {
      var t = null;
      rq.oninput = function(){
        clearTimeout(t);
        t = setTimeout(function(){
          redirectQuery = rq.value.trim();
          redirectPage = 1;
          load();
        }, 250);
      };
    }

    var prev = document.getElementById('ct-rprev');
    if (prev) prev.onclick = function(){ if (redirectPage > 1) { redirectPage--; load(); } };

    var next = document.getElementById('ct-rnext');
    if (next) next.onclick = function(){ redirectPage++; load(); };

    wireDrag();
  }

  /* -------------------------------------------------------- sidebar entry */
  /*
   * Through the shared helper in app.blade.php.
   *
   * Anchored to the Catalog entry: this is the screen the owner opens to
   * merchandise, and Catalog is the group they already open for it.
   *
   * The old second half was `|| querySelector('[data-go="products"]')`, and
   * this file is where that spread from. There has never been a 'products' row
   * in this console, so it was decoration that read like a fallback -- the
   * audit's words, and the reason the next person to rename 'catalog' would
   * have believed there was a net under them. The real net is the helper: the
   * end of the Catalog group, then the sidebar itself, with a console error
   * naming this screen if it gets that far.
   */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Categories',
      icon:   '<path d="M3 7h6l2 2h10v10a2 2 0 0 1-2 2H3z"/><path d="M3 7V5a2 2 0 0 1 2-2h4l2 2"/>',
      group:  'Catalog',
      after:  'catalog'
    });
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });

    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]')
             || document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog';
    if (title) title.textContent = 'Categories';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.innerHTML = '<div id="ct-root"></div>';

    load();
    return undefined;
  };

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }

  // Exposed for the layout test, which drives the screen in a real browser
  // without an admin session to log into.
  /*
   * Lets the Brands editor reload this screen after it renames or deletes a
   * brand from a button it injected into these rows. Without it the row sits
   * stale behind a dialog that has already saved — the owner renames a brand,
   * the dialog closes, and the old name is still on screen.
   *
   * One line rather than moving those buttons into brandsView(): that is the
   * better end state and it is written down in the Brands editor's own
   * comments, but it means rebuilding rows this screen's drag-to-reorder
   * wiring is bound to, and that is worth doing with a browser open rather
   * than in passing.
   */
  window.__ctReload = load;

  window.__ctRenderForTest = function(fixtureCats, fixtureBrands, fixtureRedirects){
    cats = fixtureCats || [];
    brands = fixtureBrands || [];
    redirects = fixtureRedirects || null;
    busy = false;
    render();
  };
  window.__ctSetTab = function(t){ tab = t; };
  window.__ctEditor = function(id){ editor(id == null ? null : catById(id)); wireModal(); };
  window.__ctDelete = function(id){ deleteDialog(catById(id)); };
  window.__ctMerge = function(id){ mergeDialog(catById(id)); };
})();
</script>
@endverbatim
