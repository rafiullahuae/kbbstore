<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Review;
use App\Services\SettingsService;
use App\Support\ProductRating;
use App\Support\ReviewStatus;
use App\Support\StoreRating;
use Database\Seeders\DemoReviewsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewsAdminRoutes;

/**
 * THE SAME DEFECT WE REMOVED FROM /reviews, SITTING BEHIND A SWITCH.
 *
 * 2.60.192 stopped /reviews publishing twelve invented customers. The Demo
 * Content feature was the same shape and was still live: a toggle in Store →
 * Demo Content that put fabricated review counts, star averages and "Verified"
 * ticks on the storefront, and — through the denormalised pair on `products` —
 * into the schema.org aggregateRating submitted to Google.
 *
 * WHAT WAS ACTUALLY BEING PUBLISHED, all of it verified against the code:
 *
 *   - DemoContent::reviewSummary() returned 12,481 reviews at 4.8 stars, which
 *     the home page printed as a full star-distribution wall ("12,481 verified
 *     reviews from real orders") and as "12.5k+ verified reviews" in the trust
 *     strip. Beside it DemoContent::reviews() supplied four named customers,
 *     each carrying verified = true.
 *   - DemoContent::productReviews() gave every unreviewed product page six more
 *     invented customers under a "4.9 · 3,204 reviews" summary.
 *   - DemoContent::products() gave each stand-in card a rating and a review
 *     count (4.9 over 3,204, and so on), and categories()/brands() gave each
 *     tile a product tally for a category that does not exist.
 *   - The home page raised its own "products stocked" and "Korean brands"
 *     counters to 671 and 93 with max().
 *   - DemoContentController::seedReviews() wrote its sample reviews with
 *     'verified' => true against @example.kbb addresses, and
 *     DemoReviewsSeeder wrote two thirds of its rows the same way.
 *   - Neither of the two demo-review mechanisms was excluded from ANY figure:
 *     seeded rows fed ProductRating (so every shop, category, brand and related
 *     card, plus ?sort=rating, ?sort=popular and the top_rated shortcode),
 *     StoreRating (the line beside the pay button), the product page summary
 *     and its aggregateRating, the home wall, /reviews, and both public
 *     /api/* review endpoints.
 *
 * WHICH OPTION WAS TAKEN. Not "label it" — B, stop it reaching the storefront.
 * A fabricated review count and a verified tick on an invented author are a
 * legal exposure rather than a cosmetic one (UAE Federal Law 15 of 2020, EU
 * UCPD Annex I as amended by the Omnibus Directive, UK DMCC Act 2024 s.236),
 * and a label a crawler does not read is not a fix. Demo rows stay in the
 * database and stay visible IN THE ADMIN, marked, because the owner cannot
 * remove what the panel hides.
 *
 * THE POINT OF THIS FILE. The switch's state must not matter. A setting that
 * can be turned on to put invented numbers in front of shoppers is the defect,
 * not the mitigation — so every assertion below runs with demo content ON.
 */

/* ------------------------------------------------------------------ fixtures */

function dctSettings(array $values): void
{
    $settings = app(SettingsService::class);

    foreach ($values as $key => $value) {
        $settings->set($key, $value);
    }

    Cache::flush();
    StoreRating::forget();
    \App\Http\Controllers\Store\HomeController::flushCache();
}

/** Demo content switched on, and every cached figure cleared behind it. */
function dctDemoOn(): void
{
    dctSettings(['demo_content' => '1']);
}

function dctProduct(array $overrides = []): Product
{
    $brand = Brand::firstOrCreate(
        ['slug' => 'dct-brand'],
        ['name' => 'DCT Brand'],
    );

    return Product::create(array_merge([
        'name' => 'DCT Product',
        'slug' => 'dct-product-' . uniqid(),
        'brand_id' => $brand->id,
        'price' => 9900,
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
    ], $overrides));
}

/** A review the seeder would have written: invented, and marked as such. */
function dctDemoReview(Product $product, int $rating = 5, string $status = ReviewStatus::APPROVED): Review
{
    return Review::create([
        'product_id' => $product->id,
        'author_name' => 'Invented Person ' . uniqid(),
        'author_email' => 'invented-' . uniqid() . '@example.com',
        'rating' => $rating,
        'title' => 'Seeded',
        'content' => 'This review was written by a seeder and not by a customer.',
        'status' => $status,
        'source' => DemoReviewsSeeder::SOURCE,
    ]);
}

