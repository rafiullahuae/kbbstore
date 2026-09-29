{{--
    ═══════════════════════════════════════════════════════════════════════════
    A · LEDGER — "there is not a single box on this page."            (Lane PDP)
    ═══════════════════════════════════════════════════════════════════════════

    THE IDEA. This shop uses very few boxes, and the one place it uses a lot of
    them is the product page. Ledger removes every one: the photograph runs to
    both edges of the phone with no frame and no corner radius, the thumbnails
    float on top of its bottom edge, and everything under it is separated by
    hairline rules — the way a well-set invoice or a menu is separated. Nothing
    is elevated, nothing is tinted, nothing has a border except one pixel of
    line between one idea and the next.

    WHAT IT ANSWERS. Finding 2 of docs/PP-PRODUCT-PAGE-PROPOSALS.md — eleven
    blocks at one rhythm with nothing saying which is the decision. Ledger does
    not answer it by drawing a card round the decision; it answers it by taking
    the noise away from everything else, so the only strong marks left on the
    page are the photograph, the price and the button.

    WHAT IT GIVES UP. It is the quietest of the five and the least "designed".
    On a desktop the buy column is a column of text with a pink button in it; a
    shopper who wants to be told where to look is not told.

    THE TABS. An underline row — the plainest form there is, one rule under the
    open title, everything else at 38% — with the panel inline directly beneath
    it. "There is more to the right" is said by a MASK: the row bleeds to the
    screen edge and its last 34px dissolve into the page, so a tab is visibly
    cut rather than visibly ending. No arrow, no gradient box, no width spent.

    THE PHOTOGRAPH IS SQUARE. `.gmain{aspect-ratio:1}`, untouched, in all five —
    the owner settled that in as many words.
--}}
@extends('store.pdp-preview._layout')

@section('pv')
<div class="pv-2col">
    <div class="pv-gal">@include('partials.product-gallery')</div>

    <div class="pv-body-in">
        @include('store.pdp-preview.parts.facts')
        @include('store.pdp-preview.parts.rating')
        <div class="pv-rule">@include('store.pdp-preview.parts.blurb')</div>
        <div class="pv-rule">@include('store.pdp-preview.parts.options')</div>
        <div class="pv-rule">@include('store.pdp-preview.parts.buyrow')</div>
    </div>
</div>

<div class="pv-rule">@include('store.pdp-preview.parts.tabs', ['mode' => 'underline'])</div>
<div class="pv-rule">@include('store.pdp-preview.parts.assurances')</div>
@endsection
