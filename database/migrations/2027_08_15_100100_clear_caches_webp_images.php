<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Content -> Media Library -> WebP images. (Lane WP)
 *
 * Adds admin routes (routes/webp-admin.php), so the compiled route cache has
 * to go, as CLAUDE.md requires of every package that adds one; and changes the
 * Media Library screen partial, so the compiled views go too. Writes no data:
 * the settings ship as code defaults and the bulk conversion runs only when the
 * owner starts it.
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
            echo "Content -> Media Library -> WebP images is ready: uploads become WebP, and `php artisan kbb:webp --plan` previews the bulk conversion.\n";
        }
    }

    public function down(): void {}
};
