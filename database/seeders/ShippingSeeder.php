<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use Illuminate\Database\Seeder;

/**
 * The two shipping zones measured on production.
 *
 * The Gulf zone matters: kbb-theme hard-locks the billing country to AE, and
 * porting that would have made every order from these five countries impossible.
 */
class ShippingSeeder extends Seeder
{
    public function run(): void
    {
        $uae = ShippingZone::firstOrCreate(['name' => 'All UAE'], ['position' => 0]);
        $uae->locations()->firstOrCreate(['type' => 'country', 'code' => 'AE']);

        ShippingMethod::firstOrCreate(
            ['shipping_zone_id' => $uae->id, 'type' => 'flat_rate'],
            ['title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0]
        );
        ShippingMethod::firstOrCreate(
            ['shipping_zone_id' => $uae->id, 'type' => 'free_shipping'],
            ['title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1]
        );

        $gulf = ShippingZone::firstOrCreate(['name' => 'Gulf Countries'], ['position' => 1]);
        // Confirm this list against the live zone during migration — the survey
        // reported five locations but not which countries they are.
        foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
            $gulf->locations()->firstOrCreate(['type' => 'country', 'code' => $code]);
        }

        ShippingMethod::firstOrCreate(
            ['shipping_zone_id' => $gulf->id, 'type' => 'flat_rate'],
            ['title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0]
        );
        ShippingMethod::firstOrCreate(
            ['shipping_zone_id' => $gulf->id, 'type' => 'free_shipping'],
            ['title' => 'Free delivery', 'cost' => 0, 'min_amount' => 160000, 'enabled' => true, 'position' => 1]
        );
    }
}
