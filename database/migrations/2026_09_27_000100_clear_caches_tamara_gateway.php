<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Tamara gateway package.
 *
 *   - ROUTES. routes/payments-tamara.php adds five paths — three under
 *     /admin-api/payments/tamara and one POST /admin-api/orders/{order}/void.
 *     A compiled route cache on the live host knows none of them, and the
 *     failure is the quiet kind that has shipped twice on this project: the
 *     button renders perfectly and every click 404s, which reads as a broken
 *     button rather than as routes that were never loaded.
 *
 *   - OPCACHE, and this package needs it more than most. It does not only add
 *     classes: it CHANGES three that already exist.
 *
 *       App\Services\Payments\Gateways\TamaraGateway now implements a fourth
 *       interface (VoidsAuthorisation). A worker holding a compiled copy of the
 *       old class would resolve it, fail the instanceof in PaymentVoider, and
 *       report "this payment method does not hold a releasable authorisation" —
 *       a wrong answer rather than an error, which is the worst shape a stale
 *       cache can take.
 *
 *       App\Services\Payments\Gateways\RemoteGateway gains the DELETE verb.
 *       Without it, removing a Tamara webhook registration would silently POST
 *       to /webhooks/{id} instead, because that is what the `default` arm of
 *       that match does.
 *
 *       App\Models\Order gains the `voided_at` datetime cast. A stale copy
 *       returns a raw string where PaymentVoider::status() calls
 *       optional(...)->toAtomString() on it.
 *
 * Runs after 2026_09_27_000000_add_authorisation_void_tracking, which adds the
 * two columns those classes write.
 *
 * VIEWS are cleared too. This package ships no Blade change — the Tamara
 * settings it adds are text fields that the existing payments screen already
 * renders from configSchema(), which is why it needed no edit to
 * resources/views/admin/app.blade.php — but the view cache is keyed by path and
 * clearing it costs one recompile.
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
