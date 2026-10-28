/* Lane GBX -- PREVIEW ONLY, never shipped. Injected into the preview shop by
   tools/gbx-shots.cjs (addInitScript), so the owner can pick:
     - a thumbnail popup design  A / B / C / D   (html[data-gbx-pop])
     - a Gifts Criteria design   1 / 2 / 3       (html[data-gbx-crit])
     - a photo glow              G1 / G2 / G3 / G4 (html[data-gbx-glow])
   Everything it needs arrives inline as window.GBX_DATA (no request). No
   layout-measuring call anywhere: the C card's pointer is placed with calc()
   from the photo's index, never from a measured box. Popups are <dialog>
   (showModal: Escape, inert page, focus kept inside) and give focus back to
   the photo or button that opened them. RTL follows <html dir>. */
(function () {
  var D = window.GBX_DATA || {}, opt = function () { return window.GBX_OPT || {}; };
  var ar, L, T;
  // The language: GBX_LANG when the live preview page sets it, else the shop's /ar/ path.
  function setLang() {
  ar = window.GBX_LANG ? window.GBX_LANG === 'ar' : (location.pathname.indexOf('/ar/') === 0 || location.pathname === '/ar');
  L = ar ? D.ar || {} : D.en || {};
  T = ar
    ? { crit: 'شروط الهدايا', close: 'إغلاق', free: 'مجاناً', freeWith: 'مجاناً مع طلبك', yours: 'سلتك الآن', value: 'القيمة', spend: 'كلما تسوّقت أكثر، حصلت على هدايا أكثر', gifts: 'الهدايا' }
    : { crit: 'Gifts Criteria', close: 'Close', free: 'FREE', freeWith: 'FREE with your order', yours: 'Your basket', value: 'Value', spend: 'The more you shop, the more you get.', gifts: 'Gifts' };
  }
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var money = function (f) { var n = (f / 100).toFixed(2).replace(/\.00$/, ''); return ar ? n + ' د.إ' : 'AED ' + n; };
  var X = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  var GIFT = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>';

  function img(g, w, cls) {
    return '<img class="' + (cls || '') + '" src="' + esc(g.img) + '"' + (g.srcset ? ' srcset="' + esc(g.srcset) + '" sizes="' + w + 'px"' : '') + ' alt="" width="' + w + '" height="' + w + '" decoding="async">';
  }
  function prices(g, mode) {
    var sale = g.now < g.reg;
    if (mode === 'free') return '<p class="gbx-pr"><span class="gbx-vl">' + T.value + '</span> <s>' + money(g.reg) + '</s> <b class="gbx-fr">' + T.free + '</b></p>';
    return '<p class="gbx-pr">' + (sale ? '<s>' + money(g.reg) + '</s> ' : '') + '<b>' + money(g.now) + '</b></p>';
  }

  /* ── one <dialog>, reused ─────────────────────────────────────────────── */
  var dlg, opener;
  function dialog(cls, html, label) {
    if (!dlg) {
      dlg = document.createElement('dialog');
      dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
      // Escape closes the popup only -- never the cart panel underneath it.
      dlg.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); dlg.close(); } });
      dlg.addEventListener('close', function () { if (opener) { opener.focus(); opener = null; } });
      document.body.appendChild(dlg);
    }
    dlg.className = 'gbx-d ' + cls;
    dlg.setAttribute('aria-label', label);
    dlg.innerHTML = html;
    dlg.querySelector('.gbx-x').addEventListener('click', function () { dlg.close(); });
    if (!dlg.open) dlg.showModal();
    dlg.querySelector('.gbx-x').focus();
  }

  /* ── THE GIFT POPUP ───────────────────────────────────────────────────── */
  function gift(name, tile, idx) {
    setLang();
    var g = (L.gifts || {})[name];
    if (!g) return;
    opener = tile;
    var d = opt().pop || 'A';
    if (d === 'C') return inline(g, tile, idx);
    var x = '<button type="button" class="gbx-x" aria-label="' + T.close + '">' + X + '</button>';
    var body;
    if (d === 'A') {
      body = '<div class="gbx-a">' + img(g, 112, 'gbx-im') + '<div class="gbx-tx"><small>' + esc(g.brand) + '</small><h3>' + esc(g.name) + '</h3>' + prices(g) + '<span class="gbx-tag">' + GIFT + T.freeWith + '</span></div></div>';
    } else if (d === 'B') {
      body = '<figure class="gbx-b-ph">' + img(g, 320, 'gbx-im') + '<span class="gbx-badge">' + T.free + '</span></figure><div class="gbx-b-tx"><small>' + esc(g.brand) + '</small><h3>' + esc(g.name) + '</h3>' + prices(g) + '<p class="gbx-note">' + GIFT + T.freeWith + '</p></div>';
    } else {
      body = '<div class="gbx-dd">' + img(g, 220, 'gbx-im') + '<small>' + esc(g.brand) + '</small><h3>' + esc(g.name) + '</h3>' + prices(g, 'free') + '<p class="gbx-dl">' + T.freeWith + '</p></div>';
    }
    dialog('gbx-pop gbx-p' + d, x + body, g.name);
  }

  /* C: a card inside the box, under the photo row, its pointer at the photo
     that was tapped (calc from the index -- nothing is measured). */
  function inline(g, tile, idx) {
    var box = tile.closest('.fgb'), th = box.querySelector('.fgb-th'), old = box.querySelector('.gbx-c');
    if (old) { var same = old.getAttribute('data-i') === String(idx); old.remove(); if (same) { tile.focus(); return; } }
    var cols = box.classList.contains('fgb-g5') ? 5 : box.classList.contains('fgb-g4') ? 4 : 0;
    var c = document.createElement('div');
    c.className = 'gbx-c';
    c.setAttribute('role', 'dialog');
    c.setAttribute('aria-label', g.name);
    c.setAttribute('data-i', idx);
    c.style.setProperty('--gi', cols ? idx % cols : idx);
    c.style.setProperty('--gc', cols || 0);
    c.innerHTML = '<span class="gbx-c-ar" aria-hidden="true"></span>' + img(g, 64, 'gbx-im') + '<div class="gbx-tx"><small>' + esc(g.brand) + '</small><b class="gbx-nm">' + esc(g.name) + '</b>' + prices(g) + '</div><span class="gbx-c-fr">' + T.free + '</span><button type="button" class="gbx-x" aria-label="' + T.close + '">' + X + '</button>';
    th.insertAdjacentElement('afterend', c);
    var close = function () { c.remove(); tile.focus(); };
    c.querySelector('.gbx-x').addEventListener('click', close);
    c.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } });
    c.querySelector('.gbx-x').focus();
  }

  /* ── THE GIFTS CRITERIA POPUP ─────────────────────────────────────────── */
  function criteria(btn) {
    setLang();
    opener = btn;
    var rows = L.crit || [], d = opt().crit || '1';
    var words = (btn.closest('.fgb').querySelector('.fgb-w') || {}).textContent || '';
    var cur = -1;
    rows.forEach(function (r, i) { if (words.indexOf(r.words) === 0) cur = i; });
    var head = '<header class="gbx-ch"><h3>' + GIFT + T.crit + '</h3><p>' + T.spend + '</p><button type="button" class="gbx-x" aria-label="' + T.close + '">' + X + '</button></header>';
    var thumbs = function (r, n) {
      return r.thumbs.slice(0, n).map(function (t) { return '<span class="gbx-t">' + img(t, 40) + (t.q > 1 ? '<b>×' + t.q + '</b>' : '') + '</span>'; }).join('')
        + (r.thumbs.length > n ? '<span class="gbx-more">+' + (r.thumbs.length - n) + '</span>' : '');
    };
    var body = '';
    if (d === '1') {
      body = '<ol class="gbx-1">' + rows.map(function (r, i) {
        return '<li class="' + (i === cur ? 'on' : '') + '"><div class="gbx-1h"><span class="gbx-am">' + esc(r.amount) + '</span>' + (i === cur ? '<span class="gbx-you">' + T.yours + '</span>' : '<span class="gbx-cn">' + esc(r.count) + '</span>') + '</div><p class="gbx-wd">' + esc(r.words) + '</p><div class="gbx-th">' + thumbs(r, 6) + '</div></li>';
      }).join('') + '</ol>';
    } else if (d === '2') {
      body = '<ol class="gbx-2">' + rows.map(function (r, i) {
        return '<li class="' + (i === cur ? 'on' : (i < cur ? 'done' : '')) + '"><span class="gbx-dot" aria-hidden="true">' + (i + 1) + '</span><span class="gbx-am">' + esc(r.amount) + '</span><span class="gbx-wd">' + esc(r.words) + (i === cur ? ' <em>' + T.yours + '</em>' : '') + '</span><span class="gbx-cn">' + esc(r.count) + '</span></li>';
      }).join('') + '</ol>';
    } else {
      var sel = cur < 0 ? 0 : cur;
      body = '<div class="gbx-3"><div class="gbx-tabs" role="tablist">' + rows.map(function (r, i) {
        return '<button type="button" role="tab" id="gbx-tab' + i + '" aria-controls="gbx-tp" aria-selected="' + (i === sel) + '" tabindex="' + (i === sel ? 0 : -1) + '">' + esc(r.amount) + (i === cur ? '<i>' + T.yours + '</i>' : '') + '</button>';
      }).join('') + '</div><div class="gbx-tp" id="gbx-tp" role="tabpanel"></div></div>';
    }
    dialog('gbx-crit gbx-c' + d, head + '<div class="gbx-cb">' + body + '</div>', T.crit);
    if (d === '3') {
      var tabs = [].slice.call(dlg.querySelectorAll('[role=tab]')), tp = dlg.querySelector('.gbx-tp');
      var show = function (i, focus) {
        tabs.forEach(function (b, j) { b.setAttribute('aria-selected', j === i); b.tabIndex = j === i ? 0 : -1; });
        var r = rows[i];
        tp.setAttribute('aria-labelledby', 'gbx-tab' + i);
        tp.innerHTML = '<p class="gbx-wd">' + esc(r.words) + ' <span class="gbx-cn">· ' + esc(r.count) + '</span></p><div class="gbx-grid">' + r.thumbs.map(function (t) {
          return '<div class="gbx-gc"><span class="gbx-t">' + img(t, 72) + (t.q > 1 ? '<b>×' + t.q + '</b>' : '') + '</span><span class="gbx-gn">' + esc(t.name) + '</span></div>';
        }).join('') + '</div>';
        if (focus) tabs[i].focus();
      };
      tabs.forEach(function (b, i) {
        b.addEventListener('click', function () { show(i); });
        b.addEventListener('keydown', function (e) {
          var k = e.key, n = tabs.length, rtl = document.documentElement.dir === 'rtl';
          if (k === 'ArrowRight' || k === 'ArrowLeft') { e.preventDefault(); show((i + ((k === 'ArrowRight') !== rtl ? 1 : -1) + n) % n, true); }
        });
      });
      show(sel);
    }
  }

  /* ── wiring the real box: photos become buttons, the new button sits on
     the right of View all (and of Hide, so it is there with the list open). */
  function wire() {
    setLang();
    [].forEach.call(document.querySelectorAll('.fgb'), function (box) {
      if (box.getAttribute('data-gbx')) return;
      box.setAttribute('data-gbx', '1');
      [].forEach.call(box.querySelectorAll('.fgb-th .fgb-t'), function (t, i) {
        var im = t.querySelector('img'), name = im ? im.getAttribute('alt') : '';
        t.setAttribute('role', 'button');
        t.setAttribute('tabindex', '0');
        t.setAttribute('aria-haspopup', 'dialog');
        t.setAttribute('aria-label', name);
        t.addEventListener('click', function () { gift(name, t, i); });
        t.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); gift(name, t, i); } });
      });
      [].forEach.call(box.querySelectorAll('.fgb-tg'), function (tg) {
        var ft = document.createElement('div'); ft.className = 'gbx-ft';
        tg.parentNode.insertBefore(ft, tg); ft.appendChild(tg);
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'gbx-cr'; b.setAttribute('aria-haspopup', 'dialog');
        b.innerHTML = GIFT + '<span>' + T.crit + '</span>';
        b.addEventListener('click', function () { criteria(b); });
        ft.appendChild(b);
      });
    });
  }
  window.GBX = { wire: wire, gift: gift, criteria: criteria };
  if (window.GBX_DATA) {
    var go = function () { wire(); new MutationObserver(wire).observe(document.body, { childList: true, subtree: true }); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
  }
})();
