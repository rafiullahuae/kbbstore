
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

  // Lane RH: the row's own device switches share their visible words with the
  // section's ("Show on phones"), so a screen reader is told which is which.
  var ARIA = { row_phone: 'Show the total row on phones', row_laptop: 'Show the total row on laptops' };

  // One argument only: `[...].map(optRow)` passes the index second, which
  // must never read as `locked`.
  function optRow(k) { return optRowL(k, false); }

  // `locked`: drawn, but disabled — see cardTiers().
  function optRowL(k, locked) {
    var f = field(k); if (!f) return '';
    var v = W.options[k];
    if (f.type === 'bool') {
      var t = tog('data-btp-opt', k, !!v, ARIA[k] || f.label);
      if (locked) t = t.replace(' tabindex="0"', ' tabindex="-1" aria-disabled="true"');
      return '<div class="mmrow">' + lbl(f.label, f.help) + t + '</div>';
    }
    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="mmrow">' + lbl(f.label, f.help) + '<span class="mmrange"><input type="range" min="' + escAttr(String(o.min)) + '" max="' + escAttr(String(o.max)) + '" step="' + escAttr(String(o.step || 1)) + '" value="' + escAttr(String(v)) + '" data-btp-opt="' + escAttr(k) + '"' + (locked ? ' disabled aria-disabled="true"' : '') + '><i id="btpv-' + escAttr(k) + '">' + escHtml(String(v) + (o.unit || '')) + '</i></span></div>';
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

  /* ── Lane RE: the live preview of the tiers ──────────────────────────────
     An example basket of AED 100 products, priced by the same arithmetic the
     server uses (App\Services\BuyTogetherPricing::unitOff): each unit reduced
     by the tier, rounded half up to the fil, then DOWN to the whole dirham.
     Redrawn on every slider move, before anything is saved. */
  var EX_UNIT = 10000; // AED 100 in fils
  function tierFor(n) { var v = Math.round(Number(W.options['tier_' + Math.min(5, n)])); return isFinite(v) ? Math.max(0, Math.min(50, v)) : 0; }
  function unitOff(unit, pct) { if (pct <= 0 || unit <= 0) return 0; var exact = Math.floor((unit * (100 - pct) + 50) / 100); var reduced = Math.floor(exact / 100) * 100; return Math.max(0, Math.min(unit, unit - reduced)); }
  function aed(minor) { return 'AED ' + String(Math.round(minor / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function previewRows() {
    var max = Math.max(3, Math.min(6, Number(W.options.count) || 4));
    var out = '';
    for (var n = 3; n <= max; n++) {
      var pct = tierFor(n), gross = EX_UNIT * n, off = unitOff(EX_UNIT, pct) * n;
      out += '<div class="btp-pv"><span class="n">' + n + ' ticked · ' + pct + '%</span>'
        + (off > 0
          ? '<span>Total: <s>' + escHtml(aed(gross)) + '</s> <span class="tot">' + escHtml(aed(gross - off)) + '</span></span><span class="save">✓ You’re saving ' + escHtml(aed(off)) + '</span>'
          : '<span>Total: <span class="tot">' + escHtml(aed(gross)) + '</span></span><span class="off">no bundle discount</span>')
        + '</div>';
    }
    return out;
  }
  function paintPreview() { var el = document.getElementById('btpPreview'); if (el && W) el.innerHTML = previewRows(); }

  /* ── Lane RH: the master switch at the top of the discount card ──────────
     "lock that discount section is the section is hided with toggle button."
     Off (the default): the row switches, the tiers, the coupons switch and the
     preview are drawn LOCKED — disabled, greyed, aria-disabled — under one
     line saying how to unlock them. Their values are kept; the server keeps
     them too (BuyTogetherSettings::LOCKED) and prices nothing while off. */
  function discountOn() { return !!W.options.discount_on; }
  function cardTiers() {
    var on = discountOn();
    var lockA = on ? '' : ' btp-locked" aria-disabled="true';
    return '<div class="card mmcard" id="btpTiers"><div class="mmhd"><b>Discount for buying together</b><span>Taken off every product in the bundle when the shopper ticks that many and presses the button — on the product page, in the cart, at the checkout and on the order. All three are 0 (off) until you set them. Remove one bundled product from the cart and the rest go back to their own prices.</span></div>'
      + '<div class="mmbody">' + optRow('discount_on') + '</div>'
      + (on ? '' : '<p class="btp-lock-note" role="note">Turn on ‘Show the total and buy-together discount’ to use these.</p>')
      + '<div class="mmbody' + lockA + '" data-btp-lock>' + ['row_phone', 'row_laptop', 'tier_3', 'tier_4', 'tier_5', 'coupons'].map(function (k) { return optRowL(k, !on); }).join('') + '</div>'
      + '<div class="btp-prev' + lockA + '"><h4>Preview — an example basket of AED 100 products</h4><div id="btpPreview">' + previewRows() + '</div></div></div>';
  }

  function body() {
    var devices = '<div class="mmrow btp-dev">' + lbl('On phones', 'The “Buy these together” row of the Mobile sections tab — which also sets where it sits on the phone page.') + tog('data-btp-dev', 'phone', W.phone, 'Show on phones') + '</div>'
      + '<div class="mmrow btp-dev">' + lbl('On laptops', 'The “Buy these together” row of the Sections tab, Desktop.') + tog('data-btp-dev', 'laptop', W.laptop, 'Show on laptops') + '</div>';
    var card1 = '<div class="card mmcard"><div class="mmhd"><b>Buy these together</b><span>The product on the page first, then one match from each category that goes with it — each with a green tick the shopper can clear — and one pink “Buy 4 items together” button that adds every ticked product. The cards are the cart page’s “Recommended for you” cards, the same size.</span></div>'
      + '<div class="mmbody">' + optRow('on') + devices + ['count', 'rule', 'hide_oos', 'same_brand', 'show_total', 'title', 'title_ar'].map(optRow).join('') + '</div></div>';
    var cardT = cardTiers();
    var card2 = '<div class="card mmcard"><div class="mmhd"><b>Category pairs</b><span>Which categories go with which. The default is worked out from your category names — Sunscreens go with Moisturizers, Toners, Cleansing oils and Face masks; Cleansers with Toners, Serums, Moisturizers and Sunscreens; and so on. Customise any category to choose up to ' + maxPairs() + ' matches in your own order. A product uses its most specific category.</span></div>'
      + '<p class="btp-note">One product is taken from each matching category, in this order, by the rule above. When a category is empty, or a product’s category has no matches, the shop’s best sellers fill the gap. Products that need an option chosen (sizes, shades) are never suggested — only the product on the page can be one.</p>'
      + '<div class="btp-find"><input type="search" placeholder="Find a category…" value="' + escAttr(FILTER) + '" data-btp-find aria-label="Find a category"><label><input type="checkbox" data-btp-only' + (ONLY_PAIRED ? ' checked' : '') + '> Only categories with matches</label></div>'
      + '<ul class="btp-rows" id="btpList">' + visibleCats().map(pairRow).join('') + '</ul></div>';
    return '<div class="mmcols">' + card1 + cardT + card2 + '</div>';
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

  // Any other range, to its OWN bounds — the tiers are 0–50, not the count's 3–6.
  function clampRange(f, v) {
    var o = (f && f.options) || {};
    var n = Math.round(Number(v)); if (!isFinite(n)) n = Number(f ? f.default : 0);
    return Math.min(+o.max, Math.max(+o.min, n));
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !W || !t.hasAttribute) return;
    if (t.hasAttribute('data-btp-find')) { FILTER = String(t.value || ''); paintPairs(); return; }
    if (t.hasAttribute('data-btp-opt') && t.tagName === 'INPUT') {
      var k = t.getAttribute('data-btp-opt'); var f = field(k); if (!f) return;
      W.options[k] = f.type === 'range' ? (k === 'count' ? clampCount(t.value) : clampRange(f, t.value)) : String(t.value);
      var out = document.getElementById('btpv-' + k); if (out) out.textContent = String(W.options[k]) + ((f.options && f.options.unit) || '');
      markDirty();
      if (k === 'count' || /^tier_/.test(k)) paintPreview();
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
      if (ot.getAttribute('aria-disabled') === 'true') return; // locked (Lane RH)
      W.options[k] = !W.options[k];
      ot.classList.toggle('on', !!W.options[k]); ot.setAttribute('aria-checked', String(!!W.options[k]));
      markDirty();
      // The master switch redraws its card locked or unlocked, focus kept.
      if (k === 'discount_on') {
        var card = document.getElementById('btpTiers');
        if (card) { card.outerHTML = cardTiers(); var back = document.querySelector('#btpTiers .ectog[data-btp-opt="discount_on"]'); if (back) back.focus(); }
      }
      return;
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
      if (r.status === 404 && (!j || (!j.message && !j.error))) {
        if (msg) { msg.style.visibility = 'visible'; msg.textContent = 'The Product page endpoint is not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'; }
        return;
      }
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
