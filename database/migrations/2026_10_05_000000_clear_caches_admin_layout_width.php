<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the admin layout width change.
 *
 * VIEWS are the whole point. The only changed file is
 * resources/views/admin/app.blade.php, and compiled Blade is keyed by path, so
 * a file that already exists on the server is exactly the case that does not
 * self-correct: the console would keep serving the previous compiled copy and
 * the console would still stop at 1180px, making the package look inert.
 *
 * No PHP class changed and no route was added, so OPcache is not the risk it
 * was in earlier packages — but opcache_reset() is cheap and the cost of
 * guessing wrong on a host with no shell is another dead release. Packages
 * 2.60.102-.106 are the standing reminder.
 *
 * No schema change, and nothing here positions a column with an AFTER clause —
 * the thing that made nine earlier migrations in this repo silent no-ops on
 * MySQL.
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
