@extends('layouts.store')
@php use App\Support\Url; @endphp

@section('title', 'Cart · K-Beauty Bliss')

@push('styles')
    @vite('resources/css/kbb/kbb-cart.css')
@endpush

@section('content')
{{-- Wrapper matches the theme's cart page shell (kbb-cart.css .kbb-cartpage). --}}
<div class="kbb-cartpage" id="cartPage">
    <div class="wrap">
        <a class="back" href="{{ Url::to('/shop/') }}">← Continue shopping</a>
        {{-- The count sits in the heading rather than on a line of its own.

             The class is `cart-count`, NOT `lead`. `.lead` in kbb.css is the
             form-field leading-icon class: `position:absolute; left:13px;
             top:50%; transform:translateY(-50%)`. A span carrying it inside an
             h1 that is not positioned gets taken out of flow and anchored to
             the nearest positioned ancestor, which is how "(14 items)" ended up
             floating in the middle-left of the cart page on a phone.

             The id stays `cartLead` so cart.js (Lane R) keeps updating the same
             element; only the class is renamed. --}}
        <h1>Your Bag <span class="cart-count" id="cartLead">({{ $totals['item_count'] }} {{ $totals['item_count'] === 1 ? 'item' : 'items' }})</span></h1>
        <div id="kbbCartNotices"></div>
        <div id="cartInner">
            @include('store.cart-inner')
        </div>
    </div>
</div>
@endsection
