<?php

use Illuminate\Database\Migrations\Migration;

/**
 * The integration's own clear, and it exists for one reason the four lane
 * clears in this package cannot cover.
 *
 * Each lane shipped a `clear_caches_*` migration for its own routes and views,
 * and those are correct. What none of them knows about is the work done WHEN
 * THEY WERE MERGED, in files no lane owns:
 *
 *   THE ROUTE TABLE, AGAIN, FOR A FILE NO LANE WROTE.
 *   `routes/payments-void.php` is new and is required from `routes/web.php`.
 *   Two payments lanes each registered POST /admin-api/orders/{id}/void in
 *   their own gateway's route file; mounted together Laravel keeps only the
 *   last, so the release endpoint moved into one provider-agnostic file. That
 *   file is invisible to every lane's own clear, and a compiled route table
 *   that predates it answers 404 to both verbs while the button on the order
 *   screen renders perfectly.
 *
 *   THE COMPILED CONSOLE. `resources/views/admin/app.blade.php` gained eleven
 *   TITLES rows and eleven ids in LATE_RENDERED, which is what gives eleven
 *   screens a working URL. `storage/framework/views` caches a compiled view by
 *   the PATH of its source and decides staleness on file times — and an unzip's
 *   timestamps are not reliably newer than what is already on disk, which is
 *   how a shop ends up holding a compiled copy of a template the package
 *   replaced. A stale console here means ?go=ugcvideo still opens the dashboard
 *   after the update, and the owner reports the fix as not working.
 *
 * ── IT MOVES NOTHING A SHOPPER SEES ─────────────────────────────────────────
 *
 * Nothing in this migration writes a setting and nothing in this package ships
 * a new one switched on. The two payment gateways arrive with empty credential
 * boxes and every new control at the value its page already had;
 * StorefrontEnglishUnchangedTest is the instrument and it does not move.
 *
 * Dated after every lane's own clear so it runs LAST. A clear that runs before
 * a later migration rebuilds the cache it just dropped is a clear that did
 * nothing, and migration order is filename order.
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
            echo "Cleared {$cleared} compiled files. Tamara and Tabby can both now release an\n"
                ."uncaptured authorisation from the order screen, through one endpoint rather\n"
                ."than two. Eleven admin screens have a working URL for the first time -- a link\n"
                ."to Shoppable video, Instagram, Security, Cache, Build my routine, Site layout,\n"
                ."Footer, Cart page or Checkout page used to open the Dashboard. No setting\n"
                ."changed and nothing on the storefront moved.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
