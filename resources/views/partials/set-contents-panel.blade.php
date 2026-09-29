{{--
 ═══════════════════════════════════════════════════════════════════════════════
  WHAT IS IN THIS SET — in the buy column, where the bulk strip used to be.
  (Lane SP, moved and rebuilt by Lane SF)
 ═══════════════════════════════════════════════════════════════════════════════

    The owner, with a marked-up screenshot — the "Choose your option / 1 unit /
    2-pack bundle / 3-pack bundle" block struck through with a red X, and an
    arrow drawn from the contents section far down the page UP into the space
    it occupied:

      "the Set product will not have bundle purchase, instead of that section,
       bring the What's inside there, and make it nice list, not grid! also the
       mobile screen will adjust that list nicely and display."

    So this is a SWAP and not two changes that happen to be in one release.
    App\Services\BundleService::forProduct() answers an empty list for a set,
    which empties that slot in store/product.blade.php's buy column, and this
    partial is included into the slot it empties. The space the strip freed is
    the space the list fills — which is what the arrow on the screenshot means.

    ── WHAT THIS REPLACED, AND WHAT WAS THROWN AWAY ──────────────────────────

    A grid: `repeat(auto-fill, minmax(min(100%, 150px), 1fr))`, five square
    tiles across, in a section of its own near the foot of the page. "not grid!"
    — so the grid is gone, and so are the three alternative designs and the
    Appearance screen that would have chosen between them. He chose. A picker
    over a decision already taken is a screen nobody opens and four drawings to
    keep working.

    ── A LIST, AND THE SAME LIST AT EVERY WIDTH ──────────────────────────────

    `.buybox` is 346px at a 390px viewport, 582px at 1280 and 778px at 1680 —
    measured in Chromium, not assumed. All three are narrow, so there is no
    width at which a grid of tiles is the right answer here and none at which
    this list has to become something else. One shape, three sizes of it:

        [ photo ] [ brand / name / option ] [ xN ]

    Three grid tracks, laid along the INLINE axis, so in an Arabic document the
    photograph is on the right and the quantity on the left from the same
    declaration — no [dir] selector in this file. Photographed on /ar at 390 and
    1280: dir="rtl", the photograph on the right of every row, and scrollWidth
    equal to the viewport. `minmax(0,1fr)` on the middle
    track and min-width:0 everywhere, because a grid item's default min-width is
    the width of its longest unbreakable word, and "Revive Eye Serum Ginseng
    Retinal 30ml" in a 346px column is how a page comes to scroll sideways.

    ── MOBILE IS DESIGNED, NOT INHERITED ─────────────────────────────────────

    He asked for it by name. Below 480px the photograph goes from 40 to 36, the
    row padding from 6 to 5, and the name from 13.5 to 13. Nothing is measured
    to decide it; it is one media query.

    ── THE SQUEEZE, AND WHAT IT COST. (Lane PP) ──────────────────────────────

      "the set products list, i want super squeeze, without pricing mentioned
       for each product inside the set."

    Two changes, and the second is what makes the first affordable. The price
    left the row (see partials/set-contents-row.blade.php), which freed the
    whole third track, so the quantity no longer has to stack under anything
    and the row's height is now set by the PHOTOGRAPH alone. That let the
    photograph come down 56 -> 40 and 48 -> 36 without the words ever being the
    thing that decides how tall a row is:

                          before          after
      photo, desktop       56px            40px
      photo, phone         48px            36px
      row padding          11px            6px   (desktop)
                            9px            5px   (phone)
      name                 14px            13.5px
      brand                11px            10px
      row height, desktop  78px            52px
      row height, phone    74px            46px

    LEGIBLE AND TAPPABLE IS THE FLOOR, not an afterthought. 13.5px is the size
    the option rows beside it already use, the brand at 10px is uppercase and
    tracked so it reads at that size, and a member's name is a LINK — so
    `a.ksl-nm` carries padding-block with an equal negative margin-block, which
    grows the hit area by 6px WITHOUT growing the row by a pixel. An inline
    box's padding does not contribute to its line box, so this is free height
    for a thumb and no height at all for the page.

    Nothing here is measured by script. Every number above is a constant in the
    sheet below, and the "after" column was read off Chromium.

    ── AND THE LIST CANNOT PUSH ADD TO CART OFF THE SCREEN ───────────────────

    This is the cost of the new position and the one thing the old one did not
    have. Twelve rows is about six hundred pixels sitting between the price and
    the button — it was seven hundred before the squeeze, which shortens the
    problem without solving it. So a box of more than six members shows the first five and
    folds the rest into a <details>:

      - HTML's own disclosure, so it is keyboard-operable, reachable by the
        browser's own find-in-page, and present in the markup for a crawler
        that runs no script;
      - NOT ONE LINE OF JAVASCRIPT, which is the rule the rest of this project
        sizes by. The summary carries both labels and CSS swaps them on
        `details[open]`;
      - the honest figures below the list count EVERY member, folded or not,
        because they come from App\Support\SetContents and never from what
        happens to be on screen.

    ── THERE IS STILL EXACTLY ONE DESCRIPTION OF A SET'S CONTENTS ────────────

    Every figure here comes out of SetContents::fromProduct() — the same array
    the cart row, the checkout summary, the invoice and the order email read.
    Nothing re-reads the pivot, re-adds the parts total or re-derives the
    saving. `url` and `visible` are keys on that array rather than decisions
    made here, and neither reaches an order snapshot or /api/*.

    ── THE HEADING IS A LABEL NOW, NOT AN <h2> ───────────────────────────────

    The old placement had an eyebrow ("The set") and an <h2> ("What is in this
    set") because it was a SECTION of the document. In the buy column it is
    not one: an <h2> between the price and the Add to cart button claims a
    major division of the page in the document outline that a 346px column has
    not earned, and a screen reader announces it as one. So it is the same
    `.opt-label` line the block it replaces used — the words, then the count in
    the note span, exactly where "Choose your option / Save more with bundles"
    sat. Same slot, same typography, same job.

    `store.set.page_eyebrow` is left in InterfaceStrings rather than deleted:
    it is a translated key and the Arabic against it may already be typed.

    ── NOTHING HERE MEASURES LAYOUT AND NOTHING HERE IS JAVASCRIPT ───────────

    No getBoundingClientRect, no offsetWidth, no ResizeObserver. Every number
    below is a constant, a calc() or a media query.

    ── ESCAPING ──────────────────────────────────────────────────────────────

    Every interpolation is escaped. A member name, a brand and an option label
    are settings. The only {!! !!} is Money::format(), which returns markup this
    application builds itself. The link's href is Product::url(), built from the
    slug by App\Support\Url — never a value out of the settings table, so there
    is no scheme to check.

    ── THE STYLE BLOCK IS @once AND INLINE ───────────────────────────────────

    resources/css/kbb/kbb.css is compiled by Vite, package.json defines no
    `build` script, and a rule added there would not reach the server until
    somebody ran `npx vite build` by hand.

    ── AND IT COSTS NO QUERY ─────────────────────────────────────────────────

    Store\ProductController::show() calls SetEagerLoad::on([$product]), which
    runs NOTHING AT ALL when the product is not a set — which is every product
    in this catalogue but the sets — and three batched queries when it is,
    whether the box holds three members or thirty. SetProductPageTest measures
    the flatness rather than asserting it.
--}}
@php
    use App\Support\Money;
    use App\Support\SetContents;

    /*
     * ONE READ OF THE APPEARANCE SETTINGS PER PAGE, not one per row and not one
     * per call site. all() walks the whole schema, and the panel, its class
     * list, the fold and every row's link switch all want the same answer —
     * three calls to get() would be three walks. It is handed down to
     * partials/set-contents-row.blade.php as $kbbSetAp for the same reason.
     *
     * It costs no query: every key is a row of `settings`, read through the one
     * Setting::map() snapshot the header and the cart panel have already warmed
     * on this request.                                              (Lane SA)
     */
    $kbbSetAp = app(\App\Services\SetAppearance::class)->all();

    /*
     * ▲ `$kbbSetPreviewContents` IS THE ADMIN PREVIEW'S DOOR AND NOTHING ELSE.
     *   Appearance → Set has sixty-odd controls for this list and, until this
     *   release, a live preview that drew only the cart's set BOX — so the
     *   owner dragged the panel's fill, its four paddings and the overhang
     *   against a frame that could not show any of them. The preview includes
     *   THIS partial rather than a second copy of it, for the reason
     *   SetAppearanceApiController's docblock gives about the box: a copy
     *   disagrees with the shop the first time either is touched.
     *
     *   It is checked with isset() BEFORE `$product`, which is deliberate:
     *   `$product` does not exist in the preview at all, and PHP evaluates a
     *   ternary left to right, so the storefront branch is never reached there
     *   and the preview never touches the database for a set it was handed.
     *   On the product page the variable is not defined and this reads exactly
     *   as it did — `p_on` first, so a list the owner has switched off does not
     *   do SetContents::fromProduct()'s work and then throw the answer away.
     */
    $kbbSetPage = ! $kbbSetAp['p_on']
        ? SetContents::NONE
        : (isset($kbbSetPreviewContents)
            ? $kbbSetPreviewContents
            : ($product->isSet() ? SetContents::fromProduct($product) : SetContents::NONE));
    /* ▲ `p_on` IS TESTED HERE AND NOT AS A WRAPPER `@if` FURTHER DOWN, which is
         not tidiness: SetContents::fromProduct() is the work, and a panel the
         owner has switched off should not do it and then throw the answer away.
         It also means the switch returns the SAME shape the "this is not a set"
         branch already returns, so there is exactly one empty case below rather
         than two. */

    /*
     * HOW MANY ROWS STAND BEFORE THE FOLD.
     *
     * Five, and the number lives on the SERVER rather than in the CSS because
     * the fold is a server decision: the rows past it are inside a <details> in
     * the markup, which is what lets the disclosure work with no script at all.
     * Five rows is about 370px of list, which leaves the Add to cart button on
     * a 667px phone screen with the price still above it.
     *
     * IT IS A SETTING NOW — Appearance -> Set -> Desktop -> "Show this many
     * before folding" — and it SHIPS AT FIVE, so a shop that applies the
     * package folds exactly where it folded yesterday. Zero from
     * SetAppearance::foldAt() means the owner turned the fold off, and then the
     * whole list stands however long it is; that is why the `> $kbbSetFold + 1`
     * test is guarded rather than left to arithmetic on a zero.
     *
     * A box of exactly six would fold ONE row, which is a disclosure that
     * saves nothing and costs a click -- so the fold applies from seven
     * upward and a six-member box is drawn whole.
     */
    $kbbSetFold = \App\Services\SetAppearance::foldAt($kbbSetAp);
    $kbbSetRows = $kbbSetPage['members'];
    $kbbSetFolds = $kbbSetFold > 0 && count($kbbSetRows) > $kbbSetFold + 1;
    $kbbSetShown = $kbbSetFolds ? array_slice($kbbSetRows, 0, $kbbSetFold) : $kbbSetRows;
    $kbbSetHidden = $kbbSetFolds ? array_slice($kbbSetRows, $kbbSetFold) : [];
@endphp
@if ($kbbSetPage['members'] !== [])
@once
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   WHAT IS IN THIS SET — the buy column's list. calc(), min() and one media
   query; nothing measured, no script.
   ═══════════════════════════════════════════════════════════════════════════ */
/* ── EVERY NUMBER BELOW IS `var(--ksl-x, <THE SHIPPED DEFAULT OF ITS CONTROL>)`.
   ────────────────────────────────────────────────────────────────────────
   NOTHING DECLARES THOSE PROPERTIES HERE, which is what makes Appearance ->
   Set possible without a specificity fight: the owner's values are declared
   once, on `.ksl`, by resources/views/partials/set-appearance-css.blade.php in
   the <head>, and every rule below simply reads them. A shop that has moved
   nothing emits no such block at all, so these fallbacks ARE the shop, byte
   for byte, and every one of them is the number that was here before.
                                                                  (Lane SA)

   ▲ AND THERE IS EXACTLY ONE FALLBACK PER PROPERTY IN THIS FILE, which is a
     rule and not an accident. Five of the numbers below used to be the BARE
     list's originals (a 40px photograph, 6px of row padding) while the panel
     section further down carried the box's own (36px, 3px) — two literals for
     one custom property, decided by source order, and the schema's shipped
     default agreeing with neither reading of the file. They are the panel's
     now, which is what the shop draws; the bare list is only reachable by
     saving `p_panel_on = false`, and a saved setting emits the property anyway,
     so nothing renders differently. SetAppearanceTest walks every
     `var(--ksl-…,…)` in this file and fails if one property carries two
     different fallbacks, or if a fallback and its control's shipped default
     disagree.                                                     (Lane SA2) */
.ksl{display:block;min-width:0;margin:0 0 var(--ksl-block,14px)}
.ksl-rows{display:block;min-width:0}
/* The heading. `.opt-label` is the product page's own class -- it is the slot
   the quantity-bundle strip used -- so these are scoped INSIDE .ksl at (0,2,0)
   and change nothing anywhere else on the page that uses it. */
.ksl .opt-label{font-size:var(--ksl-headf,12.5px);font-weight:var(--ksl-headw,700);
                color:var(--ksl-headc,inherit);margin-bottom:var(--ksl-headgap,7px)}
.ksl-nocount .opt-label span{display:none}

/* The row. Photograph, words, money — three tracks along the inline axis, so
   Arabic mirrors without a second rule. */
.ksl-r{display:grid;gap:0 var(--ksl-gap,12px);min-width:0;align-items:center;
       padding:var(--ksl-rowpad,3px) 0;
       grid-template-columns:var(--ksl-ph,36px) minmax(0,1fr) auto}
.ksl-r + .ksl-r{border-block-start:1px solid var(--ksl-linec,var(--line,rgba(42,34,40,.10)))}
/* The three track layouts the on/off switches produce. A custom property can
   change a number inside a rule; it cannot take a track out of a grid, so
   these are classes — put on `.ksl` by App\Services\SetAppearance::panelClass()
   and therefore present from the first byte, so nothing reflows after paint. */
.ksl-noph .ksl-r{grid-template-columns:minmax(0,1fr) auto}
.ksl-noq .ksl-r{grid-template-columns:var(--ksl-ph,36px) minmax(0,1fr)}
.ksl-noph.ksl-noq .ksl-r{grid-template-columns:minmax(0,1fr)}
.ksl-noph .ksl-ph,.ksl-nobr .ksl-br,.ksl-novar .ksl-var,.ksl-noq .ksl-q{display:none}
.ksl-norule .ksl-r + .ksl-r,.ksl-norule .ksl-more .ksl-r:first-of-type,
.ksl-norule .ksl-more > summary{border-block-start:0}
.ksl-noul a.ksl-nm{border-bottom:0}
.ksl-nofoot .ksl-foot,.ksl-nowas .ksl-was,.ksl-noprice .ksl-price,
.ksl-nosave .ksl-save{display:none}
/* (0,3,0), so it beats BOTH the bare footing rule and the panel's own. */
.ksl.ksl-nofootrule .ksl-foot{border-top:0}

/* A fixed square, so every name in the column starts at the same place --
   which is the whole reason to draw a list rather than a grid. */
.ksl-ph{position:relative;display:block;width:var(--ksl-ph,36px);height:var(--ksl-ph,36px);
        border-radius:var(--ksl-phr,10px);overflow:hidden;
        background:var(--ksl-phbg,var(--line-2,rgba(42,34,40,.06)))}
.ksl-ph img{width:100%;height:100%;object-fit:cover;display:block}
.ksl-ph.is-blank{background-size:cover;background-position:center}

/* align-items:flex-start, and it is load-bearing rather than tidy. The name is
   an <a> with a bottom border, and a flex child in a column stretches to the
   full cross size by DEFAULT -- so the underline ran the whole width of the
   words column and sat under the empty space past the end of the name. Caught
   in Chromium at 1280 and at 390; `flex-start` is the logical value, so it is
   the right-hand edge in Arabic from the same declaration. */
.ksl-w{min-width:0;display:flex;flex-direction:column;align-items:flex-start;
       gap:var(--ksl-wgap,1px);text-align:start}
.ksl-br{font-size:var(--ksl-br,10px);letter-spacing:var(--ksl-brls,.04em);
        text-transform:uppercase;font-weight:var(--ksl-brw,700);
        color:var(--ksl-brc,var(--ink-2,#5E545A));opacity:var(--ksl-brop,.72);
        overflow-wrap:anywhere;line-height:var(--ksl-brlh,1.3)}
/* THREE LEADINGS AND NOT ONE. They were one property until the panel shipped,
   and the panel's phone block set the name to 1.24 and the brand to 1.15 while
   leaving the option on 1.3 -- so a single control would have had to ship
   already disagreeing with two of the three elements it claimed to drive. */
.ksl-nm{font-size:var(--ksl-nm,13.5px);line-height:var(--ksl-nmlh,1.3);
        font-weight:var(--ksl-nmw,640);color:var(--ksl-nmc,var(--ink,#2A2228));
        overflow-wrap:anywhere}
/* A REAL LINK, so it is a link for a keyboard and for a crawler as well as for
   a mouse. A member that is not published is the same words without an <a> --
   see SetContents::memberIsLive(): a href to a draft is a 404 on the one page
   a shopper reached from Google. */
/* padding-block with an equal NEGATIVE margin-block: an inline box's vertical
   padding does not enter its line box, so this is 6px more hit area for a thumb
   and zero extra row height. Measured in Chromium at 390 and 1280 -- the row is
   the same height with it and without it. */
a.ksl-nm{color:inherit;text-decoration:none;
         border-bottom:1px solid var(--ksl-linec,var(--line,rgba(42,34,40,.10)));
         padding-block:3px;margin-block:-3px}
a.ksl-nm:hover{border-bottom-color:currentColor}
a.ksl-nm:focus-visible{outline:2px solid currentColor;outline-offset:2px}
.ksl-var{font-size:var(--ksl-var,11.5px);line-height:var(--ksl-lh,1.3);
         color:var(--ksl-varc,var(--ink-2,#5E545A));overflow-wrap:anywhere}

/* The third track is the quantity ALONE now. The price span and the flex column
   that wrapped it went with the price -- a column around a single child, and a
   rule for an element that is no longer emitted.
   (Their class names are deliberately not written here: this block is emitted
   INTO the page, so a class name in this comment is a string in the HTML, and
   the case that asserts no member carries a price searches the HTML for it.) */
.ksl-q{font-size:var(--ksl-q,12px);font-weight:var(--ksl-qw,700);
       color:var(--ksl-qc,var(--ink,#2A2228));white-space:nowrap;text-align:end}

/* ── THE DISCLOSURE ──────────────────────────────────────────────────────
   HTML's own, so there is no script. Both labels are in the markup and this
   swaps them; the default marker is removed because every browser draws a
   different triangle and the row already reads as a control. */
.ksl-more{display:block;min-width:0}
.ksl-more > summary{list-style:none;cursor:pointer;display:block;min-width:0;
  padding:var(--ksl-morept,8px) 0 var(--ksl-morepb,4px);
  font-size:var(--ksl-more,12.5px);font-weight:var(--ksl-morew,700);
  color:var(--ksl-morec,var(--ink,#2A2228));
  text-align:start;border-block-start:1px solid var(--ksl-linec,var(--line,rgba(42,34,40,.10)))}
.ksl-more > summary::-webkit-details-marker{display:none}
.ksl-more > summary:focus-visible{outline:2px solid currentColor;outline-offset:2px}
.ksl-more > summary span{border-bottom:1px solid var(--ksl-linec,var(--line,rgba(42,34,40,.10)))}
.ksl-more > summary .ksl-less{display:none}
.ksl-more[open] > summary .ksl-less{display:inline}
.ksl-more[open] > summary .ksl-all{display:none}
/* The first folded row carries its own hairline, so it is separated from the
   summary the way every other row is separated from the one above it. */
.ksl-more .ksl-r:first-of-type{border-block-start:1px solid var(--ksl-linec,var(--line,rgba(42,34,40,.10)))}

/* The footing. Bought separately, the set's own price, and the saving -- the
   same three integers SetContents already computed, printed rather than
   recomputed, and counting every member whether or not it is folded away. */
.ksl-foot{display:flex;flex-wrap:wrap;gap:var(--ksl-footgy,5px) var(--ksl-footgx,16px);
          align-items:baseline;min-width:0;
          margin-top:var(--ksl-footsp,9px);padding-top:var(--ksl-footsp,9px);
          border-top:1px solid var(--ksl-linec,var(--line,rgba(42,34,40,.10)))}
.ksl-f{display:flex;gap:6px;align-items:baseline;min-width:0;font-size:var(--ksl-foot,13px);
       color:var(--ksl-footc,var(--ink-2,#5E545A))}
.ksl-f b{font-weight:var(--ksl-footw,700);color:var(--ksl-footbc,var(--ink,#2A2228));white-space:nowrap}
.ksl-was b{font-weight:var(--ksl-wasw,600);text-decoration:line-through;
           color:var(--ksl-footc,var(--ink-2,#5E545A))}
.ksl-save{font-size:var(--ksl-foot,13px);font-weight:var(--ksl-savew,700);
          color:var(--ksl-savec,#1c7a4a);white-space:nowrap}

/* ── THE PHONE ───────────────────────────────────────────────────────────
   "also the mobile screen will adjust that list nicely and display."

   The photograph comes down 8px, the row padding 2, the name half a step --
   and the quantity and the price stop stacking. */
/*
 * ONE DECLARATION BLOCK INSTEAD OF FOUR RULES, and it says exactly what the
 * four said: below 480px the photograph comes down to 36, its radius to 7, the
 * row padding to 5, the column gap to 9, the name to 13 and the footing's gaps
 * to 4 and 12. Written as custom properties rather than as properties so that
 * the `.ksl-noph` / `.ksl-noq` track rules above keep working at both widths
 * from a single declaration instead of needing a phone copy of each.
 *
 * ▲ A SHOP THAT HAS MOVED A SLIDER GETS THESE FROM
 *   partials/set-appearance-css.blade.php INSTEAD, whose media query uses the
 *   owner's own breakpoint and whose selector is one class more specific than
 *   this one — so his numbers win wherever his query matches, and these are
 *   what a shop that has touched nothing renders.                  (Lane SA)
 */
/* The phone's numbers are declared ONCE now, in the block at the foot of this
   style element — see the note there. Two @media blocks declaring the same
   custom properties is how the panel's phone sizes and the bare list's came to
   disagree about the photograph and the row padding in the first place. */

/* ═══════════════════════════════════════════════════════════════════════════
   THE BOX — "Hanging photos", the treatment the owner chose, 29 September.

     "i want to redesign the products list box on the set product page. want
      nice light background box type and inside a squeezed products list."
     "ok the hanging photos style is fine. please proceed with that"

   Appended whole from Lane SPL's tools/spl-box/t3-hanging-photos.css, which is
   deleted in the same commit: three treatments were drawn, one was chosen, and
   a shipped design does not need the two it beat kept beside it. The rules
   below OVERRIDE the bare-list rules above rather than replacing them, so the
   list's structure, its fold, its escaping and its query cost are untouched and
   this is a change of drawing only.
   ═══════════════════════════════════════════════════════════════════════════ */
/* ── THE OBJECT ─────────────────────────────────────────────────────────── */
/* ▲ EVERY RULE BELOW IS SCOPED TO `.ksl-panel`, which is what the owner's
     "Draw the list inside a panel" switch puts on or leaves off.
     App\Services\SetAppearance::panelClass() writes it, so it is on the element
     from the first byte and nothing reflows after paint. OFF is not nine
     declarations undone — it is nine rules that do not select, and the bare
     list above stands exactly as it was written.                 (Lane SA2) */
/* ▲ AND EVERY NUMBER IS `var(--ksl-x, <the literal the box shipped with>)`,
     for the same reason the bare list's are: nothing declares these properties
     here, App\Services\SetAppearance declares them on `.ksl.ksl` once the owner
     has moved something, and a shop that has moved nothing emits no block at
     all — so these fallbacks ARE the box, pixel for pixel. Until this release
     the whole of this section was literals, which meant the panel silently
     OVERRODE thirteen of the controls Appearance → Set had just shipped: the
     photograph's size and radius and backing, the row's padding and gap, the
     hairline between rows, the disclosure's padding and colour, the footing's
     spacing and its rule. They were sliders that moved nothing. */
.ksl-panel{
  background:var(--ksl-pbg,var(--pink-soft,#FFF0F4));
  border-radius:var(--ksl-pr,18px);
  /* ── FOUR LOGICAL PADDINGS, AND THE SHORTHAND WAS A BUG THIS BOX SHIPPED.
     ──────────────────────────────────────────────────────────────────────
     It was `padding:12px 14px 11px 20px`. A `padding` shorthand is PHYSICAL:
     its fourth value is the LEFT edge in every language. The chips' pull and
     the footing's pull-back are both `*-inline-start`, so in English the three
     agreed and on /ar they did not — inline-start is the right-hand edge there,
     where the panel's padding was 14 and not 20.

     Measured in Chromium before the fix, three-member set, /ar/product/…:

                                 1280        390
       chips hang past panel      16px       17px     (10px is the design)
       footing past panel          6px        7px     (0 is the design)

     The footing's rule and all three money figures stuck out THROUGH the blush
     panel's own edge — it is visible in docs/lane-sa2-shots/before-ar-product-
     set-1280.jpg. The RTL guard did not catch it because `padding` is not in
     CssDirection::MAP: only the longhands are, and a shorthand hides four of
     them. Four logical longhands make the inline-start inset one value that
     both pulls read, so the three cannot disagree in either direction. */
  padding-block-start:var(--ksl-ppt,12px);
  padding-block-end:var(--ksl-ppb,11px);
  padding-inline-end:var(--ksl-ppe,14px);
  padding-inline-start:var(--ksl-pps,20px);
}

/* ── THE SQUEEZE ────────────────────────────────────────────────────────── */
.ksl-panel .ksl-r{
  grid-template-columns:var(--ksl-ph,36px) minmax(0,1fr) auto;
  gap:0 var(--ksl-gap,12px);
  padding:var(--ksl-rowpad,3px) 0;
}
/* The three track layouts again, one class more specific, because the rule
   above is (0,2,0) and so are `.ksl-noph .ksl-r` and `.ksl-noq .ksl-r` — and
   this one comes LATER in the file, so without these three the photograph's
   track would come back the moment the owner switched the photograph off.
   MUTATION: delete the `.ksl-panel.ksl-noph .ksl-r` line and the case in
   SetAppearanceTest that renders the panel with the photograph off goes red on
   a 36px empty column. */
.ksl-panel.ksl-noph .ksl-r{grid-template-columns:minmax(0,1fr) auto}
.ksl-panel.ksl-noq .ksl-r{grid-template-columns:var(--ksl-ph,36px) minmax(0,1fr)}
.ksl-panel.ksl-noph.ksl-noq .ksl-r{grid-template-columns:minmax(0,1fr)}

/* ── THE HANG ───────────────────────────────────────────────────────────── */
.ksl-panel .ksl-ph{
  width:var(--ksl-ph,36px);height:var(--ksl-ph,36px);
  border-radius:var(--ksl-phr,10px);
  /* ▲ THE CONTROL IS THE OVERHANG AND THE PULL IS ARITHMETIC ON IT.
       `padding + overhang`, never a raw pull, so the chip stands outside the
       panel by exactly `--ksl-over` whatever the padding is set to — equal
       would be a gutter and less would be an indent, and both are one drag
       away if a slider sets the pull directly. The overhang's floor is 1px in
       App\Services\SetAppearance and that is the whole of the guarantee: there
       is no pair of values these two sliders can take that makes this
       expression smaller than the padding it is measured from.
       SetContentsBoxTreatmentsTest pins `pull > padding` on this rule and
       SetAppearanceTest pins it at both ends of both sliders' ranges. */
  margin-inline-start:calc(-1 * (var(--ksl-pps,20px) + var(--ksl-over,10px)));
  background:var(--ksl-phbg,#fff);
  /* A ring and a small drop, so the chip reads as sitting ON the blush rather
     than cut out of it. The ring is a spread shadow and not a border: a border
     would grow the square and push the words along. */
  box-shadow:0 0 0 var(--ksl-ring,3px) var(--ksl-ringc,#fff),
             0 var(--ksl-shy,2px) var(--ksl-shb,6px) -1px rgba(42,34,40,var(--ksl-sha,.22));
}

/* NO ROW RULES — and it is the `p_rule_on` SETTING that says so now, shipped
   OFF, rather than the `border-block-start:0` that used to sit here and made
   that control a no-op. `ksl-norule` above does the work, so turning the
   hairlines back on from the admin actually turns them on. */

/* ── THE DISCLOSURE ─────────────────────────────────────────────────────── */
/* The summary is NOT a row and must not hang: it carries no negative margin at
   all, so it starts at the panel's own inline-start padding and lines up with
   the names rather than with the chips. The chips are the only thing in this
   treatment that crosses the panel's edge — one exception, stated once. */
.ksl-panel .ksl-more > summary{
  padding:var(--ksl-morept,8px) 0 var(--ksl-morepb,4px);
  color:var(--ksl-morec,var(--pink-deep,#C13E63));
}
.ksl-panel .ksl-more > summary span{border-bottom-color:currentColor;opacity:.85}

/* ── THE FOOTING ────────────────────────────────────────────────────────── */
/* Pulled back to the panel's real inline-start edge so the three figures — and
   the rule above them — are not indented into the words column. They are about
   the box, not about a row, so they use the PANEL's padding and not the chip's
   pull: the rule stops at the panel's edge and the chips are the only thing
   that crosses it. ONE variable for the pull and for the inset, so they cannot
   drift apart, and logical on both, so it is the other edge on /ar. */
.ksl-panel .ksl-foot{
  margin-top:var(--ksl-footsp,9px);
  padding-top:var(--ksl-footsp,9px);
  margin-inline-start:calc(-1 * var(--ksl-pps,20px));
  padding-inline-start:var(--ksl-pps,20px);
  border-top:1px solid var(--ksl-footrule,rgba(42,34,40,.09));
}

/* ── THE PHONE ──────────────────────────────────────────────────────────── */

/* ── AND WHAT THE PHONE COSTS, MEASURED RATHER THAN ASSUMED ──────────────
   A box is padding, and at 390px `.buybox` is 346px wide — so every pixel of
   inner padding is a pixel the NAMES lose, and this catalogue's names are long
   enough that losing twenty of them flips a row from one line to two. The first
   cut of this treatment was 12px of inline padding and it made the block TALLER
   than the bare list it was squeezing: 408px against 396px, because two of the
   five standing rows gained a line. A squeeze that grows the block has failed
   whatever the row padding says.

   So on the phone the panel's inline padding comes down to 9px and the gap
   between the photograph and the words to 9, which together hand back measure —
   more than the padding took. Measured, both widths, in docs/SET-BOX-SQUEEZE.md.

   THE ROWS' HEIGHT AT 390 IS SET BY THE WORDS, NOT BY THE PHOTOGRAPH, and that
   is the finding this treatment had to be re-cut around. At 1280 the names fit
   on one line and the photograph decides the row. At 390 every second name in
   this catalogue wraps to two lines and a 32px photograph is already shorter
   than they are — shrinking it further buys nothing at all. What does buy
   height there is the leading: 1.3 → 1.24 on a two-line name and 1.3 → 1.15 on
   the brand is ~2px a row, five rows of it, and neither goes near the legible
   floor.

   ▲ THIS BLOCK IS NOW THE ONE PLACE THE PHONE'S SHIPPED NUMBERS LIVE, and it
     declares VARIABLES rather than properties — which is what lets a shop that
     has moved nothing render the panel at these sizes while the panel's rules
     above stay var-driven and un-duplicated. There were TWO such blocks until
     this release, one for the bare list and one for the panel, and they
     disagreed about the photograph (36 against 32) and the row padding (5
     against 3); the panel's won because it came later, which is not a thing to
     leave to source order.

     It carries every `_m` default that DIFFERS from its laptop twin and nothing
     else; the ones that match fall through to the fallbacks above.
     SetAppearanceDefaultsTest walks both lists and asserts the EFFECTIVE phone
     value of every `_m` key against the schema, so a number added here without
     a control, or a control added without its number, is caught.

   ▲ A SHOP THAT HAS MOVED A SLIDER GETS THESE FROM
     partials/set-appearance-css.blade.php INSTEAD, whose media query uses the
     owner's own breakpoint and whose selector `.ksl.ksl` is one class more
     specific than this one — so his numbers win wherever his query matches, and
     these are what a shop that has touched nothing renders. */
@media (max-width:480px){
  .ksl{--ksl-ph:32px;--ksl-phr:9px;--ksl-gap:9px;--ksl-nm:13px;
       --ksl-footgy:4px;--ksl-footgx:12px;
       --ksl-pr:16px;--ksl-ppt:8px;--ksl-ppe:9px;--ksl-ppb:8px;--ksl-pps:16px;
       --ksl-ring:2.5px;--ksl-shb:5px;--ksl-headgap:5px;--ksl-footsp:7px;
       --ksl-nmlh:1.24;--ksl-brlh:1.15}
}


</style>
@endonce
<div class="ksl{{ \App\Services\SetAppearance::panelClass($kbbSetAp) }}">
    {{-- The same `.opt-label` line the quantity-bundle strip used, in the same
         slot: the words, then the count in the note span. Not an <h2> -- the
         note at the top of this file argues it. --}}
    @if ($kbbSetAp['p_heading_on'])<div class="opt-label">{{ __('store.set.page_heading') }} <span>{{ trans_choice('store.set.count_note', $kbbSetPage['count'], ['count' => $kbbSetPage['count']]) }}</span></div>@endif
    {{-- ONE COPY OF THE ROW, drawn twice. The folded rows and the standing rows
         are the same markup, and a second copy of it inside the <details> is
         the copy that drifts. --}}
    <div class="ksl-rows">
        @foreach ($kbbSetShown as $kbbSetPageMember)
            @include('partials.set-contents-row')
        @endforeach
    </div>
    @if ($kbbSetHidden !== [])
        <details class="ksl-more">
            <summary><span class="ksl-all">{{ trans_choice('store.set.show_all', count($kbbSetHidden), ['count' => count($kbbSetHidden)]) }}</span><span class="ksl-less">{{ __('store.set.show_fewer') }}</span></summary>
            @foreach ($kbbSetHidden as $kbbSetPageMember)
                @include('partials.set-contents-row')
            @endforeach
        </details>
    @endif
    <div class="ksl-foot">
        <span class="ksl-f ksl-was">{{ __('store.set.page_separately') }} <b>{!! Money::format((int) $kbbSetPage['partsTotal']) !!}</b></span>
        <span class="ksl-f ksl-price">{{ __('store.set.page_set_price') }} <b>{!! Money::format((int) $kbbSetPage['setPrice']) !!}</b></span>
        {{-- ▲ AN UNPRICED SET IS NOT SAVING ANYBODY ANYTHING. The owner's first
             set, half filled in, read "Bought separately: AED 806.00 / Set
             price: AED 0.00 / You save AED 806.00" -- the arithmetic right and
             the sentence false. SetContents::fromProduct() floors it to zero on
             the shopping path, and this is the one place that decides whether
             the sentence is printed at all. --}}
        @if ($kbbSetPage['saving'] > 0)<span class="ksl-save">{{ __('store.set.saving', ['amount' => Money::plain((int) $kbbSetPage['saving'])]) }}</span>@endif
    </div>
</div>
@endif
