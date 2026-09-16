<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Review;
use App\Services\SettingsService;
use App\Support\ReviewBadgeSettings;
use App\Support\ReviewStatus;
use Tests\Support\ReviewsScreensAdminRoutes;

/**
 * Reviews → Badge Themes and Reviews → Rating Capsule.
 *
 * WHAT THESE SCREENS REPLACED. Two entries in REV_SRC pointing at
 * `kbb-admin-badgethemes.html` and `kbb-capsule-editor.html`, standalone files
 * this repo has never shipped, so both printed the "isn't installed yet" card.
 *
 * EVERY TEST BELOW ASSERTS THE PRODUCT PAGE, NOT THE STORED VALUE, and that is
 * the whole point of this file. CLAUDE.md records settings that shipped with a
 * writer and no reader and survived for months because the screen read back its
 * own value and looked fine. So a test here saves through the real endpoint,
 * fetches the real product page, and asserts the markup changed.
 *
 * WHAT IS DELIBERATELY ABSENT: any key of this lane's own. All seven were
 * already read by resources/views/store/product.blade.php, and a test at the
 * foot of this file pins that the set has not grown — a later lane adding an
 * eighth control has to add a reader for it too.
 */

/*
 * The settings memo does NOT respect RefreshDatabase, and that is not a detail.
 *
 * SettingsService memoises in a process-level static as well as in the cache —
 * CLAUDE.md names this as a trap in tests specifically. RefreshDatabase rolls
 * the rows back between tests; the static survives. So the test that leaves
 * `review_capsule_style` set to 'off' hands the NEXT test a store whose capsule
 * is switched off, on a database where it plainly is not, and that test fails
 * on an assertion that has nothing to do with what it is testing. Measured:
 * three tests in this file failed exactly that way before this block existed.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    \App\Models\Setting::flushMap();
});

/* ------------------------------------------------------------------ fixtures */

function rbgAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Badge Owner',
        'email' => 'rbg-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function rbgAsAdmin(): void
{
    ReviewsScreensAdminRoutes::wire(app());
    test()->actingAs(rbgAdmin(), 'admin');
}

/** A product carrying approved reviews, because the badge only draws when there are some. */
function rbgReviewedProduct(int $reviews = 3, int $rating = 5, array $overrides = []): Product
{
    static $n = 0;
    $n++;

    $product = Product::create(array_merge([
        'slug' => 'rbg-product-' . $n . '-' . uniqid(),
        'name' => 'Badge Product ' . $n,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 50.00,
        'stock_status' => 'instock',
        'total_sales' => 2400,
        'rating' => $rating,
        'review_count' => $reviews,
    ], $overrides));

    foreach (range(1, $reviews) as $i) {
        Review::create([
            'product_id' => $product->id,
            'source' => 'kbb',
            'author_name' => 'Badge Reviewer ' . $i,
            'author_email' => 'rbg' . $n . '-' . $i . '@example.test',
            'rating' => $rating,
            'title' => 'Title',
            'content' => 'Body',
            'status' => ReviewStatus::APPROVED,
            'ip' => '203.0.113.5',
        ]);
    }

    return $product;
}

/**
 * Flush the settings memo, for the reason CLAUDE.md names.
 *
 * SettingsService memoises non-autoloaded keys in a process-level static as
 * well as in the cache, so a value written through the endpoint is not
 * necessarily visible to the product page rendered in the same test process.
 */
function rbgFlush(): void
{
    app(SettingsService::class)->flush();
}

function rbgSave(array $values): \Illuminate\Testing\TestResponse
{
    $response = test()->putJson('/admin-api/review-badges', $values);

    rbgFlush();

    return $response;
}

/**
 * Is the capsule ACTUALLY DRAWN on this page?
 *
 * Not str_contains($html, 'sr-capbar'). Every one of these class names also
 * appears in the page's own stylesheet, so a bare substring match is true
 * whether the element rendered or not — which makes the assertion structurally
 * blind and the test green against a broken setting. Measured: the default page
 * contains 'sr-capbar' four times and the page with the capsule switched OFF
 * still contains it three. The rendered ELEMENT is what is matched here.
 */
function rbgHasCapsule(string $html): bool
{
    return str_contains($html, 'sr-capbar" href="#sr"');
}

