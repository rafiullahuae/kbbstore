<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Safety → 404 page. (Lane NF)
 *
 * Adds admin routes (routes/not-found-page-admin.php), so the compiled route
 * cache has to go, as CLAUDE.md requires of every package that adds one; and a
 * new storefront view, so the compiled views go too.
 *
 * WRITES NO DATA. An absent `not_found_page` row reads as the defaults, which
 * are the owner's choice (design B, 5 October): the shop's 404 page goes live
 * on apply because he asked for it.
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
            echo "Safety → 404 page is ready: the shop now answers a missing page with its own 404 page (design B).\n";
        }
    }

    public function down(): void {}
};
