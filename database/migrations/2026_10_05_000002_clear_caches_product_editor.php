<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the product editor package (Lane AO).
 *
 * Every compiled cache this host keeps is stale after this package, and the
 * failure modes are the quiet ones:
 *
 *   - ROUTES. routes/product-editor-admin.php adds five paths under
 *     /admin-api/product-editor-*. A compiled route cache knows none of them,
 *     so the editor renders in full, the owner fills in a description, a
 *     gallery, an SEO block and a publish date, presses Save — and gets a 404
 *     that throws the lot away. This is the failure CLAUDE.md names first.
 *
 *   - VIEWS. resources/views/admin/app.blade.php gains one @include line and
 *     resources/views/admin/partials/product-editor-screen.blade.php is new.
 *     Compiled Blade is keyed by path, so the stale copy of app.blade.php is
 *     the case that never self-corrects: the console keeps serving the previous
 *     shell, the include is not in it, and the screen simply does not exist
 *     while every endpoint behind it works perfectly.
 *
 *   - OPCACHE. Classes CHANGE here as well as appear, and a changed class is
 *     the worse case — a worker holding a stale compiled copy does not fail to
 *     autoload, it silently serves the old code:
 *
 *       App\Models\Product              scopeVisible() now tests published_at.
 *                                       A stale copy shows scheduled products
 *                                       on the storefront before their date.
 *       App\Http\Controllers\Admin\AdminController
 *                                       SEO now reads and writes `seo`, not
 *                                       `seo_json`. A stale copy writes the
 *                                       dead column again.
 *       App\Providers\AppServiceProvider
 *                                       IndexNow no longer pings Google for a
 *                                       product that is not live yet.
 *       App\Http\Controllers\Store\BrandController
 *       App\Http\Controllers\Store\ReviewController
 *                                       Both had hand-written publish checks
 *                                       that now go through the shared
 *                                       predicate.
 *
 *     New: App\Support\RichText, App\Support\ProductVisibility,
 *     Admin\ProductEditorApiController.
 *
 * SCHEMA DOES CHANGE in this package — 2026_10_05_000000 adds published_at,
 * ingredients and how_to_use, and 2026_10_05_000001 moves SEO data between two
 * existing columns. Both run before this file by filename order, which is what
 * the ordering is for: the caches are cleared after the columns exist, never
 * before. Neither uses an AFTER clause, the thing that made nine earlier
 * migrations here silent no-ops on MySQL.
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
