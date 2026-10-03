{{--
    Appearance -> Homepage content. (Phase 15, Lane FO)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, toast(), uToken() and the
    design tokens this screen borrows. One include line; the block is recorded
    verbatim in docs/FO-ADMIN-APP-BLOCKS.md.

    Its own file rather than more lines inside a 19,000-line Blade: several
    lanes edit that file at once, and a screen that lives on its own can be
    reviewed, reverted and merged on its own. admin/partials/translation-screens
    .blade.php is the precedent and this follows its shape exactly.

    WHAT THIS SCREEN IS FOR. Appearance -> Homepage already owns WHICH of the
    seventeen sections appear, per device, and in which grid skin. Nothing owned
    what they SAY. Three settings the storefront reads were written by nothing in
    the tree -- home_banners, about_text and home_ticker -- so the hero slider,
    the About paragraph and the promo chip were the shipped and only values of
    every install. The hero is the largest thing on the page and carries its only
    h1. This is the screen that gives them an owner.

    IT REUSES THE SETTINGS SCHEMAS AND DOES NOT INVENT A SECOND ONE. Every
    control below is drawn from a field payload the server built with
    App\Services\ModuleSchema -- the same normalise/fields/cast that draw and
    validate PayShipRules and MarketingPixels. The renderer switches on
    `f.type`, which is that vocabulary and not a new one, so a field added to
    App\Services\HomepageContent draws here without this file changing. Nothing
    on this screen knows the name of a single setting.

    NO NAV GROUP IS CREATED HERE. The row joins the existing Appearance group
    through kbbAddNavEntry(), directly under "Homepage", because the two screens
    are two halves of one question and the owner will move between them.
    kbbAddNavEntry joins groups and never invents one, by design.

    THE ID IS ALSO ADDED TO LATE_RENDERED IN app.blade.php, which is the other
    half and is not optional: go()'s dispatch object ends ||renderDash, so an id
    that is routable but absent from it draws the DASHBOARD under this screen's
    own breadcrumb, and a deep link to ?go=hpcontent would land there with
    nothing on the page to report it. The screen paints synchronously before it
    awaits anything, which is the condition LATE_RENDERED requires.

    THIS SCREEN SPEAKS ENGLISH AND IS NOT TRANSLATED, like the rest of the
    console: T8 is deferred by the owner and Locale::localisable() keeps the
    whole admin off the /ar prefix. The words it EDITS are the shop's and are
    not strings of this application, which is the whole point of it existing.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade compiles STATEMENTS BEFORE COMMENTS, so a
    directive named in prose is compiled as a directive -- and the first such
    opening marker anywhere in the file pairs with the next closing one, which
    serves the whole docblock to the browser as visible text.

    The whole body is wrapped in one so that the braces inside JavaScript
    template literals are not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Every rule is prefixed hpc- and every id is hpc-, so this file can never
   restyle or collide with another screen in the console. The console is one
   document: an unprefixed .card or #save here would reach into whatever else
   is mounted.

   Same layout rule as every screen beside it: nothing may be wider than its
   column at 390px, because the owner reviews on a phone. min-width:0 on the
   grid AND on its children is load-bearing rather than tidiness -- a grid
   item's default min-width is auto, "at least as wide as my content", so a
   card refuses to shrink below its widest child and drags the whole column
   past the viewport with no way to scroll back.
--------------------------------------------------------------------------- */
.hpc-wrap{display:grid;gap:16px;min-width:0;max-width:1180px}
.hpc-wrap > *{min-width:0}
.hpc-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.hpc-h{font-weight:650;font-size:15px;margin:0 0 4px}
.hpc-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin:0}
.hpc-sub + .hpc-sub{margin-top:7px}

.hpc-tabs{display:flex;gap:6px;flex-wrap:wrap}
.hpc-tab{padding:8px 14px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer;font-size:13px}
.hpc-tab.on{background:rgba(127,127,127,.10);border-color:rgba(127,127,127,.4)}

/* A slide: its controls on the left, the preview of the same slide beside it.
   They collapse to one column below 900px, preview FIRST, because on a phone
   the thing being described should be above the boxes describing it. */
.hpc-slide{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,300px);gap:16px;min-width:0}
@media (max-width:900px){
  .hpc-slide{grid-template-columns:minmax(0,1fr)}
  .hpc-slide .hpc-pv{order:-1}
}
.hpc-slide > *{min-width:0}

