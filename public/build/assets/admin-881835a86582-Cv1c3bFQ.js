
(function () {
  'use strict';

  var SCREEN = 'wabutton';

  /* UNFINISHED CHANGES (Lane PM's tray), as page-wash-screen does it. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Appearance → WhatsApp button',
    values: function () { return tabs ? values : null; },
    set: function (k, v) { if (Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); },
    save: function () { save(); }
  });

  var tabs = null, values = {}, open = 'design', banner = null, busy = false, seq = 0;
  var preview = null, dev = 'm', lang = 'en', bad = {}, cssInjected = false, bubbleClosed = false;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function root() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var r = await fetch(root() + '/admin-api' + path, opts);
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
    if (e && e.status === 404) return 'The WhatsApp button endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change the WhatsApp button. An owner, manager or editor can.';
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'WhatsApp button',
      icon: '<path d="M4.5 19.5 6 15.6A8 8 0 1 1 9 18.6z"/><path d="M9.5 9.5c.4 2.2 2.3 4.4 5 5"/>',
      group: 'Appearance',
      after: ['pagewash', 'dividers', 'prodstyles', 'homepage']
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
    if (title) title.textContent = 'WhatsApp button';

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
      var body = await api('/whatsapp-button');
      if (mine !== seq) return;
      tabs = body.tabs || [];
      preview = body.preview || null;
      values = {};
      bad = {};
      tabs.forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (!tabs.some(function (t) { return t.key === open; })) open = tabs.length ? tabs[0].key : 'design';
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The WhatsApp button settings could not be read.');
    } finally {
      if (mine === seq) {
        busy = false; render();
        if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
    }
  }

  async function save() {
    if (busy || !tabs) return;
    busy = true; bad = {}; render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });

    try {
      await api('/whatsapp-button', { settings: payload });
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      say(values.enabled ? 'WhatsApp button saved. It is on the shop.' : 'WhatsApp button saved. It is OFF — the shop shows no button.');
      busy = false;
      await load();
      return;
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
      ((e && e.body && e.body.rejected) || []).forEach(function (k) { bad[k] = true; });
    } finally {
      busy = false; render();
    }
  }

  function field(key) {
    var out = null;
    (tabs || []).forEach(function (t) { t.fields.forEach(function (f) { if (f.key === key) out = f; }); });
    return out;
  }

  /* ── THE PREVIEW: the storefront partial's markup, built here ──────────── */

  var SIDES = ['top', 'right', 'bottom', 'left'];

  function offset(v) {
    var s = String(v == null ? '' : v).trim().toLowerCase().replace(/\s*px$/, '');
    if (s === '' || s === 'auto' || !/^-?\d{1,6}(\.\d+)?$/.test(s)) return null;
    return Math.max(0, Math.min(400, Math.round(Number(s))));
  }

  /* WhatsAppButton::placement(), for one device. */
  function place(d, mirror) {
    var p = {};
    SIDES.forEach(function (s) { p[s] = offset(values[d + '_' + s]); });
    var def = field(d + '_right'), defB = field(d + '_bottom');
    if (p.right !== null) p.left = null;
    else if (p.left === null) p.right = offset(def ? def['default'] : 16);
    if (p.bottom !== null) p.top = null;
    else if (p.top === null) p.bottom = offset(defB ? defB['default'] : 20);
    if (mirror) { var x = p.left; p.left = p.right; p.right = x; }
    return p;
  }

  /* WhatsAppButton::lines() for the language being previewed. */
  function lines() {
    var std = ((preview || {}).standard || {}).ar || {};
    var out = {};
    ['welcome', 'support', 'cap1', 'cap2'].forEach(function (k) {
      var en = String(values[k] == null ? '' : values[k]).trim();
      if (lang !== 'ar' || en === '') { out[k] = en; return; }
      var own = String(values[k + '_ar'] == null ? '' : values[k + '_ar']).trim();
      out[k] = own !== '' ? own : String(std[k] || '').trim();
    });
    return out;
  }

  function message(l) {
    return [l.welcome, l.support].filter(function (x) { return x !== ''; }).join('\n');
  }

  /* The same check WhatsAppButton::isSafeLink() makes, for the preview only;
     the server makes it again on save and again on every page. */
  function safeLink(v) {
    if (v.length > 500 || /[\x00-\x20\x7f"'<>`\\{}|^]/.test(v)) return false;
    if (!/^https:\/\/[^\/?#@\s]/i.test(v)) return false;
    try { var u = new URL(v); return u.protocol === 'https:' && u.hostname !== '' && u.username === '' && u.password === ''; } catch (e) { return false; }
  }

  function href(l) {
    var custom = String(values.link || '').trim();
    if (custom !== '' && safeLink(custom)) return custom;
    var text = message(l);
    return 'https://wa.me/' + ((preview || {}).digits || '') + (text === '' ? '' : '?text=' + encodeURIComponent(text));
  }

  /* WhatsAppButton::faces(): man first, then alternate, at equal angles. */
  function faces(w, m) {
    w = Math.max(0, Math.min(3, Number(w) || 0)); m = Math.max(0, Math.min(3, Number(m) || 0));
    var order = [];
    while (w > 0 || m > 0) {
      if (m > 0) { order.push('M'); m--; }
      if (w > 0) { order.push('W'); w--; }
    }
    return order.map(function (g, i) { return { g: g, a: Math.round(i * 360 / order.length * 100) / 100 }; });
  }

  function build() {
    var design = /^[A-G]$/.test(values.design) ? values.design : 'G';
    var size = Math.max(30, Math.min(110, Number(values.size) || 60));
    var mirror = lang === 'ar' && values.ar_side === 'mirror' && !!(preview || {}).rtl;
    var p = place(dev, mirror);
    var l = lines();
    var px = function (v) { return v === null ? 'auto' : v + 'px'; };

    var cls = 'kbw kbw-' + design + (p.left !== null ? ' kbw-ml kbw-dl' : '') + (p.top !== null ? ' kbw-mt kbw-dt' : '');
    var style = '--k:' + (Math.round(size / 60 * 10000) / 10000);
    ['m', 'd'].forEach(function (d) {
      style += ';--' + d + 't:' + px(p.top) + ';--' + d + 'r:' + px(p.right) + ';--' + d + 'b:' + px(p.bottom) + ';--' + d + 'l:' + px(p.left);
    });

    var inner = '', symbols = '';
    var fs = [];
    if (design === 'G') {
      style += ';--spd:' + (((preview || {}).speeds || {})[values.speed] || '14s');
      fs = faces(values.women, values.men);
      var all = (preview || {}).symbols || '';
      symbols = fs.length ? '<svg class="kbw-s" aria-hidden="true">' + all + '</svg>' : '';
    }
    var rings = { A: 2, B: 3, C: 3, D: 1, E: 1, F: 1, G: 1 }[design];
    for (var i = 1; i <= rings; i++) inner += '<span class="kbw-r' + (i > 1 ? ' kbw-r' + i : '') + '"></span>';
    if (design === 'D') inner += '<span class="kbw-h"></span>';
    if (design === 'F' || design === 'G') {
      inner += '<span class="kbw-o">' + fs.map(function (f) {
        return '<span class="kbw-v" style="--a:' + f.a + 'deg"><i><svg aria-hidden="true"><use href="#kbw' + f.g + '"/></svg></i></span>';
      }).join('') + '</span>';
    }

    var capsule = design === 'G' && values.capsule && (l.cap1 !== '' || l.cap2 !== '');
    var link = '<a class="kbw-a" href="' + esc(href(l)) + '" target="_blank" rel="noopener" aria-label="WhatsApp">'
      + ((preview || {}).icon || '')
      + (capsule ? '<span class="kbw-c"><span class="kbw-d"></span><span>'
          + (l.cap1 !== '' ? '<b>' + esc(l.cap1) + '</b>' : '')
          + (l.cap2 !== '' ? '<small>' + esc(l.cap2) + '</small>' : '') + '</span></span>' : '')
      + (design === 'E' && l.cap1 !== '' ? '<span class="kbw-t">' + esc(l.cap1) + '</span>' : '')
      + '</a>';

    var bubble = values.bubble === 'once' && !bubbleClosed && (l.welcome !== '' || l.support !== '')
      ? '<div class="kbw-b" role="status"><button type="button" class="kbw-x" data-wab-close aria-label="Close">&times;</button>'
        + (l.welcome !== '' ? '<strong>' + esc(l.welcome) + '</strong>' : '')
        + (l.support !== '' ? '<span>' + esc(l.support) + '</span>' : '') + '</div>'
      : '';

    return { html: '<div class="' + cls + '" style="' + esc(style) + '" data-wab-btn>' + symbols + '<div class="kbw-f">' + inner + link + '</div>' + bubble + '</div>', lines: l, href: href(l) };
  }

  /* ── THE SIDE TAB: partials/whatsapp-button.blade.php's tab, built here ── */

  /* WhatsAppButton::cleanLight()'s test, for the field's red outline only;
     the server refuses a dark colour on save whatever this says. */
  function light(hex) {
    if (!/^#[0-9a-f]{6}$/i.test(String(hex || ''))) return false;
    var ch = function (i) { var c = parseInt(hex.substr(i, 2), 16) / 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    return 0.2126 * ch(1) + 0.7152 * ch(3) + 0.0722 * ch(5) >= (((preview || {}).tab || {}).lightMin || 0.6);
  }

  function tabSize() {
    var t = (preview || {}).tab || {};
    return Math.max(t.min || 22, Math.min(t.max || 44, Number(values.tab_size) || t.base || 26));
  }

  function tabQ() { return Math.round(tabSize() / (((preview || {}).tab || {}).base || 26) * 10000) / 10000; }

  /* WhatsAppButton::tabLabel(). */
  function tabLabel() {
    var en = String(values.tab_label == null ? '' : values.tab_label).trim();
    if (lang !== 'ar' || en === '') return en;
    var own = String(values.tab_label_ar == null ? '' : values.tab_label_ar).trim();
    return own !== '' ? own : String((((preview || {}).standard || {}).ar || {}).tab_label || '').trim();
  }

  /* WhatsAppButton::tab(): the same classes and custom properties. */
  function buildTab() {
    var t = (preview || {}).tab || {};
    var pal = t.palettes || {};
    var stops = values.tab_palette === 'custom'
      ? [light(values.tab_c1) ? values.tab_c1 : '#FFE1EA', light(values.tab_c2) ? values.tab_c2 : '#E2F6EA']
      : (pal[values.tab_palette] || pal.blush || ['#FFE1EA', '#FFF0D9', '#E2F6EA']);
    if (stops.length === 2) stops = [stops[0], stops[1], stops[0]];
    var y = Math.max(0, Math.min(100, Math.round(Number(values.tab_y)) || 0));
    var mirror = lang === 'ar' && values.ar_side === 'mirror' && !!(preview || {}).rtl;
    var label = tabLabel();
    var style = '--q:' + tabQ() + ';--y:' + y + ';--c1:' + stops[0] + ';--c2:' + stops[1] + ';--c3:' + stops[2];
    return '<div class="kbt-z' + (mirror ? ' kbt-rt' : '') + (values.tab_anim ? '' : ' kbt-still') + '" style="' + esc(style) + '" data-wab-tabel>'
      + '<a class="kbt" href="' + esc(build().href) + '" target="_blank" rel="noopener" aria-label="' + esc(label === '' ? 'WhatsApp' : label) + '">'
      + '<span class="kbt-i">' + ((preview || {}).icon || '') + '</span>'
      + (label !== '' ? '<span class="kbt-l">' + esc(label) + '</span>' : '')
      + '</a></div>';
  }

  function tabStageHTML() {
    var note = !values.enabled ? 'The WhatsApp button is switched off (Design), so the shop prints neither the button nor the tab.'
      : !values.show_phone ? 'Hidden on phones (Design → Show on phones), so the tab is not shown either.'
      : !values.tab_on ? 'The side tab is off: the cart and checkout show the round button on phones, as every other page does.' : '';
    var mirror = lang === 'ar' && values.ar_side === 'mirror' && !!(preview || {}).rtl;
    var rows = '';
    for (var i = 0; i < 5; i++) rows += '<i><b></b><span><u></u></span><s></s></i>';
    return '<div class="wab-stage" data-wab-stage data-wab-dev="m" style="--kbtw:' + (note ? 0 : tabSize()) + 'px"' + (lang === 'ar' ? ' dir="rtl" lang="ar"' : '') + '>'
      + '<div class="wab-cart"' + (mirror ? ' style="padding:12px calc(var(--kbtw,26px) + 14px) 0 14px"' : '') + '><em></em>' + rows + '</div>'
      + '<div class="wab-dock"><span></span></div>'
      + (note ? '<p class="wab-off" style="bottom:80px">' + esc(note) + '</p>' : buildTab())
      + '</div>';
  }

  function stageNote() {
    if (!values.enabled) return 'The button is switched off, so the shop prints nothing at all. Switch it on under Design to see it here.';
    if (dev === 'm' && !values.show_phone) return 'Hidden on phones (Design → Show on phones).';
    if (dev === 'd' && !values.show_desktop) return 'Hidden on desktop (Design → Show on desktop).';
    return '';
  }

  function previewHTML() {
    if (open === 'tab') {
      return '<div class="wab-card wab-prev"><div class="wab-title">Live preview · phone cart</div>'
        + '<p class="wab-sub">A 390px phone on the cart page, with the shop’s own stylesheet. The page moves over by the tab’s width, as it does on the shop, so the tab never covers a product, a quantity button or the checkout bar. Laptops keep the round button. Nothing reaches the shop until you press Save.</p>'
        + '<div class="wab-bar" role="group" aria-label="Language">'
        + '<button type="button" class="wab-btn" data-wab-lang="en" aria-pressed="' + (lang === 'en') + '">English</button>'
        + '<button type="button" class="wab-btn" data-wab-lang="ar" aria-pressed="' + (lang === 'ar') + '">العربية</button>'
        + '</div>' + tabStageHTML() + '</div>';
    }
    var note = stageNote();
    var b = note ? null : build();
    var custom = String(values.link || '').trim();
    var rtlOff = lang === 'ar' && !(preview || {}).rtl;
    return '<div class="wab-card wab-prev"><div class="wab-title">Live preview</div>'
      + '<p class="wab-sub">The same design and stylesheet the shop gets, moving as it will. Nothing reaches the shop until you press Save.</p>'
      + '<div class="wab-bar" role="group" aria-label="Device">'
      + '<button type="button" class="wab-btn" data-wab-dev="m" aria-pressed="' + (dev === 'm') + '">Phone</button>'
      + '<button type="button" class="wab-btn" data-wab-dev="d" aria-pressed="' + (dev === 'd') + '">Desktop</button>'
      + '<span style="width:8px"></span>'
      + '<button type="button" class="wab-btn" data-wab-lang="en" aria-pressed="' + (lang === 'en') + '">English</button>'
      + '<button type="button" class="wab-btn" data-wab-lang="ar" aria-pressed="' + (lang === 'ar') + '">العربية</button>'
      + (values.bubble === 'once' ? '<button type="button" class="wab-btn" data-wab-rebubble>Show the bubble again</button>' : '')
      + '</div>'
      + '<div class="wab-stage" data-wab-stage data-wab-dev-stage="' + dev + '" data-wab-dev="' + dev + '"' + (lang === 'ar' ? ' dir="rtl" lang="ar"' : '') + '>'
      + '<div class="wab-fake"><i></i><i></i><i></i><i></i><i></i><i></i></div>'
      + (b ? b.html : '<p class="wab-off">' + esc(note) + '</p>')
      + '</div>'
      + (rtlOff ? '<p class="wab-help" style="margin-top:8px">The mirrored (right-to-left) Arabic layout is switched off in Translation → Language settings, so the Arabic shop keeps the English side.</p>' : '')
      + (b ? '<div class="wab-out">' + (custom !== '' && safeLink(custom)
          ? 'Tapping the button opens your own link; nothing is added to it:'
          : 'Tapping the button opens WhatsApp to ' + esc((preview || {}).phone || 'the shop') + ' with this message already typed:<pre>' + esc(message(b.lines) || '(no message — both lines are empty)') + '</pre>')
          + '<div style="margin-top:6px">Opens: <code>' + esc(b.href) + '</code></div></div>' : '')
      + '</div>';
  }

  function drawStage() {
    var host = document.querySelector('[data-wab-preview]');
    if (host) host.innerHTML = previewHTML();
  }

  /* ── THE CONTROLS ─────────────────────────────────────────────────────── */

  function isOffset(k) { return /^[md]_(top|right|bottom|left)$/.test(k); }

  /* The side tab's two own colours: a picker and the hex box, side by side. */
  function isColour(k) { return /^tab_c[12]$/.test(k); }

  function help(f) { return f.help ? '<p class="wab-help">' + esc(f.help) + '</p>' : ''; }

  function fieldHTML(f) {
    var id = 'wab-' + f.key;
    var cls = 'wab-f' + (bad[f.key] ? ' is-bad' : '');
    var rtl = /_ar$/.test(f.key) ? ' dir="rtl" lang="ar"' : '';

    if (f.type === 'bool') {
      return '<div class="' + cls + '"><div class="wab-check">'
        + '<input type="checkbox" id="' + id + '" data-wab-key="' + esc(f.key) + '"' + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '" class="wab-lbl">' + esc(f.label) + '</label>' + help(f) + '</div></div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '') + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="' + cls + '"><div class="wab-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + id + '" data-wab-key="' + esc(f.key) + '">' + opts + '</select>' + help(f) + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="' + cls + '"><div class="wab-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="wab-val" data-wab-val="' + esc(f.key) + '">' + esc(values[f.key]) + esc(o.unit || '') + '</span></div>'
        + '<input type="range" id="' + id + '" data-wab-key="' + esc(f.key) + '" min="' + esc(o.min) + '" max="' + esc(o.max) + '" step="' + esc(o.step) + '" value="' + esc(values[f.key]) + '">'
        + help(f) + '</div>';
    }

    if (f.type === 'textarea') {
      return '<div class="' + cls + '"><div class="wab-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<textarea id="' + id + '" data-wab-key="' + esc(f.key) + '"' + rtl + ' maxlength="200">' + esc(values[f.key]) + '</textarea>' + help(f) + '</div>';
    }

    if (isColour(f.key)) {
      var hex = String(values[f.key] || '');
      return '<div class="' + cls.replace(' is-bad', '') + (bad[f.key] || !light(hex) ? ' is-bad' : '') + '"><div class="wab-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<div class="wab-pair"><input type="color" data-wab-key="' + esc(f.key) + '" value="' + esc(/^#[0-9a-f]{6}$/i.test(hex) ? hex.toLowerCase() : '#ffffff') + '" aria-label="' + esc(f.label) + '">'
        + '<input type="text" id="' + id + '" data-wab-key="' + esc(f.key) + '" value="' + esc(hex) + '" maxlength="7" autocomplete="off" spellcheck="false"></div>'
        + help(f) + '</div>';
    }

    var ph = '';
    if (f.key === 'link') ph = ' placeholder="Empty = our WhatsApp, ' + esc((preview || {}).phone || '') + '" inputmode="url"';
    if (/_ar$/.test(f.key)) {
      var std = ((preview || {}).standard || {}).ar || {};
      ph = ' placeholder="' + esc(std[f.key.replace(/_ar$/, '')] || '') + '"';
    }
    return '<div class="' + cls + '"><div class="wab-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-wab-key="' + esc(f.key) + '" value="' + esc(values[f.key]) + '" autocomplete="off"' + ph + rtl + ' maxlength="' + (f.key === 'link' ? 500 : 200) + '">'
      + help(f) + '</div>';
  }

  /* The eight offset boxes, as two rows of four — phone, then desktop — with
     "auto" as the placeholder, because empty IS auto. */
  function offsetsHTML(fields) {
    return ['m', 'd'].map(function (d) {
      return '<div class="wab-dev"><div class="wab-lbl">' + (d === 'm' ? 'Phone (900px and narrower)' : 'Desktop (wider than 900px)') + ' · pixels from each edge</div>'
        + '<div class="wab-four">' + SIDES.map(function (s) {
          var key = d + '_' + s;
          var f = fields.filter(function (x) { return x.key === key; })[0];
          if (!f) return '';
          return '<label class="' + (bad[key] ? 'is-bad' : '') + '">' + esc(s.charAt(0).toUpperCase() + s.slice(1))
            + '<input type="text" inputmode="numeric" maxlength="6" placeholder="auto" data-wab-key="' + esc(key) + '" value="' + esc(values[key]) + '" aria-label="' + esc(f.label) + '"></label>';
        }).join('') + '</div></div>';
    }).join('');
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'WhatsApp button') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="wab-wrap"><div class="wab-card"><div class="wab-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="wab-wrap"><div class="wab-card"><div class="wab-title">WhatsApp button</div>'
        + '<p class="wab-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="wab-actions"><button type="button" class="wab-btn" data-wab-reload>Retry</button></div></div></div>';
      return;
    }

    if (preview && preview.css && !cssInjected) {
      /* Once per page: the shop's own stylesheet for all seven designs. The
         rules are all prefixed .kbw, so nothing else on the console matches. */
      var st = document.createElement('style');
      st.id = 'wab-shop-css';
      st.textContent = preview.css;
      document.head.appendChild(st);
      cssInjected = true;
    }

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];
    var strip = tabs.map(function (t) {
      return '<button type="button" class="wab-tab" data-wab-tab="' + esc(t.key) + '" aria-selected="' + (t.key === current.key) + '">' + esc(t.label) + '</button>';
    }).join('');

    var offs = current.fields.filter(function (f) { return isOffset(f.key); });
    /* Women, men and the orbit speed only mean something on design G, so they
       are not drawn for the other six -- as on the preview page he chose from. */
    var rest = current.fields.filter(function (f) {
      return !isOffset(f.key) && (values.design === 'G' || ['women', 'men', 'speed'].indexOf(f.key) === -1)
        && (values.tab_palette === 'custom' || !isColour(f.key));
    });

    host.innerHTML = '<div class="wab-wrap">'
      + '<div data-wab-preview></div>'
      + '<div class="wab-card">'
      + (banner ? '<div class="wab-note" style="margin-bottom:12px">' + esc(banner) + '</div>' : '')
      + '<div class="wab-tabs" role="tablist">' + strip + '</div>'
      + '<p class="wab-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="wab-fields">' + (offs.length ? offsetsHTML(offs) : '') + rest.map(fieldHTML).join('') + '</div>'
      + '<div class="wab-actions">'
      + '<button type="button" class="wab-btn is-primary" data-wab-save' + (busy ? ' disabled' : '') + '>' + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button type="button" class="wab-btn" data-wab-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      + '<button type="button" class="wab-btn" data-wab-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
      + '</div>'
      + '<p class="wab-help" style="margin-top:8px">“Back to defaults” moves only the controls on <b>this tab</b>. Nothing is stored until you press Save.</p>'
      + '</div></div>';

    drawStage();
  }

  document.addEventListener('input', function (e) {
    var el = e.target.closest ? e.target.closest('[data-wab-key]') : null;
    if (!el) return;
    var key = el.dataset.wabKey;
    values[key] = el.type === 'checkbox' ? el.checked : el.value;

    if (key === 'tab_size' || key === 'tab_y') {
      /* The tab's drag bars move custom properties on the drawn tab, as the
         size bar does for the button: no rebuild, no request. */
      var tv = document.querySelector('[data-wab-val="' + key + '"]');
      if (tv) tv.textContent = el.value + (key === 'tab_y' ? '%' : 'px');
      var tabEl = document.querySelector('[data-wab-tabel]');
      var stage = document.querySelector('[data-wab-stage]');
      if (tabEl && stage) {
        if (key === 'tab_size') { tabEl.style.setProperty('--q', String(tabQ())); stage.style.setProperty('--kbtw', tabSize() + 'px'); }
        else tabEl.style.setProperty('--y', String(Math.max(0, Math.min(100, Math.round(Number(el.value)) || 0))));
        return;
      }
    }
    if (isColour(key)) {
      /* Picker and box mirror each other; the field outline says "too dark". */
      document.querySelectorAll('[data-wab-key="' + key + '"]').forEach(function (x) {
        if (x !== el) x.value = x.type === 'color' ? (/^#[0-9a-f]{6}$/i.test(el.value) ? el.value.toLowerCase() : x.value) : el.value.toUpperCase();
      });
      var wrap = el.closest('.wab-f');
      if (wrap) wrap.classList.toggle('is-bad', !light(el.value));
    }
    if (key === 'size') {
      /* The drag bar moves ONE custom property; the stage is not rebuilt, so
         the orbit keeps turning smoothly under the pointer. */
      var out = document.querySelector('[data-wab-val="size"]');
      if (out) out.textContent = el.value + 'px';
      var btn = document.querySelector('[data-wab-btn]');
      if (btn) { btn.style.setProperty('--k', String(Math.round(Number(el.value) / 60 * 10000) / 10000)); return; }
    }
    drawStage();
  });

  document.addEventListener('change', function (e) {
    var el = e.target.closest ? e.target.closest('[data-wab-key]') : null;
    if (!el) return;
    values[el.dataset.wabKey] = el.type === 'checkbox' ? el.checked : el.value;
    if (el.dataset.wabKey === 'design' || el.dataset.wabKey === 'tab_palette') { render(); return; }
    drawStage();
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;

    var t = e.target.closest('[data-wab-tab]');
    if (t) { open = t.dataset.wabTab; render(); return; }

    var d = e.target.closest('button[data-wab-dev]');
    if (d) { dev = d.dataset.wabDev === 'd' ? 'd' : 'm'; drawStage(); return; }

    var lg = e.target.closest('[data-wab-lang]');
    if (lg) { lang = lg.dataset.wabLang === 'ar' ? 'ar' : 'en'; drawStage(); return; }

    if (e.target.closest('[data-wab-close]')) { bubbleClosed = true; drawStage(); return; }
    if (e.target.closest('[data-wab-rebubble]')) { bubbleClosed = false; drawStage(); return; }

    /* The preview's own link must not leave the console. */
    if (e.target.closest('[data-wab-stage] .kbw-a')) { e.preventDefault(); say('On the shop this opens WhatsApp in a new tab.'); return; }

    if (e.target.closest('[data-wab-save]')) { save(); return; }
    if (e.target.closest('[data-wab-reload]')) { load(); return; }
    if (e.target.closest('[data-wab-defaults]')) {
      var current = tabs && (tabs.filter(function (x) { return x.key === open; })[0] || tabs[0]);
      if (!current) return;
      current.fields.forEach(function (f) { values[f.key] = f['default']; });
      render();
      say(current.label + ' is back to its shipped values. Nothing is saved until you press Save.');
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
