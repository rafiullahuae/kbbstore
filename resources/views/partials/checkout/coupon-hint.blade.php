{{-- Ported from kbb_checkout_coupon_hint_html(). The code is clickable.

     THE CODE HAS TO EXIST. This partial used to fall back to the literals
     'GLOW30' and 'Need more discount? Try {code} for 30% off ✨', so a shop
     that had never configured a code advertised a 30% discount at the moment
     of payment — and the badge is clickable, so tapping it applied a code the
     shop does not have and was answered "That code is not valid." by the very
     page that recommended it.

     App\Support\CheckoutCouponHint now looks the code up and checks the same
     three conditions CouponService::validate() checks — it exists, it has
     started, it has not expired or been fully redeemed — and returns null
     otherwise, in which case nothing is rendered. With no wording of the
     owner's own it also states what the coupon is really worth, off the coupon
     row, instead of "30% off" over whatever the code is set to. --}}
@if ($settings->moduleEnabled('coupon_hint', true))
@php
    $html  = \App\Support\CheckoutCouponHint::html(\App\Support\CheckoutCouponHint::offer($settings));
    $color = (string) ($settings->get('checkout_coupon_color') ?: '#1f7d52');
    $size  = max(9, min(18, (int) $settings->get('checkout_coupon_size', 11)));
@endphp
@if (trim(strip_tags($html)) !== '')
    <div class="hint" style="color:{{ $color }};font-size:{{ $size }}px">{!! $html !!}</div>
@endif
@endif
