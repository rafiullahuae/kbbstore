<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use Tests\Support\SqlShape;

/**
 * Judge the SQL every storefront page ISSUES, not the answer it returns.
 *
 * The suite runs on SQLite and the store runs on MySQL, and SQLite is the more
 * permissive of the two: a statement MySQL rejects outright comes back 200
 * here. The admin side has paid for that twice — the Customers screen's 1140
 * and the orders aggregate that kept an OFFSET — and the storefront had never
 * been put through the same check, which is what this file does.
 *
 * SqlShape's rules are in tests/Support/SqlShape.php. Each one is a statement
 * MySQL rejects or answers differently; none fires on portable SQL, so a
 * violation here is a bug in the query and never a reason to relax the rule.
 *
 * This runs on BOTH engines and is worth running on both. On SQLite it is the
 * only thing standing between a dialect bug and production; on MySQL the server
 * would reject the statement anyway, and this says which query and why rather
 * than leaving a 500 to be traced.
 */

/** Catalogue, content and a customer, so no page renders an empty branch. */
function shapeSeed(): array
{
    test()->seed(\Database\Seeders\DatabaseSeeder::class);
    test()->seed(\Database\Seeders\DemoReviewsSeeder::class);

    /*
     * One product per page, so that the 'shop paged' entry in shapePages()
     * below is a page that genuinely exists.
     *
     * ShopController 404s a page number past the last page rather than
     * clamping it back onto page one — an out-of-range page used to answer 200
     * with the page-one grid and a self-referencing canonical, which is an
     * unbounded supply of crawlable duplicates. The seeded catalogue is
     * smaller than the default page size, so /shop?paged=2 was out of range
     * and this walk stopped exercising the paginated branch's SQL at the
     * assertSuccessful() rather than at the query log. The page size is a
     * setting, so making page two real is one row, and every other page in the
     * walk issues the same statements with a smaller LIMIT.
     */
    \App\Models\Setting::updateOrCreate(['key' => 'products_per_page'], ['value' => '1']);
    \App\Models\Setting::flushMap();

    Post::create([
        'slug' => 'shape-article',
        'title' => 'Shape Article',
        'body' => '<p>Body.</p>',
        'excerpt' => 'Excerpt.',
        'status' => 'published',
        'published_at' => now(),
    ]);

    foreach (['delivery', 'refund_returns', 'faqs', 'about', 'contact-us'] as $slug) {
        Page::firstOrCreate(
            ['slug' => $slug],
            ['title' => ucfirst($slug), 'content' => '<p>Placeholder.</p>', 'status' => 'published']
        );
    }

    $customer = Customer::create([
        'name' => 'Ada Shopper',
        'email' => 'shape@example.com',
        'password' => 'password123',
    ]);

    $order = Order::create([
        'order_number' => 'SHP00001',
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'shipping_address' => [
            'first_name' => 'Ada', 'last_name' => 'Shopper', 'line1' => '12 Marina Walk',
            'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ],
    ]);

    $order->items()->create([
        'name' => 'Rice Toner', 'quantity' => 2,
        'unit_price' => 10000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    $customer->addresses()->create([
        'first_name' => 'Ada', 'last_name' => 'Shopper', 'line1' => '12 Marina Walk',
        'city' => 'Dubai', 'country' => 'AE',
    ]);

    return [
        'customer' => $customer,
        'order' => $order,
        'brand' => Brand::query()->first(),
        'category' => Category::query()->first(),
        'product' => Product::query()->visible()->first(),
    ];
}

/**
 * Every storefront page, including the query-string variants: a filter, a sort
 * and a search each build a DIFFERENT statement from the same controller, and
 * the dialect bugs this file is for live in the branches, not the default path.
 */
function shapePages(array $seed): array
{
    $brand = $seed['brand']->slug;
    $category = $seed['category']->slug;
    $product = $seed['product']->slug;
    $customerId = $seed['customer']->id;

    return [
        'home'                => ['/', null],
        'shop'                => ['/shop', null],
        'shop paged'          => ['/shop?paged=2', null],
        'shop sorted price'   => ['/shop?orderby=price', null],
        'shop sorted rating'  => ['/shop?orderby=rating', null],
        'shop sorted popular' => ['/shop?orderby=popularity', null],
        'shop brand filter'   => ['/shop?filter_brands=' . $brand, null],
        'shop price filter'   => ['/shop?min_price=10&max_price=200', null],
        'shop search'         => ['/shop?s=serum', null],
        // A search carrying LIKE metacharacters and a backslash: the exact
        // shape that behaves differently on the two engines without an
        // explicit ESCAPE clause.
        'shop search wild'    => ['/shop?s=' . rawurlencode('50%_off\\back'), null],
        'category'            => ['/product-category/' . $category, null],
        'brand index'         => ['/korean-skincare-brands', null],
        'brand page'          => ['/korean-skincare-brands/' . $brand, null],
        'product'             => ['/product/' . $product, null],
        'quick view'          => ['/quick-view/' . $seed['product']->id, null],
        'collection new-in'   => ['/new-in', null],
        'collection best'     => ['/best-sellers', null],
        'collection sale'     => ['/super-sale', null],
        'collection budget'   => ['/everything-under-54-aed', null],
        'cart'                => ['/cart', null],
        'cart drawer'         => ['/api/cart/drawer', null],
        /*
         * WITH an order number, which it did not have before.
         *
         * Bare, this page looks up nothing: `success()` skips the Order query
         * entirely when `?order=` is absent, so the only SQL it ever issued was
         * the header's free-delivery threshold — two queries that ran on every
         * page of the site because nothing cached them. Lane BQ cached the
         * shipping zones, and this page's captured log went empty, which the
         * "issued no SQL at all" guard below reads as a page that silently
         * rendered nothing.
         *
         * The guard is right and the fixture was wrong. /checkout/success is
         * the order-received page, its query is the eager-loaded Order with its
         * lines, their products and those products' brands, and that query — the
         * one whose dialect shape this file exists to check — was never being
         * captured here at all. It is now.
         *
         * The number is a real one from shapeSeed(). mayView() may still decide
         * this visitor is not entitled to see it and blank the order out, which
         * is fine: the lookup has already happened by then, the page is still a
         * 200, and what is being asserted is the SHAPE of the SQL, not who gets
         * to read the result.
         */
        'checkout success'    => ['/checkout/success?order=' . $seed['order']->order_number, null],
        'journal'             => ['/skincare-guide', null],
        'article'             => ['/shape-article', null],
        'search suggest'      => ['/api/search?q=serum', null],
        'search suggest wild' => ['/api/search?q=' . rawurlencode('50%_off\\back'), null],
        'search starter'      => ['/api/search/starter', null],
        // With something actually saved, so the page runs its own `whereIn`
        // rather than rendering an empty branch. See the $cookies note below.
        'wishlist'            => ['/my-wishlist', null, ['kbb_wishlist' => (string) $seed['product']->id]],
        // Client-rendered: the wall fetches /api/reviews from the browser, so
        // the page itself issues no SQL. Listed in SHAPE_STATIC below.
        'review wall'         => ['/reviews', null],
        'skin quiz'           => ['/skin-quiz', null],
        'app prototype'       => ['/app', null],
        'content page'        => ['/about', null],
        'my account'          => ['/my-account', $customerId],
        'account orders'      => ['/my-account/orders', $customerId],
        'account order'       => ['/my-account/orders/' . $seed['order']->id, $customerId],
        'account addresses'   => ['/my-account/edit-address', $customerId],
        // With both halves of the lookup, for the same reason as the wishlist
        // above: bare, track() returns the empty form before it queries
        // anything, so its `where order_number = ?` was never shape-checked.
        'track order'         => ['/track-my-order?order=' . $seed['order']->order_number
                                 . '&email=' . rawurlencode((string) $seed['order']->email), null],
        'sitemap'             => ['/sitemap.xml', null],
        'api products'        => ['/api/products', null],
        'api product'         => ['/api/products/' . $product, null],
        'api product reviews' => ['/api/products/' . $product . '/reviews', null],
        'api posts'           => ['/api/posts', null],
        'api reviews'         => ['/api/reviews', null],
    ];
}

/**
 * Pages that legitimately touch no database at all, so the "issued no SQL"
 * guard below does not read them as having silently rendered nothing.
 *
 * Each one is server-rendered chrome around a client-side page: the review wall
 * fetches /api/reviews from the browser, the quiz is entirely in JavaScript, and
 * /app is a self-contained prototype with its catalogue baked into the page. A
 * page appearing here that does have data to load is a bug, not an exemption.
 */
const SHAPE_STATIC = ['review wall', 'skin quiz', 'app prototype'];

/**
 * Drop the framework's own schema introspection from a captured log.
 *
 * SeoFilesController guards the sitemap with Schema::hasTable() and
 * hasColumn(), so a half-migrated database serves a short sitemap instead of a
 * 500. Laravel answers those by querying `sqlite_master` here and
 * `information_schema` on MySQL — it writes the dialect-correct statement for
 * whichever driver is connected — so the sqlite_master that SqlShape sees is
 * the framework being portable, not the application being unportable.
 *
 * Filtered by table name rather than by muting SqlShape's rule, because the
 * rule is right: `sqlite_master` written by OUR code would be a real bug, and
 * it must still fail.
 *
 * @param  list<array{sql: string, bindings: array<int, mixed>}>  $captured
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function shapeAppSql(array $captured): array
{
    return array_values(array_filter($captured, static function (array $entry): bool {
        return preg_match('/\b(sqlite_master|information_schema)\b/i', $entry['sql']) !== 1;
    }));
}

it('issues no dialect-unsafe SQL on any storefront page', function () {
    $seed = shapeSeed();
    $failures = [];

    foreach (shapePages($seed) as $label => $page) {
        [$path, $customerId] = $page;

        /*
         * An optional third element: cookies the visitor arrives with.
         *
         * Some storefront pages read their input from a cookie and do nothing
         * at all without one -- the wishlist is a cookie of product ids, and
         * with an empty cookie its controller deliberately issues no query
         * ("Rule 27: no query at all when the cookie is empty"). Until the
         * shipping zones were cached, every such page still showed up in this
         * capture because the header's free-delivery bar ran two queries on
         * every page of the site; with that gone, the page's log is empty and
         * the guard below reads it as silently broken.
         *
         * The fixture is what was wrong. A wishlist page with nothing in the
         * wishlist never exercised the `whereIn` this file exists to check the
         * shape of -- it was passing on somebody else's SQL. Handing it the
         * cookie makes it run its own.
         */
        $cookies = $page[2] ?? [];

        $captured = SqlShape::capture(function () use ($path, $customerId, $cookies) {
            $test = test();

            if ($cookies !== []) {
                $test = $test->withCredentials()
                    ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);

                foreach ($cookies as $name => $value) {
                    $test = $test->withUnencryptedCookie($name, $value);
                }
            }

            if ($customerId !== null) {
                $test = $test->withSession([
                    'login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class) => $customerId,
                ]);
            }

            $test->get($path)->assertSuccessful();
        });

        // Guard against a page that silently ran nothing being read as clean.
        if (! in_array($label, SHAPE_STATIC, true)) {
            expect($captured)->not->toBe([], "{$label} ({$path}) issued no SQL at all");
        }

        foreach (SqlShape::violations(shapeAppSql($captured)) as $violation) {
            $failures[] = $label . ' (' . $path . '): ' . $violation;
        }
    }

    expect($failures)->toBe([], "Dialect-unsafe SQL on the storefront:\n\n" . implode("\n\n", $failures));
});

