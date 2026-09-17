<?php

declare(strict_types=1);

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Services\Mail\OrderMailer;
use App\Services\Translation\InterfaceStrings;
use App\Support\OrderLocale;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ArabicShop;
use Tests\Support\EnglishRenderWalk;

/**
 * Lane FB — the money rows on a receipt, the dispatch and cancellation wording,
 * and the half of the bilingual email machinery that had never been connected.
 *
 * ── THE LABELS ──────────────────────────────────────────────────────────────
 *
 * Services\Mail\OrderEmailPresenter::totals() decides what each row of the
 * money breakdown is CALLED — Subtotal, Discount, Delivery, Gift wrapping, the
 * payment fee, VAT, Total — for all four order emails. Services\Invoices\InvoiceDocument::totals()
 * decides the same seven again, row for row, for the emailed invoice and the
 * printed one. They were two separate lists of English literals that happened
 * to agree.
 *
 * Both now call ONE key set, `email.totals.*`. That is the point of the second
 * case below: the customer has the receipt in their inbox and the invoice
 * beside it, and a translator who renames "Delivery" must rename it on both or
 * on neither. Two key sets would have restored the disagreement in a new place.
 *
 * ── THE HALF THAT WAS MISSING, AND MATTERED MORE THAN THE LABELS ────────────
 *
 * App\Support\OrderLocale was written so that an order placed in Arabic is
 * written to in Arabic afterwards. Its header says exactly why: the confirmation
 * is sent inside the request that placed the order and is in the right language
 * BY ACCIDENT, and nothing afterwards is — the dispatch email, the cancellation,
 * the refund notice and the invoice all go out from an admin click or a queue
 * worker, in a process whose locale is whatever it was last set to.
 *
 * MEASURED BEFORE TOUCHING ANYTHING: `OrderLocale::render` appeared in this
 * repository four times, and all four were in tests. listen() was wired, so
 * every order faithfully RECORDED its language; nothing ever read it back. An
 * Arabic shopper got an Arabic checkout and English paperwork forever.
 *
 * ── WHY $mailable->locale() WOULD NOT HAVE DONE ─────────────────────────────
 *
 * Laravel's own answer translates the VIEW. It is not enough here, and the
 * third case below is what says so rather than assuming it: OrderMail's
 * CONSTRUCTOR runs OrderEmailPresenter and keeps the finished strings, so every
 * label this lane just keyed is decided BEFORE a Mailable has a locale to set.
 * The build is therefore wrapped, not just the send — which is why it asserts
 * on the captured mailable's own array rather than on rendered HTML.
 */
function fbOrderIn(string $locale): Order
{
    $order = EnglishRenderWalk::documentOrder();

    $order->forceFill(['locale' => $locale])->save();

    return $order->fresh(['items']);
}

/** The seven row labels, as the receipt built them. */
function fbTotalLabels(Order $order): array
{
    return array_column((new \App\Services\Mail\OrderEmailPresenter)->present($order)['totals'], 'label');
}

it('says every row of the money breakdown in the language the order was placed in', function () {
    ArabicShop::on();

    ArabicShop::string('email.totals.subtotal', 'المجموع الفرعي');
    ArabicShop::string('email.totals.delivery', 'التوصيل');
    ArabicShop::string('email.totals.gift_wrapping', 'تغليف الهدية');
    ArabicShop::string('email.totals.discount_coupon', 'خصم (:code)');
    ArabicShop::string('email.totals.payment_fee', 'رسوم :method');
    ArabicShop::string('email.totals.total', 'الإجمالي');

    $order = fbOrderIn('ar');

    $html = OrderLocale::render($order, fn (): string => (string) (new OrderConfirmation($order))->render());

    expect($html)
        ->toContain('المجموع الفرعي')
        ->toContain('التوصيل')
        ->toContain('تغليف الهدية')
        ->toContain('الإجمالي')
        // The coupon CODE and the payment METHOD are identifiers and the
        // owner's own wording; only the words around them are translated.
        ->toContain('خصم (GLOW10)')
        ->toContain('رسوم Cash on delivery')
        ->and($html)->not->toContain('>Subtotal<')
        ->and($html)->not->toContain('Gift wrapping')
        ->and($html)->not->toContain('Discount (GLOW10)');
});

