<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code caches, for the Sets module. (Lane SET)
 *
 * TWO REASONS, and the first is the one CLAUDE.md names:
 *
 *   THE ROUTE TABLE. routes/sets-admin.php adds eleven admin endpoints. A route
 *   added by a package does nothing at all until the compiled route table is
 *   gone -- bootstrap/cache/routes-*.php is what the application reads, and it
 *   was compiled before this file existed.
 *
 *   THE COMPILED VIEWS. This release edits Blade files that are ALREADY on the
 *   server: partials/cart-drawer.blade.php, store/cart-inner.blade.php,
 *   partials/checkout/summary-items.blade.php, partials/checkout/received-line.-
 *   blade.php, store/account/order-detail.blade.php, emails/partials/items.-
 *   blade.php and invoices/partials/sheet-invoice.blade.php.
 *   storage/framework/views keys a compiled view by the path of its source and
 *   decides staleness on file times, and an unzip's timestamps are not reliably
 *   newer than what is already on disk. Re-shipping the sources does nothing on
 *   its own; deleting the compiled copies is what makes them take effect.
 *
 * OPcache for the same reason one layer down: App\Models\Product and
 * App\Http\Controllers\Store\CheckoutController are both files the server has
 * already compiled, and both gain a few lines in this release.
 *
 * ── NOTHING ON THE SHOP CHANGES ────────────────────────────────────────────
 *
 * No setting is added and no default moves. Until somebody creates a set in
 * Catalog -> Sets there is not one row in `product_set_items`, every
 * `order_items.set_contents` is NULL, and every surface this release touches
 * takes exactly the branch it took before.
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
            echo "Cleared {$cleared} compiled files. Catalog -> Sets is now reachable:\n"
                ."create a set by choosing products, give it its own price, category,\n"
                ."description and images, and publish it like any other product.\n"
                ."Nothing on the storefront moves until you create one.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
