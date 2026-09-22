{{--
    Appearance → Cart page. (Lane: cart-page)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, window.kbbAddNavEntry and
    toast(). Its own file rather than more lines inside a 20,000-line Blade:
    several lanes edit that file at once, and a screen that lives on its own can
    be reviewed, reverted and merged on its own. The cost is that it cannot
    reach app.blade.php's module-scoped constants -- NAV, TITLES and ADMIN_BASE
    are const, not window properties -- so it appends its own sidebar entry and
    wraps window.go, exactly as cache-screen.blade.php and routines-screen.blade.php
    already do.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    The owner's cart-page brief, made adjustable: "i just want to squeeze the
    rows ... so the elements inside the rows must adjust the size and fonts
    sizes auto as per the rows height when i control from the backend", "give
    control of bold/unbold for products rows and grid section both", "i need
    full control to choose the products for this grid section with search
    products functionality on backend", "also give option to control such all
    fields and data on the backend nicely, also to adjust the floating rows
    sizes and font sizes etc".

    Every control here writes App\Services\CartPage::SCHEMA, and the FIRST one
    is the layout switch, because none of the rest of them does anything until
    it is moved. That is deliberate and it is stated on the screen: the package
    applies to a live shop and changes nothing about the cart page until the
    owner chooses to change it.

    ── THE PRODUCT PICKER ───────────────────────────────────────────────────

    The rail's products are a stored list of ids. An id is not something anybody
    can check by reading it, so the screen is handed the PRODUCTS, resolved by
    the server, and searches the catalogue for more. Search is debounced at
    220ms and the endpoint is throttled as well -- the debounce is a promise the
    browser makes and the throttle is the one the server makes.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file -- inside a comment
    included -- with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    This file is NOT inside app.blade.php's raw region, so Blade interpolation
    works here and @json is safe. Inside that region it would ship as literal
    text and be a SyntaxError in the script block that builds half the console,
    which is a fault php -l cannot see and a file-reading test cannot see
    either. CartPageScreenTest renders this screen and parses it.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED cps- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and so
    is every data- attribute anything clicks. app.blade.php binds around a dozen
    delegated listeners to `document` itself, each claiming a bare attribute
    name -- [data-open], [data-tg], [data-pp] -- and a click on any element
    carrying one is handled by that listener whichever screen it belongs to.
--}}
@verbatim
<style>
/* Controls left, a phone that redraws as you drag on the right. Below 1100px
   the preview goes under the controls rather than squeezing both: a 300px
   control column and a 300px phone are two things nobody can use. */
.cps-wrap{display:grid;gap:14px;min-width:0;grid-template-columns:minmax(0,1fr) 336px;align-items:start}
.cps-wrap > *{min-width:0}
.cps-wrap > .cps-col{display:grid;gap:14px;min-width:0}
@media (max-width:1100px){.cps-wrap{grid-template-columns:minmax(0,1fr)}}

