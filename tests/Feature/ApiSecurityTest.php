<?php

/**
 * Regression tests for the public /api surface.
 *
 * Every case here is a bug that actually shipped and was fixed in
 * 2.60.95/.96/.105/.106. They are pinned because the repo has already proved
 * it can lose this work: packages 2.60.102-.106 were built against a stale
 * tree and reverted three files on the live server.
 *
 * These endpoints are unauthenticated. A failure here is a data leak, not a
 * broken page, so each test asserts the absence of a specific field or row
 * rather than the shape of a response.
 */

use App\Models\Post;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;

function product(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'test-serum-'.uniqid(),
        'name' => 'Test Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 25.00,
        'stock_status' => 'instock',
    ], $overrides));
}

it('hides unpublished products from the index', function () {
    product(['name' => 'Live one']);
    product(['name' => 'Draft one', 'status' => 'draft']);
    product(['name' => 'Hidden one', 'is_visible' => false]);

    $body = $this->getJson('/api/products')->assertOk()->json();
    $names = collect($body['data'] ?? $body)->pluck('name');

    expect($names)->toContain('Live one')
        ->not->toContain('Draft one')
        ->not->toContain('Hidden one');
});

it('404s a draft product addressed by slug', function () {
    $draft = product(['slug' => 'secret-launch', 'status' => 'draft']);

    $this->getJson("/api/products/{$draft->slug}")->assertNotFound();
});

it('404s a product hidden from the storefront', function () {
    $hidden = product(['slug' => 'hidden-item', 'is_visible' => false]);

    $this->getJson("/api/products/{$hidden->slug}")->assertNotFound();
});

it('never exposes reviewer email or ip', function () {
    Review::create([
        'product_id' => product()->id,
        'author_name' => 'Someone',
        'author_email' => 'private@example.com',
        'ip' => '203.0.113.9',
        'rating' => 5,
        'content' => 'Lovely',
        'status' => 'approved',
    ]);

    $raw = $this->getJson('/api/reviews')->assertOk()->getContent();

    expect($raw)->not->toContain('private@example.com')
        ->not->toContain('203.0.113.9')
        ->not->toContain('author_email');
});

it('pins the review api to an allowlist, not to an exclusion', function () {
    /*
     * The same standard the settings endpoint is held to just below, applied to
     * the other table CLAUDE.md names: asserted against the CONSTANT and not
     * only against a response, so a column added to `reviews` later is private
     * until somebody publishes it deliberately.
     *
     * It matters more since the WooCommerce import began carrying this table.
     * `reviews` used to be a handful of rows; after a migration it is the whole
     * shop's reviewer base, and author_email and ip are in every one of them.
     */
    $columns = (new ReflectionClass(App\Http\Controllers\Api\ReviewController::class))
        ->getConstant('PUBLIC_COLUMNS');

    expect($columns)->toBeArray()
        ->not->toContain('author_email')
        ->not->toContain('ip')
        ->not->toContain('customer_id')
        ->not->toContain('status')
        ->not->toContain('source')
        ->and($columns)->toContain('author_name')
        ->and($columns)->toContain('rating');

    // And Review::$hidden is the second net, for anything that serialises a
    // whole model rather than going through the controller.
    expect((new Review)->getHidden())->toContain('author_email')->toContain('ip');
});

it('returns only approved reviews, whatever status is asked for', function () {
    $p = product();
    Review::create(['product_id' => $p->id, 'author_name' => 'A', 'rating' => 5, 'content' => 'Approved one', 'status' => 'approved']);
    Review::create(['product_id' => $p->id, 'author_name' => 'B', 'rating' => 1, 'content' => 'Pending spam', 'status' => 'pending']);

    foreach (['/api/reviews', '/api/reviews?status=pending', '/api/reviews?status='] as $url) {
        $raw = $this->getJson($url)->assertOk()->getContent();
        expect($raw)->not->toContain('Pending spam');
    }
});

it('never exposes admin_path or indexnow_key through settings', function () {
    // Setting::map() memoises in a process-level static, so a second HTTP call
    // inside one test process cannot see rows written after the first. The
    // protection being tested is the controller's allowlist, so assert on that
    // directly as well as on a response.
    $keys = (new ReflectionClass(App\Http\Controllers\Api\SettingController::class))
        ->getConstant('PUBLIC_KEYS');

    expect($keys)->toBeArray()
        ->not->toContain('admin_path')
        ->not->toContain('indexnow_key');

    Setting::updateOrCreate(['key' => 'admin_path'], ['value' => 'super-secret-admin']);
    Setting::updateOrCreate(['key' => 'indexnow_key'], ['value' => 'abc123indexnowkey']);

    $raw = $this->getJson('/api/settings')->assertOk()->getContent();

    expect($raw)->not->toContain('super-secret-admin')
        ->not->toContain('abc123indexnowkey')
        ->not->toContain('admin_path')
        ->not->toContain('indexnow_key');
});

it('serves the product api without leaking internal fields', function () {
    product(['slug' => 'visible-serum', 'name' => 'Visible Serum', 'sku' => 'INTERNAL-SKU-1']);

    $raw = $this->getJson('/api/products')->assertOk()->getContent();

    expect($raw)->toContain('Visible Serum')
        ->not->toContain('INTERNAL-SKU-1')
        ->not->toContain('total_sales')
        ->not->toContain('wc_id');

    $this->getJson('/api/products/visible-serum')->assertOk()
        ->assertJsonMissing(['sku' => 'INTERNAL-SKU-1']);
});

