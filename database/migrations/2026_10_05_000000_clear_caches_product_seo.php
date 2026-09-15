<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the product structured-data package (Lane AP).
 *
 * NO ROUTE CHANGE in this package, and that is worth stating rather than
 * leaving to inference: routes/web.php is untouched, nothing new is registered,
 * and the route cache is flushed here only because this migration flushes the
 * whole compiled set and a route cache that is merely rewritten costs nothing.
 * What actually has to be cleared is the other two:
 *
 *   - VIEWS. resources/views/store/product.blade.php gains a @push('head') that
 *     preloads the main gallery shot. Compiled Blade is keyed by path, so a
 *     file that already exists and merely changed is exactly the case that does
 *     not self-correct — the host would keep serving the previously compiled
 *     product template, and the change would read as having done nothing at all
 *     while the package reported success.
 *
 *   - OPCACHE. Four classes CHANGE rather than appear, which is the worse of
 *     the two cases: a worker holding a stale compiled copy does not fail to
 *     autoload, it quietly serves the old code.
 *
 *       App\Support\Seo                              the JSON-LD the crawler reads
 *       App\Support\Money                            gains decimalString()
 *       App\Http\Controllers\Store\ProductController the seoCtx it hands over
 *       App\Http\Controllers\Store\SeoFilesController the sitemap's noindex filter
 *
 *     Seo and ProductController are on the path of every product page on the
 *     site, and the pairing is what makes a stale copy dangerous here rather
 *     than merely inert: ProductController now sends `price`, `images`,
 *     `stock_status` and `currency`, and Seo reads them. A worker running the
 *     new controller against the old Seo would fall back to the legacy
 *     `price_aed` branch; one running the new Seo against the old controller
 *     would find no `price` key at all. Either mismatch publishes a different
 *     offer to Google than the page displays, which is the one failure mode
 *     this package exists to remove.
 *
 * NO SCHEMA CHANGE. Nothing is added, altered or dropped. The per-product SEO
 * overrides this package now honours in the sitemap live in `products.seo`,
 * which the Phase 0 schema already declares (and which
 * 2026_07_11_000001_add_seo_json_to_products.php backfills for older tables).
 * No AFTER clause either — the thing that made nine earlier migrations in this
 * repo silent no-ops on MySQL.
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
