<?php

declare(strict_types=1);

/*
 * The email a shopper types at checkout, recorded before the order. (Lane CAP)
 *
 * The owner, 15 October 2026, on Cart Tracking's cart #544 reading "Guest, no
 * email" with the stage chip "Entered email": "i need a function, when user
 * enter their email, i want to record that email in our system, via email the
 * customer account should be created as normal, even user don't create account
 * or place the order. do it super nicely without breaking anything."
 * And: "ok, only the left cart email should go auto. nothing else."
 */

use App\Mail\CartRecoveryReminder;
use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartRecovery;
use App\Services\CartService;
use App\Services\CartTracking\CartTrackingSettings;
use App\Services\Marketing\Audience;
use App\Services\OutboundTick;
use App\Services\SettingsService;
use App\Support\CheckoutEmailCapture;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CartTrackingRoutes;
use Tests\Support\CustomersAdminRoutes;
use Tests\Support\OutboundRoutes;

const CAP_UA = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
const CAP_MIGRATION = '2027_11_05_100000_add_checkout_email_capture.php';

beforeEach(function () {
    Route::middleware('web')->group(base_path('routes/cart-trail.php'));
    Mail::fake();
    Cache::flush();

    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 0);
    capForget();
});

/* ─────────────────────────────── fixtures ─────────────────────────────── */

function capForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function capSet(string $key, bool $on): void
{
    app(CartTrackingSettings::class)->save([$key => $on]);
    capForget();
}

/** The Abandoned Cart module on, worded, first reminder after 15 minutes. */
function capArmReminder(string $schedule = '0.25'): void
{
    $s = app(SettingsService::class);
    $s->setModule('abandoned_cart', true);
    $s->set(CartRecovery::KEY_OPTIN_LABEL, 'Email me a reminder about this basket.');
    $s->set(CartRecovery::KEY_SUBJECT, 'Your basket');
    $s->set(CartRecovery::KEY_BODY, 'You left something behind.');
    $s->set(CartRecovery::KEY_SCHEDULE, $schedule);
    capForget();
}

function capCart(int $lines = 1): Cart
{
    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);

    for ($i = 0; $i < $lines; $i++) {
        $p = Product::create(['slug' => 'cap-'.Str::random(10), 'name' => 'Glow Serum '.$i, 'status' => 'publish',
            'is_visible' => true, 'price' => 9700, 'stock_status' => 'instock']);
        $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 9700]);
    }

    DB::table('carts')->where('id', $cart->id)->update(['ct_first_at' => now(), 'ct_last_at' => now(),
        'ct_value' => 9700 * $lines, 'ct_ip' => '94.200.1.9', 'ct_country' => 'AE', 'ct_ua' => CAP_UA]);

    return $cart->fresh();
}

/** A shopper's browser: the basket cookie (or none), a real phone. */
function capAs(?Cart $cart, string $ua = CAP_UA)
{
    app()->forgetScopedInstances();
    app(CartService::class)->forget();
    app('auth')->forgetGuards();
    capForget();
    $t = test()->flushHeaders()->withHeaders(['User-Agent' => $ua])->withServerVariables(['REMOTE_ADDR' => '94.200.1.9'])
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);

    // No cart: a basket cookie that names nothing (the test client keeps the last one otherwise).
    return $t->withUnencryptedCookie(CartService::COOKIE, $cart ? $cart->token : (string) Str::uuid());
}

function capPost(?Cart $cart, mixed $email, array $extra = [], string $ua = CAP_UA)
{
    return capAs($cart, $ua)->post('/api/checkout-contact', ['email' => $email] + $extra);
}

function capCustomers(string $email): \Illuminate\Support\Collection
{
    return DB::table('customers')->whereRaw('LOWER(email) = ?', [strtolower($email)])->get();
}

function capPlace(Cart $cart, string $email)
{
    app()->forgetScopedInstances();
    app(CartService::class)->forget();
    capForget();

    return test()->flushHeaders()->withHeaders(['User-Agent' => CAP_UA])
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => $email, 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Jovana', 'billing_last_name' => 'Bisevac',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
            'billing_country' => 'AE', 'payment_method' => 'cod',
        ]);
}

