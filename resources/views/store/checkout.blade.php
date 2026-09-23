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
@section('title', __('store.checkout.page_title'))

@push('styles')
    @vite('resources/css/kbb/kbb-checkout.css')
@endpush

@section('content')
@php
    /*
     * Appearance -> Checkout page. Resolved here rather than passed in, because
     * this template is reached from more than one place and a controller that
     * forgot the key would 500 the checkout rather than lose a slider.
     *
     * Both calls emit NOTHING while every control is at its default, so the
     * rendered element is byte for byte `<section class="kbb-checkout">` on a
     * shop that has never opened the screen.
     */
    $kbbCoPage = app(\App\Services\CheckoutPage::class);
@endphp
<section class="kbb-checkout{{ $kbbCoPage->bodyClass() }}"{!! $kbbCoPage->styleAttr() !!}>

    <!-- slim secure-checkout header (design .head) -->
    <header class="co-head"><div class="in">
        <a class="logo" href="{{ Url::to('/') }}">K-Beauty<span>Bliss</span></a>
        <span class="secure">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
            {{ __('store.checkout.secure_badge') }}        </span>
    </div></header>

    {{-- ONLY WHEN THERE IS SOMETHING TO SAY.
         This band rendered on every load, errors or not, and its 16px of top
         padding sat between the header and the page as permanently empty space
         -- half of the "empty spaces on top and bottom" the owner reported,
         and the half no slider could reach, because an empty div is not a
         margin anybody thinks to look at. With it gone, the gap under the
         header is Page padding - top on the Layout tab, which is a control. --}}
    @if ($errors->any())
    <div class="co-notices" style="max-width:1040px;margin:0 auto;padding:16px 20px 0">
        <div class="co-note err">{{ $errors->first() }}</div>
    </div>
    @endif

    <form name="checkout" method="post" class="checkout woocommerce-checkout" action="{{ Url::to('/checkout/place') }}" enctype="multipart/form-data" id="kbbCheckoutForm">
        @csrf
        <div class="co-grid">

            <!-- LEFT -->
            <div>
                <div class="co-titlebar">
                    <div class="co-titlebar-main">
                        <a class="backlink" href="{{ Url::to('/shop/') }}">{{ __('store.checkout.back_to_shop') }}</a>
                        <h1 class="co-h">{{ __('store.checkout.heading') }}</h1>
                        <p class="co-lead">{{ __('store.checkout.lead') }}</p>
                    </div>
                    @include('partials.checkout.back-to-cart')
                </div>

                <div class="coupon">
                    <div class="ch"><span class="gift">🎁</span> {{ __('store.checkout.coupon_prompt') }}</div>
                    <div class="crow">
                        <input type="text" name="coupon_code" class="input-text" id="kbb_coupon_code" placeholder="{{ __('store.checkout.coupon_placeholder') }}" autocomplete="off">
                        <button type="button" class="apply" id="kbb_apply_coupon">{{ __('store.checkout.coupon_apply') }}</button>
                    </div>
                    @include('partials.checkout.coupon-hint')                </div>

                <div class="formbox" id="customer_details">

                    <!-- 1 · Contact -->
                    <div class="sec">
                        <h2><span class="n">1</span> {{ __('store.checkout.step_contact') }}</h2>
{{-- NAME FIRST, then PHONE and EMAIL side by side, and all three required.

     The three things this order needs to reach a human, in the order someone
     says them. Phone leads the second row at the owner's instruction -- it is
     the field a UAE courier actually calls, and the one a shopper types
     fastest on a phone keyboard that is already numeric.

     The name used to open the Shipping address section, which was right while
     that section was fields the shopper typed. It is an address PICKER now,
     and a saved address carries no name -- the popup asks for Area, Apartment,
     City and Country and nothing else -- so a name field above a list of saved
     addresses would read as naming the address rather than the person.

     Phone is required on the server as well as here: see place()'s rules. A
     field marked required in the markup and nullable in the controller is not
     mandatory, it is a suggestion a script can skip, and the card door posts
     to the same endpoint.

     A MOVE, NOT A REWRITE. Both shapes of the name field, their validation
     priorities and their autocomplete tokens are unchanged, and
     `billing_first_name` is still what the form posts. --}}@if ($singleName ?? true)
{{-- autocomplete="name", not "given-name". This one box holds the
                                 whole name -- splitName() in the controller cuts it up -- and a
                                 browser told "given-name" fills it with the first name alone,
                                 leaving the surname to be typed by hand on a phone. --}}
                            <x-checkout.field name="billing_first_name" :label="__('store.checkout.field_full_name')" required
                                validate="validate-required" priority="10"
                                :placeholder="__('store.checkout.field_full_name_placeholder')"
                                autocomplete="section-billing billing name"
                                :value="old('billing_first_name', $prefill['name'] ?? '')" />
