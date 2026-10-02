{{--
    The category header's DESIGN KIT for the console.                  Lane QC

    Included (once, whichever includes it first) by the two screens that edit
    the category header:

      Appearance → Site layout → Category header       (site-layout-screen)
      Catalog → Categories → Edit → Category header    (category-tree-screen)

    THE OWNER, IN HIS WORDS:

      "i will upload the background images manually. forget it. i need the
       same designs on backend to choose the category banner designs, text
       style etc and for mobile also. i will set and upload the banners
       manually"

      "along with previews of designs to choose, and also along with LIVE
       preview."

      "make sure that i should have these designs to chooose from and make
       edits as per need." -- pointing at docs/py-options/overview.png.

    So this file draws three things, all with THE SHOP'S OWN STYLESHEET
    (kbb-title-header.css, loaded just below, the file the category page
    loads), with the same classes and custom properties App\Support\TitleHeader
    writes -- so what he clicks is the shop's CSS, not a drawing of it:

      header()   one header, for one device, at full size -- the live preview.
      tiles()    a row of clickable designs, each a small rendering of that
                 design with its letter or number and name under it, the
                 chosen one ringed: role="radiogroup" / role="radio", arrows
                 move and choose, Home/End, Enter/Space choose, one tab stop.
      resolve()  which choice a device ends up with, in TitleHeader's order:
                 the category's per-device choice, its both-devices choice,
                 the shop's setting for that device, the shipped default.

    A deliberate copy of TitleHeader's resolution, the way PY's preview was,
    and pinned the same way: QcCategoryHeaderDevicesTest and
    PyCategoryHeaderOptionsTest read the lists, the property names and the
    defaults out of this file and require them to agree with the server.

    LAPTOP, SCALED BY CSS ALONE. A laptop header is 1236px wide on a 1280px
    screen; the preview column is narrower. The laptop preview is drawn at
    about that width and scaled down with `zoom`, the factor picked by a
    container query on the preview's own width -- arithmetic in the
    stylesheet, nothing measured in script (the project forbids the
    element-measuring APIs by name).

    NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, in code or in a
    comment: Blade pairs the first such opening directive it finds anywhere in
    the file with the next closing one.

    Every class is prefixed thk- and every data- attribute data-thk-, and
    neither appears anywhere else in the console.
--}}
@once
@vite('resources/css/kbb/kbb-title-header.css')
{{-- The shop's own face, so a preview's title is set in Outfit as the page sets it. Constant CSS from WebFonts. --}}
<style id="thk-outfit">{!! \App\Support\WebFonts::faceCss(\App\Support\WebFonts::OUTFIT) !!}</style>
@verbatim
<style>
/* THE SHOP'S CONTEXT around every preview, so the header is drawn as the
   category page draws it: the shop's ink, its second ink and its typeface
   (kbb.css :root), not the console's. The header itself carries padding:0
   since 2.60.350, so kbb.css's section{padding:52px 0} -- absent here --
   makes no difference to its height on either side. */
