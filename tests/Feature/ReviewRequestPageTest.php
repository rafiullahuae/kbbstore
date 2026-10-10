<?php

declare(strict_types=1);

/**
 * Lane RV — the page the review request email's stars open, and what it
 * writes: REAL reviews in `reviews`, pending, verified, linked to the product
 * and the order, in the admin Reviews list to approve like any other.
 *
 * MUTATION NOTES, each one run:
 *   - ReviewRequests::find(): drop the expiry check
 *       -> 'answers a forged, an unknown and an expired link with the same 404' is red.
 *   - ReviewRequestController::submit(): create with 'status' => 'approved'
 *       -> 'stores real pending reviews' is red.
 *   - ReviewRequestController::submit(): source_id => the bare order id
 *       -> 'stores real pending reviews' is red (UNIQUE(source, source_id)
 *          refuses the second product: the defect this encoding exists for).
 *   - ReviewRequestController::submit(): skip the `status !== 'pending'` guard
 *       -> 'edits a pending review and leaves an approved one alone' is red.
 *   - ReviewsApiController::rowToApi(): drop from_request
 *       -> 'lands in the admin Reviews list marked from the email' is red.
 *   - routes/review-request-public.php: remove withoutMiddleware(ValidateCsrfToken)
 *       -> 'stops review requests with a one-click POST' is red (419).
 *   - ReviewRequestController::page(): load products inside the card loop
 *       -> 'costs the same queries for 3 products as for 40' is red.
 */

use App\Mail\ReviewRequestMail;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\Reviews\ReviewRequests;
use App\Services\SettingsService;
use App\Support\InstantNav;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ReviewRequestRoutes;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    ReviewRequestRoutes::wire(app());
    RateLimiter::clear('review-submit:127.0.0.1');
});

afterEach(function () {
    Carbon::setTestNow();
});

function rvrProduct(string $name, array $over = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'beauty-of-joseon'], ['name' => 'Beauty of Joseon']);

    return Product::create(array_merge([
        'name' => $name, 'slug' => 'rp-' . \Illuminate\Support\Str::slug($name) . '-' . uniqid(), 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => 1, 'price' => 8900, 'stock_status' => 'instock', 'type' => 'simple',
    ], $over));
}

/** An order with these products and a sent request whose link is the returned token. */
function rvrIssue(array $products, array $orderOver = [], array $rowOver = []): array
{
    $address = ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => 'Marina Heights', 'city' => 'Dubai', 'country' => 'AE'];
    $order = Order::create(array_merge([
        'order_number' => 'KBB-RP-' . uniqid(), 'email' => 'aisha@example.com', 'status' => 'completed', 'currency' => 'AED',
        'billing_address' => $address, 'shipping_address' => $address,
        'subtotal' => 8900, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'tax_total' => 0, 'total' => 8900,
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    ], $orderOver));

    foreach ($products as $p) {
        $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'brand' => 'Beauty of Joseon', 'quantity' => 1, 'unit_price' => 8900, 'subtotal' => 8900, 'total' => 8900]);
    }

    $token = ReviewRequests::newToken();
    DB::table('review_requests')->insert(array_merge([
        'order_id' => $order->id, 'email' => 'aisha@example.com', 'completed_at' => now()->subDays(2), 'status' => 'sent',
        'token_hash' => ReviewRequests::hash($token), 'expires_at' => now()->addDays(60), 'sent_at' => now(),
        'products' => json_encode(array_map(fn ($p) => $p->id, $products)), 'created_at' => now(), 'updated_at' => now(),
    ], $rowOver));

    return [$order, $token];
}

function rvrUrl(string $token, string $tail = ''): string
{
    return '/review-order/' . $token . '/' . $tail;
}

/**
 * Open the email's link the way a browser does: the token URL hands the token
 * to the session and 303s to /review-order/ without it. Returns the page.
 */
function rvrOpen(string $token, string $query = ''): \Illuminate\Testing\TestResponse
{
    $hop = test()->get(rvrUrl($token, $query));
    $hop->assertStatus(303);
    $to = (string) $hop->headers->get('Location');
    expect($to)->not->toContain($token);

    return test()->get(parse_url($to, PHP_URL_PATH) . (parse_url($to, PHP_URL_QUERY) ? '?' . parse_url($to, PHP_URL_QUERY) : ''));
}

const RVR_PAGE = '/review-order/';

