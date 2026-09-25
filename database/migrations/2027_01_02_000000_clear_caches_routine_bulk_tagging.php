<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Lane Q4 — bulk tagging on Catalog → Build my routine.
 *
 *   - ROUTES, and it is the reason this file exists. routes/build-my-routine-
 *     admin.php gains ONE path: POST /admin-api/routine-products-bulk. A
 *     compiled route table on the live host does not contain it, and the
 *     failure mode is precise and awful: the selection checkboxes paint, he
 *     ticks twenty-five rows, presses "Use all 25 for Toner", and the request
 *     404s. The screen says so in words — the explain() branch for a 404 on
 *     this screen names the route cache by name — but a control that fails on
 *     its first press is a control he will not press again.
 *
 *   - VIEWS. resources/views/admin/partials/routines-screen.blade.php gains the
 *     selection column, the bulk bar and the undo bar. Compiled Blade is keyed
 *     by path with a filemtime freshness check, and an update package is an
 *     unzip, so the timestamps are whatever the archive carried. A stale
 *     compiled partial is the case that does not self-correct: the endpoint
 *     would exist and nothing on the screen would reach it.
 *
 *   - OPCACHE. App\Http\Controllers\Admin\RoutinesApiController gains bulk()
 *     and App\Support\AdminCapabilities gains the rule that makes it
 *     catalog.manage. A worker holding the old AdminCapabilities beside the new
 *     route finds no rule for /admin-api/routine-products-bulk and falls
 *     through to owner-only — the safe direction, and still wrong for a manager
 *     who should have it.
 *
 * NO SCHEMA CHANGE AT ALL. No column is added, no column is altered, and NOT
 * ONE ROW IS WRITTEN. Bulk tagging is a control, not a default: applying this
 * package tags nothing, publishes no concern page, and leaves
 * App\Support\ConcernCollections::live() exactly as it was. Every product's
 * routine_role and routine_concerns are byte-identical the minute after this
 * runs.
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
            echo "Cleared {$cleared} compiled files; Catalog -> Build my routine\n";
            echo "can now tag several products at once: tick the rows, then press\n";
            echo "one step or one concern for all of them, with Undo beside it.\n";
            echo "Nothing is tagged by this update and no concern page changes.\n";
        }
    }

    /** Nothing to undo. Deleting a cache is not a change to reverse. */
    public function down(): void {}
};
