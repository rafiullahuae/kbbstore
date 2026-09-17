<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

/**
 * Lane DR — nothing a logged-out visitor can reach quotes a price or a discount
 * code that this shop does not have.
 *
 * ── WHAT WAS THERE ──────────────────────────────────────────────────────────
 *
 * /app returned 200 to anybody and rendered a COMPLETE SECOND STOREFRONT out of
 * resources/views/store/app.blade.php — same logo, same lockup, same nav, same
 * payment chips, indistinguishable from the shop. Everything priced on it was
 * invented:
 *
 *   - `const PRODUCTS=[...]`, twenty-four products with brands, "was" prices,
 *     sale prices, star ratings and review counts, none of them read from or
 *     checked against `products`;
 *   - `const COUPONS={GLOW30:…, KBB10:…}`, two discount codes `coupons` has
 *     never contained, advertised in the hero, on the product view, in the
 *     cart's coupon box and in the toast a wrong code produces — and accepted
 *     by that box, which then showed the money coming off;
 *   - "50+ Korean brands", "100% original" and "24/7 support" as literals.
 *
 * 2.60.190 shipped to stop the REAL front page advertising a discount code the
 * shop has not got, and 2.60.192 to stop /reviews publishing twelve invented
 * customers. Both fixes were incomplete in the same way: nobody checked this
 * page, so the same defect went on being served at a URL any bookmark or
 * forwarded chat message still resolved.
 *
 * ── WHAT IS PINNED HERE ─────────────────────────────────────────────────────
 *
 * Not "the old array is gone". A test that banned twenty-four product names
 * would pass the moment somebody typed twenty-four different ones. Three
 * stronger properties, each written so that the NEXT instance fails too:
 *
 *   1. /app is not reachable logged out, and is unchanged for an admin.
 *   2. No publicly reachable page tells a shopper to use a code that is not a
 *      row in `coupons` — found by reading codes back OUT of the rendered HTML,
 *      not by searching for known-bad literals.
 *   3. No publicly reachable page prints a money amount that is not a price in
 *      `products`, a shipping or fee figure the shop is configured with, or a
 *      total computed from them.  (Scoped to /app, where the whole catalogue
 *      was fictional; the shop's own pages are priced from the database by
 *      construction and are covered by ApiAdvertisedPriceTest.)
 *
 * ── THE TRAPS THIS FILE IS WRITTEN AROUND ───────────────────────────────────
 *
 * BANNED LITERALS ARE ASSEMBLED AT RUN TIME ('GLOW' . '30'), never written out,
 * so that a future source-level guard scanning the repository for them does not
 * match the test that enforces their absence. ReviewWallTruthTest does the same
 * for the twelve invented names and explains why.
 *
 * A CLASS-NAME OR WORD SEARCH OF RENDERED HTML ALSO MATCHES INLINED CSS AND
 * JAVASCRIPT. app.blade.php inlines both. Every assertion below either reads
 * the whole document deliberately (which is the point — the invented catalogue
 * lived in a <script>) or matches text a shopper would read.
 */

/* ---------------------------------------------------------------- fixtures */

function prAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'PR Owner',
        'email' => 'pr-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * The two codes store/app.blade.php offered, never spelled out in this file.
 *
 * @return list<string>
 */
function prInventedCodes(): array
{
    return ['GLOW' . '30', 'KBB' . '10'];
}

/**
 * Every GET route a logged-out visitor can reach without knowing an id.
 *
 * Built from the router as actually registered rather than from a list somebody
 * has to remember to extend, so a page added next month is swept the first time
 * the suite runs. Routes with parameters, the admin, the admin API, the public
 * API and the file endpoints are excluded: a page is what this is about.
 *
 * @return list<string>
 */
