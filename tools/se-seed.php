<?php
/*
 * Seed the Lane SE preview. (Lane SE)
 *
 * tools/set-seed.php already builds the owner, a Set of three members and an
 * ordinary product beside it, which is exactly the fixture this lane's pictures
 * need — so it is REQUIRED rather than copied. A second seed describing the
 * same set would be a second description of a set, which is the thing
 * App\Support\SetContents exists to prevent.
 *
 * What this adds is only what building an order BY HAND needs and browsing does
 * not: a customer to build it for, a delivery zone to price it in, and a
 * payment method to settle it with.
 *
 * MONEY IS INTEGER FILS here too.
 */
require __DIR__.'/set-seed.php';

$customer = \App\Models\Customer::updateOrCreate(['email' => 'aisha.khan@example.com'], [
    'first_name' => 'Aisha',
    'last_name' => 'Khan',
    'phone' => '+971501234567',
]);

/*
 * NO SAVED ADDRESS. The manual-order screen ASKS for the delivery address
 * rather than prefilling one from the customer record, so a seeded address
 * would not shorten the operator's path and would only be a row nothing reads.
 * (`customer_id` is not fillable on Address either, so an updateOrCreate for
 * one fails outright — which is how this was found: the seed aborted there and
 * left the delivery zone below unwritten, and the screen answered "We do not
 * deliver to that country yet.")
 */

$zone = \App\Models\ShippingZone::updateOrCreate(['name' => 'All UAE'], ['position' => 0]);

\App\Models\ShippingZoneLocation::updateOrCreate(
    ['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE'],
    [],
);

\App\Models\ShippingMethod::updateOrCreate(
    ['shipping_zone_id' => $zone->id, 'title' => 'Delivery Charges'],
    ['type' => 'flat_rate', 'cost' => 2000, 'enabled' => true, 'position' => 0],
);

\App\Models\PaymentProvider::updateOrCreate(['id' => 'cod'], [
    'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0,
]);

/* The free-delivery threshold set-seed.php lowers to 20000 would make the
   delivery row read "Free" and hide the flat rate; raised here so the manual
   order receipt shows a delivery line like a real one. */
app(\App\Services\SettingsService::class)->set('free_shipping_threshold', 100000);

echo 'seeded customer #', $customer->id, ", a UAE zone and cash on delivery\n";
