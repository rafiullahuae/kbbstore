<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Picture galleries in the admin's rich-text boxes add one route (GET
 * /admin-api/media/link-targets, routes/rte-media-admin.php) and change shop
 * views (post, page) and RichText, so the compiled route table and views are
 * cleared, as every package that adds a route does. Literals only -- nothing this
 * package adds is called here, because the updater can still be running the
 * old classes (see 2.60.512 / 2.60.513).
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
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($file, true);
                }
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_invalidate')) {
            foreach ([
                'app/Http/Controllers/Admin/RteLinkTargetsController.php',
                'app/Support/DescriptionGallery.php',
                'app/Support/RichText.php',
                'routes/rte-media-admin.php',
                'routes/web.php',
            ] as $relative) {
                @opcache_invalidate(base_path($relative), true);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Description galleries. Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
