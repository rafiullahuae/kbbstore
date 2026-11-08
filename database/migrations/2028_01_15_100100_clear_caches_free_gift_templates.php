<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Free gifts -> Templates. (Lane GT)
 *
 * A NEW ROUTE FILE. routes/free-gift-templates-admin.php is required from
 * routes/web.php, and the router dispatches against bootstrap/cache/routes-*.php
 * rather than against the source. Without this the card's every request
 * answers 404.
 *
 * A CHANGED BLADE (admin/partials/free-gifts-screen.blade.php), compiled into
 * storage/framework/views and keyed by path: an unzip's timestamps are not
 * reliably newer than the copy already there.
 *
 * The table itself is 2028_01_15_100000_create_free_gift_templates.php.
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
            echo "Cleared {$cleared} compiled files; Free gifts -> Templates is ready.\n";
        }
    }

    public function down(): void {}
};
