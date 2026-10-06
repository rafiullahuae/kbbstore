/*
 * Store → Mega Menu → Add items.                                      Lane MX
 *
 * The owner: "in the mega menu, i need a full list of categories, brands,
 * pages etc etc. same like in wordpress, to directly click and add to specific
 * menu / columns. so i will avoid to paste the manual links mostely."
 *
 * A panel beside the column board (above it on a phone): tabs for Categories
 * (the tree, children indented), Brands (A–Z), Pages, Blog posts, Collections
 * (the shop's fixed listings) and a Custom link. Tick several, choose the menu
 * (a top-level item) and the column (one of its sub-menus), "Add to column" —
 * or drag a row, or the ticked rows, straight onto a column on the board.
 * Anything already in this menu wears a small tick.
 *
 * LIGHT BY CONSTRUCTION
 *  - ONE request when the panel first opens (GET …/mega-menu/sources), kept
 *    for the rest of the visit; switching menus or tabs asks for nothing.
 *  - The search box filters rows already drawn, by toggling `hidden`: no
 *    request per keystroke and no re-render.
 *  - ONE request per add, however many rows are ticked (POST …/mega-menu/pick).
 *    The server resolves every id itself — the browser sends no address for
 *    anything but a custom link — and the board draws the new rows from the
 *    answer without re-fetching the menu.
 *  - No element is measured and no timer runs (MegaMenuPickerTest holds both).
 *
 * Loaded as a classic script in the same script tag as menu-order.js, which
 * mounts it: KBBMenuOrder.mount() calls KBBMenuPicker.mount(el, board).
 */
