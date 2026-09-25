{{-- Matched to the live page source: a linked name (.bn), a price (.bp) and a
     .baddbtn. No brand row.

     Two attributes, on purpose:

       data-kbb-checkout-add  is what checkout.js binds. On this page an Add
           must be silent — no drawer, no jump to Order summary — so it needs a
           hook of its own rather than sharing the global one. cart.js declines
           anything inside #kbbBrowsedList for the same reason; see the note
           there.

       data-kbb-add  is kept only so the marketing pixels' own capture-phase
           listener (MarketingPixels::addToCart) still counts these adds. It no
           longer drives any cart behaviour here. --}}
@php use App\Support\Gradient; use App\Support\Money; @endphp
@php
    // The seed stays English — see components/product-card.blade.php — and the
    // name a shopper reads is t().
    $name = $bp->t('name');
    $thumb = $bp->image
        ? "background-image:url('" . e($bp->image) . "')"
        : 'background:' . Gradient::for(($bp->brand?->name ?? '') . $bp->name);
@endphp
<div class="bitem"><a class="bth" href="{{ $bp->url() }}" style="{{ $thumb }}"></a><div class="binfo"><a class="bn" href="{{ $bp->url() }}">{{ $name }}</a><div class="bp">@if ($bp->isOnSale())@php $kbbBWas = (int) $bp->compareAtPrice(); $kbbBDp = Money::decimalsToDistinguish($kbbBWas, $bp->effectivePrice()); @endphp<del aria-hidden="true">{!! Money::format($kbbBWas, $kbbBDp) !!}</del><ins aria-hidden="true">{!! Money::format($bp->effectivePrice(), $kbbBDp) !!}</ins>@else{!! Money::format($bp->effectivePrice()) !!}@endif</div></div><button type="button" class="baddbtn" data-kbb-checkout-add="{{ $bp->id }}" data-kbb-add="{{ $bp->id }}" data-price="{{ number_format($bp->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $name }}" aria-label="{{ __('store.checkout.browsed_add_label', ['product' => $name]) }}">{{ __('store.checkout.browsed_add') }}</button></div>