function capCheckout(Cart $cart): string
{
    test()->flushSession();
    app()->forgetScopedInstances();
    app('auth')->forgetGuards();
    app(CartService::class)->forget();
    capForget();

    return (string) test()->flushHeaders()->withHeaders(['User-Agent' => CAP_UA])
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/checkout')->assertOk()->getContent();
}

function capTick(): void
{
    Cache::flush();
    capForget();
    app(OutboundTick::class)->run();
}

function capBackdate(Cart $cart, int $hours = 2): void
{
    DB::table('cart_recoveries')->where('cart_id', $cart->id)->update(['consented_at' => now()->subHours($hours)]);
}

function capNode(string $js): string
{
    $node = new Process(['node', '--input-type=module', '-e', $js], base_path());
    $node->run();

    if (! $node->isSuccessful() && (str_contains($node->getErrorOutput(), 'not found') || $node->getExitCode() === 127)) {
        test()->markTestSkipped('node is not installed here');
    }

    expect($node->isSuccessful())->toBeTrue($node->getErrorOutput());

    return $node->getOutput();
}

/** email-capture.js in node, with a field, a fetch that counts, and events fired by hand. */
function capJs(string $steps): array
{
    $mod = json_encode('file://'.resource_path('js/kbb/email-capture.js'));
    $out = capNode(<<<JS
        const sent = [];
        const field = new EventTarget();
        field.value = '';
        globalThis.document = { cookie: '', activeElement: null, getElementById: (id) => id === 'billing_email' ? field : null };
        globalThis.window = { KBB: { csrf: 'tok' } };
        globalThis.fetch = (url, o) => { sent.push([url, o.body.get('email'), o.body.get('_token'), o.keepalive]); return Promise.resolve({}); };
        const { startEmailCapture } = await import($mod);
        startEmailCapture('/api/checkout-contact');
        const type = (s) => { document.activeElement = field; for (const ch of s) { field.value += ch; field.dispatchEvent(new Event('input')); } };
        const leave = () => { field.dispatchEvent(new Event('change')); document.activeElement = null; field.dispatchEvent(new Event('blur')); };
        const tapFix = (v) => { field.value = v; field.dispatchEvent(new Event('input')); field.dispatchEvent(new Event('change')); };
        $steps
        console.log(JSON.stringify(sent));
        JS);

    return json_decode(trim($out), true);
}

/* ═════════════════════════════ 1. THE BROWSER ═════════════════════════════ */

it('sends nothing while typing, and the address once when the field is left', function () {
    /*
     * DEFECT this guards: the owner's standing rule for this field, "during
     * write no any disturbance" -- a request per keystroke, or a resend on
     * every blur. MUTATION: listen on `input` -> red on the first count;
     * drop the `sent` check -> the second leave is a second request.
     */
    $sent = capJs(<<<'JS'
        type('jovana8b@yahoo.com');
        const whileTyping = sent.length;
        if (whileTyping !== 0) throw new Error('sent while typing: ' + whileTyping);
        leave();
        type(''); leave();
        JS);

    expect($sent)->toBe([['/api/checkout-contact', 'jovana8b@yahoo.com', 'tok', true]]);
});

it('sends nothing for an address that is not one', function () {
    // MUTATION: drop looksLikeEmail() from send() -> three requests.
    $sent = capJs(<<<'JS'
        type('jovana8b'); leave();
        field.value = ''; type('jovana8b@yahoo'); leave();
        field.value = ''; type('a b@yahoo.com'); leave();
        JS);

    expect($sent)->toBe([]);
});

