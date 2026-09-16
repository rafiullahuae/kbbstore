<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Review;
use App\Services\SettingsService;
use App\Support\StoreRating;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * /reviews may publish real approved reviews and nothing else.
 *
 * WHAT WAS THERE. resources/views/store/review-wall.blade.php carried
 * `var REVIEWS=[...]`: twelve invented customers with names, star ratings,
 * relative dates, review bodies and "helpful" counts, plus a computeStats()
 * that derived "4.9" and "Based on 128 reviews" from them. reviewWall() passed
 * no review data at all, so an approved review in the database could not reach
 * the page and the twelve strangers could not be removed from it by any action
 * available to the owner. Store\SeoFilesController submitted the URL to Google
 * unconditionally, described as "What customers say about the K-beauty products
 * they bought from us."
 *
 * Publishing invented customer reviews is not merely misleading; in the UAE
 * (Federal Law 15 of 2020 and its e-commerce rules), the EU (UCPD Annex I, as
 * amended by the Omnibus Directive) and the UK (DMCC Act 2024 s.236) it is
 * unlawful. That is why the central test here is not "the old names are gone"
 * but the stronger property:
 *
 *     EVERY REVIEWER NAME AND EVERY STAR RATING THE PAGE SERVES MUST BE
 *     TRACEABLE TO AN APPROVED ROW IN `reviews`.
 *
 * A test that only banned the twelve names would pass the moment someone
 * re-seeded the array with twelve different ones. This reads the names back out
 * of the rendered HTML and asserts each one is a row, so any fabricated card
 * fails whatever it is called.
 *
 * THE BANNED NAMES ARE ASSEMBLED AT RUN TIME rather than written out, because
 * the source-level guard below scans the repository for them and a guard cannot
 * be allowed to match the test that enforces it.
 */

/**
 * The twelve invented customers, never spelled out in this file.
 *
 * @return list<string>
 */
function rwFabricated(): array
{
    return [
        'Ais' . 'ha M.', 'Fat' . 'ima K.', 'Mar' . 'yam', 'Sar' . 'a A.',
        'No' . 'or', 'Hes' . 'sa', 'Lay' . 'la', 'Re' . 'em',
        'Mar' . 'iam', 'Hu' . 'da', 'Da' . 'na', 'Shai' . 'kha',
    ];
}

function rwProduct(string $suffix = ''): Product
{
    return Product::create([
        'slug' => 'wall-product-' . $suffix . uniqid(),
        'name' => 'Wall Product ' . $suffix,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'rating' => 0,
        'review_count' => 0,
    ]);
}

/**
 * One approved review with a name nothing in the repository could have guessed.
 */
function rwReview(string $name, int $rating = 5, array $attrs = []): Review
{
    return Review::create(array_merge([
        'product_id' => rwProduct()->id,
        'author_name' => $name,
        'author_email' => 'private-' . uniqid() . '@example.test',
        'rating' => $rating,
        'title' => 'Title for ' . $name,
        'content' => 'Body written by ' . $name . '.',
        'status' => 'approved',
        'verified' => true,
        'helpful' => 3,
        'ip' => '203.0.113.9',
    ], $attrs));
}

/** Seed `$n` approved reviews with distinctive names, newest last. */
function rwSeed(int $n, int $rating = 5): array
{
    $names = [];

    for ($i = 1; $i <= $n; $i++) {
        $name = 'Zephyrine Quillon ' . $i;
        rwReview($name, $rating);
        $names[] = $name;
    }

    return $names;
}

/** Every reviewer name the page rendered, read back out of the markup. */
function rwNamesOn(string $html): array
{
    preg_match_all('/<span class="sr-name"[^>]*>(.*?)<\/span>/s', $html, $m);

    return array_map(fn ($s) => html_entity_decode(trim(strip_tags($s)), ENT_QUOTES), $m[1]);
}

/** Every star rating the page rendered, as integers. */
function rwRatingsOn(string $html): array
{
    preg_match_all('/<[^>]*class="sr-stars"[^>]*data-rating="(\d)"/', $html, $m);

    return array_map('intval', $m[1]);
}

