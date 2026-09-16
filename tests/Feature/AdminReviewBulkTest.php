<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\ReviewStatus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ReviewBulkAdminRoutes;

/**
 * Store → Reviews → Bulk Add and Store → Reviews → Bulk Likes. (Lane BD)
 *
 * These endpoints manufacture review rows and manufacture the "helpful" counts
 * on them. The owner asked for both after being told what they are. What this
 * file pins is that the tool is CORRECT, because the ways it can be quietly
 * wrong are unusually expensive here:
 *
 *   THE RECOMPUTATION. `products.rating` and `products.review_count` are
 *   denormalised and nothing recomputes them on its own — the only writers in
 *   the application are App\Support\ProductRating::refresh() and the demo
 *   seeder. A bulk insert of APPROVED reviews that skipped refresh() would
 *   leave the product page and the aggregateRating handed to Google showing one
 *   number while every shop card, the ?sort=rating listing and the `top_rated`
 *   shortcode showed the number from before the insert — for ever, with no
 *   error anywhere. The two figures are asserted to AGREE, from both
 *   directions, rather than each being asserted to be "right".
 *
 *   THE GUARD. `reviews` rows feed a public product page. Mounted outside
 *   `auth:admin` this is an anonymous stranger writing text onto the storefront
 *   and into its structured data. Every route is asserted against an anonymous
 *   caller, a signed-in storefront shopper AND a plain `web` user — and the
 *   middleware is read back off the REGISTERED routes, because
 *   RouteRegistrar::middleware() replaces rather than appends and a harness
 *   that gets that wrong makes every 401 assertion pass against nothing.
 *
 *   THE BOUNDS. One request may not insert an unbounded number of rows.
 *
 *   THE DATES. A review dated in the future sorts above every real one.
 */

/* ------------------------------------------------------------------ fixtures */

function bdAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'BD Owner',
        'email' => 'bd-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function bdAsAdmin(): void
{
    ReviewBulkAdminRoutes::wire(app());
    test()->actingAs(bdAdmin(), 'admin');
}

function bdProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'bd-bulk-' . uniqid(),
        'name' => 'BD Bulk Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 75.00,
        'stock_status' => 'instock',
        'rating' => 0,
        'review_count' => 0,
    ], $overrides));
}

/** A legal Bulk Add payload, with whatever the caller wants changed. */
function bdAddPayload(array $overrides = []): array
{
    return array_merge([
        'product_ids' => [],
        'per_product' => 4,
        'status' => ReviewStatus::PENDING,
        'ratings' => [5 => 1],
        'authors' => ['BD One', 'BD Two'],
        'bodies' => ['A body written by the owner.', 'Another body written by the owner.'],
    ], $overrides);
}

function bdReview(Product $product, int $rating, string $status, int $helpful = 0): Review
{
    static $n = 0;
    $n++;

    return Review::create([
        'product_id' => $product->id,
        'author_name' => 'BD Reviewer ' . $n,
        'author_email' => 'bd-reviewer-' . $n . '@example.test',
        'rating' => $rating,
        'title' => 'BD title ' . $n,
        'content' => 'BD body ' . $n,
        'status' => $status,
        'helpful' => $helpful,
        'ip' => '203.0.113.9',
    ]);
}

/**
 * The product page's own figure, through the method the page and Seo both use,
 * rather than by re-implementing the query in the test.
 */
function bdPageSummary(int $productId): array
{
    $method = new ReflectionMethod(\App\Http\Controllers\Store\ProductController::class, 'reviewSummary');
    $method->setAccessible(true);

    return $method->invoke(app(\App\Http\Controllers\Store\ProductController::class), $productId);
}

/* --------------------------------------------------------------- the guard */

it('puts every bulk-review route behind auth:admin', function () {
    ReviewBulkAdminRoutes::wire(app());

    $routes = ReviewBulkAdminRoutes::registered();

    // Three: options, add, likes. If the file grows a fourth, this fails until
    // somebody looks at it, which is the point.
    expect($routes)->toHaveCount(3);

    foreach ($routes as $route) {
        expect($route->middleware())->toContain('auth:admin')
            ->and($route->middleware())->toContain('web');
    }
});

