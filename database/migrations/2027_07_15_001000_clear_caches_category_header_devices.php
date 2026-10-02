<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane QC: the category header's designs as tiles, per device, with a live
 * preview and fine-tuning.
 *
 * "i need the same designs on backend to choose the category banner designs,
 * text style etc and for mobile also. i will set and upload the banners
 * manually."
 *
 * ONE JOB: the compiled views. components/kbb-title-header is unchanged, but
 * the two admin partials changed and a new one (title-header-kit) is included
 * by both, and a stale compiled copy of either would draw 2.60.349's dropdowns
 * against a server that now expects per-device keys. The usual sweep.
 *
 * NO STORED SETTING IS TOUCHED, deliberately. A shop that saved a choice
 * before phone and laptop were separate keeps it on BOTH devices through
 * SiteLayout::all(), which hands each laptop key the phone's value while the
 * laptop has none of its own -- and a category's PY-era `align` / `treatment`
 * / `box` is read by TitleHeader as both devices' value. Nothing a shop chose
 * moves by applying this.
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
