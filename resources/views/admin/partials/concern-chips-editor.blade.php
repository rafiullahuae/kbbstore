{{--
    The Skin concerns chip editor (Lane CX).

    Appearance → Homepage content → Skin concerns → "Concern chips, in order",
    and the same field in All sections → Skin concerns → Edit content. ONE
    editor for both screens: each screen's field renderer hands it the field,
    the value and the attribute its own save path listens on, and this draws
    the rows around a hidden input carrying that attribute. Every change
    rewrites the input's value and fires `input` on it, so each screen stores
    the value exactly as it stores any other field — no second save path.

    The value is HomeConcerns' JSON list; the server cleans it again on save
    and on every read (HomeConcerns::items()), so nothing typed here reaches
    the shop unchecked. The icons and colours below are HomeConcerns'
    constants, printed once as JSON. Every string drawn goes through esc().
    No request, no timer: the categories come from the payload the screen
    already loaded.
--}}
<script>window.kbbCcVocab = @json(\App\Support\HomeConcerns::editorVocab(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);</script>
@verbatim
<style>
.kcc{display:grid;gap:8px;min-width:0;width:100%}
.kcc *{box-sizing:border-box}
.kcc-list{list-style:none;margin:0;padding:0;display:grid;gap:8px;min-width:0}
.kcc-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px 10px;align-items:start;border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:10px;background:var(--surface,#fff);min-width:0}
.kcc-pv{display:inline-flex;align-items:center;gap:8px;padding:4px 14px 4px 4px;border-radius:999px;max-width:100%;min-width:0;color:#3A2E35;font-weight:600;font-size:13px}
.kcc-pv b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.kcc-ic{position:relative;flex:0 0 38px;width:38px;height:38px;border-radius:50%;background:#fff;color:#B03A62}
.kcc-ic svg{position:absolute;left:19%;top:19%;width:62%;height:62%;overflow:visible}
.kcc-ic .hs-cc-a{fill:var(--kcc-acc,#F2AFC5);stroke:none}
.kcc-act{display:flex;gap:4px}
.kcc-f{grid-column:1 / -1;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr));gap:8px;min-width:0}
.kcc-f label{display:grid;gap:3px;font-size:11.5px;font-weight:600;color:var(--ink-soft,#6b7280);min-width:0}
.kcc-f select,.kcc-f input{width:100%;min-width:0;padding:7px 9px;font:inherit;font-size:13px;font-weight:400;border:1px solid var(--border,#e6e6e6);border-radius:8px;background:var(--surface,#fff);color:var(--ink,#111)}
.kcc-btn{font:inherit;font-size:12.5px;font-weight:600;padding:5px 10px;border:1px solid var(--border,#e6e6e6);border-radius:8px;background:transparent;color:inherit;cursor:pointer;min-width:30px}
.kcc-btn[disabled]{opacity:.4;cursor:default}
.kcc-btn:focus-visible,.kcc-f select:focus-visible,.kcc-f input:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:1px}
.kcc-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.kcc-note{font-size:12px;color:var(--ink-soft,#6b7280)}
.kcc-miss{font-size:11.5px;color:#b4443c}
</style>
<script>
(function(){
  'use strict';
  if (window.kbbCc) return;
  var V = window.kbbCcVocab || {icons: [], tones: [], defaults: [], cap: 12};
  var ICON = {}, TONE = {};
  V.icons.forEach(function(i){ ICON[i.key] = i; });
  V.tones.forEach(function(t){ TONE[t.key] = t; });
  var cats = [];

  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function catById(id){ for (var i = 0; i < cats.length; i++) if (+cats[i].id === +id) return cats[i]; return null; }
  function catBySlugs(list){ for (var j = 0; j < (list || []).length; j++) for (var i = 0; i < cats.length; i++) if (cats[i].slug === list[j]) return cats[i]; return null; }

  /* The rows a value means: '' is "never set" — the seven defaults, each
     matched to this shop's category by its address where one exists. */
  function parse(v){
    var rows;
    if (String(v || '').trim() === '') {
      rows = V.defaults.map(function(d){ var c = catBySlugs(d.s); return {c: c ? +c.id : 0, s: c ? [] : d.s.slice(), l: '', la: d.la || '', i: d.i, t: ''}; });
    } else {
      try { rows = JSON.parse(v); } catch (e) { rows = []; }
    }
    return (Array.isArray(rows) ? rows : []).filter(function(r){ return r && typeof r === 'object'; }).map(function(r){
      return {c: +r.c || 0, s: Array.isArray(r.s) ? r.s.slice(0, 6) : [], l: String(r.l || ''), la: String(r.la || ''), i: ICON[r.i] ? r.i : 'pores', t: TONE[r.t] ? r.t : ''};
    });
  }
  function write(box, rows){
    var hid = box.querySelector('input[type=hidden]');
    hid.value = JSON.stringify(rows.map(function(r){ var o = {c: r.c, l: r.l, la: r.la, i: r.i, t: r.t}; if (!r.c && r.s.length) o.s = r.s; return o; }));
    hid.dispatchEvent(new Event('input', {bubbles: true}));
  }
  function rowsOf(box){ return parse(box.querySelector('input[type=hidden]').value); }

  function rowHTML(r, i, n){
    var cat = r.c ? catById(r.c) : null;
    var tone = TONE[r.t || (ICON[r.i] || {}).tone] || TONE.pink;
    var name = r.l || (cat ? cat.name : (r.s[0] || ''));
    var opts = '<option value="">— choose a category —</option>' + cats.map(function(c){
      return '<option value="' + (+c.id) + '"' + (+c.id === r.c ? ' selected' : '') + '>' + esc(c.name) + '</option>';
    }).join('');
    var miss = !cat ? '<span class="kcc-miss">' + (r.s.length ? 'No category at /' + esc(r.s[0]) + '/ on this shop — not shown until one is chosen.' : (r.c ? 'Category #' + (+r.c) + ' no longer exists — not shown.' : 'Choose a category — not shown until you do.')) + '</span>' : '';
    return '<li class="kcc-row">'
      + '<div style="display:grid;gap:4px;min-width:0"><span class="kcc-pv" style="background:' + esc(tone.bg) + ';--kcc-acc:' + esc(tone.acc) + '"><span class="kcc-ic">' + ((ICON[r.i] || ICON.pores).svg) + '</span><b>' + esc(name || 'Chip ' + (i + 1)) + '</b></span>' + miss + '</div>'
      + '<span class="kcc-act">'
      +   '<button type="button" class="kcc-btn" data-kcc-mv="' + i + '|-1" aria-label="Move chip ' + (i + 1) + ' up"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
      +   '<button type="button" class="kcc-btn" data-kcc-mv="' + i + '|1" aria-label="Move chip ' + (i + 1) + ' down"' + (i === n - 1 ? ' disabled' : '') + '>↓</button>'
      +   '<button type="button" class="kcc-btn" data-kcc-x="' + i + '" aria-label="Remove chip ' + (i + 1) + '">×</button>'
      + '</span>'
      + '<div class="kcc-f">'
      +   '<label>Category (the link)<select data-kcc-f="c" data-kcc-i="' + i + '">' + opts + '</select></label>'
      +   '<label>Label<input type="text" maxlength="60" data-kcc-f="l" data-kcc-i="' + i + '" value="' + esc(r.l) + '" placeholder="' + esc(cat ? cat.name : 'The category’s name') + '"></label>'
      +   '<label>Label · Arabic<input type="text" maxlength="60" dir="rtl" data-kcc-f="la" data-kcc-i="' + i + '" value="' + esc(r.la) + '" placeholder="The category’s Arabic name"></label>'
      +   '<label>Icon<select data-kcc-f="i" data-kcc-i="' + i + '">' + V.icons.map(function(o){ return '<option value="' + esc(o.key) + '"' + (o.key === r.i ? ' selected' : '') + '>' + esc(o.label) + '</option>'; }).join('') + '</select></label>'
      +   '<label>Colour<select data-kcc-f="t" data-kcc-i="' + i + '"><option value="">The icon’s own</option>' + V.tones.map(function(o){ return '<option value="' + esc(o.key) + '"' + (o.key === r.t ? ' selected' : '') + '>' + esc(o.label) + '</option>'; }).join('') + '</select></label>'
      + '</div></li>';
  }
  function listHTML(rows){
    return rows.length ? rows.map(function(r, i){ return rowHTML(r, i, rows.length); }).join('')
      : '<li class="kcc-note">No chips — the section shows nothing. Add one, or restore the seven concerns.</li>';
  }
  function draw(box){
    var rows = rowsOf(box);
    box.querySelector('.kcc-list').innerHTML = listHTML(rows);
    box.querySelector('[data-kcc-add]').disabled = rows.length >= V.cap;
  }

  /* One set of listeners for every editor on the page, installed once. */
  document.addEventListener('click', function(e){
    var b = e.target.closest && e.target.closest('.kcc [data-kcc-mv],.kcc [data-kcc-x],.kcc [data-kcc-add],.kcc [data-kcc-reset]');
    if (!b) return;
    var box = b.closest('.kcc'), rows = rowsOf(box);
    if (b.hasAttribute('data-kcc-mv')) {
      var p = b.getAttribute('data-kcc-mv').split('|'), i = +p[0], j = i + (+p[1]);
      if (j < 0 || j >= rows.length) return;
      var t = rows[i]; rows[i] = rows[j]; rows[j] = t;
    } else if (b.hasAttribute('data-kcc-x')) {
      rows.splice(+b.getAttribute('data-kcc-x'), 1);
    } else if (b.hasAttribute('data-kcc-add')) {
      if (rows.length >= V.cap) return;
      rows.push({c: 0, s: [], l: '', la: '', i: 'pores', t: ''});
    } else {
      /* A reset asks first, in the admin's own box (reset-guard), as every
         reset-to-default control does. Nothing is saved until Save. */
      var put = function(){ write(box, parse('')); draw(box); };
      if (window.kbbConfirm) {
        window.kbbConfirm('Put the seven concern chips back? Your own chips and labels here will be replaced (nothing is saved until you press Save).').then(function(ok){ if (ok) put(); });
      } else { put(); }
      return;
    }
    write(box, rows);
    draw(box);
  });
  function edit(e, redraw){
    var el = e.target;
    if (!el.closest || !el.closest('.kcc') || !el.hasAttribute('data-kcc-f')) return;
    var box = el.closest('.kcc'), rows = rowsOf(box), r = rows[+el.getAttribute('data-kcc-i')], f = el.getAttribute('data-kcc-f');
    if (!r) return;
    if (f === 'c') { r.c = +el.value || 0; if (r.c) r.s = []; } else { r[f] = el.value; }
    write(box, rows);
    if (redraw) draw(box);
  }
  document.addEventListener('change', function(e){ if (e.target.tagName === 'SELECT') edit(e, true); });
  document.addEventListener('input', function(e){ if (e.target.tagName === 'INPUT' && e.target.type === 'text') edit(e, false); });

  window.kbbCc = {
    /* f: the ModuleSchema field; v: its value; attr: the screen's own
       listening attribute, already escaped; list: [{id, name, slug}]. */
    html: function(f, v, attr, list){
      cats = Array.isArray(list) ? list : cats;
      var rows = parse(v);
      var box = '<div class="kcc"><input type="hidden" ' + attr + ' value="' + esc(v == null ? '' : v) + '">'
        + '<ol class="kcc-list">' + listHTML(rows) + '</ol>'
        + '<div class="kcc-foot"><button type="button" class="kcc-btn" data-kcc-add' + (rows.length >= V.cap ? ' disabled' : '') + '>+ Add a chip</button>'
        + '<button type="button" class="kcc-btn" data-kcc-reset data-kbb-asks>Restore the seven concerns</button>'
        + '<span class="kcc-note">At most ' + (+V.cap) + '. Each whole chip links to its category.</span></div></div>';
      return box;
    }
  };
})();
</script>
@endverbatim
