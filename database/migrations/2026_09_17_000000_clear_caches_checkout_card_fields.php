<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for the on-site card fields.
 *
 * TWO REASONS, and either one alone would make this migration necessary.
 *
 * ROUTES. This package adds `routes/checkout-card.php` with
 * `/checkout/card/paid` and `/checkout/card/abandon`. On this host the
 * compiled route table in `bootstrap/cache/routes-*.php` is what decides
 * whether a path exists at all, and it is written once and never rebuilt by an
 * unzip. Without this, both endpoints 404: the shopper's card goes through,
 * the browser cannot tell the shop about it, and the order sits `pending`
 * until the webhook rescues it — and a declined card cannot give the basket
 * back at all.
 *
 * VIEWS. The card form lives in `resources/views/partials/checkout/
 * stripe-elements.blade.php`, included from `store/checkout.blade.php`.
 * Compiled Blade is keyed by the view's PATH with a filemtime comparison for
 * freshness, and an update package is an unzip, so the timestamps it lands are
 * whatever the archive carried and are not reliably newer than the compiled
 * copy the running site wrote. A stale compiled checkout renders the previous
 * payment box — the one with no card fields in it — which is precisely the
 * symptom this package exists to fix, with the fix apparently applied.
 *
 * No schema changes.
 *
 * Best-effort, like every clear_caches migration here: a file that cannot be
 * unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the checkout now renders the card fields\n";
            echo "and /checkout/card/paid and /checkout/card/abandon resolve.\n";
        }
    }

    public function down(): void {}
};
