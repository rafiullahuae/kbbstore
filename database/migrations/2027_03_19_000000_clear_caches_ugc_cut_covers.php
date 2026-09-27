<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code cache for the shared derive-column writer.
 *
 * NO ROUTE IS ADDED and NO VIEW CHANGES, so neither the route cache nor the
 * compiled views are the reason this exists. **OPcache is.**
 *
 * This package rewrites the bodies of two files that are already on the server
 * — Admin\UgcVideoController and Services\UgcClipIntake — to call the new
 * Services\UgcDerivedFiles instead of carrying their own copy of the same
 * sixteen assignments. OPcache keys a compiled script by path and revalidates
 * on mtime, and an unzip's timestamps are not reliably newer than what is
 * already there. A shop that kept the old compiled copies would run the OLD
 * bodies against the NEW class file: the controller would still work (it never
 * stopped), but the drift this release fixes would still be there, and the
 * package would have reported itself applied.
 *
 * The new files (the command, the writer) are not the risk — OPcache cannot
 * hold a stale copy of a file it has never seen. The risk is entirely in the
 * two it has.
 *
 * ── WHAT THIS PACKAGE CHANGES ──────────────────────────────────────────────
 *
 * `php artisan ugc:cut-covers` becomes available over SSH. It cuts the cover
 * and the 2.5-second teaser for clips that have a video and no cover, oldest
 * first, and it exists because this shop's PHP-FPM pool disables `proc_open`
 * while its CLI does not — so the covers that the browser cannot cut are
 * reachable from the command line with no server change at all.
 *
 * One bug fixed on the way: the clip-intake path never released the poster it
 * replaced, so a re-cut cover left the old file pinned in the Media Library
 * permanently. All three call sites now go through one writer that does.
 *
 * No setting added, no default changed, no row written by this migration, and
 * nothing a shopper sees is touched. StorefrontEnglishUnchangedTest does not
 * move. Nothing on the shop changes until the command is run by hand.
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
            echo "Cleared {$cleared} compiled files. `php artisan ugc:cut-covers` is now\n"
                ."available over SSH: it cuts the cover and the 2.5s teaser for every clip\n"
                ."that has a video and no cover, oldest first, and it works on this host\n"
                ."even though the browser cannot, because the CLI is not the PHP that has\n"
                ."proc_open switched off. Run it with --dry-run first to see the list.\n"
                ."Also fixed: re-cutting a cover no longer leaves the old poster stuck in\n"
                ."the Media Library. No setting changed and nothing on the storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
