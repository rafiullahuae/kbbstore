{{--
    Catalog - Product editor.  (Lane AO)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go, toast() and the design tokens
    this screen borrows.

    Its own file rather than more lines inside an 884KB Blade: several lanes
    edit that file at once, and a screen that lives on its own can be reviewed,
    reverted and merged on its own. The cost is that it cannot reach
    app.blade.php's module-scoped constants -- NAV, TITLES and ADMIN_BASE are
    const, not window properties -- so it appends its own sidebar entry to the
    rendered nav and wraps window.go instead. Both are surfaces the console
    already exposes for exactly this. The shape is deliberately the same as
    admin/partials/coupon-usage-screen.blade.php; that is the precedent.

    WHAT THIS SCREEN REPLACES. app.blade.php already contains a product editor,
    openProduct(), and it is a MOCK: it reads a hardcoded CAT_PRODUCTS array and
    every one of its controls calls toast('... (preview)'). A later script block
    wires a few of its fields to a real save. Nothing in it can set a gallery,
    more than one category, sanitised rich copy, SEO that reaches Google, or a
    publish date. This screen is the real one, on its own routes, and it does
    not touch that code.

    EVERY CLASS IS PREFIXED peo- AND APPEARS NOWHERE ELSE IN THE CONSOLE. The
    existing mock already owns .pe-card, .pe-box, .pe-grid and .rte, so a
    shorter prefix here would restyle it from across the file. peo- cannot.

    AND SO IS EVERY data- ATTRIBUTE THAT ANYTHING CLICKS, which is the same rule
    for a less obvious reason. The console is ONE document, and app.blade.php
    binds around a dozen listeners to `document` itself, each claiming a bare
    attribute name -- [data-open], [data-tg], [data-pp], [data-aptab] and so on.
    A click on any element carrying one of those names is handled by that
    listener no matter which screen the element belongs to.

    This screen's picker rows were originally data-open, which is the Appearance
    screen's skin-picker attribute. Clicking a product ran Appearance's handler,
    which did document.querySelector('[data-pop="<the product id>"]'), got null,
    and threw on the next line -- a TypeError in the console on every single
    click of a row, while the row still worked, because this file's own
    onclick ran too. It cost nothing to find only because the browser check
    listens for pageerror. Anything here that a delegated listener could claim
    is therefore data-peo-*.

    THE LAYOUT RULE. Nothing here may be wider than its column at 390px,
    because the owner reviews on a phone. The admin sets body{overflow:hidden}
    and scrolls inside #content, which means document.scrollWidth can never
    report an over-wide form -- it is #content that has to be measured. Every
    grid and flex child that can contain something wide carries min-width:0,
    because a grid item's default min-width is auto ("at least as wide as my
    content"), and that exact defect shipped on the Coupons screen: the card
    refused to shrink below the width of the table inside it, the scroller never
    got the chance to scroll, and the whole screen was stretched.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.

    The whole body is wrapped in one so that the {{ }} inside JavaScript
    template literals is not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Product editor. Prefix peo-, used nowhere else in the console.
--------------------------------------------------------------------------- */
.peo-wrap{display:grid;gap:16px;min-width:0}
.peo-wrap > *{min-width:0}

/* The save bar. Sticky to the top of the scroller so Save is reachable from
   anywhere in a long form -- the owner should never have to scroll back up to
   find out whether their work is saved. */
.peo-bar{position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:10px;
         flex-wrap:wrap;min-width:0;
         background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:11px 13px;
         box-shadow:0 1px 2px rgba(18,21,31,.04),0 6px 18px rgba(18,21,31,.05)}
.peo-bar .peo-grow{flex:1 1 auto;min-width:0}
.peo-bar h2{margin:0;font-size:15px;font-weight:650;line-height:1.3;
            overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.peo-bar .peo-sub{font-size:11.5px;color:var(--ink-soft,#6b7280);
                  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.peo-btn{font:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;
         border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit;
         cursor:pointer;white-space:nowrap}
.peo-btn:hover{background:rgba(127,127,127,.07)}
.peo-btn.peo-primary{background:#1f7d52;border-color:#1f7d52;color:#fff}
.peo-btn.peo-primary:hover{background:#1a6b46}
.peo-btn[disabled]{opacity:.5;cursor:default}
.peo-btn.peo-danger{color:#b4443c;border-color:#e3c3c0}

/* Two columns on a desk, one on a phone. minmax(0,…) on BOTH tracks, not
   1fr/320px: a bare 1fr is minmax(auto,1fr), and auto is the same refusal to
   shrink that stretched the Coupons screen. */
.peo-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,320px);gap:16px;min-width:0}
.peo-grid > *{min-width:0}
@media(max-width:900px){.peo-grid{grid-template-columns:minmax(0,1fr)}}

.peo-col{display:grid;gap:16px;align-content:start;min-width:0}
.peo-col > *{min-width:0}

.peo-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:15px;min-width:0}
.peo-card h3{margin:0 0 3px;font-size:12px;font-weight:700;letter-spacing:.04em;
             text-transform:uppercase;color:var(--ink-soft,#6b7280)}
.peo-card .peo-hint{font-size:11.5px;color:var(--ink-soft,#6b7280);margin:0 0 12px;line-height:1.45}

.peo-fld{margin-bottom:13px;min-width:0}
.peo-fld:last-child{margin-bottom:0}
.peo-fld label{display:block;font-size:12px;font-weight:600;margin-bottom:5px;color:var(--ink-2,#374151)}
.peo-fld .peo-note{font-size:11px;color:var(--ink-soft,#6b7280);margin-top:5px;line-height:1.45}

.peo-in,.peo-sel,.peo-ta{width:100%;max-width:100%;box-sizing:border-box;min-width:0;
    font:inherit;font-size:13px;padding:9px 10px;border-radius:9px;
    border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit}
.peo-ta{min-height:70px;resize:vertical}
.peo-in:focus,.peo-sel:focus,.peo-ta:focus{outline:0;border-color:#1f7d52;box-shadow:0 0 0 3px rgba(31,125,82,.14)}

.peo-row{display:flex;gap:10px;flex-wrap:wrap;min-width:0}
.peo-row > *{flex:1 1 140px;min-width:0}

.peo-check{display:flex;align-items:flex-start;gap:9px;font-size:13px;cursor:pointer;
           padding:7px 0;min-width:0}
.peo-check input{margin:2px 0 0;flex:none}
.peo-check span{min-width:0;overflow-wrap:anywhere}

/* ---- rich text ---- */
.peo-rte{border:1px solid var(--border,#e6e6e6);border-radius:10px;overflow:hidden;min-width:0}
.peo-rte-bar{display:flex;flex-wrap:wrap;gap:3px;padding:6px;background:var(--surface-2,#f7f8fa);
             border-bottom:1px solid var(--border,#e6e6e6);min-width:0}
.peo-rte-bar button{font:inherit;font-size:11.5px;font-weight:700;color:var(--ink-2,#374151);
                    padding:5px 8px;border-radius:6px;background:none;border:1px solid transparent;cursor:pointer;
                    min-width:28px}
.peo-rte-bar button:hover{background:var(--surface,#fff);border-color:var(--border,#e6e6e6)}
.peo-rte-bar .peo-sep{width:1px;background:var(--border,#e6e6e6);margin:3px 3px}
.peo-rte-area{padding:12px;font-size:13.5px;line-height:1.6;min-height:150px;
              background:var(--surface,#fff);color:inherit;outline:0;overflow-wrap:anywhere;min-width:0}
.peo-rte-area:empty:before{content:attr(data-ph);color:var(--ink-faint,#9ca3af)}
.peo-rte-area p{margin:0 0 .7em}
.peo-rte-area ul,.peo-rte-area ol{margin:0 0 .7em;padding-left:1.4em}
.peo-rte-area h2{font-size:16px;margin:.6em 0 .35em}
.peo-rte-area h3{font-size:14.5px;margin:.6em 0 .35em}
.peo-rte-area img{max-width:100%;height:auto}
.peo-rte-area table{border-collapse:collapse;max-width:100%}
.peo-rte-area td,.peo-rte-area th{border:1px solid var(--border,#e6e6e6);padding:5px 7px}
.peo-rte-foot{font-size:11px;color:var(--ink-soft,#6b7280);padding:6px 11px;
              border-top:1px solid var(--border,#e6e6e6);background:var(--surface-2,#f7f8fa);
              display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;min-width:0}

/* ---- images ---- */
/* Capped rather than full-bleed. The media card lives in the main column now,
   which is ~660px at 1280 and ~1300px at 1920, and a square preview allowed to
   fill that is a photograph the size of a window for a field whose job is
   "yes, that is the right picture". The cap is a max-width, not a width, so it
   still shrinks to the column at 390px. */
.peo-main-img{border:1px solid var(--border,#e6e6e6);border-radius:11px;overflow:hidden;
              min-width:0;max-width:260px}
.peo-main-img .peo-ph{aspect-ratio:1;background:var(--surface-2,#f7f8fa);display:grid;place-items:center;
                      color:var(--ink-faint,#9ca3af);font-size:12px;text-align:center;padding:12px}
.peo-main-img img{display:block;width:100%;aspect-ratio:1;object-fit:cover}
.peo-main-cap{display:flex;gap:8px;padding:8px 10px;border-top:1px solid var(--border,#e6e6e6);flex-wrap:wrap}

/* Main image and gallery SIDE BY SIDE inside one Images panel.

   A viewport media query is the wrong instrument here and was tried first. The
   panel's width is not a function of the viewport alone: it depends on which
   column the operator has dragged it into, and .peo-grid keeps a 320px sidebar
   from 901px up. At a 901px viewport the main column is about 473px, which a
   `min-width:901px` rule would have declared wide enough -- and the gallery
   half would have been 195px, leaving each alt-text box about 27px. That is
   the same defect the comment above this one is about, reintroduced from the
   other direction.

   So the question asked is the one that actually matters: how wide is THIS
   PANEL. Container queries answer it whichever column the panel sits in.

   The single-column rule is the BASE, and the two-column rule is what the
   query adds. A browser that does not understand @container therefore renders
   exactly what this screen rendered before -- stacked -- rather than a broken
   two-column grid. Degrading to today's layout is the whole reason the
   fallback is arranged this way round.

   620px is where the split starts paying. Below it the gallery half takes the
   alt-text inputs under the 130px that makes a sentence typeable; above it the
   main column at 1280 (~650px here) splits to a 260px preview and a ~370px
   list, and at 1920 (~1290px) the list gets over 1000px. */
.peo-mediawrap{container-type:inline-size;min-width:0}
.peo-media{display:grid;gap:16px;align-items:start;min-width:0}
.peo-media > *{min-width:0}
@container (min-width:620px){
  /* Fixed left track, not a fraction: the preview is capped at 260px by
     .peo-main-img anyway, so a fractional track would only ever add dead space
     between the two halves at wide widths. */
  .peo-media{grid-template-columns:260px minmax(0,1fr)}
}

/* The gallery is a LIST, not a grid of thumbnails, and that is a decision
   about alt text rather than about looks. Every shot carries its own alt, the
   alt is a sentence, and a sentence needs a full-width box to be typed into --
   in a 3-across grid at 390px each input would be 104px wide. A row also makes
   the reorder controls reachable with a thumb and gives the drag handle
   somewhere obvious to live. */
.peo-gal{display:grid;gap:8px;min-width:0}
.peo-gal > *{min-width:0}
.peo-tile{display:flex;align-items:center;gap:10px;padding:8px;min-width:0;
          border:1px solid var(--border,#e6e6e6);border-radius:10px;
          background:var(--surface,#fff)}
.peo-tile img{display:block;width:56px;height:56px;border-radius:8px;object-fit:cover;
              flex:none;pointer-events:none;background:var(--surface-2,#f7f8fa)}
.peo-tile.peo-drag{opacity:.35}
.peo-tile.peo-over{outline:2px dashed #1f7d52;outline-offset:-2px}
.peo-tile .peo-grip{flex:none;cursor:grab;color:var(--ink-faint,#9ca3af);font-size:15px;
                    line-height:1;padding:4px 2px;user-select:none}
.peo-tile .peo-body{flex:1 1 auto;min-width:0}
.peo-tile .peo-ord{font-size:10.5px;font-weight:700;color:var(--ink-soft,#6b7280);
                   display:block;margin-bottom:4px}
.peo-tile .peo-alt{width:100%;max-width:100%;box-sizing:border-box;min-width:0;font:inherit;
                   font-size:12.5px;padding:6px 8px;border-radius:7px;
                   border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:inherit}
.peo-tile .peo-alt:focus{outline:0;border-color:#1f7d52;box-shadow:0 0 0 3px rgba(31,125,82,.14)}
.peo-tile .peo-acts{flex:none;display:flex;gap:3px}
.peo-tile .peo-acts button{background:var(--surface-2,#f7f8fa);border:1px solid var(--border,#e6e6e6);
                           color:inherit;width:26px;height:26px;border-radius:7px;font-size:12px;
                           line-height:1;cursor:pointer;padding:0}
.peo-tile .peo-acts button[disabled]{opacity:.35;cursor:default}
.peo-tile .peo-acts button.peo-rm{color:#b4443c}
@media(max-width:420px){
  .peo-tile{flex-wrap:wrap}
  .peo-tile .peo-body{flex:1 1 100%;order:3}
}

.peo-drop{border:1.5px dashed var(--border,#e6e6e6);border-radius:10px;padding:18px 12px;text-align:center;
          font-size:12.5px;color:var(--ink-soft,#6b7280);cursor:pointer;min-width:0;background:none}
.peo-drop:hover,.peo-drop.peo-over{border-color:#1f7d52;color:#1f7d52;background:rgba(31,125,82,.04)}
.peo-drop b{display:block;font-size:13px;color:inherit;margin-bottom:3px}

/* ---- empty states ---- */
.peo-empty{padding:22px 12px;text-align:center;color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.5}
.peo-empty b{display:block;font-size:13.5px;color:var(--ink,#111827);margin-bottom:4px}

/* ---- SEO preview ---- */
.peo-snip{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:13px;
          background:var(--surface-2,#f7f8fa);min-width:0;overflow-wrap:anywhere}
.peo-snip .u{font-size:11.5px;color:#0b6b2f}
.peo-snip .t{color:#1a0dab;font-size:16px;margin:3px 0;font-weight:500;line-height:1.3}
.peo-snip .d{font-size:12.5px;color:#4d5156;line-height:1.5}
.peo-count{float:right;font-size:11px;font-weight:600;color:var(--ink-soft,#6b7280)}
.peo-count.peo-warn{color:#b7791f}
.peo-count.peo-bad{color:#b4443c}

/* ---- status pill ---- */
.peo-pill{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;
          border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280);white-space:nowrap}
.peo-pill.is-publish{border-color:#1f7d52;color:#1f7d52;background:rgba(31,125,82,.07)}
.peo-pill.is-draft{border-color:#9ca3af;color:#6b7280}
.peo-pill.is-private{border-color:#7b5cf0;color:#7b5cf0;background:rgba(123,92,240,.07)}
.peo-pill.is-scheduled{border-color:#b7791f;color:#b7791f;background:rgba(183,121,31,.08)}

/* ---- the picker list ---- */
.peo-list{display:grid;gap:8px;min-width:0}
.peo-item{display:flex;align-items:center;gap:11px;padding:9px 11px;min-width:0;
          border:1px solid var(--border,#e6e6e6);border-radius:10px;background:var(--surface,#fff);
          cursor:pointer;text-align:left;font:inherit;color:inherit;width:100%}
.peo-item:hover{background:rgba(127,127,127,.05)}
.peo-item img,.peo-item .peo-noimg{width:38px;height:38px;border-radius:8px;object-fit:cover;flex:none;
                                   background:var(--surface-2,#f7f8fa)}
.peo-item .peo-meta{flex:1 1 auto;min-width:0}
.peo-item b{display:block;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.peo-item .peo-m2{font-size:11.5px;color:var(--ink-soft,#6b7280);overflow:hidden;
                  text-overflow:ellipsis;white-space:nowrap}

.peo-banner{border-radius:10px;padding:11px 13px;font-size:12.5px;line-height:1.5;min-width:0;
            overflow-wrap:anywhere}
.peo-banner.is-bad{border:1px solid #e3c3c0;background:#fdf3f2;color:#8f3229}
.peo-banner.is-good{border:1px solid #bfe0cd;background:#f2faf5;color:#1a6b46}

/* ---------------------------------------------------------------------------
   Panel arrangement. (Lane AS)

   Every panel is wrapped in a .peo-panel rather than having its own view
   function changed. The wrapper is what carries the arrange toolbar, the drop
   target and the panel key, so this feature adds nothing at all to the inside
   of a card -- no field moves, no id changes, and the save payload is
   untouched. It also means a panel added later is arrangeable the moment it is
   listed in the registry, without anybody remembering to add markup to it.

   min-width:0 on the wrapper AND on its children, for the reason the docblock
   at the top of this file gives: a grid item defaults to min-width:auto, which
   is a refusal to shrink below its content, and inserting a new grid level
   between .peo-col and .peo-card is exactly where that defect gets
   reintroduced. The gallery's own alt-text inputs and the RTE panes are wide
   enough to stretch the whole console if this line is dropped.
--------------------------------------------------------------------------- */
.peo-panel{display:grid;gap:6px;min-width:0;align-content:start}

/* ---- colour ----------------------------------------------------------------
   A hue per panel, set on the wrapper that already carries the panel key and
   read by the card inside it, so the two Images cards pick up one hue without
   either of them naming it.

   Restraint is the point: the hue appears as a 3px rail down the left of the
   card, the heading, and a wash under the header that is a few percent of the
   colour. The card stays white, the inputs stay untouched, and nothing changes
   the meaning of the green the save button and the toggles already use. A
   product editor is a screen an operator stares at all day; the colour is there
   to tell the panels apart at a glance, not to decorate.

   Every value goes through the hue token, so re-hueing a panel is one line and
   cannot leave a heading one colour and its rail another. color-mix is declared
   AFTER a solid fallback, so a browser without it gets the plain card rather
   than a transparent one. */
.peo-panel{--peo-hue:#6366f1}
.peo-panel[data-peo-panel="basics"]{--peo-hue:#2563eb}
.peo-panel[data-peo-panel="images"]{--peo-hue:#7c3aed}
.peo-panel[data-peo-panel="short_description"]{--peo-hue:#0891b2}
.peo-panel[data-peo-panel="description"]{--peo-hue:#0891b2}
.peo-panel[data-peo-panel="ingredients"]{--peo-hue:#0d9488}
.peo-panel[data-peo-panel="how_to_use"]{--peo-hue:#0d9488}
.peo-panel[data-peo-panel="seo"]{--peo-hue:#b45309}
.peo-panel[data-peo-panel="publish"]{--peo-hue:#15803d}
.peo-panel[data-peo-panel="categories"]{--peo-hue:#c026d3}
.peo-panel[data-peo-panel="brand"]{--peo-hue:#c026d3}
.peo-panel[data-peo-panel="pricing"]{--peo-hue:#b45309}
.peo-panel[data-peo-panel="stock"]{--peo-hue:#0369a1}

.peo-card{position:relative;overflow:hidden}
/* The vertical rail down the left edge is gone — the owner asked for it out.
   The heading colour and the wash under it stay, which is what was actually
   doing the work of telling one panel from the next; the rail was a second
   statement of the same thing. No padding-left inset either, now that there is
   nothing at the card's edge for the text to clear. */
.peo-card::after{content:'';position:absolute;left:0;right:0;top:0;height:74px;
                 pointer-events:none;z-index:0;
                 background:transparent;
                 background:linear-gradient(180deg,
                   color-mix(in srgb, var(--peo-hue,#6366f1) 7%, transparent),
                   transparent)}
.peo-card > *{position:relative;z-index:1}
.peo-card h3{color:var(--peo-hue,#6b7280)}
.peo-panel > *{min-width:0}

/* The per-panel arrange toolbar. It is also the drag handle -- see the note in
   bindArrange() for why the handle is the bar and not the whole panel. */
.peo-arrbar{display:flex;align-items:center;gap:6px;min-width:0;flex-wrap:nowrap;
            padding:5px 7px;border-radius:9px;
            border:1px solid var(--border,#e6e6e6);background:var(--surface-2,#f7f8fa)}
.peo-arrbar .peo-grip{flex:none;cursor:grab;color:var(--ink-faint,#9ca3af);font-size:15px;
                      line-height:1;padding:2px;user-select:none}
.peo-arrbar .peo-arrname{flex:1 1 auto;min-width:0;font-size:11.5px;font-weight:700;
                         letter-spacing:.03em;text-transform:uppercase;
                         color:var(--ink-soft,#6b7280);
                         overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.peo-arrbar .peo-arracts{flex:none;display:flex;gap:3px}

/* 30px, not 26px like the gallery's. These are the touch targets for the whole
   feature on a phone, where drag never fires at all, so they are the larger of
   the two precedents this screen already sets. */
.peo-arrbar .peo-arracts button{background:var(--surface,#fff);
                                border:1px solid var(--border,#e6e6e6);color:inherit;
                                min-width:30px;height:30px;border-radius:8px;font-size:13px;
                                line-height:1;cursor:pointer;padding:0 6px}
.peo-arrbar .peo-arracts button:hover:not([disabled]){background:rgba(31,125,82,.07);border-color:#1f7d52;color:#1f7d52}
.peo-arrbar .peo-arracts button:focus-visible{outline:0;border-color:#1f7d52;box-shadow:0 0 0 3px rgba(31,125,82,.18)}
.peo-arrbar .peo-arracts button[disabled]{opacity:.35;cursor:default}

.peo-panel.peo-pdrag{opacity:.4}
.peo-panel.peo-pover > .peo-card,
.peo-panel.peo-pover > .peo-arrbar{outline:2px dashed #1f7d52;outline-offset:-2px}
.peo-panel.peo-arranging > .peo-card{border-style:dashed}

/* A column emptied of every panel still needs to be a drop target, or a layout
   that moved everything into one column could never be undone by dragging --
   only by Reset. */
.peo-slot{border:1.5px dashed var(--border,#e6e6e6);border-radius:10px;padding:18px 12px;
          text-align:center;font-size:12px;color:var(--ink-soft,#6b7280);min-width:0}
.peo-slot.peo-pover{border-color:#1f7d52;color:#1f7d52;background:rgba(31,125,82,.04)}

.peo-arrnote{border:1px solid #bfe0cd;background:#f2faf5;color:#1a6b46;
             border-radius:10px;padding:10px 12px;font-size:12px;line-height:1.5;
             min-width:0;overflow-wrap:anywhere}

/* Below the grid's own breakpoint the two columns are one stack, so "the other
   column" is not a thing the operator can see and the button that does it is
   removed rather than left to do something invisible. Up and down take over
   the job there -- they step through the whole stack, across the boundary.
   Hidden in CSS as well as skipped in the markup so a resize with the screen
   already open cannot leave a live control behind. */
@media(max-width:900px){
  .peo-arrbar .peo-arracts button[data-peo-col]{display:none}
}

@media(max-width:640px){
  .peo-card{padding:13px}
  .peo-bar{padding:10px 11px}
  .peo-bar h2{font-size:14px}
}
</style>

<script>
(function(){
  'use strict';

  /* The console builds its sidebar and its router before this runs. Both are
     const inside that script's own scope, so neither can be read from here --
     the entry is appended to the rendered DOM and the router is wrapped. */

  var SCREEN = 'product-editor';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ---------------------------------------------------------------- state */
  var boot = null;       // categories, brands, currency
  var model = null;      // the product being edited, or null on the picker
  var listing = null;    // picker results
  var query = '';
  var banner = null;
  var busy = false;
  var dirty = false;
  var seq = 0;

  /* ---- panel arrangement (Lane AS) ----------------------------------------
     `layout` is {main:[keys], side:[keys]} and is ALWAYS a reconciled document
     -- never whatever the server handed back. `arranging` is deliberately not
     persisted: it is a mode, not a preference, and an operator who reloads
     should land on their product, not on a screen full of toolbars.

     `layoutSent` is the last document actually posted, as JSON, so that a
     reorder that ends where it started does not write a row, and so a burst of
     taps on the move buttons collapses into one request. */
  var layout = null;
  var arranging = false;
  var layoutSent = null;
  var layoutLoaded = false;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function apiBase(){ return BASE.replace(/\/[^\/]*$/, '') + '/admin-api'; }

  async function api(path, opts){
    opts = opts || {};
    opts.headers = opts.headers || {};
    opts.headers['Accept'] = 'application/json';
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
    opts.credentials = 'same-origin';

    if (opts.json !== undefined) {
      opts.method = opts.method || 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.json);
      delete opts.json;
    }

    var r = await fetch(apiBase() + path, opts);
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }

    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  /* An attribute value, for a src/href built from stored data. esc() alone is
     enough inside a quoted attribute, but a URL also gets its scheme checked:
     the gallery stores whatever the upload endpoint returned, and a javascript:
     value reaching an <img src> is not a risk worth carrying for the sake of
     three characters. */
  function url(u){
    var s = String(u == null ? '' : u).trim();
    if (/^\s*(javascript|data|vbscript)\s*:/i.test(s)) return '';
    return esc(s);
  }

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  function icon(d){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" ' +
           'stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">' + d + '</svg>';
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry(){
    if (document.querySelector('[data-go="' + SCREEN + '"]')) return;

    var anchor = document.querySelector('#nav [data-go="catalog"]')
              || document.querySelector('#nav [data-go="orders"]');
    if (!anchor) return;

    var b = document.createElement('button');
    b.className = 'nav-item';
    b.dataset.go = SCREEN;
    b.innerHTML = icon('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>')
                + '<span>Product editor</span>';
    b.onclick = function(){ window.go(SCREEN); };
    anchor.parentNode.insertBefore(b, anchor.nextSibling);
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function(id){
    if (id !== SCREEN) {
      // Leaving with unsaved work. confirm() rather than a custom modal: this
      // has to be reliable more than it has to be pretty, and the console has
      // no modal primitive this file can reach.
      if (model && dirty && !window.confirm('You have unsaved changes. Leave without saving?')) {
        return undefined;
      }
      return previousGo.apply(this, arguments);
    }

    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog';
    if (title) title.textContent = 'Product editor';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    start();
    return undefined;
  };

  /*
   * What the screen should show once its bootstrap has arrived.
   *
   * Both external entry points set this and then switch screens; neither
   * loads anything itself. That is not tidiness, it is the fix for a real
   * race, and the race was live: peoEdit() used to call loadProduct(id)
   * directly after window.go(). go() starts start(), which awaits the
   * bootstrap and then falls back to loadList() when no model is set. If the
   * bootstrap resolved first -- which it does, being the smaller request --
   * loadList() ran, bumped the sequence counter, and loadProduct's own
   * response was then discarded as stale by its own guard. Clicking Edit on
   * the products list landed on the picker instead of the product, with no
   * error anywhere. Caught by clicking it rather than by reading it.
   *
   * Loading after the bootstrap is also the correct order on its own terms:
   * the category and brand pickers are drawn from it.
   */
  var intent = null;

  /* Opened straight onto one product from elsewhere in the console. */
  window.peoEdit = function(id){
    intent = { kind: 'product', id: id };
    window.go(SCREEN);
  };

  /* Opened straight onto a blank product, for the Catalog header's Add
     product button. */
  window.peoNew = function(){
    intent = { kind: 'new' };
    window.go(SCREEN);
  };

  /* ----------------------------------------------------------------- data */
  async function start(){
    // The stored arrangement, before anything else, so the first paint of an
    // editor opened from the picker is already the way the operator left it.
    // Idempotent and non-throwing: it falls back to the default arrangement.
    await loadLayout();

    if (!boot) {
      try { boot = await api('/product-editor-bootstrap'); }
      catch (e) { banner = message(e, 'Could not load the editor.'); render(); return; }
    }

    var want = intent;
    intent = null;

    if (want && want.kind === 'product') { loadProduct(want.id); return; }

    if (want && want.kind === 'new') {
      model = blank();
      dirty = false;
      banner = null;
      render();
      return;
    }

    if (!model) loadList();
    else render();
  }

  async function loadList(){
    var mine = ++seq;
    busy = true; render();

    try {
      var r = await api('/product-editor-list?q=' + encodeURIComponent(query));
      if (mine !== seq) return;
      listing = r.products || [];
      banner = null;
    } catch (e) {
      if (mine !== seq) return;
      banner = message(e, 'Could not load products.');
    }
    busy = false; render();
  }

  async function loadProduct(id){
    var mine = ++seq;
    busy = true; banner = null; render();

    try {
      var r = await api('/product-editor-load/' + id);
      if (mine !== seq) return;
      model = r.product;
      dirty = false;

      // Clicking a row in the picker calls this directly, without going back
      // through start(), so this is the other door the stored arrangement has
      // to come in by. Idempotent, so the start() path pays nothing for it.
      await loadLayout();
      if (mine !== seq) return;
    } catch (e) {
      if (mine !== seq) return;
      banner = message(e, 'Could not open that product.');
    }
    busy = false; render();
  }

  function blank(){
    return {
      id: null, name: '', slug: '', sku: null, gtin: null, brand_id: null,
      status: 'draft', is_visible: true, featured: false, published_at: null,
      category_ids: [], primary_category_id: null,
      price_aed: '', sale_aed: '', sale_starts_at: null, sale_ends_at: null,
      manage_stock: false, stock: null, stock_status: 'instock',
      short_description: '', description: '', ingredients: '', how_to_use: '',
      image: null, images: [], image_alts: {}, seo: null,
      readonly: {total_sales:0, rating:0, review_count:0, url:''}
    };
  }

  /* A refusal the owner can act on. Laravel's 422 carries per-field messages;
     the first real sentence is worth far more than "Save failed". */
  function message(e, fallback){
    var b = e && e.body;
    if (b && b.errors) {
      for (var k in b.errors) {
        if (b.errors[k] && b.errors[k].length) return b.errors[k][0];
      }
    }
    if (b && b.message) return b.message;
    if (e && e.status === 401) return 'Your session expired. Sign in again.';
    return fallback;
  }

  /* ----------------------------------------------------------------- save */
  async function save(){
    if (busy) return;

    collect();

    var body = {
      name: model.name,
      sku: model.sku,
      gtin: model.gtin,
      brand_id: model.brand_id,
      status: model.status,
      published_at: model.published_at,
      is_visible: !!model.is_visible,
      featured: !!model.featured,
      category_ids: model.category_ids,
      primary_category_id: model.primary_category_id,
      price_aed: model.price_aed === '' ? null : model.price_aed,
      sale_aed: model.sale_aed === '' ? null : model.sale_aed,
      sale_starts_at: model.sale_starts_at || null,
      sale_ends_at: model.sale_ends_at || null,
      manage_stock: !!model.manage_stock,
      stock: model.stock === '' ? null : model.stock,
      stock_status: model.stock_status,
      short_description: model.short_description,
      description: model.description,
      ingredients: model.ingredients,
      how_to_use: model.how_to_use,
      image: model.image,
      images: model.images,
      image_alts: model.image_alts || {},
      seo: model.seo || {}
    };

    var creating = !model.id;
    if (creating) body.slug = model.slug;

    busy = true; banner = null; render();

    try {
      var r = await api(
        creating ? '/product-editor-create' : '/product-editor-save/' + model.id,
        {json: body}
      );
      model = r.product;
      dirty = false;
      banner = null;
      say(creating ? 'Product created.' : 'Saved.');
    } catch (e) {
      banner = message(e, 'Could not save. Check your connection and try again.');
    }

    busy = false; render();
  }

  /* ------------------------------------------------------------- uploads */
  async function upload(file){
    var fd = new FormData();
    fd.append('file', file);
    fd.append('folder', 'products');

    // Posted to the ONE upload endpoint this application has. A second one is
    // what the brands and catalog route files each went out of their way to
    // avoid: two of them drift, and the one that drifts is the one with the
    // content-type, size and SVG rules in it.
    var r = await api('/media/upload', {method: 'POST', body: fd});
    return r && r.url;
  }

  async function takeFiles(files, asMain){
    var list = Array.prototype.slice.call(files || []);
    if (!list.length) return;

    busy = true; banner = null; render();

    for (var i = 0; i < list.length; i++) {
      try {
        var u = await upload(list[i]);
        if (!u) continue;

        if (asMain) {
          model.image = u;
          asMain = false;                       // only the first becomes main
        } else if (model.images.indexOf(u) === -1 && u !== model.image) {
          model.images.push(u);
        }
        dirty = true;
      } catch (e) {
        banner = message(e, 'That image could not be uploaded.');
        break;
      }
    }

    busy = false; render();
  }

  /* ------------------------------------------------------------ rich text */

  /* Reads the editable panes back into the model.
     Called before every save and before every re-render, because innerHTML is
     the only place that text lives until then -- re-rendering without this
     would silently discard whatever the owner had just typed. */
  function collect(){
    if (!model) return;

    document.querySelectorAll('#content .peo-rte-area').forEach(function(el){
      model[el.dataset.field] = el.innerHTML;
    });

    // Alt text, keyed by the image URL rather than by position, so reordering
    // or removing a shot cannot move a caption onto a different photograph.
    document.querySelectorAll('#content [data-alt]').forEach(function(el){
      model.image_alts = model.image_alts || {};
      model.image_alts[el.dataset.alt] = el.value;
    });

    document.querySelectorAll('#content [data-bind]').forEach(function(el){
      var k = el.dataset.bind;
      var v = el.type === 'checkbox' ? el.checked : el.value;

      if (k.indexOf('seo.') === 0) {
        model.seo = model.seo || {};
        model.seo[k.slice(4)] = v;
      } else if (k === 'brand_id' || k === 'primary_category_id') {
        model[k] = v === '' ? null : parseInt(v, 10);
      } else {
        model[k] = v;
      }
    });
  }

  var RTE_BUTTONS = [
    ['bold', 'B', 'Bold'],
    ['italic', 'I', 'Italic'],
    ['underline', 'U', 'Underline'],
    ['sep'],
    ['formatBlock:h2', 'H2', 'Heading'],
    ['formatBlock:h3', 'H3', 'Sub-heading'],
    ['formatBlock:p', '¶', 'Paragraph'],
    ['sep'],
    ['insertUnorderedList', '• List', 'Bulleted list'],
    ['insertOrderedList', '1. List', 'Numbered list'],
    ['sep'],
    ['createLink', '🔗', 'Link'],
    ['unlink', '⛓', 'Remove link'],
    ['sep'],
    ['removeFormat', 'Clear', 'Clear formatting']
  ];

  function rte(field, label, hint, placeholder, html){
    var bar = RTE_BUTTONS.map(function(b){
      if (b[0] === 'sep') return '<i class="peo-sep"></i>';
      return '<button type="button" data-cmd="' + esc(b[0]) + '" title="' + esc(b[2]) + '">'
           + esc(b[1]) + '</button>';
    }).join('');

    return '<div class="peo-card">'
      + '<h3>' + esc(label) + '</h3>'
      + (hint ? '<p class="peo-hint">' + esc(hint) + '</p>' : '')
      + '<div class="peo-rte">'
      +   '<div class="peo-rte-bar">' + bar + '</div>'
      +   '<div class="peo-rte-area" contenteditable="true" data-field="' + esc(field) + '" '
      +        'data-ph="' + esc(placeholder) + '">' + (html || '') + '</div>'
      +   '<div class="peo-rte-foot">'
      +     '<span data-words="' + esc(field) + '"></span>'
      +     '<span>Formatting is cleaned when you save.</span>'
      +   '</div>'
      + '</div></div>';
  }

  /* --------------------------------------------------------------- views */

  function pill(status){
    var label = {publish:'Published', draft:'Draft', private:'Private', scheduled:'Scheduled'}[status] || status;
    return '<span class="peo-pill is-' + esc(status) + '">' + esc(label) + '</span>';
  }

  function pickerView(){
    var rows;

    if (busy && !listing) {
      rows = '<div class="peo-empty">Loading…</div>';
    } else if (!listing || !listing.length) {
      rows = query
        ? '<div class="peo-empty"><b>Nothing matched “' + esc(query) + '”</b>'
          + 'Try part of a product name, a SKU, or clear the search.</div>'
        : '<div class="peo-empty"><b>No products yet</b>'
          + 'Add your first product and it will show up here.</div>';
    } else {
      rows = '<div class="peo-list">' + listing.map(function(p){
        var img = p.image
          ? '<img src="' + url(p.image) + '" alt="">'
          : '<span class="peo-noimg"></span>';

        return '<button class="peo-item" data-peo-open="' + esc(p.id) + '">'
             + img
             + '<span class="peo-meta"><b>' + esc(p.name) + '</b>'
             + '<span class="peo-m2">' + esc(p.brand || '—')
             + (p.price_aed ? ' · ' + esc(boot ? boot.currency.code : '') + ' ' + esc(p.price_aed) : '')
             + '</span></span>'
             + pill(p.status)
             + '</button>';
      }).join('') + '</div>';
    }

    return '<div class="peo-bar">'
        + '<div class="peo-grow"><h2>Products</h2>'
        + '<div class="peo-sub">Pick a product to edit, or add a new one.</div></div>'
        + '<button class="peo-btn peo-primary" data-new="1">＋ Add product</button>'
      + '</div>'
      + (banner ? '<div class="peo-banner is-bad">' + esc(banner) + '</div>' : '')
      + '<div class="peo-card">'
        + '<div class="peo-fld" style="margin-bottom:12px">'
        + '<input class="peo-in" id="peo-q" placeholder="Search by name, SKU or web address" value="' + esc(query) + '">'
        + '</div>'
        + rows
      + '</div>';
  }

  function altOf(u){
    return (model.image_alts && model.image_alts[u]) || '';
  }

  function galleryView(){
    var tiles = model.images.map(function(u, i){
      return '<div class="peo-tile" draggable="true" data-i="' + i + '">'
        + '<span class="peo-grip" title="Drag to reorder">⠿</span>'
        + '<img src="' + url(u) + '" alt="">'
        + '<span class="peo-body">'
        +   '<span class="peo-ord">Position ' + (i + 2) + '</span>'
        +   '<input class="peo-alt" data-alt="' + esc(u) + '" value="' + esc(altOf(u)) + '" '
        +     'placeholder="Describe this photo, e.g. texture on the back of a hand">'
        + '</span>'
        + '<span class="peo-acts">'
        +   '<button data-mv="' + i + ':-1"' + (i === 0 ? ' disabled' : '') + ' title="Move earlier">↑</button>'
        +   '<button data-mv="' + i + ':1"' + (i === model.images.length - 1 ? ' disabled' : '') + ' title="Move later">↓</button>'
        +   '<button class="peo-rm" data-rm="' + i + '" title="Remove">✕</button>'
        + '</span>'
      + '</div>';
    }).join('');

    var body = model.images.length
      ? '<div class="peo-gal" id="peo-gal">' + tiles + '</div>'
      : '<div class="peo-empty"><b>No gallery images yet</b>'
        + 'The main image is shown first. Add more and they appear after it, in this order.</div>';

    return '<section class="peo-card peo-media-gal">'
      + '<h3>Gallery</h3>'
      + '<p class="peo-hint">Drag the handle to reorder, or use ↑ ↓. This is the order customers see, '
      +   'after the main image. The description under each photo is what Google Images and screen '
      +   'readers read — write what is actually in the shot.</p>'
      + body
      + '<div style="height:10px"></div>'
      + '<button class="peo-drop" id="peo-galdrop"><b>Add gallery images</b>Drop them here, or tap to choose</button>'
      + '<input type="file" id="peo-galfile" accept="image/*" multiple hidden>'
      + '</section>';
  }

  /* The panel the operator actually sees. mainImageView() and galleryView()
     each return a half rather than their own card, so the two sit in one grid
     and cannot be dragged apart -- which is the point: they are one job.

     Every id inside them (peo-mainpick, peo-mainrm, peo-mainfile, peo-galdrop,
     peo-galfile, peo-gal) is unchanged and still appears exactly once in the
     document, so the delegated handlers and the drag-and-drop wiring below
     bind to the same elements they always did. */
  function imagesView(){
    /* TWO CARDS, side by side — not one card with two halves in it. The owner
       asked for two separate boxes and got a single bordered panel with two
       headings inside; from the outside that reads as one thing that happens to
       be split, which is not what was asked for.

       They still travel as ONE panel in the arrangement, which is the point of
       wrapping them rather than registering two: two registered panels could be
       dragged apart, or into different columns, and "side by side" would hold
       only until somebody moved something. */
    return '<div class="peo-mediawrap"><div class="peo-media">'
      + mainImageView()
      + galleryView()
      + '</div></div>';
  }

  function mainImageView(){
    var box = model.image
      ? '<img src="' + url(model.image) + '" alt="">'
      : '<div class="peo-ph">No main image yet</div>';

    return '<section class="peo-card peo-media-main">'
      + '<h3>Main image</h3>'
      + '<p class="peo-hint">The first picture customers see, on the shop grid and at the top of the product page.</p>'
      + '<div class="peo-main-img">' + box
      +   '<div class="peo-main-cap">'
      +     '<button class="peo-btn" id="peo-mainpick">' + (model.image ? 'Replace' : 'Upload') + '</button>'
      +     (model.image ? '<button class="peo-btn peo-danger" id="peo-mainrm">Remove</button>' : '')
      +   '</div>'
      + '</div>'
      + (model.image
          ? '<div class="peo-fld" style="margin-top:11px"><label>Image description</label>'
            + '<input class="peo-in" data-alt="' + esc(model.image) + '" value="' + esc(altOf(model.image)) + '" '
            +   'placeholder="e.g. Anua Heartleaf Toner bottle, front">'
            + '<div class="peo-note">Read by Google Images and by screen readers. '
            +   'Left empty, the shop uses the brand and product name.</div></div>'
          : '')
      + '<input type="file" id="peo-mainfile" accept="image/*" hidden>'
      + '</section>';
  }

  function categoriesView(){
    var cats = (boot && boot.categories) || [];

    if (!cats.length) {
      return '<div class="peo-card"><h3>Categories</h3>'
        + '<div class="peo-empty"><b>No categories yet</b>Create one under Catalog → Categories first.</div></div>';
    }

    var list = cats.map(function(c){
      var on = model.category_ids.indexOf(c.id) !== -1;
      return '<label class="peo-check">'
        + '<input type="checkbox" data-cat="' + c.id + '"' + (on ? ' checked' : '') + '>'
        + '<span>' + esc(c.name) + '</span></label>';
    }).join('');

    var options = model.category_ids.map(function(id){
      var c = cats.filter(function(x){ return x.id === id; })[0];
      if (!c) return '';
      return '<option value="' + c.id + '"' + (model.primary_category_id === c.id ? ' selected' : '') + '>'
           + esc(c.name) + '</option>';
    }).join('');

    return '<div class="peo-card">'
      + '<h3>Categories</h3>'
      + '<p class="peo-hint">A product can sit in as many as you like. It appears on every one of their pages.</p>'
      + '<div style="max-height:220px;overflow:auto;min-width:0">' + list + '</div>'
      + '<div class="peo-fld" style="margin-top:12px"><label>Main category</label>'
      + '<select class="peo-sel" data-bind="primary_category_id">'
      +   (options || '<option value="">Tick a category first</option>')
      + '</select>'
      + '<div class="peo-note">Used for the breadcrumb on the product page.</div></div>'
      + '</div>';
  }

  function publishView(){
    var s = model.status;

    return '<div class="peo-card">'
      + '<h3>Publishing</h3>'
      + '<div class="peo-fld"><label>Status</label>'
      +   '<select class="peo-sel" data-bind="status" id="peo-status">'
      +     '<option value="draft"' + (s === 'draft' ? ' selected' : '') + '>Draft — only you can see it</option>'
      +     '<option value="publish"' + (s === 'publish' ? ' selected' : '') + '>Published — live on the shop</option>'
      +     '<option value="private"' + (s === 'private' ? ' selected' : '') + '>Private — hidden from the shop</option>'
      +     '<option value="scheduled"' + (s === 'scheduled' ? ' selected' : '') + '>Scheduled — goes live on a date</option>'
      +   '</select></div>'
      + '<div class="peo-fld"' + (s === 'scheduled' ? '' : ' hidden') + ' id="peo-when">'
      +   '<label>Goes live on</label>'
      +   '<input class="peo-in" type="datetime-local" data-bind="published_at" value="'
      +     esc(model.published_at || '') + '">'
      +   '<div class="peo-note">Until then it is hidden from the shop, search and Google. '
      +     'It appears by itself at that time — nothing needs to be run.</div></div>'
      + '<label class="peo-check"><input type="checkbox" data-bind="is_visible"'
      +   (model.is_visible ? ' checked' : '') + '><span>Show in the shop and search</span></label>'
      + '<label class="peo-check"><input type="checkbox" data-bind="featured"'
      +   (model.featured ? ' checked' : '') + '><span>Feature on the home page</span></label>'
      + (model.id
          ? '<div class="peo-note" style="margin-top:10px">Web address: <code>' + esc(model.readonly.url) + '</code><br>'
            + 'Fixed once a product exists — customers and Google hold this link.</div>'
          : '')
      + '</div>';
  }

  function pricingView(){
    var code = boot ? boot.currency.code : '';

    return '<div class="peo-card">'
      + '<h3>Price</h3>'
      + '<div class="peo-row">'
      +   '<div class="peo-fld"><label>Price (' + esc(code) + ')</label>'
      +     '<input class="peo-in" inputmode="decimal" data-bind="price_aed" value="' + esc(model.price_aed || '') + '" placeholder="99.50"></div>'
      +   '<div class="peo-fld"><label>Sale price</label>'
      +     '<input class="peo-in" inputmode="decimal" data-bind="sale_aed" value="' + esc(model.sale_aed || '') + '" placeholder="79.00"></div>'
      + '</div>'
      + '<div class="peo-note" style="margin:-4px 0 12px">Plain numbers only — no symbol, no commas.</div>'
      + '<div class="peo-row">'
      +   '<div class="peo-fld"><label>Sale starts</label>'
      +     '<input class="peo-in" type="datetime-local" data-bind="sale_starts_at" value="' + esc(model.sale_starts_at || '') + '"></div>'
      +   '<div class="peo-fld"><label>Sale ends</label>'
      +     '<input class="peo-in" type="datetime-local" data-bind="sale_ends_at" value="' + esc(model.sale_ends_at || '') + '"></div>'
      + '</div>'
      + '</div>';
  }

  function stockView(){
    return '<div class="peo-card">'
      + '<h3>Stock</h3>'
      + '<div class="peo-fld"><label>Availability</label>'
      +   '<select class="peo-sel" data-bind="stock_status">'
      +     ['instock:In stock', 'outofstock:Out of stock', 'onbackorder:On backorder'].map(function(o){
              var p = o.split(':');
              return '<option value="' + p[0] + '"' + (model.stock_status === p[0] ? ' selected' : '') + '>' + p[1] + '</option>';
            }).join('')
      +   '</select></div>'
      + '<label class="peo-check"><input type="checkbox" data-bind="manage_stock"'
      +   (model.manage_stock ? ' checked' : '') + '><span>Count individual units</span></label>'
      + '<div class="peo-fld"><label>Units in stock</label>'
      +   '<input class="peo-in" inputmode="numeric" data-bind="stock" value="' + esc(model.stock == null ? '' : model.stock) + '"></div>'
      + '</div>';
  }

  function brandView(){
    var brands = (boot && boot.brands) || [];

    return '<div class="peo-card">'
      + '<h3>Brand</h3>'
      + '<select class="peo-sel" data-bind="brand_id">'
      +   '<option value="">No brand</option>'
      +   brands.map(function(b){
            return '<option value="' + b.id + '"' + (model.brand_id === b.id ? ' selected' : '') + '>'
                 + esc(b.name) + '</option>';
          }).join('')
      + '</select></div>';
  }

  function seoView(){
    var seo = model.seo || {};

    return '<div class="peo-card">'
      + '<h3>Search appearance</h3>'
      + '<p class="peo-hint">How this product looks on Google. Leave a box empty and the shop writes a sensible default.</p>'
      + '<div class="peo-snip" id="peo-snip">'
      +   '<div class="u" id="peo-snip-u"></div>'
      +   '<div class="t" id="peo-snip-t"></div>'
      +   '<div class="d" id="peo-snip-d"></div>'
      + '</div>'
      + '<div style="height:13px"></div>'
      + '<div class="peo-fld"><label>Page title <span class="peo-count" data-count="seo-title"></span></label>'
      +   '<input class="peo-in" data-bind="seo.title" id="peo-seo-t" value="' + esc(seo.title || '') + '" '
      +     'placeholder="' + esc(model.name || 'Product name') + '"></div>'
      + '<div class="peo-fld"><label>Description <span class="peo-count" data-count="seo-desc"></span></label>'
      +   '<textarea class="peo-ta" data-bind="seo.desc" id="peo-seo-d" '
      +     'placeholder="One or two sentences a shopper would click.">' + esc(seo.desc || '') + '</textarea></div>'
      + '<div class="peo-fld"><label>Canonical URL</label>'
      +   '<input class="peo-in" data-bind="seo.canonical" value="' + esc(seo.canonical || '') + '" '
      +     'placeholder="Leave empty unless this page duplicates another"></div>'
      + '<div class="peo-fld"><label>Share image</label>'
      +   '<input class="peo-in" data-bind="seo.og_image" id="peo-og" value="' + esc(seo.og_image || '') + '" '
      +     'placeholder="Uses the main image if empty">'
      +   '<div style="height:7px"></div>'
      +   '<button class="peo-btn" id="peo-ogpick">Upload a share image</button>'
      +   '<input type="file" id="peo-ogfile" accept="image/*" hidden></div>'
      + '<label class="peo-check"><input type="checkbox" data-bind="seo.noindex"'
      +   (seo.noindex ? ' checked' : '') + '><span>Ask Google not to list this product</span></label>'
      + '</div>';
  }

  function basicsView(){
    var creating = !model.id;

    return '<div class="peo-card">'
      + '<h3>Basics</h3>'
      + '<div class="peo-fld"><label>Product name</label>'
      +   '<input class="peo-in" data-bind="name" id="peo-name" value="' + esc(model.name) + '" placeholder="e.g. Anua Heartleaf Toner"></div>'
      + (creating
          ? '<div class="peo-fld"><label>Web address</label>'
            + '<input class="peo-in" data-bind="slug" id="peo-slug" value="' + esc(model.slug) + '" placeholder="anua-heartleaf-toner">'
            + '<div class="peo-note" id="peo-slugnote">Set once. It cannot be changed after the product exists.</div></div>'
          : '')
      + '<div class="peo-row">'
      +   '<div class="peo-fld"><label>SKU</label>'
      +     '<input class="peo-in" data-bind="sku" value="' + esc(model.sku || '') + '" placeholder="Your own code"></div>'
      +   '<div class="peo-fld"><label>Barcode (GTIN)</label>'
      +     '<input class="peo-in" inputmode="numeric" data-bind="gtin" value="' + esc(model.gtin || '') + '" placeholder="e.g. 8809525360024"></div>'
      + '</div>'
      + '<div class="peo-note" style="margin:-6px 0 0">The number under the barcode — 8, 12, 13 or 14 digits. '
      +   'Google uses it to match this product to the same item elsewhere, which your own SKU cannot do. '
      +   'Leave it empty if the product has none.</div>'
      + '</div>';
  }

  /* ------------------------------------------------- the panel registry ----

     THE ONE LIST. Adding a panel to this screen means adding a line here and
     nothing else: the arrange controls, the persistence, the reconciliation of
     older saved layouts and the reset all read from it.

     `col` is the DEFAULT column, used for the build's default arrangement and
     for placing a panel that a saved layout predates. It is not where the
     panel currently is -- that is `layout`.

     Media sits in the MAIN column by default, not the sidebar, and that is a
     decision the first screenshot forced. Every gallery row carries an
     alt-text box, and in the 320px sidebar that box was about seventy pixels
     of usable width — a field for writing a sentence, sized for writing a
     word. Photographs are primary content on a product page anyway; the
     sidebar is for the switches. The operator may now overrule all of that,
     which is the point of the feature.

     The keys are STORED VALUES. They appear in rows in admin_screen_layouts
     and in the ordering an operator has already arranged, so renaming one
     silently moves that panel back to its default position for everybody who
     had moved it. Add and remove freely; rename only on purpose. */
  var COLUMNS = ['main', 'side'];

  var PANELS = [
    { key: 'basics',     label: 'Basics',            col: 'main', view: basicsView },
    /* One panel, not two. The owner asked for the main image and the gallery
       beside each other; leaving them as separate draggable panels would have
       let an arrangement put them back in a stack, or in different columns,
       and the request would hold only until someone dragged something.

       'main_image' and 'gallery' are retired keys. reconcile() drops names it
       does not recognise and appends registry panels a saved document has
       never heard of, so an operator who had already arranged this screen
       keeps every other panel where they put it and finds Images appended to
       the main column -- a stale preference, not a broken screen. */
    { key: 'images',     label: 'Images',            col: 'main', view: function(){ return imagesView(); } },
    { key: 'short_description', label: 'Short description', col: 'main', view: function(){
        return rte('short_description', 'Short description',
          'The summary beside the price. One or two lines.',
          'A gentle daily toner that calms redness…', model.short_description); } },
    { key: 'description', label: 'Full description', col: 'main', view: function(){
        return rte('description', 'Full description',
          'The main tab on the product page. Headings, bold, lists and links all work.',
          'Tell the customer what it does, who it suits, and what makes it worth buying…', model.description); } },
    { key: 'ingredients', label: 'Ingredients',      col: 'main', view: function(){
        return rte('ingredients', 'Ingredients',
          'Shown as its own tab. Paste the INCI list here.',
          'Water, Glycerin, Niacinamide…', model.ingredients); } },
    { key: 'how_to_use', label: 'How to use',        col: 'main', view: function(){
        return rte('how_to_use', 'How to use',
          'Shown as its own tab. A short routine works best.',
          'After cleansing, apply to a cotton pad and sweep over the face…', model.how_to_use); } },
    { key: 'seo',        label: 'Search appearance', col: 'main', view: function(){ return seoView(); } },
    { key: 'publish',    label: 'Publishing',        col: 'side', view: function(){ return publishView(); } },
    { key: 'categories', label: 'Categories',        col: 'side', view: function(){ return categoriesView(); } },
    { key: 'brand',      label: 'Brand',             col: 'side', view: function(){ return brandView(); } },
    { key: 'pricing',    label: 'Price',             col: 'side', view: function(){ return pricingView(); } },
    { key: 'stock',      label: 'Stock',             col: 'side', view: function(){ return stockView(); } }
  ];

  function panelByKey(key){
    for (var i = 0; i < PANELS.length; i++) {
      if (PANELS[i].key === key) return PANELS[i];
    }
    return null;
  }

  /* The arrangement this build ships. Derived from the registry rather than
     written out a second time, so it cannot drift from it. */
  function defaultLayout(){
    var out = {};
    COLUMNS.forEach(function(c){ out[c] = []; });
    PANELS.forEach(function(p){ out[p.col].push(p.key); });
    return out;
  }

  /* -------------------------------------------------------- reconcile -----

     A SAVED ARRANGEMENT WILL OUTLIVE THE BUILD THAT WROTE IT. Someone arranges
     their editor today; a package next month adds a panel, or retires one. Two
     failures follow if nothing reconciles, and both of them look like a broken
     screen rather than a stale preference:

       - a key the build no longer has would be looked up, come back null, and
         either throw or render as a hole where a panel used to be;
       - a panel the document never mentions would simply never be rendered --
         an operator whose editor has silently lost the field they need, with
         no error anywhere and no way to work out that a preference did it.

     So: names that are not in the registry are DROPPED, and registry panels
     that no document mentions are APPENDED to their own default column in
     registry order. A key listed in both columns keeps its first occurrence,
     because rendering the same panel twice would duplicate its inputs and
     collect() would then read whichever one the browser returned last.

     The result is that this function accepts ANY input at all -- null, a
     string, an array, a document full of names that mean nothing -- and always
     returns a complete arrangement containing every panel exactly once. That
     is asserted from both ends in ProductEditorLayoutTest and in the browser
     check. The next save writes the reconciled document back, so stale names
     age out without anybody migrating anything. */
  function reconcile(saved){
    var out = {};
    var placed = {};

    COLUMNS.forEach(function(c){ out[c] = []; });

    if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
      COLUMNS.forEach(function(c){
        var list = saved[c];
        if (!Array.isArray(list)) return;

        list.forEach(function(key){
          if (typeof key !== 'string') return;      // not a name at all
          if (placed[key]) return;                  // already placed: duplicate
          if (!panelByKey(key)) return;             // a panel this build removed
          placed[key] = true;
          out[c].push(key);
        });
      });
    }

    // A panel this build ADDED, that the saved document predates.
    PANELS.forEach(function(p){
      if (placed[p.key]) return;
      placed[p.key] = true;
      out[p.col].push(p.key);
    });

    return out;
  }

  /* Is the current arrangement the one the build ships? Drives whether Reset
     is offered at all -- a button that undoes nothing is noise. */
  function layoutIsDefault(){
    return JSON.stringify(layout) === JSON.stringify(defaultLayout());
  }

  /* Below 900px .peo-grid collapses to a single column, so the two columns are
     one stack and "the other column" names nothing the operator can see. Every
     control that depends on which of the two modes we are in asks here. */
  function narrow(){
    try { return window.matchMedia('(max-width:900px)').matches; } catch (e) { return false; }
  }

  /* --------------------------------------------------------------- views */

  function arrangeBar(p, col, i){
    var keys = layout[col];
    var stack = flatten();
    var at = stack.map(function(e){ return e.key; }).indexOf(p.key);

    // Up and down mean different things at the two widths, so what counts as
    // "already at the end" does too. Wide: the end of this column. Narrow: the
    // end of the whole stack, because the buttons step across the boundary.
    var first = narrow() ? at <= 0 : i <= 0;
    var last  = narrow() ? at >= stack.length - 1 : i >= keys.length - 1;

    var other = col === 'main' ? 'side' : 'main';

    return '<div class="peo-arrbar" draggable="true" data-peo-drag="' + esc(p.key) + '">'
      + '<span class="peo-grip" aria-hidden="true">⠿</span>'
      + '<span class="peo-arrname">' + esc(p.label) + '</span>'
      + '<span class="peo-arracts">'
      +   '<button type="button" data-peo-mv="' + esc(p.key) + ':-1"' + (first ? ' disabled' : '')
      +     ' title="Move ' + esc(p.label) + ' up" aria-label="Move ' + esc(p.label) + ' up">↑</button>'
      +   '<button type="button" data-peo-mv="' + esc(p.key) + ':1"' + (last ? ' disabled' : '')
      +     ' title="Move ' + esc(p.label) + ' down" aria-label="Move ' + esc(p.label) + ' down">↓</button>'
      +   '<button type="button" data-peo-col="' + esc(p.key) + '"'
      +     ' title="Move ' + esc(p.label) + ' to the ' + (other === 'main' ? 'wide' : 'narrow') + ' column"'
      +     ' aria-label="Move ' + esc(p.label) + ' to the ' + (other === 'main' ? 'wide' : 'narrow') + ' column">'
      +     (col === 'main' ? '→' : '←') + '</button>'
      + '</span>'
      + '</div>';
  }

  /* One panel, wrapped. The wrapper is the drop target and carries the key;
     the card inside is whatever the panel's own view function returned,
     untouched. */
  function panelHtml(p, col, i){
    if (!p) return '';

    if (!arranging) {
      return '<div class="peo-panel" data-peo-panel="' + esc(p.key) + '">' + p.view() + '</div>';
    }

    return '<div class="peo-panel peo-arranging" data-peo-panel="' + esc(p.key) + '">'
      + arrangeBar(p, col, i)
      + p.view()
      + '</div>';
  }

  function columnHtml(col){
    var keys = layout[col];

    var body = keys.map(function(key, i){
      return panelHtml(panelByKey(key), col, i);
    }).join('');

    // An emptied column keeps a drop target while arranging, so a layout that
    // moved everything one way can be dragged back rather than only reset.
    if (arranging && !keys.length) {
      body = '<div class="peo-slot" data-peo-slot="' + esc(col) + '">Drop a panel here</div>';
    }

    return '<div class="peo-col" data-peo-colname="' + esc(col) + '">' + body + '</div>';
  }

  function arrangeNote(){
    if (!arranging) return '';

    var text = narrow()
      ? 'Drag a panel by its handle, or use ↑ ↓ — on a narrow screen the two columns '
        + 'are shown as one list, so ↑ and ↓ step through all of it and a panel that '
        + 'passes the join moves between the columns you would see on a wider screen.'
      : 'Drag a panel by its handle, or use ↑ ↓ to move it within its column and '
        + '← → to send it to the other one. Your arrangement is saved as you go, '
        + 'for your sign-in only.';

    return '<div class="peo-arrnote">' + esc(text) + '</div>';
  }

  function editorView(){
    var creating = !model.id;

    /* The editor can paint before the stored arrangement has come back -- a
       render is triggered the moment a product loads, and the preference is a
       second request. It paints on the build's default until then and repaints
       when loadLayout() resolves, rather than blocking the product behind a
       preference. */
    if (!layout) layout = defaultLayout();

    /* Reset is offered in the save bar whenever the arrangement differs from
       the one the build ships -- not only inside arrange mode. A rearranged
       layout is exactly the thing somebody mangles and then cannot undo, and
       the undo for it should not itself be somewhere they have to find. It is
       absent when the layout is already the default, because a button that
       does nothing is worse than no button. */
    var canReset = layout && !layoutIsDefault();

    return '<div class="peo-bar">'
        + '<button class="peo-btn" id="peo-back">← Products</button>'
        + '<div class="peo-grow"><h2>' + esc(model.name || (creating ? 'New product' : 'Untitled')) + '</h2>'
        +   '<div class="peo-sub">' + (dirty ? 'Unsaved changes' : (creating ? 'Not saved yet' : 'All changes saved')) + '</div>'
        + '</div>'
        + (canReset
            ? '<button class="peo-btn" id="peo-arrreset" title="Put every panel back where this build puts it">Reset layout</button>'
            : '')
        + '<button class="peo-btn" id="peo-arrange"' + (arranging ? ' style="border-color:#1f7d52;color:#1f7d52"' : '') + '>'
        +   (arranging ? 'Done arranging' : 'Arrange') + '</button>'
        + pill(model.status)
        + '<button class="peo-btn peo-primary" id="peo-save"' + (busy ? ' disabled' : '') + '>'
        +   (busy ? 'Saving…' : (creating ? 'Create product' : 'Save')) + '</button>'
      + '</div>'
      + (banner ? '<div class="peo-banner is-bad">' + esc(banner) + '</div>' : '')
      + arrangeNote()
      + '<div class="peo-grid">'
        + columnHtml('main')
        + columnHtml('side')
      + '</div>';
  }

  function render(){
    var host = document.querySelector('#view') || document.querySelector('#main') || document.querySelector('.content');
    if (!host) return;

    // Only paint when this screen is the one showing, so a render triggered by
    // a late response cannot overwrite whatever the operator navigated to.
    var active = document.querySelector('.side .nav-item.on');
    if (!active || active.dataset.go !== SCREEN) return;

    host.innerHTML = '<div class="peo-wrap">' + (model ? editorView() : pickerView()) + '</div>';
    bind();
  }

  /* ---------------------------------------------------------------- bind */

  function on(sel, ev, fn){
    var el = typeof sel === 'string' ? document.querySelector('#content ' + sel) : sel;
    if (el) el.addEventListener(ev, fn);
    return el;
  }

  function bind(){
    if (!model) { bindPicker(); return; }
    bindEditor();
  }

  function bindPicker(){
    var q = on('#peo-q', 'input', function(){
      query = q.value;
      clearTimeout(bindPicker._t);
      bindPicker._t = setTimeout(loadList, 220);
    });

    document.querySelectorAll('#content [data-peo-open]').forEach(function(b){
      b.onclick = function(){ loadProduct(parseInt(b.dataset.peoOpen, 10)); };
    });

    var add = document.querySelector('#content [data-new]');
    if (add) add.onclick = function(){ model = blank(); dirty = false; banner = null; render(); };
  }

  function bindEditor(){
    /* ---- the save bar ---- */
    on('#peo-back', 'click', function(){
      if (dirty && !window.confirm('You have unsaved changes. Leave without saving?')) return;
      model = null; dirty = false; banner = null;
      render(); loadList();
    });

    on('#peo-save', 'click', save);

    /* ---- panel arrangement ---- */
    bindArrange();

    /* ---- plain fields ---- */
    document.querySelectorAll('#content [data-bind]').forEach(function(el){
      var ev = (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'datetime-local')
             ? 'change' : 'input';

      el.addEventListener(ev, function(){
        dirty = true;

        if (el.dataset.bind === 'status') {
          // Re-render only this one control's dependent field, rather than the
          // whole screen: a full render() here would blow away every
          // contenteditable pane mid-typing.
          collect();
          var when = document.querySelector('#content #peo-when');
          if (when) when.hidden = el.value !== 'scheduled';

          var p = document.querySelector('#content .peo-bar .peo-pill');
          if (p) p.className = 'peo-pill is-' + el.value;
          if (p) p.textContent = {publish:'Published', draft:'Draft', private:'Private', scheduled:'Scheduled'}[el.value] || el.value;
        }

        if (el.id === 'peo-name') {
          var h = document.querySelector('#content .peo-bar h2');
          if (h) h.textContent = el.value || 'New product';
          if (!model.id) suggestSlug(el.value);
          snippet();
        }

        if (el.id === 'peo-seo-t' || el.id === 'peo-seo-d') snippet();

        markDirty();
      });
    });

    /* ---- categories ---- */
    document.querySelectorAll('#content [data-cat]').forEach(function(box){
      box.addEventListener('change', function(){
        var id = parseInt(box.dataset.cat, 10);
        var at = model.category_ids.indexOf(id);

        if (box.checked && at === -1) model.category_ids.push(id);
        if (!box.checked && at !== -1) model.category_ids.splice(at, 1);

        if (model.category_ids.indexOf(model.primary_category_id) === -1) {
          model.primary_category_id = model.category_ids[0] || null;
        }

        dirty = true;
        collect();
        markDirty();
        // The main-category select lists only ticked categories, so it has to
        // be rebuilt. Replaced in place rather than via render(), for the same
        // reason as the status control above: a full render would blow away
        // every contenteditable pane mid-edit.
        redrawCategories();
      });
    });

    /* ---- images ---- */
    var mainFile = document.querySelector('#content #peo-mainfile');
    on('#peo-mainpick', 'click', function(){ if (mainFile) mainFile.click(); });
    if (mainFile) mainFile.addEventListener('change', function(){ takeFiles(mainFile.files, true); });

    on('#peo-mainrm', 'click', function(){
      model.image = null; dirty = true; collect(); render();
    });

    var galFile = document.querySelector('#content #peo-galfile');
    var drop = document.querySelector('#content #peo-galdrop');

    if (drop) {
      drop.onclick = function(){ if (galFile) galFile.click(); };

      ['dragenter','dragover'].forEach(function(ev){
        drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('peo-over'); });
      });
      ['dragleave','drop'].forEach(function(ev){
        drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('peo-over'); });
      });
      drop.addEventListener('drop', function(e){
        if (e.dataTransfer && e.dataTransfer.files) takeFiles(e.dataTransfer.files, false);
      });
    }

    if (galFile) galFile.addEventListener('change', function(){ takeFiles(galFile.files, false); });

    document.querySelectorAll('#content [data-rm]').forEach(function(b){
      b.onclick = function(e){
        e.stopPropagation();
        model.images.splice(parseInt(b.dataset.rm, 10), 1);
        dirty = true; collect(); render();
      };
    });

    /* Move buttons beside the drag handles.
       Not decoration: HTML5 drag-and-drop does not fire on touch, and the
       owner reviews this shop on a phone. Dragging is the desktop path and
       these are the one that works everywhere. */
    document.querySelectorAll('#content [data-mv]').forEach(function(b){
      b.onclick = function(e){
        e.stopPropagation();
        var parts = b.dataset.mv.split(':');
        var from = parseInt(parts[0], 10);
        var to = from + parseInt(parts[1], 10);
        if (to < 0 || to >= model.images.length) return;

        var moved = model.images.splice(from, 1)[0];
        model.images.splice(to, 0, moved);
        dirty = true; collect(); render();
      };
    });

    /* Alt text. Read back in collect() by URL, so this listener only has to
       mark the form dirty — it must NOT re-render, or the input being typed
       into would be replaced mid-keystroke. */
    document.querySelectorAll('#content [data-alt]').forEach(function(el){
      el.addEventListener('input', function(){
        model.image_alts = model.image_alts || {};
        model.image_alts[el.dataset.alt] = el.value;
        dirty = true;
        markDirty();
      });
    });

    bindDrag();

    /* ---- SEO ---- */
    var ogFile = document.querySelector('#content #peo-ogfile');
    on('#peo-ogpick', 'click', function(){ if (ogFile) ogFile.click(); });
    if (ogFile) ogFile.addEventListener('change', async function(){
      if (!ogFile.files || !ogFile.files[0]) return;
      busy = true; render();
      try {
        var u = await upload(ogFile.files[0]);
        model.seo = model.seo || {};
        model.seo.og_image = u;
        dirty = true;
      } catch (e) { banner = message(e, 'That image could not be uploaded.'); }
      busy = false; render();
    });

    /* ---- rich text ---- */
    document.querySelectorAll('#content .peo-rte').forEach(bindRte);

    snippet();
    counts();
  }

  function markDirty(){
    var sub = document.querySelector('#content .peo-bar .peo-sub');
    if (sub) sub.textContent = 'Unsaved changes';
  }

  function redrawCategories(){
    // Rebuild only the main-category select's options.
    var sel = document.querySelector('#content [data-bind="primary_category_id"]');
    if (!sel) return;

    var cats = (boot && boot.categories) || [];
    sel.innerHTML = model.category_ids.length
      ? model.category_ids.map(function(id){
          var c = cats.filter(function(x){ return x.id === id; })[0];
          return c ? '<option value="' + c.id + '"' + (model.primary_category_id === c.id ? ' selected' : '') + '>'
                   + esc(c.name) + '</option>' : '';
        }).join('')
      : '<option value="">Tick a category first</option>';
  }

  function bindRte(box){
    var area = box.querySelector('.peo-rte-area');
    if (!area) return;

    box.querySelectorAll('.peo-rte-bar button').forEach(function(b){
      b.onclick = function(e){
        e.preventDefault();
        area.focus();

        var cmd = b.dataset.cmd;

        if (cmd.indexOf('formatBlock:') === 0) {
          document.execCommand('formatBlock', false, cmd.split(':')[1]);
        } else if (cmd === 'createLink') {
          var href = window.prompt('Link address (https://…)');
          if (href) document.execCommand('createLink', false, href);
        } else {
          document.execCommand(cmd, false, null);
        }

        model[area.dataset.field] = area.innerHTML;
        dirty = true; markDirty(); words(area);
      };
    });

    area.addEventListener('input', function(){
      model[area.dataset.field] = area.innerHTML;
      dirty = true; markDirty(); words(area);
    });

    /* Paste as PLAIN TEXT.
       Pasting from Word or a browser carries a mountain of markup, and while
       the server strips all of it on save, the owner would spend the minutes
       in between looking at a pane that does not resemble what they will get.
       Stripping it here means what is on screen is what is stored. */
    area.addEventListener('paste', function(e){
      e.preventDefault();
      var text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });

    words(area);
  }

  function words(area){
    var out = document.querySelector('#content [data-words="' + area.dataset.field + '"]');
    if (!out) return;

    var text = (area.textContent || '').replace(/\s+/g, ' ').trim();
    var n = text ? text.split(' ').length : 0;
    out.textContent = n + (n === 1 ? ' word' : ' words');
  }

  /* ---- the Google preview, and the counters that go with it ---- */
  function snippet(){
    if (!model) return;

    var t = document.querySelector('#content #peo-snip-t');
    var d = document.querySelector('#content #peo-snip-d');
    var u = document.querySelector('#content #peo-snip-u');
    if (!t || !d || !u) return;

    var seoT = (document.querySelector('#content #peo-seo-t') || {}).value || '';
    var seoD = (document.querySelector('#content #peo-seo-d') || {}).value || '';
    var name = (document.querySelector('#content #peo-name') || {}).value || model.name || 'Product';

    t.textContent = clip(seoT || name, 60);
    d.textContent = clip(seoD || stripTags(model.short_description) || 'Add a description so Google shows the right words here.', 155);
    u.textContent = (model.readonly && model.readonly.url) || ('/product/' + (model.slug || 'new-product') + '/');

    counts();
  }

  function counts(){
    count('seo-title', (document.querySelector('#content #peo-seo-t') || {}).value || '', 50, 60);
    count('seo-desc', (document.querySelector('#content #peo-seo-d') || {}).value || '', 120, 155);
  }

  function count(key, value, good, max){
    var el = document.querySelector('#content [data-count="' + key + '"]');
    if (!el) return;

    var n = value.length;
    el.textContent = n + ' / ' + max;
    el.className = 'peo-count' + (n > max ? ' peo-bad' : (n && n < good ? ' peo-warn' : ''));
  }

  function clip(s, n){
    s = String(s || '');
    return s.length > n ? s.slice(0, n - 1) + '…' : s;
  }

  function stripTags(html){
    var d = document.createElement('div');
    d.innerHTML = String(html || '');
    return (d.textContent || '').replace(/\s+/g, ' ').trim();
  }

  /* ---- slug suggestion, create only ---- */
  var slugTimer = null;

  function suggestSlug(name){
    var field = document.querySelector('#content #peo-slug');
    var note = document.querySelector('#content #peo-slugnote');
    if (!field || field.dataset.touched === '1') return;

    clearTimeout(slugTimer);
    slugTimer = setTimeout(async function(){
      try {
        var r = await api('/product-editor-slug', {json: {name: name}});
        field.value = r.suggestion || r.slug || '';
        model.slug = field.value;
        if (note) {
          note.textContent = r.available
            ? 'Web address is free: ' + r.url
            : 'That address is taken — using ' + r.suggestion + ' instead.';
        }
      } catch (e) { /* a suggestion is a nicety; the server decides on create */ }
    }, 300);
  }

  /* ---- gallery drag and drop ---- */
  /* ============================================================ arranging ==

     THE STACK, AND WHY IT IS THE SAME THING AT BOTH WIDTHS.

     flatten() returns every panel in the order the ONE-COLUMN layout paints
     them: all of `main`, then all of `side`. That is not a convenience for the
     narrow case -- it is literally the DOM order, because .peo-grid at
     <=900px is a single column and the two .peo-col elements stack. So the
     narrow-width answer to "what does up and down mean" needs no separate
     model: it is this list, and the column boundary is just a position in it.

     A move on a narrow screen therefore changes at most ONE panel's column --
     the one the operator actually moved, which takes the column of the
     neighbour it trades places with. Everything else keeps its own. That is
     what stops a phone reorder from quietly scrambling the desktop
     arrangement: the operator sees a panel move one place, and one place is
     all that moves.
  ------------------------------------------------------------------------ */

  /** @return array of {key, col} in single-column paint order. */
  function flatten(){
    var out = [];
    COLUMNS.forEach(function(c){
      layout[c].forEach(function(k){ out.push({ key: k, col: c }); });
    });
    return out;
  }

  /** Where a panel currently is. Null if it is not placed at all. */
  function locate(key){
    for (var c = 0; c < COLUMNS.length; c++) {
      var at = layout[COLUMNS[c]].indexOf(key);
      if (at !== -1) return { col: COLUMNS[c], i: at };
    }
    return null;
  }

  /** Rewrite `layout` from a flattened list, preserving order within each column. */
  function unflatten(stack){
    var out = {};
    COLUMNS.forEach(function(c){ out[c] = []; });
    stack.forEach(function(e){ if (out[e.col]) out[e.col].push(e.key); });
    layout = out;
  }

  /* Wide: up and down move a panel inside its own column, and the column is
     changed only by the ← → button. Two separate gestures for two separate
     things, because on a wide screen the operator can see both columns and a
     panel that jumped between them because it ran off the bottom would be a
     surprise rather than a move. */
  function moveWithinColumn(key, delta){
    var at = locate(key);
    if (!at) return false;

    var keys = layout[at.col];
    var to = at.i + delta;
    if (to < 0 || to >= keys.length) return false;

    keys.splice(to, 0, keys.splice(at.i, 1)[0]);
    return true;
  }

  /* Narrow: up and down step through the whole stack, crossing the boundary.
     Nothing here is a no-op that looks like a control -- the only time the
     buttons refuse is at the very top and the very bottom of the stack, and
     they are rendered disabled there. */
  function moveFlat(key, delta){
    var stack = flatten();
    var i = -1;

    for (var n = 0; n < stack.length; n++) {
      if (stack[n].key === key) { i = n; break; }
    }

    var j = i + delta;
    if (i === -1 || j < 0 || j >= stack.length) return false;

    var me = stack[i];
    var other = stack[j];

    // The moved panel takes its new neighbour's column. Because j is always
    // i±1, the reinsertion lands immediately beside `other`, so the rebuilt
    // columns are contiguous runs and the stack order after unflatten() is the
    // order computed here -- a panel never appears to jump two places.
    stack.splice(i, 1);
    me.col = other.col;
    stack.splice(j, 0, me);

    unflatten(stack);
    return true;
  }

  function movePanel(key, delta){
    return narrow() ? moveFlat(key, delta) : moveWithinColumn(key, delta);
  }

  /* Send a panel to the other column, keeping its position as closely as the
     other column's length allows. Clamped rather than appended: a panel at the
     top of the sidebar should arrive at the top of the main column, not eight
     panels below the fold where the operator has to go looking for it. */
  function moveColumn(key){
    var at = locate(key);
    if (!at) return false;

    var other = at.col === 'main' ? 'side' : 'main';

    layout[at.col].splice(at.i, 1);
    layout[other].splice(Math.min(at.i, layout[other].length), 0, key);
    return true;
  }

  /* Drop `src` into the position `dst` currently occupies, in dst's column.
     dst is located AGAIN after src is removed, because removing src from the
     same column shifts everything after it down by one and the pre-removal
     index would insert a place too low. */
  function dropOn(src, dst){
    if (!src || !dst || src === dst) return false;
    if (!panelByKey(src) || !panelByKey(dst)) return false;

    var from = locate(src);
    if (!from) return false;

    layout[from.col].splice(from.i, 1);

    var to = locate(dst);
    if (!to) { layout[from.col].splice(from.i, 0, src); return false; }

    layout[to.col].splice(to.i, 0, src);
    return true;
  }

  function dropInColumn(src, col){
    if (!src || COLUMNS.indexOf(col) === -1 || !panelByKey(src)) return false;

    var from = locate(src);
    if (!from) return false;
    if (from.col === col && layout[col].length === 1) return false;

    layout[from.col].splice(from.i, 1);
    layout[col].push(src);
    return true;
  }

  /* ------------------------------------------------- arrangement storage */

  /* A layout is furniture. If it cannot be read the editor still has to open,
     on the build's default arrangement -- the alternative is a product screen
     that refuses to load over a preference, which is a far worse failure than
     panels being in the wrong order. Same on write: a failed save says so and
     leaves the screen alone rather than snapping panels back under the
     operator's hands. */
  async function loadLayout(){
    if (layoutLoaded) return;
    layoutLoaded = true;

    var saved = null;

    try {
      var r = await api('/editor-layout?screen=' + encodeURIComponent(SCREEN));
      saved = r && r.layout;
    } catch (e) {
      saved = null;
    }

    layout = reconcile(saved);
    layoutSent = JSON.stringify(layout);
  }

  function saveLayout(){
    var body = JSON.stringify(layout);

    // A reorder that ended where it started writes nothing.
    if (body === layoutSent) return;
    layoutSent = body;

    // Debounced: holding the down button, or a run of taps, is one request.
    clearTimeout(saveLayout._t);
    saveLayout._t = setTimeout(function(){
      api('/editor-layout', { json: { screen: SCREEN, layout: layout } })
        .catch(function(){
          // Let the next change try again rather than believing it is stored.
          layoutSent = null;
          say('Could not save the panel arrangement.');
        });
    }, 350);
  }

  async function resetLayout(){
    layout = defaultLayout();
    layoutSent = JSON.stringify(layout);

    // collect() first, for the same reason the gallery's drop does: render()
    // replaces every contenteditable pane, and anything typed into one since
    // the last keystroke handler would otherwise be thrown away.
    collect();
    render();

    try {
      await api('/editor-layout-reset', { json: { screen: SCREEN } });
      say('Panel layout reset.');
    } catch (e) {
      layoutSent = null;
      say('Could not reset the panel arrangement.');
    }
  }

  /* Apply a move, repaint, persist. One place, so no caller can reorder
     without saving or save without repainting. */
  function applyMove(changed){
    if (!changed) return;
    collect();
    render();
    saveLayout();
  }

  /* -------------------------------------------------------- arrange bind */

  function bindArrange(){
    on('#peo-arrange', 'click', function(){
      arranging = !arranging;
      collect();
      render();
    });

    on('#peo-arrreset', 'click', resetLayout);

    if (!arranging) return;

    /* The move buttons. Native <button>s, so Tab reaches them and Enter or
       Space activates them with no key handling of this file's own, and a tap
       is a click. That is the whole touch and keyboard path: HTML5 drag events
       never fire on a touch screen, and the owner reviews this console on a
       phone, so the buttons are the primary way to do this and the drag is the
       convenience. The gallery in this same screen already sets that
       precedent with its own ↑ ↓ beside a drag handle. */
    document.querySelectorAll('#content [data-peo-mv]').forEach(function(b){
      b.addEventListener('click', function(){
        var parts = String(b.dataset.peoMv).split(':');
        applyMove(movePanel(parts[0], parseInt(parts[1], 10)));
      });
    });

    document.querySelectorAll('#content [data-peo-col]').forEach(function(b){
      b.addEventListener('click', function(){
        // Guarded as well as hidden in CSS. A resize that crosses 900px with
        // the screen already open would otherwise leave a live control that
        // means nothing at the width it is being tapped at.
        if (narrow()) return;
        applyMove(moveColumn(b.dataset.peoCol));
      });
    });

    /* ---- pointer drag ----

       THE HANDLE IS THE TOOLBAR, NOT THE PANEL. Marking the whole .peo-panel
       draggable would make every input, every contenteditable pane and every
       gallery thumbnail inside it the start of a panel drag -- selecting a
       word in the description would pick the panel up. The toolbar contains no
       editable anything, so it can carry draggable="true" outright with no
       mousedown/dragend dance to arm and disarm it.

       Every attribute here is data-peo-*, and every id is peo-*. The console
       is ONE document and app.blade.php binds a dozen listeners to `document`
       itself, each claiming a bare attribute name; this screen has already
       paid for that once, when its picker rows used data-open and every click
       ran the Appearance screen's skin picker. */
    var from = null;

    document.querySelectorAll('#content [data-peo-drag]').forEach(function(bar){
      bar.addEventListener('dragstart', function(e){
        from = bar.dataset.peoDrag;
        var panel = bar.closest('[data-peo-panel]');
        if (panel) panel.classList.add('peo-pdrag');
        try {
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', String(from));
        } catch (x) {}
      });

      bar.addEventListener('dragend', function(){
        from = null;
        document.querySelectorAll('#content .peo-pdrag').forEach(function(el){ el.classList.remove('peo-pdrag'); });
        document.querySelectorAll('#content .peo-pover').forEach(function(el){ el.classList.remove('peo-pover'); });
      });
    });

    document.querySelectorAll('#content [data-peo-panel]').forEach(function(panel){
      panel.addEventListener('dragover', function(e){
        if (from === null || panel.dataset.peoPanel === from) return;
        e.preventDefault();
        panel.classList.add('peo-pover');
        try { e.dataTransfer.dropEffect = 'move'; } catch (x) {}
      });

      panel.addEventListener('dragleave', function(){ panel.classList.remove('peo-pover'); });

      panel.addEventListener('drop', function(e){
        e.preventDefault();
        // Panels nest inside a column which is itself inside the grid; without
        // this the column's own drop handler would run too and move the panel
        // a second time.
        e.stopPropagation();

        var src = from || (function(){ try { return e.dataTransfer.getData('text/plain'); } catch (x) { return null; } })();
        panel.classList.remove('peo-pover');
        from = null;

        applyMove(dropOn(src, panel.dataset.peoPanel));
      });
    });

    document.querySelectorAll('#content [data-peo-slot]').forEach(function(slot){
      slot.addEventListener('dragover', function(e){
        if (from === null) return;
        e.preventDefault();
        slot.classList.add('peo-pover');
        try { e.dataTransfer.dropEffect = 'move'; } catch (x) {}
      });

      slot.addEventListener('dragleave', function(){ slot.classList.remove('peo-pover'); });

      slot.addEventListener('drop', function(e){
        e.preventDefault();
        e.stopPropagation();

        var src = from || (function(){ try { return e.dataTransfer.getData('text/plain'); } catch (x) { return null; } })();
        slot.classList.remove('peo-pover');
        from = null;

        applyMove(dropInColumn(src, slot.dataset.peoSlot));
      });
    });
  }

  /* What ↑ and ↓ do, and which of them are disabled, depends on whether the
     grid is one column or two. Rotating a phone or dragging a window across
     900px with the screen open would otherwise leave the previous width's
     answer rendered -- a down arrow greyed out at the foot of a column that is
     no longer the foot of anything. Re-rendering costs one repaint on a
     breakpoint crossing, and only while arranging. */
  try {
    var mq = window.matchMedia('(max-width:900px)');
    var onWidth = function(){
      if (!model || !arranging) return;
      collect();
      render();
    };
    if (mq.addEventListener) mq.addEventListener('change', onWidth);
    else if (mq.addListener) mq.addListener(onWidth);
  } catch (e) {}

  function bindDrag(){
    var grid = document.querySelector('#content #peo-gal');
    if (!grid) return;

    var from = null;

    grid.querySelectorAll('.peo-tile').forEach(function(tile){
      tile.addEventListener('dragstart', function(e){
        from = parseInt(tile.dataset.i, 10);
        tile.classList.add('peo-drag');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(from)); } catch (x) {}
      });

      tile.addEventListener('dragend', function(){
        tile.classList.remove('peo-drag');
        grid.querySelectorAll('.peo-tile').forEach(function(t){ t.classList.remove('peo-over'); });
      });

      tile.addEventListener('dragover', function(e){
        e.preventDefault();
        tile.classList.add('peo-over');
        try { e.dataTransfer.dropEffect = 'move'; } catch (x) {}
      });

      tile.addEventListener('dragleave', function(){ tile.classList.remove('peo-over'); });

      tile.addEventListener('drop', function(e){
        e.preventDefault();
        e.stopPropagation();

        var to = parseInt(tile.dataset.i, 10);
        if (from === null || isNaN(to) || from === to) return;

        var moved = model.images.splice(from, 1)[0];
        model.images.splice(to, 0, moved);

        from = null;
        dirty = true;
        collect();
        render();
      });
    });
  }

  /* ----------------------------------------------------------------- init */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
