<?php

/**
 * The order-summary quantity controls on checkout, applied in place.
 *
 * Reported from the live site: + and − worked, and reloaded the whole page
 * every time. They posted to /api/cart/update, which returns the DRAWER and the
 * cart page — neither of which is on screen at checkout — so there was nothing
 * the page could swap in and `window.location.reload()` was the only way to
 * show the new figures. The cost was every field already typed into the form,
 * the scroll position, and the country the shopper had chosen.
 *
 * So these tests assert the CONTENT of the fragments that come back, not that a
 * 200 came back: the summary line and its new price, the subtotal and total,
 * the free-delivery bar, the mobile bag strip and the payment options. If any
 * of them stood still, the page could not be repainted and a reload would be
 * the only honest thing left to do.
 */

use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    /*
     * The route lives in routes/checkout-line.php, which the integrator wires
     * into routes/web.php (lanes do not edit web.php directly). Register it
     * here when that line is not in place yet, so the endpoint is testable
     * either way and these tests keep passing once it is.
     */
    if (! Route::has('checkout.lineUpdate')) {
        Route::middleware('web')->group(base_path('routes/checkout-line.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);
});

/** products.price is FILS, cast to int — not a decimal number of dirhams. */
function lineProduct(string $name, int $priceFils): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . uniqid(),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'stock_status' => 'instock',
    ]);
}

function lineCart(array $lines): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    foreach ($lines as $name => $spec) {
        [$priceFils, $qty] = $spec;

        $cart->items()->create([
            'product_id' => lineProduct($name, $priceFils)->id,
            'quantity' => $qty,
            'unit_price' => $priceFils,
        ]);
    }

    return $cart;
}

/**
 * A browser carrying this cart's cookie.
 *
 * withCredentials() is not decoration: postJson() sends NO cookies unless it is
 * set, because it models a cross-origin fetch. The real handler is a
 * same-origin fetch(), which sends them by default. EncryptCookies is disabled
 * for the same reason the Browsed tests disable it — a plain token handed to
 * that middleware fails to decrypt and is dropped.
 */
function lineShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function lineUpdate(Cart $cart, array $body)
{
    return lineShopper($cart)->postJson('/checkout/line', $body);
}

it('raises the quantity and returns the re-rendered summary, totals and bag strip', function () {
    // 150.00 + 50.00 = 200.00, under a 300.00 free-delivery threshold.
    app(\App\Services\SettingsService::class)->set('free_shipping_threshold', 30000);

    $cart = lineCart(['Barrier Cream' => [15000, 1], 'Rice Toner' => [5000, 1]]);
    $line = $cart->items()->firstOrFail();

    $response = lineUpdate($cart, [
        'item_id' => $line->id,
        'quantity' => 3,
        'country' => 'AE',
    ]);

    $response->assertOk()->assertJson(['ok' => true, 'empty' => false, 'itemId' => $line->id]);

    // The write really happened, rather than merely being reported.
    expect($line->fresh()->quantity)->toBe(3);

    /*
     * 1. The summary line: the new quantity and the new LINE total, rendered
     *    server-side.
     *
     *    Not 3 x 150.00. Quantity bundles are on by default and the third unit
     *    crosses the 10% tier, so the whole line reprices to 3 x 135.00 =
     *    405.00 — which is exactly why no part of this figure may be worked out
     *    in the browser from the price already on screen.
     */
    expect($line->fresh()->unit_price)->toBe(13500);
    expect($response->json('itemsHtml'))
        ->toContain('Barrier Cream')
        ->toContain('405');

    // 2. The totals block, which opens with the free-delivery bar. Subtotal is
    //    405.00 + 50.00 = 455.00, which clears the threshold, so the bar reads
    //    as unlocked and delivery is free.
    $order = $response->json('orderHtml');
    expect($order)->toContain('455')->toContain('js-subtotal')->toContain('kbb-freeship');

    // 3. The mobile bag strip: four items now, not two.
    expect($response->json('thumbsHtml'))->toContain('4 items');

    // 4. The payment list and the badge count come back too, so nothing on the
    //    page is left holding a figure from before the press.
    expect($response->json('paymentHtml'))->toBeString();
    expect($response->json('count'))->toBe(4);

    // Nothing here is a redirect: the page does not navigate.
    expect($response->json('redirect'))->toBeNull();
});

it('lowers the quantity and reprices the line', function () {
    $cart = lineCart(['Snail Mucin 96' => [4000, 4]]);
    $line = $cart->items()->firstOrFail();

    $response = lineUpdate($cart, ['item_id' => $line->id, 'quantity' => 2, 'country' => 'AE']);

    $response->assertOk()->assertJson(['ok' => true, 'count' => 2]);

    expect($line->fresh()->quantity)->toBe(2);
    // Coming DOWN crosses a bundle tier too: 4 units bought at the 10% rate,
    // 2 units at 5%. The line is 2 x 38.00 = 76.00, not 2 x 36.00 — the rate
    // follows the final quantity, and the server is the only thing that knows.
    expect($line->fresh()->unit_price)->toBe(3800);
    expect($response->json('itemsHtml'))->toContain('76');
});

