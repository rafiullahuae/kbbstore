<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Views and config for 2.60.289.
 *
 * NO ROUTE IS ADDED by this package — every change in it is a screen, a service
 * or a controller. The route cache is dropped with the rest anyway, because it
 * costs one rebuild and the alternative is a shop running on a compiled table
 * nobody has confirmed matches the source.
 *
 * THE COMPILED VIEWS ARE WHAT MATTERS HERE. Two admin screens change: the clip
 * editor now reads this server's own upload limits and says what it will really
 * take, and the console's failure toasts stop wearing a success tick.
 * `storage/framework/views` caches a compiled view by the PATH of its source and
 * decides staleness by comparing file times, and an unzip's timestamps are not
 * reliably newer than what is already on disk. A shop that kept its compiled
 * copy would apply this package, report it applied, and go on telling the owner
 * his 8.4MB video was a bad file.
 *
 * ── IT MOVES NOTHING A SHOPPER SEES, AND NOTHING AN OPERATOR SAVED ──────────
 *
 * No setting is added, no default is changed, no row is written. The upload
 * ceiling this package starts reporting is not a new limit — it is the limit
 * PHP has been enforcing all along, said out loud for the first time.
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
             * The numbers, read from the process applying the update. This is
             * the reading that settles it: `php -i` over SSH reports the CLI
             * ini, which can differ from PHP-FPM's by a wide margin.
             */
            $upload = (string) ini_get('upload_max_filesize');
            $post = (string) ini_get('post_max_size');

            echo "Cleared {$cleared} compiled files. Uploading a video now says what THIS server\n"
                ."will really take, instead of promising 64 MB it cannot honour.\n"
                ."This server: upload_max_filesize = {$upload}, post_max_size = {$post}.\n"
                ."Raise those on the server if you want bigger clips.\n"
                ."Duplicating an order no longer copies the original's capture, a released\n"
                ."authorisation can no longer be captured, and a failed capture or refund no\n"
                ."longer reports itself with a green tick.\n"
                ."No setting changed and nothing on the storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
