{{--
    THE COUPON LINE AT THE TOP OF THE CHECKOUT (Lane QK6).

    The owner: "on very top, above summary row, i want the nice coupon line:
    For Discount, Apply coupon {coupon-code} and upon click the coupon should
    auto apply on the order total without refreshing the page etc."

    Appearance -> Checkout page -> Coupon line. Drawn only while it is on and
    its coupon (the cart panel's, GLOW by default, or one picked there) is
    usable now -- a settings snapshot and the clock, no query. The first child
    of .co-grid: above the order summary row on a phone (order:-2), and at the
    top of the checkout column on a laptop.

    BOTH STATES ARE IN THE SERVER RENDER, stacked in one grid cell, so the box
    is as tall as the taller of the two before any tap and changing state moves
    nothing. `.is-done` (the coupon is on the order) shows the second.

    The code is a real button: checkout.js hands it to the same in-place apply
    the "Have a discount code?" box uses (POST /checkout/coupon), so the totals,
    the summary row, the discount line and the box all move exactly as if the
    code had been typed there and Apply pressed. Every value is escaped; the
    only unescaped output is couponLineHtml(), which escapes the owner's
    wording and splices in this escaped button.
--}}
@php
    $kbbClApplied = strcasecmp((string) ($totals['coupon_code'] ?? ''), $kbbClCode) === 0;
    $kbbClPill = '<button type="button" class="co-cl-code" data-kbb-cline="' . e($kbbClCode) . '" aria-label="'
        . e(__('store.checkout.cline_apply_label', ['code' => $kbbClCode])) . '">' . e($kbbClCode) . '</button>';
@endphp
<div class="co-cline{{ $kbbClApplied ? ' is-done' : '' }}" id="kbbCline">
    <p class="co-cl-ask"><span class="co-cl-ic" aria-hidden="true">@include('partials.icon-tag')</span><span>{!! $kbbCoPage->couponLineHtml($kbbClCode, $kbbClPill) !!}</span></p>
    <p class="co-cl-done" role="status" aria-live="polite"><span class="co-cl-tick" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span><span class="co-cl-donetxt">{{ __('store.checkout.coupon_applied_plain', ['code' => $kbbClCode]) }}</span></p>
    <p class="co-cl-err" role="alert" hidden></p>
</div>
