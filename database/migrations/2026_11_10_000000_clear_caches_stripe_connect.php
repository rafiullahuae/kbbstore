<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Stripe connect/disconnect package.
 *
 *   - ROUTES. routes/payments-connect.php adds six paths under
 *     /admin-api/payments/stripe/. A compiled route cache on the live host
 *     knows none of them, and the failure is the quiet kind this project has
 *     already shipped twice: the Connect panel renders perfectly and every
 *     button on it 404s, which reads as a broken feature rather than as routes
 *     that were never loaded. The OAuth callback would fail the same way, after
 *     the owner had already authorised at Stripe's end.
 *
 *   - VIEWS. resources/views/admin/stripe-connected.blade.php is new, and
 *     resources/views/admin/app.blade.php gains the Connect panel in the same
 *     package. Compiled Blade is keyed by path, so a stale copy of a file that
 *     already exists is precisely the case that does not self-correct.
 *
 * OPcache too. App\Services\Payments\StripeConnect and
 * App\Http\Controllers\Admin\StripeConnectController are both new classes, and
 * App\Support\AdminCapabilities has changed — a worker still holding the old
 * compiled copy of that one would fail every new route closed, with a 403 the
 * owner cannot read his way out of.
 *
 * Nothing in the schema changes. The connection is stored in
 * `payment_providers.config`, which is an existing `encrypted:array` column;
 * the new keys live inside the blob and need no column of their own.
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
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
