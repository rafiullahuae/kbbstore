{{--
    Checkout — matched field for field against the live page source.

    Differences that were found and corrected: Emirate is a text input (the
    select reloaded the page on change), Country is hidden, Phone is optional,
    the labels and placeholders are the live wording, and prices use the Arabic
    dirham symbol with no decimals.
--}}
@extends('layouts.store')
@php use App\Support\Money; use App\Support\Url; @endphp

@section('bare', '1')
@section('title', 'Checkout · K-Beauty Bliss')

@push('styles')
    @vite('resources/css/kbb/kbb-checkout.css')
@endpush

@section('content')
<section class="kbb-checkout">

    <!-- slim secure-checkout header (design .head) -->
    <header class="co-head"><div class="in">
        <a class="logo" href="{{ Url::to('/') }}">K-Beauty<span>Bliss</span></a>
        <span class="secure">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
            Secure checkout        </span>
    </div></header>

    <div class="co-notices" style="max-width:1040px;margin:0 auto;padding:16px 20px 0">
        @if ($errors->any())<div class="co-note err">{{ $errors->first() }}</div>@endif
    </div>

    <form name="checkout" method="post" class="checkout woocommerce-checkout" action="{{ Url::to('/checkout/place') }}" enctype="multipart/form-data" id="kbbCheckoutForm">
        @csrf
        <div class="co-grid">

            <!-- LEFT -->
            <div>
                <div class="co-titlebar">
                    <div class="co-titlebar-main">
                        <a class="backlink" href="{{ Url::to('/shop/') }}">← Back to shop</a>
                        <h1 class="co-h">Checkout</h1>
                        <p class="co-lead">Almost glowing — just a few details.</p>
                    </div>
                    @include('partials.checkout.back-to-cart')
                </div>

                <div class="coupon">
                    <div class="ch"><span class="gift">🎁</span> Have a discount code?</div>
                    <div class="crow">
                        <input type="text" name="coupon_code" class="input-text" id="kbb_coupon_code" placeholder="Enter promo code" autocomplete="off">
                        <button type="button" class="apply" id="kbb_apply_coupon">Apply</button>
                    </div>
                    @include('partials.checkout.coupon-hint')                </div>

                <div class="formbox" id="customer_details">

                    <!-- 1 · Contact -->
                    <div class="sec">
                        <h2><span class="n">1</span> Contact</h2>
                        <div class="row2">
                            <p class="form-row form-row-wide validate-required validate-email" id="billing_email_field" data-priority="1"><label for="billing_email" class="required_field">Email address&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="email" class="input-text " name="billing_email" id="billing_email" placeholder="you@email.com"  value="{{ old('billing_email', $prefill['email'] ?? '') }}" aria-required="true" autocomplete="section-billing billing email" /></span></p>                            <p class="form-row form-row-wide validate-phone" id="billing_phone_field" data-priority="100"><label for="billing_phone" class="">Phone&nbsp;<span class="optional">(optional)</span></label><span class="woocommerce-input-wrapper"><input type="tel" class="input-text " name="billing_phone" id="billing_phone" placeholder="+971 5x xxx xxxx"  value="{{ old('billing_phone', $prefill['phone'] ?? '') }}" autocomplete="section-billing billing tel" /></span></p>                        </div>
                        <div class="co-note ok" id="kbbReturning" style="display:none"></div>
                            <div class="kbb-wa"><p class="form-row form-row-wide" id="billing_kbb_whatsapp_field" data-priority="25"><span class="woocommerce-input-wrapper"><label class="checkbox " ><input type="checkbox" name="billing_kbb_whatsapp" id="billing_kbb_whatsapp" value="1" class="input-checkbox " @checked(old('billing_kbb_whatsapp', true)) /> Send order updates on WhatsApp — confirmation, dispatch &amp; delivery alerts.&nbsp;<span class="optional">(optional)</span></label></span></p></div>
                    </div>

                    <!-- 2 · Shipping address -->
                    <div class="sec">
                        <h2><span class="n">2</span> Shipping address</h2>
