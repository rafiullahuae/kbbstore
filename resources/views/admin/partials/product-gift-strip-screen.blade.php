{{--
    Appearance → Product page → Product gifts strip.                   (Lane GS)

    Named “Product gifts strip” because Appearance → Free gifts strip is the
    HOMEPAGE strip (another screen, another setting set).

      "KL Confetti + Holographic sheen is fine, please give me patch. to
       control on off, and change position on the product page, keep above
       tabby tamara section by default. also give control of colors, effects,
       overal height, font size etc."

    ONE MORE TAB ON THE SCREEN THAT OWNS THE PRODUCT PAGE, added exactly the
    way Buy these together and Desktop sections are: this file wraps
    paintProductPage(), lets the original and every earlier wrapper paint, then
    appends one button to the same strip and, when it is selected, fills the
    same column. Its data is `PP.giftstrip` from the same GET
    (ProductPageApiController::show()); it saves under its own `giftstrip` key
    of the same POST, so no new endpoint and no new capability.

    Three cards — Strip (on/off, phone switch, laptop position, words), Colours,
    Effects & size — and a live sample at the top that follows every control
    before anything is saved, built here from values the page already has (no
    request per change). The phone POSITION is the strip's row on the Mobile
    sections tab; "On phones" here IS that row's switch.

    RULE 5: every string reaches the DOM through escHtml/escAttr; a select
    sends one of its own keys; a number is clamped to the server's bounds; a
    colour is #RRGGBB or it is not sent. The server re-validates all of it
    (ProductGiftStrip's ModuleSchema casts) and refuses an unknown key.
--}}
@verbatim
<style>
.gsa-prev{padding:14px 16px;background:#fbfcfd;border-bottom:1px solid #eef1f5}
.gsa-prev small{display:block;font-size:11.5px;color:#7b8697;margin-bottom:8px}
.gsa-s{position:relative;overflow:hidden;display:flex;align-items:center;gap:12px;padding:10px 16px 10px 12px;isolation:isolate;max-width:420px;font-family:Outfit,system-ui,sans-serif;font-weight:600;line-height:1.3;box-shadow:0 6px 16px rgba(255,111,145,.25)}
.gsa-s b{font-weight:700;line-height:1.25}.gsa-s .l{font-weight:500;opacity:.92}
.gsa-s .i{display:inline-grid;place-items:center;flex:none;width:42px;height:42px;border-radius:50%;box-shadow:inset 0 0 0 1px rgba(255,255,255,.6);position:relative;z-index:1}
.gsa-s .t{min-width:0;flex:1;display:flex;flex-direction:column;gap:2px;position:relative;z-index:1}
.gsa-s.sh::after{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(105deg,transparent 30%,rgba(255,255,255,.85) 45%,transparent 60%) 0 0/250% 100%;animation:gsaSheen var(--d,6s) linear infinite}
@keyframes gsaSheen{from{background-position:150% 0}to{background-position:-100% 0}}
@media (prefers-reduced-motion:reduce){.gsa-s.sh::after{animation:none}}
.gsa-col{display:inline-flex;align-items:center;gap:8px}
.gsa-col input[type=color]{inline-size:38px;block-size:30px;padding:0;border:1px solid #dfe5ec;border-radius:7px;background:#fff;cursor:pointer}
.gsa-col input[type=text]{min-width:0 !important;inline-size:92px;font-family:ui-monospace,monospace !important}
</style>
<script>
(function () {
  'use strict';
  if (typeof window.paintProductPage !== 'function' || window.paintProductPage.__gsa) return;

  var TAB = 'giftstrip';
  var DIRTY = false;
  var W = null; // { options: {key: value}, phone: bool }
  var SPEED = { slow: '9s', normal: '6s', fast: '3.5s' };
  var HEX = /^#[0-9a-fA-F]{6}$/;

  function data() { return (typeof PP !== 'undefined' && PP && PP.giftstrip) ? PP.giftstrip : null; }
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

  function row(k) {
    var f = field(k); if (!f) return '';
    var v = W.options[k];
    if (f.type === 'bool') return '<div class="mmrow">' + lbl(f.label, f.help) + tog('data-gsa-opt', k, !!v, f.label) + '</div>';
    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="mmrow">' + lbl(f.label, f.help) + '<span class="mmrange"><input type="range" min="' + escAttr(String(o.min)) + '" max="' + escAttr(String(o.max)) + '" step="' + escAttr(String(o.step || 1)) + '" value="' + escAttr(String(v)) + '" data-gsa-opt="' + escAttr(k) + '" aria-label="' + escAttr(f.label) + '"><i id="gsav-' + escAttr(k) + '">' + escHtml(String(v) + (o.unit || '')) + '</i></span></div>';
    }
    if (f.type === 'select') {
      var cur = Object.prototype.hasOwnProperty.call(f.options || {}, String(v)) ? String(v) : String(f.default);
      return '<div class="mmrow">' + lbl(f.label, f.help) + '<select data-gsa-opt="' + escAttr(k) + '" aria-label="' + escAttr(f.label) + '">' + Object.keys(f.options || {}).map(function (ok) {
        return '<option value="' + escAttr(ok) + '"' + (ok === cur ? ' selected' : '') + '>' + escHtml(f.options[ok]) + '</option>';
      }).join('') + '</select></div>';
    }
    if (f.type === 'colour') {
      var hex = HEX.test(String(v)) ? String(v).toUpperCase() : String(f.default);
      return '<div class="mmrow">' + lbl(f.label, f.help) + '<span class="gsa-col"><input type="color" value="' + escAttr(hex.toLowerCase()) + '" data-gsa-col="' + escAttr(k) + '" aria-label="' + escAttr(f.label) + '"><input type="text" maxlength="7" value="' + escAttr(hex) + '" data-gsa-hex="' + escAttr(k) + '" aria-label="' + escAttr(f.label + ' (hex)') + '" spellcheck="false"></span></div>';
    }
    var rtl = /_ar$/.test(k) ? ' dir="rtl" lang="ar"' : '';
    return '<div class="mmrow">' + lbl(f.label, f.help) + '<input type="text" maxlength="80" value="' + escAttr(String(v == null ? '' : v)) + '" data-gsa-opt="' + escAttr(k) + '"' + rtl + ' aria-label="' + escAttr(f.label) + '"></div>';
  }

  var GIFT = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';

  function hexOr(k) { var v = String(W.options[k] || ''); var f = field(k); return HEX.test(v) ? v : (f ? String(f.default) : '#000000'); }
  function rgba(hex) { var n = parseInt(hex.slice(1), 16); return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',.55)'; }

  // The live sample: every value here is a clamped number or a checked #hex.
  function sample() {
    var o = W.options;
    var style = 'background:linear-gradient(90deg,' + hexOr('c1') + ',' + hexOr('c2') + ' 55%,' + hexOr('c3') + ');color:' + hexOr('ink')
      + ';min-height:' + clamp(field('height'), o.height) + 'px;border-radius:' + clamp(field('radius'), o.radius) + 'px;--d:' + (SPEED[o.speed] || '6s');
    var l1 = String(o.line1 || ''), l2 = String(o.line2 || '');
    return '<div class="gsa-s' + (o.shine ? ' sh' : '') + '" style="' + escAttr(style) + '">'
      + (o.icon ? '<span class="i" style="background:' + escAttr(rgba(hexOr('icon_bg'))) + '">' + GIFT + '</span>' : '')
      + '<span class="t">' + (l1 ? '<b style="font-size:' + clamp(field('fs1'), o.fs1) + 'px">' + escHtml(l1) + '</b>' : '')
      + (l2 ? '<span class="l" style="font-size:' + clamp(field('fs2'), o.fs2) + 'px">' + escHtml(l2) + '</span>' : '') + '</span></div>';
  }
  function paintSample() { var el = document.getElementById('gsaSample'); if (el && W) el.innerHTML = sample(); }

  function body() {
    var phone = '<div class="mmrow">' + lbl('On phones', 'The strip’s row on the Mobile sections tab — drag it there to move it on the phone page. It sits directly above Tabby & Tamara.') + tog('data-gsa-dev', 'phone', W.phone, 'Show on phones') + '</div>';
    var card1 = '<div class="card mmcard"><div class="mmhd"><b>Product gifts strip</b><span>The “Free gifts with every order” strip on every product page, with falling confetti and a slow holographic shine. Changes show on the sample now and on the shop after Save changes.</span></div>'
      + '<div class="gsa-prev"><small>Sample (English words; the confetti shows on the shop)</small><div id="gsaSample">' + sample() + '</div></div>'
      + '<div class="mmbody">' + row('on') + phone + ['desk', 'line1', 'line2', 'line1_ar', 'line2_ar'].map(row).join('') + '</div></div>';
    var card2 = '<div class="card mmcard"><div class="mmhd"><b>Colours</b><span>The shipped colours are the approved design: yellow #FFD43B → coral #FF9F6E → pink #FF6F91, dark plum words.</span></div>'
      + '<div class="mmbody">' + ['c1', 'c2', 'c3', 'ink', 'icon_bg'].map(row).join('') + '</div></div>';
    var card3 = '<div class="card mmcard"><div class="mmhd"><b>Effects &amp; size</b><span>The motion stops by itself for shoppers whose phone asks for reduced motion.</span></div>'
      + '<div class="mmbody">' + ['icon', 'confetti', 'shine', 'speed', 'height', 'fs1', 'fs2', 'radius', 'above', 'below'].map(row).join('') + '</div></div>';
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
      if (lede) lede.textContent = 'The free gifts strip on the PRODUCT page (the homepage strip is Appearance → Free gifts strip): on or off, where it sits, its words, colours, effects and sizes. The preview shows saved changes after you press Save changes.';
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
      var out = document.getElementById('gsav-' + k); if (out) out.textContent = String(W.options[k]) + ((f.options && f.options.unit) || '');
      markDirty(); paintSample();
    }
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !W || !t.hasAttribute) return;
    if (t.tagName === 'SELECT' && t.hasAttribute('data-gsa-opt')) {
      var k = t.getAttribute('data-gsa-opt'); var f = field(k); if (!f) return;
      W.options[k] = Object.prototype.hasOwnProperty.call(f.options || {}, t.value) ? t.value : f.default;
      markDirty(); paintSample();
    }
    // A hex box left half-typed goes back to the value in use.
    if (t.hasAttribute('data-gsa-hex')) { var k2 = t.getAttribute('data-gsa-hex'); t.value = hexOr(k2).toUpperCase(); }
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
      if (f.type === 'colour' && !HEX.test(String(v))) return;   // never send a broken colour
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
</script>
@endverbatim
