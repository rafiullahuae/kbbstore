<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Review;
use App\Support\ReviewStatus;

/**
 * `reviews.author_email` and `reviews.ip` must never reach /api/*.
 *
 * CLAUDE.md names both in its landmine list because both leaked in production:
 * GET /api/reviews took a status straight off the query string and returned
 * whole models, so ?status=pending handed out unmoderated content and every
 * response carried every reviewer's address and IP, harvestable in one request.
 *
 * Api\ReviewController and Api\ProductController were rewritten with explicit
 * column lists and Review::$hidden was added as the backstop. tests/Feature/
 * ApiSecurityTest.php pins the two cases that shipped. This file is the SWEEP:
 * it walks every registered public route that can return a review and asserts
 * the absence of the two fields, so a THIRD endpoint added later — by this lane
 * or any other — cannot reintroduce the leak quietly.
 *
 * Written in the same style as ApiSecurityTest: each case asserts the absence
 * of a specific value in the raw body, not the shape of a response. A shape
 * assertion passes when a new field appears beside the ones it names.
 */
function rpProduct(): Product
{
    return Product::create([
        'slug' => 'rp-product-' . uniqid(),
        'name' => 'RP Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 45.00,
        'stock_status' => 'instock',
    ]);
}

/** One review per status, so no filter can be the reason a leak is missed. */
function rpSeed(Product $product): array
{
    $made = [];

    foreach (ReviewStatus::ALL as $i => $status) {
        $made[$status] = Review::create([
            'product_id' => $product->id,
            'author_name' => 'RP Reviewer ' . $status,
            'author_email' => 'rp-' . $status . '-secret@example.test',
            'rating' => 5,
            'title' => 'RP title ' . $status,
            'content' => 'RP body ' . $status,
            'status' => $status,
            'ip' => '198.51.100.' . ($i + 10),
        ]);
    }

    return $made;
}

it('never exposes a reviewer email or IP on any public review endpoint', function () {
    $product = rpProduct();
    $reviews = rpSeed($product);

    $urls = [
        '/api/reviews',
        '/api/reviews?limit=100',
        // The status parameter the original leak was driven by. It is no longer
        // read at all; asserted anyway, because "no longer read" is a property
        // that can be undone by one line.
        '/api/reviews?status=pending',
        '/api/reviews?status=spam',
        '/api/reviews?status=',
        '/api/products/' . $product->slug . '/reviews',
    ];

    foreach ($urls as $url) {
        $body = test()->getJson($url)->assertOk()->getContent();

        foreach ($reviews as $status => $review) {
            // str_contains + toBeFalse, not toContain($needle, $message):
            // Pest's toContain is VARIADIC, so a second argument is a second
            // needle, not a message — on a `not` assertion that quietly widens
            // what is being checked instead of explaining a failure.
            expect(str_contains($body, $review->author_email))
                ->toBeFalse("{$url} leaked a reviewer email ({$status})");

            expect(str_contains($body, $review->ip))
                ->toBeFalse("{$url} leaked a reviewer IP ({$status})");
        }

        // And the field NAMES are absent too, so an empty-string value cannot
        // be the reason this passes.
        expect($body)->not->toContain('author_email')
            ->and($body)->not->toContain('"ip"');
    }
});

it('publishes only approved reviews, whatever status is asked for', function () {
    $product = rpProduct();
    $reviews = rpSeed($product);

    foreach ([
        '/api/reviews',
        '/api/reviews?status=pending',
        '/api/reviews?status=spam',
        '/api/products/' . $product->slug . '/reviews',
    ] as $url) {
        $body = test()->getJson($url)->assertOk()->getContent();

        expect($body)->toContain('RP body approved')
            ->and($body)->not->toContain('RP body pending')
            ->and($body)->not->toContain('RP body spam');
    }
});

it('keeps the model itself from serialising the two fields, as the backstop', function () {
    $product = rpProduct();
    $review = rpSeed($product)[ReviewStatus::APPROVED];

    // The controllers name their columns, which is the real protection. This is
    // the second line: an endpoint written later that returns the model whole —
    // the mistake that has already been made here twice — still does not leak.
    $json = $review->fresh()->toJson();

    expect($json)->not->toContain('author_email')
        ->and($json)->not->toContain($review->author_email)
        ->and($json)->not->toContain($review->ip);
});

