<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Clear the compiled caches for the admin sidebar package.
 *
 * ── WHAT IS STALE IN THIS PACKAGE ─────────────────────────────────────────
 *
 * One file, and it is the biggest Blade in the repository:
 * `resources/views/admin/app.blade.php`. It gains the LATE_NAV declaration and
 * its registration, the kbbNavClick guard, the two marker ids on renderDash()
 * and frameStartupHTML(), and the early /admin-api/stats request. NO route
 * changes and no config changes -- `route:clear` and `config:clear` are here
 * because they cost nothing and the next package would run them anyway.
 *
 * ▲ WITHOUT THIS THE PACKAGE CAN APPLY AND CHANGE NOTHING AT ALL, and the
 *   reason is not obvious. Blade decides whether to recompile in
 *   Compiler::isExpired(), which is one comparison:
 *
 *       filemtime(source) >= filemtime(compiled)
 *
 *   A package arrives as a zip and is applied by unzipping it. A zip stores
 *   each file's mtime and unzip RESTORES it, so the "new" app.blade.php lands
 *   carrying the mtime it had on the machine that built the package -- which is
 *   routinely OLDER than the compiled view already sitting on the server. The
 *   comparison then says the compiled copy is current, Blade never recompiles,
 *   and the owner applies a package, sees the version number move, and gets the
 *   old console. Demonstrated rather than assumed: a source stamped 10 Sep
 *   under a compiled file stamped 20 Sep is not recompiled.
 *
 *   `clear_caches_set_screen` records the same thing about this host in its own
 *   words -- "on this host the compiled views outlive the files they came
 *   from" -- so this is the established mitigation and not a new idea.
 *
 * ▲ AND `migrations` IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT ALL.
 *   UpdateRunner::hasMigrations() reads that flag and never looks at the files.
 *   Build the package with `php artisan kbb:package <version> --since=<ref>`,
 *   which sets the flag from the presence of a file like this one; five
 *   packages once shipped eight migrations that were copied to the server and
 *   never executed because a hand-rolled builder did not know about it.
 *
 * ── WHY EVERY CALL IS GUARDED ─────────────────────────────────────────────
 *
 * A host with no cached views answers `view:clear` happily; a read-only
 * bootstrap/cache throws. A cache that cannot be cleared is one the next deploy
 * clears, and it is never a reason to fail an update and strand the shop
 * mid-package.
 *
 * The guard is safe in the sense CLAUDE.md's swallowed-exception landmine is
 * about: it wraps a call that writes NO model state, so there is nothing left
 * dirty for a later save to re-send.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['view:clear', 'config:clear', 'route:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // A cache that cannot be cleared is one the next deploy clears.
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: this migration has no schema and no data.
    }
};
