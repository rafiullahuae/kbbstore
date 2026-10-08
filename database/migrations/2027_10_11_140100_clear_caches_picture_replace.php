<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane RPL: a new admin route (POST /admin-api/product-editor-photo-undo/{id},
 * routes/product-photo-admin.php) and a changed product editor and Media
 * Library picker. The compiled route cache would hide the route and the
 * compiled views the screens until cleared. Files only -- no shop cache holds
 * anything this package changed.
 *
 * Guarded: a cache that will not clear costs minutes, an update that dies
 * half-applied costs the updater.
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
            base_path('bootstrap/cache/events.php'),
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

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
