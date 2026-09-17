{{--
    The account landing page for a signed-in shopper.

    $orders is now an Eloquent Collection rather than a plain array, so the
    empty check is ->isEmpty(). `empty($collection)` is always FALSE for an
    object, which would have silently swallowed the "nothing here yet" line and
    rendered an empty strip instead.
--}}
@extends('layouts.store')
@section('title', __('store.account.dashboard_title'))

@section('content')
@php use App\Support\Money; use App\Support\Url; @endphp

<div class="acw wide">
    <div class="acw-head">
        <span class="acw-av">{{ mb_strtoupper(mb_substr($customer->displayName(), 0, 1)) }}</span>
        <div><h1>{{ $customer->displayName() }}</h1>
            <p class="acw-sub">{{ $customer->email }}</p></div>
        <form method="post" action="{{ Url::to('/my-account/logout') }}" class="acw-outform">@csrf
            <button class="acw-out" type="submit">{{ __('store.account_panel.sign_out') }}</button></form>
    </div>

    <div class="acw-cards">
        <a class="acw-card" href="{{ Url::to('/my-account/orders/') }}"><b>{{ __('store.account.card_orders') }}</b><span>{{ __('store.account.card_orders_note') }}</span></a>
        <a class="acw-card" href="{{ Url::to('/my-wishlist/') }}"><b>{{ __('store.account.card_wishlist') }}</b><span>{{ __('store.account.card_wishlist_note') }}</span></a>
        @if (app(\App\Services\SettingsService::class)->moduleEnabled('address_book', true))<a class="acw-card" href="{{ Url::to('/my-account/edit-address/') }}"><b>{{ __('store.account.card_addresses') }}</b><span>{{ __('store.account.card_addresses_note') }}</span></a>@endif
        <a class="acw-card" href="{{ Url::to('/track-my-order/') }}"><b>{{ __('store.account.card_track') }}</b><span>{{ __('store.account.card_track_note') }}</span></a>
    </div>

    <h2 class="acw-h2">{{ __('store.account.recent_orders') }}</h2>
    @if ($orders->isEmpty())
        <p class="acw-empty">{!! __('store.account.orders_empty', ['link' => '<a href="' . e(Url::to('/shop/')) . '">' . e(__('store.account.orders_empty_link')) . '</a>']) !!}</p>
    @else
        <div class="acw-orders">
            @foreach ($orders as $order)
                <a class="acw-order" href="{{ Url::to('/my-account/orders/' . $order->id) }}">
                    <b>#{{ $order->order_number }}</b>
                    <span>{{ $order->created_at?->format('j M Y') ?? '' }}</span>
                    <span>{!! Money::format((int) $order->total) !!}</span>
                    <span class="acw-status">{{ ucfirst(str_replace('-', ' ', (string) ($order->status ?: 'pending'))) }}</span>
                </a>
            @endforeach
        </div>
        <p class="acw-empty" style="margin-top:12px"><a href="{{ Url::to('/my-account/orders/') }}">{{ __('store.account.see_all_orders') }}</a></p>
    @endif
</div>
@endsection
