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

@php
    /*
     * THE WIDTH EVERY ROW BELOW PRINTS AT — Lane FA.
     *
     * This ledger is where the lane started. A basket of AED 90.40 carrying a
     * 60-fil discount printed "Subtotal AED 90 / − AED 1 / Total AED 90",
     * because Money::displayDecimals() is 0 on this store and each row was
     * rounded on its own on the way to the screen.
     *
     * The whole-dirham policy removes the cause for everything the owner sets,
     * so in the ordinary case every figure here is a whole dirham, this is 0,
     * and the shop looks exactly as he asked. When a basket still holds a price
     * from before the policy, the SAME call widens the whole column at once and
     * the figures sum again. Honest first; tidy where honest allows.
     *
     * THE TWO FEES ARE READ FROM SETTINGS HERE rather than from $codFeeFils /
     * $giftFeeFils below — those are computed further down this file and would
     * be undefined at this point, and the width has to be settled before the
     * first row is printed. Neither can actually widen anything: both are
     * settings the whole-dirham rule refuses unless they are whole. They are
     * passed anyway, because a column must never be narrower than a figure it
     * contains.
     *
     * checkout.js assigns the strings this side formats and does no arithmetic
     * of its own, so the live-updating rows cannot disagree with these — the
     * same width reaches them through CheckoutController's JSON.
     *
     * AT COLUMN 0, WITH NO BLANK LINE AROUND IT: Blade compiles a raw-PHP block to
     * one <?php ?> and PHP swallows the newline after it, so written this way
     * the block contributes exactly zero bytes to the rendered page. That is
     * what StorefrontEnglishUnchangedTest, which compares this partial byte for
     * byte, requires.
     */
    $kbbLedgerDp = app(\App\Services\CartService::class)->ledgerDecimals(
        $totals,
        (int) $settings->get('cod_fee', 0),
        (int) $settings->get('gift_fee', '1500'),
    );
@endphp
<div class="sumrow"><span>{{ __('store.checkout.subtotal') }}</span><span class="js-subtotal">{!! \App\Support\Money::format($totals['subtotal'], $kbbLedgerDp) !!}</span></div>

<div class="js-coupons">
    @if ($totals['discount'])
        <div class="sumrow disc"><span>{{ $totals['coupon_code'] }}</span><span>&ndash; {!! \App\Support\Money::format($totals['discount'], $kbbLedgerDp) !!}</span></div>
    @endif
</div>

<div class="sumrow"><span>{{ __('store.checkout.delivery') }}</span><span class="js-shipping">@if ($totals['shipping'] > 0){!! \App\Support\Money::format($totals['shipping'], $kbbLedgerDp) !!}@else<span style="color:var(--green);font-weight:700">{{ __('store.checkout.free') }}</span>@endif</span></div>

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
<div class="sumrow js-gift-row"@if ($giftFeeFils <= 0) hidden @endif><span>{{ __('store.checkout.gift_wrapping') }}</span><span class="js-gift">{!! \App\Support\Money::format($giftFeeFils, $kbbLedgerDp) !!}</span></div>

@if ($codFeeFils > 0)
{{-- Visible only while Cash on delivery is the selected option — pure CSS,
     via :has() on the page's outer wrapper, the same technique already
     driving the selected-option highlight on the payment list itself. No JS
     needed for this part; the country-change refresh in checkout.js keeps
     the number itself correct (see js-total-fee below).

     AND THIS ONE STAYS CSS, deliberately — Lane DE. Everything else on this
     page that is rendered-and-hidden now uses the `hidden` attribute, but
     `hidden` states a fact about one moment and this row's visibility is a
     live function of which payment radio is checked. Expressing it with an
     attribute would mean adding JavaScript to something that already works
     with none, on the one part of the checkout where a stale bundle would
     leave a shopper looking at the wrong Total. --}}
<div class="sumrow js-fee-row"><span>{{ __('store.checkout.cod_fee') }}</span><span class="js-fee">{!! \App\Support\Money::format($codFeeFils, $kbbLedgerDp) !!}</span></div>
@endif