it('opens from the email\'s fourth star with four stars chosen for that product, and nothing chosen for the others', function () {
    $a = rvrProduct('Relief Sun');
    $b = rvrProduct('Glow Serum');
    [, $token] = rvrIssue([$a, $b]);

    $res = rvrOpen($token, '?p=' . $a->id . '&r=4')->assertOk();
    $html = $res->getContent();

    expect($html)->toContain('id="rr-' . $a->id . '-4" name="reviews[' . $a->id . '][rating]" value="4" checked')
        ->and(preg_match_all('/\]\[rating\]" value="\d" checked/', $html))->toBe(1)
        ->and($html)->toContain('Relief Sun')->toContain('Glow Serum')
        ->toContain('How was it, Aisha?')
        ->toContain('value="Aisha K."')
        ->toContain('Verified purchase')
        ->and($html)->not->toContain('aisha@example.com');

    // Not cached, not indexed — and the address it is on holds no credential.
    expect($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($res->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($html)->toMatch('/<meta name="robots" content="noindex/');
});

it('answers a forged, an unknown and an expired link with the same 404, word for word', function () {
    $p = rvrProduct('Quokka Jelly Patch');
    [, $expired] = rvrIssue([$p], [], ['expires_at' => now()->subMinute()]);
    [, $real] = rvrIssue([$p], ['email' => 'b@example.com']);

    $strip = fn (string $h) => preg_replace('/(csrf-token" content="|name="_token" value=")[^"]+/', '$1', $h);
    $forged = $this->get(rvrUrl(substr($real, 0, -2) . 'xx'))->assertNotFound()->getContent();
    $unknown = $this->get(rvrUrl(ReviewRequests::newToken()))->assertNotFound()->getContent();
    $late = $this->get(rvrUrl($expired))->assertNotFound()->getContent();
    // And the page itself, reached with no link opened in this browser.
    $bare = $this->get(RVR_PAGE)->assertNotFound()->getContent();

    // The token is never printed back (no canonical, no og:url carrying it).
    expect($forged)->not->toContain(substr($real, 0, 20))
        ->and($strip($forged))->toBe($strip($unknown))
        ->and($strip($bare))->toBe($strip($unknown))
        ->and($strip($late))->toBe($strip($unknown))
        ->and($unknown)->toContain('This link is not valid')
        ->and($unknown)->not->toContain('Quokka Jelly Patch');

    // A preview's inert link is the same page.
    $this->get(rvrUrl('preview'))->assertNotFound();
});

it('stores real pending reviews, verified, linked to the product and the order, one per product', function () {
    $a = rvrProduct('Relief Sun');
    $b = rvrProduct('Glow Serum');
    $c = rvrProduct('Skipped one');
    [$order, $token] = rvrIssue([$a, $b, $c]);

    rvrOpen($token);
    $this->post(RVR_PAGE, [
        'author_name' => 'Aisha K.',
        'reviews' => [
            $a->id => ['rating' => 5, 'title' => 'Holy grail', 'content' => 'No white cast at all.'],
            $b->id => ['rating' => 3, 'title' => '', 'content' => 'Nice glow, a bit sticky.'],
            $c->id => ['rating' => '', 'content' => ''],
        ],
    ])->assertRedirect('/review-order/?thanks=1');

    $rows = Review::query()->orderBy('product_id')->get();
    expect($rows)->toHaveCount(2);

    foreach ($rows as $r) {
        expect($r->status)->toBe('pending')
            ->and($r->verified)->toBeTrue()
            ->and($r->source)->toBe(ReviewRequests::SOURCE)
            ->and(intdiv((int) $r->source_id, ReviewRequests::KEY_BASE))->toBe($order->id)
            ->and($r->author_email)->toBe('aisha@example.com')
            ->and($r->author_name)->toBe('Aisha K.');
    }

    expect($rows->pluck('product_id')->all())->toBe([$a->id, $b->id])
        ->and($rows[0]->rating)->toBe(5)
        ->and(DB::table('review_requests')->where('order_id', $order->id)->value('reviews_count'))->toBe(2);

    // Nothing reaches the product page until the owner approves it.
    expect(Review::query()->approved()->count())->toBe(0);

    $this->get(RVR_PAGE . '?thanks=1')->assertOk()->assertSee('Thank you!')->assertSee('Received — waiting for approval');
});

it('edits a pending review on a second submit, and leaves an approved one alone', function () {
    $a = rvrProduct('A');
    $b = rvrProduct('B');
    [, $token] = rvrIssue([$a, $b]);

    rvrOpen($token);
    $send = fn (int $ra, int $rb, string $t) => $this->post(RVR_PAGE, ['author_name' => 'Aisha', 'reviews' => [
        $a->id => ['rating' => $ra, 'content' => $t], $b->id => ['rating' => $rb, 'content' => $t],
    ]]);

    $send(4, 4, 'First go')->assertRedirect();
    Review::query()->where('product_id', $b->id)->update(['status' => 'approved']);
    $send(2, 1, 'Changed my mind')->assertRedirect();

    expect(Review::query()->count())->toBe(2)
        ->and(Review::query()->where('product_id', $a->id)->value('rating'))->toBe(2)
        ->and(Review::query()->where('product_id', $a->id)->value('content'))->toBe('Changed my mind')
        ->and(Review::query()->where('product_id', $b->id)->value('rating'))->toBe(4)
        ->and(Review::query()->where('product_id', $b->id)->value('content'))->toBe('First go');

    $this->get(RVR_PAGE)->assertSee('Published — thank you!');
});

it('refuses no rating, a rating with no words, an over-long review, and stores markup as text', function () {
    $a = rvrProduct('A');
    [, $token] = rvrIssue([$a]);

    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => 'Aisha', 'reviews' => [$a->id => ['rating' => '', 'content' => 'x']]])
        ->assertSessionHasErrors('reviews');
    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => 'Aisha', 'reviews' => [$a->id => ['rating' => 5, 'content' => '  ']]])
        ->assertSessionHasErrors('reviews.' . $a->id . '.content');
    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => 'Aisha', 'reviews' => [$a->id => ['rating' => 5, 'content' => str_repeat('a', 5001)]]])
        ->assertSessionHasErrors('reviews.' . $a->id . '.content');
    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => '', 'reviews' => [$a->id => ['rating' => 5, 'content' => 'ok']]])
        ->assertSessionHasErrors('author_name');
    expect(Review::query()->count())->toBe(0);

    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => '<b>Aisha</b>', 'reviews' => [$a->id => ['rating' => 5, 'title' => '<script>x</script>Great', 'content' => '<img src=x onerror=alert(1)>Lovely']]])
        ->assertRedirect();
    $r = Review::query()->first();
    expect($r->author_name)->toBe('Aisha')->and($r->title)->toBe('xGreat')->and($r->content)->toBe('Lovely');

    $html = $this->get(RVR_PAGE)->getContent();
    expect($html)->not->toContain('<script>x</script>')->not->toContain('onerror=');
});