it('sends the corrected address when the "Did you mean" fix is tapped', function () {
    /*
     * DEFECT: the yahoo.co case. Leaving the field records the typo; Lane EH's
     * button then writes yahoo.com and fires `change` -- with no blur, because
     * the field is no longer focused. MUTATION: drop the `change` listener ->
     * only the typo is ever sent.
     */
    $sent = capJs(<<<'JS'
        type('jovana8b@yahoo.co'); leave();
        tapFix('jovana8b@yahoo.com');
        JS);

    expect(array_column($sent, 1))->toBe(['jovana8b@yahoo.co', 'jovana8b@yahoo.com']);

    // ... and that IS what the button does (resources/js/kbb/email-fix.js).
    expect((string) file_get_contents(resource_path('js/kbb/email-fix.js')))
        ->toContain("field.dispatchEvent(new Event('change', { bubbles: true }));");
});

it('loads only from the checkout bundle, only when the page publishes the endpoint, and measures nothing', function () {
    $co = (string) file_get_contents(resource_path('js/kbb/checkout.js'));
    $js = (string) file_get_contents(resource_path('js/kbb/email-capture.js'));

    expect(substr_count($co, "import('./email-capture.js')"))->toBe(1)
        ->and($co)->toContain('const captureUrl = window.KBB?.routes?.checkoutEmail;')
        ->and($js)->not->toMatch('/getBoundingClientRect|offsetWidth|offsetHeight|getComputedStyle|setInterval|setTimeout|addEventListener\(\'input\'/')
        ->and($js)->toContain('hasAdminHint(document.cookie)');
});

/* ═════════════════════════════ 2. THE RECORD ══════════════════════════════ */

it('files a guest customer for the typed address and puts it on the cart', function () {
    /*
     * DEFECT: Cart Tracking's "Guest, no email" on a cart whose shopper had
     * typed their address. MUTATION: drop the carts update in
     * CheckoutEmailCapture::run() -> ct_email null; drop the origin update ->
     * origin null.
     */
    $cart = capCart();

    capPost($cart, '  Jovana8B@Yahoo.COM ')->assertNoContent();

    $rows = capCustomers('jovana8b@yahoo.com');
    $c = $rows->first();
    $fresh = DB::table('carts')->where('id', $cart->id)->first();

    expect($rows)->toHaveCount(1)
        ->and($c->email)->toBe('jovana8b@yahoo.com')
        ->and($c->origin)->toBe('checkout_email')
        ->and($c->password)->toBeNull()
        ->and($c->legacy_password)->toBeNull()
        ->and($c->email_verified_at)->toBeNull()
        ->and((bool) $c->whatsapp_optin)->toBeFalse()
        ->and($fresh->ct_email)->toBe('jovana8b@yahoo.com')
        ->and((int) $fresh->ct_email_customer_id)->toBe((int) $c->id)
        ->and((int) $fresh->ct_email_n)->toBe(1)
        // NOT carts.customer_id: that decides whose basket a signed-in account sees.
        ->and($fresh->customer_id)->toBeNull();

    // Sending the same address again is not a second anything.
    capPost($cart, 'jovana8b@yahoo.com')->assertNoContent();
    expect((int) DB::table('carts')->where('id', $cart->id)->value('ct_email_n'))->toBe(1);
});

it('files the later order under the same customer row, with its name and phone', function () {
    /*
     * DEFECT: a second customers row for the same address, the owner's
     * duplicate-account complaint. MUTATION: give the capture its own
     * Customer::create() -> the unique index or a second row; drop the
     * fill-the-blanks branch in customerForGuestOrder -> no name on the row.
     */
    $cart = capCart();
    capPost($cart, 'jovana8b@yahoo.com')->assertNoContent();
    $id = (int) capCustomers('jovana8b@yahoo.com')->first()->id;

    capPlace($cart, 'jovana8b@yahoo.com')->assertRedirect();

    $order = DB::table('orders')->latest('id')->first();
    $c = capCustomers('jovana8b@yahoo.com');

    expect($c)->toHaveCount(1)
        ->and((int) $order->customer_id)->toBe($id)
        ->and($c->first()->first_name)->toBe('Jovana')
        ->and($c->first()->name)->toBe('Jovana Bisevac')
        ->and($c->first()->phone)->toBe('+971500000000')
        ->and($c->first()->password)->toBeNull();
});