it('calls a row the same thing on the invoice as it does on the receipt', function () {
    /*
     * ONE KEY SET, TWO DOCUMENTS. Before this the two lists were separate
     * English literals that agreed by coincidence; translating one and not the
     * other would have put the customer's receipt and their invoice into
     * disagreement about what a line is called, in a language neither the owner
     * nor this test suite reads.
     */
    ArabicShop::on();

    ArabicShop::string('email.totals.subtotal', 'المجموع الفرعي');
    ArabicShop::string('email.totals.delivery', 'التوصيل');
    ArabicShop::string('email.totals.total', 'الإجمالي');

    $order = fbOrderIn('ar');

    [$receipt, $invoice] = OrderLocale::render($order, fn (): array => [
        array_column((new \App\Services\Mail\OrderEmailPresenter)->present($order)['totals'], 'label'),
        array_column(app(\App\Services\Invoices\InvoiceDocument::class)->present($order)['totals'], 'label'),
    ]);

    expect($invoice)->toBe($receipt)
        ->and($receipt)->toContain('المجموع الفرعي')
        ->and($receipt)->toContain('التوصيل')
        ->and($receipt)->toContain('الإجمالي');
});

it('sends a later email in the order\'s language without anybody remembering to ask', function () {
    /*
     * THE CASE THIS LANE EXISTS FOR. A dispatch email, sent from a process that
     * is in English and has no memory of the request that took the order —
     * which is every queue worker and every admin click. Nothing here calls
     * OrderLocale: OrderMailer does it, or the customer gets English.
     *
     * Asserted on the CAPTURED MAILABLE'S OWN ARRAY rather than on rendered
     * HTML, and deliberately: $order['totals'] is filled in OrderMail's
     * constructor, so it is the half of the email that $mailable->locale()
     * cannot reach. If the wrapper were around the render alone this would
     * still read English.
     */
    ArabicShop::on();

    ArabicShop::string('email.totals.subtotal', 'المجموع الفرعي');
    ArabicShop::string('email.totals.total', 'الإجمالي');

    $order = fbOrderIn('ar');

    Mail::fake();

    app()->setLocale('en');

    app(OrderMailer::class)->statusChanged($order, 'shipped');

    Mail::assertSent(OrderStatusChanged::class, function (OrderStatusChanged $mail): bool {
        $labels = array_column($mail->order['totals'], 'label');

        return in_array('المجموع الفرعي', $labels, true)
            && in_array('الإجمالي', $labels, true)
            && ! in_array('Subtotal', $labels, true);
    });

    // And the process is handed back the language it had. Without the `finally`
    // inside OrderLocale::render(), one Arabic order would leave a queue worker
    // set to Arabic for every job behind it.
    expect(app()->getLocale())->toBe('en');
});

it('does not put the shop\'s own new-order alert into the customer\'s language', function () {
    /*
     * THE ONE EMAIL THAT IS NOT THE CUSTOMER'S. NewOrderAlert goes to the
     * merchant, and an Arabic order must not decide what language the OWNER is
     * written to in — he may not read it. This is why the wrapper is a flag on
     * send() rather than something applied to every order email.
     */
    ArabicShop::on();

    ArabicShop::string('email.totals.subtotal', 'المجموع الفرعي');

    $order = fbOrderIn('ar');

    // The alert goes nowhere unless the shop has an address to send it to, and
    // send() skips a blank recipient before it ever builds the mailable.
    \App\Models\Setting::query()->updateOrCreate(
        ['key' => 'mail_merchant_address'],
        ['value' => 'owner@kbeautybliss.test', 'autoload' => true]
    );
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    app(\App\Services\SettingsService::class)->flush();

    Mail::fake();

    app()->setLocale('en');

    app(OrderMailer::class)->placed($order);

    Mail::assertSent(NewOrderAlert::class, function (NewOrderAlert $mail): bool {
        $labels = array_column($mail->order['totals'], 'label');

        return in_array('Subtotal', $labels, true)
            && ! in_array('المجموع الفرعي', $labels, true);
    });

    // The customer's copy of the same checkout went out in Arabic.
    Mail::assertSent(OrderConfirmation::class, function (OrderConfirmation $mail): bool {
        return in_array('المجموع الفرعي', array_column($mail->order['totals'], 'label'), true);
    });
});

it('says the dispatch and cancellation wording in the customer\'s language', function () {
    ArabicShop::on();

    ArabicShop::string('email.order_status.shipped_heading', 'طلبك في الطريق');
    ArabicShop::string('email.order_status.shipped_body', 'غادر طلبك مستودعنا.');
    ArabicShop::string('email.order_status.cancelled_heading', 'تم إلغاء طلبك');
    ArabicShop::string('email.order_status.cancelled_body', 'أُلغي هذا الطلب.');

    $order = fbOrderIn('ar');

    foreach (['shipped' => ['طلبك في الطريق', 'غادر طلبك مستودعنا.'], 'cancelled' => ['تم إلغاء طلبك', 'أُلغي هذا الطلب.']] as $status => [$heading, $body]) {
        $html = OrderLocale::render(
            $order,
            fn (): string => (string) (new OrderStatusChanged($order, $status))->render()
        );

        expect($html)->toContain($heading)
            ->and($html)->toContain($body)
            ->and($html)->not->toContain(OrderStatusChanged::WORDING[$status][1])
            ->and($html)->not->toContain(OrderStatusChanged::WORDING[$status][2]);
    }
});

