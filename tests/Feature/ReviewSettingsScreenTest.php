<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\ReviewSettings;
use App\Support\ReviewStatus;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ReviewSettingsAdminRoutes;

/**
 * Store → Reviews → Review Settings.
 *
 * WHAT THIS SCREEN REPLACED. An <iframe> to `kbb-admin-reviews-settings.html`,
 * a standalone file this repo has never shipped, so the screen printed the
 * "isn't installed yet" card. Meanwhile eleven values really do govern the
 * review section on every product page of a store running 3,700+ live reviews,
 * and SEVEN of them were read by resources/views/partials/reviews.blade.php and
 * written by nothing whatsoever — no seeder, no migration, no controller, no
 * screen.
 *
 * EVERY TEST BELOW ASSERTS THE STOREFRONT, NOT THE STORED VALUE. That is the
 * whole point of this file. CLAUDE.md records two settings that shipped with a
 * writer and no reader and survived for months because the screen read back its
 * own value and looked fine. So a test here is not allowed to finish on
 * assertDatabaseHas: it saves through the real endpoint, fetches the real
 * product page, and asserts the markup changed. Where a setting governs the
 * submit endpoint, it posts a real submission and asserts the refusal.
 *
 * THREE OF THE KEYS WERE HALF-CONNECTED and that is pinned hardest of all.
 * `sr_allow_submit`, `sr_allow_photos` and `sr_max_photos` were obeyed by the
 * VIEW and ignored by the CONTROLLER: submissions off hid the button while
 * POST /reviews kept accepting, photos off hid the field while the upload loop
 * kept writing to public/uploads/reviews, and the form printed "up to 4" while
 * validation allowed six. Each now has a test that would have failed before
 * this package.
 *
 * WHAT IS DELIBERATELY ABSENT. There is no "reviews appear without approval"
 * control, no "guests may review" and no "purchase required". The first would
 * feed unmoderated rows into Store\ProductController::reviewSummary(), which is
 * what Seo publishes to Google as aggregateRating, and would need
 * ProductRating::refresh() on submit — moderation and the rating maths, both of
 * which this lane was told not to disturb. The other two have no reader on the
 * storefront at all. A test at the foot of this file pins that the moderation
 * behaviour is untouched, so a later lane cannot add one of them by accident.
 */

/* ------------------------------------------------------------------ fixtures */

function bbAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'BB Owner',
        'email' => 'bb-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function bbAsAdmin(): void
{
    ReviewSettingsAdminRoutes::wire(app());
    test()->actingAs(bbAdmin(), 'admin');
}

function bbProduct(array $overrides = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'bb-product-' . $n . '-' . uniqid(),
        'name' => 'BB Product ' . $n,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 50.00,
        'stock_status' => 'instock',
    ], $overrides));
}

function bbReview(Product $product, array $overrides = []): Review
{
    static $n = 0;
    $n++;

    return Review::create(array_merge([
        'product_id' => $product->id,
        'source' => 'kbb',
        'author_name' => 'BB Reviewer ' . $n,
        'author_email' => 'bb-reviewer-' . $n . '@example.test',
        'rating' => 5,
        'title' => 'BB title ' . $n,
        'content' => 'BB body ' . $n,
        'status' => ReviewStatus::APPROVED,
        'helpful' => 0,
        'ip' => '203.0.113.' . (($n % 250) + 1),
    ], $overrides));
}

/**
 * Write a setting the way the app does, and make the change visible in-process.
 *
 * SettingsService memoises non-autoloaded keys in a process-level static and
 * Setting::map() does the same — CLAUDE.md names that as a trap in tests and
 * queue workers specifically. set() drops the key it wrote, but a test that
 * reads BEFORE writing has already cached a miss for every other key via the
 * whole-table snapshot, so the belt-and-braces flush here is what keeps these
 * tests honest rather than accidentally green.
 */
