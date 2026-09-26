<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the shoppable-video RAIL. Phase 20, Lane V3.
 *
 * ── WHY THIS MIGRATION EXISTS AT ALL ────────────────────────────────────────
 *
 * TWO NEW PUBLIC ROUTES, in a NEW route file. routes/ugc.php is required from
 * routes/api.php, and the router dispatches against bootstrap/cache/routes-*.php
 * rather than against the source. Without this, /api/ugc/{section} and
 * /api/ugc/{slug}/like answer 404 on a shop whose route cache survived the unzip —
 * and the like button on a rail would fail silently, which is the worst shape:
 * every tap looks like a network hiccup.
 *
 * NINE NEW ADMIN ROUTES, in routes/ugc-admin.php, which was ALREADY required from
 * routes/web.php by the integrator last round — so they needed no wiring at all.
 * They still need the compiled table dropped.
 *
 * FIVE CHANGED BLADES, compiled into storage/framework/views and keyed by PATH
 * rather than by contents: the freshness check is a filemtime compare, and an
 * unzip's timestamps are not reliably newer than what is already on disk. A stale
 * copy of admin/app.blade.php here is an admin console with no Video sections row
 * and no Appearance → Video rail row in its sidebar; a stale copy of
 * resources/views/ugc/rail.blade.php is a rail rendered by last round's template,
 * which does not exist.
 *
 * ── WHAT CHANGED ────────────────────────────────────────────────────────────
 *
 *   database/migrations/2027_02_02_000000_create_ugc_sections.php
 *                                   `ugc_sections` and the `ugc_section_video`
 *                                   pivot: many rails, each with its own order,
 *                                   and one clip allowed in several of them.
 *   database/migrations/2027_02_02_000001_create_ugc_likes.php
 *                                   `ugc_videos.likes` and the one-per-browser
 *                                   ledger behind it.
 *
 *   app/Models/UgcSection.php       the rail, its handle (which IS the
 *                                   shortcode) and its clips.
 *   app/Models/UgcVideoLike.php     one like, from one browser, with no
 *                                   personal data in it at all.
 *   app/Models/UgcVideo.php         the sections relation and two more keys on
 *                                   the public allowlist.
 *
 *   app/Services/UgcSettings.php    Appearance → Video rail, on
 *                                   ModuleSchema's shared shape.
 *   app/Services/UgcRail.php        one section resolved into tiles, in FOUR
 *                                   queries whatever the rail's length, cached.
 *   app/Services/Ugc/Tile.php       one row flattened into the scalars a tile
 *                                   prints, with every path sanitised on the
 *                                   way in.
 *   app/Services/ModuleRegistry.php the `shoppable_video` row — `live`, and
 *                                   DEFAULT FALSE.
 *
 *   app/Support/Shortcodes.php      [kbb_videos section="..."], a third arm on
 *                                   the class that already had two.
 *   app/Support/AdminCapabilities.php
 *                                   the new endpoints, writes above reads.
 *   app/Services/Translation/InterfaceStrings.php
 *                                   fourteen `store.ugc.*` strings.
 *
 *   app/Http/Controllers/Api/UgcController.php          the two public endpoints
 *   app/Http/Controllers/Admin/UgcSectionController.php the sections
 *   app/Http/Controllers/Admin/UgcAppearanceController.php the settings screen
 *   routes/ugc.php, routes/api.php, routes/ugc-admin.php
 *
 *   resources/views/ugc/rail.blade.php        R3, element for element
 *   resources/views/ugc/assets.blade.php      its stylesheet and its script
 *   resources/views/ugc/likes.blade.php       the like pill, which ships off
 *   resources/views/admin/partials/ugc-sections-screen.blade.php
 *   resources/views/admin/partials/ugc-appearance-screen.blade.php
 *   resources/views/admin/app.blade.php       two @include lines
 *
 * ── APPLYING THIS CHANGES NO PAGE ───────────────────────────────────────────
 *
 * The `shoppable_video` module row ships FALSE, App\Services\UgcSettings::enabled()
 * is the only reader, and App\Support\Shortcodes::videos() returns the empty string
 * while it is off — so a page that already carries `[kbb_videos section="x"]` is
 * byte-identical. The two public routes answer 404 to everybody. No setting row and
 * no module_toggles row is written by this migration: the switch's OFF state is the
 * registry's default, not a stored value, which is what makes "applying the package
 * moves nothing" true rather than asserted. UgcShipsOffTest walks the shop and
 * checks it.
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
            echo "Cleared {$cleared} compiled files; the shop now has video SECTIONS\n"
                ."(Content -> Video sections) and a look for them (Appearance -> Video rail).\n"
                ."video). Nothing appears on the storefront until you switch Shoppable video\n"
                ."on in Store -> Modules AND paste a [kbb_videos] shortcode somewhere.\n";
        }
    }

    public function down(): void {}
};
