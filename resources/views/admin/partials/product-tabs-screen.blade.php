{{--
    Catalog → Product tabs.  (Lane PT)

    The owner's words:

        "the Product Tabs i want that user can create a new tabs for any
         product, or set as global for all products too. make a nice
         functionality and give full control."

    ── WHAT THIS SCREEN IS, AND WHY IT IS ONE SCREEN ──────────────────────────

    Two halves of one job, on one page:

      GLOBAL TABS        a tab written once that appears on every product —
                         Shipping & returns, How we authenticate, Ingredients
                         policy. Title, rich body, an Arabic box beside each,
                         an order, and an on/off that does not delete it.

      THIS PRODUCT'S TABS  pick a product and you get its whole picture: every
                         tab it inherits (the three built-in ones and every
                         global) with what it has done about each, plus tabs
                         that exist on it and nowhere else.

    ONE SCREEN AND NOT TWO, and not a panel bolted into the product editor,
    because the authoring control is IDENTICAL in both scopes — title, Arabic
    title, rich body, Arabic body, position, on/off — and a second copy of it
    inside a 4,700-line editor owned by another lane would be a second
    implementation of one form. The first correction to either would leave the
    other wrong. The product editor gets a one-line launcher instead; the lines
    are in this lane's report for the integrator.

    ── THE ORDER IS ONE SCALE, AND THE SCREEN SHOWS IT ────────────────────────

    App\Support\ProductTabs is the design and its header carries the argument.
    What matters here is that the owner SEES it: the three built-in tabs are
    drawn in the same ordered list as the authored ones, greyed and not
    editable, so moving "Shipping & returns" above "Ingredients" is a thing you
    can see before you save it.

    ── MOVE UP / MOVE DOWN, NOT DRAG AND DROP ────────────────────────────────

    Deliberate. The owner reviews this console on a phone at 390px, and a
    drag-and-drop list is the control that works worst there — a long press that
    competes with page scroll, on rows that are taller than the viewport once
    they carry an Arabic box. Two buttons work with a thumb, work with a
    keyboard, and are announceable. The whole arrangement goes back in ONE
    request (POST /product-tabs/order), so a re-order is never half saved.

    ── HOW THIS FILE JOINS THE CONSOLE ────────────────────────────────────────

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry and toast(). It registers its OWN
    sidebar entry and wraps window.go, exactly as the screens beside it do, so
    ONE @include is the whole of the change to that file.

    ── NOTHING HERE UPLOADS A FILE ────────────────────────────────────────────

    There is no file input on this screen. A tab body may carry an image the
    sanitiser allows, and the URL for one comes from window.kbbPickMedia like
    every other image in this console — never from a second upload endpoint.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ────────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included
    — with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    ── LAYOUT AND ESCAPING ────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px. Every grid and flex
    child that can hold something wide carries min-width:0, because a grid
    item's default min-width is auto.

    EVERY CLASS IS PREFIXED kpt- AND EVERY data- ATTRIBUTE data-kpt-, and both
    appear nowhere else in the console: app.blade.php binds delegated listeners
    to `document` itself, each claiming a bare attribute name. That includes
    DESCENDANT selectors — `.kpt-card .k` would be a rule on a bare class name
    and would restyle other screens.

    esc() ON EVERY INTERPOLATION of a tab title, a product name and a server
    error. CLAUDE.md rule 5: anything printed unescaped is a constant, never a
    setting. The ONE exception is a tab BODY written into the rich-text pane,
    which is the operator's own HTML and is the thing being edited — and it has
    already been through App\Support\RichText::clean() on the server before it
    could be stored, which is where that guarantee is made.
--}}
@verbatim
<style>
/* Catalog → Product tabs. Built on the console's own tokens — --border, --r/
   --r-sm/--r-xs, --sh-s, --surface-2/3, the ink scale — so the screen follows
   the console theme instead of naming literals that cannot. */
.kpt-wrap{display:grid;gap:16px;min-width:0}
.kpt-wrap > *{min-width:0}
.kpt-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
  border-radius:var(--r,18px);padding:18px;min-width:0;box-shadow:var(--sh-s)}