it('hides draft posts from the feed and by slug', function () {
    Post::create(['slug' => 'live-post', 'title' => 'Live post', 'status' => 'published']);
    Post::create(['slug' => 'draft-post', 'title' => 'Unreleased draft', 'status' => 'draft']);

    $raw = $this->getJson('/api/posts')->assertOk()->getContent();
    expect($raw)->toContain('Live post')->not->toContain('Unreleased draft');

    $this->getJson('/api/posts/draft-post')->assertNotFound();
});

it('refuses to checkout a product that is not visible', function () {
    $draft = product(['slug' => 'not-for-sale', 'status' => 'draft']);

    $this->postJson('/api/checkout/session', [
        'items' => [['slug' => $draft->slug, 'qty' => 1]],
    ])->assertStatus(422);
});

/*
|------------------------------------------------------------------------------
| Second sweep — the rest of the unauthenticated surface
|------------------------------------------------------------------------------
|
| Same rule as above: every case here was live in this tree, and each one was
| written to fail against the code as it stood before its fix.
|
| The two questions the first sweep did not ask are asked here. Can a public
| endpoint ACT on a record that is not the caller's, and does it answer
| differently for "there is no such record" than for "that one is not yours"?
| The second is the quieter of the two: an endpoint that refuses correctly but
| refuses distinguishably is still a directory of everything in the table.
*/

it('refuses an expert request addressed by a bare lead id', function () {
    // The known gap, exactly as CLAUDE.md recorded it: {id} was the primary
    // key, so counting upwards reached every customer's quiz submission.
    $victim = App\Models\QuizSubmission::create([
        'status' => 'new',
        'name' => 'Victim',
        'email' => 'victim@example.com',
    ]);

    $this->postJson("/api/quiz/{$victim->id}/expert-request", ['message' => 'walked in'])
        ->assertNotFound();

    $row = App\Models\QuizSubmission::find($victim->id);

    expect($row->status)->toBe('new')
        ->and($row->expert_message)->toBeNull()
        ->and((bool) $row->expert_requested)->toBeFalse();
});

it('answers an unissued lead handle and a lead that does not exist identically', function () {
    $victim = App\Models\QuizSubmission::create(['status' => 'new', 'name' => 'Victim']);

    $guessed = $this->postJson("/api/quiz/{$victim->id}/expert-request", ['message' => 'x']);
    $absent  = $this->postJson('/api/quiz/987654/expert-request', ['message' => 'x']);
    $forged  = $this->postJson("/api/quiz/{$victim->id}-00000000000000000000000000000000/expert-request", ['message' => 'x']);

    // Body as well as status: a differing message is the oracle.
    expect($guessed->status())->toBe($absent->status())
        ->and($guessed->getContent())->toBe($absent->getContent())
        ->and($forged->status())->toBe($absent->status())
        ->and($forged->getContent())->toBe($absent->getContent());
});