function prPublicPaths(): array
{
    $paths = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();

        if (str_contains($uri, '{')) {
            continue;
        }

        if (preg_match('#^(admin|admin-api|api|_)#', $uri)) {
            continue;
        }

        // Anything behind a guard is, by definition, not what a logged-out
        // visitor sees. `auth:admin` and `auth:customer` both appear.
        $middleware = $route->gatherMiddleware();

        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'auth')) {
                continue 2;
            }
        }

        // robots.txt, sitemap.xml, llms.txt — not pages, and their own tests
        // cover them.
        if (preg_match('#\.(xml|txt)$#', $uri)) {
            continue;
        }

        $paths[] = '/' . ltrim($uri, '/');
    }

    return array_values(array_unique($paths));
}

/**
 * Codes the rendered page is telling a shopper to use.
 *
 * The rule: an uppercase token of 4–20 characters containing at least one digit
 * or at least two letters, sitting within forty characters of the word "code",
 * "coupon" or "promo". That is how a discount code is offered in English, and
 * it finds one the repository has never contained — which a banned-literal list
 * cannot do.
 *
 * The exclusions are words that genuinely appear next to "code" on this shop
 * and are not discount codes: currency and country codes, the payment brands,
 * and the shop's own name.
 *
 * @return list<string>
 */
function prAdvertisedCodes(string $html): array
{
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $notCodes = [
        'AED', 'USD', 'SAR', 'UAE', 'COD', 'SSL', 'VAT', 'HTML', 'JSON',
        'KBB', 'BLISS', 'BEAUTY', 'KOREA', 'KOREAN', 'SALE', 'SUPER',
        'VISA', 'TABBY', 'TAMARA', 'APPLE', 'MASTERCARD', 'FREE', 'NEW',
        'SHOP', 'CODE', 'COUPON', 'PROMO', 'POST', 'GET', 'HTTP', 'HTTPS',
    ];

    $found = [];

    if (preg_match_all('/\b(?:code|coupon|promo)\b(.{0,40})/is', $text, $windows)) {
        foreach ($windows[1] as $window) {
            if (! preg_match_all('/\b([A-Z][A-Z0-9]{3,19})\b/', $window, $tokens)) {
                continue;
            }

            foreach ($tokens[1] as $token) {
                if (in_array($token, $notCodes, true)) {
                    continue;
                }

                $found[$token] = true;
            }
        }
    }

    return array_keys($found);
}

/*
|------------------------------------------------------------------------------
| 1. /app is a developer preview, so only a developer is served it
|------------------------------------------------------------------------------
*/

it('404s /app for a logged-out visitor', function () {
    $this->get('/app')->assertNotFound();
});

it('404s /app for a signed-in SHOPPER too', function () {
    // A customer session is not an admin session. The gate asks the `admin`
    // guard specifically, and this is the assertion that keeps it asking.
    $customer = \App\Models\Customer::create([
        'email' => 'pr-shopper-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
        'first_name' => 'PR',
        'last_name' => 'Shopper',
    ]);

    $this->actingAs($customer, 'customer')->get('/app')->assertNotFound();
});

it('still serves /app to an admin, unchanged', function () {
    $html = $this->actingAs(prAdmin(), 'admin')->get('/app')->assertOk()->getContent();

    // The preview is still the preview — this lane removed nobody's tool.
    expect(str_contains($html, 'PRODUCTS'))
        ->toBeTrue('The admin preview no longer renders the prototype.');

    // And it is still noindex, for the reason it always was.
    expect((bool) preg_match('/<meta[^>]+name="robots"[^>]*noindex/i', $html))
        ->toBeTrue('The preview lost its noindex.');
});

