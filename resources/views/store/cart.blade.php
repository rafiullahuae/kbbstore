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
        {{-- The count sits in the heading rather than on a line of its own. The
             id stays on the span so cart.js keeps updating the same element. --}}
        <h1>Your Bag <span class="lead" id="cartLead">({{ $totals['item_count'] }} {{ $totals['item_count'] === 1 ? 'item' : 'items' }})</span></h1>
        <div id="kbbCartNotices"></div>
        <div id="cartInner">
            @include('store.cart-inner')
        </div>
    </div>
</div>
@endsection