/**
 * Does the page carry an ELEMENT with this class?
 *
 * Not str_contains($html, 'sr-score'): this page carries its stylesheet inline,
 * so every class name it can render is in the markup whether or not anything
 * uses it, and a substring search answers true for all of them. Matching an
 * opening tag is the difference between "the rule exists" and "the block was
 * rendered".
 */
function rwHasElement(string $html, string $class): bool
{
    return preg_match('/<[a-z]+[^>]*\sclass="[^"]*\b' . preg_quote($class, '/') . '\b[^"]*"/i', $html) === 1;
}

/** Every review card element on the page, by the row id it claims to be. */
function rwCardIdsOn(string $html): array
{
    preg_match_all('/<article[^>]*class="sr-card"[^>]*data-review="(\d+)"/', $html, $m);

    return array_map('intval', $m[1]);
}

function rwGet(string $url = '/reviews'): string
{
    Cache::flush();
    StoreRating::forget();

    $response = test()->get($url);
    $response->assertOk();

    return $response->getContent();
}

// ---------------------------------------------------------------------------
// 1. Nothing invented, in any state.
// ---------------------------------------------------------------------------

it('serves none of the twelve invented customers, on an empty shop or a full one', function () {
    foreach ([0, 3, 9] as $seeded) {
        Review::query()->delete();
        rwSeed($seeded);

        $html = rwGet();

        foreach (rwFabricated() as $name) {
            expect(str_contains($html, $name))->toBeFalse(
                "The reviews page served the invented customer \"{$name}\" with {$seeded} real reviews in the database."
            );
        }
    }
});

it('serves no reviewer name that is not an approved row', function () {
    $real = rwSeed(7);

    // A pending row and a spam row, which must not reach the page either.
    rwReview('Unapproved Person', 5, ['status' => 'pending']);
    rwReview('Spammy Person', 1, ['status' => 'spam']);

    $names = rwNamesOn(rwGet());

    expect($names)->not->toBeEmpty('The page rendered no reviewer names at all.');

    foreach ($names as $name) {
        expect(in_array($name, $real, true))->toBeTrue(
            "The reviews page served \"{$name}\", which is not an approved review in the database."
        );
    }
});

it('serves no star rating and no card id that is not an approved row', function () {
    rwSeed(3, 4);
    rwSeed(2, 5);

    $html = rwGet();

    $approved = Review::query()->approved()->pluck('rating', 'id');

    foreach (rwCardIdsOn($html) as $id) {
        expect($approved->has($id))->toBeTrue(
            "The reviews page served a card for review #{$id}, which is not an approved row."
        );
    }

    $allowed = $approved->values()->all();

    foreach (rwRatingsOn($html) as $rating) {
        expect(in_array($rating, $allowed, true))->toBeTrue(
            "The reviews page served a {$rating}-star review that no approved row carries."
        );
    }
});

it('keeps a reviewer\'s email and IP off the page', function () {
    rwReview('Private Person', 5, ['author_email' => 'leaky@example.test', 'ip' => '198.51.100.7']);

    $html = rwGet();

    expect(str_contains($html, 'leaky@example.test'))->toBeFalse('The page printed a reviewer email address.');
    expect(str_contains($html, '198.51.100.7'))->toBeFalse('The page printed a reviewer IP address.');
});

// ---------------------------------------------------------------------------
// 2. The three states.
// ---------------------------------------------------------------------------

