<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.515: Analytics -> Live -> Happening now links each viewed page to the
 * shop page (admin view only). Compiled views and caches cleared, as every
 * screen change has been. Literals only -- nothing this package adds is called here, because
 * the updater can still be running the old classes (see 2.60.513).
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
            foreach (['resources/views/admin/partials/site-analytics-screen.blade.php'] as $relative) {
                @opcache_invalidate(base_path($relative), true);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Analytics feed links. Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