it('still lets the browser that filed the quiz send its own expert request', function () {
    // The storefront flow, verbatim: skin-quiz.blade.php POSTs /api/quiz, keeps
    // the `id` it gets back and puts it straight into the expert-request URL.
    // If this breaks, the quiz's expert callback stops recording and says
    // nothing about it — the page swallows the error.
    $created = $this->postJson('/api/quiz', [
        'skin_type' => 'dry',
        'name' => 'Real Shopper',
        'email' => 'shopper@example.com',
        'consent' => true,
    ])->assertCreated()->json();

    $this->postJson("/api/quiz/{$created['id']}/expert-request", ['message' => 'please call'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $row = App\Models\QuizSubmission::latest('id')->first();

    expect($row->status)->toBe('expert_requested')
        ->and($row->expert_message)->toBe('please call')
        ->and((bool) $row->expert_requested)->toBeTrue();
});

it('never hands a stored review row back from the public submit endpoint', function () {
    $p = product(['slug' => 'submit-target']);

    $raw = $this->postJson("/api/products/{$p->slug}/reviews", [
        'author' => 'Shopper',
        'rating' => 5,
        'title' => 'Great',
        'body' => 'Really great',
    ])->assertCreated()->getContent();

    // This endpoint died on the INSERT — `author`, `body` and `likes` are not
    // columns on `reviews` — so the whole-model response below it had never
    // once run. Repairing the insert without this would switch the leak on.
    expect($raw)->not->toContain('author_email')
        ->not->toContain('"ip"')
        ->not->toContain('status')
        ->not->toContain('customer_id');

    $review = Review::latest('id')->first();

    expect($review->author_name)->toBe('Shopper')
        ->and($review->content)->toBe('Really great')
        ->and($review->status)->toBe('pending');
});

it('will not vote up a review that is not approved, or admit that it exists', function () {
    $p = product();

    $pending = Review::create([
        'product_id' => $p->id, 'author_name' => 'A', 'rating' => 1,
        'content' => 'held for moderation', 'status' => 'pending', 'helpful' => 3,
    ]);
    $spam = Review::create([
        'product_id' => $p->id, 'author_name' => 'B', 'rating' => 5,
        'content' => 'buy pills', 'status' => 'spam', 'helpful' => 0,
    ]);

    $onPending = $this->postJson("/reviews/{$pending->id}/helpful");
    $onSpam    = $this->postJson("/reviews/{$spam->id}/helpful");
    $onAbsent  = $this->postJson('/reviews/999999/helpful');

    expect($onPending->status())->toBe($onAbsent->status())
        ->and($onPending->getContent())->toBe($onAbsent->getContent())
        ->and($onSpam->getContent())->toBe($onAbsent->getContent())
        ->and($pending->fresh()->helpful)->toBe(3)
        ->and($spam->fresh()->helpful)->toBe(0);
});

it('answers a junk id on either write endpoint without a 500', function () {
    // Neither route constrains its parameter to digits, and both controllers
    // declare strict_types, so a scalar int parameter would turn a typo into a
    // TypeError — a 500 that is both a worse answer than 404 and, with
    // APP_DEBUG on, a stack trace.
    $this->postJson('/reviews/not-a-number/helpful')->assertNotFound();
    $this->postJson('/api/quiz/not-a-number/expert-request', ['message' => 'x'])->assertNotFound();
});

it('caps helpful votes per IP, because the cookie is the caller to discard', function () {
    $review = Review::create([
        'product_id' => product()->id, 'author_name' => 'A', 'rating' => 5,
        'content' => 'Lovely', 'status' => 'approved', 'helpful' => 0,
    ]);

    // The cookie is never sent back, which is exactly what a script would do.
    $statuses = [];

    for ($i = 0; $i < 70; $i++) {
        $statuses[] = $this->postJson("/reviews/{$review->id}/helpful")->status();
    }

    expect($statuses)->toContain(429)
        ->and($review->fresh()->helpful)->toBeLessThanOrEqual(60);
});

it('refuses a storefront review for a product with no storefront page', function () {
    $draft = product(['slug' => 'unlaunched', 'status' => 'draft']);

    $captcha = $this->getJson('/reviews/captcha')->assertOk()->json();
    [$a, , $b] = explode(' ', $captcha['question']);

    $this->postJson('/reviews/submit', [
        'captcha_token' => $captcha['token'],
        'captcha' => (int) $a + (int) $b,
        'product_id' => $draft->id,
        'rating' => 5,
        'author_name' => 'Someone',
        'author_email' => 'someone@example.com',
        'content' => 'Reviewing something that is not for sale yet',
    ])->assertStatus(422);

    expect(Review::where('product_id', $draft->id)->count())->toBe(0);
});

/**
 * /api/cart/debug returned the five most recently active carts SITE-WIDE —
 * their ids, customer_ids and token prefixes — to anyone who opened the URL,
 * while its own doc comment said "nothing sensitive". It was live.
 *
 * The guard lives on the route rather than in the handler so an edit to
 * CartController cannot quietly drop it; this test pins the route.
 */
it('does not serve the cart debug endpoint to the public', function () {
    $response = $this->get('/api/cart/debug');

    expect($response->status())->not->toBe(200);

    // And nothing resembling the leaked payload comes back on the way out.
    expect($response->getContent())->not->toContain('recent_active_carts');
});

it('keeps the cart debug endpoint behind the admin guard', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/cart/debug');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('auth:admin');
});

/**
 * /skin-quiz and /reviews both 500'd: routes/web.php pointed at
 * PageController::skinQuiz and ::reviewWall, neither of which existed. The
 * Blade views were present the whole time, so nothing in the tree looked
 * broken. Found by Lane P while hardening the quiz API — the page that feeds
 * that API could not be reached at all.
 */
it('serves the skin quiz page', function () {
    $this->get('/skin-quiz')->assertOk()->assertSee('quiz', false);
});

it('serves the review wall page', function () {
    $this->get('/reviews')->assertOk();
});

/*
 * The public review door and the storefront door must agree.
 *
 * Lane BG found them disagreeing: Store\ReviewController was wired to
 * `sr_allow_submit` so that "Accept new reviews: off" refuses on the server
 * rather than merely hiding the button, and Api\ProductController was not. The
 * storefront answered 403 and wrote nothing; /api answered 201 and wrote a row.
 *
 * /api/* is unauthenticated, so it is the door a script finds first — which
 * makes it the one where an off switch failing open costs the most.
 */
it('refuses an api review submission when the owner has turned reviews off', function () {
    $p = product(['slug' => 'api-switch-target']);

    app(\App\Services\SettingsService::class)->set('sr_allow_submit', false);
    \App\Models\Setting::flushMap();

    $before = Review::count();

    $this->postJson("/api/products/{$p->slug}/reviews", [
        'author' => 'Walker',
        'rating' => 5,
        'title'  => 'Lovely',
        'body'   => 'Through the side door',
    ])->assertForbidden();

    expect(Review::count())->toBe($before, 'a review was written while submissions were off');
});

it('reads the per-IP review cap from the setting on the api door too', function () {
    /*
     * The two doors share ONE rate-limit key, deliberately, so that five
     * submissions cannot be had from each. A shared key with two different
     * budgets is worse than no sharing at all: the looser number wins, and the
     * owner tightening the cap to one an hour still left five here.
     */
    $p = product(['slug' => 'api-cap-target']);

    app(\App\Services\SettingsService::class)->set('sr_allow_submit', true);
    app(\App\Services\SettingsService::class)->set('sr_rate_limit', 1);
    \App\Models\Setting::flushMap();

    \Illuminate\Support\Facades\RateLimiter::clear('review-submit:127.0.0.1');

    $post = fn () => test()->postJson("/api/products/{$p->slug}/reviews", [
        'author' => 'Walker',
        'rating' => 5,
        'body'   => 'One an hour',
    ]);

    $post()->assertCreated();
    $post()->assertStatus(429);
});

/*
|------------------------------------------------------------------------------
| Outbound mail: nothing it added is reachable without a session (Lane EE)
|------------------------------------------------------------------------------
|
| Extended here rather than pinned in a file of its own, exactly as CLAUDE.md
| asks: this file is where the allowlists are pinned and where each case that
| leaked in production is recorded. Three new stores of personal data arrived
| with the outbound-mail package and each is a leak of a different shape:
|
|   mail_deliveries   every address this shop has ever sent to — a better
|                     customer list than `customers`, because it includes people
|                     who only ever asked for a password reset
|   subscribers       every address that has ever signed up, confirmed or not
|   the links         a confirmation or unsubscribe token is a bearer credential
|
| None of them may be reachable from /api/*, and none of them may be echoed back
| by the endpoints that DO live there.
*/

it('does not serve the mail delivery log to the public', function () {
    \Illuminate\Support\Facades\DB::table('mail_deliveries')->insert([
        'kind' => 'password.reset',
        'recipient' => 'victim@example.com',
        'subject' => 'Reset your password',
        'transport' => 'smtp',
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (['/api/mail/log', '/api/mail', '/api/mail-log', '/api/deliveries'] as $path) {
        $response = $this->getJson($path);

        // 404 or a redirect to a login are both fine. 200 with a body carrying
        // the address is the failure, and the assertion is on the CONTENT
        // rather than only the status, because a route that answered 200 with
        // an empty array today could grow a payload tomorrow.
        expect($response->getContent())->not->toContain('victim@example.com');
        expect($response->status())->not->toBe(200);
    }
});

it('keeps the mail delivery log behind the admin guard', function () {
    /*
     * The route file ships unmounted (CLAUDE.md forbids this lane editing
     * routes/web.php), so this loads it into the real router the way
     * MailRoutesTest does and asserts the middleware it will carry. Asserting
     * "it 404s today" would pass for the wrong reason and go on passing after
     * the integrator mounted it in the wrong group.
     */
    app(\Illuminate\Contracts\Http\Kernel::class);

    $before = \Illuminate\Support\Facades\Route::getRoutes()->getRoutes();

    \Illuminate\Support\Facades\Route::middleware(['auth:admin'])
        ->prefix('admin-api')
        ->group(base_path('routes/mail-admin.php'));

    $added = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r, $before, true));

    $log = $added->first(fn ($r) => $r->uri() === 'admin-api/mail/log');

    expect($log)->not->toBeNull('routes/mail-admin.php no longer registers the log endpoint');
    expect($log->gatherMiddleware())->toContain('auth:admin');
});

it('never returns a subscriber address through the public subscribe endpoint', function () {
    // The endpoint that takes an address must not confirm one back. Its reply
    // is the same sentence for a new address, a pending one and a confirmed
    // one — see Store\SubscribeController::CONFIRM_MESSAGE — and echoing the
    // address would make that sameness pointless.
    \Illuminate\Support\Facades\Mail::fake();

    $response = $this->postJson('/api/subscribe', ['email' => 'private@example.com']);

    expect($response->getContent())->not->toContain('private@example.com');
});

it('never puts a live newsletter token into a public response', function () {
    \Illuminate\Support\Facades\Mail::fake();

    $response = $this->postJson('/api/subscribe', ['email' => 'private@example.com']);

    // The signature is a 64-character hex HMAC. It belongs in the inbox and
    // nowhere else: a response carrying it would let anyone who can POST this
    // endpoint confirm an address they do not control.
    expect($response->getContent())->not->toMatch('#[0-9a-f]{64}#');
    expect($response->getContent())->not->toContain('signature=');
});

it('keeps the mail settings out of the public settings allowlist', function () {
    /*
     * `GET /api/settings` serves Setting::map() through
     * SettingController::PUBLIC_KEYS. The mail package writes several rows into
     * that same table — the SMTP host and username, and `mail_last_test`, which
     * records the address the owner last sent a test to. None is a password
     * (that lives in the encrypted mail_credentials row), and none may be
     * public either.
     */
    $settings = app(\App\Services\SettingsService::class);

    $settings->set('mail_host', 'smtp.hostinger.com');
    $settings->set('mail_username', 'owner@kbeautybliss.com');
    $settings->set('mail_last_test', ['to' => 'owner@kbeautybliss.com', 'ok' => true], false);
    Setting::flushMap();

    $body = $this->getJson('/api/settings')->assertOk()->getContent();

    expect($body)->not->toContain('smtp.hostinger.com');
    expect($body)->not->toContain('owner@kbeautybliss.com');
    expect($body)->not->toContain('mail_last_test');
});

/*
|------------------------------------------------------------------------------
| PAYMENT GATEWAY CREDENTIALS ON THE PUBLIC SURFACE
|------------------------------------------------------------------------------
|
| `payment_providers.config` holds live Stripe, Tabby and Tamara keys. The
| existing cases in PaymentSecretsTest pin the two doors those keys are most
| likely to walk out of — /api/settings and the admin payments screen — by
| naming them.
|
| What follows is the same question asked the other way round: not "is this
| endpoint safe" but "is there ANY public endpoint that is not". The list is
| read out of the router rather than written here, so an endpoint added to
| routes/api.php next month is covered by this test on the day it is added
| rather than on the day somebody remembers to add it to a list.
|
| A secret key that leaks is not a broken page. It is somebody else charging
| this shop's customers.
*/

/** Distinctive enough that a substring match cannot be a coincidence. */
const GATEWAY_CANARIES = [
    'sk_live_CANARY_stripe_secret_key_value',
    'whsec_CANARY_stripe_signing_secret',
    'sk_CANARY_tabby_secret_key_value',
    'CANARY_tamara_api_token_value',
    'CANARY_tamara_notification_token',
    'whsec-CANARY-url-secret-0123456789',
];

function seedGatewaySecrets(): void
{
    \App\Models\PaymentProvider::query()->delete();

    $configs = [
        'stripe' => [
            'publishable_key' => 'pk_live_safe_to_show',
            'secret_key' => GATEWAY_CANARIES[0],
            'webhook_signing_secret' => GATEWAY_CANARIES[1],
            'webhook_secret' => GATEWAY_CANARIES[5],
        ],
        'tabby' => [
            'public_key' => 'pk_test_safe',
            'secret_key' => GATEWAY_CANARIES[2],
            'merchant_code' => 'AE',
            'webhook_secret' => GATEWAY_CANARIES[5],
        ],
        'tamara' => [
            'api_token' => GATEWAY_CANARIES[3],
            'notification_token' => GATEWAY_CANARIES[4],
            'webhook_secret' => GATEWAY_CANARIES[5],
        ],
    ];

    foreach ($configs as $id => $config) {
        $row = \App\Models\PaymentProvider::create([
            'id' => $id, 'enabled' => true, 'mode' => 'live', 'position' => 1,
        ]);

        $row->config = $config;
        $row->save();
    }

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

/**
 * Every GET route the public can reach, read from the router.
 *
 * `auth:` in the middleware list is what makes a route non-public, so those
 * are dropped. Routes with required parameters are dropped too -- there is
 * nothing sensible to substitute for {slug} here, and the endpoints that
 * matter for this question are the collection ones.
 *
 * @return array<int, string>
 */
function publicGetUris(): array
{
    $uris = [];

    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/') || str_contains($uri, '{')) {
            continue;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'auth:')) {
                continue 2;
            }
        }

        $uris[] = '/' . $uri;
    }

    return array_values(array_unique($uris));
}

