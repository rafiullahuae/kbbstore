<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Lane SP: instant page changes. routes/instant-nav.php adds POST /api/viewed
 * (a product page fetched ahead records "Recently viewed" when it is opened),
 * so the compiled route cache goes, per the convention for a package that
 * adds a route. Nothing in the database changes: the two Page speed switches
 * read their defaults from SiteLayout::SCHEMA.
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

    public function down(): void {}
};
