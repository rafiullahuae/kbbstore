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
            expect($raw)->not->toContain($canary, $uri);
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
