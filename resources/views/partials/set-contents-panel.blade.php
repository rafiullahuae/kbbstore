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

    $kbbSetPage = $product->isSet() ? SetContents::fromProduct($product) : SetContents::NONE;

    /*
     * HOW MANY ROWS STAND BEFORE THE FOLD.
     *
     * Five, and the number lives here rather than in the CSS because the fold
     * is a SERVER decision: the rows past it are inside a <details> in the
     * markup, which is what lets the disclosure work with no script at all.
     * Five rows is about 370px of list, which leaves the Add to cart button on
     * a 667px phone screen with the price still above it.
     *
     * A box of exactly six would fold ONE row, which is a disclosure that
     * saves nothing and costs a click -- so the fold applies from seven
     * upward and a six-member box is drawn whole.
     */
    $kbbSetFold = 5;
    $kbbSetRows = $kbbSetPage['members'];
    $kbbSetFolds = count($kbbSetRows) > $kbbSetFold + 1;
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
.ksl{display:block;min-width:0;margin:0 0 14px}
.ksl-rows{display:block;min-width:0}

/* The row. Photograph, words, money — three tracks along the inline axis, so
   Arabic mirrors without a second rule. */
.ksl-r{display:grid;gap:0 10px;min-width:0;align-items:center;padding:6px 0;
       grid-template-columns:40px minmax(0,1fr) auto}
.ksl-r + .ksl-r{border-block-start:1px solid var(--line,rgba(42,34,40,.10))}

/* A fixed square, so every name in the column starts at the same place --
   which is the whole reason to draw a list rather than a grid. */
.ksl-ph{position:relative;display:block;width:40px;height:40px;
        border-radius:8px;overflow:hidden;background:var(--line-2,rgba(42,34,40,.06))}
.ksl-ph img{width:100%;height:100%;object-fit:cover;display:block}
.ksl-ph.is-blank{background-size:cover;background-position:center}

/* align-items:flex-start, and it is load-bearing rather than tidy. The name is
   an <a> with a bottom border, and a flex child in a column stretches to the
   full cross size by DEFAULT -- so the underline ran the whole width of the
   words column and sat under the empty space past the end of the name. Caught
   in Chromium at 1280 and at 390; `flex-start` is the logical value, so it is
   the right-hand edge in Arabic from the same declaration. */
.ksl-w{min-width:0;display:flex;flex-direction:column;align-items:flex-start;
       gap:1px;text-align:start}
.ksl-br{font-size:10px;letter-spacing:.04em;text-transform:uppercase;font-weight:700;
        color:var(--ink-2,#5E545A);opacity:.72;overflow-wrap:anywhere;line-height:1.3}
.ksl-nm{font-size:13.5px;line-height:1.3;font-weight:640;color:var(--ink,#2A2228);
        overflow-wrap:anywhere}
/* A REAL LINK, so it is a link for a keyboard and for a crawler as well as for
   a mouse. A member that is not published is the same words without an <a> --
   see SetContents::memberIsLive(): a href to a draft is a 404 on the one page
   a shopper reached from Google. */
/* padding-block with an equal NEGATIVE margin-block: an inline box's vertical
   padding does not enter its line box, so this is 6px more hit area for a thumb
   and zero extra row height. Measured in Chromium at 390 and 1280 -- the row is
   the same height with it and without it. */
a.ksl-nm{color:inherit;text-decoration:none;border-bottom:1px solid var(--line,rgba(42,34,40,.10));
         padding-block:3px;margin-block:-3px}
a.ksl-nm:hover{border-bottom-color:currentColor}
a.ksl-nm:focus-visible{outline:2px solid currentColor;outline-offset:2px}
.ksl-var{font-size:11.5px;line-height:1.3;color:var(--ink-2,#5E545A);overflow-wrap:anywhere}

/* The third track is the quantity ALONE now. The price span and the flex column
   that wrapped it went with the price -- a column around a single child, and a
   rule for an element that is no longer emitted.
   (Their class names are deliberately not written here: this block is emitted
   INTO the page, so a class name in this comment is a string in the HTML, and
   the case that asserts no member carries a price searches the HTML for it.) */
.ksl-q{font-size:12px;font-weight:700;color:var(--ink,#2A2228);white-space:nowrap;
       text-align:end}

/* ── THE DISCLOSURE ──────────────────────────────────────────────────────
   HTML's own, so there is no script. Both labels are in the markup and this
   swaps them; the default marker is removed because every browser draws a
   different triangle and the row already reads as a control. */
.ksl-more{display:block;min-width:0}
.ksl-more > summary{list-style:none;cursor:pointer;display:block;min-width:0;
  padding:8px 0 6px;font-size:12.5px;font-weight:700;color:var(--ink,#2A2228);
  text-align:start;border-block-start:1px solid var(--line,rgba(42,34,40,.10))}
.ksl-more > summary::-webkit-details-marker{display:none}
.ksl-more > summary:focus-visible{outline:2px solid currentColor;outline-offset:2px}
.ksl-more > summary span{border-bottom:1px solid var(--line,rgba(42,34,40,.10))}
.ksl-more > summary .ksl-less{display:none}
.ksl-more[open] > summary .ksl-less{display:inline}
.ksl-more[open] > summary .ksl-all{display:none}
/* The first folded row carries its own hairline, so it is separated from the
   summary the way every other row is separated from the one above it. */
.ksl-more .ksl-r:first-of-type{border-block-start:1px solid var(--line,rgba(42,34,40,.10))}

/* The footing. Bought separately, the set's own price, and the saving -- the
   same three integers SetContents already computed, printed rather than
   recomputed, and counting every member whether or not it is folded away. */
.ksl-foot{display:flex;flex-wrap:wrap;gap:5px 16px;align-items:baseline;min-width:0;
          margin-top:9px;padding-top:9px;border-top:1px solid var(--line,rgba(42,34,40,.10))}
.ksl-f{display:flex;gap:6px;align-items:baseline;min-width:0;font-size:13px;
       color:var(--ink-2,#5E545A)}
.ksl-f b{font-weight:700;color:var(--ink,#2A2228);white-space:nowrap}
.ksl-was b{font-weight:600;text-decoration:line-through;color:var(--ink-2,#5E545A)}
.ksl-save{font-size:13px;font-weight:700;color:#1c7a4a;white-space:nowrap}

/* ── THE PHONE ───────────────────────────────────────────────────────────
   "also the mobile screen will adjust that list nicely and display."

   The photograph comes down 8px, the row padding 2, the name half a step --
   and the quantity and the price stop stacking. */
@media (max-width:480px){
  .ksl-r{grid-template-columns:36px minmax(0,1fr) auto;gap:0 9px;padding:5px 0}
  .ksl-ph{width:36px;height:36px;border-radius:7px}
  .ksl-nm{font-size:13px}
  .ksl-foot{gap:4px 12px}
}
</style>
@endonce
<div class="ksl">
    {{-- The same `.opt-label` line the quantity-bundle strip used, in the same
         slot: the words, then the count in the note span. Not an <h2> -- the
         note at the top of this file argues it. --}}
    <div class="opt-label">{{ __('store.set.page_heading') }} <span>{{ trans_choice('store.set.count_note', $kbbSetPage['count'], ['count' => $kbbSetPage['count']]) }}</span></div>
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
        <span class="ksl-f">{{ __('store.set.page_set_price') }} <b>{!! Money::format((int) $kbbSetPage['setPrice']) !!}</b></span>
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
