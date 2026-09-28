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
@php
    /*
     * ── WHAT THIS SERVER WILL REALLY ACCEPT ─────────────────────────────────
     *
     * Three upload controls on this screen post to the one endpoint
     * /admin-api/media/upload, whose app cap is 5 MB -- and on the live box PHP
     * stops at upload_max_filesize=2M inside post_max_size=8M, so every number
     * this screen used to imply was a number it could not honour. That is the
     * defect App\Support\ServerUploadLimits was written for; this is it applied
     * to the product editor.
     *
     * Rendered server-side, because ini_get() has the answer at render time and
     * a fetch to learn it would be a round trip per screen visit for a constant.
     * The literal below is Admin\MediaUploadController::MAX_BYTES, which is
     * private; MediaPickerUploadLimitTest reflects it and fails if the two ever
     * disagree, so this cannot quietly drift.
     */
    $peoLimitReader = app(\App\Support\ServerUploadLimits::class);
    $peoLimits = $peoLimitReader->describe(5 * 1024 * 1024);
    $peoLimits['server'] = $peoLimitReader->raw();
@endphp
{{--
    A JSON island, not a window assignment. The two values under `server` are
    ini strings read off the host rather than constants of this application, so
    rule 5 applies to them: all four HEX flags on the way out, JSON.parse in a
    try/catch on the way in, and esc() before any of it reaches innerHTML.
--}}
<script type="application/json" id="peo-limits">@json($peoLimits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
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

/* ═══════════════════════════════════════════════════════════════════════════
   THE SET PANEL. (Lane SP)

   Every colour, radius and shadow below is one of this editor's own tokens --
   --surface, --surface-2, --border, --ink/-2/-soft, --accent -- because this is
   the SAME SCREEN as the rest of the product editor and must not become a
   second design language. Nothing here names a font.

   ▲ AND NOTHING HERE IS MEASURED. Two tiles across on a phone and three above
     it, by auto-fit and min(); every track is minmax(0,1fr) because a grid
     item's default min-width is auto and one long product name otherwise
     widens its track past its share and pushes the page sideways at 390.
   ═══════════════════════════════════════════════════════════════════════════ */
/* Tags. (Lane SP) */
.peo-tags{display:flex;flex-wrap:wrap;gap:6px;min-width:0;margin-bottom:11px}
.peo-tag{display:inline-flex;align-items:center;gap:5px;max-width:100%;overflow-wrap:anywhere;
         background:var(--surface-2,#f6f7fb);border:1px solid var(--border,#e6e6e6);border-radius:99px;
         padding:3px 5px 3px 10px;font-size:12px;color:var(--ink-2,#374151)}
.peo-tag button{border:0;background:none;cursor:pointer;color:var(--ink-soft,#6b7280);font:inherit;
                font-size:11px;line-height:1;padding:2px 4px}
.peo-tag button:hover{color:#b8362d}

.peo-settiles{display:grid;gap:9px;min-width:0;margin-bottom:14px;
              grid-template-columns:repeat(auto-fit,minmax(min(100%,132px),1fr))}
.peo-settile{background:var(--surface-2,#f6f7fb);border:1px solid var(--border,#e6e6e6);
             border-radius:11px;padding:9px 11px;min-width:0;display:grid;gap:3px}
.peo-settile .k{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
                color:var(--ink-soft,#6b7280)}
.peo-settile .v{font-size:15px;font-weight:700;color:var(--ink,#111827);overflow-wrap:anywhere}
.peo-settile.is-live{border-color:var(--accent,#15a85a)}
.peo-settile.is-save .v{color:#15803d}

/* The working-out under a hand-typed set price. (Lane SP2)

   A GRID OF TWO COLUMNS THAT COLLAPSES TO ONE, sized in `ch` and `fr`, with no
   fixed heights and nothing measured in JavaScript: at 390px the label sits
   above its figure and the card never exceeds the viewport, which is what the
   `minmax(0,1fr)` and the `overflow-wrap` are for. */
.peo-setwork{display:grid;gap:6px;margin:0 0 2px;min-width:0}
.peo-setwork>div{display:grid;gap:2px 10px;min-width:0;align-items:baseline;
                 grid-template-columns:minmax(0,1fr) auto;
                 padding:6px 10px;border-radius:9px;background:var(--surface-2,#f6f7fb);
                 border:1px solid var(--border,#e6e6e6)}
.peo-setwork .k{font-size:11px;font-weight:650;color:var(--ink-soft,#6b7280);min-width:0;
                overflow-wrap:anywhere}
.peo-setwork .v{font-size:13.5px;font-weight:700;color:var(--ink,#111827);text-align:right;
                overflow-wrap:anywhere}
.peo-setwork .v i{display:block;font-style:normal;font-size:10.5px;font-weight:600;
                  color:var(--ink-soft,#6b7280)}
.peo-setwork>div.is-cut .v{color:#b8362d}
.peo-setwork>div.is-now{border-color:var(--accent,#15a85a)}
.peo-setwork>div.is-now .v{color:#15803d}
@media (max-width:460px){.peo-setwork>div{grid-template-columns:minmax(0,1fr)}
                         .peo-setwork .v{text-align:left}}

/* The share image's state line. The thumbnail is a background image on a fixed
   box, so a 3000px photograph cannot widen the card. (Lane SP2) */
.peo-ogstate{display:flex;align-items:flex-start;gap:9px;min-width:0}
.peo-ogstate>span{min-width:0}
.peo-ogth{flex:none;width:38px;height:38px;border-radius:8px;background-size:cover;
          background-position:center;background-color:var(--surface-3,#eef0f6);
          border:1px solid var(--border,#e6e6e6)}
.peo-ogstate.is-auto{color:var(--ink-2,#374151)}
.peo-ogstate.is-hand .peo-ogth{border-color:var(--accent,#15a85a)}

.peo-setlist{display:grid;gap:7px;min-width:0;max-height:340px;overflow:auto}
.peo-setm{display:flex;align-items:center;gap:9px;min-width:0;flex-wrap:wrap;
          background:var(--surface-2,#f6f7fb);border:1px solid var(--border,#e6e6e6);
          border-radius:11px;padding:8px 10px}
.peo-setm.is-find{background:var(--surface,#fff)}
.peo-setm.is-drag{opacity:.45}
/* The drop marker is a BORDER on the row the pointer is over -- never a
   measured position. Where the row is, is the browser's business. */
.peo-setm.is-over{border-color:var(--accent,#15a85a);box-shadow:0 0 0 3px rgba(21,168,90,.16)}
.peo-grip{flex:none;width:16px;text-align:center;cursor:grab;user-select:none;
          color:var(--ink-soft,#6b7280);font-size:13px;line-height:1}
.peo-setth{flex:none;width:42px;height:42px;border-radius:9px;background-size:cover;
           background-position:center;background-color:var(--surface-3,#eef0f6);
           border:1px solid var(--border,#e6e6e6)}
.peo-setmid{flex:1 1 140px;min-width:0;display:grid;gap:2px}
.peo-setmid b{font-size:12.5px;font-weight:650;color:var(--ink,#111827);line-height:1.3;
              overflow-wrap:anywhere}
.peo-setmid i{font-style:normal;font-size:11px;color:var(--ink-soft,#6b7280);line-height:1.35;
              overflow-wrap:anywhere}
.peo-setq{flex:none;width:58px;padding:6px 8px;text-align:center}
.peo-setvar{flex:none;max-width:130px}
.peo-mini{flex:none;font:inherit;font-size:11.5px;font-weight:600;padding:5px 9px;border-radius:8px;
          border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);
          color:var(--ink-2,#374151);cursor:pointer}
.peo-mini:hover:not(:disabled){background:var(--surface-2,#f6f7fb)}
.peo-mini:disabled{opacity:.4;cursor:default}
.peo-mini.is-bad{color:#b8362d}
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
.peo-rte-code{display:block;width:100%;box-sizing:border-box;border:0;outline:0;resize:vertical;
              padding:12px;min-height:160px;background:var(--surface,#fff);color:inherit;
              font:400 12.5px/1.65 var(--mono,ui-monospace,SFMono-Regular,Menlo,Consolas,monospace);
              white-space:pre;overflow:auto;min-width:0}
.peo-rte-code[hidden]{display:none}
.peo-rte-bar button.on{background:var(--surface,#fff);border-color:#1f7d52;color:#1f7d52}
.peo-rte-bar button[disabled]{opacity:.35;cursor:default}
.peo-rte-bar button[disabled]:hover{background:none;border-color:transparent}

/* ---- upload progress ---- */
.peo-ups{display:grid;gap:8px;margin-top:11px;min-width:0}
.peo-up-head{font-size:11.5px;font-weight:600;color:var(--ink-soft,#6b7280)}
.peo-up{display:grid;gap:5px;min-width:0}
.peo-up-top{display:flex;gap:10px;align-items:baseline;justify-content:space-between;min-width:0}
.peo-up-name{font-size:12px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.peo-up-pct{font-size:11.5px;font-variant-numeric:tabular-nums;color:var(--ink-soft,#6b7280);flex:none}
.peo-up-track{height:6px;border-radius:999px;background:var(--surface-2,#f2f4fb);overflow:hidden}
/* Width is set inline by paintUploads(); the transition keeps a jump from one
   progress event to the next from reading as a glitch. */
.peo-up-track > i{display:block;height:100%;width:0;border-radius:999px;
                  background:var(--accent,#15a85a);transition:width .18s linear}
.peo-up.is-done .peo-up-pct{color:var(--accent-ink,#0b6e3a)}
.peo-up.is-bad .peo-up-track > i{background:#b4443c}
.peo-up.is-bad .peo-up-pct{color:#b4443c}
/* Every byte is sent and the answer has not come back. Its own state rather than
   a bar parked at 99%: the server is writing the file, recording the library row
   and making the phone-sized copies, which on a 4000x4000 JPEG is about half a
   second of real work. Striped by a keyframe on background-position, so nothing
   is measured and no JavaScript runs per frame. */
.peo-up.is-server .peo-up-track > i{width:100% !important;
  background-image:linear-gradient(110deg,rgba(255,255,255,.45) 25%,transparent 25%,
    transparent 50%,rgba(255,255,255,.45) 50%,rgba(255,255,255,.45) 75%,transparent 75%);
  background-size:14px 14px;animation:peo-stripe .7s linear infinite}
@keyframes peo-stripe{from{background-position:0 0}to{background-position:14px 0}}
.peo-up.is-gone .peo-up-track > i{background:var(--ink-soft,#6b7280)}
.peo-up.is-gone .peo-up-pct{color:var(--ink-soft,#6b7280)}
/* Stop sits on the ROW. A batch of six photographs has six things that might
   need stopping and only one of them is the one in flight. */
.peo-up-x{font:inherit;font-size:11px;font-weight:600;padding:2px 7px;border-radius:6px;flex:none;
          border:1px solid var(--border,#e6e6e6);background:none;color:var(--ink-soft,#6b7280);cursor:pointer}
.peo-up-x:hover{border-color:#b4443c;color:#b4443c}
.peo-up-x[hidden]{display:none}
.peo-up-headrow{display:flex;gap:10px;align-items:baseline;justify-content:space-between;min-width:0}
.peo-up-stop{font:inherit;font-size:11px;font-weight:600;padding:2px 8px;border-radius:6px;flex:none;
             border:1px solid var(--border,#e6e6e6);background:none;color:inherit;cursor:pointer}
.peo-up-stop[hidden]{display:none}
@media (prefers-reduced-motion: reduce){.peo-up-track > i{transition:none}
  .peo-up.is-server .peo-up-track > i{animation:none}}

/* ---- category search ---- */
.peo-catq{margin:0 0 9px}
.peo-catlist{max-height:240px;overflow:auto;min-width:0;
             border:1px solid var(--border,#e6e6e6);border-radius:9px;padding:7px 9px}
.peo-catlist .peo-check[hidden]{display:none}

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
          font-size:12.5px;color:var(--ink-soft,#6b7280);cursor:pointer;min-width:0;background:none;
          display:block;width:100%;font-family:inherit}
.peo-drop:hover,.peo-drop:focus-visible,.peo-drop.peo-over{border-color:#1f7d52;color:#1f7d52;background:rgba(31,125,82,.04)}
.peo-drop b{display:block;font-size:13px;color:inherit;margin-bottom:3px}
/* The ceiling, read from this server. Its own line under the zone's headline so
   the number is beside the target rather than in a paragraph above it. */
.peo-drop .peo-cap{display:block;margin-top:4px;font-size:11.5px;overflow-wrap:anywhere}

/* ---- file drop zones ----
   THE WHOLE CARD IS THE TARGET, not the dashed box inside it. A photograph let
   go two pixels outside a 140px box did nothing before -- and worse than
   nothing, because the console prevents no default drop anywhere else, so the
   browser NAVIGATED AWAY to the file and took the half-filled form with it.
   is-drag is set on the card while the pointer carries files over any part of
   it, and the dashed box inside is what lights up, so the highlight names the
   thing that will happen rather than the pixel under the cursor. */
.peo-media-main.is-drag,.peo-media-gal.is-drag,.peo-ogzone.is-drag{outline:2px solid #1f7d52;outline-offset:2px}
.peo-media-main.is-drag .peo-main-img,.peo-ogzone.is-drag .peo-drop,
.peo-media-gal.is-drag .peo-drop{border-color:#1f7d52;color:#1f7d52;background:rgba(31,125,82,.06)}
/* The main image box IS the drop target for the main image: drop a photograph on
   the picture to replace it. Nothing about the box itself changes -- its border
   and radius are the ones set above, untouched -- only the highlight is new. */
.peo-dhint{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.45;margin-top:8px;overflow-wrap:anywhere}

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

/* The vertical rail is OFF by default — the owner asked for it out — and can be
   switched back on per browser from the editor's toolbar. It is gated on an
   attribute on the editor's own wrapper rather than a body class, so nothing
   outside this screen can be affected by it either way.

   Off is the base state and on is what the attribute adds, so a browser that
   cannot read the stored preference gets the layout the owner asked for rather
   than the one they rejected. */
.peo-wrap[data-peo-rails="1"] .peo-card::before{
  content:'';position:absolute;left:0;top:0;bottom:0;width:3px;
  background:var(--peo-hue,#6366f1);opacity:.85}
.peo-wrap[data-peo-rails="1"] .peo-card{padding-left:18px}

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
  /* The Categories panel's own search. Not persisted and not part of the
     model: it is a way of looking at the list, not a property of the product,
     and an operator who reopens a product should see all of its categories. */
  var catQuery = '';

  /* A per-browser display preference, kept in localStorage rather than in the
     database. It changes nothing about the product, nothing another operator
     would want imposed on them, and nothing worth a round trip on every page
     load — the same reasoning the arrange mode uses for not persisting itself.

     Wrapped, because localStorage throws rather than returning null in a
     private window and in a browser with site data blocked. A throw here would
     take the whole screen down for a stripe. */
  var rails = (function(){
    try { return localStorage.getItem('kbb.peo.rails') === '1'; } catch (e) { return false; }
  })();

  function setRails(on){
    rails = !!on;
    try { localStorage.setItem('kbb.peo.rails', rails ? '1' : '0'); } catch (e) {}
    var wrap = document.querySelector('#content .peo-wrap');
    if (wrap) wrap.setAttribute('data-peo-rails', rails ? '1' : '0');
    var b = document.querySelector('#content #peo-rails');
    if (b) {
      b.setAttribute('aria-pressed', rails ? 'true' : 'false');
      b.textContent = rails ? 'Stripes on' : 'Stripes off';
      b.style.borderColor = rails ? '#1f7d52' : '';
      b.style.color = rails ? '#1f7d52' : '';
    }
  }
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

  /* -------------------------------------------------------- sidebar entry */
  /*
   * One shared helper, in app.blade.php, rather than a fifth hand-rolled copy
   * of "find an anchor, build a button, insert it, and give up quietly if the
   * anchor moved".
   *
   * The old code here was `anchor = querySelector('[data-go="catalog"]') ||
   * querySelector('[data-go="orders"]'); if (!anchor) return;`. The `return`
   * meant renaming the Catalog row took the product editor out of the sidebar
   * with no error anywhere, and the `orders` fallback put the editor in the
   * Store group, which is not what it is. kbbAddNavEntry keeps the row inside
   * the group its own breadcrumb names, falls back to the end of that group,
   * and if even the group is gone it still adds the row and says so out loud.
   */
  function addNavEntry(){
    window.kbbAddNavEntry({
      screen: SCREEN,
      label:  'Product editor',
      icon:   '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
      group:  'Catalog',
      after:  'catalog'
    });
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
  window.peoNew = function(preset){
    /* `preset` is optional and is additive: every existing caller (the Catalog
       header's Add product button) passes nothing and gets exactly what it
       always got. Catalog → Sets passes { type: 'set' } so "New set" opens this
       editor already switched to a set, rather than making the operator find
       the type control first. (Lane SP) */
    intent = { kind: 'new', preset: preset || null };
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

      if (want.preset && isKnownType(want.preset.type)) model.type = want.preset.type;

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
      adopt(r.product);
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

  /* Take a product from the endpoint and make it the model.
     ONE door, because there are three ways in — the picker, a deep link and
     the response to a save — and an Arabic box that was filled on two of them
     is the bug this function exists to make impossible. */
  function adopt(product){
    model = product;
    model.translations = model.translations || {};
    model.ar = {};
    /* The re-anchor instruction is spent. See collect()'s note: it must not
       survive into the next save of this product. (Lane SP2) */
    setReanchor = false;
    /* And this is the state the anchor the server just sent was taken against.
       setAnchorDirty() compares against it to decide whether the figures on the
       panel are live or about to be re-taken. */
    setSaved = setSnapshot();

    var cells = model.translations[ARABIC] || {};

    for (var field in cells) {
      if (Object.prototype.hasOwnProperty.call(cells, field)) {
        model.ar[field] = (cells[field] && cells[field].value) || '';
      }
    }
  }

  /* Which fields have an Arabic box is decided by the SERVER, not by this
     screen: the prefill carries exactly Product::$translatable. Add a column
     to that allowlist and its box appears here with no change to this file;
     that is the point of asking rather than listing. */
  var ARABIC = 'ar';

  function hasArabic(field){
    /* window.KBBArabic is the shared helper, included by
       resources/views/admin/app.blade.php. If a build has the editor and not
       the helper — which is exactly what happens between this lane landing and
       the integrator applying the include — every Arabic box is simply absent
       and the rest of the editor works unchanged. A screen that threw here
       would take the whole product editor down over a missing script. */
    if (!window.KBBArabic) return false;

    return !!(model && model.translations && model.translations[ARABIC]
              && Object.prototype.hasOwnProperty.call(model.translations[ARABIC], field));
  }

  function arabicPrefill(){
    return (model && model.translations) || null;
  }

  /* The Set panel's own two pieces of screen state: what the member search
     last found, and what was typed into it. NOT on `model`, because neither is
     part of the product and neither is saved. (Lane SP) */
  var setFound = [];
  var setQuery = '';

  /* Pressed, not stored: "start again from today's total" is an instruction for
     ONE save. NOT on `model`, because it is not part of the product. (Lane SP2) */
  var setReanchor = false;

  function blank(){
    return {
      id: null, name: '', slug: '', sku: null, gtin: null, brand_id: null,
      /* A new product is a SIMPLE product, which is what this editor has always
         created and what the server's own `$product->type ??= 'simple'` default
         says. Choosing Set is a deliberate act. (Lane SP) */
      type: 'simple', tags: [], set_members: [], price_mode: 'fixed',
      discount_percent: '', discount_amount: '',
      set_parts_total_aed: '', set_effective_aed: '',
      /* The four figures a hand-typed set price shows its working with, and the
         count of members that no longer exist. Declared here so a blank form
         reads '' rather than 'undefined'; they arrive real from the endpoint.
         (Lane SP2) */
      set_basis_aed: '', set_adjustment_aed: '', set_sale_now_aed: '',
      set_members_missing: 0,
      status: 'draft', is_visible: true, featured: false, published_at: null,
      category_ids: [], primary_category_id: null,
      price_aed: '', sale_aed: '', sale_starts_at: null, sale_ends_at: null,
      manage_stock: false, stock: null, stock_status: 'instock',
      short_description: '', description: '', ingredients: '', how_to_use: '',
      image: null, images: [], image_alts: {}, seo: null,
      /* T4b. `translations` is the endpoint's prefill — locale => field =>
         {value, status, source, stale} — and is what the boxes are DRAWN from.
         `ar` is the flat map the boxes are EDITED into, and it is what goes
         back up as translations[ar][…]. Two shapes rather than one because the
         prefill carries a draft's status and staleness, which a box being
         typed into does not have and must not lose by being overwritten. */
      translations: (boot && boot.translations) || {}, ar: {},
      /* A product being created has no page yet, so there is nothing truthful
         to preview and these stay empty rather than guessing. They arrive real
         from the endpoint on the first save, and on every load of an existing
         product. Declared here so the snippet reads '' rather than 'undefined'
         on a blank form. */
      seo_fallback_title: '', seo_fallback_description: '',
      seo_title: '', seo_description: '',
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

  /* {ar: {field: text}} for the save, or {} when this build drew no boxes.
     An ABSENT bag leaves stored translations alone; a bag with an empty string
     in it deletes that row. The two must stay distinguishable, so a screen with
     no boxes sends nothing rather than an empty locale. */
  function arabicPayload(){
    if (!model || !hasAnyArabic()) return {};

    var out = {};
    out[ARABIC] = {};

    for (var field in model.translations[ARABIC]) {
      if (Object.prototype.hasOwnProperty.call(model.translations[ARABIC], field)) {
        out[ARABIC][field] = model.ar[field] == null ? '' : model.ar[field];
      }
    }

    return out;
  }

  function hasAnyArabic(){
    if (!window.KBBArabic) return false;

    return !!(model && model.translations && model.translations[ARABIC]
              && Object.keys(model.translations[ARABIC]).length);
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
      seo: model.seo || {},

      /* ── THE TYPE, AND THE SET (Lane SP) ─────────────────────────────────

         `type` is sent only when it is one this editor offers. A product whose
         type came across from WooCommerce as 'grouped' has a DISABLED select
         with no data-bind, so model.type is still that word and omitting it is
         what leaves the column alone -- the server's own array_key_exists()
         guard is the other half of the same promise.

         The box and the rule go up on every save of a SET and on no other,
         because a payload that carried an empty member list for a simple
         product would be a request to empty a box the operator may be one
         dropdown away from coming back to. */
      type: isKnownType(model.type) ? model.type : undefined,
      tags: (model.tags || []).slice(),

      /* T4b — THE ARABIC, IN THE SAME REQUEST AS THE ENGLISH.
         Not a second call afterwards: a second call can fail on its own, and a
         product that saved while its Arabic did not is exactly the half-state
         the owner would never be told about. Blank fields are SENT rather than
         omitted, because blank means "not translated yet" and has to reach the
         server to delete the row. */
      translations: arabicPayload()
    };

    if ((model.type || 'simple') === 'set') {
      body.set_members = (model.set_members || []).map(function(m){
        return {
          product_id: m.product_id,
          variant_id: m.variant_id || null,
          quantity: Math.max(1, Math.min(99, Number(m.quantity || 1)))
        };
      });

      body.price_mode = model.price_mode || 'fixed';
      body.discount_percent = model.price_mode === 'discount_percent' ? String(model.discount_percent || '0') : null;
      body.discount_amount = model.price_mode === 'discount_amount' ? String(model.discount_amount || '0') : null;

      /* ── "START AGAIN FROM TODAY'S TOTAL" (Lane SP2) ───────────────────
         An instruction that lives for exactly one save. It is set by the
         button, sent here, and cleared by adopt() when the answer comes back,
         so it cannot leak into the next save of the same product -- which
         would re-anchor a set the operator only opened to fix a typo. */
      if (setReanchor) body.reanchor = true;
    }

    var creating = !model.id;
    if (creating) body.slug = model.slug;

    busy = true; banner = null; render();

    try {
      var r = await api(
        creating ? '/product-editor-create' : '/product-editor-save/' + model.id,
        {json: body}
      );
      adopt(r.product);
      dirty = false;
      banner = null;
      say(creating ? 'Product created.' : 'Saved.');
    } catch (e) {
      banner = message(e, 'Could not save. Check your connection and try again.');
    }

    busy = false; render();
  }

  /* ------------------------------------------------------------- uploads */

  /* ── THE REAL CEILING ─────────────────────────────────────────────────────
     Read out of the JSON island this partial renders above -- which is
     App\Support\ServerUploadLimits->describe(the endpoint's 5 MB cap) plus the
     two ini strings. Every reader is wrapped and every one falls back to the
     behaviour this screen had before the island existed ("send it and let the
     server decide"), because a malformed island must leave a working editor.

     Four readers and they are not interchangeable:
       capBytes()  the exact byte ceiling, for the pre-flight. effective_mb is
                   FLOORED, so refusing against effective_mb * 1048576 would
                   refuse a file this server would have taken.
       capWords()  the ceiling as an operator says it -- "512 KB", never "0 MB".
       cappedBy()  which of the three is capping, bounded to the two ini names
                   the island may carry and '' for anything else, because the
                   sentence below switches on it. Rule 5.
       serverIni() that ini value as the server spells it, for somebody who is
                   about to go and edit the line. */
  var LIMITS = (function(){
    try {
      var tag = document.getElementById('peo-limits');
      if (!tag) return null;
      var v = JSON.parse(tag.textContent || 'null');
      return (v && typeof v === 'object') ? v : null;
    } catch (e) { return null; }
  })();

  function capBytes(){
    var n = LIMITS && LIMITS.effective_bytes;
    return (typeof n === 'number' && n > 0) ? n : 0;      // 0 = no pre-flight
  }

  function capWords(){
    var w = LIMITS && LIMITS.effective_label;
    return (typeof w === 'string' && w) ? w : '';
  }

  function cappedBy(){
    var by = LIMITS && LIMITS.capped_by;
    return (by === 'upload_max_filesize' || by === 'post_max_size') ? by : '';
  }

  function serverIni(name){
    var srv = LIMITS && LIMITS.server;
    if (name !== 'upload_max_filesize' && name !== 'post_max_size') return '';
    return (srv && typeof srv[name] === 'string' && srv[name] !== '') ? srv[name] : 'not readable';
  }

  /* The sentence beside a drop zone. When the SERVER is the thing capping, it
     names the directive -- an operator cannot act on "2 MB" alone, and the whole
     reason this lane exists is that the console used to blame the file.

     `many` is whether the zone it goes under takes more than one file. The
     gallery does; the main image and the share image take one each, and "up to
     2 MB each" under a control that accepts a single file reads as though there
     were a second limit somewhere. Read off a screenshot, not reasoned. */
  function capSentence(many){
    var words = capWords();
    if (!words) return '';

    var each = many ? ' each' : '';
    var by = cappedBy();

    if (!by) return 'Up to ' + words + each + '.';

    return 'Up to ' + words + each + ' — this server’s own ' + by
      + ' (' + serverIni(by) + '), not a limit of the shop’s.';
  }

  /* ── ONE UPLOADER, TWO IMPLEMENTATIONS, ONE CONTRACT ──────────────────────
     window.kbbUpload is the console's shared uploader; it is preferred whenever
     it is on the page, and localUpload below is the fallback -- guarded exactly
     the way every call site here already guards window.kbbPickMedia. Both take
     the same options and fire the same four callbacks, so the queue below is
     written once and cannot behave differently depending on which answered.

     XMLHttpRequest and not fetch, in both: fetch cannot report UPLOAD progress.
     Its request body is consumed opaquely, so the best a fetch-based uploader
     can manage is a spinner -- which, for a shop owner pushing six product
     photographs over a domestic connection, is indistinguishable from a hung
     page.

     It is not a second upload PATH. Same endpoint, same folder field, same CSRF
     header, same server-side rules; a different transport. Two paths that drift
     is the trap the brands and catalog route files each went out of their way to
     avoid, because the one that drifts is always the one with the content-type,
     size and SVG rules in it. */
  function send(o){
    if (typeof window.kbbUpload === 'function') return window.kbbUpload(o);
    return localUpload(o);
  }

  /**
   * { url, file, field, extra, max, onProgress, onStage, onDone, onFail }
   * -> { cancel() }.  The reasoning behind every line of this is written out
   * once, in media-picker.blade.php; this is the same contract, tersely.
   */
  function localUpload(o){
    var fired = false;

    function stage(st){ if (typeof o.onStage === 'function') o.onStage(st); }

    function fail(f){
      if (fired) return;
      fired = true;
      stage('failed');
      if (typeof o.onFail === 'function') o.onFail(f);
    }

    // Refused before a byte is sent when a ceiling was given.
    if (typeof o.max === 'number' && o.max > 0 && o.file && o.file.size > o.max) {
      fail({ status: 0, message: 'Larger than this server will accept.', retryable: false });
      return { cancel: function(){} };
    }

    var fd = new FormData();
    fd.append(o.field || 'file', o.file);

    var extra = o.extra || {};
    Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', o.url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    // No csrf-token meta tag exists in this console; the cookie is the source.
    xhr.setRequestHeader('X-XSRF-TOKEN', cookie('XSRF-TOKEN'));

    if (xhr.upload) {
      xhr.upload.onprogress = function(e){
        // lengthComputable is false for a chunked request, and a fabricated
        // percentage is worse than reporting none.
        if (!e.lengthComputable || !(e.total > 0)) return;
        if (typeof o.onProgress === 'function') {
          o.onProgress({
            loaded: e.loaded,
            total: e.total,
            /* Capped at 99 while sending. 100% has to mean "the server has
               answered", not "the last byte left this machine" -- the answer can
               still be a 422, and a full bar beside a refusal is how a screen
               loses the operator. */
            pct: Math.min(99, Math.round((e.loaded / e.total) * 100))
          });
        }
      };
      xhr.upload.onload = function(){ stage('server'); };
    }

    xhr.onload = function(){
      if (fired) return;

      var body = null;
      try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }

      if (xhr.status >= 200 && xhr.status < 300) {
        fired = true;
        if (typeof o.onProgress === 'function') o.onProgress({ loaded: 1, total: 1, pct: 100 });
        stage('done');
        if (typeof o.onDone === 'function') o.onDone(body);
        return;
      }

      fail({
        status: xhr.status,
        message: (body && (body.message || body.error)) || '',
        // A 5xx is worth pressing again; a 413 or 422 will answer the same way
        // for ever, and inviting a retry of a file the server will never take is
        // how the same photograph goes up four times.
        retryable: xhr.status >= 500
      });
    };

    xhr.onerror = function(){
      fail({ status: 0, message: 'The upload could not reach the server.', retryable: true });
    };

    xhr.onabort = function(){
      if (fired) return;
      fired = true;
      stage('cancelled');
    };

    xhr.send(fd);

    return { cancel: function(){ try { xhr.abort(); } catch (e) {} } };
  }

  /* ── ONE DROP ZONE HELPER, THE SAME SHAPE ─────────────────────────────────
     window.kbbDropZone(el, {accept, multiple, onFiles}) when the kit is on the
     page; the wiring below when it is not. Returns a teardown function either
     way, and this screen calls it: render() replaces #content wholesale, so a
     zone bound to an element from the previous render has to go. */
  function dropZone(node, o){
    if (typeof window.kbbDropZone === 'function') return window.kbbDropZone(node, o);
    return localDropZone(node, o);
  }

  /** True when the pointer carries files from outside the page. */
  function carriesFiles(e){
    var types = e.dataTransfer && e.dataTransfer.types;
    if (!types) return false;
    return Array.prototype.indexOf.call(types, 'Files') !== -1;
  }

  function localDropZone(node, o){
    /* dragenter and dragleave fire once per element the pointer crosses, and a
       card here is full of them -- thumbnails, inputs, buttons. Counting is the
       only way to know the pointer has really left: a plain dragleave handler
       drops the highlight the moment the pointer moves from the card onto a
       thumbnail inside it, which reads as the target flickering. */
    var depth = 0;

    function enter(e){
      if (!carriesFiles(e)) return;
      e.preventDefault();
      depth++;
      node.classList.add('is-drag');
    }

    function over(e){
      if (!carriesFiles(e)) return;
      /* preventDefault is what makes this a valid drop target at all, and it is
         also what stops the browser navigating away to the dropped file -- which
         is what happens anywhere in this console outside a zone. */
      e.preventDefault();
      try { e.dataTransfer.dropEffect = 'copy'; } catch (x) {}
    }

    function leave(e){
      if (!carriesFiles(e)) return;
      depth = Math.max(0, depth - 1);
      if (depth === 0) node.classList.remove('is-drag');
    }

    function drop(e){
      if (!carriesFiles(e)) return;
      e.preventDefault();
      /* This card has claimed the drop. Panels and gallery tiles are drop
         targets too, for ARRANGING, and stopping here is what keeps a dropped
         photograph from also being read as a panel move. */
      e.stopPropagation();
      depth = 0;
      node.classList.remove('is-drag');

      var files = (e.dataTransfer && e.dataTransfer.files) || null;
      if (!files || !files.length) return;

      var list = Array.prototype.slice.call(files).filter(function(f){
        return matchesAccept(f, o && o.accept);
      });

      if (!list.length) return;
      if (!(o && o.multiple)) list = list.slice(0, 1);
      if (o && typeof o.onFiles === 'function') o.onFiles(list);
    }

    node.addEventListener('dragenter', enter);
    node.addEventListener('dragover', over);
    node.addEventListener('dragleave', leave);
    node.addEventListener('drop', drop);

    return function teardown(){
      node.removeEventListener('dragenter', enter);
      node.removeEventListener('dragover', over);
      node.removeEventListener('dragleave', leave);
      node.removeEventListener('drop', drop);
      node.classList.remove('is-drag');
    };
  }

  /**
   * Does a dropped file match an `accept` string?
   *
   * Only the two forms this console uses are understood -- a type/* wildcard and
   * a .ext suffix -- and ANYTHING NOT UNDERSTOOD IS ACCEPTED. A client filter
   * that guesses wrong discards the operator's file and shows nothing; the
   * server is the authority on what an image is and it reads the bytes, not the
   * name. This exists to stop a dropped folder and an obviously wrong file.
   */
  function matchesAccept(file, accept){
    if (!accept) return true;

    var name = String((file && file.name) || '').toLowerCase();
    var type = String((file && file.type) || '').toLowerCase();

    return String(accept).split(',').some(function(rule){
      rule = rule.trim().toLowerCase();
      if (!rule) return false;
      if (rule.charAt(0) === '.') return name.slice(-rule.length) === rule;
      if (rule.slice(-2) === '/*') return type.indexOf(rule.slice(0, -1)) === 0;
      if (rule.indexOf('/') !== -1) return type === rule;
      return true;
    });
  }

  /* ── THE UPLOAD QUEUE ─────────────────────────────────────────────────────

     One row per file: {name, size, pct, state, error, where, handle}.
     state is 'waiting' | 'sending' | 'server' | 'done' | 'failed' | 'cancelled'.

     `where` is which of the three controls started it -- 'main', 'gallery' or
     'og' -- and it exists because THE BAR HAS TO BE IN THE CARD THE OPERATOR
     JUST CLICKED. The panel used to be rendered inside galleryView() only, so
     choosing a main image showed its progress in the gallery card next door; at
     390px the two cards stack and the bar was below the fold of the thing being
     used. Sharing a share image showed nothing at all.

     WHAT WAS REJECTED for the gallery, which is the only multi-file control:

       ONE AGGREGATE BAR over the batch. Rejected. The uploads are sequential, so
       an aggregate is one live number plus N zeros advancing in steps -- and a
       single file that fails or stalls is invisible inside it, while which file
       failed is the one thing the operator needs.

       PARALLEL UPLOADS with N live bars. Rejected, and it is the stronger
       rejection: six photographs over one domestic uplink share the bandwidth,
       so six bars crawl together and none finishes until nearly all do. It would
       also scramble gallery ORDER, which is the order customers see, and it
       would put six bodies at this server's post_max_size at once.

       ONE BAR ADVANCING THROUGH THE BATCH ("3 of 6 - 47%"). Rejected: it loses
       which file failed, and the list of failures is what is left on screen at
       the end and the only actionable part of the run.

     So: sequential, a bar each, a Stop each, and one counting line above them. */
  var uploads = [];

  /* The teardown functions for the three file zones. render() replaces #content
     wholesale, so a zone bound to an element from the previous render is bound to
     a node that is no longer in the document -- harmless in itself, but the
     listener and the element it holds never go, and this screen re-renders on
     every keystroke that marks the form dirty. Torn down and rebuilt each time. */
  var zones = [];

  /* The delegated Stop listener is bound ONCE, on #content, which survives
     render() -- it is the element render() writes INTO. Binding it per render
     would add one listener per keystroke, and every one of them would fire. */
  var stopWired = false;

  function pctOf(u){
    if (u.state === 'done' || u.state === 'server') return 100;
    return u.pct || 0;
  }

  function stateLabel(u){
    if (u.state === 'failed') return u.error || 'Failed';
    if (u.state === 'cancelled') return 'Stopped';
    if (u.state === 'server') return 'Sent — saving…';
    if (u.state === 'done') return 'Done';
    if (u.state === 'waiting') return 'Waiting…';
    return pctOf(u) + '%';
  }

  function rowClass(u){
    return 'peo-up'
      + (u.state === 'failed' ? ' is-bad' : '')
      + (u.state === 'done' ? ' is-done' : '')
      + (u.state === 'cancelled' ? ' is-gone' : '')
      + (u.state === 'server' ? ' is-server' : '');
  }

  /** Can this row still be stopped? Only one that has not finished either way. */
  function stoppable(u){
    return u.state === 'waiting' || u.state === 'sending' || u.state === 'server';
  }

  function batchLine(rows){
    var done = rows.filter(function(u){ return u.state === 'done'; }).length;
    var bad = rows.filter(function(u){ return u.state === 'failed' || u.state === 'cancelled'; }).length;

    return 'Uploading ' + rows.length + (rows.length === 1 ? ' image' : ' images')
      + ' — ' + done + ' done' + (bad ? ', ' + bad + ' not added' : '');
  }

  /**
   * The progress panel for ONE control. Empty string when that control has
   * nothing in flight, so a card that is not uploading gains no blank box.
   */
  function uploadPanelHTML(where){
    var rows = uploads.filter(function(u){ return u.where === where; });
    if (!rows.length) return '';

    var live = rows.filter(stoppable).length;

    var body = rows.map(function(u){
      var i = uploads.indexOf(u);
      var pct = pctOf(u);

      return '<div class="' + rowClass(u) + '" data-up="' + i + '">'
        + '<div class="peo-up-top">'
        +   '<span class="peo-up-name">' + esc(u.name) + '</span>'
        +   '<span class="peo-up-pct">' + esc(stateLabel(u)) + '</span>'
        +   '<button type="button" class="peo-up-x" data-upx="' + i + '"'
        +     (stoppable(u) ? '' : ' hidden') + '>Stop</button>'
        + '</div>'
        /* aria-valuenow beside the width, so the bar is not a purely visual
           fact -- a screen reader gets the same number. */
        + '<div class="peo-up-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
        +      ' aria-valuenow="' + pct + '" aria-label="' + esc(u.name) + '">'
        +   '<i style="width:' + pct + '%"></i>'
        + '</div>'
        + '</div>';
    }).join('');

    return '<div class="peo-ups" data-ups="' + where + '">'
      + '<div class="peo-up-headrow"><span class="peo-up-head">' + esc(batchLine(rows)) + '</span>'
      +   '<button type="button" class="peo-up-stop" data-upstop="' + where + '"'
      +     (live > 1 ? '' : ' hidden') + '>Stop the rest</button>'
      /* Clear appears only once nothing is in flight. What is left on screen at
         that point is exactly the list of files that did NOT make it, with the
         reason on each line -- which is the actionable half of the banner above,
         and the half a count cannot carry. It stays until it is dismissed. */
      +   '<button type="button" class="peo-up-stop" data-upclear="' + where + '"'
      +     (live ? ' hidden' : '') + '>Clear</button>'
      + '</div>' + body + '</div>';
  }

  /* Patched in place rather than through render(). A full render on every
     progress event would rebuild every contenteditable pane in the screen dozens
     of times a second and throw away whatever the operator was typing in one of
     them. This touches the bar's width, its labels, its class and its Stop. */
  function paintUploads(){
    var host = document.querySelector('#content');
    if (!host) return;

    uploads.forEach(function(u, i){
      var row = host.querySelector('[data-up="' + i + '"]');
      if (!row) return;

      var pct = pctOf(u);
      var bar = row.querySelector('.peo-up-track > i');
      var track = row.querySelector('.peo-up-track');
      var lab = row.querySelector('.peo-up-pct');
      var x = row.querySelector('.peo-up-x');

      if (bar) bar.style.width = pct + '%';
      if (track) track.setAttribute('aria-valuenow', String(pct));
      if (lab) lab.textContent = stateLabel(u);
      if (x) x.hidden = !stoppable(u);
      row.className = rowClass(u);
    });

    host.querySelectorAll('[data-ups]').forEach(function(box){
      var where = box.getAttribute('data-ups');
      var rows = uploads.filter(function(u){ return u.where === where; });

      var head = box.querySelector('.peo-up-head');
      if (head) head.textContent = batchLine(rows);

      var live = rows.filter(stoppable).length;

      var stop = box.querySelector('[data-upstop]');
      if (stop) stop.hidden = live < 2;

      var clear = box.querySelector('[data-upclear]');
      if (clear) clear.hidden = live > 0;
    });
  }

  /* ── STOP HAS TO SETTLE THE QUEUE, NOT JUST ABORT THE REQUEST ─────────────
     FOUND IN CHROMIUM, NOT IN A TEST, AND IT HUNG THE SCREEN. Cancelling is not
     a failure, so the contract reports it as onStage('cancelled') and NOT as
     onFail -- and the first version of this queue resolved its promise only from
     onDone and onFail. So pressing Stop aborted the request, painted the row
     "Stopped", and then awaited a promise that nothing would ever settle: the
     loop never reached the next file, `busy` stayed true, and the screen sat
     greyed out until it was reloaded. Measured: three rows all reading "Stopped"
     and no render after them.
     Every row therefore carries its own settle(), called from BOTH ends -- the
     'cancelled' stage, and stopOne() itself. Resolving a promise twice is a
     no-op, so the belt and the braces cannot disagree, and a transport that
     forgets to report the abort at all cannot wedge the queue. */

  /** Stop one row: the request if it is in flight, its place in the queue if not. */
  function stopOne(i){
    var u = uploads[i];
    if (!u || !stoppable(u)) return;

    u.state = 'cancelled';

    if (u.handle && typeof u.handle.cancel === 'function') {
      try { u.handle.cancel(); } catch (e) {}
    }

    paintUploads();

    // The braces. See the note above.
    if (typeof u.settle === 'function') u.settle();
  }

  /** Stop everything still outstanding for one control. */
  function stopRest(where){
    uploads.forEach(function(u, i){
      if ((where == null || u.where === where) && stoppable(u)) stopOne(i);
    });
  }

  /**
   * What goes on a failed row, in the operator's own units.
   *
   * 413 IS ITS OWN CASE AND HAS TO BE. Laravel 11's global ValidatePostSize
   * throws before the router when the whole body is over post_max_size, and its
   * response carries `message` and no `error` key. MEASURED in Chromium against
   * a 9 MB body on this box, that message is exactly "The POST data is too
   * large." -- no size, no ceiling, no directive, and the same sentence whether
   * the file was 9 MB or 900. It is REPLACED rather than printed.
   */
  function failWords(f, row){
    var status = (f && f.status) || 0;
    var msg = String((f && f.message) || '');

    if (status === 413) {
      var by = cappedBy() || 'post_max_size';
      return 'Too big for this server (' + by + ' = ' + serverIni(by) + '). '
        + kb(row.size) + (capWords() ? ' — the most it takes is ' + capWords() : '');
    }

    if (msg) return msg.slice(0, 80);
    return status ? ('Upload failed (' + status + ')') : 'Upload failed';
  }

  /** A byte count as an operator would say it. */
  function kb(n){
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return Math.round(n / 1024) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  /** One file, sent. Resolves with its url, or null. */
  function sendOne(row, file){
    return new Promise(function(resolve){
      row.state = 'sending';
      row.pct = 0;
      /* The one place this row's promise can be settled from. Assigned before the
         request starts, because Stop can arrive on the very next tick. */
      row.settle = function(){ resolve(null); };
      paintUploads();

      row.handle = send({
        url: apiBase() + '/media/upload',
        file: file,
        field: 'file',
        extra: { folder: 'products' },
        /* Passed as well as pre-flighted in takeFiles(), and the two cannot both
           fire: takeFiles refuses first. This is the belt on the kit's braces. */
        max: capBytes() || undefined,
        onProgress: function(p){
          if (row.state !== 'sending') return;      // a cancelled row stops moving
          row.pct = Math.max(0, Math.min(100, Math.round((p && p.pct) || 0)));
          paintUploads();
        },
        onStage: function(st){
          // The belt. Cancelling is reported here and never through onFail.
          if (st === 'cancelled') { row.state = 'cancelled'; paintUploads(); resolve(null); return; }
          if (row.state === 'cancelled') return;
          if (st === 'server') { row.state = 'server'; paintUploads(); }
        },
        onDone: function(body){
          if (row.state === 'cancelled') { resolve(null); return; }
          var url = body && body.url;
          if (url) { row.state = 'done'; row.pct = 100; }
          else { row.state = 'failed'; row.error = 'No image came back'; }
          paintUploads();
          resolve(url || null);
        },
        onFail: function(f){
          if (row.state === 'cancelled') { paintUploads(); resolve(null); return; }
          row.state = 'failed';
          row.error = failWords(f, row);
          paintUploads();
          resolve(null);
        }
      });
    });
  }

  /**
   * Take a list of files for one control.
   *
   * `where` is 'main', 'gallery' or 'og' -- it decides which card shows the bars
   * and what a finished url is used for. It replaced a boolean asMain, which
   * could say only two of the three things and left the share image with no way
   * to report at all.
   */
  async function takeFiles(files, where){
    var list = Array.prototype.slice.call(files || []);
    if (!list.length) return;

    // The share image is one image. Anything past the first is not silently
    // uploaded somewhere else.
    if (where === 'og') list = list.slice(0, 1);

    var rows = list.map(function(f){
      return { name: f.name || 'image', size: f.size || 0, pct: 0, state: 'waiting', where: where };
    });

    /* This control's own leftover rows are replaced; ANOTHER control's are kept.
       A refused gallery photograph and a refused share image are two separate
       pieces of news, and uploading a main image is not a reason to throw either
       of them off the screen. */
    uploads = uploads.filter(function(u){ return u.where !== where; }).concat(rows);

    busy = true; banner = null; render();

    var ceiling = capBytes();
    var asMain = where === 'main';

    /* Sequential, not parallel, and deliberately -- see the rejection notes on
       the queue above. It also keeps gallery ORDER equal to the order the files
       were chosen, which parallel uploads would scramble by completion time. */
    for (var i = 0; i < list.length; i++) {
      var row = rows[i];

      /* Already cancelled means "Stop the rest" reached it while it was still
         waiting. Read off the ROW rather than a run-wide flag, so stopping the
         gallery cannot abandon a share image going up beside it. */
      if (row.state === 'cancelled') { paintUploads(); continue; }

      /* ── THE PRE-FLIGHT ──────────────────────────────────────────────────
         Refused before a byte leaves the machine, with the size and the reason
         in one sentence. The uploader's own `max` would refuse it too, but it
         cannot name this server's ini line -- and which line to raise is the
         only actionable part of the message. A refused file does NOT abandon the
         run: the other five photographs still go up. */
      if (ceiling && row.size > ceiling) {
        row.state = 'failed';
        row.error = kb(row.size) + ' — over this server’s ' + capWords() + ' limit'
          + (cappedBy() ? ' (' + cappedBy() + ' = ' + serverIni(cappedBy()) + ')' : '');
        paintUploads();
        continue;
      }

      var u = await sendOne(row, list[i]);
      if (!u) continue;

      if (where === 'og') {
        model.seo = model.seo || {};
        model.seo.og_image = u;
      } else if (asMain) {
        setMainImage(u);                           // and the share image follows it
        asMain = false;                            // only the first becomes main
      } else if (model.images.indexOf(u) === -1 && u !== model.image) {
        model.images.push(u);
      }

      dirty = true;
    }

    /* Counted over THIS run's rows, never over `uploads` -- which now also holds
       whatever another control failed to upload earlier, and counting those would
       report a failure the operator has already been told about. */
    var bad = rows.filter(function(x){ return x.state === 'failed'; });
    var stopped = rows.filter(function(x){ return x.state === 'cancelled'; }).length;

    banner = bad.length
      ? (bad.length === rows.length
          ? (rows.length === 1
              ? 'That image could not be uploaded. ' + (bad[0].error || '')
              : 'None of those images could be uploaded. ' + (bad[0].error || ''))
          : bad.length + ' of ' + rows.length + ' images could not be uploaded; the rest were added.')
      : (stopped ? (stopped === rows.length ? 'Upload stopped.' : stopped + ' of ' + rows.length + ' were stopped.') : null);

    /* Successes disappear; what did not make it STAYS, with its reason, until it
       is cleared. The panel used to be emptied unconditionally here, so a run in
       which the third of six photographs was refused ended with a banner saying
       "2 of 6 could not be uploaded" and no way to find out which two. */
    uploads = uploads.filter(function(x){ return x.state !== 'done'; });

    busy = false; render();
  }

  /* ------------------------------------------------------------ rich text */

  /* model.name, or model.ar.description.
     The Arabic panes carry a dotted field name so that the SAME rich-text
     control, the same collect(), the same word counter and the same paste
     handler serve both languages. A second set of them for Arabic is how the
     two boxes on one panel would come to behave differently. */
  function setField(path, value){
    if (!model) return;

    var dot = path.indexOf('.');
    if (dot === -1) { model[path] = value; return; }

    var head = path.slice(0, dot), tail = path.slice(dot + 1);
    model[head] = model[head] || {};
    model[head][tail] = value;
  }

  /* Reads the editable panes back into the model.
     Called before every save and before every re-render, because innerHTML is
     the only place that text lives until then -- re-rendering without this
     would silently discard whatever the owner had just typed. */
  function collect(){
    if (!model) return;

    /* Whichever pane the operator is actually in is the one that holds their
       work. Reading .peo-rte-area unconditionally would throw away everything
       typed in the HTML view — the written view still holds the markup from
       before the switch, so the save would look successful and silently revert
       the edit. That is the worst shape of bug: no error, no clue. */
    document.querySelectorAll('#content .peo-rte-code').forEach(function(code){
      if (code.hidden) return;
      setField(code.dataset.code, code.value);
    });

    document.querySelectorAll('#content .peo-rte-area').forEach(function(el){
      var code = document.querySelector('#content .peo-rte-code[data-code="' + el.dataset.field + '"]');
      if (code && !code.hidden) return;    // the HTML view is the live one
      setField(el.dataset.field, el.innerHTML);
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
      } else if (k.indexOf(ARABIC + '.') === 0) {
        setField(k, v);
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
    ['removeFormat', 'Clear', 'Clear formatting'],
    ['sep'],
    /* Both handled outside the execCommand dispatcher below: 'image' opens the
       shared picker and inserts what comes back, 'code' swaps which of the two
       panes is showing. */
    ['image', '🖼', 'Insert an image from the Media Library'],
    ['code', '&lt;/&gt;', 'Edit the HTML directly']
  ];

  /**
   * The Arabic counterpart of a PLAIN field (an <input>).
   *
   * Drawn only when the server's prefill says the field is translatable, so
   * this screen never has to hold its own copy of Product::$translatable.
   */
  function arabicField(field, label, fromSelector, maxlength){
    if (!hasArabic(field)) return '';

    return KBBArabic.box({
      field: field,
      label: label,
      prefill: arabicPrefill(),
      value: model.ar[field] || '',
      maxlength: maxlength,
      from: fromSelector,
      /* data-bind is this screen's own binding: bindEditor() listens on every
         [data-bind] and collect() reads them all, so the Arabic box needs no
         second event wiring and cannot be forgotten by one. */
      attrs: 'data-bind="' + ARABIC + '.' + field + '"'
    });
  }

  function rteBar(){
    return RTE_BUTTONS.map(function(b){
      if (b[0] === 'sep') return '<i class="peo-sep"></i>';
      /* b[1] is markup for the code button (&lt;/&gt;) and plain text for the
         rest. It is authored in this file, never operator input, so it is the
         one place here that is written through rather than escaped. */
      return '<button type="button" data-cmd="' + esc(b[0]) + '" title="' + esc(b[2]) + '">'
           + b[1] + '</button>';
    }).join('');
  }

  /* One rich-text control. `field` is the model path it writes to, which for
     the Arabic pane is dotted ("ar.description") — see setField(). */
  function rtePane(field, html, dir, placeholder){
    var rtl = dir === 'rtl';
    var ph = placeholder || (rtl ? KBBArabic.placeholder : '');

    return '<div class="peo-rte">'
      +   '<div class="peo-rte-bar">' + rteBar() + '</div>'
      +   '<div class="peo-rte-area" contenteditable="true" data-field="' + esc(field) + '" '
      +        (rtl ? 'dir="rtl" lang="ar" ' : '')
      +        (rtl ? 'data-kbbar-rich="' + esc(field) + '" ' : '')
      +        'data-ph="' + esc(ph) + '">' + (html || '') + '</div>'
      /* The HTML view. A plain textarea, because the point of it is to show
         the markup exactly as it is — escaped on the way in so that typing
         <p> shows <p> rather than making a paragraph. It sits alongside the
         written view rather than replacing it, so switching back and forth
         loses nothing. */
      +   '<textarea class="peo-rte-code" data-code="' + esc(field) + '" spellcheck="false" '
      +        (rtl ? 'dir="rtl" ' : '') + 'hidden>'
      +     esc(html || '')
      +   '</textarea>'
      +   '<div class="peo-rte-foot">'
      +     '<span data-words="' + esc(field) + '"></span>'
      +     '<span class="peo-rte-mode">Paste from anywhere — use &lt;/&gt; to edit the HTML.</span>'
      +   '</div>'
      + '</div>';
  }

  /**
   * T4b — THE ARABIC HALF OF A RICH-TEXT FIELD.
   *
   * A rich English field gets a rich ARABIC field: the same toolbar, the same
   * HTML view, the same paste handling, the same word counter. A plain textarea
   * here would mean the Arabic product page could not carry the headings, lists
   * and links the English one does — which would make the two pages different
   * pages rather than one page in two languages, and would quietly put a
   * formatting ceiling on the language the owner has not written yet.
   *
   * `translate` is passed only for SHORT copy. The full description gets no
   * Translate button, and that is the plan's decision rather than an oversight:
   * every translation service given formatted text either breaks the formatting
   * or translates it as though it were words, and the owner would be paying per
   * character for the damage. Long descriptions are typed.
   */
  function arabicPane(field, label, translate){
    if (!hasArabic(field)) return '';

    var cell = KBBArabic.cellFor(arabicPrefill(), field);
    var path = ARABIC + '.' + field;

    var tags = (cell.status === 'draft'
        ? '<span class="kbbar-tag is-draft">machine draft — read it, then Save</span>' : '')
      + (cell.stale
        ? '<span class="kbbar-tag is-stale">the English changed since this was written</span>' : '');

    return '<div class="kbbar" data-kbbar-box="' + esc(field) + '">'
      + '<div class="kbbar-h"><span class="kbbar-flag" dir="rtl" lang="ar">' + KBBArabic.native + '</span>'
      +   '<b>Arabic</b><span>· ' + esc(label) + '</span>' + tags
      +   (translate
            ? '<button type="button" class="kbbar-btn" hidden style="margin-inline-start:auto"'
              + ' data-kbbar-translate="' + esc(field) + '"'
              + ' data-kbbar-from="#content .peo-rte-area[data-field=\'' + esc(field) + '\']"'
              + ' data-kbbar-fromtext="1">Translate</button>'
            : '')
      + '</div>'
      + rtePane(path, model.ar[field] || '', 'rtl')
      + '<p class="kbbar-note">Leave it empty and this field counts as <b>not translated yet</b>. '
      +   'If the Arabic really is the same as the English, type it in — that is how the shop '
      +   'tells “deliberately identical” from “nobody has reached it”.</p>'
      + '</div>';
  }

  function rte(field, label, hint, placeholder, html, translate){
    return '<div class="peo-card">'
      + '<h3>' + esc(label) + '</h3>'
      + (hint ? '<p class="peo-hint">' + esc(hint) + '</p>' : '')
      + rtePane(field, html, 'ltr', placeholder)
      + arabicPane(field, label, !!translate)
      + '</div>';
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

  /* What the storefront ALREADY uses when a photo has no description of its
     own — mirrored here so the operator can see it rather than face an empty
     box and guess.

     This is not a new rule and must not become one: Product::altFor() falls
     back to ProductTitle::alt(brand, name, index, total) on every product page
     today, so an empty box has never meant an empty alt attribute. Showing it
     as the placeholder makes a silent, working default visible.

     Kept deliberately in step with app/Support/ProductTitle.php — `full()`
     does not repeat the brand when the name already leads with it, and
     `alt()` only adds the view counter when there is more than one shot.
     A test pins the two against each other. */
  function suggestedAlt(index, total){
    var brands = (boot && boot.brands) || [];
    var brand = '';

    for (var i = 0; i < brands.length; i++) {
      if (brands[i].id === model.brand_id) { brand = String(brands[i].name || '').trim(); break; }
    }

    var name = String(model.name || '').trim();
    var base;

    if (!brand) base = name;
    else if (!name) base = brand;
    else base = name.toLowerCase().indexOf(brand.toLowerCase()) === 0 ? name : brand + ' ' + name;

    if (!base) return '';

    return (index <= 0 || total < 2) ? base : base + ', view ' + (index + 1) + ' of ' + total;
  }

  function galleryView(){
    var tiles = model.images.map(function(u, i){
      return '<div class="peo-tile" draggable="true" data-i="' + i + '">'
        + '<span class="peo-grip" title="Drag to reorder">⠿</span>'
        + '<img src="' + url(u) + '" alt="">'
        + '<span class="peo-body">'
        +   '<span class="peo-ord">Position ' + (i + 2) + '</span>'
        +   '<input class="peo-alt" data-alt="' + esc(u) + '" value="' + esc(altOf(u)) + '" '
        +     'placeholder="' + esc(suggestedAlt(i + 1, model.images.length + 1)
                                   || 'Describe this photo, e.g. texture on the back of a hand') + '">'
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

    return '<section class="peo-card peo-media-gal" id="peo-galzone">'
      + '<h3>Gallery</h3>'
      + '<p class="peo-hint">Drag the handle to reorder, or use ↑ ↓. This is the order customers see, '
      +   'after the main image. The description under each photo is what Google Images and screen '
      +   'readers read — write what is actually in the shot.</p>'
      + body
      + '<div style="height:10px"></div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:9px">'
      +   '<button class="peo-btn" id="peo-gallib">Choose from Media Library</button>'
      + '</div>'
      + '<button type="button" class="peo-drop" id="peo-galdrop"><b>Or upload new images</b>'
      +   'Drop them anywhere on this card, or tap to choose'
      +   '<span class="peo-cap">' + esc(capSentence(true)) + '</span></button>'
      + '<input type="file" id="peo-galfile" accept="image/*" multiple hidden>'
      /* Directly under the drop target, which is where the operator is looking
         at the moment the upload starts. */
      + uploadPanelHTML('gallery')
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

    return '<section class="peo-card peo-media-main" id="peo-mainzone">'
      + '<h3>Main image</h3>'
      + '<p class="peo-hint">The first picture customers see, on the shop grid and at the top of the product page.</p>'
      + '<div class="peo-main-img">' + box
      +   '<div class="peo-main-cap">'
      +     '<button class="peo-btn" id="peo-mainlib">Choose</button>'
      +     '<button class="peo-btn" id="peo-mainpick">' + (model.image ? 'Replace' : 'Upload') + '</button>'
      +     (model.image ? '<button class="peo-btn peo-danger" id="peo-mainrm">Remove</button>' : '')
      +   '</div>'
      + '</div>'
      /* THE DROP HINT IS A SENTENCE, NOT A SECOND DASHED BOX. The picture above
         is already the target -- adding an empty box under a photograph to say
         so would be a control that duplicates one the operator is looking at. */
      + '<div class="peo-dhint">' + (model.image ? 'Drop a photograph on the picture to replace it. ' : 'Drop a photograph on the box above to upload it. ')
      +   esc(capSentence(false)) + '</div>'
      /* The bar goes in THIS card. It used to be rendered only inside the
         gallery card next door, so choosing a main image showed its progress
         beside something else -- and at 390px the two cards stack, which put it
         below the fold of the button that had just been pressed. */
      + uploadPanelHTML('main')
      + (model.image
          ? '<div class="peo-fld" style="margin-top:11px"><label>Image description</label>'
            + '<input class="peo-in" data-alt="' + esc(model.image) + '" value="' + esc(altOf(model.image)) + '" '
            +   'placeholder="' + esc(suggestedAlt(0, 1) || 'e.g. Anua Heartleaf Toner bottle, front') + '">'
            + '<div class="peo-note">Read by Google Images and by screen readers. '
            +   'Left empty, the shop uses the greyed-out text above — write your own only when '
            +   'the photo shows something that wording does not.</div></div>'
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

    /* Filtered by the search box, EXCEPT that a ticked category is always
       shown. A product filed in six categories, with a search narrowing the
       list to one, would otherwise show one tick and hide five — and the
       operator would have no way to know what the product is actually in
       without clearing the box. A selection you cannot see is a selection you
       cannot undo. */
    var q = catQuery.trim().toLowerCase();
    var shown = cats.filter(function(c){
      if (model.category_ids.indexOf(c.id) !== -1) return true;
      return !q || String(c.name).toLowerCase().indexOf(q) !== -1;
    });

    var list = shown.map(function(c){
      var on = model.category_ids.indexOf(c.id) !== -1;
      return '<label class="peo-check">'
        + '<input type="checkbox" data-cat="' + c.id + '"' + (on ? ' checked' : '') + '>'
        + '<span>' + esc(c.name) + '</span></label>';
    }).join('');

    if (!shown.length) {
      list = '<div class="peo-note" style="padding:10px 2px">No category matches “' + esc(catQuery) + '”.</div>';
    }

    var options = model.category_ids.map(function(id){
      var c = cats.filter(function(x){ return x.id === id; })[0];
      if (!c) return '';
      return '<option value="' + c.id + '"' + (model.primary_category_id === c.id ? ' selected' : '') + '>'
           + esc(c.name) + '</option>';
    }).join('');

    return '<div class="peo-card">'
      + '<h3>Categories</h3>'
      + '<p class="peo-hint">A product can sit in as many as you like. It appears on every one of their pages.</p>'
      /* The search box appears once the list is long enough to need it. Below
         that it is a control that costs a line and saves nothing. */
      + (cats.length > 8
          ? '<input class="peo-in peo-catq" id="peo-catq" type="search" autocomplete="off" '
            + 'placeholder="Search ' + cats.length + ' categories…" value="' + esc(catQuery) + '">'
          : '')
      + '<div class="peo-catlist">' + list + '</div>'
      + '<div class="peo-note peo-catnote"' + (q && shown.length ? '' : ' hidden') + '>'
      +   (q && shown.length
            ? 'Showing ' + shown.length + ' of ' + cats.length
              + '. Ticked categories stay visible while you search.'
            : '')
      + '</div>'
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
      +     '<input class="peo-in" inputmode="numeric" data-bind="price_aed" value="' + esc(model.price_aed || '') + '" placeholder="99"></div>'
      +   '<div class="peo-fld"><label>Sale price</label>'
      +     '<input class="peo-in" inputmode="numeric" data-bind="sale_aed" value="' + esc(model.sale_aed || '') + '" placeholder="79"></div>'
      + '</div>'
      /* WHOLE DIRHAMS — Lane FA. inputmode="numeric" rather than "decimal",
         so a phone keypad does not offer a decimal point the server will
         refuse, and the note says the rule rather than leaving the operator to
         discover it from a 422. The refusal itself lives on the server
         (ProductEditorApiController::apply), because this is markup and markup
         is not a guard; this is only the half that stops him typing it. */
      + '<div class="peo-note" style="margin:-4px 0 12px">Whole ' + esc(code) + ' only — no decimals, no symbol, no commas.</div>'
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

  /* ── TAGS (Lane SP) ──────────────────────────────────────────────────────

     The owner asked for "the proper tags etc on this page". The `tags` and
     `product_tag` tables have existed since the original schema and NOTHING in
     this application has ever written to them — not the importer, not either
     editor — so this is the first control that does, and it is on the PRODUCT
     editor because a set is a product and so is everything else here.

     Typed as words and sent as words; ProductEditorApiController finds or
     creates the row. esc() on the chip, because a tag is a string somebody
     typed and this screen builds its markup by concatenation. */
  function tagsView(){
    var tags = model.tags || [];

    return '<div class="peo-card">'
      + '<h3>Tags</h3>'
      + '<p class="peo-hint">Words for this product. A set publishes them in its structured data as '
      + '<b>keywords</b>.</p>'
      + (tags.length
          ? '<div class="peo-tags">' + tags.map(function(t, i){
              return '<span class="peo-tag">' + esc(t)
                + '<button type="button" data-peo-tagrm="' + i + '" aria-label="Remove tag">&#10005;</button></span>';
            }).join('') + '</div>'
          : '')
      + '<div class="peo-fld" style="margin-bottom:0"><label>Add a tag</label>'
      + '<input class="peo-in" id="peo-tagin" maxlength="60" placeholder="Type one and press Enter"></div>'
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
      + '<div class="peo-fld peo-ogzone" id="peo-ogzone"><label>Share image</label>'
      +   '<div id="peo-ogstatehost">' + ogStateView() + '</div>'
      +   '<button class="peo-btn" type="button" id="peo-oglib" style="margin-bottom:7px">Choose from Media Library</button>'
      +   '<input class="peo-in" data-bind="seo.og_image" id="peo-og" value="' + esc(seo.og_image || '') + '" '
      +     'placeholder="Uses the main image if empty">'
      +   '<div style="height:7px"></div>'
      /* THE SHARE IMAGE WAS THE SILENT ONE. It had a button, a hidden input and
         no drop target, and its upload called the uploader with no progress
         callback at all -- so xhr.upload.onprogress was never attached and the
         screen showed a greyed-out form and nothing else while a 2 MB file went
         up. It now goes through the same queue as the other two: a bar, the
         stage, a Stop, and the pre-flight against this server's real ceiling. */
      +   '<button type="button" class="peo-drop" id="peo-ogdrop"><b>Or upload a share image</b>'
      +     'Drop one anywhere in this box, or tap to choose'
      +     '<span class="peo-cap">' + esc(capSentence(false)) + '</span></button>'
      +   '<input type="file" id="peo-ogfile" accept="image/*" hidden>'
      +   uploadPanelHTML('og') + '</div>'
      + '<label class="peo-check"><input type="checkbox" data-bind="seo.noindex"'
      +   (seo.noindex ? ' checked' : '') + '><span>Ask Google not to list this product</span></label>'
      + '</div>';
  }

  /* ══════════════════════════════════════════════════════════════════════════
     THE SHARE IMAGE, AND WHICH OF ITS TWO STATES IT IS IN. (Lane SP2)

     The owner: "the seo image must be taken auto from the main image
     automatically when i upload the main image of the product or set, and
     manually also i can change that seo image."

     So it has two states, and the screen has to say which one out loud --
     otherwise the picture that appears by itself is indistinguishable from one
     somebody chose, and the operator cannot tell whether changing the main
     image will move it.

         AUTOMATIC  the box is empty, or holds the main image. It follows the
                    main image from now on.
         BY HAND    the box holds anything else. Nothing will ever overwrite it,
                    and there is a button back to automatic.

     The rule is read off the two values rather than out of a stored flag, for
     the reasons ProductEditorApiController::followMainImageIntoSeo() sets out.
     This function and that one are the same three lines, one in each language,
     so the screen cannot claim a state the server does not act on.

     ▲ A share image is what Facebook, WhatsApp and Google are handed, and this
       one is a path the operator has already put in the Main image box -- so it
       goes through the same App\Support\Seo::absolute() and the same
       imageUrlRule() the main image itself does, and needs no extra size rule
       of its own: it IS the main image. */
  function ogStateView(){
    var og = String((model.seo && model.seo.og_image) || '').trim();
    var main = String(model.image || '');
    var auto = og === '' || og === main;

    if (auto && !main) {
      return '<div class="peo-note peo-ogstate" style="margin:0 0 7px">'
        + '<b>Automatic.</b> There is no main image yet — add one above and it is used here too.</div>';
    }

    if (auto) {
      return '<div class="peo-note peo-ogstate is-auto" style="margin:0 0 7px">'
        + '<span class="peo-ogth" style="background-image:url(\'' + esc(url(main)) + '\')"></span>'
        + '<span><b>Automatic — taken from the main image.</b> Change the main image and this changes '
        + 'with it. Choose or upload one below to pick a different picture.</span></div>';
    }

    return '<div class="peo-note peo-ogstate is-hand" style="margin:0 0 7px">'
      + '<span class="peo-ogth" style="background-image:url(\'' + esc(url(og)) + '\')"></span>'
      + '<span><b>Chosen by hand.</b> Changing the main image will <b>not</b> replace it.'
      + (main ? ' <button type="button" class="peo-mini" id="peo-ogauto">Use the main image</button>' : '')
      + '</span></div>';
  }

  /* The button inside that line, bound wherever the line has just been drawn. */
  function bindOgAuto(scope){
    var el = scope && scope.querySelector('#peo-ogauto');

    if (!el) return;

    el.addEventListener('click', function(){
      collect();
      model.seo = model.seo || {};
      model.seo.og_image = model.image || '';
      dirty = true;
      render();
    });
  }

  /* THE ONE PLACE model.image IS WRITTEN. (Lane SP2)

     Three controls set the main image -- the Media Library, an upload, and
     Remove -- and the share image has to follow all three or it follows none of
     them. It used to be an assignment in each, which is exactly how a rule ends
     up applied on two paths out of three.

     `was` is the main image being replaced, and it is the whole of the test: a
     share image holding it is one this screen filled in and re-points, a share
     image holding anything else is the operator's and is left alone. Identical
     to the server's followMainImageIntoSeo(), which runs again on save and is
     the half that counts -- this one only means the operator SEES it happen. */
  function setMainImage(u){
    var was = String(model.image || '');
    var now = String(u || '');

    model.seo = model.seo || {};

    var og = String(model.seo.og_image || '').trim();

    model.image = now === '' ? null : now;

    if (og !== '' && og !== was && og !== now) return;   // chosen by hand

    if (now === '') {
      delete model.seo.og_image;
    } else {
      model.seo.og_image = now;
    }
  }

  /* Which of the three this product is, or the honest truth when it is
     something the importer wrote. (Lane SP) */
  var PEO_TYPES = [
    ['simple', 'Simple product'],
    ['variable', 'Variable product — has options'],
    ['set', 'Set — made of other products']
  ];

  function isKnownType(t){
    for (var i = 0; i < PEO_TYPES.length; i++) { if (PEO_TYPES[i][0] === t) return true; }
    return false;
  }

  function typeField(){
    var t = model.type || 'simple';

    if (!isKnownType(t)) {
      return '<div class="peo-fld"><label>Product type</label>'
        + '<select class="peo-sel" disabled><option>' + esc(t) + '</option></select>'
        + '<div class="peo-note">This type came across from WooCommerce and this editor does not '
        + 'change it. Everything else on this page still saves normally.</div></div>';
    }

    return '<div class="peo-fld"><label>Product type</label>'
      + '<select class="peo-sel" data-bind="type" id="peo-type">'
      +   PEO_TYPES.map(function(o){
            return '<option value="' + o[0] + '"' + (t === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
          }).join('')
      + '</select>'
      + '<div class="peo-note">' + (t === 'set'
          ? 'A set is sold as one product at one price, and the shop shows what is in the box.'
          : 'Choose <b>Set</b> to build a product out of other products.') + '</div></div>';
  }

  /* ════════════════════════════════════════════════════════════════════════
     WHAT IS IN THE BOX — the Set panel. (Lane SP)

     ONE panel, drawn only when the type is `set`, holding the two things a set
     has that an ordinary product does not: its members and its pricing rule.
     Everything else a set needs -- SEO, images, categories, brand, visibility,
     stock, position, the description editors -- is this editor's own machinery
     and is not copied here. That is the whole point of the merge.

     NOTHING BELOW MEASURES LAYOUT. The member rows are dragged with the HTML5
     drag events, whose targets the BROWSER supplies; there is no
     getBoundingClientRect, no offsetTop and no scroll maths. The arrows stay
     beside the drag handle because drag is a mouse gesture and this console is
     used by keyboard every day.
     ════════════════════════════════════════════════════════════════════════ */
  function setboxView(){
    var t = setTotals();
    var mode = model.price_mode || 'fixed';

    var rows = (model.set_members || []).map(function(m, i){
      /* ▲ `data-peo-setdrag` AND NOT `data-peo-drag`. The panel ARRANGE bar
         already owns `data-peo-drag` (see arrangeBar()), and this screen's drag
         handlers find their row with closest('[…]') -- so sharing the name meant
         a member row's drop could resolve to a panel and a panel's to a member.
         Caught by a test counting the attribute, not by reading. (Lane SP) */
      return '<div class="peo-setm" draggable="true" data-peo-setdrag="' + i + '">'
        + '<span class="peo-grip" aria-hidden="true">&#8942;&#8942;</span>'
        + '<span class="peo-setth"' + (m.image ? ' style="background-image:url(\'' + esc(m.image) + '\')"' : '') + '></span>'
        + '<span class="peo-setmid">'
          + '<b>' + esc(m.name) + '</b>'
          + '<i>' + (m.brand ? esc(m.brand) + ' &middot; ' : '')
            + (m.variant ? esc(m.variant) + ' &middot; ' : '')
            + esc(boot ? boot.currency.code : '') + ' ' + esc(m.unit_price_aed || '0') + ' each</i>'
        + '</span>'
        + '<input class="peo-in peo-setq" inputmode="numeric" value="' + Number(m.quantity || 1) + '" '
          + 'data-peo-mq="' + i + '" aria-label="How many of this in the box">'
        + '<button type="button" class="peo-mini" data-peo-up="' + i + '"' + (i === 0 ? ' disabled' : '') + ' aria-label="Move up">&uarr;</button>'
        + '<button type="button" class="peo-mini" data-peo-down="' + i + '"' + (i === (model.set_members || []).length - 1 ? ' disabled' : '') + ' aria-label="Move down">&darr;</button>'
        + '<button type="button" class="peo-mini is-bad" data-peo-rm="' + i + '" aria-label="Remove from the box">&#10005;</button>'
        + '</div>';
    }).join('');

    var found = (setFound || []).map(function(p){
      var options = (p.variants && p.variants.length)
        ? '<select class="peo-sel peo-setvar" data-peo-var="' + p.id + '" aria-label="Option">'
            + '<option value="">Whole product</option>'
            + p.variants.map(function(v){ return '<option value="' + v.id + '">' + esc(v.label) + '</option>'; }).join('')
          + '</select>'
        : '';

      return '<div class="peo-setm is-find">'
        + '<span class="peo-setth"' + (p.image ? ' style="background-image:url(\'' + esc(p.image) + '\')"' : '') + '></span>'
        + '<span class="peo-setmid"><b>' + esc(p.name) + '</b>'
        + '<i>' + (p.brand ? esc(p.brand) + ' &middot; ' : '') + esc(boot ? boot.currency.code : '')
        + ' ' + esc(p.price_aed || '0') + '</i></span>'
        + options
        + '<button type="button" class="peo-mini" data-peo-add="' + p.id + '">Add</button>'
        + '</div>';
    }).join('');

    var code = boot ? boot.currency.code : '';

    var rule = '';

    if (mode === 'discount_percent') {
      rule = '<div class="peo-fld"><label>Discount off the total (%)</label>'
        + '<input class="peo-in" inputmode="decimal" data-bind="discount_percent" id="peo-setpct" '
        + 'value="' + esc(model.discount_percent || '') + '" placeholder="e.g. 10"></div>';
    } else if (mode === 'discount_amount') {
      rule = '<div class="peo-fld"><label>Discount off the total (' + esc(code) + ')</label>'
        + '<input class="peo-in" inputmode="decimal" data-bind="discount_amount" id="peo-setamt" '
        + 'value="' + esc(model.discount_amount || '') + '" placeholder="e.g. 25"></div>';
    } else {
      rule = fixedFollowView();
    }

    return '<div class="peo-card">'
      + '<h3>What is in the box</h3>'
      + '<p class="peo-hint">A set is sold as one product at one price. Everything else on this page — '
      + 'the pictures, the description, the categories, search appearance — works exactly as it does '
      + 'for any other product.</p>'

      + '<div class="peo-settiles">'
        + '<div class="peo-settile"><span class="k">Bought separately</span>'
          + '<span class="v" data-peo-money="parts">' + esc(code) + ' ' + esc(setAed(t.parts)) + '</span></div>'
        + '<div class="peo-settile is-live"><span class="k">Set price</span>'
          + '<span class="v" data-peo-money="price">' + esc(code) + ' ' + esc(setAed(t.price)) + '</span></div>'
        + '<div class="peo-settile is-save"><span class="k">Saving</span>'
          + '<span class="v" data-peo-money="saving">' + (t.saving > 0 ? esc(code) + ' ' + esc(setAed(t.saving)) : '&mdash;') + '</span></div>'
      + '</div>'

      + '<div class="peo-fld"><label>How the price is decided</label>'
        + '<select class="peo-sel" data-bind="price_mode" id="peo-pricemode">'
        +   '<option value="fixed"' + (mode === 'fixed' ? ' selected' : '') + '>A price I type</option>'
        +   '<option value="discount_percent"' + (mode === 'discount_percent' ? ' selected' : '') + '>A percentage off the total</option>'
        +   '<option value="discount_amount"' + (mode === 'discount_amount' ? ' selected' : '') + '>An amount off the total</option>'
        + '</select>'
        + '<button type="button" class="peo-btn" id="peo-usetotal" style="margin-top:9px">Use this total</button>'
        + '<div class="peo-note">' + (mode === 'fixed'
            ? 'You type the price in <b>Price</b>, above. <b>Reduce a product&rsquo;s price and this set drops '
              + 'by the same amount, on its own</b> — off the price and off the sale price. Or press '
              + '<b>Use this total</b> to price the set at what its products cost.'
            : 'Worked out fresh every time the price is shown. <b>Reduce a product&rsquo;s price and this set '
              + 'drops by the same amount, on its own.</b> A basket or an order already placed keeps the price '
              + 'it was agreed at.') + '</div></div>'
      + rule

      + '<div class="peo-fld"><label>Products in this box</label>'
        + '<div class="peo-setlist">'
        + (rows || '<div class="peo-note" style="margin:0">Nothing in the box yet. Search below.</div>')
        + '</div>'
        + '<div class="peo-note">Drag a row to reorder it, or use the arrows.</div></div>'

      + '<div class="peo-fld" style="margin-bottom:0"><label>Add a product</label>'
        + '<input class="peo-in" type="search" id="peo-setq" value="' + esc(setQuery) + '" '
        + 'placeholder="Search by name or SKU">'
        + '<div class="peo-setlist" style="margin-top:9px">'
        + (found || '<div class="peo-note" style="margin:0">Type to search your catalogue.</div>')
        + '</div></div>'
      + '</div>';
  }

  /* ── THE SET'S OWN ARITHMETIC, IN INTEGER FILS ───────────────────────────

     THE SCREEN DOES NOT DECIDE WHAT A SET COSTS -- App\Support\SetPricing does,
     on the server, and hands the answer back as `set_effective_aed`. What this
     computes is the LIVE PREVIEW the owner asked for, so the figure moves as he
     types rather than after he presses Save.

     And it is the same three lines the server runs: whole fils in, integer
     multiplication, one division with the half rounded up. A preview built as
     `parts * (1 - pct/100)` is a float chain that lands a fil under what the
     server then saves, and a preview that disagrees with the saved price by one
     fil is a preview nobody trusts again. */
  function setFils(aed){
    var n = Number(aed);
    return isFinite(n) ? Math.round(n * 100) : 0;
  }

  function setAed(fils){
    return (Math.round(Number(fils) || 0) / 100).toFixed(2);
  }

  function setTotals(){
    var parts = 0, count = 0;

    (model.set_members || []).forEach(function(m){
      var q = Math.max(1, Number(m.quantity || 1));
      parts += setFils(m.unit_price_aed || 0) * q;
      count += q;
    });

    var mode = model.price_mode || 'fixed';
    var price;

    if (mode === 'discount_percent') {
      var bp = Math.max(0, Math.min(10000, Math.round(Number(model.discount_percent || 0) * 100)));
      price = Math.max(0, Math.floor((parts * (10000 - bp) + 5000) / 10000));
    } else if (mode === 'discount_amount') {
      price = Math.max(0, parts - Math.max(0, setFils(model.discount_amount || 0)));
    } else {
      /* A HAND-TYPED PRICE, LESS WHAT ITS PRODUCTS HAVE COME DOWN BY. The same
         subtraction and the same clamp App\Support\SetPricing::afterAdjustment()
         runs on the server, so the figure the owner watches while he types is
         the figure that is saved. (Lane SP2) */
      price = setFixedPrice(setFils(model.price_aed || 0), parts);
    }

    /* ▲ AN UNPRICED SET IS NOT SAVING ANYBODY ANYTHING. The owner's first set,
       half filled in, read "Bought separately AED 806.00 / Set price AED 0.00 /
       You save AED 806.00" -- the arithmetic right and the sentence false. The
       same guard is in App\Support\SetContents, so the screen and the shop say
       the same thing. */
    return { parts: parts, count: count, price: price, saving: price <= 0 ? 0 : Math.max(0, parts - price) };
  }


  /* ══════════════════════════════════════════════════════════════════════════
     A HAND-TYPED SET PRICE THAT FOLLOWS ITS PRODUCTS DOWN. (Lane SP2)

     The owner: "when i set the price of product set, either total, or
     discounted percentage or manual set the actual and sale price. For any
     case, when i change the price of any product from that set, the set price
     will also reduce that much how much i reduced in that particular product.
     from the actual price and also from the sale price if any."

     The two discount modes had this already -- they derive the whole price from
     the parts total. `fixed` is the one where the figure is the operator's own,
     so it is measured from an ANCHOR: the parts total at the moment he typed
     it, kept in `products.set_price_basis`. Everything below is that one
     subtraction, made visible.

     NOTHING HERE MEASURES LAYOUT. Four numbers and three sentences.
     ══════════════════════════════════════════════════════════════════════════ */

  /* What the anchor was taken against, in fils, or null when this set has none
     -- which is every set built before this feature, until it is next saved. */
  function setBasis(){
    return model.set_basis_aed === '' || model.set_basis_aed === null || model.set_basis_aed === undefined
      ? null
      : setFils(model.set_basis_aed);
  }

  /* The four facts the server re-anchors on, as one comparable string.

     IN FILS, NOT AS TYPED. "180" and "180.00" are the same price, and the
     server compares integers -- so comparing the strings would tell the owner
     his set was about to re-anchor when it was not. */
  function setSnapshot(){
    return JSON.stringify([
      model.price_aed === '' || model.price_aed === null ? null : setFils(model.price_aed),
      model.sale_aed === '' || model.sale_aed === null ? null : setFils(model.sale_aed),
      (model.set_members || []).map(function(m){
        return [Number(m.product_id), Number(m.variant_id || 0), Math.max(1, Number(m.quantity || 1))];
      })
    ]);
  }

  var setSaved = null;

  /* Is the anchor about to be re-taken by the save this operator is one button
     away from pressing? Typing a new price, or changing the box, re-anchors on
     the server -- so while either is true the preview must show the price he is
     TYPING and not that price less a reduction that is about to be zeroed. */
  function setAnchorDirty(){
    return setReanchor || setSaved === null || setSnapshot() !== setSaved;
  }

  /* What is coming off the typed figures today, in fils. Never negative, and
     zero in the three cases the server also declines to move a price in:
     no anchor, an empty box, and a box with a product missing from it. */
  function setAdjustment(parts){
    var basis = setBasis();

    if (basis === null || setAnchorDirty()) return 0;
    if (!(model.set_members || []).length) return 0;
    if (Number(model.set_members_missing || 0) > 0) return 0;

    return Math.max(0, basis - parts);
  }

  /* One typed figure, less today's reduction, clamped -- and never raised:
     App\Support\SetPricing::MIN_PRICE_FILS and its note. */
  function setFixedPrice(typed, parts){
    var adj = setAdjustment(parts);

    if (adj <= 0) return typed;

    return Math.max(Math.min(typed, 1), typed - adj);
  }

  /* The panel that explains a price nobody typed today.

     A HOST WITH A STABLE ID, because refreshSetMoney() rewrites the inside of
     it while a quantity box is being typed into and a full render() would take
     the focus out of that box mid-keystroke. */
  function fixedFollowView(){
    return '<div class="peo-setfollow" id="peo-setfollow">' + fixedFollowInner() + '</div>';
  }

  function fixedFollowInner(){
    var code = boot ? boot.currency.code : '';
    var parts = setTotals().parts;
    var basis = setBasis();
    var adj = setAdjustment(parts);
    var typed = setFils(model.price_aed || 0);
    var sale = model.sale_aed === '' || model.sale_aed === null ? null : setFils(model.sale_aed);

    var money = function(f){ return esc(code) + ' ' + esc(setAed(f)); };

    var rows = '';

    if (Number(model.set_members_missing || 0) > 0) {
      /* THE ONE STATE THAT IS NOT ARITHMETIC. A product deleted from the
         catalogue makes the box cheaper in exactly the way a price cut does,
         and marking the set down for it would be a discount nobody gave on a
         box that is now missing an item. The server refuses to move the price;
         this says so, because a reduction that silently stopped happening is as
         confusing as one that silently started. */
      rows = '<div class="peo-banner is-bad" style="margin:0 0 10px">'
        + esc(String(model.set_members_missing)) + ' of the products in this box no longer exist. '
        + 'The set is holding the price you typed until you fix the box.</div>';
    }

    if (basis === null || setAnchorDirty()) {
      return rows
        + '<div class="peo-note" style="margin:0">The set costs exactly what you type in <b>Price</b>. '
        + 'Saving takes today&rsquo;s total — <b>' + money(parts) + '</b> — as the starting point, and from '
        + 'then on <b>every dirham you take off one of these products comes off this set too</b>, off the '
        + 'price and off the sale price. Prices only ever come <b>down</b>: if a product gets dearer, or a '
        + 'product&rsquo;s sale ends, the set goes back to the figure you typed and never above it.</div>';
    }

    return rows
      + '<div class="peo-setwork">'
      +   '<div><span class="k">Products cost, when you set the price</span><span class="v">' + money(basis) + '</span></div>'
      +   '<div><span class="k">They cost now</span><span class="v">' + money(parts) + '</span></div>'
      +   '<div' + (adj > 0 ? ' class="is-cut"' : '') + '><span class="k">Coming off, automatically</span>'
      +     '<span class="v">' + (adj > 0 ? '&minus; ' + money(adj) : '&mdash;') + '</span></div>'
      +   '<div class="is-now"><span class="k">Price now</span><span class="v">'
      +     money(setFixedPrice(typed, parts)) + (adj > 0 ? ' <i>you typed ' + money(typed) + '</i>' : '') + '</span></div>'
      +   (sale === null ? '' : '<div class="is-now"><span class="k">Sale price now</span><span class="v">'
      +     money(setFixedPrice(sale, parts)) + (adj > 0 ? ' <i>you typed ' + money(sale) + '</i>' : '') + '</span></div>')
      + '</div>'
      + '<div class="peo-note" style="margin:8px 0 0">Prices only ever come <b>down</b>. A product getting '
      + 'dearer never raises this set above the figure you typed, and when a product&rsquo;s sale ends the '
      + 'reduction goes away by itself. A basket or an order already placed keeps the price it was agreed at.</div>'
      + '<button type="button" class="peo-btn" id="peo-setreanchor" style="margin-top:9px">'
      + 'Start again from today&rsquo;s total</button>'
      + '<div class="peo-note">Use this when the products&rsquo; new prices are the ones your typed price '
      + 'should be measured from. It changes no price today — it moves the starting point to '
      + money(parts) + '.</div>';
  }

  function basicsView(){
    var creating = !model.id;

    return '<div class="peo-card">'
      + '<h3>Basics</h3>'
      + '<div class="peo-fld"><label>Product name</label>'
      +   '<input class="peo-in" data-bind="name" id="peo-name" value="' + esc(model.name) + '" placeholder="e.g. Anua Heartleaf Toner"></div>'
      /* T4b — the Arabic name, in this form, saved by this form's Save button,
         present on a BLANK form as well as a saved one. That is the owner's
         requirement in his own words: the Arabic is entered at the moment of
         creation, not on a screen someone has to remember to visit. */
      + arabicField('name', 'Product name', '#content #peo-name', 200)
      /* ── THE TYPE CONTROL (Lane SP) ──────────────────────────────────────

         The owner: "i want if i add product, on that page it will have optin to
         switch to Set product type, and all options will be shown for set, with
         remaing same sections like seo etc. ... so in this case i will have most
         of things ready made ... instead of making such all options separately
         for set product type."

         A set IS a `products` row with type='set' plus the product_set_items
         pivot -- the shape Lane SET chose precisely so a set would carry slug,
         status, category, description, images, SEO, tags and position as an
         ordinary product. Choosing Set here reveals ONE extra panel; every
         other section on this page is this editor's own, unchanged, and a set
         uses it because a set is a product.

         ▲ AN UNRECOGNISED TYPE IS SHOWN AS ITSELF AND IS NOT SENT BACK.
           `products.type` stores an unknown value verbatim -- the WooCommerce
           import writes 'grouped' and 'external' -- and an editor that quietly
           turned one of those into 'simple' the first time somebody fixed a
           typo in its name would be rewriting the catalogue by opening it. The
           select is then disabled and carries no data-bind at all, so collect()
           never sees it and the server's array_key_exists() guard leaves the
           column alone. */
      + typeField()
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
    /* `true` is the Translate button, and it is on the SHORT description only.
       docs/BILINGUAL-PLAN.md: the machine does names, short descriptions and
       interface text; long descriptions are typed, because every service given
       formatted text either breaks the formatting or translates it as words,
       and the owner pays per character for the damage either way. */
    { key: 'short_description', label: 'Short description', col: 'main', view: function(){
        return rte('short_description', 'Short description',
          'The summary beside the price. One or two lines.',
          'A gentle daily toner that calms redness…', model.short_description, true); } },
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
    /* The Set panel. Registered like any other, so the operator can move it
       and the arrange controls, the persistence and the reconciliation all
       know about it for free -- and HIDDEN unless the product is a set, by the
       one `when` below. (Lane SP) */
    { key: 'setbox',     label: 'What is in the box', col: 'main',
      when: function(){ return (model.type || 'simple') === 'set'; },
      view: function(){ return setboxView(); } },
    { key: 'seo',        label: 'Search appearance', col: 'main', view: function(){ return seoView(); } },
    { key: 'publish',    label: 'Publishing',        col: 'side', view: function(){ return publishView(); } },
    { key: 'categories', label: 'Categories',        col: 'side', view: function(){ return categoriesView(); } },
    { key: 'brand',      label: 'Brand',             col: 'side', view: function(){ return brandView(); } },
    { key: 'tags',       label: 'Tags',              col: 'side', view: function(){ return tagsView(); } },
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

    /* A panel may declare `when`, and a panel whose condition is false is not
       drawn at all -- not hidden with CSS, not drawn empty. The Set panel is
       the only one that has one today: it is the two things a set has and an
       ordinary product does not, and on an ordinary product there is nothing
       to show.

       It stays IN the layout while it is not drawn, which is what lets the
       operator arrange it once and find it where they put it the next time
       they open a set. (Lane SP) */
    if (typeof p.when === 'function' && !p.when()) return '';

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
        /* Beside Arrange, because both are "how this screen looks to me"
           rather than anything about the product. */
        + '<button class="peo-btn" id="peo-rails" aria-pressed="' + (rails ? 'true' : 'false') + '"'
        +   ' title="Show or hide the coloured stripe down the left of each panel"'
        +   (rails ? ' style="border-color:#1f7d52;color:#1f7d52"' : '') + '>'
        +   (rails ? 'Stripes on' : 'Stripes off') + '</button>'
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

    host.innerHTML = '<div class="peo-wrap" data-peo-rails="' + (rails ? '1' : '0') + '">'
      + (model ? editorView() : pickerView()) + '</div>';
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

  /* ════════════════════════════════════════════════════════════════════════
     THE SET PANEL'S OWN WIRING. (Lane SP)

     Every listener below is installed by bindEditor() on the elements the last
     render() produced, which is how the rest of this screen works; nothing here
     is delegated to `document`, so nothing here is left behind when the panel
     is not drawn.
     ════════════════════════════════════════════════════════════════════════ */

  /* In-place, so the three figures move as the operator types without a render
     taking the focus out of the box. It writes text into boxes that already
     exist and asks the DOM nothing except which element carries an attribute --
     no size, no position, no computed style. */
  function refreshSetMoney(){
    if (!model || (model.type || 'simple') !== 'set') return;

    var t = setTotals();
    var code = boot ? boot.currency.code : '';

    var write = function(key, text){
      var box = document.querySelector('#content [data-peo-money="' + key + '"]');
      if (box) box.textContent = text;
    };

    write('parts', code + ' ' + setAed(t.parts));
    write('price', code + ' ' + setAed(t.price));
    write('saving', t.saving > 0 ? code + ' ' + setAed(t.saving) : '—');

    /* And the working-out beneath them, which is four more figures of the same
       arithmetic. Changing a quantity moves the parts total, which moves the
       reduction -- and a panel where three tiles updated and the explanation
       under them did not is worse than one that does not update at all.
       (Lane SP2) */
    var follow = document.querySelector('#content #peo-setfollow');

    if (follow) {
      follow.innerHTML = fixedFollowInner();
      bindSetReanchor(follow);
    }
  }

  /* The button lives inside HTML that refreshSetMoney() rewrites, so binding it
     is its own function rather than a line in the panel wiring. */
  function bindSetReanchor(scope){
    var el = scope && scope.querySelector('#peo-setreanchor');

    if (!el) return;

    el.addEventListener('click', function(){
      collect();
      setReanchor = true;
      dirty = true;
      render();
    });
  }

  async function setSearch(){
    /* COLLECT BEFORE THE RESULTS LAND. The render below rewrites every field
       from `model`, and the Sets screen shipped this exact defect: typing a
       product name into the picker silently emptied the name, the price and
       both descriptions above it. Found by a screenshot, not by a reader. */
    collect();

    try {
      var body = await api('/sets/products?q=' + encodeURIComponent(setQuery));
      setFound = (body && body.products) || [];
    } catch (e) {
      setFound = [];
      banner = { kind: 'bad', text: message(e, 'That search could not be run.') };
    }

    render();
  }

  /* MOVE, not swap: dropping row 1 onto row 4 puts it AT 4 and shuffles the
     rest up, which is what dragging a thing onto a place means. The arrows
     still swap with the neighbour, which is what an arrow means. */
  var setDragFrom = null;

  function setMemberIndex(node){
    var row = node && node.closest ? node.closest('[data-peo-setdrag]') : null;
    return row ? Number(row.getAttribute('data-peo-setdrag')) : null;
  }

  function bindSetBox(){
    var panel = document.querySelector('#content [data-peo-panel="setbox"]');
    if (!panel) return;

    var q = panel.querySelector('#peo-setq');

    if (q) {
      q.addEventListener('input', function(){
        setQuery = q.value;
        clearTimeout(bindSetBox._t);
        bindSetBox._t = setTimeout(setSearch, 220);
      });
    }

    /* ── "USE THIS TOTAL" ─────────────────────────────────────────────────
       NOT a button that copies the parts total into the price box. It switches
       the rule to "the parts total, less nothing", which keeps following the
       members afterwards -- a copied figure would go stale the first time a
       product in the box was repriced, which is precisely what the owner asked
       it not to do. */
    var use = panel.querySelector('#peo-usetotal');

    if (use) {
      use.addEventListener('click', function(){
        collect();
        model.price_mode = 'discount_amount';
        model.discount_amount = '0';
        dirty = true;
        render();
      });
    }

    /* "Start again from today's total". It changes NO price: it tells the next
       save to move the anchor to the parts total as it stands, so the figure
       the operator typed is measured from today rather than from whenever he
       last typed it. The only way to re-anchor without retyping the same
       number. (Lane SP2) */
    bindSetReanchor(panel);

    panel.querySelectorAll('[data-peo-mq]').forEach(function(el){
      el.addEventListener('input', function(){
        var i = Number(el.getAttribute('data-peo-mq'));
        var m = (model.set_members || [])[i];
        if (!m) return;
        m.quantity = Math.max(1, Math.min(99, parseInt(el.value, 10) || 1));
        dirty = true;
        refreshSetMoney();
      });
    });

    panel.querySelectorAll('[data-peo-rm]').forEach(function(el){
      el.addEventListener('click', function(){
        collect();
        (model.set_members || []).splice(Number(el.getAttribute('data-peo-rm')), 1);
        dirty = true;
        render();
      });
    });

    var swap = function(i, d){
      var list = model.set_members || [];
      var j = i + d;
      if (j < 0 || j >= list.length) return;
      var tmp = list[i]; list[i] = list[j]; list[j] = tmp;
      dirty = true;
      render();
    };

    panel.querySelectorAll('[data-peo-up]').forEach(function(el){
      el.addEventListener('click', function(){ collect(); swap(Number(el.getAttribute('data-peo-up')), -1); });
    });

    panel.querySelectorAll('[data-peo-down]').forEach(function(el){
      el.addEventListener('click', function(){ collect(); swap(Number(el.getAttribute('data-peo-down')), 1); });
    });

    panel.querySelectorAll('[data-peo-add]').forEach(function(el){
      el.addEventListener('click', function(){
        var id = Number(el.getAttribute('data-peo-add'));
        var found = (setFound || []).filter(function(x){ return Number(x.id) === id; })[0];
        if (!found) return;

        var sel = panel.querySelector('[data-peo-var="' + id + '"]');
        var variantId = sel && sel.value ? Number(sel.value) : null;

        collect();

        model.set_members = model.set_members || [];

        var already = model.set_members.some(function(m){
          return Number(m.product_id) === id && Number(m.variant_id || 0) === Number(variantId || 0);
        });

        if (already) {
          banner = { kind: 'bad', text: 'That product is already in the box. Raise its quantity instead.' };
          render();
          return;
        }

        model.set_members.push({
          product_id: id,
          variant_id: variantId,
          quantity: 1,
          name: found.name,
          brand: found.brand,
          sku: found.sku,
          variant: (sel && sel.selectedOptions && sel.selectedOptions[0] && sel.value)
            ? sel.selectedOptions[0].textContent : '',
          image: found.image,
          unit_price_aed: found.price_aed
        });

        dirty = true;
        render();
      });
    });

    /* ── DRAG AND DROP, AND WHAT IT DELIBERATELY DOES NOT DO ──────────────
       HTML5 drag events and nothing else. dragstart records which row picked
       up, dragover marks the row under the pointer, drop moves it. There is no
       pointermove handler, no scroll maths and NOTHING READS AN ELEMENT
       RECTANGLE: which row the pointer is over is a question the browser
       answers by firing the event on that row, and asking it any other way
       would be the layout measurement CLAUDE.md rule 4 forbids. */
    panel.querySelectorAll('[data-peo-setdrag]').forEach(function(row){
      row.addEventListener('dragstart', function(e){
        collect();
        setDragFrom = Number(row.getAttribute('data-peo-setdrag'));
        row.classList.add('is-drag');
        try {
          e.dataTransfer.effectAllowed = 'move';
          // Firefox will not start a drag at all unless something is set.
          e.dataTransfer.setData('text/plain', String(setDragFrom));
        } catch (err) {}
      });

      row.addEventListener('dragover', function(e){
        if (setDragFrom === null) return;
        e.preventDefault();            // without this the browser refuses the drop
        try { e.dataTransfer.dropEffect = 'move'; } catch (err) {}
        if (Number(row.getAttribute('data-peo-setdrag')) !== setDragFrom) row.classList.add('is-over');
      });

      row.addEventListener('dragleave', function(){ row.classList.remove('is-over'); });

      row.addEventListener('drop', function(e){
        if (setDragFrom === null) return;
        e.preventDefault();

        var to = setMemberIndex(e.target);
        var from = setDragFrom;

        setDragFrom = null;

        var list = model.set_members || [];

        if (to === null || from === to || from < 0 || from >= list.length || to < 0 || to >= list.length) {
          render();
          return;
        }

        list.splice(to, 0, list.splice(from, 1)[0]);
        dirty = true;
        render();
      });

      row.addEventListener('dragend', function(){
        setDragFrom = null;
        panel.querySelectorAll('.peo-setm').forEach(function(n){
          n.classList.remove('is-drag');
          n.classList.remove('is-over');
        });
      });
    });
  }

  /* A tag is committed on Enter (and on comma, because that is how everyone
     types a list of tags). preventDefault, or Enter submits nothing and the
     browser scrolls. (Lane SP) */
  function bindTags(){
    var box = document.querySelector('#content #peo-tagin');

    if (box) {
      box.addEventListener('keydown', function(e){
        if (e.key !== 'Enter' && e.key !== ',') return;
        e.preventDefault();

        var name = String(box.value || '').trim();
        if (name === '') return;

        collect();

        model.tags = model.tags || [];

        var already = model.tags.some(function(x){
          return String(x).toLowerCase() === name.toLowerCase();
        });

        if (!already) model.tags.push(name);

        box.value = '';
        dirty = true;
        render();

        // Put the cursor back, so a second tag can be typed straight away
        // rather than hunted for.
        var again = document.querySelector('#content #peo-tagin');
        if (again) { try { again.focus(); } catch (err) {} }
      });
    }

    document.querySelectorAll('#content [data-peo-tagrm]').forEach(function(b){
      b.addEventListener('click', function(){
        collect();
        (model.tags = model.tags || []).splice(Number(b.getAttribute('data-peo-tagrm')), 1);
        dirty = true;
        render();
      });
    });
  }

  function bindEditor(){
    bindSetBox();
    bindTags();

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

        /* ── THE TYPE AND THE PRICING RULE BOTH CHANGE WHAT IS ON SCREEN ───
           so both re-render, and both collect() FIRST. A render rebuilds every
           panel from `model`, so anything typed since the last one and not yet
           collected would be thrown away with the markup -- which is the defect
           the Sets screen's own search box shipped with and a screenshot, not a
           reader, found. (Lane SP)

           A full render is safe HERE and not on the `input` events above: this
           is a `change` on a <select>, which fires once, when the operator has
           finished choosing. */
        if (el.dataset.bind === 'type' || el.dataset.bind === 'price_mode') {
          collect();
          render();
          return;
        }

        /* The live preview the owner asked for -- "it will auto set the price"
           -- written in place rather than by re-rendering, because a render
           would take the focus out of the box being typed in. */
        if (el.dataset.bind === 'discount_percent' || el.dataset.bind === 'discount_amount'
            || el.dataset.bind === 'price_aed') {
          collect();
          refreshSetMoney();
          return;
        }

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
    /* Category search — filtered IN PLACE, never by re-rendering.
       A full render() on each keystroke rebuilds the input the operator is
       typing into, which loses focus and the caret; restoring both afterwards
       is a fiddle that this avoids entirely. catQuery is still kept up to date,
       so a render triggered by anything else reproduces the same filter. */
    /* The written view and the HTML view are two ways of editing one value, so
       each switch copies the live pane into the other one first. Nothing is
       parsed or cleaned here: what the operator typed is what the other pane
       receives, and RichText::clean() on the server remains the single place
       markup is judged. */
    document.querySelectorAll('#content .peo-rte').forEach(function(box){
      var btn  = box.querySelector('[data-cmd="code"]');
      var area = box.querySelector('.peo-rte-area');
      var code = box.querySelector('.peo-rte-code');
      var mode = box.querySelector('.peo-rte-mode');
      if (!btn || !area || !code) return;

      var imgBtn = box.querySelector('[data-cmd="image"]');

      if (imgBtn) {
        imgBtn.addEventListener('click', function(e){
          e.preventDefault();

          if (typeof window.kbbPickMedia !== 'function') return;

          /* The caret is lost the moment focus moves to the dialog, so the
             range is saved here and restored before inserting. Without it the
             image lands wherever the browser last had a selection — commonly
             the very start of a different description box. */
          var sel = window.getSelection();
          var range = (sel && sel.rangeCount && area.contains(sel.anchorNode))
            ? sel.getRangeAt(0).cloneRange()
            : null;

          window.kbbPickMedia({
            title: 'Insert an image',
            note: 'It is placed where the cursor is, and stays in your Media Library.',
            multiple: true,
            folder: 'products',
            onPick: function(urls){
              if (!urls.length) return;

              if (code.hidden) {
                area.focus();

                if (range) {
                  var s2 = window.getSelection();
                  s2.removeAllRanges();
                  s2.addRange(range);
                }

                var html = urls.map(function(u){
                  return '<img src="' + esc(u) + '" alt="">';
                }).join('');

                if (!document.execCommand('insertHTML', false, html)) {
                  area.innerHTML += html;
                }

                model[area.dataset.field] = area.innerHTML;
              } else {
                // The HTML view is showing, so the markup goes in as text.
                var tag = urls.map(function(u){
                  return '<img src="' + u + '" alt="">';
                }).join('\n');

                var at = code.selectionStart;
                code.value = code.value.slice(0, at) + tag + code.value.slice(code.selectionEnd);
                model[code.dataset.code] = code.value;
              }

              dirty = true; markDirty(); words(area);
            }
          });
        });
      }

      btn.addEventListener('click', function(e){
        e.preventDefault();

        var toCode = code.hidden;

        if (toCode) {
          code.value = area.innerHTML;
          code.style.height = Math.max(160, area.offsetHeight) + 'px';
        } else {
          area.innerHTML = code.value;
        }

        code.hidden = !toCode;
        area.hidden = toCode;

        /* Every other button drives the written view and does nothing to a
           textarea, so they are disabled rather than left looking live. */
        box.querySelectorAll('.peo-rte-bar button').forEach(function(b){
          if (b !== btn) b.disabled = toCode;
        });

        btn.setAttribute('aria-pressed', toCode ? 'true' : 'false');
        btn.classList.toggle('on', toCode);
        if (mode) {
          mode.innerHTML = toCode
            ? 'Editing the HTML. Press &lt;/&gt; again to go back.'
            : 'Paste from anywhere — use &lt;/&gt; to edit the HTML.';
        }

        (toCode ? code : area).focus();
        markDirty();
      });

      code.addEventListener('input', markDirty);
    });

    /* ---- the Media Library picker -------------------------------------
       Four controls, one dialog. Each hands the picker a callback and takes
       urls back; none of them knows anything about how the picker works, and
       the picker knows nothing about the product. The direct-upload paths
       below are untouched, so dragging a file onto the gallery still works
       exactly as it did — the owner asked for a way to STOP re-uploading, not
       for uploading to be taken away.

       Every one of these guards on the global existing. The picker is its own
       partial, and a package that shipped this screen without it would
       otherwise throw on the first click rather than simply not working. */
    function pickerReady(){ return typeof window.kbbPickMedia === 'function'; }

    var mainLib = document.querySelector('#content #peo-mainlib');
    if (mainLib) {
      mainLib.addEventListener('click', function(e){
        e.preventDefault();
        if (!pickerReady()) { banner = 'The Media Library is not available on this screen.'; render(); return; }
        window.kbbPickMedia({
          title: 'Choose the main image',
          folder: 'products',
          onPick: function(urls){
            if (!urls.length) return;
            setMainImage(urls[0]);
            dirty = true;
            render();
          }
        });
      });
    }

    var galLib = document.querySelector('#content #peo-gallib');
    if (galLib) {
      galLib.addEventListener('click', function(e){
        e.preventDefault();
        if (!pickerReady()) { banner = 'The Media Library is not available on this screen.'; render(); return; }
        window.kbbPickMedia({
          title: 'Add gallery images',
          note: 'Pick as many as you like. They are added after the main image, in the order shown here.',
          multiple: true,
          folder: 'products',
          onPick: function(urls){
            var added = 0;

            urls.forEach(function(u){
              /* The same two rules the upload path applies: never twice, and
                 never a second copy of the main image. Without them, choosing
                 from the library is the easiest way there is to file one
                 photograph in a gallery three times. */
              if (u && u !== model.image && model.images.indexOf(u) === -1) {
                model.images.push(u);
                added++;
              }
            });

            if (added) { dirty = true; }
            else { banner = urls.length === 1
                ? 'That image is already on this product.'
                : 'Those images are already on this product.'; }

            render();
          }
        });
      });
    }

    /* BACK TO AUTOMATIC. Setting the box to the main image IS the automatic
       state -- there is no flag to clear -- so this is one assignment and a
       re-render, and the state line above it flips to "Automatic". (Lane SP2) */
    bindOgAuto(document.querySelector('#content'));

    /* AND THE LINE KEEPS UP WITH THE BOX WHILE IT IS BEING TYPED INTO.

       Without this it is correct only after the next full render, so pasting a
       different picture's address left the screen still saying "Automatic —
       taken from the main image" about a share image that had just stopped
       being one. The line is rewritten in place rather than through render(),
       because render() rebuilds the input and takes the caret out of it
       mid-keystroke. (Lane SP2) */
    var ogBox = document.querySelector('#content #peo-og');

    if (ogBox) {
      ogBox.addEventListener('input', function(){
        model.seo = model.seo || {};
        model.seo.og_image = ogBox.value;

        var host = document.querySelector('#content #peo-ogstatehost');

        if (host) {
          host.innerHTML = ogStateView();
          bindOgAuto(host);
        }
      });
    }

    var ogLib = document.querySelector('#content #peo-oglib');
    if (ogLib) {
      ogLib.addEventListener('click', function(e){
        e.preventDefault();
        if (!pickerReady()) { banner = 'The Media Library is not available on this screen.'; render(); return; }
        window.kbbPickMedia({
          title: 'Choose the share image',
          note: 'Shown when this product is shared on Facebook, WhatsApp or X.',
          folder: 'products',
          onPick: function(urls){
            var box = document.querySelector('#content #peo-og');
            if (!box || !urls.length) return;
            box.value = urls[0];
            collect();
            markDirty();
            snippet();
          }
        });
      });
    }

    var railsBtn = document.querySelector('#content #peo-rails');
    if (railsBtn) railsBtn.addEventListener('click', function(){ setRails(!rails); });

    var catq = document.querySelector('#content #peo-catq');

    if (catq) {
      catq.addEventListener('input', function(){
        catQuery = catq.value;
        var q = catQuery.trim().toLowerCase();
        var listBox = document.querySelector('#content .peo-catlist');
        if (!listBox) return;

        var labels = listBox.querySelectorAll('.peo-check');
        var visible = 0;

        Array.prototype.forEach.call(labels, function(lab){
          var box = lab.querySelector('input[data-cat]');
          var ticked = box && box.checked;
          var name = (lab.textContent || '').trim().toLowerCase();
          // Ticked categories always stay visible — see categoriesView().
          var show = ticked || !q || name.indexOf(q) !== -1;
          lab.hidden = !show;
          if (show) visible++;
        });

        var note = document.querySelector('#content .peo-catnote');
        if (note) {
          note.hidden = !q;
          note.textContent = visible
            ? 'Showing ' + visible + ' of ' + labels.length + '. Ticked categories stay visible while you search.'
            : 'No category matches “' + catQuery + '”.';
        }
      });
    }

    var mainFile = document.querySelector('#content #peo-mainfile');
    on('#peo-mainpick', 'click', function(){ if (mainFile) mainFile.click(); });
    if (mainFile) mainFile.addEventListener('change', function(){ takeFiles(mainFile.files, 'main'); });

    on('#peo-mainrm', 'click', function(){
      /* collect() FIRST, then the change. It reads every box on the screen back
         into `model` -- including the share image box -- so running it after
         setMainImage() would put the old share image straight back. */
      collect();
      setMainImage(null);
      dirty = true;
      render();
    });

    var galFile = document.querySelector('#content #peo-galfile');
    var drop = document.querySelector('#content #peo-galdrop');

    // Click is all this button does now. The DROP is the card's, below.
    if (drop) drop.onclick = function(){ if (galFile) galFile.click(); };

    if (galFile) galFile.addEventListener('change', function(){ takeFiles(galFile.files, 'gallery'); });

    /* ── THE THREE FILE ZONES ─────────────────────────────────────────────
       One per card, on the CARD and not on the dashed box inside it, and each
       registered through dropZone() so the console's shared kit takes over the
       moment it is on the page.

       WHY THE WHOLE CARD. Before this, the gallery's 140px dashed button was the
       only file target on the screen, and a photograph let go anywhere else did
       not just fail -- nothing in this console prevents the default drop, so the
       browser NAVIGATED AWAY to the file and took the half-filled product form
       with it. Three cards that each swallow a drop is the fix for that as much
       as it is the feature.

       `accept` mirrors each input's own attribute, because a drop never goes
       near the input and the browser applies accept to its own file dialog and
       to nothing else -- without it, dropping a .zip on the gallery would post
       it to an image endpoint. `multiple` mirrors it too: the gallery takes as
       many as are dropped, the main image and the share image take one. */
    zones.forEach(function(teardown){ try { teardown(); } catch (e) {} });
    zones = [];

    [['#peo-galzone', 'gallery', true],
     ['#peo-mainzone', 'main', false],
     ['#peo-ogzone', 'og', false]].forEach(function(z){
      var node = document.querySelector('#content ' + z[0]);
      if (!node) return;

      zones.push(dropZone(node, {
        accept: 'image/*',
        multiple: z[2],
        onFiles: (function(where){
          return function(files){ takeFiles(files, where); };
        })(z[1])
      }));
    });

    /* Stop, for a row and for the rest of a batch. Delegated on #content, so a
       panel redrawn by paintUploads() does not have to be re-armed -- and bound
       ONCE for the life of the screen rather than on every render, which is what
       the flag below is for. render() runs on every keystroke that marks the
       form dirty. */
    if (!stopWired) {
      stopWired = true;

      var host = document.querySelector('#content');

      if (host) {
        host.addEventListener('click', function(e){
          var hit = e.target.closest ? e.target.closest('[data-upx],[data-upstop],[data-upclear]') : null;
          if (!hit) return;

          e.preventDefault();

          if (hit.hasAttribute('data-upstop')) { stopRest(hit.getAttribute('data-upstop')); return; }

          if (hit.hasAttribute('data-upclear')) {
            var where = hit.getAttribute('data-upclear');
            uploads = uploads.filter(function(u){ return u.where !== where; });
            /* collect() first, for the same reason every other repaint on this
               screen does it: innerHTML is the only place the rich-text panes
               live until then, and re-rendering without it discards whatever the
               operator had just typed in one. */
            collect();
            render();
            return;
          }

          stopOne(parseInt(hit.getAttribute('data-upx'), 10));
        });
      }
    }

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
    on('#peo-ogdrop', 'click', function(){ if (ogFile) ogFile.click(); });

    /* Through takeFiles() like the other two, which is the whole point of this
       change: the share image used to call upload() with no progress callback,
       so xhr.upload.onprogress was never attached and the one thing the operator
       saw was the form going grey. */
    if (ogFile) ogFile.addEventListener('change', function(){ takeFiles(ogFile.files, 'og'); });

    /* ---- rich text ---- */
    document.querySelectorAll('#content .peo-rte').forEach(bindRte);

    /* ---- the Arabic boxes' Translate buttons ---- */
    /* Idempotent and safe to call on every render. It also reveals the buttons,
       which are drawn hidden: with no API key configured they are never shown
       at all, and every manual path on this screen works with no key. */
    if (window.KBBArabic) KBBArabic.wire(document.querySelector('#content') || document);

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
      /* The HTML button is not an execCommand — it swaps which pane is
         showing, and its own listener does that. Claiming it here too would
         focus the written pane the instant the operator asked for the HTML
         one, and run execCommand('code') into the bargain. */
      if (b.dataset.cmd === 'code' || b.dataset.cmd === 'image') return;

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

        setField(area.dataset.field, area.innerHTML);
        dirty = true; markDirty(); words(area);
      };
    });

    area.addEventListener('input', function(){
      setField(area.dataset.field, area.innerHTML);
      dirty = true; markDirty(); words(area);
    });

    /* Paste KEEPS its markup, which reverses what this did before.
       It used to force plain text, on the reasoning that Word's markup would
       be stripped on save anyway so showing it in between was misleading. The
       owner has asked for the opposite and is right about the cost: pasting a
       formatted description from a supplier sheet, a previous site or another
       shop arrived as one grey slab, and rebuilding every heading, list and
       link by hand is the work this box exists to avoid.

       The browser's own paste is therefore allowed to run, EXCEPT that
       text/html is preferred explicitly so a source offering both does not get
       flattened. RichText::clean() on the server is still the only thing that
       decides what may be stored — this changes what reaches the editor, not
       what reaches the database, and the two disagreeing is exactly what the
       HTML view is there to make visible. */
    area.addEventListener('paste', function(e){
      var cd = e.clipboardData || window.clipboardData;
      if (!cd) return;                        // let the browser handle it

      var html = '';
      try { html = cd.getData('text/html') || ''; } catch (err) { html = ''; }

      if (!html) return;                      // plain text: nothing to improve on

      e.preventDefault();

      /* Fragments copied from a browser arrive wrapped in a full document with
         <head>, and some sources include a <style> block that would otherwise
         land in the pane as visible CSS. Taking the body's contents is what
         every editor does here; the server still judges the result. */
      var doc = null;
      try { doc = new DOMParser().parseFromString(html, 'text/html'); } catch (err) { doc = null; }

      var frag = doc && doc.body ? doc.body.innerHTML : html;

      if (!document.execCommand('insertHTML', false, frag)) {
        document.execCommand('insertText', false, cd.getData('text/plain'));
      }
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

    var emitted = emittedTags();

    t.textContent = clip(emitted.title, 60);
    d.textContent = clip(emitted.description, 155);
    u.textContent = (model.readonly && model.readonly.url) || ('/product/' + (model.slug || 'new-product') + '/');

    counts();
  }

  /* WHAT THE STOREFRONT WILL PUT IN THIS PRODUCT'S HEAD — not what the boxes
     hold, which is not the same string and was what this panel used to draw.

     Both fallbacks come from the product endpoint as `seo_fallback_title` and
     `seo_fallback_description`, built by App\Support\ProductSeo out of
     Seo::titleFor() and Seo::describe() — the same two methods the page itself
     renders with. So an empty box shows the real tag, including the case where
     the real tag is EMPTY: a product whose short description was cleared
     publishes no <meta name="description"> at all, and an empty line here is
     what Google would show. The invented sentence that used to sit in that slot
     described a page this shop has never served.

     A FILLED TITLE BOX IS THE WHOLE TITLE. Store\ProductController::show() sets
     `title_is_final` for a per-product SEO title, so the template is not
     applied and the site name is NOT appended — the typed string is the tag,
     which is why it is used here verbatim. The one thing this cannot resolve
     client-side is a %%token%% chip inside a typed title; those are substituted
     server-side, and the panel repaints from the endpoint after every save. */
  function emittedTags(){
    var seoT = (document.querySelector('#content #peo-seo-t') || {}).value || '';
    var seoD = (document.querySelector('#content #peo-seo-d') || {}).value || '';

    return {
      title: seoT.trim() || model.seo_fallback_title || model.name || 'Product',
      description: seoD.trim() || model.seo_fallback_description || ''
    };
  }

  /* The counters measure the EMITTED tag, not the box.

     With the title box empty the tag is brand + name run through
     `seo_title_template`, which on this store appends " | K-Beauty Bliss" — so
     counting the box told the operator "0 / 60" under a title Google receives
     at 40-odd characters, and counting a 58-character typed-then-cleared title
     told them "58 / 60, good" under a 75-character tag. Same reasoning as the
     snippet above: measure the thing that ships. */
  function counts(){
    var emitted = emittedTags();

    count('seo-title', emitted.title, 50, 60);
    count('seo-desc', emitted.description, 120, 155);
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

      /* ── A PHOTOGRAPH IS NOT A REORDER, AND THIS USED TO PRETEND IT WAS ──
         These three handlers ran on ANY drag, files included. So dragging a JPEG
         from the desktop over an existing gallery thumbnail lit the thumbnail up
         green -- promising a drop -- and the drop handler then found from === null
         and returned, silently doing nothing at all. Refusing a file drag here is
         what lets it reach the card's file zone instead, which uploads it.
         The two drags share one document and each now refuses the other's
         payload, the same way the shoppable-video screen's two do. */
      tile.addEventListener('dragover', function(e){
        if (from === null || carriesFiles(e)) return;
        e.preventDefault();
        tile.classList.add('peo-over');
        try { e.dataTransfer.dropEffect = 'move'; } catch (x) {}
      });

      tile.addEventListener('dragleave', function(){ tile.classList.remove('peo-over'); });

      tile.addEventListener('drop', function(e){
        // Not preventDefault()ed and not stopPropagation()ed for a file: the
        // event has to reach #peo-galzone, which is what uploads it.
        if (from === null || carriesFiles(e)) return;

        e.preventDefault();
        e.stopPropagation();

        var to = parseInt(tile.dataset.i, 10);
        if (isNaN(to) || from === to) return;

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
