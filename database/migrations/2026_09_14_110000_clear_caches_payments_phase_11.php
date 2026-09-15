<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Phase 11 (payment gateways).
 *
 * This package adds routes — the four webhook endpoints under
 * /payments/webhook/* and the admin settings API under /admin-api/payments —
 * and a compiled route cache does not know about any of them. Until it is
 * cleared, every one of those URLs 404s and the gateways look broken rather
 * than unconfigured. Same convention every route-adding package here follows.
 *
 * OPcache matters as much this time: Store\CheckoutController and
 * Api\CheckoutController are both changed, and a worker holding the old
 * compiled copy will call GatewayRegistry methods that its cached class does
 * not have.
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
