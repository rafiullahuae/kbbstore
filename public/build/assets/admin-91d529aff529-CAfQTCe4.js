
(function () {
  'use strict';

  if (typeof window.paintProductPage !== 'function' || window.paintProductPage.__pms) return;

  var TAB = 'msections';
  var DIRTY = false;
  var ORDER = null;   // working copy: list of keys
  var ROWS = null;    // key => {key,label,description,on,space,module}
  var DRAG = null;    // key being dragged

  function data() { return (typeof PP !== 'undefined' && PP && PP.msections) ? PP.msections : null; }
  function optFields() { var d = data(); var out = []; if (d && Array.isArray(d.options)) d.options.forEach(function (t) { t.fields.forEach(function (f) { out.push(f); }); }); return out; }
  function opt(k) { return optFields().find(function (f) { return f.key === k; }) || null; }
  function bounds() { var d = data(); return (d && d.space) || { min: 0, max: 48 }; }

  /** Load the working copy from what the server sent. */
  function load() {
    var d = data();
    if (!d) { ORDER = null; ROWS = null; return; }
    ORDER = []; ROWS = {};
    d.list.forEach(function (r) {
      if (typeof r.key !== 'string' || !/^[a-z]+$/.test(r.key)) return;
      ORDER.push(r.key);
      ROWS[r.key] = { key: r.key, label: String(r.label), description: String(r.description), on: !!r.on, space: r.space === null || r.space === undefined ? null : clampSpace(r.space), module: r.module || null };
    });
  }

  function clampSpace(v) {
    if (v === null || v === undefined || v === '') return null;
    var n = Math.round(Number(v));
    if (!isFinite(n)) return null;
    var b = bounds();
    return Math.min(b.max, Math.max(b.min, n));
  }

  function gap() {
    var f = opt('gap'); if (!f) return 18;
    var o = f.options || {}, n = Math.round(Number(f.value));
    if (!isFinite(n)) n = Number(f.default);
    return Math.min(+o.max, Math.max(+o.min, n));
  }

  function choice(k) {
    var f = opt(k); if (!f) return '';
    return Object.prototype.hasOwnProperty.call(f.options || {}, String(f.value)) ? String(f.value) : String(f.default);
  }

  /* ── what the server would print, computed here for the preview ────────── */

  /** ProductMobileSections::wrapperStyle(), as [property, value] pairs. */
  function vars() {
    var out = [['--pm-gap', gap() + 'px']], first = null, g = gap();
    ORDER.forEach(function (k, i) { out.push(['--pm-o-' + k, String(i + 1)]); if (first === null && ROWS[k].on) first = k; });
    ORDER.forEach(function (k) {
      var s = ROWS[k].space;
      out.push(['--pm-dm-' + k, (s !== null && k !== first && s !== g) ? (s - g) + 'px' : '']);
    });
    return out;
  }

  /** ProductMobileSections::wrapperClass(), as a list. */
  function classes() {
    var out = [];
    ORDER.forEach(function (k) { if (!ROWS[k].on) out.push('pm-off-' + k); });
    out.push('pm-rate-m-' + choice('rate_m'));
    out.push('pd-rate-d-' + choice('rate_d'));
    var dh = opt('details_head'); if (dh && dh.value) out.push('pm-dhead');
    var rb = opt('rate_bar'); if (rb && rb.value) out.push('pm-rbar');
    var rbd = opt('rate_bar_d'); if (rbd && rbd.value) out.push('pd-rbar');
    return out;
  }

  function paintFrames() {
    if (!ORDER) return;
    var v = vars(), c = classes();
    document.querySelectorAll('[data-ppframe]').forEach(function (fr) {
      var doc = null;
      try { doc = fr.contentDocument; } catch (e) { doc = null; }
      var page = doc && doc.querySelector('.pdp-page');
      if (!page) return;
      v.forEach(function (p) { if (!/^--pm-[a-z0-9-]+$/.test(p[0])) return; if (p[1] === '') page.style.removeProperty(p[0]); else page.style.setProperty(p[0], p[1]); });
      Array.prototype.slice.call(page.classList).forEach(function (cl) { if (/^(pm-off-|pm-rate-m-|pd-rate-d-|pm-dhead$|pm-rbar$|pd-rbar$)/.test(cl)) page.classList.remove(cl); });
      c.forEach(function (cl) { if (/^[a-z0-9-]+$/.test(cl)) page.classList.add(cl); });
    });
  }

  function markDirty() {
    DIRTY = true;
    var d = document.getElementById('ppDirty');
    if (d) { d.style.visibility = 'visible'; d.classList.remove('ok'); d.textContent = 'Unsaved changes'; }
    paintFrames();
  }

  /* ── drawing ───────────────────────────────────────────────────────────── */

  function tog(attr, key, on, label) {
    return '<span class="ectog' + (on ? ' on' : '') + '" ' + attr + '="' + escAttr(key) + '" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" tabindex="0" aria-label="' + escAttr(label) + '"></span>';
  }

  function optControl(k) {
    var f = opt(k); if (!f) return '';
    var lbl = '<span>' + escHtml(f.label) + '</span>';
    if (f.type === 'bool') return '<div class="pms-opt">' + lbl + tog('data-pms-opt', k, !!f.value, f.label) + '</div>';
    if (f.type === 'select') {
      return '<div class="pms-opt">' + lbl + '<select data-pms-opt="' + escAttr(k) + '" aria-label="' + escAttr(f.label) + '">' + Object.keys(f.options || {}).map(function (ok) {
        return '<option value="' + escAttr(ok) + '"' + (String(ok) === choice(k) ? ' selected' : '') + '>' + escHtml(f.options[ok]) + '</option>';
      }).join('') + '</select></div>';
    }
    return '<div class="pms-opt">' + lbl + '<input type="text" maxlength="120" value="' + escAttr(String(f.value)) + '" data-pms-opt="' + escAttr(k) + '" aria-label="' + escAttr(f.label) + '"></div>';
  }

  /** The options that belong to one section, drawn under its row. */
  var SECTION_OPTS = { price: ['rate_m', 'rate_count', 'rate_bar', 'rate_bar_d'], paylater: ['tabby_on', 'tabby_text', 'tamara_on', 'tamara_text'], details: ['details_head'] };

  function row(k, i) {
    var r = ROWS[k], n = ORDER.length, g = gap();
    var opts = (SECTION_OPTS[k] || []).map(optControl).join('');
    return '<li class="pms-row' + (r.on ? '' : ' off') + '" data-pms-row="' + escAttr(k) + '">'
      + '<button type="button" class="pms-handle" draggable="true" data-pms-handle="' + escAttr(k) + '" aria-label="' + escAttr('Move ' + r.label + ' — drag, or use the arrow keys') + '" title="Drag to move">⠿</button>'
      + '<span class="pms-num">' + (i + 1) + '</span>'
      + '<div class="pms-main"><b>' + escHtml(r.label) + '</b><span>' + escHtml(r.description) + '</span></div>'
      + tog('data-pms-on', k, r.on, 'Show ' + r.label + ' on phones')
      + '<div class="pms-ctl"><label class="pms-space">Space above <input type="number" inputmode="numeric" min="' + bounds().min + '" max="' + bounds().max + '" step="1" placeholder="' + g + '" value="' + (r.space === null ? '' : r.space) + '" data-pms-space="' + escAttr(k) + '" aria-label="' + escAttr('Space above ' + r.label + ' in pixels; blank uses the even gap') + '"> px</label>'
      + '<span class="pms-arrows"><button type="button" class="pms-arrow" data-pms-up="' + escAttr(k) + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="' + escAttr('Move ' + r.label + ' up') + '">↑</button>'
      + '<button type="button" class="pms-arrow" data-pms-down="' + escAttr(k) + '"' + (i === n - 1 ? ' disabled' : '') + ' aria-label="' + escAttr('Move ' + r.label + ' down') + '">↓</button></span></div>'
      + (opts ? '<div class="pms-opts">' + opts + '</div>' : '')
      + '</li>';
  }

  function body() {
    var gf = opt('gap'), o = (gf && gf.options) || { min: 0, max: 48, step: 1 };
    return '<div class="mmcols"><div class="card mmcard">'
      + '<div class="mmhd"><b>Mobile sections</b><span>The product page on a phone, top to bottom. Drag a row by its handle (or use ↑ ↓), switch any section off, and set the space above any one of them. Phones only — the laptop page is not moved by anything on this tab except the last card.</span></div>'
      + '<p class="pms-note">On a phone the space <b>between</b> two sections is set here. The phone spacing sliders on the Layout and Trust · Spacing tabs still move what is <b>inside</b> a section (brand → name, the blurb → “Read more”), and every laptop number there is unchanged.</p>'
      + '<div class="pms-gap"><b>' + escHtml(gf ? gf.label : 'Space between sections') + '</b><span>' + escHtml(gf ? gf.help : '') + '</span>'
      + '<input type="range" min="' + o.min + '" max="' + o.max + '" step="' + (o.step || 1) + '" value="' + gap() + '" data-pms-gap aria-label="Space between sections on a phone, in pixels"><output id="pmsGapOut">' + gap() + 'px</output></div>'
      + '<ol class="pms-list" id="pmsList">' + ORDER.map(row).join('') + '</ol></div>'
      + '<div class="card mmcard"><div class="mmhd"><b>Laptop · price row</b><span>The laptop page keeps everything else as it is: the price row sits under the name, with the rating beside it and no review count.</span></div>'
      + '<div class="mmbody" style="padding:4px 14px 10px">' + optControl('rate_d') + '</div></div></div>';
  }

  function repaintList(focusSel) {
    var list = document.getElementById('pmsList');
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

    var strip = document.querySelector('#ppStrip .ectabs');
    if (strip && !strip.querySelector('[data-pmstab]')) {
      strip.insertAdjacentHTML('beforeend', '<button class="ectab' + (ours ? ' on' : '') + '" data-pmstab="' + TAB + '">Mobile sections<span class="ecn">' + ORDER.length + '</span></button>');
      strip.querySelector('[data-pmstab]').onclick = function () { PPTAB = TAB; window.paintProductPage(); };
    }

    /* The Sections tab: a module whose phone switch this tab owns shows a
       pointer here instead of a second toggle for the same thing. */
    if (typeof PPTAB !== 'undefined' && PPTAB === 'sections' && PP && Array.isArray(PP.sections)) {
      PP.sections.forEach(function (s, i) {
        if (!s.mobile_owner) return;
        var cell = document.querySelector('.hprow[data-i="' + i + '"] [data-k="mobile"]');
        if (!cell) return;
        var a = document.createElement('button');
        a.type = 'button'; a.className = 'btn small ghost'; a.setAttribute('data-pms-goto', '1');
        a.textContent = (s.mobile ? 'On' : 'Off') + ' · Mobile sections →';
        a.title = 'This module is a whole section on the phone page; its phone switch is on the Mobile sections tab.';
        a.style.fontSize = '11px'; a.style.whiteSpace = 'normal'; a.style.padding = '4px 6px';
        cell.replaceWith(a);
      });
    }

    if (ours) {
      var col = document.getElementById('ppCol');
      if (col) col.innerHTML = body();
      var lede = document.getElementById('ppLede');
      if (lede) lede.textContent = 'The phone product page as sections — drag to reorder, switch any of them off, and set the space between them. The phone preview follows as you go.';
      var acts = document.getElementById('ppActs');
      if (acts) acts.innerHTML = '<button class="btn" id="pmsReset">Reset to the default order</button> <button class="btn" id="pmsDiscard">Discard</button>';
      if (DIRTY) { var d = document.getElementById('ppDirty'); if (d) { d.style.visibility = 'visible'; d.textContent = 'Unsaved changes'; } }
    }
    paintFrames();
  };
  wrapped.__pms = true;
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

  /** Drop `key` before or after `target`, by their positions in the list. */
  function dropOn(key, target) {
    if (!key || !target || key === target) return;
    var from = ORDER.indexOf(key), at = ORDER.indexOf(target);
    if (from < 0 || at < 0) return;
    moveTo(key, at);
    repaintList('[data-pms-handle="' + key + '"]');
  }

  function clearMarks() {
    document.querySelectorAll('.pms-row.over,.pms-row.dragging').forEach(function (r) { r.classList.remove('over', 'after', 'dragging'); });
  }

  function markOver(rowEl) {
    document.querySelectorAll('.pms-row.over').forEach(function (r) { if (r !== rowEl) r.classList.remove('over', 'after'); });
    if (!rowEl || !DRAG) return;
    var k = rowEl.getAttribute('data-pms-row');
    if (k === DRAG) return;
    rowEl.classList.add('over');
    rowEl.classList.toggle('after', ORDER.indexOf(k) > ORDER.indexOf(DRAG));
  }

  // mouse: HTML5 drag and drop, started from the handle
  document.addEventListener('dragstart', function (e) {
    var h = e.target.closest && e.target.closest('[data-pms-handle]');
    if (!h) return;
    DRAG = h.getAttribute('data-pms-handle');
    var r = h.closest('.pms-row'); if (r) r.classList.add('dragging');
    try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', DRAG); if (r) e.dataTransfer.setDragImage(r, 16, 16); } catch (err) {}
  });
  document.addEventListener('dragover', function (e) {
    if (!DRAG) return;
    var r = e.target.closest && e.target.closest('.pms-row');
    if (!r) return;
    e.preventDefault();
    markOver(r);
  });
  document.addEventListener('drop', function (e) {
    if (!DRAG) return;
    var r = e.target.closest && e.target.closest('.pms-row');
    e.preventDefault();
    var k = DRAG; DRAG = null; clearMarks();
    if (r) dropOn(k, r.getAttribute('data-pms-row'));
  });
  document.addEventListener('dragend', function () { DRAG = null; clearMarks(); });

  // finger / pen: pointer events on the handle, hit-testing the point
  var PTR = null;
  document.addEventListener('pointerdown', function (e) {
    var h = e.target.closest && e.target.closest('[data-pms-handle]');
    if (!h || e.pointerType === 'mouse') return;
    e.preventDefault();
    DRAG = h.getAttribute('data-pms-handle');
    PTR = e.pointerId;
    try { h.setPointerCapture(e.pointerId); } catch (err) {}
    var r = h.closest('.pms-row'); if (r) r.classList.add('dragging');
  });
  document.addEventListener('pointermove', function (e) {
    if (PTR === null || e.pointerId !== PTR || !DRAG) return;
    var hit = document.elementFromPoint(e.clientX, e.clientY);
    markOver(hit && hit.closest ? hit.closest('.pms-row') : null);
  });
  function endPointer(e, commit) {
    if (PTR === null || e.pointerId !== PTR) return;
    var k = DRAG, target = null;
    if (commit) { var hit = document.elementFromPoint(e.clientX, e.clientY); var r = hit && hit.closest ? hit.closest('.pms-row') : null; target = r ? r.getAttribute('data-pms-row') : null; }
    PTR = null; DRAG = null; clearMarks();
    if (target) dropOn(k, target);
  }
  document.addEventListener('pointerup', function (e) { endPointer(e, true); });
  document.addEventListener('pointercancel', function (e) { endPointer(e, false); });

  // keyboard: arrow keys on the handle
  document.addEventListener('keydown', function (e) {
    var h = e.target.closest && e.target.closest('[data-pms-handle]');
    if (h && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
      e.preventDefault();
      var k = h.getAttribute('data-pms-handle');
      moveTo(k, ORDER.indexOf(k) + (e.key === 'ArrowUp' ? -1 : 1));
      repaintList('[data-pms-handle="' + k + '"]');
      return;
    }
    var t = e.target;
    if (t && t.classList && t.classList.contains('ectog') && (t.hasAttribute('data-pms-on') || t.hasAttribute('data-pms-opt')) && (e.key === ' ' || e.key === 'Enter')) {
      e.preventDefault(); t.click();
    }
  });

  /* ── edits ─────────────────────────────────────────────────────────────── */

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !ORDER) return;
    if (t.hasAttribute && t.hasAttribute('data-pms-gap')) {
      var f = opt('gap'); if (!f) return;
      f.value = +t.value;
      var o = document.getElementById('pmsGapOut'); if (o) o.textContent = gap() + 'px';
      document.querySelectorAll('[data-pms-space]').forEach(function (i) { i.placeholder = String(gap()); });
      markDirty(); return;
    }
    if (t.hasAttribute && t.hasAttribute('data-pms-space')) {
      var k = t.getAttribute('data-pms-space'); if (!ROWS[k]) return;
      ROWS[k].space = clampSpace(t.value);
      markDirty(); return;
    }
    if (t.hasAttribute && t.hasAttribute('data-pms-opt') && t.tagName === 'INPUT') {
      var f2 = opt(t.getAttribute('data-pms-opt')); if (!f2) return;
      f2.value = t.value; markDirty();
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.hasAttribute && t.hasAttribute('data-pms-space')) {
      var k = t.getAttribute('data-pms-space'); if (ROWS && ROWS[k]) t.value = ROWS[k].space === null ? '' : ROWS[k].space;
    }
    if (t && t.tagName === 'SELECT' && t.hasAttribute('data-pms-opt')) {
      var f = opt(t.getAttribute('data-pms-opt')); if (!f) return;
      f.value = t.value; markDirty();
    }
  });

  window.addEventListener('click', async function (e) {
    var t = e.target;
    if (!t || typeof PP === 'undefined' || !PP || !ORDER) return;

    var go = t.closest && t.closest('[data-pms-goto]');
    if (go) { PPTAB = TAB; window.paintProductPage(); return; }

    var on = t.closest && t.closest('[data-pms-on]');
    if (on) {
      var k = on.getAttribute('data-pms-on'); if (!ROWS[k]) return;
      ROWS[k].on = !ROWS[k].on;
      on.classList.toggle('on', ROWS[k].on); on.setAttribute('aria-checked', String(ROWS[k].on));
      var rw = on.closest('.pms-row'); if (rw) rw.classList.toggle('off', !ROWS[k].on);
      markDirty(); return;
    }
    var ot = t.closest && t.closest('.ectog[data-pms-opt]');
    if (ot) {
      var f = opt(ot.getAttribute('data-pms-opt')); if (!f) return;
      f.value = !f.value; ot.classList.toggle('on', !!f.value); ot.setAttribute('aria-checked', String(!!f.value));
      markDirty(); return;
    }
    var up = t.closest && t.closest('[data-pms-up]'), dn = t.closest && t.closest('[data-pms-down]');
    if (up || dn) {
      var key = (up || dn).getAttribute(up ? 'data-pms-up' : 'data-pms-down');
      moveTo(key, ORDER.indexOf(key) + (up ? -1 : 1));
      repaintList('[data-pms-' + (up ? 'up' : 'down') + '="' + key + '"]:not([disabled])') ;
      return;
    }
    if (t.id === 'pmsReset') {
      /* ASKED FIRST. This listener is on `window` in the capture phase, which
         runs BEFORE the console's reset guard (document, capture) — so acting
         on the first click would reset without the question the guard exists
         to ask. The guard stops that click, asks, and on "Yes" clicks the
         button again carrying data-kbb-sure-pass; only that click acts.

         His order, his switches, the two named spacing exceptions, and every
         option back to what shipped. A buffer change, not a save — the bar
         says so, as every Reset on this screen does. */
      if (t.getAttribute('data-kbb-sure-pass') !== '1') return;
      var d = data(); if (!d || !d.defaults) return;
      ORDER = d.defaults.order.filter(function (k) { return !!ROWS[k]; });
      ORDER.forEach(function (k) { ROWS[k].on = !!d.defaults.on[k]; ROWS[k].space = clampSpace(d.defaults.space[k]); });
      optFields().forEach(function (f) { f.value = f.default; });
      markDirty(); window.paintProductPage(); return;
    }
    if (t.id === 'pmsDiscard' || t.id === 'ppDiscard' || t.id === 'ptsDiscard') {
      DIRTY = false; ORDER = null;
      if (t.id === 'pmsDiscard') renderProductPage();
      return;
    }

    if (t.id !== 'ppSave' || !DIRTY) return;

    var theirs = typeof PPDIRTY !== 'undefined' && PPDIRTY && Object.keys(PPDIRTY).some(function (k2) { return !!PPDIRTY[k2]; });
    if (!theirs) { e.stopPropagation(); e.preventDefault(); }

    var on2 = {}, space = {};
    ORDER.forEach(function (k3) { on2[k3] = !!ROWS[k3].on; space[k3] = ROWS[k3].space; });
    var options = {};
    optFields().forEach(function (f3) { options[f3.key] = f3.value; });
    var payload = { msections: { list: { order: ORDER.slice(), on: on2, space: space }, options: options } };
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
        if (j.msections) PP.msections = j.msections;
        if (Array.isArray(j.sections)) PP.sections = j.sections;
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
