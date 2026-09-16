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
.mp-up.is-bad .mp-up-track > i{background:#e3493f}
.mp-up.is-bad .mp-up-pct{color:#e3493f}
@media (prefers-reduced-motion: reduce){.mp-up-track > i{transition:none}}
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

    el.querySelector('.mp-box').addEventListener('click', function(e){
      var tile = e.target.closest ? e.target.closest('[data-mp-url]') : null;
      if (tile) toggle(tile.getAttribute('data-mp-url'));
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
      + '<span class="mp-thumb"><img src="' + esc(it.url) + '" alt="" loading="lazy">'
      +   '<span class="mp-tick">✓</span></span>'
      + '<span class="mp-cap"><span class="mp-name">' + esc(name) + '</span>'
      +   '<span class="mp-dim">' + esc([dim, bytes(it.size)].filter(Boolean).join(' · ')) + '</span>'
      + '</span></button>';
  }

  function paint(){
    var body = el.querySelector('#mp-body');

    var head = uploads.length
      ? '<div class="mp-ups">' + uploads.map(function(u, i){
          var pct = u.state === 'done' ? 100 : (u.pct || 0);
          return '<div class="mp-up' + (u.state === 'failed' ? ' is-bad' : '') + '" data-mp-up="' + i + '">'
            + '<div class="mp-up-top"><span class="mp-up-name">' + esc(u.name) + '</span>'
            +   '<span class="mp-up-pct">' + esc(u.state === 'failed' ? (u.error || 'Failed') : pct + '%') + '</span></div>'
            + '<div class="mp-up-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
            +   ' aria-valuenow="' + pct + '"><i style="width:' + pct + '%"></i></div></div>';
        }).join('') + '</div>'
      : '';

    if (busy && !items.length) {
      body.innerHTML = head + '<p class="mp-note">Loading your images…</p>';
      return;
    }

    if (!items.length) {
      body.innerHTML = head + '<p class="mp-note">'
        + (query ? 'No image matches “' + esc(query) + '”.' : 'No images yet. Use <b>Upload new</b> to add the first one.')
        + '</p>';
      paintSelection();
      return;
    }

    body.innerHTML = head + '<div class="mp-grid">' + items.map(tileHTML).join('') + '</div>';
    applyCols();
    el.querySelector('#mp-more').hidden = page >= pages;
    paintSelection();
  }

  /* Patched in place, exactly as the product editor's own bar is: repainting
     the dialog on every progress event would rebuild the grid dozens of times a
     second and drop the operator's selection with it. */
  function paintUploads(){
    uploads.forEach(function(u, i){
      var row = el.querySelector('[data-mp-up="' + i + '"]');
      if (!row) return;
      var pct = u.state === 'done' ? 100 : (u.pct || 0);
      var bar = row.querySelector('.mp-up-track > i');
      var track = row.querySelector('.mp-up-track');
      var lab = row.querySelector('.mp-up-pct');
      if (bar) bar.style.width = pct + '%';
      if (track) track.setAttribute('aria-valuenow', String(pct));
      if (lab) lab.textContent = u.state === 'failed' ? (u.error || 'Failed') : pct + '%';
      row.className = 'mp-up' + (u.state === 'failed' ? ' is-bad' : '');
    });
  }

  async function load(append){
    busy = true;
    if (!append) { items = []; }
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

  function upload(file, onProgress){
    return new Promise(function(resolve, reject){
      var fd = new FormData();
      fd.append('file', file);
      fd.append('folder', (opts && opts.folder) || 'products');

      var xhr = new XMLHttpRequest();
      xhr.open('POST', apiBase() + '/media/upload', true);
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-XSRF-TOKEN', cookie('XSRF-TOKEN'));

      if (xhr.upload) {
        xhr.upload.onprogress = function(e){
          if (e.lengthComputable && e.total > 0) onProgress(Math.min(99, Math.round(e.loaded / e.total * 100)));
        };
        xhr.upload.onload = function(){ onProgress(99); };
      }

      xhr.onload = function(){
        var b = null;
        try { b = JSON.parse(xhr.responseText); } catch (err) { b = null; }
        if (xhr.status >= 200 && xhr.status < 300) { onProgress(100); resolve(b && b.url); return; }
        var msg = (b && (b.message || b.error)) || ('Upload failed (' + xhr.status + ')');
        reject(new Error(String(msg).slice(0, 60)));
      };
      xhr.onerror = function(){ reject(new Error('Could not reach the server')); };
      xhr.send(fd);
    });
  }

  async function takeFiles(files){
    var list = Array.prototype.slice.call(files || []);
    if (!list.length) return;

    uploads = list.map(function(f){ return { name: f.name || 'image', pct: 0, state: 'waiting' }; });
    paint();

    var fresh = [];

    for (var i = 0; i < list.length; i++) {
      var row = uploads[i];
      row.state = 'sending';
      paintUploads();

      try {
        var url = await upload(list[i], (function(r){
          return function(p){ r.pct = p; paintUploads(); };
        })(row));

        if (url) { row.state = 'done'; fresh.push(url); }
        else { row.state = 'failed'; row.error = 'No image came back'; }
      } catch (e) {
        /* One bad file does not abandon the rest — the same lesson the product
           editor's uploader learned: a run that stops at the first failure
           silently drops everything after it. */
        row.state = 'failed';
        row.error = (e && e.message) || 'Failed';
      }
      paintUploads();
    }

    var failed = uploads.filter(function(u){ return u.state === 'failed'; });
    uploads = failed;      // successes disappear; failures stay on screen

    /* A new upload lands IN THE LIBRARY FIRST and is then pre-ticked, which is
       the flow the owner asked for: the file joins the library rather than
       being attached straight to whatever opened the picker. Page 1 without a
       search, because the newest rows are what the grid orders first and a
       search still in the box would hide the thing just uploaded. */
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
