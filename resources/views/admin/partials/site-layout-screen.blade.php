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
--}}
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
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'sitelayout';

  var tabs = null, values = {}, open = null, banner = null, busy = false, seq = 0;
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
    return columns(railRow(w), num('tile', 260), num('gap', 16));
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
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function save() {
    if (busy) return;
    busy = true; render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });

    try {
      var body = await api('/site-layout', { settings: payload });
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

    return '<div class="sls-f"><div class="sls-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-sls-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '" autocomplete="off">' + help + '</div>';
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
      + tableHTML()
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
    if (!host || !tabs) return;
    var cards = host.querySelectorAll('.sls-wrap > .sls-card');
    var last = cards[cards.length - 1];
    if (!last) return;
    var fresh = document.createElement('div');
    fresh.innerHTML = tableHTML();
    if (fresh.firstElementChild) last.replaceWith(fresh.firstElementChild);
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;

    var tab = e.target.closest('[data-sls-tab]');
    if (tab) { open = tab.dataset.slsTab; render(); return; }

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
