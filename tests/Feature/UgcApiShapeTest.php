<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\UgcVideo;

/**
 * The allowlist a public rail will read from, pinned before anything reads it.
 *
 * ── WHY THIS EXISTS A ROUND EARLY ───────────────────────────────────────────
 *
 * /api/* on this shop is unauthenticated and every endpoint there is public.
 * CLAUDE.md's landmine list names three tables that leaked in production — a
 * product's wc_id, sku and total_sales; a review's author_email and ip; a
 * setting's admin_path and indexnow_key — and each of them leaked the same way:
 * a controller returned a MODEL. tests/Feature/ApiSecurityTest.php pins all of
 * it because each case was a real incident.
 *
 * Nothing serves UgcVideo::toApi() yet; the rail is the next round's. It is
 * written and pinned now so that round has an allowlist to reach for instead of
 * a model, and so the key that must never appear cannot appear by being added
 * to the table later — which is the whole argument for an allowlist over
 * $hidden.
 */
function ugcApiRow(): UgcVideo
{
    $video = UgcVideo::create([
        'slug' => 'api-'.uniqid(),
        'title' => 'Layla tries the snail essence',
        'caption' => 'Three weeks in',
        'status' => 'publish',
        'rights_status' => 'granted',
        'rights_granted_at' => now(),
        'rights_evidence' => 'DM from @layla.skin, 3 March, screenshot in Drive',
        'file_path' => '/uploads/ugc/clip-20270110-000000-aaaaaaaaaa.mp4',
        'poster_path' => '/uploads/ugc/poster-20270110-000000-aaaaaaaaaa.jpg',
        'teaser_path' => '/uploads/ugc/teaser-20270110-000000-aaaaaaaaaa.mp4',
        'creator_handle' => '@layla.skin',
        'creator_url' => 'https://www.instagram.com/layla.skin/',
        'source_url' => 'https://www.instagram.com/p/Cabc123/',
        'source_platform' => 'instagram',
        'position' => 3,
        'locale' => 'en',
        'width' => 720, 'height' => 1280, 'duration_ms' => 15000,
        'bytes' => 1598325, 'teaser_bytes' => 131895, 'poster_bytes' => 22014,
        'published_at' => now()->subDay(),
    ]);

    $product = Product::create([
        'slug' => 'api-p-'.uniqid(), 'name' => 'Snail Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 12300, 'stock_status' => 'instock',
        'sku' => 'SNAIL-96', 'total_sales' => 412, 'wc_id' => 9182,
    ]);

    $video->products()->attach($product->id, ['position' => 0]);

    return $video->fresh();
}

it('returns exactly the keys §7 names and no others', function () {
    /*
     * toBe on the key list, not toHaveKeys: the point of an allowlist is that a
     * column added next year is invisible by DEFAULT, and only an exact
     * comparison catches a key that was added rather than one that went
     * missing.
     *
     * MUTATION NOTE. Add 'position' => $this->position to toApi() and this is
     * red. Replace the whole method body with $this->toArray() and it is red
     * with rights_evidence in the list. RUN: both.
     */
    $api = ugcApiRow()->toApi();

    expect(array_keys($api))->toBe([
        'slug', 'title', 'caption', 'poster', 'src', 'teaser', 'width', 'height',
        'duration_ms', 'creator_handle', 'creator_url', 'source_url', 'published_at', 'products',
    ]);
});

it('never returns the rights record, the editorial state or a second timestamp', function () {
    /*
     * §7 names these by name: rights_status, rights_evidence, rights_granted_at,
     * status, position, the uploading admin, any filesystem path and any
     * timestamp that is not published_at. rights_evidence is the worst of them
     * — it is where a creator's private message is recorded.
     */
    $json = json_encode(ugcApiRow()->toApi());

    foreach (['rights_evidence', 'rights_status', 'rights_granted_at', 'DM from',
        'created_at', 'updated_at', 'position'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

it('never leaks it through a bare json encode of the model either', function () {
    /*
     * The second lock, and the reason Review::$hidden exists: the endpoints
     * that leaked in production leaked by returning whole models. An allowlist
     * is the guard; $hidden is what makes the careless path safe as well.
     *
     * MUTATION NOTE. Empty UgcVideo::$hidden and this is red while the
     * allowlist test above stays green — which is exactly the gap this case
     * covers.
     */
    $json = json_encode(ugcApiRow());

    foreach (['rights_evidence', 'rights_status', 'rights_granted_at', 'DM from'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

it('sends its products through the existing product allowlist, not a second one', function () {
    /*
     * Product::toApi() is already what keeps wc_id, sku and total_sales off the
     * wire, and a copy of that list here would be a copy that stops being
     * updated. The three columns below are the ones ApiSecurityTest exists
     * because of.
     *
     * MUTATION NOTE. Map the products with ->toArray() instead of ->toApi() and
     * this is red with all three in the payload. RUN.
     */
    $video = ugcApiRow();
    $video->load('products');

    $json = json_encode($video->toApi()['products']);

    expect($json)->toContain('Snail Essence')
        ->and($json)->not->toContain('SNAIL-96')
        ->and($json)->not->toContain('total_sales')
        ->and($json)->not->toContain('wc_id');
});

it('answers an empty product list rather than a query when nothing is loaded', function () {
    /*
     * toApi() is called per tile on a rail of twelve. Reaching for the relation
     * lazily there is the N+1 StorefrontQueryBudgetTest exists to refuse, so it
     * reports what was EAGER-LOADED and nothing else — the caller does
     * with('products.brand'), as §4 says, and gets three queries rather than
     * twenty-five.
     */
    $video = ugcApiRow();

    expect($video->relationLoaded('products'))->toBeFalse()
        ->and($video->toApi()['products'])->toBe([]);
});

it('carries the teaser and the box dimensions a rail needs to reserve its space', function () {
    // §2 budgets layout shift at 0 and no JavaScript in this project measures
    // layout. The tile reserves from these two, so they are on the feed.
    $api = ugcApiRow()->toApi();

    expect($api['width'])->toBe(720)
        ->and($api['height'])->toBe(1280)
        ->and($api['teaser'])->toEndWith('.mp4')
        ->and($api['poster'])->toEndWith('.jpg');
});
