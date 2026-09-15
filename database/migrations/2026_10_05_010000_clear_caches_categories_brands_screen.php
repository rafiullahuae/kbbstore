<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Categories & Brands screen (Lane AQ).
 *
 * WHY THIS PACKAGE NEEDS ONE — all three caches are live here.
 *
 *   - ROUTES. routes/categories-brands-admin.php adds five routes, and the
 *     integrator additionally repoints /product-category/{path} at
 *     Store\CategoryArchiveController. CLAUDE.md is explicit: a route added to
 *     this application does not take effect until the compiled route cache is
 *     cleared, because the server has no shell and the cache is only ever
 *     rebuilt by a migration like this one. Without it the screen loads and
 *     every request it makes returns 404 — which reads as "the package did not
 *     apply" when in fact it did.
 *
 *     The archive route is the sharper half. Repointing an EXISTING route is
 *     exactly the case a stale cache hides best: the URL goes on working,
 *     serving the old closure, so the soft-404 fix appears to have shipped and
 *     silently has not.
 *
 *   - VIEWS. resources/views/admin/app.blade.php gained an @include, and
 *     resources/views/admin/partials/category-tree-screen.blade.php is new.
 *     Compiled Blade is keyed by path, so a stale copy of a file that already
 *     exists is the case that never self-corrects — the console would go on
 *     rendering the previous version of app.blade.php, without the include,
 *     indefinitely. The new partial would simply never appear.
 *
 *   - OPCACHE. App\Support\CategoryPath, Admin\BrandsTreeApiController,
 *     Admin\CategoryRedirectsApiController and Store\CategoryArchiveController
 *     are new classes, which OPcache handles cleanly. The trap is the ones that
 *     CHANGED: Admin\CategoriesApiController and Store\ShopController. A worker
 *     holding a stale compiled tree serves the old category counts and the old
 *     sidebar ordering while the new screen calls the new endpoints, which is
 *     how "the migration ran and changed nothing" gets reported.
 *
 * NO SCHEMA CHANGE HERE. The package's schema — the `seo` columns and the
 * `category_redirects` table — is created by
 * 2026_10_05_000000_add_category_seo_and_redirects.php, which is the only thing
 * that writes it, so the two can never be applied apart. Nothing in this
 * package positions a column with an AFTER clause: that is the clause that made
 * nine earlier migrations in this repo silent no-ops on MySQL, where an ALTER
 * naming a column that does not exist yet is an error and the hasColumn guards
 * around them made that error look clean.
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