it('tells a customer abroad the same thing in their own language, not an Arabic heading over an English paragraph', function () {
    /*
     * bodyFor() picks one of THREE dispatch sentences by where the parcel is
     * going. Keying only the default would have been worse than leaving the
     * email alone: a Gulf customer would have read an Arabic heading over an
     * English paragraph. This is the branch that would have been missed.
     */
    ArabicShop::on();

    ArabicShop::string('email.order_status.shipped_heading', 'طلبك في الطريق');
    ArabicShop::string('email.order_status.shipped_abroad', 'الشحنات خارج الإمارات تستغرق وقتاً أطول.');

    $order = fbOrderIn('ar');

    $address = $order->shipping_address;
    $address['country'] = 'SA';
    $order->forceFill(['shipping_address' => $address])->save();

    $html = OrderLocale::render(
        $order->fresh(['items']),
        fn (): string => (string) (new OrderStatusChanged($order->fresh(['items']), 'shipped'))->render()
    );

    expect($html)->toContain('الشحنات خارج الإمارات تستغرق وقتاً أطول.')
        ->and($html)->not->toContain('Deliveries outside the UAE take longer');
});

it('keeps an English source in the strings table for every sentence the mail constants carry', function () {
    /*
     * THE DRIFT GUARD, and the reason OrderStatusChanged's constants are not
     * dead code now that nothing renders them. WORDING is still what handles()
     * asks which statuses are worth an email at all, and three test files read
     * it by name; the private constants still hold the argument for why each
     * sentence says what it says. What they no longer do is reach a customer.
     *
     * A second English source has exactly one failure mode and it is silent:
     * the sentence changes in one file, and the Arabic goes on being a
     * translation of the sentence that used to be there.
     */
    $reflected = new ReflectionClass(OrderStatusChanged::class);

    $expected = [
        'email.order_status.shipped_heading' => OrderStatusChanged::WORDING['shipped'][1],
        'email.order_status.shipped_body' => OrderStatusChanged::WORDING['shipped'][2],
        'email.order_status.cancelled_heading' => OrderStatusChanged::WORDING['cancelled'][1],
        'email.order_status.cancelled_body' => OrderStatusChanged::WORDING['cancelled'][2],
        'email.order_status.shipped_dispatched' => $reflected->getConstant('SHIPPED_DISPATCHED'),
        'email.order_status.shipped_abroad' => $reflected->getConstant('SHIPPED_ABROAD'),
        'email.order_status.cancelled_nothing_taken' => $reflected->getConstant('CANCELLED_NOTHING_TAKEN'),
        // These two carried sprintf's %s and now carry a named placeholder, so
        // the comparison is made on the same footing.
        'email.order_status.cancelled_refunded' => str_replace('%s', ':amount', (string) $reflected->getConstant('CANCELLED_REFUNDED')),
        'email.order_status.cancelled_unrefunded' => str_replace('%s', ':amount', (string) $reflected->getConstant('CANCELLED_UNREFUNDED')),
    ];

    $missing = [];

    foreach ($expected as $key => $english) {
        if (InterfaceStrings::english($key) !== $english) {
            $missing[$key] = (string) $english;
        }
    }

    expect($missing)->toBe([], sprintf(
        "A sentence in OrderStatusChanged and its strings-table entry have stopped agreeing:\n\n%s",
        implode("\n", array_map(
            fn (string $k, string $v): string => "  {$k} => \"{$v}\"",
            array_keys($missing),
            $missing
        ))
    ));

    // And the branch that has no constant of its own is still the dispatched
    // half, rather than having quietly acquired a second wording.
    expect($reflected->getConstant('SHIPPED_UNKNOWN'))->toBe($reflected->getConstant('SHIPPED_DISPATCHED'));
});

it('changes no English on either document', function () {
    /*
     * The acceptance bar for a conversion: with Arabic off, every row is the
     * byte it was. Asserted against the literals the two methods used to carry,
     * so this fails on a misspelt key — which renders the key itself and is the
     * most likely way to get this wrong.
     */
    $order = fbOrderIn('en');

    $receipt = fbTotalLabels($order);
    $invoice = array_column(app(\App\Services\Invoices\InvoiceDocument::class)->present($order)['totals'], 'label');

    $wanted = ['Subtotal', 'Discount (GLOW10)', 'Delivery', 'Gift wrapping', 'Cash on delivery fee', 'Total'];

    expect($receipt)->toBe($wanted)
        ->and($invoice)->toBe($wanted);
});
