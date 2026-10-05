<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Catalog → Pagination. (Lane PG)
 *
 * Adds admin routes (routes/pagination-admin.php), so the compiled route cache
 * has to go, as CLAUDE.md requires of every package that adds one.
 *
 * WRITES NO DATA. Pagination stays on everywhere and no listing carries an
 * override until the owner sets one: an absent `pagination_on` row reads as
 * on, an absent `pagination_overrides` row as "follow" for every listing.
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
            echo "Catalog → Pagination is ready.\n";
        }
    }

    public function down(): void {}
};
