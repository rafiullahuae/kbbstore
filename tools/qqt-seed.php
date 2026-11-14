<?php
/*
 * Quiqup P3 preview seed (after tools/qq-seed.php): one shopper with four
 * orders -- out for delivery (with a delivery window), delivered, a missed
 * attempt, and one with no shipment at all -- driven through the real
 * ShipmentSync so the timeline reads exactly what production would. Arabic
 * on, and the timeline's Arabic published, so /ar can be photographed.
 *
 *   mariam@preview.test / preview-secret-1
 */

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Quiqup\ShipmentSync;
use Illuminate\Support\Str;

\App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
// The mirrored (right-to-left) layout on, so /ar shows the timeline as an Arabic reader sees it.
\App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
(require base_path('database/migrations/2028_02_14_100000_delivery_timeline_arabic.php'))->up();

$c = Customer::query()->updateOrCreate(['email' => 'mariam@preview.test'], [
    'name' => 'Mariam Ali', 'first_name' => 'Mariam', 'last_name' => 'Ali', 'password' => 'preview-secret-1',
]);

$mk = function (string $number, string $status, ?array $states, int $hoursAgo) use ($c) {
    $o = Order::query()->updateOrCreate(['order_number' => $number], [
        'customer_id' => $c->id, 'email' => $c->email, 'phone' => '0501234567', 'status' => $status, 'currency' => 'AED',
        'subtotal' => 18900, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 18900,
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery', 'shipping_method' => 'Next day delivery',
        'shipping_address' => ['first_name' => 'Mariam', 'last_name' => 'Ali', 'line1' => 'Villa 12, Street 5', 'line2' => 'Al Barsha 2', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '0501234567'],
        'ship_emirate' => 'Dubai',
    ]);
    $o->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();
    $o->items()->delete();
    foreach ([['Relief Sun: Rice + Probiotics', 'Beauty of Joseon', 6900], ['Advanced Snail 96 Mucin Essence', 'COSRX', 12000]] as [$n, $b, $p]) {
        $o->items()->create(['name' => $n, 'brand' => $b, 'quantity' => 1, 'unit_price' => $p, 'subtotal' => $p, 'total' => $p]);
    }

    if ($states === null) {
        return $o;
    }

    $s = Shipment::create([
        'order_id' => $o->id, 'env' => 'sandbox', 'test' => true, 'request_uuid' => (string) Str::uuid(), 'active_key' => $o->id,
        'qq_id' => random_int(30000000, 39999999), 'qq_uuid' => (string) Str::uuid(), 'service_kind' => 'partner_next_day',
        'payment_mode' => 'paid_on_delivery', 'cod_fils' => 18900, 'parcels' => 1, 'job' => 'created',
        'tracking_url' => 'https://track-parcel.quiqup.com/0b9e2a4c-1f7d-4e55-9a3b-6c1d2e3f4a5b',
    ]);

    foreach ($states as [$state, $minsAgo, $extra]) {
        $obs = ShipmentSync::observe(array_merge(['state' => $state, 'state_updated_at' => now()->subMinutes($minsAgo)->toIso8601ZuluString()], $extra));
        app(ShipmentSync::class)->observeAndApply($s, $obs, 'webhook');
    }

    return $o;
};

$mk('KBB-58101', 'processing', [
    ['ready_for_collection', 26 * 60, []],
    ['collected', 22 * 60, []],
    ['at_depot', 20 * 60, []],
    ['out_for_delivery', 95, ['delivery_before' => now()->addHours(4)->toIso8601ZuluString()]],
], 28);
$mk('KBB-58102', 'processing', [
    ['ready_for_collection', 3 * 24 * 60, []],
    ['collected', 2 * 24 * 60 + 600, []],
    ['received_at_depot', 2 * 24 * 60 + 400, []],
    ['out_for_delivery', 24 * 60 + 300, []],
    ['delivery_complete', 24 * 60 + 120, []],
], 80);
$mk('KBB-58103', 'processing', [
    ['ready_for_collection', 2 * 24 * 60, []],
    ['collected', 30 * 60, []],
    ['at_depot', 28 * 60, []],
    ['out_for_delivery', 9 * 60, []],
    ['delivery_failed', 4 * 60, ['delivery_attempts' => 1, 'delivery_failure_reason' => 'Customer not answering']],
    ['at_depot', 3 * 60, []],
], 50);
$mk('KBB-58104', 'processing', null, 2);

echo "qqt seed: 4 orders for mariam@preview.test\n";