it('replaces a typo with the tapped fix and leaves no typo customer behind', function () {
    /*
     * DEFECT: jovana8b@yahoo.co filed for ever as a customer nobody is.
     * MUTATION: drop dropIfOnlyCaptured() -> the yahoo.co row survives.
     */
    $cart = capCart();

    capPost($cart, 'jovana8b@yahoo.co')->assertNoContent();
    expect(capCustomers('jovana8b@yahoo.co'))->toHaveCount(1);

    capPost($cart, 'jovana8b@yahoo.com')->assertNoContent();

    $fresh = DB::table('carts')->where('id', $cart->id)->first();

    expect(capCustomers('jovana8b@yahoo.co'))->toHaveCount(0)
        ->and(capCustomers('jovana8b@yahoo.com'))->toHaveCount(1)
        ->and($fresh->ct_email)->toBe('jovana8b@yahoo.com')
        ->and((int) $fresh->ct_email_customer_id)->toBe((int) capCustomers('jovana8b@yahoo.com')->first()->id);
});

it('keeps a typo row that is somebody real, or that another cart also typed', function () {
    // MUTATION: drop the origin / other-cart checks in dropIfOnlyCaptured -> red.
    $real = Customer::create(['email' => 'real@yahoo.co', 'name' => 'Real Person']);
    $a = capCart();
    capPost($a, 'real@yahoo.co')->assertNoContent();
    capPost($a, 'real@yahoo.com')->assertNoContent();

    $b = capCart();
    $c = capCart();
    capPost($b, 'shared@yahoo.co')->assertNoContent();
    capPost($c, 'shared@yahoo.co')->assertNoContent();
    capPost($b, 'shared@yahoo.com')->assertNoContent();

    expect(Customer::query()->whereKey($real->id)->exists())->toBeTrue()
        ->and(capCustomers('shared@yahoo.co'))->toHaveCount(1);
});

it('links an existing registered customer and changes nothing on it', function () {
    /*
     * DEFECT this guards: a stranger typing a member's address rewrites the
     * member's row, or pulls their basket into the member's account.
     * MUTATION: write carts.customer_id instead of ct_email_customer_id -> red;
     * set origin on a found row -> red.
     */
    $member = Customer::create(['email' => 'member@example.com', 'name' => 'Real Member', 'first_name' => 'Real',
        'last_name' => 'Member', 'phone' => '+971501112222', 'password' => 'a-real-password-1']);
    $before = (array) DB::table('customers')->where('id', $member->id)->first();
    $cart = capCart();

    capPost($cart, 'MEMBER@example.com')->assertNoContent();

    $after = (array) DB::table('customers')->where('id', $member->id)->first();
    $fresh = DB::table('carts')->where('id', $cart->id)->first();

    expect($after)->toBe($before)
        ->and($after['origin'])->toBeNull()
        ->and((int) $fresh->ct_email_customer_id)->toBe((int) $member->id)
        ->and($fresh->customer_id)->toBeNull()
        ->and(capCustomers('member@example.com'))->toHaveCount(1);
});

it('answers a new address and an address with an account exactly the same', function () {
    // MUTATION: return anything but the bare 204 for a found row -> red.
    Customer::create(['email' => 'known@example.com', 'name' => 'Known', 'password' => 'a-real-password-1']);

    $new = capPost(capCart(), 'fresh@example.com');
    $known = capPost(capCart(), 'known@example.com');
    $bad = capPost(capCart(), 'not-an-email');
    $none = capPost(null, 'fresh2@example.com');

    foreach ([$new, $known, $bad, $none] as $r) {
        expect($r->getStatusCode())->toBe(204)
            ->and($r->getContent())->toBe('')
            ->and($r->headers->get('Content-Type'))->toBe($new->headers->get('Content-Type'));
    }
});

/* ══════════════════════════════ 3. ABUSE ══════════════════════════════════ */