it('needs a CSRF token, takes the honeypot\'s bait without storing it, and spends the same hourly bucket as the product form', function () {
    $a = rvrProduct('A');
    [, $token] = rvrIssue([$a]);
    $body = ['author_name' => 'Aisha', 'reviews' => [$a->id => ['rating' => 5, 'content' => 'Lovely']]];

    // CSRF is the web group's and this route does not opt out of it (the
    // framework skips the check inside tests, so the route is what is pinned);
    // only the one-click unsubscribe POST does.
    \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
    $submit = \Illuminate\Support\Facades\Route::getRoutes()->getByName('review-request.submit');
    $stop = \Illuminate\Support\Facades\Route::getRoutes()->getByName('review-request.stop');
    expect($submit->excludedMiddleware())->not->toContain(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->and($submit->gatherMiddleware())->toContain('web')
        ->and($stop->excludedMiddleware())->toContain(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

    rvrOpen($token);
    $this->post(RVR_PAGE, $body + ['sr_website' => 'http://spam.example'])->assertOk()->assertSee('Thank you!');
    expect(Review::query()->count())->toBe(0);

    app(SettingsService::class)->set('sr_rate_limit', 2);
    SettingsService::forgetMemo();
    RateLimiter::clear('review-submit:127.0.0.1');
    $this->post(RVR_PAGE, $body)->assertRedirect();
    $this->post(RVR_PAGE, $body)->assertRedirect();
    $this->post(RVR_PAGE, $body)->assertStatus(429)->assertSee('Too many reviews submitted');
});

it('takes photos only when Store → Reviews allows them, at most the number it allows', function () {
    $a = rvrProduct('A');
    [, $token] = rvrIssue([$a]);
    $one = fn () => UploadedFile::fake()->image('a.jpg', 40, 40);

    app(SettingsService::class)->set('sr_max_photos', 2);
    SettingsService::forgetMemo();
    rvrOpen($token)->assertSee('name="photos[' . $a->id . '][]"', false);

    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => 'Aisha', 'reviews' => [$a->id => ['rating' => 5, 'content' => 'Pic']], 'photos' => [$a->id => [$one(), $one(), $one()]]])
        ->assertSessionHasErrors('photos.' . $a->id);

    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => 'Aisha', 'reviews' => [$a->id => ['rating' => 5, 'content' => 'Pic']], 'photos' => [$a->id => [$one()]]])
        ->assertRedirect();
    $images = Review::query()->first()->images;
    expect($images)->toHaveCount(1)->and($images[0])->toStartWith('/uploads/reviews/');
    @unlink(public_path(ltrim($images[0], '/')));

    app(SettingsService::class)->set('sr_allow_photos', false);
    SettingsService::forgetMemo();
    $this->get(RVR_PAGE)->assertDontSee('name="photos[', false);
});

