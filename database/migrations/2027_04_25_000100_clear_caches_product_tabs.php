<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code caches, for Catalog -> Product tabs. (Lane PT)
 *
 * THREE REASONS, and the first is the one CLAUDE.md names:
 *
 *   THE ROUTE TABLE. routes/product-tabs-admin.php adds nine admin endpoints. A
 *   route added by a package does nothing at all until the compiled route table
 *   is gone -- bootstrap/cache/routes-*.php is what the application reads, and
 *   it was compiled before this file existed. The failure without this is the
 *   quiet kind: the screen renders in full, the owner writes a tab, and Save
 *   404s having thrown it away.
 *
 *   THE COMPILED VIEWS. resources/views/admin/app.blade.php gains one @include
 *   in this release, and storage/framework/views keys a compiled view by the
 *   path of its source and decides staleness on file times -- and an unzip's
 *   timestamps are not reliably newer than what is already on disk. Re-shipping
 *   the source does nothing on its own; deleting the compiled copy is what
 *   makes it take effect. The same goes for
 *   resources/views/partials/product-tabs.blade.php.
 *
 *   OPcache, one layer down: App\Http\Controllers\Store\ProductController and
 *   App\Support\AdminCapabilities are files the server has already compiled and
 *   both change here.
 *
 * ── NOTHING ON THE SHOP CHANGES ────────────────────────────────────────────
 *
 * No setting is added and no default moves. `product_tabs` is created EMPTY by
 * the migration beside this one, so App\Support\ProductTabs returns the same
 * three built-in tabs, in the same order, with the same drop rule, that
 * Store\ProductController has always returned. Every product page takes exactly
 * the branch it took before until the owner writes a tab in Catalog -> Product
 * tabs, and StorefrontEnglishUnchangedTest is the instrument that says so.
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
            echo "Cleared {$cleared} compiled files. Catalog -> Product tabs is now reachable:\n"
                ."write a tab once and it appears on every product, or open one product and\n"
                ."give it a tab of its own. A product can also hide or re-word a tab it\n"
                ."inherits. Every title and body has an Arabic box beside it.\n"
                ."Nothing on the storefront moves until you write one.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
