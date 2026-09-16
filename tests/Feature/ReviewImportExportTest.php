<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Review;
use App\Support\ReviewStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\UploadedFile;
use Tests\Support\ReviewsScreensAdminRoutes;

/**
 * Reviews → Export / Import.
 *
 * WHAT THIS SCREEN REPLACED. An <iframe> to `kbb-admin-exportimport.html`, a
 * standalone file this repo has never shipped, so the screen printed the "isn't
 * installed yet" card. This store is a WooCommerce port and the owner has
 * thousands of real reviews sitting in the old shop with no way in.
 *
 * THE THREE THINGS THIS FILE EXISTS TO PIN, in the order they would hurt:
 *
 *  1. IDEMPOTENCY. Importing a file twice must not double the store's reviews.
 *     The test does it literally — same file, twice, count asserted — because
 *     that is the failure the owner would find weeks later with no way to undo
 *     it. It is pinned for a WooCommerce file (keyed on comment_id), for this
 *     screen's own export round-tripping (keyed on review_id) and for a file
 *     with no id column at all (keyed on the synthesised fingerprint).
 *
 *  2. RATINGS. `products.rating` and `products.review_count` are what the shop
 *     cards print, and Store\ProductController hands the computed average to
 *     Seo as the schema.org aggregateRating Google publishes. An import that
 *     leaves them stale is silent and embarrassing, so the assertions here are
 *     on the PRODUCT ROW after the import, not on the review count.
 *
 *  3. REJECTIONS THAT NAME THE ROW. A silent skip makes an import
 *     untrustworthy: the totals look plausible and nobody finds the missing
 *     reviews. Every rejection is asserted to carry its line number and to
 *     quote what it could not use.
 */

/* ------------------------------------------------------------------ fixtures */

function beAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'BE Owner',
        'email' => 'be-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function beAsAdmin(): void
{
    ReviewsScreensAdminRoutes::wire(app());
    test()->actingAs(beAdmin(), 'admin');
}

function beProduct(array $overrides = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'be-product-' . $n . '-' . uniqid(),
        'name' => 'BE Product ' . $n,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 50.00,
        'stock_status' => 'instock',
    ], $overrides));
}

/** A CSV on disk, as an upload. */
function beCsv(string $body, string $name = 'reviews.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'becsv');
    file_put_contents($path, $body);

    // `test` => the file is not moved through the real upload machinery, which
    // is not available in a test request; getRealPath() still points at it,
    // which is what the importer reads.
    return new UploadedFile($path, $name, 'text/csv', null, true);
}

function beImport(UploadedFile $file, array $fields = []): \Illuminate\Testing\TestResponse
{
    return test()->post('/admin-api/reviews-io/import', array_merge(['file' => $file], $fields));
}

/* ------------------------------------------------------------------- the API */

it('refuses the whole screen to anybody who is not signed in as an admin', function () {
    ReviewsScreensAdminRoutes::wire(app());

    foreach ([
        ['get', '/admin-api/reviews-io/summary'],
        ['get', '/admin-api/reviews-io/export'],
        ['post', '/admin-api/reviews-io/import'],
        ['get', '/admin-api/review-badges'],
        ['put', '/admin-api/review-badges'],
        ['post', '/admin-api/review-badges/theme'],
        ['get', '/admin-api/review-assign/reviews'],
        ['get', '/admin-api/review-assign/products'],
        ['post', '/admin-api/review-assign/move'],
        ['post', '/admin-api/review-assign/copy'],
    ] as [$method, $path]) {
        $response = test()->{$method}($path, [], ['Accept' => 'application/json']);

        expect($response->getStatusCode())->toBeIn(
            [401, 403, 302],
            "{$method} {$path} answered {$response->getStatusCode()} to an anonymous caller"
        );
    }
});

it('carries the admin-api guard on every route this lane registers', function () {
    ReviewsScreensAdminRoutes::wire(app());

    $routes = ReviewsScreensAdminRoutes::registered();

    expect($routes)->toHaveCount(10);

    foreach ($routes as $route) {
        // toContain() takes NEEDLES, not a message — a second string here is a
        // second thing the array must contain, which is how a guard assertion
        // fails for a reason that has nothing to do with the guard.
        expect($route->gatherMiddleware())->toContain('auth:admin');
        expect($route->gatherMiddleware())->toContain('web');
    }
});

