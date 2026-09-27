<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Routes and views for "every upload joins the Media Library".
 *
 * A ROUTE IS ADDED, which is why the compiled route table has to go rather than
 * merely ought to: POST /admin-api/ugc-sections/{id}/upload, in
 * routes/ugc-admin.php — a file routes/web.php already requires, so nothing else
 * is wired. A route added by a package does NOTHING until the compiled table is
 * gone; the "Upload a new video" control would post to a 404 and the panel would
 * say the upload did not reach the server.
 *
 * TWO SCREENS CHANGE TOO. resources/views/admin/partials/ugc-sections-screen and
 * media-library-screen. `storage/framework/views` keys a compiled view by the
 * PATH of its source and decides staleness on file times, and an unzip's
 * timestamps are not reliably newer than what is on disk — so a shop that kept
 * its compiled console would apply this package, report it applied, and go on
 * rendering a card head with no upload control in it and a grid that draws
 * `<img>` at an mp4.
 *
 * ── WHAT IT MOVES, STATED RATHER THAN BURIED ────────────────────────────────
 *
 * NO SETTING IS ADDED AND NO DEFAULT CHANGED. Nothing a shopper sees moves at
 * all: StorefrontEnglishUnchangedTest does not move, and neither does any tile,
 * rail or page — every change is in the admin console and in a table the
 * storefront does not read.
 *
 * Three things an OPERATOR sees do move, all of them asked for:
 *
 *   1. Content → Media Library now lists shoppable-video clips and teasers,
 *      which it never has. That is the owner's request in his own words —
 *      "whenever we upload any media, it should go to Media also" — and the
 *      accompanying backfill migration is what makes the existing files appear
 *      rather than only the next one.
 *   2. Content → Shoppable video → Sections → (a section) grows an "Upload a
 *      new video" control in the top-right of the "Add from the library" card.
 *   3. Deleting a shoppable-video file from the Media Library is now REFUSED
 *      rather than allowed. That is a fix, not a restriction: MediaUsage walks
 *      products, brands and categories and has never looked at `ugc_videos`, so
 *      that screen has been offering to delete the poster of a live clip and
 *      calling it "safe to delete" since posters were first catalogued.
 *
 * The shared image picker is UNCHANGED, deliberately and by construction: GET
 * /admin-api/media still excludes video unless asked, so every field that opens
 * window.kbbPickMedia sees exactly the rows it saw before.
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
            echo "Cleared {$cleared} compiled files. Every upload in the back office now joins the\n"
                ."Media Library, videos included, and a section page can take a new video directly:\n"
                ."Content -> Shoppable video -> Sections -> open one -> top right of \"Add from the\n"
                ."library\". No setting changed and nothing on the storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