it('refuses an anonymous caller on every bulk-review route', function () {
    ReviewBulkAdminRoutes::wire(app());

    test()->getJson('/admin-api/review-bulk/options')->assertUnauthorized();
    test()->postJson('/admin-api/review-bulk/add', bdAddPayload())->assertUnauthorized();
    test()->postJson('/admin-api/review-bulk/likes', ['scope' => 'ids', 'mode' => 'add', 'min' => 1, 'max' => 1, 'ids' => [1]])
        ->assertUnauthorized();
});

it('refuses a signed-in storefront shopper', function () {
    ReviewBulkAdminRoutes::wire(app());

    $shopper = Customer::create([
        'email' => 'bd-shopper-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
        'first_name' => 'BD',
        'last_name' => 'Shopper',
    ]);

    test()->actingAs($shopper, 'customer');

    test()->getJson('/admin-api/review-bulk/options')->assertUnauthorized();
    test()->postJson('/admin-api/review-bulk/add', bdAddPayload())->assertUnauthorized();
});

it('refuses a plain web user who is not an admin', function () {
    ReviewBulkAdminRoutes::wire(app());

    $user = User::create([
        'name' => 'BD Web',
        'email' => 'bd-web-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
    ]);

    test()->actingAs($user, 'web');

    test()->getJson('/admin-api/review-bulk/options')->assertUnauthorized();
    test()->postJson('/admin-api/review-bulk/add', bdAddPayload())->assertUnauthorized();
});

it('keeps the bulk-review routes out of the public api file', function () {
    $api = (string) file_get_contents(base_path('routes/api.php'));

    // CLAUDE.md: everything under /api/* is unauthenticated by design. An
    // unauthenticated endpoint that writes review rows is a stranger's
    // content-injection vector into the storefront and into its structured data.
    expect($api)->not->toContain('ReviewBulkApiController')
        ->and($api)->not->toContain('review-bulk');

    $header = (string) file_get_contents(base_path('routes/review-bulk-admin.php'));

    expect($header)->toContain('auth:admin');
});

/* ---------------------------------------------------------------- creating */

it('creates the number of reviews asked for, against each product named', function () {
    bdAsAdmin();

    $a = bdProduct();
    $b = bdProduct();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$a->id, $b->id],
        'per_product' => 3,
    ]))->assertOk()->assertJson(['ok' => true, 'created' => 6]);

    expect(Review::query()->where('product_id', $a->id)->count())->toBe(3)
        ->and(Review::query()->where('product_id', $b->id)->count())->toBe(3);
});

it('tags every bulk-created row so it can be found again', function () {
    bdAsAdmin();

    $product = bdProduct();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 2,
    ]))->assertOk()->assertJson(['source' => 'admin_bulk']);

    /*
     * `reviews.source` already distinguishes 'sorina' (the WordPress import),
     * 'wp_comment' and 'kbb' (Store\ReviewController::submit()). This value is
     * the only thing that tells anyone later which rows came from this screen
     * rather than from a customer, and it is published by
     * ReviewsApiController::show() and the reviews CSV export, so it is
     * visible where somebody would actually look.
     */
    expect(Review::query()->where('source', 'admin_bulk')->count())->toBe(2)
        ->and(Review::query()->where('source', 'kbb')->count())->toBe(0);
});

it('writes no reviewer email and no IP, because there is no person and no request', function () {
    bdAsAdmin();

    $product = bdProduct();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 3,
    ]))->assertOk();

    /*
     * CLAUDE.md names author_email and ip as the two columns on this table that
     * leaked in production. Inventing plausible values for them would put fake
     * PII into the one field the owner uses to recognise a real repeat reviewer
     * — and into the field used to trace abuse. Both stay at the schema's own
     * default of ''.
     */
    foreach (Review::query()->where('source', 'admin_bulk')->get() as $row) {
        expect($row->author_email)->toBe('')
            ->and($row->ip)->toBe('');
    }
});

