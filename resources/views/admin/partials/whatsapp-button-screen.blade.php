{{--
    Appearance → WhatsApp button.                                      Lane WA

    Pulled into resources/views/admin/app.blade.php below the last screen
    partial, so this runs once the console's own script has defined window.go,
    window.kbbAddNavEntry and toast(). It registers its own sidebar entry and
    wraps window.go, as the screens beside it do; docs/WA-ADMIN-APP-BLOCKS.md
    carries the console edits that make the row exist at build time and arm the
    deep link.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    The floating WhatsApp button the owner chose from
    docs/whatsapp-float-preview/ — design G, "Orbit team" — and every control he
    asked for: seven designs, a size bar from 30 to 110px, the space from all
    four sides on phone and on desktop, his own link, the two lines the shopper
    sends, the capsule and the welcome bubble.

    ── THE PREVIEW IS THE SHOP'S OWN MARKUP AND CSS ─────────────────────────

    The endpoint sends WhatsAppButton::cssAll(), ICON and SYMBOLS — the same
    constants the storefront partial prints — and build() below writes the
    same markup partials/whatsapp-button.blade.php writes, class for class. So
    the preview animates exactly as the shop does, and it is built in the
    browser: dragging the size bar changes one custom property, and switching
    design or typing a line redraws the stage, without a request (CLAUDE.md,
    "super light"). WhatsAppButtonScreenTest pins that build() and the partial
    use the same class names.

    The stage sets BOTH the phone and the desktop custom properties to the
    device being previewed, because its media queries answer to the console's
    window, not to the stage's width.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Blade pairs the first such opening directive it finds anywhere in the file
    -- a comment included -- with the next closing one.

    EVERY CLASS IS PREFIXED wab- AND EVERY data- ATTRIBUTE data-wab-, because
    app.blade.php binds delegated listeners on `document` that claim bare
    attribute names. The storefront's own .kbw classes appear only inside the
    stage.
--}}
@verbatim
<style>
.wab-wrap{display:grid;gap:14px;min-width:0;grid-template-columns:minmax(0,1fr)}
.wab-wrap > *{min-width:0}
@media (min-width:1100px){.wab-wrap{grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);align-items:start}
  .wab-prev{position:sticky;top:12px}}
.wab-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}
.wab-title{font-weight:650;font-size:15px}
.wab-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin:4px 0 0;max-width:72ch}
.wab-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-width:0;margin-top:10px}
.wab-btn{padding:7px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:12.5px;cursor:pointer;max-width:100%}
.wab-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.wab-btn[aria-pressed="true"]{border-color:var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff;font-weight:650}
.wab-btn[disabled]{opacity:.45;cursor:default}
.wab-stage{position:relative;isolation:isolate;overflow:hidden;height:440px;margin:12px auto 0;border:1px solid var(--border,#e6e6e6);border-radius:12px;
  background:linear-gradient(180deg,#fff 0,#FFF6F8 100%);font-family:inherit;color:#2A2228;max-width:100%}
.wab-stage[data-wab-dev="m"]{width:390px}
.wab-stage[data-wab-dev="d"]{width:100%}
.wab-stage .kbw{position:absolute}
.wab-fake{position:absolute;inset:18px 18px auto 18px;display:grid;grid-template-columns:repeat(3,1fr);gap:10px;pointer-events:none}
.wab-fake i{display:block;height:110px;border-radius:10px;background:#F7EEF1}
.wab-fake i:nth-child(n+4){height:14px;border-radius:6px}
.wab-off{position:absolute;inset:auto 16px 16px 16px;font-size:12.5px;color:var(--ink-soft,#6b7280);text-align:center}
.wab-out{margin-top:10px;font-size:12px;line-height:1.5;color:var(--ink-soft,#6b7280);overflow-wrap:anywhere}
.wab-out pre{margin:4px 0 0;white-space:pre-wrap;font:inherit;font-size:12.5px;color:inherit;background:rgba(127,127,127,.08);border-radius:9px;padding:8px 10px}
.wab-out code{font-size:11.5px;word-break:break-all}
.wab-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.wab-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.wab-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.wab-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.wab-f{display:grid;gap:5px;min-width:0}
.wab-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.wab-fh label,.wab-lbl{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.wab-val{font-size:12px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap;font-variant-numeric:tabular-nums}
.wab-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:72ch}
.wab-f input[type=range]{width:100%;accent-color:var(--accent,#15a85a);margin:0;min-width:0}
.wab-f select,.wab-f input[type=text],.wab-f textarea{width:100%;min-width:0;box-sizing:border-box;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.wab-f textarea{min-height:62px;resize:vertical}
.wab-f [dir="rtl"]{text-align:right}
.wab-f.is-bad input,.wab-f.is-bad select,.wab-f.is-bad textarea{border-color:#b4443c}
.wab-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.wab-check input{margin-top:3px;flex:none;width:16px;height:16px}
.wab-dev{border:1px solid var(--border,#e6e6e6);border-radius:11px;padding:12px;display:grid;gap:8px;min-width:0}
.wab-four{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;min-width:0}
.wab-four label{display:grid;gap:4px;font-size:11.5px;color:var(--ink-soft,#6b7280);min-width:0}
.wab-four input{width:100%;min-width:0;box-sizing:border-box;padding:8px 9px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font-variant-numeric:tabular-nums}
.wab-four .is-bad input{border-color:#b4443c}
.wab-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;min-width:0}
.wab-note{border:1px solid #b4443c;color:#b4443c;border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.5}
.wab-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
/* Cart & checkout · phone: the side tab on a 390px phone cart. The shop's
   own TAB_CSS (in cssAll) draws the tab; only its column is pinned to the stage
   instead of the window, and the fake page moves over by --kbtw as #content
   does on the shop. */
.wab-stage .kbt-z{position:absolute;display:flex;top:64px;bottom:76px}
.wab-cart{position:absolute;inset:0 0 auto 0;padding:12px 14px 0 calc(var(--kbtw,26px) + 14px);display:grid;gap:8px;pointer-events:none}
.wab-cart i{display:grid;grid-template-columns:44px 1fr 46px;gap:10px;align-items:center;height:62px;padding:0 10px;border-radius:10px;background:#fff;border:1px solid #F1E3E8}
.wab-cart i b{height:44px;border-radius:8px;background:#F7EEF1}
.wab-cart i u,.wab-cart i s{display:block;height:10px;border-radius:5px;background:#EADDE2;text-decoration:none}
.wab-cart i u{box-shadow:0 18px 0 -2px #F3E9EC}
.wab-cart em{display:block;height:20px;width:44%;border-radius:6px;background:#EADDE2;margin-bottom:2px}
.wab-dock{position:absolute;inset:auto 0 0 0;height:64px;background:#fff;border-top:1px solid #F1E3E8;display:flex;align-items:center;justify-content:flex-end;padding:0 14px}
.wab-dock span{width:150px;height:40px;border-radius:12px;background:#3E9B6B}
.wab-pair{display:grid;grid-template-columns:44px minmax(0,1fr);gap:8px;align-items:center}
.wab-pair input[type=color]{width:44px;height:36px;padding:2px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent}
@media (max-width:640px){.wab-card{padding:13px}.wab-four{grid-template-columns:repeat(2,minmax(0,1fr))}.wab-stage{height:400px}}
</style>

<script>
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
      : !values.show_cart && !values.show_checkout ? 'Off on the cart and checkout (Design → Show on the cart page, Show on checkout): those two pages print no WhatsApp button and no tab at all.'
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
</script>
@endverbatim
