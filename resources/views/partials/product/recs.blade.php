{{--
  The foot of the product page: three recommendation blocks, in the order the
  owner set (Appearance → Product page → You may also like → Order of the three
  blocks).                                                         (Lane RP)

    1  partials/you-may-also-like            slider — two tabs, brand / category
    2  partials/product/recs-routine         grid   — Complete your routine
    3  partials/product/recs-recent          slider — Continue shopping

  App\Services\ProductRecs chooses all three in two queries. Every directive is
  on its own line so nothing between them is printed: with blocks 2 and 3 off
  and the default order this file prints exactly what the single @include of
  partials/you-may-also-like printed before it.
--}}@foreach (($recs['order'] ?? ['1', '2', '3']) as $kbbRpBlock)
@if ($kbbRpBlock === '1')
@include('partials.you-may-also-like')
@elseif ($kbbRpBlock === '2')
@include('partials.product.recs-routine')
@else
@include('partials.product.recs-recent')
@endif
@endforeach
