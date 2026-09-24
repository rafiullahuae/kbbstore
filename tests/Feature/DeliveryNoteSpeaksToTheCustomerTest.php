<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use Tests\Support\InvoiceAdminRoutes;

/**
 * The delivery note goes IN THE PARCEL, and it was written in the wrong
 * language.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * Four printed documents come out of Admin\InvoiceController, and until now
 * exactly one of them — the invoice — followed the language the order was
 * placed in. The other three rendered in whatever language the browser that
 * pressed the button happened to be in, which is the operator's, on the stated
 * grounds that "a packing slip is a picking list, a delivery note is a handover
 * record and a dispatch label is an address on a box — all three are read
 * inside the building or by a courier, by people who did not place the order."
 *
 * That is true of two of them and it was never true of the delivery note. That
 * sheet travels INSIDE the parcel and is opened, read and SIGNED by the person
 * who ordered — resources/views/invoices/delivery-note.blade.php has said so
 * since it was written, that it "is read at the door, facing the customer, so
 * it leads with what is in the parcel in the customer's own words".
 *
 * So an Arabic shopper who browsed /ar/shop, bought under Arabic product names,
 * got an Arabic confirmation email and an Arabic invoice opened the box on an
 * English handover sheet, with English product names on it, and was asked to
 * sign it.
 *
 * ── THE TEST THAT SEPARATES THE TWO HALVES ──────────────────────────────────
 *
 * The fix is two halves and they fail differently, so they are asserted
 * separately:
 *
 *   FURNITURE — Admin\InvoiceController::deliveryNote() renders inside
 *     OrderLocale::render(), so the headings, the labels and the signature
 *     block follow the order, and invoices/document.blade.php resolves
 *     `lang`/`dir` from the locale it is actually rendering in.
 *   LINE NAMES — sheet-delivery-note.blade.php reads `nameForCustomer` rather
 *     than `name`, so each line is called what the shopper called it.
 *
 * ── AND THE HALF THAT MUST NOT MOVE ─────────────────────────────────────────
 *
 * The PACKING SLIP is the control in almost every case below. It is a picking
 * list read at the bench, facing the shelves, by somebody who works here, and
 * `order_items.name` is deliberately the operator's language AND its exact
 * value. A fix aimed only at the customer is one that quietly takes the
 * warehouse's document with it, so every case that moves the delivery note
 * checks the slip did not move.
 *
 * The distinction is NOT "does the sheet leave the building" — the dispatch
 * label leaves too, on the outside of the box — it is WHO READS IT.
 *
 * ── MUTATION NOTES ──────────────────────────────────────────────────────────
 *
 *   - Drop the OrderLocale::render() wrapper in deliveryNote() (return the view
 *     directly, as it did) and every FURNITURE case goes red: lang="en" on an
 *     Arabic order. The line-name cases stay green, which is the point of
 *     splitting them.
 *   - Point sheet-delivery-note.blade.php back at $item['name'] and the LINE
 *     NAME cases go red while the furniture cases stay green.
 *   - Put BulkDocumentController::sheets() back on allocatesInvoiceNumbers()
 *     and 'it prints each delivery note in its own order language inside one
 *     mixed batch' goes red — the batch printing customer product names, because
 *     the partial is shared, inside English headings, because the wrapper is
 *     not. That is the drift sheet-delivery-note.blade.php's own header exists
 *     to prevent, and it fails nothing else.
 */

/* ------------------------------------------------------------------ fixtures */

function dncAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Delivery Note Owner',
        'email' => 'dnc-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** Arabic live, and the mirrored layout in whichever state is asked for. */
function dncLanguage(bool $arabic, bool $mirrored = false): void
{
    $settings = app(SettingsService::class);
    $settings->set(Locale::SETTING_ENABLED, $arabic ? '1' : '0');
    $settings->set(Locale::SETTING_RTL, $mirrored ? '1' : '0');
    $settings->flush();

    Setting::flushMap();
    SettingsService::forgetMemo();
    TranslationStore::flush();
}

