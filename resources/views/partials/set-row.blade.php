{{--
 ═══════════════════════════════════════════════════════════════════════════════
  THE SET ROW — "Option A, the fanned stack", the design the owner chose.
  ▲ THIS FILE IS THE ONLY PLACE A SET IS DRAWN, INCLUDING THE POPUP MARKUP. ▲
 ═══════════════════════════════════════════════════════════════════════════════

    The owner, 28 September 2026:

      "A Fanned Stack is fine, for cart panel, cart page and checkout page
       please apply this design everywhere. and the thumnail we don't need any
       label on thubmail etc. and What's inside i need with a small on screen
       popup with products names list. a tiny popup box."

    So: under the product name, a row of OVERLAPPING CIRCULAR MEMBER
    THUMBNAILS — one per member, picture only — and beside them one button that
    opens a TINY POPUP listing the member names and quantities. Nothing else.

    ── WHERE THIS IS DRAWN ────────────────────────────────────────────────────

    One partial, included by every surface, so a later change to the row or the
    popup is a change to THIS FILE and nothing else:

      1. cart panel       resources/views/partials/cart-drawer.blade.php
      2. cart page        resources/views/store/cart-inner.blade.php
      3. checkout summary resources/views/partials/checkout/summary-items.blade.php
      4. browsed rail     resources/views/partials/cart-drawer.blade.php (2nd tab)
      5. account order    resources/views/store/account/order-detail.blade.php
                          and partials/checkout/received-line.blade.php

    The three the owner named are 1, 2 and 3. 4 and 5 were already drawing the
    same partial and keep doing so — he said to leave the browsed rail as it is,
    and an order page that drew a set differently from the basket it came from
    would be a second description of a set, which is the thing this file exists
    to prevent.

    THE CHECKOUT SUMMARY HAS NO STEPPER AND NO REMOVE CROSS and the circles and
    the popup still belong there: it is the surface on which a shopper decides
    whether to trust what they are paying for.

    The order-confirmation email and the printed invoice do NOT include this.
    They are table-layout documents — an email client strips most CSS and a PDF
    renderer has no flexbox or popovers — so they print
    App\Support\SetContents::lines(), the same member list this file draws, in
    one cell. A receipt and an invoice are documents, not screens.

    ── WHERE THE CONTENTS COME FROM ───────────────────────────────────────────

      $contents  the App\Support\SetContents array. From fromProduct() while the
                 set is being shopped, and from fromOrderItem() — the
                 `order_items.set_contents` SNAPSHOT — once it has been sold.
                 An order NEVER re-reads the pivot, or an old order's popup
                 would list today's contents. The two return the same shape, so
                 this file cannot tell them apart and cannot get it wrong.
      $surface   'drawer' | 'cart' | 'checkout' | 'browsed' | 'order'.
      $key       a string unique to this row on this page. It becomes the
                 popup's id and the button's aria-controls, so the two are
                 really associated for a screen reader. Supplied by the call
                 site from the cart-line or order-item id — deterministic, so
                 the page's bytes do not change between two identical renders.

    ── RULE 1: NO LABELS ON ANY THUMBNAIL ─────────────────────────────────────

    The member circles carry the member's picture and NOTHING else: no initials,
    no quantity badge, no count in the corner, no text overlay. Where a member
    has no picture the circle is the gradient this shop already uses for a
    pictureless product — Gradient::for($seed), exactly as cart-drawer.blade.php
    builds its own $thumb — and deliberately NOT Gradient::initials(), which is
    the label he asked not to see. The quantities are in the popup, where they
    are words rather than furniture.

    ── RULE 2: A REAL CONTROL, AND CSS DOES THE POSITIONING ───────────────────

    The opener is a <button type="button"> with aria-expanded and aria-controls,
    and the popup is its SIBLING — not a div with a click handler, and not a
    link away, and not an expanding panel that moves the rows under it.

    NOTHING BELOW MEASURES LAYOUT. CLAUDE.md forbids the element-measuring APIs
    and two tests enforce it by name, so the popup is placed with
    `position:absolute` off the positioned wrapper and sized with calc() and
    min(). There is no getBoundingClientRect(), no offsetWidth, no
    getComputedStyle and no scroll measurement anywhere in the script — it does
    three things: toggle a class, set aria-expanded, and close on Escape or an
    outside click.

    IT CANNOT WIDEN THE PAGE. The popup is `inset-inline-start:0` with
    `max-width:min(230px, calc(100vw - 32px))` and `box-sizing:border-box`, so at
    390px its right edge is inside the viewport whatever the row does, and
    `overflow-wrap:anywhere` keeps a long product name from pushing it out.
    Measured with the popup OPEN at both widths: document.documentElement.-
    scrollWidth is unchanged.

    ── ESCAPING ───────────────────────────────────────────────────────────────

    Every interpolation is escaped. A member name, a brand and an option label
    are settings, and CLAUDE.md rule 5 is that anything printed unescaped is a
    constant. The only {!! !!} below is Money::format(), which returns markup
    this application builds itself. An image URL goes through e() inside a
    style attribute exactly as the row around it does.

    ── THE STYLES AND THE SCRIPT ARE @once AND INLINE ─────────────────────────

    resources/css/kbb/kbb.css is compiled by Vite and package.json defines no
    `build` script, so a rule added there would not reach the server until
    somebody ran `npx vite build` by hand — a set that draws unstyled on the
    live shop is exactly the kind of half-shipped thing this project keeps
    finding. @once emits them for the first set on the page and for no other.
--}}
@php
    use App\Support\CssUrl;
    use App\Support\Gradient;
    use App\Support\Money;

    $kbbSetSurface = $surface ?? 'cart';
    $kbbSetMembers = $contents['members'] ?? [];
    $kbbSetSaving = (int) ($contents['saving'] ?? 0);
    $kbbSetCount = (int) ($contents['count'] ?? 0);
    // Bounded here rather than trusted from the call site: it goes into an id
    // and an aria-controls, and both are HTML attributes.
    $kbbSetKey = 'kset-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($key ?? $kbbSetSurface));
