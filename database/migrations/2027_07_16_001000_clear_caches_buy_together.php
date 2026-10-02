<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for "Buy these together". (Lane RB)
 *
 * Two routes are new (routes/buy-together.php: POST /api/cart/add-together
 * and POST /api/product-view), and a compiled route table does not see a new
 * route until it is cleared — CLAUDE.md's rule for every package that adds
 * one. Blade changed too: partials/fbt.blade.php is a different section now,
 * and the admin console includes a new screen partial. The settings map, the
 * module map and the section's own category cache are forgotten so the new
 * switch and the category pairs are read fresh.
 *
 * Dated 2027_07_16_001000: after the random light box's 2027_07_16_000100 and
 * after this lane's own three migrations (000500 the view counter table,
 * 000600 the switch, 000700 the Arabic drafts), so it runs last.
 *
 * No schema change.
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
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.bt.cats'] as $key) {
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
