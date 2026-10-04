<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear the caches for Lane PF: one heading size and one description size per
 * device on every homepage section, the "8 sets" badge off, the homepage's
 * cold-build queries for the switched-off sections skipped, the Signature
 * preset made the owner's row-55 page, About us behind Read more, shorter
 * brand tiles on a phone and the phone carousels' peek (the saved-settings
 * half of that is 2027_07_28_200100).
 *
 * store/home.blade.php, kbb.css (a new build) and HomepageContent's schema
 * (nine new settings, all read with their defaults until saved) changed, and
 * HomeController's cached rails changed shape, so compiled Blade, the config,
 * the settings maps, opcache and the homepage's cached rows go. No data is
 * written and no route is added.
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

        // Best effort: a missing cache table or a cold store is not a failed
        // migration, and every one of these is rebuilt on the next request.
        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings',
            'kbb.home.rails', 'kbb.home.brands', 'kbb.home.cats', 'kbb.home.posts', 'kbb.home.reviews',
            'kbb.home.routine', 'kbb.home.count', 'kbb.home.brandcount'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
                // A missing cache store is not a failed migration.
            }
        }

        try {
            \App\Support\HomeSections::flush();
        } catch (\Throwable) {
            // As above.
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
            echo "THIS ONE CHANGES YOUR HOMEPAGE, because you asked for it:\n"
                ."  - every section heading is one size (34px on a laptop, 24px on a phone) and every\n"
                ."    line under a heading 16px on both, the Best Sellers style you ticked\n"
                ."    (Appearance -> Homepage content -> Section headings)\n"
                ."  - the \"8 sets\" badge beside Big savings bundles is off\n"
                ."    (Appearance -> Homepage content -> Big savings bundles -> Show how many sets beside the heading)\n"
                ."  - the Signature preset is now your homepage: the banner and the nine sections\n"
                ."    (Appearance -> Homepage -> Layouts)\n"
                ."  - About us shows its first lines, fading, with Read more, on a laptop and a phone\n"
                ."    (Appearance -> Homepage content -> About us -> Read more / Text shown before Read more)\n"
                ."  - the brand tiles on a phone are 54px tall, not 74px\n"
                ."    (Appearance -> Homepage content -> Brands -> Tile height · phone)\n";
        }
    }

    public function down(): void {}
};
