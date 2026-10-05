
(function () {
  'use strict';

  var SCREEN = 'cartpanel';

  /* UNFINISHED CHANGES (Lane PM). Leaving this screen with edits in `values`
     used to throw them away without a word. They are kept in Unfinished in
     the top bar instead (partials/unfinished-drafts.blade.php), and come back
     into `values` when the screen is next opened. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Appearance → Cart panel',
    values: function () { return tabs ? values : null; },
    set: function (k, v) { if (Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); },
    save: function () { save(); }
  });

  /* ---------------------------------------------------------------- state */
  var tabs = null;        // GET /admin-api/cart-panel -> tabs
  var values = {};        // key -> current value, edited in place
  var touch = [];         // the keys that are tap targets
  var touchMin = 44;
  var phoneMax = 680;     // where the phone's spacing takes effect
  var tapMax = 900;       // where the phone's tap targets take effect
  var open = null;        // which tab is showing
  var banner = null;
  var busy = false;
  var seq = 0;

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

  /* A 404 here almost always means the compiled route table does not know the
     path yet, which is a cleared cache away rather than a broken screen. */
  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Cart panel endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  /* The console carries a built-in `cartpanel` row, so this call finds it and
     returns it — kbbAddNavEntry is keyed on the screen id and a second call
     adds nothing. It is here so that the row survives the integrator deleting
     the dead copy this file replaced, NAV entry and all. */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Cart panel',
      icon: '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/>',
      group: 'Appearance',
      after: ['dividers', 'mobilehdr', 'prodstyles']
    });
  }

  /* ------------------------------------------------------------ the route */
  /* WRAPPED, NOT REPLACED. Nine partials do this, and one that forgot to call
     the handler it replaced would black out every screen registered before it —
     which is every screen app.blade.php draws itself. */
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
    if (title) title.textContent = 'Cart panel';

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
      var body = await api('/cart-panel');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      touch = body.touch || [];
      touchMin = body.touchMin || 44;
      phoneMax = body.phoneMax || 680;
      tapMax = body.tapMax || 900;
      values = {};
      tabs.forEach(function (t) {
        t.fields.forEach(function (f) { values[f.key] = f.value; });
      });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Cart panel settings could not be read.');
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

    try {
      await api('/cart-panel', { settings: payload });
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      say('Cart panel saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false;
      render();
    }
  }

  /* ------------------------------------------------------------- controls */
  function shown(f) {
    var o = f.options || {};
    return String(values[f.key]) + (o.unit || '');
  }

  /** The schema row for a key, across every tab. */
  function fieldFor(key) {
    var out = null;

    (tabs || []).forEach(function (t) {
      t.fields.forEach(function (f) { if (f.key === key) out = f; });
    });

    return out;
  }

  /* `name_lines` cuts a name off, so its help line is only true while a name is
     drawn at all — and nothing else on either device tab depends on a switch.
     Kept as a function rather than inlined so a later dependency has one place
     to go, the way the checkout screen's does. */
  function hidden(f) {
    return false;
  }

  /*
   * THE TAP-TARGET WARNING, AND WHY IT IS NOT A CLAMP.
   *
   * Four Mobile values are touch targets rather than spacing. The owner asked to
   * squeeze exactly those, so the slider reaches below 44 and this is what
   * happens there instead: one warm line, under the slider, at the moment the
   * number goes below it.
   *
   * Not a blocking dialog, not a refusal, and above all NOT a silent clamp — a
   * slider that stops where the owner did not ask it to stop reads as a bug and
   * he reports it as one.
   */
  function warnHTML(f) {
    if (touch.indexOf(f.key) === -1) return '';
    if (Number(values[f.key]) >= touchMin) return '';

    return '<p class="cpp-warn">Below ' + touchMin + 'px a finger misses this more often. '
      + touchMin + 'px is the smallest box that is reliable on a phone — this is '
      + esc(String(values[f.key])) + 'px. Saved as it is; nothing here stops you.</p>';
  }

  function fieldHTML(f) {
    if (hidden(f)) return '';

    var id = 'cpp-' + f.key;
    var help = f.help ? '<p class="cpp-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="cpp-f"><div class="cpp-check">'
        + '<input type="checkbox" id="' + id + '" data-cpp-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    /*
     * A SELECT IS A SELECT AND NOT A SLIDER. Three selects shipped on the
     * checkout screen rendering as `<input type="range" min="undefined">` showing
     * a value a range cannot represent, and saving NaN, because the branch read
     * the DOM element's type instead of the field's. This branches on the
     * field's own type and so does the input handler.
     */
    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '') + '>'
          + esc(f.options[k]) + '</option>';
      }).join('');

      return '<div class="cpp-f"><div class="cpp-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select class="cpp-sel" id="' + id + '" data-cpp-key="' + esc(f.key) + '">' + opts + '</select>'
        + help + '</div>';
    }

    if (f.type === 'text') {
      return '<div class="cpp-f"><div class="cpp-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<input class="cpp-text" type="text" id="' + id + '" data-cpp-key="' + esc(f.key) + '"'
        + ' value="' + esc(values[f.key] == null ? '' : values[f.key]) + '"'
        + ' placeholder="' + esc(f['default'] == null ? '' : f['default']) + '">'
        + help + '</div>';
    }

    /* A COLOUR IS A COLOUR PICKER, and the hex beside it is printed as TEXT
       rather than interpolated into a style attribute. The value is repaired to
       a `#rrggbb` on the way in by CartPanel's `hex => repair` policy, but this
       screen never has to know that to be safe. */
    if (f.type === 'colour') {
      return '<div class="cpp-f"><div class="cpp-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<span class="cpp-swatch">'
        + '<input type="color" id="' + id + '" data-cpp-key="' + esc(f.key) + '"'
        + ' value="' + esc(values[f.key]) + '">'
        + '<code data-cpp-val="' + esc(f.key) + '">' + esc(values[f.key]) + '</code>'
        + '</span>' + help + '</div>';
    }

    var o = f.options || {};
    return '<div class="cpp-f"><div class="cpp-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
      + '<span class="cpp-val" data-cpp-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
      + '<input type="range" id="' + id + '" data-cpp-key="' + esc(f.key) + '"'
      + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
      + help
      + '<span data-cpp-warn="' + esc(f.key) + '">' + warnHTML(f) + '</span>'
      + '</div>';
  }

  /* ---------------------------------------------------------------- notes */
  function noteHTML() {
    if (open === 'desktop') {
      return '<div class="cpp-note">Wider than <b>' + phoneMax + 'px</b>. Every value here has a twin on '
        + 'the <b>Mobile</b> tab, so squeezing the phone no longer squeezes this — which it used to, '
        + 'because row padding, name size, quantity buttons and list padding were one number shared by '
        + 'both.</div>';
    }

    if (open === 'mobile') {
      return '<div class="cpp-note">Width, padding, rows and type take effect at <b>' + phoneMax
        + 'px</b> and below. The four tap targets — the line ✕, the close button, the tab strip '
        + 'and the footer buttons — take effect at <b>' + tapMax + 'px</b> and below, which is where '
        + 'the panel raises them today. Both widths are the shop’s own; nothing here moved them.</div>';
    }

    return '<div class="cpp-note">The same on a laptop and on a phone. Nothing on this tab is '
      + 'device-specific, so it is left off <b>Desktop</b> and <b>Mobile</b> rather than asked twice.</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Cart panel') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="cpp-wrap"><div class="cpp-card"><div class="cpp-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="cpp-wrap"><div class="cpp-card">'
        + '<div class="cpp-title">Cart panel</div>'
        + '<p class="cpp-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="cpp-actions"><button class="cpp-btn" data-cpp-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="cpp-tab" data-cpp-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    /* Two columns on the MOBILE tab only. Matched on the prefix rather than
       listed, so a phone tab added later gets the side-by-side layout its own
       name claims instead of silently falling through to the full-width one. */
    var side = /^mobile/.test(String(open));

    host.innerHTML = '<div class="cpp-wrap' + (side ? ' cpp-side' : '') + '">'
      + (side ? '<div class="cpp-col">' : '')
      + (banner ? '<div class="cpp-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + noteHTML()
      + '<div class="cpp-card">'
      + '<div class="cpp-tabs">' + strip + '</div>'
      + '<p class="cpp-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="cpp-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '<div class="cpp-actions">'
      + '<button class="cpp-btn is-primary" data-cpp-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="cpp-btn" data-cpp-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      /* THE PRESETS WRITE THE SLIDERS AND NOTHING ELSE, and only the ones on the
         tab being looked at. The owner's report on the checkout screen's version
         of this button was "when i click squeezed, it applies on all tabs ...
         which is not correct": a preset that reaches past the screen changes
         numbers nobody can see, so the only way to learn what it did is to visit
         every tab. Nothing is stored until Save, and Reload undoes both. */
      + '<button class="cpp-btn" data-cpp-squeeze' + (busy ? ' disabled' : '') + '>Squeeze this tab</button>'
      + '<button class="cpp-btn" data-cpp-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
      + '</div>'
      + '<p class="cpp-help" style="margin-top:9px">Both presets move only the sliders on '
      + '<b>this tab</b> — the other tabs are left exactly as you set them. Nothing is stored until '
      + 'you press Save, and Reload puts them back.</p>'
      + '</div>'
      + (side ? '</div>' : '')
      + previewHTML()
      + '</div>';
  }

  /* --------------------------------------------------------------- presets */
  /*
   * `min` drives every range on the open tab to the bottom of its own scale;
   * `default` puts every field on it back to the value the schema ships. The two
   * are deliberately not symmetrical: squeezing is a look, applied to the
   * controls that make a panel denser, while "back to defaults" has to be able
   * to undo anything at all or it is not a way out.
   *
   * Squeeze reaches the four tap targets too, and that is on purpose: their
   * minimum is below 44 because the owner asked for it, and a preset that
   * silently skipped them would be the clamp this screen refuses to have, moved
   * somewhere harder to see. The warning lines appear under all four when it
   * does, which is exactly the point at which he can decide.
   */
  function preset(which) {
    if (!tabs) return;

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    current.fields.forEach(function (f) {
      if (which === 'default') { values[f.key] = f['default']; return; }
      if (f.type !== 'range') return;
      values[f.key] = Number((f.options || {}).min);
    });

    render();
    say(which === 'min'
      ? 'Squeezed — ' + current.label + ' only. Nothing is saved until you press Save.'
      : current.label + ' is back to its shipped values. Nothing is saved until you press Save.');
  }

  /* -------------------------------------------------------------- preview */
  function pvNum(key, fallback) {
    var v = Number(values[key]);
    return isFinite(v) ? v : fallback;
  }

  /* The Content tab's switches, read the way the storefront reads them: absent
     means on, because that is what a fresh shop renders. */
  function pvOn(key) { return values[key] !== false; }

  /*
   * THE PHONE MOCK IS A 320px FRAME STANDING FOR A 390px SCREEN, so every stored
   * pixel is drawn at this share of itself and a 9px row reads here at the size
   * it reads on the owner's own phone. One decimal: the point of a preview is
   * proportion, and 0.05px is not a difference anybody can see.
   *
   * The desktop mock is drawn 1:1 instead — a laptop panel is 380px and the card
   * it sits in is wider than that on anything but a phone, so there is nothing to
   * scale and scaling would only make the numbers on the ruler untrue. On a 390px
   * admin the panel's own max-width:100% takes over, which is why the ruler says
   * what the real width is rather than leaving it to be measured off the screen.
   */
  var PHONE = 320, PHONE_REAL = 390;
  function pvPx(key, fallback) {
    return (pvNum(key, fallback) * (PHONE / PHONE_REAL)).toFixed(1) + 'px';
  }
  function pvOwn(key, fallback) { return pvNum(key, fallback) + 'px'; }

  /* A percentage as the factor the stylesheet multiplies by — the same
     arithmetic CartPanel::cssVariables() does, so the drawing and the panel
     cannot disagree about what 115% means. */
  function pvFactor(key) { return (pvNum(key, 100) / 100).toFixed(3); }

  /* Four lines, the last of them long enough to reach the line clamp, which is
     the state the owner has to be able to see. */
  var PV_ITEMS = [
    {i: 'I', n: 'Age-R Booster Pro Device', p: '80', g: 'linear-gradient(140deg,#F6C6A0,#E89B6C)'},
    {i: 'RL', n: 'Hyaluronic Acid Watery Sun Gel that runs to a second line', p: '133', g: 'linear-gradient(140deg,#F4A6B8,#E0567B)'},
    {i: 'M', n: 'Cellmazing Fit Serum', p: '299', g: 'linear-gradient(140deg,#A8D0F0,#5F9BD4)'},
    {i: 'S', n: 'Ceramide Daily Moisturiser', p: '329', g: 'linear-gradient(140deg,#C4B5F0,#8B6FD4)'}
  ];

  function pvLines() {
    return PV_ITEMS.map(function (it) {
      return '<div class="cpv-item">'
        + (pvOn('show_thumb') ? '<div class="cpv-th" style="background:' + esc(it.g) + '">' + esc(it.i) + '</div>' : '')
        + '<div class="cpv-mid"><div class="cpv-nm">' + esc(it.n) + '</div>'
        + (pvOn('show_qty') ? '<div class="cpv-qty"><span>&minus;</span><b>2</b><span>+</span></div>' : '')
        + '</div>'
        + '<div class="cpv-right">'
        + (pvOn('show_remove') ? '<span class="cpv-rm">✕</span>' : '')
        + (pvOn('show_price') ? '<div class="cpv-pr">' + esc(it.p) + ' د.إ</div>' : '')
        + '</div></div>';
    }).join('');
  }

  /* The panel itself, drawn once and used by both mocks. `px` is the scaler the
     surface uses — 1:1 on the desktop mock, the phone frame's 320/390 on the
     other — and `k` decorates a key name with the surface's suffix, so ONE
     function draws both and neither can drift from the other. */
  function pvPanel(k, px, vars, isPhone) {
    return '<div class="cpv-panel" style="' + vars
      + ';--cpv-pad:' + px(k('list_pad'), 16)
      + ';--cpv-rowpad:' + px(k('row_pad'), 9)
      + ';--cpv-thumb:' + px(k('thumb_size'), 42)
      + ';--cpv-nm:' + px(k('name_size'), 13)
      + ';--cpv-lines:' + pvNum(k('name_lines'), 2)
      + ';--cpv-step:' + px(k('stepper_size'), 22)
      + ';--cpv-rm:' + px(k('rm_size'), 13)
      + ';--cpv-btngap:' + px(k('btn_gap'), 8)
      + ';--cpv-btnpad:' + px(k('btn_pad'), 12)
      + ';--cpv-btnr:' + px(k('btn_radius'), 99)
      + ';--cpv-acc:' + esc(String(values.accent || '#C13E63'))
      + '">'
      + '<div class="cpv-tabs">'
      + '<b style="color:' + esc(String(values.accent || '#C13E63')) + ';border-bottom-color:'
      + esc(String(values.accent || '#C13E63')) + '">' + esc(String(values.txt_tab_cart || 'Cart'))
      + '<em>4</em></b>'
      + (pvOn('show_browsed') ? '<i>' + esc(String(values.txt_tab_browsed || 'Browsed')) + '</i>' : '')
      + '<span class="cpv-x">✕</span>'
      + '</div>'
      + (pvOn('show_ship_bar')
          ? '<div class="cpv-ship">' + esc(String(values.txt_ship_done || ''))
            + '<div class="cpv-bar2"><div style="background:'
            + esc(String(values.accent || '#C13E63')) + '"></div></div></div>'
          : '')
      + '<div class="cpv-body">' + pvLines() + '</div>'
      + (pvOn('show_promo') ? '<div class="cpv-promo">🎁 Spend 199 for free delivery</div>' : '')
      + '<div class="cpv-foot">'
      + '<div class="cpv-sum"><span>' + esc(String(values.txt_subtotal || 'Subtotal')) + '</span><span>841 د.إ</span></div>'
      /* The stacked arrangement is a MOBILE control, so only the phone mock
         draws it — `.cp-btnstack .kc-btns` is inside the shop's own phone block
         and the desktop panel is unaffected by it. */
      + '<div class="cpv-btns' + (isPhone && String(values.btn_layout_m) === 'stack' ? ' is-stack' : '') + '">'
      + '<a>' + esc(String(values.txt_btn_cart || 'Cart')) + '</a>'
      + '<a style="background:' + esc(String(values.checkout_bg || '#C13E63'))
      + ';color:' + esc(String(values.checkout_fg || '#FFFFFF'))
      + ';border-color:' + esc(String(values.checkout_bg || '#C13E63')) + '">'
      + esc(String(values.txt_btn_checkout || 'Checkout')) + '</a>'
      + '</div></div></div>';
  }

  /* The ruler under a mock: the numbers the owner is actually setting, printed
     rather than left to be guessed at. A tap target under 44 is marked here as
     well as under its slider, because this is the picture he is looking at. */
  function pvTap(key, label) {
    var v = pvNum(key, 44);
    return label + ' <b>' + v + 'px</b>' + (v < touchMin ? ' <s>under ' + touchMin + '</s>' : '');
  }

  function previewDesktop() {
    var k = function (n) { return n; };
    var vars = '--cpv-w:' + pvNum('panel_width', 380) + 'px'
      + ';--cpv-x:' + pvOwn('x_size', 28)
      + ';--cpv-xg:' + pvOwn('x_glyph', 13)
      + ';--cpv-tabh:30px;--cpv-tabf:11px;--cpv-tabpad:10px'
      /* No box on the desktop ✕ — .kc-rm has none there either, it is a glyph in
         the flow. `auto` is what the rule falls back to. */
      + ';--cpv-rmbox:auto'
      + ';--cpv-prf:calc(12.5px * ' + pvFactor('price_size') + ')'
      + ';--cpv-btnf:calc(13.5px * ' + pvFactor('btn_size') + ')'
      + ';--cpv-btnh:0px';

    return '<div class="cpv-frame">'
      + '<div class="cpv-bar"><i></i><i></i><i></i><span>Wider than ' + phoneMax + 'px · drawn 1:1</span></div>'
      + '<div class="cpv-stage" style="--cpv-stage:360px">' + pvPanel(k, pvOwn, vars, false) + '</div>'
      + '</div>'
      + '<div class="cpv-ruler">Panel <b>' + pvNum('panel_width', 380) + 'px</b>'
      + '<span>·</span>list padding <b>' + pvNum('list_pad', 16) + 'px</b>'
      + '<span>·</span>row <b>' + pvNum('row_pad', 9) + 'px</b> above and below'
      + '<span>·</span>name <b>' + pvNum('name_size', 13) + 'px</b>'
      + '<span>·</span>price <b>' + (12.5 * pvNum('price_size', 100) / 100).toFixed(2) + 'px</b>'
      + '<span>·</span>stepper <b>' + pvNum('stepper_size', 22) + 'px</b>'
      + '<span>·</span>line ✕ <b>' + pvNum('rm_size', 13) + 'px</b>'
      + '<span>·</span>close <b>' + pvNum('x_size', 28) + 'px</b>'
      + '<span>·</span>button label <b>' + (13.5 * pvNum('btn_size', 100) / 100).toFixed(2) + 'px</b>'
      + '</div>';
  }

  function previewMobile() {
    var k = function (n) { return n + '_m'; };
    /* THE FRAME IS THE PHONE AND THE PANEL IS A SHARE OF IT, which is what
       panel_width_m sets — a percentage, not a pixel count, so it needs no
       scaling and the hatched page behind it is the part the shopper still sees. */
    var vars = '--cpv-w:' + pvNum('panel_width_m', 77) + '%'
      + ';--cpv-x:' + pvPx('x_size_m', 44)
      + ';--cpv-xg:' + pvPx('x_glyph_m', 13)
      + ';--cpv-tabh:' + pvPx('tab_h_m', 44)
      + ';--cpv-tabf:10px;--cpv-tabpad:5px'
      + ';--cpv-rmbox:' + pvPx('rm_tap_m', 44)
      + ';--cpv-prf:calc(12.5px * ' + pvFactor('price_size_m') + ' * ' + (PHONE / PHONE_REAL).toFixed(4) + ')'
      + ';--cpv-btnf:calc(12px * ' + pvFactor('btn_size_m') + ' * ' + (PHONE / PHONE_REAL).toFixed(4) + ')'
      + ';--cpv-btnh:' + pvPx('btn_h_m', 44);

    return '<div class="cpv-frame is-phone">'
      + '<div class="cpv-bar"><i></i><i></i><i></i><span>' + PHONE_REAL + 'px phone</span></div>'
      + '<div class="cpv-stage" style="--cpv-stage:340px">' + pvPanel(k, pvPx, vars, true) + '</div>'
      + '</div>'
      /* Outside the frame. Inside a 320px one this wrapped to four ragged rows
         and read as a layout fault in the thing it is measuring. */
      + '<div class="cpv-ruler">Drawn at <b>' + PHONE + 'px</b> for a <b>' + PHONE_REAL + 'px</b> phone'
      + '<span>·</span>panel <b>' + pvNum('panel_width_m', 77) + '%</b> = <b>'
      + Math.round(PHONE_REAL * pvNum('panel_width_m', 77) / 100) + 'px</b>'
      + '<span>·</span>list padding <b>' + pvNum('list_pad_m', 11) + 'px</b>'
      + '<span>·</span>row <b>' + pvNum('row_pad_m', 9) + 'px</b>'
      + '<span>·</span>name <b>' + pvNum('name_size_m', 13) + 'px</b>'
      + '<span>·</span>price <b>' + (12.5 * pvNum('price_size_m', 100) / 100).toFixed(2) + 'px</b>'
      + '<span>·</span>stepper <b>' + pvNum('stepper_size_m', 22) + 'px</b>'
      + '<span>·</span>line ✕ <b>' + pvNum('rm_size_m', 15) + 'px</b> in '
      + pvTap('rm_tap_m', 'a box of')
      + '<span>·</span>' + pvTap('x_size_m', 'close')
      + '<span>·</span>' + pvTap('tab_h_m', 'tab strip')
      + '<span>·</span>' + pvTap('btn_h_m', 'buttons')
      + '</div>';
  }

  function previewHTML() {
    var body = /^mobile/.test(String(open)) ? previewMobile() : previewDesktop();

    return '<div class="cpp-card" data-cpp-preview>'
      + '<div class="cpv-h"><b>Preview</b><span>Redraws as you drag. A drawing, not the live panel.</span></div>'
      + body + '</div>';
  }

  /* Repaint without rebuilding the controls — rebuilding them mid-drag drops the
     pointer capture and the slider stops following the finger. */
  function paintPreview() {
    var node = document.querySelector('[data-cpp-preview]');
    if (!node) return;
    var holder = document.createElement('div');
    holder.innerHTML = previewHTML();
    node.replaceWith(holder.firstChild);
  }

  /* --------------------------------------------------------------- events */
  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-cpp-key]');
    if (!el) return;

    var key = el.getAttribute('data-cpp-key');
    var fld = fieldFor(key);
    var kind = fld ? fld.type : (el.type === 'checkbox' ? 'bool' : 'range');

    /* BY THE FIELD'S TYPE, NOT THE ELEMENT'S. `Number(el.value)` on a select
       stored NaN for every select on the checkout screen — see fieldHTML. */
    if (kind === 'bool') values[key] = el.checked;
    else if (kind === 'select' || kind === 'text' || kind === 'colour') values[key] = String(el.value);
    else values[key] = Number(el.value);

    /* A checkbox or a select can decide whether another control belongs on the
       screen, so both redraw rather than only repainting. A text field and a
       colour must NOT: a redraw on every keystroke would take the caret to the
       end of the line on the second character, and on every drag of a colour
       picker it would close the picker. */
    if (el.type === 'checkbox' || el.tagName === 'SELECT') { render(); return; }

    var out = document.querySelector('[data-cpp-val="' + key + '"]');
    if (out && fld) out.textContent = kind === 'colour' ? String(values[key]) : shown(fld);

    /* The tap-target line appears and disappears as the number crosses 44. Its
       own span, replaced in place, so the slider under the pointer is untouched. */
    var warn = document.querySelector('[data-cpp-warn="' + key + '"]');
    if (warn && fld) warn.innerHTML = warnHTML(fld);

    paintPreview();
  });

  document.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-cpp-tab]');
    if (tab) { open = tab.getAttribute('data-cpp-tab'); render(); return; }

    if (e.target.closest('[data-cpp-squeeze]')) { preset('min'); return; }
    if (e.target.closest('[data-cpp-defaults]')) { preset('default'); return; }
    if (e.target.closest('[data-cpp-save]')) { save(); return; }
    if (e.target.closest('[data-cpp-reload]')) { load(); return; }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
