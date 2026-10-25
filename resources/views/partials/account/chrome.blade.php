{{--
    THE TOP OF EVERY ACCOUNT PAGE (Lane ACC): a back button, the page's title,
    a dashboard button, and the account menu, so a shopper always knows where
    she is and can reach any part of her account in one tap.

    $kacTitle   the page's heading (escaped)
    $kacBack    where Back goes: a FIXED parent, never history.back(), so it
                lands on the same page whether she arrived from an email, a
                bookmark or the menu (dashboard -> the shop, a list -> the
                dashboard, one order -> the orders list)
    $kacBackLabel  its accessible name
    $kacActive  which menu entry is this page: dash|orders|wish|addr|profile|track
    $kacTag     'p' where the page has its own <h1>; h1 otherwise

    Sign out is a POST with the CSRF token, as the old dashboard's button was.
    Addresses shows only while the address book is on, the same rule the old
    dashboard card followed. Every href is built by Url::to(), so /ar pages
    stay on /ar.
--}}
@php
    use App\Support\AccountIcons as KacI;
    use App\Support\Url as KacUrl;
    $kacActive = $kacActive ?? '';
    // A page that already has its own <h1> (Track my order) asks for a plain title.
    $kacTag = ($kacTag ?? 'h1') === 'p' ? 'p' : 'h1';
    $kacMenu = [
        ['dash', '/my-account/', 'grid', __('store.account.nav_dashboard')],
        ['orders', '/my-account/orders/', 'box', __('store.account.nav_orders')],
        ['wish', '/my-wishlist/', 'heart', __('store.account.nav_wishlist')],
    ];
    if (app(\App\Services\SettingsService::class)->moduleEnabled('address_book', true)) {
        $kacMenu[] = ['addr', '/my-account/edit-address/', 'pin', __('store.account.nav_addresses')];
    }
    $kacMenu[] = ['profile', '/my-account/profile/', 'user', __('store.account.nav_profile')];
    $kacMenu[] = ['track', '/track-my-order/', 'truck', __('store.account.nav_track')];
@endphp
@include('partials.account.styles')
<div class="kac-top">
    <a class="kac-tb kac-back" href="{{ KacUrl::to($kacBack) }}" aria-label="{{ $kacBackLabel }}" title="{{ $kacBackLabel }}">{!! KacI::svg('back', 20) !!}</a>
@if ($kacTag === 'p')
    <p class="kac-title">{{ $kacTitle }}</p>
@else
    <h1 class="kac-title">{{ $kacTitle }}</h1>
@endif
    <a class="kac-tb{{ $kacActive === 'dash' ? ' on' : '' }}" href="{{ KacUrl::to('/my-account/') }}" aria-label="{{ __('store.account.to_dashboard') }}" title="{{ __('store.account.to_dashboard') }}"@if ($kacActive === 'dash') aria-current="page"@endif>{!! KacI::svg('grid', 20) !!}</a>
</div>
<nav class="kac-menu" aria-label="{{ __('store.account.menu_label') }}">
    @foreach ($kacMenu as [$kacKey, $kacHref, $kacIcon, $kacLabel])
        <a class="kac-pill{{ $kacActive === $kacKey ? ' on' : '' }}" href="{{ KacUrl::to($kacHref) }}"@if ($kacActive === $kacKey) aria-current="page"@endif>{!! KacI::svg($kacIcon, 16) !!}{{ $kacLabel }}</a>
    @endforeach
    <form class="kac-out" method="post" action="{{ KacUrl::to('/my-account/logout') }}">@csrf<button class="kac-pill" type="submit">{!! KacI::svg('out', 16) !!}{{ __('store.account.nav_sign_out') }}</button></form>
</nav>
