<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Views and config for 2.60.288.
 *
 * NO ROUTE IS ADDED BY THIS PACKAGE, and that is worth stating rather than
 * leaving a reader to check: every change in it is a screen, a schema or a
 * helper. The route cache is dropped anyway, with the rest, because it costs
 * one rebuild and the alternative is a shop running on a compiled table nobody
 * has confirmed matches the source.
 *
 * THE COMPILED VIEWS ARE THE PART THAT MATTERS. Three admin screens change —
 * the payments card is now a two-column grid, the clip editor is laid out in
 * columns with the cut offered where an upload ends, and the header screen
 * greys a control the shop is ignoring. `storage/framework/views` caches a
 * compiled view by the PATH of its source and decides staleness by comparing
 * file times, and an unzip's timestamps are not reliably newer than what is
 * already on disk. A shop that kept its compiled copy would apply this package,
 * report it applied, and show the old screens.
 *
 * ── IT MOVES NOTHING A SHOPPER SEES, AND NOTHING AN OPERATOR SAVED ──────────
 *
 * No setting is added, no default is changed and no row is written. The one
 * field whose behaviour visibly changes — Appearance → Header → Bar → Content
 * width — is not changed at all: it was already being ignored while "Header
 * follows the site width" is on, and all that is new is the screen saying so.
 * StorefrontEnglishUnchangedTest does not move on this package.
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
            echo "Cleared {$cleared} compiled files. Store -> Ecommerce -> Payments is two columns\n"
                ."now: keys on the left, settings on the right. The clip editor is too, and it\n"
                ."offers to cut the cover and the teaser where an upload finishes. An error\n"
                ."message no longer wears a green tick. Appearance -> Header -> Bar -> Content\n"
                ."width now says it is not in use while the header is following the site width.\n"
                ."No setting changed and nothing on the storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
