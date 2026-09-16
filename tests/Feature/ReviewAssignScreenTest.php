<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\Cache;
use App\Support\ReviewStatus;
use Tests\Support\ReviewsScreensAdminRoutes;

/**
 * Reviews → Assign / Duplicate.
 *
 * WHAT THIS SCREEN REPLACED. An <iframe> to `kbb-admin-assign.html`, a
 * standalone file this repo has never shipped, so the screen printed the "isn't
 * installed yet" card.
 *
 * WHAT IS PINNED HARDEST HERE IS THE RATING MATHS, on both sides of every
 * write. `products.rating` and `products.review_count` are what the shop cards
 * print, what ShopController sorts `?sort=rating` by, and — through
 * Store\ProductController::reviewSummary() — what Seo publishes to Google as
 * aggregateRating. Moving a review changes the approved set of TWO products,
 * and an implementation that recomputed only the destination would leave the
 * product it came from advertising a score that includes a review it no longer
 * has. Every test below asserts both rows.
 *
 * THE COPY DEFAULT IS ALSO PINNED. A copy lands as pending, so nothing appears
 * on a product page until a human approves it. See
 * Admin\ReviewAssignApiController's own doc comment for why that is a
 * correctness question and not a preference.
 */

beforeEach(function () {
    app(\App\Services\SettingsService::class)->flush();
});

/* ------------------------------------------------------------------ fixtures */

function raAsAdmin(): void
{
    ReviewsScreensAdminRoutes::wire(app());

    test()->actingAs(AdminUser::create([
        'name' => 'RA Owner',
        'email' => 'ra-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
}

function raProduct(array $overrides = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'ra-product-' . $n . '-' . uniqid(),
        'name' => 'RA Product ' . $n,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 50.00,
        'stock_status' => 'instock',
    ], $overrides));
}

function raReview(?Product $product, int $rating = 5, string $status = ReviewStatus::APPROVED, array $overrides = []): Review
{
    static $n = 0;
    $n++;

    return Review::create(array_merge([
        'product_id' => $product?->id,
        'source' => 'kbb',
        'author_name' => 'RA Reviewer ' . $n,
        'author_email' => 'ra' . $n . '@example.test',
        'rating' => $rating,
        'title' => 'RA title ' . $n,
        'content' => 'RA body ' . $n,
        'status' => $status,
        'ip' => '203.0.113.11',
    ], $overrides));
}

/* --------------------------------------------------------------------- move */

it('moves a review to another product and fixes the score on BOTH products', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();

    // Two 5s and a 1 on the source; the destination starts empty.
    $five = raReview($from, 5);
    raReview($from, 5);
    raReview($to, 3);

    \App\Support\ProductRating::refresh([$from->id, $to->id]);

    expect((int) $from->fresh()->review_count)->toBe(2)
        ->and((int) $to->fresh()->review_count)->toBe(1);

    $response = test()->postJson('/admin-api/review-assign/move', [
        'ids' => [$five->id],
        'product_id' => $to->id,
    ]);

    $response->assertOk();

    expect($response->json('affected'))->toBe(1)
        ->and((int) $five->fresh()->product_id)->toBe($to->id);

    // The product it LEFT. This is the half an implementation forgets.
    expect((int) $from->fresh()->review_count)->toBe(1)
        ->and(round((float) $from->fresh()->rating, 2))->toEqual(5.0);

    // And the product it joined: a 3 and a 5 is 4.
    expect((int) $to->fresh()->review_count)->toBe(2)
        ->and(round((float) $to->fresh()->rating, 2))->toEqual(4.0);
});

it('reports the destination score back, so the screen can show what changed', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $review = raReview($from, 4);

    $response = test()->postJson('/admin-api/review-assign/move', ['ids' => [$review->id], 'product_id' => $to->id]);

    expect($response->json('product.id'))->toBe($to->id)
        ->and($response->json('product.review_count'))->toBe(1)
        ->and($response->json('product.rating'))->toEqual(4.0);
});

it('moves several reviews at once', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();

    $ids = [raReview($from, 5)->id, raReview($from, 5)->id, raReview($from, 5)->id];

    test()->postJson('/admin-api/review-assign/move', ['ids' => $ids, 'product_id' => $to->id])->assertOk();

    expect(Review::query()->where('product_id', $to->id)->count())->toBe(3)
        ->and((int) $to->fresh()->review_count)->toBe(3)
        ->and((int) $from->fresh()->review_count)->toBe(0);
});

