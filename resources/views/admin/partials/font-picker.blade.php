{{--
    The font picker — admin only.                                   (Lane FS)

    A searchable list of the font library (App\Support\FontLibrary) with every
    option set in its OWN face, so the owner sees the font before he picks it.

    LIGHT, AND MEASURED BY WHAT IT DOES NOT LOAD. The library is twenty-nine
    families and about 1.3 MB; this screen loads a face only when its option
    is SHOWN — the list shows one kind at a time (sans, serif, script,
    Arabic: eleven at most) or at most ten search matches — and caches it for
    the session. Opening the picker on "Elegant serif" fetches nine files, not
    twenty-nine; a family nobody looks at is never downloaded. Faces are added
    with the FontFace API under a private name (kbbfp-<key>), so nothing here
    can restyle the admin itself. No timer, no observer, no measuring: the
    list is drawn from the catalogue the page was rendered with.

    Used by the Homepage content editor's Fonts & size tab and by Appearance →
    Site layout → Fonts (its <select data-sls-font>, passed to enhance()).

    Pulled in once, by admin/partials/homepage-hub.blade.php, which admin/
    app.blade.php already includes once — so this file needed no new include
    in the console's own template. The catalogue is constants and Vite asset
    URLs; json_encode with the HEX flags keeps it inert inside <script>.
--}}
<style>
.kfp{display:grid;gap:8px;min-width:0}
.kfp-btn{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:8px 12px;font-size:15px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:var(--surface,#fff);color:inherit;cursor:pointer;text-align:start;min-width:0}
.kfp-btn span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.kfp-btn small{font:12px/1.2 system-ui,sans-serif;color:var(--ink-soft,#6b7280);flex:0 0 auto}
.kfp-pop{border:1px solid var(--border,#e6e6e6);border-radius:12px;background:var(--surface,#fff);padding:10px;display:grid;gap:8px;box-shadow:0 10px 28px rgba(0,0,0,.10);min-width:0}
.kfp-pop[hidden]{display:none}
.kfp-q{width:100%;padding:8px 10px;font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:var(--surface,#fff);color:inherit;min-width:0}
.kfp-kinds{display:flex;gap:6px;flex-wrap:wrap}
.kfp-kind{font:600 12px/1 system-ui,sans-serif;padding:6px 10px;border-radius:99px;border:1px solid var(--border,#e6e6e6);background:transparent;color:inherit;cursor:pointer}
.kfp-kind[aria-pressed="true"]{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.kfp-list{list-style:none;margin:0;padding:0;display:grid;gap:4px;max-height:320px;overflow:auto}
.kfp-opt{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:baseline;gap:10px;width:100%;padding:9px 10px;border:1px solid transparent;border-radius:9px;background:transparent;color:inherit;cursor:pointer;text-align:start;font-size:19px;line-height:1.25}
.kfp-opt:hover,.kfp-opt:focus-visible{border-color:var(--border,#e6e6e6);background:rgba(127,127,127,.06);outline:0}
.kfp-opt[aria-selected="true"]{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee)}
.kfp-opt span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.kfp-opt small{font:11.5px/1.2 system-ui,sans-serif;color:var(--ink-soft,#6b7280)}
.kfp-none{font-size:12.5px;color:var(--ink-soft,#6b7280);padding:6px 4px}
</style>
<script>
window.kbbFontPicker = (function () {
  'use strict';

  var CAT = {!! json_encode(\App\Support\FontLibrary::catalogue(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!};
  var SITE = {!! json_encode(\App\Support\SiteFonts::chosen(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
  var KINDS = {!! json_encode(\App\Support\FontLibrary::KINDS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
  var BY = {};
  CAT.forEach(function (c) { BY[c.key] = c; });
  var loaded = {};

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }

  /** Fetch ONE face, once per page, under a private family name. */
  function load(key) {
    var c = BY[key];
    if (!c || !c.url || loaded[key] || !window.FontFace || !document.fonts) return;
    var ff = new FontFace('kbbfp-' + key, 'url("' + c.url.replace(/["\\]/g, '') + '")', {weight: '100 900'});
    document.fonts.add(ff);
    loaded[key] = ff.load().catch(function () {});
  }

  /** The CSS font-family that shows `key` in its own face ('' = the site's). */
  function family(key, heading) {
    if (!key) key = heading ? SITE.heading : SITE.body;
    var c = BY[key];
    return c ? "'kbbfp-" + key + "'," + c.stack : 'inherit';
  }

  function label(key, labels) {
    return (labels && labels[key]) || (BY[key] && BY[key].label) || key;
  }

  /**
   * Draw a picker into `el`.
   * opts: {value, labels: {'' : 'Site heading font', key: label…}, heading: bool, onChange(key)}
   */
  function mount(el, opts) {
    var value = opts.value || '';
    var labels = opts.labels || {};
    var allowBlank = Object.prototype.hasOwnProperty.call(labels, '');
    var kind = (BY[value] || BY[opts.heading ? SITE.heading : SITE.body] || CAT[0]).kind;
    var q = '';
    var open = false;

    function shown() {
      var term = q.trim().toLowerCase();
      var rows = CAT.filter(function (c) {
        return term ? (c.label + ' ' + c.family).toLowerCase().indexOf(term) >= 0 : c.kind === kind;
      });
      return term ? rows.slice(0, 10) : rows;
    }

    function optHTML(key) {
      return '<li><button type="button" class="kfp-opt" role="option" aria-selected="' + (key === value) + '" data-kfp-opt="' + esc(key) + '"'
        + ' style="font-family:' + esc(family(key, opts.heading)) + '"><span>' + esc(key ? (BY[key] ? BY[key].family : key) : label('', labels)) + '</span>'
        + '<small>' + esc(key ? (KINDS[BY[key].kind] || '') : 'as the shop is set') + '</small></button></li>';
    }

    function paint() {
      load(value || (opts.heading ? SITE.heading : SITE.body));
      var rows = open ? shown() : [];
      rows.forEach(function (c) { load(c.key); });
      el.innerHTML = '<div class="kfp">'
        + '<button type="button" class="kfp-btn" aria-expanded="' + open + '" data-kfp-toggle style="font-family:' + esc(family(value, opts.heading)) + '">'
        + '<span>' + esc(label(value, labels)) + '</span><small>' + (open ? 'Close' : 'Change') + '</small></button>'
        + '<div class="kfp-pop"' + (open ? '' : ' hidden') + '>'
        + '<input class="kfp-q" type="search" placeholder="Search 29 fonts…" aria-label="Search fonts" value="' + esc(q) + '" data-kfp-q>'
        + '<div class="kfp-kinds" role="group" aria-label="Kind of font">' + Object.keys(KINDS).map(function (k) {
            return '<button type="button" class="kfp-kind" aria-pressed="' + (!q && k === kind) + '" data-kfp-kind="' + esc(k) + '">' + esc(KINDS[k]) + '</button>';
          }).join('') + '</div>'
        + '<ul class="kfp-list" role="listbox" aria-label="Fonts">'
        + (allowBlank && !q ? optHTML('') : '')
        + rows.map(function (c) { return optHTML(c.key); }).join('')
        + (rows.length ? '' : '<li class="kfp-none">No font by that name.</li>')
        + '</ul></div></div>';
      bind();
    }

    function bind() {
      el.querySelector('[data-kfp-toggle]').onclick = function () { open = !open; paint(); if (open) { var i = el.querySelector('[data-kfp-q]'); if (i) i.focus(); } };
      el.querySelectorAll('[data-kfp-kind]').forEach(function (b) { b.onclick = function () { kind = b.dataset.kfpKind; q = ''; paint(); }; });
      el.querySelectorAll('[data-kfp-opt]').forEach(function (b) {
        b.onpointerenter = b.onfocus = function () { load(b.dataset.kfpOpt); };
        b.onclick = function () { value = b.dataset.kfpOpt; open = false; q = ''; paint(); if (opts.onChange) opts.onChange(value); };
      });
      var i = el.querySelector('[data-kfp-q]');
      if (i) i.oninput = function () { q = i.value; var at = i.selectionStart; paint(); var j = el.querySelector('[data-kfp-q]'); j.focus(); try { j.setSelectionRange(at, at); } catch (e) {} };
      // Escape closes the list, not the editor it sits in.
      el.onkeydown = function (e) { if (e.key === 'Escape' && open) { e.stopPropagation(); open = false; paint(); el.querySelector('[data-kfp-toggle]').focus(); } };
    }

    paint();
    return {set: function (v) { value = v || ''; paint(); }};
  }

  /**
   * Upgrade every <select ATTR> under `root` (Site layout → Fonts passes its
   * own prefixed `data-sls-font`); ATTR="heading" previews the site heading.
   */
  function enhance(root, attr) {
    attr = attr || 'data-kbb-font';
    (root || document).querySelectorAll('select[' + attr + ']').forEach(function (sel) {
      if (sel.dataset.kfpDone) return;
      sel.dataset.kfpDone = '1';
      sel.hidden = true;
      var host = document.createElement('div');
      sel.parentNode.insertBefore(host, sel.nextSibling);
      var labels = {};
      Array.prototype.forEach.call(sel.options, function (o) { labels[o.value] = o.textContent; });
      mount(host, {value: sel.value, labels: labels, heading: sel.getAttribute(attr) === 'heading', onChange: function (v) {
        sel.value = v;
        sel.dispatchEvent(new Event('change', {bubbles: true}));
      }});
    });
  }

  return {mount: mount, enhance: enhance, load: load, family: family, site: SITE, catalogue: CAT};
})();
</script>
