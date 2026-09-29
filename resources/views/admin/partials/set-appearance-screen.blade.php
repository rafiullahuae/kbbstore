{{--
    Appearance → Set → Desktop / Mobile.                              (Lane SA)

    The owner, verbatim: *"I need the full controls of everything like spacing,
    fonts, elements turn on off etc etc. every single details. for mobile and
    desktop both separate tabs. Under Appearance → Set → desktop / mobile."*

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so it runs once
    the console's own script has defined window.go, window.kbbAddNavEntry and
    toast(). Its own file rather than more lines inside a 22,000-line Blade:
    several lanes edit that file at once, and a screen that lives on its own can
    be reviewed, reverted and merged on its own. The cost is that it cannot
    reach app.blade.php's module-scoped constants — NAV, TITLES and ADMIN_BASE
    are const, not window properties — so it appends its own sidebar entry to
    the rendered nav and wraps window.go instead. Both are surfaces the console
    already exposes for exactly this, and the shape is deliberately the same as
    admin/partials/banners-screen.blade.php.

    ── TWO TABS, AND THE SECOND ONE IS NOT A COPY OF THE FIRST ───────────────

    Desktop and Mobile, as asked. Every DIMENSIONAL control has a value per
    breakpoint and the phone's never falls back to the laptop's — the argument
    is in App\Services\SetAppearance's own header, and the short version is that
    the shipped sheet ALREADY differs between the two, so inheritance would have
    had to ship pre-touched and would have been a lie on the day it landed.

    Everything that is not a measurement — the on/off switches, the weights, the
    colours — is SHARED and appears once, on Desktop. The Mobile tab says so at
    the top of every card. A second copy of "show the quantities" per breakpoint
    is one control with two halves that can disagree without anybody meaning
    them to.

    ── THE SAVE BUTTON, BECAUSE HE ASKED FOR ONE ON BANNERS ──────────────────

    Nothing is written until Save. The bar says HOW MANY changes are waiting,
    Discard puts them back, leaving the screen asks first, and closing the tab
    asks too. Same machinery as the Banners editor and for the same reason: he
    makes several edits before he saves, and a screen that writes on every input
    turns a slider drag into forty writes.

    ── THE PREVIEW IS AN IFRAME, AND THAT IS NOT DECORATION ──────────────────

    The set box responds with a MEDIA QUERY, and a media query asks the VIEWPORT
    how wide it is, not the box it is drawn in. A preview injected straight into
    this page would resolve the desktop branch inside a 700px panel and show a
    row the shop never draws — on a screen whose entire subject is "desktop and
    mobile separately", that is not a nicety. Inside a frame the query resolves
    against the frame's own width, so the Phone and Desktop buttons show what
    those widths really produce.

    Its contents come from POST /admin-api/set-appearance/preview, which renders
    THE SAME PARTIAL the cart renders, from the values in the buffer. Nothing is
    written. A second copy of that markup here would disagree with the shop the
    first time either was touched — the fault HomepageLayouts::summaries()
    shipped.

    THE POPUP IN THE PREVIEW REALLY OPENS, because the partial ships its own
    script and the frame is a fresh window. The owner presses "What's inside"
    and sees the box he just sized. Nothing here simulates an open state.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED sap- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
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
.sap-wrap{display:grid;gap:16px;min-width:0}
.sap-wrap > *{min-width:0}
.sap-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.sap-title{font-weight:650;font-size:14.5px;margin:0 0 3px}
.sap-sub{font-size:12px;color:var(--ink-soft,#6b7280);margin:0 0 12px;line-height:1.55}
.sap-banner{background:#FEF3C7;border:1px solid #FCD34D;color:#7C2D12;border-radius:10px;
            padding:11px 13px;font-size:12.5px;line-height:1.55;margin-bottom:12px}
.sap-note{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A;border-radius:10px;
          padding:10px 12px;font-size:12px;line-height:1.55;margin-bottom:12px}
.sap-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;min-width:0}
.sap-tab{font:inherit;font-size:13px;font-weight:650;padding:8px 16px;border-radius:999px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;cursor:pointer}
.sap-tab[aria-selected="true"]{background:var(--accent,#E8919F);border-color:var(--accent,#E8919F);color:#fff}
.sap-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));min-width:0}
.sap-f{display:grid;gap:5px;min-width:0}
.sap-fh{display:flex;gap:8px;align-items:baseline;justify-content:space-between;min-width:0}
.sap-fh label{font-size:12.5px;font-weight:600;min-width:0;overflow-wrap:anywhere}
.sap-val{font-size:11.5px;font-weight:700;color:var(--ink-soft,#6b7280);white-space:nowrap;flex:none}
.sap-help{font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.5}
.sap-f input[type=range]{width:100%;min-width:0}
.sap-check{display:flex;gap:9px;align-items:flex-start;min-width:0}
.sap-check input{width:18px;height:18px;flex:0 0 auto;margin-top:2px}
.sap-check label{font-size:13px;font-weight:600;cursor:pointer}
.sap-col{display:flex;gap:8px;align-items:center;min-width:0}
.sap-col input[type=color]{width:38px;height:30px;padding:0;border:1px solid var(--border,#e6e6e6);
                           border-radius:7px;background:var(--surface,#fff);flex:none}
.sap-col input[type=text]{flex:1 1 auto;min-width:0;font:inherit;font-size:12.5px;padding:6px 9px;
                          border:1px solid var(--border,#e6e6e6);border-radius:8px;
                          background:var(--surface,#fff);color:inherit}
.sap-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;cursor:pointer}
.sap-btn:hover{border-color:var(--accent,#E8919F)}
.sap-btn.is-primary{background:var(--accent,#E8919F);border-color:var(--accent,#E8919F);color:#fff}
.sap-btn[disabled]{opacity:.5;cursor:default}
.sap-tiny{font:inherit;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:999px;
          border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:var(--ink-soft,#6b7280);
          cursor:pointer;flex:none}
.sap-tiny:hover{border-color:var(--accent,#E8919F);color:inherit}
.sap-bar{position:sticky;top:0;z-index:5;display:flex;gap:10px;align-items:center;flex-wrap:wrap;
         background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);
         padding:11px 14px;min-width:0}
.sap-bar .sap-count{font-size:12.5px;font-weight:650;min-width:0;flex:1 1 auto}
.sap-bar.is-dirty{border-color:var(--accent,#E8919F);background:rgba(232,145,159,.07)}
.sap-stage{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:hidden;background:#fff;
           margin-top:10px;display:flex;justify-content:center;min-width:0}
.sap-frame{border:0;display:block;background:#fff;width:100%;max-width:100%}
.sap-widths{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.sap-path{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.55}
.sap-path b{color:inherit}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'setap';
  var BASE = (window.KBB_ADMIN_BASE || '') + '/admin-api';

  var tabs = null;        // the payload's field groups
  var defaults = null;    // what "shipped" means, from the server
  var saved = null;       // key => value as the server last told us
  var draft = null;       // key => value as the owner has typed it
  var banner = null;
  var busy = false;
  var seq = 0;
  var open = 'desk';      // 'desk' | 'mob'
  var frameW = 390;
  var pvTimer = null;
  /* The last preview document, kept in a MODULE variable rather than on the
     iframe's dataset: render() replaces #content wholesale, so the element the
     dataset lived on is thrown away — switching tabs left the frame blank until
     the next debounce fired, which reads as a preview that has stopped
     working. */
  var pvHtml = null;

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* X-XSRF-TOKEN READ FROM THE XSRF-TOKEN COOKIE, which is what app.blade.php's
     own api() has always sent, and what the Banners and Checkout page screens
     send. A <meta name="csrf-token"> tag is what the shoppable-video screens
     reached for and this console does not render one.
     WITHOUT IT EVERY POST ANSWERS 419 — measured: the live preview never drew
     at all and the console log carried nothing but "419 (unknown status)",
     which reads like a broken endpoint and is a missing header. */
  function headers() {
    return {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': cookie('XSRF-TOKEN')
    };
  }

  async function api(path, opts) {
    var r = await fetch(BASE + path, Object.assign({
      credentials: 'same-origin',
      headers: headers()
    }, opts || {}));

    if (!r.ok) {
      var body = null;
      try { body = await r.json(); } catch (e) {}
      var err = new Error('http ' + r.status);
      err.status = r.status;
      err.body = body;
      throw err;
    }

    return r.json();
  }

  /* A 404 from these endpoints almost always means the package shipped without
     its clear_caches migration having run, so the compiled route table does not
     know these paths. Said plainly rather than drawing an empty screen, which
     here would read as "this shop has no set controls" — the exact wrong
     conclusion. */
  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Set appearance endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Set',
      icon: '<circle cx="8.5" cy="12" r="4.2"/><circle cx="14" cy="12" r="4.2"/><circle cx="19" cy="12" r="1.6"/>',
      group: 'Appearance',
      after: ['cartpanel', 'productpage']
    });
  }

  /* ------------------------------------------------------------- the draft */
  function startDraft() {
    draft = {};
    Object.keys(saved || {}).forEach(function (k) { draft[k] = saved[k]; });
  }

  /*
   * Field by field, so the bar can say HOW MANY rather than merely "yes". A
   * count is what tells the owner whether the thing he just changed registered.
   * Loose comparison through String() on purpose: a checkbox gives true/false
   * and the server gives 1/0 for the same column, and a bar that called that a
   * change would say "unsaved" the moment the screen opened.
   */
  function changed() {
    var out = [];
    if (!draft || !saved) return out;

    Object.keys(saved).forEach(function (k) {
      if (String(draft[k] == null ? '' : draft[k]) !== String(saved[k] == null ? '' : saved[k])) out.push(k);
    });

    return out;
  }

  function dirty() { return changed().length > 0; }

  /* How many settings differ from what the package SHIPPED. Rule 1 made
     visible: a fresh shop reads "nothing moved", and the owner can see at a
     glance whether he is looking at his own choices or at the defaults. */
  function movedFromShipped() {
    var out = [];
    if (!draft || !defaults) return out;

    Object.keys(defaults).forEach(function (k) {
      if (String(draft[k] == null ? '' : draft[k]) !== String(defaults[k] == null ? '' : defaults[k])) out.push(k);
    });

    return out;
  }

  /* THE GUARD ON LEAVING. Two doors out of this screen come through here —
     another screen in the sidebar, and a reload. The third, closing the tab, is
     beforeunload at the bottom of this file, which a browser will only honour
     as a generic prompt. */
  function mayLeave(what) {
    if (!dirty()) return true;
    return window.confirm('You have ' + changed().length + ' unsaved change(s) to the set.\n\n'
      + (what || 'Leave them?') + '\n\nPress Cancel to go back and press Save first.');
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) {
      if (draft && !mayLeave('Leave this screen and lose them?')) return undefined;
      draft = null;
      return previousGo.apply(this, arguments);
    }

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });

    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Set';

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
  async function load() {
    var mine = ++seq;
    busy = true;
    render();

    try {
      var body = await api('/set-appearance');
      if (mine !== seq) return;
      tabs = body.tabs;
      defaults = body.defaults;
      saved = {};
      tabs.forEach(function (t) {
        t.fields.forEach(function (f) { saved[f.key] = f.value; });
      });
      startDraft();
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not read the set appearance settings.');
      tabs = null;
    } finally {
      if (mine === seq) { busy = false; render(); refreshPreview(); }
    }
  }

  async function save() {
    if (!draft) return;

    var keys = changed();
    if (!keys.length) { say('Nothing to save.'); return; }

    var payload = {};
    keys.forEach(function (k) { payload[k] = draft[k]; });

    try {
      await api('/set-appearance', { method: 'POST', body: JSON.stringify({ settings: payload }) });
      keys.forEach(function (k) { saved[k] = draft[k]; });
      say('Saved ' + keys.length + ' change(s).');
      render();
    } catch (e) {
      say(explain(e, 'Could not save.'));
    }
  }

  /* -------------------------------------------------------------- drawing */
  function fieldsOf(prefix) {
    return (tabs || []).filter(function (t) { return t.key.indexOf(prefix) === 0; });
  }

  /* What the number beside a slider reads. The three list sizes are stored in
     TENTHS of a pixel — 13.5px cannot be a whole number — so the unit says
     "/10 px" and this divides. One place does it, and App\Services\
     SetAppearance prints the same division into the stylesheet, so the slider
     and the page cannot disagree by a factor of ten. */
  function shown(f) {
    var v = draft[f.key];
    var o = f.options || {};
    var u = o.unit == null ? '' : o.unit;

    if (u === '/10 px') return (Number(v) / 10) + 'px';
    if (u === '/100 em') return (Number(v) / 100) + 'em';
    if (u === '/100') return String(Number(v) / 100);

    return String(v) + u;
  }

  function isShipped(f) {
    return String(draft[f.key] == null ? '' : draft[f.key])
      === String(defaults[f.key] == null ? '' : defaults[f.key]);
  }

  function fieldHTML(f) {
    var id = 'sap-' + f.key;
    var help = f.help ? '<p class="sap-help">' + esc(f.help) + '</p>' : '';
    /* Only drawn when the value is NOT the shipped one, so a fresh screen
       carries no furniture at all and the button's presence is itself the
       answer to "have I moved this". */
    var reset = isShipped(f) ? ''
      : '<button type="button" class="sap-tiny" data-sap-reset="' + esc(f.key) + '">shipped</button>';

    if (f.type === 'bool') {
      return '<div class="sap-f"><div class="sap-check">'
        + '<input type="checkbox" id="' + id + '" data-sap-key="' + esc(f.key) + '"'
        + (draft[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + reset + '</div></div>';
    }

    if (f.type === 'colour') {
      /* TWO CONTROLS OVER ONE VALUE, because the value may be EMPTY and a
         native colour input cannot hold "nothing" — it answers #000000 for an
         empty value and would turn "keep the theme's own colour" into black the
         first time the owner opened the screen. So the text box is the value
         and the swatch is a picker that writes into it. */
      var hex = String(draft[f.key] == null ? '' : draft[f.key]);
      var swatch = /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : '#ffffff';

      return '<div class="sap-f"><div class="sap-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + reset + '</div><div class="sap-col">'
        + '<input type="color" value="' + esc(swatch) + '" data-sap-swatch="' + esc(f.key) + '"'
        + ' aria-label="' + esc(f.label) + ' colour picker">'
        + '<input type="text" id="' + id + '" data-sap-key="' + esc(f.key) + '"'
        + ' value="' + esc(hex) + '" placeholder="theme’s own" spellcheck="false">'
        + '</div>' + help + '</div>';
    }

    var o = f.options || {};
    return '<div class="sap-f"><div class="sap-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
      + '<span class="sap-val" data-sap-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span>'
      + reset + '</div>'
      + '<input type="range" id="' + id + '" data-sap-key="' + esc(f.key) + '"'
      + ' min="' + esc(o.min) + '" max="' + esc(o.max) + '" step="' + esc(o.step) + '"'
      + ' value="' + esc(draft[f.key]) + '">'
      + help + '</div>';
  }

  function cardHTML(t) {
    return '<div class="sap-card"><div class="sap-title">' + esc(t.label) + '</div>'
      + '<p class="sap-sub">' + esc(t.description) + '</p>'
      + '<div class="sap-grid">' + t.fields.map(fieldHTML).join('') + '</div></div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Set') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="sap-wrap"><div class="sap-card">Loading…</div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="sap-wrap"><div class="sap-card">'
        + '<div class="sap-title">Set</div>'
        + '<div class="sap-banner">' + esc(banner || 'Nothing to show yet.') + '</div>'
        + '<button type="button" class="sap-btn" data-sap-reload>Retry</button>'
        + '</div></div>';
      return;
    }

    var n = changed().length;
    var moved = movedFromShipped().length;

    var strip = '<div class="sap-tabs">'
      + '<button type="button" class="sap-tab" data-sap-tab="desk" aria-selected="'
      + (open === 'desk' ? 'true' : 'false') + '">Desktop</button>'
      + '<button type="button" class="sap-tab" data-sap-tab="mob" aria-selected="'
      + (open === 'mob' ? 'true' : 'false') + '">Mobile</button>'
      + '</div>';

    var bar = '<div class="sap-bar' + (n ? ' is-dirty' : '') + '">'
      + '<span class="sap-count">' + (n ? n + ' unsaved change' + (n === 1 ? '' : 's') : 'No unsaved changes')
      + ' · ' + (moved ? moved + ' setting' + (moved === 1 ? '' : 's') + ' moved from shipped'
                            : 'everything is at the value the page shipped with') + '</span>'
      + '<button type="button" class="sap-btn" data-sap-discard' + (n ? '' : ' disabled') + '>Discard</button>'
      + '<button type="button" class="sap-btn is-primary" data-sap-save' + (n ? '' : ' disabled') + '>Save</button>'
      + '</div>';

    var cards = fieldsOf(open === 'desk' ? 'd_' : 'm_').map(cardHTML).join('');

    var mobNote = open === 'mob'
      ? '<div class="sap-note">These are the phone’s own measurements. <b>What is drawn, every '
        + 'weight and every colour are shared with Desktop</b> and are set on that tab — an element '
        + 'switched off there is off here too. The phone never inherits a size from the laptop: the shop '
        + 'already draws several of these differently at the two widths, so a value that quietly followed '
        + 'the other one would be wrong the day it shipped.</div>'
      : '';

    var preview = '<div class="sap-card"><div class="sap-title">Live preview</div>'
      + '<p class="sap-sub">Drawn from what you have typed, not from what is saved — nothing here writes '
      + 'anything. It is the real set row the cart and the checkout draw, in a frame, so the phone and '
      + 'desktop sizes resolve against the frame’s width the way they do on a real screen. '
      + 'Press “What’s inside” in the frame: the popup really opens.</p>'
      + '<div class="sap-widths">'
      + '<button type="button" class="sap-btn' + (frameW === 390 ? ' is-primary' : '') + '" data-sap-w="390">Phone · 390</button>'
      + '<button type="button" class="sap-btn' + (frameW === 760 ? ' is-primary' : '') + '" data-sap-w="760">Tablet · 760</button>'
      + '<button type="button" class="sap-btn' + (frameW === 1280 ? ' is-primary' : '') + '" data-sap-w="1280">Desktop · 1280</button>'
      + '</div>'
      + '<div class="sap-stage"><iframe class="sap-frame" id="sap-frame" title="Set preview" '
      + 'style="width:' + frameW + 'px;height:340px" sandbox="allow-scripts"></iframe></div></div>';

    var where = '<div class="sap-card"><div class="sap-title">Where these controls land on the shop</div>'
      + '<p class="sap-path"><b>Cart page rows</b> — every product row on /cart, set or not. '
      + 'Their padding was hard-coded in the stylesheet until this release.<br>'
      + '<b>Set box</b> — the circles and the “What’s inside” popup under a set’s name in the '
      + 'cart drawer, on the cart page, in the checkout summary, in the browsed rail and on an order’s '
      + 'detail page.<br>'
      + '<b>Set list</b> — “What is in this set” in the buy column of a set’s own product page.</p>'
      + '<p class="sap-path">There is no font FAMILY here on purpose: this shop has one typographic '
      + 'system, and a family belongs to a site-wide typography setting rather than to the set. Sizes and '
      + 'weights are safe and are all here.</p></div>';

    host.innerHTML = '<div class="sap-wrap">' + bar + strip + mobNote + cards + preview + where + '</div>';

    var frame = document.querySelector('#sap-frame');
    if (frame && pvHtml) frame.srcdoc = pvHtml;
  }

  /* The preview, redrawn from the BUFFER and debounced. Debounced because a
     slider fires `input` on every pixel of the drag and a request per pixel is
     a request per pixel; 260ms is below the point a redraw reads as a response
     to something else. */
  function schedulePreview() {
    if (pvTimer) clearTimeout(pvTimer);
    pvTimer = setTimeout(refreshPreview, 260);
  }

  async function refreshPreview() {
    if (!draft) return;

    var frame = document.querySelector('#sap-frame');
    if (!frame) return;

    try {
      var r = await fetch(BASE + '/set-appearance/preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: Object.assign(headers(), { Accept: 'text/html' }),
        body: JSON.stringify({ settings: draft })
      });

      if (!r.ok) return;

      pvHtml = await r.text();
      frame.srcdoc = pvHtml;
    } catch (e) { /* a preview that cannot be drawn is not an error worth a toast */ }
  }

  /* ------------------------------------------------------------- handlers */
  function onScreen(el) {
    return !!(el && el.closest && el.closest('.sap-wrap'));
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!onScreen(t)) return;

    var tab = t.closest('[data-sap-tab]');
    if (tab) { open = tab.dataset.sapTab; render(); return; }

    var w = t.closest('[data-sap-w]');
    if (w) { frameW = Number(w.dataset.sapW); render(); return; }

    var reset = t.closest('[data-sap-reset]');
    if (reset) { draft[reset.dataset.sapReset] = defaults[reset.dataset.sapReset]; render(); schedulePreview(); return; }

    if (t.closest('[data-sap-save]')) { save(); return; }

    if (t.closest('[data-sap-discard]')) {
      if (!window.confirm('Put back ' + changed().length + ' change(s)?')) return;
      startDraft();
      render();
      schedulePreview();
      return;
    }

    if (t.closest('[data-sap-reload]')) { load(); }
  });

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!onScreen(el)) return;

    var swatch = el.closest && el.closest('[data-sap-swatch]');
    if (swatch) {
      draft[swatch.dataset.sapSwatch] = swatch.value;
      var box = document.querySelector('[data-sap-key="' + swatch.dataset.sapSwatch + '"]');
      if (box) box.value = swatch.value;
      schedulePreview();
      return;
    }

    var f = el.closest && el.closest('[data-sap-key]');
    if (!f) return;

    var key = f.dataset.sapKey;

    /*
     * BRANCH ON THE FIELD'S OWN TYPE AND NOT ON THE ELEMENT'S. The Checkout page
     * screen shipped three selects that fell through to a range branch and saved
     * as NaN, because the handler read Number(el.value) for everything. Here a
     * checkbox reads .checked, a colour reads .value as a STRING (it may legally
     * be empty, and Number('') is 0, which would store black), and only a range
     * is a number.
     */
    if (f.type === 'checkbox') {
      draft[key] = f.checked;
    } else if (f.type === 'range') {
      draft[key] = Number(f.value);
      var out = document.querySelector('[data-sap-val="' + key + '"]');
      if (out) {
        var field = null;
        (tabs || []).forEach(function (t) { t.fields.forEach(function (x) { if (x.key === key) field = x; }); });
        if (field) out.textContent = shown(field);
      }
    } else {
      draft[key] = f.value;
    }

    /*
     * ── THE COUNT IS UPDATED IN PLACE, AND THAT IS A FIX RATHER THAN A
     *    REFINEMENT ─────────────────────────────────────────────────────────
     *
     * This used to re-render only when the DIRTINESS FLIPPED, on the argument
     * that a redraw per pixel of a drag would take the focus off the slider the
     * owner is holding. The first half of that is right and the second half was
     * a bug: after the FIRST change the screen is already dirty, so nothing
     * flipped again and the bar kept saying "1 unsaved change" however many
     * more controls were moved. Measured in Chromium — five controls moved, bar
     * reads 1 — which is exactly the silence the count exists to remove, since
     * a count is what tells him whether the thing he just changed registered.
     *
     * So: the numbers are written straight into the bar on every input, and the
     * full redraw waits for `change`, which a slider fires when it is RELEASED.
     * Dragging gives live numbers and keeps the thumb under the pointer; letting
     * go brings the per-field "shipped" buttons up to date.
     */
    refreshBar();
    schedulePreview();
  });

  /* A slider fires `change` on release and a checkbox and a colour box fire it
     immediately, which is when a full redraw is both affordable and wanted. */
  document.addEventListener('change', function (e) {
    if (!onScreen(e.target)) return;
    if (!(e.target.closest && e.target.closest('[data-sap-key]'))) return;
    render();
  });

  function refreshBar() {
    var bar = document.querySelector('.sap-bar');
    var count = document.querySelector('.sap-count');
    if (!bar || !count) return;

    var n = changed().length;
    var moved = movedFromShipped().length;

    bar.classList.toggle('is-dirty', n > 0);
    count.textContent = (n ? n + ' unsaved change' + (n === 1 ? '' : 's') : 'No unsaved changes')
      + ' \u00b7 ' + (moved ? moved + ' setting' + (moved === 1 ? '' : 's') + ' moved from shipped'
                            : 'everything is at the value the page shipped with');

    var save = document.querySelector('[data-sap-save]');
    var discard = document.querySelector('[data-sap-discard]');
    if (save) save.disabled = n === 0;
    if (discard) discard.disabled = n === 0;
  }

  window.addEventListener('beforeunload', function (e) {
    if (!dirty()) return undefined;
    e.preventDefault();
    e.returnValue = '';
    return '';
  });

  addNavEntry();
})();
</script>
@endverbatim
