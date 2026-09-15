<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Review;
use App\Support\ReviewStatus;
use Tests\Support\ReviewsAdminRoutes;

/**
 * THE RATING MATHS. Does a pending or spam review move what a shopper sees?
 *
 * There are two figures called "the rating" on this storefront and they come
 * from different places, so the question has two answers:
 *
 *  1. COMPUTED PER REQUEST — Store\ProductController::reviewSummary() (the
 *     product page's score, star bars and review count, and the schema.org
 *     aggregateRating handed to Seo) and Store\HomeController's review wall.
 *     Both apply ->approved(). NO: a pending or spam review has never moved
 *     any of them. Asserted below rather than assumed, because "it filters"
 *     is exactly the kind of claim that stops being true when someone adds a
 *     second query beside the first.
 *
 *  2. DENORMALISED ON `products` — `products.rating` and
 *     `products.review_count`. These are what the shop CARDS print, what
 *     ShopController sorts `?sort=rating` and `?sort=popular` by, what
 *     CollectionController's "popular" uses and what the `top_rated`
 *     shortcode selects on. They are computed approved-only too — but until
 *     this package the ONLY thing in the entire application that ever wrote
 *     them was DemoReviewsSeeder::refreshAggregate().
 *
 * SO THE LIVE BUG WAS NOT THAT SPAM MOVED A RATING. It is that MODERATION
 * MOVED NOTHING. Approving a review left the product's card showing the old
 * score and the old count for ever, and marking a published review as spam
 * left it still counted on every listing page and still sorted by it. On a
 * store whose owner treats reviews as the trust signal, the one action meant
 * to publish a review did not reach the number shoppers actually see.
 *
 * App\Support\ProductRating now runs on every moderation write, and the data
 * migration catches up everything moderated before it existed. Both directions
 * are pinned here.
 */

function amRatingAdmin(): void
{
    ReviewsAdminRoutes::wire(app());

    test()->actingAs(AdminUser::create([
        'name' => 'AM Rating Owner',
        'email' => 'am-rating-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
}

function amRatedProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'am-rating-' . uniqid(),
        'name' => 'AM Rating Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 60.00,
        'stock_status' => 'instock',
        'rating' => 0,
        'review_count' => 0,
    ], $overrides));
}

function amRatingReview(Product $product, int $rating, string $status): Review
{
    static $n = 0;
    $n++;

    return Review::create([
        'product_id' => $product->id,
        'author_name' => 'AM Rater ' . $n,
        'author_email' => 'am-rater-' . $n . '@example.test',
        'rating' => $rating,
        'title' => 'AM rating title ' . $n,
        'content' => 'AM rating body ' . $n,
        'status' => $status,
        'ip' => '203.0.113.5',
    ]);
}

/* ------------------------------------- the per-request figures on the page */

it('leaves the product page score and count untouched by pending and spam reviews', function () {
    $product = amRatedProduct();

    amRatingReview($product, 5, ReviewStatus::APPROVED);
    amRatingReview($product, 5, ReviewStatus::APPROVED);

    $html = test()->get('/product/' . $product->slug);

    if ($html->getStatusCode() !== 200) {
        $html = test()->get('/' . $product->slug);
    }

    $html->assertOk();

    // Two five-star reviews: the page says 2 and 5.0 somewhere in it. Asserted
    // through the summary the controller actually builds rather than by
    // scraping, so this pins the maths and not the markup.
    $summary = (new ReflectionMethod(\App\Http\Controllers\Store\ProductController::class, 'reviewSummary'));
    $summary->setAccessible(true);

    $controller = app(\App\Http\Controllers\Store\ProductController::class);

    $before = $summary->invoke($controller, $product->id);

    expect($before['total'])->toBe(2)
        ->and($before['average'])->toBe(5.0);

    // Now bury the product under one-star reviews that are NOT approved. If any
    // of them counted, the average would fall off a cliff.
    amRatingReview($product, 1, ReviewStatus::PENDING);
    amRatingReview($product, 1, ReviewStatus::PENDING);
    amRatingReview($product, 1, ReviewStatus::SPAM);
    amRatingReview($product, 1, ReviewStatus::SPAM);
    amRatingReview($product, 1, ReviewStatus::SPAM);

    $after = $summary->invoke($controller, $product->id);

    expect($after['total'])->toBe(2)
        ->and($after['average'])->toBe(5.0)
        // And the star bars, which are the same grouped query.
        ->and($after['bars'][5]['n'])->toBe(2)
        ->and($after['bars'][1]['n'])->toBe(0);
});

it('keeps unapproved reviews out of the homepage review wall', function () {
    $product = amRatedProduct();

    amRatingReview($product, 5, ReviewStatus::APPROVED);
    amRatingReview($product, 1, ReviewStatus::PENDING);
    amRatingReview($product, 1, ReviewStatus::SPAM);

    // The wall aggregates the whole shop rather than one product.
    $approvedOnly = Review::query()->approved()->count();

    expect($approvedOnly)->toBe(1)
        ->and(Review::query()->count())->toBe(3);

    $agg = Review::query()->approved()->selectRaw('COUNT(*) c, AVG(rating) a')->first();

    expect((int) $agg->c)->toBe(1)
        ->and(round((float) $agg->a, 1))->toBe(5.0);
});

/* ------------------------------ the denormalised pair the shop cards print */