.thk-live,.thk-art{--ink:#2A2228;--ink-2:#5E545A;--sans:"Outfit",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  font-family:var(--sans);line-height:1.5;-webkit-font-smoothing:antialiased}
/* ── The tiles ─────────────────────────────────────────────────────────── */
.thk-pick{display:grid;gap:7px;min-width:0;margin-top:14px}
.thk-lab{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.thk-hint{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0}
.thk-tiles{display:grid;gap:9px;min-width:0;grid-template-columns:repeat(auto-fill,minmax(min(150px,100%),1fr))}
.thk-tiles.is-wide{grid-template-columns:repeat(auto-fill,minmax(min(300px,100%),1fr))}
.thk-tiles.is-compact{grid-template-columns:repeat(auto-fill,minmax(min(92px,100%),1fr));gap:7px}
.thk-tiles > *{min-width:0}
.thk-tile{position:relative;display:grid;gap:5px;align-content:start;min-width:0;padding:5px;
  border:1px solid var(--border,#e6e6e6);border-radius:11px;background:var(--surface,#fff);
  cursor:pointer;outline:none;color:inherit}
.thk-tile:hover{border-color:var(--ink-soft,#9ca3af)}
.thk-tile[aria-checked="true"]{border-color:var(--accent,#15a85a);box-shadow:0 0 0 2px var(--accent,#15a85a)}
.thk-tile:focus-visible{box-shadow:0 0 0 2px var(--surface,#fff),0 0 0 4px #2563eb}
.thk-tile[aria-checked="true"]:focus-visible{box-shadow:0 0 0 2px var(--accent,#15a85a),0 0 0 5px #2563eb}
.thk-tick{position:absolute;top:8px;inset-inline-end:8px;z-index:3;width:20px;height:20px;border-radius:50%;
  background:var(--accent,#15a85a);color:#fff;font-size:12px;line-height:20px;text-align:center;font-weight:700;
  display:none;box-shadow:0 1px 3px rgba(0,0,0,.25)}
.thk-tile[aria-checked="true"] .thk-tick{display:block}
.thk-cap{font-size:11.5px;line-height:1.35;min-width:0;overflow-wrap:anywhere;padding:0 2px 1px}
.thk-cap b{font-weight:750}
.thk-cap small{display:block;color:var(--ink-soft,#6b7280);font-size:10.5px}
.thk-tiles.is-compact .thk-cap{font-size:10.5px}
.thk-tiles.is-compact .thk-shop{min-height:58px;font-size:10px;padding:4px 18px 4px 4px}
.thk-tiles.is-compact .thk-tick{top:6px;width:17px;height:17px;line-height:17px;font-size:10px}
/* The art: one to three small headers side by side, drawn by the shop's CSS. */
.thk-art{display:grid;gap:3px;grid-auto-flow:column;grid-auto-columns:minmax(0,1fr);min-width:0;pointer-events:none}
.thk-art .kbb-th{margin:0}
.thk-art .kbb-th__desc{margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.thk-art .kbb-th--t-label .kbb-th__desc,.thk-art .kbb-th--t-label .kbb-th__sub{margin-top:4px}
/* The frosted panel's 16px/20px padding is a full-size number; in a 60px
   thumbnail it would be most of the header. Thumbnails only -- the live
   preview and the shop use the stylesheet's own. */
.thk-art .kbb-th--t-frost .kbb-th__text{padding:4px 6px}
.thk-shop{display:grid;place-items:center;min-height:60px;border-radius:8px;
  background:repeating-linear-gradient(135deg,rgba(127,127,127,.08) 0 8px,transparent 8px 16px);
  font-size:11px;font-weight:650;color:var(--ink-soft,#6b7280);text-align:center;padding:4px}

/* ── The device switch ─────────────────────────────────────────────────── */
.thk-dev{display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-width:0}
.thk-dev button{padding:7px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
  color:inherit;font:inherit;font-size:12.5px;cursor:pointer;max-width:100%}
.thk-dev button[aria-pressed="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650;
  background:rgba(21,168,90,.07)}
.thk-dev small{font-weight:400;opacity:.8}

/* ── The live preview ──────────────────────────────────────────────────── */
.thk-live{container-type:inline-size;container-name:thk;min-width:0;margin-top:10px}
.thk-live .kbb-th{margin:0}
.thk-phone{max-width:346px;margin:0 auto}
/* LAPTOP: drawn about 1236px wide and zoomed into the column. The width is
   the column divided by the zoom, so it always fills the column exactly. */
.thk-zoom{--z:.22;width:calc(100cqw / var(--z));zoom:var(--z)}
@container thk (min-width:300px){.thk-zoom{--z:.243}}
@container thk (min-width:350px){.thk-zoom{--z:.283}}
@container thk (min-width:400px){.thk-zoom{--z:.324}}
@container thk (min-width:450px){.thk-zoom{--z:.364}}
@container thk (min-width:500px){.thk-zoom{--z:.405}}
@container thk (min-width:550px){.thk-zoom{--z:.445}}
@container thk (min-width:600px){.thk-zoom{--z:.485}}
@container thk (min-width:650px){.thk-zoom{--z:.526}}
@container thk (min-width:700px){.thk-zoom{--z:.566}}
@container thk (min-width:750px){.thk-zoom{--z:.607}}
@container thk (min-width:800px){.thk-zoom{--z:.647}}
@container thk (min-width:850px){.thk-zoom{--z:.688}}
@container thk (min-width:900px){.thk-zoom{--z:.728}}
@container thk (min-width:950px){.thk-zoom{--z:.769}}
@container thk (min-width:1000px){.thk-zoom{--z:.809}}
@container thk (min-width:1050px){.thk-zoom{--z:.850}}
@container thk (min-width:1100px){.thk-zoom{--z:.890}}
@container thk (min-width:1150px){.thk-zoom{--z:.930}}
@container thk (min-width:1200px){.thk-zoom{--z:.971}}
@container thk (min-width:1236px){.thk-zoom{--z:1}}
.thk-livecap{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:8px 0 0}

/* ── Fine-tuning ───────────────────────────────────────────────────────── */
.thk-tune{border:1px dashed var(--border,#e6e6e6);border-radius:11px;padding:11px 12px;display:grid;gap:12px;min-width:0;margin-top:9px}
.thk-tune-h{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;min-width:0}
.thk-tune-h b{font-size:12.5px;min-width:0;overflow-wrap:anywhere}
.thk-tune-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(min(220px,100%),1fr));min-width:0}
.thk-tune-grid > *{min-width:0}
</style>

<script>
(function () {
  'use strict';

  if (window.kbbTH) return;

  /*
   * THE SERVER'S LISTS, in the server's order. App\Support\TitleHeader::ALIGNS
   * and ICON_BOXES, App\Services\SiteLayout::TREATMENTS and BOX_STYLES;
   * PyCategoryHeaderOptionsTest reads these four lines and requires them to
   * match, so a fifth treatment cannot be added to one side only.
   */
  var PV_ALIGNS = ['start', 'center', 'end'];
  var PV_TREATMENTS = ['shadow', 'fade', 'frost', 'label', 'none'];
  var PV_BOXES = ['blush', 'cream', 'mint', 'lilac', 'plain', 'custom'];
  var PV_ICON_BOXES = ['blush', 'cream', 'mint', 'lilac', 'custom'];
  var PV_TEXTS = ['auto', 'light', 'dark'];
  var PV_FOCUSES = ['left', 'center', 'right'];
  var PV_VALIGNS = ['top', 'center', 'bottom'];

  /* The option sheet's own letters, numbers and names -- his "B + 3". */
  var BOX_NAMES = {
    blush: ['A', 'Blush icons'], cream: ['B', 'Cream icons'], mint: ['C', 'Mint icons'],
    lilac: ['D', 'Lilac icons'], plain: ['E', 'Plain soft colour'], custom: ['F', 'My own colours']
  };
  var TREATMENT_NAMES = {
    shadow: ['1', 'Soft shadow'], fade: ['2', 'Dark fade on the text side'], frost: ['3', 'Frosted panel'],
    label: ['4', 'Solid label'], none: ['5', 'None']
  };
  var ALIGN_NAMES = {
    start: ['Start', 'left in English, right in Arabic'], center: ['Centre', 'both languages'],
    end: ['End', 'right in English, left in Arabic']
  };
  var TEXT_NAMES = { auto: ['Automatic', 'white on a picture, dark on the box'], light: ['White', ''], dark: ['Dark', ''] };
  var VALIGN_NAMES = { top: ['Top', ''], center: ['Middle', ''], bottom: ['Bottom', 'as asked'] };
  var FOCUS_NAMES = { left: ['Left', 'keep the left end'], center: ['Centre', 'as shipped'], right: ['Right', 'keep the right end'] };

  /* The four icon boxes as drawn: SiteLayout::BOX_PRESETS, and the hex in each
     .kbb-th--box-* rule of kbb-title-header.css. */
  var PRESETS = {
    blush: ['#FDF0F4', '#E3A1B5'], cream: ['#FBF4EA', '#CDA57B'],
    mint: ['#EEF8F2', '#8FC9AB'], lilac: ['#F4F0FB', '#B7A3DD']
  };

  /*
   * Every header setting's shipped default -- SiteLayout::SCHEMA's third
   * column. Used when the shop's own values cannot be read (a console user
   * without Site layout rights opening a category). QcCategoryHeaderDevicesTest
   * pins each one against the schema.
   */
  var DEFAULTS = {
    cat_header: true, cat_header_box: true, cat_header_fallback: false,
    cat_header_align: 'start', cat_header_treatment: 'shadow', cat_header_box_treatment: 'none',
    cat_header_overlay: 40, cat_header_box_style: 'blush', cat_header_box_bg: '#FFF4EE',
    cat_header_box_icon: '#EFA889', cat_header_text: 'auto',
    cat_header_valign: 'bottom', cat_header_generic: 'Find your favorite products in our wide range {category} category.',
    cat_header_align_desktop: 'start', cat_header_treatment_desktop: 'shadow',
    cat_header_box_treatment_desktop: 'none', cat_header_box_style_desktop: 'blush',
    cat_header_text_desktop: 'auto', cat_header_valign_desktop: 'bottom',
    cat_header_blush_bg: '#FDF0F4', cat_header_blush_ic: '#E3A1B5',
    cat_header_cream_bg: '#FBF4EA', cat_header_cream_ic: '#CDA57B',
    cat_header_mint_bg: '#EEF8F2', cat_header_mint_ic: '#8FC9AB',
    cat_header_lilac_bg: '#F4F0FB', cat_header_lilac_ic: '#B7A3DD',
    cat_header_icon_strength: 60, cat_header_icon_size: 100,
    cat_header_shadow_strength: 100, cat_header_shadow_blur: 100,
    cat_header_fade_dark: 100, cat_header_fade_reach: 100,
    cat_header_frost_opacity: 100, cat_header_frost_blur: 12, cat_header_frost_radius: 14,
    cat_header_label_bg: '', cat_header_label_fg: '', cat_header_letter: -2, cat_header_desc_colour: '',
    cat_header_title_phone: 26, cat_header_title_desktop: 40, cat_header_weight: '700',
    cat_header_desc_phone: 13, cat_header_desc_desktop: 15, cat_header_lines: 2, cat_header_more: false, cat_header_maxw: 760,
    cat_header_h_phone: 190, cat_header_h_desktop: 300,
    cat_header_pad_y_phone: 28, cat_header_pad_y_desktop: 36,
    cat_header_pad_x_phone: 20, cat_header_pad_x_desktop: 48, cat_header_radius: 18
  };

  /* [phone property, phone setting, laptop setting] -- TitleHeader::PX_VARS. */
  var PV_PAIRS = [
    ['--kbb-th-h', 'cat_header_h_phone', 'cat_header_h_desktop'],
    ['--kbb-th-ts', 'cat_header_title_phone', 'cat_header_title_desktop'],
    ['--kbb-th-ds', 'cat_header_desc_phone', 'cat_header_desc_desktop'],
    ['--kbb-th-py', 'cat_header_pad_y_phone', 'cat_header_pad_y_desktop'],
    ['--kbb-th-px', 'cat_header_pad_x_phone', 'cat_header_pad_x_desktop']
  ];

  /* TitleHeader::TWEAK_VARS and TWEAK_COLOURS: [property, setting, printed as]. */
  var TWEAK_VARS = [
    ['--kbb-th-io', 'cat_header_icon_strength', 'factor'],
    ['--kbb-th-isz', 'cat_header_icon_size', 'factor'],
    ['--kbb-th-shs', 'cat_header_shadow_strength', 'factor'],
    ['--kbb-th-shb', 'cat_header_shadow_blur', 'factor'],
    ['--kbb-th-fdd', 'cat_header_fade_dark', 'factor'],
    ['--kbb-th-fdr', 'cat_header_fade_reach', 'factor'],
    ['--kbb-th-fro', 'cat_header_frost_opacity', 'factor'],
    ['--kbb-th-frb', 'cat_header_frost_blur', 'px'],
    ['--kbb-th-frr', 'cat_header_frost_radius', 'px'],
    ['--kbb-th-ls', 'cat_header_letter', 'em']
  ];
  var TWEAK_COLOURS = [
    ['--kbb-th-lbl-bg', 'cat_header_label_bg'],
    ['--kbb-th-lbl-fg', 'cat_header_label_fg'],
    ['--kbb-th-dc', 'cat_header_desc_colour']
  ];

  /*
   * WHICH FINE-TUNING BELONGS TO WHICH DESIGN -- what the screen offers once a
   * design is chosen, and what "Back to defaults" for that design puts back.
   */
  var TUNE = {
    blush: ['cat_header_blush_bg', 'cat_header_blush_ic', 'cat_header_icon_strength', 'cat_header_icon_size'],
    cream: ['cat_header_cream_bg', 'cat_header_cream_ic', 'cat_header_icon_strength', 'cat_header_icon_size'],
    mint: ['cat_header_mint_bg', 'cat_header_mint_ic', 'cat_header_icon_strength', 'cat_header_icon_size'],
    lilac: ['cat_header_lilac_bg', 'cat_header_lilac_ic', 'cat_header_icon_strength', 'cat_header_icon_size'],
    plain: ['cat_header_box_bg'],
    custom: ['cat_header_box_bg', 'cat_header_box_icon', 'cat_header_icon_strength', 'cat_header_icon_size'],
    shadow: ['cat_header_shadow_strength', 'cat_header_shadow_blur'],
    fade: ['cat_header_fade_dark', 'cat_header_fade_reach'],
    frost: ['cat_header_frost_opacity', 'cat_header_frost_blur', 'cat_header_frost_radius'],
    label: ['cat_header_label_bg', 'cat_header_label_fg'],
    none: []
  };

  /* The three colours whose blank means "as drawn". */
  var OPTIONAL_COLOURS = ['cat_header_label_bg', 'cat_header_label_fg', 'cat_header_desc_colour'];

  /*
   * THE TWO SAMPLE PICTURES the option sheet used -- a busy dark one and a
   * light one -- as constant SVGs: no request, nothing a setting can reach.
   */
  function picture(dark) {
    var svg = dark
      ? "<svg xmlns='http://www.w3.org/2000/svg' width='1600' height='500' viewBox='0 0 1600 500'>"
        + "<defs><linearGradient id='g' x1='0' x2='1'><stop offset='0' stop-color='#5b1e2d'/><stop offset='.5' stop-color='#e07a4f'/><stop offset='1' stop-color='#1f4e63'/></linearGradient></defs>"
        + "<rect width='1600' height='500' fill='url(#g)'/>"
        + "<g fill='#fff' fill-opacity='.2'><circle cx='160' cy='120' r='120'/><circle cx='520' cy='400' r='165'/><circle cx='980' cy='90' r='140'/><circle cx='1360' cy='330' r='190'/></g>"
        + "<g fill='#180c12' fill-opacity='.62'><rect x='90' y='140' width='80' height='300' rx='8'/><rect x='210' y='190' width='100' height='250' rx='8'/><rect x='360' y='110' width='70' height='330' rx='8'/><rect x='1080' y='120' width='90' height='320' rx='8'/><rect x='1220' y='200' width='120' height='240' rx='8'/><rect x='1400' y='150' width='80' height='300' rx='8'/></g>"
        + "<g fill='#fff' fill-opacity='.55'><rect x='110' y='104' width='40' height='36' rx='5'/><rect x='235' y='154' width='50' height='36' rx='5'/><rect x='378' y='74' width='34' height='36' rx='5'/><rect x='1102' y='84' width='46' height='36' rx='5'/><rect x='1250' y='164' width='60' height='36' rx='5'/></g>"
        + "</svg>"
      : "<svg xmlns='http://www.w3.org/2000/svg' width='1600' height='500' viewBox='0 0 1600 500'>"
        + "<defs><linearGradient id='g' x1='0' x2='1'><stop offset='0' stop-color='#fbe9e7'/><stop offset='1' stop-color='#e3f1ef'/></linearGradient></defs>"
        + "<rect width='1600' height='500' fill='url(#g)'/>"
        + "<g fill='#fff' fill-opacity='.75'><circle cx='240' cy='110' r='130'/><circle cx='900' cy='420' r='180'/><circle cx='1420' cy='120' r='150'/></g>"
        + "<g fill='#e8c4c0'><rect x='1040' y='130' width='80' height='250'/><rect x='1310' y='240' width='150' height='140'/><rect x='180' y='230' width='90' height='200'/></g>"
        + "<g fill='#d6e2dc'><rect x='1160' y='190' width='110' height='190'/></g>"
        + "</svg>";
    return 'data:image/svg+xml,' + encodeURIComponent(svg);
  }
  var PICTURES = { dark: picture(true), light: picture(false) };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function pick(v, list, fallback) {
    return (v != null && list.indexOf(String(v)) !== -1) ? String(v) : fallback;
  }

  /* #rgb or #rrggbb as #RRGGBB, else the fallback. */
  function hex(v, fallback) {
    var s = String(v == null ? '' : v).trim().toUpperCase();
    var m = /^#([0-9A-F])([0-9A-F])([0-9A-F])$/.exec(s);
    if (m) s = '#' + m[1] + m[1] + m[2] + m[2] + m[3] + m[3];
    return /^#[0-9A-F]{6}$/.test(s) ? s : fallback;
  }

  /* WCAG relative luminance -- TitleHeader::luminance(), the same formula. */
  function lum(h) {
    var c = [1, 3, 5].map(function (i) {
      var v = parseInt(h.substr(i, 2), 16) / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }

  function val(values, key) {
    return (values && Object.prototype.hasOwnProperty.call(values, key)) ? values[key] : DEFAULTS[key];
  }

  function int(values, key) {
    var n = parseInt(val(values, key), 10);
    return isFinite(n) ? n : DEFAULTS[key];
  }

  function factor(n) { return String(Math.round(n) / 100); }

  function on(v) { return v === true || v === 1 || v === '1'; }

  /*
   * One device's choices: TitleHeader::device(), on the screen's unsaved
   * values. `own` is a category's header_style (or {}); `force` beats both --
   * the tiles use it to draw "this design" whatever is chosen.
   */
  function resolve(values, own, kind, dev, force) {
    own = own || {};
    force = force || {};
    var sfx = dev === 'laptop' ? '_desktop' : '';
    var mine = dev === 'laptop' ? '_desktop' : '_phone';

    var align = pick(force.align, PV_ALIGNS, null)
      || pick(own['align' + mine], PV_ALIGNS, null) || pick(own.align, PV_ALIGNS, null)
      || pick(val(values, 'cat_header_align' + sfx), PV_ALIGNS, 'start');

    var tKey = (kind === 'img' ? 'cat_header_treatment' : 'cat_header_box_treatment') + sfx;
    var treatment = pick(force.treatment, PV_TREATMENTS, null)
      || pick(own['treatment' + mine], PV_TREATMENTS, null) || pick(own.treatment, PV_TREATMENTS, null)
      || pick(val(values, tKey), PV_TREATMENTS, kind === 'img' ? 'shadow' : 'none');

    var box = null, bg = null, ic = null, drawn = null;
    if (kind === 'box') {
      box = pick(force.box, PV_BOXES, null)
        || pick(own['box' + mine], PV_BOXES, null) || pick(own.box, PV_BOXES, null)
        || pick(val(values, 'cat_header_box_style' + sfx), PV_BOXES, 'blush');

      if (PRESETS[box]) {
        drawn = PRESETS[box];
        bg = hex(val(values, 'cat_header_' + box + '_bg'), drawn[0]);
        ic = hex(val(values, 'cat_header_' + box + '_ic'), drawn[1]);
      } else {
        bg = hex(val(values, 'cat_header_box_bg'), '#FFF4EE');
        ic = hex(val(values, 'cat_header_box_icon'), '#EFA889');
      }
      bg = hex(force.bg, null) || hex(own.bg, bg);
      ic = hex(force.ic, null) || hex(own.ic, ic);
    }

    var text = pick(force.text, PV_TEXTS, null) || pick(own['text' + mine], PV_TEXTS, null)
      || pick(val(values, 'cat_header_text' + sfx), PV_TEXTS, 'auto');
    var valign = pick(force.valign, PV_VALIGNS, null) || pick(own['valign' + mine], PV_VALIGNS, null)
      || pick(val(values, 'cat_header_valign' + sfx), PV_VALIGNS, 'bottom');
    var tone = text !== 'auto' ? text : (kind === 'img' ? 'light' : (lum(bg) < 0.4 ? 'light' : 'dark'));

    return { align: align, valign: valign, treatment: treatment, box: box, bg: bg, ic: ic, tone: tone,
             own: kind === 'box' && (!drawn || bg !== drawn[0] || ic !== drawn[1]) };
  }

  /*
   * ONE HEADER, for one device -- the same element TitleHeader writes, with
   * the device's numbers written into both its phone and laptop properties so
   * the stylesheet's 900px switch has nothing to switch between.
   *
   *   o.values  the shop's (unsaved) settings      o.own    a category's header_style
   *   o.kind    'img' or 'box'                     o.image  the picture's address
   *   o.dev     'phone' or 'laptop'                o.force  {align, treatment, box, text, bg, ic}
   *   o.title, o.sub, o.desc (plain text), o.long  o.mini   a thumbnail's numbers
   *   o.rtl     draw as the Arabic page does       o.focus  where a phone cuts the picture
   */
  function header(o) {
    var values = o.values || {};
    var kind = o.kind === 'img' ? 'img' : 'box';
    var dev = o.dev === 'laptop' ? 'laptop' : 'phone';
    var r = resolve(values, o.own, kind, dev, o.force);
    var own = o.own || {};

    var cls = 'kbb-th kbb-th--flush kbb-th--' + kind + ' kbb-th--' + r.tone + ' kbb-th--a-' + r.align
      + ' kbb-th--v-' + r.valign + ' kbb-th--t-' + r.treatment + (r.box ? ' kbb-th--box-' + r.box : '');

    var style = [];
    var phone = dev === 'phone';

    if (o.mini === 'compact') {
      style.push('--kbb-th-h:58px;--kbb-th-hd:58px', '--kbb-th-ts:11px;--kbb-th-tsd:11px',
        '--kbb-th-ds:7px;--kbb-th-dsd:7px', '--kbb-th-py:7px;--kbb-th-pyd:7px', '--kbb-th-px:6px;--kbb-th-pxd:6px',
        '--kbb-th-r:6px', '--kbb-th-mw:2000px', '--kbb-th-mb:0px;--kbb-th-mbd:0px');
    } else if (o.mini) {
      style.push('--kbb-th-h:66px;--kbb-th-hd:66px', '--kbb-th-ts:13px;--kbb-th-tsd:13px',
        '--kbb-th-ds:8px;--kbb-th-dsd:8px', '--kbb-th-py:8px;--kbb-th-pyd:8px', '--kbb-th-px:9px;--kbb-th-pxd:9px',
        '--kbb-th-r:7px', '--kbb-th-mw:2000px', '--kbb-th-mb:0px;--kbb-th-mbd:0px');
    } else {
      PV_PAIRS.forEach(function (p) {
        var v = int(values, phone ? p[1] : p[2]);
        if (p[1] === 'cat_header_title_phone' && own.title_phone != null && phone) v = parseInt(own.title_phone, 10) || v;
        if (p[1] === 'cat_header_title_phone' && own.title_desktop != null && !phone) v = parseInt(own.title_desktop, 10) || v;
        if (p[1] === 'cat_header_h_phone' && own.h_phone != null && phone) v = parseInt(own.h_phone, 10) || v;
        if (p[1] === 'cat_header_h_phone' && own.h_desktop != null && !phone) v = parseInt(own.h_desktop, 10) || v;
        style.push(p[0] + ':' + v + 'px;' + p[0] + 'd:' + v + 'px');
      });
      style.push('--kbb-th-r:' + int(values, 'cat_header_radius') + 'px');
      style.push('--kbb-th-mw:' + int(values, 'cat_header_maxw') + 'px');
      style.push('--kbb-th-mb:0px;--kbb-th-mbd:0px');
    }

    style.push('--kbb-th-ov:' + (Math.max(0, Math.min(85, int(values, 'cat_header_overlay'))) / 100));
    style.push('--kbb-th-lines:' + Math.max(1, Math.min(10, int(values, 'cat_header_lines'))));
    style.push('--kbb-th-tw:' + pick(val(values, 'cat_header_weight'), ['500', '600', '700', '800'], '700'));

    var isz = Math.max(50, Math.min(200, int(values, 'cat_header_icon_size'))) / 100;
    var tile = (o.mini ? 110 : (phone ? 220 : 280)) * isz;
    style.push('--kbb-th-tile:' + tile + 'px');

    if (r.box) style.push('--kbb-th-bg:' + r.bg + ';--kbb-th-ic:' + r.ic);

    TWEAK_VARS.forEach(function (t) {
      if (t[1] === 'cat_header_icon_size') return;   // folded into the tile above
      var n = int(values, t[1]);
      if (n === DEFAULTS[t[1]]) return;
      style.push(t[0] + ':' + (t[2] === 'px' ? n + 'px' : (t[2] === 'em' ? factor(n) + 'em' : factor(n))));
    });
    TWEAK_COLOURS.forEach(function (t) {
      var h = hex(val(values, t[1]), '');
      if (h) style.push(t[0] + ':' + h);
    });

    var layer = '';
    if (kind === 'img') {
      var fx = pick(own.focus, PV_FOCUSES, 'center');
      var pos = (phone && fx === 'left') ? '0% 50%' : ((phone && fx === 'right') ? '100% 50%' : '');
      layer = '<img class="kbb-th__img" src="' + esc(o.image || PICTURES.dark) + '" alt=""'
        + (pos ? ' style="object-position:' + pos + '"' : '') + '>';
    } else if (PV_ICON_BOXES.indexOf(r.box) !== -1) {
      layer = '<div class="kbb-th__icons" aria-hidden="true"></div>';
    }

    var title = o.title || 'Sunscreens';
    var desc = o.desc == null
      ? 'Lightweight Korean sunscreens with high UV protection, made for everyday wear under the UAE sun.'
      : String(o.desc);

    /* 2.60.350: a category with no description gets his generic line, with
       its name in it -- TitleHeader::forModel(), English only. */
    if (desc === '' && o.generic) {
      desc = String(val(values, 'cat_header_generic') || '').trim().slice(0, 300).split('{category}').join(title);
    }

    /* And the description stops at its line count unless "Read more" is on
       (the component's `clamp`); with it on, a long one gets the link. */
    var more = on(val(values, 'cat_header_more'));
    var words = '<div class="kbb-th__title">' + esc(title) + '</div>'
      + (o.sub ? '<p class="kbb-th__sub">' + esc(o.sub) + '</p>' : '')
      + (desc === '' ? '' : ((more && o.long)
          ? '<div class="kbb-th__desc kbb-th__desc--clamp">' + esc(desc) + '</div><span class="kbb-th__more">Read more</span>'
          : '<div class="kbb-th__desc' + (more ? '' : ' kbb-th__desc--clamp') + '">' + esc(desc) + '</div>'));

    var html = '<section class="' + esc(cls) + '" style="' + esc(style.join(';')) + '">'
      + layer + '<div class="kbb-th__scrim" aria-hidden="true"></div>'
      + '<div class="kbb-th__inner"><div class="kbb-th__text">' + words + '</div></div></section>';

    return o.rtl ? '<div dir="rtl">' + html + '</div>' : html;
  }

  /* A thumbnail: the sheet's title and line, at a thumbnail's numbers. */
  function mini(values, kind, dev, force, extra) {
    extra = extra || {};
    return header({
      values: values, own: extra.own, kind: kind, dev: dev, force: force, mini: extra.compact ? 'compact' : true,
      image: kind === 'img' ? (extra.image || PICTURES.dark) : null,
      title: extra.rtl ? 'واقيات الشمس' : (extra.title || 'Sunscreens'),
      desc: extra.rtl ? 'واقيات شمس كورية خفيفة' : 'Lightweight Korean sunscreens, made for every day.',
      rtl: !!extra.rtl
    });
  }

  /*
   * THE PICTURES ON EACH TILE, as the option sheet laid them out:
   *
   *   box style A-F     the light box in that style
   *   1-5 on a picture  the busy dark picture and the light picture, side by
   *                     side -- the sheet's first two columns
   *   1-5 on the box    the light box -- the sheet's third column
   *   alignment         the dark picture, the box, and the box as the Arabic
   *                     page draws it -- the sheet's alignment rows
   *   text colour       the dark picture and the box
   *   where words sit   the dark picture and the box (2.60.350's top/middle/bottom)
   *
   * The sheet showed each treatment on all three grounds at once; the shop
   * has two settings for it (a picture's and the box's, shipped Soft shadow
   * and None), so its three columns are split across the two pickers that
   * actually decide them.
   */
  function art(kind, values, dev, choice, ctx) {
    ctx = ctx || {};
    var own = ctx.own || {};
    var img = ctx.image || null;
    var m = function (k, force, extra) {
      extra = extra || {};
      extra.own = Object.assign({}, own, extra.own || {});
      extra.compact = !!ctx.compact;
      if (ctx.title) extra.title = ctx.title;
      return mini(values, k, dev, force, extra);
    };

    // Each box tile in its own colours, not the category's override of them.
    if (kind === 'box') return m('box', { box: choice }, { own: { bg: null, ic: null } });

    /* A compact tile (the category dialog, three to a row at 390px) has room
       for ONE picture: the category's own banner when it has one, else the
       ground its header will actually be drawn on. */
    if (ctx.compact) {
      var force = {};
      force[{ 'treat-img': 'treatment', 'treat-box': 'treatment', align: 'align', text: 'text', valign: 'valign' }[kind] || 'x'] = choice;
      if (kind === 'focus') return m('img', {}, { image: img, own: { focus: choice } });
      return img ? m('img', force, { image: img }) : m(kind === 'treat-img' ? 'img' : 'box', force);
    }

    if (kind === 'treat-img') return img
      ? m('img', { treatment: choice }, { image: img })
      : m('img', { treatment: choice }) + m('img', { treatment: choice }, { image: PICTURES.light });
    if (kind === 'treat-box') return m('box', { treatment: choice });
    if (kind === 'align') return (img ? m('img', { align: choice }, { image: img }) : m('img', { align: choice }))
      + m('box', { align: choice }) + m('box', { align: choice }, { rtl: true });
    if (kind === 'text') return (img ? m('img', { text: choice }, { image: img }) : m('img', { text: choice }))
      + m('box', { text: choice });
    if (kind === 'valign') return (img ? m('img', { valign: choice }, { image: img }) : m('img', { valign: choice }))
      + m('box', { valign: choice });
    if (kind === 'focus') return m('img', {}, { image: img, own: { focus: choice } });
    return '';
  }

  function names(kind, v) {
    if (kind === 'box') return BOX_NAMES[v];
    if (kind === 'treat-img' || kind === 'treat-box') return TREATMENT_NAMES[v];
    if (kind === 'align') return ALIGN_NAMES[v];
    if (kind === 'text') return TEXT_NAMES[v];
    if (kind === 'focus') return FOCUS_NAMES[v];
    if (kind === 'valign') return VALIGN_NAMES[v];
    return [v, ''];
  }

  /*
   * A RADIO GROUP OF DESIGNS.
   *
   *   o.group    the name the screen hears back in the kbb-th-pick event
   *   o.label    what the group is called, read out with it
   *   o.kind     box | treat-img | treat-box | align | valign | text | focus
   *   o.value    the chosen value ('' = "Use the shop setting")
   *   o.values / o.dev / o.ctx   what the art is drawn with
   *   o.shop     add a first tile, "Use the shop setting", whose value is ''
   *   o.shopSays what the shop's own choice is, for that tile's caption
   *   o.compact  narrower tiles (the category dialog)
   */
  function tiles(o) {
    var list = { box: PV_BOXES, 'treat-img': PV_TREATMENTS, 'treat-box': PV_TREATMENTS, align: PV_ALIGNS,
                 text: PV_TEXTS, focus: PV_FOCUSES, valign: PV_VALIGNS }[o.kind] || [];
    var opts = (o.shop ? [''] : []).concat(list);
    var chosen = opts.indexOf(String(o.value == null ? '' : o.value)) !== -1 ? String(o.value == null ? '' : o.value) : opts[0];
    var id = 'thk-l-' + String(o.group).replace(/[^a-z0-9-]/gi, '');

    var html = opts.map(function (v) {
      var on = v === chosen;
      var n = v === '' ? ['Shop setting', o.shopSays || ''] : names(o.kind, v);
      var lead = (o.kind === 'box' || o.kind === 'treat-img' || o.kind === 'treat-box') && v !== '';
      var cap = lead ? '<b>' + esc(n[0]) + '</b> · ' + esc(n[1]) : '<b>' + esc(n[0]) + '</b>' + (n[1] ? '<small>' + esc(n[1]) + '</small>' : '');
      var pic = v === '' ? '<div class="thk-shop">Use the shop setting</div>' : art(o.kind, o.values, o.dev, v, Object.assign({ compact: !!o.compact }, o.ctx || {}));
      return '<div class="thk-tile" role="radio" tabindex="' + (on ? '0' : '-1') + '" aria-checked="' + (on ? 'true' : 'false') + '"'
        + ' data-thk-value="' + esc(v) + '" aria-label="' + esc(lead ? n[0] + ' · ' + n[1] : n[0] + (n[1] ? ', ' + n[1] : '')) + '">'
        + '<span class="thk-tick" aria-hidden="true">✓</span>'
        + '<div class="thk-art" aria-hidden="true">' + pic + '</div>'
        + '<div class="thk-cap" aria-hidden="true">' + cap + '</div></div>';
    }).join('');

    var wide = o.kind === 'align' || o.kind === 'treat-img' || o.kind === 'text' || o.kind === 'valign';

    return '<div class="thk-pick">'
      + '<div class="thk-lab" id="' + esc(id) + '">' + esc(o.label) + '</div>'
      + (o.hint ? '<p class="thk-hint">' + o.hint + '</p>' : '')
      + '<div class="thk-tiles' + (o.compact ? ' is-compact' : (wide ? ' is-wide' : '')) + '" role="radiogroup"'
      + ' aria-labelledby="' + esc(id) + '" data-thk-group="' + esc(o.group) + '">' + html + '</div></div>';
  }

  /*
   * CHOOSING. One delegated listener pair for every group on every screen.
   * A click or Enter/Space chooses the tile; the arrows move to the next or
   * previous design and choose it, as a native radio group does; Home and End
   * go to the first and last. The screen hears `kbb-th-pick` on the group,
   * re-renders, and the tile that was chosen gets the focus back.
   */
  function choose(tile, viaKeyboard) {
    var group = tile.closest('[data-thk-group]');
    if (!group) return;
    var name = group.getAttribute('data-thk-group');
    var value = tile.getAttribute('data-thk-value');

    group.querySelectorAll('[role="radio"]').forEach(function (t) {
      var on = t === tile;
      t.setAttribute('aria-checked', on ? 'true' : 'false');
      t.setAttribute('tabindex', on ? '0' : '-1');
    });

    group.dispatchEvent(new CustomEvent('kbb-th-pick', { bubbles: true, detail: { group: name, value: value } }));

    if (viaKeyboard || document.activeElement === tile) {
      var again = document.querySelector('[data-thk-group="' + name + '"] [data-thk-value="' + value + '"]');
      if (again) { try { again.focus({ preventScroll: true }); } catch (e) { again.focus(); } }
    }
  }

  document.addEventListener('click', function (e) {
    var tile = e.target.closest ? e.target.closest('[data-thk-group] [role="radio"]') : null;
    if (tile) choose(tile, false);
  });

  document.addEventListener('keydown', function (e) {
    var tile = e.target.closest ? e.target.closest('[data-thk-group] [role="radio"]') : null;
    if (!tile) return;

    var all = Array.prototype.slice.call(tile.closest('[data-thk-group]').querySelectorAll('[role="radio"]'));
    var at = all.indexOf(tile);
    var next = null;

    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = all[(at + 1) % all.length];
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = all[(at - 1 + all.length) % all.length];
    else if (e.key === 'Home') next = all[0];
    else if (e.key === 'End') next = all[all.length - 1];
    else if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') next = tile;
    else return;

    e.preventDefault();
    choose(next, true);
  });

  /* A Phone | Laptop switch. `attr` is the screen's own data- attribute. */
  function deviceSwitch(attr, dev, label) {
    return '<div class="thk-dev" role="group" aria-label="' + esc(label || 'Device') + '">'
      + ['phone', 'laptop'].map(function (d) {
          return '<button type="button" ' + attr + '="' + d + '" aria-pressed="' + (dev === d ? 'true' : 'false') + '">'
            + (d === 'phone' ? 'Phone <small>under 900px</small>' : 'Laptop <small>900px and wider</small>') + '</button>';
        }).join('')
      + '</div>';
  }

  /* The live preview's frame: the phone at its real width, the laptop zoomed. */
  function frame(dev, inner) {
    return '<div class="thk-live"><div class="' + (dev === 'laptop' ? 'thk-zoom' : 'thk-phone') + '">' + inner + '</div></div>';
  }

  /* Plain text out of a stored description, for the preview: parsed, never run. */
  function plain(html) {
    if (!html) return '';
    try {
      var doc = new DOMParser().parseFromString(String(html), 'text/html');
      return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
    } catch (e) {
      return String(html).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    }
  }

  /* http(s), or a site path with no `..` -- TitleHeader::safeImage(). */
  function safeImage(v) {
    v = String(v == null ? '' : v).trim();
    if (!v || v.length > 2048 || /[\s"<>\\]/.test(v)) return '';
    if (/^https?:\/\/[^\/]/i.test(v)) return v;
    if (v.charAt(0) === '/' && v.charAt(1) !== '/' && v.indexOf('..') === -1) return v;
    return '';
  }

  /* The shop's values out of GET /admin-api/site-layout's tabs. */
  function flatten(tabs) {
    var out = {};
    (tabs || []).forEach(function (t) { (t.fields || []).forEach(function (f) { out[f.key] = f.value; }); });
    return out;
  }

  window.kbbTH = {
    ALIGNS: PV_ALIGNS, TREATMENTS: PV_TREATMENTS, BOXES: PV_BOXES, ICON_BOXES: PV_ICON_BOXES,
    TEXTS: PV_TEXTS, FOCUSES: PV_FOCUSES, VALIGNS: PV_VALIGNS, VALIGN_NAMES: VALIGN_NAMES, PRESETS: PRESETS, DEFAULTS: DEFAULTS, TUNE: TUNE,
    OPTIONAL_COLOURS: OPTIONAL_COLOURS, BOX_NAMES: BOX_NAMES, TREATMENT_NAMES: TREATMENT_NAMES,
    ALIGN_NAMES: ALIGN_NAMES, TEXT_NAMES: TEXT_NAMES, FOCUS_NAMES: FOCUS_NAMES, PICTURES: PICTURES,
    esc: esc, hex: hex, on: on, resolve: resolve, header: header, tiles: tiles, deviceSwitch: deviceSwitch,
    frame: frame, plain: plain, safeImage: safeImage, flatten: flatten
  };
})();
</script>
@endverbatim
@endonce
