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
    // Read from the session rather than a request field: the country-change
    // refresh and a plain reload both land here without ever posting the
    // checkbox, and a fee that vanishes when someone changes emirate is worse
    // than no fee at all.
    $giftFeeFils = ($settings->get('gift_enabled', '1') && session('kbb_gift'))
        ? (int) $settings->get('gift_fee', '1500')
        : 0;
@endphp

{{-- GIFT WRAPPING IS ITS OWN CHARGE AND HAS ITS OWN ROW, ALWAYS.

     This row used to sit inside the `$codFeeFils > 0` guard below, next to the
     Cash-on-delivery fee, as though the two were one feature. They are not: the
     Total on this block has always been `total + giftFee`, whatever the COD fee
     is. The admin's own default for `cod_fee` is zero
     (EcommerceApiController's schema row), so on a shop that charges nothing
     extra for cash the shopper ticked "This order is a gift", watched the Total
     rise by the gift fee, and found no line anywhere on the page saying why.

     Hidden, not omitted, when nothing is being charged: checkout.js unhides it
     from the gift endpoint's answer, and an element that is not there cannot be
     unhidden. --}}
<div class="sumrow js-gift-row"@if ($giftFeeFils <= 0) hidden @endif><span>Gift wrapping</span><span class="js-gift">{!! \App\Support\Money::format($giftFeeFils) !!}</span></div>

@if ($codFeeFils > 0)
{{-- Visible only while Cash on delivery is the selected option — pure CSS,
     via :has() on the page's outer wrapper, the same technique already
     driving the selected-option highlight on the payment list itself. No JS
     needed for this part; the country-change refresh in checkout.js keeps
     the number itself correct (see js-total-fee below). --}}
<div class="sumrow js-fee-row"><span>Cash-on-delivery fee</span><span class="js-fee">{!! \App\Support\Money::format($codFeeFils) !!}</span></div>
@endif

<div class="sumrow tot js-total-row"><span>Total</span><span class="js-total">{!! \App\Support\Money::format($totals['total'] + $giftFeeFils) !!}</span></div>
{{-- THE COD TOTAL IS NOT CONDITIONAL ON THERE BEING A COD FEE.

     kbb-checkout.css hides `.js-total-row` and shows `.js-total-row-fee`
     whenever #payment_method_cod is checked — unconditionally, because CSS
     cannot see what the fee is. While this row was only rendered for a fee
     above zero, a shop taking cash on delivery with no surcharge showed the
     shopper a checkout with NO TOTAL AT ALL: subtotal, delivery, VAT, then
     straight to Place order. Measured in Chromium at 390px and 1280px.

     With a fee of zero the two rows simply carry the same number, which is the
     truth, and exactly one of them is ever on screen. --}}
<div class="sumrow tot js-total-row-fee"><span>Total</span><span class="js-total-fee">{!! \App\Support\Money::format($totals['total'] + $codFeeFils + $giftFeeFils) !!}</span></div>

@if ($totals['vat'])
    {{-- Display only. Never added to the total (D-64).

         THE LABEL CARRIES THE RATE AND SO NEEDS ITS OWN HOOK.
         vat_label is "You're paying VAT ({rate}%)" with {rate} substituted by
         VatDisplay::label(), and the rate can now differ per country. The
         country-change refresh in checkout.js updated `.js-vat` — the amount —
         and nothing else, which was invisible while one global rate applied
         everywhere. With a Saudi rate set, switching country moved the figure
         to 13.04 and left "You're paying VAT (5%)" printed beside it: a
         receipt contradicting itself, which is worse than not updating at all.
         Both halves now move together. --}}
    <div class="sumrow vat"><span class="js-vat-label">{{ $totals['vat']['label'] }}</span><span class="js-vat">{!! $totals['vat']['formatted'] !!}</span></div>
@endif

@if ($withActions ?? true)
    <button type="button" class="place" data-place="1">Place order</button>
    <div class="trust">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg> SSL secure</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg> 100% authentic</span>
    </div>
    @include('partials.checkout.delivery-line')
@endif