/**
 * The Arabic wording of the one furniture string these cases read back.
 *
 * A furniture string, not a product name: the two halves of this fix are
 * different code, and asserting a heading proves the DOCUMENT changed language
 * rather than that one column was read.
 */
const DNC_DOCTYPE_AR = 'إشعار التسليم';

function dncTranslateFurniture(): void
{
    TranslationStore::put(
        'ar', 'ui', 0, 'invoice.delivery_note.doctype', DNC_DOCTYPE_AR,
        Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL,
    );

    TranslationStore::flush();
}

/** A product with an English name and an Arabic one. */
function dncProduct(string $english = 'Rice Daily Moisturizing Toner 150ml', string $arabic = 'تونر الأرز المرطب اليومي'): Product
{
    static $n = 0;
    $n++;

    $product = Product::create([
        'name' => $english,
        'slug' => 'dnc-' . $n . '-' . uniqid(),
        'price' => 19900,
        'status' => 'published',
    ]);

    TranslationStore::put(
        'ar', 'products', $product->id, 'name', $arabic,
        Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL,
    );

    TranslationStore::flush();

    return $product;
}

/** One order in one language, with a line pointing at a real product. */
function dncOrder(string $locale, ?Product $product = null): Order
{
    static $n = 0;
    $n++;

    $product ??= dncProduct();

    $order = Order::create([
        'order_number' => 'KBB-DNC-' . $n,
        'email' => 'dnc-' . $n . '@example.test',
        'phone' => '+971 50 123 4567',
        'status' => 'processing',
        'currency' => 'AED',
        'locale' => $locale,
        'billing_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE',
        ],
        'shipping_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE',
        ],
        'subtotal' => 19900,
        'total' => 19900,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'name' => $product->name,
        'brand' => 'Haruharu Wonder',
        'sku' => 'HH-RT-150',
        'quantity' => 2,
        'unit_price' => 19900,
        'subtotal' => 39800,
        'total' => 39800,
    ]);

    return $order->fresh('items');
}

/** One printed document for an order, as the operator would fetch it. */
function dncDocument(Order $order, string $type): string
{
    InvoiceAdminRoutes::wire(app());

    return test()->actingAs(dncAdmin(), 'admin')
        ->get('/admin-api/orders/' . $order->id . '/' . $type)
        ->assertOk()
        ->getContent();
}

/* --------------------------------------------- half one: the furniture moves */

it('prints the handover sheet in the language the order was placed in', function () {
    dncLanguage(arabic: true);
    dncTranslateFurniture();

    $html = dncDocument(dncOrder('ar'), 'delivery-note');

    // The document says what it is, rather than declaring English over Arabic
    // words — a lie to every screen reader and hyphenator that reads it.
    expect($html)->toContain('<html lang="ar"')
        // And the furniture really is translated, not merely re-labelled.
        ->and($html)->toContain(DNC_DOCTYPE_AR);
});

it('leaves the picking list in the operator language on the same order', function () {
    /*
     * THE CONTROL, AND THE HALF A CUSTOMER-ONLY FIX WOULD HAVE BROKEN. The
     * packing slip is read at the bench facing the shelves. It is the same
     * order, the same fixture and the same request — only the document differs.
     */
    dncLanguage(arabic: true);
    dncTranslateFurniture();

    $order = dncOrder('ar');

    $slip = dncDocument($order, 'packing-slip');

    expect($slip)->toContain('<html lang="' . Locale::htmlLang() . '"')
        ->and(str_contains($slip, 'lang="ar"'))->toBeFalse()
        ->and(str_contains($slip, DNC_DOCTYPE_AR))->toBeFalse();
});

it('leaves the dispatch label in the operator language too', function () {
    // An address on the outside of a box, read by a courier. It leaves the
    // building and still is not the customer's document — which is why the
    // rule is "who reads it", not "where does it go".
    dncLanguage(arabic: true);
    dncTranslateFurniture();

    $label = dncDocument(dncOrder('ar'), 'shipping-label');

    expect($label)->toContain('<html lang="' . Locale::htmlLang() . '"')
        ->and(str_contains($label, 'lang="ar"'))->toBeFalse();
});

