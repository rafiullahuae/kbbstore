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

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
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
        // 2 x د.إ150 = د.إ300, in fils
        ->and($order->subtotal)->toBe(30000)
        // CashOnDelivery::start() moved it on without marking it paid.
        ->and($order->status)->toBe('processing')
        ->and($order->paid_at)->toBeNull();

    // The line landed with the column names order_items actually has.
    $item = $order->items()->first();

    expect($item)->not->toBeNull()
        ->and($item->quantity)->toBe(2)
        ->and($item->unit_price)->toBe(15000)
        ->and($item->total)->toBe(30000);

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
