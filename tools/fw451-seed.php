<?php
/*
 * Lane FW preview seed: an owner account for the admin shots and a post for
 * the blog page. The catalogue is DemoCatalogueSeeder's. Preview database only.
 */
use App\Models\AdminUser;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

try {
    \App\Models\Post::updateOrCreate(['slug' => 'fw-routine'], ['title' => 'A five-step evening routine', 'status' => 'publish',
        'body' => '<p>Cleanse, tone, treat, moisturise, protect.</p>', 'published_at' => now()->subDay()]);
} catch (\Throwable $e) {
    echo 'post: '.$e->getMessage()."\n";
}

\App\Services\Security\IpBlockList::rebuild();
echo "seeded\n";

/* The checkout proofs: a UAE zone with a flat rate, cash on delivery, and a coupon. */
$zone = \App\Models\ShippingZone::firstOrCreate(['name' => 'FW UAE'], ['position' => 0]);
\App\Models\ShippingZoneLocation::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
\App\Models\ShippingMethod::firstOrCreate(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate'], ['title' => 'Standard', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
\App\Models\PaymentProvider::updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
\App\Models\Coupon::firstOrCreate(['code' => 'FWTEN'], ['type' => 'percent', 'amount' => 1000]);
echo "checkout seeded\n";
