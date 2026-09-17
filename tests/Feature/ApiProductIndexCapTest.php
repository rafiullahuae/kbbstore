<?php

declare(strict_types=1);

/**
 * How many rows GET /api/products will hand out in one request.
 *
 * /api/* is unauthenticated — CLAUDE.md says so in as many words — so the size
 * of one response is a security property, not a performance one. Narrowing the
 * column list (ApiProductIndexCostTest) made each ROW cheap and left the ROW
 * COUNT growing with the catalogue: the cost of the request the store invites
 * anybody to make was still whatever the owner's next import made it.
 *
 * Three things have to stay true together, and it is the third that is easy to
 * lose in a later edit:
 *
 *   1. there is a cap;
 *   2. the query string cannot raise it — a cap a caller can widen is a
 *      default, not a cap, and ?limit=100000 is the first thing anybody tries;
 *   3. the catalogue past the cap is still reachable, because a bound with no
 *      way past it silently deletes every product after the hundredth from
 *      this endpoint's answer, which is a worse bug than the one being fixed.
 *
 * Nothing here asserts the FIELDS of a row. That contract belongs to
 * ApiSecurityTest and ApiProductIndexCostTest and is deliberately not restated,
 * so that widening the allowlist cannot be made to look pinned by this file.
 */

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/*
 * The migration set leaves a seeded catalogue behind, and every assertion here
 * is about an exact number of rows. Counting "the ones this test made, plus
 * however many the seeder happens to leave this month" is a test that passes
 * for a reason nobody chose. RefreshDatabase rolls the delete back with
 * everything else, so no other file sees an emptier database than it did.
 */
beforeEach(function () {
    DB::table('products')->delete();
});

/** @return list<string> the slugs the endpoint answered with, in order */
function capSlugs(string $query = ''): array
{
    $body = test()->getJson('/api/products'.$query)->assertOk()->json();

    return array_map(static fn (array $row): string => $row['slug'], $body);
}

/**
 * Visible products, numbered so that `position` gives them a total order and
 * the slug says where in it each one belongs.
 *
 * @return int how many were made
 */
function capSeed(int $count): int
{
    for ($i = 1; $i <= $count; $i++) {
        Product::create([
            'slug' => 'cap-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'name' => 'Cap probe '.$i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000,
            'stock_status' => 'instock',
            'position' => $i,
        ]);
    }

    return $count;
}

it('stops at a hundred products however many are visible', function () {
    capSeed(105);

    expect(count(capSlugs()))->toBe(100, 'the public product index is unbounded again');
});

it('returns a small catalogue whole, so the cap changes nothing for most shops', function () {
    // The cap must not become a page size that truncates a shop of forty
    // products into two requests. Under the bound, the answer is the same one
    // this endpoint has always given.
    capSeed(40);

    expect(count(capSlugs()))->toBe(40);
});

it('refuses to let the query string raise the cap', function () {
    capSeed(105);

    // Each of these is a value that, taken at its word, would return more than
    // the cap allows -- or, in the case of the non-numeric ones, whatever
    // (int) makes of them.
    foreach (['?limit=100000', '?limit=101', '?limit=999999999999', '?limit=1e9'] as $query) {
        expect(count(capSlugs($query)))
            ->toBeLessThanOrEqual(100, 'the cap was raised from the query string by '.$query);
    }
});

it('never answers with nothing because of a limit it was handed', function () {
    capSeed(10);

    // The other end of the same clamp. `(int)` turns all of these into 0 or a
    // negative, and an unclamped `limit(0)` on this grammar returns an empty
    // array -- an endpoint anybody can silence by asking it to.
    foreach (['?limit=0', '?limit=-1', '?limit=-100', '?limit=abc', '?limit='] as $query) {
        expect(count(capSlugs($query)))
            ->toBeGreaterThan(0, 'the endpoint returned nothing for '.$query);
    }
});

it('lets a caller ask for fewer', function () {
    capSeed(20);

    expect(capSlugs('?limit=5'))->toBe(['cap-001', 'cap-002', 'cap-003', 'cap-004', 'cap-005']);
});

it('can still reach the products past the cap', function () {
    capSeed(105);

    $first = capSlugs();
    $second = capSlugs('?page=2');

    expect(count($first))->toBe(100);
    expect($second)->toBe(['cap-101', 'cap-102', 'cap-103', 'cap-104', 'cap-105']);

    // And the two pages are disjoint: a product on both is a product another
    // is on neither, which is what an unstable order under OFFSET produces.
    expect(array_intersect($first, $second))->toBe([]);
});

it('pages in a stable order when every product shares a position', function () {
    /*
     * `position` defaults to 0 and is 0 for most of this catalogue, so it is
     * the tie -- not the ordering -- that decides whether paging works. With
     * `orderBy('position')` alone the database may break those ties however it
     * likes, and two queries that disagree put one product on both pages and
     * another on neither.
     */
    for ($i = 1; $i <= 12; $i++) {
        Product::create([
            'slug' => 'tie-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'name' => 'Tie probe '.$i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 1000,
            'stock_status' => 'instock',
            'position' => 0,
        ]);
    }

    $all = array_merge(capSlugs('?limit=5&page=1'), capSlugs('?limit=5&page=2'), capSlugs('?limit=5&page=3'));

    expect(count($all))->toBe(12);
    expect(count(array_unique($all)))->toBe(12, 'a product was served on two pages at once');
});

it('still hides what was never visible, at any page or limit', function () {
    // The cap narrows the answer; it must not widen it. A LIMIT applied before
    // the visibility filter is a classic way to turn "the first hundred
    // visible" into "the visible ones among the first hundred rows".
    capSeed(3);

    Product::create([
        'slug' => 'cap-draft', 'name' => 'Cap draft', 'status' => 'draft',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock', 'position' => 1,
    ]);
    Product::create([
        'slug' => 'cap-hidden', 'name' => 'Cap hidden', 'status' => 'publish',
        'is_visible' => false, 'price' => 1000, 'stock_status' => 'instock', 'position' => 1,
    ]);

    foreach (['', '?limit=100', '?limit=2', '?page=1&limit=50'] as $query) {
        $slugs = capSlugs($query);

        expect(in_array('cap-draft', $slugs, true))->toBeFalse('a draft was published by '.$query);
        expect(in_array('cap-hidden', $slugs, true))->toBeFalse('a hidden product was published by '.$query);
    }
});

it('bounds the endpoint in the source as well as in the answer', function () {
    /*
     * The behavioural tests above all pass against an endpoint that reads its
     * limit straight from the query string, as long as nobody asks it for more
     * than the catalogue holds. This is the guard that the clamp is really
     * there: a bound named once, as a constant, rather than a number the next
     * edit can raise by hand in a query builder call.
     */
    $source = (string) file_get_contents(app_path('Http/Controllers/Api/ProductController.php'));

    // Comments talk about caps at length in this file, so the source is read
    // as code -- a regex over the raw text would match the prose.
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    expect(str_contains($code, 'INDEX_MAX'))
        ->toBeTrue('the cap is no longer a named constant on the controller');

    expect(preg_match('/min\s*\(\s*max\s*\(/', $code))
        ->toBe(1, 'the limit is no longer clamped at both ends before it reaches the query');
});
