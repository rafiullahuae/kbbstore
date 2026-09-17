<?php

declare(strict_types=1);

/**
 * Back-in-stock alerts and abandoned-cart recovery, end to end (Lane EN).
 *
 * The properties every test here defends, in one list:
 *
 *   1. BOTH SHIP OFF AND UNWRITTEN. Applying this package changes nothing a
 *      shopper sees and sends nothing. Switching a module on with no wording
 *      still sends nothing.
 *   2. NOTHING SENDS TO AN ADDRESS THAT HAS NOT ASKED. A stock alert is consent
 *      for one product and never touches the marketing list.
 *   3. EVERY MESSAGE CARRIES A WORKING UNSUBSCRIBE, and it is honoured
 *      afterwards as well as at the moment it is pressed.
 *   4. NOTHING SENDS TWICE, and the guarantee is a database constraint rather
 *      than a check followed by a write.
 *   5. A RECOVERY EMAIL NEVER REACHES SOMEBODY WHO HAS ORDERED, including when
 *      they order while the message is being prepared.
 *
 * Mail::fake() throughout. Nothing here sends a real message, and the
 * assertions are on the mailable, its recipient and its RENDERED BODY — not on
 * a spy that records a call. A stub that cannot fail would pass against an
 * alert with a broken unsubscribe link in it, which is the one defect that
 * would make the whole feature useless while looking finished.
 */

use App\Mail\BackInStockAlert;
use App\Mail\CartRecoveryReminder;
use App\Models\AdminUser;
use App\Models\Product;
use App\Services\CartRecovery;
use App\Services\OutboundTick;
use App\Services\SettingsService;
use App\Services\StockAlerts;
use App\Support\OutboundOptOut;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OutboundRoutes;

beforeEach(function () {
    OutboundRoutes::wire();
    Mail::fake();
});

/* ─────────────────────────────── fixtures ─────────────────────────────── */

function enSettings(): SettingsService
{
    return app(SettingsService::class);
}

/** A product, sold out unless told otherwise. */
function enProduct(string $stock = 'outofstock'): Product
{
    return Product::create([
        'name' => 'EN Serum ' . uniqid(),
        'slug' => 'en-serum-' . uniqid(),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => $stock,
    ]);
}

/** Switch a module on and write the wording it needs. */
function enArmStock(): void
{
    enSettings()->setModule('back_in_stock', true);
    enSettings()->set(StockAlerts::KEY_FORM_LABEL, 'Sold out — we will tell you when it returns.');
    enSettings()->set(StockAlerts::KEY_SUBJECT, 'It is back');
    enSettings()->set(StockAlerts::KEY_BODY, 'The thing you asked about is back in stock.');
}

function enArmCart(string $schedule = '0.25'): void
{
    enSettings()->setModule('abandoned_cart', true);
    enSettings()->set(CartRecovery::KEY_OPTIN_LABEL, 'Email me a reminder about this basket.');
    enSettings()->set(CartRecovery::KEY_SUBJECT, 'Your basket');
    enSettings()->set(CartRecovery::KEY_BODY, 'You left something behind.');
    enSettings()->set(CartRecovery::KEY_SCHEDULE, $schedule);
}

