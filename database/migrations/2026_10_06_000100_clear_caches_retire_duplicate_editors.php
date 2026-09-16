<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the retire-duplicate-editors package (Lane AT).
 *
 * This package REMOVES things, and removal needs the caches cleared at least as
 * badly as addition does. CLAUDE.md states the rule for routes that appear; the
 * case below is the same rule read the other way round, and its failure mode is
 * worse because it is silent and it is a security one.
 *
 *   - ROUTES. Three admin-api paths are gone:
 *
 *         POST /admin-api/catalog-product-slug
 *         POST /admin-api/catalog-product-create
 *         POST /admin-api/catalog-product-image/{id}
 *
 *     Two of them WRITE the catalogue — one adds a row to `products` and can
 *     put it on the storefront in the same request, the other replaces the
 *     photograph on a product the shop is already selling. A compiled route
 *     cache is a snapshot: the live host goes on matching those three paths to
 *     App\Http\Controllers\Admin\CatalogProductCreateApiController, which this
 *     package DELETES. The result is not a tidy 404. It is a route table
 *     pointing at a class that no longer autoloads, i.e. a 500 on a path that
 *     should not exist at all — and until the cache is rebuilt the endpoints
 *     the package was written to retire are still the endpoints the server
 *     advertises. Clearing the cache is the step that actually retires them.
 *
 *   - VIEWS. resources/views/admin/app.blade.php loses the whole Lane AK region
 *     and Lane AF's cpOpenDetail panel, and the Catalog screen's Edit and Add
 *     product buttons are repointed at the surviving editor. Compiled Blade is
 *     keyed by path, so a stale copy of app.blade.php is the case that never
 *     self-corrects: the console keeps serving the previous shell, its Edit
 *     button keeps calling cpOpenDetail — a function that is no longer defined
 *     anywhere in the document — and the operator gets a dead button and a
 *     ReferenceError in the console rather than the editor.
 *
 *   - OPCACHE. A DELETED class is the case that most needs this. A worker
 *     holding a compiled copy of
 *     App\Http\Controllers\Admin\CatalogProductCreateApiController keeps
 *     serving it perfectly well after the file is gone, so the retired create
 *     and image endpoints go on writing the catalogue from a file that is not
 *     in the deployment. opcache_reset() is what makes the deletion real.
 *
 * NO SCHEMA CHANGE. Nothing in this package touches a column, and nothing here
 * depends on migration order — it is safe wherever it lands, and it is dated
 * after 2026_10_05_000002_clear_caches_product_editor so the editor it leaves
 * standing is already on disk when these caches are rebuilt.
 *
 * The integrator still has one line to remove by hand: CLAUDE.md forbids this
 * lane from editing routes/web.php, so
 * `require __DIR__.'/catalog-product-create-admin.php';` is still there, now
 * pointing at a tombstone that registers nothing. See the header of that file.
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
