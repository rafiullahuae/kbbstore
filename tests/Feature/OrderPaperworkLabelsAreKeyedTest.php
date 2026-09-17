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

/**
 * The money-row labels the ORDER-RECEIVED PAGE prints, read back off the
 * rendered partial.
 *
 * Read off the HTML rather than from a helper, because there is no helper: the
 * screen's rows are built inside the Blade and the only honest way to ask what
 * it called them is to render it and look. The first <span> of each `.sumrow`
 * is the label, the second is the money.
 *
 * @return list<string>
 */
function fjScreenTotalLabels(\App\Models\Order $order): array
{
    $html = (string) view('partials.checkout.received-summary', ['order' => $order])->render();

    preg_match_all('#<div class="(sumrow[^"]*)">\s*<span>(.*?)</span>#s', $html, $m, PREG_SET_ORDER);

    $labels = [];

    foreach ($m as $row) {
        $labels[] = trim(html_entity_decode($row[2], ENT_QUOTES, 'UTF-8'));

        /*
         * STOP AT THE TOTAL. Anything below it is the "of which" VAT note,
         * which is deliberately NOT one of the rows that sum to the total and
         * deliberately NOT worded like the invoice's — it is the owner's
         * `vat_label` setting, the shopper's own second-person sentence from
         * the checkout page. The partial's header argues that split; this
         * helper is about the rows above it.
         */
        if (str_contains($row[1], 'tot')) {
            break;
        }
    }

    return $labels;
}

it('calls a row the same thing on the screen as on the receipt and the invoice', function () {
    /*
     * THE THIRD COPY. The receipt and the invoice were merged onto one key set
     * by the case above; resources/views/partials/checkout/received-summary.blade.php
     * was keyed to `store.checkout.*` and carried its own 'VAT at ' . $rate . '%'
     * besides, so the screen a customer pays on was a third list of the same
     * seven labels. It calls `email.totals.*` now and this is what holds it
     * there.
     *
     * WHY THE ARABIC BELOW IS DELIBERATELY DIFFERENT PER KEY SET. The old
     * `store.checkout.*` keys are published here too, with visibly different
     * Arabic. If the partial still read them, the screen would print those
     * strings and the comparison goes red — where publishing only the
     * `email.totals.*` side would leave a partial that fell back to English
     * looking like a partial that was never converted, which is a different
     * bug with the same symptom.
     *
     * THE TWO DOCUMENTED DIFFERENCES ARE ASSERTED, NOT MASKED. The screen has
     * no Subtotal row (removed at the owner's request), and its delivery row
     * appends the shipping method's own name after a middot — a name, not a
     * label. Both are stated here as the exact transformation from the
     * receipt's list, so a third difference appearing later cannot hide inside
     * a looser assertion.
     */
    ArabicShop::on();

    foreach ([
        'email.totals.discount_coupon' => 'خصم (:code)',
        'email.totals.delivery' => 'التوصيل',
        'email.totals.gift_wrapping' => 'تغليف الهدية',
        'email.totals.payment_fee' => 'رسوم :method',
        'email.totals.total' => 'الإجمالي',
        'email.totals.subtotal' => 'المجموع الفرعي',
        // The key set the partial used to read, with Arabic of its own.
        'store.checkout.discount' => 'خصم-قديم',
        'store.checkout.delivery' => 'توصيل-قديم',
        'store.checkout.gift_wrapping' => 'تغليف-قديم',
        'store.checkout.payment_fee' => 'رسوم-قديمة :method',
        'store.checkout.total' => 'إجمالي-قديم',
    ] as $key => $value) {
        ArabicShop::string($key, $value);
    }

    $order = fbOrderIn('ar');

    /*
     * EVERY ONE OF THESE IS RESOLVED INSIDE THE LOCALE, including the two
     * label lookups the expectation is built from. Asking for
     * __('email.totals.delivery') after the closure has returned asks the
     * English shop, and the filter and the suffix then match nothing — the
     * comparison still fails, but for the wrong reason and with a diff that
     * blames the partial.
     */
    [$receipt, $invoice, $screen, $expected] = OrderLocale::render($order, function () use ($order): array {
        $receipt = array_column((new \App\Services\Mail\OrderEmailPresenter)->present($order)['totals'], 'label');

        return [
            $receipt,
            array_column(app(\App\Services\Invoices\InvoiceDocument::class)->present($order)['totals'], 'label'),
            fjScreenTotalLabels($order),
            // The screen's list, expressed as the receipt's list and the two
            // stated differences — nothing else.
            array_values(array_map(
                static fn (string $label) => $label === __('email.totals.delivery')
                    ? $label . ' · ' . $order->shipping_method
                    : $label,
                array_filter($receipt, static fn (string $label) => $label !== __('email.totals.subtotal'))
            )),
        ];
    });

    expect($invoice)->toBe($receipt);
    expect($screen)->toBe($expected);

    // And it really is Arabic that was compared, from the merged key set.
    expect($screen)->toContain('الإجمالي')
        ->and(implode(' ', $screen))->toContain('خصم (GLOW10)')
        ->and(implode(' ', $screen))->toContain('التوصيل');

    foreach (['خصم-قديم', 'توصيل-قديم', 'تغليف-قديم', 'رسوم-قديمة', 'إجمالي-قديم'] as $orphan) {
        expect(str_contains(implode(' ', $screen), $orphan))
            ->toBeFalse("the screen still reads store.checkout.* for a money row: {$orphan}");
    }
});