.hpc-row{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;
         padding:11px 0;border-bottom:1px solid var(--border,#e6e6e6);min-width:0;flex-wrap:wrap}
.hpc-row:last-child{border-bottom:0;padding-bottom:0}
.hpc-row-t{min-width:0;flex:1 1 190px}
.hpc-row-t b{display:block;font-weight:600;font-size:13.5px;margin-bottom:3px}
.hpc-row-t span{display:block;color:var(--ink-soft,#6b7280);font-size:12px;line-height:1.5}
.hpc-row-c{flex:1 1 210px;min-width:0}

.hpc-in{width:100%;box-sizing:border-box;padding:8px 10px;font:inherit;font-size:13px;
        border:1px solid var(--border,#e6e6e6);border-radius:9px;background:var(--surface,#fff);color:inherit}
textarea.hpc-in{min-height:64px;resize:vertical;line-height:1.5}
/* Row 55 (Lane HA): picked records as chips, and the media button. */
.hpc-ids{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.hpc-chip{display:inline-flex;align-items:center;gap:4px;border:1px solid #E7D9DF;border-radius:99px;padding:3px 4px 3px 12px;background:#fff;font-size:12.5px;max-width:100%}
.hpc-chip b{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}
.hpc-chip .hpc-btn{padding:2px 8px;min-width:0;line-height:1.3}
.hpc-thumb{width:64px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #E7D9DF}
.hpc-in:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:1px}
.hpc-in.is-bad{border-color:#b4443c}

/* Colour: the swatch and the value it is, because a colour input on its own
   tells a reviewer nothing they can read back to somebody. */
.hpc-col{display:flex;align-items:center;gap:9px}
.hpc-col input{width:42px;height:32px;padding:0;border:1px solid var(--border,#e6e6e6);
               border-radius:8px;background:transparent;cursor:pointer}
.hpc-col code{font-size:12px;color:var(--ink-soft,#6b7280)}

/* ── THE PREVIEW ────────────────────────────────────────────────────────────
   The two gradients are NOT restyled here. They are the exact strings the
   server will print into the storefront's style attribute, rebuilt from the
   same five colours by the same template -- so what this box shows is what
   that attribute will hold, and a change to the shape has one place to happen.
   The type scale is the console's, not the shop's: this is a preview of the
   WORDS, the COLOURS and the ORDER at console size, and the note under it says
   so rather than claiming to be the page. */
.hpc-pv{position:sticky;top:12px;align-self:start}
.hpc-pv-in{border-radius:14px;overflow:hidden;border:1px solid var(--border,#e6e6e6)}
.hpc-sl{display:flex;min-height:150px}
.hpc-sl-t{flex:1 1 auto;padding:16px;color:#fff;display:flex;flex-direction:column;justify-content:center;gap:6px;min-width:0}
.hpc-sl-k{font-size:10.5px;letter-spacing:.09em;text-transform:uppercase;opacity:.92;font-weight:600}
.hpc-sl-h{font-size:19px;font-weight:700;line-height:1.16;letter-spacing:-.02em;margin:0;overflow-wrap:anywhere}
.hpc-sl-p{font-size:11.5px;line-height:1.5;opacity:.93;margin:0;overflow-wrap:anywhere}
.hpc-sl-b{display:inline-block;align-self:flex-start;margin-top:4px;background:#fff;color:#2A2228;
          border-radius:999px;padding:6px 13px;font-size:11.5px;font-weight:650}
.hpc-sl-i{flex:0 0 34%}
.hpc-pv-note{margin:7px 0 0;color:var(--ink-soft,#6b7280);font-size:11.5px;line-height:1.5}
.hpc-h1tag{display:inline-block;margin-inline-start:6px;padding:1px 6px;border-radius:6px;font-size:10px;
           font-weight:700;letter-spacing:.04em;border:1px solid var(--border,#e6e6e6);
           background:rgba(127,127,127,.08);color:var(--ink-soft,#6b7280);vertical-align:middle}

.hpc-slidehd{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 10px}
.hpc-slidehd b{font-size:14px;font-weight:650;margin-inline-end:auto}

.hpc-btn{padding:7px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer;font-size:12.5px}
.hpc-btn:hover:not([disabled]){background:rgba(127,127,127,.08)}
.hpc-btn[disabled]{opacity:.45;cursor:not-allowed}
.hpc-btn.is-primary{background:var(--accent,#15a85a);border-color:transparent;color:#fff}
.hpc-btn.is-bad{border-color:#b4443c;color:#8f332d}

.hpc-note{border-radius:10px;padding:11px 13px;font-size:12.5px;line-height:1.6;
          border:1px solid var(--border,#e6e6e6);background:rgba(127,127,127,.06)}
/* The note's own HEADING, and only that one. Written as `> b:first-child`
   because a bold word inside the sentence underneath inherited the block
   display and the uppercasing, and broke the sentence across three lines with
   a comma stranded at the start of one. */
.hpc-note > b:first-child{display:block;margin-bottom:3px;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
.hpc-note.is-warn{border-color:#b7791f;color:#8a5b12;background:rgba(183,121,31,.08)}
.hpc-note.is-bad{border-color:#b4443c;color:#8f332d;background:rgba(180,68,60,.08)}
.hpc-note ul{margin:6px 0 0;padding-inline-start:18px}

/* ── THE LIVE PREVIEW (Lane HL, Phase 15) ───────────────────────────────────
   The storefront's own homepage, rendered from the controls on this screen,
   with every section in it selectable. Everything is prefixed hpe- so it
   cannot reach another screen in this one-document console.

   SIZED WITH calc() AND A DECLARED SCALE, NOT BY MEASURING. Rule 4 forbids
   JavaScript that measures layout and two tests sweep for the element-measuring
   APIs by name, so nothing here asks how wide the column is. `--hpe-s` is a
   literal per breakpoint and the frame's height is calc()'d FROM it, so the
   stage and the page inside it cannot come to disagree the way two hand-kept
   numbers would. The steps leave the frame narrower than its column at every
   width; overflow:hidden is the guard, not the plan.

   The page inside is laid out at 1280px in Desktop and at 390px in Mobile,
   which is the point: `d-off` and `m-off` are media queries in the STOREFRONT's
   own stylesheet, so the only honest way to show what a Desktop or Mobile
   switch does is to give the document a viewport of that width and let its own
   CSS answer.

   ONE COLUMN UNTIL 1040px, picture first. On a phone the thing being described
   belongs above the boxes describing it — the same rule .hpc-slide above
   already follows for a slide and its preview. */
.hpe{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;min-width:0}
.hpe > *{min-width:0}
@media (min-width:1040px){.hpe{grid-template-columns:minmax(0,1fr) minmax(0,340px)}}

.hpe-hd{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px}
.hpe-hd b{font-size:14px;font-weight:650;margin-inline-end:auto}
.hpe-dev{display:inline-flex;border:1px solid var(--border,#e6e6e6);border-radius:10px;overflow:hidden}
.hpe-dev button{border:0;background:transparent;font:inherit;font-size:12px;font-weight:600;
                padding:7px 12px;cursor:pointer;color:inherit}
.hpe-dev button.on{background:rgba(127,127,127,.14)}

.hpe-stage{--hpe-s:.24;position:relative;overflow:hidden;height:520px;border:1px solid var(--border,#e6e6e6);
           border-radius:12px;background:#fff;min-width:0}
.hpe-frame{width:1280px;height:calc(520px / var(--hpe-s));border:0;display:block;
           transform:scale(var(--hpe-s));transform-origin:top left}
@media(min-width:760px){.hpe-stage{--hpe-s:.40}}
@media(min-width:1040px){.hpe-stage{--hpe-s:.40}}
@media(min-width:1280px){.hpe-stage{--hpe-s:.48}}
@media(min-width:1440px){.hpe-stage{--hpe-s:.58}}
/* Mobile is drawn at its true size — 390px needs no reduction at any console
   width, so the one view an owner reviews on a phone is the one that is exact. */
.hpe-stage.is-mob{--hpe-s:1}
.hpe-stage.is-mob .hpe-frame{width:390px;margin:0 auto}
.hpe-empty{display:grid;place-items:center;height:100%;font-size:12.5px;
           color:var(--ink-soft,#6b7280);text-align:center;padding:0 18px}

.hpe-note{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:9px 0 0}
.hpe-note.is-bad{color:#8f332d}

/* The same nineteen sections as buttons. The picture is the fast way to
   choose one; this is the way that works with a keyboard, and the way to
   reach a section that is switched off and therefore not in the picture. */
.hpe-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:11px}
.hpe-pick{padding:6px 10px;border:1px solid var(--border,#e6e6e6);border-radius:8px;background:transparent;
          color:inherit;font:inherit;font-size:12px;font-weight:600;cursor:pointer}
.hpe-pick.on{background:rgba(127,127,127,.14);border-color:rgba(127,127,127,.45)}
.hpe-pick.off{opacity:.6}
.hpe-pick.off::after{content:' · off';color:var(--ink-soft,#6b7280);font-weight:500}
.hpe-pick:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:1px}

.hpe-panel{align-self:start}
@media (min-width:1040px){.hpe-panel{position:sticky;top:12px}}
.hpe-sel{display:block;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280)}

.hpc-save{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.hpc-dirty{color:var(--ink-soft,#6b7280);font-size:12.5px;margin-inline-end:auto}
</style>

<script>
(function(){
  'use strict';

  var SCREEN = 'hpcontent';
  var GROUP  = 'Appearance';
  var TITLE  = 'Homepage content';

  /* Server state, and the working copy the boxes edit. Never the same object:
     a failed save must leave the screen showing what the owner typed, and a
     successful one must adopt what the server stored -- which is not always
     what was sent, because a refused field falls back to its default. */
  var data = null;      // the last payload from the server
  var slides = null;    // list of {key: value}
  var copy = null;      // flat key => value
  var tab = 'hero';
  var dirty = false;
  var banner = null;    // {kind, title, lines}
  var loading = false;

  function base(){
    return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'')
      + '/admin-api/homepage/content';
  }

  function esc(s){
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  /* ---------------------------------------------------------------- loading */
  function load(){
    loading = true;
    banner = null;
    render();

    fetch(base(), {credentials:'same-origin', headers:{Accept:'application/json'}})
      .then(function(r){
        if (!r.ok) throw new Error(String(r.status));
        return r.json();
      })
      .then(function(j){
        data = j;
        slides = j.slides.map(function(s){ return Object.assign({}, s.values); });
        copy = {};
        j.tabs.forEach(function(t){ t.fields.forEach(function(f){ copy[f.key] = f.value; }); });
        loading = false;
        dirty = false;
        render();
      })
      .catch(function(e){
        loading = false;
        /* Name the failure. "Could not load" is the same sentence for a 404
           from a stale route cache and a 500 from a query -- two problems with
           nothing in common but the message, and on this host the first one is
           the likely one, because a package that adds a route is inert until
           its clear_caches migration runs. */
        var why = String(e.message || e);
        banner = {kind:'is-bad', title:'Could not load', lines:[
          why === '404'
            ? 'The admin route is not registered. The cache-clearing migration for this release may not have run — check Core Updates.'
            : why === '500'
              ? 'The server errored. Check storage/logs/laravel.log for the last entry.'
              : 'The request did not complete.',
          'Reported as: ' + why
        ]};
        render();
      });
  }

  /* ----------------------------------------------------------------- fields */
  /*
   * ONE RENDERER, SWITCHING ON THE SCHEMA'S OWN TYPE VOCABULARY.
   *
   * `f` is a field as App\Services\ModuleSchema::fields() emits it: key, type,
   * label, help, options, default, value. Nothing here names a setting, so a
   * field added to HomepageContent::SLIDE_SCHEMA or ::SCHEMA appears on this
   * screen without this file changing -- which is the difference between
   * reusing the schema and copying it.
   *
   * `onset` is how the caller stores the new value, because the two callers
   * store it in different places: a slide field into slides[i], a copy field
   * into copy{}.
   */
  function field(f, value, onsetName, bad){
    var v = value === undefined || value === null ? f.default : value;
    var id = 'hpc-f-' + onsetName.replace(/[^a-z0-9]+/gi,'-');
    var help = f.help ? '<span>' + esc(f.help) + '</span>' : '';
    var badCls = bad ? ' is-bad' : '';
    var ctl;

    if (f.type === 'bool') {
      ctl = '<button type="button" class="hpc-btn' + (v ? ' is-primary' : '') + '"'
          + ' data-hpc-toggle="' + esc(onsetName) + '" role="switch" aria-checked="' + (v ? 'true' : 'false') + '">'
          + (v ? 'On' : 'Off') + '</button>';
    } else if (f.type === 'colour') {
      ctl = '<span class="hpc-col"><input type="color" value="' + esc(v) + '"'
          + ' data-hpc-set="' + esc(onsetName) + '" aria-label="' + esc(f.label) + '">'
          + '<code>' + esc(v) + '</code></span>';
    } else if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function(k){
        return '<option value="' + esc(k) + '"' + (String(k) === String(v) ? ' selected' : '') + '>'
             + esc(f.options[k]) + '</option>';
      }).join('');
      ctl = '<select class="hpc-in' + badCls + '" id="' + id + '" data-hpc-set="' + esc(onsetName) + '">' + opts + '</select>';
    } else if (f.type === 'skin') {
      /*
       * A TYPE WHOSE OPTION SET LIVES IN ANOTHER REGISTRY, which is why it is
       * its own branch and not a `select`. ModuleSchema::fields() deliberately
       * emits `options: null` for it -- its own docblock says so: the module's
       * SCHEMA declares the type, App\Support\GridSkins declares the cards, and
       * the screen is handed that list once at the top of the payload rather
       * than repeated inside nineteen fields. Without this branch the field
       * would fall through to the text box below and an owner could type a skin
       * name the cast then refuses -- a control that looks writable and is not,
       * which is the shape this framework exists to end.
       */
      var sopts = (live.skins || []).map(function(s){
        return '<option value="' + esc(s.key) + '"' + (String(s.key) === String(v) ? ' selected' : '') + '>'
             + esc(s.label) + '</option>';
      }).join('');
      ctl = '<select class="hpc-in' + badCls + '" id="' + id + '" data-hpc-set="' + esc(onsetName) + '">' + sopts + '</select>';
    } else if (f.type === 'ids' && f.options && f.options.of) {
      /*
       * A LIST OF RECORDS PICKED BY NAME, IN ORDER (Row 55, Lane HA).
       *
       * The value is ModuleSchema's `ids` type -- a comma list of ids, cast
       * server-side to positive integers and capped -- and the names come from
       * the payload's `picks`, under the list the field's own `options.of`
       * names. So the owner picks "COSRX", never types 14. One chip per pick
       * with ↑ ↓ ×; a field whose cap is 1 is a plain select.
       */
      var list = (data.picks && data.picks[f.options.of]) || [];
      var names = {};
      list.forEach(function(r){ names[String(r.id)] = r.name; });
      var cap = +(f.options.cap || 24);
      var chosen = String(v || '').split(',').filter(function(x){ return /^[0-9]+$/.test(x); });

      if (cap === 1) {
        ctl = '<select class="hpc-in' + badCls + '" id="' + id + '" data-hpc-set="' + esc(onsetName) + '">'
            + '<option value="">— none —</option>'
            + list.map(function(r){
                return '<option value="' + r.id + '"' + (String(r.id) === chosen[0] ? ' selected' : '') + '>' + esc(r.name) + '</option>';
              }).join('')
            + '</select>';
      } else {
        ctl = '<div class="hpc-ids">'
            + (chosen.length ? chosen.map(function(x, i){
                return '<span class="hpc-chip"><b>' + esc(names[x] || ('#' + x + ' — no longer listed')) + '</b>'
                  + '<button type="button" class="hpc-btn" data-hpc-ids="' + esc(onsetName) + '|' + i + '|-1"' + (i === 0 ? ' disabled' : '') + ' aria-label="Move up">↑</button>'
                  + '<button type="button" class="hpc-btn" data-hpc-ids="' + esc(onsetName) + '|' + i + '|1"' + (i === chosen.length - 1 ? ' disabled' : '') + ' aria-label="Move down">↓</button>'
                  + '<button type="button" class="hpc-btn" data-hpc-ids="' + esc(onsetName) + '|' + i + '|x" aria-label="Remove">×</button></span>';
              }).join('') : '<span class="hpc-sub">Nothing picked.</span>')
            + (chosen.length < cap
                ? '<select class="hpc-in" id="' + id + '" data-hpc-ids-add="' + esc(onsetName) + '"><option value="">+ Add…</option>'
                  + list.filter(function(r){ return chosen.indexOf(String(r.id)) < 0; }).map(function(r){
                      return '<option value="' + r.id + '">' + esc(r.name) + '</option>';
                    }).join('') + '</select>'
                : '<span class="hpc-sub">' + cap + ' is the most this row shows.</span>')
            + '</div>';
      }
    } else if (f.options && f.options.picker === 'media') {
      /* A picture chosen from the Media Library (Row 55, Lane HA): the box
         holds its address, the button opens the shop's own picker, and the
         server scheme-checks whatever lands here before it is drawn. */
      ctl = '<div class="hpc-ids"><input type="text" class="hpc-in' + badCls + '" id="' + id + '" value="' + esc(v) + '"'
          + ' data-hpc-set="' + esc(onsetName) + '">'
          + '<button type="button" class="hpc-btn" data-hpc-media="' + esc(onsetName) + '" data-hpc-media-label="' + esc(f.label) + '">Choose from Media Library</button>'
          + (v ? '<img class="hpc-thumb" src="' + esc(v) + '" alt="">' : '')
          + '</div>';
    } else if (f.type === 'textarea') {
      ctl = '<textarea class="hpc-in' + badCls + '" id="' + id + '" data-hpc-set="' + esc(onsetName) + '">'
          + esc(v) + '</textarea>';
    } else {
      ctl = '<input type="text" class="hpc-in' + badCls + '" id="' + id + '" value="' + esc(v) + '"'
          + ' data-hpc-set="' + esc(onsetName) + '">';
    }

    return '<div class="hpc-row"><div class="hpc-row-t">'
         + '<b><label for="' + id + '">' + esc(f.label) + '</label></b>' + help
         + '</div><div class="hpc-row-c">' + ctl + '</div></div>';
  }

  /* ---------------------------------------------------------------- preview */
  /*
   * THE PREVIEW CANNOT DRIFT FROM WHAT SHIPS, AND NOT BECAUSE ANYONE PROMISED.
   *
   * The two CSS shapes are HomepageContent::CSS, sent down in the payload with
   * the field keys as {placeholders}. This substitutes them; the server
   * substitutes the same string for the storefront. There is no second copy of
   * the shape to go stale, and this file names not one colour key -- add a
   * sixth colour to the schema and both sides pick it up.
   *
   * Rebuilt on every keystroke rather than re-fetched, which is what makes it
   * live.
   */
  function css(s, which){
    var out = data.css[which];

    data.slide_fields.forEach(function(f){
      out = out.split('{' + f.key + '}').join(s[f.key] === undefined ? '' : s[f.key]);
    });

    return out;
  }

  /*
   * The slide as it will look, and NOTHING ELSE ON THE SCREEN.
   *
   * ── WHY THIS IS NOT A FULL REPAINT ─────────────────────────────────────────
   *
   * The first version of this screen called render() on every keystroke, the
   * way the Newsletter screen next door does, and put the caret back
   * afterwards. It is wrong twice. It loses the selection often enough to be
   * felt in a headline somebody is editing in the middle of -- and it THROWS:
   * replacing #content while the focused input is being blurred raises "the
   * node to be removed is no longer a child of this node", which kills the
   * handler and leaves the screen half painted. Caught in a browser, not
   * reasoned about.
   *
   * So a keystroke rewrites this one preview and nothing else. render() is for
   * STRUCTURAL changes only -- adding, removing, reordering, changing tab,
   * saving -- where no box is being typed into.
   */
  function previewBody(s, i){
    var lines = String(s.heading || '').split('\n').map(esc).join('<br>');

    return '<div class="hpc-pv-in">'
      + '<div class="hpc-sl" style="background:' + esc(css(s, 'gradient')) + '">'
      +   '<div class="hpc-sl-t">'
      +     (s.kicker ? '<span class="hpc-sl-k">' + esc(s.kicker) + '</span>' : '')
      +     '<p class="hpc-sl-h">' + (lines || '<em>No headline</em>') + '</p>'
      +     (s.text ? '<p class="hpc-sl-p">' + esc(s.text) + '</p>' : '')
      +     (s.button ? '<span class="hpc-sl-b">' + esc(s.button) + '</span>' : '')
      +   '</div>'
      +   '<div class="hpc-sl-i" style="background:' + esc(css(s, 'panel')) + '"></div>'
      + '</div></div>'
      + '<p class="hpc-pv-note">'
      + (i === 0
          ? 'Slide 1 is first on the page, and its headline is this page&rsquo;s only &lt;h1&gt; — the one line search engines read as what this shop is.'
          : 'Shown after slide ' + i + '. Its headline is an &lt;h2&gt;.')
      + ' The colours and wording are exactly what will be saved; the type is at console size, not the shop&rsquo;s.</p>';
  }

  function preview(s, i){
    return '<div class="hpc-pv" data-hpc-pv="' + i + '">' + previewBody(s, i) + '</div>';
  }

  /** Redraw one slide's preview in place, after a keystroke. */
  function refreshPreview(i){
    var el = document.querySelector('[data-hpc-pv="' + i + '"]');
    if (el) el.innerHTML = previewBody(slides[i], i);
  }

  /* ------------------------------------------------------------ live edit */
  /*
   * ── LIVE EDITING OF HOMEPAGE SECTIONS (Lane HL, Phase 15) ────────────────
   *
   * Phase 15's last unticked line is "Live editing of homepage sections,
   * reusing the settings schemas", and both halves of it already existed with
   * nothing joining them:
   *
   *   · the PICTURE — POST /admin-api/homepage/preview renders the real
   *     storefront homepage from an arrangement nobody has saved (Lane P1);
   *   · the CONTROLS — App\Services\HomepageSections::SECTION_SCHEMA is the
   *     ModuleSchema description of the three controls a section carries, and
   *     SECTION_POLICY is how they cast. The same triple every other settings
   *     screen in this console is drawn from.
   *
   * What was missing was the SEAM: pointing at a section in the picture and
   * getting that section's own controls. That is all this tab is.
   *
   * IT NAMES NOT ONE SETTING. Every control below is drawn by field() — the
   * renderer this screen already had — from a field payload the server built
   * with ModuleSchema::tabs(). Add a field to SECTION_SCHEMA and it appears
   * here without a line of this file changing, which is the difference between
   * reusing a schema and copying one.
   *
   * IT ADDS NO SETTING AND NO WRITER. The three controls are the three
   * HomepageSections has always stored, and Save posts them to
   * POST /admin-api/homepage — the endpoint that has always written them. So
   * applying this release moves nothing on the shop until somebody moves a
   * control here and presses Save.
   *
   * SELECTION IS A CLASS, NOT A MEASUREMENT. The server renders the preview
   * with a hook on every section wrapper; this file outlines the selected one
   * with a stylesheet it puts inside the frame. Nothing here asks an element
   * how big it is — rule 4, and two tests sweep for those APIs by name.
   *
   * THE FRAME IS SANDBOXED WITHOUT allow-scripts, as the Appearance → Homepage
   * preview card next door is. The storefront's own JavaScript has no business
   * running inside the console. allow-same-origin is what lets this file reach
   * in to outline a section; the two flags that together defeat a sandbox —
   * allow-scripts AND allow-same-origin — are never both set.
   */
  var live = {
    loading: false,   // fetching the section list
    loaded: false,    // the list has arrived at least once
    busy: false,      // a redraw is in flight
    again: false,     // ...and another was asked for while it was
    err: '',
    html: '',         // the last document the server drew
    dev: 'desk',
    sel: null,        // the selected section key
    rows: [],         // the working copy the controls edit
    sections: [],     // what the server last answered: labels, notes, controls
    skins: [],        // the grid-skin option set, which lives in its own registry
    mark: 'kbb-pvsec',
    dirty: false,
    reach: true       // false when the browser will not let us into the frame
  };

  /** The class the console adds to the one section that is selected. */
  var LIVE_ON = 'kbb-pvsel';

  function liveBase(){
    return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/homepage';
  }

  /*
   * THE STYLESHEET THE PREVIEW IS DECORATED WITH, AND WHY IT IS A STYLESHEET.
   *
   * An outline that follows the element needs no recomputation when the frame
   * is redrawn, when the viewport switches between 1280 and 390, or when a
   * section changes height — which is what rule 4 means by preferring a
   * rendered-once CSS answer to a scripted one.
   *
   * `pointer-events:none` on the shop's own links and controls is the other
   * half. The frame is sandboxed without allow-scripts, but a plain <a> still
   * navigates, and a preview that wandered off to a product page when you
   * clicked a card would be useless. With the anchors inert the click lands on
   * the section behind them, which is the thing being selected anyway.
   */
  function liveCss(){
    var m = '.' + live.mark;

    return m + '{cursor:pointer}'
      + m + ':hover{outline:2px dashed rgba(193,62,99,.55);outline-offset:-2px}'
      + '.' + LIVE_ON + '{outline:3px solid #C13E63;outline-offset:-3px}'
      + 'a,button,input,select,textarea,label,video{pointer-events:none}';
  }

  /** Why a request did not land, in words that name the likely cause. */
  function liveWhy(e){
    var why = String((e && e.message) || e);

    return why === '404'
      ? 'The live-preview route is not registered. The cache-clearing migration for this release may not have run — check Store → Core Updates.'
      : why === '500'
        ? 'The server errored while drawing the homepage. Check storage/logs/laravel.log for the last entry.'
        : 'The request did not complete (' + why + ').';
  }

  function liveRowOf(s){
    return {key: s.key, desktop: !!s.desktop, mobile: !!s.mobile, skin: s.skin};
  }

  function liveRow(key){
    for (var i = 0; i < live.rows.length; i++) { if (live.rows[i].key === key) return live.rows[i]; }
    return null;
  }

  function liveSection(key){
    for (var i = 0; i < live.sections.length; i++) { if (live.sections[i].key === key) return live.sections[i]; }
    return null;
  }

  function liveValue(key, field){
    var row = liveRow(key);
    return row ? row[field] : undefined;
  }

  /** The section list and the grid-skin registry, from the endpoint that owns them. */
  function loadLive(){
    live.loading = true;
    live.err = '';
    render();

    fetch(liveBase(), {credentials:'same-origin', headers:{Accept:'application/json'}})
      .then(function(r){ if (!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(function(j){
        live.skins = j.skins || [];
        live.rows = (j.sections || []).map(liveRowOf);
        live.loading = false;
        live.loaded = true;
        drawLive(false);
      })
      .catch(function(e){
        live.loading = false;
        live.err = liveWhy(e);
        render();
      });
  }

  /*
   * Redraw the picture from the rows as they stand. WRITES NOTHING.
   *
   * `quiet` is the difference between a structural repaint and a keystroke,
   * and it is load-bearing rather than an optimisation: replacing #content
   * while the control somebody just used is focused THROWS ("the node to be
   * removed is no longer a child of this node") and leaves the screen half
   * painted — the same fault previewBody() above records being caught in a
   * browser. So a change made in the panel patches the frame and the list and
   * leaves the panel alone; only a tab change, a selection, an error or a save
   * repaints.
   */
  function drawLive(quiet){
    if (live.busy) { live.again = true; return; }

    live.busy = true;
    live.err = '';

    if (quiet) { liveNotePaint(); } else { render(); }

    fetch(liveBase() + '/live', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json', 'X-XSRF-TOKEN': window.uToken ? window.uToken() : '', Accept:'application/json'},
      body: JSON.stringify({sections: live.rows})
    })
      .then(function(r){
        /* A 422 carries the server's OWN sentence -- the section key it could
           not place -- and throwing on the status would replace it with a
           number. Every other failure has no body worth reading. */
        if (r.status === 422) { return r.json(); }
        if (!r.ok) { throw new Error(String(r.status)); }

        return r.json();
      })
      .then(function(j){
        live.busy = false;

        if (!j.ok) {
          live.err = j.error || 'The server refused to draw that arrangement.';
          /* A refusal during a quiet redraw is reported in the line under the
             picture and NOT by repainting: the control that caused it is the
             one the owner is standing on, and replacing #content under it both
             loses their place and throws. */
          if (quiet) { liveNotePaint(); } else { render(); }
          return;
        }

        live.html = j.html;
        live.mark = j.select_class || live.mark;
        live.sections = j.sections || [];

        /* The order the server SETTLED, not the order that was posted: a
           nested row is put back behind its host on read, so adopting the
           answer keeps the list under the picture agreeing with the picture. */
        live.rows = live.sections.map(liveRowOf);

        if (live.sel && !liveSection(live.sel)) { live.sel = null; }

        if (live.again) { live.again = false; drawLive(quiet); return; }

        if (quiet) { liveMount(); liveListPaint(); liveNotePaint(); } else { render(); }
      })
      .catch(function(e){
        live.busy = false;
        live.again = false;
        live.err = liveWhy(e);
        if (quiet) { liveNotePaint(); } else { render(); }
      });
  }

  /* ------------------------------------------------------------ the frame */
  function liveFrame(){
    return document.querySelector('#hpe-frame');
  }

  function liveDoc(){
    var f = liveFrame();
    try { return f && f.contentDocument ? f.contentDocument : null; } catch (e) { return null; }
  }

  function liveMount(){
    var f = liveFrame();
    if (!f || !live.html) return;

    f.onload = function(){ liveDecorate(); };

    /* THE DOCUMENT IS ASSIGNED, NEVER INTERPOLATED. Eighty-odd kilobytes of
       the shop's own HTML dropped into a string that is being built is one
       stray quote away from taking the whole paint with it; assigning it to
       .srcdoc after the paint puts it somewhere it is a value and not source. */
    f.srcdoc = live.html;
  }

  function liveDecorate(){
    var doc = liveDoc();

    if (!doc || !doc.head || !doc.body) {
      /* Said out loud rather than swallowed: without reach into the frame the
         picture still draws and only the clicking is lost, and the note below
         it points at the list of names instead. */
      live.reach = false;
      liveNotePaint();
      return;
    }

    live.reach = true;

    var st = doc.createElement('style');
    st.appendChild(doc.createTextNode(liveCss()));
    doc.head.appendChild(st);

    doc.addEventListener('click', function(e){
      // The preview is a picture, not a shop.
      e.preventDefault();

      var t = e.target;
      var el = t && t.closest ? t.closest('.' + live.mark) : null;
      if (!el) return;

      var key = liveKeyOf(el);
      if (key) selectSection(key);
    }, true);

    liveHighlight();
  }

  /** Which section an element in the frame belongs to, read off its class. */
  function liveKeyOf(el){
    var m = new RegExp('(?:^|\\s)' + live.mark + '-([a-z0-9_-]+)(?:\\s|$)').exec(el.className || '');
    return m ? m[1] : null;
  }

  function liveHighlight(){
    var doc = liveDoc();
    if (!doc || !doc.body) return;

    var on = doc.querySelectorAll('.' + LIVE_ON);
    for (var i = 0; i < on.length; i++) { on[i].classList.remove(LIVE_ON); }

    if (!live.sel) return;

    var el = doc.querySelector('.' + live.mark + '-' + live.sel);
    if (el) { el.classList.add(LIVE_ON); }
  }

  function selectSection(key){
    live.sel = key;
    liveHighlight();
    livePanelPaint();
    liveListPaint();
  }

  /* ------------------------------------------------------------- painting */
  function liveNoteText(){
    var where = live.dev === 'mob'
      ? 'Drawn at 390px, its true size.'
      : 'Laid out at 1280px and drawn reduced to fit this column.';

    if (live.err) return live.err;
    if (live.busy) return 'Redrawing the homepage from these controls… ' + where;

    if (!live.reach) {
      return 'The picture is drawn, but this browser will not let the console reach inside the frame, '
        + 'so clicking a section in it does nothing. Pick one from the names below instead. ' + where;
    }

    return 'This is the storefront’s own homepage, rendered from the controls on this screen — not a diagram of it. '
      + 'Nothing is saved by looking, and the shop still shows what it showed before. ' + where;
  }

  function liveNotePaint(){
    var n = document.querySelector('#hpe-note');
    if (!n) return;
    n.textContent = liveNoteText();
    n.className = 'hpe-note' + (live.err ? ' is-bad' : '');
  }

  function liveListHTML(){
    return live.sections.map(function(s){
      var row = liveRow(s.key) || s;
      var off = !row.desktop && !row.mobile;

      return '<button type="button" class="hpe-pick' + (live.sel === s.key ? ' on' : '') + (off ? ' off' : '') + '"'
        + ' data-hpe-pick="' + esc(s.key) + '" aria-pressed="' + (live.sel === s.key ? 'true' : 'false') + '">'
        + esc(s.label) + '</button>';
    }).join('');
  }

  function liveListPaint(){
    var l = document.querySelector('#hpe-list');
    if (!l) return;
    l.innerHTML = liveListHTML();
    bind();
  }

  /** The label this screen gives a tab of its own, for the wording link. */
  function liveTabLabel(key){
    if (key === 'hero') return 'Hero slider';

    var found = 'Other wording';
    (data.tabs || []).forEach(function(t){ if (t.key === key) found = t.label; });

    return found;
  }

  function livePanelHTML(){
    var s = live.sel ? liveSection(live.sel) : null;

    if (!s) {
      return '<div class="hpc-card"><p class="hpc-h">Nothing selected</p>'
        + '<p class="hpc-sub">Click any section in the picture — or pick one from the names under it — and that section’s own '
        + 'controls open here. They are the same controls <b>Appearance → Homepage</b> carries, drawn from the same schema.</p>'
        + '<p class="hpc-sub">Nothing is saved by looking or by moving a control. The shop changes when you press '
        + '<b>Save changes</b> and not before.</p></div>';
    }

    var controls = (s.tabs || []).map(function(t){
      return '<p class="hpc-sub" style="margin:0 0 6px">' + esc(t.description) + '</p>'
        + (t.fields || []).map(function(f){
            return field(f, liveValue(s.key, f.key), 'sec.' + s.key + '.' + f.key, false);
          }).join('');
    }).join('');

    var note = s.note ? '<p class="hpc-sub" style="margin-top:10px">' + esc(s.note) + '</p>' : '';

    /* THE WORDS ARE LINKED TO, NOT REPEATED. copyTab() below states the rule
       this follows: one sentence with two boxes is a sentence that changes
       depending on which box you touched last. Each of these has exactly one
       control and one writer already, on a tab of this same screen. */
    var words = s.words
      ? '<div class="hpc-note" style="margin-top:12px"><b>What it says</b>'
        + 'The wording of this section — ' + esc(s.words.label) + ' — is edited on the <b>'
        + esc(liveTabLabel(s.words.tab)) + '</b> tab of this screen, where it already has one box and one writer. '
        + 'It is not repeated here.'
        + '<div style="margin-top:8px"><button class="hpc-btn" data-hpe-words="'
        + esc(s.words.tab) + '|' + esc(s.words.key || '') + '">Edit the wording</button></div></div>'
      : '';

    return '<div class="hpc-card">'
      + '<span class="hpe-sel">Selected section</span>'
      + '<p class="hpc-h" style="margin-top:4px">' + esc(s.label) + '</p>'
      + '<p class="hpc-sub" style="margin-bottom:8px">' + esc(s.description) + '</p>'
      + controls + note + words
      + '</div>'
      + '<div class="hpc-card" style="margin-top:16px"><div class="hpc-save">'
      + '<span class="hpc-dirty hpe-dirty">' + (live.dirty ? 'Unsaved changes' : 'Saved') + '</span>'
      + '<button class="hpc-btn" data-hpe-discard>Discard</button>'
      + '<button class="hpc-btn is-primary" data-hpe-save>Save changes</button>'
      + '</div></div>';
  }

  function livePanelPaint(){
    var p = document.querySelector('#hpe-panel');
    if (!p) return;
    p.innerHTML = livePanelHTML();
    bind();
  }

  function liveTab(){
    if (live.loading) {
      return '<div class="hpc-card"><p class="hpc-sub">Drawing the homepage&hellip;</p></div>';
    }

    if (live.err && !live.html) {
      return '<div class="hpc-note is-bad"><b>Could not draw the homepage</b>' + esc(live.err) + '</div>'
        + '<div class="hpc-card"><button class="hpc-btn" data-hpe-reload>Try again</button></div>';
    }

    var stage = live.html
      ? '<iframe class="hpe-frame" id="hpe-frame" title="Homepage preview" sandbox="allow-same-origin"></iframe>'
      : '<div class="hpe-empty">Nothing drawn yet.</div>';

    return '<div class="hpe">'
      + '<div class="hpc-card">'
      +   '<div class="hpe-hd"><b>The homepage, as these controls would leave it</b>'
      +     '<span class="hpe-dev">'
      +       '<button type="button" data-hpe-dev="desk" class="' + (live.dev === 'desk' ? 'on' : '') + '">Desktop · 1280</button>'
      +       '<button type="button" data-hpe-dev="mob" class="' + (live.dev === 'mob' ? 'on' : '') + '">Mobile · 390</button>'
      +     '</span>'
      +     '<button class="hpc-btn" data-hpe-redraw' + (live.busy ? ' disabled' : '') + '>'
      +       (live.busy ? 'Drawing…' : 'Redraw') + '</button>'
      +   '</div>'
      +   '<div class="hpe-stage' + (live.dev === 'mob' ? ' is-mob' : '') + '">' + stage + '</div>'
      +   '<p class="hpe-note' + (live.err ? ' is-bad' : '') + '" id="hpe-note">' + esc(liveNoteText()) + '</p>'
      +   '<div class="hpe-list" id="hpe-list">' + liveListHTML() + '</div>'
      + '</div>'
      + '<div class="hpe-panel" id="hpe-panel">' + livePanelHTML() + '</div>'
      + '</div>';
  }

  /* ---------------------------------------------------------------- saving */
  /*
   * THE WRITER IS THE ONE THAT WAS ALREADY THERE.
   *
   * POST /admin-api/homepage is the endpoint Appearance → Homepage has always
   * saved these three values through — same validation, same
   * SECTION_SCHEMA cast, same four cache keys forgotten afterwards. This tab
   * adds a way to reach the controls, not a second way to store them, which is
   * what keeps AdminConsoleWriteTokenTest's question ("which writer stands
   * behind this box?") answerable for every control it draws.
   */
  function liveSave(button){
    button.disabled = true;
    banner = null;

    fetch(liveBase(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json', 'X-XSRF-TOKEN': window.uToken ? window.uToken() : '', Accept:'application/json'},
      body: JSON.stringify({sections: live.rows})
    })
      .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, body:j}; }); })
      .then(function(res){
        button.disabled = false;

        if (!res.ok || !res.body.ok) {
          banner = {kind:'is-bad', title:'Not saved', lines:[
            (res.body && res.body.error) ? res.body.error : 'The server refused the change.'
          ]};
          render();
          return;
        }

        /* Adopt what the server STORED, not what was sent: a refused skin falls
           back to the section's own default on the way in, and a screen that
           went on showing the rejected value would be telling the owner their
           change is live when it is not. */
        live.rows = (res.body.sections || []).map(liveRowOf);
        live.dirty = false;

        banner = {kind:'', title:'Saved', lines:['The homepage is live with these sections now.']};
        if (window.toast) window.toast('Homepage sections saved');

        drawLive(false);
      })
      .catch(function(e){
        button.disabled = false;
        banner = {kind:'is-bad', title:'Not saved', lines:['The request did not complete: ' + String(e.message || e)]};
        render();
      });
  }

  /* ------------------------------------------------------------------ paint */
  function heroTab(){
    if (!slides.length) {
      return '<div class="hpc-card"><p class="hpc-h">No slides</p>'
        + '<p class="hpc-sub">The hero band will not be rendered at all, and the page&rsquo;s &lt;h1&gt; falls back to the shop&rsquo;s own title. '
        + 'That is a supported choice, not a broken state — the storefront has always handled an empty slider.</p>'
        + '<div class="hpc-save" style="margin-top:12px">'
        + '<button class="hpc-btn" data-hpc-add>Add a slide</button>'
        + '<button class="hpc-btn" data-hpc-reset>Restore the three that shipped</button>'
        + '</div></div>';
    }

    var cards = slides.map(function(s, i){
      var controls = data.slide_fields.map(function(f){
        return field(f, s[f.key], i + '.' + f.key, false);
      }).join('');

      return '<div class="hpc-card"><div class="hpc-slidehd">'
        + '<b>Slide ' + (i + 1) + (i === 0 ? '<span class="hpc-h1tag">carries the h1</span>' : '') + '</b>'
        + '<button class="hpc-btn" data-hpc-move="' + i + '|-1"' + (i === 0 ? ' disabled' : '') + ' aria-label="Move slide up">&uarr;</button>'
        + '<button class="hpc-btn" data-hpc-move="' + i + '|1"' + (i === slides.length - 1 ? ' disabled' : '') + ' aria-label="Move slide down">&darr;</button>'
        + '<button class="hpc-btn is-bad" data-hpc-remove="' + i + '">Remove</button>'
        + '</div>'
        + '<div class="hpc-slide"><div>' + controls + '</div>' + preview(s, i) + '</div></div>';
    }).join('');

    return cards
      + '<div class="hpc-card"><div class="hpc-save">'
      + '<button class="hpc-btn" data-hpc-add' + (slides.length >= data.max_slides ? ' disabled' : '') + '>Add a slide</button>'
      + '<button class="hpc-btn" data-hpc-reset>Restore the three that shipped</button>'
      + '<span class="hpc-sub">' + slides.length + ' of ' + data.max_slides + ' slides.</span>'
      + '</div></div>';
  }

  function copyTab(){
    /* (2.60.370) One tab's fields at a time: a second tab (Big savings
       bundles) arrived, and drawing every tab under every button would show
       the bundles controls under "Other wording" too. */
    return data.tabs.filter(function(t){ return t.key === tab; }).map(function(t){
      return '<div class="hpc-card"><p class="hpc-h">' + esc(t.label) + '</p>'
        + '<p class="hpc-sub" style="margin-bottom:8px">' + esc(t.description) + '</p>'
        + t.fields.map(function(f){ return field(f, copy[f.key], 'copy.' + f.key, false); }).join('')
        + '</div>';
    }).join('')
    + '<div class="hpc-note"><b>Elsewhere on this page</b>'
    + 'The brands strip&rsquo;s note (&ldquo;' + esc(data.claims_elsewhere) + '&rdquo;) and the three trust-row claims are edited on '
    + '<b>Store &rarr; Business Details &rarr; Claims</b>, where every statement this shop makes about itself already lives — the same boxes '
    + 'the checkout and the product page read. They are not repeated here: one sentence with two boxes is a sentence that changes '
    + 'depending on which box you touched last.</div>';
  }

  function render(){
    var el = document.querySelector('#content');
    if (!el) return;

    if (loading) {
      el.innerHTML = '<div class="wrap"><div class="page-head"><h2>Homepage content</h2><p>Loading&hellip;</p></div></div>';
      return;
    }

    if (!data) {
      el.innerHTML = '<div class="wrap"><div class="hpc-wrap">' + bannerHTML()
        + '<div class="hpc-card"><button class="hpc-btn" data-hpc-reload>Try again</button></div></div></div>';
      bind();
      return;
    }

    el.innerHTML = '<div class="wrap"><div class="hpc-wrap">'
      + '<div class="hpc-card"><p class="hpc-h">Homepage content</p>'
      + '<p class="hpc-sub">The words on the homepage. Which sections appear, and on which devices, is set next door on '
      + '<b>Appearance &rarr; Homepage</b>; this screen decides what the ones you keep actually say &mdash; and the '
      + '<b>Live preview</b> tab shows the real page, where clicking a section opens that section&rsquo;s own controls.</p>'
      + '<p class="hpc-sub">Everything here starts as the wording the shop shipped with, so leaving it alone changes nothing. '
      + 'Clearing a box means <b>say nothing</b>: the line is dropped rather than printed empty.</p></div>'
      + bannerHTML()
      + '<div class="hpc-tabs">'
      +   '<button class="hpc-tab' + (tab === 'hero' ? ' on' : '') + '" data-hpc-tab="hero">Hero slider</button>'
      +   data.tabs.map(function(t){
            return '<button class="hpc-tab' + (tab === t.key ? ' on' : '') + '" data-hpc-tab="' + esc(t.key) + '">' + esc(t.label) + '</button>';
          }).join('')
      +   '<button class="hpc-tab' + (tab === 'live' ? ' on' : '') + '" data-hpc-tab="live">Live preview</button>'
      + '</div>'
      + (tab === 'hero' ? heroTab() : tab === 'live' ? liveTab() : copyTab())
      /* The live tab carries its OWN save bar, beside the controls it saves and
         posting to the endpoint that owns those three values. Drawing this one
         under it as well would put two Save buttons on one screen writing two
         different things, which is how an owner comes to press the wrong one. */
      + (tab === 'live' ? '' :
          '<div class="hpc-card"><div class="hpc-save">'
        +   '<span class="hpc-dirty">' + (dirty ? 'Unsaved changes' : 'Saved') + '</span>'
        +   '<button class="hpc-btn" data-hpc-reload>Discard</button>'
        +   '<button class="hpc-btn is-primary" data-hpc-save>Save changes</button>'
        + '</div></div>')
      + '</div></div>';

    bind();

    // The preview document is assigned after the paint, never built into it.
    if (tab === 'live') { liveMount(); }
  }

  function bannerHTML(){
    if (!banner) return '';
    return '<div class="hpc-note ' + banner.kind + '"><b>' + esc(banner.title) + '</b>'
      + '<ul>' + banner.lines.map(function(l){ return '<li>' + esc(l) + '</li>'; }).join('') + '</ul></div>';
  }

  /* ------------------------------------------------------------------- bind */
  /*
   * Store a value and say so, WITHOUT redrawing the box it came from.
   *
   * The dirty word is patched in place rather than repainted for the reason
   * given above previewBody(): replacing #content under a focused input throws
   * and loses the caret. render() sets the same word for everything that does
   * go through a repaint.
   */
  function setValue(name, value){
    if (name.indexOf('sec.') === 0) {
      /*
       * A SECTION CONTROL, WHICH IS A THIRD PLACE A VALUE CAN LIVE.
       *
       * Named `sec.<key>.<field>` and handled FIRST, because the slide branch
       * below reads its first segment as an index: `slides[+'sec']` is
       * `slides[NaN]`, which is undefined, and assigning through it throws and
       * takes the handler with it. slideIndexOf() is the same guard on the
       * other two readers of this name.
       */
      var s = name.split('.');
      var row = liveRow(s[1]);
      if (row) row[s[2]] = value;

      live.dirty = true;

      var word = document.querySelector('.hpe-dirty');
      if (word) word.textContent = 'Unsaved changes';

      /* THE "LIVE" IN LIVE EDITING: the picture is redrawn from the controls
         as they now stand, quietly -- the panel is not repainted, so the
         control that was just used keeps the focus and its place. */
      drawLive(true);

      return;
    }

    if (name.indexOf('copy.') === 0) {
      copy[name.slice(5)] = value;
    } else {
      var parts = name.split('.');
      slides[+parts[0]][parts[1]] = value;
    }

    dirty = true;

    var el = document.querySelector('.hpc-dirty');
    if (el) el.textContent = 'Unsaved changes';
  }

  /**
   * The slide a control name belongs to, or null for one that belongs to no
   * slide -- a `copy.<key>` name and a `sec.<key>.<field>` name alike.
   *
   * NOTE THE SHAPE OF THE EXAMPLES. This screen may not NAME a setting, even
   * in prose: HomepageContentEditorTest scans the script for every key the
   * server sends and fails if one appears, because a key in this file is how a
   * second list of controls starts. Risk ▒31 in the other direction -- a scan
   * that reads prose as code -- and the answer is to write the placeholder
   * rather than to weaken the scan.
   *
   * Written as a test on the first segment rather than as "not copy.", which is
   * what stood here: that form answered NaN for anything that was neither, and
   * NaN reached slides[] and refreshPreview() as an index.
   */
  function slideIndexOf(name){
    var head = String(name).split('.')[0];

    return /^[0-9]+$/.test(head) ? +head : null;
  }

  function bind(){
    var el = document.querySelector('#content');
    if (!el) return;

    el.querySelectorAll('[data-hpc-tab]').forEach(function(b){
      b.onclick = function(){
        tab = b.dataset.hpcTab;
        render();

        /* The picture is fetched the first time the tab is opened and not
           before: it is a whole homepage render on the server, and a screen
           that is here for the wording should not pay for one. */
        if (tab === 'live' && !live.loaded && !live.loading) { loadLive(); }
      };
    });

    el.querySelectorAll('[data-hpc-set]').forEach(function(input){
      var name = input.dataset.hpcSet;
      var slide = slideIndexOf(name);

      input.oninput = function(){
        setValue(name, input.value);

        /* A colour input shows its value beside it, and that label is the only
           part of the row that can go stale. */
        var code = input.parentNode && input.parentNode.querySelector('code');
        if (code) code.textContent = input.value;

        /* The preview follows the keystroke -- that is the "live" in live
           editing -- and the box being typed into is not touched. */
        if (slide !== null) refreshPreview(slide);
      };
    });

    /* Row 55 (Lane HA): the picked-records control and the media button. Each
       rewrites the comma list through setValue() -- the one way a value moves
       on this screen -- and repaints, since the chips ARE the value. */
    function idsOf(name){
      var cur = name.indexOf('copy.') === 0 ? copy[name.slice(5)] : '';
      return String(cur || '').split(',').filter(function(x){ return /^[0-9]+$/.test(x); });
    }
    el.querySelectorAll('[data-hpc-ids]').forEach(function(b){
      b.onclick = function(){
        var parts = b.dataset.hpcIds.split('|');
        var list = idsOf(parts[0]), i = +parts[1];
        if (parts[2] === 'x') { list.splice(i, 1); }
        else {
          var j = i + (+parts[2]);
          if (j < 0 || j >= list.length) return;
          var t = list[i]; list[i] = list[j]; list[j] = t;
        }
        setValue(parts[0], list.join(','));
        render();
      };
    });
    el.querySelectorAll('[data-hpc-ids-add]').forEach(function(sel){
      sel.onchange = function(){
        if (!sel.value) return;
        var name = sel.dataset.hpcIdsAdd, list = idsOf(name);
        if (list.indexOf(sel.value) < 0) list.push(sel.value);
        setValue(name, list.join(','));
        render();
      };
    });
    el.querySelectorAll('[data-hpc-media]').forEach(function(b){
      b.onclick = function(){
        if (typeof window.kbbPickMedia !== 'function') return;
        var name = b.dataset.hpcMedia;
        window.kbbPickMedia({ title: b.dataset.hpcMediaLabel || 'Photo', note: 'The photo for this homepage panel.', folder: 'appearance',
          onPick: function(urls){ if (!urls || !urls[0]) return; setValue(name, String(urls[0])); render(); } });
      };
    });

    el.querySelectorAll('[data-hpc-toggle]').forEach(function(b){
      b.onclick = function(){
        var name = b.dataset.hpcToggle;
        var now = b.getAttribute('aria-checked') === 'true';
        setValue(name, !now);
        var slide = slideIndexOf(name);
        b.classList.toggle('is-primary', !now);
        b.setAttribute('aria-checked', now ? 'false' : 'true');
        b.textContent = now ? 'Off' : 'On';
        if (slide !== null) refreshPreview(slide);
      };
    });

    el.querySelectorAll('[data-hpc-move]').forEach(function(b){
      b.onclick = function(){
        var parts = b.dataset.hpcMove.split('|');
        var i = +parts[0], j = i + (+parts[1]);
        if (j < 0 || j >= slides.length) return;
        var tmp = slides[i]; slides[i] = slides[j]; slides[j] = tmp;
        dirty = true;
        render();
      };
    });

    el.querySelectorAll('[data-hpc-remove]').forEach(function(b){
      b.onclick = function(){
        slides.splice(+b.dataset.hpcRemove, 1);
        dirty = true;
        render();
      };
    });

    var add = el.querySelector('[data-hpc-add]');
    if (add) add.onclick = function(){
      if (slides.length >= data.max_slides) return;
      var blank = {};
      data.slide_fields.forEach(function(f){ blank[f.key] = f.default; });
      slides.push(blank);
      dirty = true;
      tab = 'hero';
      render();
    };

    var reset = el.querySelector('[data-hpc-reset]');
    if (reset) reset.onclick = function(){
      slides = data.defaults.map(function(s){ return Object.assign({}, s); });
      dirty = true;
      banner = {kind:'is-warn', title:'Restored, not saved', lines:[
        'The three slides the shop shipped with are back in the boxes. Nothing is stored until you press Save changes.'
      ]};
      render();
    };

    el.querySelectorAll('[data-hpc-reload]').forEach(function(b){ b.onclick = load; });

    var save = el.querySelector('[data-hpc-save]');
    if (save) save.onclick = function(){ submit(save); };

    /* ---- the live-preview tab (Lane HL) ---------------------------------
       Bound from the same place as everything else, and re-run whole by the
       two partial repaints below: these handlers are assignments rather than
       addEventListener, so binding twice is binding once. */
    el.querySelectorAll('[data-hpe-dev]').forEach(function(b){
      b.onclick = function(){ live.dev = b.dataset.hpeDev; render(); };
    });

    el.querySelectorAll('[data-hpe-redraw]').forEach(function(b){
      b.onclick = function(){ drawLive(false); };
    });

    el.querySelectorAll('[data-hpe-reload]').forEach(function(b){
      b.onclick = function(){ loadLive(); };
    });

    el.querySelectorAll('[data-hpe-pick]').forEach(function(b){
      b.onclick = function(){ selectSection(b.dataset.hpePick); };
    });

    el.querySelectorAll('[data-hpe-words]').forEach(function(b){
      b.onclick = function(){
        var parts = b.dataset.hpeWords.split('|');
        tab = parts[0];
        render();

        /* Land ON the box rather than near it. The id is the one field()
           builds, so there is no second spelling of it to drift. */
        if (parts[1]) {
          var box = document.getElementById('hpc-f-' + ('copy.' + parts[1]).replace(/[^a-z0-9]+/gi, '-'));
          if (box) { box.focus(); }
        }
      };
    });

    el.querySelectorAll('[data-hpe-discard]').forEach(function(b){
      b.onclick = function(){ live.dirty = false; loadLive(); };
    });

    el.querySelectorAll('[data-hpe-save]').forEach(function(b){
      b.onclick = function(){ liveSave(b); };
    });
  }

  /* ------------------------------------------------------------------- save */
  function submit(button){
    button.disabled = true;
    banner = null;

    fetch(base(), {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':window.uToken ? window.uToken() : '',Accept:'application/json'},
      body: JSON.stringify({slides: slides, copy: copy})
    })
      .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, body:j}; }); })
      .then(function(res){
        button.disabled = false;

        if (!res.ok || !res.body.ok) {
          banner = {kind:'is-bad', title:'Not saved', lines:[
            res.body && res.body.message ? res.body.message : 'The server refused the change.'
          ]};
          render();
          return;
        }

        /* ADOPT WHAT THE SERVER STORED, not what was sent. A refused colour or
           an off-site link falls back to its default on the way in, and a
           screen that went on showing the rejected value would be telling the
           owner their change is live when it is not. */
        data = res.body;
        slides = res.body.slides.map(function(s){ return Object.assign({}, s.values); });
        copy = {};
        res.body.tabs.forEach(function(t){ t.fields.forEach(function(f){ copy[f.key] = f.value; }); });
        dirty = false;

        var refused = Object.keys(res.body.rejected || {});

        if (refused.length) {
          banner = {kind:'is-warn', title:'Saved, with ' + refused.length + ' field' + (refused.length === 1 ? '' : 's') + ' refused', lines:
            refused.map(function(k){ return k + ' — ' + res.body.rejected[k] + '. That one field fell back to its default; everything else on this screen saved.'; })};
        } else {
          banner = {kind:'', title:'Saved', lines:['The homepage is live with these words now.']};
          if (window.toast) window.toast('Homepage content saved');
        }

        render();
      })
      .catch(function(e){
        button.disabled = false;
        banner = {kind:'is-bad', title:'Not saved', lines:['The request did not complete: ' + String(e.message || e)]};
        render();
      });
  }

  /* ---------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    /* LITERALS, NOT THE CONSTANTS ABOVE, and this is load-bearing rather than
       style. tests/Feature/ModuleRegistrySettingsPathTest.php builds the list
       of places the console can put a screen by reading every kbbAddNavEntry()
       call in this directory with a regex, so that a module row claiming
       "Appearance -> Homepage content" is checked against a screen that really
       is there. A regex cannot follow a variable: written as GROUP and TITLE
       this row is invisible to it, and the module row pointing at this screen
       fails as though the screen did not exist. */
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Homepage content',
      icon:   '<path d="M3 5h18v6H3z"/><path d="M3 15h9"/><path d="M3 19h6"/>',
      group:  'Appearance',
      after:  ['homepage']
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }

  /* ------------------------------------------------------------- the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    /* The console's own go() is NOT called for this id. Its dispatch object
       ends ||renderDash, so calling it would paint the dashboard a moment
       before this screen draws over it. The nav, crumb and title below are the
       only things it would have done for us. */
    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    document.querySelectorAll('#nav .nav-group').forEach(function(g){
      var has = [].slice.call(g.querySelectorAll('.nav-item')).some(function(b){
        return b.dataset.go === SCREEN;
      });
      g.classList.toggle('open', has);
    });

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = TITLE;

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.scrollTop = 0;

    if (typeof cur !== 'undefined') { try { cur = SCREEN; } catch (e) {} }

    /* SYNCHRONOUS FIRST PAINT, BEFORE ANY await. That is the condition
       LATE_RENDERED requires: the deep-link replay reads a marker inside
       #content that any real render destroys, so a screen that awaited before
       painting would be drawn twice. */
    if (data) { render(); } else { load(); }

    return undefined;
  };
})();
</script>
@endverbatim
