<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the module-framework package (Lane EH).
 *
 * No route file changes in this package, so the usual route-cache reason does
 * not apply on its own account — the route cache is cleared anyway because it
 * costs nothing and a package that half-clears is how a stale artefact survives
 * two releases. Three reasons do apply, and every one of them fails silently
 * rather than erroring:
 *
 *   - VIEWS. resources/views/partials/checkout/order-block.blade.php now
 *     includes a new partial, and store/home.blade.php gained a second
 *     condition on the brand strip. Compiled Blade is keyed by source path and
 *     mtime, which is normally enough, but a stale compiled order-block simply
 *     would not contain the include at all: the legal notice would be
 *     saveable on the admin screen and invisible on the checkout, which is
 *     precisely the "control that saves nothing" shape this package exists to
 *     make impossible.
 *
 *   - THE MODULE CACHE. `kbb.modules` is a rememberForever entry. The migration
 *     alongside this one writes `brands` into module_toggles directly, and
 *     BrandController now aborts on that value for every action. A stale entry
 *     means the storefront answers from the pre-migration value — which is
 *     `false` — and every brand page 404s.
 *
 *   - THE SETTINGS CACHE. `checkout_legal_text` is a new key read on the
 *     checkout through the same snapshot every other setting comes from.
 *
 * OPcache too: ModuleRegistry, ModuleSchema, BrandController, PayShipRules and
 * MarketingPixels are all resolved on workers that may still hold the previous
 * compiled copies, and the point of this package is branches that did not exist
 * in them.
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
        Cache::forget('kbb.module_settings');
        Cache::forget('kbb.home.brands');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
