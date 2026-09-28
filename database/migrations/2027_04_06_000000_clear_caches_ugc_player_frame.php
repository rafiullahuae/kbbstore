<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code cache for the popup's frame and the clips screen's diagnosis.
 *
 * NO ROUTE IS ADDED. This exists for the compiled VIEWS and for OPcache.
 *
 * ── THE VIEWS ARE THE HALF THE OWNER CAN SEE ───────────────────────────────
 *
 * The whole of the popup fix is CSS and markup in two Blade files —
 * resources/views/ugc/assets.blade.php and resources/views/ugc/rail.blade.php —
 * and storage/framework/views keys a compiled view by its source path and
 * decides staleness on file times. An unzip's timestamps are not reliably newer
 * than what is already on disk, so re-shipping the sources can do nothing at
 * all: the server keeps rendering the old compiled copy, the popup keeps its
 * black bands, and the package reports itself applied. Deleting the compiled
 * copies is what makes the new source take effect. The same is true of
 * resources/views/admin/partials/ugc-library-screen.blade.php, which is where
 * the new chip and the remedy note live.
 *
 * ── AND OPCACHE, FOR THE TWO PHP FILES THAT ARE ALREADY THERE ──────────────
 *
 * App\Services\UgcTranscoder and Admin\UgcVideoController both change body, and
 * they change TOGETHER: the controller's payload calls the transcoder's new
 * reason(). OPcache keys a compiled script by path and revalidates on mtime, so
 * a shop that kept the old compiled controller against the new transcoder would
 * send no `reason` at all — and the clips screen would fall back to its neutral
 * words and say nothing useful, silently, on the one screen this release is
 * about.
 *
 * ── WHAT THIS PACKAGE CHANGES ──────────────────────────────────────────────
 *
 * The opened player is now the shape of the clip. It used to be sized to the
 * viewport with the video fitted inside it, which put a black band above and
 * below the picture — 75px each at phone width — and left the product boxes and
 * the creator's credit sitting on that band instead of on the video. The frame
 * is now built from the clip's own stored width and height, so the video fills
 * it edge to edge and everything else is over the video, which is what was
 * agreed. It also holds the keyboard now: Escape closes it, Tab cycles inside
 * it instead of walking onto the page behind, and focus returns to the tile.
 *
 * And Content -> Shoppable video -> All clips now tells the truth about this
 * server. Its chip used to read "No ffmpeg here" on every box that could not cut
 * a teaser, including this one — where ffmpeg IS installed and PHP is not
 * allowed to start it. It says which now, and where that is the case it carries
 * one note: that every tile loops regardless, what a cut teaser actually saves,
 * and the single cron line that makes the covers and teasers cut themselves.
 *
 * NO SETTING IS ADDED, no default is changed, no row is written by this
 * migration, and nothing a shopper sees on a page without a video rail is
 * touched. StorefrontEnglishUnchangedTest does not move.
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
            echo "Cleared {$cleared} compiled files. Tapping a clip now opens a popup that is\n"
                ."the SHAPE OF THE VIDEO: no black bands above or below it, and the product\n"
                ."boxes and the creator's credit sit ON the video at the bottom instead of\n"
                ."outside it. Escape closes it, the keyboard stays inside it, and it works the\n"
                ."same in Arabic. Nothing about the rail itself moved.\n"
                ."Content -> Shoppable video -> All clips also stops guessing about this\n"
                ."server: where the 2.5-second teaser cannot be cut during an upload it now\n"
                ."says WHICH of the two reasons it is, notes that every tile loops anyway, and\n"
                ."gives the one cron line that makes the covers and teasers cut themselves\n"
                ."within a minute of an upload. No setting changed, and nothing on a page\n"
                ."without a video rail moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
