{{--
    Appearance → Site layout.                                          Lane W1

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry and toast(). It registers its own
    sidebar entry and wraps window.go, exactly as the screens beside it do, so
    that one include is the whole of the change to that file.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    "site width max i need 1680 px, but it must be auto adjust in below width
    screens, and for mobile is fine. please make super strong options and
    features for this. the site should fit on any kind of device automatically,
    and on 1680px the grid products will show 1 column extra, and in low, one
    less and so on, give options to control also for the whole layout."

    So: one width for the whole shop, a gutter, and a product grid whose column
    count is worked out from the row it has rather than from a breakpoint.
    App\Services\SiteLayout carries the schema and the argument for every
    default, including the census that found FOURTEEN page-container widths
    disagreeing six ways before there was one.

    ── THE TABLE IS THE POINT OF THIS SCREEN ────────────────────────────────

    A width slider on its own tells the owner nothing: he asked for the shop to
    fit every device, and the only way to show that is to show every device. So
    the preview is a table of seventeen real screen widths — 320 to 2560 — with
    the container width and the column count the current settings produce at
    each, recomputed as a slider moves and before anything is saved.

    IT IS ARITHMETIC, NOT MEASUREMENT. Nothing here reads getBoundingClientRect
    or offsetWidth; it runs the same three expressions the stylesheet runs, on
    numbers. Rule 4 forbids JavaScript that measures layout ON THE SHOP, and the
    reason it gives — that a rendered-once CSS answer beats a scripted one — is
    exactly why this screen must not measure either: a preview that measured a
    mock would be showing the mock's layout, not the shop's. The formulas below
    are a deliberate copy of kbb.css's, and the comment there says so.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included
    — with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto. The table
    scrolls inside its own box rather than pushing the column.

    EVERY CLASS IS PREFIXED sls- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks: app.blade.php binds delegated
    listeners to `document` itself, each claiming a bare attribute name, and a
    click on any element carrying one is handled by that listener whichever
    screen it belongs to.

    ── THE CATEGORY HEADER PREVIEW (Lane PY) ───────────────────────────────

    On the two Category header tabs the device table gives way to a preview
    of the header itself -- a busy picture, a light picture and the light box,
    at phone or laptop size -- redrawn as a control moves, before anything is
    saved. It is drawn by THE STOREFRONT'S OWN STYLESHEET, loaded just below,
    with the same classes and the same custom properties
    App\Support\TitleHeader writes, so what the owner sees here is the shop's
    CSS and not an imitation of it. Every selector in that file is prefixed
    .kbb-th and appears nowhere else in the console. Nothing in it measures
    anything: phone and laptop are the two sets of numbers, not a resized
    window.
--}}
@vite('resources/css/kbb/kbb-title-header.css')
@verbatim
<style>
.sls-wrap{display:grid;gap:14px;min-width:0}
.sls-wrap > *{min-width:0}
.sls-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.sls-title{font-weight:650;font-size:15px}
.sls-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.sls-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.sls-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.sls-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.sls-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.sls-f{display:grid;gap:5px;min-width:0}
.sls-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.sls-fh label{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.sls-val{font-size:11.5px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap;
         font-variant-numeric:tabular-nums}
.sls-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.sls-f input[type=range]{width:100%;accent-color:var(--accent,#15a85a);margin:0;min-width:0}
.sls-f select{width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.sls-f input[type=text]{width:100%;max-width:160px;min-width:0;box-sizing:border-box;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.sls-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.sls-check input{margin-top:3px;flex:none;width:16px;height:16px}
.sls-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.sls-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.sls-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.sls-btn[disabled]{opacity:.45;cursor:default}
.sls-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.sls-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}

/* The table scrolls inside its own box. A wide table in a grid item whose
   min-width is auto pushes the whole console column sideways at 390px. */
.sls-scroll{overflow-x:auto;min-width:0;-webkit-overflow-scrolling:touch}
.sls-t{border-collapse:collapse;font-size:12px;font-variant-numeric:tabular-nums;min-width:100%}
.sls-t th,.sls-t td{padding:5px 9px;text-align:end;white-space:nowrap;
  border-bottom:1px solid var(--border,#e6e6e6)}
.sls-t th:first-child,.sls-t td:first-child{text-align:start}
.sls-t thead th{font-weight:650;font-size:11px;color:var(--ink-soft,#6b7280);text-transform:uppercase;
  letter-spacing:.04em}
.sls-t tbody tr.is-target td{background:rgba(21,168,90,.09);font-weight:650}
.sls-t td.is-up{color:var(--accent,#15a85a)}
.sls-cap{font-size:11.5px;color:var(--ink-soft,#6b7280);margin:9px 0 0;line-height:1.5;max-width:68ch}
.sls-css{margin-top:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;
  line-height:1.6;background:rgba(127,127,127,.08);border-radius:9px;padding:10px 11px;
  overflow-wrap:anywhere;min-width:0}
@media (max-width:640px){ .sls-card{padding:13px} .sls-t th,.sls-t td{padding:5px 7px} }

/* A colour: the picker and the hex box side by side (Lane PY). */
.sls-colour{display:flex;gap:8px;align-items:center;min-width:0}
.sls-colour input[type=color]{width:42px;height:36px;padding:2px;border:1px solid var(--border,#e6e6e6);
  border-radius:9px;background:transparent;flex:none;cursor:pointer}

/* The Category header preview (Lane PY). */
.sls-pv-bar{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;min-width:0}
.sls-pv-list{display:grid;gap:14px;margin-top:12px;min-width:0}
.sls-pv-list > *{min-width:0}
.sls-pv-cap{font-size:11.5px;font-weight:650;color:var(--ink-soft,#6b7280);margin:0 0 6px}
.sls-pv-frame{min-width:0;margin:0 auto;width:100%}
.sls-pv-frame.is-phone{max-width:390px}
.sls-pv-frame .kbb-th{margin:0}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'sitelayout';

  /* UNFINISHED CHANGES (Lane PM). Leaving this screen with edits in `values`
     used to throw them away without a word. They are kept in Unfinished in
     the top bar instead (partials/unfinished-drafts.blade.php), and come back
     into `values` when the screen is next opened. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Appearance → Site layout',
    values: function () { return tabs ? values : null; },
    set: function (k, v) { if (Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); },
    save: function () { save(); }
  });

  var tabs = null, values = {}, open = null, banner = null, busy = false, seq = 0;
  var pvDevice = 'phone';
  var emittedCss = '', isDefault = true;

  /*
   * The seventeen widths the lane's evidence is measured at, and the same
   * seventeen the owner is shown. 1680 is marked, because it is the number he
   * asked for and the one row he will look for first.
   */
  var WIDTHS = [320, 360, 390, 414, 480, 600, 768, 834, 1024, 1180, 1280, 1366, 1440, 1536, 1680, 1920, 2560];

  /*
   * -- THE THREE EXPRESSIONS, COPIED FROM kbb.css ON PURPOSE ----------------
   *
   * container(w)  min(100%, --site-max) less two gutters. Exactly
   *               `.wrap{max-width:var(--site-max);padding-inline:var(--site-gutter)}`
   *               with the gutter's clamp() spelled out.
   *
   * columns(row)  the auto-fill count for a row of that width, which is the
   *               largest N with `N * track + (N - 1) * gap <= row`, where
   *               `track` is the min() of the three terms in that sheet's one
   *               grid rule. A COPY and not a reuse: the console does not load
   *               the storefront stylesheet, and a preview that guessed would be
   *               the one thing a preview must never be — different from the
   *               page. SiteLayoutColumnArithmeticTest drives the same numbers
   *               through PHP and pins them against real Chromium, so the copy
   *               cannot drift in silence.
   *
   * The /shop row is narrower than the page at the same screen size, because a
   * 250px filter rail and a 28px gap sit beside it above 900px. That is the
   * whole reason a viewport breakpoint could never get this right, so the table
   * shows both.
   */
  var SHOP_RAIL = 250, SHOP_RAIL_GAP = 28, SHOP_RAIL_FROM = 901;
  var RAIL_GUTTER = 28;   /* .kbb-home .sec > .wrap: clamp(18px, 2vw, 28px) */
  var RAIL_INSET = 24;    /* its own width: calc(100% - 24px) */

  function num(key, fallback) {
    var n = Number(values[key]);
    return isFinite(n) ? n : fallback;
  }
  function on(key) { return values[key] === true || values[key] === 1 || values[key] === '1'; }

  function gutter(w) {
    var lo = num('gutter', 22), hi = num('gutter_wide', 22);
    return Math.max(lo, Math.min(w * 0.022, Math.max(lo, hi)));
  }

  /* The page container, as `.wrap` computes it. */
  function container(w) {
    return Math.min(w, num('max', 1680)) - 2 * gutter(w);
  }

  /* The homepage section card's row, which is where the rails live. */
  function railRow(w) {
    var outer = Math.min(w - RAIL_INSET, num('max', 1680));
    return Math.max(0, outer - 2 * Math.max(18, Math.min(w * 0.02, RAIL_GUTTER)));
  }

  /* The /shop listing's row: the container, less the filter rail above 900px. */
  function shopRow(w) {
    var row = container(w);
    if (w >= SHOP_RAIL_FROM) row -= (SHOP_RAIL + SHOP_RAIL_GAP);
    return Math.max(0, row);
  }

  function columns(row, tile, gap) {
    if (row <= 0) return 0;
    var floorN = Math.max(1, num('cols_floor', 2));
    var capN = Math.max(1, num('cols_cap', 8));

    var track = Math.min(
      row,
      (row - (floorN - 1) * gap) / floorN,
      Math.max(tile, (row - (capN - 1) * gap) / capN)
    );
    var n = Math.floor((row + gap) / (track + gap));
    return Math.max(1, Math.min(n, capN));
  }

  function railCols(w) {
    var pin = String(values.pin || 'auto');
    if (pin !== 'auto' && w >= SHOP_RAIL_FROM) return Number(pin);
    return columns(railRow(w), num('tile', 220), num('gap', 16));
  }

  function shopCols(w) {
    /* The shopper's own 2/3/4 buttons pin this grid and always win; with none
       chosen it is automatic, which is what the default is now. */
    return columns(shopRow(w), num('tile_shop', 220), w <= 680 ? 12 : 18);
  }

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status; err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Site layout endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Site layout',
      icon: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="M15 4v16"/>',
      group: 'Appearance',
      /* The ids as app.blade.php's own nav list spells them: the Product styles
         row is `prodstyles` and the grid-skin picker is `layout`, not
         `productstyles`. AdminNavAndIdsTest checks an anchor against the rows
         registered BEFORE this partial and refused the invented one. */
      after: ['layout', 'prodstyles', 'header', 'dividers']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Site layout';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();

    try {
      var body = await api('/site-layout');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      emittedCss = body.css || '';
      isDefault = body.is_default !== false;
      values = {};
      tabs.forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Site layout settings could not be read.');
    } finally {
      if (mine === seq) {
        busy = false; render();
        if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
    }
  }

  async function save() {
    if (busy) return;
    busy = true; render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });

    try {
      var body = await api('/site-layout', { settings: payload });
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      emittedCss = body.css || '';
      isDefault = body.is_default !== false;
      say('Site layout saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false; render();
    }
  }

  function shown(f) {
    return String(values[f.key]) + ((f.options || {}).unit || '');
  }

  function fieldHTML(f) {
    var id = 'sls-' + f.key;
    var help = f.help ? '<p class="sls-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="sls-f"><div class="sls-check">'
        + '<input type="checkbox" id="' + id + '" data-sls-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '')
          + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="sls-f"><div class="sls-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + id + '" data-sls-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="sls-f"><div class="sls-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="sls-val" data-sls-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
        + '<input type="range" id="' + id + '" data-sls-key="' + esc(f.key) + '"'
        + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
        + help + '</div>';
    }

    /* A colour (Lane PY: the light box's own colours). The picker and the
       hex box write the same value; the server is the judge -- SiteLayout
       keeps #rgb or #rrggbb and refuses anything else with a 422. */
    if (f.type === 'colour') {
      var hex = String(values[f.key] || '');
      var six = /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : '#000000';
      return '<div class="sls-f"><div class="sls-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<div class="sls-colour">'
        + '<input type="color" data-sls-key="' + esc(f.key) + '" value="' + esc(six) + '" aria-label="' + esc(f.label) + '">'
        + '<input type="text" id="' + id + '" data-sls-key="' + esc(f.key) + '" value="' + esc(hex) + '" maxlength="7" autocomplete="off" spellcheck="false">'
        + '</div>' + help + '</div>';
    }

    /* A typed whole number (Lane PI-B: "My own number" on Loading more
       products). The phone keyboard opens on digits; the server is still the
       judge — SiteLayout clamps it to 4–96 and refuses anything else. */
    var numeric = f.type === 'int' ? ' inputmode="numeric" pattern="[0-9]*"' : '';
    return '<div class="sls-f"><div class="sls-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-sls-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '" autocomplete="off"' + numeric + '>' + help + '</div>';
  }

  /*
   * ── THE CATEGORY HEADER PREVIEW (Lane PY) ─────────────────────────────────
   *
   * "please give me multiple options to chooose from" -- and an option is only
   * a choice if he can see it. So the two Category header tabs show the header
   * itself, three times: over a busy dark picture, over a light picture, and as
   * the light box a category with no picture gets. Redrawn as a control moves,
   * before anything is saved.
   *
   * THE SAME RESOLUTION AS App\Support\TitleHeader, on the unsaved values: the
   * same classes, the same custom properties, drawn by the storefront's own
   * stylesheet (loaded at the top of this partial). A deliberate copy, like the
   * width arithmetic above, and pinned the same way: PyCategoryHeaderOptionsTest
   * reads the class words and property names out of this file and out of
   * TitleHeader and requires them to agree.
   *
   * Phone and Laptop are the two SETS OF NUMBERS, not a resized window: the
   * phone preview writes the phone value into both the phone and the laptop
   * property, so the stylesheet's 900px switch has nothing to switch between.
   * Nothing here measures anything.
   *
   * The two pictures are constant SVGs drawn here -- no request, and nothing a
   * setting can reach. Every value that reaches the markup is escaped, and the
   * words are checked against the same lists the server uses.
   */
  var PV_ALIGNS = ['start', 'center', 'end'];
  var PV_TREATMENTS = ['shadow', 'fade', 'frost', 'label', 'none'];
  var PV_BOXES = ['blush', 'cream', 'mint', 'lilac', 'plain', 'custom'];
  var PV_ICON_BOXES = ['blush', 'cream', 'mint', 'lilac', 'custom'];

  /* [phone property, phone setting, laptop setting]; the laptop property is the
     phone one with a `d` -- TitleHeader::PX_VARS spells every pair that way. */
  var PV_PAIRS = [
    ['--kbb-th-h', 'cat_header_h_phone', 'cat_header_h_desktop'],
    ['--kbb-th-ts', 'cat_header_title_phone', 'cat_header_title_desktop'],
    ['--kbb-th-ds', 'cat_header_desc_phone', 'cat_header_desc_desktop'],
    ['--kbb-th-py', 'cat_header_pad_y_phone', 'cat_header_pad_y_desktop'],
    ['--kbb-th-px', 'cat_header_pad_x_phone', 'cat_header_pad_x_desktop']
  ];

  function pvPicture(dark) {
    var svg = dark
      ? "<svg xmlns='http://www.w3.org/2000/svg' width='1600' height='500' viewBox='0 0 1600 500'>"
        + "<defs><linearGradient id='g' x1='0' x2='1'><stop offset='0' stop-color='#7a2e3b'/><stop offset='.5' stop-color='#d9774f'/><stop offset='1' stop-color='#2f5e74'/></linearGradient></defs>"
        + "<rect width='1600' height='500' fill='url(#g)'/>"
        + "<g fill='#fff' fill-opacity='.22'><circle cx='180' cy='120' r='120'/><circle cx='520' cy='380' r='170'/><circle cx='980' cy='90' r='140'/><circle cx='1350' cy='330' r='190'/></g>"
        + "<g fill='#1b0f14' fill-opacity='.35'><rect x='300' y='60' width='70' height='260' rx='18'/><rect x='420' y='120' width='90' height='220' rx='20'/><rect x='1120' y='80' width='80' height='300' rx='18'/><rect x='760' y='200' width='160' height='120' rx='26'/></g>"
        + "<g fill='#fff' fill-opacity='.5'><rect x='315' y='40' width='40' height='28' rx='6'/><rect x='440' y='96' width='50' height='30' rx='6'/><rect x='1135' y='56' width='50' height='30' rx='6'/></g>"
        + "</svg>"
      : "<svg xmlns='http://www.w3.org/2000/svg' width='1600' height='500' viewBox='0 0 1600 500'>"
        + "<defs><linearGradient id='g' x1='0' x2='1'><stop offset='0' stop-color='#fbe9e7'/><stop offset='.55' stop-color='#f6f1e7'/><stop offset='1' stop-color='#e3f1ef'/></linearGradient></defs>"
        + "<rect width='1600' height='500' fill='url(#g)'/>"
        + "<g fill='#ffffff' fill-opacity='.7'><circle cx='240' cy='110' r='130'/><circle cx='900' cy='420' r='180'/><circle cx='1420' cy='120' r='150'/></g>"
        + "<g fill='#e8c4c0'><rect x='1040' y='130' width='80' height='250' rx='20'/><rect x='1160' y='190' width='110' height='190' rx='24'/><rect x='1310' y='240' width='150' height='140' rx='30'/></g>"
        + "</svg>";
    return 'data:image/svg+xml,' + encodeURIComponent(svg);
  }

  function pvNum(key) {
    var n = parseInt(values[key], 10);
    return isFinite(n) ? n : 0;
  }

  function pvPick(v, list, fallback) {
    return list.indexOf(String(v)) !== -1 ? String(v) : fallback;
  }

  function pvHex(v, fallback) {
    var s = String(v == null ? '' : v).trim().toUpperCase();
    var m = /^#([0-9A-F])([0-9A-F])([0-9A-F])$/.exec(s);
    if (m) s = '#' + m[1] + m[1] + m[2] + m[2] + m[3] + m[3];
    return /^#[0-9A-F]{6}$/.test(s) ? s : fallback;
  }

  /* WCAG relative luminance -- TitleHeader::luminance(), the same formula. */
  function pvLum(hex) {
    var c = [1, 3, 5].map(function (i) {
      var v = parseInt(hex.substr(i, 2), 16) / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }

  function pvHeader(kind, picture) {
    var align = pvPick(values.cat_header_align, PV_ALIGNS, 'start');
    var treatment = pvPick(kind === 'img' ? values.cat_header_treatment : values.cat_header_box_treatment,
      PV_TREATMENTS, kind === 'img' ? 'shadow' : 'none');
    var box = kind === 'box' ? pvPick(values.cat_header_box_style, PV_BOXES, 'blush') : null;
    var ground = pvHex(values.cat_header_box_bg, '#FFF4EE');
    var ink = pvHex(values.cat_header_box_icon, '#EFA889');
    var text = String(values.cat_header_text || 'auto');
    var own = box === 'plain' || box === 'custom';
    var tone = (text === 'light' || text === 'dark') ? text
      : (kind === 'img' ? 'light' : (own && pvLum(ground) < 0.4 ? 'light' : 'dark'));

    var cls = 'kbb-th kbb-th--flush kbb-th--' + kind + ' kbb-th--' + tone + ' kbb-th--a-' + align
      + ' kbb-th--t-' + treatment + (box ? ' kbb-th--box-' + box : '');

    var phone = pvDevice === 'phone';
    var style = PV_PAIRS.map(function (p) {
      var v = pvNum(phone ? p[1] : p[2]);
      return p[0] + ':' + v + 'px;' + p[0] + 'd:' + v + 'px';
    });
    style.push('--kbb-th-r:' + pvNum('cat_header_radius') + 'px');
    style.push('--kbb-th-mw:' + pvNum('cat_header_maxw') + 'px');
    style.push('--kbb-th-ov:' + (Math.max(0, Math.min(85, pvNum('cat_header_overlay'))) / 100));
    style.push('--kbb-th-lines:' + Math.max(1, Math.min(10, pvNum('cat_header_lines'))));
    style.push('--kbb-th-tw:' + pvPick(values.cat_header_weight, ['500', '600', '700', '800'], '700'));
    style.push('--kbb-th-tile:' + (phone ? 220 : 280) + 'px');
    if (own) style.push('--kbb-th-bg:' + ground);
    if (box === 'custom') style.push('--kbb-th-ic:' + ink);

    var layer = kind === 'img'
      ? '<img class="kbb-th__img" src="' + esc(picture) + '" alt="">'
      : (PV_ICON_BOXES.indexOf(box) !== -1 ? '<div class="kbb-th__icons" aria-hidden="true"></div>' : '');

    return '<section class="' + esc(cls) + '" style="' + esc(style.join(';')) + '">'
      + layer
      + '<div class="kbb-th__scrim" aria-hidden="true"></div>'
      + '<div class="kbb-th__inner"><div class="kbb-th__text">'
      + '<div class="kbb-th__title">Korean Sunscreens</div>'
      + '<div class="kbb-th__desc"><p>Lightweight Korean sunscreens with high UV protection, made for everyday wear under the UAE sun &mdash; no white cast, no greasy finish.</p></div>'
      + '</div></div></section>';
  }

  function previewHTML() {
    var on = values.cat_header === true || values.cat_header === 1 || values.cat_header === '1';
    var boxOn = values.cat_header_box === true || values.cat_header_box === 1 || values.cat_header_box === '1';

    function block(caption, inner) {
      return '<div><p class="sls-pv-cap">' + esc(caption) + '</p>'
        + '<div class="sls-pv-frame' + (pvDevice === 'phone' ? ' is-phone' : '') + '">' + inner + '</div></div>';
    }

    var device = ['phone', 'laptop'].map(function (d) {
      return '<button type="button" class="sls-tab" data-sls-pv="' + d + '" aria-selected="'
        + (pvDevice === d ? 'true' : 'false') + '">' + (d === 'phone' ? 'Phone' : 'Laptop') + '</button>';
    }).join('');

    return '<div class="sls-card">'
      + '<div class="sls-title">Preview</div>'
      + '<p class="sls-sub">Drawn with the shop’s own header stylesheet from the values on this screen, before '
      + 'anything is saved. A category’s own choices (Catalog → Categories → Edit → Category header) '
      + 'are laid over these.</p>'
      + (on ? '' : '<div class="sls-note" style="margin-top:10px">The header is switched off: every category page shows '
          + 'its plain title. This is how it would look switched on.</div>')
      + '<div class="sls-pv-bar">' + device + '</div>'
      + '<div class="sls-pv-list">'
      + block('1 · Over a busy, dark picture', pvHeader('img', pvPicture(true)))
      + block('2 · Over a light picture', pvHeader('img', pvPicture(false)))
      + block('3 · No picture — the light box' + (boxOn ? '' : ' (switched off: such a category keeps its plain title)'),
          pvHeader('box', null))
      + '</div></div>';
  }

  /* The card under the fields: the device table, or on the two Category
     header tabs the header preview. Nothing on "Loading more products". */
  function extraHTML(key) {
    if (key === 'loading') return '';
    if (key === 'catheader' || key === 'catheadersize') return previewHTML();
    return tableHTML();
  }

  function tableHTML() {
    var rows = WIDTHS.map(function (w) {
      var c = Math.round(container(w));
      var rc = railCols(w), sc = shopCols(w);
      return '<tr' + (w === 1680 ? ' class="is-target"' : '') + '>'
        + '<td>' + w + 'px</td>'
        + '<td>' + c + 'px</td>'
        + '<td>' + rc + '</td>'
        + '<td>' + sc + '</td>'
        + '</tr>';
    }).join('');

    return '<div class="sls-card">'
      + '<div class="sls-title">Every screen, with these settings</div>'
      + '<p class="sls-sub">Recomputed as you move a slider, before anything is saved. '
      + 'The highlighted row is 1680px.</p>'
      + '<div class="sls-scroll" style="margin-top:12px"><table class="sls-t"><thead><tr>'
      + '<th>Screen</th><th>Page width</th><th>Cards · rails</th><th>Cards · shop</th>'
      + '</tr></thead><tbody>' + rows + '</tbody></table></div>'
      + '<p class="sls-cap"><b>Page width</b> is the content box: the screen, or the site width, '
      + 'whichever is smaller, less a gutter each side. <b>Cards · rails</b> is the homepage rails, '
      + 'a category, the wishlist, a brand page and the [kbb_products] shortcode. '
      + '<b>Cards · shop</b> is the /shop listing, which is narrower at the same screen size because '
      + 'the filter rail sits beside it — that is why one number cannot serve both, and why the count '
      + 'is worked out from each row rather than from a screen size.</p>'
      + '<p class="sls-cap">' + (isDefault
          ? 'Every setting is at its shipped value, so the shop sends <b>no extra stylesheet at all</b>. '
            + 'Nothing is added to any page until you move something.'
          : 'This shop currently sends:') + '</p>'
      + (isDefault ? '' : '<div class="sls-css">' + esc(emittedCss) + '</div>')
      + '</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Site layout') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="sls-wrap"><div class="sls-card"><div class="sls-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="sls-wrap"><div class="sls-card">'
        + '<div class="sls-title">Site layout</div>'
        + '<p class="sls-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="sls-actions"><button class="sls-btn" data-sls-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="sls-tab" data-sls-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    host.innerHTML = '<div class="sls-wrap">'
      + (banner ? '<div class="sls-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + '<div class="sls-note">The <b>cart page</b>, the <b>checkout</b> and the <b>slim footer</b> are '
      + 'not on this width. Each keeps its own Content width slider on its own screen — they are pages '
      + 'asking for money, and a narrow single column there is deliberate. Reading widths are not on it '
      + 'either: an article stays 720px wide however wide the page is.</div>'
      + '<div class="sls-card">'
      + '<div class="sls-tabs">' + strip + '</div>'
      + '<p class="sls-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="sls-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '<div class="sls-actions">'
      + '<button class="sls-btn is-primary" data-sls-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="sls-btn" data-sls-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      + '<button class="sls-btn" data-sls-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
      + '</div>'
      + '<p class="sls-help" style="margin-top:8px">“Back to defaults” moves only the sliders on '
      + '<b>this tab</b>. Nothing is stored until you press Save.</p>'
      + '</div>'
      /* The device table is about width and columns. On "Loading more
         products" it would be a page of numbers that no field on the tab can
         move, so it is not drawn there. (Lane PI-B) */
      + extraHTML(current.key)
      + '</div>';
  }

  /*
   * A slider moving repaints the table. The value readout is updated in place
   * on `input` and the whole screen only on `change`, so dragging a slider does
   * not rebuild the DOM under the thumb — which loses the drag.
   */
  document.addEventListener('input', function (e) {
    var el = e.target.closest ? e.target.closest('[data-sls-key]') : null;
    if (!el) return;

    var key = el.dataset.slsKey;
    values[key] = el.type === 'checkbox' ? el.checked : el.value;

    /* The two halves of a colour field follow each other (Lane PY). */
    if (el.type === 'color' || (el.type === 'text' && el.parentNode && el.parentNode.className === 'sls-colour')) {
      document.querySelectorAll('.sls-colour [data-sls-key="' + key + '"]').forEach(function (other) {
        if (other === el) return;
        if (other.type === 'color' && !/^#[0-9a-fA-F]{6}$/.test(el.value)) return;
        other.value = el.value;
      });
    }

    var out = document.querySelector('[data-sls-val="' + key + '"]');
    if (out && tabs) {
      var f = null;
      tabs.forEach(function (t) { t.fields.forEach(function (x) { if (x.key === key) f = x; }); });
      if (f) out.textContent = shown(f);
    }
    repaintTable();
  });

  document.addEventListener('change', function (e) {
    var el = e.target.closest ? e.target.closest('[data-sls-key]') : null;
    if (!el) return;
    values[el.dataset.slsKey] = el.type === 'checkbox' ? el.checked : el.value;
    if (el.type === 'checkbox' || el.tagName === 'SELECT') render();
    else repaintTable();
  });

  /* Only the table's own card, so the control you are holding is not replaced. */
  function repaintTable() {
    var host = document.querySelector('#content');
    if (!host || !tabs || open === 'loading') return;
    /* A colour box mid-typing ("#F") is not a colour yet; the preview keeps
       its last good one rather than flashing the fallback. pvHex() does that. */
    var cards = host.querySelectorAll('.sls-wrap > .sls-card');
    var last = cards[cards.length - 1];
    if (!last) return;
    var fresh = document.createElement('div');
    fresh.innerHTML = extraHTML(open);
    if (fresh.firstElementChild) last.replaceWith(fresh.firstElementChild);
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;

    var tab = e.target.closest('[data-sls-tab]');
    if (tab) { open = tab.dataset.slsTab; render(); return; }

    var pv = e.target.closest('[data-sls-pv]');
    if (pv) { pvDevice = pv.dataset.slsPv === 'laptop' ? 'laptop' : 'phone'; repaintTable(); return; }

    if (e.target.closest('[data-sls-save]')) { save(); return; }
    if (e.target.closest('[data-sls-reload]')) { load(); return; }
    if (e.target.closest('[data-sls-defaults]')) {
      if (!tabs) return;
      var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];
      current.fields.forEach(function (f) { values[f.key] = f['default']; });
      render();
      say(current.label + ' is back to its shipped values. Nothing is saved until you press Save.');
      return;
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
