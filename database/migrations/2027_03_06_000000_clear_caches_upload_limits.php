<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Views and config for the upload-limit honesty fix.
 *
 * NO ROUTE IS ADDED BY THIS PACKAGE. Everything in it is a screen, a support
 * class or a controller method; routes/ugc-admin.php is untouched and needs no
 * wiring. The route cache is dropped anyway, with the rest, because it costs one
 * rebuild and the alternative is a shop running on a compiled table nobody has
 * confirmed matches the source.
 *
 * ── THE COMPILED VIEW IS THE PART THAT MATTERS ──────────────────────────────
 *
 * resources/views/admin/partials/ugc-library-screen.blade.php changes, and
 * storage/framework/views caches a compiled view by the PATH of its source while
 * deciding staleness from file times — and an unzip's timestamps are not
 * reliably newer than what is already on disk. A shop that kept its compiled
 * copy would apply this package, report it applied, and still advertise
 * "up to 64 MB" on a server that takes 2 MB.
 *
 * ── WHAT IT CHANGES, AND WHY A NUMBER ON A SCREEN GETS SMALLER ──────────────
 *
 * The owner uploaded an 8.4 MB .mp4 at Content → Shoppable video → All clips →
 * step 2. The bar reached 100% and the screen said "That file was not accepted.
 * 8.4 MB — nothing on the clip was changed." His file was fine: this server has
 * upload_max_filesize=2M and post_max_size=8M, so PHP threw the whole request
 * body away before the application saw a byte of it, while the screen was
 * advertising the 64 MB that UgcMedia::MAX_BYTES allows.
 *
 * So step 2 now quotes what PHP will really take, and where that is smaller than
 * 64 MB it says so in a note that names both ini directives, both values and
 * where to raise them. On a server configured generously the note is not drawn
 * at all and the screen reads exactly as it did.
 *
 * ── IT MOVES NOTHING A SHOPPER SEES, AND NOTHING AN OPERATOR SAVED ──────────
 *
 * No setting is added, no default is changed, no row is written and no column
 * exists that did not. UgcMedia::MAX_BYTES is untouched — Shoppable video still
 * allows a 64 MB clip, and will accept one the day the server does.
 * StorefrontEnglishUnchangedTest does not move on this package: nothing here is
 * reachable from the storefront at all.
 *
 * Store → Store Import / Export is unchanged too. Its two ini values now come
 * through App\Support\ServerUploadLimits so there is one reader of those
 * directives rather than a second per screen, and UploadLimitsTest pins that
 * what it prints is the same text, character for character.
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
             * The live numbers, printed where the person applying the package is
             * already looking. This is the shortest honest answer to "how big a
             * video can I upload", and it is read out of the process that is
             * running rather than out of a document.
             */
            $limits = new \App\Support\ServerUploadLimits;
            $clip = $limits->describe(\App\Services\UgcMedia::MAX_BYTES[\App\Services\UgcMedia::KIND_CLIP]);

            echo "Cleared {$cleared} compiled files. Content -> Shoppable video -> All clips ->\n"
                ."step 2 now advertises the size THIS server will really accept instead of the\n"
                ."64 MB the shop allows, and says plainly when PHP is the thing capping it.\n"
                ."An upload PHP refused for its size, or discarded for being over post_max_size,\n"
                ."no longer reads as a bad file: the refusal names the directive, its value, and\n"
                ."the fact that the file itself is fine. The bar can be cancelled, a failure that\n"
                ."a second attempt could fix offers Try again, and a long wait says how long.\n"
                ."\n"
                ."On THIS server, right now:\n"
                ."  upload_max_filesize = ".$limits->raw()['upload_max_filesize']."\n"
                ."  post_max_size       = ".$limits->raw()['post_max_size']."\n"
                ."  largest clip that can really be uploaded: ".$clip['effective_label']."\n"
                ."  (Shoppable video itself allows ".$clip['app_mb']." MB"
                    .($clip['capped'] ? ", so the SERVER is the limit — see docs/UPLOAD-LIMITS.md" : "")
                .")\n"
                ."\n"
                ."No setting changed, no default moved, and nothing on the storefront was touched.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
