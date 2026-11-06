<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Appearance -> Free gifts strip. (Lane GST)
 *
 * A NEW ROUTE FILE. routes/gift-strip-admin.php is required from
 * routes/web.php, and the router dispatches against bootstrap/cache/routes-*.php
 * rather than against the source. Without this the screen's every request
 * answers 404 -- which it says out loud, but it is still a dead screen.
 *
 * CHANGED BLADES (store/home.blade.php gains one include; the strip's partial
 * and the admin screen are new), compiled into storage/framework/views and
 * keyed by path: an unzip's timestamps are not reliably newer than the copy
 * already there.
 *
 * NO SETTING ROW IS WRITTEN. The strip ships OFF, and an absent row is off:
 * the homepage is byte-identical until the owner turns it on.
 *
 * NO SCHEMA CHANGE.
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
            echo "Cleared {$cleared} compiled files; Appearance -> Free gifts strip is ready (the strip is off until you turn it on).\n";
        }
    }

    public function down(): void {}
};
