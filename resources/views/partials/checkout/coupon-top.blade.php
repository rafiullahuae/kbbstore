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

    2.60.512 (design A, the owner's pick): the same pink dashed ticket as the
    cart panel and the cart page, on ONE line, and once the coupon is on the
    order a green tick shows at the end of the row (the owner: "will become a
    tick check icon on the right side of the coupon row inside ... green").
    The "applied" sentence stays in the page for screen readers only.

    2.60.562 (design B, the owner's pick): an outlined "Apply" at the end of
    the row, in the same cell as the green tick. It carries the same
    data-kbb-cline as the code pill, so checkout.js applies it the same way;
    once the coupon is on the order it hides and the tick shows in its place.

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
    <p class="co-cl-ask"><span class="co-cl-ic" aria-hidden="true">@include('partials.icon-tag')</span><span class="co-cl-t">{!! $kbbCoPage->couponLineHtml($kbbClCode, $kbbClPill) !!}</span><span class="co-cl-end"><button type="button" class="co-cl-go" data-kbb-cline="{{ $kbbClCode }}" aria-label="{{ __('store.checkout.cline_apply_label', ['code' => $kbbClCode]) }}">{{ __('store.checkout.coupon_apply') }}</button><span class="co-cl-ok" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span></span></p>
    <p class="co-cl-done" role="status" aria-live="polite"><span class="co-cl-tick" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span><span class="co-cl-donetxt">{{ __('store.checkout.coupon_applied_plain', ['code' => $kbbClCode]) }}</span></p>
    <p class="co-cl-err" role="alert" hidden></p>
</div>
