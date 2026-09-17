<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Review;
use App\Support\ReviewStatus;
use Database\Seeders\DemoCatalogueSeeder;
use Database\Seeders\DemoReviewsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * THE DEMO REVIEWS ARE ROWS, AND THE CARDS COUNT THEM.
 *
 * The bug this file pins is the one the owner reported in his own words: demo
 * product cards advertised "4.9 · 3,204 reviews" over a product page that
 * showed no reviews at all. Both numbers were honest about their own source and
 * there was no shared source — DemoCatalogueSeeder wrote `products.rating` and
 * `products.review_count` from mt_rand() and created NOT ONE row in `reviews`.
 *
 * So the assertions here are not "the seeder ran". They are:
 *
 *   - real rows exist, against real product ids, moderatable like any other;
 *   - the four reviews the homepage had been faking out of
 *     App\Services\DemoContent are among them;
 *   - the cached pair on `products` EQUALS the aggregate of the APPROVED,
 *     NON-DEMO rows, product by product, computed here from the table rather
 *     than taken from the code under test;
 *   - a second run adds nothing;
 *   - and DemoCatalogueSeeder can no longer invent a figure on its own.
 *
 * WHAT CHANGED, AND WHY THE ARITHMETIC ABOVE GAINED A CLAUSE.
 *
 * This file used to say the pair equalled the aggregate of the approved rows,
 * full stop — and since the seeder's rows were the only rows, that is what put
 * a star rating on the demo catalogue's cards and, through App\Support\Seo, a
 * schema.org aggregateRating in front of Google. It was the same defect one
 * layer down: the cards no longer disagreed with the product page, but both of
 * them now agreed about customers who do not exist.
 *
 * `products.rating` and `products.review_count` are the widest-reaching figures
 * on the storefront — every shop, category, brand and related card prints them,
 * `?sort=rating` and `?sort=popular` order by them, `top_rated` selects on them
 * — so App\Support\ProductRating now computes them from real rows only. A
 * product whose only reviews were seeded therefore scores 0/0 and its card
 * shows the "New" badge, which is the truth about it.
 *
 * The demo rows themselves are untouched and still in the table: the admin
 * needs to see them to remove them. They simply do not count.
 *
 * WHY EACH TEST CLEARS FIRST. database/migrations/2026_10_11_000002_seed_demo_
 * reviews.php runs during the suite's migration pass, so every test starts with
 * the demo reviews already in place — which is the point of that migration, and
 * would otherwise make "the seeder created rows" pass without the seeder doing
 * anything. Each test below therefore states the world it wants.
 */

/** Wipe only what this seeder owns, so a test starts from a known nothing. */
function dsClearDemoReviews(): void
{
    Review::query()->where('source', DemoReviewsSeeder::SOURCE)->delete();

    Product::query()->update(['rating' => 0, 'review_count' => 0]);
}

/**
 * The aggregate the cached pair on `products` is supposed to equal: rows that
 * are APPROVED **and** were written by a real person.
 *
 * Deliberately NOT App\Support\ProductRating and NOT App\Support\DemoReviews —
 * both are the code under test, and a test that checks a value against the
 * function that produced it checks nothing. The two conditions are spelled out
 * here in full so this stays an independent second opinion.
 *
 * The demo test is written as "source IS NULL OR source <> 'demo'" rather than
 * "source <> 'demo'" for the reason the production predicate gives: on both
 * engines `NULL <> 'demo'` is NULL, not true, so the shorter form silently
 * drops every real review that carries no source at all.
 *
 * @return array{count: int, average: float}
 */
function dsApprovedAggregate(int $productId): array
{
    $rows = Review::query()
        ->where('product_id', $productId)
        ->where('status', ReviewStatus::APPROVED)
        ->where(function ($q) {
            $q->whereNull('source')->orWhere('source', '<>', DemoReviewsSeeder::SOURCE);
        })
        ->whereNotIn('id', DB::table('demo_seed_log')
            ->where('model', Review::class)
            ->pluck('record_id')
            ->all())
        ->pluck('rating')
        ->all();

    $count = count($rows);

    return [
        'count' => $count,
        'average' => $count === 0 ? 0.0 : round(array_sum($rows) / $count, 2),
    ];
}

/* --------------------------------------------------------- the rows exist */

