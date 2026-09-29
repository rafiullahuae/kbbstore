{{--
    ═══════════════════════════════════════════════════════════════════════════
    C · COUNTER — "the price is the loudest thing on the page."       (Lane PDP)
    ═══════════════════════════════════════════════════════════════════════════

    THE IDEA. On a phone the price leaves the name row and takes a tinted band
    of its own, edge to edge, at 29px — bigger than the product name. On a
    DESKTOP that band becomes a third column: a sticky price rail carrying the
    price, the stock line and the buy row, beside a middle column carrying the
    reading matter. Three columns, not two — the only one of the five that
    changes the page's skeleton rather than its dressing when it gets wide.

    ▲ AND THE ORDER HE ASKED FOR SURVIVES BOTH. The DOM order is his order —
      brand, name, price, rating, blurb, bundle bars, quantity + Add to cart —
      and the third column is made by GRID PLACEMENT at 881px and up, not by
      moving anything in the markup. On a phone the elements are simply in flow,
      so the band lands between the name and the rating exactly where he drew
      the price, and the buy row lands after the bundle bars exactly where he
      drew the button. One document, two readings, no duplicated Add to cart.

    WHAT IT ANSWERS. Finding 4 — on a phone you scroll 1155px before you can
    buy, on a screen 844px tall. Counter does not fix that by shrinking the
    photograph; it fixes it on the desktop by parking the decision in a rail
    that never leaves, and on the phone by making the price impossible to miss
    on the way down.

    WHAT IT GIVES UP. Warmth, and some of the photograph's authority: a 29px
    price on a pink band is a supermarket gesture, and this is a beauty shop.
    Three columns at 1280 also means a narrower picture than the other four.

    THE TABS. A SEGMENTED CONTROL in a cream well, the way a phone's own
    controls look: equal-width segments, and a white fill that SLIDES to the
    open one. The panel opens inside a matching cream well below, so the tab
    strip and its contents are visibly one object. "There is more to the right"
    is said by the WELL: segments are a fixed width inside a scrolling track, so
    a segment is always clipped by the well's rounded end.

    ▲ THE FILL IS PLACED BY ARITHMETIC — `inset-inline-start: k × segment
      width` — where k is the tab's index and the segment width is a constant.
      Nothing measures anything, and `inset-inline-start` means it slides the
      other way on /ar with no [dir] rule.
--}}
@extends('store.pdp-preview._layout')

@section('pv')
<div class="pv-3col">
    <div class="pv-gal">@include('partials.product-gallery')</div>

    <div class="pv-facts">
        @include('store.pdp-preview.parts.facts', ['withMoney' => false])
    </div>

    {{-- The band on a phone; the sticky rail in column three on a desktop. --}}
    <div class="pv-band">
        @include('store.pdp-preview.parts.money')
    </div>

    <div class="pv-mid">
        @include('store.pdp-preview.parts.rating')
        @include('store.pdp-preview.parts.blurb')
        @include('store.pdp-preview.parts.options')
    </div>

    <div class="pv-buygroup">
        @include('store.pdp-preview.parts.buyrow')
    </div>
</div>

@include('store.pdp-preview.parts.tabs', ['mode' => 'seg'])
@include('store.pdp-preview.parts.assurances')
@endsection
