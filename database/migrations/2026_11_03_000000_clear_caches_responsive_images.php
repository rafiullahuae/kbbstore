<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for responsive product photographs — Lane DK.
 *
 * TWO NEW ROUTES, so the route cache is the point of this file and not a
 * precaution. GET /admin-api/media/image-sizes and POST
 * /admin-api/media/image-sizes/run do not exist until bootstrap/cache/routes-*.php
 * is gone: the router dispatches against the compiled table, not against
 * routes/web.php, so the package can land complete and the Media Library's
 * "Make phone-sized copies" button still 404s.
 *
 * AND A COMPILED BLADE, which is the half that decides whether a shopper sees
 * any of this. The tile's srcset lives in components/product-card.blade.php,
 * and storage/framework/views holds a compiled copy of it per view. Leave those
 * and the shop goes on serving 1000x1000 photographs to phones out of a
 * compiled template that predates the change, with nothing in the admin to
 * suggest anything is wrong.
 *
 * What changed:
 *
 *   resources/views/components/product-card.blade.php
 *                                     the tile photograph gains srcset and
 *                                     sizes when — and only when — a smaller
 *                                     copy of it is on disk. No width/height,
 *                                     unchanged: .ph is a fixed 180px box at a
 *                                     fractional grid width and the <img> is
 *                                     out of flow inside it, so there is no
 *                                     honest intrinsic ratio to state and
 *                                     layout never asks the file for one.
 *
 *   app/Support/ImageVariants.php     new. Makes the copies, and builds the
 *                                     srcset from what is on the disk rather
 *                                     than from what a column believes.
 *
 *   app/Http/Controllers/Admin/MediaUploadController.php
 *                                     every new upload gets its copies in the
 *                                     same request, best-effort, because this
 *                                     host has no worker to do it later.
 *
 *   app/Http/Controllers/Admin/ImageSizesApiController.php
 *   routes/image-sizes-admin.php      new. The bounded batch that gives the
 *                                     photographs already in the shop the same
 *                                     copies, since there is no shell to run a
 *                                     command with.
 *
 *   resources/views/admin/partials/media-library-screen.blade.php
 *                                     the button that drives it and the tally
 *                                     beside it.
 *
 *   app/Console/Commands/BuildPackage.php
 *                                     public/img-cache/ on NEVER_SHIP.
 *
 * OPcache matters here as much as the view cache: on a host with no shell the
 * PHP a package writes is not the PHP the server runs until OPcache lets go of
 * the old copy. That is the standing reason behind the withdrawn packages
 * 2.60.102-.106 in CLAUDE.md.
 *
 * NOTHING IS GENERATED HERE. A migration that resized the catalogue would run
 * for minutes inside the updater's own request, on a host whose PHP is killed
 * at thirty seconds, and would leave the update half-applied with no way to
 * retry it. The copies are made by the upload path and by the owner's batch,
 * both of which can stop and be resumed. The storefront needs neither to be
 * correct: a photograph with no copy emits no srcset and loads exactly what it
 * loads today.
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
