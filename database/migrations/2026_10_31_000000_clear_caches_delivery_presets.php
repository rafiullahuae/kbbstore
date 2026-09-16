<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Gulf delivery presets — Lane CY.
 *
 * ONE BLADE FILE CHANGED, and it is the console: admin/app.blade.php. Store ->
 * Delivery & Shipping -> Delivery lines gains a bar of one-click suggestions
 * for the six Gulf countries, and each row gains a preview of the finished
 * sentence when its text carries the {country} placeholder.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND THE SCREEN LOOKS UNCHANGED. The server
 * serves Blade from storage/framework/views, so the old compiled console goes
 * on rendering a Delivery lines tab with no preset bar, from a file that is no
 * longer on disk — and the symptom is not an error, it is the owner opening the
 * tab he was told about and finding nothing new in it.
 *
 * The console is ONE compiled view: app.blade.php pulls its partials in with
 * an include, so a package that rewrote only a partial would leave the parent's
 * cached compile serving the old copy. app.blade.php itself changed here, so
 * that trap is not armed; the views are cleared wholesale anyway.
 *
 * OPcache is the other half, and it is why this migration matters beyond the
 * views. Two new classes arrive in this package — App\Support\CountryPresets
 * and App\Support\CountryTemplate — and App\Support\DeliveryLine changes to
 * substitute the placeholder on the way out. The host cannot be restarted or
 * shelled into, so the PHP a package writes is not the PHP the server runs
 * until OPcache lets go of the old copy. A stale DeliveryLine beside a fresh
 * console is the bad half of that: the screen would offer a placeholder the
 * storefront prints verbatim, which is the one outcome worth a migration.
 *
 * NO ROUTE CHANGED. The screen still saves through PUT /admin-api/settings and
 * still writes the same `delivery_texts` key, already in
 * AdminController::SETTING_RULES. The route cache is dropped with the rest
 * because the cost is nil and a half-cleared cache is harder to reason about.
 *
 * NO SCHEMA CHANGE AND NO SEEDED ROW. The presets are offered by a screen, not
 * written by this migration. `delivery_texts` is untouched here, so a shop that
 * applies this package and never opens the tab tells every shopper exactly what
 * it told them before — which is the whole reason the suggestions are chips
 * rather than defaults.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
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

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
