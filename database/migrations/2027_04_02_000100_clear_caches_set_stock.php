<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code caches, for the Set's remaining edges. (Lane SP)
 *
 * THREE REASONS, and the first is the one CLAUDE.md names:
 *
 *   THE ROUTE TABLE. routes/sp-set-stock-admin.php adds two admin endpoints.
 *   A route added by a package does nothing at all until the compiled route
 *   table is gone -- bootstrap/cache/routes-*.php is what the application
 *   reads, and it was compiled before this file existed.
 *
 *   THE COMPILED VIEWS. This release adds partials/set-contents-panel.blade.php
 *   and edits FOUR Blade files that are ALREADY on the server:
 *   store/product.blade.php (one @include), admin/partials/product-editor-
 *   screen.blade.php (the type control, the Set panel and Tags),
 *   admin/partials/sets-screen.blade.php (rebuilt as a list) and
 *   admin/partials/media-picker.blade.php (the skeleton and the warm result).
 *   storage/framework/views keys a compiled view by the path of its source and
 *   decides staleness on file times, and an unzip's timestamps are not reliably
 *   newer than what is already on disk. Re-shipping the sources does nothing on
 *   its own; deleting the compiled copies is what makes them take effect.
 *
 *   OPCACHE, one layer down: App\Models\Product, App\Services\StockClaim,
 *   App\Support\SetContents, App\Support\SetEagerLoad, App\Support\Seo,
 *   App\Support\AdminCapabilities, Admin\CatalogProductsApiController,
 *   Admin\ProductEditorApiController and Admin\SetApiController are all files
 *   the server has already compiled, and every one of them changes in this
 *   release.
 *
 * ── NOTHING ON THE SHOP CHANGES ────────────────────────────────────────────
 *
 * NO SETTING ROW IS WRITTEN, and the two columns this release adds
 * (2027_04_02_000000_set_pricing_columns) are NULLABLE with no backfill. The one new setting,
 * `set_stock_mode`, is deliberately NOT seeded: absent, App\Services\-
 * StockSetRule::mode() answers `set`, which is literally what this application
 * did before it existed -- the set carries its own stock and its members are
 * untouched. The switch on Catalog -> Sets moves inventory only once somebody
 * moves it.
 *
 * The product page's new panel renders NOTHING for a product that is not a set,
 * and there are no sets in a shop until somebody makes one:
 * StorefrontEnglishUnchangedTest is byte-for-byte green across this release.
 *
 * ▲ THE `migrations` FLAG IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT
 *   ALL -- UpdateRunner::hasMigrations() never looks at the files. Build the
 *   package with `php artisan kbb:package <version> --since=<ref>`, never a
 *   hand-rolled script. Five packages built by one in an afternoon shipped
 *   eight migrations that were copied to the live server and never ran.
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
            echo "Cleared {$cleared} compiled files. Sets are now built on the PRODUCT\n"
                ."editor: Catalog -> Product editor has a type control, and choosing Set\n"
                ."reveals \"What is in the box\" beside every section a product already\n"
                ."has. A set's own product page names what is in the box, Catalog ->\n"
                ."Products has a Sets chip, Catalog -> Sets is now the list of them, and\n"
                ."Catalog -> Sets -> Stock carries the switch for whether selling a set\n"
                ."takes its members off the shelf. That switch ships OFF -- the set\n"
                ."carries its own stock, exactly as before -- so nothing moves until you\n"
                ."move it.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
