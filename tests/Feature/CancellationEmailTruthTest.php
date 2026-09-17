<?php

declare(strict_types=1);

/**
 * The cancellation email says what this order's rows say — Lane CX.
 *
 * ── WHAT IT USED TO SAY ─────────────────────────────────────────────────────
 *
 * OrderStatusChanged::WORDING['cancelled'] carried a second sentence telling a
 * customer who had already paid that the money was on its way back to them by
 * the method they paid with, and that a separate email would follow when it had
 * been sent. Deliberately not quoted here: this file greps the application for
 * that sentence, and a guard that reads its own explanatory comment as code is a
 * mistake this repository has already made three times.
 *
 * Nothing behind any of it. Cancelling an order in this application starts no
 * refund: `orders.status` is moved to `cancelled` from three places and not one
 * of them writes a `refunds` row or calls a gateway. PaymentRefunder is a
 * separate action an operator takes from a different form, and the promised
 * follow-up email — OrderRefunded — is driven by a refund row settling, so for
 * an order nobody refunded it never arrives. The customer waits, and then writes
 * to the shop.
 *
 * ── WHAT IT SAYS NOW ────────────────────────────────────────────────────────
 *
 * Only what the order's own rows record, which is different in three cases and
 * is therefore said in three ways rather than in one sentence vague enough to
 * cover all of them. The two facts come from the same two methods PaymentRefunder
 * decides a refund ceiling with, so this email and the refund screen cannot
 * disagree about whether an order was paid:
 *
 *   refundedFils() > 0                 a refund IS on the books; say its amount
 *   capturedFils() === 0               nothing was ever taken; say so
 *   captured, nothing refunded         say the amount and the absence, then
 *                                      print whatever the owner wrote in
 *                                      `mail_cancelled_refund_note`
 *
 * That setting ships blank on purpose. No refund policy is written down anywhere
 * in this shop, and a default invented here would be the same untruth in a
 * different hand.
 *
 * Every case is checked in BOTH parts. The HTML and the text are separate
 * templates and a fix applied to one of them is half a fix.
 *
 * Pest note: `toContain` reads a second argument as another needle, not as a
 * message, so anything that wants to explain itself is written
 * `expect(str_contains(...))->toBeTrue('...')`.
 */

use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Setting;
use App\Services\Mail\OrderEmailPresenter;
use App\Support\Money;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;

/*
 * RECEIPT WIDTH IS THE ORDER'S, NOT THE CURRENCY'S MAXIMUM — Lane FA.
 *
 * These assertions have always meant "printed the way this shop prints a
 * receipt figure", and they expressed it by calling the presenter's own
 * helper. That helper's default is still Money::minorExponent(); what changed
 * is that the receipt no longer uses the default. Under the whole-dirham
 * policy a receipt prints at the narrowest width that states ALL of its
 * figures exactly (Money::receiptDecimals), so a whole-dirham order reads
 * "AED 235" and an order carrying fils still reads "AED 89.80".
 *
 * So the assertions pass the width the receipt actually used. What is asserted
 * is unchanged — the email prints this exact amount — and it still fails if the
 * email prints a rounded or a widened figure that is not the money charged.
 */

/**
 * Settings memoise in a process-level static as well as in the cache
 * (CLAUDE.md). One test here writes `mail_cancelled_refund_note`, and without
 * this it would outlive its own test and be read by whatever ran next.
 */