it('says plainly that there are no reviews yet, and invites one', function () {
    $html = rwGet();

    expect(rwCardIdsOn($html))->toBeEmpty('An empty shop rendered review cards.');
    expect(rwHasElement($html, 'sr-empty'))->toBeTrue('The empty state was not rendered.');
    expect(str_contains($html, 'No reviews yet'))->toBeTrue('The page did not say that there are no reviews yet.');

    // An invitation that goes somewhere real.
    expect(preg_match('/<a[^>]+class="sr-cta"[^>]+href="([^"]+)"/', $html, $m))->toBe(1,
        'The empty state offered no way to leave a review.');
    expect(str_contains($m[1], '/shop'))->toBeTrue('The invitation did not point at the shop.');

    // And no skeleton of a populated page: no score, no bars, no filters.
    expect(rwHasElement($html, 'sr-score'))->toBeFalse('An empty shop rendered the score block.');
    expect(rwHasElement($html, 'sr-bar'))->toBeFalse('An empty shop rendered the star distribution.');
    expect(rwHasElement($html, 'sr-filters'))->toBeFalse('An empty shop rendered filter controls.');
    expect(rwHasElement($html, 'sr-more'))->toBeFalse('An empty shop offered "load more".');
});

it('shows a few real reviews without inventing a shop-wide score', function () {
    $names = rwSeed(StoreRating::MINIMUM - 1);

    $html = rwGet();

    expect(rwCardIdsOn($html))->toHaveCount(count($names));
    expect(rwHasElement($html, 'sr-score'))->toBeFalse(
        'A shop with fewer than ' . StoreRating::MINIMUM . ' approved reviews published a shop-wide score.'
    );
    expect(rwHasElement($html, 'sr-avg'))->toBeFalse('An average was published below the threshold.');
    expect(rwHasElement($html, 'sr-too-few'))->toBeTrue(
        'The page did not say why no shop-wide score is shown.'
    );
});

it('publishes a shop-wide score only from the rows behind it', function () {
    // Four fives and one three: 23 / 5 = 4.6 exactly.
    rwSeed(4, 5);
    rwSeed(1, 3);

    $html = rwGet();

    expect(rwHasElement($html, 'sr-score'))->toBeTrue('A shop past the threshold published no score.');

    expect(preg_match('/<div class="sr-avg"[^>]*>([\d.]+)<\/div>/', $html, $m))->toBe(1, 'No average was rendered.');
    expect($m[1])->toBe('4.6', 'The published average is not the average of the approved rows.');

    expect(preg_match('/<div class="sr-count"[^>]*>(.*?)<\/div>/s', $html, $c))->toBe(1, 'No review count was rendered.');
    expect(str_contains($c[1], '5'))->toBeTrue('The published count is not the number of approved rows.');
});

it('never publishes the capsule figures the page used to hard-code', function () {
    rwSeed(8);

    $html = rwGet();

    expect(str_contains($html, '128 reviews'))->toBeFalse('The hard-coded "128 reviews" capsule is still served.');
    expect(preg_match('/<div class="sr-avg"[^>]*>4\.9<\/div>/', $html))->toBe(0,
        'The hard-coded 4.9 average is still served.');
});

// ---------------------------------------------------------------------------
// 3. Controls that do something, or say they are not built.
// ---------------------------------------------------------------------------

it('filters on real rows through real URLs', function () {
    rwSeed(3, 5);
    rwSeed(2, 4);

    expect(rwCardIdsOn(rwGet('/reviews?rfilter=5')))->toHaveCount(3);
    expect(rwCardIdsOn(rwGet('/reviews?rfilter=4')))->toHaveCount(2);
    expect(rwCardIdsOn(rwGet('/reviews?rfilter=all')))->toHaveCount(5);

    // An unknown filter falls back to all rather than showing nothing.
    expect(rwCardIdsOn(rwGet('/reviews?rfilter=' . urlencode('7 OR 1=1'))))->toHaveCount(5);
});

it('pages through real rows rather than pretending to', function () {
    rwSeed(\App\Support\ReviewWall::INITIAL + 4);

    $first = rwGet();
    expect(rwCardIdsOn($first))->toHaveCount(\App\Support\ReviewWall::INITIAL);
    expect(rwHasElement($first, 'sr-more'))->toBeTrue('A page with more rows behind it offered no way to reach them.');

    expect(preg_match('/<a[^>]+class="sr-more"[^>]+href="([^"]+)"/', $first, $m))->toBe(1);

    $more = rwGet('/reviews?' . parse_url(html_entity_decode($m[1]), PHP_URL_QUERY));
    expect(rwCardIdsOn($more))->toHaveCount(\App\Support\ReviewWall::INITIAL + 4);
    expect(rwHasElement($more, 'sr-more'))->toBeFalse('The last page still offered "load more".');
});

