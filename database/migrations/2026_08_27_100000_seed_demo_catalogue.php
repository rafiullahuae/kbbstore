<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Loads the placeholder catalogue.
 *
 * Written as a migration rather than a seeder run by hand, because the updater
 * runs migrations automatically — so installing the shop and filling it with
 * something to look at is one action, with no shell and no file manager.
 *
 * Idempotent: the seeder uses firstOrCreate throughout.
 *
 * Every row it writes has wc_id = null. The WordPress Migrator upserts on wc_id,
 * so it can never mistake these for real products, and clearing them later is a
 * single delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\DemoCatalogueSeeder())->run();

        // The shop sidebar caches brand and category counts; without this the
        // new products would not appear in the filters for 15 minutes.
        \App\Http\Controllers\Store\ShopController::flushSidebarCache();
    }

    public function down(): void
    {
        // Only the placeholders — anything imported from WordPress has a wc_id.
        \App\Models\Product::whereNull('wc_id')->forceDelete();
    }
};
