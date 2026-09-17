<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Lane DR — every link the shop's own chrome emits goes somewhere.
 *
 * ── WHAT WAS BROKEN ─────────────────────────────────────────────────────────
 *
 * routes/web.php hard-codes seven content-page slugs, each pointed at
 * PageController::show() with ->defaults('slug', …). show() does
 * `firstOrFail()` on a PUBLISHED row, so each of those URLs is a 404 until the
 * `pages` table has one. Exactly two of the seven were ever seeded —
 * privacy-policy and terms-and-conditions, by 2026_08_29_140000. The other five
 * never were:
 *
 *     /delivery/         linked from the footer of every page
 *     /refund_returns/   linked from the footer of every page
 *     /faqs/             linked from the footer of every page
 *     /contact-us/       linked from the footer of every page
 *     /about/            linked from the home page
 *
 * Four dead links in the site chrome and a fifth on the front page, on routes
 * the application itself registers: the code promised the page existed and the
 * data did not deliver it. 2026_11_06_000000_seed_footer_content_pages seeds
 * all five, as editable rows rather than templates, for the reason the 08_29
 * migration gives.
 *
 * ── WHAT THIS FILE PINS ─────────────────────────────────────────────────────
 *
 * Not "those five now exist" — that passes for exactly as long as nobody adds a
 * sixth link. Three properties instead:
 *
 *   1. EVERY internal href the rendered FOOTER emits resolves. The footer is
 *      parsed out of a real response and each link followed, so a link added to
 *      the Blade, or arriving through `$kbbFooterNav` from the menus table,
 *      is walked without anybody having to list it here.
 *
 *   2. EVERY route that hard-codes a page slug has a published row. This is the
 *      generic form of the defect: it is read off the ROUTER, so a route added
 *      next month with ->defaults('slug', 'returns') and no migration fails the
 *      first time the suite runs, whether or not anything links to it yet.
 *
 *   3. NOTHING in the header or the mobile chrome 500s, and nothing there 404s
 *      except links whose target is a catalogue row — see the long note over
 *      chromeToleratedDeadEnd().
 *
 * ── TRAPS THIS FILE IS WRITTEN AROUND ───────────────────────────────────────
 *
 * A CLASS-NAME SEARCH OF RENDERED HTML ALSO MATCHES THE PAGE'S INLINED CSS.
 * The storefront inlines its stylesheets, so `.fcol` and `footer` both appear as
 * CSS text. Every region below is cut out with an ELEMENT-anchored regex over
 * the tags, and the links are extracted with preg_match_all over `<a … href>`
 * rather than by searching for the paths.
 *
 * NO NETWORK CALLS. External and social links are checked for SHAPE only —
 * scheme and host — and never fetched. A test that reached the internet would
 * fail on a CI runner with no egress and would be measuring somebody else's
 * uptime.
 *
 * Pest's toContain($needle, $msg) takes a SECOND NEEDLE, not a message, so
 * every message here goes through expect(<bool>)->toBeTrue('…').
 */

/* ---------------------------------------------------------------- helpers */

/**
 * Every href inside a rendered region, in document order and de-duplicated.
 *
 * @return list<string>
 */
function chromeHrefs(string $html): array
{
    preg_match_all('#<a\s[^>]*href\s*=\s*"([^"]*)"#i', $html, $m);

    return array_values(array_unique(array_filter(
        array_map('trim', $m[1]),
        static fn (string $h): bool => $h !== '',
    )));
}

/** The <footer> element of a rendered page. */
function chromeFooter(string $html): string
{
    expect((bool) preg_match('#<footer\b.*?</footer>#si', $html, $m))
        ->toBeTrue('The page rendered no <footer> element at all.');

    return $m[0];
}

/** The <header> element of a rendered page. */
function chromeHeader(string $html): string
{
    expect((bool) preg_match('#<header\b.*?</header>#si', $html, $m))
        ->toBeTrue('The page rendered no <header> element at all.');

    return $m[0];
}

/**
 * Everything the mobile chrome emits: the slide-out menu and the bottom tab
 * bar, both rendered server-side by partials/mobile-chrome.blade.php.
 */
function chromeMobile(string $html): string
{
    $regions = '';

    foreach (['#<div[^>]+id="mmDrawer".*?</div>\s*</div>#si', '#<nav[^>]+class="[^"]*\btb\b[^"]*".*?</nav>#si'] as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $regions .= $m[0];
        }
    }

    return $regions;
}

