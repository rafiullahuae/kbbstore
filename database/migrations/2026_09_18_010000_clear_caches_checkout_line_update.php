<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the in-place checkout quantity change.
 *
 * ROUTES. This package adds POST /checkout/line (routes/checkout-line.php,
 * required from routes/web.php). A compiled bootstrap/cache/routes-*.php knows
 * nothing about it, so until it is cleared every press of + or − in the order
 * summary answers 404 — and since the handler no longer reloads the page, a
 * 404 would look exactly like a dead button. Same convention every
 * route-adding package here follows.
 *
 * VIEWS. store/checkout.blade.php gained a script that publishes the endpoint's
 * URL, already prefixed for this deployment. Compiled Blade under
 * storage/framework/views is keyed by template path, so a stale compile would
 * render the page without that URL and the stepper would have nowhere to post.
 *
 * OpCache too: CheckoutController gained lineUpdate() and browsedAdd() now
 * calls a shared fragments() helper. A worker holding the previous compiled
 * copy would resolve the route to a method it does not believe exists, and the
 * Browsed one-tap add would call a helper that is not there either.
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