it('defaults to the status a real submission gets', function () {
    bdAsAdmin();

    $product = bdProduct();

    // Store\ReviewController::submit() writes 'pending'. The screen's default is
    // the same value, so an owner who does not touch the control gets the same
    // treatment a shopper's review gets.
    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 2,
        'status' => ReviewStatus::PENDING,
    ]))->assertOk()->assertJson(['status' => ReviewStatus::PENDING]);

    expect(Review::query()->where('status', ReviewStatus::PENDING)->count())->toBe(2);
});

it('refuses a status outside the two it is meaningful to create in', function () {
    bdAsAdmin();

    $product = bdProduct();

    // `spam` is a moderation outcome, not a thing to create a review as.
    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'status' => ReviewStatus::SPAM,
    ]))->assertStatus(422);

    expect(Review::query()->count())->toBe(0);
});

it('draws the star ratings from the mix it was given', function () {
    bdAsAdmin();

    $product = bdProduct();

    // A mix with a single non-zero weight is the only distribution assertable
    // without a statistical test: every row must be that star.
    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 12,
        'ratings' => [4 => 5],
    ]))->assertOk();

    expect(Review::query()->pluck('rating')->unique()->values()->all())->toBe([4]);
});

it('refuses a star mix that is entirely zero rather than inventing one', function () {
    bdAsAdmin();

    $product = bdProduct();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'ratings' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
    ]))->assertStatus(422)->assertJsonValidationErrors(['ratings']);
});

it('strips markup out of the text it stores', function () {
    bdAsAdmin();

    $product = bdProduct();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 1,
        'authors' => ['<script>alert(1)</script>Mariam'],
        'bodies' => ['<img src=x onerror=alert(1)>Lovely texture.'],
        'titles' => ['<b>Great</b>'],
    ]))->assertOk();

    $row = Review::query()->first();

    // Same treatment Store\ReviewController::submit() gives shopper text: this
    // is printed on a public product page and an admin textarea is not a
    // sanitiser.
    expect($row->author_name)->not->toContain('<script')
        ->and($row->content)->not->toContain('<img')
        ->and($row->title)->toBe('Great');
});

/* --------------------------------------------------------- THE RECOMPUTATION */

it('recomputes the product card figures when it creates approved reviews', function () {
    bdAsAdmin();

    $product = bdProduct();

    expect($product->fresh()->review_count)->toBe(0)
        ->and((float) $product->fresh()->rating)->toBe(0.0);

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 4,
        'status' => ReviewStatus::APPROVED,
        'ratings' => [5 => 1],
    ]))->assertOk();

    /*
     * THE ASSERTION THIS FILE EXISTS FOR. Nothing in the application recomputes
     * `products.rating` / `products.review_count` on its own. Without the
     * ProductRating::refresh() call in the controller this reads 0 and 0.0
     * after a successful insert of four approved five-star reviews, and the
     * shop card goes on showing no rating at all while the product page shows
     * 5.0 from 4 reviews.
     */
    expect($product->fresh()->review_count)->toBe(4)
        ->and((float) $product->fresh()->rating)->toBe(5.0);
});

it('leaves the product card figures agreeing with the product page, both ways', function () {
    bdAsAdmin();

    $product = bdProduct();

    // A real approved review first, so the starting figures are not zero and a
    // refresh that simply wrote the batch's own numbers would be caught.
    bdReview($product, 3, ReviewStatus::APPROVED);

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 3,
        'status' => ReviewStatus::APPROVED,
        'ratings' => [5 => 1],
    ]))->assertOk();

    $card = $product->fresh();
    $page = bdPageSummary($product->id);

    /*
     * The two figures must be the SAME NUMBER. (3 + 5 + 5 + 5) / 4 = 4.5.
     *
     * reviewSummary() rounds to 1dp and ProductRating::refresh() to 2, so they
     * are compared at the coarser precision — the point is that they describe
     * the same set of rows, not that two roundings match to the digit.
     */
    expect($card->review_count)->toBe($page['total'])
        ->and(round((float) $card->rating, 1))->toBe($page['average'])
        ->and($page['average'])->toBe(4.5);
});

