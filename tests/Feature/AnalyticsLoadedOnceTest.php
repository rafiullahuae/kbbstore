<?php

declare(strict_types=1);

/**
 * Lane DP — one analytics identity per network, loaded exactly once.
 *
 * WHAT WAS WRONG. Three Google loaders and two Meta loaders had grown up in
 * this tree, each reading a different setting:
 *
 *   app/Support/Seo.php                        gtag from settings.ga
 *   app/Services/MarketingPixels.php           gtag from marketing_pixels.ga4_id
 *   a design mock under store/, since deleted   gtag from an /api/settings payload
 *
 * The first two both land in the <head> of every page that extends
 * layouts/store.blade.php — Seo::render() on line 82, baseTags() on line 140.
 * Two admin boxes for one measurement id is how both get filled in, and then
 * the page carries two loaders and two gtag('config', …) calls, which is two
 * page_view hits for one page view.
 *
 * HOW THIS TEST COUNTS. preg_match_all over the rendered HTML of REAL pages,
 * never str_contains: the bug is never "no tag", it is "two tags", and
 * str_contains reports both states identically. Assertions are on the script
 * elements and the call text, not on a substring that a page's inlined CSS or
 * JSON payload could also satisfy.
 *
 * Every id is configured in BOTH places on purpose — the legacy SEO boxes and
 * the Marketing Pixels module — because a shop with only one filled in never
 * had the defect.
 */

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Analytics;
use App\Services\SettingsService;

const DP_GA = 'G-DPLANE1234';
const DP_META = '111122223333444';
const DP_TIKTOK = 'CDPLANE0000';

/* ------------------------------------------------------------------ counts */

/** Loader <script src=…gtag/js…>, counted as elements. */
function dpGaLoaders(string $html): int
{
    return preg_match_all('#<script[^>]+src="[^"]*googletagmanager\.com/gtag/js[^"]*"#i', $html);
}

/** gtag('config', …) calls — one per measurement id per page, or GA counts twice. */
function dpGaConfigs(string $html): int
{
    return preg_match_all("#gtag\(\s*'config'#i", $html);
}

/** The Meta loader, identified by the library it inserts. */
function dpMetaLoaders(string $html): int
{
    return preg_match_all('#connect\.facebook\.net/[^\'"]*fbevents\.js#i', $html);
}

function dpMetaInits(string $html): int
{
    return preg_match_all("#fbq\(\s*'init'#i", $html);
}

function dpMetaPageViews(string $html): int
{
    return preg_match_all("#fbq\(\s*'track'\s*,\s*'PageView'#i", $html);
}

function dpTiktokLoaders(string $html): int
{
    return preg_match_all('#analytics\.tiktok\.com/i18n/pixel/events\.js#i', $html);
}

/* ----------------------------------------------------------------- fixtures */

/**
 * Every analytics id filled in, in BOTH of the two places that used to hold
 * one, written the way each screen writes it.
 */
function dpConfigureEverything(): void
{
    $settings = app(SettingsService::class);

    // The Marketing Pixels module — the canonical home.
    $settings->setModule(Analytics::MODULE, true);
    $settings->setModuleSetting(Analytics::MODULE, 'ga4_id', DP_GA);
    $settings->setModuleSetting(Analytics::MODULE, 'meta_id', DP_META);
    $settings->setModuleSetting(Analytics::MODULE, 'tiktok_id', DP_TIKTOK);

    // The SEO screen's rival boxes, straight into the settings table, exactly
    // as a shop that predates this change would carry them.
    Setting::updateOrCreate(['key' => 'ga'], ['value' => DP_GA]);
    Setting::updateOrCreate(['key' => 'meta_pixel'], ['value' => DP_META]);

    Setting::flushMap();
    SettingsService::forgetMemo();
}

function dpProduct(string $slug, ?Category $category = null): Product
{
    $product = Product::create([
        'slug' => $slug,
        'name' => 'DP ' . $slug,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 200,
        'stock_status' => 'instock',
    ]);

    if ($category !== null) {
        $product->categories()->attach($category->id);
    }

    return $product;
}

