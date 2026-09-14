<?php

/*
 * Checkout 500ed with Unknown column 'is_gift'. Adding the three gift columns
 * did not end it, because MySQL reports only the FIRST unknown column in an
 * INSERT: a table missing five columns fails five times, one release apart.
 *
 * So this drops far more than the reported column and proves one migration
 * restores the table and lets an order through.
 */

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function repairOrderTables(): void
{
    (require base_path('database/migrations/2026_09_15_020000_repair_order_tables.php'))->up();
}

/** Everything place() writes that a broken ALTER chain could have taken out. */
const DROPPED = [
    'is_gift', 'gift_note', 'gift_fee', 'customer_note',
    'whatsapp_optin', 'ip_address', 'coupon_code', 'origin',
    'payment_method_title', 'transaction_id', 'paid_at', 'pixels_fired_at',
];

it('restores every missing column in one pass', function () {
    Schema::table('orders', fn ($t) => $t->dropColumn(DROPPED));

    foreach (DROPPED as $column) {
        expect(Schema::hasColumn('orders', $column))->toBeFalse("still present before repair: {$column}");
    }

    repairOrderTables();

    foreach (DROPPED as $column) {
        expect(Schema::hasColumn('orders', $column))->toBeTrue("not restored: {$column}");
    }
});

it('lets an order be placed after a wide repair', function () {
    Schema::table('orders', fn ($t) => $t->dropColumn(DROPPED));
    repairOrderTables();

    $response = place(cartWithItem(20000), checkoutForm(['payment_method' => 'cod']));

    expect($response->status())->not->toBe(500);
    expect(Order::where('email', 'buyer@example.com')->exists())->toBeTrue();
});

it('repairs order_items too, which the next insert needs', function () {
    Schema::table('order_items', fn ($t) => $t->dropColumn(['brand', 'sku', 'variant_attributes', 'tax_total']));

    repairOrderTables();

    foreach (['brand', 'sku', 'variant_attributes', 'tax_total'] as $column) {
        expect(Schema::hasColumn('order_items', $column))->toBeTrue("not restored: {$column}");
    }

    place(cartWithItem(20000), checkoutForm(['payment_method' => 'cod']));

    expect(Order::where('email', 'buyer@example.com')->first()?->items()->count())->toBe(1);
});

it('is a no-op on a table that is already complete, and safe twice', function () {
    repairOrderTables();
    repairOrderTables();

    place(cartWithItem(20000), checkoutForm(['payment_method' => 'cod']));

    expect(Order::where('email', 'buyer@example.com')->count())->toBe(1);
});

it('keeps gift values through a repaired table', function () {
    Schema::table('orders', fn ($t) => $t->dropColumn(DROPPED));
    repairOrderTables();

    place(cartWithItem(20000), checkoutForm([
        'payment_method' => 'cod', 'is_gift' => 1, 'gift_note' => 'Happy birthday',
    ]));

    $order = Order::where('email', 'buyer@example.com')->first();

    expect((bool) $order->is_gift)->toBeTrue()
        ->and($order->gift_note)->toBe('Happy birthday');
});