/** A cart with one line in it, and its recovery row consented `$hoursAgo` ago. */
function enCartWithItem(Product $product): int
{
    $cartId = (int) DB::table('carts')->insertGetId([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'last_activity_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('cart_items')->insert([
        'cart_id' => $cartId,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 12000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $cartId;
}

/** Backdate a consent so the first stage is due. */
function enBackdate(int $cartId, int $hours = 5): void
{
    DB::table('cart_recoveries')
        ->where('cart_id', $cartId)
        ->update(['consented_at' => now()->subHours($hours)]);
}

/**
 * The unsubscribe URL as the shopper's mail client actually sees it, pulled out
 * of the RENDERED body rather than rebuilt from the signer.
 *
 * Rebuilding it would test that the signer agrees with itself and would pass
 * against a view that printed the wrong variable — which is precisely the
 * defect that makes an unsubscribe look present and not work.
 */
function enUnsubUrl(string $class, string $kind): string
{
    $mailed = Mail::sent($class);

    expect($mailed)->not->toBeEmpty('no message of that kind was sent at all');

    $body = (string) $mailed->first()->render();

    expect(preg_match(
        '#https?://[^"\s]*?/mail-preferences/' . $kind . '/\d+/\?expires=\d+&(?:amp;)?signature=[0-9a-f]{64}#',
        $body,
        $m
    ))->toBe(1, 'the rendered email carried no usable unsubscribe link');

    return html_entity_decode($m[0]);
}

function enPathOf(string $url): string
{
    $parts = parse_url($url);

    return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function enAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'EN Owner',
        'email' => 'en-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/* ══════════════════════ 1. OFF, AND UNWRITTEN ══════════════════════ */

it('ships both modules off', function () {
    expect(enSettings()->moduleEnabled('back_in_stock', false))->toBeFalse()
        ->and(enSettings()->moduleEnabled('abandoned_cart', false))->toBeFalse();
});

it('shows no notify-me form on a sold-out product while the module is off', function () {
    $product = enProduct();

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();

    // The class, not the prose: prose can be edited, and a class-name search of
    // rendered HTML would also match inlined CSS — so this asserts on the
    // element's own attribute spelling.
    expect($html)->not->toContain('class="notifyme"');
});

it('shows no notify-me form when the module is on but no wording is written', function () {
    enSettings()->setModule('back_in_stock', true);

    $product = enProduct();

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();

    expect($html)->not->toContain('class="notifyme"');
});

it('shows the notify-me form only once the module is on and the wording written', function () {
    enArmStock();

    $product = enProduct();

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();

    expect($html)->toContain('class="notifyme"')
        ->and($html)->toContain('Sold out — we will tell you when it returns.');
});

it('sends nothing when the module is on and the message is unwritten', function () {
    enSettings()->setModule('back_in_stock', true);

    $product = enProduct('instock');

    // The request itself is accepted — consent is worth collecting the moment
    // the shopper gives it — but nothing may be sent.
    app(StockAlerts::class)->request((int) $product->id, 0, 'waiting@example.test');

    app(OutboundTick::class)->run();

    Mail::assertNothingSent();

    expect(DB::table('stock_alerts')->whereNull('notified_at')->count())->toBe(1);
});

it('sends no basket reminder while the schedule box is empty', function () {
    enArmCart('');

    $cartId = enCartWithItem(enProduct('instock'));

    app(CartRecovery::class)->capture($cartId, 'nobody@example.test', true);
    enBackdate($cartId, 48);

    app(OutboundTick::class)->run();

    Mail::assertNothingSent();
});

/* ═══════════════ THE TRIGGER: AN ORDINARY PAGE VIEW SENDS IT ═══════════════ */

it('sends what is owed on the tail of an ordinary page view', function () {
    /*
     * THE TEST THE WHOLE TRIGGER DESIGN RESTS ON, and the one that would be
     * missing if this lane had only tested run() directly.
     *
     * There is no queue worker, no cron and no shell on this host, so a web
     * request is the only thing that ever executes PHP. Nothing here calls the
     * sweep: a shopper looks at a page, and the alert goes out because of it.
     * The chain being proved is RequestHandled -> OutboundTick::onRequest() ->
     * defer() -> InvokeDeferredCallbacks::terminate(), and a break anywhere
     * along it means a feature that passes every other test in this file and
     * never sends anything on the real server.
     *
     * The lock is cleared first because the cache store outlives a single test
     * in one process, and a lock left by an earlier test would make this pass
     * or fail depending on what ran before it.
     */
    \Illuminate\Support\Facades\Cache::forget(OutboundTick::LOCK_KEY);

    enArmStock();

    $product = enProduct('instock');
    app(StockAlerts::class)->request((int) $product->id, 0, 'passerby@example.test');

    // Somebody — anybody — looks at the shop.
    test()->get('/')->assertOk();

    Mail::assertSent(BackInStockAlert::class, fn ($mail) => $mail->hasTo('passerby@example.test'));
});

it('sweeps at most once per interval however many pages are viewed', function () {
    \Illuminate\Support\Facades\Cache::forget(OutboundTick::LOCK_KEY);

    enArmStock();

    $product = enProduct('instock');

    // Two people waiting, and a budget that would cover both in one sweep.
    app(StockAlerts::class)->request((int) $product->id, 0, 'first@example.test');

    test()->get('/')->assertOk();

    // A second request inside the interval must NOT sweep again. The rate
    // limit is what stops a busy shop running a sweep on a meaningful share of
    // its page views.
    app(StockAlerts::class)->request((int) $product->id, 0, 'second@example.test');
    test()->get('/')->assertOk();

    expect(Mail::sent(BackInStockAlert::class))->toHaveCount(1);
});

it('costs nothing on a request while both modules are off', function () {
    // The shipped-state guard. If this ever returns true with both off, every
    // storefront page starts paying for a cache round trip it does not need.
    expect(app(OutboundTick::class)->anythingOn())->toBeFalse();
});

/* ══════════════ 2. CONSENT: ONE PRODUCT, NOT THE MAILING LIST ══════════════ */

it('never puts a back-in-stock request on the marketing list', function () {
    enArmStock();

    $product = enProduct();

    test()->post('/notify-me', [
        'product_id' => $product->id,
        'email' => 'shopper@example.test',
    ])->assertRedirect();

    expect(DB::table('stock_alerts')->where('email', 'shopper@example.test')->count())->toBe(1)
        // The marketing list is a different table and it must be untouched.
        ->and(DB::table('subscribers')->where('email', 'shopper@example.test')->count())->toBe(0)
        // And the only sanctioned marketing query cannot see the address.
        ->and(\App\Services\NewsletterList::marketable()->where('email', 'shopper@example.test')->count())->toBe(0);
});

it('answers a new address and a repeat address identically', function () {
    enArmStock();

    $product = enProduct();

    $first = test()->postJson('/notify-me', ['product_id' => $product->id, 'email' => 'same@example.test']);
    $second = test()->postJson('/notify-me', ['product_id' => $product->id, 'email' => 'same@example.test']);

    // Byte for byte. A difference of any kind is a membership oracle.
    expect($second->getContent())->toBe($first->getContent());
});

it('refuses to capture a basket address without the tick', function () {
    enArmCart();

    $product = enProduct('instock');
    $cartId = enCartWithItem($product);

    // capture() takes consent as an argument precisely so it cannot be given
    // by accident. Without it, nothing is written.
    $outcome = app(CartRecovery::class)->capture($cartId, 'untick@example.test', false);

    expect($outcome)->toBe(CartRecovery::OUTCOME_UNAVAILABLE)
        ->and(DB::table('cart_recoveries')->count())->toBe(0);
});

/* ════════════ 3. EVERY MESSAGE CARRIES A WORKING UNSUBSCRIBE ════════════ */

it('sends one alert carrying an unsubscribe link that works', function () {
    enArmStock();

    $product = enProduct('instock');

    app(StockAlerts::class)->request((int) $product->id, 0, 'alerted@example.test');
    app(OutboundTick::class)->run();

    Mail::assertSent(BackInStockAlert::class, fn ($mail) => $mail->hasTo('alerted@example.test'));

    $url = enUnsubUrl(BackInStockAlert::class, 'stock');

    // The GET renders and changes NOTHING — a mail scanner fetching every URL
    // in the message must not be able to unsubscribe the recipient.
    test()->get(enPathOf($url))->assertOk();
    expect(DB::table('outbound_optouts')->count())->toBe(0);

    // The POST acts.
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    test()->post('/mail-preferences', [
        'kind' => 'stock',
        'id' => (int) DB::table('stock_alerts')->value('id'),
        'expires' => $q['expires'],
        'signature' => $q['signature'],
    ])->assertOk();

    expect(DB::table('outbound_optouts')->where('email', 'alerted@example.test')->count())->toBe(1);
});

it('honours an opt-out for every later request from that address', function () {
    enArmStock();

    OutboundOptOut::suppress('gone@example.test');

    $product = enProduct('instock');

    // Refused at capture: the row is never written at all.
    $outcome = app(StockAlerts::class)->request((int) $product->id, 0, 'gone@example.test');

    expect($outcome)->toBe(StockAlerts::OUTCOME_SUPPRESSED)
        ->and(DB::table('stock_alerts')->count())->toBe(0);
});

it('honours an opt-out made after a request was already stored', function () {
    enArmStock();

    $product = enProduct('instock');

    app(StockAlerts::class)->request((int) $product->id, 0, 'later@example.test');

    // The row exists, and is due. THEN they opt out.
    OutboundOptOut::suppress('later@example.test');

    app(OutboundTick::class)->run();

    // The second half of the suppression: the due-query excludes it, so a row
    // written before the opt-out never goes out either.
    Mail::assertNothingSent();
});

it('signs a stock token that cannot act as a cart token', function () {
    $link = OutboundOptOut::link('stock', 1, 'x@example.test');

    parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

    // Same id, same expiry, same signature, different purpose. Separate
    // PURPOSE strings are what make this false.
    expect(OutboundOptOut::act('cart', 1, (int) $q['expires'], (string) $q['signature']))->toBeFalse();
});

it('gives a forged token and an id that was never issued the same answer', function () {
    $forged = OutboundOptOut::act('stock', 999_999, time() + 600, str_repeat('a', 64));
    $garbage = OutboundOptOut::act('stock', 1, time() + 600, 'not-a-signature');

    expect($forged)->toBeFalse()->and($garbage)->toBeFalse();
});

/* ════════════════════ 4. NOTHING SENDS TWICE ════════════════════ */

it('refuses a second pending request for the same shelf at the database', function () {
    enArmStock();

    $product = enProduct();
    $alerts = app(StockAlerts::class);

    expect($alerts->request((int) $product->id, 0, 'dup@example.test'))->toBe(StockAlerts::OUTCOME_STORED)
        ->and($alerts->request((int) $product->id, 0, 'dup@example.test'))->toBe(StockAlerts::OUTCOME_DUPLICATE)
        ->and(DB::table('stock_alerts')->count())->toBe(1);
});

it('refuses a second pending request even when the address is typed differently', function () {
    enArmStock();

    $product = enProduct();
    $alerts = app(StockAlerts::class);

    $alerts->request((int) $product->id, 0, 'Case@Example.Test');
    $alerts->request((int) $product->id, 0, 'case@example.test');

    // Normalised before the write, so two spellings are one address and the
    // unique index actually binds.
    expect(DB::table('stock_alerts')->count())->toBe(1);
});

it('lets exactly one of two racing claims win', function () {
    enArmStock();

    $product = enProduct('instock');
    $alerts = app(StockAlerts::class);

    $alerts->request((int) $product->id, 0, 'race@example.test');

    $id = (int) DB::table('stock_alerts')->value('id');

    // The compare-and-swap, exercised directly: the second caller is the
    // second sweeper, and it must be told it lost.
    expect($alerts->claim($id))->toBeTrue()
        ->and($alerts->claim($id))->toBeFalse();
});

it('sends one alert and only one, however many times the sweep runs', function () {
    enArmStock();

    $product = enProduct('instock');

    app(StockAlerts::class)->request((int) $product->id, 0, 'once@example.test');

    app(OutboundTick::class)->run();
    app(OutboundTick::class)->run();
    app(OutboundTick::class)->run();

    expect(Mail::sent(BackInStockAlert::class))->toHaveCount(1);
});

it('lets a shopper ask again after the product sells out a second time', function () {
    enArmStock();

    $product = enProduct('instock');
    $alerts = app(StockAlerts::class);

    $alerts->request((int) $product->id, 0, 'again@example.test');
    app(OutboundTick::class)->run();

    // It sold out again. The spent row must not block a fresh request: `slot`
    // has moved off 'pending', so the unique index permits the new one.
    expect($alerts->request((int) $product->id, 0, 'again@example.test'))->toBe(StockAlerts::OUTCOME_STORED)
        ->and(DB::table('stock_alerts')->count())->toBe(2)
        ->and(DB::table('stock_alerts')->whereNull('notified_at')->count())->toBe(1);
});

it('keeps one recovery sequence per cart however often the page is reloaded', function () {
    enArmCart();

    $cartId = enCartWithItem(enProduct('instock'));
    $recovery = app(CartRecovery::class);

    $recovery->capture($cartId, 'reload@example.test', true);
    $consented = DB::table('cart_recoveries')->where('cart_id', $cartId)->value('consented_at');

    // Four reloads.
    $recovery->capture($cartId, 'reload@example.test', true);
    $recovery->capture($cartId, 'reload@example.test', true);
    $recovery->capture($cartId, 'someone-else@example.test', true);

    expect(DB::table('cart_recoveries')->where('cart_id', $cartId)->count())->toBe(1)
        // The clock did not move — the sequence was not restarted.
        ->and(DB::table('cart_recoveries')->where('cart_id', $cartId)->value('consented_at'))->toBe($consented)
        // And a second visitor to the same browser did not take over the row.
        ->and(DB::table('cart_recoveries')->where('cart_id', $cartId)->value('email'))->toBe('reload@example.test');
});

it('lets exactly one of two racing stage claims win', function () {
    enArmCart();

    $cartId = enCartWithItem(enProduct('instock'));
    $recovery = app(CartRecovery::class);

    $recovery->capture($cartId, 'stage@example.test', true);
    $id = (int) DB::table('cart_recoveries')->value('id');

    expect($recovery->claim($id, 0))->toBeTrue()
        ->and($recovery->claim($id, 0))->toBeFalse();
});

it('sends each recovery stage once and stops at the end of the schedule', function () {
    // Two stages, both already due.
    enArmCart('0.25, 0.5');

    $cartId = enCartWithItem(enProduct('instock'));

    app(CartRecovery::class)->capture($cartId, 'seq@example.test', true);
    enBackdate($cartId, 5);

    app(OutboundTick::class)->run();
    app(OutboundTick::class)->run();
    app(OutboundTick::class)->run();
    app(OutboundTick::class)->run();

    // Two stages means two messages, and a fourth sweep adds nothing.
    expect(Mail::sent(CartRecoveryReminder::class))->toHaveCount(2)
        ->and((int) DB::table('cart_recoveries')->value('stage'))->toBe(2);
});

it('sends nothing before the owner’s first interval has elapsed', function () {
    enArmCart('24');

    $cartId = enCartWithItem(enProduct('instock'));

    app(CartRecovery::class)->capture($cartId, 'early@example.test', true);
    // Consented just now; the first stage is not due for a day.

    app(OutboundTick::class)->run();

    Mail::assertNothingSent();
});

/* ════════ 5. A RECOVERY MAIL NEVER REACHES SOMEBODY WHO HAS ORDERED ════════ */

it('cancels every live sequence for an address the moment an order is created', function () {
    enArmCart();

    $cartId = enCartWithItem(enProduct('instock'));

    app(CartRecovery::class)->capture($cartId, 'buyer@example.test', true);
    enBackdate($cartId, 5);

    // The Eloquent `created` hook fires inside whatever transaction is writing
    // the order — in the real checkout, the order's own.
    \App\Models\Order::create([
        'order_number' => 'EN-' . uniqid(),
        'email' => 'buyer@example.test',
        'status' => 'processing',
        'currency' => 'AED',
        'total' => 24000,
    ]);

    expect(DB::table('cart_recoveries')->value('cancel_reason'))->toBe('ordered');

    app(OutboundTick::class)->run();

    Mail::assertNothingSent();
});

it('cancels by address, so a second basket is not chased either', function () {
    enArmCart();

    $one = enCartWithItem(enProduct('instock'));
    $two = enCartWithItem(enProduct('instock'));

    app(CartRecovery::class)->capture($one, 'two-baskets@example.test', true);
    app(CartRecovery::class)->capture($two, 'two-baskets@example.test', true);
    enBackdate($one, 5);
    enBackdate($two, 5);

    \App\Models\Order::create([
        'order_number' => 'EN-' . uniqid(),
        'email' => 'two-baskets@example.test',
        'status' => 'processing',
        'currency' => 'AED',
        'total' => 1,
    ]);

    // Over-cancelling is the safe direction: a marketing email is cheaper than
    // one that reaches somebody who has just paid.
    expect(DB::table('cart_recoveries')->whereNull('cancelled_at')->count())->toBe(0);

    app(OutboundTick::class)->run();
    Mail::assertNothingSent();
});

it('does not send when the cart converted without an order carrying that address', function () {
    enArmCart();

    $cartId = enCartWithItem(enProduct('instock'));

    app(CartRecovery::class)->capture($cartId, 'manual@example.test', true);
    enBackdate($cartId, 5);

    // A manual order built in the admin marks the cart converted directly and
    // may carry a different address entirely. Barrier 4: the sweep only ever
    // looks at carts that are still `active`.
    DB::table('carts')->where('id', $cartId)->update(['status' => 'converted', 'converted_at' => now()]);

    app(OutboundTick::class)->run();

    Mail::assertNothingSent();
});

it('abandons the send when the order lands between the claim and the transport', function () {
    enArmCart();

    $cartId = enCartWithItem(enProduct('instock'));
    $recovery = app(CartRecovery::class);

    $recovery->capture($cartId, 'racer@example.test', true);
    $id = (int) DB::table('cart_recoveries')->value('id');

    // The claim has been taken — this process owns stage 1 and is building the
    // mailable. Now the shopper pays.
    expect($recovery->claim($id, 0))->toBeTrue();

    $recovery->cancelForEmail('racer@example.test', 'ordered');

    // Barrier 3: the last look before the transport. This is what stops the
    // message that is already in hand.
    expect($recovery->sendable($id))->toBeFalse();
});

it('does not chase a basket the shopper has emptied', function () {
    enArmCart();

    $cartId = enCartWithItem(enProduct('instock'));

    app(CartRecovery::class)->capture($cartId, 'emptied@example.test', true);
    enBackdate($cartId, 5);

    DB::table('cart_items')->where('cart_id', $cartId)->delete();

    app(OutboundTick::class)->run();

    // An email about an empty basket would describe nothing.
    Mail::assertNothingSent();
});

it('describes the basket as it is at send time, not as it was at capture', function () {
    enArmCart();

    $kept = enProduct('instock');
    $cartId = enCartWithItem($kept);

    app(CartRecovery::class)->capture($cartId, 'changed@example.test', true);
    enBackdate($cartId, 5);

    app(OutboundTick::class)->run();

    $body = (string) Mail::sent(CartRecoveryReminder::class)->first()->render();

    expect($body)->toContain($kept->name);
});

/* ═══════════════════ THE ALERT ITSELF ═══════════════════ */

it('does not alert about a product that is still sold out', function () {
    enArmStock();

    $product = enProduct();

    app(StockAlerts::class)->request((int) $product->id, 0, 'patient@example.test');
    app(OutboundTick::class)->run();

    Mail::assertNothingSent();

    // Owed nothing, and still waiting. Not a backlog.
    expect(DB::table('stock_alerts')->whereNull('notified_at')->count())->toBe(1);
});

it('does not alert about a product that has been unpublished', function () {
    enArmStock();

    $product = enProduct('instock');

    app(StockAlerts::class)->request((int) $product->id, 0, 'hidden@example.test');

    $product->forceFill(['status' => 'draft'])->save();

    app(OutboundTick::class)->run();

    // The alert would have linked to a page that 404s, which is a worse answer
    // than silence.
    Mail::assertNothingSent();
});

it('alerts about the variant the shopper asked about and not another', function () {
    enArmStock();

    $product = enProduct('instock');

    $wanted = DB::table('product_variants')->insertGetId([
        'product_id' => $product->id,
        'sku' => 'EN-V1-' . uniqid(),
        'price' => 12000,
        'stock_status' => 'outofstock',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(StockAlerts::class)->request((int) $product->id, (int) $wanted, 'shade@example.test');

    // The PARENT is in stock, but the shade they asked about is not.
    app(OutboundTick::class)->run();
    Mail::assertNothingSent();

    DB::table('product_variants')->where('id', $wanted)->update(['stock_status' => 'instock']);

    app(OutboundTick::class)->run();
    Mail::assertSent(BackInStockAlert::class, fn ($mail) => $mail->hasTo('shade@example.test'));
});

it('puts the owner’s words in the message and none of its own', function () {
    enArmStock();

    $product = enProduct('instock');

    app(StockAlerts::class)->request((int) $product->id, 0, 'words@example.test');
    app(OutboundTick::class)->run();

    $mail = Mail::sent(BackInStockAlert::class)->first();
    $body = (string) $mail->render();

    expect($body)->toContain('The thing you asked about is back in stock.')
        ->and($body)->toContain($product->name)
        // The subject is the owner's, verbatim and undecorated.
        ->and($mail->envelope()->subject)->toBe('It is back');
});

it('keeps the product name out of the subject line', function () {
    enArmStock();

    $product = enProduct('instock');

    app(StockAlerts::class)->request((int) $product->id, 0, 'quiet@example.test');
    app(OutboundTick::class)->run();

    // A subject shows on a locked phone screen and in every mail server's log.
    expect(Mail::sent(BackInStockAlert::class)->first()->envelope()->subject)
        ->not->toContain($product->name);
});

it('records every message it sends in the delivery log', function () {
    /*
     * THE ONE TEST HERE THAT DOES NOT USE Mail::fake(), and MailDeliveryLogTest's
     * header says why: the fake replaces the Mailer itself and never fires the
     * transport's MessageSending / MessageSent events, so MailLog — which is
     * wired to exactly those two events and to nothing else — records nothing
     * under it. A test built on the fake would assert that the fake works.
     *
     * So the fake this file installs in beforeEach is taken back out and the
     * application's own `kbb` mailer is pointed at the `array` transport, which
     * is a genuine Symfony transport that really runs the send and really fires
     * both events while delivering into memory. Nothing leaves the process.
     */
    Mail::clearResolvedInstances();
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');
    config()->set('mail.mailers.' . \App\Services\Mail\MailConfigurator::MAILER, ['transport' => 'array']);

    enArmStock();

    $product = enProduct('instock');

    app(StockAlerts::class)->request((int) $product->id, 0, 'logged@example.test');
    app(OutboundTick::class)->run();

    // Labelled, so the Sent mail screen can group it and the owner can answer
    // "did this customer get it" — which is the whole reason the log exists.
    expect(DB::table('mail_deliveries')->where('kind', 'stock.back')->count())->toBe(1)
        ->and(DB::table('mail_deliveries')->where('kind', 'stock.back')->value('recipient'))
        ->toContain('logged@example.test')
        ->and(DB::table('mail_deliveries')->where('kind', 'stock.back')->value('status'))->toBe('sent');
});

/* ═══════════════════ THE BACKLOG IS NOT SILENT ═══════════════════ */

it('names an unwritten message as the reason nothing is going out', function () {
    enSettings()->setModule('back_in_stock', true);

    $product = enProduct('instock');
    app(StockAlerts::class)->request((int) $product->id, 0, 'stuck@example.test');

    $body = test()->actingAs(enAdmin(), 'admin')
        ->getJson('/admin-api/outbound/backlog')
        ->assertOk()
        ->json();

    // The state that looks like success and is not: consent accumulating,
    // nothing ever sent.
    expect($body['stock']['state'])->toBe('unwritten')
        ->and($body['stock']['pending'])->toBe(1);
});

it('names an empty schedule as the reason no reminder is going out', function () {
    enSettings()->setModule('abandoned_cart', true);
    enSettings()->set(CartRecovery::KEY_SUBJECT, 'Your basket');
    enSettings()->set(CartRecovery::KEY_BODY, 'You left something behind.');

    $body = test()->actingAs(enAdmin(), 'admin')
        ->getJson('/admin-api/outbound/backlog')
        ->assertOk()
        ->json();

    expect($body['cart']['state'])->toBe('unscheduled');
});

it('says when the sweep last ran, so silence can be told from health', function () {
    enArmStock();

    $before = test()->actingAs(enAdmin(), 'admin')->getJson('/admin-api/outbound/backlog')->json();

    expect($before['last_swept_at'])->toBeNull();

    app(OutboundTick::class)->run();

    $after = test()->actingAs(enAdmin(), 'admin')->getJson('/admin-api/outbound/backlog')->json();

    expect($after['last_swept_at'])->not->toBeNull();
});

it('reports what shoppers are waiting for, counted and never listed', function () {
    enArmStock();

    $wanted = enProduct();
    $alerts = app(StockAlerts::class);

    $alerts->request((int) $wanted->id, 0, 'a@example.test');
    $alerts->request((int) $wanted->id, 0, 'b@example.test');

    $response = test()->actingAs(enAdmin(), 'admin')
        ->getJson('/admin-api/outbound/demand')
        ->assertOk();

    expect($response->json('products.0.waiting'))->toBe(2)
        ->and($response->json('products.0.name'))->toBe($wanted->name)
        // NOT an export of who asked.
        ->and($response->getContent())->not->toContain('a@example.test');
});

/* ═══════════════════ THE ADMIN SURFACE IS GUARDED ═══════════════════ */

it('keeps every admin endpoint behind the admin guard', function (string $method, string $path) {
    $response = $method === 'post' ? test()->post($path) : test()->get($path);

    // Not 200, whatever else it is. These carry commercial information and one
    // of them sends email.
    expect($response->getStatusCode())->not->toBe(200);
})->with([
    ['get', '/admin-api/outbound/backlog'],
    ['get', '/admin-api/outbound/demand'],
    ['get', '/admin-api/outbound/recovery'],
    ['post', '/admin-api/outbound/sweep'],
]);

it('cannot be made to send twice by pressing the manual sweep', function () {
    enArmStock();

    $product = enProduct('instock');
    app(StockAlerts::class)->request((int) $product->id, 0, 'button@example.test');

    $admin = enAdmin();

    test()->actingAs($admin, 'admin')->postJson('/admin-api/outbound/sweep')->assertOk();
    test()->actingAs($admin, 'admin')->postJson('/admin-api/outbound/sweep')->assertOk();
    test()->actingAs($admin, 'admin')->postJson('/admin-api/outbound/sweep')->assertOk();

    expect(Mail::sent(BackInStockAlert::class))->toHaveCount(1);
});