function dpOrder(): Order
{
    static $seq = 0;
    $seq++;

    $address = [
        'first_name' => 'Aisha', 'last_name' => 'Khan',
        'line1' => '12 Marina Walk', 'city' => 'Dubai',
        'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
    ];

    $order = Order::create([
        'order_number' => 'DP' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => 0,
        'gift_fee' => 0,
        'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'billing_address' => $address,
        'shipping_address' => $address,
    ]);

    $product = dpProduct('dp-ordered-' . $seq);

    $order->items()->create([
        'product_id' => $product->id,
        'name' => $product->name,
        'quantity' => 1,
        'unit_price' => 20000,
        'subtotal' => 20000,
        'total' => 20000,
    ]);

    return $order->fresh('items');
}

/** A cart cookie the storefront will pick up, with one line in it. */
function dpShopperWithCart()
{
    $product = dpProduct('dp-cart-' . uniqid());

    $cart = \App\Models\Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20000]);

    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(\App\Services\CartService::COOKIE, $cart->token);
}

/* ------------------------------------------------------- the real pages */

/**
 * page label => a closure returning that page's HTML.
 *
 * Six real storefront pages, fetched over the HTTP stack, so what is counted
 * is what a browser receives rather than what a helper returns. Each one is
 * fetched in its own request, which is also what makes the once-per-request
 * guard meaningful: a guard scoped to the process would pass this test by
 * emitting on the home page and nowhere else, and the "at least one" half of
 * every assertion below is what catches that.
 */
function dpPages(): array
{
    return [
        'home' => function () {
            dpProduct('dp-home-product');

            return test()->get('/')->assertOk()->getContent();
        },

        'category archive' => function () {
            $category = Category::create(['slug' => 'dp-cat', 'name' => 'DP Cat']);
            $category->update(['path' => 'dp-cat']);
            dpProduct('dp-cat-product', $category);

            return test()->get('/product-category/dp-cat/')->assertOk()->getContent();
        },

        'product' => function () {
            $product = dpProduct('dp-single-product');

            return test()->get('/product/' . $product->slug . '/')->assertOk()->getContent();
        },

        'cart' => fn () => dpShopperWithCart()->get('/cart')->assertOk()->getContent(),

        'checkout' => fn () => dpShopperWithCart()->get('/checkout')->assertOk()->getContent(),

        'checkout success' => function () {
            $order = dpOrder();

            return test()
                ->withSession(['kbb_last_order' => $order->order_number])
                ->get('/checkout/success?order=' . $order->order_number)
                ->assertOk()
                ->getContent();
        },
    ];
}

/* -------------------------------------------------------------- the pins */

it('loads each analytics library exactly once on every storefront page', function () {
    dpConfigureEverything();

    foreach (dpPages() as $label => $fetch) {
        $html = $fetch();

        expect(dpGaLoaders($html))->toBe(1, "{$label}: Google's tag must be loaded exactly once");
        expect(dpMetaLoaders($html))->toBe(1, "{$label}: the Meta pixel library must be loaded exactly once");
        expect(dpTiktokLoaders($html))->toBe(1, "{$label}: the TikTok pixel library must be loaded exactly once");
    }
});

/**
 * The library count is the easy half. fbq's loader opens with `if(f.fbq)return`
 * so a second copy of the library is a no-op — but `fbq('init', …)` and
 * `fbq('track','PageView')` sit AFTER that guard in the same script, so a
 * second emitter still doubles the init and the page view even though the
 * library loads once. gtag has no guard of any kind.
 */
it('configures each network exactly once on every storefront page', function () {
    dpConfigureEverything();

    foreach (dpPages() as $label => $fetch) {
        $html = $fetch();

        expect(dpGaConfigs($html))->toBe(1, "{$label}: gtag('config') twice is two page_view hits for one page view");
        expect(dpMetaInits($html))->toBe(1, "{$label}: fbq('init') must run once");
        expect(dpMetaPageViews($html))->toBe(1, "{$label}: fbq PageView must fire once");
    }
});

