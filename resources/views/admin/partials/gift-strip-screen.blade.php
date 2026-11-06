{{--
    Appearance → Free gifts strip.                                     Lane GST

    Pulled into resources/views/admin/app.blade.php below the last screen
    partial, so this runs once the console's own script has defined window.go,
    window.kbbAddNavEntry and toast(). It registers its own sidebar row (under
    Homepage content) and wraps window.go, as the screens beside it do.

    THE OWNER, 6 November 2026: "proceed with the development, allow us to
    choose the designs from the list along with preview, and need all controls
    to edit those sections."

    THE PREVIEW IS THE SHOP'S OWN CSS AND MARKUP. GET /admin-api/gift-strip
    sends App\Support\GiftStrip::BASE_CSS, each design's STYLE_CSS, the gift
    icon and every product with its cut-out, and doc() below writes the markup
    partials/home/gift-strip.blade.php writes, class for class. Each frame is
    an iframe so the phone rules (max-width:767px) answer to a 390px window.
    Changing a control redraws the frames in the browser -- no request per
    keystroke; the redraw waits for a 150ms pause and the timer ends with it.

    Sized in CSS alone: the computer frame is a 1280px document scaled by a
    custom property set per console width in media queries. Nothing here
    measures layout.

    "PREPARE CUT-OUTS" is one POST per product, one after another, with
    "3 of 6" progress, and it stops when the list is done or he leaves the
    screen. No polling, no timer.

    LATE ANSWERS. Every async answer checks ours() before it paints: the
    console has one #content, and an answer arriving after he moved on must
    not repaint this screen over another (the Marketing Emails lesson).

    Every string drawn goes through esc(). Classes are gsa-, data attributes
    data-gsa-, because app.blade.php binds delegated listeners on document.

    NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES.
--}}
@verbatim
<style>
.gsa{display:grid;gap:14px;min-width:0;--s:.26;--m:.22}
.gsa *{box-sizing:border-box}
@media (min-width:700px){.gsa{--s:.45}}
@media (min-width:1000px){.gsa{--s:.5}}
@media (min-width:1300px){.gsa{--s:.7}}
@media (min-width:1600px){.gsa{--s:.85}}
.gsa-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}
.gsa-title{font-weight:650;font-size:15px;margin:0}
.gsa-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin:4px 0 0;max-width:80ch}
.gsa-note{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.5;background:rgba(127,127,127,.05);margin-top:10px}
.gsa-note.is-bad{border-color:#e3b3ae;background:#fdf1f0;color:#7a2a22}
.gsa-note.is-ok{border-color:#b7e1c8;background:#f0faf4}
.gsa-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;min-width:0;margin-top:10px}
.gsa-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:var(--surface,#fff);color:inherit;font:inherit;font-size:13px;font-weight:600;cursor:pointer;max-width:100%}
.gsa-btn.is-primary{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.gsa-btn.sm{padding:4px 9px;font-size:12px}
.gsa-btn[disabled]{opacity:.5;cursor:default}
.gsa-btn[aria-pressed="true"]{border-color:var(--accent,#15a85a);background:var(--accent,#15a85a);color:#fff}
.gsa-sw{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:10px}
.gsa-sw label{display:flex;gap:7px;align-items:center;font-size:13px;cursor:pointer}
.gsa-sw input{accent-color:var(--accent,#15a85a);width:16px;height:16px;margin:0}
.gsa-lbl{font-size:11.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-soft,#6b7280);margin:14px 0 6px}
.gsa-dt{height:calc(190px * var(--s));overflow:hidden;border:1px solid var(--border,#e6e6e6);border-radius:10px;background:#fff;position:relative}
.gsa-dt iframe{width:1280px;height:190px;border:0;display:block;transform:scale(var(--s));transform-origin:0 0}
.gsa-ph{width:390px;max-width:100%;height:136px;border:8px solid #1d1820;border-radius:22px;overflow:hidden;background:#fff;position:relative}
.gsa-ph iframe{width:374px;height:120px;border:0;display:block}
.gsa-hid{position:absolute;inset:0;display:grid;place-items:center;background:rgba(255,255,255,.75);font-size:12.5px;font-weight:650;color:#6b7280;pointer-events:none}
.gsa-prev{display:grid;gap:4px;min-width:0}
.gsa-lang{display:flex;gap:6px;margin-top:8px}
.gsa-styles{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));margin-top:10px}
.gsa-st{display:grid;gap:6px;padding:8px;border:2px solid var(--border,#e6e6e6);border-radius:12px;background:transparent;color:inherit;font:inherit;text-align:left;cursor:pointer;min-width:0}
.gsa-st[aria-checked="true"]{border-color:var(--accent,#15a85a);box-shadow:0 0 0 3px rgba(21,168,90,.15)}
.gsa-st b{font-size:13px}
.gsa-mini{height:calc(190px * var(--m));overflow:hidden;border-radius:8px;background:#fff;pointer-events:none}
.gsa-mini iframe{width:1280px;height:190px;border:0;display:block;transform:scale(var(--m));transform-origin:0 0}
.gsa-grid{display:grid;gap:10px 14px;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));margin-top:6px}
.gsa-f{display:grid;gap:4px;min-width:0}
.gsa-f label{font-size:12.5px;font-weight:650}
.gsa-f input[type=text],.gsa-f input[type=search],.gsa-f input[type=number]{width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.gsa-f [dir="rtl"]{text-align:right}
.gsa-f.is-bad input{border-color:#d9534f}
.gsa-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0}
.gsa-rows{display:grid;gap:8px;margin-top:10px;min-width:0}
.gsa-row{display:grid;grid-template-columns:52px minmax(0,1fr) auto;gap:10px;align-items:center;border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:8px;min-width:0}
.gsa-th{width:52px;height:52px;border-radius:8px;background:repeating-conic-gradient(#eee 0 25%,#fff 0 50%) 0 0/12px 12px;display:grid;place-items:center;overflow:hidden}
.gsa-th img{max-width:100%;max-height:100%;display:block}
.gsa-nm{font-size:13px;font-weight:600;overflow-wrap:anywhere}
.gsa-meta{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;margin-top:5px;font-size:12px}
.gsa-meta input[type=number]{width:72px;padding:4px 6px;font:inherit;font-size:12.5px;border:1px solid var(--border,#e6e6e6);border-radius:7px;background:transparent;color:inherit}
.gsa-meta label{display:flex;gap:5px;align-items:center}
.gsa-ok{color:#0b6e3a;font-weight:650}
.gsa-no{color:#a15c00;font-weight:650}
.gsa-acts{display:flex;flex-direction:column;gap:4px}
.gsa-res{display:grid;gap:4px;margin-top:6px;max-height:300px;overflow:auto}
.gsa-res button{display:flex;gap:8px;align-items:center;text-align:left;padding:6px;border:1px solid var(--border,#e6e6e6);border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:12.5px;cursor:pointer}
.gsa-res img{width:34px;height:34px;object-fit:contain;flex:none}
.gsa-save{position:sticky;bottom:0;z-index:5;display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:10px 14px}
.gsa-save span{font-size:12.5px;color:var(--ink-soft,#6b7280)}
@media (max-width:640px){.gsa-row{grid-template-columns:44px minmax(0,1fr)}.gsa-th{width:44px;height:44px}.gsa-acts{grid-column:1 / -1;flex-direction:row;flex-wrap:wrap}}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'giftstrip';
  var TITLE = 'Free gifts strip';

  var data = null, values = null, banner = null, busy = false, seq = 0, dirty = false;
  var lang = 'en', timer = 0, cutting = false, results = [], searchTimer = 0, searchSeq = 0;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }

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
    if (!r.ok) { var err = new Error('api ' + path + ' -> ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function say(msg) { try { window.toast(msg); } catch (e) {} }
  function explain(e, fallback) {
    if (e && e.status === 404) return 'The Free gifts strip endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change the Free gifts strip. An owner, manager or editor can.';
    return (e && e.body && (e.body.error || e.body.message)) ? (e.body.error || e.body.message) : fallback;
  }

  /* Still on show? An answer that lands after he moved on paints nothing. */
  function host() { return document.querySelector('#content'); }
  function ours() {
    var h = host();
    return !!h && (document.querySelector('#ptitle') || {}).textContent === TITLE && (h.children.length === 0 || !!h.querySelector('[data-gsa]'));
  }

  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Appearance → ' + TITLE,
    values: function () { return dirty ? values : null; },
    set: function (k, v) { if (values && Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); },
    save: function () { save(); }
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: TITLE,
      icon: '<path d="M20 12v9H4v-9"/><path d="M2 7h20v5H2z"/><path d="M12 21V7"/><path d="M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/>',
      group: 'Appearance',
      after: ['hpcontent', 'homepage']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = TITLE;
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
      var body = await api('/gift-strip');
      if (mine !== seq || !ours()) return;
      take(body);
      dirty = false;
    } catch (e) {
      if (mine !== seq || !ours()) return;
      banner = explain(e, 'Could not load the Free gifts strip.');
    }
    busy = false;
    render();
  }

  function take(body) {
    data = body;
    values = JSON.parse(JSON.stringify(body.values));
    if (!values.heights || Array.isArray(values.heights)) values.heights = {};
    [].concat(body.live || [], body.picked || []).forEach(function (p) {
      if (values.heights[p.id] == null) values.heights[p.id] = p.cm;
    });
  }

  /* ── The products the strip draws, as the shop picks them ──────────── */
  function rows() {
    if (!data) return [];
    if (values.source === 'pick') {
      var all = {};
      [].concat(data.live || [], data.picked || [], results || []).forEach(function (p) { all[p.id] = p; });
      return values.picks.map(function (id) { return all[id]; }).filter(Boolean);
    }
    return data.live || [];
  }
  function drawn() {
    return rows().filter(function (p) { return !!p.cut; }).slice(0, 6);
  }
  function cm(p) {
    var v = parseFloat(values.heights[p.id]);
    if (!isFinite(v)) v = p.cm;
    return Math.max(data.preview.h_min, Math.min(data.preview.h_max, Math.round(v * 10) / 10));
  }

  /* The shop's markup (partials/home/gift-strip.blade.php), class for class. */
  function markup(style, items, ar) {
    var P = data.preview, t = values.texts, k = ar ? '_ar' : '_en';
    var half = Math.ceil(items.length / 2);
    function side(cls, list) {
      return '<div class="kgs-side kgs-' + cls + '">' + list.map(function (p) {
        var c = cm(p), src = p.cut ? p.cut.src : p.thumb;
        var w = p.cut ? p.cut.w : 100, h = p.cut ? p.cut.h : 100;
        return '<div class="kgs-p" style="--h:' + c + '"><img src="' + esc(src) + '"' + (p.cut ? ' srcset="' + esc(p.cut.srcset) + '"' : '')
          + ' width="' + w + '" height="' + h + '" alt="' + esc(p.name) + '">' + (values.icon ? P.icon : '') + '</div>';
      }).join('') + '</div>';
    }
    return '<section class="kgs-sec" aria-label="' + esc(t['h' + k]) + '">' + (values.icon ? P.symbol : '')
      + '<div class="wrap"><div class="kgs' + (values.anim ? '' : ' kgs-still') + '"><div class="kgs-bg">'
      + (style === 'S5' ? '<div class="kgs-fx"></div><div class="kgs-shine"></div>' : '') + '</div>'
      + side('l', items.slice(0, half))
      + '<div class="kgs-mid"><h2 class="kgs-h">' + esc(t['h' + k]) + '</h2><span class="kgs-sub">' + esc(t['sub' + k]) + '</span>'
      + '<a class="kgs-link" href="#">' + esc(t['link' + k]) + '</a></div>'
      + side('r', items.slice(half)) + '</div></div></section>';
  }
  function doc(style, ar) {
    var P = data.preview, items = drawn();
    if (!items.length) items = rows().slice(0, 6);
    return '<!doctype html><html lang="' + (ar ? 'ar' : 'en') + '" dir="' + (ar ? 'rtl' : 'ltr') + '"><head><meta charset="utf-8">'
      + '<meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;background:#fff}'
      + 'body{font-family:"Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",sans-serif;color:#2a2228}'
      + '.wrap{max-width:1680px;margin:0 auto;padding:0 16px}a{pointer-events:none}'
      + P.base + (P.css[style] || '') + '</style></head><body>' + markup(style, items, ar) + '</body></html>';
  }

  function paintPreview() {
    timer = 0;
    var h = host();
    if (!data || !h || !ours()) return;
    var ar = lang === 'ar';
    h.querySelectorAll('iframe[data-gsa-frame]').forEach(function (f) {
      var st = f.getAttribute('data-gsa-frame');
      f.srcdoc = doc(st === 'main' ? values.style : st, ar);
    });
  }
  function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(paintPreview, 150);
  }

  /* ── Drawing ───────────────────────────────────────────────────────── */
  function sw(key, label) {
    return '<label><input type="checkbox" data-gsa-b="' + key + '"' + (values[key] ? ' checked' : '') + '> ' + esc(label) + '</label>';
  }
  function txt(key, label, ar) {
    var name = key + (ar ? '_ar' : '_en');
    return '<div class="gsa-f"><label for="gsa-' + name + '">' + esc(label) + (ar ? ' · Arabic' : ' · English') + '</label>'
      + '<input type="text" id="gsa-' + name + '" maxlength="80" data-gsa-t="' + name + '" value="' + esc(values.texts[name]) + '"' + (ar ? ' dir="rtl" lang="ar"' : '') + '></div>';
  }
  function rowHTML(p, pick, i, n) {
    var th = p.cut ? p.cut.src : p.thumb;
    return '<div class="gsa-row"><div class="gsa-th">' + (th ? '<img src="' + esc(th) + '" alt="" loading="lazy">' : '') + '</div>'
      + '<div style="min-width:0"><div class="gsa-nm">' + esc(p.name) + '</div><div class="gsa-meta">'
      + '<label>Real height <input type="number" min="' + data.preview.h_min + '" max="' + data.preview.h_max + '" step="0.5" data-gsa-h="' + p.id + '" value="' + esc(values.heights[p.id]) + '"> cm</label>'
      + '<label title="Tick for a white product, so its own white edge is kept when it is cut out"><input type="checkbox" data-gsa-w="' + p.id + '"' + (values.white.indexOf(p.id) > -1 ? ' checked' : '') + '> White product</label>'
      + (p.cut ? '<span class="gsa-ok">Cut-out ready</span>' : '<span class="gsa-no">Needs a cut-out — not shown until it has one</span>')
      + '</div></div><div class="gsa-acts">'
      + '<button type="button" class="gsa-btn sm" data-gsa-cut="' + p.id + '"' + (cutting ? ' disabled' : '') + '>' + (p.cut ? 'Re-cut' : 'Cut out') + '</button>'
      + (pick ? '<button type="button" class="gsa-btn sm" data-gsa-up="' + i + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="Move up">↑</button>'
        + '<button type="button" class="gsa-btn sm" data-gsa-down="' + i + '"' + (i === n - 1 ? ' disabled' : '') + ' aria-label="Move down">↓</button>'
        + '<button type="button" class="gsa-btn sm" data-gsa-rm="' + p.id + '">Remove</button>' : '')
      + '</div></div>';
  }

  function render() {
    var h = host();
    if (!h || (document.querySelector('#ptitle') || {}).textContent !== TITLE) return;
    if (busy && !data) { h.innerHTML = '<div class="gsa" data-gsa><div class="gsa-card">Loading…</div></div>'; return; }
    if (!data) {
      h.innerHTML = '<div class="gsa" data-gsa><div class="gsa-card"><p class="gsa-title">' + TITLE + '</p><p class="gsa-sub">' + esc(banner || 'Nothing to show yet.')
        + '</p><div class="gsa-bar"><button type="button" class="gsa-btn" data-gsa-reload>Retry</button></div></div></div>';
      return;
    }
    var P = data.preview, list = rows(), pick = values.source === 'pick', ready = list.filter(function (p) { return !!p.cut; }).length;
    var styles = Object.keys(P.styles).map(function (k) {
      return '<button type="button" class="gsa-st" role="radio" data-gsa-style="' + k + '" aria-checked="' + (values.style === k) + '"><b>' + esc(k + ' · ' + P.styles[k]) + '</b>'
        + '<div class="gsa-mini"><iframe data-gsa-frame="' + k + '" title="' + esc(P.styles[k]) + ' preview" tabindex="-1" loading="lazy"></iframe></div></button>';
    }).join('');
    var gifts = data.gifts_on === 'live' ? '' : '<div class="gsa-note is-bad">Free gifts are ' + (data.gifts_on === 'testing' ? 'in testing' : 'off')
      + ' (Growth &amp; Marketing → Free gifts), so the strip stays hidden from shoppers' + (data.gifts_on === 'testing' ? ' except the testing addresses' : '') + '. It promises gifts, so it only shows while they are given.</div>';

    h.innerHTML = '<div class="gsa" data-gsa>'
      + (banner ? '<div class="gsa-note">' + esc(banner) + '</div>' : '')
      + '<div class="gsa-card"><p class="gsa-title">Preview</p><p class="gsa-sub">The strip as the homepage draws it, right under the main banner. Products are at their real sizes against each other; only products with a cut-out are drawn on the shop.</p>'
      + '<div class="gsa-lang" role="group" aria-label="Preview language"><button type="button" class="gsa-btn sm" data-gsa-lang="en" aria-pressed="' + (lang === 'en') + '">English</button><button type="button" class="gsa-btn sm" data-gsa-lang="ar" aria-pressed="' + (lang === 'ar') + '">العربية</button></div>'
      + '<div class="gsa-prev"><div class="gsa-lbl">Computer</div><div class="gsa-dt"><iframe data-gsa-frame="main" title="Computer preview"></iframe>' + (values.desktop ? '' : '<div class="gsa-hid">Hidden on computer</div>') + '</div>'
      + '<div class="gsa-lbl">Phone</div><div class="gsa-ph"><iframe data-gsa-frame="main" title="Phone preview"></iframe>' + (values.mobile ? '' : '<div class="gsa-hid">Hidden on phone</div>') + '</div></div>'
      + (ready ? '' : '<div class="gsa-note is-bad">No product has a cut-out yet, so the preview shows the plain photos and the shop shows no strip. Press <b>Prepare cut-outs</b> below.</div>')
      + '</div>'
      + '<div class="gsa-card"><p class="gsa-title">Show</p>' + gifts
      + '<div class="gsa-sw">' + sw('on', 'Show the strip on the homepage') + sw('desktop', 'On computer') + sw('mobile', 'On phone') + sw('icon', 'Gift icon on each product') + sw('anim', 'Animation (floating products)') + '</div>'
      + '<p class="gsa-help" style="margin-top:8px">Shoppers who ask their phone for less motion always get a still strip.</p></div>'
      + '<div class="gsa-card"><p class="gsa-title">Design</p><p class="gsa-sub">Pick one. Each preview uses your products and words.</p><div class="gsa-styles" role="radiogroup" aria-label="Design">' + styles + '</div></div>'
      + '<div class="gsa-card"><p class="gsa-title">Words and link</p><div class="gsa-grid">'
      + txt('h', 'Heading', false) + txt('h', 'Heading', true) + txt('sub', 'Under the heading', false) + txt('sub', 'Under the heading', true)
      + txt('link', 'Link text', false) + txt('link', 'Link text', true) + '</div>'
      + '<div class="gsa-f" style="margin-top:10px" data-gsa-urlf><label for="gsa-url">Link goes to</label><input type="text" id="gsa-url" data-gsa-url maxlength="300" value="' + esc(values.url) + '">'
      + '<p class="gsa-help">A page of this shop starting with / (default ' + esc(P.default_url) + ', the Super Sale page with the gift row; Arabic shoppers get its /ar page), or an address starting with https://.</p></div></div>'
      + '<div class="gsa-card"><p class="gsa-title">Products</p><p class="gsa-sub">Up to three on each side. Set each product\'s real height in centimetres: they are drawn at one scale (10px per cm on a computer, 6px on a phone), so a 9 cm jar stands next to a 14 cm tube as it does on a shelf.</p>'
      + '<div class="gsa-bar" role="radiogroup" aria-label="Which products"><button type="button" class="gsa-btn" data-gsa-src="live" aria-pressed="' + !pick + '">Live free gifts</button><button type="button" class="gsa-btn" data-gsa-src="pick" aria-pressed="' + pick + '">Pick products</button></div>'
      + (pick ? '<div class="gsa-f" style="margin-top:10px"><label for="gsa-q">Add a product (' + values.picks.length + ' of ' + P.max_picks + ')</label><input type="search" id="gsa-q" data-gsa-q placeholder="Type 2 letters of a name or SKU"' + (values.picks.length >= P.max_picks ? ' disabled' : '') + '><div class="gsa-res" data-gsa-res></div></div>'
        : '<p class="gsa-help" style="margin-top:8px">The gifts the cart gives today (Growth &amp; Marketing → Free gifts), full sizes first. A gift you remove there leaves the strip by itself; a new one shows once it has a cut-out.</p>')
      + '<div class="gsa-bar"><button type="button" class="gsa-btn is-primary" data-gsa-prep' + (cutting || !list.length ? ' disabled' : '') + '>Prepare cut-outs</button><span class="gsa-help" data-gsa-prog>' + ready + ' of ' + list.length + ' ready</span></div>'
      + '<div class="gsa-rows">' + (list.length ? list.map(function (p, i) { return rowHTML(p, pick, i, list.length); }).join('') : '<div class="gsa-note">' + (pick ? 'No products picked yet.' : 'No free gift is running today.') + '</div>') + '</div></div>'
      + '<div class="gsa-save"><button type="button" class="gsa-btn is-primary" data-gsa-save' + (busy ? ' disabled' : '') + '>Save</button><span data-gsa-state>' + (dirty ? 'Unsaved changes' : 'Saved') + '</span></div>'
      + '</div>';
    paintPreview();
    drawResults();
  }

  function drawResults() {
    var box = document.querySelector('[data-gsa-res]');
    if (!box) return;
    box.innerHTML = results.filter(function (p) { return values.picks.indexOf(p.id) === -1; }).map(function (p) {
      return '<button type="button" data-gsa-add="' + p.id + '">' + (p.thumb ? '<img src="' + esc(p.thumb) + '" alt="" loading="lazy">' : '') + '<span>' + esc(p.name) + '</span></button>';
    }).join('');
  }

  function touched() {
    dirty = true;
    var s = document.querySelector('[data-gsa-state]');
    if (s) s.textContent = 'Unsaved changes';
  }

  /* ── Actions ───────────────────────────────────────────────────────── */
  async function save() {
    if (!values || busy) return;
    busy = true; render();
    var heights = {};
    Object.keys(values.heights).forEach(function (id) { var v = parseFloat(values.heights[id]); if (isFinite(v)) heights[id] = v; });
    try {
      var body = await api('/gift-strip', { settings: {
        on: values.on, desktop: values.desktop, mobile: values.mobile, style: values.style, icon: values.icon, anim: values.anim,
        url: values.url, source: values.source, texts: values.texts, picks: values.picks, heights: heights, white: values.white
      } });
      if (!ours()) { busy = false; return; }
      take(body); dirty = false; banner = null;
      say('Free gifts strip saved');
    } catch (e) {
      if (!ours()) { busy = false; return; }
      banner = explain(e, 'Not saved.');
    }
    busy = false;
    render();
  }

  async function cutOne(p) {
    var body = await api('/gift-strip/cutout', { id: p.id, white: values.white.indexOf(p.id) > -1 });
    p.cut = body.cut || null;
    return p;
  }

  async function prepare(only) {
    if (cutting) return;
    var list = only ? rows().filter(function (p) { return p.id === only; }) : rows().filter(function (p) { return !p.cut; });
    if (!list.length) list = only ? [] : rows();
    if (!list.length) return;
    cutting = true; banner = null; render();
    var done = 0, failed = [];
    for (var i = 0; i < list.length; i++) {
      if (!ours()) { cutting = false; return; }
      var prog = document.querySelector('[data-gsa-prog]');
      if (prog) prog.textContent = 'Cutting out ' + (i + 1) + ' of ' + list.length + '…';
      try { await cutOne(list[i]); done++; } catch (e) { failed.push(list[i].name + ': ' + explain(e, 'failed')); }
    }
    cutting = false;
    if (!ours()) return;
    banner = done + ' cut-out' + (done === 1 ? '' : 's') + ' made.' + (failed.length ? ' Not made — ' + failed.join(' · ') : '');
    render();
  }

  function search(q) {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = 0;
    if (q.length < 2) { results = []; drawResults(); return; }
    searchTimer = setTimeout(async function () {
      searchTimer = 0;
      var mine = ++searchSeq;
      try {
        var body = await api('/gift-strip/products?q=' + encodeURIComponent(q));
        if (mine !== searchSeq || !ours()) return;
        results = body.rows || [];
        results.forEach(function (p) { if (values.heights[p.id] == null) values.heights[p.id] = p.cm; });
        drawResults();
      } catch (e) { if (ours()) say(explain(e, 'Search failed')); }
    }, 300);
  }

  /* ── One set of delegated listeners, scoped to this screen ─────────── */
  function inScreen(el) { return !!(el && el.closest && el.closest('[data-gsa]')); }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!inScreen(t)) return;
    var b = t.closest('button');
    if (!b) return;
    if (b.hasAttribute('data-gsa-reload')) return load();
    if (b.hasAttribute('data-gsa-save')) return save();
    if (b.hasAttribute('data-gsa-prep')) return prepare(0);
    if (b.hasAttribute('data-gsa-lang')) { lang = b.getAttribute('data-gsa-lang'); return render(); }
    if (b.hasAttribute('data-gsa-style')) { values.style = b.getAttribute('data-gsa-style'); touched(); return render(); }
    if (b.hasAttribute('data-gsa-src')) { values.source = b.getAttribute('data-gsa-src'); touched(); return render(); }
    if (b.hasAttribute('data-gsa-cut')) return prepare(parseInt(b.getAttribute('data-gsa-cut'), 10));
    if (b.hasAttribute('data-gsa-add')) {
      var id = parseInt(b.getAttribute('data-gsa-add'), 10);
      if (values.picks.indexOf(id) === -1 && values.picks.length < data.preview.max_picks) values.picks.push(id);
      touched(); return render();
    }
    if (b.hasAttribute('data-gsa-rm')) {
      var rm = parseInt(b.getAttribute('data-gsa-rm'), 10);
      values.picks = values.picks.filter(function (x) { return x !== rm; });
      touched(); return render();
    }
    var up = b.getAttribute('data-gsa-up'), down = b.getAttribute('data-gsa-down');
    if (up !== null || down !== null) {
      var list = rows().map(function (p) { return p.id; });
      var i = parseInt(up !== null ? up : down, 10), j = up !== null ? i - 1 : i + 1;
      if (j < 0 || j >= list.length) return;
      var tmp = list[i]; list[i] = list[j]; list[j] = tmp;
      values.picks = list;
      touched(); return render();
    }
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!inScreen(t) || !values) return;
    if (t.hasAttribute('data-gsa-t')) { values.texts[t.getAttribute('data-gsa-t')] = t.value; touched(); return schedule(); }
    if (t.hasAttribute('data-gsa-h')) { values.heights[t.getAttribute('data-gsa-h')] = t.value; touched(); return schedule(); }
    if (t.hasAttribute('data-gsa-url')) {
      values.url = t.value.trim(); touched();
      var ok = values.url === '' || (/^\/(?!\/)[^\s"'<>\\]*$/.test(values.url) || /^https?:\/\/[a-z0-9.-]+(:\d+)?(\/[^\s"'<>\\]*)?$/i.test(values.url));
      var f = t.closest('[data-gsa-urlf]'); if (f) f.classList.toggle('is-bad', !ok);
      return;
    }
    if (t.hasAttribute('data-gsa-q')) return search(t.value.trim());
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!inScreen(t) || !values) return;
    if (t.hasAttribute('data-gsa-b')) {
      values[t.getAttribute('data-gsa-b')] = t.checked; touched();
      return (t.getAttribute('data-gsa-b') === 'desktop' || t.getAttribute('data-gsa-b') === 'mobile') ? render() : schedule();
    }
    if (t.hasAttribute('data-gsa-w')) {
      var id = parseInt(t.getAttribute('data-gsa-w'), 10);
      values.white = values.white.filter(function (x) { return x !== id; });
      if (t.checked) values.white.push(id);
      touched();
    }
  });

  addNavEntry();
})();
</script>
@endverbatim
