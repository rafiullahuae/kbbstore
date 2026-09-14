@extends('layouts.store')
@section('title', 'My account')

@section('content')
@php use App\Support\Url; @endphp

<div class="acw wide">
    <div class="acw-head">
        <span class="acw-av">{{ mb_strtoupper(mb_substr($customer->name ?? 'K', 0, 1)) }}</span>
        <div><h1>{{ $customer->name ?? 'My account' }}</h1>
            <p class="acw-sub">{{ $customer->email ?? '' }}</p></div>
        <form method="post" action="{{ Url::to('/my-account/logout') }}" class="acw-outform">@csrf
            <button class="acw-out" type="submit">Sign out</button></form>
    </div>

    <div class="acw-cards">
        <a class="acw-card" href="{{ Url::to('/my-account/orders/') }}"><b>Orders</b><span>Everything you have ordered</span></a>
        <a class="acw-card" href="{{ Url::to('/my-wishlist/') }}"><b>Wishlist</b><span>Saved for later</span></a>
        @if (app(\App\Services\SettingsService::class)->moduleEnabled('address_book', true))<a class="acw-card" href="{{ Url::to('/my-account/edit-address/') }}"><b>Addresses</b><span>Where we deliver</span></a>@endif
        <a class="acw-card" href="{{ Url::to('/track-my-order/') }}"><b>Track an order</b><span>Where your parcel is</span></a>
    </div>

    <h2 class="acw-h2">Recent orders</h2>
    @if (empty($orders))
        <p class="acw-empty">Nothing here yet. <a href="{{ Url::to('/shop/') }}">Start shopping</a>.</p>
    @else
        <div class="acw-orders">
            @foreach ($orders as $order)
                <a class="acw-order" href="{{ Url::to('/my-account/orders/' . $order->id) }}">
                    <b>#{{ $order->order_number ?? $order->id }}</b>
                    <span>{{ $order->created_at ?? '' }}</span>
                    <span class="acw-status">{{ ucfirst((string) ($order->status ?? 'pending')) }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
