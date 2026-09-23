<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled views for 2.60.246.
 *
 * WHAT IS STALE IF THIS DOES NOT RUN: the checkout's layout.
 *
 * store/checkout.blade.php carried a stray closing tag, which the browser
 * recovered from by closing the two-column grid early — so the summary fell
 * out of it, desktop lost its second column and the mobile summary dropped to
 * the foot of the page. A compiled copy of the broken file does exactly that,
 * whatever the corrected source says.
 *
 * Compiled Blade is keyed by the view's PATH, never by its contents, and its
 * freshness check is a filemtime comparison. An update package is an unzip, so
 * the timestamps it lands are whatever the archive carried and are not reliably
 * newer than the compiled copy the running site wrote. A stale copy of a file
 * that already exists is exactly the case that does not self-correct — and it
 * is how 2.60.102-.106 shipped inert.
 *
 * NO SCHEMA CHANGE. The addresses this package files an order against are the
 * ones the cart page already kept: a signed-out shopper's three in the session,
 * a signed-in one's in `addresses`, both reached through the /cart/address
 * endpoints that have existed since the cart's sheet shipped. Nothing is
 * migrated because nothing moved.
 *
 * NO NEW ROUTE either. The gate on those endpoints widened — it read the CART
 * page's layout, and the checkout does not care what that is set to — but the
 * routes themselves are unchanged.
 *
 * Best-effort, like every clear_caches migration in this set: a file that
 * cannot be unlinked mid-update must not fail the package and strand the site
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
            echo "Cleared {$cleared} compiled files; the checkout has its two columns back and\n";
            echo "the summary sits at the top again on a phone.\n";
        }
    }

    public function down(): void {}
};
