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
                            <p class="form-row form-row-wide validate-required validate-email" id="billing_email_field" data-priority="1"><label for="billing_email" class="required_field">Email address&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="email" class="input-text " name="billing_email" id="billing_email" placeholder="you@email.com"  value="{{ old('billing_email', $prefill['email'] ?? '') }}" required aria-required="true" autocomplete="section-billing billing email" /></span></p>                            <p class="form-row form-row-wide validate-phone" id="billing_phone_field" data-priority="100"><label for="billing_phone" class="">Phone&nbsp;<span class="optional">(optional)</span></label><span class="woocommerce-input-wrapper"><input type="tel" class="input-text " name="billing_phone" id="billing_phone" placeholder="+971 5x xxx xxxx"  value="{{ old('billing_phone', $prefill['phone'] ?? '') }}" autocomplete="section-billing billing tel" /></span></p>                        </div>
@guest('customer')
                            <p class="form-row form-row-wide kbb-acct" id="create_account_field">
                                <label for="create_account" class="kbb-acct-opt">
                                    <input type="checkbox" name="create_account" id="create_account" value="1" @checked(old('create_account'))>
                                    <span>Create an account for faster checkout next time</span>
                                </label>
                                <span class="woocommerce-input-wrapper kbb-acct-pw" id="account_password_wrap" hidden>
                                    <input type="password" class="input-text" name="account_password" id="account_password"
                                           placeholder="Choose a password (8 characters or more)" autocomplete="new-password" minlength="8">
                                </span>
                                @error('account_password')<span class="kbb-acct-err">{{ $message }}</span>@enderror
                            </p>
@endguest



                        <div class="co-note ok" id="kbbReturning" style="display:none"></div>
                            <div class="kbb-wa"><p class="form-row form-row-wide" id="billing_kbb_whatsapp_field" data-priority="25"><span class="woocommerce-input-wrapper"><label class="checkbox " ><input type="checkbox" name="billing_kbb_whatsapp" id="billing_kbb_whatsapp" value="1" class="input-checkbox " @checked(old('billing_kbb_whatsapp', true)) /> Send order updates on WhatsApp — confirmation, dispatch &amp; delivery alerts.&nbsp;<span class="optional">(optional)</span></label></span></p></div>
                    </div>

                    <!-- 2 · Shipping address -->
                    <div class="sec">
                        <h2><span class="n">2</span> Shipping address</h2>
@if ($singleName ?? true)
                            <p class="form-row form-row-wide validate-required" id="billing_first_name_field" data-priority="10"><label for="billing_first_name" class="required_field">Full name&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_first_name" id="billing_first_name" placeholder="First and last name"  value="{{ old('billing_first_name', $prefill['name'] ?? '') }}" required aria-required="true" autocomplete="section-billing billing given-name" /></span></p>
@else
                            {{-- Store → Ecommerce → Checkout → Form fields, off. The single field
                                 above is still what the backend sees when this is on — splitName()
                                 in the controller has accepted this shape all along; only the form
                                 itself never offered it. --}}
                            <div class="row2">
                                <p class="form-row form-row-first validate-required" id="billing_first_name_field" data-priority="10"><label for="billing_first_name" class="required_field">First name&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_first_name" id="billing_first_name" placeholder=""  value="{{ old('billing_first_name', $prefill['first_name'] ?? '') }}" required aria-required="true" autocomplete="section-billing billing given-name" /></span></p>
                                <p class="form-row form-row-last validate-required" id="billing_last_name_field" data-priority="20"><label for="billing_last_name" class="required_field">Last name&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_last_name" id="billing_last_name" placeholder=""  value="{{ old('billing_last_name', $prefill['last_name'] ?? '') }}" required aria-required="true" autocomplete="section-billing billing family-name" /></span></p>
                            </div>
