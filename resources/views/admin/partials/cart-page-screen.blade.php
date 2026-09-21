{{--
    Appearance → Cart page. (Lane: cart-page)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, window.kbbAddNavEntry and
    toast(). Its own file rather than more lines inside a 20,000-line Blade:
    several lanes edit that file at once, and a screen that lives on its own can
    be reviewed, reverted and merged on its own. The cost is that it cannot
    reach app.blade.php's module-scoped constants -- NAV, TITLES and ADMIN_BASE
    are const, not window properties -- so it appends its own sidebar entry and
    wraps window.go, exactly as cache-screen.blade.php and routines-screen.blade.php
    already do.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    The owner's cart-page brief, made adjustable: "i just want to squeeze the
    rows ... so the elements inside the rows must adjust the size and fonts
    sizes auto as per the rows height when i control from the backend", "give
    control of bold/unbold for products rows and grid section both", "i need
    full control to choose the products for this grid section with search
    products functionality on backend", "also give option to control such all
    fields and data on the backend nicely, also to adjust the floating rows
    sizes and font sizes etc".

    Every control here writes App\Services\CartPage::SCHEMA, and the FIRST one
    is the layout switch, because none of the rest of them does anything until
    it is moved. That is deliberate and it is stated on the screen: the package
    applies to a live shop and changes nothing about the cart page until the
    owner chooses to change it.

    ── THE PRODUCT PICKER ───────────────────────────────────────────────────

    The rail's products are a stored list of ids. An id is not something anybody
    can check by reading it, so the screen is handed the PRODUCTS, resolved by
    the server, and searches the catalogue for more. Search is debounced at
    220ms and the endpoint is throttled as well -- the debounce is a promise the
    browser makes and the throttle is the one the server makes.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file -- inside a comment
    included -- with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    This file is NOT inside app.blade.php's raw region, so Blade interpolation
    works here and @json is safe. Inside that region it would ship as literal
    text and be a SyntaxError in the script block that builds half the console,
    which is a fault php -l cannot see and a file-reading test cannot see
    either. CartPageScreenTest renders this screen and parses it.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED cps- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks. app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name -- [data-open], [data-tg], [data-pp] -- and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.
--}}
@verbatim
<style>
.cps-wrap{display:grid;gap:14px;min-width:0}
.cps-wrap > *{min-width:0}
.cps-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.cps-title{font-weight:650;font-size:15px}
.cps-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.cps-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.cps-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.cps-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.cps-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.cps-f{display:grid;gap:5px;min-width:0}
.cps-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.cps-fh label{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.cps-val{font-size:11.5px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap}
.cps-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.cps-f input[type=range]{width:100%;accent-color:var(--accent,#15a85a);margin:0;min-width:0}
.cps-f input[type=text],.cps-f input[type=number],.cps-f select{
  width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.cps-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.cps-check input{margin-top:3px;flex:none;width:16px;height:16px}
.cps-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.cps-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.cps-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.cps-btn[disabled]{opacity:.45;cursor:default}
.cps-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.cps-search{width:100%;min-width:0;padding:9px 11px;font:inherit;font-size:13px;
            border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.cps-list{margin-top:10px;border:1px solid var(--border,#e6e6e6);border-radius:10px;
          max-height:300px;overflow-y:auto;min-width:0}
.cps-row{display:flex;align-items:center;gap:10px;padding:9px 11px;min-width:0;cursor:pointer;
         border-bottom:1px solid var(--border,#e6e6e6);background:transparent;border-left:0;
         border-right:0;border-top:0;width:100%;text-align:left;color:inherit;font:inherit}
.cps-row:last-child{border-bottom:0}
.cps-box{width:19px;height:19px;flex:none;border:1.5px solid var(--border,#e6e6e6);border-radius:5px;
         display:grid;place-items:center;font-size:12px;color:#fff}
.cps-row[aria-selected="true"] .cps-box{border-color:var(--accent,#15a85a);background:var(--accent,#15a85a)}
.cps-row .cps-nm{flex:1;min-width:0}
.cps-row .cps-nm b{display:block;font-size:13px;font-weight:600;overflow:hidden;
                   text-overflow:ellipsis;white-space:nowrap}
.cps-row .cps-nm span{display:block;font-size:11px;color:var(--ink-soft,#6b7280)}
.cps-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.cps-chosen{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;min-width:0}
.cps-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border,#e6e6e6);
          border-radius:999px;padding:4px 6px 4px 11px;font-size:11.5px;max-width:100%}
.cps-chip b{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:22ch}
.cps-chip button{border:0;background:transparent;color:var(--ink-soft,#6b7280);cursor:pointer;
                 font:inherit;font-size:14px;line-height:1;padding:0 4px}
@media (max-width:640px){ .cps-card{padding:13px} }
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'cartpage';

  /* ---------------------------------------------------------------- state */
  var tabs = null;       // GET /admin-api/cart-page -> tabs
  var values = {};       // key -> current value, edited in place
  var chosen = [];       // [{id,name,brand,image}] the rail's products, in order
  var maxRec = 24;
  var open = null;       // which tab is showing
  var results = [];      // last search
  var banner = null;
  var busy = false;
  var seq = 0;
  var timer = null;

  /* ------------------------------------------------------------- plumbing */
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
      err.status = r.status;
      err.body = payload;
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

  /* A 404 from these endpoints almost always means the package shipped without
     its clear_caches migration having run, so the compiled route table does not
     know these paths. Said plainly rather than drawing an empty screen. */
  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Cart page endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Cart page',
      icon: '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M9.5 9.5h7"/>',
      group: 'Appearance',
      after: ['cartpanel', 'dividers']
    });
  }

  /* ------------------------------------------------------------ the route */
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
    if (title) title.textContent = 'Cart page';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function load() {
    var mine = ++seq;
    busy = true;
    banner = null;
    render();

    try {
      var body = await api('/cart-page');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      chosen = body.chosen || [];
      maxRec = body.maxRec || 24;
      values = {};
      tabs.forEach(function (t) {
        t.fields.forEach(function (f) { values[f.key] = f.value; });
      });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Cart page settings could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function save() {
    if (busy) return;
    busy = true;
    render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });
    payload.rec_ids = chosen.map(function (p) { return p.id; }).join(',');

    try {
      await api('/cart-page', { settings: payload });
      say('Cart page saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false;
      render();
    }
  }

  async function search(term) {
    var mine = ++seq;
    try {
      var body = await api('/cart-page/products?q=' + encodeURIComponent(term));
      if (mine !== seq) return;
      results = body.products || [];
    } catch (e) {
      if (mine !== seq) return;
      results = [];
      banner = explain(e, 'The catalogue could not be searched.');
    }
    render();
  }

  /* ----------------------------------------------------------------- draw */

  /* rec_per is stored in TENTHS, because 4.5 is not an integer and every other
     range in the schema is. The service clamps and stores 45; this is the one
     place that knows to print 4.5. */
  function shown(f) {
    if (f.key === 'rec_per') return (values[f.key] / 10).toFixed(1);
    if (f.type === 'money') return (values[f.key] / 100).toFixed(2);
    var unit = (f.options && f.options.unit) || '';
    return values[f.key] + (unit === '/10' ? '' : unit);
  }

  /*
   * The service fee's two modes read two different stored values, and that is
   * what stops a pricing change being made by a units change: with one shared
   * box, flipping the mode turns AED 3.00 into 3% of the order in silence — a
   * fee that has multiplied by ten on a hundred-dirham basket, with nobody
   * having touched the number. So only the field the current mode uses is
   * drawn, and each keeps its own value and its own step.
   */
  function hidden(f) {
    if (f.key === 'sum_service' && String(values.sum_service_mode) === 'percent') return true;
    if (f.key === 'sum_service_pct' && String(values.sum_service_mode) !== 'percent') return true;
    return false;
  }

  function fieldHTML(f) {
    if (hidden(f)) return '';

    var id = 'cps-' + f.key;
    var help = f.help ? '<p class="cps-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="cps-f"><div class="cps-check">'
        + '<input type="checkbox" id="' + id + '" data-cps-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"'
          + (String(values[f.key]) === k ? ' selected' : '') + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="cps-f"><div class="cps-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + id + '" data-cps-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="cps-f"><div class="cps-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="cps-val" data-cps-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
        + '<input type="range" id="' + id + '" data-cps-key="' + esc(f.key) + '"'
        + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
        + help + '</div>';
    }

    if (f.type === 'money') {
      return '<div class="cps-f"><div class="cps-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<input type="number" min="0" step="0.01" id="' + id + '" data-cps-key="' + esc(f.key) + '"'
        + ' data-cps-money="1" value="' + esc((values[f.key] / 100).toFixed(2)) + '">' + help + '</div>';
    }

    return '<div class="cps-f"><div class="cps-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-cps-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '">' + help + '</div>';
  }

  function pickerHTML() {
    var ids = chosen.map(function (p) { return p.id; });

    var rows = results.length ? results.map(function (p) {
      var on = ids.indexOf(p.id) !== -1;
      return '<button type="button" class="cps-row" data-cps-pick="' + p.id + '"'
        + ' aria-selected="' + (on ? 'true' : 'false') + '">'
        + '<span class="cps-box">' + (on ? '&#10003;' : '') + '</span>'
        + '<span class="cps-nm"><b>' + esc(p.name) + '</b><span>' + esc(p.brand) + '</span></span>'
        + '</button>';
    }).join('') : '<p class="cps-empty">Nothing matches that.</p>';

    var chips = chosen.length ? chosen.map(function (p) {
      return '<span class="cps-chip"><b>' + esc(p.name) + '</b>'
        + '<button type="button" data-cps-drop="' + p.id + '" aria-label="Remove">&times;</button></span>';
    }).join('') : '<p class="cps-help">Nothing chosen. The rail hides itself on the shop until something is.</p>';

    return '<div class="cps-card">'
      + '<div class="cps-title">Which products fill the rail</div>'
      + '<p class="cps-sub">Type to search by product name, SKU, slug or brand. '
      + 'The order below is the order they appear in, and up to ' + maxRec + ' fit.</p>'
      + '<div class="cps-fields">'
      + '<input class="cps-search" type="search" id="cps-q" placeholder="Search products…" autocomplete="off">'
      + '<div class="cps-list">' + rows + '</div>'
      + '<div class="cps-chosen">' + chips + '</div>'
      + '</div></div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Cart page') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="cps-wrap"><div class="cps-card"><div class="cps-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="cps-wrap"><div class="cps-card">'
        + '<div class="cps-title">Cart page</div>'
        + '<p class="cps-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="cps-actions"><button class="cps-btn" data-cps-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="cps-tab" data-cps-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    var warn = String(values.layout) === 'classic'
      ? '<div class="cps-note">This shop is on the <b>classic</b> cart page, which is the page it '
        + 'has always rendered. Nothing else on this screen changes anything a shopper sees until '
        + 'the layout above is set to <b>Squeezed</b>. That is deliberate: applying the update that '
        + 'brought this screen changed the shop by nothing.</div>'
      : '';

    host.innerHTML = '<div class="cps-wrap">'
      + (banner ? '<div class="cps-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + warn
      + '<div class="cps-card">'
      + '<div class="cps-tabs">' + strip + '</div>'
      + '<p class="cps-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="cps-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '</div>'
      + (open === 'rec' ? pickerHTML() : '')
      + '<div class="cps-actions">'
      + '<button class="cps-btn is-primary" data-cps-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="cps-btn" data-cps-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      + '</div></div>';

    var q = document.querySelector('#cps-q');
    if (q) q.focus();
  }

  /* --------------------------------------------------------------- events */
  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-cps-key]');
    if (el) {
      var key = el.getAttribute('data-cps-key');
      if (el.type === 'checkbox') values[key] = el.checked;
      else if (el.hasAttribute('data-cps-money')) values[key] = Math.round(Number(el.value || 0) * 100);
      else if (el.type === 'range') values[key] = Number(el.value);
      else values[key] = el.value;

      // A select changes which OTHER fields belong on the screen — the two
      // service-fee amounts — so it redraws rather than only recording.
      if (el.tagName === 'SELECT') { render(); return; }

      var out = document.querySelector('[data-cps-val="' + key + '"]');
      if (out) {
        var f = null;
        tabs.forEach(function (t) {
          t.fields.forEach(function (x) { if (x.key === key) f = x; });
        });
        if (f) out.textContent = shown(f);
      }
      return;
    }

    if (e.target.id === 'cps-q') {
      // Debounced: the endpoint is throttled as well, because a debounce is a
      // promise the browser makes and the throttle is the one the server makes.
      clearTimeout(timer);
      var term = e.target.value;
      timer = setTimeout(function () { search(term); }, 220);
    }
  });

  document.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-cps-tab]');
    if (tab) {
      open = tab.getAttribute('data-cps-tab');
      if (open === 'rec' && results.length === 0) search('');
      render();
      return;
    }

    var pick = e.target.closest('[data-cps-pick]');
    if (pick) {
      var id = Number(pick.getAttribute('data-cps-pick'));
      var at = chosen.map(function (p) { return p.id; }).indexOf(id);
      if (at !== -1) {
        chosen.splice(at, 1);
      } else if (chosen.length < maxRec) {
        var found = results.filter(function (p) { return p.id === id; })[0];
        if (found) chosen.push(found);
      } else {
        say('That is as many as the rail holds.');
      }
      render();
      return;
    }

    var drop = e.target.closest('[data-cps-drop]');
    if (drop) {
      var dropId = Number(drop.getAttribute('data-cps-drop'));
      chosen = chosen.filter(function (p) { return p.id !== dropId; });
      render();
      return;
    }

    if (e.target.closest('[data-cps-save]')) { save(); return; }
    if (e.target.closest('[data-cps-reload]')) { load(); return; }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
