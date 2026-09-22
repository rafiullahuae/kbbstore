<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;

/**
 * Walk every storefront GET route the router actually registers.
 *
 * WHY THIS FILE EXISTS.
 *
 * Three storefront pages have shipped pointing at controller methods that were
 * never written. /skin-quiz and /reviews 500'd on every visit for months. /app
 * 500'd from the 2.60.41 baseline until this lane found it — "Call to undefined
 * method App\Http\Controllers\Store\PageController::app()" — because nothing in
 * the suite had ever requested the page. In all three the Blade view was
 * complete and sitting in the tree; only the controller half was missing, so
 * reading the repo told you nothing was wrong.
 *
 * A test per page would not have caught any of them, because the missing page
 * is by definition the one nobody thought to write a test for. So this walks
 * the ROUTER, not a list: every GET route the application registers is
 * requested, and the coverage test below fails if a route exists that this file
 * does not know about. Adding a route to web.php without adding it here is a red
 * suite, which is the only thing that makes "this can never recur" true.
 *
 * Admin routes are out of this lane's scope and are skipped by prefix — but see
 * the last test in this file, which proves every route in the application,
 * admin included, resolves to a callable action. That one is static: it needs no
 * data and no request, and it is what catches a missing controller method
 * everywhere the walk below does not reach.
 */

/** The session key Illuminate's session guard reads for the `customer` guard. */
function walkCustomerSessionKey(): string
{
    return 'login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class);
}

/**
 * Realistic storefront data: catalogue, reviews, an article, the editable
 * pages, a customer with an order and an address, and the two settings that
 * turn the token-gated endpoints on.
 *
 * @return array<string, mixed>
 */
function walkSeedStorefront(): array
{
    test()->seed(\Database\Seeders\DatabaseSeeder::class);
    test()->seed(\Database\Seeders\DemoReviewsSeeder::class);

    $post = Post::create([
        'slug' => 'walk-article',
        'title' => 'Walk Article',
        'body' => '<p>Body copy.</p>',
        'excerpt' => 'An article, so the journal and the root-slug route have one.',
        'status' => 'published',
        'published_at' => now(),
    ]);

    /*
     * Every slug PageController::show() is routed for. The seeding migration
     * creates only privacy-policy and terms-and-conditions; the other five are
     * created here so this walk tests the CONTROLLER rather than the contents
     * of the pages table. That the live table is missing five of them is a
     * separate, real defect — the footer links all four of /delivery/,
     * /refund_returns/, /faqs/ and /contact-us/ on every page of the site, and
     * every one of those is a 404 on a fresh install. It is reported rather
     * than papered over here, because the fix is the owner's delivery and
     * returns policy, which is content this repo does not have.
     */
    foreach (['delivery', 'refund_returns', 'faqs', 'about', 'contact-us'] as $slug) {
        Page::firstOrCreate(
            ['slug' => $slug],
            ['title' => ucfirst($slug), 'content' => '<p>Placeholder.</p>', 'status' => 'published']
        );
    }

    $customer = Customer::create([
        'name' => 'Ada Shopper',
        'email' => 'walk@example.com',
        'password' => 'password123',
    ]);

    $product = Product::query()->visible()->first();

    $order = Order::create([
        'order_number' => 'WALK00001',
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'gift_fee' => 0,
        'tax_total' => 0,
        'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'shipping_address' => [
            'first_name' => 'Ada', 'last_name' => 'Shopper',
            'line1' => '12 Marina Walk', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971500000000',
        ],
    ]);

    $order->items()->create([
        'name' => $product?->name ?? 'Rice Toner',
        'product_id' => $product?->id,
        'brand' => 'Beauty of Joseon',
        'quantity' => 2,
        'unit_price' => 10000,
        'subtotal' => 20000,
        'total' => 20000,
    ]);

    $address = $customer->addresses()->create([
        'first_name' => 'Ada',
        'last_name' => 'Shopper',
        'line1' => '1 Test Street',
        'city' => 'Dubai',
        'country' => 'AE',
    ]);

    // Token-gated endpoints: give them their token so the walk exercises the
    // handler instead of the 404 the guard returns to everyone else.
    config(['kbb.health_token' => 'walk-health-token']);
    Setting::updateOrCreate(['key' => 'indexnow_key'], ['value' => 'walkindexnowkey123']);
    Setting::flushMap();

    return compact('post', 'customer', 'product', 'order', 'address');
}

/**
 * Every storefront GET route, as the router spells its URI, with the parameters
 * to request it by and the status it must answer with.
 *
 * A status other than 200 is spelled out with its reason. Nothing here may be a
 * 5xx: that is the whole point of the file.
 */
