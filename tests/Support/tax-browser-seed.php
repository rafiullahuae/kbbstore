<?php

/**
 * Seed a shop for the Chromium proof of the tax engine — Lane CU.
 *
 * Run with `php tests/Support/tax-browser-seed.php` after pointing .env at a
 * scratch SQLite file and migrating it. It prints the cart token to put in the
 * kbb_cart cookie.
 *
 * Not a test fixture and not loaded by the suite: this exists so the numbers in
 * the lane report come from a real page in a real browser rather than from a
 * unit test dressed up as one.
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Cart;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\SettingsService;
use Illuminate\Support\Str;

$settings = app(SettingsService::class);

PaymentProvider::query()->delete();
PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

ShippingZone::query()->delete();
ShippingMethod::query()->delete();
ShippingZoneLocation::query()->delete();

$uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
ShippingMethod::create([
    'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
    'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
]);

$gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
    ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => $code]);
}
ShippingMethod::create([
    'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate',
    'title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0,
]);

// Two decimals, so the page prints fils and the report can quote them.
$settings->set('currency_decimals', 2);
$settings->set('vat_enabled', true);
$settings->set('vat_rate', 5);
$settings->set('vat_basis', 'inclusive');
$settings->set('vat_label', "You're paying VAT ({rate}%)");
$settings->set('cod_fee', 0);

// The owner's own example: UAE inclusive at 5%, Saudi exclusive at 15%.
$settings->set('tax_mode', $argv[1] ?? 'live');
$settings->set('vat_country_rates', json_encode(['AE' => '5', 'SA' => '15']));
$settings->set('vat_country_bases', json_encode(['AE' => 'inclusive', 'SA' => 'exclusive']));
$settings->flush();

$product = Product::firstOrCreate(
    ['slug' => 'tax-proof-toner'],
    [
        'name' => 'Rice Probiotics Toner',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ]
);

Cart::query()->delete();

$cart = Cart::create([
    'token' => (string) Str::uuid(),
    'currency' => 'AED',
    'status' => 'active',
    'shipping_country' => 'AE',
    'last_activity_at' => now(),
]);

$cart->items()->create([
    'product_id' => $product->id,
    'quantity' => 1,
    'unit_price' => 10000,
]);

echo $cart->token, "\n";
