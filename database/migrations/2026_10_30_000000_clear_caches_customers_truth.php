<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Customers / Orders truth-up.
 *
 * WHAT CHANGED, AND WHY A STALE COPY WOULD BE WORSE THAN NO CHANGE.
 *
 * Three things moved together, and two of them are a PHP change and a view
 * change that only make sense as a pair:
 *
 *  1. The "registered between" filter on Store -> Customers and the "placed
 *     between" filter on Store -> Orders now convert the date the owner typed
 *     from the shop's clock to the UTC instant the column actually holds
 *     (App\Support\StoreTime::startOfDayUtc), instead of comparing a Dubai date
 *     against a UTC calendar day. Pure PHP.
 *
 *  2. Both list endpoints now emit `is_demo`, and the Customers table draws the
 *     same "demo" badge the Orders table already drew. This is the half that
 *     breaks if only one side lands: the Orders table has rendered that badge
 *     from `o.is_demo` since Demo Content shipped, but the serialiser behind
 *     /admin-api/orders-list never sent the field, so the badge has never once
 *     appeared. A stale compiled view would leave the new Customers badge
 *     unrendered while the API sends it, which is the same silent half-change.
 *
 *  3. "Date created" on the order detail screen is printed instead of being
 *     three input boxes that nothing ever saved. A stale view keeps offering
 *     the owner an editor that discards what they type -- a screen confidently
 *     stating something the code does not do, which is precisely the failure
 *     this change removes.
 *
 * The host cannot be shelled into or restarted, so the PHP a package writes is
 * not the PHP the server runs until OPcache lets go of the old copy -- the
 * standing reason behind the withdrawn packages 2.60.102-.106 recorded in
 * CLAUDE.md.
 *
 * No route was added, so the route cache is dropped only for consistency with
 * the other entries here.
 *
 * No schema change, and nothing here positions a column with an AFTER clause,
 * the thing that made nine earlier migrations silent no-ops on MySQL.
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
        // no-op, and the settings are re-read from the database anyway.
        try {
            Cache::forget('kbb.settings.map');
        } catch (\Throwable) {
            // Nothing to do — a missing cache store is not a failed migration.
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
