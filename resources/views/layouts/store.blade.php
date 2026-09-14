@php
use App\Support\Url;

/*
 * The <head> SEO block. Every value here comes from the "SEO & Meta" admin
 * screen by way of App\Support\Seo.
 *
 * The screen and the class were both finished long ago; what was missing was
 * this. The layout previously emitted the block with title_is_final set on
 * every page, which is the one flag that skips the title template — so
 * seo_title_template, the separator and the homepage title were saved,
 * displayed back in the admin, and never read by anything. That flag now
 * belongs to pages that genuinely compute their own complete title, and is
 * passed in through $seoCtx rather than forced here.
 *
 * A page can hand in richer context (product data, a specific image, an
 * explicit type, noindex) via $seoCtx before this layout renders; anything it
 * does not set falls back to the defaults below.
 */
$kbbRawTitle = trim(strip_tags((string) $__env->yieldContent('title', $kbbSettings->get('store_name', 'K-Beauty Bliss'))));
$kbbSeoCtx = array_merge([
    // routeIs(), not the path: the site is served under a base path in
    // staging, so "/" is not reliably the homepage's path info.
    'type' => request()->routeIs('home') ? 'home' : 'website',
    'title' => $kbbRawTitle,
    'url' => Url::to(request()->getPathInfo() ?: '/'),
], $seoCtx ?? []);
$kbbSeo = \App\Support\Seo::tags($kbbSeoCtx);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
{{-- Printed here rather than returned as a ready-made HTML string: these are
     database values that land inside attributes, so every one of them goes
     through Blade's {{ }} and a setting of `"><script>` stays inert text.
     The JSON-LD below is the one block that must not be entity-escaped, so it
     is encoded with JSON_HEX_TAG instead: a closing script tag inside a
     setting comes out as < and cannot close the element. --}}
<title>{{ $kbbSeo['title'] }}</title>
@foreach ($kbbSeo['meta'] as $kbbTag)
<meta {{ $kbbTag['attr'] }}="{{ $kbbTag['key'] }}" content="{{ $kbbTag['content'] }}">
@endforeach
@if ($kbbSeo['canonical'])
<link rel="canonical" href="{{ $kbbSeo['canonical'] }}">
@endif
@foreach ($kbbSeo['jsonld'] as $kbbNode)
<script type="application/ld+json">@json($kbbNode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)</script>
@endforeach
@if ($kbbSeo['ga'])
{{-- Only ever a validated measurement id (G-/UA-/AW-/GTM-/DC- plus
     alphanumerics), because HTML escaping does not protect a JS string. --}}
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $kbbSeo['ga'] }}"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ $kbbSeo['ga'] }}');</script>
@endif
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