it('leaks no gateway secret through any public api endpoint, whatever the list of them is', function () {
    seedGatewaySecrets();

    $uris = publicGetUris();

    // A sweep that swept nothing would pass silently, which is the failure
    // mode of every route-driven test.
    expect($uris)->not->toBeEmpty()
        ->and($uris)->toContain('/api/settings');

    foreach ($uris as $uri) {
        $raw = test()->get($uri)->getContent();

        foreach (GATEWAY_CANARIES as $canary) {
            /*
             * ONE NEEDLE PER CALL. toContain() is variadic, so `$uri` here was
             * a second NEEDLE rather than a failure message, and Pest's `not`
             * passes the moment the positive expectation fails for any reason
             * — including "the body does not contain the path". Both gateway
             * sweeps were measured passing over a response with the canary in
             * it. Found by Lane FJ while writing the quiz-lead sweep below the
             * same way.
             */
            expect(str_contains($raw, $canary))->toBeFalse("{$uri} returned {$canary}");
        }
    }
});

it('keeps every gateway config key out of the public settings allowlist', function () {
    seedGatewaySecrets();

    // Asked of the DOOR rather than of the constant behind it. The allowlist is
    // private, and a test that reached into it would still pass if the endpoint
    // stopped consulting it.
    $served = array_keys(test()->getJson('/api/settings')->assertOk()->json());

    expect($served)->not->toBeEmpty();

    // Not "these particular names are absent" but "no key any gateway declares
    // is present", so a gateway adding a field cannot quietly widen this.
    foreach (app(\App\Services\Payments\GatewayRegistry::class)->all() as $gateway) {
        foreach (array_keys($gateway->configSchema()) as $key) {
            expect($served)->not->toContain($key, $gateway->id() . '.' . $key);
        }
    }
});