it('removes a line on quantity zero and keeps the rest of the bag', function () {
    $cart = lineCart(['Going Away' => [3000, 1], 'Staying Put' => [7000, 1]]);
    $doomed = $cart->items()->firstOrFail();

    $response = lineUpdate($cart, ['item_id' => $doomed->id, 'quantity' => 0, 'country' => 'AE']);

    $response->assertOk()->assertJson(['ok' => true, 'empty' => false]);

    expect($cart->items()->count())->toBe(1);
    expect($response->json('itemsHtml'))
        ->toContain('Staying Put')
        ->not->toContain('Going Away');
});

it('names the cart page when the last line goes, rather than repainting an empty checkout', function () {
    $cart = lineCart(['Only Thing' => [9000, 1]]);
    $line = $cart->items()->firstOrFail();

    $response = lineUpdate($cart, ['item_id' => $line->id, 'quantity' => 0, 'country' => 'AE']);

    $response->assertOk()->assertJson(['ok' => true, 'empty' => true, 'count' => 0]);

    // The destination comes from the server so it carries the deployment's
    // base path, rather than being assembled in the browser.
    expect($response->json('redirect'))->toContain('/cart/');
    expect($cart->items()->count())->toBe(0);
});

it('refuses a line id belonging to somebody else and changes nothing', function () {
    $mine = lineCart(['Mine' => [5000, 1]]);
    $theirs = lineCart(['Theirs' => [5000, 2]]);
    $theirLine = $theirs->items()->firstOrFail();

    lineUpdate($mine, ['item_id' => $theirLine->id, 'quantity' => 9, 'country' => 'AE'])
        ->assertStatus(404)
        ->assertJson(['ok' => false]);

    expect($theirLine->fresh()->quantity)->toBe(2);
});

it('will not take a quantity outside the range the cart itself allows', function () {
    $cart = lineCart(['Clamped' => [5000, 1]]);
    $line = $cart->items()->firstOrFail();

    lineUpdate($cart, ['item_id' => $line->id, 'quantity' => 100])->assertStatus(422);
    lineUpdate($cart, ['item_id' => $line->id, 'quantity' => -1])->assertStatus(422);

    expect($line->fresh()->quantity)->toBe(1);
});

it('answers a shopper with no cart rather than creating one', function () {
    lineProduct('Nothing Doing', 5000);

    $this->postJson('/checkout/line', ['item_id' => 1, 'quantity' => 2])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    expect(Cart::query()->count())->toBe(0);
});

it('is CSRF-protected, like every other endpoint that writes to the cart', function () {
    $route = Route::getRoutes()->getByName('checkout.lineUpdate');

    expect($route)->not->toBeNull();
    expect($route->methods())->toContain('POST');

    // The `web` group is what brings session, cookies and
    // VerifyCsrfToken — the reason this route is not under /api/*, every path
    // of which is unauthenticated and public.
    expect($route->gatherMiddleware())->toContain('web');
});

it('publishes the endpoint URL on the checkout page for the stepper to post to', function () {
    $cart = lineCart(['On The Page' => [5000, 1]]);

    $html = lineShopper($cart)->get('/checkout')->assertOk()->getContent();

    expect($html)->toContain('checkoutLine');

    // The value itself, decoded: @json escapes the slashes, so a plain
    // substring check would pass on a page carrying no URL at all. Built
    // through the app's URL helper, so a subdirectory deployment gets the
    // prefix rather than a path that 404s.
    preg_match('/checkoutLine\s*=\s*("(?:[^"\\\\]|\\\\.)*")/', $html, $found);

    expect(json_decode($found[1] ?? '""'))->toBe(\App\Support\Url::to('/checkout/line'));
});

it('no longer reloads the page when a stepper is pressed', function () {
    // The whole bug was one line of JavaScript. Reading the source is the only
    // way to pin it: the handler is not reachable from PHP, and a reload is
    // indistinguishable from a working control in a status code.
    $js = (string) file_get_contents(base_path('resources/js/kbb/checkout.js'));

    // The stepper branch reaches changeLine(), which repaints in place.
    expect($js)->toContain('changeLine(');

    // And the one remaining reload belongs to the coupon box, which has no
    // fragment endpoint of its own yet — it must not have crept back onto the
    // quantity path.
    expect(substr_count($js, 'window.location.reload()'))->toBe(1);
});
