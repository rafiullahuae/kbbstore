<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled caches, because this package adds routes, views and a pair
 * of event listeners (Lane EN).
 *
 * CLAUDE.md states the rule and the reason: the server has no shell, so nothing
 * can run `php artisan route:clear` there. A route added in a PHP file that
 * routes/web.php requires does not exist until the serialised route table under
 * bootstrap/cache is gone — and until then the unsubscribe link in every
 * message this package sends leads to a dead page in the shopper's inbox, which
 * is the one failure that turns an opt-out into a spam complaint.
 *
 * EIGHT NEW ROUTES:
 *
 *   POST /notify-me                          routes/outbound-public.php
 *   POST /cart/remind-me
 *   GET  /mail-preferences/{kind}/{id}/
 *   POST /mail-preferences
 *   GET  /admin-api/outbound/backlog         routes/outbound-admin.php
 *   GET  /admin-api/outbound/demand
 *   GET  /admin-api/outbound/recovery
 *   POST /admin-api/outbound/sweep
 *
 * THE COMPILED VIEWS GO TOO, and not for tidiness. This package adds four email
 * templates, two storefront pages and a product-page partial, and Blade compiles
 * to files named by a hash of the view's PATH, not its contents — so a stale
 * compiled view is served happily forever. It also EDITS
 * store/product.blade.php and store/cart.blade.php, both of which certainly
 * have compiled copies on the server already. Dropping the lot is cheaper than
 * enumerating which.
 *
 * `config.php` and `services.php` go because MailServiceProvider now registers
 * two more listeners at boot — the request tick and the order-cancels-recovery
 * hook — and a cached service manifest is how a newly registered provider
 * binding quietly does not exist.
 *
 * NO SCHEMA CHANGE HERE. The three migrations beside this one carry those, and
 * none of them uses ->after() — see tests/Feature/MigrationConventionTest.php
 * for why that matters on this host.
 *
 * Best-effort throughout, as every other clear_caches migration here is: a file
 * that cannot be unlinked mid-update must not fail the package and strand the
 * site half-updated. Anything left behind is a stale cache, which is a visible
 * bug; a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the stock-alert and basket-reminder routes are now reachable.\n";
        }
    }

    public function down(): void {}
};
