{{-- The line under the coupon box when the page itself has something to say
     (Lane CP): the code already on the basket, with its Remove, or the
     sentence a plain form post to /checkout/coupon left behind
     (CheckoutController::couponAnswer()). Included only when one of the two is
     true, so a checkout with neither renders not one byte more. The script
     writes into this same element (#kbbCouponMsg) and builds it on first use
     when the page did not. --}}
@php
    $kbbCpN = session('kbb_coupon_notice');
    $kbbCpN = is_array($kbbCpN) ? $kbbCpN : null;
    $kbbCpErr = $kbbCpN !== null && empty($kbbCpN['ok']);
    $kbbCpOn = ! $kbbCpErr && (string) ($totals['coupon_code'] ?? '') !== '';
    $kbbCpText = $kbbCpN !== null ? (string) ($kbbCpN['text'] ?? '') : '';
@endphp
@if ($kbbCpErr || $kbbCpOn || $kbbCpText !== '')<p class="co-cmsg {{ $kbbCpErr ? 'is-err' : 'is-ok' }}" id="kbbCouponMsg" role="status" aria-live="polite">@if ($kbbCpOn)@include('partials.checkout.coupon-applied')@else{{ $kbbCpText }}@endif</p>@endif