it('writes no gateway credential into the settings table, where nothing encrypts it', function () {
    seedGatewaySecrets();

    // `settings` is plain text in the database and in every backup of it. A
    // secret that is not there cannot leak from there however /api/settings is
    // rewritten later.
    $all = Setting::query()->pluck('value')->implode(' ');

    foreach (GATEWAY_CANARIES as $canary) {
        expect($all)->not->toContain($canary);
    }
});

it('never writes a gateway secret into the log, even when the provider fails', function () {
    seedGatewaySecrets();

    $order = \App\Models\Order::create([
        'order_number' => 'LOG-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'pending',
        'currency' => 'AED',
        'subtotal' => 30000,
        'total' => 30000,
    ]);

    $lines = [];

    \Illuminate\Support\Facades\Log::listen(function ($message) use (&$lines) {
        $lines[] = $message->message . ' ' . json_encode($message->context);
    });

    // Two shapes of failure, because they are logged by different branches:
    // a transport exception, and a refusal with a body.
    \Illuminate\Support\Facades\Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('cURL error 7');
    });

    app(\App\Services\Payments\GatewayRegistry::class)->find('stripe')->start($order);

    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(
        ['error' => ['code' => 'api_key_invalid', 'message' => 'Invalid API Key: ' . GATEWAY_CANARIES[0]]],
        401,
    )]);

    app(\App\Services\Payments\GatewayRegistry::class)->find('tabby')->start($order);

    expect($lines)->not->toBeEmpty();

    $logged = implode("\n", $lines);

    foreach (GATEWAY_CANARIES as $canary) {
        expect($logged)->not->toContain($canary);
    }
});

it('never puts a gateway secret in anything it hands back to a caller', function () {
    seedGatewaySecrets();

    $order = \App\Models\Order::create([
        'order_number' => 'MSG-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 30000,
        'total' => 30000,
        'paid_at' => now(),
        'payment_method' => 'stripe',
        'transaction_id' => 'pi_x',
    ]);

    // The provider echoes the key back inside its own error, which is a thing
    // Stripe genuinely does on a bad key. Nothing we return may carry it: not
    // the sentence shown to the shopper, not the settlement message shown to
    // the admin, not the audit payload that lands in `payment_events`.
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(
        ['error' => ['code' => 'api_key_invalid', 'message' => 'Invalid API Key: ' . GATEWAY_CANARIES[0]]],
        401,
    )]);

    $start = app(\App\Services\Payments\GatewayRegistry::class)->find('stripe')->start($order);
    $capture = app(\App\Services\Payments\PaymentCapturer::class)->capture($order->fresh(), 'Admin');

    $surface = json_encode([
        $start->message,
        $start->redirectUrl,
        $capture->message,
        $capture->code,
        $capture->summary,
        \App\Models\PaymentEvent::query()->get()->toArray(),
        $order->fresh()->notes->pluck('content')->all(),
    ]);

    foreach (GATEWAY_CANARIES as $canary) {
        expect($surface)->not->toContain($canary);
    }
});

