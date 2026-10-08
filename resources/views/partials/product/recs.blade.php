{{--
  The foot of the product page: three recommendation blocks, in the order the
  owner set (Appearance → Product page → You may also like → Order of the three
  blocks).                                                         (Lane RP2)

    1  More from {brand}     partials/product/recs-block   slider by default
    2  More {category}       partials/product/recs-block   grid by default
    3  You may also like     partials/you-may-also-like    slider by default

  App\Services\ProductRecs chooses all three in two queries, no product twice.
  Slider or grid, and how many, per device: AlsoLikeSettings::layoutFor().

  THE CARDS ARE CACHED (App\Support\CardFragments): every card of every block
  is read with ONE cache read for the page, each card checked against a
  signature of everything it prints, the misses rendered with the shop's own
  component. Every directive is on its own line so nothing between them is
  printed.
--}}@php
    $kbbRpCards = \App\Support\CardFragments::many(
        collect($recs['brand']['products'] ?? [])
            ->concat($recs['category']['products'] ?? [])
            ->concat($alsoLike['products'] ?? []),
        (int) $product->id,
    );
@endphp
@foreach (($recs['order'] ?? ['1', '2', '3']) as $kbbRpBlock)
@if ($kbbRpBlock === '1' && ($recs['brand']['products'] ?? collect())->isNotEmpty())
@include('partials.product.recs-block', ['kbbB' => ['h' => 'rp1-h', 'r' => 'rp1-r', 'eyebrow' => null] + $recs['brand']])
@elseif ($kbbRpBlock === '2' && ($recs['category']['products'] ?? collect())->isNotEmpty())
@include('partials.product.recs-block', ['kbbB' => ['h' => 'rp2-h', 'r' => 'rp2-r', 'eyebrow' => null] + $recs['category']])
@elseif ($kbbRpBlock === '3')
@include('partials.you-may-also-like')
@endif
@endforeach