it('writes real review rows against real products', function () {
    dsClearDemoReviews();

    expect(Review::query()->where('source', DemoReviewsSeeder::SOURCE)->count())->toBe(0);

    test()->seed(DemoReviewsSeeder::class);

    $rows = Review::query()->where('source', DemoReviewsSeeder::SOURCE)->get();

    expect($rows->count())->toBeGreaterThan(0);

    // Every row points at a product that exists. An orphan would render on no
    // product page at all, which is the empty reviews table with extra steps.
    $productIds = $rows->pluck('product_id')->unique()->values();

    expect($productIds->contains(null))->toBeFalse('Demo reviews must be attached to a product.');

    $existing = Product::query()->whereKey($productIds->all())->count();

    expect($existing)->toBe(
        $productIds->count(),
        'Every demo review must point at a product row that exists.'
    );

    // Text, not placeholders: this is what a shopper reads.
    $blank = $rows->filter(fn ($r) => trim((string) $r->content) === '')->count();

    expect($blank)->toBe(0, 'Every demo review must carry review text.');

    // Ratings inside the schema's tinyint range, and not all five stars — a
    // straight line of fives draws no distribution bars.
    expect($rows->pluck('rating')->min())->toBeGreaterThanOrEqual(1);
    expect($rows->pluck('rating')->max())->toBeLessThanOrEqual(5);
    expect($rows->pluck('rating')->unique()->count())->toBeGreaterThan(1);
});

it('brings the four homepage fixture reviews into the table', function () {
    dsClearDemoReviews();
    test()->seed(DemoReviewsSeeder::class);

    /*
     * These four are the reviews App\Services\DemoContent::reviews() has been
     * printing on the homepage as plain objects that are never written
     * anywhere. The owner asked for "the same demo reviews which are on
     * front-end" as real, assignable rows — so their presence is the request,
     * asserted by author rather than by a substring of the page, because the
     * homepage renders them from the fixtures too and a page assertion could
     * not tell the two apart.
     */
    foreach (['Kingsley C.', 'Houda A.', 'Jenifer L.', 'Rowena M.'] as $author) {
        $row = Review::query()
            ->where('source', DemoReviewsSeeder::SOURCE)
            ->where('author_name', $author)
            ->first();

        expect($row)->not->toBeNull("The homepage review by {$author} must exist as a row.");
        expect($row->product_id)->not->toBeNull("{$author}'s review must be assigned to a product.");
        expect(trim((string) $row->content))->not->toBe('', "{$author}'s review must carry its text.");
    }
});

it('leaves a few reviews in the moderation queue', function () {
    dsClearDemoReviews();
    test()->seed(DemoReviewsSeeder::class);

    $rows = Review::query()->where('source', DemoReviewsSeeder::SOURCE)->get();

    $pending = $rows->where('status', ReviewStatus::PENDING)->count();
    $approved = $rows->where('status', ReviewStatus::APPROVED)->count();

    // Something for Store -> Reviews to open on: an empty queue tells the owner
    // nothing about whether the screen works.
    expect($pending)->toBeGreaterThan(0, 'Some demo reviews must be left pending.');

    // ...but the storefront must still have reviews to show, so most are live.
    expect($approved)->toBeGreaterThan($pending, 'Most demo reviews must be approved.');

    // Only the schema's vocabulary. `rejected` is one screen's private spelling
    // and App\Support\ReviewStatus exists because it got stored once.
    $unknown = $rows->pluck('status')->unique()->reject(
        fn ($s) => in_array($s, ReviewStatus::ALL, true)
    )->values()->all();

    expect($unknown)->toBe([], 'Demo reviews must use only the canonical statuses.');
});

/* ------------------------------------- the cached pair agrees with the rows */

it('makes every product rating column agree with its approved reviews', function () {
    dsClearDemoReviews();
    test()->seed(DemoReviewsSeeder::class);

    $productIds = Review::query()
        ->where('source', DemoReviewsSeeder::SOURCE)
        ->pluck('product_id')
        ->unique()
        ->values();

    expect($productIds->count())->toBeGreaterThan(0);

    foreach ($productIds as $id) {
        $expected = dsApprovedAggregate((int) $id);
        $product = Product::query()->whereKey($id)->first(['id', 'rating', 'review_count']);

        expect((int) $product->review_count)->toBe(
            $expected['count'],
            "products.review_count for product {$id} must equal its approved review rows."
        );

        expect(round((float) $product->rating, 2))->toBe(
            $expected['average'],
            "products.rating for product {$id} must be the average of its approved reviews."
        );
    }
});

