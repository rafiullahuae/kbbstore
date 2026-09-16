<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\ProductRating;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make products.rating and products.review_count tell the truth.
 *
 * DemoCatalogueSeeder line 80 wrote `mt_rand(4, 1400)` into review_count and a
 * random 3.8–5.0 into rating, for a catalogue that has no reviews behind it.
 * Those columns are what the shop cards, the product grid, quick view and the
 * rating sorts all print — so the shop was showing "4.9 · 3,204 reviews" on a
 * product nobody has ever reviewed.
 *
 * WHAT THIS IS NOT. Google was never told those numbers: ProductController
 * hands Seo the live summary from the reviews table, not these columns, and
 * Seo omits aggregateRating when it is empty. The lie was to shoppers only,
 * which is bad enough — it is invented social proof on a page asking for money.
 *
 * ProductRating::refresh() is the same recomputation moderation, import and
 * bulk-add all use, so this leaves the columns in exactly the state the app
 * would have put them in had a review ever been moderated. On a catalogue with
 * no approved reviews that means zero, which is the honest answer; the moment
 * the owner approves or imports a review for a product, that product's numbers
 * come back on their own.
 *
 * SCOPE, AND WHY IT IS NARROWER THAN "EVERY PRODUCT".
 *
 * This recomputes two sets and no others:
 *
 *   - products with `wc_id IS NULL` — the placeholders this repo's own seeder
 *     invented. There is nothing to protect there; the fabricated pair is
 *     exactly what has to go.
 *   - products that HAVE review rows, imported or not. Recomputing those is
 *     always safe: the answer is derived from the rows themselves.
 *
 * What it leaves alone is an imported product carrying a rating with no review
 * rows behind it. Nothing in this repo writes that combination — the
 * WooCommerce importer never touches either column, and the only writers are
 * ProductRating::refresh() and the demo seeder — but the live catalogue was
 * populated before this repo existed, and I cannot inspect it from here. If
 * such a row does exist, its rating came from WooCommerce's own rating meta,
 * and zeroing it would be this migration destroying data it does not own.
 * 2026_10_04_000000_normalise_review_statuses declined that same reach for the
 * same reason, and 2026_10_11_000002_seed_demo_reviews scopes to `wc_id IS
 * NULL` for it too. A stale figure on a card is a smaller harm than a real one
 * deleted with no way to get it back on a host with no shell.
 *
 * Re-runnable: refresh() recomputes from the reviews table rather than
 * adjusting, so running this twice gives the same answer. No schema change and
 * no AFTER clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chunked by id: a shared host will not hold the whole catalogue in
        // memory, and refresh() is a single UPDATE per batch regardless.
        $touched = 0;

        Product::withTrashed()
            ->select('id')
            ->where(function ($q): void {
                $q->whereNull('wc_id')
                    ->orWhereIn('id', function ($sub): void {
                        $sub->select('product_id')
                            ->from('reviews')
                            ->whereNotNull('product_id');
                    });
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$touched): void {
                $ids = $rows->pluck('id')->all();
                ProductRating::refresh($ids);
                $touched += count($ids);
            });

        if (app()->runningInConsole()) {
            $fabricated = DB::table('products')
                ->where('review_count', '>', 0)
                ->count();

            echo "Recomputed ratings for {$touched} products; {$fabricated} now carry a real review count.\n";
        }
    }

    /**
     * Deliberately empty.
     *
     * There is no "down" from the truth to a set of random numbers, and
     * inventing a fresh set would be a different lie rather than a rollback.
     */
    public function down(): void {}
};
