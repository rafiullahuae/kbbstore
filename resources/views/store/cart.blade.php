@extends('layouts.store')
@php use App\Support\Url; @endphp

@section('title', __('store.cart.page_title'))

@push('styles')
    @vite('resources/css/kbb/kbb-cart.css')
@endpush

@section('content')
{{-- Wrapper matches the theme's cart page shell (kbb-cart.css .kbb-cartpage). --}}
<div class="kbb-cartpage" id="cartPage">
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
@endsection
