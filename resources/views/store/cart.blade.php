@extends('layouts.store')
@php use App\Support\Url; @endphp
@php
    /*
     * Appearance → Cart page (Lane: cart-page).
     *
     * $kbbCartPage is resolved HERE and handed down to store.cart-inner through
     * the view data it already inherits, so the two halves of this page can
     * never disagree about which layout they are drawing. It is read out of the
     * container rather than passed in from CartController because that
     * controller belongs to another lane, and because it is how a dozen
     * storefront views already resolve a settings service — see the
     * $kbbCartCoupon block in cart-inner.blade.php.
     *
     * AT COLUMN 0, WITH NO BLANK LINE INSIDE IT. Blade compiles a raw-PHP block
     * to one <?php ?> and PHP swallows the single newline that follows, so a
     * block written this way contributes ZERO BYTES to the rendered page. That
     * is what StorefrontEnglishUnchangedTest, which compares this page against
     * the same page at the base commit byte for byte, requires — and on the
     * classic layout, which is how this ships, bodyClass() and styleAttr() both
     * return the empty string, so the <div> below is byte-identical too.
     */
    $kbbCartPage = app(\App\Services\CartPage::class);
@endphp
@php
/*
 * NO FOOTER ON THE CART PAGE — Appearance → Cart page → "Show the site footer
 * on the cart page", which ships OFF because the owner asked for it off.
 *
 * This is the one control on this screen whose default does NOT reproduce
 * today's rendering. Every other default on the cart page screen was chosen so
 * that applying the package changes the shop by zero bytes; this one changes
 * the cart page on purpose, and only the cart page.
 *
 * DECLARED HERE, OUTSIDE the `squeezed()` branch further down, so it governs
 * the classic page and the squeezed page alike. `layout` ships `classic`, so a
 * switch wired into the squeezed markup would reach almost no shop.
 *
 * The mechanism is a SECTION rather than a variable or a view composer.
 * layouts/store.blade.php is rendered after this file and does not share its
 * local scope — @php variables here never reach it — while a composer on the
 * layout would run for every page in the shop to answer a question only this
 * one asks. A section is recorded by this template and read by that one, and
 * no other template declares it, so no other page can lose its footer. It is
 * the same mechanism the layout already uses for 'bare'.
 *
 * Two-argument @section, so there is nothing to @endsection and nothing is
 * emitted. At column 0 with no blank line inside, per the block above: this
 * costs the page zero bytes either way, and what removes the footer is the
 * layout not including it.
 */
@endphp
@unless ($kbbCartPage->get('footer_on'))
@section('no-footer', '1')
@endunless

@section('title', __('store.cart.page_title'))

@push('styles')
    @vite('resources/css/kbb/kbb-cart.css')
@endpush

@section('content')
{{-- Wrapper matches the theme's cart page shell (kbb-cart.css .kbb-cartpage). --}}
<div class="kbb-cartpage{{ $kbbCartPage->bodyClass() }}" id="cartPage"{!! $kbbCartPage->styleAttr() !!}>
    <div class="wrap">
        <a class="backlink" href="{{ Url::to('/shop/') }}">{{ __('store.cart.continue_shopping') }}</a>
        {{-- The count sits in the heading rather than on a line of its own.

             The class is `cart-count`, NOT `lead`. `.lead` in kbb.css is the
             form-field leading-icon class: `position:absolute; left:13px;
             top:50%; transform:translateY(-50%)`. A span carrying it inside an
             h1 that is not positioned gets taken out of flow and anchored to
             the nearest positioned ancestor, which is how "(14 items)" ended up
             floating in the middle-left of the cart page on a phone.

             The id stays `cartLead` so cart.js (Lane R) keeps updating the same
             element; only the class is renamed. --}}
        <h1>{{ __('store.cart.heading') }} <span class="cart-count" id="cartLead">({{ trans_choice('store.cart.item_count', $totals['item_count']) }})</span></h1>
        <div id="kbbCartNotices"></div>
        <div id="cartInner">
            @include('store.cart-inner')
        </div>

        {{-- "Email me a reminder about this basket" (Lane EN).

             OUTSIDE #cartInner deliberately. cart.js replaces the contents of
             that div wholesale whenever a quantity changes or a line is
             removed, so a form living inside it would be torn out and rebuilt
             mid-typing, losing whatever the shopper had entered. Out here it
             survives every cart edit.

             Renders nothing unless the abandoned_cart module is on AND the
             owner has written the line beside the tick box — both ship as they
             ship, so applying the package changes this page by exactly nothing.
             partials/cart-reminder.blade.php carries the reasoning. --}}
        @include('partials.cart-reminder', ['reminderLabel' => app(\App\Services\CartRecovery::class)->optInLabel()])
    </div>
</div>
@if ($kbbCartPage->squeezed())
{{-- The address sheet and the squeezed page's stylesheet.

     EVERY DIRECTIVE IN THIS BLOCK STARTS AT COLUMN 0, including the @if above
     and the @endif below, and that is load-bearing rather than untidy. Blade
     compiles each one to a bare <?php ?> and PHP swallows the single newline
     after it, so a directive written this way contributes zero bytes — but the
     INDENTATION in front of it is ordinary text and would be emitted whatever
     the condition said. Indented by four spaces, this block would add four
     spaces and a newline to every classic cart page in the shop, and
     StorefrontEnglishUnchangedTest compares this page byte for byte.

     THE SHEET IS A PARTIAL because the checkout has the same one, over the
     same addresses: a signed-out shopper's three live in the session and a
     signed-in one's in `addresses`, and both pages reach that state through
     the same /cart/address endpoints. An address added here is already at the
     checkout, and the other way round, with nothing syncing anything.
     partials/address-sheet.blade.php carries the rest of the reasoning,
     including why it has to sit outside this page's scrolling box.

     THE SLIM FOOTER IS ON ONE LINE BELOW, AND THAT IS NOT A STYLE CHOICE.
     Appearance -> Footer -> Where it shows -> cart page ships OFF, so this
     page has to render byte for byte as it did -- which
     StorefrontEnglishUnchangedTest compares. Written across four lines the
     block emitted a single stray newline before </main> even with the
     condition false, because a Blade comment is removed and leaves its
     newline behind while PHP only swallows the one directly after a `?>`.
     One line, unindented, emits nothing at all. Its condition sits outside
     the @if above so it reaches the classic cart as well as the squeezed
     one. --}}
@include('store.cart-squeeze')
@include('partials.address-sheet')
@endif
@if (app(\App\Services\SlimFooter::class)->onCart())@include('partials.slim-footer')@endif
@endsection
