<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Views and config for the upload screens — Lane P2.
 *
 * NO ROUTE IS ADDED by this package. Every change in it is a screen: the shared
 * media picker, the product editor and the review CSV importer. The route cache
 * is dropped with the rest anyway, because it costs one rebuild and the
 * alternative is a shop running on a compiled table nobody has confirmed
 * matches the source.
 *
 * ── THE COMPILED VIEWS ARE THE WHOLE POINT OF THIS FILE ─────────────────────
 *
 * All three screens now print a ceiling they READ from this server at render
 * time — `ini_get('upload_max_filesize')` and `ini_get('post_max_size')`, via
 * App\Support\ServerUploadLimits — rather than the app's own cap.
 * `storage/framework/views` caches a compiled view by the PATH of its source and
 * decides staleness by comparing file times, and an unzip's timestamps are not
 * reliably newer than what is already on disk. A shop that kept its compiled
 * copies would apply this package, report it applied, and go on telling the
 * owner that a 3 MB photograph "is not a file".
 *
 * ── IT MOVES NO SETTING AND NO DEFAULT ──────────────────────────────────────
 *
 * No row is written, no setting is added, no slider moves. Two numbers printed
 * on screen DO change, and neither is a new limit:
 *
 *   Catalog → Products → Edit had never printed a size at all, and now prints
 *   the one its endpoint and this server agree on.
 *
 *   Reviews → Review Import / Export said "Up to 25,000 rows and 4 MB". 4 MB is
 *   ReviewsIoApiController::UPLOAD_MAX_KB; this box stops at 2M per file. The
 *   row limit is unchanged and still read from the server. The size is now the
 *   limit PHP has been enforcing all along, said out loud for the first time.
 *
 * StorefrontEnglishUnchangedTest does not move: nothing here is on the shop.
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
             * Read from the process applying the update, which is the reading
             * that settles it: `php -i` over SSH reports the CLI ini, and that
             * can differ from PHP-FPM's by a wide margin.
             */
            $upload = (string) ini_get('upload_max_filesize');
            $post = (string) ini_get('post_max_size');

            $limits = new \App\Support\ServerUploadLimits;
            $image = $limits->label($limits->ceiling(5 * 1024 * 1024));
            $csv = $limits->label($limits->ceiling(4096 * 1024));

            echo "Cleared {$cleared} compiled files.\n"
                ."\n"
                ."Every upload in the console now has a live progress bar fed by real bytes,\n"
                ."a drop target that covers the whole card rather than a small dashed box,\n"
                ."a Stop button while it is in flight, and a refusal that names the size and\n"
                ."the reason BEFORE the file is sent.\n"
                ."\n"
                ."  Choose an image (the shared Media Library popup) — drop images anywhere\n"
                ."      on the dialog; it had no drop target at all before.\n"
                ."  Catalog -> Products -> Edit -> Main image / Gallery / Search appearance\n"
                ."      — all three take a dropped photograph, and the share image reports\n"
                ."      progress at all for the first time.\n"
                ."  Reviews -> Review Import / Export -> Import — drop the CSV on the card.\n"
                ."\n"
                ."On THIS server, right now:\n"
                ."  upload_max_filesize = {$upload}\n"
                ."  post_max_size       = {$post}\n"
                ."  largest image these screens can really upload: {$image}\n"
                ."  largest review CSV they can really import:      {$csv}\n"
                ."Raise those two directives on the server if you want bigger files.\n"
                ."\n"
                ."No setting changed, no default moved, and nothing on the storefront was\n"
                ."touched. The two size numbers that changed on screen were never limits this\n"
                ."shop could honour.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
