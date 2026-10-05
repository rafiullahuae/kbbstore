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
 * Left alone, the page served at /brands/ canonicalises to
 * /brands, pointing search engines at a URL one redirect away
 * from the page they are already on. Put the slash back.
 *
 * The slash is now put back whether or not the REQUEST carried one, and that
 * is the correction rather than a tidy-up. Laravel's router rtrims the path
 * before matching (Routing\Matching\UriValidator), so /cart and /cart/ both
 * answer 200 with identical content and neither redirects to the other. While
 * this mirrored the request, those two URLs published two different
 * self-referencing canonicals — so the same document told Google it was two
 * documents, and a single inbound link written without the slash was enough to
 * split a page's signals. Every internal link, the sitemap and the URL
 * Contract (U-01) all use the slashed form, so that is the one form this
 * declares.
 *
 * Only the canonical is normalised. Nothing here changes what a URL serves, so
 * no existing address breaks; a page that has computed its own canonical still
 * passes it through $seoCtx and is untouched.
 */
$kbbCanonical = Url::to($kbbPath);
if ($kbbPath !== '/' && ! str_ends_with($kbbCanonical, '/')) {
    $kbbCanonical .= '/';
}

/*
 * The cart, the checkout, the account area, the wishlist and order tracking
 * are per-visitor pages that robots.txt has always said should not be crawled,
 * while every one of them was serving "index, follow" with a self-referencing
 * canonical. See App\Support\Indexability for why the decision lives in one
 * prefix list rather than in each of those controllers.
 *
 * Listed first in the merge, so a page that has a reason of its own to set
 * noindex (or, in principle, to override it) still wins through $seoCtx.
 */
$kbbSeoCtx = array_merge([
    'type' => $kbbIsHome ? 'home' : 'website',
    'title' => $kbbRawTitle,
    'url' => $kbbCanonical,
    'noindex' => \App\Support\Indexability::isPrivate($kbbPath),
], $seoCtx ?? []);

/*
 * WHICH KIND OF noindex THIS PAGE'S noindex IS, and it is the whole care in
 * this line.
 *
 * App\Support\Seo retracts the hreflang cluster on a page a CONTROLLER marked
 * noindex — `brands.seo` and `categories.seo` carry an editorial "do not index
 * this document" the owner sets per brand and per category, and a document
 * saying "noindex, nofollow" while advertising three alternates of itself is
 * saying two opposite things in one <head>. Google resolves that by dropping
 * the cluster, which costs the OTHER language its alternate too.
 *
 * The merged context above ALSO carries Indexability::isPrivate() — the cart,
 * the checkout, the account area, the wishlist and order tracking. Those are
 * per-visitor pages excluded for a reason that has nothing to do with what
 * document they are, and their alternates are emitted deliberately, so an
 * Arabic shopper's wishlist links to the English one. Only the editorial kind
 * retracts.
 *
 * $seoCtx and NOT $kbbSeoCtx, which is the distinction: the raw context is what
 * the controller asked for, the merged one is that plus the prefix list.
 */
$kbbSeoCtx['noindex_editorial'] = ! empty(($seoCtx ?? [])['noindex']);
@endphp
@php
    /*
     * Bilingual foundation (Lane EP).
     *
     * $kbbLocale is the language this page is being served in and $kbbDir is
     * whether the MIRRORED LAYOUT is switched on — two separate switches, on
     * purpose. Locale::direction() returns 'ltr' for Arabic while the
     * right-to-left stylesheet is still being built, which is a real state the
     * owner asked to be able to reach rather than an accident.
     *
     * With Arabic off — which is how this ships — $kbbLocale is 'en', $kbbDir
     * is 'ltr' and no hreflang is emitted at all. Diffed against the tip on
     * nine fetched pages, the whole of what changes for an English shopper is
     * ONE BLANK LINE inside <head>, left where the hreflang block used
     * to stand. Nothing else on any page moves by a byte.
     */
    $kbbLocale = \App\Support\Locale::current();
    $kbbDir = \App\Support\Locale::direction();

    /*
     * hreflang IS NO LONGER BUILT HERE. It moved into App\Support\Seo, which
     * builds it from the canonical that class has just computed rather than
     * from the request path — see Seo::alternateLinks() for the three cases
     * where those two differ, and for the four storefront pages that do not use
     * this layout at all and so were emitting no hreflang whatsoever.
     *
     * $kbbLocale and $kbbDir stay: <html lang> and <html dir> are this
     * document's own attributes and belong to the document, not to the <head>
     * block a helper renders into it.
     */

    /*
     * PRESS FEEDBACK (Lane RD): ` data-press="c"`, or '' when the owner chose
     * Off. A constant from SiteLayout::PRESS_ATTR, never the stored value, which
     * is why it may be printed unescaped. Every press rule in kbb.css and every
     * line of resources/js/kbb/press.js is keyed by this one attribute, so Off
     * is the page exactly as it was. Appearance -> Site layout -> Press feedback.
     */
    $kbbPress = app(\App\Services\SiteLayout::class)->pressAttribute();