/**
 * The LIKE escape, end to end rather than by inspection.
 *
 * A product whose name really contains a percent sign must be findable by
 * typing that name, and a wildcard typed into the search box must NOT behave as
 * a wildcard. Both engines have to agree, and without an explicit `ESCAPE`
 * clause they do not: a backslash is MySQL's default LIKE escape and means
 * nothing at all to SQLite, so the same query returns different rows in the
 * suite and in production.
 */
it('treats LIKE metacharacters in a search as literal text on both engines', function () {
    $seed = shapeSeed();

    Product::create([
        'slug' => 'literal-percent-cleanser',
        'name' => 'Niacinamide 30% Power Serum',
        'sku' => 'PCT-0001',
        'brand_id' => $seed['brand']->id,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ]);

    // The percent sign is part of the name, so searching for it finds the row.
    $this->get('/api/search?q=' . rawurlencode('30% Power'))
        ->assertOk()
        ->assertSee('Niacinamide 30% Power Serum', escape: false);

    /*
     * And the wildcard is not a wildcard. '%Power%' as a PATTERN would match
     * the product above on any engine; as LITERAL text it matches nothing,
     * because no product is named with percent signs around "Power". If the
     * escaping were dropped this assertion is the one that fails.
     */
    $this->get('/api/search?q=' . rawurlencode('%Power%'))
        ->assertOk()
        ->assertDontSee('Niacinamide 30% Power Serum', escape: false);
});

