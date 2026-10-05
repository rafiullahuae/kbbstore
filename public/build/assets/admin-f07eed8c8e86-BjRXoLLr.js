
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
  /* Lane QC: the device whose design is being EDITED, which is also the
     device the live preview draws; what the preview is drawn on; whether it
     is folded away; and his categories, for "Preview with". */
  var pvDevice = 'phone';
  var pvWith = 'dark';
  var pvHidden = false;
  var pvCats = null, pvCatsBusy = false;
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
        + '<select id="' + id + '" data-sls-key="' + esc(f.key) + '"'
        /* Lane FS: the Fonts tab's two selects become the font picker, which
           shows each family in its own face (admin/partials/font-picker). */
        + (/^font_(body|heading)$/.test(f.key) ? ' data-sls-font="' + (f.key === 'font_heading' ? 'heading' : 'body') + '"' : '')
        + '>' + opts + '</select>' + help + '</div>';
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
   * ── THE CATEGORY HEADER: DESIGNS, PER DEVICE, AND THE LIVE PREVIEW ─────────
   *                                                       (Lanes PY and QC)
   *
   * PY drew a preview of the header under the fields, three times, from the
   * unsaved values. QC keeps that promise -- the shop's own stylesheet, the
   * same classes TitleHeader writes, redrawn before anything is saved -- and
   * moves the drawing into window.kbbTH (title-header-kit), because the
   * category editor needs the same header and the same tiles.
   *
   * THE KEYS a tile writes depend on the device being edited: the phone's are
   * PY's five (cat_header_align, ...), the laptop's the same with _desktop
   * (SiteLayout::DEVICE_PAIRS). Everything else -- the fine-tuning, the sizes
   * -- is one value for both.
   */
  var TH = function () { return window.kbbTH; };

  var PICK_KEYS = {
    'sls-box': 'cat_header_box_style',
    'sls-treat-img': 'cat_header_treatment',
    'sls-treat-box': 'cat_header_box_treatment',
    'sls-align': 'cat_header_align',
    'sls-text': 'cat_header_text',
    'sls-valign': 'cat_header_valign'
  };

  function devKey(base, dev) { return base + ((dev || pvDevice) === 'laptop' ? '_desktop' : ''); }

  function fieldOf(key) {
    var found = null;
    (tabs || []).forEach(function (t) { t.fields.forEach(function (f) { if (f.key === key) found = f; }); });
    return found;
  }

  function pickerHTML(name) {
    var th = TH();
    if (!th) return '';
    var dev = pvDevice === 'laptop' ? 'laptop' : 'phone';
    var word = dev === 'laptop' ? 'laptop' : 'phone';
    var common = { values: values, dev: dev };

    if (name === 'box') return th.tiles(Object.assign({ group: 'sls-box', kind: 'box',
      label: 'A–F · The light box, for a category with no picture · ' + word,
      value: values[devKey('cat_header_box_style')] }, common));
    if (name === 'treat-img') return th.tiles(Object.assign({ group: 'sls-treat-img', kind: 'treat-img',
      label: '1–5 · Keeping the words readable over a picture · ' + word,
      hint: 'Each tile is the option sheet’s row: the same title on a busy, dark picture and on a light one.',
      value: values[devKey('cat_header_treatment')] }, common));
    if (name === 'treat-box') return th.tiles(Object.assign({ group: 'sls-treat-box', kind: 'treat-box',
      label: '1–5 · Keeping the words readable on the light box · ' + word,
      value: values[devKey('cat_header_box_treatment')] }, common));
    if (name === 'align') return th.tiles(Object.assign({ group: 'sls-align', kind: 'align',
      label: 'Text alignment · ' + word,
      hint: '<b>Start is right-aligned on Arabic pages</b> — the third picture on each tile is the Arabic page.',
      value: values[devKey('cat_header_align')] }, common));
    if (name === 'text') return th.tiles(Object.assign({ group: 'sls-text', kind: 'text',
      label: 'Text colour · ' + word,
      value: values[devKey('cat_header_text')] }, common));
    if (name === 'valign') return th.tiles(Object.assign({ group: 'sls-valign', kind: 'valign',
      label: 'Where the words sit, top to bottom · ' + word,
      hint: 'Bottom is what you asked for: the title and description at the foot of the header, on the start side. '
        + 'Move them in from the edges with the inner-space sliders on <b>Category header · sizes &amp; spacing</b>.',
      value: values[devKey('cat_header_valign')] }, common));
    return '';
  }

  /* A colour that may be blank ("as drawn"): the picker, the box, and a button
     back to automatic. The server is the judge: SiteLayout::optionalColour(). */
  function optColourHTML(f) {
    var id = 'sls-' + f.key;
    var hex = String(values[f.key] || '');
    var six = /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : '#888888';
    return '<div class="sls-f"><div class="sls-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<div class="sls-optc"><span class="sls-colour">'
      + '<input type="color" data-sls-key="' + esc(f.key) + '" value="' + esc(six) + '" aria-label="' + esc(f.label) + '">'
      + '<input type="text" id="' + id + '" data-sls-key="' + esc(f.key) + '" value="' + esc(hex) + '" maxlength="7"'
      + ' autocomplete="off" spellcheck="false" placeholder="Automatic"></span>'
      + '<button type="button" class="sls-btn" data-sls-auto="' + esc(f.key) + '"' + (hex ? '' : ' disabled') + '>As drawn (automatic)</button>'
      + '</div>' + (f.help ? '<p class="sls-help">' + esc(f.help) + '</p>' : '') + '</div>';
  }

  function tuneField(key) {
    var f = fieldOf(key);
    if (!f) return '';
    return TH().OPTIONAL_COLOURS.indexOf(key) !== -1 ? optColourHTML(f) : fieldHTML(f);
  }

  /* "Make edits as per need": the chosen design's own fine-tuning. */
  function tuneHTML(design, kind) {
    var th = TH();
    var keys = (th && th.TUNE[design]) || [];
    var n = kind === 'box' ? th.BOX_NAMES[design] : th.TREATMENT_NAMES[design];
    if (!n) return '';
    if (!keys.length) {
      return '<div class="thk-tune"><p class="sls-help">' + esc(n[0] + ' · ' + n[1]) + ' has nothing to tune: the words sit on the background as they are.</p></div>';
    }
    return '<div class="thk-tune" data-sls-tune="' + esc(design) + '">'
      + '<div class="thk-tune-h"><b>Fine-tune ' + esc(n[0] + ' · ' + n[1]) + ' <span class="sls-help" style="display:inline">(phone and laptop)</span></b>'
      + '<button type="button" class="sls-btn" data-sls-tunereset="' + esc(design) + '">Back to defaults for ' + esc(n[0] + ' · ' + n[1]) + '</button></div>'
      + '<div class="thk-tune-grid">' + keys.map(tuneField).join('') + '</div></div>';
  }

  function summaryHTML() {
    var th = TH();
    function one(dev) {
      var box = th.BOX_NAMES[values[devKey('cat_header_box_style', dev)]] || ['?', ''];
      var ti = th.TREATMENT_NAMES[values[devKey('cat_header_treatment', dev)]] || ['?', ''];
      var tb = th.TREATMENT_NAMES[values[devKey('cat_header_box_treatment', dev)]] || ['?', ''];
      var al = th.ALIGN_NAMES[values[devKey('cat_header_align', dev)]] || ['?', ''];
      var tx = th.TEXT_NAMES[values[devKey('cat_header_text', dev)]] || ['?', ''];
      var va = th.VALIGN_NAMES[values[devKey('cat_header_valign', dev)]] || ['?', ''];
      return '<b>' + (dev === 'laptop' ? 'Laptop' : 'Phone') + ':</b> box ' + esc(box[0]) + ' · picture ' + esc(ti[0])
        + ' · box words ' + esc(tb[0]) + ' · ' + esc(al[0]) + ' · ' + esc(va[0]) + ' · ' + esc(tx[0]) + ' text';
    }
    return '<p class="sls-sum" data-sls-sum>' + one('phone') + '<br>' + one('laptop') + '</p>'
      + '<div class="sls-actions" style="margin-top:8px">'
      + '<button type="button" class="sls-btn" data-sls-copy="laptop">Use the phone’s design on laptop too</button>'
      + '<button type="button" class="sls-btn" data-sls-copy="phone">Use the laptop’s design on phone too</button>'
      + '</div>';
  }

  /*
   * The "Category header" tab. THE DESIGNS COME FIRST: the owner, on
   * 2.60.350, "i can not see any designs on backend you made for me" -- so the
   * tiles are the first thing on the tab, under the Phone | Laptop switch,
   * and the switches PY shipped (on/off, the light box, brands) and the
   * generic line follow them.
   */
  function lookHTML(tab) {
    var th = TH();
    if (!th) return tab.fields.map(fieldHTML).join('');

    var phone = pvDevice !== 'laptop';
    var box = values[devKey('cat_header_box_style')];
    var ti = values[devKey('cat_header_treatment')];
    var tb = values[devKey('cat_header_box_treatment')];

    var design = '<div class="sls-design" style="border-top:0;padding-top:0">'
      + '<div class="sls-title">Choose the design · ' + (phone ? 'Phone' : 'Laptop') + '</div>'
      + '<p class="sls-help">The designs from the option sheet, A–F and 1–5, each drawn by the shop’s own stylesheet. '
      + 'Choose the device, then click a design; the live preview follows. Phone is under 900px wide, laptop 900px and wider. '
      + 'Nothing is stored until you press Save.</p>'
      + '<div style="margin-top:8px">' + th.deviceSwitch('data-sls-pv', pvDevice, 'Edit and preview the design for') + '</div>'
      + summaryHTML()
      + '<div data-sls-tiles="box">' + pickerHTML('box') + '</div>'
      + tuneHTML(box, 'box')
      /* 2.60.353: "random colored background ... on each page load" -- the
         switch and its mix sit under the box styles they choose between. */
      + '<div class="thk-tune"><div class="thk-tune-h"><b>A different light box on every visit</b></div>'
      + ['cat_header_box_random', 'cat_header_rand_blush', 'cat_header_rand_cream', 'cat_header_rand_mint',
          'cat_header_rand_lilac', 'cat_header_rand_plain']
          .map(function (k) { var f = fieldOf(k); return f ? fieldHTML(f) : ''; }).join('')
      + '</div>'
      + '<div data-sls-tiles="treat-img">' + pickerHTML('treat-img') + '</div>'
      + '<div class="thk-tune">' + (fieldOf('cat_header_overlay') ? fieldHTML(fieldOf('cat_header_overlay')) : '') + '</div>'
      + tuneHTML(ti, 'treat')
      + '<div data-sls-tiles="treat-box">' + pickerHTML('treat-box') + '</div>'
      + (tb !== ti ? tuneHTML(tb, 'treat')
          : '<p class="sls-help" style="margin-top:6px">The fine-tuning of this design is above, under the picture’s — it is one setting for both.</p>')
      + '<div data-sls-tiles="valign">' + pickerHTML('valign') + '</div>'
      + '<div data-sls-tiles="align">' + pickerHTML('align') + '</div>'
      + '<div data-sls-tiles="text">' + pickerHTML('text') + '</div>'
      + '<div class="thk-tune"><div class="thk-tune-h"><b>Fine-tune the words <span class="sls-help" style="display:inline">(phone and laptop)</span></b></div>'
      + '<div class="thk-tune-grid">' + tuneField('cat_header_letter') + tuneField('cat_header_desc_colour') + '</div>'
      + '<p class="sls-help">Title size, title weight, description size and lines, the header’s height, its inner space and the space around it — '
      + 'each for phone and laptop — are on <b>Category header · sizes &amp; spacing</b>, with this same live preview.</p></div>'
      + '</div>';

    var rest = ['cat_header', 'cat_header_box', 'cat_header_fallback', 'cat_header_brands', 'cat_header_box_brands', 'cat_header_phone_whole', 'cat_header_generic']
      .map(function (k) { var f = fieldOf(k); return f ? fieldHTML(f) : ''; }).join('');

    return design + '<div class="sls-design"><div class="sls-title">Where it shows, and the words</div>' + rest + '</div>';
  }

  /* What the live preview is drawn on. */
  function pvWithHTML() {
    var opts = [['dark', 'Sample · a busy, dark picture'], ['light', 'Sample · a light picture'], ['box', 'Sample · no picture (the light box)'],
      ['nodesc', 'Sample · no picture and no description']];
    var html = opts.map(function (o) {
      return '<option value="' + o[0] + '"' + (pvWith === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
    }).join('');
    if (pvCats && pvCats.length) {
      html += '<optgroup label="Your categories">' + pvCats.map(function (c) {
        var v = 'cat:' + c.id;
        return '<option value="' + esc(v) + '"' + (pvWith === v ? ' selected' : '') + '>'
          + esc(c.name + (c.header_image ? ' · banner' : ' · no banner')) + '</option>';
      }).join('') + '</optgroup>';
    }
    return '<label class="sls-with">Preview with<select data-sls-pvwith>' + html + '</select></label>'
      + (pvCats === null ? '<p class="sls-help">Loading your categories…</p>' : '');
  }

  function previewHeader() {
    var th = TH();
    var dev = pvDevice === 'laptop' ? 'laptop' : 'phone';
    var base = { values: values, dev: dev, title: 'Korean Sunscreens',
      desc: 'Lightweight Korean sunscreens with high UV protection, made for everyday wear under the UAE sun — no white cast, no greasy finish.' };

    if (pvWith.indexOf('cat:') === 0 && pvCats) {
      var id = pvWith.slice(4);
      var c = pvCats.filter(function (x) { return String(x.id) === id; })[0];
      if (c) {
        var image = th.safeImage(c.header_image) || (th.on(values.cat_header_fallback) ? th.safeImage(c.image) : '');
        var desc = th.plain(c.header_description || c.description);
        var own = (c.header_style && typeof c.header_style === 'object') ? Object.keys(c.header_style) : [];
        return {
          html: th.header({ values: values, dev: dev, kind: image ? 'img' : 'box', image: image, generic: true,
            title: c.header_title || c.name, sub: c.header_subtitle || '', desc: desc, long: desc.length > 180 }),
          note: (image ? 'Its own banner. ' : 'No banner: the light box. ')
            + (desc ? '' : 'It has no description, so it shows the line for a category with none. ')
            + (own.length ? 'This category also has its own choices (Catalog → Categories → Edit → Category header), which its page lays over these; here you see the shop’s.' : '')
        };
      }
    }

    if (pvWith === 'nodesc') return { html: th.header(Object.assign({}, base, { kind: 'box', title: 'Lip Care', desc: '', generic: true })),
      note: 'A category with no description of its own shows the line set under “Line when a category has no description”.' };
    if (pvWith === 'box') return { html: th.header(Object.assign({ kind: 'box' }, base)),
      note: th.on(values.cat_header_box) ? '' : 'The light box is switched off: such a category shows its plain title. This is how it would look switched on.' };
    return { html: th.header(Object.assign({ kind: 'img', image: pvWith === 'light' ? th.PICTURES.light : th.PICTURES.dark }, base)), note: '' };
  }

  function previewHTML() {
    var th = TH();
    if (!th) return '';
    var on = th.on(values.cat_header);
    var body = '';

    if (!pvHidden) {
      var pv = previewHeader();
      body = '<div style="margin-top:9px">' + th.deviceSwitch('data-sls-pv', pvDevice, 'Preview for') + '</div>'
        + pvWithHTML()
        + '<div data-sls-livebody>' + th.frame(pvDevice, pv.html) + '</div>'
        + '<p class="thk-livecap">' + (pvDevice === 'laptop'
            ? 'Laptop: the header as a 1280px screen draws it (1236px wide), scaled to fit.'
            : 'Phone: the header as a 390px phone draws it (346px wide).')
          + ' Drawn with the shop’s own stylesheet from the values on this screen, before anything is saved.'
          + (pv.note ? ' ' + esc(pv.note) : '') + '</p>'
        + (on ? '' : '<div class="sls-note" style="margin-top:8px">The header is switched off: every category page shows its plain title.</div>');
    }

    return '<div class="sls-card sls-live" data-sls-live>'
      + '<div class="sls-live-h"><div class="sls-title">Live preview · ' + (pvDevice === 'laptop' ? 'Laptop' : 'Phone') + '</div>'
      + '<button type="button" class="sls-btn" data-sls-livehide aria-expanded="' + (pvHidden ? 'false' : 'true') + '">'
      + (pvHidden ? 'Show preview' : 'Hide preview') + '</button></div>'
      + body + '</div>';
  }

  async function loadCats() {
    if (pvCats !== null || pvCatsBusy) return;
    pvCatsBusy = true;
    try {
      var body = await api('/categories');
      pvCats = ((body && body.categories) || []).slice(0, 500);
    } catch (e) {
      pvCats = [];   // no Catalog rights: the samples are still there
    } finally {
      pvCatsBusy = false;
      repaintLive();
    }
  }

  /* Redraw the preview and the tiles in place, leaving the control being held. */
  function repaintLive() {
    var host = document.querySelector('#content');
    if (!host || !tabs || (open !== 'catheader' && open !== 'catheadersize')) return;
    var live = host.querySelector('[data-sls-live]');
    if (live) {
      var fresh = document.createElement('div');
      fresh.innerHTML = previewHTML();
      if (fresh.firstElementChild) live.replaceWith(fresh.firstElementChild);
    }
    /* A tile holding the focus keeps it across the redraw -- tabbing from a
       colour box into the tiles fires that box's `change`, which redraws. */
    var heldAt = TH() ? TH().held() : null;

    host.querySelectorAll('[data-sls-tiles]').forEach(function (el) {
      el.innerHTML = pickerHTML(el.getAttribute('data-sls-tiles'));
    });

    if (heldAt) TH().refocus(heldAt);
  }

  /* The card under the fields: the device table, or on the two Category
     header tabs the header preview. Nothing on "Loading more products". */
  function extraHTML(key) {
    if (key === 'loading') return '';
    if (key === 'catheader' || key === 'catheadersize') return '';   // drawn beside the fields (Lane QC)
    if (key === 'press') return pressHTML();                          // Lane RD
    return tableHTML();
  }

  /*
   * "Press feedback" (Lane RD): the shop's header icons, a heart, Add to cart
   * and the All sets pill, in the style the select above holds RIGHT NOW --
   * redrawn when it changes, before anything is saved. The letter is checked
   * against the field's own options before it becomes an attribute.
   */
  var PRESS_ICONS = {
    account: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="8" r="4.2"/><path d="M4 20.5c.8-4 4-6.3 8-6.3s7.2 2.3 8 6.3z"/></svg>',
    heart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg>',
    cart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2.2l2.3 10.5h10.8L20.5 7H6.4"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/></svg>'
  };

  function pressHTML() {
    var f = fieldOf('press');
    var v = String(values.press);
    if (!f || !Object.prototype.hasOwnProperty.call(f.options || {}, v)) v = f ? String(f['default']) : 'c';
    /* Lane FP: which controls respond. Checked against its own options too. */
    var sf = fieldOf('press_scope');
    var sv = String(values.press_scope);
    if (!sf || !Object.prototype.hasOwnProperty.call(sf.options || {}, sv)) sv = sf ? String(sf['default']) : 'icons';

    return '<div class="sls-card">'
      + '<div class="sls-title">Try it</div>'
      + '<p class="sls-sub">Tap or click the samples. They show the choices above as they are now, before you save; '
      + (sv === 'all' ? 'on the shop it applies to every button and icon.' : 'on the shop only the icons respond — Add to cart and All sets show nothing.') + '</p>'
      + '<div class="slp" data-sls-press="' + esc(v) + '" data-sls-scope="' + esc(sv) + '" data-sls-press-box>'
      + '<button type="button" class="slp-t slp-i" aria-label="Account">' + PRESS_ICONS.account + '</button>'
      + '<button type="button" class="slp-t slp-i" aria-label="Wishlist">' + PRESS_ICONS.heart + '</button>'
      + '<button type="button" class="slp-t slp-i" aria-label="Cart">' + PRESS_ICONS.cart + '</button>'
      + '<button type="button" class="slp-t slp-i slp-h" aria-label="Save">' + PRESS_ICONS.heart + '</button>'
      + '<button type="button" class="slp-t slp-b">Add to cart</button>'
      + '<button type="button" class="slp-t slp-p">All sets</button>'
      + '<p class="slp-cap">' + esc((f && f.options && f.options[v]) || '') + '</p>'
      + '</div></div>';
  }

  /* The samples' press: the same classes-only scheme as the shop's press.js,
     held at least 180ms so a quick tap is seen. Nothing is measured. */
  var slpHeld = null, slpAt = 0;
  document.addEventListener('pointerdown', function (e) {
    var t = e.target.closest ? e.target.closest('.slp .slp-t') : null;
    if (!t) return;
    /* "Small icons only" (Lane FP): the button and the pill stay still, as on the shop. */
    if (!t.classList.contains('slp-i') && !t.closest('[data-sls-scope="all"]')) return;
    slpHeld = t; slpAt = Date.now();
    t.classList.add('is-p');
    var r = t.classList.contains('is-r') ? 'is-r2' : 'is-r';
    t.classList.remove('is-r', 'is-r2'); t.classList.add(r);
  });
  function slpRelease() {
    var t = slpHeld; slpHeld = null;
    if (!t) return;
    setTimeout(function () {
      t.classList.remove('is-p');
      var o = t.classList.contains('is-o') ? 'is-o2' : 'is-o';
      t.classList.remove('is-o', 'is-o2'); t.classList.add(o);
    }, Math.max(0, 180 - (Date.now() - slpAt)));
  }
  document.addEventListener('pointerup', slpRelease);
  document.addEventListener('pointercancel', slpRelease);
  document.addEventListener('animationend', function (e) {
    if (e.target.classList && e.target.classList.contains('slp-t')) e.target.classList.remove('is-r', 'is-r2', 'is-o', 'is-o2');
  });

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

    var headerTab = current.key === 'catheader' || current.key === 'catheadersize';
    if (headerTab) loadCats();

    host.innerHTML = '<div class="sls-wrap">'
      + (banner ? '<div class="sls-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + '<div class="sls-note">The <b>cart page</b>, the <b>checkout</b> and the <b>slim footer</b> are '
      + 'not on this width. Each keeps its own Content width slider on its own screen — they are pages '
      + 'asking for money, and a narrow single column there is deliberate. Reading widths are not on it '
      + 'either: an article stays 720px wide however wide the page is.</div>'
      + (headerTab ? '<div class="sls-thx">' + previewHTML() : '')
      + '<div class="sls-card' + (headerTab ? ' sls-main' : '') + '">'
      + '<div class="sls-tabs">' + strip + '</div>'
      + '<p class="sls-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="sls-fields">' + (current.key === 'catheader' ? lookHTML(current) : current.fields.map(fieldHTML).join('')) + '</div>'
      + '<div class="sls-actions">'
      + '<button class="sls-btn is-primary" data-sls-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="sls-btn" data-sls-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      + '<button class="sls-btn" data-sls-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
      + '</div>'
      + '<p class="sls-help" style="margin-top:8px">“Back to defaults” moves only the sliders on '
      + '<b>this tab</b>. Nothing is stored until you press Save.</p>'
      + '</div>'
      + (headerTab ? '</div>' : '')
      /* The device table is about width and columns. On "Loading more
         products" it would be a page of numbers that no field on the tab can
         move, so it is not drawn there. (Lane PI-B) */
      + extraHTML(current.key)
      + '</div>';
    if (current.key === 'fonts' && window.kbbFontPicker) window.kbbFontPicker.enhance(host, 'data-sls-font');
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

  /* "Preview with": a sample or one of his categories (Lane QC). */
  document.addEventListener('change', function (e) {
    var w = e.target.closest ? e.target.closest('[data-sls-pvwith]') : null;
    if (!w) return;
    pvWith = String(w.value || 'dark');
    repaintLive();
    var again = document.querySelector('[data-sls-pvwith]');
    if (again) { try { again.focus({ preventScroll: true }); } catch (x) {} }
  });

  /* A design tile chosen (title-header-kit): the device's own key. */
  document.addEventListener('kbb-th-pick', function (e) {
    var d = e.detail || {};
    if (!PICK_KEYS[d.group] || !tabs) return;
    values[devKey(PICK_KEYS[d.group])] = d.value;
    render();
  });

  document.addEventListener('change', function (e) {
    var el = e.target.closest ? e.target.closest('[data-sls-key]') : null;
    if (!el) return;
    values[el.dataset.slsKey] = el.type === 'checkbox' ? el.checked : el.value;
    if (el.type === 'checkbox' || el.tagName === 'SELECT') render();
    /* On the Category header tabs `input` has already redrawn the preview
       and the tiles; redrawing again on `change` -- which fires on blur --
       would pull the tiles out from under a Tab into them (Lane QC). */
    else if (open !== 'catheader' && open !== 'catheadersize') repaintTable();
  });

  /* Only the table's own card, so the control you are holding is not replaced. */
  function repaintTable() {
    var host = document.querySelector('#content');
    if (!host || !tabs || open === 'loading') return;
    if (open === 'catheader' || open === 'catheadersize') { repaintLive(); return; }
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

    /* Phone | Laptop: the device being edited AND previewed (Lane QC), so
       the whole tab is redrawn -- the tiles now write the other device's keys. */
    var pv = e.target.closest('[data-sls-pv]');
    if (pv) {
      pvDevice = pv.dataset.slsPv === 'laptop' ? 'laptop' : 'phone';
      render();
      var again = document.querySelector('[data-sls-pv="' + pvDevice + '"]');
      if (again) { try { again.focus({ preventScroll: true }); } catch (x) {} }
      return;
    }

    if (e.target.closest('[data-sls-livehide]')) { pvHidden = !pvHidden; repaintLive(); return; }

    var copy = e.target.closest('[data-sls-copy]');
    if (copy) {
      var to = copy.dataset.slsCopy === 'phone' ? 'phone' : 'laptop';
      Object.keys(PICK_KEYS).forEach(function (g) {
        var b = PICK_KEYS[g];
        values[devKey(b, to)] = values[devKey(b, to === 'laptop' ? 'phone' : 'laptop')];
      });
      render();
      say('The ' + to + ' now uses the ' + (to === 'laptop' ? 'phone' : 'laptop') + '’s design. Nothing is saved until you press Save.');
      return;
    }

    var auto = e.target.closest('[data-sls-auto]');
    if (auto) { values[auto.dataset.slsAuto] = ''; render(); return; }

    var tr = e.target.closest('[data-sls-tunereset]');
    if (tr && window.kbbTH) {
      var design = tr.dataset.slsTunereset;
      (window.kbbTH.TUNE[design] || []).forEach(function (k) {
        var f = fieldOf(k);
        if (f) values[k] = f['default'];
      });
      render();
      say('Back to the design as drawn. Nothing is saved until you press Save.');
      return;
    }

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