beforeEach(function () {
    PaymentProvider::query()->delete();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/** An order on the books: one line, UAE delivery, cash on delivery, AED 235.00. */
function cancelOrder(array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'KBB-CX-' . uniqid(),
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 1500,
        'gift_fee' => 0,
        'tax_total' => 0,
        'total' => 23500,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner', 'brand' => 'Haruharu', 'sku' => 'HH-RT-150',
        'quantity' => 1, 'unit_price' => 20000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    return $order->fresh('items');
}

/**
 * Cancel it, and hand back both parts of the one email that went out.
 *
 * THE TEXT PART COMES BACK WITH ITS WHITESPACE COLLAPSED, because
 * emails/order-status-text.blade.php wraps the body at 72 columns — a text/plain
 * part has no reflow, so the wrap is correct and deliberate. A sentence asserted
 * as one string would fail on the newline the wrap put in the middle of it,
 * which says nothing about what the email tells the customer. The HTML part is
 * returned exactly as it renders.
 *
 * @return array{0:string,1:string}  [html, flattened text]
 */
function cancelParts(Order $order): array
{
    $order->update(['status' => 'cancelled']);

    $html = '';
    $text = '';

    Mail::assertSent(OrderStatusChanged::class, function ($mail) use (&$html, &$text) {
        $html = (string) $mail->render();
        $text = (string) view('emails.order-status-text', $mail->buildViewData())->render();

        return true;
    });

    return [$html, trim((string) preg_replace('/\s+/', ' ', $text))];
}

/* -------------------------------------------- 1. nothing was ever taken -- */

it('tells a cash customer nothing was taken rather than that a refund is coming', function () {
    Mail::fake();

    // Cash on delivery: CashOnDelivery::start() leaves `paid_at` null on
    // purpose, and a cancelled order is never delivered, so no money ever
    // changed hands. capturedFils() answers 0.
    [$html, $text] = cancelParts(cancelOrder());

    expect(str_contains($html, 'Our records show no payment taken on this order, so there is nothing to refund.'))
        ->toBeTrue('The cancellation email does not say that nothing was taken.');

    expect(str_contains($text, 'no payment taken on this order'))
        ->toBeTrue('The plain-text cancellation email does not say that nothing was taken.');

    // And it never states or implies that money is coming back.
    expect(str_contains($html, 'refund is on its way'))->toBeFalse('a refund nobody started is described as in flight')
        ->and(str_contains($text, 'refund is on its way'))->toBeFalse('the text part describes a refund nobody started as in flight');

    // The half that was always true is still said.
    expect(str_contains($html, 'This order has been cancelled and nothing further will be sent.'))
        ->toBeTrue('The email no longer says the order is cancelled.');
});

/* ---------------------------------- 2. money was taken, nothing refunded -- */

it('states the amount paid and that no refund has been recorded, and promises nothing', function () {
    Mail::fake();

    // Paid, by whatever means. capturedFils() falls back to the order total for
    // an order confirmed but not captured through the capture lane, which is
    // every order this store has confirmed to date.
    [$html, $text] = cancelParts(cancelOrder(['paid_at' => now()]));

    $amount = OrderEmailPresenter::plain(23500, Money::receiptDecimals(23500));

    expect(str_contains($html, 'Our records show ' . $amount . ' paid on this order and no refund recorded against it yet.'))
        ->toBeTrue('The cancellation email does not state what was paid and that no refund exists.');

    expect(str_contains($text, 'no refund recorded against it yet'))
        ->toBeTrue('The plain-text cancellation email does not state that no refund exists.');

    // Nothing is claimed about what happens next, because nothing has happened
    // and no policy is recorded anywhere in this application.
    expect(str_contains($html, 'on its way'))->toBeFalse('the email still describes money as in flight')
        ->and(str_contains($html, 'separate email'))->toBeFalse('the email still promises a follow-up nothing will send')
        ->and(str_contains($html, 'working days'))->toBeFalse('the email invents a refund timescale');
});

it('prints the owner note under the unrefunded sentence, and only there', function () {
    Mail::fake();

    app(SettingsService::class)->set(
        OrderStatusChanged::CANCELLED_REFUND_NOTE,
        'We send refunds by bank transfer within five working days — reply with your IBAN.'
    );
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    [$html, $text] = cancelParts(cancelOrder(['paid_at' => now()]));

    expect(str_contains($html, 'reply with your IBAN'))
        ->toBeTrue("The owner's own sentence is not printed on a cancelled paid order.");

    expect(str_contains($text, 'reply with your IBAN'))
        ->toBeTrue("The owner's own sentence is missing from the plain-text part.");

    // The same note must NOT appear on an order where there is no open question
    // about money. It answers "what happens about the money you paid"; on an
    // order nothing was taken on, that is a question nobody asked.
    [$unpaidHtml] = cancelParts(cancelOrder(['order_number' => 'KBB-CX-UNPAID-' . uniqid()]));

    expect(str_contains($unpaidHtml, 'reply with your IBAN'))
        ->toBeFalse('A customer who paid nothing is told how their refund will reach them.');
});

it('escapes the owner note rather than rendering it as markup', function () {
    Mail::fake();

    app(SettingsService::class)->set(OrderStatusChanged::CANCELLED_REFUND_NOTE, 'Ask for <b>Aisha</b> & quote this number.');
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    [$html, $text] = cancelParts(cancelOrder(['paid_at' => now()]));

    // Operator input rendered into an HTML email. The body is printed through
    // Blade's {{ }}, which is what keeps the money figure in this sentence
    // plain rather than Money::format() markup — see OrderStatusChanged.
    expect(str_contains($html, '<b>Aisha</b>'))->toBeFalse('the owner note reaches the HTML part as live markup');
    expect(str_contains($html, '&lt;b&gt;Aisha&lt;/b&gt;'))->toBeTrue('the owner note was not escaped into the HTML part');

    // The text part is text/plain: there is no markup context, so it is NOT
    // escaped there. An & escaped in a text body is a corruption, not a guard.
    expect(str_contains($text, 'Aisha</b> & quote'))->toBeTrue('the plain-text part escaped a string with nothing to escape into');
});

/* ------------------------------------------ 3. a refund really is on the books */

it('states the refund that has actually been recorded against the order', function () {
    Mail::fake();

    $order = cancelOrder(['paid_at' => now()]);

    $order->refunds()->create([
        'amount' => 10000,
        'status' => 'succeeded',
        'provider' => 'cod',
        'refunded_by' => 'Admin',
    ]);

    [$html, $text] = cancelParts($order->fresh('items'));

    expect(str_contains($html, 'A refund of ' . OrderEmailPresenter::plain(10000, Money::receiptDecimals(10000)) . ' has been recorded against it.'))
        ->toBeTrue('A recorded refund is not named in the cancellation email.');

    expect(str_contains($text, 'A refund of ' . OrderEmailPresenter::plain(10000, Money::receiptDecimals(10000))))
        ->toBeTrue('A recorded refund is not named in the plain-text cancellation email.');

    // It is the REFUND's amount, never the order total. On a partial refund
    // those differ, and printing the total would overstate what is coming back.
    expect(str_contains($html, 'A refund of ' . OrderEmailPresenter::plain(23500, Money::receiptDecimals(23500))))
        ->toBeFalse('The email describes the order total as the refund.');
});

it('counts a pending refund as recorded, because pending money is claimed money', function () {
    Mail::fake();

    // PaymentRefunder writes the row PENDING inside the transaction that
    // checked the ceiling, and counts pending rows against it. A refund in
    // flight has been started; saying so is a fact.
    $order = cancelOrder(['paid_at' => now()]);

    $order->refunds()->create([
        'amount' => 23500, 'status' => 'pending', 'provider' => 'stripe', 'refunded_by' => 'Admin',
    ]);

    [$html] = cancelParts($order->fresh('items'));

    expect(str_contains($html, 'A refund of ' . OrderEmailPresenter::plain(23500, Money::receiptDecimals(23500)) . ' has been recorded against it.'))
        ->toBeTrue('A pending refund is treated as though no refund existed.');
});

it('ignores a failed refund, which is evidence money did not move', function () {
    Mail::fake();

    $order = cancelOrder(['paid_at' => now()]);

    // PaymentRefunder::settle() writes `failed` and releases the key. The row
    // stays as the audit record of an attempt that did not work; it is not a
    // refund and must not be described as one.
    $order->refunds()->create([
        'amount' => 23500, 'status' => 'failed', 'provider' => 'stripe',
        'refunded_by' => 'Admin', 'failure_code' => 'card_declined',
    ]);

    [$html] = cancelParts($order->fresh('items'));

    expect(str_contains($html, 'has been recorded against it'))
        ->toBeFalse('A failed refund attempt is reported to the customer as a refund.');

    expect(str_contains($html, 'no refund recorded against it yet'))
        ->toBeTrue('A failed refund attempt left the email saying nothing about the money.');
});

/* ----------------------------------------------- the dispatch email is untouched */

it('leaves the dispatch email alone', function () {
    Mail::fake();

    $order = cancelOrder();
    $order->update(['status' => 'shipped']);

    $html = '';

    Mail::assertSent(OrderStatusChanged::class, function ($mail) use (&$html) {
        $html = (string) $mail->render();

        return true;
    });

    expect(str_contains($html, 'Your order has left us and is with the courier.'))
        ->toBeTrue('The dispatch email lost its own wording.');

    // And it says nothing about refunds, which is a cancellation's business.
    expect(str_contains($html, 'refund'))->toBeFalse('The dispatch email talks about refunds.');
});

/* ----------------------------------------------------- the owner's own box -- */

/**
 * The setting is reachable from the screen, not only from a test.
 *
 * MailSettings' own header records the failure this closes twice over: a
 * settings screen that accepts a value and writes nothing is how a merchant
 * finds out weeks later that a box they filled in did nothing. Store → Mail
 * renders whatever SCHEMA declares and MailApiController validates the posted
 * keys against that same constant, so a key added to SCHEMA and nowhere else
 * ought to work end to end — "ought to" being exactly the kind of claim this
 * lane exists to stop taking on trust.
 */
it('lets the owner write the note on Store -> Mail and read it back', function () {
    $screen = app(\App\Http\Controllers\Admin\MailApiController::class);

    // On the screen at all: the field list the admin renders from.
    $shown = $screen->show()->getData(true);

    expect(array_column($shown['fields'] ?? [], 'key'))
        ->toContain(OrderStatusChanged::CANCELLED_REFUND_NOTE);

    $screen->save(new Illuminate\Http\Request([
        'settings' => [OrderStatusChanged::CANCELLED_REFUND_NOTE => 'Refunds go back by bank transfer.'],
    ]));

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(app(\App\Services\Mail\MailSettings::class)->get(OrderStatusChanged::CANCELLED_REFUND_NOTE))
        ->toBe('Refunds go back by bank transfer.', 'the screen accepted the note and stored nothing');

    Mail::fake();

    [$html] = cancelParts(cancelOrder(['paid_at' => now()]));

    expect(str_contains($html, 'Refunds go back by bank transfer.'))
        ->toBeTrue('the note was saved from the screen and never reached the email');
});

/* ------------------------------------------------------------------- guard */

/**
 * The sentence is gone from the application, not merely from one template.
 *
 * COMMENTS AND STRINGS ARE NOT CODE, but for this guard they are exactly what
 * matters: the defect was a sentence in a PHP constant and in Blade prose, so
 * the whole file is searched. What is therefore avoided instead is quoting the
 * removed sentence anywhere inside the files this walks — hence the assembly
 * below, and hence the header of this file describing it rather than printing
 * it.
 *
 * THE PREVIEWS ARE WALKED TOO, and that is not belt and braces. docs/email-
 * previews is written by OrderEmailPreviewsTest and committed, so it is what a
 * reviewer actually reads to see what a customer gets — and a stale one is how a
 * withdrawn sentence goes on being reviewed as current. One was committed during
 * this lane's own work, written by a suite run that overlapped a mutation
 * experiment in another process. The previews are generated, so this catches a
 * stale file rather than a bad edit; it is the same guard either way.
 */
it('no longer carries the promise anywhere a customer could be sent it', function () {
    $needles = [
        'the refund is on' . ' its way back to you',
        'you will get a sepa' . 'rate email when it has been sent',
    ];

    $files = array_merge(
        glob(app_path('Mail/*.php')) ?: [],
        glob(resource_path('views/emails/*.blade.php')) ?: [],
        glob(resource_path('views/emails/partials/*.blade.php')) ?: [],
        glob(base_path('docs/email-previews/*')) ?: [],
    );

    expect($files)->not->toBeEmpty('the guard found no files to walk, so it proves nothing');

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        foreach ($needles as $needle) {
            expect(str_contains($contents, $needle))
                ->toBeFalse('The withdrawn refund promise is back in ' . basename($file) . '.');
        }
    }
});