@endphp
<!DOCTYPE html>
<html lang="{{ $kbbLocale }}" dir="{{ $kbbDir }}"{!! $kbbPress !!}>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
{!! \App\Support\Seo::render($kbbSeoCtx) !!}
@stack('head')
@include('partials.site-app-head')

{{-- Outfit 400-800, matching the theme exactly (T-BOOT-10), AND SERVED BY
     THIS SHOP — Lane PERF. App\Support\WebFonts carries the measurement in
     full; the short version is the owner's own mobile report:

         Initial Navigation  https://extrabeauty.ae         2,366 ms
           /css2?family=Outfit…  (fonts.googleapis.com)     2,364 ms
             …pxiEyp8kv….woff2    (fonts.gstatic.com)        4,366 ms
             …pxiByp8kv….woff2    (fonts.gstatic.com)        4,369 ms
         Maximum critical path latency: 4,369 ms

     Two hops to two third-party origins before a single glyph exists, off a
     <link> that blocks rendering while it happens. With font-display:swap
     every word on the page is painted twice — once in the system fallback at
     FCP, once in Outfit two seconds later — and that repaint is most of why
     the Speed Index was 7.7 s against an LCP of 2.9 s.

     THE PRECONNECTS GO WITH IT. A preconnect to an origin the page no longer
     asks for is a TCP and TLS handshake spent on nothing, and Lighthouse's
     "Preconnected origins" insight lists exactly these two.

     THE FILES ARE GOOGLE'S OWN, unchanged, all twelve faces css2 returns. The
     same codepoint resolves to the same face; only the host changed. --}}
{!! \App\Support\SiteFonts::preloadTags() !!}<style id="kbb-outfit">{!! \App\Support\WebFonts::faceCss(\App\Support\WebFonts::OUTFIT) !!}</style>
{{--
    POPPINS CARRIES NO ARABIC GLYPHS. Not "renders Arabic badly" — it has none
    of the letters, so every Arabic word falls back to whatever the device
    happens to have: Tahoma on Windows, and on an older Android a face with no
    diacritic positioning at all. The result is legible and looks like a broken
    website, which is the worst possible combination on a page asking for a card
    number.

    Cairo, because it was drawn as an Arabic face with a Latin companion rather
    than a Latin face with Arabic bolted on, it covers the weights this theme
    uses, and it is on Google Fonts under the SIL Open Font Licence — so nothing
    has to be bought or self-hosted on a shared host with no shell.

    ONLY ON AN ARABIC PAGE. A second render-blocking stylesheet on every English
    page would repeat exactly the defect the account-panel font block further
    down was written to fix. English traffic is unchanged, byte for byte.
--}}
{{--
    WEIGHT 800 IS IN THE LIST AND IT COSTS NOTHING. The storefront styles 79
    declarations at font-weight:800 — the wordmark, the checkout h1, the payment
    logos, the price totals — and without 800 in the request every one of them
    drops to Cairo 700 on an Arabic page.

    Measured before adding it, because "one more weight" normally means one more
    download: Cairo:wght@400;600;700 and the same list with 800 return THE SAME
    variable WOFF2 — same URL, same SHA-256, 30,896 bytes either way. Only the
    CSS grows, 5,214 to 6,952 uncompressed bytes, and only on Arabic pages.

    AND THE FACE HAS TO BE NAMED SOMEWHERE, WHICH IT WAS NOT.

    The <link> above is correct and correctly gated, and on its own it did
    nothing at all: no font stack in this storefront mentions Cairo. `--sans` is
    "Outfit",system-ui,… in all four stylesheets that define it, and the product
    card name, the badges and the add-to-cart button hard-code 'Outfit',
    sans-serif of their own. A browser downloads a face when something uses it,
    so Cairo was fetched by nothing and every Arabic word still fell through
    Outfit — which has no Arabic glyphs — to the system fallback. That is
    exactly the defect the note above describes, with a 30 KB stylesheet request
    added on top of it.

    MEASURED, with Cairo served from the same bytes Google serves so the capture
    browser could actually load it. The same Arabic string at 40px, set in the
    page's own inherited stack versus set in Cairo explicitly:

                     stack      Cairo
        weight 400   496.53     479.05      before
        weight 800   595.63     537.88      before
        weight 400   479.05     479.05      after
        weight 800   537.88     537.88      after

    Before, the stack was `Outfit, system-ui, sans-serif` and matched neither —
    it was rendering in the system fallback. After, it matches Cairo exactly at
    both weights, and the two weights differ from each other, so the `;800` in
    the request above is doing real work rather than being rounded to 700.

    A sweep of every text-bearing element on the Arabic storefront (home, shop,
    product, cart, checkout) moved from 960 elements on a Cairo-capable stack to
    1800. What is left is <title>/<script>/<style>, which render nothing, and
    the blog and article views — see the note below.

    APPENDED, NEVER SUBSTITUTED. Outfit stays first so Latin — the brand name,
    prices, SKUs, every English word on a mixed page — still renders in the
    brand face; per-codepoint font selection then reaches Cairo only for the
    codepoints Outfit has no glyph for. The English page is unchanged byte for
    byte: nothing here is emitted for it, and the rules are scoped to
    html[lang="ar"] besides.

    Gated on the LANGUAGE and not on Locale::isRtl(), same as the link, and for
    the same reason: direction() returns ltr for Arabic while
    language_rtl_enabled is off, and Arabic words need Arabic glyphs in that
    state too.

    NOT FIXED HERE, and out of this lane: store/blog.blade.php and
    store/post.blade.php are standalone layouts that hard-code <html lang="en">
    with no dir attribute, so /ar/blog/ serves an English-tagged,
    left-to-right page with no Cairo link at all. Nothing in this block reaches
    them. store/app.blade.php (the admin-only /app preview) is the same.
    Whoever owns those views has to give them <html lang>/<html dir> before any
    of this applies there.

    The three hard-coded stacks are restated rather than tokenised. Editing
    them in place would put Cairo in the English stylesheets as well, which is a
    change to the default and is what this lane is not allowed to make.
    Specificity carries these over the page stylesheets whatever order they load
    in: html[lang="ar"] (0,1,1) beats :root (0,1,0), and
    html[lang="ar"] .kbb-checkout (0,2,1) beats .kbb-checkout (0,1,0).
--}}
@if ($kbbLocale !== \App\Support\Locale::DEFAULT)
{{-- AND CAIRO IS SERVED BY THIS SHOP TOO — Lane PERF. Same argument as
     Outfit above, and it has to go with it rather than after it: the two
     preconnect hints that made this request cheap belonged to the Outfit
     block, so leaving Cairo on fonts.googleapis.com while removing them would
     have made the Arabic pages SLOWER than they were. Its four weights are one
     variable file per subset — App\Support\WebFonts has the byte count that
     proves it — so this is three files, of which an Arabic page fetches one. --}}
{!! \App\Support\WebFonts::preloadTags(\App\Support\WebFonts::CAIRO) !!}<style id="kbb-cairo">{!! \App\Support\WebFonts::faceCss(\App\Support\WebFonts::CAIRO) !!}</style>
<style id="kbb-arabic-face">
html[lang="ar"],html[lang="ar"] .kbb-checkout{--sans:"Outfit","Cairo",system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
html[lang="ar"] body{font-family:"Outfit","Cairo",system-ui,sans-serif}
html[lang="ar"] .kbb-card .cn,html[lang="ar"] .kbb-badge,html[lang="ar"] .kbb-card-cart{font-family:'Outfit','Cairo',sans-serif}
html[lang="ar"] .sr{font-family:"Hanken Grotesk","Cairo",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
html[lang="ar"] .sr-title,html[lang="ar"] .sr-avg,html[lang="ar"] .sr-stitle{font-family:Fraunces,"Cairo",Georgia,serif}
html[lang="ar"] .q-next,html[lang="ar"] .ib i,html[lang="ar"] .tabbar i{font-family:"Outfit","Cairo",sans-serif}
</style>
@endif

@vite(['resources/css/kbb/kbb.css', 'resources/js/kbb/app.js'])
@stack('styles')

{{-- The brand colour and the site width, from the one partial the five
     documents that do NOT extend this layout also include.      (Lane BG)

     THEY WERE TWO BLOCKS HERE and nowhere else, which is why the owner's brand
     colour has never reached the journal, an article, the review wall or the
     skin quiz: App\View\Composers\StoreComposer is registered for
     `layouts.store` and nothing else, so `$kbbAccent` was not even defined on
     those documents, and all five hard-code `--pink:#E0567B` in their own
     stylesheet. The partial's own header carries the census.

     BOTH WRITERS STILL ANSWER '' AT THE SHIPPED SETTINGS, so this page gains
     not one byte until the owner moves something -- the same guarantee the two
     blocks it replaces made, made in one place for six documents instead of
     two places for two.

     POSITION UNCHANGED: after @stack('styles') so a page sheet cannot outrank
     the owner's numbers, and before the wash and the set partial. --}}@include('partials.shop-appearance-css')
{{-- Appearance -> Header -> Breadcrumbs: the trail's per-device switches and
     spacing. Its own header carries the argument; it sits after
     @stack('styles') like the line above, though its specificity would win
     from anywhere in the head.                                    (Lane PI-B) --}}@include('partials.breadcrumb-css')
{{-- Appearance -> Page background: the soft multi-colour wash.        (Lane BG)

     ONE LINE, and the partial's own header carries the argument. The short
     version is: it emits ZERO BYTES -- not even a newline -- until the owner
     switches the wash on, so applying this package leaves every storefront page
     byte-identical; and it is a partial rather than a block here because FIVE
     storefront pages do not extend this layout at all and the owner asked for
     "the whole background".

     AFTER the accent, the page stylesheets and the site layout, so it outranks
     kbb-shop.css's and kbb-product.css's own `body{background:var(--bg)}` --
     which is not a detail: those two are why /shop and a product page are
     WHITE today while the home page and the cart are pink. Nothing else here
     competes with it; the only selectors it declares are `html::before`,
     `body::before` and `body::after`, which no stylesheet and no Blade in this
     repository declares. --}}@include('partials.page-wash-css')
{{-- Appearance -> Set. ONE LINE, and its POSITION is load-bearing rather than
     tidy -- the partial's own header carries the whole argument, and the short
     version is: after @stack('styles') so a page sheet cannot outrank the
     owner's numbers, and BEFORE THE BODY so the set partial's own
     `.kbb-checkout .kset-pop.is-open{max-width:none}` -- the rule that gets the
     popup out of the checkout summary's overflow:hidden on a phone -- still
     wins where the two tie on specificity. It emits ZERO BYTES until a slider
     moves.                                                          (Lane SA) --}}@include('partials.set-appearance-css')
    {{--
        The account panel's welcome typeface, and ONLY for someone who can see
        it.

        This link was emitted on every page, so every visitor to /shop,
        /product, /cart and /checkout fetched a SECOND render-blocking Google
        Fonts stylesheet — Cormorant Garamond by default, since that is what
        AccountPanel::SCHEMA ships as `welcome_font` — plus the WOFF2 files
        behind it.

        The only thing that face ever styles is `.ap-greet`, and
        partials/account-panel.blade.php renders that element inside `@auth`. A
        signed-out visitor could never see a glyph of it, and storefront traffic
        is overwhelmingly signed out.

        WHAT THIS IS AND IS NOT WORTH, measured rather than assumed. It is one
        fewer render-blocking request in <head> on every guest page — 15 -> 14
        on the home page, 28 -> 27 on /shop, 11 -> 10 on a product page, at
        both 1280 and 390. It is NOT a saved origin: Outfit comes from the
        same fonts.googleapis.com, so the connection is open either way. And it
        is NOT the WOFF2 files, because a browser fetches a face only when
        something on the page uses it, and on a guest page nothing does.

        One blocking request is still worth removing here. A <head> stylesheet
        has to be fetched and parsed before the first paint, this host has no
        CDN in front of it, and the request buys a visitor who is signed out
        precisely nothing.

        The gate is deliberately WIDER than the element it protects: either
        guard signed in is enough, while the greeting additionally needs the
        default guard and the account menu switched on. Too wide leaves an
        unused stylesheet on a few signed-in pages; too narrow takes the font
        off a page that renders the greeting. Only the second is a defect, so
        the test in StorefrontCostTest pins both directions.
    --}}
    @php
        $apSignedIn = auth()->check() || auth()->guard('customer')->check();
        $apFont = $apSignedIn ? app(\App\Services\AccountPanel::class)->fontHref() : null;
    @endphp
    @if ($apFont)<link rel="stylesheet" href="{{ $apFont }}">@endif
    {{--
        The analytics loaders, from the one class that emits them.

        This line used to be MarketingPixels::baseTags(), and Seo::render() at
        the top of this same <head> emitted a SECOND Google loader from a
        SECOND setting. Both now go through App\Services\Analytics, which
        resolves one ID per network and emits the loader once per request — so
        even if a future partial, layout or include calls it again, the second
        call returns nothing.
    --}}
    {!! app(\App\Services\Analytics::class)->headTags() !!}
    {!! app(\App\Services\MarketingPixels::class)->addToCart() !!}
</head>
@php
    $kbbCards = app(\App\Services\ProductStyles::class);
    $kbbDiv   = app(\App\Services\SectionDividers::class);
@endphp
{{--
    THE PRODUCT-CARD SETTINGS REACH THE SHOP FROM HERE.             Lane AD

    This line carried cardVariables() — two properties, the name clamp — and
    nothing else. ProductStyles::cssVariables() and ::bodyClass(), which carry
    the other fifteen controls on Appearance → Product styles, were called from
    resources/views/admin/app.blade.php AND NOWHERE ELSE, so the card roundness,
    the image shape, the six colours and the seven "what the card shows"
    toggles had never moved a pixel of the shop. Measured rather than read:
    ProductStylesReachTheShopTest moves every key in the schema and re-renders
    the storefront.

    THE STYLESHEET WAS ALREADY WAITING. kbb-grid-skins.css has declared
    `border-radius:var(--kbb-radius,14px)`, `aspect-ratio:var(--kbb-ratio,1/1)`,
    the six colour properties and the seven `.pc-no*` rules for releases. Only
    the writer was missing, which is why this is one line and not a stylesheet.

    ON <body>, NOT ON EACH GRID, for the reason cardVariables() was already
    here: a shortcode grid, a homepage rail, /shop, the related-products rail
    and a lone <x-product-card> must trim and colour their cards the same way,
    and `.pc-nobrand .kbb-card-brand` is a descendant selector that only works
    from an ancestor. :root declares --kbb-gap and friends; body is a nearer
    ancestor than :root for everything inside it, so these win where they are
    set and the sheet's own fallbacks stand where they are not.

    NOTHING MOVES ON THE SHOP WHEN THIS PACKAGE IS APPLIED. Every default in
    SCHEMA equals the fallback the stylesheet already used (14px, 1/1, #E23B57,
    #1F9D55, #2A2228, #E8A33D, #E0567B, #FFFFFF, and all seven toggles on, so
    bodyClass() is the empty string). And because these controls have never had
    an effect, any value stored against them is a value the owner has never
    seen; the migration beside this change clears them, so the screen and the
    shop agree for the first time instead of the shop jumping to a year-old
    slider position.

    cardVariables() is NOT called any more: cssVariables() emits
    --kbb-name-lines and --kbb-name-min itself, so calling both would declare
    the clamp twice in one attribute. The method stays on the class — see the
    note there — because a stale compiled copy of THIS file, served after a
    package whose migration did not run, would otherwise be "Call to undefined
    method" on every storefront page.
--}}<body class="@yield('body-class') {{ $kbbDiv->bodyClass() }}{{ $kbbCards->bodyClass() === '' ? '' : ' ' . $kbbCards->bodyClass() }}"
      style="{{ $kbbCards->cssVariables() }};{{ $kbbDiv->cssVariables() }}">

{{--
    Bare pages render no site header.

    Checkout carries its own header, because form-checkout.php does, and the
    checkout stylesheet only styles that header inside .kbb-checkout. Adding a
    second one here put its lock icon outside that scope with no width rule, so
    it filled the page. Fixed once in 2.0.4, then reintroduced by a later
    package that shipped an older copy of this file. --}}
@unless (View::hasSection('bare'))
@php
/*
 * THE FLAG BAR, above the header and inside the same @unless. (Lane FB)
 *
 * A PHP comment inside an @php block and NOT a Blade comment, for the reason
 * the footer's note below gives: Blade strips a Blade comment and leaves the
 * newline after it, so a note written that way would add one byte to every
 * page in the shop. Written this way it compiles to a bare PHP tag, whose
 * close swallows the newline that follows, and the only bytes this change adds
 * to a page are the strip itself.
 *
 * It also has to be written without the two Blade delimiters in it. Blade
 * strips a Blade comment by a regex over the WHOLE template, inside an @php
 * block as readily as outside one, so a note that quoted its own example lost
 * the middle of the sentence in the compiled view — harmless, being a comment,
 * and exactly the kind of harmless that reads as a mistake to the next person.
 *
 * INSIDE the @unless, because `bare` is the checkout: a page asking for a card
 * number does not carry a marketing strip, and the section that already says
 * "no site header here" is the one that should say it.
 *
 * Guarded on flagBarOn() rather than drawn and hidden. With both switches off
 * the strip is not in the document at all and the line below compiles to
 * `if(): endif;`, whose closing `?>` swallows its own newline — so a shop that
 * turns it off on both widths gets back the byte-identical page it had before
 * the package. Appearance → Header → Flag bar.
 *
 * ── AND A PAGE MAY PLACE IT ITSELF. (Lane SEC) ──────────────────────────────
 *
 * The owner drew a red arrow on his own phone from this strip at the very top
 * of the page, curving down to below the hero: "The top countries bar, i need
 * under banner". That is the HOMEPAGE's order, and only the homepage has a
 * banner to be under — so the strip is not moved for every page, it is
 * CLAIMED by the page that wants it somewhere else.
 *
 * A page claims it with `@section('flagbar-placed', '1')` and then includes
 * partials/flag-bar itself wherever it wants it. store/home.blade.php is the
 * only page that does; every other page is untouched, which is why this is a
 * claim rather than a move.
 *
 * flagBarOn() IS TESTED FIRST and the && short-circuits, so a shop with the
 * strip off never reaches hasSection() and gets back exactly the bytes it had.
 */
@endphp
@if (app(\App\Services\HeaderSettings::class)->flagBarOn() && ! \Illuminate\Support\Facades\View::hasSection('flagbar-placed'))@include('partials.flag-bar')@endif
        @include('partials.header')
@endunless

<main id="content">
    @yield('content')
</main>

@php
/*
 * THE FOOTER, on every page that has not said otherwise.
 *
 * This note is a PHP comment inside an @php block and not a Blade comment, and
 * that is load-bearing. Blade strips `{{-- --}}` and leaves the newline after
 * it, so a comment written here would add one byte to EVERY page in the shop —
 * this template is what every page extends. Written this way it compiles to a
 * bare <?php ?>, PHP swallows the newline that follows, and the rendered page
 * is unchanged. The Blade comment above the header predates this and is part
 * of the baseline; adding a second one here is what would have moved bytes.
 *
 * TWO SECTIONS, NOT A SETTING LOOKUP. 'bare' is the long-standing one.
 * 'no-footer' is declared by store/cart.blade.php when Appearance → Cart page →
 * "Show the site footer on the cart page" is off — which is how it ships,
 * because the owner asked for a cart page with no footer.
 *
 * The check has to be phrased this way round. Reading a cart setting here would
 * put the cart page's switch on the homepage's critical path and leave "no
 * footer anywhere" one mistaken truthy value away. hasSection() is true only
 * for a page that deliberately declared the section, and store/cart.blade.php
 * is the only template in this repo that declares this one. A page that never
 * mentions it cannot lose its footer however that setting is saved.
 */
@endphp
@unless (View::hasSection('bare') || View::hasSection('no-footer'))
    @include('partials.footer')
@endunless

@include('partials.mobile-chrome')
@include('partials.drawers')
@include('partials.whatsapp-button')

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
@include('partials.js-strings')

@if (app(\App\Services\SettingsService::class)->moduleEnabled('quick_view', false))
{{-- Quick view: one shell per page, filled on demand from /quick-view/{id}. --}}
<div class="qv-back" id="kbbQv" hidden>
  <div class="qv-modal" role="dialog" aria-modal="true" aria-label="{{ __('store.quick_view.dialog_label') }}">
    <button class="qv-x" type="button" aria-label="{{ __('store.quick_view.close_label') }}">&times;</button>
    <div class="qv-slot"><div class="qv-load">{{ __('store.quick_view.loading') }}</div></div>
  </div>
</div>
{{-- `.pc .ph{position:relative}` opened the block below and it is GONE, not
     renamed. Lane PG: the quick-view button is positioned against the tile's
     photograph frame, that frame is `.kbb-card-thumb` now, and kbb.css already
     declares it `position:relative` — a second copy in a style element on every
     page of the shop is a declaration that can drift from the one that matters.
     The hover selector inside had to move with the card and could not simply be
     dropped: left as `.pc:hover` the button would never have appeared again,
     which is why every page of the shop moves by these two lines. --}}
<style>
/* RTL-PHYSICAL: centring idiom (left:50% + translate(-50%,...)). */
.qv-btn{position:absolute;left:50%;bottom:10px;transform:translate(-50%,6px);opacity:0;transition:.18s;background:rgba(255,255,255,.95);border:1px solid #e6dbe0;border-radius:99px;padding:6px 16px;font-size:11px;letter-spacing:.03em;cursor:pointer;color:#5e545a;white-space:nowrap;z-index:3}
.kbb-tile:hover .qv-btn,.qv-btn:focus-visible{opacity:1;transform:translate(-50%,0)}
@media (hover:none){.qv-btn{display:none}}
.qv-back{position:fixed;inset:0;background:rgba(40,30,36,.5);display:grid;place-items:center;z-index:9999;padding:18px}
.qv-back[hidden]{display:none}
.qv-modal{position:relative;background:#fff;border-radius:14px;max-width:760px;width:100%;max-height:88vh;overflow:auto;padding:22px}
.qv-x{position:absolute;top:10px;inset-inline-end:12px;background:none;border:0;font-size:26px;line-height:1;color:#8a7f85;cursor:pointer}
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
.qv-price del{color:#a79aa1;font-size:15px;margin-inline-end:7px}
.qv-price ins{text-decoration:none;color:#b4517a}
.qv-off{font-size:11px;background:#fff0f4;color:#b4517a;border-radius:99px;padding:2px 8px;margin-inline-start:6px}
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
    slot.innerHTML = '<div class="qv-load">{{ __('store.quick_view.loading') }}</div>';
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
          slot.innerHTML = '<div class="qv-load">{{ __('store.quick_view.load_failed') }}</div>';
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
