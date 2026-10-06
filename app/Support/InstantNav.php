<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SiteLayout;

/**
 * INSTANT PAGE CHANGES.                                               (Lane SP)
 *
 * The owner, 6 October: "when i go to any product etc page, the browser bar
 * appears but the layout is shifting late. i want super blazing speed with
 * super fast shifting layout from one page to another." Appearance → Site
 * layout → Page speed. "Open pages instantly" ships ON, as he asked; the
 * fade ships OFF, because it was measured to cost speed (below).
 *
 * ── 1. FETCH THE NEXT PAGE BEFORE THE CLICK (nav_instant) ──────────────────
 *
 * Chrome, Edge and Android: Speculation Rules, `prefetch`, eagerness
 * `moderate` -- the document is fetched when the pointer has rested on a link
 * for ~200 ms or a finger goes down on it, and Chrome itself keeps at most two
 * such fetches in flight.
 *
 * NO `<link rel=prefetch>` FALLBACK FOR SAFARI, AND WHY. Safari does not
 * implement link prefetch (it is behind a disabled flag), and a fetch() made
 * ahead cannot help either: every shop page is `Cache-Control: no-cache,
 * private` with no validator, so the browser must fetch the document again at
 * the click whatever was fetched before. Firefox does implement link prefetch,
 * into its HTTP cache, under the same rule -- so a fallback would double the
 * page requests and save nothing. What makes Safari faster is the server
 * (3-4x, SettingsRequestMemo) and the fade below (Safari 18.2+).
 *
 * PREFETCH, NOT PRERENDER, AND THAT IS THE ANALYTICS DECISION. A prerendered
 * page RUNS ITS SCRIPTS before the shopper opens it. On this shop that is the
 * Meta Pixel's PageView and ViewContent and TikTok's page() (Analytics,
 * MarketingPixels -- neither waits for activation), and fbt.js's "Most viewed"
 * beacon (ProductViews). A prefetch fetches the HTML and runs nothing: every
 * pixel and beacon fires when, and only if, the page is really opened. GA4's
 * gtag would have been safe either way; the others would not.
 *
 * What a prefetch DOES do on the server is render the page, so the one thing
 * a product page writes on a view -- the "Recently viewed" cookie -- is left
 * alone for a speculative request (isSpeculative(), ProductController).
 *
 * ONLY SHOP PAGES ARE EVER FETCHED: an ALLOWLIST of path prefixes, never "every
 * link except ...". The cart, checkout, payment returns, account, wishlist,
 * login/logout/register, the admin path, the owner app, /api and every file
 * simply are not on it -- and the admin path, a secret setting, is never
 * printed into the page to exclude it. On top of the list: no URL with a query
 * string (add-to-cart, filters, ?page=), and no link marked rel=nofollow,
 * data-no-prefetch, download or target=_blank.
 *
 * A PAGE FETCHED BEFORE A CART CHANGE WOULD SHOW THE OLD CART. So any
 * same-origin fetch() that is not a GET (add to cart, wishlist, newsletter ...)
 * drops the rules and puts them back, which discards what was fetched; the
 * next hover fetches the page as it is now.
 *
 * Data Saver and 2G: nothing is fetched (navigator.connection), on top of
 * Chrome's own refusal under Data Saver and Energy Saver.
 *
 * ── 2. A QUICK CROSS-FADE, HEADER HELD STILL (nav_fade) ────────────────────
 *
 * `@view-transition { navigation: auto }` (Chrome 126+, Safari 18.2+): the
 * old page stays on screen until the new one can paint, then a 0.15 s fade,
 * with the header given its own view-transition-name so it does not flash.
 * prefers-reduced-motion: no transition at all. Other browsers ignore it.
 *
 * SHIPPED OFF, MEASURED. Chromium 141, click to the next page's first paint,
 * same server, median of 5 (tools/spd-navtime.cjs): laptop category→product
 * 355 ms prefetch alone vs 433 ms prefetch + fade, brand→product 402 vs 491;
 * fade alone 514 / 577 against 458 / 466 with neither. Chrome already holds
 * the old page on screen until the new one paints (paint holding), so the
 * fade buys a softer change, not the absence of a white flash -- and costs
 * the speed the owner asked for. One switch turns it on.
 *
 * Both are constants plus the base path -- nothing a setting holds is printed
 * except the two booleans that decide whether to print at all (rule 5).
 */