/** A review an actual shopper left. */
function dctRealReview(Product $product, int $rating = 4, string $status = ReviewStatus::APPROVED): Review
{
    return Review::create([
        'product_id' => $product->id,
        'author_name' => 'Real Shopper ' . uniqid(),
        'rating' => $rating,
        'content' => 'A genuine review left by a genuine customer of this shop.',
        'status' => $status,
    ]);
}

/**
 * Elements rather than raw HTML.
 *
 * A substring search of a rendered page also matches the inlined CSS and the
 * inlined JavaScript, so "4.9" appearing anywhere in 80KB of markup proves
 * nothing. Where a figure has to be absent, the assertion below names the
 * rendered text it would appear in.
 */
function dctVisibleText(string $html): string
{
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

    return preg_replace('/\s+/', ' ', strip_tags($html)) ?? $html;
}

/* ------------------------------------------- the fixtures carry no figures */

it('exposes no invented review total, average or star distribution at all', function () {
    $demo = app(\App\Services\DemoContent::class);

    // The three methods that existed only to state numbers nobody earned.
    foreach (['reviewSummary', 'reviews', 'productReviews'] as $gone) {
        expect(method_exists($demo, $gone))->toBeFalse(
            "DemoContent::{$gone}() invented review figures and must not exist."
        );
    }
});

it('gives a stand-in product card no rating and no review count', function () {
    $demo = app(\App\Services\DemoContent::class);

    dctDemoOn();

    expect($demo->products())->not->toBeEmpty();

    foreach ($demo->products() as $card) {
        expect((int) $card->review_count)->toBe(0, 'A demo card must claim no reviews.');
        expect((float) $card->rating)->toBe(0.0, 'A demo card must claim no rating.');
    }
});

it('gives a stand-in category or brand tile no product tally', function () {
    $demo = app(\App\Services\DemoContent::class);

    dctDemoOn();

    foreach ($demo->categories() as $tile) {
        expect((int) $tile->products_count)->toBe(0, 'A demo category must count nothing.');
    }

    foreach ($demo->brands() as $tile) {
        expect((int) $tile->products_count)->toBe(0, 'A demo brand must count nothing.');
    }
});

it('carries no fabricated review figure anywhere in its source', function () {
    /*
     * A regex over PHP source reads comments and quoted prose as code, and this
     * file's own header names several of the numbers. The tokens are filtered
     * down to literals with token_get_all() first, and the banned values are
     * built at run time so this guard cannot match itself.
     */
    $tokens = token_get_all(file_get_contents(app_path('Services/DemoContent.php')));
    $literals = [];

    foreach ($tokens as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if (in_array($token[0], [T_LNUMBER, T_DNUMBER], true)) {
            $literals[] = $token[1];
        }
    }

    $banned = [
        (string) (12000 + 481),   // the shop-wide total
        (string) (3000 + 204),    // the per-product total
        (string) (1000 + 240),
        (string) (600 + 260),     // 860
        (string) (4 + 0.9),       // the averages, as PHP writes them
        (string) (4 + 0.8),
    ];

    foreach ($banned as $value) {
        expect(in_array($value, $literals, true))->toBeFalse(
            "DemoContent must carry no fabricated review figure; found the literal {$value}."
        );
    }
});

/* ----------------------------------------------------- the home page */

it('states no invented review figure on the home page with demo content on', function () {
    dctDemoOn();

    $text = dctVisibleText(test()->get('/')->assertOk()->getContent());

    foreach (['12,481', '12.5k+', '4.8 average'] as $invented) {
        expect(str_contains($text, $invented))->toBeFalse(
            "The home page must not print the invented figure {$invented}."
        );
    }

    // And the four invented customers the wall used to carry.
    foreach (['Kingsley C.', 'Houda A.', 'Jenifer L.', 'Rowena M.'] as $person) {
        expect(str_contains($text, $person))->toBeFalse(
            "The home page must not print the invented customer {$person}."
        );
    }
});

it('does not overstate how many products or brands the shop carries', function () {
    dctDemoOn();

    $realProducts = Product::query()->visible()->count();
    $realBrands = Brand::query()->count();

    // The two figures the page used to raise to 671 and 93 with max().
    expect($realProducts)->toBeLessThan(671, 'Fixture catalogue must be small enough for this to bite.');

    $text = dctVisibleText(test()->get('/')->assertOk()->getContent());

    expect(str_contains($text, '671'))->toBeFalse('The home page must not overstate the catalogue size.');
    expect(str_contains($text, number_format($realProducts)))->toBeTrue(
        'The home page must state the real number of products it stocks.'
    );
    expect($realBrands)->toBeLessThan(93);
});

