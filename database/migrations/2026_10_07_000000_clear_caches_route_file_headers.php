<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the route-file header corrections.
 *
 * Fourteen files under routes/ were still headed "NOT LOADED YET" or "NOT
 * WIRED YET" while routes/web.php and routes/api.php required them and their
 * routes served live traffic. Only comments changed, so the compiled route
 * table is byte-for-byte what it was -- but the files themselves are PHP that
 * the route cache was built from, and CLAUDE.md's rule is that a package
 * touching PHP ships this migration rather than reasoning about which caches
 * it happened to miss. Packages 2.60.102-.106 are the standing reminder of
 * what guessing wrong costs on a host with no shell.
 *
 * No route was added or removed: route:list reports 309 before and after.
 *
 * No schema change, and nothing here positions a column with an AFTER clause --
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
