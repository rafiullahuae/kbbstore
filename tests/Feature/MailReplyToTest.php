<?php

declare(strict_types=1);

/**
 * A reply that reaches somebody, and a footer that only says so when it does
 * — Lane DE.
 *
 * ── WHAT WAS REMOVED, AND WHY IT COULD NOT SIMPLY BE PUT BACK ───────────────
 *
 * Every customer-facing order email used to close by inviting the customer to
 * reply, and promising the reply reached the shop. The audit lane withdrew that
 * sentence, and was right to: nothing in this application set a Reply-To header,
 * so a reply went to the From address — and MailSettings::fromAddress() derives
 * `no-reply@<domain>` from APP_URL whenever the From box is empty, which is the
 * shipped state of Store → Mail, deliberately, because the server transport
 * needs nothing filled in. The sentence was an invitation to write to a mailbox
 * named for not being read.
 *
 * Restoring the wording alone would have restored the untruth. So this lane
 * built the thing the sentence was describing:
 *
 *   1. a Reply-To box on Store → Mail, blank by default;
 *   2. MailConfigurator writing it into `mail.reply_to`, so the header really
 *      is on the message;
 *   3. the footer's invitation back — ONLY while the box has an address in it.
 *
 * ── WHY EVERY ONE OF THOSE IS ASSERTED THROUGH A REAL SEND ──────────────────
 *
 * The header is read back out of a Symfony message this app actually sent, not
 * out of `config()`. Laravel applies a global address when it BUILDS a mailer
 * and memoises the result, so a value in the configuration is not the same
 * claim as a value on the message — and the gap between the two is a whole
 * class of bug (see MailConfigurator::refresh(), which purges the framework's
 * default mailer as well as this app's for exactly that reason).
 *
 * The save goes through MailApiController, because a key missing from a
 * validation allowlist makes Save report success and write nothing, which this
 * repo has recorded twice. That controller checks what it is given against
 * MailSettings::SCHEMA, so a key added there needs no controller change; this
 * file is the proof rather than the assertion.
 */

use App\Mail\OrderConfirmation;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function rtOrder(): Order
{
    $order = Order::create([
        'order_number' => 'KBB-RT-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000, 'shipping_total' => 2000, 'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    ]);

    $order->items()->create(['name' => 'Rice Toner', 'quantity' => 1, 'unit_price' => 20000, 'total' => 20000]);

    return $order->fresh('items');
}

/**
 * The owner, made fresh in each test.
 *
 * NOT memoised in a static: RefreshDatabase rolls the row back between tests
 * while a static would keep the model object, and the next test would then
 * authenticate as a user that is no longer in the database.
 */
function rtAdmin(): AdminUser
{
    return AdminUser::firstOrCreate(
        ['email' => 'rt-owner@example.com'],
        ['name' => 'Owner', 'password' => 'secret-secret', 'role' => 'owner'],
    );
}

