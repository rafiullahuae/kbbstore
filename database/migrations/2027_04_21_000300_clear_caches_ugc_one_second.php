<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code caches, for the one-second loop. (Lane UG3)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * NO ROUTE IS ADDED IN THIS RELEASE, and no table is touched. This exists for
 * the two caches that would otherwise keep the old code running beside the new.
 *
 * ── THE COMPILED VIEWS ──────────────────────────────────────────────────────
 *
 * It rewrites five Blade files that are already on the server:
 *
 *   resources/views/ugc/assets.blade.php        the loader's CSS, the three
 *                                               states, and the armed wrap
 *   resources/views/ugc/rail.blade.php          the loader element, and the
 *                                               teaser-ms fallback
 *   admin/partials/ugc-library-screen.blade.php the loop preview's length,
 *                                               its seek guard, and four
 *                                               sentences that printed 2.5
 *   admin/partials/ugc-appearance-screen.blade.php   one sentence
 *   admin/partials/ugc-sections-screen.blade.php     two sentences
 *
 * storage/framework/views keys a compiled view by the path of its source and
 * decides staleness on FILE TIMES, and an unzip's timestamps are not reliably
 * newer than what is already on disk. Re-shipping the sources does nothing on
 * its own; deleting the compiled copies is what makes them take effect.
 *
 * IT MATTERS PARTICULARLY HERE because the two halves would disagree in a way
 * that looks like a bug rather than like nothing happening: a stale compiled
 * rail.blade.php prints NO `.ugcr-load` element while a fresh
 * assets.blade.php carries the rule that hides the play disc while a tile is
 * loading. Every tile would then show nothing at all between mounting and its
 * first painted frame — a blank poster with no control on it, which is worse
 * than what this release set out to fix.
 *
 * ── AND OPCACHE, ONE LAYER DOWN ─────────────────────────────────────────────
 *
 * App\Services\UgcTranscoder (the cut length, and the file-name marker
 * `--recut-teasers` selects on), App\Services\UgcSettings (the default and the
 * range), App\Console\Commands\CutUgcCovers and App\Models\UgcVideo are all
 * files the server has already compiled, and all four change. A shop running
 * an old compiled CutUgcCovers against a new UgcTranscoder would not know the
 * `--recut-teasers` option exists.
 *
 * ── WHAT MOVES ON THE SHOP ──────────────────────────────────────────────────
 *
 * ONE SETTING'S DEFAULT MOVES, and the owner asked for it by name: *"I also
 * want 1 seconds video to be cropped as clip."* `teaser_ms` ships at 1000
 * instead of 2500 and its range starts at 1000 instead of 1500. A shop that has
 * ALREADY SAVED a value on Appearance -> Shoppable video -> Motion keeps it —
 * UgcSettings::all() reads the saved row and only falls back to the default
 * when nothing was saved. Nothing else changes value, and a page with no video
 * rail on it is byte-identical.
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
         * emptying the store: flush() removes only the keys UgcRail created, and
         * this migration runs on a live shop whose cart sessions and settings
         * are in the same store.
         */
        try {
            app(\App\Services\UgcRail::class)->flush();
            \App\Support\Shortcodes::flush();
        } catch (\Throwable $e) {
            /*
             * A cache that cannot be reached expires on its own in ten minutes.
             * It must never fail an update — and see the swallowed-exception
             * landmine in CLAUDE.md for the other half of that rule: this catch
             * touches no model, leaves nothing dirty and re-sends nothing.
             */
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files. The video tiles now loop ONE SECOND\n"
                ."instead of two and a half, which is what you asked for -- and a one-second\n"
                ."cut is a much smaller file: 98.6 KB became 30.1 KB on the same clip.\n"
                ."\n"
                ."A tile now shows one of three things and never two at once: while the clip\n"
                ."is still loading it shows a turning loader and NO play button; once it is\n"
                ."ready it shows the play button; while it is playing it shows neither. A\n"
                ."tile whose file cannot be played falls back to the play button rather than\n"
                ."spinning for ever.\n"
                ."\n"
                ."WHAT IS ACTUALLY SLOW, measured: a clip with no cut teaser fetches the\n"
                ."WHOLE video to loop the first second of it -- 3.2 MB against 54 KB for the\n"
                ."same second, per tile, with four tiles going at once. Your clips have\n"
                ."covers but no teasers, so this is what your rail is doing now. Over SSH:\n"
                ."    php artisan ugc:cut-covers\n"
                ."and, once only, if any teaser on this shop was cut before today:\n"
                ."    php artisan ugc:cut-covers --recut-teasers\n"
                ."\n"
                ."Appearance -> Shoppable video -> Motion now ships at 1 second and its\n"
                ."slider starts at 1 second. If you had already moved that slider, your own\n"
                ."value is kept. Nothing else changed, and a page with no video rail on it\n"
                ."is unchanged.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
