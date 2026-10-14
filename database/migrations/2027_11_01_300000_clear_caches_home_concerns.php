<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.508: the homepage's Shop by skin concern section (HomeConcerns, the
 * homepage view and its admin screens). New PHP, views and stylesheet; view,
 * config and OPcache cleared, and the homepage's own caches, so it shows at
 * once. Nothing stored changes.
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

        // The homepage sections' one cache entry (HomeSections::data()) is
        // rebuilt with the chips' collections in it.
        if (method_exists(\App\Support\HomeSections::class, 'flush')) {
            \App\Support\HomeSections::flush();
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