/* -------------------------------------------------------------- the WC import */

/**
 * The shape a WooCommerce review export actually arrives in: a WordPress
 * comment, so `comment_id`, `comment_post_ID`, `comment_author`,
 * `comment_content` and `comment_approved` with 1/0 rather than words.
 */
function beWooCsv(int $postId): string
{
    return implode("\n", [
        'comment_ID,comment_post_ID,comment_author,comment_author_email,comment_date,comment_content,comment_approved,rating,verified',
        '9001,' . $postId . ',Layla,layla@example.test,2024-03-04 09:12:00,"Best toner I have used",1,5,1',
        '9002,' . $postId . ',Mariam,mariam@example.test,2024-04-01 11:00:00,"Too strong for me",1,3,0',
        '9003,' . $postId . ',Sara,sara@example.test,2024-05-02 08:30:00,"Waiting to see",0,4,0',
    ]) . "\n";
}

it('imports a WooCommerce review export, matching products on the WordPress post id', function () {
    beAsAdmin();

    $product = beProduct(['wc_id' => 55501]);

    $response = beImport(beCsv(beWooCsv(55501)));

    $response->assertOk();

    expect($response->json('created'))->toBe(3)
        ->and($response->json('rejected'))->toBe(0)
        ->and(Review::query()->count())->toBe(3);

    $imported = Review::query()->where('source_id', '=', 9001)->first();

    expect($imported)->not->toBeNull()
        ->and($imported->product_id)->toBe($product->id)
        ->and($imported->source)->toBe('wp_comment')
        ->and($imported->author_name)->toBe('Layla')
        ->and($imported->rating)->toBe(5)
        ->and($imported->status)->toBe(ReviewStatus::APPROVED)
        ->and($imported->verified)->toBeTrue()
        ->and((string) $imported->created_at)->toContain('2024-03-04');
});

it("reads WordPress's comment_approved 1 and 0 rather than folding both to pending", function () {
    beAsAdmin();
    beProduct(['wc_id' => 55502]);

    beImport(beCsv(beWooCsv(55502)))->assertOk();

    expect(Review::query()->where('status', ReviewStatus::APPROVED)->count())->toBe(2)
        ->and(Review::query()->where('status', ReviewStatus::PENDING)->count())->toBe(1);
});

/* ---------------------------------------------------------------- idempotency */

it('does not double the store when the same WooCommerce file is imported twice', function () {
    beAsAdmin();
    beProduct(['wc_id' => 55503]);

    $body = beWooCsv(55503);

    beImport(beCsv($body))->assertOk();

    expect(Review::query()->count())->toBe(3);

    $second = beImport(beCsv($body));

    $second->assertOk();

    expect(Review::query()->count())->toBe(3)
        ->and($second->json('created'))->toBe(0)
        ->and($second->json('unchanged'))->toBe(3);
});

it('does not double the store when a file with NO id column is imported twice', function () {
    beAsAdmin();

    $product = beProduct();

    $body = implode("\n", [
        'product_id,author,email,rating,content,status',
        $product->id . ',Noura,noura@example.test,5,"Lovely texture",approved',
        $product->id . ',Hessa,hessa@example.test,4,"Good value",approved',
    ]) . "\n";

    beImport(beCsv($body))->assertOk();

    expect(Review::query()->count())->toBe(2);

    // The synthesised key — see ReviewCsvImport::resolveKey(). Without it this
    // is the file shape that silently doubles a store's reviews.
    $second = beImport(beCsv($body));

    expect(Review::query()->count())->toBe(2)
        ->and($second->json('unchanged'))->toBe(2);
});