it('leaves a pending review out of the score it moves into', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $pending = raReview($from, 1, ReviewStatus::PENDING);

    test()->postJson('/admin-api/review-assign/move', ['ids' => [$pending->id], 'product_id' => $to->id])->assertOk();

    // Every storefront reader filters on approved. A pending 1-star must not
    // pull the destination's public score down to 1.
    expect((int) $to->fresh()->review_count)->toBe(0)
        ->and(round((float) $to->fresh()->rating, 2))->toEqual(0.0);
});

it('can move a review off every product, as this schema\'s shop review', function () {
    raAsAdmin();

    $from = raProduct();
    $review = raReview($from, 5);

    test()->postJson('/admin-api/review-assign/move', [
        'ids' => [$review->id],
        'to_business' => '1',
    ])->assertOk();

    expect($review->fresh()->product_id)->toBeNull()
        ->and((int) $from->fresh()->review_count)->toBe(0)
        ->and(Review::query()->business()->count())->toBe(1);
});

it('refuses a destination product that does not exist rather than writing it', function () {
    raAsAdmin();

    $review = raReview(raProduct());

    test()->postJson('/admin-api/review-assign/move', [
        'ids' => [$review->id],
        'product_id' => 999999,
    ])->assertStatus(422);
});

it('says so when none of the reviews exist any more', function () {
    raAsAdmin();

    $to = raProduct();

    test()->postJson('/admin-api/review-assign/move', ['ids' => [987654], 'product_id' => $to->id])
        ->assertStatus(404);
});

/* --------------------------------------------------------------------- copy */

it('copies a review to another product, leaving the original where it was', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $review = raReview($from, 5);

    $response = test()->postJson('/admin-api/review-assign/copy', [
        'ids' => [$review->id],
        'product_id' => $to->id,
    ]);

    $response->assertOk();

    expect(Review::query()->count())->toBe(2)
        ->and((int) $review->fresh()->product_id)->toBe($from->id)
        ->and(Review::query()->where('product_id', $to->id)->count())->toBe(1);
});

it('lands a copy as pending, so nothing appears on the shop until a human approves it', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $review = raReview($from, 5, ReviewStatus::APPROVED);

    test()->postJson('/admin-api/review-assign/copy', ['ids' => [$review->id], 'product_id' => $to->id])->assertOk();

    $copy = Review::query()->where('product_id', $to->id)->first();

    expect($copy->status)->toBe(ReviewStatus::PENDING)
        // And therefore the destination's public score has NOT moved.
        ->and((int) $to->fresh()->review_count)->toBe(0);
});

it('keeps the original status only when explicitly asked to, and then moves the score', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $review = raReview($from, 5, ReviewStatus::APPROVED);

    test()->postJson('/admin-api/review-assign/copy', [
        'ids' => [$review->id],
        'product_id' => $to->id,
        'status' => 'keep',
    ])->assertOk();

    expect(Review::query()->where('product_id', $to->id)->first()->status)->toBe(ReviewStatus::APPROVED)
        ->and((int) $to->fresh()->review_count)->toBe(1)
        ->and(round((float) $to->fresh()->rating, 2))->toEqual(5.0);
});

it('does not carry the original\'s helpful votes onto the copy', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $review = raReview($from, 5, ReviewStatus::APPROVED, ['helpful' => 42]);

    test()->postJson('/admin-api/review-assign/copy', ['ids' => [$review->id], 'product_id' => $to->id])->assertOk();

    // Those votes were cast on the original, by people who read it there.
    expect((int) Review::query()->where('product_id', $to->id)->first()->helpful)->toBe(0)
        ->and((int) $review->fresh()->helpful)->toBe(42);
});

it('gives the copy a null source_id, so the unique key over (source, source_id) cannot collide', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $review = raReview($from, 5, ReviewStatus::APPROVED, ['source' => 'wp_comment', 'source_id' => 3131]);

    // Twice, which is the case a kept source_id would refuse outright — and
    // which would also make the next review import update the copy instead of
    // the original.
    test()->postJson('/admin-api/review-assign/copy', ['ids' => [$review->id], 'product_id' => $to->id])->assertOk();
    test()->postJson('/admin-api/review-assign/copy', ['ids' => [$review->id], 'product_id' => $to->id])->assertOk();

    $copies = Review::query()->where('product_id', $to->id)->get();

    expect($copies)->toHaveCount(2);

    foreach ($copies as $copy) {
        expect($copy->source_id)->toBeNull()->and($copy->source)->toBe('duplicate');
    }
});