function bbSet(array $values): void
{
    $settings = app(SettingsService::class);

    foreach ($values as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->flush();
    SettingsService::forgetMemo();
}

/**
 * A VALID captcha answer and token, so a submission fails for the reason under
 * test and no other.
 *
 * WHY THIS EXISTS. The photo tests first posted a nonsense captcha_token, which
 * 422s in verifyCaptcha() long before validation reaches the `sr_photos` rule.
 * Both tests passed — and went on passing with the photo wiring ripped back
 * out, because the status code they asserted was the captcha's, not the
 * setting's. The mutation sweep caught it; nothing else would have. A
 * submission in these tests now clears the captcha, so a 422 can only come from
 * the rule being tested, and each assertion names the field it expects to fail.
 *
 * @return array{0: string, 1: string} [answer, token]
 */
function bbCaptcha(): array
{
    $captcha = test()->getJson('/reviews/captcha')->assertOk()->json();
    [$a, $b] = array_map('intval', explode(' + ', $captcha['question']));

    return [(string) ($a + $b), $captcha['token']];
}

/** The product page's HTML, which is what every storefront assertion reads. */
function bbPage(Product $product): string
{
    $response = test()->get('/product/' . $product->slug);
    expect($response->getStatusCode())->toBe(200);

    return $response->getContent();
}

beforeEach(function () {
    // The statics outlive RefreshDatabase's rollback, so a value written by the
    // previous test would otherwise still be cached for this one.
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    RateLimiter::clear('review-submit:127.0.0.1');
});

/*
 * Store\ReviewController writes accepted uploads straight into
 * public/uploads/reviews — deliberately, because this app deploys by ZIP and a
 * storage:link symlink is a silent failure risk on that host (see the long note
 * in that controller). The consequence for tests is that an ACCEPTED submission
 * leaves real files in the working tree, and that path is neither tracked nor
 * gitignored, so they would turn up in `git status` and could be committed by
 * accident. RefreshDatabase rolls back the row; nothing rolls back the file.
 */
afterEach(function () {
    $dir = public_path('uploads/reviews');

    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    if (is_dir($dir)) {
        @rmdir($dir);
        @rmdir(public_path('uploads'));
    }
});

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on both routes the review settings screen adds', function () {
    ReviewSettingsAdminRoutes::wire(app());

    foreach ([['get', null], ['put', ['sr_max_reviews' => 9]]] as [$method, $payload]) {
        $response = $method === 'get'
            ? test()->getJson('/admin-api/review-settings')
            : test()->putJson('/admin-api/review-settings', $payload);

        expect($response->getStatusCode())->toBe(401, "{$method} /admin-api/review-settings was not refused");
    }

    // And the anonymous caller wrote nothing: the default still stands.
    expect(ReviewSettings::get(app(SettingsService::class), 'sr_max_reviews'))->toBe(200);
});

it('refuses a signed-in storefront customer as firmly as an anonymous one', function () {
    ReviewSettingsAdminRoutes::wire(app());

    $customer = Customer::create([
        'email' => 'bb-shopper-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
        'first_name' => 'BB',
        'last_name' => 'Shopper',
    ]);

    test()->actingAs($customer, 'customer');

    expect(test()->getJson('/admin-api/review-settings')->getStatusCode())->toBe(401);
    expect(test()->putJson('/admin-api/review-settings', ['sr_max_reviews' => 9])->getStatusCode())->toBe(401);
    expect(ReviewSettings::get(app(SettingsService::class), 'sr_max_reviews'))->toBe(200);
});

it('refuses a plain web user, who is not an admin', function () {
    ReviewSettingsAdminRoutes::wire(app());

    $user = User::create([
        'name' => 'BB Web',
        'email' => 'bb-web-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
    ]);

    test()->actingAs($user);

    expect(test()->getJson('/admin-api/review-settings')->getStatusCode())->toBe(401);
    expect(test()->putJson('/admin-api/review-settings', ['sr_max_reviews' => 9])->getStatusCode())->toBe(401);
    expect(ReviewSettings::get(app(SettingsService::class), 'sr_max_reviews'))->toBe(200);
});

it('carries auth:admin on every registered route, read back off the router', function () {
    ReviewSettingsAdminRoutes::wire(app());

    $routes = ReviewSettingsAdminRoutes::registered();

    // If the harness silently registered nothing, every refusal test above
    // would be passing against a 404 rather than a guard.
    expect($routes)->toHaveCount(2);

    /*
     * Read off the REGISTERED route, and with middleware(), not
     * gatherMiddleware(). The trap this exists for: RouteRegistrar::middleware()
     * REPLACES the pending middleware rather than appending, so a harness that
     * chains two calls registers routes carrying only the second — and every
     * 401 assertion above would then be passing against nothing.
     *
     * toContain takes each argument as ANOTHER needle, not as a failure
     * message, so no message is passed here: doing so silently asserts that the
     * message itself is in the middleware list, which is a test that can only
     * fail for the wrong reason.
     */
    foreach ($routes as $route) {
        expect($route->middleware())
            ->toContain('auth:admin')
            ->toContain('web')
            ->toContain(\App\Http\Middleware\NoStoreAdminApi::class);
    }
});