it('round-trips its own export without creating a single new review', function () {
    beAsAdmin();

    $product = beProduct();

    foreach (range(1, 4) as $i) {
        Review::create([
            'product_id' => $product->id,
            'source' => 'kbb',
            'author_name' => 'Round Trip ' . $i,
            'author_email' => 'rt' . $i . '@example.test',
            'rating' => 4,
            'title' => 'Title ' . $i,
            'content' => 'Body ' . $i,
            'status' => ReviewStatus::APPROVED,
            'ip' => '203.0.113.9',
        ]);
    }

    $csv = test()->get('/admin-api/reviews-io/export')->streamedContent();

    $report = beImport(beCsv($csv, 'kbb-reviews.csv'));

    $report->assertOk();

    expect(Review::query()->count())->toBe(4)
        ->and($report->json('created'))->toBe(0)
        ->and($report->json('unchanged'))->toBe(4);
});

it('updates rather than duplicates when asked to, and leaves the count alone', function () {
    beAsAdmin();
    beProduct(['wc_id' => 55504]);

    beImport(beCsv(beWooCsv(55504)))->assertOk();

    $changed = str_replace('Best toner I have used', 'Best toner, corrected', beWooCsv(55504));

    $second = beImport(beCsv($changed), ['on_duplicate' => 'update']);

    expect(Review::query()->count())->toBe(3)
        ->and($second->json('updated'))->toBe(3)
        ->and(Review::query()->where('source_id', 9001)->value('content'))->toBe('Best toner, corrected');
});

/* --------------------------------------------------------------- the ratings */

it('recomputes the denormalised rating and count that the shop cards print', function () {
    beAsAdmin();

    $product = beProduct(['wc_id' => 55505]);

    expect((int) $product->fresh()->review_count)->toBe(0);

    beImport(beCsv(beWooCsv(55505)))->assertOk();

    $product->refresh();

    // Two APPROVED rows, 5 and 3. The pending one must not count — every
    // storefront reader filters on status = 'approved'.
    expect((int) $product->review_count)->toBe(2)
        ->and(round((float) $product->rating, 2))->toBe(4.0);
});

it('publishes the imported score as the aggregateRating on the product page', function () {
    beAsAdmin();

    $product = beProduct(['wc_id' => 55506]);

    beImport(beCsv(beWooCsv(55506)))->assertOk();

    $page = test()->get('/product/' . $product->slug);

    $page->assertOk();

    // The computed pair, not the denormalised one — this is the number Google
    // is told. Both come from the same approved-only question and this asserts
    // they agree after an import.
    expect($page->getContent())->toContain('"ratingValue"')
        ->and($page->getContent())->toContain('"reviewCount":2');
});

/* ------------------------------------------------------------- the rejections */

it('rejects a malformed row, names its line number, and imports the rest', function () {
    beAsAdmin();

    $product = beProduct();

    $body = implode("\n", [
        'comment_ID,product_id,comment_author,rating,comment_content,comment_approved',
        '7001,' . $product->id . ',Fine,5,"This one is fine",1',
        '7002,' . $product->id . ',Broken,nine,"Rating is a word",1',
        '7003,' . $product->id . ',Also fine,4,"So is this",1',
    ]) . "\n";

    $response = beImport(beCsv($body));

    $response->assertOk();

    expect($response->json('created'))->toBe(2)
        ->and($response->json('rejected'))->toBe(1)
        ->and(Review::query()->count())->toBe(2);

    $reject = $response->json('rejects.0');

    // Line 3, because the header is line 1 — the number the owner sees in their
    // spreadsheet, not the index of the row within the data.
    expect($reject['row'])->toBe(3)
        ->and($reject['reason'])->toContain('rating')
        ->and($reject['reason'])->toContain('nine');
});

it('rejects a rating outside 1 to 5 rather than storing it', function () {
    beAsAdmin();

    $product = beProduct();

    $body = "product_id,author,rating,content\n" . $product->id . ",Over,9,\"Nine stars\"\n";

    $response = beImport(beCsv($body));

    expect($response->json('rejected'))->toBe(1)
        ->and($response->json('rejects.0.reason'))->toContain('outside 1 to 5')
        ->and(Review::query()->count())->toBe(0);
});

