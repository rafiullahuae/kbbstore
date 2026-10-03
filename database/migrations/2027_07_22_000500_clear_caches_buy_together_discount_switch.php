<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane RH: Appearance → Product page → Buy these together → "Discount for
 * buying together" → "Show the total and buy-together discount".
 *
 *   "also buy together pricing and discount row, i want to hide on desktop and
 *    mobile both by default, if hide, then no any discount will be picked from
 *    the system from the buy together discount. lock that discount section is
 *    the section is hided with toggle button."
 *
 * partials/fbt.blade.php (the row's condition and its device classes) and the
 * admin console's Buy these together partial changed, and three settings were
 * added to BuyTogetherSettings; a stale compiled view or a cached settings map
 * would keep drawing — and pricing — the old way. The usual sweep, as every
 * clear_caches_* migration here.
 *
 * NO ROUTE IS ADDED: the switch saves through the existing
 * POST /admin-api/product-page, under its existing capability.
 *
 * NOTHING IS WRITTEN, and that is what turns the discount OFF: the switch
 * reads its code default when `bt_discount_on` is absent, and the default is
 * OFF because he asked for off. His stored tiers (`bt_tier_3/4/5`) and the
 * coupons switch are left exactly as they are, so turning it back on restores
 * them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        // Best effort: on a host with no cache table or a cold store this is a
        // no-op, and every one of these values is re-read from the database.
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