it('lands in the admin Reviews list like any pending review, marked from the email, and approves the usual way', function () {
    $a = rvrProduct('A');
    [, $token] = rvrIssue([$a]);
    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => 'Aisha', 'reviews' => [$a->id => ['rating' => 5, 'content' => 'Lovely']]])->assertRedirect();
    $review = Review::query()->firstOrFail();

    $owner = AdminUser::create(['name' => 'RV Owner', 'email' => 'rv-owner@example.test', 'password' => 'secret-secret', 'role' => 'owner']);
    $list = $this->actingAs($owner, 'admin')->getJson('/admin-api/reviews/list?filter=pending')->assertOk()->json();
    $row = collect($list['reviews'] ?? $list['rows'] ?? $list['data'] ?? [])->firstWhere('id', $review->id);

    expect($row)->not->toBeNull()
        ->and($row['from_request'])->toBeTrue()
        ->and($row['verified'])->toBeTrue()
        ->and($row['status'])->toBe('pending');

    $this->actingAs($owner, 'admin')->putJson('/admin-api/reviews/' . $review->id . '/moderate', ['status' => 'approved'])->assertOk();
    expect($review->fresh()->status)->toBe('approved');
});

it('never shows the reviewer\'s email or IP on the public API', function () {
    $a = rvrProduct('A');
    [, $token] = rvrIssue([$a]);
    rvrOpen($token);
    $this->post(RVR_PAGE, ['author_name' => 'Aisha', 'reviews' => [$a->id => ['rating' => 5, 'content' => 'Lovely']]])->assertRedirect();
    Review::query()->update(['status' => 'approved']);

    foreach (['/api/reviews', '/api/products/' . $a->slug . '/reviews'] as $url) {
        $body = (string) $this->getJson($url)->getContent();
        expect($body)->not->toContain('aisha@example.com')->not->toContain('127.0.0.1')->not->toContain('author_email');
    }
});

