<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane GCR: Marketing -> Free gifts -> Overview -> Gift box popups ->
 * "Gifts Criteria popup shows" (Simple rows, the default the owner asked for,
 * or Complete tabs). Clears compiled views and opcache so the new
 * extras-rows partial and the FreeGifts default are served at once. Same body
 * as every clear_caches_*.
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
            echo "Cleared {$cleared} compiled files; Gifts Criteria opens as the homepage rows.\n";
        }
    }

    public function down(): void {}
};
