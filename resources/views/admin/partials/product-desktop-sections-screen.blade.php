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

    Why only those four, and not the blocks inside the buy column:
    App\Services\ProductDesktopSections' header carries the measurement.

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
.pds-row.off .pds-main b::after{content:' · off on laptops (Sections tab)';font-weight:500;color:#a3acb9}
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
@media (max-width:600px){
  .pds-row{grid-template-columns:30px minmax(0,1fr) auto;padding-inline:6px 10px}
  .pds-num{display:none}
}
</style>
<script>
(function () {
  'use strict';

  if (typeof window.paintProductPage !== 'function' || window.paintProductPage.__pds) return;

  var TAB = 'dsections';
  var DIRTY = false;
  var ORDER = null;   // working copy: list of keys
  var ROWS = null;    // key => {key,label,description,desktop}
  var DRAG = null;    // key being dragged

  /* The selector each block is drawn with, for the preview's "is it on this
     page at all" — an existence check, never a measurement. */
  var BLOCK = { buytogether: '.kbb-fbt', details: '.pm-details', reviews: '.sr', related: '.ymal' };

  function data() { return (typeof PP !== 'undefined' && PP && PP.dsections) ? PP.dsections : null; }

  function load() {
    var d = data();
    if (!d || !Array.isArray(d.list)) { ORDER = null; ROWS = null; return; }
    ORDER = []; ROWS = {};
    d.list.forEach(function (r) {
      if (!r || typeof r.key !== 'string' || !/^[a-z]+$/.test(r.key) || ROWS[r.key]) return;
      ORDER.push(r.key);
      ROWS[r.key] = { key: r.key, label: String(r.label), description: String(r.description), desktop: r.desktop !== false };
    });
  }

  function defaults() {
    var d = data();
    var list = (d && Array.isArray(d.defaults)) ? d.defaults : [];
    return list.filter(function (k) { return ROWS && !!ROWS[k]; });
  }

  function isDefault() { return ORDER !== null && ORDER.join(',') === defaults().join(','); }

  /* ── what the server would print, computed here for the preview ────────── */

  /** ProductDesktopSections::afterReviews(), against what this frame draws. */
  function afterReviews(page) {
    var drawn = function (k) { return !!(ROWS[k] && ROWS[k].desktop && page.querySelector(':scope > ' + BLOCK[k])); };
    var at = ORDER.indexOf('reviews');
    if (at < 0 || !drawn('reviews')) return null;
    for (var i = at + 1; i < ORDER.length; i++) if (drawn(ORDER[i])) return ORDER[i];
    return null;
  }

  function paintFrames() {
    if (!ORDER) return;
    var off = isDefault();
    document.querySelectorAll('[data-ppframe]').forEach(function (fr) {
      var doc = null;
      try { doc = fr.contentDocument; } catch (e) { doc = null; }
      var page = doc && doc.querySelector('.pdp-page');
      if (!page) return;
      Array.prototype.slice.call(page.classList).forEach(function (cl) { if (/^pds-/.test(cl)) page.classList.remove(cl); });
      Object.keys(BLOCK).forEach(function (k) { page.style.removeProperty('--pds-o-' + k); });
      if (off) return;
      page.classList.add('pds-on');
      var a = afterReviews(page);
      if (a && /^[a-z]+$/.test(a)) page.classList.add('pds-ar-' + a);
      ORDER.forEach(function (k, i) { if (/^[a-z]+$/.test(k)) page.style.setProperty('--pds-o-' + k, String(i + 1)); });
    });
  }

  function markDirty() {
    DIRTY = true;
    var d = document.getElementById('ppDirty');
    if (d) { d.style.visibility = 'visible'; d.classList.remove('ok'); d.textContent = 'Unsaved changes'; }
    paintFrames();
  }

  /* ── drawing ───────────────────────────────────────────────────────────── */

  function row(k, i) {
    var r = ROWS[k], n = ORDER.length;
    return '<li class="pds-row' + (r.desktop ? '' : ' off') + '" data-pds-row="' + escAttr(k) + '">'
      + '<button type="button" class="pds-handle" draggable="true" data-pds-handle="' + escAttr(k) + '" aria-label="' + escAttr('Move ' + r.label + ' — drag, or use the arrow keys') + '" title="Drag to move">⠿</button>'
      + '<span class="pds-num">' + (i + 1) + '</span>'
      + '<div class="pds-main"><b>' + escHtml(r.label) + '</b><span>' + escHtml(r.description) + '</span></div>'
      + '<span class="pds-arrows"><button type="button" class="pds-arrow" data-pds-up="' + escAttr(k) + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="' + escAttr('Move ' + r.label + ' up') + '">↑</button>'
      + '<button type="button" class="pds-arrow" data-pds-down="' + escAttr(k) + '"' + (i === n - 1 ? ' disabled' : '') + ' aria-label="' + escAttr('Move ' + r.label + ' down') + '">↓</button></span>'
      + '</li>';
  }

  function body() {
    return '<div class="mmcols"><div class="card mmcard">'
      + '<div class="mmhd"><b>Desktop sections</b><span>The laptop product page under the photo and the buy column, top to bottom. Drag a block by its handle (or use ↑ ↓) to change where it sits. Laptops only — the phone order is on the Mobile sections tab.</span></div>'
      + '<p class="pds-note">The photo and the buy column are one two-column block and stay at the top. Switching a block off for laptops is on the <b>Sections</b> tab; here it keeps its place in the list.</p>'
      + '<div class="pds-fixed"><b>Photo + buy column</b><span>The gallery beside the title, price and Add to cart.</span><i>Always first</i></div>'
      + '<ol class="pds-list" id="pdsList">' + ORDER.map(row).join('') + '</ol></div></div>';
  }

  function repaintList(focusSel) {
    var list = document.getElementById('pdsList');
    if (!list) return;
    list.innerHTML = ORDER.map(row).join('');
    if (focusSel) { var el = list.querySelector(focusSel); if (el) el.focus(); }
  }

  /* ── the wrap ──────────────────────────────────────────────────────────── */

  var original = window.paintProductPage;

  var wrapped = function (rebuild) {
    var ours = (typeof PPTAB !== 'undefined' && PPTAB === TAB);
    original.apply(this, arguments);
    if (!data()) return;
    if (ORDER === null || rebuild) { if (!DIRTY) load(); }
    if (!ORDER) return;

    var strip = document.querySelector('#ppStrip .ectabs');
    if (strip && !strip.querySelector('[data-pdstab]')) {
      var btn = '<button class="ectab' + (ours ? ' on' : '') + '" data-pdstab="' + TAB + '">Desktop sections<span class="ecn">' + ORDER.length + '</span></button>';
      var mob = strip.querySelector('[data-pmstab]');
      if (mob) mob.insertAdjacentHTML('afterend', btn); else strip.insertAdjacentHTML('beforeend', btn);
      strip.querySelector('[data-pdstab]').onclick = function () { PPTAB = TAB; window.paintProductPage(); };
    }

    if (ours) {
      var col = document.getElementById('ppCol');
      if (col) col.innerHTML = body();
      var lede = document.getElementById('ppLede');
      if (lede) lede.textContent = 'The laptop product page’s full-width blocks — drag to put them in any order. The laptop preview follows as you go; nothing changes on the shop until you press Save changes.';
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

  /* ── moving ────────────────────────────────────────────────────────────── */

  function moveTo(key, to) {
    var from = ORDER.indexOf(key);
    if (from < 0) return;
    to = Math.max(0, Math.min(ORDER.length - 1, to));
    if (to === from) return;
    ORDER.splice(from, 1);
    ORDER.splice(to, 0, key);
    markDirty();
  }

  function dropOn(key, target) {
    if (!key || !target || key === target) return;
    if (ORDER.indexOf(key) < 0 || ORDER.indexOf(target) < 0) return;
    moveTo(key, ORDER.indexOf(target));
    repaintList('[data-pds-handle="' + key + '"]');
  }

  function clearMarks() {
    document.querySelectorAll('.pds-row.over,.pds-row.dragging').forEach(function (r) { r.classList.remove('over', 'after', 'dragging'); });
  }

  function markOver(rowEl) {
    document.querySelectorAll('.pds-row.over').forEach(function (r) { if (r !== rowEl) r.classList.remove('over', 'after'); });
    if (!rowEl || !DRAG) return;
    var k = rowEl.getAttribute('data-pds-row');
    if (k === DRAG) return;
    rowEl.classList.add('over');
    rowEl.classList.toggle('after', ORDER.indexOf(k) > ORDER.indexOf(DRAG));
  }

  // mouse: HTML5 drag and drop, started from the handle
  document.addEventListener('dragstart', function (e) {
    var h = e.target.closest && e.target.closest('[data-pds-handle]');
    if (!h || !ORDER) return;
    DRAG = h.getAttribute('data-pds-handle');
    var r = h.closest('.pds-row'); if (r) r.classList.add('dragging');
    try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', DRAG); if (r) e.dataTransfer.setDragImage(r, 16, 16); } catch (err) {}
  });
  document.addEventListener('dragover', function (e) {
    if (!DRAG) return;
    var r = e.target.closest && e.target.closest('.pds-row');
    if (!r) return;
    e.preventDefault();
    markOver(r);
  });
  document.addEventListener('drop', function (e) {
    if (!DRAG) return;
    var r = e.target.closest && e.target.closest('.pds-row');
    e.preventDefault();
    var k = DRAG; DRAG = null; clearMarks();
    if (r) dropOn(k, r.getAttribute('data-pds-row'));
  });
  document.addEventListener('dragend', function () { if (DRAG) { DRAG = null; clearMarks(); } });

  // finger / pen: pointer events on the handle, hit-testing the point
  var PTR = null;
  document.addEventListener('pointerdown', function (e) {
    var h = e.target.closest && e.target.closest('[data-pds-handle]');
    if (!h || e.pointerType === 'mouse' || !ORDER) return;
    e.preventDefault();
    DRAG = h.getAttribute('data-pds-handle');
    PTR = e.pointerId;
    try { h.setPointerCapture(e.pointerId); } catch (err) {}
    var r = h.closest('.pds-row'); if (r) r.classList.add('dragging');
  });
  document.addEventListener('pointermove', function (e) {
    if (PTR === null || e.pointerId !== PTR || !DRAG) return;
    var hit = document.elementFromPoint(e.clientX, e.clientY);
    markOver(hit && hit.closest ? hit.closest('.pds-row') : null);
  });
  function endPointer(e, commit) {
    if (PTR === null || e.pointerId !== PTR) return;
    var k = DRAG, target = null;
    if (commit) { var hit = document.elementFromPoint(e.clientX, e.clientY); var r = hit && hit.closest ? hit.closest('.pds-row') : null; target = r ? r.getAttribute('data-pds-row') : null; }
    PTR = null; DRAG = null; clearMarks();
    if (target) dropOn(k, target);
  }
  document.addEventListener('pointerup', function (e) { endPointer(e, true); });
  document.addEventListener('pointercancel', function (e) { endPointer(e, false); });

  // keyboard: arrow keys on the handle
  document.addEventListener('keydown', function (e) {
    var h = e.target.closest && e.target.closest('[data-pds-handle]');
    if (!h || !ORDER || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    var k = h.getAttribute('data-pds-handle');
    moveTo(k, ORDER.indexOf(k) + (e.key === 'ArrowUp' ? -1 : 1));
    repaintList('[data-pds-handle="' + k + '"]');
  });

  /* ── buttons and Save ──────────────────────────────────────────────────── */

  window.addEventListener('click', async function (e) {
    var t = e.target;
    if (!t || typeof PP === 'undefined' || !PP || !ORDER) return;

    var up = t.closest && t.closest('[data-pds-up]'), dn = t.closest && t.closest('[data-pds-down]');
    if (up || dn) {
      var key = (up || dn).getAttribute(up ? 'data-pds-up' : 'data-pds-down');
      moveTo(key, ORDER.indexOf(key) + (up ? -1 : 1));
      repaintList('[data-pds-' + (up ? 'up' : 'down') + '="' + key + '"]:not([disabled])');
      return;
    }
    if (t.id === 'pdsReset') {
      /* ASKED FIRST: the console's reset guard (document, capture) stops the
         first click, asks, and on "Yes" clicks again carrying
         data-kbb-sure-pass — the same contract Mobile sections' Reset keeps.
         A buffer change, not a save. */
      if (t.getAttribute('data-kbb-sure-pass') !== '1') return;
      ORDER = defaults();
      markDirty(); window.paintProductPage(); return;
    }
    if (t.id === 'pdsDiscard' || t.id === 'pmsDiscard' || t.id === 'ppDiscard' || t.id === 'ptsDiscard') {
      DIRTY = false; ORDER = null;
      if (t.id === 'pdsDiscard') renderProductPage();
      return;
    }

    if (t.id !== 'ppSave' || !DIRTY) return;

    var theirs = typeof PPDIRTY !== 'undefined' && PPDIRTY && Object.keys(PPDIRTY).some(function (k2) { return !!PPDIRTY[k2]; });
    if (!theirs) { e.stopPropagation(); e.preventDefault(); }

    var msg = document.getElementById('ppDirty');

    try {
      var r = await fetch(ppBase(), { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': uToken(), Accept: 'application/json' },
        body: JSON.stringify({ dsections: { order: ORDER.slice() } }) });
      var j = null;
      try { j = await r.json(); } catch (e2) { j = null; }
      if (r.status === 404 && (!j || (!j.message && !j.error))) {
        if (msg) { msg.style.visibility = 'visible'; msg.textContent = 'The Product page endpoint is not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'; }
        return;
      }
      if (j && j.ok) {
        if (j.dsections) PP.dsections = j.dsections;
        DIRTY = false; ORDER = null; load();
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
