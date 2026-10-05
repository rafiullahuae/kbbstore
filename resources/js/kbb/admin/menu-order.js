/*
 * Appearance → Mega Menu: the column board.                           Lane MO
 *
 * The owner, of the old drag-and-drop tree: "the drag n drop functionality is
 * super annoying ... i can write sort number, and it go on that ... the line
 * is super thin, i can't catch." Then, choosing layout A: "the main parents
 * menu will come as columns display for me, and i can easily add anything in
 * specific column" — "i want thin rows and small stuff, so maximum columns can
 * be visible on the screen" — "super instant, but super light, fully smooth".
 *
 * Every top-level item is a 156px column; inside it, its sub-menus, each with
 * its links. Every row carries a sort number you type and arrow buttons, a
 * name that opens the existing editor, and a ⋯ panel (Edit, Delete, Move to
 * column…). Drag is live: the dragged row itself takes its place as it passes
 * other rows, so there is no line to catch, and a whole column lights up.
 *
 * HOW PLACEMENT IS DECIDED WITHOUT MEASURING. place() reads only the tree, the
 * row (or zone) the pointer has just entered and the direction of travel.
 * Within one list the dragged row swaps past the row entered; arriving in
 * another list it goes above the row entered when travelling down and below
 * it when travelling up. No element is measured anywhere in this file —
 * MenuOrderModuleTest forbids the APIs by name.
 *
 * ONE REQUEST PER ACTION, NO RE-FETCH. Every reorder or move is one POST to
 * /admin-api/mega-menu/{id}/move with {parent_id, ids} (ids = the target
 * group's complete new order, which the server checks). The tree is changed
 * locally and only the column(s) it touched are redrawn. A failure says why
 * and reloads the server's order. No timers: the touch press-and-hold is a CSS
 * animation whose animationend arms the drag.
 *
 * Loaded as a classic script (window.KBBMenuOrder); its tests run it in node's
 * vm with a stand-in `self`.
 */