function walkExpectations(array $seed): array
{
    $product = $seed['product'];
    $order = $seed['order'];
    $address = $seed['address'];
    $customer = $seed['customer'];
    $brandSlug = \App\Models\Brand::query()->value('slug');
    $catSlug = \App\Models\Category::query()->value('slug');

    return [
        // --- SEO and infrastructure files -------------------------------
        'up'                       => ['status' => 200],
        'sitemap.xml'              => ['status' => 200],
        'robots.txt'               => ['status' => 200],
        'llms.txt'                 => ['status' => 200],
        /*
         * Asked for by the key the application reports rather than by the one
         * the seeder wrote: IndexNow::key() memoises in a static, and so does
         * Setting::map() underneath it (see CLAUDE.md), so in a long-lived
         * process the key in the settings table is not necessarily the key the
         * route will honour. The contract worth pinning is that whatever key
         * the app publishes is the one it serves.
         */
        '{key}.txt'                => [
            'params' => ['key' => fn () => \App\Services\Seo\IndexNow::key()],
            'status' => 200,
        ],
        '_design-check'            => ['status' => 200],
        '_kbb-health'              => ['query' => ['token' => 'walk-health-token'], 'status' => 200],
        'storage/{path}'           => [
            'params' => ['path' => 'kbb/app.css'],
            // The local disk is private; the route exists to serve public
            // uploads and refuses anything else.
            'status' => 403,
        ],

        // --- Catalogue --------------------------------------------------
        '/'                        => ['status' => 200],
        'shop'                     => ['status' => 200],
        // U-07: the indexed /shop/page/2/ address 301s onto ?paged=.
        'shop/page/{page}'         => ['params' => ['page' => '2'], 'status' => 301],
        'product-category/{path}'  => ['params' => ['path' => $catSlug], 'status' => 200],
        'product/{slug}'           => ['params' => ['slug' => $product->slug], 'status' => 200],
        // The legacy ?slug= form with no slug falls back to the shop.
        'product'                  => ['status' => 302],
        'quick-view/{id}'          => ['params' => ['id' => (string) $product->id], 'status' => 200],
        'new-in'                   => ['status' => 200],
        'best-sellers'             => ['status' => 200],
        'super-sale'               => ['status' => 200],
        'everything-under-54-aed'  => ['status' => 200],

        // --- Brands -----------------------------------------------------
        'korean-skincare-brands'         => ['status' => 200],
        'korean-skincare-brands/{slug}'  => ['params' => ['slug' => $brandSlug], 'status' => 200],
        'brands'                         => ['status' => 301],
        'brand/{slug}'                   => ['params' => ['slug' => $brandSlug], 'status' => 301],

        // --- Journal ----------------------------------------------------
        'skincare-guide'           => ['status' => 200],
        'skincare-guide/{slug}'    => ['params' => ['slug' => 'walk-article'], 'status' => 301],
        '{slug}'                   => ['params' => ['slug' => 'walk-article'], 'status' => 200],
        'blog'                     => ['status' => 301],
        'post/{slug?}'             => ['params' => ['slug' => 'walk-article'], 'status' => 301],

        // --- Editable content pages -------------------------------------
        'privacy-policy'           => ['status' => 200],
        'terms-and-conditions'     => ['status' => 200],
        'delivery'                 => ['status' => 200],
        'refund_returns'           => ['status' => 200],
        'faqs'                     => ['status' => 200],
        'about'                    => ['status' => 200],
        'contact-us'               => ['status' => 200],

        // --- Standalone pages -------------------------------------------
        'skin-quiz'                => ['status' => 200],
        'reviews'                  => ['status' => 200],
        /*
         * Regression: this route pointed at a method that did not exist from
         * the 2.60.41 baseline until 2.60.110, and 500'd on every visit.
         *
         * 404 AND NOT 200 SINCE LANE DR. Writing the method turned the 500 into
         * a 200, and the 200 is what made the real defect reachable: the view is
         * a complete second storefront whose catalogue is twenty-four invented
         * products at invented prices, offering two discount codes `coupons`
         * has never held. It is a developer preview, so PageController::app()
         * serves it to an authenticated admin and gives everybody else the same
         * 404 the router gives for a path that was never registered. This walk
         * is a logged-out visitor, so 404 is the correct answer here, and
         * tests/Feature/PublicPagesQuoteRealPricesTest.php holds both halves.
         */
        'app'                      => ['status' => 404],


        // --- Cart and checkout ------------------------------------------
        'cart'                     => ['status' => 200],
        'api/cart/drawer'          => ['status' => 200],
        // Admin-guarded: a guest is bounced to the admin login.
        'api/cart/debug'           => ['status' => 302],
        // An empty cart is sent back to the cart rather than shown a checkout.
        'checkout'                 => ['status' => 302],
        'checkout/success'         => ['status' => 200],
        'checkout/pending'         => ['status' => 302],

        /*
         * 404, and deliberately so: CartAddressController aborts unless the
         * squeezed cart layout is switched on, and `cartpage_layout` ships
         * `classic`. The endpoint does not exist on a shop that is not using
         * the page it belongs to -- writes included, which is the point of the
         * gate rather than a side effect of it.
         *
         * This walk runs on default settings, so 404 is the honest expectation
         * here. Ownership, and the 404-not-403 on a stranger's id, are covered
         * in CartPageSqueezeTest.
         *
         * IT ANSWERS 200 NOW, WITH THE CLASSIC CART LAYOUT ON, and that is the
         * change rather than a hole. The gate used to read `squeezed()`,
         * because the squeezed cart page was the only thing that opened the
         * address sheet. The CHECKOUT opens it now — its Shipping address
         * section is a picker over the same addresses — and the checkout does
         * not care which layout the cart page is set to. Left as it was, every
         * one of these endpoints answered 404 to the checkout's own picker on
         * any shop running the classic cart.
         *
         * The listing is still safe to reach: a signed-out shopper is answered
         * from the session with no query at all, and a signed-in one is
         * answered only their own rows. Reading it tells a stranger nothing,
         * which is why the gate was never the security boundary — the
         * per-route auth:customer middleware is.
         *
         * MUTATION: drop the abort_unless from the controller entirely. Still
         * red in CartPageSqueezeTest, which pins that the gate exists and sits
         * in the constructor where it covers the writes.
         */
        'cart/address'             => ['status' => 200],

        // --- Wishlist ---------------------------------------------------
        'my-wishlist'              => ['status' => 200],
        'wishlist'                 => ['status' => 302],
        'wishlist/ids'             => ['status' => 200],

        // --- Account ----------------------------------------------------
        'my-account'               => ['status' => 200],
        'track-my-order'           => ['status' => 200],
        'my-account/forgot'        => ['status' => 200],
        // Guest-facing: guarded pages bounce, and the same URLs are walked
        // again signed in by the test below this one.
        'my-account/orders'              => ['status' => 302, 'auth' => 200],
        'my-account/orders/{id}'         => ['params' => ['id' => fn () => (string) $order->id], 'status' => 302, 'auth' => 200],
        'my-account/edit-address'        => ['status' => 302, 'auth' => 200],
        'my-account/edit-address/{id}'   => ['params' => ['id' => fn () => (string) $address->id], 'status' => 302, 'auth' => 200],
        'my-account/verify'              => ['status' => 302],
        /*
         * Newsletter confirm and unsubscribe, opened from a shopper's inbox.
         * 200 for a bad id on purpose, and it is the same reasoning as the
         * reset form below: these pages render for ANY well-formed link and
         * judge the signature on submit, because answering 404 for an id that
         * was never issued would make the page a membership oracle -- "is this
         * address on the list?" answered to anyone who can guess a small
         * integer. App\Support\CustomerLinkSigner's decoy row is what keeps the
         * work, and therefore the timing, identical on both paths.
         */
        'newsletter/confirm/{id}'        => [
            'params' => ['id' => '999999'],
            'status' => 200,
        ],
        'newsletter/unsubscribe/{id}'    => [
            'params' => ['id' => '999999'],
            'status' => 200,
        ],
        /*
         * Getting out of the back-in-stock and basket-reminder mails. 200 for
         * an unsigned link with an id that was never issued, and it is the
         * newsletter reasoning immediately above rather than a slip: the page
         * renders for any well-formed link and the signature is judged on
         * SUBMIT. A 404 here would answer "is this address waiting for a
         * product?" to anyone who can guess a small integer, and
         * OutboundOptOut::act() verifies against a decoy row so the work, and
         * therefore the timing, is the same on both paths.
         *
         * Walking it also pins the half that matters operationally: this is the
         * address in the footer of every marketing mail this shop sends, and an
         * unsubscribe link that 404s is the complaint that gets a sending
         * domain blocked. The route is registered above the root catch-all and
         * 'mail-preferences' is in RESERVED_SLUGS; if either regresses, this
         * expectation goes red rather than the link going quiet.
         */
        'mail-preferences/{kind}/{id}'   => [
            'params' => ['kind' => 'stock', 'id' => '999999'],
            'status' => 200,
        ],
        // An unsigned link with a wrong hash is refused, signed in or not.
        'my-account/verify/{id}/{hash}'  => [
            'params' => ['id' => (string) $customer->id, 'hash' => 'not-the-hash'],
            'status' => 404,
        ],
        /*
         * The form renders for any well-formed link and the token is judged on
         * submit, deliberately: answering 404 here for a token that does not
         * match would turn the page into a "is this reset link live?" oracle.
         * The token has to satisfy the route's [A-Za-z0-9]+ constraint, so this
         * is shaped like the 64-char hex DatabaseTokenRepository issues.
         */
        'my-account/reset/{id}/{token}'  => [
            'params' => ['id' => (string) $customer->id, 'token' => str_repeat('a1b2c3d4', 8)],
            'status' => 200,
        ],

        // --- Storefront JSON endpoints (session/cookie, not api.php) -----
        'api/search'               => ['query' => ['q' => 'serum'], 'status' => 200],
        'api/search/starter'       => ['status' => 200],
        'api/human-check'          => ['status' => 200],
        'reviews/captcha'          => ['status' => 200],

        // --- Public API (api.php) ---------------------------------------
        'api/products'                  => ['status' => 200],
        'api/products/{slug}'           => ['params' => ['slug' => $product->slug], 'status' => 200],
        'api/products/{slug}/reviews'   => ['params' => ['slug' => $product->slug], 'status' => 200],
        'api/posts'                     => ['status' => 200],
        'api/posts/{slug}'              => ['params' => ['slug' => 'walk-article'], 'status' => 200],
        'api/reviews'                   => ['status' => 200],
        'api/settings'                  => ['status' => 200],

        // --- Catch-all --------------------------------------------------
        '{fallbackPlaceholder}'    => ['params' => ['fallbackPlaceholder' => 'no-such-page-at-all'], 'status' => 404],
    ] + (Route::has('routines.index') ? [
        /*
         * Phase 10 — Build my routine (Lane FM), and it is added CONDITIONALLY
         * on purpose.
         *
         * Its two pages live in routes/build-my-routine.php, which routes/web.php
         * does not require yet: CLAUDE.md forbids that lane from editing web.php,
         * so the integrator adds one line (docs/FM-ADMIN-APP-BLOCKS.md). This
         * file fails BOTH ways — a registered route with no entry, and an entry
         * for a route that is not registered — so an unconditional pair would
         * have to be added in the same commit as that line or the suite goes
         * red, and the whole point of the walk is that adding a route cannot
         * quietly go uncovered. Keyed off the route NAME, so the day the require
         * lands these light up with it and nobody has to remember.
         *
         * 404 because the module SHIPS OFF and this walk is a shop in its
         * default state — which is exactly what makes the entry worth having:
         * both pages must answer a clean 404 rather than an exception or a
         * half-rendered page. tests/Feature/BuildMyRoutineTest.php turns the
         * module on and asserts 200 from both.
         */
        'routines'                 => ['status' => 404],
        'routines/{concern}'       => ['params' => ['concern' => 'hydration'], 'status' => 404],
    ] : []);
}