@endphp
@if ($kbbSetMembers !== [])
@once
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   THE FANNED STACK.

   Sized entirely from the Cart-panel tokens the row around it already reads
   (--cp-thumb, --cp-name and their -m twins), so Appearance → Cart panel →
   Mobile moves this with the row rather than leaving it behind. calc() and
   min() only — nothing here is measured in JavaScript.
   ═══════════════════════════════════════════════════════════════════════════ */
/* ── EVERY NUMBER BELOW IS `var(--kset-x, <the literal it has always been>)`.
   ────────────────────────────────────────────────────────────────────────
   NOTHING DECLARES THOSE PROPERTIES HERE. That is deliberate and it is what
   makes Appearance -> Set possible without a specificity fight: the owner's
   values are declared once, on `.kset`, by
   resources/views/partials/set-appearance-css.blade.php in the <head>, and
   every rule in this block simply reads them. A shop that has moved nothing
   emits no such block at all, so these fallbacks ARE the shop, byte for byte.

   ▲ TWO OF THE FALLBACKS ARE NEW VALUES AND NOT TODAY'S. `margin-top` was 6px
     and `margin-bottom` did not exist. See the note on .kset directly below --
     it is the one deliberate default move in this change and the owner asked
     for it in as many words. */
.kset{position:relative;display:flex;align-items:center;gap:var(--kset-gap,8px);
      margin-top:var(--kset-top,10px);margin-bottom:var(--kset-bot,6px);
      min-width:0;flex-wrap:wrap}
