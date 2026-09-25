<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Content → Shoppable video.
 *
 * A NEW ROUTE FILE. routes/ugc-admin.php is required from routes/web.php by the
 * integrator, and the router dispatches against bootstrap/cache/routes-*.php
 * rather than against the source. Without this the screen draws and every
 * request it makes answers 404 — which the screen says out loud rather than
 * drawing an empty panel, but which is still a dead screen.
 *
 * TWO CHANGED BLADES, compiled into storage/framework/views and keyed by PATH
 * rather than by contents: the freshness check is a filemtime compare, and an
 * unzip's timestamps are not reliably newer than what is already on disk. A
 * stale copy here is an admin console with no Shoppable video row in its
 * sidebar.
 *
 * What changed:
 *
 *   database/migrations/2027_01_10_000000_create_ugc_videos.php
 *                                   `ugc_videos` and the `ugc_video_product`
 *                                   pivot. Two empty tables; nothing on the
 *                                   storefront reads either.
 *
 *   app/Models/UgcVideo.php         the row, its products relation, the
 *                                   publish gate and the public allowlist.
 *
 *   app/Services/UgcMedia.php       upload checking and where a file is
 *                                   written. Two independent readings of the
 *                                   bytes, which must agree.
 *   app/Services/UgcPath.php        the URL and stored-path sanitisers.
 *   app/Services/UgcTranscoder.php  the poster and teaser cutter, and the
 *                                   honest answer when there is no ffmpeg.
 *
 *   app/Http/Controllers/Admin/UgcVideoController.php
 *   routes/ugc-admin.php
 *   app/Support/AdminCapabilities.php
 *   resources/views/admin/partials/ugc-library-screen.blade.php
 *   resources/views/admin/app.blade.php
 *                                   Content → Shoppable video, behind the new
 *                                   `ugc.view` and `ugc.manage` capabilities.
 *
 * ── NOTHING ON THE STOREFRONT MOVES ─────────────────────────────────────────
 *
 * No storefront route, no section, no setting, no module toggle and no query on
 * any page that exists today. Applying this package changes no page the shop
 * serves: it adds two empty tables and one admin screen. UgcShipsOffTest walks
 * the shop with a published video in the table and asserts every page is what
 * it was with an empty one.
 *
 * NO ModuleRegistry ROW, DELIBERATELY. §6 puts the master on/off switch beside
 * the storefront section, and the section does not exist yet — the owner has
 * not picked a rail (§8 question 1). ModuleRegistry's own status vocabulary
 * exists precisely to stop a switch being drawn for something nothing reads,
 * and ModuleFrameworkGuardTest enforces it in both directions. The round that
 * draws a rail is the round that adds the row, as `live`, with a reader.
 *
 * NO SETTING ROWS ARE WRITTEN by this migration, and no setting is added
 * anywhere: there is nothing on this screen for a slider to move.
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
            echo "Cleared {$cleared} compiled files; the shop now has a shoppable-video\n"
                ."library under Content -> Shoppable video. Nothing on the storefront changed.\n";
        }
    }

    public function down(): void {}
};