@else
                            {{-- Store → Ecommerce → Checkout → Form fields, off. The single field
                                 above is still what the backend sees when this is on — splitName()
                                 in the controller has accepted this shape all along; only the form
                                 itself never offered it. --}}
                            <div class="row2">
                                <x-checkout.field name="billing_first_name" :label="__('store.checkout.field_first_name')" required
                                    rowClass="form-row-first" validate="validate-required" priority="10"
                                    autocomplete="section-billing billing given-name"
                                    :value="old('billing_first_name', $prefill['first_name'] ?? '')" />

                                <x-checkout.field name="billing_last_name" :label="__('store.checkout.field_last_name')" required
                                    rowClass="form-row-last" validate="validate-required" priority="20"
                                    autocomplete="section-billing billing family-name"
                                    :value="old('billing_last_name', $prefill['last_name'] ?? '')" />
                            </div>
@endif
                        <div class="row2">
                            <x-checkout.field name="billing_phone" :label="__('store.checkout.field_phone')" type="tel" required
                                validate="validate-required validate-phone" priority="100"
                                :placeholder="__('store.checkout.field_phone_placeholder')" inputmode="tel"
                                autocomplete="section-billing billing tel"
                                :value="old('billing_phone', $prefill['phone'] ?? '')" />

                            <x-checkout.field name="billing_email" :label="__('store.checkout.field_email')" type="email" required
                                validate="validate-required validate-email" priority="1"
                                :placeholder="__('store.checkout.field_email_placeholder')" inputmode="email"
                                autocomplete="section-billing billing email"
                                :value="old('billing_email', $prefill['email'] ?? '')" />
                        </div>
{{-- THE THREE COMPOUND ROWS ON THIS PAGE STAY HAND-WRITTEN, deliberately.

     This one, the gift row below and the WhatsApp opt-in are not fields with a
     label above them: each is a tick that reveals or prices a second control,
     with its own wrapper classes, its own hidden state and -- here -- the only
     per-field error message on the checkout. Pushing them through
     x-checkout.field would mean teaching that component three shapes it has one
     caller each for, which is how a shared component becomes harder to read
     than the nine copies it replaced. The nine plain rows are the ones that
     were drifting, and they are the ones it renders. --}}
@guest('customer')
                            <p class="form-row form-row-wide kbb-acct" id="create_account_field">
                                <label for="create_account" class="kbb-acct-opt">
                                    <input type="checkbox" name="create_account" id="create_account" value="1" @checked(old('create_account'))>
                                    <span>{{ __('store.checkout.create_account') }}</span>
                                </label>
                                <span class="woocommerce-input-wrapper kbb-acct-pw" id="account_password_wrap" hidden>
                                    <input type="password" class="input-text" name="account_password" id="account_password"
                                           placeholder="{{ __('store.checkout.password_placeholder') }}" autocomplete="new-password" minlength="8">
                                </span>
                                @error('account_password')<span class="kbb-acct-err">{{ $message }}</span>@enderror
                            </p>
@endguest



                        <div class="co-note ok" id="kbbReturning" style="display:none"></div>