/**
 * The regression this replaces, stated as the thing that must not come back:
 * the SEO setting and the module setting being two live sources at once.
 */
it('does not emit a second loader from the legacy SEO analytics setting', function () {
    $settings = app(SettingsService::class);

    // Only the module configured…
    $settings->setModule(Analytics::MODULE, true);
    $settings->setModuleSetting(Analytics::MODULE, 'ga4_id', DP_GA);
    Setting::flushMap();
    SettingsService::forgetMemo();

    dpProduct('dp-legacy-product');

    $before = test()->get('/')->assertOk()->getContent();

    expect(dpGaLoaders($before))->toBe(1, 'the module id alone must load Google once');

    // …and now the SEO box as well, which is the state that used to double it.
    Setting::updateOrCreate(['key' => 'ga'], ['value' => DP_GA]);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $after = test()->get('/')->assertOk()->getContent();

    expect(dpGaLoaders($after))->toBe(1, 'filling in the second Google box must not add a second loader');
    expect(dpGaConfigs($after))->toBe(1, 'filling in the second Google box must not add a second config call');
});

/**
 * A page that renders its own <head> rather than extending the store layout —
 * the blog, a post, the quiz, /reviews, /app — used to get its analytics only
 * from Seo::render(), which meant switching the SEO Engine module off switched
 * analytics off with it. They go through the same emitter now.
 */