it('does not move the product card figures when the reviews land pending', function () {
    bdAsAdmin();

    $product = bdProduct();

    bdReview($product, 5, ReviewStatus::APPROVED);

    // The one approved review already there, recomputed by the moderation lane's
    // own path or by this one; either way the starting point is 1 / 5.0.
    \App\Support\ProductRating::refresh([$product->id]);

    expect($product->fresh()->review_count)->toBe(1);

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 20,
        'status' => ReviewStatus::PENDING,
        'ratings' => [1 => 1],
    ]))->assertOk();

    /*
     * Twenty one-star reviews that are NOT approved. If any of them counted,
     * the average would fall off a cliff. The refresh runs anyway — it is
     * unconditional in the controller — and recomputing from approved rows only
     * is what makes that safe.
     */
    expect($product->fresh()->review_count)->toBe(1)
        ->and((float) $product->fresh()->rating)->toBe(5.0);
});

it('reports the recomputed figures back to the screen', function () {
    bdAsAdmin();

    $product = bdProduct();

    $response = test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 2,
        'status' => ReviewStatus::APPROVED,
        'ratings' => [4 => 1],
    ]))->assertOk();

    /*
     * Read back from `products` AFTER the refresh, so the screen shows what the
     * shop cards will print rather than what it hoped would happen.
     *
     * Cast rather than assertJsonPath(..., 4.0): json_encode writes a float of
     * 4.0 as `4`, so the decoded value is an int and a strict path assertion
     * fails on the type while the number is right. The screen reads it with
     * toFixed(2), where JavaScript has only the one number type, so nothing
     * downstream depends on which of the two PHP hands back.
     */
    expect((int) $response->json('products.0.review_count'))->toBe(2)
        ->and((float) $response->json('products.0.rating'))->toBe(4.0);
});

it('evicts the cached homepage review wall so the two displayed figures cannot disagree', function () {
    bdAsAdmin();

    $product = bdProduct();

    // Store\HomeController wraps its wall in Cache::remember('kbb.home.reviews',
    // 900, ...). Left in place, the homepage shows the old count and average for
    // up to fifteen minutes while the product pages show the new ones.
    Cache::put('kbb.home.reviews', ['total' => 0, 'average' => 0.0, 'bars' => [], 'items' => []], 900);

    expect(Cache::has('kbb.home.reviews'))->toBeTrue();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 2,
        'status' => ReviewStatus::APPROVED,
    ]))->assertOk();

    expect(Cache::has('kbb.home.reviews'))->toBeFalse();
});

/* ------------------------------------------------------------------ bounds */

it('refuses more rows in one request than it will insert', function () {
    bdAsAdmin();

    $a = bdProduct();
    $b = bdProduct();

    // 2 products x 150 = 300, over the 200 ceiling. Reported against
    // `per_product`, which is the number the owner can change.
    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$a->id, $b->id],
        'per_product' => 150,
    ]))->assertStatus(422)->assertJsonValidationErrors(['per_product']);

    // and nothing was written on the way to refusing
    expect(Review::query()->count())->toBe(0);
});

it('refuses a per-product count above the ceiling on its own', function () {
    bdAsAdmin();

    $product = bdProduct();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 5000,
    ]))->assertStatus(422);

    expect(Review::query()->count())->toBe(0);
});

it('refuses a product that does not exist', function () {
    bdAsAdmin();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [999999],
    ]))->assertStatus(422);

    expect(Review::query()->count())->toBe(0);
});

/* ------------------------------------------------------------------- dates */

it('never dates a review in the future, however the window is asked for', function () {
    bdAsAdmin();

    $product = bdProduct();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 10,
        'date_from' => now()->addYear()->toDateString(),
        'date_to' => now()->addYears(2)->toDateString(),
    ]))->assertOk();

    /*
     * A review dated tomorrow prints tomorrow's date on the product page, sorts
     * above everything real under every "newest" ordering the storefront has,
     * and is the single most obvious tell that a row was not written by a
     * customer. Both ends of the window are clamped rather than refused, so a
     * date picker left on today in another timezone is not a 422.
     */
    $latest = Review::query()->max('created_at');

    expect(\Illuminate\Support\Carbon::parse($latest)->lessThanOrEqualTo(now()->addMinute()))->toBeTrue();
});

