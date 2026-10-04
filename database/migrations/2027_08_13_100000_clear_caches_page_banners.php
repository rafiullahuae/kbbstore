<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Pages → Page banners, and /super-sale/ listing the Super Sale category in
 * its Reorder order. (Lane SS)
 *
 * Adds two admin routes (routes/page-banners-admin.php), so the compiled route
 * cache has to go, as CLAUDE.md requires of every package that adds one; and
 * changes two storefront views, so the compiled views go too. Writes no data:
 * the banner and the product source both ship as code defaults and are stored
 * only when somebody presses Save.
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
            echo "Pages -> Page banners is ready; /super-sale/ lists the Super Sale category in its Reorder order.\n";
        }
    }

    public function down(): void {}
};
