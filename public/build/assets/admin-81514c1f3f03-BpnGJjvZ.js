
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
      ? 'The Grid sections endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Grid sections',
      /* NOT the four-square icon: `layout` — Appearance → Product grid, the
         shop-wide column and tile settings — already uses exactly that glyph,
         and two identical icons two rows apart in the same group is a sidebar
         the owner has to read rather than scan. Two stacked bands, each split,
         which is what a page of repeated grid sections looks like. */
      icon:   '<rect x="3" y="4" width="18" height="7" rx="1.5"/><path d="M12 4v7"/><rect x="3" y="14" width="18" height="6" rx="1.5"/>',
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

  /* LEAVING KEEPS THE DRAFT, AND ASKS NOTHING. (Lane PM)
     This used to be window.confirm("You have N unsaved change(s). Leave them?")
     on every door out of the editor — the owner's "weired popup". The typing
     is handed to Unfinished in the top bar instead
     (partials/unfinished-drafts.blade.php), and reopening this grid puts it
     back with a bar that says so. */
  function mayLeave(){
    if (dirty() && window.kbbDrafts) window.kbbDrafts.flush('gridsections');
    return true;
  }

  if (window.kbbDrafts) window.kbbDrafts.track({
    id: 'gridsections', screen: SCREEN,
    label: function(){
      var name = saved && saved.section ? String(saved.section.name || '') : '';
      return 'Appearance → Grid sections · ' + (name || ('grid ' + openId));
    },
    entity: function(){ return saved ? openId : null; },
    values: function(){
      if (!draft || !saved) return null;
      var out = {};
      fieldKeys().forEach(function(k){ out['v.' + k] = draft.values[k]; });
      out.manual_ids = draft.manual_ids.join(',');
      return out;
    },
    set: function(k, v){
      if (k === 'manual_ids') {
        draft.manual_ids = String(v || '').split(',').filter(Boolean).map(Number);
        return;
      }
      if (k.indexOf('v.') === 0 && fieldKeys().indexOf(k.slice(2)) !== -1) draft.values[k.slice(2)] = v;
    },
    count: function(){ return changed().length; },
    render: function(){ render(); refreshPreview(); },
    save: function(){ var b = document.querySelector('#gss-save'); if (b) b.click(); },
    /* The row is chosen BEFORE go(), whose entry branch reopens whatever
       openId names -- two openEditor() calls in flight would race. */
    open: function(id){ openId = Number(id); saved = null; draft = null; window.go(SCREEN); }
  });

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) {
      /* Asked BEFORE anything is repainted, so Cancel really does leave the
         owner where he was rather than on a half-torn-down screen. */
      /* The box he was typing in fires `change` when the screen is torn down
         under it, and its handler writes into the draft -- so it is blurred
         HERE, while the draft still exists. Measured in Chromium: leaving with
         the cursor in the set's name threw "Cannot read properties of null
         (reading 'set')" from the name box's own handler. (Lane PM) */
      var host = document.querySelector('#content');
      var focused = document.activeElement;
      if (host && focused && focused.blur && host.contains(focused)) focused.blur();
      mayLeave();
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
    if (title) title.textContent = 'Grid sections';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    /* render() BEFORE load(), synchronously — the condition app.blade.php's
       LATE_RENDERED set carries. The replay's marker inside #content has to be
       destroyed by the time the async load's task runs, or the screen is drawn
       twice. */
    render();
    load();
    /* Coming back to a grid that was open when he left (Lane PM): reopen it,
       which also brings any unfinished changes to it back. Without this the
       editor was drawn from a draft dropped on the way out. */
    if (openId !== null) openEditor(openId);
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
    /* Unfinished changes to this grid, if any, come back now. */
    if (saved && window.kbbDrafts) window.kbbDrafts.ready('gridsections');
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
      + '.sec{padding:0}'
      /* THE HOVER-ONLY CHROME IS SUPPRESSED, AND SAYING WHY MATTERS.
         `.qv-btn` and `.heart` are positioned and faded in by an inline <style>
         in layouts/store.blade.php, which this document does not have and must
         not copy — a second copy of another file's card CSS is exactly the
         fault the cards-banner preview's own header records paying for with
         `--pink`. Drawn without those rules the quick-view chip renders as a
         line of plain text in the corner of every tile, which is a picture of
         something the shop never shows. The preview's job is the LAYOUT — how
         many across, how wide, where it wraps — and a frame with no pointer
         cannot show hover state honestly either way. */
      + '.qv-btn,.heart{display:none}</style>'
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
        if (openId !== null && !mayLeave()) return;
        openEditor(Number(b.dataset.gssEdit));
      };
    });

    document.querySelectorAll('[data-gss-add]').forEach(function(b){
      b.onclick = async function(){
        if (openId !== null && !mayLeave()) return;
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
        if (openId !== null && !mayLeave()) return;
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
          if (window.kbbDrafts) window.kbbDrafts.drop('gridsections', b.dataset.gssDel);
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
        if (window.kbbDrafts) window.kbbDrafts.saved('gridsections');
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
      if (!mayLeave()) return;
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

  /* The fourth door out, closing the tab or refreshing, asks nothing either:
     the draft is already in Unfinished, written as it was typed. (Lane PM) */

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