@if ($kbbCoPage->get('optin_on'))
                            {{-- UNTICKED unless the owner says otherwise. It shipped
                                 pre-ticked, so every order carried a consent nobody
                                 actively gave. old() still wins on a rejected submission,
                                 so a shopper who ticked it does not lose the tick. --}}
                            <div class="kbb-wa"><p class="form-row form-row-wide" id="billing_kbb_whatsapp_field" data-priority="25"><span class="woocommerce-input-wrapper"><label class="checkbox " ><input type="checkbox" name="billing_kbb_whatsapp" id="billing_kbb_whatsapp" value="1" class="input-checkbox " @checked(old('billing_kbb_whatsapp', $kbbCoPage->get('optin_checked'))) /> {{ __('store.checkout.whatsapp_optin') }}&nbsp;<span class="optional">{{ __('store.checkout.optional_note') }}</span></label></span></p></div>
@endif
                    </div>

                    <!-- 2 · Shipping address -->
                    <div class="sec">
                        <h2><span class="n">2</span> {{ __('store.checkout.step_shipping') }}</h2>
@include('partials.checkout-address')
                    </div>

                    <!-- 3 · Delivery -->
                    <div class="sec">
                        <h2><span class="n">3</span> {{ __('store.checkout.step_delivery') }}</h2>
                        @if (!empty($unservedCountry))
                            <p class="xd-unserved">{{ __('store.checkout.unserved_country', ['country' => \App\Support\Countries::NAMES[$unservedCountry] ?? $unservedCountry]) }}</p>
                        @endif
                        <div id="kbbDeliverySlot" class="kbb-delivery">
                            @include('partials.checkout.delivery-options')
                        </div>
                            @if ($kbbCoPage->get('notes_on'))
                            {{-- Not rendered rather than hidden, so nothing posts
                                 customer_note while it is off. place() has always
                                 treated it as nullable, so this needs no branch there. --}}
                            <x-checkout.field name="customer_note" :label="__('store.checkout.field_notes')" type="textarea" optional
                                rowClass="form-row-wide kbb-note" rows="2" maxlength="600"
                                :placeholder="__('store.checkout.field_notes_placeholder')"
                                :value="old('customer_note')" />
                            @endif
                            @if (app(\App\Services\SettingsService::class)->get('gift_enabled', '1'))
                            <p class="form-row form-row-wide kbb-gift" id="gift_field">
                                @php
                                    $giftOn = app(\App\Services\SettingsService::class)->get('gift_enabled', '1');
                                    $giftFee = (int) app(\App\Services\SettingsService::class)->get('gift_fee', '1500');
                                @endphp
                                <label class="kbb-gift-opt" for="is_gift">
                                    <input type="checkbox" name="is_gift" id="is_gift" value="1" @checked(old('is_gift', session('kbb_gift')))>
                                    <span>{{ __('store.checkout.gift_option') }}</span>
                                    @if ($giftFee > 0)
                                        <b class="kbb-gift-fee">+{!! \App\Support\Money::format($giftFee) !!}</b>
                                    @endif
                                </label>
                                <span class="woocommerce-input-wrapper kbb-gift-msg" id="gift_note_wrap" hidden>
                                    <textarea name="gift_note" id="gift_note" class="input-text" rows="3" maxlength="600"
                                              placeholder="{{ __('store.checkout.gift_note_placeholder') }}">{{ old('gift_note') }}</textarea>
                                    <span class="kbb-gift-count">{!! trans_choice('store.checkout.gift_characters_left', 600, ['remaining' => '<span id="gift_left">600</span>']) !!}</span>
                                </span>
                            </p>
                            @endif
                    </div>

                    <!-- 4 · Payment -->
                    <div class="sec pay">
                        <h2><span class="n">4</span> {{ __('store.checkout.step_payment') }}</h2>

                            <div class="express" aria-hidden="true">
                                <button type="button" class="xbtn xapple" tabindex="-1"> Apple&nbsp;Pay</button>
                                <button type="button" class="xbtn xgoogle" tabindex="-1"><b><span class="xg-b">G</span><span class="xg-o">o</span><span class="xg-y">o</span><span class="xg-b">g</span><span class="xg-gr">l</span><span class="xg-o">e</span></b>&nbsp;Pay</button>
                            </div>
                            <div class="ordiv">{{ __('store.checkout.or_pay_with') }}</div>

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
                    <button type="button" class="stab on" data-stab="summary">{{ __('store.checkout.tab_summary') }}</button>
                    <button type="button" class="stab" data-stab="browsed">{{ __('store.checkout.tab_browsed') }} <span class="bcount">{{ $browsed->count() }}</span></button>
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
                        <p class="bhead">{{ __('store.checkout.browsed_heading') }}<span class="baddnote" id="kbbBrowsedNote" role="status" aria-live="polite"></span></p>
                        <div id="kbbBrowsedList" data-add-url="{{ Url::to('/checkout/browsed-add') }}">@include('partials.checkout.browsed-list')</div>
                    </div>
                    @endif

                    <div class="peekfade"></div>
                </div>

                <!-- mobile: expand/collapse the summary -->
                <button type="button" class="viewfull" id="kbbViewItems" aria-controls="kbbPanels" aria-expanded="false">{{ __('store.checkout.view_summary_open') }}</button>
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
        <div><div class="ml">{{ __('store.checkout.total') }}</div><div class="mt js-total">{!! Money::format($totals['total']) !!}</div></div>
        <button type="button" class="mb" data-place="1">{{ __('store.checkout.place_order') }}</button>
    </div>
    @endif
    </section>