/** Is the inline rating line rendered hidden? Same reasoning as rbgHasCapsule(). */
function rbgInlineHidden(string $html): bool
{
    if (preg_match('/id="bbRate"[^>]*>/', $html, $m) !== 1) {
        // No inline line at all is not the same question, and a test that
        // cannot tell the two apart is not worth having.
        throw new RuntimeException('the product page rendered no inline rating line to measure');
    }

    return str_contains($m[0], 'display:none');
}

function rbgPage(Product $product): string
{
    $response = test()->get('/product/' . $product->slug);

    $response->assertOk();

    return $response->getContent();
}

/* ------------------------------------------------------ the screen reads real values */

it('hands the screen the same defaults the product page already hard-codes', function () {
    rbgAsAdmin();

    $response = test()->get('/admin-api/review-badges');

    $response->assertOk();

    expect($response->json('settings.review_capsule_style'))->toBe('capsule')
        ->and($response->json('settings.review_badge_heart'))->toBeTrue()
        ->and($response->json('settings.review_badge_avg'))->toBeTrue()
        ->and($response->json('settings.review_badge_count'))->toBeTrue()
        ->and($response->json('settings.review_badge_sold'))->toBeTrue()
        ->and($response->json('settings.review_badge_label'))->toBe('{n} reviews')
        ->and($response->json('settings.review_badge_colour'))->toBe('#E8A33D');
});

it('offers a real product for the preview rather than an invented one', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct(4, 4);

    $response = test()->get('/admin-api/review-badges');

    expect($response->json('sample.real'))->toBeTrue()
        ->and($response->json('sample.product'))->toBe($product->name)
        ->and($response->json('sample.count'))->toBe(4)
        // toEqual, not toBe: an average that lands exactly on 4 is encoded as
        // the JSON number 4 and decodes as an int.
        ->and($response->json('sample.rating'))->toEqual(4.0);
});

/* -------------------------------------------- every control changes the real page */

it('shows the capsule and hides the inline line by default — the four styles differ on the page', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct();

    $default = rbgPage($product);

    expect(rbgHasCapsule($default))->toBeTrue()
        ->and(rbgInlineHidden($default))->toBeTrue();

    rbgSave(['review_capsule_style' => 'inline'])->assertOk();
    $inline = rbgPage($product);

    expect(rbgHasCapsule($inline))->toBeFalse()
        ->and(rbgInlineHidden($inline))->toBeFalse();

    rbgSave(['review_capsule_style' => 'both'])->assertOk();
    $both = rbgPage($product);

    expect(rbgHasCapsule($both))->toBeTrue()
        ->and(rbgInlineHidden($both))->toBeFalse();

    rbgSave(['review_capsule_style' => 'off'])->assertOk();
    $off = rbgPage($product);

    expect(rbgHasCapsule($off))->toBeFalse()
        ->and(rbgInlineHidden($off))->toBeTrue();
});

it('takes the heart off the capsule', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct();

    expect(rbgPage($product))->toContain('<span class="sr-cap-heart">');

    rbgSave(['review_badge_heart' => false])->assertOk();

    expect(rbgPage($product))->not->toContain('<span class="sr-cap-heart">');
});

it('takes the average score off the capsule', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct();

    expect(rbgPage($product))->toContain('<span class="sr-cap-avg">');

    rbgSave(['review_badge_avg' => false])->assertOk();

    expect(rbgPage($product))->not->toContain('<span class="sr-cap-avg">');
});

it('takes the review count off the capsule', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct();

    expect(rbgPage($product))->toContain('<span class="sr-cap-count">');

    rbgSave(['review_badge_count' => false])->assertOk();

    expect(rbgPage($product))->not->toContain('<span class="sr-cap-count">');
});

it('changes the count wording, substituting the real number for {n}', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct(3);

    rbgSave(['review_badge_label' => 'Loved by {n} shoppers'])->assertOk();

    expect(rbgPage($product))->toContain('Loved by 3 shoppers');
});

it('changes the star colour on the page', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct();

    rbgSave(['review_badge_colour' => '#15A85A'])->assertOk();

    expect(rbgPage($product))->toContain('style="color:#15A85A"');
});

