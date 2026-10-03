<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane RG: the product page's laptop switches, the buy column's laptop order,
 * Tabby & Tamara on laptops, and the details block (tab-row gap per device,
 * leading blank lines in a tab body not drawn, the eyebrow and heading
 * switches).
 *
 * store/product.blade.php (the wrapper's class/style), the Desktop sections
 * console partial and Store\ProductController changed, and a stale compiled
 * copy of either would keep drawing the old template. The usual sweep.
 *
 * NO ROUTE IS ADDED: everything saves through the existing
 * POST /admin-api/product-page, under its existing capability.
 *
 * NOTHING IS WRITTEN HERE. The three new settings rows (`pdpds_buy_order`,
 * `pdpds_off`, and Mobile sections' heading switches) read their defaults
 * when absent. Defaults that MOVE, each because he asked: Tabby & Tamara is
 * drawn on laptops; "The details" and the "Product details" heading are
 * hidden on laptops (the eyebrow already was on phones; the heading now is
 * too); a tab body's leading blank lines are not drawn. The tab-row gap is
 * the migration before this one.
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
