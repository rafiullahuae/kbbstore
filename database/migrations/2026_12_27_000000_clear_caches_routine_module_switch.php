<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Lane Q round 3.
 *
 *   - ROUTES, and it is the reason this file exists. routes/build-my-routine-
 *     admin.php gains ONE path: POST /admin-api/routines-module, the switch
 *     that publishes the routine pages, surfaced on the screen where the
 *     tagging is done. A compiled route table on the live host does not contain
 *     it, and the failure mode is the one this round was opened to answer: the
 *     switch paints, he presses it, the request 404s, and the screen turns that
 *     into one generic sentence. He would conclude the switch does not work.
 *
 *   - VIEWS. resources/views/admin/partials/routines-screen.blade.php is
 *     substantially rewritten (the live search, the module card, the demo card)
 *     and resources/views/admin/app.blade.php gains one row in its demo content
 *     list. Compiled Blade is keyed by path with a filemtime freshness check,
 *     and an update package is an unzip, so the timestamps are whatever the
 *     archive carried. A stale compiled partial is the case that does not
 *     self-correct.
 *
 *   - OPCACHE. App\Http\Controllers\Admin\RoutinesApiController gains
 *     saveModule() and the ingredients-column probe,
 *     App\Http\Controllers\Admin\DemoContentController gains a type and a
 *     generator, and App\Support\AdminCapabilities gains the rule that makes
 *     the new endpoint store.settings rather than catalog.*. A worker holding
 *     the old AdminCapabilities beside the new route would find no rule for
 *     /admin-api/routines-module and fall through to owner-only — the safe
 *     direction, and still wrong for a manager who should have it.
 *
 * NO SCHEMA CHANGE AT ALL in this round. Nothing is added, nothing is altered,
 * and no row is written: the demo content creates nothing until somebody
 * presses the button, and the module toggle ships at the value it already has.
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
            echo "now carries the on/off switch and a Demo data card, and its\n";
            echo "product search runs as you type. The module is still OFF and\n";
            echo "no demo row exists until you press Import.\n";
        }
    }

    /** Nothing to undo. Deleting a cache is not a change to reverse. */
    public function down(): void {}
};