it('shows a real review wall once real reviews exist, and none before', function () {
    dctDemoOn();

    // Nothing invented is substituted for an empty wall.
    $empty = dctVisibleText(test()->get('/')->assertOk()->getContent());

    expect(str_contains($empty, 'What our customers say'))->toBeFalse(
        'With no reviews the wall must be absent, not zeroed.'
    );

    $product = dctProduct();
    dctRealReview($product, 5);
    dctSettings(['demo_content' => '1']);

    $filled = dctVisibleText(test()->get('/')->assertOk()->getContent());

    expect(str_contains($filled, 'What our customers say'))->toBeTrue(
        'A real review must bring the wall back.'
    );
});

/* ------------------------------------------------------- the product page */

it('keeps demo-seeded reviews off a product page, its summary and its JSON-LD', function () {
    dctDemoOn();

    $product = dctProduct();
    $demoReview = dctDemoReview($product, 5);
    ProductRating::refresh([$product->id]);

    $html = test()->get('/product/' . $product->slug . '/')->assertOk()->getContent();
    $text = dctVisibleText($html);

    expect(str_contains($text, $demoReview->author_name))->toBeFalse(
        'A demo reviewer must not appear on the product page.'
    );

    // No aggregateRating in the structured data either.
    expect(preg_match('/"aggregateRating"/', $html))->toBe(
        0,
        'A product whose only reviews are demo rows must publish no aggregateRating.'
    );

    // And the denormalised pair the shop cards print is zero, not five stars.
    $product->refresh();

    expect((int) $product->review_count)->toBe(0);
    expect((float) $product->rating)->toBe(0.0);
});

it('still shows a real review beside a demo one, and counts only the real one', function () {
    dctDemoOn();

    $product = dctProduct();
    dctDemoReview($product, 5);
    $real = dctRealReview($product, 4);
    ProductRating::refresh([$product->id]);

    $text = dctVisibleText(test()->get('/product/' . $product->slug . '/')->assertOk()->getContent());

    expect(str_contains($text, $real->author_name))->toBeTrue(
        'A real review must still be published.'
    );

    $product->refresh();

    expect((int) $product->review_count)->toBe(1, 'Only the real review counts.');
    expect(round((float) $product->rating, 2))->toBe(4.0);
});

/* ---------------------------------------------------- every other surface */

it('keeps demo-seeded reviews out of the shop-wide score at the pay button', function () {
    dctDemoOn();

    $product = dctProduct();

    // Comfortably past StoreRating::MINIMUM, so only provenance can suppress it.
    for ($i = 0; $i < StoreRating::MINIMUM + 3; $i++) {
        dctDemoReview($product, 5);
    }

    StoreRating::forget();

    expect(StoreRating::summary())->toBeNull(
        'Demo rows must not earn the shop a published score.'
    );
    expect(StoreRating::line())->toBeNull();
});

it('keeps demo-seeded reviews off /reviews', function () {
    dctDemoOn();

    $product = dctProduct();
    $demoReview = dctDemoReview($product, 5);

    $text = dctVisibleText(test()->get('/reviews')->assertOk()->getContent());

    expect(str_contains($text, $demoReview->author_name))->toBeFalse(
        '/reviews must not publish a demo reviewer.'
    );

    expect(str_contains($text, 'No reviews yet'))->toBeTrue(
        'With only demo rows, /reviews must say plainly that there are none.'
    );
});

it('does not submit /reviews to search engines on the strength of demo rows', function () {
    dctDemoOn();

    $product = dctProduct();
    dctDemoReview($product, 5);

    $sitemap = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect(str_contains($sitemap, '/reviews/'))->toBeFalse(
        'A shop whose only reviews are demo rows must not advertise /reviews, which says "No reviews yet".'
    );

    // A real review puts it back, so this is not passing because the URL is
    // never listed at all.
    dctRealReview($product, 5);
    Cache::flush();

    $filled = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect(str_contains($filled, '/reviews/'))->toBeTrue(
        'A real review must bring /reviews back into the sitemap.'
    );
});

it('keeps demo-seeded reviews out of both public api endpoints', function () {
    dctDemoOn();

    $product = dctProduct();
    $demoReview = dctDemoReview($product, 5);
    $real = dctRealReview($product, 4);

    $names = collect(test()->getJson('/api/reviews')->assertOk()->json())
        ->pluck('author_name')
        ->all();

    expect(in_array($demoReview->author_name, $names, true))->toBeFalse(
        '/api/reviews must not serve a demo reviewer.'
    );
    expect(in_array($real->author_name, $names, true))->toBeTrue(
        '/api/reviews must still serve a real one.'
    );

    $perProduct = collect(test()->getJson('/api/products/' . $product->slug . '/reviews')->assertOk()->json())
        ->pluck('author_name')
        ->all();

    expect(in_array($demoReview->author_name, $perProduct, true))->toBeFalse(
        "/api/products/{slug}/reviews must not serve a demo reviewer."
    );
    expect(in_array($real->author_name, $perProduct, true))->toBeTrue();
});

