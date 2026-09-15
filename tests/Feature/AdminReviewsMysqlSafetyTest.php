<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Review;
use App\Support\ReviewStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewsAdminRoutes;
use Tests\Support\SqlShape;

/**
 * MySQL would reject these statements; SQLite runs them. So assert the SHAPE.
 *
 * The live Customers screen returned
 *
 *   SQLSTATE[42000] 1140 Mixing of GROUP columns (MIN(),MAX(),COUNT(),...)
 *   with no GROUP columns is illegal if there is no GROUP BY clause
 *
 * while every test passed, because selectRaw() APPENDS to the select list
 * rather than replacing it and applySort()/forPage() MUTATE the builder they
 * are handed. The Orders screen then copied the half-fixed helper and hit it
 * again. This screen is the third to compute chip counts beside a paginated,
 * sorted, joined list, so it is the third candidate — and it uses the one
 * shared implementation, App\Support\AggregatesQueries, rather than a fourth
 * variant.
 *
 * A test that merely calls the endpoint cannot catch any of this on SQLite: it
 * comes back 200. These inspect the SQL actually issued, which is
 * dialect-independent.
 */
function amMysqlAdmin(): AdminUser
{
    ReviewsAdminRoutes::wire(app());

    return AdminUser::create([
        'name' => 'AM Shape Owner',
        'email' => 'am-shape-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function amShapeFixtures(): Product
{
    $product = Product::create([
        'slug' => 'am-shape-' . uniqid(),
        'name' => 'AM Shape Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 30.00,
        'stock_status' => 'instock',
    ]);

    $n = 0;

    foreach ([ReviewStatus::PENDING, ReviewStatus::APPROVED, ReviewStatus::SPAM] as $status) {
        foreach (range(1, 12) as $i) {
            $n++;

            Review::create([
                'product_id' => $i % 3 === 0 ? null : $product->id,
                'author_name' => 'AM Shape ' . $n,
                'author_email' => 'am-shape-' . $n . '@example.test',
                'rating' => ($n % 5) + 1,
                'title' => 'AM shape title ' . $n,
                'content' => 'AM shape body ' . $n,
                'status' => $status,
                'ip' => '203.0.113.20',
            ]);
        }
    }

    return $product;
}

it('issues no statement MySQL would refuse, on any page of the list', function () {
    $product = amShapeFixtures();
    $admin = amMysqlAdmin();

    // Page 2 specifically: a surviving OFFSET on the count query is the failure
    // that is wrong on EVERY engine and still answers 200.
    foreach ([
        '/admin-api/reviews/list',
        '/admin-api/reviews/list?page=2&per_page=10',
        '/admin-api/reviews/list?filter=spam&rating=3&page=2&per_page=10',
        '/admin-api/reviews/list?product_id=' . $product->id . '&search=' . urlencode('shape'),
        '/admin-api/reviews/list?sort=rating_desc&page=2&per_page=10',
    ] as $url) {
        $captured = SqlShape::capture(function () use ($admin, $url) {
            test()->actingAs($admin, 'admin')->getJson($url)->assertOk();
        });

        expect($captured)->not->toBeEmpty();
        expect(SqlShape::violations($captured))->toBe([], 'on ' . $url);
    }
});

it('issues no statement MySQL would refuse from the export or the detail view', function () {
    $product = amShapeFixtures();
    $admin = amMysqlAdmin();

    $review = Review::query()->first();

    $captured = SqlShape::capture(function () use ($admin, $review) {
        test()->actingAs($admin, 'admin')->get('/admin-api/reviews/export?filter=approved')
            ->assertOk()->streamedContent();

        test()->actingAs($admin, 'admin')->getJson('/admin-api/reviews/' . $review->id)->assertOk();
    });

    expect(SqlShape::violations($captured))->toBe([]);
});

it('issues no statement MySQL would refuse when moderating', function () {
    $product = amShapeFixtures();
    $admin = amMysqlAdmin();

    $ids = Review::query()->limit(8)->pluck('id')->all();

    $captured = SqlShape::capture(function () use ($admin, $ids) {
        test()->actingAs($admin, 'admin')
            ->putJson('/admin-api/reviews/' . $ids[0] . '/moderate', ['status' => 'approved'])
            ->assertOk();

        // The grouped aggregate inside ProductRating runs here.
        test()->actingAs($admin, 'admin')
            ->postJson('/admin-api/reviews/bulk-moderate', ['action' => 'spam', 'ids' => $ids])
            ->assertOk();
    });

    expect(SqlShape::violations($captured))->toBe([]);
});

it('never mixes an aggregate with bare columns outside a GROUP BY', function () {
    amShapeFixtures();
    $admin = amMysqlAdmin();

    $seen = [];
    DB::listen(function ($q) use (&$seen) {
        $seen[] = $q->sql;
    });

    test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/reviews/list?page=2&per_page=10&search=shape')
        ->assertOk();

    expect($seen)->not->toBeEmpty();

    foreach ($seen as $sql) {
        $outer = preg_replace('/\([^()]*\)/', ' ', $sql);

        while ($outer !== ($next = preg_replace('/\([^()]*\)/', ' ', (string) $outer))) {
            $outer = $next;
        }

        $outer = (string) $outer;

        if (stripos($outer, 'group by') !== false) {
            continue;
        }

        $from = stripos($outer, ' from ');
        $select = $from === false ? $outer : substr($outer, 0, $from);

        if (preg_match('/\b(count|sum|min|max|avg)\b/i', $select) !== 1) {
            continue;
        }

        // Both identifier quotes again: a pattern that knew only SQLite's
        // double quote would pass vacuously on the engine this test is named
        // after.
        expect($select)->not->toMatch('/(?:"[a-z_]+"\."[a-z_]+"|`[a-z_]+`\.`[a-z_]+`)/i',
            "aggregate mixed with bare columns and no GROUP BY:\n" . $sql);
    }
});

it('sends exactly as many bindings as each statement has placeholders', function () {
    amShapeFixtures();
    $admin = amMysqlAdmin();

    // Dropping the select columns without dropping their bindings leaves the
    // driver more values than markers — the trap in the fix itself, and the
    // reason AggregatesQueries clears bindings['select'] and bindings['order']
    // rather than just the clauses.
    $mismatch = [];

    DB::listen(function ($q) use (&$mismatch) {
        if (substr_count($q->sql, '?') !== count($q->bindings)) {
            $mismatch[] = $q->sql;
        }
    });

    test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/reviews/list?page=2&per_page=10&search=' . urlencode('100%') . '&rating=3')
        ->assertOk();

    expect($mismatch)->toBe([]);
});

it('keeps the count query free of the page window, so page two is not zero', function () {
    amShapeFixtures();
    $admin = amMysqlAdmin();

    $counts = [];

    DB::listen(function ($q) use (&$counts) {
        /*
         * The chip-count statement specifically: the one that groups by
         * STATUS. Not every grouped count on this page — productOptions() is
         * also a COUNT(*) with a GROUP BY, and it orders by its own aggregate
         * alias and takes a LIMIT quite legitimately. Matching that one too
         * would make this assertion fail on correct SQL, which is how a guard
         * gets relaxed until it stops guarding anything.
         */
        // The identifier quote differs by driver — SQLite emits "reviews"."status",
        // MySQL emits `reviews`.`status` — so both are matched. A pattern that
        // knew only one of them found nothing on the other engine and the
        // assertion below passed over an empty list, which is the failure mode
        // this whole file exists to avoid.
        if (stripos($q->sql, 'count(*)') !== false
            && preg_match('/group\s+by\s+["`]?reviews["`]?\.["`]?status["`]?/i', $q->sql) === 1) {
            $counts[] = $q->sql;
        }
    });

    test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/reviews/list?page=3&per_page=5&sort=rating_desc')
        ->assertOk();

    expect($counts)->not->toBeEmpty();

    foreach ($counts as $sql) {
        // An aggregate that inherited the page window returns no row at all and
        // every total reads zero — silently, on every engine.
        expect($sql)->not->toMatch('/\boffset\b/i', $sql)
            ->and($sql)->not->toMatch('/\border by\b/i', $sql);
    }
});