it('has its own throttle bucket, twelve a minute', function () {
    /*
     * DEFECT: an unauthenticated write sharing the unnamed per-address bucket
     * with the cart-trail beacon (see checkout-line.php's 'checkout-coupon').
     * MUTATION: drop the 'checkout-email' prefix -> the cart-trail beacon after
     * it is refused too; raise the limit -> no 429.
     */
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/checkout-contact');
    expect($route->gatherMiddleware())->toContain('throttle:12,1,checkout-email');

    $cart = capCart();
    for ($i = 0; $i < 12; $i++) {
        capPost($cart, 'same@example.com')->assertNoContent();
    }
    capPost($cart, 'same@example.com')->assertStatus(429);

    capAs($cart)->post('/api/cart-trail', ['e' => json_encode([[30, '/checkout/', 0]])])->assertNoContent();
});

it('records at most three different addresses per cart and logs the rest', function () {
    // MUTATION: drop the MAX_PER_CART check -> a fourth customer row.
    Log::spy();
    $cart = capCart();

    foreach (['a1@example.com', 'a2@example.com', 'a3@example.com', 'a4@example.com'] as $e) {
        capPost($cart, $e)->assertNoContent();
    }

    expect(capCustomers('a4@example.com'))->toHaveCount(0)
        ->and(DB::table('carts')->where('id', $cart->id)->value('ct_email'))->toBe('a3@example.com');
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx = []) => str_contains($m, 'already sent 3 different addresses') && ($ctx['cart_id'] ?? null) === $cart->id)->once();
});

