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

  THE CARDS ARE CACHED (App\Support\CardFragments): every card of every block
  is fetched with ONE cache read for the page here (plus one for the shopper's
  own recently viewed), each card checked against a signature of everything it
  prints, and the misses rendered with the shop's own component.
  Block 1's one-row layout (the owner's opt-out) still draws its own.
--}}@php
    // The cards every visitor of this page sees are read as ONE entry for the
    // page; the shopper's own recently viewed ones are read on their own, so
    // one visitor's history never rewrites the page's entry for the next.
    $kbbRpSeen = array_flip($recs['recent']['seen'] ?? []);
    $kbbRpAll = collect($alsoLike['panels'] ?? [])
        ->flatMap(fn ($panel) => $panel['products'])
        ->concat($recs['routine']['products'] ?? [])
        ->concat($recs['recent']['products'] ?? []);
    $kbbRpCards = \App\Support\CardFragments::many($kbbRpAll->reject(fn ($p) => isset($kbbRpSeen[(int) $p->id])), (int) $product->id)
        + \App\Support\CardFragments::many($kbbRpAll->filter(fn ($p) => isset($kbbRpSeen[(int) $p->id])));
@endphp
@foreach (($recs['order'] ?? ['1', '2', '3']) as $kbbRpBlock)
@if ($kbbRpBlock === '1')
@include('partials.you-may-also-like')
@elseif ($kbbRpBlock === '2')
@include('partials.product.recs-routine')
@else
@include('partials.product.recs-recent')
@endif
@endforeach
