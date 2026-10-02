<?php
/*
 * Seed the Lane QA preview: the Lane PDP fixture (a toner with five reviews, a
 * set, a variable product, a sold-out serum, long names) plus Lane PW's UAE
 * shipping zone, so the delivery box has a free-delivery figure to quote.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
require __DIR__.'/pdp-seed.php';

$zone = \App\Models\ShippingZone::firstOrCreate(['name' => 'All UAE'], ['position' => 0]);
\App\Models\ShippingZoneLocation::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
\App\Models\ShippingMethod::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate'], [
    'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
]);
\App\Models\ShippingMethod::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping'], [
    'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
]);
app(\App\Services\SettingsService::class)->set('store_country', 'AE');

echo "qa seed done\n";
