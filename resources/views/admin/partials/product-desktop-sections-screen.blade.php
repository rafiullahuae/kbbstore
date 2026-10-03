{{--
    Appearance → Product page → Desktop sections.                     (Lane RF)

      "also give option to change position in desktop also fo the sections.
       make all those changes carefully without disturbing other things. and
       super fast and optimized."

    ONE MORE TAB, BESIDE MOBILE SECTIONS. Included from the end of
    product-mobile-sections-screen.blade.php, so this screen wraps
    paintProductPage() straight after that one does and its button lands
    directly after "Mobile sections" in the strip -- and the console's own
    file (admin/app.blade.php) is not touched.

    ── WHAT THE TAB HOLDS ─────────────────────────────────────────────────────

      · The photo and buy column, drawn as a fixed first row: on a laptop they
        are one two-column block and they stay at the top.
      · The four full-width blocks under them, in the order the laptop draws
        them — Buy these together, Product details, Reviews, You may also
        like. Each row has a drag handle and ↑ / ↓ buttons; a block switched
        off for laptops on the Sections tab says so.
      · "Reset to the default order", which the console's reset guard asks
        about first — it matches the label.

    (Lane RG) AND NOW THE BUY COLUMN TOO, AND A LAPTOP SWITCH ON EVERY ROW.
      "also give control to hide unhide any section on desktop too" and
      "also controls for changing positions of the sections on desktop too."
      · A second list above the four: the buy column's eleven blocks, in the
        order the laptop draws them, draggable within that list.
      · Every row carries its laptop on/off switch. A row that IS a Sections-
        tab module (bundles → Options / bundles, details → Detail tabs, …)
        flips that module's Desktop value in the console's own PP.sections
        model, so the Sections tab and this tab show one switch twice and the
        server stores one value (ProductDesktopSections::saveLaptop()).
      · The photo stays fixed: it is the laptop page's left column.
    How a reordered buy column keeps its spacing:
    App\Services\ProductDesktopSections' header.

    ── MOVING A ROW: THREE WAYS, NONE OF THEM MEASURES ANYTHING ──────────────

      mouse     HTML5 drag and drop, from the handle.
      finger    Pointer events on the handle, the row under the finger found
                by hit-testing the point (document.elementFromPoint).
      keyboard  ↑ / ↓ buttons on every row, and the arrow keys on the handle.

    ── THE PREVIEW ────────────────────────────────────────────────────────────

    The screen's laptop frame is the real product page at 1280px. This writes
    the class and the `--pds-o-*` properties ProductDesktopSections::
    wrapperClass() / wrapperStyle() would print onto its `.pdp-page`, as he
    drags — and takes them off again when the order is back to the default,
    exactly as the server prints nothing then. The phone frame carries them
    too and ignores them: the stylesheet reads them at 881px and up only.

    (Lane RI) BUY THESE TOGETHER CROSSES BETWEEN THE TWO LISTS.
      "ONLY IN DESKTOP: allow me option to bring the buy together section to
       the right collumn, by drag n drop."
      · Its row alone may be dropped into the other list, onto any row, by
        the rule the lists already use: a block moved UP lands above the row
        it is dropped on (the pink line on that row's top edge), a block moved
        DOWN lands below it (the line on its bottom edge). The Buy column is
        drawn above the full-width list, so dragging it in from below lands it
        above the row, and dragging it back out lands it below the row.
      · ↑ / ↓ walk it through both lists as one sequence — up from the top of
        the full-width list lands it last in the Buy column, down from the
        bottom of the Buy column lands it first under the columns.
      · "Move to right column" puts it under the Authenticity row (where his
        screenshot has the empty space); "Move below the columns" puts it back
        first under them, where it ships.
      · The preview frames MOVE the one `.kbb-fbt` node — into `.buybox`, or
        back after `.pdp` — exactly where the template draws it, then set the
        classes and properties as before. A DOM move, never a measurement.
    The server checks it sits in one list only (ProductDesktopSections::
    validatePlacement()).

    ── RULE 5 ─────────────────────────────────────────────────────────────────

    Every string reaches the DOM through escHtml/escAttr. A key must be one the
    server sent and match /^[a-z]+$/; an order is a permutation of them. The
    server validates it again (ProductPageApiController::save()).
--}}
@verbatim
<style>
.pds-list{list-style:none;margin:0;padding:0}
.pds-row{display:grid;grid-template-columns:30px 22px minmax(0,1fr) auto;column-gap:10px;align-items:center;
  padding:11px 14px 11px 8px;border-bottom:1px solid #eef1f5;background:#fff;transition:background .12s}
.pds-row:last-child{border-bottom:0}
.pds-row.dragging{background:#fdf2f6;opacity:.65}
.pds-row.over{box-shadow:inset 0 3px 0 #E0567B}
.pds-row.over.after{box-shadow:inset 0 -3px 0 #E0567B}
.pds-row.off .pds-main b{color:#8b95a5}
.pds-row.off .pds-main b::after{content:' · off on laptops';font-weight:500;color:#a3acb9}
.pds-fixed{display:flex;align-items:center;gap:10px;padding:11px 14px;background:#f8fafc;border-bottom:1px solid #eef1f5;font-size:12.5px;color:#5b6576}
.pds-fixed b{font-size:13.5px;color:#3a4252}
.pds-fixed i{font-style:normal;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#a3acb9;margin-inline-start:auto}
.pds-handle{inline-size:30px;block-size:36px;border:0;background:none;border-radius:8px;cursor:grab;color:#8b95a5;
  display:grid;place-items:center;touch-action:none;font-size:18px;line-height:1}
.pds-handle:hover{background:#f2f5f8;color:#3a4252}
.pds-handle:active{cursor:grabbing}
.pds-handle:focus-visible,.pds-arrow:focus-visible{outline:2px solid #E0567B;outline-offset:1px}
.pds-num{font-size:12px;font-weight:700;color:#a3acb9;text-align:end}
.pds-main{min-inline-size:0;display:flex;flex-direction:column;gap:2px}
.pds-main b{font-size:13.5px}
.pds-main > span{font-size:12px;color:#7b8697}
.pds-arrows{display:inline-flex;gap:4px}
.pds-arrow{inline-size:30px;block-size:30px;border:1px solid #dfe4ec;background:#fff;border-radius:7px;cursor:pointer;font-size:14px;line-height:1;color:#3a4252}
.pds-arrow[disabled]{opacity:.35;cursor:default}
.pds-note{font-size:12px;color:#5b6576;margin:0;padding:10px 14px;background:#fbf6ff;border-bottom:1px solid #eef1f5}
.pds-sub{background:#fff;padding-block:9px 7px}
.pds-sub b{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#7b8697}
.pds-same{font-style:normal;color:#a3acb9}
.pds-arrows .ectog{margin-inline-end:8px;align-self:center}
/* (Lane RI) Buy these together's own button, under its description. */
.pds-move{align-self:flex-start;margin-block-start:6px;block-size:28px;padding:0 10px;border:1px solid #f3c2d1;background:#fdf2f6;color:#b8335c;
  border-radius:7px;cursor:pointer;font-size:12px;font-weight:600;white-space:nowrap}
.pds-move:hover{background:#fbe3ec}
.pds-move:focus-visible{outline:2px solid #E0567B;outline-offset:1px}
.pds-row.pds-mv{background:#fffafb}
@media (max-width:600px){
  .pds-row{grid-template-columns:30px minmax(0,1fr) auto;padding-inline:6px 10px}
  .pds-move{padding-inline:8px;font-size:11.5px}
  .pds-num{display:none}
}
</style>
<script>
(function () {
  'use strict';

  if (typeof window.paintProductPage !== 'function' || window.paintProductPage.__pds) return;

  var TAB = 'dsections';
  var DIRTY = false;
  /* Two lists (Lane RG added the first): `buy` is the laptop buy column,
     `under` the four full-width blocks under the two columns (Lane RF). */
  var LISTS = null;   // { buy: [keys], under: [keys] }
  var ROWS = null;    // key => {key,label,description,module}
  var SW = null;      // key => on a laptop? (rows no Sections-tab module owns)
  var DRAG = null;    // key being dragged

  /* The selector each block is drawn with, for the preview's "is it on this
     page at all" — an existence check, never a measurement. */
  var BLOCK = { buytogether: '.kbb-fbt', details: '.pm-details', reviews: '.sr', related: '.ymal' };
  var BUYSEL = { title: '.pm-title', price: '.pm-price', short: '.pm-short:not(.pm-short-set)', paylater: '.pm-paylater', bundles: '.pm-bundles',
    ready: '.pm-ready', delivery: '.kbb-cart-form > .pts-del', cart: '.pm-cart', auth: '.kbb-cart-form > .pts-stack', trust: '.pm-trust', paychips: '.pm-paychips',
    buytogether: '.buybox > .kbb-fbt' };
  /* (Lane RI) The one block allowed in both lists. */
  var MOVABLE = 'buytogether';

  function data() { return (typeof PP !== 'undefined' && PP && PP.dsections) ? PP.dsections : null; }

  function load() {
    var d = data();
    if (!d || !Array.isArray(d.list)) { LISTS = null; ROWS = null; SW = null; return; }
    LISTS = { buy: [], under: [] }; ROWS = {}; SW = {};
    [['buy', Array.isArray(d.buy) ? d.buy : []], ['under', d.list]].forEach(function (pair) {
      pair[1].forEach(function (r) {
        if (!r || typeof r.key !== 'string' || !/^[a-z]+$/.test(r.key) || ROWS[r.key]) return;
        LISTS[pair[0]].push(r.key);
        ROWS[r.key] = { key: r.key, label: String(r.label), description: String(r.description),
          module: (typeof r.module === 'string' && /^[a-z]+$/.test(r.module)) ? r.module : null };
        SW[r.key] = r.desktop !== false;
      });
    });
  }

  function listOf(k) { return !LISTS ? null : (LISTS.buy.indexOf(k) >= 0 ? 'buy' : (LISTS.under.indexOf(k) >= 0 ? 'under' : null)); }

  /* ONE VALUE PER SWITCH. A row that IS a Sections-tab module reads and
     writes that module's Desktop value in the console's own model
     (PP.sections), so this tab and the Sections tab are one switch drawn
     twice; the server stores it in the same row (ProductDesktopSections::
     saveLaptop()). */
  function moduleRow(k) {
    var m = ROWS && ROWS[k] && ROWS[k].module;
    if (!m || typeof PP === 'undefined' || !PP || !Array.isArray(PP.sections)) return null;
    for (var i = 0; i < PP.sections.length; i++) if (PP.sections[i] && PP.sections[i].key === m) return PP.sections[i];
    return null;
  }
  function on(k) { var s = moduleRow(k); return s ? s.desktop !== false : (SW ? SW[k] !== false : true); }

  function defaults(name) {
    var d = data(), field = name === 'buy' ? 'buy_defaults' : 'defaults';
    var list = (d && Array.isArray(d[field])) ? d[field] : [];
    return list.filter(function (k) { return ROWS && !!ROWS[k] && listOf(k) === name; });
  }

  /** (Lane RI) Both lists as they ship — Buy these together under the columns. */
  function shipped() {
    var d = data(), keep = function (k) { return !!(ROWS && ROWS[k]); };
    var under = (d && Array.isArray(d.defaults) ? d.defaults : []).filter(keep);
    var buy = (d && Array.isArray(d.buy_defaults) ? d.buy_defaults : []).filter(function (k) { return keep(k) && under.indexOf(k) < 0; });
    return { buy: buy, under: under };
  }

  function isDefault(name) { return LISTS !== null && LISTS[name].join(',') === defaults(name).join(','); }

  /* ── what the server would print, computed here for the preview ────────── */

  /** ProductDesktopSections::afterReviews(), against what this frame draws. */
  function afterReviews(page) {
    var drawn = function (k) { return !!(ROWS[k] && on(k) && page.querySelector(':scope > ' + BLOCK[k])); };
    var at = LISTS.under.indexOf('reviews');
    if (at < 0 || !drawn('reviews')) return null;
    for (var i = at + 1; i < LISTS.under.length; i++) if (drawn(LISTS.under[i])) return LISTS.under[i];
    return null;
  }

  /** ProductDesktopSections::firstBuy(), against what this frame draws. */
  function firstBuy(page) {
    for (var i = 0; i < LISTS.buy.length; i++) {
      var k = LISTS.buy[i], el = BUYSEL[k] && page.querySelector(BUYSEL[k]);
      if (on(k) && el && el.firstElementChild) return k;
    }
    return null;
  }

  function paintFrames() {
    if (!LISTS) return;
    document.querySelectorAll('[data-ppframe]').forEach(function (fr) {
      var doc = null;
      try { doc = fr.contentDocument; } catch (e) { doc = null; }
      var page = doc && doc.querySelector('.pdp-page');
      if (!page) return;
      /* (Lane RI) The one `.kbb-fbt` goes where the template would draw it. */
      var bt = page.querySelector('.kbb-fbt'), pdp = page.querySelector(':scope > .pdp'), box = pdp && pdp.querySelector(':scope > .buybox');
      if (bt && box && listOf(MOVABLE) === 'buy') { if (bt.parentNode !== box) box.appendChild(bt); }
      else if (bt && pdp && bt.parentNode !== page) pdp.after(bt);
      Array.prototype.slice.call(page.classList).forEach(function (cl) { if (/^(pds|pdsb|pd-off)-/.test(cl)) page.classList.remove(cl); });
      Object.keys(BLOCK).forEach(function (k) { page.style.removeProperty('--pds-o-' + k); });
      Object.keys(BUYSEL).forEach(function (k) { page.style.removeProperty('--pdsb-o-' + k); });
      Object.keys(ROWS).forEach(function (k) { if (!on(k) && /^[a-z]+$/.test(k)) page.classList.add('pd-off-' + k); });
      if (!isDefault('under')) {
        page.classList.add('pds-on');
        var a = afterReviews(page);
        if (a && /^[a-z]+$/.test(a)) page.classList.add('pds-ar-' + a);
        LISTS.under.forEach(function (k, i) { if (/^[a-z]+$/.test(k)) page.style.setProperty('--pds-o-' + k, String(i + 1)); });
      }
      if (!isDefault('buy')) {
        page.classList.add('pdsb-on');
        var f = firstBuy(page);
        if (f && /^[a-z]+$/.test(f)) page.classList.add('pdsb-f-' + f);
        LISTS.buy.forEach(function (k, i) { if (/^[a-z]+$/.test(k)) page.style.setProperty('--pdsb-o-' + k, String(i + 1)); });
      }
    });
  }

  function markDirty() {
    DIRTY = true;
    var d = document.getElementById('ppDirty');
    if (d) { d.style.visibility = 'visible'; d.classList.remove('ok'); d.textContent = 'Unsaved changes'; }
    paintFrames();
  }

  /* ── drawing ───────────────────────────────────────────────────────────── */

  function row(k, i, all) {
    var r = ROWS[k], n = all.length, o = on(k), mv = k === MOVABLE, inBuy = listOf(k) === 'buy';
    /* (Lane RI) Buy these together walks both lists as one sequence. */
    var upOff = mv ? (inBuy && i === 0) : i === 0, downOff = mv ? (!inBuy && i === n - 1) : i === n - 1;
    var move = mv ? '<button type="button" class="pds-move" data-pds-move="' + escAttr(k) + '">' + (inBuy ? 'Move below the columns' : 'Move to right column') + '</button>' : '';
    return '<li class="pds-row' + (o ? '' : ' off') + (mv ? ' pds-mv' : '') + '" data-pds-row="' + escAttr(k) + '">'
      + '<button type="button" class="pds-handle" draggable="true" data-pds-handle="' + escAttr(k) + '" aria-label="' + escAttr('Move ' + r.label + ' — drag, or use the arrow keys') + '" title="Drag to move">⠿</button>'
      + '<span class="pds-num">' + (i + 1) + '</span>'
      + '<div class="pds-main"><b>' + escHtml(r.label) + '</b><span>' + escHtml(r.description) + (r.module ? ' <em class="pds-same">Same switch as the Sections tab’s Desktop column.</em>' : '') + (mv ? ' <em class="pds-same">Drag it between the two lists — laptops only; phones follow Mobile sections.</em>' : '') + '</span>' + move + '</div>'
      + '<span class="pds-arrows"><span class="ectog' + (o ? ' on' : '') + '" data-pds-sw="' + escAttr(k) + '" role="switch" aria-checked="' + (o ? 'true' : 'false') + '" tabindex="0" aria-label="' + escAttr(r.label + ' on laptops') + '" title="Show on laptops"></span>'
      + '<button type="button" class="pds-arrow" data-pds-up="' + escAttr(k) + '"' + (upOff ? ' disabled' : '') + ' aria-label="' + escAttr('Move ' + r.label + ' up') + '">↑</button>'
      + '<button type="button" class="pds-arrow" data-pds-down="' + escAttr(k) + '"' + (downOff ? ' disabled' : '') + ' aria-label="' + escAttr('Move ' + r.label + ' down') + '">↓</button></span>'
      + '</li>';
  }

  function rows(name) { return LISTS[name].map(function (k, i, all) { return row(k, i, all); }).join(''); }

  function body() {
    return '<div class="mmcols"><div class="card mmcard">'
      + '<div class="mmhd"><b>Desktop sections</b><span>The laptop product page, top to bottom. The switch on each row shows or hides that block on laptops (phones are the Mobile sections tab). Drag a block by its handle (or use ↑ ↓) to change where it sits.</span></div>'
      + '<p class="pds-note">A block switched off here is hidden from 881px up — the laptop layout — and still shows on phones if Mobile sections has it on. Rows marked “Same switch as the Sections tab” are one setting drawn in two places: switching either one switches both.</p>'
      + '<div class="pds-fixed"><b>Photo</b><span>The gallery, the page’s left column.</span><i>Always shown</i></div>'
      + '<div class="pds-fixed pds-sub"><b>Buy column</b><span>Beside the photo. Moving a block here gives each one the space above it that its own slider sets (Spacing · Buy column, Trust · Spacing).</span></div>'
      + '<ol class="pds-list" id="pdsBuy" data-pds-list="buy">' + rows('buy') + '</ol>'
      + '<div class="pds-fixed pds-sub"><b>Under the two columns</b><span>The full-width blocks. Buy these together can be dragged up into the Buy column (laptops only).</span></div>'
      + '<ol class="pds-list" id="pdsList" data-pds-list="under">' + rows('under') + '</ol></div></div>';
  }

  function repaintList(focusSel) {
    [['pdsBuy', 'buy'], ['pdsList', 'under']].forEach(function (p) {
      var list = document.getElementById(p[0]);
      if (list) list.innerHTML = rows(p[1]);
    });
    if (focusSel) { var el = document.querySelector('#pdsBuy ' + focusSel + ',#pdsList ' + focusSel); if (el) el.focus(); }
  }

  /* ── the wrap ──────────────────────────────────────────────────────────── */

  var original = window.paintProductPage;

  var wrapped = function (rebuild) {
    var ours = (typeof PPTAB !== 'undefined' && PPTAB === TAB);
    original.apply(this, arguments);
    if (!data()) return;
    if (LISTS === null || rebuild) { if (!DIRTY) load(); }
    if (!LISTS) return;

    var strip = document.querySelector('#ppStrip .ectabs');
    if (strip && !strip.querySelector('[data-pdstab]')) {
      var btn = '<button class="ectab' + (ours ? ' on' : '') + '" data-pdstab="' + TAB + '">Desktop sections<span class="ecn">' + (LISTS.buy.length + LISTS.under.length) + '</span></button>';
      var mob = strip.querySelector('[data-pmstab]');
      if (mob) mob.insertAdjacentHTML('afterend', btn); else strip.insertAdjacentHTML('beforeend', btn);
      strip.querySelector('[data-pdstab]').onclick = function () { PPTAB = TAB; window.paintProductPage(); };
    }

    if (ours) {
      var col = document.getElementById('ppCol');
      if (col) col.innerHTML = body();
      var lede = document.getElementById('ppLede');
      if (lede) lede.textContent = 'The laptop product page — switch any block off for laptops, and drag to put them in any order. The laptop preview follows as you go; nothing changes on the shop until you press Save changes.';
      var acts = document.getElementById('ppActs');
      if (acts) acts.innerHTML = '<button class="btn" id="pdsReset">Reset to the default order</button> <button class="btn" id="pdsDiscard">Discard</button>';
      if (DIRTY) { var d = document.getElementById('ppDirty'); if (d) { d.style.visibility = 'visible'; d.textContent = 'Unsaved changes'; } }
    }
    paintFrames();
  };
  wrapped.__pds = true;
  window.paintProductPage = wrapped;

  if (document.getElementById('ppShell') && typeof PP !== 'undefined' && PP) window.paintProductPage();

  document.addEventListener('load', function (e) {
    if (e.target && e.target.matches && e.target.matches('[data-ppframe]')) paintFrames();
  }, true);

  /* ── moving: within its own list only ──────────────────────────────────── */

  function moveTo(key, to) {
    var name = listOf(key);
    if (!name) return;
    var L = LISTS[name], from = L.indexOf(key);
    to = Math.max(0, Math.min(L.length - 1, to));
    if (to === from) return;
    L.splice(from, 1);
    L.splice(to, 0, key);
    markDirty();
  }

  /** (Lane RI) Into list `name` at `index`, out of whichever list held it. */
  function place(key, name, index) {
    if (key !== MOVABLE || !LISTS[name]) return;
    ['buy', 'under'].forEach(function (n) { var at = LISTS[n].indexOf(key); if (at >= 0) LISTS[n].splice(at, 1); });
    LISTS[name].splice(Math.max(0, Math.min(LISTS[name].length, index)), 0, key);
    markDirty();
  }

  /** (Lane RI) ↑ / ↓ for Buy these together: both lists are one sequence. */
  function step(key, delta) {
    var name = listOf(key), L = name && LISTS[name], at = L ? L.indexOf(key) : -1;
    if (at < 0) return;
    if (key === MOVABLE && name === 'under' && delta < 0 && at === 0) return place(key, 'buy', LISTS.buy.length);
    if (key === MOVABLE && name === 'buy' && delta > 0 && at === L.length - 1) return place(key, 'under', 0);
    moveTo(key, at + delta);
  }

  /** (Lane RI) The button: under Authenticity, or back to first under the columns. */
  function toggleSide(key) {
    if (listOf(key) === 'buy') return place(key, 'under', 0);
    var a = LISTS.buy.indexOf('auth');
    place(key, 'buy', a < 0 ? LISTS.buy.length : a + 1);
  }

  function dropOn(key, target) {
    if (!key || !target || key === target) return;
    var name = listOf(key);
    if (!name) return;
    if (listOf(target) !== name) {
      // Only Buy these together crosses. Up into the Buy column: above the
      // row. Down out of it: below the row — the in-list rule, see markOver().
      var into = listOf(target);
      if (key !== MOVABLE || !into) return;
      place(key, into, LISTS[into].indexOf(target) + (into === 'under' ? 1 : 0));
    } else {
      moveTo(key, LISTS[name].indexOf(target));
    }
    repaintList('[data-pds-handle="' + key + '"]');
  }



  function clearMarks() {
    document.querySelectorAll('.pds-row.over,.pds-row.dragging').forEach(function (r) { r.classList.remove('over', 'after', 'dragging'); });
  }

  function markOver(rowEl) {
    document.querySelectorAll('.pds-row.over').forEach(function (r) { if (r !== rowEl) r.classList.remove('over', 'after'); });
    if (!rowEl || !DRAG) return;
    var k = rowEl.getAttribute('data-pds-row'), name = listOf(DRAG);
    if (k === DRAG) return;
    if (listOf(k) !== name) {
      // (Lane RI) Only Buy these together crosses: the line shows where it lands.
      if (DRAG !== MOVABLE || !listOf(k)) return;
      rowEl.classList.add('over');
      rowEl.classList.toggle('after', listOf(k) === 'under');
      return;
    }
    rowEl.classList.add('over');
    rowEl.classList.toggle('after', LISTS[name].indexOf(k) > LISTS[name].indexOf(DRAG));
  }

  /** The row under a point / event target. */
  function targetOf(el) { return el && el.closest ? el.closest('.pds-row') : null; }
  function dropAt(k, t) { if (t) dropOn(k, t.getAttribute('data-pds-row')); }

  // mouse: HTML5 drag and drop, started from the handle
  document.addEventListener('dragstart', function (e) {
    var h = e.target.closest && e.target.closest('[data-pds-handle]');
    if (!h || !LISTS) return;
    DRAG = h.getAttribute('data-pds-handle');
    var r = h.closest('.pds-row'); if (r) r.classList.add('dragging');
    try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', DRAG); if (r) e.dataTransfer.setDragImage(r, 16, 16); } catch (err) {}
  });
  document.addEventListener('dragover', function (e) {
    if (!DRAG) return;
    var r = targetOf(e.target);
    if (!r) return;
    e.preventDefault();
    markOver(r);
  });
  document.addEventListener('drop', function (e) {
    if (!DRAG) return;
    var r = targetOf(e.target);
    e.preventDefault();
    var k = DRAG; DRAG = null; clearMarks();
    dropAt(k, r);
  });
  document.addEventListener('dragend', function () { if (DRAG) { DRAG = null; clearMarks(); } });

  // finger / pen: pointer events on the handle, hit-testing the point
  var PTR = null;
  document.addEventListener('pointerdown', function (e) {
    var h = e.target.closest && e.target.closest('[data-pds-handle]');
    if (!h || e.pointerType === 'mouse' || !LISTS) return;
    e.preventDefault();
    DRAG = h.getAttribute('data-pds-handle');
    PTR = e.pointerId;
    try { h.setPointerCapture(e.pointerId); } catch (err) {}
    var r = h.closest('.pds-row'); if (r) r.classList.add('dragging');
  });
  document.addEventListener('pointermove', function (e) {
    if (PTR === null || e.pointerId !== PTR || !DRAG) return;
    markOver(targetOf(document.elementFromPoint(e.clientX, e.clientY)));
  });
  function endPointer(e, commit) {
    if (PTR === null || e.pointerId !== PTR) return;
    var k = DRAG, target = commit ? targetOf(document.elementFromPoint(e.clientX, e.clientY)) : null;
    PTR = null; DRAG = null; clearMarks();
    dropAt(k, target);
  }
  document.addEventListener('pointerup', function (e) { endPointer(e, true); });
  document.addEventListener('pointercancel', function (e) { endPointer(e, false); });

  // keyboard: arrow keys on the handle; Space / Enter on a switch
  document.addEventListener('keydown', function (e) {
    var sw = e.target.closest && e.target.closest('[data-pds-sw]');
    if (sw && (e.key === ' ' || e.key === 'Enter')) { e.preventDefault(); toggle(sw.getAttribute('data-pds-sw')); return; }
    var h = e.target.closest && e.target.closest('[data-pds-handle]');
    if (!h || !LISTS || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    var k = h.getAttribute('data-pds-handle');
    if (!listOf(k)) return;
    step(k, e.key === 'ArrowUp' ? -1 : 1);
    repaintList('[data-pds-handle="' + k + '"]');
  });

  /* ── the laptop switch ─────────────────────────────────────────────────── */

  function toggle(k) {
    if (!ROWS || !ROWS[k]) return;
    var next = !on(k), s = moduleRow(k);
    if (s) {
      /* The Sections tab's own row, in the console's model: its next Save
         posts it too, with the same value this tab posts. */
      s.desktop = next;
      if (typeof ppMarkDirty === 'function') ppMarkDirty('sections');
    }
    SW[k] = next;
    markDirty();
    repaintList('[data-pds-sw="' + k + '"]');
  }

  /* ── buttons and Save ──────────────────────────────────────────────────── */

  window.addEventListener('click', async function (e) {
    var t = e.target;
    if (!t || typeof PP === 'undefined' || !PP || !LISTS) return;

    var sw = t.closest && t.closest('[data-pds-sw]');
    if (sw) { toggle(sw.getAttribute('data-pds-sw')); return; }

    var up = t.closest && t.closest('[data-pds-up]'), dn = t.closest && t.closest('[data-pds-down]');
    if (up || dn) {
      var key = (up || dn).getAttribute(up ? 'data-pds-up' : 'data-pds-down');
      if (!listOf(key)) return;
      step(key, up ? -1 : 1);
      repaintList('[data-pds-' + (up ? 'up' : 'down') + '="' + key + '"]:not([disabled])');
      return;
    }
    var mvb = t.closest && t.closest('[data-pds-move]');
    if (mvb) {
      var mk = mvb.getAttribute('data-pds-move');
      if (mk !== MOVABLE || !listOf(mk)) return;
      toggleSide(mk);
      repaintList('[data-pds-move="' + mk + '"]');
      return;
    }
    if (t.id === 'pdsReset') {
      /* ASKED FIRST: the console's reset guard (document, capture) stops the
         first click, asks, and on "Yes" clicks again carrying
         data-kbb-sure-pass — the same contract Mobile sections' Reset keeps.
         A buffer change, not a save. Both ORDERS go back; the switches stay. */
      if (t.getAttribute('data-kbb-sure-pass') !== '1') return;
      LISTS = shipped();
      markDirty(); window.paintProductPage(); return;
    }
    if (t.id === 'pdsDiscard' || t.id === 'pmsDiscard' || t.id === 'ppDiscard' || t.id === 'ptsDiscard') {
      DIRTY = false; LISTS = null;
      if (t.id === 'pdsDiscard') renderProductPage();
      return;
    }

    if (t.id !== 'ppSave' || !DIRTY) return;

    var theirs = typeof PPDIRTY !== 'undefined' && PPDIRTY && Object.keys(PPDIRTY).some(function (k2) { return !!PPDIRTY[k2]; });
    if (!theirs) { e.stopPropagation(); e.preventDefault(); }

    var msg = document.getElementById('ppDirty');
    var laptop = {};
    Object.keys(ROWS).forEach(function (k) { laptop[k] = on(k); });

    try {
      var r = await fetch(ppBase(), { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': uToken(), Accept: 'application/json' },
        body: JSON.stringify({ dsections: { order: LISTS.under.slice(), buy: LISTS.buy.slice(), laptop: laptop } }) });
      var j = null;
      try { j = await r.json(); } catch (e2) { j = null; }
      if (r.status === 404 && (!j || (!j.message && !j.error))) {
        if (msg) { msg.style.visibility = 'visible'; msg.textContent = 'The Product page endpoint is not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'; }
        return;
      }
      if (j && j.ok) {
        if (j.dsections) PP.dsections = j.dsections;
        if (Array.isArray(j.sections) && !theirs) PP.sections = j.sections;
        DIRTY = false; LISTS = null; load();
        if (!theirs) {
          window.paintProductPage();
          if (typeof ppReloadPreview === 'function') ppReloadPreview();
          var m2 = document.getElementById('ppDirty');
          if (m2) { m2.style.visibility = 'visible'; m2.classList.add('ok'); m2.textContent = 'Saved ' + j.saved + ' settings — live now';
            setTimeout(function () { m2.classList.remove('ok'); m2.textContent = 'Unsaved changes'; m2.style.visibility = 'hidden'; }, 2600); }
        }
      } else if (msg) { msg.style.visibility = 'visible'; msg.textContent = (j && j.error) || 'Could not save.'; }
    } catch (err) {
      if (msg) { msg.style.visibility = 'visible'; msg.textContent = 'Could not save — check your connection.'; }
    }
  }, true);
})();
</script>
@endverbatim