/** Every storefront GET route URI the router has registered. */
function walkRegisteredUris(): array
{
    $uris = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();

        // Not this lane's: the admin panel and its API have their own suites.
        if ($uri === 'admin' || str_starts_with($uri, 'admin/') || str_starts_with($uri, 'admin-api/')) {
            continue;
        }

        $uris[] = $uri;
    }

    return array_values(array_unique($uris));
}

/** Turn a router URI plus parameters into a requestable path. */
function walkPathFor(string $uri, array $spec): string
{
    $params = $spec['params'] ?? [];

    $path = preg_replace_callback('/\{([a-zA-Z_]+)\??\}/', function ($m) use ($params, $uri) {
        $value = $params[$m[1]] ?? null;
        $value = $value instanceof Closure ? $value() : $value;

        if ($value === null) {
            throw new RuntimeException("No parameter '{$m[1]}' given for route '{$uri}'.");
        }

        return (string) $value;
    }, $uri);

    $path = '/' . ltrim($path, '/');

    if (! empty($spec['query'])) {
        $path .= '?' . http_build_query($spec['query']);
    }

    return $path;
}

/* ------------------------------------------------------------------ tests */

/*
 * The coverage guard. Without this the walk is just another hand-written list,
 * and a route added tomorrow is exactly as unwalked as /app was for a year.
 */