it('records nothing for a bot, by its user agent or by its cart\'s score', function () {
    // MUTATION: drop either bot check in run() -> a customer row.
    $cart = capCart();
    capPost($cart, 'bot1@example.com', [], 'python-requests/2.31')->assertNoContent();
    capPost($cart, 'bot2@example.com', [], 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36')->assertNoContent();

    $scored = capCart();
    DB::table('carts')->where('id', $scored->id)->update(['ct_bot_score' => 90]);
    capPost($scored, 'bot3@example.com')->assertNoContent();

    expect(DB::table('customers')->count())->toBe(0)
        ->and(DB::table('carts')->whereNotNull('ct_email')->count())->toBe(0);
});

it('records nothing without a cart, or for an empty one', function () {
    // MUTATION: create the cart (create: true) -> a cart and a row from nothing.
    capPost(null, 'nocart@example.com')->assertNoContent();

    $empty = capCart();
    $empty->items()->delete();
    capPost($empty, 'empty@example.com')->assertNoContent();

    expect(DB::table('customers')->count())->toBe(0)
        ->and(DB::table('carts')->whereNotNull('ct_email')->count())->toBe(0);
});

it('ignores a cart id in the body: only the requester\'s own cart is ever written', function () {
    // MUTATION: read cart_id/cart/token from the body -> the victim cart is written.
    $mine = capCart();
    $victim = capCart();

    capPost($mine, 'me@example.com', ['cart_id' => $victim->id, 'cart' => $victim->id, 'token' => $victim->token])->assertNoContent();
    capPost(null, 'other@example.com', ['cart_id' => $victim->id, 'token' => $victim->token])->assertNoContent();

    expect(DB::table('carts')->where('id', $victim->id)->value('ct_email'))->toBeNull()
        ->and(DB::table('carts')->where('id', $mine->id)->value('ct_email'))->toBe('me@example.com')
        ->and(capCustomers('other@example.com'))->toHaveCount(0);
});

it('records nothing for a signed-in customer, and does not publish the endpoint to them', function () {
    $member = Customer::create(['email' => 'in@example.com', 'name' => 'In', 'password' => 'a-real-password-1']);
    $cart = capCart();

    capAs($cart)->actingAs($member, 'customer')->post('/api/checkout-contact', ['email' => 'else@example.com'])->assertNoContent();

    expect(capCustomers('else@example.com'))->toHaveCount(0);
});

it('sends no email of any kind for a capture', function () {
    /*
     * DEFECT this guards: a "welcome", invite or verification email to an
     * address nobody confirmed. MUTATION: call sendEmailVerificationNotification
     * or the invite path from the capture -> red.
     */
    capArmReminder();
    $cart = capCart();
    capPost($cart, 'quiet@example.com')->assertNoContent();
    capPost($cart, 'quiet2@example.com')->assertNoContent();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    \Illuminate\Support\Facades\Notification::fake();
    \Illuminate\Support\Facades\Notification::assertNothingSent();
});

/* ═══════════════════════════ 4. THE SWITCH ════════════════════════════════ */

it('ships ON, and OFF records nothing and leaves the checkout byte-identical', function () {
    /*
     * The owner asked for it, so it ships on (CLAUDE.md, 30 September).
     * Off, the checkout must be the page it was before this existed.
     * MUTATION: print the endpoint outside the @if -> the byte comparison is
     * red; ignore the switch in run() -> a row.
     */
    expect(CartTrackingSettings::SCHEMA['capture_email'][2])->toBeTrue()
        ->and(CartTrackingSettings::SCHEMA['capture_reminder'][2])->toBeTrue()
        ->and(app(CheckoutEmailCapture::class)->enabled())->toBeTrue();

    $cart = capCart();
    $on = capCheckout($cart);
    expect(substr_count($on, 'window.KBB.routes.checkoutEmail = '))->toBe(1);

    capSet('capture_email', false);
    capPost($cart, 'off@example.com')->assertNoContent();
    expect(capCustomers('off@example.com'))->toHaveCount(0)
        ->and(DB::table('carts')->where('id', $cart->id)->value('ct_email'))->toBeNull();

    $off = capCheckout($cart);

    // The checkout view as it was before this lane, from the same file.
    $now = (string) file_get_contents(resource_path('views/store/checkout.blade.php'));
    $start = strpos($now, "@if (class_exists(\\App\\Support\\CheckoutEmailCapture::class)");
    $end = strpos($now, "@endif\n", $start) + strlen("@endif\n");
    $dir = storage_path('framework/testing/cap-before-'.getmypid());
    @mkdir($dir.'/store', 0777, true);
    file_put_contents($dir.'/store/checkout.blade.php', substr($now, 0, $start).substr($now, $end));
    $finder = View::getFinder();
    $paths = $finder->getPaths();
    $finder->prependLocation($dir);
    $finder->flush();

    try {
        $before = capCheckout($cart);
    } finally {
        $finder->setPaths($paths);
        $finder->flush();
    }

    $mask = fn (string $h) => \Tests\Support\EnglishRenderWalk::mask($h);
    expect($mask($off))->toBe($mask($before))
        ->and($off)->not->toContain('checkoutEmail');
});

it('costs the checkout no query, and the capture the same queries for 3 lines or 40', function () {
    /*
     * CLAUDE.md rule 4: flat as the catalogue grows. MUTATION: load the cart's
     * items to check it is non-empty (instead of exists()) and the 40-line
     * cart costs more; read the switch around the settings memo -> the
     * checkout's count moves.
     */
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    $count = function (callable $fn) use (&$n): int {
        $n = 0;
        $fn();

        return $n;
    };

    $cart = capCart();
    // Each measured render follows a warm one: saving a setting empties the
    // settings cache, and the render after it refills it either way.
    capSet('capture_email', true);
    capCheckout($cart);
    $on = $count(fn () => capCheckout($cart));
    capSet('capture_email', false);
    capCheckout($cart);
    $off = $count(fn () => capCheckout($cart));
    capSet('capture_email', true);
    expect($on)->toBe($off);

    // Warm once: the first capture in a process asks whether its columns exist.
    capPost(capCart(), 'warm@example.com')->assertNoContent();
    $small = capCart(3);
    $big = capCart(40);
    $a = $count(fn () => capPost($small, 'small@example.com')->assertNoContent());
    $b = $count(fn () => capPost($big, 'big@example.com')->assertNoContent());
    expect($b)->toBe($a);
});

/* ════════════════════ 5. THE LEFT-CART REMINDER, ONCE ═════════════════════ */

it('sends the left-cart reminder exactly once, after the first delay, to the typed address', function () {
    /*
     * The owner: "only the left cart email should go auto. nothing else."
     * MUTATION: drop captureTyped() from run() -> nothing sent; drop the
     * stage-0 rule in typedRule() -> the second stage is sent too.
     */
    capArmReminder('0.25, 0.5');
    $cart = capCart();
    capPost($cart, 'left@example.com')->assertNoContent();

    capTick();
    Mail::assertNothingSent();      // not before the delay

    capBackdate($cart);
    capTick();
    capTick();
    capTick();

    Mail::assertSent(CartRecoveryReminder::class, 1);
    Mail::assertSent(CartRecoveryReminder::class, fn ($m) => $m->hasTo('left@example.com'));
    // Nothing else, of any kind: the whole fake list is that one reminder.
    Mail::assertOutgoingCount(1);
    expect(DB::table('cart_recoveries')->where('cart_id', $cart->id)->value('source'))->toBe('checkout_email');
});

it('sends one reminder per address, however many baskets it left', function () {
    /*
     * DEFECT, found on the preview walk: three visits, three baskets, the same
     * address typed each time -> three identical reminders in one sweep.
     * MUTATION: drop the $typed check in due() -> three in the first sweep;
     * drop the cr2 NOT EXISTS -> the older baskets go on the next sweep.
     */
    capArmReminder();
    $carts = [capCart(), capCart(), capCart()];
    foreach ($carts as $c) {
        capPost($c, 'thrice@example.com')->assertNoContent();
        capBackdate($c);
    }

    capTick();
    capTick();
    capTick();

    Mail::assertSent(CartRecoveryReminder::class, 1);
});

it('sends no reminder once an order is placed', function () {
    capArmReminder();
    $cart = capCart();
    capPost($cart, 'buyer@example.com')->assertNoContent();
    capPlace($cart, 'buyer@example.com')->assertRedirect();
    capBackdate($cart);
    capTick();

    Mail::assertNotSent(CartRecoveryReminder::class);
});

it('sends no reminder with the module off, the reminder switch off, or the capture switch off', function () {
    // MUTATION: drop either switch from typedRule() or remindEnabled() -> red.
    $cart = capCart();
    capPost($cart, 'mod-off@example.com')->assertNoContent();     // module off: no row at all
    expect(DB::table('cart_recoveries')->count())->toBe(0);

    capArmReminder();
    $a = capCart();
    capPost($a, 'switch-off@example.com')->assertNoContent();
    capBackdate($a);
    capSet('capture_reminder', false);
    capTick();

    capSet('capture_reminder', true);
    capSet('capture_email', false);
    capTick();

    Mail::assertNotSent(CartRecoveryReminder::class);
});

it('never sends the reminder to a typo the shopper corrected', function () {
    // MUTATION: leave the recovery row on the first address -> yahoo.co gets it.
    capArmReminder();
    $cart = capCart();
    capPost($cart, 'jovana8b@yahoo.co')->assertNoContent();
    capPost($cart, 'jovana8b@yahoo.com')->assertNoContent();
    capBackdate($cart);
    capTick();

    Mail::assertSent(CartRecoveryReminder::class, 1);
    Mail::assertNotSent(CartRecoveryReminder::class, fn ($m) => $m->hasTo('jovana8b@yahoo.co'));
});

it('sends no reminder to a cart scored a bot after the capture, or to a bounced address', function () {
    capArmReminder();
    $bot = capCart();
    capPost($bot, 'later-bot@example.com')->assertNoContent();
    DB::table('carts')->where('id', $bot->id)->update(['ct_bot_score' => 95]);

    $bounced = capCart();
    capPost($bounced, 'bounced@example.com')->assertNoContent();
    DB::table('email_suppressions')->insert(['email' => 'bounced@example.com', 'reason' => 'bounce', 'created_at' => now()]);

    $hard = capCart();
    capPost($hard, 'hard@example.com')->assertNoContent();
    DB::table('email_bounces')->insert(['email' => 'hard@example.com', 'kind' => 'hard', 'source' => 'smtp', 'created_at' => now()]);

    capBackdate($bot);
    capBackdate($bounced);
    capBackdate($hard);
    capTick();

    Mail::assertNotSent(CartRecoveryReminder::class);
});

it('never touches a shopper\'s own opt-in reminder', function () {
    capArmReminder('0.25, 0.5');
    $cart = capCart();
    app(CartRecovery::class)->capture($cart->id, 'optin@example.com', true, 'cart');
    capPost($cart, 'typed@example.com')->assertNoContent();

    $row = DB::table('cart_recoveries')->where('cart_id', $cart->id)->first();
    expect($row->email)->toBe('optin@example.com')->and($row->source)->toBe('cart');

    capBackdate($cart);
    capTick();
    capTick();
    // The opt-in sequence keeps both of its stages, as before.
    Mail::assertSent(CartRecoveryReminder::class, 2);
});

/* ═════════════════════════════ 6. THE ADMIN ═══════════════════════════════ */

it('shows Cart Tracking the typed email and its customer, and Customers the label until an order', function () {
    // MUTATION: drop ct_email from cartColumns() / the 'typed' key / 'from_checkout' -> red.
    CartTrackingRoutes::wire(app());
    CustomersAdminRoutes::wire(app());
    $owner = AdminUser::create(['name' => 'Owner', 'email' => 'cap-'.uniqid().'@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);

    $cart = capCart();
    capPost($cart, 'seen@example.com')->assertNoContent();
    $id = (int) capCustomers('seen@example.com')->first()->id;

    Cache::flush();
    $row = collect(test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking?period=7d')->assertOk()->json('rows'))
        ->firstWhere('id', $cart->id);
    expect($row['email'])->toBe('seen@example.com')
        ->and($row['typed'])->toBeTrue()
        ->and($row['customer']['id'])->toBe($id);

    $found = test()->actingAs($owner, 'admin')->getJson('/admin-api/cart-tracking?period=7d&q=seen@example')->assertOk()->json('rows');
    expect(collect($found)->pluck('id')->all())->toBe([$cart->id]);

    $list = fn () => collect(test()->actingAs($owner, 'admin')->getJson('/admin-api/customers/list')->assertOk()->json('customers'))->firstWhere('id', $id);
    expect($list()['from_checkout'])->toBeTrue()
        ->and($list()['account_type'])->toBe('guest');

    capPlace($cart, 'seen@example.com')->assertRedirect();
    expect($list()['from_checkout'])->toBeFalse();
});

it('keeps a captured guest out of marketing until they order', function () {
    // MUTATION: drop the origin clause in Audience::customers() -> red.
    $cart = capCart();
    capPost($cart, 'nomarketing@example.com')->assertNoContent();
    $id = (int) capCustomers('nomarketing@example.com')->first()->id;

    $ids = fn () => app(Audience::class)->customerIds([])->pluck('id')->map(fn ($v) => (int) $v)->all();
    expect($ids())->not->toContain($id);

    capPlace($cart, 'nomarketing@example.com')->assertRedirect();
    expect($ids())->toContain($id);
});

/* ═══════════════════════════ 7. THE MIGRATION ═════════════════════════════ */

it('migrates with literals and framework calls only', function () {
    // The 2.60.512 rollback: a migration that named an app class ran against
    // the OLD classes. MUTATION: reference any App\ class -> red.
    $code = '';
    foreach (token_get_all((string) file_get_contents(database_path('migrations/'.CAP_MIGRATION))) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($t) ? $t[1] : $t;
    }

    expect($code)->not->toMatch('/\bApp\\\\/')
        ->and($code)->not->toContain('CheckoutEmailCapture')
        ->and(\Illuminate\Support\Facades\Schema::hasColumns('carts', ['ct_email', 'ct_email_customer_id', 'ct_email_n']))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('customers', 'origin'))->toBeTrue();

    $m = require database_path('migrations/'.CAP_MIGRATION);
    $m->up();   // a second run is harmless
    $m->down();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('customers', 'origin'))->toBeFalse();
    $m->up();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('customers', 'origin'))->toBeTrue();
});
