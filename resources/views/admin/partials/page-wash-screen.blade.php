{{--
    Appearance → Page background.                                      Lane BG

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry and toast(). It registers its own
    sidebar entry and wraps window.go, exactly as the screens beside it do, so
    that one include is the whole of the change to that file.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    "ALSO i need the whole site background like this multi colors and color
     changing time to time, but keep it light same as in screenshot, i mean the
     whole background. preview me first how it will look on our site, i need the
     live preview please."

    THE PREVIEW IS THE DELIVERABLE AND THE SAVE IS NOT. Nothing on the shop
    changes until he switches the wash on, and the Preview tab exists so that he
    can decide without switching anything on. So this screen opens on Preview,
    not on the sliders.

    ── THE PREVIEW IS THE REAL SHOP, IN A FRAME ─────────────────────────────

    Five real storefront addresses — the home page, /shop/, a real product, the
    cart and the journal — loaded in an iframe with `?kbbwash=a|b|c|d` on them.
    Not a mock-up and not a rebuild: the two things this wash can actually break
    are a white card going translucent and text losing its background, and both
    of those live on the real pages, in other lanes' stylesheets, behind the
    real catalogue. A rebuilt preview would be the one thing a preview must
    never be — different from the page.

    The product address comes from the endpoint, which reads a real visible slug
    out of the catalogue. A frame pointed at /product/demo/ would be showing him
    a 404 and calling it his shop.

    SAME-ORIGIN FRAMES ARE AN ESTABLISHED PATTERN HERE. SecurityHeaders sends
    `X-Frame-Options: SAMEORIGIN` and the CSP sends `frame-ancestors 'self'`,
    with the comment "admin uses same-origin iframes" against the first of them.

    THE PARAMETER IS INERT FOR EVERYBODY ELSE. PageWash::previewTreatment()
    checks the value against its own constants and then requires an ADMIN
    SESSION, so a signed-out visitor to extrabeauty.ae/?kbbwash=b gets the shop
    exactly as it is today and no style block at all.

    ── THE FOUR TREATMENTS ARE DATA, NOT COPY ───────────────────────────────

    The four cards, the numbers under them and the `?kbbwash=` values all come
    from the `treatments` key of the endpoint, which is PageWash::TREATMENTS. A
    treatment changed in the service cannot fall out of step with the button
    that applies it or the frame that shows it.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included
    — with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto. The frame
    scales its contents with a transform rather than being allowed to set its
    own width.

    EVERY CLASS IS PREFIXED pwb- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks: app.blade.php binds delegated
    listeners to `document` itself, each claiming a bare attribute name, and a
    click on any element carrying one is handled by that listener whichever
    screen it belongs to.
--}}
@verbatim
<style>
.pwb-wrap{display:grid;gap:14px;min-width:0}
.pwb-wrap > *{min-width:0}
.pwb-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.pwb-title{font-weight:650;font-size:15px}
.pwb-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.pwb-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.pwb-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.pwb-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.pwb-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.pwb-f{display:grid;gap:5px;min-width:0}
.pwb-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.pwb-fh label{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.pwb-val{font-size:11.5px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap;
         font-variant-numeric:tabular-nums}
.pwb-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.pwb-f input[type=range]{width:100%;accent-color:var(--accent,#15a85a);margin:0;min-width:0}
.pwb-f select,.pwb-f input[type=text]{width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.pwb-colour{display:flex;gap:8px;align-items:center;min-width:0}
.pwb-colour input[type=color]{flex:none;width:44px;height:36px;padding:0;border:1px solid var(--border,#e6e6e6);
  border-radius:9px;background:transparent}
.pwb-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.pwb-check input{margin-top:3px;flex:none;width:16px;height:16px}
.pwb-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.pwb-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.pwb-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.pwb-btn[disabled]{opacity:.45;cursor:default}
.pwb-btn[aria-pressed="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.pwb-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.pwb-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}

/* The four treatment cards. `auto-fill` rather than a breakpoint ladder, for
   the reason kbb.css gives about the storefront grid: the count comes from the
   row this actually has, which inside the console is narrower than the window
   by a sidebar nobody here can measure. */
.pwb-grid{display:grid;gap:12px;margin-top:12px;min-width:0;
  grid-template-columns:repeat(auto-fill,minmax(min(100%,210px),1fr))}
.pwb-t{border:1px solid var(--border,#e6e6e6);border-radius:11px;padding:11px;min-width:0;display:grid;gap:8px}
.pwb-t.is-on{border-color:var(--accent,#15a85a)}
.pwb-t h4{font:650 13.5px/1.25 inherit;margin:0}
.pwb-t p{font-size:11.5px;line-height:1.5;color:var(--ink-soft,#6b7280);margin:0}
.pwb-strip{display:grid;grid-template-columns:1fr 1fr 1fr;gap:3px;min-width:0}
.pwb-strip i{display:block;height:42px;border-radius:5px;border:1px solid rgba(0,0,0,.07)}
.pwb-meta{font-size:11px;color:var(--ink-soft,#6b7280);font-variant-numeric:tabular-nums}

/* The frame. A storefront page is 1280 wide and this column is not, so it is
   rendered at its real width and SCALED DOWN with a transform -- which is the
   only honest way to show a desktop page in a narrow box. Scaling the iframe
   element instead would reflow the page to the box's width and show a layout
   the shopper never sees. */
.pwb-stage{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:hidden;min-width:0;
  background:#fff;position:relative}
.pwb-stage iframe{border:0;display:block;transform-origin:0 0;background:#fff}
.pwb-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:10px;min-width:0}
.pwb-bar .pwb-btn{padding:6px 10px;font-size:12px}
.pwb-open{font-size:11.5px;color:var(--ink-soft,#6b7280)}

.pwb-t2{border-collapse:collapse;font-size:12px;font-variant-numeric:tabular-nums;min-width:100%}
.pwb-t2 th,.pwb-t2 td{padding:5px 9px;text-align:end;white-space:nowrap;
  border-bottom:1px solid var(--border,#e6e6e6)}
.pwb-t2 th:first-child,.pwb-t2 td:first-child{text-align:start}
.pwb-t2 thead th{font-weight:650;font-size:11px;color:var(--ink-soft,#6b7280);text-transform:uppercase;
  letter-spacing:.04em}
.pwb-scroll{overflow-x:auto;min-width:0;-webkit-overflow-scrolling:touch}
.pwb-low{color:#b4443c}
.pwb-cap{font-size:11.5px;color:var(--ink-soft,#6b7280);margin:9px 0 0;line-height:1.5;max-width:68ch}
.pwb-css{margin-top:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;
  line-height:1.6;background:rgba(127,127,127,.08);border-radius:9px;padding:10px 11px;
  overflow-wrap:anywhere;min-width:0}
@media (max-width:640px){ .pwb-card{padding:13px} .pwb-t2 th,.pwb-t2 td{padding:5px 7px} }
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'pagewash';

  var tabs = null, values = {}, open = 'preview', banner = null, busy = false, seq = 0;
  var treatments = {}, palettes = {}, pages = [], emittedCss = '', isDefault = true;
  var contrast = {}, contrastToday = {}, previewParam = 'kbbwash';
  var shownTreatment = 'a', shownPage = 0, frameWidth = 1280;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* The shop's own root, derived the same way api() derives the admin-api root:
     this console's path less its last segment. Never a hard-coded '/', because
     KBB_BASE_PATH prefixes every route on a host that sets it. */
  function shopRoot() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var r = await fetch(shopRoot() + '/admin-api' + path, opts);
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
      ? 'The Page background endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Page background',
      icon: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14c4-3 7 1 10-1s5-2 8 0"/>',
      group: 'Appearance',
      /* The ids as app.blade.php's own nav list spells them. */
      after: ['dividers', 'prodstyles', 'homepage', 'layout']
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
    if (title) title.textContent = 'Page background';

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
      var body = await api('/page-wash');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      treatments = body.treatments || {};
      palettes = body.palettes || {};
      pages = body.preview_pages || [];
      emittedCss = body.css || '';
      isDefault = body.is_default !== false;
      contrast = body.contrast || {};
      contrastToday = body.contrast_today || {};
      previewParam = body.preview_param || 'kbbwash';
      values = {};
      tabs.forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (open !== 'preview' && !tabs.some(function (t) { return t.key === open; })) open = 'preview';
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Page background settings could not be read.');
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
      var body = await api('/page-wash', { settings: payload });
      emittedCss = body.css || '';
      isDefault = body.is_default !== false;
      say(values.on ? 'Page background saved, and it is ON.' : 'Page background saved. The wash is off, so the shop is unchanged.');
      await load();
      return;
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
    var id = 'pwb-' + f.key;
    var help = f.help ? '<p class="pwb-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="pwb-f"><div class="pwb-check">'
        + '<input type="checkbox" id="' + id + '" data-pwb-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '')
          + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="pwb-f"><div class="pwb-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + id + '" data-pwb-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="pwb-f"><div class="pwb-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="pwb-val" data-pwb-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
        + '<input type="range" id="' + id + '" data-pwb-key="' + esc(f.key) + '"'
        + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
        + help + '</div>';
    }

    if (f.type === 'colour') {
      /* Two controls, ONE value. The swatch is the ordinary way to pick and the
         text box is the way to paste a brand hex, which is what an owner
         actually has. Both carry the same data key, so the delegated listener
         below does not care which one moved. */
      return '<div class="pwb-f"><div class="pwb-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<div class="pwb-colour">'
        + '<input type="color" id="' + id + '" data-pwb-key="' + esc(f.key) + '" value="' + esc(values[f.key]) + '">'
        + '<input type="text" data-pwb-key="' + esc(f.key) + '" value="' + esc(values[f.key]) + '"'
        + ' autocomplete="off" spellcheck="false" maxlength="7">'
        + '</div>' + help + '</div>';
    }

    return '<div class="pwb-f"><div class="pwb-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-pwb-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '" autocomplete="off">' + help + '</div>';
  }

  /* ── THE PREVIEW ──────────────────────────────────────────────────────── */

  function previewUrl(key, index) {
    var p = pages[index] || pages[0];
    if (!p) return '';
    var sep = p.path.indexOf('?') === -1 ? '?' : '&';
    return shopRoot() + p.path + sep + previewParam + '=' + encodeURIComponent(key);
  }

  function treatmentCards() {
    return Object.keys(treatments).map(function (k) {
      var t = treatments[k];
      var mins = Math.round(t.cycle / 60 * 10) / 10;
      return '<div class="pwb-t' + (k === shownTreatment ? ' is-on' : '') + '">'
        + '<h4>' + esc(k.toUpperCase()) + ' · ' + esc(t.name) + '</h4>'
        + '<div class="pwb-strip" data-pwb-swatch="' + esc(k) + '"><i></i><i></i><i></i></div>'
        + '<p class="pwb-meta">' + esc(String(mins)) + ' min a cycle · travel ' + esc(String(t.drift))
        + '% · ' + (t.spread === 'top' ? 'behind the header' : 'the whole page') + '</p>'
        + '<div class="pwb-bar" style="margin-top:0">'
        + '<button type="button" class="pwb-btn" data-pwb-show="' + esc(k) + '"'
        + ' aria-pressed="' + (k === shownTreatment ? 'true' : 'false') + '">Show</button>'
        + '<button type="button" class="pwb-btn" data-pwb-use="' + esc(k) + '">Use this</button>'
        + '</div></div>';
    }).join('');
  }

  function stageHTML() {
    var url = previewUrl(shownTreatment, shownPage);
    if (!url) return '<div class="pwb-empty">No storefront page to frame.</div>';

    /* The frame is rendered at `frameWidth` and scaled to whatever room this
       column has. The height is a proportion of the width, so the shape of the
       box does not change when the console gets narrower. */
    var h = Math.round(frameWidth * (frameWidth <= 480 ? 1.9 : 0.62));
    return '<div class="pwb-stage" data-pwb-stage style="height:0">'
      + '<iframe data-pwb-frame title="Storefront preview" src="' + esc(url) + '"'
      + ' width="' + frameWidth + '" height="' + h + '" loading="lazy"></iframe></div>';
  }

  /* Scale the frame to the room the card actually has.
     THIS IS NOT "JAVASCRIPT THAT MEASURES LAYOUT" IN THE SENSE RULE 4 FORBIDS.
     That rule is about the SHOP: the storefront must size with calc() and never
     read back an element's box. Nothing in the storefront's own wash does. This
     is the admin console scaling a fixed-width frame of a page into a variable
     box, which cannot be expressed in CSS without knowing the box -- and it
     touches nothing the shopper ever loads. */
  function fitFrame() {
    var stage = document.querySelector('[data-pwb-stage]');
    var frame = document.querySelector('[data-pwb-frame]');
    if (!stage || !frame) return;
    var room = stage.clientWidth || frameWidth;
    var scale = Math.min(1, room / frameWidth);
    frame.style.transform = 'scale(' + scale + ')';
    stage.style.height = Math.round(frame.height * scale) + 'px';
  }

  function contrastHTML() {
    var labels = {
      '--ink': 'Body text',
      '--ink-2': 'Secondary text',
      '--muted': 'Muted text',
      '--pink': 'Pink accent',
      '--pink-deep': 'Deep pink'
    };
    var rows = Object.keys(labels).map(function (k) {
      var now = Number(contrastToday[k] || 0), mine = Number(contrast[k] || 0);
      return '<tr><td>' + esc(labels[k]) + '</td>'
        + '<td>' + now.toFixed(2) + '</td>'
        + '<td class="' + (mine + 0.005 < now ? 'pwb-low' : '') + '">' + mine.toFixed(2) + '</td></tr>';
    }).join('');

    return '<div class="pwb-card"><div class="pwb-title">Contrast, at the worst moment of the cycle</div>'
      + '<p class="pwb-sub">Measured against the <b>darkest</b> of the nine colours these settings can '
      + 'produce, so it is the worst case and not a sample. The four palettes above are all held at or '
      + 'above the background your shop already renders, so none of them can cost you contrast; three '
      + 'of your own colours can, and this is where that shows.</p>'
      + '<div class="pwb-scroll" style="margin-top:12px"><table class="pwb-t2"><thead><tr>'
      + '<th>Text</th><th>Today</th><th>With these settings</th></tr></thead><tbody>' + rows
      + '</tbody></table></div>'
      + '<p class="pwb-cap"><b>Muted text and the pink accent are already below 4.5 on this shop</b>, '
      + 'against the background it renders today. That is not something this screen introduced and not '
      + 'something it can fix — it is the brand colour against a pale page — but it is worth knowing '
      + 'before choosing a palette of your own.</p></div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Page background') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="pwb-wrap"><div class="pwb-card"><div class="pwb-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="pwb-wrap"><div class="pwb-card">'
        + '<div class="pwb-title">Page background</div>'
        + '<p class="pwb-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="pwb-actions"><button class="pwb-btn" data-pwb-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = '<button type="button" class="pwb-tab" data-pwb-tab="preview"'
      + ' aria-selected="' + (open === 'preview' ? 'true' : 'false') + '">Preview</button>'
      + tabs.map(function (t) {
          return '<button type="button" class="pwb-tab" data-pwb-tab="' + esc(t.key) + '"'
            + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
        }).join('');

    var body;

    if (open === 'preview') {
      body = '<div class="pwb-card">'
        + '<div class="pwb-tabs">' + strip + '</div>'
        + '<p class="pwb-sub" style="margin-top:12px">Four treatments, on your own pages, with your own '
        + 'products behind them. Nothing here is saved and nothing is switched on — this is what the shop '
        + '<i>would</i> look like. Only somebody signed in here can see it; a shopper who opens the same '
        + 'address gets the shop exactly as it is today.</p>'
        + '<div class="pwb-grid">' + treatmentCards() + '</div>'
        + '</div>'
        + '<div class="pwb-card">'
        + '<div class="pwb-title">' + esc((treatments[shownTreatment] || {}).name || '') + ', live</div>'
        + '<div class="pwb-bar">'
        + pages.map(function (p, i) {
            return '<button type="button" class="pwb-btn" data-pwb-page="' + i + '"'
              + ' aria-pressed="' + (i === shownPage ? 'true' : 'false') + '">' + esc(p.label) + '</button>';
          }).join('')
        + '</div>'
        + '<div class="pwb-bar">'
        + '<button type="button" class="pwb-btn" data-pwb-w="390" aria-pressed="' + (frameWidth === 390 ? 'true' : 'false') + '">Phone · 390</button>'
        + '<button type="button" class="pwb-btn" data-pwb-w="1280" aria-pressed="' + (frameWidth === 1280 ? 'true' : 'false') + '">Desktop · 1280</button>'
        + '<a class="pwb-btn" data-pwb-open href="' + esc(previewUrl(shownTreatment, shownPage)) + '" target="_blank" rel="noopener">Open in a new tab ↗</a>'
        + '</div>'
        + '<div style="margin-top:10px">' + stageHTML() + '</div>'
        + '<p class="pwb-cap">The colour moves over <b>minutes</b>, not seconds — that is what "time to '
        + 'time" means here — so leave this open for a while rather than waiting for something to happen. '
        + 'Somebody browsing with “reduce motion” switched on sees one still gradient and no animation at '
        + 'all.</p>'
        + '</div>'
        + contrastHTML();
    } else {
      var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];
      body = '<div class="pwb-card">'
        + '<div class="pwb-tabs">' + strip + '</div>'
        + '<p class="pwb-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
        + '<div class="pwb-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
        + '<div class="pwb-actions">'
        + '<button class="pwb-btn is-primary" data-pwb-save' + (busy ? ' disabled' : '') + '>'
        + (busy ? 'Saving…' : 'Save') + '</button>'
        + '<button class="pwb-btn" data-pwb-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
        + '<button class="pwb-btn" data-pwb-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
        + '</div>'
        + '<p class="pwb-help" style="margin-top:8px">“Back to defaults” moves only the controls on '
        + '<b>this tab</b>. Nothing is stored until you press Save.</p>'
        + '</div>'
        + '<div class="pwb-card"><div class="pwb-title">What the shop is sending</div>'
        + '<p class="pwb-sub">' + (isDefault || !values.on
            ? 'The wash is off, so every page of the shop carries <b>nothing at all</b> from this screen — '
              + 'not one byte. The background you see today is the one it keeps.'
            : 'Every storefront page carries this, and nothing else:') + '</p>'
        + (isDefault || !values.on ? '' : '<div class="pwb-css">' + esc(emittedCss) + '</div>')
        + '</div>'
        + contrastHTML();
    }

    host.innerHTML = '<div class="pwb-wrap">'
      + (banner ? '<div class="pwb-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + '<div class="pwb-note">Printed documents never carry any of this. The invoice, the packing slip, '
      + 'the delivery note and the shipping label are rendered from their own template, which loads no '
      + 'site stylesheet at all — and a storefront page sent to a printer drops the wash too.</div>'
      + body
      + '</div>';

    paintSwatches();
    fitFrame();
  }

  /* The three moments of each treatment, painted from the same numbers the
     storefront uses. The gradients are not restated here: the endpoint sends
     the palette and the two mixes are four lines of arithmetic, which is less
     than a second copy of nine gradient stops would be. */
  function paintSwatches() {
    Object.keys(treatments).forEach(function (k) {
      var host = document.querySelector('[data-pwb-swatch="' + k + '"]');
      if (!host) return;
      var moments = momentsFor(treatments[k]);
      var cells = host.querySelectorAll('i');
      for (var i = 0; i < cells.length && i < moments.length; i++) {
        var m = moments[i];
        cells[i].style.background = 'linear-gradient(160deg,' + rgb(m[0]) + ' 0%,' + rgb(m[1]) + ' 52%,' + rgb(m[2]) + ' 100%)';
      }
    });
  }

  function rgb(c) { return 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')'; }

  function hexRgb(h) {
    h = String(h || '').replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    return [parseInt(h.slice(0, 2), 16) || 0, parseInt(h.slice(2, 4), 16) || 0, parseInt(h.slice(4, 6), 16) || 0];
  }

  function momentsFor(t) {
    var p = palettes[t.palette] || [];
    if (p.length !== 3) return [];
    var c = p.map(hexRgb);
    var mean = [0, 1, 2].map(function (i) { return Math.round((c[0][i] + c[1][i] + c[2][i]) / 3); });
    var d = Math.max(0, Math.min(100, t.drift)) / 100;
    var w = (100 - Math.max(0, Math.min(100, t.intensity))) / 100;
    return [[0, 1, 2], [1, 2, 0], [2, 0, 1]].map(function (order) {
      return order.map(function (idx) {
        return [0, 1, 2].map(function (i) {
          var v = Math.round(mean[i] + (c[idx][i] - mean[i]) * d);
          return Math.round(v + (255 - v) * w);
        });
      });
    });
  }

  document.addEventListener('input', function (e) {
    var el = e.target.closest ? e.target.closest('[data-pwb-key]') : null;
    if (!el) return;

    var key = el.dataset.pwbKey;
    values[key] = el.type === 'checkbox' ? el.checked : el.value;

    var out = document.querySelector('[data-pwb-val="' + key + '"]');
    if (out && tabs) {
      var f = null;
      tabs.forEach(function (t) { t.fields.forEach(function (x) { if (x.key === key) f = x; }); });
      if (f) out.textContent = shown(f);
    }
    /* Keep the swatch and the text box for a colour in step without rebuilding
       the screen, which would take the control out from under the pointer. */
    document.querySelectorAll('[data-pwb-key="' + key + '"]').forEach(function (other) {
      if (other !== el && other.value !== el.value) other.value = el.value;
    });
  });

  document.addEventListener('change', function (e) {
    var el = e.target.closest ? e.target.closest('[data-pwb-key]') : null;
    if (!el) return;
    values[el.dataset.pwbKey] = el.type === 'checkbox' ? el.checked : el.value;
    if (el.type === 'checkbox' || el.tagName === 'SELECT') render();
  });

  window.addEventListener('resize', function () { fitFrame(); });

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;

    var tab = e.target.closest('[data-pwb-tab]');
    if (tab) { open = tab.dataset.pwbTab; render(); return; }

    var show = e.target.closest('[data-pwb-show]');
    if (show) { shownTreatment = show.dataset.pwbShow; render(); return; }

    var pg = e.target.closest('[data-pwb-page]');
    if (pg) { shownPage = Number(pg.dataset.pwbPage) || 0; render(); return; }

    var w = e.target.closest('[data-pwb-w]');
    if (w) { frameWidth = Number(w.dataset.pwbW) || 1280; render(); return; }

    var use = e.target.closest('[data-pwb-use]');
    if (use) {
      var t = treatments[use.dataset.pwbUse];
      if (!t) return;
      /* ONLY the six keys a treatment names, and NOT `on`. Choosing a look is
         not the same act as publishing it: the switch stays where the owner
         left it and Save is still a separate press. */
      ['palette', 'intensity', 'drift', 'cycle', 'spread'].forEach(function (k) {
        if (t[k] !== undefined) values[k] = t[k];
      });
      shownTreatment = use.dataset.pwbUse;
      open = 'colour';
      render();
      say(t.name + ' is in the controls. It is not saved, and the wash is still ' + (values.on ? 'on' : 'off') + '.');
      return;
    }

    if (e.target.closest('[data-pwb-save]')) { save(); return; }
    if (e.target.closest('[data-pwb-reload]')) { load(); return; }
    if (e.target.closest('[data-pwb-defaults]')) {
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
