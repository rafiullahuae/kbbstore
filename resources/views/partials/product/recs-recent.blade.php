{{--
  Block 3 · "Continue shopping" — a SLIDER.                        (Lane RP)

  The shopper's recently viewed products (the `kbb_viewed` cookie the product
  page already writes and the cart panel already reads), then best sellers.
  Google and a first visit carry no cookie and get best sellers — real links.
  The same carousel as block 1 (`[data-ymal]`, resources/js/kbb/ymal.js), the
  same cards-in-view, no autoplay. Chosen by App\Services\ProductRecs.
--}}@php
    $kbbRc = $recs['recent'] ?? null;
@endphp
@if ($kbbRc && $kbbRc['products']->isNotEmpty())
@php
    $kbbRcT = \App\Services\ProductRecs::track($alsoLike['config'] ?? []);
@endphp
  <section class="sec ymal rp-recent{{ $kbbRcT['arrM'] }} {{ $modules->classFor('related') }}" data-ymal data-ymal-auto="0" aria-labelledby="rp3-h" style="--ymal-d:{{ $kbbRcT['d'] }};--ymal-m:{{ $kbbRcT['m'] }}">
    <div class="eyebrow">{{ $kbbRc['eyebrow'] }}</div>
    <h2 id="rp3-h">{{ $kbbRc['title'] }}</h2>
    <div class="ymal-nav">
      <button type="button" class="ymal-btn" data-ymal-prev aria-controls="rp3-r" aria-label="{{ __('store.product.related_prev') }}" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button>
      <button type="button" class="ymal-btn" data-ymal-next aria-controls="rp3-r" aria-label="{{ __('store.product.related_next') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 5.5 6.5 6.5-6.5 6.5"/></svg></button>
    </div>
    <div class="rel kbb-pgrid ymal-track" data-skin="{{ \App\Support\GridSkins::resolve(null) }}" id="rp3-r" data-ymal-track tabindex="0" role="region" aria-label="{{ $kbbRc['title'] }}">@foreach ($kbbRc['products'] as $item){!! $kbbRpCards[$item->id] ?? \App\Support\CardFragments::render($item) !!}@endforeach</div>
  </section>
@endif