it('rejects a row whose product is in no catalogue, quoting what it could not match', function () {
    beAsAdmin();
    beProduct();

    $body = "sku,author,rating,content\nNO-SUCH-SKU,Ghost,5,\"For a product that is not here\"\n";

    $response = beImport(beCsv($body));

    expect($response->json('rejected'))->toBe(1)
        ->and($response->json('rejects.0.reason'))->toContain('NO-SUCH-SKU')
        ->and(Review::query()->count())->toBe(0);
});

it('refuses a file with no rating column at all, and imports nothing', function () {
    beAsAdmin();
    beProduct();

    $response = beImport(beCsv("product_id,author,content\n1,Nobody,\"No rating here\"\n"));

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('rating')
        ->and(Review::query()->count())->toBe(0);
});

it('refuses a file with no product column, rather than making every row a shop review', function () {
    beAsAdmin();

    $response = beImport(beCsv("author,rating,content\nNobody,5,\"No product here\"\n"));

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('product')
        ->and(Review::query()->count())->toBe(0);
});

it('rejects a row with an empty product unless shop reviews were asked for', function () {
    beAsAdmin();
    beProduct();

    $body = "product_id,author,rating,content\n,Shopper,5,\"About the shop itself\"\n";

    expect(beImport(beCsv($body))->json('rejected'))->toBe(1);

    $allowed = beImport(beCsv($body), ['allow_business' => '1']);

    expect($allowed->json('created'))->toBe(1)
        ->and(Review::query()->whereNull('product_id')->count())->toBe(1);
});

it('refuses a file that is not a CSV', function () {
    beAsAdmin();

    $file = beCsv("not really a spreadsheet", 'reviews.pdf');

    $response = beImport($file);

    $response->assertStatus(422);
});

/* --------------------------------------------------------------- the dry run */

it('writes nothing in check mode but still says how many rows are new', function () {
    beAsAdmin();
    beProduct(['wc_id' => 55507]);

    $response = beImport(beCsv(beWooCsv(55507)), ['mode' => 'check']);

    $response->assertOk();

    expect($response->json('mode'))->toBe('check')
        ->and($response->json('created'))->toBe(3)
        ->and(Review::query()->count())->toBe(0);
});

/* ------------------------------------------------------------------- the export */

it('exports the columns the importer reads back, including the key that makes it idempotent', function () {
    beAsAdmin();

    $product = beProduct(['sku' => 'BE-SKU-1']);

    Review::create([
        'product_id' => $product->id,
        'source' => 'wp_comment',
        'source_id' => 4242,
        'author_name' => 'Exported',
        'author_email' => 'exported@example.test',
        'rating' => 5,
        'title' => 'Great',
        'content' => 'Really great',
        'status' => ReviewStatus::APPROVED,
        'ip' => '203.0.113.7',
    ]);

    $csv = test()->get('/admin-api/reviews-io/export')->streamedContent();

    foreach (['review_id', 'source', 'source_id', 'product_sku', 'author', 'content'] as $column) {
        expect($csv)->toContain($column);
    }

    expect($csv)->toContain('4242')->toContain('BE-SKU-1')->toContain('exported@example.test');
});

it('can leave the reviewer email addresses out of the export', function () {
    beAsAdmin();

    $product = beProduct();

    Review::create([
        'product_id' => $product->id,
        'source' => 'kbb',
        'author_name' => 'Private',
        'author_email' => 'private@example.test',
        'rating' => 5,
        'content' => 'No email please',
        'status' => ReviewStatus::APPROVED,
        'ip' => '203.0.113.7',
    ]);

    $csv = test()->get('/admin-api/reviews-io/export?emails=0')->streamedContent();

    expect($csv)->not->toContain('private@example.test')
        ->and($csv)->toContain('No email please');
});

it('never exports the reviewer IP', function () {
    beAsAdmin();

    $product = beProduct();

    Review::create([
        'product_id' => $product->id,
        'source' => 'kbb',
        'author_name' => 'Tracked',
        'author_email' => 'tracked@example.test',
        'rating' => 5,
        'content' => 'Body',
        'status' => ReviewStatus::APPROVED,
        'ip' => '198.51.100.44',
    ]);

    $csv = test()->get('/admin-api/reviews-io/export')->streamedContent();

    expect($csv)->not->toContain('198.51.100.44')->and($csv)->not->toContain('ip');
});

