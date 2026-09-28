{{--
    Appearance → Banners → Cards banner. (Lane BN — Phase 22)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, window.kbbAddNavEntry,
    window.kbbPickMedia and toast(). Its own file rather than more lines inside
    a 22,000-line Blade: several lanes edit that file at once, and a screen that
    lives on its own can be reviewed, reverted and merged on its own. The cost
    is that it cannot reach app.blade.php's module-scoped constants — NAV,
    TITLES and ADMIN_BASE are const, not window properties — so it appends its
    own sidebar entry to the rendered nav and wraps window.go instead. Both are
    surfaces the console already exposes for exactly this, and the shape is
    deliberately the same as admin/partials/cache-screen.blade.php.

    ── WHAT THE OWNER ASKED FOR, AND WHERE EACH PART OF IT IS ────────────────

      "we can turn on off card banners"                   → card 1, the switch
      "choose which banner will show on homepage"         → card 1, the select
      "create multiple cards" inside a set                → card 3, the cards
      "how many cards, scroll speed, animation etc"       → card 3, the controls
      "same sizes of the cards"                           → card 3, Card shape
      "full control to turn on off bottom text etc"       → card 3, two switches
      a live preview, because every other Appearance
        screen in this console has one and he uses
        them daily                                        → card 3, the frame

    ── THE PREVIEW IS AN IFRAME, AND THAT IS NOT DECORATION ──────────────────

    The row's responsiveness is three media queries over `--kbbn-per` and
    `--kbbn-peek`. A media query asks the VIEWPORT how wide it is, not the box
    the preview is drawn in — so a preview injected straight into this page
    would resolve the desktop's four-across inside a 700px panel and show the
    owner a row the shop never draws. Inside an iframe the media queries resolve
    against the frame's own width, so the Phone / Tablet / Desktop buttons show
    what those widths really produce. It is the same problem the Instagram
    screen solved with @container rules, answered the other way because this
    section is sized by media queries on purpose.

    The frame's contents come from GET /admin-api/banners/sets/{id}/preview,
    which renders THE SAME PARTIAL the homepage renders. A second copy of the
    markup in this file would disagree with the shop the first time either was
    touched — the fault HomepageLayouts::summaries() shipped.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED bns- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks. app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name — [data-open], [data-tg], [data-pp] — and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file — inside a comment included — with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
.bns-wrap{display:grid;gap:16px;min-width:0}
.bns-wrap > *{min-width:0}
.bns-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.bns-title{font-weight:650;font-size:14.5px;margin:0 0 3px}
.bns-sub{font-size:12px;color:var(--ink-soft,#6b7280);margin:0 0 12px;line-height:1.55}
.bns-banner{background:#FEF3C7;border:1px solid #FCD34D;color:#7C2D12;border-radius:10px;
            padding:11px 13px;font-size:12.5px;line-height:1.55}
.bns-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0}
.bns-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));min-width:0}
.bns-fld{display:grid;gap:4px;min-width:0}
.bns-lab{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280)}
.bns-help{font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.5}
.bns-in,.bns-sel{width:100%;min-width:0;box-sizing:border-box;font:inherit;font-size:13px;
     padding:8px 10px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
     background:var(--surface,#fff);color:inherit}
.bns-in:focus,.bns-sel:focus{outline:2px solid var(--accent,#E8919F);outline-offset:1px}
.bns-rng{width:100%;min-width:0}
.bns-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;cursor:pointer}
.bns-btn:hover{border-color:var(--accent,#E8919F)}
.bns-btn.is-primary{background:var(--accent,#E8919F);border-color:var(--accent,#E8919F);color:#fff}
.bns-btn.is-danger{color:#B91C1C;border-color:#FCA5A5}
.bns-btn[disabled]{opacity:.5;cursor:default}
.bns-sw{display:inline-flex;align-items:center;gap:9px;font-size:13px;cursor:pointer;min-width:0}
.bns-sw input{width:18px;height:18px;flex:0 0 auto}
.bns-sets{display:grid;gap:9px;min-width:0}
.bns-set{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-width:0;
         border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px}
.bns-set.is-open{border-color:var(--accent,#E8919F);background:rgba(232,145,159,.06)}
.bns-set .bns-nm{font-weight:650;font-size:13px;min-width:0;flex:1 1 auto;overflow-wrap:anywhere}
.bns-pill{font-size:10.5px;font-weight:700;border-radius:999px;padding:2px 8px;
          border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280)}
.bns-pill.is-on{background:#DCFCE7;border-color:#86EFAC;color:#166534}
.bns-pill.is-live{background:#E0E7FF;border-color:#A5B4FC;color:#3730A3}
.bns-cards{display:grid;gap:12px;min-width:0;margin-top:12px}
.bns-cd{border:1px solid var(--border,#e6e6e6);border-radius:11px;padding:12px;min-width:0;
        display:grid;gap:10px;grid-template-columns:96px minmax(0,1fr)}
.bns-th{width:96px;height:120px;border-radius:9px;overflow:hidden;background:var(--code-bg,rgba(0,0,0,.05));
        display:grid;place-items:center;font-size:10.5px;color:var(--ink-soft,#6b7280);text-align:center}
.bns-th img{width:100%;height:100%;object-fit:cover;display:block}
.bns-cdb{display:grid;gap:8px;min-width:0}
.bns-frame{width:100%;border:0;display:block;background:#fff}
.bns-stage{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:hidden;
           background:#fff;margin-top:10px;display:flex;justify-content:center;min-width:0}
.bns-stage > div{min-width:0;max-width:100%;overflow:hidden}
.bns-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.bns-warn{font-size:11.5px;color:#B45309}
@media (max-width:640px){
  .bns-card{padding:13px}
  .bns-cd{grid-template-columns:64px minmax(0,1fr)}
  .bns-th{width:64px;height:80px}
}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'banners';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var data = null;        // GET /admin-api/banners
  var openId = null;      // the set whose editor is showing
  var openSet = null;     // GET /admin-api/banners/sets/{id}
  var previewWidth = 1280;
  var banner = null;
  var busy = false;
  var seq = 0;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* X-XSRF-TOKEN read from the XSRF-TOKEN COOKIE, which is what
     app.blade.php's own api() has always sent. A <meta name="csrf-token"> tag
     is what the shoppable-video screens reached for and this console does not
     render one. */
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
     screen, which here would read as "you have no banners" — the exact wrong
     conclusion, and one an owner would answer by building them all again. */
  function explain(e, fallback){
    return e && e.status === 404
      ? 'The Banners endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Banners',
      icon:   '<rect x="3" y="4" width="7" height="16" rx="2"/><rect x="14" y="4" width="7" height="16" rx="2"/>',
      group:  'Appearance',
      after:  ['hpcontent', 'homepage']
    });
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Banners';

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
      var body = await api('/banners');
      if (mine !== seq) return;
      data = body;
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not read your banner sets.');
      data = null;
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function openEditor(id){
    openId = id;
    openSet = null;
    render();

    try {
      openSet = await api('/banners/sets/' + encodeURIComponent(id));
    } catch (e) {
      say(explain(e, 'Could not open that set.'));
      openId = null;
    }

    render();
    refreshPreview();
  }

  async function refreshPreview(){
    if (openId === null) return;

    var stage = document.querySelector('#bns-stage');
    if (!stage) return;

    try {
      var body = await api('/banners/sets/' + encodeURIComponent(openId) + '/preview');
      paintPreview(stage, body);
    } catch (e) {
      stage.innerHTML = '<div class="bns-empty">' + esc(explain(e, 'Could not draw the preview.')) + '</div>';
    }
  }

  /* The frame. srcdoc rather than a URL: there is nothing to fetch — the server
     already handed us the row's markup, and a second document on a real address
     would need a route of its own, a capability of its own and a reason. The
     height is fixed per width rather than measured, because measuring it would
     mean reaching into the frame for a box, which is the one thing this whole
     feature is built not to do. */
  function paintPreview(stage, body){
    if (!body || body.empty || !body.html) {
      stage.innerHTML = '<div class="bns-empty">Nothing to draw yet — add a card with a picture, and publish the set to show it on the shop.</div>';
      return;
    }

    var doc = '<!doctype html><html><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<style>html,body{margin:0;padding:0;background:#fff;'
      + 'font-family:Poppins,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;'
      + '--site-gutter:18px;--site-max:1280px;--pink:#E8919F;--ink:#2A2228;--muted:#8A7F86;'
      + '--line:#EADCE2;--line2:#F4EEF1}'
      + '.wrap{max-width:var(--site-max);margin-inline:auto;padding-inline:var(--site-gutter)}'
      + 'body{padding:14px 0}</style></head><body><div class="wrap">'
      + body.html + '</div></body></html>';

    var height = previewWidth <= 430 ? 430 : (previewWidth <= 800 ? 400 : 430);

    stage.innerHTML = '<div style="width:' + previewWidth + 'px;max-width:100%">'
      + '<iframe class="bns-frame" style="height:' + height + 'px" '
      + 'sandbox="allow-same-origin" title="Cards banner preview" srcdoc="' + esc(doc) + '"></iframe></div>';
  }

  /* ---------------------------------------------------------------- views */

  function moduleView(){
    var setField = null;
    (data.tabs || []).forEach(function(tab){
      (tab.fields || []).forEach(function(f){ if (f.key === 'set') setField = f; });
    });

    /* data.setOptions, NOT setField.options. ModuleSchema::fields() emits the
       module's OWN schema options and never the overrides — see the controller,
       which carries the whole argument and the defect it fixes. */
    var options = data.setOptions || {'': 'None'};
    var current = setField ? String(setField.value == null ? '' : setField.value) : '';

    var html = '<div class="bns-card">'
      + '<div class="bns-title">The cards banner on the homepage</div>'
      + '<div class="bns-sub">Off by default, and nothing shows until you switch it on <em>and</em> pick a published set below. '
        + 'Its position on the page, and whether it shows on phones or on desktop, are on Appearance → Homepage under “Cards banner”.</div>'
      + '<label class="bns-sw"><input type="checkbox" id="bns-module"' + (data.moduleOn ? ' checked' : '') + '>'
      + '<span>Show the cards banner on the homepage</span></label>'
      + '<div class="bns-fld" style="margin-top:13px"><span class="bns-lab">Which set shows</span>'
      + '<select class="bns-sel" id="bns-chosen">';

    Object.keys(options).forEach(function(k){
      html += '<option value="' + esc(k) + '"' + (k === current ? ' selected' : '') + '>' + esc(options[k]) + '</option>';
    });

    html += '</select><span class="bns-help">Only a published set draws anything. A draft is listed here and marked, so you can see which one you picked.</span></div>'
      + '<div class="bns-row" style="margin-top:12px"><button class="bns-btn is-primary" id="bns-save">Save</button></div>'
      + '</div>';

    return html;
  }

  function setsView(){
    var html = '<div class="bns-card">'
      + '<div class="bns-title">Your banner sets</div>'
      + '<div class="bns-sub">Each set is its own row of cards with its own speed, animation and shape. Build as many as you like and switch between them above.</div>'
      + '<div class="bns-row" style="margin-bottom:11px"><button class="bns-btn is-primary" id="bns-new">New set</button></div>';

    if (!(data.sets || []).length) {
      html += '<div class="bns-empty">No sets yet. Press “New set” to build your first one.</div></div>';
      return html;
    }

    html += '<div class="bns-sets">';

    var chosen = '';
    (data.tabs || []).forEach(function(tab){
      (tab.fields || []).forEach(function(f){ if (f.key === 'set') chosen = String(f.value == null ? '' : f.value); });
    });

    data.sets.forEach(function(s){
      html += '<div class="bns-set' + (openId === s.id ? ' is-open' : '') + '">'
        + '<span class="bns-nm">' + esc(s.name) + '</span>'
        + '<span class="bns-pill' + (s.status === 'publish' ? ' is-on' : '') + '">' + esc(s.status === 'publish' ? 'Published' : 'Draft') + '</span>'
        + (String(s.id) === chosen ? '<span class="bns-pill is-live">On the homepage</span>' : '')
        + '<span class="bns-pill">' + esc(s.cards_count) + ' card' + (s.cards_count === 1 ? '' : 's') + '</span>'
        + '<button class="bns-btn" data-bns-open="' + esc(s.id) + '">' + (openId === s.id ? 'Close' : 'Edit') + '</button>'
        + '<button class="bns-btn" data-bns-dup="' + esc(s.id) + '">Duplicate</button>'
        + '<button class="bns-btn is-danger" data-bns-del="' + esc(s.id) + '">Delete</button>'
        + '</div>';
    });

    return html + '</div></div>';
  }

  function num(key, label, help, value, min, max, step){
    return '<div class="bns-fld"><span class="bns-lab">' + esc(label) + '</span>'
      + '<input class="bns-rng" type="range" data-bns-set="' + esc(key) + '" value="' + esc(value) + '" '
      + 'min="' + esc(min) + '" max="' + esc(max) + '" step="' + esc(step || 1) + '">'
      + '<span class="bns-help"><b data-bns-out="' + esc(key) + '">' + esc(value) + '</b> — ' + esc(help) + '</span></div>';
  }

  function pick(key, label, help, value, options){
    var html = '<div class="bns-fld"><span class="bns-lab">' + esc(label) + '</span>'
      + '<select class="bns-sel" data-bns-set="' + esc(key) + '">';
    Object.keys(options).forEach(function(k){
      html += '<option value="' + esc(k) + '"' + (String(value) === k ? ' selected' : '') + '>' + esc(options[k]) + '</option>';
    });
    return html + '</select><span class="bns-help">' + esc(help) + '</span></div>';
  }

  function sw(key, label, value){
    return '<label class="bns-sw"><input type="checkbox" data-bns-set="' + esc(key) + '"'
      + (value ? ' checked' : '') + '><span>' + esc(label) + '</span></label>';
  }

  function editorView(){
    if (openId === null) return '';

    if (!openSet) return '<div class="bns-card"><div class="bns-empty">Opening…</div></div>';

    var s = openSet.set;
    var e = data.enums;
    var L = e.limits;

    var html = '<div class="bns-card">'
      + '<div class="bns-title">' + esc(s.name) + '</div>'
      + '<div class="bns-sub">Everything on this card belongs to this set alone. Another set can be a different shape, a different speed and a different size.</div>'
      + '<div class="bns-grid">'
      + '<div class="bns-fld"><span class="bns-lab">Name</span>'
        + '<input class="bns-in" type="text" data-bns-set="name" value="' + esc(s.name) + '" maxlength="180">'
        + '<span class="bns-help">Yours to recognise it by. Shoppers never see it.</span></div>'
      + pick('status', 'Published', 'A draft never shows on the shop, whatever is picked above.', s.status, e.statuses)
      + pick('ratio', 'Card shape', 'Every card in the row is exactly this shape, whatever is written in it and whatever shape the picture is.', s.ratio, e.ratios)
      + pick('animation', 'Animation', 'The row loops seamlessly with no JavaScript at all, so it costs the page nothing to run.', s.animation, e.animations)
      + pick('shadow', 'Shadow', 'No border at all — the corner radius and the shadow are what lift the card off the page.', s.shadow, e.shadows)
      + num('per_view', 'Cards across on desktop', 'full cards, with the next one peeking past the edge. Phones and tablets narrow this by themselves — one across on a phone.', s.per_view, L.per_view[0], L.per_view[1])
      + num('peek', 'How much of the next card shows', '% of a card past the edge. This is what makes the row read as swipeable at a glance.', s.peek, L.peek[0], L.peek[1])
      + num('speed_ms', 'Speed', 'milliseconds per card. The whole loop is this times the number of cards, so adding a card does not change how fast it feels.', s.speed_ms, L.speed_ms[0], L.speed_ms[1], 100)
      + num('gap', 'Space between cards', 'px', s.gap, L.gap[0], L.gap[1])
      + num('card_radius', 'Corner radius', 'px', s.card_radius, L.card_radius[0], L.card_radius[1])
      + '</div>'
      + '<div class="bns-row" style="margin-top:13px">'
      + sw('autoplay', 'Scroll by itself', s.autoplay)
      + sw('pause_on_hover', 'Pause when the mouse is over it', s.pause_on_hover)
      + sw('show_text', 'Show the text band under each picture', s.show_text)
      + sw('show_button', 'Show the button', s.show_button)
      + sw('show_dots', 'Show dots under the row', s.show_dots)
      + sw('show_arrows', 'Show arrows', s.show_arrows)
      + '</div>'
      + '<div class="bns-help" style="margin-top:9px">'
      + 'With the text band off the card is the picture alone, at the same shape, and the button goes with the band — the whole picture becomes the link instead. '
      + 'Dots and arrows only appear while the row is <em>not</em> scrolling by itself, and for a shopper whose device asks for less motion. '
      + 'Arrows use the browser’s own scroll buttons: Chrome and Edge draw them, Safari and Firefox draw none yet, and the row is still scrollable by hand and by the dots in all four.'
      + '</div>';

    /* ---------------------------------------------------------- the cards */
    html += '<div class="bns-title" style="margin-top:18px">Cards</div>'
      + '<div class="bns-sub">A picture, one or two lines under it, and a small button. Anything you leave blank is simply not drawn — it leaves no gap.</div>'
      + '<div class="bns-row"><button class="bns-btn is-primary" id="bns-newcard">Add a card</button></div>';

    if (!(openSet.cards || []).length) {
      html += '<div class="bns-empty">No cards yet.</div></div>';
      return html;
    }

    html += '<div class="bns-cards">';

    openSet.cards.forEach(function(c){
      html += '<div class="bns-cd">'
        + '<div class="bns-th">' + (c.image_url
            ? '<img src="' + esc(c.image_url) + '" alt="">'
            : 'no picture') + '</div>'
        + '<div class="bns-cdb">'
        + '<div class="bns-row">'
          + '<button class="bns-btn" data-bns-pic="' + esc(c.id) + '">' + (c.image ? 'Change picture' : 'Choose a picture') + '</button>'
          + '<button class="bns-btn is-danger" data-bns-delcard="' + esc(c.id) + '">Delete</button>'
          + (c.button_url && !c.button_url_safe
              ? '<span class="bns-warn">That link is not a kind of address this shop will publish, so no button is drawn.</span>' : '')
        + '</div>'
        + '<input class="bns-in" type="text" placeholder="Heading" maxlength="190" data-bns-card="' + esc(c.id) + '" data-bns-k="heading" value="' + esc(c.heading) + '">'
        + '<input class="bns-in" type="text" placeholder="One short line under it" maxlength="255" data-bns-card="' + esc(c.id) + '" data-bns-k="body" value="' + esc(c.body) + '">'
        + '<div class="bns-grid">'
          + '<input class="bns-in" type="text" placeholder="Button label" maxlength="80" data-bns-card="' + esc(c.id) + '" data-bns-k="button_label" value="' + esc(c.button_label) + '">'
          + '<input class="bns-in" type="text" placeholder="Where it goes, e.g. /shop/" maxlength="400" data-bns-card="' + esc(c.id) + '" data-bns-k="button_url" value="' + esc(c.button_url) + '">'
          + '<input class="bns-in" type="text" placeholder="Picture description, for screen readers" maxlength="255" data-bns-card="' + esc(c.id) + '" data-bns-k="alt" value="' + esc(c.alt) + '">'
          + '<input class="bns-in" type="number" min="0" max="9999" placeholder="Order" data-bns-card="' + esc(c.id) + '" data-bns-k="position" value="' + esc(c.position) + '">'
        + '</div></div></div>';
    });

    html += '</div>';

    /* -------------------------------------------------------- the preview */
    html += '<div class="bns-title" style="margin-top:18px">How it looks</div>'
      + '<div class="bns-sub">Drawn by the shop’s own template, at a real screen width, so what is here is what the homepage draws.</div>'
      + '<div class="bns-row">'
      + [[390, 'Phone'], [768, 'Tablet'], [1280, 'Desktop']].map(function(w){
          return '<button class="bns-btn' + (previewWidth === w[0] ? ' is-primary' : '') + '" data-bns-w="' + w[0] + '">' + w[1] + ' · ' + w[0] + 'px</button>';
        }).join('')
      + '</div><div class="bns-stage" id="bns-stage"><div class="bns-empty">Drawing…</div></div>';

    return html + '</div>';
  }

  function render(){
    var host = document.querySelector('#content');
    if (!host) return;

    // Only paint when this screen is the one on show. The console navigates
    // before an async load finishes, and a late response must not redraw
    // somebody else's page.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    var html = '<div class="bns-wrap">';

    if (banner) html += '<div class="bns-banner">' + esc(banner) + '</div>';

    if (!data) {
      html += '<div class="bns-card"><div class="bns-empty">' + (busy ? 'Reading…' : 'Nothing to show.') + '</div></div>';
    } else {
      html += moduleView() + setsView() + editorView();
    }

    host.innerHTML = html + '</div>';
    wire();
  }

  /* ----------------------------------------------------------------- wire */
  function wire(){
    var save = document.querySelector('#bns-save');
    if (save) save.onclick = async function(){
      save.disabled = true;
      try {
        await api('/banners/module', 'POST', {enabled: document.querySelector('#bns-module').checked});
        await api('/banners', 'POST', {settings: {set: document.querySelector('#bns-chosen').value}});
        say('Saved.');
        await load();
      } catch (e) {
        save.disabled = false;
        say(explain(e, 'Could not save that.'));
      }
    };

    var add = document.querySelector('#bns-new');
    if (add) add.onclick = async function(){
      add.disabled = true;
      try {
        var body = await api('/banners/sets', 'POST', {name: 'Cards banner'});
        await load();
        openEditor(body.set.id);
      } catch (e) {
        add.disabled = false;
        say(explain(e, 'Could not create a set.'));
      }
    };

    document.querySelectorAll('[data-bns-open]').forEach(function(b){
      b.onclick = function(){
        var id = Number(b.dataset.bnsOpen);
        if (openId === id) { openId = null; openSet = null; render(); return; }
        openEditor(id);
      };
    });

    document.querySelectorAll('[data-bns-dup]').forEach(function(b){
      b.onclick = async function(){
        b.disabled = true;
        try {
          var body = await api('/banners/sets/' + b.dataset.bnsDup + '/duplicate', 'POST', {});
          say('Copied, as a draft.');
          await load();
          openEditor(body.set.id);
        } catch (e) { b.disabled = false; say(explain(e, 'Could not duplicate that.')); }
      };
    });

    document.querySelectorAll('[data-bns-del]').forEach(function(b){
      b.onclick = async function(){
        if (!window.confirm('Delete this set and all of its cards? This cannot be undone.')) return;
        b.disabled = true;
        try {
          await api('/banners/sets/' + b.dataset.bnsDel, 'DELETE');
          if (openId === Number(b.dataset.bnsDel)) { openId = null; openSet = null; }
          say('Deleted.');
          await load();
        } catch (e) { b.disabled = false; say(explain(e, 'Could not delete that.')); }
      };
    });

    /* A set control writes on `change` rather than on every keystroke, and the
       preview is redrawn from the SERVER afterwards rather than patched here —
       so what the owner is looking at is what the shop would draw, not this
       screen's opinion of it. */
    document.querySelectorAll('[data-bns-set]').forEach(function(el){
      var out = document.querySelector('[data-bns-out="' + el.dataset.bnsSet + '"]');

      if (out) el.oninput = function(){ out.textContent = el.value; };

      el.onchange = async function(){
        var value = el.type === 'checkbox' ? el.checked : el.value;
        var payload = {}; payload[el.dataset.bnsSet] = value;

        try {
          var body = await api('/banners/sets/' + openId, 'PUT', payload);
          openSet.set = body.set;
          refreshPreview();
          // The list above carries the name, the status and the card count.
          await load();
        } catch (e) { say(explain(e, 'Could not save that.')); }
      };
    });

    var newCard = document.querySelector('#bns-newcard');
    if (newCard) newCard.onclick = async function(){
      newCard.disabled = true;
      try {
        await api('/banners/sets/' + openId + '/cards', 'POST', {});
        await openEditor(openId);
      } catch (e) { newCard.disabled = false; say(explain(e, 'Could not add a card.')); }
    };

    document.querySelectorAll('[data-bns-card]').forEach(function(el){
      el.onchange = async function(){
        var payload = {}; payload[el.dataset.bnsK] = el.value;
        try {
          await api('/banners/cards/' + el.dataset.bnsCard, 'PUT', payload);
          await openEditor(openId);
        } catch (e) { say(explain(e, 'Could not save that.')); }
      };
    });

    document.querySelectorAll('[data-bns-delcard]').forEach(function(b){
      b.onclick = async function(){
        if (!window.confirm('Delete this card?')) return;
        try {
          await api('/banners/cards/' + b.dataset.bnsDelcard, 'DELETE');
          await openEditor(openId);
        } catch (e) { say(explain(e, 'Could not delete that card.')); }
      };
    });

    /* THE PICTURE COMES THROUGH THE CONSOLE'S SHARED PICKER, which is how it
       reaches the Media Library: window.kbbPickMedia opens the one dialog every
       image field in this console opens, and an upload made from it goes
       through POST /admin-api/media/upload, which registers the file. The
       controller calls MediaRegistrar::record() again on write anyway, for the
       path that did not come this way. */
    document.querySelectorAll('[data-bns-pic]').forEach(function(b){
      b.onclick = function(){
        if (typeof window.kbbPickMedia !== 'function') {
          say('The media picker is not available on this page.');
          return;
        }

        window.kbbPickMedia({
          title: 'Choose the card’s picture',
          folder: 'banners',
          /* onPick receives an ARRAY OF URLS, always — the picker's own
             docblock says so, and a single-select call gets an array of one
             rather than a bare string precisely so a caller cannot be written
             against the wrong shape and work by accident. The URL is cut down
             to a stored path by the controller, once, rather than here: a
             second copy of that rule in JavaScript is the copy that goes
             stale. */
          onPick: async function(urls){
            if (!urls || !urls.length) return;

            try {
              await api('/banners/cards/' + b.dataset.bnsPic, 'PUT', {image: String(urls[0])});
              await openEditor(openId);
            } catch (e) { say(explain(e, 'Could not set that picture.')); }
          }
        });
      };
    });

    document.querySelectorAll('[data-bns-w]').forEach(function(b){
      b.onclick = function(){ previewWidth = Number(b.dataset.bnsW); render(); refreshPreview(); };
    });
  }

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
