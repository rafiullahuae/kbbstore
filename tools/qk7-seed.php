<?php

declare(strict_types=1);

/*
 * Lane QK7: tools/mac-seed.php's shop plus the live shop's UAE delivery --
 * AED 20 flat, free over AED 199 -- so the free-delivery bar has a threshold
 * to draw against. Run through tools/qk7-preview.sh; never against a real
 * database. The admin: owner@example.com / preview-password (mac-seed.php).
 */

use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;

require __DIR__.'/mac-seed.php';

$zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
    'cost' => 2000, 'enabled' => true, 'position' => 0]);
ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'title' => 'Free delivery',
    'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1]);

\App\Services\SettingsService::forgetMemo();
echo 'fs_bar_on = '.var_export(app(\App\Services\CheckoutPage::class)->freeDeliveryBar(), true)."\n";
\Illuminate\Support\Facades\Cache::flush();
