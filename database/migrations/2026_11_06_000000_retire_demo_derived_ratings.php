<?php

declare(strict_types=1);

use App\Models\Review;
use App\Support\DemoSeed;
use App\Support\ProductRating;
use Database\Seeders\DemoReviewsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the star ratings that demo reviews put on the live storefront, and
 * clear the compiled caches for the Demo Content truth package — Lane DO.
 *
 * WHY A MIGRATION IS REQUIRED AND NOT OPTIONAL HERE.
 *
 * App\Support\ProductRating now computes `products.rating` and
 * `products.review_count` from real rows only. But those two are STORED
 * columns, not a query — they are a cache of the reviews table, written when
 * something calls refresh(). Changing the function does not change a single row
 * that was already written.
 *
 * So on the live site, every product the demo seeder gave reviews to would keep
 * the star rating and the review count it already has: printed on every shop,
 * category, brand and related-product card, ordered by `?sort=rating` and
 * `?sort=popular`, selected on by the `top_rated` shortcode, and published to
 * Google by App\Support\Seo as a schema.org aggregateRating. The package would
 * land, the code would be correct, and the shop would go on advertising
 * invented reviews indefinitely. This is the same reasoning
 * 2026_10_11_000002_seed_demo_reviews.php gives for existing in the first
 * place: the host has no shell, so a seeder nobody can invoke fixes only a
 * fresh install.
 *
 * WHAT IS RECOMPUTED, AND WHAT IS DELIBERATELY LEFT ALONE.
 *
 * Only products that actually carry a demo review. Their pair is recomputed
 * from their real approved rows — which for most of them is none, so they end
 * at 0/0 and their card shows the "New" badge instead of a star row. A product
 * with no demo review cannot have had a demo-derived figure, so it is not
 * touched: a blanket recompute would zero legitimate WooCommerce rating meta on
 * products that carry no review rows, which is the exact caution
 * 2026_10_04_000000_normalise_review_statuses records, and it is right.
 *
 * BOTH KINDS OF DEMO ROW ARE FOUND. DemoReviewsSeeder stamps `source = 'demo'`;
 * Admin\DemoContentController::seedReviews() recorded its rows only in
 * `demo_seed_log` until this same package. Asking one question would miss every
 * row written by the other mechanism, which is the whole reason
 * App\Support\DemoReviews exists. Both are asked below.
 *
 * NOTHING IS DELETED. The demo reviews stay exactly where they are. They are
 * still visible in Store → Reviews, now marked as demo, because the owner
 * cannot remove what the panel hides — and Store → Demo Content → Remove is
 * still the thing that deletes them. This migration only stops them counting.
 *
 * IDEMPOTENT. Recomputing a pair that is already correct writes the same
 * values, so a package applied twice — which is how 2.60.102-.106 went wrong —
 * does the same work and leaves the same result.
 *
 * NO SCHEMA CHANGE, and in particular no ->after() (see
 * tests/Feature/MigrationConventionTest.php). NO ROUTE IS ADDED; the route
 * cache is cleared with the rest anyway, for the reason
 * 2026_11_05_000000_clear_caches_review_wall_truth gives.
 */
return new class extends Migration
{
    /** Products per round trip while recomputing. A shared host is not a report server. */
    private const CHUNK = 200;

    public function up(): void
    {
        $this->retireDemoDerivedRatings();
        $this->clearCaches();
    }

    /**
     * Deliberately empty.
     *
     * The reverse of "stop counting invented reviews" is "count them again",
     * and a migration that puts a fabricated star rating back on a live
     * storefront is not a rollback anyone wants. Re-running the demo seeder is
     * the supported way to get demo rows back, and Store → Demo Content is the
     * supported way to remove them.
     */
    public function down(): void {}

    private function retireDemoDerivedRatings(): void
    {
        if (! Schema::hasTable('reviews') || ! Schema::hasTable('products')) {
            return;
        }

        $productIds = [];

        // Mechanism one: the seeder's stamp.
        $seeded = DB::table('reviews')
            ->where('source', DemoReviewsSeeder::SOURCE)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');

        foreach ($seeded as $id) {
            $productIds[(int) $id] = true;
        }

        // Mechanism two: the admin panel's log. Guarded, because
        // DemoContentController::ensureTable() creates it lazily and a build
        // whose migration step was skipped has no such table until Demo Content
        // is first opened.
        if (DemoSeed::tableExists()) {
            $loggedIds = DB::table(DemoSeed::TABLE)
                ->where('model', Review::class)
                ->pluck('record_id')
                ->all();

            if ($loggedIds !== []) {
                $logged = DB::table('reviews')
                    ->whereIn('id', $loggedIds)
                    ->whereNotNull('product_id')
                    ->distinct()
                    ->pluck('product_id');

                foreach ($logged as $id) {
                    $productIds[(int) $id] = true;
                }
            }
        }

        if ($productIds === []) {
            if (app()->runningInConsole()) {
                echo "No demo-derived product ratings to retire.\n";
            }

            return;
        }

        $ids = array_keys($productIds);

        foreach (array_chunk($ids, self::CHUNK) as $batch) {
            ProductRating::refresh($batch);
        }

        if (app()->runningInConsole()) {
            echo 'Retired demo-derived ratings on ' . count($ids) . " products.\n";
        }
    }

    /**
     * The standing convention, plus one key that matters specifically here.
     *
     * `kbb.home.reviews` holds the shop-wide review wall — total, average, star
     * bars and four rendered cards — computed before this package taught it to
     * exclude demo rows. `kbb.home.rails` holds Product models carrying the
     * rating and review_count this migration has just rewritten. Left in place,
     * both would go on serving the old figures for up to fifteen minutes after
     * the package lands, which is indistinguishable from the fix not working.
     */
    private function clearCaches(): void
    {
        foreach (['kbb.home.reviews', 'kbb.home.rails', 'kbb.home.cats',
                  'kbb.home.brands', 'kbb.home.count', 'kbb.home.brandcount',
                  'kbb.store.rating'] as $key) {
            try {
                \Illuminate\Support\Facades\Cache::forget($key);
            } catch (\Throwable) {
                // A cache store that is unreachable during a migration must not
                // take the package down; the entries expire on their own.
            }
        }

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
};
