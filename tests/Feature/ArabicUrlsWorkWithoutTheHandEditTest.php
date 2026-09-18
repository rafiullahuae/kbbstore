<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;

/**
 * =============================================================================
 * /ar 404'd ON THE LIVE SHOP, AND THE REASON WAS THAT THE FIX COULD NOT SHIP
 * =============================================================================
 *
 * Reported by the owner, twice: "i enabled the Arabic layout, but when i visit
 * /ar, it's giving 404 Not Found error", and later "why i can't visit any
 * arabic url with /ar/ it gives everywhere 404 not found error."
 *
 * He had done nothing wrong. SetLocaleFromPath must run BEFORE the router --
 * it strips the /ar segment, which is what lets every existing route,
 * RESERVED_SLUGS, the redirect map and the sitemap stay exactly as they are --
 * and only the global pipeline runs that early. bootstrap/app.php prepends it
 * there, and `bootstrap/` is on BuildPackage::NEVER_SHIP, so that line has
 * never reached the server and never could. It was documented as the one change
 * to be applied to the host by hand, and a hand-edit to bootstrap/app.php is
 * the worst thing to ask of an owner with no shell: a mistake in that file
 * stops the application booting, which also stops the updater that would undo
 * it.
 *
 * ── WHAT THIS TEST PINS, AND WHY IT IS NOT THE OBVIOUS TEST ─────────────────
 *
 * "Does /ar work?" is already covered elsewhere and would pass on the strength
 * of the bootstrap line alone -- in THIS repository that line is present, so a
 * plain fetch proves nothing about a server where it is absent. The property
 * that matters is the one the owner's shop has:
 *
 *     THE MIDDLEWARE IS REGISTERED BY A FILE A PACKAGE CAN SHIP.
 *
 * So this reads the registration out of the HTTP kernel and requires
 * AppServiceProvider to be the thing that put it there, by removing it first
 * and booting the provider again. If someone deletes the provider registration
 * and leans on bootstrap/app.php, every other Arabic test stays green and this
 * one fails -- which is the whole point, because the server does not have that
 * file's line.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

it('registers the locale middleware from a file that ships, not only from bootstrap', function () {
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

    $read = function () use ($kernel): array {
        $r = new ReflectionProperty($kernel, 'middleware');
        $r->setAccessible(true);

        return $r->getValue($kernel);
    };

    expect($read())->toContain(\App\Http\Middleware\SetLocaleFromPath::class);

    /*
     * Now take it out -- which is the state of the live host, where
     * bootstrap/app.php has never carried the line -- and boot the provider
     * again. If the provider is what registers it, it comes back.
     */
    $write = new ReflectionProperty($kernel, 'middleware');
    $write->setAccessible(true);
    $write->setValue($kernel, array_values(array_filter(
        $read(),
        static fn (string $m): bool => $m !== \App\Http\Middleware\SetLocaleFromPath::class
    )));

    expect($read())->not->toContain(\App\Http\Middleware\SetLocaleFromPath::class);

    (new \App\Providers\AppServiceProvider(app()))->boot();

    /*
     * in_array() and NOT toContain($class, $message): Pest's toContain is
     * VARIADIC, so a message passed as its second argument is read as a second
     * NEEDLE and the assertion then demands the array contain the sentence
     * itself. This was written the wrong way first and failed with "Failed
     * asserting that an array contains 'AppServiceProvider no longer
     * registers...'", which is the tell. ExpectationsThatCannotFailTest sweeps
     * for the ->not-> form of the same mistake, where it passes vacuously
     * instead of failing loudly.
     */
    expect(in_array(\App\Http\Middleware\SetLocaleFromPath::class, $read(), true))->toBeTrue(
        'AppServiceProvider no longer registers SetLocaleFromPath, so /ar depends on a hand-edit to '
        .'bootstrap/app.php that no package can make and the live shop has never had'
    );
});

it('registers it exactly once when bootstrap/app.php has also been hand-applied', function () {
    /*
     * A shop whose owner DID apply the hand-edit must not end up running the
     * middleware twice. Kernel::prependMiddleware() does an array_search before
     * it unshifts, and this is what pins that we rely on that rather than
     * assume it -- booting the provider repeatedly must not stack copies.
     */
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $r = new ReflectionProperty($kernel, 'middleware');
    $r->setAccessible(true);

    (new \App\Providers\AppServiceProvider(app()))->boot();
    (new \App\Providers\AppServiceProvider(app()))->boot();

    $count = count(array_filter(
        $r->getValue($kernel),
        static fn (string $m): bool => $m === \App\Http\Middleware\SetLocaleFromPath::class
    ));

    expect($count)->toBe(1, 'SetLocaleFromPath is registered '.$count.' times; the request would pass through it twice');
});

it('serves an Arabic product page, which is what the owner could not reach', function () {
    Product::create([
        'name' => 'Arabic Route Serum', 'slug' => 'arabic-route-serum', 'sku' => 'AR-ROUTE-1',
        'status' => 'publish', 'is_visible' => true, 'price' => 12600,
        'stock_status' => 'instock', 'image' => 'https://cdn.test/a.jpg',
    ]);

    $s = app(SettingsService::class);
    $s->set(Locale::SETTING_ENABLED, '1');
    $s->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    test()->get('/ar/')->assertOk();
    test()->get('/ar/shop/')->assertOk();
    test()->get('/ar/product/arabic-route-serum/')->assertOk();
});