final class InstantNav
{
    /** Request attribute: the product a speculative page asks to record when shown. */
    public const VIEWED_LATER = 'kbb.viewed-later';

    /**
     * The shop pages worth fetching ahead: listings, products, brands, blog.
     * Each is a path PREFIX (the wildcard is added below), plus the home page
     * exactly. Every one is GET-only and changes nothing.
     */
    public const PREFIXES = [
        UrlScheme::PRODUCT_BASE,
        UrlScheme::COLLECTION_BASE,
        UrlScheme::BRAND_BASE,
        UrlScheme::BLOG_BASE,
        UrlScheme::SHOP_BASE,
        '/new-in/',
        '/best-sellers/',
        '/super-sale/',
        '/everything-under-54-aed/',
        '/concern/',
    ];

    /** Links never fetched ahead, whatever their address. */
    public const SKIP_SELECTOR = '[rel~=nofollow],[data-no-prefetch],[download],[target=_blank]';

    public static function on(): bool
    {
        return (bool) app(SiteLayout::class)->get('nav_instant');
    }

    public static function fade(): bool
    {
        return (bool) app(SiteLayout::class)->get('nav_fade');
    }

    /**
     * The allowlist as this request's addresses: base path and, on /ar/, the
     * Arabic prefix and display slugs, exactly as the page's own links are
     * built (Url::to). '/' first, matched exactly.
     *
     * @return list<string>
     */
    public static function prefixes(): array
    {
        $out = [Url::to('/')];

        foreach (self::PREFIXES as $prefix) {
            // A probe segment shows how this locale spells the prefix; it is
            // cut off again, so only the prefix itself reaches the page.
            $probe = Url::to($prefix.'kbbspdprobe/');
            $at = strpos($probe, 'kbbspdprobe/');
            $out[] = $at === false ? Url::raw($prefix) : substr($probe, 0, $at);
        }

        return array_values(array_unique($out));
    }

    /**
     * The Speculation Rules document for these prefixes. Built here so the
     * test can read exactly what the browser gets; the page builds the same
     * object in its inline script from prefixes() (one list, two consumers).
     *
     * @param  list<string>  $prefixes
     * @return array<string, mixed>
     */
    public static function rules(array $prefixes): array
    {
        $home = array_shift($prefixes);
        $paths = array_merge([$home], array_map(static fn (string $p): string => $p.'*', $prefixes));

        return ['prefetch' => [[
            'where' => ['and' => [
                /*
                 * EVERY PATTERN SAYS `search: ""`, and that is load-bearing.
                 * The obvious string form, "/product/*", leaves the query a
                 * wildcard: measured in Chromium 141, it fetched
                 * /product/x/?add-to-cart=1 on hover. And the documented-looking
                 * exclusion "/*\?*" matched EVERY link and fetched nothing.
                 * A dictionary with an empty search matches an address with no
                 * query at all; the selector below is the second lock.
                 */
                ['href_matches' => array_map(static fn (string $p): array => ['pathname' => $p, 'search' => ''], $paths)],
                ['not' => ['selector_matches' => self::SKIP_SELECTOR.",[href*='?'],[href^='#']"]],
            ]],
            'eagerness' => 'moderate',
        ]]];
    }

    /**
     * A request Chrome made to fetch ahead (Speculation Rules prefetch or
     * prerender, `<link rel=prefetch>`): `Sec-Purpose: prefetch` (or
     * `prefetch;prerender`), and the older `Purpose: prefetch`. Such a request
     * may never be opened, so it must not record anything as viewed.
     */
    /** The product id the page should record when it is really opened, or 0. */
    public static function viewedLater(): int
    {
        return app()->bound('request') ? (int) request()->attributes->get(self::VIEWED_LATER, 0) : 0;
    }

    public static function isSpeculative(?\Illuminate\Http\Request $request = null): bool
    {
        $request ??= request();
        $purpose = strtolower((string) ($request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? ''));

        return str_contains($purpose, 'prefetch');
    }
}