/*
|------------------------------------------------------------------------------
| THE SAME QUESTION, ASKED OF AN AUTOMATICALLY CONNECTED SHOP
|------------------------------------------------------------------------------
|
| Store -> Payments -> Stripe -> Connect (App\Services\Payments\StripeConnect)
| writes more keys into `payment_providers.config` than the hand-filled form
| ever did: the account id, the account's name and country, the id of the
| webhook endpoint it created, and -- the two that matter -- a secret key and a
| signing secret obtained from Stripe rather than from a paste box.
|
| The sweep above reads its canaries out of a config shaped like the OLD form,
| so on its own it would keep passing while a connect-shaped shop leaked. This
| asks the identical question of the new shape.
*/

/** Distinctive enough that a substring match cannot be a coincidence. */
const CONNECTED_CANARIES = [
    'sk_live_CANARY_autoconnected_secret',
    'whsec_CANARY_autoconnected_signing',
    'whsec-CANARY-autoconnect-url-00001',
];

function seedConnectedStripe(): void
{
    \App\Models\PaymentProvider::query()->delete();

    $row = \App\Models\PaymentProvider::create([
        'id' => 'stripe', 'enabled' => true, 'mode' => 'live', 'position' => 1,
    ]);

    // Exactly the shape StripeConnect::store() writes, new keys included.
    $row->config = [
        'secret_key' => CONNECTED_CANARIES[0],
        'publishable_key' => 'pk_live_safe_to_show',
        'webhook_signing_secret' => CONNECTED_CANARIES[1],
        'webhook_secret' => CONNECTED_CANARIES[2],
        'connect_account_id' => 'acct_CANARY1',
        'connect_link' => 'key',
        'connect_client_id' => 'ca_CANARY1',
        'connected_at' => '2026-09-17T09:00:00+00:00',
        'account_name' => 'K Beauty Bliss',
        'account_country' => 'AE',
        'account_currency' => 'AED',
        'charges_enabled' => '1',
        'livemode' => '1',
        'webhook_endpoint_id' => 'we_CANARY1',
        'webhook_endpoint_managed' => '1',
    ];

    $row->save();

    app(\App\Services\Payments\GatewayCredentials::class)->forget();
}

it('leaks nothing from an automatically connected Stripe through any public api endpoint', function () {
    seedConnectedStripe();

    $uris = publicGetUris();

    // A sweep that swept nothing would pass silently.
    expect($uris)->not->toBeEmpty()->and($uris)->toContain('/api/settings');

    foreach ($uris as $uri) {
        $raw = test()->get($uri)->getContent();

        foreach (CONNECTED_CANARIES as $canary) {
            /*
             * ONE NEEDLE PER CALL. toContain() is variadic, so `$uri` here was
             * a second NEEDLE rather than a failure message, and Pest's `not`
             * passes the moment the positive expectation fails for any reason
             * — including "the body does not contain the path". Both gateway
             * sweeps were measured passing over a response with the canary in
             * it. Found by Lane FJ while writing the quiz-lead sweep below the
             * same way.
             */
            expect(str_contains($raw, $canary))->toBeFalse("{$uri} returned {$canary}");
        }
    }
});

it('encrypts what the connect flow stores, the same as what the form stored', function () {
    seedConnectedStripe();

    // Straight at the column, past the model's cast. `config` is the only place
    // any of this lives and it is an `encrypted:array`, so a database backup in
    // the wrong hands carries no usable key.
    $stored = \Illuminate\Support\Facades\DB::table('payment_providers')
        ->where('id', 'stripe')->value('config');

    expect($stored)->toBeString();

    foreach (CONNECTED_CANARIES as $canary) {
        expect($stored)->not->toContain($canary);
    }
});

it('writes nothing the connect flow stores into the settings table', function () {
    seedConnectedStripe();

    $all = Setting::query()->pluck('value')->implode(' ');

    foreach (CONNECTED_CANARIES as $canary) {
        expect($all)->not->toContain($canary);
    }
});

/*
|------------------------------------------------------------------------------
| THE SKIN QUIZ'S LEADS ARE NOW A CONTACT LIST (Lane FJ)
|------------------------------------------------------------------------------
|
| Until this lane, POST /api/quiz validated flat snake_case while
| resources/views/store/skin-quiz.blade.php posted camelCase under nested
| objects, so `name`, `phone` and `email` were written NULL on every single
| lead. That was a bug about thrown-away leads; closing it is a change to this
| file's subject matter, because `quiz_submissions` starts holding a shopper's
| full name, WhatsApp number and email address in a table reachable from two
| endpoints with no authentication in front of either.
|
| CLAUDE.md names `reviews.author_email` and `reviews.ip` as having leaked in
| production. This is the same shape of column arriving in a different table,
| so the same rule is pinned for it here: nothing on the public surface may
| hand a lead's contact back, including the endpoints that write it.
|
| Every case below fails against the controller as it stood before this lane —
| the capture cases because the columns were NULL, the echo cases because there
| was nothing to echo.
*/

/** One lead, captured exactly the way the storefront captures one. */
function quizLeadMarkers(): array
{
    return [
        'name' => 'Noura Al Marker',
        'phone' => '+971 50 909 0909',
        'email' => 'noura.marker@example.test',
    ];
}