/* ▲ THE ONE DELIBERATE DEFAULT MOVE IN THIS FILE. (Lane SA)
   The owner, with a screenshot of the cart page and a red arrow at the set
   line: *"the only this i need is the row spacing i need little bit up spacing
   or give control for set rows too on backend for cart page."*

     margin-top     6px -> 10px   what he asked for, in as many words
     margin-bottom  0   ->  6px   MEASURED, not inferred from taste

   The second one is the defect behind the complaint. Measured in Chromium on
   the cart page before this change: the product name has 6px under it
   (.cn{margin:1px 0 6px}) and then the set block, and then the quantity
   stepper with NOTHING between them -- the fan sat flush against the control
   under it while every other pair of stacked things in that row had at least
   6px. `ksetBottomToRowBottom` read 41px at 390 and 42px at 1280, all of it
   stepper. There is no overlap anywhere: the gap from the divider above to the
   top of the name measured 27px at 390 and 28px at 1280 on a SET row and 27px
   and 28px on the PLAIN rows either side of it, which is the same number -- so
   what reads as a collision in the screenshot is a block with room above it
   and none below it, and moving only the top margin would have made that
   worse. Both numbers are also settings now, per breakpoint. */

/* The fan. Each circle overlaps the one before it by a little under half its
   width; the ring is what separates them. `direction` is untouched, and the
   negative margin is a LOGICAL property, so the fan reverses correctly in
   Arabic instead of stacking the wrong way. */
/* ── THE FAN WRAPS, AND THAT IS THE WHOLE OVERFLOW FIX ─────────────────────
   A nowrap fan's MIN-CONTENT is the sum of its circles, and a row's min-content
   is what the cart page's grid track grows to — measured below. Wrapping makes
   it ONE CIRCLE, so the row can always be as narrow as the screen and a fan too
   wide for its column falls onto a second line instead of pushing the page
   sideways. `row-gap` is the ring's own width, which is zero visual change while
   nothing wraps.

   THE PADDING AND THE MARGIN CANCEL, which is what makes this free. The overlap
   used to be `-.38d` on every circle BUT THE FIRST (`.kset-c + .kset-c`); a
   wrapped line's first circle is not the first child, so it would have been
   pulled 0.38d outside the fan's start edge. So the pull is now on EVERY circle
   and the fan carries `padding-inline-start:.38d` to absorb it. Arithmetic:
   before, width = d + .62d(n-1); after, .38d + n(d - .38d) = d + .62d(n-1) —
   the same number, and the first circle's start edge is in the same place.
   Both are logical properties, so Arabic mirrors from the same declaration. */
.kset-fan{display:flex;align-items:center;flex:0 1 auto;min-inline-size:0;
          flex-wrap:wrap;row-gap:var(--kset-ring,2px);
          /* THE PADDING IS DERIVED FROM THE OVERLAP, not from a copy of its
             shipped value. `--kset-lap` is negative, so `* -1` turns the pull
             into the space that absorbs it — and the two track each other when
             the owner moves the overlap slider. Written as `* 0.38` it did not:
             at an overlap of 0 the fan would have kept a 0.38d dead gutter at
             its start and the circles would have sat that far right of the
             words above them. */
          padding-inline-start:calc(var(--kset-dia) * -1 * var(--kset-lap,-.38))}
/* The diameter, named ONCE on the wrapper so the fan's padding and the circles
   themselves are the same number by construction. Declared here rather than
   emitted, because it is an expression over two other properties and not a
   value anybody sets. */
