{{--
    The Shop by Concern tab editor (Lane SBC).

    Appearance → Homepage content → Shop by Concern → "Concern tabs, in order",
    and the same field in All sections → Shop by Concern → Edit content. ONE
    editor for both screens, the concern-chips-editor's shape: each screen's
    field renderer hands it the field, the value and the attribute its own
    save path listens on; this draws the rows around a hidden input carrying
    that attribute, and every change rewrites the input and fires `input`, so
    each screen stores the value exactly as it stores any other field.

    The value is ShopByConcern's JSON list; the server cleans it again on save
    and on every read (ShopByConcern::rows()), so nothing typed here reaches the
    shop unchecked. Icons, sources and sorts are ShopByConcern's constants,
    printed once as JSON. Every string drawn goes through esc().

    REQUESTS: none, except the manual pick's product search, which is the
    homepage hub's own typeahead (GET admin-api/homepage-hub/products, its own
    capability and throttle), 300ms debounced and 2 characters minimum. Photos
    come from the shop's Media Library (window.kbbPickMedia). No timer runs.
--}}
<script>window.kbbSbcVocab = @json(\App\Support\ShopByConcern::editorVocab(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);</script>
@verbatim
<style>
.ksb{display:grid;gap:8px;min-width:0;width:100%}
.ksb *{box-sizing:border-box}
.ksb-list{list-style:none;margin:0;padding:0;display:grid;gap:8px;min-width:0}
.ksb-row{border:1px solid var(--border,#e6e6e6);border-radius:12px;background:var(--surface,#fff);min-width:0}
.ksb-row.is-off{opacity:.7}
.ksb-top{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px 10px}
.ksb-pv{display:inline-flex;align-items:center;gap:8px;min-width:0;padding:4px 12px 4px 4px;border-radius:2px;border:1px solid #E3DADD;font-size:13px;font-weight:600;color:#2A2228;max-width:100%}
.ksb-pv b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.ksb-ic{display:grid;place-items:center;flex:0 0 28px;width:28px;height:28px;border-radius:50%;background:#FFF0F4;color:#C13E63}
.ksb-ic svg{width:18px;height:18px}
.ksb-ic .f{fill:transparent}
.ksb-act{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}
.ksb-btn{font:inherit;font-size:12.5px;font-weight:600;padding:5px 10px;border:1px solid var(--border,#e6e6e6);border-radius:8px;background:transparent;color:inherit;cursor:pointer;min-width:30px}
.ksb-btn[disabled]{opacity:.4;cursor:default}
.ksb-btn.is-on{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.ksb-det{border-top:1px solid var(--border,#e6e6e6)}
.ksb-det>summary{cursor:pointer;padding:8px 10px;font-size:12.5px;font-weight:600;color:var(--ink-soft,#6b7280)}
.ksb-f{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:8px;padding:0 10px 10px;min-width:0}
.ksb-f label,.ksb-w{display:grid;gap:3px;font-size:11.5px;font-weight:600;color:var(--ink-soft,#6b7280);min-width:0}
.ksb-wide{grid-column:1 / -1}
.ksb-f select,.ksb-f input,.ksb-f textarea{width:100%;min-width:0;padding:7px 9px;font:inherit;font-size:13px;font-weight:400;border:1px solid var(--border,#e6e6e6);border-radius:8px;background:var(--surface,#fff);color:var(--ink,#111)}
.ksb-f textarea{min-height:76px;resize:vertical;line-height:1.45}
.ksb-btn:focus-visible,.ksb-f select:focus-visible,.ksb-f input:focus-visible,.ksb-f textarea:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:1px}
.ksb-img{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ksb-img img{width:72px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--border,#e6e6e6)}
.ksb-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.ksb-chip{display:inline-flex;gap:4px;align-items:center;border:1px solid var(--border,#e6e6e6);border-radius:99px;padding:2px 4px 2px 10px;font-size:12px;font-weight:600;color:var(--ink,#111);max-width:100%}
.ksb-chip b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;font-weight:600}
.ksb-res{list-style:none;margin:4px 0 0;padding:0;display:grid;gap:2px;max-height:220px;overflow:auto;border:1px solid var(--border,#e6e6e6);border-radius:8px}
.ksb-res:empty{display:none}
.ksb-res button{width:100%;text-align:start;font:inherit;font-size:12.5px;padding:6px 9px;border:0;background:transparent;color:inherit;cursor:pointer}
.ksb-res button:hover,.ksb-res button:focus-visible{background:var(--soft,#f3f4f6)}
.ksb-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.ksb-note{font-size:12px;color:var(--ink-soft,#6b7280)}
.ksb-miss{font-size:11.5px;color:#b4443c}
</style>
<script>
(function(){
  'use strict';
  if (window.kbbSbc) return;
  var V = window.kbbSbcVocab || {icons: [], sources: {}, sorts: {}, counts: [], defaults: [], collections: {}, cap: 12};
  var ICON = {};
  V.icons.forEach(function(i){ ICON[i.key] = i; });
  var L = {cats: [], brands: [], products: []};
  var NAMES = {};

  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function byId(list, id){ for (var i = 0; i < list.length; i++) if (+list[i].id === +id) return list[i]; return null; }
  function nameOf(kind, id){ var r = byId(L[kind], id); return r ? r.name : (kind === 'products' && NAMES[id]) ? NAMES[id] : '#' + id; }
  function ids(a){ return (Array.isArray(a) ? a : []).map(function(x){ return +x; }).filter(function(x){ return x > 0; }); }
  function str(v){ return typeof v === 'string' ? v : ''; }

  function clean(r){
    r = r && typeof r === 'object' ? r : {};
    var k = V.collections[r.k] ? r.k : '';
    return {
      k: k, on: r.on !== false, i: ICON[r.i] ? r.i : (k || 'acne'),
      l: str(r.l), la: str(r.la), h: str(r.h), ha: str(r.ha), t: str(r.t), ta: str(r.ta),
      img: str(r.img), imga: str(r.imga), src: V.sources[r.src] ? r.src : 'auto',
      cats: ids(r.cats), brands: ids(r.brands), picks: ids(r.picks),
      sort: V.sorts[r.sort] ? r.sort : 'bestselling', n: +r.n || 0, u: str(r.u)
    };
  }
  /* '' is "never set": the eight shipped tabs. */
  function parse(v){
    var rows;
    if (String(v || '').trim() === '') { rows = V.defaults; }
    else { try { rows = JSON.parse(v); } catch (e) { rows = []; } }
    return (Array.isArray(rows) ? rows : []).map(clean);
  }
  function write(box, rows){
    var hid = box.querySelector('input[type=hidden]');
    hid.value = JSON.stringify(rows);
    hid.dispatchEvent(new Event('input', {bubbles: true}));
  }
  function rowsOf(box){ return parse(box.querySelector('input[type=hidden]').value); }

  function opts(map, cur){
    return Object.keys(map).map(function(k){ return '<option value="' + esc(k) + '"' + (String(k) === String(cur) ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join('');
  }
  function chips(kind, i, list){
    var cap = kind === 'picks' ? 24 : 20, src = kind === 'picks' ? 'products' : kind;
    return '<div class="ksb-chips">' + (list.length ? list.map(function(id, j){
      return '<span class="ksb-chip"><b>' + esc(nameOf(src, id)) + '</b>'
        + '<button type="button" class="ksb-btn" data-ksb-mvid="' + i + '|' + kind + '|' + j + '|-1" aria-label="Move up"' + (j === 0 ? ' disabled' : '') + '>↑</button>'
        + '<button type="button" class="ksb-btn" data-ksb-xid="' + i + '|' + kind + '|' + j + '" aria-label="Remove ' + esc(nameOf(src, id)) + '">×</button></span>';
    }).join('') : '<span class="ksb-note">None chosen yet.</span>')
    + (kind !== 'picks' && list.length < cap ? '<select data-ksb-addid="' + i + '|' + kind + '" aria-label="Add"><option value="">+ Add…</option>'
        + L[src].filter(function(r){ return list.indexOf(+r.id) < 0; }).map(function(r){ return '<option value="' + (+r.id) + '">' + esc(r.name) + '</option>'; }).join('') + '</select>' : '')
    + '</div>';
  }
  function photo(i, f, v, label){
    return '<div class="ksb-w"><span>' + esc(label) + '</span><div class="ksb-img">'
      + (v ? '<img src="' + esc(v) + '" alt="">' : '<span class="ksb-note">None — the concern’s colour and two product photos.</span>')
      + '<button type="button" class="ksb-btn" data-ksb-media="' + i + '|' + f + '">' + (v ? 'Change' : 'Choose from Media Library') + '</button>'
      + (v ? '<button type="button" class="ksb-btn" data-ksb-mediax="' + i + '|' + f + '">Remove</button>' : '') + '</div></div>';
  }
  function collectionNote(r){
    if (r.src !== 'auto') return '';
    if (!r.k) return '<span class="ksb-miss">A tab you added has no collection of its own — choose categories, brands or products.</span>';
    var slugs = V.collections[r.k] || [];
    for (var j = 0; j < slugs.length; j++) for (var c = 0; c < L.cats.length; c++) if (L.cats[c].slug === slugs[j]) return '<span class="ksb-note">Collection: ' + esc(L.cats[c].name) + '</span>';
    return '<span class="ksb-miss">No collection at /' + esc(slugs[0] || '') + '/ on this shop — this tab is not shown until it has one, or you choose its products.</span>';
  }

  function rowHTML(r, i, n, open){
    var ic = ICON[r.i] || ICON.acne;
    var f = function(key, label, max, dir){ return '<label>' + esc(label) + '<input type="text" maxlength="' + max + '"' + (dir ? ' dir="rtl"' : '') + ' data-ksb-f="' + key + '" data-ksb-i="' + i + '" value="' + esc(r[key]) + '"></label>'; };
    var counts = {'0': 'The section’s number'}; V.counts.forEach(function(c){ counts[c] = c; });
    return '<li class="ksb-row' + (r.on ? '' : ' is-off') + '">'
      + '<div class="ksb-top"><span class="ksb-pv"><span class="ksb-ic">' + ic.svg + '</span><b>' + esc(r.l || r.h || 'Tab ' + (i + 1)) + '</b></span>'
      + '<span class="ksb-act">'
      +   '<button type="button" class="ksb-btn' + (r.on ? ' is-on' : '') + '" data-ksb-on="' + i + '" aria-pressed="' + (r.on ? 'true' : 'false') + '">' + (r.on ? 'Shown' : 'Hidden') + '</button>'
      +   '<button type="button" class="ksb-btn" data-ksb-mv="' + i + '|-1" aria-label="Move tab ' + (i + 1) + ' up"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
      +   '<button type="button" class="ksb-btn" data-ksb-mv="' + i + '|1" aria-label="Move tab ' + (i + 1) + ' down"' + (i === n - 1 ? ' disabled' : '') + '>↓</button>'
      +   '<button type="button" class="ksb-btn" data-ksb-x="' + i + '" aria-label="Remove tab ' + (i + 1) + '">×</button>'
      + '</span></div>'
      + '<details class="ksb-det"' + (open ? ' open' : '') + ' data-ksb-det="' + i + '"><summary>Edit words, photo and products</summary><div class="ksb-f">'
      +   f('l', 'Tab label', 40) + f('la', 'Tab label · Arabic', 40, 1)
      +   f('h', 'Title on the panel', 80) + f('ha', 'Title · Arabic', 80, 1)
      +   '<label class="ksb-wide">Tip<textarea maxlength="400" data-ksb-f="t" data-ksb-i="' + i + '">' + esc(r.t) + '</textarea></label>'
      +   '<label class="ksb-wide">Tip · Arabic<textarea maxlength="400" dir="rtl" data-ksb-f="ta" data-ksb-i="' + i + '">' + esc(r.ta) + '</textarea></label>'
      +   '<label>Icon<select data-ksb-f="i" data-ksb-i="' + i + '">' + V.icons.map(function(o){ return '<option value="' + esc(o.key) + '"' + (o.key === r.i ? ' selected' : '') + '>' + esc(o.label) + '</option>'; }).join('') + '</select></label>'
      +   '<label>Products shown<select data-ksb-f="n" data-ksb-i="' + i + '">' + opts(counts, String(r.n)) + '</select></label>'
      +   photo(i, 'img', r.img, 'Background photo') + photo(i, 'imga', r.imga, 'Background photo · Arabic (empty: the same photo)')
      +   '<label>Products from<select data-ksb-f="src" data-ksb-i="' + i + '">' + opts(V.sources, r.src) + '</select></label>'
      +   (r.src !== 'manual' ? '<label>Order<select data-ksb-f="sort" data-ksb-i="' + i + '">' + opts(V.sorts, r.sort) + '</select></label>' : '')
      +   '<div class="ksb-w ksb-wide">' + collectionNote(r)
      +     (r.src === 'category' ? '<span>Categories (any of these)</span>' + chips('cats', i, r.cats) : '')
      +     (r.src === 'brands' ? '<span>Brands (any of these)</span>' + chips('brands', i, r.brands) : '')
      +     (r.src === 'manual' ? '<span>Products, in order</span>' + chips('picks', i, r.picks)
              + (r.picks.length < 24 ? '<input type="search" placeholder="Search products — 2 letters or more" aria-label="Search products" data-ksb-find="' + i + '"><ul class="ksb-res" data-ksb-res="' + i + '"></ul>' : '') : '')
      +   '</div>'
      +   '<label class="ksb-wide">View all link (empty: the tab’s collection)<input type="text" maxlength="500" placeholder="/collections/… or https://…" data-ksb-f="u" data-ksb-i="' + i + '" value="' + esc(r.u) + '"></label>'
      + '</div></details></li>';
  }
  function openSet(box){
    var s = {};
    box.querySelectorAll('details[data-ksb-det]').forEach(function(d){ if (d.open) s[d.getAttribute('data-ksb-det')] = 1; });
    return s;
  }
  function listHTML(rows, open){
    return rows.length ? rows.map(function(r, i){ return rowHTML(r, i, rows.length, open && open[i]); }).join('')
      : '<li class="ksb-note">No tabs — the section shows nothing. Add one, or restore the eight concerns.</li>';
  }
  function draw(box, open){
    var rows = rowsOf(box);
    box.querySelector('.ksb-list').innerHTML = listHTML(rows, open || openSet(box));
    box.querySelector('[data-ksb-add]').disabled = rows.length >= V.cap;
  }
  function commit(box, rows, open){ write(box, rows); draw(box, open); }

  document.addEventListener('click', function(e){
    var b = e.target.closest && e.target.closest('.ksb button');
    if (!b) return;
    var box = b.closest('.ksb'), rows = rowsOf(box), open = openSet(box), p;
    if (b.hasAttribute('data-ksb-on')) { var r0 = rows[+b.getAttribute('data-ksb-on')]; if (r0) r0.on = !r0.on; }
    else if (b.hasAttribute('data-ksb-mv')) {
      p = b.getAttribute('data-ksb-mv').split('|'); var i = +p[0], j = i + (+p[1]);
      if (j < 0 || j >= rows.length) return;
      var t = rows[i]; rows[i] = rows[j]; rows[j] = t; var oi = open[i]; open[i] = open[j]; open[j] = oi;
    } else if (b.hasAttribute('data-ksb-x')) { rows.splice(+b.getAttribute('data-ksb-x'), 1); open = {}; }
    else if (b.hasAttribute('data-ksb-add')) {
      if (rows.length >= V.cap) return;
      rows.push(clean({k: '', on: true, i: 'acne', src: 'category'})); open = {}; open[rows.length - 1] = 1;
    } else if (b.hasAttribute('data-ksb-mvid') || b.hasAttribute('data-ksb-xid')) {
      p = (b.getAttribute('data-ksb-mvid') || b.getAttribute('data-ksb-xid')).split('|');
      var r = rows[+p[0]], list = r && r[p[1]]; if (!list) return;
      if (b.hasAttribute('data-ksb-xid')) list.splice(+p[2], 1);
      else if (+p[2] > 0) { var x = list[+p[2]]; list[+p[2]] = list[+p[2] - 1]; list[+p[2] - 1] = x; }
    } else if (b.hasAttribute('data-ksb-media')) {
      if (typeof window.kbbPickMedia !== 'function') return;
      p = b.getAttribute('data-ksb-media').split('|');
      window.kbbPickMedia({title: 'Background photo', note: 'The photo behind this tab’s tip. Cover-fit; a soft white wash keeps the words readable.', folder: 'appearance',
        onPick: function(urls){ if (!urls || !urls[0]) return; var rs = rowsOf(box); if (!rs[+p[0]]) return; rs[+p[0]][p[1]] = String(urls[0]); commit(box, rs, open); }});
      return;
    } else if (b.hasAttribute('data-ksb-mediax')) { p = b.getAttribute('data-ksb-mediax').split('|'); if (rows[+p[0]]) rows[+p[0]][p[1]] = ''; }
    else if (b.hasAttribute('data-ksb-pick')) {
      p = b.getAttribute('data-ksb-pick').split('|'); var rp = rows[+p[0]], id = +p[1];
      if (!rp || !id) return; NAMES[id] = b.getAttribute('data-ksb-name') || NAMES[id];
      if (rp.picks.indexOf(id) < 0 && rp.picks.length < 24) rp.picks.push(id);
    } else if (b.hasAttribute('data-ksb-reset')) {
      var put = function(){ commit(box, parse(''), {}); };
      if (window.kbbConfirm) window.kbbConfirm('Put the eight shipped tabs back? Your own tabs, words and photos here will be replaced (nothing is saved until you press Save).').then(function(ok){ if (ok) put(); });
      else put();
      return;
    } else { return; }
    commit(box, rows, open);
  });
  function edit(el, redraw){
    if (!el.closest || !el.closest('.ksb') || !el.hasAttribute('data-ksb-f')) return;
    var box = el.closest('.ksb'), rows = rowsOf(box), r = rows[+el.getAttribute('data-ksb-i')], f = el.getAttribute('data-ksb-f');
    if (!r) return;
    r[f] = f === 'n' ? (+el.value || 0) : el.value;
    write(box, rows);
    if (redraw) draw(box);
  }
  document.addEventListener('change', function(e){
    var el = e.target;
    if (el.tagName === 'SELECT' && el.hasAttribute('data-ksb-addid')) {
      var box = el.closest('.ksb'), rows = rowsOf(box), p = el.getAttribute('data-ksb-addid').split('|'), r = rows[+p[0]];
      if (r && +el.value && r[p[1]].indexOf(+el.value) < 0) { r[p[1]].push(+el.value); commit(box, rows); }
      return;
    }
    if (el.tagName === 'SELECT') edit(el, true);
  });
  var wait = null, seq = 0;
  document.addEventListener('input', function(e){
    var el = e.target;
    if (el.hasAttribute && el.hasAttribute('data-ksb-find')) {
      var i = el.getAttribute('data-ksb-find'), res = el.parentNode.querySelector('[data-ksb-res="' + i + '"]'), term = el.value.trim();
      clearTimeout(wait);
      if (term.length < 2) { res.innerHTML = ''; return; }
      wait = setTimeout(function(){
        var mine = ++seq;
        var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/';
        fetch(base + 'homepage-hub/products?q=' + encodeURIComponent(term), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
          .then(function(r){
            /* A 404 here is the compiled route table missing this path, not "no
               product": say so, with the one click that fixes it. 403 is the role. */
            if (r.status === 404) throw new Error('Product search is not answering — the compiled route table on the server may be stale. Platform → Cache → Clear caches fixes it.');
            if (r.status === 403) throw new Error('Your role is not allowed to search products here.');
            return r.json();
          }).then(function(j){
            if (mine !== seq) return;
            res.innerHTML = (j && j.products || []).map(function(p){
              return '<li><button type="button" data-ksb-pick="' + esc(i) + '|' + (+p.id) + '" data-ksb-name="' + esc(p.name) + '">' + esc(p.name) + (p.brand ? ' · ' + esc(p.brand) : '') + '</button></li>';
            }).join('') || '<li class="ksb-note">No visible product matches that.</li>';
          }).catch(function(err){ if (mine === seq) res.innerHTML = '<li class="ksb-miss">' + esc(err && err.message && err.message.length > 20 ? err.message : 'Search failed.') + '</li>'; });
      }, 300);
      return;
    }
    if ((el.tagName === 'INPUT' && el.type === 'text') || el.tagName === 'TEXTAREA') edit(el, false);
  });

  window.kbbSbc = {
    /* f: the ModuleSchema field; v: its value; attr: the screen's own
       listening attribute, already escaped; lists: {cats, brands, products}
       as [{id, name(, slug)}], whichever the screen holds. */
    html: function(f, v, attr, lists){
      lists = lists || {};
      ['cats', 'brands', 'products'].forEach(function(k){ if (Array.isArray(lists[k]) && lists[k].length) L[k] = lists[k]; });
      (lists.cards || []).forEach(function(c){ if (c && c.id) NAMES[+c.id] = c.name; });
      var rows = parse(v);
      return '<div class="ksb"><input type="hidden" ' + attr + ' value="' + esc(v == null ? '' : v) + '">'
        + '<ol class="ksb-list">' + listHTML(rows, {}) + '</ol>'
        + '<div class="ksb-foot"><button type="button" class="ksb-btn" data-ksb-add' + (rows.length >= V.cap ? ' disabled' : '') + '>+ Add a tab</button>'
        + '<button type="button" class="ksb-btn" data-ksb-reset data-kbb-asks>Restore the eight concerns</button>'
        + '<span class="ksb-note">At most ' + (+V.cap) + '. A tab with no products to show is left out of the shop.</span></div></div>';
    }
  };
})();
</script>
@endverbatim
