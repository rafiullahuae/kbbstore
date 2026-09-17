<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Recompute `products.rating` and `products.review_count` from APPROVED reviews.
 *
 * WHAT THE RATING MATHS ACTUALLY DOES, having gone and read every reader.
 *
 * There are TWO figures called "the rating" on this storefront and they come
 * from different places:
 *
 *  1. COMPUTED PER REQUEST, on the product page and the homepage.
 *     Store\ProductController::reviewSummary() groups `reviews` by rating with
 *     ->approved() applied; Store\HomeController's review wall does the same
 *     for the whole shop. Store\ProductController hands reviewSummary()'s
 *     average and total to Seo as the schema.org aggregateRating. All three
 *     filter on `status = 'approved'`, so a pending or spam review has never
 *     moved any of them. GOOD, and now pinned.
 *
 *  2. DENORMALISED ON THE PRODUCTS TABLE — `products.rating` and
 *     `products.review_count`. These are what the shop cards print, what
 *     ShopController sorts `?sort=rating` and `?sort=popular` by, what
 *     CollectionController's "popular" uses, and what the `top_rated`
 *     shortcode selects on. They are computed approved-only as well — but the
 *     ONLY thing in the application that ever wrote them was
 *     DemoReviewsSeeder::refreshAggregate().
 *
 * So the live bug was not that spam moved a rating. It is that MODERATION
 * MOVED NOTHING. Approving a review left the product's card showing the old
 * score and the old count for ever; marking a published review as spam left it
 * still counted on every listing page and still sorted by it. On a store whose
 * owner treats reviews as the trust signal, the one action that is supposed to
 * publish a review did not reach the place shoppers actually see the number.
 *
 * This runs after every moderation write. Approved only — the same question
 * every reader asks — so the denormalised pair can never disagree with the
 * computed pair.
 *
 * ONE GROUPED QUERY PER PRODUCT, and products are batched, because a bulk
 * action can touch 500 rows and a shared host is not a reporting server.
 */
final class ProductRating
{
    /**
     * @param  iterable<int|null>  $productIds  Nulls (business reviews, which
     *                                          carry no product) are skipped.
     */
    public static function refresh(iterable $productIds): int
    {
        $ids = [];

        foreach ($productIds as $id) {
            if ($id === null) {
                continue;
            }

            $ids[(int) $id] = true;
        }

        $ids = array_keys($ids);

        if ($ids === []) {
            return 0;
        }

        /*
         * The aggregate for every affected product in ONE grouped query, not
         * one per product. A GROUP BY with only aggregates and the grouped
         * column beside them is legal under ONLY_FULL_GROUP_BY, which is the
         * mode this MySQL runs in.
         */
        $query = DB::table('reviews')
            ->select('product_id')
            ->selectRaw('COUNT(*) as c')
            ->selectRaw('AVG(rating) as a')
            ->whereIn('product_id', $ids)
            ->where('status', ReviewStatus::APPROVED);

        /*
         * DEMO-SEEDED ROWS ARE NOT PART OF THE SCORE.
         *
         * These two columns are the widest-reaching figures on the storefront:
         * every shop, category, brand, wishlist and related-product card
         * prints them, `?sort=rating` and `?sort=popular` order by them, the
         * `top_rated` shortcode selects on them, and App\Support\Seo turns the
         * pair into the schema.org aggregateRating it publishes to Google.
         *
         * Nothing in the application had ever asked whether the rows behind
         * them were real. DemoReviewsSeeder writes a dozen products' worth of
         * invented reviews and then calls this method to cache them into the
         * pair, so a store that had demo content switched on when an update
         * landed has been printing star ratings — and submitting them as
         * structured data — for reviews written by a seeder. Excluding them
         * here closes every one of those readers at once, because all of them
         * read the columns this method writes.
         *
         * A product whose only reviews were demo ones therefore scores 0/0,
         * which the card renders as its "New" badge rather than as an empty
         * star row. That is the honest empty state: no reviews, so no score.
         */
        DemoReviews::excludeQuery($query);

        $rows = $query
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $written = 0;

        foreach ($ids as $id) {
            $row = $rows->get($id);

            // A product whose last approved review was just rejected has no
            // row in the result at all. That is zero reviews and no score —
            // not "leave the old number alone", which is how a spam review
            // goes on inflating a card after it has been dealt with.
            $count = (int) ($row->c ?? 0);
            $average = $count > 0 ? round((float) $row->a, 2) : 0.0;

            $written += Product::query()
                ->whereKey($id)
                ->update(['review_count' => $count, 'rating' => $average]);
        }

        return $written;
    }
}