/* -------------------------------------------- half two: the line names move */

it('calls each line what the shopper called it when they bought it', function () {
    dncLanguage(arabic: true);

    $product = dncProduct('Centella Ampoule', 'أمبول سنتيلا');
    $order = dncOrder('ar', $product);

    // The snapshot exists — OrderLocale's OrderItem::creating hook wrote it at
    // checkout. This case is about which of the two columns the sheet reads.
    expect($order->items->first()->name_localised)->toBe('أمبول سنتيلا');

    $note = dncDocument($order, 'delivery-note');

    expect($note)->toContain('أمبول سنتيلا')
        ->and(str_contains($note, 'Centella Ampoule'))->toBeFalse();
});

it('keeps the English name on the picking list for the same line', function () {
    /*
     * `order_items.name` is the operator's language AND its exact value, and a
     * picker matching a shelf label needs exactly that. This is the assertion
     * that stops "make the customer's documents right" from becoming "translate
     * everything".
     */
    dncLanguage(arabic: true);

    $product = dncProduct('Centella Ampoule', 'أمبول سنتيلا');
    $order = dncOrder('ar', $product);

    $slip = dncDocument($order, 'packing-slip');

    expect($slip)->toContain('Centella Ampoule')
        ->and(str_contains($slip, 'أمبول سنتيلا'))->toBeFalse();
});

/* ------------------------------------------------- and nothing else may move */

it('leaves an English order identical on every one of the four', function () {
    // Both halves are inert when the customer's language IS the column: the
    // wrapper resolves to the same locale and `nameForCustomer` falls back to
    // `name`. This is how the shop ships.
    dncLanguage(arabic: false);

    $order = dncOrder('en');

    foreach (['delivery-note', 'packing-slip', 'invoice', 'shipping-label'] as $type) {
        $html = dncDocument($order, $type);

        expect($html)->toContain('<html lang="' . Locale::htmlLang() . '"')
            ->and($html)->not->toContain('lang="ar"');
    }

    expect(dncDocument($order, 'delivery-note'))
        ->toContain('Rice Daily Moisturizing Toner 150ml');
});

it('keeps the toolbar in the operator language while the sheet is not', function () {
    /*
     * The toolbar is inside .no-print and is gone the moment anything is
     * printed: it is navigation for the person standing at the screen, and that
     * person is the operator whichever language the sheet below is in. The
     * invoice already resolved its two strings before entering the order's
     * locale; the delivery note does the same, rather than inventing a second
     * answer.
     */
    dncLanguage(arabic: true);
    dncTranslateFurniture();

    $html = dncDocument(dncOrder('ar'), 'delivery-note');

    expect($html)->toContain(__('invoice.document.print_button'))
        ->and($html)->toContain(__('invoice.document.print_hint'))
        // …and the sheet under it did move, so this is not passing by accident.
        ->and($html)->toContain(DNC_DOCTYPE_AR);
});

/* ------------------------------------------------------------ lang and dir */

