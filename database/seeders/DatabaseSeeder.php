<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            ModuleSeeder::class,
            ShippingSeeder::class,
            TaxSeeder::class,
            PaymentProviderSeeder::class,
            // Placeholder catalogue so the shop is testable before the WordPress
            // migration. Every row has wc_id = null, so the Migrator cannot
            // confuse these with real products.
            DemoCatalogueSeeder::class,
            // Real rows in `reviews` for those products. MUST follow the
            // catalogue: it seeds `products.rating` and `products.review_count`
            // to zero and this is what fills them in, from the approved rows,
            // via App\Support\ProductRating. Run the other way round and every
            // demo product is left advertising no reviews while carrying them.
            DemoReviewsSeeder::class,
        ]);
    }
}
