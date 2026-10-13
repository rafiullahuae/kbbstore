{{-- The applied-coupon line under the checkout's coupon box (Lane CP):
     "Coupon SAVE10 applied — you save AED 21.50", then Remove.

     ONE PARTIAL FOR BOTH PATHS. The page draws it on load when the basket
     already carries a code (store/checkout.blade.php, via coupon-line), and
     CheckoutController::couponUpdate() renders it for the in-place answer, from
     the same totals as the discount row in order-block -- at that row's own
     ledger width, so "you save" and the row cannot show different figures.
     Everything printed is escaped: the sentence is a translation the owner can
     edit, and the code is a coupon row's. The amount's own space is a
     non-breaking one, so "AED" and "21.50" never land on different lines.
     Needs $totals and $settings. --}}
@php
    $kbbCpSaved = (int) ($totals['discount'] ?? 0);
    $kbbCpCode = (string) ($totals['coupon_code'] ?? '');
    $kbbCpDp = app(\App\Services\CartService::class)->ledgerDecimals(
        $totals,
        (int) $settings->get('cod_fee', 0),
        (int) $settings->get('gift_fee', '1500'),
    );
@endphp
@if (($kbbCpLook = app(\App\Services\CheckoutPage::class)->couponLook()) !== 'classic')
{{-- 2.60.495: the ticket -- the code, the saving and a small x -- in the look
     chosen on 13 October layout -> "Applied coupon looks like". The x is the
     same [data-kbb-coupon-remove] checkout.js has always answered. --}}
<span class="co-tk co-tk-{{ $kbbCpLook }}">@include('partials.icon-tag')<b class="co-tk-code">{{ $kbbCpCode }}</b>@if ($kbbCpSaved > 0)<span class="co-tk-off">&minus;&nbsp;{!! \App\Support\Money::format($kbbCpSaved, $kbbCpDp) !!}</span>@endif<button type="button" class="co-crm co-tk-x" data-kbb-coupon-remove aria-label="{{ __('store.checkout.coupon_remove_label', ['code' => $kbbCpCode]) }}">&times;</button></span>
@else
<span class="co-cmsg-t">{{ $kbbCpSaved > 0
    ? __('store.checkout.coupon_applied', ['code' => $kbbCpCode, 'amount' => str_replace(' ', "\u{00A0}", \App\Support\Money::plain($kbbCpSaved, $kbbCpDp))])
    : __('store.checkout.coupon_applied_plain', ['code' => $kbbCpCode]) }}</span><button type="button" class="co-crm" data-kbb-coupon-remove>{{ __('store.checkout.coupon_remove') }}</button>
@endif
