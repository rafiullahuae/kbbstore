<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled views, because this package adds a Blade partial that
 * resources/views/store/checkout.blade.php now includes (Lane FI,
 * `inline_validation`).
 *
 * NO NEW ROUTES. This module adds not one endpoint: its three settings are
 * fields on the Store → Ecommerce → Checkout tab, which the console already
 * draws generically from EcommerceApiController::schema() through
 * /admin-api/ecommerce — a route that has existed for releases. So the compiled
 * route table is correct as it stands and this migration is about views only.
 *
 * WHY THE COMPILED VIEWS MUST GO, SPECIFICALLY. Blade names a compiled file by
 * a hash of the view's PATH, never its contents, and serves the compiled copy
 * until something deletes it or the source is seen to be newer. The freshness
 * check is a filemtime comparison, and an update package is an unzip: the
 * timestamps it lands are whatever the archive carried, which on this host is
 * not reliably newer than a compiled file written by the running site.
 *
 * If the stale compiled store/checkout.blade.php survives, the checkout renders
 * WITHOUT the include — so Store → Modules would show "Inline field validation"
 * as a live switch, the three controls would appear on Store → Ecommerce →
 * Checkout and save correctly, and the checkout would go on behaving exactly as
 * it did. A switch that saves and does nothing is the precise fault Phase 3's
 * status field exists to prevent, and it would arrive here by the cache rather
 * than by the code.
 *
 * NOTHING IS SEEDED, which is the whole of "off by default". No row is written
 * for `inline_validation` in `module_toggles`, and SettingsService::moduleEnabled()
 * returns the registry's default only when the row is ABSENT — so absent means
 * off, the partial renders nothing at all, and applying this package changes
 * the checkout by zero bytes. Writing a '0' here would be the same state by a
 * longer route, and it would also overwrite the choice of any store that had
 * already saved Store → Modules.
 *
 * bootstrap/cache/config.php goes with them only because a package that lands
 * new code while a compiled container describes the old one is the failure this
 * whole convention exists for, and deleting it costs one rebuild on the next
 * request.
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
            echo "Cleared {$cleared} compiled files; the checkout includes a new partial\n";
            echo "(partials/checkout/inline-validation.blade.php) and a stale compiled\n";
            echo "checkout would leave the new switch saving and doing nothing.\n";
        }
    }

    /**
     * Nothing to undo. Deleting a cache is not a change to reverse, and a down()
     * that rebuilt one would be rebuilding it from the code that is live at the
     * moment of the rollback — which is the state the rollback is leaving.
     */
    public function down(): void {}
};
