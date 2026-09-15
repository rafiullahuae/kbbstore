<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\ReviewStatus;
use Tests\Support\ReviewsAdminRoutes;

/**
 * Store → Reviews → All Reviews.
 *
 * The screen this replaces was the shape CLAUDE.md keeps describing: it looked
 * finished. One unpaginated fetch of the whole table, no search, no rating or
 * product filter, the review body cut at 140 characters with no way to read the
 * rest, and chip counts taken from the entire table while the list beside them
 * was filtered.
 *
 * Four things are pinned hardest, because each is a way to be wrong quietly:
 *
 *   THE GUARD. The list carries `author_email` and the detail view carries the
 *   reviewer's `ip`. CLAUDE.md names both as data that leaked in production,
 *   and /api/* in this app is unauthenticated by design, so being on the wrong
 *   side of that line is a reviewer-database leak. Every route is asserted
 *   against an anonymous caller, a signed-in storefront shopper AND a plain
 *   `web` user — and the middleware is read back off the REGISTERED routes,
 *   because RouteRegistrar::middleware() replaces rather than appends and a
 *   harness that gets that wrong makes every 401 assertion pass against
 *   nothing.
 *
 *   THE CHIP COUNTS. They describe the set the OTHER filters already narrowed
 *   to, and page 2 reports the same totals as page 1 — the MySQL 1140 / lost
 *   OFFSET bug that shipped twice in this repo.
 *
 *   THE SEARCH ESCAPE. `%` and `_` are ordinary characters in a review body.
 *
 *   THE VOCABULARY. `spam` is moderatable, `rejected` is accepted and folded,
 *   and nothing stores a value outside ReviewStatus::ALL.
 */

/* ------------------------------------------------------------------ fixtures */

function amAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'AM Owner',
        'email' => 'am-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function amAsAdmin(): void
{
    ReviewsAdminRoutes::wire(app());
    test()->actingAs(amAdmin(), 'admin');
}

function amProduct(array $overrides = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'am-product-' . $n . '-' . uniqid(),
        'name' => 'AM Product ' . $n,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 50.00,
        'stock_status' => 'instock',
    ], $overrides));
}

function amReview(array $overrides = []): Review
{
    static $n = 0;
    $n++;

    return Review::create(array_merge([
        'product_id' => null,
        'author_name' => 'AM Reviewer ' . $n,
        'author_email' => 'am-reviewer-' . $n . '@example.test',
        'rating' => 5,
        'title' => 'AM title ' . $n,
        'content' => 'AM body ' . $n,
        'status' => ReviewStatus::PENDING,
        'ip' => '203.0.113.' . (($n % 250) + 1),
    ], $overrides));
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on every route the reviews screen adds', function () {
    ReviewsAdminRoutes::wire(app());

    $review = amReview(['author_email' => 'am-secret@example.test', 'ip' => '198.51.100.7']);

    $refusals = [
        ['get', '/admin-api/reviews/list'],
        ['get', '/admin-api/reviews/export'],
        ['get', '/admin-api/reviews/' . $review->id],
        ['put', '/admin-api/reviews/' . $review->id . '/moderate'],
        ['post', '/admin-api/reviews/bulk-moderate'],
    ];

    foreach ($refusals as [$method, $uri]) {
        $response = match ($method) {
            'get' => test()->getJson($uri),
            'put' => test()->putJson($uri, ['status' => 'approved']),
            'post' => test()->postJson($uri, ['action' => 'approved', 'ids' => [$review->id]]),
        };

        expect($response->getStatusCode())->toBe(401, "{$method} {$uri} was not refused");

        // Not merely refused — nothing leaked in the refusal body either.
        expect($response->getContent())->not->toContain('am-secret@example.test')
            ->and($response->getContent())->not->toContain('198.51.100.7');
    }

    // And the anonymous caller wrote nothing.
    expect($review->fresh()->status)->toBe(ReviewStatus::PENDING);
});

