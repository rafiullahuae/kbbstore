<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Review;
use App\Support\ProductRating;
use App\Support\ReviewStatus;
use Illuminate\Database\Seeder;

/**
 * Demo reviews as REAL ROWS in `reviews`, attached to real products.
 *
 * WHY THIS EXISTS, AND WHAT IT FIXES.
 *
 * DemoCatalogueSeeder used to write invented values straight into
 * `products.rating` and `products.review_count` — `mt_rand(4, 1400)` reviews at
 * `mt_rand(38, 50) / 10` stars — while creating NOT ONE ROW in `reviews`. So a
 * demo product card advertised "4.9 · 3,204 reviews" and its product page, which
 * computes its summary from the `reviews` table (Store\ProductController::
 * reviewSummary(), approved-only), showed nothing at all. The number on the card
 * and the number on the page could not be reconciled because one of them was
 * never derived from anything.
 *
 * The rule this seeder establishes, and the reason the catalogue seeder now
 * writes zeroes: THE ROWS ARE THE TRUTH, the columns are a cache of them. Only
 * App\Support\ProductRating writes the pair, and it only ever reads APPROVED
 * rows — the same question every storefront reader asks. A product with no
 * reviews seeded against it shows no score, which is honest, rather than a score
 * with nothing behind it.
 *
 * WHAT IS SEEDED.
 *
 *   - FRONT_PAGE — the four reviews the homepage has been showing as hard-coded
 *     fixtures out of App\Services\DemoContent::reviews(). Same authors, same
 *     words, same helpful counts; they are rows now, so they survive turning
 *     demo content off and can be moderated, replied to, moved and deleted like
 *     any other review.
 *   - AUTHORS — a wider pool so the rating distribution bars on a product page
 *     have something to draw and the shop's `?sort=rating` has a real order.
 *
 * STATUSES ARE MIXED ON PURPOSE. Most rows are `approved`; a few are `pending`,
 * so Store → Reviews opens on a moderation queue with something in it rather
 * than an empty state that tells the owner nothing about whether the screen
 * works. The pending ones are chosen by a fixed arithmetic rule, not at random,
 * so two runs of the suite agree with each other. Pending rows are deliberately
 * EXCLUDED from the rating maths by ProductRating — that is the behaviour under
 * test, not an oversight.
 *
 * IDEMPOTENT. Every row is written with `source = 'demo'` and the pair
 * (product_id, author_name) is unique within that source, so re-running adds
 * nothing. Scoping the existence check to `source = 'demo'` rather than to the
 * author alone matters: a real shopper who happens to share a name with a demo
 * author must not suppress the demo row, and — more to the point — this seeder
 * must never be able to claim, adopt or overwrite a row it did not write.
 *
 * REMOVING THEM IS ONE STATEMENT, for the same reason:
 *
 *     DELETE FROM reviews WHERE source = 'demo';
 *
 * which is what the WordPress migrator should do before importing the real
 * WooCommerce reviews.
 */
class DemoReviewsSeeder extends Seeder
{
    /**
     * Stamped on every row this seeder writes.
     *
     * `source` already exists on the table for exactly this distinction — the
     * schema comment names `sorina | wp_comment` — so no column had to be added
     * to tell seeded rows from real ones.
     */
    public const SOURCE = 'demo';

    /** How many products get demo reviews. */
    private const PRODUCTS = 12;

    /**
     * One in this many rows is left `pending` instead of `approved`.
     *
     * Deterministic, not random: the suite asserts that approved rows and the
     * cached columns agree, and a seeder that produces a different split on
     * every run turns that into a test which passes or fails by luck.
     */
    private const PENDING_EVERY = 7;

    /**
     * The four reviews the homepage draws when demo content is on, lifted
     * verbatim from App\Services\DemoContent::reviews().
     *
     * Those fixtures carry no title — the storefront partial prints one only
     * `@if ($r->title)` — so a short one is supplied here, because the
     * moderation list and the product page both have a slot for it and a column
     * of blank headings reads like a bug.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private const FRONT_PAGE = [
        ['Kingsley C.', 'Lightweight and brightening', 'I love this so much. Super lightweight and moisturizing. I felt my skin brighten up and it smooths my fine lines.', 24],
        ['Houda A.', 'Best retinal ever', 'Best retinal ever. I repurchased because it is effective and affordable. Works amazingly for dry and sensitive skin.', 17],
        ['Jenifer L.', 'Ordered twice now', 'Ordered twice now. Really effective at clearing anything left behind on your face, and it does not irritate at all.', 31],
        ['Rowena M.', 'Gentle on mature skin', 'The best azelaic acid product I have tried. Lightweight, non-greasy, and it does not dry out my mature skin.', 12],
    ];

    /**
     * The wider pool. Ratings are spread 3–5 so the distribution bars differ
     * from product to product and an average is not a straight line of fives.
     *
     * @var list<array{0: string, 1: int, 2: string, 3: string}>
     */
    private const AUTHORS = [
        ['Aisha M.', 5, 'Third bottle already', 'No white cast at all and it sits perfectly under makeup. I have repurchased twice.'],
        ['Fatima K.', 5, 'Skin feels calmer', 'Lightweight, no pilling, and my skin feels genuinely calmer after a few weeks of use.'],
        ['Noor S.', 4, 'Love it, wish it were bigger', 'Formula is lovely. Only wish the tube were larger for the price.'],
        ['Layla H.', 5, 'Survives Dubai summer', 'Does not turn greasy in the heat, which is rare. Genuinely impressed.'],
        ['Mariam A.', 5, 'Glass skin, finally', 'Two weeks in and the texture on my cheeks has smoothed right out.'],
        ['Hessa R.', 4, 'Good but slow', 'Works, just took longer than I expected to see a difference. Worth staying with.'],
        ['Sara T.', 5, 'Holy grail', 'I have tried a lot of essences and keep coming back to this one.'],
        ['Amira B.', 3, 'Fine, not amazing', 'Pleasant enough but I did not notice a dramatic change. Might suit drier skin better.'],
        ['Reem J.', 5, 'Fast delivery too', 'Arrived in two days and sealed. Product is the real thing.'],
        ['Dana Q.', 5, 'Bought for my sister as well', 'She liked mine so much she asked for her own. That says it all.'],
    ];

