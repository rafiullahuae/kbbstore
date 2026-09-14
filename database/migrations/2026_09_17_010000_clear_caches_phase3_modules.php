<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Phase 3 module-gating package (seo_engine,
 * product_sorting).
 *
 * No routes change in this package, so the usual route-cache reason does not
 * apply. Two others do, and both are silent failures rather than errors:
 *
 *   - VIEWS. resources/views/admin/app.blade.php changed (go(id,sub), the
 *     module settings link). Compiled Blade views are keyed by source path and
 *     mtime, which is normally enough — but the admin console is one enormous
 *     compiled file, and a stale copy means the Modules screen keeps rendering
 *     "Its own screen — not built yet" for two modules whose screens this
 *     package just pointed it at. Nothing errors; the fix simply appears not to
 *     have shipped.
 *
 *   - THE MODULE CACHE. SettingsService::moduleEnabled() reads through a
 *     rememberForever entry under `kbb.modules`. The migration alongside this
 *     one writes to `module_toggles` directly, and App\Support\Seo and
 *     ShopController now read that value on every request. A stale cache means
 *     the storefront answers from the pre-migration values indefinitely — the
 *     package would look like it did nothing at all.
 *
 * OPcache too: Seo.php, ShopController.php and ModuleRegistry.php are resolved
 * on workers that may still hold the previous compiled copies, and the whole
 * point of this package is a branch that did not exist in them.
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