it('neutralises a spreadsheet formula on the way out and un-neutralises it on the way back', function () {
    beAsAdmin();

    $product = beProduct(['wc_id' => 55508]);

    Review::create([
        'product_id' => $product->id,
        'source' => 'wp_comment',
        'source_id' => 8080,
        'author_name' => 'Formula',
        'author_email' => 'formula@example.test',
        'rating' => 5,
        'content' => '=SUM(A1:A9) was my dose',
        'status' => ReviewStatus::APPROVED,
        'ip' => '203.0.113.7',
    ]);

    $csv = test()->get('/admin-api/reviews-io/export')->streamedContent();

    expect($csv)->toContain("'=SUM(A1:A9)");

    // And back in: the apostrophe must not accumulate, or every round trip
    // corrupts a little more of the review.
    beImport(beCsv($csv), ['on_duplicate' => 'update'])->assertOk();

    expect(Review::query()->where('source_id', 8080)->value('content'))->toBe('=SUM(A1:A9) was my dose');
});

/* --------------------------------------------------------------- the summary */

it('reports what is here to export', function () {
    beAsAdmin();

    $product = beProduct();

    Review::create([
        'product_id' => $product->id, 'source' => 'kbb', 'author_name' => 'A', 'author_email' => 'a@example.test',
        'rating' => 5, 'content' => 'x', 'status' => ReviewStatus::APPROVED, 'ip' => '',
    ]);
    Review::create([
        'product_id' => $product->id, 'source' => 'kbb', 'author_name' => 'B', 'author_email' => 'b@example.test',
        'rating' => 2, 'content' => 'y', 'status' => ReviewStatus::PENDING, 'ip' => '',
    ]);

    $response = test()->get('/admin-api/reviews-io/summary');

    $response->assertOk();

    expect($response->json('counts.all'))->toBe(2)
        ->and($response->json('counts.approved'))->toBe(1)
        ->and($response->json('counts.pending'))->toBe(1)
        ->and($response->json('products_with_reviews'))->toBe(1)
        // The alias documentation is read off the importer itself, so the
        // screen cannot print a column list the parser does not honour.
        ->and($response->json('aliases.content'))->toContain('comment_content');
});


/* ------------------------------------------------- the homepage review wall */

/**
 * Store\HomeController wraps its review wall in
 * Cache::remember('kbb.home.reviews', 900, ...) and that wall aggregates every
 * APPROVED review SHOP-WIDE. An import is the largest single change that set
 * will ever see, so without an eviction the homepage would show the old wall
 * for up to fifteen minutes after the import finished while every product page
 * already showed the new reviews — and nothing on screen would admit the two
 * disagreed.
 *
 * ProductRating::refresh() does NOT cover this: it writes the denormalised pair
 * on `products`, which is a different reader. Found by Lane BD on the
 * moderation path (tests/Feature/ReviewModerationEvictsHomeWallTest.php); the
 * import path carries exactly the same obligation and did not honour it until
 * this lane rebased onto that work.
 */
it('drops the homepage review wall after an import', function () {
    beAsAdmin();
    beProduct(['wc_id' => 55509]);

    Cache::put('kbb.home.reviews', ['stale'], 900);

    beImport(beCsv(beWooCsv(55509)))->assertOk();

    expect(Cache::get('kbb.home.reviews'))->toBeNull();
});

it('leaves the homepage review wall alone for a dry run', function () {
    beAsAdmin();
    beProduct(['wc_id' => 55510]);

    Cache::put('kbb.home.reviews', ['warm'], 900);

    // A check writes nothing, so the wall it feeds has not changed. Evicting
    // there would throw away a valid cache entry every time somebody looked at
    // a file before importing it.
    beImport(beCsv(beWooCsv(55510)), ['mode' => 'check'])->assertOk();

    expect(Cache::get('kbb.home.reviews'))->toBe(['warm']);
});
