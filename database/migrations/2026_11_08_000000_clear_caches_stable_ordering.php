<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Drop the cached lists that were built under an order that did not settle
 * itself (Lane DX).
 *
 * NO SCHEMA CHANGE AND NO NEW ROUTE. What changed is the ORDER BY on every
 * storefront query that then takes a slice of its own result: each now ends on
 * a key that cannot tie, normally `id`. Where the sort key was unique the
 * lists are byte-identical; where it tied — which on this catalogue is most of
 * the tail, because `total_sales` is a counter, `review_count` and `rating`
 * are 0 nearly everywhere and `position` is 0 until somebody reorders
 * something — the tie is now decided by the query rather than by whichever
 * plan the database happened to pick.
 *
 * WHY THE CACHES HAVE TO GO WITH IT. Seven of these lists are served out of
 * Cache::remember() for between five and fifteen minutes: the homepage rails
 * and its brand and category strips, the shop's facet rails, the search
 * panel's starter lists and the admin's own lookup caches. An entry written
 * before this package holds the OLD answer — including, on the homepage,
 * possibly the same product in both best-seller rails, which is the defect
 * this package exists to remove. Left alone it would go on being served for a
 * quarter of an hour after the update, on the most-hit URL on the site, with
 * nothing on screen admitting it.
 *
 * The compiled views go too. `kbb.home.rails` now holds `best1` and `best2` as
 * two halves of ONE result rather than two separate queries, and although the
 * shape is unchanged — both are still Eloquent collections, so an entry
 * written by the previous version still renders — dropping the compiled views
 * keeps the package's own before/after honest rather than mixed.
 *
 * Best-effort on the cache, as the menu package does: a cache driver that is
 * unreachable mid-update must not fail the package, and every one of these
 * keys expires on its own within fifteen minutes regardless.
 *
 * Nothing here uses ->after(); there is no ALTER at all. See
 * tests/Feature/MigrationConventionTest.php for why that matters on this host.
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

        $keys = [
            // Homepage: the four product rails, the brand strip, the category
            // tiles, the journal row and the six-step routine.
            'kbb.home.rails', 'kbb.home.brands', 'kbb.home.cats',
            'kbb.home.posts', 'kbb.home.reviews', 'kbb.home.routine',
            // Shop: the category and brand facet rails.
            'kbb.shop.cats', 'kbb.shop.brands',
            // Search panel: the popular products and brands it opens with.
            'kbb.search.starter.products', 'kbb.search.starter.brands',
            // Admin lookups that feed the block builder and the header editor.
            'kbb.admin.cats', 'kbb.admin.brands', 'kbb.admin.trending',
        ];

        foreach ($keys as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
            }
        }

        /*
         * The search-insights cache is keyed by its own limit, and the limit is
         * an owner-set option rather than a constant, so the keys cannot simply
         * be listed. popular() clamps the limit to 1..12, so twelve forgets
         * cover every key the method can ever write.
         */
        for ($limit = 1; $limit <= 12; $limit++) {
            try {
                Cache::forget('kbb.search.popular.' . $limit);
            } catch (\Throwable) {
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; flushed the ordered storefront lists.\n";
        }
    }

    public function down(): void {}
};
