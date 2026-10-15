<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane CAP: the checkout email capture adds one route (POST /api/checkout-contact,
 * in routes/cart-trail.php), so the compiled route table and views are cleared,
 * as every package that adds a route does. Literals only -- nothing this
 * package adds is called here, because the updater can still be running the
 * old classes (see 2.60.512 / 2.60.513).
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
            base_path('bootstrap/cache/events.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($file, true);
                }
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_invalidate')) {
            foreach ([
                'app/Http/Controllers/Store/CheckoutEmailController.php',
                'app/Http/Controllers/Store/CheckoutController.php',
                'app/Support/CheckoutEmailCapture.php',
                'app/Services/CartRecovery.php',
                'app/Services/CartTracking/CartTrackingSettings.php',
                'app/Services/CartTracking/CartTrackingReport.php',
                'app/Services/Marketing/Audience.php',
                'app/Http/Controllers/OwnerApp/CustomersController.php',
                'app/Http/Controllers/Admin/CustomersApiController.php',
                'routes/cart-trail.php',
            ] as $relative) {
                @opcache_invalidate(base_path($relative), true);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Checkout email capture. Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
