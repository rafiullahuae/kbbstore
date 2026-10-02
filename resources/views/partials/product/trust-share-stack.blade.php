{{--
    "Authenticity Guaranteed" under the Add to cart row — Lane PW; and the share
    sheet's include — Lane QB.

    THE SHARE ROW THAT STOOD HERE IS GONE (Lane QB). The owner: "i want to
    remove the share row completely, and make the share icon only, and bring
    that beside the right side of the product title". The icon is Lane QA's,
    beside the title; the sheet it opens is partials/product/share-sheet,
    included below. That partial draws nothing here — it is @once and pushes
    itself to the end of <body> — so this spot in the cart form loses the row
    and gains no markup.

    ONE WRAPPER, AND IT IS A FLEX COLUMN FOR A REASON. Two block siblings'
    vertical margins COLLAPSE — the larger wins and the smaller does nothing —
    and flex items' margins never do, so every spacing control on Appearance →
    Product page → Trust · Spacing moves the page by exactly the pixels it says.
    Kept, unchanged, for the one block it now holds: the page's markup around
    "Authenticity Guaranteed" is byte-identical to what it was.

    INSIDE THE CART FORM, after the button row (and after Buy it now when that
    is switched on). Every control in here is `type="button"`, so nothing
    submits the basket and nothing is serialised with it.
--}}@php
    $kbbPts = app(\App\Services\ProductTrustShare::class);
@endphp
@if ($kbbPts->showsAuthenticity())
@include('partials.product.trust-share-assets')
<div class="pts-stack">
@include('partials.product.authenticity')
</div><!--/pts-stack-->
@endif
@include('partials.product.share-sheet')
