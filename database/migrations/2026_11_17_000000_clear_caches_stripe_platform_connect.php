<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the one-click Stripe Connect package (Lane FG).
 *
 *   - ROUTES. routes/payments-connect-platform.php adds
 *     GET /admin-api/payments/stripe/connect/platform, and routes/web.php
 *     requires it inside the existing admin-api group. A compiled route table
 *     on the live host does not contain it, and the failure is the quiet kind
 *     this project has already shipped twice: the Connect application panel
 *     renders perfectly, its guide request 404s, and the owner reads that as
 *     "the automatic configuration is still not there" — which is the exact
 *     sentence this package exists to stop him having to write a third time.
 *
 *   - VIEWS. resources/views/admin/app.blade.php gains the Connect application
 *     panel and the popup handling in the same package
 *     (docs/FG-ADMIN-APP-BLOCKS.md). Compiled Blade is keyed by the view's
 *     PATH and never by its contents, and its freshness check is a filemtime
 *     comparison — an update package is an unzip, so the timestamps it lands
 *     are whatever the archive carried and are not reliably newer than a
 *     compiled file the running site wrote. A stale copy of a file that
 *     already exists is precisely the case that does not self-correct, and it
 *     would paint the OLD panel — one with no way to enter a client id — over
 *     the new endpoints.
 *
 *   - OPCACHE. App\Support\StripeConnectConsole is a new class;
 *     App\Services\Payments\StripeConnect and
 *     App\Http\Controllers\Admin\StripeConnectController have both changed, and
 *     the change to the controller is what reads the OAuth state. A worker
 *     still holding the old compiled controller beside the new service would
 *     write an array into the session key and read a string back out of it,
 *     which fails the state check and refuses every connection with a message
 *     about the window not matching.
 *
 * NO SCHEMA CHANGE. The platform application's client ids and secret keys live
 * inside `payment_providers.config` for `stripe`, which is an existing column
 * the model already casts `encrypted:array`. Nothing is added to `settings` —
 * /api/settings is public, and CLAUDE.md records that group as having leaked
 * three times.
 *
 * Best-effort throughout, like every other clear_caches migration in this set:
 * a file that cannot be unlinked mid-update must not fail the package and
 * strand the site half-updated. A stale cache is a visible bug; a failed
 * migration is an outage.
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
            echo "Cleared {$cleared} compiled files; /admin-api/payments/stripe/connect/platform\n";
            echo "is now reachable and the console repaints with the Connect application panel.\n";
        }
    }

    /**
     * Nothing to undo. Deleting a cache is not a change to reverse, and a down()
     * that rebuilt one would rebuild it from the code that is live at the moment
     * of the rollback — which is the state the rollback is leaving.
     */
    public function down(): void {}
};
