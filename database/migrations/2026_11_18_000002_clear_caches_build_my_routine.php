<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Build-my-routine package (Lane FM).
 *
 *   - ROUTES, and this is the one that matters. routes/build-my-routine.php
 *     adds GET /routines and GET /routines/{concern};
 *     routes/build-my-routine-admin.php adds five paths under /admin-api. A
 *     compiled route table on the live host contains none of them, and the
 *     failure here is uniquely nasty: the storefront pages 404 when the module
 *     is OFF *by design*, so a stale route table is indistinguishable from the
 *     switch being off. Somebody would turn the module on, fetch /routines, get
 *     a 404, and conclude the feature does not work.
 *
 *   - VIEWS. resources/views/admin/app.blade.php gains one @include line
 *     (docs/FM-ADMIN-APP-BLOCKS.md) and resources/views/store/routines.blade.php
 *     is new. Compiled Blade is keyed by the view's PATH and its freshness check
 *     is a filemtime comparison; an update package is an unzip, so the
 *     timestamps it lands are whatever the archive carried. A stale compiled
 *     app.blade.php is the case that does not self-correct — the console would
 *     paint with no Build-my-routine screen at all while the endpoints behind it
 *     answered perfectly.
 *
 *   - OPCACHE. App\Services\BuildMyRoutine, App\Models\Routine,
 *     App\Support\RoutineRoles and App\Support\RoutineConcerns are all new
 *     classes, and App\Services\ModuleRegistry, App\Support\AdminCapabilities
 *     and App\Http\Controllers\Store\PageController have all changed. A worker
 *     holding the old AdminCapabilities beside the new routes would find no rule
 *     for /admin-api/routine-products and fall through to "no match means
 *     owner-only" — the safe direction, but it would refuse a manager who
 *     should have it.
 *
 * THE SCHEMA CHANGES ARE THE TWO MIGRATIONS BESIDE THIS ONE and are not
 * repeated here: 2026_11_18_000000 adds products.routine_role and
 * products.routine_concerns, 2026_11_18_000001 creates `routines`. Both are
 * additive, both are guarded with hasColumn/hasTable, and neither changes a
 * single existing value — every product starts untagged, which is the true
 * state of this catalogue on the day the package applies.
 *
 * Best-effort throughout, like every other clear_caches migration in this set:
 * a file that cannot be unlinked mid-update must not fail the package and
 * strand the site half-updated. A stale cache is a visible bug; a failed
 * migration is an outage.
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
            echo "Cleared {$cleared} compiled files; /routines and the five\n";
            echo "/admin-api/routine* paths are now in the route table. The two\n";
            echo "storefront pages stay 404 until Store -> Modules -> Build my\n";
            echo "routine is switched on, which is how this ships.\n";
        }
    }

    /** Nothing to undo. Deleting a cache is not a change to reverse. */
    public function down(): void {}
};
