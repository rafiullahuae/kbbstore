{{--
    Appearance → Mobile menu → Quick links, and V4 in the live phone. (Lane M4)

    The owner picked V4 "Two-tone" (docs/mv-preview) and asked for "full
    control of font sizes, row height, paddings, upper custom links, panel
    size, on click sub menu opening animation". The sizes and the animation
    are ordinary schema fields (MobileMenu::SCHEMA, the Style, Sizes and
    Sub-menu animation cards), which the screen in admin/app.blade.php draws
    by itself. The one thing it cannot draw is a LIST, so this file adds:

      * the Quick links card — up to eight links in the pink top band: label,
        Arabic label, link (typed, or picked from the shop's categories,
        brands, pages and listings), colour from a fixed palette, on/off,
        reorder by dragging or by the arrows, add and remove;
      * V4's band and quick links in the live phone preview.

    HOW IT JOINS THE SCREEN: it wraps paintMobileMenu() and mmPreview(), once
    (the flag below), and keeps the links as one more entry in MM.fields, so
    the screen's own Save posts them as `settings.chips` and its Reset puts
    the five defaults back. The server checks every link again
    (MobileMenu::cleanChips: a path on this shop or https only) and refuses
    the save by name rather than dropping a link quietly.

    Light by construction: no request of its own except the picker's list,
    fetched once and only when "Pick" is first pressed; no timer, nothing
    measured. Nothing here may name Blade's raw-block directives.
--}}
@verbatim
<style>
.m4c-list{display:grid;gap:8px;margin:2px 0 10px}
.m4c-row{display:grid;grid-template-columns:22px 38px minmax(0,1fr) minmax(0,1fr) auto;gap:8px;align-items:center;padding:9px;border:1px solid #e6ebf1;border-radius:10px;background:#fff}
.m4c-row.off{opacity:.55}
.m4c-row.drag{opacity:.4}
.m4c-row.over{border-color:#E0567B;box-shadow:0 0 0 2px rgba(224,86,123,.15)}
.m4c-grip{cursor:grab;color:#9aa3b0;font-size:15px;line-height:1;text-align:center;user-select:none}
.m4c-row input[type=text],.m4c-row select{width:100%;min-width:0;border:1px solid #dfe5ec;border-radius:8px;padding:7px 9px;font:400 13px inherit}
.m4c-row .m4c-ar{direction:rtl}
.m4c-link{grid-column:3/5;display:flex;gap:6px;min-width:0}
.m4c-link input{flex:1}
.m4c-tools{grid-column:5;grid-row:1/3;display:flex;flex-direction:column;gap:4px}
.m4c-tools button,.m4c-pick{border:1px solid #dfe5ec;background:#f7f9fb;border-radius:7px;min-width:30px;height:30px;cursor:pointer;font:600 12px inherit;color:#3a4553}
.m4c-tools button:disabled{opacity:.35;cursor:default}
.m4c-acc{grid-column:3/5;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.m4c-acc label{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#5b6675;cursor:pointer}
.m4c-dot{width:16px;height:16px;border-radius:50%;border:1px solid #d5dbe3;display:inline-block}
.m4c-bad{border-color:#d93848!important;background:#fff6f6}
.m4c-msg{font-size:12px;color:#d93848;grid-column:3/5}
.m4c-foot{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.m4c-foot span{font-size:12px;color:#7b8697}
@media(max-width:640px){
  .m4c-row{grid-template-columns:22px 38px minmax(0,1fr) auto}
  .m4c-row .m4c-ar{grid-column:3}
  .m4c-row .m4c-en{grid-column:3}
  .m4c-link,.m4c-acc,.m4c-msg{grid-column:1/4}
  .m4c-tools{grid-column:4;grid-row:1/4}
}
/* The live phone, V4: the band and the quick links, at the preview's scale. */
.pvmenu.pv4 .mm-srch{background:rgba(253,229,238,.94);padding-top:8px}
.pvmenu.pv4 .pv4-chips{display:flex;gap:var(--pv-cg,4px);overflow:hidden;padding:2px 10px 8px;background:rgba(253,229,238,.94);border-radius:0 0 14px 14px;box-shadow:0 8px 14px -12px rgba(193,62,99,.6);flex:none;
  -webkit-mask-image:linear-gradient(90deg,#000 calc(100% - 22px),transparent);mask-image:linear-gradient(90deg,#000 calc(100% - 22px),transparent)}
.pvmenu.pv4 .pv4-chip{flex:none;display:inline-flex;align-items:center;height:var(--pv-ch,23px);padding:0 9px;border-radius:var(--pv-cr,99px);background:#fff;border:1px solid rgba(224,86,123,.22);color:#3A2E35;font-size:var(--pv-cf,9px);font-weight:600;white-space:nowrap}
.pvmenu.pv4 .pv4-chip.a-sale{background:#B8163B;border-color:#B8163B;color:#fff}
.pvmenu.pv4 .pv4-chip.a-pink{background:#B83560;border-color:#B83560;color:#fff}
.pvmenu.pv4 .pv4-chip.a-gold{background:#FFF1D6;border-color:#E9C98A;color:#7A4B00}
.pvmenu.pv4 .pv4-chip.a-green{background:#E6F5EC;border-color:#9BD3B2;color:#155E36}
.pvmenu.pv4 .pv4-chip.a-ink{background:#2A2228;border-color:#2A2228;color:#fff}
.pvmenu.pv4 .mm-it{min-height:var(--pv-rh,32px);font-size:var(--pv-rf,10px)}
.pvmenu.pv4 .mm-si{font-size:var(--pv-qf,9px);min-height:var(--pv-qh,23px)}
.pvmenu.pv4 .mm-it.hot{background:#C73345;color:#fff}
.pvmenu.pv4.pv4-nohl .mm-it.hot{background:none;color:#B0182F}
</style>
<script>
(function () {
  if (window.__kbbM4Chips) return;   // one wrap of each, however often this is included
  window.__kbbM4Chips = 1;

  var ACC = { '': '#ffffff', sale: '#B8163B', pink: '#B83560', gold: '#FFF1D6', green: '#E6F5EC', ink: '#2A2228' };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  // The server's rule, MenuTargets::customUrlAllowed(), said again so a bad link is marked before Save.
  var okUrl = function (u) { u = String(u || '').trim(); return u.length > 0 && u.length <= 255 && !/[\s\\\x00-\x1f\x7f]/.test(u) && (/^\/(?!\/)/.test(u) || /^https:\/\/[^/]+/i.test(u)); };
  var copy = function (list) { return (list || []).map(function (c) { return Object.assign({}, c); }); };

  function field() {
    if (!MM || !MM.chips) return null;
    var f = MM.fields.find(function (x) { return x.key === 'chips'; });
    if (!f) {
      // Not in any group, so the screen's own renderer never draws it; its Save and Reset still carry it.
      f = { key: 'chips', type: 'chips', label: 'Quick links', value: copy(MM.chips.value), default: copy(MM.chips.default) };
      MM.fields.push(f);
    }
    return f;
  }
  function set(list) { var f = field(); f.value = list; }
  function list() { var f = field(); return f ? copy(f.value) : []; }
  function dirty() { if (typeof mmPreview === 'function') mmPreview(); if (typeof mmDirty === 'function') mmDirty(); }

  function rowHtml(c, i, n) {
    var bad = !okUrl(c.url);
    return '<div class="m4c-row' + (c.on ? '' : ' off') + '" data-m4i="' + i + '">'
      + '<span class="m4c-grip" draggable="true" title="Drag to reorder" aria-hidden="true">⋮⋮</span>'
      + '<span class="ectog' + (c.on ? ' on' : '') + '" data-m4="on" role="switch" aria-checked="' + !!c.on + '" tabindex="0" title="Show this link"></span>'
      + '<input type="text" class="m4c-en" data-m4="label" maxlength="' + MM.chips.label_max + '" value="' + esc(c.label) + '" placeholder="Label" aria-label="Label">'
      + '<input type="text" class="m4c-ar" data-m4="label_ar" maxlength="' + MM.chips.label_max + '" value="' + esc(c.label_ar) + '" placeholder="بالعربية" aria-label="Arabic label" lang="ar">'
      + '<span class="m4c-tools"><button type="button" data-m4="up" aria-label="Move up"' + (i ? '' : ' disabled') + '>↑</button>'
      + '<button type="button" data-m4="down" aria-label="Move down"' + (i < n - 1 ? '' : ' disabled') + '>↓</button>'
      + '<button type="button" data-m4="del" aria-label="Remove">✕</button></span>'
      + '<span class="m4c-link"><input type="text" data-m4="url" value="' + esc(c.url) + '" placeholder="/new-in/ or https://…" aria-label="Link"' + (bad ? ' class="m4c-bad"' : '') + '>'
      + '<button type="button" class="m4c-pick" data-m4="pick">Pick…</button></span>'
      + '<span class="m4c-acc">' + Object.keys(MM.chips.accents).map(function (k) {
          return '<label><input type="radio" name="m4acc' + i + '" data-m4="accent" value="' + esc(k) + '"' + (k === (c.accent || '') ? ' checked' : '') + '>'
            + '<i class="m4c-dot" style="background:' + ACC[k] + '"></i>' + esc(MM.chips.accents[k]) + '</label>';
        }).join('') + '</span>'
      + (bad ? '<span class="m4c-msg">A path on this shop, like /new-in/, or an https:// address.</span>' : '')
      + '</div>';
  }

  function card() {
    var f = field(); if (!f) return;
    var host = document.getElementById('m4Chips');
    if (!host) {
      var cards = document.querySelectorAll('.mmcols > .mmcard');
      var at = MM.groups.findIndex(function (g) { return g.key === 'style'; });
      var el = document.createElement('div');
      el.className = 'card mmcard';
      el.innerHTML = '<div class="mmhd"><b>Quick links</b><span>The links in the pink band under the search field (V4 only). Up to '
        + MM.chips.max + '. Drag ⋮⋮ or use the arrows to reorder.</span></div><div class="mmbody" id="m4Chips"></div>';
      var after = cards[at] || cards[cards.length - 1];
      if (after) after.after(el); else return;
      host = document.getElementById('m4Chips');
    }
    var l = f.value || [];
    host.innerHTML = '<div class="m4c-list">' + l.map(function (c, i) { return rowHtml(c, i, l.length); }).join('') + '</div>'
      + '<div class="m4c-foot"><button type="button" class="btn small" data-m4="add"' + (l.length >= MM.chips.max ? ' disabled' : '') + '>+ Add a link</button>'
      + '<span>' + l.length + ' of ' + MM.chips.max + ' · saved with Save changes</span></div>';
  }

  var paint = window.paintMobileMenu;
  window.paintMobileMenu = function () { field(); paint.apply(this, arguments); card(); };

  var preview = window.mmPreview;
  window.mmPreview = function () {
    preview.apply(this, arguments);
    var menu = document.querySelector('#mmPhone .pvmenu');
    if (!menu || mmVal('menu_style') !== 'v4') return;
    var s = 270 / 390 * 1.05;                  // the preview phone is 270px wide
    var px = function (k) { return (mmVal(k) * s).toFixed(1) + 'px'; };
    menu.classList.add('pv4');
    menu.classList.toggle('pv4-nohl', !mmVal('sale_fill'));
    menu.style.setProperty('--pv-rh', px('row_h')); menu.style.setProperty('--pv-rf', px('row_fs'));
    menu.style.setProperty('--pv-qf', px('sub_fs')); menu.style.setProperty('--pv-qh', px('sub_h'));
    menu.style.setProperty('--pv-ch', px('chip_h')); menu.style.setProperty('--pv-cf', px('chip_fs'));
    menu.style.setProperty('--pv-cg', px('chip_gap'));
    menu.style.setProperty('--pv-cr', mmVal('chip_radius') >= 24 ? '99px' : px('chip_radius'));
    var on = (field() || { value: [] }).value.filter(function (c) { return c.on; });
    if (!mmVal('show_chips') || !on.length) return;
    var row = document.createElement('div');
    row.className = 'pv4-chips';
    row.innerHTML = on.map(function (c) { return '<span class="pv4-chip' + (c.accent ? ' a-' + esc(c.accent) : '') + '">' + esc(c.label) + '</span>'; }).join('');
    var srch = menu.querySelector('.mm-srch');
    menu.insertBefore(row, srch ? srch.nextSibling : menu.querySelector('.mm-body'));
  };

  function at(e) { var r = e.target.closest('.m4c-row'); return r ? +r.dataset.m4i : -1; }

  document.addEventListener('input', function (e) {
    var k = e.target.dataset && e.target.dataset.m4;
    if (!MM || !k || !e.target.closest('#m4Chips')) return;
    if (k !== 'label' && k !== 'label_ar' && k !== 'url') return;
    var l = list(), i = at(e); if (!l[i]) return;
    l[i][k] = e.target.value; set(l);
    if (k === 'url') e.target.classList.toggle('m4c-bad', !okUrl(e.target.value));
    dirty();
  });

  document.addEventListener('change', function (e) {
    if (!MM || !e.target.closest('#m4Chips')) return;
    var l = list(), i = at(e);
    if (e.target.dataset.m4 === 'accent' && l[i]) { l[i].accent = e.target.value; set(l); dirty(); }
    if (e.target.dataset.m4 === 'pickto' && l[i] && e.target.value) {
      var o = e.target.selectedOptions[0];
      l[i].url = e.target.value;
      if (!String(l[i].label || '').trim()) l[i].label = o.textContent;
      set(l); card(); dirty();
    }
  });

  var sources = null;
  function pick(btn, i) {
    var holder = btn.parentNode;
    if (holder.querySelector('select')) return;
    if (!sources) {
      btn.textContent = '…';
      sources = fetch(mmBase().replace(/mobile-menu$/, 'mega-menu/sources'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          if (r.ok) return r.json();
          return r.json().catch(function () { return null; }).then(function (b) { return { failed: r.status, body: b }; });
        })
        .catch(function () { return { failed: 0 }; });
    }
    sources.then(function (j) {
      btn.textContent = 'Pick…';
      if (!j || !j.groups) {
        var f = (j && j.failed) || 0;
        sources = null;   // asked again on the next press
        btn.title = f === 404 && !(j.body && j.body.error)
          // The picker's endpoint missing from the compiled route table: the remedy is Platform → Cache.
          ? 'The list of shop links is not in this server\'s compiled route table yet. Clear the route cache (Platform → Cache) and reload — or type the link.'
          : f === 403 ? 'Your account cannot read the shop\'s link list — type the link instead.'
          : 'The list could not be loaded — type the link instead.';
        if (typeof window.toast === 'function') window.toast(btn.title);
        return;
      }
      var g = j.groups, groups = [['Listings', g.collections], ['Categories', g.categories], ['Brands', g.brands], ['Pages', g.pages]];
      var sel = document.createElement('select');
      sel.dataset.m4 = 'pickto';
      sel.setAttribute('aria-label', 'Pick a link');
      sel.innerHTML = '<option value="">Choose…</option>' + groups.map(function (p) {
        return (p[1] && p[1].length) ? '<optgroup label="' + esc(p[0]) + '">' + p[1].map(function (t) { return '<option value="' + esc(t.url) + '">' + esc(t.name) + '</option>'; }).join('') + '</optgroup>' : '';
      }).join('');
      holder.appendChild(sel);
      sel.focus();
    });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-m4]');
    if (!MM || !b || !b.closest('#m4Chips')) return;
    var k = b.dataset.m4, l = list(), i = at(e);
    if (k === 'add') {
      if (l.length >= MM.chips.max) return;
      l.push({ label: '', label_ar: '', url: '/', accent: '', on: true }); set(l); card(); dirty();
      var last = document.querySelectorAll('#m4Chips .m4c-en'); if (last.length) last[last.length - 1].focus();
      return;
    }
    if (k === 'pick') { pick(b, i); return; }
    if (!l[i]) return;
    if (k === 'on') l[i].on = !l[i].on;
    else if (k === 'del') l.splice(i, 1);
    else if (k === 'up' && i > 0) l.splice(i - 1, 0, l.splice(i, 1)[0]);
    else if (k === 'down' && i < l.length - 1) l.splice(i + 1, 0, l.splice(i, 1)[0]);
    else return;
    set(l); card(); dirty();
  });
  document.addEventListener('keydown', function (e) {
    if ((e.key === ' ' || e.key === 'Enter') && e.target.matches && e.target.matches('#m4Chips .ectog')) { e.preventDefault(); e.target.click(); }
  });

  // Drag to reorder by the grip (a mouse; on a phone the arrows do it), so
  // text in the boxes can still be selected.
  var from = -1;
  document.addEventListener('dragstart', function (e) {
    var g = e.target.closest && e.target.closest('#m4Chips .m4c-grip'); if (!g) return;
    var r = g.closest('.m4c-row');
    from = +r.dataset.m4i; r.classList.add('drag'); e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', String(from)); } catch (_) { /* old browsers */ }
  });
  document.addEventListener('dragover', function (e) {
    var r = from >= 0 && e.target.closest && e.target.closest('#m4Chips .m4c-row'); if (!r) return;
    e.preventDefault();
    document.querySelectorAll('#m4Chips .over').forEach(function (x) { if (x !== r) x.classList.remove('over'); });
    r.classList.add('over');
  });
  document.addEventListener('drop', function (e) {
    var r = from >= 0 && e.target.closest && e.target.closest('#m4Chips .m4c-row'); if (!r) return;
    e.preventDefault();
    var to = +r.dataset.m4i, l = list();
    if (to !== from && l[from]) { l.splice(to, 0, l.splice(from, 1)[0]); set(l); dirty(); }
    from = -1; card();
  });
  document.addEventListener('dragend', function () { if (from >= 0) { from = -1; card(); } });
})();
</script>
@endverbatim
