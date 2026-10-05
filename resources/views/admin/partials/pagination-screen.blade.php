{{--
    Catalog -> Pagination.                                             (Lane PG)

    "also i allow option to turn off the pagination function completely for any
    page, any category or brand, in case of turned off, all the products will
    show at once. keep this option under Catelog > Pagination."

    One switch for the whole shop, and an override per listing: Follow the
    global setting / Pagination on / Pagination off (show all products). Off is
    App\Support\ListingPagination: the listing's page becomes CAP products —
    the brand page's own "every product on one page" cap — so no page numbers,
    no "load more" batches, and ?paged=N 301s to page one.

    LIGHT: one GET when the screen opens carries every category, brand and
    listing page; the search box filters that list in the browser, so typing
    sends nothing. One POST on Save. No timer, no polling, nothing measured.

    Every name here comes from the catalogue and reaches the page through
    esc(); an href is used only when it is a path or an http(s) URL.

    Pulled into app.blade.php after product-tabs-screen by tools/pg-wire.php:
    registers its own sidebar entry (LATE_NAV carries the build-time copy) and
    wraps window.go.
--}}
@verbatim
<style>
.pgx{display:grid;gap:14px;max-width:920px}
.pgx-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:16px;min-width:0}
.pgx-card h3{font-size:15px;font-weight:700;margin:0 0 4px}
.pgx-help{font-size:12.5px;color:var(--ink-soft,#6b7280);margin:0;line-height:1.5}
.pgx-sw{display:flex;gap:12px;align-items:center;margin-top:12px;font-size:13.5px;font-weight:600;cursor:pointer}
.pgx-sw input{width:44px;height:24px;appearance:none;-webkit-appearance:none;border-radius:999px;background:#cbd5e1;position:relative;cursor:pointer;flex:none;margin:0;transition:background .15s}
.pgx-sw input::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .15s}
.pgx-sw input:checked{background:var(--accent,#15a85a)}
.pgx-sw input:checked::after{transform:translateX(20px)}
[dir=rtl] .pgx-sw input:checked::after{transform:translateX(-20px)}
.pgx-rows{margin-top:12px;border-top:1px solid var(--border,#eee)}
.pgx-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border,#eee)}
.pgx-name{font-size:13.5px;font-weight:600;overflow-wrap:anywhere;min-width:0}
.pgx-name a{color:inherit;text-decoration:none}
.pgx-name a:hover{text-decoration:underline}
.pgx-meta{display:block;font-size:11.5px;font-weight:400;color:var(--ink-soft,#6b7280);margin-top:2px;overflow-wrap:anywhere}
.pgx-kind{display:inline-block;font-size:10.5px;font-weight:650;letter-spacing:.04em;text-transform:uppercase;border-radius:999px;padding:1px 7px;margin-inline-end:6px;background:#eef2ff;color:#3730a3;vertical-align:1px}
.pgx-kind.brand{background:#fdf2f8;color:#9d174d}
.pgx-ctl{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.pgx-ctl select{font:inherit;font-size:12.5px;border:1px solid var(--border,#e6e6e6);border-radius:9px;padding:6px 8px;background:var(--surface,#fff);color:inherit;max-width:100%}
.pgx-now{font-size:11.5px;font-weight:650;border-radius:999px;padding:2px 8px;white-space:nowrap}
.pgx-now.pages{background:#ecfdf5;color:#065f46}
.pgx-now.all{background:#fff7ed;color:#9a3412}
.pgx-find{display:block;width:100%;box-sizing:border-box;margin-top:12px;border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:9px 11px;font:inherit;font-size:13px;background:var(--surface,#fff);color:inherit}
.pgx-sub{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft,#6b7280);font-weight:650;margin:14px 0 0}
.pgx-empty{padding:14px 2px;font-size:12.5px;color:var(--ink-soft,#6b7280)}
.pgx-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:14px;padding:12px 16px}
.pgx-bar.dirty{position:sticky;bottom:0;box-shadow:0 -8px 24px -12px rgba(0,0,0,.25)}
.pgx-status{font-size:12.5px;color:var(--ink-soft,#6b7280)}
.pgx-status.err{color:#b91c1c}
@media (max-width:640px){.pgx-row{grid-template-columns:1fr}.pgx-ctl{justify-content:flex-start}.pgx-card{padding:13px}}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'pagination';
  var LIMIT = 30;
  var data = null, on = true, ovr = { category: {}, brand: {}, page: {} };
  var find = '', dirty = false, busy = false, status = '', statusErr = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function safeHref(u) {
    u = String(u || '');
    return /^(\/|https?:\/\/)/i.test(u) ? u : '';
  }

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
  }

  async function api(method, body) {
    var r = await fetch(base() + '/admin-api/pagination', {
      method: method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    });
    var json = null;
    try { json = await r.json(); } catch (e) { json = null; }
    if (!r.ok) {
      var err = new Error((json && json.error) || ('pagination ' + r.status));
      err.status = r.status;
      throw err;
    }
    return json;
  }

  function take(json) {
    data = json;
    on = !!json.on;
    var o = json.overrides || {};
    ovr = {
      category: Object.assign({}, o.category || {}),
      brand: Object.assign({}, o.brand || {}),
      page: Object.assign({}, o.page || {})
    };
    dirty = false;
  }

  async function load() {
    status = ''; statusErr = false;
    try {
      take(await api('GET'));
    } catch (e) {
      data = null;
      status = e && e.status === 403
        ? 'Your role cannot change pagination. An owner, manager or editor can.'
        : (e && e.status === 404
          ? 'This page is not in the server\'s route table yet. Clear the route cache and reload.'
          : 'Could not load the pagination settings. Try again in a moment.');
      statusErr = true;
    }
    paint();
  }

  async function save() {
    if (busy || !data) return;
    busy = true; status = 'Saving…'; statusErr = false;
    paintBar();
    try {
      take(await api('POST', { on: on, overrides: ovr }));
      status = 'Saved. The shop uses it on the next page load.';
    } catch (e) {
      status = e && e.status === 403 ? 'Your role cannot change pagination.' : (e.message || 'Could not save.');
      statusErr = true;
    }
    busy = false;
    paint();
  }

  function state(kind, id) { return ovr[kind][String(id)] || 'follow'; }

  /* What the shopper gets, said in two words: the rule ListingPagination::showsAll() applies. */
  function showsAll(kind, id) {
    var s = state(kind, id);
    if (s === 'off') return true;
    if (s === 'on') return false;
    return !on || (kind === 'brand' && !!(data && data.brandAll));
  }

  function select(kind, id, label) {
    var s = state(kind, id);
    var opts = [['follow', 'Follow the global setting'], ['on', 'Pagination on'], ['off', 'Pagination off (show all products)']];
    return '<select data-pgx-kind="' + kind + '" data-pgx-id="' + esc(id) + '" aria-label="Pagination for ' + esc(label) + '">'
      + opts.map(function (o) {
          return '<option value="' + o[0] + '"' + (s === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
        }).join('')
      + '</select>';
  }

  function now(kind, id) {
    return showsAll(kind, id)
      ? '<span class="pgx-now all">All at once</span>'
      : '<span class="pgx-now pages">Pages</span>';
  }

  function row(kind, id, label, meta, url) {
    var href = safeHref(url);
    var name = href ? '<a href="' + esc(href) + '" target="_blank" rel="noopener">' + esc(label) + '</a>' : esc(label);
    var chip = kind === 'page' ? '' : '<span class="pgx-kind ' + kind + '">' + kind + '</span>';
    return '<div class="pgx-row"><div class="pgx-name">' + chip + name
      + (meta ? '<span class="pgx-meta">' + esc(meta) + '</span>' : '') + '</div>'
      + '<div class="pgx-ctl">' + now(kind, id) + select(kind, id, label) + '</div></div>';
  }

  /* Categories and brands: the search over the list the GET already brought. */
  function listHTML() {
    var q = find.toLowerCase();
    var cats = data.categories || [], brands = data.brands || [];
    var out = [], shown = 0, total = 0;

    if (q) {
      cats.forEach(function (c) {
        if ((c.name + ' ' + c.path).toLowerCase().indexOf(q) === -1) return;
        total++;
        if (shown < LIMIT) { shown++; out.push(row('category', c.id, c.name, '/' + c.path + '/', c.url)); }
      });
      brands.forEach(function (b) {
        if (b.name.toLowerCase().indexOf(q) === -1) return;
        total++;
        if (shown < LIMIT) { shown++; out.push(row('brand', b.id, b.name, '', b.url)); }
      });
      if (!total) return '<div class="pgx-empty">No category or brand matches “' + esc(find) + '”.</div>';
      return out.join('') + (total > shown ? '<div class="pgx-empty">' + (total - shown) + ' more — type a little more to narrow it.</div>' : '');
    }

    cats.forEach(function (c) { if (state('category', c.id) !== 'follow') out.push(row('category', c.id, c.name, '/' + c.path + '/', c.url)); });
    brands.forEach(function (b) { if (state('brand', b.id) !== 'follow') out.push(row('brand', b.id, b.name, '', b.url)); });

    return out.length
      ? '<p class="pgx-sub">Your overrides</p>' + out.join('')
      : '<div class="pgx-empty">Every category and brand follows the global setting. Search above to change one.</div>';
  }

  function paintList() {
    var host = document.querySelector('[data-pgx-list]');
    if (host && data) host.innerHTML = listHTML();
  }

  function paintBar() {
    var host = document.querySelector('[data-pgx-bar]');
    if (!host) return;
    // Pinned to the bottom only while there is something to save, so an
    // unchanged screen does not spend a phone's last 80px on a grey button.
    host.classList.toggle('dirty', dirty);
    host.innerHTML = '<button type="button" class="btn" data-pgx-save' + (busy || !data || !dirty ? ' disabled' : '') + '>Save</button>'
      + '<span class="pgx-status' + (statusErr ? ' err' : '') + '" role="status" aria-live="polite">'
      + esc(status || (dirty ? 'Unsaved changes.' : '')) + '</span>';
  }

  function paint() {
    var host = document.getElementById('content');
    if (!host) return;
    if (!host.querySelector('[data-pgx]')) {
      host.innerHTML = '<div class="wrap"><div class="page-head"><h2>Pagination</h2>'
        + '<p>Page numbers on the shop’s product listings — on for everything, or off for one category, one brand or one listing page. '
        + 'Off shows every product of that listing at once.</p></div><div class="pgx" data-pgx></div></div>';
    }
    var root = host.querySelector('[data-pgx]');

    if (!data) {
      root.innerHTML = '<div class="pgx-card"><div class="pgx-empty' + (statusErr ? ' err' : '') + '">' + esc(status || 'Loading…') + '</div></div>';
      return;
    }

    var cap = Number(data.cap || 0).toLocaleString('en-US');
    var brandRule = data.brandAll
      ? 'A brand that follows it keeps Appearance → Site layout → Brand page → “Show every product of the brand on one page”, which is on: brand pages show everything already.'
      : 'A brand that follows it keeps Appearance → Site layout → Brand page → “Show every product of the brand on one page”, which is off: brand pages page.';

    root.innerHTML = '<div class="pgx-card"><h3>Pagination on the shop</h3>'
      + '<p class="pgx-help">On: listings show page numbers (or load more as the shopper scrolls, as Appearance → Site layout → “How more products load” says). '
      + 'Off: every listing that follows this shows all its products at once — up to ' + cap + ' products; past that the page numbers carry the rest, so no page can become enormous. '
      + 'An old page-2 address goes to page one.</p>'
      + '<label class="pgx-sw"><input type="checkbox" data-pgx-global' + (on ? ' checked' : '') + '> <span>' + (on ? 'Pagination is on' : 'Pagination is off — all products at once') + '</span></label>'
      + '<p class="pgx-help" style="margin-top:8px">' + esc(brandRule) + '</p></div>'

      + '<div class="pgx-card"><h3>Listing pages</h3>'
      + '<p class="pgx-help">The shop page (and search results), the curated listings and the concern pages.</p>'
      + '<div class="pgx-rows">' + (data.pages || []).map(function (p) { return row('page', p.key, p.label, p.path, p.url); }).join('') + '</div></div>'

      + '<div class="pgx-card"><h3>Categories and brands</h3>'
      + '<p class="pgx-help">Find any category or brand and set it on its own. ' + (data.categories || []).length + ' categories and '
      + (data.brands || []).length + ' brands — the search runs in this page, nothing is sent while you type.</p>'
      + '<input type="search" class="pgx-find" data-pgx-find placeholder="Find a category or brand…" aria-label="Find a category or brand" value="' + esc(find) + '">'
      + '<div class="pgx-rows" data-pgx-list>' + listHTML() + '</div></div>'

      + '<div class="pgx-bar" data-pgx-bar></div>';

    paintBar();
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-pgx]')) return;
    if (t.hasAttribute('data-pgx-global')) {
      on = !!t.checked; dirty = true; status = ''; statusErr = false;
      paint();
      return;
    }
    var kind = t.getAttribute('data-pgx-kind');
    if (!kind || !ovr[kind]) return;
    var id = t.getAttribute('data-pgx-id');
    if (t.value === 'follow') delete ovr[kind][id]; else ovr[kind][id] = t.value;
    dirty = true; status = ''; statusErr = false;
    var badge = t.parentNode && t.parentNode.querySelector('.pgx-now');
    if (badge) badge.outerHTML = now(kind, id);
    paintBar();
  });

  document.addEventListener('input', function (e) {
    if (!e.target || !e.target.hasAttribute || !e.target.hasAttribute('data-pgx-find')) return;
    find = e.target.value.trim();
    paintList();
  });

  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest && e.target.closest('[data-pgx-save]');
    if (b && !b.disabled) save();
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Pagination',
      icon: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M7 20h2"/><path d="M11 20h2"/><path d="M15 20h2"/>',
      group: 'Catalog',
      after: ['brands-manager', 'category-tree', 'catalog']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog';
    if (title) title.textContent = 'Pagination';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var host = document.getElementById('content');
    if (host) host.innerHTML = '';
    paint();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
