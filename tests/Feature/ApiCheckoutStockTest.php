<?php

declare(strict_types=1);

/**
 * Lane CQ — the unauthenticated API cannot oversell either.
 *
 * WHAT WAS WRONG. POST /api/checkout/session writes a real order, and `/api/*`
 * is public — CLAUDE.md says so in as many words. It refused a product whose
 * `stock_status` was `outofstock` and stopped there: it never read the counted
 * `stock` figure, never decremented it, and never marked a shelf empty. So the
 * flag was the entire defence, and the flag only moves when the owner flips it
 * by hand or when something takes the last unit. Nothing did. One jar, one
 * `instock` row, and as many real orders as anybody cared to POST — after Lane
 * CM had closed exactly this hole on the storefront path next door.
 *
 * WHY THE FIX IS NOT A SECOND DECREMENT. The storefront claims stock through
 * CartService::claimStock(), which takes a Cart; this endpoint builds its lines
 * from a request shape and has none. Writing the arithmetic a second time
 * against the request shape would leave two implementations of "take the units
 * off the shelf", and the one that drifts is the one nobody is looking at. So
 * the routine moved to App\Services\StockClaim, which takes a list of
 * (product, variant, quantity), and both doors call it. StockAtPaymentTest
 * pins the storefront's half of that and passes unchanged.
 *
 * THE REFUSAL SHAPE IS PART OF THE FIX. StockUnavailable is a RuntimeException;
 * left to propagate out of DB::transaction() it is a 500 on a public endpoint
 * for the ordinary case of something having sold out. It is caught outside the
 * transaction — inside would commit the order it is refusing — and answered
 * 422 `{ok: false, error: …}`, which is the shape this endpoint's shipping, COD
 * and gateway refusals already use.
 *
 * The two-process race is in StockReturnRaceTest, which drives this endpoint's
 * claim routine from two real OS processes for the reason CouponRaceTest's
 * header gives.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
        'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function cqApiProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'cq-' . Str::random(8),
        'name' => 'Ginseng Essence',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ], $overrides));
}

/**
 * One POST to the public endpoint.
 *
 * @param  list<array{0: Product, 1: int}>  $items  product and quantity pairs
 */
function cqApiPost(array $items)
{
    return test()->postJson('/api/checkout/session', [
        'items' => array_map(fn (array $pair) => [
            'slug' => $pair[0]->slug,
            'qty' => $pair[1],
        ], $items),
        'customer' => [
            'name' => 'Aisha Khan',
            'email' => 'cq-buyer@example.com',
            'phone' => '0500000000',
            'emirate' => 'Dubai',
            'address' => '12 Marina Walk',
            'country' => 'AE',
        ],
        'method' => 'cod',
    ]);
}

/*
|------------------------------------------------------------------------------
| 1. The counted figure, which this endpoint never looked at
|------------------------------------------------------------------------------
*/

it('refuses an api order for more units than are actually on the shelf', function () {
    $product = cqApiProduct(['manage_stock' => true, 'stock' => 2]);

    $response = cqApiPost([[$product, 3]]);

    expect(Order::count())->toBe(0, 'The public endpoint sold three of a product the shop has two of.');

    $response->assertStatus(422);

    expect((int) $product->fresh()->stock)->toBe(2, 'A refused api order still moved the shelf.');
});

it('does not let the same single unit be bought twice through the api', function () {
    // The whole bug in one case: the endpoint took the order, left `stock`
    // alone and left `stock_status` at instock, so the next POST was as
    // welcome as the first. And the one after that.
    $product = cqApiProduct(['manage_stock' => true, 'stock' => 1]);

    cqApiPost([[$product, 1]])->assertStatus(201);
    cqApiPost([[$product, 1]])->assertStatus(422);
    cqApiPost([[$product, 1]])->assertStatus(422);

    expect(Order::count())->toBe(1, 'One unit was sold more than once through the public endpoint.');
    expect((int) $product->fresh()->stock)->toBe(0, 'The shelf went negative or was never touched.');
});

it('takes the units off the shelf when an api order is placed', function () {
    $product = cqApiProduct(['manage_stock' => true, 'stock' => 5]);

    cqApiPost([[$product, 2]])->assertStatus(201);

    expect(Order::count())->toBe(1, 'A placeable api order was refused.');
    expect((int) $product->fresh()->stock)->toBe(3, 'Stock was not reduced by the api order — the same units can be sold again.');
});

it('marks a counted product sold out when the api takes the last unit', function () {
    // Otherwise the storefront goes on advertising it and every later checkout,
    // on either door, fails at the very last step.
    $product = cqApiProduct(['manage_stock' => true, 'stock' => 1]);

    cqApiPost([[$product, 1]])->assertStatus(201);

    $product->refresh();

    expect((int) $product->stock)->toBe(0);
    expect($product->stock_status)->toBe('outofstock', 'The shelf is empty and the storefront still says in stock.');
});

