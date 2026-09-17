{{-- Ported from kbb_freeship_bar_html(). Five animated styles live in the CSS;
     the rider and cheer particles are part of the markup, not decoration. --}}
@if ($settings->moduleEnabled('freeship_bar', true) && $totals['free_shipping_threshold'])
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
