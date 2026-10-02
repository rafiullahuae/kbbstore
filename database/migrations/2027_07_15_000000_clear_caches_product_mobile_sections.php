<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane QA: the product page as ordered, switchable sections on a phone, the
 * price row under the title, the share button in the title row and the Tabby
 * & Tamara cards.
 *
 * store/product.blade.php, two new partials under partials/product/, and the
 * admin console (a new screen partial included from admin/app.blade.php)
 * changed, and a stale compiled copy of any of them would keep drawing the old
 * page — or, worse, a new template's markup against an old one's. The usual
 * sweep, as every clear_caches_* migration here.
 *
 * NOTHING IS RESET. Every new setting reads its default when absent, and the
 * mobile-section switches that correspond to a Sections-tab module inherit
 * the phone switch he had already saved there (ProductMobileSections::layout()),
 * so no stored value needs to move for the new defaults to reach the shop.
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
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
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
