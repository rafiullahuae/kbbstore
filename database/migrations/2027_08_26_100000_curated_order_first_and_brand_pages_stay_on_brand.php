<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.402. The owner: "the re-order ... is not working ... must be super
 * working as per order we set" and "every brand have shop all etc button,
 * which i don't want at all, if user come to any brand, it should be stick to
 * that brand only".
 *
 * - Catalog -> Reorder drives the default sort: the Product Sorting module on
 *   (it is the switch ShopController reads; an earlier migration turned it on,
 *   this makes sure).
 * - The brand page's "Shop all {brand}" button and its "Popular right now /
 *   View all" link both led to /shop/?filter_brands=..., off the brand page:
 *   both off. Their switches stay in Appearance -> Site layout -> Brand page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_toggles')) {
            $row = DB::table('module_toggles')->where('module', 'product_sorting')->first();
            if ($row === null) {
                DB::table('module_toggles')->insert(['module' => 'product_sorting', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('module_toggles')->where('module', 'product_sorting')->update(['enabled' => true, 'updated_at' => now()]);
            }
        }

        foreach (['layout_brand_cta', 'layout_brand_popular'] as $key) {
            DB::table('settings')->where('key', $key)->update(['value' => '0']);
        }

        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        foreach (['kbb.modules', 'kbb.settings', 'kbb.settings.map'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }

        if (app()->runningInConsole()) {
            echo "Shop listings follow Catalog -> Reorder first; brand pages lose Shop all / View all.\n";
        }
    }

    public function down(): void {}
};
