<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the responsive product gallery, the brand colour
 * control and the capped product API — Lane DN.
 *
 * NO NEW ROUTES, so the route cache is cleared here as a precaution and not as
 * the point of the file. THREE COMPILED BLADES are the point, and one of them
 * decides whether the owner can see any of this at all.
 *
 * storage/framework/views holds a compiled copy of every template. Leave them
 * and the update lands complete and changes nothing a person can observe:
 *
 *   resources/views/admin/app.blade.php
 *                                     Business Details gains "Your brand
 *                                     colour" and the Save button posts it. A
 *                                     stale compiled console draws the screen
 *                                     without the field, so the owner applies
 *                                     the update, goes looking for the control
 *                                     he was told about, and does not find it.
 *
 *   resources/views/partials/product-gallery.blade.php
 *                                     the main photograph and the thumbnails
 *                                     gain srcset and sizes when — and only
 *                                     when — smaller copies of them are on
 *                                     disk. A stale compiled copy goes on
 *                                     serving the full-size original to every
 *                                     phone with nothing in the admin to
 *                                     suggest anything is wrong, which is the
 *                                     same failure Lane DK's migration was
 *                                     written for.
 *
 *   resources/views/store/product.blade.php
 *                                     the <head> preload learns to name the
 *                                     same candidate list as the <img>. This
 *                                     one is the dangerous half to leave
 *                                     behind: a NEW gallery under an OLD
 *                                     compiled head would preload the
 *                                     full-size original AND then load the
 *                                     smaller copy the <img> resolves to —
 *                                     two downloads of the page's largest
 *                                     asset, which is worse than before the
 *                                     change. The two templates have to take
 *                                     effect together.
 *
 * What else changed, none of it needing a cache clear of its own beyond the
 * OPcache reset below:
 *
 *   app/Support/ImageVariants.php     detailSrcsetFor(), detailSizesAttribute()
 *                                     and thumbSizesAttribute(). Additions
 *                                     only: srcsetFor() and sizesAttribute()
 *                                     are untouched, so the shop tiles behave
 *                                     exactly as they did.
 *
 *   resources/js/kbb/pdp.js           the gallery swap moves the srcset with
 *                                     the src. NOT COMPILED BY THIS MIGRATION
 *                                     AND NOT SHIPPED BY IT EITHER — it is a
 *                                     Vite entry, so it reaches the server as
 *                                     a built asset under public/build/ and
 *                                     this package must carry that build. Until
 *                                     it does, a tapped thumbnail on a product
 *                                     whose copies exist would leave the
 *                                     previous photograph on screen.
 *
 *   app/Http/Controllers/Admin/AdminController.php
 *                                     `brand_accent` on SETTING_RULES and a
 *                                     `hex` rule to validate it.
 *
 *   app/Http/Controllers/Api/ProductController.php
 *                                     GET /api/products stops returning the
 *                                     whole catalogue: at most 100 rows, the
 *                                     cap clamped server-side, with ?page= to
 *                                     reach the rest.
 *
 *   app/Services/PayShipRules.php     help text only — it pointed the owner at
 *                                     a screen that does not carry the setting.
 *
 * OPcache matters as much as the view cache: on a host with no shell the PHP a
 * package writes is not the PHP the server runs until OPcache lets go of the
 * old copy. That is the standing reason behind the withdrawn packages
 * 2.60.102-.106 in CLAUDE.md.
 *
 * NOTHING IS GENERATED HERE, for the reason Lane DK's migration gives: a
 * migration that resized the catalogue would run for minutes inside the
 * updater's own request on a host that kills PHP at thirty seconds. The
 * storefront needs no copy to be correct — a photograph without one emits no
 * srcset and loads exactly what it loads today.
 *
 * No schema changed. Nothing here positions a column with an AFTER clause, the
 * thing that made nine earlier migrations silent no-ops on MySQL.
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
        }
    }

    public function down(): void {}
};