    public function run(): void
    {
        /*
         * orderBy('id') because the row-to-product assignment below is indexed
         * off the position in this collection. Without an explicit order the
         * engine may hand them back in any order it likes, and "idempotent"
         * would then mean "writes a different set every time, none of which
         * collide" — which is the opposite.
         */
        $products = Product::query()
            ->visible()
            ->orderBy('id')
            ->limit(self::PRODUCTS)
            ->get(['id']);

        if ($products->isEmpty()) {
            $this->command?->warn('No visible products — skipping demo reviews.');

            return;
        }

        $made = 0;
        $touched = [];
        $seq = 0;

        foreach ($products->values() as $i => $product) {
            $touched[] = (int) $product->id;

            foreach ($this->rowsFor($i, $products->count()) as $row) {
                $seq++;

                if ($this->alreadySeeded((int) $product->id, $row['author'])) {
                    continue;
                }

                $age = ($i * 5 + $seq * 3) % 90;

                Review::create([
                    'product_id' => (int) $product->id,
                    'author_name' => $row['author'],
                    'author_email' => $this->emailFor($row['author']),
                    'title' => $row['title'],
                    'content' => $row['body'],
                    'rating' => $row['rating'],
                    // A few left in the queue on purpose — see PENDING_EVERY.
                    'status' => $seq % self::PENDING_EVERY === 0
                        ? ReviewStatus::PENDING
                        : ReviewStatus::APPROVED,
                    'verified' => $seq % 3 !== 2,
                    'helpful' => $row['helpful'],
                    'images' => [],
                    'source' => self::SOURCE,
                    'created_at' => now()->subDays($age),
                    'updated_at' => now()->subDays($age),
                ]);

                $made++;
            }
        }

        /*
         * One batched call at the end rather than one per product inside the
         * loop. ProductRating::refresh() takes the whole set and resolves it in
         * a single grouped query; calling it per product would be N round trips
         * to save nothing, on a shared host.
         *
         * It runs even when $made is 0. A re-run that writes nothing still has
         * to leave the columns correct — if someone moderated a demo review by
         * hand between runs, this is what puts the cards back in step with it.
         */
        ProductRating::refresh($touched);

        $this->command?->info("Demo reviews: {$made} created, "
            . count($touched) . ' products refreshed.');
    }

    /**
     * The reviews for one product: its slice of the pool, plus — for the first
     * few products — one of the homepage's own four.
     *
     * Three to six from the pool, varied by position so no two neighbouring
     * products carry an identical set and the distribution bars actually
     * differ. The offset walks the pool as well, so the same three authors do
     * not head every product page in the shop.
     *
     * @return list<array{author: string, title: string, body: string, rating: int, helpful: int}>
     */
    private function rowsFor(int $i, int $productCount): array
    {
        $rows = [];

        $take = 3 + ($i % 4);
        $offset = $i % 5;

        foreach (array_slice(self::AUTHORS, $offset, $take) as $n => [$author, $rating, $title, $body]) {
            $rows[] = [
                'author' => $author,
                'title' => $title,
                'body' => $body,
                'rating' => $rating,
                'helpful' => ($i * 3 + $n * 7) % 24,
            ];
        }

        /*
         * The homepage four, one per product across the first four products —
         * spread rather than stacked on product one, so the storefront's review
         * wall (which reads shop-wide) shows them against four different names
         * exactly as the fixtures did.
         */
        if ($i < count(self::FRONT_PAGE) && $i < $productCount) {
            [$author, $title, $body, $helpful] = self::FRONT_PAGE[$i];

            $rows[] = [
                'author' => $author,
                'title' => $title,
                'body' => $body,
                // DemoContent's fixtures are all five stars.
                'rating' => 5,
                'helpful' => $helpful,
            ];
        }

        return $rows;
    }

    /**
     * Has THIS SEEDER already written this row?
     *
     * Scoped to `source = 'demo'` deliberately. Keyed on the author alone, a
     * real shopper called "Sara T." reviewing a demo product would make the
     * seeder skip its own row for ever — and worse, the same check is what a
     * future "remove the demo reviews" step would key on.
     */
    private function alreadySeeded(int $productId, string $author): bool
    {
        return Review::query()
            ->where('product_id', $productId)
            ->where('source', self::SOURCE)
            ->where('author_name', $author)
            ->exists();
    }

    /**
     * example.com is reserved by RFC 2606 precisely so that seeded addresses
     * cannot reach a real inbox if anything ever mails them.
     */
    private function emailFor(string $author): string
    {
        return strtolower(str_replace([' ', '.'], '', $author)) . '@example.com';
    }
}
