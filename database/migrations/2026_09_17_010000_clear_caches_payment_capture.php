<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the capture-and-refund package.
 *
 *   - ROUTES. routes/payments-settlement.php adds
 *     POST /admin-api/orders/{id}/capture and
 *     GET  /admin-api/orders/{id}/settlement. A compiled route cache knows
 *     neither, and the failure is the quiet kind: the order screen renders the
 *     Capture button perfectly and every click 404s, which reads as "the
 *     button is broken" rather than "the routes were never loaded". The same
 *     omission is what made two earlier repair packages inert.
 *
 *   - VIEWS. resources/views/admin/app.blade.php grew the capture panel and
 *     the refund form's idempotency key. Compiled Blade is keyed by path, so a
 *     stale copy of a file that already exists is exactly the case that does
 *     not self-correct: the screen keeps posting refunds with no key, and the
 *     double-click guard it was given is simply never exercised.
 *
 * OPcache too. The gateway classes gained capture() and refund() and a worker
 * still holding the previous compiled copies would see a SettlesPayments
 * implementation that does not implement it.
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
