<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the in-place checkout coupon.
 *
 * ROUTES. This package adds POST /checkout/coupon to routes/checkout-line.php.
 * A compiled bootstrap/cache/routes-*.php knows nothing about it, so until it
 * is cleared Apply answers 404 — and because the handler no longer reloads,
 * a 404 looks exactly like a dead button rather than an error.
 *
 * VIEWS. store/checkout.blade.php now publishes the endpoint's URL, already
 * prefixed for this deployment. Compiled Blade is keyed by template path, so a
 * stale compile renders the page without that URL. The JS falls back to the
 * old reload path in that case rather than breaking, but the reload is the
 * whole thing being fixed.
 *
 * OpCache too: CheckoutController gained couponUpdate() and now takes
 * CouponService as a fourth constructor argument. A worker holding the previous
 * compiled copy would resolve the route to a method it does not believe exists,
 * and would construct the controller with the old three-argument signature.
 *
 * Also carries the cart page's back-link fix: cart.blade.php rendered
 * class="back" while the stylesheet only ever defined .backlink, so
 * "Continue shopping" was unstyled. A stale compiled view keeps the old class.
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
