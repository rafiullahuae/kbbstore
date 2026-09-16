<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the narrowed public product index.
 *
 * No route and no Blade changed, so the route cache is correct either way.
 * OPCACHE is the reason this exists: the host cannot be restarted or shelled
 * into, so the file a package writes is not the PHP the server runs until
 * OPcache lets go of the old copy. That is the standing reason behind the
 * withdrawn packages 2.60.102-.106 recorded in CLAUDE.md, and it matters here
 * because the change is a single `select()` inside one method — nothing about
 * the endpoint's output would look different while the old bytecode is still
 * being served, so a stale copy would be invisible.
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

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
