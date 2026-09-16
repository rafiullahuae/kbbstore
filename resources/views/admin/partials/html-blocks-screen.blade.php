{{--
    Content - HTML Blocks. (Lane BC)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go, toast() and the design tokens
    this screen borrows. Same arrangement, and for the same reasons, as
    admin/partials/coupon-usage-screen.blade.php; that is the precedent.

    WHAT WAS HERE BEFORE. FRAME_SRC mapped 'htmlblocks' to a standalone file
    called kbb-admin-blocks.html. That file has never existed in this repo --
    established the way the LANE AV comment block establishes it for its
    siblings, not assumed -- so every visit probed for it, the probe failed,
    and frameNotBuiltHTML() printed "HTML Blocks isn't installed yet".

    'htmlblocks' is therefore added to LIVE_RENDERED in app.blade.php. That is
    path 1 of the two the LANE AV block sets out, and it is not optional
    decoration: without it mountFrame() still fires a HEAD for a file that is
    not there on every single visit, and the response lands after this screen
    has already drawn over it. A guaranteed 404 per visit, for markup no one
    sees. LIVE_RENDERED makes mountFrame return before it touches the network,
    leaving Lane AM's honest startup message for the one case it describes --
    this script failing to load at all.

    UNLIKE the three screens included beside this one, this screen does NOT
    append a sidebar entry: 'htmlblocks' is already in NAV and in TITLES, so
    renderFrame() already sets the crumb, the title and the active nav item.
    Appending a second entry would show the owner two "HTML Blocks" rows.

    WHAT "PLACE" MEANS, and why nothing new was invented for it. App\Support\
    Shortcodes already existed; AppServiceProvider already registered a Blade
    directive for it whose own comment named HTML blocks as a thing it was for.
    This lane added [kbb_block slug="..."] to that engine and pointed
    store/page.blade.php and store/post.blade.php at the directive -- which no
    view in the repo was using, so the engine had never rendered anything on
    the storefront at all.

    EVERY CONTROL ON THIS SCREEN READS OR WRITES SOMETHING REAL. Name and
    handle and status and content are columns; handle is what
    Shortcodes::block() looks up; status is the filter that decides whether a
    block renders at all; "Used in" is a scan of pages.content and posts.body
    done with the renderer's own pattern. Search and the status filter act on
    the list in front of you. There is no toggle here that nothing obeys.

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
   HTML Blocks. Every rule is prefixed hb- and appears nowhere else in the
   console, so this file can never restyle another screen by accident.

   The layout rule the other lane screens record, kept here because the owner
   reviews on a phone: nothing may be wider than its column at 390px. The
   table sits in its own scroller, the editor is a single column that only
   becomes two when there is room, and no element carries a min-width larger
   than the narrowest content box.
--------------------------------------------------------------------------- */

/* min-width:0 on the grid AND on its children is load-bearing, not tidiness.
   A grid item's default min-width is auto -- "at least as wide as my content"
   -- so a card refuses to shrink below the width of the table inside it, the
   scroller's overflow-x never gets the chance to act, and the whole screen is
   stretched to the table's natural width. */
.hb-wrap{display:grid;gap:16px;min-width:0}
.hb-wrap > *{min-width:0}

.hb-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
         border-radius:var(--r,18px);padding:16px;min-width:0}
.hb-head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}
.hb-title{font-weight:650;font-size:15px}
.hb-sub{color:var(--ink-soft,#626c80);font-size:12.5px;line-height:1.5}

.hb-tools{display:flex;gap:8px;flex-wrap:wrap;min-width:0}
.hb-tools input,.hb-tools select{flex:1 1 150px;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
  background:var(--surface,#fff);color:inherit}

.hb-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;max-width:100%}
.hb-table{width:100%;border-collapse:collapse;font-size:13px}
.hb-table th,.hb-table td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--border,#e6e9f2);
  white-space:nowrap;vertical-align:middle}
.hb-table th{font-weight:600;color:var(--ink-soft,#626c80);font-size:11.5px;
  text-transform:uppercase;letter-spacing:.04em}
.hb-table tbody tr{cursor:pointer}
.hb-table tbody tr:hover{background:rgba(127,127,127,.06)}
.hb-name{font-weight:600}

.hb-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;
  font-size:11.5px;font-weight:600;border:1px solid transparent}
.hb-pill.live{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.hb-pill.draft{background:var(--surface-2,#f2f4fb);color:var(--ink-soft,#626c80);
  border-color:var(--border,#e6e9f2)}

.hb-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;
  background:var(--surface-2,#f2f4fb);border:1px solid var(--border,#e6e9f2);
  border-radius:var(--r-xs,9px);padding:3px 8px;display:inline-block;max-width:100%;
  overflow:hidden;text-overflow:ellipsis;vertical-align:middle}

.hb-empty{padding:34px 16px;text-align:center;color:var(--ink-soft,#626c80);font-size:13px;line-height:1.6}
.hb-empty b{display:block;color:var(--ink,#101729);font-size:15px;margin-bottom:6px}

/* ---- editor ---- */
.hb-form{display:grid;gap:14px;min-width:0}
.hb-form > *{min-width:0}
.hb-row{display:grid;gap:14px;grid-template-columns:1fr;min-width:0}
@media (min-width:760px){ .hb-row.two{grid-template-columns:1fr 1fr} }
.hb-field{display:grid;gap:5px;min-width:0}
.hb-field label{font-size:12px;font-weight:600;color:var(--ink-2,#3c465c)}
.hb-field .hint{font-size:11.5px;color:var(--ink-soft,#626c80);line-height:1.5}
.hb-field input,.hb-field select,.hb-field textarea{width:100%;min-width:0;box-sizing:border-box;
  padding:9px 11px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e9f2);
  border-radius:var(--r-xs,9px);background:var(--surface,#fff);color:inherit}
.hb-field textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;
  line-height:1.6;min-height:240px;resize:vertical;white-space:pre;overflow:auto}
.hb-field .bad{border-color:var(--red,#e3493f)}
.hb-err{color:var(--red,#e3493f);font-size:11.5px;min-height:1px}

.hb-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.hb-actions .spacer{flex:1 1 auto;min-width:0}

.hb-preview{border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
  background:#fff;overflow:hidden;min-width:0}
.hb-preview iframe{display:block;width:100%;height:280px;border:0;background:#fff}

.hb-used{display:grid;gap:6px;font-size:12.5px;min-width:0}
.hb-used a,.hb-used span.u{display:block;padding:7px 10px;border:1px solid var(--border,#e6e9f2);
  border-radius:var(--r-xs,9px);background:var(--surface-2,#f2f4fb);color:inherit;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.hb-note{background:var(--surface-2,#f2f4fb);border:1px solid var(--border,#e6e9f2);
  border-radius:var(--r-xs,9px);padding:10px 12px;font-size:12px;color:var(--ink-soft,#626c80);
  line-height:1.6}

.hb-banner{border-radius:var(--r-xs,9px);padding:10px 12px;font-size:12.5px;line-height:1.5}
.hb-banner.ok{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.hb-banner.bad{background:rgba(227,73,63,.1);color:var(--red,#e3493f)}
</style>

<script>
(function(){
  'use strict';

  /* The console builds its sidebar and its router before this runs. NAV,
     TITLES and ADMIN_BASE are const inside that script's own scope, so neither
     can be read from here -- the router is wrapped instead, which is the
     surface the console already exposes for exactly this. */

  var SCREEN = 'htmlblocks';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var list = null;        // the block list payload, or null before it loads
  var editing = null;     // {id|null, name, slug, status, content, used_in}
  var slugTouched = false;// has the owner typed a handle of their own?
  var query = '';
  var statusFilter = '';
  var banner = null;      // {kind:'ok'|'bad', text}
  var fieldErrors = {};
  var busy = false;
  var seq = 0;            // guards against an older response landing last
  var mounted = null;     // the `editing` object the mounted editor belongs to

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* Same shape as the sibling lane screens: the admin panel is served from a
     secret path, so /admin-api is resolved relative to the current one rather
     than hard-coded. */
  async function api(path, method, body){
    var opts = {
      method: method || 'GET',
      headers: {'Accept':'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN')},
      credentials: 'same-origin'
    };

    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }

    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = payload;
      throw err;
    }

    return payload;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function icon(d){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" ' +
           'stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">' + d + '</svg>';
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* The handle rule, mirrored from the server's regex so the field can say no
     before a round trip. The SERVER is still the authority -- it runs
     Str::slug() and the same pattern -- this only saves the owner a refusal
     they can see coming. */
  function slugify(s){
    return String(s || '').toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  }

  function shortcodeFor(slug){ return '[kbb_block slug="' + slug + '"]'; }

  function when(iso){
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString(undefined, {day:'numeric', month:'short', year:'numeric'});
  }

  async function copy(text){
    try { await navigator.clipboard.writeText(text); say('Shortcode copied'); return; }
    catch (e) {}
    // Clipboard API needs a secure context; this path is the fallback for the
    // times it is not there, not decoration.
    var t = document.createElement('textarea');
    t.value = text;
    t.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(t);
    t.select();
    try { document.execCommand('copy'); say('Shortcode copied'); } catch (e2) { say('Copy failed'); }
    document.body.removeChild(t);
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    /* previousGo runs FIRST, for every id including this one: it is what sets
       the crumb, the page title and the active sidebar row from TITLES, and
       for this id it now stops at LIVE_RENDERED without touching the network.
       Then this screen draws over the placeholder it left. */
    var out = previousGo.apply(this, arguments);

    if (id !== SCREEN) return out;

    editing = null;
    banner = null;
    fieldErrors = {};
    render();
    load();

    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function load(){
    var mine = ++seq;

    try {
      var payload = await api('/blocks');
      if (mine !== seq) return;
      list = payload;
    } catch (e) {
      if (mine !== seq) return;
      list = {blocks: [], failed: true, status: e.status || 0};
    }

    /* A list refresh must never redraw an open editor.
       CAUGHT IN CHROMIUM, not reasoned about: leave() calls render() and then
       load(), and at 390px the list response landed AFTER the owner had opened
       "New block" and typed a name. render() then rebuilt the editor from the
       `editing` object, which still held the empty strings it was created
       with, so the name and the derived handle were silently wiped and the
       save came back 422. At 1920 and 1280 the same response happened to land
       first and it looked fine -- an intermittent data-loss bug, which is the
       worst kind to leave in a screen someone types into. */
    if (editing) return;

    render();
  }

  /* Read the mounted editor's fields back into the object it was rendered for,
     so that a re-render for any other reason cannot discard typed work. Only
     ever called when the mounted editor really does belong to `editing` --
     otherwise open() would copy the previous block's fields onto the new one. */
  function captureEditor(){
    if (!mounted || mounted !== editing) return;

    var name = document.querySelector('#hb-f-name');
    var slug = document.querySelector('#hb-f-slug');
    var status = document.querySelector('#hb-f-status');
    var content = document.querySelector('#hb-f-content');

    if (!name || !slug || !status || !content) return;

    editing.name = name.value;
    editing.slug = slug.value;
    editing.status = status.value;
    editing.content = content.value;
  }

  /* ---------------------------------------------------------------- views */
  function render(){
    var box = document.querySelector('#content');
    if (!box) return;

    captureEditor();

    box.innerHTML = '<div class="wrap"><div class="hb-wrap" id="hb-root"></div></div>';
    var root = document.querySelector('#hb-root');

    root.innerHTML = editing ? editorHTML() : listHTML();

    mounted = editing;

    if (editing) bindEditor(); else bindList();

    box.scrollTop = 0;
  }

  function bannerHTML(){
    if (!banner) return '';
    return '<div class="hb-banner ' + (banner.kind === 'bad' ? 'bad' : 'ok') + '">' + esc(banner.text) + '</div>';
  }

  function visibleBlocks(){
    var rows = (list && list.blocks) || [];
    var q = query.trim().toLowerCase();

    return rows.filter(function(b){
      if (statusFilter && b.status !== statusFilter) return false;
      if (!q) return true;
      return (b.name || '').toLowerCase().indexOf(q) >= 0
          || (b.slug || '').toLowerCase().indexOf(q) >= 0;
    });
  }

  function listHTML(){
    if (!list) {
      return '<div class="hb-card"><div class="hb-sub">Loading blocks…</div></div>';
    }

    if (list.failed) {
      /* The honest version. A 404 here means one specific thing and it is
         worth naming, because it is the state this whole package is shipped
         to leave behind: the routes exist in the tree but the server is still
         serving a compiled route table that predates them. */
      return '<div class="hb-card"><div class="hb-empty">' +
        '<b>Blocks could not be loaded</b>' +
        (list.status === 404
          ? 'The /admin-api/blocks endpoint answered 404. That is what a stale compiled route cache looks like — run the update package’s migrations, which clear it.'
          : 'The server answered ' + esc(String(list.status || 'nothing')) + '. Reload the page; if it keeps happening the admin API is not reachable.') +
        '</div></div>';
    }

    var rows = visibleBlocks();
    var total = (list.blocks || []).length;

    var head =
      '<div class="hb-card">' +
        '<div class="hb-head">' +
          '<div>' +
            '<div class="hb-title">Reusable HTML blocks</div>' +
            '<div class="hb-sub">Write a snippet once, then place it in any page or post by pasting its shortcode. Editing the block updates every page that uses it.</div>' +
          '</div>' +
          '<button class="btn" id="hb-new">' + icon('<path d="M12 5v14M5 12h14"/>') + ' New block</button>' +
        '</div>' +
        (total ? '<div class="hb-tools" style="margin-top:14px">' +
          '<input id="hb-q" type="search" placeholder="Search name or handle" value="' + esc(query) + '">' +
          '<select id="hb-status">' +
            '<option value="">All statuses</option>' +
            '<option value="published"' + (statusFilter === 'published' ? ' selected' : '') + '>Published</option>' +
            '<option value="draft"' + (statusFilter === 'draft' ? ' selected' : '') + '>Draft</option>' +
          '</select>' +
        '</div>' : '') +
      '</div>';

    if (!total) {
      return bannerHTML() + head +
        '<div class="hb-card"><div class="hb-empty">' +
          '<b>No blocks yet</b>' +
          'A block is a piece of HTML you want in more than one place — a delivery note, a payment-logo strip, a seasonal banner. ' +
          'Create one and it gets a shortcode you can paste into any page or post.' +
        '</div></div>';
    }

    if (!rows.length) {
      return bannerHTML() + head +
        '<div class="hb-card"><div class="hb-empty"><b>Nothing matches</b>' +
        'No block matches that search and filter.</div></div>';
    }

    var body = rows.map(function(b){
      return '<tr data-hb-open="' + b.id + '">' +
        '<td class="hb-name">' + esc(b.name) + '</td>' +
        '<td><span class="hb-code">' + esc(b.shortcode) + '</span></td>' +
        '<td><span class="hb-pill ' + (b.status === 'published' ? 'live' : 'draft') + '">' +
          (b.status === 'published' ? 'Published' : 'Draft') + '</span></td>' +
        '<td>' + (b.used_in ? b.used_in + (b.used_in === 1 ? ' place' : ' places') : '—') + '</td>' +
        '<td>' + esc(when(b.updated_at)) + '</td>' +
        '<td><button class="btn ghost sm" data-copy="' + esc(b.shortcode) + '">Copy</button></td>' +
      '</tr>';
    }).join('');

    return bannerHTML() + head +
      '<div class="hb-card"><div class="hb-scroll"><table class="hb-table">' +
        '<thead><tr><th>Name</th><th>Shortcode</th><th>Status</th><th>Used in</th><th>Updated</th><th></th></tr></thead>' +
        '<tbody>' + body + '</tbody>' +
      '</table></div></div>';
  }

  function editorHTML(){
    var b = editing;
    var isNew = !b.id;
    var slug = b.slug || slugify(b.name);

    var used = (b.used_in || []).map(function(u){
      return '<span class="u">' + esc(u.title) + ' <span style="color:var(--ink-faint,#97a0b2)">· ' +
             esc(u.type) + ' · ' + esc(u.status) + '</span></span>';
    }).join('');

    return bannerHTML() +
      '<div class="hb-card">' +
        '<div class="hb-head">' +
          '<div>' +
            '<div class="hb-title">' + (isNew ? 'New block' : esc(b.name)) + '</div>' +
            '<div class="hb-sub">' + (isNew
              ? 'Give it a name, write the HTML, then place it with the shortcode below.'
              : 'Changes take effect on every page that places this block.') + '</div>' +
          '</div>' +
          '<button class="btn ghost" id="hb-back">' + icon('<path d="m15 18-6-6 6-6"/>') + ' All blocks</button>' +
        '</div>' +
      '</div>' +

      '<div class="hb-card"><div class="hb-form">' +
        '<div class="hb-row two">' +
          '<div class="hb-field">' +
            '<label for="hb-f-name">Name</label>' +
            '<input id="hb-f-name" type="text" maxlength="120" value="' + esc(b.name) + '"' +
              (fieldErrors.name ? ' class="bad"' : '') + '>' +
            '<div class="hint">Only you see this. It is how the block is listed and searched.</div>' +
            '<div class="hb-err">' + esc(fieldErrors.name || '') + '</div>' +
          '</div>' +
          '<div class="hb-field">' +
            '<label for="hb-f-slug">Handle</label>' +
            '<input id="hb-f-slug" type="text" maxlength="120" value="' + esc(slug) + '"' +
              (fieldErrors.slug ? ' class="bad"' : '') + '>' +
            '<div class="hint">The name the shortcode uses. Lowercase letters, numbers and dashes.</div>' +
            '<div class="hb-err">' + esc(fieldErrors.slug || '') + '</div>' +
          '</div>' +
        '</div>' +

        '<div class="hb-row two">' +
          '<div class="hb-field">' +
            '<label for="hb-f-status">Status</label>' +
            '<select id="hb-f-status">' +
              '<option value="published"' + (b.status === 'published' ? ' selected' : '') + '>Published — renders on the storefront</option>' +
              '<option value="draft"' + (b.status !== 'published' ? ' selected' : '') + '>Draft — renders nothing, anywhere</option>' +
            '</select>' +
            '<div class="hint">Draft is the off switch: the shortcode stays in your pages and simply renders nothing until you publish again.</div>' +
          '</div>' +
          '<div class="hb-field">' +
            '<label>Shortcode</label>' +
            '<div class="hb-actions">' +
              '<span class="hb-code" id="hb-sc">' + esc(shortcodeFor(slug)) + '</span>' +
              '<button class="btn ghost sm" id="hb-copy" type="button">Copy</button>' +
            '</div>' +
            '<div class="hint">Paste this into a page or post. It updates as you change the handle.</div>' +
          '</div>' +
        '</div>' +

        '<div class="hb-field">' +
          '<label for="hb-f-content">HTML</label>' +
          '<textarea id="hb-f-content" spellcheck="false">' + esc(b.content || '') + '</textarea>' +
          '<div class="hb-err">' + esc(fieldErrors.content || '') + '</div>' +
        '</div>' +

        '<div class="hb-field">' +
          '<label>Preview</label>' +
          '<div class="hb-preview"><iframe id="hb-prev" sandbox title="Block preview"></iframe></div>' +
          '<div class="hint">Your HTML, on its own, with scripts disabled. It is not the storefront: the theme’s styles are not loaded here, and a [kbb_products] or [kbb_block] shortcode inside this block is expanded when the page is served, not in this box.</div>' +
        '</div>' +

        '<div class="hb-actions">' +
          '<button class="btn" id="hb-save"' + (busy ? ' disabled' : '') + '>' + (busy ? 'Saving…' : 'Save block') + '</button>' +
          '<button class="btn ghost" id="hb-cancel" type="button">Cancel</button>' +
          '<span class="spacer"></span>' +
          (isNew ? '' : '<button class="btn danger" id="hb-del" type="button">Delete</button>') +
        '</div>' +
      '</div></div>' +

      (isNew ? '' :
      '<div class="hb-card">' +
        '<div class="hb-title" style="margin-bottom:4px">Used in</div>' +
        (used
          ? '<div class="hb-sub" style="margin-bottom:10px">Pages and posts whose content places this block.</div><div class="hb-used">' + used + '</div>'
          : '<div class="hb-sub">Nothing places this block yet. Copy the shortcode above into a page or post.</div>') +
      '</div>') +

      '<div class="hb-note">' +
        'Blocks are rendered into the page on the server, so their HTML is in the source a search engine sees. ' +
        'Where a block is placed is decided by the page, not here — the same block can appear in as many pages and posts as you paste the shortcode into.' +
      '</div>';
  }

  /* ------------------------------------------------------------- bindings */
  function bindList(){
    var neu = document.querySelector('#hb-new');
    if (neu) neu.onclick = function(){
      editing = {id:null, name:'', slug:'', status:'published', content:'', used_in:[]};
      slugTouched = false;
      fieldErrors = {};
      banner = null;
      render();
    };

    var q = document.querySelector('#hb-q');
    if (q) q.oninput = function(){
      query = q.value;
      var at = q.selectionStart;
      render();
      var again = document.querySelector('#hb-q');
      if (again) { again.focus(); try { again.setSelectionRange(at, at); } catch (e) {} }
    };

    var st = document.querySelector('#hb-status');
    if (st) st.onchange = function(){ statusFilter = st.value; render(); };

    document.querySelectorAll('#hb-root [data-copy]').forEach(function(btn){
      btn.onclick = function(e){ e.stopPropagation(); copy(btn.dataset.copy); };
    });

    /* data-hb-open, NOT data-open. app.blade.php installs a document-level
       click handler for the Homepage skin pickers that claims `data-open`
       globally: it reads the attribute, looks up [data-pop="<value>"] and
       calls pop.classList.toggle() with no null check. A row carrying a plain
       data-open therefore threw "Cannot read properties of null (reading
       'classList')" on every click — caught here as a real pageerror in
       Chromium, not reasoned about. The prefixed name sidesteps it entirely.
       The unguarded handler itself is still there and is reported upward;
       it is in another lane's region of that file. */
    document.querySelectorAll('#hb-root tr[data-hb-open]').forEach(function(tr){
      tr.onclick = function(){ open(parseInt(tr.dataset.hbOpen, 10)); };
    });
  }

  async function open(id){
    var mine = ++seq;

    try {
      var payload = await api('/blocks/' + id);
      if (mine !== seq) return;
      editing = payload.block;
      editing.used_in = payload.used_in || [];
      slugTouched = true;   // an existing block's handle is already its own
      fieldErrors = {};
      banner = null;
      render();
    } catch (e) {
      banner = {kind:'bad', text:'That block could not be opened (' + (e.status || 'no response') + ').'};
      render();
    }
  }

  function bindEditor(){
    var name = document.querySelector('#hb-f-name');
    var slug = document.querySelector('#hb-f-slug');
    var status = document.querySelector('#hb-f-status');
    var content = document.querySelector('#hb-f-content');
    var sc = document.querySelector('#hb-sc');

    function syncShortcode(){
      if (sc) sc.textContent = shortcodeFor(slug ? slug.value : '');
    }

    /* Field values are read out of the DOM on save rather than mirrored into
       `editing` on every keystroke, so typing never re-renders the textarea
       and never moves the caret. `editing` is only refreshed where a redraw
       is about to happen anyway. */
    if (name) name.oninput = function(){
      if (!slugTouched && slug) { slug.value = slugify(name.value); syncShortcode(); }
    };

    if (slug) slug.oninput = function(){ slugTouched = true; syncShortcode(); };

    var copyBtn = document.querySelector('#hb-copy');
    if (copyBtn) copyBtn.onclick = function(){ copy(shortcodeFor(slug ? slug.value : '')); };

    /* srcdoc + sandbox with no allow-scripts: the block's own markup is shown,
       and any script inside it cannot run in the admin document. The owner
       writes this HTML themselves, so this is not a trust boundary against
       them -- it is so a half-typed <script> or a stray onerror cannot take
       the console down while they are still writing it. */
    var prev = document.querySelector('#hb-prev');
    function paint(){
      if (!prev) return;
      prev.srcdoc = '<!doctype html><meta charset="utf-8">' +
        '<style>body{margin:12px;font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#101729}' +
        'img{max-width:100%;height:auto}</style>' + (content ? content.value : '');
    }
    paint();

    if (content) {
      var timer = null;
      content.oninput = function(){ clearTimeout(timer); timer = setTimeout(paint, 250); };
    }

    var back = document.querySelector('#hb-back');
    var cancel = document.querySelector('#hb-cancel');
    function leave(){ editing = null; fieldErrors = {}; banner = null; render(); load(); }
    if (back) back.onclick = leave;
    if (cancel) cancel.onclick = leave;

    var save = document.querySelector('#hb-save');
    if (save) save.onclick = function(){
      submit({
        name: name ? name.value.trim() : '',
        slug: slug ? slug.value.trim() : '',
        status: status ? status.value : 'draft',
        content: content ? content.value : ''
      });
    };

    var del = document.querySelector('#hb-del');
    if (del) del.onclick = function(){ remove(false); };
  }

  async function submit(values){
    if (busy) return;

    // Hold what was typed, so a validation failure redraws the form with the
    // owner's own words in it rather than with whatever was last saved.
    editing.name = values.name;
    editing.slug = values.slug;
    editing.status = values.status;
    editing.content = values.content;

    busy = true;
    fieldErrors = {};
    banner = null;
    render();

    try {
      var payload = editing.id
        ? await api('/blocks/' + editing.id, 'PUT', values)
        : await api('/blocks', 'POST', values);

      busy = false;
      say(editing.id ? 'Block saved' : 'Block created');
      editing = null;
      banner = {kind:'ok', text:'“' + payload.block.name + '” saved. Place it with ' + shortcodeFor(payload.block.slug) + '.'};
      render();
      load();
    } catch (e) {
      busy = false;

      if (e.status === 422 && e.body && e.body.errors) {
        Object.keys(e.body.errors).forEach(function(k){ fieldErrors[k] = e.body.errors[k][0]; });
        banner = {kind:'bad', text:'That could not be saved — see the fields below.'};
      } else {
        banner = {kind:'bad', text:'Saving failed (' + (e.status || 'no response') + '). Nothing was changed.'};
      }

      render();
    }
  }

  async function remove(force){
    if (busy || !editing || !editing.id) return;

    busy = true;
    render();

    try {
      await api('/blocks/' + editing.id + (force ? '?force=1' : ''), 'DELETE');
      busy = false;
      say('Block deleted');
      editing = null;
      banner = {kind:'ok', text:'Block deleted.'};
      render();
      load();
    } catch (e) {
      busy = false;

      /* The in-use refusal is a question, not a failure. The server counts
         the pages that place this block and declines; the owner is shown the
         count and can mean it. */
      if (e.status === 422 && e.body && e.body.error === 'block_in_use') {
        var used = (e.body.used_in || []).map(function(u){ return '• ' + u.title + ' (' + u.type + ')'; }).join('\n');
        var ok = window.confirm(e.body.message + '\n\n' + used + '\n\nDelete it anyway?');
        if (ok) return remove(true);
        render();
        return;
      }

      banner = {kind:'bad', text:'Deleting failed (' + (e.status || 'no response') + '). Nothing was changed.'};
      render();
    }
  }

  /* ------------------------------------------------------------------ init
     The console's boot block resolves ?go= and #hash against TITLES and calls
     go() at parse time -- BEFORE this file runs, because this is included
     after it. 'htmlblocks' is in TITLES, so a bookmark straight to this screen
     lands on the placeholder mountFrame left and stops there. Re-dispatching
     once, and only when this really is the screen being asked for, is what
     makes that bookmark work. */
  function bootIfCurrent(){
    var q = new URLSearchParams(window.location.search).get('go');
    var h = (window.location.hash || '').replace('#', '');

    if ((q || h) === SCREEN) window.go(SCREEN);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootIfCurrent);
  } else {
    bootIfCurrent();
  }
})();
</script>
@endverbatim
