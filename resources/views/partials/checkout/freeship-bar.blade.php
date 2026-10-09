{{-- Ported from kbb_freeship_bar_html(). Five animated styles live in the CSS;
     the rider and cheer particles are part of the markup, not decoration.
     Lane QK7: drawn only while Appearance -> Checkout page -> Delivery labels
     -> "Free-delivery bar on the cart and checkout pages" is on (it ships off,
     as the owner asked). Both order blocks and the country-change JSON render
     THIS partial, so the switch reaches every checkout copy, re-renders too. --}}
@if ($totals['free_shipping_threshold'] && app(\App\Services\CheckoutPage::class)->freeDeliveryBar())
@php
    $style = $settings->get('freeship_bar_style', 'mint');
    $unlocked = $totals['free_shipping_unlocked'];
@endphp
<div class="freebar fs-{{ $style }}{{ $unlocked ? ' is-unlocked' : '' }}">
    <div class="free">
        @if ($unlocked)
            {{-- The live site wraps this in .fs-done and adds the emoji from CSS. --}}
            <span class="fs-done">{!! \App\Support\Phrase::inline(__('store.checkout.freeship_done')) !!}</span>
        @else
            {!! __('store.cart.free_delivery_away', ['amount' => '<b>' . \App\Support\Money::format($totals['free_shipping_remaining']) . '</b>', 'free_delivery' => '<b>' . e(__('store.cart.free_delivery_phrase')) . '</b>']) !!}
        @endif
    </div>
    <div class="ftrack">
        <div class="ffill" style="width:{{ $totals['free_shipping_percent'] }}%"><i class="fs-rider" aria-hidden="true"></i></div>
        <span class="fs-cheer" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
    </div>
</div>
@endif
