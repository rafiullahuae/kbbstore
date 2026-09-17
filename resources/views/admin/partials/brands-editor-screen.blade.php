{{--
    Store - Catalog - Brands, and the page banner editor (Lane BW).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, and AFTER
    admin/partials/category-tree-screen.blade.php, which this cooperates with.
    Same shape as that file and as coupon-usage-screen.blade.php; that is the
    precedent.

    WHY THIS IS A SEPARATE FILE AND NOT A PATCH TO THE BRANDS TAB.

    The owner's report was "on the Brands tab I cannot see any edit, add
    buttons etc.", and they are right: brandsView() in
    category-tree-screen.blade.php renders every brand row with an EMPTY
    <div class="ct-acts"></div> and its card head has no Add button, while the
    Categories tab beside it has Add, Edit, Merge and Delete. The API those
    buttons would call has existed the whole time -- BrandsApiController has
    index, store, update and destroy, and routes/brands-admin.php registers all
    four. It was only ever a missing UI.

    category-tree-screen.blade.php is owned by another lane this cycle (its
    image field is being moved to the media library and its modal close
    handling has just been fixed), so it is not edited here. Instead this file
    does two things:

      1. It registers a Brands screen of its own -- nav entry, list, Add, Edit,
         Delete, and the banner editor -- which is complete on its own terms
         and is what the tests drive.

      2. It ENHANCES the existing Brands tab in place. A MutationObserver
         watches #content; whenever the Categories & Brands screen re-renders,
         the Add button is put into the brands card head and Edit/Delete into
         each row's empty .ct-acts. Nothing is replaced and no existing handler
         is rebound -- the drag-to-reorder wiring that file does is untouched,
         because injecting a button into an existing element does not disturb
         the listeners already attached to the row.

         The same pass adds a Banner button to each CATEGORY row, because the
         owner asked for the banner on category pages too and the category
         editor lives in that same off-limits file.

    THE INTEGRATOR'S OPTION. If the observer is not wanted, the native change
    is small and is written out in full in this lane's report: in brandsView(),
    put the three buttons inside the .ct-acts it already emits and an
    #ct-badd into the .ct-head, then three querySelectorAll blocks in wire().
    This file's window.KbbBrandEditor API is what those handlers would call.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Brands + banner editor. Every rule is prefixed bz- and appears nowhere else
   in the console, so this file cannot restyle another screen by accident.

   THE LAYOUT RULE: min-width:0 on every grid and flex child that can contain
   something wide. A grid item's default min-width is `auto` -- "at least as
   wide as my content" -- so one long brand name or one long URL refuses to
   shrink, the scroller's overflow-x never gets a chance, and the whole screen
   is stretched. That shipped on the Coupons screen once already.

   And why it is invisible in testing: this admin sets body{overflow-x:hidden}
   and scrolls inside #content, so documentElement.scrollWidth reads a
   comforting zero however over-wide the screen is. #content is the element
   that has to be measured.
--------------------------------------------------------------------------- */
.bz-wrap{display:grid;gap:16px;min-width:0}
.bz-wrap > *{min-width:0}

.bz-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:16px;min-width:0}
.bz-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;min-width:0}
.bz-head > *{min-width:0}
.bz-title{font-weight:650;font-size:15px}
.bz-sub{color:var(--ink-soft,#6b7280);font-size:12.5px}

.bz-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:12px;min-width:0}
.bz-stats > *{min-width:0}
.bz-stat{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:12px 14px;min-width:0}
.bz-stat b{display:block;font-size:20px;line-height:1.3;font-variant-numeric:tabular-nums}
.bz-stat span{color:var(--ink-soft,#6b7280);font-size:12px}

.bz-list{display:grid;gap:6px;min-width:0}
.bz-row{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;
        border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:9px 11px;
        background:var(--surface,#fff);min-width:0}
.bz-row > *{min-width:0}

.bz-logo{width:34px;height:34px;border-radius:8px;object-fit:contain;background:var(--chip,#f3f4f6);
         border:1px solid var(--border,#e6e6e6);flex:0 0 auto}
.bz-initial{width:34px;height:34px;border-radius:8px;background:var(--chip,#f3f4f6);
            border:1px solid var(--border,#e6e6e6);display:grid;place-items:center;
            font-weight:700;font-size:14px;color:var(--ink-soft,#6b7280);flex:0 0 auto}

.bz-main{display:flex;flex-wrap:wrap;gap:4px 9px;align-items:baseline;min-width:0}
.bz-main > *{min-width:0}
.bz-name{font-weight:600;font-size:13.5px;overflow-wrap:anywhere}
/* The URL is the longest thing on the row and the most likely to blow the
   layout out. It wraps anywhere and gets its own line on a phone. */
.bz-path{font-family:var(--mono,ui-monospace,monospace);font-size:11px;
         color:var(--ink-soft,#6b7280);overflow-wrap:anywhere;min-width:0}

.bz-acts{display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end;min-width:0}
.bz-btn{border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);
        border-radius:8px;padding:5px 9px;font:inherit;font-size:11.5px;cursor:pointer;
        color:var(--ink-2,#374151)}
.bz-btn:hover{border-color:var(--ink,#111)}
.bz-btn.is-danger{color:var(--danger,#d6455a)}
.bz-btn.is-primary{background:var(--ink,#111);color:#fff;border-color:var(--ink,#111)}

.bz-pill{display:inline-block;border-radius:999px;padding:2px 8px;font-size:10.5px;
         background:var(--chip,#f3f4f6);color:var(--ink-soft,#6b7280);white-space:nowrap}
.bz-pill.is-zero{background:#fee2e2;color:#991b1b}
.bz-pill.is-on{background:#dcfce7;color:#166534}

.bz-empty{padding:26px 12px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}

/* ------------------------------------------------------------------- modal */
/* z-index 900, NOT higher, and that is the whole reason it is written down.
   The Media Library popup (admin/partials/media-picker.blade.php, .mp-back)
   is also 900, and it is appended to <body> after this dialog, so equal
   stacking plus source order puts it in front — which is what has to happen
   when the operator clicks "Choose from Media Library" from inside here. At
   9000 this dialog covered the picker it had just opened, and the picker was
   visible around the edges but unusable. .ct-modal on the neighbouring screen
   is 900 for the same reason. */
.bz-modal{position:fixed;inset:0;background:rgba(15,15,20,.45);z-index:900;
          display:flex;align-items:flex-start;justify-content:center;padding:22px 14px;overflow:auto}
.bz-modal-box{background:var(--surface,#fff);border-radius:14px;padding:18px;width:min(620px,100%);
              box-shadow:0 24px 70px rgba(0,0,0,.28);min-width:0}
.bz-modal-h{display:flex;align-items:center;justify-content:space-between;gap:10px;
            margin-bottom:12px;font-size:15px;min-width:0}
.bz-x{border:none;background:none;font-size:17px;line-height:1;cursor:pointer;color:var(--ink-soft,#6b7280)}
.bz-modal-f{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-top:16px;min-width:0}

.bz-fld{margin:0 0 12px;min-width:0}
.bz-fld label{display:block;font-size:12px;font-weight:600;margin-bottom:5px;color:var(--ink-2,#374151)}
.bz-fld input[type=text],.bz-fld input[type=number],.bz-fld input[type=url],
.bz-fld select,.bz-fld textarea{
  width:100%;box-sizing:border-box;min-width:0;border:1px solid var(--border,#e6e6e6);
  border-radius:9px;padding:8px 10px;font:inherit;font-size:13px;background:var(--surface,#fff);
  color:var(--ink,#111)}
.bz-fld textarea{resize:vertical}
.bz-note{font-size:11.5px;color:var(--ink-soft,#6b7280);margin-top:5px;line-height:1.45}
.bz-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:0 12px;min-width:0}
.bz-grid2 > *{min-width:0}

.bz-warn{background:#fef3c7;color:#7c2d12;border-radius:10px;padding:10px 12px;
         font-size:12.5px;line-height:1.5;margin-bottom:12px}
.bz-danger{background:#fee2e2;color:#7f1d1d;border-radius:10px;padding:10px 12px;
           font-size:12.5px;line-height:1.5;margin-bottom:12px}
.bz-warn code,.bz-danger code{font-family:var(--mono,ui-monospace,monospace);font-size:11.5px}

.bz-thumb{width:52px;height:52px;border-radius:9px;object-fit:cover;flex:0 0 auto;
          background:var(--chip,#f3f4f6);border:1px solid var(--border,#e6e6e6)}

/* The banner block is folded away until the switch is on: off is the default
   and eight fields for something switched off is noise. */
.bz-switch{display:flex;gap:9px;align-items:flex-start;min-width:0;
           border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
           background:var(--chip,#f9fafb)}
.bz-switch input{margin:2px 0 0;flex:0 0 auto}
.bz-switch b{font-size:12.5px}
.bz-body{margin-top:12px}
.bz-body[hidden]{display:none}

/* ----------------------------------------------------------------- preview
   A miniature of the real thing so the owner can see the three styles rather
   than read three names. It is NOT the storefront stylesheet -- it is forty
   lines that approximate it, because loading the shop's CSS into the admin
   would restyle the console. */
.bz-prev{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:hidden;
         min-width:0;position:relative;isolation:isolate;background:#f4eef0}
.bz-prev-in{position:relative;z-index:2;display:flex;align-items:flex-end;padding:14px 16px;
            aspect-ratio:5 / 2;min-height:118px;min-width:0}
.bz-prev-h{font-size:19px;font-weight:700;line-height:1.1;letter-spacing:-.02em;overflow-wrap:anywhere}
.bz-prev-s{font-size:11.5px;margin-top:5px;line-height:1.4;overflow-wrap:anywhere}
.bz-prev-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0}
.bz-prev-scrim{position:absolute;inset:0;z-index:1}
.bz-prev.is-light .bz-prev-h{color:#fff}
.bz-prev.is-light .bz-prev-s{color:rgba(255,255,255,.9)}
.bz-prev.is-dark .bz-prev-h{color:#111}
.bz-prev.is-dark .bz-prev-s{color:#4b5563}
.bz-prev-split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);min-width:0}
.bz-prev-split > *{min-width:0}
.bz-prev-half{position:relative;aspect-ratio:4 / 3;background:#f4eef0}
.bz-prev-half img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.bz-prev-panel{display:flex;align-items:center;padding:14px 16px;min-width:0}

@media (max-width:560px){
  /* Action buttons move under the name rather than competing with it for the
     row's width. Without this the three-column grid keeps a column for them at
     every width and the name is squeezed to nothing. */
  .bz-row{grid-template-columns:auto 1fr}
  .bz-acts{grid-column:1 / -1;justify-content:flex-start}
}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'brands-manager';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var brands = [];
  var cats = [];
  var busy = false;
  var seq = 0;
  var modal = null;

  /* The three styles. The value is what PageBanner::STYLES accepts; changing
     one here without changing it there stores a value the storefront will
     silently replace with the default. */
  var STYLES = [
    ['full',  'Full-bleed photo',  'The picture runs edge to edge with a soft dark gradient over it, and the words sit on top. The workhorse — use it when you have one strong photograph.'],
    ['split', 'Split',             'Picture on one side, words on a coloured panel on the other. On a phone the picture sits above the words. Good when the photo has a subject you do not want text across.'],
    ['tint',  'Soft tint',         'No photograph at all — a wash of your brand colour behind large type. This is the one to pick when you have no good picture, and it is what the other two fall back to if you leave the image empty.']
  ];

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
     so both are surfaced, and the parsed body is hung on the error so a caller
     that needs to branch on `error` (brand_in_use) still can. */
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

  /* Every operator-supplied string -- a brand name, a slug, a heading, an
     error message quoting one back -- goes through this before innerHTML. */
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* A preview thumbnail that is EMPTY rather than broken.

     src="" is not "no image": it is a request for the current document, which
     the browser fetches and then fails to decode, so the field shows a
     broken-image icon before the operator has done anything wrong. The
     attribute is left off entirely until there is a URL, and the CSS gives the
     element a neutral tile in the meantime. */
  function thumb(id, url){
    url = String(url || '').trim();
    return '<img class="bz-thumb" id="' + id + '" alt=""' + (url ? ' src="' + esc(url) + '"' : '') + '>';
  }

  function byId(list, id){
    for (var i = 0; i < list.length; i++) {
      if (Number(list[i].id) === Number(id)) return list[i];
    }
    return null;
  }

  /* ----------------------------------------------------------------- data */
  /* The empty shape of the Arabic boxes, for the Add-brand form. A brand being
     created has no translations but still has to draw a box for every
     translatable field — that is the requirement. Held here rather than listed,
     so Brand::$translatable stays the one place the answer lives. */
  var ARABIC_SHAPE = null;

  async function loadBrands(){
    var d = await api('/brands');
    brands = (d && d.brands) || [];
    ARABIC_SHAPE = (d && d.translatable) || ARABIC_SHAPE;
    return brands;
  }

  async function loadCats(){
    var d = await api('/categories');
    cats = (d && d.categories) || [];
    return cats;
  }

  async function load(){
    var mine = ++seq;
    busy = true;
    render();

    try {
      await loadBrands();
      if (mine !== seq) return;
    } catch (e) {
      if (mine === seq) say(e.message);
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  /* ---------------------------------------------------------------- views */
  function countPill(b){
    var n = Number(b.products_count || 0);
    return '<span class="bz-pill' + (n === 0 ? ' is-zero' : '') + '">'
         + esc(n) + ' product' + (n === 1 ? '' : 's') + '</span>';
  }

  function bannerPill(row){
    var bn = row && row.banner;
    if (!bn || !bn.enabled) return '';
    var label = 'Full-bleed';
    for (var i = 0; i < STYLES.length; i++) {
      if (STYLES[i][0] === bn.style) label = STYLES[i][1];
    }
    return ' <span class="bz-pill is-on">Banner: ' + esc(label) + '</span>';
  }

  function rowHtml(b){
    return '<div class="bz-row" data-brow="' + esc(b.id) + '">'
      + (b.logo
          ? '<img class="bz-logo" src="' + esc(b.logo) + '" alt="">'
          : '<span class="bz-initial">' + esc(String(b.name || '?').charAt(0).toUpperCase()) + '</span>')
      + '<div class="bz-main">'
        + '<span class="bz-name">' + esc(b.name) + '</span>'
        + countPill(b)
        + bannerPill(b)
        + '<span class="bz-path">/shop/?filter_brands=' + esc(b.slug) + '</span>'
      + '</div>'
      + '<div class="bz-acts">'
        + '<button class="bz-btn" data-bedit="' + esc(b.id) + '">Edit</button>'
        + '<button class="bz-btn" data-bbanner="' + esc(b.id) + '">Banner</button>'
        + '<button class="bz-btn is-danger" data-bdel="' + esc(b.id) + '">Delete</button>'
      + '</div>'
    + '</div>';
  }

  function render(){
    var host = document.getElementById('bz-root');
    if (!host) return;

    var body;

    if (busy && !brands.length) {
      body = '<div class="bz-empty">Loading brands…</div>';
    } else if (!brands.length) {
      body = '<div class="bz-empty">No brands yet — add the first one.</div>';
    } else {
      body = '<div class="bz-list">' + brands.map(rowHtml).join('') + '</div>';
    }

    var withBanner = brands.filter(function(b){ return b.banner && b.banner.enabled; }).length;
    var empty = brands.filter(function(b){ return Number(b.products_count || 0) === 0; }).length;

    host.innerHTML = '<div class="bz-wrap">'
      + '<div class="bz-stats">'
        + '<div class="bz-stat"><b>' + esc(brands.length) + '</b><span>Brands</span></div>'
        + '<div class="bz-stat"><b>' + esc(withBanner) + '</b><span>With a banner switched on</span></div>'
        + '<div class="bz-stat"><b>' + esc(empty) + '</b><span>With nothing in the shop</span></div>'
      + '</div>'
      + '<div class="bz-card">'
        + '<div class="bz-head"><div style="min-width:0">'
          + '<div class="bz-title">Brands</div>'
          + '<div class="bz-sub">Add, rename and delete brands, and switch on the banner that shows at the top of a brand’s page. '
          + 'A brand’s product listing is <code>/shop/?filter_brands=…</code> — a query parameter, not a path.</div>'
        + '</div>'
        + '<button class="bz-btn is-primary" id="bz-add">+ Add brand</button>'
        + '</div>'
        + '<div style="margin-top:13px;min-width:0">' + body + '</div>'
      + '</div>'
      + '</div>';

    wire(host);
  }

  function wire(host){
    var add = host.querySelector('#bz-add');
    if (add) add.onclick = function(){ editor(null); };

    host.querySelectorAll('[data-bedit]').forEach(function(b){
      b.onclick = function(){ editor(byId(brands, b.dataset.bedit)); };
    });

    host.querySelectorAll('[data-bbanner]').forEach(function(b){
      b.onclick = function(){ bannerDialog('brand', byId(brands, b.dataset.bbanner)); };
    });

    host.querySelectorAll('[data-bdel]').forEach(function(b){
      b.onclick = function(){ deleteDialog(byId(brands, b.dataset.bdel)); };
    });
  }

  /* ---------------------------------------------------------------- modal */
  function openModal(html){
    closeModal();
    redraw = function(){};
    modal = document.createElement('div');
    modal.className = 'bz-modal';
    modal.innerHTML = '<div class="bz-modal-box">' + html + '</div>';
    modal.addEventListener('click', function(ev){ if (ev.target === modal) closeModal(); });
    document.body.appendChild(modal);
    modal.querySelectorAll('[data-close]').forEach(function(b){ b.onclick = closeModal; });
    return modal;
  }

  function closeModal(){
    if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
    modal = null;
  }

  function val(id){
    var el = document.getElementById(id);
    return el ? String(el.value == null ? '' : el.value).trim() : '';
  }

  function checked(id){
    var el = document.getElementById(id);
    return !!(el && el.checked);
  }

  /* -------------------------------------------------------- image fields */
  /* THE MEDIA LIBRARY, NOT A RAW FILE PICKER.

     The owner's standing instruction is "on any upload media on the whole
     backend, the media library is a must to show", and a raw
     <input type="file"> cannot satisfy it: it can only ever send a file from
     this computer, so an image already in the library has to be found,
     downloaded and uploaded a second time — which is the duplicate-uploading
     the instruction exists to stop. tests/Feature/AdminMediaPickerEverywhereTest
     fails by name if one appears here.

     window.kbbPickMedia is the console's one picker
     (admin/partials/media-picker.blade.php), and its own Upload-new tab is
     still how a file from this computer gets in — it just joins the library on
     the way through. The markup below carries no file input at all. */
  function libraryField(btnId, urlId, thumbId, hint){
    return '<div style="display:flex;gap:9px;align-items:center;min-width:0">'
      + thumb(thumbId, '')
      + '<input type="text" id="' + urlId + '" style="flex:1 1 auto;min-width:0" placeholder="https://…">'
      + '</div>'
      + '<div style="margin-top:7px"><button type="button" class="bz-btn" id="' + btnId + '">'
      + 'Choose from Media Library</button></div>'
      + '<p class="bz-note">' + hint + '</p>';
  }

  function wireLibrary(btnId, urlId, thumbId, folder, title, initial){
    var box = document.getElementById(urlId);
    var pic = document.getElementById(thumbId);

    function apply(url){
      url = String(url || '').trim();
      if (box) box.value = url;
      if (pic) {
        // Removing the attribute rather than setting it empty: src="" is a
        // request for the current document, which draws as a broken image.
        if (url) pic.setAttribute('src', url); else pic.removeAttribute('src');
      }
    }

    apply(initial);

    // Typing or pasting an address keeps the thumbnail honest. Without this
    // only the picker could move it, so a pasted URL would save a picture the
    // dialog was never showing.
    if (box) box.oninput = function(){ apply(box.value); redraw(); };

    var btn = document.getElementById(btnId);
    if (!btn) return;

    btn.onclick = function(e){
      e.preventDefault();

      /* The picker is its own partial, included before this one. Guarded
         rather than assumed: a package that shipped this screen without it
         would throw on the first click, which reads to the operator as a dead
         button with no explanation. */
      if (typeof window.kbbPickMedia !== 'function') { say('The Media Library is not available.'); return; }

      window.kbbPickMedia({
        title: title,
        note: 'Pick one already in the library, or upload a new one — it joins the library first.',
        folder: folder,
        onPick: function(urls){
          if (!urls || !urls.length) return;
          apply(urls[0]);
          redraw();
          say('Image chosen');
        }
      });
    };
  }

  /* The banner preview redraws on any image change, and the brand editor has
     no preview to redraw. One hook rather than two code paths. */
  var redraw = function(){};

  /* ------------------------------------------------------- banner fieldset */
  /* Shared by the brand editor and the category banner dialog, because the
     banner is the same thing on both and the storefront reads both through
     the same App\Support\PageBanner. */
  function bannerFields(bn){
    bn = bn || {};
    var on = !!bn.enabled;

    var styleOpts = STYLES.map(function(s){
      return '<option value="' + s[0] + '"' + ((bn.style || 'full') === s[0] ? ' selected' : '') + '>'
           + esc(s[1]) + '</option>';
    }).join('');

    return '<div class="bz-switch">'
        + '<input type="checkbox" id="bz-bn-on"' + (on ? ' checked' : '') + '>'
        + '<label for="bz-bn-on" style="margin:0"><b>Show a banner on this page</b>'
        + '<div class="bz-note" style="margin-top:3px">Off unless you switch it on. '
        + 'Nothing is added to the page while this is off.</div></label>'
      + '</div>'

      + '<div class="bz-body" id="bz-bn-body"' + (on ? '' : ' hidden') + '>'
        + '<div class="bz-fld" style="margin-top:12px"><label for="bz-bn-style">Style</label>'
          + '<select id="bz-bn-style">' + styleOpts + '</select>'
          + '<p class="bz-note" id="bz-bn-styledesc"></p></div>'

        + '<div class="bz-fld"><label for="bz-bn-image">Banner image</label>'
          + libraryField('bz-bn-lib', 'bz-bn-image', 'bz-bn-thumb',
              'A wide picture works best — roughly 1600 by 640. '
              + '<b>Leave it empty and the banner falls back to Soft tint</b>, '
              + 'so the page never shows a broken image.')
        + '</div>'

        + '<div class="bz-fld"><label for="bz-bn-alt">What the picture shows</label>'
          + '<input type="text" id="bz-bn-alt" maxlength="200" value="' + esc(bn.image_alt || '') + '" '
          + 'placeholder="Defaults to the heading">'
          + '<p class="bz-note">Read aloud to anyone using a screen reader, and shown if the picture fails to load.</p></div>'

        + '<div class="bz-fld"><label for="bz-bn-heading">Heading</label>'
          + '<input type="text" id="bz-bn-heading" maxlength="160" value="' + esc(bn.heading || '') + '" '
          + 'placeholder="Defaults to the name of this page">'
          + '<p class="bz-note">This becomes the page’s main heading. The plain one underneath is hidden while the banner is on, so the page never has two.</p></div>'

        + '<div class="bz-fld"><label for="bz-bn-sub">Subheading</label>'
          + '<textarea id="bz-bn-sub" rows="2" maxlength="320" placeholder="Optional">' + esc(bn.subheading || '') + '</textarea></div>'

        + '<div class="bz-grid2">'
          + '<div class="bz-fld"><label for="bz-bn-tone">Text colour</label>'
            + '<select id="bz-bn-tone">'
            + '<option value="light"' + ((bn.tone || 'light') === 'light' ? ' selected' : '') + '>Light text on a dark banner</option>'
            + '<option value="dark"' + (bn.tone === 'dark' ? ' selected' : '') + '>Dark text on a light banner</option>'
            + '</select>'
            + '<p class="bz-note">Pick whichever your picture is not.</p></div>'
          + '<div class="bz-fld"><label for="bz-bn-tint">Banner colour</label>'
            + '<input type="text" id="bz-bn-tint" maxlength="7" value="' + esc(bn.tint || '#e0567b') + '" placeholder="#e0567b">'
            + '<p class="bz-note">Used by Soft tint and by the Split panel. Six-digit hex.</p></div>'
        + '</div>'

        + '<div class="bz-fld"><label for="bz-bn-overlay">Shading over the picture — <span id="bz-bn-ovval">'
          + esc(bn.overlay == null ? 55 : bn.overlay) + '</span>%</label>'
          + '<input type="range" id="bz-bn-overlay" min="0" max="90" step="5" style="width:100%" '
          + 'value="' + esc(bn.overlay == null ? 55 : bn.overlay) + '">'
          + '<p class="bz-note">Full-bleed only. More shading means the words are easier to read and the picture is harder to see.</p></div>'

        + '<div class="bz-fld"><label>Preview</label><div id="bz-bn-prev"></div>'
          + '<p class="bz-note">Close to the real thing, not exactly it — the real one is wider and uses the shop’s fonts.</p></div>'
      + '</div>';
  }

  /* Reads the fieldset back into the shape the API stores. */
  function bannerPayload(){
    return {
      enabled: checked('bz-bn-on'),
      style: val('bz-bn-style') || 'full',
      image: val('bz-bn-image'),
      image_alt: val('bz-bn-alt'),
      heading: val('bz-bn-heading'),
      subheading: val('bz-bn-sub'),
      tone: val('bz-bn-tone') || 'light',
      tint: val('bz-bn-tint') || '#e0567b',
      overlay: parseInt(val('bz-bn-overlay'), 10)
    };
  }

  function hex(v){
    v = String(v || '').trim().toLowerCase();
    return /^#([0-9a-f]{3}|[0-9a-f]{6})$/.test(v) ? v : '#e0567b';
  }

  /* The miniature. Mirrors the storefront's own rules: a missing image falls
     back to the tint style, exactly as PageBanner::resolve() does, so the
     preview cannot promise something the page will not draw. */
  function drawPreview(fallbackHeading){
    var host = document.getElementById('bz-bn-prev');
    if (!host) return;

    var p = bannerPayload();
    var img = String(p.image || '').trim();
    var style = (!img && p.style !== 'tint') ? 'tint' : p.style;
    var tone = p.tone === 'dark' ? 'dark' : 'light';
    var tint = hex(p.tint);
    var a = isNaN(p.overlay) ? .55 : Math.max(0, Math.min(90, p.overlay)) / 100;

    var heading = esc(p.heading || fallbackHeading || 'Your heading');
    var sub = p.subheading ? '<div class="bz-prev-s">' + esc(p.subheading) + '</div>' : '';
    var copy = '<div><div class="bz-prev-h">' + heading + '</div>' + sub + '</div>';

    var desc = '';
    for (var i = 0; i < STYLES.length; i++) {
      if (STYLES[i][0] === p.style) desc = STYLES[i][2];
    }
    if (style !== p.style) {
      desc += ' — no picture yet, so this is showing as Soft tint.';
    }
    var note = document.getElementById('bz-bn-styledesc');
    if (note) note.textContent = desc;

    var rgb = tone === 'light' ? '18,12,16' : '255,255,255';
    var html;

    if (style === 'full') {
      html = '<div class="bz-prev is-' + tone + '">'
        + '<img class="bz-prev-img" src="' + esc(img) + '" alt="">'
        + '<div class="bz-prev-scrim" style="background:linear-gradient(to top,rgba(' + rgb + ','
        + a + ') 0%,rgba(' + rgb + ',0) 100%),linear-gradient(to right,rgba(' + rgb + ','
        + (a * .7) + ') 0%,rgba(' + rgb + ',0) 62%)"></div>'
        + '<div class="bz-prev-in">' + copy + '</div>'
        + '</div>';
    } else if (style === 'split') {
      var panel = tone === 'light'
        ? 'linear-gradient(135deg,' + tint + ',' + tint + ')'
        : 'linear-gradient(135deg,#fff,#fff)';
      html = '<div class="bz-prev is-' + tone + '">'
        + '<div class="bz-prev-split">'
        + '<div class="bz-prev-half"><img src="' + esc(img) + '" alt=""></div>'
        + '<div class="bz-prev-panel" style="background:' + panel + '">' + copy + '</div>'
        + '</div></div>';
    } else {
      html = '<div class="bz-prev is-' + tone + '">'
        + '<div class="bz-prev-scrim" style="background:radial-gradient(120% 130% at 12% 8%,'
        + (tone === 'light' ? '#fff8' : '#fff') + ' 0%,transparent 58%),linear-gradient(118deg,'
        + tint + ' 0%,' + tint + 'cc 100%)' + (tone === 'dark' ? ';opacity:.22' : '') + '"></div>'
        + '<div class="bz-prev-in">' + copy + '</div>'
        + '</div>';
    }

    host.innerHTML = html;
  }

  /* Wires the fieldset: the fold, the upload, and a redraw on every change. */
  function wireBannerFields(fallbackHeading, initialImage){
    var sw = document.getElementById('bz-bn-on');
    var body = document.getElementById('bz-bn-body');

    if (sw && body) {
      sw.onchange = function(){
        body.hidden = !sw.checked;
        if (sw.checked) drawPreview(fallbackHeading);
      };
    }

    redraw = function(){ drawPreview(fallbackHeading); };

    wireLibrary('bz-bn-lib', 'bz-bn-image', 'bz-bn-thumb', 'banners',
      'Choose the banner image', initialImage);

    ['bz-bn-style','bz-bn-image','bz-bn-heading','bz-bn-sub','bz-bn-tone','bz-bn-tint','bz-bn-alt']
      .forEach(function(id){
        var el = document.getElementById(id);
        if (!el) return;
        el.oninput = function(){ drawPreview(fallbackHeading); };
        el.onchange = function(){ drawPreview(fallbackHeading); };
      });

    var ov = document.getElementById('bz-bn-overlay');
    if (ov) {
      ov.oninput = function(){
        var out = document.getElementById('bz-bn-ovval');
        if (out) out.textContent = ov.value;
        drawPreview(fallbackHeading);
      };
    }

    drawPreview(fallbackHeading);
  }

  /**
   * T4b — the Arabic counterpart of one brand field.
   *
   * Drawn only when the SERVER says the field is translatable. Add a column to
   * Brand::$translatable and its box appears here with no change to this file.
   *
   * The maxlength mirrors the English control's own — the Arabic box must not
   * accept what the English box would refuse. BrandsApiController enforces the
   * same bound server-side, derived from the same English rule.
   */
  function arabicBox(brand, field, label, fromSelector, maxlength, type){
    /* The shared helper is included by resources/views/admin/app.blade.php. A
       build that has this screen and not the helper simply draws no Arabic
       boxes, rather than throwing and taking the brands screen down with it. */
    if (!window.KBBArabic) return '';

    var shape = (brand && brand.translations) || ARABIC_SHAPE;

    if (!shape || !shape[KBBArabic.locale]
        || !Object.prototype.hasOwnProperty.call(shape[KBBArabic.locale], field)) {
      return '';
    }

    return KBBArabic.box({
      field: field,
      label: label,
      prefill: (brand && brand.translations) || null,
      type: type || 'text',
      maxlength: maxlength,
      rows: 3,
      from: fromSelector
    });
  }

  /* -------------------------------------------------------- brand editor */
  function editor(brand){
    var isNew = !brand;
    brand = brand || {name:'', slug:'', logo:'', description:'', position:0, seo:null, banner:null};
    var seo = brand.seo || {};

    /* The warning the Categories dialog shows is about redirects, and it is
       NOT true here, which is why this says something different rather than
       the same words.

       A category slug is a path segment inside /product-category/{path}/, so
       renaming it moves an indexed URL and the category screen records a
       redirect. A brand slug is not a path: the listing is
       /shop/?filter_brands={slug}, a query parameter (URL contract U-05), and
       there is no brand redirect table. What a rename breaks is different and
       worth naming precisely: any saved or shared link carrying the old value
       stops matching any brand, and the shop answers with the unfiltered grid
       rather than an error. The brand's own landing page at
       /korean-skincare-brands/{slug}/ does move, and does 404 afterwards. */
    var slugWarn = isNew ? '' :
      '<div class="bz-warn">Changing the slug changes how this brand is found in links. '
      + 'Its listing is <code>/shop/?filter_brands=' + esc(brand.slug) + '</code> and its page is '
      + '<code>/korean-skincare-brands/' + esc(brand.slug) + '/</code>. '
      + 'Old links with the previous slug will not redirect — the listing one quietly shows '
      + '<b>every</b> product instead of this brand’s, and the page one stops existing. '
      + 'Changing only the <b>name</b> leaves both addresses exactly as they are.</div>';

    openModal(
      '<div class="bz-modal-h"><b>' + (isNew ? 'Add brand' : 'Edit ' + esc(brand.name)) + '</b>'
      + '<button class="bz-x" data-close>✕</button></div>'
      + slugWarn
      + '<div class="bz-fld"><label for="bz-name">Name</label>'
        + '<input type="text" id="bz-name" value="' + esc(brand.name) + '">'
        /* T4b — the Arabic name, in this form, saved by this form's button, and
           present on the Add form as well as the Edit one. Blank means "not
           translated yet"; a brand name that reads the same in both languages,
           like Anua, is TYPED IN, and that is what keeps "deliberately
           identical" distinguishable from "nobody has reached it". */
        + arabicBox(brand, 'name', 'Name', '#bz-name', 255)
        + '</div>'
      + '<div class="bz-fld"><label for="bz-slug">URL slug</label>'
        + '<input type="text" id="bz-slug" value="' + esc(brand.slug) + '" placeholder="left blank, made from the name">'
        + '<p class="bz-note">Lower case, numbers and single hyphens. Used in '
        + '<code>/shop/?filter_brands=…</code> and in the brand’s own page address.</p></div>'
      + '<div class="bz-fld"><label for="bz-logo">Logo</label>'
        + libraryField('bz-logo-lib', 'bz-logo', 'bz-logo-thumb',
            'Shown beside the brand in the shop filter and on its page.')
        + '</div>'
      + '<div class="bz-fld"><label for="bz-desc">Description</label>'
        + '<textarea id="bz-desc" rows="3" maxlength="5000">' + esc(brand.description || '') + '</textarea>'
        + '<p class="bz-note">Shown under the heading on the brand’s page.</p>'
        + arabicBox(brand, 'description', 'Description', '#bz-desc', 5000, 'textarea')
        + '</div>'
      + '<div class="bz-fld"><label for="bz-seotitle">SEO title</label>'
        + '<input type="text" id="bz-seotitle" maxlength="255" value="' + esc(seo.title || '') + '" placeholder="Defaults to the brand name"></div>'
      + '<div class="bz-fld"><label for="bz-seodesc">SEO description</label>'
        + '<textarea id="bz-seodesc" rows="2" maxlength="500" placeholder="Defaults to a generated sentence">' + esc(seo.description || '') + '</textarea>'
        + '<p class="bz-note">Google truncates past roughly 155 characters on desktop and 120 on mobile.</p></div>'
      /* The three the STOREFRONT ALREADY READ off brands.seo and this screen
         had no box for. Store\BrandController::seoCtx() resolves all three,
         and Store\SeoFilesController reads noindex again to decide whether the
         sitemap may advertise the brand — so until these boxes existed the
         owner could not set, see, or correct any of them. */
      + '<div class="bz-fld"><label for="bz-seocanon">Canonical URL</label>'
        + '<input type="text" id="bz-seocanon" maxlength="500" value="' + esc(seo.canonical || '') + '" placeholder="Leave empty to use this page\u2019s own address">'
        + '<p class="bz-note">A full address, including https://. Use it only when this page is a duplicate of one somewhere else.</p></div>'
      + '<div class="bz-fld"><label for="bz-seoog">Share image</label>'
        + '<input type="text" id="bz-seoog" maxlength="500" value="' + esc(seo.og_image || '') + '" placeholder="Defaults to the banner photo, then the logo">'
        + '<p class="bz-note">Shown when the brand page is shared on WhatsApp, Facebook or X.</p></div>'
      + '<div class="bz-switch" style="margin-bottom:12px">'
        + '<input type="checkbox" id="bz-seonoindex"' + (seo.noindex ? ' checked' : '') + '>'
        + '<label for="bz-seonoindex" style="margin:0"><b>Ask Google not to list this brand page</b>'
        + '<div class="bz-note" style="margin-top:3px">Removes the page from search results AND from the sitemap. '
        + 'The brand\u2019s products stay listed \u2014 this hides its own landing page only.</div></label>'
      + '</div>'
      + '<div class="bz-grid2"><div class="bz-fld"><label for="bz-pos">Position</label>'
        + '<input type="number" id="bz-pos" min="0" max="65535" value="' + esc(brand.position || 0) + '">'
        + '<p class="bz-note">Lowest first in the shop’s brand filter. Or drag the row on Categories &amp; Brands.</p>'
        + '</div><div></div></div>'
      + '<div style="border-top:1px solid var(--border,#e6e6e6);margin:16px 0 13px"></div>'
      + bannerFields(brand.banner)
      + '<div class="bz-modal-f">'
        + '<button class="bz-btn" data-close>Cancel</button>'
        + '<button class="bz-btn is-primary" id="bz-save">' + (isNew ? 'Create brand' : 'Save brand') + '</button>'
      + '</div>'
    );

    wireBannerFields(brand.name || '', (brand.banner || {}).image);
    wireLibrary('bz-logo-lib', 'bz-logo', 'bz-logo-thumb', 'brands',
      'Choose the brand logo', brand.logo);

    var nameEl = document.getElementById('bz-name');
    if (nameEl) nameEl.oninput = function(){ drawPreview(nameEl.value); };

    /* Attaches the Translate buttons and reveals them only if an API key is
       configured. With no key they stay hidden and every manual path here
       works unchanged — typing Arabic needs no account and costs nothing. */
    if (window.KBBArabic) KBBArabic.wire(modal || document);

    document.getElementById('bz-save').onclick = async function(){
      var payload = {
        name: val('bz-name'),
        slug: val('bz-slug'),
        logo: val('bz-logo'),
        description: val('bz-desc'),
        position: parseInt(val('bz-pos'), 10) || 0,
        /* Every box is sent on every save, including an unchecked checkbox as
           an explicit false. The server treats a key it RECEIVES as
           authoritative and leaves one it does not receive alone, so omitting
           the checkbox when it is off would mean a noindex could be set and
           never cleared. See App\Support\ProductSeo::mergeFromForm(). */
        seo: {
          title: val('bz-seotitle'),
          description: val('bz-seodesc'),
          canonical: val('bz-seocanon'),
          og_image: val('bz-seoog'),
          noindex: !!(document.getElementById('bz-seonoindex') || {}).checked
        },
        banner: bannerPayload(),
        /* The Arabic goes up in the SAME request as the English, saved by the
           same button. Blank fields are sent rather than omitted, because blank
           means "not translated yet" and has to reach the server to delete the
           row. */
        /* Scoped to THIS dialog. This screen also opens a category-banner
           dialog that PUTs a category, and a document-wide read would carry
           one form's Arabic into the other's save. */
        translations: window.KBBArabic ? KBBArabic.collect(modal || document) : {}
      };

      if (!payload.name) { say('A brand needs a name'); return; }

      try {
        if (isNew) {
          await api('/brands', 'POST', payload);
          say('Brand created');
        } else {
          await api('/brands/' + Number(brand.id), 'PUT', payload);
          say('Brand saved');
        }
        closeModal();
        await refreshEverything();
      } catch (e) { say(e.message); }
    };
  }

  /* -------------------------------------------------------------- delete */
  /* The screen says what will happen BEFORE it happens, in the same words the
     API uses, and it says it whether or not anything is attached.

     BrandsApiController::destroy() refuses a brand with products unless
     `force=1` is passed, and nulls products.brand_id inside a transaction
     when it is. So there are exactly two truthful things to say here, and the
     count decides which -- guessing either one would be a screen that lies
     about what the button does. */
  function deleteDialog(brand){
    if (!brand) return;
    var n = Number(brand.products_count || 0);

    var body = n > 0
      ? '<div class="bz-danger"><b>' + esc(n) + '</b> product' + (n === 1 ? '' : 's')
        + ' still ' + (n === 1 ? 'belongs' : 'belong') + ' to <b>' + esc(brand.name) + '</b>. '
        + 'Deleting the brand leaves ' + (n === 1 ? 'that product' : 'those products')
        + ' with <b>no brand</b> — ' + (n === 1 ? 'it is' : 'they are') + ' <b>not</b> deleted, '
        + (n === 1 ? 'it' : 'they') + ' just stop showing a brand name and stop appearing under this brand’s filter.'
        + '<br><br>The address <code>/shop/?filter_brands=' + esc(brand.slug) + '</code> stops filtering and '
        + 'shows every product instead, and <code>/korean-skincare-brands/' + esc(brand.slug) + '/</code> will 404. '
        + 'This cannot be undone.</div>'
        + '<div class="bz-warn">If you only want to retire the brand, move those products to another brand first — '
        + 'then this delete affects nothing.</div>'
      : '<div class="bz-warn">Nothing is attached to <b>' + esc(brand.name) + '</b>. '
        + 'Its page <code>/korean-skincare-brands/' + esc(brand.slug) + '/</code> stops existing. '
        + 'This cannot be undone.</div>';

    openModal(
      '<div class="bz-modal-h"><b>Delete brand</b><button class="bz-x" data-close>✕</button></div>'
      + body
      + '<div class="bz-modal-f">'
      + '<button class="bz-btn" data-close>Cancel</button>'
      + '<button class="bz-btn is-danger" id="bz-dgo">'
      + (n > 0 ? 'Delete and unbrand ' + esc(n) + ' product' + (n === 1 ? '' : 's') : 'Delete') + '</button>'
      + '</div>'
    );

    document.getElementById('bz-dgo').onclick = async function(){
      try {
        // force=1 is what the operator just confirmed. Without it the API
        // refuses to unbrand products behind their back, which is the right
        // default and the wrong thing to hit after a dialog that spelled the
        // consequence out.
        await api('/brands/' + Number(brand.id) + (n > 0 ? '?force=1' : ''), 'DELETE');
        say(n > 0 ? 'Brand deleted — ' + n + ' product' + (n === 1 ? '' : 's') + ' now unbranded' : 'Brand deleted');
        closeModal();
        await refreshEverything();
      } catch (e) { say(e.message); }
    };
  }

  /* ------------------------------------------------- banner-only dialog */
  /* For a CATEGORY the banner cannot live in its own editor, because that
     editor is in category-tree-screen.blade.php and another lane owns that
     file this cycle. So the banner gets a dialog of its own, reached from a
     button this file injects into the category row.

     The PUT carries the category's CURRENT name, slug, parent and so on
     alongside the new banner. That is not redundancy for its own sake:
     CategoriesApiController::validated() requires name and slug on every
     update and rebuilds `seo` from what it is sent, so a partial body would
     fail validation and a body without `seo` would wipe the SEO overrides. */
  function bannerDialog(kind, row){
    if (!row) return;
    var isBrand = kind === 'brand';
    var where = isBrand
      ? '<code>/shop/?filter_brands=' + esc(row.slug) + '</code> and <code>/korean-skincare-brands/' + esc(row.slug) + '/</code>'
      : '<code>/product-category/' + esc(row.path || row.slug) + '/</code>';

    openModal(
      '<div class="bz-modal-h"><b>Banner — ' + esc(row.name) + '</b><button class="bz-x" data-close>✕</button></div>'
      + '<div class="bz-note" style="margin-bottom:12px">Shows at the top of ' + where + '.</div>'
      + bannerFields(row.banner)
      + '<div class="bz-modal-f">'
      + '<button class="bz-btn" data-close>Cancel</button>'
      + '<button class="bz-btn is-primary" id="bz-bnsave">Save banner</button>'
      + '</div>'
    );

    wireBannerFields(row.name || '', (row.banner || {}).image);

    document.getElementById('bz-bnsave').onclick = async function(){
      var banner = bannerPayload();

      try {
        if (isBrand) {
          await api('/brands/' + Number(row.id), 'PUT', {
            name: row.name,
            slug: row.slug,
            logo: row.logo || '',
            description: row.description || '',
            position: Number(row.position || 0),
            seo: row.seo || {},
            banner: banner
          });
        } else {
          await api('/categories/' + Number(row.id), 'PUT', {
            name: row.name,
            slug: row.slug,
            parent_id: row.parent_id == null ? null : Number(row.parent_id),
            description: row.description || '',
            image: row.image || '',
            position: Number(row.position || 0),
            seo: row.seo || {},
            banner: banner
          });
        }

        say(banner.enabled ? 'Banner saved and switched on' : 'Banner switched off');
        closeModal();
        await refreshEverything();
      } catch (e) { say(e.message); }
    };
  }

  /* Re-pull both lists and redraw whatever screen is open. The enhanced tab
     lives in another file and repaints itself on its own load(), so this only
     has to refresh the data behind the injected buttons. */
  async function refreshEverything(){
    try { await loadBrands(); } catch (e) {}
    try { await loadCats(); } catch (e) {}

    render();
    enhance();

    // The other screen redraws from its own state; ask it to reload so a
    // renamed brand does not sit there stale behind a fresh Edit button.
    if (typeof window.__ctReload === 'function') {
      try { window.__ctReload(); } catch (e) {}
    }
  }

  /* ================================================================ ENHANCE
     The Brands tab that already exists, given the buttons it is missing.

     Why a MutationObserver and not a one-off pass: that screen re-renders its
     whole #ct-root on every tab switch, every save and every reorder, which
     throws away anything injected. Watching #content means the buttons come
     back each time without this file knowing when that happens.

     Everything below is additive. Rows are not rebuilt, existing listeners
     are not touched, and each injection is guarded by a data attribute so a
     repeat pass over the same DOM does nothing. */
  var enhanceQueued = false;

  function enhance(){
    var root = document.getElementById('ct-root');
    if (!root) return;

    // Brand rows carry data-bid; category rows carry data-id. That is how the
    // other screen tells its two tabs apart in its own drag code, so it is a
    // contract of sorts rather than a guess.
    root.querySelectorAll('.ct-row[data-bid]').forEach(function(row){
      if (row.dataset.bzDone === '1') return;
      row.dataset.bzDone = '1';

      var id = Number(row.dataset.bid);
      var acts = row.querySelector('.ct-acts');
      if (!acts) return;

      acts.appendChild(mkBtn('Edit', 'ct-btn', function(){
        var b = byId(brands, id);
        if (b) editor(b); else say('Reloading brands — try again in a moment');
      }));

      acts.appendChild(mkBtn('Banner', 'ct-btn', function(){
        var b = byId(brands, id);
        if (b) bannerDialog('brand', b); else say('Reloading brands — try again in a moment');
      }));

      acts.appendChild(mkBtn('Delete', 'ct-btn is-danger', function(){
        var b = byId(brands, id);
        if (b) deleteDialog(b); else say('Reloading brands — try again in a moment');
      }));
    });

    root.querySelectorAll('.ct-row[data-id]').forEach(function(row){
      if (row.dataset.bzDone === '1') return;
      row.dataset.bzDone = '1';

      var id = Number(row.dataset.id);
      var acts = row.querySelector('.ct-acts');
      if (!acts) return;

      acts.appendChild(mkBtn('Banner', 'ct-btn', function(){
        var c = byId(cats, id);
        if (c) bannerDialog('category', c); else say('Reloading categories — try again in a moment');
      }));
    });

    // The Add button belongs in the brands card head, and only there: the
    // categories tab has its own #ct-add already.
    var brandRow = root.querySelector('.ct-row[data-bid]');
    var isBrandsTab = !!brandRow || /No brands yet/.test(root.textContent || '');

    if (isBrandsTab && !root.querySelector('#bz-ct-add')) {
      var head = root.querySelector('.ct-head');
      if (head && !root.querySelector('#ct-add')) {
        var add = mkBtn('+ Add brand', 'ct-btn is-primary', function(){ editor(null); });
        add.id = 'bz-ct-add';
        head.appendChild(add);
      }
    }
  }

  function mkBtn(label, cls, fn){
    var b = document.createElement('button');
    b.type = 'button';
    b.className = cls;
    b.textContent = label;
    b.onclick = fn;
    return b;
  }

  function queueEnhance(){
    if (enhanceQueued) return;
    enhanceQueued = true;
    // Coalesced: one repaint of that screen fires many mutation records and
    // each would otherwise walk the whole tree.
    requestAnimationFrame(function(){
      enhanceQueued = false;
      enhance();
    });
  }

  function watch(){
    var content = document.getElementById('content');
    if (!content || content.dataset.bzWatch === '1') return;
    content.dataset.bzWatch = '1';

    try {
      new MutationObserver(queueEnhance).observe(content, {childList: true, subtree: true});
    } catch (e) {
      // No observer: the injected buttons simply do not appear, and the
      // Brands screen this file registers is unaffected. Not worth failing
      // the console's boot over.
    }

    queueEnhance();
  }

  /* -------------------------------------------------------- sidebar entry */
  /*
   * Through the shared helper in app.blade.php. This file was modelled on
   * category-tree and inherited its `[data-go="products"]` fallback, which has
   * never matched anything -- there has never been a 'products' row in this
   * console. It read like a net and was decoration, and it is gone.
   *
   * 'category-tree' is the real preference: Brands sits directly under
   * Categories. It is injected by another partial rather than declared in NAV,
   * so 'catalog' follows it as the half that is always there. Neither is load
   * bearing any more -- if both were renamed the helper would still put this
   * row at the end of the Catalog group, and if that group went too it would
   * add the row anyway and log which name it could not find.
   */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Brands',
      icon:   '<path d="M20.6 13.4 12 22l-8.6-8.6a5 5 0 0 1 0-7.1 5 5 0 0 1 7.1 0L12 7.8l1.5-1.5a5 5 0 0 1 7.1 7.1Z"/>',
      group:  'Catalog',
      after:  ['category-tree', 'catalog']
    });
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) {
      var out = previousGo.apply(this, arguments);
      // Another screen just replaced #content. If it is the Categories &
      // Brands screen its rows want our buttons, and the observer will say so
      // -- this is only belt and braces for the very first paint.
      queueEnhance();
      return out;
    }

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });

    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]')
             || document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog';
    if (title) title.textContent = 'Brands';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.innerHTML = '<div id="bz-root"></div>';

    load();
    return undefined;
  };

  /* ----------------------------------------------------------------- init */
  function boot(){
    addNavEntry();
    watch();

    // The injected buttons need the brand and category rows in hand before
    // they are clicked, and the console may open straight onto the Categories
    // & Brands screen. Both are best-effort: a failure here leaves the tab
    // exactly as it is today rather than breaking it.
    loadBrands().then(enhance).catch(function(){});
    loadCats().catch(function(){});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  /* Exposed for the layout test, which drives the screen in a real browser
     without an admin session to log into, and for the integrator: if the
     Brands tab gains its buttons natively, these are what its handlers call. */
  window.KbbBrandEditor = {
    open: function(brand){ editor(brand || null); },
    banner: function(kind, row){ bannerDialog(kind, row); },
    remove: function(brand){ deleteDialog(brand); },
    enhance: enhance
  };

  window.__bzRenderForTest = function(fixtureBrands, fixtureCats){
    brands = fixtureBrands || [];
    cats = fixtureCats || [];
    busy = false;
    render();
  };
  window.__bzEditor = function(id){ editor(id == null ? null : byId(brands, id)); };
  window.__bzBanner = function(kind, id){
    bannerDialog(kind, byId(kind === 'brand' ? brands : cats, id));
  };
  window.__bzDelete = function(id){ deleteDialog(byId(brands, id)); };
})();
</script>
@endverbatim
