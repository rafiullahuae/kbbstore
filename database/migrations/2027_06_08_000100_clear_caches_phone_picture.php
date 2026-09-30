<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the phone-picture round.               (Lane SEC)
 *
 * NO ROUTE CHANGED, so this follows the `clear_caches_*` convention for its
 * other half: Blade serves `storage/framework/views` in preference to the
 * template it was compiled from, and two templates changed shape.
 *
 *   resources/views/partials/home/slider-banner.blade.php   draws <picture> and
 *                                                           a media-scoped
 *                                                           <source>, and a
 *                                                           preload per
 *                                                           breakpoint
 *   resources/views/admin/partials/banners-screen.blade.php the second picker,
 *                                                           the second
 *                                                           thumbnail and the
 *                                                           warning
 *
 * THE FAILURE MODE IF THIS DOES NOT RUN IS THE WORST-SHAPED ONE THERE IS, and
 * it is why this migration is not optional. The two templates are compiled
 * SEPARATELY and cached SEPARATELY, so a server that kept one and not the other
 * gets:
 *
 *   old console + new slider   the columns exist, the storefront reads them,
 *                              and there is no way in the admin to put anything
 *                              in them. The owner is told his banner supports a
 *                              phone picture and cannot find the control.
 *   new console + old slider   he uploads a phone picture, the console shows it
 *                              saved, and his phone goes on drawing the cropped
 *                              wide one. He would report that as "the phone
 *                              picture does not work", and it is a stale cache.
 *
 * Neither looks like a stale cache, and the second would send somebody hunting
 * through code that is correct.
 *
 * NO CACHE KEY IS DROPPED HERE, and that is deliberate rather than an omission.
 * This round writes no setting and no module toggle: the three new columns live
 * on `banner_cards`, which `Banners::load()` reads with a direct query on every
 * request and never caches. The migration that DID write settings —
 * `banner_ships_as_image_slider` — has its own clear beside it.
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
            echo "Cleared {$cleared} compiled files. Appearance -> Banners now has a second\n"
                ."picture on every slide of a picture slider: the wide one at 1920 x 550 and\n"
                ."a phone one at 500 x 600, side by side on the row. Nothing on the shop\n"
                ."moved -- a slide with no phone picture draws exactly what it drew.\n";
        }
    }

    public function down(): void {}
};