function chromeIsInternal(string $href): bool
{
    return ! preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $href)
        && ! str_starts_with($href, '#');
}

/**
 * Which registered route, if any, a path would reach.
 *
 * Used to tell "this link points at nothing the router knows about" apart from
 * "the route exists and the row behind it does not" — two 404s that mean
 * completely different things.
 */
function chromeRouteFor(string $path): ?\Illuminate\Routing\Route
{
    try {
        return Route::getRoutes()->match(Request::create($path, 'GET'));
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Is a 404 from this path one a bare test database explains?
 *
 * THE DISTINCTION, AND WHY IT IS DRAWN STRUCTURALLY RATHER THAN AS A LIST OF
 * PATHS. The header's mega menu is seeded from menu_items by
 * 2026_09_09_040000_seed_kbeautybliss_menu, carrying the LIVE SITE'S category
 * URLs — /toners/, /sunscreens/, /cleansing-oils/ and eleven more — and the
 * home page's routine strip links /product-category/{slug}/ for six slugs
 * hard-coded in Store\HomeController. Every one of those resolves to a
 * catalogue lookup, and this test database has no catalogue: the suite runs
 * migrations, and the products, brands and categories arrive by import.
 *
 * So a 404 from one of them says nothing about the link. A 404 from /delivery/
 * says everything about it, because that route hard-codes its slug and the
 * application is asserting the page exists.
 *
 * The rule is therefore about WHICH CONTROLLER the path reaches, not about
 * which path it is: a lookup by slug against a table the fixtures do not fill
 * is tolerated, and anything else — a route that hard-codes its target, or no
 * route at all — is not.
 *
 * THIS IS NOT A CLEAN BILL OF HEALTH FOR THOSE LINKS. Fourteen mega-menu items
 * point at WooCommerce-era flat category URLs (/toners/) while this application
 * serves categories at /product-category/{path}/, and the root catch-all that
 * currently answers them looks up a POST. Whether they resolve on the live
 * site depends on the redirects table and on imported data, which is a
 * catalogue and navigation question this lane does not own and cannot answer
 * from here. It is named in the lane report and the count is pinned below so
 * that it cannot quietly grow.
 */
function chromeToleratedDeadEnd(string $path): bool
{
    $route = chromeRouteFor($path);

    if ($route === null) {
        return false;
    }

    $action = $route->getActionName();

    // A route that hard-codes the row it wants is never tolerated: the code is
    // the thing making the promise.
    if ($route->defaults !== [] && array_key_exists('slug', $route->defaults)) {
        return false;
    }

    foreach ([
        'CategoryArchiveController',
        'BrandController',
        'ProductController',
        // The single-segment catch-all at the site root, which looks a post up
        // by slug. Everything the mega menu emits lands here.
        'PageController@post',
    ] as $catalogue) {
        if (str_contains($action, $catalogue)) {
            return true;
        }
    }

    return false;
}

/*
|------------------------------------------------------------------------------
| 1. The footer — every link, followed
|------------------------------------------------------------------------------
*/

it('emits no dead link in the footer', function () {
    $footer = chromeFooter($this->get('/')->assertOk()->getContent());
    $hrefs = chromeHrefs($footer);

    expect(count($hrefs))->toBeGreaterThan(8, 'The footer rendered almost no links; the parse is wrong.');

    $dead = [];

    foreach ($hrefs as $href) {
        if (! chromeIsInternal($href)) {
            continue;
        }

        $status = $this->get($href)->getStatusCode();

        if ($status >= 400) {
            $dead[] = $href . ' → ' . $status;
        }
    }

    expect($dead)->toBe([], 'The footer links to pages that do not exist: ' . implode(', ', $dead));
});

it('links only well-formed absolute URLs off-site, and never fetches them', function () {
    $footer = chromeFooter($this->get('/')->assertOk()->getContent());

    $external = array_filter(chromeHrefs($footer), static fn (string $h): bool => ! chromeIsInternal($h));

    expect(count($external))->toBeGreaterThan(0, 'The footer emitted no external links; the parse is wrong.');

    foreach ($external as $href) {
        // mailto: and tel: are addresses, not URLs with hosts.
        if (preg_match('#^(mailto|tel):#i', $href)) {
            expect(strlen($href))->toBeGreaterThan(8, $href . ' is an empty mailto/tel link.');
            continue;
        }

        $parts = parse_url($href);

        expect(is_array($parts) && isset($parts['scheme'], $parts['host']))
            ->toBeTrue($href . ' is not a well-formed absolute URL.');

        expect(in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true))
            ->toBeTrue($href . ' uses a scheme a browser will not follow.');
    }
});

it('keeps the four footer links that used to 404 alive', function () {
    // The named regression. The walker above would catch these anyway; this
    // says out loud which four they were, so the next reader does not have to
    // reconstruct it from a diff.
    foreach (['/delivery/', '/refund_returns/', '/faqs/', '/contact-us/'] as $path) {
        $this->get($path)->assertOk();
    }
});

/*
|------------------------------------------------------------------------------
| 2. Every route that hard-codes a page slug has a page behind it
|------------------------------------------------------------------------------
*/

it('backs every hard-coded page slug with a published row', function () {
    $slugs = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (! str_contains($route->getActionName(), 'PageController@show')) {
            continue;
        }

        $slug = $route->defaults['slug'] ?? null;

        if (is_string($slug) && $slug !== '') {
            $slugs[$slug] = '/' . ltrim($route->uri(), '/');
        }
    }

    expect(count($slugs))->toBeGreaterThanOrEqual(7, 'The router lost its fixed content-page routes.');

    $published = DB::table('pages')->where('status', 'published')->pluck('slug')->all();
    $missing = [];

    foreach ($slugs as $slug => $uri) {
        if (! in_array($slug, $published, true)) {
            $missing[] = $uri . ' (no published pages row for "' . $slug . '")';
        }
    }

    expect($missing)->toBe(
        [],
        'A route promises a content page that has no row, so it 404s: ' . implode(', ', $missing),
    );

    // And the promise holds end to end, not just in the table.
    foreach ($slugs as $uri) {
        $this->get($uri)->assertOk();
    }
});

