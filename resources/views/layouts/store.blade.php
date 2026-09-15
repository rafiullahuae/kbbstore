@php
use App\Support\Url;

/*
 * The SEO & Meta admin screen and App\Support\Seo::render() have both been
 * fully built for some time — the screen saves real settings, the class
 * reads and formats every one of them correctly (title template, meta
 * description, Open Graph, Twitter cards, JSON-LD). Neither had ever been
 * connected to an actual page: this was a bare <title> tag and nothing else.
 *
 * Two corrections to how that connection was first made:
 *
 *   - title_is_final was set unconditionally here, which is the one flag that
 *     tells Seo::render() to skip the title template. Every page therefore
 *     took that branch, and seo_title_template / seo_separator / the whole
 *     Search-appearance block were saved by the admin and read by nothing. It
 *     is now left off, so the configured template applies; a page that really
 *     has computed its own final title (a per-product SEO override) still sets
 *     it through $seoCtx. Seo::render() drops the {sitename} token when the
 *     page title already carries the brand, so the titles the views build
 *     ("Cart · K-Beauty Bliss") do not gain a second copy of it.
 *
 *   - Nothing ever passed type => 'home', so seo_home_title and
 *     seo_home_description were unreachable settings. The home page is the one
 *     the router serves at "/", which is exactly what is testable here.
 *
 * A page can still hand in its own richer context (product data, a specific
 * image, an explicit type) via a $seoCtx array before this layout renders.
 */
$kbbPath = request()->getPathInfo() ?: '/';
$kbbIsHome = trim($kbbPath, '/') === '';
$kbbRawTitle = trim(strip_tags($__env->yieldContent('title', '')));
/*
 * Url::to() trims a trailing slash (UrlGenerator::format does), but every
 * storefront route is declared with one and every internal link carries one.
 * Left alone, the page served at /korean-skincare-brands/ canonicalises to
 * /korean-skincare-brands, pointing search engines at a URL one redirect away
 * from the page they are already on. Put the slash back.
 */
$kbbCanonical = Url::to($kbbPath);
if ($kbbPath !== '/' && str_ends_with($kbbPath, '/') && ! str_ends_with($kbbCanonical, '/')) {
    $kbbCanonical .= '/';
}

$kbbSeoCtx = array_merge([
    'type' => $kbbIsHome ? 'home' : 'website',
    'title' => $kbbRawTitle,
    'url' => $kbbCanonical,
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
    {!! app(\App\Services\MarketingPixels::class)->addToCart() !!}
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

@if (app(\App\Services\SettingsService::class)->moduleEnabled('quick_view', true))
{{-- Quick view: one shell per page, filled on demand from /quick-view/{id}. --}}
<div class="qv-back" id="kbbQv" hidden>
  <div class="qv-modal" role="dialog" aria-modal="true" aria-label="Quick view">
    <button class="qv-x" type="button" aria-label="Close">&times;</button>
    <div class="qv-slot"><div class="qv-load">Loading…</div></div>
  </div>
</div>
<style>
.pc .ph{position:relative}
.qv-btn{position:absolute;left:50%;bottom:10px;transform:translate(-50%,6px);opacity:0;transition:.18s;background:rgba(255,255,255,.95);border:1px solid #e6dbe0;border-radius:99px;padding:6px 16px;font-size:11px;letter-spacing:.03em;cursor:pointer;color:#5e545a;white-space:nowrap;z-index:3}
.pc:hover .qv-btn,.qv-btn:focus-visible{opacity:1;transform:translate(-50%,0)}
@media (hover:none){.qv-btn{display:none}}
.qv-back{position:fixed;inset:0;background:rgba(40,30,36,.5);display:grid;place-items:center;z-index:9999;padding:18px}
.qv-back[hidden]{display:none}
.qv-modal{position:relative;background:#fff;border-radius:14px;max-width:760px;width:100%;max-height:88vh;overflow:auto;padding:22px}
.qv-x{position:absolute;top:10px;right:12px;background:none;border:0;font-size:26px;line-height:1;color:#8a7f85;cursor:pointer}
.qv-load{padding:46px 0;text-align:center;color:#8a7f85;font-size:13px}
.qv-wrap{display:grid;grid-template-columns:1fr 1fr;gap:22px}
@media (max-width:640px){.qv-wrap{grid-template-columns:1fr}}
.qv-media img{width:100%;border-radius:10px;display:block}
.qv-noimg{aspect-ratio:1;background:#f6f1f3;border-radius:10px}
.qv-brand{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8a7f85}
.qv-name{font-size:19px;margin:5px 0 9px;line-height:1.3}
.qv-rate{font-size:12px;color:#6d6369;margin-bottom:9px}
.qv-rate span{color:#e0a33c}
.qv-price{font-size:19px;margin-bottom:8px}
.qv-price del{color:#a79aa1;font-size:15px;margin-right:7px}
.qv-price ins{text-decoration:none;color:#b4517a}
.qv-off{font-size:11px;background:#fff0f4;color:#b4517a;border-radius:99px;padding:2px 8px;margin-left:6px}
.qv-stock{font-size:12px;color:#2f6b41;margin-bottom:11px}
.qv-stock.out{color:#a33a55}
.qv-blurb{font-size:13px;color:#5e545a;line-height:1.55;margin:0 0 16px}
.qv-acts{display:flex;flex-wrap:wrap;gap:11px;align-items:center}
.qv-add{background:#b4517a;color:#fff;border:0;border-radius:8px;padding:11px 22px;font-size:14px;cursor:pointer}
.qv-full{font-size:13px;color:#b4517a}
</style>
<script>
(function () {
  var box = document.getElementById('kbbQv');
  if (!box) return;
  var slot = box.querySelector('.qv-slot');
  var base = (window.KBB && window.KBB.routes && window.KBB.routes.home) || '/';
  var last = null;

  function open() { box.hidden = false; document.body.style.overflow = 'hidden'; }
  function close() {
    box.hidden = true; document.body.style.overflow = '';
    slot.innerHTML = '<div class="qv-load">Loading…</div>';
    if (last && last.focus) last.focus();
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-kbb-qv]');
    if (btn) {
      e.preventDefault();
      last = btn;
      open();
      var url = base.replace(/\/+$/, '') + '/quick-view/' + btn.dataset.kbbQv;
      fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) { slot.innerHTML = d.html; })
        .catch(function () {
          slot.innerHTML = '<div class="qv-load">Sorry — that product could not be loaded.</div>';
        });
      return;
    }
    if (e.target.closest('.qv-x') || e.target === box) close();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !box.hidden) close();
  });
})();
</script>
@endif

@stack('scripts')
</body>
</html>