(function (root, factory) {
  'use strict';
  root.KBBMenuPicker = factory();
})(self, function () {
  'use strict';

  var TABS = [
    ['categories', 'Categories'],
    ['brands', 'Brands'],
    ['pages', 'Pages'],
    ['posts', 'Blog posts'],
    ['collections', 'Collections'],
    ['custom', 'Custom link'],
  ];
  /* The list kind each tab sends back to the server. */
  var KIND = { categories: 'category', brands: 'brand', pages: 'page', posts: 'article', collections: 'collection' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function kids(n) { return (n && n.children) || []; }

  /* ================= Pure: lists, search, targets ================= */

  /** Categories in tree order with their depth: parents first, children indented under them. */
  function categoryRows(list) {
    var byParent = {}, ids = {};
    (list || []).forEach(function (c) { ids[c.id] = true; });
    (list || []).forEach(function (c) {
      var p = c.parent != null && ids[c.parent] ? c.parent : 0; // an orphan sits at the top, not lost
      (byParent[p] = byParent[p] || []).push(c);
    });
    var out = [], seen = {};
    (function walk(pid, depth) {
      (byParent[pid] || []).forEach(function (c) {
        if (seen[c.id]) return;
        seen[c.id] = true;
        out.push({ id: c.id, name: c.name, url: c.url, depth: Math.min(depth, 4) });
        walk(c.id, depth + 1);
      });
    })(0, 0);
    return out;
  }

  /** The rows a tab lists, each {id, name, url, depth}. */
  function rowsFor(groups, tab) {
    if (tab === 'categories') return categoryRows(groups.categories);
    return ((groups && groups[tab]) || []).map(function (r) { return { id: r.id, name: r.name, url: r.url, depth: 0 }; });
  }

  /** Case- and accent-insensitive "contains", on the name and the address. */
  function fold(s) {
    s = String(s == null ? '' : s).toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  }
  function matches(row, query) {
    var q = fold(query).trim();
    if (!q) return true;
    var hay = fold(row.name) + ' ' + fold(row.url);
    return q.split(/\s+/).every(function (w) { return hay.indexOf(w) >= 0; });
  }

  /** Every address already somewhere in this menu, at any depth. */
  function addedUrls(tree) {
    var out = {};
    (function walk(list) { (list || []).forEach(function (n) { if (n.url) out[String(n.url)] = true; walk(kids(n)); }); })(tree);
    return out;
  }

  /**
   * Where "Add to column" can file items: for each top-level item (a menu),
   * itself (its dropdown / a new column) and each of its sub-menus (a column).
   * Under a link nothing is ever drawn, so a link is never offered.
   */
  function targets(tree) {
    return (tree || []).map(function (top) {
      return {
        id: Number(top.id),
        label: String(top.label),
        columns: kids(top).map(function (c) { return { id: Number(c.id), label: String(c.label) }; }),
      };
    });
  }

  /** The parent id an add goes under, from the two selects: 'top' (a new top-level item), a menu id, or a column id. */
  function parentFor(menuVal, colVal) {
    if (menuVal === 'top') return null;
    var c = Number(colVal);
    return c > 0 ? c : Number(menuVal);
  }

  /** The request body: {menu_id, parent_id, items:[{type, id}]}. */
  function pickBody(menuId, parentId, tab, ids) {
    return {
      menu_id: menuId,
      parent_id: parentId,
      items: ids.map(function (id) { return { type: KIND[tab], id: tab === 'collections' ? String(id) : Number(id) }; }),
    };
  }

  /**
   * The custom link rule, as the server states it (MenuTargets::customUrlAllowed):
   * a path on this shop, or an https address. Checked here only to say so
   * before a round trip; the server decides.
   */
  function customUrlOk(url) {
    url = String(url || '').trim();
    if (!url || url.length > 255 || /[\u0000- \u007f\\]/.test(url) || /&[#a-z0-9]+;/i.test(url)) return false;
    if (url.charAt(0) === '/') return url.charAt(1) !== '/';
    return /^https:\/\/[^\/?#]+/i.test(url);
  }

  /* ================= Renderers ================= */

  function tabsHtml(tab) {
    return '<div class="mx-tabs" role="tablist" aria-label="What to add">' + TABS.map(function (t) {
      return '<button type="button" class="mx-tab" role="tab" data-mx-tab="' + t[0] + '" aria-selected="' + (t[0] === tab ? 'true' : 'false') + '">' + esc(t[1]) + '</button>';
    }).join('') + '</div>';
  }

  function rowHtml(tab, r, added) {
    var on = added[String(r.url)];
    return '<label class="mx-row' + (on ? ' mx-on' : '') + '" draggable="true" data-mx-row="' + esc(r.id) + '" data-mx-url="' + esc(r.url) + '"'
      + (r.depth ? ' style="--mx-d:' + Number(r.depth) + '"' : '') + ' title="' + esc(r.url) + '">'
      + '<input type="checkbox" data-mx-tick="' + esc(r.id) + '">'
      + '<span class="mx-name">' + esc(r.name) + '</span>'
      + '<span class="mx-added" aria-label="Already in this menu" title="Already in this menu">✓</span></label>';
  }

  function listHtml(tab, rows, added) {
    if (!rows.length) {
      var empty = { categories: 'No categories yet.', brands: 'No brands yet.', pages: 'No published pages with an address on the shop.', posts: 'No published blog posts yet.', collections: 'No listings.' }[tab];
      return '<p class="mx-note">' + esc(empty) + '</p>';
    }
    return rows.map(function (r) { return rowHtml(tab, r, added); }).join('') + '<p class="mx-note" data-mx-none hidden>Nothing matches.</p>';
  }

  function targetHtml(tree, menuVal, colVal) {
    var t = targets(tree);
    var menu = '<select class="mx-in" data-mx-menu aria-label="Menu">'
      + t.map(function (m) { return '<option value="' + m.id + '"' + (String(m.id) === String(menuVal) ? ' selected' : '') + '>' + esc(m.label) + '</option>'; }).join('')
      + '<option value="top"' + (menuVal === 'top' || !t.length ? ' selected' : '') + '>New top-level items</option></select>';
    var chosen = t.filter(function (m) { return String(m.id) === String(menuVal); })[0] || (menuVal === 'top' ? null : t[0]);
    var col = '';
    if (chosen) {
      col = '<select class="mx-in" data-mx-col aria-label="Column">'
        + chosen.columns.map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(colVal) ? ' selected' : '') + '>' + esc(c.label) + '</option>'; }).join('')
        + '<option value="0"' + (String(colVal) === '0' || !chosen.columns.length ? ' selected' : '') + '>' + esc('Directly in ' + chosen.label + ' (a new column)') + '</option></select>';
    }
    return '<div class="mx-target"><span class="mx-lbl">Add to</span>' + menu + col + '</div>';
  }

  function customHtml() {
    return '<div class="mx-custom">'
      + '<input class="mx-in" data-mx-clabel maxlength="60" placeholder="Label, e.g. Gift cards" aria-label="Label">'
      + '<input class="mx-in" data-mx-curl maxlength="255" placeholder="/path/ or https://…" aria-label="Address">'
      + '<p class="mx-note">A path on this shop (starting with /) or an https:// address.</p></div>';
  }

  function panelHtml(state) {
    return '<div class="mx-panel" data-mx-panel>'
      + '<div class="mx-head"><b>Add items</b><span class="mx-sub">Tick, choose where, add — or drag a row onto a column.</span>'
      + '<button type="button" class="mx-x" data-mx-close aria-label="Close the list">×</button></div>'
      + tabsHtml(state.tab)
      + '<div data-mx-body></div>'
      + '<div data-mx-target></div>'
      + '<div class="mx-foot"><button type="button" class="mx-go" data-mx-add disabled>Add to column</button>'
      + '<button type="button" class="mx-link" data-mx-all>Tick all shown</button>'
      + '<button type="button" class="mx-link" data-mx-reload>Reload list</button></div></div>';
  }

  /* ================= In the page ================= */

  /* Kept for the visit: re-mounting (switching menu) asks the server for nothing. */
  var cache = null, loading = null;
  var memo = { open: false, tab: 'categories', menu: null, col: null };

  try { memo.open = self.localStorage && self.localStorage.getItem('kbb.mx.open') === '1'; } catch (e) { /* storage off: closed */ }

  function remember() {
    try { if (self.localStorage) self.localStorage.setItem('kbb.mx.open', memo.open ? '1' : '0'); } catch (e) { /* a convenience only */ }
  }

  /**
   * board = {tree(), menuId(), api(path, opts), toast(msg, kind), insert(parentId, nodes), busy()}
   * from KBBMenuOrder.mount(). Returns {refresh()} for the board to call on every tree change.
   */
  function mount(el, board) {
    var slot = el.querySelector('[data-mx-slot]');
    if (!slot) return null;
    var dragIds = null, dragTab = null, hotEl = null, sending = false;

    function q(sel) { return slot.querySelector(sel); }
    function qa(sel) { return Array.prototype.slice.call(slot.querySelectorAll(sel)); }

    function drawClosed() {
      el.classList.remove('mx-open');
      slot.innerHTML = '<button type="button" class="mx-open-btn" data-mx-open aria-expanded="false">'
        + '<span aria-hidden="true">＋</span> Add items <small>categories, brands, pages, blog posts, collections</small></button>';
    }

    function drawOpen() {
      el.classList.add('mx-open');
      slot.innerHTML = panelHtml(memo);
      drawBody();
      drawTarget();
      var first = q('[data-mx-tab][aria-selected="true"]');
      if (first) first.focus();
    }

    function drawBody() {
      var body = q('[data-mx-body]');
      if (!body) return;
      if (memo.tab === 'custom') { body.innerHTML = customHtml(); syncAdd(); return; }
      if (!cache) { body.innerHTML = '<p class="mx-note">Loading…</p>'; syncAdd(); return; }
      var rows = rowsFor(cache.groups, memo.tab);
      var warn = memo.tab === 'brands' && cache.brands_module_on === false
        ? '<p class="mx-warn">Brand pages are switched off (Store, then Modules, then Brands), so brand links stay out of the header until it is on.</p>' : '';
      body.innerHTML = warn + '<input class="mx-in mx-find" type="search" data-mx-find placeholder="Search ' + esc(TABS.filter(function (t) { return t[0] === memo.tab; })[0][1].toLowerCase()) + '…" aria-label="Search this list">'
        + '<div class="mx-list" data-mx-list>' + listHtml(memo.tab, rows, addedUrls(board.tree())) + '</div>';
      syncAdd();
    }

    function drawTarget() {
      var box = q('[data-mx-target]');
      if (!box) return;
      var tree = board.tree();
      var ok = function (v) { return v === 'top' || tree.some(function (m) { return String(m.id) === String(v); }); };
      if (memo.menu === null || !ok(memo.menu)) memo.menu = tree.length ? String(tree[0].id) : 'top';
      box.innerHTML = targetHtml(tree, memo.menu, memo.col);
      var col = q('[data-mx-col]');
      memo.col = col ? col.value : null;
    }

    function ticked() { return qa('[data-mx-tick]:checked').map(function (b) { return b.dataset.mxTick; }); }

    function syncAdd() {
      var b = q('[data-mx-add]');
      if (!b) return;
      var n = memo.tab === 'custom' ? 1 : ticked().length;
      b.disabled = sending || n === 0;
      b.textContent = memo.tab === 'custom' ? 'Add custom link' : (n ? 'Add ' + n + ' to column' : 'Add to column');
      var all = q('[data-mx-all]');
      if (all) all.hidden = memo.tab === 'custom';
    }

    function load() {
      if (cache || loading) return loading;
      loading = board.api('/sources', { method: 'GET' }).then(function (r) {
        loading = null;
        if (!r || !r.ok || !r.data || !r.data.groups) { board.toast('Could not load the list — try Reload list.', 'bad'); return; }
        cache = r.data;
        drawBody();
      }, function () { loading = null; board.toast('Could not load the list.', 'bad'); });
      return loading;
    }

    function open(yes) {
      memo.open = yes;
      remember();
      if (yes) { drawOpen(); load(); } else { drawClosed(); var b = q('[data-mx-open]'); if (b) b.focus(); }
    }

    function filter(text) {
      var any = false;
      qa('[data-mx-row]').forEach(function (row) {
        var show = matches({ name: row.textContent, url: row.dataset.mxUrl }, text);
        row.hidden = !show;
        if (show) any = true;
      });
      var none = q('[data-mx-none]');
      if (none) none.hidden = any;
    }

    /** One request for every item; the board draws the rows the server made. */
    function send(parentId, items, where) {
      if (sending || board.busy()) return Promise.resolve(false);
      sending = true;
      syncAdd();
      return board.api('/pick', { method: 'POST', body: JSON.stringify({ menu_id: board.menuId(), parent_id: parentId, items: items }) }).then(function (r) {
        sending = false;
        if (!r || !r.ok || !r.data || !r.data.items) {
          board.toast((r && r.data && r.data.errors && r.data.errors[0]) || 'Could not add those.', 'bad');
          syncAdd();
          return false;
        }
        board.insert(parentId, r.data.items);
        board.toast('Added ' + r.data.items.length + (where ? ' to ' + where : ''));
        qa('[data-mx-tick]:checked').forEach(function (b) { b.checked = false; });
        syncAdd();
        return true;
      }, function () { sending = false; syncAdd(); board.toast('Could not add those.', 'bad'); return false; });
    }

    function whereLabel(parentId) {
      if (parentId === null) return 'the top level';
      var found = '';
      board.tree().forEach(function (m) {
        if (Number(m.id) === parentId) found = String(m.label);
        kids(m).forEach(function (c) { if (Number(c.id) === parentId) found = m.label + ' › ' + c.label; });
      });
      return found;
    }

    function addTicked() {
      var parentId = parentFor(memo.menu, memo.col);
      if (memo.tab === 'custom') {
        var label = (q('[data-mx-clabel]') || {}).value || '', url = (q('[data-mx-curl]') || {}).value || '';
        if (!label.trim()) { board.toast('Give the link a label.', 'bad'); return; }
        if (!customUrlOk(url)) { board.toast('Use a path on this shop (starting with /) or an https:// address.', 'bad'); return; }
        send(parentId, [{ type: 'custom', label: label.trim(), url: url.trim() }], whereLabel(parentId)).then(function (ok) {
          if (ok) { q('[data-mx-clabel]').value = ''; q('[data-mx-curl]').value = ''; }
        });
        return;
      }
      var ids = ticked();
      if (!ids.length) return;
      send(parentId, pickBody(0, 0, memo.tab, ids).items, whereLabel(parentId));
    }

    /* ---- the panel's own events ---- */

    slot.addEventListener('click', function (e) {
      var t = e.target, b;
      if (t.closest('[data-mx-open]')) open(true);
      else if (t.closest('[data-mx-close]')) open(false);
      else if ((b = t.closest('[data-mx-tab]'))) {
        memo.tab = b.dataset.mxTab;
        qa('[data-mx-tab]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
        drawBody();
      } else if (t.closest('[data-mx-add]')) addTicked();
      else if (t.closest('[data-mx-all]')) {
        qa('[data-mx-row]').forEach(function (row) { if (!row.hidden) row.querySelector('[data-mx-tick]').checked = true; });
        syncAdd();
      } else if (t.closest('[data-mx-reload]')) { cache = null; drawBody(); load(); }
    });

    slot.addEventListener('input', function (e) { if (e.target.matches('[data-mx-find]')) filter(e.target.value); });

    slot.addEventListener('change', function (e) {
      var t = e.target;
      if (t.matches('[data-mx-tick]')) syncAdd();
      else if (t.matches('[data-mx-menu]')) { memo.menu = t.value; memo.col = null; drawTarget(); }
      else if (t.matches('[data-mx-col]')) memo.col = t.value;
    });

    slot.addEventListener('keydown', function (e) {
      var t = e.target;
      if (e.key === 'Enter' && (t.matches('[data-mx-clabel]') || t.matches('[data-mx-curl]'))) { e.preventDefault(); addTicked(); }
      else if (e.key === 'Escape' && t.closest('[data-mx-panel]')) { e.preventDefault(); open(false); }
      else if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && t.matches('[data-mx-tab]')) {
        var tabs = qa('[data-mx-tab]'), i = tabs.indexOf(t) + (e.key === 'ArrowRight' ? 1 : -1);
        var next = tabs[(i + tabs.length) % tabs.length];
        next.focus();
        next.click();
      }
    });

    /* ---- drag a row (or every ticked row) onto a column ---- */

    slot.addEventListener('dragstart', function (e) {
      var row = e.target.closest && e.target.closest('[data-mx-row]');
      if (!row || memo.tab === 'custom') return;
      var box = row.querySelector('[data-mx-tick]');
      var ids = box.checked ? ticked() : [row.dataset.mxRow];
      dragIds = ids;
      dragTab = memo.tab;
      el.classList.add('mx-dragging');
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'copy';
        e.dataTransfer.setData('text/plain', ids.length + ' item' + (ids.length === 1 ? '' : 's'));
      }
    });

    function dropTarget(t) {
      var grp = t.closest && t.closest('.mo-grp');
      if (grp) return { el: grp, id: Number(grp.dataset.moItem) };
      var col = t.closest && t.closest('.mo-col');
      if (col) return { el: col, id: Number(col.dataset.moItem) };
      return null;
    }

    function setHot(n) {
      if (hotEl === n) return;
      if (hotEl) hotEl.classList.remove('mx-drop');
      hotEl = n;
      if (hotEl) hotEl.classList.add('mx-drop');
    }

    function endDrag() { dragIds = null; dragTab = null; setHot(null); el.classList.remove('mx-dragging'); }

    el.addEventListener('dragover', function (e) {
      if (!dragIds) return;
      var tgt = dropTarget(e.target);
      setHot(tgt ? tgt.el : null);
      if (!tgt) return;
      e.preventDefault();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
    });
    el.addEventListener('drop', function (e) {
      if (!dragIds) return;
      var tgt = dropTarget(e.target), ids = dragIds, tab = dragTab;
      endDrag();
      if (!tgt) return;
      e.preventDefault();
      send(tgt.id, pickBody(0, 0, tab, ids).items, whereLabel(tgt.id));
    });
    el.addEventListener('dragend', endDrag);

    if (memo.open) { drawOpen(); load(); } else drawClosed();

    return {
      /* The board changed (an add, a move, a delete): the ticks and the column choices follow. */
      refresh: function () {
        if (!memo.open) return;
        var added = addedUrls(board.tree());
        qa('[data-mx-row]').forEach(function (row) { row.classList.toggle('mx-on', !!added[row.dataset.mxUrl]); });
        drawTarget();
      },
    };
  }

  return {
    TABS: TABS,
    KIND: KIND,
    categoryRows: categoryRows,
    rowsFor: rowsFor,
    matches: matches,
    addedUrls: addedUrls,
    targets: targets,
    parentFor: parentFor,
    pickBody: pickBody,
    customUrlOk: customUrlOk,
    rowHtml: rowHtml,
    targetHtml: targetHtml,
    mount: mount,
  };
});