@endif                        <p class="form-row form-row-wide validate-required" id="billing_address_1_field" data-priority="50"><label for="billing_address_1" class="required_field">Address&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_address_1" id="billing_address_1" placeholder="Street, building / villa no."  value="{{ old('billing_address_1', $prefill['line1'] ?? '') }}" required aria-required="true" autocomplete="section-billing billing address-line1" /></span></p>                        <div class="row2">
                            {{-- A text input, exactly as the live site. It was a select whose
                                 change handler reloaded the page, which made the field unusable. --}}
                            <p class="form-row form-row-wide validate-required validate-state" id="billing_state_field" data-priority="80"><label for="billing_state" class="required_field">Emirate&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " value="{{ old('billing_state', $prefill['state'] ?? '') }}"  placeholder="" name="billing_state" id="billing_state" required aria-required="true" autocomplete="section-billing billing address-level1" data-input-classes=""/></span></p>                            <p class="form-row form-row-wide validate-required" id="billing_city_field" data-priority="70"><label for="billing_city" class="required_field">City / area&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text " name="billing_city" id="billing_city" placeholder="e.g. Al Reem Island"  value="{{ old('billing_city', $prefill['city'] ?? '') }}" required aria-required="true" autocomplete="section-billing billing address-level2" /></span></p>                        </div>
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
                            <p class="form-row form-row-wide kbb-note" id="customer_note_field">
                                <label for="customer_note">Delivery notes <span class="optional">(optional)</span></label>
                                <span class="woocommerce-input-wrapper">
                                    <textarea name="customer_note" id="customer_note" class="input-text" rows="2" maxlength="600"
                                              placeholder="Delivery instructions, a landmark, a preferred time">{{ old('customer_note') }}</textarea>
                                </span>
                            </p>
                            @if (app(\App\Services\SettingsService::class)->get('gift_enabled', '1'))
                            <p class="form-row form-row-wide kbb-gift" id="gift_field">
                                @php
                                    $giftOn = app(\App\Services\SettingsService::class)->get('gift_enabled', '1');
                                    $giftFee = (int) app(\App\Services\SettingsService::class)->get('gift_fee', '1500');
                                @endphp
                                <label class="kbb-gift-opt" for="is_gift">
                                    <input type="checkbox" name="is_gift" id="is_gift" value="1" @checked(old('is_gift', session('kbb_gift')))>
                                    <span>This order is a gift</span>
                                    @if ($giftFee > 0)
                                        <b class="kbb-gift-fee">+{!! \App\Support\Money::format($giftFee) !!}</b>
                                    @endif
                                </label>
                                <span class="woocommerce-input-wrapper kbb-gift-msg" id="gift_note_wrap" hidden>
                                    <textarea name="gift_note" id="gift_note" class="input-text" rows="3" maxlength="600"
                                              placeholder="Your message, printed on the gift card">{{ old('gift_note') }}</textarea>
                                    <span class="kbb-gift-count"><span id="gift_left">600</span> characters left</span>
                                </span>
                            </p>
                            @endif
                    </div>

                    <!-- 4 · Payment -->
                    <div class="sec pay">
                        <h2><span class="n">4</span> Payment</h2>

                            <div class="express" aria-hidden="true">
                                <button type="button" class="xbtn xapple" tabindex="-1"> Apple&nbsp;Pay</button>
                                <button type="button" class="xbtn xgoogle" tabindex="-1"><b><span class="xg-b">G</span><span class="xg-o">o</span><span class="xg-y">o</span><span class="xg-b">g</span><span class="xg-gr">l</span><span class="xg-o">e</span></b>&nbsp;Pay</button>
                            </div>
                            <div class="ordiv">or pay with</div>

                        {{-- The list itself lives in its own partial: adding a
                             product from Browsed can move the order total
                             across the Cash-on-delivery window, and the refresh
                             has to render THIS markup rather than a second copy
                             of it. old('payment_method') is honoured for the
                             same reason it is on every other field here — a
                             rejected Place order should not silently reset the
                             choice. --}}
                        <div id="payment" class="woocommerce-checkout-payment">@include('partials.checkout.payment-methods', ['selectedMethod' => old('payment_method')])</div>
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

                        {{-- A stable container round the order block, for the
                             same reason .kbb-freeship-slot inside it has one:
                             the block is rendered twice (here and in the mobile
                             box), so it cannot carry an id, and the one-tap add
                             swaps every copy of it in one go. --}}
                        <div class="kbb-order-slot">@include('partials.checkout.order-block', ['withActions' => true])</div>                    </div>

                    @if ($showBrowsed)
                    <!-- Browsed panel -->
                    <div class="spanel" data-spanel="browsed">
                        {{-- The "Added" confirmation is pinned to this heading,
                             not to the row: a successful add replaces the list
                             below, which would take a note living on the row
                             with it. It is absolutely positioned so appearing
                             costs no height — nothing on the page moves. --}}
                        <p class="bhead">Recently browsed — add in one tap<span class="baddnote" id="kbbBrowsedNote" role="status" aria-live="polite"></span></p>
                        <div id="kbbBrowsedList" data-add-url="{{ Url::to('/checkout/browsed-add') }}">@include('partials.checkout.browsed-list')</div>
                    </div>
                    @endif

                    <div class="peekfade"></div>
                </div>

                <!-- mobile: expand/collapse the summary -->
                <button type="button" class="viewfull" id="kbbViewItems" aria-controls="kbbPanels" aria-expanded="false">View full summary ▾</button>
            </aside>

        </div>

        <!-- mobile-only: full on-page Place order box (sticky bar is optional) -->
        {{-- The mobile bag strip — "Your bag · N items", the round thumbnails
             with their ×n badges — and, immediately under it, the order block
             whose first element is the free-delivery progress bar. Both are in
             their own slots: a one-tap add from Browsed has to bring a new
             thumbnail, a new count and a moved progress bar with it, and a bar
             still reading 80% while the summary says "Free" is worse than one
             that never moved at all. --}}
        <div class="kbb-mobile-order">
            <div class="kbb-thumbs-slot">@include('partials.checkout.thumbs')</div><div class="kbb-order-slot">@include('partials.checkout.order-block', ['withActions' => true])</div>        </div>
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