/* -------------------------------------------- the settings are really there */

it('serves every schema key with the storefront defaults', function () {
    bbAsAdmin();

    $body = test()->getJson('/admin-api/review-settings')->assertOk()->json();

    expect(array_keys($body['settings']))->toEqualCanonicalizing(array_keys(ReviewSettings::SCHEMA));

    // Each default IS the literal the storefront hard-coded before this lane,
    // so applying the package cannot change a single page.
    expect($body['settings']['sr_show_stars'])->toBeTrue()
        ->and($body['settings']['sr_grid_cols'])->toBe(4)
        ->and($body['settings']['sr_sort'])->toBe('newest')
        ->and($body['settings']['sr_max_reviews'])->toBe(200)
        ->and($body['settings']['sr_max_photos'])->toBe(4)
        ->and($body['settings']['sr_rate_limit'])->toBe(5)
        ->and($body['settings']['sr_empty_text'])->toBe('Be the first to share your thoughts ♡');
});

/* ------------------------------------------- each field, on the STOREFRONT */

it('sr_show_stars removes the score summary from the product page', function () {
    $product = bbProduct();
    bbReview($product, ['rating' => 4]);

    /*
     * Asserted on the ELEMENT, not on the bare class name.
     *
     * Store\ProductController inlines resources/css/kbb/sorina-reviews.css into
     * the page, and that stylesheet contains `.sr-bars{flex:1}`. A test looking
     * for 'sr-bars' therefore matches the CSS whether or not the markup is
     * there — it passed the "present" half for the wrong reason and could never
     * have passed the "absent" half at all. Caught by this test failing.
     */
    expect(bbPage($product))->toContain('<div class="sr-bars">');

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_show_stars' => false])->assertOk();

    $html = bbPage($product);

    expect($html)->not->toContain('<div class="sr-bars">')
        ->and($html)->toContain('class="sr sr-nostars"');
});

it('sr_show_tabs removes the filter chips from the product page', function () {
    $product = bbProduct();
    bbReview($product);

    // The element, not the class name: the inlined stylesheet carries
    // `.sr-filters{...}` on every page whatever this setting says.
    expect(bbPage($product))->toContain('<div class="sr-filters">');

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_show_tabs' => false])->assertOk();

    expect(bbPage($product))->not->toContain('<div class="sr-filters">');
});

it('sr_show_date removes the date from every review card', function () {
    $product = bbProduct();
    $review = bbReview($product);
    $review->forceFill(['created_at' => '2026-03-04 10:00:00'])->save();

    expect(bbPage($product))->toContain('04 Mar 2026');

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_show_date' => false])->assertOk();

    expect(bbPage($product))->not->toContain('04 Mar 2026');
});

it('sr_grid_cols changes the column count the section renders with', function () {
    $product = bbProduct();
    bbReview($product);

    expect(bbPage($product))->toContain('--sr-cols:4');

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_grid_cols' => 2])->assertOk();

    $html = bbPage($product);

    expect($html)->toContain('--sr-cols:2')
        ->and($html)->not->toContain('--sr-cols:4');
});

it('sr_sort reorders the reviews the product page prints', function () {
    $product = bbProduct();

    $low = bbReview($product, ['rating' => 1, 'content' => 'BB-LOWEST-MARKER']);
    $high = bbReview($product, ['rating' => 5, 'content' => 'BB-HIGHEST-MARKER']);

    // The low-rated one is the NEWER row, so under the default 'newest' it
    // leads. Without this the sort assertion could pass on insertion order.
    $low->forceFill(['created_at' => '2026-05-02 10:00:00'])->save();
    $high->forceFill(['created_at' => '2026-05-01 10:00:00'])->save();

    $html = bbPage($product);
    expect(strpos($html, 'BB-LOWEST-MARKER'))->toBeLessThan(strpos($html, 'BB-HIGHEST-MARKER'));

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_sort' => 'highest'])->assertOk();

    $html = bbPage($product);
    expect(strpos($html, 'BB-HIGHEST-MARKER'))->toBeLessThan(strpos($html, 'BB-LOWEST-MARKER'));

    test()->putJson('/admin-api/review-settings', ['sr_sort' => 'oldest'])->assertOk();

    $html = bbPage($product);
    expect(strpos($html, 'BB-HIGHEST-MARKER'))->toBeLessThan(strpos($html, 'BB-LOWEST-MARKER'));
});

