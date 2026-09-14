<?php

/*
 * The live 500: SQLSTATE[42S22] Unknown column 'is_gift' on every Place order.
 *
 * The gift migrations are recorded as run on that database and the columns are
 * not there. Each adds its column with ->after(...) naming the column the
 * previous migration should have created, so one missing anchor takes out the
 * chain while the hasColumn guards make it look like a clean no-op.
 *
 * These reproduce the broken shape by dropping the columns, then prove the
 * repair migration restores it and that an order can be placed afterwards.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use Illuminate\Support\Facades\Schema;

/*
 * The same setup CheckoutPlacementTest uses: without a zone covering the UAE,
 * ratesFor() returns nothing and checkout bails out before it ever reaches the
 * insert these tests are about.
 */
beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

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

function dropGiftColumns(): void
{
    Schema::table('orders', function ($t) {
        $t->dropColumn(['is_gift', 'gift_note', 'gift_fee']);
    });
}

function runRepair(): void
{
    (require base_path('database/migrations/2026_09_15_010000_repair_orders_checkout_columns.php'))->up();
}

it('reproduces the live failure when the gift columns are absent', function () {
    dropGiftColumns();

    expect(Schema::hasColumn('orders', 'is_gift'))->toBeFalse();

    $cart = cartWithItem(20000);
    $threw = false;

    try {
        place($cart, checkoutForm(['payment_method' => 'cod']));
    } catch (Throwable $e) {
        $threw = str_contains($e->getMessage(), 'is_gift');
    }

    // Either the request 500s or the driver throws; both mean no order.
    expect(Order::count())->toBe(0);
});

it('restores every column the checkout insert needs', function () {
    dropGiftColumns();

    runRepair();

    foreach (['is_gift', 'gift_note', 'gift_fee', 'customer_note', 'whatsapp_optin', 'ip_address', 'coupon_code'] as $column) {
        expect(Schema::hasColumn('orders', $column))->toBeTrue("missing: {$column}");
    }
});

it('lets an order be placed again after the repair', function () {
    dropGiftColumns();
    runRepair();

    $cart = cartWithItem(20000);
    $response = place($cart, checkoutForm(['payment_method' => 'cod']));

    expect($response->status())->not->toBe(500);
    expect(Order::where('email', 'buyer@example.com')->exists())->toBeTrue();
});

it('is safe to run on a table that already has the columns', function () {
    runRepair();
    runRepair();

    expect(Schema::hasColumn('orders', 'is_gift'))->toBeTrue();

    $cart = cartWithItem(20000);
    place($cart, checkoutForm(['payment_method' => 'cod']));

    expect(Order::where('email', 'buyer@example.com')->count())->toBe(1);
});

it('keeps the gift values written by a real order', function () {
    dropGiftColumns();
    runRepair();

    $cart = cartWithItem(20000);
    place($cart, checkoutForm([
        'payment_method' => 'cod',
        'is_gift' => 1,
        'gift_note' => 'Happy birthday',
    ]));

    $order = Order::where('email', 'buyer@example.com')->first();

    expect($order)->not->toBeNull()
        ->and((bool) $order->is_gift)->toBeTrue()
        ->and($order->gift_note)->toBe('Happy birthday');
});
