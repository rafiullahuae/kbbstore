<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Add-product / product-image package (Lane AK).
 *
 * All three compiled caches this host keeps are stale after this package, and
 * each fails differently and quietly:
 *
 *   - ROUTES. routes/catalog-product-create-admin.php adds three paths under
 *     /admin-api/catalog-product-*. A compiled route cache knows none of them.
 *     The failure is the quiet kind and it is worse here than on a read screen:
 *     the Add product form renders completely, the operator types a name, a
 *     price, picks a category, uploads a photograph — and Save 404s, having
 *     thrown the whole form away. Packages 2.60.102–.106 are the standing
 *     reminder of what an inert release costs.
 *
 *   - VIEWS. resources/views/admin/app.blade.php gains the create form, the
 *     image box on the edit panel and the upload widget behind both. Compiled
 *     Blade is keyed by path, so a stale copy of a file that already exists is
 *     exactly the case that does not self-correct: the console would keep
 *     serving the previous Products screen — the one whose Add product button
 *     reached the preview mock — out of the compiled view while the new
 *     endpoints sit there unused, which reads as "the update did nothing".
 *
 *   - OPCACHE. Two classes CHANGE rather than appear:
 *     Admin\CatalogProductsApiController (its money parse now calls
 *     App\Support\MajorUnits) and Admin\MediaUploadController (the stored
 *     extension is taken from the file's real content type instead of its
 *     name). A changed existing class is the worse of the two cases — a worker
 *     holding a stale compiled copy does not fail to autoload, it serves the
 *     old code — and MediaUploadController is on the path of every image upload
 *     in the admin. CLAUDE.md requires a clear_caches migration for any package
 *     changing a PHP class; this one changes two and adds three.
 *
 * NO SCHEMA CHANGE. This package adds no column and alters no table. Creating a
 * product writes `products` and `category_product`, both of which the Phase 0
 * schema already declares, and the image is a URL in the existing
 * `products.image` column. Nothing here uses an AFTER clause either — the thing
 * that made nine earlier migrations in this repo silent no-ops on MySQL, where
 * an ALTER naming a column that does not exist yet is an error that a
 * hasColumn guard then made look clean.
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
