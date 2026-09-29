{{--
    ═══════════════════════════════════════════════════════════════════════════
    D · DECK — "the tab row IS the panel."                            (Lane PDP)
    ═══════════════════════════════════════════════════════════════════════════

    THE IDEA, AND IT IS THE ANSWER TO THE THING HE ASKED FOR AN IDEA ABOUT.

    Books on a shelf. Every tab is a card in one horizontal, snapping row. The
    open one is a SPREAD — full width, its title at the top, its text inside it.
    The closed ones are SPINES: 46px wide, their titles stood on end with
    `writing-mode: vertical-rl`, at half opacity. Tap a spine and it becomes the
    spread; the one that was open folds back into a spine.

    Every one of his four requirements is met by the same object rather than by
    four separate mechanisms:

      · one row                  — there is only one row; it is the whole thing
      · open prominent, rest
        faded                    — a spread against spines, at 100% and 50%
      · scrolls left to right    — the row is the scroller, with scroll snapping
      · tap any tab, it opens    — the spine IS the tab, and tapping it opens it

    AND THE "MORE TO THE RIGHT" PROBLEM SOLVES ITSELF. There is no arrow because
    there is nothing to point at: a spine is always standing at the edge of the
    screen, so the strip can never look finished when it is not. This is the
    only one of the five where the affordance is the design rather than
    something added to the design.

    THE REST OF THE PAGE STAYS OUT OF ITS WAY. No cards anywhere else, no tints,
    no bands — because the deck is already a strong object and two strong
    objects on one page is one too many. The one other move is the DOCK: the
    quantity and Add to cart row is `position:sticky; bottom:10px`, so while you
    are anywhere in the part of the page that is about buying, the button is on
    screen at the bottom — and the moment you scroll past into the tabs it
    releases and stays where it belongs. Not a second floating bar with a second
    copy of the price: the SAME button, in the same place in the reading order,
    that happens to stay visible while it is still relevant.

    WHAT IT GIVES UP. Vertical titles are a device, and a device can wear out —
    a shop with eight tabs would show a picket fence of spines. It also reads
    less like a standard product page than the other four, which is either the
    reason to choose it or the reason not to.
--}}
@extends('store.pdp-preview._layout')

@section('pv')
<div class="pv-2col">
    <div class="pv-gal">@include('partials.product-gallery')</div>

    <div class="pv-body-in">
        @include('store.pdp-preview.parts.facts')
        @include('store.pdp-preview.parts.rating')
        @include('store.pdp-preview.parts.blurb')
        @include('store.pdp-preview.parts.options')
        <div class="pv-dock">@include('store.pdp-preview.parts.buyrow')</div>
    </div>
</div>

@include('store.pdp-preview.parts.tabs', ['mode' => 'deck'])
@include('store.pdp-preview.parts.assurances')
@endsection