{{-- THE TAX ROW THAT IS PART OF THE SUM.

     Drawn ABOVE the Total, because on an exclusive basis the tax was ADDED and
     this column of figures has to add up to what is charged. The matching row
     below the Total is the "of which" note for an inclusive or printed-only
     basis, where the tax is a portion OF the total rather than an addition to
     it. Exactly one of the two is ever visible.

     BOTH ARE RENDERED AND ONE IS HIDDEN, never omitted, for the same reason
     .js-gift-row above is: the country-change refresh switches between them,
     and an element that is not in the page cannot be unhidden. Changing the
     destination from an inclusive country to an exclusive one has to move the
     line as well as its figure.

     HIDDEN WITH THE ATTRIBUTE, like the gift row above it and the delivery line
     below — Lane DE.

     These two carried `style="display:none"` instead, and had to: an author
     rule (`.kbb-checkout .sumrow{display:flex}`) beats the user agent's
     `[hidden]{display:none}` whatever its specificity, so the attribute did
     nothing on this page. store/checkout.blade.php now ships
     `.kbb-checkout [hidden]{display:none!important}`, and an author !important
     declaration outranks an inline one, so `hidden` is the stronger of the two
     mechanisms here as well as the clearer one. This partial now hides rows
     exactly one way.

     THE PAYMENT-DRIVEN ROWS BELOW ARE DELIBERATELY NOT CONVERTED. `.js-fee-row`
     and the two Total rows are shown and hidden by kbb-checkout.css through
     `:has(#payment_method_cod:checked)` — a live function of what the shopper
     has selected, with no JavaScript in the loop at all. An attribute is a
     static fact about one moment; it cannot express "while that radio is
     checked", and replacing the selector with script would be adding JavaScript
     to something that works without any.

     resources/js/kbb/checkout.js sets `.hidden` on these rows to match. THE TWO
     MUST SHIP TOGETHER: the compiled bundle under public/build is what runs on
     the server, so this Blade change needs the rebuilt asset in the same
     package. CheckoutHiddenRowsTest pins both halves in source. --}}
<div class="sumrow vat js-vat-row vat-add"@if (! ($totals['vat'] && $totals['vat']['added'])) hidden @endif><span class="js-vat-label">{{ $totals['vat']['label'] ?? '' }}</span><span class="js-vat">{!! $totals['vat']['formatted'] ?? '' !!}</span></div>
<div class="sumrow tot js-total-row"><span>{{ __('store.checkout.total') }}</span><span class="js-total">{!! \App\Support\Money::format($totals['total'] + $giftFeeFils, $kbbLedgerDp) !!}</span></div>
{{-- THE COD TOTAL IS NOT CONDITIONAL ON THERE BEING A COD FEE.

     kbb-checkout.css hides `.js-total-row` and shows `.js-total-row-fee`
     whenever #payment_method_cod is checked — unconditionally, because CSS
     cannot see what the fee is. While this row was only rendered for a fee
     above zero, a shop taking cash on delivery with no surcharge showed the
     shopper a checkout with NO TOTAL AT ALL: subtotal, delivery, VAT, then
     straight to Place order. Measured in Chromium at 390px and 1280px.

     With a fee of zero the two rows simply carry the same number, which is the
     truth, and exactly one of them is ever on screen. --}}
<div class="sumrow tot js-total-row-fee"><span>{{ __('store.checkout.total') }}</span><span class="js-total-fee">{!! \App\Support\Money::format($totals['total'] + $codFeeFils + $giftFeeFils, $kbbLedgerDp) !!}</span></div>

{{-- THE "OF WHICH" NOTE, under the Total and not part of it.

     What this row meant under D-64 — a VAT figure printed beside a total it
     never altered — is what an INCLUSIVE basis still means: the tax is already
     inside the prices above. A "printed only" basis means it as well, and
     charges nothing. An EXCLUSIVE basis does not, and takes the row above the
     Total instead.

     THE LABEL CARRIES THE RATE AND SO NEEDS ITS OWN HOOK. vat_label is
     "You're paying VAT ({rate}%)" with {rate} substituted by
     VatDisplay::label(), and the rate differs per country. The country-change
     refresh updated `.js-vat` — the amount — and nothing else, which was
     invisible while one global rate applied everywhere. With a Saudi rate set,
     switching country moved the figure to 13.04 and left "You're paying VAT
     (5%)" printed beside it: a receipt contradicting itself. Both halves move
     together. --}}
<div class="sumrow vat js-vat-row vat-note"@if (! ($totals['vat'] && ! $totals['vat']['added'])) hidden @endif><span class="js-vat-label">{{ $totals['vat']['label'] ?? '' }}</span><span class="js-vat">{!! $totals['vat']['formatted'] ?? '' !!}</span></div>

@if ($withActions ?? true)
    @include('partials.checkout.legal-notice')
    <button type="button" class="place" data-place="1">{{ __('store.checkout.place_order') }}</button>
    {{-- "100% authentic" WAS A LITERAL HERE — Lane DR.

         The last sentence a shopper reads before pressing Place order, on the
         one page where being wrong costs money, and nobody at the shop had
         approved it or could remove it: it lived in this template, and this
         template only changes by signed package.

         It is App\Support\TrustClaims now, defaulting to exactly this wording,
         so the button looks the same today as it did yesterday. Cleared in the
         admin, the whole chip goes — icon included — rather than leaving a tick
         with nothing after it.

         "SSL secure" is left alone deliberately: it is a fact about the
         connection this page was served over, not a claim about the business. --}}
    @php($checkoutAuth = \App\Support\TrustClaims::text($settings, 'checkout_authentic_text'))
    <div class="trust">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg> {{ __('store.checkout.ssl_secure') }}</span>
        @if ($checkoutAuth !== null)
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg> {{ $checkoutAuth }}</span>
        @endif
    </div>
    @include('partials.checkout.delivery-line')
@endif
