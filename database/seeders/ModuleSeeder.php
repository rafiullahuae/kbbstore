<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ModuleToggle;
use Illuminate\Database\Seeder;

/**
 * The 29 modules from KBB Modules v2.39.0, with the plugin's own defaults.
 *
 * Defaults are deliberately copied rather than chosen: the thirteen checkout and
 * cart modules ship on because the theme's markup expects them, and the rest ship
 * off so a fresh install renders plainly until someone opts in. Same behaviour as
 * WordPress, so a store configured there behaves identically here.
 */
class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            // checkout
            ['module' => 'freeship_bar', 'enabled' => true],
            ['module' => 'vat_line', 'enabled' => true],
            ['module' => 'cod_fee', 'enabled' => true],
            ['module' => 'delivery_line', 'enabled' => true],
            ['module' => 'coupon_hint', 'enabled' => true],
            ['module' => 'legal_notice', 'enabled' => true],
            ['module' => 'reassurance', 'enabled' => true],
            ['module' => 'checkout_thumbs', 'enabled' => true],
            ['module' => 'address_autocomplete', 'enabled' => true],
            ['module' => 'inline_validation', 'enabled' => true],
            ['module' => 'single_name', 'enabled' => true],
            // cart
            ['module' => 'minicart_promo', 'enabled' => true],
            ['module' => 'back_to_cart', 'enabled' => true],
            // store
            ['module' => 'banners', 'enabled' => false],
            ['module' => 'mega_menu', 'enabled' => false],
            ['module' => 'notification_bar', 'enabled' => false],
            ['module' => 'product_labels', 'enabled' => false],
            // payship
            ['module' => 'pay_ship_rules', 'enabled' => false],
            // catalogue
            ['module' => 'product_sorting', 'enabled' => false],
            ['module' => 'brands', 'enabled' => false],
            ['module' => 'wishlist', 'enabled' => false],
            ['module' => 'recently_viewed', 'enabled' => false],
            ['module' => 'frequently_bought', 'enabled' => false],
            // marketing
            ['module' => 'marketing_pixels', 'enabled' => false],
            ['module' => 'abandoned_cart', 'enabled' => false],
            ['module' => 'back_in_stock', 'enabled' => false],
            ['module' => 'newsletter', 'enabled' => false],
            // performance
            ['module' => 'performance', 'enabled' => false],
            // seo
            ['module' => 'seo_engine', 'enabled' => false],
        ];

        foreach ($modules as $module) {
            ModuleToggle::firstOrCreate(['module' => $module['module']], ['enabled' => $module['enabled']]);
        }
    }
}
