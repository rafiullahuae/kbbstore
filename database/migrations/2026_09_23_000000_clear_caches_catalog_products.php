<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Catalog → Products package (Lane AF).
 *
 *   - ROUTES. routes/catalog-products-admin.php adds eight paths under
 *     /admin-api/catalog-products-*. A compiled route cache knows none of them,
 *     and the failure is the quiet kind: the Products screen renders its chips,
 *     its inline price and stock cells and its export button perfectly, and
 *     every request 404s — which reads as "the screen is broken" rather than
 *     "the routes were never loaded". Packages 2.60.102–.106 are the standing
 *     reminder of what an inert release costs.
 *
 *   - VIEWS. resources/views/admin/app.blade.php grew the whole Products list
 *     and its edit panel. Compiled Blade is keyed by path, so a stale copy of a
 *     file that already exists is exactly the case that does not self-correct:
 *     the admin console would keep serving the previous, read-only Products
 *     screen from the compiled view while the new endpoints sit there unused.
 *
 * OPcache too. CatalogProductsApiController is an EXISTING class whose methods
 * changed — index() returns new keys and seven methods are new — which is the
 * worse case of the two: a worker holding a stale compiled copy does not fail
 * to autoload, it serves the old code. CLAUDE.md requires a clear_caches
 * migration for any package changing a PHP class, and this package changes one
 * every admin request touches.
 *
 * No schema change. This package adds no column and alters no table — the
 * screen reads what `products`, `category_product`, `brands`, `order_items` and
 * `orders` already hold. Nothing here positions a column with an AFTER clause
 * either, which is what made nine earlier migrations in this repo silent no-ops
 * on MySQL: an ALTER naming a column that does not exist yet is an error there,
 * and the hasColumn guards wrapped around them made that error look clean.
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
