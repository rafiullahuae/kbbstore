{{--
    Catalog → Sets. (Lane SET)

    The owner's words:

      "I have a new product type, which called Set. ... Under Catalog, there will
       be Sets, and upon creating new set, the system will ask to choose the
       products, and will ask for set price, category, description etc, the same
       as in product edit page. and it will be published same like other products
       and display."

    So this screen creates a PRODUCT. A set is a row in `products` with
    `type = 'set'` plus rows in `product_set_items` for what is in the box — not
    a table of its own, and not a folder. Everything a published product needs
    it therefore already has: a slug, a status, a category, a description,
    images, a price, a sale price and its SEO row. See
    database/migrations/2027_04_01_000000_sets_schema.php for the shape and for
    the first-hand sweep of everything in this application that branches on
    `products.type`.

    ── HOW THIS FILE JOINS THE CONSOLE ────────────────────────────────────────

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry, window.kbbPickMedia and toast().
    It registers its OWN sidebar entry and wraps window.go, exactly as the
    screens beside it do, so ONE @include is the whole of the change to that
    file. The integrator's note is in the report.

    ── NOTHING HERE UPLOADS A FILE ────────────────────────────────────────────

    There is no file input in this screen at all. Both the main image and the
    gallery go through window.kbbPickMedia, which is the owner's standing rule —
    "on any upload media on the whole backend, the media library is a must to
    show" — and AdminMediaPickerEverywhereTest is what enforces it. It also
    means one upload path in the application and one place where the type, size
    and SVG rules live.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ────────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included
    — with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    ── LAYOUT AND ESCAPING ────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto.

    EVERY CLASS IS PREFIXED kst- AND EVERY data- ATTRIBUTE data-kst-, and both
    appear nowhere else in the console: app.blade.php binds delegated listeners
    to `document` itself, each claiming a bare attribute name.

    esc() ON EVERY INTERPOLATION of a product name, a brand, a category and a
    server error. CLAUDE.md rule 5: anything printed unescaped is a constant,
    never a setting.
--}}
@verbatim
<style>
/* Catalog → Sets. Built on the console's own tokens — --border, --r/--r-sm/
   --r-xs, --sh-s, --surface-2/3, the ink scale — so the screen follows the
   console theme instead of naming literals that cannot. */
.kst-wrap{display:grid;gap:16px;min-width:0}
.kst-wrap > *{min-width:0}
.kst-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
          border-radius:var(--r,18px);padding:18px;min-width:0;box-shadow:var(--sh-s)}