it('loads analytics on the standalone documents too, and only once', function () {
    dpConfigureEverything();

    // /skincare-guide/ is the blog index (routes/web.php names it `blog`;
    // /blog 301s to it). Asserted at 200 rather than skipped on anything else,
    // so a renamed route fails this test instead of quietly emptying it.
    /*
     * /app IS WALKED AS AN ADMIN — Lane DR.
     *
     * PageController::app() gives a logged-out visitor a 404 now: the preview
     * priced twenty-four invented products and offered two discount codes the
     * coupons table has never held, so it is served to an authenticated admin
     * and to nobody else. It still renders its own <head>, which is what this
     * test is about, so it stays in the sweep with a session that can reach it
     * rather than dropping out of coverage.
     */
    $admin = \App\Models\AdminUser::create([
        'name' => 'DP Owner',
        'email' => 'dp-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    foreach (['/skincare-guide/', '/skin-quiz/', '/reviews/', '/app/'] as $path) {
        $html = test()->actingAs($admin, 'admin')->get($path)->assertOk()->getContent();

        expect(dpGaLoaders($html))->toBe(1, "{$path}: Google's tag must be loaded exactly once");
        expect(dpGaConfigs($html))->toBe(1, "{$path}: one config call");
        expect(dpMetaInits($html))->toBe(1, "{$path}: one fbq init");
    }
});

/* ------------------------------------------------- one id, not two boxes */

it('shows the same Google id in both admin boxes, whichever one was typed in', function () {
    $analytics = app(Analytics::class);

    $analytics->setId('ga4', DP_GA);

    expect($analytics->id('ga4'))->toBe(DP_GA);
    expect(app(\App\Services\MarketingPixels::class)->all()['ga4_id'])->toBe(DP_GA);

    // And the legacy row is gone rather than left behind holding something else.
    expect(Setting::query()->where('key', 'ga')->exists())
        ->toBeFalse('the superseded settings row must not survive a save');
});

it('switches the pixels module on when an id arrives while it is off', function () {
    $settings = app(SettingsService::class);
    $settings->setModule(Analytics::MODULE, false);

    $analytics = app(Analytics::class);
    $analytics->setId('ga4', DP_GA);

    expect($analytics->enabled())
        ->toBeTrue('saving a Google id must leave the tag actually loading, as the screen promises');
});

it('reads a legacy id that no migration has moved yet, but only as a fallback', function () {
    $settings = app(SettingsService::class);
    $settings->setModule(Analytics::MODULE, true);

    Setting::updateOrCreate(['key' => 'ga'], ['value' => DP_GA]);
    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(app(Analytics::class)->id('ga4'))->toBe(DP_GA);

    // The canonical key wins the moment it holds anything, so the two can
    // never both be live.
    $settings->setModuleSetting(Analytics::MODULE, 'ga4_id', 'G-CANONICAL1');

    expect(app(Analytics::class)->id('ga4'))->toBe('G-CANONICAL1');
});

/* --------------------------------------------------------- shape and leakage */

/**
 * The shape check that used to live in App\Support\Seo, moved here with the
 * emitter it protects. htmlspecialchars() is no defence inside a <script>
 * body: the browser HTML-decodes the element's contents before the JS parser
 * sees them, so the only defence is the value's own shape.
 *
 * Marketing Pixels never applied this check to its own `ga4_id`, so junk in
 * that box produced a broken script tag on every page. One emitter now means
 * one check.
 */
it('emits nothing at all for an id that is not a measurement id', function () {
    $settings = app(SettingsService::class);
    $settings->setModule(Analytics::MODULE, true);

    dpProduct('dp-badid-product');

    foreach (["G-OK'));alert(1);//", '<script>alert(1)</script>', 'G-OK" onload="x'] as $bad) {
        $settings->setModuleSetting(Analytics::MODULE, 'ga4_id', $bad);
        Setting::flushMap();
        SettingsService::forgetMemo();

        $html = test()->get('/')->assertOk()->getContent();

        expect(dpGaLoaders($html))->toBe(0, 'a malformed id must not reach a script body: ' . $bad);
        expect(str_contains($html, 'alert(1)'))->toBeFalse('a malformed id must not reach a script body: ' . $bad);
    }
});

/**
 * The purchase tag is the only one that sees an order. It may carry the order
 * number, the total and the catalogue lines; it may not carry the customer.
 */
it('sends no customer data in the purchase tag', function () {
    dpConfigureEverything();

    $order = dpOrder();
    $order->update(['email' => 'private.buyer@example.com']);

    $html = test()
        ->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order=' . $order->order_number)
        ->assertOk()
        ->getContent();

    $tags = [];
    preg_match_all("#<script>(?:fbq|gtag|ttq)\((.*?)</script>#s", $html, $tags);
    $tagText = implode("\n", $tags[0]);

    expect($tagText)->not->toBe('', 'the purchase tags must actually be on the page');

    foreach ([
        'private.buyer@example.com',
        '12 Marina Walk',
        '+971500000000',
        'Aisha',
    ] as $private) {
        expect(str_contains($tagText, $private))
            ->toBeFalse('an analytics tag carries "' . $private . '", which is the customer, not the sale');
    }

    expect(str_contains($tagText, $order->order_number))
        ->toBeTrue('the purchase tag has to identify the sale by its order number');
});

it('json-encodes every value it interpolates into a tag', function () {
    dpConfigureEverything();

    $order = dpOrder();

    $html = test()
        ->withSession(['kbb_last_order' => $order->order_number])
        ->get('/checkout/success?order=' . $order->order_number)
        ->assertOk()
        ->getContent();

    /*
     * A PHP float rendered by string conversion is not always JavaScript:
     * 1.0E+25 is a syntax error inside an object literal, and a value that
     * arrives as one takes the whole tag — and therefore the sale — down with
     * it. Every number in these tags goes through json_encode(), so no
     * exponent notation can appear in one.
     */
    preg_match_all('#<script>(?:fbq|gtag|ttq)\(.*?</script>#s', $html, $tags);

    foreach ($tags[0] as $tag) {
        expect(preg_match('#\d[Ee][+-]\d#', $tag))
            ->toBe(0, 'a number reached a tag in exponent notation: ' . $tag);
    }

    expect(count($tags[0]))->toBeGreaterThan(0, 'the purchase tags must be on the page');
});