it('offers no submit form it cannot honour, and says where reviews are written', function () {
    rwSeed(6);

    $html = rwGet();

    // The old page's write sheet validated, showed "Thank you" and posted
    // nothing anywhere. POST /reviews/submit requires a product_id, which a
    // shop-wide page does not have.
    expect(preg_match('/<form[^>]*>/', $html))->toBe(0, 'The page carries a form again.');
    expect(rwHasElement($html, 'sr-unbuilt'))->toBeTrue('The page does not say the shop-wide form is not built.');
    expect(str_contains($html, 'not built'))->toBeTrue('The unbuilt note does not say so in words.');
});

// ---------------------------------------------------------------------------
// 4. The source, so the array cannot come back.
// ---------------------------------------------------------------------------

it('carries no seeded review array in the view', function () {
    $blade = file_get_contents(resource_path('views/store/review-wall.blade.php'));

    expect($blade)->not->toBeFalse();

    foreach (rwFabricated() as $name) {
        expect(str_contains($blade, $name))->toBeFalse("The view still contains the invented customer \"{$name}\".");
    }

    // The shape, not just the names: an object literal carrying a name and a
    // star rating is a seeded review whatever the names are.
    expect(preg_match('/\{\s*name\s*:.*?(rate|rating)\s*:/s', $blade))->toBe(0,
        'The view contains an object literal carrying a name and a rating.');
    expect(preg_match('/\bREVIEWS\s*=\s*\[/', $blade))->toBe(0, 'The view declares a REVIEWS array again.');
    expect(preg_match('/computeStats/', $blade))->toBe(0,
        'The view computes statistics client-side again.');
});

it('advertises the reviews page to search engines only when it has reviews', function () {
    Cache::flush();

    $empty = test()->get('/sitemap.xml');
    $empty->assertOk();
    expect(str_contains($empty->getContent(), '/reviews/'))->toBeFalse(
        'The sitemap submits /reviews/ to search engines on a shop with no reviews.'
    );

    rwSeed(1);
    Cache::flush();

    $filled = test()->get('/sitemap.xml');
    $filled->assertOk();
    expect(str_contains($filled->getContent(), '/reviews/'))->toBeTrue(
        'The sitemap omits /reviews/ even though the shop has approved reviews to show.'
    );
});

it('keeps the page itself reachable and indexable when it is empty', function () {
    $response = test()->get('/reviews');
    $response->assertOk();

    expect(str_contains($response->getContent(), 'noindex'))->toBeFalse(
        'An empty reviews page noindexes itself; absence from the sitemap is the lighter tool.'
    );
});

// ---------------------------------------------------------------------------
// 5. What it costs, with rows behind it.
// ---------------------------------------------------------------------------

/**
 * StorefrontQueryBudgetTest measures this page against a fixture that seeds no
 * reviews, so it can only ever see the empty branch. The number that matters is
 * the one with rows in it, and that it does not move as the shop collects more:
 * a page doing one query per card passes any ceiling on a small enough fixture.
 */
it('costs the same number of queries with 200 reviews as with 5', function () {
    /*
     * Reset the same two kinds of per-request state budgetReset() does, and for
     * the same reasons — but NOT the cache, which is shared between requests in
     * production too. Flushing it here would add the settings read back to
     * every measurement and report a number no real visitor pays.
     */
    $count = function (): int {
        SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/reviews')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    rwSeed(5);
    $count();                 // warm the per-process memos this page shares
    $small = $count();

    rwSeed(195);
    $large = $count();

    expect($large)->toBe(
        $small,
        "The reviews page ran {$large} queries for 200 reviews and {$small} for 5 — it is not batching its loads."
    );

    expect($small)->toBeLessThanOrEqual(
        6,
        "The reviews page ran {$small} queries; StorefrontQueryBudgetTest allows 6."
    );
});
