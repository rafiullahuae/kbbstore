<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for 2.60.240.
 *
 * WHAT IS STALE IF THIS DOES NOT RUN: CartController, and with it every live
 * update on the cart page. The package is one file and adds no route and no
 * Blade change, so on the letter of CLAUDE.md's rule it needs no migration.
 *
 * IT SHIPS ONE ANYWAY, and the reason is worth writing down because the two
 * authorities in this repo disagree.
 *
 * `kbb:package` warns that a code-only package carrying no fresh migration
 * will "run no migration, so no opcache_reset() fires". That premise does not
 * hold today: UpdateRunner::clearCaches() is called UNCONDITIONALLY at step 5,
 * straight after copyFiles() and before migrations are even considered, and it
 * calls opcache_reset() itself. So the warning is stale — it describes an
 * updater that no longer exists, and a migration adds nothing that step 5 has
 * not already done in the same process.
 *
 * The migration is here regardless, for one reason that is not technical: this
 * package fixes a bug on a live shop where every cart write was answering 500,
 * and the owner is waiting on it. Being right about opcache is worth less than
 * being certain. The cost is one file and one row in the migrations table; the
 * cost of being wrong is the owner applying a package that appears to change
 * nothing, which is the 2.60.102-.106 story.
 *
 * If somebody later fixes the warning text in BuildPackage to match
 * UpdateRunner, this comment is the note explaining why a migration exists
 * here that the rule did not require.
 *
 * Best-effort, like every clear_caches migration in this set: a file that
 * cannot be unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the cart page adds, changes quantity and\n";
            echo "removes in real time again instead of only on a reload.\n";
        }
    }

    public function down(): void {}
};