/*
|------------------------------------------------------------------------------
| 3. The header and the mobile chrome
|------------------------------------------------------------------------------
*/

it('emits nothing in the header or mobile chrome that 500s or points nowhere', function () {
    $html = $this->get('/')->assertOk()->getContent();

    $hrefs = array_unique(array_merge(
        chromeHrefs(chromeHeader($html)),
        chromeHrefs(chromeMobile($html)),
    ));

    expect(count($hrefs))->toBeGreaterThan(10, 'The header and mobile chrome rendered almost no links; the parse is wrong.');

    $broken = [];
    $tolerated = [];

    foreach ($hrefs as $href) {
        if (! chromeIsInternal($href)) {
            continue;
        }

        $status = $this->get($href)->getStatusCode();

        if ($status >= 500) {
            $broken[] = $href . ' → ' . $status;
            continue;
        }

        if ($status === 404) {
            if (chromeToleratedDeadEnd($href)) {
                $tolerated[] = $href;
            } else {
                $broken[] = $href . ' → 404';
            }

            continue;
        }

        if ($status >= 400) {
            $broken[] = $href . ' → ' . $status;
        }
    }

    expect($broken)->toBe(
        [],
        'The header or mobile menu links to pages that do not exist: ' . implode(', ', $broken),
    );

    /*
     * THE CATALOGUE GAP, PINNED SO THAT IT CANNOT GROW QUIETLY.
     *
     * Fourteen of these are the mega menu's flat category URLs and the rest are
     * the home page's routine steps. They are tolerated because a bare test
     * database has no catalogue, NOT because they are known to be fine — see
     * chromeToleratedDeadEnd(). If this number rises, somebody added another
     * link that only resolves when the right row happens to exist, and they
     * should be made to look at it. If it falls, the navigation lane has fixed
     * some and this bound comes down with them.
     */
    expect(count($tolerated))->toBeLessThanOrEqual(
        20,
        'More chrome links now depend on catalogue rows that may not exist: ' . implode(', ', $tolerated),
    );
});

it('reaches the account area from the chrome without a 404', function () {
    // /my-account/orders and /my-account/edit-address sit behind auth:customer
    // and answer a logged-out visitor with a redirect to the sign-in page. That
    // is correct, and it is worth pinning that it is a REDIRECT and not the 404
    // the page would give if the route were lost.
    foreach (['/my-account/', '/my-account/orders/', '/my-account/edit-address/'] as $path) {
        $status = $this->get($path)->getStatusCode();

        expect($status < 400)->toBeTrue($path . ' answered ' . $status . ' to a logged-out shopper.');
    }
});
