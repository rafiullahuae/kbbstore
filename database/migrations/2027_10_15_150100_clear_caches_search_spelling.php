<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * (Lane SR) Spelling mistakes in search. No route is added; this clears the
 * compiled views (store/shop.blade.php draws the "Showing results for" line)
 * and drops the spelling dictionary so it is built from the live catalogue on
 * the first search that needs it. Same body as every clear_caches_* migration.
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

        try {
            \App\Support\SearchSpelling::flush();
        } catch (\Throwable) {
            // A cache that cannot be reached must not fail the update.
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; Store -> Site Search -> Spelling mistakes is on.\n";
        }
    }

    public function down(): void {}
};