@push('scripts')
<style>
/* Scoped to .kbb-checkout and built from the sheet's own tokens, so these
   rows inherit the same palette as every other field.

   Checkbox metrics copied from .kbb-wa rather than guessed: 17px box,
   margin-top 1px, flex-shrink 0, align-items flex-start. That row already
   sits correctly against a label that wraps to two lines, so matching it
   keeps every tick on this page on the same optical line instead of each
   one being a slightly different hand-tuned number.

   None of these may go inside .row2 -- that is a two-column grid, and a
   .form-row dropped into it becomes a grid child and interleaves with Email
   and Phone. */
.kbb-checkout .kbb-acct,
.kbb-checkout .kbb-gift,
.kbb-checkout .kbb-note{margin-top:10px}
/* label.<class>, not just .<class>: the sheet's own
   `.kbb-checkout .form-row label{display:block}` scores 0,2,1 and beat a bare
   0,2,0 selector, so display:flex never applied and the box sat inline on the
   text baseline -- which is why align-items did nothing at all. */
.kbb-checkout .form-row label.kbb-acct-opt,
.kbb-checkout .form-row label.kbb-gift-opt{display:flex;align-items:center;gap:9px;cursor:pointer;
  font-size:12px;font-weight:500;color:var(--ink-2);margin:0;line-height:1.45}
/* HIDDEN MEANS HIDDEN, ON EVERY ELEMENT OF THIS PAGE.

   The browser's own sheet says [hidden]{display:none}, and ANY author rule that
   sets display beats it, whatever its specificity -- author origin outranks
   user-agent origin before specificity is even looked at. This page is full of
   such rules: `.kbb-checkout .sumrow{display:flex}` and
   `.kbb-checkout .kbb-delivery-line{display:flex}` in kbb-checkout.css, and
   `.kbb-acct-pw`/`.kbb-gift-msg` below. So `hidden` did nothing at all on any
   element carrying one of them.

   It was fixed here once, for `.sumrow` alone, after the Gift wrapping row was
   found showing "AED 0.00" to every shopper who had not ticked "this order is a
   gift". Then partials/checkout/delivery-line.blade.php was changed to render
   the delivery line and hide it -- rendered and hidden precisely so the
   country-change refresh in checkout.js can reveal it -- and it landed on the
   same rake: a shopper in a country with no recorded delivery window got the
   bordered row and the truck icon with no words in it. Measured in Chromium at
   1280 and 390 before and after; see CheckoutHiddenRowsTest.

   One rule instead of a list of them, because the next element rendered-and-
   hidden under .kbb-checkout would otherwise be the third instance of one bug.
   `!important` because this is not a style preference that a later rule may
   reasonably overrule: the attribute states that the element is not to be
   rendered, and no rule on this page has a legitimate reason to say otherwise.
   (It is also not a tie this file can win on source order alone -- this block is
   pushed into the scripts stack, and a rule of equal specificity landing after
   it would take the element back.)

   `hidden="until-found"` would need content-visibility rather than display and
   is not used anywhere in this application; if it ever is, this rule has to
   learn about it. */