it('refuses a signed-in non-admin as firmly as an anonymous one', function () {
    ReviewsAdminRoutes::wire(app());

    $review = amReview();

    // A shopper signed into the storefront. The `customer` guard is
    // deliberately separate from `admin` (config/auth.php says so in as many
    // words); this is the assertion that the separation is real, not just
    // documented.
    $shopper = Customer::create([
        'name' => 'AM Shopper',
        'email' => 'am-shopper-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($shopper, 'customer');

    expect(test()->getJson('/admin-api/reviews/list')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/reviews/export')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/reviews/' . $review->id)->getStatusCode())->toBe(401)
        ->and(test()->putJson('/admin-api/reviews/' . $review->id . '/moderate', ['status' => 'approved'])->getStatusCode())->toBe(401)
        ->and(test()->postJson('/admin-api/reviews/bulk-moderate', ['action' => 'approved', 'ids' => [$review->id]])->getStatusCode())->toBe(401);

    // And a site user on the default `web` guard, which is neither of those.
    $user = User::create([
        'name' => 'AM Web User',
        'email' => 'am-webuser-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');

    expect(test()->getJson('/admin-api/reviews/list')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/reviews/export')->getStatusCode())->toBe(401)
        ->and(test()->getJson('/admin-api/reviews/' . $review->id)->getStatusCode())->toBe(401);

    expect($review->fresh()->status)->toBe(ReviewStatus::PENDING);
});

it('really does mount every route behind the admin guard, not just appear to', function () {
    ReviewsAdminRoutes::wire(app());

    $routes = ReviewsAdminRoutes::registered();

    // Five routes, and the count is asserted so a sixth cannot be added without
    // this file noticing it needs a guard assertion too.
    expect($routes)->toHaveCount(5);

    foreach ($routes as $route) {
        // The trap this exists for: RouteRegistrar::middleware() REPLACES the
        // pending middleware, so a harness that chains it twice registers
        // routes with no `auth:admin` at all while reading as though it did —
        // and every 401 above would then be passing against nothing. Read back
        // off the registered route, not off the harness's intent.
        expect($route->middleware())->toContain('auth:admin')
            ->and($route->middleware())->toContain('web')
            ->and($route->middleware())->toContain(\App\Http\Middleware\NoStoreAdminApi::class);
    }
});

it('does not collide with the review routes routes/web.php already registers', function () {
    ReviewsAdminRoutes::wire(app());

    // AdminController owns GET /admin-api/reviews, PUT /admin-api/reviews/{id}
    // and POST /admin-api/reviews/bulk, and belongs to another lane. Laravel
    // dispatches the first matching route, so anything registered here on an
    // identical method+URI would be dead code that still looks wired.
    foreach (ReviewsAdminRoutes::registered() as $route) {
        $uri = $route->uri();

        expect($uri)->not->toBe('admin-api/reviews')
            ->and($uri)->not->toBe('admin-api/reviews/bulk');

        // PUT /admin-api/reviews/{id} is web.php's; ours is .../{review}/moderate.
        if (in_array('PUT', $route->methods(), true)) {
            expect($uri)->toEndWith('/moderate');
        }
    }
});

it('documents for the integrator exactly where the file must be mounted', function () {
    $header = (string) file_get_contents(base_path('routes/reviews-admin.php'));

    expect($header)->toContain('admin-api')
        ->and($header)->toContain('auth:admin')
        ->and($header)->toContain("require __DIR__.'/reviews-admin.php';")
        // And why the list is not at GET /admin-api/reviews, which web.php
        // already registers against AdminController.
        ->and($header)->toContain('/admin-api/reviews/list');
});

/* ----------------------------------------------------------------- the list */

it('paginates instead of handing back the whole table', function () {
    amAsAdmin();

    foreach (range(1, 30) as $i) {
        amReview();
    }

    $body = test()->getJson('/admin-api/reviews/list?per_page=10')->assertOk()->json();

    expect($body['reviews'])->toHaveCount(10)
        ->and($body['total'])->toBe(30)
        ->and($body['pages'])->toBe(3)
        ->and($body['page'])->toBe(1);
});

it('reads the full review text from the detail endpoint, not a truncated list cell', function () {
    amAsAdmin();

    $long = str_repeat('This review is long enough that a 240-character table cell cannot hold it. ', 12);
    $review = amReview(['content' => $long]);

    $list = test()->getJson('/admin-api/reviews/list')->assertOk()->json();
    $row = collect($list['reviews'])->firstWhere('id', $review->id);

    // The list says it is truncated AND how long the real thing is, so the
    // screen knows whether opening it will actually show more.
    expect($row['truncated'])->toBeTrue()
        ->and(mb_strlen($row['excerpt']))->toBe(240)
        ->and($row['length'])->toBe(mb_strlen($long));

    $detail = test()->getJson('/admin-api/reviews/' . $review->id)->assertOk()->json();

    expect($detail['review']['content'])->toBe($long)
        ->and($detail['review']['truncated'])->toBeFalse();
});

it('keeps a business review — one with no product — on the screen', function () {
    amAsAdmin();

    // product_id NULL is a business review; in WordPress that was product_id 0
    // and the schema says so beside the column. An inner join would drop every
    // one of them, which is how an unmoderated review becomes invisible rather
    // than merely unapproved.
    $business = amReview(['product_id' => null]);

    $body = test()->getJson('/admin-api/reviews/list')->assertOk()->json();

    $row = collect($body['reviews'])->firstWhere('id', $business->id);

    expect($row)->not->toBeNull()
        ->and($row['product_id'])->toBeNull()
        ->and($row['product'])->toBeNull();
});

/* --------------------------------------------------------------- the filters */

it('filters by status, rating and product at once', function () {
    amAsAdmin();

    $a = amProduct();
    $b = amProduct();

    amReview(['product_id' => $a->id, 'rating' => 5, 'status' => ReviewStatus::PENDING]);
    $wanted = amReview(['product_id' => $a->id, 'rating' => 1, 'status' => ReviewStatus::PENDING]);
    amReview(['product_id' => $a->id, 'rating' => 1, 'status' => ReviewStatus::APPROVED]);
    amReview(['product_id' => $b->id, 'rating' => 1, 'status' => ReviewStatus::PENDING]);

    $body = test()->getJson('/admin-api/reviews/list?filter=pending&rating=1&product_id=' . $a->id)
        ->assertOk()->json();

    expect($body['reviews'])->toHaveCount(1)
        ->and($body['reviews'][0]['id'])->toBe($wanted->id);
});

it('filters to the business reviews, which have no product id to filter by', function () {
    amAsAdmin();

    $product = amProduct();

    amReview(['product_id' => $product->id]);
    $business = amReview(['product_id' => null]);

    $body = test()->getJson('/admin-api/reviews/list?product_id=business')->assertOk()->json();

    expect($body['reviews'])->toHaveCount(1)
        ->and($body['reviews'][0]['id'])->toBe($business->id);
});

it('counts the chips over the set the other filters already narrowed to', function () {
    amAsAdmin();

    $a = amProduct();
    $b = amProduct();

    // Product A: 2 pending, 1 approved, 1 spam.
    amReview(['product_id' => $a->id, 'status' => ReviewStatus::PENDING]);
    amReview(['product_id' => $a->id, 'status' => ReviewStatus::PENDING]);
    amReview(['product_id' => $a->id, 'status' => ReviewStatus::APPROVED]);
    amReview(['product_id' => $a->id, 'status' => ReviewStatus::SPAM]);

    // Product B: a pile of approved, which must NOT show up in A's counts.
    foreach (range(1, 7) as $i) {
        amReview(['product_id' => $b->id, 'status' => ReviewStatus::APPROVED]);
    }

    $all = test()->getJson('/admin-api/reviews/list')->assertOk()->json();

    expect($all['counts'])->toMatchArray(['all' => 11, 'pending' => 2, 'approved' => 8, 'spam' => 1]);

    $narrowed = test()->getJson('/admin-api/reviews/list?product_id=' . $a->id)->assertOk()->json();

    // This is the whole point: a count beside a filtered list must describe the
    // filtered list, not the table.
    expect($narrowed['counts'])->toMatchArray(['all' => 4, 'pending' => 2, 'approved' => 1, 'spam' => 1]);

    // And picking a chip does not change the counts — the chip is the thing
    // being counted, so lifting only it is what makes the numbers stable.
    $onChip = test()->getJson('/admin-api/reviews/list?product_id=' . $a->id . '&filter=spam')
        ->assertOk()->json();

    expect($onChip['counts'])->toMatchArray(['all' => 4, 'pending' => 2, 'approved' => 1, 'spam' => 1])
        ->and($onChip['reviews'])->toHaveCount(1);
});

it('reports the same totals on page two as on page one', function () {
    amAsAdmin();

    foreach (range(1, 12) as $i) {
        amReview(['status' => ReviewStatus::PENDING]);
    }
    foreach (range(1, 5) as $i) {
        amReview(['status' => ReviewStatus::APPROVED]);
    }

    $page1 = test()->getJson('/admin-api/reviews/list?per_page=10&page=1')->assertOk()->json();
    $page2 = test()->getJson('/admin-api/reviews/list?per_page=10&page=2')->assertOk()->json();

    /*
     * THE BUG THIS EXISTS FOR, and it shipped twice in this repo. An aggregate
     * computed from the same builder as the page inherits its OFFSET; an
     * aggregate returns one row, so `skip 10` leaves none and every total reads
     * zero from page two on — silently, while the endpoint still answers 200.
     * Wrong on every engine, SQLite included, so this is catchable here.
     */
    expect($page2['counts'])->toBe($page1['counts'])
        ->and($page2['total'])->toBe($page1['total'])
        ->and($page1['total'])->toBe(17)
        ->and($page2['reviews'])->toHaveCount(7);
});

/* ---------------------------------------------------------------- the search */

it('escapes % and _ in a search instead of treating them as wildcards', function () {
    amAsAdmin();

    $literal = amReview(['title' => 'AM worth 100% of it', 'content' => 'body one']);
    $other = amReview(['title' => 'AM worth 1000 of it', 'content' => 'body two']);

    /*
     * Unescaped, `100%` is "100 followed by anything" and matches both rows.
     * MySQL defaults to a backslash escape and SQLite has NO default escape at
     * all, so ESCAPE '!' is named explicitly — otherwise the same search
     * behaves differently under the suite than it does in production.
     */
    $body = test()->getJson('/admin-api/reviews/list?search=' . urlencode('100%'))->assertOk()->json();

    $ids = collect($body['reviews'])->pluck('id');

    expect($ids)->toContain($literal->id)
        ->and($ids)->not->toContain($other->id)
        ->and($body['counts']['all'])->toBe(1);

    // The underscore is the single-character wildcard and has the same problem.
    $u1 = amReview(['title' => 'AM size_5 tube']);
    amReview(['title' => 'AM sizeX5 tube']);

    $underscore = test()->getJson('/admin-api/reviews/list?search=' . urlencode('size_5'))->assertOk()->json();

    expect(collect($underscore['reviews'])->pluck('id')->all())->toBe([$u1->id]);

    // And the escape character itself is not a way to break the pattern.
    $bang = amReview(['title' => 'AM bang!bang']);

    $escaped = test()->getJson('/admin-api/reviews/list?search=' . urlencode('bang!bang'))->assertOk()->json();

    expect(collect($escaped['reviews'])->pluck('id')->all())->toBe([$bang->id]);
});

it('searches the product name and the review id too', function () {
    amAsAdmin();

    $product = amProduct(['name' => 'AM Snail Mucin Essence']);
    $onProduct = amReview(['product_id' => $product->id, 'title' => 'nothing matching here']);
    amReview(['title' => 'unrelated']);

    $byProduct = test()->getJson('/admin-api/reviews/list?search=' . urlencode('Snail Mucin'))
        ->assertOk()->json();

    expect(collect($byProduct['reviews'])->pluck('id')->all())->toBe([$onProduct->id]);

    $byId = test()->getJson('/admin-api/reviews/list?search=' . $onProduct->id)->assertOk()->json();

    expect(collect($byId['reviews'])->pluck('id'))->toContain($onProduct->id);
});

/* ------------------------------------------------------------ the vocabulary */

it('moderates a spam review instead of answering 422 for it', function () {
    amAsAdmin();

    // The exact row that could not be moderated at all before: it carried the
    // schema's own third value, the admin refused it, and it appeared under no
    // chip.
    $imported = amReview(['status' => ReviewStatus::SPAM]);

    test()->putJson('/admin-api/reviews/' . $imported->id . '/moderate', ['status' => 'approved'])
        ->assertOk()
        ->assertJsonPath('status', ReviewStatus::APPROVED);

    expect($imported->fresh()->status)->toBe(ReviewStatus::APPROVED);

    // And it is reachable from a chip.
    $body = test()->getJson('/admin-api/reviews/list?filter=spam')->assertOk()->json();

    expect($body['counts'])->toHaveKey('spam');
});

it('accepts the legacy `rejected` spelling and stores the canonical one', function () {
    amAsAdmin();

    $review = amReview();

    test()->putJson('/admin-api/reviews/' . $review->id . '/moderate', ['status' => 'rejected'])
        ->assertOk()
        // The response tells the caller what was actually stored, so an old
        // client cannot believe it wrote a value that does not exist.
        ->assertJsonPath('status', ReviewStatus::SPAM);

    expect($review->fresh()->status)->toBe(ReviewStatus::SPAM)
        ->and(ReviewStatus::ALL)->not->toContain('rejected');
});

it('refuses a status outside the vocabulary', function () {
    amAsAdmin();

    $review = amReview();

    test()->putJson('/admin-api/reviews/' . $review->id . '/moderate', ['status' => 'trash'])
        ->assertStatus(422);

    expect($review->fresh()->status)->toBe(ReviewStatus::PENDING);
});

it('never stores a status outside the canonical set, whatever route it came in by', function () {
    amAsAdmin();

    $ids = collect(ReviewStatus::accepted())
        ->map(fn ($status) => [amReview()->id, $status])
        ->all();

    foreach ($ids as [$id, $status]) {
        test()->putJson('/admin-api/reviews/' . $id . '/moderate', ['status' => $status])->assertOk();
    }

    $bulk = amReview();

    foreach (ReviewStatus::accepted() as $action) {
        test()->postJson('/admin-api/reviews/bulk-moderate', ['action' => $action, 'ids' => [$bulk->id]])
            ->assertOk();
    }

    $stored = Review::query()->pluck('status')->unique()->values()->all();

    foreach ($stored as $status) {
        expect(ReviewStatus::ALL)->toContain($status);
    }
});

/* ------------------------------------------------------------- bulk actions */

it('approves, rejects and deletes in bulk', function () {
    amAsAdmin();

    $ids = collect(range(1, 5))->map(fn () => amReview()->id)->all();

    test()->postJson('/admin-api/reviews/bulk-moderate', ['action' => 'approved', 'ids' => $ids])
        ->assertOk()
        ->assertJsonPath('affected', 5);

    expect(Review::whereKey($ids)->where('status', ReviewStatus::APPROVED)->count())->toBe(5);

    // The old screen's word, on the new vocabulary.
    test()->postJson('/admin-api/reviews/bulk-moderate', ['action' => 'rejected', 'ids' => $ids])
        ->assertOk();

    expect(Review::whereKey($ids)->where('status', ReviewStatus::SPAM)->count())->toBe(5);

    test()->postJson('/admin-api/reviews/bulk-moderate', ['action' => 'delete', 'ids' => $ids])
        ->assertOk()
        ->assertJsonPath('affected', 5);

    expect(Review::whereKey($ids)->count())->toBe(0);
});

it('leaves rows that are already correct alone in a bulk action', function () {
    amAsAdmin();

    $already = amReview(['status' => ReviewStatus::APPROVED]);
    $waiting = amReview(['status' => ReviewStatus::PENDING]);

    $body = test()->postJson('/admin-api/reviews/bulk-moderate', [
        'action' => 'approved',
        'ids' => [$already->id, $waiting->id],
    ])->assertOk()->json();

    // Two requested, one actually written. Reported separately so the screen
    // can say "1 approved" rather than claiming it changed something it did not
    // — and so `updated_at`, which this screen sorts on, does not move on a row
    // nobody touched.
    expect($body['requested'])->toBe(2)
        ->and($body['affected'])->toBe(1);
});

it('bounds one bulk action so a stuck loop cannot empty the table', function () {
    amAsAdmin();

    test()->postJson('/admin-api/reviews/bulk-moderate', [
        'action' => 'delete',
        'ids' => range(1, 501),
    ])->assertStatus(422);
});

/* -------------------------------------------------------------- the CSV ---- */

it('exports the filtered view, not the whole table, and neutralises formulas', function () {
    amAsAdmin();

    $product = amProduct(['name' => 'AM Exported Product']);

    // Every one of these is a cell Excel, LibreOffice and Sheets would EXECUTE,
    // and every one of them is typed by the public on this store.
    amReview([
        'product_id' => $product->id,
        'status' => ReviewStatus::PENDING,
        'author_name' => '=HYPERLINK("http://evil.test","click")',
        'title' => '+1234',
        'content' => "-cmd|' /c calc'!A1",
        'reply' => '@SUM(1:2)',
    ]);

    amReview(['status' => ReviewStatus::APPROVED, 'author_name' => 'AM Not In The Export']);

    $response = test()->get('/admin-api/reviews/export?filter=pending');

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $csv = $response->streamedContent();

    // The filter is respected: the approved row is not in the file.
    expect($csv)->not->toContain('AM Not In The Export');

    // Each dangerous leading character is quoted out.
    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->toContain("'+1234")
        ->and($csv)->toContain("'-cmd")
        ->and($csv)->toContain("'@SUM");

    // A formula that is NOT quoted must not appear — this is the assertion that
    // fails if csvCell() is removed.
    expect($csv)->not->toMatch('/(^|\n|,)"?=HYPERLINK/');
});

it('keeps the reviewer IP out of the CSV', function () {
    amAsAdmin();

    amReview(['ip' => '198.51.100.44', 'author_email' => 'am-in-csv@example.test']);

    $csv = test()->get('/admin-api/reviews/export')->assertOk()->streamedContent();

    // The email is there — contacting a reviewer is a real workflow and this is
    // an admin-only download. The IP is not: no workflow needs a column of IP
    // addresses, and it is the most sensitive field on the table.
    expect($csv)->toContain('am-in-csv@example.test')
        ->and($csv)->not->toContain('198.51.100.44');
});

/* --------------------------------------------------------------- PII on the
 * admin side: present where the owner needs it, and nowhere wider. */

it('shows the email on the list and the IP only on the detail view', function () {
    amAsAdmin();

    $review = amReview(['author_email' => 'am-moderate-me@example.test', 'ip' => '198.51.100.99']);

    $list = test()->getJson('/admin-api/reviews/list')->assertOk();

    expect($list->getContent())->toContain('am-moderate-me@example.test')
        ->and($list->getContent())->not->toContain('198.51.100.99');

    $detail = test()->getJson('/admin-api/reviews/' . $review->id)->assertOk();

    expect($detail->getContent())->toContain('198.51.100.99');
});

it('answers 404 for a review that is not there rather than leaking that it is not', function () {
    amAsAdmin();

    test()->getJson('/admin-api/reviews/999999')->assertStatus(404);
    test()->putJson('/admin-api/reviews/999999/moderate', ['status' => 'approved'])->assertStatus(404);
});
