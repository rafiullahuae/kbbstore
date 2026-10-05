
(function () {
  'use strict';

  if (typeof window.paintProductPage !== 'function' || window.paintProductPage.__pts) return;

  /** The tab keys this file owns. ProductTrustShare::TABS. */
  var OURS = ['ts_delivery', 'ts_auth', 'ts_share', 'ts_card', 'ts_space'];
  var DIRTY = false;

  function data() { return (typeof PP !== 'undefined' && PP && Array.isArray(PP.trust)) ? PP.trust : []; }
  function fields() { var out = []; data().forEach(function (t) { t.fields.forEach(function (f) { out.push(f); }); }); return out; }
  function field(k) { return fields().find(function (f) { return f.key === k; }) || null; }
  function props() { return (typeof PP !== 'undefined' && PP && PP.preview && PP.preview.trust_props) || {}; }
  function isOurs(tab) { return OURS.indexOf(tab) >= 0; }

  function show(f) {
    var o = f.options || {}, sc = (+o.scale) || 1;
    return (sc > 1 ? String(+f.value / sc) : String(f.value)) + (o.unit || '');
  }

  function help(f) { return f.help ? '<span>' + escHtml(f.help) + '</span>' : ''; }

  function control(f) {
    var k = escAttr(f.key), v = f.value;
    if (f.type === 'bool') {
      return '<div class="mmrow"><div class="mmlbl"><b>' + escHtml(f.label) + '</b>' + help(f) + '</div>'
        + '<span class="ectog' + (v ? ' on' : '') + '" data-pts-k="' + k + '" role="switch" aria-checked="' + (!!v) + '" tabindex="0" aria-label="' + escAttr(f.label) + '"></span></div>';
    }
    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="mmrow"><div class="mmlbl"><b>' + escHtml(f.label) + '</b>' + help(f) + '</div>'
        + '<span class="mmrange"><input type="range" min="' + escAttr(o.min) + '" max="' + escAttr(o.max) + '" step="' + escAttr(o.step || 1) + '" value="' + escAttr(v) + '" data-pts-k="' + k + '" aria-label="' + escAttr(f.label) + '">'
        + '<i id="ptsv-' + k + '">' + escHtml(show(f)) + '</i></span></div>';
    }
    if (f.type === 'select') {
      return '<div class="mmrow"><div class="mmlbl"><b>' + escHtml(f.label) + '</b>' + help(f) + '</div>'
        + '<select data-pts-k="' + k + '">' + Object.keys(f.options || {}).map(function (ok) {
          return '<option value="' + escAttr(ok) + '"' + (String(ok) === String(v) ? ' selected' : '') + '>' + escHtml(f.options[ok]) + '</option>';
        }).join('') + '</select></div>';
    }
    if (f.type === 'colour') {
      var hex = /^#[0-9a-f]{6}$/i.test(String(v)) ? String(v) : String(f.default);
      return '<div class="mmrow"><div class="mmlbl"><b>' + escHtml(f.label) + '</b>' + help(f) + '</div>'
        + '<span class="mmcol"><input type="color" value="' + escAttr(hex) + '" data-pts-k="' + k + '" aria-label="' + escAttr(f.label) + '"><code id="ptsv-' + k + '">' + escHtml(hex) + '</code></span></div>';
    }
    if (f.key === 'del_image') {
      return '<div class="mmrow" style="display:block"><div class="mmlbl" style="margin-bottom:8px"><b>' + escHtml(f.label) + '</b>' + help(f) + '</div>'
        + '<div class="pts-adm-img"><span class="pts-adm-thumb" id="ptsImgThumb">' + thumb(v) + '</span>'
        + '<button type="button" class="btn small" id="ptsImgPick">Choose from Media Library</button>'
        + '<button type="button" class="btn small ghost" id="ptsImgClear">Use the drawn truck</button>'
        + '<input type="text" class="inp" placeholder="or paste https://…" value="' + escAttr(v) + '" data-pts-k="' + k + '" aria-label="' + escAttr(f.label) + '"></div></div>';
    }
    if (f.key === 'share_order') {
      var names = (typeof PP !== 'undefined' && PP && PP.preview && PP.preview.share_networks) || {};
      var keys = orderKeys(v, names);
      return '<div class="mmrow" style="display:block"><div class="mmlbl" style="margin-bottom:8px"><b>' + escHtml(f.label) + '</b>' + help(f) + '</div>'
        + '<ol class="pts-adm-order" id="ptsOrder">' + keys.map(function (nk, i) {
          var sw = field('share_' + nk), on = !!(sw && sw.value);
          return '<li class="' + (on ? 'on' : 'off') + '" data-k="' + escAttr(nk) + '"><b>' + escHtml(names[nk]) + '</b>' + (on ? '' : '<em>off</em>')
            + '<button type="button" class="btn small ghost" data-pts-move="-1" data-k="' + escAttr(nk) + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="Move ' + escAttr(names[nk]) + ' up">↑</button>'
            + '<button type="button" class="btn small ghost" data-pts-move="1" data-k="' + escAttr(nk) + '"' + (i === keys.length - 1 ? ' disabled' : '') + ' aria-label="Move ' + escAttr(names[nk]) + ' down">↓</button></li>';
        }).join('') + '</ol></div>';
    }
    if (f.type === 'textarea') {
      return '<div class="mmrow" style="display:block"><div class="mmlbl" style="margin-bottom:8px"><b>' + escHtml(f.label) + '</b>' + help(f) + '</div>'
        + '<textarea class="pts-adm-ta" data-pts-k="' + k + '" aria-label="' + escAttr(f.label) + '">' + escHtml(v) + '</textarea></div>';
    }
    return '<div class="mmrow"><div class="mmlbl"><b>' + escHtml(f.label) + '</b>' + help(f) + '</div>'
      + '<input type="text" value="' + escAttr(v) + '" data-pts-k="' + k + '" aria-label="' + escAttr(f.label) + '"></div>';
  }

  /** The tile order as a list of known keys, every platform once — the server's cleanOrder(), mirrored. */
  function orderKeys(v, names) {
    var out = [];
    String(v || '').split(',').forEach(function (k) { k = k.trim(); if (names[k] && out.indexOf(k) < 0) out.push(k); });
    Object.keys(names).forEach(function (k) { if (out.indexOf(k) < 0) out.push(k); });
    return out;
  }

  /** A preview of the picture, only for an address that is http(s) or a path on this site. */
  function safeImg(u) {
    u = String(u || '').trim();
    if (u === '') return '';
    if (/^https?:\/\//i.test(u)) return u;
    if (/^\/[^\/]/.test(u)) return u;
    return '';
  }
  function thumb(u) {
    var s = safeImg(u);
    return s ? '<img src="' + escAttr(s) + '" alt="">' : 'drawn truck';
  }

  /* ── the live preview ─────────────────────────────────────────────────── */

  function cssValue(f) {
    if (f.type === 'colour') return /^#[0-9a-f]{6}$/i.test(String(f.value)) ? String(f.value) : String(f.default);
    if (f.type !== 'range') return null;
    var o = f.options || {}, sc = (+o.scale) || 1, n = Number(f.value);
    if (!isFinite(n)) n = Number(f.default);
    n = Math.min(+o.max, Math.max(+o.min, n));
    return String(n / sc) + 'px';
  }

  function paintFrames() {
    var map = props();
    document.querySelectorAll('[data-ppframe]').forEach(function (fr) {
      var doc = null;
      try { doc = fr.contentDocument; } catch (e) { doc = null; }
      if (!doc) return;
      Object.keys(map).forEach(function (block) {
        if (!/^(del|auth|share)$/.test(block)) return;
        var els = doc.querySelectorAll('[data-pts="' + block + '"]');
        if (!els.length) return;
        Object.keys(map[block] || {}).forEach(function (key) {
          var prop = map[block][key], f = field(key);
          if (!f || typeof prop !== 'string' || !/^--pts-[a-z0-9-]+$/.test(prop)) return;
          var v = cssValue(f);
          if (v === null) return;
          els.forEach(function (el) { el.style.setProperty(prop, v); });
        });
      });
    });
  }

  function markDirty() {
    DIRTY = true;
    var d = document.getElementById('ppDirty');
    if (d) { d.style.visibility = 'visible'; d.classList.remove('ok'); d.textContent = 'Unsaved changes'; }
  }

  /* ── the wrap ─────────────────────────────────────────────────────────── */

  var original = window.paintProductPage;

  var wrapped = function (rebuild) {
    var ours = isOurs(typeof PPTAB !== 'undefined' ? PPTAB : '');
    original.apply(this, arguments);

    if (!data().length) return;

    var strip = document.querySelector('#ppStrip .ectabs');
    if (strip && !strip.querySelector('[data-ptstab]')) {
      strip.insertAdjacentHTML('beforeend', data().map(function (t) {
        return '<button class="ectab' + (t.key === PPTAB ? ' on' : '') + '" data-ptstab="' + escAttr(t.key) + '">'
          + escHtml(t.label) + '<span class="ecn">' + t.fields.length + '</span></button>';
      }).join(''));
      strip.querySelectorAll('[data-ptstab]').forEach(function (b) {
        b.onclick = function () { PPTAB = b.getAttribute('data-ptstab'); window.paintProductPage(); };
      });
    }

    if (ours) {
      var tab = data().find(function (t) { return t.key === PPTAB; });
      if (!tab) return;
      var col = document.getElementById('ppCol');
      if (col) {
        col.innerHTML = '<div class="mmcols"><div class="card mmcard">'
          + '<div class="mmhd"><b>' + escHtml(tab.label) + '</b><span>' + escHtml(tab.description) + '</span></div>'
          + '<div class="mmbody"><p class="pts-adm-note">Colours, sizes and spacing move in the preview as you change them. Words, the picture and the on/off switches appear there once you press <b>Save changes</b>.' + (tab.key === 'ts_share' ? ' The share sheet opens from the share icon beside the product title.' : '') + (tab.key === 'ts_card' ? ' WhatsApp remembers a link’s card for a while, so a product already shared can show its old card until WhatsApp refreshes it; new shares show the new card.' : '') + '</p>'
          + tab.fields.map(control).join('')
          /* 2.60.367: "Make all share pictures now" — the website makes every
             product's share picture itself, in slices (ShareImagesApiController).
             Writes picture files only; saves no setting. */
          + (tab.key === 'ts_card' ? '<div class="mmrow" style="display:block"><div class="mmlbl" style="margin-bottom:8px"><b>Share pictures</b><span>Each product gets its share picture the first time it is opened or shared. This makes all of them now, in the shape picked above (save first if you changed it). It only makes pictures; nothing else changes.</span></div>'
            + '<button type="button" class="btn small" id="ptsShareMake">Make all share pictures now</button><div id="ptsShareMsg" role="status" aria-live="polite" style="margin-top:8px"></div></div>' : '')
          + '</div></div></div>';
      }
      var lede = document.getElementById('ppLede');
      if (lede) lede.textContent = 'The delivery box and “Authenticity Guaranteed” in the buy column, and the share icon beside the title with the sheet it opens — at both widths.';
      var acts = document.getElementById('ppActs');
      if (acts) acts.innerHTML = '<button class="btn" id="ptsReset">Reset these tabs to defaults</button> <button class="btn" id="ptsDiscard">Discard</button>';
      if (DIRTY) markDirty();
    }

    paintFrames();
  };
  wrapped.__pts = true;
  window.paintProductPage = wrapped;

  /* A deep link (?go=productpage) can paint the screen BEFORE this script is
     parsed — the console's own script runs first and its fetch can land while
     the rest of this long document is still arriving. Measured: the four tabs
     were missing on exactly that path and present after any click. So if the
     screen is already up, paint it once more through the wrapper. */
  if (document.getElementById('ppShell') && typeof PP !== 'undefined' && PP) window.paintProductPage();

  /* The frames repaint themselves on load through the console's own listener;
     this one adds ours on the same event. Delegated, because the frames are
     created after this script runs. */
  document.addEventListener('load', function (e) {
    if (e.target && e.target.matches && e.target.matches('[data-ppframe]')) paintFrames();
  }, true);

  /* ── edits ────────────────────────────────────────────────────────────── */

  document.addEventListener('input', function (e) {
    var el = e.target.closest && e.target.closest('[data-pts-k]');
    if (!el || el.classList.contains('ectog')) return;
    var f = field(el.getAttribute('data-pts-k'));
    if (!f) return;
    f.value = el.type === 'range' ? +el.value : el.value;
    var out = document.getElementById('ptsv-' + f.key);
    if (out) out.textContent = f.type === 'colour' ? f.value : show(f);
    if (f.key === 'del_image') { var t = document.getElementById('ptsImgThumb'); if (t) t.innerHTML = thumb(f.value); }
    markDirty();
    paintFrames();
  });

  document.addEventListener('change', function (e) {
    var el = e.target.closest && e.target.closest('select[data-pts-k]');
    if (!el) return;
    var f = field(el.getAttribute('data-pts-k'));
    if (!f) return;
    f.value = el.value; markDirty();
  });

  function toggle(el) {
    var f = field(el.getAttribute('data-pts-k'));
    if (!f) return;
    f.value = !f.value;
    el.classList.toggle('on', !!f.value);
    el.setAttribute('aria-checked', String(!!f.value));
    markDirty();
  }

  document.addEventListener('keydown', function (e) {
    var el = e.target;
    if (el && el.classList && el.classList.contains('ectog') && el.hasAttribute('data-pts-k') && (e.key === ' ' || e.key === 'Enter')) {
      e.preventDefault(); toggle(el);
    }
  });

  /* Capture phase on window, so this runs before the screen's own click
     handler — which, seeing neither of ITS halves dirty, would otherwise answer
     "Nothing has changed." to a Save that has ours to send. */
  window.addEventListener('click', async function (e) {
    var t = e.target;
    if (!t || typeof PP === 'undefined' || !PP) return;

    var tog = t.closest && t.closest('.ectog[data-pts-k]');
    if (tog) {
      toggle(tog);
      // A platform switched on or off shows as such in the order list.
      if (/^share_/.test(tog.getAttribute('data-pts-k') || '') && document.getElementById('ptsOrder')) window.paintProductPage();
      return;
    }

    var mv = t.closest && t.closest('[data-pts-move]');
    if (mv) {
      var of = field('share_order');
      var nm = (PP.preview && PP.preview.share_networks) || {};
      if (!of) return;
      var list = orderKeys(of.value, nm), k = mv.getAttribute('data-k'), d = +mv.getAttribute('data-pts-move'), i = list.indexOf(k), j = i + d;
      if (i < 0 || j < 0 || j >= list.length) return;
      list.splice(i, 1); list.splice(j, 0, k);
      of.value = list.join(',');
      markDirty(); window.paintProductPage();
      var again = document.querySelector('[data-pts-move="' + (d < 0 ? '-1' : '1') + '"][data-k="' + k + '"]');
      if (again && !again.disabled) again.focus();
      return;
    }

    if (t.id === 'ptsImgPick' && typeof window.kbbPickMedia === 'function') {
      window.kbbPickMedia({ title: 'Delivery picture', note: 'Your FAST DELIVERY image. It replaces the drawn truck.', folder: 'appearance', onPick: function (urls) {
        var f = field('del_image'); if (!f || !urls || !urls[0]) return;
        f.value = String(urls[0]);
        var box = document.querySelector('[data-pts-k="del_image"]'); if (box) box.value = f.value;
        var th = document.getElementById('ptsImgThumb'); if (th) th.innerHTML = thumb(f.value);
        markDirty();
      } });
      return;
    }
    if (t.id === 'ptsImgClear') {
      var f = field('del_image'); if (!f) return;
      f.value = '';
      var box = document.querySelector('[data-pts-k="del_image"]'); if (box) box.value = '';
      var th = document.getElementById('ptsImgThumb'); if (th) th.innerHTML = thumb('');
      markDirty(); return;
    }
    if (t.id === 'ptsShareMake') {
      e.stopPropagation(); e.preventDefault();
      if (t.disabled) return;
      t.disabled = true;
      var out = document.getElementById('ptsShareMsg');
      var url = ppBase().replace(/\/product-page$/, '/share-images');
      var after = 0, total = null, seen = 0, made = 0, fresh = 0, skipped = 0;
      var say = function (s) { if (out) out.textContent = s; };
      say('Starting…');
      try {
        for (var guard = 0; guard < 2000; guard++) {
          var r = await fetch(url, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': uToken(), Accept: 'application/json' },
            body: JSON.stringify({ after: after }) });
          var j = null;
          try { j = await r.json(); } catch (e3) { j = null; }
          if (r.status === 404 && (!j || (!j.message && !j.error))) { say('This button is not in the server’s route table yet. Clear the route cache (Platform → Cache) and reload.'); break; }
          if (!r.ok || !j || !j.ok) { say((j && (j.error || j.message)) || 'Could not make the pictures.'); break; }
          if (j.total !== null && j.total !== undefined) total = j.total;
          made += j.made; fresh += j.fresh; skipped += j.skipped;
          seen = made + fresh + skipped; after = j.after;
          say((j.done ? 'Done: ' : 'Working… ') + made + ' made, ' + fresh + ' already up to date, ' + skipped + ' skipped' + (total ? ' — ' + seen + ' of ' + total : '') + '.');
          if (j.done) break;
        }
      } catch (err) {
        say('Stopped — check your connection, then press the button again; pictures already made are passed over quickly.');
      }
      t.disabled = false;
      return;
    }
    if (t.id === 'ptsReset') {
      fields().forEach(function (f) { f.value = f.default; });
      markDirty(); window.paintProductPage(); paintFrames(); return;
    }
    if (t.id === 'ptsDiscard') {
      DIRTY = false; renderProductPage(); return;
    }

    if (t.id !== 'ppSave' || !DIRTY) return;

    /* ANY of the screen's own halves — sections, layout, and whatever a later
       lane adds (Lane PS added `also`) — means its handler must still run. */
    var theirs = typeof PPDIRTY !== 'undefined' && PPDIRTY && Object.keys(PPDIRTY).some(function (k) { return !!PPDIRTY[k]; });
    if (!theirs) { e.stopPropagation(); e.preventDefault(); }

    var payload = { trust: {} };
    fields().forEach(function (f) { payload.trust[f.key] = f.value; });
    var msg = document.getElementById('ppDirty');

    try {
      var r = await fetch(ppBase(), { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': uToken(), Accept: 'application/json' },
        body: JSON.stringify(payload) });
      var j = null;
      try { j = await r.json(); } catch (e2) { j = null; }
      /* A route-cache 404 answers a JSON body that says nothing; a controller's
         own refusal says something. Same discriminator as the media picker. */
      if (r.status === 404 && (!j || (!j.message && !j.error))) {
        if (msg) { msg.style.visibility = 'visible'; msg.textContent = 'The Product page endpoint is not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'; }
        return;
      }
      if (j && j.ok) {
        if (Array.isArray(j.trust)) PP.trust = j.trust;
        DIRTY = false;
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
