<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Lane FS: POST /admin-api/homepage-hub/type is a new route, so the compiled
 * route table goes (CLAUDE.md: every package that adds a route ships one of
 * these), and the compiled views go with it — the hub partial, the font picker
 * and the storefront layout's preload line all changed.
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
            echo "Homepage content: every section editor has a Fonts & size tab. Appearance -> Site layout -> Fonts picks the body and headings fonts.\n";
        }
    }

    public function down(): void {}
};
