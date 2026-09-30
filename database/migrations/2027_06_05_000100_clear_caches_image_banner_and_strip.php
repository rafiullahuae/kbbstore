<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the image-banner round.               (Lane SEC)
 *
 * ── WHY IT IS NEEDED, WHICH IS NOT THE USUAL REASON ─────────────────────────
 *
 * No route changed in this round, so the `clear_caches_*` convention is being
 * followed for the OTHER half of what it does: Blade serves
 * `storage/framework/views` in preference to the template it was compiled
 * from, and two templates changed shape.
 *
 *   resources/views/layouts/store.blade.php   stops drawing the countries
 *                                             strip when the page claims it
 *   resources/views/store/home.blade.php      claims it, and draws it under
 *                                             the banner instead
 *
 * THE FAILURE MODE IF THIS DOES NOT RUN IS NOT "THE OLD PAGE". It is worse
 * than that, and it is the reason this migration is not optional: the two
 * templates are compiled SEPARATELY and cached SEPARATELY, so a server that
 * kept one compiled file and not the other draws the strip twice (old layout
 * plus new homepage) or not at all (new layout plus old homepage). The first
 * is two countries strips on the front page; the second is a control the owner
 * has just been told is on, showing nothing. Neither looks like a stale cache.
 *
 * ── AND THE FOUR CACHE KEYS ─────────────────────────────────────────────────
 *
 * `banner_ships_as_image_slider` writes `settings.header_settings`,
 * `module_toggles.cards_banner` and `module_settings.cards_banner.set`
 * directly through the query builder, which is correct — Setting::map()
 * memoises in a process-level static as well as in the cache, so a migration
 * that went through SettingsService would be reading its own stale memo. The
 * cost of writing underneath the cache is that the cache has to be dropped
 * here, and all three of those reads are cached whole-table.
 *
 * `kbb.home.brands` is forgotten with them for the reason the module-framework
 * clear gives: the homepage's own memo is keyed off nothing that would notice.
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

        Cache::forget('kbb.settings');
        Cache::forget('kbb.modules');
        Cache::forget('kbb.module_settings');
        Cache::forget('kbb.home.brands');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files and four cached reads.\n";
        }
    }

    public function down(): void {}
};
