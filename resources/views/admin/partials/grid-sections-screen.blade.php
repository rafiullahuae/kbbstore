{{--
    Appearance → Product grids. (Lane GS — Phase 23)

    The owner:

      "DO ONE thing. prepare a proper grid section with all controls and it can
       be use anywhere, and can be edit that specific grid section. so this case
       we can re-use this grid section anywhere multiple times with different
       products etc selection."

    ── WHERE EACH PART OF THE ASK IS ON THIS SCREEN ──────────────────────────

      "re-use this grid section anywhere multiple times"  → card 1, Add a grid
      "can be edit that specific grid section"            → card 2, one editor
      "choose the brand, category, manual products"       → editor, Which products
      "4 by default on desktop"                           → editor, Layout
      "on mobile careousel"                               → editor, Layout
      "on desktop also give control to make it carousel"  → editor, Layout
      "bottom view all button (manual link)"              → editor, View all button
      the 4-up bundles row and the 5-up BEST SELLERS row  → card 1, the two presets
      a live preview, because every other Appearance
        screen in this console has one and he uses
        them daily                                        → editor, the frame

    ── THE CONTROLS ARE NOT WRITTEN OUT HERE ────────────────────────────────

    Every field in the editor is drawn from the payload's `tabs`, which the
    server builds with `ModuleSchema::tabs()` from `GridSections::SCHEMA`,
    `::TABS` and `::POLICY` — the same three constants the controller casts
    every write through. A hand-written list of inputs in this file would be a
    SECOND description of the same controls, which is the arrangement
    docs/M-PHASE3-SETTINGS-SCHEMA.md §1 measured the cost of: the picker
    offering an option the cast refuses, or the cast falling back to a default
    the picker does not show. Add a field to that constant and it appears here,
    is validated on the way in, and is persisted — from one statement.

    The three option sets that live in ANOTHER registry — the brands, the
    categories and the 28 card templates — arrive as their own top-level keys
    beside the tabs, because `ModuleSchema::fields()` deliberately emits a
    field's DECLARED options rather than its overrides. Its own comment names
    the two other screens that do the same and why.

    ── THE SCREEN BUFFERS ───────────────────────────────────────────────────

    Two objects, and the difference between them is the feature:

      saved   what the server last handed over. Never written to by a control.
              Discard goes back to it and "unsaved" is measured against it.
      draft   what the owner has typed. Every control writes here and nowhere
              else, and it reaches the database only when Save is pressed.

    A buffered editor that re-renders on every keystroke is the same defect
    with a Save button bolted on — the caret jumps and a <select> closes as it
    is opened — so a control's handler updates the draft, the unsaved bar and
    the preview, and touches no other element. The structural actions (add,
    duplicate, delete, reorder) are IMMEDIATE, because they create and destroy
    the rows the editor is editing and a buffered delete is a row that is gone
    on screen and present in the database.

    ── THE PREVIEW IS AN IFRAME, AND THAT IS NOT DECORATION ─────────────────

    The section's responsiveness is two media queries over `--gs-d` and
    `--gs-m`. A media query asks the VIEWPORT how wide it is, not the box the
    preview is drawn in — so a preview injected straight into this page would
    resolve the desktop's four-across inside a 700px panel and show the owner a
    row the shop never draws. Inside an iframe the media queries resolve against
    the frame's own width, so the Phone / Tablet / Desktop buttons show what
    those widths really produce.

    Its contents come from the grid-sections preview endpoints, which render THE
    SAME PARTIAL the homepage renders, and the frame links the shop's own BUILT
    stylesheets so the card in it is the real card. A second copy of either here
    would disagree with the shop the first time either was touched — the fault
    `HomepageLayouts::summaries()` shipped, and the fault the cards-banner
    preview's own header records paying for with `--pink`.

    ── ORDERING IS SOMEWHERE ELSE, AND THE SCREEN SAYS SO ───────────────────

    Where a grid sits among the shop's OTHER sections is Appearance → Homepage,
    which is the one ordering mechanism this feature has and the one it
    inherits — an instance is a row in that screen's list like any of the
    seventeen. The ↑↓ here move the grids among THEMSELVES, which is a different
    question. Two answers to one question is how a screen ends up lying, so this
    one names both in as many words.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED gss- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks. app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name — [data-open], [data-tg], [data-pp] — and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry and toast(). It cannot reach that
    file's module-scoped constants — NAV, TITLES and ADMIN_BASE are const, not
    window properties — so it appends its own sidebar entry to the rendered nav
    and wraps window.go, exactly as the screens above it do.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file — inside a comment included — with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
.gss-wrap{display:grid;gap:16px;min-width:0}
.gss-wrap > *{min-width:0}
.gss-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.gss-title{font-weight:650;font-size:14.5px;margin:0 0 3px}
.gss-sub{font-size:12px;color:var(--ink-soft,#6b7280);margin:0 0 12px;line-height:1.55}
.gss-banner{background:#FEF3C7;border:1px solid #FCD34D;color:#7C2D12;border-radius:10px;
            padding:11px 13px;font-size:12.5px;line-height:1.55}
.gss-note{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.55;margin:10px 0 0}
.gss-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0}
.gss-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));min-width:0}
.gss-fld{display:grid;gap:4px;min-width:0}
.gss-lab{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280)}
.gss-help{font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.5}
.gss-in,.gss-sel{width:100%;min-width:0;box-sizing:border-box;font:inherit;font-size:13px;
     padding:8px 10px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
     background:var(--surface,#fff);color:inherit}
.gss-in:focus,.gss-sel:focus{outline:2px solid var(--accent,#E8919F);outline-offset:1px}
.gss-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;cursor:pointer}
.gss-btn:hover{border-color:var(--accent,#E8919F)}
.gss-btn.is-primary{background:var(--accent,#E8919F);border-color:var(--accent,#E8919F);color:#fff}
.gss-btn.is-danger{color:#B91C1C;border-color:#FCA5A5}
.gss-btn[disabled]{opacity:.5;cursor:default}
.gss-tiny{font:inherit;font-size:11px;padding:3px 8px;border-radius:7px;cursor:pointer;
          border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit}
.gss-sw{display:inline-flex;align-items:center;gap:9px;font-size:13px;cursor:pointer;min-width:0}
.gss-sw input{width:18px;height:18px;flex:0 0 auto}
.gss-list{display:grid;gap:9px;min-width:0}
.gss-item{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0;
          border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px}
.gss-item.is-open{border-color:var(--accent,#E8919F);background:rgba(232,145,159,.06)}
.gss-item .gss-nm{font-weight:650;font-size:13px;min-width:0;flex:1 1 auto;overflow-wrap:anywhere}
.gss-item .gss-meta{font-size:11px;color:var(--ink-soft,#6b7280);width:100%;line-height:1.5}
.gss-pill{font-size:10.5px;font-weight:700;border-radius:999px;padding:2px 8px;
          border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280)}
.gss-pill.is-on{background:#DCFCE7;border-color:#86EFAC;color:#166534}
.gss-empty{font-size:12.5px;color:var(--ink-soft,#6b7280);line-height:1.6;text-align:center;padding:16px 8px}
.gss-picked{display:grid;gap:7px;min-width:0;margin-top:10px}
.gss-prow{display:flex;gap:9px;align-items:center;min-width:0;
          border:1px solid var(--border,#e6e6e6);border-radius:9px;padding:6px 9px}
.gss-prow .gss-pn{flex:1 1 auto;min-width:0;font-size:12.5px;overflow-wrap:anywhere}
.gss-th{width:34px;height:34px;flex:0 0 auto;border-radius:7px;overflow:hidden;
        background:var(--code-bg,rgba(0,0,0,.05))}
.gss-th img{width:100%;height:100%;object-fit:cover;display:block}
.gss-results{display:grid;gap:6px;max-height:260px;overflow:auto;margin-top:9px;min-width:0}
.gss-stage{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:hidden;
           background:var(--code-bg,rgba(0,0,0,.04));padding:10px;display:grid;justify-items:center}
.gss-frame{width:100%;border:0;display:block;background:#fff}
.gss-widths{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px}
.gss-widths .gss-tiny.is-on{border-color:var(--accent,#E8919F);font-weight:700}
.gss-foot{position:sticky;bottom:0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;
          margin:16px -16px -16px;padding:12px 16px;border-top:1px solid var(--border,#e6e6e6);
          background:var(--surface,#fff);border-radius:0 0 var(--r,12px) var(--r,12px)}
.gss-foot.is-dirty{background:#f2f4fb;border-top-color:#cfd9ee}
.gss-state{flex:1 1 200px;min-width:0;font-size:12px;line-height:1.5;color:var(--ink-soft,#6b7280)}
.gss-state.is-bad{color:#9b1c1c;font-weight:600}
@media (max-width:640px){
  .gss-card{padding:13px}
  .gss-foot{margin:13px -13px -13px;padding:10px 13px}
}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'gridsections';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;        /* GET /admin-api/grid-sections */
  var openId = null;      /* the instance whose editor is showing */
  var saved = null;       /* the server's answer: {section, tabs, manual, …} */
  var draft = null;       /* {values:{}, manual_ids:[]} — what has been typed */
  var results = null;     /* the manual picker's last search */
  var previewWidth = 1280;
  var banner = null;
  var busy = false;
  var saving = false;
  var seq = 0;
  var pvSeq = 0;
  var pvTimer = null;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* X-XSRF-TOKEN read from the XSRF-TOKEN COOKIE, which is what app.blade.php's
     own api() has always sent. A <meta name="csrf-token"> tag is what the
     shoppable-video screens reached for and this console does not render one. */
  async function api(path, method, body){
    var opts = {method: method || 'GET', headers:{'Accept':'application/json'}, credentials:'same-origin'};
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

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

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* A 404 from any of these endpoints almost always means the package shipped
     without its clear_caches migration having run, so the compiled route table
     does not know these paths. Said plainly rather than drawing an empty
     screen, which here would read as "you have no grids" — the exact wrong
     conclusion, and one an owner would answer by building them all again. */
  function explain(e, fallback){
    return e && e.status === 404
      ? 'The Product grids endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Product grids',
      icon:   '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
      group:  'Appearance',
      after:  ['banners', 'hpcontent', 'homepage']
    });
  }

  /* -------------------------------------------------------------- the draft */
  /* The FIELD KEYS COME FROM THE PAYLOAD, not from a list in this file. A
     hand-kept list here is the "control with no writer behind it" shape
     AdminConsoleWriteTokenTest was written after: add a field to
     GridSections::SCHEMA, forget to add it here, and the screen shows a box
     that saves nothing. */
  function fieldKeys(){
    var out = [];
    (saved && saved.tabs ? saved.tabs : []).forEach(function(t){
      (t.fields || []).forEach(function(f){ out.push(f.key); });
    });
    return out;
  }

  function fieldOf(key){
    var found = null;
    (saved && saved.tabs ? saved.tabs : []).forEach(function(t){
      (t.fields || []).forEach(function(f){ if (f.key === key) found = f; });
    });
    return found;
  }

  function startDraft(){
    draft = null;
    if (!saved) return;

    draft = {values: {}, manual_ids: (saved.section.manual_ids || []).slice()};
    fieldKeys().forEach(function(k){
      var f = fieldOf(k);
      draft.values[k] = f ? f.value : null;
    });
  }

  function savedValue(key){
    var f = fieldOf(key);
    return f ? f.value : null;
  }

  function changed(){
    if (!draft || !saved) return [];

    var out = [];

    fieldKeys().forEach(function(k){
      if (String(draft.values[k]) !== String(savedValue(k))) out.push(k);
    });

    if (draft.manual_ids.join(',') !== (saved.section.manual_ids || []).join(',')) {
      out.push('manual_ids');
    }

    return out;
  }

  function dirty(){ return changed().length > 0; }

  function mayLeave(what){
    if (!dirty()) return true;
    return window.confirm('You have ' + changed().length + ' unsaved change(s). '
      + (what || 'Leave them?') + '\n\nPress Cancel to go back and press Save first.');
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) {
      /* Asked BEFORE anything is repainted, so Cancel really does leave the
         owner where he was rather than on a half-torn-down screen. */
      if (openId !== null && !mayLeave('Leave this screen and lose them?')) return undefined;
      draft = null;
      return previousGo.apply(this, arguments);
    }

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Product grids';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    /* render() BEFORE load(), synchronously — the condition app.blade.php's
       LATE_RENDERED set carries. The replay's marker inside #content has to be
       destroyed by the time the async load's task runs, or the screen is drawn
       twice. */
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
      var body = await api('/grid-sections');
      if (mine !== seq) return;
      data = body;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not read your product grids.');
      data = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function openEditor(id){
    openId = id;
    saved = null;
    draft = null;
    results = null;
    render();

    try {
      saved = await api('/grid-sections/' + encodeURIComponent(id));
      startDraft();
    } catch (e) {
      say(explain(e, 'Could not open that grid.'));
      openId = null;
    }

    render();
    refreshPreview();
  }

  /* The preview, redrawn from the BUFFER and debounced. Debounced because a
     text box fires `input` on every keystroke and a request per keystroke is a
     request per keystroke; 260ms is below the point a redraw reads as a
     response to something else. */
  function refreshPreview(){
    if (pvTimer) { clearTimeout(pvTimer); pvTimer = null; }
    pvTimer = setTimeout(drawPreview, 260);
  }

  async function drawPreview(){
    var stage = document.querySelector('#gss-stage');
    if (!stage || openId === null || !draft) return;

    var mine = ++pvSeq;

    try {
      var body = await api('/grid-sections/' + encodeURIComponent(openId) + '/preview', 'POST', {
        values: draft.values,
        manual_ids: draft.manual_ids
      });
      if (mine !== pvSeq) return;
      paintPreview(stage, body);
    } catch (e) {
      if (mine !== pvSeq) return;
      stage.innerHTML = '<div class="gss-empty">' + esc(explain(e, 'Could not draw the preview.')) + '</div>';
    }
  }

  /* srcdoc rather than a URL: there is nothing to fetch — the server already
     handed us the section's markup — and a second document on a real address
     would need a route of its own, a capability of its own and a reason. The
     height is fixed per width rather than measured, because measuring it would
     mean reaching into the frame for a box, which is the one thing this whole
     feature is built not to do. */
  function paintPreview(stage, body){
    if (!body || body.empty) {
      stage.innerHTML = '<div class="gss-empty">Nothing to draw yet — this grid\'s selection returned no products. '
        + 'A grid with nothing in it draws nothing on the shop rather than an empty heading.</div>';
      return;
    }

    var links = (body.stylesheets || []).map(function(href){
      return '<link rel="stylesheet" href="' + esc(href) + '">';
    }).join('');

    var doc = '<!doctype html><html><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width,initial-scale=1">'
      + links
      + '<style>html,body{margin:0;padding:0;background:#fff}body{padding:14px 0}'
      + '.sec{padding:0}</style>'
      + (body.css || '')
      + '</head><body>' + body.html + '</body></html>';

    var height = previewWidth <= 430 ? 620 : (previewWidth <= 800 ? 560 : 520);

    stage.innerHTML = '<div style="width:' + previewWidth + 'px;max-width:100%">'
      + '<iframe class="gss-frame" style="height:' + height + 'px" '
      + 'sandbox="allow-same-origin" title="Product grid preview" srcdoc="' + esc(doc) + '"></iframe></div>';
  }

  /* ---------------------------------------------------------------- views */
  function listView(){
    var rows = (data.sections || []);

    var html = '<div class="gss-card"><div class="gss-title">Your product grids</div>'
      + '<p class="gss-sub">One grid section, used as many times as you like. Each one has its own heading, '
      + 'its own products and its own layout. Build it here; decide where it sits on the page in '
      + 'Appearance → Homepage, where every grid you build appears as a row alongside the shop\'s own sections.</p>';

    if (!rows.length) {
      html += '<div class="gss-empty">No grids yet. Start from one of the two below, or from a blank one.</div>';
    } else {
      html += '<div class="gss-list">';

      rows.forEach(function(s, i){
        html += '<div class="gss-item' + (s.id === openId ? ' is-open' : '') + '">'
          + '<span class="gss-nm">' + esc(s.name) + '</span>'
          + '<span class="gss-pill' + (s.status === 'publish' ? ' is-on' : '') + '">'
          + (s.status === 'publish' ? 'Published' : 'Draft') + '</span>'
          + '<button type="button" class="gss-tiny" data-gss-up="' + s.id + '"'
          + (i === 0 ? ' disabled' : '') + ' aria-label="Move up">↑</button>'
          + '<button type="button" class="gss-tiny" data-gss-down="' + s.id + '"'
          + (i === rows.length - 1 ? ' disabled' : '') + ' aria-label="Move down">↓</button>'
          + '<button type="button" class="gss-btn" data-gss-edit="' + s.id + '">Edit</button>'
          + '<button type="button" class="gss-tiny" data-gss-dup="' + s.id + '">Duplicate</button>'
          + '<button type="button" class="gss-tiny gss-btn is-danger" data-gss-del="' + s.id + '">Delete</button>'
          + '<div class="gss-meta">' + esc(describe(s)) + ' · On Appearance → Homepage it is the row called “'
          + esc(s.name) + '”.</div>'
          + '</div>';
      });

      html += '</div>';
    }

    html += '<div class="gss-row" style="margin-top:13px">'
      + '<button type="button" class="gss-btn is-primary" data-gss-add="">Add a blank grid</button>';

    (data.presets || []).forEach(function(p){
      html += '<button type="button" class="gss-btn" data-gss-add="' + esc(p.key) + '">'
        + esc(p.label) + '</button>';
    });

    html += '</div>'
      + '<p class="gss-note">A new grid is always created as a DRAFT, whatever you start it from, so nothing '
      + 'appears on the front page before you have looked at it. The ↑↓ above order the grids among '
      + 'themselves; where they sit among the shop\'s other sections is Appearance → Homepage.</p>'
      + '</div>';

    return html;
  }

  /* One line of plain English about what a grid is set to, so the list is
     readable without opening every one. */
  function describe(s){
    var v = s.values || {};
    var source = {
      bestsellers: 'Best sellers', newest: 'Newest', onsale: 'On sale',
      featured: 'Featured', brand: 'One brand', category: 'One category',
      manual: 'A list you picked'
    }[v.source] || v.source;

    var desk = v.desktop_layout === 'carousel'
      ? ('carousel, ' + v.desktop_cols + ' in view')
      : (v.desktop_cols + ' columns');

    var mob = v.mobile_layout === 'carousel'
      ? ('carousel, ' + v.mobile_cols + ' in view')
      : (v.mobile_cols + ' columns');

    return source + ' · ' + v.count + ' on desktop (' + desk + ') · '
      + v.mobile_count + ' on mobile (' + mob + ')';
  }

  function fieldHTML(f){
    var id = 'gss-f-' + f.key;
    var help = f.help ? '<p class="gss-help">' + esc(f.help) + '</p>' : '';
    var value = draft.values[f.key];

    if (f.type === 'bool') {
      return '<div class="gss-fld"><label class="gss-sw">'
        + '<input type="checkbox" id="' + id + '" data-gss-key="' + esc(f.key) + '"'
        + (value ? ' checked' : '') + '>'
        + '<span>' + esc(f.label) + '</span></label>' + help + '</div>';
    }

    if (f.type === 'select' || f.type === 'skin') {
      /* The option set is the field's own where it declares one, and the
         payload's top-level set where it does not — the brands, the categories
         and the 28 card templates live in another registry and travel beside
         the tabs for the reason ModuleSchema::fields() gives. Either way it is
         the SAME set the server's cast checks against. */
      var opts = f.options || {};

      if (f.key === 'source_brand_id') opts = saved.brands || opts;
      if (f.key === 'source_category_id') opts = saved.categories || opts;
      if (f.key === 'skin') opts = saved.skins || opts;

      var body = '';

      Object.keys(opts).forEach(function(k){
        body += '<option value="' + esc(k) + '"'
          + (String(k) === String(value) ? ' selected' : '') + '>' + esc(opts[k]) + '</option>';
      });

      return '<div class="gss-fld"><label class="gss-lab" for="' + id + '">' + esc(f.label) + '</label>'
        + '<select class="gss-sel" id="' + id + '" data-gss-key="' + esc(f.key) + '">' + body + '</select>'
        + help + '</div>';
    }

    var type = f.type === 'int' ? 'number' : 'text';

    return '<div class="gss-fld"><label class="gss-lab" for="' + id + '">' + esc(f.label) + '</label>'
      + '<input class="gss-in" type="' + type + '" id="' + id + '" data-gss-key="' + esc(f.key) + '"'
      + ' value="' + esc(value) + '">' + help + '</div>';
  }

  function editorView(){
    if (openId === null) return '';

    if (!saved) {
      return '<div class="gss-card"><div class="gss-empty">Opening…</div></div>';
    }

    var html = '<div class="gss-card"><div class="gss-title">Editing “' + esc(saved.section.name) + '”</div>'
      + '<p class="gss-sub">Every control below belongs to THIS grid. Build another one and it has its own.</p>'
      + '<div id="gss-draftbar"></div>';

    (saved.tabs || []).forEach(function(t){
      html += '<div style="margin-top:14px"><div class="gss-title">' + esc(t.label || t.key) + '</div>';
      if (t.description) html += '<p class="gss-sub">' + esc(t.description) + '</p>';
      html += '<div class="gss-grid">' + (t.fields || []).map(fieldHTML).join('') + '</div></div>';

      if (t.key === 'products') html += manualView();
    });

    html += '<div style="margin-top:16px"><div class="gss-title">How it will look</div>'
      + '<p class="gss-sub">The shop\'s own template, drawn from what you have typed — not from what is saved.</p>'
      + '<div class="gss-widths">'
      + [[390, 'Phone'], [768, 'Tablet'], [1280, 'Desktop']].map(function(w){
          return '<button type="button" class="gss-tiny' + (previewWidth === w[0] ? ' is-on' : '') + '"'
            + ' data-gss-w="' + w[0] + '">' + w[1] + ' · ' + w[0] + 'px</button>';
        }).join('')
      + '</div><div class="gss-stage" id="gss-stage"><div class="gss-empty">Drawing…</div></div></div>';

    html += '<div class="gss-foot" id="gss-foot">'
      + '<span class="gss-state" id="gss-state"></span>'
      + '<button type="button" class="gss-btn" id="gss-discard">Discard</button>'
      + '<button type="button" class="gss-btn is-primary" id="gss-save">Saved</button>'
      + '<button type="button" class="gss-btn" id="gss-close">Close</button>'
      + '</div></div>';

    return html;
  }

  function manualView(){
    if (draft.values.source !== 'manual') {
      return '<p class="gss-note">Set “Which products” to “A list I pick myself” and a picker appears here.</p>';
    }

    var byId = {};
    (saved.manual || []).forEach(function(p){ byId[p.id] = p; });
    (results || []).forEach(function(p){ byId[p.id] = p; });

    var html = '<div style="margin-top:12px"><div class="gss-lab">Your list, in your own order</div>'
      + '<div class="gss-picked">';

    if (!draft.manual_ids.length) {
      html += '<div class="gss-empty">Nothing picked yet. Search below.</div>';
    } else {
      draft.manual_ids.forEach(function(id, i){
        var p = byId[id];
        html += '<div class="gss-prow">'
          + '<span class="gss-th">' + (p && p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') + '</span>'
          + '<span class="gss-pn">' + esc(p ? p.name : ('#' + id)) + '</span>'
          + '<button type="button" class="gss-tiny" data-gss-mup="' + id + '"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
          + '<button type="button" class="gss-tiny" data-gss-mdown="' + id + '"'
          + (i === draft.manual_ids.length - 1 ? ' disabled' : '') + '>↓</button>'
          + '<button type="button" class="gss-tiny" data-gss-mdel="' + id + '">Remove</button>'
          + '</div>';
      });
    }

    html += '</div><div class="gss-row" style="margin-top:10px">'
      + '<input class="gss-in" id="gss-search" placeholder="Search the catalogue by name" style="flex:1 1 200px">'
      + '<button type="button" class="gss-btn" id="gss-searchgo">Search</button></div>';

    if (results !== null) {
      html += '<div class="gss-results">';

      if (!results.length) {
        html += '<div class="gss-empty">Nothing matched.</div>';
      } else {
        results.forEach(function(p){
          var already = draft.manual_ids.indexOf(p.id) !== -1;
          html += '<div class="gss-prow">'
            + '<span class="gss-th">' + (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') + '</span>'
            + '<span class="gss-pn">' + esc(p.name) + '</span>'
            + '<button type="button" class="gss-tiny" data-gss-madd="' + p.id + '"'
            + (already ? ' disabled' : '') + '>' + (already ? 'Added' : 'Add') + '</button>'
            + '</div>';
        });
      }

      html += '</div>';
    }

    return html + '</div>';
  }

  function paintDraftBar(){
    var n = changed().length;
    var bar = document.querySelector('#gss-draftbar');
    var foot = document.querySelector('#gss-foot');
    var save = document.querySelector('#gss-save');
    var disc = document.querySelector('#gss-discard');

    if (bar) {
      bar.innerHTML = n === 0 ? '' : '<div class="gss-banner"><b>' + n + ' unsaved change'
        + (n === 1 ? '' : 's') + '.</b> Nothing has reached the shop yet. '
        + 'The picture below shows them; press Save to keep them.</div>';
    }

    if (foot) foot.classList.toggle('is-dirty', n > 0);
    if (save) {
      save.disabled = saving || n === 0;
      save.textContent = saving ? 'Saving…' : (n === 0 ? 'Saved' : 'Save ' + n + ' change' + (n === 1 ? '' : 's'));
    }
    if (disc) disc.disabled = saving || n === 0;
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    /* Only paint when this screen is the one on show. The console navigates
       before an async load finishes, and a late response must not redraw
       somebody else's page. */
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="gss-wrap">';

    if (banner) html += '<div class="gss-banner">' + esc(banner) + '</div>';

    if (!data) {
      html += '<div class="gss-card"><div class="gss-empty">' + (busy ? 'Reading…' : 'Nothing to show.') + '</div></div>';
    } else {
      html += listView() + editorView();
    }

    host.innerHTML = html + '</div>';

    bind();
    paintDraftBar();
  }

  /* -------------------------------------------------------------- actions */
  function bind(){
    document.querySelectorAll('[data-gss-edit]').forEach(function(b){
      b.onclick = function(){
        if (openId !== null && !mayLeave('Open another grid and lose them?')) return;
        openEditor(Number(b.dataset.gssEdit));
      };
    });

    document.querySelectorAll('[data-gss-add]').forEach(function(b){
      b.onclick = async function(){
        if (openId !== null && !mayLeave('Add a grid and lose them?')) return;
        try {
          var body = await api('/grid-sections', 'POST', {preset: b.dataset.gssAdd || null});
          say('Added as a draft. Publish it when you are happy with it.');
          await load();
          openEditor(body.section.id);
        } catch (e) { say(explain(e, 'Could not add a grid.')); }
      };
    });

    document.querySelectorAll('[data-gss-dup]').forEach(function(b){
      b.onclick = async function(){
        if (openId !== null && !mayLeave('Duplicate and lose them?')) return;
        try {
          await api('/grid-sections/' + b.dataset.gssDup + '/duplicate', 'POST', {});
          say('Copied, as a draft.');
          openId = null; saved = null; draft = null;
          load();
        } catch (e) { say(explain(e, 'Could not duplicate that grid.')); }
      };
    });

    document.querySelectorAll('[data-gss-del]').forEach(function(b){
      b.onclick = async function(){
        if (!window.confirm('Delete this grid? It disappears from the homepage and from Appearance → Homepage. This cannot be undone.')) return;
        try {
          await api('/grid-sections/' + b.dataset.gssDel, 'DELETE');
          say('Deleted.');
          if (String(openId) === String(b.dataset.gssDel)) { openId = null; saved = null; draft = null; }
          load();
        } catch (e) { say(explain(e, 'Could not delete that grid.')); }
      };
    });

    document.querySelectorAll('[data-gss-up],[data-gss-down]').forEach(function(b){
      b.onclick = async function(){
        var id = Number(b.dataset.gssUp || b.dataset.gssDown);
        var order = (data.sections || []).map(function(s){ return s.id; });
        var i = order.indexOf(id);
        var j = b.dataset.gssUp ? i - 1 : i + 1;
        if (i < 0 || j < 0 || j >= order.length) return;
        order[i] = order[j]; order[j] = id;
        try {
          await api('/grid-sections/reorder', 'POST', {order: order});
          load();
        } catch (e) { say(explain(e, 'Could not reorder.')); }
      };
    });

    document.querySelectorAll('[data-gss-w]').forEach(function(b){
      b.onclick = function(){
        previewWidth = Number(b.dataset.gssW);
        document.querySelectorAll('[data-gss-w]').forEach(function(x){
          x.classList.toggle('is-on', x === b);
        });
        drawPreview();
      };
    });

    /* A CONTROL UPDATES THE DRAFT, THE BAR AND THE PREVIEW — AND NOTHING ELSE.
       render() here would rebuild the editor under the owner's caret on every
       keystroke, which is the defect the buffer exists to remove. The one
       exception is `source`, which decides whether the manual picker is on the
       screen at all, so it is structural rather than a value. */
    document.querySelectorAll('[data-gss-key]').forEach(function(el){
      var key = el.dataset.gssKey;

      var apply = function(){
        draft.values[key] = el.type === 'checkbox' ? el.checked : el.value;
        paintDraftBar();
        refreshPreview();
        if (key === 'source') render();
      };

      el.oninput = apply;
      el.onchange = apply;
    });

    var searchGo = document.querySelector('#gss-searchgo');
    if (searchGo) searchGo.onclick = async function(){
      var box = document.querySelector('#gss-search');
      try {
        var body = await api('/grid-sections/products?q=' + encodeURIComponent(box ? box.value : ''));
        results = body.products || [];
        render();
      } catch (e) { say(explain(e, 'Could not search the catalogue.')); }
    };

    document.querySelectorAll('[data-gss-madd]').forEach(function(b){
      b.onclick = function(){
        var id = Number(b.dataset.gssMadd);
        if (draft.manual_ids.indexOf(id) === -1) draft.manual_ids.push(id);
        render();
        refreshPreview();
      };
    });

    document.querySelectorAll('[data-gss-mdel]').forEach(function(b){
      b.onclick = function(){
        draft.manual_ids = draft.manual_ids.filter(function(x){ return x !== Number(b.dataset.gssMdel); });
        render();
        refreshPreview();
      };
    });

    document.querySelectorAll('[data-gss-mup],[data-gss-mdown]').forEach(function(b){
      b.onclick = function(){
        var id = Number(b.dataset.gssMup || b.dataset.gssMdown);
        var i = draft.manual_ids.indexOf(id);
        var j = b.dataset.gssMup ? i - 1 : i + 1;
        if (i < 0 || j < 0 || j >= draft.manual_ids.length) return;
        draft.manual_ids[i] = draft.manual_ids[j];
        draft.manual_ids[j] = id;
        render();
        refreshPreview();
      };
    });

    var save = document.querySelector('#gss-save');
    if (save) save.onclick = async function(){
      if (!dirty() || saving) return;
      saving = true;
      paintDraftBar();
      state('Saving…');

      try {
        var body = await api('/grid-sections/' + encodeURIComponent(openId), 'PUT', {
          values: draft.values,
          manual_ids: draft.manual_ids
        });
        saved = Object.assign({}, saved, body);
        startDraft();
        saving = false;
        await load();
        state('Saved. The shop is showing it now.');
        say('Saved.');
        refreshPreview();
      } catch (e) {
        saving = false;
        paintDraftBar();
        state(explain(e, 'Could not save that grid.'), true);
      }
    };

    var discard = document.querySelector('#gss-discard');
    if (discard) discard.onclick = function(){
      if (!dirty()) return;
      if (!window.confirm('Throw away your ' + changed().length + ' unsaved change(s) and go back to what is saved?')) return;
      startDraft();
      render();
      refreshPreview();
    };

    var close = document.querySelector('#gss-close');
    if (close) close.onclick = function(){
      if (!mayLeave('Close the editor and lose them?')) return;
      openId = null; saved = null; draft = null; results = null;
      render();
    };
  }

  function state(text, bad){
    var el = document.querySelector('#gss-state');
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('is-bad', !!bad);
  }

  /* The fourth door out: closing the tab. A browser will only show its own
     generic wording here, which is why the three doors above ask in this
     screen's own words instead. */
  window.addEventListener('beforeunload', function(ev){
    if (openId === null || !dirty()) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
