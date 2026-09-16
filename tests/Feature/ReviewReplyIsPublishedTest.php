<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Review;
use App\Support\ProductRating;

/**
 * A reply the owner writes is shown to shoppers.
 *
 * The admin has always offered a reply box, stored what was typed, displayed it
 * on the moderation screen and included it in both CSV exports — and its
 * placeholder said "Shown publicly under the review". It was not.
 * Store\ProductController never SELECTed the column and
 * partials/reviews.blade.php never printed it, so no reply had ever been seen
 * by a shopper. The promise was in the admin and the delivery was nowhere.
 *
 * Two halves, and each fails silently without the other: a column that is not
 * selected reads as null in the view, and a view that does not print it
 * ignores a perfectly good column. So this asserts the rendered page, which is
 * the only thing that proves both.
 */
function replyProduct(): Product
{
    return Product::create([
        'slug' => 'reply-' . uniqid(),
        'name' => 'Reply Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
    ]);
}

it('prints the shop reply under the review it answers', function () {
    $product = replyProduct();

    Review::create([
        'product_id' => $product->id,
        'author_name' => 'Aisha K.',
        'rating' => 5,
        'content' => 'Lovely texture, absorbs fast.',
        'status' => 'approved',
        'reply' => 'Thank you Aisha, glad it suits you.',
    ]);

    ProductRating::refresh([$product->id]);

    $html = $this->get('/product/' . $product->slug . '/')->assertOk()->getContent();

    /*
     * The ELEMENT, not the class name. The product page inlines its own
     * stylesheet, so `sr-reply` appears on every page whether a reply rendered
     * or not — the trap that produced a false positive on this very screen's
     * verified-badge count earlier this week.
     */
    expect(preg_match('/<div class="sr-reply">(.*?)<\/div>/s', $html, $m))
        ->toBe(1, 'the shop reply is not rendered on the product page');

    expect(str_contains($m[1], 'glad it suits you'))
        ->toBeTrue('the reply element rendered but not the reply text');
});

it('renders nothing where there is no reply', function () {
    // An empty bordered block under every unanswered review would be worse
    // than the original bug.
    $product = replyProduct();

    Review::create([
        'product_id' => $product->id,
        'author_name' => 'Noor S.',
        'rating' => 4,
        'content' => 'Works well.',
        'status' => 'approved',
    ]);

    ProductRating::refresh([$product->id]);

    $html = $this->get('/product/' . $product->slug . '/')->assertOk()->getContent();

    expect(preg_match('/<div class="sr-reply">/', $html))
        ->toBe(0, 'an empty reply block rendered under a review with no reply');
});

it('escapes a reply rather than letting it write markup', function () {
    /*
     * The reply is typed by an admin, not a shopper, so this is a smaller
     * worry than the review body — but it is rendered on a public page and the
     * cost of being sure is one assertion.
     */
    $product = replyProduct();

    Review::create([
        'product_id' => $product->id,
        'author_name' => 'Hessa M.',
        'rating' => 5,
        'content' => 'Great.',
        'status' => 'approved',
        'reply' => 'Thanks! <script>alert(1)</script>',
    ]);

    ProductRating::refresh([$product->id]);

    $html = $this->get('/product/' . $product->slug . '/')->assertOk()->getContent();

    expect(str_contains($html, '<script>alert(1)</script>'))
        ->toBeFalse('a reply wrote raw markup onto the product page');
});
