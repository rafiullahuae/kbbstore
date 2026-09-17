<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled admin console for the Stripe setup wizard and the CSRF fix.
 *
 * WHY THIS NEEDS ITS OWN MIGRATION AND CANNOT LEAN ON THE .205 ONE. A migration
 * runs once. `2026_11_17_000000_clear_caches_stripe_platform_connect` has either
 * already run on this host — in which case it will not run again and clears
 * nothing for this package — or it is arriving in the same zip, in which case it
 * runs BEFORE this file's own changes are guaranteed to have been considered.
 * Either way the console shipped here needs a clear of its own, and a package
 * that changes a Blade file without one is how 2.60.102-.106 shipped inert.
 *
 * WHAT IS STALE IF THIS DOES NOT RUN, and it is the whole point of the package:
 * `resources/views/admin/app.blade.php` is the only file in it. Compiled Blade is
 * keyed by the view's PATH, never by its contents, and its freshness check is a
 * filemtime comparison. An update package is an unzip, so the timestamps it lands
 * are whatever the archive carried and are not reliably newer than the compiled
 * copy the running site wrote. A stale copy of a file that already exists is
 * exactly the case that does not self-correct.
 *
 * And here the stale copy is not a cosmetic difference. It is a console whose
 * Stripe writes carry no CSRF token, so:
 *
 *   - Connect Stripe, Save Connect application and Disconnect all take a 419
 *   - because the Connect application cannot be saved, `oauth_ready` stays false
 *   - because `oauth_ready` stays false, the one-click button is never drawn
 *
 * which is the exact report this package answers. Shipping the fix and leaving
 * the old console compiled would look identical to not shipping it at all.
 *
 * NO SCHEMA CHANGE and no route change. Nothing in this package adds an endpoint;
 * the wizard posts to POST /admin-api/payments/stripe/connect, which has existed
 * since the Connect panel shipped. The route cache is cleared anyway because it
 * costs nothing and a config cache holding an old session or CSRF setting is the
 * other way a 419 survives an update.
 *
 * Best-effort, like every clear_caches migration in this set: a file that cannot
 * be unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the admin console now sends a CSRF token\n";
            echo "with every Stripe write, and Store -> Payments offers Set up Stripe.\n";
        }
    }

    public function down(): void {}
};