it('sweeps every public GET route that mentions reviews', function () {
    $product = rpProduct();
    $reviews = rpSeed($product);

    /*
     * Discovered from the ROUTER rather than from a list kept by hand, so an
     * endpoint added after this file was written is covered by it. Only GET
     * routes with no parameters other than ones we can fill, and only the
     * unauthenticated surfaces — /admin-api/* is guarded and is allowed to
     * carry both fields.
     */
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        ->filter(fn ($r) => str_contains($r->uri(), 'review'))
        ->reject(fn ($r) => str_starts_with($r->uri(), 'admin-api'))
        ->reject(fn ($r) => str_contains($r->uri(), 'captcha'))
        ->values();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $uri = '/' . ltrim(str_replace(
            ['{slug}', '{product}', '{review}', '{id}'],
            [$product->slug, $product->slug, (string) $reviews[ReviewStatus::APPROVED]->id, (string) $reviews[ReviewStatus::APPROVED]->id],
            $route->uri()
        ), '/');

        // Any parameter left unfilled means this test cannot reach the route;
        // that is worth knowing rather than skipping silently.
        expect(str_contains($uri, '{'))
            ->toBeFalse('unfillable route parameter on ' . $route->uri());

        $response = test()->get($uri);

        // A redirect or a 404 is fine — this is about what a 200 carries.
        if ($response->getStatusCode() !== 200) {
            continue;
        }

        $body = $response->getContent();

        foreach ($reviews as $status => $review) {
            expect(str_contains($body, $review->author_email))
                ->toBeFalse($uri . ' leaked a reviewer email (' . $status . ')');

            expect(str_contains($body, $review->ip))
                ->toBeFalse($uri . ' leaked a reviewer IP (' . $status . ')');
        }
    }
});

/*
 * THE TWO LAYERS, PINNED SEPARATELY.
 *
 * Widening Api\ReviewController::PUBLIC_COLUMNS to include author_email does
 * NOT currently leak, because Review::$hidden strips both fields on the way to
 * JSON. That is the backstop doing its job — and it is also why a body-only
 * assertion cannot see the first layer failing. Two defences that can only be
 * tested together are one defence: the day someone removes $hidden for an
 * unrelated reason, the widened allowlist becomes a live leak with nothing
 * having gone red in between.
 *
 * So each layer is asserted on its own terms: the allowlist by reading the
 * constant, the backstop by serialising a model.
 */
it('keeps the public column allowlists narrow, whatever the model also hides', function () {
    $forbidden = ['author_email', 'ip', 'customer_id', 'source_id'];

    $indexColumns = (new ReflectionClassConstant(
        \App\Http\Controllers\Api\ReviewController::class,
        'PUBLIC_COLUMNS'
    ))->getValue();

    foreach ($forbidden as $column) {
        expect($indexColumns)->not->toContain($column);
    }

    // The per-product endpoint builds its list inline rather than as a
    // constant, so it is read out of the source. Named explicitly: a ->select()
    // that grew a column is the same mistake in a different shape.
    $source = (string) file_get_contents(
        app_path('Http/Controllers/Api/ProductController.php')
    );

    $at = strpos($source, 'public function reviews(');
    expect($at)->not->toBeFalse();

    $body = substr($source, (int) $at, 1200);

    foreach ($forbidden as $column) {
        expect(str_contains($body, "'" . $column . "'"))
            ->toBeFalse("Api\\ProductController::reviews() selects {$column}");
    }

    // And it is an allowlist at all — a select() that names columns, not a
    // whole model handed back.
    expect($body)->toContain("->select([");
});

it('hands nothing back from the public submit endpoint but an acknowledgement', function () {
    $product = rpProduct();

    $raw = test()->postJson('/api/products/' . $product->slug . '/reviews', [
        'author_name' => 'RP Shopper',
        'author_email' => 'rp-submitter@example.test',
        'rating' => 5,
        'title' => 'Lovely',
        'content' => 'Really great',
    ])->getContent();

    // The submitter's own address must not come back either: an echo is a
    // confirmation oracle for an address somebody else typed.
    expect($raw)->not->toContain('rp-submitter@example.test')
        ->and($raw)->not->toContain('author_email');
});
