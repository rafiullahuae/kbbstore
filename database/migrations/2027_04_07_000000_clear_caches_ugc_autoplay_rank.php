<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code and rail caches for the autoplay fix and the player's arrows.
 *
 * NO ROUTE IS ADDED. This exists for the compiled VIEWS, for the RAIL CACHE and
 * for OPcache — and the second of those is new in this package and is the one a
 * copied-in file list would miss.
 *
 * ── THE RAIL CACHE, WHICH NO EARLIER UGC PACKAGE HAD TO CLEAR ──────────────
 *
 * App\Services\UgcRail caches a section's tiles for ten minutes, as an array of
 * scalars. This release adds a KEY to that array — `demo`, from
 * App\Services\Ugc\Tile, which is how the storefront knows a placeholder from a
 * clip the owner uploaded and therefore the whole of the fix. A cache entry
 * written by the old build has no such key, so for up to ten minutes after the
 * package is applied the rail would go on ranking every tile the same and the
 * owner would refresh, see no change, and report it a fourth time. The template
 * reads `$tile['demo'] ?? false`, so a stale entry is SAFE — it is not safe to
 * leave it there and call the package applied.
 *
 * ── THE VIEWS ARE THE HALF HE CAN SEE ──────────────────────────────────────
 *
 * The rail's arbiter, the play disc's honesty, the previous/next arrows and the
 * square product thumbnail are all CSS and script inside
 * resources/views/ugc/assets.blade.php and resources/views/ugc/rail.blade.php.
 * storage/framework/views keys a compiled view by its source path and decides
 * staleness on file times, and an unzip's timestamps are not reliably newer
 * than what is already on disk — so re-shipping the sources can do nothing at
 * all and the package still reports itself applied.
 * resources/views/admin/partials/ugc-appearance-screen.blade.php is in the same
 * position: the new "what your shop will do right now" panel lives there.
 *
 * ── AND OPCACHE, FOR THE PHP THAT MOVED TOGETHER ───────────────────────────
 *
 * App\Services\Ugc\Tile, App\Services\Ugc\RailPlayback (new),
 * App\Support\UgcDemoMedia and Admin\UgcAppearanceController change as a set:
 * the controller's payload calls the new service, the service compares against
 * the new constant, and Tile prints the flag the storefront ranks on. A shop
 * that kept an old compiled controller against the new service would answer the
 * appearance endpoint with no `playback` block at all, and the screen would
 * quietly draw no panel — which is the exact silence this release is about.
 *
 * ── WHAT THIS PACKAGE CHANGES ──────────────────────────────────────────────
 *
 * NO SETTING MOVES. Every control keeps the value it has, and a shop with no
 * video rail on any page is byte-identical.
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

        /*
         * The rail's own cache, through the service that owns it rather than by
         * emptying the store: flush() removes only the keys UgcRail created —
         * its own docblock says so — and this migration runs on a live shop
         * whose cart sessions and settings are in the same store.
         */
        try {
            app(\App\Services\UgcRail::class)->flush();
            \App\Support\Shortcodes::flush();
        } catch (\Throwable $e) {
            // A cache that cannot be reached is a cache that will expire on its
            // own in ten minutes. It must never fail an update — see the
            // swallowed-exception landmine in CLAUDE.md for the other half of
            // that rule: this catch leaves no state behind and re-sends nothing.
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files. The video tiles now loop on their own\n"
                ."for the clips YOU uploaded. They were losing every playback slot to the\n"
                ."(Demo) clips, because the rail filled its slots in the order the clips\n"
                ."appear and the demo ones were imported first: your own two sat at the end\n"
                ."showing a play button. Your clips are now preferred over demo footage, a\n"
                ."tile whose file is missing gives its slot back instead of keeping it for\n"
                ."ever, and the play button only disappears once a tile is really playing.\n"
                ."Content -> Shoppable video -> Appearance -> Motion now SAYS what the shop\n"
                ."will do: how many tiles will move, which will not and why, and what to\n"
                ."change.\n"
                ."The opened player also gets previous/next arrows (keyboard arrows too,\n"
                ."mirrored in Arabic, greyed out at the first and last clip), and its\n"
                ."product thumbnail is square again instead of a stretched rectangle, which\n"
                ."gives the product name about 85px more room.\n"
                ."No setting changed. A page with no video rail on it is unchanged.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