@if ($singleName ?? true)
                            <p class="form-row form-row-wide validate-required" id="billing_first_name_field" data-priority="10"><label for="billing_first_name" class="required_field">Full name&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_first_name" id="billing_first_name" placeholder="First and last name"  value="{{ old('billing_first_name', $prefill['name'] ?? '') }}" aria-required="true" autocomplete="section-billing billing given-name" /></span></p>
@else
                            {{-- Store → Ecommerce → Checkout → Form fields, off. The single field
                                 above is still what the backend sees when this is on — splitName()
                                 in the controller has accepted this shape all along; only the form
                                 itself never offered it. --}}
                            <div class="row2">
                                <p class="form-row form-row-first validate-required" id="billing_first_name_field" data-priority="10"><label for="billing_first_name" class="required_field">First name&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_first_name" id="billing_first_name" placeholder=""  value="{{ old('billing_first_name', $prefill['first_name'] ?? '') }}" aria-required="true" autocomplete="section-billing billing given-name" /></span></p>
                                <p class="form-row form-row-last validate-required" id="billing_last_name_field" data-priority="20"><label for="billing_last_name" class="required_field">Last name&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_last_name" id="billing_last_name" placeholder=""  value="{{ old('billing_last_name', $prefill['last_name'] ?? '') }}" aria-required="true" autocomplete="section-billing billing family-name" /></span></p>
                            </div>