/** Save through the REAL Store → Mail endpoint, as the owner does. */
function rtSaveThroughScreen(array $settings): void
{
    test()->actingAs(rtAdmin(), 'admin')
        ->postJson('/admin-api/mail', ['settings' => $settings])
        ->assertOk()
        ->assertJson(['ok' => true]);

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/**
 * Send a real order confirmation and hand back the Symfony message.
 *
 * Through Mail::send() on the app's own mailer rather than Mail::fake(), which
 * intercepts the Mailable BEFORE a Mailer is involved and would therefore never
 * apply a global address at all — a fake would report a green test about a
 * header this application had not set.
 */
function rtSent(bool $refresh = true): Symfony\Component\Mime\Email
{
    if ($refresh) {
        app(MailConfigurator::class)->refresh();
    }

    /*
     * Mail::mailer(), NOT app('mailer'). The container binds `mailer` as a
     * singleton holding whatever the manager handed out first, so it would go
     * on serving a mailer that refresh() has already purged — and the test
     * would then be reading headers off the instance the purge exists to
     * replace. Mail::mailer() asks the manager, which rebuilds.
     */
    $mailer = Mail::mailer();

    $mailer->to('buyer@example.com')->send(new OrderConfirmation(rtOrder()));

    $messages = $mailer->getSymfonyTransport()->messages();

    expect($messages)->not->toBeEmpty('nothing was sent, so there is no header to read');

    return $messages[count($messages) - 1]->getOriginalMessage();
}

/* ------------------------------------------------------------- shipped blank */

it('ships with no Reply-To box filled in and sets no Reply-To header', function () {
    expect(app(MailSettings::class)->get('mail_reply_to'))->toBe('', 'the Reply-To box does not ship blank');
    expect(app(MailSettings::class)->replyToAddress())->toBe('');

    expect(rtSent()->getReplyTo())->toBe([], 'a Reply-To is being set on an install that configured none');
});

it('does not invite a reply while no Reply-To is configured', function () {
    $html = (string) (new OrderConfirmation(rtOrder()))->render();

    expect(str_contains($html, 'it reaches us'))
        ->toBeFalse('the footer invites a reply to an address nothing reads');

    // And the sentence that is true about why they got it stays either way.
    expect(str_contains($html, 'You are receiving this because an order was placed'))
        ->toBeTrue('the footer lost the one thing it could say');
});

/* ----------------------------------------------- once the owner sets one */

it('saves a Reply-To through the real Store → Mail screen and puts it on a sent message', function () {
    rtSaveThroughScreen(['mail_reply_to' => 'hello@kbeautybliss.com']);

    expect(app(MailSettings::class)->replyToAddress())->toBe('hello@kbeautybliss.com');

    $addresses = array_map(
        static fn ($a) => $a->getAddress(),
        rtSent()->getReplyTo()
    );

    expect($addresses)->toBe(['hello@kbeautybliss.com'], 'the screen saved an address the mailer never used');
});

it('applies a Reply-To saved after the mailer was already built', function () {
    /*
     * THE CASE refresh() EXISTS FOR, and the one a test that sends only once
     * cannot see. Laravel applies a global address at the moment it BUILDS a
     * mailer and then memoises that mailer, so an owner who sends something and
     * then saves the screen in the same process would go on sending with the
     * configuration from before the save. That is the shape of bug that makes a
     * settings screen look like it saved and did nothing.
     *
     * The first send below is what builds the mailer. Nothing is asserted about
     * it except that it carries no Reply-To, which is the state being escaped
     * from.
     */
    expect(rtSent()->getReplyTo())->toBe([], 'the fixture did not start from an unconfigured mailer');

    rtSaveThroughScreen(['mail_reply_to' => 'hello@kbeautybliss.com']);

    // NOT refreshed by this call: the save went through MailApiController, which
    // calls refresh() itself. If that purge does not reach the mailer the first
    // send built, this reads back an empty Reply-To.
    $addresses = array_map(static fn ($a) => $a->getAddress(), rtSent(false)->getReplyTo());

    expect($addresses)->toBe(
        ['hello@kbeautybliss.com'],
        'a mailer built before the save kept sending with the old Reply-To'
    );
});

it('invites a reply once, and only once, when the address is configured', function () {
    rtSaveThroughScreen(['mail_reply_to' => 'hello@kbeautybliss.com']);

    $html = (string) (new OrderConfirmation(rtOrder()))->render();

    expect(substr_count($html, 'it reaches us'))
        ->toBe(1, 'the invitation to reply is missing, or printed more than once');
});

it('keeps the invitation out of the merchant\'s own new-order alert', function () {
    // The alert goes to the person who packs the boxes. Telling him he may
    // reply to himself is the same nonsense as telling him an order was placed
    // using his email address, which that footer already declines to do.
    rtSaveThroughScreen(['mail_reply_to' => 'hello@kbeautybliss.com']);

    $html = (string) (new App\Mail\NewOrderAlert(rtOrder()))->render();

    expect(str_contains($html, 'it reaches us'))
        ->toBeFalse('the merchant alert invites the owner to reply to himself');
});

it('carries the invitation into every customer-facing order email, not only one of them', function () {
    rtSaveThroughScreen(['mail_reply_to' => 'hello@kbeautybliss.com']);

    $order = rtOrder();

    foreach ([
        'confirmation' => new OrderConfirmation($order),
        'dispatch' => new App\Mail\OrderStatusChanged($order, 'shipped'),
        'invoice' => new App\Mail\OrderInvoice($order),
    ] as $what => $mailable) {
        expect(str_contains((string) $mailable->render(), 'it reaches us'))
            ->toBeTrue("the {$what} email does not invite a reply although one would reach the shop");
    }
});

/* ------------------------------------------------------------ bad input */

it('refuses an address that is not an address rather than storing it', function () {
    // Dropped, not stored: a malformed Reply-To is a header the transport
    // refuses, and the footer would meanwhile be promising it works.
    app(MailSettings::class)->save(['mail_reply_to' => 'not-an-address']);
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(app(MailSettings::class)->get('mail_reply_to'))->toBe('');
    expect(rtSent()->getReplyTo())->toBe([]);
});

it('ignores a malformed address that reached the column some other way', function () {
    /*
     * Checked on the way OUT as well as on the way in. save() refuses a bad
     * value, but this row can also arrive from an older build, a hand-edited
     * database or a half-applied package — and what it feeds is a header on
     * every order email and a sentence promising the header works.
     */
    app(SettingsService::class)->set('mail_reply_to', 'nonsense');
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(app(MailSettings::class)->replyToAddress())->toBe('');

    expect(str_contains((string) (new OrderConfirmation(rtOrder()))->render(), 'it reaches us'))
        ->toBeFalse('the footer promises a reply reaches a mailbox that is not an address');
});

/* ------------------------------------------------------------- the screen */

it('offers the Reply-To box on Store → Mail and never echoes it as a secret', function () {
    rtSaveThroughScreen(['mail_reply_to' => 'hello@kbeautybliss.com']);

    expect(MailSettings::SCHEMA)->toHaveKey('mail_reply_to');
    expect(MailSettings::MAX_LENGTHS)->toHaveKey('mail_reply_to');

    $fields = test()->actingAs(rtAdmin(), 'admin')
        ->getJson('/admin-api/mail')
        ->assertOk()
        ->json('fields');

    $row = collect($fields)->firstWhere('key', 'mail_reply_to');

    expect($row)->not->toBeNull('the Reply-To box is not rendered on the screen');
    expect($row['type'])->toBe('text');
    expect($row['value'])->toBe('hello@kbeautybliss.com', 'the screen cannot show the owner what he saved');
});