it('spreads the reviews across the window rather than stamping them all alike', function () {
    bdAsAdmin();

    $product = bdProduct();

    test()->postJson('/admin-api/review-bulk/add', bdAddPayload([
        'product_ids' => [$product->id],
        'per_product' => 30,
        'date_from' => now()->subYear()->toDateString(),
        'date_to' => now()->subDay()->toDateString(),
    ]))->assertOk();

    // Thirty rows landing on one timestamp is what a naive implementation does,
    // and it is visible on the page: thirty reviews all dated the same minute.
    expect(Review::query()->distinct()->count('created_at'))->toBeGreaterThan(1);

    // updated_at tracks created_at, so a bulk row does not sort to the top of
    // the console's `updated_desc` as though it had just been moderated.
    foreach (Review::query()->get() as $row) {
        expect($row->updated_at->timestamp)->toBe($row->created_at->timestamp);
    }
});

/* ------------------------------------------------------------------- likes */

it('raises the helpful count on the reviews it is pointed at', function () {
    bdAsAdmin();

    $product = bdProduct();
    $review = bdReview($product, 5, ReviewStatus::APPROVED, 2);

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'ids',
        'ids' => [$review->id],
        'mode' => 'add',
        'min' => 5,
        'max' => 5,
    ])->assertOk()->assertJson(['ok' => true, 'affected' => 1]);

    expect($review->fresh()->helpful)->toBe(7);   // 2 already there, +5
});

it('replaces the count in set mode and adds to it in add mode', function () {
    bdAsAdmin();

    $product = bdProduct();
    $review = bdReview($product, 5, ReviewStatus::APPROVED, 40);

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'ids', 'ids' => [$review->id], 'mode' => 'set', 'min' => 3, 'max' => 3,
    ])->assertOk();

    // 'set' discards whatever genuine votes the review had; the screen says so.
    expect($review->fresh()->helpful)->toBe(3);
});

it('only touches approved reviews unless it is told otherwise', function () {
    bdAsAdmin();

    $product = bdProduct();

    $approved = bdReview($product, 5, ReviewStatus::APPROVED, 0);
    $pending = bdReview($product, 5, ReviewStatus::PENDING, 0);

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'product', 'product_ids' => [$product->id],
        'mode' => 'set', 'min' => 9, 'max' => 9,
    ])->assertOk()->assertJson(['affected' => 1]);

    /*
     * Store\ReviewController::helpful() looks the row up through ->approved()
     * and answers 404 for anything else, so a pending review cannot accumulate
     * a single genuine vote. The default here is the same set.
     */
    expect($approved->fresh()->helpful)->toBe(9)
        ->and($pending->fresh()->helpful)->toBe(0);
});

it('widens to other statuses only when asked', function () {
    bdAsAdmin();

    $product = bdProduct();

    $approved = bdReview($product, 5, ReviewStatus::APPROVED, 0);
    $pending = bdReview($product, 5, ReviewStatus::PENDING, 0);

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'product', 'product_ids' => [$product->id], 'status' => 'any',
        'mode' => 'set', 'min' => 4, 'max' => 4,
    ])->assertOk()->assertJson(['affected' => 2]);

    expect($approved->fresh()->helpful)->toBe(4)
        ->and($pending->fresh()->helpful)->toBe(4);
});

it('holds the ceiling on the helpful count', function () {
    bdAsAdmin();

    $product = bdProduct();
    $review = bdReview($product, 5, ReviewStatus::APPROVED, 9990);

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'ids', 'ids' => [$review->id], 'mode' => 'add', 'min' => 500, 'max' => 500,
    ])->assertOk();

    // Clamped in PHP, not with a SQL LEAST()/MIN() whose spelling differs
    // between MySQL and SQLite — see docs/MYSQL-PARITY.md.
    expect($review->fresh()->helpful)->toBe(9999);
});

it('refuses a range whose top is below its bottom', function () {
    bdAsAdmin();

    $product = bdProduct();
    $review = bdReview($product, 5, ReviewStatus::APPROVED, 0);

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'ids', 'ids' => [$review->id], 'mode' => 'add', 'min' => 9, 'max' => 2,
    ])->assertStatus(422)->assertJsonValidationErrors(['max']);

    expect($review->fresh()->helpful)->toBe(0);
});