{{--
    The card fields' script is appended to the SCRIPTS PUSH BELOW rather than
    given a push of its own, and where it sits on the line is load-bearing
    rather than tidy. Both reasons are about bytes, which
    StorefrontEnglishUnchangedTest compares: a shop with no card gateway
    configured must render a checkout identical to the one it had before this
    package, and "identical" is not a thing to be argued with afterwards.

    A @push block of its own adds two blank lines before </main>, because the
    directives emit nothing but the newlines around them are literal template
    text.

    And this @include goes on ITS OWN LINE, not appended to the one above.
    Blade's echo compiler pads `{!! … !!}` with a newline to keep line numbers,
    and PHP then eats the one after the closing `?>` — so the pair nets out to
    the single newline the source had. Butting a directive straight onto the
    end of that echo puts `<?php` where the padding would go, the padding is
    not emitted, and the line silently loses its newline. Measured, not
    assumed: it moved a byte on the rendered checkout and the walk caught it.

    The partial draws nothing at all unless Stripe is on offer with a
    publishable key, so such a shop also loads no Stripe script. The mount box
    it drives is partials/checkout/stripe-card, which the payment list renders
    inside the card option's own .payment_box.

    This comment's closing marker is glued to @push for the first reason above:
    Blade strips the comment and leaves the newline that followed it.
--}}@push('scripts')
@include('partials.checkout.inline-validation', ['validation' => \App\Support\InlineValidation::config(app(\App\Services\SettingsService::class))]){!! app(\App\Services\MarketingPixels::class)->beginCheckout((int) $totals['total']) !!}
@include('partials.checkout.stripe-elements')
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
.kbb-checkout .kbb-gift-fee{margin-inline-start:7px;font-weight:700;color:var(--pink)}
.kbb-checkout .kbb-acct-pw,
.kbb-checkout .kbb-gift-msg{display:block;margin-top:9px}
.kbb-checkout .kbb-acct-err{display:block;margin-top:6px;font-size:12px;color:var(--pink)}
.kbb-checkout .kbb-note textarea,
.kbb-checkout .kbb-gift textarea{resize:vertical;min-height:62px}
.kbb-checkout .kbb-gift-count{display:block;margin-top:5px;font-size:11px;color:var(--muted);text-align:end}
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
{{-- The delivery-address sheet, the same one the cart page opens. It sits at
     the very foot of the page and outside every box that scrolls or clips, for
     the reasons its own header sets out. --}}
@include('partials.address-sheet')
@endsection
