<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\Cache;

/**
 * Moderating a review must drop the homepage's cached review wall.
 *
 * Store\HomeController wraps that wall in Cache::remember('kbb.home.reviews',
 * 900, ...) and it aggregates every APPROVED review shop-wide — exactly the set
 * moderation changes. Nothing evicted the key, so for up to fifteen minutes
 * after approving or rejecting a review the homepage showed one set of reviews
 * and the product pages another, with nothing on screen admitting it.
 *
 * ProductRating::refresh() does not cover this. That writes products.rating and
 * products.review_count, which is a different reader entirely; the wall is
 * built from the reviews table.
 *
 * Found by Lane BD while tracing what a bulk insert has to invalidate. It
 * reported rather than fixed it because ReviewsApiController belongs to another
 * lane.
 */
function modAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Wall Owner',
        'email' => 'wall-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function modProduct(): Product
{
    return Product::create([
        'slug' => 'wall-' . uniqid(),
        'name' => 'Wall Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 75.00,
        'stock_status' => 'instock',
        'rating' => 0,
        'review_count' => 0,
    ]);
}

function modReview(string $status = 'pending'): Review
{
    return Review::create([
        'product_id' => modProduct()->id,
        'author_name' => 'Wall Tester',
        'rating' => 5,
        'content' => 'Lovely',
        'status' => $status,
    ]);
}

it('drops the homepage review wall when a single review is moderated', function () {
    $review = modReview();

    Cache::put('kbb.home.reviews', ['stale'], 900);

    test()->actingAs(modAdmin(), 'admin')
        ->putJson("/admin-api/reviews/{$review->id}/moderate", ['status' => 'approved'])
        ->assertOk();

    expect(Cache::get('kbb.home.reviews'))->toBeNull();
});

it('drops it on the bulk path too', function () {
    $a = modReview();
    $b = modReview();

    Cache::put('kbb.home.reviews', ['stale'], 900);

    test()->actingAs(modAdmin(), 'admin')
        ->postJson('/admin-api/reviews/bulk-moderate', [
            'ids' => [$a->id, $b->id],
            'action' => 'approved',
        ])
        ->assertOk();

    expect(Cache::get('kbb.home.reviews'))->toBeNull();
});

it('leaves the wall alone when moderation changes nothing', function () {
    /*
     * A review already in the target status is left alone by the single-row
     * path, and the wall it feeds has not changed — so evicting there would be
     * throwing away a valid cache entry on every no-op click.
     */
    $review = modReview('approved');

    Cache::put('kbb.home.reviews', ['warm'], 900);

    test()->actingAs(modAdmin(), 'admin')
        ->putJson("/admin-api/reviews/{$review->id}/moderate", ['status' => 'approved'])
        ->assertOk();

    expect(Cache::get('kbb.home.reviews'))->toBe(['warm']);
});