.kst-title{font-weight:680;font-size:15.5px;letter-spacing:-.01em;color:var(--ink,#101729)}
.kst-sub{color:var(--ink-soft,#626c80);font-size:12.5px;line-height:1.55;margin-top:4px;max-width:68ch}
.kst-note{border:1px solid var(--border,#e6e9f2);background:var(--surface-2,#f2f4fb);
          border-radius:var(--r-sm,12px);padding:11px 13px;font-size:12.5px;line-height:1.55;
          color:var(--ink-soft,#626c80);min-width:0}
.kst-note.is-bad{border-color:#f3c9c6;background:var(--red-soft,#fdeceb);color:#9d332c}
.kst-actions{display:flex;flex-wrap:wrap;gap:8px;min-width:0;align-items:center}
.kst-btn{padding:8px 14px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
         background:var(--surface,#fff);color:var(--ink-2,#3c465c);font:inherit;font-size:12.5px;
         font-weight:600;cursor:pointer;max-width:100%}
.kst-btn:hover{background:var(--surface-2,#f2f4fb)}
.kst-btn.is-primary{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.kst-btn.is-danger{color:var(--red,#e3493f)}
.kst-btn[disabled]{opacity:.45;cursor:default}
.kst-btn:focus-visible,.kst-mini:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.kst-mini{padding:4px 8px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
          background:var(--surface,#fff);color:var(--ink-2,#3c465c);font:inherit;font-size:11.5px;
          font-weight:600;cursor:pointer;line-height:1.2}
.kst-mini.is-danger{color:var(--red,#e3493f)}

/* The form. minmax(0,...) and not 1fr — a grid child's default min-width is
   auto, which is what made three other screens in this console overflow at
   390px before they were fixed. */
.kst-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(210px,100%),1fr));min-width:0}
.kst-f{display:grid;gap:5px;min-width:0}
.kst-f label{font-size:11.5px;font-weight:650;color:var(--ink-soft,#626c80);letter-spacing:.02em}
.kst-f input,.kst-f select,.kst-f textarea{width:100%;box-sizing:border-box;font:inherit;font-size:13px;
  padding:8px 10px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
  background:var(--surface,#fff);color:var(--ink,#101729);min-width:0}
.kst-f textarea{min-height:90px;resize:vertical}
.kst-help{font-size:11.5px;color:var(--ink-soft,#626c80);line-height:1.5}

/* The member list and the picker. One column on a phone, two above it. */
.kst-two{display:grid;gap:14px;grid-template-columns:minmax(0,1fr);min-width:0}
@media (min-width:860px){.kst-two{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}
.kst-row{display:flex;align-items:center;gap:9px;padding:8px 0;
         border-bottom:1px solid var(--border,#e6e9f2);min-width:0;flex-wrap:wrap}
.kst-th{width:38px;height:38px;border-radius:9px;flex:none;background-size:cover;
        background-position:center;background-color:var(--surface-2,#f2f4fb)}
.kst-nm{font-size:12.5px;font-weight:620;color:var(--ink,#101729);line-height:1.3;
        min-width:0;overflow-wrap:anywhere}
.kst-meta{font-size:11.5px;color:var(--ink-soft,#626c80);line-height:1.4;overflow-wrap:anywhere}
.kst-mid{min-width:0;flex:1 1 120px}
.kst-qty{width:56px;flex:none}
.kst-sel{max-width:130px;flex:none}
.kst-list{max-height:360px;overflow:auto;min-width:0}
.kst-sum{display:flex;flex-wrap:wrap;gap:8px 18px;font-size:12.5px;color:var(--ink-soft,#626c80)}
.kst-sum b{color:var(--ink,#101729)}
.kst-save{color:#15803d}
.kst-imgs{display:flex;flex-wrap:wrap;gap:10px;min-width:0}
.kst-img{width:60px;height:60px;border-radius:9px;background-size:cover;background-position:center;
         background-color:var(--surface-2,#f2f4fb);position:relative;flex:none}
.kst-img button{position:absolute;inset-block-start:-6px;inset-inline-end:-6px;width:20px;height:20px;
  border-radius:50%;border:1px solid var(--border,#e6e9f2);background:#fff;cursor:pointer;
  font-size:11px;line-height:1;color:var(--red,#e3493f);padding:0}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'sets';

  var state = { sets: [], categories: [], editing: null, found: [], busy: false, error: null, q: '' };

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(m) { try { window.toast(m); } catch (e) {} }

  /* The XSRF cookie, not a <meta> tag. The admin console has no csrf-token meta
     and a screen that read one sent '' on every write and was refused with 419
     — which the screen then reported as "could not be saved", a sentence that
     names the symptom and hides the cause. That happened on the Shoppable video
     screen and nobody could create a section until it was found. */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body, method) {
    var options = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (body !== undefined || method) {
      options.method = method || 'POST';
      options.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      if (body !== undefined) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
      }
    }
    var response = await fetch(base() + path, options);
    var payload = null;
    try { payload = await response.json(); } catch (e) {}
    if (!response.ok) { throw { status: response.status, body: payload }; }
    return payload || {};
  }

  /* A 404 here means one specific thing and it is worth saying rather than
     hiding behind "something went wrong": the package's routes are not in the
     server's compiled route table. That is what the clear_caches migration
     shipping beside this exists to fix, and a shop that applied the files
     without the migration lands exactly here. */
  function explain(e, fallback) {
    if (e && e.status === 404) {
      return 'The Sets endpoints are not in this server\'s compiled route table yet. '
           + 'Clear the route cache and reload.';
    }
    if (e && e.status === 403) {
      return 'Your account does not hold the Sets capability.';
    }
    if (e && e.body && e.body.errors) {
      var first = Object.keys(e.body.errors)[0];
      if (first) return String(e.body.errors[first][0]);
    }
    if (e && e.body && e.body.error) return String(e.body.error);
    return fallback;
  }

  /* ------------------------------------------------------------------ money */

  /* Two decimals in, two decimals out, and the SERVER does the conversion to
     fils. Nothing on this screen multiplies a price by 100 — that arithmetic
     happens once, in SetApiController::filsFromMajor(), where it is a rounded
     integer and never a float. A second copy here is a second answer to what a
     set costs. */
  function money(v) {
    var n = Number(v);
    return isFinite(n) ? n.toFixed(2) : '0.00';
  }

  function totals() {
    var set = state.editing;
    if (!set) return { parts: 0, count: 0, saving: 0 };
    var parts = 0, count = 0;
    set.members.forEach(function (m) {
      parts += Number(m.unit_price_aed || 0) * Number(m.quantity || 1);
      count += Number(m.quantity || 1);
    });
    var price = Number(set.price_aed || 0);
    return { parts: parts, count: count, saving: Math.max(0, parts - price) };
  }

  /* ----------------------------------------------------------------- render */

  function render() {
    var el = document.querySelector('#content');
    if (!el) return;
    el.innerHTML = '<div class="wrap kst-wrap">'
      + (state.error ? '<div class="kst-note is-bad">' + esc(state.error) + '</div>' : '')
      + (state.editing ? editor() : list())
      + '</div>';
  }

  function list() {
    var rows = state.sets.map(function (s) {
      return '<div class="kst-row">'
        + '<span class="kst-th" style="' + (s.image ? 'background-image:url(\'' + esc(s.image) + '\')' : '') + '"></span>'
        + '<div class="kst-mid">'
          + '<div class="kst-nm">' + esc(s.name) + '</div>'
          + '<div class="kst-meta">' + esc(s.status) + (s.category ? ' · ' + esc(s.category) : '')
            + ' · ' + s.member_count + ' product' + (s.member_count === 1 ? '' : 's')
            + ' · AED ' + esc(money(s.price_aed)) + '</div>'
        + '</div>'
        + '<button class="kst-mini" data-kst-edit="' + s.id + '">Edit</button>'
        + '<button class="kst-mini is-danger" data-kst-del="' + s.id + '">Delete</button>'
        + '</div>';
    }).join('');

    return '<div class="kst-card">'
      + '<div class="kst-actions" style="justify-content:space-between">'
        + '<div><div class="kst-title">Sets</div>'
        + '<div class="kst-sub">A set is a product made of other products. It has its own price, '
        + 'category, description and images, and it publishes and displays exactly like any other '
        + 'product — it appears in the shop, on its own page, in search and in the basket.</div></div>'
        + '<button class="kst-btn is-primary" data-kst-new>New set</button>'
      + '</div>'
      + '<div class="kst-list" style="margin-top:14px">'
        + (rows || '<div class="kst-note">No sets yet. <b>New set</b> creates one.</div>')
      + '</div></div>';
  }

  function editor() {
    var s = state.editing;
    var t = totals();

    var members = s.members.map(function (m, i) {
      return '<div class="kst-row">'
        + '<span class="kst-th" style="' + (m.image ? 'background-image:url(\'' + esc(m.image) + '\')' : '') + '"></span>'
        + '<div class="kst-mid">'
          + '<div class="kst-nm">' + esc(m.name) + '</div>'
          + '<div class="kst-meta">' + (m.brand ? esc(m.brand) + ' · ' : '')
            + 'AED ' + esc(money(m.unit_price_aed)) + '</div>'
        + '</div>'
        + '<input class="kst-qty" type="number" min="1" max="99" value="' + Number(m.quantity || 1) + '" data-kst-mq="' + i + '" aria-label="Quantity">'
        + '<button class="kst-mini" data-kst-up="' + i + '" ' + (i === 0 ? 'disabled' : '') + ' aria-label="Move up">&uarr;</button>'
        + '<button class="kst-mini" data-kst-down="' + i + '" ' + (i === s.members.length - 1 ? 'disabled' : '') + ' aria-label="Move down">&darr;</button>'
        + '<button class="kst-mini is-danger" data-kst-rm="' + i + '" aria-label="Remove">&#10005;</button>'
        + '</div>';
    }).join('');

    var found = state.found.map(function (p) {
      var options = p.variants.length
        ? '<select class="kst-sel" data-kst-var="' + p.id + '" aria-label="Option">'
            + '<option value="">Whole product</option>'
            + p.variants.map(function (v) { return '<option value="' + v.id + '">' + esc(v.label) + '</option>'; }).join('')
          + '</select>'
        : '';
      return '<div class="kst-row">'
        + '<span class="kst-th" style="' + (p.image ? 'background-image:url(\'' + esc(p.image) + '\')' : '') + '"></span>'
        + '<div class="kst-mid">'
          + '<div class="kst-nm">' + esc(p.name) + '</div>'
          + '<div class="kst-meta">' + (p.brand ? esc(p.brand) + ' · ' : '') + 'AED ' + esc(money(p.price_aed)) + '</div>'
        + '</div>' + options
        + '<button class="kst-mini" data-kst-add="' + p.id + '">Add</button>'
        + '</div>';
    }).join('');

    var cats = '<option value="">No category</option>' + state.categories.map(function (c) {
      return '<option value="' + c.id + '"' + (String(c.id) === String(s.category_id || '') ? ' selected' : '') + '>' + esc(c.name) + '</option>';
    }).join('');

    var gallery = s.images.map(function (u, i) {
      return '<span class="kst-img" style="background-image:url(\'' + esc(u) + '\')">'
        + '<button type="button" data-kst-img-rm="' + i + '" aria-label="Remove image">&#10005;</button></span>';
    }).join('');

    return '<div class="kst-card">'
      + '<div class="kst-actions" style="justify-content:space-between">'
        + '<div class="kst-title">' + (s.id ? 'Edit set' : 'New set') + '</div>'
        + '<div class="kst-actions">'
          + '<button class="kst-btn" data-kst-back>Back</button>'
          + '<button class="kst-btn is-primary" data-kst-save ' + (state.busy ? 'disabled' : '') + '>Save set</button>'
        + '</div>'
      + '</div>'
      + '<div class="kst-grid" style="margin-top:14px">'
        + field('Set name', '<input type="text" data-kst-f="name" value="' + esc(s.name) + '" maxlength="200">')
        + field('Status', '<select data-kst-f="status">'
            + ['publish', 'draft', 'private'].map(function (v) {
                return '<option value="' + v + '"' + (s.status === v ? ' selected' : '') + '>' + v + '</option>';
              }).join('') + '</select>')
        + field('Category', '<select data-kst-f="category_id">' + cats + '</select>')
        + field('Set price (AED)', '<input type="text" inputmode="decimal" data-kst-f="price_aed" value="' + esc(s.price_aed) + '">')
        + field('Sale price (AED)', '<input type="text" inputmode="decimal" data-kst-f="sale_price_aed" value="' + esc(s.sale_price_aed) + '">')
        + field('Visible in the shop', '<select data-kst-f="is_visible">'
            + '<option value="1"' + (s.is_visible ? ' selected' : '') + '>Yes</option>'
            + '<option value="0"' + (s.is_visible ? '' : ' selected') + '>No</option></select>')
      + '</div>'
      + '<div class="kst-f" style="margin-top:12px"><label>Short description</label>'
        + '<textarea data-kst-f="short_description" style="min-height:60px">' + esc(s.short_description) + '</textarea></div>'
      + '<div class="kst-f" style="margin-top:12px"><label>Description</label>'
        + '<textarea data-kst-f="description">' + esc(s.description) + '</textarea>'
        + '<div class="kst-help">Printed on the set\'s own product page, exactly as a product description is.</div></div>'
      + '<div class="kst-f" style="margin-top:12px"><label>Images</label>'
        + '<div class="kst-imgs">'
          + '<span class="kst-img" style="' + (s.image ? 'background-image:url(\'' + esc(s.image) + '\')' : '') + '"></span>'
          + gallery
        + '</div>'
        + '<div class="kst-actions" style="margin-top:8px">'
          + '<button class="kst-mini" data-kst-pick-main>Choose main image</button>'
          + '<button class="kst-mini" data-kst-pick-gallery>Add gallery images</button>'
        + '</div>'
        + '<div class="kst-help">Both open the Media Library. There is no file box on this screen '
        + 'on purpose — every upload in this back office goes through the library.</div></div>'
      + '</div>'

      + '<div class="kst-card"><div class="kst-title">What is in the box</div>'
        + '<div class="kst-sum" style="margin-top:8px">'
          + '<span>Items: <b>' + t.count + '</b></span>'
          + '<span>Bought separately: <b>AED ' + esc(money(t.parts)) + '</b></span>'
          + '<span>Set price: <b>AED ' + esc(money(s.price_aed)) + '</b></span>'
          + '<span class="kst-save">Saving: <b>AED ' + esc(money(t.saving)) + '</b></span>'
        + '</div>'
        + '<div class="kst-two" style="margin-top:14px">'
          + '<div><div class="kst-meta" style="margin-bottom:6px">In this set</div>'
            + '<div class="kst-list">' + (members || '<div class="kst-note">Nothing chosen yet.</div>') + '</div></div>'
          + '<div><div class="kst-meta" style="margin-bottom:6px">Choose products</div>'
            + '<input type="search" placeholder="Search by name or SKU" data-kst-q value="' + esc(state.q) + '" '
            + 'style="width:100%;box-sizing:border-box;font:inherit;font-size:13px;padding:8px 10px;'
            + 'border:1px solid var(--border,#e6e9f2);border-radius:9px">'
            + '<div class="kst-list" style="margin-top:8px">' + (found || '<div class="kst-note">Search for a product.</div>') + '</div></div>'
        + '</div></div>';
  }

  function field(label, control) {
    return '<div class="kst-f"><label>' + esc(label) + '</label>' + control + '</div>';
  }

  /* ------------------------------------------------------------------ reads */

  async function load() {
    state.busy = true; state.error = null; render();
    try {
      var body = await api('/sets');
      state.sets = body.sets || [];
      state.categories = body.categories || [];
    } catch (e) {
      state.error = explain(e, 'The list of sets could not be loaded.');
    }
    state.busy = false;
    render();
  }

  async function search() {
    /* ▲ COLLECT BEFORE THE RE-RENDER, and this is a real defect that a
       screenshot found rather than a reader.

       The search box re-renders the screen when its results land, and a
       re-render rewrites every field from `state`. Without this line the name,
       the price, the category and both descriptions were rewritten from
       whatever `state` held BEFORE the operator typed them -- so searching for
       a product to put in the set silently emptied the price box above it. Seen
       on the create shot: "Set price: AED 0.00" under a box that had just been
       filled with 179.00. */
    collect();

    try {
      var body = await api('/sets/products?q=' + encodeURIComponent(state.q));
      state.found = body.products || [];
    } catch (e) {
      state.found = [];
      state.error = explain(e, 'That search could not be run.');
    }
    render();
  }

  function blank() {
    return {
      id: null, name: '', slug: '', status: 'draft', is_visible: true, category_id: '',
      short_description: '', description: '', price_aed: '0.00', sale_price_aed: '',
      image: null, images: [], members: []
    };
  }

  /* ----------------------------------------------------------------- writes */

  function collect() {
    if (!state.editing) return;
    document.querySelectorAll('[data-kst-f]').forEach(function (el) {
      var k = el.getAttribute('data-kst-f');
      state.editing[k] = k === 'is_visible' ? el.value === '1' : el.value;
    });
  }

  async function save() {
    collect();
    var s = state.editing;

    if (!s.members.length) { say('A set has to contain at least one product.'); return; }

    var payload = {
      name: s.name, status: s.status, is_visible: s.is_visible,
      category_id: s.category_id === '' ? null : Number(s.category_id),
      short_description: s.short_description, description: s.description,
      price: String(s.price_aed || '0'),
      sale_price: String(s.sale_price_aed || '') === '' ? null : String(s.sale_price_aed),
      image: s.image, images: s.images,
      members: s.members.map(function (m) {
        return { product_id: m.product_id, variant_id: m.variant_id || null, quantity: Number(m.quantity || 1) };
      })
    };

    state.busy = true; state.error = null; render();
    try {
      var body = s.id
        ? await api('/sets/' + s.id, payload, 'PUT')
        : await api('/sets', payload, 'POST');
      say('Set saved.');
      await load();
      state.editing = body.set;
    } catch (e) {
      state.error = explain(e, 'That set could not be saved.');
    }
    state.busy = false;
    render();
  }

  function move(i, d) {
    var list = state.editing.members;
    var j = i + d;
    if (j < 0 || j >= list.length) return;
    var tmp = list[i]; list[i] = list[j]; list[j] = tmp;
    render();
  }

  /* ------------------------------------------------------------- the events */

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var hit = function (a) { var n = t.closest('[' + a + ']'); return n ? n.getAttribute(a) : null; };

    if (t.closest('[data-kst-new]')) { state.editing = blank(); state.found = []; state.q = ''; render(); return; }
    if (t.closest('[data-kst-back]')) { state.editing = null; render(); return; }
    if (t.closest('[data-kst-save]')) { save(); return; }

    var edit = hit('data-kst-edit');
    if (edit) {
      (async function () {
        try {
          var body = await api('/sets/' + edit);
          state.editing = body.set; state.found = []; state.q = '';
        } catch (err) { state.error = explain(err, 'That set could not be opened.'); }
        render();
      })();
      return;
    }

    var del = hit('data-kst-del');
    if (del) {
      if (!window.confirm('Delete this set? The products in it are not touched.')) return;
      (async function () {
        try { await api('/sets/' + del, undefined, 'DELETE'); say('Set deleted.'); await load(); }
        catch (err) { state.error = explain(err, 'That set could not be deleted.'); render(); }
      })();
      return;
    }

    if (!state.editing) return;

    var add = hit('data-kst-add');
    if (add) {
      var p = state.found.filter(function (x) { return String(x.id) === String(add); })[0];
      if (!p) return;
      var sel = document.querySelector('[data-kst-var="' + p.id + '"]');
      var variantId = sel && sel.value ? Number(sel.value) : null;
      var already = state.editing.members.some(function (m) {
        return String(m.product_id) === String(p.id) && String(m.variant_id || '') === String(variantId || '');
      });
      if (already) { say('That product is already in this set. Raise its quantity instead.'); return; }
      collect();
      state.editing.members.push({
        product_id: p.id, variant_id: variantId, quantity: 1,
        name: p.name, brand: p.brand, sku: p.sku, image: p.image, unit_price_aed: p.price_aed
      });
      render(); return;
    }

    var rm = hit('data-kst-rm');
    if (rm !== null) { collect(); state.editing.members.splice(Number(rm), 1); render(); return; }

    var up = hit('data-kst-up');
    if (up !== null) { collect(); move(Number(up), -1); return; }

    var down = hit('data-kst-down');
    if (down !== null) { collect(); move(Number(down), 1); return; }

    var imgRm = hit('data-kst-img-rm');
    if (imgRm !== null) { collect(); state.editing.images.splice(Number(imgRm), 1); render(); return; }

    if (t.closest('[data-kst-pick-main]')) {
      collect();
      window.kbbPickMedia({ title: 'Set image', onPick: function (picked) {
        var one = Array.isArray(picked) ? picked[0] : picked;
        state.editing.image = (one && (one.url || one)) || null;
        render();
      } });
      return;
    }

    if (t.closest('[data-kst-pick-gallery]')) {
      collect();
      window.kbbPickMedia({ multiple: true, title: 'Set gallery', onPick: function (picked) {
        var many = Array.isArray(picked) ? picked : [picked];
        many.forEach(function (one) {
          var url = one && (one.url || one);
          if (url && state.editing.images.indexOf(url) === -1) state.editing.images.push(url);
        });
        render();
      } });
      return;
    }
  });

  /* The quantity boxes and the search box. `input`, not `change`: a number
     typed and then immediately Saved would otherwise never fire. */
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.getAttribute || !state.editing) return;

    var mq = t.getAttribute('data-kst-mq');
    if (mq !== null) {
      state.editing.members[Number(mq)].quantity = Math.max(1, Math.min(99, Number(t.value) || 1));
      var sum = document.querySelector('.kst-sum');
      if (sum) {
        /* The totals line is rewritten in place rather than re-rendering the
           screen, because re-rendering would take the focus out of the box the
           operator is still typing in. */
        var tt = totals();
        sum.innerHTML = '<span>Items: <b>' + tt.count + '</b></span>'
          + '<span>Bought separately: <b>AED ' + esc(money(tt.parts)) + '</b></span>'
          + '<span>Set price: <b>AED ' + esc(money(state.editing.price_aed)) + '</b></span>'
          + '<span class="kst-save">Saving: <b>AED ' + esc(money(tt.saving)) + '</b></span>';
      }
      return;
    }

    if (t.hasAttribute('data-kst-q')) {
      state.q = t.value;
      clearTimeout(window.__kstSearch);
      window.__kstSearch = setTimeout(search, 220);
    }
  });

  /* ---------------------------------------------------------------- routing */

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Sets',
      icon: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18"/><path d="M12 7V4"/><path d="M8 4h8"/>',
      group: 'Catalog',
      after: ['catalog']
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
    if (title) title.textContent = 'Sets';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    state.editing = null;
    /* render() BEFORE load(), synchronously. That is the condition
       LATE_RENDERED carries in app.blade.php: the deep-link replay's marker
       inside #content has to be destroyed by the time its task runs, or the
       screen is drawn twice. */
    render();
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