function captureQuizLead(\Tests\TestCase $test): string
{
    // Field for field what buildPayload() + ensureLead() post. If this drifts
    // from the page the rest of this section stops testing the real thing.
    $markers = quizLeadMarkers();

    return $test->postJson('/api/quiz', [
        'submittedAt' => '2026-09-17T10:00:00.000Z',
        'skinType' => 'Oily',
        'concerns' => ['Acne & breakouts', 'Dark spots'],
        'answers' => [
            'age' => '25-34',
            'routineDepth' => 'Balanced (4-5 steps)',
            'budget' => 'AED 200-400',
            'allergies' => ['Fragrance'],
            'allergyNote' => 'pregnant',
        ],
        'contact' => $markers,
        'recommendedRoutines' => [['name' => 'Balanced glow', 'steps' => ['Cleanse', 'Tone']]],
        'expertRequest' => ['requested' => false],
        'status' => 'new',
        'consent' => true,
        'source_url' => '/skin-quiz',
    ])->assertCreated()->json('id');
}

it('keeps the contact the quiz page actually posts', function () {
    captureQuizLead($this);

    $row = App\Models\QuizSubmission::latest('id')->first();

    expect([
        'skin_type' => $row->skin_type,
        'age' => $row->age,
        'routine_depth' => $row->routine_depth,
        'budget' => $row->budget,
        'name' => $row->name,
        'phone' => $row->phone,
        'email' => $row->email,
    ])->toBe([
        'skin_type' => 'Oily',
        'age' => '25-34',
        'routine_depth' => 'Balanced (4-5 steps)',
        'budget' => 'AED 200-400',
        'name' => 'Noura Al Marker',
        'phone' => '+971 50 909 0909',
        'email' => 'noura.marker@example.test',
    ]);

    expect($row->concerns)->toBe('Acne & breakouts,Dark spots')
        ->and((int) $row->consent)->toBe(1)
        ->and($row->consent_at)->not->toBeNull();
});

it('still accepts the flat spelling the endpoint has always answered to', function () {
    // The nested shape is canonical because the page is the published
    // contract, but the flat one has been accepted for the endpoint's whole
    // life and nothing may be broken by choosing between them.
    $this->postJson('/api/quiz', [
        'skin_type' => 'Dry',
        'concerns' => ['Hydration'],
        'age' => '35-44',
        'routine_depth' => 'Minimal',
        'budget' => 'AED 100-200',
        'name' => 'Flat Caller',
        'phone' => '+971500000001',
        'email' => 'flat@example.test',
        'consent' => true,
    ])->assertCreated();

    $row = App\Models\QuizSubmission::latest('id')->first();

    expect($row->name)->toBe('Flat Caller')
        ->and($row->phone)->toBe('+971500000001')
        ->and($row->email)->toBe('flat@example.test')
        ->and($row->skin_type)->toBe('Dry')
        ->and($row->age)->toBe('35-44')
        ->and($row->routine_depth)->toBe('Minimal')
        ->and($row->budget)->toBe('AED 100-200');
});

it('keeps taking a concern list as a plain comma-separated string', function () {
    /*
     * `concerns` was validated as a bare 'nullable' — no type, no length, no
     * content rule — on a public endpoint, into a column the owner's leads
     * screen renders. It is `array|max:20` with each entry `string|max:80` now,
     * and this is the caller that rule must not break: the endpoint answered
     * 201 to a comma-separated string for its whole life, and the column is a
     * comma-joined string either way, so narrowing it to arrays would refuse a
     * caller for nothing.
     */
    $this->postJson('/api/quiz', [
        'contact' => ['email' => 'string-concerns@example.test'],
        'concerns' => 'Hydration, Pores & texture',
    ])->assertCreated();

    expect(App\Models\QuizSubmission::latest('id')->first()->concerns)
        ->toBe('Hydration,Pores & texture');
});

it('refuses a concern list used as free storage', function () {
    // The other side of the same rule: 20 entries of 80 characters, and no more.
    $this->postJson('/api/quiz', [
        'contact' => ['email' => 'bulk@example.test'],
        'concerns' => array_fill(0, 40, 'x'),
    ])->assertStatus(422);

    $this->postJson('/api/quiz', [
        'contact' => ['email' => 'long@example.test'],
        'concerns' => [str_repeat('x', 500)],
    ])->assertStatus(422);

    expect(App\Models\QuizSubmission::count())->toBe(0);
});

it('stores nothing the contact form did not ask permission to keep', function () {
    /*
     * THE LINE THIS LANE STOPPED AT, pinned so a later one has to argue with
     * it rather than drift past it.
     *
     * The allergy step says "So we steer clear of ingredients that don't agree
     * with you" — a purpose served while the quiz is on screen — over a free
     * text box whose own placeholder invites "allergies, pregnancy, current
     * products". That is health data, the form does not say it is kept, and
     * there is no column for it. recommend() reads it in the browser and it
     * goes no further.
     *
     * `status` and `expertRequest` are the other half: both are in the posted
     * body and neither may be honoured from it, because this endpoint is
     * public. A caller must not be able to file a lead pre-marked 'converted',
     * nor set expert_requested without the signed handle that
     * /api/quiz/{token}/expert-request demands.
     */
    $this->postJson('/api/quiz', [
        'contact' => ['name' => 'Boundary', 'email' => 'boundary@example.test'],
        'answers' => ['allergies' => ['Fragrance'], 'allergyNote' => 'pregnant, on tretinoin'],
        'status' => 'converted',
        'expertRequest' => ['requested' => true, 'message' => 'granted myself a callback'],
        'consent' => true,
    ])->assertCreated();

    $row = App\Models\QuizSubmission::latest('id')->first();
    $stored = implode(' ', array_map('strval', $row->getAttributes()));

    expect($stored)->not->toContain('pregnant')
        ->not->toContain('tretinoin')
        ->not->toContain('Fragrance');

    expect($row->status)->toBe('new')
        ->and((bool) $row->expert_requested)->toBeFalse()
        ->and($row->expert_message)->toBeNull();
});

