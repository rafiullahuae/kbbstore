<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the payment-preflight package.
 *
 *   - ROUTES. routes/payments-preflight.php adds
 *     GET /admin-api/payments/preflight and
 *     GET /admin-api/payments/preflight/{gateway}. A compiled route cache
 *     knows neither, and the failure is the quiet kind that has already
 *     shipped twice on this project: the payments screen renders the "Check
 *     this setup" button perfectly and every click 404s, which reads as a
 *     broken button rather than as routes that were never loaded.
 *
 *   - VIEWS. The button and its panel live in
 *     resources/views/admin/app.blade.php. Compiled Blade is keyed by path, so
 *     a stale copy of a file that already exists is exactly the case that does
 *     not self-correct.
 *
 * OPcache too. App\Services\Payments\GatewayPreflight and
 * App\Http\Controllers\Admin\PaymentPreflightController are both new classes,
 * and a worker still holding a compiled copy of the container's class map
 * would fail to resolve them.
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
