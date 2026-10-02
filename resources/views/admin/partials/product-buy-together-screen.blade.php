{{--
    Appearance → Product page → Buy these together.                   (Lane RB)

      "I want one new section called Buy these together [...] one from each
       random, or best seller or best visits. give option to choose the
       criteria at the backend."

    ONE MORE TAB ON THE SCREEN THAT OWNS THE PRODUCT PAGE, added exactly the
    way Lane QA added Mobile sections: this file wraps paintProductPage() (a
    top-level function of the console's first <script>, so a property of
    `window`), lets the original and every earlier wrapper paint their tabs,
    then appends one button to the same strip and, when it is selected, fills
    the same column. Its data is `PP.together`, from the same GET; it saves
    under its own `together` key of the same POST.

    ── WHAT THE TAB HOLDS ─────────────────────────────────────────────────────

      Card 1  Show the section · On phones · On laptops · How many products ·
              How the other products are chosen · Hide sold-out · Prefer the
              same brand · Show the total · Heading (English, Arabic).
              "On phones" IS the Mobile sections row and "On laptops" IS the
              Sections row — drawn here as well, written there.
      Card 2  Category pairs: every category, what it pairs with by default
              (worked out from his own category names), and "Customise" to
              choose up to five, in order — or "Use the default" to go back.

    ── RULE 5 ─────────────────────────────────────────────────────────────────

    Every string reaches the DOM through escHtml/escAttr. A category in a pair
    list must be one the server sent; an option is one of a select's own keys;
    the count is clamped to the server's bounds. The server validates all of it
    again (ProductPageApiController::save(), BuyTogetherPairs::
    validateOverrides()).
--}}
@verbatim
<style>
.btp-rows{list-style:none;margin:0;padding:0}
.btp-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 12px;align-items:center;padding:10px 14px;border-bottom:1px solid #eef1f5}
.btp-row:last-child{border-bottom:0}
.btp-name{font-size:13px;min-inline-size:0}
.btp-name b{font-weight:600}
.btp-name small{display:inline-block;margin-inline-start:6px;padding:1px 7px;border-radius:99px;background:#f2f5f8;color:#5b6576;font-size:11px;font-weight:600}
.btp-match{grid-column:1 / -1;display:flex;flex-wrap:wrap;gap:5px;align-items:center;font-size:12px;color:#5b6576}
.btp-chip{padding:2px 9px;border-radius:99px;background:#eef8f2;color:#1f6b47;font-weight:600;font-size:11.5px}
.btp-row.custom .btp-chip{background:#fdf0f4;color:#a32f53}
.btp-none{color:#8b95a5;font-style:italic}
.btp-slots{grid-column:1 / -1;display:flex;flex-wrap:wrap;gap:6px}
.btp-slots select{flex:1 1 150px;min-inline-size:0;padding:6px 8px;border:1px solid #dfe4ec;border-radius:7px;font:inherit;font-size:12.5px}
.btp-find{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;padding:10px 14px;border-bottom:1px solid #eef1f5}
.btp-find input[type=search]{flex:1 1 180px;min-inline-size:0;padding:7px 10px;border:1px solid #dfe4ec;border-radius:8px;font:inherit;font-size:13px}
.btp-find label{font-size:12px;color:#5b6576;display:flex;gap:6px;align-items:center}
.btp-note{font-size:12px;color:#5b6576;margin:0;padding:10px 14px;background:#f2fbf6;border-bottom:1px solid #eef1f5}
.btp-dev small{display:block;font-size:11.5px;color:#7b8697;margin-top:2px}
</style>
<script>
(function () {
  'use strict';

  if (typeof window.paintProductPage !== 'function' || window.paintProductPage.__btp) return;

  var TAB = 'together';
  var DIRTY = false;
  var W = null;          // working copy: { options: {key: value}, phone, laptop, custom: {id: list|null}, touched: {id: true} }
  var FILTER = '';
  var ONLY_PAIRED = false;

  function data() { return (typeof PP !== 'undefined' && PP && PP.together) ? PP.together : null; }
  function fields() { var d = data(); var out = []; if (d && Array.isArray(d.options)) d.options.forEach(function (t) { t.fields.forEach(function (f) { out.push(f); }); }); return out; }
  function field(k) { return fields().find(function (f) { return f.key === k; }) || null; }
  function cats() { var d = data(); return (d && Array.isArray(d.pairs)) ? d.pairs : []; }
  function catById(id) { return cats().find(function (c) { return c.id === id; }) || null; }
  function maxPairs() { var d = data(); return (d && +d.max_pairs) || 5; }

  function load() {
    var d = data();
    if (!d) { W = null; return; }
    W = { options: {}, phone: !!d.phone, laptop: !!d.laptop, custom: {}, touched: {} };
    fields().forEach(function (f) { W.options[f.key] = f.value; });
    cats().forEach(function (c) { W.custom[c.id] = Array.isArray(c.custom) ? c.custom.filter(function (x) { return !!catById(x); }) : null; });
  }

  function markDirty() {
    DIRTY = true;
    var d = document.getElementById('ppDirty');
    if (d) { d.style.visibility = 'visible'; d.classList.remove('ok'); d.textContent = 'Unsaved changes'; }
  }

  function tog(attr, key, on, label) {
    return '<span class="ectog' + (on ? ' on' : '') + '" ' + attr + '="' + escAttr(key) + '" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" tabindex="0" aria-label="' + escAttr(label) + '"></span>';
  }

  function lbl(title, help) {
    return '<div class="mmlbl"><b>' + escHtml(title) + '</b>' + (help ? '<span>' + escHtml(help) + '</span>' : '') + '</div>';
  }

  function optRow(k) {
    var f = field(k); if (!f) return '';
    var v = W.options[k];
    if (f.type === 'bool') return '<div class="mmrow">' + lbl(f.label, f.help) + tog('data-btp-opt', k, !!v, f.label) + '</div>';
    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="mmrow">' + lbl(f.label, f.help) + '<span class="mmrange"><input type="range" min="' + escAttr(String(o.min)) + '" max="' + escAttr(String(o.max)) + '" step="' + escAttr(String(o.step || 1)) + '" value="' + escAttr(String(v)) + '" data-btp-opt="' + escAttr(k) + '"><i id="btpv-' + escAttr(k) + '">' + escHtml(String(v)) + '</i></span></div>';
    }
    if (f.type === 'select') {
      var cur = Object.prototype.hasOwnProperty.call(f.options || {}, String(v)) ? String(v) : String(f.default);
      return '<div class="mmrow">' + lbl(f.label, f.help) + '<select data-btp-opt="' + escAttr(k) + '">' + Object.keys(f.options || {}).map(function (ok) {
        return '<option value="' + escAttr(ok) + '"' + (ok === cur ? ' selected' : '') + '>' + escHtml(f.options[ok]) + '</option>';
      }).join('') + '</select></div>';
    }
    var rtl = /_ar$/.test(k) ? ' dir="rtl" lang="ar"' : '';
    return '<div class="mmrow">' + lbl(f.label, f.help) + '<input type="text" maxlength="60" value="' + escAttr(String(v == null ? '' : v)) + '" data-btp-opt="' + escAttr(k) + '"' + rtl + '></div>';
  }

  function chips(ids) {
    if (!ids.length) return '<span class="btp-none">nothing — the shop’s best sellers fill the section</span>';
    return ids.map(function (id, i) { var c = catById(id); return c ? '<span class="btp-chip">' + (i + 1) + '. ' + escHtml(c.name) + '</span>' : ''; }).join('');
  }

  function options(selected) {
    return '<option value="">—</option>' + cats().map(function (c) {
      return '<option value="' + c.id + '"' + (c.id === selected ? ' selected' : '') + '>' + escHtml(new Array(c.level + 1).join('· ') + c.name) + '</option>';
    }).join('');
  }

  function pairRow(c) {
    var custom = W.custom[c.id];
    var isCustom = Array.isArray(custom);
    var ids = isCustom ? custom : (Array.isArray(c.default) ? c.default : []);
    var slots = '';
    if (isCustom) {
      for (var i = 0; i < maxPairs(); i++) {
        slots += '<select data-btp-slot="' + c.id + '" data-i="' + i + '" aria-label="' + escAttr('Match ' + (i + 1) + ' for ' + c.name) + '">' + options(custom[i] || null) + '</select>';
      }
    }
    return '<li class="btp-row' + (isCustom ? ' custom' : '') + '" data-btp-cat="' + c.id + '">'
      + '<div class="btp-name" style="padding-inline-start:' + (Math.min(6, c.level) * 14) + 'px"><b>' + escHtml(c.name) + '</b>' + (c.kind_label ? '<small>' + escHtml(c.kind_label) + '</small>' : '') + '</div>'
      + '<button type="button" class="btn small" data-btp-mode="' + c.id + '">' + (isCustom ? 'Use the default' : 'Customise') + '</button>'
      + '<div class="btp-match">' + (isCustom ? 'Your matches:' : 'Matches (default):') + ' ' + chips(ids) + '</div>'
      + (isCustom ? '<div class="btp-slots">' + slots + '</div>' : '')
      + '</li>';
  }

  function visibleCats() {
    var q = FILTER.trim().toLowerCase();
    return cats().filter(function (c) {
      if (q && c.name.toLowerCase().indexOf(q) < 0) return false;
      if (ONLY_PAIRED) { var ids = Array.isArray(W.custom[c.id]) ? W.custom[c.id] : (c.default || []); if (!ids.length && !Array.isArray(W.custom[c.id])) return false; }
      return true;
    });
  }

  function paintPairs() {
    var list = document.getElementById('btpList');
    if (list) list.innerHTML = visibleCats().map(pairRow).join('') || '<li class="btp-row"><span class="btp-none">No category matches.</span></li>';
  }

  function body() {
    var devices = '<div class="mmrow btp-dev">' + lbl('On phones', 'The “Buy these together” row of the Mobile sections tab — which also sets where it sits on the phone page.') + tog('data-btp-dev', 'phone', W.phone, 'Show on phones') + '</div>'
      + '<div class="mmrow btp-dev">' + lbl('On laptops', 'The “Buy these together” row of the Sections tab, Desktop.') + tog('data-btp-dev', 'laptop', W.laptop, 'Show on laptops') + '</div>';
    var card1 = '<div class="card mmcard"><div class="mmhd"><b>Buy these together</b><span>The product on the page first, then one match from each category that goes with it — each with a green tick the shopper can clear — and one pink “Buy 4 items together” button that adds every ticked product. The cards are the cart page’s “Recommended for you” cards, the same size.</span></div>'
      + '<div class="mmbody">' + optRow('on') + devices + ['count', 'rule', 'hide_oos', 'same_brand', 'show_total', 'title', 'title_ar'].map(optRow).join('') + '</div></div>';
    var card2 = '<div class="card mmcard"><div class="mmhd"><b>Category pairs</b><span>Which categories go with which. The default is worked out from your category names — Sunscreens go with Moisturizers, Toners, Cleansing oils and Face masks; Cleansers with Toners, Serums, Moisturizers and Sunscreens; and so on. Customise any category to choose up to ' + maxPairs() + ' matches in your own order. A product uses its most specific category.</span></div>'
      + '<p class="btp-note">One product is taken from each matching category, in this order, by the rule above. When a category is empty, or a product’s category has no matches, the shop’s best sellers fill the gap. Products that need an option chosen (sizes, shades) are never suggested — only the product on the page can be one.</p>'
      + '<div class="btp-find"><input type="search" placeholder="Find a category…" value="' + escAttr(FILTER) + '" data-btp-find aria-label="Find a category"><label><input type="checkbox" data-btp-only' + (ONLY_PAIRED ? ' checked' : '') + '> Only categories with matches</label></div>'
      + '<ul class="btp-rows" id="btpList">' + visibleCats().map(pairRow).join('') + '</ul></div>';
    return '<div class="mmcols">' + card1 + card2 + '</div>';
  }

  var original = window.paintProductPage;

  var wrapped = function (rebuild) {
    var ours = (typeof PPTAB !== 'undefined' && PPTAB === TAB);
    original.apply(this, arguments);
    if (!data()) return;
    if (W === null || rebuild) { if (!DIRTY) load(); }

    var strip = document.querySelector('#ppStrip .ectabs');
    if (strip && !strip.querySelector('[data-btptab]')) {
      strip.insertAdjacentHTML('beforeend', '<button class="ectab' + (ours ? ' on' : '') + '" data-btptab="' + TAB + '">Buy these together<span class="ecn">' + fields().length + '</span></button>');
      strip.querySelector('[data-btptab]').onclick = function () { PPTAB = TAB; window.paintProductPage(); };
    }

    if (ours && W) {
      var col = document.getElementById('ppCol');
      if (col) col.innerHTML = body();
      var lede = document.getElementById('ppLede');
      if (lede) lede.textContent = 'The bundle box under the buy column: what it shows, how the matches are chosen, and which categories go together. The preview shows saved changes after you press Save changes.';
      var acts = document.getElementById('ppActs');
      if (acts) acts.innerHTML = '<button class="btn" id="btpDiscard">Discard</button>';
      if (DIRTY) { var d = document.getElementById('ppDirty'); if (d) { d.style.visibility = 'visible'; d.textContent = 'Unsaved changes'; } }
    }
  };
  wrapped.__btp = true;
  window.paintProductPage = wrapped;

  if (document.getElementById('ppShell') && typeof PP !== 'undefined' && PP) window.paintProductPage();

  /* ── edits ─────────────────────────────────────────────────────────────── */

  function clampCount(v) {
    var f = field('count'), o = (f && f.options) || { min: 3, max: 6 };
    var n = Math.round(Number(v)); if (!isFinite(n)) n = Number(f ? f.default : 4);
    return Math.min(+o.max, Math.max(+o.min, n));
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !W || !t.hasAttribute) return;
    if (t.hasAttribute('data-btp-find')) { FILTER = String(t.value || ''); paintPairs(); return; }
    if (t.hasAttribute('data-btp-opt') && t.tagName === 'INPUT') {
      var k = t.getAttribute('data-btp-opt'); var f = field(k); if (!f) return;
      W.options[k] = f.type === 'range' ? clampCount(t.value) : String(t.value);
      var out = document.getElementById('btpv-' + k); if (out) out.textContent = String(W.options[k]);
      markDirty();
    }
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !W || !t.hasAttribute) return;
    if (t.hasAttribute('data-btp-only')) { ONLY_PAIRED = !!t.checked; paintPairs(); return; }
    if (t.tagName === 'SELECT' && t.hasAttribute('data-btp-opt')) {
      var k = t.getAttribute('data-btp-opt'); var f = field(k); if (!f) return;
      W.options[k] = Object.prototype.hasOwnProperty.call(f.options || {}, t.value) ? t.value : f.default;
      markDirty(); return;
    }
    if (t.tagName === 'SELECT' && t.hasAttribute('data-btp-slot')) {
      var id = +t.getAttribute('data-btp-slot'), i = +t.getAttribute('data-i');
      if (!Array.isArray(W.custom[id])) return;
      var list = W.custom[id].slice();
      var picked = t.value === '' ? null : +t.value;
      if (picked !== null && !catById(picked)) return;
      // Slot i takes the pick; the list is then compacted with no repeats.
      var slots = []; for (var n = 0; n < maxPairs(); n++) slots.push(list[n] === undefined ? null : list[n]);
      slots[i] = picked;
      var clean = []; slots.forEach(function (x) { if (x !== null && clean.indexOf(x) < 0) clean.push(x); });
      W.custom[id] = clean; W.touched[id] = true;
      markDirty();
      var row = document.querySelector('[data-btp-cat="' + id + '"]'); var c = catById(id);
      if (row && c) { row.outerHTML = pairRow(c); }
    }
  });

  window.addEventListener('click', async function (e) {
    var t = e.target;
    if (!t || typeof PP === 'undefined' || !PP || !W) return;

    var ot = t.closest && t.closest('.ectog[data-btp-opt]');
    if (ot) {
      var k = ot.getAttribute('data-btp-opt'); if (!field(k)) return;
      W.options[k] = !W.options[k];
      ot.classList.toggle('on', !!W.options[k]); ot.setAttribute('aria-checked', String(!!W.options[k]));
      markDirty(); return;
    }
    var dv = t.closest && t.closest('[data-btp-dev]');
    if (dv) {
      var dk = dv.getAttribute('data-btp-dev'); if (dk !== 'phone' && dk !== 'laptop') return;
      W[dk] = !W[dk];
      dv.classList.toggle('on', W[dk]); dv.setAttribute('aria-checked', String(W[dk]));
      markDirty(); return;
    }
    var md = t.closest && t.closest('[data-btp-mode]');
    if (md) {
      var id = +md.getAttribute('data-btp-mode'); var c = catById(id); if (!c) return;
      W.custom[id] = Array.isArray(W.custom[id]) ? null : (Array.isArray(c.default) ? c.default.slice() : []);
      W.touched[id] = true;
      markDirty();
      var row = md.closest('.btp-row'); if (row) row.outerHTML = pairRow(c);
      return;
    }
    if (t.id === 'btpDiscard' || t.id === 'ppDiscard' || t.id === 'pmsDiscard' || t.id === 'ptsDiscard') {
      DIRTY = false; W = null;
      if (t.id === 'btpDiscard') renderProductPage();
      return;
    }

    if (t.id !== 'ppSave' || !DIRTY) return;

    // Another half dirty too? Let the console's own Save run beside this one.
    var theirs = typeof PPDIRTY !== 'undefined' && PPDIRTY && Object.keys(PPDIRTY).some(function (k2) { return !!PPDIRTY[k2]; });
    if (!theirs) { e.stopPropagation(); e.preventDefault(); }

    var pairs = {};
    Object.keys(W.touched).forEach(function (id) { pairs[id] = Array.isArray(W.custom[id]) ? W.custom[id].slice(0, maxPairs()) : null; });
    var payload = { together: { options: W.options, phone: !!W.phone, laptop: !!W.laptop } };
    if (Object.keys(pairs).length) payload.together.pairs = pairs;
    var msg = document.getElementById('ppDirty');

    try {
      var r = await fetch(ppBase(), { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': uToken(), Accept: 'application/json' },
        body: JSON.stringify(payload) });
      var j = null;
      try { j = await r.json(); } catch (e2) { j = null; }
      if (j && j.ok) {
        if (j.together) PP.together = j.together;
        if (Array.isArray(j.sections)) PP.sections = j.sections;
        if (j.msections) PP.msections = j.msections;
        DIRTY = false; W = null; load();
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

  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (t && t.classList && t.classList.contains('ectog') && (t.hasAttribute('data-btp-opt') || t.hasAttribute('data-btp-dev')) && (e.key === ' ' || e.key === 'Enter')) {
      e.preventDefault(); t.click();
    }
  });
})();
</script>
@endverbatim