(function (root, factory) {
  'use strict';
  root.KBBMenuOrder = factory();
})(self, function () {
  'use strict';

  /** Deepest index a node may sit at: 0 column, 1 sub-menu, 2 link. */
  var MAX_DEPTH = 2;

  function kids(n) { return (n && n.children) || []; }
  function ids(list) { return list.map(function (n) { return Number(n.id); }); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ================= The tree: pure functions, no DOM ================= */

  /** Where an id sits: node, parent (null at the top), siblings, index, depth, path from the top. */
  function locate(tree, id) {
    id = Number(id);
    var stack = [{ list: tree || [], parent: null, depth: 0, path: [] }];
    while (stack.length) {
      var f = stack.pop();
      for (var i = 0; i < f.list.length; i++) {
        var n = f.list[i], path = f.path.concat([n]);
        if (Number(n.id) === id) return { node: n, parent: f.parent, siblings: f.list, index: i, depth: f.depth, path: path };
        if (kids(n).length) stack.push({ list: kids(n), parent: n, depth: f.depth + 1, path: path });
      }
    }
    return null;
  }

  /** Levels below a node: 0 for a leaf. */
  function subtreeDepth(n) {
    var d = -1;
    kids(n).forEach(function (k) { d = Math.max(d, subtreeDepth(k)); });
    return d + 1;
  }

  /**
   * The server's rule (MegaMenuApiController::move) plus the board's own two:
   * a column stays a column, and nothing else ever becomes one. Under a
   * parent: not itself, not inside its own branch, and its whole subtree must
   * still fit in three levels.
   */
  function canMoveUnder(tree, id, parentId) {
    var me = locate(tree, id);
    if (!me) return false;
    var top = parentId === null || parentId === undefined || parentId === '';
    if (me.depth === 0 || top) return me.depth === 0 && top;
    var target = locate(tree, parentId);
    if (!target) return false;
    for (var i = 0; i < target.path.length; i++) if (Number(target.path[i].id) === Number(id)) return false;
    return target.path.length + subtreeDepth(me.node) <= MAX_DEPTH; // path.length is the server's depthOf()
  }

  /** "Move to column…": every parent the item may go under, in tree order, labelled by path. */
  function parentChoices(tree, id) {
    var me = locate(tree, id);
    if (!me || me.depth === 0) return [];
    var cur = me.parent ? Number(me.parent.id) : null, out = [];
    (tree || []).forEach(function (col) {
      [col].concat(kids(col)).forEach(function (n, k) {
        if (!canMoveUnder(tree, id, n.id)) return;
        var label = k === 0 ? String(col.label) : String(col.label) + ' › ' + String(n.label);
        out.push({ id: Number(n.id), label: label, depth: k === 0 ? 0 : 1, current: cur === Number(n.id) });
      });
    });
    return out;
  }

  /** The number box's reading: an integer clamped to 1..count, or null (not a whole number — revert). */
  function parsePosition(input, count) {
    var s = String(input == null ? '' : input).trim();
    if (!/^[-+]?\d{1,6}$/.test(s) || count < 1) return null;
    return Math.min(count, Math.max(1, parseInt(s, 10)));
  }

  /**
   * The request for putting id under parentId (undefined = where it is) at
   * 1-based position among its new siblings (clamped; undefined = last).
   */
  function planMove(tree, id, parentId, position) {
    var me = locate(tree, id);
    if (!me) return { ok: false, reason: 'That item is no longer on this menu.' };
    var curParent = me.parent ? Number(me.parent.id) : null;
    var target = parentId === undefined ? curParent : (parentId === null || parentId === '' ? null : Number(parentId));
    if (!canMoveUnder(tree, id, target)) {
      return { ok: false, parentId: target, reason: me.depth === 0 || target === null
        ? 'Columns only change places with columns.'
        : 'It can’t go there — a sub-menu with links can only sit in a column.' };
    }
    var group = target === null ? (tree || []) : kids(locate(tree, target).node);
    var order = ids(group).filter(function (x) { return x !== Number(id); });
    var at = position === undefined ? order.length : Math.min(order.length, Math.max(0, Number(position) - 1));
    order.splice(at, 0, Number(id));
    var same = target === curParent && order.join(',') === ids(me.siblings).join(',');
    return { ok: true, noop: same, itemId: Number(id), parentId: target, ids: order, position: at + 1 };
  }

  function planPosition(tree, id, typed) {
    var me = locate(tree, id);
    if (!me) return null;
    var p = parsePosition(typed, me.siblings.length);
    return p === null ? null : planMove(tree, id, undefined, p);
  }

  /** One place earlier (-1) or later (+1) among its siblings; null at an end. */
  function planStep(tree, id, delta) {
    var me = locate(tree, id);
    if (!me) return null;
    var to = me.index + (delta < 0 ? -1 : 1);
    return to < 0 || to >= me.siblings.length ? null : planMove(tree, id, undefined, to + 1);
  }

  /**
   * DRAG PLACEMENT. over = {kind:'row', id} (the row just entered) or
   * {kind:'zone', id} (a column body or a sub-menu's link list, id = its
   * owner); dir = +1 travelling down/right, -1 up/left. Returns a planMove()
   * result, {ok:false, reason} for a place the server would refuse (shown as
   * a refusal), or null for "nothing to do here".
   *
   *  - A column only ever trades places with another column.
   *  - Entering a row in the dragged item's own list: swap past it.
   *  - Entering a row in another list: above it travelling down, below it
   *    travelling up (where the pointer came in).
   *  - Entering a column's header: first in that column.
   *  - Entering a zone of another list: last in it.
   */
  function place(tree, dragId, over, dir) {
    var me = locate(tree, dragId);
    if (!me || !over) return null;
    var t = locate(tree, over.id);
    if (!t || Number(over.id) === Number(dragId)) return null;
    for (var i = 0; i < t.path.length; i++) if (Number(t.path[i].id) === Number(dragId)) return null; // own branch

    if (me.depth === 0) {
      var col = t.path[0];
      if (Number(col.id) === Number(dragId)) return null;
      // Swap past it: from the left that is "after it", from the right
      // "before it" — and with the dragged column taken out first, both are
      // the column's own index + 1.
      var ci = ids(tree).indexOf(Number(col.id));
      var plan0 = planMove(tree, dragId, null, ci + 1);
      return plan0.noop ? null : plan0;
    }

    var parentId, position;
    if (over.kind === 'zone' || t.depth === 0) {
      parentId = Number(t.node.id);
      if (me.parent && Number(me.parent.id) === parentId && over.kind === 'zone') return null; // already in it
      position = over.kind === 'zone' ? undefined : 1;
    } else {
      parentId = Number(t.parent.id);
      var sameList = me.parent && Number(me.parent.id) === parentId;
      var others = ids(t.siblings).filter(function (x) { return x !== Number(dragId); });
      var k = others.indexOf(Number(t.node.id));
      var after = sameList ? me.index < t.index : dir < 0;
      position = k + (after ? 2 : 1);
    }
    var plan = planMove(tree, dragId, parentId, position);
    return plan.ok && plan.noop ? null : plan;
  }

  /** A copy of the tree with the plan applied. */
  function applyPlan(tree, plan) {
    var copy = JSON.parse(JSON.stringify(tree || []));
    var me = locate(copy, plan.itemId);
    if (!me) return copy;
    me.siblings.splice(me.index, 1);
    var group = copy;
    if (plan.parentId !== null) {
      var p = locate(copy, plan.parentId).node;
      if (!p.children) p.children = [];
      group = p.children;
    }
    var byId = {};
    group.concat([me.node]).forEach(function (n) { byId[Number(n.id)] = n; });
    var next = plan.ids.map(function (x) { return byId[x]; }).filter(Boolean);
    group.length = 0;
    Array.prototype.push.apply(group, next);
    return copy;
  }

  /** Where the tree's state differs, the plan that gets there from `before`: what a finished drag sends. */
  function planFrom(before, after, id) {
    var a = locate(after, id), b = locate(before, id);
    if (!a || !b) return null;
    var parentId = a.parent ? Number(a.parent.id) : null;
    var plan = { ok: true, itemId: Number(id), parentId: parentId, ids: ids(a.siblings), position: a.index + 1 };
    plan.noop = (b.parent ? Number(b.parent.id) : null) === parentId && ids(b.siblings).join(',') === plan.ids.join(',');
    return plan;
  }

  /**
   * The one place that saves. host = {getTree, setTree, patch(plan, before),
   * reload, send(itemId, body) -> Promise<{ok, data}>, toast(msg, kind)}.
   * One request per action; a second action while one is in flight is
   * refused ('busy'), never queued. The tree changes locally first and stays
   * that way on success — no re-fetch; a failure reloads the server's order.
   */
  function controller(host) {
    var inflight = false;
    function fail(r) {
      inflight = false;
      var why = r && r.data && r.data.errors && r.data.errors[0];
      host.toast(why || 'Could not save that — the list is back to the saved order.', 'bad');
      host.reload();
      return 'failed';
    }
    function run(plan) {
      if (inflight) return Promise.resolve('busy');
      if (!plan) return Promise.resolve('invalid');
      if (!plan.ok) { host.toast(plan.reason, 'bad'); return Promise.resolve('refused'); }
      if (plan.noop) return Promise.resolve('noop');
      inflight = true;
      var before = host.getTree();
      host.setTree(applyPlan(before, plan));
      host.patch(plan, before);
      return Promise.resolve()
        .then(function () { return host.send(plan.itemId, { parent_id: plan.parentId, ids: plan.ids }); })
        .then(function (r) {
          if (!r || !r.ok) return fail(r);
          inflight = false;
          host.toast('Saved');
          return 'saved';
        }, fail);
    }
    return {
      busy: function () { return inflight; },
      toPosition: function (id, typed) { return run(planPosition(host.getTree(), id, typed)); },
      step: function (id, delta) { return run(planStep(host.getTree(), id, delta)); },
      under: function (id, parentId) { return run(planMove(host.getTree(), id, parentId === '' || parentId == null ? null : Number(parentId), undefined)); },
      run: run,
    };
  }

  /* ================= Renderers: strings with data-mo-* hooks ================= */

  var VIS = { guest: 'Signed out only', auth: 'Signed in only' };

  function isRtl() { return typeof document !== 'undefined' && document.documentElement.getAttribute('dir') === 'rtl'; }

  function numBox(id, pos, count, label) {
    return '<input class="mo-num" type="text" inputmode="numeric" autocomplete="off" maxlength="3" data-mo-num="' + id + '" value="' + pos + '"'
      + ' aria-label="' + esc('Position of ' + label + ', 1 to ' + count) + '" title="Type a number, then Enter">';
  }

  function arrows(id, pos, count, label, h) {
    var b = function (d, glyph, word, off) {
      return '<button type="button" class="mo-ib" data-mo-step="' + d + '" data-mo-id="' + id + '"' + (off ? ' disabled' : '')
        + ' aria-label="' + esc('Move ' + label + ' ' + word) + '">' + glyph + '</button>';
    };
    // Columns run in reading order, so in a right-to-left page "earlier" is to the right.
    var rtl = isRtl();
    return b(-1, h ? (rtl ? '\u2192' : '\u2190') : '\u2191', h ? (rtl ? 'right' : 'left') : 'up', pos === 1)
      + b(1, h ? (rtl ? '\u2190' : '\u2192') : '\u2193', h ? (rtl ? 'left' : 'right') : 'down', pos === count);
  }

  function nameBtn(n) {
    var tip = String(n.label) + (n.url ? '  ' + n.url : '') + (VIS[n.visibility] ? ' · ' + VIS[n.visibility] : '') + (n.new_tab ? ' · new tab' : '');
    return '<span class="mo-name" role="button" tabindex="0" data-mo-edit="' + Number(n.id) + '" title="' + esc(tip) + '">' + esc(n.label)
      + '</span>' + (n.badge ? '<em class="mo-badge">' + esc(n.badge) + '</em>' : '');
  }

  function more(n) {
    return '<button type="button" class="mo-ib mo-more" data-mo-more="' + Number(n.id) + '" aria-expanded="false" aria-label="' + esc('More for ' + n.label) + '">⋯</button>';
  }

  function swatch(n) {
    return /^#[0-9a-fA-F]{6}$/.test(String(n.highlight_color || '')) ? ' style="--mo-hl:' + n.highlight_color + '"' : '';
  }

  /** A row's inner controls: number, name, arrows, ⋯. */
  function rowInner(n, pos, count, h) {
    var id = Number(n.id), label = String(n.label);
    return numBox(id, pos, count, label) + nameBtn(n) + arrows(id, pos, count, label, h) + more(n);
  }

  function linkHtml(n, pos, count) {
    return '<div class="mo-row mo-link" data-mo-item="' + Number(n.id) + '" data-mo-depth="2" data-mo-grab' + swatch(n) + '>' + rowInner(n, pos, count) + '</div>';
  }

  function groupHtml(n, pos, count) {
    var k = kids(n), id = Number(n.id);
    return '<div class="mo-grp" data-mo-item="' + id + '" data-mo-depth="1"' + swatch(n) + '>'
      + '<div class="mo-row mo-ghead" data-mo-grab>' + rowInner(n, pos, count) + '</div>'
      + '<div class="mo-links" data-mo-zone="' + id + '">' + k.map(function (c, i) { return linkHtml(c, i + 1, k.length); }).join('') + '</div>'
      + '<button type="button" class="mo-add mo-add-s" data-mo-add="' + id + '">+ Add item</button></div>';
  }

  function headHtml(n, pos, count) {
    return '<div class="mo-row mo-head" data-mo-grab>' + rowInner(n, pos, count, true) + '</div>';
  }

  function columnHtml(n, pos, count) {
    var k = kids(n), id = Number(n.id);
    return '<section class="mo-col" data-mo-item="' + id + '" data-mo-depth="0"' + swatch(n) + '>' + headHtml(n, pos, count)
      + '<div class="mo-body" data-mo-zone="' + id + '">' + k.map(function (c, i) { return groupHtml(c, i + 1, k.length); }).join('') + '</div>'
      + '<button type="button" class="mo-add" data-mo-add="' + id + '">+ Add a sub-menu</button></section>';
  }

  /** The item at any depth, as its row/group/column. */
  function itemHtml(tree, id) {
    var me = locate(tree, id);
    if (!me) return '';
    var f = [columnHtml, groupHtml, linkHtml][me.depth];
    return f(me.node, me.index + 1, me.siblings.length);
  }

  /** The floating + and its sheet. Rendered once with the board. */
  function fabHtml() {
    return '<button type="button" class="mo-fab" data-mo-fab aria-label="Add to the menu" aria-haspopup="dialog" aria-expanded="false" aria-controls="moSheet">'
      + '<span aria-hidden="true">+</span></button>'
      + '<div class="mo-sheet" id="moSheet" role="dialog" aria-label="Add to the menu" hidden>'
      + '<div class="mo-sheet-h"><b>Add to the menu</b><button type="button" class="mo-ib" data-mo-sheet-close aria-label="Close">×</button></div>'
      + '<div class="mo-choices">'
      + '<button type="button" class="mo-choice" data-mo-choice="top" aria-pressed="false">Add a parent menu (top-level)</button>'
      + '<button type="button" class="mo-choice" data-mo-choice="sub" aria-pressed="false">Add a sub-menu to…</button>'
      + '<button type="button" class="mo-choice" data-mo-choice="link" aria-pressed="false">Add a link to…</button>'
      + '</div><div class="mo-sheet-f" data-mo-sheet-form></div></div>';
  }

  function boardHtml(tree) {
    var t = tree || [];
    // The two edge strips exist only while dragging: moving over one scrolls
    // the board that way, so a far column can be reached without letting go.
    return '<div class="mo-wrap"><span class="mo-edge mo-edge-l" data-mo-edge="-1" aria-hidden="true"></span>'
      + '<div class="mo-board" data-mo-board><div class="mo-cols" data-mo-zone="root">'
      + t.map(function (n, i) { return columnHtml(n, i + 1, t.length); }).join('')
      + '</div></div><span class="mo-edge mo-edge-r" data-mo-edge="1" aria-hidden="true"></span></div>'
      + (t.length ? '' : '<p class="mo-empty">Nothing here yet — the header is showing its built-in fallback. Add the first item with the + button.</p>')
      + fabHtml();
  }

  /** The ⋯ panel, built only when opened. */
  function moreHtml(tree, id) {
    var me = locate(tree, id);
    if (!me) return '';
    var label = String(me.node.label);
    var sel = '';
    if (me.depth > 0) {
      sel = '<select class="mo-under" data-mo-under="' + Number(id) + '" aria-label="' + esc('Move ' + label + ' to column') + '">'
        + '<option value="">Move to column…</option>'
        + parentChoices(tree, id).filter(function (c) { return !c.current; }).map(function (c) {
          return '<option value="' + c.id + '">' + esc(c.label) + '</option>';
        }).join('') + '</select>';
    }
    return '<div class="mo-panel" data-mo-panel="' + Number(id) + '">'
      + '<button type="button" class="mo-pb" data-mo-edit="' + Number(id) + '">Edit</button>'
      + '<button type="button" class="mo-pb mo-pb-del" data-mo-del="' + Number(id) + '">Delete</button>' + sel + '</div>';
  }

  /* ================= The board in the page ================= */

  function q(el, sel) { return el.querySelector(sel); }
  function itemEl(rootEl, id) { return q(rootEl, '[data-mo-item="' + Number(id) + '"]'); }
  function frag(html) { var t = document.createElement('template'); t.innerHTML = html; return t.content.firstElementChild; }
  function directItems(zone) { return Array.prototype.filter.call(zone.children, function (c) { return c.hasAttribute('data-mo-item'); }); }

  /**
   * Mount the board in `el`. host = {
   *   tree(), setTree(t), menuId(),
   *   api(path, opts) -> Promise<{ok, data}>   (the screen's mgmApi),
   *   toast(msg, kind), reload(), edit(id), confirmDelete(label) -> Promise<bool>
   * }
   */
  function mount(el, host) {
    if (!el) return null;
    el.innerHTML = boardHtml(host.tree());
    // Every local change goes through here, so the screen's other views of
    // the tree (the live preview strip) can follow without a re-fetch.
    var setTree = function (t) { host.setTree(t); if (host.changed) host.changed(); };
    var board = q(el, '[data-mo-board]');
    var reduced = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

    var ctl = controller({
      getTree: host.tree,
      setTree: setTree,
      reload: host.reload,
      toast: host.toast,
      send: function (id, body) { return host.api('/' + id + '/move', { method: 'POST', body: JSON.stringify(body) }); },
      patch: patch,
    });

    function flash(id) {
      var n = itemEl(el, id);
      if (!n) return;
      n.classList.add('mo-flash');
      if (n.scrollIntoView) n.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduced ? 'auto' : 'smooth' });
    }

    function columnOf(tree, id) { var l = locate(tree, id); return l ? Number(l.path[0].id) : null; }

    /** Redraw what a change touched: the column heads for a column move, else the one or two columns involved. */
    function patch(plan, before) {
      var tree = host.tree(), cols = q(el, '[data-mo-zone="root"]');
      if (plan.parentId === null) {
        var node = itemEl(el, plan.itemId), rest = directItems(cols).filter(function (c) { return c !== node; });
        if (node) cols.insertBefore(node, rest[plan.position - 1] || null);
        directItems(cols).forEach(function (c, i) {
          var n = tree[i];
          if (n && Number(c.dataset.moItem) === Number(n.id)) c.replaceChild(frag(headHtml(n, i + 1, tree.length)), c.firstElementChild);
        });
      } else {
        var seen = {};
        [columnOf(before, plan.itemId), columnOf(tree, plan.itemId)].forEach(function (cid) {
          if (cid === null || seen[cid]) return;
          seen[cid] = 1;
          var old = itemEl(el, cid);
          if (old) old.replaceWith(frag(itemHtml(tree, cid)));
        });
      }
      flash(plan.itemId);
    }

    /* ---- numbers, arrows, ⋯ panel, names ---- */

    function refocus(id, sel) {
      var n = itemEl(el, id);
      var row = n && (n.matches('[data-mo-grab]') ? n : q(n, '[data-mo-grab]'));
      var f = row && (q(row, sel + ':not([disabled])') || q(row, '[data-mo-num]'));
      if (f) f.focus();
    }

    function commitNum(box) {
      if (box.dataset.moDone === '1') return;
      box.dataset.moDone = '1';
      var id = Number(box.dataset.moNum);
      ctl.toPosition(id, box.value).then(function (r) {
        box.dataset.moDone = '';
        if (r !== 'saved' && r !== 'failed') box.value = box.defaultValue;
      });
      if (box.value !== box.defaultValue) refocus(id, '[data-mo-num]');
    }

    function closePanels() {
      Array.prototype.forEach.call(el.querySelectorAll('[data-mo-panel]'), function (p) { p.remove(); });
      Array.prototype.forEach.call(el.querySelectorAll('[data-mo-more][aria-expanded="true"]'), function (b) { b.setAttribute('aria-expanded', 'false'); });
    }

    function togglePanel(btn) {
      var open = btn.getAttribute('aria-expanded') === 'true';
      closePanels();
      if (open) return;
      btn.setAttribute('aria-expanded', 'true');
      btn.closest('[data-mo-grab]').insertAdjacentHTML('afterend', moreHtml(host.tree(), Number(btn.dataset.moMore)));
    }

    function removeLocal(id) {
      var tree = JSON.parse(JSON.stringify(host.tree())), me = locate(tree, id);
      if (!me) return;
      var cid = Number(me.path[0].id);
      me.siblings.splice(me.index, 1);
      setTree(tree);
      if (me.depth === 0) {
        var gone = itemEl(el, id);
        if (gone) gone.remove();
        var cols = q(el, '[data-mo-zone="root"]');
        directItems(cols).forEach(function (c, i) { c.replaceChild(frag(headHtml(tree[i], i + 1, tree.length)), c.firstElementChild); });
      } else {
        var old = itemEl(el, cid);
        if (old) old.replaceWith(frag(itemHtml(tree, cid)));
      }
    }

    function del(id) {
      var me = locate(host.tree(), id);
      if (!me || busy()) return;
      Promise.resolve(host.confirmDelete(String(me.node.label))).then(function (yes) {
        if (!yes) return;
        creating = true;
        return host.api('/' + Number(id) + '/delete', { method: 'POST' }).then(function (r) {
          creating = false;
          if (!r || !r.ok) { host.toast('Could not delete that.', 'bad'); return; }
          removeLocal(id);
          host.toast('Deleted');
        }, function () { creating = false; host.toast('Could not delete that.', 'bad'); });
      });
    }

    /* ---- creating: inline rows and the floating sheet ---- */

    var creating = false;
    function busy() { return creating || ctl.busy(); }

    function create(parentId, label, url) {
      label = String(label || '').trim();
      url = String(url || '').trim();
      if (!label) { host.toast('Give it a name first.', 'bad'); return Promise.resolve(false); }
      if (busy()) return Promise.resolve(false);
      creating = true;
      var body = { menu_id: host.menuId(), parent_id: parentId, label: label, url: url || null };
      return host.api('', { method: 'POST', body: JSON.stringify(body) }).then(function (r) {
        creating = false;
        if (!r || !r.ok || !r.data || !r.data.id) {
          host.toast((r && r.data && r.data.errors && r.data.errors[0]) || 'Could not add that.', 'bad');
          return false;
        }
        var tree = JSON.parse(JSON.stringify(host.tree()));
        var node = { id: Number(r.data.id), label: label, url: url || null, icon: null, badge: null, highlight_color: null, visibility: 'always', new_tab: false, children: [] };
        if (parentId === null) tree.push(node);
        else { var p = locate(tree, parentId).node; (p.children = p.children || []).push(node); }
        setTree(tree);
        var cols = q(el, '[data-mo-zone="root"]');
        if (parentId === null) {
          cols.appendChild(frag(columnHtml(node, tree.length, tree.length)));
          var prev = directItems(cols)[tree.length - 2];
          if (prev) prev.replaceChild(frag(headHtml(tree[tree.length - 2], tree.length - 1, tree.length)), prev.firstElementChild);
          var empty = q(el, '.mo-empty');
          if (empty) empty.remove();
        } else {
          var cid = columnOf(tree, node.id), old = itemEl(el, cid);
          if (old) old.replaceWith(frag(itemHtml(tree, cid)));
        }
        flash(node.id);
        host.toast('Added');
        return node.id;
      }, function () { creating = false; host.toast('Could not add that.', 'bad'); return false; });
    }

    function fieldsHtml() {
      return '<input class="mo-in" data-mo-f="label" maxlength="60" placeholder="Name" aria-label="Name">'
        + '<input class="mo-in" data-mo-f="url" maxlength="255" placeholder="/link/ (optional)" aria-label="Link">';
    }

    function openInline(btn) {
      var form = frag('<div class="mo-addrow" data-mo-addform="' + Number(btn.dataset.moAdd) + '">' + fieldsHtml() + '</div>');
      btn.replaceWith(form);
      q(form, '[data-mo-f="label"]').focus();
    }

    function inlineKey(e, form) {
      var pid = Number(form.dataset.moAddform);
      if (e.key === 'Escape') {
        e.preventDefault();
        var btn = frag('<button type="button" class="mo-add' + (locate(host.tree(), pid).depth ? ' mo-add-s' : '') + '" data-mo-add="' + pid + '">'
          + (locate(host.tree(), pid).depth ? '+ Add item' : '+ Add a sub-menu') + '</button>');
        form.replaceWith(btn);
        btn.focus();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        create(pid, q(form, '[data-mo-f="label"]').value, q(form, '[data-mo-f="url"]').value);
      }
    }

    var sheet = q(el, '#moSheet'), fab = q(el, '[data-mo-fab]'), choice = '';

    function sheetForm() {
      var tree = host.tree(), f = q(sheet, '[data-mo-sheet-form]');
      var colSel = function () {
        return '<select class="mo-in" data-mo-f="col" aria-label="Column">' + tree.map(function (n) {
          return '<option value="' + Number(n.id) + '">' + esc(n.label) + '</option>';
        }).join('') + '</select>';
      };
      var html = '';
      if (choice === 'top') html = fieldsHtml();
      else if (choice === 'sub') html = tree.length ? colSel() + fieldsHtml() : '<p class="mo-note">Add a parent menu first.</p>';
      else if (choice === 'link') html = tree.length ? colSel() + '<span data-mo-subs></span>' + fieldsHtml() : '<p class="mo-note">Add a parent menu first.</p>';
      if (html && html.indexOf('mo-note') < 0) html += '<button type="button" class="mo-go" data-mo-sheet-add>Add</button>';
      f.innerHTML = html;
      if (choice === 'link' && tree.length) subsFor();
      var first = q(f, 'select, input');
      if (first) first.focus();
    }

    function subsFor() {
      var colId = Number(q(sheet, '[data-mo-f="col"]').value), col = locate(host.tree(), colId), slot = q(sheet, '[data-mo-subs]');
      var subs = col ? kids(col.node) : [];
      slot.innerHTML = subs.length
        ? '<select class="mo-in" data-mo-f="sub" aria-label="Sub-menu">' + subs.map(function (n) { return '<option value="' + Number(n.id) + '">' + esc(n.label) + '</option>'; }).join('') + '</select>'
        : '<p class="mo-note">This column has no sub-menu yet — add one first.</p>';
    }

    function sheetAdd() {
      var v = function (k) { var x = q(sheet, '[data-mo-f="' + k + '"]'); return x ? x.value : ''; };
      var parent = choice === 'top' ? null : choice === 'sub' ? Number(v('col')) : (v('sub') ? Number(v('sub')) : NaN);
      if (parent !== null && !(parent > 0)) { host.toast('Pick a sub-menu first.', 'bad'); return; }
      create(parent, v('label'), v('url')).then(function (id) { if (id) setSheet(false); });
    }

    function setSheet(open) {
      sheet.hidden = !open;
      fab.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { choice = ''; q(sheet, '[data-mo-sheet-form]').innerHTML = ''; setChoice('top'); }
      else fab.focus();
    }

    function setChoice(c) {
      choice = c;
      Array.prototype.forEach.call(sheet.querySelectorAll('[data-mo-choice]'), function (b) { b.setAttribute('aria-pressed', b.dataset.moChoice === c ? 'true' : 'false'); });
      sheetForm();
    }

    /* ---- drag: live reordering, no measuring, no timers ---- */

    var drag = null, swallowClick = false, refusedZone = null, hot = null;

    function setHot(n) {
      if (hot === n) return;
      if (hot) hot.classList.remove('mo-hot');
      hot = n;
      if (hot) hot.classList.add('mo-hot');
    }

    function setRefused(n) {
      if (refusedZone === n) return;
      if (refusedZone) refusedZone.classList.remove('mo-no');
      refusedZone = n;
      if (refusedZone) refusedZone.classList.add('mo-no');
    }

    function begin() {
      drag.started = true;
      drag.before = host.tree();
      drag.work = drag.before;
      drag.el.classList.add('mo-lift');
      board.classList.add('mo-dragging');
      el.classList.add('mo-dragging');
      closePanels();
    }

    function end(commit) {
      var d = drag;
      drag = null;
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onCancel);
      document.removeEventListener('keydown', onKey, true);
      if (d.hold) d.hold.classList.remove('mo-holding');
      if (!d.started) return;
      d.el.classList.remove('mo-lift');
      swallowClick = true;
      setHot(null); setRefused(null);
      board.classList.remove('mo-dragging');
      el.classList.remove('mo-dragging');
      var plan = commit ? planFrom(d.before, d.work, d.id) : null;
      if (!plan || plan.noop) {
        // Esc, a cancelled touch, or back where it started: the board as the tree says.
        q(el, '[data-mo-zone="root"]').innerHTML = host.tree().map(function (n, i) { return columnHtml(n, i + 1, host.tree().length); }).join('');
        return;
      }
      ctl.run(plan);
    }

    function onKey(e) { if (e.key === 'Escape' && drag) { e.preventDefault(); e.stopPropagation(); end(false); } }
    function onCancel() { if (drag) end(false); }
    function onUp() { if (drag) end(true); }

    function onMove(e) {
      if (!drag || e.pointerId !== drag.pointerId) return;
      var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
      if (!drag.started) {
        if (drag.touch) { if (Math.abs(dx) + Math.abs(dy) > 10) end(false); return; } // a scroll, not a hold
        if (Math.abs(dx) + Math.abs(dy) < 5) return;
        begin();
      }
      e.preventDefault();
      var edge = e.target.closest && e.target.closest('[data-mo-edge]');
      if (edge) board.scrollBy(Number(edge.dataset.moEdge) * 24, 0);
      var m = drag.depth === 0 ? dx : dy;
      if (m) drag.dir = m > 0 ? 1 : -1;
      drag.x = e.clientX; drag.y = e.clientY;
    }

    /*
     * What the pointer has entered, for place(). Only real targets count: a
     * row, a column's header, or an add button (= "last in here"). The gaps
     * between rows are deliberately nothing — counted as "the column" they
     * would throw a link to the bottom of the column every time it crossed
     * one, and back again on the next row: the jitter of a thin target, in a
     * new place.
     */
    function overFor(t) {
      if (drag.depth === 0) {
        var c = t.closest('.mo-col');
        return c ? { kind: 'row', id: Number(c.dataset.moItem), zone: c } : null;
      }
      var link = t.closest('.mo-link');
      if (link) return { kind: 'row', id: Number(link.dataset.moItem), zone: link.parentNode.closest('.mo-grp') };
      var gh = t.closest('.mo-ghead');
      if (gh) return { kind: 'row', id: Number(gh.parentNode.dataset.moItem), zone: gh.closest('.mo-col') };
      var head = t.closest('.mo-head');
      if (head) return { kind: 'row', id: Number(head.parentNode.dataset.moItem), zone: head.parentNode };
      var add = t.closest('[data-mo-add]');
      if (add) return { kind: 'zone', id: Number(add.dataset.moAdd), zone: add.parentNode };
      return null;
    }

    function onOver(e) {
      if (!drag || !drag.started || drag.el.contains(e.target)) return;
      setHot(e.target.closest('.mo-col'));
      var over = overFor(e.target);
      if (!over) return;
      var plan = place(drag.work, drag.id, over, drag.dir);
      if (!plan) { setRefused(null); return; }
      if (!plan.ok) { setRefused(over.zone); return; }
      setRefused(null);

      var was = locate(drag.work, drag.id), wasParent = was.parent ? Number(was.parent.id) : null;
      drag.work = applyPlan(drag.work, plan);
      var zone = plan.parentId === null ? q(el, '[data-mo-zone="root"]') : q(el, '[data-mo-zone="' + plan.parentId + '"]');
      if (!zone) return;
      var depthNow = locate(drag.work, drag.id).depth;
      if (String(depthNow) !== drag.el.dataset.moDepth) {
        // A link becoming a sub-menu (or back): it changes shape, so draw it fresh.
        var fresh = frag(itemHtml(drag.work, drag.id));
        fresh.classList.add('mo-lift');
        drag.el.replaceWith(fresh);
        drag.el = fresh;
      }
      var rest = directItems(zone).filter(function (c) { return c !== drag.el; });
      var ref = rest[plan.position - 1] || null;
      zone.insertBefore(drag.el, ref);

      // The row it passed slides into its new place: one row's travel, by transform only.
      var sib = over.kind === 'row' ? locate(drag.work, over.id) : null;
      if (!reduced && sib && wasParent === plan.parentId && (sib.parent ? Number(sib.parent.id) : null) === plan.parentId) {
        var passed = itemEl(el, over.id);
        if (passed && !passed.className.match(/mo-slide-/)) {
          var movedAfter = ref !== passed;
          passed.classList.add(drag.depth === 0 ? (movedAfter ? 'mo-slide-l' : 'mo-slide-r') : (movedAfter ? 'mo-slide-u' : 'mo-slide-d'));
        }
      }
    }

    function onDown(e) {
      if (swallowClick) swallowClick = false;
      if (drag || e.button > 0 || busy()) return;
      var grab = e.target.closest('[data-mo-grab]');
      if (!grab || e.target.closest('input,select,textarea,a,button')) return;
      var item = grab.hasAttribute('data-mo-item') ? grab : grab.parentNode;
      drag = {
        id: Number(item.dataset.moItem), el: item, depth: Number(item.dataset.moDepth), pointerId: e.pointerId,
        x: e.clientX, y: e.clientY, dir: 1, started: false, touch: e.pointerType !== 'mouse', hold: null,
      };
      // A touch is captured to the element it began on; released, the rows it
      // passes over get their own pointerover, which is all placement reads.
      if (e.target.hasPointerCapture && e.target.hasPointerCapture(e.pointerId)) e.target.releasePointerCapture(e.pointerId);
      if (drag.touch) { drag.hold = grab; grab.classList.add('mo-holding'); }
      document.addEventListener('pointermove', onMove, { passive: false });
      document.addEventListener('pointerup', onUp);
      document.addEventListener('pointercancel', onCancel);
      document.addEventListener('keydown', onKey, true);
    }

    /* ---- one set of listeners on the board's element ---- */

    el.addEventListener('pointerdown', onDown);
    el.addEventListener('pointerover', onOver);
    // Press-and-hold on touch: the hold ring's CSS animation ending arms the drag.
    el.addEventListener('animationend', function (e) {
      var n = e.target;
      if (e.animationName === 'mo-hold' && drag && !drag.started && drag.hold === n) { n.classList.remove('mo-holding'); begin(); return; }
      if (/^mo-(slide|flash)/.test(e.animationName)) n.classList.remove('mo-slide-u', 'mo-slide-d', 'mo-slide-l', 'mo-slide-r', 'mo-flash');
    });
    // While a drag is live, a finger moves the row, not the page.
    el.addEventListener('touchmove', function (e) { if (drag && drag.started) e.preventDefault(); }, { passive: false });
    el.addEventListener('contextmenu', function (e) { if (drag) e.preventDefault(); });

    el.addEventListener('click', function (e) {
      if (swallowClick) { swallowClick = false; e.preventDefault(); e.stopPropagation(); return; }
      var t = e.target, b;
      if ((b = t.closest('[data-mo-step]')) && !b.disabled) {
        var id = Number(b.dataset.moId), d = Number(b.dataset.moStep);
        ctl.step(id, d);
        refocus(id, '[data-mo-step="' + d + '"]');
      } else if ((b = t.closest('[data-mo-more]'))) togglePanel(b);
      else if ((b = t.closest('[data-mo-edit]'))) host.edit(Number(b.dataset.moEdit));
      else if ((b = t.closest('[data-mo-del]'))) del(Number(b.dataset.moDel));
      else if ((b = t.closest('[data-mo-add]'))) openInline(b);
      else if ((b = t.closest('[data-mo-fab]'))) setSheet(sheet.hidden);
      else if (t.closest('[data-mo-sheet-close]')) setSheet(false);
      else if ((b = t.closest('[data-mo-choice]'))) setChoice(b.dataset.moChoice);
      else if (t.closest('[data-mo-sheet-add]')) sheetAdd();
    });

    el.addEventListener('keydown', function (e) {
      var t = e.target, box = t.closest('[data-mo-num]'), form = t.closest('[data-mo-addform]');
      if (box) {
        if (e.key === 'Enter') { e.preventDefault(); commitNum(box); }
        else if (e.key === 'Escape') { box.value = box.defaultValue; box.blur(); }
      } else if (form) inlineKey(e, form);
      else if (t.closest('[data-mo-sheet-form]') && e.key === 'Enter' && t.tagName === 'INPUT') { e.preventDefault(); sheetAdd(); }
      else if (!sheet.hidden && e.key === 'Escape' && t.closest('#moSheet')) { e.preventDefault(); setSheet(false); }
      else if ((e.key === 'Enter' || e.key === ' ') && t.matches('.mo-name')) { e.preventDefault(); host.edit(Number(t.dataset.moEdit)); }
    });

    el.addEventListener('focusout', function (e) {
      var box = e.target.closest && e.target.closest('[data-mo-num]');
      if (box && box.value !== box.defaultValue) commitNum(box);
    });

    el.addEventListener('change', function (e) {
      var s = e.target.closest('[data-mo-under]');
      if (s && s.value) { closePanels(); ctl.under(Number(s.dataset.moUnder), s.value); return; }
      if (e.target.matches('[data-mo-f="col"]') && choice === 'link') subsFor();
    });

    return { controller: ctl, create: create };
  }

  return {
    MAX_DEPTH: MAX_DEPTH,
    locate: locate,
    subtreeDepth: subtreeDepth,
    canMoveUnder: canMoveUnder,
    parentChoices: parentChoices,
    parsePosition: parsePosition,
    planMove: planMove,
    planPosition: planPosition,
    planStep: planStep,
    place: place,
    planFrom: planFrom,
    applyPlan: applyPlan,
    controller: controller,
    boardHtml: boardHtml,
    itemHtml: itemHtml,
    moreHtml: moreHtml,
    fabHtml: fabHtml,
    mount: mount,
    esc: esc,
  };
});