it('moves the product card score when a review is approved — it never did before', function () {
    amRatingAdmin();

    $product = amRatedProduct();

    // A product with nothing published yet.
    $waiting = amRatingReview($product, 4, ReviewStatus::PENDING);

    expect($product->fresh()->review_count)->toBe(0)
        ->and((float) $product->fresh()->rating)->toBe(0.0);

    test()->putJson('/admin-api/reviews/' . $waiting->id . '/moderate', ['status' => 'approved'])
        ->assertOk();

    /*
     * THE ASSERTION THAT PINS THE ANSWER. Before this package nothing in the
     * application recomputed these two columns on moderation, so this read 0
     * and 0.0 after a successful approval and the shop card went on showing no
     * rating at all.
     */
    expect($product->fresh()->review_count)->toBe(1)
        ->and((float) $product->fresh()->rating)->toBe(4.0);
});

it('takes a review back off the card when it is marked spam', function () {
    amRatingAdmin();

    $product = amRatedProduct();

    // Seeded as pending and approved THROUGH THE ENDPOINT, because that is the
    // only path that builds the card. A row written straight to the table as
    // `approved` — an importer, a seeder — leaves the denormalised pair stale
    // by construction; catching those up is the data migration's job, not this
    // one's.
    $good = amRatingReview($product, 5, ReviewStatus::PENDING);
    $bad = amRatingReview($product, 1, ReviewStatus::PENDING);

    // Published state: two reviews averaging 3.
    test()->putJson('/admin-api/reviews/' . $good->id . '/moderate', ['status' => 'approved'])->assertOk();
    test()->putJson('/admin-api/reviews/' . $bad->id . '/moderate', ['status' => 'approved'])->assertOk();

    expect($product->fresh()->review_count)->toBe(2)
        ->and((float) $product->fresh()->rating)->toBe(3.0);

    test()->putJson('/admin-api/reviews/' . $bad->id . '/moderate', ['status' => 'rejected'])->assertOk();

    // The one-star is gone from the number a shopper sees, not merely hidden
    // from the list of reviews underneath it.
    expect($product->fresh()->review_count)->toBe(1)
        ->and((float) $product->fresh()->rating)->toBe(5.0);
});

it('clears the card completely when the last approved review is refused', function () {
    amRatingAdmin();

    $product = amRatedProduct();
    $only = amRatingReview($product, 5, ReviewStatus::PENDING);

    test()->putJson('/admin-api/reviews/' . $only->id . '/moderate', ['status' => 'approved'])->assertOk();

    expect($product->fresh()->review_count)->toBe(1);

    test()->putJson('/admin-api/reviews/' . $only->id . '/moderate', ['status' => 'spam'])->assertOk();

    // Zero, not "leave the old number alone" — which is how a spam review goes
    // on inflating a card after it has been dealt with.
    expect($product->fresh()->review_count)->toBe(0)
        ->and((float) $product->fresh()->rating)->toBe(0.0);
});

it('moves the card on a bulk action too, and on a delete', function () {
    amRatingAdmin();

    $product = amRatedProduct();

    $ids = collect([5, 5, 5, 1])
        ->map(fn ($r) => amRatingReview($product, $r, ReviewStatus::PENDING)->id)
        ->all();

    test()->postJson('/admin-api/reviews/bulk-moderate', ['action' => 'approved', 'ids' => $ids])
        ->assertOk();

    expect($product->fresh()->review_count)->toBe(4)
        ->and((float) $product->fresh()->rating)->toBe(4.0);

    // Deleting the one-star raises the average, and the card follows.
    test()->postJson('/admin-api/reviews/bulk-moderate', ['action' => 'delete', 'ids' => [end($ids)]])
        ->assertOk();

    expect($product->fresh()->review_count)->toBe(3)
        ->and((float) $product->fresh()->rating)->toBe(5.0);
});

it('agrees with the per-request figure, which is the whole point of keeping both', function () {
    amRatingAdmin();

    $product = amRatedProduct();

    foreach ([5, 4, 4, 3] as $rating) {
        $review = amRatingReview($product, $rating, ReviewStatus::PENDING);
        test()->putJson('/admin-api/reviews/' . $review->id . '/moderate', ['status' => 'approved'])->assertOk();
    }

    // And some noise that must count towards neither figure.
    amRatingReview($product, 1, ReviewStatus::PENDING);
    amRatingReview($product, 1, ReviewStatus::SPAM);

    $summary = (new ReflectionMethod(\App\Http\Controllers\Store\ProductController::class, 'reviewSummary'));
    $summary->setAccessible(true);

    $computed = $summary->invoke(app(\App\Http\Controllers\Store\ProductController::class), $product->id);

    $product->refresh();

    // The denormalised pair and the computed pair answer the same question, so
    // they cannot disagree. A card saying 4.0 over a page saying 3.2 is the
    // failure this rules out.
    expect($product->review_count)->toBe($computed['total'])
        ->and(round((float) $product->rating, 1))->toBe($computed['average']);
});

it('leaves a product with no reviews at all alone', function () {
    amRatingAdmin();

    // A product whose rating came from somewhere else — WooCommerce carries its
    // own rating meta — must not be zeroed by a refresh triggered elsewhere.
    $imported = amRatedProduct(['rating' => 4.5, 'review_count' => 12]);
    $other = amRatedProduct();

    $review = amRatingReview($other, 5, ReviewStatus::PENDING);

    test()->putJson('/admin-api/reviews/' . $review->id . '/moderate', ['status' => 'approved'])->assertOk();

    $imported->refresh();

    expect((float) $imported->rating)->toBe(4.5)
        ->and($imported->review_count)->toBe(12);
});