it('counts a demo review in neither the rating nor the count, approved or not', function () {
    /*
     * This test used to be about PENDING rows — it asserted that the cached
     * pair excluded a demo review that was awaiting moderation, which meant it
     * also asserted that an APPROVED demo review was included. That inclusion
     * is the defect. Approved-versus-pending maths is pinned on real rows in
     * RatingsTellTheTruthTest ('counts only approved reviews, not pending or
     * spam'), so nothing is lost by making this one about provenance instead.
     */
    dsClearDemoReviews();
    test()->seed(DemoReviewsSeeder::class);

    // A product the seeder gave APPROVED reviews to — the case that used to
    // produce a public star rating.
    $productId = (int) Review::query()
        ->where('source', DemoReviewsSeeder::SOURCE)
        ->where('status', ReviewStatus::APPROVED)
        ->value('product_id');

    expect($productId)->toBeGreaterThan(0);

    $demoApproved = Review::query()
        ->where('product_id', $productId)
        ->where('source', DemoReviewsSeeder::SOURCE)
        ->where('status', ReviewStatus::APPROVED)
        ->count();

    expect($demoApproved)->toBeGreaterThan(
        0,
        'This product must hold at least one approved demo review for the test to mean anything.'
    );

    $product = Product::query()->whereKey($productId)->first(['rating', 'review_count']);

    expect((int) $product->review_count)->toBe(0, 'Demo reviews must not be counted.');
    expect(round((float) $product->rating, 2))->toBe(0.0, 'Demo reviews must not produce a score.');

    /*
     * And the columns are not simply stuck at zero: one REAL review on the same
     * product moves both. Without this half the test would pass just as well
     * against a ProductRating that had been broken outright.
     */
    Review::create([
        'product_id' => $productId,
        'author_name' => 'A Real Shopper',
        'rating' => 4,
        'content' => 'Genuinely bought this.',
        'status' => ReviewStatus::APPROVED,
    ]);

    \App\Support\ProductRating::refresh([$productId]);

    $product = Product::query()->whereKey($productId)->first(['rating', 'review_count']);

    expect((int) $product->review_count)->toBe(1, 'A real review must still count.');
    expect(round((float) $product->rating, 2))->toBe(4.0);
});

/* ------------------------------------------------------------ idempotence */

it('adds nothing when it is run a second time', function () {
    dsClearDemoReviews();

    test()->seed(DemoReviewsSeeder::class);

    $first = Review::query()->where('source', DemoReviewsSeeder::SOURCE)->count();
    $firstIds = Review::query()->where('source', DemoReviewsSeeder::SOURCE)->pluck('id')->sort()->values();

    expect($first)->toBeGreaterThan(0);

    test()->seed(DemoReviewsSeeder::class);
    test()->seed(DemoReviewsSeeder::class);

    $after = Review::query()->where('source', DemoReviewsSeeder::SOURCE)->count();

    expect($after)->toBe($first, 'Re-running the seeder must not duplicate reviews.');

    // Not merely the same NUMBER of rows — the same rows. A seeder that deleted
    // and rewrote would keep the count and lose every moderation decision, every
    // reply and every helpful vote on them.
    $afterIds = Review::query()->where('source', DemoReviewsSeeder::SOURCE)->pluck('id')->sort()->values();

    expect($afterIds->all())->toBe($firstIds->all(), 'Re-running must leave the existing rows in place.');

    // And no product ends up counting the same review twice — the failure a
    // row-count check alone would miss if the seeder both added and removed.
    $productIds = Review::query()
        ->where('source', DemoReviewsSeeder::SOURCE)
        ->pluck('product_id')
        ->unique();

    foreach ($productIds as $id) {
        expect((int) Product::query()->whereKey($id)->value('review_count'))
            ->toBe(
                dsApprovedAggregate((int) $id)['count'],
                "products.review_count for product {$id} must not double after a re-run."
            );
    }
});

/* ------------------------------------------- the catalogue invents nothing */

it('no longer writes a rating the demo catalogue cannot account for', function () {
    /*
     * Rebuild the placeholder catalogue from nothing. firstOrCreate does not
     * update an existing row, so the products already in the test database
     * would report whatever they were seeded with and this would pass without
     * testing the change.
     *
     * Only wc_id IS NULL is removed — the scope the demo catalogue migration's
     * own down() uses. Nothing imported from WooCommerce is touched.
     */
    Product::query()->whereNull('wc_id')->forceDelete();

    expect(Product::query()->whereNull('wc_id')->count())->toBe(0);

    (new DemoCatalogueSeeder())->run();

    $seeded = Product::query()->whereNull('wc_id')->get(['id', 'rating', 'review_count']);

    expect($seeded->count())->toBeGreaterThan(0);

    /*
     * Not one of them may carry a score, because not one of them has a review.
     * This is the assertion the old seeder failed: it wrote up to 1,400 reviews
     * at up to five stars onto a product with an empty reviews table.
     */
    $invented = $seeded->filter(
        fn ($p) => (int) $p->review_count !== 0 || round((float) $p->rating, 2) !== 0.0
    );

    expect($invented->count())->toBe(
        0,
        'A freshly seeded demo product has no reviews, so it must carry no rating and no review count.'
    );

    expect(Review::query()->whereIn('product_id', $seeded->pluck('id'))->count())->toBe(0);
});

/* --------------------------------------------- what actually ships to the host */