/**
 * The suggestion panel's relevance ranking, which is the half that was escaping
 * nothing.
 *
 * The WHERE clause and the ORDER BY have to agree about what the shopper typed.
 * They did not: the CASE ranking interpolated the raw term into a LIKE pattern
 * while SearchTerms escaped it for the matching. Only the ORDER of the results
 * was ever affected, which is exactly why it went unnoticed.
 */
it('ranks a name that starts with the search term above one that merely contains it', function () {
    $seed = shapeSeed();

    foreach (['Ultra Hydrating Serum' => 'RNK-0001', 'Serum Ultra Repair' => 'RNK-0002'] as $name => $sku) {
        Product::create([
            'slug' => \Illuminate\Support\Str::slug($name),
            'name' => $name,
            'sku' => $sku,
            'brand_id' => $seed['brand']->id,
            'type' => 'simple',
            'status' => 'publish',
            'is_visible' => true,
            'price' => 9900,
            'stock_status' => 'instock',
            'total_sales' => 0,
        ]);
    }

    $body = $this->get('/api/search?q=' . rawurlencode('Serum Ultra'))->assertOk()->getContent();

    $prefixMatch = strpos($body, 'Serum Ultra Repair');
    $containsOnly = strpos($body, 'Ultra Hydrating Serum');

    expect($prefixMatch)->not->toBeFalse()
        ->and($containsOnly)->not->toBeFalse()
        ->and($prefixMatch)->toBeLessThan(
            $containsOnly,
            'The product whose name STARTS with the term must rank first.'
        );
});