it('sr_max_reviews caps how many review cards reach the page', function () {
    $product = bbProduct();

    for ($i = 0; $i < 6; $i++) {
        bbReview($product, ['content' => 'BB-CAP-BODY-' . $i]);
    }

    expect(substr_count(bbPage($product), 'sr-card'))->toBeGreaterThanOrEqual(6);

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_max_reviews' => 4])->assertOk();

    $html = bbPage($product);

    // Four cards rendered, and the two beyond the cap are genuinely absent from
    // the document rather than merely hidden by CSS.
    expect(substr_count($html, 'class="sr-card'))->toBe(4);
});

it('sr_empty_text is what a product with no reviews prints', function () {
    $product = bbProduct();

    expect(bbPage($product))->toContain('Be the first to share your thoughts');

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', [
        'sr_empty_text' => 'No reviews here yet — tell us what you think!',
    ])->assertOk();

    $html = bbPage($product);

    expect($html)->toContain('No reviews here yet')
        ->and($html)->not->toContain('Be the first to share your thoughts');
});

/* ----------------------------- the three that the server used to ignore ---- */

it('sr_allow_submit hides the form AND refuses a real submission', function () {
    $product = bbProduct();

    expect(bbPage($product))->toContain('data-sr-open');

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_allow_submit' => false])->assertOk();

    // The view half, which already worked.
    expect(bbPage($product))->not->toContain('data-sr-open');

    // The server half, which did NOT. Before this package the POST below
    // created a row: the setting had a reader and no teeth.
    $before = Review::query()->where('product_id', $product->id)->count();

    $response = test()->postJson('/reviews/submit', [
        'product_id' => $product->id,
        'rating' => 5,
        'author_name' => 'Bypass',
        'author_email' => 'bypass@example.test',
        'content' => 'Posted straight at the endpoint.',
    ]);

    expect($response->getStatusCode())->toBe(403);
    expect(Review::query()->where('product_id', $product->id)->count())->toBe($before);
});