it('has an expectation for every storefront GET route the router registers', function () {
    $seed = walkSeedStorefront();
    $known = array_keys(walkExpectations($seed));
    $registered = walkRegisteredUris();

    $missing = array_values(array_diff($registered, $known));

    expect($missing)->toBe([], sprintf(
        "These storefront GET routes have no entry in walkExpectations():\n  %s\n"
        . 'Add each one with the status it must answer, so it is walked on every run.',
        implode("\n  ", $missing)
    ));

    // And nothing stale: an expectation for a route that no longer exists is a
    // page this file thinks it is covering and is not.
    $stale = array_values(array_diff($known, $registered));
    expect($stale)->toBe([], 'walkExpectations() lists routes the router does not register: ' . implode(', ', $stale));

    // Guard against the whole thing silently walking nothing.
    expect(count($registered))->toBeGreaterThan(50);
});

it('serves every storefront GET route without a server error', function () {
    $seed = walkSeedStorefront();
    $expectations = walkExpectations($seed);
    $failures = [];

    foreach (walkRegisteredUris() as $uri) {
        $spec = $expectations[$uri];
        $path = walkPathFor($uri, $spec);

        try {
            $status = $this->get($path)->getStatusCode();
        } catch (\Throwable $e) {
            $failures[] = sprintf('%s (%s) threw %s: %s', $uri, $path, get_class($e), $e->getMessage());

            continue;
        }

        if ($status !== $spec['status']) {
            $failures[] = sprintf('%s (%s) answered %d, expected %d', $uri, $path, $status, $spec['status']);
        }
    }

    expect($failures)->toBe([], "Storefront routes answered the wrong status:\n  " . implode("\n  ", $failures));
});

