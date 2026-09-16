<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Review;
use App\Support\ProductRating;

/**
 * A product shows a rating only when real reviews say so.
 *
 * The owner reported the product page advertising "4.9 · 3,204 reviews" while
 * the admin correctly said no product had a single approved review. Two causes,
 * both fixed and both pinned here:
 *
 *   1. DemoCatalogueSeeder wrote mt_rand(4, 1400) into products.review_count
 *      and a random 3.8-5.0 into products.rating. Those columns are what the
 *      shop cards, the grid, quick view and the rating sorts all print.
 *   2. The product page fell back to those columns whenever the live review
 *      summary was empty, so even a corrected column could be spoken over by a
 *      stale one.
 *
 * Google was never told the invented numbers — ProductController hands Seo the
 * live summary — so this was a lie to shoppers only, on a page asking them for
 * money.
 */
function truthProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'truth-' . uniqid(),
        'name' => 'Truth Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ], $overrides));
}

it('shows no rating on a product with no approved reviews, whatever the column says', function () {
    /*
     * The column is deliberately set to the seeded fiction, because a
     * corrected database is not what protects this — the view must not reach
     * for that column at all.
     */
    $product = truthProduct(['rating' => 4.9, 'review_count' => 3204]);

    $html = $this->get('/product/' . $product->slug . '/')
        ->assertOk()
        ->getContent();

    expect(str_contains($html, '3,204'))->toBeFalse('the fabricated count reached the page');

    /*
     * The ELEMENT, not the class name. `sr-capbar` also appears in the
     * stylesheet this page inlines, so a bare string search matches on every
     * page whether the capsule rendered or not — the same trap
     * ProductMobileLayoutTest's header warns about and Lane BB walked into
     * this session. Only an opening <a> carrying the class counts.
     */
    expect(preg_match('/<a[^>]*class="[^"]*sr-capbar/', $html))
        ->toBe(0, 'the rating capsule rendered with no reviews behind it');
});

it('shows the real numbers once real reviews exist', function () {
    $product = truthProduct();

    foreach ([5, 4] as $stars) {
        Review::create([
            'product_id' => $product->id,
            'author_name' => 'Shopper',
            'rating' => $stars,
            'content' => 'Genuine',
            'status' => 'approved',
        ]);
    }

    ProductRating::refresh([$product->id]);

    $html = $this->get('/product/' . $product->slug . '/')->assertOk()->getContent();

    // Two reviews averaging 4.5 — and the capsule appears because there is
    // something true to put in it.
    expect(preg_match('/<a[^>]*class="[^"]*sr-capbar/', $html))
        ->toBe(1, 'the capsule is missing with real reviews present');
    expect(str_contains($html, '4.5'))->toBeTrue('the real average is missing');
});

it('counts only approved reviews, not pending or spam', function () {
    /*
     * The other half of "only those products show actual reviews": a review
     * waiting for the owner must not inflate the number, or the moderation
     * queue becomes a way of publishing without approving.
     */
    $product = truthProduct();

    foreach (['approved', 'pending', 'spam'] as $status) {
        Review::create([
            'product_id' => $product->id,
            'author_name' => 'Shopper',
            'rating' => 5,
            'content' => 'Lovely',
            'status' => $status,
        ]);
    }

    ProductRating::refresh([$product->id]);

    expect((int) $product->fresh()->review_count)->toBe(1);
});

it('recomputes the seeded columns to what the reviews table actually holds', function () {
    /*
     * What the migration does, asserted through the same helper it calls. A
     * product carrying invented numbers and no reviews comes back to zero; the
     * moment one is approved, its numbers return on their own.
     */
    $product = truthProduct(['rating' => 4.9, 'review_count' => 3204]);

    ProductRating::refresh([$product->id]);

    $fresh = $product->fresh();

    expect((int) $fresh->review_count)->toBe(0)
        ->and((float) $fresh->rating)->toBe(0.0);
});