it('honours the ceiling on how many reviews one pass touches', function () {
    bdAsAdmin();

    $product = bdProduct();

    foreach (range(1, 6) as $i) {
        bdReview($product, 5, ReviewStatus::APPROVED, 0);
    }

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'product', 'product_ids' => [$product->id], 'limit' => 2,
        'mode' => 'set', 'min' => 7, 'max' => 7,
    ])->assertOk()->assertJson(['matched' => 2, 'affected' => 2]);

    expect(Review::query()->where('helpful', 7)->count())->toBe(2);
});

it('answers plainly when nothing matches instead of reporting a success it did not have', function () {
    bdAsAdmin();

    $product = bdProduct();
    bdReview($product, 5, ReviewStatus::PENDING, 0);

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'product', 'product_ids' => [$product->id],
        'mode' => 'set', 'min' => 5, 'max' => 5,
    ])->assertOk()->assertJson(['affected' => 0, 'matched' => 0]);
});

it('never lets a helpful count move a star rating', function () {
    bdAsAdmin();

    $product = bdProduct();
    $review = bdReview($product, 4, ReviewStatus::APPROVED, 0);

    \App\Support\ProductRating::refresh([$product->id]);

    $before = $product->fresh();

    test()->postJson('/admin-api/review-bulk/likes', [
        'scope' => 'ids', 'ids' => [$review->id], 'mode' => 'set', 'min' => 900, 'max' => 900,
    ])->assertOk();

    /*
     * ProductRating::refresh() reads COUNT(*) and AVG(rating) only — `helpful`
     * is not an input to either — so a vote count cannot move a rating. Pinned
     * so that a later "be safe, recompute everything" edit cannot start feeding
     * vote counts into the score.
     */
    $after = $product->fresh();

    expect($after->review_count)->toBe($before->review_count)
        ->and((float) $after->rating)->toBe((float) $before->rating)
        ->and($review->fresh()->helpful)->toBe(900);
});

/* ----------------------------------------------------------------- options */

it('offers products that have no reviews yet, which is the whole point', function () {
    bdAsAdmin();

    $fresh = bdProduct(['name' => 'BD Never Reviewed']);

    $response = test()->getJson('/admin-api/review-bulk/options')->assertOk();

    /*
     * The moderation screen's picker is built from `reviews`, which is right
     * for moderating. Built the same way here it would hide every product the
     * owner actually wants to reach, because the products worth adding reviews
     * to are exactly the ones that have none.
     */
    $ids = collect($response->json('products'))->pluck('id')->all();

    expect($ids)->toContain($fresh->id);

    $response->assertJsonPath('limits.max_rows', 200)
        ->assertJsonPath('limits.likes_max_reviews', 500);
});

it('escapes the wildcards in a product search', function () {
    bdAsAdmin();

    bdProduct(['name' => 'BD Under_score']);
    bdProduct(['name' => 'BD Ordinary']);
    bdProduct(['name' => 'BD 100% Worth It']);

    /*
     * `_` IS THE TERM THAT DISCRIMINATES, and the first draft of this test used
     * `%` instead and could not tell the two implementations apart. Searching
     * "100%" unescaped builds LIKE '%100%%', which still fails to match "BD
     * Ordinary" because that name contains no "100" — the wildcard never
     * widened anything, so the assertion passed against an unescaped query.
     * Established by mutation: removing escapeLike() left it green.
     *
     * `_` is LIKE's single-character wildcard, so unescaped it builds '%_%' and
     * matches EVERY row with at least one character in its name. Escaped it
     * matches the one product whose name really contains an underscore. The two
     * implementations cannot both pass this.
     */
    $hits = test()->getJson('/admin-api/review-bulk/options?q=' . urlencode('_'))
        ->assertOk()->json('products');

    expect(collect($hits)->pluck('name')->all())->toBe(['BD Under_score']);

    // And `%` is an ordinary character in a product name — "100% worth it" is a
    // thing a product is called — so it must still find its own row.
    $percent = test()->getJson('/admin-api/review-bulk/options?q=' . urlencode('100%'))
        ->assertOk()->json('products');

    expect(collect($percent)->pluck('name')->all())->toBe(['BD 100% Worth It']);
});