/* ------------------------------------------------------------------- search */

it('finds reviews by author, by body, by product name and by id', function () {
    raAsAdmin();

    $product = raProduct(['name' => 'Heartleaf Toner']);
    $review = raReview($product, 5, ReviewStatus::APPROVED, [
        'author_name' => 'Fatima',
        'content' => 'the smell of jasmine',
    ]);

    foreach (['Fatima', 'jasmine', 'Heartleaf', (string) $review->id] as $term) {
        $found = test()->get('/admin-api/review-assign/reviews?q=' . urlencode($term))->json('reviews');

        expect($found)->not->toBeEmpty("searching for {$term} found nothing");
        expect(collect($found)->pluck('id'))->toContain($review->id);
    }
});

it('does not let a wildcard in the search term match the whole table', function () {
    raAsAdmin();

    $product = raProduct();
    raReview($product, 5, ReviewStatus::APPROVED, ['author_name' => 'Amal']);
    raReview($product, 5, ReviewStatus::APPROVED, ['author_name' => 'Noor']);

    // Unescaped, '%' matches every row — which the owner reads as "search is
    // broken", correctly.
    expect(test()->get('/admin-api/review-assign/reviews?q=%25')->json('reviews'))->toBeEmpty();
});

it('never hands the reviewer\'s email address or IP to this screen', function () {
    raAsAdmin();

    $product = raProduct();
    raReview($product, 5, ReviewStatus::APPROVED, [
        'author_email' => 'private-assign@example.test',
        'ip' => '198.51.100.77',
    ]);

    $body = test()->get('/admin-api/review-assign/reviews')->getContent();

    // Neither is needed to decide where a review belongs, and CLAUDE.md names
    // both as data that has already leaked from this table in production.
    expect($body)->not->toContain('private-assign@example.test')
        ->and($body)->not->toContain('198.51.100.77');
});

it('finds a destination product by name and by sku', function () {
    raAsAdmin();

    $product = raProduct(['name' => 'Glow Serum', 'sku' => 'GLOW-01']);

    foreach (['Glow Serum', 'GLOW-01'] as $term) {
        $found = test()->get('/admin-api/review-assign/products?q=' . urlencode($term))->json('products');

        expect(collect($found)->pluck('id'))->toContain($product->id);
    }
});

/* -------------------------------------------------------------------- bounds */

it('refuses a batch bigger than the ceiling rather than trying it', function () {
    raAsAdmin();

    $to = raProduct();

    test()->postJson('/admin-api/review-assign/move', [
        'ids' => range(1, 500),
        'product_id' => $to->id,
    ])->assertStatus(422);
});


/* ------------------------------------------------- the homepage review wall */

/**
 * The same obligation the moderation path has (Lane BD,
 * tests/Feature/ReviewModerationEvictsHomeWallTest.php): the homepage review
 * wall is cached for fifteen minutes and prints the product each approved
 * review belongs to. A move changes that link; a copy kept approved adds to the
 * set. Neither is visible to ProductRating::refresh(), which writes a different
 * reader entirely.
 */
it('drops the homepage review wall after a move', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $review = raReview($from, 5);

    Cache::put('kbb.home.reviews', ['stale'], 900);

    test()->postJson('/admin-api/review-assign/move', ['ids' => [$review->id], 'product_id' => $to->id])
        ->assertOk();

    expect(Cache::get('kbb.home.reviews'))->toBeNull();
});

it('drops the homepage review wall after a copy', function () {
    raAsAdmin();

    $from = raProduct();
    $to = raProduct();
    $review = raReview($from, 5);

    Cache::put('kbb.home.reviews', ['stale'], 900);

    test()->postJson('/admin-api/review-assign/copy', [
        'ids' => [$review->id], 'product_id' => $to->id, 'status' => 'keep',
    ])->assertOk();

    expect(Cache::get('kbb.home.reviews'))->toBeNull();
});