it('sr_allow_photos removes the upload field AND refuses a submission carrying photos', function () {
    $product = bbProduct();

    expect(bbPage($product))->toContain('data-sr-file');

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_allow_photos' => false])->assertOk();

    expect(bbPage($product))->not->toContain('data-sr-file');

    // A submission carrying a photo is REFUSED, not silently stripped —
    // quietly discarding an upload is how a shopper concludes the site ate
    // their review. The captcha is answered correctly so the only thing left
    // that can refuse this request is the sr_photos rule, and the assertion
    // names that field rather than trusting the status code alone.
    [$answer, $token] = bbCaptcha();

    $response = test()->post('/reviews/submit', [
        'product_id' => $product->id,
        'rating' => 5,
        'author_name' => 'Photo Poster',
        'author_email' => 'photo@example.test',
        'content' => 'With a picture.',
        'captcha' => $answer,
        'captcha_token' => $token,
        'sr_photos' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg')],
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422)->assertJsonValidationErrors(['sr_photos']);
    expect(Review::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('sr_max_photos is the number the form promises and the number the server enforces', function () {
    $product = bbProduct();

    // The form's promise, straight out of the markup.
    expect(bbPage($product))->toContain('up to 4');

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_max_photos' => 2])->assertOk();

    expect(bbPage($product))->toContain('up to 2');

    /*
     * And validation now agrees with it. Three photos against a ceiling of two
     * is a 422 naming sr_photos — and BEFORE this package it was a 201, because
     * the rule read the hard-coded self::MAX_PHOTOS of 6 while the form beside
     * it printed 4.
     *
     * The captcha is answered correctly on purpose. With a nonsense token this
     * test passed with the photo wiring removed entirely: the 422 it asserted
     * was the captcha's.
     */
    [$answer, $token] = bbCaptcha();

    $response = test()->post('/reviews/submit', [
        'product_id' => $product->id,
        'rating' => 5,
        'author_name' => 'Three Photos',
        'author_email' => 'three@example.test',
        'content' => 'Too many pictures.',
        'captcha' => $answer,
        'captcha_token' => $token,
        'sr_photos' => [
            \Illuminate\Http\UploadedFile::fake()->image('a.jpg'),
            \Illuminate\Http\UploadedFile::fake()->image('b.jpg'),
            \Illuminate\Http\UploadedFile::fake()->image('c.jpg'),
        ],
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422)->assertJsonValidationErrors(['sr_photos']);
    expect(Review::query()->where('product_id', $product->id)->count())->toBe(0);

    // The control half: TWO photos against a ceiling of two is accepted, so the
    // refusal above is the limit doing its job and not uploads being broken.
    [$answer2, $token2] = bbCaptcha();

    test()->post('/reviews/submit', [
        'product_id' => $product->id,
        'rating' => 5,
        'author_name' => 'Two Photos',
        'author_email' => 'two@example.test',
        'content' => 'Just enough pictures.',
        'captcha' => $answer2,
        'captcha_token' => $token2,
        'sr_photos' => [
            \Illuminate\Http\UploadedFile::fake()->image('d.jpg'),
            \Illuminate\Http\UploadedFile::fake()->image('e.jpg'),
        ],
    ], ['Accept' => 'application/json'])->assertOk();

    expect(Review::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('sr_rate_limit is the per-IP ceiling the submit endpoint actually applies', function () {
    $product = bbProduct();

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', ['sr_rate_limit' => 1])->assertOk();

    // One hit already spent: the endpoint must now refuse with 429 rather than
    // falling through to the captcha check (which would be 422).
    RateLimiter::hit('review-submit:127.0.0.1', 3600);

    $response = test()->postJson('/reviews/submit', [
        'product_id' => $product->id,
        'rating' => 5,
        'author_name' => 'Rate Limited',
        'author_email' => 'rate@example.test',
        'content' => 'Second in the hour.',
    ]);

    expect($response->getStatusCode())->toBe(429);

    // And with the default of 5 the same single hit is NOT over the line, so
    // the 429 above is the setting doing the work and not the limiter being
    // exhausted some other way.
    test()->putJson('/admin-api/review-settings', ['sr_rate_limit' => 5])->assertOk();

    $again = test()->postJson('/reviews/submit', [
        'product_id' => $product->id,
        'rating' => 5,
        'author_name' => 'Rate Limited',
        'author_email' => 'rate@example.test',
        'content' => 'Second in the hour.',
    ]);

    expect($again->getStatusCode())->not->toBe(429);
});

/* ------------------------------------------------------- clamping and input */

it('clamps a value outside the schema rather than storing it', function () {
    bbAsAdmin();

    // Out of range is a 422 from the endpoint...
    test()->putJson('/admin-api/review-settings', ['sr_grid_cols' => 99])
        ->assertStatus(422);

    // ...and a row hand-edited into the database is clamped on READ, so the
    // storefront can never be put outside the range the screen allows.
    bbSet(['sr_grid_cols' => 99, 'sr_max_photos' => 500]);

    expect(ReviewSettings::get(app(SettingsService::class), 'sr_grid_cols'))->toBe(6)
        ->and(ReviewSettings::get(app(SettingsService::class), 'sr_max_photos'))
        ->toBe(ReviewSettings::PHOTO_CEILING);
});

it('refuses a sort it does not know and falls back on a stored one', function () {
    bbAsAdmin();

    test()->putJson('/admin-api/review-settings', ['sr_sort' => 'whatever'])
        ->assertStatus(422);

    bbSet(['sr_sort' => 'whatever']);

    expect(ReviewSettings::get(app(SettingsService::class), 'sr_sort'))->toBe('newest');
});

it('strips markup from the empty-state line and never lets it be blank', function () {
    bbAsAdmin();

    test()->putJson('/admin-api/review-settings', [
        'sr_empty_text' => '<script>alert(1)</script>Nothing yet',
    ])->assertOk();

    $stored = ReviewSettings::get(app(SettingsService::class), 'sr_empty_text');

    expect($stored)->toBe('alert(1)Nothing yet')
        ->and($stored)->not->toContain('<script');

    // Whitespace only falls back to the default rather than leaving a blank gap
    // where the page is supposed to say something.
    test()->putJson('/admin-api/review-settings', ['sr_empty_text' => '   '])->assertOk();

    expect(ReviewSettings::get(app(SettingsService::class), 'sr_empty_text'))
        ->toBe('Be the first to share your thoughts ♡');
});

it('reads a boolean back as false however an older build spelled it', function () {
    foreach (['0', '', 'false', 'off', 'no'] as $spelling) {
        bbSet(['sr_show_stars' => $spelling]);

        expect(ReviewSettings::get(app(SettingsService::class), 'sr_show_stars'))
            ->toBeFalse("[{$spelling}] was not read as false");
    }

    foreach (['1', 'true', 'on', 'yes'] as $spelling) {
        bbSet(['sr_show_stars' => $spelling]);

        expect(ReviewSettings::get(app(SettingsService::class), 'sr_show_stars'))
            ->toBeTrue("[{$spelling}] was not read as true");
    }
});

it('leaves fields a partial save did not mention alone', function () {
    bbAsAdmin();

    test()->putJson('/admin-api/review-settings', ['sr_grid_cols' => 3])->assertOk();
    test()->putJson('/admin-api/review-settings', ['sr_max_reviews' => 40])->assertOk();

    $body = test()->getJson('/admin-api/review-settings')->assertOk()->json();

    expect($body['settings']['sr_grid_cols'])->toBe(3)
        ->and($body['settings']['sr_max_reviews'])->toBe(40);
});

/* ------------------------------------------------------- the hard constraints */

it('never lets a review setting change what the public API returns', function () {
    $product = bbProduct();
    bbReview($product, [
        'author_email' => 'bb-private@example.test',
        'ip' => '198.51.100.9',
    ]);

    bbAsAdmin();

    // Every switch thrown to its most permissive position at once.
    test()->putJson('/admin-api/review-settings', [
        'sr_max_reviews' => 500,
        'sr_sort' => 'helpful',
        'sr_allow_submit' => true,
        'sr_allow_photos' => true,
        'sr_max_photos' => 6,
        'sr_rate_limit' => 50,
    ])->assertOk();

    $body = test()->getJson('/api/reviews')->assertOk()->getContent();

    // /api/* is unauthenticated by design (CLAUDE.md). Nothing this screen
    // writes may widen it, and the two columns that leaked in production stay
    // out of it whatever the settings say.
    expect($body)->not->toContain('bb-private@example.test')
        ->and($body)->not->toContain('198.51.100.9')
        ->and($body)->not->toContain('author_email')
        ->and($body)->not->toContain('"ip"');
});

it('leaves moderation alone: a new review is still pending and still invisible', function () {
    $product = bbProduct();

    bbAsAdmin();

    // Nothing on this screen can publish a review without a moderator, so the
    // most permissive settings possible still produce a pending row.
    test()->putJson('/admin-api/review-settings', [
        'sr_allow_submit' => true,
        'sr_allow_photos' => true,
        'sr_max_reviews' => 500,
    ])->assertOk();

    $captcha = test()->getJson('/reviews/captcha')->assertOk()->json();
    [$a, $b] = array_map('intval', explode(' + ', $captcha['question']));

    test()->postJson('/reviews/submit', [
        'product_id' => $product->id,
        'rating' => 5,
        'author_name' => 'Fresh Reviewer',
        'author_email' => 'fresh@example.test',
        'content' => 'BB-PENDING-MARKER body.',
        'captcha' => (string) ($a + $b),
        'captcha_token' => $captcha['token'],
    ])->assertOk();

    $review = Review::query()->where('product_id', $product->id)->firstOrFail();

    expect($review->status)->toBe(ReviewStatus::PENDING);

    // And it is nowhere on the storefront, so no setting here can feed an
    // unmoderated review into the aggregateRating Seo publishes to Google.
    expect(bbPage($product))->not->toContain('BB-PENDING-MARKER');
});

it('leaves the denormalised rating on the product untouched', function () {
    $product = bbProduct();
    bbReview($product, ['rating' => 5]);

    $before = $product->fresh()->only(['rating', 'review_count']);

    bbAsAdmin();
    test()->putJson('/admin-api/review-settings', [
        'sr_sort' => 'lowest',
        'sr_max_reviews' => 4,
        'sr_show_stars' => false,
    ])->assertOk();

    bbPage($product);

    // ProductRating::refresh() is the moderation path's business. Saving a
    // display setting must not write products.rating or products.review_count.
    expect($product->fresh()->only(['rating', 'review_count']))->toBe($before);
});