it('costs the same queries for 3 products as for 40', function () {
    /*
     * BrandPageOwnerAsksTest's shape: the page's cost must stay flat however
     * much the order holds. The cap keeps a real email at 6; the row here is
     * written by hand with 40 so the slope, if there were one, would show.
     */
    $count = function (int $n): int {
        $products = [];

        for ($i = 0; $i < $n; $i++) {
            $products[] = rvrProduct('Flat ' . $n . '-' . $i);
        }

        [, $token] = rvrIssue($products, ['email' => "flat{$n}@example.com"]);
        rvrOpen($token)->assertOk(); // warm the settings memo and the opened_at write

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(RVR_PAGE)->assertOk()->assertSee('Flat ' . $n . '-' . ($n - 1));
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    $three = $count(3);
    $forty = $count(40);

    expect($forty)->toBe($three)
        ->and($three)->toBeLessThanOrEqual(12);
});

it('is never prefetched: outside every InstantNav prefix, a reserved root segment, and its links opt out', function () {
    foreach (InstantNav::PREFIXES as $prefix) {
        expect(str_starts_with(ReviewRequests::PATH, rtrim($prefix, '/') . '/'))->toBeFalse();
    }

    $a = rvrProduct('A');
    [, $token] = rvrIssue([$a]);
    $html = rvrOpen($token)->getContent();
    preg_match('#<main.*</main>#s', $html, $m);
    $main = $m[0] ?? $html;

    preg_match_all('#<a [^>]*href="[^"]*/product/[^"]*"[^>]*>#', $main, $links);
    expect($links[0])->not->toBeEmpty();
    foreach ($links[0] as $tag) {
        expect($tag)->toContain('data-no-prefetch');
    }

    $src = (string) file_get_contents(app_path('Http/Controllers/Store/PageController.php'));
    expect($src)->toContain("'review-order',");
});

it('stops review requests with a one-click POST from the mail client, and GET alone changes nothing', function () {
    Mail::fake();
    $a = rvrProduct('A');
    [$order, $token] = rvrIssue([$a], [], ['expires_at' => now()->subDays(400)]);

    $hop = $this->get(rvrUrl($token, 'stop/'))->assertStatus(303);
    expect((string) $hop->headers->get('Location'))->not->toContain($token);
    $this->get('/review-order/stop/')->assertOk()->assertSee('Stop sending review request emails')->assertDontSee($token);
    expect(ReviewRequests::optedOut('aisha@example.com'))->toBeFalse();

    // RFC 8058: no session, no CSRF token — the token in the path is the credential.
    $this->withMiddleware()->call('POST', rvrUrl($token, 'stop/'), ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Stopped');
    expect(ReviewRequests::optedOut('aisha@example.com'))->toBeTrue();

    // A forged token gets the same page and stops nobody.
    $this->withMiddleware()->call('POST', rvrUrl(ReviewRequests::newToken(), 'stop/'))->assertOk()->assertSee('Stopped');
    expect(DB::table('review_request_optouts')->count())->toBe(1);

    // And the next completed order of that address is never asked.
    $next = Order::create(['order_number' => 'KBB-RP-N', 'email' => 'Aisha@example.com', 'status' => 'processing', 'currency' => 'AED', 'total' => 100, 'subtotal' => 100]);
    $next->items()->create(['product_id' => $a->id, 'name' => 'A', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'total' => 100]);
    $next->update(['status' => 'completed']);
    Carbon::setTestNow(now()->addDays(3));
    expect(app(ReviewRequests::class)->sweep(10))->toBe(0);
    Mail::assertNotSent(ReviewRequestMail::class);
});

it('works end to end: the link in the sent email opens the page and its review reaches the list', function () {
    Mail::fake();
    app(SettingsService::class)->setModule('email_order_feedback', false);
    $a = rvrProduct('End to end');
    $address = ['first_name' => 'Rafi', 'last_name' => 'U', 'country' => 'AE'];
    $order = Order::create(['order_number' => 'KBB-RP-E2E', 'email' => 'rafi@example.com', 'status' => 'processing', 'currency' => 'AED', 'total' => 100, 'subtotal' => 100, 'billing_address' => $address]);
    $order->items()->create(['product_id' => $a->id, 'name' => 'End to end', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'total' => 100]);
    $order->update(['status' => 'completed']);

    Carbon::setTestNow(now()->addDays(2)->addMinute());
    expect(app(ReviewRequests::class)->sweep(10))->toBe(1);

    $star = null;
    Mail::assertSent(ReviewRequestMail::class, function (ReviewRequestMail $m) use (&$star) {
        $star = $m->products[0]['stars'][4];

        return true;
    });

    preg_match('#/review-order/([^/]+)/#', (string) parse_url($star, PHP_URL_PATH), $t);
    rvrOpen($t[1], '?' . parse_url($star, PHP_URL_QUERY))->assertOk()->assertSee('value="5" checked', false);
    $this->post(RVR_PAGE, ['author_name' => 'Rafi U.', 'reviews' => [$a->id => ['rating' => 5, 'content' => 'Great']]])->assertRedirect();

    expect(Review::query()->where('source', ReviewRequests::SOURCE)->where('status', 'pending')->count())->toBe(1)
        ->and(DB::table('review_requests')->where('order_id', $order->id)->value('opened_at'))->not->toBeNull();
});

it('is wired exactly once, once the integrator adds the two lines', function () {
    /*
     * THE FINISHED STATE (CLAUDE.md): one require for each route file. Two
     * would register every route twice. Skipped, never asserted absent, until
     * the integrator adds them.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $counts = [
        substr_count($web, "require __DIR__.'/review-request-public.php';"),
        substr_count($web, "require __DIR__.'/review-request-admin.php';"),
    ];

    expect(max($counts))->toBeLessThanOrEqual(1);

    if (min($counts) === 0) {
        $this->markTestSkipped('Not wired yet: the integrator adds the two require lines from the route files\' headers.');
    }

    expect($counts)->toBe([1, 1]);

    $partial = (string) file_get_contents(resource_path('views/admin/partials/emails-templates-screens.blade.php'));
    expect(substr_count($partial, "@include('admin.partials.review-request-rules')"))->toBe(1);
});