/* ── the preview ────────────────────────────────────────────────────────── */
.cpv{position:sticky;top:16px;min-width:0}
@media (max-width:1100px){.cpv{position:static}}
.cpv-h{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin:0 0 8px}
.cpv-h b{font-size:12px;font-weight:650}
.cpv-h span{font-size:11px;color:var(--ink-soft,#6b7280)}
.cpv-phone{border:1px solid var(--border,#e6e6e6);border-radius:20px;overflow:hidden;
  background:#fff;box-shadow:0 8px 26px -18px rgba(0,0,0,.4)}
.cpv-bar{display:flex;align-items:center;gap:6px;padding:7px 11px;background:#f6f7f9;
  border-bottom:1px solid var(--border,#e6e6e6);font-size:10px;color:#6b7280}
.cpv-bar i{width:6px;height:6px;border-radius:50%;background:#d8dbe0;display:block}
.cpv-body{position:relative;background:#fbf5f4;padding:10px;min-height:150px;color:#17181c}
.cpv-note{font-size:10.5px;color:#6b7280;padding:14px 10px;text-align:center}

/* rows — every size derives from --h, exactly as the shop does */
.cpv-ci{display:flex;align-items:center;gap:calc(var(--h) * .13);height:var(--h);
  padding:calc(var(--h) * .13);background:#fff;border-bottom:1px solid #ebe3e6;overflow:hidden}
.cpv-ci:first-child{border-radius:10px 10px 0 0}
.cpv-ci:last-child{border-bottom:0;border-radius:0 0 10px 10px}
.cpv-th{flex:0 0 auto;width:calc(var(--h) - var(--h) * .26);height:calc(var(--h) - var(--h) * .26);
  border-radius:8px;display:grid;place-items:center;color:#fff;font-weight:600;
  font-size:calc((var(--h) - var(--h) * .26) * .32)}
.cpv-mid{flex:1 1 auto;min-width:0}
.cpv-br{font-size:calc((7.5px + var(--h) * .022) * var(--f));font-weight:var(--w);
  letter-spacing:.09em;text-transform:uppercase;color:#c13a5e;white-space:nowrap;overflow:hidden;
  text-overflow:ellipsis}
.cpv-nm{font-size:calc((11.5px + var(--h) * .042) * var(--f));font-weight:var(--w);line-height:1.25;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cpv-qty{display:inline-flex;align-items:center;border:1px solid #ebe3e6;border-radius:99px;
  height:calc(var(--h) * .30);margin-top:calc(var(--h) * .04);
  font-size:calc(var(--h) * .135 * var(--f))}
.cpv-qty b{padding:0 calc(var(--h) * .10);font-weight:var(--w)}
.cpv-qty i{padding:0 calc(var(--h) * .08);font-style:normal;color:#6b7280}
.cpv-pr{flex:0 0 auto;font-size:calc((11px + var(--h) * .040) * var(--f));font-weight:var(--w);
  white-space:nowrap}

/* rail */
.cpv-rec{margin:0 -10px;padding:9px 0;position:relative;overflow:hidden}
.cpv-rec::before{content:"";position:absolute;inset:0;
  background:linear-gradient(115deg,#FFEDF3,#FFF6EC,#EFF9F3,#F4EFFC,#FFEDF3);background-size:280% 280%;
  animation:cpvdrift calc(26s / var(--mo,1)) ease-in-out infinite;opacity:calc(.45 + .55 * var(--mo,1))}
@keyframes cpvdrift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@media (prefers-reduced-motion:reduce){.cpv-rec::before{animation:none}}
.cpv-rec > *{position:relative}
.cpv-rec h6{margin:0 0 7px;padding:0 10px;font-size:11.5px;font-weight:600}
.cpv-rail{display:flex;gap:5px;padding:0 10px 2px;overflow:hidden}
.cpv-rc{flex:0 0 calc((100% - (5px * (var(--per) - 1))) / var(--per));background:#fff;
  border:1px solid #ebe3e6;border-radius:7px;padding:4px}
.cpv-rc .im{position:relative;aspect-ratio:1;border-radius:5px;margin-bottom:var(--rig,5px)}
/* The + on the corner of the picture. Same three knobs the shop reads -- the
   size is a multiplier and the two offsets are added to the corner it is
   pinned to -- so the drawing cannot disagree with the card by arithmetic. */
.cpv-rc .pl{position:absolute;inset-inline-end:calc(-2px + var(--rax,0px));
  bottom:calc(-2px + var(--ray,0px));
  width:calc(15px * var(--ras,1));height:calc(15px * var(--ras,1));border-radius:50%;
  background:#1e9e5a;color:#fff;border:1.5px solid #fff;display:grid;place-items:center;
  font-size:calc(10px * var(--ras,1));line-height:1}
.cpv-rc .t{font-size:8px;font-weight:var(--rw);line-height:var(--rlh,1.25);
  height:calc(var(--rlh,1.25) * 2em);overflow:hidden;color:#3c3a40;margin-bottom:var(--rg,2px)}
.cpv-rc .p{font-size:8.5px;font-weight:var(--rpw,400)}

/* summary */
.cpv-sum{background:#fff;border:1px solid #ebe3e6;border-radius:10px;padding:10px;font-size:11px}
.cpv-sr{display:flex;justify-content:space-between;gap:8px;padding:2px 0;color:#3c3a40}
.cpv-sr s{color:#9aa0aa;margin-right:3px}
.cpv-tot{display:flex;justify-content:space-between;align-items:center;gap:8px;background:#e6f5ed;
  border-radius:7px;padding:7px 9px;margin-top:7px;font-weight:700;font-size:12px}
.cpv-ship{margin:6px 0 2px}
.cpv-ship .t{font-size:10px;color:#6b7280;margin-bottom:4px}
.cpv-ship .t.won{color:#1e9e5a;font-weight:600}
.cpv-ship .bar{position:relative;height:7px;border-radius:99px;background:#f0eaec}
.cpv-ship .fill{position:relative;height:100%;border-radius:99px;
  background:linear-gradient(90deg,#1e9e5a,#3fd089 45%,#1e9e5a);background-size:220% 100%;
  animation:cpvflow 2.6s linear infinite}
@keyframes cpvflow{to{background-position:-220% 0}}
.cpv-ship .fill::after{content:"";position:absolute;right:0;top:50%;width:20px;height:20px;
  transform:translate(50%,-50%);border-radius:50%;
  background:radial-gradient(circle,rgba(255,255,255,.95) 0%,rgba(150,245,196,.7) 28%,rgba(63,208,137,0) 68%);
  animation:cpvbloom 1.9s ease-in-out infinite}
@keyframes cpvbloom{0%,100%{opacity:.75;transform:translate(50%,-50%) scale(.85)}
  50%{opacity:1;transform:translate(50%,-50%) scale(1.15)}}
@media (prefers-reduced-motion:reduce){.cpv-ship .fill,.cpv-ship .fill::after{animation:none}}
/* The whole trust row off --ts, exactly as the shop derives it from
   --cpg-trust-s: one number, so the row scales as a row. */
.cpv-trust{display:flex;align-items:center;justify-content:center;gap:calc(5px * var(--ts));
  flex-wrap:wrap;padding:calc(8px * var(--ts)) 0 2px;font-size:calc(9px * var(--ts));color:#6b7280}
.cpv-trust .tick{color:#1e9e5a;font-weight:700}
.cpv-pay{height:calc(13px * var(--ts));padding:0 calc(3px * var(--ts));border:1px solid #ebe3e6;
  border-radius:2px;background:#fff;
  display:grid;place-items:center;font-size:calc(5.5px * var(--ts));font-weight:600;color:#3c3a40}

/* docked */
/* White to the bottom edge, and --bp is the shop's own space under the rows.
   Drawn on the block and not on the row inside it, for the same reason the
   shop does it that way: the space it adds has to be white too. */
.cpv-dock{border-top:1px solid #ebe3e6;background:#fff;padding-bottom:var(--bp);
  box-shadow:0 -2px 10px -6px rgba(0,0,0,.25)}
/* #fff, matching "the background must be full white" — the preview showed the
   cream the shop no longer uses. */
.cpv-ab{display:flex;align-items:center;justify-content:space-between;gap:8px;background:#fff;
  min-height:var(--ah);padding:0 10px;font-size:calc(11px * var(--bf))}
.cpv-ab .who{min-width:0;overflow:hidden}
.cpv-ab .who b,.cpv-ab .who i{display:block;font-style:normal;white-space:nowrap;overflow:hidden}
.cpv-ab .who b{font-weight:500;color:#3c3a40}
.cpv-ab .who i{color:#6b7280;font-size:calc(10px * var(--bf))}
/* THE FADE ONLY ON THE ROW THAT HAS AN ADDRESS. Both states are drawn below, so
   the difference this makes is visible here rather than only on a phone. */
.cpv-ab.has .who b,.cpv-ab.has .who i{
  -webkit-mask-image:linear-gradient(to right,#000 calc(100% - 22px),transparent);
  mask-image:linear-gradient(to right,#000 calc(100% - 22px),transparent)}
.cpv-ab .bt{flex:0 0 auto;color:#1e9e5a;font-weight:600;
  font-size:calc(11.5px * var(--bf) * var(--abf))}
.cpv-cb{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fff;
  min-height:var(--ch);padding:0 10px}
/* 0 0 auto: the tally never shrinks, so the figure cannot be clipped — the
   button gives way, exactly as it does on the page. */
.cpv-cb .ta{flex:0 0 auto;min-width:0;white-space:nowrap}
.cpv-cb .ta i{display:block;font-style:normal;font-size:calc(10px * var(--bf));color:#6b7280}
.cpv-cb .ta b{display:block;font-size:calc(15px * var(--bf));font-weight:700}
/* "AED" and the digits are ONE inline run. The shop's own defect was a
   descendant selector turning the currency span into a block; the preview
   states the intended result so a future edit has something to disagree with. */
.cpv-cb .ta b span{display:inline;font-size:inherit;color:inherit;white-space:nowrap}
.cpv-cb button{flex:0 1 auto;min-width:0;background:#1e9e5a;color:#fff;border:0;border-radius:8px;
  padding:calc(9px * var(--bf)) calc(13px * var(--bf));font-size:calc(12.5px * var(--bf));
  font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* popup */
.cpv-stage{position:relative;height:290px;background:#fbf5f4;overflow:hidden}
.cpv-scrim{position:absolute;inset:0;background:rgba(23,24,28,.34);
  -webkit-backdrop-filter:blur(calc(var(--bl,3) * 1px));backdrop-filter:blur(calc(var(--bl,3) * 1px))}
.cpv-sheet{position:absolute;left:0;right:0;bottom:0;background:#fff;border-radius:13px 13px 0 0;
  padding:calc(11px * var(--sd));overflow:hidden;max-height:var(--cap)}
.cpv-sheet h6{margin:0 0 8px;font-size:calc(13px * var(--sf));font-weight:700}
.cpv-al{display:flex;gap:7px;align-items:flex-start;border:1px solid #e4e7ec;border-radius:8px;
  padding:calc(8px * var(--sd));margin-bottom:6px;position:relative;overflow:hidden}
.cpv-al.on{border-color:#1e9e5a}
.cpv-al.on::after{content:"✓";position:absolute;top:0;right:0;width:26px;height:21px;
  border-radius:0 7px 0 8px;background:#1e9e5a;color:#fff;display:grid;place-items:center;font-size:11px}
.cpv-al .ad{min-width:0;flex:1}
.cpv-al .ad b{display:block;font-size:calc(11.5px * var(--sf));padding-right:28px}
.cpv-al .ad i{display:block;font-style:normal;font-size:calc(10px * var(--sf));color:#6b7280;
  line-height:1.4;max-height:2.8em;overflow:hidden}
.cpv-tag{flex:0 0 auto;align-self:center;background:#e8f6ee;color:#177f47;border-radius:4px;
  padding:2px 6px;font-size:calc(9.5px * var(--sf));font-weight:500}
.cpv-add{background:none;border:0;color:#1e9e5a;font-size:calc(11.5px * var(--sf));font-weight:600;
  padding:calc(6px * var(--sd)) 0}
/* The guest-cap line, at the preview's own scale — the live sheet's .cpg-note
   in the same place and the same grey. */
.cpv-note{margin:calc(3px * var(--sd)) 0 0;color:#6b7280;
  font-size:calc(9.5px * var(--sf));line-height:1.35}
.cpv-fg{display:grid;gap:calc(7px * var(--sd));margin-bottom:8px}
.cpv-fg.two{grid-template-columns:1fr 1fr}
.cpv-fg.two .full{grid-column:1 / -1}
.cpv-fg label{display:block;font-size:calc(9.5px * var(--sf));font-weight:600;margin-bottom:2px;color:#3c3a40}
.cpv-fi{border:1px solid #e4e7ec;border-radius:6px;padding:calc(6px * var(--sd)) 7px;
  font-size:calc(11px * var(--sf));color:#9aa0aa;background:#fff;white-space:nowrap;overflow:hidden;
  text-overflow:ellipsis}
.cpv-geo{font-size:calc(9px * var(--sf));color:#1e9e5a;margin:0}
.cpv-act{display:grid;grid-template-columns:auto auto 1fr;gap:5px;margin-top:2px}
.cpv-mk{display:inline-flex;align-items:center;gap:4px;border:1px solid #e4e7ec;border-radius:7px;
  padding:calc(7px * var(--sd)) calc(8px * var(--sd));font-size:calc(10.5px * var(--sf));
  font-weight:600;color:#3c3a40;white-space:nowrap}
.cpv-mk.on{border-color:#1e9e5a;background:#e8f6ee;color:#177f47}
.cpv-dl{display:grid;place-items:center;background:#1e9e5a;color:#fff;border-radius:7px;
  padding:calc(7px * var(--sd)) 6px;font-size:calc(11px * var(--sf));font-weight:700;white-space:nowrap}
.cps-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.cps-title{font-weight:650;font-size:15px}
.cps-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.cps-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.cps-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.cps-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.cps-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.cps-f{display:grid;gap:5px;min-width:0}
.cps-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.cps-fh label{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.cps-val{font-size:11.5px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap}
.cps-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.cps-f input[type=range]{width:100%;accent-color:var(--accent,#15a85a);margin:0;min-width:0}
.cps-f input[type=text],.cps-f input[type=number],.cps-f select{
  width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.cps-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.cps-check input{margin-top:3px;flex:none;width:16px;height:16px}
.cps-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.cps-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.cps-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.cps-btn[disabled]{opacity:.45;cursor:default}
.cps-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.cps-search{width:100%;min-width:0;padding:9px 11px;font:inherit;font-size:13px;
            border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.cps-list{margin-top:10px;border:1px solid var(--border,#e6e6e6);border-radius:10px;
          max-height:300px;overflow-y:auto;min-width:0}
.cps-row{display:flex;align-items:center;gap:10px;padding:9px 11px;min-width:0;cursor:pointer;
         border-bottom:1px solid var(--border,#e6e6e6);background:transparent;border-left:0;
         border-right:0;border-top:0;width:100%;text-align:left;color:inherit;font:inherit}
.cps-row:last-child{border-bottom:0}
.cps-box{width:19px;height:19px;flex:none;border:1.5px solid var(--border,#e6e6e6);border-radius:5px;
         display:grid;place-items:center;font-size:12px;color:#fff}
.cps-row[aria-selected="true"] .cps-box{border-color:var(--accent,#15a85a);background:var(--accent,#15a85a)}
.cps-row .cps-nm{flex:1;min-width:0}
.cps-row .cps-nm b{display:block;font-size:13px;font-weight:600;overflow:hidden;
                   text-overflow:ellipsis;white-space:nowrap}
.cps-row .cps-nm span{display:block;font-size:11px;color:var(--ink-soft,#6b7280)}
.cps-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.cps-chosen{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;min-width:0}
/* The chosen rail, drawn above the search because it is the answer the panel
   exists to give. Tinted, so it reads as a held list rather than as more
   results. */
.cps-picked-wrap{border:1px solid var(--border,#e6e6e6);border-radius:10px;
  background:var(--sunk,#f6f7f9);padding:10px 11px;margin-bottom:12px}
.cps-picked-h{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;
  color:var(--ink-soft,#6b7280);margin-bottom:8px}
.cps-picked-h b{font-size:13px;color:var(--ink,#16181d)}
.cps-picked-h em{font-style:normal;font-size:10.5px;font-weight:600;letter-spacing:.06em;
  text-transform:uppercase;background:var(--accent,#15a85a);color:#fff;padding:2px 7px;border-radius:99px}
.cps-picked{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.cps-pk{display:flex;align-items:center;gap:9px;background:var(--surface,#fff);
  border:1px solid var(--border,#e6e6e6);border-radius:8px;padding:7px 9px;min-width:0}
.cps-pos{flex:0 0 auto;width:20px;height:20px;border-radius:50%;background:var(--accent,#15a85a);
  color:#fff;display:grid;place-items:center;font-size:11px;font-weight:700;
  font-variant-numeric:tabular-nums}
.cps-pk .cps-nm{flex:1;min-width:0}
.cps-mv{flex:0 0 auto;display:flex;gap:3px}
.cps-mv button{width:26px;height:26px;border:1px solid var(--border,#e6e6e6);border-radius:6px;
  background:var(--surface,#fff);color:var(--ink-soft,#6b7280);cursor:pointer;font-size:12px;
  line-height:1;display:grid;place-items:center}
.cps-mv button:hover:not([disabled]){border-color:var(--accent,#15a85a);color:var(--accent,#15a85a)}
/* Disabled, not hidden: the first and last rows keep their buttons in place so
   the row does not reflow under the pointer as you move things. */
.cps-mv button[disabled]{opacity:.35;cursor:default}
.cps-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border,#e6e6e6);
          border-radius:999px;padding:4px 6px 4px 11px;font-size:11.5px;max-width:100%}
.cps-chip b{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:22ch}
.cps-chip button{border:0;background:transparent;color:var(--ink-soft,#6b7280);cursor:pointer;
                 font:inherit;font-size:14px;line-height:1;padding:0 4px}
@media (max-width:640px){ .cps-card{padding:13px} }
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'cartpage';

  /* ---------------------------------------------------------------- state */
  var tabs = null;       // GET /admin-api/cart-page -> tabs
  var values = {};       // key -> current value, edited in place
  var chosen = [];       // [{id,name,brand,image}] the rail's products, in order
  var maxRec = 24;
  var open = null;       // which tab is showing
  var results = [];      // last search
  var banner = null;
  var busy = false;
  var seq = 0;
  var timer = null;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  /* A 404 from these endpoints almost always means the package shipped without
     its clear_caches migration having run, so the compiled route table does not
     know these paths. Said plainly rather than drawing an empty screen. */
  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Cart page endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Cart page',
      icon: '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M9.5 9.5h7"/>',
      group: 'Appearance',
      after: ['cartpanel', 'dividers']
    });
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Cart page';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function load() {
    var mine = ++seq;
    busy = true;
    banner = null;
    render();

    try {
      var body = await api('/cart-page');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      chosen = body.chosen || [];
      maxRec = body.maxRec || 24;
      values = {};
      tabs.forEach(function (t) {
        t.fields.forEach(function (f) { values[f.key] = f.value; });
      });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Cart page settings could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function save() {
    if (busy) return;
    busy = true;
    render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });
    payload.rec_ids = chosen.map(function (p) { return p.id; }).join(',');

    try {
      await api('/cart-page', { settings: payload });
      say('Cart page saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false;
      render();
    }
  }

  /*
   * WHAT THE OWNER TYPED, kept outside render().
   *
   * search() finishes by calling render(), which rebuilds the whole screen --
   * including the search box. The box was drawn with no `value`, so 220ms after
   * every keystroke it emptied itself and the caret jumped back to an empty
   * field. Reported as "it's not letting me write anything", which is exactly
   * what it looked like: you could type, and then you could not.
   */
  var term = '';

  async function search(t) {
    term = t;
    var mine = ++seq;
    try {
      var body = await api('/cart-page/products?q=' + encodeURIComponent(t));
      if (mine !== seq) return;
      results = body.products || [];
    } catch (e) {
      if (mine !== seq) return;
      results = [];
      banner = explain(e, 'The catalogue could not be searched.');
    }
    render();
  }

  /* ----------------------------------------------------------------- draw */

  /* rec_per is stored in TENTHS, because 4.5 is not an integer and every other
     range in the schema is. The service clamps and stores 45; this is the one
     place that knows to print 4.5. */
  function shown(f) {
    if (f.key === 'rec_per') return (values[f.key] / 10).toFixed(1);
    if (f.type === 'money') return (values[f.key] / 100).toFixed(2);
    var unit = (f.options && f.options.unit) || '';
    return values[f.key] + (unit === '/10' ? '' : unit);
  }

  /*
   * The service fee's two modes read two different stored values, and that is
   * what stops a pricing change being made by a units change: with one shared
   * box, flipping the mode turns AED 3.00 into 3% of the order in silence — a
   * fee that has multiplied by ten on a hundred-dirham basket, with nobody
   * having touched the number. So only the field the current mode uses is
   * drawn, and each keeps its own value and its own step.
   */
  function hidden(f) {
    if (f.key === 'sum_service' && String(values.sum_service_mode) === 'percent') return true;
    if (f.key === 'sum_service_pct' && String(values.sum_service_mode) !== 'percent') return true;
    return false;
  }

  function fieldHTML(f) {
    if (hidden(f)) return '';

    var id = 'cps-' + f.key;
    var help = f.help ? '<p class="cps-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="cps-f"><div class="cps-check">'
        + '<input type="checkbox" id="' + id + '" data-cps-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"'
          + (String(values[f.key]) === k ? ' selected' : '') + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="cps-f"><div class="cps-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + id + '" data-cps-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="cps-f"><div class="cps-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="cps-val" data-cps-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
        + '<input type="range" id="' + id + '" data-cps-key="' + esc(f.key) + '"'
        + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
        + help + '</div>';
    }

    if (f.type === 'money') {
      return '<div class="cps-f"><div class="cps-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<input type="number" min="0" step="0.01" id="' + id + '" data-cps-key="' + esc(f.key) + '"'
        + ' data-cps-money="1" value="' + esc((values[f.key] / 100).toFixed(2)) + '">' + help + '</div>';
    }

    return '<div class="cps-f"><div class="cps-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-cps-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '">' + help + '</div>';
  }

  function pickerHTML() {
    var ids = chosen.map(function (p) { return p.id; });

    var rows = results.length ? results.map(function (p) {
      var on = ids.indexOf(p.id) !== -1;
      return '<button type="button" class="cps-row" data-cps-pick="' + p.id + '"'
        + ' aria-selected="' + (on ? 'true' : 'false') + '">'
        + '<span class="cps-box">' + (on ? '&#10003;' : '') + '</span>'
        + '<span class="cps-nm"><b>' + esc(p.name) + '</b><span>' + esc(p.brand) + '</span></span>'
        + '</button>';
    }).join('') : '<p class="cps-empty">Nothing matches that.</p>';

    /*
     * THE CHOSEN LIST IS THE POINT OF THIS PANEL, so it is drawn FIRST and as a
     * list, not as chips under the results.
     *
     * The owner: "currently just check boxes green showing, which i can't find
     * in 1000s of products, the selected ones." He was right -- a tick inside a
     * search result you can only see while that exact search is on screen is
     * not an answer to "what is in the rail". Type a new term and the evidence
     * is gone.
     *
     * Each row carries its POSITION, because the rail draws them in this order
     * and that was previously only findable by reading the help text. Up and
     * down move a product; the first and last have theirs disabled rather than
     * hidden, so the buttons do not reflow as you use them.
     */
    var picked = chosen.length ? '<ol class="cps-picked">' + chosen.map(function (p, i) {
      var first = i === 0, last = i === chosen.length - 1;
      return '<li class="cps-pk">'
        + '<span class="cps-pos">' + (i + 1) + '</span>'
        + '<span class="cps-nm"><b>' + esc(p.name) + '</b><span>' + esc(p.brand) + '</span></span>'
        + '<span class="cps-mv">'
        + '<button type="button" data-cps-up="' + p.id + '"' + (first ? ' disabled' : '')
        + ' aria-label="Move ' + esc(p.name) + ' earlier">&uarr;</button>'
        + '<button type="button" data-cps-down="' + p.id + '"' + (last ? ' disabled' : '')
        + ' aria-label="Move ' + esc(p.name) + ' later">&darr;</button>'
        + '<button type="button" data-cps-drop="' + p.id + '" aria-label="Remove ' + esc(p.name) + '">&times;</button>'
        + '</span></li>';
    }).join('') + '</ol>'
      : '<p class="cps-help">Nothing chosen yet. The rail hides itself on the shop until something is.</p>';

    return '<div class="cps-card">'
      + '<div class="cps-title">Which products fill the rail</div>'
      + '<p class="cps-sub">Search below to add. The list here is the rail, left to right — '
      + 'use the arrows to reorder. Up to ' + maxRec + ' fit.</p>'
      + '<div class="cps-picked-wrap">'
      + '<div class="cps-picked-h">In the rail <b>' + chosen.length + '</b>'
      + (chosen.length >= maxRec ? '<em>full</em>' : '') + '</div>'
      + picked
      + '</div>'
      + '<div class="cps-fields">'
      + '<input class="cps-search" type="search" id="cps-q" placeholder="Search products to add…" autocomplete="off" value="' + esc(term) + '">'
      + '<div class="cps-list">' + rows + '</div>'
      + '</div></div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Cart page') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="cps-wrap"><div class="cps-card"><div class="cps-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="cps-wrap"><div class="cps-card">'
        + '<div class="cps-title">Cart page</div>'
        + '<p class="cps-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="cps-actions"><button class="cps-btn" data-cps-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="cps-tab" data-cps-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    var warn = String(values.layout) === 'classic'
      ? '<div class="cps-note">This shop is on the <b>classic</b> cart page, which is the page it '
        + 'has always rendered. Nothing else on this screen changes anything a shopper sees until '
        + 'the layout above is set to <b>Squeezed</b>. That is deliberate: applying the update that '
        + 'brought this screen changed the shop by nothing.</div>'
      : '';

    host.innerHTML = '<div class="cps-wrap"><div class="cps-col">'
      + (banner ? '<div class="cps-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + warn
      + '<div class="cps-card">'
      + '<div class="cps-tabs">' + strip + '</div>'
      + '<p class="cps-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="cps-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '</div>'
      + (open === 'rec' ? pickerHTML() : '')
      + '<div class="cps-actions">'
      + '<button class="cps-btn is-primary" data-cps-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="cps-btn" data-cps-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      + '</div></div>'
      + previewHTML()
      + '</div>';

    var q = document.querySelector('#cps-q');
    if (q) {
      q.focus();
      // focus() alone lands the caret at position 0 on a freshly created input,
      // so the next character types itself in front of the word.
      try { q.setSelectionRange(q.value.length, q.value.length); } catch (e) {}
    }
  }

  /* -------------------------------------------------------------- preview */
  /*
   * A LIVE DRAWING OF THE TAB YOU ARE ON.
   *
   * Only the region the open tab controls, not the whole cart. A phone frame
   * showing the top of the basket while you drag the docked-bar height is a
   * preview of nothing, and scrolling one to the right place is a second thing
   * that can be wrong.
   *
   * Every size is a calc() off the same custom properties the shop itself
   * reads -- --h for row height, --per for cards across, --ah/--ch for the two
   * bars -- so the drawing cannot disagree with the page by arithmetic. It is
   * still a DRAWING and not the real cart: rendering the storefront in here
   * would mean an authenticated fetch per keystroke.
   */
  var PV_ROWS = [
    {b:'COSRX', n:'Rice Probiotics Toner', q:1, p:'AED 115', c:'#f6a98a,#ef8a72'},
    {b:'SKIN1004', n:'Ceramide Daily Moisturiser', q:2, p:'AED 328', c:'#bfa6f2,#a387e8'}
  ];
  var PV_CARDS = ['#f5b8c8,#e99bb0', '#9fd8c4,#77c2a9', '#f2c08a,#e5a566', '#8fc9ee,#68afe0',
                  '#a8d5b8,#81bf96', '#e7a7b8,#d78598', '#f0ce8e,#e0b564'];

  function pvNum(key, fallback) {
    var v = Number(values[key]);
    return isFinite(v) ? v : fallback;
  }
  function pvOn(key) { return values[key] === true || values[key] === 1 || values[key] === '1'; }
  function pvText(key, fallback) {
    var v = values[key];
    return (v === undefined || v === null || v === '') ? fallback : String(v);
  }

  /* The custom properties, built once and handed to whichever region draws. */
  function pvVars() {
    var per = pvNum('rec_per', 45) / 10;
    return 'style="'
      + '--h:' + pvNum('row_h', 96) + 'px;'
      + '--f:' + (pvNum('row_font', 100) / 100) + ';'
      + '--w:' + (pvOn('row_bold') ? 600 : 400) + ';'
      + '--per:' + (per > 0 ? per : 4.5) + ';'
      + '--rw:' + (pvOn('rec_bold') ? 600 : 400) + ';'
      + '--rpw:' + (pvOn('rec_price_bold') ? 600 : 400) + ';'
      + '--rlh:' + (pvNum('rec_lh', 125) / 100) + ';'
      + '--rg:' + pvNum('rec_gap', 2) + 'px;'
      + '--rig:' + pvNum('rec_img_gap', 5) + 'px;'
      + '--ras:' + (pvNum('rec_add_size', 100) / 100) + ';'
      + '--rax:' + pvNum('rec_add_x', 0) + 'px;'
      + '--ray:' + pvNum('rec_add_y', 0) + 'px;'
      + '--mo:' + (pvNum('rec_motion', 1) || 0.0001) + ';'
      + '--ah:' + pvNum('addr_h', 40) + 'px;'
      + '--ch:' + pvNum('co_h', 62) + 'px;'
      + '--bf:' + (pvNum('bar_font', 100) / 100) + ';'
      + '--bp:' + pvNum('bar_pad', 0) + 'px;'
      + '--abf:' + (pvNum('addr_btn_font', 100) / 100) + ';'
      + '--ts:' + (pvNum('trust_size', 100) / 100) + ';'
      + '--sd:' + (pvNum('sheet_dense', 100) / 100) + ';'
      + '--sf:' + (pvNum('sheet_font', 100) / 100) + ';'
      + '--bl:' + pvNum('sheet_blur', 3) + ';'
      + '"';
  }

  function pvRows() {
    return PV_ROWS.map(function (r) {
      return '<div class="cpv-ci">'
        + '<div class="cpv-th" style="background:linear-gradient(140deg,' + r.c + ')">' + esc(r.b.charAt(0)) + '</div>'
        + '<div class="cpv-mid"><div class="cpv-br">' + esc(r.b) + '</div>'
        + '<div class="cpv-nm">' + esc(r.n) + '</div>'
        + '<div class="cpv-qty"><i>−</i><b>' + r.q + '</b><i>+</i></div></div>'
        + '<div class="cpv-pr">' + esc(r.p) + '</div></div>';
    }).join('');
  }

  function pvRail() {
    if (!pvOn('rec_on')) {
      return '<p class="cpv-note">The rail is switched off, so the shop draws nothing here.</p>';
    }
    var names = chosen.length
      ? chosen.map(function (p) { return p.name; })
      : ['PDRN Pink Peptide Serum', 'CER-100 Collagen Treatment', 'Collagen Night Mask',
         'PDRN Hyaluronic Mist', 'Gel Cleanser 150ml', 'Fino Shampoo Set', 'Relief Sun SPF50'];
    return '<section class="cpv-rec"><h6>' + esc(pvText('rec_heading', 'Recommended for you')) + '</h6>'
      + '<div class="cpv-rail">'
      + names.slice(0, 7).map(function (n, i) {
          return '<div class="cpv-rc"><div class="im" style="background:linear-gradient(140deg,'
            + PV_CARDS[i % PV_CARDS.length] + ')"><span class="pl">+</span></div>'
            + '<div class="t">' + esc(n) + '</div><div class="p">AED 88</div></div>';
        }).join('')
      + '</div></section>'
      + (chosen.length ? '' : '<p class="cpv-note">Example products — pick real ones below.</p>');
  }

  function pvSummary() {
    var money = function (f) { return 'AED ' + (Number(values[f] || 0) / 100).toFixed(2); };
    var out = '<div class="cpv-sum">'
      + '<div class="cpv-sr"><span>' + esc(pvText('sum_value_label', 'Order Value')) + '</span>'
      + '<span><s>AED 492</s><b>AED 443</b></span></div>';

    if (pvOn('sum_express_on')) {
      out += '<div class="cpv-sr"><span>' + esc(pvText('sum_express_label', 'Express Delivery Charge'))
        + ' ⓘ</span><span>' + money('sum_express') + '</span></div>';
    }
    if (pvOn('sum_delivery_on')) {
      out += '<div class="cpv-sr"><span>' + esc(pvText('sum_std_label', 'Standard Delivery Charge'))
        + ' ⓘ</span><span>' + esc(pvText('sum_std_free', 'Free')) + '</span></div>';
    } else {
      /* The free-delivery bar stands in for the delivery lines -- it answers
         the same question AND says what would make delivery free.
         ▲ MARKED AS AN EXAMPLE, deliberately. The threshold is not a cart-page
         setting: it comes from the shipping zone, and "no threshold" is a real
         answer, in which case the shop prints sum_fallback instead. Drawing a
         confident bar here would advertise free delivery in the admin that the
         shop may not offer. */
      out += '<div class="cpv-ship"><div class="t won">🎉 <b>You have unlocked free delivery!</b></div>'
        + '<div class="bar"><div class="fill" style="width:100%"></div></div></div>'
        + '<p class="cpv-note" style="padding:4px 0 0;text-align:left">Example — the threshold comes '
        + 'from your delivery zone. With none set, the shop prints “'
        + esc(pvText('sum_fallback', 'Delivery is calculated at checkout')) + '” here instead.</p>';
    }

    var fee = 0;
    if (pvOn('sum_service_on')) {
      fee = String(values.sum_service_mode) === 'pct'
        ? 443 * pvNum('sum_service_pct', 2) / 100
        : pvNum('sum_service', 300) / 100;
      out += '<div class="cpv-sr"><span>' + esc(pvText('sum_service_label', 'Service Fee'))
        + ' ⓘ</span><span>AED ' + fee.toFixed(2) + '</span></div>';
    }

    out += '<div class="cpv-tot"><span>' + esc(pvText('sum_total_label', 'Order Total')) + '</span>'
      + '<span>AED ' + (443 + fee).toFixed(2) + '</span></div></div>';

    if (pvOn('trust_on')) {
      var marks = [['pay_visa','VISA'],['pay_mc','MC'],['pay_apple','Pay'],['pay_google','GPay'],
                   ['pay_tabby','tabby'],['pay_tamara','tamara']]
        .filter(function (m) { return pvOn(m[0]); })
        .map(function (m) { return '<span class="cpv-pay">' + esc(m[1]) + '</span>'; }).join('');
      out += '<div class="cpv-trust"><span class="tick">✓</span> '
        + esc(pvText('trust_text', 'Secure checkout')) + ' <span>|</span> ' + marks + '</div>';
    }
    return out;
  }

  /* BOTH STATES OF THE ADDRESS ROW, one above the other.
     The row looks different before and after a shopper picks an address, and
     the difference is a setting on this very tab: the prompt carries no fade,
     the chosen address does. Drawing only the prompt — which is what this did —
     left the fade invisible in the admin and discoverable only on a phone,
     which is where it was reported from. `+ Address` and `Change address` are
     each on their own row too, so the button's size slider is judged against
     the longer of the two words. */
  function pvBars() {
    var rows = '';

    if (pvOn('addr_on')) {
      rows += '<div class="cpv-ab"><span class="who"><b>'
        + esc(pvText('addr_heading', 'Please choose your delivery address'))
        + '</b></span><span class="bt">' + esc(pvText('addr_btn_add', '+ Address')) + '</span></div>'
        + '<div class="cpv-ab has"><span class="who"><b>'
        + esc(pvText('addr_chosen', 'Delivering to {tag}').replace('{tag}', pvText('sheet_home', 'Home')))
        + '</b><i>Building 1-10, G-04 apartment, Al jhail gate phase 2 - Al Quoz - Dubai</i>'
        + '</span><span class="bt">' + esc(pvText('addr_btn_change', 'Change address')) + '</span></div>';
    }

    /* AED and the amount in one inline run, wrapped the way Money::format()
       wraps them, so the preview would show the two-line total if it ever came
       back. */
    return '<div class="cpv-dock">' + rows
      + '<div class="cpv-cb"><div class="ta"><i>3 items</i>'
      + '<b><span><span>AED</span> 1,443</span></b></div>'
      + '<button type="button">' + esc(pvText('co_label', 'Proceed to Checkout')) + '</button></div></div>'
      + (pvOn('addr_on')
          ? '<p class="cpv-note">The address row before and after a delivery address is chosen. '
            + 'The fade off the right belongs to a real address — the prompt is never faded.</p>'
          : '');
  }

  /* The popup, drawn at whichever of its two caps applies. `which` is 'list'
     or 'form' -- those caps are otherwise invisible until you open the sheet on
     a real phone, which is the worst place to find out you set them wrong. */
  function pvSheet(which) {
    var cap = which === 'list' ? pvNum('sheet_max_list', 38) : pvNum('sheet_max', 50);
    var inner;
    if (which === 'list') {
      inner = '<h6>' + esc(pvText('sheet_list_title', 'Choose location')) + '</h6>'
        + '<div class="cpv-al on"><span class="ad"><b>Zulfiqar Sha</b>'
        + '<i>Building 1-10, G-04 apartment, Al jhail gate phase 2 - Al Quoz - Dubai</i></span>'
        + '<span class="cpv-tag">' + esc(pvText('sheet_home', 'Home')) + '</span></div>'
        + '<div class="cpv-al"><span class="ad"><b>Zulfiqar Sha</b>'
        + '<i>Office 402, Boutique Tower 2 - Business Bay - Dubai</i></span>'
        + '<span class="cpv-tag">' + esc(pvText('sheet_office', 'Office')) + '</span></div>'
        /* THREE, because three is what the list can hold. A shopper who is not
           signed in keeps three addresses in their session, and a preview that
           draws two is a preview whose height caps were set against a list
           shorter than the real one — which is exactly the thing this preview
           exists to stop happening on somebody's phone. */
        + '<div class="cpv-al"><span class="ad"><b>Zulfiqar Sha</b>'
        + '<i>Villa 6, Street 14 - Al Barsha South - Dubai</i></span>'
        + '<span class="cpv-tag">' + esc(pvText('sheet_home', 'Home')) + '</span></div>'
        /* And the line that goes with a full list. Same condition as the live
           sheet: shown when a signed-out shopper already has three, because
           the next one replaces the oldest. */
        + '<p class="cpv-note">' + esc(pvText('sheet_guest_note',
            'We keep your 3 most recent addresses on this device. Adding another replaces the oldest.')) + '</p>'
        + '<button class="cpv-add" type="button">' + esc(pvText('sheet_add_new', '+ Add New Address')) + '</button>';
    } else {
      var two = pvOn('sheet_two_up') ? ' two' : '';
      inner = '<h6>' + esc(pvText('sheet_form_title', 'Add New Address')) + '</h6>'
        + '<div class="cpv-fg' + two + '">'
        + '<div class="full"><label>' + esc(pvText('sheet_area', 'Area')) + '</label>'
        + '<div class="cpv-fi">' + esc(pvText('sheet_area_hint', 'e.g. Jumeirah Village Circle')) + '</div></div>'
        + '<div class="full"><label>' + esc(pvText('sheet_apt', 'Apartment / building')) + '</label>'
        + '<div class="cpv-fi">' + esc(pvText('sheet_apt_hint', 'e.g. Flat 802, Sunrise Residence')) + '</div></div>'
        + '<div><label>' + esc(pvText('sheet_city', 'City')) + '</label>'
        + '<div class="cpv-fi">' + esc(pvText('sheet_city_hint', 'e.g. Sharjah')) + '</div></div>'
        + '<div><label>' + esc(pvText('sheet_country', 'Country')) + '</label>'
        + '<div class="cpv-fi" style="color:#17181c">United Arab Emirates</div></div>'
        + '<p class="cpv-geo full">✓ ' + esc(pvText('sheet_geo_note', 'Country set from where you are.')) + '</p>'
        + '</div>'
        + '<div class="cpv-act"><span class="cpv-mk on">⌂ ' + esc(pvText('sheet_home', 'Home')) + '</span>'
        + '<span class="cpv-mk">▤ ' + esc(pvText('sheet_office', 'Office')) + '</span>'
        + '<span class="cpv-dl">✓ ' + esc(pvText('sheet_save', 'Deliver here')) + '</span></div>';
    }
    return '<div class="cpv-stage"><div class="cpv-scrim"></div>'
      + '<div class="cpv-sheet" style="--cap:' + cap + '%">' + inner + '</div></div>';
  }

  /** What the open tab is responsible for, and nothing else. */
  function previewHTML() {
    var region, label;
    if (open === 'rows')        { region = pvRows(); label = 'Product rows'; }
    else if (open === 'rec')    { region = pvRail(); label = 'Recommended'; }
    else if (open === 'summary'){ region = pvSummary(); label = 'Summary & trust'; }
    else if (open === 'bars')   { region = pvBars(); label = 'Docked rows'; }
    else if (open === 'popup')  { region = pvSheet('list') + pvSheet('form'); label = 'Address popup · list, then form'; }
    else                        { region = pvRows() + pvRail() + pvSummary() + pvBars(); label = 'The whole page'; }

    var classic = String(values.layout) === 'classic';

    return '<div class="cpv" id="cps-preview">'
      + '<div class="cpv-h"><b>Live preview</b><span>' + esc(label) + '</span></div>'
      + '<div class="cpv-phone" ' + pvVars() + '>'
      + '<div class="cpv-bar"><i></i>extrabeauty.ae/cart/</div>'
      + '<div class="cpv-body"' + (open === 'popup' ? ' style="padding:0"' : '') + '>' + region + '</div>'
      + '</div>'
      + (classic
          ? '<p class="cpv-note" style="text-align:left;padding:8px 0 0">This is what <b>Squeezed</b> '
            + 'would draw. The shop is still on <b>Classic</b>, so nothing here is live yet.</p>'
          : '')
      + '</div>';
  }

  /* Redraw the preview WITHOUT touching the controls: re-rendering the whole
     screen mid-drag destroys the range input under the finger and the drag
     stops dead. */
  function paintPreview() {
    var node = document.querySelector('#cps-preview');
    if (!node) return;
    var holder = document.createElement('div');
    holder.innerHTML = previewHTML();
    node.replaceWith(holder.firstChild);
  }

  /* --------------------------------------------------------------- events */
  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-cps-key]');
    if (el) {
      var key = el.getAttribute('data-cps-key');
      if (el.type === 'checkbox') values[key] = el.checked;
      else if (el.hasAttribute('data-cps-money')) values[key] = Math.round(Number(el.value || 0) * 100);
      else if (el.type === 'range') values[key] = Number(el.value);
      else values[key] = el.value;

      // A select changes which OTHER fields belong on the screen — the two
      // service-fee amounts — so it redraws rather than only recording.
      if (el.tagName === 'SELECT') { render(); return; }

      var out = document.querySelector('[data-cps-val="' + key + '"]');
      if (out) {
        var f = null;
        tabs.forEach(function (t) {
          t.fields.forEach(function (x) { if (x.key === key) f = x; });
        });
        if (f) out.textContent = shown(f);
      }
      paintPreview();
      return;
    }

    if (e.target.id === 'cps-q') {
      // Debounced: the endpoint is throttled as well, because a debounce is a
      // promise the browser makes and the throttle is the one the server makes.
      clearTimeout(timer);
      var typed = e.target.value;
      term = typed;          // so a render() before the timer fires keeps it
      timer = setTimeout(function () { search(typed); }, 220);
    }
  });

  document.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-cps-tab]');
    if (tab) {
      open = tab.getAttribute('data-cps-tab');
      if (open === 'rec' && results.length === 0) search('');
      render();
      return;
    }

    var pick = e.target.closest('[data-cps-pick]');
    if (pick) {
      var id = Number(pick.getAttribute('data-cps-pick'));
      var at = chosen.map(function (p) { return p.id; }).indexOf(id);
      if (at !== -1) {
        chosen.splice(at, 1);
      } else if (chosen.length < maxRec) {
        var found = results.filter(function (p) { return p.id === id; })[0];
        if (found) chosen.push(found);
      } else {
        say('That is as many as the rail holds.');
      }
      render();
      return;
    }

    /* Reorder. The rail renders `chosen` in order, so moving a row here is the
       whole of it -- there is no separate sort field to keep in step. */
    var up = e.target.closest('[data-cps-up]');
    var down = e.target.closest('[data-cps-down]');
    if (up || down) {
      var moveId = Number((up || down).getAttribute(up ? 'data-cps-up' : 'data-cps-down'));
      var from = chosen.map(function (p) { return p.id; }).indexOf(moveId);
      var to = from + (up ? -1 : 1);
      if (from !== -1 && to >= 0 && to < chosen.length) {
        var moved = chosen.splice(from, 1)[0];
        chosen.splice(to, 0, moved);
        render();
      }
      return;
    }

    var drop = e.target.closest('[data-cps-drop]');
    if (drop) {
      var dropId = Number(drop.getAttribute('data-cps-drop'));
      chosen = chosen.filter(function (p) { return p.id !== dropId; });
      render();
      return;
    }

    if (e.target.closest('[data-cps-save]')) { save(); return; }
    if (e.target.closest('[data-cps-reload]')) { load(); return; }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
