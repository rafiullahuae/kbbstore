@php
use App\Support\Url;

/*
 * The SEO & Meta admin screen and App\Support\Seo::render() have both been
 * fully built for some time — the screen saves real settings, the class
 * reads and formats every one of them correctly (title template, meta
 * description, Open Graph, Twitter cards, JSON-LD). Neither had ever been
 * connected to an actual page: this was a bare <title> tag and nothing
 * else. title_is_final passes through whatever a page already computes via
 * @section('title', ...) untouched, so no page's visible title changes here
 * — this only adds what was missing around it. A page can still hand in its
 * own richer context (product data, a specific image, an explicit type) via
 * a $seoCtx array before this layout renders.
 */
$kbbRawTitle = trim(strip_tags($__env->yieldContent('title', $kbbSettings->get('store_name', 'K-Beauty Bliss'))));
$kbbSeoCtx = array_merge([
    'title' => $kbbRawTitle,
    'title_is_final' => true,
    'url' => Url::to(request()->getPathInfo() ?: '/'),
], $seoCtx ?? []);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
{!! \App\Support\Seo::render($kbbSeoCtx) !!}
@stack('head')

{{-- Poppins 400-800, matching the theme exactly (T-BOOT-10). --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="dns-prefetch" href="https://fonts.gstatic.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
{{-- Only the weights the stylesheets actually use. Each extra weight is a
     separate font file on the critical path. --}}
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">

@vite(['resources/css/kbb/kbb.css', 'resources/js/kbb/app.js'])
@stack('styles')

@if ($kbbAccent)
    {{-- Only emitted when the accent differs from the design default. --}}
    <style id="kbb-brand-accent">:root,.kbb-checkout,.kbb-cart{--pink:{{ $kbbAccent['base'] }};--pink-deep:{{ $kbbAccent['deep'] }};}</style>
@endif
    @php $apFont = app(\App\Services\AccountPanel::class)->fontHref(); @endphp
    @if ($apFont)<link rel="stylesheet" href="{{ $apFont }}">@endif
    {!! app(\App\Services\MarketingPixels::class)->baseTags() !!}
</head>
@php
    $kbbCards = app(\App\Services\ProductStyles::class);
    $kbbDiv   = app(\App\Services\SectionDividers::class);
@endphp
<body class="@yield('body-class') {{ $kbbDiv->bodyClass() }}"
      style="{{ $kbbCards->cardVariables() }};{{ $kbbDiv->cssVariables() }}">

{{--
    Bare pages render no site header.

    Checkout carries its own header, because form-checkout.php does, and the
    checkout stylesheet only styles that header inside .kbb-checkout. Adding a
    second one here put its lock icon outside that scope with no width rule, so
    it filled the page. Fixed once in 2.0.4, then reintroduced by a later
    package that shipped an older copy of this file. --}}
@unless (View::hasSection('bare'))
        @include('partials.header')
@endunless

<main id="content">
    @yield('content')
</main>

@unless (View::hasSection('bare'))
    @include('partials.footer')
@endunless

@include('partials.mobile-chrome')
@include('partials.drawers')

@php
    // Every route the front-end needs, already prefixed for this environment.
    // The JS never builds a path itself, so a subdirectory deployment cannot
    // produce links that escape the app.
    $kbbJs = [
        'base' => Url::base(),
        'cartCount' => $kbbCartCount,
        'freeShip' => $kbbFreeShipThreshold,
        'nav' => $kbbMobileNav,
        'routes' => [
            'home' => Url::to('/'),
            'shop' => Url::to('/shop/'),
            'cart' => Url::to('/cart/'),
            'checkout' => Url::to('/checkout/'),
            'checkoutRates' => Url::to('/api/checkout/rates'),
            'search' => Url::to('/api/search'),
            'cartApi' => Url::to('/api/cart'),
            'subscribe' => Url::to('/api/subscribe'),
            'wishlistToggle' => Url::to('/wishlist/toggle'),
            'wishlistIds' => Url::to('/wishlist/ids'),
            'reviewsCaptcha' => Url::to('/reviews/captcha'),
            'reviewsSubmit' => Url::to('/reviews/submit'),
            'reviewsHelpful' => Url::to('/reviews'),
        ],
        'csrf' => csrf_token(),
    ];
@endphp
<script>window.KBB = @json($kbbJs);</script>

@stack('scripts')
</body>
</html>