it('counts the same slug sent twice as one demand on the shelf', function () {
    // `items` is a plain list and nothing stops a caller repeating a slug, so
    // checking each entry against the shelf on its own lets 1 + 1 buy a single
    // remaining unit twice over in one request.
    $product = cqApiProduct(['manage_stock' => true, 'stock' => 1]);

    cqApiPost([[$product, 1], [$product, 1]])->assertStatus(422);

    expect(Order::count())->toBe(0, 'Two entries of one unit each bought the same single unit twice.');
    expect((int) $product->fresh()->stock)->toBe(1);
});

/*
|------------------------------------------------------------------------------
| 2. The refusal a caller actually receives
|------------------------------------------------------------------------------
*/

it('answers a sold-out basket with 422 and a sentence, not a 500', function () {
    // StockUnavailable is a RuntimeException. Uncaught, this endpoint answers
    // 500 for the ordinary case of something having sold out — a public
    // endpoint failing loudly at a caller that did nothing wrong.
    $product = cqApiProduct(['manage_stock' => true, 'stock' => 1]);

    $response = cqApiPost([[$product, 4]]);

    $response->assertStatus(422)->assertJson(['ok' => false]);

    $error = (string) $response->json('error');

    expect(str_contains($error, 'Ginseng Essence'))
        ->toBeTrue('The refusal does not name the product: ' . $error);

    expect(str_contains($error, '1'))
        ->toBeTrue('The refusal does not say how many are left: ' . $error);
});

it('rolls the whole placement back when one line cannot be filled', function () {
    // Deliberately before the order lines and before the gateway, inside the
    // transaction, exactly where place() puts it: a refused call has written no
    // order, no line and taken nothing off the other product's shelf.
    $fine = cqApiProduct(['manage_stock' => true, 'stock' => 10]);
    $gone = cqApiProduct(['manage_stock' => true, 'stock' => 0, 'stock_status' => 'instock']);

    cqApiPost([[$fine, 1], [$gone, 1]])->assertStatus(422);

    expect(Order::count())->toBe(0);
    expect(\App\Models\OrderItem::count())->toBe(0);
    expect((int) $fine->fresh()->stock)->toBe(10, 'A refused order took units off a product that was available.');
});

/*
|------------------------------------------------------------------------------
| 3. Products that count no stock are unaffected, exactly as before
|------------------------------------------------------------------------------
*/

it('sells an uncounted product through the api exactly as before', function () {
    $product = cqApiProduct(['manage_stock' => false, 'stock' => null]);

    cqApiPost([[$product, 4]])->assertStatus(201);

    expect(Order::count())->toBe(1, 'A product with no counted stock was refused.');
    expect($product->fresh()->stock)->toBeNull('An uncounted product had a stock figure invented for it.');
    expect($product->fresh()->stock_status)->toBe('instock');
});

it('does not touch a leftover figure on an uncounted product', function () {
    // manage_stock off with a number still in the column — the WooCommerce
    // import leaves plenty of these. The number means nothing.
    $product = cqApiProduct(['manage_stock' => false, 'stock' => 1]);

    cqApiPost([[$product, 9]])->assertStatus(201);

    expect((int) $product->fresh()->stock)->toBe(1, 'A stock figure that is not being counted was decremented anyway.');
    expect($product->fresh()->stock_status)->toBe('instock');
});

it('still refuses a product the owner has marked sold out', function () {
    // The check that was already there, kept.
    $product = cqApiProduct(['stock_status' => 'outofstock']);

    cqApiPost([[$product, 1]])->assertStatus(422);

    expect(Order::count())->toBe(0);
});

/*
|------------------------------------------------------------------------------
| 4. One routine, not two
|------------------------------------------------------------------------------
*/

it('claims through the same routine the storefront claims through', function () {
    /*
     * The structural half of this lane, and worth pinning rather than trusting.
     * Two implementations of "take the units off the shelf" drift, and the one
     * that drifts is the one nobody is looking at — this endpoint, which no
     * page in this application calls and which only a headless client uses.
     *
     * Read off the FILES rather than asserted through behaviour, because
     * behaviour is exactly what a second copy would reproduce on the day it was
     * written and stop reproducing six months later.
     */
    $api = file_get_contents(base_path('app/Http/Controllers/Api/CheckoutController.php'));
    $cart = file_get_contents(base_path('app/Services/CartService.php'));

    expect(str_contains($api, 'StockClaim'))
        ->toBeTrue('The api checkout no longer goes through StockClaim.');

    expect(str_contains($cart, 'StockClaim'))
        ->toBeTrue('CartService no longer goes through StockClaim.');

    // The arithmetic itself lives in one file. A decrement written anywhere
    // else is the second implementation this lane exists to prevent.
    $decrementers = [];

    foreach (['app/Http/Controllers/Api/CheckoutController.php',
        'app/Http/Controllers/Store/CheckoutController.php',
        'app/Services/CartService.php'] as $relative) {
        $body = file_get_contents(base_path($relative));

        if (preg_match('/stock\s*-\s*[\'"]?\s*\.\s*\$|[\'"]stock[\'"]\s*=>\s*DB::raw/', $body) === 1) {
            $decrementers[] = $relative;
        }
    }

    expect($decrementers)->toBe([], 'A second implementation of the stock decrement has appeared in: ' . implode(', ', $decrementers));
});
