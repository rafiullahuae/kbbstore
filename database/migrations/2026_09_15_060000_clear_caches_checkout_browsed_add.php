<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the checkout Browsed one-tap add.
 *
 * ROUTES. This package adds POST /checkout/browsed-add (routes/checkout-browsed.php,
 * required from routes/web.php). A compiled bootstrap/cache/routes-*.php knows
 * nothing about it, so until it is cleared every tap on Add in the Browsed tab
 * answers 404 — and the failure would be reported on the row, in words, which
 * is honest but reads as a broken feature rather than a stale cache. Same
 * convention every route-adding package here follows.
 *
 * VIEWS. store/checkout.blade.php changed shape, and three partials are new or
 * moved: partials/checkout/payment-methods.blade.php and browsed-list.blade.php
 * did not exist before, and browsed-item.blade.php gained the attribute
 * checkout.js binds. Compiled Blade under storage/framework/views is keyed by
 * the template path, so a stale compile of checkout.blade.php would go on
 * rendering the old inline payment list — which has no partial for the refresh
 * to re-render, and no #kbbBrowsedList data-add-url for the handler to post to.
 * That is the "handler runs, nothing moves" failure this project has shipped
 * twice; clearing the compiled views is what keeps it from being a third.
 *
 * OpCache too: the controller gained a method, and a worker holding the
 * previous compiled copy of CheckoutController would resolve the route to a
 * method it does not believe exists.
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
