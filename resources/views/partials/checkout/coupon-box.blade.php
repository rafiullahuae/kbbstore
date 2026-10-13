{{-- The checkout's coupon box: the field, Apply, the line under them and the
     hint. Its own partial since 2.60.495 so the same markup can sit where it
     always did (13 October layout -> "Coupon field inside the order summary"
     off) or inside the order summary (on, as asked). Rendered ONCE per page
     either way: checkout.js finds the field, the button and the message slot
     by id and by `.coupon .crow`. --}}
                <div class="coupon">
{{-- Lane CO: "remove the icon + text Have a discount code? from the coupon
     box" -- Appearance -> Checkout page -> Coupon, express & sign in -> first
     switch, OFF as asked. ON is the line exactly as it was. --}}@if ($kbbCoPage->couponHead())
                    <div class="ch"><span class="gift">🎁</span> {{ __('store.checkout.coupon_prompt') }}</div>
@endif
                    <div class="crow">
{{-- FLOATING LABELS (Lane CD): Appearance -> Checkout page -> Fields &
     attention -> "Floating labels on checkout fields", ON as asked. The
     Create account form's .fld shape -- icon, control, then a real label the
     CSS lifts once the box is focused or filled. Same name, id and
     autocomplete either way; directives at column 0 so OFF is byte-identical. --}}@if ($kbbCoPage->floatLabels())
                        <span class="fld kbb-fl kbb-fl-coupon ico"><span class="lead" aria-hidden="true">@include('partials.icon-tag')</span><input type="text" name="coupon_code" class="input-text" id="kbb_coupon_code" placeholder=" " autocomplete="off"><label for="kbb_coupon_code">{{ __('store.checkout.coupon_placeholder') }}</label></span>
@else
                        <input type="text" name="coupon_code" class="input-text" id="kbb_coupon_code" placeholder="{{ __('store.checkout.coupon_placeholder') }}" autocomplete="off">
@endif
                        <button type="button" class="apply" id="kbb_apply_coupon">{{ __('store.checkout.coupon_apply') }}</button>
                    </div>
@if (session()->has('kbb_coupon_notice') || (string) ($totals['coupon_code'] ?? '') !== '')@include('partials.checkout.coupon-line')@endif                    @include('partials.checkout.coupon-hint')                </div>