
(function () {
  'use strict';

  var SCREEN = 'cartpage';

  /* UNFINISHED CHANGES (Lane PM). Leaving this screen with edits in `values`
     used to throw them away without a word. They are kept in Unfinished in
     the top bar instead (partials/unfinished-drafts.blade.php), and come back
     into `values` when the screen is next opened. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Appearance → Cart page',
    values: function () { return tabs ? values : null; },
    set: function (k, v) { if (Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); },
    save: function () { save(); }
  });

  /* ---------------------------------------------------------------- state */
  var tabs = null;       // GET /admin-api/cart-page -> tabs
  var values = {};       // key -> current value, edited in place
  var chosen = [];       // [{id,name,brand,image}] the rail's products, in order
  var maxRec = 24;
  var fsBar = false;     // Appearance → Checkout page's free-delivery bar switch (Lane QK7)
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
      fsBar = body.fsBar === true;
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
      if (mine === seq) {
        busy = false; render();
        if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
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
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      say('Cart page saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false;
      render();
    }
  }

  /*
   * WHAT THE OWNER TYPED, kept outside render().
   *
   * search() finishes by calling render(), which rebuilds the whole screen --
   * including the search box. The box was drawn with no `value`, so 220ms after
   * every keystroke it emptied itself and the caret jumped back to an empty
   * field. Reported as "it's not letting me write anything", which is exactly
   * what it looked like: you could type, and then you could not.
   */
  var term = '';

  async function search(t) {
    term = t;
    var mine = ++seq;
    try {
      var body = await api('/cart-page/products?q=' + encodeURIComponent(t));
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

    /*
     * THE CHOSEN LIST IS THE POINT OF THIS PANEL, so it is drawn FIRST and as a
     * list, not as chips under the results.
     *
     * The owner: "currently just check boxes green showing, which i can't find
     * in 1000s of products, the selected ones." He was right -- a tick inside a
     * search result you can only see while that exact search is on screen is
     * not an answer to "what is in the rail". Type a new term and the evidence
     * is gone.
     *
     * Each row carries its POSITION, because the rail draws them in this order
     * and that was previously only findable by reading the help text. Up and
     * down move a product; the first and last have theirs disabled rather than
     * hidden, so the buttons do not reflow as you use them.
     */
    var picked = chosen.length ? '<ol class="cps-picked">' + chosen.map(function (p, i) {
      var first = i === 0, last = i === chosen.length - 1;
      return '<li class="cps-pk">'
        + '<span class="cps-pos">' + (i + 1) + '</span>'
        + '<span class="cps-nm"><b>' + esc(p.name) + '</b><span>' + esc(p.brand) + '</span></span>'
        + '<span class="cps-mv">'
        + '<button type="button" data-cps-up="' + p.id + '"' + (first ? ' disabled' : '')
        + ' aria-label="Move ' + esc(p.name) + ' earlier">&uarr;</button>'
        + '<button type="button" data-cps-down="' + p.id + '"' + (last ? ' disabled' : '')
        + ' aria-label="Move ' + esc(p.name) + ' later">&darr;</button>'
        + '<button type="button" data-cps-drop="' + p.id + '" aria-label="Remove ' + esc(p.name) + '">&times;</button>'
        + '</span></li>';
    }).join('') + '</ol>'
      : '<p class="cps-help">Nothing chosen yet. The rail hides itself on the shop until something is.</p>';

    return '<div class="cps-card">'
      + '<div class="cps-title">Which products fill the rail</div>'
      + '<p class="cps-sub">Search below to add. The list here is the rail, left to right — '
      + 'use the arrows to reorder. Up to ' + maxRec + ' fit.</p>'
      + '<div class="cps-picked-wrap">'
      + '<div class="cps-picked-h">In the rail <b>' + chosen.length + '</b>'
      + (chosen.length >= maxRec ? '<em>full</em>' : '') + '</div>'
      + picked
      + '</div>'
      + '<div class="cps-fields">'
      + '<input class="cps-search" type="search" id="cps-q" placeholder="Search products to add…" autocomplete="off" value="' + esc(term) + '">'
      + '<div class="cps-list">' + rows + '</div>'
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

    /* ▲ AND NOT ON THE TWO ROW-SIZE TABS. (Lane CR)
       This note was true of every tab on this screen and is now false of two of
       them: "Product rows . spacing and size" and "Product rows . phone" are
       ordinary declarations at `.kbb-cartpage .items .ci:not(.ci-set)`, which
       BOTH layouts match, so they are live on this shop today. Leaving the note
       up over them would tell the owner that the sliders he is dragging do
       nothing -- which is the complaint that brought this lane here, said by
       the console itself. */
    var liveOnClassic = open === 'rowsize' || open === 'rowphone';

    var warn = String(values.layout) === 'classic' && !liveOnClassic
      ? '<div class="cps-note">This shop is on the <b>classic</b> cart page, which is the page it '
        + 'has always rendered. Nothing else on this screen changes anything a shopper sees until '
        + 'the layout above is set to <b>Squeezed</b>. That is deliberate: applying the update that '
        + 'brought this screen changed the shop by nothing.</div>'
      : (liveOnClassic
        ? '<div class="cps-note">These controls are <b>live on the page this shop serves right now</b>, '
          + 'whichever layout is chosen above \u2014 they are the row\u2019s real padding, picture and type '
          + 'sizes rather than the squeezed layout\u2019s. They reach an ordinary product\u2019s row and '
          + 'never a set\u2019s: a set has its own under <b>Appearance \u2192 Set</b>.</div>'
        : '');

    host.innerHTML = '<div class="cps-wrap' + (open === 'desktop' ? ' cps-stack' : '') + '"><div class="cps-col">'
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
      + '</div></div>'
      + previewHTML()
      + '</div>';

    var q = document.querySelector('#cps-q');
    if (q) {
      q.focus();
      // focus() alone lands the caret at position 0 on a freshly created input,
      // so the next character types itself in front of the word.
      try { q.setSelectionRange(q.value.length, q.value.length); } catch (e) {}
    }
  }

  /* -------------------------------------------------------------- preview */
  /*
   * A LIVE DRAWING OF THE TAB YOU ARE ON.
   *
   * Only the region the open tab controls, not the whole cart. A phone frame
   * showing the top of the basket while you drag the docked-bar height is a
   * preview of nothing, and scrolling one to the right place is a second thing
   * that can be wrong.
   *
   * Every size is a calc() off the same custom properties the shop itself
   * reads -- --h for row height, --per for cards across, --ah/--ch for the two
   * bars -- so the drawing cannot disagree with the page by arithmetic. It is
   * still a DRAWING and not the real cart: rendering the storefront in here
   * would mean an authenticated fetch per keystroke.
   */
  var PV_ROWS = [
    {b:'COSRX', n:'Rice Probiotics Toner', q:1, p:'AED 115', c:'#f6a98a,#ef8a72'},
    {b:'SKIN1004', n:'Ceramide Daily Moisturiser', q:2, p:'AED 328', c:'#bfa6f2,#a387e8'}
  ];
  var PV_CARDS = ['#f5b8c8,#e99bb0', '#9fd8c4,#77c2a9', '#f2c08a,#e5a566', '#8fc9ee,#68afe0',
                  '#a8d5b8,#81bf96', '#e7a7b8,#d78598', '#f0ce8e,#e0b564'];

  function pvNum(key, fallback) {
    var v = Number(values[key]);
    return isFinite(v) ? v : fallback;
  }
  function pvOn(key) { return values[key] === true || values[key] === 1 || values[key] === '1'; }
  function pvText(key, fallback) {
    var v = values[key];
    return (v === undefined || v === null || v === '') ? fallback : String(v);
  }

  /* A desktop measurement as a percentage of the page's own maximum width, so
     the preview holds the shop's proportions whatever size the mock is drawn
     at. Clamped: `d_max` cannot be zero from the schema, but a settings row
     hand-edited to 0 would divide by it, and a preview that throws is a blank
     screen where the controls used to be. */
  function pvPct(key, fallback) {
    var max = pvNum('d_max', 1200) || 1200;
    return ((pvNum(key, fallback) / max) * 100).toFixed(2) + '%';
  }

  /* The custom properties, built once and handed to whichever region draws. */
  function pvVars() {
    var per = pvNum('rec_per', 45) / 10;
    return 'style="'
      + '--h:' + pvNum('row_h', 96) + 'px;'
      + '--f:' + (pvNum('row_font', 100) / 100) + ';'
      + '--w:' + (pvOn('row_bold') ? 600 : 400) + ';'
      + (open === 'desktop' ? '' : '--qs:' + (pvNum('qty_size', 100) / 100) + ';')
      + '--per:' + (per > 0 ? per : 4.5) + ';'
      + '--rw:' + (pvOn('rec_bold') ? 600 : 400) + ';'
      + '--rpw:' + (pvOn('rec_price_bold') ? 600 : 400) + ';'
      + '--rlh:' + (pvNum('rec_lh', 125) / 100) + ';'
      + '--rg:' + pvNum('rec_gap', 2) + 'px;'
      + '--rig:' + pvNum('rec_img_gap', 5) + 'px;'
      + '--ras:' + (pvNum('rec_add_size', 100) / 100) + ';'
      + '--rax:' + pvNum('rec_add_x', 0) + 'px;'
      + '--ray:' + pvNum('rec_add_y', 0) + 'px;'
      + '--mo:' + (pvNum('rec_motion', 1) || 0.0001) + ';'
      + '--ah:' + pvNum('addr_h', 40) + 'px;'
      + '--ch:' + pvNum('co_h', 62) + 'px;'
      + '--bf:' + (pvNum('bar_font', 100) / 100) + ';'
      + '--bp:' + pvNum('bar_pad', 0) + 'px;'
      + '--af:' + (pvNum('addr_font', 100) / 100) + ';'
      /* The two weights are STORED AS THE CSS VALUE — '500', '700' — so
         pvNum reads them straight, and an unsaved one falls back to the
         weight the shop paints by hand today. */
      + '--aw:' + pvNum('addr_bold', 500) + ';'
      + '--abf:' + (pvNum('addr_btn_font', 100) / 100) + ';'
      + '--abw:' + pvNum('addr_btn_bold', 600) + ';'
      + '--ts:' + (pvNum('trust_size', 100) / 100) + ';'
      + '--sd:' + (pvNum('sheet_dense', 100) / 100) + ';'
      + '--sf:' + (pvNum('sheet_font', 100) / 100) + ';'
      + '--bl:' + pvNum('sheet_blur', 3) + ';'
      /* Desktop. Scaled to the preview frame rather than printed raw: the
         mock is about a third of a real window, so a 380px column drawn at
         380px would fill it entirely and the ratio between the two tracks —
         which is the thing being adjusted — would be invisible. The DIVISOR
         is the same for both tracks, so the proportion the owner sees is the
         proportion the page renders. */
      + '--cpv-d-per:' + ((pvNum('d_rec_per', 55) / 10) || 5.5) + ';'
      /* AS PERCENTAGES OF THE PAGE WIDTH, not as scaled pixels. A fixed
         divisor only looks right at one frame size, and this preview is now
         336px wide on most tabs and full-screen on the Desktop one. A ratio is
         a ratio at any width: 380 of 1200 is 31.7% of the page, and that is
         what the shop draws, so that is what the mock draws. */
      + '--cpv-d-arrow:' + pvPct('d_arrow_size', 34) + ';'
      + '--cpv-d-dockpad:' + pvPct('d_dock_pad', 14) + ';'
      /* The stepper's two multipliers, stacked the way the page stacks them. */
      + '--qs:' + ((pvNum('qty_size', 100) / 100) * (pvNum('d_qty_size', 100) / 100)) + ';'
      + '--cpv-d-secgap:' + pvPct('d_sec_gap', 16) + ';'
      + '--cpv-d-secpad:' + pvPct('d_sec_pad', 16) + ';'
      + '--cpv-d-padx:' + pvPct('d_pad_x', 24) + ';'
      + '--cpv-d-pady:' + pvPct('d_pad_y', 24) + ';'
      + '--cpv-d-aside:' + pvPct('d_aside', 380) + ';'
      + '--cpv-d-gap:' + pvPct('d_gap', 28) + ';'
      + '--cpv-d-modal:' + pvPct('d_modal_w', 460) + ';'
      + '--cpv-d-blur:' + pvNum('d_modal_blur', 4) + 'px;'
      + '"';
  }

  /* The desktop tab's own region: the two columns, at the ratio the sliders
     set, with the docked rows sitting IN the right column rather than pinned
     to the foot of the screen -- which is the whole difference between the
     two layouts and the thing the owner is adjusting.

     It reuses pvRows/pvRail/pvSummary/pvBars rather than drawing its own
     furniture. A desktop preview with its own idea of what a product row
     looks like is a second place for the mock to drift from the shop. */
  function pvDesktop() {
    if (!pvOn('d_on')) {
      return '<p class="cpv-note">The two-column desktop layout is switched off, so a desktop '
        + 'gets the phone layout stretched across the screen.</p>';
    }

    var side = '<div class="cpv-side">' + pvSummary() + pvBars() + '</div>';

    return '<div class="cpv-cols" style="--per:var(--cpv-d-per,5.5)">'
      + '<div>' + pvRows()
          + (pvOn('d_arrows')
              ? '<div class="cpv-arrwrap">' + pvRail()
                + '<span class="cpv-arr l">\u2039</span><span class="cpv-arr r">\u203a</span></div>'
              : pvRail())
          + '</div>'
      + '<div>' + (pvOn('d_sticky') ? '<div class="cpv-stick">' + side + '</div>' : side) + '</div>'
      + '</div>'
      + '<div class="cpv-modal">' + pvSheet('form') + '</div>';
  }

  function pvRows() {
    return PV_ROWS.map(function (r) {
      return '<div class="cpv-ci">'
        + '<div class="cpv-th" style="background:linear-gradient(140deg,' + r.c + ')">' + esc(r.b.charAt(0)) + '</div>'
        + '<div class="cpv-mid"><div class="cpv-br">' + esc(r.b) + '</div>'
        + '<div class="cpv-nm">' + esc(r.n) + '</div>'
        + '<div class="cpv-qty"><i>−</i><b>' + r.q + '</b><i>+</i></div></div>'
        + '<div class="cpv-pr">' + esc(r.p) + '</div></div>';
    }).join('');
  }

  function pvRail() {
    if (!pvOn('rec_on')) {
      return '<p class="cpv-note">The rail is switched off, so the shop draws nothing here.</p>';
    }
    var names = chosen.length
      ? chosen.map(function (p) { return p.name; })
      : ['PDRN Pink Peptide Serum', 'CER-100 Collagen Treatment', 'Collagen Night Mask',
         'PDRN Hyaluronic Mist', 'Gel Cleanser 150ml', 'Fino Shampoo Set', 'Relief Sun SPF50'];
    return '<section class="cpv-rec"><h6>' + esc(pvText('rec_heading', 'Recommended for you')) + '</h6>'
      + '<div class="cpv-rail">'
      + names.slice(0, 7).map(function (n, i) {
          return '<div class="cpv-rc"><div class="im" style="background:linear-gradient(140deg,'
            + PV_CARDS[i % PV_CARDS.length] + ')"><span class="pl">+</span></div>'
            + '<div class="t">' + esc(n) + '</div><div class="p">AED 88</div></div>';
        }).join('')
      + '</div></section>'
      + (chosen.length ? '' : '<p class="cpv-note">Example products — pick real ones below.</p>');
  }

  function pvSummary() {
    var money = function (f) { return 'AED ' + (Number(values[f] || 0) / 100).toFixed(2); };
    var out = '<div class="cpv-sum">'
      + '<div class="cpv-sr"><span>' + esc(pvText('sum_value_label', 'Order Value')) + '</span>'
      + '<span><s>AED 492</s><b>AED 443</b></span></div>';

    if (pvOn('sum_express_on')) {
      out += '<div class="cpv-sr"><span>' + esc(pvText('sum_express_label', 'Express Delivery Charge'))
        + ' ⓘ</span><span>' + money('sum_express') + '</span></div>';
    }
    if (pvOn('sum_delivery_on')) {
      out += '<div class="cpv-sr"><span>' + esc(pvText('sum_std_label', 'Standard Delivery Charge'))
        + ' ⓘ</span><span>' + esc(pvText('sum_std_free', 'Free')) + '</span></div>';
    } else if (!fsBar) {
      /* Lane QK7: the bar is switched off for the cart page and the checkout,
         so the shop draws nothing here -- and neither does the preview. */
      out += '<div class="cpv-ship"><div class="t">Free-delivery bar: off — '
        + 'Appearance → Checkout page → Delivery labels.</div></div>';
    } else {
      /* The free-delivery bar stands in for the delivery lines -- it answers
         the same question AND says what would make delivery free.
         ▲ MARKED AS AN EXAMPLE, deliberately. The threshold is not a cart-page
         setting: it comes from the shipping zone, and "no threshold" is a real
         answer, in which case the shop prints sum_fallback instead. Drawing a
         confident bar here would advertise free delivery in the admin that the
         shop may not offer. */
      out += '<div class="cpv-ship"><div class="t won">🎉 <b>You have unlocked free delivery!</b></div>'
        + '<div class="bar"><div class="fill" style="width:100%"></div></div></div>'
        + '<p class="cpv-note" style="padding:4px 0 0;text-align:left">Example — the threshold comes '
        + 'from your delivery zone. With none set, the shop prints “'
        + esc(pvText('sum_fallback', 'Delivery is calculated at checkout')) + '” here instead.</p>';
    }

    var fee = 0;
    if (pvOn('sum_service_on')) {
      fee = String(values.sum_service_mode) === 'pct'
        ? 443 * pvNum('sum_service_pct', 2) / 100
        : pvNum('sum_service', 300) / 100;
      out += '<div class="cpv-sr"><span>' + esc(pvText('sum_service_label', 'Service Fee'))
        + ' ⓘ</span><span>AED ' + fee.toFixed(2) + '</span></div>';
    }

    out += '<div class="cpv-tot"><span>' + esc(pvText('sum_total_label', 'Order Total')) + '</span>'
      + '<span>AED ' + (443 + fee).toFixed(2) + '</span></div></div>';

    if (pvOn('trust_on')) {
      /* NOT esc()'d, and that is not an oversight: these are drawings, and the
         values are a hardcoded PHP constant handed over above -- no form value,
         no setting and nothing a user typed reaches them. The six `pay_*`
         booleans still decide which ones appear. */
      var art = window.CPV_PAY_ART || {};
      var marks = Object.keys(art)
        .filter(function (k) { return pvOn(k); })
        .map(function (k) { return '<span class="cpv-pay">' + art[k] + '</span>'; }).join('');
      out += '<div class="cpv-trust"><span class="tick">✓</span> '
        + esc(pvText('trust_text', 'Secure checkout')) + ' <span>|</span> ' + marks + '</div>';
    }
    return out;
  }

  /* BOTH STATES OF THE ADDRESS ROW, one above the other.
     The row looks different before and after a shopper picks an address, and
     the difference is a setting on this very tab: the prompt carries no fade,
     the chosen address does. Drawing only the prompt — which is what this did —
     left the fade invisible in the admin and discoverable only on a phone,
     which is where it was reported from. `+ Address` and `Change address` are
     each on their own row too, so the button's size slider is judged against
     the longer of the two words. */
  function pvBars() {
    var rows = '';

    if (pvOn('addr_on')) {
      rows += '<div class="cpv-ab"><span class="who"><b>'
        + esc(pvText('addr_heading', 'Please choose your delivery address'))
        + '</b></span><span class="bt">' + esc(pvText('addr_btn_add', '+ Address')) + '</span></div>'
        + '<div class="cpv-ab has"><span class="who"><b>'
        + esc(pvText('addr_chosen', 'Delivering to {tag}').replace('{tag}', pvText('sheet_home', 'Home')))
        + '</b><i>Building 1-10, G-04 apartment, Al jhail gate phase 2 - Al Quoz - Dubai</i>'
        + '</span><span class="bt">' + esc(pvText('addr_btn_change', 'Change address')) + '</span></div>';
    }

    /* AED and the amount in one inline run, wrapped the way Money::format()
       wraps them, so the preview would show the two-line total if it ever came
       back. */
    return '<div class="cpv-dock">' + rows
      + '<div class="cpv-cb"><div class="ta"><i>3 items</i>'
      + '<b><span><span>AED</span> 1,443</span></b></div>'
      + '<button type="button">' + esc(pvText('co_label', 'Proceed to Checkout')) + '</button></div></div>'
      + (pvOn('addr_on')
          ? '<p class="cpv-note">The address row before and after a delivery address is chosen. '
            + 'The fade off the right belongs to a real address — the prompt is never faded.</p>'
          : '');
  }

  /* The popup, drawn at whichever of its two caps applies. `which` is 'list'
     or 'form' -- those caps are otherwise invisible until you open the sheet on
     a real phone, which is the worst place to find out you set them wrong. */
  function pvSheet(which) {
    var cap = which === 'list' ? pvNum('sheet_max_list', 38) : pvNum('sheet_max', 50);
    var inner;
    if (which === 'list') {
      inner = '<h6>' + esc(pvText('sheet_list_title', 'Choose location')) + '</h6>'
        + '<div class="cpv-al on"><span class="ad"><b>Zulfiqar Sha</b>'
        + '<i>Building 1-10, G-04 apartment, Al jhail gate phase 2 - Al Quoz - Dubai</i></span>'
        + '<span class="cpv-tag">' + esc(pvText('sheet_home', 'Home')) + '</span></div>'
        + '<div class="cpv-al"><span class="ad"><b>Zulfiqar Sha</b>'
        + '<i>Office 402, Boutique Tower 2 - Business Bay - Dubai</i></span>'
        + '<span class="cpv-tag">' + esc(pvText('sheet_office', 'Office')) + '</span></div>'
        /* THREE, because three is what the list can hold. A shopper who is not
           signed in keeps three addresses in their session, and a preview that
           draws two is a preview whose height caps were set against a list
           shorter than the real one — which is exactly the thing this preview
           exists to stop happening on somebody's phone. */
        + '<div class="cpv-al"><span class="ad"><b>Zulfiqar Sha</b>'
        + '<i>Villa 6, Street 14 - Al Barsha South - Dubai</i></span>'
        + '<span class="cpv-tag">' + esc(pvText('sheet_home', 'Home')) + '</span></div>'
        /* And the line that goes with a full list. Same condition as the live
           sheet: shown when a signed-out shopper already has three, because
           the next one replaces the oldest. */
        + '<p class="cpv-note">' + esc(pvText('sheet_guest_note',
            'We keep your 3 most recent addresses on this device. Adding another replaces the oldest.')) + '</p>'
        + '<button class="cpv-add" type="button">' + esc(pvText('sheet_add_new', '+ Add New Address')) + '</button>';
    } else {
      var two = pvOn('sheet_two_up') ? ' two' : '';
      inner = '<h6>' + esc(pvText('sheet_form_title', 'Add New Address')) + '</h6>'
        + '<div class="cpv-fg' + two + '">'
        + '<div class="full"><label>' + esc(pvText('sheet_area', 'Area')) + '</label>'
        + '<div class="cpv-fi">' + esc(pvText('sheet_area_hint', 'e.g. Jumeirah Village Circle')) + '</div></div>'
        + '<div class="full"><label>' + esc(pvText('sheet_apt', 'Apartment / building')) + '</label>'
        + '<div class="cpv-fi">' + esc(pvText('sheet_apt_hint', 'e.g. Flat 802, Sunrise Residence')) + '</div></div>'
        + '<div><label>' + esc(pvText('sheet_city', 'City')) + '</label>'
        + '<div class="cpv-fi">' + esc(pvText('sheet_city_hint', 'e.g. Sharjah')) + '</div></div>'
        + '<div><label>' + esc(pvText('sheet_country', 'Country')) + '</label>'
        + '<div class="cpv-fi" style="color:#17181c">United Arab Emirates</div></div>'
        + '<p class="cpv-geo full">✓ ' + esc(pvText('sheet_geo_note', 'Country set from where you are.')) + '</p>'
        + '</div>'
        + '<div class="cpv-act"><span class="cpv-mk on">⌂ ' + esc(pvText('sheet_home', 'Home')) + '</span>'
        + '<span class="cpv-mk">▤ ' + esc(pvText('sheet_office', 'Office')) + '</span>'
        + '<span class="cpv-dl">✓ ' + esc(pvText('sheet_save', 'Deliver here')) + '</span></div>';
    }
    return '<div class="cpv-stage"><div class="cpv-scrim"></div>'
      + '<div class="cpv-sheet" style="--cap:' + cap + '%">' + inner + '</div></div>';
  }

  /* ── THE ORDINARY-ROW CONTROLS, AS THE PROPERTIES THE MOCK READS ──────
     `m` picks the phone's twin of every key, and it is the ONLY place the
     `_m` suffix is added — the same discipline CartPage::rowBlock() keeps on
     the server, so the two cannot drift into drawing different rows. The
     fallbacks are the shipped values, so a control the owner has not touched
     draws what the shop draws. (Lane CR) */
  function pvRowVars(m) {
    function n(k, d) { return pvNum(m ? k + '_m' : k, d); }
    return 'style="'
      + '--cim:' + n('ci_min_h', 0) + 'px;'
      + '--cipt:' + n('ci_pad_t', m ? 10 : 11) + 'px;'
      + '--cipb:' + n('ci_pad_b', m ? 10 : 11) + 'px;'
      + '--cips:' + n('ci_pad_s', m ? 12 : 14) + 'px;'
      + '--cipe:' + n('ci_pad_e', m ? 12 : 14) + 'px;'
      + '--cig:' + n('ci_gap', m ? 11 : 12) + 'px;'
      + '--cith:' + n('ci_thumb', m ? 52 : 56) + 'px;'
      + '--cithr:' + n('ci_thumb_r', 10) + 'px;'
      + '--cibf:' + n('ci_brand_f', 10) + 'px;'
      /* TENTHS OF A PIXEL, divided here exactly as CartPage::px10() does it —
         13.5px cannot be a slider value, and a mock that printed 130px would
         be a mock nobody could read. */
      + '--cinf:' + (n('ci_name_f', m ? 125 : 130) / 10) + 'px;'
      + '--cing:' + n('ci_name_gap', m ? 5 : 6) + 'px;'
      + '--ciqt:' + n('ci_qty_top', 0) + 'px;'
      + '--ciqb:' + n('ci_qty_bot', 0) + 'px"';
  }

  /* Three ordinary rows and one SET row. The set row is drawn from the SAME
     mock but greyed and labelled, because the boundary is the thing the owner
     kept running into: these controls stop at `:not(.ci-set)`, and a picture
     of where they stop is worth more than a sentence saying so. */
  function pvClassic(m) {
    var rows = PV_ROWS.slice(0, 3).map(function (r) {
      return '<div class="r">'
        + '<div class="th" style="background:linear-gradient(140deg,' + r.c + ')">' + esc(r.b.charAt(0)) + '</div>'
        + '<div class="mid"><div class="br">' + esc(r.b) + '</div>'
        + '<div class="nm">' + esc(r.n) + '</div>'
        + '<div class="q"><i>\u2212</i><b>' + r.q + '</b><i>+</i></div></div>'
        + '<div class="pr">' + esc(r.p) + '</div></div>';
    }).join('');

    rows += '<div class="r set">'
      + '<div class="th" style="background:linear-gradient(140deg,#e7d7ff,#b79cf0)">S</div>'
      + '<div class="mid"><div class="br">KBB</div>'
      + '<div class="nm">Glass Skin Discovery Set</div>'
      + '<div class="fan"><s></s><s></s><s></s><em>What\u2019s inside</em></div>'
      + '<div class="q"><i>\u2212</i><b>1</b><i>+</i></div>'
      + '<div class="cpv-lock">A set\u2019s row \u2014 Appearance \u2192 Set</div></div>'
      + '<div class="pr">AED 120</div></div>';

    return '<div class="cpv-cl" ' + pvRowVars(m) + '>' + rows + '</div>';
  }

  /** What the open tab is responsible for, and nothing else. */
  function previewHTML() {
    var region, label;
    if (open === 'rows')        { region = pvRows(); label = 'Product rows · the squeezed layout'; }
    else if (open === 'rowsize')  { region = pvClassic(false); label = 'Product rows · laptop values'; }
    else if (open === 'rowphone') { region = pvClassic(true); label = 'Product rows · phone values'; }
    else if (open === 'rec')    { region = pvRail(); label = 'Recommended'; }
    else if (open === 'summary'){ region = pvSummary(); label = 'Summary & trust'; }
    else if (open === 'bars')   { region = pvBars(); label = 'Docked rows'; }
    else if (open === 'popup')  { region = pvSheet('list') + pvSheet('form'); label = 'Address popup · list, then form'; }
    else if (open === 'desktop'){ region = pvDesktop(); label = 'Desktop · ' + pvNum('d_min', 1024) + 'px and up'; }
    else                        { region = pvRows() + pvRail() + pvSummary() + pvBars(); label = 'The whole page'; }

    /* The note below says "this is what Squeezed would draw". It is TRUE of
       every other tab and FALSE of these two: their rules are declarations at
       `.kbb-cartpage .items .ci:not(.ci-set)`, which both layouts match, so
       they are live on this shop today. Printing it there would tell the owner
       his sliders do nothing. (Lane CR) */
    var classic = String(values.layout) === 'classic'
      && open !== 'rowsize' && open !== 'rowphone';

    return '<div class="cpv" id="cps-preview">'
      + '<div class="cpv-h"><b>' + (open === 'desktop' ? 'Preview' : 'Live preview') + '</b><span>' + esc(label) + '</span></div>'
      /* A DESKTOP-SHAPED FRAME for the desktop tab. Drawing a two-column
         layout inside a phone outline would be a preview that contradicts
         itself, and this screen has already been through one round of
         previews that did not match the page they claimed to show. */
      + '<div class="' + (open === 'desktop' ? 'cpv-desk' : 'cpv-phone') + '" ' + pvVars() + '>'
      + '<div class="cpv-bar"><i></i>extrabeauty.ae/cart/</div>'
      + '<div class="cpv-body"' + (open === 'popup' ? ' style="padding:0"' : '') + '>' + region + '</div>'
      + '</div>'
      + (classic
          ? '<p class="cpv-note" style="text-align:left;padding:8px 0 0">This is what <b>Squeezed</b> '
            + 'would draw. The shop is still on <b>Classic</b>, so nothing here is live yet.</p>'
          : '')
      + '</div>';
  }

  /* Redraw the preview WITHOUT touching the controls: re-rendering the whole
     screen mid-drag destroys the range input under the finger and the drag
     stops dead. */
  function paintPreview() {
    var node = document.querySelector('#cps-preview');
    if (!node) return;
    var holder = document.createElement('div');
    holder.innerHTML = previewHTML();
    node.replaceWith(holder.firstChild);
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
      paintPreview();
      return;
    }

    if (e.target.id === 'cps-q') {
      // Debounced: the endpoint is throttled as well, because a debounce is a
      // promise the browser makes and the throttle is the one the server makes.
      clearTimeout(timer);
      var typed = e.target.value;
      term = typed;          // so a render() before the timer fires keeps it
      timer = setTimeout(function () { search(typed); }, 220);
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

    /* Reorder. The rail renders `chosen` in order, so moving a row here is the
       whole of it -- there is no separate sort field to keep in step. */
    var up = e.target.closest('[data-cps-up]');
    var down = e.target.closest('[data-cps-down]');
    if (up || down) {
      var moveId = Number((up || down).getAttribute(up ? 'data-cps-up' : 'data-cps-down'));
      var from = chosen.map(function (p) { return p.id; }).indexOf(moveId);
      var to = from + (up ? -1 : 1);
      if (from !== -1 && to >= 0 && to < chosen.length) {
        var moved = chosen.splice(from, 1)[0];
        chosen.splice(to, 0, moved);
        render();
      }
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