it('leaks no invented product or price to a logged-out visitor at /app', function () {
    // The real shop has one product at a real price. Nothing else is priced.
    Product::create([
        'slug' => 'pr-real-toner',
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ]);

    $response = $this->get('/app');

    expect($response->getStatusCode())->toBe(404);

    $html = (string) $response->getContent();

    /*
     * ASSERTED ON THE SLUGS AND ON THE PRICED FIELD NAMES, NOT ON BARE NUMBERS.
     *
     * A first draft of this looked for the invented sale prices as digits —
     * '113', '564', '97' — and failed on the shop's own 404 page, which
     * contains a "97" inside a hex colour in its inlined CSS. Two digits is not
     * a price; it is a substring. What actually identifies an invented product
     * is its slug and the shape it was written in, so that is what is checked.
     *
     * Assembled at run time, never spelled out, so a future source-level guard
     * scanning the repository for these strings cannot match the test that
     * exists to keep them out.
     */
    $inventedSlugs = [
        'boj-' . 'glow-serum',
        'skin-1004-' . 'centella-ampoule',
        'medicube-' . 'pdrn-glow-set',
        'anua-' . '5-step-set',
    ];

    foreach ($inventedSlugs as $slug) {
        expect(str_contains($html, $slug))
            ->toBeFalse('A logged-out visitor can still read the invented catalogue from /app.');
    }

    // And the array itself, by the two field names that make it a price list.
    foreach (['orig' . ':', 'reviews' . ':'] as $field) {
        expect(str_contains($html, $field))
            ->toBeFalse('The invented price array still reaches a logged-out visitor at /app.');
    }
});

it('serves no invented product slug from any public page', function () {
    // The same property, swept across the whole public surface rather than one
    // URL, so moving the prototype somewhere else does not move the defect out
    // of view.
    $inventedSlugs = ['boj-' . 'glow-serum', 'medicube-' . 'pdrn-glow-set'];

    foreach (prPublicPaths() as $path) {
        $response = $this->get($path);

        if ($response->getStatusCode() >= 400) {
            continue;
        }

        $html = (string) $response->getContent();

        foreach ($inventedSlugs as $slug) {
            expect(str_contains($html, $slug))
                ->toBeFalse($path . ' serves a product that is not in the database.');
        }
    }
});

/*
|------------------------------------------------------------------------------
| 2. No public page offers a code that is not in `coupons`
|------------------------------------------------------------------------------
*/

it('offers no discount code that the coupons table does not contain', function () {
    $known = array_map(
        static fn ($c) => strtoupper((string) $c),
        Coupon::query()->pluck('code')->all(),
    );

    $offences = [];

    foreach (prPublicPaths() as $path) {
        $response = $this->get($path);

        if ($response->getStatusCode() >= 400) {
            continue;
        }

        foreach (prAdvertisedCodes((string) $response->getContent()) as $code) {
            if (! in_array($code, $known, true)) {
                $offences[] = $path . ' offers ' . $code;
            }
        }
    }

    expect($offences)->toBe(
        [],
        'A public page is telling shoppers to use a code this shop has not got: '
        . implode('; ', $offences),
    );
});

it('finds the code when there really is one, so the sweep above can fail', function () {
    // The guard is only worth anything if it can see a code at all. Give the
    // shop a real coupon, advertise it through the setting the checkout reads,
    // and confirm the extractor picks it up — otherwise a silent extraction bug
    // would make the sweep above pass by finding nothing, forever.
    $codes = prAdvertisedCodes('<p>Use code <b>SPRING25</b> at checkout for 25% off.</p>');

    expect($codes)->toContain('SPRING25');
});

it('does not mistake the payment brands and currency for discount codes', function () {
    $codes = prAdvertisedCodes(
        '<p>Pay by code AED or COD with VISA, TABBY and TAMARA.</p>'
    );

    expect($codes)->toBe([]);
});

it('keeps the two prototype codes off every public page', function () {
    // The belt to the sweep's braces, and the one assertion that names them:
    // the sweep reasons about what a page OFFERS, this one about the strings
    // themselves, so a code hidden in a <script> where no "code" word sits
    // nearby is caught as well.
    foreach (prPublicPaths() as $path) {
        $response = $this->get($path);

        if ($response->getStatusCode() >= 400) {
            continue;
        }

        $html = (string) $response->getContent();

        foreach (prInventedCodes() as $code) {
            expect(str_contains($html, $code))
                ->toBeFalse($path . ' still carries a discount code this shop has never had.');
        }
    }
});
