{{--
    The account landing page for a signed-in shopper (Lane ACC redesign).

    Top: back to the shop, the title, the dashboard button and the account
    menu (partials.account.chrome). Then a hello card with three numbers, the
    latest order with its photos and steps, and every part of the account as a
    big row, Change password among them. On a computer the account rows sit
    beside the hello card; on a phone everything is one column.

    $latest is the newest order (or null) with its lines and photos loaded;
    $orderCount / $liveCount / $wishCount come from AccountController::index().
--}}
@extends('layouts.store')
@section('title', __('store.account.dashboard_title'))

@section('content')
@php
    use App\Support\AccountIcons;
    use App\Support\Money;
    use App\Support\OrderProgress;
    use App\Support\Url;
    $first = trim((string) ($customer->first_name ?: strtok($customer->displayName(), ' @')));
    $rows = [
        ['/my-account/orders/', 'box', __('store.account.nav_orders'), __('store.account.row_orders_note'), ''],
        ['/my-wishlist/', 'heart', __('store.account.nav_wishlist'), __('store.account.row_wishlist_note'), ''],
    ];
    if (app(\App\Services\SettingsService::class)->moduleEnabled('address_book', true)) {
        $rows[] = ['/my-account/edit-address/', 'pin', __('store.account.nav_addresses'), __('store.account.row_addresses_note'), ''];
    }
    $rows[] = ['/my-account/profile/', 'user', __('store.account.nav_profile'), __('store.account.row_profile_note'), ''];
    $rows[] = ['/my-account/profile/#password', 'lock', __('store.account.row_password'), __('store.account.row_password_note'), ' g'];
    $rows[] = ['/track-my-order/', 'truck', __('store.account.nav_track'), __('store.account.row_track_note'), ''];
@endphp
<div class="acw wide kac">
    @include('partials.account.chrome', ['kacTitle' => __('store.account.dashboard_title'), 'kacBack' => '/', 'kacBackLabel' => __('store.account.back_to_shop'), 'kacActive' => 'dash'])

    <div class="kac-dash">
        <div>
            <div class="kac-card">
                <div class="kac-hello">
                    <span class="kac-av" aria-hidden="true">{{ mb_strtoupper(mb_substr($customer->displayName(), 0, 1)) }}</span>
                    <div style="min-width:0"><b>{{ __('store.account.hello', ['name' => $first !== '' ? $first : $customer->displayName()]) }}</b>
                        <small>{{ $customer->email }}@if ($customer->created_at) · {{ __('store.account.member_since', ['year' => $customer->created_at->format('Y')]) }}@endif</small></div>
                </div>
                <div class="kac-stats">
                    <a class="kac-stat" href="{{ Url::to('/my-account/orders/') }}"><b>{{ $orderCount }}</b><span>{{ __('store.account.stat_orders') }}</span></a>
                    <a class="kac-stat" href="{{ Url::to('/my-account/orders/') }}"><b>{{ $liveCount }}</b><span>{{ __('store.account.stat_in_progress') }}</span></a>
                    <a class="kac-stat" href="{{ Url::to('/my-wishlist/') }}"><b>{{ $wishCount }}</b><span>{{ __('store.account.stat_wishlist') }}</span></a>
                </div>
            </div>

            <h2 class="kac-h2">{{ __('store.account.latest_order') }}@if ($latest)<a href="{{ Url::to('/my-account/orders/') }}">{{ __('store.account.all_orders') }}</a>@endif</h2>
            @if ($latest)
                <div class="kac-rows kac-one">@include('partials.account.order-row', ['order' => $latest])</div>
                @php $steps = OrderProgress::steps($latest); @endphp
                @if ($steps !== [])
                    <div class="kac-card" style="margin-top:14px">@include('partials.account.steps', ['steps' => $steps])</div>
                @endif
            @else
                <div class="kac-card"><p class="kac-empty">{{ __('store.account.no_orders_yet') }} <a href="{{ Url::to('/shop/') }}">{{ __('store.account.start_shopping') }}</a></p></div>
            @endif
        </div>

        <div class="kac-side">
            <h2 class="kac-h2">{{ __('store.account.your_account') }}</h2>
            <div class="kac-list">
                @foreach ($rows as [$href, $icon, $label, $note, $tone])
                    <a class="kac-li" href="{{ Url::to($href) }}"><span class="kac-ib{{ $tone }}">{!! AccountIcons::svg($icon, 18) !!}</span><span class="kac-lt">{{ $label }}<small>{{ $note }}</small></span>{!! AccountIcons::svg('chev', 16, 'kac-chev') !!}</a>
                @endforeach
                <form method="post" action="{{ Url::to('/my-account/logout') }}" style="margin:0">@csrf<button class="kac-li" type="submit"><span class="kac-ib">{!! AccountIcons::svg('out', 18) !!}</span><span class="kac-lt">{{ __('store.account.nav_sign_out') }}</span></button></form>
            </div>
        </div>
    </div>
</div>
@endsection
