{{--
    Quick-view body. Rendered server-side by QuickViewController and injected
    into the shell that layouts/store.blade.php prints once per page.

    Only fields confirmed present on `products` are used here: short_description
    and stock_status are real columns; price/effectivePrice()/isOnSale() are the
    same accessors the card and product page use, so a sale shown on the card
    cannot disappear in the modal.
--}}
@php
    $brand = $product->brand?->name ?? '';
    $img = $product->image;
    $inStock = ($product->stock_status ?? 'instock') !== 'outofstock';
    $blurb = trim(strip_tags((string) ($product->short_description ?? '')));
@endphp

<div class="qv-wrap">
  <div class="qv-media">
    @if ($img)
      <img src="{{ $img }}" alt="{{ $product->name }}" loading="lazy">
    @else
      <div class="qv-noimg"></div>
    @endif
  </div>

  <div class="qv-info">
    @if ($brand)<div class="qv-brand">{{ $brand }}</div>@endif
    <h3 class="qv-name">{{ $product->name }}</h3>

    @if ((int) $product->review_count > 0)
      <div class="qv-rate">
        <span>{{ str_repeat('★', max(1, (int) round((float) $product->rating))) }}</span>
        {{ number_format((float) $product->rating, 1) }} · {{ (int) $product->review_count }} reviews
      </div>
    @endif

    <div class="qv-price">
      @if ($product->isOnSale())
        <del>{!! \App\Support\Money::format((int) $product->price) !!}</del>
        <ins>{!! \App\Support\Money::format($product->effectivePrice()) !!}</ins>
        <span class="qv-off">-{{ $product->discountPercent() }}%</span>
      @else
        {!! \App\Support\Money::format($product->effectivePrice()) !!}
      @endif
    </div>

    <div class="qv-stock{{ $inStock ? '' : ' out' }}">{{ $inStock ? 'In stock' : 'Out of stock' }}</div>

    @if ($blurb !== '')
      <p class="qv-blurb">{{ \Illuminate\Support\Str::limit($blurb, 260) }}</p>
    @endif

    <div class="qv-acts">
      @if ($inStock)
        <button type="button" class="qv-add" data-kbb-add="{{ $product->id }}" data-quantity="1" data-price="{{ number_format($product->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $product->name }}">Add to cart</button>
      @endif
      <a class="qv-full" href="{{ $product->url() }}">View full details</a>
    </div>
  </div>
</div>