.kbb-checkout [hidden]{display:none!important}
.kbb-checkout .kbb-acct-opt input[type="checkbox"],
.kbb-checkout .kbb-gift-opt input[type="checkbox"]{width:17px;height:17px;margin-top:1px;
  accent-color:var(--pink);flex-shrink:0;margin-top:0}
.kbb-checkout .kbb-gift-fee{margin-left:7px;font-weight:700;color:var(--pink)}
.kbb-checkout .kbb-acct-pw,
.kbb-checkout .kbb-gift-msg{display:block;margin-top:9px}
.kbb-checkout .kbb-acct-err{display:block;margin-top:6px;font-size:12px;color:var(--pink)}
.kbb-checkout .kbb-note textarea,
.kbb-checkout .kbb-gift textarea{resize:vertical;min-height:62px}
.kbb-checkout .kbb-gift-count{display:block;margin-top:5px;font-size:11px;color:var(--muted);text-align:right}
</style>
<script>
/* The order summary's quantity endpoint, prefixed for this deployment.
   Url::to() rather than route(): the route lives in routes/checkout-line.php,
   which the integrator wires into routes/web.php, and a page that 500s because
   a route name is not registered yet is worse than a stepper that is not wired
   up yet. Published here rather than added to the layout's route list, which
   belongs to every page on the site and not just this one. */
window.KBB.routes.checkoutLine = @json(Url::to('/checkout/line'));
window.KBB.routes.checkoutCoupon = @json(Url::to('/checkout/coupon'));

(function () {
  var box = document.getElementById('create_account');
  var wrap = document.getElementById('account_password_wrap');
  if (!box || !wrap) return;
  function sync() {
    wrap.hidden = !box.checked;
    // Only required while the box is ticked, so an untouched checkout still
    // submits -- the server applies the same rule with required_if.
    var pw = document.getElementById('account_password');
    if (pw) { pw.required = box.checked; if (!box.checked) pw.value = ''; }
  }
  box.addEventListener('change', sync);
  sync();
})();

(function () {
  var gift = document.getElementById('is_gift');
  var wrap = document.getElementById('gift_note_wrap');
  var msg = document.getElementById('gift_note');
  var left = document.getElementById('gift_left');
  if (!gift || !wrap) return;
  function sync() {
    wrap.hidden = !gift.checked;
    // Cleared rather than merely hidden. The server drops it too when the box
    // is unticked, but a field the shopper cannot see should not still be
    // carrying their words.
    if (!gift.checked && msg) { msg.value = ''; }
    count();
  }
  function count() {
    if (msg && left) { left.textContent = String(600 - msg.value.length); }
  }
  gift.addEventListener('change', function () { sync(); price(); });
  if (msg) { msg.addEventListener('input', count); }

  // The fee comes back from the server, never added up here. The browser is
  // told what wrapping costs; it does not get to decide.
  var busy = false;
  function price() {
    if (busy) return;
    busy = true;
    var country = document.getElementById('billing_country');
    var state = document.getElementById('billing_state');
    fetch(@json(route('checkout.gift')), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': window.KBB.csrf,
        Accept: 'application/json'
      },
      body: JSON.stringify({
        is_gift: gift.checked ? 1 : 0,
        country: country ? country.value : 'AE',
        state: state ? state.value : ''
      })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { window.kbbToast && window.kbbToast(d.error || 'Could not update the total.'); return; }
        document.querySelectorAll('.js-gift-row').forEach(function (el) { el.hidden = !d.on; });
        document.querySelectorAll('.js-gift').forEach(function (el) { el.innerHTML = d.giftFee; });
        // Two copies of the totals exist -- summary column and mobile box.
        document.querySelectorAll('.js-total').forEach(function (el) { el.innerHTML = d.total; });
        if (d.totalWithFee) {
          document.querySelectorAll('.js-total-fee').forEach(function (el) { el.innerHTML = d.totalWithFee; });
        }
      })
      .catch(function () { window.kbbToast && window.kbbToast('Could not update the total.'); })
      .finally(function () { busy = false; });
  }

  sync();
})();
</script>
@endpush
@endsection
