{{--
    Quick-view body. Rendered server-side by QuickViewController and injected
    into the shell that layouts/store.blade.php prints once per page.

    Only fields confirmed present on `products` are used here: short_description
    and stock_status are real columns; price/effectivePrice()/isOnSale() are the
    same accessors the card and product page use, so a sale shown on the card
    cannot disappear in the modal.
--}}
@php
    // t(), not the column — the same read the card and the product page make.
    // On English it IS the column; on /ar it is the Arabic, falling back per
    // field to the English. See App\Support\HasTranslations.
    $brand = $product->brand?->t('name') ?? '';
    $name = $product->t('name');
    $img = $product->image;
    $inStock = ($product->stock_status ?? 'instock') !== 'outofstock';
    $blurb = trim(strip_tags((string) ($product->t('short_description') ?? '')));
@endphp

<div class="qv-wrap">
  <div class="qv-media">
    @if ($img)
      @php $qvSrcset = \App\Support\ImageVariants::srcsetFor($img); @endphp
      <img src="{{ $img }}" alt="{{ $name }}" loading="lazy"
           @if ($qvSrcset !== '') srcset="{{ $qvSrcset }}" sizes="{{ \App\Support\ImageVariants::quickViewSizesAttribute() }}" @endif>
    @else
      <div class="qv-noimg"></div>
    @endif
  </div>

  <div class="qv-info">
    @if ($brand)<div class="qv-brand">{{ $brand }}</div>@endif
    <h3 class="qv-name">{{ $name }}</h3>

    @if ((int) $product->review_count > 0)
      <div class="qv-rate">
        <span>{{ str_repeat('★', max(1, (int) round((float) $product->rating))) }}</span>
        {{ number_format((float) $product->rating, 1) }} · {{ trans_choice('store.reviews.review_count', (int) $product->review_count) }}
      </div>
    @endif

    {{-- The modal quotes the same pair the card does, at the same width, for
         the same reason: at whole dirhams a markdown of AED 100.00 to AED 99.80
         printed the struck price and the live price as the identical string.
         And the -0% that guard rules out on the card was live HERE — this
         printed discountPercent() unguarded, so the modal for that product
         carried "-0%" beside two equal numbers. --}}
    <div class="qv-price">
      @if ($product->isOnSale())
        @php
          // Product::compareAtPrice(), not the `price` column: it is NULL on a
          // variable parent (the money is on the variations), so a marked-down
          // variable product printed `<del>AED 0</del>` here the moment
          // isOnSale() started answering true for one. Identical to
          // `(int) $product->price` for everything that has a price of its own.
          $kbbQvWas = (int) $product->compareAtPrice();
          $kbbQvNow = $product->effectivePrice();
          $kbbQvDp = \App\Support\Money::decimalsToDistinguish($kbbQvWas, $kbbQvNow);
          $kbbQvOff = $product->discountPercent();
        @endphp
        <del>{!! \App\Support\Money::format($kbbQvWas, $kbbQvDp) !!}</del>
        <ins>{!! \App\Support\Money::format($kbbQvNow, $kbbQvDp) !!}</ins>
        @if ($kbbQvOff >= 1)<span class="qv-off">{{ \App\Support\Bidi::number('-' . $kbbQvOff . '%') }}</span>@endif
      @else
        {!! \App\Support\Money::format($product->effectivePrice()) !!}
      @endif
    </div>

    <div class="qv-stock{{ $inStock ? '' : ' out' }}">{{ $inStock ? __('store.quick_view.in_stock') : __('store.quick_view.out_of_stock') }}</div>

    @if ($blurb !== '')
      <p class="qv-blurb">{{ \Illuminate\Support\Str::limit($blurb, 260) }}</p>
    @endif

    <div class="qv-acts">
      @if ($inStock)
        <button type="button" class="qv-add" data-kbb-add="{{ $product->id }}" data-quantity="1" data-price="{{ number_format($product->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $name }}">{{ __('store.product_card.add_to_cart') }}</button>
      @endif
      <a class="qv-full" href="{{ $product->url() }}">{{ __('store.quick_view.view_full') }}</a>
    </div>
  </div>
</div>
