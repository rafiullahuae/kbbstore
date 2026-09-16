<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Store defaults. Values match production where the survey measured them, so a
 * fresh install behaves like the live store rather than like a demo.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Identity
            'store_name' => 'K-Beauty Bliss',
            'currency' => 'AED',
            'store_country' => 'AE',

            // The shop trades from Dubai, so every date in the admin panel and
            // on every document is read on Dubai's clock. Storage stays UTC and
            // APP_TIMEZONE stays UTC with it — this is a DISPLAY setting, read
            // by App\Support\StoreTime, whose header explains why flipping the
            // application timezone instead would move every historical order.
            'store_timezone' => 'Asia/Dubai',
            'support_email' => 'info@kbeautybliss.com',
            'brand_whatsapp' => '+971585052611',
            'brand_accent' => '#E0567B',

            // VAT — display only (D-64). Never alters a total.
            'vat_enabled' => '1',
            'vat_rate' => '5',
            'vat_basis' => 'inclusive',   // inclusive | flat
            'vat_label' => "You're paying VAT ({rate}%)",

            // Fees — production charges AED 10, not the theme default of 5.
            'cod_fee' => '1000',          // fils

            // Shipping display. Real thresholds come from the shipping methods.
            'hide_paid_when_free' => '1',

            // Catalogue
            'products_per_page' => '25',  // production paginates at 25, not 12 (URL contract)
        ];

        foreach ($settings as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value, 'autoload' => true]);
        }
    }
}
