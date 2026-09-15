<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the cart-layout package.
 *
 * No route changes here, so the usual route-cache reason does not apply. Three
 * others do, and every one of them fails silently rather than erroring:
 *
 *   - VIEWS. resources/views/store/cart.blade.php and cart-inner.blade.php both
 *     changed. Compiled Blade is keyed by source path and mtime, which is
 *     normally enough — but these files arrive by unzipping a package, and an
 *     archive that preserves mtimes can land a new source next to a compiled
 *     copy that still looks current. The visible symptom would be the cart page
 *     still rendering `class="lead"` on the count, i.e. the bug this package
 *     exists to fix, with no error anywhere.
 *
 *   - THE MODULE CACHE. This package adds the `cart_coupon_field` module and
 *     SettingsService::moduleEnabled() reads through a rememberForever entry
 *     under `kbb.modules`. A stale entry is not wrong for this key — an absent
 *     key falls through to the default either way — but the admin's Modules
 *     screen counts from the same map, so clearing it keeps the header count
 *     honest from the first page load after the update.
 *
 *   - OPCACHE. ModuleRegistry.php is the file the admin's Modules screen is
 *     built from, and workers may still hold the previous compiled copy. The
 *     new row would simply not appear on the screen.
 *
 * Deliberately NOT here: any write to `module_toggles` for `cart_coupon_field`.
 * The key is new, so no install has ever expressed an opinion about it, and
 * moduleEnabled() already returns the registry default (false) when the row is
 * absent. Writing a row would turn "we have not been asked" into "the owner
 * chose off", and would then also stand to overwrite the choice of anyone who
 * turns it on before a later re-run. The default belongs in ModuleRegistry; the
 * table is for decisions a human has actually made.
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

        Cache::forget('kbb.modules');
        Cache::forget('kbb.settings');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
