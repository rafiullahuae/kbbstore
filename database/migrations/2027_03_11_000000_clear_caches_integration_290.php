<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Views and config for 2.60.290.
 *
 * NO ROUTE IS ADDED. Every change is a screen or a shared partial, and one of
 * them is NEW — resources/views/admin/partials/upload-kit.blade.php — which is
 * why the view cache has to go rather than merely ought to.
 * `storage/framework/views` keys a compiled view by the PATH of its source and
 * decides staleness on file times, and an unzip's timestamps are not reliably
 * newer than what is on disk. A shop that kept its compiled console would apply
 * this package, report it applied, and go on showing a progress bar that sits
 * motionless for five seconds at a time with no speed beside it.
 *
 * ── IT MOVES NOTHING A SHOPPER SEES, AND NOTHING AN OPERATOR SAVED ──────────
 *
 * No setting added, no default changed, no row written. Two printed numbers
 * change — "Up to 64MB" on the sections screen and "4 MB" on the review
 * importer — and neither was a limit this shop could honour on the server it
 * runs on. StorefrontEnglishUnchangedTest does not move.
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
            echo "Cleared {$cleared} compiled files. Every upload in the console now has a live\n"
                ."bar with a real speed and a time remaining, and a drop zone: Shoppable video,\n"
                ."the image picker, the product editor and the review importer. A file dropped\n"
                ."beside a box no longer opens in the browser and throws away the form you were\n"
                ."filling in. No setting changed and nothing on the storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