it('excludes a review the admin panel seeded, which carries no source of its own', function () {
    /*
     * The two demo-review mechanisms mark their rows differently:
     * DemoReviewsSeeder stamps `source`, while DemoContentController records
     * the row in `demo_seed_log` — and rows already on the server were written
     * before seedReviews() started stamping `source` too. A predicate that
     * asked only about `source` would still publish every one of those.
     */
    dctDemoOn();

    $product = dctProduct();

    $review = Review::create([
        'product_id' => $product->id,
        'author_name' => 'Logged Only Person',
        'rating' => 5,
        'content' => 'Seeded through the admin panel, with no source column set.',
        'status' => ReviewStatus::APPROVED,
    ]);

    DB::table('demo_seed_log')->insert([
        'type' => 'reviews',
        'model' => Review::class,
        'record_id' => $review->id,
        'created_at' => now(),
    ]);

    ProductRating::refresh([$product->id]);

    $text = dctVisibleText(test()->get('/product/' . $product->slug . '/')->assertOk()->getContent());

    expect(str_contains($text, 'Logged Only Person'))->toBeFalse(
        'A row logged in demo_seed_log must be excluded even with no source set.'
    );

    expect((int) Product::query()->whereKey($product->id)->value('review_count'))->toBe(0);
});

it('still publishes an ordinary review that carries no source at all', function () {
    /*
     * The guard against the obvious way to get this wrong. `source <> 'demo'`
     * evaluates to NULL — not true — for every row whose source is NULL, so a
     * predicate written that way hides every real review in the table. Most of
     * this shop's reviews carry no source.
     */
    dctDemoOn();

    $product = dctProduct();
    $real = dctRealReview($product, 5);

    expect($real->source)->toBeNull('This test is meaningless if the row carries a source.');

    ProductRating::refresh([$product->id]);

    $text = dctVisibleText(test()->get('/product/' . $product->slug . '/')->assertOk()->getContent());

    expect(str_contains($text, $real->author_name))->toBeTrue(
        'A review with a NULL source is an ordinary review and must be published.'
    );

    expect((int) Product::query()->whereKey($product->id)->value('review_count'))->toBe(1);
});

/* ------------------------------------------------ nothing claims to be verified */

it('writes no seeded sample review as a verified purchase', function () {
    dctDemoOn();

    // Both mechanisms, since both used to set the flag.
    test()->seed(DemoReviewsSeeder::class);

    $verifiedSeeded = Review::query()
        ->where('source', DemoReviewsSeeder::SOURCE)
        ->where('verified', true)
        ->count();

    expect($verifiedSeeded)->toBe(
        0,
        'A seeder cannot verify a purchase that never happened.'
    );

    app(\App\Http\Controllers\Admin\DemoContentController::class)->import('reviews');

    $verifiedFromPanel = Review::query()
        ->whereIn('id', DB::table('demo_seed_log')
            ->where('model', Review::class)
            ->pluck('record_id')
            ->all())
        ->where('verified', true)
        ->count();

    expect($verifiedFromPanel)->toBe(
        0,
        'Demo Content must not seed a review flagged as a verified purchase.'
    );
});

/* ------------------------------------------- what actually ships to the host */

