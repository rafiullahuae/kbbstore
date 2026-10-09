{{--
    Appearance → #KBeautyBliss Spotted.                              (Lane HB)

    Included ONCE at the end of resources/views/admin/app.blade.php by the
    integrator (beside the Footer screen's include). It registers its own
    sidebar entry and wraps window.go, exactly as the Footer screen does, so
    that one include is the whole of the change to that file.

    WHAT IT IS FOR. The owner: "with instagram feed make it carousel, but with
    manual selection … button to a new page /kbeautybliss-spotted with a
    manually selected IG grid". So: a list of hand-picked posts (picture from
    the media library, Instagram link, handle, caption, an optional product, an
    optional heart count, and two ticks — Homepage and Spotted page), in the
    order he arranges, and below it the section's settings in six tabs.

    (Lane HS) The Homepage grid tab draws the static grid's six pictures as
    cards — media library picker, link, description, ↑ ↓ — through
    gridHTML(); every other tab is still drawn straight from the schema.

    Endpoints: routes/spotted-admin.php, capability spotted.manage.

    Every class is spa- and every data attribute data-spa-, and neither appears
    anywhere else in the console: app.blade.php binds delegated listeners to
    `document`, each claiming a bare attribute name.

    Nothing wider than its column at 390px: every grid and flex child that can
    hold something wide carries min-width:0.

    Nothing below may name Blade's raw-block directives, in code or in prose.
--}}
@verbatim
<style>
.spa-wrap{display:grid;gap:14px;min-width:0}
.spa-wrap > *{min-width:0}
.spa-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}
.spa-title{font-weight:650;font-size:15px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.spa-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin:3px 0 0;max-width:72ch}
.spa-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.spa-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.spa-btn.is-danger{color:#b4443c}
.spa-btn[disabled]{opacity:.45;cursor:default}
.spa-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.spa-list{display:grid;gap:8px;margin-top:12px;min-width:0}
.spa-row{display:grid;grid-template-columns:56px minmax(0,1fr) auto;gap:12px;align-items:center;border:1px solid var(--border,#e6e6e6);border-radius:11px;padding:8px;min-width:0}
.spa-th{width:56px;height:70px;border-radius:8px;background:#f3eef0 center/cover no-repeat;display:block;overflow:hidden}
.spa-th img{width:100%;height:100%;object-fit:cover;display:block}
.spa-meta{min-width:0}
.spa-meta b{display:block;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.spa-meta span{display:block;font-size:12px;color:var(--ink-soft,#6b7280);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.spa-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}
.spa-chip{font-size:10.5px;font-weight:650;border-radius:99px;padding:2px 8px;background:#eef7f1;color:#157347}
.spa-chip.is-off{background:#f3f3f3;color:#888}
.spa-chip.is-warn{background:#fdecea;color:#b4443c}
.spa-rbt{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}
.spa-rbt .spa-btn{padding:6px 9px;font-size:12px}
.spa-form{display:grid;gap:12px;margin-top:12px;grid-template-columns:repeat(2,minmax(0,1fr))}
.spa-form .spa-full{grid-column:1 / -1}
.spa-f{display:grid;gap:5px;min-width:0}
.spa-f label{font-size:12.5px;font-weight:650}
.spa-f input[type=text],.spa-f input[type=url],.spa-f input[type=number],.spa-f select,.spa-f textarea{width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.spa-f textarea{min-height:70px;resize:vertical;line-height:1.5}
.spa-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0}
.spa-pic{display:flex;gap:12px;align-items:center;min-width:0}
.spa-pic .spa-th{width:72px;height:90px;flex:none}
.spa-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.spa-check input{margin-top:3px;flex:none;width:16px;height:16px}
.spa-res{display:grid;gap:4px;margin-top:4px}
.spa-res button{display:flex;gap:8px;align-items:center;text-align:start;padding:6px 8px;border:1px solid var(--border,#e6e6e6);border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:12.5px;cursor:pointer;min-width:0}
.spa-res img{width:28px;height:28px;border-radius:6px;object-fit:cover;flex:none}
.spa-res span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.spa-err{border:1px solid #f0c8c4;background:#fdf3f2;color:#b4443c;border-radius:10px;padding:10px 12px;font-size:12.5px}
.spa-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0;margin-top:10px}
.spa-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.spa-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.spa-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.spa-gcard{display:grid;grid-template-columns:72px minmax(0,1fr) auto;gap:12px;align-items:start;border:1px solid var(--border,#e6e6e6);border-radius:11px;padding:10px;min-width:0}
.spa-gcard .spa-th{width:72px;height:86px}
.spa-gbody{display:grid;gap:10px;min-width:0}
.spa-gbody .spa-actions{margin-top:0}
.spa-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px;border:1px dashed var(--border,#e6e6e6);border-radius:11px;margin-top:12px}
@media (max-width:640px){
  .spa-card{padding:13px}
  .spa-form{grid-template-columns:minmax(0,1fr)}
  .spa-row{grid-template-columns:48px minmax(0,1fr)}
  .spa-row .spa-th{width:48px;height:60px}
  .spa-rbt{grid-column:1 / -1;justify-content:flex-start}
  .spa-gcard{grid-template-columns:56px minmax(0,1fr)}
  .spa-gcard .spa-th{width:56px;height:67px}
}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'spotted';
  var LABEL = '#KBeautyBliss Spotted';

  var data = null, banner = null, busy = false, seq = 0;
  var values = {}, open = 'home';
  var editing = null;      // null, or the post being edited ({} for a new one)
  var formError = '';
  var found = [];          // product search results
  var searchTimer = null, searchSeq = 0;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, method, body) {
    var opts = { method: method || 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
    if (body !== undefined) {
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

  /* A picture address for the console's own thumbnails: a path on this shop or
     http(s), nothing else -- the same rule the server applies. */
  function safeSrc(u) {
    u = String(u || '').trim();
    if (/^\/(?!\/)/.test(u) || /^https?:\/\//i.test(u)) return u;
    return '';
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  function explain(e, fallback) {
    if (e && e.status === 404 && !(e.body && e.body.error)) {
      return 'The #KBeautyBliss Spotted endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    }
    if (e && e.status === 403) return 'Your account cannot change this screen.';
    return (e && e.body && (e.body.error || e.body.message)) ? (e.body.error || e.body.message) : fallback;
  }

  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: LABEL,
      icon: '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/>',
      group: 'Appearance',
      after: ['hpcontent', 'homepage']
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
    if (title) title.textContent = LABEL;

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    editing = null;
    render();
    load();
    return undefined;
  };

  function here() {
    return (document.querySelector('#ptitle') || {}).textContent === LABEL;
  }

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();
    try {
      var body = await api('/spotted');
      if (mine !== seq) return;
      data = body;
      values = {};
      (data.tabs || []).forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (!(data.tabs || []).some(function (t) { return t.key === open; })) open = (data.tabs[0] || {}).key;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The #KBeautyBliss Spotted settings could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  /* ------------------------------------------------------------- the list */
  function rowHTML(p, i, n) {
    var src = safeSrc(p.image);
    var chips = '<span class="spa-chip' + (p.on_home ? '' : ' is-off') + '">Homepage ' + (p.on_home ? '✓' : '✕') + '</span>'
      + '<span class="spa-chip' + (p.on_page ? '' : ' is-off') + '">Spotted page ' + (p.on_page ? '✓' : '✕') + '</span>'
      + (p.likes !== null && p.likes !== undefined ? '<span class="spa-chip">♥ ' + esc(p.likes) + '</span>' : '')
      + (p.product ? '<span class="spa-chip">' + esc(p.product.name) + '</span>' : '')
      + (p.drawable ? '' : '<span class="spa-chip is-warn">Not shown: check its picture and link</span>');
    return '<div class="spa-row">'
      + '<span class="spa-th">' + (src ? '<img src="' + esc(src) + '" alt="" loading="lazy">' : '') + '</span>'
      + '<div class="spa-meta"><b>@' + esc(p.handle) + '</b><span>' + esc(p.caption || p.ig_url || '') + '</span><div class="spa-chips">' + chips + '</div></div>'
      + '<div class="spa-rbt">'
      + '<button class="spa-btn" data-spa-up="' + p.id + '" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
      + '<button class="spa-btn" data-spa-down="' + p.id + '" aria-label="Move down"' + (i === n - 1 ? ' disabled' : '') + '>↓</button>'
      + '<button class="spa-btn" data-spa-edit="' + p.id + '">Edit</button>'
      + '<button class="spa-btn is-danger" data-spa-del="' + p.id + '">Remove</button>'
      + '</div></div>';
  }

  function formHTML() {
    var p = editing;
    var src = safeSrc(p.image);
    var prod = p.product
      ? '<div class="spa-res"><button type="button" data-spa-unprod>' + (safeSrc(p.product.image) ? '<img src="' + esc(safeSrc(p.product.image)) + '" alt="">' : '') + '<span>' + esc(p.product.name) + ' — remove</span></button></div>'
      : '';
    var res = found.map(function (r) {
      return '<button type="button" data-spa-prod="' + r.id + '">' + (safeSrc(r.image) ? '<img src="' + esc(safeSrc(r.image)) + '" alt="">' : '') + '<span>' + esc(r.name) + '</span></button>';
    }).join('');

    return '<div class="spa-card" data-spa-formcard>'
      + '<div class="spa-title">' + (p.id ? 'Edit post @' + esc(p.handle) : 'Add a post') + '</div>'
      + (formError ? '<div class="spa-err" role="alert" style="margin-top:10px">' + esc(formError) + '</div>' : '')
      + '<div class="spa-form">'
      + '<div class="spa-f spa-full"><label>Picture</label><div class="spa-pic"><span class="spa-th">' + (src ? '<img src="' + esc(src) + '" alt="">' : '') + '</span>'
      + '<div><button type="button" class="spa-btn" data-spa-pick>' + (src ? 'Change picture' : 'Choose from the media library') + '</button>'
      + '<p class="spa-help" style="margin-top:6px">Shown 4:5 (portrait), cropped to fill. A screenshot or a download of the post works well.</p></div></div></div>'
      + '<div class="spa-f"><label for="spa-ig">Instagram post link</label><input type="url" id="spa-ig" data-spa-in="ig_url" value="' + esc(p.ig_url || '') + '" placeholder="https://www.instagram.com/p/…" autocomplete="off">'
      + '<p class="spa-help">Must start https://www.instagram.com/ or https://instagram.com/.</p></div>'
      + '<div class="spa-f"><label for="spa-h">Handle</label><input type="text" id="spa-h" data-spa-in="handle" value="' + esc(p.handle || '') + '" placeholder="@sara.glows" autocomplete="off">'
      + '<p class="spa-help">Shown on the dark band at the top of the photo.</p></div>'
      + '<div class="spa-f"><label for="spa-c">Caption</label><input type="text" id="spa-c" data-spa-in="caption" value="' + esc(p.caption || '') + '" maxlength="160" placeholder="Torriden Serum" autocomplete="off">'
      + '<p class="spa-help">The line under the photo. Short reads best — about 24 characters fit.</p></div>'
      + '<div class="spa-f"><label for="spa-l">Heart count</label><input type="number" id="spa-l" data-spa-in="likes" value="' + esc(p.likes === null || p.likes === undefined ? '' : p.likes) + '" min="0" step="1" placeholder="Empty = no hearts shown">'
      + '<p class="spa-help">Only if you want one shown: 1240 is drawn as 1.2k. Left empty, no number is drawn — none is ever made up.</p></div>'
      + '<div class="spa-f"><label for="spa-a">Picture description (alt text)</label><input type="text" id="spa-a" data-spa-in="image_alt" value="' + esc(p.image_alt || '') + '" maxlength="200" autocomplete="off">'
      + '<p class="spa-help">For Google and screen readers. Empty: “@handle — caption”.</p></div>'
      + '<div class="spa-f"><label for="spa-t">A tap on the card opens</label><select id="spa-t" data-spa-in="link_to">'
      + '<option value="instagram"' + (p.link_to !== 'product' ? ' selected' : '') + '>The Instagram post (new tab)</option>'
      + '<option value="product"' + (p.link_to === 'product' ? ' selected' : '') + '>The linked product on this shop</option></select>'
      + '<p class="spa-help">When the one chosen is missing, the other is used.</p></div>'
      + '<div class="spa-f spa-full"><label for="spa-ps">Linked product (optional)</label>' + prod
      + '<input type="text" id="spa-ps" data-spa-search placeholder="Search the catalogue…" autocomplete="off"><div class="spa-res">' + res + '</div></div>'
      + '<div class="spa-f"><div class="spa-check"><input type="checkbox" id="spa-oh" data-spa-in="on_home"' + (p.on_home !== false ? ' checked' : '') + '><label for="spa-oh">Show on the homepage carousel</label></div></div>'
      + '<div class="spa-f"><div class="spa-check"><input type="checkbox" id="spa-op" data-spa-in="on_page"' + (p.on_page !== false ? ' checked' : '') + '><label for="spa-op">Show on the Spotted page</label></div></div>'
      + '</div>'
      + '<div class="spa-actions"><button class="spa-btn is-primary" data-spa-savepost' + (busy ? ' disabled' : '') + '>' + (busy ? 'Saving…' : (p.id ? 'Save post' : 'Add post')) + '</button>'
      + '<button class="spa-btn" data-spa-cancel>Cancel</button></div>'
      + '</div>';
  }

  /* --------------------------------------------------------- the settings */
  function fieldHTML(f) {
    var id = 'spa-s-' + f.key;
    var help = f.help ? '<p class="spa-help">' + esc(f.help) + '</p>' : '';
    if (f.type === 'bool') {
      return '<div class="spa-f"><div class="spa-check"><input type="checkbox" id="' + id + '" data-spa-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '><div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div></div></div>';
    }
    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '') + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="spa-f"><label for="' + id + '">' + esc(f.label) + '</label><select id="' + id + '" data-spa-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }
    if (f.type === 'textarea') {
      return '<div class="spa-f"><label for="' + id + '">' + esc(f.label) + '</label><textarea id="' + id + '" data-spa-key="' + esc(f.key) + '">' + esc(values[f.key]) + '</textarea>' + help + '</div>';
    }
    return '<div class="spa-f"><label for="' + id + '">' + esc(f.label) + '</label><input type="text" id="' + id + '" data-spa-key="' + esc(f.key) + '" value="' + esc(values[f.key]) + '" autocomplete="off">' + help + '</div>';
  }

  /* ------------------------------------------------ the homepage grid (Lane HS)
     The six pictures of the static grid, one card each: the picture from the
     media library, its link and its description, and ↑ ↓ to reorder. Moving
     a card swaps its three values with its neighbour's in `values`; nothing
     is sent until Save settings, like every other tab. */
  var GRID_RE = /^grid_(\d+)_(img|url|alt)$/;

  function gridHTML(t) {
    var byKey = {};
    t.fields.forEach(function (f) { byKey[f.key] = f; });
    var out = t.fields.filter(function (f) { return !GRID_RE.test(f.key); }).map(fieldHTML).join('');
    var n = gridCount();
    for (var i = 1; i <= n; i++) {
      var src = safeSrc(values['grid_' + i + '_img']);
      out += '<div class="spa-gcard">'
        + '<span class="spa-th">' + (src ? '<img src="' + esc(src) + '" alt="" loading="lazy">' : '') + '</span>'
        + '<div class="spa-gbody"><b>Picture ' + i + '</b><div class="spa-actions">'
        + '<button type="button" class="spa-btn" data-spa-gpick="' + i + '">' + (src ? 'Change picture' : 'Choose from the media library') + '</button>'
        + (src ? '<button type="button" class="spa-btn is-danger" data-spa-gclear="' + i + '">Remove picture</button>' : '')
        + '</div>'
        + (byKey['grid_' + i + '_url'] ? fieldHTML(byKey['grid_' + i + '_url']) : '')
        + (byKey['grid_' + i + '_alt'] ? fieldHTML(byKey['grid_' + i + '_alt']) : '')
        + '</div><div class="spa-rbt">'
        + '<button type="button" class="spa-btn" data-spa-gmove="' + i + ':-1" aria-label="Move picture ' + i + ' up"' + (i === 1 ? ' disabled' : '') + '>↑</button>'
        + '<button type="button" class="spa-btn" data-spa-gmove="' + i + ':1" aria-label="Move picture ' + i + ' down"' + (i === n ? ' disabled' : '') + '>↓</button>'
        + '</div></div>';
    }
    return out;
  }

  function gridCount() {
    var n = 0;
    while (Object.prototype.hasOwnProperty.call(values, 'grid_' + (n + 1) + '_img')) n++;
    return n;
  }

  function gridMove(i, d) {
    var j = i + d;
    if (j < 1 || j > gridCount()) return;
    ['img', 'url', 'alt'].forEach(function (part) {
      var a = 'grid_' + i + '_' + part, b = 'grid_' + j + '_' + part;
      var tmp = values[a]; values[a] = values[b]; values[b] = tmp;
    });
    render();
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || !here()) return;

    if (!data) {
      host.innerHTML = '<div class="spa-wrap"><div class="spa-card">'
        + (busy ? '<div class="spa-empty">Loading…</div>'
          : '<div class="spa-title">' + LABEL + '</div><p class="spa-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
            + '<div class="spa-actions"><button class="spa-btn" data-spa-reload>Retry</button></div>')
        + '</div></div>';
      return;
    }

    var posts = data.posts || [];
    var homeCount = posts.filter(function (p) { return p.on_home && p.drawable; }).length;
    var pageCount = posts.filter(function (p) { return p.on_page && p.drawable; }).length;
    var current = (data.tabs || []).filter(function (t) { return t.key === open; })[0] || (data.tabs || [])[0];

    host.innerHTML = '<div class="spa-wrap">'
      + (banner ? '<div class="spa-err" role="alert">' + esc(banner) + '</div>' : '')
      + '<div class="spa-card">'
      + '<div class="spa-title"><span>Manual posts</span><span style="display:flex;gap:8px;flex-wrap:wrap">'
      + '<a class="spa-btn" href="' + esc(data.page_url) + '" target="_blank" rel="noopener">View the Spotted page ↗</a>'
      + '<button class="spa-btn is-primary" data-spa-add>+ Add a post</button></span></div>'
      + '<p class="spa-sub">Hand-picked, in this order. <b>' + homeCount + '</b> on the homepage carousel, <b>' + pageCount + '</b> on the Spotted page. '
      + 'With the Carousel layout, the homepage section stays hidden until at least one post is ticked “Homepage”.</p>'
      + (posts.length
          ? '<div class="spa-list">' + posts.map(function (p, i) { return rowHTML(p, i, posts.length); }).join('') + '</div>'
          : '<div class="spa-empty">No posts yet. Add the first one — a picture from the media library and its Instagram link.</div>')
      + '</div>'
      + (editing ? formHTML() : '')
      + (current
          ? '<div class="spa-card"><div class="spa-title">Settings</div>'
            + '<div class="spa-tabs">' + data.tabs.map(function (t) {
                return '<button type="button" class="spa-tab" data-spa-tab="' + esc(t.key) + '" aria-selected="' + (t.key === current.key ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
              }).join('') + '</div>'
            + '<p class="spa-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
            + '<div class="spa-fields">' + (current.key === 'grid' ? gridHTML(current) : current.fields.map(fieldHTML).join('')) + '</div>'
            + '<div class="spa-actions"><button class="spa-btn is-primary" data-spa-savesettings' + (busy ? ' disabled' : '') + '>' + (busy ? 'Saving…' : 'Save settings') + '</button>'
            + '<button class="spa-btn" data-spa-reload' + (busy ? ' disabled' : '') + '>Reload</button></div></div>'
          : '')
      + '</div>';
  }

  /* -------------------------------------------------------------- actions */
  async function saveSettings() {
    busy = true; render();
    try {
      var body = await api('/spotted/settings', 'POST', { settings: values });
      say(body && body.ok ? 'Saved — the homepage and the Spotted page show it now.' : 'Saved, except: ' + Object.values((body || {}).rejected || {}).join(', '));
    } catch (e) {
      banner = explain(e, 'The settings could not be saved.');
    } finally { busy = false; render(); }
  }

  async function savePost() {
    var p = editing;
    var payload = {
      image: p.image || '', ig_url: p.ig_url || '', handle: p.handle || '', caption: p.caption || '',
      image_alt: p.image_alt || '', link_to: p.link_to || 'instagram',
      likes: (p.likes === null || p.likes === undefined) ? '' : String(p.likes),
      product_id: p.product ? p.product.id : null,
      on_home: p.on_home !== false, on_page: p.on_page !== false
    };
    busy = true; formError = ''; render();
    try {
      await api(p.id ? '/spotted/posts/' + p.id : '/spotted/posts', 'POST', payload);
      editing = null; found = [];
      say(p.id ? 'Post saved.' : 'Post added.');
      busy = false;
      await load();
      return;
    } catch (e) {
      formError = explain(e, 'The post could not be saved.');
    } finally { busy = false; render(); }
  }

  async function removePost(id) {
    if (!window.confirm('Remove this post from the homepage and the Spotted page?')) return;
    try {
      await api('/spotted/posts/' + id, 'DELETE');
      say('Post removed.');
      await load();
    } catch (e) { banner = explain(e, 'The post could not be removed.'); render(); }
  }

  async function move(id, dir) {
    var posts = data.posts.slice();
    var i = posts.findIndex(function (p) { return p.id === id; });
    var j = i + dir;
    if (i < 0 || j < 0 || j >= posts.length) return;
    var tmp = posts[i]; posts[i] = posts[j]; posts[j] = tmp;
    data.posts = posts; render();
    try {
      await api('/spotted/order', 'POST', { ids: posts.map(function (p) { return p.id; }) });
    } catch (e) { banner = explain(e, 'The new order could not be saved.'); await load(); }
  }

  function search(term) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async function () {
      var mine = ++searchSeq;
      if (!term.trim()) { found = []; paintResults(); return; }
      try {
        var body = await api('/spotted/products?q=' + encodeURIComponent(term));
        if (mine !== searchSeq) return;
        found = (body && body.products) || [];
      } catch (e) { found = []; }
      paintResults();
    }, 220);
  }

  /* Only the result list is redrawn while typing, so the caret stays put. */
  function paintResults() {
    var box = document.querySelector('[data-spa-search]');
    if (!box || !box.nextElementSibling) return;
    box.nextElementSibling.innerHTML = found.map(function (r) {
      return '<button type="button" data-spa-prod="' + r.id + '">' + (safeSrc(r.image) ? '<img src="' + esc(safeSrc(r.image)) + '" alt="">' : '') + '<span>' + esc(r.name) + '</span></button>';
    }).join('');
  }

  document.addEventListener('input', function (e) {
    if (!here()) return;
    var k = e.target.closest('[data-spa-key]');
    if (k) {
      var key = k.getAttribute('data-spa-key');
      values[key] = k.type === 'checkbox' ? k.checked : k.value;
      return;
    }
    var f = e.target.closest('[data-spa-in]');
    if (f && editing) {
      var name = f.getAttribute('data-spa-in');
      editing[name] = f.type === 'checkbox' ? f.checked : f.value;
      return;
    }
    var s = e.target.closest('[data-spa-search]');
    if (s) { search(s.value); return; }
  });

  document.addEventListener('change', function (e) {
    if (!here()) return;
    var k = e.target.closest('[data-spa-key]');
    if (k && k.type === 'checkbox') values[k.getAttribute('data-spa-key')] = k.checked;
    var f = e.target.closest('[data-spa-in]');
    if (f && editing) editing[f.getAttribute('data-spa-in')] = f.type === 'checkbox' ? f.checked : f.value;
  });

  document.addEventListener('click', function (e) {
    if (!here()) return;
    var t;
    if ((t = e.target.closest('[data-spa-gmove]'))) {
      var gm = t.getAttribute('data-spa-gmove').split(':');
      gridMove(Number(gm[0]), Number(gm[1]));
      return;
    }
    if ((t = e.target.closest('[data-spa-gclear]'))) { values['grid_' + Number(t.getAttribute('data-spa-gclear')) + '_img'] = ''; render(); return; }
    if ((t = e.target.closest('[data-spa-gpick]'))) {
      var gi = Number(t.getAttribute('data-spa-gpick'));
      if (typeof window.kbbPickMedia !== 'function') { say('The media library is not available on this page.'); return; }
      window.kbbPickMedia({
        title: 'Choose picture ' + gi + ' of the homepage grid',
        note: 'Shown 5:6 (portrait), cropped to fill.',
        onPick: function (urls) {
          if (urls && urls[0]) { values['grid_' + gi + '_img'] = String(urls[0]); render(); }
        }
      });
      return;
    }
    if ((t = e.target.closest('[data-spa-tab]'))) { open = t.getAttribute('data-spa-tab'); render(); return; }
    if (e.target.closest('[data-spa-savesettings]')) { saveSettings(); return; }
    if (e.target.closest('[data-spa-reload]')) { load(); return; }
    if (e.target.closest('[data-spa-add]')) {
      editing = { on_home: true, on_page: true, link_to: 'instagram', likes: null };
      formError = ''; found = []; render();
      var card = document.querySelector('[data-spa-formcard]');
      if (card && card.scrollIntoView) card.scrollIntoView({ block: 'start', behavior: 'smooth' });
      return;
    }
    if ((t = e.target.closest('[data-spa-edit]'))) {
      var id = Number(t.getAttribute('data-spa-edit'));
      var p = (data.posts || []).filter(function (x) { return x.id === id; })[0];
      if (p) {
        editing = JSON.parse(JSON.stringify(p)); formError = ''; found = []; render();
        var c = document.querySelector('[data-spa-formcard]');
        if (c && c.scrollIntoView) c.scrollIntoView({ block: 'start', behavior: 'smooth' });
      }
      return;
    }
    if ((t = e.target.closest('[data-spa-del]'))) { removePost(Number(t.getAttribute('data-spa-del'))); return; }
    if ((t = e.target.closest('[data-spa-up]'))) { move(Number(t.getAttribute('data-spa-up')), -1); return; }
    if ((t = e.target.closest('[data-spa-down]'))) { move(Number(t.getAttribute('data-spa-down')), 1); return; }
    if (e.target.closest('[data-spa-cancel]')) { editing = null; formError = ''; render(); return; }
    if (e.target.closest('[data-spa-savepost]')) { savePost(); return; }
    if (e.target.closest('[data-spa-unprod]')) { if (editing) { editing.product = null; render(); } return; }
    if ((t = e.target.closest('[data-spa-prod]'))) {
      var pid = Number(t.getAttribute('data-spa-prod'));
      var r = found.filter(function (x) { return x.id === pid; })[0];
      if (r && editing) { editing.product = { id: r.id, name: r.name, image: r.image }; found = []; render(); }
      return;
    }
    if (e.target.closest('[data-spa-pick]')) {
      if (typeof window.kbbPickMedia !== 'function') { say('The media library is not available on this page.'); return; }
      window.kbbPickMedia({
        title: 'Choose the post’s picture',
        note: 'A portrait picture reads best: it is shown 4:5.',
        onPick: function (urls) {
          if (editing && urls && urls[0]) { editing.image = String(urls[0]); render(); }
        }
      });
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