.kset{--kset-dia:calc(var(--cp-thumb,42px) * var(--kset-cf,.62))}
.kset-c{--kset-d:var(--kset-dia);
        width:var(--kset-d);height:auto;aspect-ratio:1;border-radius:50%;
        flex:0 1 auto;min-inline-size:0;
        background-size:cover;background-position:center;background-repeat:no-repeat;
        box-shadow:0 0 0 var(--kset-ring,2px) var(--kset-ringc,#fff),0 1px 2px rgba(0,0,0,.14)}
/* ▲ THE FAN COULD PUSH THE WHOLE PAGE SIDEWAYS, AND DID. (Lane SA)
   `flex:none` on both the fan and its circles with a fixed `--kset-d` makes N
   circles occupy d*(1 + 0.62*(N-1)) with no way to give any of it back, so the
   row's min-content width was the fan's width whatever the screen was.
   MEASURED on the cart page with a twelve-member set, before this change:

     viewport 320  document.documentElement.scrollWidth 381   fan 204px
     viewport 360  scrollWidth 381                            fan 204px
     viewport 390  scrollWidth 390                            fan 204px

   -- a horizontal scrollbar on the whole shop at 320 and 360, and 390 saved
   only by the coincidence that 12 circles at 26px come to 203.3px and the
   column is 203.6px. One more member, or one turn of the new circle-size
   slider, and 390 goes too.

   The answer is pure CSS and measures nothing: let them shrink. `flex:0 1 auto`
   with `min-inline-size:0` on the circles AND on the fan makes the fan
   compressible, and `height:auto` with `aspect-ratio:1` keeps a shrunken
   circle round instead of leaving it an ellipse -- which is what `height` fixed
   at `--kset-d` would have done. The overlap stays a proportion of the
   UNSHRUNKEN diameter, so a squeezed fan simply overlaps a little harder,
   which is what a fanned stack is for. */
/* EVERY circle, not `.kset-c + .kset-c` — see the note on .kset-fan above: the
   first circle of a WRAPPED line is not the first child, and the fan's own
   padding-inline-start is what the first one is pulled back into. */
.kset-c{margin-inline-start:calc(var(--kset-d) * var(--kset-lap,-.38))}

/* The opener. A real button, and small enough to sit on the same line as the
   fan at 390px without wrapping the row it lives in. */
.kset-btn{border:1px solid var(--kset-btnline,var(--line-2,#eadfe4));
          background:var(--kset-btnbg,#fff);cursor:pointer;
          border-radius:var(--kset-btnr,99px);
          padding:var(--kset-btnpy,3px) var(--kset-btnpx,9px);font:inherit;line-height:1.35;
          font-size:calc(var(--kset-base,var(--cp-name,12.5px)) * var(--kset-btnf,.82));
          font-weight:var(--kset-btnw,650);
          color:var(--kset-btnc,var(--cp-accent,#c9587f));white-space:nowrap;
          min-height:var(--kset-btnh,24px);flex:none}
.kset-btn:hover{background:var(--kset-btnhbg,#fff4f8)}
.kset-btn:focus-visible{outline:2px solid currentColor;outline-offset:2px}

/* The tiny popup. Absolute off .kset, which is positioned — so opening it moves
   nothing on the page and costs no layout below the row.

   THE WIDTH IS WHAT KEEPS 390px HONEST: min() against the viewport with the
   page gutter subtracted, and border-box so the padding is inside that figure.
   There is no measured position anywhere; `inset-inline-start:0` anchors it to
   the row's own start edge, which is inside the panel by construction. */
.kset-pop{display:none;position:absolute;z-index:40;
          inset-block-start:calc(100% + var(--kset-popoff,6px));
          inset-inline-start:0;box-sizing:border-box;
          width:max-content;max-width:min(var(--kset-popw,230px), calc(100vw - 32px));
          background:var(--kset-popbg,#fff);
          border:1px solid var(--kset-popline,var(--line-2,#eadfe4));
          border-radius:var(--kset-popr,10px);
          box-shadow:0 var(--kset-popshy,8px) var(--kset-popshb,24px)
                     calc(var(--kset-popshb,24px) / -3) rgba(0,0,0,var(--kset-popsha,.28));
          padding:var(--kset-poppy,8px) var(--kset-poppx,10px);
          /* ROOM FOR THE CLOSE BUTTON, on the devices that get one. It is an
             absolutely-positioned 28px target on the popup's own end corner,
             and a member name running under it is the defect this clears. The
             hover branch at the foot of this block takes it back off, because
             a pointer closes the popup by leaving it and the cross is not
             drawn there at all. Logical, so Arabic gets the room on the side
             the button actually lands on. */
          padding-inline-end:var(--kset-closepad,34px);
          text-align:start}
.kset-pop.is-open{display:block}
/* Opens UPWARD when the row asks for it, by a class the markup carries — never
   by a script that reads geometry. The order surfaces sit at the foot of a long
   page where downward is fine; the checkout summary's last line does not. */
.kset-pop.is-up{inset-block-start:auto;inset-block-end:calc(100% + var(--kset-popoff,6px))}
/*
 * ── THE BRIDGE, AND IT IS THE REASON HOVER IS USABLE AT ALL ────────────────
 *
 * The popup hangs `--kset-popoff` below the row, and that strip belongs to
 * neither element — so a pointer travelling from the button down into the
 * popup leaves `.kset`, the hover rule stops matching, and the box vanishes
 * under the cursor before it arrives. A transparent ::before spanning exactly
 * that strip is part of the popup, so the pointer never leaves. It is
 * `inset-inline` rather than left/right, so it mirrors on /ar, and it is
 * pointer-events-none where it would otherwise sit over the row's own
 * controls — it only has to be hoverable, never clickable.
 */
.kset-pop::before{content:"";position:absolute;inset-inline:0;
                  inset-block-start:calc(var(--kset-popoff,6px) * -1);
                  height:var(--kset-popoff,6px)}
.kset-pop.is-up::before{inset-block-start:auto;
                        inset-block-end:calc(var(--kset-popoff,6px) * -1)}
.kset-pop h4{margin:0 0 var(--kset-headgap,4px);
             font-size:calc(var(--cp-name,12.5px) * var(--kset-headf,.78));
             font-weight:var(--kset-headw,700);
             letter-spacing:var(--kset-headls,.03em);text-transform:uppercase;
             color:var(--kset-headc,var(--ink-soft,#8a7c83))}
.kset-pop ul{margin:0;padding:0;list-style:none}
.kset-pop li{font-size:calc(var(--kset-base,var(--cp-name,12.5px)) * var(--kset-lif,.88));
             font-weight:var(--kset-liw,400);
             line-height:var(--kset-lilh,1.45);
             color:var(--kset-lic,var(--ink-2,#5e545a));overflow-wrap:anywhere}
.kset-pop li + li{margin-top:var(--kset-ligap,2px)}
.kset-q{font-size:calc(100% * var(--kset-qf,1));font-weight:var(--kset-qw,700);
        color:var(--kset-qc,var(--ink,#2b2226))}
.kset-save{font-size:calc(var(--kset-base,var(--cp-name,12.5px)) * var(--kset-savef,.82));
           font-weight:var(--kset-savew,700);color:var(--kset-savec,#1c7a4a);
           white-space:nowrap;flex:none}

/* ── THE CLOSE CONTROL — "corner red cross icon inside the circle" ──────────
 *
 * The owner: *"and the tiny popup should be mouse hover to display on desktop,
 * and on mobile on-click with corner red cross icon inside the circle to close
 * the tiny popup."*
 *
 * A REAL <button type="button"> with an accessible name, not a glyph in a div:
 * it is operable from a keyboard, it is announced, and it is in the tab order
 * of the popup it closes. The name goes through __() like every other string on
 * this row, so Lane AR translates it rather than finding an English word baked
 * into a partial.
 *
 * POSITIONED WITH LOGICAL INSETS off the popup, so it lands on the top-right
 * corner in English and the top-LEFT in Arabic from the same declaration — no
 * [dir] selector in this file, which is the rule the rest of it already keeps.
 *
 * THE CIRCLE IS DRAWN AND THE TARGET IS PADDED OUT AROUND IT. The ring is sized
 * in calc() off the popup's own type token, so it tracks the box; the BUTTON is
 * a flat 28px so a thumb has something to hit even when the ring is smaller
 * than that. Growing the ring to 28px instead would have put a cross the size
 * of a member's name in the corner of a 230px box.
 *
 * THE COLOUR IS A CONSTANT. Rule 5: anything printed unescaped is a constant,
 * never a setting. There is no Appearance control for this red and there must
 * not be one — the cross is a system affordance, not decoration.
 */
.kset-close{position:absolute;inset-block-start:0;inset-inline-end:0;
            width:28px;height:28px;min-width:28px;min-height:28px;
            display:grid;place-items:center;padding:0;border:0;background:none;
            color:#D93025;cursor:pointer;z-index:1;line-height:0}
.kset-close::before{content:"";position:absolute;
                    width:calc(var(--cp-name,12.5px) * 1.34);
                    height:calc(var(--cp-name,12.5px) * 1.34);
                    border-radius:50%;border:1px solid currentColor}
.kset-close svg{position:relative;display:block;
                width:calc(var(--cp-name,12.5px) * .68);
                height:calc(var(--cp-name,12.5px) * .68);
                stroke:currentColor;stroke-width:2.6;fill:none;
                stroke-linecap:round}
.kset-close:focus-visible{outline:2px solid currentColor;outline-offset:1px}

/* ── HOVER OPENS IT, AND IT IS CSS AND NOT SCRIPT ──────────────────────────
 *
 * `hover:hover` AND `pointer:fine` together, never either alone: a touch device
 * that reports a coarse hover capability would otherwise be given a popup it
 * has no way to dismiss, because the finger that opened it has already left.
 *
 * HUNG OFF `.kset` AND NOT OFF THE BUTTON, so moving the pointer from the
 * opener into the popup does not close it half-read — the popup is a DESCENDANT
 * of `.kset`, so `.kset:hover` still matches while the pointer is inside it,
 * and the ::before above spans the gap between the two.
 *
 * `:focus-within` is the keyboard's half of the same rule: tabbing to the
 * opener shows the box without a keypress, exactly as the pointer does.
 *
 * A CLICK STILL TOGGLES. Hover is an ADDITION — `.is-open` is written by the
 * script below and honoured everywhere, including here, so a shopper who taps
 * a trackpad gets what they expect and `aria-expanded` stays the truth. The
 * close button is removed on this branch because the way out is to move the
 * pointer, and a cross that does nothing a shopper needs is furniture. */
@media (hover:hover) and (pointer:fine){
  .kset:hover > .kset-pop,.kset:focus-within > .kset-pop{display:block}
  .kset-close{display:none}
  .kset-pop{padding-inline-end:var(--kset-poppx,10px)}
}
@media (max-width:760px){
  /*
   * ONE DECLARATION INSTEAD OF THREE FONT-SIZES, and it says exactly what the
   * three said: the button, the popup's lines and the saving all measure
   * against the cart row's PHONE name token here, and the saving is a shade
   * larger than it is on a laptop (.85 against .82). The heading is
   * deliberately NOT in this list -- it has always measured against the
   * laptop token at every width, and restating it here would have changed
   * every phone in the shop.
   *
   * ▲ A SHOP THAT HAS MOVED A SLIDER GETS THESE FROM
   *   partials/set-appearance-css.blade.php INSTEAD, whose own media query
   *   uses the owner's breakpoint and whose selector is one class more
   *   specific than this one -- so his numbers win wherever his query matches
   *   and these are what a shop that has touched nothing renders.
   */
  .kset{--kset-base:var(--cp-name-m,13px);--kset-savef:.85}

  /* ▲ THE CHECKOUT'S SUMMARY CLIPS ITS OWN CHILDREN ON A PHONE, and the first
     shot of this screen proved it: `.kbb-checkout .panels` is
     `max-height:148px; overflow:hidden` below 760px, so the popup a shopper had
     just opened was sliced to one visible line.

     `position:fixed` is the only thing that escapes an overflow:hidden
     ancestor, and it needs no measurement at all: the box is pinned to the
     viewport's own bottom edge with logical insets, so where it lands is
     decided entirely by CSS. It cannot widen the page either -- a fixed element
     is laid out against the viewport, and the shots read
     document.documentElement.scrollWidth === 390 with it open.

     SCOPED TO THE CHECKOUT AND TO THE PHONE. The cart page does not clip and
     the desktop summary is not collapsed, so neither of them takes this branch
     and neither of them moves. It also cannot affect a shop with no sets: there
     is no .kset-pop on the page at all. */
  .kbb-checkout .kset-pop.is-open{
    position:fixed;inset-block-start:auto;inset-block-end:14px;
    inset-inline-start:14px;inset-inline-end:14px;
    width:auto;max-width:none;z-index:95}
  /* The hover bridge belongs to a box that hangs off a row. This one does not
     hang off anything -- it is pinned to the viewport -- so the strip would be
     a transparent band floating in the middle of the checkout. The close
     button comes WITH the popup automatically: it is absolute inside it, so it
     lands on the pinned box's own corner. (Lane SA) */
  .kbb-checkout .kset-pop.is-open::before{display:none}
}
</style>
<script>
/* What's inside — open, close, and nothing else.
 *
 * ▲ THIS SCRIPT MEASURES NOTHING. No getBoundingClientRect, no offsetWidth, no
 *   offsetHeight, no getComputedStyle, no scrollWidth. It toggles one class and
 *   writes aria-expanded. Where the box appears is decided entirely by the CSS
 *   above, which is the rule CLAUDE.md states and two tests in this repository
 *   enforce by name.
 *
 * ONE DELEGATED LISTENER on the document, not one per row. The cart panel
 * replaces its own markup on every add and every quantity change — the
 * .kc-fragment contract — so a listener bound to a button would be lost the
 * first time a shopper pressed +. Delegation survives that with nothing to
 * re-bind. It is installed once per page by Blade's once-directive (see the
 * top of this file) and guards on its own flag
 * as well, because the drawer fragment can arrive through innerHTML.
 */
(function(){
  if (window.__ksetPopupsReady) return;
  window.__ksetPopupsReady = true;

  function closeAll(except){
    document.querySelectorAll('.kset-pop.is-open').forEach(function(pop){
      if (pop === except) return;
      pop.classList.remove('is-open');
      var owner = document.querySelector('[aria-controls="' + pop.id + '"]');
      if (owner) owner.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function(e){
    /* THE CROSS. Checked before the opener, because it lives INSIDE the popup
       and the outside-click arm below would otherwise treat a press on it as
       "somebody is selecting a product name" and leave the box open — which is
       the whole defect the owner is asking to be given a way out of. */
    var close = e.target.closest ? e.target.closest('[data-kset-close]') : null;

    if (close) {
      var box = close.closest('.kset-pop');
      var opener = box ? document.querySelector('[aria-controls="' + box.id + '"]') : null;
      closeAll(null);
      /* Focus back to what opened it, exactly as Escape does. A press that
         leaves focus on a button that has just been removed from the page's
         reading order is a keyboard user dumped at the top of the document. */
      if (opener) opener.focus();
      return;
    }

    var btn = e.target.closest ? e.target.closest('[data-kset-toggle]') : null;

    if (!btn) {
      /* A press anywhere else closes them — unless it landed inside an open
         box, where a shopper may be selecting the text of a product name. */
      if (!(e.target.closest && e.target.closest('.kset-pop'))) closeAll(null);
      return;
    }

    var pop = document.getElementById(btn.getAttribute('aria-controls'));
    if (!pop) return;

    var open = !pop.classList.contains('is-open');
    closeAll(pop);
    pop.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  /*
   * ── aria-expanded HAS TO STAY TRUE WHILE CSS IS DOING THE OPENING ────────
   *
   * On a pointer device the popup is shown by `.kset:hover > .kset-pop` and no
   * script runs at all — so the button would keep saying aria-expanded="false"
   * over a box a sighted shopper can see, which is the one thing an assistive
   * technology reads to answer "is it open". These two listeners write that
   * attribute and NOTHING ELSE: they toggle no class, they do not open or close
   * anything, and they measure nothing. The CSS decides what is visible; this
   * only reports it.
   *
   * GATED ON THE SAME QUERY THE CSS IS GATED ON, through matchMedia, so a touch
   * device — where the hover rule never applies — is not told the popup is open
   * because a finger brushed past. `pointer:fine` is checked with `hover:hover`
   * for the reason the stylesheet gives: a coarse pointer that reports hover
   * would get an aria-expanded that never comes back.
   *
   * `mouseenter`/`mouseleave` are CAPTURED on the document rather than bound
   * per row: the cart panel replaces its own markup on every add and every
   * quantity change, so a listener bound to an element is a listener lost. They
   * do not bubble, which is exactly why the third argument is `true`.
   */
  var fine = window.matchMedia ? window.matchMedia('(hover:hover) and (pointer:fine)') : null;

  function reportHover(e, open){
    if (!fine || !fine.matches) return;
    var row = e.target && e.target.closest ? e.target.closest('.kset') : null;
    if (!row) return;
    var owner = row.querySelector('[data-kset-toggle]');
    if (!owner) return;
    /* A row whose popup the shopper CLICKED open stays reported open when the
       pointer wanders off it, because the click is what is still holding it
       there. */
    var box = document.getElementById(owner.getAttribute('aria-controls'));
    if (!open && box && box.classList.contains('is-open')) return;
    owner.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  document.addEventListener('mouseenter', function(e){ reportHover(e, true); }, true);
  document.addEventListener('mouseleave', function(e){ reportHover(e, false); }, true);

  document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    /* Focus goes back to the button that opened it, which is what a keyboard
       user expects and what stops Escape dumping focus at the top of the page. */
    var open = document.querySelector('.kset-pop.is-open');
    var owner = open ? document.querySelector('[aria-controls="' + open.id + '"]') : null;
    closeAll(null);
    if (owner) owner.focus();
  });
})();
</script>
@endonce
{{-- ONE BRANCH, ONE LIVE DESIGN. A future design is another branch here and a
     one-line change to App\Support\SetDesign — and nothing else in this
     repository moves, which is the property SetRowSurfacesTest protects by
     asserting WHERE this partial is included and never WHAT it draws.

     AN @if AND NOT AN @switch: Blade compiles @switch by requiring its FIRST
     @case or @default to follow it with nothing in between, and a Blade comment
     leaves the newline it sat on — so a switch with an explanation above its
     first case does not compile at all ("unexpected token <, expecting
     endswitch"). Found by running the suite, not by reading. --}}
@if (\App\Support\SetDesign::current() === \App\Support\SetDesign::FAN)
        <div class="kset">
            <div class="kset-fan" aria-hidden="true">{{-- aria-hidden: the circles are decoration. Every name they stand for is in the popup, which is the accessible answer, so a screen reader is not read a row of empty divs. --}}
                @foreach ($kbbSetMembers as $kbbSetMember)
                    <span class="kset-c" style="{{ ($kbbSetMemberCss = CssUrl::value($kbbSetMember['image'] ?? null)) !== '' ? "background-image:url('" . e($kbbSetMemberCss) . "')" : 'background:' . Gradient::for(($kbbSetMember['brand'] ?? '') . ($kbbSetMember['name'] ?? '')) }}"></span>{{-- NO LABEL OF ANY KIND on a member circle: no initials, no quantity badge, no overlay. The owner asked for the picture and nothing else, and Gradient::initials() is deliberately not called here even for a member with no picture -- that is the label he is asking not to see. --}}
                @endforeach
            </div>
            <button type="button" class="kset-btn" data-kset-toggle aria-expanded="false" aria-controls="{{ $kbbSetKey }}">{{ __('store.set.whats_inside') }}</button>
            <div class="kset-pop{{ in_array($kbbSetSurface, ['checkout', 'order'], true) ? ' is-up' : '' }}" id="{{ $kbbSetKey }}" role="group" aria-label="{{ __('store.set.whats_inside') }}">
                {{-- THE CLOSE CONTROL, and it is FIRST in the popup on purpose: a
                     keyboard user tabbing into the box meets the way out before
                     the list, and a screen reader announces it in the same
                     place. It is removed by CSS on a pointer device -- see the
                     hover branch in the sheet above -- so on a laptop this is
                     markup nobody ever reaches. --}}
                <button type="button" class="kset-close" data-kset-close aria-label="{{ __('store.set.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6 18 18M18 6 6 18"/></svg></button>
                <h4>{{ trans_choice('store.set.contents', $kbbSetCount, ['count' => $kbbSetCount]) }}</h4>
                <ul>
                    @foreach ($kbbSetMembers as $kbbSetMember)
                        <li><span class="kset-q">{{ (int) $kbbSetMember['quantity'] }}&times;</span> {{ $kbbSetMember['name'] }}@if (($kbbSetMember['variant'] ?? '') !== '') &middot; {{ $kbbSetMember['variant'] }}@endif</li>
                    @endforeach
                </ul>
            </div>
            @if ($kbbSetSaving > 0)<span class="kset-save">{{ __('store.set.saving', ['amount' => Money::plain($kbbSetSaving)]) }}</span>@endif
        </div>
@endif
@endif