it('retires a demo-derived rating already written to the products table', function () {
    /*
     * The code change alone cannot fix the live site. `products.rating` and
     * `products.review_count` are stored columns — a cache of the reviews
     * table, written when something calls ProductRating::refresh(). A store
     * that had demo content on when an earlier package landed already has
     * demo-derived figures on those columns, and they would stay there,
     * printed on every card and published as aggregateRating, until something
     * recomputed them.
     */
    $seeded = dctProduct();
    dctDemoReview($seeded, 5);

    $logged = dctProduct();
    $loggedReview = Review::create([
        'product_id' => $logged->id,
        'author_name' => 'Panel Seeded Person',
        'rating' => 5,
        'content' => 'Seeded through the admin panel.',
        'status' => ReviewStatus::APPROVED,
    ]);

    DB::table('demo_seed_log')->insert([
        'type' => 'reviews',
        'model' => Review::class,
        'record_id' => $loggedReview->id,
        'created_at' => now(),
    ]);

    // An imported product with its own WooCommerce rating meta and no review
    // rows at all. The migration must not touch this one.
    $imported = dctProduct(['wc_id' => 778899, 'rating' => 4.5, 'review_count' => 88]);

    // The state the live server is in: the pair written from demo rows.
    Product::query()->whereKey($seeded->id)->update(['rating' => 4.9, 'review_count' => 3204]);
    Product::query()->whereKey($logged->id)->update(['rating' => 5.0, 'review_count' => 12]);

    $migration = require base_path('database/migrations/2026_11_06_000000_retire_demo_derived_ratings.php');
    $migration->up();

    expect((int) Product::query()->whereKey($seeded->id)->value('review_count'))
        ->toBe(0, 'A rating derived from seeder rows must be retired.');
    expect((int) Product::query()->whereKey($logged->id)->value('review_count'))
        ->toBe(0, 'A rating derived from panel-seeded rows must be retired too.');

    expect((int) Product::query()->whereKey($imported->id)->value('review_count'))
        ->toBe(88, 'An imported product with no demo review must keep its own rating meta.');
    expect(round((float) Product::query()->whereKey($imported->id)->value('rating'), 2))->toBe(4.5);

    // And the reviews are still there — this was achieved by not counting them,
    // not by deleting them behind the owner's back.
    expect(Review::query()->where('source', DemoReviewsSeeder::SOURCE)->where('product_id', $seeded->id)->count())
        ->toBeGreaterThan(0);
    expect(Review::query()->whereKey($loggedReview->id)->exists())->toBeTrue();

    // Twice is the same as once.
    $migration->up();

    expect((int) Product::query()->whereKey($seeded->id)->value('review_count'))->toBe(0);
    expect((int) Product::query()->whereKey($imported->id)->value('review_count'))->toBe(88);
});

/* --------------------------------------------------- visible in the admin */

it('still shows demo reviews in the admin, marked as demo', function () {
    ReviewsAdminRoutes::wire(app());

    test()->actingAs(AdminUser::create([
        'name' => 'DCT Owner',
        'email' => 'dct-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');

    $product = dctProduct();
    $demoReview = dctDemoReview($product, 5);
    $real = dctRealReview($product, 4);

    $rows = collect(test()->getJson('/admin-api/reviews/list?per_page=100')->assertOk()->json('reviews'));

    $demoRow = $rows->firstWhere('id', $demoReview->id);
    $realRow = $rows->firstWhere('id', $real->id);

    expect($demoRow)->not->toBeNull(
        'The admin must still see a demo review — the owner cannot remove what is hidden.'
    );
    expect($demoRow['is_demo'])->toBeTrue('A demo review must be marked as one.');

    expect($realRow)->not->toBeNull();
    expect($realRow['is_demo'])->toBeFalse('A real review must not be marked as demo.');
});

/* ------------------------------------------------------------ removal path */

it('removes every row the demo reviews import created, and is idempotent', function () {
    $controller = app(\App\Http\Controllers\Admin\DemoContentController::class);

    $before = [
        'reviews' => Review::query()->count(),
        'products' => Product::query()->count(),
        'brands' => Brand::query()->count(),
    ];

    $controller->import('reviews');

    expect(Review::query()->count())->toBeGreaterThan($before['reviews'], 'The import must create rows.');
    expect(DB::table('demo_seed_log')->where('type', 'reviews')->count())
        ->toBeGreaterThan(0, 'Every created row must be logged.');

    $controller->remove('reviews');

    // Back exactly where it started — reviews, the product it invented and the
    // brand, not merely the reviews.
    expect(Review::query()->count())->toBe($before['reviews'], 'Removal must delete every seeded review.');
    expect(Product::query()->count())->toBe($before['products'], 'Removal must delete the product it invented.');
    expect(Brand::query()->count())->toBe($before['brands'], 'Removal must delete the brand it invented.');
    expect(DB::table('demo_seed_log')->where('type', 'reviews')->count())->toBe(0);

    // Twice is the same as once.
    $controller->remove('reviews');

    expect(Review::query()->count())->toBe($before['reviews']);
    expect(DB::table('demo_seed_log')->where('type', 'reviews')->count())->toBe(0);

    // And a re-import after a removal still works, which is what a stale log
    // row or a soft-deleted slug would break.
    $controller->import('reviews');

    expect(DB::table('demo_seed_log')->where('type', 'reviews')->count())->toBeGreaterThan(0);
});
