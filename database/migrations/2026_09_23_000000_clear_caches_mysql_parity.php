<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the MySQL parity fixes.
 *
 * CustomersApiController changed again (aggregate() now also discards the
 * ORDER BY, LIMIT and OFFSET, and their bindings) and its BUILD constant moved
 * to 2.60.123. The schema migration in this package widens two config columns
 * and does not reset OPcache, so without this the server could keep executing
 * the previous compiled class exactly as it did after 2.60.121 — which is the
 * whole reason that release appeared to fail.
 *
 * Any package that changes a PHP class ships one of these, not only the ones
 * that add routes. The packager now warns when a package changes code and
 * carries no migration the server has yet to run.
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

        Cache::forget('kbb.modules');
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
