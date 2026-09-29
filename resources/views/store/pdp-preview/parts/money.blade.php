{{--
    THE PRICE PAIR, ON ITS OWN, so a candidate can put it somewhere other than
    beside the title.                                                (Lane PDP)

    Four of the five draw it inside the name row — "right side cut price and
    actual price" — and parts/facts.blade.php @includes this file to do that.
    Candidate C takes it out of the row and gives it a band of its own, which on
    a desktop becomes the sticky price rail in the third column. Same markup,
    same figures, one place.

    ▲ THE STRUCK FIGURE IS Product::compareAtPrice(), NOT `(int) $product->price`.
      A variable product's markdown lives on its VARIATIONS and `products.price`
      is NULL on the parent, so the naive read prints "AED 0" struck through
      beside a real from-price. compareAtPrice() is the lowest REGULAR price
      across the options — the same figure the tile's badge and the "On sale"
      facet compare against.

    ▲ BOTH FIGURES AT ONE PRECISION, and the precision that separates them.
      Money::decimalsToDistinguish() answers 0 for every markdown that whole
      dirhams can already tell apart, and 2 for the one that cannot: without it
      a markdown from AED 100.00 to AED 99.80 prints "AED 100" inside the <b>
      and "AED 100" inside the <s> beside it, striking a price through and
      quoting the identical number next to it.

    ▲ A VARIABLE PRODUCT'S HEADLINE IS THE RANGE, not zero. $kbbHeadline comes
      from App\Services\VariantPricing by way of the shipped template's own
      reasoning; every candidate prints it for the same reason the shop does.
--}}
@php
    use App\Support\Money;

    $pvWas = (int) $product->compareAtPrice();
    $pvDp = $onSale ? Money::decimalsToDistinguish($pvWas, $price) : null;
@endphp
<div class="pv-money">
    @if ($onSale)<s>{!! Money::format($pvWas, $pvDp) !!}</s>@endif
    <b>@if ($kbbHeadline !== null){!! $kbbHeadline !!}@else{!! Money::format($price, $pvDp) !!}@endif</b>
    @if ($onSale && $off)<span class="pv-off">{{ \App\Support\Bidi::number('-'.$off.'%') }}</span>@endif
</div>
