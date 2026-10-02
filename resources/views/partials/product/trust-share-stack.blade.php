{{--
    "Authenticity Guaranteed" and the share bar, under the Add to cart row — Lane PW.

    ONE WRAPPER FOR BOTH, AND IT IS A FLEX COLUMN FOR A REASON. Two block
    siblings' vertical margins COLLAPSE — the larger wins and the smaller does
    nothing — so with the authenticity line's "space below" at 0 and the share
    bar's "space above" at 16, dragging either slider under the other's value
    would move nothing at all. Flex items' margins never collapse, so every
    spacing control on Appearance → Product page → Trust · Spacing moves the
    page by exactly the pixels it says.

    INSIDE THE CART FORM, after the button row (and after Buy it now when that
    is switched on). Every control in here is `type="button"` or a link, so
    nothing submits the basket and nothing is serialised with it.

    Nothing is drawn — not even the wrapper — when both blocks are off.
--}}@php
    $kbbPts = app(\App\Services\ProductTrustShare::class);
@endphp
@if ($kbbPts->showsAuthenticity() || $kbbPts->on('share_on'))
@include('partials.product.trust-share-assets')
<div class="pts-stack">
@include('partials.product.authenticity')
@include('partials.product.share-bar')
</div><!--/pts-stack-->
@endif