.kpt-title{font-weight:680;font-size:15.5px;letter-spacing:-.01em;color:var(--ink,#101729)}
.kpt-sub{color:var(--ink-soft,#626c80);font-size:12.5px;line-height:1.55;margin-top:4px;max-width:68ch}
.kpt-note{border:1px solid var(--border,#e6e9f2);background:var(--surface-2,#f2f4fb);
  border-radius:var(--r-sm,12px);padding:11px 13px;font-size:12.5px;line-height:1.55;
  color:var(--ink-soft,#626c80);min-width:0;margin-top:12px}
.kpt-note.is-bad{border-color:#f3c9c6;background:var(--red-soft,#fdeceb);color:#9d332c}
.kpt-head{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;justify-content:space-between;min-width:0}
.kpt-head > div{min-width:0;flex:1 1 220px}
.kpt-btn{padding:8px 14px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
  background:var(--surface,#fff);color:var(--ink-2,#3c465c);font:inherit;font-size:12.5px;
  font-weight:600;cursor:pointer;max-width:100%}
.kpt-btn:hover{background:var(--surface-2,#f2f4fb)}
.kpt-btn.is-primary{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.kpt-btn.is-primary:hover{filter:brightness(1.05)}
.kpt-mini{padding:5px 10px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
  background:var(--surface,#fff);color:var(--ink-2,#3c465c);font:inherit;font-size:11.5px;
  font-weight:600;cursor:pointer;flex:none}
.kpt-mini:hover{background:var(--surface-2,#f2f4fb)}
.kpt-mini.is-danger{color:var(--red,#e3493f)}
.kpt-mini[disabled]{opacity:.4;cursor:default}

/* The ordered list. One row per tab, whatever kind it is. */
.kpt-list{display:grid;gap:8px;min-width:0;margin-top:14px}
.kpt-row{border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);
  background:var(--surface-2,#f2f4fb);min-width:0;overflow:hidden}
.kpt-row.is-builtin{background:var(--surface,#fff)}
.kpt-rh{display:flex;align-items:center;gap:8px;padding:9px 11px;min-width:0;flex-wrap:wrap}
.kpt-nm{font-size:12.5px;font-weight:620;color:var(--ink,#101729);line-height:1.35;
  min-width:0;flex:1 1 150px;overflow-wrap:anywhere}
.kpt-meta{display:block;font-size:11.5px;font-weight:500;color:var(--ink-soft,#626c80);line-height:1.4}
.kpt-pill{font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
  border-radius:99px;padding:2px 8px;background:var(--surface-3,#e9ecf6);
  color:var(--ink-soft,#626c80);flex:none}
.kpt-pill.is-on{background:#e4f5ea;color:#1c6b36}
.kpt-pill.is-off{background:#e9ecf6;color:#626c80}
.kpt-pill.is-over{background:#fdf0d5;color:#8a5a00}
.kpt-pill.is-hidden{background:#fdeceb;color:#9d332c}
.kpt-ord{display:flex;gap:4px;flex:none}

/* The editor, revealed under a row. */
.kpt-edit{border-top:1px solid var(--border,#e6e9f2);background:var(--surface,#fff);
  padding:14px 12px;display:grid;gap:12px;min-width:0}
.kpt-f{display:grid;gap:5px;min-width:0}
.kpt-f label{font-size:11.5px;font-weight:650;color:var(--ink-soft,#626c80);letter-spacing:.02em}
.kpt-f input[type=text],.kpt-f input[type=number],.kpt-f select{width:100%;box-sizing:border-box;
  font:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--border,#e6e9f2);
  border-radius:var(--r-xs,9px);background:var(--surface,#fff);color:var(--ink,#101729);min-width:0}
.kpt-hint{font-size:11px;color:var(--ink-soft,#626c80);line-height:1.5}

/* The rich-text pane. Same shape as the Pages editor's, with its own prefix. */
.kpt-rte{border:1px solid var(--border,#e6e9f2);border-radius:10px;overflow:hidden;min-width:0}
.kpt-rte-bar{display:flex;flex-wrap:wrap;gap:3px;padding:6px;background:var(--surface-2,#f2f4fb);
  border-bottom:1px solid var(--border,#e6e9f2)}
.kpt-rte-bar button{font:inherit;font-size:11.5px;font-weight:700;color:var(--ink-2,#3c465c);
  background:transparent;border:1px solid transparent;border-radius:6px;padding:4px 8px;cursor:pointer}
.kpt-rte-bar button:hover{background:var(--surface,#fff);border-color:var(--border,#e6e9f2)}
.kpt-rte-area{padding:12px;font-size:13.5px;line-height:1.7;min-height:150px;outline:0;
  background:var(--surface,#fff);color:var(--ink,#101729);overflow-wrap:break-word}
.kpt-rte-area:empty:before{content:attr(data-ph);color:var(--ink-faint,#9aa3b5)}
.kpt-rte-area p{margin:0 0 .7em}
.kpt-rte-area h2{font-size:17px;margin:.7em 0 .35em}
.kpt-rte-area h3{font-size:15px;margin:.7em 0 .35em}
.kpt-rte-area ul,.kpt-rte-area ol{margin:0 0 .7em;padding-inline-start:1.4em}
.kpt-rte-area img{max-width:100%;height:auto}

/* The product picker. */
.kpt-search{display:flex;gap:8px;flex-wrap:wrap;min-width:0;margin-top:12px}
.kpt-search input{flex:1 1 200px;min-width:0;box-sizing:border-box;font:inherit;font-size:13px;
  padding:8px 10px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
  background:var(--surface,#fff);color:var(--ink,#101729)}
.kpt-hits{display:grid;gap:6px;margin-top:10px;min-width:0}
.kpt-hit{display:flex;align-items:center;gap:9px;padding:8px 10px;min-width:0;width:100%;
  border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);
  background:var(--surface,#fff);cursor:pointer;text-align:start;font:inherit}
.kpt-hit:hover{background:var(--surface-2,#f2f4fb)}
.kpt-th{width:36px;height:36px;border-radius:8px;flex:none;background-size:cover;
  background-position:center;background-color:var(--surface-2,#f2f4fb);
  border:1px solid var(--border,#e6e9f2)}
.kpt-hit .kpt-nm{flex:1 1 120px}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'product-tabs';

  /* `boot` stays null until /admin-api/product-tabs has answered, and STAYS
     null on a 404 or a 403 — so the screen shows one explanation rather than an
     empty form that cannot save. */
  var state = {
    boot: null,
    globals: [],
    builtins: [],
    error: null,
    open: null,          /* 'global:<id>' | 'own:<id>' | 'new-global' | 'new-own' */
    product: null,       /* the picked product */
    inherited: [],
    own: [],
    hits: null,
    query: '',
    draft: null          /* the row being edited, held here so a re-render keeps typing */
  };

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
     names the symptom and hides the cause. */
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
      return 'The Product tabs endpoints are not in this server\'s compiled route table yet. '
           + 'Clear the route cache and reload.';
    }
    if (e && e.status === 403) {
      return 'Your account does not hold the Product tabs capability.';
    }
    if (e && e.body && e.body.errors) {
      var first = Object.keys(e.body.errors)[0];
      if (first) return String(e.body.errors[first][0]);
    }
    if (e && e.body && e.body.message) return String(e.body.message);
    return fallback;
  }

  /* ------------------------------------------------------------- the forms */

  /* One Arabic box, or nothing at all if the shape does not carry the field.
     KBBArabic.boxIf asks the SERVER which fields are translatable rather than
     this screen holding its own copy of the model's allowlist — so a field
     added to ProductTab::$translatable grows a box here without this file
     being touched, and a box is never offered for a field the server would
     silently drop. */
  function arabic(field, label, type, prefill, value) {
    if (!window.KBBArabic) return '';

    return KBBArabic.boxIf(prefill, {
      field: field,
      label: label,
      type: type,
      prefill: prefill,
      value: value,
      maxlength: type === 'rich' ? null : 120
    });
  }

  /* The rich-text pane. The BODY is written in unescaped, and it is the one
     interpolation on this screen that is: it is the operator's own HTML, it is
     the thing being edited, and App\Support\RichText::clean() ran over it on
     the server before it could be stored. */
  function rte(html) {
    return '<div class="kpt-rte">'
      + '<div class="kpt-rte-bar">'
      +   '<button type="button" data-kpt-cmd="bold">B</button>'
      +   '<button type="button" data-kpt-cmd="italic"><i>I</i></button>'
      +   '<button type="button" data-kpt-cmd="formatBlock:h3">H3</button>'
      +   '<button type="button" data-kpt-cmd="formatBlock:p">P</button>'
      +   '<button type="button" data-kpt-cmd="insertUnorderedList">List</button>'
      +   '<button type="button" data-kpt-cmd="insertOrderedList">1.</button>'
      +   '<button type="button" data-kpt-cmd="createLink">Link</button>'
      + '</div>'
      + '<div class="kpt-rte-area" contenteditable="true" data-kpt-body '
      +   'data-ph="What this tab says. Headings, bold, lists and links all work.">'
      + (html || '')
      + '</div></div>';
  }

  /* The editor under a row. `row` is null for a brand new tab. */
  function form(row, kind) {
    var prefill = (row && row.translations) || (state.boot && state.boot.translations) || null;

    return '<div class="kpt-edit">'
      + '<div class="kpt-f">'
      +   '<label for="kptTitle">Tab title</label>'
      +   '<input type="text" id="kptTitle" data-kpt-title maxlength="120" '
      +     'value="' + esc(row ? row.title : '') + '" placeholder="Shipping &amp; returns">'
      +   '<p class="kpt-hint">The word on the tab. It is a heading, not a paragraph.</p>'
      +   arabic('title', 'Tab title', 'text', prefill, row ? undefined : '')
      + '</div>'
      + '<div class="kpt-f">'
      +   '<label>What the tab says</label>'
      +   rte(row ? row.body : '')
      +   arabic('body', 'What the tab says', 'rich', prefill, row ? undefined : '')
      + '</div>'
      + '<div class="kpt-f" style="max-width:220px">'
      +   '<label for="kptOn">Show this tab</label>'
      +   '<select id="kptOn" data-kpt-on>'
      +     '<option value="1"' + (!row || row.is_enabled ? ' selected' : '') + '>Yes, show it</option>'
      +     '<option value="0"' + (row && !row.is_enabled ? ' selected' : '') + '>No, keep it but hide it</option>'
      +   '</select>'
      + '</div>'
      + '<div class="kpt-ord" style="flex-wrap:wrap;gap:8px">'
      +   '<button class="kpt-btn is-primary" data-kpt-save="' + esc(kind) + '">Save this tab</button>'
      +   '<button class="kpt-btn" data-kpt-cancel>Cancel</button>'
      + '</div></div>';
  }

  /* ------------------------------------------------------------- the rows */

  function orderButtons(scope, id, first, last) {
    return '<span class="kpt-ord">'
      + '<button class="kpt-mini" data-kpt-move="up" data-kpt-scope="' + esc(scope) + '" '
      +   'data-kpt-id="' + esc(id) + '"' + (first ? ' disabled' : '') + '>&uarr;</button>'
      + '<button class="kpt-mini" data-kpt-move="down" data-kpt-scope="' + esc(scope) + '" '
      +   'data-kpt-id="' + esc(id) + '"' + (last ? ' disabled' : '') + '>&darr;</button>'
      + '</span>';
  }

  /* A built-in tab, drawn in the SAME ordered list as the authored ones. Greyed
     and not editable: its words are three columns on each product, written in
     the product editor, so there is no one body to show here. The row exists so
     the owner can see where Description sits in the order. */
  function builtinRow(tab) {
    return '<div class="kpt-row is-builtin">'
      + '<div class="kpt-rh">'
      +   '<span class="kpt-nm">' + esc(tab.label)
      +     '<span class="kpt-meta">Built in &middot; each product writes its own, in the product editor</span></span>'
      +   '<span class="kpt-pill">Built in</span>'
      +   '<span class="kpt-pill">' + esc(tab.position) + '</span>'
      + '</div></div>';
  }

  function globalRow(tab, first, last) {
    var key = 'global:' + tab.id;
    var open = state.open === key;

    return '<div class="kpt-row">'
      + '<div class="kpt-rh">'
      +   '<span class="kpt-nm">' + esc(tab.title)
      +     '<span class="kpt-meta">On every product</span></span>'
      +   '<span class="kpt-pill ' + (tab.is_enabled ? 'is-on">Showing' : 'is-off">Switched off') + '</span>'
      +   orderButtons('global', tab.id, first, last)
      +   '<button class="kpt-mini" data-kpt-open="' + esc(key) + '">'
      +     (open ? 'Close' : 'Edit') + '</button>'
      +   '<button class="kpt-mini is-danger" data-kpt-del="' + esc(tab.id) + '">Delete</button>'
      + '</div>'
      + (open ? form(tab, key) : '')
      + '</div>';
  }

  /* --------------------------------------------------------- global tabs */

  function globalsCard() {
    if (!state.boot) return '';

    /* Built-ins and globals interleaved BY POSITION, because that is what the
       shop does. Seeing them in two lists would hide the one decision this
       screen exists to make visible. */
    var rows = [];

    state.builtins.forEach(function (b) {
      rows.push({ position: b.position, kind: 'builtin', tab: b });
    });

    state.globals.forEach(function (g) {
      rows.push({ position: g.position, kind: 'global', tab: g });
    });

    rows.sort(function (a, b) { return a.position - b.position; });

    var firstGlobal = null;
    var lastGlobal = null;

    state.globals.forEach(function (g) {
      if (firstGlobal === null) firstGlobal = g.id;
      lastGlobal = g.id;
    });

    var listed = rows.map(function (r) {
      if (r.kind === 'builtin') return builtinRow(r.tab);
      return globalRow(r.tab, r.tab.id === firstGlobal, r.tab.id === lastGlobal);
    }).join('');

    return '<div class="kpt-card">'
      + '<div class="kpt-head">'
      +   '<div><div class="kpt-title">Global tabs</div>'
      +   '<div class="kpt-sub">A tab you write once that appears on <b>every</b> product &mdash; '
      +   'Shipping &amp; returns, How we authenticate, an ingredients policy. The three greyed rows '
      +   'are the tabs the shop has always had; each product writes those itself, in the product '
      +   'editor. Everything sorts on one order, so a tab you add can sit before Ingredients or '
      +   'after How to use &mdash; wherever you put it.</div></div>'
      +   '<button class="kpt-btn is-primary" data-kpt-open="new-global">Add a global tab</button>'
      + '</div>'
      + (state.open === 'new-global' ? '<div class="kpt-list"><div class="kpt-row">'
          + form(null, 'new-global') + '</div></div>' : '')
      + '<div class="kpt-list">' + (listed || '') + '</div>'
      + (state.globals.length ? '' : '<div class="kpt-note">No global tabs yet. '
          + 'Nothing on the shop has changed &mdash; every product page still shows exactly the '
          + 'tabs it showed before.</div>')
      + '</div>';
  }

  /* ------------------------------------------------------ one product */

  function hitsList() {
    if (state.hits === null) return '';

    if (!state.hits.length) {
      return '<div class="kpt-note">Nothing matched &ldquo;' + esc(state.query) + '&rdquo;. '
        + 'Try part of a product name, a SKU or a slug.</div>';
    }

    return '<div class="kpt-hits">' + state.hits.map(function (p) {
      return '<button class="kpt-hit" data-kpt-pick="' + esc(p.id) + '">'
        + '<span class="kpt-th" style="' + (p.image ? 'background-image:url(\'' + esc(p.image) + '\')' : '') + '"></span>'
        + '<span class="kpt-nm">' + esc(p.name)
        +   '<span class="kpt-meta">' + esc(p.status) + (p.sku ? ' &middot; ' + esc(p.sku) : '') + '</span></span>'
        + (p.has_tabs ? '<span class="kpt-pill is-on">Has tabs</span>' : '')
        + '</button>';
    }).join('') + '</div>';
  }

  /* An inherited tab, and what this product has done about it.

     THREE STATES, NAMED IN WORDS rather than implied by shading, because
     "is this one mine or the shop's?" is the question this whole half of the
     screen exists to answer. */
  function inheritedRow(tab) {
    var body = '';

    if (tab.state === 'inherited') {
      body = '<span class="kpt-pill">Inherited</span>'
        + '<button class="kpt-mini" data-kpt-over="' + esc(tab.key) + '">Override here</button>'
        + '<button class="kpt-mini" data-kpt-hide="' + esc(tab.key) + '">Hide on this product</button>';
    } else if (tab.state === 'overridden') {
      body = '<span class="kpt-pill is-over">Overridden</span>'
        + '<button class="kpt-mini" data-kpt-over="' + esc(tab.key) + '">Edit the override</button>'
        + '<button class="kpt-mini" data-kpt-revert="' + esc(tab.key) + '">Use the shop&rsquo;s one</button>';
    } else {
      body = '<span class="kpt-pill is-hidden">Hidden here</span>'
        + '<button class="kpt-mini" data-kpt-revert="' + esc(tab.key) + '">Show it again</button>';
    }

    var open = state.open === 'over:' + tab.key;

    return '<div class="kpt-row' + (tab.kind === 'builtin' ? ' is-builtin' : '') + '">'
      + '<div class="kpt-rh">'
      +   '<span class="kpt-nm">' + esc(tab.state === 'overridden' && tab.row && tab.row.title
            ? tab.row.title : tab.label)
      +     '<span class="kpt-meta">'
      +       (tab.kind === 'builtin' ? 'Built in' : 'Global tab')
      +       (tab.state === 'overridden' && tab.row && tab.row.title
              ? ' &middot; the shop calls it &ldquo;' + esc(tab.label) + '&rdquo;' : '')
      +     '</span></span>'
      +   body
      + '</div>'
      + (open ? overrideForm(tab) : '')
      + '</div>';
  }

  /* The override form. EVERY BOX EMPTY MEANS INHERIT — it is not a blank value,
     and the hint says so in words, because the alternative reading ("blank
     wipes the heading") would silently delete the tab: an empty title is what
     the storefront drops a tab on. */
  function overrideForm(tab) {
    var row = tab.row;
    var prefill = (row && row.translations) || (state.boot && state.boot.translations) || null;

    return '<div class="kpt-edit">'
      + '<div class="kpt-note">Leave a box <b>empty</b> and this product uses the shop&rsquo;s own '
      + 'wording for it. Fill one in and only this product changes.</div>'
      + '<div class="kpt-f">'
      +   '<label for="kptTitle">Tab title on this product</label>'
      +   '<input type="text" id="kptTitle" data-kpt-title maxlength="120" '
      +     'value="' + esc(row ? row.title : '') + '" placeholder="' + esc(tab.label) + '">'
      +   arabic('title', 'Tab title', 'text', prefill, row ? undefined : '')
      + '</div>'
      + '<div class="kpt-f">'
      +   '<label>What the tab says on this product</label>'
      +   rte(row ? row.body : '')
      +   arabic('body', 'What the tab says', 'rich', prefill, row ? undefined : '')
      + '</div>'
      + '<div class="kpt-ord" style="flex-wrap:wrap;gap:8px">'
      +   '<button class="kpt-btn is-primary" data-kpt-saveover="' + esc(tab.key) + '">Save the override</button>'
      +   '<button class="kpt-btn" data-kpt-cancel>Cancel</button>'
      + '</div></div>';
  }

  function ownRow(tab, first, last) {
    var key = 'own:' + tab.id;
    var open = state.open === key;

    return '<div class="kpt-row">'
      + '<div class="kpt-rh">'
      +   '<span class="kpt-nm">' + esc(tab.title)
      +     '<span class="kpt-meta">On this product only</span></span>'
      +   '<span class="kpt-pill ' + (tab.is_enabled ? 'is-on">Showing' : 'is-off">Switched off') + '</span>'
      +   orderButtons('own', tab.id, first, last)
      +   '<button class="kpt-mini" data-kpt-open="' + esc(key) + '">' + (open ? 'Close' : 'Edit') + '</button>'
      +   '<button class="kpt-mini is-danger" data-kpt-del="' + esc(tab.id) + '">Delete</button>'
      + '</div>'
      + (open ? form(tab, key) : '')
      + '</div>';
  }

  function productCard() {
    if (!state.boot) return '';

    var picker = '<div class="kpt-search">'
      + '<input type="text" data-kpt-q placeholder="Search by name, SKU or slug" '
      +   'value="' + esc(state.query) + '">'
      + '<button class="kpt-btn" data-kpt-find>Find</button>'
      + (state.product ? '<button class="kpt-btn" data-kpt-clear>Pick another product</button>' : '')
      + '</div>';

    if (!state.product) {
      return '<div class="kpt-card">'
        + '<div class="kpt-title">This product&rsquo;s tabs</div>'
        + '<div class="kpt-sub">Pick a product to give it a tab of its own, or to hide or re-word '
        + 'one of the tabs it inherits from the list above.</div>'
        + picker + hitsList() + '</div>';
    }

    var firstOwn = state.own.length ? state.own[0].id : null;
    var lastOwn = state.own.length ? state.own[state.own.length - 1].id : null;

    return '<div class="kpt-card">'
      + '<div class="kpt-head">'
      +   '<div><div class="kpt-title">' + esc(state.product.name) + '</div>'
      +   '<div class="kpt-sub">Everything this product shows, and what it has done about each.</div></div>'
      +   '<button class="kpt-btn is-primary" data-kpt-open="new-own">Add a tab to this product</button>'
      + '</div>'
      + picker + hitsList()
      + '<div class="kpt-list">'
      +   state.inherited.map(inheritedRow).join('')
      + '</div>'
      + (state.open === 'new-own' ? '<div class="kpt-list"><div class="kpt-row">'
          + form(null, 'new-own') + '</div></div>' : '')
      + '<div class="kpt-list">'
      +   state.own.map(function (t) { return ownRow(t, t.id === firstOwn, t.id === lastOwn); }).join('')
      + '</div>'
      + (state.own.length ? '' : '<div class="kpt-note">This product has no tabs of its own yet.</div>')
      + '</div>';
  }

  /* ----------------------------------------------------------------- render */

  function render() {
    var el = document.querySelector('#content');
    if (!el) return;

    el.innerHTML = '<div class="wrap kpt-wrap">'
      + (state.error ? '<div class="kpt-note is-bad">' + esc(state.error) + '</div>' : '')
      + globalsCard()
      + productCard()
      + '</div>';

    /* Idempotent, and it also reveals the Translate buttons — which are drawn
       hidden, so with no API key configured they never appear at all and every
       manual path on this screen works with no key and no bill. */
    if (window.KBBArabic) { try { KBBArabic.wire(el); } catch (e) {} }
  }

  /* ------------------------------------------------------------------ reads */

  async function load() {
    state.error = null;

    try {
      var body = await api('/product-tabs');
      state.boot = body;
      state.globals = body.tabs || [];
      state.builtins = body.builtins || [];
    } catch (e) {
      state.boot = null;
      state.error = explain(e, 'The product tabs could not be loaded.');
    }

    render();
  }

  async function loadProduct(id) {
    try {
      var body = await api('/product-tabs/product/' + Number(id));
      state.product = body.product;
      state.inherited = body.inherited || [];
      state.own = body.own || [];
      state.hits = null;
      state.open = null;
    } catch (e) {
      state.error = explain(e, 'That product\'s tabs could not be loaded.');
    }

    render();
  }

  /* ----------------------------------------------------------------- writes */

  /* Read the open editor back out of the DOM.

     The Arabic half goes through KBBArabic.collect(), which returns {} when the
     screen drew no boxes at all — an ABSENT bag leaves existing translations
     alone, while a present-but-empty field DELETES its row, and those two have
     to stay distinguishable. */
  function readForm(root) {
    var title = root.querySelector('[data-kpt-title]');
    var body = root.querySelector('[data-kpt-body]');
    var on = root.querySelector('[data-kpt-on]');

    var payload = {
      title: title ? title.value : '',
      body: body ? body.innerHTML : '',
      translations: window.KBBArabic ? KBBArabic.collect(root) : {}
    };

    /* BOUNDED HERE TOO, and the server bounds it again against its own two
       values. A select stores one of its own options or the default. */
    if (on) payload.is_enabled = on.value === '1';

    return payload;
  }

  function editorRoot(el) {
    return el.closest('.kpt-edit') || document.querySelector('.kpt-edit');
  }

  /* The whole arrangement, in one request, so a re-order is never half saved. */
  async function move(scope, id, direction) {
    var list = scope === 'global' ? state.globals : state.own;
    var index = -1;

    for (var i = 0; i < list.length; i++) { if (list[i].id === Number(id)) index = i; }

    var swap = direction === 'up' ? index - 1 : index + 1;
    if (index < 0 || swap < 0 || swap >= list.length) return;

    var moved = list.slice();
    var held = moved[index];
    moved[index] = moved[swap];
    moved[swap] = held;

    /* Renumbered from a FLOOR with a gap of ten rather than by swapping the two
       positions. Swapping breaks the moment two rows share a position — which
       they legitimately can, since a position is just a number the owner may
       type — and renumbering cannot. */
    var floor = scope === 'global' ? 100 : 500;
    var order = moved.map(function (t, i) { return { id: t.id, position: floor + (i * 10) }; });

    try {
      await api('/product-tabs/order', { order: order });
      say('Order saved.');
      await load();
      if (state.product) await loadProduct(state.product.id);
    } catch (e) {
      state.error = explain(e, 'That order could not be saved.');
      render();
    }
  }

  /* ------------------------------------------------------------- the events */

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    if (!document.querySelector('.kpt-wrap')) return;

    var hit = function (a) { var n = t.closest('[' + a + ']'); return n ? n.getAttribute(a) : null; };

    /* The rich-text toolbar. Handled first, because every one of these is
       inside a card that also carries the buttons below. */
    var cmd = hit('data-kpt-cmd');
    if (cmd) {
      e.preventDefault();
      var area = t.closest('.kpt-rte').querySelector('[data-kpt-body]');
      if (!area) return;
      area.focus();

      if (cmd.indexOf('formatBlock:') === 0) {
        document.execCommand('formatBlock', false, cmd.split(':')[1]);
      } else if (cmd === 'createLink') {
        var href = window.prompt('Link address (https://…)');
        if (href) document.execCommand('createLink', false, href);
      } else {
        document.execCommand(cmd, false, null);
      }
      return;
    }

    var open = hit('data-kpt-open');
    if (open !== null) {
      state.open = state.open === open ? null : open;
      render();
      return;
    }

    if (t.closest('[data-kpt-cancel]')) { state.open = null; render(); return; }

    var over = hit('data-kpt-over');
    if (over) { state.open = 'over:' + over; render(); return; }

    var save = hit('data-kpt-save');
    if (save) {
      var root = editorRoot(t);
      if (!root) return;
      var payload = readForm(root);

      (async function () {
        try {
          if (save === 'new-global') {
            await api('/product-tabs', payload);
          } else if (save === 'new-own') {
            await api('/product-tabs/product/' + Number(state.product.id), payload);
          } else {
            await api('/product-tabs/' + Number(save.split(':')[1]), payload, 'PUT');
          }
          state.open = null;
          say('Tab saved.');
          await load();
          if (state.product) await loadProduct(state.product.id);
        } catch (err) {
          state.error = explain(err, 'That tab could not be saved.');
          render();
        }
      })();
      return;
    }

    var saveOver = hit('data-kpt-saveover');
    if (saveOver) {
      var oroot = editorRoot(t);
      if (!oroot) return;
      var obody = readForm(oroot);
      obody.source_key = saveOver;
      obody.mode = 'override';
      delete obody.is_enabled;

      (async function () {
        try {
          await api('/product-tabs/product/' + Number(state.product.id) + '/override', obody);
          state.open = null;
          say('This product now has its own wording for that tab.');
          await loadProduct(state.product.id);
        } catch (err) {
          state.error = explain(err, 'That override could not be saved.');
          render();
        }
      })();
      return;
    }

    var hide = hit('data-kpt-hide');
    if (hide) {
      (async function () {
        try {
          await api('/product-tabs/product/' + Number(state.product.id) + '/override',
            { source_key: hide, mode: 'hide' });
          say('Hidden on this product. Every other product still shows it.');
          await loadProduct(state.product.id);
        } catch (err) {
          state.error = explain(err, 'That tab could not be hidden.');
          render();
        }
      })();
      return;
    }

    var revert = hit('data-kpt-revert');
    if (revert) {
      (async function () {
        try {
          await api('/product-tabs/product/' + Number(state.product.id) + '/override',
            { source_key: revert, mode: 'inherit' });
          say('Back to what the rest of the shop shows.');
          await loadProduct(state.product.id);
        } catch (err) {
          state.error = explain(err, 'That tab could not be restored.');
          render();
        }
      })();
      return;
    }

    var del = hit('data-kpt-del');
    if (del) {
      if (!window.confirm('Delete this tab? Its Arabic goes with it.')) return;
      (async function () {
        try {
          await api('/product-tabs/' + Number(del), undefined, 'DELETE');
          state.open = null;
          say('Tab deleted.');
          await load();
          if (state.product) await loadProduct(state.product.id);
        } catch (err) {
          state.error = explain(err, 'That tab could not be deleted.');
          render();
        }
      })();
      return;
    }

    var mv = hit('data-kpt-move');
    if (mv) {
      move(hit('data-kpt-scope'), hit('data-kpt-id'), mv);
      return;
    }

    var pick = hit('data-kpt-pick');
    if (pick) { loadProduct(pick); return; }

    if (t.closest('[data-kpt-clear]')) {
      state.product = null; state.inherited = []; state.own = []; state.hits = null; state.open = null;
      render();
      return;
    }

    if (t.closest('[data-kpt-find]')) {
      var box = document.querySelector('[data-kpt-q]');
      state.query = box ? box.value : '';
      (async function () {
        try {
          var body = await api('/product-tabs/search?q=' + encodeURIComponent(state.query));
          state.hits = body.products || [];
        } catch (err) {
          state.error = explain(err, 'That search could not be run.');
          state.hits = [];
        }
        render();
      })();
      return;
    }
  });

  /* Enter in the search box searches, rather than doing nothing. */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-kpt-q]')) return;
    e.preventDefault();
    var find = document.querySelector('[data-kpt-find]');
    if (find) find.click();
  });

  /* --------------------------------------------------------------- the nav */

  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;

    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Product tabs',
      icon: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18"/><path d="M8 7V4"/><path d="M14 7V4"/>',
      group: 'Catalog',
      after: ['catalog', 'sets']
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
    if (title) title.textContent = 'Product tabs';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

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
