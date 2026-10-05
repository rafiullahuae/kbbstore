<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Homepage → Brands: an image per brand, and a look per device. (Lane BS)
 *
 * No route and no column: the images are one setting (`home_br_imgs`, a JSON
 * map brand id → path) and the looks two selects, all in homepage_content and
 * saved through the endpoint that already owns them. What has to go is the
 * compiled Blade (partials/home/hs-brands and the admin popup changed) — a
 * stale compiled view would draw the old cards with the new stylesheet.
 *
 * NOTHING IS WRITTEN. The two new selects are new keys, so a saved shop reads
 * their defaults — Look · laptop "Image + name — no logo" and Look · phone
 * "Text only", which are what the owner asked for — without a row existing.
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

        \App\Support\HomeSections::flush();

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
            echo "Homepage brands: image + name on a laptop, the names alone on a phone; choose an image per brand at Appearance -> Homepage content -> Top brands -> Edit content -> Brands tab (the looks: Layout tab).\n";
        }
    }

    public function down(): void {}
};
