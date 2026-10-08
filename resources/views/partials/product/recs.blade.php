{{--
  The foot of the product page, in the order the owner set (Appearance →
  Product page → You may also like → Order of the blocks).          (Lane BC)

    1  Brand and category   partials/product/recs-tabs     two tabs (default)
                            partials/product/recs-block ×2  or two blocks
    2  Best sellers         partials/you-may-also-like      off by default
    3  Continue shopping    partials/product/recs-block     slider by default

  App\Services\ProductRecs chooses every block in two queries, no product
  twice. Slider or grid, and how many, per device: AlsoLikeSettings::layoutFor().

  THE CARDS ARE CACHED (App\Support\CardFragments): the cards every visitor of
  this page sees are read as ONE entry for the page; the shopper's own recently
  viewed ones are read on their own, so one visitor's history never rewrites
  the page's entry for the next. Every directive is on its own line so nothing
  between them is printed.
--}}@php
    $kbbRpSeen = array_flip($recs['recent']['seen'] ?? []);
    $kbbRpAll = collect($recs['brand']['products'] ?? [])
        ->concat($recs['category']['products'] ?? [])
        ->concat($alsoLike['products'] ?? [])
        ->concat($recs['recent']['products'] ?? []);
    $kbbRpCards = \App\Support\CardFragments::many($kbbRpAll->reject(fn ($p) => isset($kbbRpSeen[(int) $p->id])), (int) $product->id)
        + ($kbbRpSeen === [] ? [] : \App\Support\CardFragments::many($kbbRpAll->filter(fn ($p) => isset($kbbRpSeen[(int) $p->id]))));
@endphp
@foreach (($recs['order'] ?? ['1', '2', '3']) as $kbbRpBlock)
@if ($kbbRpBlock === '1' && ! empty($recs['tabs']))
@include('partials.product.recs-tabs')
@elseif ($kbbRpBlock === '1')
@if (($recs['brand']['products'] ?? collect())->isNotEmpty())
@include('partials.product.recs-block', ['kbbB' => ['h' => 'rp1-h', 'r' => 'rp1-r', 'eyebrow' => null] + $recs['brand']])
@endif
@if (($recs['category']['products'] ?? collect())->isNotEmpty())
@include('partials.product.recs-block', ['kbbB' => ['h' => 'rp2-h', 'r' => 'rp2-r', 'eyebrow' => null] + $recs['category']])
@endif
@elseif ($kbbRpBlock === '2')
@include('partials.you-may-also-like')
@elseif ($kbbRpBlock === '3' && ($recs['recent']['products'] ?? collect())->isNotEmpty())
@include('partials.product.recs-block', ['kbbB' => ['h' => 'rp3-h', 'r' => 'rp3-r', 'cls' => ' rp-recent', 'auto' => 0] + $recs['recent']])
@endif
@endforeach
