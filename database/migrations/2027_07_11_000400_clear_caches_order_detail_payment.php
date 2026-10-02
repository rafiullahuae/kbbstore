<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Lane PU: Store -> Orders -> (an order) -- the payment panel, the "Mark as
 * paid" modal, the Billing / Shipping editor and the "Order history" popup.
 *
 * Four new routes in routes/order-detail-admin.php and a rewritten order
 * screen in resources/views/admin/app.blade.php. The compiled route cache on
 * the live host knows none of the routes and the compiled view still draws the
 * old screen, so both are cleared here -- the convention every package that
 * adds a route follows (CLAUDE.md). No schema change: every column this lane
 * writes (paid_at, transaction_id, payment_method, payment_method_title,
 * captured_at, captured_total, capture_ref, billing_address, shipping_address,
 * customer_id) already exists.
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
