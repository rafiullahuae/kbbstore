<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Views and config for the upload kit.
 *
 * NO ROUTE IS ADDED by this package. One controller method gains a key in its
 * JSON — UgcVideoController::show() now returns `limits`, the same block index()
 * has carried since App\Support\ServerUploadLimits was written — and everything
 * else is a screen. The route cache is dropped with the rest anyway, because it
 * costs one rebuild and the alternative is a shop running on a compiled table
 * nobody has confirmed matches the source.
 *
 * ── THE COMPILED VIEWS ARE THE WHOLE POINT ──────────────────────────────────
 *
 * A NEW PARTIAL ARRIVES IN THIS PACKAGE — resources/views/admin/partials/
 * upload-kit.blade.php — and two screens start calling the two globals it
 * defines. `storage/framework/views` caches a compiled view by the PATH of its
 * source and decides staleness by comparing file times, and an unzip's
 * timestamps are not reliably newer than what is already on disk. A shop that
 * kept its compiled copy of app.blade.php would apply this package, report it
 * applied, and then run two screens whose uploaders call window.kbbUpload —
 * which the stale compiled page never included. Every upload in the console
 * would fail with "kbbUpload is not a function" until somebody cleared a cache
 * nobody had been told about.
 *
 * ── WHAT THE OWNER GETS OUT OF IT ───────────────────────────────────────────
 *
 * The bar that appeared to stick at 72% now says how fast the file is moving and
 * roughly how long is left, so a slow upload can be told apart from a stopped
 * one — which was the actual complaint. Nothing about the upload got faster,
 * because nothing client-side can make bytes move faster; what changed is that
 * the screen stopped being unable to answer the question.
 *
 * ── IT MOVES NOTHING A SHOPPER SEES, AND NOTHING AN OPERATOR SAVED ──────────
 *
 * No setting is added, no default is changed, no row is written, no migration
 * here touches a table. The upload ceiling the sections screen starts printing is
 * not a new limit — it is the limit PHP has been enforcing all along, said out
 * loud in the one place that was still promising 64 MB.
 * StorefrontEnglishUnchangedTest does not move.
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
            /*
             * The numbers, read from the process applying the update. This is the
             * reading that settles it: `php -i` over SSH reports the CLI ini,
             * which can differ from PHP-FPM's by a wide margin.
             */
            $upload = (string) ini_get('upload_max_filesize');
            $post = (string) ini_get('post_max_size');

            echo "Cleared {$cleared} compiled files.\n"
                ."Uploads in the console now show a real progress bar with the current speed and\n"
                ."an estimate of the time left, so a slow upload can be told from a stuck one.\n"
                ."Files can be dragged onto the Video, Poster and Short loop rows under\n"
                ."Content -> Shoppable video -> Sections -> (a section) -> (a clip) -> Files.\n"
                ."That screen also stops advertising 64 MB and prints what THIS server takes.\n"
                ."This server: upload_max_filesize = {$upload}, post_max_size = {$post}.\n"
                ."If post_max_size is the smaller of those two, IT is what caps your uploads —\n"
                ."raising it alone is what unlocks bigger clips. See docs/UPLOAD-LIMITS.md.\n"
                ."No setting changed and nothing on the storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
