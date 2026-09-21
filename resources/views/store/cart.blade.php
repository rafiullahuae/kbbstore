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
{{-- The address sheet, and the stylesheet and script behind the squeezed page.

     EVERY DIRECTIVE IN THIS BLOCK STARTS AT COLUMN 0, including the @if above
     and the @endif below, and that is load-bearing rather than untidy. Blade
     compiles each one to a bare <?php ?> and PHP swallows the single newline
     after it, so a directive written this way contributes zero bytes — but the
     INDENTATION in front of it is ordinary text and would be emitted whatever
     the condition said. Indented by four spaces, this block would add four
     spaces and a newline to every classic cart page in the shop, and
     StorefrontEnglishUnchangedTest compares this page byte for byte.

     THE SHEET SITS OUTSIDE .kbb-cartpage ALTOGETHER, and that placement is the
     fix for a real defect rather than a preference. Nested inside the page's
     own scrolling box, a bottom sheet positioned against `bottom:0` resolves
     against the CONTENT box and not the visible one — so on a long cart it
     parks itself at the bottom of all the content and the docked bars, which
     paint later, come up through the middle of it. The trust row was seen
     cutting across the open form. No z-index fixes that: it is a
     containing-block problem wearing a stacking-order costume. Out here, with
     `position:fixed`, `bottom:0` means the bottom of the screen, which is what
     a bottom sheet has always meant.

     It matters twice over, because .kbb-cartpage.cpg-squeeze carries
     `overflow-x:clip` for the full-bleed rail, and an element with clipped
     overflow clips fixed-position descendants too.

     It is also outside #cartInner, for the reason the reminder form above it
     is: cart.js replaces that div's contents wholesale whenever a quantity
     changes, so a sheet living inside it would be torn out from under a
     shopper mid-typing.

     It is EMPTY in the markup. Its contents come from the one fetch it makes
     when it opens, so a page nobody taps the button on carries no address list
     at all — a saved address is somebody's home, and it does not belong in the
     HTML of a page they have not asked for it on.

     The × is a SIBLING of the sheet rather than a child: it floats above it,
     clear of the content, so a thumb reaching for it never lands on an address
     by accident. --}}
@php [$kbbSheetClass, $kbbSheetStyle] = $kbbCartPage->sheetAttrs(); @endphp
{{-- One wrapper around all three, carrying the popup's custom properties.

     It has to be here rather than on .kbb-cartpage, because the three elements
     below are no longer inside that element and inherit nothing from it. The
     wrapper itself is an ordinary static box with three fixed children, so it
     lays nothing out and establishes no containing block — it exists only so
     the variables have one place to be declared. --}}
<div class="cpg-portal{{ $kbbSheetClass }}"{!! $kbbSheetStyle !!}>
    <div class="cpg-scrim" id="cpgScrim"></div>
    <button class="cpg-x" id="cpgX" type="button" aria-label="{{ __('store.quick_view.close_label') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <div class="cpg-sheet" id="cpgSheet" role="dialog" aria-modal="true" aria-label="{{ $kbbCartPage->get('sheet_list_title') }}" hidden></div>
</div>
@include('store.cart-squeeze')
@endif
@endsection