it('states lang and dir from the order and the two switches, measured', function () {
    /*
     * THE SAME TABLE THE INVOICE PRODUCES, for the document that just joined
     * it — and every row of it was measured rather than predicted. The first
     * draft of this case guessed the top row wrong, which is the reason it is
     * written out in full.
     *
     *   shop: Arabic   shop: mirrored   order    <html …>
     *   off            off              ar       lang="ar" dir="ltr"
     *   off            off              en       lang="en" dir="ltr"
     *   ON             off              ar       lang="ar" dir="ltr"   <- today
     *   ON             off              en       lang="en" dir="ltr"
     *   ON             ON               ar       lang="ar" dir="rtl"
     *   ON             ON               en       lang="en" dir="ltr"
     *
     * TWO RULES, AND THE FIRST ONE IS THE SURPRISING HALF. `lang` follows the
     * ORDER, not the shop's current switch: an order placed in Arabic still
     * prints Arabic paperwork after the owner switches Arabic off. That is the
     * right answer and not an oversight — the customer already has the Arabic
     * invoice and the Arabic confirmation email, and a handover sheet that
     * disagreed with them would be a second document about one order in a
     * second language. OrderLocale::render() checks Locale::isSupported(), not
     * Locale::enabled(), and this is the behaviour the invoice has had since
     * Lane F.
     *
     * `dir` is the other rule: it comes from Locale::direction(), which answers
     * 'rtl' only once the MIRRORED LAYOUT has also been switched on — Arabic
     * can be live while the mirrored stylesheet is still being built, which is
     * the state this shop is in today. So an Arabic sheet is dir="ltr" for now,
     * and pinning dir="rtl" as the Arabic answer would pin a state the shop is
     * not in. An ENGLISH order is dir="ltr" even with mirroring on, because
     * direction() is asked about the document, not about the shop.
     */
    $rows = [
        [false, false, 'ar', '<html lang="ar" dir="ltr">'],
        [false, false, 'en', '<html lang="en" dir="ltr">'],
        [true,  false, 'ar', '<html lang="ar" dir="ltr">'],
        [true,  false, 'en', '<html lang="en" dir="ltr">'],
        [true,  true,  'ar', '<html lang="ar" dir="rtl">'],
        [true,  true,  'en', '<html lang="en" dir="ltr">'],
    ];

    $checked = 0;

    foreach ($rows as [$arabic, $mirrored, $locale, $expected]) {
        dncLanguage($arabic, $mirrored);

        expect(dncDocument(dncOrder($locale), 'delivery-note'))->toContain($expected);

        $checked++;
    }

    // A loop that does not run asserts nothing.
    expect($checked)->toBe(6);
});

/* ------------------------------------------------------------------- in bulk */

it('prints each delivery note in its own order language inside one mixed batch', function () {
    /*
     * THE BATCH AND THE SINGLE SHEET SHARE ONE PARTIAL AND MUST NOT DRIFT —
     * sheet-delivery-note.blade.php's own header is about exactly this. The
     * bulk path decided whether to enter the order's locale by asking
     * `allocatesInvoiceNumbers()`, which gave the right answer only while the
     * invoice was the only customer-facing document. Left alone, a bulk run
     * would have printed Arabic product names, because the partial is shared,
     * inside English headings, because the wrapper is not.
     */
    dncLanguage(arabic: true);
    dncTranslateFurniture();

    InvoiceAdminRoutes::wire(app());
    test()->actingAs(dncAdmin(), 'admin');

    $english = dncOrder('en');
    $arabic = dncOrder('ar');

    $html = test()->get(
        \App\Http\Controllers\Admin\BulkDocumentController::bulkUrl('delivery-note', [$english->id, $arabic->id])
    )->assertOk()->getContent();

    // Each sheet declares its own language, whatever the shell around it is…
    expect($html)->toContain('lang="ar"')
        ->and($html)->toContain('lang="en"')
        // …and the shell is the operator's, not the last order's.
        ->and($html)->toContain('<html lang="' . Locale::htmlLang() . '"')
        // The Arabic sheet's furniture really moved.
        ->and($html)->toContain(DNC_DOCTYPE_AR);
});

it('still mints no invoice number for a delivery note, in bulk or alone', function () {
    /*
     * ALLOCATION AND LANGUAGE ARE TWO QUESTIONS, and separating them is the
     * whole reason BulkDocumentSelection::readsInCustomerLanguage() exists
     * beside allocatesInvoiceNumbers(). A delivery note now follows the
     * customer's language AND still must not consume a number out of a sequence
     * an accountant reconciles.
     */
    dncLanguage(arabic: true);

    $order = dncOrder('ar');

    expect($order->invoice_number)->toBeNull();

    dncDocument($order, 'delivery-note');

    expect($order->fresh()->invoice_number)->toBeNull();

    $selection = \App\Services\Invoices\BulkDocumentSelection::from('delivery-note', (string) $order->id);

    expect($selection->readsInCustomerLanguage())->toBeTrue()
        ->and($selection->allocatesInvoiceNumbers())->toBeFalse();
});
