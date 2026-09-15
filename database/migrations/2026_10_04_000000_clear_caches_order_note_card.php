<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Customer note card fix.
 *
 * VIEWS are the whole reason this exists. The only changed file is
 * resources/views/admin/app.blade.php, and compiled Blade is keyed by path --
 * so a file that already exists on the server is exactly the case that does
 * not self-correct. Without this, the admin console would keep serving the
 * previous compiled copy, the card would keep rendering with no padding, and
 * the package would look like it had done nothing.
 *
 * No PHP class changed and no route was added, so OPcache is not the risk here
 * that it was in the last two packages -- but opcache_reset() is cheap and the
 * cost of guessing wrong on this host, where there is no shell to check from,
 * is another inert release. Packages 2.60.102-.106 are the standing reminder.
 *
 * No schema change. Nothing here positions a column with an AFTER clause,
 * which is what made nine earlier migrations in this repo silent no-ops on
 * MySQL: an ALTER naming a column that does not exist yet is an error there,
 * and the hasColumn guards wrapped around them made that error look clean.
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
