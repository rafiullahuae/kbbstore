{{-- Ported from kbb_checkout_order_block(). Rendered twice: in the desktop
     summary and in the mobile place-order box, exactly as the theme does. --}}

{{-- Wrapped in a stable, class-based container (this partial is rendered
     twice, so an id would collide) so the country-change refresh in
     checkout.js can replace it wholesale — it was being left stale after a
     country change until now: the delivery charge updated correctly but this
     bar kept showing whatever threshold the page loaded with. --}}
<div class="kbb-freeship-slot">
    @include('partials.checkout.freeship-bar')
</div>

<div class="sumrow"><span>Subtotal</span><span class="js-subtotal">{!! \App\Support\Money::format($totals['subtotal']) !!}</span></div>

<div class="js-coupons">
    @if ($totals['discount'])
        <div class="sumrow disc"><span>{{ $totals['coupon_code'] }}</span><span>&ndash; {!! \App\Support\Money::format($totals['discount']) !!}</span></div>
    @endif
</div>

<div class="sumrow"><span>Delivery</span><span class="js-shipping">@if ($totals['shipping'] > 0){!! \App\Support\Money::format($totals['shipping']) !!}@else<span style="color:var(--green);font-weight:700">Free</span>@endif</span></div>

@php
    // Flat, global, does not vary by country — computed once here rather than
    // threaded through from the controller, matching how the freeship bar
    // above already reads $settings directly in this partial.
    $codFeeFils = (int) $settings->get('cod_fee', 0);
@endphp

@if ($codFeeFils > 0)
{{-- Visible only while Cash on delivery is the selected option — pure CSS,
     via :has() on the page's outer wrapper, the same technique already
     driving the selected-option highlight on the payment list itself. No JS
     needed for this part; the country-change refresh in checkout.js keeps
     the number itself correct (see js-total-fee below). --}}
<div class="sumrow js-fee-row"><span>Cash-on-delivery fee</span><span class="js-fee">{!! \App\Support\Money::format($codFeeFils) !!}</span></div>
@endif

<div class="sumrow tot js-total-row"><span>Total</span><span class="js-total">{!! \App\Support\Money::format($totals['total']) !!}</span></div>
@if ($codFeeFils > 0)
<div class="sumrow tot js-total-row-fee"><span>Total</span><span class="js-total-fee">{!! \App\Support\Money::format($totals['total'] + $codFeeFils) !!}</span></div>
@endif

@if ($totals['vat'])
    {{-- Display only. Never added to the total (D-64). --}}
    <div class="sumrow vat"><span>{{ $totals['vat']['label'] }}</span><span class="js-vat">{!! $totals['vat']['formatted'] !!}</span></div>
@endif

@if ($withActions ?? true)
    <button type="button" class="place" data-place="1">Place order</button>
    <div class="trust">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg> SSL secure</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg> 100% authentic</span>
    </div>
    @include('partials.checkout.delivery-line')
@endif
