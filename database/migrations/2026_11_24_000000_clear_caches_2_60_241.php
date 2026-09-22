<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled views for 2.60.241.
 *
 * WHAT IS STALE IF THIS DOES NOT RUN: the whole package. Every visible change
 * in it lives in a Blade file —
 *
 *   - store/cart-squeeze.blade.php carries the desktop stylesheet AND the
 *     breakpoint, which is interpolated into the media query by Blade rather
 *     than read from a custom property (a media query is resolved before
 *     custom properties exist). A stale compiled copy is a cart page with no
 *     `@media (min-width: …)` block at all: the desktop stays one stretched
 *     column and every control on the new Desktop tab moves nothing.
 *   - store/cart-inner.blade.php carries the one element the two-column
 *     layout needs. Without it there is no right-hand column to fill.
 *   - admin/partials/cart-page-screen.blade.php carries the Desktop tab's
 *     preview.
 *
 * Compiled Blade is keyed by the view's PATH, never by its contents, and its
 * freshness check is a filemtime comparison. An update package is an unzip, so
 * the timestamps it lands are whatever the archive carried and are not reliably
 * newer than the compiled copy the running site wrote. A stale copy of a file
 * that already exists is exactly the case that does not self-correct — and it
 * is how 2.60.102-.106 shipped inert.
 *
 * NO SCHEMA CHANGE and NO NEW ROUTE. The Desktop tab is served by the
 * `/cart-page` endpoints that have existed since the screen shipped; it is a
 * new entry in CartPage::TABS, not a new endpoint. The route and config caches
 * are cleared anyway because it costs nothing.
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
            echo "Cleared {$cleared} compiled files; the cart page draws two columns from 1024px up,\n";
            echo "and Appearance -> Cart page has a Desktop tab to tune them.\n";
        }
    }

    public function down(): void {}
};