it('prints the VAT row on screen with the same words the receipt uses', function () {
    /*
     * The one row the partial did not merely key to a different set — it built
     * 'VAT at ' . $vatRate . '%' out of a literal and a concatenation, so there
     * was nothing for a translator to reach at all, and Arabic could not put
     * the rate where Arabic puts it. It is `email.totals.vat_at_rate` now, the
     * same key and the same placeholder the receipt and the invoice use.
     */
    ArabicShop::on();
    ArabicShop::string('email.totals.vat_at_rate', 'ضريبة القيمة المضافة :rate٪');

    $order = fbOrderIn('ar');
    // An order that recorded an EXCLUSIVE 5% — tax charged on top, so the
    // figure is a row among the charges rather than a note under the total.
    $order->forceFill(['tax_basis' => 'exclusive', 'tax_rate' => 5.0, 'tax_total' => 2250])->save();

    $order = $order->fresh(['items']);

    $screen = OrderLocale::render($order, fn (): array => fjScreenTotalLabels($order));

    expect(implode(' ', $screen))->toContain('ضريبة القيمة المضافة 5٪')
        ->and(implode(' ', $screen))->not->toContain('VAT at');
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

/** A WORDING subject's `%1$s` / `%2$s` written the way the strings table writes them. */
function fjNamedPlaceholders(string $subject): string
{
    return str_replace(['%1$s', '%2$s'], [':store', ':number'], $subject);
}

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
        // The subjects, whose constants carry sprintf's numbered pair where the
        // key carries named placeholders — compared on the same footing, the
        // way the two amount sentences below already are.
        'email.order_status.shipped_subject' => fjNamedPlaceholders(OrderStatusChanged::WORDING['shipped'][0]),
        'email.order_status.cancelled_subject' => fjNamedPlaceholders(OrderStatusChanged::WORDING['cancelled'][0]),
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

it('changes exactly one English row on the order-received screen, and names it', function () {
    /*
     * THE DELIBERATE ENGLISH CHANGE, written down rather than left for somebody
     * to find in a diff.
     *
     * Joining the screen to `email.totals.*` moved six rows and changed the
     * wording of one: the discount row printed the bare coupon code where both
     * documents print "Discount (GLOW10)". A code on its own is not a label —
     * it says nothing about what the row is — and leaving the screen with its
     * own wording is what made three key sets defensible in the first place.
     *
     * StorefrontEnglishUnchangedTest does not cover this: it renders
     * /checkout/success with no order in the session, so the summary partial is
     * never included and the rows below are never drawn. This is that bar,
     * applied where the walk cannot reach.
     */
    $order = fbOrderIn('en');

    expect(fjScreenTotalLabels($order))->toBe([
        // Changed, and the only one.
        'Discount (GLOW10)',
        // Unchanged, to the byte, including the method's own name after the
        // middot and the absence of a Subtotal row.
        'Delivery · Standard delivery (1–3 working days)',
        'Gift wrapping',
        'Cash on delivery fee',
        'Total',
    ]);
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

/*
|------------------------------------------------------------------------------
| THE PRINTED INVOICE'S LANGUAGE (Lane FJ)
|------------------------------------------------------------------------------
|
| The previous lane called this one ambiguous on purpose and asked for a
| decision rather than a guess: Admin\InvoiceController renders in the browser
| of whoever pressed the button, which is the operator, but the sheet is the
| customer's invoice.
|
| DECIDED: the customer's. The emailed invoice for the same order already
| renders in the order's language, so a reprint in the operator's language is a
| second sheet, carrying the same invoice number, that reads differently from
| the one the customer already has. That is the failure, and an invoice is the
| worst document in the shop to have two of.
|
| AND ONLY THE INVOICE. The packing slip, the delivery note and the dispatch
| label are read inside the building or by a courier. They stay as they were,
| which the last case here pins so the decision cannot quietly spread.
*/

function fjPrintAdmin(): \App\Models\AdminUser
{
    return \App\Models\AdminUser::create([
        'name' => 'Print Owner',
        'email' => 'print-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

it('prints the invoice in the language the order was placed in', function () {
    \Tests\Support\InvoiceAdminRoutes::wire(app());

    ArabicShop::on();
    ArabicShop::string('email.totals.total', 'الإجمالي');
    ArabicShop::string('email.totals.delivery', 'التوصيل');
    ArabicShop::string('invoice.document.print_button', 'اطبع');

    $order = fbOrderIn('ar');

    $html = (string) test()->actingAs(fjPrintAdmin(), 'admin')
        ->get('/admin-api/orders/' . $order->id . '/invoice')
        ->assertOk()
        ->getContent();

    // The sheet, in the customer's language — the same strings the invoice
    // already in their inbox was built from.
    expect($html)->toContain('الإجمالي')
        ->and($html)->toContain('التوصيل')
        ->and($html)->not->toContain('>Total<');

    // And the element says what it is. A sheet of Arabic under lang="en"
    // dir="ltr" is a lie to every screen reader that reads it.
    expect($html)->toContain('<html lang="ar"');

    /*
     * The toolbar stays the OPERATOR'S. It is .no-print navigation for the
     * person at the screen, and the controller resolves its two strings before
     * it enters the order's locale. If that ever stops being true this is what
     * says so — and it is the assertion most likely to break, because the
     * obvious implementation (wrapping the whole view() call and returning the
     * View) gets it wrong in the other direction and renders the SHEET in
     * English while looking like it worked.
     */
    expect($html)->toContain('>Print<')
        ->and($html)->not->toContain('اطبع');
});

it('renders the same invoice in English for an order placed in English', function () {
    // The other half, and the one that matters to the shop as it ships: with
    // an English order nothing about this document moves.
    \Tests\Support\InvoiceAdminRoutes::wire(app());

    ArabicShop::on();
    ArabicShop::string('email.totals.total', 'الإجمالي');

    $order = fbOrderIn('en');

    $html = (string) test()->actingAs(fjPrintAdmin(), 'admin')
        ->get('/admin-api/orders/' . $order->id . '/invoice')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<html lang="en" dir="ltr">')
        ->and($html)->not->toContain('الإجمالي');
});

it('leaves the warehouse documents in the operator\'s language', function () {
    /*
     * The decision is about the ONE document that leaves the building addressed
     * to the customer. A picking list in a language the person picking does not
     * read is a worse document, not a better one, and a courier reads the label.
     */
    \Tests\Support\InvoiceAdminRoutes::wire(app());

    ArabicShop::on();
    ArabicShop::string('email.totals.total', 'الإجمالي');
    ArabicShop::string('invoice.packing_slip.title', 'قائمة التعبئة');

    $order = fbOrderIn('ar');
    $admin = fjPrintAdmin();

    foreach (['packing-slip', 'delivery-note', 'shipping-label'] as $document) {
        $html = (string) test()->actingAs($admin, 'admin')
            ->get('/admin-api/orders/' . $order->id . '/' . $document)
            ->assertOk()
            ->getContent();

        expect($html)->toContain('<html lang="en" dir="ltr">')
            ->and(str_contains($html, 'الإجمالي'))->toBeFalse($document . ' followed the order into Arabic')
            ->and(str_contains($html, 'قائمة التعبئة'))->toBeFalse($document . ' followed the order into Arabic');
    }
});

/*
|------------------------------------------------------------------------------
| THE DISPATCH AND CANCELLATION SUBJECT LINES (Lane FJ)
|------------------------------------------------------------------------------
|
| The headings and bodies were keyed; OrderStatusChanged::WORDING[...][0] — the
| subjects — were not. An Arabic customer therefore got an Arabic email under an
| English subject line, which is half a job in the most visible place: the
| subject is what shows in the inbox before anything is opened, and for a
| dispatch notice it is often the only part read at all.
|
| A SUBJECT IS NOT A BODY, so two things are checked that no body test checks:
| that it stays short enough to survive an inbox, and that BOTH placeholders
| come out the other side. A subject that lost its order number is a dispatch
| notice that does not say which order.
*/

/** The subject line for one status, as the mailer would send it. */
function fjSubjectFor(\App\Models\Order $order, string $status): string
{
    return (string) OrderLocale::render(
        $order,
        fn (): string => (new OrderStatusChanged($order, $status))->envelope()->subject
    );
}

it('says the subject line in the order\'s language too, not just the body', function () {
    ArabicShop::on();
    ArabicShop::string('email.order_status.shipped_subject', 'طلبك :number من :store في الطريق إليك');
    ArabicShop::string('email.order_status.cancelled_subject', 'تم إلغاء طلبك :number من :store');
    ArabicShop::string('email.order_status.shipped_heading', 'طلبك في الطريق إليك');

    app(\App\Services\SettingsService::class)->set('store_name', 'Aisha Beauty Co');
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    $order = fbOrderIn('ar');

    foreach (['shipped' => 'في الطريق إليك', 'cancelled' => 'تم إلغاء طلبك'] as $status => $arabic) {
        $subject = fjSubjectFor($order, $status);

        expect($subject)->toContain($arabic);

        /*
         * BOTH PLACEHOLDERS SURVIVED, and in the order THIS translation put
         * them in rather than the order English puts them in — which is the
         * whole reason the key uses named placeholders and not sprintf's
         * numbered pair. The Arabic above deliberately leads with the order
         * number; a positional substitution would have produced a subject
         * naming the shop where the number belongs.
         */
        expect($subject)->toContain($order->order_number)
            ->and($subject)->toContain('Aisha Beauty Co');

        expect(strpos($subject, $order->order_number))
            ->toBeLessThan((int) strpos($subject, 'Aisha Beauty Co'), 'the translation put :number first and the rendering did not');

        // And no placeholder was left standing where a value should be.
        expect(str_contains($subject, ':store'))->toBeFalse('the subject printed a placeholder at the customer');
        expect(str_contains($subject, ':number'))->toBeFalse('the subject printed a placeholder at the customer');
        expect(str_contains($subject, '%1$s') || str_contains($subject, '%2$s'))->toBeFalse();
    }
});

it('keeps both placeholders and a sane length in every published subject', function () {
    /*
     * The two failure modes a SUBJECT has that a body does not, checked over
     * the English source and over any Arabic the shop has published — because
     * an owner typing a translation into the console is the likeliest way to
     * lose a placeholder, and nothing else in the suite would notice.
     *
     * SIXTY CHARACTERS IS NOT A GUESS ABOUT TASTE. An inbox list shows roughly
     * that much of a subject before it truncates, and the store's own name is
     * inside the budget. Both English subjects sit under it today with the
     * shipped shop name substituted; the bound is here so that a sentence added
     * to one — the usual way a subject grows — is a failure rather than a
     * surprise in somebody's phone.
     */
    ArabicShop::on();
    ArabicShop::string('email.order_status.shipped_subject', 'طلبك :number من :store في الطريق إليك');
    ArabicShop::string('email.order_status.cancelled_subject', 'تم إلغاء طلبك :number من :store');

    $order = fbOrderIn('en');

    foreach (['shipped', 'cancelled'] as $status) {
        foreach (['en', 'ar'] as $locale) {
            $template = (string) __('email.order_status.' . $status . '_subject', [], $locale);

            // str_contains()->toBeTrue() rather than toContain($needle, $message):
            // toContain() is VARIADIC, so a message passed beside the needle is
            // a second needle. On the positive side that is a false failure; on
            // the `not` side it silently passes over a real leak, which is how
            // two gateway-secret sweeps in ApiSecurityTest were found asserting
            // nothing.
            expect(str_contains($template, ':store'))
                ->toBeTrue("{$status}/{$locale} lost the shop's name");
            expect(str_contains($template, ':number'))
                ->toBeTrue("{$status}/{$locale} lost the order number");

            // Rendered with real values, which is the length that matters.
            $order->forceFill(['locale' => $locale])->save();
            $rendered = fjSubjectFor($order->fresh(['items']), $status);

            expect(mb_strlen($rendered))->toBeLessThanOrEqual(
                60,
                "the {$locale} {$status} subject is {$rendered}"
            );
        }
    }
});

it('leaves the English subject exactly as it was', function () {
    // The acceptance bar, the same one the labels are held to: with Arabic off
    // the inbox shows the byte it showed.
    app(\App\Services\SettingsService::class)->set('store_name', 'K Beauty Bliss');
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    $order = fbOrderIn('en');

    expect(fjSubjectFor($order, 'shipped'))
        ->toBe('Your K Beauty Bliss order ' . $order->order_number . ' is on its way');
    expect(fjSubjectFor($order, 'cancelled'))
        ->toBe('Your K Beauty Bliss order ' . $order->order_number . ' has been cancelled');
});
