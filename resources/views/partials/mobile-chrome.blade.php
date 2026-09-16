{{--
    Mobile chrome: the menu sheet and the bottom tab bar.

    The sheet is rendered on every page and hidden above the mobile breakpoint
    by CSS rather than detected server-side, so a cached page is correct for
    every device. Appearance comes from Appearance → Mobile menu.
--}}
@php
    use App\Support\Url;
    $mm = app(\App\Services\MobileMenu::class);
    $cfg = $mm->all();

    // Real menu first. The published kbeautybliss.com menu stands in only while
    // demo content is on, so the sheet is never empty before the WordPress
    // menus are migrated.
    $mmItems = $kbbMobileNav ?: $kbbNav;

    // Top the menu up rather than only replacing an empty one: a menu with a
    // handful of items is the normal state before migration, and requiring it
    // to be empty meant the demo never showed. Real items keep their order.
    if (app(\App\Services\DemoContent::class)->enabled()) {
        $mmItems = \App\Services\MenuDemo::fill($mmItems ?: []);
    }
@endphp

<div class="mscrim" id="mscrim"></div>

<nav class="mmenu {{ $mm->bodyClass() }}" id="mmenu" style="{{ $mm->cssVariables() }}"
     data-single-open="{{ $cfg['single_open'] ? '1' : '0' }}" aria-label="Menu">
    @if ($cfg['show_grab'])<div class="mm-grab" data-mm-close></div>@endif
    @if ($cfg['show_close'])<button class="mm-x" type="button" id="mmx" aria-label="Close">&times;</button>@endif

    @if ($cfg['show_heading'])
        <div class="mm-head"><b>{{ $cfg['heading_text'] }}</b></div>
    @endif

    @if ($cfg['show_search'])
        <div class="mm-srch">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
            <input type="search" id="mmFilter" placeholder="{{ $cfg['search_text'] }}" autocomplete="off">
        </div>
    @endif

    <div class="mm-body" id="mmBody">
        @foreach ($mmItems as $item)
            @include('partials.mobile-menu-item', ['item' => $item, 'depth' => 0])
        @endforeach

        @if ($cfg['show_account'])
            <div class="mm-grp">{{ $cfg['account_label'] }}</div>
            @auth
                <a class="mm-it" href="{{ Url::to('/my-account/') }}">My account</a>
                <a class="mm-it" href="{{ Url::to('/my-account/orders/') }}">Orders</a>
            @else
                <a class="mm-it" href="{{ Url::to('/my-account/') }}">Sign in</a>
                <a class="mm-it" href="{{ Url::to('/my-account/?action=register') }}">Create an account</a>
            @endauth
            <a class="mm-it" href="{{ Url::to('/my-wishlist/') }}">Wishlist</a>
        @endif

        <p class="mm-empty" id="mmEmpty" hidden>Nothing matches that.</p>
    </div>

    @if ($cfg['show_support'])
        {{-- The mobile menu prints the dialled number itself, so it asks for
             whatsapp() rather than phone() — see App\Support\SupportContact. --}}
        @php $phone = \App\Support\SupportContact::whatsapp(); @endphp
        <div class="mm-foot">
            <a class="mm-wa" href="https://wa.me/{{ \App\Support\SupportContact::whatsappDigits() }}">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-2.8.7.8-2.8-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.1-.2 0-.4.1-.5l.4-.5c.1-.1.1-.3 0-.4l-.7-1.7c-.2-.4-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2c0 1.3 1 2.6 1.1 2.8.1.2 1.9 3 4.7 4.1 1.7.6 2.3.7 3.1.6.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1 0-.1-.2-.2-.4-.3Z"/></svg>
                {{ $cfg['support_text'] }} · {{ $phone }}
            </a>
        </div>
    @endif
</nav>

@php
    /*
     * Store & content -> "Floating bottom menu (mobile)" (module key
     * mobile_tabbar), default OFF.
     *
     * Read here rather than passed in, the same way cart-inner.blade.php and a
     * dozen other storefront views resolve a module. moduleEnabled() returns
     * the stored module_toggles row when one exists, so an install that has
     * chosen a value keeps its choice; only an install with no row at all gets
     * the default.
     *
     * Nothing else depends on this bar: the header carries its own cart icon
     * and count, and the mobile menu above is a separate nav. Hiding it removes
     * a floating overlay, not a route.
     */
    $kbbTabbar = app(\App\Services\SettingsService::class)->moduleEnabled('mobile_tabbar', false);
@endphp

@if ($kbbTabbar)
<nav class="tabbar" aria-label="Quick navigation">
    <a href="{{ Url::to('/') }}" @class(['on' => '/' === request()->path()])>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 11 12 3l9 8"/><path d="M5 10v10h14V10"/></svg><span>Home</span></a>
    <a href="{{ Url::to('/shop/') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg><span>Shop</span></a>
    <a class="tb-q" href="{{ Url::to('/skin-quiz/') }}">
        <span class="qb"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 9a3 3 0 1 1 4 2.8c-.7.3-1 .9-1 1.7v.5"/><circle cx="12" cy="17.5" r="1"/></svg></span><span>Quiz</span></a>
    <a href="{{ Url::to('/my-wishlist/') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg><span>Saved</span></a>
    <a href="{{ Url::to('/cart/') }}" data-kbb-open="cart">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/></svg><span>Bag</span>
        @if (($kbbCartCount ?? 0) > 0)<i id="tabCartCt">{{ $kbbCartCount }}</i>@endif</a>
</nav>
@endif
