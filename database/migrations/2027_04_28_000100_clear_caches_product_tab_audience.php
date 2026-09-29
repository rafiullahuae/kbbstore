<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Compiled-code caches, for the "where it shows" rule on a global tab.
 * (Lane PT, round 2)
 *
 * NO NEW ROUTE THIS TIME, and that is worth saying because the convention
 * CLAUDE.md names is about routes. The five rule types were added to the
 * endpoints Catalog -> Product tabs already has -- one more field on the
 * bootstrap, one more field on the save -- rather than to new ones, which is
 * what the capability map asks for too: `producttabs.view` and
 * `producttabs.manage` already cover every path this release touches, so
 * nothing new has to be granted to anybody.
 *
 * What DOES have to be cleared:
 *
 *   THE COMPILED VIEWS. resources/views/admin/partials/product-tabs-screen
 *   .blade.php gains the whole targeting control. storage/framework/views keys
 *   a compiled view by the path of its source and decides staleness on file
 *   times, and an unzip's timestamps are not reliably newer than what is
 *   already on disk. Re-shipping the source does nothing on its own; deleting
 *   the compiled copy is what makes it take effect.
 *
 *   OPcache, one layer down: App\Support\ProductTabs, App\Models\ProductTab,
 *   App\Models\Category and Admin\ProductTabsApiController are all files the
 *   server has already compiled and all four change here.
 *
 * ── NOTHING ON THE SHOP CHANGES ────────────────────────────────────────────
 *
 * `audience` lands DEFAULT 'global' on every row that already exists, which is
 * the rule those rows were written under, so every tab keeps showing exactly
 * where it showed. No setting is added and no default moves. The three
 * built-in tabs are not rows in that table at all and are untouched.
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
            echo "Cleared {$cleared} compiled files. A global tab can now be pointed at\n"
                ."specific products, whole categories (and everything under them), brands,\n"
                ."or every set -- Catalog -> Product tabs -> Global tabs -> Where it shows.\n"
                ."Every tab you have already written stays on every product until you\n"
                ."narrow it.\n";
        }
    }

    /** Clearing a cache is not a state change; the next request rebuilds it. */
    public function down(): void {}
};
