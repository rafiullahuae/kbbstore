{{--
  Block 2 · "Complete your routine" — a GRID.                      (Lane RP)

  The next steps of a routine from the complementary shelves (App\Services\
  BuyTogetherPairs), one shelf at a time, in stock first and sharing a tag with
  this product first; never a product already shown above. Chosen by
  App\Services\ProductRecs. The cards are the shop's own <x-product-card> on
  the product page's own grid (`.rel.kbb-pgrid[data-skin]`), so the skin,
  badges, prices and chips are the category page's. No script.
--}}@php
    $kbbRt = $recs['routine'] ?? null;
@endphp
@if ($kbbRt && $kbbRt['products']->isNotEmpty())
  <section class="sec ymal rp-grid {{ $modules->classFor('related') }}" aria-labelledby="rp2-h">
    <div class="eyebrow">{{ $kbbRt['eyebrow'] }}</div>
    <h2 id="rp2-h">{{ $kbbRt['title'] }}</h2>
    <div class="rel kbb-pgrid" data-skin="{{ \App\Support\GridSkins::resolve(null) }}">@foreach ($kbbRt['products'] as $item)<x-product-card :product="$item" />@endforeach</div>
  </section>
@endif
