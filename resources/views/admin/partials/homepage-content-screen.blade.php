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
    return data.tabs.map(function(t){
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
      + '<b>Appearance &rarr; Homepage</b>; this screen decides what the ones you keep actually say.</p>'
      + '<p class="hpc-sub">Everything here starts as the wording the shop shipped with, so leaving it alone changes nothing. '
      + 'Clearing a box means <b>say nothing</b>: the line is dropped rather than printed empty.</p></div>'
      + bannerHTML()
      + '<div class="hpc-tabs">'
      +   '<button class="hpc-tab' + (tab === 'hero' ? ' on' : '') + '" data-hpc-tab="hero">Hero slider</button>'
      +   data.tabs.map(function(t){
            return '<button class="hpc-tab' + (tab === t.key ? ' on' : '') + '" data-hpc-tab="' + esc(t.key) + '">' + esc(t.label) + '</button>';
          }).join('')
      + '</div>'
      + (tab === 'hero' ? heroTab() : copyTab())
      + '<div class="hpc-card"><div class="hpc-save">'
      +   '<span class="hpc-dirty">' + (dirty ? 'Unsaved changes' : 'Saved') + '</span>'
      +   '<button class="hpc-btn" data-hpc-reload>Discard</button>'
      +   '<button class="hpc-btn is-primary" data-hpc-save>Save changes</button>'
      + '</div></div>'
      + '</div></div>';

    bind();
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

  function bind(){
    var el = document.querySelector('#content');
    if (!el) return;

    el.querySelectorAll('[data-hpc-tab]').forEach(function(b){
      b.onclick = function(){ tab = b.dataset.hpcTab; render(); };
    });

    el.querySelectorAll('[data-hpc-set]').forEach(function(input){
      var name = input.dataset.hpcSet;
      var slide = name.indexOf('copy.') === 0 ? null : +name.split('.')[0];

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

    el.querySelectorAll('[data-hpc-toggle]').forEach(function(b){
      b.onclick = function(){
        var name = b.dataset.hpcToggle;
        var now = b.getAttribute('aria-checked') === 'true';
        setValue(name, !now);
        var slide = name.indexOf('copy.') === 0 ? null : +name.split('.')[0];
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
