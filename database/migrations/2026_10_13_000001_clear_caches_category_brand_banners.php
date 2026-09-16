<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the category/brand banner and the Brands editor.
 *
 * Blade changed in four places — store/shop.blade.php, store/brands.blade.php,
 * the new components/kbb-banner.blade.php and admin/app.blade.php, which now
 * includes admin/partials/brands-editor-screen.blade.php — so the compiled
 * view cache is stale and the old markup would keep rendering out of it.
 *
 * Routes changed too: routes/brands-admin.php still registers the same four
 * paths, but the integrator wires nothing new here. The route cache is cleared
 * regardless, because the cost is nil and a half-cleared cache is the harder
 * thing to reason about — the same reasoning as
 * 2026_10_12_000001_clear_caches_review_badge_parity.
 *
 * ShopController's sidebar caches (kbb.shop.cats / kbb.shop.brands) are
 * forgotten as well. They hold `id, name, slug` only and carry no banner, but
 * they are keyed by name with no fingerprint, and a stale entry surviving a
 * deploy is exactly the kind of thing that makes a shipped change look like it
 * did not ship.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets
 * go of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * No schema change here (that is the sibling migration), and nothing below
 * positions a column with an AFTER clause.
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
        foreach (['kbb.settings.map', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
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