it('seeds and rescores the placeholder catalogue when demo content is on', function () {
    /*
     * The seeder alone cannot fix the live site: the demo catalogue was seeded
     * there months ago and firstOrCreate never updates. This is the migration
     * that overwrites what is already in the database, exercised as the updater
     * will run it.
     *
     * demo_content is written through SettingsService, never Setting::
     * updateOrCreate — the service holds a forever-cache AND a per-process
     * static memo, so a direct write leaves both reporting the old value and
     * the migration would read `false` here no matter what the row said.
     */
    app(\App\Services\SettingsService::class)->set('demo_content', true);

    dsClearDemoReviews();

    // A placeholder product carrying exactly the kind of figure the old seeder
    // wrote: thousands of reviews, none of which exist.
    $liar = Product::query()->whereNull('wc_id')->orderBy('id')->firstOrFail();

    Product::query()->whereKey($liar->id)->update(['rating' => 4.9, 'review_count' => 3204]);

    // A real, imported product with its own WooCommerce rating meta and no
    // review rows. The migration must not touch this one.
    $imported = Product::create([
        'wc_id' => 987654,
        'slug' => 'ds-imported-' . uniqid(),
        'name' => 'DS Imported Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'rating' => 4.5,
        'review_count' => 88,
    ]);

    $migration = require base_path('database/migrations/2026_10_11_000002_seed_demo_reviews.php');
    $migration->up();

    $liar->refresh();

    // Either it now has real reviews behind its numbers, or it has no numbers.
    $expected = dsApprovedAggregate((int) $liar->id);

    expect((int) $liar->review_count)->toBe(
        $expected['count'],
        'A placeholder product must count only the approved reviews it actually has.'
    );

    expect(round((float) $liar->rating, 2))->toBe($expected['average']);

    expect((int) $liar->review_count)->not->toBe(3204, 'The invented count must be gone.');

    // The imported product is left exactly as it was: no wc_id, no opinion.
    $imported->refresh();

    expect((int) $imported->review_count)->toBe(88, 'An imported product must keep its own rating meta.');
    expect(round((float) $imported->rating, 2))->toBe(4.5);

    // And the reviews are there, so this was not achieved by zeroing everything.
    expect(Review::query()->where('source', DemoReviewsSeeder::SOURCE)->count())->toBeGreaterThan(0);
});

it('writes no demo reviews when the owner has demo content switched off', function () {
    /*
     * A store that turned demo content OFF has said it does not want invented
     * content on its storefront. Writing demo reviews into its `reviews` table
     * would put them on public product pages and into the schema.org
     * aggregateRating published to Google — real damage done by a preview aid.
     *
     * The rating fix is NOT conditional, though: a figure with no review behind
     * it is wrong either way, and that is the half the owner actually
     * complained about.
     */
    dsClearDemoReviews();

    // The default is off, but stated rather than assumed.
    app(\App\Services\SettingsService::class)->set('demo_content', false);

    $liar = Product::query()->whereNull('wc_id')->orderBy('id')->firstOrFail();

    Product::query()->whereKey($liar->id)->update(['rating' => 4.9, 'review_count' => 3204]);

    $migration = require base_path('database/migrations/2026_10_11_000002_seed_demo_reviews.php');
    $migration->up();

    expect(Review::query()->where('source', DemoReviewsSeeder::SOURCE)->count())
        ->toBe(0, 'Demo reviews must not be written when demo content is off.');

    $liar->refresh();

    expect((int) $liar->review_count)
        ->toBe(0, 'The invented count must be retired even with demo content off.');

    expect(round((float) $liar->rating, 2))->toBe(0.0);
});

it('removes only its own reviews when the migration is rolled back', function () {
    test()->seed(DemoReviewsSeeder::class);

    $product = Product::query()->whereNull('wc_id')->orderBy('id')->firstOrFail();

    // A review from a real shopper, on the same product, with a demo author's
    // name — the row a source-blind delete would take with it.
    $real = Review::create([
        'product_id' => $product->id,
        'author_name' => 'Aisha M.',
        'author_email' => 'a-real-shopper@example.test',
        'rating' => 4,
        'title' => 'DS real review',
        'content' => 'Written by an actual customer.',
        'status' => ReviewStatus::APPROVED,
        'source' => 'sorina',
    ]);

    expect(Review::query()->where('source', DemoReviewsSeeder::SOURCE)->count())->toBeGreaterThan(0);

    $migration = require base_path('database/migrations/2026_10_11_000002_seed_demo_reviews.php');
    $migration->down();

    expect(Review::query()->where('source', DemoReviewsSeeder::SOURCE)->count())
        ->toBe(0, 'Rolling back must remove every demo review.');

    expect(Review::query()->whereKey($real->id)->exists())
        ->toBeTrue('Rolling back must not remove a real customer review.');

    // The columns follow the rows down.
    expect((int) DB::table('products')->where('id', $product->id)->value('review_count'))
        ->toBe(dsApprovedAggregate((int) $product->id)['count']);
});
