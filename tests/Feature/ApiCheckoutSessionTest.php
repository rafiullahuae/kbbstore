<?php

/**
 * Phase 11 — POST /api/checkout/session.
 *
 * This endpoint is public and unauthenticated, and until this phase it could
 * never have created an order: it wrote five columns `orders` does not have,
 * omitted order_number (NOT NULL UNIQUE), and wrote `qty` where order_items
 * calls the column `quantity`. Nothing downstream of that INSERT had ever run.
 *
 * So these tests are mostly "does the thing work at all", plus the gate that
 * stops this endpoint being the way around the storefront's payment checks.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    /*
     * A SHIPPING ZONE, because this endpoint now prices delivery from the zones
     * rather than from the `delivery_flat` / `free_ship` settings — see the
     * comment at the change in Api\CheckoutController::session() and
     * OrderLifecycleTest for the divergence that prompted it.
     *
     * These cases predate that and seeded no zone, which used to be invisible:
     * the flat default (2000 fils) applied to every destination whether or not
     * the store delivered there. Now a destination no zone covers is refused,
     * exactly as Store\CheckoutController::place() refuses it — so a store with
     * no zones configured cannot take an order through this endpoint either,
     * which is the correct answer and what these tests now set up for.
     *
     * AED 20 flat, matching the live "All UAE" zone, so every figure asserted
     * below is the one this suite always asserted.
     */
    $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
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

/** products.price is integer fils, like every money column in this schema. */
function apiProduct(int $priceFils = 15000): Product
{
    return Product::create([
        'slug' => 'api-serum-' . uniqid(),
        'name' => 'API Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'stock_status' => 'instock',
    ]);
}

function sessionPayload(Product $product, string $method = 'cod'): array
{
    return [
        'items' => [['slug' => $product->slug, 'qty' => 2]],
        'customer' => [
            'name' => 'Aisha Khan',
            'email' => 'api-buyer@example.com',
            'phone' => '0500000000',
            'emirate' => 'Dubai',
            'address' => '12 Marina Walk',
        ],
        'method' => $method,
    ];
}

it('creates a real order through the api checkout session', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $product = apiProduct(15000);
    $before = Order::count();

    $response = $this->postJson('/api/checkout/session', sessionPayload($product))
        ->assertStatus(201)
        ->assertJson(['ok' => true]);

    expect(Order::count())->toBe($before + 1);

    $order = Order::latest('id')->first();

    expect($order->order_number)->not->toBeNull()
        ->and($order->email)->toBe('api-buyer@example.com')
        ->and($order->payment_method)->toBe('cod')
        /*
         * 2 x د.إ142.50 = د.إ285.00, in fils.
         *
         * NOT 2 x د.إ150. This basket is two of the same product, which is
         * the 2-pack bundle every simple product's page offers at 5% off, and
         * the endpoint now prices its lines through
         * CartService::unitPriceFor() like the storefront does — see
         * ApiBundlePricingTest for the divergence this closed. The figure
         * asserted here was the endpoint's old answer, which was د.إ15.00
         * MORE than the page quoted for the identical basket.
         */
        ->and($order->subtotal)->toBe(28500)
        // CashOnDelivery::start() moved it on without marking it paid.
        ->and($order->status)->toBe('processing')
        ->and($order->paid_at)->toBeNull();

    // The line landed with the column names order_items actually has.
    $item = $order->items()->first();

    expect($item)->not->toBeNull()
        ->and($item->quantity)->toBe(2)
        ->and($item->unit_price)->toBe(14250)
        ->and($item->total)->toBe(28500);

    // And the response carries the number the success page keys off.
    $response->assertJsonStructure(['ok', 'order_id', 'orderId', 'order_number', 'redirect']);
});

it('stores the billing address in the column that exists', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $this->postJson('/api/checkout/session', sessionPayload(apiProduct()))->assertStatus(201);

    $order = Order::latest('id')->first();

    expect($order->billing_address)->toBeArray()
        ->and($order->billing_address['state'])->toBe('Dubai')
        ->and($order->billing_address['line1'])->toBe('12 Marina Walk');
});

it('refuses a gateway with no credentials rather than creating a dead order', function () {
    PaymentProvider::create(['id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $product = apiProduct();
    $before = Order::count();

    $this->postJson('/api/checkout/session', sessionPayload($product, 'stripe'))
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    expect(Order::count())->toBe($before);
});

it('still refuses a product that is not visible', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $draft = apiProduct();
    $draft->update(['status' => 'draft']);

    $this->postJson('/api/checkout/session', sessionPayload($draft))->assertStatus(422);
});

it('still enforces the cod window on this endpoint', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $settings = app(\App\Services\SettingsService::class);
    $settings->setModule('pay_ship_rules', true);
    $settings->setModuleSetting('pay_ship_rules', 'cod_max', 5000);

    $product = apiProduct(15000);     // 2 x د.إ150 is well over the د.إ50 ceiling
    $before = Order::count();

    $this->postJson('/api/checkout/session', sessionPayload($product))
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    expect(Order::count())->toBe($before);
});

it('gives each api order its own number', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'COD', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $product = apiProduct();

    $this->postJson('/api/checkout/session', sessionPayload($product))->assertStatus(201);
    $this->postJson('/api/checkout/session', sessionPayload($product))->assertStatus(201);

    $numbers = Order::latest('id')->take(2)->pluck('order_number');

    expect($numbers->unique())->toHaveCount(2);
});

/**
 * The public checkout endpoint had no collision check whatsoever. (Lane BA)
 *
 * Its allocation was `10000 + MAX(id) + 1`, and its docblock claimed that
 * continued from "whatever is already there" and kept imported WooCommerce
 * orders safe. It did neither: it never read `order_number` at all. One
 * imported order numbered near the id range, or any SOFT-DELETED order holding
 * the number it computed — still very much present in the UNIQUE index, and
 * invisible to a default-scoped query — and this endpoint raised SQLSTATE
 * 23000 on the insert. Unlike the other two placement paths it had no search
 * and no retry, so there was nothing to recover it.
 *
 * It now allocates from App\Services\Orders\OrderNumbers, before the
 * transaction opens. Both rows below are the ones that used to break it.
 */
it('places an order past an imported number and a trashed one', function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    // The number the old formula would have produced for the next id, held by
    // an order that has been trashed. Its number is still in the index.
    $trashed = Order::create([
        'order_number' => '10002',
        'email' => 'trashed@example.com',
        'status' => 'cancelled', 'currency' => 'AED',
        'subtotal' => 100, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 100,
    ]);
    $trashed->delete();

    // And an import, far ahead of the ids.
    Order::create([
        'order_number' => '48231',
        'email' => 'imported@example.com',
        'status' => 'completed', 'currency' => 'AED',
        'subtotal' => 100, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 100,
    ]);

    $response = test()->postJson('/api/checkout/session', sessionPayload(apiProduct()))
        ->assertStatus(201);

    $number = (int) $response->json('order_number');

    // Above everything already in the table, trashed rows included, and not
    // one of them.
    expect($number)->toBeGreaterThan(48231);

    expect(Order::withTrashed()->where('order_number', (string) $number)->count())->toBe(1);
});