@endif                        <p class="form-row form-row-wide validate-required" id="billing_address_1_field" data-priority="50"><label for="billing_address_1" class="required_field">Address&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_address_1" id="billing_address_1" placeholder="Street, building / villa no."  value="{{ old('billing_address_1', $prefill['line1'] ?? '') }}" aria-required="true" autocomplete="section-billing billing address-line1" /></span></p>                        <div class="row2">
                            {{-- A text input, exactly as the live site. It was a select whose
                                 change handler reloaded the page, which made the field unusable. --}}
                            <p class="form-row form-row-wide validate-required validate-state" id="billing_state_field" data-priority="80"><label for="billing_state" class="required_field">Emirate&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " value="{{ old('billing_state', $prefill['state'] ?? '') }}"  placeholder="" name="billing_state" id="billing_state" aria-required="true" autocomplete="section-billing billing address-level1" data-input-classes=""/></span></p>                            <p class="form-row form-row-wide validate-required" id="billing_city_field" data-priority="70"><label for="billing_city" class="required_field">City / area&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_city" id="billing_city" placeholder="e.g. Al Reem Island"  value="{{ old('billing_city', $prefill['city'] ?? '') }}" aria-required="true" autocomplete="section-billing billing address-level2" /></span></p>                        </div>
{{-- The country selector is always shown now: delivery always covers at
                             least the zone countries (the Gulf set, in production), and the
                             charge always depends on which one is picked. It carries the
                             checkout's own .input-text class, the same wrapper as Emirate and
                             City, so it matches rather than being a new look bolted in. --}}
                        <p class="form-row form-row-wide validate-required" id="billing_country_field" data-priority="40"><label for="billing_country" class="required_field">Country&nbsp;<span class="required" aria-hidden="true">*</span>@if ($countryDetected ?? false)<span class="xd-detected">Detected</span>@endif</label><span class="woocommerce-input-wrapper"><select name="billing_country" id="billing_country" class="input-text " aria-required="true" autocomplete="section-billing billing country">@foreach ($countries as $code => $name)<option value="{{ $code }}" @selected(old('billing_country', $prefill['country'] ?? $defaultCountry) === $code)>{{ $name }}</option>@endforeach</select></span></p>
                    </div>

                    <!-- 3 · Delivery -->
                    <div class="sec">
                        <h2><span class="n">3</span> Delivery</h2>
                        @if (!empty($unservedCountry))
                            <p class="xd-unserved">We do not deliver to
                                {{ \App\Support\Countries::NAMES[$unservedCountry] ?? $unservedCountry }} yet.
                                Choose another country above, or contact us and we will see what we can do.</p>
                        @endif
                        <div id="kbbDeliverySlot" class="kbb-delivery">
                            @include('partials.checkout.delivery-options')
                        </div>
                    </div>

                    <!-- 4 · Payment -->
                    <div class="sec pay">
                        <h2><span class="n">4</span> Payment</h2>

                            <div class="express" aria-hidden="true">
                                <button type="button" class="xbtn xapple" tabindex="-1"> Apple&nbsp;Pay</button>
                                <button type="button" class="xbtn xgoogle" tabindex="-1"><b><span class="xg-b">G</span><span class="xg-o">o</span><span class="xg-y">o</span><span class="xg-b">g</span><span class="xg-gr">l</span><span class="xg-o">e</span></b>&nbsp;Pay</button>
                            </div>
                            <div class="ordiv">or pay with</div>

                        <div id="payment" class="woocommerce-checkout-payment">
            <ul class="wc_payment_methods payment_methods methods">
            @if (!empty($codHidden))<p class="pay-note">{{ $codHidden }}</p>@endif
            @foreach ($gateways as $g)
            <li class="wc_payment_method payment_method_{{ $g['id'] }}">
    <input id="payment_method_{{ $g['id'] }}" type="radio" class="input-radio" name="payment_method" value="{{ $g['id'] }}" @checked($loop->first) data-order_button_text="" />

    <label for="payment_method_{{ $g['id'] }}">
        {{ $g['title'] }}
        {{-- The fee, right on the option — not only in the paragraph below,
             which is easy to miss until after it has already been chosen. --}}
        @if (!empty($g['fee_html']))<span class="codfee">{!! $g['fee_html'] !!}</span>@endif
    </label>
            @if ($g['description'])
            {{-- Shown purely by :has() on .kbb-checkout below, matching how
                 the selected-option highlight on this same list already
                 works — no JS, and correct for whichever option is checked
                 rather than only ever the first one. --}}
            <div class="payment_box payment_method_{{ $g['id'] }}">
            <p>{!! $g['description'] !!}</p>
        </div>
            @endif
    </li>
            @endforeach
        </ul>
                        </div>
                    </div>

                    @include('partials.checkout.reassurance')                </div>
            </div>

            <!-- RIGHT summary -->
            <aside class="summary" id="kbbSummary">
                @if ($showBrowsed)
                <div class="sumtabs" role="tablist">
                    <button type="button" class="stab on" data-stab="summary">Order summary</button>
                    <button type="button" class="stab" data-stab="browsed">Browsed <span class="bcount">{{ $browsed->count() }}</span></button>
                </div>
                @endif

                <div class="panels" id="kbbPanels">
                    <!-- Order summary panel -->
                    <div class="spanel on" data-spanel="summary">
                        <div class="co-items">
                            @include('partials.checkout.summary-items')                        </div>

                        @include('partials.checkout.order-block', ['withActions' => true])                    </div>

                    @if ($showBrowsed)
                    <!-- Browsed panel -->
                    <div class="spanel" data-spanel="browsed">
                        <p class="bhead">Recently browsed — add in one tap</p>
                        <div id="kbbBrowsedList">
                            @foreach ($browsed as $bp)@include('partials.checkout.browsed-item', ['bp' => $bp])@endforeach                        </div>
                    </div>
                    @endif

                    <div class="peekfade"></div>
                </div>

                <!-- mobile: expand/collapse the summary -->
                <button type="button" class="viewfull" id="kbbViewItems">View full summary ▾</button>
            </aside>

        </div>

        <!-- mobile-only: full on-page Place order box (sticky bar is optional) -->
        <div class="kbb-mobile-order">
            @include('partials.checkout.thumbs')            @include('partials.checkout.order-block', ['withActions' => true])        </div>
    </form>

    @if ($settings->get('mobile_sticky_bar', false))
    {{-- Optional, off by default. The on-page box above is the design; this is
         an extra for stores that want the total always visible while scrolling. --}}
    <div class="mpbar">
        <div><div class="ml">Total</div><div class="mt js-total">{!! Money::format($totals['total']) !!}</div></div>
        <button type="button" class="mb" data-place="1">Place order</button>
    </div>
    @endif
    </section>

@push('scripts')
{!! app(\App\Services\MarketingPixels::class)->beginCheckout((int) $totals['total']) !!}
@endpush
@endsection