it('lets a routine name through and leaves an invented product behind', function () {
    /*
     * `recommended_routines` is written now, so what may go in it is decided
     * rather than inherited from the body. A routine contributes a name and a
     * list of step names; everything else on the object is dropped. The page
     * once posted seventeen products the shop does not sell and a bundle total
     * nobody set, and the column must not be able to carry one again.
     */
    $this->postJson('/api/quiz', [
        'contact' => ['email' => 'routines@example.test'],
        'recommendedRoutines' => [[
            'name' => 'Balanced glow',
            'steps' => ['Cleanse', 'Tone'],
            'products' => [['name' => 'Invented Serum', 'brand' => 'Numbuzin']],
            'bundle_aed' => 411,
            'price' => 129,
        ]],
        'products' => [['name' => 'Invented Serum']],
        'bundle_aed' => 411,
    ])->assertCreated();

    $row = App\Models\QuizSubmission::latest('id')->first();

    expect($row->recommended_routines)
        ->toBe([['name' => 'Balanced glow', 'steps' => ['Cleanse', 'Tone']]]);

    expect(implode(' ', array_map('strval', $row->getAttributes())))
        ->not->toContain('Invented Serum')
        ->not->toContain('Numbuzin')
        ->not->toContain('411')
        ->not->toContain('129');
});

it('never echoes the lead it just captured back to the caller', function () {
    $body = $this->postJson('/api/quiz', [
        'contact' => quizLeadMarkers(),
        'consent' => true,
    ])->assertCreated()->getContent();

    foreach (quizLeadMarkers() as $marker) {
        expect($body)->not->toContain($marker);
    }

    // And neither does the endpoint that writes to the same row afterwards.
    $token = captureQuizLead($this);
    $reply = $this->postJson("/api/quiz/{$token}/expert-request", ['message' => 'please call'])
        ->assertOk()->getContent();

    foreach (quizLeadMarkers() as $marker) {
        expect($reply)->not->toContain($marker);
    }
});

it('does not name the lead in a validation failure either', function () {
    // A 422 is the other way a write endpoint talks back. Laravel echoes the
    // FIELD in its message; it must not echo the value, or a rejected body is
    // a mirror for anything posted through it.
    $body = $this->postJson('/api/quiz', [
        'contact' => ['name' => 'Noura Al Marker', 'phone' => '+971 50 909 0909', 'email' => 'not-an-email'],
    ])->assertStatus(422)->getContent();

    expect($body)->not->toContain('Noura Al Marker')
        ->not->toContain('+971 50 909 0909');
});

it('hands no quiz lead back from anything on the public api', function () {
    /*
     * The sweep, rather than a list of paths somebody remembered to write
     * down: every GET registered under /api is requested with a lead in the
     * table, and none of the bodies may carry it. A new public endpoint that
     * joins its way to `quiz_submissions` fails here on the day it is added.
     */
    captureQuizLead($this);

    $gets = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/') && in_array('GET', $r->methods(), true))
        ->reject(fn ($r) => str_contains($r->uri(), '{'))
        ->map(fn ($r) => '/' . $r->uri())
        ->unique()
        ->values();

    expect($gets)->not->toBeEmpty('the /api GET surface could not be enumerated');

    $paths = $gets
        // And the obvious guesses at a collection endpoint for the table,
        // which the enumeration cannot cover because they are not registered.
        ->merge(['/api/quiz', '/api/quiz-leads', '/api/leads', '/api/submissions']);

    foreach ($paths as $uri) {
        $raw = $this->getJson($uri)->getContent();

        foreach (quizLeadMarkers() as $field => $marker) {
            /*
             * ONE NEEDLE PER CALL, and not a style preference.
             *
             * expect($raw)->not->toContain($marker, $uri) reads like a needle
             * and a failure message and is neither: toContain() is variadic,
             * so the second argument is a SECOND NEEDLE, and Pest's `not`
             * passes as soon as the positive expectation fails for any reason
             * — including "the body does not contain the path I passed as a
             * message". Written that way this whole sweep passes over a
             * response with the lead's name in it, measured. The message goes
             * in expect()'s own $message argument instead.
             */
            expect($raw)->not->toContain($marker);
            expect(str_contains($raw, $marker))->toBeFalse("{$uri} returned the lead's {$field}");
        }
    }
});

it('will not let a handle issued for one lead act on another', function () {
    /*
     * The other half of the handle, and the half the existing cases do not
     * reach: they prove a FORGED signature fails and that failing looks like
     * "no such lead". This proves a GENUINE signature is bound to the id it
     * was issued for, which is what stops a shopper who filed their own quiz
     * from walking the table with a valid-looking token.
     */
    $mine = App\Models\QuizSubmission::create(['status' => 'new', 'name' => 'Mine']);
    $theirs = App\Models\QuizSubmission::create(['status' => 'new', 'name' => 'Theirs']);

    [, $mySignature] = explode('-', $mine->publicToken(), 2);

    $swapped = $this->postJson("/api/quiz/{$theirs->id}-{$mySignature}/expert-request", ['message' => 'x']);
    $absent = $this->postJson('/api/quiz/987654/expert-request', ['message' => 'x']);

    expect($swapped->status())->toBe($absent->status())
        ->and($swapped->getContent())->toBe($absent->getContent());

    expect((bool) $theirs->fresh()->expert_requested)->toBeFalse()
        ->and((bool) $mine->fresh()->expert_requested)->toBeFalse();
});