it('serves the customer-guarded account pages to a signed-in customer', function () {
    $seed = walkSeedStorefront();
    $expectations = walkExpectations($seed);
    $failures = [];

    foreach ($expectations as $uri => $spec) {
        if (! isset($spec['auth'])) {
            continue;
        }

        $path = walkPathFor($uri, $spec);

        // Sign in the way a browser does, not with actingAs(): actingAs()
        // changes the application's default guard, which is precisely the bug
        // the account area already shipped once. See AccountAreaTest's header.
        $status = $this
            ->withSession([walkCustomerSessionKey() => $seed['customer']->id])
            ->get($path)
            ->getStatusCode();

        if ($status !== $spec['auth']) {
            $failures[] = sprintf('%s (%s) answered %d signed in, expected %d', $uri, $path, $status, $spec['auth']);
        }
    }

    expect($failures)->toBe([], "Account pages answered the wrong status:\n  " . implode("\n  ", $failures));
    // The loop must actually have walked something.
    expect(count(array_filter($expectations, fn ($s) => isset($s['auth']))))->toBeGreaterThan(3);
});

/**
 * The static half, and the one that covers the admin panel too.
 *
 * Every one of the three missing-method outages was a route whose action named
 * a method that did not exist. That is decidable without a database, a session
 * or a request: ask the router what each route dispatches to and check the
 * method is really there. This walks EVERY route in the application — admin
 * included, GET and POST alike — so the next one is caught the moment it is
 * registered rather than the first time a visitor happens to load the page.
 */
it('resolves every registered route to an action that actually exists', function () {
    $broken = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $action = $route->getAction('uses');

        // Closure routes carry their body with them; nothing to resolve.
        if (! is_string($action)) {
            continue;
        }

        $checked++;

        // Laravel spells a controller action "Class@method"; a single-action
        // controller is the class alone and dispatches to __invoke().
        [$class, $method] = array_pad(explode('@', $action, 2), 2, '__invoke');

        if (! class_exists($class)) {
            $broken[] = sprintf('%s %s -> class %s does not exist', implode('|', $route->methods()), $route->uri(), $class);

            continue;
        }

        if (! method_exists($class, $method)) {
            $broken[] = sprintf(
                '%s /%s -> %s::%s() does not exist',
                implode('|', $route->methods()),
                $route->uri(),
                $class,
                $method
            );

            continue;
        }

        if (! (new ReflectionMethod($class, $method))->isPublic()) {
            $broken[] = sprintf('%s /%s -> %s::%s() is not public', implode('|', $route->methods()), $route->uri(), $class, $method);
        }
    }

    expect($broken)->toBe([], "Routes pointing at actions that cannot be called:\n  " . implode("\n  ", $broken));
    expect($checked)->toBeGreaterThan(100);
});
