<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled views for 2.60.244.
 *
 * WHAT IS STALE IF THIS DOES NOT RUN: all of it. Every visible change in this
 * package is in a Blade file.
 *
 *   - store/cart-squeeze.blade.php holds the rule that stops the rail's arrows
 *     being clipped, the script that puts the close button on the panel's own
 *     corner, the checkout box's padding and the desktop stepper multiplier —
 *     plus the breakpoint itself, which Blade interpolates into the media
 *     query because a media query is resolved before custom properties exist.
 *   - admin/partials/cart-page-screen.blade.php previews all of it.
 *
 * A stale compiled copy is a cart page where the arrows are still sliced in
 * half by the section they hang off and the close button is still inside the
 * popup instead of above it.
 *
 * Compiled Blade is keyed by the view's PATH, never by its contents, and its
 * freshness check is a filemtime comparison. An update package is an unzip, so
 * the timestamps it lands are whatever the archive carried and are not reliably
 * newer than the compiled copy the running site wrote. A stale copy of a file
 * that already exists is exactly the case that does not self-correct — and it
 * is how 2.60.102-.106 shipped inert.
 *
 * NO SCHEMA CHANGE and NO NEW ROUTE. The new settings are rows in
 * CartPage::SCHEMA, served by the `/cart-page` endpoints that have existed
 * since that screen shipped.
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
            echo "Cleared {$cleared} compiled files; the rail's arrows are no longer clipped, the\n";
            echo "popup's close button sits above its top-right corner, and the checkout box and\n";
            echo "quantity stepper have desktop controls of their own.\n";
        }
    }

    public function down(): void {}
};
