<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch the random light box ON for this shop. (Integrator, 2.60.352+)
 *
 * The owner: "can we have options to use random layout on random categories,
 * where we didin't upload the background image yet. so on each page load, it
 * will give random colored background as we have multiple designs."
 *
 * SiteLayout's own default for `cat_header_box_random` is OFF, so a fresh
 * install and every test draw the same box every time. This writes the ON he
 * asked for on a shop that has categories (never in the test suite) -- and
 * only where no value is
 * stored: a shop that has already turned it off keeps its off. Appearance → Site layout → Category header →
 * "A different light box on every visit" turns it off again.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            // EVERY SHOP BUT THE TEST SUITE'S. Its database is built by running
            // these migrations and seeded with categories, and its pages must
            // be the same from one load to the next; it keeps the schema's
            // off. (Whether the live shop calls itself production or staging
            // does not matter -- env.staging.txt says staging.)
            $isShop = ! app()->runningUnitTests() && Schema::hasTable('categories') && DB::table('categories')->exists();

            if ($isShop && Schema::hasTable('settings') && ! DB::table('settings')->where('key', 'layout_cat_header_box_random')->exists()) {
                DB::table('settings')->insert([
                    'key' => 'layout_cat_header_box_random', 'value' => '1', 'autoload' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                // Setting::map() keeps its own cache beside SettingsService's.
                \App\Models\Setting::flushMap();
            }
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) {
                echo 'Random light box: could not write the setting ('.$e->getMessage().").\n";
            }
        }

        // The admin screen gained the switch: compiled Blade is keyed by path.
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }
    }

    public function down(): void {}
};
