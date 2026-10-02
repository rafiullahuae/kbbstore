<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane RC: the single-image banner, the slider's fit and height caps, and the
 * homepage banner section sitting flush under the header.
 *
 * ONE JOB: the compiled views. store/home.blade.php, the slider partial and
 * the banners admin screen changed, and partials/home/single-banner is new; a
 * stale compiled home.blade.php would keep printing `padding-top:8px` and a
 * stale slider would keep cropping. The usual sweep -- the schema and the row
 * moves are 2027_07_18_000300's.
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

        // Best effort: every one of these is re-read from the database.
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings', 'kbb.shop.cats', 'kbb.shop.brands'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
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