it('takes the sold note off the inline line', function () {
    rbgAsAdmin();

    // Over 999 sales, or the note never appears at all and the test would pass
    // against a page that was never going to show it.
    $product = rbgReviewedProduct(3, 5, ['total_sales' => 4200]);

    expect(rbgPage($product))->toContain('k+ sold');

    rbgSave(['review_badge_sold' => false])->assertOk();

    expect(rbgPage($product))->not->toContain('k+ sold');
});

/* ----------------------------------------------------------------- the themes */

it('applies a preset by writing the keys the storefront already reads', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct();

    $response = test()->postJson('/admin-api/review-badges/theme', ['theme' => 'trust']);

    $response->assertOk();

    rbgFlush();

    expect($response->json('active_theme'))->toBe('trust');

    $html = rbgPage($product);

    expect($html)->toContain('style="color:#15A85A"')->toContain('Loved by 3 shoppers');
});

it('reports the active theme by comparing values, not by remembering one', function () {
    rbgAsAdmin();

    test()->postJson('/admin-api/review-badges/theme', ['theme' => 'minimal'])->assertOk();
    rbgFlush();

    expect(test()->get('/admin-api/review-badges')->json('active_theme'))->toBe('minimal');

    // Change one value by hand and the screen must say Custom. A stored theme
    // name would go on claiming Minimal here, which is the whole reason there
    // is no `review_badge_theme` key.
    rbgSave(['review_badge_colour' => '#123456'])->assertOk();

    expect(test()->get('/admin-api/review-badges')->json('active_theme'))->toBe('custom');
});

it('refuses a theme it does not have', function () {
    rbgAsAdmin();

    test()->postJson('/admin-api/review-badges/theme', ['theme' => 'neon'])->assertStatus(422);
});

/* ------------------------------------------------------------- the colour guard */

it('refuses anything that is not a colour, so nothing else can reach a style attribute', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct();

    // A CSS payload that never needs a quote, and so is not stopped by Blade's
    // escaping — see ReviewBadgeSettings::colour().
    rbgSave(['review_badge_colour' => 'red;position:fixed;inset:0;z-index:9999'])->assertStatus(422);

    $html = rbgPage($product);

    // The whole payload, not the substring 'position:fixed' — a product page
    // has plenty of legitimate fixed positioning in its own stylesheet, and an
    // assertion that cannot tell the two apart proves nothing.
    expect($html)->not->toContain('color:red;position:fixed')
        ->and($html)->toContain('style="color:#E8A33D"');
});

it('folds a stored value that is not a colour back to the default on read', function () {
    rbgAsAdmin();

    // Hand-edited row, older build, WordPress export — the endpoint is not the
    // only way a value reaches this column.
    app(SettingsService::class)->set('review_badge_colour', 'javascript:alert(1)');
    rbgFlush();

    expect(test()->get('/admin-api/review-badges')->json('settings.review_badge_colour'))->toBe('#E8A33D');
});

it('expands a three-digit colour rather than refusing it', function () {
    rbgAsAdmin();

    $product = rbgReviewedProduct();

    rbgSave(['review_badge_colour' => '#0f0'])->assertOk();

    expect(rbgPage($product))->toContain("style=\"color:#00FF00\"");
});

/* ------------------------------------------------------------ partial saves */

it('does not reset the keys the other screen owns when only one is saved', function () {
    rbgAsAdmin();

    rbgSave(['review_badge_colour' => '#111111'])->assertOk();
    rbgSave(['review_capsule_style' => 'both'])->assertOk();

    $settings = test()->get('/admin-api/review-badges')->json('settings');

    expect($settings['review_badge_colour'])->toBe('#111111')
        ->and($settings['review_capsule_style'])->toBe('both');
});

/* ---------------------------------------------------- no key without a reader */

it('has invented no setting of its own — every key is one the product page already reads', function () {
    $blade = file_get_contents(base_path('resources/views/store/product.blade.php'));

    foreach (array_keys(ReviewBadgeSettings::SCHEMA) as $key) {
        expect($blade)->toContain(
            $key,
            // A key this screen writes and the product page never mentions is a
            // dead control, which is the defect this repo has shipped before.
        );
    }

    expect(ReviewBadgeSettings::SCHEMA)->toHaveCount(7);
});
