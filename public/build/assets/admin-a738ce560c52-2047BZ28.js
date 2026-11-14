
(function () {
  'use strict';
  if (typeof window.paintProductPage !== 'function' || window.paintProductPage.__gsa) return;

  var TAB = 'giftstrip';
  var DIRTY = false;
  var W = null; // { options: {key: value}, phone: bool }
  var HEX = /^#[0-9a-fA-F]{6}$/;
  var CVAR = { ink: 'ink', accent: 'ac', bg: 'bg', c1: 'c1', c2: 'c2', c3: 'c3', icon_bg: 'ib' };

  function data() { return (typeof PP !== 'undefined' && PP && PP.giftstrip) ? PP.giftstrip : null; }
  function look() { var d = data(); return (d && d.look && d.look.styles) ? d.look : null; }
  function cur() { var L = look(); var k = W && W.options.style; return L ? (L.styles[k] ? k : 'underline') : 'underline'; }
  function curStyle() { var L = look(); return L ? L.styles[cur()] : null; }
  // Does the chosen style use this control? (Grey it out when not.)
  function applies(k) {
    var st = curStyle(); if (!st) return true;
    if (CVAR[k] && k !== 'icon_bg') return Object.prototype.hasOwnProperty.call(st.own, CVAR[k]);
    if (k === 'icon_bg') return !!st.circle;
    if (k === 'confetti') return !!st.confetti;
    if (k === 'shine' || k === 'speed') return !!st.moves;
    return true;
  }
  function ownHex(k) { var st = curStyle(); var v = st && st.own ? st.own[CVAR[k]] : ''; return HEX.test(String(v || '')) ? String(v).toUpperCase() : ''; }
  // The shop's own style sheet for every style, once, for the sample only.
  function lookCss() {
    var L = look(); if (!L || document.getElementById('gsaLookCss')) return;
    var el = document.createElement('style'); el.id = 'gsaLookCss'; el.textContent = String(L.css || ''); document.head.appendChild(el);
  }
  function fields() { var d = data(); var out = []; if (d && Array.isArray(d.options)) d.options.forEach(function (t) { (t.fields || []).forEach(function (f) { out.push(f); }); }); return out; }
  function field(k) { return fields().find(function (f) { return f.key === k; }) || null; }

  function load() {
    var d = data();
    if (!d) { W = null; return; }
    W = { options: {}, phone: !!d.phone };
    fields().forEach(function (f) { W.options[f.key] = f.value; });
  }

  function markDirty() {
    DIRTY = true;
    var d = document.getElementById('ppDirty');
    if (d) { d.style.visibility = 'visible'; d.classList.remove('ok'); d.textContent = 'Unsaved changes'; }
  }

  function lbl(title, help) {
    return '<div class="mmlbl"><b>' + escHtml(title) + '</b>' + (help ? '<span>' + escHtml(help) + '</span>' : '') + '</div>';
  }
  function tog(attr, key, on, label) {
    return '<span class="ectog' + (on ? ' on' : '') + '" ' + attr + '="' + escAttr(key) + '" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" tabindex="0" aria-label="' + escAttr(label) + '"></span>';
  }

  function clamp(f, v) {
    var o = (f && f.options) || {};
    var step = +o.step || 1;
    var n = Number(v); if (!isFinite(n)) n = Number(f.default);
    n = Math.round(n / step) * step;
    return Math.min(+o.max, Math.max(+o.min, n));
  }

  function rangeText(k, v, o) {
    if (k === 'height' && Number(v) === 0) return 'Style’s own';
    if (k === 'radius' && Number(v) < 0) return 'Style’s own';
    return String(v) + (o.unit || '');
  }

  function row(k) {
    var f = field(k); if (!f) return '';
    var v = W.options[k];
    var rc = 'mmrow' + (applies(k) ? '' : ' gsa-na');
    if (f.type === 'bool') return '<div class="' + rc + '">' + lbl(f.label, f.help) + tog('data-gsa-opt', k, !!v, f.label) + '</div>';
    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="' + rc + '">' + lbl(f.label, f.help) + '<span class="mmrange"><input type="range" min="' + escAttr(String(o.min)) + '" max="' + escAttr(String(o.max)) + '" step="' + escAttr(String(o.step || 1)) + '" value="' + escAttr(String(v)) + '" data-gsa-opt="' + escAttr(k) + '" aria-label="' + escAttr(f.label) + '"><i id="gsav-' + escAttr(k) + '">' + escHtml(rangeText(k, v, o)) + '</i></span></div>';
    }
    if (f.type === 'select') {
      var sel = Object.prototype.hasOwnProperty.call(f.options || {}, String(v)) ? String(v) : String(f.default);
      var opt = function (ok) { return '<option value="' + escAttr(ok) + '"' + (ok === sel ? ' selected' : '') + '>' + escHtml(f.options[ok]) + '</option>'; };
      var L = look(), inner;
      if (k === 'style' && L) {
        // Grouped: One line / Calm cards / Colourful, from the server's own constants.
        inner = Object.keys(L.groups).map(function (g) {
          return '<optgroup label="' + escAttr(L.groups[g]) + '">' + Object.keys(f.options || {}).filter(function (ok) { return L.styles[ok] && L.styles[ok].group === g; }).map(opt).join('') + '</optgroup>';
        }).join('');
      } else {
        inner = Object.keys(f.options || {}).map(opt).join('');
      }
      return '<div class="' + rc + '">' + lbl(f.label, f.help) + '<select data-gsa-opt="' + escAttr(k) + '" aria-label="' + escAttr(f.label) + '">' + inner + '</select></div>';
    }
    if (f.type === 'colour') {
      // Empty = the style's own colour: the picker shows it, the box stays empty.
      var mine = HEX.test(String(v)) ? String(v).toUpperCase() : '';
      var show = mine || ownHex(k) || '#FFFFFF';
      return '<div class="' + rc + '">' + lbl(f.label, f.help) + '<span class="gsa-col"><input type="color" value="' + escAttr(show.toLowerCase()) + '" data-gsa-col="' + escAttr(k) + '" aria-label="' + escAttr(f.label) + '"><input type="text" maxlength="7" value="' + escAttr(mine) + '" placeholder="' + escAttr(ownHex(k) || 'none') + '" data-gsa-hex="' + escAttr(k) + '" aria-label="' + escAttr(f.label + ' (hex)') + '" spellcheck="false">'
        + (mine ? '<button type="button" class="gsa-own" data-gsa-own="' + escAttr(k) + '">Style’s own</button>' : '') + '</span></div>';
    }
    var rtl = /_ar$/.test(k) ? ' dir="rtl" lang="ar"' : '';
    return '<div class="mmrow">' + lbl(f.label, f.help) + '<input type="text" maxlength="80" value="' + escAttr(String(v == null ? '' : v)) + '" data-gsa-opt="' + escAttr(k) + '"' + rtl + ' aria-label="' + escAttr(f.label) + '"></div>';
  }

  function rgba(hex) { var n = parseInt(hex.slice(1), 16); return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',.55)'; }

  // ProductGiftStrip::emWidth(), the same table: the words' width in 1/100 em.
  function emWidth(t) {
    var L = look(), w = 0, i, c;
    for (i = 0; i < t.length; i++) { c = t.charCodeAt(i); if (c >= 0xDC00 && c <= 0xDFFF) continue; w += (c >= 32 && c < 127 && L) ? L.widths[c - 32] : (L ? L.nonAscii : 56); }
    return Math.max(100, w);
  }

  // The live sample: the shop's markup for the chosen style. Every value in
  // its style is a clamped number or a checked #hex; the words are escaped.
  function sample(cls) {
    var o = W.options, st = curStyle();
    if (!st) return '';
    var l1 = String(o.line1 || '').trim(), l2 = String(o.line2 || '').trim();
    var words = (l1 ? '<b>' + escHtml(l1) + '</b>' : '') + (l1 && l2 ? ' ' : '') + (l2 ? '<span class="pgs-l">' + escHtml(l2) + '</span>' : '');
    var css = ['--pgs-w:' + emWidth(l1 + (l1 && l2 ? ' ' : '') + l2)];
    Object.keys(CVAR).forEach(function (k) {
      var v = String(o[k] || ''); if (!HEX.test(v)) return;
      css.push('--pgs-' + CVAR[k] + ':' + (k === 'icon_bg' ? rgba(v) : v.toUpperCase()));
    });
    var h = clamp(field('height'), o.height), r = clamp(field('radius'), o.radius), fs = clamp(field('fs'), o.fs);
    if (h > 0) css.push('--pgs-h:' + h + 'px');
    if (r >= 0) css.push('--pgs-r:' + r + 'px');
    css.push('--pgs-fs:' + fs + 'px');
    var c = 'pdp-gs ' + st.classes + (o.icon ? '' : ' pgs-noicon') + (o.confetti ? '' : ' gsa-nocf')
      + (!o.shine ? ' pgs-still' : (o.speed === 'slow' || o.speed === 'fast' ? ' pgs-sp-' + o.speed : ''));
    return '<div class="gsa-fr ' + cls + '"><div class="' + escAttr(c) + '" style="' + escAttr(css.join(';')) + '">' + String(st.inner).split('%T%').join(words) + '</div></div>';
  }
  function samples() {
    return '<small>Phone</small>' + sample('gsa-fph') + '<small>Laptop · buy column</small>' + sample('gsa-flp');
  }
  function paintSample() { var el = document.getElementById('gsaSample'); if (el && W) el.innerHTML = samples(); }
  function paintCol() { var col = document.getElementById('ppCol'); if (col && W && typeof PPTAB !== 'undefined' && PPTAB === TAB) col.innerHTML = body(); }

  function body() {
    var phone = '<div class="mmrow">' + lbl('On phones', 'The strip’s row on the Mobile sections tab — drag it there to move it on the phone page. It sits directly above Tabby & Tamara.') + tog('data-gsa-dev', 'phone', W.phone, 'Show on phones') + '</div>';
    lookCss();
    var card1 = '<div class="card mmcard"><div class="mmhd"><b>Product gifts strip</b><span>“Free gifts with every order. Limited time only!” on every product page, on one line that sizes itself to the screen. Changes show on the sample now and on the shop after Save changes.</span></div>'
      + '<div class="gsa-prev"><div id="gsaSample">' + samples() + '</div></div>'
      + '<div class="mmbody">' + row('on') + phone + ['desk', 'style', 'line1', 'line2', 'line1_ar', 'line2_ar'].map(row).join('') + '</div></div>';
    var card2 = '<div class="card mmcard"><div class="mmhd"><b>Colours</b><span>Empty = the chosen style’s own colour, shown in the picker. Pick a colour to change it; “Style’s own” puts it back.</span></div>'
      + '<div class="mmbody">' + ['ink', 'accent', 'bg', 'c1', 'c2', 'c3', 'icon_bg'].map(row).join('') + '</div></div>';
    var card3 = '<div class="card mmcard"><div class="mmhd"><b>Effects &amp; size</b><span>The movement stops by itself for shoppers whose phone asks for reduced motion. The words stay on one line: Largest font size is the most they grow to.</span></div>'
      + '<div class="mmbody">' + ['icon', 'confetti', 'shine', 'speed', 'fs', 'height', 'radius', 'above', 'below'].map(row).join('') + '</div></div>';
    return '<div class="mmcols">' + card1 + card2 + card3 + '</div>';
  }

  var original = window.paintProductPage;
  var wrapped = function (rebuild) {
    var ours = (typeof PPTAB !== 'undefined' && PPTAB === TAB);
    original.apply(this, arguments);
    if (!data()) return;
    if (W === null || rebuild) { if (!DIRTY) load(); }

    var strip = document.querySelector('#ppStrip .ectabs');
    if (strip && !strip.querySelector('[data-gsatab]')) {
      strip.insertAdjacentHTML('beforeend', '<button class="ectab' + (ours ? ' on' : '') + '" data-gsatab="' + TAB + '">Product gifts strip<span class="ecn">' + fields().length + '</span></button>');
      strip.querySelector('[data-gsatab]').onclick = function () { PPTAB = TAB; window.paintProductPage(); };
    }

    if (ours && W) {
      var col = document.getElementById('ppCol');
      if (col) col.innerHTML = body();
      var lede = document.getElementById('ppLede');
      if (lede) lede.textContent = 'The free gifts strip on the PRODUCT page (the homepage strip is Appearance → Free gifts strip): on or off, where it sits, its style, words, colours, effects and sizes. The preview shows saved changes after you press Save changes.';
      var acts = document.getElementById('ppActs');
      if (acts) acts.innerHTML = '<button class="btn" id="gsaDiscard">Discard</button>';
      if (DIRTY) { var d = document.getElementById('ppDirty'); if (d) { d.style.visibility = 'visible'; d.textContent = 'Unsaved changes'; } }
    }
  };
  wrapped.__gsa = true;
  window.paintProductPage = wrapped;

  if (document.getElementById('ppShell') && typeof PP !== 'undefined' && PP) window.paintProductPage();

  /* ── edits ─────────────────────────────────────────────────────────────── */

  function setColour(k, hex, from) {
    if (!HEX.test(hex) || !field(k)) return;
    W.options[k] = hex.toUpperCase();
    var c = document.querySelector('[data-gsa-col="' + k + '"]'), t = document.querySelector('[data-gsa-hex="' + k + '"]');
    if (c && from !== c) c.value = hex.toLowerCase();
    if (t && from !== t) t.value = hex.toUpperCase();
    markDirty(); paintSample();
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !W || !t.hasAttribute) return;
    if (t.hasAttribute('data-gsa-col')) { setColour(t.getAttribute('data-gsa-col'), String(t.value), t); return; }
    if (t.hasAttribute('data-gsa-hex')) { var v = String(t.value).trim(); if (v.charAt(0) !== '#') v = '#' + v; setColour(t.getAttribute('data-gsa-hex'), v, t); return; }
    if (t.hasAttribute('data-gsa-opt') && t.tagName === 'INPUT') {
      var k = t.getAttribute('data-gsa-opt'); var f = field(k); if (!f) return;
      W.options[k] = f.type === 'range' ? clamp(f, t.value) : String(t.value);
      var out = document.getElementById('gsav-' + k); if (out) out.textContent = rangeText(k, W.options[k], f.options || {});
      markDirty(); paintSample();
    }
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !W || !t.hasAttribute) return;
    if (t.tagName === 'SELECT' && t.hasAttribute('data-gsa-opt')) {
      var k = t.getAttribute('data-gsa-opt'); var f = field(k); if (!f) return;
      W.options[k] = Object.prototype.hasOwnProperty.call(f.options || {}, t.value) ? t.value : f.default;
      markDirty();
      if (k === 'style') paintCol(); else paintSample();
    }
    // A colour settled (picker closed, box left): redraw the column so its
    // "Style's own" button shows; a half-typed box goes back to the value in use.
    if (t.hasAttribute('data-gsa-col') || t.hasAttribute('data-gsa-hex')) paintCol();
  });

  window.addEventListener('click', async function (e) {
    var t = e.target;
    if (!t || typeof PP === 'undefined' || !PP || !W) return;

    var ot = t.closest && t.closest('.ectog[data-gsa-opt]');
    if (ot) {
      var k = ot.getAttribute('data-gsa-opt'); if (!field(k)) return;
      W.options[k] = !W.options[k];
      ot.classList.toggle('on', !!W.options[k]); ot.setAttribute('aria-checked', String(!!W.options[k]));
      markDirty(); paintSample(); return;
    }
    var own = t.closest && t.closest('[data-gsa-own]');
    if (own) {
      var ko = own.getAttribute('data-gsa-own'); if (!field(ko)) return;
      W.options[ko] = '';
      markDirty(); paintCol(); return;
    }
    var dv = t.closest && t.closest('[data-gsa-dev]');
    if (dv) {
      W.phone = !W.phone;
      dv.classList.toggle('on', W.phone); dv.setAttribute('aria-checked', String(W.phone));
      markDirty(); return;
    }
    if (t.id === 'gsaDiscard' || t.id === 'ppDiscard' || t.id === 'pmsDiscard' || t.id === 'ptsDiscard' || t.id === 'btpDiscard') {
      DIRTY = false; W = null;
      if (t.id === 'gsaDiscard') renderProductPage();
      return;
    }

    if (t.id !== 'ppSave' || !DIRTY) return;

    // Another half dirty too? Let the console's own Save run beside this one.
    var theirs = typeof PPDIRTY !== 'undefined' && PPDIRTY && Object.keys(PPDIRTY).some(function (k2) { return !!PPDIRTY[k2]; });
    if (!theirs) { e.stopPropagation(); e.preventDefault(); }

    var opts = {};
    fields().forEach(function (f) {
      var v = W.options[f.key];
      if (f.type === 'colour' && !HEX.test(String(v))) v = '';   // never a broken colour: '' is the style's own
      if (f.type === 'range') v = clamp(f, v);
      opts[f.key] = v;
    });
    var payload = { giftstrip: { options: opts, phone: !!W.phone } };
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
        if (j.giftstrip) PP.giftstrip = j.giftstrip;
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
    if (t && t.classList && t.classList.contains('ectog') && (t.hasAttribute('data-gsa-opt') || t.hasAttribute('data-gsa-dev')) && (e.key === ' ' || e.key === 'Enter')) {
      e.preventDefault(); t.click();
    }
  });
})();
